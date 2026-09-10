<?php
/**
 * Analytics gateway — sends anonymous usage events to PostHog.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles server-side analytics via PostHog HTTP Capture API.
 *
 * distinct_id is a random site UUID — never the site URL or any PII.
 * All error codes are enum strings — no customer content is ever captured.
 */
class Mcpwp_Analytics {

	/**
	 * wp_options key for the persistent site UUID.
	 */
	const UUID_OPTION = 'mcpwp_site_uuid';

	/**
	 * Whether analytics is enabled for this site.
	 *
	 * Free tier: requires an explicit opt-in — a stored analytics_enabled true
	 * recorded as analytics_choice = explicit (see has_explicit_consent()).
	 * Paid tier: defaults to true unless analytics_enabled is stored false.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'mcpwp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// A stored off always wins — free or paid.
		if ( array_key_exists( 'analytics_enabled', $settings ) && ! $settings['analytics_enabled'] ) {
			return false;
		}

		// Paid sites: default to enabled when not explicitly set.
		$fs = function_exists( 'mcpwp_get_fs_instance' ) ? mcpwp_get_fs_instance() : null;
		if ( $fs && method_exists( $fs, 'is_paying' ) && $fs->is_paying() ) {
			return true;
		}

		// Free sites: only consent the admin gave.
		return self::has_explicit_consent( $settings );
	}

	/**
	 * Whether the stored settings record an explicit analytics opt-in.
	 *
	 * A true alone is not consent: a paid plan's default carried into Free, a
	 * cloned database or a programmatic write can all leave one behind (review
	 * r1 P2-A). Consent is a true the settings form recorded as a change the
	 * admin made (analytics_choice = explicit); opt-ins stored before 3.11.1
	 * are stamped once by Mcpwp_Activator::stamp_analytics_choice().
	 *
	 * @param array $settings mcpwp_settings.
	 * @return bool
	 */
	public static function has_explicit_consent( array $settings ) {
		return array_key_exists( 'analytics_enabled', $settings )
			&& in_array( $settings['analytics_enabled'], array( true, 1, '1' ), true )
			&& isset( $settings['analytics_choice'] )
			&& 'explicit' === $settings['analytics_choice'];
	}

	/**
	 * Get or create the persistent site UUID.
	 *
	 * The UUID is prefixed with 'mcpwp-' so it is recognisable in PostHog.
	 * It is stored in wp_options and never changes after first generation.
	 *
	 * @return string
	 */
	public static function get_site_uuid() {
		$uuid = get_option( self::UUID_OPTION, '' );
		if ( empty( $uuid ) ) {
			$uuid = 'mcpwp-' . wp_generate_uuid4();
			update_option( self::UUID_OPTION, $uuid, false );
		}
		return $uuid;
	}

	/**
	 * Send an event to PostHog. Fire-and-forget — never throws, never blocks.
	 *
	 * @param string $event      PostHog event name.
	 * @param array  $properties Event properties (must not contain PII or customer content).
	 */
	public static function capture( $event, array $properties = array() ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$config = Mcpwp_Integration_Manager::get_instance()->get_posthog_config();
		if ( empty( $config['token'] ) ) {
			return;
		}

		$payload = array(
			'api_key'     => $config['token'],
			'event'       => $event,
			'distinct_id' => self::get_site_uuid(),
			'properties'  => array_merge(
				array(
					'plugin_version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '',
					'wp_version'     => get_bloginfo( 'version' ),
					'php_version'    => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
					'$lib'           => 'mcpwp-php',
				),
				$properties
			),
		);

		try {
			wp_remote_post(
				rtrim( $config['host'], '/' ) . '/capture/',
				array(
					'blocking'    => false,
					'timeout'     => 5,
					'headers'     => array( 'Content-Type' => 'application/json' ),
					'body'        => wp_json_encode( $payload ),
					'data_format' => 'body',
				)
			);
		} catch ( Exception $e ) {
			// Analytics must never affect tool execution.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MCPWP Analytics: capture failed — ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Hook subscriber for mcpwp_tool_called action.
	 *
	 * @param string $tool_name   MCP tool name.
	 * @param string $category    Tool category slug.
	 * @param int    $duration_ms Execution time in milliseconds.
	 * @param string $error_code  Enum error code, empty string on success.
	 */
	public static function on_tool_called( $tool_name, $category, $duration_ms, $error_code ) {
		self::capture(
			'mcp_tool_called',
			array(
				'tool'        => (string) $tool_name,
				'category'    => (string) $category,
				// A replay is the feature's happy path, not a failed call: it
				// must not poison the tool error rate (#802 round 4).
				'success'     => in_array( (string) $error_code, array( '', 'replayed' ), true ),
				'duration_ms' => (int) $duration_ms,
				'error_code'  => (string) $error_code,
			)
		);
	}
}
