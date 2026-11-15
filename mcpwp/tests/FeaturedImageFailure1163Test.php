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
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-integrations.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-pages.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-woocommerce.php';

if ( ! class_exists( 'WooCommerce', false ) ) {
	class WooCommerce {} // phpcs:ignore
}
if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return (object) array( 'version' => '9.0.0' );
	}
}
if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $name, $taxonomy, $args = array() ) {
		$GLOBALS['_mcpwp_test_term_insert_count'] = (int) ( $GLOBALS['_mcpwp_test_term_insert_count'] ?? 0 ) + 1;
		$id                                       = 11960 + $GLOBALS['_mcpwp_test_term_insert_count'];
		if ( ! isset( $GLOBALS['_mcpwp_test_taxonomies'][ $taxonomy ]['terms'] ) ) {
			$GLOBALS['_mcpwp_test_taxonomies'][ $taxonomy ] = array( 'terms' => array() );
		}
		$GLOBALS['_mcpwp_test_taxonomies'][ $taxonomy ]['terms'][ $id ] = array(
			'name'        => (string) $name,
			'slug'        => isset( $args['slug'] ) ? (string) $args['slug'] : sanitize_title( $name ),
			'description' => isset( $args['description'] ) ? (string) $args['description'] : '',
			'parent'      => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
			'count'       => 0,
		);
		return array( 'term_id' => $id, 'term_taxonomy_id' => $id );
	}
}
if ( ! function_exists( 'wp_update_term' ) ) {
	function wp_update_term( $term_id, $taxonomy, $args = array() ) {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term ) {
			return new WP_Error( 'invalid_term', 'Missing' );
		}
		$t = &$GLOBALS['_mcpwp_test_taxonomies'][ $taxonomy ]['terms'][ (int) $term_id ];
		if ( isset( $args['name'] ) ) {
			$t['name'] = (string) $args['name'];
		}
		if ( isset( $args['slug'] ) ) {
			$t['slug'] = (string) $args['slug'];
		}
		if ( isset( $args['description'] ) ) {
			$t['description'] = (string) $args['description'];
		}
		return array( 'term_id' => (int) $term_id, 'term_taxonomy_id' => (int) $term_id );
	}
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key = '', $single = false ) {
		$store = $GLOBALS['_mcpwp_test_term_meta'][ (int) $term_id ] ?? array();
		if ( '' === $key ) {
			return $store;
		}
		return isset( $store[ $key ] ) ? $store[ $key ] : ( $single ? '' : array() );
	}
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$GLOBALS['_mcpwp_test_term_meta'][ (int) $term_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_term_meta' ) ) {
	function delete_term_meta( $term_id, $key ) {
		unset( $GLOBALS['_mcpwp_test_term_meta'][ (int) $term_id ][ $key ] );
		return true;
	}
}

if ( ! class_exists( 'Mcpwp_Test_Generate_Img_Provider', false ) ) {
	class Mcpwp_Test_Generate_Img_Provider { // phpcs:ignore
		public function generate_image_to_media( $prompt, $args ) {
			return array( 'id' => 11634 );
		}
	}
}

if ( ! class_exists( 'Mcpwp_Test_Generate_Img_Manager', false ) ) {
	class Mcpwp_Test_Generate_Img_Manager extends Mcpwp_Integration_Manager { // phpcs:ignore
		public function has_provider_key( $provider ) {
			return true;
		}
		public function get_provider_key( $provider ) {
			return 'stub-key';
		}
		public function get_provider_instance( $provider ) {
			return new Mcpwp_Test_Generate_Img_Provider();
		}
	}
}

final class FeaturedImageFailure1163Test extends TestCase {

	private const POST_ID       = 11631;
	private const PAGE_ID       = 11632;
	private const IMAGE_ID      = 11633;
	private const PDF_ID        = 11634;
	private const PDF_PREVIEW   = 11635;
	private const MISSING_ID    = 116399;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['_mcpwp_test_posts']            = array();
		$GLOBALS['_mcpwp_test_meta']             = array();
		$GLOBALS['_mcpwp_test_thumbnail_ids']    = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'post', 'page' );
		$GLOBALS['mcpwp_test_image_downsize']    = array();

		$this->seed_content( self::POST_ID, 'post', 'Post' );
		$this->seed_content( self::PAGE_ID, 'page', 'Page' );
		$this->seed_attachment( self::IMAGE_ID, 'image/jpeg' );
		$this->seed_attachment( self::PDF_ID, 'application/pdf' );
		$this->seed_attachment( self::PDF_PREVIEW, 'application/pdf' );
		$GLOBALS['mcpwp_test_image_downsize'][ self::PDF_PREVIEW ]['thumbnail'] = array(
			'https://example.test/media/' . self::PDF_PREVIEW . '-preview.jpg',
			100,
			100,
			false,
		);

		set_post_thumbnail( self::POST_ID, self::IMAGE_ID );
		set_post_thumbnail( self::PAGE_ID, self::IMAGE_ID );
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts']           = array();
		$GLOBALS['_mcpwp_test_meta']            = array();
		$GLOBALS['_mcpwp_test_thumbnail_ids']   = array();
		$GLOBALS['mcpwp_test_image_downsize']   = array();
		$GLOBALS['_mcpwp_test_taxonomies']      = array();
		$GLOBALS['_mcpwp_test_term_insert_count'] = 0;
		$GLOBALS['_mcpwp_test_term_meta']       = array();
		unset( $GLOBALS['mcpwp_test_thumbnail_readback_for_post'], $GLOBALS['mcpwp_test_thumbnail_readback_armed'], $GLOBALS['mcpwp_test_public_post_types'], $GLOBALS['mcpwp_test_fs'] );
		$manager_ref = new ReflectionProperty( Mcpwp_Integration_Manager::class, 'instance' );
		$manager_ref->setAccessible( true );
		$manager_ref->setValue( null, null );
		$license_ref = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$license_ref->setAccessible( true );
		$license_ref->setValue( null, null );
	}

	private function seed_content( int $id, string $type, string $title ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_title'  => $title,
				'post_name'   => sanitize_title( $title ),
				'post_status' => 'publish',
			)
		);
	}

	private function seed_attachment( int $id, string $mime ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
			array(
				'ID'             => $id,
				'post_type'      => 'attachment',
				'post_mime_type' => $mime,
				'post_title'     => 'Attachment ' . $id,
				'post_name'      => 'attachment-' . $id,
				'post_status'    => 'inherit',
			)
		);
	}

	private function posts(): Mcpwp_Posts {
		return new Mcpwp_Posts();
	}

	private function pages(): Mcpwp_Pages {
		return new Mcpwp_Pages();
	}

	private function featured_route( int $post_id, $media_id ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts/' . $post_id . '/featured-image' );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'media_id', $media_id );
		return ( new Mcpwp_REST_Posts() )->set_featured_image( $request );
	}

	private function assert_thumbnail_unchanged( int $post_id, int $expected ): void {
		$this->assertSame( $expected, (int) get_post_meta( $post_id, '_thumbnail_id', true ), 'Existing thumbnail must stay untouched on failure.' );
	}

	public function test_validate_featured_image_id_rejects_missing_and_zero(): void {
		$missing = Mcpwp_Posts::validate_featured_image_id( self::MISSING_ID );
		$this->assertInstanceOf( WP_Error::class, $missing );
		$this->assertSame( 'invalid_media', $missing->get_error_code() );

		$zero = Mcpwp_Posts::validate_featured_image_id( 0 );
		$this->assertSame( 'invalid_media', $zero->get_error_code() );

		$post_as_media = Mcpwp_Posts::validate_featured_image_id( self::POST_ID );
		$this->assertSame( 'invalid_media', $post_as_media->get_error_code() );

		$this->assertTrue( Mcpwp_Posts::validate_featured_image_id( self::IMAGE_ID ) );
	}

	public function test_pdf_without_preview_is_refused_pdf_with_preview_accepted(): void {
		$pdf = Mcpwp_Posts::validate_featured_image_id( self::PDF_ID );
		$this->assertSame( 'invalid_media', $pdf->get_error_code() );

		$this->assertTrue( Mcpwp_Posts::validate_featured_image_id( self::PDF_PREVIEW ) );
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

	public function test_put_posts_featured_image_pdf_preview_is_allowed(): void {
		$result = $this->posts()->update_post(
			self::POST_ID,
			array( 'featured_image' => self::PDF_PREVIEW )
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( self::PDF_PREVIEW, (int) get_post_meta( self::POST_ID, '_thumbnail_id', true ) );
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

	public function test_setting_the_same_image_again_succeeds(): void {
		$route = $this->featured_route( self::POST_ID, self::IMAGE_ID );
		$this->assertInstanceOf( WP_REST_Response::class, $route );
		$this->assertSame( self::IMAGE_ID, (int) get_post_meta( self::POST_ID, '_thumbnail_id', true ) );
	}

	public function test_featured_image_removal_via_zero_on_update_and_route(): void {
		$by_field = $this->posts()->update_post( self::POST_ID, array( 'featured_image' => 0 ) );
		$this->assertIsArray( $by_field );
		$this->assertSame( 0, (int) get_post_meta( self::POST_ID, '_thumbnail_id', true ) );

		set_post_thumbnail( self::POST_ID, self::IMAGE_ID );
		$by_route = $this->featured_route( self::POST_ID, 0 );
		$this->assertInstanceOf( WP_REST_Response::class, $by_route );
		$this->assertSame( 0, (int) get_post_meta( self::POST_ID, '_thumbnail_id', true ) );
	}

	public function test_featured_image_removal_accepts_empty_string_and_signed_zero_tokens(): void {
		foreach ( array( '', '0', 0.0, '+00', '-0' ) as $value ) {
			set_post_thumbnail( self::POST_ID, self::IMAGE_ID );
			$result = $this->posts()->update_post( self::POST_ID, array( 'featured_image' => $value ) );
			$this->assertIsArray( $result, var_export( $value, true ) );
			$this->assertSame( 0, (int) get_post_meta( self::POST_ID, '_thumbnail_id', true ), var_export( $value, true ) );
		}
	}

	public function test_whole_number_float_ids_are_accepted_on_featured_image(): void {
		$new_image = self::IMAGE_ID + 50;
		$this->seed_attachment( $new_image, 'image/png' );
		$result = $this->posts()->update_post( self::POST_ID, array( 'featured_image' => (float) $new_image ) );
		$this->assertIsArray( $result );
		$this->assertSame( $new_image, (int) get_post_meta( self::POST_ID, '_thumbnail_id', true ) );
		$five = Mcpwp_Posts::resolve_featured_image_field( '5.0' );
		$this->assertIsArray( $five );
		$this->assertFalse( $five['remove'] );
		$this->assertSame( 5, $five['id'] );
	}

	public function test_featured_image_null_on_a_body_field_is_refused(): void {
		$error = $this->posts()->update_post( self::POST_ID, array( 'featured_image' => null ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'invalid_post_ref', $error->get_error_code() );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
	}

	public function test_apply_featured_image_restores_previous_thumbnail_when_read_back_fails(): void {
		$new_image = self::IMAGE_ID + 77;
		$this->seed_attachment( $new_image, 'image/jpeg' );
		$GLOBALS['mcpwp_test_thumbnail_readback_for_post'][ self::POST_ID ] = self::MISSING_ID;
		$error = Mcpwp_Posts::apply_featured_image( self::POST_ID, $new_image );
		$this->assertInstanceOf( WP_Error::class, $error );
		unset( $GLOBALS['mcpwp_test_thumbnail_readback_for_post'][ self::POST_ID ] );
		$this->assertSame( self::IMAGE_ID, (int) ( $GLOBALS['_mcpwp_test_meta'][ self::POST_ID ]['_thumbnail_id'] ?? 0 ) );
		$this->assertSame( 'invalid_media', $error->get_error_code() );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
	}

	public function test_product_category_create_refuses_bad_image_before_insert_term(): void {
		$GLOBALS['_mcpwp_test_term_insert_count'] = 0;
		$wc = new Mcpwp_WooCommerce();
		$error = $wc->create_product_category(
			array(
				'name'     => 'Should not land',
				'image_id' => self::PDF_ID,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'invalid_media', $error->get_error_code() );
		$this->assertSame( 0, (int) ( $GLOBALS['_mcpwp_test_term_insert_count'] ?? 0 ) );
	}

	public function test_product_category_update_refuses_bad_image_before_renaming(): void {
		$GLOBALS['_mcpwp_test_taxonomies'] = array(
			'product_cat' => array(
				'terms' => array(
					44 => array( 'name' => 'Keep', 'slug' => 'keep', 'description' => '', 'parent' => 0, 'count' => 0 ),
				),
			),
		);
		$wc    = new Mcpwp_WooCommerce();
		$error = $wc->update_product_category( 44, array( 'name' => 'Renamed', 'image_id' => self::PDF_ID ) );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'Keep', $GLOBALS['_mcpwp_test_taxonomies']['product_cat']['terms'][44]['name'] );
	}

	public function test_create_post_refuses_bad_attachment_before_insert(): void {
		$before = count( $GLOBALS['_mcpwp_test_posts'] );
		$error  = $this->posts()->create_post(
			array(
				'title'          => 'New',
				'featured_image' => self::PDF_ID,
			)
		);
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( $before, count( $GLOBALS['_mcpwp_test_posts'] ) );
	}

	public function test_featured_image_route_refuses_non_attachment(): void {
		$response = $this->featured_route( self::POST_ID, self::PAGE_ID );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_media', $response->get_error_code() );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
	}

	public function test_resolve_featured_image_refuses_null_array_and_negatives(): void {
		$this->assertSame( 'invalid_post_ref', Mcpwp_Posts::resolve_featured_image_field( null )->get_error_code() );
		$this->assertSame( 'invalid_post_ref', Mcpwp_Posts::resolve_featured_image_field( array( 99 ) )->get_error_code() );
		$this->assertSame( 'invalid_post_ref', Mcpwp_Posts::resolve_featured_image_field( -5 )->get_error_code() );
		$this->assertSame( 'invalid_post_ref', Mcpwp_Posts::resolve_featured_image_field( '0abc' )->get_error_code() );

		$remove = Mcpwp_Posts::resolve_featured_image_field( 0 );
		$this->assertIsArray( $remove );
		$this->assertTrue( $remove['remove'] );
	}

	public function test_bot_loose_featured_image_values_refused_at_handler(): void {
		$this->assertInstanceOf( WP_Error::class, $this->posts()->update_post( self::POST_ID, array( 'featured_image' => array( 99 ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->posts()->update_post( self::POST_ID, array( 'featured_image' => -5 ) ) );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
	}

	public function test_post_meta_thumbnail_id_route_shares_validation(): void {
		$controller = new class() extends Mcpwp_REST_Site_Content {
			public function check_permission( $request ) {
				return true;
			}
		};
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/post-meta/' . self::POST_ID );
		$request->set_url_params( array( 'id' => self::POST_ID ) );
		$request->set_param( 'key', '_thumbnail_id' );
		$request->set_param( 'value', (string) self::PDF_ID );

		$response = $controller->set_post_meta_handler( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_media', $response->get_error_code() );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
	}

	public function test_rollback_reports_failure_when_snapshot_thumbnail_is_invalid(): void {
		$method = new ReflectionMethod( Mcpwp_Action_Log::class, 'apply_snapshot' );
		$method->setAccessible( true );
		$snap   = array(
			'post_id'        => self::POST_ID,
			'post_title'     => 'Post',
			'post_content'   => '',
			'post_status'    => 'publish',
			'post_name'      => 'post',
			'post_excerpt'   => '',
			'thumbnail_id'   => self::MISSING_ID,
		);
		$result = $method->invoke( null, 'post', $snap, true );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rollback_thumbnail_failed', $result->get_error_code() );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
	}

	public function test_generate_featured_image_validation_error_keeps_attachment_id_in_data(): void {
		$manager_ref = new ReflectionProperty( Mcpwp_Integration_Manager::class, 'instance' );
		$manager_ref->setAccessible( true );
		$manager_ref->setValue( null, new Mcpwp_Test_Generate_Img_Manager() );

		$GLOBALS['mcpwp_test_fs'] = new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' );
		$license_ref              = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$license_ref->setAccessible( true );
		$license_ref->setValue( null, null );

		$controller = new class() extends Mcpwp_REST_Integrations {
			public function check_permission( $request ) {
				return true;
			}
		};
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/integrations/generate-featured-image' );
		$request->set_param( 'post_id', self::POST_ID );
		$request->set_param( 'prompt', 'hero shot' );
		$request->set_param( 'provider', 'openai' );

		$response = $controller->generate_featured_image( $request );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_media', $response->get_error_code() );
		$data = $response->get_error_data();
		$this->assertSame( self::PDF_ID, (int) $data['id'] );
		$this->assertSame( self::PDF_ID, (int) $data['attachment_id'] );
		$this->assert_thumbnail_unchanged( self::POST_ID, self::IMAGE_ID );
	}
}