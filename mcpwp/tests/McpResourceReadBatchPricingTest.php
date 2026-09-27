<?php
/**
 * #1015 adversarial review round 1, N5 — a JSON-RPC batch's whole-batch
 * spend pre-pricing (message_spend_price(), #752 F9) priced EVERY
 * resources/read at a flat 1 unit. mcpwp://page/{id} and mcpwp://brand
 * (#913) each make up to two internal dispatches (post/page data + an
 * optional Elementor/globals section), so a batch containing one of them
 * was under-priced by half its real cost — a batch whose true cost exceeded
 * the budget could still be admitted. Every other resource — the six FIXED
 * ones, and the single-route mcpwp://memory/{namespace} and
 * mcpwp://guide/{topic} proxies — is still exactly 1.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpResourceReadBatchPricingTest extends TestCase {

	/** @var array */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['_mcpwp_test_posts']            = array(
			501 => new WP_Post(
				array(
					'ID'           => 501,
					'post_type'    => 'page',
					'post_title'   => 'Pricing Fixture Page',
					'post_name'    => 'pricing-fixture-page',
					'post_status'  => 'publish',
					'post_content' => 'Body.',
				)
			),
		);
		Mcpwp_REST_MCP::reset_verdict_memo();

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'batch-pricing', array( 'admin' ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	private function budget( int $units ): void {
		add_filter( 'mcpwp_request_spend_budget', static function () use ( $units ) {
			return $units;
		} );
	}

	private function mcp_batch( array $body ) {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $this->key['key'] ), $body );
		$this->assertTrue( $controller->verify_api_key( $request ) );
		return $controller->handle_mcp( $request )->get_data();
	}

	private function read( $uri, $id ) {
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => 'resources/read', 'params' => array( 'uri' => $uri ) );
	}

	/**
	 * @dataProvider two_unit_uris
	 */
	public function test_page_and_brand_price_at_two_units( string $uri ): void {
		$this->budget( 1 );

		$data = $this->mcp_batch( array( $this->read( $uri, 1 ) ) );

		// The whole batch is refused before any dispatch — a WP_Error from
		// refuse_whole() becomes a plain array of jsonrpc error objects
		// (not wrapped in a further "result"), one per message.
		$this->assertArrayHasKey( 0, $data );
		$this->assertArrayHasKey( 'error', $data[0] );
		$this->assertSame( 'spend_budget_exceeded', $data[0]['error']['data']['code'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'Refused whole — nothing may dispatch.' );
	}

	/**
	 * @dataProvider two_unit_uris
	 */
	public function test_page_and_brand_are_admitted_at_exactly_two_units_budget( string $uri ): void {
		$this->budget( 2 );

		$data = $this->mcp_batch( array( $this->read( $uri, 1 ) ) );

		$this->assertArrayHasKey( 0, $data );
		$this->assertArrayHasKey( 'result', $data[0], 'A budget of exactly 2 must admit a 2-unit read all the way to a real result.' );
	}

	public function two_unit_uris(): array {
		return array(
			'page' => array( 'mcpwp://page/501' ),
			'brand' => array( 'mcpwp://brand' ),
		);
	}

	/**
	 * @dataProvider one_unit_uris
	 *
	 * Admission past the pre-price check is what is under test here, not
	 * whether the read itself then succeeds — a malformed page id, for
	 * instance, is correctly priced at the safe floor of 1 (no dispatch it
	 * could make costs more) but still 404s for its own, unrelated reason
	 * once admitted. So the assertion is specifically "not refused by the
	 * budget", not "no error at all".
	 */
	public function test_memory_guide_and_a_fixed_resource_still_price_at_one_unit( string $uri ): void {
		$this->budget( 1 );

		$data = $this->mcp_batch( array( $this->read( $uri, 1 ) ) );

		$this->assertArrayHasKey( 0, $data );
		$refused_by_budget = isset( $data[0]['error']['data']['code'] ) && 'spend_budget_exceeded' === $data[0]['error']['data']['code'];
		$this->assertFalse( $refused_by_budget, "{$uri} must not be refused by the spend budget at a 1-unit budget." );
	}

	public function one_unit_uris(): array {
		return array(
			'memory'      => array( 'mcpwp://memory/identity' ),
			'guide'       => array( 'mcpwp://guide/elementor' ),
			'site-info'   => array( 'mcpwp://site-info' ),
			'malformed'   => array( 'mcpwp://page/not-a-number' ),
		);
	}
}
