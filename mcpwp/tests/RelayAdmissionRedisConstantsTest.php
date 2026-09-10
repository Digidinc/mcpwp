<?php
/**
 * #752 F6 — Redis Object Cache configurations, set by constant, under which the
 * relay's admission must not be reported atomic.
 *
 * Each test defines a constant, and a PHP constant cannot be undefined, so
 * each runs in its own process.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-feedback.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-feedback.php';
require_once __DIR__ . '/fixtures/relay-object-cache-fakes.php';

final class RelayAdmissionRedisConstantsTest extends TestCase {

	private const GROUP = 'mcpwp_relay_admission';

	private function arrange_connected_redis(): Mcpwp_Test_Redis_Object_Cache {
		$GLOBALS['mcpwp_test_options']                   = array();
		$GLOBALS['mcpwp_test_transients']                = array();
		$GLOBALS['mcpwp_test_transient_write_fails']     = false;
		$GLOBALS['mcpwp_test_object_cache']              = array();
		$GLOBALS['mcpwp_test_ext_object_cache']          = true;
		$GLOBALS['mcpwp_test_object_cache_incr_creates'] = false;
		$GLOBALS['mcpwp_test_cache_addition_suspended']  = false;
		$GLOBALS['wp_object_cache']                      = new Mcpwp_Test_Redis_Object_Cache();
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_X_REAL_IP'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.90';

		return $GLOBALS['wp_object_cache'];
	}

	private function status(): array {
		return ( new Mcpwp_REST_Site_Updates() )->get_rate_limit_status( new WP_REST_Request( 'GET', '/mcpwp/v1/rate-limit' ) )->get_data();
	}

	private function relay( Mcpwp_REST_Feedback $controller ): string {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/feedback/relay' );
		$request->set_param( 'type', 'feedback' );
		$request->set_param( 'title', 'Automated test' );
		$request->set_param( 'description', 'Automated test' );
		$result = $controller->relay_feedback( $request );

		return ( $result instanceof WP_Error && 'relay_failed' === $result->get_error_code() )
			? 'admitted'
			: ( $result instanceof WP_Error ? $result->get_error_code() : 'unexpected_success' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_igbinary_redis_increment_is_not_reported_atomic_and_is_not_trusted(): void {
		// Redis Object Cache with igbinary increments by GET, add, SET — the
		// F6 read-modify-write itself.
		define( 'WP_REDIS_IGBINARY', true );
		$this->arrange_connected_redis();

		$status = $this->status();
		$this->assertSame( 'approximate', $status['relay_admission'] ?? null );
		$this->assertSame( 'object_cache_incr_not_atomic', $status['relay_admission_basis'] ?? null );

		$this->assertSame( 'admitted', $this->relay( new Mcpwp_REST_Feedback() ) );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_object_cache'], 'A read-modify-write increment must not be used as the gate.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_redis_max_ttl_shorter_than_a_window_is_not_reported_atomic(): void {
		define( 'WP_REDIS_MAXTTL', 600 );
		$redis      = $this->arrange_connected_redis();
		$controller = new Mcpwp_REST_Feedback();

		$status = $this->status();
		$this->assertSame( 'approximate', $status['relay_admission'] ?? null, 'An hourly counter capped at 600s restarts inside its window.' );
		$this->assertSame( 'object_cache_max_ttl_shorter_than_window', $status['relay_admission_basis'] ?? null );

		$this->assertSame( 'admitted', $this->relay( $controller ) );

		$hour_key = ( function () {
			return $this->relay_global_transient_key();
		} )->call( $controller );
		$this->assertContains(
			array( $redis->fast_build_key( $hour_key, self::GROUP ), 600 ),
			$redis->client->expire_calls,
			'The gate still runs, and a re-applied TTL respects the operator cap.'
		);
	}
}
