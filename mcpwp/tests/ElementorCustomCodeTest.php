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

		// #974/#976 review NIT-a: the tool schemas must advertise the same
		// exact-match contract normalize_custom_code_status() enforces —
		// an MCP client reading the schema should see 'enum' say the same
		// thing the 400 refusal proves at call time.
		$by_name = array();
		foreach ( $tools as $tool ) {
			$by_name[ $tool['name'] ] = $tool;
		}

		$this->assertSame(
			array( 'publish', 'draft' ),
			$by_name['wp_create_elementor_custom_code']['inputSchema']['properties']['status']['enum']
		);
		$this->assertSame(
			array( 'publish', 'draft' ),
			$by_name['wp_update_elementor_custom_code']['inputSchema']['properties']['status']['enum']
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
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
		// #976: this main-process test never loads the ElementorPro\...\
		// ThemeBuilder classes, so save_custom_code_conditions() takes the
		// 'fallback_meta' branch. It used to also write
		// `elementor_pro_theme_builder_conditions` itself here, under the
		// wrong axis (`{"include/general": [1]}` instead of Elementor Pro's
		// real `{location => {post_id => [condition_strings]}}`) — dead
		// weight even then, since Elementor Pro's own Custom Code renderer
		// (modules/custom-code/module.php print_snippets()) never reads
		// that option directly; it always goes through
		// ThemeBuilder's Conditions_Manager, which this fallback branch by
		// definition has none of loaded. The option must be left untouched.
		$this->assertSame( array(), get_option( 'elementor_pro_theme_builder_conditions', array() ) );
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

	/**
	 * Helper: seed an existing published elementor_snippet post, optionally
	 * with a stale/invalid _wp_page_template meta value — the exact shape
	 * production hit on mcpwp.net (#965): a snippet post that already has a
	 * template slug in postmeta that the active theme no longer registers.
	 */
	private function seed_published_snippet( int $id, string $stale_page_template = '' ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => 'elementor_snippet',
			'post_title'        => 'PostHog snippet',
			'post_name'         => 'posthog-snippet',
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

		if ( '' !== $stale_page_template ) {
			update_post_meta( $id, '_wp_page_template', $stale_page_template );
		}
	}

	/**
	 * #965 core repro: a snippet carrying a stale _wp_page_template meta
	 * (from a theme that no longer registers it) must not block a
	 * title-only update. Before the fix, wp_update_post() merged that stale
	 * value into $postarr and core's own page-template validation rejected
	 * the whole update with "Invalid page template."
	 */
	public function test_update_custom_code_survives_stale_page_template_meta(): void {
		$this->seed_published_snippet( 682, 'old-theme-template.php' );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/custom-code/682',
			array(
				'id'     => 682,
				'title'  => 'PostHog snippet (renamed)',
				'status' => 'publish',
			)
		);

		$response = $controller->update_custom_code( $request );

		$this->assertFalse( is_wp_error( $response ), 'update must not fail with invalid_page_template' );
		$data = $response->get_data();
		$this->assertSame( 'PostHog snippet (renamed)', $data['snippet']['title'] );
		$this->assertSame( 'publish', $data['snippet']['status'] );

		// The stale slug is self-healed rather than left to fail the next update too.
		$this->assertSame( 'default', get_post_meta( 682, '_wp_page_template', true ) );
	}

	/**
	 * A code-only update (no title, no status) on a published snippet must
	 * leave the status untouched.
	 */
	public function test_update_custom_code_code_only_preserves_status(): void {
		$this->seed_published_snippet( 683 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/custom-code/683',
			array(
				'id'   => 683,
				'code' => '<script>console.log("updated");</script>',
			)
		);

		$response = $controller->update_custom_code( $request );
		$data     = $response->get_data();

		$this->assertSame( 'publish', $data['snippet']['status'] );
		$this->assertStringContainsString( 'updated', $data['snippet']['code'] );
		$this->assertSame( 'publish', get_post_status( 683 ) );
	}

	/**
	 * A title-only update on a published snippet, with status explicitly
	 * repeated as publish, must succeed and keep publish.
	 */
	public function test_update_custom_code_title_only_with_explicit_status_keeps_publish(): void {
		$this->seed_published_snippet( 684 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/custom-code/684',
			array(
				'id'     => 684,
				'title'  => 'Renamed snippet',
				'status' => 'publish',
			)
		);

		$response = $controller->update_custom_code( $request );
		$data     = $response->get_data();

		$this->assertSame( 'Renamed snippet', $data['snippet']['title'] );
		$this->assertSame( 'publish', $data['snippet']['status'] );
	}

	/**
	 * Status omitted entirely on update must never default to draft — the
	 * write-args schema only carries a 'draft' default for the CREATE route;
	 * see get_custom_code_write_args(). Before the fix this schema default
	 * was shared with the update route (applied by the real REST dispatcher
	 * whenever the caller omits the param), silently unpublishing on update.
	 */
	public function test_update_custom_code_status_omitted_keeps_publish(): void {
		$this->seed_published_snippet( 685 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/custom-code/685',
			array(
				'id'    => 685,
				'title' => 'No status param here',
			)
		);

		$response = $controller->update_custom_code( $request );
		$data     = $response->get_data();

		$this->assertSame( 'publish', $data['snippet']['status'] );
		$this->assertSame( 'publish', get_post_status( 685 ) );
	}

	/**
	 * dry_run must report the same outcome the real save would produce. Before
	 * the fix, dry_run on an update with status omitted reported a fabricated
	 * "draft" (the create-only schema default leaking into prepare's payload)
	 * even though the real save left a published snippet untouched.
	 */
	public function test_update_custom_code_dry_run_reports_current_status_when_omitted(): void {
		$this->seed_published_snippet( 686 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/custom-code/686',
			array(
				'id'      => 686,
				'title'   => 'Dry run title',
				'dry_run' => true,
			)
		);

		$response = $controller->update_custom_code( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['dry_run'] );
		$this->assertTrue( $data['valid'] );
		$this->assertSame( 'publish', $data['payload']['status'] );

		// Nothing was actually written.
		$this->assertSame( 'publish', get_post_status( 686 ) );
		$this->assertSame( 'PostHog snippet', get_post( 686 )->post_title );
	}

	/**
	 * dry_run parity must also hold when the snippet carries the same stale
	 * _wp_page_template meta as the core repro: dry_run must not report
	 * success while the real save would fail (and, per the fix, the real
	 * save no longer fails either).
	 */
	public function test_update_custom_code_dry_run_parity_with_stale_page_template(): void {
		$this->seed_published_snippet( 687, 'old-theme-template.php' );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$dry_request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/custom-code/687',
			array(
				'id'      => 687,
				'title'   => 'Renamed under dry run',
				'status'  => 'publish',
				'dry_run' => true,
			)
		);
		$dry_response = $controller->update_custom_code( $dry_request );
		$this->assertTrue( $dry_response->get_data()['valid'] );

		$real_request = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/custom-code/687',
			array(
				'id'     => 687,
				'title'  => 'Renamed under dry run',
				'status' => 'publish',
			)
		);
		$real_response = $controller->update_custom_code( $real_request );

		$this->assertFalse( is_wp_error( $real_response ), 'dry_run reported valid but the real save failed — parity broken' );
		$this->assertSame( 'Renamed under dry run', $real_response->get_data()['snippet']['title'] );
	}

	/**
	 * location and priority, like status, must only default on create.
	 * Omitting them on update must not reset a previously-set value.
	 */
	public function test_update_custom_code_location_and_priority_omitted_are_preserved(): void {
		$this->seed_published_snippet( 688 );
		update_post_meta( 688, '_elementor_location', 'elementor_body_end' );
		update_post_meta( 688, '_elementor_priority', 7 );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/elementor/custom-code/688',
			array(
				'id'    => 688,
				'title' => 'Location/priority preserved',
			)
		);

		$response = $controller->update_custom_code( $request );
		$data     = $response->get_data();

		$this->assertSame( 'body_end', $data['snippet']['location'] );
		$this->assertSame( 7, $data['snippet']['priority'] );
	}

	/**
	 * Direct check on the root cause: the write-args schema must only carry
	 * a 'default' for status/location/priority on the CREATE route. Sharing
	 * those defaults with the update route is exactly what made the real WP
	 * REST dispatcher (which back-fills a registered 'default' into the
	 * request whenever the caller omits the param) look like an explicit
	 * "reset to draft" on every update.
	 */
	public function test_write_args_schema_only_defaults_status_location_priority_on_create(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$method     = new ReflectionMethod( Mcpwp_REST_Elementor_Pro::class, 'get_custom_code_write_args' );
		$method->setAccessible( true );

		$create_args = $method->invoke( $controller, true );
		$update_args = $method->invoke( $controller, false );

		$this->assertArrayHasKey( 'default', $create_args['status'] );
		$this->assertArrayHasKey( 'default', $create_args['location'] );
		$this->assertArrayHasKey( 'default', $create_args['priority'] );

		$this->assertArrayNotHasKey( 'default', $update_args['status'] );
		$this->assertArrayNotHasKey( 'default', $update_args['location'] );
		$this->assertArrayNotHasKey( 'default', $update_args['priority'] );
	}

	/**
	 * Create path, checked for the same class of bug: a fresh snippet must
	 * always persist with page_template pinned to 'default' so a later
	 * theme switch can never retroactively invalidate it.
	 */
	public function test_create_custom_code_pins_page_template_to_default(): void {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/custom-code',
			array(
				'title'  => 'New snippet',
				'code'   => '<script>console.log("new");</script>',
				'status' => 'publish',
			)
		);

		$response = $controller->create_custom_code( $request );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'default', get_post_meta( $data['id'], '_wp_page_template', true ) );
	}
}
