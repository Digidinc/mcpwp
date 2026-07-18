<?php
/**
 * Curated plugin-settings REST routes.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Expose retained plugin settings through authenticated REST routes.
 */
class Mcpwp_REST_Site_Settings extends Mcpwp_REST_API {
	/**
	 * Canonical retained setting defaults.
	 *
	 * @var array<string,bool|int>
	 */
	private $defaults = array(
		'enable_logging'     => true,
		'log_retention_days' => 30,
	);

	/**
	 * Register retained plugin-settings REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/plugin-settings',
			array(
				array_merge(
					$this->route_definition( WP_REST_Server::READABLE, 'get_plugin_settings', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), false ),
					array( 'permission_callback' => array( $this, 'check_admin_permission' ) )
				),
				array_merge(
					$this->route_definition( 'PUT,PATCH', 'update_plugin_settings', 'check_admin_permission', 'site', 'admin', array( 'mcpwp_manage_settings' ), true, $this->plugin_settings_args() ),
					array( 'permission_callback' => array( $this, 'check_admin_permission' ) )
				),
			)
		);
	}

	/**
	 * Return canonical retained plugin settings.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Settings response.
	 */
	public function get_plugin_settings( $request ) {
		return $this->success_response( $this->canonical_settings() );
	}

	/**
	 * Validate and update retained plugin settings.
	 *
	 * @param WP_REST_Request $request Current REST request.
	 * @return WP_REST_Response Update response.
	 */
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
	/**
	 * Merge stored plugin settings with canonical defaults.
	 *
	 * @return array<string,bool|int> Canonical settings.
	 */
	private function canonical_settings() {
		$stored = get_option( 'mcpwp_settings', array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'enable_logging'     => isset( $stored['enable_logging'] ) && is_bool( $stored['enable_logging'] ) ? $stored['enable_logging'] : $this->defaults['enable_logging'],
			'log_retention_days' => isset( $stored['log_retention_days'] ) && is_int( $stored['log_retention_days'] ) && $stored['log_retention_days'] >= 1 && $stored['log_retention_days'] <= 365 ? $stored['log_retention_days'] : $this->defaults['log_retention_days'],
		);
	}
}
