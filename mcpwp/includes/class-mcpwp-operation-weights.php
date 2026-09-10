<?php
/**
 * Operation weights.
 *
 * What one tool call costs the weighted rate limiter (#802). A call is charged
 * for the work it will do, not for being one HTTP message: a list of items
 * costs its length, and a tool that acts on many posts without carrying a list
 * costs a fixed weight from one map.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operation weights.
 */
final class Mcpwp_Operation_Weights {

	/**
	 * Tools that act on many posts without carrying a list of them.
	 *
	 * wp_bulk_find_replace rewrites Elementor data across a post type or the
	 * whole site, up to 200 posts a run; wp_delete_all_drafts removes every
	 * draft. Weighed by their list arguments alone, each cost one slot. `ids`
	 * names the argument an explicit id list arrives in; a list longer than the
	 * weight is charged per id.
	 *
	 * @var array<string,array{weight:int,ids:string}>
	 */
	const TOOL_WEIGHTS = array(
		'wp_bulk_find_replace' => array(
			'weight' => 10,
			'ids'    => 'ids',
		),
		'wp_delete_all_drafts' => array(
			'weight' => 10,
			'ids'    => 'ids',
		),
	);

	/**
	 * Arguments that fan out into one operation per item.
	 *
	 * Aliases of one list sit in one group and are charged once — the largest
	 * (urls beside items is one upload list spelled twice); genuinely different
	 * lists are charged each — the sum (pages beside posts). #802 rounds 4–5.
	 *
	 * @var array<int,string[]>
	 */
	const LIST_GROUPS = array(
		array( 'operations' ),
		array( 'updates', 'items', 'urls', 'files', 'media' ),
		array( 'pages' ),
		array( 'posts' ),
		array( 'page_ids', 'post_ids' ),
		array( 'rows' ),
		array( 'widgets' ),
		array( 'sections' ),
	);

	/**
	 * How many items the list arguments of a call carry. Zero when none.
	 *
	 * @param mixed $arguments Tool or route arguments.
	 * @return int
	 */
	public static function list_count( $arguments ) {
		if ( ! is_array( $arguments ) ) {
			return 0;
		}

		$count = 0;
		foreach ( self::LIST_GROUPS as $group ) {
			$largest = 0;
			foreach ( $group as $key ) {
				if ( isset( $arguments[ $key ] ) && is_array( $arguments[ $key ] ) ) {
					$largest = max( $largest, count( $arguments[ $key ] ) );
				}
			}
			$count += $largest;
		}

		return $count;
	}

	/**
	 * Rate-limit slots one tool call costs.
	 *
	 * The largest of: one; the items its lists carry; the tool's own weight —
	 * TOOL_WEIGHTS, else 1 — as filtered by `mcpwp_tool_operation_weight`; and
	 * the length of an explicit id list for a tool in the map. A filter can move
	 * a tool's own weight up or down, never below the lists the call carries,
	 * and a filtered value that is not a weight leaves the default in place.
	 *
	 * @param string $tool_name Tool name ('' when unknown).
	 * @param mixed  $arguments Tool arguments.
	 * @return int At least 1.
	 */
	public static function tool_weight( $tool_name, $arguments ) {
		$arguments = is_array( $arguments ) ? $arguments : array();
		$weight    = max( 1, self::list_count( $arguments ) );

		if ( ! is_string( $tool_name ) || '' === $tool_name ) {
			return $weight;
		}

		$spec    = array_key_exists( $tool_name, self::TOOL_WEIGHTS ) ? self::TOOL_WEIGHTS[ $tool_name ] : null;
		$default = null !== $spec ? (int) $spec['weight'] : 1;
		$own     = $default;

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filters the rate-limit weight of one tool call (#802).
			 *
			 * The call is still charged at least the items its list arguments
			 * carry, and a value that is not a whole number of at least 1 is
			 * ignored.
			 *
			 * @param int    $weight    Default: the TOOL_WEIGHTS entry, else 1.
			 * @param string $tool_name Tool name.
			 * @param array  $arguments Tool arguments.
			 */
			$own = self::read_amount( apply_filters( 'mcpwp_tool_operation_weight', $default, $tool_name, $arguments ), $default, 1 );
		}

		$weight = max( $weight, $own );

		if ( null !== $spec && isset( $arguments[ $spec['ids'] ] ) && is_array( $arguments[ $spec['ids'] ] ) ) {
			$weight = max( $weight, count( $arguments[ $spec['ids'] ] ) );
		}

		return $weight;
	}

	/**
	 * A filtered amount when it is one, else the default.
	 *
	 * A filter that returns something that is not a whole number at or above
	 * $min — a word, null, a bool, an array, NaN, a negative — has a bug. A bug
	 * must not turn a price into zero or a ceiling into none, so it reads as
	 * the default, not as the value PHP would coerce it to.
	 *
	 * @param mixed $value   Filtered value.
	 * @param int   $default Default.
	 * @param int   $min     Smallest acceptable value.
	 * @return int
	 */
	public static function read_amount( $value, $default, $min ) {
		if ( is_int( $value ) ) {
			return $value >= $min ? $value : (int) $default;
		}

		if ( is_float( $value ) ) {
			return ( is_finite( $value ) && $value >= $min && $value < PHP_INT_MAX ) ? (int) $value : (int) $default;
		}

		if ( is_string( $value ) && 1 === preg_match( '/^[0-9]{1,18}$/', $value ) ) {
			return (int) $value >= $min ? (int) $value : (int) $default;
		}

		return (int) $default;
	}
}
