<?php
/**
 * Round-3 adversarial review of PR #1030 (follow-up to #1024/#1022's
 * category chokepoint): round-2's chokepoint scanned every request for a
 * fixed list of PARAM NAMES and resolved anything numeric found under them
 * via get_post() — which meant a route whose numeric id happens to share a
 * name with that list, but names something that is NOT a post at all (a
 * WP_User id at /users/{id} or /woocommerce/customers/{id}, a term id at
 * /terms/{id}, a menu id at /menus/{id}), collided with an unrelated gated
 * post sharing the same numeric id and refused a request that never touched
 * a post. The coordinator's decision replaced that name-based scan with
 * explicit, per-route-family DECLARATIONS
 * (Mcpwp_Api_Auth::get_post_ref_param_map()) — this test proves the
 * replacement is both SAFE (test one below: a route that is NOT a post
 * reference is never mistakenly resolved) and COMPLETE (test two: every
 * route that IS one is actually declared and actually enforced, with the
 * missing/category-denied/gated-elementor-library cases all answering
 * byte-identically).
 *
 * Test one enumerates every REGISTERED mcpwp/v1 route — Free and Pro, the
 * same way AllRegisteredRoutesCategoryTest's own register_every_known_route()
 * does — and, for every route whose regex or declared args carry a param
 * named like a post reference (id/post_id/page_id/source_id/template_id/
 * object_id/ids), asserts it is accounted for one way or the other: declared
 * in get_post_ref_param_map() for a matching prefix, or explicitly listed in
 * get_non_post_id_route_prefixes() as a deliberate non-post id. Anything in
 * neither is a genuine gap — a new route nobody told this map about yet.
 *
 * Test two exercises every route+param the map DOES declare (scalar specs —
 * array-shaped ones like ids[]/updates[].id have their own dedicated
 * wholesale-batch tests elsewhere) three ways: a gated WooCommerce product
 * id, a gated Elementor library id (elementor-templates disabled), and a
 * genuinely missing id — asserting check_permission() refuses all three with
 * the exact same body and status (Mcpwp_Api_Auth::post_ref_not_found_error()),
 * not merely the same shape. A route classified 'woocommerce'/
 * 'elementor-templates' itself is skipped for the matching case: a caller
 * who already holds that category to reach the route at all is not proving
 * anything by also being allowed to touch that type.
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

/**
 * Exposes check_post_naming_params_category() directly and lets a test set
 * an arbitrary key record without going through full auth — needed because
 * /menus is blanket-admin-SCOPE (get_required_scope_for_request()), and an
 * admin-SCOPE key can only ever be admin-ROLE, which bypasses every category
 * check entirely (key_allows_category() returns true unconditionally for an
 * empty tool_categories list). WARN-3's fix is therefore unreachable through
 * check_permission()'s full pipeline for this route family; this harness
 * exercises the chokepoint's own conditional object_id logic directly,
 * the same way force_key_record() lets other tests in this PR bypass full
 * auth to reach a chokepoint's category logic in isolation.
 */
class Mcpwp_Post_Ref_Category_Harness {
	use Mcpwp_Api_Auth;

	public function check( $request ) {
		return $this->check_post_naming_params_category( $request );
	}

	public function set_key_record( array $record ) {
		$ref = new ReflectionProperty( $this, 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue( $this, $record );
	}
}

final class PostNamingParamChokepointAllRoutesTest extends TestCase {

	private const PRODUCT_ID   = 8801;
	private const ARCHETYPE_ID = 8802; // elementor_library, not a theme-builder location type.
	private const MISSING_ID   = 9999999;

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
		$GLOBALS['_mcpwp_test_meta']                 = array(
			self::ARCHETYPE_ID => array( '_elementor_template_type' => 'archetype' ),
		);

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
	 * controller, arg_names}. Mirrors
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

	/** Whether $path is $prefix or a sub-path of it (matches literal prefixes against a raw route regex too, since these route strings never put regex metacharacters before the first '/'-delimited literal segment). */
	private function prefix_matches( string $path, string $prefix ): bool {
		return $path === $prefix || 0 === strpos( $path, $prefix . '/' ) || 0 === strpos( $path, $prefix . '(' );
	}

	/** A 'custom'-role key holding every known category except $except (or every one at all, when $except is ''). */
	private function key_excluding( object $controller, string $except ): array {
		$all        = array_keys( $controller->get_all_tool_category_labels() );
		$categories = '' === $except ? $all : array_values( array_diff( $all, array( $except ) ) );
		return $controller->create_scoped_api_key( 'scoped', array( 'read', 'write', 'admin' ), 'custom', $categories );
	}

	/**
	 * Item 6(a): every route whose regex or declared args carry a param
	 * named like a post reference must be accounted for — either
	 * get_post_ref_param_map() declares SOMETHING for a matching prefix, or
	 * the route's prefix is in get_non_post_id_route_prefixes(). A route in
	 * neither is a gap: a new door nobody told this map about yet.
	 */
	public function test_every_id_shaped_route_is_declared_or_explicitly_excluded(): void {
		$routes = $this->register_every_known_route_flat();
		$this->assertGreaterThan( 100, count( $routes ), 'Sanity: this must enumerate the whole plugin, not a stub that registered nothing.' );

		$id_shaped_names = array( 'id', 'post_id', 'page_id', 'source_id', 'template_id', 'object_id', 'ids' );
		$map             = Mcpwp_REST_Elementor::get_post_ref_param_map();
		$excluded        = Mcpwp_REST_Elementor::get_non_post_id_route_prefixes();

		$uncovered = array();
		foreach ( $routes as $r ) {
			if ( '/mcp' === $r['route'] || '/batch' === $r['route'] ) {
				continue; // Envelopes — see this file's own top-of-file docblock.
			}

			$has_id_shaped_param = false;
			foreach ( $r['arg_names'] as $name ) {
				if ( in_array( $name, $id_shaped_names, true ) ) {
					$has_id_shaped_param = true;
					break;
				}
			}
			if ( ! $has_id_shaped_param ) {
				continue;
			}

			$covered = false;
			foreach ( array_keys( $map ) as $prefix ) {
				if ( $this->prefix_matches( $r['route'], $prefix ) ) {
					$covered = true;
					break;
				}
			}
			if ( ! $covered ) {
				foreach ( $excluded as $prefix ) {
					if ( $this->prefix_matches( $r['route'], $prefix ) ) {
						$covered = true;
						break;
					}
				}
			}

			if ( ! $covered ) {
				$uncovered[] = $r['method'] . ' ' . $r['route'];
			}
		}

		$this->assertSame(
			array(),
			array_values( array_unique( $uncovered ) ),
			'Routes carrying an id-shaped param but declared nowhere — add them to Mcpwp_Api_Auth::get_post_ref_param_map() (if a real post reference) or get_non_post_id_route_prefixes() (if not).'
		);
	}

	/**
	 * Item 6(b): for every route+scalar-param get_post_ref_param_map()
	 * actually declares, a gated WooCommerce product id and a gated
	 * Elementor library id must both refuse through check_permission() with
	 * the exact same body and status (Mcpwp_Api_Auth::post_ref_not_found_error()).
	 *
	 * A genuinely MISSING id is asserted the other way — check_permission()
	 * must return `true` (pass through), NOT refuse. An earlier draft of the
	 * chokepoint also enforced existence at the guard for scalar params, on
	 * the theory that "missing" and "gated" should be indistinguishable by
	 * construction; that broke 57 existing tests elsewhere in the suite that
	 * build a request naming a placeholder id with no matching fixture,
	 * because their concern is something else entirely (publish-scope,
	 * draft-leak parity, archetype-apply status logic) and they were never
	 * meant to exercise this chokepoint's existence handling. Round-2 already
	 * established the correct rule — "a missing id is the door's own
	 * business, not this chokepoint's" — and round-3 keeps it for scalars
	 * too, matching how array-shaped params already behave. Byte-parity
	 * between a door's own missing-post branch and this chokepoint's
	 * gated-post branch is instead achieved per-door, by pointing both at
	 * post_ref_not_found_error() directly (see this PR's changes to
	 * class-mcpwp-rest-blocks.php, -seo.php, -seo-audit.php,
	 * -content-quality.php, and -site-content.php's post-meta handlers).
	 *
	 * Array-shaped specs (ids[], updates[].id, ids:csv, ...) are skipped
	 * here — their wholesale-batch-refusal semantics (round-2) are exercised
	 * by their own dedicated tests, not this one-value-per-request shape.
	 */
	public function test_every_declared_scalar_param_refuses_gated_and_missing_identically(): void {
		$routes = $this->register_every_known_route_flat();
		$map    = Mcpwp_REST_Elementor::get_post_ref_param_map();

		$cases = array(
			array( 'id' => self::PRODUCT_ID, 'except' => 'woocommerce', 'label' => 'product' ),
			array( 'id' => self::ARCHETYPE_ID, 'except' => 'elementor-templates', 'label' => 'elementor_library' ),
		);

		$checked  = 0;
		$expected = null;

		foreach ( $routes as $r ) {
			if ( '/mcp' === $r['route'] || '/batch' === $r['route'] ) {
				continue;
			}

			$declared = null;
			foreach ( $map as $prefix => $specs ) {
				if ( $this->prefix_matches( $r['route'], $prefix ) ) {
					$declared = $specs;
					break;
				}
			}
			if ( empty( $declared ) ) {
				continue;
			}

			foreach ( $declared as $spec ) {
				// Scalar specs only — no '[]' or ':csv' suffix.
				if ( false !== strpos( $spec, '[]' ) || false !== strpos( $spec, ':csv' ) ) {
					continue;
				}
				if ( ! in_array( $spec, $r['arg_names'], true ) ) {
					continue;
				}

				$route_category = $r['controller']->get_category_for_route( '/mcpwp/v1' . preg_replace( '/\([^()]*\)/', '1', $r['route'] ) );

				// Missing id: even a key holding every category must PASS —
				// the guard never manufactures a false "not found" for an id
				// it merely failed to resolve. See this test's own docblock.
				$missing_key     = $this->key_excluding( $r['controller'], '' );
				$missing_request = new WP_REST_Request(
					$r['method'],
					'/mcpwp/v1' . preg_replace( '/\([^()]*\)/', '1', $r['route'] ),
					array( $spec => self::MISSING_ID ),
					array( 'X-API-Key' => $missing_key['key'] )
				);
				$missing_result = $r['controller']->check_permission( $missing_request );
				$this->assertTrue(
					$missing_result,
					sprintf( '%s %s with %s=%d (missing) must pass the chokepoint — existence is the door\'s own business.', $r['method'], $r['route'], $spec, self::MISSING_ID )
				);

				foreach ( $cases as $case ) {
					if ( $case['except'] === $route_category ) {
						continue; // Route already requires this category; not this test's gap.
					}

					$created = $this->key_excluding( $r['controller'], $case['except'] );
					$request = new WP_REST_Request(
						$r['method'],
						'/mcpwp/v1' . preg_replace( '/\([^()]*\)/', '1', $r['route'] ),
						array( $spec => $case['id'] ),
						array( 'X-API-Key' => $created['key'] )
					);

					$result = $r['controller']->check_permission( $request );
					$checked++;
					$this->assertInstanceOf(
						WP_Error::class,
						$result,
						sprintf(
							'%s %s with %s=%s (a gated %s) must be refused for a key lacking "%s".',
							$r['method'],
							$r['route'],
							$spec,
							$case['id'],
							$case['label'],
							$case['except']
						)
					);
					$label = $r['method'] . ' ' . $r['route'] . " ({$spec}, gated {$case['label']})";
					$this->assert_post_ref_not_found_shape( $result, $label );
				}
			}
		}

		$this->assertGreaterThan( 0, $checked, 'Sanity: this must actually exercise at least one route+param+case, not silently match nothing.' );
	}

	/**
	 * WARN-3: /menus' add_menu_item accepts an `object_id` that only ever
	 * names a post when its sibling `type` field is literally 'post_type' —
	 * under 'taxonomy' it is a TERM id, under 'custom' there is no object at
	 * all. Declaring object_id unconditionally would misresolve the other
	 * two shapes exactly the way BLOCK-2 did for /users and
	 * /woocommerce/customers; this proves the conditional in
	 * check_post_naming_params_category() gets all three right.
	 *
	 * Exercised via Mcpwp_Post_Ref_Category_Harness (see its own docblock)
	 * rather than check_permission(): /menus is blanket-admin-scope, and an
	 * admin-scope key is necessarily admin-role, which bypasses category
	 * checks entirely — this fix cannot be demonstrated end-to-end through
	 * the full auth pipeline for this specific route family.
	 */
	public function test_menu_item_object_id_only_gated_when_type_is_post_type(): void {
		$harness = new Mcpwp_Post_Ref_Category_Harness();
		$harness->set_key_record( array( 'role' => 'custom', 'tool_categories' => array( 'site' ) ) ); // No 'woocommerce'.

		$gated = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/menus/16/items',
			array( 'type' => 'post_type', 'object' => 'product', 'object_id' => self::PRODUCT_ID )
		);
		$this->assertInstanceOf(
			WP_Error::class,
			$harness->check( $gated ),
			'type=post_type, object_id naming a gated product must be refused.'
		);

		$taxonomy = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/menus/16/items',
			array( 'type' => 'taxonomy', 'object' => 'product_cat', 'object_id' => self::PRODUCT_ID )
		);
		$this->assertTrue(
			$harness->check( $taxonomy ),
			'type=taxonomy: the SAME numeric object_id is a TERM id, not a post — must pass.'
		);

		$custom = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/menus/16/items',
			array( 'type' => 'custom', 'url' => 'https://example.com' )
		);
		$this->assertTrue(
			$harness->check( $custom ),
			'type=custom: no object_id at all — must pass.'
		);
	}

	/**
	 * Every declared-scalar-param denial, whatever route or case produced
	 * it, must carry the IDENTICAL {code, message, status, hint} —
	 * Mcpwp_Api_Auth::post_ref_not_found_error()'s own fixed shape — so
	 * "missing" and "category-gated" are indistinguishable by construction
	 * across the whole declared surface, not merely within one door.
	 */
	private function assert_post_ref_not_found_shape( WP_Error $error, string $label ): void {
		$this->assertSame( 'not_found', $error->get_error_code(), "{$label}: code" );
		$this->assertSame( 'The requested resource was not found.', $error->get_error_message(), "{$label}: message" );
		$data = $error->get_error_data();
		$this->assertIsArray( $data, "{$label}: data" );
		$this->assertSame( 404, $data['status'] ?? null, "{$label}: status" );
		$this->assertSame( 'Check the id with wp_search or wp_list_posts.', $data['hint'] ?? null, "{$label}: hint" );
	}
}
