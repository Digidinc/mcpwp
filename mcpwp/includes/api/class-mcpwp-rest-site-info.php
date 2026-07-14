<?php
/** Local site information. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class Mcpwp_REST_Site_Info extends Mcpwp_REST_API {
	public function register_routes() {
		register_rest_route( $this->namespace, '/site-info', $this->route_definition( WP_REST_Server::READABLE, 'get_site_info', 'check_site_info_permission', 'site', 'read', array( 'read' ), false ) );
	}

	public function get_site_info( $request ) {
		global $wp_version;
		return $this->success_response(
			array(
				'name'         => get_bloginfo( 'name' ),
				'url'          => get_site_url(),
				'language'     => get_bloginfo( 'language' ),
				'timezone'     => wp_timezone_string(),
				'wordpress'    => $wp_version,
				'plugin'       => array(
					'name'    => 'Mumega MCP',
					'version' => MCPWP_VERSION,
				),
				'counts'       => array(
					'posts' => $this->readable_count( 'post' ),
					'pages' => $this->readable_count( 'page' ),
					'media' => $this->readable_count( 'attachment' ),
				),
				'capabilities' => array(
					'posts'       => true,
					'pages'       => true,
					'drafts'      => true,
					'local_media' => true,
					'taxonomy'    => true,
				),
			)
		);
	}
	private function readable_count( $post_type ) {
		$counts = wp_count_posts( $post_type, 'readable' );
		return is_object( $counts ) ? array_sum( array_map( 'intval', get_object_vars( $counts ) ) ) : 0;
	}
}
