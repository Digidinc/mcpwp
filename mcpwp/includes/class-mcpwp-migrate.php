<?php
/** Retained compatibility migration. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_Migrate {
	public static function run() {
		if ( get_option( 'mcpwp_migrated_from_spai', false ) ) { return; }
		$owner_id = self::migration_owner_id();
		if ( $owner_id < 1 ) { return; }
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
	public static function setup_owner( $owner_id ) {
		$owner_id = is_int( $owner_id ) ? $owner_id : 0;
		if ( $owner_id < 1 || $owner_id !== get_current_user_id() || ! get_userdata( $owner_id ) || ! current_user_can( 'mcpwp_manage_settings' ) ) { return false; }
		update_option( 'mcpwp_legacy_migration_owner_id', $owner_id, false );
		self::repair_unbound_key_owners( $owner_id );
		self::run();
		return true;
	}
	private static function migration_owner_id() {
		$current = get_current_user_id();
		if ( $current < 1 || ! get_userdata( $current ) || ! current_user_can( 'mcpwp_manage_settings' ) ) { return 0; }
		$owner_id = get_option( 'mcpwp_legacy_migration_owner_id', 0 );
		if ( is_int( $owner_id ) && $owner_id > 0 && get_userdata( $owner_id ) ) { return $owner_id; }
		update_option( 'mcpwp_legacy_migration_owner_id', $current, false );
		return $current;
	}
	private static function repair_unbound_key_owners( $owner_id ) {
		$records = get_option( 'mcpwp_api_keys', false );
		if ( ! is_array( $records ) ) { return; }
		$changed = false;
		foreach ( $records as &$record ) {
			if ( is_array( $record ) && isset( $record['user_id'] ) && is_int( $record['user_id'] ) && 0 === $record['user_id'] ) {
				$record['user_id'] = $owner_id;
				$changed = true;
			}
		}
		unset( $record );
		if ( $changed ) { update_option( 'mcpwp_api_keys', $records, false ); }
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
