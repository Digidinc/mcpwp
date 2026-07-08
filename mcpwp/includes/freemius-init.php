<?php
/**
 * Freemius SDK Integration
 *
 * @package mumcp
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
				'trial'               => array(
					'days'               => 14,
					'is_require_payment' => true,
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
		$sites = get_sites( array( 'fields' => 'ids' ) );
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
 * Cleanup a single site's mumcp data.
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
}
