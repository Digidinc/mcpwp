<?php
/**
 * #1100 Pass A — the pinned route capability map.
 *
 * Mcpwp_Route_Capability_Map is ONE row per registered mcpwp/v1 route and
 * method with two columns, scope and capability.  This test pins it in both
 * directions — every registered route/method has a row, every row is
 * registered — so a new route without a decision fails CI, and pins the
 * scope column to the live scope gate so the two can never disagree.  It
 * then drives the capability column through the chokepoint
 * (check_user_run_capability()) for an Editor acting as a user, an
 * administrator acting as a user, and a bot-run admin API key.
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

/** Exposes the scope gate, the predicate and the capability chokepoint. */
class Mcpwp_Route_Capability_Map_Harness {
	use Mcpwp_Api_Auth {
		get_required_scope_for_request as public;
		get_required_scope_for_tool_name as public;
		check_user_run_capability as public;
		requester_acts_as_user as public;
	}

	/** @param array|null $record Principal record. */
	public function act_as( $record ) {
		$this->current_api_key_record = $record;
	}
}

/** The chokepoint as a build or load order that never loaded the map class would see it (#1108). */
class Mcpwp_Route_Capability_Map_Absent_Harness extends Mcpwp_Route_Capability_Map_Harness {
	protected function route_capability_map_available() {
		return false;
	}
}

final class RouteCapabilityMapTest extends TestCase {

	/** WordPress core's Editor role (populate_roles()), as far as this map cares. */
	const EDITOR_CAPS = array(
		'read', 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts',
		'delete_others_posts', 'delete_published_posts', 'delete_private_posts', 'edit_private_posts',
		'read_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages',
		'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_private_pages',
		'edit_private_pages', 'read_private_pages', 'manage_categories', 'manage_links', 'moderate_comments',
		'upload_files', 'unfiltered_html',
	);

	/** Core's Contributor and Subscriber roles (populate_roles()). */
	const CONTRIBUTOR_CAPS = array( 'read', 'edit_posts', 'delete_posts' );
	const SUBSCRIBER_CAPS  = array( 'read' );

	const EDITOR      = 7;
	const ADMIN       = 1;
	const CONTRIBUTOR = 8;
	const SUBSCRIBER  = 9;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_user_caps']          = array(
			self::EDITOR => array_fill_keys( self::EDITOR_CAPS, true ),
			self::ADMIN  => array( 'manage_options' => true ),
			self::CONTRIBUTOR => array_fill_keys( self::CONTRIBUTOR_CAPS, true ),
			self::SUBSCRIBER  => array_fill_keys( self::SUBSCRIBER_CAPS, true ),
		);

		// Mcpwp_Webhooks::get_instance() creates a DB table as a side effect
		// of its private constructor; the shared $wpdb test double doesn't
		// support that call chain. Save/restore the singleton around this
		// test only, seeded via reflection (newInstanceWithoutConstructor) —
		// register_routes() never touches the instance's data, only its
		// existence.
		$ref            = new ReflectionClass( 'Mcpwp_Webhooks' );
		$this->wh_prop  = $ref->getProperty( 'instance' );
		$this->wh_prop->setAccessible( true );
		$this->wh_saved = $this->wh_prop->getValue();
		$this->wh_prop->setValue( null, $ref->newInstanceWithoutConstructor() );
	}

	protected function tearDown(): void {
		$this->wh_prop->setValue( null, $this->wh_saved );
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_user_caps']          = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_multisite']       = false;
	}

	/** @var \ReflectionProperty */
	private $wh_prop;
	/** @var mixed */
	private $wh_saved;

	/**
	 * Collapse a WP REST regex route pattern to a concrete, classifiable path.
	 *
	 * get_category_for_route() only ever inspects the STATIC portion of a
	 * path via strpos() prefix matching (plus one regex tier for
	 * path-parameter routes, see get_route_pattern_map()) — it never needs a
	 * real numeric id. Every capture group in this plugin's own routes is a
	 * single, non-alternating-across-groups id/slug/type segment (verified:
	 * no route registers nested alternation spanning multiple groups), so
	 * collapsing each parenthesised group to the literal '1' — repeatedly,
	 * to unwind any nested optional group — reproduces a real request path
	 * closely enough to classify correctly.
	 */
	private function concrete_path( string $route ): string {
		$path = $route;
		do {
			$prev = $path;
			$path = preg_replace( '/\([^()]*\)/', '1', $path );
		} while ( $prev !== $path );
		return str_replace( array( '?', '^', '$' ), '', $path );
	}

	/**
	 * Register every REST controller this plugin ships, Free and Pro,
	 * unconditionally — mirroring class-mcpwp-loader.php's
	 * register_rest_routes() and class-mcpwp-pro-bootstrap.php's
	 * register_routes(), but calling each controller directly instead of
	 * going through Mcpwp_Loader (whose constructor wires unrelated
	 * admin/locale hooks this test has no need of) or the
	 * Mcpwp_License::is_pro() gate (irrelevant here: the point is every
	 * route the CODE can register, not just the ones a particular license
	 * state would activate).
	 *
	 * @return array<string,string> route pattern => concrete classifiable path.
	 */
	private function register_every_known_route(): array {
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

		$out = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $r ) {
			if ( 'mcpwp/v1' !== $r['namespace'] ) {
				continue;
			}
			$route = $r['route'];
			if ( isset( $out[ $route ] ) ) {
				continue;
			}
			$out[ $route ] = $this->concrete_path( '/mcpwp/v1' . $route );
		}
		return $out;
	}

	/** Registered route/method keys, normalised the way the map resolves them. */
	private function registered_keys(): array {
		$GLOBALS['mcpwp_test_is_multisite'] = true; // the /network routes register only on multisite.
		$this->register_every_known_route();
		$GLOBALS['mcpwp_test_is_multisite'] = false;
		$keys = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $r ) {
			if ( 'mcpwp/v1' !== $r['namespace'] ) {
				continue;
			}
			$args     = $r['args'];
			$handlers = isset( $args['methods'] ) || isset( $args['callback'] ) ? array( $args ) : $args;
			foreach ( $handlers as $h ) {
				if ( ! is_array( $h ) || ! isset( $h['methods'] ) ) {
					continue;
				}
				foreach ( is_array( $h['methods'] ) ? $h['methods'] : explode( ',', (string) $h['methods'] ) as $m ) {
					$m = strtoupper( trim( $m ) );
					$m = 'PATCH' === $m ? 'PUT' : ( 'HEAD' === $m ? 'GET' : $m );
					$keys[ $m . ' ' . $r['route'] ] = true;
				}
			}
		}
		return array_keys( $keys );
	}

	/**
	 * A concrete path for a row: each named group becomes the first sample
	 * value its own regex accepts (an id, a slug, a plugin name), so the
	 * path is one WordPress itself would route to that pattern.
	 */
	private function concrete_for( string $key ): array {
		list( $method, $pattern ) = explode( ' ', $key, 2 );
		$path = preg_replace_callback(
			'/\(\?P<(\w+)>([^()]*)\)/',
			static function ( $m ) {
				foreach ( array( '1', 'abc', 'gravityforms', 'abc-def' ) as $sample ) {
					if ( preg_match( '@^(?:' . $m[2] . ')$@', $sample ) ) {
						return $sample;
					}
				}
				return '1';
			},
			$pattern
		);
		return array( $method, $this->concrete_path( '/mcpwp/v1' . $path ) );
	}

	/**
	 * Rows WordPress itself never routes to, because an EARLIER registered
	 * pattern already matches the same path (WP_REST_Server matches in
	 * registration order). Pinned so a new shadowing is a visible diff; each
	 * one resolves to a row with the same capability, so enforcement is the
	 * same either way.
	 */
	const SHADOWED = array(
		// Registered twice with equivalent patterns ([\d]+ vs \d+): the first wins.
		'DELETE /menus/(?P<id>[\d]+)',
		'POST /menus/(?P<menu_id>[\d]+)/items',
		'POST /menus/(?P<menu_id>[\d]+)/items/(?P<item_id>[\d]+)',
		'PUT /menus/(?P<menu_id>[\d]+)/items/(?P<item_id>[\d]+)',
		'DELETE /menus/(?P<menu_id>[\d]+)/items/(?P<item_id>[\d]+)',
		'POST /pages/(?P<id>[\d]+)/template',
		// GET /site-blueprints/(?P<id>[a-z0-9_-]+) is registered first and
		// also matches "extract" — reported as a routing bug, not fixed here.
		'GET /site-blueprints/extract',
	);

	// ── Both directions ────────────────────────────────────────────────────

	public function test_every_registered_route_has_a_row(): void {
		$rows    = Mcpwp_Route_Capability_Map::rows();
		$missing = array_values( array_diff( $this->registered_keys(), array_keys( $rows ) ) );
		$this->assertGreaterThan( 400, count( $rows ), 'Premise: the map is the whole plugin.' );
		$this->assertSame( array(), $missing, "Registered routes with no capability decision (add a row, TODO if undecided):\n" . implode( "\n", $missing ) );
	}

	public function test_every_row_is_registered(): void {
		$stale = array_values( array_diff( array_keys( Mcpwp_Route_Capability_Map::rows() ), $this->registered_keys() ) );
		$this->assertSame( array(), $stale, "Map rows for routes that are not registered:\n" . implode( "\n", $stale ) );
	}

	public function test_scope_column_matches_the_live_scope_gate(): void {
		$auth     = new Mcpwp_Route_Capability_Map_Harness();
		$mismatch = array();
		foreach ( Mcpwp_Route_Capability_Map::rows() as $key => $row ) {
			list( $method, $concrete ) = $this->concrete_for( $key );
			$live = $auth->get_required_scope_for_request( new WP_REST_Request( $method, $concrete, array() ) );
			if ( $live !== $row[0] ) {
				$mismatch[] = "$key: map says {$row[0]}, gate says $live";
			}
		}
		$this->assertSame( array(), $mismatch );
	}

	public function test_each_row_resolves_to_itself(): void {
		$wrong    = array();
		$shadowed = array();
		foreach ( array_keys( Mcpwp_Route_Capability_Map::rows() ) as $key ) {
			list( $method, $concrete ) = $this->concrete_for( $key );
			$row = Mcpwp_Route_Capability_Map::resolve( $method, $concrete );
			if ( in_array( $key, self::SHADOWED, true ) ) {
				$own = Mcpwp_Route_Capability_Map::rows()[ $key ];
				$this->assertNotNull( $row, $key );
				$this->assertSame( $own[1], $row['capability'], "$key is shadowed by {$row['key']}, which must carry the same capability" );
				$shadowed[] = $key;
				continue;
			}
			if ( null === $row || $row['key'] !== $key ) {
				$wrong[] = "$key -> " . ( null === $row ? 'nothing' : $row['key'] );
			}
		}
		$this->assertSame( array(), $wrong );
		$expected = self::SHADOWED;
		sort( $expected );
		sort( $shadowed );
		$this->assertSame( $expected, $shadowed );
	}

	public function test_patch_and_head_resolve_through_put_and_get(): void {
		$this->assertSame( 'PUT /posts/(?P<id>\d+)', Mcpwp_Route_Capability_Map::resolve( 'PATCH', '/mcpwp/v1/posts/5' )['key'] );
		$this->assertSame( 'GET /posts', Mcpwp_Route_Capability_Map::resolve( 'HEAD', '/mcpwp/v1/posts' )['key'] );
	}

	public function test_every_capability_value_is_well_formed_and_every_row_has_a_reason(): void {
		foreach ( Mcpwp_Route_Capability_Map::rows() as $key => $row ) {
			$this->assertMatchesRegularExpression( '/^(none|TODO|forms:(forms|entries)|[a-z_]+(\+[a-z_]+)*)$/', $row[1], $key );
			$this->assertContains( $row[0], array( 'read', 'write', 'admin' ), $key );
			$this->assertNotSame( '', trim( $row[2] ), "$key needs a reason" );
		}
	}

	public function test_every_tool_resolves_to_a_row(): void {
		$tool_rows = Mcpwp_Route_Capability_Map::tool_rows();
		$rows      = Mcpwp_Route_Capability_Map::rows();
		$orphans   = array();
		foreach ( array_merge( ( new Mcpwp_MCP_Free_Tools() )->get_tool_map(), ( new Mcpwp_MCP_Pro_Tools() )->get_tool_map() ) as $name => $def ) {
			if ( isset( $tool_rows[ $name ] ) ) {
				foreach ( $tool_rows[ $name ] as $key ) {
					if ( ! isset( $rows[ $key ] ) ) {
						$orphans[] = "$name -> $key (no such row)";
					}
				}
				continue;
			}
			if ( isset( $def['rest_path'] ) ) {
				continue; // dispatches to another namespace; core routes carry their own capability checks.
			}
			$route = preg_replace_callback(
				'/\{([^}]+)\}/',
				static function ( $m ) {
					return 'plugin' === $m[1] ? 'gravityforms' : ( preg_match( '/id$/', $m[1] ) ? '1' : 'abc' );
				},
				(string) ( $def['route'] ?? '' )
			);
			if ( null === Mcpwp_Route_Capability_Map::resolve( (string) ( $def['method'] ?? 'GET' ), $route ) ) {
				$orphans[] = "$name {$def['method']} {$def['route']}";
			}
		}
		$this->assertSame( array(), $orphans );
		$this->assertSame( array( 'wp_get_translations', 'wp_create_translation', 'wp_network_sites', 'wp_network_switch', 'wp_network_stats' ), array_keys( $tool_rows ) );
	}

	/**
	 * Every decision is deliberate: changing any row's scope or capability
	 * changes this digest and these counts, so the same commit must update
	 * both — the diff then shows which class grew or shrank. (The per-row
	 * data-provider tests below only prove the chokepoint ENFORCES the map;
	 * this pin is what stops the map itself from changing silently.)
	 */
	public function test_the_decisions_are_pinned(): void {
		$lines  = array();
		$counts = array();
		foreach ( Mcpwp_Route_Capability_Map::rows() as $key => $row ) {
			$lines[]            = $key . '|' . $row[0] . '|' . $row[1];
			$counts[ $row[1] ] = ( $counts[ $row[1] ] ?? 0 ) + 1;
		}
		sort( $lines );
		ksort( $counts );
		$this->assertSame(
			array(
				'TODO'                 => 155,
				'delete_others_posts+delete_others_pages' => 1,
				'delete_pages'         => 1,
				'delete_posts'         => 1,
				'edit_pages'           => 12,
				'edit_posts'           => 28,
				'edit_products'        => 12,
				'edit_theme_options'   => 29,
				'forms:entries'        => 1,
				'forms:forms'          => 4,
				'manage_categories'    => 4,
				'manage_options'       => 138,
				'manage_product_terms' => 3,
				'manage_woocommerce'   => 7,
				'none'                 => 39,
				'read'                 => 7,
				'upload_files'         => 8,
			),
			$counts
		);
		$this->assertSame( 'cfdb1ddd52fc1cb83cfb9bb403175aa4b1bb797719a56e0e6e58ddf870552b40', hash( 'sha256', implode( "\n", $lines ) ) );
	}

	/**
	 * #1103 round 2 (c): rows whose patterns overlap — one path that both
	 * would route — must agree on scope AND capability, so whichever one
	 * WordPress picks, the decision is the same. Pinned: the overlapping
	 * pairs present today (all agree; none had to be resolved).
	 */
	public function test_overlapping_rows_agree_on_scope_and_capability(): void {
		$rows     = Mcpwp_Route_Capability_Map::rows();
		$pairs    = array();
		$disagree = array();
		foreach ( array_keys( $rows ) as $a ) {
			list( $method, $concrete ) = $this->concrete_for( $a );
			$path = (string) substr( $concrete, strlen( '/mcpwp/v1' ) );
			foreach ( array_keys( $rows ) as $b ) {
				if ( $a === $b || 0 !== strpos( $b, $method . ' ' ) ) {
					continue;
				}
				if ( ! preg_match( '@^' . explode( ' ', $b, 2 )[1] . '$@i', $path ) ) {
					continue;
				}
				$pair = array( $a, $b );
				sort( $pair );
				$pairs[ implode( ' <> ', $pair ) ] = true;
				if ( $rows[ $a ][0] !== $rows[ $b ][0] || $rows[ $a ][1] !== $rows[ $b ][1] ) {
					$disagree[] = "$a {$rows[$a][0]}/{$rows[$a][1]} vs $b {$rows[$b][0]}/{$rows[$b][1]}";
				}
			}
		}
		$this->assertSame( array(), $disagree );
		$this->assertCount( 9, $pairs, implode( "\n", array_keys( $pairs ) ) );
	}

	// ── The predicate ──────────────────────────────────────────────────────

	public function test_acts_as_user_predicate(): void {
		$auth = new Mcpwp_Route_Capability_Map_Harness();

		$auth->act_as( array( 'id' => 'oauth-user:7', 'type' => 'oauth', 'scopes' => array( 'read', 'write' ), 'acts_as_user' => self::EDITOR ) );
		$this->assertTrue( $auth->requester_acts_as_user(), 'OAuth token minted for a user' );

		$auth->act_as( array( 'id' => 'oauth-client:m', 'type' => 'oauth', 'scopes' => array( 'read' ) ) );
		$this->assertFalse( $auth->requester_acts_as_user(), 'client_credentials token runs as the service account' );

		$auth->act_as( array( 'id' => 'k1', 'type' => 'scoped', 'scopes' => array( 'read', 'write', 'admin' ), 'role' => 'admin' ) );
		$this->assertFalse( $auth->requester_acts_as_user(), 'API key runs as the service account' );

		$auth->act_as( array( 'id' => 'broker:7', 'type' => 'broker', 'scopes' => array( 'read', 'write' ) ) );
		$this->assertFalse( $auth->requester_acts_as_user(), 'broker bearer runs as the service account' );

		$auth->act_as( Mcpwp_WebMCP::principal_record( self::EDITOR ) );
		$this->assertTrue( $auth->requester_acts_as_user(), 'WebMCP principal' );
	}

	// ── The capability column, row by row ──────────────────────────────────

	public static function row_provider(): array {
		$out = array();
		foreach ( Mcpwp_Route_Capability_Map::rows() as $key => $row ) {
			$out[ $key ] = array( $key, $row[1] );
		}
		return $out;
	}

	private function check_as( int $user_id, string $key, array $record ) {
		$auth = new Mcpwp_Route_Capability_Map_Harness();
		$auth->act_as( $record );
		$GLOBALS['mcpwp_test_current_user'] = $user_id;
		list( $method, $concrete ) = $this->concrete_for( $key );
		return $auth->check_user_run_capability( new WP_REST_Request( $method, $concrete, array() ) );
	}

	private function editor_record(): array {
		return array( 'id' => 'oauth-user:7', 'type' => 'oauth', 'scopes' => array( 'read', 'write' ), 'acts_as_user' => self::EDITOR );
	}

	/**
	 * @dataProvider row_provider
	 */
	public function test_editor_acting_as_a_user_gets_what_an_editor_gets_in_wp_admin( string $key, string $capability ): void {
		$result = $this->check_as( self::EDITOR, $key, $this->editor_record() );

		$editor_holds_all = 'TODO' !== $capability && 0 !== strpos( $capability, 'forms:' ) && array() === array_diff( explode( '+', $capability ), self::EDITOR_CAPS );
		if ( 'none' === $capability || $editor_holds_all ) {
			$this->assertTrue( $result, "$key ($capability): an Editor can do this in wp-admin" );
			return;
		}
		$this->assertInstanceOf( WP_Error::class, $result, "$key ($capability): an Editor cannot do this in wp-admin" );
		$this->assertSame( 'rest_forbidden_capability', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * @dataProvider row_provider
	 */
	public function test_bot_run_admin_key_is_never_capability_checked( string $key, string $capability ): void {
		$GLOBALS['mcpwp_test_user_caps'][2] = array(); // the service account holds none of these caps
		$this->assertTrue( $this->check_as( 2, $key, array( 'id' => 'k1', 'type' => 'scoped', 'scopes' => array( 'read', 'write', 'admin' ), 'role' => 'admin' ) ), "$key: bots are gated by scope only" );
	}

	// ── #1108: principals that FAIL the edit_posts rows ────────────────────

	/** Every (role, row) pair; the Editor fixture above holds edit_posts, so it can never fail those rows. */
	public static function lower_role_row_provider(): array {
		$out = array();
		foreach ( array( 'contributor' => self::CONTRIBUTOR_CAPS, 'subscriber' => self::SUBSCRIBER_CAPS ) as $role => $caps ) {
			foreach ( Mcpwp_Route_Capability_Map::rows() as $key => $row ) {
				$out[ $role . ' ' . $key ] = array( $role, $caps, $key, $row[1] );
			}
		}
		return $out;
	}

	/**
	 * @dataProvider lower_role_row_provider
	 */
	public function test_contributor_and_subscriber_get_what_their_role_gets_in_wp_admin( string $role, array $role_caps, string $key, string $capability ): void {
		$id     = 'contributor' === $role ? self::CONTRIBUTOR : self::SUBSCRIBER;
		$result = $this->check_as( $id, $key, array( 'id' => 'oauth-user:' . $id, 'type' => 'oauth', 'scopes' => array( 'read', 'write' ), 'acts_as_user' => $id ) );

		$holds_all = 'TODO' !== $capability && 0 !== strpos( $capability, 'forms:' ) && array() === array_diff( explode( '+', $capability ), $role_caps );
		if ( 'none' === $capability || $holds_all ) {
			$this->assertTrue( $result, "$key ($capability): a $role can do this in wp-admin" );
			return;
		}
		$this->assertInstanceOf( WP_Error::class, $result, "$key ($capability): a $role cannot do this in wp-admin" );
		$this->assertSame( 'rest_forbidden_capability', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * Hand-written, not derived from the map, so removing an edit_posts check
	 * from the chokepoint cannot move the expectation with it (mutation M25).
	 */
	public function test_edit_posts_rows_refuse_a_principal_without_edit_posts(): void {
		$subscriber  = array( 'id' => 'oauth-user:9', 'type' => 'oauth', 'scopes' => array( 'read', 'write' ), 'acts_as_user' => self::SUBSCRIBER );
		$contributor = array( 'id' => 'oauth-user:8', 'type' => 'oauth', 'scopes' => array( 'read', 'write' ), 'acts_as_user' => self::CONTRIBUTOR );

		$edit_posts_rows = array( 'GET /approvals', 'GET /seo/(?P<id>\\d+)', 'POST /seo/(?P<id>\\d+)', 'POST /posts' );
		foreach ( $edit_posts_rows as $key ) {
			$this->assertSame( 'edit_posts', Mcpwp_Route_Capability_Map::rows()[ $key ][1], "$key requires edit_posts" );

			$refused = $this->check_as( self::SUBSCRIBER, $key, $subscriber );
			$this->assertInstanceOf( WP_Error::class, $refused, "$key: a Subscriber has no edit_posts" );
			$this->assertSame( 'edit_posts', $refused->get_error_data()['required_capability'], $key );
			$this->assertTrue( $this->check_as( self::CONTRIBUTOR, $key, $contributor ), "$key: a Contributor holds edit_posts" );
		}

		// A Contributor fails the rows one step above edit_posts.
		foreach ( array( 'POST /pages' => 'edit_pages', 'POST /media/from-url' => 'upload_files', 'POST /terms' => 'manage_categories' ) as $key => $needs ) {
			$this->assertSame( $needs, Mcpwp_Route_Capability_Map::rows()[ $key ][1], $key );
			$refused = $this->check_as( self::CONTRIBUTOR, $key, $contributor );
			$this->assertInstanceOf( WP_Error::class, $refused, "$key: a Contributor lacks $needs" );
			$this->assertSame( $needs, $refused->get_error_data()['required_capability'], $key );
		}
	}

	public function test_a_missing_capability_map_fails_closed_for_a_user_run_principal(): void {
		$auth = new Mcpwp_Route_Capability_Map_Absent_Harness();
		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;
		$GLOBALS['mcpwp_test_user_caps'][ self::ADMIN ] = array_fill_keys( array_merge( self::EDITOR_CAPS, array( 'manage_options' ) ), true );

		foreach ( array( array( 'GET', '/mcpwp/v1/site-info' ), array( 'POST', '/mcpwp/v1/posts' ), array( 'GET', '/mcpwp/v1/mcp' ) ) as list( $method, $route ) ) {
			foreach ( array(
				'oauth user (admin)' => array( 'id' => 'oauth-user:1', 'type' => 'oauth', 'scopes' => array( 'read', 'write' ), 'acts_as_user' => self::ADMIN ),
				'webmcp'             => Mcpwp_WebMCP::principal_record( self::ADMIN ),
			) as $who => $record ) {
				$auth->act_as( $record );
				$result = $auth->check_user_run_capability( new WP_REST_Request( $method, $route, array() ) );
				$this->assertInstanceOf( WP_Error::class, $result, "$who $method $route: no map, no pass-through" );
				$this->assertSame( 'rest_forbidden_capability', $result->get_error_code() );
				$this->assertSame( 403, $result->get_error_data()['status'] );
			}
		}

		// A bot principal is gated by scope alone, map or no map.
		$auth->act_as( array( 'id' => 'k1', 'type' => 'scoped', 'scopes' => array( 'read', 'write', 'admin' ), 'role' => 'admin' ) );
		$this->assertTrue( $auth->check_user_run_capability( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array() ) ) );

		// And the shipped seam answers true while the class is loaded.
		$real = new class() extends Mcpwp_Route_Capability_Map_Harness {
			public function loaded() {
				return $this->route_capability_map_available();
			}
		};
		$this->assertTrue( $real->loaded() );
	}

	public function test_undecided_rows_are_admin_only_until_decided(): void {
		$todo = array_keys( array_filter( Mcpwp_Route_Capability_Map::rows(), function ( $row ) {
			return 'TODO' === $row[1];
		} ) );
		$this->assertNotEmpty( $todo );
		foreach ( $todo as $key ) {
			$this->assertInstanceOf( WP_Error::class, $this->check_as( self::EDITOR, $key, $this->editor_record() ), $key );
			$this->assertTrue( $this->check_as( self::ADMIN, $key, array( 'id' => 'oauth-user:1', 'type' => 'oauth', 'scopes' => array( 'read', 'write' ), 'acts_as_user' => self::ADMIN ) ), $key );
		}
	}

	public function test_an_unknown_route_is_admin_only_until_decided(): void {
		$auth = new Mcpwp_Route_Capability_Map_Harness();
		$auth->act_as( $this->editor_record() );
		$GLOBALS['mcpwp_test_current_user'] = self::EDITOR;
		$result = $auth->check_user_run_capability( new WP_REST_Request( 'GET', '/mcpwp/v1/some-addon-route', array() ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'manage_options', $result->get_error_data()['required_capability'] );
		$this->assertSame( 'manage_options', Mcpwp_Route_Capability_Map::UNDECIDED_ADMIN_ONLY_CAPABILITY );
	}

	public function test_forms_capability_follows_the_plugin_segment(): void {
		$this->assertSame( 'gravityforms_view_entries', Mcpwp_Route_Capability_Map::required_capability( 'GET', '/mcpwp/v1/forms/gravityforms/3/entries' ) );
		$this->assertSame( 'gravityforms_edit_forms', Mcpwp_Route_Capability_Map::required_capability( 'GET', '/mcpwp/v1/forms/gravityforms/3' ) );
		$this->assertSame( 'wpcf7_read_contact_forms', Mcpwp_Route_Capability_Map::required_capability( 'GET', '/mcpwp/v1/forms/cf7/3' ) );
		$this->assertSame( 'manage_options', Mcpwp_Route_Capability_Map::required_capability( 'GET', '/mcpwp/v1/forms/wpforms/3/entries' ) );
		$this->assertSame( 'manage_options', Mcpwp_Route_Capability_Map::required_capability( 'GET', '/mcpwp/v1/forms' ), 'No plugin named: the fallback.' );
	}

	public function test_kasra_and_named_rows(): void {
		$rows = Mcpwp_Route_Capability_Map::rows();
		$this->assertSame( 'edit_pages', $rows['POST /pages'][1] );
		$this->assertSame( 'write', $rows['POST /pages'][0], 'A capability row, not admin scope.' );
		$this->assertSame( 'manage_categories', $rows['POST /terms'][1] );
		$this->assertSame( 'write', $rows['POST /terms'][0] );
		$this->assertSame( 'edit_theme_options', $rows['POST /elementor/globals'][1] );
		$this->assertSame( 'write', $rows['POST /elementor/globals'][0] );
		$this->assertSame( 'edit_theme_options', $rows['POST /elementor/kit-css'][1] );
		$this->assertSame( 'write', $rows['POST /elementor/kit-css'][0] );
		$this->assertSame( 'manage_woocommerce', $rows['GET /woocommerce/orders'][1] );
		// #1103 round 2: F2, F3, (a).
		foreach ( array( 'POST /elementor/page', 'POST /elementor/build-page', 'POST /elementor/landing-page', 'POST /elementor/clone', 'POST /elementor/blueprints/build' ) as $key ) {
			$this->assertSame( 'edit_pages', $rows[ $key ][1], $key );
		}
		$this->assertSame( 'delete_others_posts+delete_others_pages', $rows['DELETE /drafts/delete-all'][1] );
		$this->assertSame( 'none', $rows['POST /feedback/relay'][1] );
		// (D) First-connection discovery: read.
		foreach ( array( 'GET /site-info', 'GET /onboard', 'GET /introspect' ) as $key ) {
			$this->assertSame( 'read', $rows[ $key ][1], $key );
		}
		// Kasra, Pass A follow-up: the core-profile tool rows, decided by ROW.
		$core_rows = array(
			'GET /site-context'                   => array( 'read', 'wp_get_site_context' ),
			'GET /rendered-html'                  => array( 'read', 'wp_get_rendered_html' ),
			'GET /elementor/(?P<id>\\d+)'         => array( 'read', 'wp_get_elementor' ),
			'GET /elementor/(?P<id>\\d+)/summary' => array( 'read', 'wp_get_elementor_summary' ),
			'GET /seo/(?P<id>\\d+)'               => array( 'edit_posts', 'wp_get_seo' ),
			'POST /seo/(?P<id>\\d+)'              => array( 'edit_posts', 'wp_set_seo' ),
			'GET /approvals'                      => array( 'edit_posts', 'wp_list_approvals' ),
		);
		$map = array_merge( ( new Mcpwp_MCP_Free_Tools() )->get_tool_map(), ( new Mcpwp_MCP_Pro_Tools() )->get_tool_map() );
		foreach ( $core_rows as $key => list( $capability, $tool ) ) {
			$this->assertSame( $capability, $rows[ $key ][1], $key );
			// The tool resolves to exactly this row.
			$route = preg_replace( '/\\{[^}]+\\}/', '5', $map[ $tool ]['route'] );
			$this->assertSame( $key, Mcpwp_Route_Capability_Map::resolve( $map[ $tool ]['method'], $route )['key'], $tool );
		}
		// Their per-post check: each route that names a post declares it.
		$probe = new class() extends Mcpwp_Route_Capability_Map_Harness {
			public function refs( $request ) {
				return $this->collect_post_refs( $request );
			}
		};
		foreach ( array( array( 'GET', '/mcpwp/v1/rendered-html' ), array( 'GET', '/mcpwp/v1/elementor/5' ), array( 'GET', '/mcpwp/v1/elementor/5/summary' ), array( 'GET', '/mcpwp/v1/seo/5' ), array( 'POST', '/mcpwp/v1/seo/5' ) ) as list( $method, $route ) ) {
			$refs = $probe->refs( new WP_REST_Request( $method, $route, array( 'id' => 5 ) ) );
			$this->assertSame( array( 5 ), $refs['scalar'], "$method $route declares its post id for the per-post check" );
		}
		foreach ( $rows as $key => $row ) {
			if ( 0 === strpos( explode( ' ', $key, 2 )[1], '/learnpress' ) ) {
				$this->assertSame( 'manage_options', $row[1], $key );
			}
		}
	}
}
