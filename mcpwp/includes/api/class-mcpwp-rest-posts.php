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

	public function list_posts( $request ) { $params = $this->validated_query_params( $request, $this->content_list_args() ); return is_wp_error( $params ) ? $this->validation_error_response( $params ) : $this->respond( $this->posts->list_posts( $params ) ); }
	public function get_post( $request ) { $params = $this->validated_query_params( $request, $this->id_route_args() ); return is_wp_error( $params ) ? $this->validation_error_response( $params ) : $this->respond( $this->posts->get_post( $params['id'] ) ); }
	public function create_post( $request ) {
		$data = $this->validated_json_body( $request, $this->post_body_args() );
		if ( is_wp_error( $data ) ) { return $this->validation_error_response( $data ); }
		$relationships = $this->validate_content_relationships( $data );
		return is_wp_error( $relationships ) ? $this->validation_error_response( $relationships ) : $this->respond( $this->posts->create_post( $data ), 201 );
	}
	public function update_post( $request ) {
		$schema = $this->post_body_args( true ); unset( $schema['id'] );
		$data = $this->validated_json_body( $request, $schema, true );
		if ( is_wp_error( $data ) ) { return $this->validation_error_response( $data ); }
		$id = absint( $request->get_param( 'id' ) ); $relationships = $this->validate_content_relationships( $data, $id );
		return is_wp_error( $relationships ) ? $this->validation_error_response( $relationships ) : $this->respond( $this->posts->update_post( $id, $data ) );
	}
	public function list_drafts( $request ) { $params = $this->validated_query_params( $request, $this->draft_list_args() ); return is_wp_error( $params ) ? $this->validation_error_response( $params ) : $this->respond( $this->drafts->list_drafts( $params ) ); }
	public function delete_draft( $request ) { $params = $this->validated_query_params( $request, $this->id_route_args() ); return is_wp_error( $params ) ? $this->validation_error_response( $params ) : $this->respond( $this->drafts->delete_draft( $params['id'] ) ); }

	private function respond( $result, $status = 200 ) { return is_wp_error( $result ) ? $this->error_response( $result->get_error_code(), $result->get_error_message(), (int) ( $result->get_error_data()['status'] ?? 400 ) ) : $this->success_response( $result, $status ); }
}
