<?php
/**
 * REST controller for approval requests.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Approval request REST endpoints.
 */
class Mcpwp_REST_Approvals extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/approvals',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_approvals' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status' => array(
							'description' => __( 'Optional approval status filter.', 'mcpwp' ),
							'type'        => 'string',
						),
						'limit'  => array(
							'description'       => __( 'Maximum approvals to return.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/approvals/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_approval' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		foreach ( array( 'approve', 'reject', 'apply', 'rollback' ) as $action ) {
			register_rest_route(
				$this->namespace,
				'/approvals/(?P<id>[A-Za-z0-9_-]+)/' . $action,
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, $action . '_approval' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => array(
							'note' => array(
								'description' => __( 'Optional human review note.', 'mcpwp' ),
								'type'        => 'string',
							),
						),
					),
				)
			);
		}
	}

	/**
	 * List approvals.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Response.
	 */
	public function list_approvals( $request ) {
		$this->log_activity( 'list_approvals', $request );

		return $this->success_response(
			array(
				'approvals' => Mcpwp_Approvals::list_requests(
					(string) $request->get_param( 'status' ),
					(int) $request->get_param( 'limit' )
				),
			)
		);
	}

	/**
	 * Get approval.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_approval( $request ) {
		$result = Mcpwp_Approvals::get_request( $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'get_approval', $request, array( 'approval_id' => $result['id'] ) );
		return $this->success_response( $result );
	}

	/**
	 * Approve request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function approve_approval( $request ) {
		$self_approval_check = $this->check_self_approval( $request->get_param( 'id' ) );
		if ( is_wp_error( $self_approval_check ) ) {
			return $self_approval_check;
		}

		return $this->approval_transition_response( $request, 'approve_request', 'approve_approval' );
	}

	/**
	 * Reject request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function reject_approval( $request ) {
		return $this->approval_transition_response( $request, 'reject_request', 'reject_approval' );
	}

	/**
	 * Apply request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function apply_approval( $request ) {
		$self_approval_check = $this->check_self_approval( $request->get_param( 'id' ) );
		if ( is_wp_error( $self_approval_check ) ) {
			return $self_approval_check;
		}

		$result = Mcpwp_Approvals::apply_request( $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'apply_approval', $request, array( 'approval_id' => $result['id'] ) );
		return $this->success_response( $result );
	}

	/**
	 * Roll back request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function rollback_approval( $request ) {
		$result = Mcpwp_Approvals::rollback_request( $request->get_param( 'id' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'rollback_approval', $request, array( 'approval_id' => $result['id'] ) );
		return $this->success_response( $result );
	}

	/**
	 * Block self-approval: the same API key that created an approval request
	 * cannot approve or apply it.  Enforced whenever the record carries a
	 * 'requested_by_key_id' field — now set by the staging-push path AND by every
	 * approval created through an API key (post_content_update included).
	 * A genuine admin-AJAX context has no key record and is intentionally exempt.
	 *
	 * @param string $approval_id Approval request ID.
	 * @return true|WP_Error True when allowed; WP_Error when same key tries to self-approve.
	 */
	private function check_self_approval( $approval_id ) {
		$key_record = $this->get_current_api_key_record();

		// No key record means we are in an admin-AJAX trusted context — no restriction.
		if ( ! $key_record || empty( $key_record['id'] ) ) {
			return true;
		}

		$current_key_id = (string) $key_record['id'];

		// Fetch the raw stored record (not public_record — we need the internal field).
		$all_items = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
		if ( ! is_array( $all_items ) ) {
			return true;
		}

		$approval_id = sanitize_key( (string) $approval_id );
		if ( empty( $all_items[ $approval_id ] ) ) {
			return true; // Not found — let apply_request / approve_request return the 404.
		}

		$record = $all_items[ $approval_id ];

		// Only enforce when the record carries a requester identity.
		if ( empty( $record['requested_by_key_id'] ) ) {
			return true;
		}

		if ( $current_key_id === (string) $record['requested_by_key_id'] ) {
			return new WP_Error(
				'self_approval_forbidden',
				__( 'The same API key that created this request cannot approve or apply it. Use a different admin key or approve via WordPress admin.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Run approve/reject transition.
	 *
	 * @param WP_REST_Request $request  Request.
	 * @param string          $method   Mcpwp_Approvals method.
	 * @param string          $activity Activity name.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	private function approval_transition_response( $request, $method, $activity ) {
		$result = call_user_func(
			array( 'Mcpwp_Approvals', $method ),
			$request->get_param( 'id' ),
			(string) $request->get_param( 'note' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( $activity, $request, array( 'approval_id' => $result['id'] ) );
		return $this->success_response( $result );
	}
}
