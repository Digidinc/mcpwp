<?php
/** Retained compatibility migration. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_Migrate {
	public static function run() {
		if ( get_option( 'mcpwp_migrated_from_spai', false ) ) { return; }
		self::copy_if_empty( 'spai_api_key', 'mcpwp_api_key' );
		self::copy_if_empty( 'spai_api_keys', 'mcpwp_api_keys' );
		self::copy_if_empty( 'spai_rate_limit_settings', 'mcpwp_rate_limit_settings' );
		$legacy = get_option( 'spai_settings', array() );
		if ( is_array( $legacy ) && ! get_option( 'mcpwp_settings', false ) ) {
			$allowed = array_intersect_key( $legacy, array_flip( array( 'enable_logging', 'log_retention_days', 'log_store_response_data', 'log_redaction_keys' ) ) );
			if ( ! empty( $allowed ) ) { update_option( 'mcpwp_settings', $allowed, false ); }
		}
		update_option( 'mcpwp_migrated_from_spai', true, false );
	}
	private static function copy_if_empty( $from, $to ) {
		if ( false !== get_option( $to, false ) ) { return; }
		$value = get_option( $from, false );
		if ( false !== $value ) { update_option( $to, $value, false ); }
	}
}
