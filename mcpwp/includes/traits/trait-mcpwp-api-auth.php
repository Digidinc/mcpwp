<?php
/**
 * Scoped local API-key authentication.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Api_Auth {
	private $mcpwp_current_api_key_record = null;

	public function get_current_api_key_record() {
		return $this->mcpwp_current_api_key_record;
	}

	public function verify_api_key( $request, $required_scope = null, $charge_rate_limit = true ) {
		$key = $this->get_api_key_from_request( $request );
		if ( '' === $key ) {
			return new WP_Error( 'missing_api_key', __( 'An API key is required in the X-API-Key header.', 'mumega-mcp' ), array( 'status' => 401 ) );
		}

		$legacy = get_option( 'mcpwp_api_key', '' );
		$record = $this->find_scoped_api_key( $key );
		if ( ! $record ) {
			$record = $this->find_legacy_spai_key( $key );
		}
		if ( ! $record && $legacy && $this->is_api_key_match( $key, $legacy ) ) {
			$record = $this->migrate_legacy_key_to_scoped_store( $key );
		}
		if ( ! $record ) {
			if ( ! $this->has_configured_api_keys( $legacy ) ) {
				return new WP_Error( 'api_not_configured', __( 'No API key is configured.', 'mumega-mcp' ), array( 'status' => 500 ) );
			}
			return new WP_Error( 'invalid_api_key', __( 'The API key is invalid.', 'mumega-mcp' ), array( 'status' => 401 ) );
		}
		if ( ! $this->record_has_valid_identity_fields( $record ) ) {
			return new WP_Error( 'api_key_record_invalid', __( 'The API key has an invalid stored record.', 'mumega-mcp' ), array( 'status' => 500 ) );
		}
		if ( ! $this->record_has_valid_scopes( $record ) ) {
			return new WP_Error( 'api_key_scope_invalid', __( 'The API key has an invalid stored scope configuration.', 'mumega-mcp' ), array( 'status' => 500 ) );
		}
		if ( ! isset( $record['user_id'] ) || ! is_int( $record['user_id'] ) || $record['user_id'] < 1 ) {
			return new WP_Error( 'api_key_owner_setup_required', __( 'The API key must be bound to an explicit WordPress user.', 'mumega-mcp' ), array( 'status' => 500 ) );
		}
		$user = function_exists( 'get_user_by' ) ? get_user_by( 'id', (int) $record['user_id'] ) : get_userdata( (int) $record['user_id'] );
		if ( ! $user ) {
			return new WP_Error( 'api_key_owner_unavailable', __( 'The WordPress user bound to this API key is unavailable.', 'mumega-mcp' ), array( 'status' => 500 ) );
		}
		wp_set_current_user( (int) $record['user_id'] );

		$required = null === $required_scope ? $this->get_required_scope_for_request( $request ) : $required_scope;
		if ( ! $this->key_has_scope( $record, $required ) ) {
			return new WP_Error(
				'insufficient_scope',
				__( 'The API key does not grant the required scope.', 'mumega-mcp' ),
				array(
					'status'         => 403,
					'required_scope' => $required,
				)
			);
		}

		$this->mcpwp_current_api_key_record = $record;
		if ( ! $charge_rate_limit ) {
			return true; }
		$rate = $this->check_rate_limit( 'key:' . sanitize_key( $record['id'] ), $request->get_method(), $record );
		return is_wp_error( $rate ) ? $rate : true;
	}

	protected function get_api_key_from_request( $request ) {
		return trim( (string) $request->get_header( 'X-API-Key' ) );
	}

	protected function check_rate_limit( $identifier = null, $method = 'POST', $key_record = null ) {
		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return true;
		}
		return Mcpwp_Rate_Limiter::get_instance()->check_limit( $identifier, $method, $key_record );
	}

	protected function is_api_key_match( $key, $stored ) {
		if ( $this->looks_like_password_hash( $stored ) ) {
			return wp_check_password( $key, $stored );
		}
		return hash_equals( (string) $stored, (string) $key );
	}

	protected function has_configured_api_keys( $legacy_key ) {
		return '' !== (string) $legacy_key || ! empty( $this->get_scoped_api_keys_raw() ) || ! empty( get_option( 'spai_api_keys', array() ) ) || '' !== (string) get_option( 'spai_api_key', '' );
	}

	protected function find_scoped_api_key( $key ) {
		foreach ( $this->get_scoped_api_keys_raw() as $record ) {
			$record = $this->normalize_api_key_record( $record );
			if ( ! $this->record_has_valid_identity_fields( $record ) ) {
				continue; }
			if ( empty( $record['revoked_at'] ) && $this->is_api_key_match( $key, $record['hash'] ) ) {
				return $record;
			}
		}
		return null;
	}

	protected function find_legacy_spai_key( $key ) {
		foreach ( (array) get_option( 'spai_api_keys', array() ) as $record ) {
			$record = $this->normalize_api_key_record( $record );
			if ( ! $this->record_has_valid_identity_fields( $record ) ) {
				continue; }
			if ( empty( $record['revoked_at'] ) && $this->is_api_key_match( $key, $record['hash'] ) ) {
				return $record;
			}
		}
		$single = get_option( 'spai_api_key', '' );
		if ( $single && $this->is_api_key_match( $key, $single ) ) {
			return array(
				'id'              => 'legacy-spai',
				'label'           => 'Legacy key',
				'hash'            => $single,
				'user_id'         => (int) get_option( 'mcpwp_legacy_migration_owner_id', 0 ),
				'scopes'          => array( 'read', 'write', 'admin' ),
				'tool_categories' => array(),
				'revoked_at'      => null,
			);
		}
		return null;
	}

	protected function get_required_scope_for_request( $request ) {
		$route  = (string) $request->get_route();
		$method = strtoupper( (string) $request->get_method() );
		if ( false !== strpos( $route, '/mcpwp/v1/mcp' ) ) {
			$body = $request->get_json_params();
			if ( is_array( $body ) && 'tools/call' === ( $body['method'] ?? '' ) ) {
				return $this->get_required_scope_for_tool_name( (string) ( $body['params']['name'] ?? '' ) );
			}
			return 'read';
		}
		if ( $this->route_matches( $route, '/mcpwp/v1/api-keys' ) || $this->route_matches( $route, '/mcpwp/v1/plugin-settings' ) || $this->route_matches( $route, '/mcpwp/v1/rate-limit' ) ) {
			return 'admin';
		}
		if ( $this->route_matches( $route, '/mcpwp/v1/drafts' ) && 'DELETE' === $method ) {
			return 'admin'; }
		return in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ? 'read' : 'write';
	}

	protected function route_matches( $route, $prefix ) {
		return $route === $prefix || 0 === strpos( $route, $prefix . '/' );
	}

	protected function get_required_scope_for_tool_name( $tool_name ) {
		if ( 'wp_delete_draft' === $tool_name ) {
			return 'admin';
		}
		if ( class_exists( 'Mcpwp_MCP_Free_Tools' ) ) {
			$map = ( new Mcpwp_MCP_Free_Tools() )->get_tool_map();
			if ( isset( $map[ $tool_name ] ) && 'GET' === $map[ $tool_name ]['method'] ) {
				return 'read';
			}
		}
		return 'write';
	}

	protected function key_has_scope( $record, $required_scope ) {
		$scopes = isset( $record['scopes'] ) ? (array) $record['scopes'] : array();
		return $this->scope_rank( $required_scope ) <= max( array_map( array( $this, 'scope_rank' ), $scopes ) );
	}

	protected function scope_rank( $scope ) {
		return array_search( $scope, array( 'read', 'write', 'admin' ), true ) ?: 0;
	}

	public function create_scoped_api_key( $label = '', $scopes = array(), $tool_categories = array(), $user_id = null ) {
		$user_id          = null === $user_id ? get_current_user_id() : (int) $user_id;
		$valid_scopes     = $this->validated_scopes( $scopes );
		$valid_categories = $this->validated_tool_categories( $tool_categories );
		if ( is_wp_error( $valid_scopes ) || is_wp_error( $valid_categories ) || $user_id < 1 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'invalid_api_key_record', __( 'A valid owner and non-empty scopes are required.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		$key       = $this->generate_api_key();
		$record    = array(
			'id'              => wp_generate_uuid4(),
			'label'           => sanitize_text_field( $label ),
			'hash'            => wp_hash_password( $key ),
			'user_id'         => $user_id,
			'scopes'          => $valid_scopes,
			'tool_categories' => $valid_categories,
			'created_at'      => current_time( 'mysql' ),
			'last_used_at'    => null,
			'revoked_at'      => null,
		);
		$records   = $this->get_scoped_api_keys_raw();
		$records[] = $record;
		update_option( $this->get_scoped_api_keys_option_name(), $records, false );
		$public        = $record;
		$public['key'] = $key;
		unset( $public['hash'] );
		return $public;
	}

	public function list_scoped_api_keys( $include_revoked = false ) {
		$output = array();
		foreach ( $this->get_scoped_api_keys_raw() as $record ) {
			$record = $this->normalize_api_key_record( $record );
			if ( ! $include_revoked && ! empty( $record['revoked_at'] ) ) {
				continue;
			}
			unset( $record['hash'] );
			$output[] = $record;
		}
		return $output;
	}

	public function revoke_scoped_api_key( $key_id ) {
		$records = $this->get_scoped_api_keys_raw();
		$changed = false;
		foreach ( $records as &$record ) {
			if ( sanitize_key( $record['id'] ?? '' ) === sanitize_key( $key_id ) && empty( $record['revoked_at'] ) ) {
				$record['revoked_at'] = current_time( 'mysql' );
				$changed              = true;
			}
		}
		unset( $record );
		if ( $changed ) {
			update_option( $this->get_scoped_api_keys_option_name(), $records, false );
		}
		return $changed;
	}

	protected function migrate_legacy_key_to_scoped_store( $legacy_key ) {
		$hash = wp_hash_password( $legacy_key );
		update_option( 'mcpwp_api_key', $hash, false );
		$owner_id = (int) get_option( 'mcpwp_legacy_migration_owner_id', 0 );
		$record   = array(
			'id'              => wp_generate_uuid4(),
			'label'           => 'Legacy key',
			'hash'            => $hash,
			'user_id'         => $owner_id,
			'scopes'          => array( 'read', 'write', 'admin' ),
			'tool_categories' => array(),
			'created_at'      => current_time( 'mysql' ),
			'last_used_at'    => null,
			'revoked_at'      => null,
		);
		if ( $owner_id < 1 ) {
			return $record; }
		$records   = $this->get_scoped_api_keys_raw();
		$records[] = $record;
		update_option( $this->get_scoped_api_keys_option_name(), $records, false );
		return $record;
	}

	protected function get_scoped_api_keys_raw() {
		$value = get_option( $this->get_scoped_api_keys_option_name(), array() );
		return is_array( $value ) ? $value : array();
	}

	protected function normalize_api_key_record( $record ) {
		$record = is_array( $record ) ? $record : array();
		return array(
			'id'              => isset( $record['id'] ) ? $record['id'] : '',
			'label'           => isset( $record['label'] ) ? (string) $record['label'] : '',
			'hash'            => isset( $record['hash'] ) ? $record['hash'] : '',
			'user_id'         => isset( $record['user_id'] ) ? $record['user_id'] : 0,
			'scopes'          => isset( $record['scopes'] ) ? $record['scopes'] : array(),
			'tool_categories' => isset( $record['tool_categories'] ) ? $record['tool_categories'] : array(),
			'created_at'      => isset( $record['created_at'] ) ? (string) $record['created_at'] : '',
			'last_used_at'    => isset( $record['last_used_at'] ) ? $record['last_used_at'] : null,
			'revoked_at'      => isset( $record['revoked_at'] ) ? $record['revoked_at'] : null,
		);
	}

	protected function sanitize_scopes( $scopes ) {
		$validated = $this->validated_scopes( $scopes );
		return is_wp_error( $validated ) ? array() : $validated;
	}

	protected function validated_scopes( $scopes ) {
		if ( ! is_array( $scopes ) || empty( $scopes ) ) {
			return new WP_Error( 'invalid_scopes', __( 'At least one API-key scope is required.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		$normalized = array_values( $scopes );
		foreach ( $normalized as $scope ) {
			if ( ! is_string( $scope ) ) {
				return new WP_Error( 'invalid_scopes', __( 'API-key scopes must be unique read, write, or admin values.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
		}
		if ( count( $normalized ) !== count( array_unique( $normalized ) ) || array_diff( $normalized, array( 'read', 'write', 'admin' ) ) ) {
			return new WP_Error( 'invalid_scopes', __( 'API-key scopes must be unique read, write, or admin values.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		return array_values( $normalized );
	}

	protected function record_has_valid_scopes( $record ) {
		return ! is_wp_error( $this->validated_scopes( $record['scopes'] ?? null ) ) && ! is_wp_error( $this->validated_tool_categories( $record['tool_categories'] ?? null ) );
	}

	protected function record_has_valid_identity_fields( $record ) {
		$id   = isset( $record['id'] ) ? $record['id'] : '';
		$hash = isset( $record['hash'] ) ? $record['hash'] : '';
		return is_string( $id ) && '' !== $id && strlen( $id ) <= 64 && sanitize_key( $id ) === $id && is_string( $hash ) && '' !== $hash;
	}

	protected function validated_tool_categories( $categories ) {
		if ( ! is_array( $categories ) ) {
			return new WP_Error( 'invalid_tool_categories', __( 'Tool categories must be an array.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
		$normalized = array_values( $categories );
		foreach ( $normalized as $category ) {
			if ( ! is_string( $category ) ) {
				return new WP_Error( 'invalid_tool_categories', __( 'Tool categories contain an invalid or duplicate value.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
		}
		if ( count( $normalized ) > 4 || count( $normalized ) !== count( array_unique( $normalized ) ) || array_diff( $normalized, array( 'site', 'content', 'media', 'taxonomy' ) ) ) {
			return new WP_Error( 'invalid_tool_categories', __( 'Tool categories contain an invalid or duplicate value.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		return array_values( $normalized );
	}

	public function resolve_key_tool_categories( $record ) {
		return array_values( array_unique( array_map( 'sanitize_key', (array) ( $record['tool_categories'] ?? array() ) ) ) );
	}

	public function key_allows_category( $record, $category ) {
		$allowed = $this->resolve_key_tool_categories( $record );
		return empty( $allowed ) || in_array( sanitize_key( $category ), $allowed, true );
	}

	protected function get_scoped_api_keys_option_name() {
		return 'mcpwp_api_keys';
	}

	protected function looks_like_password_hash( $value ) {
		return is_string( $value ) && 0 === strpos( $value, '$' );
	}

	public function generate_api_key() {
		return 'mcpwp_' . bin2hex( random_bytes( 24 ) );
	}

	public function regenerate_api_key() {
		$key = $this->generate_api_key();
		update_option( 'mcpwp_api_key', wp_hash_password( $key ), false );
		return $key;
	}
}
