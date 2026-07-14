<?php
/** Install the copied immutable ZIP and activate its final basename. */

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'WP_INSTALLING', true );
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

function mcpwp_wporg_install_fail( $message ) {
	fwrite( STDERR, 'Install failure: ' . $message . "\n" );
	exit( 1 );
}

if ( ! is_blog_installed() ) {
	wp_install( 'Mumega MCP WordPress.org Gate', 'wporg-admin', 'admin@example.test', true, '', 'wporg-password' );
}

$admin = get_user_by( 'login', 'wporg-admin' );
if ( ! $admin ) {
	mcpwp_wporg_install_fail( 'could not resolve the WordPress administrator.' );
}
wp_set_current_user( $admin->ID );

$candidate      = '/opt/mcpwp-candidate.zip';
$expected_hash  = strtolower( (string) getenv( 'MCPWP_WPORG_SHA256' ) );
$candidate_hash = is_file( $candidate ) ? hash_file( 'sha256', $candidate ) : '';
if ( 64 !== strlen( $expected_hash ) || $candidate_hash !== $expected_hash ) {
	mcpwp_wporg_install_fail( 'copied candidate SHA-256 does not match the frozen host SHA-256.' );
}

if ( ! WP_Filesystem() ) {
	mcpwp_wporg_install_fail( 'WordPress could not initialize the direct filesystem.' );
}
global $wp_filesystem;
$existing = $wp_filesystem->dirlist( WP_PLUGIN_DIR );
if ( is_array( $existing ) ) {
	foreach ( array_keys( $existing ) as $name ) {
		if ( ! $wp_filesystem->delete( trailingslashit( WP_PLUGIN_DIR ) . $name, true ) ) {
			mcpwp_wporg_install_fail( 'could not empty the fresh plugin directory.' );
		}
	}
}
$remaining = $wp_filesystem->dirlist( WP_PLUGIN_DIR );
if ( is_array( $remaining ) && ! empty( $remaining ) ) {
	mcpwp_wporg_install_fail( 'the plugin directory was not empty before candidate installation.' );
}

$upgrade_dir = WP_CONTENT_DIR . '/upgrade';
if ( ! wp_mkdir_p( $upgrade_dir ) ) {
	mcpwp_wporg_install_fail( 'could not create the candidate staging directory.' );
}
$staged = $upgrade_dir . '/mumega-mcp-3.4.3.zip';
if ( ! copy( $candidate, $staged ) || hash_file( 'sha256', $staged ) !== $expected_hash ) {
	mcpwp_wporg_install_fail( 'the staged candidate bytes do not match the frozen candidate.' );
}

$installed = unzip_file( $staged, WP_PLUGIN_DIR );
wp_delete_file( $staged );
if ( is_wp_error( $installed ) ) {
	mcpwp_wporg_install_fail( $installed->get_error_message() );
}

$plugin = 'mumega-mcp/mumega-mcp.php';
if ( ! is_file( WP_PLUGIN_DIR . '/' . $plugin ) || is_file( WP_PLUGIN_DIR . '/mumega-mcp/mcpwp.php' ) ) {
	mcpwp_wporg_install_fail( 'the exact final plugin basename was not installed.' );
}
$plugins = $wp_filesystem->dirlist( WP_PLUGIN_DIR );
if ( ! is_array( $plugins ) || array( 'mumega-mcp' ) !== array_keys( $plugins ) ) {
	mcpwp_wporg_install_fail( 'the candidate is not the only plugin in the fresh plugin directory.' );
}

$debug_log = WP_CONTENT_DIR . '/debug.log';
if ( is_file( $debug_log ) && ! unlink( $debug_log ) ) {
	mcpwp_wporg_install_fail( 'could not reset the plugin-phase debug log.' );
}

$mu_dir = WPMU_PLUGIN_DIR;
if ( ! wp_mkdir_p( $mu_dir ) ) {
	mcpwp_wporg_install_fail( 'could not create the MU-plugin directory for the HTTP spy.' );
}
$spy_path = $mu_dir . '/mcpwp-wporg-outbound-spy.php';
if ( ! copy( '/opt/mcpwp-wporg-harness/outbound-spy.php', $spy_path ) ) {
	mcpwp_wporg_install_fail( 'could not install the pre-activation outbound HTTP spy.' );
}
require_once $spy_path;
mcpwp_wporg_reset_spy();
$spy_probe = apply_filters(
	'pre_http_request',
	false,
	array( 'method' => 'PATCH' ),
	'https://example.invalid/wporg-spy-fidelity'
);
$spy_probe_lines = is_file( MCPWP_WPORG_ATTEMPTS_FILE ) ? file( MCPWP_WPORG_ATTEMPTS_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) : array();
$spy_probe_data  = 1 === count( $spy_probe_lines ) ? json_decode( $spy_probe_lines[0], true ) : array();
$spy_probe_ok    = is_wp_error( $spy_probe )
	&& 'mcpwp_wporg_outbound_http_blocked' === $spy_probe->get_error_code()
	&& isset( $spy_probe_data['context'], $spy_probe_data['method'], $spy_probe_data['url'] )
	&& 'bootstrap' === $spy_probe_data['context']
	&& 'PATCH' === $spy_probe_data['method']
	&& 'https://example.invalid/wporg-spy-fidelity' === $spy_probe_data['url']
	&& ! empty( $spy_probe_data['caller'] );
if ( ! $spy_probe_ok ) {
	mcpwp_wporg_install_fail( 'the pre-activation outbound spy fidelity probe did not record and block the expected request.' );
}
echo "Pre-activation outbound spy fidelity probe passed.\n";
mcpwp_wporg_reset_spy();
mcpwp_wporg_mark_context( 'activation' );

$result = activate_plugin( $plugin );
if ( is_wp_error( $result ) ) {
	mcpwp_wporg_install_fail( $result->get_error_message() );
}
if ( ! is_plugin_active( $plugin ) ) {
	mcpwp_wporg_install_fail( 'the exact installed plugin did not activate.' );
}
if ( ! defined( 'MCPWP_PLUGIN_BASENAME' ) || $plugin !== MCPWP_PLUGIN_BASENAME ) {
	mcpwp_wporg_install_fail( 'the runtime plugin basename is not mumega-mcp/mumega-mcp.php.' );
}

echo 'Installed and activated frozen candidate ' . $expected_hash . ".\n";
