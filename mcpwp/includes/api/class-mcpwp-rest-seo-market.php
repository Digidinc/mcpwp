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
		// a seed there is genuinely nothing to rank, and the ranker says so with
		// reason 'no_inputs' rather than returning a misleading empty list.
		$metrics = array();
		$seed    = (string) $request->get_param( 'seed' );

		if ( empty( $visibility['rows'] ) && '' !== $seed ) {
			$provider = $this->get_dataforseo_provider();

			if ( null !== $provider ) {
				$fetched = Mcpwp_SEO_Cache::remember(
					'suggestions|' . $seed,
					function () use ( $provider, $seed ) {
						return $provider->keyword_suggestions( $seed );
					}
				);

				if ( is_wp_error( $fetched ) ) {
					return $this->error_response( $fetched->get_error_code(), $fetched->get_error_message(), 502 );
				}

				$metrics = $fetched;
			}
		}

		$result = Mcpwp_SEO_Opportunities::rank( $visibility['rows'], $metrics, $issues );

		return $this->success_response( $result );
	}

	/**
	 * Build a configured provider, or null when credentials are absent.
	 *
	 * Protected (not private) so tests can inject a stub provider without
	 * calling the live API — see tests/unit/test-seo-market-debounce.php.
	 *
	 * @return Mcpwp_Provider_Dataforseo|null
	 */
	protected function get_dataforseo_provider() {
		if ( ! class_exists( 'Mcpwp_Integration_Manager' ) || ! class_exists( 'Mcpwp_Provider_Dataforseo' ) ) {
			return null;
		}

		$config = Mcpwp_Integration_Manager::get_instance()->get_provider_config( 'dataforseo' );

		if ( empty( $config['login'] ) || empty( $config['password'] ) ) {
			return null;
		}

		return new Mcpwp_Provider_Dataforseo( $config['login'], $config['password'] );
	}
}
