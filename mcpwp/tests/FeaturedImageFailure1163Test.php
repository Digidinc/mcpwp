<?php
/**
 * #1163 — featured-image setters must refuse invalid media before calling
 * set_post_thumbnail(), so a failed set never deletes an existing thumbnail
 * or returns success with an empty url.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-pages.php';

final class FeaturedImageFailure1163Test extends TestCase {

	private const POST_ID    = 11631;
	private const PAGE_ID    = 11632;
	private const IMAGE_ID   = 11633;
	private const PDF_ID     = 11634;
	private const MISSING_ID = 116399;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['_mcpwp_test_posts']            = array();
		$GLOBALS['_mcpwp_test_meta']             = array();
		$GLOBALS['_mcpwp_test_thumbnail_ids']    = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'post', 'page' );

		$this->seed_content( self::POST_ID, 'post', 'Post' );
		$this->seed_content( self::PAGE_ID, 'page', 'Page' );
		$this->seed_attachment( self::IMAGE_ID, 'image/jpeg' );
		$this->seed_attachment( self::PDF_ID, 'application/pdf' );

		set_post_thumbnail( self::POST_ID, self::IMAGE_ID );
		set_post_thumbnail( self::PAGE_ID, self::IMAGE_ID );
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts']         = array();
		$GLOBALS['_mcpwp_test_meta']          = array();
		$GLOBALS['_mcpwp_test_thumbnail_ids'] = array();
		unset( $GLOBALS['mcpwp_test_public_post_types'] );
	}

	private function seed_content( int $id, string $type, string $title ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => $type,
			'post_title'        => $title,
			'post_name'         => sanitize_title( $title ),
			'post_status'       => 'publish',
			'post_content'      => '',
			'post_excerpt'      => '',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_author'       => 1,
			'post_date'         => '2026-06-06 00:00:00',
			'post_date_gmt'     => '2026-06-06 00:00:00',
			'post_modified'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
		);
	}

	private function seed_attachment( int $id, string $mime ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => 'attachment',
			'post_mime_type'    => $mime,
			'post_title'        => 'Attachment ' . $id,
			'post_name'         => 'attachment-' . $id,
			'post_status'       => 'inherit',
			'post_content'      => '',
			'post_excerpt'      => '',
			'post_parent'       => 0,
			'post_author'       => 1,
			'post_date'         => '2026-06-06 00:00:00',
			'post_date_gmt'     => '2026-06-06 00:00:00',
			'post_modified'     => '2026-06-06 00:00:00',
			'post_modified_gmt' => '2026-06-06 00:00:00',
		);
	}

	private function posts(): Mcpwp_Posts {
		return new Mcpwp_Posts();
	}

	private function pages(): Mcpwp_Pages {
		return new Mcpwp_Pages();
	}

	private function featured_route( int $post_id, int $media_id ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts/' . $post_id . '/featured-image' );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'media_id', $media_id );
		return ( new Mcpwp_REST_Posts() )->set_featured_image( $request );
	}

	private function assert_thumbnail_unchanged( int $post_id, int $expected ): void {
		$this->assertSame( $expected, (int) get_post_meta( $post_id, '_thumbnail_id', true ), 'Existing thumbnail must stay untouched on failure.' );
	}

	public function test_validate_featured_image_id_rejects_missing_and_non_image(): void {
		$missing = Mcpwp_Posts::validate_featured_image_id( self::MISSING_ID );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'invalid_media', $missing->get_error_code() );

		$post_as_media = Mcpwp_Posts::validate_featured_image_id( self::POST_ID );
		$this->assertSame( 'invalid_media', $post_as_media->get_error_code() );

		$pdf = Mcpwp_Posts::validate_featured_image_id( self::PDF_ID );
		$this->assertSame( 'invalid_media', $pdf->get_error_code() );
		$this->assertSame( 400, $pdf->get_error_data()['status'] );

		$this->assertTrue( Mcpwp_Posts::validate_featured_image_id( self::IMAGE_ID ) );
	}

	public function test_put_posts_featured_image_pdf_leaves_existing_thumbnail(): void {
		$error = $this->posts()->update_post(
			self::POST_ID,
			array(
				'title'          => 'Changed title must not land',
				'featured_image' => self::PDF_ID,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'invalid_media', $error->get_error_code() );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
		$this->assertSame( 'Post', $GLOBALS['_mcpwp_test_posts'][ self::POST_ID ]->post_title );
	}

	public function test_post_featured_image_route_refuses_pdf_and_keeps_thumbnail(): void {
		$response = $this->featured_route( self::POST_ID, self::PDF_ID );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_media', $response->get_error_code() );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
	}

	public function test_page_update_refuses_pdf_featured_image(): void {
		$error = $this->pages()->update_page(
			self::PAGE_ID,
			array( 'featured_image' => self::PDF_ID )
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'invalid_media', $error->get_error_code() );
		$this->assert_thumbnail_unchanged( self::PAGE_ID, self::IMAGE_ID );
	}

	public function test_page_create_refuses_pdf_featured_image_before_insert(): void {
		$before = count( $GLOBALS['_mcpwp_test_posts'] );

		$error = $this->pages()->create_page(
			array(
				'title'          => 'New page',
				'featured_image' => self::PDF_ID,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'invalid_media', $error->get_error_code() );
		$this->assertSame( $before, count( $GLOBALS['_mcpwp_test_posts'] ), 'Nothing was created.' );
	}

	public function test_bulk_update_refuses_pdf_on_one_item_and_leaves_thumbnail(): void {
		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk' );
		$request->set_param(
			'updates',
			array(
				array(
					'id'             => self::POST_ID,
					'featured_image' => self::PDF_ID,
				),
			)
		);

		$response = ( new Mcpwp_REST_Posts() )->bulk_update_posts( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'errors', $data );
		$this->assertCount( 1, $data['errors'] );
		$this->assertSame( self::POST_ID, (int) $data['errors'][0]['id'] );
		$this->assertStringContainsString( 'not an image', strtolower( $data['errors'][0]['message'] ) );
		$this->assertCount( 0, $data['updated'] );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
	}

	public function test_success_path_still_sets_a_real_image(): void {
		$new_image = self::IMAGE_ID + 100;
		$this->seed_attachment( $new_image, 'image/png' );

		$result = $this->posts()->update_post( self::POST_ID, array( 'featured_image' => $new_image ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $new_image, (int) get_post_meta( self::POST_ID, '_thumbnail_id', true ) );

		$route = $this->featured_route( self::POST_ID, self::IMAGE_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $route );
		$this->assertTrue( $route->get_data()['success'] ?? false );
		$this->assertSame( self::IMAGE_ID, (int) get_post_meta( self::POST_ID, '_thumbnail_id', true ) );
	}
}