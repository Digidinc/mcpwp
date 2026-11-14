<?php
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-token-store.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
if ( file_exists( dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-users.php' ) ) { require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-users.php'; }

final class PocR1117Test extends TestCase {
	const REDIRECT = 'https://client.example.com/callback';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	private $saved_users;
	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => 1, 'roles' => array(), 'user_login' => 'user1' );
		$GLOBALS['mcpwp_test_options'] = array(); $GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array(); $GLOBALS['mcpwp_test_user_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps'] = array( 1 => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true ) );
		Mcpwp_Test_OAuth_Tokens_Table::reset();
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true, 'oauth_client_id' => 'test_client', 'oauth_token_ttl' => 3600, 'oauth_redirect_uris' => array( self::REDIRECT ) ) );
	}
	protected function tearDown(): void { $GLOBALS['mcpwp_test_users'] = $this->saved_users; $GLOBALS['mcpwp_test_user_caps'] = array(); Mcpwp_Test_OAuth_Tokens_Table::reset(); }
	private function ctrl() { return new Mcpwp_REST_OAuth(); }
	private function refresh( $t ) { return $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $t, 'client_id' => 'test_client' ) ) ); }
	private function works( $at ) { return true === ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $at ) ) ); }
	private function legacy_rt() {
		$t = 'mcpwp_rt_' . bin2hex( random_bytes( 32 ) );
		set_transient( $this->ctrl()->get_oauth_refresh_token_transient_key( $t ), array( 'scopes' => array( 'read', 'write' ), 'user_id' => 1, 'created_at' => time() - 10, 'expires_at' => time() + 3600 ), 3600 );
		return $t;
	}

	/** F1: a pre-#1101 refresh token, rotated by a thief, then replayed by the victim: reuse must kill the thief's family. */
	public function test_legacy_replay_kills_thief_family() {
		$legacy = $this->legacy_rt();
		$thief  = $this->refresh( $legacy )->get_data();
		$this->assertSame( 'reuse_tombstone', mcpwp_test_oauth_row( $legacy )['type'] );
		$this->refresh( $legacy ); // victim replays
		$this->assertFalse( $this->works( $thief['access_token'] ), 'thief access token should die on reuse' );
		$this->assertNotSame( 200, $this->refresh( $thief['refresh_token'] )->get_status(), 'thief chain should die on reuse' );
	}

	/** F1b: victim revokes (RFC 7009) the legacy token it holds after the thief rotated it. */
	public function test_legacy_rfc7009_reaches_thief_family() {
		$legacy = $this->legacy_rt();
		$thief  = $this->refresh( $legacy )->get_data();
		$r = $this->ctrl()->handle_revoke( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/revoke', array( 'token' => $legacy, 'client_id' => 'test_client' ) ) );
		$this->assertSame( 200, $r->get_status() );
		$this->assertNotSame( 200, $this->refresh( $thief['refresh_token'] )->get_status(), 'revoke should reach the rotated chain' );
	}

	/** F2: an access-token revoke whose UPDATE fails must not answer 200. */
	public function test_access_revoke_db_failure_is_not_200() {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => 'test_client', 'redirect_uri' => self::REDIRECT, 'code_challenge' => $this->ctrl()->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read write', 'user_id' => 1, 'issued_at' => Mcpwp_OAuth_Revocation::now(), 'expires' => time() + 60 ), 60 );
		$data = $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'test_client', 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER ) ) )->get_data();
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = function ( $k ) { return 'query' === $k; };
		$r = $this->ctrl()->handle_revoke( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/revoke', array( 'token' => $data['access_token'], 'client_id' => 'test_client' ) ) );
		$GLOBALS['mcpwp_test_oauth_tokens_fail'] = null;
		$still = $this->works( $data['access_token'] );
		$this->assertFalse( 200 === $r->get_status() && $still, 'got 200 while the access token still works' );
	}

	/** F3: #1115 follow-up — revoked_before meta must be protected. */
	public function test_revoked_before_meta_is_protected() {
		if ( ! class_exists( 'Mcpwp_Users' ) ) { $this->markTestSkipped( 'no Mcpwp_Users' ); }
		$m = new ReflectionMethod( 'Mcpwp_Users', 'is_protected_user_meta_key' ); $m->setAccessible( true );
		$this->assertTrue( $m->invoke( null, 'mcpwp_oauth_revoked_before' ) );
	}
}
