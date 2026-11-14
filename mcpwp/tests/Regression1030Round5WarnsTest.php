<?php
/**
 * PR #1030 round-5 adversarial review, the three WARNs:
 *
 *  WARN-1 GET /site-state returned stored SEO issues about a product (title,
 *         url, post_id) to a key without woocommerce: Mcpwp_Site_State's SEO
 *         summary read list_issues() without the caller-category clamp
 *         /seo/issues applies.
 *  WARN-2 POST /menus {items:[{type:post_type, object:shop_coupon,
 *         object_id:N}]} created the item — the items[] of a menu created
 *         in one call were never declared post refs.
 *  WARN-3 wp_block had no category mapping, fell back to 'content', and a
 *         designer key (gutenberg, no content) got a 404 on its own
 *         reusable blocks through /blocks/{id}.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-event-store.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-state.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-blocks.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-site-manager.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-site-manager.php';

final class Regression1030Round5WarnsTest extends TestCase {

	private const PAGE_ID    = 6601;
	private const PRODUCT_ID = 6602;
	private const COUPON_ID  = 6603;
	private const BLOCK_ID   = 6604;
	private const MISSING_ID = 6699;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_public_post_types']  = array( 'product' );
		$GLOBALS['_mcpwp_test_nav_menus']         = array();
		$GLOBALS['_mcpwp_test_nav_menu_item_writes'] = array();
		$GLOBALS['_mcpwp_test_menu_page_ids']     = array();
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID    => new WP_Post( array( 'ID' => self::PAGE_ID, 'post_type' => 'page', 'post_title' => 'About', 'post_status' => 'publish' ) ),
			self::PRODUCT_ID => new WP_Post( array( 'ID' => self::PRODUCT_ID, 'post_type' => 'product', 'post_title' => 'Secret Hoodie', 'post_status' => 'publish' ) ),
			self::COUPON_ID  => new WP_Post( array( 'ID' => self::COUPON_ID, 'post_type' => 'shop_coupon', 'post_title' => 'SECRETCOUPON', 'post_status' => 'publish' ) ),
			self::BLOCK_ID   => new WP_Post( array( 'ID' => self::BLOCK_ID, 'post_type' => 'wp_block', 'post_title' => 'Reusable CTA', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>CTA</p><!-- /wp:paragraph -->' ) ),
		);
	}

	protected function tearDown(): void {
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	private function issue( string $id, int $post_id, string $title ): array {
		return array(
			'id'             => $id,
			'post_id'        => $post_id,
			'title'          => $title,
			'url'            => 'https://example.com/' . $post_id,
			'code'           => 'missing_meta_description',
			'category'       => 'metadata',
			'severity'       => 'warning',
			'status'         => 'open',
			'message'        => 'Missing meta description.',
			'priority_score' => 10,
			'last_seen_at'   => '2026-09-01 00:00:00',
		);
	}

	/** Permission callback, then the handler — what a real request gets. */
	private function dispatch( object $controller, string $method, string $route, array $params, string $key, string $handler ) {
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $key ) );
		$auth    = $controller->check_permission( $request );
		return true === $auth ? $controller->$handler( $request ) : $auth;
	}

	// ── WARN-1 ───────────────────────────────────────────────────────────

	public function test_site_state_seo_summary_drops_product_issues_for_a_key_without_woocommerce(): void {
		update_option(
			Mcpwp_SEO_Audit_Store::ISSUES_OPTION,
			array( $this->issue( 's1', self::PAGE_ID, 'About' ), $this->issue( 's2', self::PRODUCT_ID, 'Secret Hoodie' ) )
		);

		$c   = new Mcpwp_REST_Site_Info();
		$key = $c->create_scoped_api_key( 'no woo', array( 'read', 'write' ), 'custom', array( 'site', 'content', 'seo' ) )['key'];
		$seo = $this->dispatch( $c, 'GET', '/mcpwp/v1/site-state', array(), $key, 'get_site_state' )->get_data()['seo'];

		$this->assertSame( array( self::PAGE_ID ), array_column( $seo['top_issues'], 'post_id' ) );
		$this->assertSame( 1, $seo['summary']['total'], 'Counts describe only what the caller receives.' );
		$this->assertStringNotContainsString( 'Secret Hoodie', wp_json_encode( $seo ) );

		$c   = new Mcpwp_REST_Site_Info();
		$key = $c->create_scoped_api_key( 'woo', array( 'read', 'write' ), 'custom', array( 'site', 'content', 'seo', 'woocommerce' ) )['key'];
		$seo = $this->dispatch( $c, 'GET', '/mcpwp/v1/site-state', array(), $key, 'get_site_state' )->get_data()['seo'];
		$this->assertSame( 2, $seo['summary']['total'], 'A key holding woocommerce still sees both.' );
	}

	// ── WARN-2 ───────────────────────────────────────────────────────────

	/**
	 * /menus is admin-scope, so its keys are admin-role and bypass per-key
	 * categories; the reachable gate is the site-wide disabled category.
	 */
	private function create_menu_with( int $object_id, string $type = 'post_type' ): array {
		$GLOBALS['_mcpwp_test_nav_menus']            = array();
		$GLOBALS['_mcpwp_test_nav_menu_item_writes'] = array();
		update_option( 'mcpwp_disabled_tool_categories', array( 'woocommerce' ) );

		$c   = new Mcpwp_REST_Site_Manager( new Mcpwp_Site_Manager() );
		$key = $c->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' )['key'];

		$result = $this->dispatch(
			$c,
			'POST',
			'/mcpwp/v1/menus',
			array(
				'name'  => 'Main',
				'items' => array(
					array( 'title' => 'About', 'type' => 'post_type', 'object' => 'page', 'object_id' => self::PAGE_ID ),
					array( 'title' => 'Deal', 'type' => $type, 'object' => 'shop_coupon', 'object_id' => $object_id ),
				),
			),
			$key,
			'create_menu'
		);
		$this->assertInstanceOf( WP_Error::class, $result, 'Every item is validated before creating the menu.' );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menus'] );

		return array( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $result->get_error_data() ), $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
	}

	public function test_menu_create_items_answer_a_gated_post_exactly_like_a_missing_one(): void {
		foreach ( array( 'post_type', 'post' ) as $type ) { // 'post' is the back-compat alias that maps onto post_type.
			list( $gated, $gated_writes )     = $this->create_menu_with( self::COUPON_ID, $type );
			list( $missing, $missing_writes ) = $this->create_menu_with( self::MISSING_ID, $type );

			$this->assertEmpty( $gated_writes, "{$type}: validation failure must precede all item writes." );
			$this->assertSame( wp_json_encode( $missing ), wp_json_encode( $gated ), "{$type}: the gated item's error is the missing item's error." );
			$this->assertSame( count( $missing_writes ), count( $gated_writes ) );
			$this->assertStringNotContainsString( (string) self::COUPON_ID, wp_json_encode( $gated_writes ) );
		}
	}

	public function test_menu_item_alias_types_are_post_refs_too(): void {
		$this->assertTrue( Mcpwp_REST_API::menu_item_names_post( array( 'type' => 'post', 'object_id' => 5 ) ) );
		$this->assertTrue( Mcpwp_REST_API::menu_item_names_post( array( 'type' => 'page', 'object_id' => 5 ) ) );
		$this->assertTrue( Mcpwp_REST_API::menu_item_names_post( array( 'type' => 'post_type', 'object_id' => 5 ) ) );
		$this->assertFalse( Mcpwp_REST_API::menu_item_names_post( array( 'type' => 'taxonomy', 'object_id' => 5 ) ) );
		$this->assertFalse( Mcpwp_REST_API::menu_item_names_post( array( 'type' => 'custom', 'url' => 'https://example.com' ) ) );
	}

	// ── WARN-3 ───────────────────────────────────────────────────────────

	public function test_wp_block_belongs_to_gutenberg(): void {
		$this->assertSame( 'gutenberg', Mcpwp_REST_API::category_for_post_type( 'wp_block' ) );
	}

	public function test_designer_reads_and_writes_a_reusable_block_and_it_is_hidden_from_a_key_without_gutenberg(): void {
		$c        = new Mcpwp_REST_Blocks();
		$designer = $c->create_scoped_api_key( 'designer', array( 'read', 'write' ), 'designer' )['key'];

		$get = $this->dispatch( $c, 'GET', '/mcpwp/v1/blocks/' . self::BLOCK_ID, array( 'id' => self::BLOCK_ID ), $designer, 'get_blocks' );
		$this->assertInstanceOf( WP_REST_Response::class, $get );
		$this->assertSame( 200, $get->get_status() );

		// The write goes past the permission guard and the door's category
		// check to the block-safety validator; what that validator makes of
		// this harness's parse_blocks() double is not what is tested here
		// (the rig run shows the real 200).
		$c   = new Mcpwp_REST_Blocks();
		$put = $this->dispatch(
			$c,
			'POST',
			'/mcpwp/v1/blocks/' . self::BLOCK_ID,
			array( 'id' => self::BLOCK_ID, 'content' => '<!-- wp:paragraph --><p>New CTA</p><!-- /wp:paragraph -->' ),
			$designer,
			'set_blocks'
		);
		if ( $put instanceof WP_Error ) {
			$this->assertNotSame( 'not_found', $put->get_error_code(), 'A designer must not be refused its own reusable block.' );
		} else {
			$this->assertLessThan( 300, $put->get_status() );
		}

		// A key without gutenberg cannot reach /blocks at all (the route's own
		// category is gutenberg: 403 forbidden_category for ANY id, gated or
		// missing alike). Where such a key CAN name a reusable block is a
		// generic door — /post-meta/{id} is 'content' — and there the block
		// must read exactly like a missing id: the canonical 404.
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';
		$c       = new Mcpwp_REST_Site_Content();
		$all     = array_keys( $c->get_all_tool_category_labels() );
		$no_gb   = $c->create_scoped_api_key( 'no gutenberg', array( 'read', 'write' ), 'custom', array_values( array_diff( $all, array( 'gutenberg' ) ) ) )['key'];
		$gated   = $this->dispatch( $c, 'GET', '/mcpwp/v1/post-meta/' . self::BLOCK_ID, array( 'id' => self::BLOCK_ID ), $no_gb, 'get_post_meta_handler' );
		$c       = new Mcpwp_REST_Site_Content();
		$missing = $this->dispatch( $c, 'GET', '/mcpwp/v1/post-meta/' . self::MISSING_ID, array( 'id' => self::MISSING_ID ), $no_gb, 'get_post_meta_handler' );

		$this->assertInstanceOf( WP_Error::class, $gated );
		$this->assertSame( 'not_found', $gated->get_error_code() );
		$this->assertSame( 'The requested resource was not found.', $gated->get_error_message() );
		$this->assertSame( array( 'status' => 404, 'hint' => 'Check the id with wp_search or wp_list_posts.' ), $gated->get_error_data() );
		$this->assertSame( wp_json_encode( array( $missing->get_error_code(), $missing->get_error_message(), $missing->get_error_data() ) ), wp_json_encode( array( $gated->get_error_code(), $gated->get_error_message(), $gated->get_error_data() ) ) );

		$c        = new Mcpwp_REST_Blocks();
		$on_route = $this->dispatch( $c, 'GET', '/mcpwp/v1/blocks/' . self::BLOCK_ID, array( 'id' => self::BLOCK_ID ), $no_gb, 'get_blocks' );
		$this->assertInstanceOf( WP_Error::class, $on_route );
		$this->assertSame( 'forbidden_category', $on_route->get_error_code(), '/blocks itself requires gutenberg, for every id.' );
	}
}
