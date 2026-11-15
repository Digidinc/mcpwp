<?php
/**
 * Upgrade-time key hygiene: keys whose secret matches a stored value are revoked.
 *
 * Covered: a matching record is revoked whichever stored value it matches;
 * normal records are untouched; several records at once; the sweep is
 * bounded and resumes; an unreadable or locked store fails closed; a rerun
 * changes nothing; the notice and the Site Health item are gated and escaped
 * and never show a hash; the plugin wires the sweep into upgrade, activation
 * and admin_init.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-store.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-key-hygiene.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-key-hygiene-matcher.php';

class Mcpwp_Key_Hygiene_Test_Auth {
	use Mcpwp_Api_Auth;

	public function scoped_lookup( $api_key ) {
		return $this->find_scoped_api_key( $api_key );
	}
}

final class KeyHygieneSweepTest extends TestCase {

	private const CORRUPT = 'a:2:{i:0;a:7:{s:2:"id";s:36:"11111111-1111-1111-1111-111111111111";s:5:"label";s:3:"';

	/** A budget no test reaches: one run finishes the sweep. */
	private const LONG = 600.0;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_object_cache'] = array();
		$_GET                               = array();
		$_POST                              = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$_POST                              = array();
	}

	// ── fixtures ─────────────────────────────────────────────────────────

	private function record( string $id, string $label, string $hash, $revoked_at = null ): array {
		return array(
			'id'           => $id,
			'label'        => $label,
			'hash'         => $hash,
			'scopes'       => array( 'read', 'write' ),
			'created_at'   => '2026-01-01 00:00:00',
			'last_used_at' => null,
			'revoked_at'   => $revoked_at,
		);
	}

	private function store(): array {
		$store = get_option( Mcpwp_Key_Store::OPTION );
		$this->assertIsArray( $store );
		return $store;
	}

	private function by_id( string $id ): array {
		foreach ( $this->store() as $record ) {
			if ( $record['id'] === $id ) {
				return $record;
			}
		}
		$this->fail( "No record $id." );
	}

	private function become_admin(): void {
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'][1] = array( 'manage_options' => true );
	}

	private function render_notice(): string {
		ob_start();
		Mcpwp_Key_Hygiene::render_notice();
		return (string) ob_get_clean();
	}

	// ── revocation ───────────────────────────────────────────────────────

	public function test_a_record_whose_secret_is_the_legacy_option_value_is_revoked(): void {
		$legacy_hash = wp_hash_password( 'legacy-secret' );
		update_option( 'mcpwp_api_key', $legacy_hash );
		$normal = $this->record( 'k_normal', 'Normal', wp_hash_password( 'mcpwp_' . str_repeat( 'n', 48 ) ) );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k_match', 'Matches', wp_hash_password( $legacy_hash ) ),
				$normal,
			)
		);

		$auth = new Mcpwp_Key_Hygiene_Test_Auth();
		$this->assertNotNull( $auth->scoped_lookup( $legacy_hash ), 'Precondition: the record is active and its secret is the stored value.' );

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
		$this->assertSame( $normal, $this->by_id( 'k_normal' ), 'A normal record is left exactly as it was.' );
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
		$this->assertSame( array( 'k_match' ), array_column( Mcpwp_Key_Hygiene::revoked_records(), 'id' ) );

		$this->assertNull( $auth->scoped_lookup( $legacy_hash ), 'The revoked record no longer authenticates.' );
		$this->assertNull( get_option( Mcpwp_Key_Hygiene::STATE_OPTION, null ), 'No position is left behind.' );
	}

	public function test_each_kind_of_stored_value_is_a_candidate(): void {
		$spai_single = wp_hash_password( 'spai-single' );
		$spai_record = wp_hash_password( 'spai-record' );
		$other_hash  = wp_hash_password( 'other-secret' );
		$md5_value   = md5( 'md5-era' );
		update_option( 'spai_api_key', $spai_single );
		update_option( 'spai_api_keys', array( array( 'id' => 's1', 'hash' => $spai_record ) ) );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k_other', 'Other', $other_hash ),
				$this->record( 'k_old_md5', 'Old', $md5_value, '2026-02-02 00:00:00' ),
				$this->record( 'k_spai_single', 'A', wp_hash_password( $spai_single ) ),
				$this->record( 'k_spai_record', 'B', md5( $spai_record ) ),
				$this->record( 'k_other_record', 'C', wp_hash_password( $other_hash ) ),
				$this->record( 'k_revoked_record', 'D', md5( $md5_value ) ),
			)
		);

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG, 100000 );

		$this->assertSame( 'done', $outcome['status'] );
		$revoked = array_column( $outcome['revoked'], 'id' );
		sort( $revoked );
		$this->assertSame( array( 'k_other_record', 'k_revoked_record', 'k_spai_record', 'k_spai_single' ), $revoked );
		$this->assertNull( $this->by_id( 'k_other' )['revoked_at'], 'A record whose hash is a candidate is not itself revoked for it.' );
		$this->assertSame( '2026-02-02 00:00:00', $this->by_id( 'k_old_md5' )['revoked_at'], 'An already revoked record keeps its revocation time.' );
	}

	public function test_a_record_carrying_a_stored_verifier_verbatim_is_not_revoked(): void {
		// The shape adopt_spai_credentials() writes: a stored verifier is carried
		// into the store as it is, never re-hashed, so the record's hash IS the
		// stored string. Its secret is still only the key nobody has written down.
		$legacy = wp_hash_password( 'mcpwp_' . str_repeat( 'v', 48 ) );
		$md5    = md5( 'mcpwp_' . str_repeat( 'w', 48 ) );
		update_option( 'mcpwp_api_key', $legacy );
		update_option( 'spai_api_key', $md5 );
		$store = array(
			$this->record( 'k_legacy', 'Carried legacy', $legacy ),
			$this->record( 'k_spai', 'Carried spai', $md5 ),
			$this->record( 'k_spai_upper', 'Carried spai, other hex case', strtoupper( $md5 ) ),
		);
		update_option( Mcpwp_Key_Store::OPTION, $store );

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertSame( array(), $outcome['revoked'] );
		$this->assertSame( $store, get_option( Mcpwp_Key_Store::OPTION ) );
	}

	public function test_a_record_hashing_a_plaintext_credential_still_in_the_database_is_revoked(): void {
		// A pre-1.0.14 site stored its single key as plaintext. A record that
		// hashes that value has a secret anyone who can read wp_options already
		// has, so it is revoked and the admin is told to create a new key.
		$plaintext = 'mcpwp_' . str_repeat( 'p', 48 );
		update_option( 'mcpwp_api_key', $plaintext );
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k_plain', 'Adopted plaintext', wp_hash_password( $plaintext ) ) ) );

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertSame( array( 'k_plain' ), array_column( $outcome['revoked'], 'id' ) );
		$this->assertNotEmpty( $this->by_id( 'k_plain' )['revoked_at'] );
	}

	public function test_normal_records_are_untouched_and_the_store_is_not_rewritten(): void {
		$store = array(
			$this->record( 'k1', 'One', wp_hash_password( 'mcpwp_' . str_repeat( 'a', 48 ) ) ),
			$this->record( 'k2', 'Two', md5( 'mcpwp_' . str_repeat( 'b', 48 ) ) ),
			$this->record( 'k3', 'Three', wp_hash_password( 'mcpwp_' . str_repeat( 'c', 48 ) ), '2026-03-03 00:00:00' ),
		);
		update_option( 'mcpwp_api_key', wp_hash_password( 'legacy' ) );
		update_option( Mcpwp_Key_Store::OPTION, $store );
		$writes = 0;
		$GLOBALS['mcpwp_test_raw_option_after_write'] = static function ( $name ) use ( &$writes ) {
			if ( Mcpwp_Key_Store::OPTION === $name ) {
				++$writes;
			}
		};

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );
		unset( $GLOBALS['mcpwp_test_raw_option_after_write'] );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertSame( array(), $outcome['revoked'] );
		$this->assertSame( $store, get_option( Mcpwp_Key_Store::OPTION ) );
		$this->assertSame( 0, $writes, 'Nothing to revoke: the store is not written.' );
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
		$this->assertSame( array(), Mcpwp_Key_Hygiene::revoked_records() );
	}

	public function test_a_record_without_a_hash_string_is_never_revoked(): void {
		$store = array(
			array( 'id' => 'k_nohash', 'label' => 'x', 'revoked_at' => null ),
			array( 'id' => 'k_arrayhash', 'label' => 'y', 'hash' => array( 'x' ), 'revoked_at' => null ),
			array( 'id' => 'k_empty', 'label' => 'z', 'hash' => '', 'revoked_at' => null ),
			'not a record',
		);
		update_option( Mcpwp_Key_Store::OPTION, $store );

		$this->assertSame( 'done', Mcpwp_Key_Hygiene::run( self::LONG )['status'] );
		$this->assertSame( $store, get_option( Mcpwp_Key_Store::OPTION ) );
	}

	public function test_an_empty_or_absent_store_finishes_at_once(): void {
		$this->assertSame( 'done', Mcpwp_Key_Hygiene::run()['status'] );
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
		$this->assertFalse( get_option( Mcpwp_Key_Store::OPTION, false ), 'No store row is created.' );
	}

	// ── bounded and resumable ────────────────────────────────────────────

	public function test_the_sweep_is_bounded_resumes_and_sets_the_flag_only_when_complete(): void {
		$legacy = md5( 'legacy' );
		update_option( 'mcpwp_api_key', $legacy );
		$records = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$records[] = $this->record( 'k' . $i, 'Key ' . $i, md5( 'mcpwp_' . $i . str_repeat( 'x', 40 ) ) );
		}
		$records[] = $this->record( 'k_match', 'Match', md5( $legacy ) );
		update_option( Mcpwp_Key_Store::OPTION, $records );

		$runs     = 0;
		$statuses = array();
		do {
			$outcome    = Mcpwp_Key_Hygiene::run( self::LONG, 3 );
			$statuses[] = $outcome['status'];
			++$runs;
			if ( 'partial' === $outcome['status'] ) {
				$this->assertFalse( Mcpwp_Key_Hygiene::is_done(), 'No flag while records are unchecked.' );
				$this->assertIsArray( get_option( Mcpwp_Key_Hygiene::STATE_OPTION ), 'The position is kept.' );
			}
		} while ( 'partial' === $outcome['status'] && $runs < 100 );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertGreaterThan( 3, $runs, 'Three checks per run cannot cover 7 records x 8 candidates at once.' );
		// 7 targets x 8 candidates = 56 checks at most; resuming never repeats one.
		$this->assertLessThanOrEqual( 19, $runs );
		$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertNull( $this->by_id( 'k' . $i )['revoked_at'] );
		}
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
		$this->assertNull( get_option( Mcpwp_Key_Hygiene::STATE_OPTION, null ) );
	}

	public function test_a_zero_time_budget_still_makes_progress(): void {
		update_option( 'mcpwp_api_key', md5( 'legacy' ) );
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k_match', 'Match', md5( md5( 'legacy' ) ) ) ) );

		$runs = 0;
		do {
			$outcome = Mcpwp_Key_Hygiene::run( 0.0 );
			++$runs;
		} while ( 'partial' === $outcome['status'] && $runs < 20 );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
	}

	public function test_a_new_record_mid_sweep_starts_it_over_and_is_checked(): void {
		$legacy = md5( 'legacy' );
		update_option( 'mcpwp_api_key', $legacy );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k1', 'One', md5( 'one' ) ),
				$this->record( 'k2', 'Two', md5( 'two' ) ),
			)
		);
		$this->assertSame( 'partial', Mcpwp_Key_Hygiene::run( self::LONG, 1 )['status'] );

		// A record arrives between passes.
		Mcpwp_Key_Store::mutate(
			function ( array $keys ) use ( $legacy ) {
				$keys[] = $this->record( 'k_new', 'New', md5( $legacy ) );
				return array( $keys, true );
			}
		);

		do {
			$outcome = Mcpwp_Key_Hygiene::run( self::LONG, 1 );
		} while ( 'partial' === $outcome['status'] );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertNotEmpty( $this->by_id( 'k_new' )['revoked_at'] );
		$this->assertNull( $this->by_id( 'k1' )['revoked_at'] );
	}

	public function test_a_match_found_on_a_partial_pass_is_revoked_then_the_rest_continue(): void {
		$legacy = md5( 'legacy' );
		update_option( 'mcpwp_api_key', $legacy );
		$records = array( $this->record( 'k_match', 'Match', md5( $legacy ) ) );
		for ( $i = 0; $i < 4; $i++ ) {
			$records[] = $this->record( 'k' . $i, 'Key ' . $i, md5( 'k' . $i ) );
		}
		update_option( Mcpwp_Key_Store::OPTION, $records );

		$revoked_on_partial = false;
		do {
			$outcome = Mcpwp_Key_Hygiene::run( self::LONG, 2 );
			if ( 'partial' === $outcome['status'] && ! empty( $outcome['revoked'] ) ) {
				$revoked_on_partial = true;
				$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
			}
		} while ( 'partial' === $outcome['status'] );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertTrue( $revoked_on_partial || ! empty( $outcome['revoked'] ) );
		$this->assertCount( 1, Mcpwp_Key_Hygiene::revoked_records() );
	}

	// ── fails closed ─────────────────────────────────────────────────────

	public function test_an_unreadable_store_revokes_nothing_and_leaves_the_flag_unset(): void {
		update_option( 'mcpwp_api_key', md5( 'legacy' ) );
		update_option( Mcpwp_Key_Store::OPTION, self::CORRUPT );

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'unreadable', $outcome['status'] );
		$this->assertSame( self::CORRUPT, get_option( Mcpwp_Key_Store::OPTION ) );
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
		$this->assertSame( array(), Mcpwp_Key_Hygiene::revoked_records() );
	}

	public function test_a_store_that_turns_unreadable_under_the_lock_fails_closed(): void {
		// The cached copy reads fine; the database row does not.
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k1', 'One', md5( 'one' ) ) ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::OPTION ] = self::CORRUPT;

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'unreadable', $outcome['status'] );
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
	}

	public function test_a_held_store_lock_revokes_nothing_and_leaves_the_flag_unset(): void {
		update_option( 'mcpwp_api_key', md5( 'legacy' ) );
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k_match', 'Match', md5( md5( 'legacy' ) ) ) ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|deadbeef';

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'busy', $outcome['status'] );
		$this->assertNull( $this->by_id( 'k_match' )['revoked_at'] );
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );

		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] );
		$this->assertSame( 'done', Mcpwp_Key_Hygiene::run( self::LONG )['status'] );
		$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
	}

	public function test_a_held_sweep_lock_does_nothing(): void {
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k1', 'One', md5( 'one' ) ) ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Hygiene::LOCK ] = ( time() + 300 ) . '|deadbeef';

		$this->assertSame( 'busy', Mcpwp_Key_Hygiene::run( self::LONG )['status'] );
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
	}

	public function test_run_safely_never_throws(): void {
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::OPTION ] = self::CORRUPT;
		update_option( Mcpwp_Key_Store::OPTION, self::CORRUPT );
		$outcome = Mcpwp_Key_Hygiene::run_safely();
		$this->assertSame( 'unreadable', $outcome['status'] );
	}

	// ── idempotent ───────────────────────────────────────────────────────

	public function test_a_rerun_changes_nothing(): void {
		update_option( 'mcpwp_api_key', md5( 'legacy' ) );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k_match', 'Match', md5( md5( 'legacy' ) ) ),
				$this->record( 'k_normal', 'Normal', md5( 'normal' ) ),
			)
		);

		$this->assertSame( 'done', Mcpwp_Key_Hygiene::run( self::LONG )['status'] );
		$after_first = get_option( Mcpwp_Key_Store::OPTION );
		$listed      = Mcpwp_Key_Hygiene::revoked_records();

		// Finished: a second run is a no-op.
		$this->assertSame( array(), Mcpwp_Key_Hygiene::run( self::LONG )['revoked'] );
		// Even with the flag cleared, nothing new is revoked or listed twice.
		delete_option( Mcpwp_Key_Hygiene::FLAG );
		$again = Mcpwp_Key_Hygiene::run( self::LONG );
		$this->assertSame( 'done', $again['status'] );
		$this->assertSame( array(), $again['revoked'] );

		$this->assertSame( $after_first, get_option( Mcpwp_Key_Store::OPTION ) );
		$this->assertSame( $listed, Mcpwp_Key_Hygiene::revoked_records() );
	}

	// ── reporting ────────────────────────────────────────────────────────

	private function seed_revoked_with_markup(): string {
		$legacy = md5( 'legacy' );
		update_option( 'mcpwp_api_key', $legacy );
		$hash = md5( $legacy );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array( $this->record( 'k_<b>id</b>', '<script>alert(1)</script>', $hash ) )
		);
		Mcpwp_Key_Hygiene::run( self::LONG );
		return $hash;
	}

	public function test_the_notice_is_for_administrators_only(): void {
		$this->seed_revoked_with_markup();

		$this->assertSame( '', $this->render_notice(), 'Logged out: nothing.' );
		$GLOBALS['mcpwp_test_current_user'] = 2;
		$GLOBALS['mcpwp_test_user_caps'][2] = array( 'edit_posts' => true );
		$this->assertSame( '', $this->render_notice(), 'Without manage_options: nothing.' );

		$this->become_admin();
		$this->assertStringContainsString( 'mcpwp-key-hygiene-notice', $this->render_notice() );
	}

	public function test_the_notice_is_escaped_lists_ids_and_labels_and_never_a_hash(): void {
		$hash = $this->seed_revoked_with_markup();
		$this->become_admin();

		$html = $this->render_notice();

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>id</b>', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( $hash, $html );
		$this->assertStringNotContainsString( md5( 'legacy' ), $html );
		$this->assertStringContainsString( 'secret matched a value stored', $html );
		$this->assertStringContainsString( 'Create a new key', $html );
		$this->assertStringContainsString( 'spai_api_key', $html );
		$this->assertStringContainsString( 'MD5', $html );
		$this->assertStringContainsString( Mcpwp_Key_Hygiene::DISMISS_ACTION, $html );
	}

	public function test_no_revocation_means_no_notice(): void {
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k1', 'One', md5( 'one' ) ) ) );
		Mcpwp_Key_Hygiene::run( self::LONG );
		$this->become_admin();
		$this->assertSame( '', $this->render_notice() );
	}

	public function test_the_notice_can_be_dismissed_with_a_valid_nonce_only(): void {
		$this->seed_revoked_with_markup();
		$this->become_admin();

		$this->assertFalse( Mcpwp_Key_Hygiene::process_dismiss( array( Mcpwp_Key_Hygiene::NONCE_FIELD => 'wrong' ) ) );
		$this->assertNotSame( '', $this->render_notice() );

		$GLOBALS['mcpwp_test_current_user'] = 3;
		$GLOBALS['mcpwp_test_user_caps'][3] = array( 'edit_posts' => true );
		$this->assertFalse( Mcpwp_Key_Hygiene::process_dismiss( array( Mcpwp_Key_Hygiene::NONCE_FIELD => wp_create_nonce( Mcpwp_Key_Hygiene::DISMISS_ACTION ) ) ) );

		$this->become_admin();
		$this->assertTrue( Mcpwp_Key_Hygiene::process_dismiss( array( Mcpwp_Key_Hygiene::NONCE_FIELD => wp_create_nonce( Mcpwp_Key_Hygiene::DISMISS_ACTION ) ) ) );
		$this->assertSame( '', $this->render_notice() );
	}

	public function test_site_health_reports_good_then_lists_revoked_records_escaped(): void {
		$this->assertSame( 'good', Mcpwp_Key_Hygiene::run_site_status_test()['status'] );

		$hash   = $this->seed_revoked_with_markup();
		$result = Mcpwp_Key_Hygiene::run_site_status_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'mcpwp_key_hygiene', $result['test'] );
		$this->assertStringContainsString( '&lt;script&gt;', $result['description'] );
		$this->assertStringNotContainsString( '<script>', $result['description'] );
		$this->assertStringNotContainsString( $hash, $result['description'] );
		$this->assertStringContainsString( 'spai_api_key', $result['description'] );

		$tests = Mcpwp_Key_Hygiene::register_site_status_test( array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'mcpwp_key_hygiene', $tests['direct'] );
	}

	// ── wiring ───────────────────────────────────────────────────────────

	public function test_admin_init_runs_the_sweep_for_administrators_only(): void {
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k1', 'One', md5( 'one' ) ) ) );

		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done(), 'Not on an anonymous admin-ajax request.' );

		$this->become_admin();
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
	}

	public function test_the_plugin_runs_the_sweep_on_upgrade_activation_and_admin_init(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/mcpwp.php' );

		$this->assertMatchesRegularExpression( "/update_option\\( 'mcpwp_db_version', MCPWP_VERSION \\);.*?Mcpwp_Key_Hygiene::run_safely\\(\\);/s", $src, 'On upgrade.' );
		$this->assertSame( 2, substr_count( $src, "Mcpwp_Activator::adopt_spai_credentials(); // #1098.\n\t\t\t\tMcpwp_Key_Hygiene::run_safely();" ) + substr_count( $src, "Mcpwp_Activator::adopt_spai_credentials(); // #1098.\n\t\t\tMcpwp_Key_Hygiene::run_safely();" ), 'On activation, single site and network.' );
		$this->assertStringContainsString( 'Mcpwp_Key_Hygiene::init();', $src );

		$GLOBALS['mcpwp_test_actions'] = array();
		Mcpwp_Key_Hygiene::init();
		$callbacks = array_column( $GLOBALS['mcpwp_test_actions']['admin_init'][10] ?? array(), 'callback' );
		$this->assertContains( array( 'Mcpwp_Key_Hygiene', 'maybe_run_for_admin' ), $callbacks );
	}

	public function test_the_uninstaller_removes_the_sweep_options(): void {
		require_once dirname( __DIR__ ) . '/includes/class-mcpwp-uninstaller.php';
		foreach ( array( Mcpwp_Key_Hygiene::FLAG, Mcpwp_Key_Hygiene::STATE_OPTION, Mcpwp_Key_Hygiene::REVOKED_OPTION, Mcpwp_Key_Hygiene::LOCK ) as $option ) {
			$this->assertContains( $option, Mcpwp_Uninstaller::LEGACY_OPTIONS );
		}
		$this->assertContains( Mcpwp_Key_Hygiene::DISMISSED_META, Mcpwp_Uninstaller::LEGACY_USER_META );
	}
}
