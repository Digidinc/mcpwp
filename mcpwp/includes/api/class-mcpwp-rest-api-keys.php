<?php
/**
 * Scoped API-key REST routes.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Expose scoped API-key management through authenticated REST routes.
 */
class Mcpwp_REST_API_Keys extends Mcpwp_REST_API {
	/**
	 * Register retained scoped API-key routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/api-keys',
			array(
				$this->route_definition(
					WP_REST_Server::READABLE,
					'list_api_keys',
					'check_admin_permission',
					'site',
					'admin',
					array( 'mcpwp_manage_settings' ),
					false,
					array(
						'include_revoked' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					)
				),
				$this->route_definition( WP_REST_Server::CREATABLE, 'create_api_key', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), true, $this->api_key_create_args() ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/api-keys/(?P<id>[a-z0-9-]+)',
			$this->route_definition(
				WP_REST_Server::DELETABLE,
				'revoke_api_key',
				'check_admin_permission',
				'site',
				'admin',
				array( 'mcpwp_manage_settings' ),
				true,
				array(
					'id' => $this->string_arg(
						array(
							'minLength' => 1,
							'maxLength' => 64,
							'required'  => true,
							'pattern'   => '^[a-z0-9-]+$',
						)
					),
				)
			)
		);
	}
	/**
	 * Return sanitized scoped API-key records.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response API-key collection response.
	 */
	public function list_api_keys( $request ) {
		if ( ! $this->can_manage() ) {
			return $this->error_response( 'forbidden', __( 'You do not have permission to manage API keys.', 'mumega-mcp' ), 403 ); }
		$params = $this->validated_query_params(
			$request,
			array(
				'include_revoked' => array(
					'type'    => 'boolean',
					'default' => false,
				),
			)
		);
		if ( is_wp_error( $params ) ) {
			return $this->validation_error_response( $params ); }
		$keys = $this->list_scoped_api_keys( isset( $params['include_revoked'] ) ? $params['include_revoked'] : false );
		return $this->success_response(
			array(
				'keys'  => $keys,
				'total' => count( $keys ),
			)
		);
	}
	/**
	 * Validate input and create a scoped API key.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Creation response containing the one-time key.
	 */
	public function create_api_key( $request ) {
		if ( ! $this->can_manage() ) {
			return $this->error_response( 'forbidden', __( 'You do not have permission to manage API keys.', 'mumega-mcp' ), 403 ); }
		$params = $this->validated_json_body( $request, $this->api_key_create_args() );
		if ( is_wp_error( $params ) ) {
			return $this->validation_error_response( $params ); }
		$record = $this->get_current_api_key_record();
		$key    = $this->create_scoped_api_key( $params['label'], $params['scopes'], isset( $params['tool_categories'] ) ? $params['tool_categories'] : array(), (int) ( $record['user_id'] ?? 0 ) );
		if ( is_wp_error( $key ) ) {
			return $this->error_response( $key->get_error_code(), $key->get_error_message(), (int) ( $key->get_error_data()['status'] ?? 400 ) ); }
		return $this->success_response( array( 'api_key' => $key ), 201 );
	}
	/**
	 * Revoke one scoped API key.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Revocation response.
	 */
	public function revoke_api_key( $request ) {
		if ( ! $this->can_manage() ) {
			return $this->error_response( 'forbidden', __( 'You do not have permission to manage API keys.', 'mumega-mcp' ), 403 ); }
		$schema = array(
			'id' => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 64,
					'required'  => true,
					'pattern'   => '^[a-z0-9-]+$',
				)
			),
		);
		$params = $this->validated_query_params( $request, $schema );
		if ( is_wp_error( $params ) ) {
			return $this->validation_error_response( $params ); }
		$id = $params['id'];
		return $this->revoke_scoped_api_key( $id ) ? $this->success_response(
			array(
				'revoked' => true,
				'id'      => $id,
			)
		) : $this->error_response( 'not_found', __( 'API key not found or already revoked.', 'mumega-mcp' ), 404 );
	}
	/**
	 * Check the retained settings capability.
	 *
	 * @return bool True when the current user can manage settings.
	 */
	private function can_manage() {
		return function_exists( 'current_user_can' ) && current_user_can( 'mcpwp_manage_settings' ); }
}
