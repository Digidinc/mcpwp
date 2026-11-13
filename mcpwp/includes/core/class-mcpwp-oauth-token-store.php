<?php
/**
 * Persistent, hashed OAuth token storage (#1101 PR-3b).
 *
 * Access tokens, refresh tokens and refresh reuse tombstones live in
 * {$wpdb->prefix}mcpwp_oauth_tokens, keyed by sha256(token) — the token
 * itself is never stored.  Authorization codes stay transients (60 s).
 *
 * Why a table: transients cannot be enumerated or listed (not at all under
 * an object cache), an object cache may evict them, and a row can carry
 * revoked_at, last_used_at and the (client, user, family) it belongs to —
 * which the "connected apps" lists and revocation need.
 *
 * Fail closed: a token is valid only when its row is found active.  If the
 * table is missing or a write fails, issuance refuses and validation finds
 * nothing; new tokens never fall back to transients.
 *
 * Migration: tokens issued before this release are transients.  While the
 * grace window is open (GRACE_OPTION, the longest refresh lifetime plus a
 * day from when the table was created) a token with no row is looked up in
 * its old transient; a valid one is moved into the table on that use (the
 * transient is deleted only once the row is written) and then follows the
 * table rules.  After the window the transient path is gone.
 *
 * Every claim is a single conditional UPDATE whose affected-row count is the
 * answer (=== 1 passes): never a check followed by a delete.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The OAuth token table.
 */
class Mcpwp_OAuth_Token_Store {

	/** Table suffix after $wpdb->prefix. */
	const TABLE = 'mcpwp_oauth_tokens';

	/** This table's own schema version (not the release version). */
	const SCHEMA_VERSION = 1;

	/** Option holding the installed schema version. */
	const SCHEMA_OPTION = 'mcpwp_oauth_tokens_schema_version';

	/** Option holding the end of the transient grace window (Unix seconds). */
	const GRACE_OPTION = 'mcpwp_oauth_tokens_grace_until';

	/** Daily cleanup of expired rows. */
	const CLEANUP_CRON_HOOK = 'mcpwp_oauth_tokens_cleanup';

	const TYPE_ACCESS    = 'access';
	const TYPE_REFRESH   = 'refresh';
	const TYPE_TOMBSTONE = 'reuse_tombstone';

	/** live_apps_for_user() key for tokens from before #1101 (no client). */
	const LEGACY_APP = 'legacy';

	/** last_used_at is written at most this often per token (seconds). */
	const TOUCH_INTERVAL = 60;

	/**
	 * @return string Prefixed table name.
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * CREATE TABLE for dbDelta() (no IF NOT EXISTS — see the activator's
	 * action_log_create_sql() for why dbDelta needs the plain form).
	 *
	 * Times: issued_at and revoked_at are integer microseconds (the
	 * Mcpwp_OAuth_Revocation clock); expires_at and last_used_at are Unix
	 * seconds.
	 *
	 * @param string $table           Prefixed table name.
	 * @param string $charset_collate $wpdb->get_charset_collate().
	 * @return string
	 */
	public static function create_sql( $table, $charset_collate ) {
		return "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			type varchar(20) NOT NULL,
			client_id varchar(100) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			family_id varchar(64) NOT NULL DEFAULT '',
			scopes varchar(100) NOT NULL DEFAULT '',
			aud varchar(2048) NOT NULL DEFAULT '',
			issued_at bigint(20) unsigned NOT NULL DEFAULT 0,
			expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			last_used_at bigint(20) unsigned NOT NULL DEFAULT 0,
			revoked_at bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY client_id (client_id),
			KEY user_id (user_id),
			KEY family_id (family_id),
			KEY expires_at (expires_at)
		) $charset_collate;";
	}

	/**
	 * Create or upgrade the table when the stored schema version is behind,
	 * verify it exists, then record the version and open the grace window.
	 * Called from Mcpwp_Activator::create_tables() and on every load (cheap
	 * once current — one autoloaded option read).
	 *
	 * @return bool True when the table is current.
	 */
	public static function ensure_table() {
		global $wpdb;
		if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return true;
		}
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( self::create_sql( self::table(), $wpdb->get_charset_collate() ) );
		}
		if ( ! self::table_exists() ) {
			return false;
		}
		if ( false === get_option( self::GRACE_OPTION, false ) ) {
			update_option( self::GRACE_OPTION, time() + self::grace_seconds(), false );
		}
		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		return true;
	}

	/**
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return is_string( $found ) && $found === $table;
	}

	/**
	 * Length of the transient grace window: the longest refresh lifetime
	 * plus a day, so every token issued before the table existed has
	 * expired by its end.
	 *
	 * @return int Seconds.
	 */
	public static function grace_seconds() {
		return 30 * DAY_IN_SECONDS + DAY_IN_SECONDS;
	}

	/**
	 * Whether the transient fallback is still open.
	 *
	 * @return bool
	 */
	public static function grace_open() {
		$until = get_option( self::GRACE_OPTION, false );
		return false === $until || (int) $until > time();
	}

	/**
	 * @param string $token Token string.
	 * @return string sha256 hex.
	 */
	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}

	// -------------------------------------------------------------------------
	// Writes
	// -------------------------------------------------------------------------

	/**
	 * D8: OAuth never grants admin.  The scopes a token bound to a WordPress
	 * user may carry: `admin` is dropped and replaced by the `write` it
	 * implied (an old admin grant keeps read + write, never admin).  Only
	 * narrows; a record without `admin` comes back unchanged.
	 *
	 * @param string[] $scopes Scopes as stored or presented.
	 * @return string[]
	 */
	public static function cap_user_bound_scopes( array $scopes ) {
		$scopes = array_values( array_unique( array_filter( $scopes, 'is_string' ) ) );
		if ( ! in_array( 'admin', $scopes, true ) ) {
			return $scopes;
		}
		$kept = array_values( array_diff( $scopes, array( 'admin' ) ) );
		foreach ( array( 'read', 'write' ) as $implied ) {
			if ( ! in_array( $implied, $kept, true ) ) {
				$kept[] = $implied;
			}
		}
		return $kept;
	}

	/**
	 * Insert a token row.
	 *
	 * @param string $token  Token string (hashed here).
	 * @param string $type   TYPE_ACCESS | TYPE_REFRESH | TYPE_TOMBSTONE.
	 * @param array  $record scopes, user_id, client_id, family_id, aud, issued_at, expires_at.
	 * @return bool True only when exactly one row was written.
	 */
	public static function insert( $token, $type, array $record ) {
		global $wpdb;
		$scopes = isset( $record['scopes'] ) ? array_values( array_filter( (array) $record['scopes'], 'is_string' ) ) : array();
		$user   = isset( $record['user_id'] ) ? max( 0, (int) $record['user_id'] ) : 0;
		if ( $user > 0 ) {
			// D8, at the one place every token and refresh row is written —
			// fresh issuance, rotation AND the carry-over of a record the
			// pre-3.14.11 build issued (which let an administrator consent to
			// `admin`): a token that runs as a user never persists `admin`.
			$scopes = self::cap_user_bound_scopes( $scopes );
		}
		$row    = array(
			'token_hash'   => self::hash( $token ),
			'type'         => (string) $type,
			'client_id'    => isset( $record['client_id'] ) ? (string) $record['client_id'] : '',
			'user_id'      => isset( $record['user_id'] ) ? max( 0, (int) $record['user_id'] ) : 0,
			'family_id'    => isset( $record['family_id'] ) ? (string) $record['family_id'] : '',
			'scopes'       => implode( ' ', $scopes ),
			'aud'          => isset( $record['aud'] ) ? (string) $record['aud'] : '',
			'issued_at'    => isset( $record['issued_at'] ) ? Mcpwp_OAuth_Revocation::to_micros( $record['issued_at'] ) : Mcpwp_OAuth_Revocation::now(),
			'expires_at'   => isset( $record['expires_at'] ) ? (int) $record['expires_at'] : 0,
			'last_used_at' => 0,
			'revoked_at'   => null,
		);
		$suppressed = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$written    = $wpdb->insert( self::table(), $row, array( '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d' ) );
		if ( null !== $suppressed ) {
			$wpdb->suppress_errors( $suppressed );
		}
		return 1 === (int) $written;
	}

	/**
	 * Atomically claim a refresh token: the row turns into its own reuse
	 * tombstone (type reuse_tombstone, revoked_at set) in one conditional
	 * UPDATE.  Exactly one of any number of concurrent claims affects the
	 * row; every other gets 0 and must refuse.  Because the tombstone IS the
	 * claimed row, there is no instant at which the token has neither a
	 * live row nor a tombstone (a revocation racing a refresh always finds
	 * one).
	 *
	 * @param string $token Refresh token.
	 * @return bool True for the one caller whose UPDATE affected the row.
	 */
	public static function claim_refresh( $token, $family_id = '', $client_id = '' ) {
		global $wpdb;
		// #1101 PR-3b round 2: the tombstone names the family and the client
		// the new tokens were just minted under — not whatever the claimed
		// row carried.  A migrated pre-#1101 row carries no client; before
		// this, its tombstone kept '' for it, so a replay could not reach the
		// family and an RFC 7009 revoke could not prove ownership.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET type = %s, revoked_at = %d, family_id = %s, client_id = %s WHERE token_hash = %s AND type = %s AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is $wpdb->prefix plus a constant.
				self::TYPE_TOMBSTONE,
				Mcpwp_OAuth_Revocation::now(),
				(string) $family_id,
				(string) $client_id,
				self::hash( $token ),
				self::TYPE_REFRESH
			)
		);
		return 1 === (int) $affected;
	}

	/**
	 * Mark one token revoked (any type).
	 *
	 * @param string $token Token string.
	 * @return bool False only when the UPDATE failed: a row already revoked
	 *              (0 rows) is success, so a retried revoke answers the same.
	 */
	public static function revoke( $token ) {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %d WHERE token_hash = %s AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				Mcpwp_OAuth_Revocation::now(),
				self::hash( $token )
			)
		);
		return false !== $affected;
	}

	/**
	 * Mark every live row of a refresh family revoked.
	 *
	 * @param string $family_id Family id.
	 * @return bool False when the UPDATE failed (0 rows is success).
	 */
	public static function revoke_family( $family_id ) {
		global $wpdb;
		if ( '' === (string) $family_id ) {
			return false;
		}
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %d WHERE family_id = %s AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				Mcpwp_OAuth_Revocation::now(),
				(string) $family_id
			)
		);
		return false !== $affected;
	}

	/**
	 * Mark every live row of a client revoked (#1101 PR-3b: the client is
	 * deleted from the admin list).  The client's epoch tombstone stays the
	 * primary guard; this keeps the rows honest for the lists.
	 *
	 * @param string $client_id Client id.
	 * @return bool False when the UPDATE failed (0 rows is success).
	 */
	public static function revoke_client_rows( $client_id ) {
		global $wpdb;
		$affected = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %d WHERE client_id = %s AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				Mcpwp_OAuth_Revocation::now(),
				(string) $client_id
			)
		);
		return false !== $affected;
	}

	/**
	 * Mark live rows of one user revoked: every client (null), one client,
	 * or only the client-less rows a pre-#1101 token migrated into ('').
	 *
	 * @param int         $user_id   User id.
	 * @param string|null $client_id Client id, '' for unattributed rows, null for all.
	 * @return bool False when the UPDATE failed (0 rows is success).
	 */
	public static function revoke_user_rows( $user_id, $client_id = null ) {
		global $wpdb;
		if ( null === $client_id ) {
			$sql = $wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %d WHERE user_id = %d AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				Mcpwp_OAuth_Revocation::now(),
				(int) $user_id
			);
		} else {
			$sql = $wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %d WHERE user_id = %d AND client_id = %s AND revoked_at IS NULL', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				Mcpwp_OAuth_Revocation::now(),
				(int) $user_id,
				(string) $client_id
			);
		}
		return false !== $wpdb->query( $sql );
	}

	/**
	 * Live access/refresh rows — not revoked, not expired, and not caught by
	 * any revocation epoch — optionally for one user.
	 *
	 * @param int $user_id User id; 0 for every user.
	 * @return array[] Rows.
	 */
	public static function live_rows( $user_id = 0 ) {
		global $wpdb;
		$columns = 'client_id, user_id, family_id, scopes, aud, issued_at, expires_at, last_used_at';
		if ( (int) $user_id > 0 ) {
			$sql = $wpdb->prepare(
				'SELECT ' . $columns . ' FROM ' . self::table() . " WHERE user_id = %d AND revoked_at IS NULL AND expires_at > %d AND type IN ('access','refresh')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				(int) $user_id,
				time()
			);
		} else {
			$sql = $wpdb->prepare(
				'SELECT ' . $columns . ' FROM ' . self::table() . " WHERE revoked_at IS NULL AND expires_at > %d AND type IN ('access','refresh')", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				time()
			);
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		$live = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! Mcpwp_OAuth_Revocation::is_revoked( self::to_record( $row ) ) ) {
				$live[] = $row;
			}
		}
		return $live;
	}

	/**
	 * The apps holding live tokens for a user, one entry per client:
	 * client_id, client_name, scopes (union), last_used_at (latest),
	 * issued_at (earliest live token).
	 *
	 * @param int $user_id User id.
	 * @return array<string,array>
	 */
	public static function live_apps_for_user( $user_id ) {
		$apps = array();
		foreach ( self::live_rows( (int) $user_id ) as $row ) {
			// #1101 PR-3b round 2: a token from before the update names no
			// app; it is listed as one row of its own (key LEGACY_APP) so the
			// user can still revoke it.
			$client_id = '' === (string) $row['client_id'] ? self::LEGACY_APP : (string) $row['client_id'];
			if ( ! isset( $apps[ $client_id ] ) ) {
				$record             = ( self::LEGACY_APP !== $client_id && class_exists( 'Mcpwp_OAuth_Clients' ) ) ? Mcpwp_OAuth_Clients::find( $client_id ) : null;
				$apps[ $client_id ] = array(
					'client_id'    => $client_id,
					'legacy'       => self::LEGACY_APP === $client_id,
					'client_name'  => self::LEGACY_APP === $client_id
						? __( 'App connected before this update', 'mcpwp' )
						: ( is_array( $record ) && '' !== (string) $record['client_name'] ? (string) $record['client_name'] : $client_id ),
					'scopes'       => array(),
					'last_used_at' => 0,
					'issued_at'    => PHP_INT_MAX,
				);
			}
			$apps[ $client_id ]['scopes']       = array_values( array_unique( array_merge( $apps[ $client_id ]['scopes'], array_filter( explode( ' ', (string) $row['scopes'] ) ) ) ) );
			$apps[ $client_id ]['last_used_at'] = max( $apps[ $client_id ]['last_used_at'], (int) $row['last_used_at'] );
			$apps[ $client_id ]['issued_at']    = min( $apps[ $client_id ]['issued_at'], (int) $row['issued_at'] );
		}
		return $apps;
	}

	/**
	 * Live token count per client.
	 *
	 * @return array<string,int>
	 */
	public static function active_counts_by_client() {
		$counts = array();
		foreach ( self::live_rows() as $row ) {
			$client_id            = (string) $row['client_id'];
			$counts[ $client_id ] = ( $counts[ $client_id ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/**
	 * Record a use, at most once per TOUCH_INTERVAL (best effort).
	 *
	 * @param array $row Row from find().
	 */
	public static function touch( array $row ) {
		global $wpdb;
		$now = time();
		if ( (int) $row['last_used_at'] > $now - self::TOUCH_INTERVAL ) {
			return;
		}
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET last_used_at = %d WHERE token_hash = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				$now,
				(string) $row['token_hash']
			)
		);
	}

	/**
	 * Delete expired rows (the daily cron).
	 *
	 * @return int|false Rows deleted, false on failure.
	 */
	public static function cleanup() {
		global $wpdb;
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . ' WHERE expires_at < %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				time()
			)
		);
		return false === $deleted ? false : (int) $deleted;
	}

	/**
	 * Schedule the daily cleanup (idempotent).
	 */
	public static function schedule_cleanup() {
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( self::CLEANUP_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Reads
	// -------------------------------------------------------------------------

	/**
	 * The row for a token, whatever its state.
	 *
	 * @param string $token Token string.
	 * @return array|null
	 */
	public static function find( $token ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- see claim_refresh().
				self::hash( $token )
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether a row is usable as a token of $type right now.
	 *
	 * @param array|null $row  Row.
	 * @param string     $type Expected type.
	 * @return bool
	 */
	public static function is_active( $row, $type ) {
		return is_array( $row )
			&& $type === $row['type']
			&& ( null === $row['revoked_at'] || '' === $row['revoked_at'] )
			&& (int) $row['expires_at'] > time();
	}

	/**
	 * A row as the token record the auth code expects (scopes array, aud,
	 * user_id, client_id, family_id, issued_at, expires_at).
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function to_record( array $row ) {
		$record = array(
			'scopes'     => '' === (string) $row['scopes'] ? array() : explode( ' ', (string) $row['scopes'] ),
			'aud'        => (string) $row['aud'],
			'issued_at'  => (int) $row['issued_at'],
			'expires_at' => (int) $row['expires_at'],
			'family_id'  => (string) $row['family_id'],
			'client_id'  => (string) $row['client_id'],
		);
		if ( (int) $row['user_id'] > 0 ) {
			$record['user_id'] = (int) $row['user_id'];
		}
		return $record;
	}

	/**
	 * Grace-window migration: move a valid pre-table transient token into
	 * the table on use.  The transient is deleted only after the row is
	 * written; if the write fails the token is refused (fail closed).
	 *
	 * @param string $token         Token string.
	 * @param string $type          TYPE_ACCESS | TYPE_REFRESH.
	 * @param string $transient_key Old transient key.
	 * @param int    $default_ttl   Lifetime to assume when the record has no expires_at.
	 * @return array|null The migrated record, or null.
	 */
	public static function migrate_from_transient( $token, $type, $transient_key, $default_ttl ) {
		if ( ! self::grace_open() ) {
			return null;
		}
		$record = get_transient( $transient_key );
		if ( ! is_array( $record ) || empty( $record['scopes'] ) ) {
			return null;
		}
		if ( empty( $record['expires_at'] ) ) {
			$record['expires_at'] = ( isset( $record['created_at'] ) ? (int) $record['created_at'] : time() ) + (int) $default_ttl;
		}
		if ( (int) $record['expires_at'] <= time() ) {
			return null;
		}
		if ( ! isset( $record['issued_at'] ) ) {
			// Seconds; the revocation clock scales it.
			$record['issued_at'] = isset( $record['created_at'] ) ? (int) $record['created_at'] : 0;
		}
		// A record from before PR-2 carries no audience; it was accepted as
		// such and is re-bound to this site's MCP endpoint on migration (a
		// row's empty aud is never "no audience" — it fails closed).
		if ( ! array_key_exists( 'aud', $record ) && class_exists( 'Mcpwp_OAuth_Well_Known' ) ) {
			$record['aud'] = Mcpwp_OAuth_Well_Known::get_canonical_resource();
		}
		// #1101 PR-3b round 2: a pre-#1101 refresh token belongs to no
		// family.  It gets one derived from the token itself, so every
		// concurrent migration of the same token agrees on it, and its
		// tombstone, its replay and its revocation all reach the same chain.
		if ( self::TYPE_REFRESH === $type && empty( $record['family_id'] ) ) {
			$record['family_id'] = self::legacy_family_id( $token );
		}
		if ( ! self::insert( $token, $type, $record ) ) {
			// A concurrent migration of the same token may have won the
			// UNIQUE key: use its row (still subject to every check), never a
			// false 401.  Anything else is a failed write: refused.
			$row = self::find( $token );
			return is_array( $row ) && self::is_active( $row, $type ) ? self::to_record( $row ) : null;
		}
		delete_transient( $transient_key );
		return self::to_record( (array) self::find( $token ) );
	}

	/**
	 * The family a pre-#1101 refresh token is given on migration.
	 *
	 * @param string $token Refresh token.
	 * @return string
	 */
	public static function legacy_family_id( $token ) {
		return hash( 'sha256', 'legacy|' . (string) $token );
	}
}
