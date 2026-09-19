<?php
/**
 * RC 3.12.1 adversarial review, WARN — the test suite structurally cannot
 * catch this class of bug on its own, so pin the one relationship that
 * actually prevents it.
 *
 * tests/bootstrap.php's WP_REST_Request stub has no has_valid_params(), no
 * sanitize_params(), and no concept of a route's registered 'args'/'enum' at
 * all (see its class, ~line 222) — every *RequiresAdminScopeTest builds a
 * request with clean, pre-built params and calls
 * get_required_scope_for_request() directly, so those tests can never model
 * the real WP_REST_Server dispatch order (sanitize_params() -> permission
 * callback -> has_valid_params() -> callback) that is WHY five routes were
 * safe by construction (an enum rejects an out-of-range value before the
 * gate or the handler ever sees it) and five were not (no schema at all).
 * Teaching the stub the full WP_REST_Server dispatch pipeline is a much
 * larger undertaking than this fix, and would still only prove the CURRENT
 * five schemas are correct — it would not stop a SIXTH route from being
 * added later with no schema at all, which is the actual root cause BLOCK 1
 * traces back to.
 *
 * What this file pins instead: every route get_required_scope_for_request()
 * treats as "conditionally admin-gated based on a caller-supplied 'status'"
 * (PublishStatusHandlerAuditTest::ROUTE_SCOPE_CASES, reused here via
 * gated_route_scope_cases() rather than duplicated) must, in its OWN
 * register_routes(), declare an 'enum' on that 'status' arg for the write
 * method under test. If a future refactor ever adds a new $publish_gated_routes
 * entry without also adding the matching schema (or removes a schema while
 * leaving the route in the gate table), this test fails immediately —
 * closing exactly the gap that let BLOCK 1 ship: the gate function and the
 * route schemas silently disagreeing with no test able to notice.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PublishStatusHandlerAuditTest.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-drafts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-learnpress.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-events.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';

final class PublishGateSchemaInvariantTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();

		// Every controller that owns at least one route named in
		// PublishStatusHandlerAuditTest::ROUTE_SCOPE_CASES. None of these
		// constructors touch their handler beyond storing it, and
		// register_routes() never calls into it either -- null is safe,
		// mirroring the same pattern WooCommerceArchetypeApplyRequiresAdminScopeTest
		// and AdditionalPublishSurfacesRequireAdminScopeTest already use.
		( new Mcpwp_REST_Posts() )->register_routes();
		( new Mcpwp_REST_Pages() )->register_routes();
		( new Mcpwp_REST_Elementor() )->register_routes();
		( new Mcpwp_REST_LearnPress( null ) )->register_routes();
		( new Mcpwp_REST_Events( null ) )->register_routes();
		( new Mcpwp_REST_Elementor_Pro( null ) )->register_routes();
		( new Mcpwp_REST_WooCommerce( null ) )->register_routes();
	}

	/**
	 * Build the full, anchored regex WP_REST_Server would match a concrete
	 * request route against, from what register_rest_route() recorded.
	 *
	 * @param array $registered One entry from $GLOBALS['_mcpwp_test_registered_routes'].
	 * @return string
	 */
	private function full_route_pattern( array $registered ): string {
		$namespace = trim( (string) $registered['namespace'], '/' );
		$route     = '/' . ltrim( (string) $registered['route'], '/' );
		return '#^/' . $namespace . $route . '$#';
	}

	/**
	 * Find the 'args' schema WP would apply for $method on the concrete
	 * $route, among everything every register_routes() call above fed to
	 * register_rest_route() -- handling both the single-definition and the
	 * multi-definition (one path, several methods) shapes real controllers
	 * use.
	 *
	 * @param string $method Concrete HTTP method, e.g. 'POST'.
	 * @param string $route  Concrete request route, e.g. '/mcpwp/v1/events/1'.
	 * @return array|null The method definition's 'args' array (possibly
	 *                     empty), or null if no registered route+method matched.
	 */
	private function find_registered_args( string $method, string $route ): ?array {
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registered ) {
			if ( ! preg_match( $this->full_route_pattern( $registered ), $route ) ) {
				continue;
			}

			$defs = $registered['args'];
			// A single-method route passes one flat definition; a
			// multi-method route passes a list of them. Normalize to a list.
			if ( isset( $defs['methods'] ) ) {
				$defs = array( $defs );
			}

			foreach ( $defs as $def ) {
				if ( ! is_array( $def ) || ! isset( $def['methods'] ) ) {
					continue;
				}
				$methods = array_map( 'trim', explode( ',', (string) $def['methods'] ) );
				if ( in_array( $method, $methods, true ) ) {
					return isset( $def['args'] ) && is_array( $def['args'] ) ? $def['args'] : array();
				}
			}
		}
		return null;
	}

	/**
	 * @dataProvider gated_route_provider
	 */
	public function test_every_publish_gated_route_declares_a_status_enum_in_its_own_schema(
		string $case_name,
		string $method,
		string $route
	): void {
		$args = $this->find_registered_args( $method, $route );

		$this->assertIsArray(
			$args,
			"No registered route matched $method $route (case: $case_name). Either the route moved, or the controller that " .
			'owns it is missing from this test\'s setUp() -- add it so this invariant keeps covering the real registration.'
		);

		$this->assertArrayHasKey(
			'status',
			$args,
			"$method $route (case: $case_name) is in get_required_scope_for_request()'s \$publish_gated_routes / dynamic-regex " .
			"gate list but its OWN register_routes() declares no 'status' arg at all. This is exactly BLOCK 1's root cause: " .
			"without a declared arg, WordPress's has_valid_params() never runs, and the gate is the ONLY thing standing " .
			'between a write-scoped key and a handler that will normalize an out-of-range value into \'publish\'/\'private\' ' .
			'anyway. Add an \'enum\' => array( \'draft\', \'pending\', \'private\', \'publish\' ) (or the handler\'s real ' .
			"accepted subset) to this route's args."
		);

		$this->assertArrayHasKey(
			'enum',
			$args['status'],
			"$method $route (case: $case_name) declares a 'status' arg but no 'enum' on it -- WordPress will accept ANY " .
			"string, so this route is exactly as unprotected as if the arg were absent. Add an 'enum'."
		);

		$this->assertNotEmpty(
			$args['status']['enum'],
			"$method $route (case: $case_name) declares 'enum' on 'status' but it is empty."
		);

		$this->assertContains(
			'publish',
			$args['status']['enum'],
			"$method $route (case: $case_name) is gated specifically because it can reach 'publish' -- its own enum must " .
			"actually include 'publish', or the enum is not the thing making this route safe."
		);
	}

	public function gated_route_provider(): array {
		$cases = array();
		foreach ( PublishStatusHandlerAuditTest::gated_route_scope_cases() as $name => $case ) {
			// [method, route, base_params] -> this test only needs method+route.
			$cases[ $name ] = array( $name, $case[0], $case[1] );
		}
		return $cases;
	}

	/**
	 * Sanity check on the helper itself: a route/method with NO enum at all
	 * (a bare 'args' array without 'status') is correctly reported as
	 * missing the key, not silently passed. This guards against a future
	 * edit to find_registered_args()/the assertions above accidentally
	 * turning "missing" into "vacuously present".
	 */
	public function test_helper_reports_missing_status_arg_as_a_failure_not_a_pass(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array(
			array(
				'namespace' => 'mcpwp/v1',
				'route'     => '/example',
				'args'      => array(
					'methods'             => 'POST',
					'callback'            => '__return_true',
					'permission_callback' => '__return_true',
					'args'                => array(
						'title' => array( 'type' => 'string' ),
					),
				),
			),
		);

		$args = $this->find_registered_args( 'POST', '/mcpwp/v1/example' );
		$this->assertIsArray( $args );
		$this->assertArrayNotHasKey( 'status', $args );
	}
}
