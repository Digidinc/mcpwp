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
		$request = $this->authed_request( $c, $created['key'], 'PUT', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'title' => 'HACKED' ) );

		$this->assert_not_found( $c->update_post( $request ), '#1022 BLOCK-1: a content-only key must not retitle a product via PUT /posts/{id}.' );
		$this->assertSame( 'Woolly Hoodie', Mcpwp_Agent_Text::decode( $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_title ), 'Nothing must have been written.' );
	}

	public function test_update_post_elementor_template_denied_for_content_only_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$request = $this->authed_request( $c, $created['key'], 'PUT', '/mcpwp/v1/posts/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID, 'content' => 'HACKED BODY' ) );

		$this->assert_not_found( $c->update_post( $request ), '#1022 BLOCK-1: a content-only key must not re-body an Elementor template via PUT /posts/{id}.' );
		$this->assertSame( 'Original template body.', $GLOBALS['_mcpwp_test_posts'][ self::ARCHETYPE_ID ]->post_content );
	}

	public function test_update_post_product_allowed_for_woocommerce_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content', 'woocommerce' ) );
		$request = $this->authed_request( $c, $created['key'], 'PUT', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'title' => 'New Hoodie Name' ) );

		$result = $c->update_post( $request );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'New Hoodie Name', $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_title );
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
		$request = $this->authed_request( $c, $created['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $c->delete_post( $request ), '#1022 BLOCK-1: a content-only key must not delete a product via DELETE /posts/{id}.' );
		$this->assertArrayHasKey( self::PRODUCT_ID, $GLOBALS['_mcpwp_test_posts'], 'The product must still exist.' );
	}

	public function test_delete_post_product_allowed_for_woocommerce_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content', 'woocommerce' ) );
		$request = $this->authed_request( $c, $created['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$result = $c->delete_post( $request );
		$this->assertNotInstanceOf( WP_Error::class, $result );
	}

	// ── BLOCK-1: bulk (per-item refusal, no bulk-specific code) ──────────

	public function test_bulk_update_posts_refuses_the_product_item_and_updates_the_rest(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$request = $this->authed_request(
			$c,
			$created['key'],
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

	public function test_bulk_create_posts_refuses_a_product_item_and_creates_the_rest(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$request = $this->authed_request(
			$c,
			$created['key'],
			'POST',
			'/mcpwp/v1/posts/bulk',
			array(
				'posts' => array(
					array( 'post_type' => 'product', 'title' => 'Sneaky New Product' ),
					array( 'title' => 'A legit new post' ),
				),
			)
		);

		$resp = $c->bulk_create_posts( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$data = $resp->get_data();

		$this->assertCount( 1, $data['errors'], 'Exactly the product item must be refused.' );
		$this->assertCount( 1, $data['created'], 'The ordinary post item must still be created.' );
		foreach ( $GLOBALS['_mcpwp_test_posts'] as $post ) {
			$this->assertNotSame( 'Sneaky New Product', $post->post_title, 'No product must have been created.' );
		}
	}

	// ── BLOCK-1: POST /posts (create) — this controller's own audit finding ─

	public function test_create_post_of_type_product_denied_for_content_only_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content' ) );
		$request = $this->authed_request( $c, $created['key'], 'POST', '/mcpwp/v1/posts', array( 'post_type' => 'product', 'title' => 'Sneaky New Product' ) );

		$this->assert_forbidden_category( $c->create_post( $request ), 'A content-only key must not be able to CREATE a product through the generic post endpoint.' );
	}

	public function test_create_post_of_type_product_allowed_for_woocommerce_key(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content', 'woocommerce' ) );
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

		$denied_request  = $this->authed_request( $c, $restricted['key'], 'GET', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );
		$denied_response  = $c->get_post( $denied_request );

		$c2               = $this->controller();
		$unrestricted     = $this->scoped_key( $c2, array( 'content', 'woocommerce' ) );
		$missing_request  = $this->authed_request( $c2, $unrestricted['key'], 'GET', '/mcpwp/v1/posts/' . $missing_id, array( 'id' => $missing_id ) );
		$missing_response = $c2->get_post( $missing_request );

		$this->assert_wp_error_bodies_identical( $denied_response, $missing_response, 'A category-denied product and a genuinely missing id must answer with the exact same body on GET /posts/{id}.' );
	}

	public function test_set_featured_image_denial_is_byte_identical_to_a_missing_post(): void {
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );
		$missing_id = self::PRODUCT_ID + 5000;

		$denied_request  = $this->authed_request( $c, $restricted['key'], 'POST', '/mcpwp/v1/posts/' . self::PRODUCT_ID . '/featured-image', array( 'id' => self::PRODUCT_ID, 'media_id' => self::MEDIA_ID ) );
		$denied_response  = $c->set_featured_image( $denied_request );

		$c2               = $this->controller();
		$unrestricted     = $this->scoped_key( $c2, array( 'content', 'woocommerce' ) );
		$missing_request  = $this->authed_request( $c2, $unrestricted['key'], 'POST', '/mcpwp/v1/posts/' . $missing_id . '/featured-image', array( 'id' => $missing_id, 'media_id' => self::MEDIA_ID ) );
		$missing_response = $c2->set_featured_image( $missing_request );

		$this->assert_wp_error_bodies_identical( $denied_response, $missing_response, 'A category-denied product and a genuinely missing id must answer with the exact same body on POST /posts/{id}/featured-image.' );
	}

	public function test_update_post_denial_is_byte_identical_to_a_missing_post(): void {
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );
		$missing_id = self::PRODUCT_ID + 5000;

		$denied_request  = $this->authed_request( $c, $restricted['key'], 'PUT', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'title' => 'x' ) );
		$denied_response  = $c->update_post( $denied_request );

		$c2               = $this->controller();
		$unrestricted     = $this->scoped_key( $c2, array( 'content', 'woocommerce' ) );
		$missing_request  = $this->authed_request( $c2, $unrestricted['key'], 'PUT', '/mcpwp/v1/posts/' . $missing_id, array( 'id' => $missing_id, 'title' => 'x' ) );
		$missing_response = $c2->update_post( $missing_request );

		$this->assert_wp_error_bodies_identical( $denied_response, $missing_response, 'A category-denied product and a genuinely missing id must answer with the exact same body on PUT /posts/{id}.' );
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

		$denied_request  = $this->authed_request( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );
		$denied_response  = $c->delete_post( $denied_request );

		$c2               = $this->controller();
		$unrestricted     = $this->scoped_key( $c2, array( 'content', 'woocommerce' ) );
		$missing_request  = $this->authed_request( $c2, $unrestricted['key'], 'DELETE', '/mcpwp/v1/posts/' . $missing_id, array( 'id' => $missing_id ) );
		$missing_response = $c2->delete_post( $missing_request );

		$this->assert_wp_error_bodies_identical( $denied_response, $missing_response, 'BLOCK-R2-1: a category-denied product and a genuinely missing id must answer with the exact same body on DELETE /posts/{id}.' );
	}

	public function test_delete_post_denial_is_identical_for_force_true_too(): void {
		// force=true makes delete_is_permanent() unconditionally true — the
		// exact condition that used to let a category-denied id skip
		// straight to defer_destructive() without ever being checked.
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );

		$request = $this->authed_request( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'force' => true ) );
		$this->assert_not_found( $c->delete_post( $request ), 'force=true must not bypass the category check.' );
		$this->assertArrayHasKey( self::PRODUCT_ID, $GLOBALS['_mcpwp_test_posts'], 'The product must still exist.' );
	}

	public function test_delete_post_denial_of_an_elementor_template_is_not_found_not_deferred(): void {
		// The template is the review's second live example: delete_is_permanent()
		// is unconditionally true for elementor_library, which used to mean
		// an automatic defer_destructive() with no category check at all.
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );

		$request = $this->authed_request( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID ) );
		$this->assert_not_found( $c->delete_post( $request ), 'A content-only key must not be able to queue a template delete for approval either.' );
		$this->assertArrayHasKey( self::ARCHETYPE_ID, $GLOBALS['_mcpwp_test_posts'] );
	}

	public function test_delete_post_denial_never_queues_an_approval_request(): void {
		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );

		$product_request  = $this->authed_request( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'force' => true ) );
		$c->delete_post( $product_request );

		$template_request = $this->authed_request( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID ) );
		$c->delete_post( $template_request );

		$this->assertCount( 0, Mcpwp_Approvals::list_requests( 'pending', 50, true ), 'A category-denied delete must never reach the point of being queued, whatever force is set to.' );
	}

	public function test_delete_post_denial_holds_with_the_destructive_gate_switched_off(): void {
		// Belt-and-braces: the fix calls resolve_deletable_post()
		// unconditionally, before should_gate() is even consulted, so the
		// site-wide approval switch being off must not matter either.
		update_option( 'mcpwp_settings', array( 'destructive_requires_approval' => false ) );

		$c          = $this->controller();
		$restricted = $this->scoped_key( $c, array( 'content' ) );
		$request    = $this->authed_request( $c, $restricted['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'force' => true ) );

		$this->assert_not_found( $c->delete_post( $request ) );
		$this->assertArrayHasKey( self::PRODUCT_ID, $GLOBALS['_mcpwp_test_posts'] );
	}

	// ── NIT: the deferred approval title now names the post type ────────

	public function test_deferred_delete_title_names_the_post_type(): void {
		$c       = $this->controller();
		$created = $this->scoped_key( $c, array( 'content', 'woocommerce' ) );
		$request = $this->authed_request( $c, $created['key'], 'DELETE', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID, 'force' => true ) );

		$resp = $c->delete_post( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 202, $resp->get_status() );

		$pending = Mcpwp_Approvals::list_requests( 'pending', 50, true );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'Permanently delete product #' . self::PRODUCT_ID, $pending[0]['title'] ?? null, 'The queued approval must name the post TYPE, not just "post".' );
	}
}
