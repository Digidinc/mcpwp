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
 * scoped key from create_scoped_api_key(), fixed to the least privilege that
 * still lets ChatGPT do real work: the Editor role (content, media, taxonomy,
 * SEO, Elementor), read + write scope, never admin. A write key cannot
 * publish (3.13), change settings, or manage keys. The only state kept here
 * is which scoped key id is the ChatGPT one, so the Setup page can show its
 * status and rotate or revoke exactly that key without touching others.
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
	const ROLE = 'editor';

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
		$this->revoke();

		$created = $this->auth->create_scoped_api_key(
			__( 'ChatGPT connector', 'mcpwp' ),
			self::SCOPES,
			self::ROLE
		);

		if ( ! is_array( $created ) || empty( $created['key'] ) || empty( $created['id'] ) ) {
			$message = ( is_array( $created ) && isset( $created['message'] ) )
				? $created['message']
				: __( 'Could not create the ChatGPT connector key.', 'mcpwp' );
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
