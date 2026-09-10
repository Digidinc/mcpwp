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
		unset( $GLOBALS['mcpwp_test_post_type_supports'], $GLOBALS['mcpwp_test_public_post_types'] );
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

	public function test_a_registered_callback_actually_runs(): void {
		// The harness now runs sanitize_meta() on write like core does, so
		// this can only pass if the callback executed.
		$GLOBALS['_mcpwp_test_registered_meta']['smc_code'] = array(
			'object_subtype'    => 'post',
			'sanitize_callback' => 'strtoupper',
		);
		$this->seed( 40, 'post' );

		$this->posts()->update_post( 40, array( 'meta' => array( 'smc_code' => 'abc' ) ) );

		$this->assertSame( 'ABC', get_post_meta( 40, 'smc_code', true ) );
	}

	public function test_registered_meta_without_a_sanitizer_is_still_reduced_when_it_carries_tags(): void {
		// register_post_meta( 'post', 'subtitle', [ show_in_rest => true ] ) with
		// no sanitize_callback is the common case. "Registered" is not a
		// licence to store <script> while post_content in the same request
		// is kses'd.
		$GLOBALS['_mcpwp_test_registered_meta']['subtitle'] = array( 'object_subtype' => 'post', 'sanitize_callback' => null );
		$this->seed( 40, 'post' );

		$this->posts()->update_post( 40, array( 'meta' => array( 'subtitle' => '<em>Hi</em><script>alert(1)</script>' ) ) );

		$stored = get_post_meta( 40, 'subtitle', true );
		$this->assertStringContainsString( '<em>Hi</em>', $stored );
		$this->assertStringNotContainsString( '<script', $stored );
	}

	public function test_an_all_digit_slug_is_a_slug_not_a_term_id(): void {
		// A "2024" year slug must not resolve to whichever term carries id
		// 2024. Only an integer is an id.
		$GLOBALS['_mcpwp_test_taxonomies']['sm_year'] = array(
			'object_type' => array( 'post' ),
			'terms'       => array(
				2024 => array( 'slug' => 'widgets', 'name' => 'Widgets' ),
				77   => array( 'slug' => '2024', 'name' => '2024' ),
			),
		);
		$this->seed( 40, 'post' );

		$this->posts()->update_post( 40, array( 'terms' => array( 'sm_year' => array( '2024' ) ) ) );
		$this->assertSame( array( 77 ), $GLOBALS['_mcpwp_test_object_terms'][40]['sm_year'], 'The string resolved as a slug.' );

		$this->posts()->update_post( 40, array( 'terms' => array( 'sm_year' => array( 2024 ) ) ) );
		$this->assertSame( array( 2024 ), $GLOBALS['_mcpwp_test_object_terms'][40]['sm_year'], 'The integer resolved as an id.' );
	}

	public function test_clearing_a_taxonomy_is_visible_in_the_response(): void {
		$this->seed( 40, 'post' );
		$GLOBALS['_mcpwp_test_object_terms'][40]['sm_audience'] = array( 1522 );

		$result = $this->posts()->update_post( 40, array( 'terms' => array( 'sm_audience' => array() ) ) );

		$this->assertSame( array(), $GLOBALS['_mcpwp_test_object_terms'][40]['sm_audience'] );
		$this->assertSame( array( 'sm_audience' => array() ), $result['terms'], 'An emptied taxonomy is reported, not dropped from the echo.' );
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
	// The dedicated meta route answers with the same policy.
	// ---------------------------------------------------------------

	private function set_meta( int $post_id, string $key, $value ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts/' . $post_id . '/meta' );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'key', $key );
		$request->set_param( 'value', $value );
		return ( new Mcpwp_REST_Site_Content() )->set_post_meta_handler( $request );
	}

	public function test_the_meta_route_uses_the_same_policy_as_a_post_write(): void {
		$this->seed( 40, 'post' );
		// The route checks `instanceof WP_Post`; the plain seed is a stdClass.
		$GLOBALS['_mcpwp_test_posts'][40] = new WP_Post( (array) $GLOBALS['_mcpwp_test_posts'][40] );

		$ok = $this->set_meta( 40, 'smc_role', 'Founder & CEO' );
		$this->assertInstanceOf( WP_REST_Response::class, $ok );
		$this->assertSame( 'Founder & CEO', get_post_meta( 40, 'smc_role', true ), 'No sanitize_text_field: the ampersand survives here too.' );

		$blocked = $this->set_meta( 40, 'mcpwp_api_key', 'x' );
		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'forbidden_meta_key', $blocked->get_error_code() );

		$elementor = $this->set_meta( 40, '_elementor_data', '[]' );
		$this->assertSame( 'use_elementor_endpoint', $elementor->get_error_code() );

		// The two core keys this route has always accepted still are — and
		// _thumbnail_id has to point at an attachment, as on the dedicated route.
		$this->seed( 51, 'attachment' );
		$this->assertInstanceOf( WP_REST_Response::class, $this->set_meta( 40, '_thumbnail_id', 51 ) );
		$this->assertSame( 'invalid_media', $this->set_meta( 40, '_thumbnail_id', 40 )->get_error_code(), 'A page is not an attachment.' );
		$this->assertInstanceOf( WP_REST_Response::class, $this->set_meta( 40, '_wp_page_template', 'default' ) );

		// A key that does not survive the charset is refused, not rewritten.
		$this->assertSame( 'invalid_meta_key', $this->set_meta( 40, 'my key/with stuff', 'v' )->get_error_code() );

		// Blocked prefixes are case-insensitive: meta_key collates that way.
		$this->assertSame( 'forbidden_meta_key', $this->set_meta( 40, 'MCPWP_api_key', 'x' )->get_error_code() );
	}

	public function test_setting_a_meta_key_to_null_deletes_it_and_says_so(): void {
		$this->seed( 40, 'post' );
		update_post_meta( 40, 'smc_old', 'v' );

		$result = $this->posts()->update_post( 40, array( 'meta' => array( 'smc_old' => null, 'smc_new' => 'w' ) ) );

		$this->assertSame( '', (string) get_post_meta( 40, 'smc_old', true ) );
		$this->assertSame( array( 'smc_old' ), $result['meta_deleted'] );
		$this->assertSame( array( 'smc_new' ), $result['meta_applied'], 'A deletion is not reported as a write.' );
	}

	public function test_categories_and_terms_category_together_are_refused(): void {
		$this->seed( 40, 'post' );

		$error = $this->posts()->update_post( 40, array( 'categories' => array( 1 ), 'terms' => array( 'category' => array( 1 ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'conflicting_terms', $error->get_error_code(), 'Two fields writing one taxonomy: the later would silently win.' );
	}

	public function test_oversized_meta_and_term_lists_are_refused(): void {
		$this->seed( 40, 'post' );

		// Literals, not the constants: a test built from the constant passes
		// for any cap value and pins nothing (round-3 P2-G).
		$meta = array();
		for ( $i = 1; $i <= 101; $i++ ) {
			$meta[ 'k' . $i ] = 'v';
		}
		$this->assertSame( 'too_many_meta_keys', $this->posts()->update_post( 40, array( 'meta' => $meta ) )->get_error_code() );
		unset( $meta['k101'] );
		$this->assertNotInstanceOf( WP_Error::class, $this->posts()->update_post( 40, array( 'meta' => $meta ) ), '100 keys is the cap, not over it.' );

		$terms = array_fill( 0, 101, 1522 );
		$this->assertSame( 'too_many_terms', $this->posts()->update_post( 40, array( 'terms' => array( 'sm_audience' => $terms ) ) )->get_error_code() );
		$this->assertSame( 100, Mcpwp_Post_Fields::MAX_META_KEYS );
		$this->assertSame( 100, Mcpwp_Post_Fields::MAX_TERMS_PER_TAX );
	}

	public function test_the_featured_image_gate_is_the_writable_type_allowlist(): void {
		$this->assertTrue( Mcpwp_Posts::is_writable_post_type( 'post' ) );
		$this->assertTrue( Mcpwp_Posts::is_writable_post_type( 'elementor_library' ) );
		$this->assertFalse( Mcpwp_Posts::is_writable_post_type( 'revision' ) );
		$this->assertFalse( Mcpwp_Posts::is_writable_post_type( 'sm_private' ), 'A type that is not public and not whitelisted is not writable, whatever it claims to support.' );
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
		$GLOBALS['mcpwp_test_public_post_types']  = array( 'sm_team' );
		$GLOBALS['mcpwp_test_post_type_supports'] = array( 'sm_team' => array( 'thumbnail' ) );

		$response = $this->featured( 50, 51 );

		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );
		$this->assertSame( 51, (int) get_post_meta( 50, '_thumbnail_id', true ) );
	}

	public function test_a_blocked_type_is_not_found_even_if_it_claims_thumbnail_support(): void {
		$this->seed( 50, 'revision' );
		$this->seed( 51, 'attachment' );
		$GLOBALS['mcpwp_test_post_type_supports'] = array( 'revision' => array( 'thumbnail' ) );

		$error = $this->featured( 50, 51 );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'not_found', $error->get_error_code() );
	}

	public function test_a_type_without_thumbnail_support_is_told_why(): void {
		$this->seed( 50, 'sm_event' );
		$this->seed( 51, 'attachment' );
		$GLOBALS['mcpwp_test_public_post_types']  = array( 'sm_event' );
		$GLOBALS['mcpwp_test_post_type_supports'] = array();

		$error = $this->featured( 50, 51 );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'thumbnails_unsupported', $error->get_error_code() );
	}

	// ---------------------------------------------------------------
	// Round 3: credential-shaped keys, value bounds, normalised overlap.
	// ---------------------------------------------------------------

	public function test_a_credential_shaped_meta_key_is_refused_on_both_routes(): void {
		// P1-A: post meta is world-readable to every plugin and exported
		// with the post. The old meta route refused these; the new post-write
		// meta field never did, and the meta route lost the check.
		$this->seed( 40, 'post' );
		$GLOBALS['_mcpwp_test_posts'][40] = new WP_Post( (array) $GLOBALS['_mcpwp_test_posts'][40] );

		foreach ( array( 'stripe_secret_key', 'api_token', 'user_password', 'oauth_credential', 'basic_auth', 'smc_api_key', 'PASSWORD' ) as $key ) {
			$via_post = $this->posts()->update_post( 40, array( 'meta' => array( $key => 'sk_live_abcdefghijklmnop' ) ) );
			$this->assertInstanceOf( WP_Error::class, $via_post, $key );
			$this->assertSame( 'forbidden_meta_key', $via_post->get_error_code(), $key );
			$this->assertStringContainsString( 'credential', $via_post->get_error_message(), $key );

			$via_route = $this->set_meta( 40, $key, 'sk_live_abcdefghijklmnop' );
			$this->assertInstanceOf( WP_Error::class, $via_route, $key );
			$this->assertSame( 'forbidden_meta_key', $via_route->get_error_code(), $key );

			$this->assertArrayNotHasKey( $key, $GLOBALS['_mcpwp_test_meta'][40] ?? array(), 'Nothing was written: ' . $key );
		}

		// Ordinary keys that merely resemble one are still fine.
		foreach ( array( 'author_note', 'smc_role', 'smc_page_count' ) as $key ) {
			$this->assertNotInstanceOf( WP_Error::class, $this->posts()->update_post( 40, array( 'meta' => array( $key => 'x' ) ) ), $key );
		}
	}

	public function test_the_read_side_and_the_write_side_share_one_secret_detector(): void {
		$this->assertTrue( Mcpwp_Post_Fields::is_secret_shaped_key( 'stripe_secret_key' ) );
		$this->assertTrue( Mcpwp_Post_Fields::is_secret_shaped_key( 'apiKey' ) );
		$this->assertFalse( Mcpwp_Post_Fields::is_secret_shaped_key( 'smc_role' ) );

		$content = new ReflectionMethod( Mcpwp_REST_Site_Content::class, 'is_blocked_meta_key' );
		$content->setAccessible( true );
		$this->assertTrue( $content->invoke( new Mcpwp_REST_Site_Content(), 'stripe_secret_key' ) );
		$this->assertFalse( $content->invoke( new Mcpwp_REST_Site_Content(), 'smc_role' ) );
	}

	public function test_a_single_oversized_meta_value_is_refused(): void {
		$this->seed( 40, 'post' );

		$big = str_repeat( 'x', 65537 );
		$err = $this->posts()->update_post( 40, array( 'meta' => array( 'smc_blob' => $big ) ) );
		$this->assertSame( 'meta_value_too_large', $err->get_error_code() );

		$nested = array_fill( 0, 20000, 'abcd' );
		$err    = $this->posts()->update_post( 40, array( 'meta' => array( 'smc_list' => $nested ) ) );
		$this->assertSame( 'meta_value_too_large', $err->get_error_code(), 'An array is measured as a whole, not by its top-level count.' );

		$this->assertNotInstanceOf( WP_Error::class, $this->posts()->update_post( 40, array( 'meta' => array( 'smc_blob' => str_repeat( 'x', 60000 ) ) ) ) );
		$this->assertSame( 65536, Mcpwp_Post_Fields::MAX_META_VALUE_BYTES );
	}

	public function test_too_many_taxonomies_in_one_write_is_refused(): void {
		$this->seed( 40, 'post' );

		$terms = array();
		for ( $i = 1; $i <= 21; $i++ ) {
			$terms[ 'tax_' . $i ] = array( 1 );
		}
		$this->assertSame( 'too_many_taxonomies', $this->posts()->update_post( 40, array( 'terms' => $terms ) )->get_error_code() );
		$this->assertSame( 20, Mcpwp_Post_Fields::MAX_TAXONOMIES );
	}

	public function test_the_overlap_check_sees_the_taxonomy_name_the_write_will_use(): void {
		// P2-A: the loop normalises keys with sanitize_key(); the overlap
		// check used the raw key, so terms.Category beside categories passed.
		$this->seed( 40, 'post' );

		foreach ( array( 'Category', 'category.', 'CATEGORY' ) as $spelling ) {
			$err = $this->posts()->update_post( 40, array( 'categories' => array( 1 ), 'terms' => array( $spelling => array( 'news' ) ) ) );
			$this->assertInstanceOf( WP_Error::class, $err, $spelling );
			$this->assertSame( 'conflicting_terms', $err->get_error_code(), $spelling );
		}
	}

	// ---------------------------------------------------------------
	// Round 4: the read side is never looser than before; the detector is
	// asserted in both directions; duplicates and bad bytes are refused.
	// ---------------------------------------------------------------

	public function test_the_read_blocklist_still_covers_every_name_the_old_regex_did(): void {
		$content = new ReflectionMethod( Mcpwp_REST_Site_Content::class, 'is_blocked_meta_key' );
		$content->setAccessible( true );
		$ctl = new Mcpwp_REST_Site_Content();

		// Round-4 P1-837: these were filtered on main and had become readable.
		foreach ( array( 'authorization', 'oauth_refresh', 'oauth_code', 'oauth_state', '_oauth_verifier', 'xauth_key', 'authenticator', 'reauth', 'authorized_by' ) as $key ) {
			$this->assertTrue( $content->invoke( $ctl, $key ), $key . ' must stay blocked on read.' );
			$this->assertTrue( Mcpwp_Post_Fields::is_secret_shaped_key( $key ), $key . ' is refused on write too.' );
		}

		// And a real post is not dumped with them: the no-key listing filters.
		$this->seed( 40, 'post' );
		$GLOBALS['_mcpwp_test_posts'][40] = new WP_Post( (array) $GLOBALS['_mcpwp_test_posts'][40] );
		update_post_meta( 40, 'authorization', 'Bearer abc.def.ghi' );
		update_post_meta( 40, 'smc_role', 'Founder' );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/content/40/meta' );
		$request->set_param( 'id', 40 );
		$listed = ( new Mcpwp_REST_Site_Content() )->get_post_meta_handler( $request );
		$this->assertStringNotContainsString( 'Bearer abc', wp_json_encode( $listed->get_data() ) );
		$this->assertStringContainsString( 'Founder', wp_json_encode( $listed->get_data() ) );
	}

	public function test_the_secret_detector_in_both_directions(): void {
		$secret = array(
			'stripe_secret_key', 'api_token', 'user_password', 'oauth_credential', 'basic_auth', 'apiKey', 'myPwd', 'sessionId', 'session_id',
			'privkey', 'ssh_privkey', 'private_key', 'recovery_codes', 'seed_phrase', 'totp',
			'aws_access_key_id', 'access_key', 'PASSWORD', 'client_secret', 'refresh_token', 'x-auth', 'authorization',
			// Round 5: separators, numeric suffixes, the old substring set.
			'x-api-key', 'X-API-Key', 'api.key', 'api:key', 'access-key', 'session-id', 'apikey2', 'api_key_2', 'stripe_sk', 'secretKey', 'PrivateKey', 'db_pass', 'passwd_hash', 'oauth2_token', 'client-secret',
			'mysecret', 'apitoken', 'token2', 'secret2', 'githubtoken', 'vault_token1', 'stripe_secret2', 'clientsecret',
		);
		foreach ( $secret as $key ) {
			$this->assertTrue( Mcpwp_Post_Fields::is_secret_shaped_key( $key ), $key . ' names a credential.' );
		}

		$ordinary = array(
			'author', 'author_note', 'authors', 'authored_by', 'authoring', 'authority', 'authorship', 'license', 'license_url', 'license_name', 'salt_grams',
			'smc_role', 'smc_page_count', 'pinned', 'pinterest_url', 'session_count', 'nonce_field', 'nonce_key', 'event_pin', 'pin_code', 'signature_image',
			'_yoast_wpseo_title', '_price', 'total_sales', 'rank_math_title',
		);
		foreach ( $ordinary as $key ) {
			$this->assertFalse( Mcpwp_Post_Fields::is_secret_shaped_key( $key ), $key . ' is an ordinary field.' );
		}
	}

	public function test_two_spellings_of_one_taxonomy_in_terms_are_refused(): void {
		$this->seed( 40, 'post' );

		$err = $this->posts()->update_post( 40, array( 'terms' => array( 'Category' => array( 'a' ), 'category' => array( 'b' ), 'category.' => array( 'c' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'conflicting_terms', $err->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_object_terms'][40] ?? array(), 'Nothing was written.' );
	}

	public function test_invalid_utf8_in_a_meta_value_is_refused_not_stored_as_an_empty_string(): void {
		$this->seed( 40, 'post' );

		$err = $this->posts()->update_post( 40, array( 'meta' => array( 'smc_blob' => "\xFF" . str_repeat( 'x', 70000 ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_meta_value', $err->get_error_code() );
		$this->assertArrayNotHasKey( 'smc_blob', $GLOBALS['_mcpwp_test_meta'][40] ?? array() );

		$err = $this->posts()->update_post( 40, array( 'meta' => array( 'smc_list' => array( 'ok', array( 'deep' => "\xC3\x28" ) ) ) ) );
		$this->assertSame( 'invalid_meta_value', $err->get_error_code() );
		$this->assertStringContainsString( '1.deep', $err->get_error_message() );

		// A valid string round-trips byte-identical.
		$this->assertNotInstanceOf( WP_Error::class, $this->posts()->update_post( 40, array( 'meta' => array( 'smc_note' => "Founder & CEO — 東京" ) ) ) );
		$this->assertSame( "Founder & CEO — 東京", get_post_meta( 40, 'smc_note', true ) );
	}

	public function test_meta_and_terms_are_visible_in_the_route_schema(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		( new Mcpwp_REST_Posts() )->register_routes();
		( new Mcpwp_REST_Pages() )->register_routes();

		$seen = 0;
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $route ) {
			if ( ! in_array( $route['route'], array( '/posts', '/pages', '/posts/(?P<id>\d+)', '/pages/(?P<id>\d+)' ), true ) ) {
				continue;
			}
			foreach ( $route['args'] as $handler ) {
				if ( ! isset( $handler['methods'] ) || 'GET' === $handler['methods'] || false !== strpos( (string) $handler['methods'], 'DELETE' ) || ! isset( $handler['args'] ) ) {
					continue;
				}
				$this->assertArrayHasKey( 'meta', $handler['args'], $route['route'] );
				$this->assertArrayHasKey( 'terms', $handler['args'], $route['route'] );
				if ( false !== strpos( $route['route'], 'id' ) ) {
					$this->assertArrayNotHasKey( 'required', $handler['args']['title'], 'An update requires nothing.' );
				}
				$seen++;
			}
		}
		$this->assertSame( 4, $seen );
	}

	// ---------------------------------------------------------------
	// Round 5: the meta route's door for underscore keys; read never looser.
	// ---------------------------------------------------------------

	public function test_the_meta_route_accepts_the_underscore_keys_plugins_actually_use(): void {
		// P1-NEW: _yoast_wpseo_title, _price, _sku, _acf_* were refused as
		// "protected meta" on the very tool that exists to set them.
		$this->seed( 40, 'post' );
		$GLOBALS['_mcpwp_test_posts'][40] = new WP_Post( (array) $GLOBALS['_mcpwp_test_posts'][40] );

		foreach ( array( '_yoast_wpseo_title' => 'Best title', '_yoast_wpseo_metadesc' => 'desc', '_price' => '19.90', '_sku' => 'SKU-1', '_stock' => '4', '_acf_x' => 'y' ) as $key => $value ) {
			$ok = $this->set_meta( 40, $key, $value );
			$this->assertInstanceOf( WP_REST_Response::class, $ok, $key . ': ' . ( is_wp_error( $ok ) ? $ok->get_error_message() : '' ) );
			$this->assertSame( $value, get_post_meta( 40, $key, true ), $key );
		}

		// The reserved ones are still refused on the meta route.
		foreach ( array( '_wp_attached_file', '_edit_lock', '_elementor_data', 'mcpwp_api_key' ) as $key ) {
			$this->assertInstanceOf( WP_Error::class, $this->set_meta( 40, $key, 'x' ), $key );
		}

		// And a post write's meta field keeps the underscore rule (a stray
		// key beside title/content is more likely an accident).
		$err = $this->posts()->update_post( 40, array( 'meta' => array( '_yoast_wpseo_title' => 'x' ) ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertStringContainsString( 'wp_set_post_meta', $err->get_error_message() );
	}

	public function test_the_read_blocklist_covers_the_old_substring_set(): void {
		// P1-837 (round 5): the old regex was pure substring; secret/token
		// at a word boundary only had let mysecret/apitoken through.
		$content = new ReflectionMethod( Mcpwp_REST_Site_Content::class, 'is_blocked_meta_key' );
		$content->setAccessible( true );
		$ctl = new Mcpwp_REST_Site_Content();
		foreach ( array( 'mysecret', 'apitoken', 'token2', 'secret2', 'githubtoken', 'vault_token1', 'stripe_secret2', 'clientsecret', 'x-api-key' ) as $k ) {
			$this->assertTrue( $content->invoke( $ctl, $k ), $k . ' must stay blocked on read.' );
		}
		foreach ( array( 'author', 'author_email', 'co_authors', 'smc_role', '_yoast_wpseo_title' ) as $k ) {
			$this->assertFalse( $content->invoke( $ctl, $k ), $k . ' is readable.' );
		}
	}

	public function test_a_nul_byte_and_a_kses_amplified_value_are_refused(): void {
		$this->seed( 40, 'post' );

		$err = $this->posts()->update_post( 40, array( 'meta' => array( 'smc_note' => "a\0b" ) ) );
		$this->assertSame( 'invalid_meta_value', $err->get_error_code() );

		// Under the bound as sent (20,001 bytes); the size as it would be
		// stored is measured too — the harness's wp_kses_post() entity-encodes
		// '&' as core does, so this grows fivefold past the bound.
		$err = $this->posts()->update_post( 40, array( 'meta' => array( 'smc_blob' => '<' . str_repeat( '&', 20000 ) ) ) );
		$this->assertSame( 'meta_value_too_large', $err->get_error_code() );
		$this->assertGreaterThan( 65536, strlen( wp_kses_post( '<' . str_repeat( '&', 20000 ) ) ), 'Premise: the double amplifies like core.' );
	}

	// ---------------------------------------------------------------
	// Round 6 residuals.
	// ---------------------------------------------------------------

	public function test_the_bound_is_measured_through_the_registered_sanitiser_when_there_is_one(): void {
		$this->seed( 40, 'post' );
		$GLOBALS['_mcpwp_test_registered_meta']['reg_txt']  = array( 'object_subtype' => 'post', 'sanitize_callback' => 'strval' );
		$GLOBALS['_mcpwp_test_registered_meta']['reg_kses'] = array( 'object_subtype' => 'post', 'sanitize_callback' => 'wp_kses_post' );

		// strval stores 20,008 bytes: under the bound, accepted.
		$ok = $this->posts()->update_post( 40, array( 'meta' => array( 'reg_txt' => '<b>x</b>' . str_repeat( '&', 20000 ) ) ) );
		$this->assertNotInstanceOf( WP_Error::class, $ok, is_wp_error( $ok ) ? $ok->get_error_message() : '' );

		// kses stores ~300,000: refused, although as sent it is 60,000.
		$err = $this->posts()->update_post( 40, array( 'meta' => array( 'reg_kses' => str_repeat( '&', 60000 ) ) ) );
		$this->assertSame( 'meta_value_too_large', $err->get_error_code() );
	}

	public function test_internal_keys_stay_closed_on_both_routes_and_case_does_not_open_a_read(): void {
		$this->seed( 40, 'post' );
		$GLOBALS['_mcpwp_test_posts'][40] = new WP_Post( (array) $GLOBALS['_mcpwp_test_posts'][40] );
		foreach ( array( '_menu_item_url', '_menu_item_object_id', '_pingme', '_encloseme', '_transient_x', '_customize_changeset_uuid', '_downloadable_files' ) as $key ) {
			$this->assertInstanceOf( WP_Error::class, $this->set_meta( 40, $key, 'x' ), $key );
		}

		$content = new ReflectionMethod( Mcpwp_REST_Site_Content::class, 'is_blocked_meta_key' );
		$content->setAccessible( true );
		foreach ( array( '_WP_ATTACHED_FILE', '_Wp_old_slug', '_ELEMENTOR_data', '_EDIT_LOCK', '_Menu_Item_url' ) as $key ) {
			$this->assertTrue( $content->invoke( new Mcpwp_REST_Site_Content(), $key ), $key . ' is blocked whatever its case.' );
		}
	}

	public function test_a_nul_byte_in_a_nested_key_is_refused(): void {
		$this->seed( 40, 'post' );
		$err = $this->posts()->update_post( 40, array( 'meta' => array( 'smc_x' => array( "a\0b" => 'v' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $err );
		$this->assertSame( 'invalid_meta_value', $err->get_error_code() );
		$err = $this->posts()->update_post( 40, array( 'meta' => array( "smc\0y" => 'v' ) ) );
		$this->assertSame( 'invalid_meta_key', $err->get_error_code() );
	}

	public function test_the_key_family_of_credential_names_is_detected(): void {
		foreach ( array( 'license_key', 'licence_key', 'consumer_key', 'encryption_key', 'master_key', 'signing_key', 'kms_key', 'ssh_key', 'gpg_key', 'htpasswd', 'keystore', 'account_sid', 'activation_code', 'p12', 'pem', 'consumer_secret' ) as $key ) {
			$this->assertTrue( Mcpwp_Post_Fields::is_secret_shaped_key( $key ), $key );
		}
		foreach ( array( 'meta_key', 'cache_key', 'sort_key', 'key_visual', 'pemberton', 'keystone_id' ) as $key ) {
			$this->assertFalse( Mcpwp_Post_Fields::is_secret_shaped_key( $key ), $key );
		}
	}
}
