<?php

use PHPUnit\Framework\TestCase;

/**
 * The discovery documents as actually served — status, headers and body bytes.
 *
 * BrokerWellKnownTest asserts on build_protected_resource_metadata(), which a
 * correct builder can pass while the serving path (maybe_serve_well_known() →
 * emit_json()) still sends something else. The dispatcher exit()s after
 * answering, so each request is served by a throwaway process
 * (PHP built-in web server, support/well-known-router.php) and the status,
 * headers and body are read off a real HTTP response.
 *
 * #1089 item 3. This does not exercise authorization or scope behaviour.
 *
 * @package MCPWP
 */
final class WellKnownServedBytesTest extends TestCase {

	/** @var resource|null */
	private $server = null;

	private string $options_file = '';

	private int $port = 0;

	protected function tearDown(): void {
		if ( is_resource( $this->server ) ) {
			proc_terminate( $this->server );
			proc_close( $this->server );
		}
		$this->server = null;
		if ( '' !== $this->options_file && file_exists( $this->options_file ) ) {
			unlink( $this->options_file );
		}
	}

	private function broker_options( array $settings ): array {
		return array(
			'mcpwp_broker_enrollment' => array(
				'site_id'         => 'abc',
				'audience'        => 'https://example.com',
				'site_credential' => 'x',
			),
			'mcpwp_settings'          => $settings,
		);
	}

	/**
	 * GET $path from PHP's built-in web server running the real dispatcher.
	 *
	 * @return array{served:bool,status:int,headers:string[],raw:string,json:mixed}
	 */
	private function request( string $path, array $options ): array {
		$this->options_file = tempnam( sys_get_temp_dir(), 'mcpwp-wk-' );
		file_put_contents( $this->options_file, wp_json_encode( $options ) );

		$sock = stream_socket_server( 'tcp://127.0.0.1:0' );
		$this->port = (int) substr( strrchr( (string) stream_socket_get_name( $sock, false ), ':' ), 1 );
		fclose( $sock );

		$this->server = proc_open(
			array( PHP_BINARY, '-d', 'display_errors=0', '-S', '127.0.0.1:' . $this->port, __DIR__ . '/support/well-known-router.php' ),
			array( 0 => array( 'file', '/dev/null', 'r' ), 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
			$pipes,
			__DIR__,
			array_merge( getenv(), array( 'MCPWP_WK_OPTIONS' => $this->options_file ) )
		);
		$this->assertIsResource( $this->server );

		$ctx = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'timeout' => 10 ) ) );
		$raw = false;
		for ( $i = 0; $i < 50 && false === $raw; $i++ ) {
			$raw = @file_get_contents( 'http://127.0.0.1:' . $this->port . $path, false, $ctx );
			if ( false === $raw ) {
				usleep( 100000 );
			}
		}
		$this->assertIsString( $raw, 'built-in server never answered' );

		$headers = $http_response_header; // phpcs:ignore -- set by file_get_contents().
		preg_match( '#^HTTP/\S+ (\d+)#', $headers[0], $m );

		return array(
			'served'  => 404 !== (int) $m[1] || 'not-served' !== $raw,
			'status'  => (int) $m[1],
			'headers' => $headers,
			'raw'     => $raw,
			'json'    => json_decode( $raw, true ),
		);
	}

	private function assertServedJsonHeaders( array $r ): void {
		$this->assertTrue( $r['served'] );
		$this->assertSame( 200, $r['status'] );
		$this->assertContains( 'Content-Type: application/json; charset=utf-8', $r['headers'] );
		$this->assertContains( 'Cache-Control: no-store', $r['headers'] );
		$this->assertContains( 'Access-Control-Allow-Origin: *', $r['headers'] );
	}

	public function test_broker_enrolled_site_serves_broker_metadata_on_the_wire(): void {
		$r = $this->request(
			'/.well-known/oauth-protected-resource/wp-json/mcpwp/v1/mcp',
			$this->broker_options( array( 'oauth_enabled' => false ) )
		);

		$this->assertServedJsonHeaders( $r );
		$this->assertSame(
			array(
				'resource'                 => 'https://example.com/wp-json/mcpwp/v1/mcp',
				'authorization_servers'    => array( 'https://mumcp.com' ),
				'scopes_supported'         => array( 'read', 'write' ),
				'bearer_methods_supported' => array( 'header' ),
			),
			$r['json']
		);
		$this->assertStringNotContainsString( 'admin', $r['raw'] );
	}

	/** Broker-enrolled but the site's own OAuth server is on (the default): it is the named AS. */
	public function test_broker_enrolled_with_local_oauth_on_serves_local_issuer(): void {
		$r = $this->request( '/.well-known/oauth-protected-resource', $this->broker_options( array() ) );

		$this->assertServedJsonHeaders( $r );
		$this->assertSame( array( 'https://example.com' ), $r['json']['authorization_servers'] );
		$this->assertSame( array( 'read', 'write' ), $r['json']['scopes_supported'] );
		$this->assertSame( 'https://example.com/wp-json/mcpwp/v1/mcp', $r['json']['resource'] );
	}

	public function test_non_enrolled_site_serves_its_own_issuer_on_the_wire(): void {
		$r = $this->request( '/.well-known/oauth-protected-resource', array() );

		$this->assertServedJsonHeaders( $r );
		$this->assertSame(
			array(
				'resource'                 => 'https://example.com/wp-json/mcpwp/v1/mcp',
				'authorization_servers'    => array( 'https://example.com' ),
				'scopes_supported'         => array( 'read', 'write' ),
				'bearer_methods_supported' => array( 'header' ),
			),
			$r['json']
		);
		$this->assertStringNotContainsString( 'admin', $r['raw'] );
	}

	public function test_non_enrolled_site_with_oauth_off_serves_nothing(): void {
		$r = $this->request(
			'/.well-known/oauth-protected-resource',
			array( 'mcpwp_settings' => array( 'oauth_enabled' => false ) )
		);

		$this->assertFalse( $r['served'] );
		$this->assertSame( 'not-served', $r['raw'] );
	}

	/** The site's own AS document is unchanged by enrollment and carries the same scope list. */
	public function test_authorization_server_document_is_served_with_read_write_only(): void {
		$r = $this->request( '/.well-known/oauth-authorization-server', $this->broker_options( array() ) );

		$this->assertServedJsonHeaders( $r );
		$this->assertSame( 'https://example.com', $r['json']['issuer'] );
		$this->assertSame( array( 'read', 'write' ), $r['json']['scopes_supported'] );
		$this->assertStringNotContainsString( 'admin', $r['raw'] );
	}
}
