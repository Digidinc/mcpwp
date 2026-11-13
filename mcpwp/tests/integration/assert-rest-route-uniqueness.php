<?php
/**
 * Run against a booted WordPress plugin, without the PHPUnit WP doubles:
 * wp eval-file wp-content/plugins/mcpwp/tests/integration/assert-rest-route-uniqueness.php
 * Run once Free, once Pro; register tp_event/lp_course before including this
 * file to exercise those optional Pro controllers too.
 */
require_once dirname( __DIR__ ) . '/helpers/rest-route-uniqueness.php';

$routes = rest_get_server()->get_routes();
$duplicates = mcpwp_test_duplicate_rest_handlers( $routes );
if ( $duplicates ) {
	throw new RuntimeException( "Duplicate REST handlers:\n" . implode( "\n", $duplicates ) );
}
WP_CLI::success( 'No duplicate MCPWP REST route/method handlers.' );
