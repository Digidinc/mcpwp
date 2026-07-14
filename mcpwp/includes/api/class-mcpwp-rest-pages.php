<?php
/** Pages routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_REST_Pages extends Mcpwp_REST_API {
	private $pages;
	public function __construct() { $this->pages = new Mcpwp_Pages(); }
	public function register_routes() {
		register_rest_route( $this->namespace, '/pages', array(
			$this->route_definition( WP_REST_Server::READABLE, 'list_pages', 'check_list_pages_permission', 'content', 'read', array( 'read', 'edit_pages/read_private_pages for non-public visibility' ), false, $this->content_list_args( true ) ),
			$this->route_definition( WP_REST_Server::CREATABLE, 'create_page', 'check_create_page_permission', 'content', 'write', array( 'edit_pages', 'publish_pages for publish/private' ), true, $this->page_body_args() ),
		) );
		register_rest_route( $this->namespace, '/pages/(?P<id>\d+)', array(
			$this->route_definition( WP_REST_Server::READABLE, 'get_page', 'check_get_page_permission', 'content', 'read', array( 'read_post:{id}', 'post type must be page' ), false, $this->id_route_args() ),
			$this->route_definition( 'PUT,PATCH', 'update_page', 'check_update_page_permission', 'content', 'write/dynamic-admin', array( 'edit_post:{id}', 'publish_pages for publish/private', 'post type must be page' ), true, $this->page_body_args( true ) ),
		) );
	}
	public function list_pages( $request ) { return $this->respond( $this->pages->list_pages( $request->get_params() ) ); }
	public function get_page( $request ) { return $this->respond( $this->pages->get_page( absint( $request->get_param( 'id' ) ) ) ); }
	public function create_page( $request ) { return $this->respond( $this->pages->create_page( $this->request_data( $request ) ), 201 ); }
	public function update_page( $request ) { return $this->respond( $this->pages->update_page( absint( $request->get_param( 'id' ) ), $this->request_data( $request ) ) ); }
	private function request_data( $request ) { $json = $request->get_json_params(); return is_array( $json ) ? $json : $request->get_params(); }
	private function respond( $result, $status = 200 ) { return is_wp_error( $result ) ? $this->error_response( $result->get_error_code(), $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 400 ) ) : $this->success_response( $result, $status ); }
}
