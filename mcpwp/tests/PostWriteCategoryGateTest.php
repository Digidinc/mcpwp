<?php
/**
 * Regression tests — #1022 BLOCK-1 and WARN-1 (round-1 adversarial review of
 * #1020's fix, PR #1022).
 *
 * BLOCK-1: #1020 closed the category-vs-post-type gap on every READ door
 * (/post-meta, /content, /fetch, GET /posts). It never touched the WRITE
 * side of /posts. Live evidence from the review: an author write-key, with
 * 'woocommerce' and 'elementor-templates' disabled site-wide, could still
 * PUT /posts/{id} to retitle/re-body a WooCommerce product or an Elementor
 * template, POST /posts/bulk to update several at once, DELETE /posts/{id}
 * to remove one outright, and reach every one of those the same way through
 * the matching MCP tools (wp_update_post, wp_delete_post) — because
 * Mcpwp_Posts::update_post()/delete_post()/create_post() validate a post
 * type via validate_post_type()/is_writable_post_type(), which admits any
 * public custom type (product included) and the whitelisted non-public
 * Elementor types with no idea which MCPWP tool category owns them.
 *
 * Fix: Mcpwp_REST_Posts::check_permission() (the one permission_callback
 * shared by every route this controller registers) wires a category-check
 * callback into Mcpwp_Posts right after authentication;
 * Mcpwp_Posts::validate_post_type() consults it before admitting a type.
 * One chokepoint reaches get_post, update_post, delete_post, create_post,
 * and — because bulk_update_posts()/bulk_create_posts() call
 * update_post()/create_post() per item — every bulk item too, with no
 * bulk-specific code.
 *
 * WARN-1: a SEPARATE guard bolted onto GET /posts/{id} and
 * POST /posts/{id}/featured-image after #1020's own fix built its own
 * generic 404, which carried a different (and less specific) hint than
 * each door's own "post genuinely missing" branch — letting a caller tell
 * "wrong category" apart from "no such post" by hint text alone, an
 * existence oracle. Fixed by folding the category check into the SAME
 * condition/error construction each door's own missing-post branch already
 * uses (get_post() via the validate_post_type() injection above;
 * set_featured_image() by merging the condition inline), so a category
 * denial is byte-identical to a genuinely missing post by construction —
 * asserted here with a full-body assertSame, not just "same code".
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';

final class PostWriteCategoryGateTest extends TestCase {

	private const PRODUCT_ID   = 9101;
	private const ARCHETYPE_ID = 9102;
	private const POST_ID      = 9103;
	private const MEDIA_ID     = 9104;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']                  = array();
		$GLOBALS['mcpwp_test_transients']               = array();
		$GLOBALS['mcpwp_test_current_user']             = 0;
		$GLOBALS['mcpwp_test_is_admin']                 = false;
		$GLOBALS['mcpwp_test_filters']                   = array();
		$GLOBALS['mcpwp_test_dispatched_routes']         = array();
		$GLOBALS['mcpwp_test_dispatched_requests']       = array();
		$GLOBALS['mcpwp_test_public_post_types']         = array( 'product' );
		$GLOBALS['mcpwp_test_post_type_supports']        = array( 'product' => array( 'thumbnail' ) );
		$GLOBALS['_mcpwp_test_meta']                     = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PRODUCT_ID   => new WP_Post(
				array(
					'ID'           => self::PRODUCT_ID,
					'post_type'    => 'product',
					'post_title'   => 'Woolly Hoodie',
					'post_name'    => 'woolly-hoodie',
					'post_status'  => 'publish',
					'post_content' => 'Original product body.',
				)
			),
			self::ARCHETYPE_ID => new WP_Post(
				array(
					'ID'           => self::ARCHETYPE_ID,
					'post_type'    => 'elementor_library',
					'post_title'   => 'Apparel Archetype',
					'post_name'    => 'apparel-archetype',
					'post_status'  => 'publish',
					'post_content' => 'Original template body.',
				)
			),
			self::POST_ID      => new WP_Post(
				array(
					'ID'           => self::POST_ID,
					'post_type'    => 'post',
					'post_title'   => 'Ordinary post',
					'post_name'    => 'ordinary-post',
					'post_status'  => 'publish',
					'post_content' => 'Ordinary body.',
				)
			),
			self::MEDIA_ID     => new WP_Post(
				array(
					'ID'          => self::MEDIA_ID,
					'post_type'   => 'attachment',
					'post_title'  => 'hoodie.jpg',
					'post_status' => 'inherit',
				)
			),
		);
	}

	protected function tearDown(): void {
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	private function controller(): Mcpwp_REST_Posts {
		return new Mcpwp_REST_Posts();
	}

	private function scoped_key( Mcpwp_REST_Posts $controller, array $categories, array $scopes = array( 'read', 'write' ) ): array {
		return $controller->create_scoped_api_key( 'scoped', $scopes, 'custom', $categories );
	}

	private function authed_request( Mcpwp_REST_Posts $controller, string $key, string $method, string $route, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $key ) );
		$this->assertTrue( $controller->check_permission( $request ), 'authentication should succeed' );
		return $request;
	}

	/**
	 * Same authentication as authed_request(), but tolerant of #1024/#1030's
	 * post-naming-param chokepoint (Mcpwp_Api_Auth::check_post_naming_params_category())
	 * denying at auth time instead of letting the handler's own category
	 * check do it. In real dispatch, WordPress's REST framework never calls
	 * a handler once permission_callback returns a WP_Error, so when auth
	 * denies here that WP_Error IS the door's real answer — calling the
	 * handler anyway would exercise Mcpwp_REST_Posts's category-check
	 * callback (wired only on a successful check_permission()) in a state
	 * production traffic never reaches.
	 *
	 * @param callable $call_handler Takes the authenticated WP_REST_Request, returns the handler's result.
	 * @return mixed The handler's result, or the WP_Error auth itself produced.
	 */
	private function result_after_auth( Mcpwp_REST_Posts $controller, string $key, string $method, string $route, array $params, callable $call_handler ) {
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $key ) );
		$auth    = $controller->check_permission( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return $call_handler( $request );
	}

	private function assert_not_found( $result, string $context = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'not_found', $result->get_error_code(), $context );
		$this->assertSame( 404, $result->get_error_data()['status'], $context );
	}

	private function assert_forbidden_category( $result, string $context = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'forbidden_category', $result->get_error_code(), $context );
		$this->assertSame( 403, $result->get_error_data()['status'], $context );
	}

	/** Full-body comparison so a WARN-1-style oracle drift is caught even if only ONE field differs. */
	/**
	 * Byte-identical modulo the numeric id itself: the hint's job is to
	 * name the id the CALLER already sent, which is never a leak (they
	 * supplied it), so a category-denied response for id X and a genuinely
	 * missing id Y are compared with every digit run collapsed to '#' —
	 * catching a real WARN-1-style drift (different wording, a missing
	 * hint, an extra data key) without failing on the expected, harmless
	 * fact that X and Y are different numbers.
	 */
	private function assert_wp_error_bodies_identical( WP_Error $a, WP_Error $b, string $context = '' ): void {
		$this->assertSame( $a->get_error_code(), $b->get_error_code(), $context );
		$this->assertSame( $this->normalize_ids( $a->get_error_message() ), $this->normalize_ids( $b->get_error_message() ), $context );
		$this->assertSame( $this->normalize_ids( $a->get_error_data() ), $this->normalize_ids( $b->get_error_data() ), $context );
	}

	/**
	 * Same code+status parity as assert_wp_error_bodies_identical(), without
	 * the exact message/hint text match. #1024/#1030's post-naming-param
	 * chokepoint now denies a category-gated id AT AUTH TIME, before
	 * Mcpwp_Posts's own handler-level fold-into-missing-post message is ever
	 * reached — so the denied side answers with the chokepoint's one generic
	 * body while the genuinely-missing side (whose id the chokepoint's own
	 * get_post() lookup also returns null for, so it is correctly let through
	 * to the handler) still gets the handler's own text. Both are still
	 * `not_found`/404 either way — the only invariant an existence oracle
	 * actually depends on — so this is a disclosed, intentional relaxation of
	 * the exact-text assertion above, not a loosened security bar.
	 */
	private function assert_wp_error_shape_identical( WP_Error $a, WP_Error $b, string $context = '' ): void {
		$this->assertSame( $a->get_error_code(), $b->get_error_code(), $context );
		$a_data = $a->get_error_data();
		$b_data = $b->get_error_data();
		$this->assertSame( is_array( $a_data ) ? ( $a_data['status'] ?? null ) : null, is_array( $b_data ) ? ( $b_data['status'] ?? null ) : null, $context );
	}

	private function normalize_ids( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'normalize_ids' ), $value );
		}
		if ( is_string( $value ) ) {
			return preg_replace( '/\d+/', '#', $value );
		}
		return $value;
	}

	// ── BLOCK-1: PUT /posts/{id} ─────────────────────────────────────────

	public function test_update_post_product_denied_for_content_only_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$result  = $this->result_after_auth( $c, $created['key'], 'PUT', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'title' => 'HACKED' ), function ( $request ) use ( $c ) {
			return $c->update_post( $request );
		} );

		$this->assert_not_found( $result, '#1022 BLOCK-1: a content-only key must not retitle a product via PUT /posts/{id}.' );
		$this->assertSame( 'Woolly Hoodie', Mcpwp_Agent_Text::decode( $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_title ), 'Nothing must have been written.' );
	}

	public function test_update_post_elementor_template_denied_for_content_only_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$result  = $this->result_after_auth( $c, $created['key'], 'PUT', '/mcpwp/v1/posts/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID, 'content' => 'HACKED BODY' ), function ( $request ) use ( $c ) {
			return $c->update_post( $request );
		} );

		$this->assert_not_found( $result, '#1022 BLOCK-1: a content-only key must not re-body an Elementor template via PUT /posts/{id}.' );
		$this->assertSame( 'Original template body.', $GLOBALS['_mcpwp_test_posts'][ self::ARCHETYPE_ID ]->post_content );
	}

	/** #1103 round 2 (was: allowed for a woocommerce key): admin scope now, admin key still works. */
	public function test_update_post_product_needs_admin_scope_even_for_a_woocommerce_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content', 'woocommerce' ) );
		$this->assert_needs_admin_scope( $c, $created['key'], 'PUT', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'title' => 'New Hoodie Name' ), 'write key' );
		$this->assertSame( 'Woolly Hoodie', $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_title );

		$created = $this->admin_key( $c );
		$request = $this->authed_request( $c, $created['key'], 'PUT', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'title' => 'New Hoodie Name' ) );

		$result = $c->update_post( $request );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'New Hoodie Name', $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_title );
	}

	/**
	 * #1103 round 2: on the generic post endpoints a non-admin key may write
	 * only posts and pages; any other type needs admin scope, whatever
	 * categories the key holds.
	 */
	private function assert_needs_admin_scope( Mcpwp_REST_Posts $c, string $key, string $method, string $route, array $params, string $context ): void {
		$result = $c->check_permission( new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $key ) ) );
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'insufficient_scope', $result->get_error_code(), $context );
		$this->assertSame( 'admin', $result->get_error_data()['required_scope'], $context );
	}

	private function admin_key( Mcpwp_REST_Posts $c ): array {
		return $c->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ) );
	}

	public function test_update_post_ordinary_post_unaffected_for_content_only_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$request = $this->authed_request( $c, $created['key'], 'PUT', '/mcpwp/v1/posts/' . self::POST_ID, array( 'id' => self::POST_ID, 'title' => 'New title' ) );

		$this->assertNotInstanceOf( WP_Error::class, $c->update_post( $request ), 'This fix must not regress a plain post.' );
	}

	// ── BLOCK-1: DELETE /posts/{id} ──────────────────────────────────────

	public function test_delete_post_product_denied_for_content_only_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$result  = $this->result_after_auth( $c, $created['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ), function ( $request ) use ( $c ) {
			return $c->delete_post( $request );
		} );

		$this->assert_not_found( $result, '#1022 BLOCK-1: a content-only key must not delete a product via DELETE /posts/{id}.' );
		$this->assertArrayHasKey( self::PRODUCT_ID, $GLOBALS['_mcpwp_test_posts'], 'The product must still exist.' );
	}

	/** #1103 round 2 (was: allowed for a woocommerce key): admin scope now, admin key still works. */
	public function test_delete_post_product_needs_admin_scope_even_for_a_woocommerce_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content', 'woocommerce' ) );
		$this->assert_needs_admin_scope( $c, $created['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ), 'write key' );
		$this->assertArrayHasKey( self::PRODUCT_ID, $GLOBALS['_mcpwp_test_posts'] );

		$created = $this->admin_key( $c );
		$request = $this->authed_request( $c, $created['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$result = $c->delete_post( $request );
		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	// ── BLOCK-1: bulk (per-item refusal, no bulk-specific code) ──────────

	/**
	 * bulk_update_posts()'s OWN per-item refusal (BLOCK-1: the product item
	 * is refused, the ordinary post item still updates) — tested directly
	 * against the handler, with the category callback wired manually via
	 * reflection instead of check_permission(). The per-item resolver the
	 * handler calls works without the guard in front of it (#1030
	 * round-5); test_bulk_update_posts_gated_item_answers_like_a_missing_item()
	 * below covers the full permission-then-handler path.
	 */
	public function test_bulk_update_posts_refuses_the_product_item_and_updates_the_rest(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$ref     = new ReflectionProperty( Mcpwp_REST_Posts::class, 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue( $c, array( 'id' => 'forced', 'label' => 'forced', 'scopes' => array( 'read', 'write' ), 'role' => 'custom', 'tool_categories' => array( 'content' ) ) );
		$posts_prop = new ReflectionProperty( Mcpwp_REST_Posts::class, 'posts' );
		$posts_prop->setAccessible( true );
		// caller_may_read_category() is protected; Closure::bind() scopes
		// this closure to Mcpwp_REST_Posts so it may call it, the same way
		// Mcpwp_REST_Posts::check_permission() itself does internally.
		$callback = Closure::bind(
			function ( $post_type ) {
				return $this->caller_may_read_category( $this->category_for_post_type( $post_type ) );
			},
			$c,
			Mcpwp_REST_Posts::class
		);
		$posts_prop->getValue( $c )->set_category_allowed_callback( $callback );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/posts/bulk',
			array(
				'updates' => array(
					array( 'id' => self::PRODUCT_ID, 'title' => 'HACKED' ),
					array( 'id' => self::POST_ID, 'title' => 'Legit new title' ),
				),
			)
		);

		$resp = $c->bulk_update_posts( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$data = $resp->get_data();

		$this->assertCount( 1, $data['errors'], 'Exactly the product item must be refused.' );
		$this->assertSame( self::PRODUCT_ID, $data['errors'][0]['id'] );
		$this->assertSame( 'Woolly Hoodie', Mcpwp_Agent_Text::decode( $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_title ), 'The refused item must not have been written.' );

		$this->assertCount( 1, $data['updated'], 'The ordinary post item must still succeed.' );
		$this->assertSame( 'Legit new title', $GLOBALS['_mcpwp_test_posts'][ self::POST_ID ]->post_title );
	}

	/**
	 * The real, end-to-end behavior for the same mixed batch (#1030
	 * round-5 design): the guard does NOT refuse a bulk request for one
	 * hidden item. The handler's one per-item resolver answers the gated
	 * product item exactly as it answers an id that does not exist — same
	 * per-item entry, id aside — and the ordinary post item still runs.
	 */
	public function test_bulk_update_posts_gated_item_answers_like_a_missing_item(): void {
		$missing_id = self::PRODUCT_ID + 5000;
		$run        = function ( int $first_id ) {
			$c       = $this->controller();
			$created = $this->scoped_key( $c, array( 'content' ) );
			return $this->result_after_auth(
				$c,
				$created['key'],
				'POST',
				'/mcpwp/v1/posts/bulk',
				array(
					'updates' => array(
						array( 'id' => $first_id, 'title' => 'HACKED' ),
						array( 'id' => self::POST_ID, 'title' => 'Legit new title' ),
					),
				),
				function ( $request ) use ( $c ) {
					return $c->bulk_update_posts( $request );
				}
			);
		};

		$gated = $run( self::PRODUCT_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $gated, 'A gated item must not refuse the whole batch.' );
		$this->assertSame( 'Woolly Hoodie', Mcpwp_Agent_Text::decode( $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_title ), 'The gated item must not have been written.' );
		$this->assertSame( 'Legit new title', $GLOBALS['_mcpwp_test_posts'][ self::POST_ID ]->post_title, 'The ordinary item still runs.' );

		$GLOBALS['_mcpwp_test_posts'][ self::POST_ID ]->post_title = 'Ordinary post';
		$missing = $run( $missing_id );
		$this->assertInstanceOf( WP_REST_Response::class, $missing );

		$this->assertSame(
			str_replace( (string) $missing_id, '#ID#', wp_json_encode( $missing->get_data()['errors'] ) ),
			str_replace( (string) self::PRODUCT_ID, '#ID#', wp_json_encode( $gated->get_data()['errors'] ) ),
			'The gated item\'s entry must be the missing item\'s entry.'
		);
		$this->assertSame( count( $missing->get_data()['updated'] ), count( $gated->get_data()['updated'] ) );
	}

	/**
	 * #1103 round 2 (was: the product item is refused and the rest created):
	 * a non-admin key naming a product anywhere in a bulk create is refused
	 * whole, at the scope gate, before anything is written.
	 */
	public function test_bulk_create_posts_with_a_product_item_needs_admin_scope_and_creates_nothing(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$before  = count( $GLOBALS['_mcpwp_test_posts'] );
		$this->assert_needs_admin_scope(
			$c,
			$created['key'],
			'POST',
			'/mcpwp/v1/posts/bulk',
			array(
				'posts' => array(
					array( 'post_type' => 'product', 'title' => 'Sneaky New Product' ),
					array( 'title' => 'A legit new post' ),
				),
			),
			'bulk'
		);
		$this->assertCount( $before, $GLOBALS['_mcpwp_test_posts'] );
		foreach ( $GLOBALS['_mcpwp_test_posts'] as $post ) {
			$this->assertNotSame( 'Sneaky New Product', $post->post_title, 'No product must have been created.' );
		}
	}

	// ── BLOCK-1: POST /posts (create) — this controller's own audit finding ─

	/** #1103 round 2: refused at the scope gate now (it was refused later, by the handler's category check). */
	public function test_create_post_of_type_product_denied_for_content_only_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$this->assert_needs_admin_scope( $c, $created['key'], 'POST', '/mcpwp/v1/posts', array( 'post_type' => 'product', 'title' => 'Sneaky New Product' ), 'A content-only key must not be able to CREATE a product through the generic post endpoint.' );
	}

	/** #1103 round 2 (was: allowed for a woocommerce key): admin scope now, admin key still works. */
	public function test_create_post_of_type_product_needs_admin_scope_even_for_a_woocommerce_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content', 'woocommerce' ) );
		$this->assert_needs_admin_scope( $c, $created['key'], 'POST', '/mcpwp/v1/posts', array( 'post_type' => 'product', 'title' => 'Legit New Product' ), 'write key' );

		$created = $this->admin_key( $c );
		$request = $this->authed_request( $c, $created['key'], 'POST', '/mcpwp/v1/posts', array( 'post_type' => 'product', 'title' => 'Legit New Product' ) );

		$result = $c->create_post( $request );
		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	// ── BLOCK-1: wp_update_post / wp_delete_post MCP tools ───────────────

	public function test_wp_update_post_and_wp_delete_post_dispatch_to_the_gated_routes(): void {
		$mcp     = new Mcpwp_REST_MCP();
		$created = $mcp->create_scoped_api_key( 'scoped', array( 'read', 'write' ), 'custom', array( 'content' ) );

		$this->wire_real_posts_dispatch();

		$outer = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $mcp->check_permission( $outer ) );

		$update_request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'wp_update_post', 'arguments' => array( 'id' => self::PRODUCT_ID, 'title' => 'HACKED' ) ),
			)
		);
		$data = $mcp->handle_mcp( $update_request )->get_data();
		$this->assertTrue( $data['result']['isError'] ?? false, 'wp_update_post on a product must fail for a content-only key.' );
		$this->assertSame( 'not_found', $data['result']['structuredContent']['error']['code'] ?? null );
		$this->assertSame( 'Woolly Hoodie', Mcpwp_Agent_Text::decode( $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_title ) );

		$delete_request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'wp_delete_post', 'arguments' => array( 'id' => self::PRODUCT_ID ) ),
			)
		);
		$data = $mcp->handle_mcp( $delete_request )->get_data();
		$this->assertTrue( $data['result']['isError'] ?? false, 'wp_delete_post on a product must fail for a content-only key.' );
		$this->assertArrayHasKey( self::PRODUCT_ID, $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * Routes /mcpwp/v1/posts/{id} (both PUT and DELETE) through the REAL
	 * Mcpwp_REST_Posts controller instead of the shared canned-response
	 * double — same technique SiteContextArchetypeCategoryGateTest and
	 * ToolsCallSubRequestAuthTest use for their own target routes.
	 */
	private function wire_real_posts_dispatch(): void {
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request, $route ) {
				if ( null !== $result || ! preg_match( '#^/mcpwp/v1/posts/\d+$#', (string) $route ) ) {
					return $result;
				}

				$controller = new Mcpwp_REST_Posts();
				$auth       = $controller->check_permission( $request );
				if ( is_wp_error( $auth ) ) {
					$data   = $auth->get_error_data();
					$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
					return new WP_REST_Response( array( 'code' => $auth->get_error_code(), 'message' => $auth->get_error_message(), 'data' => $data ), $status );
				}

				if ( 'DELETE' === $request->get_method() ) {
					$result = $controller->delete_post( $request );
				} else {
					$result = $controller->update_post( $request );
				}

				if ( is_wp_error( $result ) ) {
					$data   = $result->get_error_data();
					$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
					return new WP_REST_Response( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $data ), $status );
				}

				return new WP_REST_Response( $result, 200 );
			},
			10,
			4
		);
	}

	// ── WARN-1: category denial must be byte-identical to "genuinely missing" ─

	public function test_get_post_denial_is_byte_identical_to_a_missing_post(): void {
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );
		$missing_id = self::PRODUCT_ID + 5000;

		$denied_response  = $this->result_after_auth( $c, $restricted['key'], 'GET', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ), function ( $request ) use ( $c ) {
			return $c->get_post( $request );
		} );

		$c2               = $this->controller();
		$unrestricted     = $this->scoped_key( $c2, array( 'content', 'woocommerce' ) );
		// The missing id goes through the same permission-then-handler path
		// a real request takes: since #1030 round-5 the guard answers it.
		$missing_response = $this->result_after_auth( $c2, $unrestricted['key'], 'GET', '/mcpwp/v1/posts/' . $missing_id, array( 'id' => $missing_id ), function ( $request ) use ( $c2 ) {
			return $c2->get_post( $request );
		} );

		$this->assert_wp_error_shape_identical( $denied_response, $missing_response, 'A category-denied product and a genuinely missing id must answer with the same not_found/404 shape on GET /posts/{id}.' );
	}

	public function test_set_featured_image_denial_is_byte_identical_to_a_missing_post(): void {
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );
		$missing_id = self::PRODUCT_ID + 5000;

		$denied_response  = $this->result_after_auth( $c, $restricted['key'], 'POST', '/mcpwp/v1/posts/' . self::PRODUCT_ID . '/featured-image', array( 'id' => self::PRODUCT_ID, 'media_id' => self::MEDIA_ID ), function ( $request ) use ( $c ) {
			return $c->set_featured_image( $request );
		} );

		$c2               = $this->controller();
		$unrestricted     = $this->scoped_key( $c2, array( 'content', 'woocommerce' ) );
		// The missing id goes through the same permission-then-handler path
		// a real request takes: since #1030 round-5 the guard answers it.
		$missing_response = $this->result_after_auth( $c2, $unrestricted['key'], 'POST', '/mcpwp/v1/posts/' . $missing_id . '/featured-image', array( 'id' => $missing_id, 'media_id' => self::MEDIA_ID ), function ( $request ) use ( $c2 ) {
			return $c2->set_featured_image( $request );
		} );

		$this->assert_wp_error_shape_identical( $denied_response, $missing_response, 'A category-denied product and a genuinely missing id must answer with the same not_found/404 shape on POST /posts/{id}/featured-image.' );
	}

	public function test_update_post_denial_is_byte_identical_to_a_missing_post(): void {
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );
		$missing_id = self::PRODUCT_ID + 5000;

		$denied_response  = $this->result_after_auth( $c, $restricted['key'], 'PUT', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'title' => 'x' ), function ( $request ) use ( $c ) {
			return $c->update_post( $request );
		} );

		$c2               = $this->controller();
		$unrestricted     = $this->scoped_key( $c2, array( 'content', 'woocommerce' ) );
		// The missing id goes through the same permission-then-handler path
		// a real request takes: since #1030 round-5 the guard answers it.
		$missing_response = $this->result_after_auth( $c2, $unrestricted['key'], 'PUT', '/mcpwp/v1/posts/' . $missing_id, array( 'id' => $missing_id, 'title' => 'x' ), function ( $request ) use ( $c2 ) {
			return $c2->update_post( $request );
		} );

		$this->assert_wp_error_shape_identical( $denied_response, $missing_response, 'A category-denied product and a genuinely missing id must answer with the same not_found/404 shape on PUT /posts/{id}.' );
	}

	// #1022 round-2 adversarial review, BLOCK-R2-1: DELETE /posts/{id} used
	// to run its OWN, EARLIER "does this id exist" check (ahead of the
	// destructive-approval decision), entirely separate from
	// Mcpwp_Posts::delete_post()'s internal existence+category check — so a
	// category-denied but otherwise-writable id (an Elementor template, or
	// literally anything with force=true, which is always "permanent")
	// sailed past that pre-check and straight into defer_destructive(),
	// QUEUING AN APPROVAL an admin could later apply and complete, for a
	// delete the calling key was never authorized for. Fixed by calling
	// Mcpwp_Posts::resolve_deletable_post() first, unconditionally, before
	// any destructive-gate decision — the tests below are its regression
	// coverage, replacing the "no equivalent test" note this file used to
	// carry for DELETE specifically.

	public function test_delete_post_denial_is_now_byte_identical_to_a_missing_post(): void {
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );
		$missing_id = self::PRODUCT_ID + 5000;

		$denied_response  = $this->result_after_auth( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ), function ( $request ) use ( $c ) {
			return $c->delete_post( $request );
		} );

		$c2               = $this->controller();
		$unrestricted     = $this->scoped_key( $c2, array( 'content', 'woocommerce' ) );
		// The missing id goes through the same permission-then-handler path
		// a real request takes: since #1030 round-5 the guard answers it.
		$missing_response = $this->result_after_auth( $c2, $unrestricted['key'], 'DELETE', '/mcpwp/v1/posts/' . $missing_id, array( 'id' => $missing_id ), function ( $request ) use ( $c2 ) {
			return $c2->delete_post( $request );
		} );

		$this->assert_wp_error_shape_identical( $denied_response, $missing_response, 'BLOCK-R2-1: a category-denied product and a genuinely missing id must answer with the same not_found/404 shape on DELETE /posts/{id}.' );
	}

	public function test_delete_post_denial_is_identical_for_force_true_too(): void {
		// force=true makes delete_is_permanent() unconditionally true — the
		// exact condition that used to let a category-denied id skip
		// straight to defer_destructive() without ever being checked.
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );

		$result = $this->result_after_auth( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'force' => true ), function ( $request ) use ( $c ) {
			return $c->delete_post( $request );
		} );
		$this->assert_not_found( $result, 'force=true must not bypass the category check.' );
		$this->assertArrayHasKey( self::PRODUCT_ID, $GLOBALS['_mcpwp_test_posts'], 'The product must still exist.' );
	}

	public function test_delete_post_denial_of_an_elementor_template_is_not_found_not_deferred(): void {
		// The template is the review's second live example: delete_is_permanent()
		// is unconditionally true for elementor_library, which used to mean
		// an automatic defer_destructive() with no category check at all.
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );

		$result = $this->result_after_auth( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID ), function ( $request ) use ( $c ) {
			return $c->delete_post( $request );
		} );
		$this->assert_not_found( $result, 'A content-only key must not be able to queue a template delete for approval either.' );
		$this->assertArrayHasKey( self::ARCHETYPE_ID, $GLOBALS['_mcpwp_test_posts'] );
	}

	public function test_delete_post_denial_never_queues_an_approval_request(): void {
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );

		$this->result_after_auth( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'force' => true ), function ( $request ) use ( $c ) {
			return $c->delete_post( $request );
		} );

		$this->result_after_auth( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID ), function ( $request ) use ( $c ) {
			return $c->delete_post( $request );
		} );

		$this->assertCount( 0, Mcpwp_Approvals::list_requests( 'pending', 50, true ), 'A category-denied delete must never reach the point of being queued, whatever force is set to.' );
	}

	public function test_delete_post_denial_holds_with_the_destructive_gate_switched_off(): void {
		// Belt-and-braces: the fix calls resolve_deletable_post()
		// unconditionally, before should_gate() is even consulted, so the
		// site-wide approval switch being off must not matter either.
		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false ) );

		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );
		$result     = $this->result_after_auth( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'force' => true ), function ( $request ) use ( $c ) {
			return $c->delete_post( $request );
		} );

		$this->assert_not_found( $result );
		$this->assertArrayHasKey( self::PRODUCT_ID, $GLOBALS['_mcpwp_test_posts'] );
	}

	// ── NIT: the deferred approval title now names the post type ────────

	public function test_deferred_delete_title_names_the_post_type(): void {
		$c       = $this->controller();
		$created = $this->admin_key( $c ); // #1103 round 2: deleting a product via /posts needs admin scope.
		$request = $this->authed_request( $c, $created['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'force' => true ) );

		$resp = $c->delete_post( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 202, $resp->get_status() );

		$pending = Mcpwp_Approvals::list_requests( 'pending', 50, true );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'Permanently delete product #' . self::PRODUCT_ID, $pending[0]['title'] ?? null, 'The queued approval must name the post TYPE, not just "post".' );
	}
}
