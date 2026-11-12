<?php
/**
 * Shared site-graph uninstall logic (#943 S1 review round 2, BLOCK-1).
 *
 * `uninstall.php` (WordPress core's own preferred uninstall mechanism —
 * "core uses uninstall.php first") and `mcpwp_fs_uninstall_cleanup_site()`
 * (Freemius's `after_uninstall` action, fired by the Freemius SDK's OWN
 * uninstall reporting/cleanup) both need to drop the 4 graph tables and
 * their options/schedules. Duplicating that logic in both places is how it
 * drifts out of sync (exactly what happened here: `uninstall.php` had the
 * queue table and the current option/hook names; `freemius-init.php` still
 * had the old `mcpwp_graph_dirty`/`mcpwp_graph_dirty_drain` names and no
 * queue table at all). One class, called from both.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drops the site graph's tables, options and schedules.
 */
class Mcpwp_Uninstaller {

	/** @var array<string> Table name suffixes (without the site prefix). */
	const GRAPH_TABLES = array(
		'mcpwp_graph_nodes',
		'mcpwp_graph_edges',
		'mcpwp_graph_signals',
		'mcpwp_graph_queue',
	);

	/** @var array<string> Options this feature owns. */
	const GRAPH_OPTIONS = array(
		'mcpwp_graph_ready',
		'mcpwp_graph_backfill_status',
		'mcpwp_graph_backfill_cursor',
		'mcpwp_graph_built_at',
		'mcpwp_graph_reconcile_status',
		'mcpwp_graph_reconcile_cursor',
	);

	/** @var array<string> Cron/Action-Scheduler hooks this feature owns. */
	const GRAPH_CRON_HOOKS = array(
		'mcpwp_graph_worker',
		'mcpwp_graph_backfill',
		'mcpwp_graph_tables_retry',
		'mcpwp_graph_signals_prune',
	);

	/**
	 * Drop the graph tables/options/schedules for every site — the current
	 * site on single-site, every site in the network on multisite (W3: with
	 * `number => 0`, not the default-limited page, so a network past 100
	 * sites is not silently left with orphaned tables past the first page).
	 *
	 * Call this from a context where switch_to_blog() is safe to call
	 * (uninstall.php, an admin-triggered Freemius uninstall action) — never
	 * from inside a request that is itself mid switch_to_blog() for an
	 * unrelated reason, since this always ends by restoring the blog that
	 * was current when it was called.
	 */
	public static function drop_graph_data() {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::drop_graph_data_for_current_site();
				restore_current_blog();
			}
			return;
		}

		self::drop_graph_data_for_current_site();
	}

	/** @var array<string> Approvals options (#1042). */
	const APPROVALS_OPTIONS = array(
		'mcpwp_approvals_table_migrated',
		'mcpwp_approval_requests_pre_3149',
		'mcpwp_approval_requests',
		'mcpwp_approvals_identity_migrated',
		// OAuth client store + D10 migration flag + aud-rotation record (PR-2).
		'mcpwp_oauth_clients',
		'mcpwp_oauth_clients_lock',
		'mcpwp_oauth_dcr_site_window',
		'mcpwp_oauth_default_migrated',
		'mcpwp_oauth_last_resource',
		// OAuth revocation epochs + their lock, D10 migration lock (#1101).
		'mcpwp_oauth_deleted_users',
		'mcpwp_oauth_client_epochs',
		'mcpwp_oauth_revoked_families',
		'mcpwp_oauth_revoked_pairs',
		'mcpwp_oauth_revocation_lock',
		'mcpwp_oauth_default_migration_lock',
		'mcpwp_approvals_table_migration_lock',
		'mcpwp_approvals_schema_version',
		'mcpwp_approvals_migration_skipped',
		'mcpwp_approvals_rederive_after',
	);

	/**
	 * Drop the approvals table (#1042), its options and its cron for every
	 * site — the current site on single-site, every site in the network on
	 * multisite (same looping as drop_graph_data()).
	 */
	public static function drop_approvals_data() {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::drop_approvals_data_for_current_site();
				restore_current_blog();
			}
			return;
		}

		self::drop_approvals_data_for_current_site();
	}

	/**
	 * Drop the approvals table, options and cron for ONLY the current site.
	 */
	public static function drop_approvals_data_for_current_site() {
		global $wpdb;

		$table = $wpdb->prefix . 'mcpwp_approvals';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe; the suffix is fixed.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		foreach ( self::APPROVALS_OPTIONS as $option ) {
			delete_option( $option );
		}

		wp_clear_scheduled_hook( 'mcpwp_approvals_retention' );
		wp_clear_scheduled_hook( 'mcpwp_approvals_late_import' );

		self::drop_oauth_tokens_for_current_site();
	}

	/**
	 * Drop the OAuth token table (#1101 PR-3b), its options and its cron for
	 * ONLY the current site.  Runs inside every per-site loop that drops the
	 * approvals data (drop_approvals_data_for_current_site() calls it).
	 */
	public static function drop_oauth_tokens_for_current_site() {
		global $wpdb;

		$table = $wpdb->prefix . 'mcpwp_oauth_tokens';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe; the suffix is fixed.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		delete_option( 'mcpwp_oauth_tokens_schema_version' );
		delete_option( 'mcpwp_oauth_tokens_grace_until' );
		wp_clear_scheduled_hook( 'mcpwp_oauth_tokens_cleanup' );
	}

	/**
	 * Drop the graph tables/options/schedules for ONLY the current site —
	 * no multisite looping. Use this when the caller is already inside its
	 * own per-site loop (Freemius's `mcpwp_fs_uninstall_cleanup()`, which
	 * switch_to_blog()s per site before calling this).
	 */
	public static function drop_graph_data_for_current_site() {
		global $wpdb;

		foreach ( self::GRAPH_TABLES as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe; $suffix is a fixed class constant, never input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		foreach ( self::GRAPH_OPTIONS as $option ) {
			delete_option( $option );
		}

		foreach ( self::GRAPH_CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
			// N4b / BLOCK-1(a): also unschedule any Action Scheduler action
			// for this hook — wp_clear_scheduled_hook() only ever touches
			// WP-Cron's own store, and this class's worker/backfill prefer
			// Action Scheduler when it is present, leaving orphaned
			// actionscheduler_actions rows behind otherwise.
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook );
			}
		}
	}

	/** @var array<string> The older custom tables (suffixes, exact — never a LIKE pattern) (#995). */
	const LEGACY_TABLES = array(
		'mcpwp_activity_log',
		'mcpwp_webhooks',
		'mcpwp_webhook_logs',
		'mcpwp_feedback',
		'mcpwp_action_log',
	);

	/** @var array<string> Core options and action-log migration markers (#995). */
	const LEGACY_OPTIONS = array(
		'mcpwp_api_key',
		'mcpwp_api_keys',
		// The key store's last-used bookkeeping and write lock (#1096).
		'mcpwp_api_keys_last_used',
		'mcpwp_api_keys_lock',
		'mcpwp_settings',
		'mcpwp_version',
		'mcpwp_db_version',
		'mcpwp_rate_limit_settings',
		'mcpwp_first_activation',
		'mcpwp_premium_preferred',
		'mcpwp_last_update_flush',
		'mcpwp_action_log_resource_post_type_column',
		'mcpwp_action_log_column_migration_lock',
		'mcpwp_action_log_post_type_backfilled',
		'mcpwp_action_log_backfill_lock',
		'mcpwp_action_log_backfill_cursor',
		'mcpwp_action_log_post_type_backfill_cursor',
		// Stored third-party provider keys (encrypted) — the plugin's own data.
		'mcpwp_integrations',
		// Migrated licence/trial copies and the 2.8.x originals the licence
		// bridge still reads (Mcpwp_License).
		'mcpwp_pro_license',
		'mcpwp_trial_started',
		'spai_pro_license',
		'spai_trial_started',
		// The 2.8.x API key stores. find_legacy_spai_key() still authenticates
		// against these, so leaving them lets a key revoked in mcpwp_api_keys
		// come back to life after uninstall + reinstall (#995 review). Exact
		// names only — never a spai_% pattern.
		'spai_api_keys',
		'spai_api_key',
		// NOT 'mcpwp_migrated_from_spai': it is kept on purpose. Mcpwp_Migrate::run()
		// re-runs whenever that flag is missing and spai_settings exists, and
		// would copy the leftover spai_* options and tables (webhooks with
		// their secrets, provider keys, site memory) back in — resurrecting
		// data the owner deleted. With the key and licence stores above gone,
		// keeping the flag leaves both bridges closed.
	);

	/** @var array<string> Transients cleared with the older data (#995). */
	const LEGACY_TRANSIENTS = array(
		'mcpwp_capabilities_cache',
		'mcpwp_new_api_key',
	);

	/** @var array<string> Cron hooks cleared with the older data (#995). */
	const LEGACY_CRON_HOOKS = array(
		'mcpwp_cleanup_logs',
		'mcpwp_check_alerts',
		'mcpwp_action_log_daily_prune',
		// Per-site schedules deactivation clears only on the current site.
		'mcpwp_compute_signals',
		'mcpwp_figma_sync_design_tokens',
	);

	/**
	 * Drop the older tables and core options for every site — the current
	 * site on single-site, every site in the network on multisite (#995).
	 * Same looping as drop_graph_data().
	 */
	public static function drop_legacy_data() {
		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( $site_id );
				self::drop_legacy_data_for_current_site();
				restore_current_blog();
			}
			return;
		}

		self::drop_legacy_data_for_current_site();
	}

	/**
	 * Drop the older tables, options, transients and cron for ONLY the
	 * current site (#995). Idempotent. Table names are the exact fixed
	 * suffixes above on this site's prefix — nothing is matched by pattern, so
	 * another plugin's tables are never touched, and no post content is.
	 */
	public static function drop_legacy_data_for_current_site() {
		global $wpdb;

		foreach ( self::LEGACY_TABLES as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe; $suffix is a fixed class constant, never input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		foreach ( self::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
		foreach ( self::LEGACY_TRANSIENTS as $transient ) {
			delete_transient( $transient );
		}
		foreach ( self::LEGACY_CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Whether the given plugins list holds another copy of MCPWP (#995).
	 *
	 * Tables and options are keyed by the fixed `mcpwp_` names, not by plugin
	 * directory, so deleting one copy (`mcpwp-old/`, or `mcpwp-premium/` while
	 * a Free `mcpwp/` stays) while another is installed — active or not —
	 * would wipe the data the other owns. A copy is identified like
	 * Mcpwp_Duplicate_Copy does: Plugin Name `MCPWP`, `MCPWP Premium` or `MCPWP (Premium)` (the
	 * Freemius SDK appends/strips a "(Premium)" suffix) AND
	 * the `mcpwp` Text Domain, so an add-on or an unrelated plugin that merely
	 * reuses the text domain does not suppress cleanup. Compared by basename,
	 * so the plugin being uninstalled never counts as its own other copy.
	 *
	 * @param array<string,array<string,mixed>> $plugins     get_plugins() output (basename => headers).
	 * @param string                            $uninstalled Basename being uninstalled.
	 */
	public static function another_copy_installed( array $plugins, $uninstalled ) {
		foreach ( $plugins as $basename => $headers ) {
			if ( (string) $basename === (string) $uninstalled || ! is_array( $headers ) ) {
				continue;
			}
			$name = isset( $headers['Name'] ) ? trim( (string) $headers['Name'] ) : '';
			$td   = isset( $headers['TextDomain'] ) ? (string) $headers['TextDomain'] : '';
			if ( 'mcpwp' === $td && preg_match( '/^MCPWP(\s+\(?Premium\)?)?$/i', $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a copy of MCPWP other than the one in $self_dir is loaded in this
	 * request — e.g. one in mu-plugins, which get_plugins() never lists.
	 *
	 * @param string $loaded_dir MCPWP_PLUGIN_DIR, or '' when no copy is loaded.
	 * @param string $self_dir   Directory of the copy being uninstalled.
	 */
	public static function loaded_from_elsewhere( $loaded_dir, $self_dir ) {
		if ( '' === (string) $loaded_dir ) {
			return false;
		}
		$norm = static function ( $dir ) {
			$real = function_exists( 'realpath' ) ? realpath( (string) $dir ) : false;
			$dir  = false !== $real ? $real : (string) $dir;
			return rtrim( str_replace( '\\', '/', $dir ), '/' );
		};
		return $norm( $loaded_dir ) !== $norm( $self_dir );
	}

	/**
	 * Whether any other copy of MCPWP exists, so this copy must leave the
	 * shared tables and options alone. Used by uninstall.php and by the
	 * Freemius cleanup.
	 *
	 * @param string $self_basename Basename of the copy being uninstalled.
	 * @param string $self_dir      Its directory.
	 * @param string $loaded_dir    Directory of the copy loaded in this request ('' when none) —
	 *                              the caller reads MCPWP_PLUGIN_DIR; this class never branches on it.
	 */
	public static function other_copy_present( $self_basename, $self_dir, $loaded_dir = '' ) {
		if ( self::loaded_from_elsewhere( $loaded_dir, $self_dir ) ) {
			return true;
		}
		if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			return false;
		}
		return self::another_copy_installed( get_plugins(), $self_basename );
	}
}
