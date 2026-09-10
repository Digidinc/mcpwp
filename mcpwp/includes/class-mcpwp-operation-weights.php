<?php
/**
 * Operation weights.
 *
 * What one operation costs (#802, #752 F9), for both ceilings that price work:
 *
 * - the weighted rate limiter (tool_weight): a call is charged for the work it
 *   will do, not for being one HTTP message — a list of items costs its length,
 *   and a tool that acts on many posts without carrying a list costs a fixed
 *   weight from one map;
 * - the per-request spend budget (spend_weight): the same, plus a price on
 *   credential-spending and egress routes, where the ceiling that matters is
 *   money and outbound traffic rather than CPU.
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
	 * weight is charged per id. `route` is the REST route the tool dispatches, so
	 * a batch operation that reaches the same handler is priced the same.
	 *
	 * @var array<string,array{weight:int,ids:string,route:string}>
	 */
	const TOOL_WEIGHTS = array(
		'wp_bulk_find_replace' => array(
			'weight' => 10,
			'ids'    => 'ids',
			'route'  => '/elementor/find-replace',
		),
		'wp_delete_all_drafts' => array(
			'weight' => 10,
			'ids'    => 'ids',
			'route'  => '/drafts/delete-all',
		),
	);

	/**
	 * Spend units of one credential-spending or egress call (#752 F9).
	 */
	const SPEND_EGRESS_WEIGHT = 25;

	/**
	 * Routes that spend a stored provider credential or fetch from the network,
	 * one call each. Relative to the plugin namespace, lower case.
	 *
	 * @var string[]
	 */
	const SPEND_EGRESS_ROUTES = array(
		'/integrations/generate-image',
		'/integrations/generate-featured-image',
		'/integrations/text-to-speech',
		'/integrations/describe-image',
		'/integrations/generate-alt-text',
		'/integrations/generate-excerpt',
		'/media/from-url',
	);

	/**
	 * Routes that fetch one URL per item, and the arguments the items arrive
	 * in. The larger list is the price, whichever one the handler reads.
	 *
	 * @var array<string,string[]>
	 */
	const SPEND_URL_ITEM_ROUTES = array(
		'/media/bulk' => array( 'urls', 'items' ),
	);

	/**
	 * Bulk routes whose items arrive as an id list — an array, or one
	 * comma-separated string — rather than a list of objects, and the argument
	 * it arrives in. Each id is one item processed.
	 *
	 * @var array<string,string>
	 */
	const SPEND_ID_LIST_ROUTES = array(
		'/elementor/bulk' => 'ids',
	);

	/**
	 * Entry points that only dispatch other operations. Each operation they
	 * dispatch is charged as it runs, so the container itself is free —
	 * charging it too would bill every operation twice.
	 *
	 * @var string[]
	 */
	const SPEND_CONTAINER_ROUTES = array(
		'/batch',
		'/mcp',
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
	 * Spend units one dispatched operation costs (#752 F9).
	 *
	 * - a container (/batch, /mcp): 0 — its operations are charged one by one;
	 * - a credential-spending or egress route: 25;
	 * - /media/bulk: 25 per URL item;
	 * - /elementor/bulk: one per id it reads;
	 * - a route a TOOL_WEIGHTS tool dispatches: that tool's weight;
	 * - anything else: the items its list arguments carry, at least 1.
	 *
	 * The route is normalised first (see normalize_route()), so the price does
	 * not depend on how the caller spelled a path WordPress routes the same way.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route, with or without the /mcpwp/vN prefix.
	 * @param mixed  $params Request parameters.
	 * @return int At least 0.
	 */
	public static function spend_weight( $method, $route, $params ) {
		$path    = self::normalize_route( $route );
		$params  = is_array( $params ) ? $params : array();
		$default = self::default_spend_weight( $path, $params );

		if ( ! function_exists( 'apply_filters' ) ) {
			return $default;
		}

		/**
		 * Filters the spend units one dispatched operation costs (#752 F9).
		 *
		 * May run twice for one dispatch — for the route pattern WordPress
		 * matched and for the route the caller sent — and the larger price
		 * is charged. A value that is not a whole number of at least 0 is
		 * ignored: the default applies, never "free".
		 *
		 * @param int    $weight Default price.
		 * @param string $path   Normalised route, e.g. "/integrations/generate-image".
		 * @param string $method Upper-case HTTP method.
		 * @param array  $params Request parameters.
		 */
		$filtered = apply_filters( 'mcpwp_spend_weight', $default, $path, is_string( $method ) ? strtoupper( $method ) : '', $params );

		return self::read_amount( $filtered, $default, 0 );
	}

	/**
	 * The unfiltered price of a normalised route.
	 *
	 * @param string $path   Normalised route.
	 * @param array  $params Request parameters.
	 * @return int
	 */
	private static function default_spend_weight( $path, array $params ) {
		if ( in_array( $path, self::SPEND_CONTAINER_ROUTES, true ) ) {
			return 0;
		}

		if ( in_array( $path, self::SPEND_EGRESS_ROUTES, true ) ) {
			return self::SPEND_EGRESS_WEIGHT;
		}

		if ( array_key_exists( $path, self::SPEND_URL_ITEM_ROUTES ) ) {
			$items = 0;
			foreach ( self::SPEND_URL_ITEM_ROUTES[ $path ] as $key ) {
				if ( isset( $params[ $key ] ) && is_array( $params[ $key ] ) ) {
					$items = max( $items, count( $params[ $key ] ) );
				}
			}
			return max( 1, $items ) * self::SPEND_EGRESS_WEIGHT;
		}

		if ( array_key_exists( $path, self::SPEND_ID_LIST_ROUTES ) ) {
			return max( max( 1, self::list_count( $params ) ), self::id_list_count( $params, self::SPEND_ID_LIST_ROUTES[ $path ] ) );
		}

		foreach ( self::TOOL_WEIGHTS as $tool_name => $spec ) {
			if ( $spec['route'] === $path ) {
				return self::tool_weight( $tool_name, $params );
			}
		}

		return max( 1, self::list_count( $params ) );
	}

	/**
	 * How many ids an id-list argument names: array entries, or the non-empty
	 * pieces of a comma-separated string.
	 *
	 * @param array  $params Request parameters.
	 * @param string $key    Argument name.
	 * @return int
	 */
	private static function id_list_count( array $params, $key ) {
		if ( ! isset( $params[ $key ] ) ) {
			return 0;
		}

		if ( is_array( $params[ $key ] ) ) {
			return count( $params[ $key ] );
		}

		if ( ! is_string( $params[ $key ] ) && ! is_int( $params[ $key ] ) ) {
			return 0;
		}

		return count( array_filter( array_map( 'trim', explode( ',', (string) $params[ $key ] ) ), 'strlen' ) );
	}

	/**
	 * A route in the one spelling prices are keyed on.
	 *
	 * WordPress matches REST routes case-insensitively, and core's anchored
	 * matcher lets a trailing newline through; the batch controller collapses
	 * repeated slashes before its own checks. The price must be at least as
	 * forgiving as every one of those, or a spelling that still reaches a paid
	 * handler would be priced as an ordinary one. So: trim, lower-case, cut a
	 * query string or fragment, collapse slashes, drop the /mcpwp/vN prefix and
	 * any trailing slash. A route this cannot normalise prices as an ordinary
	 * operation — never as a free container.
	 *
	 * @param mixed $route Route.
	 * @return string Leading-slash path relative to the plugin namespace; '' when unreadable.
	 */
	public static function normalize_route( $route ) {
		if ( ! is_string( $route ) ) {
			return '';
		}

		$route = strtolower( trim( $route ) );
		$route = substr( $route, 0, strcspn( $route, '?#' ) );
		$route = preg_replace( '#/+#', '/', $route );

		if ( ! is_string( $route ) ) {
			return '';
		}

		$route = preg_replace( '#^/?mcpwp/v[0-9]+(?=/|$)#', '', $route );

		if ( ! is_string( $route ) ) {
			return '';
		}

		return '/' . trim( $route, "/ \t\n\r\0\x0B" );
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
