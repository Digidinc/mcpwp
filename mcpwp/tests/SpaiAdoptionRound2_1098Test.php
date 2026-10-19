<?php
/**
 * #1119 (#1098) round 2 — the review's P0 and P1s, pinned from both sides:
 * the adoption step and the bridge it falls back to.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';

class Mcpwp_Spai_Round2_Test_Auth {
	use Mcpwp_Api_Auth;

	public function bridge( $key ) {
		return $this->find_legacy_spai_key( $key );
	}

	public function migrate_legacy( $value ) {
		return $this->migrate_legacy_key_to_scoped_store( $value );
	}
}

final class SpaiAdoptionRound2_1098Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_object_cache']       = array();
		$GLOBALS['mcpwp_test_update_option_fail'] = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_filters']            = array();
		add_filter( 'mcpwp_key_store_lock_wait_ms', static function () { return 0; } ); // No one else can release it in-process.
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert']  = null;
		$GLOBALS['mcpwp_test_object_cache']        = array();
		$GLOBALS['mcpwp_test_current_user']        = 0;
	}

	private function works( string $key ): bool {
		$r = ( new Mcpwp_Spai_Round2_Test_Auth() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) ) );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		return true === $r;
	}

	private function adopt(): bool {
		for ( $i = 0; $i < 3; $i++ ) {
			if ( Mcpwp_Activator::adopt_spai_credentials() ) {
				return true;
			}
		}
		return false;
	}

	private function store(): array {
		$s = get_option( 'mcpwp_api_keys', array() );
		return is_array( $s ) ? $s : array();
	}

	private function hold_lock(): void {
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_State::STORE_LOCK_OPTION ] = (string) ( time() + 60 );
	}

	private function key( string $tag ): string {
		return 'spai_' . str_repeat( $tag, 24 );
	}

	// ── P0: a revoked spai record blocks its single-key twin ──────────────

	private function revoked_primary_with_twin( string $key ): string {
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_keys', array( array( 'id' => 'p28', 'label' => 'Primary API Key', 'hash' => $hash, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => '2026-01-01 00:00:00' ) ) );
		update_option( 'spai_api_key', $hash );
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'current primary', array( 'read', 'write', 'admin' ) );
		return $hash;
	}

	public function test_the_bridge_refuses_a_single_key_whose_spai_record_is_revoked(): void {
		$key = $this->key( 'a1' );
		$this->revoked_primary_with_twin( $key );
		$this->assertNull( ( new Mcpwp_Spai_Round2_Test_Auth() )->bridge( $key ), 'Bridge side: no record for the revoked twin.' );
		$this->assertFalse( $this->works( $key ) );
	}

	public function test_adoption_never_adopts_the_twin_of_a_revoked_spai_record(): void {
		$key  = $this->key( 'a2' );
		$hash = $this->revoked_primary_with_twin( $key );
		$this->assertTrue( $this->adopt() );
		foreach ( $this->store() as $record ) {
			$this->assertNotSame( $hash, $record['hash'] ?? '', 'Adoption side: no record carries the revoked hash.' );
		}
		$this->assertSame( '__gone__', get_option( 'spai_api_key', '__gone__' ) );
		$this->assertFalse( $this->works( $key ), 'Dead after the originals are gone, too.' );
	}

	// ── P1-a: plaintext single key ────────────────────────────────────────

	public function test_a_plaintext_single_key_matching_a_revoked_record_is_refused_both_sides(): void {
		$key = $this->key( 'b1' );
		foreach ( array( 'store' => 'mcpwp_api_keys', 'spai' => 'spai_api_keys' ) as $where => $option ) {
			$GLOBALS['mcpwp_test_options'] = array();
			( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
			$revoked = array( 'id' => 'rv', 'hash' => wp_hash_password( $key ), 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => '2026-01-01 00:00:00' );
			update_option( $option, 'mcpwp_api_keys' === $option ? array_merge( $this->store(), array( $revoked ) ) : array( $revoked ) );
			update_option( 'spai_api_key', $key ); // Pre-1.0.14: stored in plaintext.
			$this->assertFalse( $this->works( $key ), "$where: the bridge refuses it." );
			$this->assertTrue( $this->adopt(), $where );
			$this->assertSame( 1, count( array_filter( $this->store(), static function ( $r ) {
				return empty( $r['revoked_at'] );
			} ) ), "$where: only the unrelated key is active." );
			$this->assertFalse( $this->works( $key ), "$where: still dead." );
		}
	}

	public function test_a_plaintext_single_key_is_adopted_hashed_and_only_once(): void {
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		$key = $this->key( 'b2' );
		update_option( 'spai_api_key', $key );
		$this->assertTrue( $this->adopt() );
		$adopted = array_values( array_filter( $this->store(), static function ( $r ) {
			return 'spai' === ( $r['migrated_from'] ?? '' );
		} ) );
		$this->assertCount( 1, $adopted, 'Adopted once, not again on the confirming run.' );
		$this->assertNotSame( $key, $adopted[0]['hash'] );
		$this->assertTrue( wp_check_password( $key, $adopted[0]['hash'] ), 'Stored as a hash of the key.' );
		$this->assertTrue( $this->works( $key ) );
	}

	public function test_a_dollar_prefixed_plaintext_single_key_is_adopted_hashed(): void {
		// '$' alone does not make a hash: a plaintext key that happens to
		// start with it is still a plaintext key, and gets hashed.
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		$key = '$plain-' . str_repeat( 'h1', 20 );
		update_option( 'spai_api_key', $key );
		$this->assertTrue( $this->works( $key ), 'The bridge honours it as plaintext.' );
		$this->assertTrue( $this->adopt(), 'Adoption completes.' );
		$this->assertTrue( $this->works( $key ), 'Still works: adopted, hashed.' );
		$this->assertNotContains( $key, array_column( $this->store(), 'hash' ) );
	}

	public function test_the_chatgpt_link_survives_a_busy_revoke(): void {
		$auth = new class() {
			public $busy = true;
			public $created = 0;
			public function revoke_scoped_api_key( $id ) {
				return false;
			}
			public function key_store_was_busy() {
				return $this->busy;
			}
			public function create_scoped_api_key( ...$args ) {
				$this->created++;
				return array( 'id' => 'new', 'key' => 'k' );
			}
			public function list_scoped_api_keys( $all = false ) {
				return array();
			}
		};
		require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-chatgpt-connector.php';
		update_option( Mcpwp_ChatGPT_Connector::OPTION_KEY_ID, 'old' );
		$connector = new Mcpwp_ChatGPT_Connector( $auth );
		$this->assertFalse( $connector->revoke() );
		$this->assertSame( 'old', get_option( Mcpwp_ChatGPT_Connector::OPTION_KEY_ID ), 'The link id is kept: the old key is not orphaned live.' );
	}

	// ── P1-b: a record with no id is not a principal ──────────────────────

	public function test_a_spai_record_with_an_empty_id_is_not_adopted(): void {
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		$keys = array();
		foreach ( array( 'missing' => null, 'empty' => '', 'sanitized away' => '!!!' ) as $label => $id ) {
			$record = array( 'label' => $label, 'hash' => wp_hash_password( $this->key( substr( md5( $label ), 0, 2 ) ) ), 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => null );
			if ( null !== $id ) {
				$record['id'] = $id;
			}
			$keys[] = $record;
		}
		update_option( 'spai_api_keys', $keys );
		foreach ( array( 'missing', 'empty', 'sanitized away' ) as $label ) {
			$this->assertFalse( $this->works( $this->key( substr( md5( $label ), 0, 2 ) ) ), "Bridge: $label id refused." );
		}
		$this->assertTrue( $this->adopt() );
		$this->assertCount( 1, $this->store(), 'None became a live admin record.' );
	}

	public function test_an_absent_single_key_is_never_adopted_as_a_key(): void {
		// Only spai_api_keys on the site: spai_api_key is absent. The absent
		// sentinel must not be adopted as a (plaintext) key of its own.
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		update_option( 'spai_api_keys', array( array( 'id' => 'm1', 'hash' => wp_hash_password( $this->key( 'f1' ) ), 'scopes' => array( 'read' ), 'role' => 'author', 'revoked_at' => null ) ) );
		$this->assertTrue( $this->adopt() );
		$this->assertCount( 2, $this->store(), 'The record, and nothing for the absent single key.' );
		$this->assertFalse( $this->works( '__NOT_SET__' ) );
	}

	// ── the sentinel class, at three levels ───────────────────────────────

	/** @return array<string,array{0:string}> */
	public static function non_hash_values(): array {
		return array( 'sentinel' => array( '__NOT_SET__' ), 'short' => array( 'h' ), 'dollar junk' => array( '$garbage' ), 'md5-ish' => array( '5f4dcc3b5aa765d61d8327deb882cf99' ) );
	}

	/**
	 * @dataProvider non_hash_values
	 */
	public function test_a_store_record_whose_hash_is_not_a_hash_never_authenticates( string $value ): void {
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'bad', 'label' => 'x', 'hash' => $value, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => null ) ) );
		$this->assertFalse( $this->works( $value ), 'Scoped store: hash-only match, no plaintext fallback.' );
		$GLOBALS['mcpwp_test_options'] = array();
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		update_option( 'spai_api_keys', array( array( 'id' => 'bad', 'hash' => $value, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => null ) ) );
		$this->assertFalse( $this->works( $value ), 'A 2.8 store record: hash-only too.' );
	}

	public function test_the_plaintext_fallback_still_serves_the_two_legacy_single_key_options(): void {
		$legacy = 'mcpwp_' . str_repeat( 'g1', 24 );
		update_option( 'mcpwp_api_key', $legacy ); // Pre-multi-key, plaintext, empty store.
		$this->assertTrue( $this->works( $legacy ) );
		$GLOBALS['mcpwp_test_options'] = array();
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		$single = $this->key( 'g2' );
		update_option( 'spai_api_key', $single ); // Pre-1.0.14, plaintext.
		$this->assertTrue( $this->works( $single ) );
	}

	public function test_adoption_skips_and_reports_a_non_hash_spai_record_by_id_only(): void {
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		update_option( 'spai_api_keys', array( array( 'id' => 'plain-one', 'hash' => 'not-a-hash-secret', 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => null ) ) );
		$this->assertTrue( $this->adopt(), 'Completes: a skipped record does not block adoption forever.' );
		$this->assertCount( 1, $this->store() );
		$this->assertSame( array( 'skipped_not_hash_ids' => array( 'plain-one' ) ), get_option( Mcpwp_Activator::SPAI_ADOPTION_REPORT ) );
		$this->assertStringNotContainsString( 'not-a-hash-secret', (string) wp_json_encode( get_option( Mcpwp_Activator::SPAI_ADOPTION_REPORT ) ) );
	}

	public function test_the_append_never_writes_a_non_hash_record(): void {
		$m = new ReflectionMethod( 'Mcpwp_Activator', 'append_and_verify_scoped_records' );
		$m->setAccessible( true );
		$m->invoke( null, array( array( 'id' => 'junk', 'hash' => '__NOT_SET__', 'revoked_at' => null ), array( 'id' => 'ok', 'hash' => wp_hash_password( 'x' ), 'revoked_at' => null ) ) );
		$this->assertSame( array( 'ok' ), array_column( $this->store(), 'id' ) );
		$this->assertSame( array( 'skipped_not_hash_ids' => array( 'junk' ) ), get_option( Mcpwp_Activator::SPAI_ADOPTION_REPORT ) );
	}

	public function test_hash_shapes(): void {
		foreach ( array( '$P$Babc', '$H$9abc', '$2y$10$abc', '$2a$10$abc', '$2b$10$abc', '$wp$2y$10$abc', wp_hash_password( 'k' ) ) as $ok ) {
			$this->assertTrue( Mcpwp_Activator::is_password_hash_shaped( $ok ), $ok );
		}
		foreach ( array( '__NOT_SET__', '', 'h', '$garbage', '$1$md5crypt', 'P$B', null, 12 ) as $bad ) {
			$this->assertFalse( Mcpwp_Activator::is_password_hash_shaped( $bad ), var_export( $bad, true ) );
		}
	}

	// ── M15: the single key keeps admin ───────────────────────────────────

	public function test_the_single_key_is_adopted_as_admin(): void {
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'c1' ) ) );
		Mcpwp_Activator::adopt_spai_credentials();
		$listed = array_values( array_filter( ( new Mcpwp_Spai_Round2_Test_Auth() )->list_scoped_api_keys( false ), static function ( $k ) {
			return 'spai' === $k['migrated_from'];
		} ) );
		$this->assertSame( 'admin', $listed[0]['role'] );
		$this->assertSame( array( 'read', 'write', 'admin' ), $listed[0]['scopes'] );
	}

	// ── deferred deletion ─────────────────────────────────────────────────

	public function test_originals_are_deleted_only_once_the_adopted_record_survived_a_run(): void {
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		$key  = $this->key( 'c2' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials(), 'Run 1 adopts…' );
		$this->assertSame( $hash, get_option( 'spai_api_key' ), '…and keeps the original.' );
		// A writer that bypasses the lock drops the record (stale full write).
		update_option( 'mcpwp_api_keys', array_slice( $this->store(), 0, 1 ) );
		$this->assertTrue( $this->works( $key ), 'The original still answers: no lockout.' );
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials(), 'Run 2 re-adopts the lost record…' );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials(), '…run 3 confirms and deletes.' );
		$this->assertSame( '__gone__', get_option( 'spai_api_key', '__gone__' ) );
		$this->assertTrue( $this->works( $key ) );
	}

	// ── P1 races: one lock, fresh reads, flag re-checked ──────────────────

	public function test_create_refuses_with_503_while_the_store_lock_is_held(): void {
		$this->hold_lock();
		$created = ( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'x', array( 'read' ), 'author' );
		$this->assertSame( 'key_store_busy', $created['error'] );
		$this->assertSame( 503, $created['status'] );
		$this->assertSame( array(), $this->store(), 'Nothing written unlocked.' );
	}

	public function test_revoke_and_regenerate_refuse_while_the_store_lock_is_held(): void {
		$auth    = new Mcpwp_Spai_Round2_Test_Auth();
		$created = $auth->create_scoped_api_key( 'x', array( 'read' ), 'author' );
		$this->hold_lock();
		$this->assertFalse( $auth->revoke_scoped_api_key( $created['id'] ) );
		$this->assertTrue( $auth->key_store_was_busy(), 'Busy, not "not found".' );
		$regen = $auth->regenerate_api_key();
		$this->assertInstanceOf( WP_Error::class, $regen );
		$this->assertSame( 503, $regen->get_error_data()['status'] );
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_State::STORE_LOCK_OPTION ] );
		$this->assertTrue( $this->works( $created['key'] ), 'Nothing changed while busy.' );
		$this->assertTrue( $auth->revoke_scoped_api_key( $created['id'] ) );
		$this->assertFalse( $auth->key_store_was_busy() );
	}

	public function test_rest_revoke_and_create_answer_503_while_the_store_is_busy(): void {
		$admin   = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'owner', array( 'read', 'write', 'admin' ) );
		$victim  = ( new Mcpwp_REST_Site_Updates() )->create_scoped_api_key( 'victim', array( 'read' ), 'author' );
		$ctl     = new Mcpwp_REST_Site_Updates();
		$request = new WP_REST_Request( 'DELETE', '/mcpwp/v1/api-keys/' . $victim['id'], array( 'id' => $victim['id'] ), array( 'X-API-Key' => $admin['key'] ) );
		$this->assertTrue( $ctl->verify_api_key( $request ) );
		$this->hold_lock();
		$status = static function ( $r ) {
			return $r instanceof WP_Error ? ( $r->get_error_data()['status'] ?? 0 ) : $r->get_status();
		};
		$this->assertSame( 503, $status( $ctl->revoke_api_key( $request ) ), 'Busy, not a misleading 404.' );
		$create = new WP_REST_Request( 'POST', '/mcpwp/v1/api-keys', array( 'label' => 'n', 'scopes' => array( 'read' ) ), array( 'X-API-Key' => $admin['key'] ) );
		$this->assertSame( 503, $status( $ctl->create_api_key( $create ) ) );
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_State::STORE_LOCK_OPTION ] );
		$this->assertSame( 200, $status( $ctl->revoke_api_key( $request ) ) );
	}

	public function test_every_writer_releases_the_lock(): void {
		$auth    = new Mcpwp_Spai_Round2_Test_Auth();
		$created = $auth->create_scoped_api_key( 'x', array( 'read' ), 'author' );
		$auth->revoke_scoped_api_key( $created['id'] );
		$auth->regenerate_api_key();
		$this->adopt();
		$this->assertArrayNotHasKey( Mcpwp_Key_State::STORE_LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'], 'No writer leaves the lock held (no self-deadlock on the next write).' );
	}

	public function test_regenerate_does_not_nest_the_lock(): void {
		// regenerate holds the lock and mints through the unlocked core: with
		// a nested acquire it would refuse itself.
		$new = ( new Mcpwp_Spai_Round2_Test_Auth() )->regenerate_api_key();
		$this->assertIsString( $new );
		$this->assertTrue( $this->works( $new ) );
	}

	public function test_adoption_rechecks_the_flag_inside_the_lock(): void {
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'd1' ) ) );
		$before = $this->store();
		// Another request finishes adoption between our first flag check and
		// our lock acquire.
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = static function ( $name ) {
			if ( Mcpwp_Key_State::STORE_LOCK_OPTION === $name ) {
				update_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG, 1 );
			}
		};
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertSame( $before, $this->store(), 'Saw the flag inside the lock: nothing appended.' );
	}

	public function test_adoption_reads_the_flag_past_a_stale_options_cache(): void {
		( new Mcpwp_Spai_Round2_Test_Auth() )->create_scoped_api_key( 'other', array( 'read' ), 'author' );
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'd2' ) ) );
		$before = $this->store();
		// This request cached "flag not set" before another request set it.
		$GLOBALS['mcpwp_test_options'][ Mcpwp_Activator::SPAI_ADOPTION_FLAG ] = 1;
		$GLOBALS['mcpwp_test_object_cache']['options:notoptions'][ Mcpwp_Activator::SPAI_ADOPTION_FLAG ] = true;
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials(), 'The lock dropped the caches: the real flag is seen.' );
		$this->assertSame( $before, $this->store() );
	}

	public function test_the_first_use_move_of_a_legacy_key_takes_the_lock(): void {
		$legacy = 'mcpwp_' . str_repeat( 'e1', 24 );
		$hash   = wp_hash_password( $legacy );
		update_option( 'mcpwp_api_key', $hash );
		$this->hold_lock();
		$this->assertNull( ( new Mcpwp_Spai_Round2_Test_Auth() )->migrate_legacy( $hash ), 'Busy: no unlocked write.' );
		$this->assertSame( array(), $this->store() );
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_State::STORE_LOCK_OPTION ] );
		$this->assertTrue( $this->works( $legacy ), 'Free again: moved on first use.' );
		$this->assertCount( 1, $this->store() );
	}
}
