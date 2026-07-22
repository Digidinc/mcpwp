<?php

use PHPUnit\Framework\TestCase;

final class RestPagesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_posts']             = array(
			1 => (object) array(
				'ID'            => 1,
				'post_type'     => 'page',
				'post_title'    => 'Launch Home',
				'post_name'     => 'launch-home',
				'post_status'   => 'publish',
				'post_date'     => '2026-06-01 00:00:00',
				'post_modified' => '2026-06-02 00:00:00',
				'post_parent'   => 0,
				'menu_order'    => 0,
				'post_author'   => 7,
				'post_content'  => 'Home content',
			),
			2 => (object) array(
				'ID'            => 2,
				'post_type'     => 'page',
				'post_title'    => 'Download',
				'post_name'     => 'download',
				'post_status'   => 'publish',
				'post_date'     => '2026-06-01 00:00:00',
				'post_modified' => '2026-06-02 00:00:00',
				'post_parent'   => 0,
				'menu_order'    => 1,
				'post_author'   => 7,
				'post_content'  => 'Download content',
			),
			3 => (object) array(
				'ID'            => 3,
				'post_type'     => 'page',
				'post_title'    => 'Pricing',
				'post_name'     => 'pricing',
				'post_status'   => 'draft',
				'post_date'     => '2026-06-01 00:00:00',
				'post_modified' => '2026-06-02 00:00:00',
				'post_parent'   => 0,
				'menu_order'    => 2,
				'post_author'   => 7,
				'post_content'  => 'Pricing content',
			),
		);
		$GLOBALS['_mcpwp_test_meta'] = array();
	}

	public function test_pages_route_accepts_advertised_pagination_args(): void {
		$controller = new Mcpwp_REST_Pages();
		$controller->register_routes();

		$pages_route = null;
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $route ) {
			if ( 'mcpwp/v1' === $route['namespace'] && '/pages' === $route['route'] ) {
				$pages_route = $route;
				break;
			}
		}

		$this->assertNotNull( $pages_route, 'Expected /pages route to be registered.' );
		$this->assertArrayHasKey( 'per_page', $pages_route['args'][0]['args'] );
		$this->assertArrayHasKey( 'page', $pages_route['args'][0]['args'] );
		$this->assertSame( 100, $pages_route['args'][0]['args']['per_page']['maximum'] );
		$this->assertSame( 1, $pages_route['args'][0]['args']['page']['minimum'] );
	}

	public function test_list_pages_maps_per_page_and_page_to_query_args(): void {
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/pages',
			array(
				'status'   => 'publish',
				'per_page' => 1,
				'page'     => 2,
				'fields'   => 'id,title',
			)
		);

		$response = $controller->list_pages( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['per_page'] );
		$this->assertSame( 2, $data['page'] );
		$this->assertSame( 2, $data['total'] );
		$this->assertSame( 2, $data['pages_count'] );
		$this->assertCount( 1, $data['pages'] );
		$this->assertSame( 2, $data['pages'][0]['id'] );
		$this->assertSame( 'Download', $data['pages'][0]['title'] );
	}

	public function test_create_page_accepts_and_returns_excerpt(): void {
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/pages',
			array(
				'title'   => 'New page',
				'excerpt' => 'A short deck.',
			)
		);

		$response = $controller->create_page( $request );
		$data     = $response->get_data();

		$this->assertSame( 'A short deck.', $data['excerpt'] );
	}

	public function test_update_page_sets_excerpt_and_reports_it_in_changes(): void {
		$controller = new Mcpwp_REST_Pages();
		$request    = new WP_REST_Request(
			'PUT',
			'/mcpwp/v1/pages/1',
			array(
				'id'      => 1,
				'excerpt' => 'Updated deck copy.',
			)
		);

		$response = $controller->update_page( $request );
		$data     = $response->get_data();

		$this->assertSame( 'Updated deck copy.', $data['excerpt'] );

		$changed_fields = array_column( $data['changes'], 'field' );
		$this->assertContains( 'excerpt', $changed_fields );
	}
}
