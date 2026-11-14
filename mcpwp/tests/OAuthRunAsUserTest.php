<?php
/**
 * PR-2 round 2, P0: an OAuth token acts as the WordPress user who approved
 * it — never as the mcpwp_api_agent service account — and only users at or
 * above the site's minimum role may hold one.  Also the WooCommerce
 * orders/customers admin-scope classification (Kasra option A).
 *
 * Unit-level notes (the rig check covers real WordPress):
 *  - The bootstrap's current_user_can() does not map meta capabilities
 *    (read_post / edit_post) per post, so fixtures grant or deny them per
 *    user.  The per-post decision itself is WordPress's; what is tested here
 *    is that MCPWP asks it, as the right user, on every path.
 *  - A user below the minimum role is refused at authentication (401
 *    invalid_token) before any route runs; a hidden draft is the canonical
 *    404 (existence is never disclosed); a visible post the user may not
 *    change is 403 rest_cannot_edit.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-oauth-local-admin.php';

final class OAuthRunAsUserTest extends TestCase {

	const BOT_ID     = 2;
	const EDITOR     = 7;
	const AUTHOR     = 11;
	const CONTRIB    = 12;
	const SUBSCRIBER = 13;
	const OTHER      = 50;

	const DRAFT_ID = 9301;
	const PUB_ID   = 9302;

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['_mcpwp_test_meta']        = array();

		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		foreach ( array( self::EDITOR, self::AUTHOR, self::CONTRIB, self::SUBSCRIBER, self::OTHER ) as $id ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $id, 'roles' => array(), 'user_login' => 'u' . $id );
		}

		$GLOBALS['mcpwp_test_user_caps'] = array(
			self::EDITOR     => array( 'read' => true, 'edit_posts' => true, 'edit_others_posts' => true, 'publish_posts' => true, 'read_private_posts' => true, 'read_post' => true, 'edit_post' => true, 'delete_post' => true ),
			// Authors read and edit only their own; these fixtures are about
			// ANOTHER author's posts, so the meta caps are denied.
			self::AUTHOR     => array( 'read' => true, 'edit_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'upload_files' => true, 'read_post' => false, 'edit_post' => false ),
			self::CONTRIB    => array( 'read' => true, 'edit_posts' => true, 'read_post' => false, 'edit_post' => false ),
			self::SUBSCRIBER => array( 'read' => true ),
		);

		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::DRAFT_ID => new WP_Post( array( 'ID' => self::DRAFT_ID, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Secret draft', 'post_author' => self::OTHER, 'post_content' => 'unreleased' ) ),
			self::PUB_ID   => new WP_Post( array( 'ID' => self::PUB_ID, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Live post', 'post_author' => self::OTHER, 'post_content' => 'live' ) ),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']        = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_post_caps']    = array();
	}

	private function token( int $user_id, array $scopes = array( 'read', 'write' ) ): string {
		$auth    = new Mcpwp_Api_Auth_Test_Harness();
		$context = $user_id > 0 ? array( 'user_id' => $user_id ) : array( 'client_id' => 'machine' );
		return $auth->issue_oauth_access_token( $scopes, 3600, $context )['access_token'];
	}

	/** Authenticate a request the way the REST permission callback does. */
	private function auth( string $token, string $method, string $route, array $params = array() ) {
		$request = new WP_REST_Request( $method, $route, $params, array( 'Authorization' => 'Bearer ' . $token ) );
		return ( new Mcpwp_REST_Posts() )->check_permission( $request );
	}

	private function assert_error_code( $result, string $code, int $status ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $status, $result->get_error_data()['status'] );
	}

	// ── (a) run as the approving user ──────────────────────────────────────

	public function test_editor_token_runs_as_the_editor_not_the_service_account(): void {
		$this->assertTrue( $this->auth( $this->token( self::EDITOR ), 'GET', '/mcpwp/v1/posts' ) );
		$this->assertSame( self::EDITOR, get_current_user_id(), 'P0: WordPress capability checks must see the approving user.' );
	}

	public function test_token_without_a_user_keeps_the_service_account(): void {
		// client_credentials (and pre-identity legacy tokens): unchanged path.
		$this->assertTrue( $this->auth( $this->token( 0 ), 'GET', '/mcpwp/v1/posts' ) );
		$this->assertSame( self::BOT_ID, get_current_user_id() );
	}

	public function test_editor_token_works_for_a_normal_write(): void {
		$this->assertTrue( $this->auth( $this->token( self::EDITOR ), 'PUT', '/mcpwp/v1/posts/' . self::PUB_ID, array( 'id' => self::PUB_ID, 'title' => 'Edited' ) ) );
		$this->assertSame( self::EDITOR, get_current_user_id() );
	}

	public function test_editor_token_reads_a_draft(): void {
		$this->assertTrue( $this->auth( $this->token( self::EDITOR ), 'GET', '/mcpwp/v1/posts/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ) ) );
	}

	public function test_subscriber_token_reading_a_draft_is_refused(): void {
		$result = $this->auth( $this->token( self::SUBSCRIBER ), 'GET', '/mcpwp/v1/posts/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ) );
		$this->assert_error_code( $result, 'invalid_token', 401 );
		$this->assertNotSame( self::BOT_ID, get_current_user_id(), 'Refused before any user context is set.' );
	}

	public function test_user_who_cannot_read_a_draft_gets_the_canonical_404(): void {
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		$result = $this->auth( $this->token( self::AUTHOR ), 'GET', '/mcpwp/v1/posts/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ) );
		$this->assert_error_code( $result, 'not_found', 404 );
	}

	public function test_read_scope_token_never_sees_a_draft_even_for_an_editor(): void {
		$result = $this->auth( $this->token( self::EDITOR, array( 'read' ) ), 'GET', '/mcpwp/v1/posts/' . self::DRAFT_ID, array( 'id' => self::DRAFT_ID ) );
		$this->assert_error_code( $result, 'not_found', 404 );
	}

	public function test_contributor_token_editing_another_authors_post_is_refused(): void {
		// Below every minimum-role choice: refused at authentication.
		$result = $this->auth( $this->token( self::CONTRIB ), 'PUT', '/mcpwp/v1/posts/' . self::PUB_ID, array( 'id' => self::PUB_ID, 'title' => 'Hijack' ) );
		$this->assert_error_code( $result, 'invalid_token', 401 );
	}

	public function test_user_who_can_read_but_not_edit_a_post_gets_403(): void {
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		$GLOBALS['mcpwp_test_user_caps'][ self::AUTHOR ]['read_post'] = true; // can see the live post…

		$put = $this->auth( $this->token( self::AUTHOR ), 'PUT', '/mcpwp/v1/posts/' . self::PUB_ID, array( 'id' => self::PUB_ID, 'title' => 'Hijack' ) );
		$this->assert_error_code( $put, 'rest_cannot_edit', 403 ); // …but not change it.

		// DELETE asks delete_post, not edit_post: allowed to edit, not to delete.
		$GLOBALS['mcpwp_test_user_caps'][ self::AUTHOR ]['edit_post']   = true;
		$GLOBALS['mcpwp_test_user_caps'][ self::AUTHOR ]['delete_post'] = false;
		$this->assertTrue( $this->auth( $this->token( self::AUTHOR ), 'PUT', '/mcpwp/v1/posts/' . self::PUB_ID, array( 'id' => self::PUB_ID, 'title' => 'Fine' ) ) );
		$delete = $this->auth( $this->token( self::AUTHOR ), 'DELETE', '/mcpwp/v1/posts/' . self::PUB_ID, array( 'id' => self::PUB_ID ) );
		$this->assert_error_code( $delete, 'rest_cannot_edit', 403 );

		$this->assertTrue( $this->auth( $this->token( self::AUTHOR ), 'GET', '/mcpwp/v1/posts/' . self::PUB_ID, array( 'id' => self::PUB_ID ) ), 'Reading stays allowed.' );
	}

	public function test_deleted_user_token_is_refused(): void {
		$token                        = $this->token( self::EDITOR );
		$GLOBALS['mcpwp_test_users'] = $this->saved_users; // user 7 gone
		$this->assert_error_code( $this->auth( $token, 'GET', '/mcpwp/v1/posts' ), 'invalid_token', 401 );
	}

	public function test_demoted_user_token_is_refused_on_its_next_request(): void {
		$token = $this->token( self::EDITOR );
		$this->assertTrue( $this->auth( $token, 'GET', '/mcpwp/v1/posts' ) );

		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['edit_others_posts'] = false;
		$this->assert_error_code( $this->auth( $token, 'GET', '/mcpwp/v1/posts' ), 'invalid_token', 401 );
	}

	// ── (b) minimum role ───────────────────────────────────────────────────

	public function test_min_role_default_is_editor_and_choices_map_to_capabilities(): void {
		$this->assertSame( 'editor', Mcpwp_OAuth_Well_Known::get_min_role() );
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'root' );
		$this->assertSame( 'editor', Mcpwp_OAuth_Well_Known::get_min_role(), 'Unknown values fall back to Editor.' );

		$this->assertTrue( Mcpwp_OAuth_Well_Known::user_meets_min_role( self::EDITOR ) );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::user_meets_min_role( self::AUTHOR ) );

		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		$this->assertTrue( Mcpwp_OAuth_Well_Known::user_meets_min_role( self::AUTHOR ) );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::user_meets_min_role( self::CONTRIB ) );

		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'administrator' );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::user_meets_min_role( self::EDITOR ) );
		$this->assertFalse( Mcpwp_OAuth_Well_Known::user_meets_min_role( 0 ) );
	}

	public function test_list_level_non_public_visibility_follows_the_users_caps(): void {
		$probe = new class() extends Mcpwp_Api_Auth_Test_Harness {
			public function can_see_non_public() {
				return $this->requester_can_see_non_public();
			}
		};
		$token = $this->token( self::EDITOR );
		$this->assertTrue( $probe->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/posts', array(), array( 'Authorization' => 'Bearer ' . $token ) ) ) );
		$this->assertTrue( $probe->can_see_non_public(), 'An Editor with a write token lists drafts, as in wp-admin.' );

		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['read_private_posts'] = false;
		$this->assertFalse( $probe->can_see_non_public(), 'Without read_private_posts, lists clamp to published.' );
	}

	public function test_setup_switch_stores_only_a_listed_minimum_role(): void {
		$GLOBALS['mcpwp_test_current_user']                 = 1;
		$GLOBALS['mcpwp_test_user_caps'][1]                 = array( 'activate_plugins' => true );
		$post = array( Mcpwp_OAuth_Local_Admin::NONCE_FIELD => 'test-nonce', 'mcpwp_local_oauth' => '1' );

		Mcpwp_OAuth_Local_Admin::process_toggle( $post + array( 'mcpwp_local_oauth_min_role' => 'author' ) );
		$this->assertSame( 'author', get_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION ) );

		Mcpwp_OAuth_Local_Admin::process_toggle( $post + array( 'mcpwp_local_oauth_min_role' => 'subscriber' ) );
		$this->assertSame( 'author', get_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION ), 'An unlisted role is never stored.' );

		Mcpwp_OAuth_Local_Admin::process_toggle( $post + array( 'mcpwp_local_oauth_min_role' => 'administrator' ) );
		$this->assertSame( 'administrator', Mcpwp_OAuth_Well_Known::get_min_role() );
	}

	// ── Per-item (bulk) post references ────────────────────────────────────

	const OWN_A = 9311;
	const OWN_B = 9312;

	private function seed_bulk_posts(): void {
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		foreach ( array( self::OWN_A, self::OWN_B ) as $id ) {
			$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Own ' . $id, 'post_author' => self::AUTHOR, 'post_content' => 'mine' ) );
		}
		// WordPress's per-post answer for this Author: own posts yes, another
		// author's live post no.
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ] = array(
			'read_post' => array( self::OWN_A => true, self::OWN_B => true, self::PUB_ID => true ),
			'edit_post' => array( self::OWN_A => true, self::OWN_B => true, self::PUB_ID => false ),
		);
	}

	public function test_author_bulk_edit_including_another_authors_post_is_403_and_changes_nothing(): void {
		$this->seed_bulk_posts();
		$titles = array_map( function ( $p ) {
			return $p->post_title;
		}, $GLOBALS['_mcpwp_test_posts'] );

		foreach ( array(
			array( 'updates' => array( array( 'id' => self::OWN_A, 'title' => 'x' ), array( 'id' => self::PUB_ID, 'title' => 'x' ) ) ),
			array( 'posts' => array( array( 'id' => self::OWN_B, 'title' => 'x' ), array( 'id' => self::PUB_ID, 'title' => 'x' ) ) ),
		) as $body ) {
			$result = $this->auth( $this->token( self::AUTHOR ), 'POST', '/mcpwp/v1/posts/bulk', $body );
			$this->assert_error_code( $result, 'rest_cannot_edit', 403 );
		}
		// Refused at the permission callback: no handler ran, nothing changed.
		$this->assertSame( $titles, array_map( function ( $p ) {
			return $p->post_title;
		}, $GLOBALS['_mcpwp_test_posts'] ) );
	}

	public function test_author_bulk_edit_of_only_own_posts_passes(): void {
		$this->seed_bulk_posts();
		$body = array( 'updates' => array( array( 'id' => self::OWN_A, 'title' => 'x' ), array( 'id' => self::OWN_B, 'title' => 'y' ) ) );
		$this->assertTrue( $this->auth( $this->token( self::AUTHOR ), 'POST', '/mcpwp/v1/posts/bulk', $body ) );
	}

	public function test_bulk_ids_array_is_checked_too(): void {
		$this->seed_bulk_posts();
		$GLOBALS['mcpwp_test_user_caps'][ self::AUTHOR ]['edit_posts'] = true;
		$result = $this->auth( $this->token( self::AUTHOR ), 'POST', '/mcpwp/v1/elementor/find-replace', array( 'find' => 'a', 'replace' => 'b', 'ids' => array( self::OWN_A, self::PUB_ID ) ) );
		$this->assert_error_code( $result, 'rest_cannot_edit', 403 );
	}

	// ── Sub-request path (batch / tools/call fan-out) ──────────────────────

	private function sub_request( string $token, string $route = '/mcpwp/v1/site-info' ) {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		try {
			return $auth->verify_api_key( new WP_REST_Request( 'GET', $route, array(), array( 'Authorization' => 'Bearer ' . $token ) ) );
		} finally {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
	}

	public function test_sub_request_path_runs_as_the_user_too(): void {
		$this->assertTrue( $this->sub_request( $this->token( self::EDITOR ) ) );
		$this->assertSame( self::EDITOR, get_current_user_id() );
		$this->assert_error_code( $this->sub_request( $this->token( self::SUBSCRIBER ) ), 'invalid_token', 401 );
	}

	public function test_sub_request_path_enforces_aud(): void {
		$token = $this->token( self::EDITOR );
		mcpwp_test_oauth_set( $token, array( 'aud' => 'https://other.example/wp-json/mcpwp/v1/mcp' ) );

		$this->assert_error_code( $this->sub_request( $token ), 'invalid_token', 401 );
	}

	// ── Consent + token endpoint under the minimum role ────────────────────

	const REDIRECT = 'https://client.example.com/callback';
	const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

	private function legacy_client(): void {
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true, 'oauth_client_id' => 'test_client', 'oauth_redirect_uris' => array( self::REDIRECT ), 'enable_logging' => true ) );
	}

	private function consent_get( int $user_id ) {
		$GLOBALS['mcpwp_test_current_user'] = $user_id;
		$ctrl                                = new Mcpwp_REST_OAuth();
		return $ctrl->handle_authorize_get(
			new WP_REST_Request(
				'GET',
				'/mcpwp/v1/oauth/authorize',
				array(
					'response_type'         => 'code',
					'client_id'             => 'test_client',
					'redirect_uri'          => self::REDIRECT,
					'code_challenge'        => $ctrl->compute_pkce_challenge( self::VERIFIER ),
					'code_challenge_method' => 'S256',
					'scope'                 => 'read write',
					'state'                 => 's',
				)
			)
		);
	}

	private function assert_refusal_page( $response ): void {
		$this->assertSame( 403, $response->get_status() );
		$this->assertStringContainsString( 'Your WordPress role can&#039;t connect AI apps on this site; ask an administrator.', (string) $response->get_data() );
		$this->assertArrayNotHasKey( 'Location', $response->get_headers() );
	}

	public function test_consent_refuses_a_subscriber(): void {
		$this->legacy_client();
		$this->assert_refusal_page( $this->consent_get( self::SUBSCRIBER ) );
	}

	public function test_consent_refuses_an_author_under_the_default_editor_minimum(): void {
		$this->legacy_client();
		$this->assert_refusal_page( $this->consent_get( self::AUTHOR ) );
		$this->assertStringContainsString( 'Editor role or higher', (string) $this->consent_get( self::AUTHOR )->get_data() );
	}

	public function test_consent_allows_an_author_when_the_minimum_is_author(): void {
		$this->legacy_client();
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		$response = $this->consent_get( self::AUTHOR );
		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( '<form', (string) $response->get_data() );
	}

	public function test_consent_allows_an_editor_by_default(): void {
		$this->legacy_client();
		$this->assertSame( 200, $this->consent_get( self::EDITOR )->get_status() );
	}

	private function seed_code( int $user_id ): string {
		$code = 'c_' . bin2hex( random_bytes( 6 ) );
		set_transient( 'mcpwp_oauth_code_' . $code, array( 'client_id' => 'test_client', 'redirect_uri' => self::REDIRECT, 'code_challenge' => ( new Mcpwp_REST_OAuth() )->compute_pkce_challenge( self::VERIFIER ), 'scope' => 'read write', 'user_id' => $user_id, 'expires' => time() + 60 ), 60 );
		return $code;
	}

	private function redeem( string $code ) {
		return ( new Mcpwp_REST_OAuth() )->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'test_client', 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER ) ) );
	}

	public function test_code_redeemed_after_demotion_is_invalid_grant(): void {
		$this->legacy_client();
		$code = $this->seed_code( self::EDITOR );
		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['edit_others_posts'] = false;

		$response = $this->redeem( $code );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	public function test_refresh_after_demotion_is_invalid_grant(): void {
		$this->legacy_client();
		$data = $this->redeem( $this->seed_code( self::EDITOR ) )->get_data();
		$this->assertArrayHasKey( 'refresh_token', $data );

		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['edit_others_posts'] = false;
		$response = ( new Mcpwp_REST_OAuth() )->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'client_id' => 'test_client', 'refresh_token' => $data['refresh_token'] ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	// ── P1-A: refresh carries its own live-user check ──────────────────────

	private function refresh( string $refresh_token ) {
		return ( new Mcpwp_REST_OAuth() )->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'client_id' => 'test_client', 'refresh_token' => $refresh_token ) ) );
	}

	public function test_refresh_after_the_user_is_deleted_is_invalid_grant(): void {
		$this->legacy_client();
		$data = $this->redeem( $this->seed_code( self::EDITOR ) )->get_data();

		// Deleted: get_userdata() is false, even though a stale cap map remains.
		$GLOBALS['mcpwp_test_users'] = $this->saved_users;
		$response                    = $this->refresh( $data['refresh_token'] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_grant', $response->get_data()['error'] );
	}

	public function test_refresh_after_all_roles_are_removed_is_invalid_grant(): void {
		$this->legacy_client();
		$data = $this->redeem( $this->seed_code( self::EDITOR ) )->get_data();

		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ] = array(); // no role, no caps
		$this->assertSame( 'invalid_grant', $this->refresh( $data['refresh_token'] )->get_data()['error'] );
	}

	public function test_a_user_without_the_read_capability_cannot_connect_or_refresh(): void {
		// A custom role that still carries edit_others_posts but not `read`:
		// the read check stands on its own, not only via the minimum role.
		$this->legacy_client();
		$data  = $this->redeem( $this->seed_code( self::EDITOR ) )->get_data();
		$token = $data['access_token'];

		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['read'] = false;
		$this->assertSame( 'invalid_grant', $this->refresh( $data['refresh_token'] )->get_data()['error'] );
		$this->assert_error_code( $this->auth( $token, 'GET', '/mcpwp/v1/posts' ), 'invalid_token', 401 );
		$this->assert_refusal_page( $this->consent_get( self::EDITOR ) );
		$this->assertSame( 'invalid_grant', $this->redeem( $this->seed_code( self::EDITOR ) )->get_data()['error'] );
	}

	public function test_code_for_a_deleted_user_is_invalid_grant(): void {
		$this->legacy_client();
		$code                        = $this->seed_code( self::EDITOR );
		$GLOBALS['mcpwp_test_users'] = $this->saved_users;
		$this->assertSame( 'invalid_grant', $this->redeem( $code )->get_data()['error'] );
	}

	// ── P2-1: the delete is the claim ──────────────────────────────────────

	public function test_concurrent_second_redemption_of_one_code_is_invalid_grant(): void {
		$this->legacy_client();
		$code   = $this->seed_code( self::EDITOR );
		$record = get_transient( 'mcpwp_oauth_code_' . $code );

		// The second request READ the code before the first one deleted it:
		// get_transient() still hands it the record, but its delete finds
		// nothing to remove.
		$GLOBALS['mcpwp_test_transient_hook'] = function ( $name ) use ( $code, $record ) {
			return 'mcpwp_oauth_code_' . $code === $name ? $record : null;
		};
		try {
			$first  = $this->redeem( $code );
			$second = $this->redeem( $code );
		} finally {
			unset( $GLOBALS['mcpwp_test_transient_hook'] );
		}

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'invalid_grant', $second->get_data()['error'] );
	}

	public function test_concurrent_second_refresh_with_one_token_is_invalid_grant(): void {
		$this->legacy_client();
		$data = $this->redeem( $this->seed_code( self::EDITOR ) )->get_data();

		// #1101 PR-3b: both requests read the live row and pass every check;
		// the second one's claim lands first.  The first one's claim — one
		// conditional UPDATE — then affects 0 rows.
		$second = null;
		$GLOBALS['mcpwp_test_oauth_tokens_before'] = function ( $kind, $sql ) use ( $data, &$second ) {
			if ( null === $second && 'query' === $kind && false !== strpos( $sql, "SET type = 'reuse_tombstone'" ) ) {
				$second = false;
				$second = $this->refresh( $data['refresh_token'] );
			}
		};
		try {
			$first = $this->refresh( $data['refresh_token'] );
		} finally {
			$GLOBALS['mcpwp_test_oauth_tokens_before'] = null;
		}

		$this->assertSame( 200, $second->get_status(), 'The claim that landed first wins.' );
		$this->assertSame( 'invalid_grant', $first->get_data()['error'] );
	}

	// ── P1-2: refresh lifetime decoupled from the access TTL ──────────────

	public function test_refresh_token_lives_30_days_and_keeps_rotating(): void {
		$this->legacy_client();
		$data = $this->redeem( $this->seed_code( self::EDITOR ) )->get_data();
		$this->assertSame( 3600, $data['expires_in'], 'The access TTL is unchanged.' );

		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, (int) mcpwp_test_oauth_row( $data['refresh_token'] )['expires_at'], 5, 'Was min(30 d, 10 x 3600 s) = 10 hours.' );

		// Simulate day 29: the stored record is near its end, the refresh still works and rotates.
		mcpwp_test_oauth_set( $data['refresh_token'], array( 'expires_at' => time() + 60 ) );
		$next = ( new Mcpwp_REST_OAuth() )->handle_token( new WP_REST_Request( 'POST', '/mcpwp/v1/oauth/token', array( 'grant_type' => 'refresh_token', 'client_id' => 'test_client', 'refresh_token' => $data['refresh_token'] ) ) )->get_data();
		$this->assertArrayHasKey( 'refresh_token', $next );
		$this->assertNotSame( $data['refresh_token'], $next['refresh_token'] );
		$this->assertEqualsWithDelta( time() + 30 * DAY_IN_SECONDS, (int) mcpwp_test_oauth_row( $next['refresh_token'] )['expires_at'], 5 );
		$this->assertSame( 'reuse_tombstone', mcpwp_test_oauth_row( $data['refresh_token'] )['type'], 'The used refresh token is spent.' );
	}

	public function test_refresh_lifetime_is_clamped_to_14_30_days(): void {
		add_filter( 'mcpwp_oauth_refresh_ttl_days', function () {
			return 2;
		} );
		$this->assertSame( 14 * DAY_IN_SECONDS, Mcpwp_REST_OAuth::refresh_token_ttl() );
		$GLOBALS['mcpwp_test_filters'] = array();
		add_filter( 'mcpwp_oauth_refresh_ttl_days', function () {
			return 365;
		} );
		$this->assertSame( 30 * DAY_IN_SECONDS, Mcpwp_REST_OAuth::refresh_token_ttl() );
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	// ── P2: activity log ───────────────────────────────────────────────────

	public function test_token_issuance_is_logged_without_any_secret(): void {
		$this->legacy_client();
		$GLOBALS['mcpwp_test_wpdb_rows'] = array();
		$code = $this->seed_code( self::EDITOR );
		$data = $this->redeem( $code )->get_data();

		$rows = $GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_activity_log'] ?? array();
		$this->assertNotEmpty( $rows );
		$entry = end( $rows );
		$this->assertSame( 'oauth_token_issued', $entry['action'] );
		$logged = json_decode( $entry['request_data'], true );
		$this->assertSame( 'test_client', $logged['client_id'] );
		$this->assertSame( self::EDITOR, $logged['user_id'] );
		$this->assertSame( 'read write', $logged['scope'] );

		$all = serialize( $rows );
		foreach ( array( $code, $data['access_token'], $data['refresh_token'], self::VERIFIER ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $all );
		}
	}

	// ── WooCommerce orders/customers: admin scope (option A) ──────────────

	/**
	 * @dataProvider woo_routes
	 */
	public function test_oauth_tokens_never_reach_woo_orders_or_customers( string $route ): void {
		$this->assert_error_code( $this->auth( $this->token( self::SUBSCRIBER ), 'GET', $route ), 'invalid_token', 401 );

		$editor = $this->auth( $this->token( self::EDITOR ), 'GET', $route );
		$this->assert_error_code( $editor, 'insufficient_scope', 403 );
		$this->assertSame( 'admin', $editor->get_error_data()['required_scope'] );
	}

	/**
	 * @dataProvider woo_routes
	 */
	public function test_non_admin_api_key_with_the_woocommerce_category_is_refused( string $route ): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		foreach ( array( array( 'read' ), array( 'read', 'write' ) ) as $scopes ) {
			$key     = $auth->create_scoped_api_key( 'woo', $scopes, 'custom', array( 'woocommerce' ) );
			$request = new WP_REST_Request( 'GET', $route, array(), array( 'X-API-Key' => $key['key'] ) );
			$this->assert_error_code( ( new Mcpwp_REST_Posts() )->check_permission( $request ), 'insufficient_scope', 403 );
		}
	}

	/**
	 * @dataProvider woo_routes
	 */
	public function test_admin_api_key_still_passes_the_auth_gate( string $route ): void {
		$auth    = new Mcpwp_Api_Auth_Test_Harness();
		$key     = $auth->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ) );
		$request = new WP_REST_Request( 'GET', $route, array(), array( 'X-API-Key' => $key['key'] ) );
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( $request ) );
	}

	public static function woo_routes(): array {
		return array(
			'orders'          => array( '/mcpwp/v1/woocommerce/orders' ),
			'order'           => array( '/mcpwp/v1/woocommerce/orders/42' ),
			'order statuses'  => array( '/mcpwp/v1/woocommerce/orders/statuses' ),
			'future sub-route' => array( '/mcpwp/v1/woocommerce/orders/42/notes' ),
			'customers'       => array( '/mcpwp/v1/woocommerce/customers' ),
			'customer'        => array( '/mcpwp/v1/woocommerce/customers/9' ),
		);
	}

	public function test_woo_order_and_customer_tools_resolve_to_admin(): void {
		$auth = new Mcpwp_Api_Auth_Test_Harness();
		foreach ( array( 'wc_list_orders', 'wc_get_order', 'wc_update_order', 'wc_list_order_statuses', 'wc_list_customers', 'wc_get_customer' ) as $tool ) {
			$this->assertSame( 'admin', $auth->required_scope_for_tool( $tool ), $tool );
		}
		$this->assertNotSame( 'admin', $auth->required_scope_for_tool( 'wc_list_products' ), 'Products are not swept in.' );
	}
}
