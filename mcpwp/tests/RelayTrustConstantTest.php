<?php
/**
 * Coverage for the production seam that reads MCPWP_TRUST_PROXY_HEADERS.
 *
 * Every existing relay test (RelayRateLimitTest.php) calls the private
 * resolve_relay_client_ip( $bool ) directly, passing the trust decision in
 * as an argument rather than going through get_relay_client_ip(), which is
 * the method that actually reads the constant:
 *
 *     private function get_relay_client_ip() {
 *         $trust_proxy = defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && MCPWP_TRUST_PROXY_HEADERS;
 *         return $this->resolve_relay_client_ip( $trust_proxy );
 *     }
 *
 * That split exists precisely because a PHP constant cannot be undefined
 * once set and the main suite runs in a single process — so nothing there
 * can define MCPWP_TRUST_PROXY_HEADERS without poisoning every later test.
 * The consequence: a typo in the constant name on either side (e.g.
 * MCPWP_TRUST_PROXY_HEADER, missing the S) would leave get_relay_client_ip()
 * silently stuck on the untrusted branch forever, and the entire suite would
 * stay green, because nothing calls get_relay_client_ip() itself.
 *
 * @runInSeparateProcess / @preserveGlobalState disabled isolates the
 * define() to its own process (the same pattern already used in
 * LicenseCapabilitiesTest.php for MCPWP_WPORG_BUILD), so this file can
 * define the real constant without affecting any other test in the suite.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-feedback.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-feedback.php';

final class RelayTrustConstantTest extends TestCase {

	/**
	 * @var array<string, string|null>
	 */
	private array $saved_server = array();

	private Mcpwp_REST_Feedback $controller;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();

		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
			$this->saved_server[ $key ] = $_SERVER[ $key ] ?? null;
			unset( $_SERVER[ $key ] );
		}

		$this->controller = new Mcpwp_REST_Feedback();
	}

	protected function tearDown(): void {
		foreach ( $this->saved_server as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
	}

	/** Call the private production seam itself — not the split-out resolver. */
	private function call_seam(): string {
		$reflection = new ReflectionMethod( Mcpwp_REST_Feedback::class, 'get_relay_client_ip' );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $this->controller, array() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_seam_honours_the_constant_when_trust_is_enabled(): void {
		define( 'MCPWP_TRUST_PROXY_HEADERS', true );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.42';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$this->assertSame(
			'198.51.100.42',
			$this->call_seam(),
			'get_relay_client_ip() must honour a proxy header once MCPWP_TRUST_PROXY_HEADERS is truthy — ' .
			'if this fails while RelayRateLimitTest still passes, the constant name in the seam has drifted ' .
			'from the name every test actually exercises.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_seam_ignores_proxy_headers_when_trust_is_explicitly_disabled(): void {
		define( 'MCPWP_TRUST_PROXY_HEADERS', false );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.42';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$this->assertSame(
			'203.0.113.10',
			$this->call_seam(),
			'get_relay_client_ip() must fall back to REMOTE_ADDR when MCPWP_TRUST_PROXY_HEADERS is defined false.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_seam_defaults_to_untrusted_when_constant_is_undefined(): void {
		// Deliberately does not define MCPWP_TRUST_PROXY_HEADERS — the
		// out-of-the-box state on every site that has not opted in.
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.42';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$this->assertSame(
			'203.0.113.10',
			$this->call_seam(),
			'Without the constant defined at all, the seam must fail closed to REMOTE_ADDR.'
		);
	}
}
