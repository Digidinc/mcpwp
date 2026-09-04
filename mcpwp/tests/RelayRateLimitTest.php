<?php
/**
 * Regression coverage for the public feedback relay throttle.
 *
 * The relay is unauthenticated and spends this site's own GitHub token, so
 * its rate limit is the only control in front of it:
 *  - a spoofable CF-Connecting-IP must not mint a fresh bucket by default;
 *  - a value that is not an IP address must never become a bucket key;
 *  - a site-wide ceiling must bound total volume even when every caller has a
 *    distinct, genuine address.
 *
 * Both sides of the MCPWP_TRUST_PROXY_HEADERS decision are exercised by
 * calling the resolver with the trust flag as an argument. A PHP constant
 * cannot be undefined once set and this suite runs in a single process, so
 * define()-ing it here would permanently poison every other test.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-feedback.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-feedback.php';

final class RelayRateLimitTest extends TestCase {

	/**
	 * @var array<string, string|null>
	 */
	private array $saved_server = array();

	private Mcpwp_REST_Feedback $controller;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']                = array();
		$GLOBALS['mcpwp_test_transients']              = array();
		$GLOBALS['mcpwp_test_transient_write_fails']   = false;

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
		$GLOBALS['mcpwp_test_transients']            = array();
		$GLOBALS['mcpwp_test_transient_write_fails'] = false;
	}

	/** Call a private method on the controller. */
	private function call_private( string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( Mcpwp_REST_Feedback::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $this->controller, $args );
	}

	/** Build a minimally valid relay request. */
	private function relay_request(): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/feedback/relay' );
		$request->set_param( 'type', 'feedback' );
		$request->set_param( 'title', 'Automated test' );
		$request->set_param( 'description', 'Automated test' );
		return $request;
	}

	/** Per-IP bucket keys currently in the stub transient store. */
	private function ip_bucket_keys(): array {
		$keys = array_keys( $GLOBALS['mcpwp_test_transients'] );
		return array_values(
			array_filter(
				$keys,
				static function ( $key ) {
					return 1 === preg_match( '/^mcpwp_relay_[0-9a-f]{32}_[0-9]+$/', (string) $key );
				}
			)
		);
	}

	public function test_untrusted_default_ignores_cf_connecting_ip(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$this->assertSame( '203.0.113.10', $this->call_private( 'resolve_relay_client_ip', array( false ) ) );
	}

	public function test_trusted_mode_honours_cf_connecting_ip(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$this->assertSame( '198.51.100.7', $this->call_private( 'resolve_relay_client_ip', array( true ) ) );
	}

	public function test_non_ip_header_value_never_becomes_a_bucket_key(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$this->assertSame( '203.0.113.10', $this->call_private( 'resolve_relay_client_ip', array( true ) ) );
	}

	public function test_unusable_remote_addr_falls_back_to_placeholder(): void {
		$_SERVER['REMOTE_ADDR'] = 'garbage';

		$this->assertSame( '0.0.0.0', $this->call_private( 'resolve_relay_client_ip', array( false ) ) );
	}

	public function test_rotating_cf_headers_share_one_bucket_by_default(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		$last = null;
		for ( $i = 1; $i <= Mcpwp_REST_Feedback::RELAY_RATE_MAX + 1; $i++ ) {
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.' . $i;
			$last                             = $this->controller->relay_feedback( $this->relay_request() );
		}

		$buckets = $this->ip_bucket_keys();
		$this->assertCount( 1, $buckets, 'Rotating CF-Connecting-IP must not mint new buckets.' );
		$this->assertSame(
			Mcpwp_REST_Feedback::RELAY_RATE_MAX,
			(int) get_transient( $buckets[0] )
		);
		$this->assertInstanceOf( WP_Error::class, $last );
		$this->assertSame( 'rate_limited', $last->get_error_code() );
		$this->assertSame( 429, $last->get_error_data()['status'] );
	}

	public function test_global_ceiling_trips_independently_of_per_ip_buckets(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';

		$global_key = $this->call_private( 'relay_global_transient_key' );
		set_transient(
			$global_key,
			Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX,
			Mcpwp_REST_Feedback::RELAY_GLOBAL_WINDOW
		);

		$result = $this->controller->relay_feedback( $this->relay_request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertSame(
			array(),
			$this->ip_bucket_keys(),
			'A request refused by the global ceiling must not consume per-IP budget.'
		);
		$this->assertSame(
			Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX,
			(int) get_transient( $global_key )
		);
	}

	public function test_permitted_request_increments_both_counters(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		$result = $this->controller->relay_feedback( $this->relay_request() );

		// GitHub is unconfigured in the stub environment, so the call gets past
		// both throttles and fails downstream — which is what proves it passed.
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'relay_failed', $result->get_error_code() );

		$this->assertSame(
			1,
			(int) get_transient( $this->call_private( 'relay_ip_transient_key', array( '203.0.113.10' ) ) )
		);
		$this->assertSame(
			1,
			(int) get_transient( $this->call_private( 'relay_global_transient_key' ) )
		);
	}

	public function test_per_ip_bucket_key_is_stable_inside_one_window(): void {
		$base = 1758000000; // Divisible by RELAY_RATE_WINDOW: start of a window.

		$this->assertSame(
			$this->call_private( 'relay_ip_transient_key', array( '203.0.113.10', $base ) ),
			$this->call_private( 'relay_ip_transient_key', array( '203.0.113.10', $base + 59 ) ),
			'Two requests inside one window must share a bucket, or the ceiling never trips.'
		);
	}

	public function test_per_ip_bucket_key_tumbles_at_the_window_boundary(): void {
		$base = 1758000000;

		// A sliding window keys on the address alone, so these are equal and
		// one source holding the bucket at the ceiling locks out everyone
		// sharing it indefinitely. A tumbling window cannot outlive its own
		// boundary regardless of how many writes refresh the TTL.
		$this->assertNotSame(
			$this->call_private( 'relay_ip_transient_key', array( '203.0.113.10', $base + 59 ) ),
			$this->call_private( 'relay_ip_transient_key', array( '203.0.113.10', $base + 60 ) ),
			'The per-IP bucket must not survive its window boundary.'
		);
	}

	public function test_refuses_when_per_ip_counter_write_fails(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';

		// Every set_transient() call fails, simulating an unreachable
		// transient backend (dropped external object cache, read-only
		// options table). The per-IP write is attempted first, so this
		// must be caught before the relay ever calls GitHub.
		$GLOBALS['mcpwp_test_transient_write_fails'] = true;

		$result = $this->controller->relay_feedback( $this->relay_request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'relay_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame(
			array(),
			$this->ip_bucket_keys(),
			'A failed write must not appear to have persisted anything.'
		);
	}

	public function test_refuses_when_global_counter_write_fails(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.11';

		// Only the global bucket's write fails; the per-IP write succeeds.
		// The relay must still refuse — a slightly over-counted per-IP
		// bucket left behind is the safe direction, per the fix design.
		$GLOBALS['mcpwp_test_transient_write_fails'] = static function ( $name ) {
			return false !== strpos( (string) $name, 'mcpwp_relay_global_' );
		};

		$result = $this->controller->relay_feedback( $this->relay_request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'relay_unavailable', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_per_ip_buckets_stay_separate_per_address_within_a_window(): void {
		$base = 1758000000;

		$this->assertNotSame(
			$this->call_private( 'relay_ip_transient_key', array( '203.0.113.10', $base ) ),
			$this->call_private( 'relay_ip_transient_key', array( '198.51.100.7', $base ) ),
			'Adding the window index must not collapse distinct sources into one bucket.'
		);
	}

	public function test_global_per_minute_ceiling_trips_independently_of_hourly_ceiling(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';

		$minute_key = $this->call_private( 'relay_global_minute_transient_key' );
		set_transient(
			$minute_key,
			Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE,
			60
		);

		$result = $this->controller->relay_feedback( $this->relay_request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );

		// The hourly ceiling is nowhere near tripped on its own — only the
		// per-minute component is responsible for refusing this request.
		$hourly_count = (int) get_transient( $this->call_private( 'relay_global_transient_key' ) );
		$this->assertLessThan( Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX, $hourly_count );
	}

	public function test_global_minute_bucket_key_tumbles_at_the_window_boundary(): void {
		$base = 1758000000; // Divisible by 60: start of a minute.

		$this->assertNotSame(
			$this->call_private( 'relay_global_minute_transient_key', array( $base + 59 ) ),
			$this->call_private( 'relay_global_minute_transient_key', array( $base + 60 ) ),
			'The site-wide per-minute bucket must not survive its window boundary.'
		);
	}

	public function test_request_refused_by_per_minute_ceiling_consumes_no_other_budget(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.51';

		$minute_key = $this->call_private( 'relay_global_minute_transient_key' );
		set_transient(
			$minute_key,
			Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE,
			60
		);

		$result = $this->controller->relay_feedback( $this->relay_request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );
		$this->assertSame(
			array(),
			$this->ip_bucket_keys(),
			'A request refused by the per-minute ceiling must not consume per-IP budget.'
		);
		$this->assertSame(
			0,
			(int) get_transient( $this->call_private( 'relay_global_transient_key' ) ),
			'A request refused by the per-minute ceiling must not consume the hourly budget.'
		);
		$this->assertSame(
			Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE,
			(int) get_transient( $minute_key ),
			'The per-minute bucket itself must not be incremented past the ceiling by a refused request.'
		);
	}

	public function test_trusted_mode_honours_x_real_ip_when_no_cf_connecting_ip(): void {
		$_SERVER['HTTP_X_REAL_IP'] = '198.51.100.20';
		$_SERVER['REMOTE_ADDR']    = '203.0.113.10';

		$this->assertSame( '198.51.100.20', $this->call_private( 'resolve_relay_client_ip', array( true ) ) );
	}

	public function test_trusted_mode_honours_comma_list_x_forwarded_for(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.30, 10.0.0.1, 10.0.0.2';
		$_SERVER['REMOTE_ADDR']          = '203.0.113.10';

		$this->assertSame( '198.51.100.30', $this->call_private( 'resolve_relay_client_ip', array( true ) ) );
	}

	public function test_untrusted_default_ignores_all_proxy_headers(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.30';
		$_SERVER['HTTP_X_REAL_IP']        = '198.51.100.20';
		$_SERVER['REMOTE_ADDR']           = '203.0.113.10';

		$this->assertSame( '203.0.113.10', $this->call_private( 'resolve_relay_client_ip', array( false ) ) );
	}
}
