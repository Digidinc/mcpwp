<?php
/**
 * #804 — the verification surface's screenshot tool had two dishonesty bugs,
 * plus adversarial-review follow-ups (W1/W2/W3/NIT-2):
 *
 * 1. When the mshots fallback (WordPress.com) refuses a host outright
 *    (401/403/404/410/451), capture() still reported success:true with a
 *    screenshot_url that would never render anything but a broken-image
 *    icon. Fixed as `mshots_rejected` — but the original message blamed the
 *    target HOST ("refused this host"), when confirmed live from a Hetzner
 *    server against multiple unrelated targets (github.com,
 *    wordpress.org/news, …), mshots is refusing to talk to THIS SERVER —
 *    it blocks datacenter-hosted requesters, not anything about the URL
 *    being screenshotted. W1 rewords the message to say that.
 * 2. W2: mshots being down or rate-limiting (400/429/5xx) was not checked at
 *    all and fell through to a false "success" too — now `mshots_unavailable`.
 * 3. full_page was silently dropped: the REST route never accepted it even
 *    though capture_via_cloudflare() already forwarded it to the worker, and
 *    the mshots fallback has no full-page mode at all but never said so.
 *    NIT-2: full_page_honored must reflect what the WORKER echoed back, not
 *    just what was asked for — 'unknown' when it doesn't echo the field.
 * 4. capture_many() (used for a widths sweep) probes mshots reachability at
 *    most once for the whole sweep, not once per width.
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
	 * Mock every outbound HTTP call: mshots GET requests get $mshots_response
	 * (or the result of calling $mshots_response if it's a callable, so a
	 * test can count/vary calls), a POST to the configured worker URL gets
	 * $worker_response (and its decoded JSON body is captured into
	 * $worker_request_body).
	 */
	private function mock_http( $mshots_response, $worker_response = null, &$worker_request_body = null ) {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $mshots_response, $worker_response, &$worker_request_body ) {
				if ( false !== strpos( $url, 's0.wp.com/mshots' ) ) {
					return is_callable( $mshots_response ) ? $mshots_response( $url ) : $mshots_response;
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

	private function status_response( int $code, string $body = '' ): array {
		return array( 'response' => array( 'code' => $code ), 'body' => $body, 'headers' => array() );
	}

	// ── mshots outright rejection → honest error, not a lying "success" ──

	public function test_mshots_403_returns_error_not_false_success(): void {
		$this->mock_http( $this->status_response( 403, 'Forbidden' ) );

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/' );

		$this->assertInstanceOf( WP_Error::class, $result, 'A 403 from mshots must not be reported as success (#804).' );
		$this->assertSame( 'mshots_rejected', $result->get_error_code() );
		$this->assertStringContainsString( '403', $result->get_error_message() );
	}

	public function test_mshots_451_also_returns_error(): void {
		$this->mock_http( $this->status_response( 451 ) );

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mshots_rejected', $result->get_error_code() );
	}

	// ── #804 W1: the message blames OUR server, not the target URL ───────

	public function test_mshots_rejection_message_blames_this_server_not_the_target(): void {
		$this->mock_http( $this->status_response( 403, 'Forbidden' ) );

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/' );

		$message = $result->get_error_message();
		$this->assertStringContainsString( "refused this site's server", $message, 'Must blame our server, not the target host — mshots blocks datacenter IPs regardless of the URL requested.' );
		$this->assertStringContainsString( 'datacenter hosts', $message );
		$this->assertStringContainsString( 'Screenshot Worker', $message );
		$this->assertStringNotContainsString( 'refused this host', $message, 'The old, target-blaming wording must be gone.' );
	}

	// ── #804 W2: mshots down/rate-limiting is also a failure, not success ─

	public function test_mshots_400_is_unavailable_not_success(): void {
		$this->mock_http( $this->status_response( 400, 'Bad Request' ) );

		$result = ( new Mcpwp_Screenshot() )->capture( 'https://example.com/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mshots_unavailable', $result->get_error_code() );
	}

	public function test_mshots_429_is_unavailable_not_success(): void {
		$this->mock_http( $this->status_response( 429, 'Too Many Requests' ) );

		$result = ( new Mcpwp_Screenshot() )->capture( 'https://example.com/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mshots_unavailable', $result->get_error_code() );
	}

	/**
	 * @dataProvider server_error_statuses
	 */
	public function test_mshots_5xx_is_unavailable_not_success( int $status ): void {
		$this->mock_http( $this->status_response( $status ) );

		$result = ( new Mcpwp_Screenshot() )->capture( 'https://example.com/' );

		$this->assertInstanceOf( WP_Error::class, $result, "HTTP $status must not be reported as success." );
		$this->assertSame( 'mshots_unavailable', $result->get_error_code() );
	}

	public function server_error_statuses(): array {
		return array( array( 500 ), array( 502 ), array( 503 ), array( 599 ) );
	}

	// ── A normal "still generating" placeholder is unaffected ────────────

	public function test_mshots_200_placeholder_still_succeeds(): void {
		$this->mock_http( $this->status_response( 200, 'placeholder' ) );

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'wordpress-mshots', $result['service'] );
	}

	public function test_mshots_202_placeholder_still_succeeds(): void {
		$this->mock_http( $this->status_response( 202, 'still generating' ) );

		$result = ( new Mcpwp_Screenshot() )->capture( 'https://example.com/' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	// ── A probe that itself fails to connect is inconclusive, not a rejection ──

	public function test_mshots_probe_network_failure_is_inconclusive_not_a_rejection(): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				return new WP_Error( 'http_request_failed', 'timed out' );
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

	public function test_full_page_forwarded_to_worker_and_honored_when_echoed(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_settings'] = array(
			'screenshot_worker_url'   => 'https://worker.example.com/screenshot',
			'screenshot_worker_token' => 'test-token',
		);

		$worker_body = null;
		$this->mock_http(
			$this->status_response( 200, 'unused' ),
			array(
				'response' => array( 'code' => 200 ),
				// The worker echoes full_page back in its response — see
				// screenshot-worker/src/index.js's `full_page: full_page`.
				'body'     => wp_json_encode( array( 'success' => true, 'screenshot' => 'ZmFrZQ==', 'format' => 'png', 'full_page' => true ) ),
				'headers'  => array(),
			),
			$worker_body
		);

		$result = ( new Mcpwp_Screenshot() )->capture( 'https://example.com/', array( 'full_page' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'cloudflare-browser', $result['service'] );
		$this->assertTrue( $result['full_page_honored'] );
		$this->assertIsArray( $worker_body );
		$this->assertTrue( $worker_body['full_page'], 'full_page must reach the worker request body.' );
	}

	// #804 NIT-2: report what the worker actually did, not what we asked
	// for — a worker that does not echo full_page leaves this genuinely
	// unknown rather than a guessed true.
	public function test_full_page_honored_is_unknown_when_worker_does_not_echo_it(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_settings'] = array(
			'screenshot_worker_url'   => 'https://worker.example.com/screenshot',
			'screenshot_worker_token' => 'test-token',
		);

		$this->mock_http(
			$this->status_response( 200, 'unused' ),
			array(
				'response' => array( 'code' => 200 ),
				// No full_page key at all — an older worker deploy, or a
				// self-hosted one that never echoes it.
				'body'     => wp_json_encode( array( 'success' => true, 'screenshot' => 'ZmFrZQ==', 'format' => 'png' ) ),
				'headers'  => array(),
			)
		);

		$result = ( new Mcpwp_Screenshot() )->capture( 'https://example.com/', array( 'full_page' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'unknown', $result['full_page_honored'] );
	}

	// A worker that echoes full_page=false (it tried, but e.g. clamped it
	// off) is reported as false, not true just because it was requested.
	public function test_full_page_honored_is_false_when_worker_echoes_false(): void {
		$GLOBALS['mcpwp_test_options']['mcpwp_settings'] = array(
			'screenshot_worker_url'   => 'https://worker.example.com/screenshot',
			'screenshot_worker_token' => 'test-token',
		);

		$this->mock_http(
			$this->status_response( 200, 'unused' ),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'success' => true, 'screenshot' => 'ZmFrZQ==', 'format' => 'png', 'full_page' => false ) ),
				'headers'  => array(),
			)
		);

		$result = ( new Mcpwp_Screenshot() )->capture( 'https://example.com/', array( 'full_page' => true ) );

		$this->assertSame( false, $result['full_page_honored'] );
	}

	// ── full_page is NOT silently dropped by the mshots fallback ──────────

	public function test_full_page_not_honored_by_mshots_fallback_and_says_so(): void {
		$this->mock_http( $this->status_response( 200, 'placeholder' ) );

		$screenshot = new Mcpwp_Screenshot();
		$result     = $screenshot->capture( 'https://example.com/', array( 'full_page' => true ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'wordpress-mshots', $result['service'] );
		$this->assertFalse( $result['full_page_honored'], 'mshots has no full-page mode; this must not be silently ignored.' );
		$this->assertStringContainsString( 'full_page', $result['note'] );
	}

	// ── #804 W2: capture_many() probes mshots once for the whole sweep ────

	public function test_capture_many_probes_mshots_once_for_the_whole_sweep(): void {
		$probe_calls = 0;
		$this->mock_http(
			function () use ( &$probe_calls ) {
				++$probe_calls;
				return $this->status_response( 200, 'placeholder' );
			}
		);

		$results = ( new Mcpwp_Screenshot() )->capture_many( 'https://example.com/', array( 390, 767, 1024, 1440 ) );

		$this->assertSame( 1, $probe_calls, 'One mshots probe for the whole sweep, not one per width.' );
		$this->assertCount( 4, $results );
		foreach ( $results as $width => $result ) {
			$this->assertIsArray( $result, "width $width" );
			$this->assertTrue( $result['success'], "width $width" );
		}
	}

	// A rejection found by the single sweep-wide probe applies to every
	// width — it does not re-probe and get a different answer per width.
	public function test_capture_many_rejection_applies_to_every_width(): void {
		$probe_calls = 0;
		$this->mock_http(
			function () use ( &$probe_calls ) {
				++$probe_calls;
				return $this->status_response( 403, 'Forbidden' );
			}
		);

		$results = ( new Mcpwp_Screenshot() )->capture_many( 'https://example.com/', array( 390, 767, 1024 ) );

		$this->assertSame( 1, $probe_calls );
		$this->assertCount( 3, $results );
		foreach ( $results as $width => $result ) {
			$this->assertInstanceOf( WP_Error::class, $result, "width $width" );
			$this->assertSame( 'mshots_rejected', $result->get_error_code() );
		}
	}
}
