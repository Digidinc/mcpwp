<?php
/**
 * #804 — the verification surface's screenshot tool had two dishonesty bugs:
 *
 * 1. When the mshots fallback (WordPress.com) refuses a host outright
 *    (403/404/451 — staging/private/auth-gated hosts it cannot reach),
 *    capture() still reported success:true with a screenshot_url that would
 *    never render anything but a broken-image icon.
 * 2. full_page was silently dropped: the REST route never accepted it even
 *    though capture_via_cloudflare() already forwarded it to the worker, and
 *    the mshots fallback has no full-page mode at all but never said so.
 *
 * These tests pin: an outright mshots rejection is now a real error, a
 * "still generating" placeholder is unaffected, full_page reaches the
 * Cloudflare worker's request body, and the mshots fallback says plainly
 * when it did not honor full_page.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-screenshot.php';

final class ScreenshotHonestyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	/**
	 * Mock every outbound HTTP call: mshots GET requests get $mshots_response,
	 * a POST to the configured worker URL gets $worker_response (and its
	 * decoded JSON body is captured into $worker_request_body).
	 */
	private function mock_http( $mshots_response, $worker_response = null, &$worker_request_body = null ) {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $mshots_response, $worker_response, &$worker_request_body ) {
				if ( false !== strpos( $url, 's0.wp.com/mshots' ) ) {
					return $mshots_response;
				}

				if ( null !== $worker_response ) {
					$worker_request_body = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
					return $worker_response;
				}

				return new WP_Error( 'http_request_failed', 'Unexpected outbound request in test: ' . $url );
			},
			10,
			3
		);
	}

	// ── mshots outright rejection → honest error, not a lying "success" ──

	public function test_mshots_403_returns_error_not_false_success(): void {
		$this->mock_http(
			array( 'response' => array( 'code' => 403 ), 'body' => 'Forbidden', 'headers' => array() )
		);

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/' );

		$this->assertInstanceOf( WP_Error::class, $result, 'A 403 from mshots must not be reported as success (#804).' );
		$this->assertSame( 'mshots_rejected', $result->get_error_code() );
		$this->assertStringContainsString( '403', $result->get_error_message() );
	}

	public function test_mshots_451_also_returns_error(): void {
		$this->mock_http(
			array( 'response' => array( 'code' => 451 ), 'body' => '', 'headers' => array() )
		);

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mshots_rejected', $result->get_error_code() );
	}

	// ── A normal "still generating" placeholder is unaffected ────────────

	public function test_mshots_200_placeholder_still_succeeds(): void {
		$this->mock_http(
			array( 'response' => array( 'code' => 200 ), 'body' => 'placeholder', 'headers' => array() )
		);

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'wordpress-mshots', $result['service'] );
	}

	// ── A probe that itself fails to connect is inconclusive, not a rejection ──

	public function test_mshots_probe_network_failure_is_inconclusive_not_a_rejection(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				if ( false !== strpos( $url, 's0.wp.com/mshots' ) ) {
					return new WP_Error( 'http_request_failed', 'timed out' );
				}
				return new WP_Error( 'http_request_failed', 'no mock for ' . $url );
			},
			10,
			3
		);

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/' );

		$this->assertIsArray( $result, 'A probe that cannot connect must not be treated as a rejection.' );
		$this->assertTrue( $result['success'] );
	}

	// ── full_page reaches the Cloudflare worker and is reported honored ──

	public function test_full_page_forwarded_to_worker_and_honored(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_settings'] = array(
			'screenshot_worker_url'   => 'https://worker.example.com/screenshot',
			'screenshot_worker_token' => 'test-token',
		);

		$worker_body = null;
		$this->mock_http(
			array( 'response' => array( 'code' => 200 ), 'body' => 'unused', 'headers' => array() ),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'success' => true, 'screenshot' => 'ZmFrZQ==', 'format' => 'png' ) ),
				'headers'  => array(),
			),
			$worker_body
		);

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/', array( 'full_page' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'cloudflare-browser', $result['service'] );
		$this->assertTrue( $result['full_page_honored'] );
		$this->assertIsArray( $worker_body );
		$this->assertTrue( $worker_body['full_page'], 'full_page must reach the worker request body.' );
	}

	// ── full_page is NOT silently dropped by the mshots fallback ──────────

	public function test_full_page_not_honored_by_mshots_fallback_and_says_so(): void {
		$this->mock_http(
			array( 'response' => array( 'code' => 200 ), 'body' => 'placeholder', 'headers' => array() )
		);

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/', array( 'full_page' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'wordpress-mshots', $result['service'] );
		$this->assertFalse( $result['full_page_honored'], 'mshots has no full-page mode; this must not be silently ignored.' );
		$this->assertStringContainsString( 'full_page', $result['note'] );
	}
}
