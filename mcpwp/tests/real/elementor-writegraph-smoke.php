<?php

require_once MCPWP_PLUGIN_DIR . 'includes/pro/core/class-mcpwp-elementor-pro.php';

if ( ! did_action( 'elementor/loaded' ) || ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	throw new RuntimeException( 'Elementor and Elementor Pro must be active.' );
}

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );

$tree = array(
	array(
		'id'       => 'sec1083',
		'elType'   => 'section',
		'settings' => array(),
		'elements' => array(
			array(
				'id'       => 'col1083',
				'elType'   => 'column',
				'settings' => array( '_column_size' => 100 ),
				'elements' => array(
					array(
						'id'         => 'html183',
						'elType'     => 'widget',
						'widgetType' => 'html',
						'settings'   => array( 'html' => '<script>window.__clone1083=1;</script>' ),
						'elements'   => array(),
					),
					array(
						'id'         => 'head183',
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array( 'title' => 'Original heading' ),
						'elements'   => array(),
					),
				),
			),
		),
	),
);

$source_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Writegraph source' ), true );
if ( is_wp_error( $source_id ) ) {
	throw new RuntimeException( $source_id->get_error_message() );
}
update_post_meta( $source_id, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
update_post_meta( $source_id, '_elementor_edit_mode', 'builder' );
update_post_meta( $source_id, '_elementor_page_settings', array( 'hide_title' => 'yes' ) );
update_post_meta( $source_id, '_elementor_element_cache', array( 'html183' => '<script>stale</script>' ) );

wp_set_current_user( 0 );
$edited_tree = $tree;
$edited_tree[0]['elements'][0]['elements'][1]['settings']['title'] = 'Updated heading';
$edited = ( new Mcpwp_Elementor_Basic() )->set_elementor_data( $source_id, array( 'elementor_data' => wp_json_encode( $edited_tree ) ) );
if ( is_wp_error( $edited ) ) {
	throw new RuntimeException( 'Same-post edit failed: ' . $edited->get_error_message() );
}
$source_tree = json_decode( get_post_meta( $source_id, '_elementor_data', true ), true );
if ( '<script>window.__clone1083=1;</script>' !== ( $source_tree[0]['elements'][0]['elements'][0]['settings']['html'] ?? null ) ) {
	throw new RuntimeException( 'Same-post edit changed untouched HTML.' );
}
$pro   = new Mcpwp_Elementor_Pro();
$clone = $pro->clone_page( $source_id, array( 'title' => 'Writegraph copy' ) );
if ( is_wp_error( $clone ) || ! is_array( $clone ) ) {
	throw new RuntimeException( 'Clone failed: ' . ( is_wp_error( $clone ) ? $clone->get_error_message() : gettype( $clone ) ) );
}
$copy_id = (int) $clone['id'];
$copy_raw = get_post_meta( $copy_id, '_elementor_data', true );
$copy_tree = json_decode( $copy_raw, true );
$copy_html = $copy_tree[0]['elements'][0]['elements'][0]['settings']['html'] ?? null;
if ( ! is_string( $copy_html ) || false !== strpos( $copy_html, '<script' ) ) {
	throw new RuntimeException( 'Clone introduced raw script.' );
}
if ( false === strpos( get_post_meta( $source_id, '_elementor_data', true ), '<script' ) ) {
	throw new RuntimeException( 'Clone changed the source.' );
}
if ( '' !== get_post_meta( $copy_id, '_elementor_element_cache', true ) ) {
	throw new RuntimeException( 'Clone reused the source render cache.' );
}
if ( array( 'hide_title' => 'yes' ) !== get_post_meta( $copy_id, '_elementor_page_settings', true ) ) {
	throw new RuntimeException( 'Clone did not persist page settings.' );
}

$blocked_id = 0;
$refuse_tree = static function ( $check, $post_id, $key ) use ( &$blocked_id ) {
	if ( '_elementor_data' === $key ) {
		$blocked_id = (int) $post_id;
		return false;
	}
	return $check;
};
add_filter( 'add_post_metadata', $refuse_tree, PHP_INT_MAX - 1, 5 );
$refused = $pro->clone_page( $source_id, array( 'title' => 'Writegraph refused copy' ) );
remove_filter( 'add_post_metadata', $refuse_tree, PHP_INT_MAX - 1 );
if ( ! is_wp_error( $refused ) || ! $blocked_id || get_post( $blocked_id ) ) {
	throw new RuntimeException( 'Failed clone metadata write was reported as success or left a post.' );
}

echo wp_json_encode( array( 'untouched_html_preserved' => true, 'clone_sanitized' => true, 'source_preserved' => true, 'cache_not_copied' => true, 'settings_copied' => true, 'failed_clone_cleaned' => true ) ) . PHP_EOL;
