<?php
/**
 * Approval, diff, apply, and rollback store.
 *
 * @package MCPWP
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
	 * Allowed values for triage.priority — the single enum every writer and
	 * every reader of a `triage` record must agree on. Anything else (an
	 * injected string, a typo, a future Jev model naming levels differently)
	 * is rejected, never stored, never rendered.
	 *
	 * @var string[]
	 */
	const TRIAGE_PRIORITIES = array( 'low', 'medium', 'high', 'urgent' );

	/**
	 * Allowed values for triage.change_kind. Same fail-closed contract as
	 * TRIAGE_PRIORITIES.
	 *
	 * @var string[]
	 */
	const TRIAGE_CHANGE_KINDS = array( 'content_edit', 'layout_change', 'seo_meta', 'deletion', 'bulk', 'settings', 'other' );

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
			return new WP_Error( 'not_found', __( 'Post not found.', 'mcpwp' ), array( 'status' => 404 ) );
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
			__( 'Review approval request.', 'mcpwp' )
		);
		self::maybe_schedule_triage( $public );

		return $public;
	}

	/**
	 * List approval requests.
	 *
	 * Fail-closed chokepoint: EVERY read of the approval list flows through here,
	 * so the source-leak redaction lives here rather than on each REST consumer
	 * (prior passes gated one consumer and left siblings — /site-state and the
	 * control room both read this list). Unless the caller proves it may see
	 * non-public content ($reveal_non_public === true), every returned record
	 * whose source post is draft/pending/private is run through
	 * redact_source_for_unprivileged() so a read-only key can never harvest a
	 * hidden title, URL, status, or the 40-word body excerpt a privileged key
	 * queued for review. The reveal flag is threaded from the REST handler via
	 * requester_can_see_non_public(); the admin Control Room passes true because
	 * it renders under activate_plugins, not a read API key.
	 *
	 * @param string $status            Status filter.
	 * @param int    $limit             Maximum records.
	 * @param bool   $reveal_non_public When true, return full records; when false
	 *                                  (default, SAFE) redact non-public sources.
	 * @return array Requests.
	 */
	/**
	 * Store a destructive operation for review (#536).
	 *
	 * @param string $operation Short name (delete_page, revoke_api_key…).
	 * @param array  $payload   {route, method, params} to re-dispatch on apply.
	 * @param array  $resource  {type, id, title}.
	 * @param array  $args      title, note, requested_by_key_id.
	 * @return array|WP_Error Public record.
	 */
	public static function create_destructive_request( $operation, array $payload, array $resource, $args = array() ) {
		$requester = isset( $args['requested_by_key_id'] ) ? sanitize_text_field( (string) $args['requested_by_key_id'] ) : '';

		// A destructive record names its requester or it is not a record:
		// the two-person rule cannot bind an anonymous request.
		if ( '' === $requester ) {
			return new WP_Error(
				'unidentified_requester',
				__( 'A destructive operation must be requested by an identified principal — an API key, or an administrator in wp-admin.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		// A flood of gated calls must not fill the queue other people review
		// — from one principal, or from many minted for the purpose.
		$pending_mine = 0;
		$pending_api  = 0;
		foreach ( self::get_all() as $existing ) {
			if ( ! isset( $existing['status'] ) || 'pending' !== $existing['status'] ) {
				continue;
			}
			$by = isset( $existing['requested_by_key_id'] ) ? (string) $existing['requested_by_key_id'] : '';
			if ( 0 !== strpos( $by, 'wp-user:' ) ) {
				$pending_api++;
			}
			if ( $by === $requester ) {
				$pending_mine++;
			}
		}
		$is_admin_requester = 0 === strpos( $requester, 'wp-user:' );
		if ( $pending_mine >= Mcpwp_Destructive_Gate::MAX_PENDING_PER_REQUESTER ) {
			return new WP_Error(
				'too_many_pending',
				sprintf(
					/* translators: %d: cap */
					__( 'This principal already has %d destructive requests pending review. Have them reviewed or rejected before requesting more.', 'mcpwp' ),
					Mcpwp_Destructive_Gate::MAX_PENDING_PER_REQUESTER
				),
				array( 'status' => 429 )
			);
		}
		if ( ! $is_admin_requester && $pending_api >= Mcpwp_Destructive_Gate::MAX_PENDING_TOTAL ) {
			return new WP_Error(
				'approval_queue_full',
				sprintf(
					/* translators: %d: cap */
					__( 'This site already has %d destructive requests pending review. Nothing was queued; have the queue reviewed first.', 'mcpwp' ),
					Mcpwp_Destructive_Gate::MAX_PENDING_TOTAL
				),
				array( 'status' => 429 )
			);
		}

		$id  = self::generate_id();
		$now = gmdate( 'c' );

		$request = array(
			'id'                  => $id,
			'status'              => 'pending',
			'action'              => 'destructive_operation',
			'operation'           => sanitize_key( (string) $operation ),
			'title'               => isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : sprintf( 'Destructive: %s', sanitize_key( (string) $operation ) ),
			'note'                => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '',
			'tool'                => sanitize_key( (string) $operation ),
			'resource'            => array(
				'type'  => isset( $resource['type'] ) ? sanitize_key( (string) $resource['type'] ) : 'unknown',
				'id'    => isset( $resource['id'] ) ? $resource['id'] : null,
				'title' => isset( $resource['title'] ) ? sanitize_text_field( (string) $resource['title'] ) : '',
			),
			'diff'                => array(
				'summary' => __( 'No undo. Once applied, this cannot be rolled back.', 'mcpwp' ),
			),
			'payload'             => $payload,
			'rollback'            => array(),
			'metadata'            => array(),
			'requested_by_key_id' => isset( $args['requested_by_key_id'] ) ? sanitize_text_field( (string) $args['requested_by_key_id'] ) : '',
			'created_at'          => $now,
			'updated_at'          => $now,
			'approved_at'         => null,
			'applied_at'          => null,
			'rejected_at'         => null,
			'rolled_back_at'      => null,
		);

		self::upsert( $request );
		$public = self::public_record( $request );

		self::emit_event(
			'approval.created',
			$public,
			'pending',
			'high',
			__( 'Review a destructive operation before it runs.', 'mcpwp' )
		);
		self::maybe_schedule_triage( $public );

		return $public;
	}

	/**
	 * Create an approval request to publish a batch of already-drafted
	 * posts/pages at once (#957 rule 2: a bulk-create call over the
	 * configured threshold is created as drafts plus one approval request,
	 * instead of publishing directly). Unlike create_destructive_request(),
	 * this is reversible: rollback simply reverts the batch to draft — see
	 * Mcpwp_Content_Guardrails::apply_bulk_publish(), which apply_payload()
	 * delegates to below.
	 *
	 * @param int[]  $post_ids  IDs already created as drafts.
	 * @param string $post_type 'post' or 'page'.
	 * @param array  $args      title/note/tool/requested_by_key_id.
	 * @return array|WP_Error Public approval request.
	 */
	public static function create_bulk_publish_request( array $post_ids, $post_type, $args = array() ) {
		$post_ids  = array_values( array_unique( array_map( 'absint', $post_ids ) ) );
		$post_type = sanitize_key( (string) $post_type );

		if ( empty( $post_ids ) ) {
			return new WP_Error( 'no_items', __( 'No items to queue for publish approval.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$id    = self::generate_id();
		$now   = gmdate( 'c' );
		$count = count( $post_ids );

		$request = array(
			'id'                  => $id,
			'status'              => 'pending',
			'action'              => 'bulk_content_publish',
			'title'               => isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : sprintf(
				/* translators: 1: item count, 2: post type. */
				__( 'Publish %1$d %2$s at once', 'mcpwp' ),
				$count,
				$post_type
			),
			'note'                => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '',
			'tool'                => isset( $args['tool'] ) ? sanitize_key( (string) $args['tool'] ) : '',
			'resource'            => array(
				'type'  => 'bulk_' . $post_type,
				'id'    => null,
				'title' => sprintf( '%1$d %2$s', $count, $post_type ),
			),
			'diff'                => array(
				'summary' => sprintf(
					/* translators: %d: item count. */
					__( 'Created as drafts; queued for approval to publish all %d at once (bulk creation threshold exceeded).', 'mcpwp' ),
					$count
				),
			),
			'payload'             => array(
				'post_ids'  => $post_ids,
				'post_type' => $post_type,
			),
			'rollback'            => array(
				'post_ids'  => $post_ids,
				'post_type' => $post_type,
			),
			'metadata'            => array(),
			'requested_by_key_id' => isset( $args['requested_by_key_id'] ) ? sanitize_text_field( (string) $args['requested_by_key_id'] ) : '',
			'created_at'          => $now,
			'updated_at'          => $now,
			'approved_at'         => null,
			'applied_at'          => null,
			'rejected_at'         => null,
			'rolled_back_at'      => null,
		);

		self::upsert( $request );

		$public = self::public_record( $request );
		self::emit_event(
			'approval.created',
			$public,
			'pending',
			'medium',
			__( 'Review before publishing this batch.', 'mcpwp' )
		);
		self::maybe_schedule_triage( $public );

		return $public;
	}

	/**
	 * @param string $status            Status filter.
	 * @param int    $limit             Maximum records.
	 * @param bool   $reveal_non_public When true, return full records; when false
	 *                                  (default, SAFE) redact non-public sources.
	 * @param string $order             'date' (default) or 'priority'. 'priority'
	 *                                  sorts records that carry a validated Jev
	 *                                  `triage` by priority (urgent first), placing
	 *                                  untriaged records after them; within each
	 *                                  group the existing date order is kept. Jev
	 *                                  never decides WHETHER a request is shown,
	 *                                  approved, or applied — only where it sorts.
	 * @return array Requests.
	 */
	public static function list_requests( $status = '', $limit = 50, $reveal_non_public = false, $order = 'date' ) {
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

		if ( 'priority' === sanitize_key( (string) $order ) ) {
			$items = self::sort_by_priority( $items );
		}

		$records = array_map( array( __CLASS__, 'public_record' ), array_slice( $items, 0, $limit ) );

		if ( ! $reveal_non_public ) {
			$records = array_map( array( __CLASS__, 'redact_source_for_unprivileged' ), $records );
		}

		return $records;
	}

	/**
	 * Stable-sort already date-ordered items so a validated `triage.priority`
	 * moves urgent-first; items with no (or invalid) triage keep their
	 * existing date order, after every triaged item. This is display
	 * ordering only — it does not filter, approve, reject, or otherwise
	 * change any record.
	 *
	 * @param array $items Date-ordered requests (private or public shape; both carry 'triage').
	 * @return array Same items, re-ordered.
	 */
	private static function sort_by_priority( array $items ) {
		$rank = function ( $item ) {
			$triage = self::sanitize_triage( isset( $item['triage'] ) ? $item['triage'] : null );
			return null === $triage ? -1 : self::triage_priority_rank( $triage['priority'] );
		};

		usort(
			$items,
			function ( $a, $b ) use ( $rank ) {
				return $rank( $b ) <=> $rank( $a );
			}
		);

		return $items;
	}

	/**
	 * Numeric rank for a validated priority level, highest first.
	 *
	 * @param string $priority One of TRIAGE_PRIORITIES.
	 * @return int
	 */
	public static function triage_priority_rank( $priority ) {
		$index = array_search( $priority, self::TRIAGE_PRIORITIES, true );
		return false === $index ? -1 : $index;
	}

	/**
	 * The single validation chokepoint for a `triage` record — every writer
	 * (the Jev cron callback) and every reader that must not trust stored
	 * data at face value (sort_by_priority(), the Control Room template)
	 * calls this. Anything that fails comes back null: never partially
	 * accepted, never rendered, never sorted as if it meant something.
	 *
	 * Jev's answer is untrusted data (site content it summarized may itself
	 * carry injected instructions) — this is the fail-closed boundary that
	 * keeps a spoofed "urgent"-looking string, or an out-of-range confidence,
	 * from reaching storage or the screen unchanged.
	 *
	 * @param mixed $triage Candidate triage array.
	 * @return array{priority:string,change_kind:string,confidence:float,source:string,at:string}|null
	 */
	public static function sanitize_triage( $triage ) {
		if ( ! is_array( $triage ) ) {
			return null;
		}

		$priority = isset( $triage['priority'] ) ? sanitize_key( (string) $triage['priority'] ) : '';
		if ( ! in_array( $priority, self::TRIAGE_PRIORITIES, true ) ) {
			return null;
		}

		$change_kind = isset( $triage['change_kind'] ) ? sanitize_key( (string) $triage['change_kind'] ) : '';
		if ( ! in_array( $change_kind, self::TRIAGE_CHANGE_KINDS, true ) ) {
			return null;
		}

		$confidence = isset( $triage['confidence'] ) && is_numeric( $triage['confidence'] ) ? (float) $triage['confidence'] : 0.0;
		$confidence = max( 0.0, min( 1.0, $confidence ) );

		$at = isset( $triage['at'] ) ? sanitize_text_field( (string) $triage['at'] ) : gmdate( 'c' );

		return array(
			'priority'    => $priority,
			'change_kind' => $change_kind,
			'confidence'  => $confidence,
			'source'      => 'jev',
			'at'          => $at,
		);
	}

	/**
	 * How many times set_triage() re-reads and retries its compare-and-swap
	 * after losing a race to another writer. Mirrors
	 * Mcpwp_Integration_Manager::WRITE_ATTEMPTS (#845 pattern).
	 *
	 * @var int
	 */
	const TRIAGE_WRITE_ATTEMPTS = 5;

	/**
	 * Store a validated Jev triage result on an approval record. Never
	 * called from, and never read by, approve_request()/reject_request()/
	 * apply_request()/apply_payload() — triage is display/sort metadata
	 * only, not a gate (see class docblock on Mcpwp_Jev).
	 *
	 * A record that fails sanitize_triage() is dropped: nothing is stored,
	 * and the existing (or absent) triage on the record is left untouched.
	 *
	 * Deliberately NOT built on get_all()/upsert(): that pair reads the
	 * whole store once here, then upsert() reads it AGAIN and blindly
	 * replaces this id's slot with the (possibly by-then-stale) record this
	 * method built from its own earlier read — so an approve/apply/reject
	 * that lands on this id between those two reads is silently reverted
	 * (approved -> pending; applied -> approved with applied_at wiped back
	 * to null, making it re-applicable). Instead this reads the row directly
	 * and merges ONLY the `triage` key onto whatever is there right now,
	 * with a real compare-and-swap retry loop identical in spirit to
	 * Mcpwp_Integration_Manager::write_records() (#845) — a lost race is
	 * detected and retried onto fresh data rather than silently overwritten.
	 *
	 * @param string $id     Approval request ID.
	 * @param mixed  $triage Candidate triage array.
	 * @return bool True when stored.
	 */
	public static function set_triage( $id, $triage ) {
		$sanitized = self::sanitize_triage( $triage );
		if ( null === $sanitized ) {
			return false;
		}

		$id = sanitize_key( (string) $id );
		if ( '' === $id || ! class_exists( 'Mcpwp_Encryption' ) ) {
			return false;
		}

		for ( $attempt = 0; $attempt < self::TRIAGE_WRITE_ATTEMPTS; $attempt++ ) {
			$row = Mcpwp_Encryption::read_option_row( self::OPTION_NAME );

			if ( Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
				return false;
			}

			$present = Mcpwp_Encryption::ROW_PRESENT === $row['state'];
			$items   = $present ? maybe_unserialize( $row['value'] ) : array();
			$items   = is_array( $items ) ? $items : array();

			if ( empty( $items[ $id ] ) || ! is_array( $items[ $id ] ) ) {
				return false;
			}

			// Merge onto the record exactly as read THIS iteration — never a
			// copy captured before this loop started.
			$items[ $id ]['triage'] = $sanitized;

			$value = maybe_serialize( $items );

			if ( $present && $value === $row['value'] ) {
				return true; // Already exactly this.
			}

			$written = $present
				? Mcpwp_Encryption::compare_and_swap_option_row( self::OPTION_NAME, $row['value'], $value )
				: Mcpwp_Encryption::insert_option_row_if_absent( self::OPTION_NAME, $value, 'no' );

			if ( $written ) {
				Mcpwp_Encryption::clean_option_cache( self::OPTION_NAME );
				return true;
			}

			// Another writer's change landed between our read and our write
			// this iteration — read again and reapply the merge onto ITS
			// result, rather than keeping our now-stale copy.
		}

		return false;
	}

	/**
	 * Schedule async Jev triage for a just-created approval — never blocks
	 * the call that created it. A no-op whenever Jev is not configured, or
	 * the cron API is unavailable (mirrors Mcpwp_Screenshot's guard).
	 *
	 * @param array $public Public approval record (needs only 'id').
	 * @return void
	 */
	private static function maybe_schedule_triage( $public ) {
		if ( ! is_array( $public ) || empty( $public['id'] ) ) {
			return;
		}
		if ( ! class_exists( 'Mcpwp_Jev' ) || ! Mcpwp_Jev::is_configured() ) {
			return;
		}
		if ( ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( ! class_exists( 'Mcpwp_Jev_Triage' ) ) {
			return;
		}

		wp_schedule_single_event( time(), Mcpwp_Jev_Triage::HOOK, array( (string) $public['id'] ) );
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
			return new WP_Error( 'approval_not_found', __( 'Approval request not found.', 'mcpwp' ), array( 'status' => 404 ) );
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
	public static function approve_request( $id, $note = '', $context = array() ) {
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		// The actor guard binds destructive operations, on approve as on
		// apply. Other actions keep their own check_self_approval() path.
		if ( isset( $request['action'] ) && 'destructive_operation' === $request['action'] ) {
			$guard = self::guard_actor( $id, $context );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
		}

		return self::transition_request( $id, 'approved', 'approved_at', $note );
	}

	/**
	 * The two-person rule, enforced where the state changes (#536 review):
	 * the principal that requested an operation cannot approve or apply it,
	 * whichever door it comes through — REST, MCP or the Control Room.
	 *
	 * @param string $id      Approval id.
	 * @param array  $context {actor?: string (key/principal id), admin_user?: int}.
	 * @return true|WP_Error
	 */
	private static function guard_actor( $id, $context ) {
		$context    = is_array( $context ) ? $context : array();
		$actor      = isset( $context['actor'] ) ? (string) $context['actor'] : '';
		$admin_user = isset( $context['admin_user'] ) ? (int) $context['admin_user'] : 0;

		if ( '' === $actor && $admin_user < 1 ) {
			return new WP_Error(
				'unidentified_actor',
				__( 'Approving or applying needs an identified actor: a different API principal, or an administrator in wp-admin.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$requester = isset( $request['requested_by_key_id'] ) ? (string) $request['requested_by_key_id'] : '';
		if ( '' === $requester ) {
			return new WP_Error(
				'unidentified_requester',
				__( 'This destructive request names no requester, so the two-person rule cannot be applied to it. Reject it and request the operation again.', 'mcpwp' ),
				array( 'status' => 409 )
			);
		}
		if ( '' !== $actor && $actor === $requester ) {
			return new WP_Error(
				'self_approval_forbidden',
				__( 'The principal that requested this operation cannot approve or apply it. A different admin key, or an administrator in wp-admin, has to.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		return true;
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
	public static function apply_request( $id, $context = array() ) {
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		if ( isset( $request['action'] ) && 'destructive_operation' === $request['action'] ) {
			$guard = self::guard_actor( $id, $context );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}
		}

		if ( 'approved' !== $request['status'] ) {
			return new WP_Error( 'approval_not_ready', __( 'Approval request must be approved before it can be applied.', 'mcpwp' ), array( 'status' => 409 ) );
		}

		$result = self::apply_payload( $request, 'payload', is_array( $context ) ? $context : array() );
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
			__( 'Monitor applied change and keep rollback available.', 'mcpwp' )
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
			return new WP_Error( 'approval_not_applied', __( 'Only applied approval requests can be rolled back.', 'mcpwp' ), array( 'status' => 409 ) );
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
			__( 'Review rollback result.', 'mcpwp' )
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
			return new WP_Error( 'approval_closed', __( 'Approval request is already closed.', 'mcpwp' ), array( 'status' => 409 ) );
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
			'approved' === $status ? __( 'Apply approved change when ready.', 'mcpwp' ) : __( 'No apply action needed.', 'mcpwp' )
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
	private static function apply_payload( $request, $field, $context = array() ) {
		// Staging push apply path.
		if ( 'staging_push' === $request['action'] ) {
			return self::apply_staging_push( $request, $field );
		}

		// Bulk-publish apply/rollback path (#957) — delegates to the
		// guardrails module, which owns the ramp/anomaly bookkeeping that a
		// batch publish still has to go through.
		if ( 'bulk_content_publish' === $request['action'] ) {
			if ( ! class_exists( 'Mcpwp_Content_Guardrails' ) ) {
				return new WP_Error( 'guardrails_unavailable', __( 'The content guardrails module is not loaded.', 'mcpwp' ), array( 'status' => 500 ) );
			}
			return Mcpwp_Content_Guardrails::apply_bulk_publish( $request, $field );
		}

		// A destructive operation replays the reviewed call once; there is
		// nothing to roll back to.
		if ( 'destructive_operation' === $request['action'] ) {
			if ( 'rollback' === $field ) {
				return new WP_Error(
					'destructive_rollback_unavailable',
					__( 'A destructive operation has no rollback: what it removed is gone. Restore from a backup.', 'mcpwp' ),
					array( 'status' => 400 )
				);
			}

			if ( ! class_exists( 'Mcpwp_Destructive_Gate' ) ) {
				return new WP_Error( 'destructive_gate_unavailable', __( 'The destructive-operation gate is not loaded.', 'mcpwp' ), array( 'status' => 500 ) );
			}

			$result = Mcpwp_Destructive_Gate::replay(
				isset( $request['payload'] ) && is_array( $request['payload'] ) ? $request['payload'] : array(),
				is_array( $context ) ? $context : array(),
				isset( $request['operation'] ) ? (string) $request['operation'] : ''
			);

			return is_wp_error( $result ) ? $result : true;
		}

		if ( 'post_content_update' !== $request['action'] || empty( $request['resource']['id'] ) ) {
			return new WP_Error( 'unsupported_approval_action', __( 'Approval action is not supported yet.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$post_id = (int) $request['resource']['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		$current_hash  = hash( 'sha256', (string) $post->post_content );
		$expected_hash = 'rollback' === $field
			? ( isset( $request['diff']['after_hash'] ) ? (string) $request['diff']['after_hash'] : '' )
			: ( isset( $request['rollback']['before_hash'] ) ? (string) $request['rollback']['before_hash'] : '' );

		if ( '' !== $expected_hash && $current_hash !== $expected_hash ) {
			return new WP_Error( 'approval_content_conflict', __( 'Post content changed after this approval request was created.', 'mcpwp' ), array( 'status' => 409 ) );
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
			return new WP_Error( 'approval_not_found', __( 'Approval request not found.', 'mcpwp' ), array( 'status' => 404 ) );
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

		// The target fingerprint is for drift(), which reads the private
		// record. Publicly it would hand a read-scope key a private page's
		// exact title, status, modification time and a hash that confirms
		// guessed body text offline (#536 round 2, P1-2).
		if ( isset( $request['payload']['target'] ) && is_array( $request['payload']['target'] ) ) {
			$target = $request['payload']['target'];
			$request['payload']['target'] = array_intersect_key( $target, array( 'exists' => 1, 'type' => 1, 'count' => 1 ) );
		}

		return $request;
	}

	/**
	 * Redact source-derived fields from a public approval record when the reader
	 * is unprivileged and the referenced post is non-public.
	 *
	 * The approvals read surface (GET /approvals, GET /approvals/{id}) is only
	 * API-key gated, not write-scope gated, so a read-only key can list every
	 * queued approval. A record that references a draft/private post carries the
	 * source's title, permalink, status, and a 40-word before/after excerpt of
	 * its body via public_record(). Without this clamp a read key could harvest
	 * unpublished content that a privileged key legitimately queued for review.
	 *
	 * Scope is decided by the caller (it lives on the REST controller, which has
	 * the auth trait); this static helper only performs the field-level strip so
	 * the redaction is identical across every read entry point. Only 'post'
	 * resources are touched — staging-push and other non-post approvals are
	 * unaffected. Non-public status is resolved from the LIVE post first (a post
	 * that was published then unpublished is still protected), falling back to the
	 * status stored on the record.
	 *
	 * @param array $record Public approval record.
	 * @return array Record with source fields stripped when protected.
	 */
	public static function redact_source_for_unprivileged( $record ) {
		if ( ! is_array( $record ) || empty( $record['resource'] ) || ! is_array( $record['resource'] ) ) {
			return $record;
		}

		$resource = $record['resource'];
		// Any post-like resource (post, page, a custom type): a destructive
		// request against a private page must not leak its title to a
		// read-scope key through the approvals list.
		if ( empty( $resource['id'] ) || ! is_numeric( $resource['id'] ) || ( isset( $resource['type'] ) && in_array( $resource['type'], array( 'api_key', 'drafts' ), true ) ) ) {
			return $record;
		}

		$post   = get_post( (int) $resource['id'] );
		$status = $post ? $post->post_status : ( isset( $resource['status'] ) ? (string) $resource['status'] : '' );

		$status_obj = get_post_status_object( (string) $status );
		if ( $status_obj && ! empty( $status_obj->public ) ) {
			return $record;
		}

		// Non-public source: strip everything that would reveal the hidden body,
		// title, location, or confirm which draft/private status it holds.
		$record['resource']['title']  = '';
		$record['resource']['url']    = '';
		$record['resource']['status'] = 'restricted';

		if ( isset( $record['diff'] ) && is_array( $record['diff'] ) ) {
			unset( $record['diff']['before_excerpt'], $record['diff']['after_excerpt'] );
			$record['diff']['redacted'] = true;
		}

		// triage.change_kind is derived from (and hints at) the hidden
		// content — e.g. "deletion" or "seo_meta" on an otherwise-restricted
		// record still tells an unprivileged reader something about what the
		// content is. priority/confidence stay: they only rank the queue and
		// name nothing about the resource itself.
		if ( isset( $record['triage'] ) && is_array( $record['triage'] ) ) {
			unset( $record['triage']['change_kind'] );
		}

		$record['redacted'] = true;

		return $record;
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

		// Only closed records are evicted; a pending or approved request is
		// someone's unfinished review and stays until it is decided.
		if ( count( $items ) > self::MAX_ITEMS ) {
			$open   = array_filter( $items, function ( $item ) { return isset( $item['status'] ) && in_array( $item['status'], array( 'pending', 'approved' ), true ); } );
			$closed = array_filter( $items, function ( $item ) { return ! isset( $item['status'] ) || ! in_array( $item['status'], array( 'pending', 'approved' ), true ); } );
			$closed = array_slice( $closed, 0, max( 0, self::MAX_ITEMS - count( $open ) ), true );
			$items  = $open + $closed;
		}
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
					'mcpwp'
				),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( 'Mcpwp_Staging' ) ) {
			return new WP_Error(
				'staging_class_unavailable',
				__( 'Staging module is not available. Ensure the Pro module is active.', 'mcpwp' ),
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
				__( 'Staging push approval is missing source or destination environment ID.', 'mcpwp' ),
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
				isset( $result['message'] ) ? $result['message'] : __( 'Staging push failed.', 'mcpwp' ),
				array( 'status' => 502 )
			);
		}

		return true;
	}
}
