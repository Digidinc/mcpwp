<?php
/** Curated plugin settings. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class Mcpwp_REST_Site_Settings extends Mcpwp_REST_API {
	private $defaults = array(
		'enable_logging'     => true,
		'log_retention_days' => 30,
	);

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/plugin-settings',
			array(
				$this->route_definition( WP_REST_Server::READABLE, 'get_plugin_settings', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), false ),
				$this->route_definition( 'PUT,PATCH', 'update_plugin_settings', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), true, $this->plugin_settings_args() ),
			)
		);
	}

	public function get_plugin_settings( $request ) {
		return $this->success_response( $this->canonical_settings() );
	}

	public function update_plugin_settings( $request ) {
		$params = $this->validated_json_body( $request, $this->plugin_settings_args(), true );
		if ( is_wp_error( $params ) ) {
			return $this->validation_error_response( $params ); }
		$current = $this->canonical_settings();
		if ( array_key_exists( 'enable_logging', $params ) ) {
			$current['enable_logging'] = (bool) $params['enable_logging']; }
		if ( array_key_exists( 'log_retention_days', $params ) ) {
			$current['log_retention_days'] = $params['log_retention_days']; }
		update_option( 'mcpwp_settings', $current, false );
		return $this->success_response( $current );
	}
	private function canonical_settings() {
		$stored = get_option( 'mcpwp_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'enable_logging'     => isset( $stored['enable_logging'] ) && is_bool( $stored['enable_logging'] ) ? $stored['enable_logging'] : $this->defaults['enable_logging'],
			'log_retention_days' => isset( $stored['log_retention_days'] ) && is_int( $stored['log_retention_days'] ) && $stored['log_retention_days'] >= 1 && $stored['log_retention_days'] <= 365 ? $stored['log_retention_days'] : $this->defaults['log_retention_days'],
		);
	}
}
