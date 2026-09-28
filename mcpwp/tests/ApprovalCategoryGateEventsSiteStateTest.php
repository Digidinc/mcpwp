<?php
/**
 * Regression tests — #1025 (siblings found while fixing /approvals).
 *
 * An approval.* event (Mcpwp_Approvals::emit_event()) stores the full
 * public approval record verbatim at payload.approval — the exact same
 * type/title/route leak fixed on /approvals itself, reachable through a
 * DIFFERENT category gate: GET /events (wp_list_mcp_events) is classified
 * 'webhooks' in get_route_category_map(), not 'admin', so a key that never
 * held 'admin' at all could still read it there. Mcpwp_Site_State's
 * approvals and events summaries (backing GET /site-state, category 'site')
 * embed the same two arrays a third time.
 *
 * Fix: Mcpwp_Event_Store::list_events() and Mcpwp_Approvals::list_requests()
 * both take an optional `category_allowed` callback (the caller's own
 * caller_may_read_category(), since only the REST controller holding the
 * request has that context) and filter BEFORE computing any total/count
 * derived from the same list, so a hidden item is never contradicted by a
 * count that still includes it. Mcpwp_Site_State::get_snapshot() threads the
 * same callback through to both.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-event-store.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-state.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-webhooks.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';

final class ApprovalCategoryGateEventsSiteStateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
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
	private function create_destructive_approval( string $operation, array $resource, string $route ): array {
		return Mcpwp_Approvals::create_destructive_request(
			$operation,
			array(
				'route'  => $route,
				'method' => 'DELETE',
				'params' => array(),
				'target' => array( 'exists' => true ),
			),
			$resource,
			array(
				'title'               => 'Test: ' . $operation,
				'requested_by_key_id' => 'other-key',
			)
		);
	}

	// ── GET /events, wp_list_mcp_events ──────────────────────────────────────

	public function test_list_events_hides_an_approval_event_whose_target_category_the_key_lacks(): void {
		$this->create_destructive_approval( 'delete_user', array( 'type' => 'user', 'id' => 1, 'title' => 'jdoe' ), '/mcpwp/v1/users/1' );
		$this->create_destructive_approval( 'delete_product', array( 'type' => 'product', 'id' => 8701, 'title' => 'Woolly Hoodie' ), '/mcpwp/v1/woocommerce/products/8701' );
		// A non-approval event must stay visible regardless of category
		// restriction — '' (not gate-worthy) is always shown.
		Mcpwp_Event_Store::emit( 'content.publish_spike', array(), array( 'risk_level' => 'low' ) );

		$controller = new Mcpwp_REST_Webhooks();
		// 'webhooks' is what /events itself requires; 'admin' is the delete_user
		// approval's own target category (route /users => 'admin') — held here
		// so that approval event stays visible and only the product one (which
		// needs 'woocommerce', deliberately not granted) is the hidden one.
		$this->force_key_record( $controller, array( 'webhooks', 'admin' ) );

		$response = $controller->list_events( new WP_REST_Request( 'GET', '/mcpwp/v1/events', array( 'type' => '', 'limit' => 50 ) ) );
		$data     = $response->get_data();
		$types    = array_column( $data['events'], 'type' );

		$this->assertContains( 'content.publish_spike', $types );
		$this->assertContains( 'approval.created', $types, 'the admin-target approval event stays visible' );

		foreach ( $data['events'] as $event ) {
			if ( 'approval.created' === $event['type'] ) {
				$this->assertSame( 'jdoe', $event['payload']['approval']['resource']['title'] ?? null, 'only the visible (user) approval event may appear' );
			}
		}

		// #1025: `total` must be recomputed on the FILTERED list, not the raw
		// one — an unfiltered total next to a filtered `events` array would
		// itself be a count oracle for the approval this filter hides.
		$this->assertSame( count( $data['events'] ), $data['total'] );
	}

	public function test_list_events_shows_the_approval_event_once_the_key_holds_the_category(): void {
		$this->create_destructive_approval( 'delete_product', array( 'type' => 'product', 'id' => 8701, 'title' => 'Woolly Hoodie' ), '/mcpwp/v1/woocommerce/products/8701' );

		$controller = new Mcpwp_REST_Webhooks();
		$this->force_key_record( $controller, array( 'webhooks', 'woocommerce' ) );

		$response = $controller->list_events( new WP_REST_Request( 'GET', '/mcpwp/v1/events', array( 'type' => '', 'limit' => 50 ) ) );
		$data     = $response->get_data();
		$types    = array_column( $data['events'], 'type' );

		$this->assertContains( 'approval.created', $types );
		$this->assertSame( count( $data['events'] ), $data['total'] );
	}

	// ── GET /site-state ───────────────────────────────────────────────────────

	public function test_site_state_hides_approvals_and_events_the_key_may_not_read(): void {
		$this->create_destructive_approval( 'delete_user', array( 'type' => 'user', 'id' => 1, 'title' => 'jdoe' ), '/mcpwp/v1/users/1' );
		$this->create_destructive_approval( 'delete_product', array( 'type' => 'product', 'id' => 8701, 'title' => 'Woolly Hoodie' ), '/mcpwp/v1/woocommerce/products/8701' );

		$controller = new Mcpwp_REST_Site_Info();
		$this->force_key_record( $controller, array( 'site', 'admin' ) );

		$response = $controller->get_site_state( new WP_REST_Request( 'GET', '/mcpwp/v1/site-state', array() ) );
		$snapshot = $response->get_data();

		$pending_titles = array_column( $snapshot['approvals']['pending_items'], 'resource' );
		$pending_titles = array_column( $pending_titles, 'title' );
		$this->assertContains( 'jdoe', $pending_titles );
		$this->assertNotContains( 'Woolly Hoodie', $pending_titles );

		// #1025: `counts.pending` must match what's actually visible in
		// pending_items — both are derived from the SAME filtered list, so
		// they cannot disagree (a count next to a filtered list is itself
		// the oracle this exists to prevent).
		$this->assertSame( count( $snapshot['approvals']['pending_items'] ), $snapshot['approvals']['counts']['pending'] );

		$event_types = array_column( $snapshot['events']['items'], 'type' );
		$hidden_event_present = false;
		foreach ( $snapshot['events']['items'] as $event ) {
			if ( 'approval.created' === $event['type'] && 'Woolly Hoodie' === ( $event['payload']['approval']['resource']['title'] ?? null ) ) {
				$hidden_event_present = true;
			}
		}
		$this->assertFalse( $hidden_event_present, 'the product approval event must not appear in the site-state snapshot' );
		$this->assertSame( count( $snapshot['events']['items'] ), $snapshot['events']['count'] );
	}

	public function test_site_state_shows_everything_for_an_unrestricted_admin_role_key(): void {
		$this->create_destructive_approval( 'delete_product', array( 'type' => 'product', 'id' => 8701, 'title' => 'Woolly Hoodie' ), '/mcpwp/v1/woocommerce/products/8701' );

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
}
