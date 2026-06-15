<?php
/**
 * Non-destructive migration from AUTH_SALT-derived encryption to a dedicated key.
 *
 * Runs once on plugins_loaded (after update or re-activation), gated by the
 * absence of the mcpwp_encryption_migrated option.
 *
 * CRITICAL SAFETY RULE:
 *   - If decrypt with the old key fails for any reason (AUTH_SALT already
 *     rotated, blob corrupt, sodium unavailable), the blob is PRESERVED as-is
 *     and its provider slug is recorded in mcpwp_encryption_migration_failures.
 *   - A WP admin notice is surfaced so the site admin knows which providers
 *     need their keys re-entered.
 *   - mcpwp_encryption_migrated is only set AFTER every blob has been processed
 *     (success or failure recorded) and the new blobs have been written back.
 *   - If the process is interrupted mid-run (e.g. a fatal on write-back),
 *     the option is not set and the migration re-runs on next boot (idempotent).
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
		// Always wire the persistent failure notice, independent of migrated flag.
		// Failures stored in FAILURES_OPTION must surface on every admin page load
		// until the admin re-enters the affected provider keys and the option is cleared.
		add_action( 'admin_notices', array( __CLASS__, 'render_failure_notice' ) );

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

		self::run();
	}

	/**
	 * Perform the migration.
	 *
	 * For each provider blob in mcpwp_integrations:
	 *   - Try to decrypt with the OLD AUTH_SALT-derived key.
	 *   - On success: re-encrypt with the new dedicated key and write back.
	 *   - On failure: leave the blob untouched, record the provider slug.
	 *
	 * Atomic write: we collect ALL new blobs first, then do a single
	 * update_option call. If that write fails (returns false), we do NOT set
	 * the migrated flag so the next boot retries.
	 */
	public static function run() {
		$encryption = Mcpwp_Encryption::get_instance();
		$old_key    = $encryption->get_legacy_auth_salt_key();

		$data = get_option( Mcpwp_Integration_Manager::OPTION_NAME, array() );
		if ( ! is_array( $data ) || empty( $data ) ) {
			// Nothing to migrate.
			update_option( self::MIGRATED_FLAG, '1' );
			return;
		}

		$new_data        = array();
		$failures        = array();
		$any_re_encrypted = false; // True when at least one blob was re-encrypted.

		foreach ( $data as $provider => $record ) {
			$new_data[ $provider ] = $record; // default: keep as-is

			if ( empty( $record['key_encrypted'] ) ) {
				continue; // Nothing encrypted for this provider — skip.
			}

			// Attempt decryption with the OLD key.
			$plaintext = $encryption->decrypt_with_key( $record['key_encrypted'], $old_key );

			if ( false === $plaintext ) {
				// Could not decrypt: blob may already use the new key (re-run after
				// a partial migration), may be corrupt, or salt already rotated.
				// Preserve the blob exactly; the admin will need to re-enter the key.
				$failures[] = sanitize_key( (string) $provider );
				continue;
			}

			// Re-encrypt with the new dedicated key (get_key() path).
			$new_encrypted = $encryption->encrypt( $plaintext );

			// Wipe plaintext from memory immediately.
			if ( function_exists( 'sodium_memzero' ) ) {
				try {
					sodium_memzero( $plaintext );
				} catch ( \Exception $e ) {
					// sodium_memzero may throw on non-mutable strings in some builds.
					// Not a security failure — the value is local and goes out of scope.
				}
			}

			if ( false === $new_encrypted ) {
				// Re-encryption failed — preserve original blob.
				$failures[] = sanitize_key( (string) $provider );
				continue;
			}

			$new_data[ $provider ]['key_encrypted'] = $new_encrypted;
			$any_re_encrypted = true;
		}

		// Atomic write — only needed when at least one blob was re-encrypted.
		if ( $any_re_encrypted ) {
			$written = update_option( Mcpwp_Integration_Manager::OPTION_NAME, $new_data );

			if ( false === $written ) {
				// update_option can return false for two distinct reasons:
				//   (a) DB write error — the new blobs were NOT persisted.
				//   (b) The value was identical to what was already stored (no-op).
				// Case (b) cannot occur here because $any_re_encrypted is true, meaning
				// $new_data !== $data. Therefore false here is always a DB error.
				// Do NOT set MIGRATED_FLAG — retry on next boot so data is never lost.
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'MCPWP encryption migration: integration option write failed — will retry on next boot.' );
				}
				return;
			}
		}

		// Record any decrypt/re-encrypt failures persistently so admin notice
		// fires on every subsequent admin page load, not just this request.
		if ( ! empty( $failures ) ) {
			update_option( self::FAILURES_OPTION, $failures );
		}

		// Mark done only after the write succeeded (or when nothing needed writing).
		update_option( self::MIGRATED_FLAG, '1' );
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
			$list,
			esc_html__( 'Please re-enter the API keys for these providers in MCPWP > Integrations. Your existing configuration is preserved and unchanged.', 'mcpwp' )
		);
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
