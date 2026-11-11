<?php
/**
 * Hardening of the self-hosted OAuth server: scope cap for user-bound tokens
 * carried over from the pre-3.14.11 build, registration limits that cannot
 * lock callers out, bearer-header-only access tokens, PKCE value shape,
 * uniform token-endpoint headers, authorization-code replay, uninstall
 * residue.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiAuthTest.php';
require_once __DIR__ . '/OAuthRevocation1101Test.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-token-store.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-uninstaller.php';

final class OAuthServerHardeningTest extends TestCase {

	const USER     = 1;
	const REDIRECT = 'https://chatgpt.com/cb';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const CLIENT   = 'mcpwpc_0123456789abcdef0123456789abcdef';
	const OTHER    = 'mcpwpc_fedcba9876543210fedcba9876543210';

	/** @var array */
	private $saved_users;
	/** @var mixed */
	private $saved_addr;
	/** @var array */
	private $saved_server;
	/** @var array */
	private $saved_get;

	protected function setUp(): void {
		$this->saved_addr   = $_SERVER['REMOTE_ADDR'] ?? null;
		$this->saved_server = $_SERVER;
		$this->saved_get    = $_GET;
		$this->saved_users  = $GLOBALS['mcpwp_test_users'];

		$GLOBALS['mcpwp_test_users'][]      = (object) array( 'ID' => self::USER, 'roles' => array(), 'user_login' => 'u1' );
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array( self::USER => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'manage_options' => true ) );
		$_SERVER['REMOTE_ADDR']             = '203.0.113.7';
		unset( $_GET['api_key'] );

		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'       => true,
				'oauth_client_id'     => 'test_client',
				'oauth_token_ttl'     => 3600,
				'oauth_redirect_uris' => array( 'https://client.example.com/callback' ),
			)
		);
		update_option(
			Mcpwp_OAuth_Clients::OPTION,
			array(
				self::CLIENT => array(
					'client_id'     => self::CLIENT,
					'client_name'   => 'X',
					'redirect_uris' => array( self::REDIRECT ),
					'created_at'    => time() - 100,
					'last_used_at'  => time() - 50,
					'authorized_at' => time() - 50,
				),
				self::OTHER  => array(
					'client_id'     => self::OTHER,
					'client_name'   => 'Y',
					'redirect_uris' => array( self::REDIRECT ),
					'created_at'    => time() - 100,
					'last_used_at'  => time() - 50,
					'authorized_at' => time() - 50,
				),
			),
			false
		);
		Mcpwp_OAuth_Token_Store::ensure_table();
	}

	protected function tearDown(): void {
		$_SERVER                              = $this->saved_server;
		$_GET                                 = $this->saved_get;
		$GLOBALS['mcpwp_test_users']          = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']      = array();
		$GLOBALS['mcpwp_test_current_user']   = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept'] = null;
		$GLOBALS['mcpwp_test_transient_hook'] = null;
		unset( $GLOBALS['mcpwp_test_oauth_tokens_before'] );
		if ( null === $this->saved_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		}
	}

	// ── helpers ───────────────────────────────────────────────────────────

	private function ctrl(): Mcpwp_OAuth_1101_Controller {
		return new Mcpwp_OAuth_1101_Controller();
	}

	private function auth_harness(): Mcpwp_Api_Auth_Test_Harness {
		return new Mcpwp_Api_Auth_Test_Harness();
	}

	private function seed_code( string $client_id = self::CLIENT, string $scope = 'read write' ): string {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient(
			'mcpwp_oauth_code_' . $code,
			array(
				'client_id'      => $client_id,
				'redirect_uri'   => self::REDIRECT,
				'code_challenge' => $this->ctrl()->compute_pkce_challenge( self::VERIFIER ),
				'scope'          => $scope,
				'user_id'        => self::USER,
				'issued_at'      => Mcpwp_OAuth_Revocation::now(),
				'expires'        => time() + 60,
			),
			60
		);
		return $code;
	}

	private function redeem( string $code, string $client_id = self::CLIENT, array $override = array() ): WP_REST_Response {
		return $this->ctrl()->handle_token(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/oauth/token',
				array_merge(
					array(
						'grant_type'    => 'authorization_code',
						'code'          => $code,
						'client_id'     => $client_id,
						'redirect_uri'  => self::REDIRECT,
						'code_verifier' => self::VERIFIER,
					),
					$override
				)
			)
		);
	}

	private function grant(): array {
		$response = $this->redeem( $this->seed_code() );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	private function refresh( string $refresh_token, string $client_id = self::CLIENT ): WP_REST_Response {
		return $this->ctrl()->handle_token(
			new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_token, 'client_id' => $client_id ) )
		);
	}

	private function bearer( string $token, string $route = '/mcpwp/v1/site-info', string $method = 'GET' ): WP_REST_Request {
		return new WP_REST_Request( $method, $route, array(), array( 'Authorization' => 'Bearer ' . $token ) );
	}

	private function works( string $token ): bool {
		return true === $this->auth_harness()->verify_api_key( $this->bearer( $token ) );
	}

	/** Authenticate a bearer token the way the REST permission callback does, on an admin-scope route. */
	private function admin_route( string $token ) {
		return ( new Mcpwp_REST_Posts() )->check_permission( $this->bearer( $token, '/mcpwp/v1/woocommerce/orders' ) );
	}

	/** A record shaped like the pre-3.14.11 build wrote it (transient, admin consented). */
	private function seed_old_build_token( string $token, string $prefix = 'mcpwp_oauth_token_' ): void {
		set_transient(
			$prefix . md5( $token ),
			array(
				'scopes'     => array( 'read', 'write', 'admin' ),
				'user_id'    => self::USER,
				'created_at' => time() - 60,
				'expires_at' => time() + 3600,
			),
			3600
		);
	}

	private function assert_error( $result, string $code, int $status ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	// ── 1. D8: no admin scope for a user-bound OAuth record ───────────────

	public function test_cap_user_bound_scopes_drops_admin_and_keeps_what_it_implied(): void {
		$this->assertSame( array( 'read', 'write' ), Mcpwp_OAuth_Token_Store::cap_user_bound_scopes( array( 'read', 'write', 'admin' ) ) );
		$this->assertSame( array( 'read', 'write' ), Mcpwp_OAuth_Token_Store::cap_user_bound_scopes( array( 'admin' ) ) );
		$this->assertSame( array( 'read' ), Mcpwp_OAuth_Token_Store::cap_user_bound_scopes( array( 'read' ) ), 'Only ever narrows.' );
		$this->assertSame( array( 'read', 'write' ), Mcpwp_OAuth_Token_Store::cap_user_bound_scopes( array( 'read', 'write' ) ) );
	}

	public function test_an_old_build_admin_token_is_write_only_once_carried_over(): void {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		$this->seed_old_build_token( $token );

		$result = $this->admin_route( $token );

		$this->assert_error( $result, 'insufficient_scope', 403 );
		$this->assertSame( 'admin', $result->get_error_data()['required_scope'] );
		$this->assertNotContains( 'admin', $result->get_error_data()['granted_scopes'] );
		$this->assertTrue( $this->works( $token ), 'It still reads and writes as its user.' );
		$this->assertNotContains( 'admin', mcpwp_test_oauth_record( $token )['scopes'], 'The carried-over row never holds admin.' );
	}

	public function test_an_old_build_legacy_prefix_admin_token_is_capped_too(): void {
		$token = 'spai_at_' . bin2hex( random_bytes( 24 ) );
		$this->seed_old_build_token( $token, 'spai_oauth_token_' );

		$this->assert_error( $this->admin_route( $token ), 'insufficient_scope', 403 );
	}

	/** Belt and braces: a row that already holds admin (written by anything) is capped when authenticated. */
	public function test_a_stored_row_holding_admin_is_capped_at_authentication(): void {
		$token = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );
		$this->assertTrue( Mcpwp_OAuth_Token_Store::insert( $token, 'access', array( 'scopes' => array( 'read' ), 'user_id' => self::USER, 'aud' => Mcpwp_OAuth_Well_Known::get_canonical_resource(), 'expires_at' => time() + 600 ) ) );
		foreach ( $GLOBALS['mcpwp_test_oauth_tokens'] as $id => $row ) {
			$GLOBALS['mcpwp_test_oauth_tokens'][ $id ]['scopes'] = 'read write admin';
		}
		$this->assertContains( 'admin', mcpwp_test_oauth_record( $token )['scopes'], 'Precondition: the row holds admin.' );

		$this->assert_error( $this->admin_route( $token ), 'insufficient_scope', 403 );
		$this->assertTrue( $this->works( $token ) );
	}

	public function test_a_legacy_refresh_token_with_admin_refreshes_to_write_only(): void {
		$legacy = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );
		set_transient( $this->ctrl()->get_oauth_refresh_token_transient_key( $legacy ), array( 'scopes' => array( 'read', 'write', 'admin' ), 'user_id' => self::USER, 'created_at' => time() - 60, 'expires_at' => time() + 3600 ), 3600 );

		$response = $this->refresh( $legacy );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'read write', $response->get_data()['scope'] );
		$this->assertNotContains( 'admin', mcpwp_test_oauth_record( $response->get_data()['refresh_token'] )['scopes'] );
	}

	public function test_a_machine_token_without_a_user_is_not_touched_by_the_cap(): void {
		$token = $this->auth_harness()->issue_oauth_access_token( array( 'read', 'write', 'admin' ), 3600, array( 'client_id' => 'machine' ) )['access_token'];
		$this->assertContains( 'admin', mcpwp_test_oauth_record( $token )['scopes'] );
		$this->assertTrue( $this->admin_route( $token ) );
	}

	// ── 2. Registration limits cannot lock callers out ────────────────────

	private function register( string $ip, int $n = 0, int $now = 0, bool $privileged = false ) {
		return Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( 'https://app' . $n . '.example/cb' ) ), $ip, $now, $privileged );
	}

	/** 20 + 20 + 10 anonymous registrations from three addresses: the pending limit is full. */
	private function flood( int $now ): void {
		$n = 0;
		foreach ( array( '198.51.100.1' => 20, '198.51.100.2' => 20, '198.51.100.3' => 10 ) as $ip => $count ) {
			for ( $i = 0; $i < $count; $i++ ) {
				$r = $this->register( $ip, ++$n, $now );
				$this->assertIsArray( $r, 'flood registration ' . $n . ' from ' . $ip );
			}
		}
		$this->assertCount( 50 + 2, Mcpwp_OAuth_Clients::all() );
	}

	public function test_a_flood_from_three_addresses_cannot_block_a_legitimate_registration(): void {
		$now = time();
		$this->flood( $now );
		$before = count( Mcpwp_OAuth_Clients::all() );

		$legit = $this->register( '192.0.2.50', 99, $now + 1 );

		$this->assertIsArray( $legit, 'Three addresses cannot fill the site limit.' );
		$this->assertCount( $before + 1, Mcpwp_OAuth_Clients::all() );
		$this->assertNotNull( Mcpwp_OAuth_Clients::find( $legit['client_id'] ) );
	}

	public function test_a_flooding_address_is_blocked_no_longer_than_the_expiry_window(): void {
		$now = time();
		$this->flood( $now );

		$refused = $this->register( '198.51.100.1', 100, $now + 10 );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 429, $refused->get_error_data()['status'] );
		$this->assertLessThanOrEqual( HOUR_IN_SECONDS, $refused->get_error_data()['retry_after'], 'Documented expiry window: one hour.' );
		$this->assertSame( HOUR_IN_SECONDS - 10, $refused->get_error_data()['retry_after'], 'Equal to when the oldest pending registration expires.' );

		$after = $now + HOUR_IN_SECONDS + 5;
		$this->assertIsArray( $this->register( '198.51.100.1', 101, $after ), 'The registrations expired and stopped counting.' );
		$this->assertCount( 1 + 2, Mcpwp_OAuth_Clients::all(), 'Only the new one and the two seeded authorized clients remain.' );
	}

	/** A quiet address's registration survives a flood from many distinct addresses, and every 201 stays valid. */
	public function test_a_quiet_client_and_every_issued_id_survive_a_flood_from_many_addresses(): void {
		$now   = time();
		$quiet = $this->register( '192.0.2.250', 1, $now );
		$this->assertIsArray( $quiet );
		$issued = array( $quiet['client_id'] );
		$refused = 0;
		for ( $i = 0; $i < 250; $i++ ) {
			$r = $this->register( '198.51.' . intdiv( $i, 200 ) . '.' . ( $i % 200 + 1 ), 10 + $i, $now + 1 + intdiv( $i, 1 ) );
			if ( is_wp_error( $r ) ) {
				$this->assertSame( 429, $r->get_error_data()['status'] );
				++$refused;
				continue;
			}
			$issued[] = $r['client_id'];
		}
		$this->assertGreaterThan( 0, $refused, 'The flood was bounded.' );
		foreach ( $issued as $id ) {
			$this->assertNotNull( Mcpwp_OAuth_Clients::find( $id ), 'Every id handed out with a 201 still exists.' );
		}
	}

	public function test_an_administrators_clients_survive_a_flood_and_count_against_no_limit(): void {
		$now   = time();
		$admin = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$r = $this->register( '203.0.113.200', 500 + $i, $now, true );
			$this->assertIsArray( $r );
			$admin[] = $r['client_id'];
		}
		for ( $i = 0; $i < 300; $i++ ) {
			$this->register( '198.51.' . intdiv( $i, 200 ) . '.' . ( $i % 200 + 1 ), 600 + $i, $now + 1 + intdiv( $i, 2 ) );
		}
		foreach ( $admin as $id ) {
			$this->assertNotNull( Mcpwp_OAuth_Clients::find( $id ) );
		}
		// And a purge an hour and a bit later keeps them (kept until used or deleted).
		$this->assertIsArray( $this->register( '192.0.2.9', 900, $now + HOUR_IN_SECONDS + 100 ) );
		foreach ( $admin as $id ) {
			$this->assertNotNull( Mcpwp_OAuth_Clients::find( $id ), 'Not purged for age.' );
		}
	}

	public function test_the_site_limit_refuses_and_displaces_nobody(): void {
		$now = time();
		$ids = array();
		for ( $i = 0; $i < Mcpwp_OAuth_Clients::SITE_PENDING_LIMIT; $i++ ) {
			$r = $this->register( '198.51.' . intdiv( $i, 200 ) . '.' . ( $i % 200 + 1 ), $i, $now + 2 * $i );
			$this->assertIsArray( $r );
			$ids[] = $r['client_id'];
		}
		$refused = $this->register( '198.51.100.1', 77, $now + 250 );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 429, $refused->get_error_data()['status'] );
		$this->assertSame( HOUR_IN_SECONDS - 250, $refused->get_error_data()['retry_after'] );
		foreach ( $ids as $id ) {
			$this->assertNotNull( Mcpwp_OAuth_Clients::find( $id ) );
		}
	}

	public function test_the_per_minute_ceiling_bounds_registrations_whatever_the_addresses(): void {
		$now = time();
		$ok  = 0;
		for ( $i = 0; $i < 80; $i++ ) {
			$r = $this->register( '198.51.' . intdiv( $i, 200 ) . '.' . ( $i % 200 + 1 ), $i, $now );
			if ( is_array( $r ) ) {
				++$ok;
				continue;
			}
			$this->assertSame( 429, $r->get_error_data()['status'] );
			$this->assertLessThanOrEqual( 60, $r->get_error_data()['retry_after'] );
		}
		$this->assertSame( Mcpwp_OAuth_Clients::SITE_REGISTRATIONS_PER_MINUTE, $ok );
		$this->assertIsArray( $this->register( '192.0.2.77', 999, $now + 61 ), 'The next minute is open again.' );
	}

	/** Thousands of registrations from distinct addresses leave a bounded number of rows and a bounded option. */
	public function test_many_registrations_leave_bounded_storage(): void {
		$now = time();
		for ( $i = 0; $i < 2000; $i++ ) {
			$this->register( '10.' . intdiv( $i, 65000 ) . '.' . intdiv( $i % 65000, 250 ) . '.' . ( $i % 250 + 1 ), $i, $now + intdiv( $i, 2 ) );
		}
		$rows = 0;
		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $name ) {
			if ( 0 === strpos( $name, 'mcpwp_oauth_dcr_' ) ) {
				++$rows;
			}
		}
		$this->assertLessThanOrEqual( Mcpwp_OAuth_Clients::SITE_PENDING_LIMIT + 5, $rows );
		$this->assertLessThanOrEqual( Mcpwp_OAuth_Clients::SITE_PENDING_LIMIT + 2, count( Mcpwp_OAuth_Clients::all() ) );
		$this->assertLessThan( 60000, strlen( serialize( get_option( Mcpwp_OAuth_Clients::OPTION ) ) ) );
	}

	public function test_a_refused_registration_never_takes_the_lock_nor_counts(): void {
		$now = time();
		$this->flood( $now );
		$buckets_before = array_keys( $GLOBALS['mcpwp_test_transients'] );

		// The store lock is held by someone else: a request that would be
		// refused still answers 429 at once; one that would be allowed waits and gets 503.
		$held                                                                   = ( time() + 60 ) . '|held';
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Clients::LOCK_OPTION ] = $held;
		$refused = $this->register( '198.51.100.1', 100, $now + 1 );
		$this->assertSame( 429, $refused->get_error_data()['status'], 'Refused before the lock, not a 503.' );
		$allowed = $this->register( '192.0.2.77', 101, $now + 1 );
		$this->assertSame( 503, $allowed->get_error_data()['status'], 'Control: an in-limit request does wait on the lock.' );
		$this->assertSame( $held, $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Clients::LOCK_OPTION ] );

		$this->assertSame( $buckets_before, array_keys( $GLOBALS['mcpwp_test_transients'] ), 'Neither request was counted.' );
		$this->assertCount( 50 + 2, Mcpwp_OAuth_Clients::all() );
	}

	public function test_in_limit_registrations_queue_on_the_lock_instead_of_a_503(): void {
		// Another request holds the lock for the first few attempts, then lets go.
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Clients::LOCK_OPTION ] = ( time() + 60 ) . '|held';
		$polls                                                                  = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept']                                   = function ( $kind, $query ) use ( &$polls ) {
			if ( 'get_var' === $kind && false !== strpos( $query, Mcpwp_OAuth_Clients::LOCK_OPTION ) && ++$polls === 5 ) {
				unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Clients::LOCK_OPTION ] );
			}
			return MCPWP_TEST_WPDB_PASS;
		};

		$result = $this->register( '192.0.2.60', 1 );

		$this->assertIsArray( $result, 'Within limits, and the lock came free inside the retry window: no 503.' );
		$this->assertGreaterThanOrEqual( 5, $polls );
	}

	public function test_an_authenticated_administrator_is_not_blocked_by_an_anonymous_flood(): void {
		$now = time();
		$this->flood( $now );

		// Same address as a flooder, and the site limit is full: still registers.
		$this->assertIsArray( $this->register( '198.51.100.1', 200, $now + 1, true ) );

		// Through the endpoint: a request authenticated as an administrator.
		$GLOBALS['mcpwp_test_current_user'] = self::USER;
		$request                            = new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/register' );
		$request->set_body( wp_json_encode( array( 'redirect_uris' => array( 'https://admin-app.example/cb' ) ) ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		$response               = $this->ctrl()->handle_register( $request );
		$this->assertSame( 201, $response->get_status() );

		// And the same request anonymous is refused.
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$this->assertSame( 429, $this->ctrl()->handle_register( $request )->get_status() );
	}

	public function test_registration_limits_key_on_the_trusted_address_not_a_spoofable_header(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		for ( $i = 0; $i < Mcpwp_OAuth_Clients::ADDRESS_PENDING_LIMIT; $i++ ) {
			$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.9.' . $i . '.1';
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '10.8.' . $i . '.1';
			$request                         = new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/register' );
			$request->set_body( wp_json_encode( array( 'redirect_uris' => array( 'https://h' . $i . '.example/cb' ) ) ) );
			$this->assertSame( 201, $this->ctrl()->handle_register( $request )->get_status() );
		}
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '10.7.7.7';
		$request                         = new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/register' );
		$request->set_body( wp_json_encode( array( 'redirect_uris' => array( 'https://more.example/cb' ) ) ) );
		$this->assertSame( 429, $this->ctrl()->handle_register( $request )->get_status(), 'A forged forwarding header buys no fresh allowance.' );
	}

	/**
	 * Simulate an object cache that serves the count the pre-check cached until
	 * the entry is dropped (wp_cache_delete): returns a reader for
	 * mcpwp_test_transient_hook.
	 */
	private function stale_counters(): void {
		$snapshot = array();
		foreach ( $GLOBALS['mcpwp_test_transients'] as $name => $item ) {
			if ( 0 === strpos( $name, 'mcpwp_oauth_dcr_' ) ) {
				$snapshot[ $name ] = $item['value'];
			}
		}
		$GLOBALS['mcpwp_test_cache_deletes']  = array();
		$GLOBALS['mcpwp_test_transient_hook'] = static function ( $name ) use ( $snapshot ) {
			if ( ! isset( $snapshot[ $name ] ) ) {
				return null;
			}
			foreach ( $GLOBALS['mcpwp_test_cache_deletes'] as $dropped ) {
				if ( 'options' === $dropped[0] && '_transient_' . $name === $dropped[1] && $dropped[2] ) {
					return null; // Dropped under the store lock: read the store.
				}
			}
			return $snapshot[ $name ];
		};
	}

	/** R2-1: the per-minute ceiling is re-read from the store under the lock, not from a cached count. */
	public function test_the_per_minute_ceiling_is_read_fresh_under_the_lock(): void {
		$now = time();
		for ( $i = 0; $i < Mcpwp_OAuth_Clients::SITE_REGISTRATIONS_PER_MINUTE - 1; $i++ ) {
			$this->assertIsArray( $this->register( '198.51.' . intdiv( $i, 200 ) . '.' . ( $i % 200 + 1 ), $i, $now ) );
		}
		$this->stale_counters(); // A parallel request has just taken the 60th slot; this request's pre-check cached 59.
		$minute                                                              = $GLOBALS['mcpwp_test_transients'][ Mcpwp_OAuth_Clients::MINUTE_BUCKET ];
		$minute['value']['count']                                            = Mcpwp_OAuth_Clients::SITE_REGISTRATIONS_PER_MINUTE;
		$GLOBALS['mcpwp_test_transients'][ Mcpwp_OAuth_Clients::MINUTE_BUCKET ] = $minute;

		$result = $this->register( '192.0.2.201', 900, $now );

		$this->assertInstanceOf( WP_Error::class, $result, 'The re-check under the lock sees the real count.' );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/** R2-1: the hourly (address, hosts) limit likewise. */
	public function test_the_hourly_bucket_is_read_fresh_under_the_lock(): void {
		$now = time();
		$uri = array( 'redirect_uris' => array( 'https://same-host.example/cb' ) );
		for ( $i = 0; $i < 9; $i++ ) {
			$this->assertIsArray( Mcpwp_OAuth_Clients::register( $uri, '192.0.2.202', $now ) );
		}
		$this->stale_counters();
		foreach ( $GLOBALS['mcpwp_test_transients'] as $name => $item ) {
			if ( 0 === strpos( $name, 'mcpwp_oauth_dcr_' ) && 'mcpwp_oauth_dcr_site_minute' !== $name ) {
				$GLOBALS['mcpwp_test_transients'][ $name ]['value']['count'] = 10;
			}
		}

		$result = Mcpwp_OAuth_Clients::register( $uri, '192.0.2.202', $now );

		$this->assertInstanceOf( WP_Error::class, $result, 'The tenth is the last: the eleventh is refused on the real count.' );
		$this->assertSame( 429, $result->get_error_data()['status'] );
	}

	/** R2-2: an option that was absent at the pre-check is read from the store under the lock. */
	public function test_the_absent_option_cache_is_dropped_under_the_lock(): void {
		delete_option( Mcpwp_OAuth_Clients::OPTION );
		$GLOBALS['mcpwp_test_cache_deletes'] = array();

		$this->assertIsArray( $this->register( '192.0.2.203', 1 ) );

		$under_lock = array_map(
			static function ( $d ) {
				return $d[0] . ':' . $d[1];
			},
			array_filter(
				$GLOBALS['mcpwp_test_cache_deletes'],
				static function ( $d ) {
					return $d[2];
				}
			)
		);
		$this->assertContains( 'options:notoptions', $under_lock );
		$this->assertContains( 'options:' . Mcpwp_OAuth_Clients::OPTION, $under_lock );
	}

	// ── 3. Access tokens: Authorization: Bearer only ──────────────────────

	public function test_an_access_token_is_refused_in_x_api_key_and_the_query_string(): void {
		$data  = $this->grant();
		$token = $data['access_token'];

		$this->assertTrue( $this->works( $token ), 'Control: the bearer header works.' );

		$via_header = $this->auth_harness()->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $token ) ) );
		$this->assert_error( $via_header, 'missing_api_key', 401 );

		$_GET['api_key'] = $token;
		$via_query       = $this->auth_harness()->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info' ) );
		unset( $_GET['api_key'] );
		$this->assert_error( $via_query, 'missing_api_key', 401 );
	}

	public function test_the_sub_request_fast_path_refuses_it_too(): void {
		$token = $this->grant()['access_token'];
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		try {
			$result = $this->auth_harness()->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $token ) ) );
		} finally {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		$this->assert_error( $result, 'missing_api_key', 401 );
	}

	public function test_a_bearer_token_beside_a_stray_x_api_key_token_still_authenticates(): void {
		$token  = $this->grant()['access_token'];
		$result = $this->auth_harness()->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $token, 'Authorization' => 'Bearer ' . $token ) ) );
		$this->assertTrue( $result );
	}

	public function test_api_keys_keep_working_through_every_method(): void {
		$auth = $this->auth_harness();
		$key  = $auth->create_scoped_api_key( 'hardening', array( 'read', 'write' ) )['key'];

		$this->assertTrue( $auth->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'X-API-Key' => $key ) ) ), 'X-API-Key' );
		$this->assertTrue( $auth->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $key ) ) ), 'Authorization: Bearer' );
		$_GET['api_key'] = $key;
		$via_query       = $auth->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info' ) );
		unset( $_GET['api_key'] );
		$this->assertTrue( $via_query, '?api_key=' );
	}

	// ── 4. PKCE value shape ───────────────────────────────────────────────

	public static function challenge_cases(): array {
		return array(
			'43 chars'         => array( str_repeat( 'a', 43 ), true ),
			'128 chars'        => array( str_repeat( 'a', 128 ), true ),
			'unreserved set'   => array( str_repeat( 'Az09-._~', 6 ), true ),
			'42 chars'         => array( str_repeat( 'a', 42 ), false ),
			'1 char'           => array( 'a', false ),
			'129 chars'        => array( str_repeat( 'a', 129 ), false ),
			'plus sign'        => array( str_repeat( 'a', 42 ) . '+', false ),
			'padding'          => array( str_repeat( 'a', 42 ) . '=', false ),
			'space'            => array( str_repeat( 'a', 42 ) . ' ', false ),
		);
	}

	/**
	 * @dataProvider challenge_cases
	 */
	public function test_the_authorize_step_checks_the_code_challenge_shape( string $challenge, bool $valid ): void {
		$result = $this->ctrl()->validate_authorize_params(
			array(
				'response_type'         => 'code',
				'client_id'             => self::CLIENT,
				'redirect_uri'          => self::REDIRECT,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'scope'                 => 'read',
				'state'                 => 's',
				'resource'              => '',
			)
		);
		if ( $valid ) {
			$this->assertTrue( $result );
			return;
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_request', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * @dataProvider challenge_cases
	 */
	public function test_the_token_step_checks_the_code_verifier_shape( string $verifier, bool $valid ): void {
		$code = $this->seed_code();
		$response = $this->redeem( $code, self::CLIENT, array( 'code_verifier' => $verifier ) );
		if ( $valid ) {
			// Well-formed but not this code's verifier: the PKCE comparison fails, not the shape check.
			$this->assertSame( 'PKCE verification failed.', $response->get_data()['error_description'] );
			return;
		}
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
		$this->assertStringContainsString( '43 to 128', $response->get_data()['error_description'] );
		$this->assertNotFalse( get_transient( 'mcpwp_oauth_code_' . $code ), 'A malformed verifier consumes nothing.' );
	}

	// ── 5. Token endpoint response headers ────────────────────────────────

	private function client_credentials( array $override = array() ): WP_REST_Response {
		$settings                             = get_option( 'mcpwp_settings', array() );
		$settings['oauth_client_secret_hash'] = wp_hash_password( 'cc_secret' );
		update_option( 'mcpwp_settings', $settings );
		return $this->ctrl()->handle_token(
			new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array_merge( array( 'grant_type' => 'client_credentials', 'client_id' => 'test_client', 'client_secret' => 'cc_secret', 'scope' => 'read' ), $override ) )
		);
	}

	private function assert_no_store( WP_REST_Response $response, string $context ): void {
		$headers = $response->get_headers();
		$this->assertSame( 'no-store', $headers['Cache-Control'] ?? null, $context . ' Cache-Control' );
		$this->assertSame( 'no-cache', $headers['Pragma'] ?? null, $context . ' Pragma' );
	}

	public function test_client_credentials_answers_are_uncacheable(): void {
		$ok = $this->client_credentials();
		$this->assertSame( 200, $ok->get_status() );
		$this->assert_no_store( $ok, 'success' );

		$this->assert_no_store( $this->client_credentials( array( 'client_secret' => 'wrong' ) ), 'invalid_client' );
		$this->assert_no_store( $this->client_credentials( array( 'client_secret' => '' ) ), 'invalid_request' );
	}

	public function test_client_credentials_rate_limit_is_an_oauth_error_response(): void {
		$ctrl               = $this->ctrl();
		$ctrl->limit_answer = new WP_Error( 'rate_limit_exceeded', 'Slow down.', array( 'status' => 429 ) );
		$response           = $ctrl->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'client_credentials', 'client_id' => 'test_client', 'client_secret' => 'cc_secret' ) ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( 'rate_limit_exceeded', $response->get_data()['error'] );
		$this->assertSame( 'Slow down.', $response->get_data()['error_description'] );
		$this->assert_no_store( $response, 'rate limit' );
	}

	public function test_every_grants_errors_are_uncacheable_too(): void {
		$this->assert_no_store( $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/x', array( 'grant_type' => 'nope' ) ) ), 'unsupported_grant_type' );
		$this->assert_no_store( $this->redeem( 'unknown-code' ), 'authorization_code error' );
		$this->assert_no_store( $this->refresh( 'mcpwp_rt_' . str_repeat( 'a', 64 ) ), 'refresh error' );
	}

	// ── 6. Authorization code replay ──────────────────────────────────────

	/** Move a redeemed code's marker past the double-submit grace window. */
	private function age_redeemed_marker( string $code ): void {
		$key = Mcpwp_REST_OAuth::REDEEMED_CODE_PREFIX . hash( 'sha256', $code );
		$GLOBALS['mcpwp_test_transients'][ $key ]['value']['redeemed_at'] -= 30;
	}

	public function test_replaying_a_redeemed_code_revokes_the_tokens_it_issued(): void {
		$code  = $this->seed_code();
		$first = $this->redeem( $code );
		$this->assertSame( 200, $first->get_status() );
		$data = $first->get_data();
		$this->assertTrue( $this->works( $data['access_token'] ) );
		$this->age_redeemed_marker( $code );

		$replay = $this->redeem( $code );

		$this->assertSame( 400, $replay->get_status() );
		$this->assertSame( 'invalid_grant', $replay->get_data()['error'] );
		$this->assertFalse( $this->works( $data['access_token'] ), 'The access token issued by the first redemption is revoked.' );
		$refreshed = $this->refresh( $data['refresh_token'] );
		$this->assertSame( 400, $refreshed->get_status(), 'And so is its refresh token.' );
	}

	public function test_a_parallel_double_submit_loses_without_disconnecting_the_winner(): void {
		$code = $this->seed_code();
		$data = $this->redeem( $code )->get_data();

		$loser = $this->redeem( $code ); // immediately: inside the grace window

		$this->assertSame( 'invalid_grant', $loser->get_data()['error'] );
		$this->assertTrue( $this->works( $data['access_token'] ), 'The winner keeps its tokens.' );
		$this->assertSame( 200, $this->refresh( $data['refresh_token'] )->get_status() );
	}

	public function test_a_replay_also_kills_the_tokens_after_they_were_refreshed(): void {
		$code    = $this->seed_code();
		$data    = $this->redeem( $code )->get_data();
		$rotated = $this->refresh( $data['refresh_token'] )->get_data();
		$this->assertTrue( $this->works( $rotated['access_token'] ) );
		$this->age_redeemed_marker( $code );

		$this->redeem( $code );

		$this->assertFalse( $this->works( $rotated['access_token'] ), 'The whole family goes.' );
		$this->assertSame( 400, $this->refresh( $rotated['refresh_token'] )->get_status() );
	}

	public function test_a_replay_by_another_client_revokes_nothing(): void {
		$code = $this->seed_code();
		$data = $this->redeem( $code )->get_data();
		$this->age_redeemed_marker( $code );

		$replay = $this->redeem( $code, self::OTHER );

		$this->assertSame( 'invalid_grant', $replay->get_data()['error'] );
		$this->assertTrue( $this->works( $data['access_token'] ), 'Presenting someone else\'s code under another client id is just an invalid code.' );
	}

	public function test_a_replay_that_does_not_authenticate_as_the_client_revokes_nothing(): void {
		$code = $this->seed_code();
		$data = $this->redeem( $code )->get_data();
		$this->age_redeemed_marker( $code );

		$wrong_redirect = $this->redeem( $code, self::CLIENT, array( 'redirect_uri' => 'https://elsewhere.example/cb' ) );
		$this->assertSame( 'invalid_grant', $wrong_redirect->get_data()['error'] );
		$unknown = $this->redeem( $code, 'mcpwpc_00000000000000000000000000000000' );
		$this->assertSame( 'invalid_grant', $unknown->get_data()['error'] );
		$this->assertTrue( $this->works( $data['access_token'] ), 'A wrong redirect URI or an unregistered client id cannot disconnect the app.' );
	}

	public function test_a_replay_with_a_wrong_client_secret_revokes_nothing_but_the_right_one_does(): void {
		$settings                             = get_option( 'mcpwp_settings', array() );
		$settings['oauth_client_secret_hash'] = wp_hash_password( 'cc_secret' );
		update_option( 'mcpwp_settings', $settings );

		$code = $this->seed_code( 'test_client' );
		$first = $this->redeem( $code, 'test_client', array( 'client_secret' => 'cc_secret' ) );
		$this->assertSame( 200, $first->get_status() );
		$data = $first->get_data();
		$this->age_redeemed_marker( $code );

		$wrong = $this->redeem( $code, 'test_client', array( 'client_secret' => 'not-the-secret' ) );
		$this->assertSame( 400, $wrong->get_status() );
		$this->assertTrue( $this->works( $data['access_token'] ), 'A wrong secret revokes nothing.' );

		$this->redeem( $code, 'test_client', array( 'client_secret' => 'cc_secret' ) );
		$this->assertFalse( $this->works( $data['access_token'] ), 'The client itself, authenticated, does.' );
	}

	/** A confidential client must prove itself to trigger the revocation: omitting the secret revokes nothing. */
	public function test_a_replay_without_the_secret_for_a_confidential_client_revokes_nothing(): void {
		$settings                             = get_option( 'mcpwp_settings', array() );
		$settings['oauth_client_secret_hash'] = wp_hash_password( 'cc_secret' );
		update_option( 'mcpwp_settings', $settings );

		$code = $this->seed_code( 'test_client' );
		$data = $this->redeem( $code, 'test_client', array( 'client_secret' => 'cc_secret' ) )->get_data();
		$this->age_redeemed_marker( $code );

		$this->assertSame( 'invalid_grant', $this->redeem( $code, 'test_client' )->get_data()['error'] );
		$this->assertTrue( $this->works( $data['access_token'] ) );
	}

	public function test_an_unknown_code_revokes_nothing(): void {
		$data = $this->grant();
		$this->assertSame( 'invalid_grant', $this->redeem( 'code_never_issued' )->get_data()['error'] );
		$this->assertTrue( $this->works( $data['access_token'] ) );
	}

	public function test_the_redeemed_code_marker_holds_no_secret(): void {
		$code = $this->seed_code();
		$this->redeem( $code );
		$names = array_filter( array_keys( $GLOBALS['mcpwp_test_transients'] ), static function ( $n ) {
			return 0 === strpos( $n, Mcpwp_REST_OAuth::REDEEMED_CODE_PREFIX );
		} );
		$this->assertCount( 1, $names );
		$this->assertStringNotContainsString( $code, implode( '', $names ) . serialize( $GLOBALS['mcpwp_test_transients'] ) );
	}

	// ── 7. Uninstall residue ──────────────────────────────────────────────

	public function test_uninstall_removes_the_oauth_options_user_meta_and_transients(): void {
		update_option( 'mcpwp_oauth_min_role', 'editor' );
		update_option( 'mcpwp_chatgpt_connector_key_id', 'abc' );
		update_user_meta( self::USER, 'mcpwp_oauth_revoked_before', '1700000000000000' );
		update_user_meta( self::USER, 'mcpwp_oauth_default_notice_dismissed', '1' );
		update_user_meta( self::USER, 'unrelated_meta', 'keep' );

		Mcpwp_Uninstaller::drop_approvals_data_for_current_site();

		$this->assertFalse( get_option( 'mcpwp_oauth_min_role', false ) );
		$this->assertFalse( get_option( 'mcpwp_chatgpt_connector_key_id', false ) );
		$this->assertSame( '', get_user_meta( self::USER, 'mcpwp_oauth_revoked_before', true ) );
		$this->assertSame( '', get_user_meta( self::USER, 'mcpwp_oauth_default_notice_dismissed', true ) );
		$this->assertSame( 'keep', get_user_meta( self::USER, 'unrelated_meta', true ), 'Only the OAuth keys.' );

		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		foreach ( Mcpwp_Uninstaller::OAUTH_TRANSIENT_PREFIXES as $prefix ) {
			$this->assertStringContainsString( addcslashes( '_transient_' . $prefix, '_%\\' ) . '%', $sql, $prefix );
			$this->assertStringContainsString( addcslashes( '_transient_timeout_' . $prefix, '_%\\' ) . '%', $sql, $prefix );
		}
	}

	public function test_the_uninstall_prefixes_cover_every_transient_the_server_writes(): void {
		$this->grant();
		$code = $this->seed_code();
		$this->redeem( $code );
		$this->redeem( $code );
		$this->register( '198.51.100.1', 1 );
		Mcpwp_OAuth_Revocation::revoke_family( 'fam1' );

		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $name ) {
			if ( 0 !== strpos( $name, 'mcpwp_oauth_' ) ) {
				continue;
			}
			$covered = false;
			foreach ( Mcpwp_Uninstaller::OAUTH_TRANSIENT_PREFIXES as $prefix ) {
				$covered = $covered || 0 === strpos( $name, $prefix );
			}
			$this->assertTrue( $covered, $name . ' is left behind by the uninstaller.' );
		}
	}
}
