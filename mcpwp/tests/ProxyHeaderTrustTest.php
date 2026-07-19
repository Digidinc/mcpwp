<?php
/**
 * Regression test — spoofable proxy IP headers are ignored by default.
 *
 * Without MCPWP_TRUST_PROXY_HEADERS, get_client_ip() must resolve the client
 * from REMOTE_ADDR only, so an attacker cannot forge CF-Connecting-IP /
 * X-Forwarded-For / X-Real-IP to mint fresh auth-throttle buckets or spoof a
 * whitelisted IP. (The trusted branch can't be exercised here because the
 * constant, once defined, is process-global across the suite.)
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/** Harness exposing the protected client-IP resolver from the auth trait. */
class Mcpwp_ProxyHeader_Auth_Harness {
	use Mcpwp_Api_Auth;

	public function client_ip() {
		return $this->get_client_ip();
	}
}

final class ProxyHeaderTrustTest extends TestCase {

	private array $saved = array();

	protected function setUp(): void {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $k ) {
			$this->saved[ $k ] = $_SERVER[ $k ] ?? null;
			unset( $_SERVER[ $k ] );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $k => $v ) {
			if ( null === $v ) {
				unset( $_SERVER[ $k ] );
			} else {
				$_SERVER[ $k ] = $v;
			}
		}
	}

	public function test_spoofed_proxy_headers_are_ignored_by_default(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '1.2.3.4';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '5.6.7.8';
		$_SERVER['HTTP_X_REAL_IP']        = '9.9.9.9';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$harness = new Mcpwp_ProxyHeader_Auth_Harness();

		$this->assertSame(
			'203.0.113.10',
			$harness->client_ip(),
			'Without MCPWP_TRUST_PROXY_HEADERS, only REMOTE_ADDR should be trusted.'
		);
	}

	public function test_falls_back_to_unknown_when_remote_addr_missing(): void {
		// Even with a spoofed forwarded header present, an absent REMOTE_ADDR must
		// not fall through to the untrusted header.
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

		$harness = new Mcpwp_ProxyHeader_Auth_Harness();

		$this->assertSame( 'unknown', $harness->client_ip() );
	}
}
