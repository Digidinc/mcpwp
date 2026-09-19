<?php
/**
 * RC review finding #1 — 3.12.0-rc.
 *
 * #831 added `elementor_data_base64` alongside `elementor_data` on several
 * Elementor Pro create/update tools, and `elementor_data` lost its own
 * `required: true` in the process (both are now optional; the tool
 * description just says "one of the two is required", with nothing
 * enforcing it).
 *
 * Traced consequence: wp_create_elementor_part / wp_create_elementor_archetype
 * → get_elementor_payload_from_request() resolves to null when both params
 * are absent → Mcpwp_Elementor_Pro::create_template() does
 * `if ( ! empty( $data['elementor_data'] ) )` and simply skips the save step
 * — so a caller who forgets the payload got a 201 with a blank library entry
 * instead of a clear error.
 *
 * JSON Schema cannot express "exactly one of elementor_data /
 * elementor_data_base64" in a way this validator honours (re-adding
 * `required: true` to either one would wrongly reject the other), so this is
 * enforced in the REST handler via require_elementor_payload() instead.
 *
 * Per-tool determination (all four tools #831 gave elementor_data_base64):
 *  - wp_create_elementor_part      → same bug. Guarded.
 *  - wp_create_elementor_archetype → same bug (a blank archetype is
 *    reusable-nothing, and wp_apply_elementor_archetype's default mode can
 *    replace a page's content with it). Guarded.
 *  - wp_create_elementor_template  → genuinely optional. Its own description
 *    says so ("If omitted, Elementor creates a blank template") — a Theme
 *    Builder template a human finishes visually in the Elementor editor is a
 *    real workflow a part/archetype has no equivalent of. NOT guarded.
 *  - wp_update_elementor_template  → genuinely optional. Already gated by
 *    has_elementor_payload_in_request()'s has_param() check: omitting both
 *    params means "do not touch the data", a normal partial update (e.g.
 *    title-only), not "create blank". NOT guarded — this is pre-existing,
 *    correct behaviour, pinned here so a refactor cannot quietly regress it
 *    to also require a payload.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorLibraryPayloadRequiredTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['_mcpwp_test_posts']  = array();
		$GLOBALS['_mcpwp_test_meta']   = array();
	}

	private function bare_controller(): Mcpwp_REST_Elementor_Pro {
		// Never reaches $this->elementor_pro in the guarded tools below (the
		// guard returns first), so a stdClass stand-in is enough and keeps
		// these tests from depending on Mcpwp_Elementor_Pro at all.
		return new Mcpwp_REST_Elementor_Pro( new stdClass() );
	}

	private function real_controller(): Mcpwp_REST_Elementor_Pro {
		return new Mcpwp_REST_Elementor_Pro( new Mcpwp_Elementor_Pro() );
	}

	private function require_payload( Mcpwp_REST_Elementor_Pro $controller, WP_REST_Request $request ) {
		$ref = new ReflectionMethod( Mcpwp_REST_Elementor_Pro::class, 'require_elementor_payload' );
		$ref->setAccessible( true );

		return $ref->invoke( $controller, $request );
	}

	// ── require_elementor_payload() in isolation ─────────────────────────────

	public function test_require_elementor_payload_refuses_when_both_absent(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/parts', array( 'title' => 'Hero' ) );

		$result = $this->require_payload( $this->bare_controller(), $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_elementor_data', $result->get_error_code() );
		$this->assertStringContainsString( 'elementor_data', $result->get_error_message() );
		$this->assertStringContainsString( 'elementor_data_base64', $result->get_error_message() );
	}

	public function test_require_elementor_payload_refuses_an_explicit_empty_array(): void {
		// Present but empty is the same dead end as absent — an empty array
		// still makes create_template() skip the save step.
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/parts', array(
			'title'          => 'Hero',
			'elementor_data' => array(),
		) );

		$result = $this->require_payload( $this->bare_controller(), $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_elementor_data', $result->get_error_code() );
	}

	public function test_require_elementor_payload_accepts_array_only(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/parts', array(
			'title'          => 'Hero',
			'elementor_data' => array( array( 'id' => 'sec1abc', 'elType' => 'section' ) ),
		) );

		$this->assertTrue( $this->require_payload( $this->bare_controller(), $request ) );
	}

	public function test_require_elementor_payload_accepts_base64_only(): void {
		$encoded = base64_encode( wp_json_encode( array( array( 'id' => 'sec1abc', 'elType' => 'section' ) ) ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/parts', array(
			'title'                 => 'Hero',
			'elementor_data_base64' => $encoded,
		) );

		$this->assertTrue( $this->require_payload( $this->bare_controller(), $request ) );
	}

	// ── wp_create_elementor_part: guarded ────────────────────────────────────

	public function test_create_part_is_refused_with_neither_payload_param(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/parts', array( 'title' => 'Hero' ) );

		$result = $this->bare_controller()->create_part( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_elementor_data', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'], 'no library post should have been created' );
	}

	// ── wp_create_elementor_archetype: guarded ───────────────────────────────

	public function test_create_archetype_is_refused_with_neither_payload_param(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/archetypes', array(
			'title'           => 'Landing',
			'archetype_scope' => 'page',
			'archetype_class' => 'landing_page',
		) );

		$result = $this->bare_controller()->create_archetype( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_elementor_data', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'], 'no library post should have been created' );
	}

	// ── wp_create_elementor_template: NOT guarded — blank is intentional ─────

	public function test_create_template_with_neither_payload_param_is_not_refused_by_the_guard(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/templates', array(
			'title' => 'Blank shell',
			'type'  => 'page',
		) );

		$result = $this->real_controller()->create_template( $request );

		// Elementor is not "active" in this test process (did_action() reads
		// 0), so a request that got past require_elementor_payload() reaches
		// the real handler and fails there instead — on 'elementor_inactive',
		// never on 'missing_elementor_data'. That is the proof this tool's
		// omitted-payload path was not touched by the new guard.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'elementor_inactive', $result->get_error_code() );
	}

	// ── wp_update_elementor_template: NOT guarded — omission = no change ────

	public function test_update_template_with_neither_payload_param_updates_title_only(): void {
		$GLOBALS['_mcpwp_test_posts'][501] = (object) array(
			'ID'          => 501,
			'post_type'   => 'elementor_library',
			'post_status' => 'publish',
			'post_title'  => 'Old title',
		);

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/templates/501', array(
			'id'    => 501,
			'title' => 'New title',
		) );

		$result = $this->real_controller()->update_template( $request );

		// This test environment has no real Elementor, so the final
		// get_template() re-read inside update_template() can itself fail
		// with 'elementor_inactive' — that is unrelated to what this test
		// pins down. The point under test is upstream of it: omitting both
		// payload params must never be refused as "missing data" (Finding
		// #1's bug is specifically about CREATE, not UPDATE), and the title
		// write must have happened regardless.
		if ( is_wp_error( $result ) ) {
			$this->assertNotSame( 'missing_elementor_data', $result->get_error_code() );
		}
		$this->assertSame( 'New title', $GLOBALS['_mcpwp_test_posts'][501]->post_title );
	}
}
