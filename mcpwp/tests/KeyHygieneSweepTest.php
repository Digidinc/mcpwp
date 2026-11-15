<?php
/**
 * Upgrade-time key hygiene: keys whose secret matches a stored verifier are revoked.
 *
 * Covered: a matching record is revoked whichever stored verifier it matches;
 * plaintext option leftovers and non-verifier record hashes are not candidates;
 * normal records are untouched; several records at once; the sweep is bounded
 * and resumes (including under real bcrypt); progress survives added records;
 * an unreadable or locked store fails closed; a refused revoke is reported and
 * retried; a match whose target was revoked/deleted/regenerated elsewhere does
 * not stick the sweep; matched state stores fingerprints only; admin does the
 * external pass under a short budget and cron finishes record-vs-record (with a
 * tiny admin fallback); a store write between snapshot and revoke returns
 * changed; the notice and the Site Health item are gated and escaped and never
 * show a hash; admin ajax/admin-post/REST/cron skip the admin_init sweep; the
 * plugin wires upgrade, activation, admin_init and the cron hook.
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

	/** @var array<string,mixed> */
	private $server_backup = array();

	/** @var mixed */
	private $pagenow_backup;

	protected function setUp(): void {
		$this->server_backup  = $_SERVER;
		$this->pagenow_backup = $GLOBALS['pagenow'] ?? null;
		$GLOBALS['mcpwp_test_options']         = array();
		$GLOBALS['mcpwp_test_transients']      = array();
		$GLOBALS['mcpwp_test_current_user']    = 0;
		$GLOBALS['mcpwp_test_user_caps']       = array();
		$GLOBALS['mcpwp_test_user_meta']       = array();
		$GLOBALS['mcpwp_test_filters']         = array();
		$GLOBALS['mcpwp_test_actions']         = array();
		$GLOBALS['mcpwp_test_wpdb_options']    = array();
		$GLOBALS['mcpwp_test_object_cache']    = array();
		$GLOBALS['mcpwp_test_doing_ajax']      = false;
		$GLOBALS['mcpwp_test_doing_cron']      = false;
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'utf8mb4';
		$GLOBALS['mcpwp_test_cron']            = array();
		unset( $GLOBALS['pagenow'] );
		unset( $_SERVER['REQUEST_URI'], $_SERVER['PHP_SELF'], $_SERVER['SCRIPT_NAME'], $_SERVER['SCRIPT_FILENAME'] );
		$_GET  = array();
		$_POST = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_current_user']     = 0;
		$GLOBALS['mcpwp_test_user_caps']        = array();
		$GLOBALS['mcpwp_test_wpdb_options']     = array();
		$GLOBALS['mcpwp_test_doing_ajax']       = false;
		$GLOBALS['mcpwp_test_doing_cron']       = false;
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'utf8mb4';
		$GLOBALS['mcpwp_test_filters']         = array();
		$GLOBALS['mcpwp_test_actions']         = array();
		$GLOBALS['mcpwp_test_cron']            = array();
		$_SERVER = $this->server_backup;
		if ( null === $this->pagenow_backup ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->pagenow_backup;
		}
		$_POST = array();
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
		$this->assertNotNull( $auth->scoped_lookup( $legacy_hash ), 'Precondition: the record is active and its secret is the stored verifier.' );

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
		$this->assertSame( $normal, $this->by_id( 'k_normal' ), 'A normal record is left exactly as it was.' );
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
		$this->assertSame( array( 'k_match' ), array_column( Mcpwp_Key_Hygiene::revoked_records(), 'id' ) );

		$this->assertNull( $auth->scoped_lookup( $legacy_hash ), 'The revoked record no longer authenticates.' );
		$this->assertNull( get_option( Mcpwp_Key_Hygiene::STATE_OPTION, null ), 'No position is left behind.' );
	}

	public function test_each_kind_of_stored_verifier_is_a_candidate(): void {
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
		// stored string. Its secret is still only the original key material.
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

	public function test_a_plaintext_option_value_is_not_a_candidate(): void {
		// Only stored verifiers are candidates. A plaintext leftover in
		// mcpwp_api_key is not one, so a record that verifies it stays active.
		$plaintext = 'mcpwp_' . str_repeat( 'p', 48 );
		update_option( 'mcpwp_api_key', $plaintext );
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k_plain', 'Adopted plaintext', wp_hash_password( $plaintext ) ) ) );

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertSame( array(), $outcome['revoked'] );
		$this->assertNull( $this->by_id( 'k_plain' )['revoked_at'] );
	}

	public function test_plaintext_2_8_adopted_record_stays_active_and_authenticates(): void {
		// 2.8 shape: plaintext spai_api_key copied into mcpwp_api_key, then
		// adopt_spai_credentials() writes spai_legacy_single as
		// wp_hash_password(K). The sweep must leave that record active so K
		// still authenticates.
		$k = 'mcpwp_' . str_repeat( 'k', 48 );
		update_option( 'spai_api_key', $k );
		update_option( 'mcpwp_api_key', $k );
		update_option( 'spai_api_keys', array( array( 'id' => 'other', 'hash' => wp_hash_password( 'other' ) ) ) );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'spai_legacy_single', 'Legacy single', wp_hash_password( $k ) ),
				$this->record( 'spai_other', 'Other', wp_hash_password( 'other-key' ) ),
			)
		);

		$auth = new Mcpwp_Key_Hygiene_Test_Auth();
		$this->assertNotNull( $auth->scoped_lookup( $k ), 'Precondition: K authenticates.' );

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertSame( array(), $outcome['revoked'] );
		$this->assertNull( $this->by_id( 'spai_legacy_single' )['revoked_at'] );
		$this->assertNotNull( $auth->scoped_lookup( $k ), 'K still authenticates after the sweep.' );
	}

	public function test_md5_shaped_candidates_include_both_hex_cases(): void {
		$md5_lower = md5( 'md5-hex-case' );
		$md5_upper = strtoupper( $md5_lower );
		update_option( 'mcpwp_api_key', $md5_lower );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k_from_upper', 'From upper', wp_hash_password( $md5_upper ) ),
				$this->record( 'k_normal', 'Normal', wp_hash_password( 'mcpwp_' . str_repeat( 'z', 48 ) ) ),
			)
		);

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertNotEmpty( $this->by_id( 'k_from_upper' )['revoked_at'] );
		$this->assertNull( $this->by_id( 'k_normal' )['revoked_at'] );
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

		$runs = 0;
		do {
			$outcome = Mcpwp_Key_Hygiene::run( self::LONG, 3 );
			++$runs;
			if ( 'partial' === $outcome['status'] ) {
				$this->assertFalse( Mcpwp_Key_Hygiene::is_done(), 'No flag while records are unchecked.' );
				$this->assertIsArray( get_option( Mcpwp_Key_Hygiene::STATE_OPTION ), 'The position is kept.' );
			}
		} while ( 'partial' === $outcome['status'] && $runs < 100 );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertGreaterThan( 3, $runs, 'Three checks per run cannot cover 7 records x candidates at once.' );
		$this->assertLessThanOrEqual( 40, $runs );
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

	public function test_a_new_record_mid_sweep_keeps_prior_progress_and_is_checked(): void {
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
		$state_before = get_option( Mcpwp_Key_Hygiene::STATE_OPTION );
		$this->assertIsArray( $state_before );
		$this->assertNotEmpty( $state_before['done'] );

		Mcpwp_Key_Store::mutate(
			function ( array $keys ) use ( $legacy ) {
				$keys[] = $this->record( 'k_new', 'New', md5( $legacy ) );
				return array( $keys, true );
			}
		);

		do {
			$outcome = Mcpwp_Key_Hygiene::run( self::LONG, 1 );
		} while ( in_array( $outcome['status'], array( 'partial', 'changed' ), true ) );

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

	public function test_bcrypt_time_budget_bounds_work_and_the_sweep_resumes(): void {
		$legacy = wp_hash_password( 'bcrypt-legacy' );
		update_option( 'mcpwp_api_key', $legacy );
		$records = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$records[] = $this->record( 'bk' . $i, 'Bcrypt ' . $i, wp_hash_password( 'mcpwp_bcrypt_' . $i . str_repeat( 'y', 40 ) ) );
		}
		$records[] = $this->record( 'bk_match', 'Match', wp_hash_password( $legacy ) );
		update_option( Mcpwp_Key_Store::OPTION, $records );

		$first = Mcpwp_Key_Hygiene::run( 0.05, 100000 );
		$this->assertSame( 'partial', $first['status'], 'A tight time budget must stop before finishing real bcrypt checks.' );
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
		$this->assertIsArray( get_option( Mcpwp_Key_Hygiene::STATE_OPTION ) );

		$runs = 1;
		do {
			$outcome = Mcpwp_Key_Hygiene::run( 0.5, 100000 );
			++$runs;
		} while ( 'partial' === $outcome['status'] && $runs < 200 );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertGreaterThan( 1, $runs );
		$this->assertNotEmpty( $this->by_id( 'bk_match' )['revoked_at'] );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->assertNull( $this->by_id( 'bk' . $i )['revoked_at'] );
		}
	}

	public function test_a_write_between_snapshot_and_revoke_returns_changed_and_keeps_progress(): void {
		$legacy = md5( 'legacy-changed' );
		update_option( 'mcpwp_api_key', $legacy );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k_match', 'Match', md5( $legacy ) ),
				$this->record( 'k_other', 'Other', md5( 'other' ) ),
			)
		);

		add_action(
			'mcpwp_key_hygiene_between_checks',
			static function () {
				Mcpwp_Key_Store::mutate(
					static function ( array $keys ) {
						$keys[] = array(
							'id'           => 'k_injected',
							'label'        => 'Injected',
							'hash'         => md5( 'injected' ),
							'scopes'       => array( 'read' ),
							'created_at'   => '2026-01-01 00:00:00',
							'last_used_at' => null,
							'revoked_at'   => null,
						);
						return array( $keys, true );
					}
				);
			}
		);

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG, 100000 );
		unset( $GLOBALS['mcpwp_test_actions']['mcpwp_key_hygiene_between_checks'] );

		$this->assertSame( 'changed', $outcome['status'] );
		$this->assertNull( $this->by_id( 'k_match' )['revoked_at'], 'No revoke applied on a changed store.' );
		$this->assertIsArray( get_option( Mcpwp_Key_Hygiene::STATE_OPTION ), 'Progress is kept for the next run.' );
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );

		do {
			$outcome = Mcpwp_Key_Hygiene::run( self::LONG, 100000 );
		} while ( in_array( $outcome['status'], array( 'partial', 'changed' ), true ) );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
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

	public function test_a_refused_revoke_is_reported_and_retried(): void {
		$legacy = md5( 'legacy-pending' );
		update_option( 'mcpwp_api_key', $legacy );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array( $this->record( 'k_match', "label \u{1F600}", md5( $legacy ) ) )
		);
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'latin1';

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );
		$this->assertSame( 'unsupported_characters', $outcome['status'] );
		$this->assertNull( $this->by_id( 'k_match' )['revoked_at'] );
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
		$this->assertSame( array( 'k_match' ), array_column( Mcpwp_Key_Hygiene::pending_records(), 'id' ) );

		$health = Mcpwp_Key_Hygiene::run_site_status_test();
		$this->assertSame( 'critical', $health['status'] );
		$this->assertStringContainsString( 'could not be revoked', $health['description'] );
		$this->assertStringContainsString( 'k_match', $health['description'] );
		$this->assertStringNotContainsString( md5( $legacy ), $health['description'] );

		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'utf8mb4';
		$retry = Mcpwp_Key_Hygiene::run( self::LONG );
		$this->assertSame( 'done', $retry['status'] );
		$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
		$this->assertSame( array(), Mcpwp_Key_Hygiene::pending_records() );
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

		$this->assertSame( array(), Mcpwp_Key_Hygiene::run( self::LONG )['revoked'] );
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
		$this->assertStringContainsString( 'secret matched a stored verifier', $html );
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

	public function test_admin_init_skips_ajax_admin_post_rest_and_cron(): void {
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k1', 'One', md5( 'one' ) ) ) );
		$this->become_admin();

		$GLOBALS['mcpwp_test_doing_ajax'] = true;
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
		$GLOBALS['mcpwp_test_doing_ajax'] = false;

		add_filter( 'mcpwp_key_hygiene_is_admin_post', static function () { return true; } );
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
		$GLOBALS['mcpwp_test_filters'] = array();

		add_filter( 'mcpwp_key_hygiene_is_rest_request', static function () { return true; } );
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
		$GLOBALS['mcpwp_test_filters'] = array();

		$GLOBALS['mcpwp_test_doing_cron'] = true;
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
		$GLOBALS['mcpwp_test_doing_cron'] = false;

		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
	}

	public function test_admin_post_skip_matches_script_name_only(): void {
		update_option( Mcpwp_Key_Store::OPTION, array( $this->record( 'k1', 'One', md5( 'one' ) ) ) );
		$this->become_admin();

		$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=mcpwp&redirect_to=admin-post.php';
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done(), 'admin-post.php in a query string must not skip the sweep.' );

		delete_option( Mcpwp_Key_Hygiene::FLAG );
		$_SERVER['REQUEST_URI'] = '/wp-admin/admin-post.php?action=something';
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done(), 'The admin-post.php script itself is skipped.' );

		unset( $_SERVER['REQUEST_URI'] );
		$GLOBALS['pagenow'] = 'admin-post.php';
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
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
		$cron = array_column( $GLOBALS['mcpwp_test_actions'][ Mcpwp_Key_Hygiene::CRON_HOOK ][10] ?? array(), 'callback' );
		$this->assertContains( array( 'Mcpwp_Key_Hygiene', 'maybe_run_for_cron' ), $cron );
	}

	public function test_the_uninstaller_removes_the_sweep_options(): void {
		require_once dirname( __DIR__ ) . '/includes/class-mcpwp-uninstaller.php';
		foreach ( array( Mcpwp_Key_Hygiene::FLAG, Mcpwp_Key_Hygiene::STATE_OPTION, Mcpwp_Key_Hygiene::REVOKED_OPTION, Mcpwp_Key_Hygiene::PENDING_OPTION, Mcpwp_Key_Hygiene::LOCK ) as $option ) {
			$this->assertContains( $option, Mcpwp_Uninstaller::LEGACY_OPTIONS );
		}
		$this->assertContains( Mcpwp_Key_Hygiene::DISMISSED_META, Mcpwp_Uninstaller::LEGACY_USER_META );
		$this->assertContains( Mcpwp_Key_Hygiene::CRON_HOOK, Mcpwp_Uninstaller::LEGACY_CRON_HOOKS );
	}

	// ── review follow-ups ────────────────────────────────────────────────

	private function seed_refused_match( string $id = 'k_match' ): string {
		$legacy = md5( 'legacy-stuck-' . $id );
		update_option( 'mcpwp_api_key', $legacy );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array( $this->record( $id, "label \u{1F600}", md5( $legacy ) ) )
		);
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'latin1';
		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );
		$this->assertSame( 'unsupported_characters', $outcome['status'] );
		$this->assertSame( array( $id ), array_column( Mcpwp_Key_Hygiene::pending_records(), 'id' ) );
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'utf8mb4';
		return $legacy;
	}

	public function test_a_match_revoked_elsewhere_clears_pending_and_can_finish(): void {
		$this->seed_refused_match( 'k_elsewhere' );
		$state = get_option( Mcpwp_Key_Hygiene::STATE_OPTION );
		$this->assertIsArray( $state );
		$this->assertNotEmpty( $state['matched'] );
		foreach ( $state['matched'] as $info ) {
			$this->assertArrayHasKey( 'candidate_fp', $info );
			$this->assertArrayNotHasKey( 'candidate', $info );
		}

		Mcpwp_Key_Store::mutate(
			static function ( array $keys ) {
				foreach ( $keys as $i => $record ) {
					if ( isset( $record['id'] ) && 'k_elsewhere' === $record['id'] ) {
						$keys[ $i ]['revoked_at'] = '2026-04-01 00:00:00';
					}
				}
				return array( $keys, true );
			}
		);

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );
		$this->assertSame( 'done', $outcome['status'] );
		$this->assertSame( array(), Mcpwp_Key_Hygiene::pending_records() );
		$this->assertSame( 'good', Mcpwp_Key_Hygiene::run_site_status_test()['status'] );
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
	}

	public function test_a_match_deleted_elsewhere_clears_pending_and_can_finish(): void {
		$this->seed_refused_match( 'k_deleted' );

		Mcpwp_Key_Store::mutate(
			static function ( array $keys ) {
				return array( array(), true );
			}
		);

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );
		$this->assertSame( 'done', $outcome['status'] );
		$this->assertSame( array(), Mcpwp_Key_Hygiene::pending_records() );
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
	}

	public function test_a_match_regenerated_elsewhere_clears_pending_and_can_finish(): void {
		$this->seed_refused_match( 'k_regen' );

		Mcpwp_Key_Store::mutate(
			static function ( array $keys ) {
				foreach ( $keys as $i => $record ) {
					if ( isset( $record['id'] ) && 'k_regen' === $record['id'] ) {
						$keys[ $i ]['hash'] = md5( 'brand-new-secret' );
					}
				}
				return array( $keys, true );
			}
		);

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );
		$this->assertSame( 'done', $outcome['status'] );
		$this->assertSame( array(), Mcpwp_Key_Hygiene::pending_records() );
		$this->assertNull( $this->by_id( 'k_regen' )['revoked_at'], 'A regenerated record is not revoked for the old match.' );
		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
	}

	public function test_a_plaintext_record_hash_is_not_an_internal_candidate(): void {
		$plaintext = 'mcpwp_' . str_repeat( 'q', 48 );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k_plain_hash', 'Plain', $plaintext ),
				$this->record( 'k_hashed_plain', 'Hashed', wp_hash_password( $plaintext ) ),
			)
		);

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );
		$this->assertSame( 'done', $outcome['status'] );
		$this->assertSame( array(), $outcome['revoked'] );
		$this->assertNull( $this->by_id( 'k_hashed_plain' )['revoked_at'] );
		$this->assertNull( $this->by_id( 'k_plain_hash' )['revoked_at'] );
	}

	public function test_matched_state_never_stores_the_candidate_string(): void {
		$legacy = md5( 'legacy-fp-only' );
		update_option( 'mcpwp_api_key', $legacy );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array( $this->record( 'k_match', "label \u{1F600}", md5( $legacy ) ) )
		);
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'latin1';
		Mcpwp_Key_Hygiene::run( self::LONG );
		$GLOBALS['mcpwp_test_wpdb_col_charset'] = 'utf8mb4';

		$state = get_option( Mcpwp_Key_Hygiene::STATE_OPTION );
		$this->assertIsArray( $state );
		$encoded = wp_json_encode( $state );
		$this->assertIsString( $encoded );
		$this->assertStringNotContainsString( $legacy, $encoded );
		$this->assertStringNotContainsString( md5( $legacy ), $encoded );
		foreach ( $state['matched'] as $info ) {
			$this->assertArrayHasKey( 'candidate_fp', $info );
			$this->assertArrayNotHasKey( 'candidate', $info );
			$this->assertStringStartsWith( 'c', $info['candidate_fp'] );
		}
	}

	public function test_cron_path_completes_record_vs_record_work(): void {
		$other = wp_hash_password( 'other-secret-cron' );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k_other', 'Other', $other ),
				$this->record( 'k_match', 'Match', wp_hash_password( $other ) ),
				$this->record( 'k_ok', 'Ok', wp_hash_password( 'mcpwp_' . str_repeat( 'o', 48 ) ) ),
			)
		);

		$this->become_admin();
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$this->assertFalse( Mcpwp_Key_Hygiene::is_done(), 'Admin external pass alone cannot finish record-vs-record work.' );
		$this->assertNotFalse( wp_next_scheduled( Mcpwp_Key_Hygiene::CRON_HOOK ) );

		$runs = 0;
		do {
			Mcpwp_Key_Hygiene::maybe_run_for_cron();
			++$runs;
		} while ( ! Mcpwp_Key_Hygiene::is_done() && $runs < 50 );

		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
		$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
		$this->assertNull( $this->by_id( 'k_other' )['revoked_at'] );
		$this->assertFalse( wp_next_scheduled( Mcpwp_Key_Hygiene::CRON_HOOK ) );
	}

	public function test_admin_path_is_time_bounded_and_schedules_cron(): void {
		$legacy = wp_hash_password( 'admin-bound-legacy' );
		update_option( 'mcpwp_api_key', $legacy );
		$records = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$records[] = $this->record( 'ab' . $i, 'Admin ' . $i, wp_hash_password( 'mcpwp_admin_' . $i . str_repeat( 'z', 40 ) ) );
		}
		$records[] = $this->record( 'ab_match', 'Match', wp_hash_password( $legacy ) );
		update_option( Mcpwp_Key_Store::OPTION, $records );

		$this->become_admin();
		$started = microtime( true );
		Mcpwp_Key_Hygiene::maybe_run_for_admin();
		$elapsed = microtime( true ) - $started;

		$this->assertFalse( Mcpwp_Key_Hygiene::is_done() );
		$this->assertLessThan( 1.0, $elapsed, 'Admin path must stay well under a second even with bcrypt.' );
		$this->assertNotFalse( wp_next_scheduled( Mcpwp_Key_Hygiene::CRON_HOOK ) );
	}

	public function test_admin_fallback_slices_can_finish_without_cron(): void {
		$other = md5( 'fallback-other' );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k_other', 'Other', $other ),
				$this->record( 'k_match', 'Match', md5( $other ) ),
			)
		);
		$this->become_admin();

		// Disable cron scheduling side-effects by clearing after each admin pass.
		$runs = 0;
		do {
			Mcpwp_Key_Hygiene::maybe_run_for_admin();
			wp_clear_scheduled_hook( Mcpwp_Key_Hygiene::CRON_HOOK );
			++$runs;
		} while ( ! Mcpwp_Key_Hygiene::is_done() && $runs < 200 );

		$this->assertTrue( Mcpwp_Key_Hygiene::is_done() );
		$this->assertNotEmpty( $this->by_id( 'k_match' )['revoked_at'] );
		$this->assertLessThan( 200, $runs );
	}

	public function test_duplicate_hash_records_are_all_revoked(): void {
		$legacy = md5( 'legacy-dup' );
		$hash   = md5( $legacy );
		update_option( 'mcpwp_api_key', $legacy );
		update_option(
			Mcpwp_Key_Store::OPTION,
			array(
				$this->record( 'k_a', 'A', $hash ),
				$this->record( 'k_b', 'B', $hash ),
			)
		);

		$outcome = Mcpwp_Key_Hygiene::run( self::LONG );
		$this->assertSame( 'done', $outcome['status'] );
		$this->assertNotEmpty( $this->by_id( 'k_a' )['revoked_at'] );
		$this->assertNotEmpty( $this->by_id( 'k_b' )['revoked_at'] );
		$auth = new Mcpwp_Key_Hygiene_Test_Auth();
		$this->assertNull( $auth->scoped_lookup( $legacy ) );
	}
}
