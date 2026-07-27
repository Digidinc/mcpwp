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
	 * Cache key for the visibility fetch.
	 */
	const REFRESH_LOCK = 'mcpwp_seo_visibility_refresh';

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
	 * agent loop cannot quietly spend the customer's credit overnight.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function refresh_visibility( $request ) {
		$this->log_activity( 'refresh_seo_visibility', $request );

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
			return $this->error_response( $rows->get_error_code(), $rows->get_error_message(), 502 );
		}

		$stored = Mcpwp_SEO_Visibility::store_snapshot( $rows, $target );

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
	 * @return Mcpwp_Provider_Dataforseo|null
	 */
	private function get_dataforseo_provider() {
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
