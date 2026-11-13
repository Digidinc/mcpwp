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
		// Minimum role for AI connections, and the "Add to ChatGPT" key id.
		'mcpwp_oauth_min_role',
		'mcpwp_chatgpt_connector_key_id',
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

		self::drop_oauth_residue_for_current_site();
	}

	/** @var array<string> User meta the OAuth server writes (per-user revocation epoch, notice dismissal). */
	const OAUTH_USER_META = array(
		'mcpwp_oauth_revoked_before',
		'mcpwp_oauth_default_notice_dismissed',
	);

	/** @var array<string> Prefixes of the transients the OAuth server writes: codes, redeemed-code markers, DCR buckets, family revocation markers, legacy refresh/access tokens, per-user notices. */
	const OAUTH_TRANSIENT_PREFIXES = array(
		'mcpwp_oauth_code_',
		'mcpwp_oauth_codeused_',
		'mcpwp_oauth_dcr_',
		'mcpwp_oauth_famrev_',
		'mcpwp_oauth_rt_',
		'mcpwp_oauth_token_',
		'mcpwp_oauth_apps_notice_',
	);

	/**
	 * Remove the OAuth server's user meta and transients (its options are in
	 * APPROVALS_OPTIONS, its table is dropped above).  Transients are removed
	 * from the options table, so a site on a persistent object cache keeps
	 * the ones the cache holds until they expire (all are short-lived or
	 * hashed ids, and none carries a usable token).
	 */
	public static function drop_oauth_residue_for_current_site() {
		global $wpdb;

		foreach ( self::OAUTH_USER_META as $meta_key ) {
			delete_metadata( 'user', 0, $meta_key, '', true );
		}

		foreach ( self::OAUTH_TRANSIENT_PREFIXES as $prefix ) {
			foreach ( array( '_transient_', '_transient_timeout_' ) as $kind ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->options is core's; the LIKE value is a fixed constant, escaped and prepared.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $kind . $prefix ) . '%' ) );
			}
		}
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
}
