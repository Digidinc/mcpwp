<?php
/** Scoped API-key routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_REST_API_Keys extends Mcpwp_REST_API {
	public function register_routes() {
		register_rest_route( $this->namespace, '/api-keys', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'list_api_keys' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'create_api_key' ), 'permission_callback' => array( $this, 'check_permission' ) ),
		) );
		register_rest_route( $this->namespace, '/api-keys/(?P<id>[a-z0-9-]+)', array( 'methods' => WP_REST_Server::DELETABLE, 'callback' => array( $this, 'revoke_api_key' ), 'permission_callback' => array( $this, 'check_permission' ) ) );
	}
	public function list_api_keys( $request ) {
		if ( ! $this->can_manage() ) { return $this->error_response( 'forbidden', __( 'You do not have permission to manage API keys.', 'mumega-mcp' ), 403 ); }
		$keys = $this->list_scoped_api_keys( (bool) $request->get_param( 'include_revoked' ) );
		return $this->success_response( array( 'keys' => $keys, 'total' => count( $keys ) ) );
	}
	public function create_api_key( $request ) {
		if ( ! $this->can_manage() ) { return $this->error_response( 'forbidden', __( 'You do not have permission to manage API keys.', 'mumega-mcp' ), 403 ); }
		$key = $this->create_scoped_api_key( (string) $request->get_param( 'label' ), (array) $request->get_param( 'scopes' ), (string) $request->get_param( 'role' ) ?: 'admin', (array) $request->get_param( 'tool_categories' ) );
		return $this->success_response( array( 'api_key' => $key ), 201 );
	}
	public function revoke_api_key( $request ) {
		if ( ! $this->can_manage() ) { return $this->error_response( 'forbidden', __( 'You do not have permission to manage API keys.', 'mumega-mcp' ), 403 ); }
		$id = sanitize_key( (string) $request->get_param( 'id' ) );
		return $this->revoke_scoped_api_key( $id ) ? $this->success_response( array( 'revoked' => true, 'id' => $id ) ) : $this->error_response( 'not_found', __( 'API key not found or already revoked.', 'mumega-mcp' ), 404 );
	}
	private function can_manage() { return function_exists( 'current_user_can' ) && current_user_can( 'mcpwp_manage_settings' ); }
}
