<?php

rest_get_server();
if ( ! class_exists( 'Mcpwp_REST_Theme_Builder' ) || ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	throw new RuntimeException( 'MCPWP Theme Builder and Elementor Pro must be loaded.' );
}

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
$controller = new Mcpwp_REST_Theme_Builder( new Mcpwp_Theme_Builder() );
$record = $controller->create_scoped_api_key( 'theme-builder-smoke', array( 'read', 'write', 'admin' ), 'admin' );
if ( is_wp_error( $record ) || empty( $record['key'] ) ) {
	throw new RuntimeException( 'Could not prepare a scoped local test key.' );
}
wp_set_current_user( 0 );

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
						'id' => 'head183',
						'elType' => 'widget',
						'widgetType' => 'heading',
						'settings' => array( 'title' => 'GraphHeader1083' ),
						'elements' => array(),
					),
				),
			),
		),
	),
);

$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates' );
$request->set_header( 'X-API-Key', $record['key'] );
$request->set_param( 'title', 'Writegraph Header' );
$request->set_param( 'type', 'header' );
$request->set_param( 'scope', 'entire_site' );
$request->set_param( 'elementor_data', $tree );
$response = rest_do_request( $request );
if ( is_wp_error( $response ) || $response->get_status() >= 400 ) {
	throw new RuntimeException( 'Authenticated Theme Builder route refused: ' . ( is_wp_error( $response ) ? $response->get_error_code() : ( $response->get_data()['code'] ?? $response->get_status() ) ) );
}
$template = $response->get_data();
$post_id = (int) ( $template['id'] ?? 0 );
if ( ! $post_id || 'publish' !== get_post_status( $post_id ) ) {
	throw new RuntimeException( 'Theme Builder template was not published.' );
}
$stored = get_post_meta( $post_id, '_elementor_data', true );
if ( ! is_string( $stored ) || false === strpos( $stored, 'GraphHeader1083' ) ) {
	throw new RuntimeException( 'Theme Builder tree was not persisted.' );
}
$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
$cache_has_template = is_array( $cache ) && isset( $cache['header'][ $post_id ] );

echo wp_json_encode( array( 'post_id' => $post_id, 'authenticated' => true, 'tree_persisted' => true, 'assigned' => (bool) ( $template['assigned'] ?? false ), 'conditions_cached' => $cache_has_template ) ) . PHP_EOL;
