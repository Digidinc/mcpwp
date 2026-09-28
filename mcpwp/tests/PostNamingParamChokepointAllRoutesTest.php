<?php
/**
 * Round-2 adversarial review of PR #1030 (follow-up to #1024/#1022):
 * per-door category checks only ever cover the doors someone thought to
 * enumerate. This test enumerates every REGISTERED mcpwp/v1 route — Free and
 * Pro, the same way AllRegisteredRoutesCategoryTest's own
 * register_every_known_route() does — and, for every route argument named in
 * Mcpwp_Api_Auth::POST_NAMING_PARAMS, sends a category-restricted key a
 * gated WooCommerce product id in that param, asserting
 * Mcpwp_Api_Auth::check_post_naming_params_category() (the new chokepoint)
 * refuses it — so a NEW route that resolves a post by id and forgets its own
 * category check still fails HERE, without anyone having to name it first.
 *
 * A route classified 'woocommerce' itself is skipped for the product case: a
 * caller who already holds that category (required just to reach the route
 * at all) is not proving anything by also being allowed to touch that type —
 * the SAME "is this additional, or does the route already require it"
 * reasoning Mcpwp_Elementor_Basic::validate_post()'s own page/post exemption
 * uses, generalized to every route instead of one door.
 *
 * elementor_library/elementor_snippet are DELIBERATELY not exercised here.
 * check_post_naming_params_category() exempts both post types from this
 * generic chokepoint (see its docblock in trait-mcpwp-api-auth.php): live rig
 * proof during round 2 found the chokepoint's own category_for_post_type()
 * mapping ('elementor-templates' for every elementor_library post,
 * unconditionally) actively breaks /theme-builder/*, whose established,
 * narrower category is 'elementor-theme' — a key legitimately scoped to only
 * 'elementor-theme' could no longer read its own theme templates. Every place
 * elementor_library/elementor_snippet is reachable already carries its own
 * precise, domain-aware check instead —
 * Mcpwp_Elementor_Basic::validate_post()'s elementor_type_category_denied(),
 * Mcpwp_Theme_Builder::resolve_theme_template(), and this PR's individual
 * REST-layer checks on clone/create_landing_page/save_section_as_template/
 * apply_template — so a second, cruder, one-size-fits-all mapping in this
 * generic chokepoint would not close a gap, it would reopen one.
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

/** Exposes the trait's post-naming-param list and chokepoint directly. */
class Mcpwp_Post_Naming_Param_Harness {
	use Mcpwp_Api_Auth;

	public function params(): array {
		return self::POST_NAMING_PARAMS;
	}
}

final class PostNamingParamChokepointAllRoutesTest extends TestCase {

	private const PRODUCT_ID   = 8801;
	private const ARCHETYPE_ID = 8802; // elementor_library

	/** @var \ReflectionProperty */
	private $wh_prop;
	/** @var mixed */
	private $wh_saved;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']              = array();
		$GLOBALS['mcpwp_test_transients']            = array();
		$GLOBALS['mcpwp_test_current_user']          = 0;
		$GLOBALS['mcpwp_test_is_admin']              = false;
		$GLOBALS['mcpwp_test_filters']               = array();
		$GLOBALS['_mcpwp_test_registered_routes']    = array();
		$GLOBALS['mcpwp_test_public_post_types']     = array( 'product' );
		$GLOBALS['_mcpwp_test_meta']                 = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PRODUCT_ID   => new WP_Post(
				array(
					'ID'          => self::PRODUCT_ID,
					'post_type'   => 'product',
					'post_title'  => 'Rig Product',
					'post_status' => 'publish',
				)
			),
			self::ARCHETYPE_ID => new WP_Post(
				array(
					'ID'          => self::ARCHETYPE_ID,
					'post_type'   => 'elementor_library',
					'post_title'  => 'Rig Template',
					'post_status' => 'publish',
				)
			),
		);

		// Same Mcpwp_Webhooks singleton workaround AllRegisteredRoutesCategoryTest uses.
		$ref            = new ReflectionClass( 'Mcpwp_Webhooks' );
		$this->wh_prop  = $ref->getProperty( 'instance' );
		$this->wh_prop->setAccessible( true );
		$this->wh_saved = $this->wh_prop->getValue();
		$this->wh_prop->setValue( null, $ref->newInstanceWithoutConstructor() );
	}

	protected function tearDown(): void {
		$this->wh_prop->setValue( null, $this->wh_saved );
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}

	/**
	 * Every registered mcpwp/v1 route, flattened to {route, method,
	 * controller, category, arg_names}. Mirrors
	 * AllRegisteredRoutesCategoryTest::register_every_known_route(), but
	 * keeps the controller instance and per-method arg names instead of
	 * collapsing to a bare path — this test needs both to build a real
	 * request and know what to name.
	 */
	private function register_every_known_route_flat(): array {
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

		$flat = array();
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $r ) {
			if ( 'mcpwp/v1' !== $r['namespace'] ) {
				continue;
			}
			$handlers = $r['args'];
			// A single-handler registration is one assoc array; a
			// multi-method one is a list of them — normalize to a list.
			if ( isset( $handlers['callback'] ) || isset( $handlers['methods'] ) ) {
				$handlers = array( $handlers );
			}
			foreach ( $handlers as $h ) {
				if ( ! is_array( $h ) || ! isset( $h['permission_callback'] ) || ! is_array( $h['permission_callback'] ) ) {
					continue;
				}
				$controller = $h['permission_callback'][0] ?? null;
				if ( ! is_object( $controller ) ) {
					continue;
				}
				$flat[] = array(
					'route'      => $r['route'],
					'method'     => is_string( $h['methods'] ?? null ) ? $h['methods'] : 'GET',
					'controller' => $controller,
					'arg_names'  => is_array( $h['args'] ?? null ) ? array_keys( $h['args'] ) : array(),
				);
			}
		}
		return $flat;
	}

	/** A 'custom'-role key holding every known category except $except. */
	private function key_excluding( object $controller, string $except ): array {
		$all = array_keys( Mcpwp_Post_Naming_Param_Harness::get_all_tool_category_labels() );
		$categories = array_values( array_diff( $all, array( $except ) ) );
		return $controller->create_scoped_api_key( 'scoped', array( 'read', 'write', 'admin' ), 'custom', $categories );
	}

	/**
	 * For every registered route + method, for every arg name in
	 * Mcpwp_Api_Auth::POST_NAMING_PARAMS, a key holding every category
	 * EXCEPT the one the target post type needs must be refused when that
	 * param names the gated post — UNLESS the route's own classified
	 * category already equals the one being tested (in which case holding
	 * every OTHER category still means the caller lacks the route's own
	 * requirement, so the request never reaches the new chokepoint at all —
	 * skipped, not a gap this fix is about).
	 */
	public function test_every_route_refuses_a_gated_id_in_any_post_naming_param(): void {
		$harness = new Mcpwp_Post_Naming_Param_Harness();
		$params  = $harness->params();

		$routes = $this->register_every_known_route_flat();
		$this->assertGreaterThan( 100, count( $routes ), 'Sanity: this must enumerate the whole plugin, not a stub that registered nothing.' );

		// elementor_library is deliberately not a case here — see this file's
		// top-of-file docblock for why the chokepoint exempts it.
		$cases = array(
			array( 'id' => self::PRODUCT_ID, 'except' => 'woocommerce', 'label' => 'product' ),
		);

		$checked = 0;
		foreach ( $routes as $r ) {
			// /mcp and /batch are envelopes the chokepoint itself skips
			// (their own params don't name the real target — see
			// check_post_naming_params_category()'s docblock); their real
			// dispatch re-enters through a fresh sub-request this same loop
			// already covers via every OTHER controller's routes.
			if ( '/mcp' === $r['route'] || '/batch' === $r['route'] ) {
				continue;
			}

			foreach ( $params as $param ) {
				if ( ! in_array( $param, $r['arg_names'], true ) ) {
					continue;
				}

				foreach ( $cases as $case ) {
					$route_category = $r['controller']->get_category_for_route( '/mcpwp/v1' . preg_replace( '/\([^()]*\)/', '1', $r['route'] ) );
					if ( $case['except'] === $route_category ) {
						continue; // Route already requires the category being tested; not this fix's gap.
					}

					$created = $this->key_excluding( $r['controller'], $case['except'] );
					$request = new WP_REST_Request(
						$r['method'],
						'/mcpwp/v1' . preg_replace( '/\([^()]*\)/', '1', $r['route'] ),
						array( $param => $case['id'] ),
						array( 'X-API-Key' => $created['key'] )
					);

					$result = $r['controller']->check_permission( $request );
					$checked++;
					$this->assertInstanceOf(
						WP_Error::class,
						$result,
						sprintf(
							'%s %s with %s=%s (a gated %s) must be refused for a key lacking "%s" — got %s.',
							$r['method'],
							$r['route'],
							$param,
							$case['id'],
							$case['label'],
							$case['except'],
							is_object( $result ) ? get_class( $result ) : var_export( $result, true )
						)
					);
				}
			}
		}

		$this->assertGreaterThan( 0, $checked, 'Sanity: this must actually exercise at least one route+param+case, not silently match nothing.' );
	}
}
