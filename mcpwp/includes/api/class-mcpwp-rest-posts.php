<?php
/** Posts and drafts routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_REST_Posts extends Mcpwp_REST_API {
	private $posts;
	private $drafts;
	public function __construct() { $this->posts = new Mcpwp_Posts(); $this->drafts = new Mcpwp_Drafts(); }

	public function register_routes() {
		register_rest_route( $this->namespace, '/posts', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'list_posts' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create_post' ), 'permission_callback' => array( $this, 'check_permission' ) ),
		) );
		register_rest_route( $this->namespace, '/posts/(?P<id>\d+)', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_post' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => 'PUT,PATCH', 'callback' => array( $this, 'update_post' ), 'permission_callback' => array( $this, 'check_permission' ) ),
		) );
		register_rest_route( $this->namespace, '/drafts', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'list_drafts' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
		register_rest_route( $this->namespace, '/drafts/(?P<id>\d+)', array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( $this, 'delete_draft' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
	}

	public function list_posts( $request ) { return $this->respond( $this->posts->list_posts( $request->get_params() ) ); }
	public function get_post( $request ) { return $this->respond( $this->posts->get_post( absint( $request->get_param( 'id' ) ) ) ); }
	public function create_post( $request ) { return $this->respond( $this->posts->create_post( $this->request_data( $request ) ), 201 ); }
	public function update_post( $request ) { return $this->respond( $this->posts->update_post( absint( $request->get_param( 'id' ) ), $this->request_data( $request ) ) ); }
	public function list_drafts( $request ) { return $this->respond( $this->drafts->list_drafts( $request->get_params() ) ); }
	public function delete_draft( $request ) { return $this->respond( $this->drafts->delete_draft( absint( $request->get_param( 'id' ) ) ) ); }

	private function request_data( $request ) { $json = $request->get_json_params(); return is_array( $json ) ? $json : $request->get_params(); }
	private function respond( $result, $status = 200 ) { return is_wp_error( $result ) ? $this->error_response( $result->get_error_code(), $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 400 ) ) : $this->success_response( $result, $status ); }
}
