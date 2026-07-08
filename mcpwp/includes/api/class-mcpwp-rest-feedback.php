<?php
/**
 * Feedback REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for AI feedback submission and listing.
 */
class Mcpwp_REST_Feedback extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/feedback',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_feedback' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type'        => array(
							'description' => __( 'Feedback type.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
							'enum'        => array( 'bug_report', 'feature_request', 'feedback' ),
						),
						'title'       => array(
							'description' => __( 'Short summary.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'description' => array(
							'description' => __( 'Detailed description.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'agent'       => array(
							'description' => __( 'AI model or agent name.', 'mumcp' ),
							'type'        => 'string',
							'default'     => '',
						),
						'priority'    => array(
							'description' => __( 'Priority level.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( 'low', 'medium', 'high', 'critical' ),
							'default'     => 'medium',
						),
						'meta'        => array(
							'description' => __( 'Extra context as JSON object.', 'mumcp' ),
							'type'        => 'object',
							'default'     => array(),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_feedback' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type'   => array(
							'description' => __( 'Filter by feedback type.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( 'bug_report', 'feature_request', 'feedback' ),
						),
						'status' => array(
							'description' => __( 'Filter by status.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( 'open', 'acknowledged', 'resolved', 'closed', 'all' ),
							'default'     => 'open',
						),
						'limit'  => array(
							'description' => __( 'Maximum results.', 'mumcp' ),
							'type'        => 'integer',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						),
					),
				),
			)
		);

		// Public relay endpoint — receives feedback from any MCPWP install.
		// No API key required; rate-limited by IP.
		register_rest_route(
			$this->namespace,
			'/feedback/relay',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'relay_feedback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'type'           => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'bug_report', 'feature_request', 'feedback' ),
					),
					'title'          => array(
						'type'     => 'string',
						'required' => true,
					),
					'description'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'agent'          => array(
						'type'    => 'string',
						'default' => '',
					),
					'priority'       => array(
						'type'    => 'string',
						'enum'    => array( 'low', 'medium', 'high', 'critical' ),
						'default' => 'medium',
					),
					'meta'           => array(
						'type'    => 'object',
						'default' => array(),
					),
					'site_url'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'site_name'      => array(
						'type'    => 'string',
						'default' => '',
					),
					'plugin_version' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Submit feedback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function submit_feedback( $request ) {
		$result = Mcpwp_Feedback::submit(
			array(
				'type'        => $request->get_param( 'type' ),
				'title'       => $request->get_param( 'title' ),
				'description' => $request->get_param( 'description' ),
				'agent'       => $request->get_param( 'agent' ),
				'priority'    => $request->get_param( 'priority' ),
				'meta'        => $request->get_param( 'meta' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'submit_feedback', $request, $result );

		return $this->success_response( $result, 201 );
	}

	/**
	 * Relay feedback from remote MCPWP installs.
	 *
	 * Public endpoint — no API key required.
	 * Creates a GitHub issue using this server's github_token.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function relay_feedback( $request ) {
		// Basic rate limiting by IP — max 10 relay requests per minute.
		$ip        = $this->get_relay_client_ip();
		$transient = 'mcpwp_relay_' . md5( $ip );
		$count     = (int) get_transient( $transient );
		if ( $count >= 10 ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many feedback submissions. Please try again later.', 'mumcp' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $transient, $count + 1, 60 );

		$data = array(
			'type'           => $request->get_param( 'type' ),
			'title'          => $request->get_param( 'title' ),
			'description'    => $request->get_param( 'description' ),
			'agent'          => $request->get_param( 'agent' ),
			'priority'       => $request->get_param( 'priority' ),
			'meta'           => $request->get_param( 'meta' ),
			'site_url'       => $request->get_param( 'site_url' ),
			'site_name'      => $request->get_param( 'site_name' ),
			'plugin_version' => $request->get_param( 'plugin_version' ),
		);

		$result = Mcpwp_Feedback::create_github_issue_from_relay( $data );

		if ( ! $result['success'] ) {
			return new WP_Error(
				'relay_failed',
				$result['message'],
				array( 'status' => 502 )
			);
		}

		return new WP_REST_Response(
			array(
				'success'          => true,
				'github_issue_url' => $result['github_issue_url'],
				'message'          => $result['message'],
			),
			201
		);
	}

	/**
	 * Get client IP address for relay rate limiting.
	 *
	 * @return string
	 */
	private function get_relay_client_ip() {
		$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( false !== strpos( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				return $ip;
			}
		}
		return '0.0.0.0';
	}

	/**
	 * List feedback entries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_feedback( $request ) {
		$entries = Mcpwp_Feedback::list_entries(
			array(
				'type'   => $request->get_param( 'type' ),
				'status' => $request->get_param( 'status' ),
				'limit'  => $request->get_param( 'limit' ),
			)
		);

		$this->log_activity( 'list_feedback', $request );

		return $this->success_response(
			array(
				'feedback' => $entries,
				'total'    => count( $entries ),
			)
		);
	}
}
