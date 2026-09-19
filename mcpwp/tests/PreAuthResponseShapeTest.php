<?php
/**
 * Standing guard for #670: a permission callback must not tell an anonymous
 * caller anything about the resource it names.
 *
 * The finding was that permission callbacks ran `get_post()` existence, type and
 * status checks BEFORE verifying the API key. `verify_api_key()` answers 401 for
 * a missing key, so an anonymous caller got 404 for an id that does not exist
 * and 401 for one that does — an oracle for enumerating valid ids, telling a
 * post from a page from an attachment, and detecting drafts, including private
 * and unpublished ones. Metadata only, never content, but more than WordPress
 * core exposes and squarely against what this plugin advertises.
 *
 * The ordering is correct on main today. This is not a test of that fix — it is
 * the standing check the issue asked for, so the class cannot come back in a
 * route nobody thought about. It is therefore written as a STRUCTURAL invariant
 * over every route the loaded controllers register, not as a list of the three
 * callbacks that were originally wrong. A new route with an id parameter is
 * covered the day it is added, without anyone remembering to extend this file.
 *
 * The assertion is byte-equality of the whole response, not just the status
 * code: a 401 whose message or hint differs by target is the same oracle in a
 * quieter voice.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// This file's own controller list (CONTROLLERS below) is the thing under
// test — do not rely on some OTHER test file in the suite having already
// require_once'd a given controller by coincidence of PHPUnit's file/run
// order. tests/bootstrap.php itself only loads Mcpwp_REST_Pages,
// Mcpwp_REST_Menus, Mcpwp_REST_Media and Mcpwp_REST_Site_Updates; every
// other class in CONTROLLERS was, before this, reachable only because some
// unrelated test happened to load it first when the full suite ran —
// Mcpwp_REST_Webhooks had no such accidental loader at all and was
// discovering zero routes even in the widened list, invisibly, until
// test_the_harness_actually_found_routes_to_check() below started requiring
// a positive count per controller and caught it.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-blocks.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-webhooks.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-seo-audit.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-quality.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-library.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-taxonomy.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-blueprints.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-action-log.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-memory.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-network.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-integrations.php';

final class PreAuthResponseShapeTest extends TestCase {

	private const PUBLISHED_PAGE = 8300;
	private const DRAFT_PAGE     = 8301;
	private const PUBLISHED_POST = 8302;
	private const ATTACHMENT     = 8303;
	private const MISSING        = 999999;

	/**
	 * Put the request-scoped state back to a clean slate.
	 *
	 * Rate-limit settings and counters both live here. Another test class in
	 * this suite lowers the burst limit and does not restore it (#757's family),
	 * so this cannot assume the defaults are in force — it has to establish
	 * them.
	 */
	private function reset_request_state(): void {
		$GLOBALS['mcpwp_test_options']    = array( 'mcpwp_api_key' => 'hashed-key-value' );
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	protected function setUp(): void {
		$this->reset_request_state();

		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_meta']              = array();

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUBLISHED_PAGE => $this->post( self::PUBLISHED_PAGE, 'page', 'publish' ),
			self::DRAFT_PAGE     => $this->post( self::DRAFT_PAGE, 'page', 'draft' ),
			self::PUBLISHED_POST => $this->post( self::PUBLISHED_POST, 'post', 'publish' ),
			self::ATTACHMENT     => $this->post( self::ATTACHMENT, 'attachment', 'inherit' ),
		);

		// reset_request_state() seeds mcpwp_api_key, so the plugin is in its
		// normal configured state and the request fails for the one reason
		// under test: the caller presented no key.
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['mcpwp_test_filters']            = array();
	}

	private function post( int $id, string $type, string $status ): WP_Post {
		return new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_title'  => 'Target ' . $id,
				'post_name'   => 'target-' . $id,
				'post_status' => $status,
			)
		);
	}

	/**
	 * Every id an anonymous caller might probe with. The response must not
	 * distinguish any of them from any other.
	 *
	 * @return array<string,int>
	 */
	private function probe_ids(): array {
		return array(
			'published page' => self::PUBLISHED_PAGE,
			'draft page'     => self::DRAFT_PAGE,
			'published post' => self::PUBLISHED_POST,
			'attachment'     => self::ATTACHMENT,
			'nonexistent'    => self::MISSING,
		);
	}

	/**
	 * Every controller under includes/api/ believed to register a route with
	 * an id-shaped path parameter. Membership here is a claim, not proof —
	 * discover_id_routes() below is what proves it, by requiring every entry
	 * to actually contribute at least one discovered route
	 * (test_the_harness_actually_found_routes_to_check()). A controller
	 * added later that never appears in this array is invisible to the
	 * whole file, the same way these four were before this list grew from
	 * 16 to 20 (RC 3.12.1 review, 2026-09-19): Mcpwp_REST_Action_Log
	 * (`log_id`), Mcpwp_REST_Site_Memory (`namespace`/`key`),
	 * Mcpwp_REST_Site_Network (`name`/`topic`), Mcpwp_REST_Integrations
	 * (`provider`).
	 */
	private const CONTROLLERS = array(
		Mcpwp_REST_Pages::class,
		Mcpwp_REST_Menus::class,
		Mcpwp_REST_Posts::class,
		Mcpwp_REST_Media::class,
		Mcpwp_REST_Elementor::class,
		Mcpwp_REST_Blocks::class,
		Mcpwp_REST_Content::class,
		Mcpwp_REST_Approvals::class,
		Mcpwp_REST_Webhooks::class,
		Mcpwp_REST_SEO_Audit::class,
		Mcpwp_REST_Content_Quality::class,
		Mcpwp_REST_Site_Library::class,
		Mcpwp_REST_Site_Taxonomy::class,
		Mcpwp_REST_Site_Blueprints::class,
		Mcpwp_REST_Site_Content::class,
		Mcpwp_REST_Site_Updates::class,
		Mcpwp_REST_Action_Log::class,
		Mcpwp_REST_Site_Memory::class,
		Mcpwp_REST_Site_Network::class,
		Mcpwp_REST_Integrations::class,
	);

	/**
	 * Floor for the number of id-bearing (route, method) pairs discovery
	 * must find. Set to what discover_id_routes() actually finds today
	 * (raise it when a route is added; a drop below it means something that
	 * used to be covered stopped being found, and test_the_harness_
	 * actually_found_routes_to_check() must fail rather than pass quietly
	 * over a thinner list).
	 */
	private const MIN_EXPECTED_ROUTES = 92;

	/**
	 * Registered routes that take one or more id-shaped path parameters, as
	 * {route, method, params, callback}. One entry per (route, HTTP method)
	 * pair — a route registered for POST,PUT,PATCH under
	 * WP_REST_Server::EDITABLE yields three entries, not one silently
	 * tested as GET.
	 *
	 * @return array<int,array{route:string,method:string,params:string[],callback:callable}>
	 */
	private function id_routes(): array {
		return $this->discover_id_routes()['routes'];
	}

	/**
	 * Route discovery plus a per-controller contribution count, so the
	 * backstop test can assert not just "found something" but "every listed
	 * controller found something".
	 *
	 * Discovery matches ANY single named capture group — `(?P<name>...)` for
	 * any `name`, not the literal substring `(?P<id>` — because the id-shaped
	 * parameter is not always spelled `id`. The literal-substring version of
	 * this method listed Mcpwp_REST_Menus as covered while silently finding
	 * zero of its routes: `/menus/(?P<menu_id>\d+)` and
	 * `/menus/(?P<menu_id>\d+)/items/(?P<item_id>\d+)` never contain the
	 * substring `(?P<id>`. The same gap hid four whole controllers that were
	 * simply absent from the list (see CONTROLLERS above).
	 *
	 * @return array{routes: array<int,array{route:string,method:string,params:string[],callback:callable}>, controllers: array<class-string,int>}
	 */
	private function discover_id_routes(): array {
		$controller_counts = array_fill_keys( self::CONTROLLERS, 0 );

		foreach ( self::CONTROLLERS as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$before = count( $GLOBALS['_mcpwp_test_registered_routes'] );
			( new $class() )->register_routes();
			$after = count( $GLOBALS['_mcpwp_test_registered_routes'] );

			// Tag each route this controller just registered so contribution
			// can be counted per controller below, without changing the
			// shared register_rest_route() double that every other test file
			// in this suite also relies on.
			for ( $i = $before; $i < $after; $i++ ) {
				$GLOBALS['_mcpwp_test_registered_routes'][ $i ]['_discovered_by'] = $class;
			}
		}

		$found = array();

		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registered ) {
			$route = (string) $registered['route'];

			if ( ! preg_match_all( '/\(\?P<([A-Za-z_][A-Za-z0-9_]*)>[^)]*\)/', $route, $matches ) ) {
				continue;
			}

			$param_names = $matches[1];
			$controller  = $registered['_discovered_by'] ?? null;

			// register_rest_route() accepts one endpoint or a list of them.
			$endpoints = isset( $registered['args'][0] ) ? $registered['args'] : array( $registered['args'] );

			foreach ( $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) || ! isset( $endpoint['permission_callback'] ) ) {
					continue;
				}
				if ( ! is_callable( $endpoint['permission_callback'] ) ) {
					continue;
				}

				$methods_raw = isset( $endpoint['methods'] ) ? (string) $endpoint['methods'] : 'GET';
				$methods     = array_filter( array_map( 'trim', explode( ',', $methods_raw ) ) );

				foreach ( $methods as $method ) {
					$found[] = array(
						'route'    => $route,
						'method'   => $method,
						'params'   => $param_names,
						'callback' => $endpoint['permission_callback'],
					);

					if ( null !== $controller && isset( $controller_counts[ $controller ] ) ) {
						++$controller_counts[ $controller ];
					}
				}
			}
		}

		return array(
			'routes'      => $found,
			'controllers' => $controller_counts,
		);
	}

	/**
	 * Reduce whatever a permission callback returns to a comparable shape.
	 *
	 * Deliberately includes the message and the error data, not just the code:
	 * an oracle that answers 401 both times but varies the hint is still an
	 * oracle.
	 */
	private function describe( $result ): string {
		if ( $result instanceof WP_Error ) {
			return wp_json_encode(
				array(
					'type'    => 'WP_Error',
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'data'    => $result->get_error_data(),
				)
			);
		}

		return wp_json_encode(
			array(
				'type'  => gettype( $result ),
				'value' => $result,
			)
		);
	}

	public function test_the_harness_actually_found_routes_to_check(): void {
		$discovery = $this->discover_id_routes();

		// Without this, a refactor that renames an id parameter — or drops a
		// controller from CONTROLLERS above — turns every assertion below
		// into a vacuous pass over an empty or thinner list, the exact "test
		// passes for the wrong reason" shape this suite has been bitten by
		// before: Mcpwp_REST_Menus sat in the controller list contributing
		// zero routes, and nothing failed.
		$this->assertGreaterThanOrEqual(
			self::MIN_EXPECTED_ROUTES,
			count( $discovery['routes'] ),
			'Fewer id-bearing (route, method) pairs were discovered than expected; the invariant below would assert less than it claims to.'
		);

		// A non-empty total is not the same guarantee as "nobody on the list
		// is being silently skipped" — a controller listed in CONTROLLERS
		// that discovers zero routes (a renamed param, a route pattern the
		// regex above stops matching, a controller class that quietly stops
		// registering routes) must fail loudly here instead of just shrinking
		// the total that the assertion above still happens to clear.
		foreach ( $discovery['controllers'] as $class => $count ) {
			$this->assertGreaterThan(
				0,
				$count,
				sprintf(
					'%s is listed in CONTROLLERS but contributed zero id-bearing routes — it is being silently skipped by discovery, the same way Mcpwp_REST_Menus was before this test widened its matching.',
					$class
				)
			);
		}
	}

	public function test_an_anonymous_caller_cannot_tell_targets_apart(): void {
		$routes = $this->id_routes();

		foreach ( $routes as $entry ) {
			$responses = array();

			foreach ( $this->probe_ids() as $label => $id ) {
				// Every probe starts from the same state, so the only thing that
				// varies between them is the target. Without this the rate
				// limiter — which counts requests and answers identically
				// whatever the target is — trips partway through the loop and
				// the test "finds" a difference that is its own probing, not the
				// code under test.
				$this->reset_request_state();

				// Substitute the probe id into every named parameter the route
				// declares (a route can carry more than one, e.g. Menus'
				// menu_id + item_id), not just one hardcoded as `id`.
				$path   = $entry['route'];
				$params = array();
				foreach ( $entry['params'] as $name ) {
					$path            = preg_replace( '/\(\?P<' . preg_quote( $name, '/' ) . '>[^)]*\)/', (string) $id, $path, 1 );
					$params[ $name ] = $id;
				}

				$request = new WP_REST_Request(
					$entry['method'],
					'/mcpwp/v1' . $path,
					$params
				);

				$responses[ $label ] = $this->describe( call_user_func( $entry['callback'], $request ) );
			}

			$distinct = array_unique( array_values( $responses ) );

			$this->assertCount(
				1,
				$distinct,
				sprintf(
					"%s %s answers differently depending on the target:\n%s",
					$entry['method'],
					$entry['route'],
					wp_json_encode( $responses )
				)
			);
		}
	}

	public function test_the_shared_permission_callback_refuses_before_looking_at_the_target(): void {
		// The base callback is what every route above resolves to today. Pinned
		// separately so the reason the invariant holds is itself asserted, not
		// just its consequence.
		$controller = new Mcpwp_REST_Pages();

		$existing = $controller->check_permission(
			new WP_REST_Request( 'GET', '/mcpwp/v1/pages/' . self::DRAFT_PAGE, array( 'id' => self::DRAFT_PAGE ) )
		);
		$missing = $controller->check_permission(
			new WP_REST_Request( 'GET', '/mcpwp/v1/pages/' . self::MISSING, array( 'id' => self::MISSING ) )
		);

		$this->assertInstanceOf( WP_Error::class, $existing );
		$this->assertSame( 'missing_api_key', $existing->get_error_code(), 'The refusal must be about the credential, never about the target.' );
		$this->assertSame( $this->describe( $existing ), $this->describe( $missing ) );
	}
}
