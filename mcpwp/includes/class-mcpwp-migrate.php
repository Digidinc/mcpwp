<?php
/**
 * Data migration: spai_* options → mcpwp_* options + spai_* tables → mcpwp_* tables
 *
 * Copies every known legacy option written by site-pilot-ai 2.8.x to its
 * mcpwp_* equivalent, and copies rows from the spai_* database tables into
 * the freshly-created mcpwp_* tables.  The routine is:
 *
 *   - Idempotent: guarded by the `mcpwp_migrated_from_spai` flag option.
 *   - Non-destructive: leaves all spai_* originals intact.
 *   - Non-overwriting: skips any mcpwp_* option that already has a value.
 *   - Safe on fresh installs: no spai_* options present → nothing happens.
 *
 * ENCRYPTION NOTICE
 * -----------------
 * Both Spai_Encryption (2.8.x) and Mcpwp_Encryption (v3) use
 * sodium_crypto_secretbox keyed by sodium_crypto_generichash( AUTH_SALT ).
 * Because AUTH_SALT is site-specific and constant across the rename (same
 * WordPress install), the encrypted blobs in spai_integrations are directly
 * portable: copy the raw option value and Mcpwp_Encryption will decrypt it
 * identically.  No re-encryption step is required.
 *
 * FLAGGED RISK: if AUTH_SALT ever changed between the last spai_ write and
 * this migration (e.g., wp-config.php was regenerated), decryption will
 * return false for the copied integrations blob.  Mcpwp_Integration_Manager
 * handles that gracefully (returns empty/null providers), so the site will
 * not crash — but provider keys will be lost and must be re-entered.
 * The migration log records this scenario so the admin knows to re-enter keys.
 *
 * PER-USER OPTIONS (spai_chat_history_*)
 * ---------------------------------------
 * Chat history is stored per user-ID as spai_chat_history_{user_id}.
 * These are admin-UI conveniences, not MCP data.  They are NOT migrated
 * because (a) the new admin chat UI may differ, (b) iterating over all user
 * IDs at activation time is expensive, and (c) the data is ephemeral.
 *
 * ENTITLEMENT OPTIONS
 * -------------------
 * site-pilot-ai 2.8.56 has TWO local entitlement options that grant Pro
 * access independently of Freemius:
 *   - spai_pro_license  (Spai_License::OPTION_KEY)  — stored license blob.
 *   - spai_trial_started (Spai_License::TRIAL_KEY)  — Unix trial timestamp.
 * Both are copied to their mcpwp_ equivalents for completeness, but
 * Mcpwp_License::is_pro() deliberately reads the ORIGINAL spai_ options for the
 * bridge entitlement decision — the spai_ namespace cannot be written through
 * any REST/MCP surface, so it is un-forgeable, whereas the mcpwp_ copies and the
 * mcpwp_migrated_from_spai flag are settable by a write-scope token (Warden
 * 3.1.0 P0). See class-mcpwp-license.php bridge_local_license_is_valid() and
 * bridge_trial_is_active() for the exact validity checks used.
 *
 * @package MCPWP
 * @since   3.0.0 (unreleased bridge)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles one-time migration of spai_* options and tables to mcpwp_* equivalents.
 */
class Mcpwp_Migrate {

	/**
	 * Flag option name.  Set to '1' after a successful migration run.
	 */
	const MIGRATED_FLAG = 'mcpwp_migrated_from_spai';

	/**
	 * Migration log option name.  Stores a structured record of what happened.
	 */
	const LOG_OPTION = 'mcpwp_migration_log';

	/**
	 * Map of legacy spai_* option key → mcpwp_* option key.
	 *
	 * Keys are the 2.8.56 option names found via grep of the source zip.
	 * The value is the corresponding v3 mcpwp_ name.
	 *
	 * Options intentionally excluded:
	 *   spai_chat_history_*  — per-user, ephemeral, not migrated (see file docblock)
	 *   spai_version         — version tracking; v3 sets its own mcpwp_version
	 *   spai_db_version      — DB schema version; v3 manages its own mcpwp_db_version
	 *   spai_update_info     — update manifest override; intentionally left empty in v3
	 *                          (a stale override blocks the update channel)
	 *   spai_version_url     — update check URL; v3 sets its own default on activation
	 *   spai_chat_endpoint   — internal admin chat config; v3 may differ
	 *   spai_chat_model      — internal admin chat config; v3 may differ
	 *   spai_chat_secret     — internal admin chat config; v3 may differ
	 *   spai_signals_meta    — v3 introduces this key; 2.8.56 does not write it
	 *
	 * @var array<string,string>
	 */
	const OPTION_MAP = array(
		// API keys — primary migration target; dual-key auth depends on these.
		// NOTE: spai_api_keys is handled specially in run() (append, not overwrite)
		// when mcpwp_api_keys already exists.  The entry here is kept so the
		// OPTION_MAP remains a complete inventory and tests can verify the key
		// is in the map.  The run() method removes it from the main-map loop and
		// handles it separately.
		'spai_api_keys'                   => 'mcpwp_api_keys',
		'spai_api_key'                    => 'mcpwp_api_key',

		// Entitlement — local license blob and trial timestamp written by
		// Spai_License in 2.8.56 (OPTION_KEY / TRIAL_KEY constants).
		// Copied so Mcpwp_License::is_pro() bridge fallback can honour them.
		// Simple non-destructive copy — same guard as all other options.
		'spai_pro_license'                => 'mcpwp_pro_license',
		'spai_trial_started'              => 'mcpwp_trial_started',

		// Plugin settings (logging, analytics toggle, rate limits, etc.).
		'spai_settings'                   => 'mcpwp_settings',
		'spai_rate_limit_settings'        => 'mcpwp_rate_limit_settings',
		'spai_disabled_tool_categories'   => 'mcpwp_disabled_tool_categories',

		// Provider integration keys (sodium-encrypted blob — see ENCRYPTION NOTICE).
		'spai_integrations'               => 'mcpwp_integrations',

		// Site content & context.
		'spai_site_context'               => 'mcpwp_site_context',
		'spai_site_context_updated'       => 'mcpwp_site_context_updated',
		'spai_site_profile'               => 'mcpwp_site_context', // spai_site_profile was a
		                                                            // separate option in some
		                                                            // builds; copy to same target
		                                                            // only if mcpwp_site_context
		                                                            // was not already set by
		                                                            // spai_site_context.  Logic in
		                                                            // run() handles priority.

		// Design references library.
		'spai_design_references'          => 'mcpwp_design_references',

		// WooCommerce product archetypes.
		'spai_wc_product_archetypes'      => 'mcpwp_wc_product_archetypes',

		// Analytics UUID — keep the same UUID so PostHog history is continuous.
		'spai_site_uuid'                  => 'mcpwp_site_uuid',

		// Timestamps.
		'spai_first_activation'           => 'mcpwp_first_activation',

		// SEO audit data.
		'spai_seo_audit_runs'             => 'mcpwp_seo_audit_runs',
		'spai_seo_issues'                 => 'mcpwp_seo_issues',

		// Action log retention.
		'spai_action_log_retention_days'  => 'mcpwp_action_log_retention_days',

		// Approval requests (class-mcpwp-approvals.php OPTION_NAME).
		'spai_approval_requests'          => 'mcpwp_approval_requests',

		// Event store (class-mcpwp-event-store.php OPTION_NAME).
		'spai_recent_events'              => 'mcpwp_recent_events',

		// AI site memory (class-mcpwp-site-memory.php OPTION_KEY).
		'spai_site_memory'                => 'mcpwp_site_memory',

		// Site blueprints (class-mcpwp-site-blueprints.php OPTION_KEY).
		'spai_site_blueprints'            => 'mcpwp_site_blueprints',

		// White-label config (class-mcpwp-white-label.php OPTION_KEY).
		'spai_white_label'                => 'mcpwp_white_label',

		// Signals cache (class-mcpwp-signals.php OPTION_KEY).
		// NOTE: spai_signals_meta does NOT exist in 2.8.56 — 2.8.56 only writes
		// spai_signals and spai_signal_settings.  mcpwp_signals_meta is a v3 addition.
		'spai_signals'                    => 'mcpwp_signals',
		'spai_signal_settings'            => 'mcpwp_signal_settings',

		// Search-console data (class-mcpwp-search-performance.php).
		'spai_search_performance_imports' => 'mcpwp_search_performance_imports',
		'spai_search_performance_rows'    => 'mcpwp_search_performance_rows',
	);

	/**
	 * Table migration map: spai_ table suffix → mcpwp_ table suffix.
	 * Each entry is migrated if the source exists + is non-empty and the
	 * target exists + is empty.
	 *
	 * @var array<string,string>
	 */
	const TABLE_MAP = array(
		'spai_webhooks'     => 'mcpwp_webhooks',
		'spai_webhook_logs' => 'mcpwp_webhook_logs',
		'spai_action_log'   => 'mcpwp_action_log',
		'spai_activity_log' => 'mcpwp_activity_log',
		'spai_feedback'     => 'mcpwp_feedback',
	);

	/**
	 * Run the migration.
	 *
	 * Intended to be called from the plugin's `plugins_loaded` action (priority 5)
	 * AND from the activation hook so that sites which activate v3 fresh on a
	 * previously-active 2.8.x install are covered immediately.
	 *
	 * Safe to call multiple times — the flag short-circuits after the first run.
	 *
	 * @return bool True if migration ran (or was already done), false on hard error.
	 */
	public static function run() {
		// Already migrated — nothing to do.
		if ( get_option( self::MIGRATED_FLAG ) ) {
			return true;
		}

		// No spai_ data present (fresh install or already-renamed install).
		// Check the most fundamental key first to avoid iterating all options.
		if ( false === get_option( 'spai_settings', false ) &&
			 false === get_option( 'spai_api_keys', false ) &&
			 false === get_option( 'spai_api_key', false ) ) {
			// Nothing to migrate — mark as done so we don't check again.
			update_option( self::MIGRATED_FLAG, '1' );
			return true;
		}

		$log = array(
			'timestamp'          => function_exists( 'current_time' ) ? current_time( 'c' ) : gmdate( 'c' ),
			'from_version'       => (string) get_option( 'spai_version', 'unknown' ),
			'copied'             => array(),
			'skipped_existing'   => array(),
			'skipped_missing'    => array(),
			'encryption_warning' => false,
			'tables'             => array(),
		);

		// -----------------------------------------------------------------------
		// OPTION MIGRATION
		// -----------------------------------------------------------------------

		// Process the main option map.
		// spai_site_profile → mcpwp_site_context is handled specially below.
		// spai_api_keys is handled specially below (append rather than simple copy).
		// spai_settings is handled specially below (deep-merge rather than skip).
		$main_map = self::OPTION_MAP;
		unset( $main_map['spai_site_profile'] );
		unset( $main_map['spai_api_keys'] );
		unset( $main_map['spai_settings'] );

		foreach ( $main_map as $legacy_key => $new_key ) {
			// #1042: once the approvals queue lives in its own table, the
			// table migration has already consumed the legacy queue. Copying
			// spai_approval_requests again would feed the late import and
			// resurrect records deleted since.
			if ( 'mcpwp_approval_requests' === $new_key && get_option( 'mcpwp_approvals_table_migrated' ) ) {
				$log['skipped_existing'][] = $new_key . ' (approvals table already migrated)';
				continue;
			}

			$legacy_value = get_option( $legacy_key, '__NOT_SET__' );

			// Legacy option does not exist on this site.
			if ( '__NOT_SET__' === $legacy_value ) {
				$log['skipped_missing'][] = $legacy_key;
				continue;
			}

			// Target already has a value — do not overwrite.
			if ( false !== get_option( $new_key, false ) ) {
				$log['skipped_existing'][] = $new_key;
				continue;
			}

			update_option( $new_key, $legacy_value );
			$log['copied'][] = $legacy_key . ' → ' . $new_key;
		}

		// Special case: spai_site_profile → mcpwp_site_context
		// Only migrate if mcpwp_site_context was not already set by the
		// spai_site_context copy above.
		$spai_site_profile = get_option( 'spai_site_profile', '__NOT_SET__' );
		if ( '__NOT_SET__' !== $spai_site_profile ) {
			if ( false === get_option( 'mcpwp_site_context', false ) ) {
				update_option( 'mcpwp_site_context', $spai_site_profile );
				$log['copied'][] = 'spai_site_profile → mcpwp_site_context (fallback)';
			} else {
				$log['skipped_existing'][] = 'spai_site_profile (mcpwp_site_context already set)';
			}
		}

		// -----------------------------------------------------------------------
		// FIX2: spai_settings → mcpwp_settings — sub-key deep-merge
		//
		// The activator always writes mcpwp_settings with its own defaults before
		// migration runs, so a simple copy/skip would silently lose all customer
		// settings including OAuth config.  Instead we load both arrays and overlay
		// every sub-key the customer set in spai_settings on top of the existing
		// mcpwp_settings — customer value wins over v3 default; v3-only keys keep
		// their defaults; the result is written back.
		//
		// Customer values that must survive (all live inside the settings array in
		// 2.8.56 — see class-spai-settings.php::get_defaults()):
		//   oauth_enabled, oauth_client_id, oauth_client_secret_hash, oauth_token_ttl
		//   alerts_enabled, alerts_window_minutes, alerts_cooldown_minutes,
		//   alerts_5xx_threshold, alerts_auth_threshold
		//   enable_logging, log_retention_days, log_store_response_data
		//   allowed_origins, analytics_enabled
		//
		// Non-destructive: spai_settings is never modified.
		// Idempotent: re-merging the same spai_settings always produces the same
		// result because the customer-value-wins rule is stable.
		// -----------------------------------------------------------------------
		$spai_settings_raw = get_option( 'spai_settings', '__NOT_SET__' );
		if ( '__NOT_SET__' !== $spai_settings_raw ) {
			if ( is_array( $spai_settings_raw ) && ! empty( $spai_settings_raw ) ) {
				$mcpwp_settings_existing = get_option( 'mcpwp_settings', false );
				if ( false === $mcpwp_settings_existing ) {
					// mcpwp_settings not yet written — simple copy.
					// A consent label is recorded only by this version's settings
					// form; one found in spai_settings is never copied (review r3
					// R3-2). The 2.8 value itself is labelled legacy below.
					$copy = $spai_settings_raw;
					unset( $copy['analytics_choice'] );
					update_option( 'mcpwp_settings', $copy );
					self::restamp_if_analytics_copied( $copy );
					$log['copied'][] = 'spai_settings → mcpwp_settings (direct copy)';
				} else {
					// mcpwp_settings already exists (written by activator) — deep-merge.
					// Customer sub-keys win over v3 defaults.
					$base    = is_array( $mcpwp_settings_existing ) ? $mcpwp_settings_existing : array();
					$merged  = $base;
					$applied = array();

					foreach ( $spai_settings_raw as $sub_key => $customer_value ) {
						if ( ! is_string( $sub_key ) || '' === $sub_key ) {
							continue;
						}
						// Analytics consent is not a customer value to carry
						// over. A label is never copied from spai_settings, and a
						// choice already recorded here (explicit or legacy)
						// outranks an unattributable 2.8 value in either
						// direction — this merge re-runs on every request while
						// a table migration keeps failing (review r3 R3-1, R3-2).
						if ( 'analytics_choice' === $sub_key ) {
							continue;
						}
						if ( 'analytics_enabled' === $sub_key && isset( $base['analytics_choice'] ) ) {
							continue;
						}
						// Only overlay scalar and simple-array values that differ from
						// the existing setting — avoids re-writing identical defaults.
						if ( ! array_key_exists( $sub_key, $base ) ||
							json_encode( $base[ $sub_key ] ) !== json_encode( $customer_value ) ) {
							$merged[ $sub_key ] = $customer_value;
							$applied[]          = $sub_key;
						}
					}

					if ( ! empty( $applied ) ) {
						update_option( 'mcpwp_settings', $merged );
						if ( in_array( 'analytics_enabled', $applied, true ) ) {
							self::restamp_if_analytics_copied( $merged );
						}
						$log['copied'][] = 'spai_settings → mcpwp_settings (deep-merge; overlaid: ' . implode( ', ', $applied ) . ')';
					} else {
						$log['skipped_existing'][] = 'spai_settings (all sub-keys already match mcpwp_settings)';
					}
				}
			} else {
				// spai_settings exists but is empty or non-array — nothing to merge.
				$log['skipped_missing'][] = 'spai_settings (empty or non-array)';
			}
		} else {
			$log['skipped_missing'][] = 'spai_settings';
		}

		// -----------------------------------------------------------------------
		// R5: spai_api_keys — append into mcpwp_api_keys (de-duplicated)
		//
		// If mcpwp_api_keys already exists (e.g. written by Mcpwp_Activator before
		// migration runs), we cannot overwrite it — that would clobber any v3 key
		// the activator just created.  Instead we append the spai_ records, skipping
		// any that are already present (matched by hash) or already revoked.
		// -----------------------------------------------------------------------
		$spai_keys_raw = get_option( 'spai_api_keys', '__NOT_SET__' );
		$keys_failed   = false;
		if ( '__NOT_SET__' !== $spai_keys_raw && is_array( $spai_keys_raw ) && ! empty( $spai_keys_raw ) ) {
			// #1096: written through Mcpwp_Key_Store, the single write path to
			// mcpwp_api_keys, so this plugin-load migration cannot overwrite a
			// key created or revoked at the same moment. The decision is made on
			// the store as read under the lock.
			if ( ! class_exists( 'Mcpwp_Key_Store' ) ) {
				require_once __DIR__ . '/core/class-mcpwp-key-store.php';
			}
			$merge = Mcpwp_Key_Store::mutate(
				static function ( array $mcpwp_keys, $exists ) use ( $spai_keys_raw ) {
					if ( ! $exists ) {
						// mcpwp_api_keys does not exist yet — simple copy.
						return array( $spai_keys_raw, array( 'copy', count( $spai_keys_raw ) ) );
					}

					// Build a set of existing hashes for O(1) dedup lookup.
					$existing_hashes = array();
					foreach ( $mcpwp_keys as $k ) {
						if ( ! empty( $k['hash'] ) ) {
							$existing_hashes[ (string) $k['hash'] ] = true;
						}
					}

					$appended = 0;
					foreach ( $spai_keys_raw as $spai_key ) {
						if ( ! is_array( $spai_key ) ) {
							continue;
						}
						// Skip revoked spai_ keys — no point importing them.
						if ( ! empty( $spai_key['revoked_at'] ) ) {
							continue;
						}
						$hash = isset( $spai_key['hash'] ) ? (string) $spai_key['hash'] : '';
						if ( '' === $hash ) {
							continue;
						}
						// Skip if already present by hash.
						if ( isset( $existing_hashes[ $hash ] ) ) {
							continue;
						}
						$mcpwp_keys[]             = $spai_key;
						$existing_hashes[ $hash ] = true;
						$appended++;
					}

					return array( $appended > 0 ? $mcpwp_keys : null, array( 'append', $appended ) );
				},
				false
			);

			if ( ! $merge['written'] ) {
				// Busy store: not marked done, retried on the next request.
				$keys_failed = true;
			} elseif ( 'copy' === $merge['result'][0] ) {
				$log['copied'][] = 'spai_api_keys → mcpwp_api_keys (direct copy)';
			} elseif ( $merge['result'][1] > 0 ) {
				$log['copied'][] = 'spai_api_keys → mcpwp_api_keys (appended ' . $merge['result'][1] . ' record(s), no clobber)';
			} else {
				$log['skipped_existing'][] = 'spai_api_keys (all records already present in mcpwp_api_keys)';
			}
		} elseif ( '__NOT_SET__' !== $spai_keys_raw ) {
			// spai_api_keys exists but is empty or not an array — nothing to append.
			$log['skipped_missing'][] = 'spai_api_keys (empty or non-array)';
		} else {
			$log['skipped_missing'][] = 'spai_api_keys';
		}

		// -----------------------------------------------------------------------
		// Encryption sanity check.
		// After copying spai_integrations → mcpwp_integrations, attempt to verify
		// the blob is still parseable.  Mcpwp_Encryption derives its key from
		// AUTH_SALT — if that constant is the same (it should be on the same WP
		// install), decryption will succeed.
		// -----------------------------------------------------------------------
		$integrations_copied = in_array( 'spai_integrations → mcpwp_integrations', $log['copied'], true );
		if ( $integrations_copied && class_exists( 'Mcpwp_Encryption' ) ) {
			$enc  = Mcpwp_Encryption::get_instance();
			$blob = get_option( 'mcpwp_integrations', false );

			if ( is_string( $blob ) && '' !== $blob ) {
				$test = $enc->decrypt( $blob );
				if ( false === $test ) {
					// Decryption failed — likely AUTH_SALT changed or blob is
					// from a different WP install.  Flag it clearly.
					$log['encryption_warning'] = true;
					$log['encryption_warning_detail'] = 'mcpwp_integrations blob copied from spai_integrations '
						. 'but Mcpwp_Encryption::decrypt() returned false.  Provider keys (OpenAI, Gemini, '
						. 'ElevenLabs, Pexels, Figma, PostHog, etc.) must be re-entered in '
						. 'WP Admin > MCPWP > Integrations.';
				}
			} elseif ( is_array( $blob ) ) {
				// spai_integrations was a plain array (unencrypted, older 2.8.x builds).
				// This is safe — no re-entry needed.
				$log['copied'][] = 'spai_integrations was unencrypted array — copied as-is';
			}
			// false === $blob means the option was not yet written (shouldn't happen
			// if integrations_copied is true, but guard defensively).
		}

		// -----------------------------------------------------------------------
		// R2: TABLE MIGRATION
		// -----------------------------------------------------------------------
		self::migrate_tables( $log );

		// -----------------------------------------------------------------------
		// Persist the log.
		// -----------------------------------------------------------------------
		update_option( self::LOG_OPTION, $log );

		// -----------------------------------------------------------------------
		// FIX3: Conditional migration flag.
		//
		// Only set mcpwp_migrated_from_spai when ALL table migrations ended with
		// status='migrated' or status='skipped'.  A status='error' means a table
		// INSERT failed and the data was NOT copied; we must leave the flag UNSET
		// so plugins_loaded retries on the next request.
		//
		// The target-empty guard (dst_rows > 0 → skipped) already prevents
		// successfully-migrated tables from being double-copied on retry.
		//
		// If any table errored:
		//   - Leave MIGRATED_FLAG unset (run() will re-enter next request).
		//   - Set mcpwp_migration_incomplete with the list of failed tables so
		//     the admin notice can surface it.
		//   - Register an admin_notices action to show a persistent WP notice.
		//
		// If all tables are clean:
		//   - Clear mcpwp_migration_incomplete (previous failed run may have set it).
		//   - Set MIGRATED_FLAG to '1' — short-circuits all future calls.
		// -----------------------------------------------------------------------
		$failed_tables = $keys_failed ? array( 'mcpwp_api_keys' ) : array();
		foreach ( $log['tables'] as $table_key => $table_entry ) {
			if ( isset( $table_entry['status'] ) && 'error' === $table_entry['status'] ) {
				$failed_tables[] = $table_key;
			}
		}

		if ( ! empty( $failed_tables ) ) {
			// Partial migration — record which tables failed and register notice.
			update_option( 'mcpwp_migration_incomplete', $failed_tables );

			// Register the admin notice if we are in an admin context.
			if ( function_exists( 'add_action' ) ) {
				add_action( 'admin_notices', array( __CLASS__, 'show_migration_incomplete_notice' ) );
			}

			// Return false to signal a partial run — caller / plugins_loaded will
			// re-invoke next request to retry only the errored tables.
			return false;
		}

		// All tables succeeded or were cleanly skipped.
		delete_option( 'mcpwp_migration_incomplete' );
		update_option( self::MIGRATED_FLAG, '1' );

		return true;
	}

	/**
	 * Admin notice displayed when one or more table migrations failed.
	 *
	 * Reads the mcpwp_migration_incomplete option (array of table suffixes)
	 * and renders a dismissable WP error notice.  Fires on admin_notices.
	 *
	 * @return void
	 */
	public static function show_migration_incomplete_notice(): void {
		$failed = get_option( 'mcpwp_migration_incomplete', array() );
		if ( empty( $failed ) || ! is_array( $failed ) ) {
			return;
		}

		$tables = implode( ', ', array_map( 'esc_html', $failed ) );
		printf(
			'<div class="notice notice-error"><p><strong>MCPWP migration incomplete:</strong> '
			. 'The following database tables could not be migrated from site-pilot-ai and will be retried on the next page load: %s. '
			. 'Check the migration log under WP Admin &gt; MCPWP &gt; Settings for details. '
			. 'If the error persists, re-activate the MCPWP plugin.</p></div>',
			esc_html( $tables )
		);
	}

	/**
	 * Migrate rows from spai_* tables into mcpwp_* tables.
	 *
	 * Strategy (non-destructive):
	 *  - Only migrate if the v3 target table exists AND is empty.
	 *  - Only migrate if the spai_ source table exists AND is non-empty.
	 *  - Compare schemas (SHOW COLUMNS).  If columns match → INSERT … SELECT *.
	 *    If columns diverge → copy only the common columns; log a warning about
	 *    any columns that could not be mapped.  Never blind-copy mismatched schemas.
	 *  - Keep the spai_ source tables intact (rollback path).
	 *  - Each table records its status in the log ('migrated', 'skipped', 'error').
	 *    On retry (MIGRATED_FLAG not set), the target-empty guard (dst_rows > 0)
	 *    protects already-migrated tables from double-copy; only genuinely empty
	 *    targets are attempted again.  There are no separate per-table done flags —
	 *    idempotency relies entirely on the dst_rows check.
	 *  - Each table is wrapped in its own try/catch so one bad table does not
	 *    abort the rest.
	 *
	 * @param array &$log Migration log array (passed by reference).
	 */
	private static function migrate_tables( array &$log ) {
		// Bail early if wpdb is not available (unit-test context).
		if ( ! isset( $GLOBALS['wpdb'] ) ) {
			return;
		}

		global $wpdb;

		foreach ( self::TABLE_MAP as $spai_suffix => $mcpwp_suffix ) {
			// Derive full table names using the current site's prefix.
			// In multisite, $wpdb->prefix is per-site — these are per-site tables.
			$src = $wpdb->prefix . $spai_suffix;
			$dst = $wpdb->prefix . $mcpwp_suffix;

			$table_log = array(
				'src'    => $src,
				'dst'    => $dst,
				'status' => 'skipped',
				'rows'   => 0,
				'note'   => '',
			);

			try {
				// 1. Verify source table exists and is non-empty.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$src_count = $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					$src
				) );
				if ( ! $src_count ) {
					$table_log['status'] = 'skipped';
					$table_log['note']   = 'source table does not exist';
					$log['tables'][ $spai_suffix ] = $table_log;
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$src_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$src}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( 0 === $src_rows ) {
					$table_log['status'] = 'skipped';
					$table_log['note']   = 'source table is empty';
					$log['tables'][ $spai_suffix ] = $table_log;
					continue;
				}

				// 2. Verify destination table exists and is empty.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$dst_exists = $wpdb->get_var( $wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s',
					$dst
				) );
				if ( ! $dst_exists ) {
					$table_log['status'] = 'skipped';
					$table_log['note']   = 'destination table does not exist (activator may not have run yet)';
					$log['tables'][ $spai_suffix ] = $table_log;
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$dst_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$dst}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( $dst_rows > 0 ) {
					$table_log['status'] = 'skipped';
					$table_log['note']   = "destination table already has {$dst_rows} rows — skipped to preserve data";
					$log['tables'][ $spai_suffix ] = $table_log;
					continue;
				}

				// 3. Compare schemas.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$src_cols_raw = $wpdb->get_results( "SHOW COLUMNS FROM `{$src}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$dst_cols_raw = $wpdb->get_results( "SHOW COLUMNS FROM `{$dst}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

				$src_col_names = array_column( $src_cols_raw, 'Field' );
				$dst_col_names = array_column( $dst_cols_raw, 'Field' );

				$common_cols      = array_values( array_intersect( $src_col_names, $dst_col_names ) );
				$src_only_cols    = array_values( array_diff( $src_col_names, $dst_col_names ) );
				$dst_only_cols    = array_values( array_diff( $dst_col_names, $src_col_names ) );

				if ( empty( $common_cols ) ) {
					$table_log['status'] = 'skipped';
					$table_log['note']   = 'no common columns between source and destination — schema mismatch too severe to migrate safely';
					$log['tables'][ $spai_suffix ] = $table_log;
					continue;
				}

				// 4. Build the INSERT … SELECT with only the common columns.
				$col_list = implode( ', ', array_map( function( $c ) {
					return '`' . esc_sql( $c ) . '`';
				}, $common_cols ) );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$result = $wpdb->query(
					"INSERT INTO `{$dst}` ({$col_list}) SELECT {$col_list} FROM `{$src}`" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				);

				if ( false === $result ) {
					$table_log['status'] = 'error';
					$table_log['note']   = 'INSERT failed: ' . $wpdb->last_error;
				} else {
					$table_log['status'] = 'migrated';
					$table_log['rows']   = (int) $result;

					$schema_notes = array();
					if ( ! empty( $src_only_cols ) ) {
						$schema_notes[] = 'source-only columns (not migrated): ' . implode( ', ', $src_only_cols );
					}
					if ( ! empty( $dst_only_cols ) ) {
						$schema_notes[] = 'destination-only columns (set to default): ' . implode( ', ', $dst_only_cols );
					}
					$table_log['note'] = empty( $schema_notes ) ? 'schema parity OK' : implode( '; ', $schema_notes );
				}

			} catch ( \Throwable $e ) {
				$table_log['status'] = 'error';
				$table_log['note']   = 'exception: ' . $e->getMessage();
			} catch ( \Exception $e ) {
				$table_log['status'] = 'error';
				$table_log['note']   = 'exception: ' . $e->getMessage();
			}

			$log['tables'][ $spai_suffix ] = $table_log;
		}
	}

	/**
	 * Get the migration log from the last run.
	 *
	 * Returns null if migration has never run.
	 *
	 * @return array|null
	 */
	/**
	 * A 2.8 analytics opt-in cannot be attributed — any API key could write
	 * spai_settings — so it must be labelled legacy, which is not consent on
	 * Free (see Mcpwp_Activator::stamp_analytics_choice()). The copied settings
	 * are left exactly as they were; clearing the stamp flag makes the stamp —
	 * which runs after this migration on plugin load — label it, even on a
	 * site that stamped before.
	 *
	 * @param array $settings Settings just written.
	 */
	private static function restamp_if_analytics_copied( array $settings ) {
		if ( array_key_exists( 'analytics_enabled', $settings )
			&& in_array( $settings['analytics_enabled'], array( true, 1, '1' ), true )
			&& ! isset( $settings['analytics_choice'] ) ) {
			delete_option( 'mcpwp_analytics_choice_stamped' );
		}
	}

	public static function get_log() {
		$log = get_option( self::LOG_OPTION, null );
		return is_array( $log ) ? $log : null;
	}

	/**
	 * Check whether the migration has been completed.
	 *
	 * @return bool
	 */
	public static function is_done() {
		return (bool) get_option( self::MIGRATED_FLAG );
	}

	/**
	 * Reset the migration flag (for testing / re-run scenarios only).
	 *
	 * This should NOT be called in production code outside of test tooling.
	 */
	public static function reset_flag() {
		delete_option( self::MIGRATED_FLAG );
		delete_option( self::LOG_OPTION );
	}
}
