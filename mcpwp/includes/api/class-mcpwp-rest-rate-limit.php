<?php
/** Local rate-limit routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_REST_Rate_Limit extends Mcpwp_REST_API {
	public function register_routes() {
		register_rest_route( $this->namespace, '/rate-limit', array(
			$this->route_definition( WP_REST_Server::READABLE, 'get_status', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), false ),
			$this->route_definition( 'PUT,PATCH', 'save_settings', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), true, $this->rate_settings_args() ),
		) );
		register_rest_route( $this->namespace, '/rate-limit/reset', $this->route_definition( WP_REST_Server::CREATABLE, 'reset_counter', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), true, array( 'identifier' => $this->string_arg( array( 'minLength' => 1, 'maxLength' => 100, 'required' => true, 'pattern' => '^key:[a-z0-9-]+$' ) ) ) ) );
	}
	public function get_status( $request ) {
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$record  = $this->get_current_api_key_record();
		$id      = is_array( $record ) && ! empty( $record['id'] ) ? 'key:' . sanitize_key( $record['id'] ) : null;
		return $this->success_response( array( 'settings' => $limiter->get_settings(), 'usage' => $limiter->get_usage( $id ) ) );
	}
	public function save_settings( $request ) {
		if ( ! $this->can_manage() ) { return $this->error_response( 'forbidden', __( 'You do not have permission to manage rate limiting.', 'mumega-mcp' ), 403 ); }
		$params = array_merge( $request->get_params(), (array) $request->get_json_params() );
		$values = array_intersect_key( $params, array_flip( array( 'enabled', 'requests_per_minute', 'requests_per_hour', 'burst_limit', 'whitelist' ) ) );
		if ( empty( $values ) ) { return $this->error_response( 'missing_settings', __( 'No rate-limit settings were provided.', 'mumega-mcp' ), 400 ); }
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$limiter->update_settings( $values );
		return $this->success_response( $limiter->get_settings() );
	}
	public function reset_counter( $request ) {
		if ( ! $this->can_manage() ) { return $this->error_response( 'forbidden', __( 'You do not have permission to reset rate limits.', 'mumega-mcp' ), 403 ); }
		$id = sanitize_text_field( (string) $request->get_param( 'identifier' ) );
		if ( '' === $id ) { return $this->error_response( 'missing_identifier', __( 'Identifier is required.', 'mumega-mcp' ), 400 ); }
		Mcpwp_Rate_Limiter::get_instance()->reset_limit( $id );
		return $this->success_response( array( 'reset' => true, 'identifier' => $id ) );
	}
	private function can_manage() { return function_exists( 'current_user_can' ) && current_user_can( 'mcpwp_manage_settings' ); }
}
