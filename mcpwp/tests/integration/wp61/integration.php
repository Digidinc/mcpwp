<?php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$test_number = 0;
$failures = 0;
function mcpwp_tap( $condition, $description ) {
	global $test_number, $failures;
	$test_number++;
	if ( ! $condition ) { $failures++; }
	echo ( $condition ? 'ok ' : 'not ok ' ) . $test_number . ' - ' . $description . "\n";
}
function mcpwp_request( $method, $route, $key, $body = null, $query = array() ) {
	$request = new WP_REST_Request( $method, '/mcpwp/v1' . $route );
	if ( '' !== $key ) { $request->set_header( 'X-API-Key', $key ); }
	if ( null !== $body ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}
	if ( ! empty( $query ) ) { $request->set_query_params( $query ); }
	return rest_do_request( $request );
}
function mcpwp_code( $response ) {
	$data = $response->get_data();
	return is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';
}

echo "TAP version 13\n";
echo "1..15\n";

$admin = get_user_by( 'login', 'integration-admin' );
$author_id = username_exists( 'integration-author' );
if ( ! $author_id ) { $author_id = wp_create_user( 'integration-author', 'author-password', 'author@example.test' ); }
$author = new WP_User( $author_id );
$author->set_role( 'author' );

$deleted_id = username_exists( 'integration-deleted' );
if ( ! $deleted_id ) { $deleted_id = wp_create_user( 'integration-deleted', 'deleted-password', 'deleted@example.test' ); }
wp_delete_user( $deleted_id );

$keys = array(
	array( 'id' => 'integration-admin', 'label' => 'Admin', 'hash' => wp_hash_password( 'integration-admin-key' ), 'user_id' => (int) $admin->ID, 'scopes' => array( 'read', 'write', 'admin' ), 'tool_categories' => array(), 'created_at' => current_time( 'mysql' ), 'last_used_at' => null, 'revoked_at' => null ),
	array( 'id' => 'integration-author', 'label' => 'Author', 'hash' => wp_hash_password( 'integration-author-key' ), 'user_id' => (int) $author_id, 'scopes' => array( 'read', 'write' ), 'tool_categories' => array( 'content' ), 'created_at' => current_time( 'mysql' ), 'last_used_at' => null, 'revoked_at' => null ),
	array( 'id' => 'integration-unbound', 'label' => 'Unbound', 'hash' => wp_hash_password( 'integration-unbound-key' ), 'user_id' => 0, 'scopes' => array( 'read' ), 'tool_categories' => array(), 'created_at' => current_time( 'mysql' ), 'last_used_at' => null, 'revoked_at' => null ),
	array( 'id' => 'integration-deleted', 'label' => 'Deleted', 'hash' => wp_hash_password( 'integration-deleted-key' ), 'user_id' => (int) $deleted_id, 'scopes' => array( 'read' ), 'tool_categories' => array(), 'created_at' => current_time( 'mysql' ), 'last_used_at' => null, 'revoked_at' => null ),
);
update_option( 'mcpwp_api_keys', $keys, false );
do_action( 'rest_api_init' );

mcpwp_tap( '6.1.1' === get_bloginfo( 'version' ), 'runs exact WordPress 6.1.1' );
mcpwp_tap( PHP_VERSION_ID >= 70400 && PHP_VERSION_ID < 80000, 'runs PHP 7.4' );

$site = mcpwp_request( 'GET', '/site-info', 'integration-admin-key' );
$counts = isset( $site->get_data()['counts'] ) ? $site->get_data()['counts'] : array();
mcpwp_tap( 200 === $site->get_status() && is_int( $counts['posts'] ) && is_int( $counts['pages'] ) && is_int( $counts['media'] ), 'site counts are readable integers' );

$unbound = mcpwp_request( 'GET', '/site-info', 'integration-unbound-key' );
mcpwp_tap( 500 === $unbound->get_status() && 'api_key_owner_setup_required' === mcpwp_code( $unbound ), 'unbound owner fails closed' );
$deleted = mcpwp_request( 'GET', '/site-info', 'integration-deleted-key' );
mcpwp_tap( 500 === $deleted->get_status() && 'api_key_owner_unavailable' === mcpwp_code( $deleted ), 'deleted owner fails closed' );

wp_set_current_user( $admin->ID );
$draft = mcpwp_request( 'POST', '/posts', 'integration-author-key', array( 'title' => 'Author draft', 'status' => 'draft' ) );
$draft_id = isset( $draft->get_data()['id'] ) ? (int) $draft->get_data()['id'] : 0;
mcpwp_tap( 201 === $draft->get_status() && (int) get_post_field( 'post_author', $draft_id ) === (int) $author_id, 'stored key owner replaces ambient cookie identity' );

$publish = mcpwp_request( 'POST', '/posts', 'integration-author-key', array( 'title' => 'No publish', 'status' => 'publish' ) );
mcpwp_tap( 403 === $publish->get_status() && 'insufficient_scope' === mcpwp_code( $publish ), 'write key cannot publish' );
$unknown = mcpwp_request( 'POST', '/posts', 'integration-admin-key', array( 'title' => 'Unknown', 'post_type' => 'page' ) );
mcpwp_tap( 400 === $unknown->get_status() && 'unknown_parameter' === mcpwp_code( $unknown ), 'unknown JSON keys fail before mutation' );
$unsafe = mcpwp_request( 'POST', '/posts', 'integration-admin-key', array( 'title' => 'Unsafe', 'content' => '<p onclick="alert(1)">bad</p>' ) );
mcpwp_tap( 400 === $unsafe->get_status() && 'unsafe_html' === mcpwp_code( $unsafe ), 'unsafe HTML is rejected rather than stripped' );
$override = mcpwp_request( 'GET', '/posts', 'integration-admin-key', null, array( 'post_type' => 'page' ) );
mcpwp_tap( 400 === $override->get_status() && 'unknown_parameter' === mcpwp_code( $override ), 'query cannot override internal post type' );
$wrong_type = mcpwp_request( 'GET', '/pages/' . $draft_id, 'integration-admin-key' );
mcpwp_tap( 404 === $wrong_type->get_status(), 'wrong controller object type returns 404' );

$png = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAGUlEQVQokWPkSbnDQApgIkn1qIZRDUNKAwAppAFsr7L83wAAAABJRU5ErkJggg==';
$media = mcpwp_request( 'POST', '/media/from-base64', 'integration-admin-key', array( 'data' => $png, 'filename' => 'integration.png', 'mime_type' => 'image/png' ) );
$media_ok = 201 === $media->get_status() && isset( $media->get_data()['mime_type'] ) && 'image/png' === $media->get_data()['mime_type'];
if ( ! $media_ok ) { echo '# media response: ' . wp_json_encode( array( 'status' => $media->get_status(), 'data' => $media->get_data() ) ) . "\n"; }
mcpwp_tap( $media_ok, 'strict Base64 local upload succeeds' );

$bearer = new WP_REST_Request( 'GET', '/mcpwp/v1/site-info' );
$bearer->set_header( 'Authorization', 'Bearer integration-admin-key' );
$bearer_response = rest_do_request( $bearer );
mcpwp_tap( 401 === $bearer_response->get_status() && 'missing_api_key' === mcpwp_code( $bearer_response ), 'Bearer credentials are not accepted' );

$multipart_file = wp_tempnam( 'mcpwp-http.png' );
file_put_contents( $multipart_file, base64_decode( $png, true ) );
$multipart_command = 'curl --silent --show-error --request POST --header ' . escapeshellarg( 'X-API-Key: integration-admin-key' ) . ' --form ' . escapeshellarg( 'file=@' . $multipart_file . ';filename=integration-http.png;type=image/png' ) . ' --form ' . escapeshellarg( 'title=HTTP multipart' ) . ' ' . escapeshellarg( 'http://127.0.0.1/?rest_route=/mcpwp/v1/media' );
$multipart_output = array();
$multipart_status = 0;
exec( $multipart_command, $multipart_output, $multipart_status );
wp_delete_file( $multipart_file );
$multipart_data = json_decode( implode( "\n", $multipart_output ), true );
$multipart_ok = 0 === $multipart_status && is_array( $multipart_data ) && isset( $multipart_data['mime_type'] ) && 'image/png' === $multipart_data['mime_type'];
if ( ! $multipart_ok ) { echo '# multipart response: ' . wp_json_encode( array( 'exit' => $multipart_status, 'data' => $multipart_data ) ) . "\n"; }
mcpwp_tap( $multipart_ok, 'real HTTP multipart local upload succeeds' );

$debug_log = WP_CONTENT_DIR . '/debug.log';
$debug = is_file( $debug_log ) ? file_get_contents( $debug_log ) : '';
mcpwp_tap( ! preg_match( '/PHP (Warning|Notice|Fatal|Parse error)/i', $debug ), 'debug log has no PHP warning notice or fatal' );

exit( $failures > 0 ? 1 : 0 );
