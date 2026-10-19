<?php
/**
 * #1024 / PR #1030, round-5 design (coordinator decision): every declared
 * post reference answers a gated id EXACTLY as it answers a missing one.
 *
 * Mcpwp_Api_Auth::get_post_ref_param_map() declares, per route family, which
 * params name a post. For a SCALAR declared param the guard owns both
 * outcomes — missing and hidden both get the one canonical 404
 * (post_ref_not_found_error()). For a PER-ITEM param (ids[], updates[].id,
 * ids:csv, id:item) the request is never refused for one item; each bulk
 * handler answers a hidden item with the entry it gives a missing one.
 *
 * test_every_id_shaped_route_is_declared_or_named_non_post() — coverage:
 * every registered Free+Pro route whose regex or args carry an id-shaped
 * param is declared in the map or named in the (assertSame-pinned)
 * non-post list, so a new door cannot be forgotten silently.
 *
 * test_gated_ids_answer_exactly_like_missing_ids_on_every_declared_param()
 * — parity: for every declared route/param, five hidden cases (a product
 * for a key without woocommerce; an elementor_library template for a key
 * without elementor-templates; the same template for an ADMIN key with
 * elementor-templates disabled site-wide; a shop_coupon — an
 * exclude_from_search type WP_Query 'any' used to drop; a trashed product)
 * are each dispatched the way a real request is — permission callback,
 * then the handler, through error_response_from_wp_error() — and the
 * status and full JSON body must be assertSame to a missing id's with the
 * same key, the id itself normalised out. Scalar params must moreover
 * answer the canonical 404.
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
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-seo.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-error-hints.php';

/**
 * Exposes check_post_naming_params_category() directly and lets a test set
 * an arbitrary key record without going through full auth — /menus is
 * blanket-admin-SCOPE, and an admin-scope key is necessarily admin-role,
 * which bypasses every category check; the conditional object_id rule can
 * only be exercised this way.
 */
class Mcpwp_Declared_Post_Ref_Harness {
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

final class DeclaredPostRefParityAllRoutesTest extends TestCase {

	private const PRODUCT_ID  = 88101;
	private const TEMPLATE_ID = 88102; // elementor_library 'section': not a theme location.
	private const COUPON_ID   = 88103;
	private const TRASHED_ID  = 88104;
	private const MISSING_ID  = 88999;

	/** @var \ReflectionProperty */
	private $wh_prop;
	/** @var mixed */
	private $wh_saved;
	/** @var array Baseline post table, restored before every dispatch. */
	private $baseline_posts = array();

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 0;
		$GLOBALS['mcpwp_test_is_admin']          = false;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product' );
		$GLOBALS['_mcpwp_test_meta']             = array(
			self::TEMPLATE_ID => array( '_elementor_template_type' => 'section' ),
		);

		$this->baseline_posts = array(
			self::PRODUCT_ID  => array( 'product', 'publish' ),
			self::TEMPLATE_ID => array( 'elementor_library', 'publish' ),
			self::COUPON_ID   => array( 'shop_coupon', 'publish' ),
			self::TRASHED_ID  => array( 'product', 'trash' ),
		);
		$this->reset_posts();

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

	private function reset_posts(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		foreach ( $this->baseline_posts as $id => $shape ) {
			$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
				array(
					'ID'          => $id,
					'post_type'   => $shape[0],
					'post_title'  => 'Fixture ' . $id,
					'post_status' => $shape[1],
				)
			);
		}
	}

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
		( new Mcpwp_REST_SEO( new Mcpwp_SEO() ) )->register_routes(); // A real handler: /seo/bulk's per-item path runs.
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
				$methods = is_string( $h['methods'] ?? null ) ? $h['methods'] : 'GET';
				$flat[]  = array(
					'route'      => $r['route'],
					'method'     => trim( explode( ',', $methods )[0] ),
					'controller' => $controller,
					'callback'   => $h['callback'] ?? null,
					'arg_names'  => is_array( $h['args'] ?? null ) ? array_keys( $h['args'] ) : array(),
				);
			}
		}
		return $flat;
	}

	/** Whether $path is $prefix or a sub-path of it (literal prefixes against a raw route regex). */
	private function prefix_matches( string $path, string $prefix ): bool {
		if ( false !== strpos( $prefix, '*' ) ) {
			// #1101 PR-3c: '*' is one path segment — a regex group in a registered route.
			return 1 === preg_match( '#^' . str_replace( '\\*', '[^/]+', preg_quote( $prefix, '#' ) ) . '(/|$)#', $path );
		}
		return $path === $prefix || 0 === strpos( $path, $prefix . '/' ) || 0 === strpos( $path, $prefix . '(' );
	}

	/** The declared specs for a registered route (first matching prefix wins, as in the guard). */
	private function declared_specs_for( string $route ): ?array {
		foreach ( Mcpwp_REST_API::get_post_ref_param_map() as $prefix => $specs ) {
			if ( $this->prefix_matches( $route, $prefix ) ) {
				return $specs;
			}
		}
		return null;
	}

	/** Param base name of a declared spec: 'updates[].id' => 'updates', 'ids:csv' => 'ids'. */
	private function spec_base( string $spec ): string {
		return (string) preg_replace( '/(\[\].*|\[\]|:csv|:item)$/', '', $spec );
	}

	private function spec_is_per_item( string $spec ): bool {
		return false !== strpos( $spec, '[]' ) || ':csv' === substr( $spec, -4 ) || ':item' === substr( $spec, -5 );
	}

	/** Request params naming $id through $spec, in the shape that spec declares. */
	private function params_for( string $spec, int $id, string $route ): array {
		if ( ':csv' === substr( $spec, -4 ) ) {
			$params = array( $this->spec_base( $spec ) => (string) $id );
		} elseif ( false !== strpos( $spec, '[].' ) ) {
			list( $list, $field ) = explode( '[].', $spec, 2 );
			$params = array( $list => array( array( $field => $id, 'title' => 'Parity' ) ) );
		} elseif ( '[]' === substr( $spec, -2 ) ) {
			$params = array( $this->spec_base( $spec ) => array( $id ) );
		} else {
			$params = array( $this->spec_base( $spec ) => $id );
		}
		if ( 0 === strpos( $route, '/elementor/find-replace' ) ) {
			$params += array( 'search' => 'Old', 'replace' => 'New', 'dry_run' => true );
		}
		return $params;
	}

	/** The concrete path: each (?P<name>...) group filled with $id when it is the declared param, else 1. */
	private function concrete_path( string $route, string $param, int $id ): string {
		return (string) preg_replace_callback(
			'/\(\?P<(\w+)>[^()]*\)/',
			function ( $m ) use ( $param, $id ) {
				return $m[1] === $param ? (string) $id : '1';
			},
			$route
		);
	}

	/** Named regex groups of a route. */
	private function regex_params( string $route ): array {
		preg_match_all( '/\(\?P<(\w+)>/', $route, $m );
		return $m[1];
	}

	/**
	 * Dispatch the way WP_REST_Server does: permission callback first (a
	 * WP_Error there IS the response), then the handler; a WP_Error from
	 * either becomes {code, message, data} with data.status as the status.
	 *
	 * @return array{0:int,1:string} Status and JSON body.
	 */
	private function dispatch( array $r, string $path, array $params, string $key ): array {
		$this->reset_posts();
		$GLOBALS['mcpwp_test_transients'] = array();
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}

		$request = new WP_REST_Request( $r['method'], '/mcpwp/v1' . $path, $params, array( 'X-API-Key' => $key ) );
		try {
			$result = $r['controller']->check_permission( $request );
			if ( true === $result && is_callable( $r['callback'] ) ) {
				$result = call_user_func( $r['callback'], $request );
			}
		} catch ( \Throwable $e ) {
			return array( 599, 'threw ' . get_class( $e ) );
		}

		if ( $result instanceof WP_Error ) {
			$data   = $result->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;
			return array(
				$status,
				(string) wp_json_encode(
					array(
						'code'    => $result->get_error_code(),
						'message' => $result->get_error_message(),
						'data'    => $data,
					)
				),
			);
		}
		if ( $result instanceof WP_REST_Response ) {
			return array( (int) $result->get_status(), (string) wp_json_encode( $result->get_data() ) );
		}
		return array( 200, (string) wp_json_encode( $result ) );
	}

	/** Replace the id (as a bare number, whatever surrounds it) with a marker. */
	private function normalise( array $answer, int $id ): array {
		return array( $answer[0], (string) preg_replace( '/(?<!\d)' . $id . '(?!\d)/', '#ID#', $answer[1] ) );
	}

	/** A 'custom'-role key holding every known category except $except. */
	private function key_without( object $controller, string $except ): string {
		$all = array_keys( $controller->get_all_tool_category_labels() );
		return $controller->create_scoped_api_key( 'scoped', array( 'read', 'write', 'admin' ), 'custom', array_values( array_diff( $all, array( $except ) ) ) )['key'];
	}

	private function admin_key( object $controller ): string {
		return $controller->create_scoped_api_key( 'admin', array( 'read', 'write', 'admin' ), 'admin' )['key'];
	}

	/**
	 * Coverage: every route carrying an id-shaped param is either declared
	 * in the map or on the non-post list — and the non-post list itself is
	 * pinned, so moving a route onto it is a deliberate, reviewed change.
	 */
	public function test_every_id_shaped_route_is_declared_or_named_non_post(): void {
		$routes = $this->register_every_known_route_flat();
		$this->assertGreaterThan( 100, count( $routes ), 'Sanity: this must enumerate the whole plugin.' );

		$this->assertSame(
			array(
				'/users',
				'/woocommerce/customers',
				'/woocommerce/orders',
				'/woocommerce/products/categories',
				'/woocommerce/products/tags',
				'/learnpress/categories',
				'/terms',
				'/categories',
				'/tags',
				'/menus',
				'/events',
				'/webhooks',
				'/api-keys',
				'/design-references',
				'/site-blueprints',
				'/approvals',
				'/action-log',
				'/sidebars',
				'/widgets',
				'/staging',
				'/forms',
				'/graph',
				'/integrations/stock-photos',
				'/integrations/text-to-speech',
				'/figma',
			),
			Mcpwp_REST_API::get_non_post_id_route_prefixes(),
			'The non-post list is pinned: a route moves onto it only by a reviewed change to this test.'
		);

		$id_shaped = '/^(id|ids|[a-z_]*_id|[a-z_]*_ids)$/';
		$uncovered = array();
		foreach ( $routes as $r ) {
			if ( '/mcp' === $r['route'] || '/batch' === $r['route'] ) {
				continue; // Envelopes: each sub-request re-enters the guard with its real route.
			}
			$names = array_merge( $r['arg_names'], $this->regex_params( $r['route'] ) );
			if ( ! preg_grep( $id_shaped, $names ) ) {
				continue;
			}

			$covered = null !== $this->declared_specs_for( $r['route'] );
			foreach ( Mcpwp_REST_API::get_non_post_id_route_prefixes() as $prefix ) {
				$covered = $covered || $this->prefix_matches( $r['route'], $prefix );
			}
			if ( ! $covered ) {
				$uncovered[] = $r['method'] . ' ' . $r['route'] . ' [' . implode( ',', preg_grep( $id_shaped, $names ) ) . ']';
			}
		}

		$this->assertSame(
			array(),
			array_values( array_unique( $uncovered ) ),
			'Routes carrying an id-shaped param but declared nowhere — add them to get_post_ref_param_map() (a post reference) or get_non_post_id_route_prefixes() (not one).'
		);
	}

	/**
	 * Parity over the WHOLE declared surface: see this file's docblock.
	 */
	public function test_gated_ids_answer_exactly_like_missing_ids_on_every_declared_param(): void {
		$routes = $this->register_every_known_route_flat();

		$cases = array(
			'product'                    => array( self::PRODUCT_ID, 'without', 'woocommerce' ),
			'elementor_library'          => array( self::TEMPLATE_ID, 'without', 'elementor-templates' ),
			'elementor_library/disabled' => array( self::TEMPLATE_ID, 'disabled', 'elementor-templates' ),
			'shop_coupon'                => array( self::COUPON_ID, 'without', 'woocommerce' ),
			'trashed product'            => array( self::TRASHED_ID, 'without', 'woocommerce' ),
		);

		// One key per case, minted once: verification walks every stored key,
		// so a key per request would make this sweep quadratic.
		$minter = $routes[0]['controller'];
		$keys   = array();
		foreach ( $cases as $label => $case ) {
			$keys[ $label ] = 'disabled' === $case[1] ? $this->admin_key( $minter ) : $this->key_without( $minter, $case[2] );
		}
		// Same keys, cheaper bcrypt cost: thousands of verifications at the
		// default cost would dominate the run without testing anything more.
		$stored = get_option( 'mcpwp_api_keys', array() );
		foreach ( $stored as $i => $record ) {
			foreach ( $keys as $plain ) {
				if ( isset( $record['hash'] ) && password_verify( $plain, $record['hash'] ) ) {
					$stored[ $i ]['hash'] = password_hash( $plain, PASSWORD_BCRYPT, array( 'cost' => 4 ) );
				}
			}
		}
		update_option( 'mcpwp_api_keys', $stored );

		$tally    = array_fill_keys( array_keys( $cases ), array( 0, 0 ) );
		$failures = array();
		$scalars  = 0;

		foreach ( $routes as $r ) {
			if ( '/mcp' === $r['route'] || '/batch' === $r['route'] ) {
				continue;
			}
			$specs = $this->declared_specs_for( $r['route'] );
			if ( empty( $specs ) ) {
				continue;
			}
			$names = array_merge( $r['arg_names'], $this->regex_params( $r['route'] ) );

			foreach ( $specs as $declared_spec ) {
				// #1101 PR-3c: the class suffix is the guard's to parse.  A
				// '@read-user' ref is checked for user-run principals only —
				// this test drives API keys, which it deliberately does not
				// reach (OAuthPostRefAudit1101cTest covers those).
				list( $spec, $class ) = Mcpwp_REST_API::post_ref_spec_parts( $declared_spec );
				if ( 'read-user' === $class ) {
					continue;
				}
				$base = $this->spec_base( $spec );
				// The spec applies where the route actually takes that param —
				// or, for a per-item spec, on an args-less bulk route itself.
				$applies = in_array( $base, $names, true )
					|| ( $this->spec_is_per_item( $spec ) && empty( $r['arg_names'] ) && array_key_exists( $r['route'], Mcpwp_REST_API::get_post_ref_param_map() ) );
				if ( ! $applies ) {
					continue;
				}

				foreach ( $cases as $label => $case ) {
					list( $gated_id, $mode, $category ) = $case;

					update_option( 'mcpwp_disabled_tool_categories', 'disabled' === $mode ? array( $category ) : array() );
					$key = $keys[ $label ];

					$gated   = $this->dispatch( $r, $this->concrete_path( $r['route'], $base, $gated_id ), $this->params_for( $spec, $gated_id, $r['route'] ), $key );
					$missing = $this->dispatch( $r, $this->concrete_path( $r['route'], $base, self::MISSING_ID ), $this->params_for( $spec, self::MISSING_ID, $r['route'] ), $key );

					$tally[ $label ][1]++;
					$where = sprintf( '%s %s (%s, %s)', $r['method'], $r['route'], $spec, $label );

					if ( $this->normalise( $gated, $gated_id ) === $this->normalise( $missing, self::MISSING_ID ) ) {
						$tally[ $label ][0]++;
					} else {
						$failures[] = $where . "\n    gated:   " . $gated[0] . ' ' . $gated[1] . "\n    missing: " . $missing[0] . ' ' . $missing[1];
					}

					// A scalar declared param is the guard's to answer: missing
					// (and so gated) is the canonical 404 — unless the route
					// itself refused the key first (both then get that answer).
					if ( ! $this->spec_is_per_item( $spec ) && 404 === $missing[0] ) {
						$scalars++;
						$this->assertSame(
							wp_json_encode(
								array(
									'code'    => 'not_found',
									'message' => 'The requested resource was not found.',
									'data'    => array(
										'status' => 404,
										'hint'   => 'Check the id with wp_search or wp_list_posts.',
									),
								)
							),
							$missing[1],
							$where . ': a missing declared scalar id gets the canonical error.'
						);
					}
				}
			}
		}

		update_option( 'mcpwp_disabled_tool_categories', array() );

		$summary = array();
		foreach ( $tally as $label => $t ) {
			$summary[] = sprintf( '%s %d/%d identical', $label, $t[0], $t[1] );
		}
		fwrite( STDERR, "\n[declared post-ref parity] " . implode( '; ', $summary ) . "\n" );

		$this->assertSame( array(), $failures, "Gated and missing ids answered differently:\n" . implode( "\n", $failures ) );
		$this->assertGreaterThan( 50, $tally['product'][1], 'Sanity: the sweep must cover the declared surface, not a handful of routes.' );
		$this->assertGreaterThan( 20, $scalars, 'Sanity: scalar params must actually reach the canonical-404 check.' );
	}

	/**
	 * BLOCK-1 (round 4): the canonical error must leave a handler exactly
	 * as the permission layer sends it. error_response_from_wp_error() used
	 * to rebuild it and let Mcpwp_Error_Hints swap in its id-echoing
	 * 'not_found' hint; build_not_found_error() did the same for a guard
	 * given the canonical message.
	 */
	public function test_canonical_error_is_identical_from_every_layer(): void {
		$controller = new class() extends Mcpwp_REST_API {
			public function register_routes() {
			}
			public function canonical() {
				return $this->post_ref_not_found_error();
			}
			public function via_handler() {
				return $this->error_response_from_wp_error( $this->post_ref_not_found_error() );
			}
			public function via_error_response() {
				return $this->error_response( 'not_found', self::post_ref_not_found_message(), 404, array( 'id' => 88101 ) );
			}
			public function via_guard_builder() {
				return $this->build_not_found_error( self::post_ref_not_found_message() );
			}
		};
		$shape = function ( WP_Error $e ) {
			return wp_json_encode( array( $e->get_error_code(), $e->get_error_message(), $e->get_error_data() ) );
		};

		$canonical = $shape( $controller->canonical() );
		$this->assertSame(
			wp_json_encode( array( 'not_found', 'The requested resource was not found.', array( 'status' => 404, 'hint' => 'Check the id with wp_search or wp_list_posts.' ) ) ),
			$canonical
		);
		$this->assertSame( $canonical, $shape( $controller->via_handler() ), 'error_response_from_wp_error() must pass it through.' );
		$this->assertSame( $canonical, $shape( $controller->via_error_response() ), 'error_response() must not add an id-echoing hint.' );
		$this->assertSame( $canonical, $shape( $controller->via_guard_builder() ), 'build_not_found_error() must not either.' );
	}

	/**
	 * WARN-3 (round 3): /menus' object_id names a post only when its sibling
	 * `type` is 'post_type' — under 'taxonomy' it is a TERM id, under
	 * 'custom' there is no object at all.
	 */
	public function test_menu_item_object_id_only_gated_when_type_is_post_type(): void {
		$harness = new Mcpwp_Declared_Post_Ref_Harness();
		$harness->set_key_record( array( 'role' => 'custom', 'tool_categories' => array( 'site' ) ) ); // No 'woocommerce'.

		$gated = new WP_REST_Request( 'POST', '/mcpwp/v1/menus/16/items', array( 'type' => 'post_type', 'object' => 'product', 'object_id' => self::PRODUCT_ID ) );
		$this->assertInstanceOf( WP_Error::class, $harness->check( $gated ), 'type=post_type naming a gated product must be refused.' );

		$taxonomy = new WP_REST_Request( 'POST', '/mcpwp/v1/menus/16/items', array( 'type' => 'taxonomy', 'object' => 'product_cat', 'object_id' => self::PRODUCT_ID ) );
		$this->assertTrue( $harness->check( $taxonomy ), 'type=taxonomy: the same number is a TERM id — must pass.' );

		$custom = new WP_REST_Request( 'POST', '/mcpwp/v1/menus/16/items', array( 'type' => 'custom', 'url' => 'https://example.com' ) );
		$this->assertTrue( $harness->check( $custom ), 'type=custom: no object_id at all — must pass.' );
	}

	/**
	 * BLOCK-2 (round 4): the guard resolves ids with ONE direct query — no
	 * WP_Query 'any', which drops exclude_from_search types and trash — and
	 * caps a per-item list before looking anything up.
	 */
	public function test_resolution_is_one_direct_query_and_the_cap_runs_first(): void {
		$harness = new Mcpwp_Declared_Post_Ref_Harness();
		$harness->set_key_record( array( 'role' => 'custom', 'tool_categories' => array( 'seo' ) ) );

		$GLOBALS['mcpwp_test_post_ref_queries'] = array();
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/seo/bulk',
			array( 'updates' => array( array( 'id' => self::COUPON_ID ), array( 'post_id' => self::TRASHED_ID ), array( 'id' => self::PRODUCT_ID ) ) )
		);
		$this->assertTrue( $harness->check( $request ), 'Per-item params never refuse the request.' );
		$this->assertCount( 1, $GLOBALS['mcpwp_test_post_ref_queries'], 'Every id resolves in one query.' );
		$this->assertStringContainsString( 'SELECT ID, post_type, post_status FROM', $GLOBALS['mcpwp_test_post_ref_queries'][0] );

		$GLOBALS['mcpwp_test_post_ref_queries'] = array();
		$too_many = new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/bulk', array( 'ids' => implode( ',', range( 1, 501 ) ) ) );
		$result   = $harness->check( $too_many );
		$this->assertInstanceOf( WP_Error::class, $result, 'A csv list over the cap is refused.' );
		$this->assertSame( 'too_many_ids', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_post_ref_queries'], 'The cap runs before any lookup.' );
	}
}
