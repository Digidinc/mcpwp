<?php
/** Local media routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_REST_Media extends Mcpwp_REST_API {
	private $media;
	public function __construct() { $this->media = new Mcpwp_Media(); }
	public function register_routes() {
		register_rest_route( $this->namespace, '/media', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'list_media' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'upload_media' ), 'permission_callback' => array( $this, 'check_permission' ) ),
		) );
		register_rest_route( $this->namespace, '/media/from-base64', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'upload_from_base64' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
		register_rest_route( $this->namespace, '/media/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_media' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
	}
	public function list_media( $request ) { return $this->respond( $this->media->list_media( $request->get_params() ) ); }
	public function get_media( $request ) {
		$post = get_post( absint( $request->get_param( 'id' ) ) );
		if ( ! $post || 'attachment' !== $post->post_type ) { return $this->error_response( 'not_found', __( 'Media attachment not found.', 'mumega-mcp' ), 404 ); }
		return $this->success_response( array( 'id' => (int) $post->ID, 'title' => $post->post_title, 'caption' => $post->post_excerpt, 'description' => $post->post_content, 'url' => wp_get_attachment_url( $post->ID ), 'mime_type' => $post->post_mime_type ) );
	}
	public function upload_media( $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) { return $this->error_response( 'missing_file', __( 'A local file upload is required.', 'mumega-mcp' ), 400 ); }
		return $this->respond( $this->media->upload_file( $files['file'], $request->get_params() ), 201 );
	}
	public function upload_from_base64( $request ) {
		$params = array_merge( $request->get_params(), (array) $request->get_json_params() );
		if ( empty( $params['data'] ) || empty( $params['filename'] ) ) { return $this->error_response( 'missing_data', __( 'Base64 data and a file name are required.', 'mumega-mcp' ), 400 ); }
		return $this->respond( $this->media->upload_from_base64( (string) $params['data'], (string) $params['filename'], $params ), 201 );
	}
	private function respond( $result, $status = 200 ) { return is_wp_error( $result ) ? $this->error_response( $result->get_error_code(), $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 400 ) ) : $this->success_response( $result, $status ); }
}
