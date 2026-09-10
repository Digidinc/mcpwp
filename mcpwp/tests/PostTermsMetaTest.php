<?php
/**
 * Coverage for #797 — custom post types could be created but not filled.
 *
 * From a 101-record migration into site-plugin post types: no way to assign
 * custom taxonomy terms (`tax_input` unknown; a batch PUT with the taxonomy
 * as a field returned 200 and assigned nothing), no `meta` on create (165
 * separate meta calls for 33 records), `wp_set_post_meta` stripping `%20`
 * out of URLs, and the featured-image route refusing every type but post
 * and page.
 *
 * `terms: {taxonomy: [ids|slugs|names]}` and `meta: {key: value}` are now
 * accepted on create and update, for posts and pages. Rules pinned here:
 *
 *   - Validation first, write second. An unknown taxonomy, a taxonomy not
 *     attached to the type, an unknown term or a refused meta key is a 400
 *     before anything is written — not a 200 with a silent skip.
 *   - Registered meta goes through its registered sanitize callback and is
 *     otherwise stored as sent; unregistered strings keep their %xx octets.
 *   - What was applied is echoed back, resolved: term id, slug and name.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class PostTermsMetaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']         = array();
		$GLOBALS['_mcpwp_test_posts']          = array();
		$GLOBALS['_mcpwp_test_meta']           = array();
		$GLOBALS['_mcpwp_test_object_terms']   = array();
		$GLOBALS['_mcpwp_test_registered_meta'] = array();
		$GLOBALS['mcpwp_test_current_user']    = 2;

		$GLOBALS['_mcpwp_test_taxonomies'] = array(
			'sm_audience' => array(
				'object_type' => array( 'sm_team', 'post' ),
				'terms'       => array(
					1522 => array( 'slug' => 'k12', 'name' => 'K-12' ),
					1523 => array( 'slug' => 'educators', 'name' => 'Educators' ),
				),
			),
			'category'    => array(
				'object_type' => array( 'post' ),
				'terms'       => array( 1 => array( 'slug' => 'uncategorized', 'name' => 'Uncategorized' ) ),
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts']         = array();
		$GLOBALS['_mcpwp_test_meta']          = array();
		$GLOBALS['_mcpwp_test_object_terms']  = array();
		$GLOBALS['_mcpwp_test_taxonomies']    = array();
		$GLOBALS['_mcpwp_test_registered_meta'] = array();
	}

	private function seed( int $id, string $type ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_type'         => $type,
			'post_title'        => 'Seed',
			'post_name'         => 'seed',
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

	private function posts(): Mcpwp_Posts {
		return new Mcpwp_Posts();
	}

	// ---------------------------------------------------------------
	// Terms.
	// ---------------------------------------------------------------

	public function test_terms_by_id_are_assigned_on_create_and_echoed_resolved(): void {
		$result = $this->posts()->create_post(
			array(
				'title'     => 'Jane',
				'post_type' => 'post',
				'terms'     => array( 'sm_audience' => array( 1522 ) ),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( array( 1522 ), $GLOBALS['_mcpwp_test_object_terms'][ $result['id'] ]['sm_audience'] );
		$this->assertSame(
			array( array( 'id' => 1522, 'slug' => 'k12', 'name' => 'K-12' ) ),
			$result['terms']['sm_audience'],
			'The caller sees what landed, resolved, not what it sent.'
		);
	}

	public function test_terms_by_slug_or_name_are_resolved_on_update(): void {
		$this->seed( 40, 'post' );

		$result = $this->posts()->update_post( 40, array( 'terms' => array( 'sm_audience' => array( 'k12', 'Educators' ) ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 1522, 1523 ), $GLOBALS['_mcpwp_test_object_terms'][40]['sm_audience'] );
	}

	public function test_an_unknown_taxonomy_is_refused_before_any_write(): void {
		$this->seed( 40, 'post' );

		$error = $this->posts()->update_post( 40, array( 'title' => 'Changed', 'terms' => array( 'sm_region' => array( 1 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'unknown_taxonomy', $error->get_error_code() );
		$this->assertSame( 'Seed', get_post( 40 )->post_title, 'Refused before the title changed.' );
	}

	public function test_a_taxonomy_not_attached_to_the_post_type_is_refused(): void {
		$this->seed( 41, 'page' );

		$error = ( new Mcpwp_Pages() )->update_page( 41, array( 'terms' => array( 'sm_audience' => array( 1522 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'taxonomy_not_for_post_type', $error->get_error_code() );
	}

	public function test_an_unknown_term_is_refused_by_name(): void {
		$this->seed( 40, 'post' );

		$error = $this->posts()->update_post( 40, array( 'terms' => array( 'sm_audience' => array( 'k12', 'no-such-term', 9999 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'unknown_term', $error->get_error_code() );
		$this->assertSame( array( 'no-such-term', 9999 ), $error->get_error_data()['unknown'] );
		$this->assertArrayNotHasKey( 40, $GLOBALS['_mcpwp_test_object_terms'], 'All or nothing: the known term was not assigned either.' );
	}

	public function test_terms_replace_the_taxonomy_s_current_set(): void {
		$this->seed( 40, 'post' );
		$GLOBALS['_mcpwp_test_object_terms'][40]['sm_audience'] = array( 1523 );

		$this->posts()->update_post( 40, array( 'terms' => array( 'sm_audience' => array( 1522 ) ) ) );

		$this->assertSame( array( 1522 ), $GLOBALS['_mcpwp_test_object_terms'][40]['sm_audience'], 'Same semantics as categories: the list sent is the list that is.' );
	}

	// ---------------------------------------------------------------
	// Meta.
	// ---------------------------------------------------------------

	public function test_meta_on_create_is_written_and_registered_callbacks_run(): void {
		$GLOBALS['_mcpwp_test_registered_meta']['smc_cta_url'] = array(
			'object_subtype'    => 'post',
			'sanitize_callback' => 'esc_url_raw',
		);

		$result = $this->posts()->create_post(
			array(
				'title'     => 'Jane',
				'post_type' => 'post',
				'meta'      => array(
					'smc_cta_url' => 'mailto:info@example.com?subject=Farm%20Tour',
					'smc_role'    => 'Founder & CEO',
					'smc_links'   => array( 'x' => 'https://x.com/j', 'li' => 'https://linkedin.com/in/j' ),
				),
			)
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$id = $result['id'];
		$this->assertSame( 'mailto:info@example.com?subject=Farm%20Tour', get_post_meta( $id, 'smc_cta_url', true ), 'The registered callback ran; %20 survived.' );
		$this->assertSame( 'Founder & CEO', get_post_meta( $id, 'smc_role', true ), 'Not run through sanitize_text_field — an ampersand is not a threat.' );
		$this->assertSame( array( 'x' => 'https://x.com/j', 'li' => 'https://linkedin.com/in/j' ), get_post_meta( $id, 'smc_links', true ) );
		$this->assertSame( array( 'smc_cta_url', 'smc_role', 'smc_links' ), $result['meta_applied'] );
	}

	public function test_unregistered_meta_keeps_percent_encoding(): void {
		$this->seed( 40, 'post' );

		$this->posts()->update_post( 40, array( 'meta' => array( 'smc_cta_url' => 'https://example.com/?q=Farm%20Tour' ) ) );

		$this->assertSame( 'https://example.com/?q=Farm%20Tour', get_post_meta( 40, 'smc_cta_url', true ) );
	}

	public function test_html_in_unregistered_meta_is_reduced_to_what_a_post_may_carry(): void {
		$this->seed( 40, 'post' );

		$this->posts()->update_post( 40, array( 'meta' => array( 'smc_bio' => '<p>Hi</p><script>alert(1)</script>' ) ) );

		$stored = get_post_meta( 40, 'smc_bio', true );
		$this->assertStringContainsString( '<p>Hi</p>', $stored );
		$this->assertStringNotContainsString( '<script', $stored );
	}

	public function test_protected_and_reserved_meta_keys_are_refused_before_any_write(): void {
		$this->seed( 40, 'post' );

		foreach ( array( '_elementor_data', '_wp_page_template', 'mcpwp_api_key', '_edit_lock' ) as $key ) {
			$error = $this->posts()->update_post( 40, array( 'title' => 'Changed', 'meta' => array( $key => 'x' ) ) );

			$this->assertInstanceOf( WP_Error::class, $error, $key );
			$this->assertSame( 'forbidden_meta_key', $error->get_error_code(), $key );
		}

		$this->assertSame( 'Seed', get_post( 40 )->post_title );
		$this->assertArrayNotHasKey( 40, $GLOBALS['_mcpwp_test_meta'] );
	}

	public function test_pages_accept_meta_too(): void {
		$this->seed( 41, 'page' );

		$result = ( new Mcpwp_Pages() )->update_page( 41, array( 'meta' => array( 'smc_hero_variant' => 'b' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'b', get_post_meta( 41, 'smc_hero_variant', true ) );
	}

	public function test_a_meta_value_that_is_not_scalar_or_array_is_refused(): void {
		$this->seed( 40, 'post' );

		$error = $this->posts()->update_post( 40, array( 'meta' => 'not a map' ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'invalid_meta', $error->get_error_code() );
	}

	// ---------------------------------------------------------------
	// Featured image on any type that supports it.
	// ---------------------------------------------------------------

	private function featured( int $post_id, int $media_id ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts/' . $post_id . '/featured-image' );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'media_id', $media_id );
		return ( new Mcpwp_REST_Posts() )->set_featured_image( $request );
	}

	public function test_a_custom_post_type_that_supports_thumbnails_takes_a_featured_image(): void {
		$this->seed( 50, 'sm_team' );
		$this->seed( 51, 'attachment' );
		$GLOBALS['mcpwp_test_post_type_supports'] = array( 'sm_team' => array( 'thumbnail' ) );

		$response = $this->featured( 50, 51 );

		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( 51, (int) get_post_meta( 50, '_thumbnail_id', true ) );
	}

	public function test_a_type_without_thumbnail_support_is_told_why(): void {
		$this->seed( 50, 'sm_event' );
		$this->seed( 51, 'attachment' );
		$GLOBALS['mcpwp_test_post_type_supports'] = array();

		$error = $this->featured( 50, 51 );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'thumbnails_unsupported', $error->get_error_code() );
	}
}
