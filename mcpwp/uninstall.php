<?php
/**
 * Uninstall routine.
 *
 * WordPress core includes this file (never the plugin's main file) when the
 * plugin is deleted from wp-admin > Plugins, so it must be fully standalone —
 * no assumption that any MCPWP class has been loaded.
 *
 * IMPORTANT (#943 S1 review round 2, BLOCK-1): core prefers a static
 * `uninstall.php` over a plugin's `register_uninstall_hook()` callback — a
 * plugin gets ONE OR THE OTHER, never both. Freemius registers its OWN
 * uninstall hook internally (which is what fires `after_uninstall` ->
 * `mcpwp_fs_uninstall_cleanup()` in `includes/freemius-init.php` — SDK
 * cleanup, licence deactivation, uninstall reporting, and this file's own
 * table cleanup). Shipping this file in the FREEMIUS build would silently
 * disable ALL of that for every paying customer who uninstalls. This file
 * therefore ships ONLY in the Free and Agency builds — `scripts/
 * build-freemius.sh` deletes it, and `scripts/verify_freemius_no_uninstall.sh`
 * fails the build if it is ever present in that ZIP. Free/Agency builds have
 * no Freemius SDK loaded at all, so this static file is the only uninstall
 * mechanism available to them.
 *
 * Scope: every table, option and schedule the plugin owns, for single site
 * and, on multisite, every blog in the network — the site graph tables, the
 * approvals/OAuth tables, and (#995) the older tables (activity log,
 * webhooks, webhook logs, feedback, action log) with the core options.
 * The Freemius build runs the same Mcpwp_Uninstaller methods from
 * `mcpwp_fs_uninstall_cleanup_site()`, so the lists cannot diverge.
 *
 * @package MCPWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! class_exists( 'Mcpwp_Uninstaller' ) ) {
	// Already loaded when another copy of the plugin is active in this
	// request — declaring it twice would be a fatal.
	require_once __DIR__ . '/includes/class-mcpwp-uninstaller.php';
}

// Another installed copy of MCPWP (e.g. `mcpwp-old/`) shares these tables and
// options; deleting this copy must not wipe the other's data.
if ( ! function_exists( 'get_plugins' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if ( Mcpwp_Uninstaller::another_copy_installed( get_plugins(), defined( 'WP_UNINSTALL_PLUGIN' ) ? WP_UNINSTALL_PLUGIN : '' ) ) {
	return;
}

Mcpwp_Uninstaller::drop_graph_data();

// The approvals table (#1042) and its options.
Mcpwp_Uninstaller::drop_approvals_data();

// The older tables, core options, transients and cron (#995).
Mcpwp_Uninstaller::drop_legacy_data();
