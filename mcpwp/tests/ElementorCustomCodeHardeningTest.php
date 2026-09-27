<?php
/**
 * Regression coverage for #974 — the adversarial-review findings against
 * #973 (which fixed #965) for Elementor Pro Custom Code create/update:
 *
 *  - N1: readback (create/update response, and the GET route) returned
 *    `post_content`, which wp_insert_post()/wp_update_post() re-filter
 *    through core's own content_save_pre KSES gate for a writer without
 *    `unfiltered_html` (a multisite site admin, or anyone under
 *    DISALLOW_UNFILTERED_HTML) — silently stripping <script> from the
 *    RESPONSE while the code Elementor actually renders, `_elementor_code`,
 *    was untouched. An agent that read, edited and wrote the snippet back
 *    would save the already-stripped copy into `_elementor_code`,
 *    permanently destroying the real content. Fixed by reading `code` (and
 *    `code_length`) from `_elementor_code` — see
 *    Mcpwp_REST_Elementor_Pro::read_custom_code_source().
 *  - N2: an invalid post status (e.g. "enabled", a typo) was silently
 *    coerced to 'draft' by the shared
 *    Mcpwp_Trait_Sanitization::validate_post_status(), unpublishing a live
 *    snippet with no error at all. Fixed by
 *    Mcpwp_REST_Elementor_Pro::normalize_custom_code_status(), which
 *    refuses outright (400) the same way normalize_custom_code_location()
 *    already does for an invalid location (#871).
 *  - N3: priority used absint(), which takes the absolute value BEFORE
 *    clamping — so priority=-5 became 5 (a value inside the valid 1-10
 *    range) instead of clamping DOWN to the floor, 1. Fixed by keeping the
 *    sign via intval()/(int) before max(1, min(10, ...)).
 *  - dry_run on update never reported the snippet's real, unchanged
 *    `conditions` when the caller omitted that field, unlike
 *    status/location/priority (already fixed for #965). Fixed by adding the
 *    same "fall back to the stored value" branch for conditions.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorCustomCodeHardeningTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
	}

	/**
	 * Seed an existing published elementor_snippet post, with `post_content`
	 * and `_elementor_code` deliberately DIFFERENT — the exact shape a
	 * kses-stripped `post_content` produces on a real site while
	 * `_elementor_code` (written under the scoped unfiltered_html grant)
	 * keeps the real code intact.
	 */
	private function seed_snippet_with_diverged_content( int $id ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => 'elementor_snippet',
			'post_title'        => 'Diverged snippet',
			'post_name'         => 'diverged-snippet',
			'post_status'       => 'publish',
			'post_date'         => '2026-06-06 00:00:00',
			'post_modified'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			// The stripped copy a KSES-gated wp_update_post() would have
			// left in post_content — no <script> at all.
			'post_content'      => '',
			'post_excerpt'      => '',
		);
		update_post_meta( $id, '_elementor_location', 'elementor_head' );
		update_post_meta( $id, '_elementor_priority', 1 );
		// The real, untouched code — what Elementor's own renderer actually
		// prints (custom-code/document.php print_content()).
		update_post_meta( $id, '_elementor_code', '<script>real()</script>' );
	}

	private function seed_published_snippet( int $id ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => 'elementor_snippet',
			'post_title'        => 'Existing snippet',
			'post_name'         => 'existing-snippet',
			'post_status'       => 'publish',
			'post_date'         => '2026-06-06 00:00:00',
			'post_modified'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			'post_content'      => '<script>console.log("original");</script>',
			'post_excerpt'      => '',
		);
		update_post_meta( $id, '_elementor_location', 'elementor_head' );
		update_post_meta( $id, '_elementor_priority', 1 );
		update_post_meta( $id, '_elementor_code', '<script>console.log("original");</script>' );
	}

	// ---------------------------------------------------------------
	// N1 — readback must come from _elementor_code, never post_content.
	// ---------------------------------------------------------------

	public function test_get_reads_back_elementor_code_not_the_kses_stripped_post_content(): void {
		$this->seed_snippet_with_diverged_content( 700 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/custom-code/700', array( 'id' => 700 ) );

		$response = $controller->get_custom_code( $request );
		$snippet  = $response->get_data()['snippet'];

		$this->assertSame(
			'<script>real()</script>',
			$snippet['code'],
			'Readback must return the code Elementor actually renders (_elementor_code), not the possibly kses-stripped post_content.'
		);
		$this->assertSame( '_elementor_code', $snippet['code_source'] );
		$this->assertFalse( $snippet['code_may_be_altered'] );
	}

	/**
	 * A legacy snippet with no `_elementor_code` at all (pre-#871) must
	 * still return something on readback — falling back to post_content —
	 * but the fallback must say plainly that it may not be the untouched
	 * source, satisfying the issue's "or says clearly that it was altered"
	 * alternative.
	 */
	public function test_get_falls_back_to_post_content_and_flags_it_when_elementor_code_is_missing(): void {
		$GLOBALS['_mcpwp_test_posts'][ 701 ] = (object) array(
			'ID'                => 701,
			'post_type'         => 'elementor_snippet',
			'post_title'        => 'Pre-#871 legacy snippet',
			'post_name'         => 'legacy-snippet',
			'post_status'       => 'publish',
			'post_date'         => '2026-06-06 00:00:00',
			'post_modified'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			'post_content'      => '<div>legacy</div>',
			'post_excerpt'      => '',
		);
		// Deliberately no _elementor_code meta at all.

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->get_custom_code( new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/custom-code/701', array( 'id' => 701 ) ) );
		$snippet    = $response->get_data()['snippet'];

		$this->assertSame( '<div>legacy</div>', $snippet['code'] );
		$this->assertSame( 'post_content_fallback', $snippet['code_source'] );
		$this->assertTrue( $snippet['code_may_be_altered'] );
	}

	public function test_create_and_update_responses_also_read_back_from_elementor_code(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$create     = $controller->create_custom_code(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/elementor/custom-code',
				array(
					'title'  => 'N1 create',
					'code'   => '<script>created()</script>',
					'status' => 'publish',
				)
			)
		);
		$post_id = $create->get_data()['id'];

		$this->assertSame( '<script>created()</script>', $create->get_data()['snippet']['code'] );
		$this->assertSame( '<script>created()</script>', get_post_meta( $post_id, '_elementor_code', true ) );

		// Simulate the exact divergence a real KSES gate would create on a
		// later WRITE that this same code is fed back into: post_content
		// drifts, _elementor_code must remain the read-back source.
		$GLOBALS['_mcpwp_test_posts'][ $post_id ]->post_content = '';

		$update = $controller->update_custom_code(
			new WP_REST_Request(
				'PUT',
				'/mcpwp/v1/elementor/custom-code/' . $post_id,
				array(
					'id'    => $post_id,
					'title' => 'N1 create (renamed)',
				)
			)
		);

		$this->assertSame(
			'<script>created()</script>',
			$update->get_data()['snippet']['code'],
			'The update response must still read back the real code from _elementor_code, even though post_content has since diverged.'
		);
	}

	// ---------------------------------------------------------------
	// N2 — an invalid status must be refused (400), never coerced.
	// ---------------------------------------------------------------

	public function test_update_rejects_an_invalid_status_instead_of_coercing_to_draft(): void {
		$this->seed_published_snippet( 710 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->update_custom_code(
			new WP_REST_Request(
				'PUT',
				'/mcpwp/v1/elementor/custom-code/710',
				array(
					'id'     => 710,
					'status' => 'enabled', // A plausible typo for 'publish'.
				)
			)
		);

		$this->assertTrue( is_wp_error( $response ), 'An invalid status must be refused, not silently coerced.' );
		$this->assertSame( 'invalid_status', $response->get_error_code() );
		$this->assertSame( 400, (int) ( $response->get_error_data()['status'] ?? 0 ) );

		// The live snippet must be untouched — no silent unpublish.
		$this->assertSame( 'publish', get_post_status( 710 ) );
	}

	public function test_create_rejects_an_invalid_status(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->create_custom_code(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/elementor/custom-code',
				array(
					'title'  => 'Bad status',
					'code'   => '<div>x</div>',
					'status' => 'Published', // Case-sensitive typo.
				)
			)
		);

		$this->assertTrue( is_wp_error( $response ) );
		$this->assertSame( 'invalid_status', $response->get_error_code() );
	}

	public function test_valid_statuses_still_work_on_update(): void {
		$this->seed_published_snippet( 711 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->update_custom_code(
			new WP_REST_Request(
				'PUT',
				'/mcpwp/v1/elementor/custom-code/711',
				array(
					'id'     => 711,
					'status' => 'draft',
				)
			)
		);

		$this->assertFalse( is_wp_error( $response ) );
		$this->assertSame( 'draft', $response->get_data()['snippet']['status'] );
	}

	/**
	 * enable_custom_code()/disable_custom_code() route through the same
	 * normalize_custom_code_status() now — both pass a hardcoded literal, so
	 * this pins that the shared validator does not regress that path.
	 */
	public function test_enable_and_disable_routes_are_unaffected(): void {
		$this->seed_published_snippet( 712 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );

		$disabled = $controller->disable_custom_code( new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code/712/disable', array( 'id' => 712 ) ) );
		$this->assertFalse( is_wp_error( $disabled ) );
		$this->assertSame( 'draft', $disabled->get_data()['status'] );

		$enabled = $controller->enable_custom_code( new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code/712/enable', array( 'id' => 712 ) ) );
		$this->assertFalse( is_wp_error( $enabled ) );
		$this->assertSame( 'publish', $enabled->get_data()['status'] );
	}

	// ---------------------------------------------------------------
	// N3 — priority must clamp by sign, never flip a negative to positive.
	// ---------------------------------------------------------------

	public function test_negative_priority_clamps_to_the_floor_not_to_its_absolute_value(): void {
		$this->seed_published_snippet( 720 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->update_custom_code(
			new WP_REST_Request(
				'PUT',
				'/mcpwp/v1/elementor/custom-code/720',
				array(
					'id'       => 720,
					'priority' => -5,
				)
			)
		);

		$this->assertFalse( is_wp_error( $response ) );
		$this->assertSame(
			1,
			$response->get_data()['snippet']['priority'],
			'priority=-5 must clamp DOWN to the floor (1), not flip sign to land inside the valid range (absint(-5) = 5).'
		);
		$this->assertSame( 1, get_post_meta( 720, '_elementor_priority', true ) );
	}

	public function test_priority_above_the_ceiling_clamps_to_ten(): void {
		$this->seed_published_snippet( 721 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->update_custom_code(
			new WP_REST_Request(
				'PUT',
				'/mcpwp/v1/elementor/custom-code/721',
				array(
					'id'       => 721,
					'priority' => 999,
				)
			)
		);

		$this->assertSame( 10, $response->get_data()['snippet']['priority'] );
	}

	public function test_zero_priority_clamps_to_the_floor(): void {
		$this->seed_published_snippet( 722 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->update_custom_code(
			new WP_REST_Request(
				'PUT',
				'/mcpwp/v1/elementor/custom-code/722',
				array(
					'id'       => 722,
					'priority' => 0,
				)
			)
		);

		$this->assertSame( 1, $response->get_data()['snippet']['priority'] );
	}

	// ---------------------------------------------------------------
	// dry_run must report the real, unchanged conditions when omitted.
	// ---------------------------------------------------------------

	public function test_update_dry_run_reports_current_conditions_when_omitted(): void {
		$this->seed_published_snippet( 730 );
		update_post_meta(
			730,
			'_elementor_conditions',
			array(
				array(
					'type'     => 'include',
					'name'     => 'singular',
					'sub_name' => 'post',
					'sub_id'   => '',
				),
			)
		);

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->update_custom_code(
			new WP_REST_Request(
				'PUT',
				'/mcpwp/v1/elementor/custom-code/730',
				array(
					'id'      => 730,
					'title'   => 'Dry run, conditions omitted',
					'dry_run' => true,
				)
			)
		);

		$data = $response->get_data();

		$this->assertTrue( $data['dry_run'] );
		$this->assertArrayHasKey(
			'conditions',
			$data['payload'],
			'dry_run must report the conditions the real save would leave unchanged, the same way it already does for status/location/priority.'
		);
		$this->assertSame(
			array(
				array(
					'type'     => 'include',
					'name'     => 'singular',
					'sub_name' => 'post',
				),
			),
			$data['payload']['conditions'],
			'get_custom_code_conditions() runs the stored meta through normalize_custom_code_conditions(), which drops empty-string fields (e.g. an unused sub_id) — the payload must reflect that same normalized shape.'
		);

		// Nothing was actually written.
		$this->assertSame(
			array(
				array(
					'type'     => 'include',
					'name'     => 'singular',
					'sub_name' => 'post',
					'sub_id'   => '',
				),
			),
			get_post_meta( 730, '_elementor_conditions', true )
		);
	}
}
