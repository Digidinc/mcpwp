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
// #1104: core matches in registration order, so a route an earlier pattern
// already matches is unreachable. Checked on the real table, in the real
// loader order, which the unit harness only approximates. The sweep is a
// sampling heuristic, not exhaustive — see mcpwp_test_shadowed_rest_routes().
$shadowed = array();
foreach ( mcpwp_test_shadowed_rest_routes( $routes ) as $key => $winner ) {
	$shadowed[] = "$key -> $winner";
}
if ( $shadowed ) {
	throw new RuntimeException( "Unreachable REST routes (shadowed by an earlier registration):\n" . implode( "\n", $shadowed ) );
}
WP_CLI::success( 'No duplicate or shadowed MCPWP REST route/method handlers.' );
