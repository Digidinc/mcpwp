<?php
/**
 * Adversarial review round 1 on #1007, BLOCK-1.
 *
 * RouteCategoryToolParityTest (added for #943 S2, extended for #1007) proves
 * every route a Free or Pro MCP TOOL dispatches to resolves to that tool's
 * category. That is a real guarantee, but it can only see routes a tool
 * actually maps to — a route with NO tool wrapping it is invisible to that
 * test no matter how it classifies. #1007's own review turned up exactly
 * that class of gap, all check_permission()-gated (so a real key IS required
 * and the category system DOES apply) but dispatching no tool at all:
 *
 *   - /settings   — POST/PUT changes blogname/tagline/timezone/etc.
 *   - /favicon    — POST changes the site icon
 *   - /theme/*    — customizer mods, global styles, custom CSS (Pro)
 *   - /themes/*   — Astra colors/typography/header/footer/layout (Pro)
 *   - /action-log — cross-key audit trail (which key ran what)
 *   - /users      — WP user account CRUD (Pro; privilege-escalation surface)
 *
 * Live evidence from the review: an author key (role categories: content,
 * media, taxonomy — no 'site') with the site-wide 'site' category disabled
 * could still POST /favicon and change the site icon; an admin key with
 * 'site' disabled could still PUT /settings (changed blogname) and freely
 * GET/PUT /theme/custom-css, while GET /custom-css (the Free, tooled
 * sibling) correctly 403'd.
 *
 * This test closes the BLIND SPOT itself, not just the six routes found by
 * hand: it registers every REST controller this plugin ships — Free AND
 * Pro, regardless of license state, the same way class-mcpwp-loader.php's
 * register_rest_routes() and class-mcpwp-pro-bootstrap.php's
 * register_routes() do — and asserts EVERY resulting mcpwp/v1 route
 * resolves to a non-empty category, except two closed, commented,
 * assertSame-pinned lists: the discovery allowlist (get_discovery_allowlist_routes())
 * and a short list of routes that are genuinely not key-category-gated at
 * all (never reach check_permission()/verify_api_key(), or are the /mcp
 * dispatch envelope itself, gated per-tool elsewhere). Any new route that
 * dispatches no tool and forgets a category mapping now fails HERE, without
 * needing a tool to exist first or a live probe to notice.
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

/** Exposes the protected route classifier + discovery allowlist. */
class Mcpwp_All_Routes_Category_Harness {
	use Mcpwp_Api_Auth {
		get_category_for_route as public;
		get_discovery_allowlist_routes as public;
	}
}

final class AllRegisteredRoutesCategoryTest extends TestCase {

	/**
	 * Routes that never reach check_permission()/verify_api_key() at all —
	 * the category system has nothing to apply to. Each one is
	 * permission_callback => '__return_true' at registration (verified by
	 * reading the source, not inferred), so leaving these OUT of the route
	 * category map is correct, not an oversight.
	 */
	private const PUBLIC_NO_AUTH_ROUTES = array(
		'/openapi.json',          // Mcpwp_REST_OpenAPI: public machine-readable schema, no API key.
		'/oauth/authorize',       // Mcpwp_REST_OAuth: pre-token OAuth 2.1 authorize step.
		'/oauth/token',           // Mcpwp_REST_OAuth: pre-token OAuth 2.1 token exchange.
		'/broker/enroll-callback', // Mcpwp_REST_Broker: mumcp.com enrollment handshake (public by design).
		'/broker/enroll-confirm',  // Mcpwp_REST_Broker: mumcp.com enrollment handshake (public by design).
		'/broker/enroll-store',    // Mcpwp_REST_Broker: mumcp.com enrollment handshake (public by design).
	);

	/**
	 * Routes that DO run check_permission(), but are deliberately not
	 * route-category-classified because a finer-grained gate already covers
	 * them elsewhere.
	 */
	private const AUTHED_BUT_UNCLASSIFIED_ROUTES = array(
		// /mcp is the JSON-RPC dispatch envelope. The real per-call gate is
		// tool_category_for()/resolve_key_tool_categories() inside
		// Mcpwp_REST_MCP::handle_tools_call(), keyed by TOOL NAME — a route
		// classification of '/mcp' itself would be strictly less precise
		// (every tool shares the one route) and is explicitly documented as
		// out of scope in get_category_for_route()'s own docblock.
		'/mcp',
	);

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['mcpwp_test_filters']            = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();

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

	public function test_every_registered_route_resolves_to_a_category_or_is_explicitly_exempt(): void {
		$auth   = new Mcpwp_All_Routes_Category_Harness();
		$routes = $this->register_every_known_route();

		// Sanity: this must actually enumerate the whole plugin, not a stub
		// that silently registered nothing.
		$this->assertGreaterThan( 250, count( $routes ), 'Premise: every controller actually registered its routes.' );

		$exempt      = array_merge(
			$auth->get_discovery_allowlist_routes(),
			self::PUBLIC_NO_AUTH_ROUTES,
			self::AUTHED_BUT_UNCLASSIFIED_ROUTES
		);
		$found_empty = array();
		$failures    = array();

		foreach ( $routes as $pattern => $concrete ) {
			$category    = $auth->get_category_for_route( $concrete );
			$is_exempt   = false;
			foreach ( $exempt as $exempt_route ) {
				if ( 0 === strpos( $concrete, '/mcpwp/v1' . $exempt_route ) ) {
					$is_exempt = true;
					break;
				}
			}

			if ( '' === $category ) {
				if ( $is_exempt ) {
					$found_empty[] = $pattern;
				} else {
					$failures[] = sprintf( '%s -> %s: resolves to no category and is not on any exemption list', $pattern, $concrete );
				}
			}
		}

		$this->assertSame( array(), $failures, "Untooled or unclassified routes found:\n" . implode( "\n", $failures ) );

		// Pin the exemption set exactly, sorted, so a route silently added to
		// (or removed from) any exemption list is a visible diff here.
		sort( $found_empty );
		sort( $exempt );
		$this->assertSame( $exempt, $found_empty );
	}
}
