<?php
/**
 * REST surface for SEO market data — visibility and opportunities.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visibility and opportunity endpoints.
 */
class Mcpwp_REST_SEO_Market extends Mcpwp_REST_API {

	/**
	 * Transient key for the refresh debounce lock.
	 */
	const REFRESH_LOCK = 'mcpwp_seo_visibility_refresh';

	/**
	 * Minimum time between provider fetches.
	 *
	 * Rankings do not move within five minutes, so a second refresh inside the
	 * window can never have anything new to report. This is a cost guard, not
	 * a cache: it never substitutes stale rows for fresh ones (see
	 * refresh_visibility()) — it only refuses to spend the credit again.
	 */
	const REFRESH_DEBOUNCE = 5 * MINUTE_IN_SECONDS;

	/**
	 * Maximum accepted length for a caller-supplied seed keyword.
	 *
	 * Unlike keyword_research() this route had no bound at all before — a read
	 * scoped key could submit an arbitrarily long string as the cache key input.
	 */
	const MAX_SEED_LENGTH = 200;

	/**
	 * Per-site ceiling on distinct paid keyword lookups this route can trigger
	 * in one window.
	 *
	 * get_opportunities() is a READABLE (GET) route driven entirely by a
	 * caller-supplied seed, and the natural reading of the playbook is
	 * "brainstorm candidate seeds" — every distinct seed that misses
	 * Mcpwp_SEO_Cache is a real DataForSEO charge ($0.012/request +
	 * $0.00012/result). Left unguarded, ~2,000 distinct seeds cost roughly $36.
	 * This budget is independent of the generic rate limiter, which throttles
	 * request counts, not spend, and is keyed to a read-scoped key even though
	 * this is the expensive call.
	 */
	const LOOKUP_BUDGET_MAX = 200;

	/**
	 * Rolling window for the lookup budget above.
	 */
	const LOOKUP_BUDGET_WINDOW = DAY_IN_SECONDS;

	/**
	 * Transient key for the lookup-budget counter.
	 */
	const LOOKUP_BUDGET_TRANSIENT = 'mcpwp_seo_lookup_budget';

	/**
	 * Reason slug returned when the lookup budget above is exhausted.
	 */
	const REASON_LOOKUP_BUDGET_EXHAUSTED = 'lookup_budget_exhausted';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/seo/visibility',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_visibility' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/seo/visibility/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh_visibility' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/seo/opportunities',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_opportunities' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Return the stored visibility snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_visibility( $request ) {
		$this->log_activity( 'get_seo_visibility', $request );

		return $this->success_response( Mcpwp_SEO_Visibility::get_latest() );
	}

	/**
	 * Fetch a fresh snapshot from DataForSEO.
	 *
	 * Explicit action only — nothing in this feature runs on a timer, so an
	 * agent loop cannot quietly spend the customer's credit overnight. On top
	 * of that, a short debounce blocks a second fetch within
	 * self::REFRESH_DEBOUNCE seconds — this is the actual expensive call in
	 * the feature, and the generic rate limiter alone is not a cost guard.
	 *
	 * Debouncing must never be confused with caching: a debounced call returns
	 * the existing snapshot tagged 'refresh_debounced' so the caller can tell
	 * "we did not refetch" from "we refetched and nothing changed" — it must
	 * never claim a fresh capture time for data that was not just fetched.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function refresh_visibility( $request ) {
		$this->log_activity( 'refresh_seo_visibility', $request );

		$lock_set_at = get_transient( self::REFRESH_LOCK );
		if ( false !== $lock_set_at ) {
			$latest                = Mcpwp_SEO_Visibility::get_latest();
			$latest['reason']      = 'refresh_debounced';
			$latest['retry_after'] = max( 0, ( (int) $lock_set_at + self::REFRESH_DEBOUNCE ) - time() );

			return $this->success_response( $latest );
		}

		$provider = $this->get_dataforseo_provider();
		if ( null === $provider ) {
			return $this->error_response(
				'not_configured',
				Mcpwp_Provider_Dataforseo::describe_reason( 'not_configured' ),
				400
			);
		}

		$target = wp_parse_url( home_url(), PHP_URL_HOST );
		$rows   = $provider->ranked_keywords( $target );

		if ( is_wp_error( $rows ) ) {
			// A transient provider outage must not block a legitimate retry for
			// the full debounce window — the lock is only set below, on success.
			return $this->error_response( $rows->get_error_code(), $rows->get_error_message(), 502 );
		}

		$stored = Mcpwp_SEO_Visibility::store_snapshot( $rows, $target );

		set_transient( self::REFRESH_LOCK, time(), self::REFRESH_DEBOUNCE );

		return $this->success_response( $stored );
	}

	/**
	 * Return the ranked opportunity list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_opportunities( $request ) {
		$this->log_activity( 'get_seo_opportunities', $request );

		$visibility = Mcpwp_SEO_Visibility::get_latest();

		$issues = class_exists( 'Mcpwp_SEO_Audit_Store' )
			? Mcpwp_SEO_Audit_Store::list_issues( array( 'status' => 'open' ) )
			: array();

		// A site that ranks for nothing has no visibility rows to score. Without
		// a seed there is genuinely nothing to rank yet, and the ranker says so
		// with reason 'no_rankings_needs_seed' (site was fetched, ranks for
		// nothing) or 'no_inputs' (never fetched) — never a misleading empty
		// list that reads the same for both cases.
		$metrics = array();
		$seed    = sanitize_text_field( (string) $request->get_param( 'seed' ) );
		$seed    = mb_substr( $seed, 0, self::MAX_SEED_LENGTH );

		if ( empty( $visibility['rows'] ) && '' !== $seed ) {
			$provider = $this->get_dataforseo_provider();

			// Credentials absent must never collapse into "no seed given" — the
			// caller supplied one; the gap is that nobody was ever asked.
			if ( null === $provider ) {
				return $this->error_response(
					'not_configured',
					Mcpwp_Provider_Dataforseo::describe_reason( 'not_configured' ),
					400
				);
			}

			// "seed|en|2840" matches Mcpwp_SEO_Cache's own documented key shape
			// and the provider's actual (currently fixed) defaults — locale is
			// never varied today, but the key must already carry it so a future
			// caller that does vary it cannot silently hit the wrong cache entry.
			$cache_key = 'suggestions|' . $seed . '|en|2840';

			// The budget only ever guards a real spend — a cache hit replays a
			// prior answer for free and must not be charged against it.
			if ( ! Mcpwp_SEO_Cache::has( $cache_key ) ) {
				$budget = $this->consume_lookup_budget();

				if ( is_wp_error( $budget ) ) {
					$budget_data = $budget->get_error_data();

					return $this->success_response(
						array(
							'mode'          => 'unknown',
							'opportunities' => array(),
							'reason'        => self::REASON_LOOKUP_BUDGET_EXHAUSTED,
							'retry_after'   => isset( $budget_data['retry_after'] ) ? (int) $budget_data['retry_after'] : self::LOOKUP_BUDGET_WINDOW,
						)
					);
				}
			}

			$fetched = Mcpwp_SEO_Cache::remember(
				$cache_key,
				function () use ( $provider, $seed ) {
					return $provider->keyword_suggestions( $seed );
				}
			);

			if ( is_wp_error( $fetched ) ) {
				return $this->error_response( $fetched->get_error_code(), $fetched->get_error_message(), 502 );
			}

			// A genuine zero-result answer for a real seed lookup is a different
			// claim than "no seed was given" and must carry its own reason.
			if ( empty( $fetched ) ) {
				return $this->success_response(
					array(
						'mode'          => 'greenfield',
						'opportunities' => array(),
						'reason'        => Mcpwp_SEO_Opportunities::REASON_SEED_NO_SUGGESTIONS,
					)
				);
			}

			$metrics = $fetched;
		}

		$result = Mcpwp_SEO_Opportunities::rank(
			$visibility['rows'],
			$metrics,
			$issues,
			isset( $visibility['reason'] ) ? $visibility['reason'] : null
		);

		return $this->success_response( $result );
	}

	/**
	 * Consume one slot from the daily paid-lookup budget.
	 *
	 * Must only be called on a Mcpwp_SEO_Cache miss — see get_opportunities().
	 * A fixed-window counter, same shape as Mcpwp_Rate_Limiter's windows: the
	 * window resets itself the first time it is read after expiring, rather
	 * than sliding on every write.
	 *
	 * @return true|WP_Error True if a slot was consumed; WP_Error (with
	 *                       'retry_after' seconds in its data) if exhausted.
	 */
	protected function consume_lookup_budget() {
		$now    = time();
		$window = get_transient( self::LOOKUP_BUDGET_TRANSIENT );

		if ( ! is_array( $window ) || ! isset( $window['count'], $window['reset'] ) || (int) $window['reset'] <= $now ) {
			$window = array(
				'count' => 0,
				'reset' => $now + self::LOOKUP_BUDGET_WINDOW,
			);
		}

		if ( (int) $window['count'] >= self::LOOKUP_BUDGET_MAX ) {
			return new WP_Error(
				'lookup_budget_exhausted',
				__( 'Daily budget for paid keyword lookups on this site has been reached.', 'mcpwp' ),
				array( 'retry_after' => max( 0, (int) $window['reset'] - $now ) )
			);
		}

		$window['count']++;
		set_transient( self::LOOKUP_BUDGET_TRANSIENT, $window, max( 1, (int) $window['reset'] - $now ) );

		return true;
	}
}
