<?php
/** Retained compatibility migration. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_Migrate {
	public static function run() {
		if ( get_option( 'mcpwp_migrated_from_spai', false ) ) { return; }
		$owner_id = self::migration_owner_id();
		self::copy_if_empty( 'spai_api_key', 'mcpwp_api_key' );
		self::copy_legacy_keys_if_empty( $owner_id );
		self::copy_if_empty( 'spai_rate_limit_settings', 'mcpwp_rate_limit_settings' );
		$legacy = get_option( 'spai_settings', array() );
		if ( is_array( $legacy ) && ! get_option( 'mcpwp_settings', false ) ) {
			$allowed = array_intersect_key( $legacy, array_flip( array( 'enable_logging', 'log_retention_days' ) ) );
			if ( ! empty( $allowed ) ) { update_option( 'mcpwp_settings', $allowed, false ); }
		}
		update_option( 'mcpwp_migrated_from_spai', true, false );
	}
	private static function migration_owner_id() {
		$owner_id = get_option( 'mcpwp_legacy_migration_owner_id', 0 );
		if ( is_int( $owner_id ) && $owner_id > 0 && get_userdata( $owner_id ) ) { return $owner_id; }
		$current = get_current_user_id();
		if ( $current > 0 && get_userdata( $current ) && current_user_can( 'mcpwp_manage_settings' ) ) {
			update_option( 'mcpwp_legacy_migration_owner_id', $current, false );
			return $current;
		}
		return 0;
	}
	private static function copy_legacy_keys_if_empty( $owner_id ) {
		if ( false !== get_option( 'mcpwp_api_keys', false ) ) { return; }
		$legacy = get_option( 'spai_api_keys', false );
		if ( ! is_array( $legacy ) ) { return; }
		$records = array();
		foreach ( $legacy as $record ) {
			if ( ! is_array( $record ) ) { continue; }
			$scopes = isset( $record['scopes'] ) && is_array( $record['scopes'] ) ? array_values( array_unique( array_intersect( array( 'read', 'write', 'admin' ), $record['scopes'] ) ) ) : array();
			$categories = isset( $record['tool_categories'] ) && is_array( $record['tool_categories'] ) ? array_values( array_unique( array_intersect( array( 'site', 'content', 'media', 'taxonomy' ), $record['tool_categories'] ) ) ) : array();
			$records[] = array(
				'id' => sanitize_key( isset( $record['id'] ) ? $record['id'] : '' ), 'label' => sanitize_text_field( isset( $record['label'] ) ? $record['label'] : '' ),
				'hash' => isset( $record['hash'] ) ? (string) $record['hash'] : '', 'user_id' => (int) $owner_id, 'scopes' => $scopes,
				'tool_categories' => $categories, 'created_at' => isset( $record['created_at'] ) ? (string) $record['created_at'] : '',
				'last_used_at' => isset( $record['last_used_at'] ) ? $record['last_used_at'] : null, 'revoked_at' => isset( $record['revoked_at'] ) ? $record['revoked_at'] : null,
			);
		}
		update_option( 'mcpwp_api_keys', $records, false );
	}
	private static function copy_if_empty( $from, $to ) {
		if ( false !== get_option( $to, false ) ) { return; }
		$value = get_option( $from, false );
		if ( false !== $value ) { update_option( $to, $value, false ); }
	}
}
