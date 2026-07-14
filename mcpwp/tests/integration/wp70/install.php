<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
define( 'WP_INSTALLING', true );
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

if ( ! is_blog_installed() ) {
	wp_install( 'MCPWP WordPress 7.0', 'integration-admin', 'admin@example.test', true, '', 'integration-password' );
}

$admin = get_user_by( 'login', 'integration-admin' );
if ( ! $admin ) {
	fwrite( STDERR, "Could not resolve integration administrator.\n" );
	exit( 1 );
}
wp_set_current_user( $admin->ID );
$result = activate_plugin( 'mumega-mcp/mcpwp.php' );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, $result->get_error_message() . "\n" );
	exit( 1 );
}
echo "WordPress installed and Mumega MCP activated.\n";
