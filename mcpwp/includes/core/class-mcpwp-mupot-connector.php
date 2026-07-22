<?php
/**
 * mupot WordPress Connector
 *
 * Pure HTTP client of mupot's existing runtime-adapter/v1 contract. Each WP
 * user carries their own personal mupot member token and squad id; identity
 * and capability are resolved entirely server-side by mupot from the bearer
 * token. This class never asserts who a user is or what they may do — it
 * only supplies the squad scope task_board requires for non-agent-bound
 * (ordinary human) member tokens.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for the per-user mupot connector.
 */
class Mcpwp_Mupot_Connector {

	/**
	 * User meta key storing the encrypted personal mupot member token.
	 *
	 * @var string
	 */
	const TOKEN_META_KEY = 'mcpwp_mupot_member_token';

	/**
	 * User meta key storing the plain mupot squad id/slug.
	 *
	 * @var string
	 */
	const SQUAD_META_KEY = 'mcpwp_mupot_squad_id';

	/**
	 * Default mupot instance URL when mupot_instance_url is unset.
	 *
	 * @var string
	 */
	const DEFAULT_INSTANCE_URL = 'https://mupot.mumega.com';

	/**
	 * Transient cache TTL in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL = 60;

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 5;

	/**
	 * Get the current user's mupot status (self-scoped, no squad required).
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array|WP_Error Parsed mupot result, or error.
	 */
	public function get_status( $wp_user_id ) {
		return $this->call_cached( $wp_user_id, 'status', array() );
	}

	/**
	 * Get the current user's mupot task board.
	 *
	 * Requires a stored squad id — task_board rejects non-agent-bound
	 * (ordinary human) tokens with 400 invalid_args when squad_id is absent.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array|WP_Error Parsed mupot result, or error.
	 */
	public function get_task_board( $wp_user_id ) {
		if ( ! $this->has_squad( $wp_user_id ) ) {
			return new WP_Error(
				'mupot_squad_not_configured',
				__( 'Add your mupot squad id in your profile to see your task board.', 'mcpwp' )
			);
		}

		return $this->call_cached( $wp_user_id, 'task_board', array( 'squad_id' => $this->get_squad( $wp_user_id ) ) );
	}

	/**
	 * Clear this user's cached status + task_board results.
	 *
	 * Call after a Profile-screen save that actually changes the stored
	 * token or squad id, so Control Room doesn't keep showing a previous
	 * identity's status or a previous squad's task board for up to
	 * CACHE_TTL seconds. Reuses the same cache-key construction as
	 * call_cached() — must stay in sync with it.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return void
	 */
	public function clear_cache( $wp_user_id ) {
		delete_transient( 'mcpwp_mupot_status_' . (int) $wp_user_id );
		delete_transient( 'mcpwp_mupot_task_board_' . (int) $wp_user_id );
	}

	/**
	 * Encrypt and store a user's personal mupot member token.
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $raw_token  Plaintext member token.
	 * @return bool True on success.
	 */
	public function save_token( $wp_user_id, $raw_token ) {
		$encrypted = Mcpwp_Encryption::get_instance()->encrypt( (string) $raw_token );
		if ( false === $encrypted ) {
			return false;
		}
		update_user_meta( $wp_user_id, self::TOKEN_META_KEY, $encrypted );
		return true;
	}

	/**
	 * Store a user's mupot squad id (plain — not secret).
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $squad_id   Squad id or slug.
	 * @return bool True on success.
	 */
	public function save_squad( $wp_user_id, $squad_id ) {
		update_user_meta( $wp_user_id, self::SQUAD_META_KEY, sanitize_text_field( (string) $squad_id ) );
		return true;
	}

	/**
	 * Whether the given user has a stored token.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return bool
	 */
	public function has_token( $wp_user_id ) {
		return '' !== (string) get_user_meta( $wp_user_id, self::TOKEN_META_KEY, true );
	}

	/**
	 * Whether the given user has a stored squad id.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return bool
	 */
	public function has_squad( $wp_user_id ) {
		return '' !== $this->get_squad( $wp_user_id );
	}

	/**
	 * Get the given user's stored squad id (empty string if unset).
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return string
	 */
	public function get_squad( $wp_user_id ) {
		return (string) get_user_meta( $wp_user_id, self::SQUAD_META_KEY, true );
	}

	/**
	 * Decrypt the given user's stored token.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return string|false Decrypted token, or false if unset/undecryptable.
	 */
	private function get_decrypted_token( $wp_user_id ) {
		$encrypted = (string) get_user_meta( $wp_user_id, self::TOKEN_META_KEY, true );
		if ( '' === $encrypted ) {
			return false;
		}
		return Mcpwp_Encryption::get_instance()->decrypt( $encrypted );
	}

	/**
	 * Get the configured mupot instance URL.
	 *
	 * Enforces HTTPS: any non-empty configured URL that does not start with
	 * https:// (case-insensitive) is treated as invalid and falls back to the
	 * default. Defense-in-depth to prevent accidental bearer token leakage
	 * over insecure connections.
	 *
	 * @return string
	 */
	private function get_instance_url() {
		$settings = get_option( 'mcpwp_settings', array() );
		$url      = isset( $settings['mupot_instance_url'] ) ? trim( (string) $settings['mupot_instance_url'] ) : '';

		// Enforce HTTPS: reject any configured URL that isn't HTTPS.
		if ( '' !== $url && 0 !== stripos( $url, 'https://' ) ) {
			return self::DEFAULT_INSTANCE_URL;
		}

		return '' !== $url ? untrailingslashit( $url ) : self::DEFAULT_INSTANCE_URL;
	}

	/**
	 * Call a tool with the 60s transient cache in front of it.
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $tool       mupot tool name.
	 * @param array  $args       Tool arguments.
	 * @return array|WP_Error
	 */
	private function call_cached( $wp_user_id, $tool, $args ) {
		$cache_key = 'mcpwp_mupot_' . $tool . '_' . (int) $wp_user_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->call_tool( $wp_user_id, $tool, $args );

		if ( ! is_wp_error( $result ) ) {
			set_transient( $cache_key, $result, self::CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Make the actual POST /mcp call.
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $tool       mupot tool name.
	 * @param array  $args       Tool arguments.
	 * @return array|WP_Error
	 */
	private function call_tool( $wp_user_id, $tool, $args ) {
		$token = $this->get_decrypted_token( $wp_user_id );
		if ( false === $token || '' === $token ) {
			return new WP_Error(
				'mupot_not_connected',
				__( 'Connect your mupot account in your profile to see this.', 'mcpwp' )
			);
		}

		$response = wp_remote_post(
			$this->get_instance_url() . '/mcp',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'tool' => $tool,
						// Cast to object: an empty PHP array (the `status` call's
						// args) encodes as JSON `[]`, but mupot's tool schemas
						// declare args as type object — `{}` is required even
						// when empty. (object) array() gives an empty stdClass,
						// which json_encode renders as `{}`.
						'args' => (object) $args,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mupot_unavailable',
				__( 'mupot is unavailable right now.', 'mcpwp' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// A 400 invalid_args on task_board means mupot rejected the call
		// because no (or an unusable) squad_id was sent — treat it the
		// same as "no squad stored" so the UI re-shows the squad-not-
		// configured prompt instead of a generic "unavailable" message.
		if ( 400 === $code && 'task_board' === $tool ) {
			return new WP_Error(
				'mupot_squad_not_configured',
				__( 'Add your mupot squad id in your profile to see your task board.', 'mcpwp' )
			);
		}

		if ( 401 === $code || 403 === $code ) {
			if ( 'task_board' === $tool ) {
				return new WP_Error(
					'mupot_squad_forbidden',
					__( "Your mupot token doesn't have access to that squad — check the squad id in your profile.", 'mcpwp' )
				);
			}
			return new WP_Error(
				'mupot_unauthorized',
				__( 'Your mupot token is invalid or has been revoked — reconnect from your profile.', 'mcpwp' )
			);
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'mupot_unavailable',
				__( 'mupot is unavailable right now.', 'mcpwp' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['result'] ) || ! is_array( $body['result'] ) ) {
			return new WP_Error(
				'mupot_unavailable',
				__( 'mupot is unavailable right now.', 'mcpwp' )
			);
		}

		return $body['result'];
	}
}
