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
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_options_autoload']   = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_delete_option_fail'] = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_user_caps']          = array();
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['mcpwp_test_object_cache']       = array();
		$GLOBALS['mcpwp_test_option_cache_stale'] = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$GLOBALS['mcpwp_test_cache_deletes']      = array();
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
		unset( $_SERVER['REQUEST_URI'] );
		if ( method_exists( 'Mcpwp_Activator', 'clear_spai_adoption_request_state' ) ) {
			Mcpwp_Activator::clear_spai_adoption_request_state();
		}
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

	public function test_adoption_waits_on_admin_wp_cli_and_rest(): void {
		$this->assertFalse( Mcpwp_Activator::adoption_should_wait(), 'Front-end default: impatient.' );

		$GLOBALS['mcpwp_test_is_admin'] = true;
		$this->assertTrue( Mcpwp_Activator::adoption_should_wait(), 'Admin waits.' );
		$GLOBALS['mcpwp_test_is_admin'] = false;

		$_SERVER['REQUEST_URI'] = '/wp-json/mcpwp/v1/mcp';
		$this->assertTrue( Mcpwp_Activator::adoption_should_wait(), 'REST/MCP URI waits.' );
		unset( $_SERVER['REQUEST_URI'] );

		$_GET['rest_route'] = '/mcpwp/v1/site-info';
		$this->assertTrue( Mcpwp_Activator::adoption_should_wait(), 'rest_route query waits.' );
		unset( $_GET['rest_route'] );

		// WP_CLI cannot be toggled once defined; pin the branch in source instead.
		$src = file_get_contents( dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php' );
		$this->assertMatchesRegularExpression(
			"/defined\\(\\s*'WP_CLI'\\s*\\)\\s*&&\\s*WP_CLI/",
			$src,
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
		$this->assertSame( 'busy', Mcpwp_Activator::adoption_failure_reason() );
		$this->assertTrue( Mcpwp_Activator::adoption_busy_this_request() );

		Mcpwp_Activator::clear_spai_adoption_request_state();
		delete_transient( Mcpwp_Activator::SPAI_ADOPTION_BACKOFF );
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

	public function test_busy_adoption_then_peer_write_authenticates_after_reread(): void {
		$this->seed_store_key();
		$key  = $this->key( 'c1' );
		$hash = wp_hash_password( $key );
		update_option( 'spai_api_key', $hash );
		$stale_store = get_option( 'mcpwp_api_keys' );

		// Request B: store lock held → busy (as on concurrent first requests).
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] = ( time() + 300 ) . '|peer';
		$this->assertFalse( Mcpwp_Activator::adopt_spai_credentials( false ) );
		$this->assertSame( 'busy', Mcpwp_Activator::adoption_failure_reason() );
		$this->assertTrue( Mcpwp_Activator::adoption_busy_this_request() );
		// Busy must not start the expensive back-off (#1178): A may finish immediately.
		$this->assertFalse( get_transient( Mcpwp_Activator::SPAI_ADOPTION_BACKOFF ) );

		// Peer A finishes adoption.
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Key_Store::LOCK ] );
		Mcpwp_Activator::clear_spai_adoption_request_state();
		$this->assertTrue( Mcpwp_Activator::adopt_spai_credentials( true ) );
		$this->assertNotEmpty( get_option( Mcpwp_Activator::SPAI_ADOPTION_FLAG ) );

		// Re-arm B's busy marker for this request (plugins_loaded already did).
		Mcpwp_Activator::mark_spai_adoption_busy_for_tests();

		// B still sees the pre-adoption store (stale options cache).
		$GLOBALS['mcpwp_test_option_cache_stale']['mcpwp_api_keys'] = $stale_store;
		$this->assertNull(
			( new Mcpwp_Spai_Lockout_Test_Auth() )->find_scoped_api_key_public( $key ),
			'Stale cache hides the adopted record.'
		);

		// Auth retries once after busy: invalidate + re-read.
		$this->assertTrue( $this->works( $key ), 'After busy, one re-read sees A\'s write.' );
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
