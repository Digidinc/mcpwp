<?php
/** Local media routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class Mcpwp_REST_Media extends Mcpwp_REST_API {
	private $media;
	public function __construct() {
		$this->media = new Mcpwp_Media(); }
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/media',
			array(
				$this->route_definition( WP_REST_Server::READABLE, 'list_media', 'check_list_media_permission', 'media', 'read', array( 'read', 'read_post per non-public attachment' ), false, $this->media_list_args() ),
				$this->route_definition( WP_REST_Server::CREATABLE, 'upload_media', 'check_upload_media_permission', 'media', 'write', array( 'upload_files' ), true, $this->multipart_media_args() ),
			)
		);
		register_rest_route( $this->namespace, '/media/from-base64', $this->route_definition( WP_REST_Server::CREATABLE, 'upload_from_base64', 'check_upload_media_permission', 'media', 'write', array( 'upload_files' ), true, $this->base64_media_args() ) );
		register_rest_route( $this->namespace, '/media/(?P<id>\d+)', $this->route_definition( WP_REST_Server::READABLE, 'get_media', 'check_get_media_permission', 'media', 'read', array( 'read_post:{id}', 'post type must be attachment' ), false, $this->id_route_args() ) );
	}
	public function list_media( $request ) {
		$params = $this->validated_query_params( $request, $this->media_list_args() );
		return is_wp_error( $params ) ? $this->validation_error_response( $params ) : $this->respond( $this->media->list_media( $params ) ); }
	public function get_media( $request ) {
		$params = $this->validated_query_params( $request, $this->id_route_args() );
		if ( is_wp_error( $params ) ) {
			return $this->validation_error_response( $params ); }
		$post = get_post( $params['id'] );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return $this->error_response( 'not_found', __( 'Media attachment not found.', 'mumega-mcp' ), 404 ); }
		return $this->success_response(
			array(
				'id'          => (int) $post->ID,
				'title'       => $post->post_title,
				'caption'     => $post->post_excerpt,
				'description' => $post->post_content,
				'url'         => wp_get_attachment_url( $post->ID ),
				'mime_type'   => $post->post_mime_type,
			)
		);
	}
	public function upload_media( $request ) {
		$files         = $request->get_file_params();
		$unknown_files = array_diff( array_keys( $files ), array( 'file' ) );
		if ( ! empty( $unknown_files ) ) {
			return $this->error_response( 'unknown_parameter', __( 'The upload contains an unknown file part.', 'mumega-mcp' ), 400 ); }
		if ( empty( $files['file'] ) ) {
			return $this->error_response( 'missing_file', __( 'A local file upload is required.', 'mumega-mcp' ), 400 ); }
		$params = $this->validated_form_params( $request, $this->media_text_args() );
		if ( is_wp_error( $params ) ) {
			return $this->validation_error_response( $params ); }
		return $this->respond( $this->media->upload_file( $files['file'], $params ), 201 );
	}
	public function upload_from_base64( $request ) {
		$params = $this->validated_json_body( $request, $this->base64_media_args(), false, 15 * 1024 * 1024 );
		if ( is_wp_error( $params ) ) {
			return $this->validation_error_response( $params ); }
		return $this->respond( $this->media->upload_from_base64( $params['data'], $params['filename'], $params ), 201 );
	}
	private function respond( $result, $status = 200 ) {
		return is_wp_error( $result ) ? $this->error_response( $result->get_error_code(), $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 400 ) ) : $this->success_response( $result, $status ); }
}
