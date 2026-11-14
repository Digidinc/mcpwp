<?php
/**
 * #989 (from #804): wp_fetch says it takes "a public URL", but this site's own
 * archive and REST URLs came back "Content not found". These tests pin the
 * fix:
 *
 * - an archive URL (category, tag, custom taxonomy, author, search, date,
 *   blog index) returns the archive and a page of its PUBLISHED posts;
 * - a /wp-json/ or ?rest_route= URL is dispatched internally as a logged-out
 *   visitor, outside any MCP/batch sub-request, and MCPWP's own namespace is
 *   refused;
 * - URL query vars never reach WP_Query directly, so a URL cannot list drafts.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// The controller under test is not loaded by the shared bootstrap.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

// Core's two credential checks on rest_authentication_errors, as they behave
// for the request wp_fetch runs inside: the cookie check fails a browser
// session's nonce once the user is 0, and the application-password check
// answers true for an outer request that authenticated with one.
if ( ! function_exists( 'rest_cookie_check_errors' ) ) {
	function rest_cookie_check_errors( $result ) {
		return new WP_Error( 'rest_cookie_invalid_nonce', 'Cookie check failed', array( 'status' => 403 ) );
	}
}
if ( ! function_exists( 'rest_application_password_check_errors' ) ) {
	function rest_application_password_check_errors( $result ) {
		return true;
	}
}

final class WpFetchSiteUrlsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']              = array();
		$GLOBALS['mcpwp_test_transients']           = array();
		$GLOBALS['mcpwp_test_current_user']         = 0;
		$GLOBALS['mcpwp_test_is_admin']             = false;
		$GLOBALS['_mcpwp_test_registered_routes']   = array();
		$GLOBALS['_mcpwp_test_posts']               = array();
		$GLOBALS['_mcpwp_test_meta']                = array();
		$GLOBALS['mcpwp_test_dispatched_routes']    = array();
		$GLOBALS['mcpwp_test_dispatched_requests']  = array();
		$GLOBALS['mcpwp_test_url_to_postid']        = array();
		$GLOBALS['_mcpwp_test_taxonomies']          = array(
			'category' => array(
				'public'             => true,
				'publicly_queryable' => true,
				'query_var'          => 'category_name',
				'object_type'        => array( 'post' ),
				'terms'              => array( 5 => array( 'slug' => 'blog', 'name' => 'Blog' ) ),
			),
			'genre'    => array(
				'public'             => true,
				'publicly_queryable' => true,
				'query_var'          => 'genre',
				'object_type'        => array( 'post' ),
				'terms'              => array( 9 => array( 'slug' => 'jazz', 'name' => 'Jazz' ) ),
			),
			'internal' => array(
				'public'             => false,
				'publicly_queryable' => false,
				'query_var'          => 'internal',
				'terms'              => array( 3 => array( 'slug' => 'secret', 'name' => 'Secret' ) ),
			),
		);

		$GLOBALS['wp_rewrite'] = new class() {
			public $use_verbose_page_rules = true;

			public function wp_rewrite_rules() {
				return array(
					// A verbose page rule first, as on a /%postname%/ site:
					// it must not swallow archive paths.
					'(.?.+?)(?:/([0-9]+))?/?$'                    => 'index.php?pagename=$matches[1]&page=$matches[2]',
					'category/(.+?)/page/?([0-9]{1,})/?$'          => 'index.php?category_name=$matches[1]&paged=$matches[2]',
					'category/(.+?)/?$'                            => 'index.php?category_name=$matches[1]',
					'genre/([^/]+)/?$'                             => 'index.php?genre=$matches[1]',
				);
			}
		};

		$this->seed_post( 11, 'Published one', 'publish' );
		$this->seed_post( 12, 'A draft', 'draft' );
		$this->seed_post( 13, 'Locked', 'publish', array( 'post_password' => 'pw' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
		unset( $GLOBALS['wp_rewrite'] );
		$GLOBALS['_mcpwp_test_taxonomies'] = array();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
		unset( $GLOBALS['mcpwp_test_home_url'] );
		$GLOBALS['mcpwp_test_public_post_types'] = array();
	}

	private function seed_post( int $id, string $title, string $status, array $extra = array() ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
			array_merge(
				array(
					'ID'          => $id,
					'post_type'   => 'post',
					'post_title'  => $title,
					'post_name'   => sanitize_title( $title ),
					'post_status' => $status,
				),
				$extra
			)
		);
	}

	private function fetch( array $params, array $categories = array( 'content' ), array $scopes = array( 'read' ) ) {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $controller->create_scoped_api_key( 'test key', $scopes, 'custom', $categories );

		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/fetch', $params, array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $request ) );

		return $controller->fetch_content( $request );
	}

	private function data( $response ): array {
		$this->assertInstanceOf( WP_REST_Response::class, $response, is_wp_error( $response ) ? $response->get_error_message() : '' );

		return $response->get_data();
	}

	// ── Archives ─────────────────────────────────────────────────────────

	/**
	 * The #804 acceptance criterion: wp_fetch("/category/blog/") returns the
	 * archive.
	 */
	public function test_category_archive_url_returns_the_archive_with_published_posts_only(): void {
		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/category/blog/' ) ) );

		$this->assertSame( 'archive', $data['resource'] );
		$this->assertSame( 'category', $data['archive_type'] );
		$this->assertSame( 'blog', $data['term']['slug'] );
		$this->assertSame( 5, $data['term']['id'] );
		$this->assertSame( 'Blog', $data['title'] );

		$ids = array_column( $data['items'], 'id' );
		$this->assertSame( array( 11 ), $ids, 'Only the published, unprotected post is listed: never the draft, never the password-protected one.' );
		$this->assertArrayNotHasKey( 'content', $data['items'][0], 'Archive items carry no body.' );
	}

	public function test_paged_category_archive_reports_the_page(): void {
		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/category/blog/page/2/' ) ) );

		$this->assertSame( 'category', $data['archive_type'] );
		$this->assertSame( 2, $data['pagination']['page'] );
	}

	public function test_plain_permalink_category_archive(): void {
		unset( $GLOBALS['wp_rewrite'] );

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/?cat=5' ) ) );

		$this->assertSame( 'category', $data['archive_type'] );
		$this->assertSame( 'blog', $data['term']['slug'] );
	}

	public function test_custom_taxonomy_archive(): void {
		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/genre/jazz/' ) ) );

		$this->assertSame( 'taxonomy', $data['archive_type'] );
		$this->assertSame( 'genre', $data['term']['taxonomy'] );
	}

	/**
	 * WordPress ignores the query var of a taxonomy that is not publicly
	 * queryable, so the URL is the blog index, and the term is never named.
	 */
	public function test_non_queryable_taxonomy_var_is_ignored_like_wordpress_does(): void {
		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/?internal=secret' ) ) );

		$this->assertSame( 'home', $data['archive_type'] );
		$this->assertArrayNotHasKey( 'term', $data );

		$this->assertNull(
			Mcpwp_Site_Url_Resolver::classify( array( 'taxonomy' => 'internal', 'term' => 'secret' ), '' ),
			'Named directly, a non-queryable taxonomy is not an archive.'
		);
	}

	public function test_unknown_term_is_not_found(): void {
		$response = $this->fetch( array( 'url' => 'https://example.com/category/nope/' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'not_found', $response->get_error_code() );
	}

	public function test_search_url_returns_search_results(): void {
		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/?s=Published' ) ) );

		$this->assertSame( 'search', $data['archive_type'] );
		$this->assertSame( 'Published', $data['search'] );
		$this->assertSame( array( 11 ), array_column( $data['items'], 'id' ) );
	}

	public function test_author_without_published_posts_is_not_found(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();

		$response = $this->fetch( array( 'url' => 'https://example.com/?author=2' ) );

		$this->assertInstanceOf( WP_Error::class, $response, 'An author archive must not become a way to read user names by id.' );
		$this->assertSame( 'not_found', $response->get_error_code() );
	}

	public function test_site_root_is_the_blog_index_when_it_lists_posts(): void {
		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/' ) ) );

		$this->assertSame( 'home', $data['archive_type'] );
	}

	public function test_posts_page_url_is_answered_as_the_blog_index(): void {
		$this->seed_post( 40, 'News', 'publish', array( 'post_type' => 'page' ) );
		$GLOBALS['mcpwp_test_options']['show_on_front']  = 'page';
		$GLOBALS['mcpwp_test_options']['page_for_posts'] = 40;
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/news/'] = 40;

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/news/' ) ) );

		$this->assertSame( 'archive', $data['resource'] );
		$this->assertSame( 'home', $data['archive_type'] );
		$this->assertSame( 40, $data['posts_page_id'] );
		$this->assertStringContainsString( 'post=40', $data['edit_url'] );
	}

	public function test_url_query_vars_never_reach_the_listing_query(): void {
		$parsed = Mcpwp_Site_Url_Resolver::query_vars( 'https://example.com/?cat=5&post_status=draft&perm=readable&posts_per_page=999' );

		$this->assertSame( array( 'cat' => '5' ), $parsed['vars'], 'Only public query vars are read from a URL.' );

		$archive = Mcpwp_Site_Url_Resolver::classify( $parsed['vars'], $parsed['path'] );
		$args    = Mcpwp_Site_Url_Resolver::listing_args( $archive, 999 );

		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertFalse( $args['has_password'] );
		$this->assertSame( Mcpwp_Site_Url_Resolver::MAX_PER_PAGE, $args['posts_per_page'] );
		$this->assertSame( 5, $args['tax_query'][0]['terms'] );
	}

	public function test_verbose_page_rule_still_resolves_a_real_page(): void {
		$this->seed_post( 50, 'About', 'publish', array( 'post_type' => 'page' ) );

		$vars = Mcpwp_Site_Url_Resolver::match_rewrite( 'about', $GLOBALS['wp_rewrite']->wp_rewrite_rules(), true );

		$this->assertSame( 'about', $vars['pagename'] );
		$this->assertNull( Mcpwp_Site_Url_Resolver::classify( $vars, 'about' ), 'A page path is not an archive.' );
	}

	public function test_archive_url_with_an_explicit_post_type_stays_not_found(): void {
		$response = $this->fetch( array( 'url' => 'https://example.com/category/blog/', 'type' => 'post' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
	}

	public function test_another_sites_archive_url_is_not_resolved(): void {
		$response = $this->fetch( array( 'url' => 'https://elsewhere.example.org/category/blog/' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'not_found', $response->get_error_code() );
	}

	// ── REST URLs ───────────────────────────────────────────────────────

	private function capture_dispatch( array $answer = array( 'ok' => true ) ): void {
		$GLOBALS['mcpwp_test_dispatch_seen'] = array();
		add_filter(
			'rest_dispatch_request',
			static function ( $result, $request ) use ( $answer ) {
				$GLOBALS['mcpwp_test_dispatch_seen'][] = array(
					'route' => $request->get_route(),
					'query' => $request->get_query_params(),
					'user'  => get_current_user_id(),
					'batch' => Mcpwp_REST_Batch::$is_batch_sub_request,
				);
				$response = new WP_REST_Response( $answer, 200 );
				$response->header( 'X-WP-Total', 7 );
				$response->header( 'X-WP-TotalPages', 4 );
				return $response;
			},
			10,
			2
		);
	}

	public function test_rest_url_is_dispatched_as_a_logged_out_visitor(): void {
		$this->capture_dispatch( array( array( 'id' => 11 ) ) );
		$GLOBALS['mcpwp_test_current_user']     = 1;
		Mcpwp_REST_Batch::$is_batch_sub_request = true; // As inside /mcp.

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/posts?per_page=2' ) ) );

		$this->assertSame( 'rest', $data['resource'] );
		$this->assertSame( '/wp/v2/posts', $data['route'] );
		$this->assertSame( 200, $data['status_code'] );
		$this->assertSame( array( array( 'id' => 11 ) ), $data['data'] );
		$this->assertSame( 7, $data['total'] );
		$this->assertSame( 4, $data['total_pages'] );

		$seen = $GLOBALS['mcpwp_test_dispatch_seen'];
		$this->assertCount( 1, $seen );
		$this->assertSame( array( 'per_page' => '2' ), $seen[0]['query'] );
		$this->assertSame( 0, $seen[0]['user'], 'The REST request runs with no user, so no key privilege applies.' );
		$this->assertFalse( $seen[0]['batch'], 'The REST request must not run as an authenticated MCP sub-request.' );

		$this->assertTrue( Mcpwp_REST_Batch::$is_batch_sub_request, 'The sub-request flag is restored.' );
	}

	public function test_rest_route_query_form_is_dispatched(): void {
		$this->capture_dispatch();

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/?rest_route=/wp/v2/pages/' ) ) );

		$this->assertSame( '/wp/v2/pages', $data['route'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatch_seen'][0]['query'] );
	}

	public function test_own_namespace_is_refused_without_dispatch(): void {
		$this->capture_dispatch();

		foreach ( array( 'https://example.com/wp-json/mcpwp/v1/api-keys', 'https://example.com/?rest_route=/MCPWP/v1/settings' ) as $url ) {
			$response = $this->fetch( array( 'url' => $url ) );

			$this->assertInstanceOf( WP_Error::class, $response, $url );
			$this->assertSame( 'own_namespace', $response->get_error_code() );
		}

		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatch_seen'] );
	}

	public function test_another_sites_rest_url_is_not_dispatched(): void {
		$this->capture_dispatch();

		$response = $this->fetch( array( 'url' => 'https://elsewhere.example.org/wp-json/wp/v2/posts' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatch_seen'] );
	}

	public function test_oversized_rest_response_is_refused_with_a_hint(): void {
		$this->capture_dispatch( array( 'blob' => str_repeat( 'x', Mcpwp_REST_Site_Content::RENDERED_HTML_MAX_BYTES_CEILING + 10 ) ) );

		$response = $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/posts' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'response_too_large', $response->get_error_code() );
	}

	public function test_post_url_still_returns_the_post(): void {
		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/?p=11' ) ) );

		$this->assertSame( 11, $data['id'] );
		$this->assertArrayNotHasKey( 'resource', $data );
	}

	// ── Adversarial review round 1 (#989) ───────────────────────────────

	/**
	 * rest_do_request() skips serve_request(), where core applies
	 * rest_authentication_errors. A site that refuses anonymous REST there
	 * must refuse wp_fetch too.
	 */
	public function test_site_refusing_anonymous_rest_refuses_the_fetch(): void {
		$this->capture_dispatch( array( array( 'id' => 1, 'name' => 'admin' ) ) );
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$seen_user                          = null;
		add_filter(
			'rest_authentication_errors',
			static function ( $result ) use ( &$seen_user ) {
				$seen_user = get_current_user_id();
				return new WP_Error( 'rest_login_required', 'REST is for logged-in users only.', array( 'status' => 401 ) );
			}
		);

		$response = $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/users' ), array( 'content', 'admin' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'rest_visitor_refused', $response->get_error_code() );
		$this->assertSame( 401, $response->get_error_data()['status'] );
		$this->assertStringContainsString( 'logged-in users only', $response->get_error_message() );
		$this->assertSame( 0, $seen_user, 'The filter is asked about the logged-out visitor, not this key.' );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatch_seen'], 'A refused request is never dispatched.' );
		$this->assertNotSame( 0, get_current_user_id(), 'The request user is restored after the visitor check.' );
	}

	public function test_rest_authentication_filter_that_allows_lets_the_fetch_through(): void {
		$this->capture_dispatch();
		add_filter(
			'rest_authentication_errors',
			static function ( $result ) {
				return $result;
			}
		);

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/posts' ) ) );

		$this->assertSame( 'rest', $data['resource'] );
	}

	/**
	 * The #1020 per-post-type category rule holds on the REST door too.
	 */
	public function test_rest_route_for_a_gated_post_type_is_refused(): void {
		$this->capture_dispatch();
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product' );

		foreach ( array( 'https://example.com/wp-json/wc/store/v1/products', 'https://example.com/wp-json/wp/v2/product/5', 'https://example.com/wp-json/wp/v2/search?search=x' ) as $url ) {
			$response = $this->fetch( array( 'url' => $url ) );

			$this->assertInstanceOf( WP_Error::class, $response, $url );
			$this->assertSame( 'forbidden_category', $response->get_error_code(), $url );
		}

		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatch_seen'] );

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/wp-json/wc/store/v1/products' ), array( 'content', 'woocommerce' ) ) );
		$this->assertSame( 'rest', $data['resource'], 'A key holding the category gets the route.' );
	}

	public function test_rest_route_for_elementor_templates_needs_their_category(): void {
		$this->capture_dispatch();

		$response = $this->fetch( array( 'url' => 'https://example.com/wp-json/elementor/v1/globals' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'forbidden_category', $response->get_error_code() );
	}

	/**
	 * A posts page this key cannot read is not turned into the blog index,
	 * and its id and title are never emitted.
	 */
	public function test_unreadable_posts_page_is_not_found_and_not_named(): void {
		$this->seed_post( 41, 'Secret News', 'draft', array( 'post_type' => 'page' ) );
		$GLOBALS['mcpwp_test_options']['show_on_front']  = 'page';
		$GLOBALS['mcpwp_test_options']['page_for_posts'] = 41;
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/secret-news/'] = 41;

		$response = $this->fetch( array( 'url' => 'https://example.com/secret-news/' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'not_found', $response->get_error_code() );
	}

	public function test_blog_index_does_not_name_an_unreadable_posts_page(): void {
		$this->seed_post( 41, 'Secret News', 'draft', array( 'post_type' => 'page' ) );
		$GLOBALS['mcpwp_test_options']['page_for_posts'] = 41;

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/' ) ) );

		$this->assertSame( 'home', $data['archive_type'] );
		$this->assertArrayNotHasKey( 'posts_page_id', $data );
		$this->assertArrayNotHasKey( 'edit_url', $data );
		$this->assertStringNotContainsString( 'Secret', wp_json_encode( $data ) );
	}

	/**
	 * Subdirectory multisite: /site2/ owns only paths under /site2/.
	 */
	public function test_subdirectory_site_owns_only_its_own_paths(): void {
		$GLOBALS['mcpwp_test_home_url'] = 'https://example.com/site2';

		$this->assertTrue( Mcpwp_REST_Site_Content::is_same_site_origin( 'https://example.com/site2/' ) );
		$this->assertTrue( Mcpwp_REST_Site_Content::is_same_site_origin( 'https://example.com/site2' ) );
		$this->assertTrue( Mcpwp_REST_Site_Content::is_same_site_origin( 'https://example.com/site2/category/blog/' ) );
		$this->assertFalse( Mcpwp_REST_Site_Content::is_same_site_origin( 'https://example.com/' ) );
		$this->assertFalse( Mcpwp_REST_Site_Content::is_same_site_origin( 'https://example.com/wp-json/wp/v2/users' ) );
		$this->assertFalse( Mcpwp_REST_Site_Content::is_same_site_origin( 'https://example.com/site3/' ) );
		$this->assertFalse( Mcpwp_REST_Site_Content::is_same_site_origin( 'https://example.com/site22/' ) );
		$this->assertFalse( Mcpwp_REST_Site_Content::is_same_site_origin( 'https://example.com/site2/../site3/' ) );
		$this->assertFalse( Mcpwp_REST_Site_Content::is_same_site_origin( 'https://example.com/site2/%2e%2e/site3/' ) );

		$this->capture_dispatch();
		$response = $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/users' ) );
		$this->assertInstanceOf( WP_Error::class, $response, 'The network root\'s REST API is another site.' );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatch_seen'] );

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/site2/wp-json/wp/v2/posts' ) ) );
		$this->assertSame( '/wp/v2/posts', $data['route'] );
	}

	// ── Delta review (#989) ─────────────────────────────────────────────

	/**
	 * A lock that decides by URL must see the TARGET route, not the outer
	 * /mcp request: allowing /wp-json/mcpwp/ must not let /wp/v2/users out.
	 */
	public function test_url_based_lock_judges_the_target_route_not_the_outer_request(): void {
		$this->capture_dispatch();
		$_SERVER['REQUEST_URI']    = '/wp-json/mcpwp/v1/mcp';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$GLOBALS['wp']             = (object) array( 'query_vars' => array( 'rest_route' => '/mcpwp/v1/mcp' ) );
		$seen                      = array();
		add_filter(
			'rest_authentication_errors',
			static function ( $result ) use ( &$seen ) {
				$seen = array(
					'uri'        => $_SERVER['REQUEST_URI'],
					'method'     => $_SERVER['REQUEST_METHOD'],
					'rest_route' => $GLOBALS['wp']->query_vars['rest_route'],
				);
				if ( false !== strpos( (string) $_SERVER['REQUEST_URI'], '/mcpwp/' ) ) {
					return $result;
				}
				return new WP_Error( 'rest_disabled', 'REST is off for visitors.', array( 'status' => 401 ) );
			}
		);

		$response = $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/users?per_page=5' ), array( 'content', 'admin' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'rest_visitor_refused', $response->get_error_code() );
		$this->assertSame(
			array(
				'uri'        => '/wp-json/wp/v2/users?per_page=5',
				'method'     => 'GET',
				'rest_route' => '/wp/v2/users',
			),
			$seen
		);
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatch_seen'] );

		// Restored afterwards.
		$this->assertSame( '/wp-json/mcpwp/v1/mcp', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'POST', $_SERVER['REQUEST_METHOD'] );
		$this->assertSame( '/mcpwp/v1/mcp', $GLOBALS['wp']->query_vars['rest_route'] );

		unset( $GLOBALS['wp'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
	}

	/**
	 * Core's cookie and application-password checks judge the OUTER
	 * request's credentials. Neither is the visitor's: they are detached
	 * for the visitor check and put back afterwards.
	 */
	public function test_core_credential_checks_are_detached_for_the_visitor_and_restored(): void {
		$this->capture_dispatch();
		add_filter( 'rest_authentication_errors', 'rest_application_password_check_errors', 90 );
		add_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', 100 );
		$site_lock_ran = false;
		add_filter(
			'rest_authentication_errors',
			static function ( $result ) use ( &$site_lock_ran ) {
				$site_lock_ran = true;
				return $result;
			},
			200
		);

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/posts' ) ) );

		$this->assertSame( 'rest', $data['resource'], 'A browser session is not refused by its own nonce check.' );
		$this->assertTrue( $site_lock_ran, 'The site\'s own lock still runs.' );
		$this->assertSame( 90, has_filter( 'rest_authentication_errors', 'rest_application_password_check_errors' ) );
		$this->assertSame( 100, has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ) );
	}

	public function test_users_route_needs_the_admin_category(): void {
		$this->capture_dispatch();

		$denied = $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/users' ) );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'forbidden_category', $denied->get_error_code() );
		$this->assertSame( 'admin', $denied->get_error_data()['category'] );

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/users' ), array( 'content', 'admin' ) ) );
		$this->assertSame( 'rest', $data['resource'] );
	}

	public function test_comments_route_is_judged_by_the_post_it_belongs_to(): void {
		$this->capture_dispatch();
		$this->seed_post( 70, 'Widget', 'publish', array( 'post_type' => 'product' ) );
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product' );

		$on_post = $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/comments?post=11' ) );
		$this->assertSame( 'rest', $this->data( $on_post )['resource'] );

		$on_product = $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/comments?post=70' ) );
		$this->assertInstanceOf( WP_Error::class, $on_product );
		$this->assertSame( 'woocommerce', $on_product->get_error_data()['category'] );

		$all = $this->fetch( array( 'url' => 'https://example.com/wp-json/wp/v2/comments' ) );
		$this->assertInstanceOf( WP_Error::class, $all, 'Comments across every type need every type\'s category.' );
	}

	public function test_oembed_is_judged_by_the_post_it_embeds(): void {
		$this->capture_dispatch();
		$this->seed_post( 70, 'Widget', 'publish', array( 'post_type' => 'product' ) );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/product/widget/'] = 70;

		$product = $this->fetch( array( 'url' => 'https://example.com/wp-json/oembed/1.0/embed?url=' . rawurlencode( 'https://example.com/product/widget/' ) ) );
		$this->assertInstanceOf( WP_Error::class, $product );
		$this->assertSame( 'woocommerce', $product->get_error_data()['category'] );

		$post = $this->fetch( array( 'url' => 'https://example.com/wp-json/oembed/1.0/embed?url=' . rawurlencode( 'https://example.com/?p=11' ) ) );
		$this->assertSame( 'rest', $this->data( $post )['resource'] );

		$proxy = $this->fetch( array( 'url' => 'https://example.com/wp-json/oembed/1.0/proxy?url=x' ) );
		$this->assertInstanceOf( WP_Error::class, $proxy );
	}

	/**
	 * Fail closed: a namespace or wp/v2 route the map does not know needs
	 * the admin category.
	 */
	public function test_unknown_routes_need_the_admin_category(): void {
		$this->capture_dispatch();

		foreach ( array( '/wp-json/acme-forms/v1/entries', '/wp-json/wp/v2/settings', '/wp-json/wp/v2/plugins', '/wp-json/wp-site-health/v1/tests/background-updates' ) as $path ) {
			$response = $this->fetch( array( 'url' => 'https://example.com' . $path ) );

			$this->assertInstanceOf( WP_Error::class, $response, $path );
			$this->assertSame( 'admin', $response->get_error_data()['category'], $path );
		}

		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatch_seen'] );

		$data = $this->data( $this->fetch( array( 'url' => 'https://example.com/wp-json/acme-forms/v1/entries' ), array( 'content', 'admin' ) ) );
		$this->assertSame( 'rest', $data['resource'] );
	}

	public function test_route_index_and_type_listings_are_content(): void {
		$this->capture_dispatch();

		foreach ( array( '/wp-json/', '/wp-json/wp/v2', '/wp-json/wp/v2/types', '/wp-json/wp/v2/categories' ) as $path ) {
			$this->assertSame( 'rest', $this->data( $this->fetch( array( 'url' => 'https://example.com' . $path ) ) )['resource'], $path );
		}
	}

	// ── Round-3 review (#989) ───────────────────────────────────────────

	/**
	 * A set_current_user callback that throws while switching to the visitor
	 * must not leave the request URI, query globals, rest_route, the
	 * sub-request flag, the user or core's credential checks changed.
	 */
	public function test_a_throw_while_entering_the_visitor_request_restores_everything(): void {
		$this->capture_dispatch();
		$_SERVER['REQUEST_URI']                 = '/wp-json/mcpwp/v1/mcp';
		$_SERVER['REQUEST_METHOD']              = 'POST';
		$_GET                                   = array( 'outer' => '1' );
		$_REQUEST                               = array( 'outer' => '1', 'body' => 'x' );
		$GLOBALS['wp']                          = (object) array( 'query_vars' => array( 'rest_route' => '/mcpwp/v1/mcp' ) );
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		add_filter( 'rest_authentication_errors', 'rest_cookie_check_errors', 100 );

		$controller = new Mcpwp_REST_Site_Content();
		$created    = $controller->create_scoped_api_key( 'test key', array( 'read' ), 'custom', array( 'content' ) );
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/fetch', array( 'url' => 'https://example.com/wp-json/wp/v2/posts?per_page=3' ), array( 'X-API-Key' => $created['key'] ) );
		$this->assertTrue( $controller->verify_api_key( $request ) );
		$key_user = get_current_user_id();

		$thrown = 0;
		add_action(
			'set_current_user',
			static function () use ( &$thrown ) {
				if ( 0 === get_current_user_id() && 0 === $thrown ) {
					++$thrown;
					throw new RuntimeException( 'set_current_user callback exploded' );
				}
			}
		);

		try {
			$controller->fetch_content( $request );
			$this->fail( 'The callback\'s exception should propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'set_current_user callback exploded', $e->getMessage() );
		} finally {
			unset( $GLOBALS['mcpwp_test_actions']['set_current_user'] );
		}

		$this->assertSame( '/wp-json/mcpwp/v1/mcp', $_SERVER['REQUEST_URI'] );
		$this->assertSame( 'POST', $_SERVER['REQUEST_METHOD'] );
		$this->assertSame( array( 'outer' => '1' ), $_GET );
		$this->assertSame( array( 'outer' => '1', 'body' => 'x' ), $_REQUEST );
		$this->assertSame( '/mcpwp/v1/mcp', $GLOBALS['wp']->query_vars['rest_route'] );
		$this->assertTrue( Mcpwp_REST_Batch::$is_batch_sub_request );
		$this->assertSame( $key_user, get_current_user_id() );
		$this->assertSame( 100, has_filter( 'rest_authentication_errors', 'rest_cookie_check_errors' ) );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatch_seen'] );

		unset( $GLOBALS['wp'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
		$_GET     = array();
		$_REQUEST = array();
	}

	/**
	 * $_GET and $_REQUEST carry the TARGET query while the lock and the
	 * dispatch run, and are put back after.
	 */
	public function test_query_globals_are_the_target_query_during_the_visitor_request(): void {
		$_GET     = array( 'outer' => '1' );
		$_REQUEST = array( 'outer' => '1', 'body' => 'x' );
		$seen     = array();
		add_filter(
			'rest_authentication_errors',
			static function ( $result ) use ( &$seen ) {
				$seen['auth'] = array( $_GET, $_REQUEST );
				return $result;
			}
		);
		add_filter(
			'rest_dispatch_request',
			static function ( $result, $request ) use ( &$seen ) {
				$seen['dispatch'] = array( $_GET, $_REQUEST );
				return new WP_REST_Response( array(), 200 );
			},
			10,
			2
		);

		$this->data( $this->fetch( array( 'url' => 'https://example.com/?rest_route=/wp/v2/posts&per_page=3' ) ) );

		$target = array( 'rest_route' => '/wp/v2/posts', 'per_page' => '3' );
		$this->assertSame( array( $target, $target ), $seen['auth'] );
		$this->assertSame( array( $target, $target ), $seen['dispatch'] );
		$this->assertSame( array( 'outer' => '1' ), $_GET );
		$this->assertSame( array( 'outer' => '1', 'body' => 'x' ), $_REQUEST );

		$_GET     = array();
		$_REQUEST = array();
	}

	/**
	 * An embed URL that does not resolve to a post on this site may still
	 * be answered (an oembed_request_post_id filter, another network site),
	 * so it needs admin.
	 */
	public function test_oembed_of_an_unresolved_url_needs_admin(): void {
		$this->capture_dispatch();
		$url = 'https://example.com/wp-json/oembed/1.0/embed?url=' . rawurlencode( 'https://example.com/elsewhere/thing/' );

		$denied = $this->fetch( array( 'url' => $url ) );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 'admin', $denied->get_error_data()['category'] );

		$this->assertSame( 'rest', $this->data( $this->fetch( array( 'url' => $url ), array( 'content', 'admin' ) ) )['resource'] );
	}
}
