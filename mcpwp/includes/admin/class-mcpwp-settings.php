<?php
/** Typed local plugin settings. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class Mcpwp_Settings {
	const OPTION_NAME            = 'mcpwp_settings';
	const RATE_LIMIT_OPTION_NAME = 'mcpwp_rate_limit_settings';
	public function get_defaults() {
		return array(
			'enable_logging'     => true,
			'log_retention_days' => 30,
		); }
	public function get_rate_limit_defaults() {
		return array(
			'enabled'             => true,
			'requests_per_minute' => 60,
			'requests_per_hour'   => 1000,
			'burst_limit'         => 10,
			'whitelist'           => array(),
		); }
	public function handle_admin_save( $post ) {
		if ( ! current_user_can( 'mcpwp_manage_settings' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to save these settings.', 'mumega-mcp' ) );
		}
		$post   = is_array( $post ) ? $post : array();
		$target = sanitize_key( wp_unslash( $post['mcpwp_save_settings'] ?? '' ) );
		$action = 'rate' === $target ? 'mcpwp_save_rate_settings' : 'mcpwp_save_plugin_settings';
		$nonce  = sanitize_text_field( wp_unslash( $post['_wpnonce'] ?? '' ) );
		if ( ! in_array( $target, array( 'plugin', 'rate' ), true ) || ! wp_verify_nonce( $nonce, $action ) ) {
			return new WP_Error( 'invalid_admin_nonce', __( 'The settings request could not be verified.', 'mumega-mcp' ) );
		}

		if ( 'plugin' === $target ) {
			$current                   = array_merge( $this->get_defaults(), (array) get_option( self::OPTION_NAME, array() ) );
			$input                     = isset( $post[ self::OPTION_NAME ] ) && is_array( $post[ self::OPTION_NAME ] ) ? $post[ self::OPTION_NAME ] : array();
			$current['enable_logging'] = ! empty( $input['enable_logging'] );
			if ( array_key_exists( 'log_retention_days', $input ) ) {
				$current['log_retention_days'] = min( 365, max( 1, absint( $input['log_retention_days'] ) ) );
			}
			$clean = array_intersect_key( $current, $this->get_defaults() );
			update_option( self::OPTION_NAME, $clean, false );
			return array(
				'saved' => self::OPTION_NAME,
				'value' => $clean,
			);
		}

		$current              = array_merge( $this->get_rate_limit_defaults(), (array) get_option( self::RATE_LIMIT_OPTION_NAME, array() ) );
		$input                = isset( $post[ self::RATE_LIMIT_OPTION_NAME ] ) && is_array( $post[ self::RATE_LIMIT_OPTION_NAME ] ) ? $post[ self::RATE_LIMIT_OPTION_NAME ] : array();
		$candidate            = $current;
		$candidate['enabled'] = ! empty( $input['enabled'] );
		foreach ( array( 'requests_per_minute', 'requests_per_hour', 'burst_limit' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$candidate[ $field ] = min( 100000, max( 1, absint( $input[ $field ] ) ) );
			}
		}
		if ( array_key_exists( 'whitelist', $input ) ) {
			$whitelist = is_array( $input['whitelist'] ) ? $input['whitelist'] : preg_split( '/[\r\n,]+/', (string) $input['whitelist'] );
			$whitelist = $this->sanitize_rate_whitelist( $whitelist );
			if ( is_wp_error( $whitelist ) ) {
				return $whitelist;
			}
			$candidate['whitelist'] = $whitelist;
		}
		if ( $candidate['burst_limit'] > $candidate['requests_per_minute'] ) {
			return new WP_Error( 'invalid_rate_settings', __( 'Burst limit cannot exceed requests per minute.', 'mumega-mcp' ) );
		}
		$clean = array_intersect_key( $candidate, $this->get_rate_limit_defaults() );
		update_option( self::RATE_LIMIT_OPTION_NAME, $clean, false );
		return array(
			'saved' => self::RATE_LIMIT_OPTION_NAME,
			'value' => $clean,
		);
	}
	private function sanitize_rate_whitelist( $whitelist ) {
		$clean = array();
		foreach ( (array) $whitelist as $identifier ) {
			$identifier = (string) $identifier;
			if ( '' === trim( $identifier ) ) {
				continue;
			}
			$identifier = sanitize_text_field( $identifier );
			if ( '' === $identifier || strlen( $identifier ) > 100 ) {
				return new WP_Error( 'invalid_rate_whitelist', __( 'Whitelist identifiers must be non-empty and no more than 100 bytes.', 'mumega-mcp' ) );
			}
			$clean[] = $identifier;
		}
		$clean = array_values( array_unique( $clean ) );
		if ( count( $clean ) > 100 ) {
			return new WP_Error( 'invalid_rate_whitelist', __( 'The whitelist cannot contain more than 100 unique identifiers.', 'mumega-mcp' ) );
		}
		return $clean;
	}
}
