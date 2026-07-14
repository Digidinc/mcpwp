<?php
/** Posts and drafts routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_REST_Posts extends Mcpwp_REST_API {
	private $posts;
	private $drafts;
	public function __construct() { $this->posts = new Mcpwp_Posts(); $this->drafts = new Mcpwp_Drafts(); }

	public function register_routes() {
		register_rest_route( $this->namespace, '/posts', array(
			$this->route_definition( WP_REST_Server::READABLE, 'list_posts', 'check_list_posts_permission', 'content', 'read', array( 'read', 'edit_posts/read_private_posts for non-public visibility' ), false, $this->content_list_args() ),
			$this->route_definition( WP_REST_Server::CREATABLE, 'create_post', 'check_create_post_permission', 'content', 'write', array( 'edit_posts', 'publish_posts for publish/private', 'assign_terms when terms supplied' ), true, $this->post_body_args() ),
		) );
		register_rest_route( $this->namespace, '/posts/(?P<id>\d+)', array(
			$this->route_definition( WP_REST_Server::READABLE, 'get_post', 'check_get_post_permission', 'content', 'read', array( 'read_post:{id}', 'post type must be post' ), false, $this->id_route_args() ),
			$this->route_definition( 'PUT,PATCH', 'update_post', 'check_update_post_permission', 'content', 'write/dynamic-admin', array( 'edit_post:{id}', 'publish_posts for publish/private', 'assign_terms when terms supplied', 'post type must be post' ), true, $this->post_body_args( true ) ),
		) );
		register_rest_route( $this->namespace, '/drafts', $this->route_definition( WP_REST_Server::READABLE, 'list_drafts', 'check_list_drafts_permission', 'content', 'read', array( 'read', 'edit_posts and/or edit_pages', 'own drafts without edit_others capability' ), false, $this->draft_list_args() ) );
		register_rest_route( $this->namespace, '/drafts/(?P<id>\d+)', $this->route_definition( WP_REST_Server::DELETABLE, 'delete_draft', 'check_delete_draft_permission', 'content', 'admin', array( 'delete_post:{id}', 'post/page status must be draft' ), true, $this->id_route_args() ) );
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
