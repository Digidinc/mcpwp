<?php
/**
 * Approval, diff, apply, and rollback store.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central approval request store for agent mutations.
 */
class Mcpwp_Approvals {

	/**
	 * Option name for stored approval requests.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'mcpwp_approval_requests';

	/**
	 * Maximum stored requests.
	 *
	 * @var int
	 */
	const MAX_ITEMS = 200;

	/**
	 * Create a post-content approval request.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $after_content Proposed content.
	 * @param array  $args         Request metadata.
	 * @return array|WP_Error Approval request.
	 */
	public static function create_post_content_request( $post_id, $after_content, $args = array() ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$before_content = (string) $post->post_content;
		$after_content  = (string) $after_content;
		$id             = self::generate_id();
		$now            = gmdate( 'c' );

		$request = array(
			'id'          => $id,
			'status'      => 'pending',
			'action'      => 'post_content_update',
			'title'       => isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : sprintf( 'Update content for #%d', (int) $post_id ),
			'note'        => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '',
			'tool'        => isset( $args['tool'] ) ? sanitize_key( (string) $args['tool'] ) : '',
			'resource'    => array(
				'type'   => 'post',
				'id'     => (int) $post_id,
				'title'  => get_the_title( $post ),
				'status' => $post->post_status,
				'url'    => get_permalink( $post ),
			),
			'diff'        => self::build_content_diff( $before_content, $after_content ),
			'payload'     => array(
				'after_content' => $after_content,
			),
			'rollback'    => array(
				'before_content' => $before_content,
				'before_hash'    => hash( 'sha256', $before_content ),
			),
			'metadata'    => isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array(),
			// Requester identity for separation-of-duties: the same API key that
			// creates an approval cannot later approve/apply it.
			// Mirrors the field the staging-push path already stores.
			'requested_by_key_id' => isset( $args['requested_by_key_id'] ) ? sanitize_text_field( (string) $args['requested_by_key_id'] ) : '',
			'created_at'  => $now,
			'updated_at'  => $now,
			'approved_at' => null,
			'applied_at'  => null,
			'rejected_at' => null,
			'rolled_back_at' => null,
		);

		self::upsert( $request );

		$public = self::public_record( $request );
		self::emit_event(
			'approval.created',
			$public,
			'pending',
			'medium',
			__( 'Review approval request.', 'mumcp' )
		);

		return $public;
	}

	/**
	 * List approval requests.
	 *
	 * @param string $status Status filter.
	 * @param int    $limit  Maximum records.
	 * @return array Requests.
	 */
	public static function list_requests( $status = '', $limit = 50 ) {
		$items  = array_values( self::get_all() );
		$status = sanitize_key( (string) $status );
		$limit  = min( 100, max( 1, (int) $limit ) );

		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( isset( $b['created_at'] ) ? $b['created_at'] : '', isset( $a['created_at'] ) ? $a['created_at'] : '' );
			}
		);

		if ( '' !== $status ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $status ) {
						return isset( $item['status'] ) && $status === $item['status'];
					}
				)
			);
		}

		return array_map( array( __CLASS__, 'public_record' ), array_slice( $items, 0, $limit ) );
	}

	/**
	 * Get one request.
	 *
	 * @param string $id Request ID.
	 * @return array|WP_Error Request.
	 */
	public static function get_request( $id ) {
		$items = self::get_all();
		$id    = sanitize_key( (string) $id );

		if ( empty( $items[ $id ] ) ) {
			return new WP_Error( 'approval_not_found', __( 'Approval request not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		return self::public_record( $items[ $id ] );
	}

	/**
	 * Approve a request.
	 *
	 * @param string $id   Request ID.
	 * @param string $note Approval note.
	 * @return array|WP_Error Request.
	 */
	public static function approve_request( $id, $note = '' ) {
		return self::transition_request( $id, 'approved', 'approved_at', $note );
	}

	/**
	 * Reject a request.
	 *
	 * @param string $id   Request ID.
	 * @param string $note Rejection note.
	 * @return array|WP_Error Request.
	 */
	public static function reject_request( $id, $note = '' ) {
		return self::transition_request( $id, 'rejected', 'rejected_at', $note );
	}

	/**
	 * Apply an approved request.
	 *
	 * @param string $id Request ID.
	 * @return array|WP_Error Request.
	 */
	public static function apply_request( $id ) {
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		if ( 'approved' !== $request['status'] ) {
			return new WP_Error( 'approval_not_ready', __( 'Approval request must be approved before it can be applied.', 'mumcp' ), array( 'status' => 409 ) );
		}

		$result = self::apply_payload( $request, 'payload' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$request['status']     = 'applied';
		$request['applied_at'] = gmdate( 'c' );
		$request['updated_at'] = $request['applied_at'];
		self::upsert( $request );

		$public = self::public_record( $request );
		self::emit_event(
			'approval.applied',
			$public,
			'applied',
			'medium',
			__( 'Monitor applied change and keep rollback available.', 'mumcp' )
		);

		return $public;
	}

	/**
	 * Roll back an applied request.
	 *
	 * @param string $id Request ID.
	 * @return array|WP_Error Request.
	 */
	public static function rollback_request( $id ) {
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		if ( 'applied' !== $request['status'] ) {
			return new WP_Error( 'approval_not_applied', __( 'Only applied approval requests can be rolled back.', 'mumcp' ), array( 'status' => 409 ) );
		}

		$result = self::apply_payload( $request, 'rollback' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$request['status']         = 'rolled_back';
		$request['rolled_back_at'] = gmdate( 'c' );
		$request['updated_at']     = $request['rolled_back_at'];
		self::upsert( $request );

		$public = self::public_record( $request );
		self::emit_event(
			'approval.rolled_back',
			$public,
			'rolled_back',
			'low',
			__( 'Review rollback result.', 'mumcp' )
		);

		return $public;
	}

	/**
	 * Build content diff metadata.
	 *
	 * @param string $before Before content.
	 * @param string $after  After content.
	 * @return array Diff data.
	 */
	private static function build_content_diff( $before, $after ) {
		return array(
			'type'           => 'content',
			'changed'        => $before !== $after,
			'before_hash'    => hash( 'sha256', $before ),
			'after_hash'     => hash( 'sha256', $after ),
			'before_length'  => strlen( $before ),
			'after_length'   => strlen( $after ),
			'before_excerpt' => wp_trim_words( wp_strip_all_tags( $before ), 40 ),
			'after_excerpt'  => wp_trim_words( wp_strip_all_tags( $after ), 40 ),
		);
	}

	/**
	 * Transition request status.
	 *
	 * @param string $id         Request ID.
	 * @param string $status     New status.
	 * @param string $time_field Timestamp field.
	 * @param string $note       Human note.
	 * @return array|WP_Error Request.
	 */
	private static function transition_request( $id, $status, $time_field, $note ) {
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		if ( ! in_array( $request['status'], array( 'pending', 'approved' ), true ) ) {
			return new WP_Error( 'approval_closed', __( 'Approval request is already closed.', 'mumcp' ), array( 'status' => 409 ) );
		}

		$now                     = gmdate( 'c' );
		$request['status']       = $status;
		$request[ $time_field ]  = $now;
		$request['updated_at']   = $now;
		$request['review_note']  = sanitize_textarea_field( (string) $note );

		self::upsert( $request );

		$public = self::public_record( $request );
		self::emit_event(
			'approval.' . $status,
			$public,
			$status,
			'approved' === $status ? 'medium' : 'low',
			'approved' === $status ? __( 'Apply approved change when ready.', 'mumcp' ) : __( 'No apply action needed.', 'mumcp' )
		);

		return $public;
	}

	/**
	 * Emit an approval lifecycle event.
	 *
	 * @param string $type               Event type.
	 * @param array  $approval           Public approval record.
	 * @param string $approval_state     Approval state.
	 * @param string $risk_level         Risk level.
	 * @param string $recommended_action Recommended action.
	 * @return void
	 */
	private static function emit_event( $type, $approval, $approval_state, $risk_level, $recommended_action ) {
		if ( ! class_exists( 'Mcpwp_Event_Store' ) ) {
			return;
		}

		$resource = isset( $approval['resource'] ) && is_array( $approval['resource'] ) ? $approval['resource'] : array();
		Mcpwp_Event_Store::emit(
			$type,
			array(
				'approval' => $approval,
			),
			array(
				'resource'           => $resource,
				'risk_level'         => $risk_level,
				'approval_state'     => $approval_state,
				'recommended_action' => $recommended_action,
			)
		);
	}

	/**
	 * Apply payload or rollback data.
	 *
	 * @param array  $request Request record.
	 * @param string $field   Data field.
	 * @return true|WP_Error True on success.
	 */
	private static function apply_payload( $request, $field ) {
		// Staging push apply path.
		if ( 'staging_push' === $request['action'] ) {
			return self::apply_staging_push( $request, $field );
		}

		if ( 'post_content_update' !== $request['action'] || empty( $request['resource']['id'] ) ) {
			return new WP_Error( 'unsupported_approval_action', __( 'Approval action is not supported yet.', 'mumcp' ), array( 'status' => 400 ) );
		}

		$post_id = (int) $request['resource']['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		$current_hash  = hash( 'sha256', (string) $post->post_content );
		$expected_hash = 'rollback' === $field
			? ( isset( $request['diff']['after_hash'] ) ? (string) $request['diff']['after_hash'] : '' )
			: ( isset( $request['rollback']['before_hash'] ) ? (string) $request['rollback']['before_hash'] : '' );

		if ( '' !== $expected_hash && $current_hash !== $expected_hash ) {
			return new WP_Error( 'approval_content_conflict', __( 'Post content changed after this approval request was created.', 'mumcp' ), array( 'status' => 409 ) );
		}

		$content = 'rollback' === $field
			? ( isset( $request['rollback']['before_content'] ) ? (string) $request['rollback']['before_content'] : '' )
			: ( isset( $request['payload']['after_content'] ) ? (string) $request['payload']['after_content'] : '' );

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * Get private request.
	 *
	 * @param string $id Request ID.
	 * @return array|WP_Error Request.
	 */
	private static function get_private_request( $id ) {
		$items = self::get_all();
		$id    = sanitize_key( (string) $id );

		if ( empty( $items[ $id ] ) ) {
			return new WP_Error( 'approval_not_found', __( 'Approval request not found.', 'mumcp' ), array( 'status' => 404 ) );
		}

		return $items[ $id ];
	}

	/**
	 * Remove private payload content before returning a record.
	 *
	 * @param array $request Request.
	 * @return array Public request.
	 */
	private static function public_record( $request ) {
		unset( $request['payload']['after_content'], $request['rollback']['before_content'] );
		return $request;
	}

	/**
	 * Upsert request.
	 *
	 * @param array $request Request.
	 * @return void
	 */
	private static function upsert( $request ) {
		$items = self::get_all();
		$items[ $request['id'] ] = $request;

		uasort(
			$items,
			function ( $a, $b ) {
				return strcmp( isset( $b['created_at'] ) ? $b['created_at'] : '', isset( $a['created_at'] ) ? $a['created_at'] : '' );
			}
		);

		$items = array_slice( $items, 0, self::MAX_ITEMS, true );
		update_option( self::OPTION_NAME, $items, false );
	}

	/**
	 * Get all requests.
	 *
	 * @return array Requests.
	 */
	private static function get_all() {
		$items = get_option( self::OPTION_NAME, array() );
		return is_array( $items ) ? $items : array();
	}

	/**
	 * Generate request ID.
	 *
	 * @return string ID.
	 */
	private static function generate_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'apr_' . str_replace( '-', '', wp_generate_uuid4() );
		}

		return 'apr_' . bin2hex( random_bytes( 12 ) );
	}

	/**
	 * Execute a staging push after it has been approved.
	 *
	 * Delegates to Mcpwp_Staging::push() using the payload stored in the
	 * approval record.  Only called from apply_payload() when action === 'staging_push'.
	 *
	 * @param array  $request Approval record.
	 * @param string $field   'payload' for apply, 'rollback' for rollback.
	 * @return true|WP_Error
	 */
	private static function apply_staging_push( $request, $field ) {
		// Rollback of a staging push is not automatically reversible — the push
		// has already overwritten the live environment.  We can inform the user
		// but cannot undo it programmatically without a backup.
		if ( 'rollback' === $field ) {
			return new WP_Error(
				'staging_push_rollback_unavailable',
				__(
					'Staging push rollback is not available automatically. Restore from a host-level backup or push a known-good staging build to live again.',
					'mumcp'
				),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'Mcpwp_Staging' ) ) {
			return new WP_Error(
				'staging_class_unavailable',
				__( 'Staging module is not available. Ensure the Pro module is active.', 'mumcp' ),
				array( 'status' => 500 )
			);
		}

		$payload = isset( $request['payload'] ) && is_array( $request['payload'] ) ? $request['payload'] : array();

		$source      = isset( $payload['source'] ) ? (string) $payload['source'] : '';
		$destination = isset( $payload['destination'] ) ? (string) $payload['destination'] : '';
		$include_db    = isset( $payload['include_db'] ) ? (bool) $payload['include_db'] : true;
		$include_files = isset( $payload['include_files'] ) ? (bool) $payload['include_files'] : true;

		if ( '' === $source || '' === $destination ) {
			return new WP_Error(
				'staging_push_missing_params',
				__( 'Staging push approval is missing source or destination environment ID.', 'mumcp' ),
				array( 'status' => 400 )
			);
		}

		$staging = new Mcpwp_Staging();

		// Re-validate env IDs against the live environment list before executing.
		$environments = $staging->list_environments();
		if ( is_wp_error( $environments ) ) {
			return $environments;
		}

		$validation = $staging->validate_env_ids( $environments, $source, $destination );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Re-assert destination is not production using fresh env list.
		// Defence-in-depth: guards against env reclassification between approval
		// creation and apply time.  Primary protection is the approval gate itself.
		$dest_env = null;
		foreach ( $environments as $env ) {
			if ( isset( $env['id'] ) && $env['id'] === $destination ) {
				$dest_env = $env;
				break;
			}
		}
		if ( null === $dest_env ) {
			return new WP_Error( 'env_not_found', 'Destination environment no longer exists.', array( 'status' => 400 ) );
		}
		if ( isset( $dest_env['type'] ) && 'production' === $dest_env['type'] ) {
			return new WP_Error(
				'direct_production_push_blocked',
				'Cannot apply a direct production push at apply time. The destination environment is classified as production by the host API.',
				array( 'status' => 403 )
			);
		}

		$result = $staging->push(
			$source,
			$destination,
			array(
				'include_db'    => $include_db,
				'include_files' => $include_files,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['success'] ) && ! $result['success'] ) {
			return new WP_Error(
				'staging_push_failed',
				isset( $result['message'] ) ? $result['message'] : __( 'Staging push failed.', 'mumcp' ),
				array( 'status' => 502 )
			);
		}

		return true;
	}
}
