<?php
/**
 * OAuth token revocation by epoch (#1101 PR-3a).
 *
 * Issued tokens live in transients keyed by a hash of the token, and
 * transients cannot be enumerated (not at all under an object cache), so a
 * revocation never goes looking for tokens.  Instead every access token,
 * refresh token and authorization code carries who it was issued to
 * (client_id, user_id), the refresh family it belongs to (family_id) and
 * when it was issued (issued_at), and a revocation records an EPOCH:
 *
 *  - per client: the registered record's revoked_before, or — for the
 *    legacy settings client and for a deleted client whose record is gone —
 *    an entry in CLIENT_EPOCHS_OPTION (a tombstone);
 *  - per user: the user meta USER_META, or — once the user is deleted and
 *    their meta with them — an entry in DELETED_USERS_OPTION, so a user id
 *    a database reuses later does not inherit the old tokens;
 *  - per refresh family: a marker (a transient for the fast path plus an
 *    entry in FAMILIES_OPTION, because an object cache may evict a
 *    transient and a revocation must not come undone that way).
 *
 * A token is revoked when issued_at <= the epoch that applies to it.  Epochs
 * and issued_at are microtime(true): a token issued in the same second
 * after a revocation is not caught by it, and a clock that steps backwards
 * can only revoke more (a new token issued "before" the epoch dies), never
 * resurrect one.  A record without issued_at (minted before this release)
 * falls back to created_at, then 0 — any epoch catches it.
 *
 * The options are not autoloaded and are pruned on every write: an entry
 * outlives every token it can apply to (refresh TTL plus the longest access
 * TTL) and is then dropped.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Epoch-based revocation of OAuth tokens.
 */
class Mcpwp_OAuth_Revocation {

	/** Per-user epoch for a live user. */
	const USER_META = 'mcpwp_oauth_revoked_before';

	/** user_id => epoch for deleted users (their meta is deleted with them). */
	const DELETED_USERS_OPTION = 'mcpwp_oauth_deleted_users';

	/** client_id => epoch for the legacy client and for deleted clients. */
	const CLIENT_EPOCHS_OPTION = 'mcpwp_oauth_client_epochs';

	/** family_id => epoch for revoked refresh families. */
	const FAMILIES_OPTION = 'mcpwp_oauth_revoked_families';

	/** Transient prefix for the family marker's fast path. */
	const FAMILY_TRANSIENT_PREFIX = 'mcpwp_oauth_famrev_';

	/** Lock row for the three options above (Mcpwp_OAuth_Clients::acquire_lock()). */
	const LOCK_OPTION = 'mcpwp_oauth_revocation_lock';

	/**
	 * Register the user-deletion hooks.
	 */
	public static function init() {
		if ( function_exists( 'add_action' ) ) {
			add_action( 'delete_user', array( __CLASS__, 'on_user_deleted' ) );
			add_action( 'wpmu_delete_user', array( __CLASS__, 'on_user_deleted' ) );
		}
	}

	/**
	 * The clock every epoch and issued_at uses.
	 *
	 * @return float
	 */
	public static function now() {
		return microtime( true );
	}

	/**
	 * When a stored token record was issued.
	 *
	 * @param array $record Token, refresh or code record.
	 * @return float 0.0 when unknown.
	 */
	public static function issued_at( array $record ) {
		if ( isset( $record['issued_at'] ) && is_numeric( $record['issued_at'] ) ) {
			return (float) $record['issued_at'];
		}
		if ( isset( $record['created_at'] ) && is_numeric( $record['created_at'] ) ) {
			return (float) $record['created_at'];
		}
		return 0.0;
	}

	/**
	 * Whether a token record is caught by any revocation that applies to it.
	 *
	 * @param array $record Token, refresh or code record.
	 * @return bool
	 */
	public static function is_revoked( array $record ) {
		$at = self::issued_at( $record );

		$user_id = isset( $record['user_id'] ) ? (int) $record['user_id'] : 0;
		if ( $user_id > 0 ) {
			$epoch = self::user_epoch( $user_id );
			if ( $epoch > 0 && $at <= $epoch ) {
				return true;
			}
		}

		$client_id = isset( $record['client_id'] ) && is_string( $record['client_id'] ) ? $record['client_id'] : '';
		if ( '' !== $client_id ) {
			$epoch = self::client_epoch( $client_id );
			if ( $epoch > 0 && $at <= $epoch ) {
				return true;
			}
		}

		$family_id = isset( $record['family_id'] ) && is_string( $record['family_id'] ) ? $record['family_id'] : '';
		if ( '' !== $family_id && self::family_revoked( $family_id ) ) {
			return true;
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Epoch readers
	// -------------------------------------------------------------------------

	/**
	 * @param int $user_id User id.
	 * @return float
	 */
	public static function user_epoch( $user_id ) {
		$user_id = (int) $user_id;
		$epoch   = 0.0;
		if ( function_exists( 'get_user_meta' ) ) {
			$meta = get_user_meta( $user_id, self::USER_META, true );
			if ( is_numeric( $meta ) ) {
				$epoch = (float) $meta;
			}
		}
		$deleted = self::read_map( self::DELETED_USERS_OPTION );
		if ( isset( $deleted[ (string) $user_id ] ) ) {
			$epoch = max( $epoch, (float) $deleted[ (string) $user_id ] );
		}
		return $epoch;
	}

	/**
	 * @param string $client_id Client id.
	 * @return float
	 */
	public static function client_epoch( $client_id ) {
		$epoch = 0.0;
		if ( class_exists( 'Mcpwp_OAuth_Clients' ) ) {
			$record = Mcpwp_OAuth_Clients::find( $client_id );
			if ( is_array( $record ) && isset( $record['revoked_before'] ) && is_numeric( $record['revoked_before'] ) ) {
				$epoch = (float) $record['revoked_before'];
			}
		}
		$map = self::read_map( self::CLIENT_EPOCHS_OPTION );
		if ( isset( $map[ (string) $client_id ] ) ) {
			$epoch = max( $epoch, (float) $map[ (string) $client_id ] );
		}
		return $epoch;
	}

	/**
	 * @param string $family_id Refresh family id.
	 * @return bool
	 */
	public static function family_revoked( $family_id ) {
		$family_id = (string) $family_id;
		if ( '' === $family_id ) {
			return false;
		}
		if ( false !== get_transient( self::family_transient_key( $family_id ) ) ) {
			return true;
		}
		$map = self::read_map( self::FAMILIES_OPTION );
		return isset( $map[ $family_id ] );
	}

	// -------------------------------------------------------------------------
	// Revocations
	// -------------------------------------------------------------------------

	/**
	 * Revoke every token issued to a user so far.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function revoke_user( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! function_exists( 'update_user_meta' ) ) {
			return false;
		}
		$epoch = max( self::now(), self::user_epoch( $user_id ) );
		update_user_meta( $user_id, self::USER_META, $epoch );
		return self::user_epoch( $user_id ) >= $epoch;
	}

	/**
	 * delete_user / wpmu_delete_user: the user's meta is about to go, so the
	 * epoch is kept in an option.
	 *
	 * @param int $user_id User id.
	 */
	public static function on_user_deleted( $user_id ) {
		$user_id = (int) $user_id;
		if ( $user_id > 0 ) {
			self::write_map_entry( self::DELETED_USERS_OPTION, (string) $user_id, self::now() );
		}
	}

	/**
	 * Revoke every token issued to a client so far.  A registered client's
	 * record carries the epoch; any other client id (the legacy settings
	 * client) gets an entry in CLIENT_EPOCHS_OPTION.
	 *
	 * @param string $client_id Client id.
	 * @return bool
	 */
	public static function revoke_client( $client_id ) {
		$client_id = (string) $client_id;
		if ( '' === $client_id ) {
			return false;
		}
		$epoch = self::now();
		if ( class_exists( 'Mcpwp_OAuth_Clients' ) && null !== Mcpwp_OAuth_Clients::find( $client_id ) ) {
			return Mcpwp_OAuth_Clients::set_revoked_before( $client_id, $epoch );
		}
		return self::write_map_entry( self::CLIENT_EPOCHS_OPTION, $client_id, $epoch );
	}

	/**
	 * Tombstone a client that is about to be deleted.
	 *
	 * @param string $client_id Client id.
	 * @return bool
	 */
	public static function tombstone_client( $client_id ) {
		$client_id = (string) $client_id;
		return '' !== $client_id && self::write_map_entry( self::CLIENT_EPOCHS_OPTION, $client_id, self::now() );
	}

	/**
	 * Revoke a refresh family: every refresh token rotated from the same
	 * grant, and every access token issued alongside them.
	 *
	 * The transient is written first and unconditionally, so the family is
	 * dead at once even if the option write has to wait for its lock; the
	 * option makes it survive an object-cache eviction.
	 *
	 * @param string $family_id Family id.
	 * @return bool True when the persistent marker was written too.
	 */
	public static function revoke_family( $family_id ) {
		$family_id = (string) $family_id;
		if ( '' === $family_id ) {
			return false;
		}
		set_transient( self::family_transient_key( $family_id ), 1, self::retention() );
		return self::write_map_entry( self::FAMILIES_OPTION, $family_id, self::now() );
	}

	/**
	 * A fresh family id.
	 *
	 * @return string
	 */
	public static function new_family_id() {
		return bin2hex( random_bytes( 16 ) );
	}

	// -------------------------------------------------------------------------
	// Storage
	// -------------------------------------------------------------------------

	/**
	 * How long an epoch entry is kept: longer than any token it applies to.
	 *
	 * @return int Seconds.
	 */
	public static function retention() {
		$refresh = class_exists( 'Mcpwp_REST_OAuth' ) ? (int) Mcpwp_REST_OAuth::refresh_token_ttl() : 30 * DAY_IN_SECONDS;
		return $refresh + DAY_IN_SECONDS;
	}

	/**
	 * @param string $family_id Family id.
	 * @return string
	 */
	private static function family_transient_key( $family_id ) {
		return self::FAMILY_TRANSIENT_PREFIX . substr( hash( 'sha256', (string) $family_id ), 0, 40 );
	}

	/**
	 * @param string $option Option name.
	 * @return array<string,float>
	 */
	private static function read_map( $option ) {
		$stored = get_option( $option, array() );
		$map    = array();
		foreach ( is_array( $stored ) ? $stored : array() as $key => $epoch ) {
			if ( is_numeric( $epoch ) ) {
				$map[ (string) $key ] = (float) $epoch;
			}
		}
		return $map;
	}

	/**
	 * Set map[$key] = max(existing, $epoch) under the revocation lock, prune
	 * expired entries, and verify the write.
	 *
	 * @param string $option Option name.
	 * @param string $key    Entry key.
	 * @param float  $epoch  Epoch.
	 * @return bool
	 */
	private static function write_map_entry( $option, $key, $epoch ) {
		$token = Mcpwp_OAuth_Clients::acquire_lock( 0, self::LOCK_OPTION );
		if ( false === $token ) {
			return false;
		}
		Mcpwp_OAuth_Clients::flush_option_cache( $option );
		$map    = self::read_map( $option );
		$cutoff = self::now() - self::retention();
		foreach ( $map as $existing_key => $existing_epoch ) {
			if ( $existing_epoch < $cutoff ) {
				unset( $map[ $existing_key ] );
			}
		}
		$map[ (string) $key ] = max( (float) $epoch, (float) ( $map[ (string) $key ] ?? 0 ) );
		update_option( $option, $map, false );
		Mcpwp_OAuth_Clients::flush_option_cache( $option );
		$check = self::read_map( $option );
		Mcpwp_OAuth_Clients::release_lock( $token, self::LOCK_OPTION );
		return isset( $check[ (string) $key ] ) && $check[ (string) $key ] >= (float) $epoch;
	}
}
