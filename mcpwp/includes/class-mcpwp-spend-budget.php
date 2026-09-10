<?php
/**
 * Per-request spend budget (#752 F9).
 *
 * The rate limiter bounds how many HTTP requests a caller sends. It does not
 * bound what one request can make the site do: a JSON-RPC batch of tool calls,
 * each a wp_batch of 100 operations, each operation a bulk route or a call
 * against the site's OpenAI key, multiplies into thousands of writes or
 * hundreds of paid generations — and a whitelisted key, or a site with the
 * limiter off, is not metered at all.
 *
 * This is the second ceiling: one budget per top-level HTTP request, shared by
 * its whole fan-out tree, drawn down by every operation that tree dispatches.
 *
 * - The entry points that fan out (/mcp and /batch) call open() before their
 *   first dispatch. The first one opens the budget; one reached from inside
 *   the tree (wp_batch inside /mcp) joins it — a nested dispatch can never
 *   reset or widen it. Only the opener closes it, in a finally block, so the
 *   state cannot outlive its request even when a handler throws, in a process
 *   that serves many requests.
 * - Operations are charged in core's rest_dispatch_request filter: after
 *   WordPress has matched the handler and run its permission callback, before
 *   the handler runs. One chokepoint for every dispatch in the tree — batch
 *   operations, tool calls, resources/read — priced from the route WordPress is
 *   about to run, however the caller spelled it.
 * - An operation that does not fit is refused with `spend_budget_exhausted`
 *   and does not run. From the first refusal on, every later operation in the
 *   request is refused too, so the refused set is always the tail to resend.
 *   Nothing that already ran is undone.
 *
 * Nothing a caller sends can raise the budget: it is read from
 * `mcpwp_request_spend_budget` when the budget opens, and lives in this PHP
 * process only.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-request spend budget.
 */
final class Mcpwp_Spend_Budget {

	/**
	 * Units one top-level request may spend (#752 decision, 2026-09-06).
	 */
	const DEFAULT_BUDGET = 1000;

	/**
	 * Error code of a refused operation.
	 */
	const ERROR_CODE = 'spend_budget_exhausted';

	/**
	 * HTTP status of a refused operation: the request is larger than the site
	 * will run in one go. Not 429 — waiting does not help; a smaller request
	 * does.
	 */
	const HTTP_STATUS = 413;

	/**
	 * JSON-RPC error code of a refused tool call.
	 */
	const JSONRPC_CODE = -32030;

	/**
	 * The charge runs after every other rest_dispatch_request filter, so a
	 * refusal is the last word and a handler another filter already answered
	 * (and so never runs) is not charged.
	 */
	const HOOK_PRIORITY = PHP_INT_MAX;

	/**
	 * The open budget, or null between requests.
	 *
	 * @var array{token:int,limit:int,spent:int,exhausted:bool}|null
	 */
	private static $frame = null;

	/**
	 * Source of opener tokens.
	 *
	 * @var int
	 */
	private static $sequence = 0;

	/**
	 * Open the request's budget, or join the one already open.
	 *
	 * @param WP_REST_Request|null $request The request opening it, for the filter.
	 * @return int|null Token for close() when this call opened the budget; null when it joined one.
	 */
	public static function open( $request = null ) {
		self::arm();

		if ( null !== self::$frame ) {
			return null;
		}

		++self::$sequence;

		self::$frame = array(
			'token'     => self::$sequence,
			'limit'     => self::resolve_limit( $request ),
			'spent'     => 0,
			'exhausted' => false,
		);

		return self::$sequence;
	}

	/**
	 * Close the budget this caller opened. A joiner's null token closes nothing.
	 *
	 * @param int|null $token Token open() returned.
	 */
	public static function close( $token ) {
		if ( null !== $token && null !== self::$frame && self::$frame['token'] === $token ) {
			self::$frame = null;
		}
	}

	/**
	 * Register the charge on rest_dispatch_request, once.
	 *
	 * Called by open(), so the charge is in place before the first operation of
	 * any budgeted request dispatches — it does not depend on a loader line
	 * somewhere else having run.
	 */
	public static function arm() {
		if ( ! function_exists( 'add_filter' ) || ! function_exists( 'has_filter' ) ) {
			return;
		}

		$callback = array( __CLASS__, 'filter_dispatch' );

		if ( false === has_filter( 'rest_dispatch_request', $callback ) ) {
			add_filter( 'rest_dispatch_request', $callback, self::HOOK_PRIORITY, 4 );
		}
	}

	/**
	 * Charge one operation that is about to run.
	 *
	 * @param int $weight Units it costs. Zero is free (a container whose own operations are charged).
	 * @return true|WP_Error
	 */
	public static function charge( $weight ) {
		if ( null === self::$frame ) {
			return true;
		}

		$weight = (int) $weight;

		if ( $weight <= 0 ) {
			return true;
		}

		$limit     = self::$frame['limit'];
		$remaining = max( 0, $limit - self::$frame['spent'] );

		if ( self::$frame['exhausted'] || $weight > $remaining ) {
			self::$frame['exhausted'] = true;
			return self::refusal( $weight, $remaining, $limit );
		}

		self::$frame['spent'] += $weight;

		return true;
	}

	/**
	 * Refuse up front once an earlier operation of this request was refused.
	 *
	 * For callers that would otherwise spend something else (rate-limit slots)
	 * before the dispatch-time charge refuses.
	 *
	 * @return true|WP_Error
	 */
	public static function refuse_if_exhausted() {
		if ( null === self::$frame || ! self::$frame['exhausted'] ) {
			return true;
		}

		return self::refusal( null, max( 0, self::$frame['limit'] - self::$frame['spent'] ), self::$frame['limit'] );
	}

	/**
	 * The open budget as a response reports it; null when none is open.
	 *
	 * @return array{limit:int,spent:int,remaining:int,exhausted:bool}|null
	 */
	public static function report() {
		if ( null === self::$frame ) {
			return null;
		}

		return array(
			'limit'     => self::$frame['limit'],
			'spent'     => self::$frame['spent'],
			'remaining' => max( 0, self::$frame['limit'] - self::$frame['spent'] ),
			'exhausted' => self::$frame['exhausted'],
		);
	}

	/**
	 * rest_dispatch_request callback: charge the operation WordPress is about
	 * to run, or refuse it.
	 *
	 * @param mixed           $result  Null unless an earlier filter answered.
	 * @param WP_REST_Request $request Request being dispatched.
	 * @param string          $route   Route pattern WordPress matched.
	 * @param array           $handler Matched handler.
	 * @return mixed The unchanged $result, or a WP_Error refusal.
	 */
	public static function filter_dispatch( $result, $request = null, $route = '', $handler = array() ) {
		if ( null !== $result || null === self::$frame || ! is_object( $request ) ) {
			return $result;
		}

		$method = method_exists( $request, 'get_method' ) ? $request->get_method() : 'GET';
		$params = method_exists( $request, 'get_params' ) ? $request->get_params() : array();
		$asked  = method_exists( $request, 'get_route' ) ? $request->get_route() : '';

		// Priced from the matched pattern and from the request's own route,
		// whichever costs more: the pattern is what WordPress will run, the
		// route is what the caller asked for.
		$weight = Mcpwp_Operation_Weights::spend_weight( $method, is_string( $route ) && '' !== $route ? $route : $asked, $params );
		if ( is_string( $asked ) && $asked !== $route ) {
			$weight = max( $weight, Mcpwp_Operation_Weights::spend_weight( $method, $asked, $params ) );
		}

		$charge = self::charge( $weight );

		return is_wp_error( $charge ) ? $charge : $result;
	}

	/**
	 * The budget for a request opening now.
	 *
	 * @param WP_REST_Request|null $request Request opening the budget.
	 * @return int
	 */
	private static function resolve_limit( $request ) {
		if ( ! function_exists( 'apply_filters' ) ) {
			return self::DEFAULT_BUDGET;
		}

		/**
		 * Filters the spend budget of one top-level HTTP request (#752 F9).
		 *
		 * Every operation the request dispatches — directly, or through a
		 * nested batch — draws from this one number. A value that is not a whole
		 * number of at least 0 is ignored: the default applies, never "no
		 * limit". Return a large number to raise it.
		 *
		 * @param int                  $budget  Units. Default 1000.
		 * @param WP_REST_Request|null $request The /mcp or /batch request opening it.
		 */
		$filtered = apply_filters( 'mcpwp_request_spend_budget', self::DEFAULT_BUDGET, $request );

		return Mcpwp_Operation_Weights::read_amount( $filtered, self::DEFAULT_BUDGET, 0 );
	}

	/**
	 * Build a refusal.
	 *
	 * @param int|null $weight    Units the refused operation costs; null when refused before it was priced.
	 * @param int      $remaining Units left.
	 * @param int      $limit     Budget.
	 * @return WP_Error
	 */
	private static function refusal( $weight, $remaining, $limit ) {
		$data = array(
			'status'    => self::HTTP_STATUS,
			'remaining' => (int) $remaining,
			'limit'     => (int) $limit,
			'hint'      => 'This operation did not run, and neither will any later operation in this request. The budget belongs to one HTTP request, so waiting does not help: send the refused operations in a new request. AI generation, text-to-speech, image description and URL imports cost 25 units each (per URL in /media/bulk); a bulk route costs the items it processes; everything else costs 1.',
		);

		if ( null !== $weight ) {
			$data['weight'] = (int) $weight;
			$message        = sprintf( 'Spend budget exhausted: this operation costs %1$d units and %2$d of this request\'s %3$d remain.', (int) $weight, (int) $remaining, (int) $limit );
		} else {
			$message = sprintf( 'Spend budget exhausted: an earlier operation in this request was refused (%1$d of %2$d units remain).', (int) $remaining, (int) $limit );
		}

		return new WP_Error( self::ERROR_CODE, $message, $data );
	}
}
