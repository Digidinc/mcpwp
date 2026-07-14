<?php
/**
 * Page REST routes.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Expose page operations through authenticated REST routes.
 */
class Mcpwp_REST_Pages extends Mcpwp_REST_API {
	/**
	 * Local page service.
	 *
	 * @var Mcpwp_Pages
	 */
	private $pages;
	/** Initialize the local page service. */
	public function __construct() {
		$this->pages = new Mcpwp_Pages(); }
	/**
	 * Register retained page REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/pages',
			array(
				$this->route_definition( WP_REST_Server::READABLE, 'list_pages', 'check_list_pages_permission', 'content', 'read', array( 'read', 'edit_pages/read_private_pages for non-public visibility' ), false, $this->content_list_args( true ) ),
				$this->route_definition( WP_REST_Server::CREATABLE, 'create_page', 'check_create_page_permission', 'content', 'write', array( 'edit_pages', 'publish_pages for publish/private' ), true, $this->page_body_args() ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/pages/(?P<id>\d+)',
			array(
				$this->route_definition( WP_REST_Server::READABLE, 'get_page', 'check_get_page_permission', 'content', 'read', array( 'read_post:{id}', 'post type must be page' ), false, $this->id_route_args() ),
				$this->route_definition( 'PUT,PATCH', 'update_page', 'check_update_page_permission', 'content', 'write/dynamic-admin', array( 'edit_post:{id}', 'publish_pages for publish/private', 'post type must be page' ), true, $this->page_body_args( true ) ),
			)
		);
	}
	/**
	 * Return pages visible to the authenticated key owner.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Page collection response.
	 */
	public function list_pages( $request ) {
		$params = $this->validated_query_params( $request, $this->content_list_args( true ) );
		return is_wp_error( $params ) ? $this->validation_error_response( $params ) : $this->respond( $this->pages->list_pages( $params ) ); }
	/**
	 * Return one visible page.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Page response.
	 */
	public function get_page( $request ) {
		$params = $this->validated_query_params( $request, $this->id_route_args() );
		return is_wp_error( $params ) ? $this->validation_error_response( $params ) : $this->respond( $this->pages->get_page( $params['id'] ) ); }
	/**
	 * Validate input and create a page.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Creation response.
	 */
	public function create_page( $request ) {
		$data = $this->validated_json_body( $request, $this->page_body_args() );
		if ( is_wp_error( $data ) ) {
			return $this->validation_error_response( $data ); }
		$relationships = $this->validate_content_relationships( $data, 0, true );
		return is_wp_error( $relationships ) ? $this->validation_error_response( $relationships ) : $this->respond( $this->pages->create_page( $data ), 201 );
	}
	/**
	 * Validate input and update a page.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Update response.
	 */
	public function update_page( $request ) {
		$schema = $this->page_body_args( true );
		unset( $schema['id'] );
		$data = $this->validated_json_body( $request, $schema, true );
		if ( is_wp_error( $data ) ) {
			return $this->validation_error_response( $data ); }
		$id            = absint( $request->get_param( 'id' ) );
		$relationships = $this->validate_content_relationships( $data, $id, true );
		return is_wp_error( $relationships ) ? $this->validation_error_response( $relationships ) : $this->respond( $this->pages->update_page( $id, $data ) );
	}
	/**
	 * Convert a page-service result to a REST response.
	 *
	 * @param mixed $result Page-service result.
	 * @param int   $status Success status code.
	 * @return WP_REST_Response REST response.
	 */
	private function respond( $result, $status = 200 ) {
		return is_wp_error( $result ) ? $this->error_response( $result->get_error_code(), $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 400 ) ) : $this->success_response( $result, $status ); }
}
