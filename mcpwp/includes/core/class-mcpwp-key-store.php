<?php
/**
 * The one write path to the scoped API key store (#1096).
 *
 * `mcpwp_api_keys` is a single option holding every key record, revoked ones
 * included, so any writer that rewrites it from an earlier read can undo a
 * revoke (the key works again), drop a new key, or drop a migration. Every
 * writer therefore goes through mutate():
 *
 * 1. take the store lock (Mcpwp_OAuth_Clients::acquire_lock(), the insert-claim
 *    lock the OAuth stores use) so writers queue instead of colliding;
 * 2. READ THE ROW FROM THE DATABASE, never from the options cache — the store
 *    is autoloaded, so its live copy sits in core's `alloptions` entry;
 * 3. hand that to the caller's mutator;
 * 4. write it back with a compare-and-swap on the exact bytes read
 *    (Mcpwp_Encryption::compare_and_swap_option_row(): UPDATE ... WHERE
 *    HEX(option_value) = ...), or a plain INSERT when the row did not exist.
 *
 * The lock only keeps writers from colliding; the compare-and-swap is what
 * makes a lost write impossible. A holder that stalls past the lock's TTL, or
 * a node whose clock runs ahead, can have its lock stolen — its late write
 * then finds the row changed and writes nothing.
 *
 * Fails closed: if the lock cannot be taken, or the swap keeps missing, the
 * mutator's change is not made and the caller is told so. Three more refusals,
 * each leaving the stored bytes exactly as they were:
 * - the stored value does not unserialize to an array (a corrupt store): no
 *   writer may build on it, and Mcpwp_Api_Auth refuses to authenticate through
 *   its legacy fallbacks while it is in that state;
 * - the new value contains characters the column cannot hold (a latin1 table
 *   and a CJK label): the database would silently truncate the row there;
 * - a write that does not read back whole is rolled back to the previous bytes.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_Key_Store {

	/** Option holding the key records. */
	const OPTION = 'mcpwp_api_keys';

	/** Lock row serialising writers. */
	const LOCK = 'mcpwp_api_keys_lock';

	/** Read-mutate-swap tries inside one lock hold. */
	const SWAP_ATTEMPTS = 3;

	/**
	 * Read-modify-write the key store without losing a concurrent write.
	 *
	 * The mutator receives (array $keys, bool $exists) read under the lock and
	 * returns array( $new_keys_or_null, $result ); null leaves the store alone.
	 * It may run more than once (after a swap miss), so it must be a pure
	 * function of what it is given.
	 *
	 * @param callable $mutator Mutator.
	 * @param bool     $patient Retry the lock for ~1.5 s (admin writes) rather than
	 *                          once (request paths: fail this request, the next
	 *                          one retries).
	 * @return array{locked:bool,written:bool,result:mixed,reason:string} reason is
	 *         '' on success, else 'busy' (no lock), 'conflict' (swap kept missing),
	 *         'unreadable' (the stored value is corrupt), 'unsupported_characters'
	 *         (the column cannot hold the new value), 'unwritable' (the write did
	 *         not read back whole and was rolled back) or 'unavailable' (no
	 *         database to arbitrate with).
	 */
	public static function mutate( callable $mutator, $patient = false ) {
		$outcome = array(
			'locked'  => false,
			'written' => false,
			'result'  => null,
			'reason'  => 'busy',
		);
		// Loaded on demand: on the activation request mcpwp_load_plugin() has not
		// run yet, so the classes this needs are not there (#1096).
		if ( ! class_exists( 'Mcpwp_OAuth_Clients' ) ) {
			require_once __DIR__ . '/class-mcpwp-oauth-clients.php';
		}
		if ( ! class_exists( 'Mcpwp_Encryption' ) ) {
			require_once __DIR__ . '/class-mcpwp-encryption.php';
		}
		if ( ! class_exists( 'Mcpwp_OAuth_Clients' ) || ! class_exists( 'Mcpwp_Encryption' ) ) {
			$outcome['reason'] = 'unavailable';
			return $outcome;
		}

		$token = false;
		for ( $try = 0; $try < ( $patient ? 10 : 1 ); $try++ ) {
			$token = Mcpwp_OAuth_Clients::acquire_lock( 0, self::LOCK );
			if ( false !== $token ) {
				break;
			}
		}
		if ( false === $token ) {
			return $outcome;
		}

		try {
			$outcome['locked'] = true;
			$outcome['reason'] = 'conflict';
			for ( $attempt = 0; $attempt < self::SWAP_ATTEMPTS; $attempt++ ) {
				$raw    = self::read_row();
				$exists = null !== $raw;
				if ( $exists && ! self::is_readable_store( $raw ) ) {
					$outcome['reason'] = 'unreadable';
					return $outcome;
				}
				$keys = $exists ? maybe_unserialize( $raw ) : array();
				$keys = is_array( $keys ) ? $keys : array();

				list( $new_keys, $result ) = $mutator( $keys, $exists );
				$outcome['result']         = $result;

				if ( null === $new_keys ) {
					$outcome['written'] = true;
					$outcome['reason']  = '';
					return $outcome;
				}

				$new_raw = maybe_serialize( $new_keys );
				if ( ! self::fits_column( $new_raw ) ) {
					$outcome['result'] = null;
					$outcome['reason'] = 'unsupported_characters';
					return $outcome;
				}

				$status = self::swap( $raw, $new_keys, $new_raw );
				if ( 'ok' === $status ) {
					$outcome['written'] = true;
					$outcome['reason']  = '';
					return $outcome;
				}
				if ( 'corrupt' === $status ) {
					// Rolled back; trying again would corrupt it the same way.
					$outcome['result'] = null;
					$outcome['reason'] = 'unwritable';
					return $outcome;
				}
			}
			// Never report the mutator's result for a change that was not made.
			$outcome['result'] = null;
		} finally {
			Mcpwp_OAuth_Clients::release_lock( $token, self::LOCK );
		}

		return $outcome;
	}

	/**
	 * The stored bytes of the store row, straight from the database.
	 *
	 * @return string|null Null when the row does not exist.
	 */
	private static function read_row() {
		global $wpdb;
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION )
		);
		return is_string( $value ) ? $value : null;
	}

	/**
	 * Whether the stored value is something the store can be built on.
	 *
	 * @param string $raw Stored bytes.
	 * @return bool A serialised array.
	 */
	private static function is_readable_store( $raw ) {
		// Only a serialised list is readable ('a:0:{}' is the empty one); '' is not.
		return is_array( maybe_unserialize( $raw ) );
	}

	/**
	 * Whether the option_value column can hold this value as it is.
	 *
	 * A latin1 (or ascii, or 3-byte utf8) wp_options table silently TRUNCATES a
	 * value at the first character it cannot encode, and MariaDB/MySQL in
	 * non-strict mode accept the write. For this option that destroys every key
	 * record after that point. Refusing before the write is the only safe answer.
	 *
	 * @param string $value Serialised store.
	 * @return bool
	 */
	private static function fits_column( $value ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return true;
		}
		$charset = method_exists( $wpdb, 'get_col_charset' ) ? $wpdb->get_col_charset( $wpdb->options, 'option_value' ) : false;
		if ( is_string( $charset ) && '' !== $charset ) {
			switch ( strtolower( $charset ) ) {
				case 'utf8mb4':
					break;
				case 'utf8':
				case 'utf8mb3':
					if ( 1 === preg_match( '/[\x{10000}-\x{10FFFF}]/u', $value ) ) {
						return false;
					}
					break;
				case 'ascii':
					if ( 1 === preg_match( '/[^\x00-\x7F]/', $value ) ) {
						return false;
					}
					break;
				case 'latin1':
					if ( function_exists( 'iconv' ) && false === @iconv( 'UTF-8', 'CP1252', $value ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- iconv() warns on an unencodable character; false is the answer.
						return false;
					}
					break;
			}
		}
		if ( method_exists( $wpdb, 'strip_invalid_text_for_column' ) ) {
			$checked = $wpdb->strip_invalid_text_for_column( $wpdb->options, 'option_value', $value );
			if ( ! is_string( $checked ) || $checked !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Replace the row only if it still holds the bytes that were read.
	 *
	 * @param string|null $expected_raw Bytes read; null when the row was absent.
	 * @param array       $new_keys     Value to store.
	 * @param string      $new_raw      Its serialised form.
	 * @return string 'ok' (written and read back whole), 'miss' (the row changed;
	 *                nothing written) or 'corrupt' (written but did not read back
	 *                whole; the previous bytes were restored).
	 */
	private static function swap( $expected_raw, array $new_keys, $new_raw ) {
		global $wpdb;

		if ( null === $expected_raw ) {
			// A plain INSERT against the UNIQUE option_name: exactly one creator wins.
			$suppressed = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$inserted   = $wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => self::OPTION,
					'option_value' => $new_raw,
					'autoload'     => 'yes',
				),
				array( '%s', '%s', '%s' )
			);
			if ( null !== $suppressed ) {
				$wpdb->suppress_errors( $suppressed );
			}
			$won = 1 === (int) $inserted;
		} else {
			$won = Mcpwp_Encryption::compare_and_swap_option_row( self::OPTION, $expected_raw, $new_raw );
			if ( ! $won && self::read_row() === $expected_raw ) {
				// The row is unchanged, so this miss is not a race: the byte-exact
				// HEX() compare cannot match on this table. That happens on a
				// latin1 wp_options table read over a utf8mb4 connection, where an
				// 'e acute' is stored as E9 but read (and sent back) as C3A9.
				// Compare as text in the connection charset instead: weaker (the
				// column collation decides case and trailing-space equality) but
				// still a conditional write against the value that was read, and
				// the revocation lever must never depend on the table's charset.
				$won = self::swap_by_text_equality( $expected_raw, $new_raw );
			}
		}
		if ( ! $won ) {
			return 'miss';
		}

		$stored = self::read_row();
		if ( self::reads_back_as( $new_keys, $new_raw, $stored ) ) {
			self::refresh_cache( $new_raw );
			return 'ok';
		}

		// What landed is not what was written (a truncated row, say). Put the
		// bytes that were there before back, while the lock is still held.
		self::restore( $expected_raw, $stored );
		return 'corrupt';
	}

	/**
	 * Undo a write that did not read back whole.
	 *
	 * @param string|null $previous Bytes before the write; null when there was no row.
	 * @param string|null $stored   Bytes that landed instead.
	 */
	private static function restore( $previous, $stored ) {
		global $wpdb;
		if ( null === $stored ) {
			return;
		}
		if ( null === $previous ) {
			$wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => self::OPTION,
					'option_value' => $stored,
				),
				array( '%s', '%s' )
			);
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
				wp_cache_delete( 'notoptions', 'options' );
			}
			return;
		}
		$restored = Mcpwp_Encryption::compare_and_swap_option_row( self::OPTION, $stored, $previous );
		if ( ! $restored ) {
			self::swap_by_text_equality( $stored, $previous );
		}
		// Cache what the row ACTUALLY holds. If a newer legitimate write landed
		// before the restore, the restore correctly did nothing and the cache
		// must show that write, not the bytes from before it.
		$now = self::read_row();
		if ( null === $now ) {
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
		} else {
			self::refresh_cache( $now );
		}
	}

	/**
	 * Whether the stored row is the value just written.
	 *
	 * Byte-equal is the normal answer. On a table whose charset cannot hold some
	 * character in a label (a latin1 table and a CJK key label), the database
	 * stores a lossy copy and the bytes can never match; the write did land, so
	 * what must match is every record's identity and state, not its label text.
	 *
	 * @param array       $new_keys Keys written.
	 * @param string      $new_raw  Their serialised form.
	 * @param string|null $stored   What the row holds now.
	 * @return bool
	 */
	private static function reads_back_as( array $new_keys, $new_raw, $stored ) {
		if ( $stored === $new_raw ) {
			return true;
		}
		if ( null === $stored ) {
			return false;
		}
		$stored_keys = maybe_unserialize( $stored );
		if ( ! is_array( $stored_keys ) || count( $stored_keys ) !== count( $new_keys ) ) {
			return false;
		}
		$identity = static function ( $record ) {
			$record = is_array( $record ) ? $record : array();
			return array(
				(string) ( $record['id'] ?? '' ),
				(string) ( $record['hash'] ?? '' ),
				(string) ( $record['revoked_at'] ?? '' ),
			);
		};
		return array_map( $identity, array_values( $stored_keys ) ) === array_map( $identity, array_values( $new_keys ) );
	}

	/**
	 * Conditional write comparing the row as connection-charset text.
	 *
	 * @param string $expected_raw Bytes read.
	 * @param string $new_raw      Bytes to store.
	 * @return bool
	 */
	private static function swap_by_text_equality( $expected_raw, $new_raw ) {
		global $wpdb;
		$suppressed = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a conditional write must bypass the options API and its caches.
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $new_raw,
				self::OPTION,
				(string) $expected_raw
			)
		);
		if ( null !== $suppressed ) {
			$wpdb->suppress_errors( $suppressed );
		}
		return 1 === (int) $rows;
	}

	/**
	 * Put the new value in the object cache the way core's update_option() does.
	 *
	 * Not a bare delete: a request that read `alloptions` from the database
	 * BEFORE this write can wp_cache_add() that older copy AFTER a delete, and
	 * every later autoloaded write then force-reads and writes back the stale
	 * copy, so a revoked key stays live on a persistent cache until another
	 * key-store write or a flush. wp_cache_set() of the fresh copy cannot be
	 * overridden by a later wp_cache_add().
	 *
	 * @param string $new_raw The bytes now stored.
	 */
	private static function refresh_cache( $new_raw ) {
		if ( function_exists( 'wp_load_alloptions' ) && function_exists( 'wp_cache_set' ) ) {
			$all = wp_load_alloptions( true );
			if ( is_array( $all ) && array_key_exists( self::OPTION, $all ) ) {
				$all[ self::OPTION ] = $new_raw;
				wp_cache_set( 'alloptions', $all, 'options' );
			} else {
				wp_cache_set( self::OPTION, $new_raw, 'options' );
			}
			$notoptions = function_exists( 'wp_cache_get' ) ? wp_cache_get( 'notoptions', 'options' ) : false;
			if ( is_array( $notoptions ) && isset( $notoptions[ self::OPTION ] ) ) {
				unset( $notoptions[ self::OPTION ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
			return;
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
