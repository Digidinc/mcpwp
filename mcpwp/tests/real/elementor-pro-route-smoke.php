<?php

rest_get_server();
if ( ! class_exists( 'Mcpwp_REST_Elementor_Pro' ) || ! did_action( 'elementor/loaded' ) || ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	throw new RuntimeException( 'MCPWP Pro route and Elementor Pro must be loaded.' );
}

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
$source_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Pro route source' ), true );
if ( is_wp_error( $source_id ) ) {
	throw new RuntimeException( $source_id->get_error_message() );
}
$tree = array(
	array(
		'id' => 'sec1083',
		'elType' => 'section',
		'settings' => array(),
		'elements' => array(
			array(
				'id' => 'col1083',
				'elType' => 'column',
				'settings' => array( '_column_size' => 100 ),
				'elements' => array(
					array(
						'id' => 'html183',
						'elType' => 'widget',
						'widgetType' => 'html',
						'settings' => array( 'html' => '<script>window.__route1083=1;</script>' ),
						'elements' => array(),
					),
				),
			),
		),
	),
);
update_post_meta( $source_id, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
update_post_meta( $source_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $source_id, '_elementor_page_settings', array( 'hide_title' => 'yes' ) );

$controller = new Mcpwp_REST_Elementor_Pro( new Mcpwp_Elementor_Pro() );
$record = $controller->create_scoped_api_key( 'writegraph-smoke', array( 'read', 'write', 'admin' ), 'admin' );
if ( is_wp_error( $record ) || empty( $record['key'] ) ) {
	throw new RuntimeException( 'Could not prepare a scoped local test key.' );
}
$key = $record['key'];
wp_set_current_user( 0 );

$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/clone' );
$request->set_header( 'X-API-Key', $key );
$request->set_param( 'source_id', $source_id );
$request->set_param( 'title', 'Pro route copy' );
$response = rest_do_request( $request );
if ( is_wp_error( $response ) || $response->get_status() >= 400 ) {
	throw new RuntimeException( 'Authenticated Pro clone route refused: ' . ( is_wp_error( $response ) ? $response->get_error_code() : $response->get_status() ) );
}
$copy_id = (int) ( $response->get_data()['id'] ?? 0 );
if ( ! $copy_id ) {
	throw new RuntimeException( 'Pro clone route returned no copy ID.' );
}
$copy_tree = json_decode( get_post_meta( $copy_id, '_elementor_data', true ), true );
$copy_html = $copy_tree[0]['elements'][0]['elements'][0]['settings']['html'] ?? null;
if ( ! is_string( $copy_html ) || false !== strpos( $copy_html, '<script' ) ) {
	throw new RuntimeException( 'Authenticated Pro clone route stored active HTML.' );
}
if ( array( 'hide_title' => 'yes' ) !== get_post_meta( $copy_id, '_elementor_page_settings', true ) ) {
	throw new RuntimeException( 'Authenticated Pro clone route lost page settings.' );
}
if ( false === strpos( get_post_meta( $source_id, '_elementor_data', true ), '<script' ) ) {
	throw new RuntimeException( 'Authenticated Pro clone route altered the source.' );
}

echo wp_json_encode( array( 'pro_route_authenticated' => true, 'clone_sanitized' => true, 'settings_copied' => true, 'source_preserved' => true ) ) . PHP_EOL;
