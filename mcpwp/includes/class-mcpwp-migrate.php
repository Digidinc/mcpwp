<?php
/**
 * Retained compatibility migration routines.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

/**
 * Migrate retained settings and API keys from legacy storage.
 */
class Mcpwp_Migrate {
	/**
	 * Run retained legacy migrations for the authenticated owner.
	 *
	 * @return void
	 */
	public static function run() {
		$owner_id = self::migration_owner_id();
		if ( $owner_id < 1 ) {
			return; }
		self::repair_unbound_key_owners( $owner_id );
		if ( get_option( 'mcpwp_migrated_from_spai', false ) ) {
			return; }
		self::copy_if_empty( 'spai_api_key', 'mcpwp_api_key' );
		self::copy_legacy_keys_if_empty( $owner_id );
		self::copy_if_empty( 'spai_rate_limit_settings', 'mcpwp_rate_limit_settings' );
		$legacy = get_option( 'spai_settings', array() );
		if ( is_array( $legacy ) && ! get_option( 'mcpwp_settings', false ) ) {
			$allowed = array_intersect_key( $legacy, array_flip( array( 'enable_logging', 'log_retention_days' ) ) );
			if ( ! empty( $allowed ) ) {
				update_option( 'mcpwp_settings', $allowed, false ); }
		}
		update_option( 'mcpwp_migrated_from_spai', true, false );
	}
	/**
	 * Bind legacy migration to the current administrator.
	 *
	 * @param int $owner_id Proposed WordPress user ID.
	 * @return bool True when the owner is valid and migration ran.
	 */
	public static function setup_owner( $owner_id ) {
		$owner_id = is_int( $owner_id ) ? $owner_id : 0;
		if ( $owner_id < 1 || get_current_user_id() !== $owner_id || ! get_userdata( $owner_id ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'mcpwp_manage_settings' ) ) {
			return false; }
		update_option( 'mcpwp_legacy_migration_owner_id', $owner_id, false );
		self::repair_unbound_key_owners( $owner_id );
		self::run();
		return true;
	}
	/**
	 * Resolve the authorized migration owner for the current request.
	 *
	 * @return int Valid WordPress user ID, or zero when unavailable.
	 */
	private static function migration_owner_id() {
		$current = get_current_user_id();
		if ( $current < 1 || ! get_userdata( $current ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'mcpwp_manage_settings' ) ) {
			return 0; }
		$owner_id = (int) get_option( 'mcpwp_legacy_migration_owner_id', 0 );
		if ( $owner_id > 0 && get_userdata( $owner_id ) ) {
			return $owner_id; }
		update_option( 'mcpwp_legacy_migration_owner_id', $current, false );
		return $current;
	}
	/**
	 * Assign an authorized owner to legacy unbound key records.
	 *
	 * @param int $owner_id WordPress user ID to assign.
	 * @return void
	 */
	private static function repair_unbound_key_owners( $owner_id ) {
		$records = get_option( 'mcpwp_api_keys', false );
		if ( ! is_array( $records ) ) {
			return; }
		$changed = false;
		foreach ( $records as &$record ) {
			if ( is_array( $record ) && isset( $record['user_id'] ) && is_int( $record['user_id'] ) && 0 === $record['user_id'] ) {
				$record['user_id'] = $owner_id;
				$changed           = true;
			}
		}
		unset( $record );
		if ( $changed ) {
			update_option( 'mcpwp_api_keys', $records, false ); }
	}
	/**
	 * Copy legacy scoped key records when the current store is absent.
	 *
	 * @param int $owner_id WordPress user ID assigned to copied records.
	 * @return void
	 */
	private static function copy_legacy_keys_if_empty( $owner_id ) {
		if ( false !== get_option( 'mcpwp_api_keys', false ) ) {
			return; }
		$legacy = get_option( 'spai_api_keys', false );
		if ( ! is_array( $legacy ) ) {
			return; }
		$records = array();
		foreach ( $legacy as $record ) {
			if ( ! is_array( $record ) ) {
				continue; }
			$scopes     = isset( $record['scopes'] ) && is_array( $record['scopes'] ) ? array_values( array_unique( array_intersect( array( 'read', 'write', 'admin' ), $record['scopes'] ) ) ) : array();
			$categories = isset( $record['tool_categories'] ) && is_array( $record['tool_categories'] ) ? array_values( array_unique( array_intersect( array( 'site', 'content', 'media', 'taxonomy' ), $record['tool_categories'] ) ) ) : array();
			$records[]  = array(
				'id'              => sanitize_key( isset( $record['id'] ) ? $record['id'] : '' ),
				'label'           => sanitize_text_field( isset( $record['label'] ) ? $record['label'] : '' ),
				'hash'            => isset( $record['hash'] ) ? (string) $record['hash'] : '',
				'user_id'         => (int) $owner_id,
				'scopes'          => $scopes,
				'tool_categories' => $categories,
				'created_at'      => isset( $record['created_at'] ) ? (string) $record['created_at'] : '',
				'last_used_at'    => isset( $record['last_used_at'] ) ? $record['last_used_at'] : null,
				'revoked_at'      => isset( $record['revoked_at'] ) ? $record['revoked_at'] : null,
			);
		}
		update_option( 'mcpwp_api_keys', $records, false );
	}
	/**
	 * Copy a legacy option only when the target option is absent.
	 *
	 * @param string $from Legacy option name.
	 * @param string $to   Current option name.
	 * @return void
	 */
	private static function copy_if_empty( $from, $to ) {
		if ( false !== get_option( $to, false ) ) {
			return; }
		$value = get_option( $from, false );
		if ( false !== $value ) {
			update_option( $to, $value, false ); }
	}
}
