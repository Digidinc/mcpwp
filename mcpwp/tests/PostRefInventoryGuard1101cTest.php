<?php
/**
 * #1101 PR-3c — guard: every post reference a user-run principal can send
 * is either declared in the per-post map or explicitly marked not-a-post.
 *
 * Two-way pin in the style of RouteCapabilityMapTest:
 *
 *  1. SWEEP → MAP. For every registered route/method whose capability row an
 *     Editor (or any lesser role) can satisfy, the id-like parameter names
 *     the route can read — its regex groups, its declared args, and every
 *     get_param('x') / $request['x'] read in the callback body — must each
 *     be declared by Mcpwp_REST_API::get_post_ref_param_map() for that path,
 *     sit under a get_non_post_id_route_prefixes() route, or be listed in
 *     NOT_A_POST with a reason.
 *  2. MAP → SWEEP. Every NOT_A_POST entry is still found by the sweep (no
 *     stale reasons), and every top-level spec the map declares for a
 *     reachable path is a name the sweep finds there (no typo'd, dead spec).
 *  3. NESTED keys — ids inside a body array or item list (featured_image,
 *     parent …) — are not reachable by an automatic sweep of the route
 *     layer: the handlers read them out of $data / $item arrays in core
 *     classes. They are pinned as the REVIEWED list NESTED, in both
 *     directions against a sweep of those classes' array reads: a new
 *     id-like key read there fails until it is classified, and every
 *     declared nested key must be in the map.
 *
 * A new route reachable by a user-run principal that reads an id-like name
 * fails here until it is declared or classified.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-core.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-design-references.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-webhooks.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-screenshot.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-figma.php';

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-openapi.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-settings.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-taxonomy.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-custom-css.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-updates.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-library.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-network.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-cache.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-graph.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-graph.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-seo-audit.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-seo-market.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-ai-presence.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-quality.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-media.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-menus.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-webhooks.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-screenshot.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-cloudflare.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-feedback.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-blocks.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-action-log.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-memory.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-signals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-blueprints.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-mcp.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-oauth.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-broker.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-integrations.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-figma.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-webmcp.php';

require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-seo.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-forms.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-site-manager.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-users.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-widgets.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-themes.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-multilang.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-google-indexing.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-learnpress.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-events.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-staging.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-staging.php';

require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-free-tools.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-pro-tools.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-route-capability-map.php';


final class PostRefInventoryGuard1101cTest extends TestCase {

	/** WordPress core's Editor role, as RouteCapabilityMapTest::EDITOR_CAPS. */
	const EDITOR_CAPS = array(
		'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts',
		'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts',
		'read_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages',
		'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages',
		'edit_private_pages', 'read_private_pages', 'manage_categories', 'manage_links', 'moderate_comments',
		'upload_files', 'unfiltered_html',
	);

	/** An id-like parameter name. */
	const ID_LIKE = '/^(id|ids|[a-z_]*_id|[a-z_]*_ids|[a-z][A-Za-z]*Ids?|parent|post_parent|[a-z_]*_parent|featured_image|featured_media|thumbnail|attachment|attachments|image|images|gallery|media|source|target|template|post|page|object_id|menu_item_parent)$/';

	/**
	 * Reviewed: id-like names on reachable routes that do not name a post.
	 * Key "METHOD /pattern" => [ name => reason ].
	 */
	const NOT_A_POST = array(
		'GET /search'                                        => array( 'page' => 'pagination (1-based page number)' ),
		'GET /posts'                                         => array(
			'page' => 'pagination (1-based page number)',
			'ids'  => 'list filter (post__in): the list is clamped to published content for a user-run principal (requester_can_see_non_public()), so it can only narrow what the caller may already read',
		),
		'GET /pages'                                         => array(
			'page' => 'pagination (1-based page number)',
			'ids'  => 'list filter (post__in): the list is clamped to published content for a user-run principal (requester_can_see_non_public()), so it can only narrow what the caller may already read',
		),
		'POST /pages'                                        => array( 'template' => 'a page template FILE name (_wp_page_template), not a post id' ),
		'GET /media'                                         => array( 'page' => 'pagination (1-based page number)' ),
		'GET /elementor/(?P<id>\\d+)'                         => array( 'element_id' => 'an Elementor element id inside the page named by {id}, not a post' ),
		'POST /elementor/(?P<id>\\d+)'                        => array( 'repair_ids' => 'a boolean flag (rewrite non-conforming Elementor element ids)' ),
		'POST /elementor/(?P<id>\\d+)/edit-section'           => array( 'element_id' => 'an Elementor element id inside the page named by {id}, not a post' ),
		'POST /elementor/(?P<id>\\d+)/remove-section'         => array( 'element_id' => 'an Elementor element id inside the page named by {id}, not a post' ),
		'POST /elementor/(?P<id>\\d+)/replace-section'        => array( 'element_id' => 'an Elementor element id inside the page named by {id}, not a post' ),
		'POST /elementor/(?P<id>\\d+)/patch'                  => array( 'repair_ids' => 'a boolean flag (rewrite non-conforming Elementor element ids)' ),
		'POST /elementor/(?P<id>\\d+)/edit-widget'            => array( 'widget_id' => 'an Elementor element id inside the page named by {id}, not a post' ),
		'POST /elementor/save-section-as-template'           => array( 'element_id' => 'an Elementor element id inside page_id (page_id itself is declared @read)' ),
		'POST /broker/enroll-store'                          => array( 'site_id' => 'the broker\'s site identifier, not a WordPress object' ),
		'POST /webmcp/call'                                  => array( 'request_id' => 'a JSON-RPC request correlation id' ),
	);

	/**
	 * Reviewed nested keys: [ file, key ] => class ('write' | 'read' |
	 * 'read-user' | 'not-a-post: reason').
	 */
	const NESTED = array(
		'includes/core/class-mcpwp-pages.php featured_image'          => 'read-user',
		'includes/core/class-mcpwp-pages.php parent'                  => 'read-user',
		'includes/core/class-mcpwp-pages.php post_parent'             => 'not-a-post: the wp_insert_post() array this class BUILDS from $data[parent] (output, not input)',
		'includes/core/class-mcpwp-pages.php template'                => 'not-a-post: a page template file name',
		'includes/core/class-mcpwp-posts.php featured_image'          => 'read-user',
		'includes/pro/core/class-mcpwp-elementor-pro.php page'              => 'not-a-post: pagination of the template list',
		'includes/pro/core/class-mcpwp-elementor-pro.php parent'            => 'read',   // /elementor/clone parent.
		'includes/pro/core/class-mcpwp-elementor-pro.php source_element_id' => 'not-a-post: copied OUT of a part\'s own meta into the response (output)',
		'includes/pro/core/class-mcpwp-elementor-pro.php source_page_id'    => 'not-a-post: copied OUT of a part\'s own meta into the response (output)',
		'includes/pro/core/class-mcpwp-elementor-pro.php template_id'       => 'source', // /elementor/landing-page template_id.
		'includes/traits/trait-mcpwp-sanitization.php featured_image' => 'read-user',
		'includes/traits/trait-mcpwp-sanitization.php id'             => 'write',
		'includes/traits/trait-mcpwp-sanitization.php ids'            => 'not-a-post: list filter (post__in), clamped to published for user-run principals',
		'includes/traits/trait-mcpwp-sanitization.php page'           => 'not-a-post: pagination',
		'includes/traits/trait-mcpwp-sanitization.php parent'         => 'read-user',
		'includes/traits/trait-mcpwp-sanitization.php template'       => 'not-a-post: a page template file name',
	);

	/** @var \ReflectionProperty */
	private $wh_prop;
	/** @var mixed */
	private $wh_saved;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$ref           = new ReflectionClass( 'Mcpwp_Webhooks' );
		$this->wh_prop = $ref->getProperty( 'instance' );
		$this->wh_prop->setAccessible( true );
		$this->wh_saved = $this->wh_prop->getValue();
		$this->wh_prop->setValue( null, $ref->newInstanceWithoutConstructor() );
	}

	protected function tearDown(): void {
		$this->wh_prop->setValue( null, $this->wh_saved );
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_is_multisite']       = false;
	}

	private function register_every_known_route(): void {
		$GLOBALS['mcpwp_test_is_multisite'] = true;
		( new Mcpwp_REST_OpenAPI() )->register_routes();
		( new Mcpwp_REST_Site_Info() )->register_routes();
		( new Mcpwp_REST_Site_Settings() )->register_routes();
		( new Mcpwp_REST_Site_Content() )->register_routes();
		( new Mcpwp_REST_Site_Taxonomy() )->register_routes();
		( new Mcpwp_REST_Site_Custom_Css() )->register_routes();
		( new Mcpwp_REST_Site_Updates() )->register_routes();
		( new Mcpwp_REST_Site_Library() )->register_routes();
		( new Mcpwp_REST_Site_Network() )->register_routes();
		( new Mcpwp_REST_Site_Cache() )->register_routes();
		( new Mcpwp_REST_Content_Graph() )->register_routes();
		( new Mcpwp_REST_Graph() )->register_routes();
		( new Mcpwp_REST_SEO_Audit() )->register_routes();
		( new Mcpwp_REST_SEO_Market() )->register_routes();
		( new Mcpwp_REST_AI_Presence() )->register_routes();
		( new Mcpwp_REST_Content_Quality() )->register_routes();
		( new Mcpwp_REST_Posts() )->register_routes();
		( new Mcpwp_REST_Pages() )->register_routes();
		( new Mcpwp_REST_Media() )->register_routes();
		( new Mcpwp_REST_Menus() )->register_routes();
		( new Mcpwp_REST_Content() )->register_routes();
		( new Mcpwp_REST_Elementor() )->register_routes();
		( new Mcpwp_REST_Webhooks() )->register_routes();
		( new Mcpwp_REST_Screenshot() )->register_routes();
		( new Mcpwp_REST_Cloudflare() )->register_routes();
		( new Mcpwp_REST_Feedback() )->register_routes();
		( new Mcpwp_REST_Blocks() )->register_routes();
		( new Mcpwp_REST_Approvals() )->register_routes();
		( new Mcpwp_REST_Action_Log() )->register_routes();
		( new Mcpwp_REST_Site_Memory() )->register_routes();
		( new Mcpwp_REST_Signals() )->register_routes();
		( new Mcpwp_REST_Site_Blueprints() )->register_routes();
		( new Mcpwp_REST_MCP() )->register_routes();
		( new Mcpwp_REST_OAuth() )->register_routes();
		( new Mcpwp_REST_Broker() )->register_routes();
		( new Mcpwp_REST_Batch() )->register_routes();
		( new Mcpwp_REST_Integrations() )->register_routes();
		( new Mcpwp_REST_Figma() )->register_routes();
		( new Mcpwp_REST_WebMCP() )->register_routes();

		// Pro — registered unconditionally (see method docblock).
		( new Mcpwp_REST_Elementor_Pro( null ) )->register_routes();
		( new Mcpwp_REST_SEO( null ) )->register_routes();
		( new Mcpwp_REST_Forms( null ) )->register_routes();
		( new Mcpwp_REST_Site_Manager( null ) )->register_routes();
		( new Mcpwp_REST_Theme_Builder( null ) )->register_routes();
		( new Mcpwp_REST_Users( null ) )->register_routes();
		( new Mcpwp_REST_Widgets( null ) )->register_routes();
		( new Mcpwp_REST_Themes( null ) )->register_routes();
		( new Mcpwp_REST_WooCommerce( null ) )->register_routes();
		( new Mcpwp_REST_Multilang( null ) )->register_routes();
		( new Mcpwp_REST_Google_Indexing( null ) )->register_routes();
		( new Mcpwp_REST_LearnPress( null ) )->register_routes();
		( new Mcpwp_REST_Events( null ) )->register_routes();
		( new Mcpwp_REST_Staging( new Mcpwp_Staging() ) )->register_routes();

		$GLOBALS['mcpwp_test_is_multisite'] = false;
	}

	/** Rows a user-run principal holding at most Editor capabilities can pass. */
	private static function reachable_rows(): array {
		$out = array();
		foreach ( Mcpwp_Route_Capability_Map::rows() as $key => $row ) {
			$cap = (string) $row[1];
			if ( 'TODO' === $cap || 0 === strpos( $cap, 'forms:' ) ) {
				continue;
			}
			if ( 'none' === $cap || array() === array_diff( explode( '+', $cap ), self::EDITOR_CAPS ) ) {
				$out[ $key ] = $row;
			}
		}
		return $out;
	}

	/** The source text of a callable's body. */
	private static function body_of( $callback ): string {
		if ( ! is_array( $callback ) || 2 !== count( $callback ) ) {
			return '';
		}
		$m     = new ReflectionMethod( $callback[0], $callback[1] );
		$lines = file( $m->getFileName() );
		return implode( '', array_slice( $lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1 ) );
	}

	/** Every name the sweep finds per "METHOD /pattern", id-like only. */
	private function sweep(): array {
		$this->register_every_known_route();
		$found = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $r ) {
			if ( 'mcpwp/v1' !== $r['namespace'] ) {
				continue;
			}
			$args     = $r['args'];
			$handlers = isset( $args['methods'] ) || isset( $args['callback'] ) ? array( $args ) : $args;
			preg_match_all( '/\(\?P<(\w+)>/', $r['route'], $groups );
			foreach ( $handlers as $h ) {
				if ( ! is_array( $h ) || ! isset( $h['methods'] ) ) {
					continue;
				}
				$names = $groups[1];
				foreach ( array_keys( isset( $h['args'] ) && is_array( $h['args'] ) ? $h['args'] : array() ) as $a ) {
					$names[] = (string) $a;
				}
				$body = self::body_of( $h['callback'] ?? null );
				// get_param('x'), $request['x'], get_json_params()['x'] /
				// get_body_params()['x'], and $params/$body/$json/$input['x']
				// (a callback's local copy of the request's params, #1118 P2).
				preg_match_all( '/get_param\(\s*[\'"](\w+)[\'"]\s*\)|\$request\[\s*[\'"](\w+)[\'"]\s*\]|get_(?:json|body)_params\(\)\s*\[\s*[\'"](\w+)[\'"]\s*\]|\$(?:params|body|json|input)\[\s*[\'"](\w+)[\'"]\s*\]/', $body, $reads );
				foreach ( array_merge( $reads[1], $reads[2], $reads[3], $reads[4] ) as $n ) {
					if ( '' !== $n ) {
						$names[] = $n;
					}
				}
				$names = array_values( array_unique( array_filter( $names, static function ( $n ) {
					return 1 === preg_match( self::ID_LIKE, $n );
				} ) ) );
				foreach ( is_array( $h['methods'] ) ? $h['methods'] : explode( ',', (string) $h['methods'] ) as $m ) {
					$m   = strtoupper( trim( $m ) );
					$m   = 'PATCH' === $m ? 'PUT' : ( 'HEAD' === $m ? 'GET' : $m );
					$key = $m . ' ' . $r['route'];
					if ( ! isset( $found[ $key ] ) ) { // WP routes to the first registration.
						$found[ $key ] = $names;
					}
				}
			}
		}
		return $found;
	}

	/** A concrete path WordPress would route to the pattern. */
	private static function concrete( string $pattern ): string {
		$path = preg_replace_callback(
			'/\(\?P<(\w+)>([^()]*)\)/',
			static function ( $m ) {
				foreach ( array( '1', 'abc', 'abc-def' ) as $sample ) {
					if ( preg_match( '@^(?:' . $m[2] . ')$@', $sample ) ) {
						return $sample;
					}
				}
				return '1';
			},
			$pattern
		);
		do {
			$prev = $path;
			$path = preg_replace( '/\([^()]*\)/', '1', $path );
		} while ( $prev !== $path );
		return str_replace( array( '?', '^', '$' ), '', $path );
	}

	/** Top-level names the map declares for a concrete path. */
	private static function declared_top_names( string $path ): array {
		$out = array();
		foreach ( Mcpwp_REST_API::post_ref_specs_for_path( $path ) as $spec ) {
			list( $base ) = Mcpwp_REST_API::post_ref_spec_parts( $spec );
			$out[]        = preg_split( '/\[|:/', $base )[0];
		}
		return array_values( array_unique( $out ) );
	}

	private static function under_non_post_prefix( string $path ): bool {
		foreach ( Mcpwp_REST_API::get_non_post_id_route_prefixes() as $prefix ) {
			if ( $path === $prefix || 0 === strpos( $path, rtrim( $prefix, '/' ) . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	// ── 1. sweep → map ────────────────────────────────────────────────────

	public function test_every_id_like_name_on_a_reachable_route_is_declared_or_classified(): void {
		$sweep   = $this->sweep();
		$missing = array();
		foreach ( self::reachable_rows() as $key => $row ) {
			$this->assertArrayHasKey( $key, $sweep, "$key: reachable row with no registered handler — register its controller in this test." );
			list( , $pattern ) = explode( ' ', $key, 2 );
			$path              = self::concrete( $pattern );
			$declared          = self::declared_top_names( $path );
			foreach ( $sweep[ $key ] as $name ) {
				if ( in_array( $name, $declared, true ) || self::under_non_post_prefix( $path ) || isset( self::NOT_A_POST[ $key ][ $name ] ) ) {
					continue;
				}
				$missing[] = sprintf( "%s → '%s'", $key, $name );
			}
		}
		$this->assertSame( array(), $missing, "Undeclared id-like names on reachable routes. Declare each in get_post_ref_param_map() (WRITE, '@read' or '@read-user') or add it to NOT_A_POST with a reason:\n" . implode( "\n", $missing ) );
	}

	// ── 2. map → sweep ────────────────────────────────────────────────────

	public function test_every_not_a_post_entry_is_still_found_by_the_sweep(): void {
		$sweep = $this->sweep();
		$reach = self::reachable_rows();
		$stale = array();
		foreach ( self::NOT_A_POST as $key => $names ) {
			foreach ( $names as $name => $reason ) {
				$this->assertNotSame( '', trim( (string) $reason ), "$key '$name' needs a reason." );
				if ( ! isset( $reach[ $key ], $sweep[ $key ] ) || ! in_array( $name, $sweep[ $key ], true ) ) {
					$stale[] = "$key → '$name'";
				}
			}
		}
		$this->assertSame( array(), $stale, "Stale NOT_A_POST entries:\n" . implode( "\n", $stale ) );
	}

	/**
	 * Every top-level spec a map key declares is a name some reachable route
	 * governed by that key reads — directly (the sweep) or, for handlers
	 * that pass the whole body to Mcpwp_Posts / Mcpwp_Pages, as a reviewed
	 * NESTED key. A key whose routes are all admin-only is out of scope.
	 */
	public function test_every_declared_top_level_spec_on_a_reachable_route_is_a_name_the_route_reads(): void {
		$sweep  = $this->sweep();
		$nested = array();
		foreach ( self::NESTED as $entry => $class ) {
			if ( 0 !== strpos( $class, 'not-a-post' ) ) {
				$nested[] = explode( ' ', $entry, 2 )[1];
			}
		}
		$names_by_key = array();
		foreach ( self::reachable_rows() as $key => $row ) {
			list( , $pattern ) = explode( ' ', $key, 2 );
			$map_key = Mcpwp_REST_API::post_ref_map_key_for_path( self::concrete( $pattern ) );
			if ( null !== $map_key ) {
				$names_by_key[ $map_key ] = array_merge( $names_by_key[ $map_key ] ?? array(), $sweep[ $key ] ?? array(), $nested );
			}
		}
		$map  = Mcpwp_REST_API::get_post_ref_param_map();
		$dead = array();
		foreach ( $names_by_key as $map_key => $names ) {
			foreach ( $map[ $map_key ] as $spec ) {
				list( $base ) = Mcpwp_REST_API::post_ref_spec_parts( $spec );
				$top          = preg_split( '/\\[|:/', $base )[0];
				if ( ! in_array( $top, $names, true ) && ! in_array( $top, self::CONTAINERS, true ) ) {
					$dead[] = "$map_key → '$spec'";
				}
			}
		}
		$this->assertSame( array(), $dead, "Declared specs no reachable handler under that key reads:\n" . implode( "\n", $dead ) );
	}

	/** Body containers whose ITEMS carry ids (declared as 'name[].key'). */
	const CONTAINERS = array( 'updates', 'posts', 'pages', 'items', 'post_ids' );

	/** The nested keys that name posts are declared somewhere in the map. */
	public function test_every_nested_post_key_is_declared_in_the_map(): void {
		$declared = array();
		foreach ( Mcpwp_REST_API::get_post_ref_param_map() as $specs ) {
			foreach ( $specs as $spec ) {
				list( $base, $class ) = Mcpwp_REST_API::post_ref_spec_parts( $spec );
				$parts                = preg_split( '/\\[\\]\\.|:/', $base );
				$declared[ end( $parts ) . '|' . $class ] = true;
			}
		}
		foreach ( self::NESTED as $entry => $class ) {
			if ( 0 === strpos( $class, 'not-a-post' ) ) {
				continue;
			}
			$key = explode( ' ', $entry, 2 )[1];
			$this->assertArrayHasKey( $key . '|' . $class, $declared, "$entry is classified $class but no map spec declares '$key' with that class." );
		}
	}

	/**
	 * DEFERRED, not swept: POST /post-meta/{id} {key, value} — for an id-meta
	 * key (_thumbnail_id, _product_image_gallery, _upsell_ids …) `value`
	 * names posts. Declaring it was cut from PR-3c (#1118 scope cut: case-
	 * variant keys and JSON-object values bypassed it); it gets its own PR
	 * with key normalisation and the handler's own decoder. `value` is not
	 * id-like, so the sweep cannot see it; this pins that the map still
	 * declares only the path id there, exactly as on main.
	 */
	public function test_post_meta_value_refs_are_deferred_and_the_route_is_as_on_main(): void {
		$this->assertSame( array( 'id' ), Mcpwp_REST_API::post_ref_specs_for_path( '/post-meta/1' ) );
		$this->assertFalse( method_exists( 'Mcpwp_REST_API', 'get_post_ref_meta_value_keys' ), 'The cut code is gone, not dormant.' );
	}

	// ── 3. nested keys: the reviewed list ─────────────────────────────────

	/** Core files whose array reads carry the body's nested ids. */
	const NESTED_FILES = array(
		'includes/core/class-mcpwp-posts.php',
		'includes/core/class-mcpwp-pages.php',
		'includes/traits/trait-mcpwp-sanitization.php',
		// #1118 P2: the other cores whose handlers take a whole args array.
		'includes/core/class-mcpwp-media.php',
		'includes/core/class-mcpwp-post-fields.php',
		'includes/pro/core/class-mcpwp-elementor-pro.php',
	);

	private static function nested_sweep(): array {
		$out = array();
		foreach ( self::NESTED_FILES as $file ) {
			$src = (string) file_get_contents( dirname( __DIR__ ) . '/' . $file );
			preg_match_all( '/\$(?:data|args|item|update|params|post_data|page_data|input)\[\s*\'(\w+)\'\s*\]/', $src, $m );
			foreach ( array_unique( $m[1] ) as $k ) {
				if ( preg_match( self::ID_LIKE, $k ) ) {
					$out[] = $file . ' ' . $k;
				}
			}
		}
		sort( $out );
		return $out;
	}

	public function test_nested_id_keys_read_by_the_core_handlers_are_the_reviewed_list(): void {
		$found = self::nested_sweep();
		$pinned = array_keys( self::NESTED );
		sort( $pinned );
		$this->assertSame( $pinned, $found, 'A nested id-like key read by Mcpwp_Posts / Mcpwp_Pages / sanitization changed. Classify it in NESTED (and declare it in the map if it names a post).' );
	}
}
