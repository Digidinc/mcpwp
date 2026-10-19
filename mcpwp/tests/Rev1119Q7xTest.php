<?php
/**
 * #1119 (#1098) round-2 review repros, copied from kasra-review's
 * Rev1119Q7xTest.php. Debug output removed; every test asserts a verdict.
 * Two adaptations, both to the real semantics of the fix:
 *  - adoption now completes on a LATER run (originals are deleted only once
 *    every adopted credential is confirmed still in the store), so a first
 *    run is asserted to have adopted, not to have finished;
 *  - R2's "concurrent" revoke runs inside adoption's own store write, i.e.
 *    while adoption holds the key-store lock, so it is now REFUSED (busy)
 *    instead of being silently undone. The verdict asserted is the
 *    invariant the bug broke: a revoke that reports success stays revoked.
 *
 * @package MCPWP
 */
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-migrate.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';
class Rev1119Q7x_Auth { use Mcpwp_Api_Auth; public function ao( $r ) { return $this->verify_api_key_auth_only( $r ); } }
final class Rev1119Q7xTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array(); $GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_update_option_fail'] = array(); $GLOBALS['mcpwp_test_current_user'] = 0;
		unset( $GLOBALS['mcpwp_test_option_store_hook'] );
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) { Mcpwp_REST_MCP::reset_verdict_memo(); }
	}
	protected function tearDown(): void { unset( $GLOBALS['mcpwp_test_option_store_hook'] ); }
	private function works( $k ) {
		$a = new Rev1119Q7x_Auth();
		$r = $a->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $k ) ) );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		return true === $r;
	}
	// R0 (found in round 2): a site with spai_api_keys but NO spai_api_key —
	// the absent-option sentinel was adopted as a key whose secret was '__NOT_SET__'.
	public function test_R0_absent_single_key_sentinel_never_authenticates(): void {
		$a = new Rev1119Q7x_Auth(); $a->create_scoped_api_key( 'x', array( 'read' ), 'author' );
		update_option( 'spai_api_keys', array( array( 'id' => 'm1', 'hash' => wp_hash_password( 'spai_' . str_repeat( '56', 24 ) ), 'revoked_at' => null ) ) );
		for ( $i = 0; $i < 3 && ! Mcpwp_Activator::adopt_spai_credentials(); $i++ ) {}
		$this->assertFalse( $this->works( '__NOT_SET__' ), 'The sentinel must never be a key' );
		foreach ( get_option( 'mcpwp_api_keys' ) as $r ) {
			$this->assertSame( 1, preg_match( '/^\$/', (string) $r['hash'] ), 'every stored value is a hash' );
		}
	}

	// R1: 2.8 site: primary key K revoked in spai_api_keys, its hash still in spai_api_key (2.8 left the legacy option in place on revoke).
	public function test_R1_key_revoked_in_28_is_adopted_active(): void {
		$k = 'spai_' . str_repeat( 'ab', 24 ); $h = wp_hash_password( $k );
		update_option( 'spai_api_keys', array( array( 'id' => 'p28', 'label' => 'Primary API Key', 'hash' => $h, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => '2026-01-01 00:00:00' ) ) );
		update_option( 'spai_api_key', $h );
		// activate(): is_fresh_site() ignores spai -> mint + ensure_scoped (simulated via public API)
		$this->assertTrue( Mcpwp_Key_State::is_fresh_site() );
		$a = new Rev1119Q7x_Auth(); $c = $a->create_scoped_api_key( 'Primary API Key', array( 'read', 'write', 'admin' ) );
		update_option( 'mcpwp_api_key', wp_hash_password( $c['key'] ) );
		Mcpwp_Migrate::run();
		Mcpwp_Activator::adopt_spai_credentials();
		$this->assertFalse( $this->works( $k ), 'Key revoked in 2.8 must not be an active record after adoption' );
	}
	// R2: concurrent revoke lands between adoption's re-read and its write -> lost update revives the revoked key.
	public function test_R2_revoke_lost_by_adoption_write(): void {
		$a = new Rev1119Q7x_Auth(); $c = $a->create_scoped_api_key( 'victim', array( 'read', 'write', 'admin' ) );
		update_option( 'spai_api_key', wp_hash_password( 'spai_' . str_repeat( 'cd', 24 ) ) );
		$fired = false;
		$GLOBALS['mcpwp_test_option_store_hook'] = function ( $name, $value ) use ( $a, $c, &$fired ) {
			if ( 'mcpwp_api_keys' === $name && ! $fired && is_array( $value ) && count( $value ) > 1 ) {
				$fired = true; unset( $GLOBALS['mcpwp_test_option_store_hook'] );
				$GLOBALS['r2_revoked'] = $a->revoke_scoped_api_key( $c['id'] ); // request B tries to revoke mid-write
				$GLOBALS['r2_busy']    = method_exists( $a, 'key_store_was_busy' ) && $a->key_store_was_busy();
				$GLOBALS['mcpwp_test_option_store_hook'] = function ( $n, $v ) { return $v; };
			}
			return $value;
		};
		add_filter( 'mcpwp_key_store_lock_wait_ms', static function () { return 0; } ); // In-process: no one else can release it.
		Mcpwp_Activator::adopt_spai_credentials();
		unset( $GLOBALS['mcpwp_test_filters']['mcpwp_key_store_lock_wait_ms'] );
		$this->assertTrue( $fired );
		if ( ! empty( $GLOBALS['r2_revoked'] ) ) {
			$this->assertFalse( $this->works( $c['key'] ), 'A revoke that REPORTED success must stay revoked' );
		} else {
			$this->assertTrue( $GLOBALS['r2_busy'], 'A refused revoke says the store was busy (not "not found")' );
			$this->assertTrue( $a->revoke_scoped_api_key( $c['id'] ), 'Retried once the lock is free: revoked' );
			$this->assertFalse( $this->works( $c['key'] ) );
		}
		unset( $GLOBALS['r2_revoked'], $GLOBALS['r2_busy'] );
	}
	// R3: a revoke request with a pre-adoption snapshot writes after adoption -> adopted spai keys lost, originals deleted (lockout).
	public function test_R3_stale_writer_after_adoption_loses_spai_key(): void {
		$a = new Rev1119Q7x_Auth(); $c = $a->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		$k = 'spai_' . str_repeat( 'ef', 24 ); update_option( 'spai_api_key', wp_hash_password( $k ) );
		$stale = get_option( 'mcpwp_api_keys' );
		Mcpwp_Activator::adopt_spai_credentials();
		$this->assertCount( 2, get_option( 'mcpwp_api_keys' ), 'Adopted on the first run' );
		$stale[0]['revoked_at'] = '2026-10-18 00:00:00'; update_option( 'mcpwp_api_keys', $stale ); // B's revoke from stale read
		$this->assertTrue( $this->works( $k ), 'live 2.8 key must survive (lockout otherwise)' );
	}
	// R4: single spai key stored plaintext (pre-hash 2.8) is copied verbatim into the store's hash field.
	public function test_R4_plaintext_spai_single_copied_unhashed(): void {
		$a = new Rev1119Q7x_Auth(); $a->create_scoped_api_key( 'x', array( 'read' ), 'author' );
		$k = 'spai_' . str_repeat( '12', 24 ); update_option( 'spai_api_key', $k );
		$this->assertTrue( $this->works( $k ) );
		Mcpwp_Activator::adopt_spai_credentials();
		$hashes = array_column( get_option( 'mcpwp_api_keys' ), 'hash' );
		$this->assertNotContains( $k, $hashes, 'plaintext secret stored in mcpwp_api_keys.hash' );
	}
	// R5: privilege preservation for a restricted spai record.
	public function test_R5_restricted_record_keeps_authority(): void {
		$a = new Rev1119Q7x_Auth(); $a->create_scoped_api_key( 'x', array( 'read' ), 'author' );
		$k = 'spai_' . str_repeat( '34', 24 ); $h = wp_hash_password( $k );
		$rec = array( 'id' => 'rr', 'hash' => $h, 'scopes' => array( 'read' ), 'role' => 'custom', 'tool_categories' => array( 'pages' ), 'rate_limits' => array( 'burst' => 3 ), 'allow_destructive' => false, 'revoked_at' => null );
		update_option( 'spai_api_keys', array( $rec ) );
		$m = new ReflectionMethod( $a, 'find_legacy_spai_key' ); $m->setAccessible( true ); $before = $m->invoke( $a, $k );
		Mcpwp_Activator::adopt_spai_credentials();
		$m2 = new ReflectionMethod( $a, 'find_scoped_api_key' ); $m2->setAccessible( true ); $after = $m2->invoke( $a, $k );
		foreach ( array( 'scopes', 'role', 'tool_categories', 'rate_limits', 'allow_destructive', 'id' ) as $f ) { $this->assertSame( $before[ $f ], $after[ $f ], $f ); }
	}
}
