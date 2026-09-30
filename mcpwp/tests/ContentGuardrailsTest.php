<?php
/**
 * Coverage for #957 — guardrails against scaled and duplicate publishing.
 *
 * Rewritten after adversarial review of the first version: the enforcement
 * chokepoint is now Mcpwp_Content_Guardrails::on_insert_post_data(), a
 * wp_insert_post_data filter that fires for EVERY wp_insert_post() AND
 * wp_update_post() call while enter_context() has been called for this
 * request (mirroring what trait-mcpwp-api-auth.php's verify_api_key() does
 * for a real MCPWP-authenticated request) — not a function each create/
 * publish call site remembers to call. Counting happens on
 * transition_post_status, atomically, per UTC day.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-content-guardrails.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-seo-audit-store.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-integration-manager.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-multilang.php';

final class ContentGuardrailsTest extends TestCase {

	/** @var array Hook registries as this test found them — restored in tearDown(). */
	private $saved_hooks = array();

	protected function setUp(): void {
		$this->saved_hooks = array(
			'filters' => $GLOBALS['mcpwp_test_filters'] ?? array(),
			'actions' => $GLOBALS['mcpwp_test_actions'] ?? array(),
		);
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['_mcpwp_test_posts']             = array();
		$GLOBALS['_mcpwp_test_meta']              = array();
		$GLOBALS['mcpwp_test_current_user']       = 2;
		$GLOBALS['mcpwp_test_actions']            = array();
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['mcpwp_test_doing_ajax']         = false;
		Mcpwp_Content_Guardrails::reset_context_for_tests();
		Mcpwp_Content_Guardrails::register_hooks();
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['mcpwp_test_is_admin'] = false;
		$GLOBALS['mcpwp_test_doing_ajax'] = false;
		Mcpwp_Content_Guardrails::reset_context_for_tests();
		// setUp() registers the guardrail hooks and several tests add their
		// own closures (event captures by reference, filters); none of them
		// may outlive this test (#1084). Put the registries back exactly as
		// this test found them.
		$GLOBALS['mcpwp_test_filters'] = $this->saved_hooks['filters'];
		$GLOBALS['mcpwp_test_actions'] = $this->saved_hooks['actions'];
	}

	private function words( $count, $prefix = 'word' ) {
		$out = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = $prefix . $i;
		}
		return implode( ' ', $out );
	}

	private function seed_published( $id, $type, $title, $content ) {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => $type,
			'post_title'        => $title,
			'post_name'         => sanitize_title( $title ),
			'post_status'       => 'publish',
			'post_content'      => $content,
			'post_excerpt'      => '',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			'post_date'         => '2026-06-06 00:00:00',
			'post_modified'     => '2026-06-06 00:00:00',
			'post_date_gmt'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
		);
	}

	private function seed_draft( $id, $type, $title, $content ) {
		$this->seed_published( $id, $type, $title, $content );
		$GLOBALS['_mcpwp_test_posts'][ $id ]->post_status = 'draft';
	}

	// -------------------------------------------------------------------------
	// Context gating

	public function test_disabled_outside_mcpwp_context_never_touches_a_write(): void {
		// in_context() is false by default (no enter_context() call) —
		// exactly the "wp-admin/WP-CLI/another plugin" case (#957
		// adversarial review design fix).
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1 ) );

		wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'A', 'post_status' => 'publish', 'post_content' => $this->words( 5 ) ) );
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'B', 'post_status' => 'publish', 'post_content' => $this->words( 5 ) ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
		$this->assertSame( 0, Mcpwp_Content_Guardrails::get_daily_count() );
	}

	// -------------------------------------------------------------------------
	// Rule 1: publishing ramp — including draft -> publish via UPDATE (BLOCK-1)

	public function test_ramp_under_cap_allows_publish(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 3, 'min_word_count' => 0 ) );

		for ( $i = 0; $i < 2; $i++ ) {
			$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => "Post $i", 'post_status' => 'publish', 'post_content' => $this->words( 10, "p{$i}w" ) ) );
			$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
		}

		$this->assertSame( 2, Mcpwp_Content_Guardrails::get_daily_count() );
	}

	public function test_ramp_over_cap_downgrades_create_to_draft(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 2, 'min_word_count' => 0 ) );

		for ( $i = 0; $i < 2; $i++ ) {
			wp_insert_post( array( 'post_type' => 'post', 'post_title' => "Post $i", 'post_status' => 'publish', 'post_content' => $this->words( 10, "p{$i}w" ) ) );
		}

		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Post 3', 'post_status' => 'publish', 'post_content' => $this->words( 10, 'p3w' ) ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
		$this->assertSame( 2, Mcpwp_Content_Guardrails::get_daily_count(), 'the rejected publish must not itself count' );

		$response = array( 'id' => $id );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertTrue( $response['guardrails']['downgraded'] );
		$this->assertNotEmpty( $response['warnings'] );
	}

	public function test_block1_draft_to_publish_via_update_post_is_gated(): void {
		// BLOCK-1: create as draft (unguarded, correctly), then UPDATE to
		// publish — the exact bypass the adversarial review found. Both
		// wp_insert_post() and wp_update_post() run through the SAME
		// wp_insert_post_data filter, so this is gated identically to create.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1, 'min_word_count' => 0 ) );

		wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Uses the cap', 'post_status' => 'publish', 'post_content' => $this->words( 10 ) ) );

		$draft_id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Created quietly', 'post_status' => 'draft', 'post_content' => $this->words( 10 ) ) );
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $draft_id ]->post_status );

		wp_update_post( array( 'ID' => $draft_id, 'post_status' => 'publish' ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $draft_id ]->post_status, 'the update-to-publish bypass must be gated exactly like create' );
		$this->assertSame( 1, Mcpwp_Content_Guardrails::get_daily_count() );
	}

	public function test_update_post_model_class_draft_to_publish_over_cap(): void {
		// Same bypass, exercised through the actual REST-facing model class
		// (Mcpwp_Posts::update_post()) rather than a raw wp_update_post()
		// call — proves the real call site, not just the primitive.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 0, 'min_word_count' => 0 ) );
		// 0 is clamped to 1 by sanitize_settings — force a stronger cap breach:
		wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Cap filler', 'post_status' => 'publish', 'post_content' => $this->words( 10 ) ) );

		$this->seed_draft( 501, 'post', 'Draft Post', $this->words( 50 ) );

		$posts = new Mcpwp_Posts();
		$result = $posts->update_post( 501, array( 'status' => 'publish' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] ?? $GLOBALS['_mcpwp_test_posts'][501]->post_status );
	}

	public function test_ramp_applies_regardless_of_key_scope(): void {
		// #957 rule 1: "admin keys included — this is a site-level safety
		// valve." on_insert_post_data() takes no key-scope/identity argument
		// at all — enter_context() is boolean, there is nowhere for an
		// exemption to hide.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1, 'min_word_count' => 0 ) );

		wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'First', 'post_status' => 'publish', 'post_content' => $this->words( 10 ) ) );
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Second (admin key)', 'post_status' => 'publish', 'post_content' => $this->words( 10 ) ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
	}

	public function test_drafts_are_never_ramp_capped(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1 ) );

		wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Uses the cap', 'post_status' => 'publish', 'post_content' => $this->words( 200 ) ) );

		for ( $i = 0; $i < 5; $i++ ) {
			$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => "Draft $i", 'post_status' => 'draft', 'post_content' => 'short' ) );
			$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
		}
	}

	public function test_resaving_an_already_published_post_is_not_a_new_publish_event(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1, 'min_word_count' => 0 ) );

		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Live', 'post_status' => 'publish', 'post_content' => $this->words( 10 ) ) );
		$this->assertSame( 1, Mcpwp_Content_Guardrails::get_daily_count() );

		// A plain edit of an already-published post — status stays
		// 'publish' throughout — must not consume ramp allowance again.
		wp_update_post( array( 'ID' => $id, 'post_title' => 'Live (edited)', 'post_status' => 'publish' ) );

		$this->assertSame( 1, Mcpwp_Content_Guardrails::get_daily_count() );
	}

	public function test_failed_insert_does_not_consume_allowance(): void {
		// Counting happens on transition_post_status — AFTER a write lands
		// — never inside the data filter itself (#957 design fix: "failed
		// inserts don't consume allowance").
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 100 ) );

		// wp_update_post() on a nonexistent ID returns 0/WP_Error and never
		// reaches wp_insert_post_data or transition_post_status at all.
		$result = wp_update_post( array( 'ID' => 999999, 'post_status' => 'publish' ), true );
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 0, Mcpwp_Content_Guardrails::get_daily_count() );
	}

	public function test_sequential_publishes_under_cap_reserve_exactly_once_each(): void {
		// True concurrent-request atomicity (many requests hitting the
		// reservation CAS loop at the exact same instant) is a database-
		// engine guarantee — see the class docblock + PR description for
		// the rig proof with real parallel HTTP requests against real
		// MySQL. This pins the reservation PRIMITIVE's arithmetic itself:
		// 30 sequential reservations, each one individually below the cap,
		// must land at exactly 30 — not more, not fewer.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1000, 'min_word_count' => 0 ) );

		for ( $i = 0; $i < 30; $i++ ) {
			wp_insert_post( array( 'post_type' => 'post', 'post_title' => "P$i", 'post_status' => 'publish', 'post_content' => $this->words( 10, "p{$i}w" ) ) );
		}

		$this->assertSame( 30, Mcpwp_Content_Guardrails::get_daily_count() );
	}

	public function test_reservation_gate_lets_through_exactly_the_remaining_slots(): void {
		// #957 adversarial review round 2 WARN-3: at cap-1, exactly ONE
		// more publish may land — this is the sequential form of the rig's
		// genuinely-parallel proof (50 real concurrent HTTP requests at
		// cap-1 landing exactly 1 publish; see the PR description), pinning
		// that the RESERVATION decision itself (not just the arithmetic) is
		// a hard cutoff, not a probabilistic one.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 5, 'min_word_count' => 0, 'duplicate_threshold' => 1.1 ) );

		for ( $i = 0; $i < 4; $i++ ) {
			wp_insert_post( array( 'post_type' => 'post', 'post_title' => "Filler $i", 'post_status' => 'publish', 'post_content' => $this->words( 10, "f{$i}w" ) ) );
		}
		$this->assertSame( 4, Mcpwp_Content_Guardrails::get_daily_count(), 'at cap-1 before the contested batch' );

		$published = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => "Contested $i", 'post_status' => 'publish', 'post_content' => $this->words( 10, "c{$i}w" ) ) );
			if ( 'publish' === $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status ) {
				$published++;
			}
		}

		$this->assertSame( 1, $published, 'exactly the one remaining slot, whichever of the 10 callers got it' );
		$this->assertSame( 5, Mcpwp_Content_Guardrails::get_daily_count() );
	}

	public function test_release_stale_reservation_gives_back_a_slot_from_a_failed_insert(): void {
		// #957 adversarial review round 2 WARN-3: "release the reservation
		// if the insert then fails." wp_insert_post() failing AFTER the
		// data filter reserved a slot (e.g. a page-template validation
		// error) must not permanently burn that slot.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1, 'min_word_count' => 0 ) );

		$GLOBALS['mcpwp_test_page_templates'] = array(); // 'invalid-template' below is not in the (now empty) allow-list.
		$failed = wp_insert_post(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Will fail template validation',
				'post_status'   => 'publish',
				'post_content'  => $this->words( 10 ),
				'page_template' => 'invalid-template',
			),
			true
		);
		$this->assertInstanceOf( 'WP_Error', $failed );

		// The reservation the filter made for the failed call above must
		// have been released — a fresh publish attempt still gets the slot.
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Should still get the slot', 'post_status' => 'publish', 'post_content' => $this->words( 10, 'ok' ) ) );
		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
		$this->assertSame( 1, Mcpwp_Content_Guardrails::get_daily_count() );

		unset( $GLOBALS['mcpwp_test_page_templates'] );
	}

	public function test_nested_reservation_confirm_does_not_cross_contaminate_a_different_post(): void {
		// #957 adversarial review round 3 NIT-b: "key reservations by post
		// ID and confirm the specific reservation for that post. A nested
		// covered publish must not release another post's reservation."
		// Post A's row lands (reserving its slot) but its OWN
		// `wp_insert_post` action confirmation is delayed — modeling
		// another callback on that same action triggering an entirely
		// separate post B's full reserve-and-confirm cycle first, before
		// A's confirmation finally runs.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1000, 'min_word_count' => 0 ) );

		$this->seed_draft( 1751, 'page', 'Page A', $this->words( 10, 'a_' ) );

		$filtered_a = apply_filters(
			'wp_insert_post_data',
			array( 'ID' => 1751, 'post_status' => 'publish', 'post_content' => $this->words( 10, 'a_' ), 'post_type' => 'page' ),
			array( 'ID' => 1751 ),
			array(),
			true
		);
		$this->assertSame( 'publish', $filtered_a['post_status'], 'A is under the cap' );
		$GLOBALS['_mcpwp_test_posts'][1751]->post_status = 'publish'; // The row write itself — always precedes the action firing.
		$this->assertSame( 1, Mcpwp_Content_Guardrails::get_daily_count(), 'A reserved a slot, keyed by its own post ID' );

		// B's ENTIRE cycle — reserve AND confirm — happens while A's own
		// confirmation is still pending.
		$b_id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Page B', 'post_status' => 'publish', 'post_content' => $this->words( 10, 'b_' ) ) );
		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $b_id ]->post_status );
		$this->assertSame( 2, Mcpwp_Content_Guardrails::get_daily_count(), "A's still-pending reservation survived B's entire nested cycle" );

		// A's own confirmation finally fires.
		do_action( 'wp_insert_post', 1751, $GLOBALS['_mcpwp_test_posts'][1751], true );

		$this->assertSame( 2, Mcpwp_Content_Guardrails::get_daily_count(), "confirming A afterward released nothing — B's confirm never touched A's reservation, and A's confirm didn't touch B's" );
	}

	public function test_daily_count_resets_on_a_new_utc_day(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1000, 'min_word_count' => 0 ) );

		$day1 = strtotime( '2026-01-01 12:00:00 UTC' );
		$day2 = strtotime( '2026-01-02 12:00:00 UTC' );

		$this->assertSame( 0, Mcpwp_Content_Guardrails::get_daily_count( $day1 ) );
		$this->assertSame( 0, Mcpwp_Content_Guardrails::get_daily_count( $day2 ) );
	}

	// -------------------------------------------------------------------------
	// Rule 2: bulk-approval threshold

	public function test_bulk_plan_requires_approval_over_threshold(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 5 ) );
		$plan = Mcpwp_Content_Guardrails::plan_bulk_publish( array_fill( 0, 6, array( 'status' => 'publish' ) ) );
		$this->assertTrue( $plan['requires_approval'] );
	}

	public function test_bulk_plan_never_counts_drafts(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 2 ) );
		$plan = Mcpwp_Content_Guardrails::plan_bulk_publish( array_fill( 0, 20, array( 'status' => 'draft' ) ) );
		$this->assertFalse( $plan['requires_approval'] );
	}

	public function test_bulk_create_pages_over_threshold_queues_one_approval_and_drafts_every_item(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 2, 'daily_publish_cap' => 1000, 'min_word_count' => 0 ) );

		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/pages/bulk' );
		$pages      = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$pages[] = array( 'title' => "Page $i", 'content' => $this->words( 10, "p{$i}_" ), 'status' => 'publish' );
		}
		$request->set_param( 'pages', $pages );

		$response = $controller->bulk_create_pages( $request );
		$data     = $response->get_data();

		foreach ( $data['created'] as $page ) {
			$this->assertSame( 'draft', $page['status'] );
		}
		$this->assertTrue( $data['guardrails']['bulk_approval_required'] );

		$pending = Mcpwp_Approvals::list_requests( 'pending', 10, true );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'bulk_content_publish', $pending[0]['action'] );
		$this->assertCount( 3, $pending[0]['payload']['items'] );
		// Findings snapshotted at queue time, for the approver (WARN-1).
		$this->assertArrayHasKey( 'word_count', $pending[0]['payload']['items'][0]['findings'] );
	}

	public function test_bulk_create_pages_a_batch_of_only_drafts_is_never_gated(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 1, 'min_word_count' => 0 ) );

		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/pages/bulk' );
		$request->set_param(
			'pages',
			array(
				array( 'title' => 'Page A', 'content' => $this->words( 10 ), 'status' => 'draft' ),
				array( 'title' => 'Page B', 'content' => $this->words( 10 ), 'status' => 'draft' ),
			)
		);

		$response = $controller->bulk_create_pages( $request );
		$this->assertArrayNotHasKey( 'guardrails', $response->get_data() );
	}

	public function test_bulk_apply_after_edit_skips_the_changed_post_only(): void {
		// WARN-1: apply re-checks every item's content hash; a post edited
		// after being queued is skipped, not published, and everything
		// else in the same batch still goes through.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1000, 'min_word_count' => 0, 'duplicate_threshold' => 1.1 ) );

		$this->seed_draft( 601, 'page', 'Page A', $this->words( 30, 'a_' ) );
		$this->seed_draft( 602, 'page', 'Page B', $this->words( 30, 'b_' ) );

		$approval = Mcpwp_Content_Guardrails::request_bulk_publish_approval( array( 601, 602 ), 'page', array( 'requested_by_key_id' => 'key-1' ) );
		$this->assertIsArray( $approval );

		// A human edits post 602 after the snapshot was taken.
		$GLOBALS['_mcpwp_test_posts'][602]->post_content = $this->words( 30, 'edited_' );

		Mcpwp_Approvals::approve_request( $approval['id'], '', array( 'actor' => 'key-2' ) );
		$applied = Mcpwp_Approvals::apply_request( $approval['id'], array( 'actor' => 'key-2' ) );
		$this->assertIsArray( $applied );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][601]->post_status, 'unedited item still publishes' );
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][602]->post_status, 'edited item is skipped, not published' );

		$record = Mcpwp_Approvals::get_request( $approval['id'] );
		$this->assertContains( 601, $record['metadata']['apply_summary']['published'] );
		$skipped_ids = array_column( $record['metadata']['apply_summary']['skipped'], 'id' );
		$this->assertContains( 602, $skipped_ids );
	}

	public function test_bulk_rollback_only_reverts_what_this_apply_actually_published(): void {
		// WARN-2: rollback must never touch a post it didn't apply-publish,
		// and never a post a human has since changed again.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1000, 'min_word_count' => 0, 'duplicate_threshold' => 1.1 ) );

		$this->seed_draft( 701, 'page', 'Page A', $this->words( 30, 'a_' ) );
		$this->seed_draft( 702, 'page', 'Page B', $this->words( 30, 'b_' ) );

		$approval = Mcpwp_Content_Guardrails::request_bulk_publish_approval( array( 701, 702 ), 'page', array() );

		// 702 is edited before apply — it will be skipped, never published.
		$GLOBALS['_mcpwp_test_posts'][702]->post_content = $this->words( 30, 'edited_' );

		Mcpwp_Approvals::approve_request( $approval['id'], '', array( 'actor' => 'key-2' ) );
		Mcpwp_Approvals::apply_request( $approval['id'], array( 'actor' => 'key-2' ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][701]->post_status );
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][702]->post_status );

		// A human then manually unpublishes 701 again, outside this flow.
		$GLOBALS['_mcpwp_test_posts'][701]->post_status = 'draft';

		$rolled_back = Mcpwp_Approvals::rollback_request( $approval['id'] );
		$this->assertIsArray( $rolled_back );

		// 701 stays exactly as the human left it — rollback does not stomp
		// on a state this apply did not itself create.
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][701]->post_status );
		// 702 was never applied, so rollback never touches it either — it
		// stays a plain draft, not some third "reverted" state.
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][702]->post_status );
	}

	// -------------------------------------------------------------------------
	// Blueprint deploy identity guard (WARN-7)

	public function test_blueprint_deploy_bulk_approval_requires_stable_identity(): void {
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-blueprints.php';
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-blueprints.php';

		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 1, 'min_word_count' => 0 ) );

		$blueprint = array(
			'id'    => 'bp-test',
			'name'  => 'Test Blueprint',
			'pages' => array(
				array( 'title' => 'Page One', 'slug' => 'p1', 'sections' => array() ),
				array( 'title' => 'Page Two', 'slug' => 'p2', 'sections' => array() ),
			),
		);
		update_option( 'mcpwp_site_blueprints', array( $blueprint ), false );

		$controller = new Mcpwp_REST_Site_Blueprints();
		// Simulate an unstable (legacy) OAuth identity — the guard refuses
		// to queue an unattributed bulk-publish approval for it (WARN-7).
		$ref = new ReflectionProperty( $controller, 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue( $controller, array( 'id' => 'oauth:legacyhash', 'type' => 'oauth' ) );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/site-blueprints/bp-test/deploy' );
		$request->set_param( 'id', 'bp-test' );
		$request->set_param( 'post_status', 'publish' );

		$response = $controller->deploy_blueprint( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['guardrails']['bulk_approval_required'] );
		// No stable identity in this test context — guard refuses, and the
		// message says so rather than silently queuing an unattributed request.
		$this->assertArrayNotHasKey( 'approval_id', $data['guardrails'] );
	}

	// -------------------------------------------------------------------------
	// Rule 3a: thin-content is WARNING ONLY (false-positive fix)

	public function test_thin_published_content_only_warns_never_downgrades(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150, 'daily_publish_cap' => 1000 ) );

		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Too short', 'post_status' => 'publish', 'post_content' => $this->words( 10 ) ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
	}

	public function test_elementor_first_page_with_zero_words_at_create_still_publishes_with_warning(): void {
		// The textbook false-positive case: build_page()/create_elementor_page()
		// create the post with post_content='' and write Elementor data to
		// postmeta AFTERWARD — at insert time there is genuinely nothing to
		// judge thinness from, and that must never block a legitimate publish.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150, 'daily_publish_cap' => 1000 ) );

		$id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Elementor First', 'post_status' => 'publish', 'post_content' => '' ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
	}

	public function test_contact_page_with_shortcode_only_content_still_publishes(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150, 'daily_publish_cap' => 1000 ) );

		$id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Contact Us', 'post_status' => 'publish', 'post_content' => '[contact-form-7 id="1"]' ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
	}

	// -------------------------------------------------------------------------
	// Rule 3b: near-duplicate detection (only ever downgrades)

	public function test_identical_content_is_downgraded(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$body = 'Our roofing company installs and repairs residential roofs with quality materials and skilled crews every single week of the year across the region.';
		$this->seed_published( 801, 'page', 'Springfield Roofing Services', $body );

		$id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Springfield Roofing Services', 'post_status' => 'publish', 'post_content' => $body ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
	}

	public function test_city_swap_is_detected_and_downgraded(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.99, 'daily_publish_cap' => 1000 ) );

		$toronto = 'Looking for a reliable plumber in Toronto? Our Toronto team handles emergencies across Toronto every day of the week.';
		$this->seed_published( 901, 'page', 'Toronto Plumbing Services', $toronto );

		$ottawa = 'Looking for a reliable plumber in Ottawa? Our Ottawa team handles emergencies across Ottawa every day of the week.';
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Ottawa Plumbing Services', 'post_status' => 'publish', 'post_content' => $ottawa ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
	}

	public function test_genuinely_different_content_is_not_flagged(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 1001, 'post', 'Baking Sourdough Bread', 'Sourdough baking requires patience, a healthy starter, careful hydration, and a hot oven with steam for a good crust and open crumb structure throughout the loaf.' );

		$id = wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_title'   => 'Electric Vehicle Battery Chemistry',
				'post_status'  => 'publish',
				'post_content' => 'Lithium-ion battery packs balance energy density against thermal stability, and pack designers weigh cobalt-free chemistries against cost, longevity, and fast-charging tolerance for modern vehicles.',
			)
		);

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
	}

	public function test_duplicate_check_on_update_uses_elementor_meta_when_content_is_thin(): void {
		// An existing post being UPDATED (has an ID) may carry its real
		// content in _elementor_data — the false-positive fix's counterpart:
		// this must be found and used, not skipped just because
		// post_content itself is empty.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$body = $this->words( 40, 'roofing_' );
		$this->seed_published( 1101, 'page', 'Existing Roofing Page', $body );

		$this->seed_draft( 1102, 'page', 'New Roofing Page', '' );
		update_post_meta( 1102, '_elementor_data', wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'editor' => $body ) ) ) ) );

		wp_update_post( array( 'ID' => 1102, 'post_status' => 'publish' ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][1102]->post_status );
	}

	public function test_block1_elementor_clone_with_filler_post_content_is_refused_at_publish(): void {
		// #957 adversarial review round 4 BLOCK-1: the gate used to prefer
		// post_content (falling back to Elementor only when post_content
		// was thin) while candidates preferred Elementor — a draft could be
		// given a cloned Elementor page, then published with unique filler
		// post_content (>= MIN_COMPARABLE_WORDS, so the gate never fell
		// back to Elementor at all) and slip through untouched, even though
		// visitors would see the clone (Elementor pages render from
		// _elementor_data, never post_content). Exact bypass sequence:
		// draft -> wp_set_elementor a clone of X -> update_page with 30
		// unique filler words + status=publish.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$original_body = $this->words( 40, 'roofing_' );
		$this->seed_published( 1111, 'page', 'Existing Roofing Page', $original_body );

		// 1. Create a draft.
		$this->seed_draft( 1112, 'page', 'New Page', '' );

		// 2. wp_set_elementor a clone of the existing page onto it (still a
		// draft — the elementor-meta advisory chokepoint only watches
		// ALREADY-published posts, so this alone must do nothing yet).
		update_post_meta( 1112, '_elementor_data', wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'editor' => $original_body ) ) ) ) );
		$response = array( 'id' => 1112 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertArrayNotHasKey( 'guardrails', $response, 'a DRAFT is not the advisory chokepoint\'s concern' );

		// 3. update_page: 30 UNIQUE filler words, status=publish — the
		// exact bypass shape. Filler shares no vocabulary with anything.
		$filler = $this->words( 30, 'zzfiller_' );
		wp_update_post( array( 'ID' => 1112, 'post_content' => $filler, 'post_status' => 'publish' ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][1112]->post_status, 'the Elementor clone is caught even though post_content alone was unique filler' );
	}

	public function test_block1_builder_page_with_meaningful_post_content_scores_the_max_of_both(): void {
		// The other half of the fix: a builder page that ALSO carries
		// meaningful post_content must not be able to hide a match behind
		// WHICHEVER field happens to be checked — both are scored, the
		// worse (more suspicious) one wins.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$dup_body = $this->words( 40, 'dup_' );
		$this->seed_published( 1121, 'page', 'Existing Page', $dup_body );

		$this->seed_draft( 1122, 'page', 'New Page', $dup_body ); // post_content ALSO matches.
		update_post_meta( 1122, '_elementor_data', wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'editor' => $dup_body ) ) ) ) );

		wp_update_post( array( 'ID' => 1122, 'post_status' => 'publish' ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][1122]->post_status );
	}

	public function test_block1_builder_page_with_unique_post_content_and_unique_elementor_text_still_publishes(): void {
		// Sanity: a genuinely unique builder page (both fields unique) is
		// never wrongly caught by the dual-scoring.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 1131, 'page', 'Unrelated Existing Page', $this->words( 40, 'unrelated_' ) );

		$this->seed_draft( 1132, 'page', 'New Unique Page', $this->words( 30, 'contentA_' ) );
		update_post_meta( 1132, '_elementor_data', wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'editor' => $this->words( 40, 'contentB_' ) ) ) ) ) );

		wp_update_post( array( 'ID' => 1132, 'post_status' => 'publish' ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][1132]->post_status );
	}

	// -------------------------------------------------------------------------
	// CJK word counting

	public function test_cjk_content_is_not_wrongly_flagged_thin(): void {
		Mcpwp_Content_Guardrails::enter_context();
		// 300 CJK characters ≈ 150 "words" at the ÷2 rule — comfortably over
		// a 150-word floor; a naive whitespace splitter would see ONE token
		// and call it thin.
		$cjk = str_repeat( '这是一个测试内容用于检查中文字数统计是否正确', 15 );

		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150, 'daily_publish_cap' => 1000, 'duplicate_threshold' => 1.1 ) );

		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'CJK Post', 'post_status' => 'publish', 'post_content' => $cjk ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
	}

	public function test_cjk_duplicate_detection_still_works(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$cjk = str_repeat( '这是一个测试内容用于检查中文字数统计是否正确并且这段文字足够长', 10 );
		$this->seed_published( 1201, 'post', 'CJK Original', $cjk );

		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'CJK Copy', 'post_status' => 'publish', 'post_content' => $cjk ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
	}

	// -------------------------------------------------------------------------
	// Translation exemption

	public function test_translation_is_exempt_from_duplicate_check_against_its_own_source(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.1, 'daily_publish_cap' => 1000 ) );

		$body = $this->words( 40, 'shared_' );
		$this->seed_published( 1301, 'post', 'Original English Post', $body );

		$multilang = new Mcpwp_Multilang();
		$ref = new ReflectionProperty( $multilang, 'detected_plugin' );
		$ref->setAccessible( true );
		$ref->setValue( $multilang, 'polylang' );

		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			function pll_get_post_translations( $post_id ) { return array(); }
		}
		if ( ! function_exists( 'pll_set_post_language' ) ) {
			function pll_set_post_language( $post_id, $lang ) {}
		}
		if ( ! function_exists( 'pll_save_post_translations' ) ) {
			function pll_save_post_translations( $translations ) {}
		}
		if ( ! function_exists( 'pll_languages_list' ) ) {
			function pll_languages_list() { return array( 'en', 'fr' ); }
		}
		if ( ! function_exists( 'PLL' ) ) {
			function PLL() {
				return new class {
					public $model;
					public function __construct() {
						$this->model = new class {
							public function get_languages_list() {
								return array(
									(object) array( 'slug' => 'en', 'name' => 'English', 'flag_url' => '', 'is_default' => true, 'locale' => 'en_US' ),
									(object) array( 'slug' => 'fr', 'name' => 'French', 'flag_url' => '', 'is_default' => false, 'locale' => 'fr_FR' ),
								);
							}
						};
					}
				};
			}
		}

		// SAME content as the source (a translation not yet actually
		// translated is the realistic near-term state) — without the
		// exemption this would trip the duplicate check at a 0.1 threshold.
		$result = $multilang->create_post_translation( 1301, 'fr', array( 'title' => 'Translated Post', 'content' => $body, 'status' => 'publish' ) );

		$this->assertIsInt( $result );
		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $result ]->post_status );
	}

	// -------------------------------------------------------------------------
	// Round 3 design change: content edits to an ALREADY-published post are
	// ADVISORY, never reverted/refused — see the class docblock.

	public function test_editing_a_published_post_into_a_city_swap_copy_is_advisory_not_reverted(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$toronto = 'Looking for a reliable plumber in Toronto? Our Toronto team handles emergencies across Toronto every day of the week.';
		$this->seed_published( 1501, 'page', 'Toronto Plumbing Services', $toronto );

		// A page published with genuinely unique text...
		$this->seed_published( 1502, 'page', 'Unique Original Page', $this->words( 40, 'orig_' ) );

		// ...then edited into a city-swap copy of 1501.
		$ottawa = 'Looking for a reliable plumber in Ottawa? Our Ottawa team handles emergencies across Ottawa every day of the week.';
		$result = wp_update_post( array( 'ID' => 1502, 'post_title' => 'Ottawa Plumbing Services', 'post_content' => $ottawa ), true );

		$this->assertSame( 1502, $result );
		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][1502]->post_status, 'never unpublished' );
		$this->assertSame( $ottawa, $GLOBALS['_mcpwp_test_posts'][1502]->post_content, 'the edit is saved exactly as submitted — never reverted' );
		$this->assertSame( 'Ottawa Plumbing Services', $GLOBALS['_mcpwp_test_posts'][1502]->post_title, 'the title change is saved too' );

		$response = array( 'id' => 1502 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertTrue( $response['guardrails']['content_edit_advisory'] );
		$this->assertTrue( $response['guardrails']['duplicate']['city_swap'] );
		$this->assertNotEmpty( $response['warnings'] );

		$issues = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 1502, 'reveal_non_public' => true ) );
		$this->assertNotEmpty( $issues['issues'], 'a Control Room issue is recorded for a human to review' );
		$this->assertSame( 'content_guardrails', $issues['issues'][0]['category'] );
	}

	public function test_editing_a_published_post_to_genuinely_different_content_is_never_flagged(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 1511, 'page', 'Original Topic', $this->words( 40, 'topicA_' ) );

		$result = wp_update_post( array( 'ID' => 1511, 'post_content' => $this->words( 40, 'topicB_' ) ), true );

		$this->assertSame( 1511, $result );
		$this->assertSame( $this->words( 40, 'topicB_' ), $GLOBALS['_mcpwp_test_posts'][1511]->post_content );

		$response = array( 'id' => 1511 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertArrayNotHasKey( 'guardrails', $response, 'a genuinely different edit gets no notice at all' );
	}

	public function test_editing_a_published_post_without_touching_content_never_runs_the_duplicate_check(): void {
		// "A change that doesn't touch the content stays cheap" — verified
		// indirectly: a status/meta-only update to a post whose content
		// WOULD duplicate-match something else must still go through
		// untouched and unflagged, because the guard bails before ever
		// comparing.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.01, 'daily_publish_cap' => 1000 ) );

		$shared = $this->words( 40, 'dup_' );
		$this->seed_published( 1521, 'page', 'Page A', $shared );
		$this->seed_published( 1522, 'page', 'Page B', $shared ); // Already an exact duplicate of 1521 — pre-existing, not this edit's concern.

		// An update that never mentions post_content at all (e.g. only
		// changing the menu order) must not be flagged just because the
		// EXISTING content happens to duplicate something else.
		$result = wp_update_post( array( 'ID' => 1522, 'menu_order' => 5 ), true );

		$this->assertSame( 1522, $result );
		$this->assertSame( 5, $GLOBALS['_mcpwp_test_posts'][1522]->menu_order );

		$response = array( 'id' => 1522 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertArrayNotHasKey( 'guardrails', $response );
	}

	public function test_title_only_edit_on_a_published_post_does_zero_duplicate_work(): void {
		// "Title-only or meta-only edits run zero duplicate work" — pin this
		// with a spy, not just an outcome: even a title that duplicate_check
		// WOULD flag if it ran must never trigger it.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.01, 'daily_publish_cap' => 1000 ) );

		$shared = $this->words( 40, 'dup_' );
		$this->seed_published( 1541, 'page', 'Page A', $shared );
		$this->seed_published( 1542, 'page', 'Page B', $shared );

		$query_count_before = $GLOBALS['mcpwp_test_get_posts_call_count'] ?? 0;

		$result = wp_update_post( array( 'ID' => 1542, 'post_title' => 'A Brand New Title' ), true );

		$this->assertSame( 1542, $result );
		$this->assertSame( 'A Brand New Title', $GLOBALS['_mcpwp_test_posts'][1542]->post_title );

		$response = array( 'id' => 1542 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertArrayNotHasKey( 'guardrails', $response, 'a title-only edit is never flagged, however similar the (untouched) body is' );
		$this->assertSame( $query_count_before, $GLOBALS['mcpwp_test_get_posts_call_count'] ?? 0, 'get_posts() (the duplicate-check query) was never called' );
	}

	public function test_bulk_update_posts_editing_into_a_duplicate_is_flagged_advisory_across_the_site(): void {
		// "wp_bulk_update_posts does the same across the site" — bulk
		// update loops over Mcpwp_Posts::update_post(), the exact same
		// wp_update_post() call this guard already covers; no separate
		// wiring needed, proven here through the actual bulk REST route.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 1531, 'post', 'Source Post', $this->words( 40, 'src_' ) );
		$this->seed_published( 1532, 'post', 'Target Post', $this->words( 40, 'tgt_' ) );

		$controller = new Mcpwp_REST_Posts();
		$request    = new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk' );
		$request->set_param(
			'updates',
			array(
				array( 'id' => 1532, 'content' => $this->words( 40, 'src_' ) ), // Duplicates 1531.
			)
		);

		$controller->bulk_update_posts( $request );

		$this->assertSame( $this->words( 40, 'src_' ), $GLOBALS['_mcpwp_test_posts'][1532]->post_content, 'the bulk edit into a duplicate is saved exactly as submitted' );
		$issues = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 1532, 'reveal_non_public' => true ) );
		$this->assertNotEmpty( $issues['issues'], 'flagged for review, site-wide, via the same bulk update path' );
	}

	public function test_elementor_meta_edit_on_a_published_page_is_advisory(): void {
		// _elementor_data never flows through wp_insert_post_data — this is
		// the SEPARATE chokepoint (#957 adversarial review round 3): the
		// generic update_post_meta/updated_post_meta actions, filtered to
		// '_elementor_data' and a currently-published post.
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 2001, 'page', 'Elementor Page', '' );
		$GLOBALS['_mcpwp_test_meta'][2001]['_elementor_data'] = wp_json_encode(
			array( array( 'elType' => 'widget', 'settings' => array( 'title' => $this->words( 40, 'orig_' ) ) ) )
		);

		$this->seed_published( 2002, 'page', 'Sibling Page', $this->words( 40, 'dup_' ) );

		Mcpwp_Content_Guardrails::enter_context();

		$new_elementor = wp_json_encode(
			array( array( 'elType' => 'widget', 'settings' => array( 'title' => $this->words( 40, 'dup_' ) ) ) )
		);
		update_post_meta( 2001, '_elementor_data', $new_elementor );

		$this->assertSame( $new_elementor, $GLOBALS['_mcpwp_test_meta'][2001]['_elementor_data'], 'the meta write always lands exactly as submitted — never blocked' );
		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][2001]->post_status );

		$response = array( 'id' => 2001 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertTrue( $response['guardrails']['content_edit_advisory'] );
		$this->assertTrue( $response['guardrails']['duplicate']['flagged'] );

		$issues = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 2001, 'reveal_non_public' => true ) );
		$this->assertNotEmpty( $issues['issues'] );
		$this->assertSame( 'duplicate_edit', $issues['issues'][0]['code'] );
	}

	public function test_elementor_meta_edit_advisory_dedupes_per_post_not_per_call(): void {
		// "Dedupe per post, not per call" — flagging the SAME post twice
		// must update one issue record, not create a second.
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 2011, 'page', 'Elementor Page', '' );
		$GLOBALS['_mcpwp_test_meta'][2011]['_elementor_data'] = wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'title' => 'v0' ) ) ) );
		$this->seed_published( 2012, 'page', 'Sibling Page', $this->words( 40, 'dup_' ) );

		Mcpwp_Content_Guardrails::enter_context();

		update_post_meta( 2011, '_elementor_data', wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'title' => $this->words( 40, 'dup_' ) ) ) ) ) );
		update_post_meta( 2011, '_elementor_data', wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'title' => $this->words( 40, 'dup_' ) . ' extra' ) ) ) ) );

		$issues = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 2011, 'reveal_non_public' => true, 'limit' => 200 ) );
		$this->assertCount( 1, $issues['issues'], 'two flags on the same post update one issue record' );
		$this->assertSame( 2, $issues['issues'][0]['seen_count'] );
	}

	public function test_elementor_meta_edit_on_a_draft_page_is_never_advisory_checked(): void {
		// "only while ... the post is published" — a draft page's Elementor
		// data changes constantly while it's being built; none of that is
		// this chokepoint's concern.
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.01, 'daily_publish_cap' => 1000 ) );

		$this->seed_draft( 2021, 'page', 'Draft Page', '' );
		$this->seed_published( 2022, 'page', 'Sibling Page', $this->words( 40, 'dup_' ) );

		Mcpwp_Content_Guardrails::enter_context();
		update_post_meta( 2021, '_elementor_data', wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'title' => $this->words( 40, 'dup_' ) ) ) ) ) );

		$response = array( 'id' => 2021 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertArrayNotHasKey( 'guardrails', $response );
	}

	public function test_elementor_meta_write_via_meta_input_on_a_new_insert_is_not_double_checked(): void {
		// clone_page()'s meta_input front-loading already duplicate-checks
		// this exact content via on_insert_post_data()'s own lookahead — the
		// generic added_post_meta hook must not ALSO flag the very same
		// first-publish content a second time.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 2031, 'page', 'Sibling Page', $this->words( 40, 'dup_' ) );

		$elementor_json = wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'title' => $this->words( 40, 'dup_' ) ) ) ) );
		$new_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Cloned Page',
				'post_status'  => 'publish',
				'post_content' => '',
				'meta_input'   => array( '_elementor_data' => $elementor_json ),
			)
		);

		$response = array( 'id' => $new_id );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		// downgraded (create-time duplicate check) is expected — but only
		// ONE warning, not a second "content_edit_advisory" one stacked on
		// top of it.
		$this->assertArrayNotHasKey( 'content_edit_advisory', $response['guardrails'] ?? array() );
	}

	// -------------------------------------------------------------------------
	// WARN-2: the exclude-id list is cleared on EVERY pass, not just publishes

	public function test_exclude_ids_set_for_a_draft_translation_do_not_leak_into_a_later_unrelated_publish(): void {
		// wp_batch: create_translation (draft) of post A, then create_post
		// (publish) of an UNRELATED copy of post A's body. The exclude list
		// set for the draft translation must be cleared even though the
		// draft write never reaches the publish/duplicate-check branch at
		// all — otherwise the second, unrelated call would wrongly skip
		// comparing against post A (#957 adversarial review round 2 WARN-2).
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$body = $this->words( 40, 'shared_' );
		$this->seed_published( 1601, 'post', 'Post A', $body );

		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			function pll_get_post_translations( $post_id ) { return array(); }
		}
		if ( ! function_exists( 'pll_set_post_language' ) ) {
			function pll_set_post_language( $post_id, $lang ) {}
		}
		if ( ! function_exists( 'pll_save_post_translations' ) ) {
			function pll_save_post_translations( $translations ) {}
		}
		if ( ! function_exists( 'pll_languages_list' ) ) {
			function pll_languages_list() { return array( 'en', 'fr' ); }
		}
		if ( ! function_exists( 'PLL' ) ) {
			function PLL() {
				return new class {
					public $model;
					public function __construct() {
						$this->model = new class {
							public function get_languages_list() {
								return array(
									(object) array( 'slug' => 'en', 'name' => 'English', 'flag_url' => '', 'is_default' => true, 'locale' => 'en_US' ),
									(object) array( 'slug' => 'fr', 'name' => 'French', 'flag_url' => '', 'is_default' => false, 'locale' => 'fr_FR' ),
								);
							}
						};
					}
				};
			}
		}

		$multilang = new Mcpwp_Multilang();
		$ref       = new ReflectionProperty( $multilang, 'detected_plugin' );
		$ref->setAccessible( true );
		$ref->setValue( $multilang, 'polylang' );

		// Step 1: create a DRAFT translation of post A. This sets the
		// exclude-ids list to [1301-ish source id], then the write is a
		// draft, so the OLD code never reached the point that would have
		// consumed it.
		$translation_id = $multilang->create_post_translation( 1601, 'fr', array( 'title' => 'Translated (draft)', 'content' => $body, 'status' => 'draft' ) );
		$this->assertIsInt( $translation_id );

		// Step 2: an UNRELATED post, published, with the SAME body as post
		// A. If the exclude list leaked, post A would be wrongly excluded
		// from the comparison and this would NOT be flagged.
		$copy_id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'An Unrelated Copy', 'post_status' => 'publish', 'post_content' => $body ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $copy_id ]->post_status, 'the leaked exclude list must not have shielded this unrelated duplicate' );
	}

	// -------------------------------------------------------------------------
	// NIT-3: per-request state cannot leak across requests in a worker process

	public function test_shutdown_resets_context_so_a_worker_process_cannot_leak_it(): void {
		Mcpwp_Content_Guardrails::enter_context();
		$this->assertTrue( Mcpwp_Content_Guardrails::in_context() );

		// Simulates the end of this HTTP request in a long-lived worker
		// process (FrankenPHP/Swoole) that reuses the same PHP process —
		// and the next request beginning with in_context() still true
		// would be exactly the leak (#957 adversarial review round 2 NIT-3).
		do_action( 'shutdown' );

		$this->assertFalse( Mcpwp_Content_Guardrails::in_context(), 'context must not survive past the end of the request that set it' );
	}

	public function test_shutdown_releases_any_reservation_still_pending_at_request_end(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1, 'min_word_count' => 0 ) );

		$GLOBALS['mcpwp_test_page_templates'] = array();
		wp_insert_post(
			array(
				'post_type'     => 'page',
				'post_title'    => 'Fails at the very end of the request',
				'post_status'   => 'publish',
				'post_content'  => $this->words( 10 ),
				'page_template' => 'invalid-template',
			),
			true
		);
		$this->assertSame( 1, Mcpwp_Content_Guardrails::get_daily_count(), 'reserved, not yet confirmed or released' );

		do_action( 'shutdown' );

		$this->assertSame( 0, Mcpwp_Content_Guardrails::get_daily_count(), 'shutdown released the never-confirmed reservation' );
		unset( $GLOBALS['mcpwp_test_page_templates'] );
	}

	// -------------------------------------------------------------------------
	// NIT-4: applying an approval from wp-admin re-runs the same guard

	public function test_admin_control_room_apply_runs_the_guard_even_without_api_context(): void {
		// Control Room's approve/apply runs entirely inside wp-admin — no
		// verify_api_key() call, so in_context() is false going in.
		// apply_bulk_publish() must enter context for its own duration
		// (#957 adversarial review round 2 NIT-4) — proven here via
		// DUPLICATE detection (NOT exempt from the cap, unlike the ramp —
		// see NIT-d below), which only runs at all while in_context().
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1000, 'min_word_count' => 0, 'duplicate_threshold' => 0.6 ) );
		$this->assertFalse( Mcpwp_Content_Guardrails::in_context(), 'a wp-admin request never called verify_api_key()' );

		$this->seed_published( 1700, 'page', 'Existing Page', $this->words( 30, 'dup_' ) );
		$this->seed_draft( 1701, 'page', 'Page A', $this->words( 30, 'dup_' ) ); // Duplicates 1700.

		$approval = Mcpwp_Content_Guardrails::request_bulk_publish_approval( array( 1701 ), 'page', array() );
		$this->assertIsArray( $approval );

		Mcpwp_Approvals::approve_request( $approval['id'], '', array( 'actor' => 'admin' ) );
		// Still no enter_context() call anywhere in this test — apply_bulk_publish() must do it itself.
		Mcpwp_Approvals::apply_request( $approval['id'], array( 'admin_user' => 1, 'actor' => 'wp-user:1' ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][1701]->post_status, 'the duplicate check WAS re-run from wp-admin' );

		// And the guard did not leave context on for whatever runs next in
		// this same wp-admin page load.
		$this->assertFalse( Mcpwp_Content_Guardrails::in_context() );
	}

	// -------------------------------------------------------------------------
	// NIT-d: an approved apply is exempt from the ramp cap, but still counted

	public function test_approved_apply_larger_than_the_remaining_cap_publishes_fully_and_counts_fully(): void {
		// The cap exemption (#957 adversarial review round 4 WARN-2) only
		// applies to a GENUINE wp-admin human: is_admin() true, not
		// admin-ajax, not a REST/API-authenticated request, and the
		// claimed admin_user must be the actual current session's user.
		$GLOBALS['mcpwp_test_is_admin']     = true;
		$GLOBALS['mcpwp_test_current_user'] = 1;
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1, 'min_word_count' => 0, 'duplicate_threshold' => 1.1 ) );

		$this->seed_draft( 1711, 'page', 'Page A', $this->words( 30, 'a_' ) );
		$this->seed_draft( 1712, 'page', 'Page B', $this->words( 30, 'b_' ) );
		$this->seed_draft( 1713, 'page', 'Page C', $this->words( 30, 'c_' ) );

		$approval = Mcpwp_Content_Guardrails::request_bulk_publish_approval( array( 1711, 1712, 1713 ), 'page', array() );
		Mcpwp_Approvals::approve_request( $approval['id'], '', array( 'actor' => 'admin' ) );
		Mcpwp_Approvals::apply_request( $approval['id'], array( 'admin_user' => 1, 'actor' => 'wp-user:1' ) );

		// A human already approved all 3 — the cap of 1 does not downgrade
		// any of them.
		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][1711]->post_status );
		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][1712]->post_status );
		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][1713]->post_status );

		// But the count reflects all 3 — a SUBSEQUENT, non-approved publish
		// today must see an already-exceeded cap, not a stale "1 used."
		$this->assertSame( 3, Mcpwp_Content_Guardrails::get_daily_count(), 'the count increases by the full size of the approved batch' );
	}

	public function test_two_key_self_approve_apply_stays_capped_even_with_admin_fields_present(): void {
		// #957 adversarial review round 4 WARN-2: "an agent that holds two
		// principals ... must stay capped." Simulates an apply reached via
		// an ALREADY API/OAuth-authenticated request (context active
		// before apply_bulk_publish() ever runs, exactly what
		// verify_api_key() does for a second key applying what a first
		// key requested) — the cap exemption must never apply here, even
		// though the context ALSO happens to carry admin-looking fields.
		$GLOBALS['mcpwp_test_is_admin']     = true;
		$GLOBALS['mcpwp_test_current_user'] = 1;
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1, 'min_word_count' => 0, 'duplicate_threshold' => 1.1 ) );

		$this->seed_draft( 1721, 'page', 'Page A', $this->words( 30, 'a_' ) );
		$this->seed_draft( 1722, 'page', 'Page B', $this->words( 30, 'b_' ) );

		$approval = Mcpwp_Content_Guardrails::request_bulk_publish_approval( array( 1721, 1722 ), 'page', array() );
		Mcpwp_Approvals::approve_request( $approval['id'], '', array( 'actor' => 'key-1' ) );

		Mcpwp_Content_Guardrails::enter_context(); // Simulates the SECOND key's request already being API-authenticated.
		Mcpwp_Approvals::apply_request( $approval['id'], array( 'admin_user' => 1, 'actor' => 'key-2' ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][1721]->post_status, 'first item fits under the cap of 1' );
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][1722]->post_status, 'second item is capped — an already API-authenticated apply is never exempt' );
	}

	public function test_chat_admin_ajax_apply_stays_capped(): void {
		// Chat authenticates via admin-ajax.php, which reports is_admin()
		// TRUE too — wp_doing_ajax() is what actually tells a real admin
		// PAGE apart from an admin-ajax REQUEST (#957 adversarial review
		// round 4 WARN-2).
		$GLOBALS['mcpwp_test_doing_ajax']   = true;
		$GLOBALS['mcpwp_test_is_admin']     = true;
		$GLOBALS['mcpwp_test_current_user'] = 1;
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1, 'min_word_count' => 0, 'duplicate_threshold' => 1.1 ) );

		$this->seed_draft( 1731, 'page', 'Page A', $this->words( 30, 'a_' ) );
		$this->seed_draft( 1732, 'page', 'Page B', $this->words( 30, 'b_' ) );

		$approval = Mcpwp_Content_Guardrails::request_bulk_publish_approval( array( 1731, 1732 ), 'page', array() );
		Mcpwp_Approvals::approve_request( $approval['id'], '', array( 'actor' => 'admin' ) );
		Mcpwp_Approvals::apply_request( $approval['id'], array( 'admin_user' => 1, 'actor' => 'wp-user:1' ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][1731]->post_status );
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][1732]->post_status, 'admin-ajax (Chat) is never exempt, even with admin_user present' );
	}

	// -------------------------------------------------------------------------
	// NIT-5: content_hash covers title/slug/SEO meta, not just body content

	public function test_content_hash_changes_when_only_the_title_changes(): void {
		$this->seed_published( 1801, 'page', 'Original Title', $this->words( 20 ) );
		$post = get_post( 1801 );
		$hash_before = Mcpwp_Content_Guardrails::content_hash( $post );

		$GLOBALS['_mcpwp_test_posts'][1801]->post_title = 'Renamed Title';
		$hash_after = Mcpwp_Content_Guardrails::content_hash( get_post( 1801 ) );

		$this->assertNotSame( $hash_before, $hash_after, 'a title-only edit (exactly what a city-swap rename touches) must change the hash' );
	}

	public function test_content_hash_changes_when_seo_meta_changes(): void {
		$this->seed_published( 1802, 'page', 'A Page', $this->words( 20 ) );
		$post = get_post( 1802 );
		$hash_before = Mcpwp_Content_Guardrails::content_hash( $post );

		update_post_meta( 1802, '_yoast_wpseo_title', 'A totally different SEO title' );
		$hash_after = Mcpwp_Content_Guardrails::content_hash( get_post( 1802 ) );

		$this->assertNotSame( $hash_before, $hash_after );
	}

	public function test_bulk_apply_skips_a_post_whose_title_changed_since_approval(): void {
		// The hash-based skip in apply_bulk_publish() (WARN-1) must fire
		// on a title-only change too, now that content_hash() covers it.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1000, 'min_word_count' => 0, 'duplicate_threshold' => 1.1 ) );

		$this->seed_draft( 1811, 'page', 'Original Title', $this->words( 30 ) );
		$approval = Mcpwp_Content_Guardrails::request_bulk_publish_approval( array( 1811 ), 'page', array() );

		$GLOBALS['_mcpwp_test_posts'][1811]->post_title = 'Retitled After Approval';

		Mcpwp_Approvals::approve_request( $approval['id'], '', array( 'actor' => 'key-2' ) );
		Mcpwp_Approvals::apply_request( $approval['id'], array( 'actor' => 'key-2' ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][1811]->post_status, 'title-only change since approval must still be caught' );
	}

	// -------------------------------------------------------------------------
	// NIT-1 (strengthened): CJK bigram tokenization actually shingles

	public function test_cjk_near_duplicate_with_word_level_edits_is_detected(): void {
		// A whole-run-as-one-token tokenizer could only ever match byte-
		// identical CJK text; this proves bigram tokenization catches a
		// NEAR match with real edits scattered through it — the case
		// round 1's tokenizer could not have caught even by coincidence.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$original = '欢迎来到我们的多伦多水管维修服务公司我们提供全天候紧急服务和专业的维修团队为您解决所有水管问题';
		$this->seed_published( 1901, 'post', 'CJK Original', $original );

		// Same passage with the city name and a few characters swapped
		// throughout — NOT byte-identical, but still a near-duplicate.
		$near_dup = str_replace( '多伦多', '渥太华', $original );
		$this->assertNotSame( $original, $near_dup, 'sanity: the edited copy really is different bytes' );

		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'CJK Copy', 'post_status' => 'publish', 'post_content' => $near_dup ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status, 'a near-duplicate with word-level CJK edits must still be caught' );
	}

	// -------------------------------------------------------------------------
	// wp_batch dispatches through the same gate

	public function test_wp_batch_update_to_publish_over_cap_is_gated(): void {
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_REST_Batch::$is_batch_sub_request  = false;

		$this->seed_draft( 1401, 'post', 'Batch Draft', $this->words( 50 ) );

		add_filter(
			'rest_dispatch_request',
			function ( $result, $request, $route = '' ) {
				if ( null !== $result ) {
					return $result;
				}
				if ( preg_match( '#^/mcpwp/v1/batch$#', (string) $route ) ) {
					return ( new Mcpwp_REST_Batch() )->handle_batch( $request );
				}
				if ( preg_match( '#^/mcpwp/v1/posts/(\d+)$#', (string) $route, $m ) ) {
					$request->set_param( 'id', (int) $m[1] );
					return ( new Mcpwp_REST_Posts() )->update_post( $request );
				}
				return $result;
			},
			10,
			3
		);

		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 0, 'min_word_count' => 0 ) );
		// enter_context() would normally be set by verify_api_key() on the
		// real, authenticated outer /batch request — set it directly here,
		// since this test exercises the batch DISPATCH shape, not auth.
		Mcpwp_Content_Guardrails::enter_context();

		$outer = new WP_REST_Request( 'POST', '/mcpwp/v1/batch' );
		$outer->set_param(
			'requests',
			array(
				array( 'method' => 'PUT', 'route' => '/posts/1401', 'body' => array( 'status' => 'publish' ) ),
			)
		);

		$response = apply_filters( 'rest_dispatch_request', null, $outer, '/mcpwp/v1/batch' );

		$this->assertNotNull( $response );
		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][1401]->post_status, 'a batch-dispatched update-to-publish over cap must be gated identically to a direct call' );
	}

	// -------------------------------------------------------------------------
	// Settings: checkbox + partial-save safety (WARN-6)

	public function test_settings_unchecked_enabled_checkbox_saves_false_not_default(): void {
		// The hidden input in render_field_enabled() means a real form
		// submission always sends an explicit key — simulate that exact
		// shape (string '0', not simply absent).
		Mcpwp_Content_Guardrails::update_settings( array( 'enabled' => true, 'daily_publish_cap' => 42 ) );

		$sanitized = Mcpwp_Content_Guardrails::sanitize_settings( array( 'enabled' => '0', 'daily_publish_cap' => 42, 'bulk_approval_threshold' => 10, 'min_word_count' => 150, 'duplicate_threshold' => 0.6, 'duplicate_compare_limit' => 200, 'anomaly_multiplier' => 2.0, 'anomaly_min_count' => 10 ) );

		$this->assertFalse( $sanitized['enabled'] );
	}

	public function test_settings_sanitize_falls_back_to_stored_value_not_hardcoded_default(): void {
		// WordPress's Settings API calls sanitize_callback directly from
		// options.php — never through update_settings() — so the "field
		// absent" fallback inside sanitize_settings() itself must protect
		// every OTHER field on a save that only mentions one.
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 77, 'bulk_approval_threshold' => 33 ) );

		// Simulate a save that mentions only ONE field (e.g. a partial
		// REST PUT, or a stripped-down form submission).
		$sanitized = Mcpwp_Content_Guardrails::sanitize_settings( array( 'daily_publish_cap' => 5 ) );

		$this->assertSame( 5, $sanitized['daily_publish_cap'] );
		$this->assertSame( 33, $sanitized['bulk_approval_threshold'], 'a single-field save must not reset every other field to its hardcoded default' );
	}

	public function test_settings_sanitize_clamps_out_of_range_values(): void {
		$sanitized = Mcpwp_Content_Guardrails::sanitize_settings(
			array(
				'daily_publish_cap'       => 0,
				'bulk_approval_threshold' => 999999,
				'min_word_count'          => 999999,
				'duplicate_threshold'     => 5.0,
				'duplicate_compare_limit' => 999999,
				'anomaly_multiplier'      => 0.1,
				'anomaly_min_count'       => 0,
			)
		);

		$this->assertSame( 1, $sanitized['daily_publish_cap'] );
		$this->assertSame( 1000, $sanitized['bulk_approval_threshold'] );
		$this->assertSame( 5000, $sanitized['min_word_count'] );
		$this->assertSame( 1.0, $sanitized['duplicate_threshold'] );
		$this->assertSame( 200, $sanitized['duplicate_compare_limit'] );
		$this->assertSame( 1.0, $sanitized['anomaly_multiplier'] );
		$this->assertSame( 1, $sanitized['anomaly_min_count'] );
	}

	public function test_settings_sanitize_fills_missing_keys_with_defaults_when_nothing_stored(): void {
		$this->assertSame( Mcpwp_Content_Guardrails::default_settings(), Mcpwp_Content_Guardrails::sanitize_settings( array() ) );
	}

	// -------------------------------------------------------------------------
	// Anomaly signal

	public function test_publish_spike_emits_content_publish_spike_event(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1000, 'min_word_count' => 0, 'anomaly_min_count' => 5, 'anomaly_multiplier' => 2.0 ) );

		$captured = array();
		add_action( 'mcpwp_event_emitted', function ( $event ) use ( &$captured ) { $captured[] = $event; }, 10, 1 );

		for ( $i = 0; $i < 6; $i++ ) {
			wp_insert_post( array( 'post_type' => 'post', 'post_title' => "Spike $i", 'post_status' => 'publish', 'post_content' => $this->words( 5 ) ) );
		}

		$spikes = array_values( array_filter( $captured, function ( $e ) { return isset( $e['type'] ) && 'content.publish_spike' === $e['type']; } ) );
		$this->assertCount( 1, $spikes );
		$this->assertSame( 6, $spikes[0]['payload']['published_today_utc'] );
	}

	public function test_no_spike_below_the_anomaly_floor(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1000, 'min_word_count' => 0, 'anomaly_min_count' => 10 ) );

		$captured = array();
		add_action( 'mcpwp_event_emitted', function ( $event ) use ( &$captured ) { $captured[] = $event; }, 10, 1 );

		for ( $i = 0; $i < 5; $i++ ) {
			wp_insert_post( array( 'post_type' => 'post', 'post_title' => "Normal $i", 'post_status' => 'publish', 'post_content' => $this->words( 5 ) ) );
		}

		$spikes = array_filter( $captured, function ( $e ) { return isset( $e['type'] ) && 'content.publish_spike' === $e['type']; } );
		$this->assertEmpty( $spikes );
	}

	// -------------------------------------------------------------------------
	// Performance guard

	public function test_duplicate_check_against_200_10k_word_posts_stays_within_budget(): void {
		// #957 adversarial review round 3 PERF: "200 candidates x 3,000
		// tokens finishes in under 2s." Each seeded post is 10k words —
		// each one's OWN analysis is capped to MAX_WORDS_PER_DOCUMENT
		// (3,000) tokens by normalize_words() regardless, so this is
		// exactly the named benchmark, at the plugin's real public API.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_compare_limit' => 200, 'daily_publish_cap' => 1000 ) );

		for ( $i = 1; $i <= 200; $i++ ) {
			$this->seed_published( $i, 'post', "Post Number $i", $this->words( 10000, "p{$i}_w" ) );
		}

		$start = microtime( true );
		$mem_start = memory_get_usage();

		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'A brand new post', 'post_status' => 'publish', 'post_content' => $this->words( 10000, 'new_w' ) ) );

		$elapsed   = microtime( true ) - $start;
		$mem_delta = memory_get_usage() - $mem_start;

		fwrite( STDERR, sprintf( "\n[perf] 200 candidates x ~3000-token cap: %.4fs, %.2fMB\n", $elapsed, $mem_delta / 1024 / 1024 ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][ $id ]->post_status );
		$this->assertLessThan( 2.0, $elapsed, '200 candidates x 3,000 tokens must finish in under 2s (#957 round 3 PERF)' );
		// Gross process memory delta — 200 seeded WP_Post-shaped test
		// fixtures plus get_posts()'s own result set dominate this, not
		// just the candidate-analysis cache (which is separately capped at
		// its own ~32MB via MAX_TOKEN_CACHE_BYTES) — kept at the same bound
		// round 1's original version of this test used.
		$this->assertLessThan( 64 * 1024 * 1024, $mem_delta, 'per-document truncation + a bounded, memory-guarded token cache must keep memory well under 64MB' );
	}

	// -------------------------------------------------------------------------
	// NIT-2: record only after the write succeeds; dedupe one save -> one advisory

	public function test_failed_write_leaves_no_phantom_advisory(): void {
		// #957 adversarial review round 4 NIT-2: the post_content advisory
		// DECISION runs inside wp_insert_post_data, BEFORE the row is
		// written; recording it is deferred to `post_updated`, which only
		// fires once the write actually lands. A write that fails after
		// the filter returns (a page-template validation error here) must
		// leave no phantom notice or Control Room issue.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 2051, 'page', 'Existing Page', $this->words( 40, 'dup_' ) );
		$this->seed_published( 2052, 'page', 'Target Page', $this->words( 40, 'orig_' ) );

		$GLOBALS['mcpwp_test_page_templates'] = array(); // Any template is now invalid.
		$result = wp_update_post( array( 'ID' => 2052, 'post_content' => $this->words( 40, 'dup_' ), 'page_template' => 'invalid-template' ), true );
		$this->assertInstanceOf( 'WP_Error', $result, 'sanity: the write itself really did fail' );

		$response = array( 'id' => 2052 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertArrayNotHasKey( 'guardrails', $response, 'a failed write leaves no phantom advisory notice' );

		$issues = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 2052, 'reveal_non_public' => true ) );
		$this->assertEmpty( $issues['issues'], 'and no phantom Control Room issue either' );

		unset( $GLOBALS['mcpwp_test_page_templates'] );
	}

	public function test_one_elementor_save_produces_one_advisory_not_two(): void {
		// #957 adversarial review round 4 NIT-2: "dedupe so one Elementor
		// save produces one advisory, not two" — simulates the real
		// double-write (Document::save() + the writer trait's explicit
		// re-write of the SAME final value).
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 2041, 'page', 'Elementor Page', '' );
		$GLOBALS['_mcpwp_test_meta'][2041]['_elementor_data'] = wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'title' => 'v0' ) ) ) );
		$this->seed_published( 2042, 'page', 'Sibling Page', $this->words( 40, 'dup_' ) );

		Mcpwp_Content_Guardrails::enter_context();

		$new_elementor = wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'title' => $this->words( 40, 'dup_' ) ) ) ) );
		update_post_meta( 2041, '_elementor_data', $new_elementor ); // Document::save()'s own write.
		update_post_meta( 2041, '_elementor_data', $new_elementor ); // The writer trait's explicit re-write, same value.

		$response = array( 'id' => 2041 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertCount( 1, $response['warnings'], 'one logical save produces one advisory, not two' );
	}

	public function test_last_advised_hash_is_not_the_raw_text(): void {
		// #957 adversarial review round 4 NIT-2: "store a hash, not the
		// raw text" in the per-post dedupe map.
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );
		$this->seed_published( 2061, 'page', 'Elementor Page', '' );
		$this->seed_published( 2062, 'page', 'Sibling Page', $this->words( 40, 'dup_' ) );

		Mcpwp_Content_Guardrails::enter_context();
		$long_text = $this->words( 40, 'dup_' );
		update_post_meta( 2061, '_elementor_data', wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'title' => $long_text ) ) ) ) );

		$rc   = new ReflectionProperty( 'Mcpwp_Content_Guardrails', 'last_advised_text_by_post' );
		$rc->setAccessible( true );
		$map  = $rc->getValue();
		$this->assertArrayHasKey( 2061, $map );
		$this->assertSame( 32, strlen( $map[2061] ), 'an md5 hash is 32 hex chars — not the ~240-char raw text' );
		$this->assertNotSame( $long_text, $map[2061] );
	}

	// -------------------------------------------------------------------------
	// NIT-3: advisory work is bounded per request

	public function test_advisory_checks_are_bounded_per_request(): void {
		// #957 adversarial review round 4 NIT-3: "bound the advisory work
		// per request ... so a 100-page find-replace stays fast." 25
		// different already-published pages edited into duplicates in ONE
		// request — only the first MAX_ADVISORY_CHECKS_PER_REQUEST (20)
		// actually run the duplicate check; the rest are skipped and
		// reported ONCE, in aggregate.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 3000, 'page', 'Source Page', $this->words( 40, 'dup_' ) );

		$items = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$post_id = 3000 + $i;
			$this->seed_published( $post_id, 'page', "Page $i", $this->words( 40, "orig{$i}_" ) );
			wp_update_post( array( 'ID' => $post_id, 'post_content' => $this->words( 40, 'dup_' ) ) );
			$items[] = array( 'id' => $post_id );
		}

		$response = array( 'items' => $items );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );

		$flagged = 0;
		foreach ( $response['items'] as $item ) {
			if ( isset( $item['guardrails']['content_edit_advisory'] ) ) {
				$flagged++;
			}
		}
		$this->assertSame( 20, $flagged, 'exactly MAX_ADVISORY_CHECKS_PER_REQUEST advisories actually ran' );
		$this->assertArrayHasKey( 'guardrails_advisory_note', $response );
		$this->assertStringContainsString( '5', $response['guardrails_advisory_note'], '25 - 20 = 5 skipped' );

		// #957 adversarial review round 5 NIT-3: "record ONE guardrail
		// issue for the request ... with the post IDs (capped list), so
		// it leaves a trace in the Control Room."
		$issues = Mcpwp_SEO_Audit_Store::list_issues( array( 'reveal_non_public' => true, 'limit' => 200 ) );
		$skip_issues = array_values( array_filter( $issues['issues'], function ( $i ) { return 'advisory_checks_skipped' === $i['code']; } ) );
		$this->assertCount( 1, $skip_issues, 'exactly ONE issue for the whole request, not one per skipped post' );
		$this->assertStringContainsString( '5', $skip_issues[0]['message'] );
		// One of the 5 actually-skipped post IDs (3021-3025, the 21st-25th
		// iterations) must be named.
		$this->assertMatchesRegularExpression( '/302[1-5]/', $skip_issues[0]['message'] );
	}

	// -------------------------------------------------------------------------
	// NIT-4: guardrail issues resolve and expire

	public function test_a_later_clean_advisory_check_resolves_the_open_duplicate_edit_issue(): void {
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 7001, 'page', 'Existing Page', $this->words( 40, 'dup_' ) );
		$this->seed_published( 7002, 'page', 'Target Page', $this->words( 40, 'orig_' ) );

		// First edit: into a duplicate — opens the issue.
		wp_update_post( array( 'ID' => 7002, 'post_content' => $this->words( 40, 'dup_' ) ) );

		$issues = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 7002, 'reveal_non_public' => true ) );
		$this->assertSame( 'open', $issues['issues'][0]['status'] );

		// Second edit: back to genuinely unique content — a REAL check
		// runs (different text) and scores clean, resolving the issue.
		wp_update_post( array( 'ID' => 7002, 'post_content' => $this->words( 40, 'genuinelyunique_' ) ) );

		$issues_after = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 7002, 'reveal_non_public' => true ) );
		$duplicate_edit_issue = array_values( array_filter( $issues_after['issues'], function ( $i ) { return 'duplicate_edit' === $i['code']; } ) );
		$this->assertSame( 'resolved', $duplicate_edit_issue[0]['status'], 'a later clean check resolves the open issue' );
	}

	public function test_a_flagged_elementor_duplicate_plus_a_trivial_post_content_edit_stays_open(): void {
		// #957 adversarial review round 6 NIT-1: "resolve a duplicate_edit
		// issue only when the SAME representation that raised it is
		// re-checked." An Elementor-raised issue must not be resolved by
		// an unrelated, genuinely-clean post_content edit to the same
		// post — the Elementor content that caused the flag was never
		// re-examined at all.
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 7101, 'page', 'Sibling Page', $this->words( 40, 'dup_' ) );
		$this->seed_published( 7102, 'page', 'Target Page', '' );
		$GLOBALS['_mcpwp_test_meta'][7102]['_elementor_data'] = wp_json_encode( array( array(
			'elType' => 'widget', 'settings' => array( 'title' => 'v0' ),
		) ) );

		Mcpwp_Content_Guardrails::enter_context();

		// Flag it via Elementor.
		update_post_meta( 7102, '_elementor_data', wp_json_encode( array( array(
			'elType' => 'widget', 'settings' => array( 'title' => $this->words( 40, 'dup_' ) ),
		) ) ) );

		$issues = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 7102, 'reveal_non_public' => true ) );
		$duplicate_edit_issue = array_values( array_filter( $issues['issues'], function ( $i ) { return 'duplicate_edit' === $i['code']; } ) );
		$this->assertSame( 'open', $duplicate_edit_issue[0]['status'] );
		$this->assertSame( 'elementor', $duplicate_edit_issue[0]['representation'] );

		// Now a TRIVIAL, genuinely-unrelated post_content edit — a real
		// check runs (different text) and scores clean for post_content,
		// but must NOT resolve the Elementor-raised issue.
		wp_update_post( array( 'ID' => 7102, 'post_content' => $this->words( 20, 'trivialedit_' ) ) );

		$issues_after = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 7102, 'reveal_non_public' => true ) );
		$duplicate_edit_issue_after = array_values( array_filter( $issues_after['issues'], function ( $i ) { return 'duplicate_edit' === $i['code']; } ) );
		$this->assertSame( 'open', $duplicate_edit_issue_after[0]['status'], 'a clean post_content check must not resolve an Elementor-raised issue' );
	}

	public function test_guardrail_issues_expire_after_30_days(): void {
		Mcpwp_SEO_Audit_Store::upsert_issue( array(
			'post_id'  => 7011,
			'title'    => 'A Page',
			'url'      => 'http://example.test/a-page/',
			'type'     => 'page',
			'category' => 'content_guardrails',
			'code'     => 'duplicate_edit',
			'severity' => 'warning',
			'message'  => 'Looks like a duplicate.',
		) );

		// Age the stored record past the 30-day expiry window directly —
		// no clock-mocking needed, this store keys off last_seen_at.
		$option_name = Mcpwp_SEO_Audit_Store::GUARDRAIL_ISSUES_OPTION;
		$stored = get_option( $option_name, array() );
		$key = array_key_first( $stored );
		$stored[ $key ]['last_seen_at'] = gmdate( 'c', time() - ( 31 * DAY_IN_SECONDS ) );
		update_option( $option_name, $stored, false );

		$issues = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 7011, 'reveal_non_public' => true ) );
		$this->assertSame( 'resolved', $issues['issues'][0]['status'], 'an OPEN issue not re-flagged in 30+ days expires on its own' );
	}

	// -------------------------------------------------------------------------
	// Elementor text extraction

	public function test_extract_text_from_elementor_pulls_widget_copy(): void {
		$data = array(
			array(
				'elType'   => 'section',
				'elements' => array(
					array(
						'elType'   => 'widget',
						'settings' => array( 'title' => 'Hello World', 'editor' => '<p>Some body copy here.</p>' ),
					),
				),
			),
		);
		$text = Mcpwp_Content_Guardrails::extract_text_from_elementor( $data );
		$this->assertStringContainsString( 'Hello World', $text );
		$this->assertStringContainsString( 'Some body copy here.', $text );
	}

	// -------------------------------------------------------------------------
	// WARN-1/NIT-1: deep recursion through settings, allowlist, templates

	public function test_extract_text_from_elementor_excludes_dynamic_globals_link_and_image_and_enum_values(): void {
		// #957 adversarial review round 5 NIT-1: an ALLOWLIST of copy
		// keys, never including __dynamic__/__globals__ bindings, a
		// link/image control's own sub-keys, or enum-like tokens sitting
		// bare in a list.
		$data = array( array(
			'elType'   => 'widget',
			'settings' => array(
				'title'       => 'Real Copy Title',
				'__dynamic__' => array( 'title' => '[elementor-tag id="abc123" name="post-title"]' ),
				'__globals__' => array( 'title_color' => 'globals/colors?id=xyz' ),
				'link'        => array( 'url' => 'https://example.test/', 'is_external' => '', 'nofollow' => '' ),
				'image'       => array( 'id' => 42, 'url' => 'https://example.test/pic.jpg', 'alt' => 'a photo' ),
				'alignment'   => array( 'left', 'center' ), // enum-like tokens in a bare list.
			),
		) );
		$text = Mcpwp_Content_Guardrails::extract_text_from_elementor( $data );
		$this->assertSame( 'Real Copy Title', $text );
	}

	public function test_extract_text_from_elementor_pulls_icon_box_title_and_description(): void {
		$data = array( array(
			'elType'   => 'widget',
			'widgetType' => 'icon-box',
			'settings' => array(
				'title_text'       => 'Fast Shipping',
				'description_text' => 'Orders ship within 24 hours of purchase confirmation.',
				'title_color'      => '#FF0000',
				'icon_color'       => 'rgba(0,0,0,0.5)',
				'selected_icon'    => array( 'value' => 'fas fa-truck', 'library' => 'fa-solid' ),
			),
		) );
		$text = Mcpwp_Content_Guardrails::extract_text_from_elementor( $data );
		$this->assertStringContainsString( 'Fast Shipping', $text );
		$this->assertStringContainsString( 'Orders ship within 24 hours of purchase confirmation.', $text );
		$this->assertStringNotContainsString( '#FF0000', $text );
		$this->assertStringNotContainsString( 'rgba(0,0,0,0.5)', $text );
	}

	public function test_extract_text_from_elementor_pulls_icon_list_repeater_items(): void {
		$data = array( array(
			'elType'     => 'widget',
			'widgetType' => 'icon-list',
			'settings'   => array(
				'icon_list' => array(
					array( 'text' => 'Free returns within 30 days', 'selected_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ) ),
					array( 'text' => 'Lifetime warranty on all parts', 'selected_icon' => array( 'value' => 'fas fa-check', 'library' => 'fa-solid' ) ),
				),
			),
		) );
		$text = Mcpwp_Content_Guardrails::extract_text_from_elementor( $data );
		$this->assertStringContainsString( 'Free returns within 30 days', $text );
		$this->assertStringContainsString( 'Lifetime warranty on all parts', $text );
	}

	public function test_extract_text_from_elementor_pulls_testimonial_content(): void {
		$data = array( array(
			'elType'     => 'widget',
			'widgetType' => 'testimonial',
			'settings'   => array(
				'testimonial_content' => 'This product changed how our whole team works day to day.',
				'testimonial_name'    => 'Jordan Rivera',
				'testimonial_image'   => array( 'id' => 42, 'url' => 'https://example.test/jordan.jpg' ),
			),
		) );
		$text = Mcpwp_Content_Guardrails::extract_text_from_elementor( $data );
		$this->assertStringContainsString( 'This product changed how our whole team works day to day.', $text );
		$this->assertStringContainsString( 'Jordan Rivera', $text );
		$this->assertStringNotContainsString( 'https://example.test/jordan.jpg', $text );
	}

	public function test_extract_text_from_elementor_covers_every_common_widget_copy_key(): void {
		// #957 adversarial review round 6 WARN-1: "a pure allowlist will
		// keep missing keys" — one 17-word unique sentence per common
		// Elementor widget's real copy key(s); at least 15 of the 17 words
		// must come through for every one of them, via either the
		// explicit allowlist or the hybrid's copy-pattern match.
		$sentence = function ( $seed ) {
			$words = array();
			for ( $i = 0; $i < 17; $i++ ) {
				$words[] = $seed . $i;
			}
			return implode( ' ', $words );
		};

		$fixtures = array(
			'heading'           => array( array( 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array(
				'title' => $sentence( 'heading' ),
			) ) ),
			'text-editor'       => array( array( 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => array(
				'editor' => $sentence( 'texteditor' ),
			) ) ),
			'button'            => array( array( 'elType' => 'widget', 'widgetType' => 'button', 'settings' => array(
				'text' => $sentence( 'buttonwidget' ),
			) ) ),
			'icon-box'          => array( array( 'elType' => 'widget', 'widgetType' => 'icon-box', 'settings' => array(
				'title_text' => $sentence( 'iconbox' ),
			) ) ),
			'image-box'         => array( array( 'elType' => 'widget', 'widgetType' => 'image-box', 'settings' => array(
				'description_text' => $sentence( 'imagebox' ),
			) ) ),
			'icon-list'         => array( array( 'elType' => 'widget', 'widgetType' => 'icon-list', 'settings' => array(
				'icon_list' => array( array( 'text' => $sentence( 'iconlist' ) ) ),
			) ) ),
			'testimonial'       => array( array( 'elType' => 'widget', 'widgetType' => 'testimonial', 'settings' => array(
				'testimonial_content' => $sentence( 'testimonialwidget' ),
			) ) ),
			'tabs'              => array( array( 'elType' => 'widget', 'widgetType' => 'tabs', 'settings' => array(
				'tabs' => array( array( 'tab_content' => $sentence( 'tabswidget' ) ) ),
			) ) ),
			'accordion'         => array( array( 'elType' => 'widget', 'widgetType' => 'accordion', 'settings' => array(
				'tabs' => array( array( 'tab_content' => $sentence( 'accordionwidget' ) ) ),
			) ) ),
			'toggle'            => array( array( 'elType' => 'widget', 'widgetType' => 'toggle', 'settings' => array(
				'tabs' => array( array( 'tab_content' => $sentence( 'togglewidget' ) ) ),
			) ) ),
			'price-table'       => array( array( 'elType' => 'widget', 'widgetType' => 'price-table', 'settings' => array(
				'sub_heading' => $sentence( 'pricetable' ),
			) ) ),
			'call-to-action'    => array( array( 'elType' => 'widget', 'widgetType' => 'call-to-action', 'settings' => array(
				'button' => $sentence( 'ctawidget' ),
			) ) ),
			'flip-box'          => array( array( 'elType' => 'widget', 'widgetType' => 'flip-box', 'settings' => array(
				'title_text_a' => $sentence( 'flipbox' ),
			) ) ),
			'animated-headline' => array( array( 'elType' => 'widget', 'widgetType' => 'animated-headline', 'settings' => array(
				'rotating_text' => $sentence( 'animatedheadline' ),
			) ) ),
			'form'              => array( array( 'elType' => 'widget', 'widgetType' => 'form', 'settings' => array(
				'form_fields' => array( array( 'field_label' => $sentence( 'formwidget' ) ) ),
			) ) ),
			'progress'          => array( array( 'elType' => 'widget', 'widgetType' => 'progress', 'settings' => array(
				'inner_text' => $sentence( 'progresswidget' ),
			) ) ),
			'alert'             => array( array( 'elType' => 'widget', 'widgetType' => 'alert', 'settings' => array(
				'alert_title' => $sentence( 'alertwidget' ),
			) ) ),
			'blockquote'        => array( array( 'elType' => 'widget', 'widgetType' => 'blockquote', 'settings' => array(
				'blockquote_content' => $sentence( 'blockquotewidget' ),
			) ) ),
		);

		foreach ( $fixtures as $widget => $data ) {
			$text                  = Mcpwp_Content_Guardrails::extract_text_from_elementor( $data );
			$extracted_word_count  = count( array_filter( explode( ' ', trim( $text ) ) ) );
			$this->assertGreaterThanOrEqual( 15, $extracted_word_count, "widget '$widget' extracted only $extracted_word_count/17 words: '$text'" );
		}
	}

	public function test_round6_flip_box_price_table_clone_with_filler_post_content_is_refused_at_publish(): void {
		// Re-run of the round-4 BLOCK-1 bypass shape, this time with
		// widgets the PURE allowlist (round 5) missed entirely — a
		// flip-box + price-table clone would have extracted as empty text
		// under round 5, so this exact sequence would have published
		// unflagged. Confirms round 6's hybrid allowlist closes it.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.5, 'daily_publish_cap' => 1000 ) );

		$flip_text  = 'Save twenty percent on every single order placed before midnight tonight only for new customers everywhere';
		$price_text = 'Our most popular plan includes unlimited storage and priority support for every growing team worldwide';

		$this->seed_published( 8001, 'page', 'Existing Offer Page', '' );
		$GLOBALS['_mcpwp_test_meta'][8001]['_elementor_data'] = wp_json_encode( array(
			array( 'elType' => 'widget', 'widgetType' => 'flip-box', 'settings' => array( 'title_text_a' => $flip_text ) ),
			array( 'elType' => 'widget', 'widgetType' => 'price-table', 'settings' => array( 'sub_heading' => $price_text ) ),
		) );

		// 1. Create a draft.
		$this->seed_draft( 8002, 'page', 'New Offer Page', '' );

		// 2. wp_set_elementor a clone of the existing page onto it (still a draft).
		$GLOBALS['_mcpwp_test_meta'][8002]['_elementor_data'] = wp_json_encode( array(
			array( 'elType' => 'widget', 'widgetType' => 'flip-box', 'settings' => array( 'title_text_a' => $flip_text ) ),
			array( 'elType' => 'widget', 'widgetType' => 'price-table', 'settings' => array( 'sub_heading' => $price_text ) ),
		) );

		// 3. update_page: unique filler words + status=publish.
		$filler = $this->words( 30, 'zzfiller6_' );
		wp_update_post( array( 'ID' => 8002, 'post_content' => $filler, 'post_status' => 'publish' ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][8002]->post_status, 'the flip-box/price-table clone is caught even though post_content alone was unique filler' );
	}

	public function test_extract_text_from_elementor_never_expands_a_referenced_template(): void {
		// #957 adversarial review round 5 WARN-1: a template's OWN text is
		// NEVER pulled into a page's comparable text — round 4 briefly did
		// this, which made every sibling page built from the same shared
		// template score 0.6-0.8 against each other, a false positive.
		$this->seed_published( 5001, 'elementor_library', 'A Template', '' );
		$GLOBALS['_mcpwp_test_meta'][5001]['_elementor_data'] = wp_json_encode( array( array(
			'elType' => 'widget', 'settings' => array( 'title' => 'Template Headline Text' ),
		) ) );

		$data = array( array(
			'elType'     => 'widget',
			'widgetType' => 'template',
			'settings'   => array( 'template_id' => 5001 ),
		) );
		$text = Mcpwp_Content_Guardrails::extract_text_from_elementor( $data );
		$this->assertStringNotContainsString( 'Template Headline Text', $text );
		$this->assertSame( '', $text, 'a page with ONLY a template reference and no own copy extracts as empty' );
	}

	public function test_measure_embedded_templates_reports_template_word_count_without_including_it_in_text(): void {
		// The one-level lookup that remains is measurement-only — it feeds
		// the thin-content warning's wording, never duplicate detection.
		$this->seed_published( 5002, 'elementor_library', 'A Template', '' );
		$template_body = $this->words( 400, 'tpl' );
		$GLOBALS['_mcpwp_test_meta'][5002]['_elementor_data'] = wp_json_encode( array( array(
			'elType' => 'widget', 'settings' => array( 'content' => $template_body ),
		) ) );

		$data = array( array(
			'elType' => 'widget', 'settings' => array( 'content' => 'Five short own words here today', 'template_id' => 5002 ),
		) );

		$rc = new ReflectionMethod( 'Mcpwp_Content_Guardrails', 'measure_embedded_templates' );
		$rc->setAccessible( true );
		$info = $rc->invoke( null, $data );

		$this->assertTrue( $info['has_templates'] );
		$this->assertSame( 400, $info['template_word_count'] );

		// And this same $data's OWN extracted text never includes the
		// template's 400 words.
		$text = Mcpwp_Content_Guardrails::extract_text_from_elementor( $data );
		$this->assertStringNotContainsString( 'tpl0', $text );
	}

	public function test_shared_template_no_longer_makes_siblings_score_as_duplicates(): void {
		// #957 adversarial review round 5 WARN-1 — the false positive
		// round 4's template expansion created: 150 unique words + a
		// 400-word SHARED template vs. a sibling with DIFFERENT unique
		// words + the SAME template must not be flagged. Under round 4
		// this scored 0.6-0.77 (the shared template dominating the
		// comparison); under round 5 it must score near zero, since only
		// each page's own text is ever compared.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.5, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 6001, 'elementor_library', 'Shared Template', '' );
		$GLOBALS['_mcpwp_test_meta'][6001]['_elementor_data'] = wp_json_encode( array( array(
			'elType' => 'widget', 'settings' => array( 'content' => $this->words( 400, 'tpl' ) ),
		) ) );

		// Sibling, already published: 150 unique words + the shared template.
		$this->seed_published( 6002, 'page', 'Sibling Page', '' );
		$GLOBALS['_mcpwp_test_meta'][6002]['_elementor_data'] = wp_json_encode( array(
			array( 'elType' => 'widget', 'settings' => array( 'content' => $this->words( 150, 'siblingwords' ) ) ),
			array( 'elType' => 'widget', 'settings' => array( 'template_id' => 6001 ) ),
		) );

		// New page: 150 DIFFERENT unique words + the SAME shared template.
		$this->seed_draft( 6003, 'page', 'New Page', '' );
		$GLOBALS['_mcpwp_test_meta'][6003]['_elementor_data'] = wp_json_encode( array(
			array( 'elType' => 'widget', 'settings' => array( 'content' => $this->words( 150, 'newwords' ) ) ),
			array( 'elType' => 'widget', 'settings' => array( 'template_id' => 6001 ) ),
		) );

		wp_update_post( array( 'ID' => 6003, 'post_status' => 'publish' ) );

		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][6003]->post_status, 'a shared template must not, by itself, make two otherwise-unique pages duplicates of each other' );
	}

	public function test_icon_box_city_page_duplicate_is_still_refused_after_the_template_fix(): void {
		// The round 4 WARN-1 fix (deep recursion through icon-box/repeater
		// widgets) must still work after round 5 removed template
		// expansion — the two fixes are independent.
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.5, 'daily_publish_cap' => 1000 ) );

		$sibling_body = 'Fast local delivery within 24 hours guaranteed every single time for every customer near you today. Friendly expert support available around the clock every single day of the week for everyone.';
		$this->seed_published( 6011, 'page', 'Denver Service Overview', $sibling_body );

		$this->seed_draft( 6012, 'page', 'Aurora Service Overview', '' );
		$GLOBALS['_mcpwp_test_meta'][6012]['_elementor_data'] = wp_json_encode( array(
			array( 'elType' => 'widget', 'widgetType' => 'icon-box', 'settings' => array(
				'title_text' => 'Fast local delivery within 24 hours guaranteed every single time for every customer near you today.',
				'title_color' => '#FF0000',
			) ),
			array( 'elType' => 'widget', 'widgetType' => 'icon-box', 'settings' => array(
				'title_text' => 'Friendly expert support available around the clock every single day of the week for everyone.',
				'title_color' => '#0000FF',
			) ),
		) );

		wp_update_post( array( 'ID' => 6012, 'post_status' => 'publish' ) );

		$this->assertSame( 'draft', $GLOBALS['_mcpwp_test_posts'][6012]->post_status, 'the icon-box-built duplicate is still caught' );
	}

	public function test_five_words_plus_a_template_publishes_with_thin_and_template_warning(): void {
		// #957 adversarial review round 5 WARN-1: "5 unique words + a
		// template → publishes with the thin/template warning."
		Mcpwp_Content_Guardrails::enter_context();
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150, 'duplicate_threshold' => 0.9, 'daily_publish_cap' => 1000 ) );

		$this->seed_published( 6021, 'elementor_library', 'Shared Template', '' );
		$GLOBALS['_mcpwp_test_meta'][6021]['_elementor_data'] = wp_json_encode( array( array(
			'elType' => 'widget', 'settings' => array( 'content' => $this->words( 400, 'tpl' ) ),
		) ) );

		$this->seed_draft( 6022, 'page', 'Thin Page', '' );
		$GLOBALS['_mcpwp_test_meta'][6022]['_elementor_data'] = wp_json_encode( array(
			array( 'elType' => 'widget', 'settings' => array( 'content' => 'Five unique own words here' ) ),
			array( 'elType' => 'widget', 'settings' => array( 'template_id' => 6021 ) ),
		) );

		$result = wp_update_post( array( 'ID' => 6022, 'post_status' => 'publish' ) );

		$this->assertSame( 6022, $result );
		$this->assertSame( 'publish', $GLOBALS['_mcpwp_test_posts'][6022]->post_status, 'thin content is warning-only, never a reason to downgrade' );

		$response = array( 'id' => 6022 );
		Mcpwp_Content_Guardrails::attach_notices_to_response( $response );
		$this->assertTrue( $response['guardrails']['thin_content']['is_thin'] );
		$warning_text = implode( ' ', $response['warnings'] );
		$this->assertStringContainsString( 'shared templates', $warning_text );
	}

	// -------------------------------------------------------------------------
	// WARN-3: guardrail advisories don't live/die with SEO audit runs

	public function test_guardrail_advisory_survives_an_seo_audit_run_that_covers_the_same_post(): void {
		// #957 adversarial review round 4 WARN-3: an SEO audit run that
		// DOES cover this exact post (but reports DIFFERENT findings, not
		// this advisory) must not resolve the advisory — it lives in a
		// store store_issues_for_run() structurally cannot see.
		Mcpwp_SEO_Audit_Store::upsert_issue( array(
			'post_id'  => 9001,
			'title'    => 'A Page',
			'url'      => 'http://example.test/a-page/',
			'type'     => 'page',
			'category' => 'content_guardrails',
			'code'     => 'duplicate_edit',
			'severity' => 'warning',
			'message'  => 'Looks like a duplicate.',
			'recommendation' => 'Review it.',
		) );

		Mcpwp_SEO_Audit_Store::store_run( array(
			'urls' => array(
				array(
					'id'         => 9001,
					'title'      => 'A Page',
					'url'        => 'http://example.test/a-page/',
					'type'       => 'page',
					'top_issues' => array(
						array( 'category' => 'meta', 'code' => 'missing_meta_description', 'severity' => 'warning', 'message' => 'No meta description.' ),
					),
				),
			),
		) );

		$result   = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 9001, 'reveal_non_public' => true, 'limit' => 200 ) );
		$advisory = array_values( array_filter( $result['issues'], function ( $i ) { return 'content_guardrails' === $i['category']; } ) );
		$this->assertCount( 1, $advisory );
		$this->assertSame( 'open', $advisory[0]['status'], 'the guardrail advisory is still OPEN after an audit run covering the same post' );

		$seo_issue = array_values( array_filter( $result['issues'], function ( $i ) { return 'meta' === $i['category']; } ) );
		$this->assertCount( 1, $seo_issue );
		$this->assertSame( 'open', $seo_issue[0]['status'] );
	}

	public function test_300_guardrail_advisories_never_evict_a_real_seo_issue(): void {
		Mcpwp_SEO_Audit_Store::store_run( array(
			'urls' => array(
				array(
					'id'         => 9101,
					'title'      => 'Important Page',
					'url'        => 'http://example.test/important/',
					'type'       => 'page',
					'top_issues' => array(
						array( 'category' => 'meta', 'code' => 'missing_meta_description', 'severity' => 'warning', 'message' => 'No meta description.' ),
					),
				),
			),
		) );

		for ( $i = 1; $i <= 300; $i++ ) {
			Mcpwp_SEO_Audit_Store::upsert_issue( array(
				'post_id'  => 9200 + $i,
				'title'    => "Page $i",
				'url'      => "http://example.test/page-$i/",
				'type'     => 'page',
				'category' => 'content_guardrails',
				'code'     => 'duplicate_edit',
				'severity' => 'warning',
				'message'  => 'Looks like a duplicate.',
				'recommendation' => 'Review it.',
			) );
		}

		$result = Mcpwp_SEO_Audit_Store::list_issues( array( 'post_id' => 9101, 'reveal_non_public' => true ) );
		$this->assertNotEmpty( $result['issues'], '300 advisories must never evict a real SEO issue — separately capped stores' );
	}
}
