<?php

rest_get_server();
if ( ! class_exists( 'Mcpwp_REST_Elementor_Pro' ) || ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	throw new RuntimeException( 'MCPWP Pro and Elementor Pro must be loaded.' );
}

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
$controller = new Mcpwp_REST_Elementor_Pro( new Mcpwp_Elementor_Pro() );
$record = $controller->create_scoped_api_key( 'custom-code-smoke', array( 'read', 'write', 'admin' ), 'admin' );
if ( is_wp_error( $record ) || empty( $record['key'] ) ) {
	throw new RuntimeException( 'Could not prepare a scoped local test key.' );
}
wp_set_current_user( 0 );

$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code' );
$request->set_header( 'X-API-Key', $record['key'] );
$request->set_param( 'title', 'Writegraph Custom Code' );
$request->set_param( 'code', '<script>window.__custom1083=1;</script>' );
$request->set_param( 'location', 'head' );
$request->set_param( 'status', 'publish' );
$request->set_param( 'conditions', array( array( 'type' => 'include', 'name' => 'general' ) ) );
$response = rest_do_request( $request );
if ( is_wp_error( $response ) || $response->get_status() >= 400 ) {
	throw new RuntimeException( 'Authenticated Custom Code route refused: ' . ( is_wp_error( $response ) ? $response->get_error_code() : ( $response->get_data()['code'] ?? $response->get_status() ) ) );
}
$post_id = (int) ( $response->get_data()['id'] ?? 0 );
if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
	throw new RuntimeException( 'Custom Code was not published.' );
}
if ( '<script>window.__custom1083=1;</script>' !== get_post_meta( $post_id, '_elementor_code', true ) ) {
	throw new RuntimeException( 'Custom Code did not persist exact content.' );
}
$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
if ( ! is_array( $cache ) || ! isset( $cache['elementor_head'][ $post_id ] ) ) {
	throw new RuntimeException( 'Elementor Pro conditions cache omitted the snippet.' );
}

echo wp_json_encode( array( 'post_id' => $post_id, 'authenticated' => true, 'code_persisted' => true, 'conditions_cached' => true ) ) . PHP_EOL;
