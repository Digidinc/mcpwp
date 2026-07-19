<?php

/**
 * Tests for API-key auth after the legacy spai_ bridge was sunset (#505 bridge
 * removed from the WordPress.org edition — GH #669 / 3.4.14).
 *
 * The WordPress.org "mumega-mcp" edition is a fresh install that never carried
 * any spai_ prefixed keys, so the runtime bridge that accepted raw spai_ keys
 * (find_legacy_spai_key) was removed. This suite pins the new contract:
 *   - A current mcpwp_ scoped key is accepted.
 *   - The native mcpwp_api_key legacy singleton is still accepted + migrated.
 *   - A key stored ONLY in spai_api_keys / spai_api_key is NO LONGER accepted.
 *   - A store containing only spai_ keys counts as "not configured".
 *   - Unknown key → 401; no keys at all → 500.
 *
 * Run command (in wp-test docker):
 *   docker exec -w /tmp/wporg-root/mcpwp wp-test-wordpress-1 \
 *     php vendor/bin/phpunit --configuration tests/phpunit.xml --filter BridgeAuthTest
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class BridgeAuthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	private function make_auth(): Mcpwp_Api_Auth_Test_Harness {
		return new Mcpwp_Api_Auth_Test_Harness();
	}

	private function get_request( string $key, string $method = 'GET', string $route = '/mcpwp/v1/site-info' ): WP_REST_Request {
		return new WP_REST_Request(
			$method,
			$route,
			array(),
			array( 'X-API-Key' => $key )
		);
	}

	private function seed_spai_scoped_key( string $plain_key, array $scopes = array( 'read', 'write', 'admin' ), string $role = 'admin', $revoked_at = null ): void {
		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-key-1',
				'label'      => 'Legacy scoped key',
				'hash'       => wp_hash_password( $plain_key ),
				'user_id'    => 2,
				'scopes'     => $scopes,
				'role'       => $role,
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => $revoked_at,
			),
		) );
	}

	// -----------------------------------------------------------------------
	// Current mcpwp_ keys must still work
	// -----------------------------------------------------------------------

	public function test_mcpwp_scoped_key_is_accepted(): void {
		$auth    = $this->make_auth();
		$created = $auth->create_scoped_api_key( 'v3 key', array( 'read', 'write', 'admin' ) );

		$result = $auth->verify_api_key( $this->get_request( $created['key'] ) );

		$this->assertTrue( $result );
	}

	public function test_native_mcpwp_legacy_singleton_key_is_accepted_and_migrated(): void {
		$auth      = $this->make_auth();
		$plain_key = 'mcpwp_native_legacy_singleton_1234';

		// The plugin's own legacy singleton store (NOT a spai_ store).
		update_option( 'mcpwp_api_key', wp_hash_password( $plain_key ) );
		update_option( 'mcpwp_legacy_migration_owner_id', 2 );

		$result = $auth->verify_api_key( $this->get_request( $plain_key ) );

		$this->assertTrue( $result, 'The native mcpwp_api_key legacy singleton must still authenticate after the spai_ bridge sunset.' );
	}

	// -----------------------------------------------------------------------
	// Sunset: legacy spai_ stores are no longer accepted
	// -----------------------------------------------------------------------

	public function test_legacy_spai_scoped_key_is_rejected_after_bridge_sunset(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_abc123legacy_scoped_key';
		$this->seed_spai_scoped_key( $plain_key );

		// Seed an mcpwp_ key so has_configured_api_keys() is true and we get the
		// 401 invalid_api_key path, isolating "spai key is not honored" from the
		// separate "nothing configured" 500 path.
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );

		$result = $auth->verify_api_key( $this->get_request( $plain_key ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'A key present only in spai_api_keys must no longer be accepted.' );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_legacy_spai_single_key_is_rejected_after_bridge_sunset(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_singlekeylegacy_test1234';

		update_option( 'spai_api_key', wp_hash_password( $plain_key ) );
		update_option( 'mcpwp_legacy_migration_owner_id', 2 );
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );

		$result = $auth->verify_api_key( $this->get_request( $plain_key ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'A key present only in spai_api_key (single-key option) must no longer be accepted.' );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
	}

	public function test_spai_only_store_counts_as_not_configured(): void {
		$auth = $this->make_auth();
		// Only a spai_ store exists — no mcpwp_ keys at all.
		$this->seed_spai_scoped_key( 'spai_only_store_key_1234567890' );

		$result = $auth->verify_api_key( $this->get_request( 'spai_only_store_key_1234567890' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'api_not_configured', $result->get_error_code(),
			'has_configured_api_keys() must ignore spai_ stores after the sunset, so a spai-only site is treated as unconfigured.' );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------------
	// Rejection cases (independent of the bridge)
	// -----------------------------------------------------------------------

	public function test_unknown_key_is_rejected(): void {
		$auth = $this->make_auth();
		$auth->create_scoped_api_key( 'real key', array( 'read', 'write', 'admin' ) );

		$result = $auth->verify_api_key( $this->get_request( 'mcpwp_totallywrongkey' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_no_keys_configured_returns_500(): void {
		$auth   = $this->make_auth();
		$result = $auth->verify_api_key( $this->get_request( 'mcpwp_somekeybutnoconfig' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'api_not_configured', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}
}
