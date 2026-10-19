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
	 * The one lock every writer of the key store holds (#1098 round 2):
	 * create, revoke and regenerate (Mcpwp_Api_Auth), the first-use move of
	 * a legacy key, and the 2.8 spai adoption. Not re-entrant, and no holder
	 * calls another public writer while holding it (regenerate uses the
	 * unlocked create core), so nothing nests or deadlocks.
	 */
	const STORE_LOCK_OPTION = 'mcpwp_key_store_lock';
	const STORE_LOCK_TTL    = 15;

	/**
	 * Take the key-store lock, waiting up to $wait_ms for a holder to finish.
	 *
	 * Uses Mcpwp_Activator's atomic INSERT / compare-and-swap lock row. On
	 * success the option caches are dropped, so the caller's next read of the
	 * store (and of any flag) comes from the database, not from a snapshot
	 * this request took before another request's write.
	 *
	 * @param int|null $wait_ms Milliseconds to wait; null = the filterable default (2000).
	 * @return string|false Token for release_store_lock(), or false when busy.
	 */
	public static function acquire_store_lock( $wait_ms = null ) {
		if ( ! class_exists( 'Mcpwp_Activator' ) ) {
			require_once dirname( __DIR__ ) . '/class-mcpwp-activator.php';
		}
		if ( null === $wait_ms ) {
			$wait_ms = function_exists( 'apply_filters' ) ? (int) apply_filters( 'mcpwp_key_store_lock_wait_ms', 2000 ) : 2000;
		}
		$deadline = microtime( true ) + max( 0, (int) $wait_ms ) / 1000;
		do {
			$token = Mcpwp_Activator::acquire_migration_lock( self::STORE_LOCK_OPTION, self::STORE_LOCK_TTL );
			if ( false !== $token ) {
				self::refresh_option_caches();
				return $token;
			}
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			usleep( 50000 );
		} while ( true );
	}

	/**
	 * Release a token from acquire_store_lock().
	 *
	 * @param string|false $token Token.
	 */
	public static function release_store_lock( $token ) {
		if ( false !== $token ) {
			Mcpwp_Activator::release_migration_lock( self::STORE_LOCK_OPTION, $token );
		}
	}

	/**
	 * Drop the autoloaded-options caches (alloptions/notoptions) and the
	 * key options' own entries, so the next get_option() reads the database.
	 */
	public static function refresh_option_caches() {
		if ( ! function_exists( 'wp_cache_delete' ) ) {
			return;
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		foreach ( array( 'mcpwp_api_keys', 'mcpwp_api_key', 'spai_api_keys', 'spai_api_key', 'mcpwp_spai_credentials_adopted' ) as $name ) {
			wp_cache_delete( $name, 'options' );
		}
	}

	/** The error a writer returns when the store lock stays busy. */
	public static function busy_message() {
		return __( 'API keys are being updated by another request. Nothing was changed; try again in a moment.', 'mcpwp' );
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
