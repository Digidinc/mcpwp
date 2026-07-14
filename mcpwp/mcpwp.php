<?php
/**
 * Mumega MCP
 *
 * @package           MCPWP
 * @author            Mumega
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Mumega MCP
 * Description:       A focused Model Context Protocol server for WordPress posts, pages, local media, and taxonomies.
 * x-release-please-start-version
 * Version:           3.4.3
 * x-release-please-end
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Mumega
 * Author URI:        https://mumega.com/
 * Text Domain:       mumega-mcp
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCPWP_VERSION', '3.4.3' ); // x-release-please-version.
define( 'MCPWP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCPWP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MCPWP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'MCPWP_MIN_WP_VERSION', '6.1' );
define( 'MCPWP_MIN_PHP_VERSION', '7.4' );

/**
 * Check the minimum WordPress and PHP versions.
 *
 * @return bool True when both runtime requirements are met.
 */
function mcpwp_requirements_met() {
	global $wp_version;
	return version_compare( PHP_VERSION, MCPWP_MIN_PHP_VERSION, '>=' ) && version_compare( $wp_version, MCPWP_MIN_WP_VERSION, '>=' );
}

/**
 * Load the retained plugin classes and start WordPress integrations.
 *
 * @return void
 */
function mcpwp_load_plugin() {
	if ( ! mcpwp_requirements_met() ) {
		return;
	}

	$files = array(
		'includes/traits/trait-mcpwp-api-auth.php',
		'includes/traits/trait-mcpwp-logging.php',
		'includes/traits/trait-mcpwp-sanitization.php',
		'includes/class-mcpwp-i18n.php',
		'includes/class-mcpwp-rate-limiter.php',
		'includes/core/class-mcpwp-posts.php',
		'includes/core/class-mcpwp-core.php',
		'includes/core/class-mcpwp-pages.php',
		'includes/core/class-mcpwp-drafts.php',
		'includes/core/class-mcpwp-media.php',
		'includes/mcp/class-mcpwp-mcp-tool-registry.php',
		'includes/mcp/traits/trait-mcpwp-free-tools-site.php',
		'includes/mcp/traits/trait-mcpwp-free-tools-content.php',
		'includes/mcp/traits/trait-mcpwp-free-tools-media.php',
		'includes/mcp/traits/trait-mcpwp-free-tools-ops.php',
		'includes/mcp/class-mcpwp-mcp-free-tools.php',
		'includes/api/class-mcpwp-rest-api.php',
		'includes/api/class-mcpwp-rest-site-info.php',
		'includes/api/class-mcpwp-rest-site-settings.php',
		'includes/api/class-mcpwp-rest-api-keys.php',
		'includes/api/class-mcpwp-rest-rate-limit.php',
		'includes/api/class-mcpwp-rest-posts.php',
		'includes/api/class-mcpwp-rest-pages.php',
		'includes/api/class-mcpwp-rest-media.php',
		'includes/api/class-mcpwp-rest-site-taxonomy.php',
		'includes/api/class-mcpwp-rest-mcp.php',
		'includes/admin/traits/trait-mcpwp-admin-setup.php',
		'includes/admin/traits/trait-mcpwp-admin-settings.php',
		'includes/admin/traits/trait-mcpwp-admin-activity.php',
		'includes/admin/class-mcpwp-admin.php',
		'includes/admin/class-mcpwp-settings.php',
		'includes/admin/class-mcpwp-tools-admin.php',
		'includes/admin/class-mcpwp-activity-log.php',
		'includes/class-mcpwp-migrate.php',
		'includes/class-mcpwp-loader.php',
	);
	foreach ( $files as $file ) {
		require_once MCPWP_PLUGIN_DIR . $file;
	}

	require_once MCPWP_PLUGIN_DIR . 'includes/class-mcpwp-activator.php';
	if ( version_compare( (string) get_option( 'mcpwp_db_version', '0' ), MCPWP_VERSION, '<' ) ) {
		Mcpwp_Activator::activate();
		update_option( 'mcpwp_db_version', MCPWP_VERSION );
	}
	Mcpwp_Migrate::run();

	( new Mcpwp_Loader() )->run();
}

/**
 * Activate plugin storage for one site or every site in a network.
 *
 * @param bool $network_wide Whether WordPress requested network activation.
 * @return void
 */
function mcpwp_activate( $network_wide = false ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/traits/trait-mcpwp-api-auth.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mcpwp-activator.php';
	if ( $network_wide && function_exists( 'get_sites' ) ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $mcpwp_site_id ) {
			switch_to_blog( $mcpwp_site_id );
			Mcpwp_Activator::activate();
			restore_current_blog();
		}
		return;
	}
	Mcpwp_Activator::activate();
}

/**
 * Clear plugin schedules for one site or every site in a network.
 *
 * @param bool $network_wide Whether WordPress requested network deactivation.
 * @return void
 */
function mcpwp_deactivate( $network_wide = false ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-mcpwp-deactivator.php';
	Mcpwp_Deactivator::deactivate( $network_wide );
}

register_activation_hook( __FILE__, 'mcpwp_activate' );
register_deactivation_hook( __FILE__, 'mcpwp_deactivate' );
add_action( 'plugins_loaded', 'mcpwp_load_plugin' );
