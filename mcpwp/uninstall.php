<?php
/**
 * Uninstall routine.
 *
 * WordPress core includes this file (never the plugin's main file) when the
 * plugin is deleted from wp-admin > Plugins, so it must be fully standalone —
 * no assumption that any MCPWP class has been loaded.
 *
 * Scope (#943 S1 review, W7): the site graph tables only — nodes, edges,
 * signals, queue — plus their options and cron schedules, for single site
 * and, on multisite, every blog in the network. The plugin's OLDER tables
 * (activity log, webhooks, webhook logs, feedback, action log) are
 * deliberately left untouched here: on a self-hosted/agency install those
 * were previously cleaned up only via the Freemius `after_uninstall` action
 * in `includes/freemius-init.php`, which is gated on `MCPWP_FREEMIUS_BUILD`
 * and therefore never runs on a Free or Agency build at all — a pre-existing
 * gap this file does not attempt to fix (tracked separately; see the issue
 * this PR files rather than silently expanding scope here).
 *
 * @package MCPWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Drop the 4 site graph tables + their options + cron schedules for the
 * CURRENT blog (the caller has already switch_to_blog()'d when needed).
 */
function mcpwp_uninstall_drop_graph_tables_current_blog() {
	global $wpdb;

	foreach ( array( 'mcpwp_graph_nodes', 'mcpwp_graph_edges', 'mcpwp_graph_signals', 'mcpwp_graph_queue' ) as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	foreach ( array(
		'mcpwp_graph_ready',
		'mcpwp_graph_backfill_status',
		'mcpwp_graph_backfill_cursor',
		'mcpwp_graph_built_at',
		'mcpwp_graph_reconcile_status',
		'mcpwp_graph_reconcile_cursor',
	) as $option ) {
		delete_option( $option );
	}

	foreach ( array(
		'mcpwp_graph_worker',
		'mcpwp_graph_backfill',
		'mcpwp_graph_tables_retry',
		'mcpwp_graph_signals_prune',
	) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		mcpwp_uninstall_drop_graph_tables_current_blog();
		restore_current_blog();
	}
} else {
	mcpwp_uninstall_drop_graph_tables_current_blog();
}
