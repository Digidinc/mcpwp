<?php
/**
 * The scoped key store is never rewritten from a stale read (#1096 P2-4).
 *
 * The one-time legacy-key migration read mcpwp_api_keys, then wrote the whole
 * array back; create / revoke / rotate did the same. A write landing between
 * the read and the write — a revoke, a new key, a rotation — was overwritten,
 * and a revoked key could be re-created as a fresh active record. Every writer
 * now takes the key-store lock, re-reads inside it and fails closed when it
 * cannot. The last-used map is pruned on revoke and autoloaded deliberately.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';

class Mcpwp_Keystore_Race_Test_Auth {
	use Mcpwp_Api_Auth;

	public function migrate( $legacy ) {
		return $this->migrate_legacy_key_to_scoped_store( $legacy );
	}

	public function touch( $id ) {
		$this->touch_api_key_last_used( $id );
	}
}

final class ApiKeyStoreRace1096Test extends TestCase {

	private const LOCK = 'mcpwp_api_keys_lock';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_options_autoload'] = array();
		$GLOBALS['mcpwp_test_transients']       = array();
		$GLOBALS['mcpwp_test_current_user']     = 0;
		$GLOBALS['mcpwp_test_filters']          = array();
		$GLOBALS['mcpwp_test_wpdb_options']     = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$GLOBALS['mcpwp_test_wpdb_before_update'] = null;
		$GLOBALS['mcpwp_test_wpdb_after_update']  = null;
		$GLOBALS['mcpwp_test_option_cache_stale'] = array();
		$GLOBALS['mcpwp_test_wpdb_hex_compare_blind'] = false;
		$GLOBALS['mcpwp_test_wpdb_col_charset']   = 'utf8mb4';
		$GLOBALS['mcpwp_test_force_truncate_insert'] = false;
		$GLOBALS['mcpwp_test_object_cache']       = array();
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$GLOBALS['mcpwp_test_wpdb_before_update'] = null;
		$GLOBALS['mcpwp_test_wpdb_after_update']  = null;
		$GLOBALS['mcpwp_test_option_cache_stale'] = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_wpdb_hex_compare_blind'] = false;
		$GLOBALS['mcpwp_test_wpdb_col_charset']   = 'utf8mb4';
		$GLOBALS['mcpwp_test_force_truncate_insert'] = false;
		$GLOBALS['mcpwp_test_object_cache']       = array();
	}

	private function request( string $key ): WP_REST_Request {
		return new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) );
	}

	private function record( string $id, string $hash, $revoked_at = null ): array {
		return array(
			'id'         => $id,
			'label'      => $id,
			'hash'       => $hash,
			'scopes'     => array( 'read', 'write', 'admin' ),
			'role'       => 'admin',
			'created_at' => '2026-01-01 00:00:00',
			'revoked_at' => $revoked_at,
		);
	}

	private function hold_lock(): void {
		// Another request owns the store: an unexpired lock row.
		$GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK ] = ( time() + 300 ) . '|deadbeef';
	}

	// ── revoke-then-migrate ───────────────────────────────────────────────

	public function test_a_revoked_key_is_not_resurrected_by_the_migration(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$hash = wp_hash_password( 'mcpwp_legacy_plain' );
		// The store gained (and revoked) this key after the caller's emptiness
		// check — exactly what a concurrent revoke leaves behind.
		update_option( 'mcpwp_api_keys', array( $this->record( 'oldkey', $hash, '2026-02-02 00:00:00' ) ) );

		$this->assertNull( $auth->migrate( $hash ) );

		$keys = get_option( 'mcpwp_api_keys' );
		$this->assertCount( 1, $keys, 'No record may be re-created for a revoked key.' );
		$this->assertSame( '2026-02-02 00:00:00', $keys[0]['revoked_at'], 'The revocation record must survive.' );
	}

	public function test_a_record_added_before_the_lock_survives_the_migration(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$hash = wp_hash_password( 'mcpwp_legacy_plain' );
		$fired = false;
		// A concurrent writer lands a new key + a revoked one right as the
		// migration reaches for the lock.
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = static function ( $name ) use ( &$fired ) {
			if ( 'mcpwp_api_keys_lock' === $name ) {
				$fired = true;
				$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array(
					array( 'id' => 'newkey', 'label' => 'new', 'hash' => wp_hash_password( 'other' ), 'scopes' => array( 'read' ), 'revoked_at' => null ),
					array( 'id' => 'gone', 'label' => 'gone', 'hash' => wp_hash_password( 'gone' ), 'scopes' => array( 'read' ), 'revoked_at' => '2026-02-02 00:00:00' ),
				);
			}
		};

		$result = $auth->migrate( $hash );

		$this->assertTrue( $fired, 'The migration must take the key-store lock.' );
		$this->assertNull( $result );
		$ids = array_column( get_option( 'mcpwp_api_keys' ), 'id' );
		$this->assertSame( array( 'newkey', 'gone' ), $ids, 'Neither the new key nor the revocation may be dropped.' );
	}

	// ── migrate-then-revoke ───────────────────────────────────────────────

	public function test_migrate_then_revoke_leaves_the_key_dead(): void {
		$auth  = new Mcpwp_Keystore_Race_Test_Auth();
		$plain = 'mcpwp_' . str_repeat( 'a', 48 );
		update_option( 'mcpwp_api_key', wp_hash_password( $plain ) );

		$this->assertTrue( $auth->verify_api_key( $this->request( $plain ) ), 'The legacy key migrates on first use.' );
		$records = get_option( 'mcpwp_api_keys' );
		$this->assertCount( 1, $records );

		$this->assertTrue( $auth->revoke_scoped_api_key( $records[0]['id'] ) );
		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $plain ) ) );
		$this->assertCount( 1, get_option( 'mcpwp_api_keys' ), 'The revoked record stays; nothing is re-created.' );
		$this->assertArrayNotHasKey( self::LOCK, $GLOBALS['mcpwp_test_wpdb_options'], 'The lock is released.' );
	}

	public function test_two_requests_with_the_same_legacy_key_make_one_record(): void {
		$auth  = new Mcpwp_Keystore_Race_Test_Auth();
		$plain = 'mcpwp_' . str_repeat( 'b', 48 );
		update_option( 'mcpwp_api_key', wp_hash_password( $plain ) );

		// The second request already passed its emptiness gate when the first
		// finished: it must adopt the first's record, not add another.
		$first  = $auth->migrate( get_option( 'mcpwp_api_key' ) );
		$second = $auth->migrate( get_option( 'mcpwp_api_key' ) );

		$this->assertSame( $first['id'], $second['id'] );
		$this->assertCount( 1, get_option( 'mcpwp_api_keys' ) );
	}

	// ── fail closed when the store is busy ────────────────────────────────

	public function test_a_busy_store_fails_the_migration_and_writes_nothing(): void {
		$auth  = new Mcpwp_Keystore_Race_Test_Auth();
		$plain = 'mcpwp_' . str_repeat( 'c', 48 );
		update_option( 'mcpwp_api_key', wp_hash_password( $plain ) );
		$this->hold_lock();

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $plain ) ) );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys', array() ), 'Nothing may be written without the lock.' );

		unset( $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK ] );
		$this->assertTrue( $auth->verify_api_key( $this->request( $plain ) ), 'The next request migrates normally.' );
	}

	public function test_a_busy_store_refuses_a_revoke_rather_than_pretending(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ) ) );
		$this->hold_lock();

		$this->assertFalse( $auth->revoke_scoped_api_key( 'k1' ) );
		$this->assertNull( get_option( 'mcpwp_api_keys' )[0]['revoked_at'], 'A refused revoke changes nothing.' );
	}

	public function test_a_busy_store_refuses_a_new_key_with_an_error_shape(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$this->hold_lock();

		$created = $auth->create_scoped_api_key( 'x', array( 'read' ), 'admin' );

		$this->assertSame( 'keystore_busy', $created['error'] );
		$this->assertArrayNotHasKey( 'key', $created );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys', array() ) );
	}

	public function test_an_expired_lock_is_stolen_not_waited_on(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK ] = ( time() - 5 ) . '|dead';

		$created = $auth->create_scoped_api_key( 'x', array( 'read' ), 'admin' );

		$this->assertArrayHasKey( 'key', $created );
		$this->assertCount( 1, get_option( 'mcpwp_api_keys' ) );
	}

	public function test_create_and_revoke_keep_each_others_records(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$b    = $auth->create_scoped_api_key( 'b', array( 'read' ), 'admin' );
		$auth->revoke_scoped_api_key( $a['id'] );
		$c = $auth->create_scoped_api_key( 'c', array( 'read' ), 'admin' );

		$live = array_column( $auth->list_scoped_api_keys( false ), 'id' );
		$this->assertSame( array( $b['id'], $c['id'] ), $live );
		$this->assertCount( 3, get_option( 'mcpwp_api_keys' ), 'The revocation record is kept.' );
	}

	// ── last-used map: pruned and autoloaded ──────────────────────────────

	public function test_revoking_a_key_prunes_its_last_used_entry(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$b    = $auth->create_scoped_api_key( 'b', array( 'read' ), 'admin' );
		$auth->touch( $a['id'] );
		$auth->touch( $b['id'] );
		$this->assertCount( 2, get_option( 'mcpwp_api_keys_last_used' ) );

		$auth->revoke_scoped_api_key( $a['id'] );

		$this->assertSame( array( $b['id'] ), array_keys( get_option( 'mcpwp_api_keys_last_used' ) ) );
	}

	public function test_a_refused_revoke_does_not_prune(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$auth->touch( $a['id'] );
		$this->hold_lock();

		$auth->revoke_scoped_api_key( $a['id'] );

		$this->assertArrayHasKey( $a['id'], get_option( 'mcpwp_api_keys_last_used' ) );
	}

	public function test_rotating_the_primary_key_prunes_every_old_entry(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$auth->touch( $a['id'] );

		$new = $auth->regenerate_api_key();

		$this->assertNotSame( '', $new );
		$this->assertArrayNotHasKey( $a['id'], (array) get_option( 'mcpwp_api_keys_last_used', array() ) );
		$this->assertTrue( $auth->verify_api_key( $this->request( $new ) ) );
	}

	public function test_a_busy_store_refuses_rotation_and_keeps_the_old_keys(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$this->hold_lock();

		$this->assertSame( '', $auth->regenerate_api_key() );
		$this->assertNull( get_option( 'mcpwp_api_keys' )[0]['revoked_at'] );
	}

	public function test_the_last_used_option_is_not_autoloaded_so_a_touch_never_writes_alloptions(): void {
		// In core an update_option() on an AUTOLOADED option force-loads and then
		// wp_cache_set()s `alloptions`, where the key store also lives: a touch
		// stalled in between could write a pre-revoke copy back over a revoke.
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$b    = $auth->create_scoped_api_key( 'b', array( 'read' ), 'admin' );
		wp_cache_set( 'alloptions', array( 'sentinel' => 'untouched' ), 'options' );

		$auth->touch( $a['id'] );
		$this->assertFalse( $GLOBALS['mcpwp_test_options_autoload']['mcpwp_api_keys_last_used'], 'The touch must not autoload the option.' );
		$this->assertSame( array( 'sentinel' => 'untouched' ), wp_cache_get( 'alloptions', 'options' ), 'The touch must not write the alloptions cache entry.' );

		$auth->touch( $b['id'] );
		$auth->revoke_scoped_api_key( $a['id'] );
		$this->assertFalse( $GLOBALS['mcpwp_test_options_autoload']['mcpwp_api_keys_last_used'], 'Nor may the prune.' );
	}

	public function test_last_used_still_shows_for_a_live_key(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$auth->touch( $a['id'] );

		$this->assertNotEmpty( $auth->list_scoped_api_keys( false )[0]['last_used_at'] );
	}

	// ── the in-lock read is a real read ───────────────────────────────────

	public function test_the_store_is_read_from_the_database_not_a_stale_cached_snapshot(): void {
		// The options API serves a cached copy that has gone stale (an empty
		// store) while the database row holds k1. A writer that built its
		// change from the cached copy would drop k1.
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ) ) );
		$GLOBALS['mcpwp_test_option_cache_stale']['mcpwp_api_keys'] = array();
		$swaps = 0;
		$count = static function ( $name ) use ( &$swaps, &$count ) {
			if ( 'mcpwp_api_keys' === $name ) {
				$swaps++;
			}
			$GLOBALS['mcpwp_test_wpdb_before_update'] = $count;
		};
		$GLOBALS['mcpwp_test_wpdb_before_update'] = $count;

		$created = $auth->create_scoped_api_key( 'b', array( 'read' ), 'admin' );

		$ids = array_column( (array) get_option( 'mcpwp_api_keys' ), 'id' );
		$this->assertSame( array( 'k1', $created['id'] ), $ids, 'k1 must survive a stale cached snapshot.' );
		// The compare-and-swap would catch a stale read and retry; the point of
		// reading the row is that it never needs to.
		$this->assertSame( 1, $swaps, 'The first read must already be the database row.' );
	}

	public function test_a_revoke_is_not_built_from_a_stale_cached_snapshot(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ), $this->record( 'k2', $hash ) ) );
		// Stale cache: only k1 (k2 was added since).
		$GLOBALS['mcpwp_test_option_cache_stale']['mcpwp_api_keys'] = array( $this->record( 'k1', $hash ) );

		$this->assertSame( 'revoked', $auth->revoke_scoped_api_key_outcome( 'k1' ) );

		$records = get_option( 'mcpwp_api_keys' );
		$this->assertCount( 2, $records, 'k2 must not be dropped.' );
		$this->assertNotNull( $records[0]['revoked_at'] );
		$this->assertNull( $records[1]['revoked_at'] );
	}

	// ── compare-and-swap: a stalled or stolen-lock writer cannot overwrite ─

	public function test_a_stalled_writer_whose_lock_was_stolen_cannot_undo_a_revoke(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ) ) );
		$fired = 0;

		// The creator has read the store and is about to write. It stalls past
		// the lock TTL; another request steals the lock and revokes k1.
		$arm = static function () use ( &$arm, &$fired, $hash ) {
			$GLOBALS['mcpwp_test_wpdb_before_update'] = function ( $name ) use ( &$arm, &$fired ) {
				if ( 'mcpwp_api_keys' !== $name ) {
					$arm();
					return;
				}
				$fired++;
				$records                                       = get_option( 'mcpwp_api_keys' );
				$records[0]['revoked_at']                       = '2026-03-03 00:00:00';
				$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
				if ( $fired < 3 ) {
					$arm();
				}
			};
		};
		$arm();

		$created = $auth->create_scoped_api_key( 'late', array( 'read' ), 'admin' );

		$this->assertGreaterThanOrEqual( 1, $fired, 'The swap must have raced the revoke.' );
		$records = get_option( 'mcpwp_api_keys' );
		$this->assertNotNull( $records[0]['revoked_at'], 'The late writer must not resurrect the revoked key.' );
		// The writer re-read after the miss, so its own key is there too — or,
		// if it never won, it said so and wrote nothing.
		if ( isset( $created['key'] ) ) {
			$this->assertContains( $created['id'], array_column( $records, 'id' ) );
		} else {
			$this->assertSame( 'keystore_busy', $created['error'] );
			$this->assertCount( 1, $records );
		}
	}

	public function test_a_swap_that_keeps_missing_fails_closed(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ) ) );
		$n = 0;
		// A different writer lands a change between every read and every swap.
		$arm = static function () use ( &$arm, &$n ) {
			$GLOBALS['mcpwp_test_wpdb_before_update'] = function ( $name ) use ( &$arm, &$n ) {
				if ( 'mcpwp_api_keys' === $name ) {
					$n++;
					$records                                         = get_option( 'mcpwp_api_keys' );
					$records[]                                       = array( 'id' => 'other' . $n, 'hash' => 'h', 'scopes' => array( 'read' ), 'revoked_at' => null );
					$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
				}
				$arm();
			};
		};
		$arm();

		$this->assertSame( 'busy', $auth->revoke_scoped_api_key_outcome( 'k1' ) );

		$GLOBALS['mcpwp_test_wpdb_before_update'] = null;
		$records = get_option( 'mcpwp_api_keys' );
		$this->assertNull( $records[0]['revoked_at'], 'A swap that never won changed nothing of its own.' );
		$this->assertSame( 3, $n, 'Three attempts, then it gives up.' );
	}

	public function test_a_write_that_does_not_read_back_is_reported_not_trusted(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ) ) );
		// Something rewrites the row right after our write lands.
		$arm = static function () use ( &$arm ) {
			$GLOBALS['mcpwp_test_wpdb_after_update'] = function ( $name ) use ( &$arm ) {
				if ( 'mcpwp_api_keys' === $name ) {
					$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = array( array( 'id' => 'k1', 'hash' => 'h', 'scopes' => array( 'read' ), 'revoked_at' => null, 'note' => 'rewritten' ) );
				}
				$arm();
			};
		};
		$arm();

		$this->assertSame( 'busy', $auth->revoke_scoped_api_key_outcome( 'k1' ) );
	}

	// ── three revoke outcomes ─────────────────────────────────────────────

	public function test_revoke_outcome_tells_revoked_gone_and_busy_apart(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );

		$this->hold_lock();
		$this->assertSame( 'busy', $auth->revoke_scoped_api_key_outcome( $a['id'] ) );
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK ] );

		$this->assertSame( 'revoked', $auth->revoke_scoped_api_key_outcome( $a['id'] ) );
		$this->assertSame( 'not_found', $auth->revoke_scoped_api_key_outcome( $a['id'] ) );
		$this->assertSame( 'not_found', $auth->revoke_scoped_api_key_outcome( 'nosuchkey' ) );
		$this->assertFalse( $auth->revoke_scoped_api_key( $a['id'] ), 'The bool form is true only for a revoke this call made.' );
	}

	// ── rotation is one write ─────────────────────────────────────────────

	public function test_a_busy_rotation_changes_nothing_and_every_old_key_stays_live(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$b    = $auth->create_scoped_api_key( 'b', array( 'read' ), 'admin' );
		$this->hold_lock();

		$this->assertSame( '', $auth->regenerate_api_key() );

		$this->assertCount( 2, $auth->list_scoped_api_keys( false ), 'No key may be revoked by a failed rotation.' );
	}

	public function test_rotation_revokes_and_adds_in_one_store_write(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$writes = 0;
		$GLOBALS['mcpwp_test_wpdb_before_update'] = null;
		$count = static function ( $name ) use ( &$writes, &$count ) {
			if ( 'mcpwp_api_keys' === $name ) {
				$writes++;
			}
			$GLOBALS['mcpwp_test_wpdb_before_update'] = $count;
		};
		$GLOBALS['mcpwp_test_wpdb_before_update'] = $count;

		$new = $auth->regenerate_api_key();

		$this->assertNotSame( '', $new );
		$this->assertSame( 1, $writes, 'Revoke-all and the new record are a single write.' );
		$this->assertCount( 1, $auth->list_scoped_api_keys( false ) );
	}

	// ── the other request-path writers use the same write path ────────────

	public function test_the_spai_key_import_does_not_overwrite_a_store_that_gained_a_key(): void {
		$hash = wp_hash_password( 'x' );
		update_option( 'spai_settings', array() );
		update_option( 'spai_api_keys', array( $this->record( 'spai1', wp_hash_password( 'old' ) ) ) );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash, '2026-02-02 00:00:00' ) ) );
		// Stale cached copy: an empty store, as a reader that loaded earlier sees it.
		$GLOBALS['mcpwp_test_option_cache_stale']['mcpwp_api_keys'] = array();
		Mcpwp_Migrate::reset_flag();

		Mcpwp_Migrate::run();

		$records = get_option( 'mcpwp_api_keys' );
		$this->assertSame( array( 'k1', 'spai1' ), array_column( $records, 'id' ) );
		$this->assertSame( '2026-02-02 00:00:00', $records[0]['revoked_at'], 'The revocation record survives the import.' );
	}

	public function test_a_busy_store_leaves_the_spai_import_unfinished_and_unmarked(): void {
		update_option( 'spai_settings', array() );
		update_option( 'spai_api_keys', array( $this->record( 'spai1', wp_hash_password( 'old' ) ) ) );
		Mcpwp_Migrate::reset_flag();
		$this->hold_lock();

		$this->assertFalse( Mcpwp_Migrate::run() );
		$this->assertFalse( get_option( 'mcpwp_migrated_from_spai', false ), 'Not declared done: the next request retries.' );
		$this->assertSame( array(), get_option( 'mcpwp_api_keys', array() ) );

		unset( $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK ] );
		Mcpwp_Migrate::run();
		$this->assertSame( array( 'spai1' ), array_column( get_option( 'mcpwp_api_keys' ), 'id' ) );
	}

	public function test_the_deny_all_migration_reads_the_store_past_a_stale_cache_and_retries_when_busy(): void {
		$empty = array( 'id' => 'c1', 'role' => 'custom', 'tool_categories' => array(), 'hash' => 'h', 'label' => 'C', 'revoked_at' => null );
		update_option( 'mcpwp_api_keys', array( $empty, $this->record( 'k2', wp_hash_password( 'x' ), '2026-02-02 00:00:00' ) ) );
		$GLOBALS['mcpwp_test_option_cache_stale']['mcpwp_api_keys'] = array( $empty );
		$this->hold_lock();

		Mcpwp_Activator::migrate_empty_custom_category_keys();
		$this->assertFalse( get_option( 'mcpwp_deny_all_categories_migrated', false ), 'A busy store leaves it unfinished.' );

		unset( $GLOBALS['mcpwp_test_wpdb_options'][ self::LOCK ] );
		Mcpwp_Activator::migrate_empty_custom_category_keys();
		$records = get_option( 'mcpwp_api_keys' );
		$this->assertCount( 2, $records, 'The key the stale cache did not show is not dropped.' );
		$this->assertSame( array( Mcpwp_Activator::deny_all_category_marker() ), $records[0]['tool_categories'] );
		$this->assertSame( '2026-02-02 00:00:00', $records[1]['revoked_at'] );
	}

	// ── the object cache: set, never a bare delete (persistent cache) ─────

	public function test_the_cached_alloptions_copy_is_replaced_and_a_late_stale_add_cannot_win(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$stale_all = wp_load_alloptions( true ); // The pre-revoke copy another request read from the database.

		$this->assertSame( 'revoked', $auth->revoke_scoped_api_key_outcome( $a['id'] ) );

		// That request now tries to cache what it read before the revoke.
		$this->assertFalse( wp_cache_add( 'alloptions', $stale_all, 'options' ), 'The fresh copy must already be there.' );
		$cached = wp_cache_get( 'alloptions', 'options' );
		$this->assertSame( maybe_serialize( get_option( 'mcpwp_api_keys' ) ), $cached['mcpwp_api_keys'] );
		$this->assertNotNull( get_option( 'mcpwp_api_keys' )[0]['revoked_at'] );
		$this->assertNotSame( $stale_all['mcpwp_api_keys'], $cached['mcpwp_api_keys'], 'The cached store is the post-revoke bytes.' );
	}

	// ── the compare-and-swap itself ───────────────────────────────────────

	public function test_a_row_raced_between_the_read_and_the_swap_misses_and_is_retried_on_the_new_bytes(): void {
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ) ) );
		$seen = array();
		$GLOBALS['mcpwp_test_wpdb_before_update'] = function ( $name ) use ( $hash ) {
			if ( 'mcpwp_api_keys' === $name ) {
				// Another writer lands k2 after our read, before our swap.
				$records                                         = get_option( 'mcpwp_api_keys' );
				$records[]                                       = $this->record( 'k2', $hash );
				$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
			}
		};

		$outcome = Mcpwp_Key_Store::mutate(
			static function ( array $keys ) use ( &$seen ) {
				$seen[]   = array_column( $keys, 'id' );
				$keys[]   = array( 'id' => 'mine', 'hash' => 'h', 'scopes' => array( 'read' ), 'revoked_at' => null );
				return array( $keys, true );
			},
			true
		);

		$this->assertSame( array( array( 'k1' ), array( 'k1', 'k2' ) ), $seen, 'The second attempt runs on the bytes the other writer left.' );
		$this->assertTrue( $outcome['written'] );
		$this->assertSame( array( 'k1', 'k2', 'mine' ), array_column( get_option( 'mcpwp_api_keys' ), 'id' ) );
	}

	public function test_a_swap_that_never_wins_reports_no_result_and_a_conflict(): void {
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ) ) );
		$n   = 0;
		$arm = function () use ( &$arm, &$n, $hash ) {
			$GLOBALS['mcpwp_test_wpdb_before_update'] = function ( $name ) use ( &$arm, &$n, $hash ) {
				if ( 'mcpwp_api_keys' === $name ) {
					$n++;
					$records                                         = get_option( 'mcpwp_api_keys' );
					$records[]                                       = $this->record( 'r' . $n, $hash );
					$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
				}
				$arm();
			};
		};
		$arm();

		$outcome = Mcpwp_Key_Store::mutate(
			static function ( array $keys ) {
				return array( array_merge( $keys, array( array( 'id' => 'mine', 'hash' => 'h', 'scopes' => array(), 'revoked_at' => null ) ) ), 'would-have-succeeded' );
			},
			true
		);

		$this->assertFalse( $outcome['written'] );
		$this->assertNull( $outcome['result'], 'A change that was not made must not report a result.' );
		$this->assertSame( 'conflict', $outcome['reason'] );
		$this->assertNotContains( 'mine', array_column( get_option( 'mcpwp_api_keys' ), 'id' ) );
	}

	// ── a table whose charset defeats the byte-exact compare ──────────────

	public function test_revoke_still_works_when_the_byte_exact_compare_can_never_match(): void {
		// latin1 wp_options read over utf8mb4: the row is unchanged but HEX()
		// of it is not what PHP read. The revocation lever must survive that.
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'Clé café ü', array( 'read' ), 'admin' );
		$b    = $auth->create_scoped_api_key( 'second', array( 'read' ), 'admin' );
		$GLOBALS['mcpwp_test_wpdb_hex_compare_blind'] = true;

		$this->assertSame( 'revoked', $auth->revoke_scoped_api_key_outcome( $a['id'] ) );
		$created = $auth->create_scoped_api_key( 'Primär ñ', array( 'read' ), 'admin' );
		$this->assertArrayHasKey( 'key', $created, 'Create must not be locked out either.' );

		$live = array_column( $auth->list_scoped_api_keys( false ), 'id' );
		$this->assertSame( array( $b['id'], $created['id'] ), $live );
	}

	public function test_a_raced_row_still_misses_when_the_byte_exact_compare_is_unavailable(): void {
		// The text-equality fallback is only taken for an UNCHANGED row; a row
		// another writer changed is a real race and must still miss.
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ) ) );
		$GLOBALS['mcpwp_test_wpdb_hex_compare_blind'] = true;
		$n   = 0;
		$arm = function () use ( &$arm, &$n, $hash ) {
			$GLOBALS['mcpwp_test_wpdb_before_update'] = function ( $name ) use ( &$arm, &$n, $hash ) {
				if ( 'mcpwp_api_keys' === $name ) {
					$n++;
					$records                                         = get_option( 'mcpwp_api_keys' );
					$records[0]['revoked_at']                        = '2026-03-03 00:00:00';
					$records[]                                       = $this->record( 'r' . $n, $hash );
					$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
				}
				$arm();
			};
		};
		$arm();

		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$created = $auth->create_scoped_api_key( 'late', array( 'read' ), 'admin' );

		$this->assertSame( 'keystore_busy', $created['error'] );
		$this->assertNotNull( get_option( 'mcpwp_api_keys' )[0]['revoked_at'], 'The raced revoke is not undone.' );
	}

	public function test_a_lossy_charset_that_changes_a_label_does_not_make_a_landed_write_look_failed(): void {
		// A latin1 table cannot hold a CJK label: it stores '??'. The write
		// landed; reporting it as failed would lock the site out of creating keys.
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$GLOBALS['mcpwp_test_wpdb_after_update'] = null;
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$a = $auth->create_scoped_api_key( 'first', array( 'read' ), 'admin' );
		$GLOBALS['mcpwp_test_wpdb_after_update'] = static function ( $name ) {
			if ( 'mcpwp_api_keys' === $name ) {
				$records                                         = get_option( 'mcpwp_api_keys' );
				$records[ count( $records ) - 1 ]['label']       = '??';
				$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
			}
		};

		$b = $auth->create_scoped_api_key( '日本語', array( 'read' ), 'admin' );

		$this->assertArrayHasKey( 'key', $b );
		$this->assertSame( array( $a['id'], $b['id'] ), array_column( get_option( 'mcpwp_api_keys' ), 'id' ) );
	}

	public function test_a_write_landing_during_the_mutation_is_not_overwritten(): void {
		// Not a stolen lock, not a late swap: another writer lands k2 while this
		// writer is still computing its change from the bytes it read. The swap
		// must compare against THOSE bytes, never against a fresh read.
		$hash = wp_hash_password( 'x' );
		update_option( 'mcpwp_api_keys', array( $this->record( 'k1', $hash ) ) );
		$calls = 0;

		$outcome = Mcpwp_Key_Store::mutate(
			function ( array $keys ) use ( &$calls, $hash ) {
				$calls++;
				if ( 1 === $calls ) {
					$records                                         = get_option( 'mcpwp_api_keys' );
					$records[]                                       = $this->record( 'k2', $hash );
					$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $records;
				}
				$keys[] = array( 'id' => 'mine', 'hash' => 'h', 'scopes' => array( 'read' ), 'revoked_at' => null );
				return array( $keys, true );
			},
			true
		);

		$this->assertTrue( $outcome['written'] );
		$this->assertSame( 2, $calls, 'The change is recomputed on the bytes that were raced in.' );
		$this->assertSame( array( 'k1', 'k2', 'mine' ), array_column( get_option( 'mcpwp_api_keys' ), 'id' ), 'k2 must not be lost.' );
	}

	public function test_the_alloptions_copy_is_reloaded_from_the_database_not_patched_in_place(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		update_option( 'blogname', 'new name' );
		wp_cache_set( 'alloptions', array( 'blogname' => 's:3:"old";', 'mcpwp_api_keys' => 'stale' ), 'options' );

		$auth->revoke_scoped_api_key( $a['id'] );

		$cached = wp_cache_get( 'alloptions', 'options' );
		$this->assertSame( maybe_serialize( 'new name' ), $cached['blogname'], 'Core re-reads alloptions from the database before setting it.' );
	}

	public function test_the_notoptions_entry_for_the_store_is_cleared_when_the_store_is_created(): void {
		wp_cache_set( 'notoptions', array( 'mcpwp_api_keys' => true, 'other' => true ), 'options' );
		$auth = new Mcpwp_Keystore_Race_Test_Auth();

		$auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );

		$this->assertSame( array( 'other' => true ), wp_cache_get( 'notoptions', 'options' ), 'A stale "this option does not exist" would hide the new store.' );
	}

	// ── a column that cannot hold the value ───────────────────────────────

	public static function unencodable_labels(): array {
		return array(
			'CJK'            => array( "\u{4e3b}\u{952e}" ),
			'emoji'          => array( "Key \u{1F600}" ),
			'CJK with Latin' => array( "Site \u{4e3b}\u{952e} key" ),
		);
	}

	/**
	 * @dataProvider unencodable_labels
	 */
	public function test_a_label_a_latin1_column_cannot_hold_is_refused_and_the_store_is_untouched( string $label ): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$a    = $auth->create_scoped_api_key( 'first', array( 'read' ), 'admin' );
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'latin1';
		$before = get_option( 'mcpwp_api_keys' );

		$created = $auth->create_scoped_api_key( $label, array( 'read' ), 'admin' );

		$this->assertSame( 'unsupported_characters', $created['error'] );
		$this->assertArrayNotHasKey( 'key', $created );
		$this->assertSame( $before, get_option( 'mcpwp_api_keys' ), 'Nothing is written.' );
		$this->assertCount( 1, $auth->list_scoped_api_keys( false ) );
		$this->assertSame( 'revoked', $auth->revoke_scoped_api_key_outcome( $a['id'] ), 'The existing key can still be revoked.' );
	}

	public function test_what_the_column_can_hold_is_accepted(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'latin1';
		$this->assertArrayHasKey( 'key', $auth->create_scoped_api_key( "Cl\u{e9} caf\u{e9} \u{fc} \u{20ac}", array( 'read' ), 'admin' ), 'latin1 (cp1252) accents and the euro sign fit.' );

		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'utf8';
		$this->assertArrayHasKey( 'key', $auth->create_scoped_api_key( "\u{4e3b}\u{952e}", array( 'read' ), 'admin' ), 'utf8 holds CJK.' );
		$this->assertSame( 'unsupported_characters', $auth->create_scoped_api_key( "x \u{1F600}", array( 'read' ), 'admin' )['error'], 'but not a 4-byte emoji.' );

		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'ascii';
		$this->assertSame( 'unsupported_characters', $auth->create_scoped_api_key( "Cl\u{e9}", array( 'read' ), 'admin' )['error'] );

		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'utf8mb4';
		$this->assertArrayHasKey( 'key', $auth->create_scoped_api_key( "\u{4e3b}\u{952e} \u{1F600}", array( 'read' ), 'admin' ) );
	}

	// ── a write that lands truncated is rolled back ───────────────────────

	public function test_a_truncated_write_is_rolled_back_to_the_previous_bytes(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );
		$auth->create_scoped_api_key( 'b', array( 'read' ), 'admin' );
		$before = maybe_serialize( get_option( 'mcpwp_api_keys' ) );
		// The database cuts the row short; only the first write after this is hit.
		$GLOBALS['mcpwp_test_wpdb_after_update'] = static function ( $name ) {
			if ( 'mcpwp_api_keys' === $name ) {
				$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = substr( maybe_serialize( $GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] ), 0, 120 );
			}
		};

		$created = $auth->create_scoped_api_key( 'c', array( 'read' ), 'admin' );

		$this->assertSame( 'keystore_write_failed', $created['error'] );
		$this->assertSame( $before, maybe_serialize( get_option( 'mcpwp_api_keys' ) ), 'The previous bytes are back, byte for byte.' );
		$this->assertCount( 2, $auth->list_scoped_api_keys( false ) );
		$this->assertArrayNotHasKey( 'mcpwp_api_keys_lock', $GLOBALS['mcpwp_test_wpdb_options'], 'The lock is released.' );
	}

	public function test_a_truncated_first_write_leaves_no_row_behind(): void {
		$auth = new Mcpwp_Keystore_Race_Test_Auth();
		$GLOBALS['mcpwp_test_force_truncate_insert'] = true;

		$created = $auth->create_scoped_api_key( 'a', array( 'read' ), 'admin' );

		$GLOBALS['mcpwp_test_force_truncate_insert'] = false;
		$this->assertSame( 'keystore_write_failed', $created['error'] );
		$this->assertFalse( get_option( 'mcpwp_api_keys', false ), 'A truncated first write is removed, not left as a corrupt store.' );
	}

	// ── a corrupt store fails closed ──────────────────────────────────────

	private function corrupt_store(): string {
		$corrupt = 'a:2:{i:0;a:7:{s:2:"id";s:36:"11111111-1111-1111-1111-111111111111";s:5:"label";s:3:"';
		update_option( 'mcpwp_api_keys', $corrupt );
		return $corrupt;
	}

	public function test_a_corrupt_store_is_never_written_and_refuses_every_writer(): void {
		$auth    = new Mcpwp_Keystore_Race_Test_Auth();
		$corrupt = $this->corrupt_store();

		$this->assertSame( 'keystore_unreadable', $auth->create_scoped_api_key( 'x', array( 'read' ), 'admin' )['error'] );
		$this->assertSame( 'unreadable', $auth->revoke_scoped_api_key_outcome( 'anything' ) );
		$this->assertSame( '', $auth->regenerate_api_key() );
		$this->assertSame( $corrupt, get_option( 'mcpwp_api_keys' ), 'The stored bytes are exactly as they were.' );
	}

	public function test_a_corrupt_store_does_not_let_the_legacy_key_back_in_as_an_active_admin(): void {
		$auth  = new Mcpwp_Keystore_Race_Test_Auth();
		$plain = 'mcpwp_' . str_repeat( 'd', 48 );
		update_option( 'mcpwp_api_key', wp_hash_password( $plain ) );
		$corrupt = $this->corrupt_store();

		$verdict = $auth->verify_api_key( $this->request( $plain ) );

		$this->assertInstanceOf( WP_Error::class, $verdict, 'A key store that cannot be read fails closed.' );
		$this->assertSame( $corrupt, get_option( 'mcpwp_api_keys' ), 'And nothing is migrated over it.' );
	}

	public function test_a_corrupt_store_does_not_let_a_spai_copy_of_a_revoked_key_authenticate(): void {
		$auth  = new Mcpwp_Keystore_Race_Test_Auth();
		$plain = 'spai_' . str_repeat( 'e', 48 );
		update_option( 'spai_api_keys', array( $this->record( 'old', wp_hash_password( $plain ) ) ) );
		$this->corrupt_store();

		$this->assertInstanceOf( WP_Error::class, $auth->verify_api_key( $this->request( $plain ) ) );
	}

	public function test_the_legacy_option_is_not_honoured_while_the_store_is_unreadable(): void {
		$auth   = new Mcpwp_Keystore_Race_Test_Auth();
		$method = new ReflectionMethod( $auth, 'legacy_api_key_option_is_honoured' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $auth ), 'An empty store honours it (a pre-multi-key install).' );
		$this->corrupt_store();
		$this->assertFalse( $method->invoke( $auth ), 'A corrupt store reads as empty but must not.' );
	}
}
