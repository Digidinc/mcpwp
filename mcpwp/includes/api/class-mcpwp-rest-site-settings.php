<?php
/** Curated plugin settings. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_REST_Site_Settings extends Mcpwp_REST_API {
	private $defaults = array( 'enable_logging' => true, 'log_retention_days' => 30 );

	public function register_routes() {
		register_rest_route( $this->namespace, '/plugin-settings', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_plugin_settings' ), 'permission_callback' => array( $this, 'check_permission' ) ),
			array( 'methods' => 'PUT,PATCH', 'callback' => array( $this, 'update_plugin_settings' ), 'permission_callback' => array( $this, 'check_permission' ) ),
		) );
	}

	public function get_plugin_settings( $request ) {
		return $this->success_response( array_merge( $this->defaults, (array) get_option( 'mcpwp_settings', array() ) ) );
	}

	public function update_plugin_settings( $request ) {
		$params  = array_merge( $request->get_params(), (array) $request->get_json_params() );
		$current = array_merge( $this->defaults, (array) get_option( 'mcpwp_settings', array() ) );
		if ( array_key_exists( 'enable_logging', $params ) ) { $current['enable_logging'] = (bool) $params['enable_logging']; }
		if ( array_key_exists( 'log_retention_days', $params ) ) { $current['log_retention_days'] = min( 365, max( 1, absint( $params['log_retention_days'] ) ) ); }
		update_option( 'mcpwp_settings', $current, false );
		return $this->success_response( $current );
	}
}
