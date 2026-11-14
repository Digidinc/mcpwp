<?php
/**
 * #1110: the gate reads request lists and ids through get_param(), the
 * accessor every handler uses — never get_params(), whose bucket merge lets
 * a JSON-body null hide a query-string value the handler still reads.
 *
 * Also pins the WP_REST_Request double's bucket model against core, and
 * the bulk-item shape check on the admin-AJAX and destructive-replay legs.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-seo.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-destructive-gate.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';

/**
 * The SEO controller with ONE map row decided for this test: POST /seo/bulk
 * needs edit_posts instead of "admin-only until decided" (manage_options),
 * so an Author's request reaches the per-post edit_post check. The row
 * table is a constant behind a function-static cache — there is no filter
 * or reflection seam — so the decision is made here, in a test-only
 * subclass; every other step is the real check_permission path.
 */
class Mcpwp_Gate_1110_Seo_Decided extends Mcpwp_REST_SEO {
	protected function check_user_run_capability( $request ) {
		if ( 'POST' === $request->get_method() && '/mcpwp/v1/seo/bulk' === $request->get_route() && $this->requester_acts_as_user() ) {
			return current_user_can( 'edit_posts' ) ? true : new WP_Error( 'rest_forbidden_capability', 'no', array( 'status' => 403, 'required_capability' => 'edit_posts' ) );
		}
		return parent::check_user_run_capability( $request );
	}
}

final class Gate1110GetParamParityTest extends TestCase {

	const AUTHOR     = 11;
	const ADMIN      = 1;
	const ADMIN_POST = 9402;

	/** @var array */
	private $saved_users;
	/** @var array */
	private $saved_post;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$this->saved_users                  = $GLOBALS['mcpwp_test_users'];
		$this->saved_post                   = $_POST;
		foreach ( array( self::AUTHOR, self::ADMIN ) as $id ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $id, 'roles' => array(), 'user_login' => 'u' . $id );
		}
		$GLOBALS['mcpwp_test_user_caps'] = array(
			self::AUTHOR => array_fill_keys( array( 'read', 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_published_posts', 'upload_files' ), true ),
			self::ADMIN  => array_fill_keys( array_merge( RouteCapabilityMapTest::EDITOR_CAPS, array( 'manage_options', 'activate_plugins' ) ), true ),
		);
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::ADMIN_POST => new WP_Post( array( 'ID' => self::ADMIN_POST, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Admin post', 'post_author' => self::ADMIN ) ),
		);
		// The Author can read the admin's post but not edit it.
		$GLOBALS['mcpwp_test_post_caps'] = array(
			self::AUTHOR => array( 'read_post' => array( self::ADMIN_POST => true ) ),
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']              = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']          = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_posts']             = array();
		$GLOBALS['mcpwp_test_post_caps']          = array();
		$GLOBALS['mcpwp_test_ajax_referer_valid'] = false;
		$_POST                                    = $this->saved_post;
		$ref = new ReflectionProperty( 'Mcpwp_Destructive_Gate', 'applying_as_admin' );
		$ref->setAccessible( true );
		$ref->setValue( null, 0 );
	}

	private function author_token(): array {
		$token = ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => self::AUTHOR ) )['access_token'];
		return array( 'Authorization' => 'Bearer ' . $token );
	}

	/** A request whose JSON body nulls a key the query string carries. */
	private static function split_request( string $method, string $route, array $headers, string $key, $query_value ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route, array(), $headers );
		$request->set_query_params( array( $key => $query_value ) );
		$request->set_json_params( array( $key => null ) );
		return $request;
	}

	// ── (a) the issue's example ────────────────────────────────────────────

	public function test_a_json_null_cannot_hide_query_string_seo_bulk_ids_from_the_per_post_check(): void {
		$updates = array( array( 'post_id' => (string) self::ADMIN_POST, 'title' => 'Pwned' ) );
		$request = self::split_request( 'POST', '/mcpwp/v1/seo/bulk', $this->author_token(), 'updates', $updates );

		// What the handler acts on: get_param() skips the JSON null.
		$this->assertSame( $updates, $request->get_param( 'updates' ) );
		$this->assertNull( $request->get_params()['updates'], 'get_params() shows the null that used to blind the gate.' );

		$result = ( new Mcpwp_Gate_1110_Seo_Decided( null ) )->check_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $result, 'The gate must see the query-string id the handler would write to.' );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );

		// Control: the same id sent plainly is refused the same way, and an
		// id the Author may edit passes.
		$plain = new WP_REST_Request( 'POST', '/mcpwp/v1/seo/bulk', array( 'updates' => $updates ), $this->author_token() );
		$this->assertSame( 'rest_cannot_edit', ( new Mcpwp_Gate_1110_Seo_Decided( null ) )->check_permission( $plain )->get_error_code() );
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['edit_post'] = array( self::ADMIN_POST => true );
		$this->assertTrue( ( new Mcpwp_Gate_1110_Seo_Decided( null ) )->check_permission( self::split_request( 'POST', '/mcpwp/v1/seo/bulk', $this->author_token(), 'updates', $updates ) ) );
	}

	/** The same differential on the publish-status scope rule (request_targets_publish_status()). */
	public function test_a_json_null_cannot_hide_a_query_string_publish_status(): void {
		$created = ( new Mcpwp_Api_Auth_Test_Harness() )->create_scoped_api_key( 'bot', array( 'read', 'write' ), 'custom', array( 'content' ) );
		$request = self::split_request( 'POST', '/mcpwp/v1/posts', array( 'X-API-Key' => $created['key'] ), 'status', 'publish' );
		$request->set_body_params( array( 'title' => 'x' ) );
		$this->assertSame( 'publish', $request->get_param( 'status' ) );

		$result = ( new Mcpwp_REST_Posts() )->check_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	/** The bulk-item shape check (check_bulk_items_are_objects()) reads the list the handler reads. */
	public function test_a_json_null_cannot_hide_query_string_string_items(): void {
		$created = ( new Mcpwp_Api_Auth_Test_Harness() )->create_scoped_api_key( 'bot', array( 'read', 'write' ), 'custom', array( 'content' ) );
		$request = self::split_request( 'PUT', '/mcpwp/v1/posts/bulk', array( 'X-API-Key' => $created['key'] ), 'updates', array( '{"id":' . self::ADMIN_POST . ',"title":"x"}' ) );
		$result  = ( new Mcpwp_REST_Posts() )->check_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_item', $result->get_error_code() );
	}

	/** The wide find/replace scope rule (find_replace_is_wide()) reads the scope the handler reads. */
	public function test_a_json_null_cannot_hide_a_query_string_site_wide_find_replace(): void {
		$created = ( new Mcpwp_Api_Auth_Test_Harness() )->create_scoped_api_key( 'bot', array( 'read', 'write' ), 'custom', array( 'elementor', 'content' ) );
		$request = self::split_request( 'POST', '/mcpwp/v1/elementor/find-replace', array( 'X-API-Key' => $created['key'] ), 'scope', 'site' );
		$request->set_body_params( array( 'find' => 'a', 'replace' => 'b' ) );
		$this->assertSame( 'site', $request->get_param( 'scope' ) );

		$result = ( new Mcpwp_REST_Elementor() )->check_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'insufficient_scope', $result->get_error_code() );
	}

	// ── (b) the request double models core's buckets ─────────────────────

	public function test_get_param_skips_a_null_and_falls_through_to_the_next_bucket(): void {
		$request = new WP_REST_Request( 'POST', '/r', array( 'a' => 'body' ) );
		$request->set_query_params( array( 'a' => 'query', 'b' => 'query' ) );
		$request->set_url_params( array( 'c' => 'url' ) );
		$request->set_default_params( array( 'd' => 'default' ) );
		$request->set_json_params( array( 'a' => null, 'b' => null ) );

		$this->assertSame( array( 'JSON', 'POST', 'GET', 'URL', 'defaults' ), $request->get_parameter_order() );
		$this->assertSame( 'body', $request->get_param( 'a' ), 'JSON null -> body.' );
		$this->assertSame( 'query', $request->get_param( 'b' ), 'JSON null, no body -> query.' );
		$this->assertSame( 'url', $request->get_param( 'c' ) );
		$this->assertSame( 'default', $request->get_param( 'd' ) );
		$this->assertNull( $request->get_param( 'missing' ) );
		$this->assertTrue( $request->has_param( 'a' ) );
	}

	public function test_get_params_merges_lowest_priority_first_so_a_null_wins(): void {
		$request = new WP_REST_Request( 'POST', '/r', array( 'a' => 'body', 'e' => 'body' ) );
		$request->set_query_params( array( 'a' => 'query', 'b' => 'query', 'e' => 'query' ) );
		$request->set_default_params( array( 'b' => 'default', 'f' => 'default' ) );
		$request->set_json_params( array( 'a' => null, 'b' => null ) );

		$this->assertSame(
			array( 'b' => null, 'f' => 'default', 'a' => null, 'e' => 'body' ),
			$request->get_params()
		);
	}

	public function test_a_get_request_has_no_body_bucket_and_no_json_without_a_json_content_type(): void {
		$get = new WP_REST_Request( 'GET', '/r', array( 'q' => 'query' ) );
		$this->assertSame( array( 'GET', 'URL', 'defaults' ), $get->get_parameter_order() );
		$this->assertSame( array( 'q' => 'query' ), $get->get_query_params(), 'A GET constructor\'s params are its query string.' );

		$post = new WP_REST_Request( 'POST', '/r', array(), array( 'Content-Type' => 'application/json' ) );
		$this->assertSame( 'JSON', $post->get_parameter_order()[0] );
		$plain = new WP_REST_Request( 'PUT', '/r' );
		$this->assertSame( array( 'POST', 'GET', 'URL', 'defaults' ), $plain->get_parameter_order() );
	}

	public function test_setters_replace_their_bucket_and_set_param_writes_where_the_key_lives(): void {
		$request = new WP_REST_Request( 'POST', '/r', array( 'a' => 1 ) );
		$request->set_body_params( array( 'b' => 2 ) );
		$this->assertSame( array( 'b' => 2 ), $request->get_body_params(), 'Core replaces, it does not merge.' );

		$request->set_query_params( array( 'q' => 'x' ) );
		$request->set_param( 'q', 'y' );
		$this->assertSame( array( 'q' => 'y' ), $request->get_query_params() );
		$request->set_param( 'new', 'z' );
		$this->assertSame( 'z', $request->get_body_params()['new'], 'A new key goes to the first bucket in the order.' );
	}

	// ── (c) the shape check on the AJAX and replay legs ──────────────────

	private function enter_trusted_admin_ajax(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		$GLOBALS['mcpwp_test_is_admin']           = true;
		$GLOBALS['mcpwp_test_ajax_referer_valid'] = true;
		$GLOBALS['mcpwp_test_current_user']       = self::ADMIN;
		$_POST['action']                          = 'mcpwp_chat_execute_tool';
	}

	private function assert_invalid_item( $result, string $context ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'invalid_item', $result->get_error_code(), $context );
		$this->assertSame( 400, $result->get_error_data()['status'], $context );
	}

	public function test_a_string_item_is_refused_on_the_admin_ajax_leg(): void {
		$this->enter_trusted_admin_ajax();
		$this->assert_invalid_item(
			( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/posts/bulk', array( 'posts' => array( '{"title":"x"}' ) ) ) ),
			'chat tab'
		);
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/posts/bulk', array( 'posts' => array( array( 'title' => 'x' ) ) ) ) ), 'Array items pass.' );
	}

	public function test_a_string_item_is_refused_on_the_destructive_replay_leg(): void {
		$ref = new ReflectionProperty( 'Mcpwp_Destructive_Gate', 'applying_as_admin' );
		$ref->setAccessible( true );
		$ref->setValue( null, self::ADMIN );
		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;
		$GLOBALS['mcpwp_test_post_caps'][ self::ADMIN ] = array( 'read_post' => array( self::ADMIN_POST => true ), 'edit_post' => array( self::ADMIN_POST => true ) );

		$this->assert_invalid_item(
			( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk', array( 'updates' => array( '{"id":' . self::ADMIN_POST . ',"title":"x"}' ) ) ) ),
			'replay'
		);
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk', array( 'updates' => array( array( 'id' => self::ADMIN_POST, 'title' => 'x' ) ) ) ) ), 'Array items pass.' );
	}
}
