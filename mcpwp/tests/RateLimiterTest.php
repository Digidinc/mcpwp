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

	/**
	 * @dataProvider rateLimitHintProvider
	 */
	public function test_rate_limit_error_hints_point_to_the_real_settings_screen( $settings, $identifier ): void {
		update_option( 'mcpwp_rate_limit_settings', $settings );
		$this->resetLimiterSingleton();
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$this->assertTrue( $limiter->check_limit( $identifier ) );

		$error = $limiter->check_limit( $identifier );
		$this->assertInstanceOf( WP_Error::class, $error );
		$hint = (string) $error->get_error_data()['hint'];
		$this->assertStringContainsString( 'Mumega MCP > Settings', $hint );
		$this->assertStringNotContainsString( 'mumcp', $hint );
		$this->assertStringNotContainsString( 'custom rate_limits', $hint );
	}

	public function rateLimitHintProvider(): array {
		return array(
			'burst'  => array( array( 'enabled' => true, 'requests_per_minute' => 10, 'requests_per_hour' => 100, 'burst_limit' => 1, 'whitelist' => array() ), 'hint-burst' ),
			'minute' => array( array( 'enabled' => true, 'requests_per_minute' => 1, 'requests_per_hour' => 100, 'burst_limit' => 10, 'whitelist' => array() ), 'hint-minute' ),
			'hour'   => array( array( 'enabled' => true, 'requests_per_minute' => 100, 'requests_per_hour' => 1, 'burst_limit' => 10, 'whitelist' => array() ), 'hint-hour' ),
		);
	}

	public function test_noncanonical_per_key_rate_override_surface_is_removed(): void {
		update_option(
			'mcpwp_rate_limit_settings',
			array(
				'enabled'             => true,
				'requests_per_minute' => 10,
				'requests_per_hour'   => 100,
				'burst_limit'         => 2,
				'whitelist'           => array(),
			)
		);
		$this->resetLimiterSingleton();
		$limiter = Mcpwp_Rate_Limiter::get_instance();

		$method = new ReflectionMethod( Mcpwp_Rate_Limiter::class, 'check_limit' );
		$this->assertSame( 2, $method->getNumberOfParameters() );
		$this->assertTrue( $limiter->check_limit( 'canonical-client', 'POST' ) );
		$this->assertTrue( $limiter->check_limit( 'canonical-client', 'POST' ) );
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

	public function test_persisted_and_updated_settings_are_projected_to_exact_canonical_keys(): void {
		update_option( 'mcpwp_rate_limit_settings', array( 'enabled' => false, 'requests_per_minute' => 7, 'unknown_authority' => 'drop-me' ) );
		$this->resetLimiterSingleton();
		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$expected = array( 'enabled', 'requests_per_minute', 'requests_per_hour', 'burst_limit', 'whitelist' );
		$this->assertSame( $expected, array_keys( $limiter->get_settings() ) );
		$limiter->update_settings( array( 'requests_per_hour' => 77, 'another_unknown' => true ) );
		$this->assertSame( $expected, array_keys( get_option( 'mcpwp_rate_limit_settings' ) ) );
	}

	private function resetLimiterSingleton(): void {
		$ref  = new ReflectionClass( Mcpwp_Rate_Limiter::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null );
	}
}
