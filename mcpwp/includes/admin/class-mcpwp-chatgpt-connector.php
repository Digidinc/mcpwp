<?php
/**
 * "Add to ChatGPT" — one connector link per site.
 *
 * ChatGPT's custom MCP server form takes a URL and an authentication choice;
 * it has no field for a request header. The zero-account path is therefore a
 * URL that carries a key (`?api_key=`, already accepted by
 * Mcpwp_Api_Auth::get_api_key_from_request()) with authentication set to
 * "No authentication".
 *
 * This class mints no new kind of credential. The link's key is an ordinary
 * scoped key from create_scoped_api_key(): a custom role limited to
 * CATEGORIES, read + write scope, never admin. It is the Editor role minus
 * the Elementor theme-builder and template categories (elementor-theme,
 * elementor-templates), whose write routes can publish site-wide
 * headers/footers and already-published templates without a `status` param
 * (adversarial review of #1090, P1-1). That is not "no template can be
 * created": /elementor/save-section-as-template is classified under the
 * allowed `elementor-build` category and stays reachable, and it creates a
 * published `elementor_library` section template (#1096 P2-3). Whether to
 * block it is an owner decision; this comment only records the behaviour.
 * What remains is stated on the Setup page claim by claim, and
 * ChatGptConnectorTest pins each one.
 * The only state kept here is which scoped key id is the ChatGPT one, so the
 * Setup page can show its status and rotate or revoke exactly that key.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, rotates and revokes the site's ChatGPT connector key.
 */
class Mcpwp_ChatGPT_Connector {

	/**
	 * Option holding the scoped key id of the current ChatGPT connector key.
	 *
	 * @var string
	 */
	const OPTION_KEY_ID = 'mcpwp_chatgpt_connector_key_id';

	/**
	 * Role the connector key is minted with. Never 'admin'.
	 *
	 * @var string
	 */
	const ROLE = 'custom';

	/**
	 * Tool categories the connector key may reach.
	 *
	 * Deliberately excludes elementor-theme, elementor-templates, site,
	 * admin, forms, webhooks, woocommerce and every other category.
	 *
	 * @var string[]
	 */
	const CATEGORIES = array( 'content', 'media', 'taxonomy', 'seo', 'elementor', 'elementor-build', 'elementor-info' );

	/**
	 * Scopes the connector key is minted with. Never 'admin'.
	 *
	 * @var string[]
	 */
	const SCOPES = array( 'read', 'write' );

	/**
	 * Object using the Mcpwp_Api_Auth trait (Mcpwp_Admin in production).
	 *
	 * @var object
	 */
	private $auth;

	/**
	 * @param object $auth Object exposing create_scoped_api_key(),
	 *                     revoke_scoped_api_key() and list_scoped_api_keys().
	 */
	public function __construct( $auth ) {
		$this->auth = $auth;
	}

	/**
	 * The MCP endpoint ChatGPT should call, without any key.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( 'mcpwp/v1/mcp' );
	}

	/**
	 * Whether the endpoint is HTTPS (or the site is a local development site).
	 *
	 * @return bool
	 */
	public static function endpoint_is_secure() {
		if ( 0 === strpos( self::endpoint_url(), 'https://' ) ) {
			return true;
		}
		return function_exists( 'wp_get_environment_type' ) && 'local' === wp_get_environment_type();
	}

	/**
	 * The connector link: the endpoint with the key in `api_key`.
	 *
	 * @param string $plain_key Plaintext key, available only at creation.
	 * @return string
	 */
	public static function connector_url( $plain_key ) {
		return add_query_arg( 'api_key', rawurlencode( (string) $plain_key ), self::endpoint_url() );
	}

	/**
	 * Metadata of the active connector key, or null when there is none
	 * (never created, revoked here, or revoked from the keys table).
	 *
	 * @return array|null
	 */
	public function get_active_key() {
		$key_id = (string) get_option( self::OPTION_KEY_ID, '' );
		if ( '' === $key_id ) {
			return null;
		}
		foreach ( $this->auth->list_scoped_api_keys( false ) as $key ) {
			if ( isset( $key['id'] ) && $key['id'] === $key_id ) {
				return $key;
			}
		}
		return null;
	}

	/**
	 * Mint a new connector key, revoking the previous one first.
	 *
	 * Create and rotate are the same operation: the old link stops working
	 * the moment a new one exists, so there is never more than one live
	 * ChatGPT link per site.
	 *
	 * @return array{url:string,key:array}|WP_Error
	 */
	public function create_or_rotate() {
		if ( ! self::endpoint_is_secure() ) {
			return new WP_Error(
				'mcpwp_chatgpt_needs_https',
				__( 'This site\'s REST URL is not HTTPS, so a link carrying a key would travel in clear text. Enable HTTPS for the site first.', 'mcpwp' )
			);
		}

		$had_link = $this->revoke();

		$created = $this->auth->create_scoped_api_key(
			__( 'ChatGPT connector', 'mcpwp' ),
			self::SCOPES,
			self::ROLE,
			self::CATEGORIES
		);

		if ( ! is_array( $created ) || empty( $created['key'] ) || empty( $created['id'] ) ) {
			$message = ( is_array( $created ) && isset( $created['message'] ) )
				? $created['message']
				: __( 'Could not create the ChatGPT connector key.', 'mcpwp' );
			if ( $had_link ) {
				$message .= ' ' . __( 'The previous ChatGPT link was already revoked.', 'mcpwp' );
			}
			return new WP_Error( 'mcpwp_chatgpt_key_failed', $message );
		}

		update_option( self::OPTION_KEY_ID, $created['id'], false );

		$plain = $created['key'];
		unset( $created['key'] );

		return array(
			'url' => self::connector_url( $plain ),
			'key' => $created,
		);
	}

	/**
	 * Nonce action for every connector form on the Setup page.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'mcpwp_chatgpt_connector';

	/**
	 * Handle a Setup-page POST for the connector.
	 *
	 * Checks capability and nonce itself, so the guarantee does not rest on
	 * the caller alone (the Setup page also runs check_admin_referer()).
	 *
	 * @param array $post Unslashed request fields.
	 * @return array{type:string,message:string,url?:string}|null Null when the
	 *         request is not a connector action.
	 */
	public function handle_request( array $post ) {
		if ( ! isset( $post['mcpwp_chatgpt_connector_action'] ) ) {
			return null;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return array( 'type' => 'error', 'message' => __( 'You are not allowed to manage the ChatGPT link.', 'mcpwp' ) );
		}
		$nonce = isset( $post['mcpwp_chatgpt_connector_nonce'] ) ? (string) $post['mcpwp_chatgpt_connector_nonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return array( 'type' => 'error', 'message' => __( 'The form expired. Reload the page and try again.', 'mcpwp' ) );
		}

		$action = sanitize_key( (string) $post['mcpwp_chatgpt_connector_action'] );
		if ( 'create' === $action ) {
			$result = $this->create_or_rotate();
			if ( is_wp_error( $result ) ) {
				return array( 'type' => 'error', 'message' => $result->get_error_message() );
			}
			return array(
				'type'    => 'updated',
				'message' => __( 'ChatGPT link created. Copy it now — it will not be shown again. Any earlier ChatGPT link has stopped working.', 'mcpwp' ),
				'url'     => $result['url'],
			);
		}
		if ( 'revoke' === $action ) {
			$this->revoke();
			return array( 'type' => 'updated', 'message' => __( 'ChatGPT link revoked. ChatGPT can no longer reach this site with it.', 'mcpwp' ) );
		}
		return null;
	}

	/**
	 * Whether OAuth discovery would send ChatGPT to the wrong sign-in.
	 *
	 * Only the mumcp.com broker is a conflict, and only while this site's own
	 * OAuth is off: then a request with a missing, mistyped or revoked key
	 * answers 401 + WWW-Authenticate pointing at mumcp.com, and ChatGPT asks
	 * for a mumcp.com sign-in instead of failing plainly.
	 *
	 * With local OAuth on (the default since PR-2, D10), discovery names this
	 * site as the authorization server (D11), so the sign-in ChatGPT shows is
	 * the WordPress login — the second, intended connection path, not a
	 * conflict.  The former 'legacy' warning is therefore gone (D14).
	 *
	 * @return string 'broker' or ''.
	 */
	public static function oauth_discovery_conflict() {
		if ( ! class_exists( 'Mcpwp_OAuth_Well_Known' ) ) {
			return '';
		}
		if ( ! Mcpwp_OAuth_Well_Known::is_legacy_oauth_enabled() && Mcpwp_OAuth_Well_Known::is_broker_enrolled() ) {
			return 'broker';
		}
		return '';
	}

	/**
	 * Revoke the current connector key, if any, and forget it.
	 *
	 * @return bool True when a live key was revoked.
	 */
	public function revoke() {
		$key_id = (string) get_option( self::OPTION_KEY_ID, '' );
		delete_option( self::OPTION_KEY_ID );
		if ( '' === $key_id ) {
			return false;
		}
		return (bool) $this->auth->revoke_scoped_api_key( $key_id );
	}
}
