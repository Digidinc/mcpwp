<?php
/**
 * Cloudflare Provider
 *
 * Zone-level Cloudflare API client: Configuration Rules (bot-check skip),
 * cache purge, and Speed settings relevant to Core Web Vitals.
 *
 * @package MCPWP
 * @since   3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cloudflare API provider.
 */
class Mcpwp_Provider_Cloudflare {

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.cloudflare.com/client/v4/';

	/**
	 * Zone settings this integration can read/toggle.
	 *
	 * Mirage is intentionally excluded — Cloudflare deprecated/removed it
	 * as a settable zone setting in September 2025.
	 *
	 * @var string[]
	 */
	const SPEED_SETTINGS = array( 'early_hints', 'http3', 'rocket_loader', 'polish' );

	/**
	 * API token.
	 *
	 * @var string
	 */
	private $api_token;

	/**
	 * Zone ID.
	 *
	 * @var string
	 */
	private $zone_id;

	/**
	 * Whether both required fields are present.
	 *
	 * @var bool
	 */
	private $configured = false;

	/**
	 * Constructor. Loads config from the integration manager.
	 */
	public function __construct() {
		$manager = Mcpwp_Integration_Manager::get_instance();
		$config  = $manager->get_provider_config( 'cloudflare' );

		if ( is_array( $config ) ) {
			$this->api_token  = isset( $config['api_token'] ) ? trim( $config['api_token'] ) : '';
			$this->zone_id    = isset( $config['zone_id'] ) ? trim( $config['zone_id'] ) : '';
			$this->configured = '' !== $this->api_token && '' !== $this->zone_id;
		}
	}

	/**
	 * Whether the integration has both an API token and a zone ID configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return $this->configured;
	}

	/**
	 * Test the Cloudflare connection by reading the configured zone.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function test_connection() {
		if ( ! $this->configured ) {
			return array(
				'success' => false,
				'message' => __( 'Cloudflare API token and zone ID are both required.', 'mcpwp' ),
			);
		}

		$zone = $this->get_zone_status();
		if ( is_wp_error( $zone ) ) {
			return array(
				'success' => false,
				'message' => $zone->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: zone name, 2: zone status */
				__( 'Connected to Cloudflare zone "%1$s" (status: %2$s).', 'mcpwp' ),
				isset( $zone['name'] ) ? $zone['name'] : $this->zone_id,
				isset( $zone['status'] ) ? $zone['status'] : 'unknown'
			),
		);
	}

	/**
	 * Get the configured zone's status/details.
	 *
	 * @return array|WP_Error
	 */
	public function get_zone_status() {
		return $this->request( 'GET', 'zones/' . rawurlencode( $this->zone_id ) );
	}

	/**
	 * Ensure requests to this site's MCPWP REST namespace skip Cloudflare bot checks.
	 *
	 * Adds/updates a Configuration Rule that drops Security Level to
	 * "essentially_off" for the site's own /wp-json/mcpwp/v1/* path — this is
	 * the confirmed fix for standard Bot Fight Mode / Security Level blocks
	 * (verified live against a real customer site). It also attempts a WAF
	 * custom "skip" rule for Super Bot Fight Mode / Managed Rules on the same
	 * path; the exact skip-target product string isn't independently
	 * verified against live docs, so that half is best-effort and never
	 * blocks the primary Configuration Rule fix if it fails.
	 *
	 * @return array{summary: string[], path_prefix: string}|WP_Error
	 */
	public function fix_mcp_access() {
		if ( ! $this->configured ) {
			return new WP_Error(
				'cloudflare_not_configured',
				__( 'Cloudflare integration is not configured (missing API token or zone ID).', 'mcpwp' )
			);
		}

		$path_prefix = wp_parse_url( rest_url( 'mcpwp/v1/' ), PHP_URL_PATH );
		if ( ! $path_prefix ) {
			$path_prefix = '/wp-json/mcpwp/v1/';
		}
		$expression = sprintf( '(starts_with(http.request.uri.path, "%s"))', $path_prefix );

		$summary = array();

		// 1. Configuration Rule — drop Security Level for this path (the confirmed fix).
		$config_ruleset = $this->request( 'GET', 'zones/' . rawurlencode( $this->zone_id ) . '/rulesets/phases/http_config_settings/entrypoint' );
		if ( is_wp_error( $config_ruleset ) ) {
			return $config_ruleset;
		}

		$rules = ( isset( $config_ruleset['rules'] ) && is_array( $config_ruleset['rules'] ) ) ? $config_ruleset['rules'] : array();
		$found = false;
		foreach ( $rules as &$rule ) {
			if ( isset( $rule['expression'] ) && $rule['expression'] === $expression ) {
				$rule['action']            = 'set_config';
				$rule['action_parameters'] = array( 'security_level' => 'essentially_off' );
				$rule['description']       = 'MCPWP: allow MCP API requests through bot checks';
				$found                     = true;
				break;
			}
		}
		unset( $rule );
		if ( ! $found ) {
			$rules[] = array(
				'expression'        => $expression,
				'action'            => 'set_config',
				'action_parameters' => array( 'security_level' => 'essentially_off' ),
				'description'       => 'MCPWP: allow MCP API requests through bot checks',
			);
		}

		$updated = $this->request(
			'PUT',
			'zones/' . rawurlencode( $this->zone_id ) . '/rulesets/' . rawurlencode( $config_ruleset['id'] ),
			array( 'rules' => $rules )
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$summary[] = $found
			? 'Updated existing Configuration Rule to skip Security Level checks for ' . $path_prefix . '*'
			: 'Created Configuration Rule to skip Security Level checks for ' . $path_prefix . '*';

		// 2. WAF custom rule — best-effort skip for Super Bot Fight Mode / Managed Rules.
		$waf_ruleset = $this->request( 'GET', 'zones/' . rawurlencode( $this->zone_id ) . '/rulesets/phases/http_request_firewall_custom/entrypoint' );
		if ( is_wp_error( $waf_ruleset ) ) {
			$summary[] = 'Could not read the WAF custom-rules ruleset (' . $waf_ruleset->get_error_message() . ') — skipped the Super Bot Fight Mode rule; the Configuration Rule above is the primary, confirmed fix.';
			return array(
				'summary'     => $summary,
				'path_prefix' => $path_prefix,
			);
		}

		$waf_rules = ( isset( $waf_ruleset['rules'] ) && is_array( $waf_ruleset['rules'] ) ) ? $waf_ruleset['rules'] : array();
		$waf_found = false;
		foreach ( $waf_rules as &$rule ) {
			if ( isset( $rule['expression'], $rule['action'] ) && $rule['expression'] === $expression && 'skip' === $rule['action'] ) {
				$rule['action_parameters'] = array( 'products' => array( 'superBotFightMode', 'bic', 'waf' ) );
				$rule['description']       = 'MCPWP: allow MCP API requests through bot checks';
				$waf_found                 = true;
				break;
			}
		}
		unset( $rule );
		if ( ! $waf_found ) {
			$waf_rules[] = array(
				'expression'        => $expression,
				'action'            => 'skip',
				'action_parameters' => array( 'products' => array( 'superBotFightMode', 'bic', 'waf' ) ),
				'description'       => 'MCPWP: allow MCP API requests through bot checks',
			);
		}

		$waf_updated = $this->request(
			'PUT',
			'zones/' . rawurlencode( $this->zone_id ) . '/rulesets/' . rawurlencode( $waf_ruleset['id'] ),
			array( 'rules' => $waf_rules )
		);
		if ( is_wp_error( $waf_updated ) ) {
			$summary[] = 'Could not write the Super Bot Fight Mode skip rule (' . $waf_updated->get_error_message() . ') — the Configuration Rule above already resolves standard Bot Fight Mode / Security Level blocks.';
		} else {
			$summary[] = $waf_found
				? 'Updated existing WAF skip rule for Super Bot Fight Mode / Managed Rules on ' . $path_prefix . '*'
				: 'Created WAF skip rule for Super Bot Fight Mode / Managed Rules on ' . $path_prefix . '*';
		}

		return array(
			'summary'     => $summary,
			'path_prefix' => $path_prefix,
		);
	}

	/**
	 * Purge Cloudflare cache for specific URLs, or the whole zone.
	 *
	 * @param string[] $urls              URLs to purge (max 30, Cloudflare's own cap).
	 * @param bool     $purge_everything  Purge the entire zone instead.
	 * @return array|WP_Error
	 */
	public function purge_cache( $urls = array(), $purge_everything = false ) {
		if ( ! $this->configured ) {
			return new WP_Error(
				'cloudflare_not_configured',
				__( 'Cloudflare integration is not configured (missing API token or zone ID).', 'mcpwp' )
			);
		}

		if ( $purge_everything ) {
			$body = array( 'purge_everything' => true );
		} else {
			$urls = array_values( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) );
			if ( empty( $urls ) ) {
				return new WP_Error(
					'cloudflare_purge_no_urls',
					__( 'Provide at least one URL to purge, or set purge_everything to true.', 'mcpwp' )
				);
			}
			$urls = array_slice( $urls, 0, 30 );
			$body = array( 'files' => $urls );
		}

		return $this->request( 'POST', 'zones/' . rawurlencode( $this->zone_id ) . '/purge_cache', $body );
	}

	/**
	 * Read the current value of every supported Speed setting.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_speed_settings() {
		if ( ! $this->configured ) {
			return new WP_Error(
				'cloudflare_not_configured',
				__( 'Cloudflare integration is not configured (missing API token or zone ID).', 'mcpwp' )
			);
		}

		$settings = array();
		foreach ( self::SPEED_SETTINGS as $name ) {
			$result = $this->request( 'GET', 'zones/' . rawurlencode( $this->zone_id ) . '/settings/' . $name );
			if ( is_wp_error( $result ) ) {
				$settings[ $name ] = array( 'error' => $result->get_error_message() );
				continue;
			}
			$settings[ $name ] = isset( $result['value'] ) ? $result['value'] : null;
		}

		return $settings;
	}

	/**
	 * Update one Speed setting.
	 *
	 * @param string $name  Setting name — one of self::SPEED_SETTINGS.
	 * @param mixed  $value New value (e.g. "on"/"off", or "lossless"/"lossy"/"webp" for polish).
	 * @return array|WP_Error
	 */
	public function update_speed_setting( $name, $value ) {
		if ( ! $this->configured ) {
			return new WP_Error(
				'cloudflare_not_configured',
				__( 'Cloudflare integration is not configured (missing API token or zone ID).', 'mcpwp' )
			);
		}

		if ( ! in_array( $name, self::SPEED_SETTINGS, true ) ) {
			return new WP_Error(
				'cloudflare_invalid_setting',
				sprintf(
					/* translators: 1: attempted setting name, 2: comma-separated list of valid names */
					__( 'Unknown speed setting "%1$s". Valid settings: %2$s.', 'mcpwp' ),
					$name,
					implode( ', ', self::SPEED_SETTINGS )
				)
			);
		}

		return $this->request( 'PATCH', 'zones/' . rawurlencode( $this->zone_id ) . '/settings/' . $name, array( 'value' => $value ) );
	}

	/**
	 * Make an authenticated request against the Cloudflare API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path relative to API_BASE (no leading slash).
	 * @param array|null $body   Request body, JSON-encoded if provided.
	 * @return array|WP_Error Decoded `result` on success.
	 */
	private function request( $method, $path, $body = null ) {
		if ( ! $this->configured ) {
			return new WP_Error(
				'cloudflare_not_configured',
				__( 'Cloudflare integration is not configured (missing API token or zone ID).', 'mcpwp' )
			);
		}

		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'cloudflare_invalid_response',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Cloudflare API returned a non-JSON response (HTTP %d).', 'mcpwp' ),
					$code
				)
			);
		}

		if ( empty( $decoded['success'] ) ) {
			$messages = array();
			if ( ! empty( $decoded['errors'] ) && is_array( $decoded['errors'] ) ) {
				foreach ( $decoded['errors'] as $err ) {
					$messages[] = isset( $err['message'] ) ? $err['message'] : wp_json_encode( $err );
				}
			}
			return new WP_Error(
				'cloudflare_api_error',
				$messages ? implode( '; ', $messages ) : sprintf(
					/* translators: %d: HTTP status code */
					__( 'Cloudflare API returned HTTP %d.', 'mcpwp' ),
					$code
				),
				array(
					'status' => $code,
					'errors' => isset( $decoded['errors'] ) ? $decoded['errors'] : array(),
				)
			);
		}

		return $decoded['result'];
	}
}
