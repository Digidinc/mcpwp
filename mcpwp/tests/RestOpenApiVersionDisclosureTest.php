<?php

use PHPUnit\Framework\TestCase;

/**
 * GET /openapi.json — pre-auth version disclosure (#607 invariant 1).
 *
 * `/openapi.json` is registered with `permission_callback => '__return_true'`
 * (see Mcpwp_REST_OpenAPI::register_routes()) — it is reachable by anyone,
 * authenticated or not. The rest of the codebase treats the exact installed
 * plugin version as sensitive on every OTHER open route: Mcpwp_REST_MCP's
 * OPTIONS handler explicitly withholds the `X-MCPWP-Version` header on the
 * unauthenticated path ("an exact version on an open route is a scanner's
 * oracle" — see Mcpwp_REST_MCP::prepare_mcp_response()). Before this fix,
 * `/openapi.json` broke that pattern by echoing `MCPWP_VERSION` straight into
 * `info.version`, letting an anonymous caller fingerprint the exact plugin
 * build (and target version-specific CVEs) with zero authentication.
 *
 * These tests lock the fix in: the schema must never contain the literal
 * MCPWP_VERSION string, regardless of what that constant is set to.
 *
 * @package MCPWP
 */

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-openapi.php';

final class RestOpenApiVersionDisclosureTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
	}

	private function controller() {
		return new Mcpwp_REST_OpenAPI();
	}

	public function test_route_is_registered_publicly(): void {
		$ctrl = $this->controller();
		$ctrl->register_routes();

		$registered = null;
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $entry ) {
			if ( 'mcpwp/v1' === $entry['namespace'] && '/openapi.json' === $entry['route'] ) {
				$registered = $entry;
				break;
			}
		}

		$this->assertNotNull( $registered, 'Expected /openapi.json to be registered.' );
		$this->assertSame( '__return_true', $registered['args'][0]['permission_callback'] );
	}

	public function test_schema_does_not_echo_the_installed_plugin_version(): void {
		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/openapi.json' );
		$response = $ctrl->get_openapi_schema( $request );

		$data = $response->get_data();

		$this->assertArrayHasKey( 'info', $data );
		$this->assertArrayHasKey( 'version', $data['info'] );
		$this->assertNotSame(
			MCPWP_VERSION,
			$data['info']['version'],
			'openapi.json is a public (__return_true) route — it must not disclose the exact installed plugin version to an unauthenticated caller.'
		);
	}

	public function test_schema_version_field_is_a_stable_document_version_not_a_build_fingerprint(): void {
		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/openapi.json' );
		$response = $ctrl->get_openapi_schema( $request );

		$data = $response->get_data();

		// Locked to a literal so a future edit that quietly reintroduces
		// MCPWP_VERSION (or any other build-identifying value) here fails
		// this test rather than only the assertNotSame() above.
		$this->assertSame( '1.0.0', $data['info']['version'] );
	}

	public function test_no_leaf_value_in_the_schema_equals_the_plugin_version(): void {
		$ctrl     = $this->controller();
		$request  = new WP_REST_Request( 'GET', '/mcpwp/v1/openapi.json' );
		$response = $ctrl->get_openapi_schema( $request );

		// Exact-match leaf comparison rather than a substring search across
		// the whole encoded body: MCPWP_VERSION is a short, generic-looking
		// string ("test" in this suite) that could coincidentally appear
		// inside unrelated prose (e.g. "latest", "fastest") in a tool
		// description, which would make a substring assertion flaky rather
		// than meaningful. What actually matters is that no field VALUE is
		// the version.
		$offender = $this->find_leaf_equal_to( $response->get_data(), MCPWP_VERSION );

		$this->assertNull(
			$offender,
			"Field '{$offender}' equals MCPWP_VERSION — the public openapi.json body must not disclose the exact plugin build version."
		);
	}

	/**
	 * Recursively search $data for a leaf scalar exactly equal to $needle.
	 *
	 * @return string|null Dotted path to the offending leaf, or null if none found.
	 */
	private function find_leaf_equal_to( $data, string $needle, string $path = '$' ): ?string {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$found = $this->find_leaf_equal_to( $value, $needle, $path . '.' . $key );
				if ( null !== $found ) {
					return $found;
				}
			}
			return null;
		}

		if ( is_string( $data ) && $data === $needle ) {
			return $path;
		}

		return null;
	}
}
