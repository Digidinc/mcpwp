<?php
/**
 * #752 F6 — admission to the public feedback relay must be atomic wherever the
 * site can make it atomic, and must say which it is.
 *
 * The relay is unauthenticated and every request it admits spends this site's
 * own GitHub token. Its three ceilings (per-IP, site-wide per minute, site-wide
 * per hour) were enforced as get_transient() then set_transient(): N requests
 * that all read before any of them writes all see the same count, and all pass.
 *
 * Concurrency is simulated deterministically rather than with threads: the
 * transient read hook answers every relay counter read with the value the
 * store held *before the burst*, which is exactly what N truly concurrent
 * requests observe when every read lands before the first write. The object
 * cache double (tests/bootstrap.php) is shared and its incr is atomic, so
 * against it only a gate that decides on the incremented value holds the line.
 *
 * Properties pinned:
 *  - with a persistent object cache, no burst admits more than any ceiling;
 *  - a request refused by one ceiling holds no slot in any other (per-IP vs
 *    site-wide, both directions) — the pre-existing "a refused request
 *    consumes no budget" property, carried into the atomic path;
 *  - an incr that returns anything but a positive count (false after an
 *    eviction or a backend failure, a drop-in's non-count) refuses — it never
 *    reads as "count 0, admit";
 *  - counter keys expire with their window, and never with TTL 0 ("forever");
 *  - the atomic gate is never weaker than the transient record it sits beside;
 *  - GET /rate-limit reports which mode is in force, and the two modes never
 *    report identically.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-feedback.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-feedback.php';

final class RelayAtomicAdmissionTest extends TestCase {

	/**
	 * Object-cache group holding the admission counters. Spelled out rather
	 * than read from the class: an operator who marks this group
	 * non-persistent turns the gate off, so the name is part of the contract.
	 */
	private const GROUP = 'mcpwp_relay_admission';

	/**
	 * @var array<string, string|null>
	 */
	private array $saved_server = array();

	private Mcpwp_REST_Feedback $controller;

	protected function setUp(): void {
		// Counter keys carry their window index. Keep each test inside one
		// minute (and so one hour) so a seeded key is the key the request uses.
		$into_minute = time() % 60;
		if ( $into_minute >= 57 ) {
			sleep( 60 - $into_minute );
		}

		$GLOBALS['mcpwp_test_options']               = array();
		$GLOBALS['mcpwp_test_transients']            = array();
		$GLOBALS['mcpwp_test_transient_write_fails'] = false;
		$GLOBALS['mcpwp_test_object_cache']          = array();
		$GLOBALS['mcpwp_test_ext_object_cache']      = true;
		unset( $GLOBALS['mcpwp_test_transient_hook'], $GLOBALS['mcpwp_test_object_cache_hook'] );

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
		$GLOBALS['mcpwp_test_object_cache']          = array();
		$GLOBALS['mcpwp_test_ext_object_cache']      = false;
		unset( $GLOBALS['mcpwp_test_transient_hook'], $GLOBALS['mcpwp_test_object_cache_hook'] );
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	private function call_private( string $method, array $args = array() ) {
		$reflection = new ReflectionMethod( Mcpwp_REST_Feedback::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $this->controller, $args );
	}

	private function relay_from( string $ip ) {
		$_SERVER['REMOTE_ADDR'] = $ip;

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/feedback/relay' );
		$request->set_param( 'type', 'feedback' );
		$request->set_param( 'title', 'Automated test' );
		$request->set_param( 'description', 'Automated test' );

		return $this->controller->relay_feedback( $request );
	}

	/**
	 * 'admitted' when the request got past every ceiling. GitHub is not
	 * configured in the stub environment, so an admitted request fails
	 * downstream with relay_failed — which is what proves it was admitted.
	 */
	private function outcome( $result ): string {
		if ( ! $result instanceof WP_Error ) {
			return 'unexpected_success';
		}
		return 'relay_failed' === $result->get_error_code() ? 'admitted' : $result->get_error_code();
	}

	private function count_of( array $outcomes, string $outcome ): int {
		return count( array_keys( $outcomes, $outcome, true ) );
	}

	/**
	 * Every relay counter read from here on sees the store as it was now:
	 * the observation N concurrent requests make when all of their reads land
	 * before the first of their writes.
	 */
	private function freeze_relay_counter_reads(): void {
		$snapshot = $GLOBALS['mcpwp_test_transients'];

		$GLOBALS['mcpwp_test_transient_hook'] = static function ( $name ) use ( $snapshot ) {
			if ( 0 !== strpos( (string) $name, 'mcpwp_relay_' ) ) {
				return null;
			}
			return isset( $snapshot[ $name ] ) ? $snapshot[ $name ]['value'] : false;
		};
	}

	private function ip_key( string $ip ): string {
		return $this->call_private( 'relay_ip_transient_key', array( $ip ) );
	}

	private function minute_key(): string {
		return $this->call_private( 'relay_global_minute_transient_key' );
	}

	private function hour_key(): string {
		return $this->call_private( 'relay_global_transient_key' );
	}

	/** The atomic counter's current value, or null when the key was never created. */
	private function counter( string $key ) {
		$id = self::GROUP . ':' . $key;
		return isset( $GLOBALS['mcpwp_test_object_cache'][ $id ] ) ? $GLOBALS['mcpwp_test_object_cache'][ $id ]['value'] : null;
	}

	private function seed_counter( string $key, int $value, int $ttl ): void {
		$this->assertTrue( wp_cache_add( $key, $value, self::GROUP, $ttl ) );
	}

	/** Relay counter transients written so far — the accounting record. */
	private function relay_transients(): array {
		return array_values(
			array_filter(
				array_keys( $GLOBALS['mcpwp_test_transients'] ),
				static function ( $key ) {
					return 0 === strpos( (string) $key, 'mcpwp_relay_' );
				}
			)
		);
	}

	// ---------------------------------------------------------------
	// Concurrent bursts cannot exceed a ceiling.
	// ---------------------------------------------------------------

	public function test_concurrent_burst_from_one_source_admits_no_more_than_the_per_ip_ceiling(): void {
		$this->freeze_relay_counter_reads();

		$outcomes = array();
		for ( $i = 0; $i < Mcpwp_REST_Feedback::RELAY_RATE_MAX + 5; $i++ ) {
			$outcomes[] = $this->outcome( $this->relay_from( '203.0.113.10' ) );
		}

		$this->assertSame(
			Mcpwp_REST_Feedback::RELAY_RATE_MAX,
			$this->count_of( $outcomes, 'admitted' ),
			'Every request read the pre-burst count; only the atomic counter can refuse the excess.'
		);
		$this->assertSame( 5, $this->count_of( $outcomes, 'rate_limited' ) );
		$this->assertSame(
			Mcpwp_REST_Feedback::RELAY_RATE_MAX,
			$this->counter( $this->ip_key( '203.0.113.10' ) ),
			'Refused requests must hand their per-IP increment back.'
		);
		$this->assertSame(
			Mcpwp_REST_Feedback::RELAY_RATE_MAX,
			$this->counter( $this->minute_key() ),
			'A request refused at the per-IP ceiling must never reach the site-wide counters.'
		);
	}

	public function test_concurrent_burst_across_sources_admits_no_more_than_the_site_wide_minute_ceiling(): void {
		$this->freeze_relay_counter_reads();

		$outcomes = array();
		$refused  = array();
		for ( $i = 1; $i <= Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE + 5; $i++ ) {
			$ip      = '198.51.100.' . $i;
			$outcome = $this->outcome( $this->relay_from( $ip ) );
			if ( 'rate_limited' === $outcome ) {
				$refused[] = $ip;
			}
			$outcomes[] = $outcome;
		}

		$this->assertSame( Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE, $this->count_of( $outcomes, 'admitted' ) );
		$this->assertCount( 5, $refused );
		$this->assertSame( Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE, $this->counter( $this->minute_key() ) );
		$this->assertSame(
			Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE,
			$this->counter( $this->hour_key() ),
			'Only admitted requests may be charged to the hourly budget.'
		);
		foreach ( $refused as $ip ) {
			$this->assertSame( 0, $this->counter( $this->ip_key( $ip ) ), "Refused source $ip must not keep a per-IP slot." );
		}
	}

	public function test_concurrent_burst_admits_no_more_than_the_remaining_hourly_budget(): void {
		$this->seed_counter( $this->hour_key(), Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX - 3, Mcpwp_REST_Feedback::RELAY_GLOBAL_WINDOW );
		$this->freeze_relay_counter_reads();

		$outcomes = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$outcomes[] = $this->outcome( $this->relay_from( '192.0.2.' . $i ) );
		}

		$this->assertSame( 3, $this->count_of( $outcomes, 'admitted' ) );
		$this->assertSame( 7, $this->count_of( $outcomes, 'rate_limited' ) );
		$this->assertSame( Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX, $this->counter( $this->hour_key() ) );
		$this->assertSame(
			3,
			$this->counter( $this->minute_key() ),
			'Requests refused at the hourly ceiling must hand their per-minute increment back.'
		);
	}

	// ---------------------------------------------------------------
	// A refusal consumes no budget in any other counter.
	// ---------------------------------------------------------------

	public static function site_wide_ceilings(): array {
		return array(
			'per-minute ceiling' => array( 'minute' ),
			'hourly ceiling'     => array( 'hour' ),
		);
	}

	/**
	 * @dataProvider site_wide_ceilings
	 */
	public function test_refusal_at_a_site_wide_ceiling_holds_no_per_ip_slot( string $which ): void {
		$ip = '203.0.113.20';
		if ( 'minute' === $which ) {
			$this->seed_counter( $this->minute_key(), Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE, Mcpwp_REST_Feedback::RELAY_GLOBAL_MINUTE_WINDOW );
		} else {
			$this->seed_counter( $this->hour_key(), Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX, Mcpwp_REST_Feedback::RELAY_GLOBAL_WINDOW );
		}

		$result = $this->relay_from( $ip );

		$this->assertSame( 'rate_limited', $this->outcome( $result ) );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$this->assertSame( 0, (int) $this->counter( $this->ip_key( $ip ) ), 'The refused request must not keep a per-IP slot.' );
		if ( 'minute' === $which ) {
			$this->assertSame( Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE, $this->counter( $this->minute_key() ) );
			$this->assertSame( 0, (int) $this->counter( $this->hour_key() ) );
		} else {
			$this->assertSame( 0, (int) $this->counter( $this->minute_key() ), 'The refused request must not keep a per-minute slot.' );
			$this->assertSame( Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX, $this->counter( $this->hour_key() ) );
		}
		$this->assertSame( array(), $this->relay_transients(), 'A refused request must not be written to the accounting record.' );
	}

	public function test_refusal_at_the_per_ip_ceiling_holds_no_site_wide_slot(): void {
		$ip = '203.0.113.21';
		$this->seed_counter( $this->ip_key( $ip ), Mcpwp_REST_Feedback::RELAY_RATE_MAX, Mcpwp_REST_Feedback::RELAY_RATE_WINDOW );

		$result = $this->relay_from( $ip );

		$this->assertSame( 'rate_limited', $this->outcome( $result ) );
		$this->assertSame( Mcpwp_REST_Feedback::RELAY_RATE_MAX, $this->counter( $this->ip_key( $ip ) ) );
		$this->assertSame( 0, (int) $this->counter( $this->minute_key() ) );
		$this->assertSame( 0, (int) $this->counter( $this->hour_key() ) );
		$this->assertSame( array(), $this->relay_transients() );
	}

	// ---------------------------------------------------------------
	// An incr that is not a count is not an admission.
	// ---------------------------------------------------------------

	public static function non_count_incr_results(): array {
		return array(
			'false (evicted key / backend failure)' => array( false ),
			'zero'                                  => array( 0 ),
			'negative'                              => array( -1 ),
			'empty string'                          => array( '' ),
			'non-numeric string'                    => array( '3abc' ),
			'float'                                 => array( 2.5 ),
		);
	}

	/**
	 * @dataProvider non_count_incr_results
	 */
	public function test_incr_result_that_is_not_a_count_refuses_instead_of_admitting( $answer ): void {
		$GLOBALS['mcpwp_test_object_cache_hook'] = static function ( $op ) use ( $answer ) {
			return 'incr' === $op ? $answer : null;
		};

		$result = $this->relay_from( '203.0.113.30' );

		$this->assertSame( 'relay_unavailable', $this->outcome( $result ), 'An unreadable counter must refuse, never read as zero.' );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->relay_transients() );
	}

	public function test_counter_evicted_between_add_and_incr_refuses_and_releases_earlier_slots(): void {
		$ip         = '203.0.113.31';
		$minute_key = $this->minute_key();

		// The per-minute key vanishes between its add and its incr (LRU
		// eviction under memory pressure), so the incr finds nothing: core,
		// Memcached and this double all answer false.
		$GLOBALS['mcpwp_test_object_cache_hook'] = static function ( $op, $key, $group ) use ( $minute_key ) {
			if ( 'incr' === $op && $minute_key === $key ) {
				unset( $GLOBALS['mcpwp_test_object_cache'][ $group . ':' . $key ] );
			}
			return null;
		};

		$result = $this->relay_from( $ip );

		$this->assertSame( 'relay_unavailable', $this->outcome( $result ) );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->counter( $this->ip_key( $ip ) ), 'The per-IP slot taken before the failure must be handed back.' );
		$this->assertNull( $this->counter( $this->hour_key() ), 'Nothing after the failed counter may be touched.' );
		$this->assertSame( array(), $this->relay_transients() );

		unset( $GLOBALS['mcpwp_test_object_cache_hook'] );
		$this->assertSame( 'admitted', $this->outcome( $this->relay_from( $ip ) ), 'Recovery needs no operator action.' );
	}

	// ---------------------------------------------------------------
	// Keys expire with their window; the gate is never weaker than the record.
	// ---------------------------------------------------------------

	public function test_counter_keys_expire_with_their_window(): void {
		$ip = '203.0.113.40';

		$this->assertSame( 'admitted', $this->outcome( $this->relay_from( $ip ) ) );

		$expected = array(
			$this->ip_key( $ip )  => Mcpwp_REST_Feedback::RELAY_RATE_WINDOW,
			$this->minute_key()   => Mcpwp_REST_Feedback::RELAY_GLOBAL_MINUTE_WINDOW,
			$this->hour_key()     => Mcpwp_REST_Feedback::RELAY_GLOBAL_WINDOW,
		);
		foreach ( $expected as $key => $window ) {
			$id = self::GROUP . ':' . $key;
			$this->assertArrayHasKey( $id, $GLOBALS['mcpwp_test_object_cache'], "Counter $key must be created in the admission group." );
			$this->assertSame( 1, $GLOBALS['mcpwp_test_object_cache'][ $id ]['value'] );
			$this->assertGreaterThan( 0, $GLOBALS['mcpwp_test_object_cache'][ $id ]['ttl'], 'TTL 0 means "never expires" to an object cache.' );
			$this->assertSame( $window, $GLOBALS['mcpwp_test_object_cache'][ $id ]['ttl'] );
		}
	}

	public function test_gate_is_never_weaker_than_the_accounting_record_when_the_cache_does_not_persist(): void {
		// A drop-in present but not persisting this group — marked
		// non-persistent, or a Redis drop-in fallen back to its in-process
		// array — hands every request a fresh counter. The transient record
		// must still bound the source.
		$ip       = '203.0.113.41';
		$outcomes = array();
		for ( $i = 0; $i < Mcpwp_REST_Feedback::RELAY_RATE_MAX + 5; $i++ ) {
			$GLOBALS['mcpwp_test_object_cache'] = array();
			$outcome                            = $this->outcome( $this->relay_from( $ip ) );
			if ( 'admitted' === $outcome ) {
				$this->assertSame( 1, $this->counter( $this->ip_key( $ip ) ), 'Admission must have gone through the atomic gate.' );
			}
			$outcomes[] = $outcome;
		}

		$this->assertSame( Mcpwp_REST_Feedback::RELAY_RATE_MAX, $this->count_of( $outcomes, 'admitted' ) );
		$this->assertSame( 5, $this->count_of( $outcomes, 'rate_limited' ) );
	}

	// ---------------------------------------------------------------
	// The mode in force is reported, and never ambiguously.
	// ---------------------------------------------------------------

	public function test_approximate_mode_is_used_and_named_when_no_persistent_cache_is_present(): void {
		$GLOBALS['mcpwp_test_ext_object_cache'] = false;

		$this->assertSame( 'approximate', Mcpwp_REST_Feedback::relay_admission_mode() );

		$outcomes = array();
		for ( $i = 0; $i < Mcpwp_REST_Feedback::RELAY_RATE_MAX + 1; $i++ ) {
			$outcomes[] = $this->outcome( $this->relay_from( '203.0.113.50' ) );
		}

		$this->assertSame( Mcpwp_REST_Feedback::RELAY_RATE_MAX, $this->count_of( $outcomes, 'admitted' ) );
		$this->assertSame( 1, $this->count_of( $outcomes, 'rate_limited' ) );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_object_cache'], 'A per-request cache must not be mistaken for an admission gate.' );
	}

	public function test_rate_limit_status_reports_the_relay_admission_mode_in_force(): void {
		$controller = new Mcpwp_REST_Site_Updates();
		$request    = new WP_REST_Request( 'GET', '/mcpwp/v1/rate-limit' );

		$GLOBALS['mcpwp_test_ext_object_cache'] = true;
		$atomic                                 = $controller->get_rate_limit_status( $request )->get_data();

		$GLOBALS['mcpwp_test_ext_object_cache'] = false;
		$approximate                            = $controller->get_rate_limit_status( $request )->get_data();

		$this->assertSame( 'atomic', $atomic['relay_admission'] ?? null );
		$this->assertSame( 'approximate', $approximate['relay_admission'] ?? null );
		$this->assertNotSame( $atomic['relay_admission'], $approximate['relay_admission'] );

		$expected_limits = array(
			'per_ip'            => array(
				'limit'          => Mcpwp_REST_Feedback::RELAY_RATE_MAX,
				'window_seconds' => Mcpwp_REST_Feedback::RELAY_RATE_WINDOW,
			),
			'global_per_minute' => array(
				'limit'          => Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE,
				'window_seconds' => Mcpwp_REST_Feedback::RELAY_GLOBAL_MINUTE_WINDOW,
			),
			'global_per_hour'   => array(
				'limit'          => Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX,
				'window_seconds' => Mcpwp_REST_Feedback::RELAY_GLOBAL_WINDOW,
			),
		);
		$this->assertSame( $expected_limits, $atomic['relay_limits'] ?? null );
		$this->assertSame( $expected_limits, $approximate['relay_limits'] ?? null );
	}
}
