<?php
/**
 * AI crawler policy + IndexNow REST surface.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the AI crawler policy and IndexNow.
 */
class Mcpwp_REST_AI_Presence extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/ai-crawlers/policy',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_crawler_policy' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					// Mutating methods on this route require admin scope —
					// see the '/mcpwp/v1/ai-crawlers' entry in
					// trait-mcpwp-api-auth.php's $admin_write_routes table.
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_crawler_policy' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/indexnow/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_indexnow_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/indexnow/submit',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_indexnow' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * GET /ai-crawlers/policy
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_crawler_policy( $request ) {
		$this->log_activity( 'get_ai_crawler_policy', $request );

		return $this->success_response( $this->describe_policy() );
	}

	/**
	 * PUT /ai-crawlers/policy
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_crawler_policy( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}

		$bots = isset( $params['bots'] ) && is_array( $params['bots'] ) ? $params['bots'] : $params;
		if ( empty( $bots ) || ! is_array( $bots ) ) {
			return $this->error_response(
				'missing_bots',
				__( 'Provide "bots" as an object of bot-name => "allow"|"deny".', 'mcpwp' ),
				400
			);
		}

		$this->log_activity( 'update_ai_crawler_policy', $request );

		$result = Mcpwp_AI_Crawler_Policy::set_policy( $bots );

		return $this->success_response(
			array_merge( $this->describe_policy( $result['policy'] ), array(
				'applied'  => $result['applied'],
				'rejected' => $result['rejected'],
			) )
		);
	}

	/**
	 * Build the policy response body, including the search-vs-training
	 * explanation and the physical-robots.txt warning.
	 *
	 * @param array|null $policy Optional pre-fetched policy.
	 * @return array
	 */
	private function describe_policy( $policy = null ) {
		$policy = null !== $policy ? $policy : Mcpwp_AI_Crawler_Policy::get_policy();
		$bots   = Mcpwp_AI_Crawler_Policy::known_bots();

		$described = array();
		foreach ( $policy as $bot => $status ) {
			$meta          = isset( $bots[ $bot ] ) ? $bots[ $bot ] : array();
			$described[ $bot ] = array(
				'status' => $status,
				'type'   => isset( $meta['type'] ) ? $meta['type'] : 'unknown',
				'vendor' => isset( $meta['vendor'] ) ? $meta['vendor'] : '',
				'label'  => isset( $meta['label'] ) ? $meta['label'] : '',
			);
		}

		$physical_file = Mcpwp_AI_Crawler_Policy::physical_robots_file_exists();

		return array(
			'bots'                   => $described,
			'guidance'               => 'Denying a SEARCH bot removes this site from that AI product\'s live answers (it can no longer be cited). Denying a TRAINING bot only stops future model training on this content — it has no effect on current answers.',
			'physical_robots_file'   => $physical_file,
			'policy_applies'         => ! $physical_file,
			'warning'                => $physical_file
				? 'A physical robots.txt file exists at the site root. WordPress only serves its virtual robots.txt (which is where this policy is written) when no physical file is present, so this policy cannot take effect until that file is removed or updated by hand.'
				: '',
		);
	}

	/**
	 * GET /indexnow/status
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_indexnow_status( $request ) {
		$this->log_activity( 'get_indexnow_status', $request );

		$indexnow = new Mcpwp_IndexNow();
		return $this->success_response( $indexnow->get_status() );
	}

	/**
	 * POST /indexnow/submit
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_indexnow( $request ) {
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}

		$urls = isset( $params['urls'] ) && is_array( $params['urls'] ) ? $params['urls'] : array();
		if ( empty( $urls ) ) {
			return $this->error_response(
				'missing_urls',
				__( 'Provide "urls" as a non-empty array of this site\'s own URLs.', 'mcpwp' ),
				400
			);
		}

		$this->log_activity( 'indexnow_submit', $request );

		$indexnow = new Mcpwp_IndexNow();

		// An explicit submit call is allowed to run in a non-production
		// environment (that gate only governs the automatic publish-hook
		// path); the off-switch and the Rank Math deferral still apply.
		$reason = $indexnow->blocked_reason();
		if ( 'rankmath_indexnow_active' === $reason ) {
			return $this->error_response(
				'indexnow_deferred_to_rankmath',
				__( 'Rank Math\'s own IndexNow module is active on this site. MCPWP defers to it to avoid double-submitting the same URLs.', 'mcpwp' ),
				409
			);
		}
		if ( 'disabled_by_setting' === $reason ) {
			return $this->error_response(
				'indexnow_disabled',
				__( 'IndexNow is disabled in MCPWP settings.', 'mcpwp' ),
				409
			);
		}

		$result = $indexnow->submit( $urls );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}
}
