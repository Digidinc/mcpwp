<?php
/**
 * The legacy single-key option `mcpwp_api_key` must not outlive revocation.
 *
 * regenerate_api_key() writes the new primary key twice: as a scoped record
 * and as a hash in `mcpwp_api_key`. find_scoped_api_key() skips revoked
 * records, so a revoked primary key used to fall through to the legacy
 * branch, match the hash still sitting in `mcpwp_api_key`, and be re-created
 * by migrate_legacy_key_to_scoped_store() as a brand-new ACTIVE admin record
 * — revoking a primary key did not revoke it.
 *
 * The legacy option is now honoured only on a site whose scoped store is
 * still empty (a genuine pre-multi-key install, migrated once on first use),
 * and revoking a record clears a matching legacy hash.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

class Mcpwp_Legacy_Key_Test_Auth {
	use Mcpwp_Api_Auth;

	public function auth_only( $request ) {
		return $this->verify_api_key_auth_only( $request );
	}
}

final class LegacyKeyRevocationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_user_caps']    = array();
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	private function request( string $key ): WP_REST_Request {
		return new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) );
	}

	private function record_id_for_label( $auth, string $label ): string {
		foreach ( $auth->list_scoped_api_keys( false ) as $key ) {
			if ( $label === $key['label'] ) {
				return $key['id'];
			}
		}
		return '';
	}

	private function active_count( $auth ): int {
		return count( $auth->list_scoped_api_keys( false ) );
	}

	public function test_a_revoked_primary_key_stays_revoked(): void {
		$auth    = new Mcpwp_Legacy_Key_Test_Auth();
		$primary = $auth->regenerate_api_key();
		$this->assertTrue( $auth->verify_api_key( $this->request( $primary ) ) );

		$auth->revoke_scoped_api_key( $this->record_id_for_label( $auth, 'Primary API Key' ) );

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $primary ) ) );
		$this->assertSame( 0, $this->active_count( $auth ), 'No record may be re-created for a revoked key.' );
	}

	public function test_a_revoked_primary_key_stays_revoked_on_the_sub_request_path(): void {
		$auth    = new Mcpwp_Legacy_Key_Test_Auth();
		$primary = $auth->regenerate_api_key();
		$auth->revoke_scoped_api_key( $this->record_id_for_label( $auth, 'Primary API Key' ) );

		$this->assertInstanceOf( WP_Error::class, $auth->auth_only( $this->request( $primary ) ) );
		$this->assertSame( 0, $this->active_count( $auth ) );
	}

	public function test_revoking_the_primary_record_clears_the_legacy_hash(): void {
		$auth    = new Mcpwp_Legacy_Key_Test_Auth();
		$auth->regenerate_api_key();
		$this->assertNotEmpty( get_option( 'mcpwp_api_key' ) );

		$auth->revoke_scoped_api_key( $this->record_id_for_label( $auth, 'Primary API Key' ) );

		$this->assertEmpty( get_option( 'mcpwp_api_key' ) );
	}

	public function test_a_legacy_hash_with_no_record_is_refused_once_scoped_keys_exist(): void {
		$auth  = new Mcpwp_Legacy_Key_Test_Auth();
		$auth->create_scoped_api_key( 'some integration', array( 'read' ), 'author' );
		$stray = 'mcpwp_' . str_repeat( 'ab', 24 );
		update_option( 'mcpwp_api_key', wp_hash_password( $stray ) );

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $stray ) ) );
		$this->assertSame( 1, $this->active_count( $auth ) );
	}

	public function test_a_legacy_hash_with_no_record_is_refused_on_the_sub_request_path(): void {
		$auth  = new Mcpwp_Legacy_Key_Test_Auth();
		$auth->create_scoped_api_key( 'some integration', array( 'read' ), 'author' );
		$stray = 'mcpwp_' . str_repeat( '9a', 24 );
		update_option( 'mcpwp_api_key', wp_hash_password( $stray ) );

		$this->assertInstanceOf( WP_Error::class, $auth->auth_only( $this->request( $stray ) ) );
		$this->assertSame( 1, $this->active_count( $auth ) );
	}

	public function test_a_pre_multi_key_site_still_migrates_its_legacy_key_once(): void {
		$auth   = new Mcpwp_Legacy_Key_Test_Auth();
		$legacy = 'mcpwp_' . str_repeat( 'cd', 24 );
		update_option( 'mcpwp_api_key', wp_hash_password( $legacy ) );

		$this->assertTrue( $auth->verify_api_key( $this->request( $legacy ) ) );
		$this->assertSame( 1, $this->active_count( $auth ) );

		// Second use: matched by the migrated record, not re-migrated.
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertTrue( $auth->verify_api_key( $this->request( $legacy ) ) );
		$this->assertSame( 1, $this->active_count( $auth ) );
	}

	public function test_a_plaintext_legacy_key_on_a_pre_multi_key_site_still_works(): void {
		$auth   = new Mcpwp_Legacy_Key_Test_Auth();
		$legacy = 'mcpwp_' . str_repeat( 'ef', 24 );
		update_option( 'mcpwp_api_key', $legacy );

		$this->assertTrue( $auth->verify_api_key( $this->request( $legacy ) ) );
		$this->assertNotSame( $legacy, get_option( 'mcpwp_api_key' ), 'Plaintext is hashed on first use.' );
	}
}
