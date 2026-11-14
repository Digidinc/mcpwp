<?php
/**
 * Router for WellKnownServedBytesTest, run by PHP's built-in web server.
 *
 * Boots the same stubs as the unit suite, seeds options from the file named in
 * MCPWP_WK_OPTIONS (a JSON object), then calls the real dispatcher with the
 * real REQUEST_URI. The dispatcher sends status, headers and body itself and
 * exit()s; a request it does not answer gets a plain 404 "not-served".
 *
 * @package MCPWP
 */

require dirname( __DIR__ ) . '/bootstrap.php';

$mcpwp_wk_options = json_decode( (string) file_get_contents( (string) getenv( 'MCPWP_WK_OPTIONS' ) ), true );
$GLOBALS['mcpwp_test_options'] = is_array( $mcpwp_wk_options ) ? $mcpwp_wk_options : array();

Mcpwp_OAuth_Well_Known::maybe_serve_well_known( true, null );

http_response_code( 404 );
header( 'Content-Type: text/plain' );
echo 'not-served';
