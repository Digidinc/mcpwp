<?php
/**
 * Local rate-limit REST routes.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Expose administrative rate-limit status and settings routes.
 */
class Mcpwp_REST_Rate_Limit extends Mcpwp_REST_API {
	/**
	 * Register retained rate-limit administration routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/rate-limit',
			array(
				array_merge(
					$this->route_definition( WP_REST_Server::READABLE, 'get_status', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), false ),
					array( 'permission_callback' => array( $this, 'check_admin_permission' ) )
				),
				array_merge(
					$this->route_definition( 'PUT,PATCH', 'save_settings', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), true, $this->rate_settings_args() ),
					array( 'permission_callback' => array( $this, 'check_admin_permission' ) )
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/rate-limit/reset',
			array_merge(
				$this->route_definition(
					WP_REST_Server::CREATABLE,
					'reset_counter',
					'check_admin_permission',
					'site',
					'admin',
					array( 'mcpwp_manage_settings' ),
					true,
					array(
						'identifier' => $this->string_arg(
							array(
								'minLength' => 1,
								'maxLength' => 100,
								'required'  => true,
								'pattern'   => '^key:[a-z0-9-]+$',
							)
						),
					)
				),
				array( 'permission_callback' => array( $this, 'check_admin_permission' ) )
			)
		);
	}
	/**
	 * Return effective rate-limit settings and current usage.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Rate-limit status response.
	 */
	public function get_status( $request ) {
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$record  = $this->get_current_api_key_record();
		$id      = is_array( $record ) && ! empty( $record['id'] ) ? 'key:' . sanitize_key( $record['id'] ) : null;
		return $this->success_response(
			array(
				'settings' => $limiter->get_settings(),
				'usage'    => $limiter->get_usage( $id, $request->get_method() ),
			)
		);
	}
	/**
	 * Validate and save rate-limit settings.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Settings update response.
	 */
	public function save_settings( $request ) {
		if ( ! $this->can_manage() ) {
			return $this->error_response( 'forbidden', __( 'You do not have permission to manage rate limiting.', 'mumega-mcp' ), 403 ); }
		$values = $this->validated_json_body( $request, $this->rate_settings_args(), true );
		if ( is_wp_error( $values ) ) {
			return $this->validation_error_response( $values ); }
		$limiter   = Mcpwp_Rate_Limiter::get_instance();
		$current   = $limiter->get_settings();
		$candidate = array_merge( $current, $values );
		if ( $candidate['burst_limit'] > $candidate['requests_per_minute'] ) {
			return $this->error_response( 'invalid_rate_settings', __( 'Burst limit cannot exceed requests per minute.', 'mumega-mcp' ), 400 ); }
		$limiter->update_settings( $values );
		return $this->success_response( $limiter->get_settings() );
	}
	/**
	 * Reset stored counters for one identifier.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Reset response.
	 */
	public function reset_counter( $request ) {
		if ( ! $this->can_manage() ) {
			return $this->error_response( 'forbidden', __( 'You do not have permission to reset rate limits.', 'mumega-mcp' ), 403 ); }
		$schema = array(
			'identifier' => $this->string_arg(
				array(
					'minLength' => 1,
					'maxLength' => 100,
					'required'  => true,
					'pattern'   => '^key:[a-z0-9-]+$',
				)
			),
		);
		$params = $this->validated_json_body( $request, $schema );
		if ( is_wp_error( $params ) ) {
			return $this->validation_error_response( $params ); }
		$id    = $params['identifier'];
		$known = false;
		foreach ( $this->list_scoped_api_keys( true ) as $record ) {
			if ( 'key:' . $record['id'] === $id ) {
				$known = true;
				break; }
		}
		if ( ! $known ) {
			return $this->error_response( 'invalid_identifier', __( 'The rate-limit identifier is not a stored API key.', 'mumega-mcp' ), 400 ); }
		Mcpwp_Rate_Limiter::get_instance()->reset_limit( $id );
		return $this->success_response(
			array(
				'reset'      => true,
				'identifier' => $id,
			)
		);
	}
	/**
	 * Check the retained settings capability.
	 *
	 * @return bool True when the current user can manage settings.
	 */
	private function can_manage() {
		return function_exists( 'current_user_can' ) && current_user_can( 'mcpwp_manage_settings' ); }
}
