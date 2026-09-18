<?php

use PHPUnit\Framework\TestCase;

final class ElementorCustomCodeTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']     = array();
		$GLOBALS['mcpwp_test_transients']  = array();
		$GLOBALS['_mcpwp_test_posts']      = array();
		$GLOBALS['_mcpwp_test_meta']       = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
	}

	public function test_pro_registry_exposes_custom_code_create_get_update_tools(): void {
		$registry = new Mcpwp_MCP_Pro_Tools();
		$tools    = $registry->get_tools();
		$names    = array_column( $tools, 'name' );
		$map      = $registry->get_tool_map();

		$this->assertContains( 'wp_create_elementor_custom_code', $names );
		$this->assertContains( 'wp_get_elementor_custom_code', $names );
		$this->assertContains( 'wp_update_elementor_custom_code', $names );
		$this->assertSame( '/elementor/custom-code', $map['wp_create_elementor_custom_code']['route'] );
		$this->assertSame( '/elementor/custom-code/{id}', $map['wp_get_elementor_custom_code']['route'] );
		$this->assertSame( '/elementor/custom-code/{id}', $map['wp_update_elementor_custom_code']['route'] );
	}

	public function test_create_custom_code_preserves_raw_script_and_reads_back_conditions(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/custom-code',
			array(
				'title'      => 'GA4 Funnel',
				'code'       => '<html><head><script>window.dataLayer = window.dataLayer || [];</script></head></html>',
				'location'   => 'body_end',
				'status'     => 'publish',
				'conditions' => array(
					array(
						'type' => 'include',
						'name' => 'general',
					),
				),
			)
		);

		$response = $controller->create_custom_code( $request );
		$data     = $response->get_data();
		$snippet  = $data['snippet'];

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'GA4 Funnel', $snippet['title'] );
		$this->assertSame( 'publish', $snippet['status'] );
		$this->assertSame( 'body_end', $snippet['location'] );
		$this->assertStringContainsString( '<script>window.dataLayer', $snippet['code'] );
		$this->assertStringNotContainsString( '<html>', $snippet['code'] );
		$this->assertTrue( $snippet['wrapper_tags_removed'] );
		$this->assertSame( array( array( 'type' => 'include', 'name' => 'general' ) ), $snippet['conditions'] );
		$this->assertSame( 'fallback_meta', $snippet['conditions_engine'] );
		$this->assertSame( array( 1 ), get_option( 'elementor_pro_theme_builder_conditions' )['include/general'] );
	}

	public function test_update_custom_code_dry_run_does_not_mutate_existing_snippet(): void {
		$GLOBALS['_mcpwp_test_posts'][ 99 ] = (object) array(
			'ID'                => 99,
			'post_type'         => 'elementor_snippet',
			'post_title'        => 'Existing',
			'post_name'         => 'existing',
			'post_status'       => 'draft',
			'post_date'         => '2026-06-06 00:00:00',
			'post_modified'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			'post_content'      => '<script>old()</script>',
			'post_excerpt'      => '',
		);
		update_post_meta( 99, '_elementor_location', 'head' );
		update_post_meta( 99, '_elementor_conditions', array( array( 'type' => 'include', 'name' => 'general' ) ) );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/custom-code/99',
			array(
				'id'       => 99,
				'code'     => '<script>new()</script>',
				'location' => 'body_start',
				'dry_run'  => true,
			)
		);

		$response = $controller->update_custom_code( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['dry_run'] );
		$this->assertTrue( $data['valid'] );
		$this->assertSame( '<script>old()</script>', $GLOBALS['_mcpwp_test_posts'][99]->post_content );
		$this->assertSame( 'head', get_post_meta( 99, '_elementor_location', true ) );
	}

	/**
	 * GH #871: our public location values (head/body_start/body_end) must be
	 * translated to Elementor Pro's real internal `_elementor_location`
	 * values on write, or the snippet is silently dead on arrival — created
	 * successfully, reads back "correct", never rendered anywhere. Confirmed
	 * against Elementor Pro 4.2.x source: Custom_Code_Metabox::
	 * OPTION_LOCATION_BODY_END = 'elementor_body_end' (modules/custom-code/
	 * custom-code-metabox.php), matched exactly by module.php's wp_footer
	 * handler. This test locks the public API contract (location comes back
	 * as 'body_end') while asserting the raw stored meta is the internal
	 * value Elementor's own renderer actually queries for.
	 *
	 * It also locks the two companion metas that were entirely missing
	 * before this fix: `_elementor_code` (what ElementorPro\Modules\
	 * CustomCode\Document::print_content() actually echoes — this class
	 * used to only ever write `post_content`, which that renderer never
	 * reads) and `_elementor_priority` (required for Elementor's own
	 * get_snippets_by_location() query to include the post at all, since it
	 * runs with an implicit meta_key clause ANDed to the location filter).
	 */
	public function test_create_custom_code_maps_location_to_elementor_internal_key_and_writes_render_metas(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/custom-code',
			array(
				'title'    => 'GTM Container',
				'code'     => '<div id="gtm-marker">rendered</div>',
				'location' => 'body_end',
				'status'   => 'publish',
			)
		);

		$response = $controller->create_custom_code( $request );
		$data     = $response->get_data();
		$snippet  = $data['snippet'];
		$post_id  = $data['id'];

		$this->assertSame( 201, $response->get_status() );
		// Public contract unchanged: callers still see the short-form value.
		$this->assertSame( 'body_end', $snippet['location'] );
		$this->assertSame( 1, $snippet['priority'] );
		// What Elementor's renderer actually reads must be its own internal value.
		$this->assertSame( 'elementor_body_end', get_post_meta( $post_id, '_elementor_location', true ) );
		$this->assertSame( '<div id="gtm-marker">rendered</div>', get_post_meta( $post_id, '_elementor_code', true ) );
		$this->assertSame( 1, get_post_meta( $post_id, '_elementor_priority', true ) );
	}

	/**
	 * An unrecognized location used to be silently coerced to 'head' — a
	 * typo went through as a snippet that looked saved and never rendered
	 * anywhere (GH #871). It must now be rejected outright, and nothing
	 * saved.
	 */
	public function test_create_custom_code_rejects_unknown_location(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/custom-code',
			array(
				'title'    => 'Bad Location',
				'code'     => '<div>x</div>',
				'location' => 'footer',
				'status'   => 'publish',
			)
		);

		$response = $controller->create_custom_code( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_location', $response->get_error_code() );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * Elementor's own internal values are accepted directly too (documented
	 * alias), and always normalized back to our public short-form value —
	 * a caller should never see two different spellings for the same
	 * location depending on which one they wrote.
	 */
	public function test_create_custom_code_accepts_elementor_internal_location_value_directly(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/custom-code',
			array(
				'title'    => 'Direct Internal Value',
				'code'     => '<div>x</div>',
				'location' => 'elementor_head',
				'status'   => 'publish',
			)
		);

		$response = $controller->create_custom_code( $request );
		$data     = $response->get_data();

		$this->assertSame( 'head', $data['snippet']['location'] );
		$this->assertSame( 'elementor_head', get_post_meta( $data['id'], '_elementor_location', true ) );
	}

	/**
	 * Same mapping must apply on the update path, not just create.
	 */
	public function test_update_custom_code_maps_location_to_elementor_internal_key(): void {
		$GLOBALS['_mcpwp_test_posts'][ 99 ] = (object) array(
			'ID'                => 99,
			'post_type'         => 'elementor_snippet',
			'post_title'        => 'Existing',
			'post_name'         => 'existing',
			'post_status'       => 'draft',
			'post_date'         => '2026-06-06 00:00:00',
			'post_modified'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			'post_content'      => '<div>old</div>',
			'post_excerpt'      => '',
		);
		update_post_meta( 99, '_elementor_location', 'elementor_head' );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/custom-code/99',
			array(
				'id'       => 99,
				'location' => 'body_start',
			)
		);

		$response = $controller->update_custom_code( $request );
		$data     = $response->get_data();

		$this->assertSame( 'body_start', $data['snippet']['location'] );
		$this->assertSame( 'elementor_body_start', get_post_meta( 99, '_elementor_location', true ) );
	}
}
