<?php
/**
 * #1178 adversarial-review round: Argon2 and other non-core hash formats, the
 * bridge after adoption, rotation atomicity, bounded retries, and the legacy
 * key cases the first round's tests let through.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';

class Mcpwp_Spai_Review_Test_Auth {
	use Mcpwp_Api_Auth;

	public function store_record( $k ) {
		return $this->find_scoped_api_key( $k );
	}

	public function bridge_record( $k ) {
		return $this->find_legacy_spai_key( $k );
	}
}

final class SpaiAdoptionReview1178Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_options_autoload']   = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$GLOBALS['mcpwp_test_object_cache']       = array();
		$GLOBALS['mcpwp_test_wpdb_col_charset']   = 'utf8mb4';
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
	}

	private function argon( string $key ): string {
		return password_hash( $key, PASSWORD_ARGON2ID );
	}

	private function works( string $key ): bool {
		$auth = new Mcpwp_Spai_Review_Test_Auth();
		$ok   = $auth->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) ) );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		return true === $ok;
	}

	private function store(): array {
		$s = get_option( 'mcpwp_api_keys', array() );
		return is_array( $s ) ? $s : array();
	}

	private function with_hash( string $hash ): array {
		return array_values( array_filter( $this->store(), static function ( $r ) use ( $hash ) {
			return ( $r['hash'] ?? '' ) === $hash;
		} ) );
	}

	private function seed(): void {
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'k1', 'label' => 'x', 'hash' => password_hash( 'zzz', PASSWORD_BCRYPT ), 'scopes' => array( 'read' ), 'role' => 'author', 'revoked_at' => null ) ) );
	}

	/** The first store-lock claim (the rotation's write) succeeds; every later one finds it held. */
	private function block_store_lock_after_first_claim(): void {
		$claims = 0;
		$hook   = null;
		$hook   = static function ( $name ) use ( &$claims, &$hook ) {
			if ( Mcpwp_Key_Store::LOCK !== $name ) {
				$GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
				return;
			}
			$claims++;
			if ( $claims > 1 ) {
				$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|held';
			}
			$GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
		};
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
	}

	private function later_load(): bool {
		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BACKOFF );
		return Mcpwp_Activator::adopt_spai_credentials();
	}

	// ── P1: one predicate; Argon2 ─────────────────────────────────────────

	public function test_the_hash_predicate_covers_every_wordpress_format_and_no_plaintext(): void {
		foreach ( array( '$P$B' . str_repeat( 'a', 28 ), '$H$9' . str_repeat( 'a', 28 ), '$2y$10$' . str_repeat( 'a', 53 ), '$wp$2y$10$' . str_repeat( 'a', 53 ), '$argon2id$v=19$m=65536,t=4,p=1$c2FsdA$aGFzaA' ) as $hash ) {
			$this->assertTrue( Mcpwp_Key_State::is_password_hash_shaped( $hash ), $hash );
		}
		foreach ( array( '', '__NOT_SET__', 'spai_' . str_repeat( 'a', 48 ), 'mcpwp_abc' ) as $plain ) {
			$this->assertFalse( Mcpwp_Key_State::is_password_hash_shaped( $plain ), $plain );
		}
	}

	public function test_argon_store_record_authenticates(): void {
		$key = 'mcpwp_' . str_repeat( 'a', 48 );
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'k1', 'label' => 'x', 'hash' => $this->argon( $key ), 'scopes' => array( 'read', 'write', 'admin' ), 'role' => 'admin', 'revoked_at' => null ) ) );
		update_option( 'mcpwp_spai_credentials_adopted', 1 );
		$this->assertNotNull( ( new Mcpwp_Spai_Review_Test_Auth() )->store_record( $key ), 'An Argon2id scoped record still authenticates.' );
		$this->assertTrue( $this->works( $key ) );
	}

	public function test_a_store_record_never_matches_as_plaintext_but_a_core_md5_hash_still_does(): void {
		$this->seed();
		$store   = $this->store();
		$store[] = array( 'id' => 'junk', 'label' => 'j', 'hash' => 'plaintext-secret-value', 'scopes' => array( 'read' ), 'revoked_at' => null );
		$store[] = array( 'id' => 'md5', 'label' => 'm', 'hash' => md5( 'old-md5-key' ), 'scopes' => array( 'read' ), 'revoked_at' => null );
		update_option( 'mcpwp_api_keys', $store );
		$this->assertFalse( $this->works( 'plaintext-secret-value' ) );
		$this->assertTrue( $this->works( 'old-md5-key' ), 'Core compares a <=32-char stored value as MD5, as main did.' );
	}

	public function test_an_argon_single_spai_key_is_adopted_as_the_hash_it_is(): void {
		$key  = 'spai_' . str_repeat( 'b', 48 );
		$hash = $this->argon( $key );
		$this->seed();
		update_option( 'mcpwp_api_key', password_hash( 'other', PASSWORD_BCRYPT ) );
		update_option( 'spai_api_key', $hash );
		$a = new Mcpwp_Spai_Review_Test_Auth();

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertCount( 1, $this->with_hash( $hash ), 'Stored as the same hash, not re-hashed as "plaintext".' );
		$this->assertNotNull( $a->store_record( $key ), 'The real key keeps working.' );
		$this->assertNull( $a->store_record( $hash ), 'The stored hash string is never a credential.' );
		$this->assertFalse( $this->works( $hash ) );
		$this->assertTrue( $this->works( $key ) );
	}

	public function test_an_argon_spai_api_keys_record_is_adopted_not_erased(): void {
		$key = 'spai_' . str_repeat( 'e', 48 );
		$this->seed();
		update_option( 'spai_api_keys', array( array( 'id' => 'a1', 'hash' => $this->argon( $key ), 'scopes' => array( 'read' ), 'role' => 'author', 'revoked_at' => null ) ) );
		$this->assertTrue( $this->works( $key ), 'Precondition: the 2.8 key works.' );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertSame( '__gone__', get_option( 'spai_api_keys', '__gone__' ) );
		$this->assertTrue( $this->works( $key ), 'It was written to the store before the option went.' );
	}

	public function test_an_argon_designer_record_that_twins_the_single_key_keeps_its_role(): void {
		$key  = 'spai_' . str_repeat( 'c', 48 );
		$hash = $this->argon( $key );
		$this->seed();
		update_option( 'spai_api_keys', array( array( 'id' => 'd1', 'hash' => $hash, 'scopes' => array( 'read' ), 'role' => 'designer', 'revoked_at' => null ) ) );
		update_option( 'spai_api_key', $hash );
		$a = new Mcpwp_Spai_Review_Test_Auth();
		$this->assertSame( 'designer', $a->bridge_record( $key )['role'] ?? null, 'The bridge must find the designer record, not the admin fallback.' );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertSame( 'designer', $a->store_record( $key )['role'] ?? null );
		$this->assertCount( 1, $this->with_hash( $hash ), 'One record, not an extra admin one.' );
	}

	// ── P2-1: the bridge after adoption ───────────────────────────────────

	public function test_after_adoption_a_spai_key_that_reappears_is_refused(): void {
		$this->seed();
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		// A reactivated 2.8 plugin mints a fresh key.
		$fresh = 'spai_' . str_repeat( 'f', 48 );
		update_option( 'spai_api_key', password_hash( $fresh, PASSWORD_BCRYPT ) );
		update_option( 'spai_api_keys', array( array( 'id' => 'n1', 'hash' => password_hash( $fresh, PASSWORD_BCRYPT ), 'revoked_at' => null ) ) );

		$this->assertNull( ( new Mcpwp_Spai_Review_Test_Auth() )->bridge_record( $fresh ) );
		$this->assertFalse( $this->works( $fresh ), 'Not an unlisted admin key.' );
	}

	public function test_before_adoption_finishes_the_bridge_still_honours_an_uncopied_key(): void {
		$this->seed();
		$key = 'spai_' . str_repeat( 'g', 48 );
		update_option( 'spai_api_key', password_hash( $key, PASSWORD_BCRYPT ) );
		$this->assertTrue( $this->works( $key ) );
	}

	// ── P2-3: the rotation carries the spai placeholders ──────────────────

	public function test_a_rotation_writes_the_spai_placeholders_in_its_own_write(): void {
		$this->seed();
		$auth   = new Mcpwp_Spai_Review_Test_Auth();
		$single = 'spai_' . str_repeat( 'h', 48 );
		update_option( 'spai_api_key', password_hash( $single, PASSWORD_BCRYPT ) );
		// Both the later mutate and the delete fail: nothing but the rotation write lands.
		$GLOBALS['mcpwp_test_delete_option_fail']['spai_api_key'] = true;
		$this->block_store_lock_after_first_claim();

		$new = $auth->regenerate_api_key();

		$this->assertNotSame( '', $new );
		$hash   = get_option( 'spai_api_key' );
		$stored = $this->with_hash( $hash );
		$this->assertCount( 1, $stored, 'Placeholder is in the store.' );
		$this->assertNotEmpty( $stored[0]['revoked_at'] );
		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] );
		$this->assertTrue( $this->later_load() );
		$this->assertFalse( $this->works( $single ), 'The next adoption never takes the old single key as live.' );
		$this->assertTrue( $this->works( $new ) );
	}

	public function test_a_rotation_with_a_failed_options_delete_leaves_no_live_spai_store_record(): void {
		$this->seed();
		$auth  = new Mcpwp_Spai_Review_Test_Auth();
		$multi = 'spai_' . str_repeat( 'i', 48 );
		update_option( 'spai_api_keys', array( array( 'id' => 'l1', 'hash' => password_hash( $multi, PASSWORD_BCRYPT ), 'revoked_at' => null ) ) );
		$GLOBALS['mcpwp_test_delete_option_fail']['spai_api_keys'] = true;
		$this->block_store_lock_after_first_claim();
		$auth->regenerate_api_key();
		$this->assertFalse( $this->works( $multi ), 'Only the rotation write landed (the later sweep and the delete both failed).' );
		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] );
		$this->later_load();
		$this->assertFalse( $this->works( $multi ) );
	}

	// ── P2-4: bounded work while adoption is unfinished ───────────────────

	public function test_a_failed_attempt_is_not_repeated_on_every_load(): void {
		$this->seed();
		update_option( 'spai_api_key', 'spai_' . str_repeat( 'j', 48 ) ); // Plaintext: the expensive case.
		$GLOBALS['mcpwp_test_delete_option_fail']['spai_api_key'] = true;
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );

		$claimed = false;
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = static function () use ( &$claimed ) {
			$claimed = true; // The store lock claim.
		};
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials(), 'Within the back-off: not attempted.' );
		$this->assertFalse( $claimed, 'No lock, no hashing, no verifies.' );

		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$this->assertTrue( $this->later_load(), 'After the back-off it completes.' );
	}

	// ── P2-5: legacy key cases ────────────────────────────────────────────

	public function test_a_legacy_key_revoked_in_spai_does_not_move_in_live(): void {
		$legacy = 'mcpwp_' . str_repeat( 'l', 48 );
		$lhash  = password_hash( $legacy, PASSWORD_BCRYPT );
		update_option( 'mcpwp_api_key', $lhash );
		update_option( 'spai_api_keys', array(
			array( 'id' => 'p', 'hash' => $lhash, 'revoked_at' => '2026-01-01 00:00:00' ),
			array( 'id' => 'o', 'hash' => password_hash( 'spai_' . str_repeat( 'm', 48 ), PASSWORD_BCRYPT ), 'revoked_at' => null ),
		) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$records = $this->with_hash( $lhash );
		$this->assertCount( 1, $records );
		$this->assertNotEmpty( $records[0]['revoked_at'], 'Revoked in spai: not live.' );
		$this->assertFalse( $this->works( $legacy ) );
	}

	public function test_a_plaintext_legacy_key_revoked_in_spai_does_not_move_in_live(): void {
		$legacy = 'mcpwp_' . str_repeat( 'n', 48 );
		update_option( 'mcpwp_api_key', $legacy ); // Plaintext: its hash would be a fresh salt.
		update_option( 'spai_api_keys', array(
			array( 'id' => 'p', 'hash' => password_hash( $legacy, PASSWORD_BCRYPT ), 'revoked_at' => '2026-01-01 00:00:00' ),
			array( 'id' => 'o', 'hash' => password_hash( 'spai_' . str_repeat( 'o', 48 ), PASSWORD_BCRYPT ), 'revoked_at' => null ),
		) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		foreach ( $this->store() as $r ) {
			if ( password_verify( $legacy, $r['hash'] ) ) {
				$this->assertNotEmpty( $r['revoked_at'], 'No live record for it.' );
			}
		}
		$this->assertFalse( $this->works( $legacy ) );
	}

	public function test_a_legacy_key_is_not_added_to_a_store_that_already_has_records(): void {
		$legacy = 'mcpwp_' . str_repeat( 'p', 48 );
		$lhash  = password_hash( $legacy, PASSWORD_BCRYPT );
		update_option( 'mcpwp_api_key', $lhash );
		$this->seed();
		$store   = $this->store();
		$store[] = array( 'id' => 'old', 'label' => 'old', 'hash' => $lhash, 'scopes' => array( 'read' ), 'revoked_at' => '2026-01-01 00:00:00' );
		update_option( 'mcpwp_api_keys', $store );
		update_option( 'spai_api_key', password_hash( 'spai_' . str_repeat( 'q', 48 ), PASSWORD_BCRYPT ) );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertCount( 1, $this->with_hash( $lhash ) );
		$this->assertFalse( $this->works( $legacy ), 'Revoked stays revoked.' );
	}

	public function test_a_legacy_key_the_store_does_not_hold_stays_out_of_a_non_empty_store(): void {
		$legacy = 'mcpwp_' . str_repeat( 't', 48 );
		update_option( 'mcpwp_api_key', password_hash( $legacy, PASSWORD_BCRYPT ) );
		$this->seed(); // A non-empty store: the legacy option is not honoured on this site.
		update_option( 'spai_api_key', password_hash( 'spai_' . str_repeat( 'u', 48 ), PASSWORD_BCRYPT ) );
		$this->assertFalse( $this->works( $legacy ), 'Precondition.' );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertFalse( $this->works( $legacy ), 'Adoption must not make it live.' );
		$this->assertCount( 2, $this->store() );
	}

	public function test_a_spai_record_equal_to_the_legacy_key_leaves_one_record(): void {
		$legacy = 'mcpwp_' . str_repeat( 'r', 48 );
		$lhash  = password_hash( $legacy, PASSWORD_BCRYPT );
		update_option( 'mcpwp_api_key', $lhash );
		update_option( 'spai_api_keys', array(
			array( 'id' => 'same', 'hash' => $lhash, 'revoked_at' => null ),
			array( 'id' => 'other', 'hash' => password_hash( 'spai_' . str_repeat( 's', 48 ), PASSWORD_BCRYPT ), 'revoked_at' => null ),
		) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertCount( 1, $this->with_hash( $lhash ), 'Never two live records for one key.' );
		$this->assertTrue( $this->works( $legacy ) );
	}

	public function test_the_bridge_never_matches_a_spai_api_keys_record_as_plaintext(): void {
		$this->seed();
		update_option( 'spai_api_keys', array( array( 'id' => 'pl', 'hash' => 'plain-secret-value', 'revoked_at' => null ) ) );
		$this->assertNull( ( new Mcpwp_Spai_Review_Test_Auth() )->bridge_record( 'plain-secret-value' ) );
		$this->assertFalse( $this->works( 'plain-secret-value' ) );
	}
}
