<?php
/**
 * Regression tests — #1025.
 *
 * Found in round 3 of the adversarial review of PR #1022 (predates that PR).
 * /approvals is gated only the blanket 'admin' category
 * (get_route_category_map()), so a custom key holding 'admin' but not, say,
 * 'woocommerce' or 'elementor-templates' could list/get/approve/reject/apply/
 * roll back a request whose target that same key's role denies it
 * everywhere else — live evidence in the issue: an elementor_library
 * "Arch T" archetype's type, title and payload route, visible through a
 * plain GET /approvals to a key with no elementor-templates access at all.
 *
 * Fix: Mcpwp_Approvals::category_for_approval() maps an approval record to
 * the tool category that governs its TARGET (reusing the #1020/#1022
 * chokepoint — Mcpwp_REST_API::category_for_post_type() /
 * ::get_category_for_route() — rather than a second one), and
 * Mcpwp_REST_Approvals applies caller_may_read_category() to that result on
 * every door: list_approvals() (filtered before its own $limit slice, no
 * separate total anywhere in that response to contradict it), and
 * get_approval()/approve_approval()/reject_approval()/apply_approval()/
 * rollback_approval() (all via resolve_visible_approval(), returning the
 * exact same WP_Error a genuinely-missing id returns —
 * Mcpwp_Approvals::not_found_error() — so a hidden approval cannot be told
 * apart from a missing one). The visibility guard runs BEFORE
 * check_self_approval() on approve/apply: running it after would let that
 * check's own "same key requested it" branch disclose that a category-hidden
 * record exists, the same ordering bug #1022 round 2's BLOCK-R2-1 fixed for
 * the destructive gate's own defer/apply path.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';

final class ApprovalCategoryGateTest extends TestCase {

	private const ELEMENTOR_LIBRARY_ID = 9101;
	private const ORDINARY_POST_ID     = 9102;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['_mcpwp_test_posts']       = array(
			self::ELEMENTOR_LIBRARY_ID => new WP_Post(
				array(
					'ID'          => self::ELEMENTOR_LIBRARY_ID,
					'post_type'   => 'elementor_library',
					'post_title'  => 'Arch T',
					'post_name'   => 'arch-t',
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
	}

	/**
	 * Seed a raw approval record directly into the store option — same
	 * technique OAuthSelfApprovalTest::seed_approval() already uses — so
	 * each test controls exactly the action/resource/payload shape it needs
	 * without going through every creator function's own side effects
	 * (flood caps, event emission — covered separately below for /events).
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
				'id'    => 8701,
				'title' => 'Woolly Hoodie',
			),
			'diff'                => array( 'summary' => 'No undo.' ),
			'payload'             => array(
				'route'  => '/mcpwp/v1/woocommerce/products/8701',
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

		$items       = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
		$items       = is_array( $items ) ? $items : array();
		$items[ $id ] = $record;
		update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );

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

	// ── Mcpwp_Approvals::category_for_approval(): the chokepoint itself ─────

	public function test_destructive_operation_classifies_by_its_stored_route(): void {
		$id = $this->seed_approval(
			array(
				'action'  => 'destructive_operation',
				'payload' => array( 'route' => '/mcpwp/v1/woocommerce/products/8701' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( 'woocommerce', Mcpwp_Approvals::category_for_approval( $record ) );
	}

	public function test_destructive_operation_route_beats_the_bare_type_fallback(): void {
		// resource.type='attachment' is PUBLIC, so category_for_post_type()'s
		// own bare-type fallback would say 'content' — the real /media route
		// requires 'media'. The stored route must win.
		$id = $this->seed_approval(
			array(
				'action'   => 'destructive_operation',
				'operation' => 'delete_media',
				'resource' => array( 'type' => 'attachment', 'id' => 55, 'title' => 'hoodie.jpg' ),
				'payload'  => array( 'route' => '/mcpwp/v1/media/55' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( 'media', Mcpwp_Approvals::category_for_approval( $record ) );
	}

	public function test_destructive_operation_on_a_non_post_resource_classifies_by_route(): void {
		$id = $this->seed_approval(
			array(
				'action'   => 'destructive_operation',
				'operation' => 'delete_user',
				'resource' => array( 'type' => 'user', 'id' => 55, 'title' => 'jdoe' ),
				'payload'  => array( 'route' => '/mcpwp/v1/users/55' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( 'admin', Mcpwp_Approvals::category_for_approval( $record ) );
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
		$this->assertSame( 'woocommerce', Mcpwp_Approvals::category_for_approval( $record ) );
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
		$this->assertSame( 'staging', Mcpwp_Approvals::category_for_approval( $record ) );
	}

	public function test_post_content_update_resolves_the_live_post_type_not_the_hardcoded_resource_type(): void {
		// create_post_content_request() always stores resource.type='post'
		// regardless of the resolved post's real type (#1024's own finding
		// for the /blocks and /content-graph doors that call it on a
		// non-'post' target) — the LIVE post_type must win, or an
		// Elementor template edited through one of those doors would read
		// back here as ordinary 'content'.
		$id = $this->seed_approval(
			array(
				'action'   => 'post_content_update',
				'resource' => array( 'type' => 'post', 'id' => self::ELEMENTOR_LIBRARY_ID, 'title' => 'Arch T' ),
				'payload'  => array( 'after_content' => 'new' ),
				'rollback' => array( 'before_content' => 'old', 'before_hash' => hash( 'sha256', 'old' ) ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( 'elementor-templates', Mcpwp_Approvals::category_for_approval( $record ) );
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
		$this->assertSame( 'content', Mcpwp_Approvals::category_for_approval( $record ) );
	}

	public function test_falls_back_to_admin_when_the_target_post_is_gone(): void {
		$id = $this->seed_approval(
			array(
				'action'   => 'post_content_update',
				'resource' => array( 'type' => 'post', 'id' => 999999, 'title' => 'Deleted since' ),
				'payload'  => array( 'after_content' => 'new' ),
			)
		);
		$record = Mcpwp_Approvals::get_request( $id );
		$this->assertSame( 'admin', Mcpwp_Approvals::category_for_approval( $record ) );
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
		$hidden_id = $this->seed_approval(
			array(
				'operation' => 'delete_product',
				'resource'  => array( 'type' => 'product', 'id' => 8701, 'title' => 'Woolly Hoodie' ),
				'payload'   => array( 'route' => '/mcpwp/v1/woocommerce/products/8701' ),
			)
		);

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin' ) );

		$response = $controller->list_approvals( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals', array( 'limit' => 50, 'order' => 'date' ) ) );
		$ids      = array_column( $response->get_data()['approvals'], 'id' );

		$this->assertContains( $visible_id, $ids );
		$this->assertNotContains( $hidden_id, $ids );
	}

	public function test_list_approvals_shows_it_once_the_key_holds_the_category(): void {
		$hidden_id = $this->seed_approval(
			array(
				'operation' => 'delete_product',
				'resource'  => array( 'type' => 'product', 'id' => 8701, 'title' => 'Woolly Hoodie' ),
				'payload'   => array( 'route' => '/mcpwp/v1/woocommerce/products/8701' ),
			)
		);

		$controller = new Mcpwp_REST_Approvals();
		$this->force_key_record( $controller, array( 'admin', 'woocommerce' ) );

		$response = $controller->list_approvals( new WP_REST_Request( 'GET', '/mcpwp/v1/approvals', array( 'limit' => 50, 'order' => 'date' ) ) );
		$ids      = array_column( $response->get_data()['approvals'], 'id' );

		$this->assertContains( $hidden_id, $ids );
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
