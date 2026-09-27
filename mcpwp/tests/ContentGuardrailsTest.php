<?php
/**
 * Coverage for #957 — guardrails against scaled and duplicate publishing:
 * the publishing ramp, the bulk-approval threshold, thin/near-duplicate
 * detection (including the city-swap heuristic), the anomaly signal,
 * settings bounds, and a performance guard on the duplicate comparison.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-content-guardrails.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-integration-manager.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';

final class ContentGuardrailsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['_mcpwp_test_posts']      = array();
		$GLOBALS['_mcpwp_test_meta']       = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
		$GLOBALS['mcpwp_test_actions']['mcpwp_event_emitted'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();
	}

	/**
	 * Words like "word0 word1 word2 ..." — enough to clear the default
	 * 150-word floor and to be trivially distinguishable from other bodies
	 * for the shingle comparison.
	 */
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

	// -------------------------------------------------------------------------
	// Rule 1: publishing ramp

	public function test_ramp_under_cap_allows_publish(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 3, 'min_word_count' => 0 ) );
		$now = 1000000;

		for ( $i = 0; $i < 2; $i++ ) {
			$result = Mcpwp_Content_Guardrails::evaluate(
				array( 'post_type' => 'post', 'status' => 'publish', 'title' => "Post $i", 'content' => $this->words( 10 ), 'now' => $now )
			);
			$this->assertSame( 'publish', $result['status'] );
			$this->assertFalse( $result['downgraded'] );
		}

		$this->assertSame( 2, Mcpwp_Content_Guardrails::count_published_last_24h( $now ) );
	}

	public function test_ramp_exactly_at_cap_still_publishes(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 3, 'min_word_count' => 0 ) );
		$now = 1000000;

		for ( $i = 0; $i < 3; $i++ ) {
			$result = Mcpwp_Content_Guardrails::evaluate(
				array( 'post_type' => 'post', 'status' => 'publish', 'title' => "Post $i", 'content' => $this->words( 10 ), 'now' => $now )
			);
			$this->assertSame( 'publish', $result['status'], "item $i should still publish at the cap" );
		}
	}

	public function test_ramp_over_cap_downgrades_to_draft_with_warning(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 2, 'min_word_count' => 0 ) );
		$now = 1000000;

		for ( $i = 0; $i < 2; $i++ ) {
			Mcpwp_Content_Guardrails::evaluate(
				array( 'post_type' => 'post', 'status' => 'publish', 'title' => "Post $i", 'content' => $this->words( 10 ), 'now' => $now )
			);
		}

		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'Post 3', 'content' => $this->words( 10 ), 'now' => $now )
		);

		$this->assertSame( 'draft', $result['status'] );
		$this->assertTrue( $result['downgraded'] );
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertSame( 2, $result['guardrails']['ramp']['published_last_24h'] );

		// The rejected publish must not itself count against the ramp.
		$this->assertSame( 2, Mcpwp_Content_Guardrails::count_published_last_24h( $now ) );
	}

	public function test_ramp_has_no_admin_key_exemption(): void {
		// #957 rule 1: "admin keys included — this is a site-level safety
		// valve." evaluate() takes no key-scope/identity argument at all —
		// there is nowhere for an exemption to hide.
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1, 'min_word_count' => 0 ) );
		$now = 1000000;

		Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'First', 'content' => $this->words( 10 ), 'now' => $now )
		);
		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'Second (admin key)', 'content' => $this->words( 10 ), 'now' => $now )
		);

		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_drafts_are_never_ramp_capped(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1 ) );
		$now = 1000000;

		Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'Uses the cap', 'content' => $this->words( 200 ), 'now' => $now )
		);

		for ( $i = 0; $i < 10; $i++ ) {
			$result = Mcpwp_Content_Guardrails::evaluate(
				array( 'post_type' => 'post', 'status' => 'draft', 'title' => "Draft $i", 'content' => 'short', 'now' => $now )
			);
			$this->assertSame( 'draft', $result['status'] );
			$this->assertFalse( $result['downgraded'] );
			$this->assertEmpty( $result['warnings'] );
		}
	}

	public function test_disabled_guardrails_never_downgrade(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'enabled' => false, 'daily_publish_cap' => 1 ) );
		$now = 1000000;

		Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'A', 'content' => $this->words( 5 ), 'now' => $now )
		);
		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'B', 'content' => $this->words( 5 ), 'now' => $now )
		);

		$this->assertSame( 'publish', $result['status'] );
		$this->assertFalse( $result['guardrails']['enabled'] );
	}

	public function test_non_covered_post_type_is_never_capped(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 1 ) );
		$now = 1000000;

		for ( $i = 0; $i < 5; $i++ ) {
			$result = Mcpwp_Content_Guardrails::evaluate(
				array( 'post_type' => 'product', 'status' => 'publish', 'title' => "Product $i", 'content' => 'x', 'now' => $now )
			);
			$this->assertSame( 'publish', $result['status'] );
			$this->assertFalse( $result['guardrails']['covered'] );
		}
	}

	// -------------------------------------------------------------------------
	// Rule 2: bulk-approval threshold

	public function test_bulk_plan_requires_approval_over_threshold(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 5 ) );
		$items = array_fill( 0, 6, array( 'status' => 'publish' ) );

		$plan = Mcpwp_Content_Guardrails::plan_bulk_publish( $items );

		$this->assertTrue( $plan['requires_approval'] );
		$this->assertSame( 6, $plan['publish_count'] );
		$this->assertSame( 5, $plan['threshold'] );
	}

	public function test_bulk_plan_exactly_at_threshold_does_not_require_approval(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 5 ) );
		$items = array_fill( 0, 5, array( 'status' => 'publish' ) );

		$plan = Mcpwp_Content_Guardrails::plan_bulk_publish( $items );

		$this->assertFalse( $plan['requires_approval'] );
	}

	public function test_bulk_plan_ignores_items_below_threshold(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 5 ) );
		$items = array_fill( 0, 4, array( 'status' => 'publish' ) );

		$plan = Mcpwp_Content_Guardrails::plan_bulk_publish( $items );

		$this->assertFalse( $plan['requires_approval'] );
	}

	public function test_bulk_plan_never_counts_drafts(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 2 ) );
		$items = array_fill( 0, 20, array( 'status' => 'draft' ) );

		$plan = Mcpwp_Content_Guardrails::plan_bulk_publish( $items );

		$this->assertFalse( $plan['requires_approval'] );
		$this->assertSame( 0, $plan['publish_count'] );
	}

	public function test_bulk_create_pages_over_threshold_queues_one_approval_and_drafts_every_item(): void {
		Mcpwp_Content_Guardrails::update_settings(
			array( 'bulk_approval_threshold' => 2, 'daily_publish_cap' => 1000, 'min_word_count' => 0 )
		);

		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/pages/bulk' );
		$pages      = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$pages[] = array( 'title' => "Page $i", 'content' => $this->words( 10 ), 'status' => 'publish' );
		}
		$request->set_param( 'pages', $pages );

		$response = $controller->bulk_create_pages( $request );
		$data     = $response->get_data();

		$this->assertCount( 3, $data['created'] );
		foreach ( $data['created'] as $page ) {
			$this->assertSame( 'draft', $page['status'], 'every item must be created as a draft, not just some' );
		}

		$this->assertTrue( $data['guardrails']['bulk_approval_required'] );
		$this->assertNotEmpty( $data['guardrails']['approval_id'] );

		$pending = Mcpwp_Approvals::list_requests( 'pending', 10, true );
		$this->assertCount( 1, $pending );
		$this->assertSame( 'bulk_content_publish', $pending[0]['action'] );
		$this->assertCount( 3, $pending[0]['payload']['post_ids'] );
	}

	public function test_bulk_create_pages_under_threshold_publishes_directly(): void {
		Mcpwp_Content_Guardrails::update_settings(
			array( 'bulk_approval_threshold' => 10, 'daily_publish_cap' => 1000, 'min_word_count' => 0 )
		);

		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/pages/bulk' );
		$request->set_param(
			'pages',
			array(
				array( 'title' => 'Page A', 'content' => $this->words( 10, 'alpha' ), 'status' => 'publish' ),
				array( 'title' => 'Page B', 'content' => $this->words( 10, 'beta' ), 'status' => 'publish' ),
			)
		);

		$response = $controller->bulk_create_pages( $request );
		$data     = $response->get_data();

		foreach ( $data['created'] as $page ) {
			$this->assertSame( 'publish', $page['status'] );
		}
		$this->assertArrayNotHasKey( 'guardrails', $data );
	}

	public function test_bulk_create_posts_over_threshold_queues_approval(): void {
		Mcpwp_Content_Guardrails::update_settings(
			array( 'bulk_approval_threshold' => 1, 'daily_publish_cap' => 1000, 'min_word_count' => 0 )
		);

		$controller = new Mcpwp_REST_Posts();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/posts/bulk' );
		$request->set_param(
			'posts',
			array(
				array( 'title' => 'Post A', 'content' => $this->words( 10 ), 'status' => 'publish' ),
				array( 'title' => 'Post B', 'content' => $this->words( 10 ), 'status' => 'publish' ),
			)
		);

		$response = $controller->bulk_create_posts( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['guardrails']['bulk_approval_required'] );
		foreach ( $data['created'] as $post ) {
			$this->assertSame( 'draft', $post['status'] );
		}
	}

	public function test_bulk_create_pages_a_batch_of_only_drafts_is_never_gated(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'bulk_approval_threshold' => 1, 'min_word_count' => 0 ) );

		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/pages/bulk' );
		$request->set_param(
			'pages',
			array(
				array( 'title' => 'Page A', 'content' => $this->words( 10 ), 'status' => 'draft' ),
				array( 'title' => 'Page B', 'content' => $this->words( 10 ), 'status' => 'draft' ),
				array( 'title' => 'Page C', 'content' => $this->words( 10 ), 'status' => 'draft' ),
			)
		);

		$response = $controller->bulk_create_pages( $request );
		$data     = $response->get_data();

		$this->assertArrayNotHasKey( 'guardrails', $data );
	}

	// -------------------------------------------------------------------------
	// Rule 3a: thin-content floor

	public function test_thin_published_content_downgrades_to_draft(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150 ) );

		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'Too short', 'content' => $this->words( 10 ) )
		);

		$this->assertSame( 'draft', $result['status'] );
		$this->assertTrue( $result['downgraded'] );
		$this->assertTrue( $result['guardrails']['thin_content']['is_thin'] );
		$this->assertSame( 10, $result['guardrails']['thin_content']['word_count'] );
	}

	public function test_content_at_the_floor_is_not_thin(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150 ) );

		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'Exactly enough', 'content' => $this->words( 150 ) )
		);

		$this->assertFalse( $result['guardrails']['thin_content']['is_thin'] );
		$this->assertSame( 'publish', $result['status'] );
	}

	public function test_thin_content_floor_is_ignored_for_drafts(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 150 ) );

		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'draft', 'title' => 'Tiny draft', 'content' => 'one two three' )
		);

		$this->assertSame( 'draft', $result['status'] );
		$this->assertFalse( $result['downgraded'] );
	}

	// -------------------------------------------------------------------------
	// Rule 3b: near-duplicate detection

	public function test_identical_content_is_flagged_and_downgraded(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6 ) );

		$body = 'Our roofing company installs and repairs residential roofs with quality materials and skilled crews every single week of the year across the region.';
		$this->seed_published( 501, 'page', 'Springfield Roofing Services', $body );

		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'page', 'status' => 'publish', 'title' => 'Springfield Roofing Services', 'content' => $body )
		);

		$this->assertSame( 'draft', $result['status'] );
		$this->assertTrue( $result['downgraded'] );
		$this->assertTrue( $result['guardrails']['duplicate']['flagged'] );
		$this->assertGreaterThanOrEqual( 0.6, $result['guardrails']['duplicate']['score'] );
		$this->assertSame( 501, $result['guardrails']['duplicate']['match_id'] );
	}

	public function test_near_identical_content_above_threshold_is_flagged(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6 ) );

		// 40 shared words, then 38 of the SAME shared words with just the
		// last 2 swapped — only 2 of 36 five-word shingles differ, well
		// above a 0.6 Jaccard threshold.
		$shared   = $this->words( 38, 'shared' );
		$original = $shared . ' original_tail_one original_tail_two';
		$near_dup = $shared . ' different_tail_one different_tail_two';

		$this->seed_published( 601, 'post', 'Morning Walk', $original );

		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'Morning Walk Redux', 'content' => $near_dup )
		);

		$this->assertTrue( $result['guardrails']['duplicate']['flagged'] );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_city_swap_is_detected_and_downgraded(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.99 ) );

		$toronto_body = 'Looking for a reliable plumber in Toronto? Our Toronto team handles emergencies across Toronto every day of the week.';
		$this->seed_published( 701, 'page', 'Toronto Plumbing Services', $toronto_body );

		// A high Jaccard threshold (0.99) means the ordinary similarity
		// score alone would NOT flag this — only the city-swap heuristic
		// (content identical once the title's location token is discounted)
		// catches it.
		$ottawa_body = 'Looking for a reliable plumber in Ottawa? Our Ottawa team handles emergencies across Ottawa every day of the week.';

		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'page', 'status' => 'publish', 'title' => 'Ottawa Plumbing Services', 'content' => $ottawa_body )
		);

		$this->assertTrue( $result['guardrails']['duplicate']['city_swap'] );
		$this->assertTrue( $result['guardrails']['duplicate']['flagged'] );
		$this->assertSame( 'draft', $result['status'] );
	}

	public function test_genuinely_different_content_is_not_flagged(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6 ) );

		$this->seed_published( 801, 'post', 'Baking Sourdough Bread', 'Sourdough baking requires patience, a healthy starter, careful hydration, and a hot oven with steam for a good crust and open crumb structure throughout the loaf.' );

		$result = Mcpwp_Content_Guardrails::evaluate(
			array(
				'post_type' => 'post',
				'status'    => 'publish',
				'title'     => 'Electric Vehicle Battery Chemistry',
				'content'   => 'Lithium-ion battery packs balance energy density against thermal stability, and pack designers weigh cobalt-free chemistries against cost, longevity, and fast-charging tolerance for modern vehicles.',
			)
		);

		$this->assertFalse( $result['guardrails']['duplicate']['flagged'] );
		$this->assertFalse( $result['guardrails']['duplicate']['city_swap'] );
		$this->assertSame( 'publish', $result['status'] );
	}

	public function test_duplicate_check_only_compares_against_same_post_type(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_threshold' => 0.6 ) );

		$body = 'Identical wording that would otherwise trip the duplicate detector immediately if the post types were not kept separate here.';
		$this->seed_published( 901, 'post', 'A Post', $body );

		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'page', 'status' => 'publish', 'title' => 'A Page', 'content' => $body )
		);

		$this->assertFalse( $result['guardrails']['duplicate']['flagged'] );
	}

	// -------------------------------------------------------------------------
	// Rule 4: anomaly signal

	public function test_publish_spike_emits_content_publish_spike_event(): void {
		Mcpwp_Content_Guardrails::update_settings(
			array( 'daily_publish_cap' => 1000, 'min_word_count' => 0, 'anomaly_min_count' => 5, 'anomaly_multiplier' => 2.0 )
		);

		$captured = array();
		add_action(
			'mcpwp_event_emitted',
			function ( $event ) use ( &$captured ) {
				$captured[] = $event;
			},
			10,
			1
		);

		$now = 2000000;
		// No history: baseline daily average is 0, so the floor
		// (anomaly_min_count = 5) is what the 24h count must cross.
		for ( $i = 0; $i < 6; $i++ ) {
			Mcpwp_Content_Guardrails::evaluate(
				array( 'post_type' => 'post', 'status' => 'publish', 'title' => "Spike $i", 'content' => $this->words( 5 ), 'now' => $now )
			);
		}

		$spikes = array_values( array_filter( $captured, function ( $e ) {
			return isset( $e['type'] ) && 'content.publish_spike' === $e['type'];
		} ) );

		$this->assertCount( 1, $spikes, 'the event fires exactly once, on the publish that crosses the threshold' );
		$this->assertSame( 6, $spikes[0]['payload']['published_last_24h'] );
	}

	public function test_no_spike_below_the_anomaly_floor(): void {
		Mcpwp_Content_Guardrails::update_settings(
			array( 'daily_publish_cap' => 1000, 'min_word_count' => 0, 'anomaly_min_count' => 10 )
		);

		$captured = array();
		add_action( 'mcpwp_event_emitted', function ( $event ) use ( &$captured ) {
			$captured[] = $event;
		}, 10, 1 );

		$now = 2000000;
		for ( $i = 0; $i < 5; $i++ ) {
			Mcpwp_Content_Guardrails::evaluate(
				array( 'post_type' => 'post', 'status' => 'publish', 'title' => "Normal $i", 'content' => $this->words( 5 ), 'now' => $now )
			);
		}

		$spikes = array_filter( $captured, function ( $e ) {
			return isset( $e['type'] ) && 'content.publish_spike' === $e['type'];
		} );
		$this->assertEmpty( $spikes );
	}

	// -------------------------------------------------------------------------
	// Settings bounds

	public function test_settings_sanitize_clamps_out_of_range_values(): void {
		// absint() takes the absolute value (WordPress's own convention),
		// so a negative input never reaches the floor clamp below — only
		// zero or an out-of-range positive number does. Both are covered.
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

		$this->assertSame( 1, $sanitized['daily_publish_cap'], 'floored to 1, never 0' );
		$this->assertSame( 1000, $sanitized['bulk_approval_threshold'] );
		$this->assertSame( 5000, $sanitized['min_word_count'] );
		$this->assertSame( 1.0, $sanitized['duplicate_threshold'] );
		$this->assertSame( 200, $sanitized['duplicate_compare_limit'], 'never above the hard architectural cap' );
		$this->assertSame( 1.0, $sanitized['anomaly_multiplier'] );
		$this->assertSame( 1, $sanitized['anomaly_min_count'] );
	}

	public function test_settings_sanitize_rejects_non_numeric_junk(): void {
		$sanitized = Mcpwp_Content_Guardrails::sanitize_settings(
			array( 'daily_publish_cap' => 'not-a-number', 'enabled' => 'yes' )
		);

		$this->assertSame( 1, $sanitized['daily_publish_cap'], 'absint() on junk floors to 0, then the min-1 clamp applies' );
		$this->assertTrue( $sanitized['enabled'] );
	}

	public function test_settings_sanitize_fills_missing_keys_with_defaults(): void {
		$sanitized = Mcpwp_Content_Guardrails::sanitize_settings( array() );
		$this->assertSame( Mcpwp_Content_Guardrails::default_settings(), $sanitized );
	}

	public function test_get_settings_persists_and_reloads(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'daily_publish_cap' => 42 ) );
		$this->assertSame( 42, Mcpwp_Content_Guardrails::get_settings()['daily_publish_cap'] );
	}

	// -------------------------------------------------------------------------
	// Performance guard

	public function test_duplicate_check_against_200_posts_stays_fast(): void {
		Mcpwp_Content_Guardrails::update_settings( array( 'min_word_count' => 0, 'duplicate_compare_limit' => 200 ) );

		for ( $i = 1; $i <= 200; $i++ ) {
			$this->seed_published( $i, 'post', "Post Number $i", $this->words( 300, "p{$i}_w" ) );
		}

		$start = microtime( true );
		$result = Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'publish', 'title' => 'A brand new post', 'content' => $this->words( 300, 'new_w' ) )
		);
		$elapsed = microtime( true ) - $start;

		$this->assertLessThan( 3.0, $elapsed, 'a 200-post duplicate comparison must stay well under a couple of seconds' );
		$this->assertSame( 'publish', $result['status'] );

		// A second, near-identical call re-checks against the same 200
		// candidates — the per-request shingle cache should make this at
		// least as fast, not slower.
		$start2 = microtime( true );
		Mcpwp_Content_Guardrails::evaluate(
			array( 'post_type' => 'post', 'status' => 'draft', 'title' => 'Another post', 'content' => $this->words( 300, 'new_w' ) )
		);
		$elapsed2 = microtime( true ) - $start2;
		$this->assertLessThan( 3.0, $elapsed2 );
	}

	// -------------------------------------------------------------------------
	// Elementor text extraction (used by build_page / landing pages / blueprint deploy)

	public function test_extract_text_from_elementor_pulls_widget_copy(): void {
		$data = array(
			array(
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'elType'   => 'column',
						'settings' => array(),
						'elements' => array(
							array(
								'elType'   => 'widget',
								'widgetType' => 'heading',
								'settings' => array( 'title' => 'Hello World' ),
							),
							array(
								'elType'   => 'widget',
								'widgetType' => 'text-editor',
								'settings' => array( 'editor' => '<p>Some body copy here.</p>' ),
							),
						),
					),
				),
			),
		);

		$text = Mcpwp_Content_Guardrails::extract_text_from_elementor( $data );

		$this->assertStringContainsString( 'Hello World', $text );
		$this->assertStringContainsString( 'Some body copy here.', $text );
	}

	public function test_extract_text_from_elementor_accepts_json_string(): void {
		$json = wp_json_encode(
			array(
				array(
					'elType'   => 'widget',
					'settings' => array( 'title' => 'From JSON' ),
				),
			)
		);

		$text = Mcpwp_Content_Guardrails::extract_text_from_elementor( $json );

		$this->assertStringContainsString( 'From JSON', $text );
	}
}
