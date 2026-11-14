<?php
/**
 * #1098 — every 2.8 spai credential ends up in the revocable scoped store,
 * written through Mcpwp_Key_Store::mutate() (#1166), and the spai originals
 * are deleted.
 *
 * Before: on the normal upgrade path the activator minted mcpwp_api_key before
 * Mcpwp_Migrate::run(), so a single spai_api_key was never copied. It then
 * authenticated as admin through find_legacy_spai_key() for good, and nothing
 * could revoke it.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-migrate.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-uninstaller.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';

class Mcpwp_Spai_Adoption_Test_Auth {
	use Mcpwp_Api_Auth;

	public function auth_only( $request ) {
		return $this->verify_api_key_auth_only( $request );
	}

	/** The record the 2.8 bridge grants for a key, or null. */
	public function bridge_record( $key ) {
		return $this->find_legacy_spai_key( $key );
	}

	/** The record the scoped store grants for a key, or null. */
	public function store_record( $key ) {
		return $this->find_scoped_api_key( $key );
	}
}

final class SpaiCredentialAdoption1098Test extends TestCase {

	private const CORRUPT = 'a:2:{i:0;a:7:{s:2:"id";s:36:"11111111-1111-1111-1111-111111111111";s:5:"label";s:3:"';

	/** @var string|false */
	private $saved_error_log;
	/** @var string */
	private $log_file;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']              = array();
		$GLOBALS['mcpwp_test_options_autoload']     = array();
		$GLOBALS['mcpwp_test_transients']           = array();
		$GLOBALS['mcpwp_test_wpdb_options']         = array();
		$GLOBALS['mcpwp_test_delete_option_fail']   = array();
		$GLOBALS['mcpwp_test_current_user']         = 0;
		$GLOBALS['mcpwp_test_filters']              = array();
		$GLOBALS['mcpwp_test_user_caps']            = array();
		$GLOBALS['mcpwp_test_object_cache']         = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert']   = null;
		$GLOBALS['mcpwp_test_wpdb_before_update']   = null;
		$GLOBALS['mcpwp_test_wpdb_col_charset']     = 'utf8mb4';
		$GLOBALS['mcpwp_test_force_truncate_insert'] = false;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
		$this->log_file        = tempnam( sys_get_temp_dir(), 'mcpwp1098' );
		$this->saved_error_log = ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', (string) $this->saved_error_log );
		if ( is_file( $this->log_file ) ) {
			unlink( $this->log_file );
		}
		$GLOBALS['mcpwp_test_delete_option_fail']    = array();
		$GLOBALS['mcpwp_test_current_user']          = 0;
		$GLOBALS['mcpwp_test_wpdb_before_insert']    = null;
		$GLOBALS['mcpwp_test_wpdb_before_update']    = null;
		$GLOBALS['mcpwp_test_wpdb_col_charset']      = 'utf8mb4';
		$GLOBALS['mcpwp_test_force_truncate_insert'] = false;
		$GLOBALS['mcpwp_test_wpdb_options']          = array();
	}

	// ── helpers ───────────────────────────────────────────────────────────

	private function request( string $key ): WP_REST_Request {
		return new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) );
	}

	private function works( string $key ): bool {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$main = $auth->verify_api_key( $this->request( $key ) );
		$sub  = $auth->auth_only( $this->request( $key ) );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$this->assertSame( true === $main, true === $sub, 'Both auth paths agree.' );
		return true === $main;
	}

	/** The id the request authenticates as, or '' when it does not. */
	private function principal_id( string $key ): string {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$ok   = $auth->verify_api_key( $this->request( $key ) );
		$id   = true === $ok ? (string) ( $auth->get_current_api_key_record()['id'] ?? '' ) : '';
		$GLOBALS['mcpwp_test_current_user'] = 0;
		return $id;
	}

	private function store(): array {
		$s = get_option( 'mcpwp_api_keys', array() );
		return is_array( $s ) ? $s : array();
	}

	private function records_with_hash( string $hash ): array {
		return array_values( array_filter( $this->store(), static function ( $r ) use ( $hash ) {
			return is_array( $r ) && ( $r['hash'] ?? '' ) === $hash;
		} ) );
	}

	private function key( string $tag ): string {
		return 'spai_' . str_repeat( $tag, 24 );
	}

	private function seed_store_key(): array {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$made = $auth->create_scoped_api_key( 'existing integration', array( 'read' ), 'author' );
		$this->assertArrayHasKey( 'key', $made );
		return $made;
	}

	/** A later page load: the failed-attempt back-off has expired. */
	private function later_load(): bool {
		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BACKOFF );
		return Mcpwp_Activator::adopt_spai_credentials();
	}

	private function gone( string $option ): bool {
		return '__gone__' === get_option( $option, '__gone__' );
	}

	// ── the concept: an uncopied spai key becomes a revocable record ──────

	public function test_an_uncopied_single_key_is_adopted_listed_and_revocable(): void {
		$this->seed_store_key();
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$key  = $this->key( 'a1' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		$this->assertTrue( $this->works( $key ), 'Before adoption the bridge honours it (and nothing can revoke it).' );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertTrue( $this->gone( 'spai_api_key' ), 'The spai original is deleted.' );
		$records = $this->records_with_hash( $hash );
		$this->assertCount( 1, $records );
		$this->assertSame( Mcpwp_Activator::SPAI_ADOPTED_LABEL, $records[0]['label'] );
		$this->assertSame( 'spai', $records[0]['migrated_from'] );
		$this->assertSame( array( 'read', 'write', 'admin' ), $records[0]['scopes'], 'Admin, as the bridge granted it.' );
		$this->assertNull( $records[0]['revoked_at'] );
		$this->assertTrue( $this->works( $key ), 'Still works, now as a scoped record.' );
		$listed = array_values( array_filter( $auth->list_scoped_api_keys( false ), static function ( $k ) {
			return 'spai' === $k['migrated_from'];
		} ) );
		$this->assertCount( 1, $listed, 'Active Keys lists it, marked as migrated.' );

		$this->assertTrue( $auth->revoke_scoped_api_key( $records[0]['id'] ) );
		$this->assertFalse( $this->works( $key ), 'Revoked in Active Keys: refused.' );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertFalse( $this->works( $key ), 'And it stays dead.' );
	}

	/**
	 * Real activate(); its user/role helpers are stubbed here, in a separate
	 * process, so the stubs cannot leak into other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_normal_activation_order_ends_with_the_spai_key_adopted(): void {
		if ( ! function_exists( 'get_user_by' ) ) {
			function get_user_by( $field, $value ) {
				return (object) array( 'ID' => 99, 'user_login' => (string) $value );
			}
		}
		if ( ! function_exists( 'flush_rewrite_rules' ) ) {
			function flush_rewrite_rules() {
			}
		}
		$key  = $this->key( 'b2' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		update_option( 'spai_settings', array( 'enable_logging' => true ) );

		Mcpwp_Activator::activate();
		$minted = (string) get_transient( 'mcpwp_new_api_key' );
		Mcpwp_Migrate::run();
		$this->assertNotSame( $hash, get_option( 'mcpwp_api_key' ), 'Precondition: the migration did not copy it.' );
		$this->assertSame( array(), $this->records_with_hash( $hash ), 'Precondition: no scoped record (the #1098 P1).' );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertCount( 1, $this->records_with_hash( $hash ) );
		$this->assertTrue( $this->gone( 'spai_api_key' ) );
		$this->assertTrue( $this->works( $key ) );
		if ( '' !== $minted ) {
			$this->assertTrue( $this->works( $minted ), 'The key the activator minted keeps working.' );
		}
	}

	public function test_an_empty_store_keeps_its_legacy_mcpwp_key_working(): void {
		// mcpwp_api_key is honoured only while the store is empty; adopting a
		// spai key must not switch it off, so it moves into the same write.
		$legacy = 'mcpwp_' . str_repeat( 'c3', 24 );
		update_option( 'mcpwp_api_key', wp_hash_password( $legacy ) );
		$key = $this->key( 'c4' );
		update_option( 'spai_api_key', wp_hash_password( $key ) );
		$this->assertSame( array(), $this->store() );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertCount( 2, $this->store(), 'Both written by the one mutate().' );
		$this->assertTrue( $this->works( $legacy ) );
		$this->assertTrue( $this->works( $key ) );
	}

	public function test_a_legacy_key_that_is_also_the_spai_key_is_not_written_twice(): void {
		$key  = $this->key( 'c5' );
		$hash = wp_hash_password( $key );
		update_option( 'mcpwp_api_key', $hash ); // The migration copied it.
		update_option( 'spai_api_key', $hash );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertLessThanOrEqual( 1, count( $this->records_with_hash( $hash ) ), 'De-duplicated by hash: never two live records for one credential.' );
		$this->assertTrue( $this->works( $key ) );
		$this->assertTrue( $this->gone( 'spai_api_key' ) );
	}

	// ── what an adopted record may do: never more than the bridge gave ────

	public function test_spai_api_keys_records_keep_exactly_what_the_bridge_granted(): void {
		$this->seed_store_key();
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$k1   = $this->key( 'd1' );
		$k2   = $this->key( 'd2' );
		$k3   = $this->key( 'd3' );
		update_option( 'spai_api_keys', array(
			array( 'id' => 'ed', 'label' => 'Editor', 'hash' => wp_hash_password( $k1 ), 'scopes' => array( 'read', 'write' ), 'role' => 'editor', 'revoked_at' => null ),
			array( 'id' => 'cu', 'label' => 'Custom', 'hash' => wp_hash_password( $k2 ), 'scopes' => array( 'read' ), 'role' => 'custom', 'tool_categories' => array( 'seo' ), 'rate_limits' => array( 'per_minute' => 5 ), 'allow_destructive' => true, 'revoked_at' => null ),
			array( 'id' => 'ro', 'label' => 'Roleless', 'hash' => wp_hash_password( $k3 ), 'revoked_at' => null ),
		) );
		$before = array();
		foreach ( array( $k1, $k2, $k3 ) as $k ) {
			$before[ $k ] = $auth->bridge_record( $k );
			$this->assertNotNull( $before[ $k ] );
		}

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		foreach ( $before as $k => $granted ) {
			$after = $auth->store_record( $k );
			$this->assertNotNull( $after, 'Still authenticates.' );
			foreach ( array( 'id', 'scopes', 'role', 'tool_categories', 'rate_limits', 'allow_destructive' ) as $field ) {
				$this->assertSame( $granted[ $field ], $after[ $field ], "Adoption must not change $field." );
			}
		}
	}

	public function test_a_spai_record_the_migration_already_copied_is_not_duplicated(): void {
		$this->seed_store_key();
		$key   = $this->key( 'd4' );
		$hash  = wp_hash_password( $key );
		$store = $this->store();
		$store[] = array( 'id' => 'copied', 'label' => 'copy', 'hash' => $hash, 'scopes' => array( 'read' ), 'role' => 'author', 'revoked_at' => null );
		update_option( 'mcpwp_api_keys', $store );
		update_option( 'spai_api_keys', array( array( 'id' => 'orig', 'label' => 'orig', 'hash' => $hash, 'scopes' => array( 'read', 'write', 'admin' ), 'role' => 'admin', 'revoked_at' => null ) ) );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$records = $this->records_with_hash( $hash );
		$this->assertCount( 1, $records, 'Not appended again.' );
		$this->assertSame( 'copied', $records[0]['id'], 'The existing record is authoritative; adoption never widens it.' );
		$this->assertTrue( $this->gone( 'spai_api_keys' ) );
	}

	public function test_a_non_hash_or_idless_spai_record_is_not_adopted_and_never_authenticates(): void {
		$this->seed_store_key();
		update_option( 'spai_api_keys', array(
			array( 'id' => 'plain', 'hash' => '__NOT_SET__', 'revoked_at' => null ),
			array( 'id' => '', 'hash' => wp_hash_password( $this->key( 'e1' ) ), 'revoked_at' => null ),
		) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertCount( 1, $this->store(), 'Neither was written.' );
		$this->assertFalse( $this->works( '__NOT_SET__' ), 'A non-hash value is never a key.' );
		$this->assertFalse( $this->works( $this->key( 'e1' ) ) );
	}

	public function test_a_non_hash_store_record_never_matches_as_plaintext(): void {
		$this->seed_store_key();
		$store   = $this->store();
		$store[] = array( 'id' => 'junk', 'label' => 'junk', 'hash' => 'plaintext-secret-value', 'scopes' => array( 'read' ), 'revoked_at' => null );
		update_option( 'mcpwp_api_keys', $store );
		$this->assertFalse( $this->works( 'plaintext-secret-value' ) );
	}

	// ── P0-a: a corrupt/truncated store is never built on ────────────────

	public function test_a_corrupt_store_is_left_exactly_as_it_is_and_nothing_is_adopted(): void {
		$key  = $this->key( 'f1' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		update_option( 'mcpwp_api_keys', self::CORRUPT );

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertSame( self::CORRUPT, get_option( 'mcpwp_api_keys' ), 'The recoverable bytes are untouched.' );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ), 'Flag unset.' );
		$this->assertSame( $hash, get_option( 'spai_api_key' ), 'Original kept.' );
		$this->assertFalse( $this->works( $key ), 'The unreadable store fails every legacy fallback closed.' );
	}

	public function test_an_unreadable_store_is_refused_before_any_lock_or_query(): void {
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'f2' ) ) );
		update_option( 'mcpwp_api_keys', self::CORRUPT );
		$touched = false;
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = static function () use ( &$touched ) {
			$touched = true; // The lock claim is an INSERT.
		};
		$GLOBALS['mcpwp_test_wpdb_before_update'] = $GLOBALS['mcpwp_test_wpdb_before_insert'];

		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials(), 'Every load: still refused.' );
		}

		$this->assertFalse( $touched, 'No lock claimed, nothing written.' );
		$this->assertArrayNotHasKey( Mcpwp_Key_Store::LOCK, $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	public function test_a_truncated_serialised_store_is_refused_too(): void {
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'f3' ) ) );
		$truncated = serialize( array( array( 'id' => 'a', 'hash' => 'h' ) ) );
		$truncated = substr( $truncated, 0, -1 ); // Ends in ';' not '}': core still calls it serialized.
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $truncated;
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertSame( $truncated, $GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );
	}

	public function test_a_store_the_column_cannot_hold_is_refused_and_nothing_is_deleted(): void {
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'latin1';
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$auth->create_scoped_api_key( 'ascii', array( 'read' ), 'author' );
		$store                 = $this->store();
		$store[0]['label']     = "\u{65E5}\u{672C}"; // Not encodable in latin1.
		$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $store;
		$hash = wp_hash_password( $this->key( 'f4' ) );
		update_option( 'spai_api_key', $hash );
		$before = serialize( $this->store() );

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertSame( $before, serialize( $this->store() ) );
		$this->assertSame( $hash, get_option( 'spai_api_key' ), 'The original survives.' );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );
	}

	public function test_a_write_that_does_not_read_back_is_rolled_back_and_keeps_the_originals(): void {
		$GLOBALS['mcpwp_test_force_truncate_insert'] = true;
		$hash = wp_hash_password( $this->key( 'f5' ) );
		update_option( 'spai_api_key', $hash );
		$this->assertArrayNotHasKey( 'mcpwp_api_keys', $GLOBALS['mcpwp_test_options'] );

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertSame( $hash, get_option( 'spai_api_key' ) );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );
	}

	public function test_a_busy_store_changes_nothing_and_a_later_load_finishes(): void {
		$hash = wp_hash_password( $this->key( 'f6' ) );
		update_option( 'spai_api_key', $hash );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|deadbeef';

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertSame( $hash, get_option( 'spai_api_key' ) );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );

		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] );
		$this->assertTrue( $this->later_load() );
		$this->assertCount( 1, $this->records_with_hash( $hash ) );
	}

	// ── P0-b: a revoke racing the adoption is never lost ──────────────────

	public function test_a_revoke_landing_before_the_swap_is_kept_not_overwritten(): void {
		$made = $this->seed_store_key();
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$hash = wp_hash_password( $this->key( 'g1' ) );
		update_option( 'spai_api_key', $hash );
		$revoked_id = $this->store()[0]['id'];

		// Another request revokes the existing key between adoption's read and its swap.
		$GLOBALS['mcpwp_test_wpdb_before_update'] = function () use ( $revoked_id ) {
			$store = $GLOBALS['mcpwp_test_options']['mcpwp_api_keys'];
			foreach ( $store as &$r ) {
				if ( $r['id'] === $revoked_id ) {
					$r['revoked_at'] = '2026-11-01 00:00:00';
				}
			}
			unset( $r );
			$GLOBALS['mcpwp_test_options']['mcpwp_api_keys'] = $store;
		};
		$this->assertNull( $this->store()[0]['revoked_at'] );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$by_id = array_column( $this->store(), null, 'id' );
		$this->assertSame( '2026-11-01 00:00:00', $by_id[ $revoked_id ]['revoked_at'], 'The revoke survives the adoption.' );
		$this->assertFalse( $this->works( $made['key'] ) );
		$this->assertCount( 1, $this->records_with_hash( $hash ), 'And the adoption itself still landed.' );
	}

	public function test_a_revoke_of_the_adopted_key_is_never_undone_by_a_rerun(): void {
		$this->seed_store_key();
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$key  = $this->key( 'g2' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		// Adopt, but pretend the deletes did not take, so the originals linger.
		$GLOBALS['mcpwp_test_delete_option_fail']['spai_api_key'] = true;
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );
		$id = $this->records_with_hash( $hash )[0]['id'];
		$this->assertTrue( $auth->revoke_scoped_api_key( $id ) );

		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$this->assertTrue( $this->later_load() );

		$this->assertFalse( $this->works( $key ), 'A revoked adopted key stays revoked.' );
		$this->assertCount( 1, $this->records_with_hash( $hash ) );
	}

	// ── P1-a: a partial delete never resurrects a revoked twin ───────────

	private function twin_site(): array {
		$this->seed_store_key();
		$key  = $this->key( 'h1' );
		$hash = wp_hash_password( $key );
		// 2.8 revoked its Primary key in its store and left the same hash in spai_api_key.
		update_option( 'spai_api_keys', array( array( 'id' => 'p28', 'label' => 'Primary API Key', 'hash' => $hash, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => '2026-01-01 00:00:00' ) ) );
		update_option( 'spai_api_key', $hash );
		return array( $key, $hash );
	}

	public function test_a_revoked_twin_is_written_as_a_revoked_placeholder_not_a_live_record(): void {
		list( $key, $hash ) = $this->twin_site();
		$this->assertFalse( $this->works( $key ), 'Precondition (#1097/this PR): refused before adoption.' );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$records = $this->records_with_hash( $hash );
		$this->assertCount( 1, $records );
		$this->assertSame( '2026-01-01 00:00:00', $records[0]['revoked_at'] );
		$this->assertTrue( $this->gone( 'spai_api_keys' ), 'The spai options are gone...' );
		$this->assertTrue( $this->gone( 'spai_api_key' ) );
		$this->assertFalse( $this->works( $key ), '...and the revocation outlives them.' );
		// Even if the original reappears (a restored backup, a copied option row).
		update_option( 'spai_api_key', $hash );
		$this->assertFalse( $this->works( $key ), 'The placeholder keeps the bridge refusing it.' );
	}

	public function test_a_failed_delete_of_the_store_option_never_resurrects_the_twin(): void {
		list( $key, $hash ) = $this->twin_site();
		$GLOBALS['mcpwp_test_delete_option_fail']['spai_api_keys'] = true;

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertTrue( $this->gone( 'spai_api_key' ), 'The single key went first...' );
		$this->assertFalse( $this->gone( 'spai_api_keys' ), '...the store survived the failed delete.' );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ), 'Flag unset until both deletes verify.' );
		$this->assertFalse( $this->works( $key ), 'The revoked twin is not live.' );
		foreach ( $this->records_with_hash( $hash ) as $r ) {
			$this->assertNotEmpty( $r['revoked_at'], 'No active record for the twin.' );
		}
	}

	public function test_a_failed_delete_of_the_single_key_stops_before_touching_the_store_option(): void {
		list( $key, $hash ) = $this->twin_site();
		$GLOBALS['mcpwp_test_delete_option_fail']['spai_api_key'] = true;

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertFalse( $this->gone( 'spai_api_keys' ), 'spai_api_keys is only deleted after spai_api_key.' );
		$this->assertFalse( $this->works( $key ), 'Still refused: the store holds the revoked placeholder.' );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );

		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$this->assertTrue( $this->later_load(), 'A later load completes.' );
		$this->assertFalse( $this->works( $key ) );
		$this->assertCount( 1, $this->records_with_hash( $hash ) );
	}

	public function test_a_plaintext_single_key_revoked_in_the_store_stays_dead(): void {
		$this->seed_store_key();
		$auth  = new Mcpwp_Spai_Adoption_Test_Auth();
		$plain = $this->key( 'h2' );
		$store = $this->store();
		// Its rehashed copy was revoked in the scoped store.
		$store[] = array( 'id' => 'rv', 'label' => 'x', 'hash' => wp_hash_password( $plain ), 'scopes' => array( 'read' ), 'revoked_at' => '2026-02-02 00:00:00' );
		update_option( 'mcpwp_api_keys', $store );
		update_option( 'spai_api_key', $plain ); // pre-1.0.14: plaintext.
		$this->assertFalse( $this->works( $plain ), 'Precondition: the bridge refuses it.' );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertCount( 2, $this->store(), 'Nothing added: it matches a revoked record.' );
		$this->assertFalse( $this->works( $plain ) );
	}

	public function test_a_live_plaintext_single_key_is_adopted_hashed(): void {
		$this->seed_store_key();
		$plain = $this->key( 'h3' );
		update_option( 'spai_api_key', $plain );
		$this->assertTrue( $this->works( $plain ) );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertNotContains( $plain, array_column( $this->store(), 'hash' ), 'The plaintext is never stored.' );
		$this->assertTrue( $this->works( $plain ) );
		$this->assertSame( 'spai_legacy_single', $this->principal_id( $plain ) );
	}

	// ── P1-b: identity — the approvals self-approval check compares ids ──

	public function test_the_single_key_keeps_its_bridge_identity(): void {
		$this->seed_store_key();
		$key = $this->key( 'i1' );
		update_option( 'spai_api_key', wp_hash_password( $key ) );
		$this->assertSame( 'spai_legacy_single', $this->principal_id( $key ), 'The id the bridge gives it today.' );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertSame( 'spai_legacy_single', $this->principal_id( $key ), 'Unchanged, so a request it filed before cannot be approved by it now.' );
	}

	public function test_a_spai_records_id_is_kept_even_when_the_store_has_the_same_id(): void {
		$made = $this->seed_store_key();
		$taken = $this->store()[0]['id'];
		$key   = $this->key( 'i2' );
		update_option( 'spai_api_keys', array( array( 'id' => $taken, 'label' => 'dup', 'hash' => wp_hash_password( $key ), 'scopes' => array( 'read' ), 'role' => 'author', 'revoked_at' => null ) ) );
		$this->assertSame( $taken, $this->principal_id( $key ) );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertSame( $taken, $this->principal_id( $key ), 'Identity unchanged: same as the bridge gave it.' );
		$this->assertTrue( $this->works( $made['key'] ) );
	}

	// ── re-runs, reactivation, flag ───────────────────────────────────────

	public function test_a_second_run_and_a_reactivation_write_nothing(): void {
		$this->seed_store_key();
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'j1' ) ) );
		update_option( 'spai_api_keys', array( array( 'id' => 'r1', 'hash' => wp_hash_password( $this->key( 'j2' ) ), 'revoked_at' => '2026-03-03 00:00:00' ) ) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$after = serialize( $this->store() );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials(), 'Flag set: a no-op.' );
		delete_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ); // A reactivation / a wiped flag.
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );

		$this->assertSame( $after, serialize( $this->store() ), 'Nothing duplicated.' );
	}

	public function test_a_site_with_no_spai_options_just_sets_the_flag_and_leaves_the_store_alone(): void {
		$this->seed_store_key();
		$before = serialize( $this->store() );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertSame( $before, serialize( $this->store() ) );
		$this->assertNotEmpty( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );
		$this->assertTrue( $GLOBALS['mcpwp_test_options_autoload'][ Mcpwp_Activator::SPAI_ADOPTION_FLAG ], 'Autoloaded: the per-request check costs no query.' );
	}

	public function test_each_site_of_a_network_is_adopted_on_its_own(): void {
		$site_a = array( 'spai_api_key' => wp_hash_password( $this->key( 'k1' ) ) );
		$key_b  = $this->key( 'k2' );
		$hash_a = $site_a['spai_api_key'];

		$GLOBALS['mcpwp_test_options'] = $site_a;
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$state_a = $GLOBALS['mcpwp_test_options'];
		$this->assertCount( 1, $this->records_with_hash( $hash_a ) );

		// Site B: its own options, its own spai key, flag unset.
		$GLOBALS['mcpwp_test_options'] = array( 'spai_api_key' => wp_hash_password( $key_b ) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials(), 'B is adopted independently.' );
		$this->assertCount( 0, $this->records_with_hash( $hash_a ), 'A key never crosses sites.' );
		$this->assertCount( 1, $this->store() );
		$this->assertTrue( $this->works( $key_b ) );
		$this->assertNotEmpty( $state_a[ Mcpwp_Activator::SPAI_ADOPTION_FLAG ] );
	}

	// ── nothing printed or logged ─────────────────────────────────────────

	public function test_no_key_or_hash_is_logged_or_written_anywhere_else(): void {
		$key  = $this->key( 'l1' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		update_option( 'spai_api_keys', array( array( 'id' => 'x1', 'hash' => wp_hash_password( $this->key( 'l2' ) ), 'revoked_at' => null ) ) );

		ob_start();
		Mcpwp_Activator::adopt_spai_credentials();
		$printed = ob_get_clean();

		$this->assertSame( '', $printed );
		$this->assertSame( '', (string) file_get_contents( $this->log_file ) );
		foreach ( $GLOBALS['mcpwp_test_options'] as $name => $value ) {
			if ( 'mcpwp_api_keys' === $name ) {
				continue;
			}
			$blob = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
			$this->assertStringNotContainsString( $hash, $blob, "Option $name carries a hash." );
			$this->assertStringNotContainsString( $key, $blob, "Option $name carries a key." );
		}
		foreach ( ( new Mcpwp_Spai_Adoption_Test_Auth() )->list_scoped_api_keys( true ) as $listed ) {
			$this->assertArrayNotHasKey( 'hash', $listed );
		}
	}

	// ── regenerate revokes EVERY key ──────────────────────────────────────

	public function test_regenerate_ends_the_spai_credentials_too(): void {
		$this->seed_store_key();
		$auth   = new Mcpwp_Spai_Adoption_Test_Auth();
		$single = $this->key( 'm1' );
		$multi  = $this->key( 'm2' );
		update_option( 'spai_api_key', wp_hash_password( $single ) );
		update_option( 'spai_api_keys', array( array( 'id' => 'm1', 'hash' => wp_hash_password( $multi ), 'revoked_at' => null ) ) );
		$this->assertTrue( $this->works( $single ) );
		$this->assertTrue( $this->works( $multi ) );

		$new = $auth->regenerate_api_key();

		$this->assertNotSame( '', $new );
		$this->assertFalse( $this->works( $single ) );
		$this->assertFalse( $this->works( $multi ) );
		$this->assertTrue( $this->works( $new ) );
		$this->assertTrue( $this->gone( 'spai_api_key' ) );
		$this->assertTrue( $this->gone( 'spai_api_keys' ) );
	}

	public function test_regenerate_still_refuses_the_spai_keys_when_their_deletion_fails(): void {
		$this->seed_store_key();
		$auth   = new Mcpwp_Spai_Adoption_Test_Auth();
		$single = $this->key( 'm3' );
		update_option( 'spai_api_key', wp_hash_password( $single ) );
		$GLOBALS['mcpwp_test_delete_option_fail']['spai_api_key'] = true;

		$new = $auth->regenerate_api_key();

		$this->assertFalse( $this->gone( 'spai_api_key' ), 'Precondition: the delete did not take.' );
		$this->assertFalse( $this->works( $single ), 'The revoked placeholder keeps it refused.' );
		$this->assertTrue( $this->works( $new ) );
		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$this->assertTrue( $this->later_load(), 'And a later adoption does not revive it.' );
		$this->assertFalse( $this->works( $single ) );
	}

	public function test_regenerate_still_refuses_a_live_spai_store_record_when_its_deletion_fails(): void {
		$this->seed_store_key();
		$auth  = new Mcpwp_Spai_Adoption_Test_Auth();
		$multi = $this->key( 'm6' );
		update_option( 'spai_api_keys', array( array( 'id' => 'live1', 'hash' => wp_hash_password( $multi ), 'revoked_at' => null ) ) );
		$GLOBALS['mcpwp_test_delete_option_fail']['spai_api_keys'] = true;

		$new = $auth->regenerate_api_key();

		$this->assertFalse( $this->gone( 'spai_api_keys' ), 'Precondition: the delete did not take.' );
		$this->assertFalse( $this->works( $multi ), 'Written as a revoked placeholder, so it stays refused.' );
		$this->assertTrue( $this->works( $new ) );
	}

	public function test_a_refused_rotation_leaves_the_spai_keys_alone(): void {
		$this->seed_store_key();
		$auth   = new Mcpwp_Spai_Adoption_Test_Auth();
		$single = $this->key( 'm4' );
		update_option( 'spai_api_key', wp_hash_password( $single ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|deadbeef';

		$this->assertSame( '', $auth->regenerate_api_key(), 'Busy: nothing rotated.' );

		$this->assertFalse( $this->gone( 'spai_api_key' ), 'The spai key is deleted only after the new key exists.' );
		$this->assertTrue( $this->works( $single ) );
	}

	public function test_ending_the_spai_credentials_revokes_an_adopted_record_that_slipped_in_after_the_rotation(): void {
		$this->seed_store_key();
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$key  = $this->key( 'm5' );
		update_option( 'spai_api_key', wp_hash_password( $key ) );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertTrue( $this->works( $key ) );
		update_option( 'spai_api_key', wp_hash_password( $key ) ); // Adoption landed; its delete is "later".

		$this->assertTrue( Mcpwp_Activator::end_spai_credentials() );

		$this->assertFalse( $this->works( $key ) );
	}

	// ── placement ─────────────────────────────────────────────────────────

	public function test_adoption_runs_after_the_migration_on_load_and_on_activation(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/mcpwp.php' );
		$this->assertSame( 3, substr_count( $src, 'Mcpwp_Activator::adopt_spai_credentials();' ), 'Load, single-site activation, network activation.' );
		$this->assertMatchesRegularExpression( '/Mcpwp_Migrate::run\(\);\s*\} catch.*?if \( ! get_option\( \'mcpwp_spai_credentials_adopted\' \) \)/s', $src, 'On load: after Migrate::run().' );
		$this->assertSame( 2, preg_match_all( '/Mcpwp_Migrate::run\(\);\s*(?:Mcpwp_Activator::adopt_spai_credentials\(\);)/', $src ), 'On activation (both branches): right after Migrate::run().' );
	}

	public function test_the_uninstaller_names_the_flag_exactly_and_adds_no_lock(): void {
		$all = array_merge( Mcpwp_Uninstaller::LEGACY_OPTIONS, Mcpwp_Uninstaller::APPROVALS_OPTIONS );
		$this->assertContains( 'mcpwp_spai_credentials_adopted', $all );
		$this->assertNotContains( 'mcpwp_key_store_lock', $all, 'This work adds no lock of its own.' );
		foreach ( $all as $name ) {
			$this->assertStringNotContainsString( '%', $name, 'Exact names, no wildcards (#1159).' );
		}
		$this->assertStringNotContainsString( 'mcpwp_key_store_lock', (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php' ) );
	}
}
