<?php
/**
 * Regression coverage for the activity-log client-IP resolver.
 *
 * get_client_ip_for_logging() previously read proxy-supplied IP headers
 * (CF-Connecting-IP, X-Forwarded-For, X-Real-IP) unconditionally, with no
 * MCPWP_TRUST_PROXY_HEADERS gate — unlike every sibling surface in this
 * plugin (rate limiter, auth trait, relay throttle). That let any
 * unauthenticated caller write an arbitrary address into the site's
 * activity log by setting a header, corrupting the audit trail in exactly
 * the incident where it would be consulted.
 *
 * Both sides of the MCPWP_TRUST_PROXY_HEADERS decision are exercised by
 * calling the private resolver with the trust flag as an argument. A PHP
 * constant cannot be undefined once set and this suite runs in a single
 * process, so define()-ing it here would permanently poison every other
 * test.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/** Harness exposing the logging trait's client-IP resolution. */
class Mcpwp_Logging_IP_Harness {
	use Mcpwp_Logging;

	public function client_ip_for_logging() {
		return $this->get_client_ip_for_logging();
	}
}

final class LoggingClientIpTest extends TestCase {

	/**
	 * @var array<string, string|null>
	 */
	private array $saved_server = array();

	private Mcpwp_Logging_IP_Harness $harness;

	protected function setUp(): void {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
			$this->saved_server[ $key ] = $_SERVER[ $key ] ?? null;
			unset( $_SERVER[ $key ] );
		}

		$this->harness = new Mcpwp_Logging_IP_Harness();
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

	/** Call the private resolver with an explicit trust flag. */
	private function resolve( bool $trust_proxy ) {
		$reflection = new ReflectionMethod( Mcpwp_Logging_IP_Harness::class, 'resolve_logging_client_ip' );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $this->harness, array( $trust_proxy ) );
	}

	public function test_untrusted_default_ignores_spoofed_cf_connecting_ip(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		// No MCPWP_TRUST_PROXY_HEADERS defined — exercises the public seam,
		// which must default to untrusted.
		$this->assertSame( '203.0.113.10', $this->harness->client_ip_for_logging() );
	}

	public function test_untrusted_default_ignores_spoofed_x_forwarded_for(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
		$_SERVER['REMOTE_ADDR']          = '203.0.113.10';

		$this->assertSame( '203.0.113.10', $this->harness->client_ip_for_logging() );
	}

	public function test_trusted_mode_honours_cf_connecting_ip(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$this->assertSame( '198.51.100.7', $this->resolve( true ) );
	}

	public function test_trusted_mode_forwarded_for_comma_list_yields_first_entry(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 10.0.0.1, 10.0.0.2';
		$_SERVER['REMOTE_ADDR']          = '203.0.113.10';

		$this->assertSame( '198.51.100.9', $this->resolve( true ) );
	}

	public function test_non_ip_header_value_is_never_returned(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$this->assertSame( '203.0.113.10', $this->resolve( true ) );
	}
}
