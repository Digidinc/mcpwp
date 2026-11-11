<?php
/**
 * Request fixtures own their globals, request caches and created records (#1114).
 * Capture before setUp changes anything; restore from tearDown even on failure.
 */
trait Mcpwp_Test_Request_Fixture_State {
	private $fixture_globals = array();
	private $fixture_statics = array();
	private $fixture_cleanups = array();
	private $fixture_transient_names = array();

	private function capture_request_fixture_state(): void {
		foreach ( array(
			'mcpwp_test_options', 'mcpwp_test_options_autoload', 'mcpwp_test_transients',
			'mcpwp_test_filters', 'mcpwp_test_current_user', 'mcpwp_test_is_admin',
			'mcpwp_test_dispatched_routes', '_mcpwp_test_registered_routes',
			'_mcpwp_test_posts', '_mcpwp_test_meta', 'mcpwp_test_rest_response_override',
			'wp_version', 'mcpwp_test_theme_headers',
		) as $name ) {
			$this->fixture_globals[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
		}
		// Authentication enters guardrail context; integration resolution and
		// verdict/budget caches also survive a controller instance.
		foreach ( array( 'Mcpwp_Integration', 'Mcpwp_REST_API', 'Mcpwp_REST_MCP', 'Mcpwp_Content_Guardrails', 'Mcpwp_Rate_Limiter', 'Mcpwp_Spend_Budget', 'Mcpwp_License' ) as $class ) {
			if ( ! class_exists( $class, false ) ) {
				continue;
			}
			foreach ( ( new ReflectionClass( $class ) )->getProperties( ReflectionProperty::IS_STATIC ) as $property ) {
				$property->setAccessible( true );
				$this->fixture_statics[] = array( $property, $property->getValue() );
			}
		}
		mcpwp_test_reset_rate_limiter();
	}

	private function restore_request_fixture_state(): void {
		try {
			foreach ( array_reverse( $this->fixture_cleanups ) as $cleanup ) {
				$cleanup();
			}
		} finally {
			foreach ( $this->fixture_globals as $name => list( $exists, $value ) ) {
				if ( $exists ) {
					$GLOBALS[ $name ] = $value;
				} else {
					unset( $GLOBALS[ $name ] );
				}
			}
			foreach ( $this->fixture_statics as list( $property, $value ) ) {
				$property->setValue( null, $value );
			}
			$this->fixture_cleanups = $this->fixture_transient_names = array();
			$this->fixture_globals = $this->fixture_statics = array();
		}
	}

	private function fixture_api_key( $controller, ...$args ): array {
		$created = $controller->create_scoped_api_key( ...$args );
		$id      = $created['id'];
		$this->fixture_cleanups[] = static function () use ( $id ) {
			// Revocation retains a record. Remove only this fixture's key ID.
			$keys = get_option( 'mcpwp_api_keys', array() );
			$keys = array_values( array_filter( $keys, static function ( $key ) use ( $id ) {
				return $id !== $key['id'];
			} ) );
			update_option( 'mcpwp_api_keys', $keys );
		};
		return $created;
	}

	private function fixture_request( callable $request ) {
		$before = $GLOBALS['mcpwp_test_transients'];
		try {
			return $request();
		} finally {
			// Register immediately, including when a request throws. Teardown
			// deletes exact names, never another test's rate/idempotency keys.
			foreach ( array_diff_key( $GLOBALS['mcpwp_test_transients'], $before ) as $name => $value ) {
				if ( isset( $this->fixture_transient_names[ $name ] ) ) {
					continue;
				}
				$this->fixture_transient_names[ $name ] = true;
				$this->fixture_cleanups[] = static function () use ( $name ) {
					delete_transient( $name );
				};
			}
		}
	}
}
