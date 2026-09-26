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
		$conflicts     = Mcpwp_AI_Crawler_Policy::last_known_conflicts();

		return array(
			'bots'                   => $described,
			'guidance'               => 'Denying a SEARCH bot removes this site from that AI product\'s live answers (it can no longer be cited). Denying a TRAINING bot only stops future model training on this content — it has no effect on current answers.',
			'physical_robots_file'   => $physical_file,
			'policy_applies'         => ! $physical_file,
			'warning'                => $physical_file
				? 'A physical robots.txt file exists at the site root. WordPress only serves its virtual robots.txt (which is where this policy is written) when no physical file is present, so this policy cannot take effect until that file is removed or updated by hand.'
				: '',
			'conflicts'              => $conflicts,
			'conflicts_note'         => ! empty( $conflicts )
				? 'These denied bots also have some other directive for the exact same User-agent elsewhere in robots.txt (last observed the previous time robots.txt was generated). MCPWP still appends its own "Disallow: /" group for them, but the conflict is surfaced here, not resolved: under RFC 9309, a crawler merges same-agent groups and an equal-length "Allow: /" beats "Disallow: /" — so the other directive may be the one that actually wins for a given crawler. Review and reconcile the two by hand.'
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
	 * POST /indexnow/submit — admin scope only (see the '/mcpwp/v1/indexnow'
	 * entry in trait-mcpwp-api-auth.php's $admin_write_routes table).
	 *
	 * #937 WARN-3 hardening over the automatic publish-hook path: every
	 * gate that governs automatic submission (off switch, blog_public,
	 * environment, Rank Math deferral) applies here too — there is no
	 * "explicit calls bypass the environment gate" carve-out — plus a
	 * per-call cap, a 1/minute throttle, and URL validation that requires
	 * each URL to resolve via url_to_postid() to a currently-indexable
	 * post and rejects any URL carrying a query string.
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

		$reason_messages = array(
			'disabled_by_setting'              => array( 'indexnow_disabled', __( 'IndexNow is disabled in MCPWP settings.', 'mcpwp' ) ),
			'site_discourages_search_engines'  => array( 'indexnow_blog_public_off', __( 'This site\'s "Discourage search engines from indexing this site" setting is on. IndexNow will not run while that is set, regardless of the IndexNow setting.', 'mcpwp' ) ),
			'non_production_environment'       => array( 'indexnow_non_production', __( 'This environment is not production and IndexNow is not forced on for non-production environments in MCPWP settings.', 'mcpwp' ) ),
			'rankmath_indexnow_active'         => array( 'indexnow_deferred_to_rankmath', __( 'Rank Math\'s own Instant Indexing module is configured to auto-submit on this site. MCPWP defers to it to avoid double-submitting the same URLs.', 'mcpwp' ) ),
		);

		$reason = $indexnow->blocked_reason();
		if ( isset( $reason_messages[ $reason ] ) ) {
			list( $code, $message ) = $reason_messages[ $reason ];
			return $this->error_response( $code, $message, 409 );
		}

		// Validate BEFORE consuming the throttle (#937 NOTE-6): a caller
		// whose whole batch fails validation (a typo'd URL, a query
		// string) has made no successful submission at all, and must not
		// be forced to wait out the 1/minute window just to retry with the
		// corrected URLs.
		$validated = $indexnow->validate_explicit_urls( $urls );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		if ( empty( $validated['valid'] ) ) {
			return $this->error_response(
				'indexnow_no_valid_urls',
				__( 'None of the submitted URLs are eligible: each must belong to this site, carry no query string, and resolve to a currently-published, indexable post.', 'mcpwp' ),
				400,
				array( 'rejected' => $validated['rejected'] )
			);
		}

		if ( ! $indexnow->check_and_consume_submit_throttle() ) {
			return $this->error_response(
				'indexnow_throttled',
				__( 'Explicit IndexNow submissions are limited to once per minute. Try again shortly.', 'mcpwp' ),
				429
			);
		}

		$result = $indexnow->submit( $validated['valid'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['rejected'] = $validated['rejected'];

		return $this->success_response( $result );
	}
}
