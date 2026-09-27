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
 * Scope: the site graph tables — nodes, edges, signals, queue — plus their
 * options and cron/Action-Scheduler schedules, for single site and, on
 * multisite, every blog in the network. The plugin's OLDER tables (activity
 * log, webhooks, webhook logs, feedback, action log) are deliberately left
 * untouched here: on a self-hosted/agency install those were previously
 * cleaned up only via the Freemius `after_uninstall` action, which — see
 * above — never runs on a Free or Agency build at all. That is a
 * pre-existing gap tracked separately (#995) rather than expanded here.
 *
 * @package MCPWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-mcpwp-uninstaller.php';

Mcpwp_Uninstaller::drop_graph_data();
