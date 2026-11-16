<?php

/**
 * Tests for 2.8 → scoped-store credential adoption (#505 / #1098 / #1120).
 *
 * Auth no longer reads spai_api_keys / spai_api_key at request time. A leftover
 * 2.8 credential is refused until adopt_spai_credentials() writes it into the
 * scoped store; after adoption the same plaintext key is accepted there.
 *
 * Covers:
 *   - A current mcpwp_ key stored in mcpwp_api_keys is accepted.
 *   - A leftover spai_ key is refused before adoption and accepted after.
 *   - A bad / unknown key is rejected with 401.
 *   - A revoked spai_ record is adopted revoked (still refused).
 *   - Both stores empty → 500 (no keys configured).
 *   - Adopted scoped keys keep their role / scopes for write gates.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class BridgeAuthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
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

	private function plant_spai_scoped( string $plain_key, array $extra = array() ): void {
		$record = array_merge(
			array(
				'id'         => 'spai-key-1',
				'label'      => 'Legacy scoped key',
				'hash'       => wp_hash_password( $plain_key ),
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
			$extra
		);
		update_option( 'spai_api_keys', array( $record ) );
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
	// Leftover spai_ multi-key store (spai_api_keys): refuse → adopt → accept
	// -----------------------------------------------------------------------

	public function test_legacy_spai_scoped_key_is_refused_before_adoption_and_accepted_after(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_abc123legacy_scoped_key';
		$this->plant_spai_scoped( $plain_key );
		// Seed a mcpwp_ key so has_configured_api_keys() is true (401, not 500).
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );

		$before = $auth->verify_api_key( $this->get_request( $plain_key ) );
		$this->assertInstanceOf( WP_Error::class, $before, 'Before adoption the old options are not an auth source' );
		$this->assertSame( 'invalid_api_key', $before->get_error_code() );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}

		$result = $auth->verify_api_key( $this->get_request( $plain_key ) );
		$this->assertTrue( $result, 'After adoption the same key authenticates from the scoped store' );
	}

	public function test_legacy_spai_scoped_key_sets_user_context_after_adoption(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_userset_test_key_value12';
		$this->plant_spai_scoped( $plain_key, array( 'id' => 'spai-key-ctx', 'label' => 'Context key' ) );
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$auth->verify_api_key( $this->get_request( $plain_key ) );

		$this->assertNotSame( 0, $GLOBALS['mcpwp_test_current_user'],
			'verify_api_key must set the API user context for an adopted key' );
	}

	// -----------------------------------------------------------------------
	// Leftover spai_ single key (spai_api_key)
	// -----------------------------------------------------------------------

	public function test_legacy_spai_single_key_is_refused_before_adoption_and_accepted_after(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_singlekeylegacy_test1234';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_key', $hash );
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );

		$before = $auth->verify_api_key( $this->get_request( $plain_key ) );
		$this->assertInstanceOf( WP_Error::class, $before );
		$this->assertSame( 'invalid_api_key', $before->get_error_code() );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}

		$result = $auth->verify_api_key( $this->get_request( $plain_key ) );
		$this->assertTrue( $result, 'After adoption a former spai_api_key authenticates from the scoped store' );
	}

	public function test_legacy_spai_single_key_gets_admin_scope_on_write_after_adoption(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_singlewrite_test_key1234';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_key', $hash );
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array( 'X-API-Key' => $plain_key )
		);

		$result = $auth->verify_api_key( $request );

		// Adopted single key keeps admin scopes → write is allowed.
		$this->assertTrue( $result, 'Adopted spai_api_key single key must have write scope' );
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

		$before = $auth->verify_api_key( $this->get_request( $plain_key ) );
		$this->assertInstanceOf( WP_Error::class, $before );
		$this->assertSame( 'invalid_api_key', $before->get_error_code() );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}

		$result = $auth->verify_api_key( $this->get_request( $plain_key ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_api_key', $result->get_error_code() );
	}

	public function test_bad_key_against_spai_store_is_rejected(): void {
		$auth = $this->make_auth();

		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-good',
				'label'      => 'Good',
				'hash'       => wp_hash_password( 'spai_goodkey_12345678901234' ),
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		// Also seed one mcpwp_ key so has_configured_api_keys() returns true.
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );

		// Before adoption even the correct key is refused.
		$pre = $auth->verify_api_key( $this->get_request( 'spai_goodkey_12345678901234' ) );
		$this->assertInstanceOf( WP_Error::class, $pre );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}

		// Correct key → accepted after adoption.
		$good = $auth->verify_api_key( $this->get_request( 'spai_goodkey_12345678901234' ) );
		$this->assertTrue( $good );

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
	// Priority: mcpwp_ store works; spai leftovers need adoption
	// -----------------------------------------------------------------------

	public function test_mcpwp_key_works_while_spai_leftover_needs_adoption(): void {
		$auth         = $this->make_auth();
		$v3_plain     = 'mcpwp_v3keyvalue_test12345678';
		$spai_plain   = 'spai_v28keyvalue_test12345678';

		update_option( 'mcpwp_api_keys', array(
			array(
				'id'         => 'v3-key-1',
				'label'      => 'v3',
				'hash'       => wp_hash_password( $v3_plain ),
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-key-1',
				'label'      => 'legacy',
				'hash'       => wp_hash_password( $spai_plain ),
				'scopes'     => array( 'read', 'write', 'admin' ),
				'role'       => 'admin',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );

		$r1 = $auth->verify_api_key( $this->get_request( $v3_plain ) );
		$this->assertTrue( $r1, 'v3 mcpwp_ key must be accepted' );

		$GLOBALS['mcpwp_test_current_user'] = 0;

		$r2 = $auth->verify_api_key( $this->get_request( $spai_plain ) );
		$this->assertInstanceOf( WP_Error::class, $r2, 'Leftover spai key is refused before adoption' );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}

		$r3 = $auth->verify_api_key( $this->get_request( $spai_plain ) );
		$this->assertTrue( $r3, 'After adoption the former spai key is accepted from the scoped store' );
	}

	// -----------------------------------------------------------------------
	// Restricted scope survives adoption
	// -----------------------------------------------------------------------

	public function test_restricted_scope_spai_key_is_rejected_on_write_route_after_adoption(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_restricted_author_key12345';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-author-key',
				'label'      => 'Author read-only',
				'hash'       => $hash,
				'scopes'     => array( 'read' ),
				'role'       => 'author',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$write_request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts',
			array(),
			array( 'X-API-Key' => $plain_key )
		);

		$result = $auth->verify_api_key( $write_request );

		$this->assertInstanceOf( WP_Error::class, $result,
			'Restricted-scope adopted key must not pass on a write route' );
		$this->assertSame( 'insufficient_scope', $result->get_error_code(),
			'Error code must be insufficient_scope, not a generic auth failure' );
		$this->assertSame( 403, $result->get_error_data()['status'],
			'HTTP status must be 403' );
	}

	public function test_restricted_scope_spai_key_is_accepted_on_read_route_after_adoption(): void {
		$auth      = $this->make_auth();
		$plain_key = 'spai_restricted_read_only_12345';
		$hash      = wp_hash_password( $plain_key );

		update_option( 'spai_api_keys', array(
			array(
				'id'         => 'spai-read-key',
				'label'      => 'Read-only',
				'hash'       => $hash,
				'scopes'     => array( 'read' ),
				'role'       => 'author',
				'created_at' => '2026-01-01 00:00:00',
				'revoked_at' => null,
			),
		) );
		$auth->create_scoped_api_key( 'mcpwp sentinel', array( 'read' ) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$read_request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/site-info',
			array(),
			array( 'X-API-Key' => $plain_key )
		);

		$result = $auth->verify_api_key( $read_request );

		$this->assertTrue( $result,
			'A read-scoped adopted key must be accepted on a GET route' );
	}
}
