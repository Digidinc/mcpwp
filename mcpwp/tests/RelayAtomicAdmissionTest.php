<?php
/**
 * #752 F6 — admission to the public feedback relay must be atomic wherever the
 * site can make it atomic, and the report must claim no more than the code can
 * see.
 *
 * The relay is unauthenticated and every request it admits spends this site's
 * own GitHub token. Its three ceilings (per-IP, site-wide per minute, site-wide
 * per hour) were enforced as get_transient() then set_transient(): N requests
 * that all read before any of them writes all see the same count, and all pass.
 *
 * Two kinds of concurrency are simulated deterministically:
 *  - bursts: every relay transient read returns the pre-burst value, which is
 *    what N concurrent requests observe when all reads land before any write;
 *  - interleaving: a second request runs to completion *inside* the first
 *    request's increment on a chosen counter, before that increment applies.
 *    A gate that checks a count and then increments (rather than deciding on
 *    the increment's own result) admits both; these tests are what catch it.
 *
 * The object cache double (tests/bootstrap.php) is shared and its incr is
 * atomic. The drop-in in $GLOBALS['wp_object_cache'] is a stand-in for the
 * public API surface of Redis Object Cache or Automattic memcached
 * (tests/fixtures/relay-object-cache-fakes.php) — the relay recognises a
 * drop-in by that surface and claims "atomic" only for a recognised one.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-feedback.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-feedback.php';
require_once __DIR__ . '/fixtures/relay-object-cache-fakes.php';

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

	private bool $had_object_cache = false;

	/** @var mixed */
	private $saved_object_cache = null;

	private bool $interleaved = false;

	private Mcpwp_REST_Feedback $controller;

	protected function setUp(): void {
		// Counter keys carry their window index. Keep each test inside one
		// minute (and so one hour) so a seeded key is the key the request uses.
		$into_minute = time() % 60;
		if ( $into_minute >= 57 ) {
			sleep( 60 - $into_minute );
		}

		$GLOBALS['mcpwp_test_options']                   = array();
		$GLOBALS['mcpwp_test_transients']                = array();
		$GLOBALS['mcpwp_test_transient_write_fails']     = false;
		$GLOBALS['mcpwp_test_object_cache']              = array();
		$GLOBALS['mcpwp_test_ext_object_cache']          = true;
		$GLOBALS['mcpwp_test_object_cache_incr_creates'] = false;
		$GLOBALS['mcpwp_test_cache_addition_suspended']  = false;
		unset( $GLOBALS['mcpwp_test_transient_hook'], $GLOBALS['mcpwp_test_object_cache_hook'] );

		$this->had_object_cache     = array_key_exists( 'wp_object_cache', $GLOBALS );
		$this->saved_object_cache   = $this->had_object_cache ? $GLOBALS['wp_object_cache'] : null;
		$GLOBALS['wp_object_cache'] = new Mcpwp_Test_Redis_Object_Cache();
		$this->interleaved          = false;

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
		if ( $this->had_object_cache ) {
			$GLOBALS['wp_object_cache'] = $this->saved_object_cache;
		} else {
			unset( $GLOBALS['wp_object_cache'] );
		}
		$GLOBALS['mcpwp_test_transients']                = array();
		$GLOBALS['mcpwp_test_transient_write_fails']     = false;
		$GLOBALS['mcpwp_test_object_cache']              = array();
		$GLOBALS['mcpwp_test_ext_object_cache']          = false;
		$GLOBALS['mcpwp_test_object_cache_incr_creates'] = false;
		$GLOBALS['mcpwp_test_cache_addition_suspended']  = false;
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

	/**
	 * Run $nested once, to completion, inside the next request's increment of
	 * $trigger_key — after that request has taken every earlier decision and
	 * before its own increment applies.
	 */
	private function interleave_at( string $trigger_key, callable $nested, array &$outcomes ): void {
		$GLOBALS['mcpwp_test_object_cache_hook'] = function ( $op, $key ) use ( $trigger_key, $nested, &$outcomes ) {
			if ( 'incr' === $op && $trigger_key === $key && ! $this->interleaved ) {
				$this->interleaved = true;
				$outcomes[]        = $nested();
			}
			return null;
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

	/** Admission-group keys in the object cache — present only if the gate ran. */
	private function admission_keys(): array {
		return array_values(
			array_filter(
				array_keys( $GLOBALS['mcpwp_test_object_cache'] ),
				static function ( $id ) {
					return 0 === strpos( (string) $id, self::GROUP . ':' );
				}
			)
		);
	}

	private function rate_limit_status(): array {
		return ( new Mcpwp_REST_Site_Updates() )
			->get_rate_limit_status( new WP_REST_Request( 'GET', '/mcpwp/v1/rate-limit' ) )
			->get_data();
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
	// Interleaving: a second request inside the first one's increment.
	// ---------------------------------------------------------------

	public function test_request_taking_the_last_per_ip_slot_inside_anothers_increment_leaves_that_one_refused(): void {
		$ip     = '203.0.113.77';
		$ip_key = $this->ip_key( $ip );
		$this->seed_counter( $ip_key, Mcpwp_REST_Feedback::RELAY_RATE_MAX - 1, Mcpwp_REST_Feedback::RELAY_RATE_WINDOW );
		$this->freeze_relay_counter_reads();

		// A has seen one slot left; B takes it before A's increment applies.
		$outcomes = array();
		$this->interleave_at(
			$ip_key,
			function () use ( $ip ) {
				return $this->outcome( $this->relay_from( $ip ) );
			},
			$outcomes
		);
		$outcomes[] = $this->outcome( $this->relay_from( $ip ) );

		$this->assertTrue( $this->interleaved, 'Precondition: the second request ran inside the increment of the first.' );
		$this->assertSame( 1, $this->count_of( $outcomes, 'admitted' ), 'One slot, one admission: ' . wp_json_encode( $outcomes ) );
		$this->assertSame( Mcpwp_REST_Feedback::RELAY_RATE_MAX, $this->counter( $ip_key ) );
	}

	public function test_rollback_of_a_request_refused_at_the_minute_ceiling_is_exact_under_interleaving(): void {
		$ip         = '203.0.113.78';
		$ip_key     = $this->ip_key( $ip );
		$minute_key = $this->minute_key();
		$this->seed_counter( $ip_key, Mcpwp_REST_Feedback::RELAY_RATE_MAX - 1, Mcpwp_REST_Feedback::RELAY_RATE_WINDOW );
		$this->seed_counter( $minute_key, Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE, Mcpwp_REST_Feedback::RELAY_GLOBAL_MINUTE_WINDOW );
		$this->freeze_relay_counter_reads();

		// A holds the last per-IP slot when B runs; A is then refused at the
		// full minute ceiling. Both refusals must leave both counters as found.
		$outcomes = array();
		$this->interleave_at(
			$minute_key,
			function () use ( $ip ) {
				return $this->outcome( $this->relay_from( $ip ) );
			},
			$outcomes
		);
		$outcomes[] = $this->outcome( $this->relay_from( $ip ) );

		$this->assertTrue( $this->interleaved, 'Precondition: the second request ran inside the increment of the first.' );
		$this->assertSame( 0, $this->count_of( $outcomes, 'admitted' ), wp_json_encode( $outcomes ) );
		$this->assertSame( Mcpwp_REST_Feedback::RELAY_RATE_MAX - 1, $this->counter( $ip_key ) );
		$this->assertSame( Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE, $this->counter( $minute_key ) );
	}

	public function test_request_taking_the_last_hourly_slot_inside_anothers_increment_leaves_that_one_refused(): void {
		$hour_key   = $this->hour_key();
		$minute_key = $this->minute_key();
		$this->seed_counter( $hour_key, Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX - 1, Mcpwp_REST_Feedback::RELAY_GLOBAL_WINDOW );
		$this->freeze_relay_counter_reads();

		// A has passed its per-IP and per-minute counters and seen one hourly
		// slot left; B, from another address, takes it first.
		$outcomes = array();
		$this->interleave_at(
			$hour_key,
			function () {
				return $this->outcome( $this->relay_from( '198.51.100.9' ) );
			},
			$outcomes
		);
		$outcomes[] = $this->outcome( $this->relay_from( '198.51.100.8' ) );

		$this->assertTrue( $this->interleaved, 'Precondition: the second request ran inside the increment of the first.' );
		$this->assertSame( 1, $this->count_of( $outcomes, 'admitted' ), 'One slot, one admission: ' . wp_json_encode( $outcomes ) );
		$this->assertSame( Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX, $this->counter( $hour_key ) );
		$this->assertSame( 1, $this->counter( $minute_key ), 'The refused request must hand back its per-minute slot.' );
		$this->assertSame( 0, $this->counter( $this->ip_key( '198.51.100.8' ) ), 'The refused request must hand back its per-IP slot.' );
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

	public function test_counter_evicted_between_add_and_incr_on_core_or_memcached_semantics_refuses_and_releases_earlier_slots(): void {
		$ip         = '203.0.113.31';
		$minute_key = $this->minute_key();

		// The per-minute key vanishes between its add and its incr (LRU
		// eviction under memory pressure). Core and memcached answer false for
		// an incr on a missing key; Redis does not — see the TTL tests below.
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
	// Keys expire with their window.
	// ---------------------------------------------------------------

	public function test_counter_keys_expire_with_their_window(): void {
		$ip = '203.0.113.40';

		$this->assertSame( 'admitted', $this->outcome( $this->relay_from( $ip ) ) );

		$expected = array(
			$this->ip_key( $ip ) => Mcpwp_REST_Feedback::RELAY_RATE_WINDOW,
			$this->minute_key()  => Mcpwp_REST_Feedback::RELAY_GLOBAL_MINUTE_WINDOW,
			$this->hour_key()    => Mcpwp_REST_Feedback::RELAY_GLOBAL_WINDOW,
		);
		foreach ( $expected as $key => $window ) {
			$id = self::GROUP . ':' . $key;
			$this->assertArrayHasKey( $id, $GLOBALS['mcpwp_test_object_cache'], "Counter $key must be created in the admission group." );
			$this->assertSame( 1, $GLOBALS['mcpwp_test_object_cache'][ $id ]['value'] );
			$this->assertGreaterThan( 0, $GLOBALS['mcpwp_test_object_cache'][ $id ]['ttl'], 'TTL 0 means "never expires" to an object cache.' );
			$this->assertSame( $window, $GLOBALS['mcpwp_test_object_cache'][ $id ]['ttl'] );
		}
	}

	public function test_counter_recreated_by_a_redis_increment_after_eviction_gets_its_window_ttl_back(): void {
		$GLOBALS['mcpwp_test_object_cache_incr_creates'] = true;
		$minute_key                                      = $this->minute_key();

		// Evicted between add and incr: Redis INCRBY recreates the key at 1
		// with no expiry, and admits.
		$GLOBALS['mcpwp_test_object_cache_hook'] = static function ( $op, $key, $group ) use ( $minute_key ) {
			if ( 'incr' === $op && $minute_key === $key ) {
				unset( $GLOBALS['mcpwp_test_object_cache'][ $group . ':' . $key ] );
			}
			return null;
		};

		$this->assertSame( 'admitted', $this->outcome( $this->relay_from( '203.0.113.70' ) ) );

		$entry = $GLOBALS['mcpwp_test_object_cache'][ self::GROUP . ':' . $minute_key ] ?? null;
		$this->assertNotNull( $entry );
		$this->assertSame( 1, $entry['value'] );
		$this->assertSame(
			Mcpwp_REST_Feedback::RELAY_GLOBAL_MINUTE_WINDOW,
			$entry['ttl'],
			'A key recreated without expiry must get its window TTL back, or it lives forever.'
		);
	}

	public function test_counter_recreated_by_a_redis_rollback_after_eviction_gets_its_window_ttl_back(): void {
		$GLOBALS['mcpwp_test_object_cache_incr_creates'] = true;
		$ip                                              = '203.0.113.71';
		$ip_key                                          = $this->ip_key( $ip );
		$this->seed_counter( $this->minute_key(), Mcpwp_REST_Feedback::RELAY_GLOBAL_MAX_PER_MINUTE, Mcpwp_REST_Feedback::RELAY_GLOBAL_MINUTE_WINDOW );

		// Refused at the minute ceiling; its per-IP key is evicted before the
		// rollback, so Redis DECRBY recreates it at -1 with no expiry.
		$GLOBALS['mcpwp_test_object_cache_hook'] = static function ( $op, $key, $group ) use ( $ip_key ) {
			if ( 'decr' === $op && $ip_key === $key ) {
				unset( $GLOBALS['mcpwp_test_object_cache'][ $group . ':' . $key ] );
			}
			return null;
		};

		$this->assertSame( 'rate_limited', $this->outcome( $this->relay_from( $ip ) ) );

		$entry = $GLOBALS['mcpwp_test_object_cache'][ self::GROUP . ':' . $ip_key ] ?? null;
		$this->assertNotNull( $entry );
		$this->assertSame( -1, $entry['value'] );
		$this->assertSame( Mcpwp_REST_Feedback::RELAY_RATE_WINDOW, $entry['ttl'] );
	}

	// ---------------------------------------------------------------
	// The gate is never weaker than the record.
	// ---------------------------------------------------------------

	public function test_gate_is_never_weaker_than_the_accounting_record_when_the_cache_does_not_persist(): void {
		// A drop-in present but not persisting this group, undetectably, hands
		// every request a fresh counter. The transient record must still bound
		// the source.
		$ip       = '203.0.113.41';
		$outcomes = array();
		for ( $i = 0; $i < Mcpwp_REST_Feedback::RELAY_RATE_MAX + 5; $i++ ) {
			$GLOBALS['mcpwp_test_object_cache'] = array();
			$outcome                            = $this->outcome( $this->relay_from( $ip ) );
			if ( 'admitted' === $outcome ) {
				$this->assertSame( 1, $this->counter( $this->ip_key( $ip ) ), 'Admission must have gone through the increment gate.' );
			}
			$outcomes[] = $outcome;
		}

		$this->assertSame( Mcpwp_REST_Feedback::RELAY_RATE_MAX, $this->count_of( $outcomes, 'admitted' ) );
		$this->assertSame( 5, $this->count_of( $outcomes, 'rate_limited' ) );
	}

	// ---------------------------------------------------------------
	// A backend lost inside the request is not an admission.
	// ---------------------------------------------------------------

	/**
	 * Model Redis Object Cache losing its backend on the first $flip_op in the
	 * admission group: that operation fails, redis_status() turns false, and
	 * from then on the drop-in answers from a per-request array — where an
	 * increment of a key it has never seen returns 1.
	 */
	private function lose_the_backend_on( string $flip_op ): void {
		$GLOBALS['mcpwp_test_object_cache_hook'] = static function ( $op, $key, $group ) use ( $flip_op ) {
			if ( self::GROUP !== $group ) {
				return null;
			}
			$cache = $GLOBALS['wp_object_cache'];
			if ( $cache->connected ) {
				if ( $flip_op === $op ) {
					$cache->connected = false;
					return false;
				}
				return null;
			}
			if ( 'incr' === $op ) {
				return 1;
			}
			if ( 'decr' === $op ) {
				return 0;
			}
			return 'add' === $op ? true : null;
		};
	}

	public function test_backend_lost_inside_the_gate_refuses_instead_of_admitting_from_memory(): void {
		// Redis refusing writes while serving reads (OOM under noeviction,
		// READONLY replica, MISCONF) on a client that throws: the gate's own
		// add is where the drop-in loses its backend.
		$this->lose_the_backend_on( 'add' );

		$result = $this->relay_from( '203.0.113.80' );

		$this->assertSame( 'relay_unavailable', $this->outcome( $result ), 'An increment answered from memory is not a count; the token must not be spent on it.' );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->relay_transients(), 'Nothing is written once the backend is gone.' );
		$this->assertSame( 'fail_closed', Mcpwp_REST_Feedback::relay_admission_decision()['mode'], 'Later requests are refused up front.' );
	}

	public function test_backend_lost_while_writing_the_accounting_record_refuses_before_any_spend(): void {
		// The gate runs against a healthy backend; the loss happens on the
		// first transient write, after which every write "succeeds" in memory.
		$GLOBALS['mcpwp_test_transient_write_fails'] = static function () {
			$GLOBALS['wp_object_cache']->connected = false;
			return false;
		};

		$result = $this->relay_from( '203.0.113.81' );

		$this->assertSame( 'relay_unavailable', $this->outcome( $result ), 'The decision must be read again before the token is spent.' );
		$this->assertSame( 503, $result->get_error_data()['status'] );
	}

	public function test_a_memcached_failure_reported_mid_request_does_not_refuse_counts_that_are_real(): void {
		// A pool member's failure is reported during the request (pecl memcache
		// fires its failure callback on the first real connect attempt), after
		// the decision was read. That drop-in has no in-memory fallback: the
		// counts just taken live on the surviving servers, so refusing would
		// burn budget and protect nothing.
		$GLOBALS['wp_object_cache']              = new Mcpwp_Test_Memcached_Object_Cache();
		$GLOBALS['mcpwp_test_object_cache_hook'] = static function ( $op, $key, $group ) {
			if ( 'incr' === $op && self::GROUP === $group && array() === $GLOBALS['wp_object_cache']->connection_errors ) {
				$GLOBALS['wp_object_cache']->connection_errors[] = array(
					'host' => '10.0.0.2',
					'port' => 11211,
				);
			}
			return null;
		};
		$ip = '203.0.113.83';

		$this->assertSame( 'admitted', $this->outcome( $this->relay_from( $ip ) ), 'Counts that are real are not refused because the report changed.' );
		$this->assertSame( 'object_cache_pool_degraded', Mcpwp_REST_Feedback::relay_admission_decision()['basis'], 'Precondition: the basis changed inside the request.' );
		$this->assertSame( 1, $this->counter( $this->ip_key( $ip ) ) );
	}

	public function test_admission_group_made_non_persistent_mid_request_refuses_before_any_spend(): void {
		// A plugin marks the admission group non-persistent while the gate
		// runs, so Redis Object Cache sends that group's later operations to a
		// per-request array. The backend is up and nothing refuses, but the
		// gate stopped counting anything that outlives the request.
		$GLOBALS['mcpwp_test_object_cache_hook'] = static function ( $op, $key, $group ) {
			$cache = $GLOBALS['wp_object_cache'];
			if ( 'incr' === $op && self::GROUP === $group && ! in_array( self::GROUP, $cache->ignored_groups, true ) ) {
				$cache->ignored_groups[] = self::GROUP;
			}
			return null;
		};

		$result = $this->relay_from( '203.0.113.84' );

		$this->assertSame( 'relay_unavailable', $this->outcome( $result ), 'A gate that stopped counting mid-request cannot vouch for this admission.' );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$decision = Mcpwp_REST_Feedback::relay_admission_decision();
		$this->assertFalse( $decision['refuse'], 'Precondition: nothing refuses; only the gate flag changed.' );
		$this->assertFalse( $decision['gate'] );
	}

	public function test_backend_lost_on_a_stage_one_read_with_the_gate_off_refuses_before_any_spend(): void {
		// Gate off (the admission group is non-persistent), so the re-check
		// before the GitHub call is the only defence. A relay counter read
		// times out: the drop-in falls to memory, the read counts as zero and
		// every write after it "succeeds".
		$GLOBALS['wp_object_cache']->ignored_groups = array( self::GROUP );
		$this->assertFalse( Mcpwp_REST_Feedback::relay_admission_decision()['gate'], 'Precondition: the gate does not run.' );

		$GLOBALS['mcpwp_test_transient_hook'] = static function ( $name ) {
			if ( 0 === strpos( (string) $name, 'mcpwp_relay_' ) ) {
				$GLOBALS['wp_object_cache']->connected = false;
			}
			return null;
		};

		$result = $this->relay_from( '203.0.113.85' );

		$this->assertSame( 'relay_unavailable', $this->outcome( $result ), 'With the gate off, the last re-check must still stop the spend.' );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->admission_keys(), 'The gate did not run.' );
	}

	public function test_admission_rests_on_the_value_the_increment_returned_not_on_a_re_read(): void {
		$ip = '203.0.113.82';
		$this->seed_counter( $this->ip_key( $ip ), Mcpwp_REST_Feedback::RELAY_RATE_MAX, Mcpwp_REST_Feedback::RELAY_RATE_WINDOW );

		// Every read of a counter finds it gone, as if evicted between the
		// increment and the read. Only the increment's own result is evidence.
		$GLOBALS['mcpwp_test_object_cache_hook'] = static function ( $op, $key, $group ) {
			return ( 'get' === $op && self::GROUP === $group ) ? false : null;
		};

		$this->assertSame( 'rate_limited', $this->outcome( $this->relay_from( $ip ) ) );
		$this->assertSame( Mcpwp_REST_Feedback::RELAY_RATE_MAX, $this->counter( $this->ip_key( $ip ) ) );
	}

	// ---------------------------------------------------------------
	// One decision: what is admitted is what is reported.
	// ---------------------------------------------------------------

	public static function admission_states(): array {
		return array(
			'no persistent object cache'                   => array( 'no_object_cache', 'approximate', 'no_persistent_object_cache', 'admitted', false ),
			'redis object cache, connected'                => array( 'redis', 'atomic', 'redis_object_cache', 'admitted', true ),
			'redis object cache, backend unreachable'      => array( 'redis_unreachable', 'fail_closed', 'object_cache_backend_unreachable', 'relay_unavailable', false ),
			'redis object cache, admission group ignored'  => array( 'redis_group_ignored', 'approximate', 'admission_group_not_persistent', 'admitted', false ),
			'cache addition suspended'                     => array( 'addition_suspended', 'approximate', 'cache_addition_suspended', 'admitted', false ),
			'memcached object cache, connected'            => array( 'memcached', 'atomic', 'memcached_object_cache', 'admitted', true ),
			'memcached object cache, a pool member down'  => array( 'memcached_errors', 'approximate', 'object_cache_pool_degraded', 'admitted', true ),
			'memcached object cache, group non-persistent' => array( 'memcached_group_non_persistent', 'approximate', 'admission_group_not_persistent', 'admitted', false ),
			'unrecognised object-cache drop-in'            => array( 'unrecognised', 'unverified', 'unrecognised_object_cache', 'admitted', true ),
		);
	}

	private function arrange( string $state ): void {
		switch ( $state ) {
			case 'no_object_cache':
				$GLOBALS['mcpwp_test_ext_object_cache'] = false;
				unset( $GLOBALS['wp_object_cache'] );
				break;
			case 'redis':
				break;
			case 'redis_unreachable':
				// What Redis Object Cache does after a failed connection or an
				// exception: redis_status() false, everything kept in memory.
				$GLOBALS['wp_object_cache']->connected = false;
				break;
			case 'redis_group_ignored':
				$GLOBALS['wp_object_cache']->ignored_groups = array( 'counts', self::GROUP );
				break;
			case 'addition_suspended':
				$GLOBALS['mcpwp_test_cache_addition_suspended'] = true;
				break;
			case 'memcached':
				$GLOBALS['wp_object_cache'] = new Mcpwp_Test_Memcached_Object_Cache();
				break;
			case 'memcached_errors':
				$GLOBALS['wp_object_cache']                    = new Mcpwp_Test_Memcached_Object_Cache();
				$GLOBALS['wp_object_cache']->connection_errors = array(
					array(
						'host' => '127.0.0.1',
						'port' => 11211,
					),
				);
				break;
			case 'memcached_group_non_persistent':
				$GLOBALS['wp_object_cache']               = new Mcpwp_Test_Memcached_Object_Cache();
				$GLOBALS['wp_object_cache']->no_mc_groups = array( self::GROUP );
				// What that drop-in does with such a group: add() keeps the key
				// in-process and reports success; incr() and decr() ask the
				// server, which has never seen the key.
				$GLOBALS['mcpwp_test_object_cache_hook'] = static function ( $op, $key, $group ) {
					if ( self::GROUP !== $group ) {
						return null;
					}
					if ( 'add' === $op ) {
						return true;
					}
					return ( 'incr' === $op || 'decr' === $op ) ? false : null;
				};
				break;
			case 'unrecognised':
				$GLOBALS['wp_object_cache'] = new stdClass();
				break;
			default:
				$this->fail( "Unknown state $state" );
		}
	}

	/**
	 * @dataProvider admission_states
	 */
	public function test_admission_and_report_follow_one_decision( string $state, string $mode, string $basis, string $outcome, bool $gate_runs ): void {
		$this->arrange( $state );

		$status = $this->rate_limit_status();
		$this->assertSame( $mode, $status['relay_admission'] ?? null, 'Never claim more than the drop-in shows.' );
		$this->assertSame( $basis, $status['relay_admission_basis'] ?? null, 'The report must say why, not only what.' );

		$decision = Mcpwp_REST_Feedback::relay_admission_decision();
		$this->assertSame( array( $mode, $basis ), array( $decision['mode'], $decision['basis'] ), 'Admission and the report must read one decision.' );

		$result = $this->relay_from( '203.0.113.60' );
		$this->assertSame( $outcome, $this->outcome( $result ) );
		$this->assertSame( $gate_runs, array() !== $this->admission_keys(), $gate_runs ? 'The increment gate must run.' : 'The increment gate must not run.' );
		if ( 'relay_unavailable' === $outcome ) {
			$this->assertSame( array(), $this->relay_transients(), 'A relay that fails closed writes nothing.' );
		}
	}

	public function test_approximate_mode_is_used_and_named_when_no_persistent_cache_is_present(): void {
		$GLOBALS['mcpwp_test_ext_object_cache'] = false;

		$outcomes = array();
		for ( $i = 0; $i < Mcpwp_REST_Feedback::RELAY_RATE_MAX + 1; $i++ ) {
			$outcomes[] = $this->outcome( $this->relay_from( '203.0.113.50' ) );
		}

		$this->assertSame( Mcpwp_REST_Feedback::RELAY_RATE_MAX, $this->count_of( $outcomes, 'admitted' ) );
		$this->assertSame( 1, $this->count_of( $outcomes, 'rate_limited' ) );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_object_cache'], 'A per-request cache must not be mistaken for an admission gate.' );
		$this->assertSame( 'approximate', Mcpwp_REST_Feedback::relay_admission_decision()['mode'] );
	}

	public function test_rate_limit_status_reports_the_relay_admission_mode_in_force(): void {
		$atomic = $this->rate_limit_status();

		$GLOBALS['mcpwp_test_ext_object_cache'] = false;
		$approximate                            = $this->rate_limit_status();

		$this->assertSame( 'atomic', $atomic['relay_admission'] ?? null );
		$this->assertSame( 'approximate', $approximate['relay_admission'] ?? null );
		$this->assertNotSame(
			array( $atomic['relay_admission'] ?? null, $atomic['relay_admission_basis'] ?? null ),
			array( $approximate['relay_admission'] ?? null, $approximate['relay_admission_basis'] ?? null )
		);

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
