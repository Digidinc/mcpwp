<?php
/**
 * #1101 PR-3a — OAuth: refresh tokens bound to their client, revocation per
 * client / user / refresh family by epoch, RFC 7009, checks before the
 * claim, refresh reuse detection, the DCR per-address ceiling, the D10
 * migration lock, and the two OAuth read_post branches.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-clients.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-oauth-revocation.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-oauth-local-admin.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';

/** The OAuth controller with the access-token key exposed and the limiter observable. */
class Mcpwp_OAuth_1101_Controller extends Mcpwp_REST_OAuth {
	/** @var string[] */
	public $limited = array();
	/** @var WP_Error|null */
	public $limit_answer = null;

	public function access_key( $token ) {
		return $this->get_oauth_token_transient_key( $token );
	}

	protected function check_rate_limit( $identifier = null, $method = 'POST', $key_record = null, $weight = 1 ) {
		$this->limited[] = (string) $identifier;
		return null === $this->limit_answer ? true : $this->limit_answer;
	}
}

/** Exposes the two OAuth read_post branches. */
class Mcpwp_OAuth_1101_Auth extends Mcpwp_Api_Auth_Test_Harness {
	public function can_read( $post ) {
		return $this->requester_can_read_post( $post );
	}
}

final class OAuthRevocation1101Test extends TestCase {

	const EDITOR_CAPS = array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true );
	const REDIRECT    = 'https://client.example.com/callback';
	const VERIFIER    = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	const DCR_CLIENT  = 'mcpwpc_0123456789abcdef0123456789abcdef';
	const DCR_REDIRECT = 'https://chatgpt.com/connector_platform_oauth_redirect';

	/** @var array */
	private $saved_users;
	/** @var mixed */
	private $saved_remote_addr;

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		foreach ( array( 1, 2 ) as $uid ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $uid, 'roles' => array(), 'user_login' => 'user' . $uid );
		}
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_user_meta']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array( 1 => self::EDITOR_CAPS, 2 => self::EDITOR_CAPS );
		$GLOBALS['mcpwp_test_post_caps']    = array();
		$this->saved_remote_addr            = $_SERVER['REMOTE_ADDR'] ?? null;
		$_SERVER['REMOTE_ADDR']             = '203.0.113.7';
		update_option(
			'mcpwp_settings',
			array(
				'oauth_enabled'       => true,
				'oauth_client_id'     => 'test_client',
				'oauth_token_ttl'     => 3600,
				'oauth_redirect_uris' => array( self::REDIRECT ),
			)
		);
		// One DCR-registered, authorized client.
		update_option(
			Mcpwp_OAuth_Clients::OPTION,
			array(
				self::DCR_CLIENT => array(
					'client_id'     => self::DCR_CLIENT,
					'client_name'   => 'ChatGPT',
					'redirect_uris' => array( self::DCR_REDIRECT ),
					'created_at'    => time() - 100,
					'last_used_at'  => time() - 50,
					'authorized_at' => time() - 50,
				),
			),
			false
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']        = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_post_caps']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['_mcpwp_test_posts']       = array();
		if ( null === $this->saved_remote_addr ) {
			unset( $_SERVER['REMOTE_ADDR'] );
		} else {
			$_SERVER['REMOTE_ADDR'] = $this->saved_remote_addr;
		}
	}

	// ── helpers ───────────────────────────────────────────────────────────

	private function ctrl(): Mcpwp_OAuth_1101_Controller {
		return new Mcpwp_OAuth_1101_Controller();
	}

	private function seed_code( string $client_id = 'test_client', int $user_id = 1 ): string {
		$code = 'code_' . bin2hex( random_bytes( 8 ) );
		set_transient(
			'mcpwp_oauth_code_' . $code,
			array(
				'client_id'      => $client_id,
				'redirect_uri'   => 'test_client' === $client_id ? self::REDIRECT : self::DCR_REDIRECT,
				'code_challenge' => $this->ctrl()->compute_pkce_challenge( self::VERIFIER ),
				'scope'          => 'read write',
				'user_id'        => $user_id,
				'issued_at'      => Mcpwp_OAuth_Revocation::now(),
				'expires'        => time() + 60,
			),
			60
		);
		return $code;
	}

	private function redeem( string $code, string $client_id = 'test_client', array $override = array() ): WP_REST_Response {
		return $this->ctrl()->handle_token(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/oauth/token',
				array_merge(
					array(
						'grant_type'    => 'authorization_code',
						'code'          => $code,
						'client_id'     => $client_id,
						'redirect_uri'  => 'test_client' === $client_id ? self::REDIRECT : self::DCR_REDIRECT,
						'code_verifier' => self::VERIFIER,
					),
					$override
				)
			)
		);
	}

	/** A fresh grant for a client: access + refresh tokens. */
	private function grant( string $client_id = 'test_client', int $user_id = 1 ): array {
		$response = $this->redeem( $this->seed_code( $client_id, $user_id ), $client_id );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	private function refresh( string $refresh_token, string $client_id = 'test_client' ): WP_REST_Response {
		return $this->ctrl()->handle_token(
			new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh_token, 'client_id' => $client_id ) )
		);
	}

	private function revoke( array $params ): WP_REST_Response {
		return $this->ctrl()->handle_revoke( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/revoke', $params ) );
	}

	/** Whether an access token authenticates on the API. */
	private function works( string $access_token ): bool {
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key(
			new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $access_token ) )
		);
		return true === $result;
	}

	private function refresh_record( string $refresh_token ) {
		return get_transient( $this->ctrl()->get_oauth_refresh_token_transient_key( $refresh_token ) );
	}

	private function assert_grant_error( WP_REST_Response $response, string $error, string $context = '' ): void {
		$this->assertSame( 400, $response->get_status(), $context );
		$this->assertSame( $error, $response->get_data()['error'], $context );
	}

	// ── 1. client binding ─────────────────────────────────────────────────

	public function test_access_and_refresh_records_store_the_client_and_family(): void {
		$data    = $this->grant( self::DCR_CLIENT );
		$access  = get_transient( $this->ctrl()->access_key( $data['access_token'] ) );
		$refresh = $this->refresh_record( $data['refresh_token'] );

		$this->assertSame( self::DCR_CLIENT, $access['client_id'] );
		$this->assertSame( self::DCR_CLIENT, $refresh['client_id'] );
		$this->assertSame( 1, (int) $access['user_id'] );
		$this->assertIsFloat( $access['issued_at'] );
		$this->assertNotEmpty( $refresh['family_id'] );
		$this->assertSame( $refresh['family_id'], $access['family_id'] );
	}

	public function test_refresh_by_another_client_is_invalid_grant_and_leaves_the_token(): void {
		$data = $this->grant( self::DCR_CLIENT );

		$this->assert_grant_error( $this->refresh( $data['refresh_token'], 'test_client' ), 'invalid_grant', 'other client' );
		$this->assertIsArray( $this->refresh_record( $data['refresh_token'] ), 'Not consumed.' );

		$this->assertSame( 200, $this->refresh( $data['refresh_token'], self::DCR_CLIENT )->get_status(), 'The real client still refreshes.' );
	}

	public function test_refresh_without_client_id_is_invalid_request(): void {
		$data     = $this->grant();
		$response = $this->ctrl()->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'refresh_token' => $data['refresh_token'] ) ) );
		$this->assert_grant_error( $response, 'invalid_request' );
		$this->assertIsArray( $this->refresh_record( $data['refresh_token'] ) );
	}

	public function test_code_redemption_by_another_client_or_without_client_id_never_skips_the_check(): void {
		$code = $this->seed_code( self::DCR_CLIENT );
		$this->assert_grant_error( $this->redeem( $code, 'test_client', array( 'redirect_uri' => self::DCR_REDIRECT ) ), 'invalid_grant', 'other client' );
		$this->assert_grant_error( $this->redeem( $code, self::DCR_CLIENT, array( 'client_id' => '' ) ), 'invalid_request', 'empty client_id' );
		$this->assertSame( 200, $this->redeem( $code, self::DCR_CLIENT )->get_status() );
	}

	/**
	 * Pre-PR-3a refresh records carry no client_id: honoured ONCE for a
	 * client that exists, and the rotated token is bound to that client.
	 */
	public function test_a_legacy_unbound_refresh_token_is_honoured_once_and_bound(): void {
		$legacy = 'mcpwp_rt_' . bin2hex( random_bytes( 16 ) );
		set_transient( $this->ctrl()->get_oauth_refresh_token_transient_key( $legacy ), array( 'scopes' => array( 'read', 'write' ), 'user_id' => 1, 'created_at' => time() - 60, 'expires_at' => time() + 3600 ), 3600 );

		$this->assert_grant_error( $this->refresh( $legacy, 'nobody_client' ), 'invalid_grant', 'an unknown client' );
		$this->assertIsArray( $this->refresh_record( $legacy ), 'Not consumed by an unknown client.' );

		$response = $this->refresh( $legacy, self::DCR_CLIENT );
		$this->assertSame( 200, $response->get_status() );
		$next = $this->refresh_record( $response->get_data()['refresh_token'] );
		$this->assertSame( self::DCR_CLIENT, $next['client_id'], 'Bound from now on.' );
		$this->assertNotEmpty( $next['family_id'] );
		$this->assert_grant_error( $this->refresh( $response->get_data()['refresh_token'], 'test_client' ), 'invalid_grant', 'and the binding holds' );
	}

	// ── 2. revocation by epoch ────────────────────────────────────────────

	public function test_revoking_a_client_kills_its_issued_tokens_only(): void {
		$mine   = $this->grant( self::DCR_CLIENT );
		$theirs = $this->grant( 'test_client' );

		$this->assertTrue( Mcpwp_OAuth_Revocation::revoke_client( self::DCR_CLIENT ) );
		$this->assertGreaterThan( 0, (float) Mcpwp_OAuth_Clients::find( self::DCR_CLIENT )['revoked_before'], 'The client record carries the epoch.' );

		$this->assertFalse( $this->works( $mine['access_token'] ) );
		$this->assert_grant_error( $this->refresh( $mine['refresh_token'], self::DCR_CLIENT ), 'invalid_grant' );
		$this->assertTrue( $this->works( $theirs['access_token'] ), 'Another client is untouched.' );

		usleep( 20 );
		$fresh = $this->grant( self::DCR_CLIENT );
		$this->assertTrue( $this->works( $fresh['access_token'] ), 'Tokens issued after the revocation work.' );
	}

	public function test_a_code_issued_before_its_client_was_revoked_is_dead(): void {
		$code = $this->seed_code( self::DCR_CLIENT );
		usleep( 20 );
		Mcpwp_OAuth_Revocation::revoke_client( self::DCR_CLIENT );
		$this->assert_grant_error( $this->redeem( $code, self::DCR_CLIENT ), 'invalid_grant' );
	}

	public function test_revoking_the_legacy_client_uses_the_epoch_option(): void {
		$data = $this->grant( 'test_client' );
		$this->assertTrue( Mcpwp_OAuth_Revocation::revoke_client( 'test_client' ) );
		$this->assertArrayHasKey( 'test_client', get_option( Mcpwp_OAuth_Revocation::CLIENT_EPOCHS_OPTION ) );
		$this->assertFalse( $this->works( $data['access_token'] ) );
	}

	public function test_deleting_a_client_kills_its_tokens_although_the_record_is_gone(): void {
		$data = $this->grant( self::DCR_CLIENT );
		$this->assertTrue( Mcpwp_OAuth_Clients::delete( self::DCR_CLIENT ) );
		$this->assertNull( Mcpwp_OAuth_Clients::find( self::DCR_CLIENT ) );

		$this->assertFalse( $this->works( $data['access_token'] ), 'Tombstoned.' );
		$this->assertGreaterThan( 0, Mcpwp_OAuth_Revocation::client_epoch( self::DCR_CLIENT ) );
	}

	public function test_revoking_a_user_kills_that_users_tokens_only(): void {
		$one = $this->grant( 'test_client', 1 );
		$two = $this->grant( 'test_client', 2 );

		$this->assertTrue( Mcpwp_OAuth_Revocation::revoke_user( 1 ) );
		$this->assertFalse( $this->works( $one['access_token'] ) );
		$this->assert_grant_error( $this->refresh( $one['refresh_token'] ), 'invalid_grant' );
		$this->assertTrue( $this->works( $two['access_token'] ) );
	}

	public function test_a_deleted_users_tokens_stay_dead_if_the_id_is_reused(): void {
		$data = $this->grant( 'test_client', 2 );
		do_action( 'delete_user', 2 );
		Mcpwp_OAuth_Revocation::on_user_deleted( 2 ); // what the delete_user hook runs (init())
		$GLOBALS['mcpwp_test_user_meta'] = array(); // the user's meta is deleted with them

		// The id comes back (an InnoDB auto-increment reset): a new user 2.
		$this->assertFalse( $this->works( $data['access_token'] ) );
		$this->assert_grant_error( $this->refresh( $data['refresh_token'] ), 'invalid_grant' );
	}

	public function test_user_deletion_hook_is_registered(): void {
		$GLOBALS['mcpwp_test_actions'] = array();
		Mcpwp_OAuth_Revocation::init();
		foreach ( array( 'delete_user', 'wpmu_delete_user' ) as $hook ) {
			$callbacks = array_column( array_merge( ...array_values( $GLOBALS['mcpwp_test_actions'][ $hook ] ?? array( array() ) ) ), 'callback' );
			$this->assertContains( array( 'Mcpwp_OAuth_Revocation', 'on_user_deleted' ), $callbacks, $hook );
		}

		// And through the hook, the epoch lands in the option.
		do_action( 'delete_user', 7 );
		$this->assertArrayHasKey( '7', get_option( Mcpwp_OAuth_Revocation::DELETED_USERS_OPTION ) );
		$GLOBALS['mcpwp_test_actions'] = array();
	}

	public function test_the_epoch_comparison_is_strict_and_fails_closed(): void {
		update_user_meta( 1, Mcpwp_OAuth_Revocation::USER_META, 1000.5 );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( array( 'user_id' => 1, 'issued_at' => 1000.5 ) ), 'Issued AT the epoch: revoked.' );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( array( 'user_id' => 1, 'issued_at' => 999.0 ) ) );
		$this->assertFalse( Mcpwp_OAuth_Revocation::is_revoked( array( 'user_id' => 1, 'issued_at' => 1000.501 ) ) );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( array( 'user_id' => 1 ) ), 'No issued_at / created_at: any epoch catches it.' );
		$this->assertTrue( Mcpwp_OAuth_Revocation::is_revoked( array( 'user_id' => 1, 'created_at' => 1000 ) ), 'created_at is the fallback.' );
		$this->assertFalse( Mcpwp_OAuth_Revocation::is_revoked( array( 'user_id' => 2, 'issued_at' => 1.0 ) ), 'No epoch: not revoked.' );
	}

	// ── 3. RFC 7009 ───────────────────────────────────────────────────────

	public function test_revoking_a_refresh_token_kills_its_whole_family(): void {
		$first  = $this->grant( self::DCR_CLIENT );
		$second = $this->refresh( $first['refresh_token'], self::DCR_CLIENT )->get_data();

		$response = $this->revoke( array( 'token' => $second['refresh_token'], 'token_type_hint' => 'refresh_token', 'client_id' => self::DCR_CLIENT ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store', $response->get_headers()['Cache-Control'] );

		$this->assertFalse( $this->refresh_record( $second['refresh_token'] ) );
		$this->assertFalse( $this->works( $second['access_token'] ), 'The family\'s access tokens die.' );
		$this->assertFalse( $this->works( $first['access_token'] ), 'Including the earlier rotation\'s.' );

		$other = $this->grant( self::DCR_CLIENT );
		$this->assertTrue( $this->works( $other['access_token'] ), 'Another family of the same client is untouched.' );
	}

	public function test_revoking_an_access_token_deletes_only_that_token(): void {
		$data = $this->grant( self::DCR_CLIENT );
		$this->assertSame( 200, $this->revoke( array( 'token' => $data['access_token'], 'token_type_hint' => 'access_token', 'client_id' => self::DCR_CLIENT ) )->get_status() );
		$this->assertFalse( $this->works( $data['access_token'] ) );
		$this->assertSame( 200, $this->refresh( $data['refresh_token'], self::DCR_CLIENT )->get_status(), 'The refresh token is not touched.' );
	}

	public function test_only_the_presenting_clients_token_is_revoked(): void {
		$data = $this->grant( self::DCR_CLIENT );
		$this->assertSame( 200, $this->revoke( array( 'token' => $data['refresh_token'], 'client_id' => 'test_client' ) )->get_status(), 'Still 200 (§2.2).' );
		$this->assertSame( 200, $this->revoke( array( 'token' => $data['access_token'], 'client_id' => 'test_client' ) )->get_status() );
		$this->assertTrue( $this->works( $data['access_token'] ) );
		$this->assertIsArray( $this->refresh_record( $data['refresh_token'] ) );
	}

	public function test_unknown_and_malformed_tokens_answer_200(): void {
		foreach ( array( 'mcpwp_rt_' . str_repeat( 'a', 64 ), 'mcpwp_at_' . str_repeat( 'b', 48 ), 'garbage', str_repeat( 'x', 5000 ) ) as $token ) {
			$this->assertSame( 200, $this->revoke( array( 'token' => $token, 'client_id' => self::DCR_CLIENT ) )->get_status(), substr( $token, 0, 12 ) );
		}
	}

	public function test_a_revoke_without_token_or_client_is_a_request_error(): void {
		$this->assertSame( 'invalid_request', $this->revoke( array( 'client_id' => self::DCR_CLIENT ) )->get_data()['error'] );
		$this->assertSame( 'invalid_request', $this->revoke( array( 'token' => 'mcpwp_rt_x' ) )->get_data()['error'] );
		$unknown = $this->revoke( array( 'token' => 'mcpwp_rt_x', 'client_id' => 'nobody' ) );
		$this->assertSame( 401, $unknown->get_status() );
		$this->assertSame( 'invalid_client', $unknown->get_data()['error'] );
	}

	public function test_the_revoke_endpoint_is_rate_limited_per_caller(): void {
		$ctrl               = $this->ctrl();
		$ctrl->limit_answer = new WP_Error( 'rate_limit_exceeded', 'Slow down.', array( 'status' => 429 ) );
		$data               = $this->grant( self::DCR_CLIENT );

		$response = $ctrl->handle_revoke( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/revoke', array( 'token' => $data['access_token'], 'client_id' => self::DCR_CLIENT ) ) );
		$this->assertSame( 429, $response->get_status() );
		$this->assertSame( array( 'oauth-revoke:203.0.113.7' ), $ctrl->limited );
		$this->assertTrue( $this->works( $data['access_token'] ), 'Nothing revoked over the limit.' );
	}

	public function test_metadata_advertises_the_revocation_endpoint(): void {
		$meta = Mcpwp_OAuth_Well_Known::build_authorization_server_metadata();
		$this->assertStringEndsWith( '/mcpwp/v1/oauth/revoke', $meta['revocation_endpoint'] );
		$this->assertSame( array( 'none' ), $meta['revocation_endpoint_auth_methods_supported'] );
	}

	// ── 4. nothing is burned before its checks ────────────────────────────

	/** @return array<string,array{0:array}> */
	public static function bad_redemptions(): array {
		return array(
			'wrong verifier'     => array( array( 'code_verifier' => 'wrong-verifier-wrong-verifier-wrong-verifier' ) ),
			'wrong redirect_uri' => array( array( 'redirect_uri' => 'https://evil.example/cb' ) ),
			'wrong client'       => array( array( 'client_id' => self::DCR_CLIENT ) ),
			'wrong resource'     => array( array( 'resource' => 'https://evil.example/mcp' ) ),
		);
	}

	/**
	 * @dataProvider bad_redemptions
	 */
	public function test_a_failed_redemption_does_not_burn_the_code( array $override ): void {
		$code = $this->seed_code();
		$this->assertNotSame( 200, $this->redeem( $code, 'test_client', $override )->get_status() );
		$this->assertSame( 200, $this->redeem( $code )->get_status(), 'The real client can still redeem.' );
		$this->assertNotSame( 200, $this->redeem( $code )->get_status(), 'Still single-use.' );
	}

	// ── 5. refresh reuse detection ────────────────────────────────────────

	public function test_presenting_a_rotated_away_refresh_token_kills_the_family(): void {
		$first  = $this->grant( self::DCR_CLIENT );
		$second = $this->refresh( $first['refresh_token'], self::DCR_CLIENT )->get_data();
		$this->assertTrue( $this->works( $second['access_token'] ) );

		$replay = $this->refresh( $first['refresh_token'], self::DCR_CLIENT );
		$this->assert_grant_error( $replay, 'invalid_grant' );

		$this->assert_grant_error( $this->refresh( $second['refresh_token'], self::DCR_CLIENT ), 'invalid_grant', 'The current token of the family dies.' );
		$this->assertFalse( $this->works( $second['access_token'] ) );
		$this->assertTrue( Mcpwp_OAuth_Revocation::family_revoked( $this->tombstone_family( $first['refresh_token'] ) ) );
	}

	public function test_the_family_marker_survives_a_lost_transient(): void {
		$first  = $this->grant( self::DCR_CLIENT );
		$second = $this->refresh( $first['refresh_token'], self::DCR_CLIENT )->get_data();
		$this->refresh( $first['refresh_token'], self::DCR_CLIENT );

		// An object cache evicts every transient marker.
		foreach ( array_keys( $GLOBALS['mcpwp_test_transients'] ) as $key ) {
			if ( 0 === strpos( $key, Mcpwp_OAuth_Revocation::FAMILY_TRANSIENT_PREFIX ) ) {
				unset( $GLOBALS['mcpwp_test_transients'][ $key ] );
			}
		}
		$this->assertFalse( $this->works( $second['access_token'] ), 'The option still knows.' );
	}

	public function test_rotation_leaves_a_tombstone_for_the_refresh_lifetime(): void {
		$first = $this->grant( self::DCR_CLIENT );
		$this->refresh( $first['refresh_token'], self::DCR_CLIENT );
		$key = $this->ctrl()->get_oauth_refresh_token_reuse_key( $first['refresh_token'] );
		$this->assertArrayHasKey( $key, $GLOBALS['mcpwp_test_transients'] );
		$this->assertSame( Mcpwp_REST_OAuth::refresh_token_ttl(), $GLOBALS['mcpwp_test_transients'][ $key ]['ttl'] );
	}

	private function tombstone_family( string $refresh_token ): string {
		$t = get_transient( $this->ctrl()->get_oauth_refresh_token_reuse_key( $refresh_token ) );
		return is_array( $t ) ? (string) $t['family_id'] : '';
	}

	// ── 6. DCR per-address ceiling ────────────────────────────────────────

	public function test_one_address_cannot_spend_the_site_day_by_varying_redirect_hosts(): void {
		$made = 0;
		for ( $i = 0; $i < 30; $i++ ) {
			$result = Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( 'https://host' . $i . '.example.com/cb' ) ), '198.51.100.9' );
			if ( is_wp_error( $result ) ) {
				$this->assertSame( 'temporarily_unavailable', $result->get_error_code() );
				$this->assertSame( 429, $result->get_error_data()['status'] );
				break;
			}
			++$made;
		}
		$this->assertSame( Mcpwp_OAuth_Clients::ADDRESS_REGISTRATIONS_PER_DAY, $made );
		$this->assertLessThan( Mcpwp_OAuth_Clients::SITE_REGISTRATIONS_PER_DAY, $made );

		// The same /64 is the same address; another address still registers.
		$this->assertIsArray( Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( 'https://other.example.com/cb' ) ), '198.51.100.10' ) );
		for ( $i = 0; $i < Mcpwp_OAuth_Clients::ADDRESS_REGISTRATIONS_PER_DAY; $i++ ) {
			Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( 'https://v6host' . $i . '.example.com/cb' ) ), '2001:db8:1:2::' . dechex( $i + 1 ) );
		}
		$this->assertInstanceOf( WP_Error::class, Mcpwp_OAuth_Clients::register( array( 'redirect_uris' => array( 'https://v6last.example.com/cb' ) ), '2001:db8:1:2:ffff::1' ) );
	}

	// ── 7. small items ────────────────────────────────────────────────────

	public function test_the_d10_migration_waits_for_its_lock(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false, 'other' => 1 ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Local_Admin::MIGRATION_LOCK_OPTION ] = ( time() + 60 ) . '|deadbeef';

		$this->assertFalse( Mcpwp_OAuth_Local_Admin::migrate_default_on(), 'Held: skipped.' );
		$this->assertSame( array( 'oauth_enabled' => false, 'other' => 1 ), get_option( 'mcpwp_settings' ) );
		$this->assertFalse( (bool) get_option( Mcpwp_OAuth_Local_Admin::MIGRATED_FLAG ) );

		unset( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_OAuth_Local_Admin::MIGRATION_LOCK_OPTION ] );
		$this->assertTrue( Mcpwp_OAuth_Local_Admin::migrate_default_on(), 'Next load runs it.' );
		$this->assertSame( array( 'other' => 1 ), get_option( 'mcpwp_settings' ) );
		$this->assertArrayNotHasKey( Mcpwp_OAuth_Local_Admin::MIGRATION_LOCK_OPTION, $GLOBALS['mcpwp_test_wpdb_options'], 'Released.' );
	}

	public function test_the_d10_migration_rereads_the_flag_under_the_lock(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => false ) );
		// Another request finishes the migration — and the admin then switches
		// OAuth off again — between this request's first check and its lock.
		$real            = $GLOBALS['wpdb'];
		$GLOBALS['wpdb'] = new class( $real ) {
			private $real;
			public function __construct( $real ) {
				$this->real = $real;
			}
			public function insert( ...$args ) {
				update_option( Mcpwp_OAuth_Local_Admin::MIGRATED_FLAG, 1 );
				return $this->real->insert( ...$args );
			}
			public function __call( $name, $args ) {
				return $this->real->$name( ...$args );
			}
			public function __get( $name ) {
				return $this->real->$name;
			}
		};
		try {
			$this->assertFalse( Mcpwp_OAuth_Local_Admin::migrate_default_on() );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}
		$this->assertSame( array( 'oauth_enabled' => false ), get_option( 'mcpwp_settings' ), 'The later explicit "off" is kept.' );
	}

	/** The two OAuth read_post branches (Kasra, #1101), each pinned on its own. */
	public function test_oauth_read_post_branch_in_requester_can_read_post(): void {
		$auth  = new Mcpwp_OAuth_1101_Auth();
		$token = $auth->issue_oauth_access_token( array( 'read' ), 3600, array( 'user_id' => 1, 'client_id' => 'test_client' ) )['access_token'];
		$this->assertTrue( $auth->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/site-info', array(), array( 'Authorization' => 'Bearer ' . $token ) ) ) );

		$public = new WP_Post( array( 'ID' => 9701, 'post_type' => 'post', 'post_status' => 'publish' ) );
		$draft  = new WP_Post( array( 'ID' => 9702, 'post_type' => 'post', 'post_status' => 'draft' ) );

		$this->assertFalse( $auth->can_read( $public ), 'A public post the user may not read_post is unreadable.' );
		$GLOBALS['mcpwp_test_post_caps'][1] = array( 'read_post' => array( 9701 => true, 9702 => true ) );
		$this->assertTrue( $auth->can_read( $public ) );
		$this->assertFalse( $auth->can_read( $draft ), 'read_post, but a non-public post also needs write scope.' );
	}

	public function test_oauth_read_post_branch_in_post_ref_is_hidden(): void {
		$GLOBALS['_mcpwp_test_posts'] = array(
			9701 => new WP_Post( array( 'ID' => 9701, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'x' ) ),
			9702 => new WP_Post( array( 'ID' => 9702, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'y' ) ),
		);
		$token = ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token( array( 'read' ), 3600, array( 'user_id' => 1, 'client_id' => 'test_client' ) )['access_token'];
		$check = function ( $id ) use ( $token ) {
			return ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'GET', '/mcpwp/v1/posts/' . $id, array( 'id' => $id ), array( 'Authorization' => 'Bearer ' . $token ) ) );
		};

		$this->assertSame( 'not_found', $check( 9701 )->get_error_code(), 'No read_post: reads like a missing id.' );
		$GLOBALS['mcpwp_test_post_caps'][1] = array( 'read_post' => array( 9701 => true, 9702 => true ) );
		$this->assertTrue( $check( 9701 ) );
		$this->assertSame( 'not_found', $check( 9702 )->get_error_code(), 'A draft needs write scope too.' );
	}
}
