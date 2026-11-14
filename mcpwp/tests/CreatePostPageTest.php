<?php
/**
 * Regression coverage for #1039: creating a page through the posts writer.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';

final class CreatePostPageTest extends TestCase {

	private $saved_actions;

	protected function setUp(): void {
		$this->saved_actions = $GLOBALS['mcpwp_test_actions'];
		$GLOBALS['_mcpwp_test_posts']           = array();
		$GLOBALS['_mcpwp_test_meta']            = array();
		$GLOBALS['_mcpwp_test_object_terms']    = array();
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_current_user']     = 2;
		$GLOBALS['mcpwp_test_filters']          = array();
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product' );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_actions'] = $this->saved_actions;
		$GLOBALS['mcpwp_test_filters'] = array();
		unset( $GLOBALS['mcpwp_test_public_post_types'] );
	}

	public function test_create_post_with_page_type_returns_created_id_once(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'post_type' => 'page',
			'title'     => 'Issue 1039 page',
			'content'   => '<p>Created page content.</p>',
		) ) );

		$response = ( new Mcpwp_REST_Posts() )->create_post( $request );

		$pages = get_posts( array( 'post_type' => 'page', 'post_status' => 'draft' ) );
		$this->assertCount( 1, $pages, 'One create call must insert exactly one page.' );
		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( (int) $pages[0]->ID, $data['id'] );
		$this->assertSame( 'Issue 1039 page', $data['title'] );
		$this->assertSame( '<p>Created page content.</p>', $data['content'] );
		$this->assertSame( 'draft', $data['status'] );
	}

	public function test_readback_revalidates_the_persisted_post_type(): void {
		$posts = new Mcpwp_Posts();
		$posts->set_category_allowed_callback( function ( $type ) {
			return 'product' !== $type;
		} );
		add_filter( 'wp_insert_post_data', function ( $data ) {
			$data['post_type'] = 'product';
			return $data;
		} );

		$result = $posts->create_post( array( 'post_type' => 'page', 'title' => 'Changed by filter' ) );

		$this->assertCount( 1, get_posts( array( 'post_type' => 'product' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
	}

	public function test_deleted_during_insert_returns_not_found(): void {
		add_action( 'wp_insert_post', function ( $id ) {
			wp_delete_post( $id, true );
		} );

		$result = ( new Mcpwp_Posts() )->create_post( array( 'post_type' => 'page', 'title' => 'Deleted by hook' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}
}
