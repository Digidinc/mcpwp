<?php
/**
 * #1098 — every 2.8 spai credential ends up in the revocable scoped store,
 * and the spai originals are deleted.
 *
 * Before: on the normal upgrade path the activator minted mcpwp_api_key
 * before Mcpwp_Migrate::run(), so a single spai_api_key was never copied. It
 * then authenticated as admin through find_legacy_spai_key() for good, and
 * no screen could revoke it.
 *
 * Production constraint (mcpwp.net holds spai options AND revoked records):
 * adoption must never resurrect a revoked credential, must be idempotent and
 * flag-guarded, and must never print or log a key or a hash.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-migrate.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';

class Mcpwp_Spai_Adoption_Test_Auth {
	use Mcpwp_Api_Auth;

	public function auth_only( $request ) {
		return $this->verify_api_key_auth_only( $request );
	}
}

final class SpaiCredentialAdoption1098Test extends TestCase {

	/** @var string|false */
	private $saved_error_log;
	/** @var string */
	private $log_file;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_wpdb_options']        = array();
		$GLOBALS['mcpwp_test_update_option_fail']  = array();
		$GLOBALS['mcpwp_test_current_user']        = 0;
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_user_caps']           = array();
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
		$GLOBALS['mcpwp_test_update_option_fail'] = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
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

	private function store(): array {
		$s = get_option( 'mcpwp_api_keys', array() );
		return is_array( $s ) ? $s : array();
	}

	private function records_with_hash( string $hash ): array {
		return array_values( array_filter( $this->store(), static function ( $r ) use ( $hash ) {
			return is_array( $r ) && ( $r['hash'] ?? '' ) === $hash;
		} ) );
	}

	private function id_for_hash( string $hash ): string {
		$r = $this->records_with_hash( $hash );
		return (string) ( $r[0]['id'] ?? '' );
	}

	/**
	 * Run adoption to completion, as successive page loads would: the first
	 * run adopts, a later run confirms and deletes the originals (#1098
	 * round 2). Returns true once complete.
	 */
	private function adopt(): bool {
		for ( $i = 0; $i < 3; $i++ ) {
			if ( Mcpwp_Activator::adopt_spai_credentials() ) {
				return true;
			}
		}
		return false;
	}

	private function key( string $tag ): string {
		return 'spai_' . str_repeat( $tag, 24 );
	}

	// ── the P1: an uncopied single spai key ───────────────────────────────

	public function test_an_uncopied_single_key_is_adopted_and_then_revocable(): void {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$auth->create_scoped_api_key( 'existing integration', array( 'read' ), 'author' );
		$key  = $this->key( 'a1' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		$this->assertTrue( $this->works( $key ), 'Before adoption: the bridge honours it (BridgeAuthTest compatibility).' );

		$this->assertTrue( $this->adopt() );

		$this->assertSame( '__gone__', get_option( 'spai_api_key', '__gone__' ), 'The spai original is deleted.' );
		$records = $this->records_with_hash( $hash );
		$this->assertCount( 1, $records );
		$this->assertSame( Mcpwp_Activator::SPAI_ADOPTED_LABEL, $records[0]['label'] );
		$this->assertSame( 'spai', $records[0]['migrated_from'] );
		$this->assertSame( array( 'read', 'write', 'admin' ), $records[0]['scopes'], 'Admin, as the bridge granted it: no lockout, no change.' );
		$this->assertNull( $records[0]['revoked_at'] );
		$this->assertTrue( $this->works( $key ), 'After adoption: still works, now as a scoped record.' );
		$listed = array_values( array_filter( $auth->list_scoped_api_keys( false ), static function ( $k ) {
			return 'spai' === $k['migrated_from'];
		} ) );
		$this->assertCount( 1, $listed, 'Active Keys lists it, marked as migrated (the Setup note keys off this).' );

		$this->assertTrue( $auth->revoke_scoped_api_key( $records[0]['id'] ) );
		$this->assertFalse( $this->works( $key ), 'Revoked in Active Keys: 401.' );
		$this->adopt();
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
		// A 2.8 site: only spai options. activate() mints mcpwp_api_key first,
		// so Mcpwp_Migrate::run() skips spai_api_key (target already set).
		$key  = $this->key( 'b2' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		update_option( 'spai_settings', array( 'enable_logging' => true ) );

		Mcpwp_Activator::activate();
		$minted = (string) get_transient( 'mcpwp_new_api_key' );
		Mcpwp_Migrate::run();
		$this->assertNotSame( $hash, get_option( 'mcpwp_api_key' ), 'Precondition: the migration did not copy it.' );
		$this->assertSame( array(), $this->records_with_hash( $hash ), 'Precondition: no scoped record — the #1098 P1.' );

		$this->adopt();

		$this->assertCount( 1, $this->records_with_hash( $hash ) );
		$this->assertSame( '__gone__', get_option( 'spai_api_key', '__gone__' ) );
		$this->assertTrue( $this->works( $key ) );
		if ( '' !== $minted ) {
			$this->assertTrue( $this->works( $minted ), 'The key the activator minted keeps working.' );
		}
	}

	public function test_an_empty_store_keeps_its_legacy_mcpwp_key_working(): void {
		// mcpwp_api_key is honoured only while the store is empty; adopting a
		// spai key must not switch it off.
		$legacy = 'mcpwp_' . str_repeat( 'c3', 24 );
		update_option( 'mcpwp_api_key', wp_hash_password( $legacy ) );
		$key = $this->key( 'c4' );
		update_option( 'spai_api_key', wp_hash_password( $key ) );
		// No authenticated request first: the first use of a legacy key moves it
		// into the store by itself, which would hide what this pins.
		$this->assertSame( array(), $this->store() );

		$this->adopt();

		$this->assertCount( 2, $this->store(), 'Both written in the same append.' );

		$this->assertTrue( $this->works( $legacy ), 'The legacy key moved into the store with it.' );
		$this->assertTrue( $this->works( $key ) );
	}

	// ── spai_api_keys records ─────────────────────────────────────────────

	public function test_spai_api_keys_records_are_adopted_with_what_the_bridge_granted(): void {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$auth->create_scoped_api_key( 'existing', array( 'read' ), 'author' );
		$k1 = $this->key( 'd1' );
		$k2 = $this->key( 'd2' );
		$h1 = wp_hash_password( $k1 );
		update_option( 'spai_api_keys', array(
			array( 'id' => 'spai-old-1', 'label' => 'old', 'hash' => $h1, 'scopes' => array( 'read', 'write', 'admin' ), 'created_at' => '2026-01-02 03:04:05', 'last_used_at' => '2026-05-06 07:08:09', 'revoked_at' => null ),
			array( 'id' => 'spai-old-2', 'label' => 'editor', 'hash' => wp_hash_password( $k2 ), 'scopes' => array( 'read', 'write' ), 'role' => 'editor', 'revoked_at' => null ),
		) );
		$before = array( $auth->verify_api_key( $this->request( $k2 ) ) );

		$this->assertTrue( $this->adopt() );

		$this->assertSame( '__gone__', get_option( 'spai_api_keys', '__gone__' ) );
		$r1 = $this->records_with_hash( $h1 );
		$this->assertSame( 'spai-old-1', $r1[0]['id'], 'The original id is kept when free.' );
		$this->assertSame( '2026-01-02 03:04:05', $r1[0]['created_at'] );
		$this->assertSame( '2026-05-06 07:08:09', $r1[0]['last_used_at'] );
		$this->assertSame( 'spai', $r1[0]['migrated_from'] );
		$this->assertSame( 'editor', $this->store()[2]['role'], 'A narrower 2.8 key is not widened to admin.' );
		$this->assertTrue( $this->works( $k1 ) );
		$this->assertSame( $before, array( $auth->verify_api_key( $this->request( $k2 ) ) ) );
	}

	// ── never resurrect ───────────────────────────────────────────────────

	public function test_a_hash_revoked_in_the_store_is_not_adopted_and_its_spai_copy_is_deleted(): void {
		$key  = $this->key( 'e1' );
		$hash = wp_hash_password( $key );
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'rev1', 'label' => 'old', 'hash' => $hash, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => '2026-10-01 00:00:00' ) ) );
		update_option( 'spai_api_key', $hash );
		update_option( 'spai_api_keys', array( array( 'id' => 'rev1', 'label' => 'old', 'hash' => $hash, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => null ) ) );
		$this->assertFalse( $this->works( $key ), 'Precondition: dead (#1097 guard).' );

		$this->assertTrue( $this->adopt() );

		$records = $this->records_with_hash( $hash );
		$this->assertCount( 1, $records, 'No second, active record.' );
		$this->assertSame( '2026-10-01 00:00:00', $records[0]['revoked_at'] );
		$this->assertSame( '__gone__', get_option( 'spai_api_key', '__gone__' ) );
		$this->assertSame( '__gone__', get_option( 'spai_api_keys', '__gone__' ) );
		$this->assertFalse( $this->works( $key ) );
		$this->adopt();
		$this->assertFalse( $this->works( $key ), 'Stays dead.' );
	}

	public function test_a_record_revoked_in_spai_itself_is_not_adopted(): void {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$auth->create_scoped_api_key( 'existing', array( 'read' ), 'author' );
		$key  = $this->key( 'e2' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_keys', array( array( 'id' => 'sr', 'hash' => $hash, 'revoked_at' => '2025-01-01 00:00:00' ) ) );

		$this->adopt();

		$this->assertSame( array(), $this->records_with_hash( $hash ) );
		$this->assertSame( '__gone__', get_option( 'spai_api_keys', '__gone__' ) );
		$this->assertFalse( $this->works( $key ) );
	}

	public function test_an_already_copied_hash_is_not_duplicated(): void {
		$key  = $this->key( 'f1' );
		$hash = wp_hash_password( $key );
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'live1', 'label' => 'old 2.8 key', 'hash' => $hash, 'scopes' => array( 'read', 'write', 'admin' ), 'revoked_at' => null ) ) );
		update_option( 'spai_api_keys', array( array( 'id' => 'live1', 'label' => 'old 2.8 key', 'hash' => $hash, 'revoked_at' => null ) ) );
		update_option( 'spai_api_key', $hash );

		$this->adopt();

		$this->assertCount( 1, $this->store() );
		$this->assertSame( 'old 2.8 key', $this->store()[0]['label'], 'The existing record is left as it was.' );
		$this->assertTrue( $this->works( $key ) );
	}

	public function test_a_second_run_is_a_no_op(): void {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$auth->create_scoped_api_key( 'existing', array( 'read' ), 'author' );
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'f2' ) ) );

		$this->adopt();
		$after_first = $this->store();
		$this->assertSame( 1, get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );

		// Even if a stale spai option reappeared, the flag ends it.
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'f3' ) ) );
		$GLOBALS['mcpwp_test_options'][ Mcpwp_Activator::SPAI_ADOPTION_FLAG ] = 1;
		$this->adopt();
		$this->assertSame( $after_first, $this->store() );

		// And with the flag cleared, a re-run over the same data adds nothing.
		update_option( 'spai_api_key', $after_first[1]['hash'] );
		unset( $GLOBALS['mcpwp_test_options'][ Mcpwp_Activator::SPAI_ADOPTION_FLAG ] );
		$this->adopt();
		$this->assertSame( $after_first, $this->store() );
	}

	/**
	 * The builder's own skip, without the append's re-check behind it: each
	 * guard is pinned separately (the append re-checks the same hashes, which
	 * would otherwise mask a broken skip here).
	 */
	public function test_the_builder_never_proposes_a_hash_the_store_already_holds(): void {
		$revoked = wp_hash_password( 'spai_' . str_repeat( 'r0', 24 ) );
		$live    = wp_hash_password( 'spai_' . str_repeat( 'l0', 24 ) );
		update_option( 'mcpwp_api_keys', array(
			array( 'id' => 'r', 'hash' => $revoked, 'revoked_at' => '2026-10-01 00:00:00' ),
			array( 'id' => 'l', 'hash' => $live, 'revoked_at' => null ),
		) );
		$m = new ReflectionMethod( 'Mcpwp_Activator', 'build_spai_adoption_records' );
		$m->setAccessible( true );
		foreach ( array( 'revoked' => $revoked, 'live' => $live ) as $label => $hash ) {
			$this->assertSame( array(), $m->invoke( null, array( array( 'id' => 'x', 'hash' => $hash, 'revoked_at' => null ) ), '' ), "spai_api_keys record ($label hash)" );
			$this->assertSame( array(), $m->invoke( null, array(), $hash ), "single spai_api_key ($label hash)" );
		}
		$fresh = $m->invoke( null, array(), wp_hash_password( 'spai_' . str_repeat( 'n0', 24 ) ) );
		$this->assertCount( 1, $fresh, 'A hash the store does not hold is proposed.' );
	}

	public function test_the_append_rechecks_a_fresh_read_so_a_concurrent_copy_is_not_duplicated(): void {
		// A hash that landed in the store after the records were built (another
		// request) must not be appended a second time.
		$hash = wp_hash_password( 'spai_' . str_repeat( 'rc', 24 ) );
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'race', 'hash' => $hash, 'revoked_at' => '2026-10-01 00:00:00' ) ) );
		$m = new ReflectionMethod( 'Mcpwp_Activator', 'append_and_verify_scoped_records' );
		$m->setAccessible( true );
		$this->assertTrue( $m->invoke( null, array( array( 'id' => 'new', 'hash' => $hash, 'revoked_at' => null, 'label' => 'x' ) ) ) );
		$this->assertCount( 1, $this->store(), 'Not appended again.' );
		$this->assertSame( '2026-10-01 00:00:00', $this->store()[0]['revoked_at'], 'And not resurrected.' );
	}

	public function test_adoption_runs_after_the_migration_on_load_and_on_activation(): void {
		// mcpwp.php's plugins_loaded and activation bodies cannot run under
		// this bootstrap; the order is pinned at the source instead.
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/mcpwp.php' );
		$this->assertSame( 3, substr_count( $src, 'Mcpwp_Activator::adopt_spai_credentials();' ), 'Load, single-site activation, network activation.' );
		$this->assertMatchesRegularExpression( '/Mcpwp_Migrate::run\(\);\s*\} catch.*?if \( ! get_option\( \'mcpwp_spai_credentials_adopted\' \) \)/s', $src, 'On load: after Migrate::run().' );
		$this->assertSame( 2, preg_match_all( '/Mcpwp_Migrate::run\(\);\s*\/\/ #1098[^\n]*\n\s*Mcpwp_Activator::adopt_spai_credentials\(\);/', $src ), 'On activation (both branches): right after Migrate::run().' );
	}

	// ── partial failure ───────────────────────────────────────────────────

	public function test_a_failed_store_write_keeps_the_originals_and_retries(): void {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$auth->create_scoped_api_key( 'existing', array( 'read' ), 'author' );
		$key  = $this->key( 'g1' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );

		$GLOBALS['mcpwp_test_update_option_fail']['mcpwp_api_keys'] = true;
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertSame( $hash, get_option( 'spai_api_key' ), 'Original kept.' );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ), 'Flag unset.' );
		$this->assertTrue( $this->works( $key ), 'Still honoured by the bridge meanwhile.' );

		$GLOBALS['mcpwp_test_update_option_fail'] = array();
		$this->assertTrue( $this->adopt(), 'Later loads retry and complete.' );
		$this->assertCount( 1, $this->records_with_hash( $hash ) );
		$this->assertSame( '__gone__', get_option( 'spai_api_key', '__gone__' ) );
	}

	public function test_a_failed_delete_leaves_the_flag_unset(): void {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$auth->create_scoped_api_key( 'existing', array( 'read' ), 'author' );
		$hash = wp_hash_password( $this->key( 'g2' ) );
		update_option( 'spai_api_key', $hash );
		// A row only $wpdb sees (the options API cannot delete it) stands in
		// for a delete that did not take.
		$GLOBALS['mcpwp_test_wpdb_options']['spai_api_keys'] = serialize( array() );
		if ( '__x__' === get_option( 'spai_api_keys', '__x__' ) ) {
			$this->markTestSkipped( 'This bootstrap does not read raw option rows through get_option().' );
		}

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials(), 'Run 1 adopts.' );
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials(), 'Run 2 tries the deletes; one does not take.' );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ), 'So the flag stays unset.' );
		$this->assertCount( 1, $this->records_with_hash( $hash ), 'The adoption itself stands; a retry will not duplicate it.' );
	}

	public function test_another_holder_of_the_lock_means_no_change(): void {
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Activator::SPAI_ADOPTION_LOCK_OPTION ] = (string) ( time() + 60 );
		$hash = wp_hash_password( $this->key( 'g3' ) );
		update_option( 'spai_api_key', $hash );

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials() );
		$this->assertSame( $hash, get_option( 'spai_api_key' ) );
		$this->assertSame( array(), $this->store() );
	}

	// ── no secret material anywhere but the store ─────────────────────────

	public function test_no_key_or_hash_is_logged_or_written_anywhere_else(): void {
		$key  = $this->key( 'h1' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		update_option( 'spai_api_keys', array( array( 'id' => 'x1', 'hash' => wp_hash_password( $this->key( 'h2' ) ), 'revoked_at' => null ) ) );

		ob_start();
		$this->adopt();
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
		foreach ( $GLOBALS['mcpwp_test_wpdb_options'] as $name => $value ) {
			$this->assertStringNotContainsString( $hash, (string) $value, "Row $name carries a hash." );
		}
		foreach ( ( new Mcpwp_Spai_Adoption_Test_Auth() )->list_scoped_api_keys( true ) as $listed ) {
			$this->assertArrayNotHasKey( 'hash', $listed, 'The listing (REST /api-keys, Setup) never carries a hash.' );
		}
	}

	// ── regenerate revokes EVERY key ──────────────────────────────────────

	public function test_regenerate_neutralises_spai_credentials_too(): void {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$auth->create_scoped_api_key( 'existing', array( 'read' ), 'author' );
		$single = $this->key( 'i1' );
		$multi  = $this->key( 'i2' );
		update_option( 'spai_api_key', wp_hash_password( $single ) );
		update_option( 'spai_api_keys', array( array( 'id' => 'm1', 'hash' => wp_hash_password( $multi ), 'revoked_at' => null ) ) );
		$this->assertTrue( $this->works( $single ) );
		$this->assertTrue( $this->works( $multi ) );

		$new = $auth->regenerate_api_key();

		$this->assertFalse( $this->works( $single ), 'Regenerate revokes the uncopied 2.8 single key.' );
		$this->assertFalse( $this->works( $multi ), 'And the uncopied 2.8 store record.' );
		$this->assertTrue( $this->works( $new ) );
		$this->assertSame( '__gone__', get_option( 'spai_api_key', '__gone__' ) );
		$this->assertSame( '__gone__', get_option( 'spai_api_keys', '__gone__' ) );
	}

	// ── P2: shared key state on the two remaining surfaces ────────────────

	public function test_onboarding_status_counts_an_active_scoped_key_without_the_legacy_option(): void {
		$auth = new Mcpwp_Spai_Adoption_Test_Auth();
		$auth->create_scoped_api_key( 'only scoped', array( 'read' ), 'author' );
		delete_option( 'mcpwp_api_key' );

		require_once dirname( __DIR__ ) . '/includes/admin/traits/trait-mcpwp-admin-setup.php';
		// The real get_onboarding_status(); only its two data sources are stubbed.
		$admin = new class() {
			use Mcpwp_Api_Auth;
			use Mcpwp_Admin_Setup_Trait;
			const PAGE_SLUG          = 'mcpwp';
			const SETTINGS_PAGE_SLUG = 'mcpwp-settings';
			const LIBRARY_PAGE_SLUG  = 'mcpwp-library';
			public function get_site_profile() {
				return array();
			}
			public function get_library_inventory() {
				return array( 'design_references' => array(), 'page_archetypes' => array(), 'product_archetypes' => array(), 'parts' => array() );
			}
		};
		$steps = $admin->get_onboarding_status();
		$key_step = null;
		foreach ( (array) ( $steps['steps'] ?? $steps ) as $step ) {
			if ( is_array( $step ) && isset( $step['title'] ) && false !== stripos( (string) $step['title'], 'AI key' ) ) {
				$key_step = $step;
			}
		}
		$this->assertNotNull( $key_step );
		$this->assertTrue( $key_step['done'] );
	}
}
