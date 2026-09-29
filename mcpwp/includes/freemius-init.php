<?php
/**
 * Freemius SDK Integration
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'mcpwp_fs' ) ) {
	/**
	 * Ensure Freemius has a host value when WordPress runs outside HTTP.
	 *
	 * Freemius reads HTTP_HOST during initialization. WP-CLI and some cron
	 * contexts do not set it, which can produce noisy warnings during package
	 * activation and update checks.
	 */
	function mcpwp_freemius_ensure_http_host() {
		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			return;
		}

		$site_url = function_exists( 'home_url' ) ? home_url() : '';
		$host     = function_exists( 'wp_parse_url' ) ? wp_parse_url( $site_url, PHP_URL_HOST ) : parse_url( $site_url, PHP_URL_HOST );

		if ( is_string( $host ) && '' !== $host ) {
			$_SERVER['HTTP_HOST'] = $host;
		}
	}

	/**
	 * Create a helper function for easy SDK access.
	 *
	 * @return Freemius
	 */
	function mcpwp_fs() {
		global $mcpwp_fs;

		if ( ! isset( $mcpwp_fs ) ) {
			// Activate multisite network integration.
			if ( ! defined( 'WP_FS__PRODUCT_23824_MULTISITE' ) ) {
				define( 'WP_FS__PRODUCT_23824_MULTISITE', true );
			}

			mcpwp_freemius_ensure_http_host();

			// Include Freemius SDK.
			require_once MCPWP_PLUGIN_DIR . 'freemius/start.php';

			$mcpwp_fs = fs_dynamic_init( array(
				'id'                  => '23824',
				'slug'                => 'mcpwp',
				'premium_slug'        => 'mcpwp-premium',
				'type'                => 'plugin',
				'public_key'          => 'pk_24f806380f2ccf8a5e3283dac895b',
				// Free + premium distribution. The premium source sets is_premium
				// true; Freemius auto-generates the free wp.org version (is_premium
				// flipped) using wp_org_gatekeeper.
				'is_premium'          => true,
				'is_premium_only'     => false,
				'has_premium_version' => true,
				'has_addons'          => false,
				'has_paid_plans'      => true,
				'is_org_compliant'    => true,
				// Authorizes Freemius to generate the free wp.org build. Stripped
				// from the free version automatically.
				'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
				// is_require_payment=false + anonymous mode allowed: free tier is
				// usable immediately (Skip), Pro is a passive Upgrade nag rather
				// than a hard license/payment gate at first activation. The free
				// vs Pro split already happens per-tool at runtime (pro_required
				// upsell telemetry, v3.2.2+) — that only works if people can
				// actually reach the free tier first.
				'trial'               => array(
					'days'               => 14,
					'is_require_payment' => false,
				),
				// NOTE: menu kept as the plugin's real TOP-LEVEL page
				// (admin.php?page=mcpwp). The dashboard snippet's
				// tools.php first-path would 404 after opt-in since the menu is
				// not under Tools. account/pricing kept so Freemius renders those.
				'menu'                => array(
					'slug'       => 'mcpwp',
					'parent'     => array(
						'slug' => 'mcpwp',
					),
					'first-path' => 'admin.php?page=mcpwp',
					'support'    => false,
					'account'    => true,
					'pricing'    => true,
				),
				'is_live'             => true,
			) );
		}

		return $mcpwp_fs;
	}

	// Init Freemius.
	mcpwp_fs();

	// Signal that SDK was initiated.
	do_action( 'mcpwp_fs_loaded' );
}

/**
 * Get Freemius instance safely.
 *
 * @return object|null
 */
function mcpwp_get_fs_instance() {
	if ( ! function_exists( 'mcpwp_fs' ) ) {
		return null;
	}
	$instance = mcpwp_fs();
	return is_object( $instance ) ? $instance : null;
}

/**
 * Freemius customizations.
 */

// Custom icon for opt-in screen.
function mcpwp_fs_custom_icon() {
	return MCPWP_PLUGIN_DIR . 'assets/icon-128x128.png';
}
$mcpwp_fs_instance = mcpwp_get_fs_instance();
if ( $mcpwp_fs_instance && method_exists( $mcpwp_fs_instance, 'add_filter' ) ) {
	$mcpwp_fs_instance->add_filter( 'plugin_icon', 'mcpwp_fs_custom_icon' );
}

/**
 * Freemius's connect screen defaults to requiring a license key whenever the
 * running code is the premium package itself — it assumes premium code only
 * ever arrives via its own free-to-paid auto-upgrade, never a cold direct
 * download. We distribute the premium zip directly (mcpwp-freemius-*.zip),
 * so without this every fresh install hit a hard license/trial gate with no
 * way to just try the free tier — the opposite of how the free/Pro split
 * already works everywhere else in this plugin (per-tool gating, not an
 * all-or-nothing wall).
 *
 * BUG FIX (2026-07-17, Hadi report: "manage pricing and license" led nowhere):
 * get_reconnect_url() ALSO builds its link through this same connect_url
 * filter, carrying fs_action=reset_anonymous_mode. That's a user explicitly
 * asking to come back and enter a real license after having skipped — forcing
 * require_license=false onto that flow defeats the entire point of clicking
 * it, landing back on the same skip-oriented screen with no obvious way to
 * enter a license (just a small, easy-to-miss "Have a license key?" toggle).
 *
 * TWO separate moments need covering, not one:
 *  1. Building the "Manage pricing and license" href on the Setup page
 *     (get_reconnect_url() → get_activation_url($params) with
 *     fs_action=reset_anonymous_mode already merged into $url BEFORE this
 *     filter runs) — a plain substring check on $url catches this, since the
 *     marker is right there in the string being filtered.
 *  2. Freemius's own connect_again() (hooked on admin_init, fired when a
 *     click lands on that href): it resets anonymous mode, THEN does a
 *     SECOND internal redirect to a freshly built activation URL with NO
 *     fs_action marker at all — which goes through this same filter again.
 *     A substring check can't see this one; only real, tracked intent can.
 *     mcpwp_fs_mark_reconnect_intent() sets a short-lived transient the
 *     moment case 1's URL is actually visited, and this covers case 2.
 * Live-verified via a real reproduction (Reflection-forced is_anonymous=true,
 * walked the full request chain): missing either check alone still lets
 * require_license=false leak onto the link, right back to the stuck loop.
 *
 * @param string $url Connect/activation URL Freemius is about to redirect to.
 * @return string
 */
function mcpwp_fs_soften_connect_gate( $url ) {
	if ( false !== strpos( $url, 'fs_action=reset_anonymous_mode' )
		|| get_transient( 'mcpwp_fs_reconnect_intent' )
	) {
		return $url;
	}
	return add_query_arg( 'require_license', 'false', $url );
}
if ( $mcpwp_fs_instance && method_exists( $mcpwp_fs_instance, 'add_filter' ) ) {
	$mcpwp_fs_instance->add_filter( 'connect_url', 'mcpwp_fs_soften_connect_gate' );
}

/**
 * Mark that the user just explicitly asked to reconnect/re-enter a license
 * (get_reconnect_url()'s fs_action=reset_anonymous_mode), so the soft-gate
 * functions below can recognize real intent even across Freemius's own
 * internal redirect that drops that marker from the URL. Must run before
 * Freemius's own connect_again() (default priority on admin_init) so the
 * transient exists by the time that handler's redirect gets filtered.
 */
function mcpwp_fs_mark_reconnect_intent() {
	if ( isset( $_GET['fs_action'] ) && 'reset_anonymous_mode' === $_GET['fs_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, mirrors Freemius's own unauthenticated dispatch check.
		set_transient( 'mcpwp_fs_reconnect_intent', true, 60 );
	}
}
add_action( 'admin_init', 'mcpwp_fs_mark_reconnect_intent', 1 );

/**
 * The connect_url filter above only covers URLs Freemius builds itself (e.g.
 * "Connect Again"). The routine case — a user just clicking the plugin's own
 * admin menu item while still in activation mode — renders the connect
 * screen in place of the page content and reads require_license straight off
 * the current request, which never has our param. Inject it on admin_init,
 * before Freemius's own page callback runs, so the soft gate applies
 * regardless of how the page was reached.
 */
function mcpwp_fs_default_require_license_false() {
	if ( ! isset( $_GET['page'] ) || 'mcpwp' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing check, no state change.
		return;
	}
	// See mcpwp_fs_mark_reconnect_intent() above — a user explicitly
	// reconnecting must not be forced back into skip-mode.
	if ( get_transient( 'mcpwp_fs_reconnect_intent' ) ) {
		return;
	}
	// fs_request_get_bool() reads $_REQUEST, not $_GET — both must be set,
	// they are independent superglobals in PHP, not aliases of each other.
	if ( ! isset( $_GET['require_license'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['require_license'] = 'false';
	}
	if ( ! isset( $_REQUEST['require_license'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_REQUEST['require_license'] = 'false';
	}
}
add_action( 'admin_init', 'mcpwp_fs_default_require_license_false', 5 );

// Custom connect message.
function mcpwp_fs_custom_connect_message(
	$message,
	$user_first_name,
	$product_title,
	$user_login,
	$site_link,
	$freemius_link
) {
	return sprintf(
		/* translators: %1$s: User first name, %2$s: Product title */
		__( 'Hey %1$s, allow %2$s to collect diagnostic data to help improve the plugin and enable license management.', 'mcpwp' ),
		$user_first_name,
		'<b>' . $product_title . '</b>'
	);
}
if ( $mcpwp_fs_instance && method_exists( $mcpwp_fs_instance, 'add_filter' ) ) {
	$mcpwp_fs_instance->add_filter( 'connect_message', 'mcpwp_fs_custom_connect_message', 10, 6 );
}

// Make "Download latest" links trigger Freemius auto-install (one-click upgrade).
function mcpwp_fs_download_latest_url_auto_install( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return $url;
	}

	$separator = ( strpos( $url, '?' ) === false ) ? '?' : '&';
	return $url . $separator . 'auto_install=true';
}
if ( $mcpwp_fs_instance && method_exists( $mcpwp_fs_instance, 'add_filter' ) ) {
	$mcpwp_fs_instance->add_filter( 'download_latest_url', 'mcpwp_fs_download_latest_url_auto_install' );
}

// Force Freemius to re-check for updates when visiting the plugins page.
// This fixes the delay between deploying a new version and seeing the update notice.
add_action( 'load-plugins.php', 'mcpwp_fs_maybe_flush_update_cache' );
add_action( 'load-update-core.php', 'mcpwp_fs_maybe_flush_update_cache' );

/**
 * Clear Freemius update cache on plugins page to ensure updates appear immediately.
 */
function mcpwp_fs_maybe_flush_update_cache() {
	if ( ! function_exists( 'mcpwp_fs' ) ) {
		return;
	}

	$fs = mcpwp_fs();

	// Only flush once per hour to avoid hammering Freemius API.
	$last_flush = get_option( 'mcpwp_last_update_flush', 0 );
	if ( time() - $last_flush < 3600 ) {
		return;
	}

	// Delete the WordPress update transient so it re-checks.
	delete_site_transient( 'update_plugins' );
	update_option( 'mcpwp_last_update_flush', time(), false );

	// Trigger Freemius to re-sync if available.
	if ( is_object( $fs ) && method_exists( $fs, 'get_update' ) ) {
		$fs->get_update( false, false );
	}
}

// Uninstall hook.
if ( $mcpwp_fs_instance && method_exists( $mcpwp_fs_instance, 'add_action' ) ) {
	$mcpwp_fs_instance->add_action( 'after_uninstall', 'mcpwp_fs_uninstall_cleanup' );
}

/**
 * Cleanup on uninstall.
 */
function mcpwp_fs_uninstall_cleanup() {
	if ( is_multisite() ) {
		// W3: number => 0 — every site in the network, not the default-limited page.
		$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
		foreach ( $sites as $blog_id ) {
			switch_to_blog( $blog_id );
			mcpwp_fs_uninstall_cleanup_site();
			restore_current_blog();
		}
	} else {
		mcpwp_fs_uninstall_cleanup_site();
	}
}

/**
 * Cleanup a single site's MCPWP data.
 */
function mcpwp_fs_uninstall_cleanup_site() {
	// Clean up options.
	delete_option( 'mcpwp_api_key' );
	delete_option( 'mcpwp_api_keys' );
	delete_option( 'mcpwp_settings' );
	delete_option( 'mcpwp_version' );
	delete_option( 'mcpwp_db_version' );
	delete_option( 'mcpwp_rate_limit_settings' );
	delete_option( 'mcpwp_first_activation' );
	delete_option( 'mcpwp_premium_preferred' );
	delete_option( 'mcpwp_last_update_flush' );

	// Clean up transients.
	delete_transient( 'mcpwp_capabilities_cache' );
	delete_transient( 'mcpwp_new_api_key' );

	// Clear scheduled events.
	wp_clear_scheduled_hook( 'mcpwp_cleanup_logs' );
	wp_clear_scheduled_hook( 'mcpwp_check_alerts' );

	// Clean up tables.
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_activity_log" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_webhooks" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_webhook_logs" );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->prefix is safe.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcpwp_feedback" );

	// Site graph tables/options/schedules (#943 S1 review round 2, BLOCK-1b):
	// this is the SAME helper uninstall.php calls — the two lists cannot
	// drift out of sync again the way they just did (this function's inline
	// copy still had mcpwp_graph_dirty/mcpwp_graph_dirty_drain and no queue
	// table, both stale by the time this review round landed). Called here
	// per-site, WITHOUT its own multisite loop — mcpwp_fs_uninstall_cleanup()
	// above already switch_to_blog()s before calling this function.
	if ( ! class_exists( 'Mcpwp_Uninstaller' ) ) {
		require_once __DIR__ . '/class-mcpwp-uninstaller.php';
	}
	Mcpwp_Uninstaller::drop_graph_data_for_current_site();
	Mcpwp_Uninstaller::drop_approvals_data_for_current_site();
}
