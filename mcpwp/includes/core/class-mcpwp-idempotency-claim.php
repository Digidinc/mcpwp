<?php
/**
 * The one atomic primitive behind idempotent tool calls (#802).
 *
 * The first cut claimed a request_id with add_option(). Adversarial review
 * showed that is not atomic in WordPress: add_option() is a cached
 * read-then-write, and its INSERT uses ON DUPLICATE KEY UPDATE, so the
 * UNIQUE index on option_name never produces a failure — two concurrent
 * claims both "succeed". The test double had encoded the wrong semantics, so
 * the suite was green over a race.
 *
 * This class talks to the options table directly with a plain INSERT. The
 * UNIQUE index is then the arbiter: exactly one of two simultaneous inserts
 * lands. Reads bypass the option caches for the same reason — a direct
 * insert never updates `notoptions`, so get_option() could keep answering
 * "does not exist".
 *
 * A claim is `<unix time>|<args hash>` under `<store key>_lease`, autoload
 * off. It is released after the done-record is stored, never before — the
 * gap between "lease gone" and "record present" is a guaranteed duplicate
 * window.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic claims on wp_options.
 */
class Mcpwp_Idempotency_Claim {

	const ACQUIRED    = 'acquired';
	const HELD        = 'held';
	const UNAVAILABLE = 'unavailable';

	/**
	 * How often, in claims, the sweep of abandoned leases runs.
	 */
	const SWEEP_EVERY = 50;

	/**
	 * Try to take the claim.
	 *
	 * @param string $name      Option name.
	 * @param int    $now       Time.
	 * @param string $args_hash Hash of the call's arguments.
	 * @return string ACQUIRED, HELD or UNAVAILABLE.
	 */
	public static function acquire( $name, $now, $args_hash ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
			return self::UNAVAILABLE;
		}

		$wpdb->last_error = '';

		// A duplicate-key refusal is the expected path on every retry. wpdb
		// reports it through print_error(), which writes to error_log on every
		// call and, with WP_DEBUG_DISPLAY, prints an HTML block ahead of the
		// JSON body — the corrupted-response failure #802 opens with. Silence
		// it for this one statement; the return value and last_error still
		// tell us what happened.
		$suppressed = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;

		$rows = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => (int) $now . '|' . $args_hash,
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);

		if ( null !== $suppressed ) {
			$wpdb->suppress_errors( $suppressed );
		}

		if ( 1 === (int) $rows ) {
			if ( 0 === wp_rand( 0, self::SWEEP_EVERY - 1 ) ) {
				self::sweep( $now );
			}
			return self::ACQUIRED;
		}

		// The UNIQUE index refused us: held. The message is driver- and
		// locale-specific ("Duplicate entry", "Duplicata du champ", "UNIQUE
		// constraint failed"), so the row itself is the authority and the
		// message only a shortcut.
		if ( preg_match( '/duplicat|unique/i', (string) $wpdb->last_error ) ) {
			return self::HELD;
		}

		if ( null !== self::read( $name ) ) {
			return self::HELD;
		}

		// No refusal we recognise and no row: the store could not arbitrate.
		// The caller must not run unprotected on this answer without first
		// checking for a completed record — see the consumer.
		return self::UNAVAILABLE;
	}

	/**
	 * Read the current claim.
	 *
	 * @param string $name Option name.
	 * @return array|null {at, args_hash, raw} or null when there is none.
	 */
	public static function read( $name ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}

		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$parts = explode( '|', $raw, 2 );

		return array(
			'at'        => (int) $parts[0],
			'args_hash' => isset( $parts[1] ) ? $parts[1] : '',
			'raw'       => $raw,
		);
	}

	/**
	 * Take over an abandoned claim, compare-and-swap on its current value so
	 * two retries racing for the same stale lease cannot both win.
	 *
	 * @param string $name      Option name.
	 * @param string $expected  The raw value seen by read().
	 * @param int    $now       Time.
	 * @param string $args_hash Hash of the call's arguments.
	 * @return bool
	 */
	public static function take_over( $name, $expected, $now, $args_hash ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
			return false;
		}

		$rows = $wpdb->update(
			$wpdb->options,
			array( 'option_value' => (int) $now . '|' . $args_hash ),
			array(
				'option_name'  => $name,
				'option_value' => $expected,
			),
			array( '%s' ),
			array( '%s', '%s' )
		);

		return 1 === (int) $rows;
	}

	/**
	 * Release a claim.
	 *
	 * @param string $name Option name.
	 */
	public static function release( $name ) {
		global $wpdb;

		if ( is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
		}
	}

	/**
	 * Delete leases abandoned for more than a day. A process that died
	 * between claim and release leaves a row nothing else will ever touch.
	 *
	 * @param int $now Time.
	 */
	public static function sweep( $now ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return;
		}

		$cutoff = (int) $now - DAY_IN_SECONDS;

		// Portable across MySQL, MariaDB and the SQLite integration: the
		// value starts with the unix time, so a prefix comparison against the
		// cutoff's digit count is enough (all leases in a plausible window
		// share the same digit count until the year 2286).
		$suppressed = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s",
				$wpdb->esc_like( 'mcpwp_idem_' ) . '%' . $wpdb->esc_like( '_lease' ),
				(string) $cutoff . '|'
			)
		);

		if ( null !== $suppressed ) {
			$wpdb->suppress_errors( $suppressed );
		}
	}
}
