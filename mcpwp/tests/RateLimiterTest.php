<?php

use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$this->resetLimiterSingleton();
	}

	public function test_stale_window_is_reinitialized_before_limit_check(): void {
		update_option(
			'mcpwp_rate_limit_settings',
			array(
				'enabled'             => true,
				'requests_per_minute' => 1,
				'requests_per_hour'   => 100,
				'burst_limit'         => 10,
				'whitelist'           => array(),
			)
		);

		$identifier = 'client-a';
		$minute_key = 'mcpwp_rate_' . md5( $identifier . '_minute' );
		$hour_key   = 'mcpwp_rate_' . md5( $identifier . '_hour' );

		set_transient( $minute_key, array( 'count' => 1, 'reset' => time() - 30 ), 120 );
		set_transient( $hour_key, array( 'count' => 0, 'reset' => time() + 3000 ), 3000 );

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$result  = $limiter->check_limit( $identifier );

		$this->assertTrue( $result );
	}

	public function test_retry_after_is_never_negative(): void {
		update_option(
			'mcpwp_rate_limit_settings',
			array(
				'enabled'             => true,
				'requests_per_minute' => 1,
				'requests_per_hour'   => 100,
				'burst_limit'         => 10,
				'whitelist'           => array(),
			)
		);

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$this->assertTrue( $limiter->check_limit( 'client-b' ) );

		$error = $limiter->check_limit( 'client-b' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'rate_limit_exceeded', $error->get_error_code() );
		$this->assertGreaterThanOrEqual( 0, (int) $error->get_error_data()['retry_after'] );
	}

	public function test_burst_limit_is_enforced(): void {
		update_option(
			'mcpwp_rate_limit_settings',
			array(
				'enabled'             => true,
				'requests_per_minute' => 100,
				'requests_per_hour'   => 1000,
				'burst_limit'         => 2,
				'whitelist'           => array(),
			)
		);

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$this->assertTrue( $limiter->check_limit( 'burst-client' ) );
		$this->assertTrue( $limiter->check_limit( 'burst-client' ) );

		$error = $limiter->check_limit( 'burst-client' );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'rate_limit_exceeded', $error->get_error_code() );
	}

	public function test_update_settings_sanitizes_values(): void {
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$limiter->update_settings(
			array(
				'requests_per_minute' => -5,
				'requests_per_hour'   => 0,
				'burst_limit'         => 999999,
				'whitelist'           => "127.0.0.1\nkey:test\n",
			)
		);

		$settings = $limiter->get_settings();
		$this->assertSame( 1, (int) $settings['requests_per_minute'] );
		$this->assertSame( 1, (int) $settings['requests_per_hour'] );
		$this->assertSame( 1, (int) $settings['burst_limit'] );
		$this->assertSame( array( '127.0.0.1', 'key:test' ), $settings['whitelist'] );
	}

	/**
	 * This class lowers the rate limits and caches them in the limiter
	 * singleton. setUp alone does not undo that — nothing clears it after the
	 * class's last test, so the next class inherits a limiter frozen at a burst
	 * limit of 2 instead of the default 30, and any test that makes more than a
	 * couple of requests starts getting 429s for reasons of its own.
	 *
	 * Found while writing the #670 pre-auth invariant, whose probe loop tripped
	 * on exactly this. Same family as #757: state cleaned on the way IN is not
	 * state cleaned after the last test that set it.
	 */
	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$this->resetLimiterSingleton();
	}

	private function resetLimiterSingleton(): void {
		$ref  = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null );
	}
}
