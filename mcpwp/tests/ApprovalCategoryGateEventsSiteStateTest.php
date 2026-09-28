<?php
/**
 * Regression tests — #1025 (siblings found while fixing /approvals).
 *
 * ROUND 1: an approval.* event (Mcpwp_Approvals::emit_event()) stores the
 * full public approval record verbatim at payload.approval — the exact same
 * type/title/route leak fixed on /approvals itself, reachable through a
 * DIFFERENT category gate: GET /events (wp_list_mcp_events) is classified
 * 'webhooks' in get_route_category_map(), not 'admin', so a key that never
 * held 'admin' at all could still read it there. Mcpwp_Site_State's
 * approvals and events summaries (backing GET /site-state, category 'site')
 * embed the same two arrays a third time.
 *
 * ROUND 2: both surfaces defer entirely to
 * Mcpwp_Approvals::is_approval_visible_to()/categories_for_approval() (see
 * ApprovalCategoryGateTest.php's docblock for BLOCK-1/WARN-1/WARN-2), so a
 * destructive delete queued through a GENERIC door (DELETE /posts/{id},
 * DELETE /content/{type}/{id}) is hidden here too unless the caller holds
 * BOTH the route category and the target's real post-type category. This
 * file adds that scenario for /events and /site-state, plus WARN-2:
 * /content-coherence's aggregate counts must be computed from the SAME
 * filtered set, not the raw one.
 *
 * Fix (unchanged from round 1): Mcpwp_Event_Store::list_events() and
 * Mcpwp_Approvals::list_requests() both take an optional `category_allowed`
 * callback (the caller's own caller_may_read_category(), since only the
 * REST controller holding the request has that context) and filter BEFORE
 * computing any total/count derived from the same list, so a hidden item is
 * never contradicted by a count that still includes it. Mcpwp_Site_State::
 * get_snapshot() threads the same callback through to both, and
 * Mcpwp_Content_Coherence::get_report() (WARN-2) forwards it straight to
 * get_snapshot() too.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-event-store.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-state.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-content-coherence.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-webhooks.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';

final class ApprovalCategoryGateEventsSiteStateTest extends TestCase {

	private const PRODUCT_ID           = 8701;
	private const ELEMENTOR_LIBRARY_ID = 9101;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']            = false;
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_public_post_types']  = array( 'product' );
		$GLOBALS['_mcpwp_test_posts']              = array(
			self::PRODUCT_ID           => new WP_Post(
				array(
					'ID'          => self::PRODUCT_ID,
					'post_type'   => 'product',
					'post_title'  => 'Woolly Hoodie',
					'post_name'   => 'woolly-hoodie',
					'post_status' => 'publish',
				)
			),
			self::ELEMENTOR_LIBRARY_ID => new WP_Post(
				array(
					'ID'          => self::ELEMENTOR_LIBRARY_ID,
					'post_type'   => 'elementor_library',
					'post_title'  => 'Arch T',
					'post_name'   => 'arch-t',
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
	}

	private function force_key_record( object $controller, array $categories, string $key_id = 'forced' ): void {
		$ref = new ReflectionProperty( get_class( $controller ), 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue(
			$controller,
			array(
				'id'              => $key_id,
				'label'           => 'forced',
				'scopes'          => array( 'read', 'write', 'admin' ),
				'role'            => 'custom',
				'tool_categories' => $categories,
			)
		);
	}

	/** Creates a real destructive-request approval, so emit_event() actually fires. */
	private function create_destructive_approval( string $operation, array $resource, string $route, array $target = array( 'exists' => true ) ): array {
		return Mcpwp_Approvals::create_destructive_request(
			$operation,
			array(
				'route'  => $route,
				'method' => 'DELETE',
				'params' => array(),
				'target' => $target,
			),
			$resource,
			array(
				'title'               => 'Test: ' . $operation,
				'requested_by_key_id' => 'other-key',
			)
		);
	}

	/** BLOCK-1 scenario: the exact shape DELETE /posts/{id} defers with for a product. */
	private function create_product_via_generic_posts_door(): array {
		return $this->create_destructive_approval(
			'delete_post',
			array( 'type' => 'product', 'id' => self::PRODUCT_ID, 'title' => 'Woolly Hoodie' ),
			'/mcpwp/v1/posts/' . self::PRODUCT_ID,
			array( 'exists' => true, 'type' => 'product' )
		);
	}

	/** BLOCK-1 scenario: the exact shape DELETE /content/{type}/{id} defers with for an Elementor archetype. */
	private function create_elementor_library_via_generic_content_door(): array {
		return $this->create_destructive_approval(
			'delete_content',
			array( 'type' => 'elementor_library', 'id' => self::ELEMENTOR_LIBRARY_ID, 'title' => 'Arch T' ),
			'/mcpwp/v1/content/elementor_library/' . self::ELEMENTOR_LIBRARY_ID,
			array( 'exists' => true, 'type' => 'elementor_library' )
		);
	}

	// ── GET /events, wp_list_mcp_events ──────────────────────────────────────

	public function test_list_events_hides_an_approval_event_whose_target_category_the_key_lacks(): void {
		$this->create_destructive_approval( 'delete_user', array( 'type' => 'user', 'id' => 1, 'title' => 'jdoe' ), '/mcpwp/v1/users/1' );
		$this->create_product_via_generic_posts_door();
		// A non-approval event must stay visible regardless of category
		// restriction — '' (not gate-worthy) is always shown.
		Mcpwp_Event_Store::emit( 'content.publish_spike', array(), array( 'risk_level' => 'low' ) );

		$controller = new Mcpwp_REST_Webhooks();
		// 'webhooks' is what /events itself requires; 'admin' is the delete_user
		// approval's own target category (route /users => 'admin') — held here
		// so that approval event stays visible and only the product one
		// (route classifies 'content', but the target's real category
		// 'woocommerce' is deliberately not granted) is the hidden one.
		$this->force_key_record( $controller, array( 'webhooks', 'admin', 'content' ) );

		$response = $controller->list_events( new WP_REST_Request( 'GET', '/mcpwp/v1/events', array( 'type' => '', 'limit' => 50 ) ) );
		$data     = $response->get_data();
		$types    = array_column( $data['events'], 'type' );

		$this->assertContains( 'content.publish_spike', $types );
		$this->assertContains( 'approval.created', $types, 'the admin-target approval event stays visible' );

		foreach ( $data['events'] as $event ) {
			if ( 'approval.created' === $event['type'] ) {
				$this->assertSame( 'jdoe', $event['payload']['approval']['resource']['title'] ?? null, 'only the visible (user) approval event may appear — the product one (BLOCK-1: generic /posts door) must not' );
			}
		}

		// #1025: `total` must be recomputed on the FILTERED list, not the raw
		// one — an unfiltered total next to a filtered `events` array would
		// itself be a count oracle for the approval this filter hides.
		$this->assertSame( count( $data['events'] ), $data['total'] );
	}

	public function test_list_events_shows_the_generic_door_event_once_the_key_holds_BOTH_categories(): void {
		$this->create_elementor_library_via_generic_content_door();

		$controller = new Mcpwp_REST_Webhooks();
		// Holding only the route category ('content') is not enough (BLOCK-1) —
		// 'elementor-templates' (the real target type) is also required.
		$partial = new Mcpwp_REST_Webhooks();
		$this->force_key_record( $partial, array( 'webhooks', 'content' ) );
		$partial_response = $partial->list_events( new WP_REST_Request( 'GET', '/mcpwp/v1/events', array( 'type' => '', 'limit' => 50 ) ) );
		$this->assertNotContains( 'approval.created', array_column( $partial_response->get_data()['events'], 'type' ), 'route category alone must not be enough (BLOCK-1)' );

		$this->force_key_record( $controller, array( 'webhooks', 'content', 'elementor-templates' ) );
		$response = $controller->list_events( new WP_REST_Request( 'GET', '/mcpwp/v1/events', array( 'type' => '', 'limit' => 50 ) ) );
		$data     = $response->get_data();
		$types    = array_column( $data['events'], 'type' );

		$this->assertContains( 'approval.created', $types );
		$this->assertSame( count( $data['events'] ), $data['total'] );
	}

	// ── GET /site-state ───────────────────────────────────────────────────────

	public function test_site_state_hides_approvals_and_events_the_key_may_not_read(): void {
		$this->create_destructive_approval( 'delete_user', array( 'type' => 'user', 'id' => 1, 'title' => 'jdoe' ), '/mcpwp/v1/users/1' );
		$this->create_product_via_generic_posts_door();

		$controller = new Mcpwp_REST_Site_Info();
		$this->force_key_record( $controller, array( 'site', 'admin', 'content' ) );

		$response = $controller->get_site_state( new WP_REST_Request( 'GET', '/mcpwp/v1/site-state', array() ) );
		$snapshot = $response->get_data();

		$pending_titles = array_column( $snapshot['approvals']['pending_items'], 'resource' );
		$pending_titles = array_column( $pending_titles, 'title' );
		$this->assertContains( 'jdoe', $pending_titles );
		$this->assertNotContains( 'Woolly Hoodie', $pending_titles, 'a [site, admin, content] key must not see a product deletion queued through the generic /posts door (BLOCK-1)' );

		// #1025: `counts.pending` must match what's actually visible in
		// pending_items — both are derived from the SAME filtered list, so
		// they cannot disagree (a count next to a filtered list is itself
		// the oracle this exists to prevent).
		$this->assertSame( count( $snapshot['approvals']['pending_items'] ), $snapshot['approvals']['counts']['pending'] );

		foreach ( $snapshot['events']['items'] as $event ) {
			if ( 'approval.created' === $event['type'] ) {
				$this->assertNotSame( 'Woolly Hoodie', $event['payload']['approval']['resource']['title'] ?? null );
			}
		}
		$this->assertSame( count( $snapshot['events']['items'] ), $snapshot['events']['count'] );
	}

	public function test_site_state_shows_everything_for_an_unrestricted_admin_role_key(): void {
		$this->create_product_via_generic_posts_door();

		$controller = new Mcpwp_REST_Site_Info();
		// role 'admin' -> resolve_key_tool_categories() returns array() ->
		// unrestricted; caller_may_read_category() allows everything.
		$ref = new ReflectionProperty( Mcpwp_REST_Site_Info::class, 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue( $controller, array( 'id' => 'admin-key', 'role' => 'admin', 'scopes' => array( 'read', 'write', 'admin' ) ) );

		$response = $controller->get_site_state( new WP_REST_Request( 'GET', '/mcpwp/v1/site-state', array() ) );
		$snapshot = $response->get_data();

		$pending_titles = array_column( $snapshot['approvals']['pending_items'], 'resource' );
		$pending_titles = array_column( $pending_titles, 'title' );
		$this->assertContains( 'Woolly Hoodie', $pending_titles );
	}

	// ── WARN-2: /content-coherence's counts must match the filtered set ────────

	public function test_content_coherence_pending_count_matches_the_filtered_set_for_a_seo_only_key(): void {
		// Neither approval's target category is held by a seo-only key.
		$this->create_destructive_approval( 'delete_user', array( 'type' => 'user', 'id' => 1, 'title' => 'jdoe' ), '/mcpwp/v1/users/1' );
		$this->create_product_via_generic_posts_door();

		$controller = new Mcpwp_REST_Site_Info();
		$this->force_key_record( $controller, array( 'seo' ) );

		$response = $controller->get_content_coherence( new WP_REST_Request( 'GET', '/mcpwp/v1/content-coherence', array() ) );
		$report   = $response->get_data();

		// Before WARN-2's fix, this read the UNFILTERED counts (2 pending, 2
		// high-risk events — both approvals are destructive_operation,
		// emitted at 'high' risk) straight off an uncategorized
		// Mcpwp_Site_State::get_snapshot() call — telling a seo-only key
		// exactly how many approvals and high-risk events it cannot even see
		// exist.
		$this->assertStringContainsString( '0 pending approvals and 0 high-risk recent events', $report['dimensions']['governance']['evidence'] );

		// Round-trip: an admin-role (unrestricted) key sees the real counts.
		$unrestricted = new Mcpwp_REST_Site_Info();
		$ref          = new ReflectionProperty( Mcpwp_REST_Site_Info::class, 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue( $unrestricted, array( 'id' => 'admin-key', 'role' => 'admin', 'scopes' => array( 'read', 'write', 'admin' ) ) );
		$full_report = $unrestricted->get_content_coherence( new WP_REST_Request( 'GET', '/mcpwp/v1/content-coherence', array() ) )->get_data();
		$this->assertStringContainsString( '2 pending approvals and 2 high-risk recent events', $full_report['dimensions']['governance']['evidence'] );
	}
}
