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

	private function fetch( array $params ) {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $controller->create_scoped_api_key( 'test key', array( 'read' ), 'custom', array( 'content' ) );

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
}
