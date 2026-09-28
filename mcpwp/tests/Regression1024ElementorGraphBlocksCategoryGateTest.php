<?php
/**
 * Regression tests — #1024, follow-up to #1022's WARN-2 (adversarial review
 * of #1022).
 *
 * #1020/#1022 built one chokepoint — category_for_post_type() +
 * caller_may_read_category() + guard_post_type_category()/
 * guard_post_type_category_for_type() (trait-mcpwp-api-auth.php) — mapping a
 * RESOLVED post's post_type to the MCPWP tool category that governs it, and
 * wired it into every generic, id-based content door (/post-meta, /content,
 * /fetch, /posts). This issue is the same class of gap on doors #1020/#1022
 * never touched:
 *
 *  - /elementor/{id} and its sub-routes (get/set/summary/preview/sections/
 *    widgets/patch/find-replace): validate_post()
 *    (trait-mcpwp-elementor-validator.php) accepted elementor_library/
 *    elementor_snippet under the coarse route-level 'elementor' category
 *    alone, bypassing their own 'elementor-templates'/'elementor-theme'
 *    category.
 *  - /elementor/find-replace (bulk, post_type= param): same gap, plus the
 *    per-post scan loop.
 *  - /content-graph (post_types= param) and /content-graph/apply-link
 *    (source_id/target_id): no category filter at all — any type the
 *    public/queryable filter let through was returned, regardless of
 *    category.
 *  - /blocks/{id} (Gutenberg): resolved ANY post by numeric ID with NO type
 *    restriction whatsoever.
 *  - The site graph read surface (wp_graph_node/neighbors/query,
 *    Mcpwp_Graph_Reader) has no category concept at all — its own
 *    visibility model is purely post-status.
 *
 * Fix, at each chokepoint, reusing category_for_post_type()/
 * caller_may_read_category() — no second mapping:
 *
 *  - Mcpwp_Elementor_Basic::validate_post() (via an injected callback,
 *    mirroring Mcpwp_Posts::set_category_allowed_callback()) — EXCEPT for
 *    page/post, which stay gated by the /elementor route's own 'elementor'
 *    category alone: page/post are this door's base case, and the
 *    'designer' role deliberately holds 'elementor' without 'content'
 *    (KeyCategoryRestrictionAuthPlaneTest::test_designer_key_can_write_elementor_directly).
 *    Folding category_for_post_type('page') === 'content' in here
 *    unconditionally would silently break every designer-role site.
 *  - Mcpwp_REST_Elementor::find_replace_category_denied() — the SAME
 *    page/post exemption, applied to find_replace_many()'s scope resolution
 *    and per-post scan loop.
 *  - Mcpwp_REST_API::parse_graph_post_types() — filters the already-public/
 *    queryable list down to categories the caller holds; an empty result is
 *    a genuine denial, never defaulted back to page/post (see
 *    build_content_graph_data()'s own empty-list guard against WP_Query's
 *    "empty post_type array defaults to 'post'" gotcha).
 *  - Mcpwp_REST_Content_Graph::apply_internal_link() — folds the category
 *    check into the SAME not_found/target_not_found branches the door
 *    already uses for a missing/unpublished post, so denial is
 *    byte-identical and no approval is ever queued.
 *  - Mcpwp_REST_Blocks::blocks_post_category_denied() — the SAME page/post
 *    exemption (the 'gutenberg' route category, like 'elementor', is
 *    distinct from 'content').
 *  - Mcpwp_REST_Graph::graph_node_category_denied() /
 *    filter_graph_neighbors_by_category() — a REST-layer filter on top of
 *    Mcpwp_Graph_Reader, which stays status-only.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-basic.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-graph.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-blocks.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-graph.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';

final class Regression1024ElementorGraphBlocksCategoryGateTest extends TestCase {

	private const PAGE_ID      = 9101;
	private const POST_ID      = 9102;
	private const PRODUCT_ID   = 9103;
	private const ARCHETYPE_ID = 9104; // elementor_library
	private const SNIPPET_ID   = 9105; // elementor_snippet
	private const TARGET_ID    = 9106; // page, published, used as an internal-link target

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']                 = array();
		$GLOBALS['mcpwp_test_transients']              = array();
		$GLOBALS['mcpwp_test_current_user']            = 0;
		$GLOBALS['mcpwp_test_is_admin']                = false;
		$GLOBALS['mcpwp_test_filters']                 = array();
		$GLOBALS['_mcpwp_test_meta']                   = array();
		$GLOBALS['mcpwp_test_public_post_types']       = array( 'product', 'elementor_library' );
		$GLOBALS['mcpwp_test_post_type_supports']      = array( 'product' => array( 'thumbnail' ) );
		$GLOBALS['mcpwp_test_show_in_rest_post_types'] = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID      => new WP_Post(
				array(
					'ID'           => self::PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'About',
					'post_name'    => 'about',
					'post_status'  => 'publish',
					'post_content' => 'Plain page body.',
				)
			),
			self::POST_ID      => new WP_Post(
				array(
					'ID'           => self::POST_ID,
					'post_type'    => 'post',
					'post_title'   => 'Ordinary post',
					'post_name'    => 'ordinary-post',
					'post_status'  => 'publish',
					'post_content' => 'Plain post body.',
				)
			),
			self::PRODUCT_ID   => new WP_Post(
				array(
					'ID'           => self::PRODUCT_ID,
					'post_type'    => 'product',
					'post_title'   => 'Woolly Hoodie',
					'post_name'    => 'woolly-hoodie',
					'post_status'  => 'publish',
					'post_content' => 'SECRET margin notes in the product body.',
				)
			),
			self::ARCHETYPE_ID => new WP_Post(
				array(
					'ID'           => self::ARCHETYPE_ID,
					'post_type'    => 'elementor_library',
					'post_title'   => 'Apparel Archetype',
					'post_name'    => 'apparel-archetype',
					'post_status'  => 'publish',
					'post_content' => '',
				)
			),
			self::SNIPPET_ID   => new WP_Post(
				array(
					'ID'           => self::SNIPPET_ID,
					'post_type'    => 'elementor_snippet',
					'post_title'   => 'Header Custom Code',
					'post_name'    => 'header-custom-code',
					'post_status'  => 'publish',
					'post_content' => '',
				)
			),
			self::TARGET_ID    => new WP_Post(
				array(
					'ID'           => self::TARGET_ID,
					'post_type'    => 'page',
					'post_title'   => 'Chapter 7',
					'post_name'    => 'chapter-7',
					'post_status'  => 'publish',
					'post_content' => 'Target body.',
				)
			),
		);

		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data']    = json_encode( $this->tree( 'Old Brand' ) );
		$GLOBALS['_mcpwp_test_meta'][ self::ARCHETYPE_ID ]['_elementor_data'] = json_encode( $this->tree( 'Old Brand' ) );
	}

	protected function tearDown(): void {
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	private function tree( string $title ): array {
		return array(
			array(
				'id'       => 'sec00001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col00001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'head0001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => $title ),
							),
						),
					),
				),
			),
		);
	}

	/** A key restricted to exactly the given tool categories, read+write scope. */
	private function scoped_key( object $controller, array $categories ): array {
		return $controller->create_scoped_api_key( 'scoped', array( 'read', 'write' ), 'custom', $categories );
	}

	private function auth_request( object $controller, string $key, string $method, string $route, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $key ) );
		$auth    = method_exists( $controller, 'check_permission' )
			? $controller->check_permission( $request )
			: $controller->verify_api_key( $request );
		$this->assertTrue( $auth, 'authentication should succeed for a route-classified category the key holds' );
		return $request;
	}

	/**
	 * Same authentication as auth_request(), but tolerant of #1030 round-2
	 * review's post-naming-param chokepoint (Mcpwp_Api_Auth::check_post_naming_params_category())
	 * denying at auth time instead of letting the handler's own validate_post()/
	 * find-replace check do it. In real dispatch, WordPress's REST framework
	 * never calls a handler once permission_callback returns a WP_Error, so
	 * when auth denies here that WP_Error IS the door's real answer —
	 * proceeding to call the handler anyway would exercise
	 * Mcpwp_Elementor_Basic's category callback (wired only on a successful
	 * check_permission()) in a state production traffic never reaches.
	 *
	 * @param callable $call_handler Takes the authenticated WP_REST_Request, returns the handler's result.
	 * @return mixed The handler's result, or the WP_Error auth itself produced.
	 */
	private function result_after_auth( object $controller, string $key, string $method, string $route, array $params, callable $call_handler ) {
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $key ) );
		$auth    = method_exists( $controller, 'check_permission' )
			? $controller->check_permission( $request )
			: $controller->verify_api_key( $request );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		return $call_handler( $request );
	}

	/** Attach a key record directly, bypassing scope/route gates unrelated to this fix. */
	private function force_key_record( object $controller, array $categories, string $role = 'custom' ): void {
		$ref = new ReflectionProperty( get_class( $controller ), 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue(
			$controller,
			array(
				'id'              => 'forced',
				'label'           => 'forced',
				'scopes'          => array( 'read', 'write', 'admin' ),
				'role'            => $role,
				'tool_categories' => $categories,
			)
		);
	}

	private function assert_not_found( $result, string $context = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'not_found', $result->get_error_code(), $context );
		$this->assertSame( 404, $result->get_error_data()['status'], $context );
	}

	// =========================================================================
	// Door: Mcpwp_Elementor_Basic::validate_post() — /elementor/{id} chokepoint
	// =========================================================================

	/** A callback exactly like Mcpwp_REST_Elementor::check_permission() wires, for a key holding only $categories. */
	private function category_callback( array $categories ): callable {
		return function ( $post_type ) use ( $categories ) {
			return in_array( Mcpwp_REST_Elementor::category_for_post_type( $post_type ), $categories, true );
		};
	}

	public function test_validate_post_denies_archetype_for_elementor_only_key(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback( $this->category_callback( array( 'elementor' ) ) );

		$result = $elementor->validate_post( self::ARCHETYPE_ID );
		$this->assert_not_found( $result, 'elementor_library needs elementor-templates on top of elementor.' );
	}

	public function test_validate_post_denies_snippet_for_elementor_only_key(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback( $this->category_callback( array( 'elementor' ) ) );

		$result = $elementor->validate_post( self::SNIPPET_ID );
		$this->assert_not_found( $result, 'elementor_snippet needs elementor-theme on top of elementor.' );
	}

	public function test_validate_post_allows_archetype_when_key_holds_its_own_category(): void {
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback( $this->category_callback( array( 'elementor', 'elementor-templates' ) ) );

		$result = $elementor->validate_post( self::ARCHETYPE_ID );
		$this->assertInstanceOf( WP_Post::class, $result );
	}

	public function test_validate_post_never_gates_page_or_post_by_content_category(): void {
		// The designer-role regression guard: a key holding ONLY 'elementor'
		// (never 'content') must still resolve an ordinary page/post.
		$elementor = new Mcpwp_Elementor_Basic();
		$elementor->set_category_allowed_callback( $this->category_callback( array( 'elementor' ) ) ); // no 'content'

		$this->assertInstanceOf( WP_Post::class, $elementor->validate_post( self::PAGE_ID ) );
		$this->assertInstanceOf( WP_Post::class, $elementor->validate_post( self::POST_ID ) );
	}

	public function test_validate_post_unrestricted_when_no_callback_wired(): void {
		// Matches every other category gate's convention: no callback (no
		// REST/auth context, e.g. an internal call) means unrestricted.
		$elementor = new Mcpwp_Elementor_Basic();
		$this->assertInstanceOf( WP_Post::class, $elementor->validate_post( self::ARCHETYPE_ID ) );
	}

	// =========================================================================
	// Door: Mcpwp_REST_Elementor::check_permission() wiring, end to end
	// =========================================================================

	public function test_end_to_end_get_elementor_data_denies_archetype_for_content_only_key(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		$controller = new Mcpwp_REST_Elementor();
		$created    = $this->scoped_key( $controller, array( 'elementor' ) ); // no elementor-templates
		$result     = $this->result_after_auth( $controller, $created['key'], 'GET', '/mcpwp/v1/elementor/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID ), function ( $request ) use ( $controller ) {
			return $controller->get_elementor_data( $request );
		} );

		$this->assert_not_found( $result, 'wp_get_elementor => GET /elementor/{id} on an archetype.' );
	}

	public function test_end_to_end_get_elementor_data_allows_page_for_elementor_only_key(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		$controller = new Mcpwp_REST_Elementor();
		$created    = $this->scoped_key( $controller, array( 'elementor' ) ); // no content
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/elementor/' . self::PAGE_ID, array( 'id' => self::PAGE_ID ) );

		$resp = $controller->get_elementor_data( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp, 'A designer-style elementor-only key must still read a plain page.' );
	}

	public function test_end_to_end_site_wide_disable_of_elementor_templates_blocks_archetype(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '3.0' );
		}
		update_option( 'mcpwp_disabled_tool_categories', array( 'elementor-templates' ) );

		$controller = new Mcpwp_REST_Elementor();
		$created    = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$result     = $this->result_after_auth( $controller, $created['key'], 'GET', '/mcpwp/v1/elementor/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID ), function ( $request ) use ( $controller ) {
			return $controller->get_elementor_data( $request );
		} );

		$this->assert_not_found( $result, 'Site-wide toggle must block even an admin-role key.' );
	}

	// =========================================================================
	// Door: /elementor/{id}/find-replace (single) — reuses validate_post()
	// =========================================================================

	public function test_single_find_replace_denies_archetype_for_elementor_only_key(): void {
		$controller = new Mcpwp_REST_Elementor();
		$created    = $this->scoped_key( $controller, array( 'elementor' ) );
		$result     = $this->result_after_auth(
			$controller,
			$created['key'],
			'POST',
			'/mcpwp/v1/elementor/' . self::ARCHETYPE_ID . '/find-replace',
			array( 'id' => self::ARCHETYPE_ID, 'search' => 'Old Brand', 'replace' => 'New Brand' ),
			function ( $request ) use ( $controller ) {
				return $controller->find_replace( $request );
			}
		);

		$this->assert_not_found( $result );
		$this->assertStringContainsString( 'Old Brand', $GLOBALS['_mcpwp_test_meta'][ self::ARCHETYPE_ID ]['_elementor_data'], 'Nothing must have been written.' );
	}

	// =========================================================================
	// Door: /elementor/find-replace (bulk)
	// =========================================================================

	// Naming a whole post_type is a "wide" find/replace, which requires a
	// genuinely admin-SCOPED key at the route level (get_required_scope_for_request()) —
	// distinct from the #1024 category check. create_scoped_api_key() cannot
	// produce an admin-scope key with a restricted role/category (admin
	// scope is only reachable via role 'admin', which is always
	// category-unrestricted — resolve_key_tool_categories()), so these two
	// tests attach the key record directly, the same technique
	// PostTypeCategoryGateTest uses for the identical reason, to isolate
	// the #1024 category chokepoint from that unrelated scope gate.

	public function test_bulk_find_replace_post_type_archetype_denied_as_forbidden_category(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor' ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/find-replace', array( 'post_type' => 'elementor_library', 'search' => 'Old', 'replace' => 'New' ) );

		$result = $controller->find_replace_many( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'forbidden_category', $result->get_error_code() );
	}

	public function test_bulk_find_replace_post_type_page_unaffected_for_elementor_only_key(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor' ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/find-replace', array( 'post_type' => 'page', 'search' => 'Old Brand', 'replace' => 'New Brand' ) );

		$result = $controller->find_replace_many( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $result, 'page must never require content category through this door.' );
	}

	/**
	 * find_replace_many()'s OWN per-item selective skip (page rewritten,
	 * gated archetype quietly not_touched) — tested directly against the
	 * handler via force_key_record(), bypassing full auth: the per-item
	 * resolver the handler calls works without the guard in front of it
	 * (#1030 round-5). test_bulk_find_replace_gated_id_reads_like_a_missing_id()
	 * below covers the full permission-then-handler path.
	 */
	public function test_bulk_find_replace_by_ids_silently_skips_a_gated_archetype(): void {
		$controller = new Mcpwp_REST_Elementor();
		$this->force_key_record( $controller, array( 'elementor' ) );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/find-replace',
			array( 'ids' => array( self::PAGE_ID, self::ARCHETYPE_ID ), 'search' => 'Old Brand', 'replace' => 'New Brand' )
		);

		$response = $controller->find_replace_many( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$matched_ids = array_column( $data['matched'], 'id' );
		$this->assertContains( self::PAGE_ID, $matched_ids, 'The page must still be rewritten.' );
		$this->assertNotContains( self::ARCHETYPE_ID, $matched_ids, 'The archetype must not be rewritten.' );

		$not_touched = array();
		foreach ( $data['not_touched'] as $row ) {
			$not_touched[ $row['id'] ] = $row['reason'];
		}
		$this->assertSame( 'not_found', $not_touched[ self::ARCHETYPE_ID ] ?? null, 'A category denial must read exactly like a missing id (round-1 review NIT).' );
		$this->assertStringContainsString( 'Old Brand', $GLOBALS['_mcpwp_test_meta'][ self::ARCHETYPE_ID ]['_elementor_data'], 'The archetype must not have been written.' );
	}

	/**
	 * The real, end-to-end behavior for a mixed batch naming a WooCommerce
	 * product (#1030 round-5 design): the guard does not refuse a bulk
	 * request for one hidden id. find_replace_many() resolves each named id
	 * through the one per-item resolver: the page is rewritten, and the
	 * gated product's not_touched entry is exactly a missing id's entry.
	 */
	public function test_bulk_find_replace_gated_id_reads_like_a_missing_id(): void {
		$missing_id = 9999991;
		$run        = function ( int $second_id ) {
			$controller = new Mcpwp_REST_Elementor();
			$created    = $controller->create_scoped_api_key( 'scoped', array( 'read', 'write' ), 'custom', array( 'elementor' ) );
			return $this->result_after_auth(
				$controller,
				$created['key'],
				'POST',
				'/mcpwp/v1/elementor/find-replace',
				array( 'ids' => array( self::PAGE_ID, $second_id ), 'search' => 'Old Brand', 'replace' => 'New Brand', 'dry_run' => true ),
				function ( $request ) use ( $controller ) {
					return $controller->find_replace_many( $request );
				}
			);
		};

		$gated   = $run( self::PRODUCT_ID );
		$missing = $run( $missing_id );
		$this->assertInstanceOf( WP_REST_Response::class, $gated, 'A gated id must not refuse the whole batch.' );
		$this->assertInstanceOf( WP_REST_Response::class, $missing );

		$gated_data   = $gated->get_data();
		$missing_data = $missing->get_data();
		$this->assertContains( self::PAGE_ID, array_column( $gated_data['matched'], 'id' ), 'The page is still processed.' );

		$normalize = function ( array $data, int $id ) {
			$json = wp_json_encode( $data );
			return str_replace( (string) $id, '#ID#', $json );
		};
		$this->assertSame(
			$normalize( $missing_data, $missing_id ),
			$normalize( $gated_data, self::PRODUCT_ID ),
			'The whole response for a gated id must equal the response for a missing id, id aside.'
		);
	}

	// =========================================================================
	// Door: /content-graph (post_types=), including build_content_graph_data()'s
	// own empty-list guard
	// =========================================================================

	public function test_content_graph_drops_product_type_for_content_only_key(): void {
		$controller = new Mcpwp_REST_Content_Graph();
		$this->force_key_record( $controller, array( 'content' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/content-graph', array( 'post_types' => 'page,product', 'limit' => 50 ) );

		$response = $controller->get_content_graph( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( array( 'page' ), $data['summary']['post_types'], 'product must be dropped, not silently widened or refused outright.' );
		$types = array_column( $data['nodes'], 'type' );
		$this->assertNotContains( 'product', $types, 'No product node may be returned.' );
		$this->assertContains( self::PAGE_ID, array_column( $data['nodes'], 'id' ), 'The page must still come through.' );
	}

	public function test_content_graph_all_types_denied_returns_empty_graph_not_a_leak(): void {
		// A 'woocommerce'-only key asking for post_types=page: page needs
		// 'content', which this key does not hold. The pre-fix WP_Query
		// gotcha ("empty post_type array defaults to every post") would have
		// silently handed back every post/page on the site here.
		$controller = new Mcpwp_REST_Content_Graph();
		$this->force_key_record( $controller, array( 'woocommerce' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/content-graph', array( 'post_types' => 'page', 'limit' => 50 ) );

		$response = $controller->get_content_graph( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();

		$this->assertSame( array(), $data['nodes'], 'No node of any type may leak when every requested type is denied.' );
		$this->assertSame( 0, $data['summary']['node_count'] );
	}

	// =========================================================================
	// Door: /content-graph/apply-link
	// =========================================================================

	public function test_apply_internal_link_source_product_denied_and_no_approval_queued(): void {
		$controller = new Mcpwp_REST_Content_Graph();
		$this->force_key_record( $controller, array( 'content' ) ); // no woocommerce
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/content-graph/apply-link',
			array( 'source_id' => self::PRODUCT_ID, 'target_id' => self::TARGET_ID )
		);

		$before = get_option( 'mcpwp_approval_requests', array() );
		$result = $controller->apply_internal_link( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertSame( $before, get_option( 'mcpwp_approval_requests', array() ), 'No approval may be queued for a gated source.' );
	}

	public function test_apply_internal_link_target_archetype_denied_as_target_not_found(): void {
		$controller = new Mcpwp_REST_Content_Graph();
		$this->force_key_record( $controller, array( 'content' ) ); // no elementor-templates
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/content-graph/apply-link',
			array( 'source_id' => self::PAGE_ID, 'target_id' => self::ARCHETYPE_ID )
		);

		$before = get_option( 'mcpwp_approval_requests', array() );
		$result = $controller->apply_internal_link( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'target_not_found', $result->get_error_code() );
		$this->assertSame( $before, get_option( 'mcpwp_approval_requests', array() ), 'No approval may be queued for a gated target.' );
	}

	public function test_apply_internal_link_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Content_Graph();
		$this->force_key_record( $controller, array( 'content', 'woocommerce', 'admin' ), 'admin' );
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/content-graph/apply-link',
			array( 'source_id' => self::PRODUCT_ID, 'target_id' => self::TARGET_ID, 'approval_required' => false )
		);

		$result = $controller->apply_internal_link( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $result, 'A woocommerce-scoped admin key must still be able to link from a product.' );
	}

	// =========================================================================
	// Door: /blocks/{id} (Gutenberg) — no allowed-types list at all before #1024
	// =========================================================================

	public function test_get_blocks_denies_product_for_gutenberg_only_key(): void {
		$controller = new Mcpwp_REST_Blocks();
		$this->force_key_record( $controller, array( 'gutenberg' ) ); // no woocommerce
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/blocks/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->get_blocks( $request ), 'A gutenberg-only key must not read a product body via /blocks.' );
	}

	public function test_get_blocks_allows_page_for_gutenberg_only_key(): void {
		$controller = new Mcpwp_REST_Blocks();
		$this->force_key_record( $controller, array( 'gutenberg' ) ); // no content
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/blocks/' . self::PAGE_ID, array( 'id' => self::PAGE_ID ) );

		$resp = $controller->get_blocks( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp, 'The designer-style regression guard: gutenberg alone must still read a plain page.' );
	}

	public function test_set_blocks_denies_product_for_gutenberg_only_key_and_writes_nothing(): void {
		$controller = new Mcpwp_REST_Blocks();
		$this->force_key_record( $controller, array( 'gutenberg' ) );
		$before  = $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_content;
		$request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/blocks/' . self::PRODUCT_ID,
			array( 'id' => self::PRODUCT_ID, 'content' => '<!-- wp:paragraph --><p>HACKED</p><!-- /wp:paragraph -->', 'approval_required' => false )
		);

		$this->assert_not_found( $controller->set_blocks( $request ) );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ]->post_content, 'Nothing must have been written.' );
	}

	public function test_patch_block_section_denies_product_for_gutenberg_only_key(): void {
		$controller = new Mcpwp_REST_Blocks();
		$this->force_key_record( $controller, array( 'gutenberg' ) );
		$request = new WP_REST_Request(
			'PATCH',
			'/mcpwp/v1/blocks/' . self::PRODUCT_ID . '/patch',
			array( 'id' => self::PRODUCT_ID, 'selector' => 'p', 'content' => 'HACKED' )
		);

		$this->assert_not_found( $controller->patch_block_section( $request ) );
	}

	public function test_get_blocks_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Blocks();
		$this->force_key_record( $controller, array( 'gutenberg', 'woocommerce' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/blocks/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$resp = $controller->get_blocks( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
	}

	// =========================================================================
	// Site graph read surface — Mcpwp_REST_Graph's own category filter, since
	// Mcpwp_Graph_Reader stays status-only (its own visibility model)
	// =========================================================================

	private function graph_controller_with(): array {
		$controller = new Mcpwp_REST_Graph();
		$this->force_key_record( $controller, array( 'content' ) ); // no woocommerce
		return array( $controller, new ReflectionClass( $controller ) );
	}

	public function test_graph_node_category_denied_true_for_a_product_backed_key(): void {
		[ $controller, $rc ] = $this->graph_controller_with();
		$method = $rc->getMethod( 'graph_node_category_denied' );
		$method->setAccessible( true );

		$this->assertTrue( $method->invoke( $controller, 'post:' . self::PRODUCT_ID ), 'A product node must be denied for a content-only key.' );
	}

	public function test_graph_node_category_denied_false_for_a_page_backed_key(): void {
		[ $controller, $rc ] = $this->graph_controller_with();
		$method = $rc->getMethod( 'graph_node_category_denied' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $controller, 'post:' . self::PAGE_ID ) );
	}

	public function test_graph_node_category_denied_false_for_non_post_keys(): void {
		[ $controller, $rc ] = $this->graph_controller_with();
		$method = $rc->getMethod( 'graph_node_category_denied' );
		$method->setAccessible( true );

		$this->assertFalse( $method->invoke( $controller, 'term:category:5' ) );
		$this->assertFalse( $method->invoke( $controller, 'media:12' ) );
	}

	public function test_filter_graph_neighbors_by_category_drops_product_node_and_its_edges(): void {
		[ $controller, $rc ] = $this->graph_controller_with();
		$method = $rc->getMethod( 'filter_graph_neighbors_by_category' );
		$method->setAccessible( true );

		$result = array(
			'center' => array( 'node_key' => 'post:' . self::PAGE_ID ),
			'nodes'  => array(
				array( 'node_key' => 'post:' . self::POST_ID ),
				array( 'node_key' => 'post:' . self::PRODUCT_ID ),
			),
			'edges'  => array(
				array( 'from_key' => 'post:' . self::PAGE_ID, 'to_key' => 'post:' . self::POST_ID, 'type' => 'content_link' ),
				array( 'from_key' => 'post:' . self::PAGE_ID, 'to_key' => 'post:' . self::PRODUCT_ID, 'type' => 'content_link' ),
			),
		);

		$filtered = $method->invoke( $controller, $result );

		$this->assertCount( 1, $filtered['nodes'] );
		$this->assertSame( 'post:' . self::POST_ID, $filtered['nodes'][0]['node_key'] );
		$this->assertCount( 1, $filtered['edges'], 'The edge touching the hidden product node must be omitted, not just the node.' );
		$this->assertSame( 'post:' . self::POST_ID, $filtered['edges'][0]['to_key'] );
	}
}
