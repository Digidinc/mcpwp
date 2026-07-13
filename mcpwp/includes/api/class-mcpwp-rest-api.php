<?php
/**
 * Base REST controller.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Mcpwp_REST_API {
	use Mcpwp_Api_Auth;
	use Mcpwp_Logging;
	use Mcpwp_Sanitization;

	protected $namespace = 'mcpwp/v1';

	public function check_permission( $request ) {
		return $this->verify_api_key( $request );
	}

	protected function success_response( $data, $status = 200 ) {
		return $this->add_rate_limit_headers( new WP_REST_Response( $data, $status ) );
	}

	protected function error_response( $code, $message, $status = 400, $context = array() ) {
		return $this->add_rate_limit_headers( new WP_REST_Response( array( 'code' => $code, 'message' => $message, 'data' => array_merge( array( 'status' => $status ), $context ) ), $status ) );
	}

	protected function add_rate_limit_headers( $response ) {
		if ( class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			foreach ( Mcpwp_Rate_Limiter::get_instance()->get_headers() as $name => $value ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	protected function get_pagination_args() {
		return array( 'page' => 1, 'per_page' => 20 );
	}

	protected function get_post_args() {
		return array();
	}
}
