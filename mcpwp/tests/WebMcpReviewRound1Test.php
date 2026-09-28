<?php
/**
 * #981 adversarial review round 1 — WordPress's own per-user rules under
 * the WebMCP principal.
 *
 *  BLOCK-1 non-public reads follow read_post per post; lists clamp to
 *          published; listing a type needs its edit_posts cap.
 *  BLOCK-2 gates run on the dispatcher's CANONICAL arguments (postType →
 *          post_type), proven through the real /mcp tools/call path.
 *  WARN-1  terms need assign_terms (edit_terms for a new tag); meta keys
 *          need edit_post_meta (protected / registered auth_callback).
 *  WARN-2  post_content_update approvals: per-requester (20) and site (100)
 *          pending caps and a 256 KB body cap, for every requester.
 *  WARN-3  rendered-html/-copy fetch only this site's own origin.
 *  NITs    propose respects the category limit; request_id forwarded;
 *          endpoint refusals metered before logging; onboard suggestions
 *          filtered to the WebMCP allowlist.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-core.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';

/** Exposes the per-post and list visibility gates. */
class Mcpwp_WebMcp_R1_Harness extends Mcpwp_REST_API {
	public function register_routes() {}

	public function can_see_non_public() {
		return $this->requester_can_see_non_public();
	}

	public function can_read( $post ) {
		return $this->requester_can_read_post( $post );
	}

	public function guard( $post ) {
		return $this->guard_non_public_post( $post, 'Not found.' );
	}

	public function hidden( $id, $row ) {
		return $this->post_ref_is_hidden( $id, $row, 'content' );
	}

	public function list_args( array $args ) {
		return $this->sanitize_query_args( $args );
	}

	public function use_record( $record ) {
		$this->current_api_key_record = $record;
	}
}

final class WebMcpReviewRound1Test extends TestCase {

	private const EDITOR  = 5;
	private const PRIVATE_SECRET = 9820;
	private const PUBLIC_POST    = 9821;
	private const OWN_DRAFT      = 9822;
	private const BLOCK          = 9823;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		$GLOBALS['mcpwp_test_wpdb_rows']           = array();
		$GLOBALS['mcpwp_test_public_post_types']   = array( 'secretdoc' );
		// A CPT with its own capability_type: the editor role has none of its caps.
		$GLOBALS['mcpwp_test_post_type_caps']      = array(
			'secretdoc' => array( 'edit_posts' => 'edit_secretdocs', 'create_posts' => 'edit_secretdocs' ),
		);
		$GLOBALS['_mcpwp_test_taxonomies']         = array(
			'category'       => array( 'object_type' => array( 'post' ) ),
			'post_tag'       => array( 'object_type' => array( 'post' ) ),
			'restricted_tax' => array( 'object_type' => array( 'post' ), 'cap' => array( 'assign_terms' => 'manage_options' ) ),
		);
		$GLOBALS['_mcpwp_test_existing_terms']     = array( 'post_tag' => array( 'news' ) );
		$GLOBALS['mcpwp_test_user_caps']           = array(
			// Editor-like custom role: edit_others_posts, but no read_private_*
			// for secretdoc, no manage_options, no manage_categories.
			self::EDITOR => array(
				'edit_posts'        => true,
				'edit_pages'        => true,
				'edit_others_posts' => true,
				'edit_post'         => true,
				'read_post'         => false,
				'unfiltered_html'   => true,
			),
		);
		$GLOBALS['mcpwp_test_current_user']        = self::EDITOR;
		$GLOBALS['_mcpwp_test_posts']              = array(
			self::PRIVATE_SECRET => new WP_Post( array( 'ID' => self::PRIVATE_SECRET, 'post_type' => 'secretdoc', 'post_status' => 'private', 'post_title' => 'Secret', 'post_content' => 'secret body' ) ),
			self::PUBLIC_POST    => new WP_Post( array( 'ID' => self::PUBLIC_POST, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Public', 'post_content' => 'public body' ) ),
			self::OWN_DRAFT      => new WP_Post( array( 'ID' => self::OWN_DRAFT, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Mine', 'post_content' => 'draft body' ) ),
			self::BLOCK          => new WP_Post( array( 'ID' => self::BLOCK, 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Reusable', 'post_content' => 'block' ) ),
		);
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );
		Mcpwp_REST_MCP::reset_verdict_memo();
		Mcpwp_WebMCP::end_dispatch( 0 );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
		Mcpwp_Integration::clear_cache();
		add_filter(
			'mcpwp_integrations',
			static function ( $integrations ) {
				$integrations[] = new Mcpwp_WebMCP_Integration();
				return $integrations;
			}
		);
		$this->reset_rate_limiter();
	}

	protected function tearDown(): void {
		Mcpwp_WebMCP::end_dispatch( 0 );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
		Mcpwp_Integration::clear_cache();
		foreach ( array( 'mcpwp_test_public_post_types', 'mcpwp_test_post_type_caps', '_mcpwp_test_taxonomies', '_mcpwp_test_existing_terms', 'mcpwp_test_rest_response_override', 'wp_rest_auth_cookie' ) as $g ) {
			unset( $GLOBALS[ $g ] );
		}
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$this->reset_rate_limiter();
	}

	private function reset_rate_limiter(): void {
		$ref  = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	private function webmcp_harness(): Mcpwp_WebMcp_R1_Harness {
		$h = new Mcpwp_WebMcp_R1_Harness();
		$h->use_record( Mcpwp_WebMCP::principal_record( self::EDITOR ) );
		return $h;
	}

	/** Run one sub-request through the auth chokepoint under the lift. */
	private function sub_request_verdict( WP_REST_Request $request ) {
		Mcpwp_REST_MCP::reset_verdict_memo();
		$prev = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		try {
			return ( new Mcpwp_WebMcp_R1_Harness() )->verify_api_key( $request );
		} finally {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
	}

	private function grant( string $cap, bool $value = true ): void {
		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ][ $cap ] = $value;
	}

	// ── BLOCK-1 ────────────────────────────────────────────────────────────

	public function test_lists_clamp_to_published_for_a_webmcp_user(): void {
		$h = $this->webmcp_harness();
		$this->assertFalse( $h->can_see_non_public(), 'Write scope does not buy blanket non-public visibility.' );
		$this->assertSame( 'publish', $h->list_args( array( 'status' => 'any' ) )['post_status'] );

		// Unchanged for an API key with write scope.
		$key = new Mcpwp_WebMcp_R1_Harness();
		$key->use_record( array( 'id' => 'k1', 'scopes' => array( 'read', 'write' ), 'role' => 'admin' ) );
		$this->assertTrue( $key->can_see_non_public() );
	}

	public function test_single_reads_follow_read_post_per_post(): void {
		$h       = $this->webmcp_harness();
		$private = $GLOBALS['_mcpwp_test_posts'][ self::PRIVATE_SECRET ];
		$draft   = $GLOBALS['_mcpwp_test_posts'][ self::OWN_DRAFT ];

		// read_post denied (the private secretdoc the role cannot read).
		$this->assertFalse( $h->can_read( $private ) );
		$this->assertInstanceOf( WP_Error::class, $h->guard( $private ) );
		$this->assertSame( 404, $h->guard( $private )->get_error_data()['status'] );
		$this->assertTrue( $h->hidden( self::PRIVATE_SECRET, array( 'post_status' => 'private', 'post_type' => 'secretdoc' ) ) );

		// read_post granted (e.g. the user's own draft).
		$this->grant( 'read_post' );
		$this->assertTrue( $h->can_read( $draft ) );
		$this->assertNull( $h->guard( $draft ) );
		$this->assertFalse( $h->hidden( self::OWN_DRAFT, array( 'post_status' => 'draft', 'post_type' => 'post' ) ) );

		// An API key with write scope keeps its old behaviour.
		$key = new Mcpwp_WebMcp_R1_Harness();
		$key->use_record( array( 'id' => 'k1', 'scopes' => array( 'read', 'write' ), 'role' => 'admin' ) );
		$this->grant( 'read_post', false );
		$this->assertNull( $key->guard( $private ) );
	}

	public function test_listing_a_type_needs_its_edit_posts_cap(): void {
		$list = new WP_REST_Request( 'GET', '/mcpwp/v1/posts', array( 'post_type' => 'secretdoc', 'status' => 'any' ) );
		$verdict = $this->sub_request_verdict( $list );
		$this->assertSame( 'webmcp_post_type_forbidden', $verdict->get_error_code() );

		$search = new WP_REST_Request( 'GET', '/mcpwp/v1/search', array( 'query' => 'x', 'type' => 'secretdoc' ) );
		$this->assertSame( 'webmcp_post_type_forbidden', $this->sub_request_verdict( $search )->get_error_code() );

		$this->assertTrue( $this->sub_request_verdict( new WP_REST_Request( 'GET', '/mcpwp/v1/posts', array( 'post_type' => 'post' ) ) ) );
		$this->assertTrue( $this->sub_request_verdict( new WP_REST_Request( 'GET', '/mcpwp/v1/search', array( 'query' => 'x', 'type' => 'any' ) ) ) );

		$this->grant( 'edit_secretdocs' );
		$this->assertTrue( $this->sub_request_verdict( $list ) );

		// Creating: the type's create_posts cap (a page, for a role without edit_pages).
		$this->grant( 'edit_pages', false );
		$page = $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'post_type' => 'page' ) ) );
		$this->assertSame( 'webmcp_post_type_forbidden', $page->get_error_code() );
		$this->assertSame( 'webmcp_post_type_forbidden', $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/pages', array( 'title' => 'x', 'status' => 'draft' ) ) )->get_error_code() );
		$this->assertSame( 'webmcp_post_type_forbidden', $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'post_type' => array( 'post' ) ) ) )->get_error_code() );
	}

	// ── BLOCK-2 ────────────────────────────────────────────────────────────

	public function test_create_gates_run_on_canonical_arguments_through_the_real_dispatcher(): void {
		// The stubbed rest_do_request() does not run permission callbacks;
		// run the real one (verify_api_key under the lift) from the dispatch
		// filter, exactly where core would.
		$sub_post_type = array();
		add_filter(
			'rest_dispatch_request',
			static function ( $pre, $request ) use ( &$sub_post_type ) {
				$sub_post_type[] = $request->get_param( 'post_type' );
				$verdict = ( new Mcpwp_WebMcp_R1_Harness() )->verify_api_key( $request );
				if ( is_wp_error( $verdict ) ) {
					return $verdict;
				}
				return new WP_REST_Response( array( 'id' => 77, 'status' => 'draft' ), 201 );
			},
			10,
			2
		);

		foreach ( array( 'postType', 'Post-Type', 'POST_TYPE', 'post_type' ) as $spelling ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
			$GLOBALS['mcpwp_test_dispatched_requests'] = array();
			$body    = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => array( 'name' => 'wp_create_post', 'arguments' => array( 'title' => 'x', 'status' => 'draft', $spelling => 'secretdoc' ) ) );
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', $body, array(), $body );
			$mcp     = new Mcpwp_REST_MCP();
			$prev    = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
			try {
				$this->assertTrue( $mcp->verify_api_key( $request ) );
				$data = $mcp->handle_mcp( $request )->get_data();
			} finally {
				Mcpwp_WebMCP::end_dispatch( $prev );
			}

			$this->assertTrue( $data['result']['isError'] ?? false, $spelling . ': ' . wp_json_encode( $data ) );
			$this->assertStringContainsString( 'webmcp_post_type_forbidden', wp_json_encode( $data ), $spelling );
			$this->assertSame( 'secretdoc', end( $sub_post_type ), "$spelling was canonicalised before the gate ran" );
		}
	}

	public function test_sub_request_refuses_a_non_draft_status(): void {
		foreach ( array( 'publish', 'pending', 'private' ) as $status ) {
			$verdict = $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => $status ) ) );
			$this->assertInstanceOf( WP_Error::class, $verdict, $status );
		}
		$this->assertTrue( $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft' ) ) ) );
	}

	// ── WARN-1 ─────────────────────────────────────────────────────────────

	public function test_terms_need_assign_terms_and_new_tags_need_edit_terms(): void {
		$restricted = $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'terms' => array( 'restricted_tax' => array( 'a' ) ) ) ) );
		$this->assertSame( 'webmcp_terms_forbidden', $restricted->get_error_code() );

		$this->assertTrue( $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'categories' => array( 1 ), 'tags' => array( 'news' ) ) ) ) );

		$new_tag = $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'tags' => array( 'brand-new' ) ) ) );
		$this->assertSame( 'webmcp_terms_forbidden', $new_tag->get_error_code() );

		$this->grant( 'manage_categories' );
		$this->assertTrue( $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'tags' => array( 'brand-new' ) ) ) ) );
	}

	public function test_meta_follows_edit_post_meta_rules(): void {
		// register_meta( 'post', 'featured_flag', auth_callback => manage_options ).
		add_filter(
			'auth_post_meta_featured_flag_for_post',
			static function () {
				return current_user_can( 'manage_options' );
			},
			10,
			6
		);

		$flag = $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'meta' => array( 'mood' => 'ok', 'featured_flag' => '1' ) ) ) );
		$this->assertSame( 'webmcp_meta_forbidden', $flag->get_error_code() );

		$protected = $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'meta' => array( '_hidden' => '1' ) ) ) );
		$this->assertSame( 'webmcp_meta_forbidden', $protected->get_error_code() );

		$this->assertTrue( $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'meta' => array( 'mood' => 'ok' ) ) ) ) );

		$this->grant( 'manage_options' );
		$this->assertTrue( $this->sub_request_verdict( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft', 'meta' => array( 'featured_flag' => '1' ) ) ) ) );
	}

	// ── WARN-2 ─────────────────────────────────────────────────────────────

	public function test_content_approvals_are_bounded_for_every_requester(): void {
		$too_big = Mcpwp_Approvals::create_post_content_request( self::PUBLIC_POST, str_repeat( 'a', Mcpwp_Approvals::MAX_PROPOSED_CONTENT_BYTES + 1 ), array( 'requested_by_key_id' => 'k1' ) );
		$this->assertSame( 'content_too_large', $too_big->get_error_code() );
		$this->assertSame( 413, $too_big->get_error_data()['status'] );

		for ( $i = 0; $i < Mcpwp_Approvals::MAX_PENDING_CONTENT_PER_REQUESTER; $i++ ) {
			$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::PUBLIC_POST, "v$i", array( 'requested_by_key_id' => 'k1' ) ) );
		}
		$mine = Mcpwp_Approvals::create_post_content_request( self::PUBLIC_POST, 'one more', array( 'requested_by_key_id' => 'k1' ) );
		$this->assertSame( 'too_many_pending', $mine->get_error_code() );
		$this->assertSame( 429, $mine->get_error_data()['status'] );

		// Another requester still can, until the site-wide cap.
		$requester = 0;
		$count     = Mcpwp_Approvals::MAX_PENDING_CONTENT_PER_REQUESTER;
		while ( $count < Mcpwp_Approvals::MAX_PENDING_CONTENT_TOTAL ) {
			$by = 'k-other-' . intdiv( $requester++, 10 );
			$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::PUBLIC_POST, "w$count", array( 'requested_by_key_id' => $by ) ) );
			$count++;
		}
		$full = Mcpwp_Approvals::create_post_content_request( self::PUBLIC_POST, 'overflow', array( 'requested_by_key_id' => 'fresh' ) );
		$this->assertSame( 'approval_queue_full', $full->get_error_code() );
	}

	// ── WARN-3 ─────────────────────────────────────────────────────────────

	public function test_fetches_only_this_sites_origin(): void {
		foreach ( array( 'https://example.com/', 'https://example.com/about/?x=1', 'https://EXAMPLE.com:443/p' ) as $ok ) {
			$this->assertTrue( Mcpwp_REST_Site_Content::is_same_site_origin( $ok ), $ok );
		}
		foreach ( array( 'https://example.com:3306/', 'http://example.com/', 'https://example.com:80/wp-login.php', 'https://user:pw@example.com/', 'https://evil.example/', 'file:///etc/passwd', 'example.com/x', '' ) as $bad ) {
			$this->assertFalse( Mcpwp_REST_Site_Content::is_same_site_origin( $bad ), $bad );
		}

		$controller = new Mcpwp_REST_Site_Content();
		$result     = $controller->get_rendered_html( new WP_REST_Request( 'GET', '/mcpwp/v1/rendered-html', array( 'url' => 'https://example.com:3306/' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );

		$copy = $controller->get_rendered_copy( new WP_REST_Request( 'GET', '/mcpwp/v1/rendered-copy', array( 'url' => 'https://example.com:8080/' ) ) );
		$this->assertSame( 'ssrf_blocked', $copy->get_error_code() );
	}

	// ── NITs ───────────────────────────────────────────────────────────────

	public function test_propose_respects_the_principal_category_limit(): void {
		$prev = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		try {
			$block = Mcpwp_WebMCP::propose_content_change( array( 'post_id' => self::BLOCK, 'content' => 'x' ) );
			$post  = Mcpwp_WebMCP::propose_content_change( array( 'post_id' => self::PUBLIC_POST, 'content' => 'x' ) );
		} finally {
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
		$this->assertSame( 'not_found', $block->get_error_code(), 'wp_block is gutenberg, not WebMCP\'s.' );
		$this->assertIsArray( $post );
	}

	private function endpoint_request( array $body ): WP_REST_Request {
		return new WP_REST_Request( 'POST', '/mcpwp/v1/webmcp/call', $body, array( 'Origin' => 'https://example.com', 'X-WP-Nonce' => 'test-nonce' ), $body );
	}

	public function test_request_id_is_forwarded_for_idempotency(): void {
		update_option( 'mcpwp_settings', array( 'webmcp_admin_enabled' => true ) );
		$seen = array();
		add_filter(
			'rest_dispatch_request',
			static function ( $pre, $request ) use ( &$seen ) {
				$seen[] = $request->get_json_params();
				return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => 1, 'result' => array( 'content' => array(), 'isError' => false ) ), 200 );
			},
			10,
			2
		);

		( new Mcpwp_REST_WebMCP() )->handle_call( $this->endpoint_request( array( 'name' => 'wp_create_post', 'arguments' => array( 'title' => 'x' ), 'request_id' => 'agent:job:1' ) ) );
		$this->assertSame( 'agent:job:1', $seen[0]['params']['_meta']['request_id'] );
		$this->assertTrue( Mcpwp_WebMCP::mcp_body_is_allowed( $seen[0] ) );

		( new Mcpwp_REST_WebMCP() )->handle_call( $this->endpoint_request( array( 'name' => 'wp_list_posts' ) ) );
		$this->assertArrayNotHasKey( '_meta', $seen[1]['params'] );
	}

	public function test_endpoint_refusals_are_metered_before_they_are_logged(): void {
		update_option( 'mcpwp_settings', array( 'webmcp_admin_enabled' => true, 'enable_logging' => true ) );
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'enabled' => true, 'requests_per_minute' => 100, 'requests_per_hour' => 1000, 'burst_limit' => 2, 'whitelist' => array() ) );

		$codes = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$codes[] = ( new Mcpwp_REST_WebMCP() )->handle_call( $this->endpoint_request( array( 'name' => 'wp_delete_page', 'arguments' => array( 'id' => 1 ) ) ) )->get_error_code();
		}
		$this->assertSame( array( 'webmcp_tool_not_allowed', 'webmcp_tool_not_allowed' ), array_slice( $codes, 0, 2 ) );
		$this->assertNotSame( 'webmcp_tool_not_allowed', $codes[2] );
		$this->assertSame( 429, ( new Mcpwp_REST_WebMCP() )->handle_call( $this->endpoint_request( array( 'name' => 'wp_delete_page' ) ) )->get_error_data()['status'] );

		$logged = 0;
		foreach ( $GLOBALS['mcpwp_test_wpdb_rows'] as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( is_array( $row ) && isset( $row['action'] ) && 'webmcp_tool_call' === $row['action'] ) {
					$logged++;
				}
			}
		}
		$this->assertSame( 2, $logged, 'Only the refusals inside the budget were written to the activity log.' );
	}

	public function test_onboard_suggestions_are_filtered_to_the_allowlist_for_webmcp(): void {
		$info = new Mcpwp_REST_Site_Info();
		$prop = new ReflectionProperty( $info, 'current_api_key_record' );
		$prop->setAccessible( true );
		$prop->setValue( $info, Mcpwp_WebMCP::principal_record( self::EDITOR ) );

		$filter = new ReflectionMethod( $info, 'filter_actions_by_caller_categories' );
		$filter->setAccessible( true );
		$out = $filter->invoke(
			$info,
			array(
				array( 'tool' => 'wp_set_site_context' ),
				array( 'tool' => 'wp_create_page' ),
				array( 'tool' => 'wp_elementor_status' ),
				array( 'tool' => 'wp_list_posts' ),
				array( 'tool' => '' ),
			)
		);
		$this->assertSame( array( 'wp_create_page', 'wp_list_posts' ), array_column( $out, 'tool' ) );
	}
}
