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
		return '' === (string) get_option( 'mcpwp_api_key', '' ) && array() === self::scoped_records() && ! self::store_is_unreadable();
	}

	/**
	 * Whether the scoped store holds something that is not a list of keys.
	 *
	 * A truncated or corrupt value reads as an empty store through
	 * scoped_records() (an array() fallback, whether get_option() returned a
	 * string or false); every caller that would WRITE on the strength of "empty"
	 * (the first-key mint, the legacy migration, the activator) must ask this
	 * first, because overwriting it would drop every revocation record and let
	 * the legacy key and the spai_ copies of revoked keys authenticate again
	 * (#1096).
	 *
	 * @return bool
	 */
	public static function store_is_unreadable() {
		// Distinguish "no row" (a fresh or empty store) from "a row that is not
		// a list of keys". get_option() hands back FALSE for a truncated value
		// whose last character is ';' or '}' (core's is_serialized() accepts it,
		// unserialize() then fails), so false is NOT "not unreadable": only the
		// absence of the row, or a serialised empty array, is. An empty string
		// ('' or 's:0:"";') is NOT an empty store either: nothing in the plugin
		// writes one, so a present row that is not a list of keys is always
		// someone's damage, and treating it as empty would let the legacy key and
		// the spai_ copy of a revoked key authenticate (#1096).
		$absent = '__mcpwp_row_absent__';
		$value  = get_option( 'mcpwp_api_keys', $absent );
		return $absent !== $value && ! is_array( $value );
	}

	/**
	 * What to tell an administrator about an unreadable key store.
	 *
	 * Deliberately NOT "delete it": with the store gone the legacy single key
	 * and the keys copied from 2.8 (spai_*) are honoured again, revoked keys
	 * included.
	 *
	 * @return string
	 */
	public static function unreadable_message() {
		return __( 'The stored MCPWP API keys (option "mcpwp_api_keys") cannot be read, so every API key is refused and nothing can be created, revoked or rotated. Restore that option from a backup or contact MCPWP support. Do not simply delete it: that would make the old single key and the keys copied from version 2.8 (options "mcpwp_api_key", "spai_api_key", "spai_api_keys") work again, including keys that were revoked; clear those options first if you must delete it.', 'mcpwp' );
	}

	/**
	 * Whether a stored value is a password hash, as wp_check_password() sees it.
	 *
	 * ONE predicate for every place that classes a credential as hash or
	 * plaintext. A hash is anything leading with `$`: phpass ($P$), bcrypt
	 * ($2y$), WordPress 6.8's prefixed form ($wp$2y$...) and whatever the
	 * `wp_hash_password_algorithm` filter produces, such as a bare
	 * `$argon2id$...`. A narrower list classed an Argon2 hash as plaintext,
	 * re-hashed it, and refused the real key (#1178 review).
	 *
	 * @param mixed $value Stored value.
	 * @return bool
	 */
	public static function is_password_hash_shaped( $value ) {
		return is_string( $value ) && '' !== $value && '$' === $value[0];
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
