<?php
/**
 * PR #1209 review follow-ups: failed 2.8 key adoption must not lock out
 * silently — patient lock on admin/CLI/REST, re-read after busy, admin
 * notice + Site Health with a recorded reason code (never a secret).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-state.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-key-store.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-updates.php';

class Mcpwp_Spai_Lockout_Test_Auth {
	use Mcpwp_Api_Auth;

	public function auth_only( $request ) {
		return $this->verify_api_key_auth_only( $request );
	}

	public function find_scoped_api_key_public( $api_key ) {
		return $this->find_scoped_api_key( $api_key );
	}
}

final class SpaiAdoptionLockout1209Test extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']                   = array();
		$GLOBALS['mcpwp_test_options_autoload']          = array();
		$GLOBALS['mcpwp_test_transients']                = array();
		$GLOBALS['mcpwp_test_wpdb_options']              = array();
		$GLOBALS['mcpwp_test_delete_option_fail']        = array();
		$GLOBALS['mcpwp_test_current_user']              = 0;
		$GLOBALS['mcpwp_test_user_caps']                 = array();
		$GLOBALS['mcpwp_test_is_admin']                  = false;
		$GLOBALS['mcpwp_test_object_cache']              = array();
		$GLOBALS['mcpwp_test_option_cache_stale']        = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert']        = null;
		$GLOBALS['mcpwp_test_cache_deletes']             = array();
		$GLOBALS['mcpwp_test_wp_hash_password_calls']    = 0;
		$GLOBALS['mcpwp_test_wp_check_password_calls']   = 0;
		unset( $_SERVER['REQUEST_URI'], $_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_AUTHORIZATION'], $_GET['api_key'], $_GET['rest_route'], $_POST['api_key'], $_REQUEST['api_key'] );
		unset( $_COOKIE['wordpress_logged_in_testhash'], $_COOKIE['wordpress_' . str_repeat( 'a', 32 )] );
		unset( $GLOBALS['mcpwp_test_filters']['mcpwp_spai_credential_mutator_prepare'] );
		unset( $GLOBALS['mcpwp_test_actions']['mcpwp_spai_adoption_mutator_run'] );
		if ( method_exists( 'Mcpwp_Activator', 'clear_spai_adoption_request_state' ) ) {
			Mcpwp_Activator::clear_spai_adoption_request_state();
		}
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$GLOBALS['mcpwp_test_option_cache_stale'] = array();
		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_user_caps']          = array();
		$GLOBALS['mcpwp_test_cache_deletes']      = array();
		unset( $_SERVER['REQUEST_URI'], $_SERVER['HTTP_X_API_KEY'], $_SERVER['HTTP_AUTHORIZATION'], $_GET['api_key'], $_GET['rest_route'], $_POST['api_key'], $_REQUEST['api_key'] );
		unset( $_COOKIE['wordpress_logged_in_testhash'], $_COOKIE['wordpress_' . str_repeat( 'a', 32 )] );
		unset( $GLOBALS['mcpwp_test_filters']['mcpwp_spai_credential_mutator_prepare'] );
		unset( $GLOBALS['mcpwp_test_actions']['mcpwp_spai_adoption_mutator_run'] );
		if ( method_exists( 'Mcpwp_Activator', 'clear_spai_adoption_request_state' ) ) {
			Mcpwp_Activator::clear_spai_adoption_request_state();
		}
		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_COOLDOWN );
		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_STREAK );
	}

	private function request( string $key ): WP_REST_Request {
		return new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) );
	}

	private function key( string $tag ): string {
		return 'spai_' . str_repeat( $tag, 24 );
	}

	private function seed_store_key(): void {
		$auth = new Mcpwp_Spai_Lockout_Test_Auth();
		$made = $auth->create_scoped_api_key( 'existing', array( 'read' ), 'author' );
		$this->assertArrayHasKey( 'key', $made );
	}

	private function works( string $key ): bool {
		$auth = new Mcpwp_Spai_Lockout_Test_Auth();
		$main = $auth->verify_api_key( $this->request( $key ) );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
		return true === $main;
	}

	// ── P1-1 (a)/(P2-1): patient lock + re-read after busy ────────────────

	public function test_adoption_waits_on_credential_auth_cookie_and_wp_cli_not_path_alone(): void {
		$this->assertFalse( Mcpwp_Activator::adoption_should_wait(), 'Front-end default: impatient.' );

		// Bare is_admin() (unauthenticated admin-ajax) must NOT wait (#1209 P2-A).
		$GLOBALS['mcpwp_test_is_admin'] = true;
		$this->assertFalse( Mcpwp_Activator::adoption_should_wait(), 'Unauthenticated admin screen: impatient.' );
		$GLOBALS['mcpwp_test_is_admin'] = false;

		$_SERVER['REQUEST_URI'] = '/wp-json/mcpwp/v1/mcp';
		$this->assertFalse( Mcpwp_Activator::adoption_should_wait(), 'REST URI alone: impatient.' );
		unset( $_SERVER['REQUEST_URI'] );

		$_GET['rest_route'] = '/mcpwp/v1/site-info';
		$this->assertFalse( Mcpwp_Activator::adoption_should_wait(), 'rest_route alone: impatient.' );
		unset( $_GET['rest_route'] );

		$_SERVER['HTTP_X_API_KEY'] = 'mcpwp_present';
		$this->assertTrue( Mcpwp_Activator::adoption_should_wait(), 'X-API-Key present: patient.' );
		unset( $_SERVER['HTTP_X_API_KEY'] );

		$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer mcpwp_present';
		$this->assertTrue( Mcpwp_Activator::adoption_should_wait(), 'Authorization present: patient.' );
		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		$_GET['api_key'] = 'mcpwp_present';
		$this->assertTrue( Mcpwp_Activator::adoption_should_wait(), 'api_key param: patient.' );
		unset( $_GET['api_key'] );

		// #1209 P2-3: presence of an auth cookie only — never resolve the user
		// at plugins_loaded (get_current_user_id would cache a premature id).
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'][1] = array( 'manage_options' => true );
		$this->assertFalse(
			Mcpwp_Activator::adoption_should_wait(),
			'A resolved current user alone must not wait (no cookie).'
		);
		$_COOKIE['wordpress_logged_in_testhash'] = 'admin|expiration|token';
		$this->assertTrue( Mcpwp_Activator::adoption_should_wait(), 'Auth cookie present: patient.' );
		unset( $_COOKIE['wordpress_logged_in_testhash'] );
		$GLOBALS['mcpwp_test_current_user'] = 0;

		$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php' );
		$this->assertSame(
			1,
			preg_match(
				'/function adoption_should_wait\s*\(\s*\)\s*\{(?P<body>.*?)\n\tpublic static function /s',
				$src,
				$m
			),
			'Locate adoption_should_wait body.'
		);
		$wait_body = $m['body'];
		$this->assertStringNotContainsString(
			'get_current_user_id',
			$wait_body,
			'adoption_should_wait must not resolve the user at plugins_loaded.'
		);
		$this->assertStringNotContainsString( 'current_user_can', $wait_body );
		$this->assertStringContainsString( 'request_presents_auth_cookie', $wait_body );

		// WP_CLI cannot be toggled once defined; pin the branch in source instead.
		$this->assertMatchesRegularExpression(
			"/defined\\(\\s*'WP_CLI'\\s*\\)\\s*&&\\s*WP_CLI/",
			$wait_body,
			'WP-CLI uses the patient lock.'
		);
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->assertTrue( Mcpwp_Activator::adoption_should_wait() );
		}
	}

	public function test_patient_adoption_retries_the_lock_while_impatient_returns_busy(): void {
		$this->seed_store_key();
		$hash = wp_hash_password( $this->key( 'p1' ) );
		update_option( 'spai_api_key', $hash );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|held';

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials( false ) );
		// First busy must not raise Site Health (#1209 P2-B).
		$this->assertSame( '', Mcpwp_Activator::adoption_failure_reason() );
		$this->assertTrue( Mcpwp_Activator::adoption_busy_this_request() );
		$this->assertNotFalse( get_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_COOLDOWN ) );

		Mcpwp_Activator::clear_spai_adoption_request_state();
		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BACKOFF );
		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_COOLDOWN );
		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_STREAK );
		delete_option( Mcpwp_Activator::SPAI_ADOPTION_FAILURE );

		$attempts = 0;
		$hook     = null;
		$hook     = static function ( $name ) use ( &$attempts, &$hook ) {
			if ( Mcpwp_Key_Store::LOCK !== $name ) {
				$GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
				return;
			}
			$attempts++;
			if ( $attempts >= 2 ) {
				unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] );
			}
			$GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
		};
		$GLOBALS['mcpwp_test_wpdb_before_insert']                    = $hook;
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|held';

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials( true ), 'Patient lock eventually claims.' );
		$this->assertGreaterThanOrEqual( 2, $attempts );
		$this->assertTrue( $this->works( $this->key( 'p1' ) ) );
	}

	public function test_busy_adoption_then_peer_write_authenticates_after_fresh_db_read(): void {
		$this->seed_store_key();
		$key  = $this->key( 'c1' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		// Autoloaded (core default): served from alloptions, not a per-option entry.
		$GLOBALS['mcpwp_test_options_autoload']['mcpwp_api_keys'] = 'yes';
		$stale_store = get_option( 'mcpwp_api_keys' );

		// Request B: store lock held → busy (as on concurrent first requests).
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|peer';
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials( false ) );
		$this->assertSame( '', Mcpwp_Activator::adoption_failure_reason(), 'First busy is not a critical alarm.' );
		$this->assertTrue( Mcpwp_Activator::adoption_busy_this_request() );
		// Busy must not start the expensive back-off (#1178): A may finish immediately.
		$this->assertFalse( get_transient( Mcpwp_Activator::SPAI_ADOPTION_BACKOFF ) );
		$this->assertNotFalse( get_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_COOLDOWN ) );

		// Peer A finishes adoption (cool-down cleared so A can try).
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] );
		Mcpwp_Activator::clear_spai_adoption_request_state();
		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_COOLDOWN );
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials( true ) );
		$this->assertNotEmpty( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );
		$fresh_store = get_option( 'mcpwp_api_keys' );
		$this->assertNotEquals( $stale_store, $fresh_store, 'Peer A wrote adopted records.' );

		// Re-arm B's busy marker for this request (plugins_loaded already did).
		Mcpwp_Activator::mark_spai_adoption_busy_for_tests();

		// B's boot-time alloptions still holds the pre-adoption store.
		$GLOBALS['mcpwp_test_options_autoload']['mcpwp_api_keys']  = 'yes';
		$GLOBALS['mcpwp_test_option_cache_stale']['mcpwp_api_keys'] = $stale_store;
		$this->assertSame(
			$stale_store,
			get_option( 'mcpwp_api_keys' ),
			'get_option serves stale alloptions for an autoloaded option.'
		);
		$this->assertNull(
			( new Mcpwp_Spai_Lockout_Test_Auth() )->find_scoped_api_key_public( $key ),
			'Stale alloptions hides the adopted record from get_option().'
		);

		// Mutant proof (#1209 P2-1): deleting only the per-option cache entry
		// (the old invalidate_option_cache() behaviour) must NOT clear
		// alloptions — a retry that called get_option() again would still 401.
		wp_cache_delete( 'mcpwp_api_keys', 'options' );
		$this->assertSame(
			$stale_store,
			get_option( 'mcpwp_api_keys' ),
			'Per-option cache delete must not refresh alloptions for autoloaded keys.'
		);
		$this->assertNull(
			( new Mcpwp_Spai_Lockout_Test_Auth() )->find_scoped_api_key_public( $key ),
			'Mutant that re-reads get_option() after per-option delete still misses.'
		);

		// Auth retries once after busy via a fresh DB row read (not get_option).
		$this->assertNotEmpty( Mcpwp_Key_Store::read_keys(), 'DB row holds the adopted store.' );
		$this->assertTrue( $this->works( $key ), 'After busy, a fresh DB read sees A\'s write.' );

		$auth_src = file_get_contents( dirname( __DIR__ ) . '/includes/traits/trait-mcpwp-api-auth.php' );
		$this->assertSame(
			1,
			preg_match(
				'/function find_scoped_api_key_after_spai_adoption\s*\([^)]*\)\s*\{(?P<body>.*?)\n\tprotected function /s',
				$auth_src,
				$fm
			),
			'Locate find_scoped_api_key_after_spai_adoption body.'
		);
		$retry_body = $fm['body'];
		$this->assertStringContainsString(
			'Mcpwp_Key_Store::read_keys()',
			$retry_body,
			'Busy retry must call Mcpwp_Key_Store::read_keys(), not get_option().'
		);
		$this->assertStringNotContainsString( 'invalidate_option_cache', $retry_body );
		$this->assertStringNotContainsString( 'get_scoped_api_keys_raw', $retry_body );
		$this->assertStringNotContainsString( 'get_option', $retry_body );
	}

	// ── #1209 P2-A/B amplification follow-ups ─────────────────────────────

	public function test_busy_cooldown_skips_further_adoption_attempts(): void {
		$this->seed_store_key();
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'cd' ) ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|held';

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials( false ) );
		$this->assertNotFalse( get_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_COOLDOWN ) );

		Mcpwp_Activator::clear_spai_adoption_request_state();
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] );

		$runs = 0;
		add_action(
			'mcpwp_spai_adoption_mutator_run',
			static function () use ( &$runs ) {
				$runs++;
			}
		);
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials( true ), 'Cool-down: skipped entirely.' );
		$this->assertSame( 0, $runs, 'Cool-down must not take the lock or run the mutator.' );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );
	}

	public function test_busy_lock_does_not_run_mutator_or_prepare_filter(): void {
		$this->seed_store_key();
		// Plaintext forces bcrypt if prep ran before the lock.
		update_option( 'spai_api_key', $this->key( 'pl' ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|held';

		$prep = 0;
		$runs = 0;
		add_filter(
			'mcpwp_spai_credential_mutator_prepare',
			static function ( $v ) use ( &$prep ) {
				$prep++;
				return $v;
			}
		);
		add_action(
			'mcpwp_spai_adoption_mutator_run',
			static function () use ( &$runs ) {
				$runs++;
			}
		);

		// Reset after setup hashes (seed_store_key / any prior bcrypt).
		$GLOBALS['mcpwp_test_wp_hash_password_calls']  = 0;
		$GLOBALS['mcpwp_test_wp_check_password_calls'] = 0;

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials( false ) );
		$this->assertSame( 0, $prep, 'Prep/hash runs only under the lock.' );
		$this->assertSame( 0, $runs, 'Mutator callback never runs when busy.' );
		$this->assertSame(
			0,
			(int) $GLOBALS['mcpwp_test_wp_hash_password_calls'],
			'wp_hash_password must not run before the lock is held.'
		);
		$this->assertSame(
			0,
			(int) $GLOBALS['mcpwp_test_wp_check_password_calls'],
			'wp_check_password must not run before the lock is held.'
		);
		$this->assertTrue( Mcpwp_Activator::adoption_busy_this_request() );
	}

	public function test_first_busy_does_not_record_failure_sustained_busy_does(): void {
		$this->seed_store_key();
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'st' ) ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|held';

		for ( $i = 1; $i < Mcpwp_Activator::SPAI_ADOPTION_BUSY_RECORD_AFTER_COUNT; $i++ ) {
			delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_COOLDOWN );
			Mcpwp_Activator::clear_spai_adoption_request_state();
			$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials( false ) );
			$this->assertSame( '', Mcpwp_Activator::adoption_failure_reason(), "Busy #$i must not alarm." );
		}

		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BUSY_COOLDOWN );
		Mcpwp_Activator::clear_spai_adoption_request_state();
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials( false ) );
		$this->assertSame( 'busy', Mcpwp_Activator::adoption_failure_reason(), 'Sustained busy records once.' );
		$this->assertTrue( Mcpwp_Activator::spai_adoption_needs_admin_attention() );
	}

	public function test_alloptions_stale_survives_per_option_cache_delete(): void {
		$live = array( array( 'id' => 'live', 'hash' => 'x', 'revoked_at' => null ) );
		$stale = array( array( 'id' => 'stale', 'hash' => 'y', 'revoked_at' => null ) );
		update_option( 'mcpwp_api_keys', $live, 'yes' );
		$GLOBALS['mcpwp_test_option_cache_stale']['mcpwp_api_keys'] = $stale;

		$this->assertSame( $stale, get_option( 'mcpwp_api_keys' ) );
		wp_cache_delete( 'mcpwp_api_keys', 'options' );
		$this->assertSame(
			$stale,
			get_option( 'mcpwp_api_keys' ),
			'Autoloaded: per-option delete leaves alloptions stale.'
		);
		wp_cache_delete( 'alloptions', 'options' );
		$this->assertSame(
			$live,
			get_option( 'mcpwp_api_keys' ),
			'Deleting alloptions drops the stale view.'
		);
	}

	// ── P1-1 (b)/(c): failure option, notice, Site Health ─────────────────

	public function test_failed_adoption_records_reason_and_surfaces_admin_notice_and_site_health(): void {
		$this->seed_store_key();
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'f1' ) ) );
		$GLOBALS['mcpwp_test_delete_option_fail']['spai_api_key'] = true;

		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials( false ) );
		$failure = get_option( Mcpwp_Activator::SPAI_ADOPTION_FAILURE );
		$this->assertIsArray( $failure );
		$this->assertSame( 'delete_failed', $failure['reason'] );
		$this->assertArrayHasKey( 'at', $failure );
		$this->assertFalse(
			! empty( $GLOBALS['mcpwp_test_options_autoload'][ Mcpwp_Activator::SPAI_ADOPTION_FAILURE ] ),
			'Failure option is not autoloaded.'
		);
		$this->assertNotFalse( get_transient( Mcpwp_Activator::SPAI_ADOPTION_BACKOFF ) );

		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'][1] = array( 'manage_options' => true );

		ob_start();
		Mcpwp_Activator::render_spai_adoption_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( 'notice-error', $notice );
		$this->assertStringContainsString( 'is-dismissible', $notice );
		$this->assertStringContainsString( 'delete_failed', $notice );
		$this->assertStringContainsString( 'will not work', $notice );
		$this->assertDoesNotMatchRegularExpression( '/spai_[a-z0-9]{20,}/', $notice, 'No key material in the notice.' );

		$GLOBALS['mcpwp_test_current_user'] = 2;
		$GLOBALS['mcpwp_test_user_caps'][2] = array( 'edit_posts' => true );
		ob_start();
		Mcpwp_Activator::render_spai_adoption_notice();
		$this->assertSame( '', ob_get_clean(), 'Non-admins see nothing.' );

		$tests = Mcpwp_Activator::register_spai_adoption_site_status_test( array( 'direct' => array() ) );
		$this->assertArrayHasKey( 'mcpwp_spai_adoption', $tests['direct'] );
		$result = Mcpwp_Activator::run_spai_adoption_site_status_test();
		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'delete_failed', $result['description'] );
		$this->assertStringContainsString( 'will not work', wp_strip_all_tags( $result['description'] ) );
	}

	public function test_successful_adoption_clears_failure_and_health_is_good(): void {
		$this->seed_store_key();
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'f2' ) ) );
		update_option( Mcpwp_Activator::SPAI_ADOPTION_FAILURE, array( 'reason' => 'busy', 'at' => 1 ), false );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials( true ) );
		$this->assertFalse( get_option( Mcpwp_Activator::SPAI_ADOPTION_FAILURE ) );

		$result = Mcpwp_Activator::run_spai_adoption_site_status_test();
		$this->assertSame( 'good', $result['status'] );

		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'][1] = array( 'manage_options' => true );
		ob_start();
		Mcpwp_Activator::render_spai_adoption_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_notice_stays_hidden_when_spai_options_exist_but_no_failure_recorded(): void {
		update_option( 'spai_api_key', wp_hash_password( $this->key( 'f3' ) ) );
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'][1] = array( 'manage_options' => true );
		ob_start();
		Mcpwp_Activator::render_spai_adoption_notice();
		$this->assertSame( '', ob_get_clean(), 'No failure yet: no notice.' );
		$this->assertSame( 'good', Mcpwp_Activator::run_spai_adoption_site_status_test()['status'] );
	}

	// ── P2-4: rate-limit status never reads spai options ──────────────────

	public function test_rate_limit_status_lookup_never_consults_spai_options(): void {
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-updates.php' );
		$this->assertIsString( $src );
		$this->assertSame(
			1,
			preg_match(
				'/function get_rate_limit_status\s*\([^)]*\)\s*\{(?P<body>.*?)\n\tpublic function /s',
				$src,
				$m
			),
			'Locate get_rate_limit_status body.'
		);
		$body = $m['body'];
		$this->assertStringContainsString( 'find_scoped_api_key', $body );
		$this->assertStringNotContainsString( 'spai_api_key', $body );
		$this->assertStringNotContainsString( 'spai_api_keys', $body );
		$this->assertStringNotContainsString( 'find_legacy_spai', $body );

		// Behaviour: a leftover spai-only credential is not a rate-limit principal
		// until adoption writes it into the scoped store.
		$plain = $this->key( 'rl' );
		update_option(
			'spai_api_keys',
			array(
				array(
					'id'         => 'spai-rl',
					'hash'       => wp_hash_password( $plain ),
					'scopes'     => array( 'read' ),
					'revoked_at' => null,
				),
			)
		);
		$auth = new Mcpwp_Spai_Lockout_Test_Auth();
		$auth->create_scoped_api_key( 'sentinel', array( 'read' ), 'author' );
		$controller = new Mcpwp_REST_Site_Updates();
		$req        = new WP_REST_Request( 'GET', '/mcpwp/v1/rate-limit', array(), array( 'X-API-Key' => $plain ) );
		$data       = $controller->get_rate_limit_status( $req )->get_data();
		$this->assertIsArray( $data );

		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials( true ) );
		$GLOBALS['mcpwp_test_current_user'] = 0;
		if ( method_exists( 'Mcpwp_REST_MCP', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
		}
		$after = $controller->get_rate_limit_status(
			new WP_REST_Request( 'GET', '/mcpwp/v1/rate-limit', array(), array( 'X-API-Key' => $plain ) )
		)->get_data();
		$this->assertIsArray( $after );
	}
}
