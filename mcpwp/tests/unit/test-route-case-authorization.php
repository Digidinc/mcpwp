<?php
/**
 * Regression tests — route case must not change an authorization decision.
 *
 * WordPress compiles REST route patterns with `@^…$@i` and never rewrites
 * $request->get_route(). Every case-sensitive comparison in the auth trait
 * therefore missed a mixed-case spelling of a route while WordPress still
 * dispatched it to the real handler.
 *
 * Verified against a live install before the fix: `POST /mcpwp/v1/Api-Keys`
 * resolved to the write default instead of admin, so a write-scoped key could
 * mint a key with explicit ["read","write","admin"] scopes and then read
 * /plugin-settings (HTTP 200). Same trick reached /Plugin-Settings, /Options
 * and /Update.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * Harness: exposes the trait's protected scope resolver.
 */
class Mcpwp_Route_Case_Auth_Harness {
	use Mcpwp_Api_Auth;

	/** Resolve the scope the trait would require for a request. */
	public function required_scope( string $method, string $route ): string {
		return $this->get_required_scope_for_request( new WP_REST_Request( $method, $route ) );
	}
}

final class RouteCaseAuthorizationTest extends TestCase {

	private Mcpwp_Route_Case_Auth_Harness $auth;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();

		$this->auth = new Mcpwp_Route_Case_Auth_Harness();
	}

	/**
	 * Routes whose spelling must not change the required scope.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function adminRouteProvider(): array {
		return array(
			'api key creation'   => array( 'POST', '/mcpwp/v1/api-keys', 'admin' ),
			'plugin settings'    => array( 'GET', '/mcpwp/v1/plugin-settings', 'admin' ),
			'options write'      => array( 'POST', '/mcpwp/v1/options', 'admin' ),
			'plugin update'      => array( 'POST', '/mcpwp/v1/update', 'admin' ),
			'approval apply'     => array( 'POST', '/mcpwp/v1/approvals/42/apply', 'admin' ),
			'blueprint deploy'   => array( 'POST', '/mcpwp/v1/site-blueprints/abc/deploy', 'admin' ),
			'figma token sync'   => array( 'POST', '/mcpwp/v1/figma/design-tokens/sync', 'admin' ),
			'provider key write' => array( 'POST', '/mcpwp/v1/integrations/providers/openai/key', 'admin' ),
		);
	}

	/**
	 * @dataProvider adminRouteProvider
	 */
	public function test_mixed_case_route_requires_the_same_scope_as_lower_case(
		string $method,
		string $route,
		string $expected
	): void {
		$this->assertSame( $expected, $this->auth->required_scope( $method, $route ), 'baseline' );

		foreach ( array( strtoupper( $route ), ucwords( $route, '/-' ) ) as $variant ) {
			// Only the namespace prefix stays lower-cased in the ucwords variant;
			// WordPress would dispatch every one of these to the same handler.
			$this->assertSame(
				$expected,
				$this->auth->required_scope( $method, $variant ),
				sprintf( 'Route spelling "%s" must not lower the required scope.', $variant )
			);
		}
	}

	public function test_ascii_fold_does_not_depend_on_the_c_locale(): void {
		// strtolower() is locale-sensitive below PHP 8.2: under tr_TR it maps "I"
		// to a dotless "ı", which matches no route prefix and reopens the hole.
		$previous = setlocale( LC_CTYPE, 0 );
		$applied  = setlocale( LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR', 'turkish' );

		if ( false === $applied ) {
			$this->markTestSkipped( 'Turkish locale not available in this container.' );
		}

		try {
			$this->assertSame( 'admin', $this->auth->required_scope( 'POST', '/mcpwp/v1/API-KEYS' ) );
		} finally {
			setlocale( LC_CTYPE, $previous );
		}
	}

	public function test_read_routes_are_not_escalated_by_the_fold(): void {
		$this->assertSame( 'read', $this->auth->required_scope( 'GET', '/mcpwp/v1/pages' ) );
		$this->assertSame( 'write', $this->auth->required_scope( 'POST', '/mcpwp/v1/pages' ) );
	}
}
