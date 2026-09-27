<?php
/**
 * Regression tests — #1020.
 *
 * Found in the round-4 adversarial review of PR #1013/#1007. get_post_meta_handler()
 * (includes/api/class-mcpwp-rest-site-content.php) checked the post's public
 * status and a meta-key blocklist, but never the post's TYPE. Every generic,
 * id-based door that resolves an arbitrary post by numeric ID was gated only
 * by its ROUTE PREFIX (get_category_for_route() — always 'content' for
 * /post-meta, /content, /fetch, /posts), never by what the id actually
 * resolved to. Live evidence from the finding: an author read-scope key, on a
 * site with 'elementor-templates' and 'woocommerce' both disabled site-wide,
 * still read a WooCommerce product's _sku/_regular_price and an Elementor
 * archetype's _mcpwp_archetype_brief straight through GET /post-meta/{id} —
 * while the archetype's and product's own dedicated routes (GET
 * /elementor/archetypes, GET /woocommerce/products) correctly answered 403.
 *
 * Fix: category_for_post_type() (trait-mcpwp-api-auth.php) is the one
 * chokepoint mapping a RESOLVED post's post_type to the tool category that
 * governs it — product/product_variation/shop_order/shop_order_refund/
 * shop_coupon => woocommerce; elementor_library => elementor-templates;
 * elementor_snippet => elementor-theme; post/page => content; anything else
 * => content only if public or show_in_rest, else 'admin' (no scoped-key
 * category reaches it). guard_post_type_category() (404-shaped, for a door
 * that resolves ONE specific post — the existence of that post is the
 * sensitive fact) and guard_post_type_category_for_type() (403-shaped, for a
 * door where the caller names the post type up front) apply it, combined
 * with caller_may_read_category() (both the site-wide disabled-category
 * toggle and the key's own role restriction), at every door named in the
 * issue: /post-meta (read + write, and the wp_get_post_meta/wp_set_post_meta
 * tools that dispatch to that same route), /content (list + delete), /fetch,
 * /posts (?post_type= and GET /posts/{id}), and /posts/{id}/featured-image.
 *
 * /search's `type` param and /pages/{id} (including clone and the template
 * route) are ALSO named in the issue as doors to audit, but both are already
 * safe against this class of bug for reasons unrelated to this fix —
 * search_content() clamps `type` to post/page/any before it ever reaches a
 * query, and every /pages/{id} handler already refuses a non-'page' post
 * type outright. This file's PagesAndSearchAlreadySafe tests are the
 * evidence for that, not a behavior change.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';

/**
 * Exposes the trait's protected chokepoint methods directly, same
 * conflict-resolution technique SiteRouteCategoryGatingTest's harness uses
 * for get_category_for_route().
 */
class Mcpwp_Post_Type_Category_Harness {
	use Mcpwp_Api_Auth {
		category_for_post_type as public;
		guard_post_type_category as public;
		guard_post_type_category_for_type as public;
	}
}

final class PostTypeCategoryGateTest extends TestCase {

	private const PRODUCT_ID   = 8701;
	private const ARCHETYPE_ID = 8702;
	private const SNIPPET_ID   = 8703;
	private const POST_ID      = 8704;
	private const PRIVATE_ID   = 8705;
	private const PUBLIC_CPT_ID = 8706;
	private const PAGE_ID       = 8707;
	private const MEDIA_ID       = 8708;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']              = array();
		$GLOBALS['mcpwp_test_transients']           = array();
		$GLOBALS['mcpwp_test_current_user']         = 0;
		$GLOBALS['mcpwp_test_is_admin']             = false;
		$GLOBALS['mcpwp_test_filters']               = array();
		// 'product' is registered as a real, PUBLIC post type in test bootstrap
		// too (matching WooCommerce's actual registration) so the pre-existing,
		// unrelated post_type_exists()/is_writable_post_type() gates each door
		// already runs do not mask what THIS fix is responsible for — every
		// "allowed" assertion below must succeed because of the #1020 category
		// guard passing, not because some other gate never got the chance to run.
		$GLOBALS['mcpwp_test_public_post_types']    = array( 'kb_article', 'product' );
		// WooCommerce actually registers 'product' with thumbnail support.
		$GLOBALS['mcpwp_test_post_type_supports']   = array( 'product' => array( 'thumbnail' ) );
		$GLOBALS['mcpwp_test_private_post_types']   = array( 'internal_widget_config' );
		$GLOBALS['mcpwp_test_show_in_rest_post_types'] = array();
		$GLOBALS['_mcpwp_test_meta']                = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PRODUCT_ID     => new WP_Post(
				array(
					'ID'          => self::PRODUCT_ID,
					'post_type'   => 'product',
					'post_title'  => 'Woolly Hoodie',
					'post_name'   => 'woolly-hoodie',
					'post_status' => 'publish',
				)
			),
			self::ARCHETYPE_ID   => new WP_Post(
				array(
					'ID'          => self::ARCHETYPE_ID,
					'post_type'   => 'elementor_library',
					'post_title'  => 'Apparel Archetype',
					'post_name'   => 'apparel-archetype',
					'post_status' => 'publish',
				)
			),
			self::SNIPPET_ID     => new WP_Post(
				array(
					'ID'          => self::SNIPPET_ID,
					'post_type'   => 'elementor_snippet',
					'post_title'  => 'Header Custom Code',
					'post_name'   => 'header-custom-code',
					'post_status' => 'publish',
				)
			),
			self::POST_ID        => new WP_Post(
				array(
					'ID'          => self::POST_ID,
					'post_type'   => 'post',
					'post_title'  => 'Ordinary post',
					'post_name'   => 'ordinary-post',
					'post_status' => 'publish',
				)
			),
			self::PRIVATE_ID     => new WP_Post(
				array(
					'ID'          => self::PRIVATE_ID,
					'post_type'   => 'internal_widget_config',
					'post_title'  => 'Internal config',
					'post_name'   => 'internal-config',
					'post_status' => 'publish',
				)
			),
			self::PUBLIC_CPT_ID  => new WP_Post(
				array(
					'ID'          => self::PUBLIC_CPT_ID,
					'post_type'   => 'kb_article',
					'post_title'  => 'How to reset your widget',
					'post_name'   => 'how-to-reset',
					'post_status' => 'publish',
				)
			),
			self::PAGE_ID        => new WP_Post(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'About',
					'post_name'   => 'about',
					'post_status' => 'publish',
				)
			),
			self::MEDIA_ID       => new WP_Post(
				array(
					'ID'          => self::MEDIA_ID,
					'post_type'   => 'attachment',
					'post_title'  => 'hoodie.jpg',
					'post_name'   => 'hoodie',
					'post_status' => 'inherit',
				)
			),
		);

		$GLOBALS['_mcpwp_test_meta'][ self::PRODUCT_ID ] = array(
			'_sku'            => 'HOODIE-001',
			'_regular_price'  => '89.00',
		);
		$GLOBALS['_mcpwp_test_meta'][ self::ARCHETYPE_ID ] = array(
			'_mcpwp_archetype_brief' => 'PRIVATE: internal margin targets and supplier terms.',
		);
	}

	protected function tearDown(): void {
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	private function harness(): Mcpwp_Post_Type_Category_Harness {
		return new Mcpwp_Post_Type_Category_Harness();
	}

	/** A key restricted to exactly the given tool categories, read+write scope. */
	private function scoped_key( object $controller, array $categories ): array {
		return $controller->create_scoped_api_key( 'scoped', array( 'read', 'write' ), 'custom', $categories );
	}

	private function auth_request( object $controller, string $key, string $method, string $route, array $params = array() ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $key ) );
		$this->assertTrue( $controller->verify_api_key( $request ), 'verify_api_key should succeed for a route-classified category the key holds' );
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

	// ── category_for_post_type(): the chokepoint mapping itself ─────────────

	public function test_maps_post_and_page_to_content(): void {
		$h = $this->harness();
		$this->assertSame( 'content', $h->category_for_post_type( 'post' ) );
		$this->assertSame( 'content', $h->category_for_post_type( 'page' ) );
	}

	public function test_maps_woocommerce_types(): void {
		$h = $this->harness();
		foreach ( array( 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon' ) as $type ) {
			$this->assertSame( 'woocommerce', $h->category_for_post_type( $type ), $type );
		}
	}

	public function test_maps_elementor_library_and_snippet_to_their_own_categories(): void {
		$h = $this->harness();
		$this->assertSame( 'elementor-templates', $h->category_for_post_type( 'elementor_library' ) );
		$this->assertSame( 'elementor-theme', $h->category_for_post_type( 'elementor_snippet' ) );
	}

	public function test_unknown_public_cpt_falls_back_to_content(): void {
		$h = $this->harness();
		$this->assertSame( 'content', $h->category_for_post_type( 'kb_article' ) );
	}

	public function test_unknown_unregistered_type_falls_back_to_admin(): void {
		$h = $this->harness();
		$this->assertSame( 'admin', $h->category_for_post_type( 'nobody_registered_this' ) );
	}

	public function test_registered_but_private_cpt_falls_back_to_admin(): void {
		// Exists (post_type_exists() === true) but public === false AND
		// show_in_rest === false — the exact "unknown/private CPT" case the
		// issue calls out, distinct from a type nobody registered at all.
		$h = $this->harness();
		$this->assertSame( 'admin', $h->category_for_post_type( 'internal_widget_config' ) );
	}

	// ── guard_post_type_category(): existence-sensitive (404) doors ────────

	public function test_guard_post_type_category_allows_when_key_holds_the_category(): void {
		$h       = $this->harness();
		$created = $this->scoped_key( $h, array( 'content', 'woocommerce' ) );
		$this->auth_request( $h, $created['key'], 'GET', '/mcpwp/v1/post-meta/' . self::PRODUCT_ID );

		$this->assertNull( $h->guard_post_type_category( $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ], 'nope' ) );
	}

	public function test_guard_post_type_category_denies_by_role_as_not_found(): void {
		$h       = $this->harness();
		$created = $this->scoped_key( $h, array( 'content' ) );
		$this->auth_request( $h, $created['key'], 'GET', '/mcpwp/v1/post-meta/' . self::PRODUCT_ID );

		$result = $h->guard_post_type_category( $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ], 'Post not found.' );
		$this->assert_not_found( $result );
		$this->assertSame( 'Post not found.', $result->get_error_message(), 'Denial must reuse the caller-supplied not-found message byte for byte.' );
	}

	public function test_guard_post_type_category_denies_site_wide_disable_even_for_an_unrestricted_key(): void {
		update_option( 'mcpwp_disabled_tool_categories', array( 'woocommerce' ) );

		$h       = $this->harness();
		$created = $this->scoped_key( $h, array() ); // empty => unrestricted by role
		$this->auth_request( $h, $created['key'], 'GET', '/mcpwp/v1/post-meta/' . self::PRODUCT_ID );

		$result = $h->guard_post_type_category( $GLOBALS['_mcpwp_test_posts'][ self::PRODUCT_ID ], 'Post not found.' );
		$this->assert_not_found( $result, 'The site-wide toggle must block even a role-unrestricted key.' );
	}

	public function test_guard_post_type_category_passes_through_non_wp_post(): void {
		$h = $this->harness();
		$this->assertNull( $h->guard_post_type_category( null, 'irrelevant' ), 'A missing post is some other guard\'s job to 404.' );
	}

	// ── guard_post_type_category_for_type(): caller-named-type (403) doors ─

	public function test_guard_post_type_category_for_type_allows_when_key_holds_the_category(): void {
		// Route classified 'content' but WITHOUT /content's own blanket-admin
		// SCOPE requirement (get_required_scope_for_request()'s $admin_routes),
		// so this exercises the category chokepoint alone, not that unrelated
		// scope gate.
		$h       = $this->harness();
		$created = $this->scoped_key( $h, array( 'content', 'woocommerce' ) );
		$this->auth_request( $h, $created['key'], 'GET', '/mcpwp/v1/posts' );

		$this->assertNull( $h->guard_post_type_category_for_type( 'product' ) );
	}

	public function test_guard_post_type_category_for_type_denies_as_forbidden_category(): void {
		$h       = $this->harness();
		$created = $this->scoped_key( $h, array( 'content' ) );
		$this->auth_request( $h, $created['key'], 'GET', '/mcpwp/v1/posts' );

		$result = $h->guard_post_type_category_for_type( 'product' );
		$this->assert_forbidden_category( $result );
	}

	// ── Door: GET /post-meta/{id} ───────────────────────────────────────────

	public function test_get_post_meta_product_denied_for_content_only_key(): void {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/post-meta/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->get_post_meta_handler( $request ), '#1020 PoC: a content-only key must not read a product\'s _sku via /post-meta.' );
	}

	public function test_get_post_meta_product_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content', 'woocommerce' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/post-meta/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$resp = $controller->get_post_meta_handler( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 'HOODIE-001', $resp->get_data()['meta']['_sku'] ?? null );
	}

	public function test_get_post_meta_elementor_archetype_denied_matching_the_issue_poc(): void {
		// #1020's own PoC evidence, replicated exactly: an author key (read
		// scope only) on a site with 'elementor-templates' AND 'woocommerce'
		// disabled site-wide.
		update_option( 'mcpwp_disabled_tool_categories', array( 'elementor-templates', 'woocommerce' ) );

		$controller = new Mcpwp_REST_Site_Content();
		$created    = $controller->create_scoped_api_key( 'author', array( 'read' ), 'author' );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/post-meta/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID ) );

		$this->assert_not_found( $controller->get_post_meta_handler( $request ), '#1020 PoC: an author key must not read an Elementor archetype brief via /post-meta.' );
	}

	public function test_get_post_meta_elementor_archetype_allowed_for_templates_key(): void {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content', 'elementor-templates' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/post-meta/' . self::ARCHETYPE_ID, array( 'id' => self::ARCHETYPE_ID ) );

		$resp = $controller->get_post_meta_handler( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertStringContainsString( 'PRIVATE', $resp->get_data()['meta']['_mcpwp_archetype_brief'] ?? '' );
	}

	public function test_get_post_meta_ordinary_post_unaffected_for_content_only_key(): void {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/post-meta/' . self::POST_ID, array( 'id' => self::POST_ID ) );

		$this->assertInstanceOf( WP_REST_Response::class, $controller->get_post_meta_handler( $request ), 'This fix must not regress a plain post/page.' );
	}

	// ── Door: POST /post-meta/{id} (set) ────────────────────────────────────

	public function test_set_post_meta_product_denied_for_content_only_key(): void {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content' ) );
		$request    = $this->auth_request(
			$controller,
			$created['key'],
			'POST',
			'/mcpwp/v1/post-meta/' . self::PRODUCT_ID,
			array( 'id' => self::PRODUCT_ID, 'key' => '_sku', 'value' => 'HACKED' )
		);

		$this->assert_not_found( $controller->set_post_meta_handler( $request ) );
		$this->assertSame( 'HOODIE-001', $GLOBALS['_mcpwp_test_meta'][ self::PRODUCT_ID ]['_sku'], 'Nothing must have been written.' );
	}

	public function test_set_post_meta_product_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content', 'woocommerce' ) );
		$request    = $this->auth_request(
			$controller,
			$created['key'],
			'POST',
			'/mcpwp/v1/post-meta/' . self::PRODUCT_ID,
			array( 'id' => self::PRODUCT_ID, 'key' => '_sku', 'value' => 'HOODIE-002' )
		);

		$resp = $controller->set_post_meta_handler( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 'HOODIE-002', $GLOBALS['_mcpwp_test_meta'][ self::PRODUCT_ID ]['_sku'] );
	}

	// ── Door: wp_get_post_meta / wp_set_post_meta (dispatch to /post-meta) ─

	public function test_wp_get_post_meta_and_wp_set_post_meta_dispatch_to_the_gated_route(): void {
		// Both tools map to route '/post-meta/{id}' (class-mcpwp-mcp-free-tools.php).
		// This is a direct, targeted proof that they route through this
		// controller's real handlers (rather than re-testing the whole /mcp
		// tools/call machinery, which ToolsCallSubRequestAuthTest already
		// covers for the credential-forwarding half of this dispatch).
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content' ) );

		$get_request = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/post-meta/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );
		$this->assert_not_found( $controller->get_post_meta_handler( $get_request ), 'wp_get_post_meta => GET /post-meta/{id}' );

		$set_request = $this->auth_request(
			$controller,
			$created['key'],
			'POST',
			'/mcpwp/v1/post-meta/' . self::PRODUCT_ID,
			array( 'id' => self::PRODUCT_ID, 'key' => '_sku', 'value' => 'HACKED' )
		);
		$this->assert_not_found( $controller->set_post_meta_handler( $set_request ), 'wp_set_post_meta => POST /post-meta/{id}' );
	}

	// ── Door: GET /fetch ─────────────────────────────────────────────────────

	public function test_fetch_by_id_product_denied_for_content_only_key(): void {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/fetch', array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->fetch_content( $request ) );
	}

	public function test_fetch_by_id_product_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content', 'woocommerce' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/fetch', array( 'id' => self::PRODUCT_ID ) );

		$resp = $controller->fetch_content( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
	}

	// ── Door: GET /content (?post_type=), DELETE /content/{type}/{id} ──────
	//
	// /content carries its OWN blanket-admin SCOPE requirement, every method
	// including GET (get_required_scope_for_request()'s $admin_routes) — a
	// pre-existing, unrelated gate. create_scoped_api_key() can only ever
	// grant 'admin' scope on role 'admin', and role 'admin' is unconditionally
	// category-unrestricted (resolve_key_tool_categories()) — so no key this
	// plugin's own key-creation path can produce is BOTH admin-scope-capable
	// AND category-restricted, and a real HTTP call from a category-restricted
	// key never reaches list_content()/delete_content() at all: it is refused
	// upstream in verify_api_key() with insufficient_scope. The category guard
	// added here is still correct defense-in-depth for whatever ELSE might one
	// day attach a category-restricted key_record to this controller (a future
	// OAuth/broker credential type, an internal dispatch) — these tests attach
	// one directly, bypassing the unrelated scope gate on purpose, so they
	// exercise exactly the #1020 chokepoint and nothing else.

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

	public function test_list_content_product_denied_as_forbidden_category(): void {
		$controller = new Mcpwp_REST_Content();
		$this->force_key_record( $controller, array( 'content' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/content', array( 'post_type' => 'product' ) );

		$this->assert_forbidden_category( $controller->list_content( $request ) );
	}

	public function test_list_content_product_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Content();
		$this->force_key_record( $controller, array( 'content', 'woocommerce' ) );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/content', array( 'post_type' => 'product' ) );

		$resp = $controller->list_content( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 1, $resp->get_data()['pagination']['total'] ?? null );
	}

	public function test_delete_content_elementor_library_denied_as_not_found(): void {
		$controller = new Mcpwp_REST_Content();
		$this->force_key_record( $controller, array( 'content' ) );
		$request = new WP_REST_Request(
			'DELETE',
			'/mcpwp/v1/content/elementor_library/' . self::ARCHETYPE_ID,
			array( 'post_type' => 'elementor_library', 'id' => self::ARCHETYPE_ID )
		);

		$this->assert_not_found( $controller->delete_content( $request ), '#1020 delete-content door: a content-only key must not delete an Elementor archetype.' );
	}

	public function test_delete_content_product_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Content();
		$this->force_key_record( $controller, array( 'content', 'woocommerce' ) );
		$request = new WP_REST_Request(
			'DELETE',
			'/mcpwp/v1/content/product/' . self::PRODUCT_ID,
			array( 'post_type' => 'product', 'id' => self::PRODUCT_ID )
		);

		$resp = $controller->delete_content( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
	}

	// ── Door: GET /posts (?post_type=), GET /posts/{id}, featured-image ────

	public function test_list_posts_product_override_denied_as_forbidden_category(): void {
		$controller = new Mcpwp_REST_Posts();
		$created    = $this->scoped_key( $controller, array( 'content' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/posts', array( 'post_type' => 'product' ) );

		$this->assert_forbidden_category( $controller->list_posts( $request ) );
	}

	public function test_list_posts_product_override_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Posts();
		$created    = $this->scoped_key( $controller, array( 'content', 'woocommerce' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/posts', array( 'post_type' => 'product' ) );

		$resp = $controller->list_posts( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 1, $resp->get_data()['total'] ?? null );
	}

	public function test_list_posts_default_type_unaffected_for_content_only_key(): void {
		$controller = new Mcpwp_REST_Posts();
		$created    = $this->scoped_key( $controller, array( 'content' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/posts' );

		$this->assertInstanceOf( WP_REST_Response::class, $controller->list_posts( $request ), 'No ?post_type= override — must not regress the default post listing.' );
	}

	public function test_get_post_by_id_product_denied_as_not_found(): void {
		$controller = new Mcpwp_REST_Posts();
		$created    = $this->scoped_key( $controller, array( 'content' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->get_post( $request ), '#1020 door: GET /posts/{id} on a product id must not resolve for a content-only key.' );
	}

	public function test_get_post_by_id_product_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Posts();
		$created    = $this->scoped_key( $controller, array( 'content', 'woocommerce' ) );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/posts/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assertInstanceOf( WP_REST_Response::class, $controller->get_post( $request ) );
	}

	public function test_set_featured_image_on_product_denied_as_not_found(): void {
		$controller = new Mcpwp_REST_Posts();
		$created    = $this->scoped_key( $controller, array( 'content' ) );
		$request    = $this->auth_request(
			$controller,
			$created['key'],
			'POST',
			'/mcpwp/v1/posts/' . self::PRODUCT_ID . '/featured-image',
			array( 'id' => self::PRODUCT_ID, 'media_id' => 999 )
		);

		$this->assert_not_found( $controller->set_featured_image( $request ), '#1020 door: a content-only key must not set a product\'s featured image.' );
	}

	public function test_set_featured_image_on_product_allowed_for_woocommerce_key(): void {
		$controller = new Mcpwp_REST_Posts();
		$created    = $this->scoped_key( $controller, array( 'content', 'woocommerce' ) );
		$request    = $this->auth_request(
			$controller,
			$created['key'],
			'POST',
			'/mcpwp/v1/posts/' . self::PRODUCT_ID . '/featured-image',
			array( 'id' => self::PRODUCT_ID, 'media_id' => self::MEDIA_ID )
		);

		$resp = $controller->set_featured_image( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp, 'A woocommerce-scoped key must still be able to set a product\'s featured image.' );
	}

	// ── Already-safe doors named in the issue (evidence, not a behavior change) ─

	public function test_search_type_param_cannot_reach_a_non_post_page_category(): void {
		// search_content() clamps `type` to post/page/any before it is ever used
		// in a query — a caller cannot pass type=product and reach WooCommerce
		// data through /search at all, regardless of category. Confirmed here
		// directly against the seeded product+post fixtures: a search for the
		// product's own title with type=product returns zero results (silently
		// clamped to 'any' => post+page), never the product.
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $this->scoped_key( $controller, array( 'content' ) );
		$request    = $this->auth_request(
			$controller,
			$created['key'],
			'GET',
			'/mcpwp/v1/search',
			array( 'query' => 'Woolly Hoodie', 'type' => 'product' )
		);

		$resp = $controller->search_content( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$this->assertSame( 'any', $resp->get_data()['type'], 'An unrecognised type must silently clamp to "any" (post+page), never pass a CPT through.' );
		$this->assertSame( 0, $resp->get_data()['pagination']['total'] ?? null, 'A product must never surface through /search regardless of the type param.' );
	}

	public function test_pages_by_id_with_a_non_page_id_is_already_not_found(): void {
		$controller = new Mcpwp_REST_Pages();
		$created    = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$request    = $this->auth_request( $controller, $created['key'], 'GET', '/mcpwp/v1/pages/' . self::PRODUCT_ID, array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->get_page( $request ), 'GET /pages/{id} already refuses a non-page post type on its own — no #1020 change needed here.' );
	}

	public function test_pages_clone_with_a_non_page_id_is_already_not_found(): void {
		$controller = new Mcpwp_REST_Pages();
		$created    = $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' );
		$request    = $this->auth_request( $controller, $created['key'], 'POST', '/mcpwp/v1/pages/' . self::PRODUCT_ID . '/clone', array( 'id' => self::PRODUCT_ID ) );

		$this->assert_not_found( $controller->clone_page( $request ), 'POST /pages/{id}/clone already refuses a non-page post type on its own.' );
	}
}
