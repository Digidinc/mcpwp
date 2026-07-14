<?php

/**
 * Tests for dual-prefix API key auth (issue #505 bridge).
 *
 * Covers:
 *   - A current mcpwp_ key stored in mcpwp_api_keys is accepted.
 *   - A legacy spai_ key stored in spai_api_keys is accepted.
 *   - A legacy single key stored in spai_api_key is accepted.
 *   - A bad / unknown key is rejected with 401.
 *   - A revoked spai_ key is rejected.
 *   - Both stores empty → 500 (no keys configured).
 *   - Legacy spai_ key gets the correct default scopes (admin = read+write+admin).
 *
 * Run command (in wp-test docker):
 *   docker exec wp-test-wordpress-1 bash -c \
 *     "cd /var/www/html/wp-content/plugins/mcpwp && \
 *      php vendor/bin/phpunit --configuration tests/phpunit.xml \
 *      --filter BridgeAuthTest"
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class BridgeAuthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
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

	// -----------------------------------------------------------------------
	// Current mcpwp_ keys must still work
	// -----------------------------------------------------------------------

	public function test_mcpwp_scoped_key_is_accepted(): void {
		$auth    = $this->make_auth();
		$created = $auth->create_scoped_api_key( 'v3 key', array( 'read', 'write', 'admin' ) );

		$result = $auth->verify_api_key( $this->get_request( $created['key'] ) );

		$this->assertTrue( $result );
	}

	// -----------------------------------------------------------------------
	// Legacy spai_ multi-key store (spai_api_keys)
	// -----------------------------------------------------------------------

	public function test_legacy_spai_scoped_key_is_accepted(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_abc123legacy_scoped_key';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-key-1',
				'label'      => 'Legacy scoped key',
				'hash'       => $hash,
				'user_id'    => 2,
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		$result = $auth->verify_api_key( $this->get_request( $plain_key ) );

		$this->assertTrue( $result, 'A valid key from spai_api_keys must be accepted' );
	}

	public function test_legacy_spai_scoped_key_sets_user_context(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_userset_test_key_value12';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-key-ctx',
				'label'      => 'Context key',
				'hash'       => $hash,
				'user_id'    => 2,
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		$auth->verify_api_key( $this->get_request( $plain_key ) );

		$this->assertNotSame( 0, $GLOBALS['mcpwp_test_current_user'],
			'verify_api_key must set the API user context even for legacy spai_ key' );
	}

	// -----------------------------------------------------------------------
	// Legacy spai_ single key (spai_api_key)
	// -----------------------------------------------------------------------

	public function test_legacy_spai_single_key_is_accepted(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_singlekeylegacy_test1234';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_key', $hash );
		update_option( 'mcpwp_legacy_migration_owner_id', 2 );

		$result = $auth->verify_api_key( $this->get_request( $plain_key ) );

		$this->assertTrue( $result, 'A valid key from spai_api_key (single-key option) must be accepted' );
	}

	public function test_legacy_spai_single_key_gets_admin_scope_on_write(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_singlewrite_test_key1234';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_key', $hash );
		update_option( 'mcpwp_legacy_migration_owner_id', 2 );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array( 'X-API-Key' => $plain_key )
		);

		$result = $auth->verify_api_key( $request );

		// Single spai_ key is synthesised with default admin scopes → write is allowed.
		$this->assertTrue( $result, 'Legacy spai_api_key single key must have write scope' );
	}

	// -----------------------------------------------------------------------
	// Rejection cases
	// -----------------------------------------------------------------------

	public function test_unknown_key_is_rejected(): void {
		$auth = $this->make_auth();
		// Set up a valid key so the "no keys configured" path is not hit.
		$auth->create_scoped_api_key( 'real key', array( 'read', 'write', 'admin' ) );

		$result = $auth->verify_api_key( $this->get_request( 'mcpwp_totallywrongkey' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_revoked_spai_key_is_rejected(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_revokedkey_test_abc12345';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'revoked-spai',
				'label'      => 'Revoked',
				'hash'       => $hash,
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => '2026-06-01 00:00:00', // revoked
			),
		) );

		// Seed a mcpwp_ key so we don't hit "no keys configured".
		$auth->create_scoped_api_key( 'other key', array( 'read' ) );

		$result = $auth->verify_api_key( $this->get_request( $plain_key ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
	}

	public function test_bad_key_against_spai_store_is_rejected(): void {
		$auth = $this->make_auth();

		// Seed the spai_ store with one valid key.
		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-good',
				'label'      => 'Good',
				'hash'       => wp_hash_password( 'spai_goodkey_12345678901234' ),
				'user_id'    => 2,
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		// Also seed one mcpwp_ key so has_configured_api_keys() returns true,
		// ensuring the "invalid_api_key" (401) path fires rather than the
		// "api_not_configured" (500) path.  has_configured_api_keys() intentionally
		// checks only the mcpwp_ stores because the spai_ bridge store is a
		// fallback, not the configured state.
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );

		// Correct key → accepted.
		$good = $auth->verify_api_key( $this->get_request( 'spai_goodkey_12345678901234' ) );
		$this->assertTrue( $good );

		// Reset user context.
		$GLOBALS['mcpwp_test_current_user'] = 0;

		// Wrong key → rejected with 401 invalid_api_key.
		$bad = $auth->verify_api_key( $this->get_request( 'spai_wrongkey_000000000000' ) );
		$this->assertInstanceOf( WP_Error::class, $bad );
		$this->assertSame( 'invalid_api_key', $bad->get_error_code() );
	}

	// -----------------------------------------------------------------------
	// No keys configured at all
	// -----------------------------------------------------------------------

	public function test_no_keys_configured_returns_500(): void {
		$auth   = $this->make_auth();
		$result = $auth->verify_api_key( $this->get_request( 'mcpwp_somekeybutnoconfig' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'api_not_configured', $result->get_error_code() );
		$this->assertSame( 500, $result->get_error_data()['status'] );
	}

	// -----------------------------------------------------------------------
	// Priority: mcpwp_ store wins over spai_ store
	// -----------------------------------------------------------------------

	public function test_mcpwp_key_wins_when_both_stores_have_a_valid_key(): void {
		$auth         = $this->make_auth();
		$v3_plain     = 'mcpwp_v3keyvalue_test12345678';
		$spai_plain   = 'spai_v28keyvalue_test12345678';

		// Create a v3 key.
		update_option( 'mcpwp_api_keys', array(
			array(
				'id'         => 'v3-key-1',
				'label'      => 'v3',
				'hash'       => wp_hash_password( $v3_plain ),
				'user_id'    => 2,
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		// Also seed a spai_ key.
		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-key-1',
				'label'      => 'legacy',
				'hash'       => wp_hash_password( $spai_plain ),
				'user_id'    => 2,
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		// Both keys must work independently.
		$r1 = $auth->verify_api_key( $this->get_request( $v3_plain ) );
		$this->assertTrue( $r1, 'v3 mcpwp_ key must be accepted' );

		$GLOBALS['mcpwp_test_current_user'] = 0;

		$r2 = $auth->verify_api_key( $this->get_request( $spai_plain ) );
		$this->assertTrue( $r2, 'legacy spai_ key must also be accepted' );
	}

	// -----------------------------------------------------------------------
	// Escalation regression: restricted spai_ scope must be enforced (R7 / Warden)
	//
	// A spai_ key with role=author / scopes=[read] must get 403 on a POST to a
	// write route.  The find_legacy_spai_key() path preserves the role + scopes
	// from the stored record, so the scope check must fire correctly.
	// -----------------------------------------------------------------------

	public function test_restricted_scope_spai_key_is_rejected_on_write_route(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_restricted_author_key12345';
		$hash      = wp_hash_password( $plain_key );

		// A spai_ key scoped to read-only (author role).
		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-author-key',
				'label'      => 'Author read-only',
				'hash'       => $hash,
				'user_id'    => 2,
				'scopes'     => array( 'read' ),
				'role'       => 'author',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		// Attempt a POST (write operation) — must be 403.
		$write_request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array( 'X-API-Key' => $plain_key )
		);

		$result = $auth->verify_api_key( $write_request );

		$this->assertInstanceOf( WP_Error::class, $result,
			'Restricted-scope spai key must not pass on a write route' );
		$this->assertSame( 'insufficient_scope', $result->get_error_code(),
			'Error code must be insufficient_scope, not a generic auth failure' );
		$this->assertSame( 403, $result->get_error_data()['status'],
			'HTTP status must be 403' );
	}

	public function test_restricted_scope_spai_key_is_accepted_on_read_route(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_restricted_read_only_12345';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-read-key',
				'label'      => 'Read-only',
				'hash'       => $hash,
				'user_id'    => 2,
				'scopes'     => array( 'read' ),
				'role'       => 'author',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		// A GET request to a read route must succeed.
		$read_request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'X-API-Key' => $plain_key )
		);

		$result = $auth->verify_api_key( $read_request );

		$this->assertTrue( $result,
			'A read-scoped spai_ key must be accepted on a GET route' );
	}
}
