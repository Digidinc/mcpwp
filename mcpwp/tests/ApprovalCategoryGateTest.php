<?php
/**
 * Regression tests — #1025.
 *
 * ROUND 1 (found in round 3 of the adversarial review of #1022, predates that
 * PR): /approvals is gated only the blanket 'admin' category
 * (get_route_category_map()), so a custom key holding 'admin' but not, say,
 * 'woocommerce' or 'elementor-templates' could list/get/approve/reject/apply/
 * roll back a request whose target that same key's role denies it
 * everywhere else — live evidence in the issue: an elementor_library
 * "Arch T" archetype's type, title and payload route, visible through a
 * plain GET /approvals to a key with no elementor-templates access at all.
 *
 * ROUND 2 (found reproducing the issue's own PoC against round 1's fix):
 *
 * BLOCK-1 — round 1 classified a destructive approval's target by its ROUTE
 * alone (Mcpwp_REST_API::get_category_for_route(payload.route)). The
 * GENERIC, id-based doors DELETE /posts/{id} and DELETE /content/{type}/{id}
 * defer a delete of ANY post type through a route that itself classifies as
 * 'content' — so a custom [admin, content] key still saw a queued
 * WooCommerce product deletion and an Elementor archetype deletion in full,
 * reproducing the original issue through a different pair of doors. Fixed by
 * making the check ADDITIVE: the caller must hold the route category AND,
 * whenever the target resolves to a real post, its post-type category too
 * (Mcpwp_Approvals::compute_visibility_categories() /
 * ::resolve_target_post_type()).
 *
 * WARN-1 — once the target is hard-deleted, live resolution fails, and
 * round 1's fallback was 'admin' — the same category /approvals itself
 * needs, so the record became visible to EVERY approvals reader. Fixed two
 * ways: (a) every `create_*_request()` method now computes and stores
 * `visibility_categories` on the record AT CREATION TIME, while the target
 * is guaranteed to still resolve, so a later deletion can never widen who
 * may see it; (b) a record with nothing stored and nothing left to resolve
 * live falls back to Mcpwp_Approvals::UNRESOLVABLE_CATEGORY — a sentinel
 * only an UNRESTRICTED key can ever satisfy, not 'admin'.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class ApprovalCategoryGateTest extends TestCase {

	private const PRODUCT_ID           = 8701;
	private const ELEMENTOR_LIBRARY_ID = 9101;
	private const ORDINARY_POST_ID     = 9102;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']              = array();
		$GLOBALS['mcpwp_test_transients']           = array();
		$GLOBALS['mcpwp_test_current_user']         = 0;
		$GLOBALS['mcpwp_test_is_admin']              = false;
		$GLOBALS['mcpwp_test_filters']               = array();
		$GLOBALS['mcpwp_test_dispatched_routes']     = array();
		// 'product' is registered as a real, PUBLIC post type, matching
		// WooCommerce's actual registration — same convention
		// PostTypeCategoryGateTest uses.
		$GLOBALS['mcpwp_test_public_post_types']    = array( 'product' );
		$GLOBALS['mcpwp_test_post_type_supports']   = array( 'product' => array( 'thumbnail' ) );
		$GLOBALS['_mcpwp_test_posts']                = array(
			self::PRODUCT_ID           => new WP_Post(
				array(
					'ID'          => self::PRODUCT_ID,
					'post_type'   => 'product',
					'post_title'  => 'SECRET PRODUCT Zeta',
					'post_name'   => 'secret-product-zeta',
					'post_status' => 'publish',
				)
			),
			self::ELEMENTOR_LIBRARY_ID => new WP_Post(
				array(
					'ID'          => self::ELEMENTOR_LIBRARY_ID,
					'post_type'   => 'elementor_library',
					'post_title'  => 'Arch T SECRET',
					'post_name'   => 'arch-t-secret',
					'post_status' => 'publish',
				)
			),
			self::ORDINARY_POST_ID     => new WP_Post(
				array(
					'ID'          => self::ORDINARY_POST_ID,
					'post_type'   => 'post',
					'post_title'  => 'Ordinary post',
					'post_name'   => 'ordinary-post',
					'post_status' => 'publish',
				)
			),
		);
	}

	protected function tearDown(): void {
		if ( class_exists( 'Mcpwp_REST_Batch' ) ) {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
		}
		update_option( 'mcpwp_disabled_tool_categories', array() );
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	/**
	 * Seed a raw approval record directly into the store — same
	 * technique OAuthSelfApprovalTest::seed_approval() already uses — so
	 * each test controls exactly the action/resource/payload shape it needs
	 * without going through every creator function's own side effects
	 * (flood caps, event emission — covered separately for /events).
	 *
	 * Deliberately does NOT set `visibility_categories` unless the caller
	 * passes it: every test below exercises the LEGACY, live-recomputed path
	 * unless it explicitly opts into the "as if created by this round's
	 * fixed creators" shape.
	 */
	private function seed_approval( array $overrides = array() ): string {
		static $counter = 0;
		++$counter;
		$id = 'appr_test_' . $counter;

		$defaults = array(
			'id'                  => $id,
			'status'              => 'pending',
			'action'              => 'destructive_operation',
			'operation'           => 'delete_product',
			'title'               => 'Test approval',
			'note'                => '',
			'tool'                => 'delete_product',
			'resource'            => array(
				'type'  => 'product',
				'id'    => self::PRODUCT_ID,
				'title' => 'SECRET PRODUCT Zeta',
			),
			'diff'                => array( 'summary' => 'No undo.' ),
			'payload'             => array(
				'route'  => '/mcpwp/v1/woocommerce/products/' . self::PRODUCT_ID,
				'method' => 'DELETE',
				'params' => array(),
				'target' => array(),
			),
			'rollback'            => array(),
			'metadata'            => array(),
			'requested_by_key_id' => 'other-key',
			'created_at'          => gmdate( 'c' ),
			'updated_at'          => gmdate( 'c' ),
			'approved_at'         => null,
			'applied_at'          => null,
			'rejected_at'         => null,
			'rolled_back_at'      => null,
		);

		$record = array_merge( $defaults, $overrides );
		$record['id'] = $id;

		$items       = mcpwp_test_approvals_all();
		$items       = is_array( $items ) ? $items : array();
		$items[ $id ] = $record;
		mcpwp_test_approvals_put( $items );

		return $id;
	}

	private function assert_not_found( $result, string $context = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'approval_not_found', $result->get_error_code(), $context );
		$this->assertSame( 404, $result->get_error_data()['status'], $context );
	}

	private function force_key_record( object $controller, array $categories, string $key_id = 'forced', string $role = 'custom' ): void {
		$ref = new ReflectionProperty( get_class( $controller ), 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue(
			$controller,
			array(
				'id'              => $key_id,
				'label'           => 'forced',
				'scopes'          => array( 'read', 'write', 'admin' ),
				'role'            => $role,
				'tool_categories' => $categories,
			)
		);
	}

	/** A real scoped key, authenticated on $controller via check_permission() (wires any door-specific callback, e.g. Mcpwp_REST_Posts's). */
	private function real_scoped_key_request( object $controller, array $categories, string $method, string $route, array $params = array() ): WP_REST_Request {
		$created = $controller->create_scoped_api_key( 'scoped-' . implode( '-', $categories ), array( 'read', 'write' ), 'custom', $categories );
		$request = new WP_REST_Request( $method, $route, $params, array( 'X-API-Key' => $created['key'] ) );
		$auth    = $controller->check_permission( $request );
		$this->assertTrue( $auth, 'premise: authentication + route-category gate must pass for this key/route' );
		return $request;
	}

	// ── Mcpwp_Approvals::categories_for_approval(): the chokepoint itself ───

	public function test_destructive_operation_classifies_by_its_stored_route(): void {
		$id = $this->seed_approval(
			array(
				'action'   => 'destructive_operation',
				'resource' => array( 'type' => 'user', 'id' => 1, 'title' => 'jdoe' ),
				'payload'  => array( 'route' => '/mcpwp/v1/users/1' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( array( 'admin' ), Mcpwp_Approvals::categories_for_approval( $record ) );
	}

	public function test_block1_generic_posts_door_requires_BOTH_route_and_post_type_category(): void {
		// The exact shape DELETE /posts/{id} defers with (see
		// Mcpwp_REST_Posts::delete_post()): resource.type is the REAL
		// resolved post type ('product'), but the route is the GENERIC
		// /posts/{id} door, which classifies 'content' by itself.
		$id = $this->seed_approval(
			array(
				'action'   => 'destructive_operation',
				'operation' => 'delete_post',
				'resource' => array( 'type' => 'product', 'id' => self::PRODUCT_ID, 'title' => 'SECRET PRODUCT Zeta' ),
				'payload'  => array( 'route' => '/mcpwp/v1/posts/' . self::PRODUCT_ID, 'target' => array( 'exists' => true, 'type' => 'product' ) ),
			)
		);
		$record     = Mcpwp_Approvals::get_request( $id );
		$categories = Mcpwp_Approvals::categories_for_approval( $record );

		$this->assertEqualsCanonicalizing( array( 'content', 'woocommerce' ), $categories );
	}

	public function test_block1_generic_content_door_requires_BOTH_route_and_post_type_category(): void {
		// DELETE /content/{type}/{id}'s own shape (Mcpwp_REST_Content::delete_content()).
		$id = $this->seed_approval(
			array(
				'action'   => 'destructive_operation',
				'operation' => 'delete_content',
				'resource' => array( 'type' => 'elementor_library', 'id' => self::ELEMENTOR_LIBRARY_ID, 'title' => 'Arch T SECRET' ),
				'payload'  => array( 'route' => '/mcpwp/v1/content/elementor_library/' . self::ELEMENTOR_LIBRARY_ID, 'target' => array( 'exists' => true, 'type' => 'elementor_library' ) ),
			)
		);
		$record     = Mcpwp_Approvals::get_request( $id );
		$categories = Mcpwp_Approvals::categories_for_approval( $record );

		$this->assertEqualsCanonicalizing( array( 'content', 'elementor-templates' ), $categories );
	}

	public function test_destructive_operation_route_and_post_type_agree_are_not_duplicated(): void {
		// /woocommerce/products/{id} classifies 'woocommerce' by route AND
		// by the live post's type — must not require ['woocommerce','woocommerce'].
		$id = $this->seed_approval(); // Defaults: product via /woocommerce/products/{id}.
		$record     = Mcpwp_Approvals::get_request( $id );
		$categories = Mcpwp_Approvals::categories_for_approval( $record );

		$this->assertSame( array( 'woocommerce' ), $categories );
	}

	public function test_destructive_operation_on_a_non_post_resource_classifies_by_route_only(): void {
		$id = $this->seed_approval(
			array(
				'action'   => 'destructive_operation',
				'operation' => 'delete_widget',
				'resource' => array( 'type' => 'widget', 'id' => 'text-3', 'title' => 'text-3' ),
				'payload'  => array( 'route' => '/mcpwp/v1/widgets/text-3' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( array( 'site' ), Mcpwp_Approvals::categories_for_approval( $record ) );
	}

	/**
	 * Round 3 of the review: resolve_target_post_type() called get_post()
	 * for EVERY numeric resource.id before ever looking at resource.type,
	 * so delete_user/delete_course_category (a USER id / a TERM id) got
	 * misclassified by whatever unrelated POST happens to share that same
	 * numeric id — live PoC: user #11 vs. a product #11 → stored
	 * ["admin","woocommerce"], so disabling 'woocommerce' site-wide hid the
	 * delete_user approval from every key, including ones with no reason to
	 * lose it. self::PRODUCT_ID (a real product fixture) is reused here
	 * deliberately AS the colliding id.
	 */
	public function test_delete_user_with_a_colliding_post_id_classifies_by_route_only(): void {
		$id = $this->seed_approval(
			array(
				'action'   => 'destructive_operation',
				'operation' => 'delete_user',
				'resource' => array( 'type' => 'user', 'id' => self::PRODUCT_ID, 'title' => 'jdoe' ),
				'payload'  => array( 'route' => '/mcpwp/v1/users/' . self::PRODUCT_ID ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame(
			array( 'admin' ),
			Mcpwp_Approvals::categories_for_approval( $record ),
			'a colliding product at the same numeric id must not add woocommerce to a delete_user approval'
		);
	}

	public function test_delete_course_category_with_a_colliding_post_id_classifies_by_route_only(): void {
		$id = $this->seed_approval(
			array(
				'action'    => 'destructive_operation',
				'operation' => 'delete_course_category',
				'resource'  => array( 'type' => 'term', 'id' => self::PRODUCT_ID, 'taxonomy' => 'course_category', 'title' => 'Beginner' ),
				'payload'   => array( 'route' => '/mcpwp/v1/learnpress/course-categories/' . self::PRODUCT_ID ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame(
			array( 'learnpress' ),
			Mcpwp_Approvals::categories_for_approval( $record ),
			'a colliding product at the same numeric id must not add woocommerce to a delete_course_category approval'
		);
	}

	public function test_bulk_content_publish_classifies_by_payloads_real_post_type(): void {
		$id = $this->seed_approval(
			array(
				'action'   => 'bulk_content_publish',
				'resource' => array( 'type' => 'bulk_product', 'id' => null, 'title' => '3 product' ),
				'payload'  => array( 'items' => array(), 'post_type' => 'product' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( array( 'woocommerce' ), Mcpwp_Approvals::categories_for_approval( $record ) );
	}

	public function test_staging_push_always_classifies_as_staging(): void {
		$id = $this->seed_approval(
			array(
				'action'   => 'staging_push',
				'resource' => array( 'type' => 'staging', 'source' => 'staging', 'dest' => 'live' ),
				'payload'  => array( 'source' => 'staging', 'destination' => 'live' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( array( 'staging' ), Mcpwp_Approvals::categories_for_approval( $record ) );
	}

	public function test_post_content_update_resolves_the_live_post_type_not_the_hardcoded_resource_type(): void {
		// create_post_content_request() always stores resource.type='post'
		// regardless of the resolved post's real type (#1024's own finding
		// for the /blocks and /content-graph doors that call it on a
		// non-'post' target) — the LIVE post_type must win, or an Elementor
		// template edited through one of those doors would read back here
		// as ordinary 'content'.
		$id = $this->seed_approval(
			array(
				'action'   => 'post_content_update',
				'resource' => array( 'type' => 'post', 'id' => self::ELEMENTOR_LIBRARY_ID, 'title' => 'Arch T SECRET' ),
				'payload'  => array( 'after_content' => 'new' ),
				'rollback' => array( 'before_content' => 'old', 'before_hash' => hash( 'sha256', 'old' ) ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( array( 'elementor-templates' ), Mcpwp_Approvals::categories_for_approval( $record ) );
	}

	public function test_post_content_update_on_an_ordinary_post_classifies_as_content(): void {
		$id = $this->seed_approval(
			array(
				'action'   => 'post_content_update',
				'resource' => array( 'type' => 'post', 'id' => self::ORDINARY_POST_ID, 'title' => 'Ordinary post' ),
				'payload'  => array( 'after_content' => 'new' ),
				'rollback' => array( 'before_content' => 'old', 'before_hash' => hash( 'sha256', 'old' ) ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( array( 'content' ), Mcpwp_Approvals::categories_for_approval( $record ) );
	}

	// ── WARN-1: legacy records, a deleted target, and the UNRESOLVABLE fallback ─

	public function test_legacy_record_with_deleted_target_falls_back_to_unresolvable_not_admin(): void {
		// Simulates a record from BEFORE this round's fix: no
		// visibility_categories stored, resource.type hardcoded 'post'
		// (post_content_update's known-unreliable shape), payload carries no
		// fingerprinted target, and the post has since been hard-deleted —
		// nothing left to resolve live.
		$id = $this->seed_approval(
			array(
				'action'   => 'post_content_update',
				'resource' => array( 'type' => 'post', 'id' => 999999, 'title' => 'Deleted since' ),
				'payload'  => array( 'after_content' => 'new' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame(
			array( Mcpwp_Approvals::UNRESOLVABLE_CATEGORY ),
			Mcpwp_Approvals::categories_for_approval( $record )
		);
	}

	public function test_unresolvable_category_is_visible_only_to_an_unrestricted_key(): void {
		$id     = $this->seed_approval(
			array(
				'action'   => 'post_content_update',
				'resource' => array( 'type' => 'post', 'id' => 999999, 'title' => 'Deleted since' ),
				'payload'  => array( 'after_content' => 'new' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );

		// A restricted key holding EVERY real category still cannot see it —
		// the sentinel matches no role's category list, by construction.
		$restricted_allowed = function ( $category ) {
			return true; // Simulates "every real category is allowed" — still must fail.
		};
		$this->assertFalse(
			Mcpwp_Approvals::is_approval_visible_to(
				$record,
				function ( $category ) use ( $record ) {
					// A real caller_may_read_category() only ever says true for
					// a category the key's role actually lists; a restricted
					// role's list never contains the sentinel, so simulate
					// that precisely rather than "always true".
					return Mcpwp_Approvals::UNRESOLVABLE_CATEGORY !== $category;
				}
			)
		);

		// An unrestricted key (caller_may_read_category() always true, as it
		// is for role 'admin' — resolve_key_tool_categories() returns empty).
		$this->assertTrue(
			Mcpwp_Approvals::is_approval_visible_to(
				$record,
				function ( $category ) {
					return true;
				}
			)
		);
	}

	// ── WARN-1 (b): visibility_categories computed + stored at creation time ──

	public function test_create_post_content_request_stores_the_real_type_at_creation_time(): void {
		$result = Mcpwp_Approvals::create_post_content_request( self::ELEMENTOR_LIBRARY_ID, 'new content' );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'elementor-templates' ), $result['visibility_categories'] );

		// Even after the target is hard-deleted, the STORED value — not a
		// live recompute — governs visibility.
		unset( $GLOBALS['_mcpwp_test_posts'][ self::ELEMENTOR_LIBRARY_ID ] );
		$refetched = Mcpwp_Approvals::get_request( $result['id'] );
		$this->assertSame( array( 'elementor-templates' ), Mcpwp_Approvals::categories_for_approval( $refetched ) );
	}

	public function test_create_destructive_request_stores_route_and_post_type_categories(): void {
		$result = Mcpwp_Approvals::create_destructive_request(
			'delete_post',
			array( 'route' => '/mcpwp/v1/posts/' . self::PRODUCT_ID, 'method' => 'DELETE', 'params' => array(), 'target' => array( 'exists' => true, 'type' => 'product' ) ),
			array( 'type' => 'product', 'id' => self::PRODUCT_ID, 'title' => 'SECRET PRODUCT Zeta' ),
			array( 'title' => 'Permanently delete product', 'requested_by_key_id' => 'req-key' )
		);
		$this->assertIsArray( $result );
		$this->assertEqualsCanonicalizing( array( 'content', 'woocommerce' ), $result['visibility_categories'] );
	}

	public function test_create_bulk_publish_request_stores_the_real_post_type_category(): void {
		$result = Mcpwp_Approvals::create_bulk_publish_request(
			array( array( 'id' => 501 ), array( 'id' => 502 ) ),
			'product'
		);
		$this->assertIsArray( $result );
		$this->assertSame( array( 'woocommerce' ), $result['visibility_categories'] );
	}

	// ── Mcpwp_REST_Approvals::list_approvals() ───────────────────────────────

	public function test_list_approvals_hides_a_target_category_the_key_lacks(): void {
		$visible_id = $this->seed_approval(
			array(
				'operation' => 'delete_user',
				'resource'  => array( 'type' => 'user', 'id' => 1, 'title' => 'jdoe' ),
				'payload'   => array( 'route' => '/mcpwp/v1/users/1' ),
			)
		);
		$hidden_id = $this->seed_approval(); // Default: product.

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin' ) );

		$response = $controller->list_approvals( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals', array( 'limit' => 50, 'order' => 'date' ) ) );
		$ids      = array_column( $response->get_data()['approvals'], 'id' );

		$this->assertContains( $visible_id, $ids );
		$this->assertNotContains( $hidden_id, $ids );
	}

	public function test_list_approvals_shows_it_once_the_key_holds_the_category(): void {
		$hidden_id = $this->seed_approval();

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin', 'woocommerce' ) );

		$response = $controller->list_approvals( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals', array( 'limit' => 50, 'order' => 'date' ) ) );
		$ids      = array_column( $response->get_data()['approvals'], 'id' );

		$this->assertContains( $hidden_id, $ids );
	}

	// ── BLOCK-1: real door-level proof (queue via /posts/{id}, /content/{type}/{id}) ─

	public function test_block1_door_level_product_delete_via_generic_posts_route_is_hidden_from_admin_content_key(): void {
		$posts_controller = new Mcpwp_REST_Posts();
		$requester        = $this->real_scoped_key_request(
			$posts_controller,
			array( 'content', 'woocommerce' ),
			'DELETE',
			'/mcpwp/v1/posts/' . self::PRODUCT_ID,
			array( 'id' => self::PRODUCT_ID, 'force' => true )
		);

		$deferred = $posts_controller->delete_post( $requester );
		$this->assertInstanceOf( WP_REST_Response::class, $deferred, 'premise: the requester key must be able to queue this delete' );
		$this->assertSame( 202, $deferred->get_status() );
		$approval_id = $deferred->get_data()['approval_id'];

		// Live PoC from the review: a [admin, content] key.
		$approvals_controller = new Mcpwp_REST_Approvals();
		$reader_request       = $this->real_scoped_key_request( $approvals_controller, array( 'admin', 'content' ), 'GET', '/mcpwp/v1/approvals', array( 'limit' => 50 ) );

		$list_response = $approvals_controller->list_approvals( $reader_request );
		$ids           = array_column( $list_response->get_data()['approvals'], 'id' );
		$this->assertNotContains( $approval_id, $ids, 'a [admin, content] key must not see the queued product deletion' );

		$get_result = $approvals_controller->get_approval( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals/' . $approval_id, array( 'id' => $approval_id ) ) );
		$this->assert_not_found( $get_result );
	}

	public function test_block1_door_level_elementor_library_delete_via_generic_content_route_is_hidden_from_admin_content_key(): void {
		// #1022's own note: /content's delete_content() carries a blanket
		// 'admin' SCOPE requirement on every method, and
		// create_scoped_api_key() caps a non-admin-role key's scope ceiling
		// to read+write — so no key this plugin's OWN creation path can
		// produce is both admin-scope-capable and category-restricted.
		// force_key_record() (same technique PostTypeCategoryGateTest uses
		// for this exact door) attaches one directly, for defense-in-depth,
		// bypassing the unrelated scope gate on purpose so this test
		// exercises only the #1025 category chokepoint.
		$content_controller = new Mcpwp_REST_Content();
		$this->force_key_record( $content_controller, array( 'content', 'elementor-templates' ), 'requester-key' );
		$requester = new WP_REST_Request(
			'DELETE',
			'/mcpwp/v1/content/elementor_library/' . self::ELEMENTOR_LIBRARY_ID,
			array( 'post_type' => 'elementor_library', 'id' => self::ELEMENTOR_LIBRARY_ID, 'force' => true )
		);

		$deferred = $content_controller->delete_content( $requester );
		$this->assertInstanceOf( WP_REST_Response::class, $deferred, 'premise: the requester key must be able to queue this delete' );
		$this->assertSame( 202, $deferred->get_status() );
		$approval_id = $deferred->get_data()['approval_id'];

		$approvals_controller = new Mcpwp_REST_Approvals();
		$reader_request       = $this->real_scoped_key_request( $approvals_controller, array( 'admin', 'content' ), 'GET', '/mcpwp/v1/approvals', array( 'limit' => 50 ) );

		$list_response = $approvals_controller->list_approvals( $reader_request );
		$ids           = array_column( $list_response->get_data()['approvals'], 'id' );
		$this->assertNotContains( $approval_id, $ids, 'a [admin, content] key must not see the queued Elementor archetype deletion' );

		$get_result = $approvals_controller->get_approval( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals/' . $approval_id, array( 'id' => $approval_id ) ) );
		$this->assert_not_found( $get_result );
	}

	// ── wp_list_approvals (MCP tools/call) ───────────────────────────────────

	/**
	 * Routes /mcpwp/v1/approvals through the REAL Mcpwp_REST_Approvals
	 * controller instead of the shared canned-response double — same
	 * technique SiteContextArchetypeCategoryGateTest/PostWriteCategoryGateTest
	 * use for their own target routes.
	 */
	private function wire_real_approvals_list_dispatch(): void {
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request, $route ) {
				if ( null !== $result || '/mcpwp/v1/approvals' !== (string) $route ) {
					return $result;
				}
				$controller = new Mcpwp_REST_Approvals();
				$auth       = $controller->check_permission( $request );
				if ( is_wp_error( $auth ) ) {
					$data   = $auth->get_error_data();
					$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
					return new WP_REST_Response( array( 'code' => $auth->get_error_code(), 'message' => $auth->get_error_message(), 'data' => $data ), $status );
				}
				return $controller->list_approvals( $request );
			},
			10,
			4
		);
	}

	public function test_wp_list_approvals_tool_hides_the_same_target(): void {
		$hidden_id  = $this->seed_approval(); // Default: product.
		$visible_id = $this->seed_approval(
			array(
				'operation' => 'delete_user',
				'resource'  => array( 'type' => 'user', 'id' => 1, 'title' => 'jdoe' ),
				'payload'   => array( 'route' => '/mcpwp/v1/users/1' ),
			)
		);

		$this->wire_real_approvals_list_dispatch();

		$mcp        = new Mcpwp_REST_MCP();
		$created    = $mcp->create_scoped_api_key( 'reader', array( 'read', 'write' ), 'custom', array( 'admin', 'content' ) );
		$mcp_request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $created['key'] ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array( 'name' => 'wp_list_approvals', 'arguments' => array() ),
			)
		);
		$auth = $mcp->check_permission( $mcp_request );
		$this->assertTrue( $auth, 'premise: the outer /mcp request must authenticate' );

		$data = $mcp->handle_mcp( $mcp_request )->get_data();
		$this->assertFalse( $data['result']['isError'] ?? true, 'wp_list_approvals must not error for this key' );

		$text = $data['result']['content'][0]['text'] ?? '';
		$this->assertStringNotContainsString( $hidden_id, $text, 'the product approval id must not appear in wp_list_approvals output' );
		$this->assertStringNotContainsString( 'SECRET PRODUCT Zeta', $text, 'the product title must not leak through wp_list_approvals' );
		$this->assertStringContainsString( $visible_id, $text, 'the admin-category approval must still appear' );
	}

	// ── Mcpwp_REST_Approvals::get_approval() ─────────────────────────────────

	public function test_get_approval_denies_as_not_found_not_forbidden(): void {
		$hidden_id = $this->seed_approval();

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin' ) );

		$result = $controller->get_approval( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals/' . $hidden_id, array( 'id' => $hidden_id ) ) );
		$this->assert_not_found( $result );
	}

	public function test_get_approval_denial_is_byte_identical_to_a_genuinely_missing_id(): void {
		$hidden_id = $this->seed_approval();

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin' ) );

		$hidden_result  = $controller->get_approval( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals/' . $hidden_id, array( 'id' => $hidden_id ) ) );
		$missing_result = $controller->get_approval( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals/does-not-exist', array( 'id' => 'does-not-exist' ) ) );

		$this->assertSame( $missing_result->get_error_code(), $hidden_result->get_error_code() );
		$this->assertSame( $missing_result->get_error_message(), $hidden_result->get_error_message() );
		$this->assertSame( $missing_result->get_error_data(), $hidden_result->get_error_data() );
	}

	public function test_get_approval_allowed_once_the_key_holds_the_category(): void {
		$id = $this->seed_approval();

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin', 'woocommerce' ) );

		$response = $controller->get_approval( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals/' . $id, array( 'id' => $id ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $id, $response->get_data()['id'] );
	}

	// ── approve / reject / apply / rollback ──────────────────────────────────

	public function test_approve_denies_as_not_found_and_leaves_status_unchanged(): void {
		$id = $this->seed_approval();

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin' ) );

		$result = $controller->approve_approval( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/' . $id . '/approve', array( 'id' => $id ) ) );
		$this->assert_not_found( $result );
		$this->assertSame( 'pending', Mcpwp_Approvals::get_request( $id )['status'] );
	}

	public function test_approve_denial_never_leaks_via_self_approval_even_for_the_same_requester_key(): void {
		// The category guard must run BEFORE check_self_approval(): if it ran
		// after, a same-key requester would get 'self_approval_forbidden'
		// instead of 'approval_not_found' for a category-hidden record —
		// disclosing that the record exists (only an EXISTING record can
		// trigger that check). #1022 round 2's BLOCK-R2-1 fixed the same
		// class of ordering bug on the destructive gate's own defer/apply
		// path.
		$id = $this->seed_approval( array( 'requested_by_key_id' => 'forced' ) );

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin' ), 'forced' );

		$result = $controller->approve_approval( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/' . $id . '/approve', array( 'id' => $id ) ) );
		$this->assert_not_found( $result );
	}

	public function test_approve_succeeds_once_the_key_holds_the_category(): void {
		$id = $this->seed_approval();

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin', 'woocommerce' ) );

		$response = $controller->approve_approval( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/' . $id . '/approve', array( 'id' => $id ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'approved', $response->get_data()['status'] );
	}

	public function test_reject_denies_as_not_found(): void {
		$id = $this->seed_approval();

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin' ) );

		$result = $controller->reject_approval( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/' . $id . '/reject', array( 'id' => $id ) ) );
		$this->assert_not_found( $result );
		$this->assertSame( 'pending', Mcpwp_Approvals::get_request( $id )['status'] );
	}

	public function test_reject_succeeds_once_the_key_holds_the_category(): void {
		$id = $this->seed_approval();

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin', 'woocommerce' ) );

		$response = $controller->reject_approval( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/' . $id . '/reject', array( 'id' => $id ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 'rejected', $response->get_data()['status'] );
	}

	public function test_apply_denies_as_not_found_even_when_already_approved(): void {
		$id = $this->seed_approval( array( 'status' => 'approved' ) );

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin' ) );

		$result = $controller->apply_approval( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/' . $id . '/apply', array( 'id' => $id ) ) );
		$this->assert_not_found( $result );
		$this->assertSame( 'approved', Mcpwp_Approvals::get_request( $id )['status'] );
	}

	public function test_rollback_denies_as_not_found_even_when_already_applied(): void {
		// Deliberately in a state (already 'applied') where, without the
		// #1025 fix, rollback_request() would happily proceed past its own
		// status check — proving the category guard runs first, unconditionally,
		// not merely "whenever the normal flow happens to reach it".
		$id = $this->seed_approval( array( 'status' => 'applied' ) );

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin' ) );

		$result = $controller->rollback_approval( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/' . $id . '/rollback', array( 'id' => $id ) ) );
		$this->assert_not_found( $result );
		$this->assertSame( 'applied', Mcpwp_Approvals::get_request( $id )['status'] );
	}

	public function test_rollback_reaches_real_logic_once_the_key_holds_the_category(): void {
		// A destructive operation has no rollback by design (there is
		// nothing to undo) — apply_payload() answers a specific, different
		// error for that. Getting THIS error (not 'approval_not_found')
		// proves the category guard let the call through to the real
		// business logic.
		$id = $this->seed_approval( array( 'status' => 'applied' ) );

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin', 'woocommerce' ) );

		$result = $controller->rollback_approval( new WP_REST_Request( 'POST', '/mcpwp/v1/approvals/' . $id . '/rollback', array( 'id' => $id ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'destructive_rollback_unavailable', $result->get_error_code() );
	}
}
