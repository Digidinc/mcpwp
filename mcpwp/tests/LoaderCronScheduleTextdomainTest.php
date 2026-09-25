<?php
/**
 * Regression test — the `mcpwp_every_five_minutes` cron schedule label must
 * never call a translation function before the `init` action has fired.
 *
 * `Mcpwp_Loader::register_cron_schedules()` is hooked to the `cron_schedules`
 * filter, which WordPress applies from inside `wp_get_schedules()` — and this
 * plugin's own first-run cron setup (Mcpwp_Signals::schedule(),
 * Mcpwp_Figma::schedule(), the action-log prune scheduling in mcpwp.php) calls
 * `wp_schedule_event()` eagerly on `plugins_loaded`, before `init`. Building
 * the schedule's 'display' label with `__( '...', 'mcpwp' )` unconditionally
 * therefore loaded the 'mcpwp' text domain before WordPress considers it safe
 * to, and WP 6.7+ logs a `_load_textdomain_just_in_time` doing-it-wrong notice
 * on every first activation/reactivation — flooding debug.log ahead of the
 * Product Hunt launch. See tests/bootstrap.php's `__()` stub, which records
 * any call for a non-default domain made before `init` the same way WordPress
 * itself decides whether to log that notice.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class LoaderCronScheduleTextdomainTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_did_action']            = array();
		$GLOBALS['mcpwp_test_early_textdomain_calls'] = array();
	}

	private function loader(): Mcpwp_Loader {
		// Avoid the real constructor: it eagerly instantiates the whole admin
		// surface (Mcpwp_Admin, Mcpwp_Settings, …), none of which this test
		// needs — register_cron_schedules() only reads/returns its $schedules
		// argument and does not touch instance state.
		return ( new ReflectionClass( Mcpwp_Loader::class ) )->newInstanceWithoutConstructor();
	}

	public function test_before_init_does_not_trigger_early_textdomain_load(): void {
		// did_action('init') reads 0 by default — an unbooted plugin, exactly
		// the state during the eager plugins_loaded-time cron scheduling.
		$result = $this->loader()->register_cron_schedules( array() );

		$this->assertArrayHasKey( 'mcpwp_every_five_minutes', $result );
		$this->assertSame( 5 * MINUTE_IN_SECONDS, $result['mcpwp_every_five_minutes']['interval'] );
		$this->assertNotEmpty( $result['mcpwp_every_five_minutes']['display'] );

		$this->assertSame(
			array(),
			$GLOBALS['mcpwp_test_early_textdomain_calls'],
			'register_cron_schedules() must not load the mcpwp text domain before init fires.'
		);
	}

	public function test_after_init_still_returns_a_translated_label(): void {
		$GLOBALS['mcpwp_test_did_action']['init'] = 1;

		$result = $this->loader()->register_cron_schedules( array() );

		$this->assertSame( 'Every 5 Minutes (MCPWP)', $result['mcpwp_every_five_minutes']['display'] );
		$this->assertSame(
			array(),
			$GLOBALS['mcpwp_test_early_textdomain_calls'],
			'A call made after init is not "early" and must not be recorded as one.'
		);
	}

	public function test_does_not_overwrite_an_existing_schedule_key(): void {
		$existing = array(
			'mcpwp_every_five_minutes' => array(
				'interval' => 123,
				'display'  => 'Custom',
			),
		);

		$result = $this->loader()->register_cron_schedules( $existing );

		$this->assertSame( $existing, $result );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_early_textdomain_calls'] );
	}
}
