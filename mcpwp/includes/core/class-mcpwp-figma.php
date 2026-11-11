<?php
/**
 * Figma Design Context Client
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight client for Figma design intake.
 *
 * Figma design intake is Pro, and the tier is enforced here, at the
 * capability, not per entry point (#564 review r1). Every method that calls
 * Figma or writes Figma-derived data refuses on Free with a 403 pro_required
 * WP_Error, fail-closed, and the two HTTP chokepoints (request(),
 * refresh_oauth_token()) refuse as well. REST, MCP, WP-Cron, the Integrations
 * page (test connection, OAuth) and site-info status all inherit it.
 *
 * Storing Figma credentials on Free is allowed: with every use refused, a
 * stored token unlocks nothing. get_status() reports it as configured and,
 * separately, as needing Pro.
 */
class Mcpwp_Figma {

	/**
	 * WP option storing the last synced design-tokens payload.
	 *
	 * Shape: array{ synced_at: int, file_key: string, colors: array<string,string>,
	 * typography: array<string,array{fontFamily:string,fontWeight:int,fontSize:int,lineHeightPx:int}> }
	 *
	 * A separate WordPress theme project reads this option directly to emit
	 * CSS custom properties — the key names in that shape are a public contract;
	 * do not rename them without coordinating the theme-side consumer.
	 *
	 * @var string
	 */
	const DESIGN_TOKENS_OPTION = 'mcpwp_figma_design_tokens';

	/**
	 * Cron hook for the periodic design-token sync.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'mcpwp_figma_sync_design_tokens';

	/**
	 * Cron schedule recurrence for the design-token sync.
	 *
	 * @var string
	 */
	const CRON_INTERVAL = 'hourly';

	/**
	 * Figma OAuth authorize URL.
	 *
	 * @var string
	 */
	private $oauth_authorize_url = 'https://www.figma.com/oauth';

	/**
	 * Figma OAuth token URL.
	 *
	 * @var string
	 */
	private $oauth_token_url = 'https://api.figma.com/v1/oauth/token';

	/**
	 * Figma REST API base URL.
	 *
	 * @var string
	 */
	private $api_base = 'https://api.figma.com/v1';

	/**
	 * Integration manager.
	 *
	 * @var Mcpwp_Integration_Manager
	 */
	private $manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->manager = Mcpwp_Integration_Manager::get_instance();
	}

	/**
	 * Whether this install may use Figma (Pro). Fails closed.
	 *
	 * @return bool
	 */
	public static function is_entitled() {
		return class_exists( 'Mcpwp_REST_API', false ) && Mcpwp_REST_API::is_pro_entitled();
	}

	/**
	 * The refusal every Figma use returns on Free.
	 *
	 * @return WP_Error
	 */
	private static function pro_required_error() {
		return new WP_Error(
			'pro_required',
			__( 'Figma design intake requires MCPWP Pro. Upgrade at https://mcpwp.net/pricing/', 'mcpwp' ),
			array(
				'status'      => 403,
				'upgrade_url' => 'https://mcpwp.net/pricing/',
			)
		);
	}

	/**
	 * Get Figma integration status.
	 *
	 * Never calls Figma on Free: an expired OAuth token is not refreshed
	 * (refresh_oauth_token() refuses), so a Free site with only OAuth tokens
	 * can report configured => false together with pro_required => true.
	 *
	 * @return array
	 */
	public function get_status() {
		$config = $this->get_raw_config();
		$access = $this->get_access_token();

		return array(
			'pro_required'     => ! self::is_entitled(),
			'configured'       => ! is_wp_error( $access ),
			'auth_mode'        => ! empty( $config['personal_access_token'] ) ? 'personal_token' : ( ! empty( $config['access_token'] ) || ! empty( $config['refresh_token'] ) ? 'oauth' : 'unconfigured' ),
			'oauth_ready'      => ! empty( $config['oauth_client_id'] ) && ! empty( $config['oauth_client_secret'] ),
			'oauth_connected'  => ! empty( $config['access_token'] ) || ! empty( $config['refresh_token'] ),
			'default_file_key' => is_array( $config ) && ! empty( $config['default_file_key'] ) ? (string) $config['default_file_key'] : '',
			'redirect_uri'     => $this->get_oauth_redirect_uri(),
			'api_base'         => $this->api_base,
		);
	}

	/**
	 * Test the Figma connection.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function test_connection() {
		if ( ! self::is_entitled() ) {
			return array(
				'success' => false,
				'message' => self::pro_required_error()->get_error_message(),
			);
		}

		$config = $this->get_raw_config();
		$access = $this->get_access_token();
		if ( is_wp_error( $access ) ) {
			return array(
				'success' => false,
				'message' => $access->get_error_message(),
			);
		}

		$mode_label = ! empty( $config['personal_access_token'] ) ? __( 'personal token', 'mcpwp' ) : __( 'OAuth', 'mcpwp' );
		$me = $this->request( '/me' );
		if ( ! is_wp_error( $me ) ) {
			$handle = isset( $me['handle'] ) ? (string) $me['handle'] : __( 'authenticated user', 'mcpwp' );
			return array(
				'success' => true,
				/* translators: 1: Figma handle 2: auth mode */
				'message' => sprintf( __( 'Connected to Figma as %1$s using %2$s.', 'mcpwp' ), $handle, $mode_label ),
			);
		}

		if ( is_wp_error( $access ) ) {
			return array(
				'success' => false,
				'message' => $access->get_error_message(),
			);
		}

		if ( ! empty( $config['default_file_key'] ) ) {
			$file = $this->request(
				'/files/' . rawurlencode( $config['default_file_key'] ),
				array( 'depth' => 1 )
			);

			if ( ! is_wp_error( $file ) ) {
				$title = isset( $file['name'] ) ? (string) $file['name'] : $config['default_file_key'];
				return array(
					'success' => true,
					/* translators: %s: Figma file title */
					'message' => sprintf( __( 'Connected to Figma and reached file %s.', 'mcpwp' ), $title ),
				);
			}
		}

		return array(
			'success' => false,
			'message' => $me->get_error_message(),
		);
	}

	/**
	 * Get the admin callback URI for Figma OAuth.
	 *
	 * @return string
	 */
	public function get_oauth_redirect_uri() {
		return admin_url( 'admin-post.php?action=mcpwp_figma_oauth_callback' );
	}

	/**
	 * Check whether OAuth app credentials are present.
	 *
	 * @return bool
	 */
	public function is_oauth_ready() {
		$config = $this->get_raw_config();
		return ! empty( $config['oauth_client_id'] ) && ! empty( $config['oauth_client_secret'] );
	}

	/**
	 * Start an OAuth handshake and return the Figma authorize URL.
	 *
	 * @return string|WP_Error
	 */
	public function get_oauth_authorize_url() {
		if ( ! self::is_entitled() ) {
			return self::pro_required_error();
		}

		$config = $this->get_raw_config();
		if ( empty( $config['oauth_client_id'] ) || empty( $config['oauth_client_secret'] ) ) {
			return new WP_Error( 'figma_oauth_not_ready', __( 'Figma OAuth requires both a client ID and client secret.', 'mcpwp' ) );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient(
			'mcpwp_figma_oauth_state_' . $state,
			array(
				'created_at' => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		return add_query_arg(
			array(
				'client_id'     => $config['oauth_client_id'],
				'redirect_uri'  => $this->get_oauth_redirect_uri(),
				'scope'         => 'file_content:read current_user:read',
				'state'         => $state,
				'response_type' => 'code',
			),
			$this->oauth_authorize_url
		);
	}

	/**
	 * Exchange an OAuth code for tokens and persist them.
	 *
	 * @param string $code  Authorization code.
	 * @param string $state State token.
	 * @return array|WP_Error
	 */
	public function exchange_oauth_code( $code, $state ) {
		if ( ! self::is_entitled() ) {
			return self::pro_required_error();
		}

		$config = $this->get_raw_config();
		if ( empty( $config['oauth_client_id'] ) || empty( $config['oauth_client_secret'] ) ) {
			return new WP_Error( 'figma_oauth_not_ready', __( 'Figma OAuth credentials are missing.', 'mcpwp' ) );
		}

		$stored_state = get_transient( 'mcpwp_figma_oauth_state_' . $state );
		delete_transient( 'mcpwp_figma_oauth_state_' . $state );
		if ( empty( $stored_state ) ) {
			return new WP_Error( 'figma_invalid_state', __( 'The Figma OAuth state is invalid or expired. Start the connection again.', 'mcpwp' ) );
		}

		$response = wp_remote_post(
			$this->oauth_token_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $config['oauth_client_id'] . ':' . $config['oauth_client_secret'] ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => http_build_query(
					array(
						'redirect_uri' => $this->get_oauth_redirect_uri(),
						'code'         => $code,
						'grant_type'   => 'authorization_code',
					),
					'',
					'&'
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_status = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code_status < 200 || $code_status >= 300 || ! is_array( $payload ) ) {
			$message = is_array( $payload ) && ! empty( $payload['error_description'] ) ? (string) $payload['error_description'] : __( 'Figma OAuth token exchange failed.', 'mcpwp' );
			return new WP_Error( 'figma_token_exchange_failed', $message, array( 'status' => $code_status ) );
		}

		// OAuth owns token fields; settings may have changed during HTTP.
		$tokens = array();
		$tokens['access_token']  = isset( $payload['access_token'] ) ? (string) $payload['access_token'] : '';
		$tokens['refresh_token'] = isset( $payload['refresh_token'] ) ? (string) $payload['refresh_token'] : '';
		$tokens['token_type']    = isset( $payload['token_type'] ) ? (string) $payload['token_type'] : 'Bearer';
		$tokens['expires_at']    = ! empty( $payload['expires_in'] ) ? gmdate( 'Y-m-d H:i:s', time() + (int) $payload['expires_in'] ) : '';

		if ( ! $this->manager->set_provider_config( 'figma', $tokens ) ) {
			return new WP_Error( 'figma_save_failed', __( 'Figma OAuth succeeded, but the tokens could not be saved.', 'mcpwp' ) );
		}

		return $this->test_connection();
	}

	/**
	 * Get a Figma file summary and outline.
	 *
	 * @param string $file_key Figma file key.
	 * @param int    $depth    Requested depth.
	 * @return array|WP_Error
	 */
	public function get_file( $file_key = '', $depth = 2 ) {
		if ( ! self::is_entitled() ) {
			return self::pro_required_error();
		}

		$file_key = $this->resolve_file_key( $file_key );
		if ( is_wp_error( $file_key ) ) {
			return $file_key;
		}

		$response = $this->request(
			'/files/' . rawurlencode( $file_key ),
			array(
				'depth' => max( 1, min( 4, (int) $depth ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$document = isset( $response['document'] ) && is_array( $response['document'] ) ? $response['document'] : array();
		$children = isset( $document['children'] ) && is_array( $document['children'] ) ? $document['children'] : array();

		return array(
			'file_key'        => $file_key,
			'name'            => isset( $response['name'] ) ? (string) $response['name'] : '',
			'last_modified'   => isset( $response['lastModified'] ) ? (string) $response['lastModified'] : '',
			'thumbnail_url'   => isset( $response['thumbnailUrl'] ) ? (string) $response['thumbnailUrl'] : '',
			'version'         => isset( $response['version'] ) ? (string) $response['version'] : '',
			'role'            => isset( $response['role'] ) ? (string) $response['role'] : '',
			'editor_type'     => isset( $response['editorType'] ) ? (string) $response['editorType'] : '',
			'components'      => isset( $response['components'] ) && is_array( $response['components'] ) ? count( $response['components'] ) : 0,
			'component_sets'  => isset( $response['componentSets'] ) && is_array( $response['componentSets'] ) ? count( $response['componentSets'] ) : 0,
			'styles'          => isset( $response['styles'] ) && is_array( $response['styles'] ) ? count( $response['styles'] ) : 0,
			'canvases'        => $this->summarize_children( $children, 0, 1 ),
			'document_outline' => $this->summarize_node_tree( $document, 0, 2 ),
		);
	}

	/**
	 * Get a specific Figma node/frame summary.
	 *
	 * @param string $file_key Figma file key.
	 * @param string $node_id  Node ID.
	 * @param int    $depth    Requested depth.
	 * @return array|WP_Error
	 */
	public function get_node( $file_key, $node_id, $depth = 2 ) {
		if ( ! self::is_entitled() ) {
			return self::pro_required_error();
		}

		$file_key = $this->resolve_file_key( $file_key );
		if ( is_wp_error( $file_key ) ) {
			return $file_key;
		}

		$node_id = trim( (string) $node_id );
		if ( '' === $node_id ) {
			return new WP_Error( 'missing_node_id', __( 'A Figma node_id is required.', 'mcpwp' ) );
		}

		$response = $this->request(
			'/files/' . rawurlencode( $file_key ) . '/nodes',
			array(
				'ids'   => $node_id,
				'depth' => max( 1, min( 4, (int) $depth ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$node = isset( $response['nodes'][ $node_id ]['document'] ) && is_array( $response['nodes'][ $node_id ]['document'] )
			? $response['nodes'][ $node_id ]['document']
			: null;

		if ( ! is_array( $node ) ) {
			return new WP_Error( 'node_not_found', __( 'The requested Figma node was not found in the file response.', 'mcpwp' ) );
		}

		return array(
			'file_key' => $file_key,
			'node_id'  => $node_id,
			'summary'  => $this->summarize_single_node( $node ),
			'outline'  => $this->summarize_node_tree( $node, 0, 3 ),
		);
	}

	/**
	 * Sync design tokens (colors + typography) from a Figma file's shared
	 * styles into the mcpwp_figma_design_tokens option.
	 *
	 * Flow: GET /files/{file_key}/styles (all plans, not Enterprise-gated) to
	 * enumerate FILL and TEXT styles, then a single batched
	 * GET /files/{file_key}/nodes?ids=... to resolve every style's node in one
	 * request. FILL styles resolve to a CSS color (hex, or rgba() when the
	 * fill has alpha < 1). TEXT styles resolve to a small typography record.
	 * EFFECT/GRID styles are ignored — they have no simple CSS token form.
	 *
	 * An empty styles list (or a file with no FILL/TEXT styles) is a valid,
	 * successful result — it stores and returns an empty-but-valid tokens
	 * shape rather than a WP_Error.
	 *
	 * @param string|null $file_key Explicit Figma file key. Falls back to the
	 *                              configured default_file_key when omitted/empty.
	 * @return array|WP_Error Tokens payload (see DESIGN_TOKENS_OPTION docblock), or WP_Error on failure.
	 */
	public function sync_design_tokens( $file_key = null ) {
		if ( ! self::is_entitled() ) {
			return self::pro_required_error();
		}

		$resolved_key = $this->resolve_file_key( (string) $file_key );
		if ( is_wp_error( $resolved_key ) ) {
			return $resolved_key;
		}

		$styles_response = $this->request( '/files/' . rawurlencode( $resolved_key ) . '/styles' );
		if ( is_wp_error( $styles_response ) ) {
			return $styles_response;
		}

		// Figma wraps the styles list in a `meta` envelope; fall back to a
		// top-level `styles` key defensively in case that ever changes.
		if ( isset( $styles_response['meta']['styles'] ) && is_array( $styles_response['meta']['styles'] ) ) {
			$styles = $styles_response['meta']['styles'];
		} elseif ( isset( $styles_response['styles'] ) && is_array( $styles_response['styles'] ) ) {
			$styles = $styles_response['styles'];
		} else {
			$styles = array();
		}

		// Only FILL (color) and TEXT (typography) styles map to simple CSS
		// tokens; EFFECT (shadows/blurs) and GRID styles are skipped.
		$relevant = array();
		foreach ( $styles as $style ) {
			if ( ! is_array( $style ) || empty( $style['node_id'] ) || empty( $style['style_type'] ) ) {
				continue;
			}

			$style_type = strtoupper( (string) $style['style_type'] );
			if ( ! in_array( $style_type, array( 'FILL', 'TEXT' ), true ) ) {
				continue;
			}

			$name = isset( $style['name'] ) ? (string) $style['name'] : '';
			$slug = $this->slugify_style_name( $name );
			if ( '' === $slug ) {
				$slug = 'style-' . sanitize_key( (string) $style['node_id'] );
			}

			$relevant[ (string) $style['node_id'] ] = array(
				'style_type' => $style_type,
				'slug'       => $slug,
			);
		}

		$tokens = array(
			'synced_at'  => time(),
			'file_key'   => $resolved_key,
			'colors'     => array(),
			'typography' => array(),
		);

		if ( empty( $relevant ) ) {
			update_option( self::DESIGN_TOKENS_OPTION, $tokens );
			return $tokens;
		}

		$documents = $this->fetch_nodes_batch( $resolved_key, array_keys( $relevant ) );
		if ( is_wp_error( $documents ) ) {
			return $documents;
		}

		foreach ( $relevant as $node_id => $meta ) {
			if ( ! isset( $documents[ $node_id ] ) ) {
				continue;
			}

			$document = $documents[ $node_id ];

			if ( 'FILL' === $meta['style_type'] ) {
				$css_color = $this->extract_fill_color( $document );
				if ( null !== $css_color ) {
					$tokens['colors'][ $meta['slug'] ] = $css_color;
				}
				continue;
			}

			$typography = $this->extract_typography( $document );
			if ( null !== $typography ) {
				$tokens['typography'][ $meta['slug'] ] = $typography;
			}
		}

		update_option( self::DESIGN_TOKENS_OPTION, $tokens );

		return $tokens;
	}

	/**
	 * Read the cached design-tokens option without hitting the Figma API.
	 *
	 * Returns an empty-but-valid shape (null synced_at, empty maps) when
	 * design tokens have never been synced, so callers never have to
	 * special-case "not synced yet" vs. "synced, but no tokens found".
	 *
	 * @return array{synced_at: int|null, file_key: string, colors: array, typography: array}
	 */
	public function get_cached_design_tokens() {
		$tokens = get_option( self::DESIGN_TOKENS_OPTION, array() );

		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			return array(
				'synced_at'  => null,
				'file_key'   => '',
				'colors'     => array(),
				'typography' => array(),
			);
		}

		return array(
			'synced_at'  => isset( $tokens['synced_at'] ) ? (int) $tokens['synced_at'] : null,
			'file_key'   => isset( $tokens['file_key'] ) ? (string) $tokens['file_key'] : '',
			'colors'     => isset( $tokens['colors'] ) && is_array( $tokens['colors'] ) ? $tokens['colors'] : array(),
			'typography' => isset( $tokens['typography'] ) && is_array( $tokens['typography'] ) ? $tokens['typography'] : array(),
		);
	}

	/**
	 * Schedule the recurring design-token sync cron event, if not already scheduled.
	 *
	 * On Free nothing is scheduled, and an event left from a Pro period is
	 * cleared.
	 */
	public static function schedule() {
		if ( ! self::is_entitled() ) {
			self::unschedule();
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled design-token sync cron event.
	 */
	public static function unschedule() {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Cron entry point. Only syncs when Figma has a usable access token AND a
	 * default_file_key configured; a safe no-op otherwise (e.g. Figma never
	 * configured on this site). Swallows WP_Error results — a failed
	 * background sync must not surface to the WP-Cron scheduler.
	 */
	public static function cron_sync_design_tokens() {
		// A no-op on Free, and the event is removed so it stops firing.
		if ( ! self::is_entitled() ) {
			self::unschedule();
			return;
		}

		$figma  = new self();
		$status = $figma->get_status();

		if ( empty( $status['configured'] ) || empty( $status['default_file_key'] ) ) {
			return;
		}

		$figma->sync_design_tokens();
	}

	/**
	 * Resolve the effective file key.
	 *
	 * @param string $file_key Requested file key.
	 * @return string|WP_Error
	 */
	private function resolve_file_key( $file_key ) {
		$file_key = trim( (string) $file_key );
		if ( '' !== $file_key ) {
			return $file_key;
		}

		$config = $this->get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( empty( $config['default_file_key'] ) ) {
			return new WP_Error( 'missing_file_key', __( 'No Figma file_key was provided and no default_file_key is configured.', 'mcpwp' ) );
		}

		return (string) $config['default_file_key'];
	}

	/**
	 * Get stored config.
	 *
	 * @return array|WP_Error
	 */
	private function get_config() {
		$config = $this->manager->get_provider_config( 'figma' );
		if ( ! is_array( $config ) ) {
			return new WP_Error( 'figma_not_configured', __( 'Figma is not configured. Add a personal token or OAuth app in MCPWP → Integrations.', 'mcpwp' ) );
		}

		return $config;
	}

	/**
	 * Get raw stored config with array fallback.
	 *
	 * @return array
	 */
	private function get_raw_config() {
		$config = $this->manager->get_provider_config( 'figma' );
		return is_array( $config ) ? $config : array();
	}

	/**
	 * Get a valid access token, refreshing OAuth tokens when needed.
	 *
	 * @return string|WP_Error
	 */
	private function get_access_token() {
		$config = $this->get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( ! empty( $config['personal_access_token'] ) ) {
			return (string) $config['personal_access_token'];
		}

		if ( ! empty( $config['access_token'] ) && ! $this->is_token_expired( $config ) ) {
			return (string) $config['access_token'];
		}

		if ( ! empty( $config['refresh_token'] ) ) {
			$refreshed = $this->refresh_oauth_token( $config );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return (string) $refreshed['access_token'];
		}

		return new WP_Error( 'figma_not_configured', __( 'Figma is not configured. Add a personal token or complete the OAuth connection in MCPWP → Integrations.', 'mcpwp' ) );
	}

	/**
	 * Check whether a stored OAuth token is expired.
	 *
	 * @param array $config Figma config.
	 * @return bool
	 */
	private function is_token_expired( $config ) {
		if ( empty( $config['expires_at'] ) ) {
			return false;
		}

		$expires_at = strtotime( (string) $config['expires_at'] );
		if ( false === $expires_at ) {
			return false;
		}

		return $expires_at <= ( time() + 60 );
	}

	/**
	 * Refresh a stored OAuth token.
	 *
	 * @param array $config Figma config.
	 * @return array|WP_Error
	 */
	private function refresh_oauth_token( $config ) {
		// HTTP chokepoint: a token refresh calls Figma and writes new tokens.
		if ( ! self::is_entitled() ) {
			return self::pro_required_error();
		}

		if ( empty( $config['oauth_client_id'] ) || empty( $config['oauth_client_secret'] ) || empty( $config['refresh_token'] ) ) {
			return new WP_Error( 'figma_refresh_unavailable', __( 'Figma OAuth refresh is unavailable because credentials or refresh token are missing.', 'mcpwp' ) );
		}

		$response = wp_remote_post(
			$this->oauth_token_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $config['oauth_client_id'] . ':' . $config['oauth_client_secret'] ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => http_build_query(
					array(
						'refresh_token' => $config['refresh_token'],
						'grant_type'    => 'refresh_token',
					),
					'',
					'&'
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_status = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code_status < 200 || $code_status >= 300 || ! is_array( $payload ) ) {
			$message = is_array( $payload ) && ! empty( $payload['error_description'] ) ? (string) $payload['error_description'] : __( 'Figma OAuth token refresh failed.', 'mcpwp' );
			return new WP_Error( 'figma_refresh_failed', $message, array( 'status' => $code_status ) );
		}

		// Merge only refreshed fields onto the current record in the manager.
		$tokens = array();
		$tokens['access_token'] = isset( $payload['access_token'] ) ? (string) $payload['access_token'] : '';
		if ( ! empty( $payload['refresh_token'] ) ) {
			$tokens['refresh_token'] = (string) $payload['refresh_token'];
		}
		$tokens['token_type'] = isset( $payload['token_type'] ) ? (string) $payload['token_type'] : 'Bearer';
		$tokens['expires_at'] = ! empty( $payload['expires_in'] ) ? gmdate( 'Y-m-d H:i:s', time() + (int) $payload['expires_in'] ) : '';

		if ( ! $this->manager->set_provider_config( 'figma', $tokens ) ) {
			return new WP_Error( 'figma_refresh_save_failed', __( 'Figma OAuth token refresh succeeded, but the new token could not be saved.', 'mcpwp' ) );
		}

		return $tokens;
	}

	/**
	 * Perform a Figma API request.
	 *
	 * @param string $path  API path.
	 * @param array  $query Query args.
	 * @return array|WP_Error
	 */
	private function request( $path, $query = array() ) {
		// HTTP chokepoint for every Figma API read.
		if ( ! self::is_entitled() ) {
			return self::pro_required_error();
		}

		$config = $this->get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$url = trailingslashit( $this->api_base ) . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg(
				array_filter(
					$query,
					function ( $value ) {
						return null !== $value && '' !== $value;
					}
				),
				$url
			);
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['err'] )
				? (string) $data['err']
				/* translators: %d: HTTP response status code */
				: sprintf( __( 'Figma API returned HTTP %d.', 'mcpwp' ), $code );
			return new WP_Error( 'figma_request_failed', $message, array( 'status' => $code ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'figma_invalid_response', __( 'Figma returned an invalid JSON response.', 'mcpwp' ) );
		}

		return $data;
	}

	/**
	 * Summarize a node subtree for AI consumption.
	 *
	 * @param array $node      Node data.
	 * @param int   $depth     Current depth.
	 * @param int   $max_depth Maximum depth.
	 * @return array
	 */
	private function summarize_node_tree( $node, $depth, $max_depth ) {
		$summary = $this->summarize_single_node( $node );

		if ( $depth >= $max_depth || empty( $node['children'] ) || ! is_array( $node['children'] ) ) {
			return $summary;
		}

		$summary['children'] = $this->summarize_children( $node['children'], $depth + 1, $max_depth );
		return $summary;
	}

	/**
	 * Summarize children nodes.
	 *
	 * @param array $children  Child nodes.
	 * @param int   $depth     Current depth.
	 * @param int   $max_depth Maximum depth.
	 * @return array
	 */
	private function summarize_children( $children, $depth, $max_depth ) {
		$result = array();
		$index  = 0;

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$result[] = $this->summarize_node_tree( $child, $depth, $max_depth );
			++$index;

			if ( $index >= 12 ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Summarize a single Figma node.
	 *
	 * @param array $node Node data.
	 * @return array
	 */
	private function summarize_single_node( $node ) {
		$summary = array(
			'id'            => isset( $node['id'] ) ? (string) $node['id'] : '',
			'name'          => isset( $node['name'] ) ? (string) $node['name'] : '',
			'type'          => isset( $node['type'] ) ? (string) $node['type'] : '',
			'visible'       => ! isset( $node['visible'] ) || (bool) $node['visible'],
			'children_count' => isset( $node['children'] ) && is_array( $node['children'] ) ? count( $node['children'] ) : 0,
		);

		if ( ! empty( $node['absoluteBoundingBox'] ) && is_array( $node['absoluteBoundingBox'] ) ) {
			$bounds = $node['absoluteBoundingBox'];
			$summary['bounds'] = array(
				'x'      => isset( $bounds['x'] ) ? (float) $bounds['x'] : 0,
				'y'      => isset( $bounds['y'] ) ? (float) $bounds['y'] : 0,
				'width'  => isset( $bounds['width'] ) ? (float) $bounds['width'] : 0,
				'height' => isset( $bounds['height'] ) ? (float) $bounds['height'] : 0,
			);
		}

		if ( isset( $node['layoutMode'] ) && '' !== $node['layoutMode'] ) {
			$summary['layout_mode'] = (string) $node['layoutMode'];
		}

		return $summary;
	}

	/**
	 * Batch-resolve a set of Figma node IDs to their document payloads in a
	 * single request (Figma's /nodes endpoint accepts comma-separated ids).
	 *
	 * @param string $file_key Figma file key.
	 * @param array  $node_ids Node IDs to resolve.
	 * @return array|WP_Error Map of node_id => document array, or WP_Error.
	 */
	private function fetch_nodes_batch( $file_key, array $node_ids ) {
		if ( empty( $node_ids ) ) {
			return array();
		}

		$response = $this->request(
			'/files/' . rawurlencode( $file_key ) . '/nodes',
			array( 'ids' => implode( ',', $node_ids ) )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$nodes     = isset( $response['nodes'] ) && is_array( $response['nodes'] ) ? $response['nodes'] : array();
		$documents = array();

		foreach ( $node_ids as $node_id ) {
			if ( isset( $nodes[ $node_id ]['document'] ) && is_array( $nodes[ $node_id ]['document'] ) ) {
				$documents[ $node_id ] = $nodes[ $node_id ]['document'];
			}
		}

		return $documents;
	}

	/**
	 * Extract a CSS-usable color from a resolved FILL-style node's first
	 * solid fill.
	 *
	 * @param array $document Resolved node document.
	 * @return string|null Hex or rgba() color string, or null if no solid fill was found.
	 */
	private function extract_fill_color( array $document ) {
		if ( empty( $document['fills'] ) || ! is_array( $document['fills'] ) ) {
			return null;
		}

		foreach ( $document['fills'] as $fill ) {
			if ( is_array( $fill ) && isset( $fill['type'] ) && 'SOLID' === $fill['type']
				&& isset( $fill['color'] ) && is_array( $fill['color'] ) ) {
				return $this->figma_color_to_css( $fill['color'] );
			}
		}

		return null;
	}

	/**
	 * Convert a Figma color object ({r,g,b,a} each 0-1 float) to a CSS color
	 * string. Fully opaque colors become #RRGGBB hex; anything with alpha
	 * below 1 becomes rgba(r, g, b, a) so transparency is preserved.
	 *
	 * @param array $color Figma color object.
	 * @return string CSS color string.
	 */
	private function figma_color_to_css( array $color ) {
		$r = isset( $color['r'] ) ? (float) $color['r'] : 0.0;
		$g = isset( $color['g'] ) ? (float) $color['g'] : 0.0;
		$b = isset( $color['b'] ) ? (float) $color['b'] : 0.0;
		$a = isset( $color['a'] ) ? (float) $color['a'] : 1.0;

		$r255 = (int) round( $r * 255 );
		$g255 = (int) round( $g * 255 );
		$b255 = (int) round( $b * 255 );

		if ( $a >= 0.999 ) {
			return sprintf( '#%02X%02X%02X', $r255, $g255, $b255 );
		}

		// Trim to a compact decimal (e.g. 0.5, 0.25) without trailing zeros.
		$alpha = rtrim( rtrim( number_format( $a, 3, '.', '' ), '0' ), '.' );
		if ( '' === $alpha ) {
			$alpha = '0';
		}

		return sprintf( 'rgba(%d, %d, %d, %s)', $r255, $g255, $b255, $alpha );
	}

	/**
	 * Extract typography properties from a resolved TEXT-style node.
	 *
	 * lineHeightPx is passed through as a pixel value (matching Figma's own
	 * field name) rather than converted to a unitless ratio — simpler for the
	 * theme-side CSS consumer, which can divide by fontSize itself if it
	 * wants a ratio instead.
	 *
	 * @param array $document Resolved node document.
	 * @return array{fontFamily:string,fontWeight:int,fontSize:int,lineHeightPx?:int}|null
	 */
	private function extract_typography( array $document ) {
		if ( empty( $document['style'] ) || ! is_array( $document['style'] ) ) {
			return null;
		}

		$style = $document['style'];

		$typography = array(
			'fontFamily' => isset( $style['fontFamily'] ) ? (string) $style['fontFamily'] : '',
			'fontWeight' => isset( $style['fontWeight'] ) ? (int) $style['fontWeight'] : 400,
			'fontSize'   => isset( $style['fontSize'] ) ? (int) round( (float) $style['fontSize'] ) : 0,
		);

		if ( isset( $style['lineHeightPx'] ) ) {
			$typography['lineHeightPx'] = (int) round( (float) $style['lineHeightPx'] );
		}

		return $typography;
	}

	/**
	 * Slugify a Figma style name into a CSS-custom-property-safe key.
	 *
	 * Figma style names are often hierarchical ("Brand/Primary",
	 * "Heading/H1") — the whole path is slugified into a single dashed key
	 * (e.g. "brand-primary", "heading-h1").
	 *
	 * @param string $name Raw Figma style name.
	 * @return string Slug containing only [a-z0-9-], or '' if nothing usable remained.
	 */
	private function slugify_style_name( $name ) {
		$slug = strtolower( (string) $name );
		$slug = str_replace( array( '/', ' ' ), '-', $slug );
		$slug = preg_replace( '/[^a-z0-9-]/', '', $slug );
		$slug = preg_replace( '/-+/', '-', $slug );
		return trim( $slug, '-' );
	}
}
