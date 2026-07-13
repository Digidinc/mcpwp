<?php
/** Typed local plugin settings. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_Settings {
	const OPTION_NAME = 'mcpwp_settings';
	const RATE_LIMIT_OPTION_NAME = 'mcpwp_rate_limit_settings';
	public function register_settings() {
		register_setting( 'mcpwp_settings_group', self::OPTION_NAME, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_settings' ), 'default' => $this->get_defaults() ) );
		register_setting( 'mcpwp_rate_limit_group', self::RATE_LIMIT_OPTION_NAME, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_rate_limit_settings' ), 'default' => $this->get_rate_limit_defaults() ) );
	}
	public function get_defaults() { return array( 'enable_logging' => true, 'log_retention_days' => 30, 'log_store_response_data' => false, 'log_redaction_keys' => array( 'api_key', 'authorization', 'token' ) ); }
	public function get_rate_limit_defaults() { return array( 'enabled' => true, 'requests_per_minute' => 60, 'requests_per_hour' => 1000, 'burst_limit' => 10, 'whitelist' => array() ); }
	public function sanitize_settings( $input ) {
		$defaults = $this->get_defaults(); $input = is_array( $input ) ? $input : array();
		$keys = $input['log_redaction_keys'] ?? $defaults['log_redaction_keys'];
		$keys = is_array( $keys ) ? $keys : preg_split( '/[\s,]+/', (string) $keys );
		return array( 'enable_logging' => ! empty( $input['enable_logging'] ), 'log_retention_days' => min( 365, max( 1, absint( $input['log_retention_days'] ?? 30 ) ) ), 'log_store_response_data' => ! empty( $input['log_store_response_data'] ), 'log_redaction_keys' => array_values( array_filter( array_unique( array_map( 'sanitize_key', $keys ) ) ) ) );
	}
	public function sanitize_rate_limit_settings( $input ) {
		$input = is_array( $input ) ? $input : array(); $defaults = $this->get_rate_limit_defaults();
		$whitelist = $input['whitelist'] ?? array(); $whitelist = is_array( $whitelist ) ? $whitelist : preg_split( '/[\s,]+/', (string) $whitelist );
		return array( 'enabled' => ! empty( $input['enabled'] ), 'requests_per_minute' => max( 1, absint( $input['requests_per_minute'] ?? $defaults['requests_per_minute'] ) ), 'requests_per_hour' => max( 1, absint( $input['requests_per_hour'] ?? $defaults['requests_per_hour'] ) ), 'burst_limit' => max( 1, absint( $input['burst_limit'] ?? $defaults['burst_limit'] ) ), 'whitelist' => array_values( array_filter( array_map( 'sanitize_text_field', $whitelist ) ) ) );
	}
}
