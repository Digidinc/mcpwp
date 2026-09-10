<?php
/**
 * Coverage for #802 — the transport and version acceptance criteria.
 *
 * The reporter's client had to special-case three things: a response that was
 * sometimes text/event-stream, an empty 200 it could not tell from "the server
 * returned nothing", and a plugin replaced under a running job with no in-band
 * signal. Pinned here:
 *
 *   - Every answer to a POST is typed application/json, whatever the client's
 *     Accept header asked for — the endpoint never streams.
 *   - A 200 always carries a body. A body-less answer is 204, and only when
 *     nothing in the request expected a response.
 *   - A request that carries an id is answered, even when its method name is
 *     one a notification would use.
 *   - Every JSON-RPC result carries `_meta.plugin_version`, so a swap between
 *     two calls is visible without re-running initialize — including on a
 *     replayed result, which must name the plugin that answered, not the one
 *     that stored it.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpTransportContractTest extends TestCase {

	/** @var array */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_wpdb_options']      = array();
		Mcpwp_REST_MCP::reset_verdict_memo();

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'transport', array( 'admin' ) );

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 500,
				'requests_per_hour'   => 5000,
				'burst_limit'         => 500,
				'whitelist'           => array(),
			)
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	private function post( $body, array $headers = array(), $controller = null ): WP_REST_Response {
		$controller = $controller ?: new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array_merge( array( 'X-API-Key' => $this->key['key'] ), $headers ), $body );
		$controller->verify_api_key( $request );

		return $controller->handle_mcp( $request );
	}

	private function rpc( string $method, $id = 1, array $params = array() ): array {
		$message = array( 'jsonrpc' => '2.0', 'method' => $method );
		if ( null !== $id ) {
			$message['id'] = $id;
		}
		if ( ! empty( $params ) ) {
			$message['params'] = $params;
		}
		return $message;
	}

	private function controller_at_version( string $version ): Mcpwp_REST_MCP {
		$controller = new Mcpwp_REST_MCP();
		$property   = new ReflectionProperty( Mcpwp_REST_MCP::class, 'server_version' );
		$property->setAccessible( true );
		$property->setValue( $controller, $version );
		return $controller;
	}

	// ---------------------------------------------------------------
	// Version change detectable mid-session.
	// ---------------------------------------------------------------

	public function test_every_result_carries_the_plugin_version(): void {
		$single = $this->post( $this->rpc( 'tools/list' ) )->get_data();
		$this->assertSame( MCPWP_VERSION, $single['result']['_meta']['plugin_version'] );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$call = $this->post( $this->rpc( 'tools/call', 2, array( 'name' => 'wp_list_posts', 'arguments' => array() ) ) )->get_data();
		$this->assertSame( MCPWP_VERSION, $call['result']['_meta']['plugin_version'] );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$batch = $this->post( array( $this->rpc( 'ping', 1 ), $this->rpc( 'tools/list', 2 ), $this->rpc( 'initialize', 3 ) ) )->get_data();
		$this->assertCount( 3, $batch );
		foreach ( $batch as $response ) {
			$this->assertSame( MCPWP_VERSION, $response['result']['_meta']['plugin_version'], 'Every element of a batch, not only the first.' );
		}
	}

	public function test_a_plugin_swap_between_two_calls_is_visible_in_band(): void {
		$before = $this->post( $this->rpc( 'tools/list' ), array(), $this->controller_at_version( '3.11.0' ) );
		Mcpwp_REST_MCP::reset_verdict_memo();
		$after = $this->post( $this->rpc( 'tools/list' ), array(), $this->controller_at_version( '3.11.1' ) );

		$this->assertSame( '3.11.0', $before->get_data()['result']['_meta']['plugin_version'] );
		$this->assertSame( '3.11.1', $after->get_data()['result']['_meta']['plugin_version'] );
		$this->assertSame( $after->get_data()['result']['_meta']['plugin_version'], $after->get_headers()['X-MCPWP-Version'], 'Body and header agree.' );
	}

	public function test_a_replayed_result_names_the_plugin_that_answered_not_the_one_that_stored_it(): void {
		$call = $this->rpc( 'tools/call', 1, array( 'name' => 'wp_create_page', 'arguments' => array( 'title' => 'A' ), '_meta' => array( 'request_id' => 'swap:1' ) ) );
		$this->post( $call, array(), $this->controller_at_version( '3.11.0' ) );

		// Whatever the stored copy says about versions, the answer is from now.
		$store_key = Mcpwp_REST_MCP::idempotency_key( $this->key['id'], 'swap:1' );
		$stored    = get_transient( $store_key );
		$this->assertIsArray( $stored, 'Test premise: the first call was pinned.' );
		$stored['response']['result']['_meta']['plugin_version'] = '3.11.0';
		set_transient( $store_key, $stored, DAY_IN_SECONDS );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$replay = $this->post( $call, array(), $this->controller_at_version( '3.11.1' ) )->get_data();

		$this->assertTrue( $replay['result']['_meta']['replayed'] );
		$this->assertSame( '3.11.1', $replay['result']['_meta']['plugin_version'] );
	}

	// ---------------------------------------------------------------
	// JSON in, JSON out; never an empty 200.
	// ---------------------------------------------------------------

	public function body_provider(): array {
		return array(
			'single request'                 => array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ),
			'batch of requests'              => array( array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ), array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ) ) ),
			'request without an id'          => array( array( 'jsonrpc' => '2.0', 'method' => 'ping' ) ),
			'unknown method'                 => array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope/nothing' ) ),
			'not json at all'                => array( null ),
			'empty batch'                    => array( array() ),
			'batch of scalars'               => array( array( 1, 'x' ) ),
			'notification beside a request'  => array( array( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), array( 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'ping' ) ) ),
			'single notification'            => array( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ),
			'notifications-only batch'       => array( array( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), array( 'jsonrpc' => '2.0', 'method' => 'notifications/cancelled' ) ) ),
			'initialized carrying an id'     => array( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'notifications/initialized' ) ),
		);
	}

	/**
	 * @dataProvider body_provider
	 */
	public function test_a_post_is_answered_as_json_and_a_200_is_never_empty( $body ): void {
		foreach ( array( 'application/json, text/event-stream', 'text/event-stream' ) as $accept ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
			$response = $this->post( $body, array( 'Accept' => $accept ) );

			$this->assertSame( 'application/json; charset=UTF-8', $response->get_headers()['Content-Type'], 'Asking for a stream still gets JSON: the endpoint never streams.' );

			if ( 204 === $response->get_status() ) {
				$this->assertNull( $response->get_data(), 'A 204 has no body.' );
				continue;
			}

			$this->assertSame( 200, $response->get_status() );
			$this->assertNotEmpty( $response->get_data(), 'An empty 200 is indistinguishable from a server that returned nothing.' );
		}
	}

	public function test_only_a_request_that_expects_nothing_gets_a_204(): void {
		$this->assertSame( 204, $this->post( $this->rpc( 'notifications/initialized', null ) )->get_status() );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->assertSame( 204, $this->post( array( $this->rpc( 'notifications/initialized', null ), $this->rpc( 'notifications/cancelled', null ) ) )->get_status() );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$mixed = $this->post( array( $this->rpc( 'notifications/initialized', null ), $this->rpc( 'ping', 9 ) ) );
		$this->assertSame( 200, $mixed->get_status() );
		$this->assertCount( 1, $mixed->get_data(), 'The notification is not answered; the request is.' );
		$this->assertSame( 9, $mixed->get_data()[0]['id'] );
	}

	public function test_a_request_carrying_an_id_is_answered_even_under_a_notification_method_name(): void {
		// JSON-RPC: a message with an id is a request, and a request is answered.
		// Dropping it leaves a client waiting on id 5 forever.
		$single = $this->post( $this->rpc( 'notifications/initialized', 5 ) );
		$this->assertSame( 200, $single->get_status() );
		$this->assertSame( 5, $single->get_data()['id'] );
		$this->assertArrayHasKey( 'result', $single->get_data() );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$batch = $this->post( array( $this->rpc( 'notifications/initialized', 5 ), $this->rpc( 'notifications/initialized', null ) ) );
		$this->assertSame( 200, $batch->get_status() );
		$this->assertCount( 1, $batch->get_data() );
		$this->assertSame( 5, $batch->get_data()[0]['id'] );
	}

	public function test_an_answered_notification_method_has_an_object_result_before_any_decoration(): void {
		// The wire shape must not depend on a later decorator adding _meta: an
		// empty PHP array serialises as [], and MCP clients expect an object.
		$process = new ReflectionMethod( Mcpwp_REST_MCP::class, 'process_single_request' );
		$process->setAccessible( true );
		$raw = $process->invoke( new Mcpwp_REST_MCP(), $this->rpc( 'notifications/initialized', 5 ), new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' ) );

		$this->assertSame( '{}', wp_json_encode( $raw['result'] ) );
	}

	public function test_the_get_health_check_is_json_too(): void {
		$controller = new Mcpwp_REST_MCP();
		$response   = $controller->handle_mcp_get( new WP_REST_Request( 'GET', '/mcpwp/v1/mcp', array(), array( 'Accept' => 'text/event-stream' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'application/json; charset=UTF-8', $response->get_headers()['Content-Type'] );
		$this->assertNotEmpty( $response->get_data() );
	}
}
