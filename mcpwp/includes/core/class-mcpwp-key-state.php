<?php
/**
 * One answer to "has this site been given an API key?"
 *
 * Three surfaces used to answer it by reading the legacy single-key option
 * `mcpwp_api_key` alone: the Setup page, the activator's first-key mint and
 * the admin test-connection check. That option is only a legacy copy — the
 * scoped store `mcpwp_api_keys` is where live keys are — so a site whose
 * option was cleared (by hand, or by an older revoke) but which has live
 * scoped keys was shown "No API key configured", offered a hero "Generate
 * API Key" that revokes every key, and minted a phantom key on upgrade that
 * could never authenticate (#1097 review round 2).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only helpers over the two key stores.
 */
class Mcpwp_Key_State {

	/**
	 * Whether the site has any key: the legacy option, or an active scoped record.
	 *
	 * @return bool
	 */
	public static function site_has_api_key() {
		return '' !== (string) get_option( 'mcpwp_api_key', '' ) || self::has_active_scoped_key();
	}

	/**
	 * Whether the scoped store holds at least one non-revoked record.
	 *
	 * @return bool
	 */
	public static function has_active_scoped_key() {
		foreach ( self::scoped_records() as $record ) {
			if ( is_array( $record ) && empty( $record['revoked_at'] ) && ! empty( $record['hash'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the site has never had a key at all — both stores empty.
	 *
	 * Only then may the activator mint a first key: on any other site a minted
	 * legacy key would be refused (legacy_api_key_option_is_honoured() needs an
	 * empty scoped store) and shown to the admin as a key that never works.
	 *
	 * @return bool
	 */
	public static function is_fresh_site() {
		return '' === (string) get_option( 'mcpwp_api_key', '' ) && array() === self::scoped_records();
	}

	/**
	 * Raw scoped records.
	 *
	 * @return array
	 */
	private static function scoped_records() {
		$records = get_option( 'mcpwp_api_keys', array() );
		return is_array( $records ) ? $records : array();
	}
}
