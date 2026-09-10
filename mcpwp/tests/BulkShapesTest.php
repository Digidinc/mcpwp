<?php
/**
 * Coverage for #803 — bulk tools with surprising shapes, and one that
 * reports success while doing nothing.
 *
 *   1. `wp_bulk_update_pages` demanded `pages`, `wp_bulk_update_posts`
 *      demanded `posts`; the tool names and every sibling suggest `updates`.
 *      Both are accepted now.
 *   2. `wp_bulk_find_replace` demanded a single `id` — a site-wide
 *      find/replace tool that worked on one post at a time. `post_type`,
 *      `ids` and `dry_run` now scope it, with a per-post report.
 *   3. `PUT /posts/<id>` with a field the updater does not know returned 200
 *      and assigned nothing. Unknown fields are refused, by name, before any
 *      write — the same rule for pages, and for create.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class BulkShapesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['_mcpwp_test_posts']     = array();
		$GLOBALS['_mcpwp_test_meta']      = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta']  = array();
	}

	private function seed( int $id, string $type, string $title, ?string $elementor = null ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'            => $id,
			'post_type'     => $type,
			'post_title'    => $title,
			'post_name'     => sanitize_title( $title ),
			'post_status'   => 'publish',
			'post_content'  => '',
			'post_excerpt'  => '',
			'post_parent'   => 0,
			'menu_order'    => 0,
			'post_author'   => 1,
			'post_date'     => '2026-06-06 00:00:00',
			'post_modified' => '2026-06-06 00:00:00',
			'post_date_gmt'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
		);

		if ( null !== $elementor ) {
			update_post_meta( $id, '_elementor_data', $elementor );
		}
	}

	private function elementor_with( string $text ): string {
		return wp_json_encode(
			array(
				array(
					'id'       => 'sec1',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						array(
							'id'       => 'col1',
							'elType'   => 'column',
							'settings' => array( '_column_size' => 100 ),
							'elements' => array(
								array(
									'id'         => 'w1',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => array( 'title' => $text ),
								),
							),
						),
					),
				),
			)
		);
	}

	// ---------------------------------------------------------------
	// 1. `updates` is accepted beside the old names.
	// ---------------------------------------------------------------

	public function test_bulk_update_pages_accepts_updates(): void {
		$this->seed( 10, 'page', 'One' );
		$this->seed( 11, 'page', 'Two' );

		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/pages/bulk' );
		$request->set_param( 'updates', array( array( 'id' => 10, 'title' => 'One!' ), array( 'id' => 11, 'title' => 'Two!' ) ) );

		$data = ( new Mcpwp_REST_Pages() )->bulk_update_pages( $request )->get_data();

		$this->assertSame( 2, $data['total'] );
		$this->assertSame( array(), $data['errors'] );
		$this->assertSame( 'One!', get_post( 10 )->post_title );
	}

	public function test_bulk_update_pages_still_accepts_pages(): void {
		$this->seed( 10, 'page', 'One' );

		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/pages/bulk' );
		$request->set_param( 'pages', array( array( 'id' => 10, 'title' => 'One!' ) ) );

		$this->assertSame( 1, ( new Mcpwp_REST_Pages() )->bulk_update_pages( $request )->get_data()['total'] );
	}

	public function test_bulk_update_pages_with_neither_says_which_names_it_takes(): void {
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/pages/bulk' );

		$error = ( new Mcpwp_REST_Pages() )->bulk_update_pages( $request );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertStringContainsString( 'updates', $error->get_error_message() );
	}

	public function test_bulk_update_posts_accepts_updates(): void {
		$this->seed( 20, 'post', 'P' );

		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk' );
		$request->set_param( 'updates', array( array( 'id' => 20, 'title' => 'P!' ) ) );

		$data = ( new Mcpwp_REST_Posts() )->bulk_update_posts( $request )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'P!', get_post( 20 )->post_title );
	}

	public function test_the_tool_schemas_no_longer_require_the_old_name(): void {
		$tools = array();
		foreach ( ( new Mcpwp_MCP_Free_Tools() )->get_tools() as $tool ) {
			$tools[ $tool['name'] ] = $tool;
		}

		foreach ( array( 'wp_bulk_update_pages' => 'pages', 'wp_bulk_update_posts' => 'posts' ) as $name => $old ) {
			$required = isset( $tools[ $name ]['inputSchema']['required'] ) ? $tools[ $name ]['inputSchema']['required'] : array();
			$this->assertNotContains( $old, $required, $name . ' must not demand the old name.' );
			$this->assertArrayHasKey( 'updates', $tools[ $name ]['inputSchema']['properties'] );
		}

		$fr = $tools['wp_bulk_find_replace']['inputSchema'];
		$this->assertNotContains( 'id', isset( $fr['required'] ) ? $fr['required'] : array() );
		foreach ( array( 'post_type', 'ids', 'dry_run' ) as $p ) {
			$this->assertArrayHasKey( $p, $fr['properties'] );
		}
	}

	// ---------------------------------------------------------------
	// 2. Site-wide find/replace.
	// ---------------------------------------------------------------

	private function find_replace( array $params ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/find-replace' );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return ( new Mcpwp_REST_Elementor() )->find_replace_many( $request );
	}

	public function test_dry_run_reports_per_post_counts_and_writes_nothing(): void {
		$this->seed( 1, 'page', 'Home', $this->elementor_with( 'Call old-brand today' ) );
		$this->seed( 2, 'post', 'News', $this->elementor_with( 'old-brand and old-brand' ) );
		$this->seed( 3, 'page', 'Clean', $this->elementor_with( 'nothing here' ) );

		$data = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'new-brand', 'dry_run' => true, 'scope' => 'site' ) )->get_data();

		$this->assertTrue( $data['dry_run'] );
		$this->assertSame( 3, $data['total_replacements'] );
		$this->assertSame( array( 1 => 1, 2 => 2 ), array_column( $data['matched'], 'replacements', 'id' ) );
		$this->assertSame( 3, $data['scanned'] );
		$this->assertStringContainsString( 'old-brand', get_post_meta( 1, '_elementor_data', true ), 'Dry run must not write.' );
	}

	public function test_a_real_run_writes_only_the_posts_that_matched(): void {
		$this->seed( 1, 'page', 'Home', $this->elementor_with( 'old-brand' ) );
		$this->seed( 3, 'page', 'Clean', $this->elementor_with( 'nothing here' ) );
		$before_3 = get_post_meta( 3, '_elementor_data', true );

		$data = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'new-brand', 'scope' => 'site' ) )->get_data();

		$this->assertFalse( $data['dry_run'] );
		$this->assertStringContainsString( 'new-brand', get_post_meta( 1, '_elementor_data', true ) );
		$this->assertSame( $before_3, get_post_meta( 3, '_elementor_data', true ) );
		$this->assertSame( array( 1 ), array_column( $data['matched'], 'id' ) );
	}

	public function test_post_type_and_ids_scope_the_run(): void {
		$this->seed( 1, 'page', 'Home', $this->elementor_with( 'old-brand' ) );
		$this->seed( 2, 'post', 'News', $this->elementor_with( 'old-brand' ) );
		$this->seed( 4, 'post', 'More', $this->elementor_with( 'old-brand' ) );

		$by_type = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'post_type' => 'post', 'dry_run' => true ) )->get_data();
		$this->assertSame( array( 2, 4 ), array_column( $by_type['matched'], 'id' ) );

		$by_ids = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'ids' => array( 4 ), 'dry_run' => true ) )->get_data();
		$this->assertSame( array( 4 ), array_column( $by_ids['matched'], 'id' ) );
	}

	public function test_a_single_id_still_works_through_the_same_route(): void {
		$this->seed( 1, 'page', 'Home', $this->elementor_with( 'old-brand' ) );
		$this->seed( 2, 'post', 'News', $this->elementor_with( 'old-brand' ) );

		$data = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'id' => 2 ) )->get_data();

		$this->assertSame( array( 2 ), array_column( $data['matched'], 'id' ) );
		$this->assertStringContainsString( 'old-brand', get_post_meta( 1, '_elementor_data', true ) );
	}

	public function test_nothing_matched_and_nothing_scanned_are_different_answers(): void {
		$this->seed( 3, 'page', 'Clean', $this->elementor_with( 'nothing here' ) );

		$none = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'dry_run' => true, 'scope' => 'site' ) )->get_data();
		$this->assertSame( 1, $none['scanned'] );
		$this->assertSame( 0, $none['total_replacements'] );

		$empty = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'post_type' => 'elementor_library', 'dry_run' => true ) )->get_data();
		$this->assertSame( 0, $empty['scanned'], 'No Elementor content of that type is not the same as no match.' );
	}

	public function test_unreadable_elementor_data_is_reported_not_skipped_silently(): void {
		$this->seed( 1, 'page', 'Home', $this->elementor_with( 'old-brand' ) );
		$this->seed( 5, 'page', 'Broken', '{not json' );

		$data = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'dry_run' => true, 'scope' => 'site' ) )->get_data();

		$this->assertSame( array( 5 ), array_column( $data['unreadable'], 'id' ) );
	}

	// ---------------------------------------------------------------
	// Scope is explicit. From the adversarial review: `ids: []` used to
	// fall through to a site-wide rewrite — the single most likely shape an
	// agent produces when its candidate list comes back empty.
	// ---------------------------------------------------------------

	public function test_an_empty_or_zero_id_list_is_refused_never_widened(): void {
		$this->seed( 1, 'page', 'Home', $this->elementor_with( 'old-brand' ) );

		foreach ( array( array( 'ids' => array() ), array( 'ids' => array( 0 ) ), array( 'ids' => array( 'abc' ) ), array( 'id' => 0 ) ) as $scope ) {
			$result = $this->find_replace( array_merge( array( 'search' => 'old-brand', 'replace' => 'x' ), $scope ) );

			$this->assertInstanceOf( WP_Error::class, $result, wp_json_encode( $scope ) );
			$this->assertSame( 'invalid_ids', $result->get_error_code(), wp_json_encode( $scope ) );
		}

		$this->assertStringContainsString( 'old-brand', get_post_meta( 1, '_elementor_data', true ), 'Nothing was touched.' );
	}

	public function test_no_scope_is_refused_and_site_wide_is_opt_in(): void {
		$this->seed( 1, 'page', 'Home', $this->elementor_with( 'old-brand' ) );

		$none = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x' ) );
		$this->assertInstanceOf( WP_Error::class, $none );
		$this->assertSame( 'missing_scope', $none->get_error_code() );

		$both = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'ids' => array( 1 ), 'scope' => 'site' ) );
		$this->assertSame( 'ambiguous_scope', $both->get_error_code() );

		$this->assertStringContainsString( 'old-brand', get_post_meta( 1, '_elementor_data', true ) );
	}

	public function test_a_post_type_outside_elementor_content_is_refused(): void {
		$this->seed( 9, 'revision', 'Rev', $this->elementor_with( 'old-brand' ) );

		$result = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'post_type' => 'revision' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}

	public function test_drafts_and_private_posts_are_in_scope_trash_is_not(): void {
		$this->seed( 1, 'page', 'Draft', $this->elementor_with( 'old-brand' ) );
		$GLOBALS['_mcpwp_test_posts'][1]->post_status = 'draft';
		$this->seed( 2, 'page', 'Private', $this->elementor_with( 'old-brand' ) );
		$GLOBALS['_mcpwp_test_posts'][2]->post_status = 'private';
		$this->seed( 3, 'page', 'Trashed', $this->elementor_with( 'old-brand' ) );
		$GLOBALS['_mcpwp_test_posts'][3]->post_status = 'trash';

		$data = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'scope' => 'site', 'dry_run' => true ) )->get_data();

		$this->assertSame( array( 1, 2 ), array_column( $data['matched'], 'id' ) );
	}

	public function test_a_run_is_capped_and_says_where_to_resume(): void {
		for ( $i = 1; $i <= Mcpwp_REST_Elementor::FIND_REPLACE_MAX_POSTS + 5; $i++ ) {
			$this->seed( $i, 'page', 'P' . $i, $this->elementor_with( 'old-brand' ) );
		}

		$first = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'scope' => 'site', 'dry_run' => true ) )->get_data();

		$this->assertTrue( $first['truncated'] );
		$this->assertCount( Mcpwp_REST_Elementor::FIND_REPLACE_MAX_POSTS, $first['matched'] );
		$this->assertSame( Mcpwp_REST_Elementor::FIND_REPLACE_MAX_POSTS, $first['next_offset'] );

		$second = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'scope' => 'site', 'dry_run' => true, 'offset' => $first['next_offset'] ) )->get_data();

		$this->assertFalse( $second['truncated'] );
		$this->assertCount( 5, $second['matched'] );
	}

	public function test_present_but_empty_elementor_data_is_its_own_count(): void {
		$this->seed( 1, 'page', 'Empty', '' );
		$this->seed( 2, 'page', 'Home', $this->elementor_with( 'old-brand' ) );

		$data = $this->find_replace( array( 'search' => 'old-brand', 'replace' => 'x', 'scope' => 'site', 'dry_run' => true ) )->get_data();

		$this->assertSame( 1, $data['scanned'] );
		$this->assertSame( 1, $data['skipped_empty'], 'Present-but-empty is neither scanned nor unreadable; it is reported as what it is.' );
	}

	public function test_the_bulk_routes_register_updates_as_a_top_level_argument(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		( new Mcpwp_REST_Pages() )->register_routes();
		( new Mcpwp_REST_Posts() )->register_routes();

		$seen = 0;
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $route ) {
			if ( ! in_array( $route['route'], array( '/pages/bulk', '/posts/bulk' ), true ) ) {
				continue;
			}
			foreach ( $route['args'] as $handler ) {
				if ( ! isset( $handler['args'] ) || ! isset( $handler['args']['updates'] ) ) {
					continue;
				}
				$seen++;
				$this->assertSame( 'array', $handler['args']['updates']['type'] );
				foreach ( array( 'pages', 'posts' ) as $old ) {
					if ( isset( $handler['args'][ $old ] ) ) {
						$this->assertArrayNotHasKey( 'updates', $handler['args'][ $old ], 'updates must be a sibling of the old name, not nested inside it.' );
					}
				}
			}
		}

		$this->assertSame( 2, $seen, 'Both bulk-update handlers declare updates at the top level.' );
	}

	public function test_elementor_location_is_accepted_on_update_as_on_create(): void {
		$this->seed( 30, 'elementor_snippet', 'Snip' );

		$result = ( new Mcpwp_Posts() )->update_post( 30, array( 'elementor_location' => 'body_end' ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'body_end', get_post_meta( 30, '_elementor_location', true ) );
	}

	// ---------------------------------------------------------------
	// 3. Unknown fields are refused, never a silent 200.
	// ---------------------------------------------------------------

	public function test_update_post_refuses_a_field_it_does_not_know(): void {
		$this->seed( 20, 'post', 'P' );

		$error = ( new Mcpwp_Posts() )->update_post( 20, array( 'title' => 'P!', 'sm_audience' => array( 1522 ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'unsupported_fields', $error->get_error_code() );
		$this->assertSame( array( 'sm_audience' ), $error->get_error_data()['unsupported'] );
		$this->assertSame( 'P', get_post( 20 )->post_title, 'Refused before any write, not after a partial one.' );
	}

	public function test_update_page_refuses_a_field_it_does_not_know(): void {
		$this->seed( 10, 'page', 'One' );

		$error = ( new Mcpwp_Pages() )->update_page( 10, array( 'title' => 'One!', 'categories' => array( 1 ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'unsupported_fields', $error->get_error_code() );
		$this->assertStringContainsString( 'categories', $error->get_error_message() );
	}

	public function test_create_post_refuses_a_field_it_does_not_know(): void {
		$error = ( new Mcpwp_Posts() )->create_post( array( 'title' => 'New', 'tax_input' => array( 'sm_audience' => array( 1 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'unsupported_fields', $error->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'], 'Nothing created.' );
	}

	public function test_route_and_transport_parameters_are_not_unknown_fields(): void {
		// The REST layer hands the updater get_params(), which carries the
		// route's id and whatever the transport adds. Those are not the
		// caller's fields.
		$this->seed( 20, 'post', 'P' );

		$result = ( new Mcpwp_Posts() )->update_post( 20, array( 'id' => 20, 'title' => 'P!', 'context' => 'edit', '_locale' => 'user' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'P!', get_post( 20 )->post_title );
	}

	public function test_the_bulk_path_reports_the_unknown_field_per_item(): void {
		$this->seed( 20, 'post', 'P' );
		$this->seed( 21, 'post', 'Q' );

		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk' );
		$request->set_param( 'updates', array( array( 'id' => 20, 'title' => 'P!' ), array( 'id' => 21, 'sm_audience' => array( 1 ) ) ) );

		$data = ( new Mcpwp_REST_Posts() )->bulk_update_posts( $request )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertCount( 1, $data['errors'] );
		$this->assertSame( 21, $data['errors'][0]['id'] );
		$this->assertStringContainsString( 'sm_audience', $data['errors'][0]['message'] );
	}
}
