<?php

use PHPUnit\Framework\TestCase;

// Not pulled in by the main bootstrap (it's 3000+ lines) — same pattern as
// unit/test-rest-seo-audit-keyword-enrichment.php.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-seo-audit.php';

/**
 * REST-level round trip for GET /seo/flags (wp_seo_flags).
 */
final class RestSeoFlagsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function make_request( $args = array() ) {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/seo/flags' );
		foreach ( $args as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return $request;
	}

	public function test_no_import_returns_success_with_next_step() {
		$controller = new Mcpwp_REST_SEO_Audit();
		$response   = $controller->get_seo_flags( $this->make_request() );
		$data       = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'next_step', $data );
		foreach ( $data['flags'] as $items ) {
			$this->assertSame( array(), $items );
		}
	}

	public function test_imported_rows_surface_in_flags() {
		Mcpwp_Search_Performance::import_rows(
			array(
				'provider' => 'manual',
				'rows'     => array(
					array(
						'date'        => '2026-09-20',
						'url'         => 'https://example.com/pricing',
						'query'       => 'widget pricing plans',
						'clicks'      => 1,
						'impressions' => 200,
						'position'    => 8.0,
					),
				),
			)
		);

		$controller = new Mcpwp_REST_SEO_Audit();
		$response   = $controller->get_seo_flags( $this->make_request() );
		$data       = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$queries = array_column( $data['flags']['almost_there'], 'query' );
		$this->assertContains( 'widget pricing plans', $queries );
		$this->assertNotEmpty( $data['last_import_at'] );
	}

	public function test_days_and_limit_params_are_honored() {
		Mcpwp_Search_Performance::import_rows(
			array(
				'provider' => 'manual',
				'rows'     => array(
					array(
						'date'        => '2026-09-20',
						'url'         => 'https://example.com/a',
						'query'       => 'some query here',
						'clicks'      => 1,
						'impressions' => 200,
						'position'    => 8.0,
					),
				),
			)
		);

		$controller = new Mcpwp_REST_SEO_Audit();
		$response   = $controller->get_seo_flags( $this->make_request( array( 'days' => 7, 'limit' => 5 ) ) );
		$data       = $response->get_data();

		$this->assertSame( 7, $data['filters']['days'] );
		$this->assertSame( 5, $data['filters']['limit'] );
	}
}
