<?php
/**
 * Non-destructive migration from AUTH_SALT-derived encryption to a dedicated key.
 *
 * Runs on plugins_loaded (after update or re-activation), gated by the
 * absence of the mcpwp_encryption_migrated option.
 *
 * CRITICAL SAFETY RULES:
 *   - If decrypt with the old key fails for any reason (AUTH_SALT already
 *     rotated, blob corrupt, sodium unavailable), the blob is PRESERVED as-is.
 *     A blob that already opens under the current key, or is versioned, is
 *     not a failure (it was migrated or written later). Anything else is
 *     recorded in mcpwp_encryption_migration_failures and surfaced in an
 *     admin notice until that provider's key is re-entered or removed.
 *   - A problem with the current key itself (unreadable, corrupt, cannot be
 *     stored) aborts the run with nothing written and no flag set, so it is
 *     retried rather than recorded as done.
 *   - Only one request migrates at a time: the run holds a lock row created
 *     with a plain INSERT (first writer wins) that carries its own expiry, so
 *     a crashed run cannot wedge the migration. A live lock held by another
 *     request, or a lock store that cannot arbitrate, means this request does
 *     not run at all — never unprotected.
 *   - mcpwp_encryption_migrated is set only after the re-encrypted blobs are
 *     written AND read back: every one must decrypt, under the key the site
 *     stores now, to the value that was re-encrypted.
 *
 * @package MCPWP
 * @since   3.2.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the one-time migration of provider blobs from AUTH_SALT key to dedicated key.
 */
class Mcpwp_Encryption_Migration {

	/**
	 * WP option that marks migration as done.
	 */
	const MIGRATED_FLAG = 'mcpwp_encryption_migrated';

	/**
	 * WP option that records provider slugs whose blobs could not be re-encrypted.
	 */
	const FAILURES_OPTION = 'mcpwp_encryption_migration_failures';

	/**
	 * Lock row: "<expiry unix time>|<random token>", autoload off.
	 */
	const LOCK_OPTION = 'mcpwp_encryption_migration_lock';

	/**
	 * Lock lifetime in seconds. A run re-encrypts a handful of blobs in
	 * milliseconds; the expiry only exists so a crashed run is taken over.
	 */
	const LOCK_TTL = 120;

	/**
	 * Transient that defers the next attempt after a run that could not finish.
	 *
	 * Without it, a site whose key is broken would take and release the lock
	 * row — two writes — on every request until someone fixed the key.
	 */
	const RETRY_TRANSIENT = 'mcpwp_encryption_migration_retry';

	/**
	 * Seconds to wait before retrying an unfinished run.
	 */
	const RETRY_DELAY = 300;

	/**
	 * Hook this migration into plugins_loaded.
	 *
	 * Called once from the plugin bootstrap. Priority 20 so that all classes
	 * (encryption, integration manager) are already loaded.
	 */
	public static function register() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_run' ), 20 );
	}

	/**
	 * Entry point: run the migration only if it has not completed yet.
	 *
	 * Idempotent — safe to call on every boot; exits immediately when already done.
	 */
	public static function maybe_run() {
		// Always wire the persistent notices, independent of the migrated flag.
		// Failures stored in FAILURES_OPTION surface on every admin page load
		// until the affected provider keys are re-entered or removed (see
		// clear_provider_failure()).
		add_action( 'admin_notices', array( __CLASS__, 'render_failure_notice' ) );
		add_action( 'admin_notices', array( 'Mcpwp_Encryption', 'render_admin_notice' ) );

		if ( get_option( self::MIGRATED_FLAG ) ) {
			return;
		}

		if ( ! Mcpwp_Encryption::is_available() ) {
			// Sodium unavailable — cannot migrate yet. Do NOT set MIGRATED_FLAG:
			// the condition is recoverable (admin installs php-sodium), and burning
			// the flag here would prevent migration from running after the extension
			// is installed. Surface the notice via admin_notices (already wired above).
			self::record_sodium_unavailable_notice();
			return;
		}

		if ( false !== get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}

		self::run();
	}

	/**
	 * Perform the migration under the lock.
	 *
	 * For each provider blob in mcpwp_integrations:
	 *   - Versioned, or already readable under the current key: leave it.
	 *   - Opens under the OLD AUTH_SALT-derived key: re-encrypt it.
	 *   - Otherwise: leave the blob untouched, record the provider slug.
	 */
	public static function run() {
		$lock = self::acquire_lock();
		if ( null === $lock ) {
			self::debug_log( 'lock not acquired (held by another request, or the lock store is unavailable) — will retry on a later boot.' );
			self::defer_retry();
			return;
		}

		try {
			self::migrate();
		} finally {
			self::release_lock( $lock );
		}
	}

	/**
	 * Remove a provider from the recorded migration failures.
	 *
	 * Called when that provider's key is re-entered or removed. An empty
	 * list is deleted so the admin notice stops.
	 *
	 * @param string $provider Provider slug.
	 */
	public static function clear_provider_failure( $provider ) {
		$failures = get_option( self::FAILURES_OPTION, false );
		if ( false === $failures ) {
			return;
		}

		if ( ! is_array( $failures ) ) {
			delete_option( self::FAILURES_OPTION );
			return;
		}

		$remaining = array_values( array_diff( $failures, array( sanitize_key( (string) $provider ) ) ) );

		if ( empty( $remaining ) ) {
			delete_option( self::FAILURES_OPTION );
		} elseif ( count( $remaining ) !== count( $failures ) ) {
			update_option( self::FAILURES_OPTION, $remaining, false );
		}
	}

	/**
	 * Render a WP admin notice listing providers that need their keys re-entered.
	 */
	public static function render_failure_notice() {
		$failures = get_option( self::FAILURES_OPTION, array() );
		if ( empty( $failures ) || ! is_array( $failures ) ) {
			return;
		}

		$list = implode( ', ', array_map( 'esc_html', $failures ) );
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <code>%s</code>. %s</p></div>',
			esc_html__( 'MCPWP encryption migration:', 'mcpwp' ),
			esc_html__( 'Could not automatically re-encrypt the API keys for:', 'mcpwp' ),
			$list, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each item escaped above.
			esc_html__( 'Please re-enter the API keys for these providers in MCPWP > Integrations. Your existing configuration is preserved and unchanged.', 'mcpwp' )
		);
	}

	/**
	 * The migration body. Caller holds the lock.
	 */
	private static function migrate() {
		// A request that held the lock before us may already have finished.
		if ( self::flag_is_stored() ) {
			return;
		}

		$encryption = Mcpwp_Encryption::get_instance();
		$old_key    = $encryption->get_legacy_auth_salt_key();

		// Read the row itself: a failed read is a reason to retry, never
		// "nothing to migrate" (get_option() would answer its default and set
		// the flag over blobs that were never looked at).
		$snapshot = self::read_integrations_row();
		if ( ! $snapshot['ok'] ) {
			self::debug_log( 'provider records unreadable — will retry.' );
			self::defer_retry();
			return;
		}

		$data = $snapshot['records'];
		if ( empty( $data ) ) {
			// Nothing to migrate.
			update_option( self::MIGRATED_FLAG, '1' );
			return;
		}

		// Decide once, without side effects, whether a current key exists. A key
		// problem stops the run here. With no key row at all (a <=3.2.3 site),
		// no blob can be under a current key, so that probe is skipped rather
		// than raising a misleading "key missing" alert for a key that never was.
		$key_state = Mcpwp_Encryption::inspect_key();
		if ( self::is_key_problem( $key_state ) ) {
			self::debug_log( 'current key unusable (' . $key_state . ') — nothing written, will retry.' );
			self::defer_retry();
			return;
		}
		$probe_current_key = ( '' === $key_state );

		$new_data     = array();
		$failures     = array();
		$re_encrypted = array(); // provider => BLAKE2b of the plaintext, for the read-back check.

		foreach ( $data as $provider => $record ) {
			$new_data[ $provider ] = $record; // default: keep as-is

			if ( ! is_array( $record ) || empty( $record['key_encrypted'] ) || ! is_string( $record['key_encrypted'] ) ) {
				continue; // Nothing encrypted for this provider — skip.
			}

			$blob = $record['key_encrypted'];

			if ( Mcpwp_Encryption::is_versioned_blob( $blob ) ) {
				continue; // Written after the AUTH_SALT era; never a candidate.
			}

			$plaintext = $encryption->decrypt_with_key( $blob, $old_key );

			if ( false === $plaintext ) {
				// Not an AUTH_SALT blob. Already under the current key (a 3.2.4 –
				// 3.11.0 write, or a re-run) is not a failure.
				if ( $probe_current_key ) {
					$current = $encryption->decrypt( $blob );
					if ( false !== $current ) {
						self::wipe( $current );
						continue;
					}

					// The key was readable a moment ago; a failure now is the key's.
					if ( self::is_key_problem( $encryption->get_last_error() ) ) {
						self::debug_log( 'current key unusable (' . $encryption->get_last_error() . ') — nothing written, will retry.' );
						self::defer_retry();
						return;
					}
				}

				// Corrupt, or AUTH_SALT already rotated. Preserve the blob exactly.
				$failures[] = sanitize_key( (string) $provider );
				continue;
			}

			$digest        = sodium_crypto_generichash( $plaintext );
			$new_encrypted = $encryption->encrypt( $plaintext );
			self::wipe( $plaintext );

			if ( false === $new_encrypted ) {
				if ( self::is_key_problem( $encryption->get_last_error() ) ) {
					self::debug_log( 'current key unusable (' . $encryption->get_last_error() . ') — nothing written, will retry.' );
					self::defer_retry();
					return;
				}
				$failures[] = sanitize_key( (string) $provider );
				continue;
			}

			$new_data[ $provider ]['key_encrypted'] = $new_encrypted;
			$re_encrypted[ $provider ]              = $digest;
		}

		// Re-read before writing. The lock keeps other migrations out, not an
		// admin saving, replacing or removing a provider while this ran, and
		// writing the snapshot back would silently revert that. Only blobs whose
		// stored value is still exactly the one re-encrypted are swapped;
		// anything else is newer than this run and is left alone. A failed
		// re-read is not "every provider was removed".
		$latest_row = self::read_integrations_row();
		if ( ! $latest_row['ok'] ) {
			self::debug_log( 'provider records unreadable before write — will retry.' );
			self::defer_retry();
			return;
		}
		$latest = $latest_row['records'];

		$to_verify = array();
		foreach ( $re_encrypted as $provider => $digest ) {
			if ( isset( $latest[ $provider ] ) && is_array( $latest[ $provider ] )
				&& isset( $latest[ $provider ]['key_encrypted'] )
				&& $latest[ $provider ]['key_encrypted'] === $data[ $provider ]['key_encrypted'] ) {
				$current = $latest[ $provider ];
				$next    = $current;
				$next['key_encrypted'] = $new_data[ $provider ]['key_encrypted'];
				$floor = $latest[ Mcpwp_Integration_Manager::GENERATIONS_KEY ][ $provider ] ?? 0;
				$latest[ $provider ] = Mcpwp_Integration_Manager::advance_record_version( $current, $next, $floor );
				$latest[ Mcpwp_Integration_Manager::GENERATIONS_KEY ][ $provider ] = $latest[ $provider ]['generation'];
				$to_verify[ $provider ]               = $digest;
			}
		}

		if ( ! empty( $to_verify ) ) {
			// Compare-and-swap on the exact row that was re-read: a change landing
			// between that read and this write makes it miss instead of being
			// overwritten. A miss or a failed write retries later.
			if ( ! self::compare_and_swap_integrations( $latest_row['raw'], $latest ) ) {
				self::debug_log( 'provider records changed since the re-read, or the write failed — will retry.' );
				self::defer_retry();
				return;
			}

			if ( ! self::verify_written( $to_verify ) ) {
				self::debug_log( 'integration option read-back did not verify — flag not set, will retry.' );
				self::defer_retry();
				return;
			}
		}

		// A provider removed meanwhile has nothing left to re-enter.
		$failures = array_values( array_intersect( $failures, array_map( 'sanitize_key', array_map( 'strval', array_keys( $latest ) ) ) ) );

		if ( empty( $failures ) ) {
			delete_option( self::FAILURES_OPTION );
		} else {
			// Persist so the admin notice fires on every admin page load, not just this request.
			update_option( self::FAILURES_OPTION, array_values( array_unique( $failures ) ), false );
		}

		// Mark done only after the write verified (or when nothing needed writing).
		update_option( self::MIGRATED_FLAG, '1' );
	}

	/**
	 * Read back the re-encrypted blobs and check each against the stored key.
	 *
	 * @param array<string, string> $digests provider => BLAKE2b of the plaintext.
	 * @return bool
	 */
	private static function verify_written( array $digests ) {
		$stored_row = self::read_integrations_row();
		if ( ! $stored_row['ok'] ) {
			return false;
		}
		$stored = $stored_row['records'];

		$encryption = Mcpwp_Encryption::get_instance();

		foreach ( $digests as $provider => $digest ) {
			if ( empty( $stored[ $provider ]['key_encrypted'] ) ) {
				return false;
			}

			$plaintext = $encryption->decrypt_uncached( $stored[ $provider ]['key_encrypted'] );
			if ( false === $plaintext ) {
				return false;
			}

			$matches = hash_equals( $digest, sodium_crypto_generichash( $plaintext ) );
			self::wipe( $plaintext );

			if ( ! $matches ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Defer the next attempt by RETRY_DELAY. Best effort.
	 */
	private static function defer_retry() {
		set_transient( self::RETRY_TRANSIENT, time(), self::RETRY_DELAY );
	}

	/**
	 * Read the provider records straight from their row, past every cache.
	 *
	 * @return array{ok: bool, raw: string|null, records: array}
	 *   ok false when the read failed — never reported as an empty store.
	 *   raw is the stored value (null when no row exists), for compare-and-swap.
	 *   A row that does not unserialize to an array holds no provider records.
	 */
	private static function read_integrations_row() {
		$row = Mcpwp_Encryption::read_option_row( Mcpwp_Integration_Manager::OPTION_NAME );

		if ( Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
			return array(
				'ok'      => false,
				'raw'     => null,
				'records' => array(),
			);
		}

		if ( Mcpwp_Encryption::ROW_ABSENT === $row['state'] ) {
			return array(
				'ok'      => true,
				'raw'     => null,
				'records' => array(),
			);
		}

		$records = maybe_unserialize( $row['value'] );

		return array(
			'ok'      => true,
			'raw'     => $row['value'],
			'records' => is_array( $records ) ? $records : array(),
		);
	}

	/**
	 * Replace the provider records only if the row still holds $expected_raw.
	 *
	 * @param string|null $expected_raw Stored value the new records were built from.
	 * @param array       $records      New provider records.
	 * @return bool True when exactly this row was updated.
	 */
	private static function compare_and_swap_integrations( $expected_raw, array $records ) {
		// Byte-exact: a plain option_value = %s compares with the column's
		// case-insensitive collation (#845 review).
		$swapped = Mcpwp_Encryption::compare_and_swap_option_row(
			Mcpwp_Integration_Manager::OPTION_NAME,
			$expected_raw,
			maybe_serialize( $records )
		);

		// Written behind the options API: drop the cached copies (the option is
		// autoloaded, so the alloptions copy too).
		Mcpwp_Encryption::clean_option_cache( Mcpwp_Integration_Manager::OPTION_NAME );
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}

		return $swapped;
	}

	/**
	 * Whether the failure is the key's, not the blob's.
	 *
	 * @param string $reason Mcpwp_Encryption reason.
	 * @return bool
	 */
	private static function is_key_problem( $reason ) {
		return in_array(
			$reason,
			array(
				Mcpwp_Encryption::REASON_KEY_UNAVAILABLE,
				Mcpwp_Encryption::REASON_KEY_CORRUPT,
				Mcpwp_Encryption::REASON_KEY_WRITE_FAILED,
				Mcpwp_Encryption::REASON_SODIUM_UNAVAILABLE,
				Mcpwp_Encryption::REASON_PINNED_KEY_WEAK,
			),
			true
		);
	}

	/**
	 * Whether the migrated flag is stored, checked past the option cache too.
	 *
	 * @return bool
	 */
	private static function flag_is_stored() {
		if ( get_option( self::MIGRATED_FLAG ) ) {
			return true;
		}

		$row = Mcpwp_Encryption::read_option_row( self::MIGRATED_FLAG );

		return Mcpwp_Encryption::ROW_PRESENT === $row['state'] && '' !== $row['value'] && '0' !== $row['value'];
	}

	/**
	 * Take the migration lock.
	 *
	 * @return string|null This run's lock value, or null when it must not run.
	 */
	private static function acquire_lock() {
		global $wpdb;

		$now = time();

		try {
			$token = ( $now + self::LOCK_TTL ) . '|' . bin2hex( random_bytes( 8 ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( Mcpwp_Encryption::insert_option_row_if_absent( self::LOCK_OPTION, $token ) ) {
			return $token;
		}

		$row = Mcpwp_Encryption::read_option_row( self::LOCK_OPTION );
		if ( Mcpwp_Encryption::ROW_PRESENT !== $row['state'] ) {
			// Unreadable, or the insert failed with no row to show for it: the
			// store cannot arbitrate, so do not run unprotected.
			return null;
		}

		$parts   = explode( '|', (string) $row['value'], 2 );
		$expires = (int) $parts[0];

		// A live lock is honoured. An expiry further out than one lifetime was
		// not written by this code and must not wedge the migration.
		if ( $expires > $now && $expires <= $now + self::LOCK_TTL ) {
			return null;
		}

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
			return null;
		}

		// Take over the expired lock, compare-and-swap on the value we saw, so
		// two requests racing for the same stale lock cannot both win.
		$rows = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => $token ),
			array(
				'option_name'  => self::LOCK_OPTION,
				'option_value' => (string) $row['value'],
			),
			array( '%s' ),
			array( '%s', '%s' )
		);

		Mcpwp_Encryption::clean_option_cache( self::LOCK_OPTION );

		return 1 === (int) $rows ? $token : null;
	}

	/**
	 * Release the lock if it is still ours.
	 *
	 * @param string $token This run's lock value.
	 */
	private static function release_lock( $token ) {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
			$wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => self::LOCK_OPTION,
					'option_value' => $token,
				),
				array( '%s', '%s' )
			);
		}

		Mcpwp_Encryption::clean_option_cache( self::LOCK_OPTION );
	}

	/**
	 * Overwrite a plaintext in memory where the build allows it.
	 *
	 * @param string $value Value to wipe.
	 */
	private static function wipe( &$value ) {
		if ( function_exists( 'sodium_memzero' ) && is_string( $value ) ) {
			try {
				sodium_memzero( $value );
			} catch ( \Throwable $e ) {
				// Some builds refuse non-mutable strings. Not a security failure:
				// the value is local and goes out of scope.
				$value = '';
			}
		}
	}

	/**
	 * Debug log line (never includes key material).
	 *
	 * @param string $message Message.
	 */
	private static function debug_log( $message ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP encryption migration: ' . $message );
		}
	}

	/**
	 * Record a notice when sodium is unavailable on activation.
	 *
	 * Without sodium, no blobs can be encrypted or decrypted. The admin notice
	 * points to the requirement so they can install the extension.
	 */
	private static function record_sodium_unavailable_notice() {
		add_action( 'admin_notices', function () {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'MCPWP:', 'mcpwp' ),
				esc_html__( 'The PHP sodium extension is required for provider key encryption but is not available. Please install php-sodium and re-activate the plugin.', 'mcpwp' )
			);
		} );
	}
}
