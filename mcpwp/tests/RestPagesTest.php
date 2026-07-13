<?php
use PHPUnit\Framework\TestCase;

final class RestPagesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array( 1 => (object) array( 'ID' => 1, 'post_type' => 'page', 'post_title' => 'Home', 'post_name' => 'home', 'post_status' => 'publish', 'post_date' => '2026-01-01', 'post_modified' => '2026-01-02', 'post_parent' => 0, 'menu_order' => 0, 'post_author' => 2, 'post_content' => 'Hello' ) );
		$GLOBALS['_mcpwp_test_meta'] = array();
	}
	public function test_registers_only_collection_and_singular_page_routes(): void {
		( new Mcpwp_REST_Pages() )->register_routes();
		$this->assertSame( array( '/pages', '/pages/(?P<id>\d+)' ), array_column( $GLOBALS['_mcpwp_test_registered_routes'], 'route' ) );
	}
	public function test_get_page_returns_local_page(): void {
		$response = ( new Mcpwp_REST_Pages() )->get_page( new WP_REST_Request( 'GET', '/mcpwp/v1/pages/1', array( 'id' => 1 ) ) );
		$this->assertSame( 1, $response->get_data()['id'] );
		$this->assertSame( 'Home', $response->get_data()['title'] );
	}
}
