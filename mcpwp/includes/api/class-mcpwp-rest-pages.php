<?php
/** Pages routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_REST_Pages extends Mcpwp_REST_API {
	private $pages;
	public function __construct() { $this->pages = new Mcpwp_Pages(); }
	public function register_routes() {
		register_rest_route( $this->namespace, '/pages', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'list_pages' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create_page' ), 'permission_callback' => array( $this, 'check_permission' ) ),
		) );
		register_rest_route( $this->namespace, '/pages/(?P<id>\d+)', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_page' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => 'PUT,PATCH', 'callback' => array( $this, 'update_page' ), 'permission_callback' => array( $this, 'check_permission' ) ),
		) );
	}
	public function list_pages( $request ) { return $this->respond( $this->pages->list_pages( $request->get_params() ) ); }
	public function get_page( $request ) { return $this->respond( $this->pages->get_page( absint( $request->get_param( 'id' ) ) ) ); }
	public function create_page( $request ) { return $this->respond( $this->pages->create_page( $this->request_data( $request ) ), 201 ); }
	public function update_page( $request ) { return $this->respond( $this->pages->update_page( absint( $request->get_param( 'id' ) ), $this->request_data( $request ) ) ); }
	private function request_data( $request ) { $json = $request->get_json_params(); return is_array( $json ) ? $json : $request->get_params(); }
	private function respond( $result, $status = 200 ) { return is_wp_error( $result ) ? $this->error_response( $result->get_error_code(), $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 400 ) ) : $this->success_response( $result, $status ); }
}
