<?php
/**
 * Staging REST API Controller
 *
 * Exposes staging environment management endpoints under mcpwp/v1.
 *
 * GET  /staging             — list environments
 * POST /staging/push        — push between environments (→live gates via approval)
 * GET  /staging/status/{id} — poll async operation status
 *
 * @package MCPWP_Pro
 * @since   3.3.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for staging operations.
 */
class Mcpwp_REST_Staging extends Mcpwp_REST_API {

	/**
	 * Staging orchestrator.
	 *
	 * @var Mcpwp_Staging
	 */
	private $staging;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Staging $staging Staging orchestrator instance.
	 */
	public function __construct( Mcpwp_Staging $staging ) {
		$this->staging = $staging;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// List environments.
		register_rest_route(
			$this->namespace,
			'/staging',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_environments' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Push between environments.
		register_rest_route(
			$this->namespace,
			'/staging/push',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'push' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source'        => array(
							'description'       => __( 'Source environment ID.', 'mcpwp' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'destination'   => array(
							'description'       => __( 'Destination environment ID.', 'mcpwp' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'include_db'    => array(
							'description' => __( 'Include database in push.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => true,
						),
						'include_files' => array(
							'description' => __( 'Include files in push.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => true,
						),
						'reason'        => array(
							'description'       => __( 'Reason for this push (required when destination is live/production).', 'mcpwp' ),
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);

		// Poll async operation status.
		register_rest_route(
			$this->namespace,
			'/staging/status/(?P<id>[a-zA-Z0-9_\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Operation ID returned by the push endpoint.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * GET /staging — list available environments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_environments( $request ) {
		$this->log_activity( 'staging_list_environments', $request );

		$host  = $this->staging->detect_host();
		$envs  = $this->staging->list_environments();

		if ( is_wp_error( $envs ) ) {
			return $envs;
		}

		return $this->success_response(
			array(
				'host'         => $host,
				'environments' => $envs,
				'count'        => count( $envs ),
			)
		);
	}

	/**
	 * POST /staging/push — copy one environment to another.
	 *
	 * When the destination is a production environment this method does NOT push
	 * immediately; instead it creates a pending approval request and returns the
	 * approval ID so the operator can review before applying.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function push( $request ) {
		$this->log_activity( 'staging_push', $request );

		$source      = (string) $request->get_param( 'source' );
		$destination = (string) $request->get_param( 'destination' );
		$include_db  = (bool) $request->get_param( 'include_db' );
		$include_files = (bool) $request->get_param( 'include_files' );
		$reason      = (string) $request->get_param( 'reason' );

		// Fetch environment list and validate source/destination against it.
		$environments = $this->staging->list_environments();
		if ( is_wp_error( $environments ) ) {
			return $environments;
		}

		$validation = $this->staging->validate_env_ids( $environments, $source, $destination );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$is_dest_production = $this->staging->is_production_env( $environments, $destination );

		// Push-to-live path: create approval request, do NOT push yet.
		if ( $is_dest_production ) {
			if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
				return new WP_Error(
					'approvals_unavailable',
					'Approvals class is not available. Cannot gate push-to-live.',
					array( 'status' => 500 )
				);
			}

			$before_snapshot = $this->staging->snapshot_live_state();
			$description     = sprintf(
				'Push staging to live: %s',
				$reason ?: 'No reason provided.'
			);

			$approval = $this->create_staging_push_approval(
				$source,
				$destination,
				$include_db,
				$include_files,
				$reason,
				$before_snapshot,
				$description
			);

			if ( is_wp_error( $approval ) ) {
				return $approval;
			}

			return $this->success_response(
				array(
					'status'      => 'pending_approval',
					'approval_id' => $approval['id'],
					'message'     => sprintf(
						'Push to live requires approval. Use wp_approve_request with id "%s" to proceed, then wp_apply_approval to execute the push.',
						$approval['id']
					),
					'before'      => $before_snapshot,
				),
				202
			);
		}

		// Push-to-staging path: execute immediately.
		$options = array(
			'include_db'    => $include_db,
			'include_files' => $include_files,
		);

		$result = $this->staging->push( $source, $destination, $options );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			array_merge(
				array(
					'source'      => $source,
					'destination' => $destination,
				),
				$result
			)
		);
	}

	/**
	 * GET /staging/status/{id} — poll an async operation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_status( $request ) {
		$this->log_activity( 'staging_get_status', $request );

		$operation_id = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$result       = $this->staging->get_operation_status( $operation_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response(
			array_merge(
				array( 'operation_id' => $operation_id ),
				$result
			)
		);
	}

	/**
	 * Create an approval request for a staging→live push.
	 *
	 * Stores a custom 'staging_push' action record via the Approvals store.
	 * The apply_change() method in Mcpwp_Approvals will need to handle
	 * 'staging_push' action type (wired separately in class-mcpwp-approvals.php).
	 *
	 * @param string $source          Source env ID.
	 * @param string $destination     Destination env ID.
	 * @param bool   $include_db      Include database.
	 * @param bool   $include_files   Include files.
	 * @param string $reason          Operator-supplied reason.
	 * @param array  $before_snapshot Live state snapshot.
	 * @param string $description     Human-readable description.
	 * @return array|WP_Error Approval record or error.
	 */
	private function create_staging_push_approval(
		string $source,
		string $destination,
		bool $include_db,
		bool $include_files,
		string $reason,
		array $before_snapshot,
		string $description
	) {
		// Build a minimal approval record compatible with Mcpwp_Approvals storage.
		$id  = $this->generate_approval_id();
		$now = gmdate( 'c' );

		// An unstable-identity OAuth token (legacy/bridge, no user_id) must not
		// open a push-to-live request: it could later be approved by its own
		// refreshed token under a different id, defeating separation of duties.
		$identity_guard = $this->guard_stable_identity_for_approvals();
		if ( is_wp_error( $identity_guard ) ) {
			return $identity_guard;
		}

		// Capture the requesting key's identity so the approve/apply path can
		// enforce separation of duties (same key cannot both request and approve).
		$key_record   = $this->get_current_api_key_record();
		$requester_id = ( $key_record && ! empty( $key_record['id'] ) ) ? (string) $key_record['id'] : '';

		$record = array(
			'id'                      => $id,
			'status'                  => 'pending',
			'action'                  => 'staging_push',
			'title'                   => 'Push staging to live',
			'note'                    => sanitize_textarea_field( $reason ),
			'tool'                    => 'wp_push_to_live',
			'requested_by_key_id'     => $requester_id,
			'resource'                => array(
				'type'   => 'staging',
				'source' => $source,
				'dest'   => $destination,
			),
			'diff'                    => array(
				'type'    => 'staging_push',
				'changed' => true,
				'note'    => $description,
			),
			'payload'                 => array(
				'source'        => $source,
				'destination'   => $destination,
				'include_db'    => $include_db,
				'include_files' => $include_files,
				'reason'        => sanitize_text_field( $reason ),
			),
			'rollback'                => array(
				'note' => 'Rollback requires a separate staging→live push reverting to a known-good backup.',
			),
			'metadata'                => array(
				'before' => $before_snapshot,
			),
			// #1025: computed ONCE, now — see
			// Mcpwp_Approvals::categories_for_approval()'s docblock. This
			// creator builds its own record shape directly (it never calls a
			// Mcpwp_Approvals::create_*_request() method), so it must set
			// this itself rather than inheriting it from a shared creator.
			'visibility_categories'   => class_exists( 'Mcpwp_Approvals' )
				? Mcpwp_Approvals::compute_visibility_categories( 'staging_push', array( 'type' => 'staging' ), array() )
				: array( 'staging' ),
			'created_at'              => $now,
			'updated_at'              => $now,
			'approved_at'             => null,
			'applied_at'              => null,
			'rejected_at'             => null,
			'rolled_back_at'          => null,
		);

		// Persist through the approvals store's own create path (#1042):
		// create lock, open-request cap, verified INSERT.
		$stored = Mcpwp_Approvals::insert_request( $record );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		// Emit an event so the Control Room panel shows it.
		if ( class_exists( 'Mcpwp_Event_Store' ) ) {
			Mcpwp_Event_Store::emit(
				'approval.created',
				array( 'approval' => $record ),
				array(
					'resource'           => $record['resource'],
					'risk_level'         => 'high',
					'approval_state'     => 'pending',
					'recommended_action' => 'Review and approve the staging→live push in MCPWP > Control Room.',
				)
			);
		}

		// Return a public-safe copy (payload is needed for apply, keep it for now
		// but callers only receive id + status).
		return $record;
	}

	/**
	 * Generate a unique approval ID with the staging prefix.
	 *
	 * @return string
	 */
	private function generate_approval_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'apr_stg_' . str_replace( '-', '', wp_generate_uuid4() );
		}
		return 'apr_stg_' . bin2hex( random_bytes( 12 ) );
	}
}
