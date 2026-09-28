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

		// #1025 WARN-1: resource.type below is ALWAYS the literal string
		// 'post', regardless of $post's real post_type — a product or an
		// Elementor template edited through /blocks or /content-graph (#1024)
		// stores 'post' here too. resource.post_type is the real type,
		// captured NOW while $post is still guaranteed to resolve, so a
		// later reader is never forced to fall back to a guess once the
		// target has been deleted.
		$resource = array(
			'type'      => 'post',
			'post_type' => (string) $post->post_type,
			'id'        => (int) $post_id,
			'title'     => get_the_title( $post ),
			'status'    => $post->post_status,
			'url'       => get_permalink( $post ),
		);
		$payload  = array(
			'after_content' => $after_content,
		);

		$request = array(
			'id'          => $id,
			'status'      => 'pending',
			'action'      => 'post_content_update',
			'title'       => isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : sprintf( 'Update content for #%d', (int) $post_id ),
			'note'        => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '',
			'tool'        => isset( $args['tool'] ) ? sanitize_key( (string) $args['tool'] ) : '',
			'resource'    => $resource,
			'diff'        => self::build_content_diff( $before_content, $after_content ),
			'payload'     => $payload,
			'rollback'    => array(
				'before_content' => $before_content,
				'before_hash'    => hash( 'sha256', $before_content ),
			),
			'metadata'    => isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array(),
			// Requester identity for separation-of-duties: the same API key that
			// creates an approval cannot later approve/apply it.
			// Mirrors the field the staging-push path already stores.
			'requested_by_key_id' => isset( $args['requested_by_key_id'] ) ? sanitize_text_field( (string) $args['requested_by_key_id'] ) : '',
			// #1025: computed ONCE, now, while the target is guaranteed to
			// resolve — see categories_for_approval()'s docblock for why a
			// value stored here is preferred over recomputing at read time.
			'visibility_categories' => self::compute_visibility_categories( 'post_content_update', $resource, $payload ),
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

		$normalized_resource = array(
			'type'  => isset( $resource['type'] ) ? sanitize_key( (string) $resource['type'] ) : 'unknown',
			'id'    => isset( $resource['id'] ) ? $resource['id'] : null,
			'title' => isset( $resource['title'] ) ? sanitize_text_field( (string) $resource['title'] ) : '',
		);

		$request = array(
			'id'                  => $id,
			'status'              => 'pending',
			'action'              => 'destructive_operation',
			'operation'           => sanitize_key( (string) $operation ),
			'title'               => isset( $args['title'] ) ? sanitize_text_field( (string) $args['title'] ) : sprintf( 'Destructive: %s', sanitize_key( (string) $operation ) ),
			'note'                => isset( $args['note'] ) ? sanitize_textarea_field( (string) $args['note'] ) : '',
			'tool'                => sanitize_key( (string) $operation ),
			'resource'            => $normalized_resource,
			'diff'                => array(
				'summary' => __( 'No undo. Once applied, this cannot be rolled back.', 'mcpwp' ),
			),
			'payload'             => $payload,
			'rollback'            => array(),
			'metadata'            => array(),
			'requested_by_key_id' => isset( $args['requested_by_key_id'] ) ? sanitize_text_field( (string) $args['requested_by_key_id'] ) : '',
			// #1025: computed ONCE, now, from the resolved resource/payload
			// (payload.target, just built by fingerprint() in defer(), still
			// names the target's real post_type when it has one) — see
			// categories_for_approval()'s docblock.
			'visibility_categories' => self::compute_visibility_categories( 'destructive_operation', $normalized_resource, $payload ),
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
	 * @param array  $items     {id, hash, findings} entries, IDs already
	 *                          created as drafts.
	 * @param string $post_type 'post' or 'page'.
	 * @param array  $args      title/note/tool/requested_by_key_id.
	 * @return array|WP_Error Public approval request.
	 */
	public static function create_bulk_publish_request( array $items, $post_type, $args = array() ) {
		$post_type = sanitize_key( (string) $post_type );

		// Each item: {id, hash, findings} — see
		// Mcpwp_Content_Guardrails::snapshot_for_approval(). 'hash' lets
		// apply_bulk_publish() refuse to publish anything edited since this
		// snapshot; 'findings' is shown to the approver (#957 adversarial
		// review WARN-1).
		$normalized = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$normalized[] = array(
				'id'       => absint( $item['id'] ),
				'hash'     => isset( $item['hash'] ) ? sanitize_text_field( (string) $item['hash'] ) : null,
				'findings' => isset( $item['findings'] ) && is_array( $item['findings'] ) ? $item['findings'] : array(),
			);
		}

		if ( empty( $normalized ) ) {
			return new WP_Error( 'no_items', __( 'No items to queue for publish approval.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$id    = self::generate_id();
		$now   = gmdate( 'c' );
		$count = count( $normalized );

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
				'items'     => $normalized,
				'post_type' => $post_type,
			),
			'rollback'            => array(
				'items'     => $normalized,
				'post_type' => $post_type,
			),
			'metadata'            => array(),
			'requested_by_key_id' => isset( $args['requested_by_key_id'] ) ? sanitize_text_field( (string) $args['requested_by_key_id'] ) : '',
			// #1025: computed ONCE, now, from the real $post_type — see
			// categories_for_approval()'s docblock.
			'visibility_categories' => self::compute_visibility_categories( 'bulk_content_publish', array( 'type' => 'bulk_' . $post_type, 'id' => null ), array( 'post_type' => $post_type ) ),
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
	 * @param callable|null $category_allowed #1025: optional `function(string $category): bool`
	 *                                  — the caller's own `caller_may_read_category()`,
	 *                                  since only the REST controller holding the
	 *                                  request has that context. When given, a
	 *                                  request whose categories_for_approval() this
	 *                                  rejects is dropped BEFORE the $limit slice
	 *                                  (so a hidden item never displaces a visible
	 *                                  one out of the page) and before any count is
	 *                                  derived from $items elsewhere (Mcpwp_Site_State),
	 *                                  so a hidden approval is indistinguishable from
	 *                                  one that was never created — no separate total
	 *                                  exists here for a filtered-out item to
	 *                                  contradict. Null (default): unfiltered,
	 *                                  matching every existing caller unchanged.
	 * @return array Requests.
	 */
	public static function list_requests( $status = '', $limit = 50, $reveal_non_public = false, $order = 'date', $category_allowed = null ) {
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

		if ( is_callable( $category_allowed ) ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $category_allowed ) {
						return self::is_approval_visible_to( $item, $category_allowed );
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
			return self::not_found_error();
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

		// Re-fetch: apply_payload() (e.g. Mcpwp_Content_Guardrails::
		// apply_bulk_publish()'s record_apply_result()) may have just
		// written metadata onto this SAME record via its own update_option()
		// call — upserting the copy read at the top of this method, before
		// that write happened, would silently erase it.
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
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

		// Re-fetch for the same reason as apply_request() above.
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
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
		// batch publish still has to go through. $context is forwarded so
		// apply_bulk_publish() can decide whether THIS apply is a genuine
		// wp-admin human (the only caller its ramp-cap exemption ever
		// applies to — #957 adversarial review round 4 WARN-2).
		if ( 'bulk_content_publish' === $request['action'] ) {
			if ( ! class_exists( 'Mcpwp_Content_Guardrails' ) ) {
				return new WP_Error( 'guardrails_unavailable', __( 'The content guardrails module is not loaded.', 'mcpwp' ), array( 'status' => 500 ) );
			}
			return Mcpwp_Content_Guardrails::apply_bulk_publish( $request, $field, is_array( $context ) ? $context : array() );
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

			// #1022 round-2 review NIT: the resolved post_type this record
			// was queued against (set at defer time — see fingerprint()) so
			// a replay failure can name the disabled category to the
			// wp-admin principal applying it, instead of a bare HTTP status.
			$replay_context                  = is_array( $context ) ? $context : array();
			$replay_context['resource_type'] = isset( $request['resource']['type'] ) ? (string) $request['resource']['type'] : '';

			$result = Mcpwp_Destructive_Gate::replay(
				isset( $request['payload'] ) && is_array( $request['payload'] ) ? $request['payload'] : array(),
				$replay_context,
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
			return self::not_found_error();
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
	 * Sentinel category (#1025 round-2 WARN-1): never a real, toggleable
	 * category — no role's category list, and no admin's disabled-categories
	 * option, can ever legitimately name it. caller_may_read_category()'s
	 * own rules make it behave exactly as "visible only to an UNRESTRICTED
	 * key" without a special case at the call site: an unrestricted key
	 * (resolve_key_tool_categories() returns empty) is allowed regardless of
	 * the category asked; any restricted key's `in_array( $category,
	 * $allowed, true )` is false for a slug that appears in no role's list.
	 * Used when an approval's target category genuinely cannot be resolved —
	 * see categories_for_approval()'s docblock.
	 *
	 * @var string
	 */
	const UNRESOLVABLE_CATEGORY = '__unresolvable__';

	/**
	 * Every tool category that must ALL be satisfied to read an approval
	 * (#1025 round 2, BLOCK-1 — found reproducing the issue's own PoC
	 * against round 1 of this fix). Round 1 classified a destructive
	 * approval's target by its ROUTE alone, but the generic, id-based doors
	 * `DELETE /posts/{id}` and `DELETE /content/{type}/{id}` defer a delete
	 * of ANY post type through a route that itself classifies as 'content'
	 * — so a custom [admin, content] key still saw a queued WooCommerce
	 * product deletion and an Elementor archetype deletion in full
	 * (`{"resource":{"type":"product","id":...,"title":"SECRET PRODUCT
	 * Zeta"}}`), exactly reproducing the original issue through a different
	 * pair of doors. A generic door's route can never be widened to name
	 * every domain it might touch, so the fix is additive, not a
	 * replacement: the ROUTE category AND, whenever the target is a
	 * resolvable post, `category_for_post_type()`'s category — the caller
	 * must hold BOTH. See resolve_target_post_type() for how "whenever the
	 * target is a resolvable post" is decided.
	 *
	 * Computed ONCE, at creation time, by every `create_*_request()` method
	 * (and Mcpwp_REST_Staging::create_staging_push_approval(), which builds
	 * its own record shape) and stored on the record as
	 * `visibility_categories` — categories_for_approval() prefers that
	 * stored value over recomputing live (see its own docblock for why: a
	 * value computed NOW, while the target is guaranteed to still resolve,
	 * cannot be widened later by the target being deleted — #1025 round 2
	 * WARN-1).
	 *
	 * @param string $action   Approval action ('destructive_operation',
	 *                         'bulk_content_publish', 'staging_push',
	 *                         'post_content_update', ...).
	 * @param array  $resource Resource block as stored on the record.
	 * @param array  $payload  Payload block as stored on the record.
	 * @return string[] Every category the caller must hold; never empty —
	 *                   falls back to array(self::UNRESOLVABLE_CATEGORY)
	 *                   when nothing resolves.
	 */
	public static function compute_visibility_categories( $action, array $resource, array $payload ) {
		if ( ! class_exists( 'Mcpwp_REST_API' ) ) {
			return array( self::UNRESOLVABLE_CATEGORY );
		}

		$action     = (string) $action;
		$categories = array();

		// The route this call will replay (or already replayed once, for a
		// closed record) — the classification the generic door ITSELF is
		// gated by. Still required in addition to the post-type category
		// below: a route-only check is what round 1 shipped and BLOCK-1
		// broke; a post-type-only check would miss a destructive target
		// that is not a post at all (user, term, widget, the "all drafts"
		// purge) and that only the route names.
		if ( 'destructive_operation' === $action && ! empty( $payload['route'] ) ) {
			$route_category = Mcpwp_REST_API::get_category_for_route( (string) $payload['route'] );
			if ( '' !== $route_category ) {
				$categories[] = $route_category;
			}
		}

		if ( 'bulk_content_publish' === $action && ! empty( $payload['post_type'] ) ) {
			$categories[] = Mcpwp_REST_API::category_for_post_type( (string) $payload['post_type'] );
		}

		if ( 'staging_push' === $action ) {
			$categories[] = 'staging';
		}

		// Whenever the target IS a post — independent of, and additive to,
		// the action-shape classification above — the caller must ALSO hold
		// its post-type category. This is what closes BLOCK-1: a product
		// deleted through the GENERIC /posts/{id} or /content/{type}/{id}
		// door classifies 'content' by route alone, but the record's target
		// is unambiguously a product, so 'woocommerce' is required too.
		$post_type = self::resolve_target_post_type( $action, $resource, $payload );
		if ( '' !== $post_type ) {
			$categories[] = Mcpwp_REST_API::category_for_post_type( $post_type );
		}

		if ( empty( $categories ) ) {
			// Nothing resolved at all: no route classification applied and
			// no post-type could be determined. Round 1's fallback here was
			// 'admin' — the same category /approvals itself is gated by, so
			// EVERY approvals reader could see a record in this state
			// (#1025 round 2, WARN-1). Fall back to "visible only to an
			// unrestricted key" instead — see UNRESOLVABLE_CATEGORY's
			// docblock for how that's enforced with no special case.
			return array( self::UNRESOLVABLE_CATEGORY );
		}

		return array_values( array_unique( $categories ) );
	}

	/**
	 * Resolve the real post_type of an approval's target, for
	 * compute_visibility_categories() — '' when the target is not a post at
	 * all, or is one no signal here can place.
	 *
	 * Tries, in order of trust:
	 * 1. The LIVE post (get_post() on resource.id) — authoritative, and the
	 *    only source available at creation time (every caller here has just
	 *    resolved the same post itself). Only attempted when resource.type
	 *    ITSELF already names a post — literally 'post' (post_content_
	 *    update's own deliberate hardcode) or a real, currently-registered
	 *    post type (post_type_exists()). NEVER for a non-post sentinel
	 *    ('user', 'term', 'widget', 'drafts', 'staging', 'api_key', a
	 *    'bulk_<type>' label): resource.id there is a USER id, a TERM id,
	 *    a widget slug — not a post id, and can coincidentally collide with
	 *    an unrelated post's numeric ID. #1025 round 3: a delete_user
	 *    approval for user #11 was misclassified as ["admin","woocommerce"]
	 *    because a product happened to exist with post ID 11 — get_post(11)
	 *    resolved to the UNRELATED product and its category got added
	 *    alongside the correct 'admin' one, so disabling 'woocommerce'
	 *    site-wide hid a delete_user approval from every key, including
	 *    ones with no reason to lose it.
	 * 2. payload.target.type — Mcpwp_Destructive_Gate::fingerprint()'s own
	 *    capture of the post's type at the moment a destructive call was
	 *    deferred. Reliable for every destructive_operation record, even a
	 *    LEGACY one predating `visibility_categories` (#1025 round 2,
	 *    WARN-1): the target's type was already being captured here before
	 *    this fix, so a hard-deleted destructive target still classifies
	 *    correctly without any new data.
	 * 3. resource.post_type — the real-type field this round's creators now
	 *    store explicitly (see e.g. create_post_content_request()).
	 * 4. resource.type — ONLY for destructive_operation. Its callers pass
	 *    the resolved post's real post_type deliberately (or a non-post
	 *    sentinel — 'user', 'term', 'widget', 'drafts', 'api_key' — that
	 *    post_type_exists() rejects here, so trusting it is safe). NEVER for
	 *    post_content_update: create_post_content_request() hardcodes
	 *    resource.type to the literal string 'post' regardless of the
	 *    target's real type (WARN-1's own finding) — trusting it there
	 *    would under-classify a legacy, target-deleted product/template
	 *    edit as ordinary 'content' instead of falling back safely.
	 *
	 * @param string $action   Approval action.
	 * @param array  $resource Resource block.
	 * @param array  $payload  Payload block.
	 * @return string Real post_type, or '' when none of the above resolves one.
	 */
	private static function resolve_target_post_type( $action, array $resource, array $payload ) {
		$post_id       = isset( $resource['id'] ) ? $resource['id'] : null;
		$resource_type = isset( $resource['type'] ) ? (string) $resource['type'] : '';

		if ( is_numeric( $post_id ) && (int) $post_id > 0 && ( 'post' === $resource_type || post_type_exists( $resource_type ) ) ) {
			$post = get_post( (int) $post_id );
			if ( $post instanceof WP_Post ) {
				return (string) $post->post_type;
			}
		}

		if ( ! empty( $payload['target']['type'] ) && post_type_exists( (string) $payload['target']['type'] ) ) {
			return (string) $payload['target']['type'];
		}

		if ( ! empty( $resource['post_type'] ) && post_type_exists( (string) $resource['post_type'] ) ) {
			return (string) $resource['post_type'];
		}

		if ( 'destructive_operation' === $action && ! empty( $resource['type'] ) && post_type_exists( (string) $resource['type'] ) ) {
			return (string) $resource['type'];
		}

		return '';
	}

	/**
	 * Every tool category a caller must hold to read $record (#1025).
	 *
	 * Prefers the record's own stored `visibility_categories` — computed
	 * ONCE at creation time by whichever `create_*_request()` method built
	 * it, while the target was guaranteed to still resolve — over
	 * recomputing live. This is what closes WARN-1: recomputing at READ time
	 * against a target that has SINCE been hard-deleted can only ever do
	 * worse than what was known at creation, never better, so a stored value
	 * is authoritative and a live one is a fallback for a record that
	 * predates this field (or was built by a caller not yet updated to set
	 * it).
	 *
	 * @param array $record Public or private approval record.
	 * @return string[] Every category the caller must hold; never empty.
	 */
	public static function categories_for_approval( array $record ) {
		if ( ! empty( $record['visibility_categories'] ) && is_array( $record['visibility_categories'] ) ) {
			$stored = array_values( array_unique( array_map( 'strval', $record['visibility_categories'] ) ) );
			if ( ! empty( $stored ) ) {
				return $stored;
			}
		}

		$action   = isset( $record['action'] ) ? (string) $record['action'] : '';
		$resource = isset( $record['resource'] ) && is_array( $record['resource'] ) ? $record['resource'] : array();
		$payload  = isset( $record['payload'] ) && is_array( $record['payload'] ) ? $record['payload'] : array();

		return self::compute_visibility_categories( $action, $resource, $payload );
	}

	/**
	 * Whether $record is visible to a caller whose permission is decided by
	 * $category_allowed — the single chokepoint every reader (list, get,
	 * approve, reject, apply, rollback, the event store, the site-state
	 * summary) calls, so "ALL categories must be satisfied" is enforced in
	 * exactly one place rather than re-implemented (and potentially
	 * mis-implemented, e.g. as OR instead of AND) at each call site.
	 *
	 * @param array    $record           Public or private approval record.
	 * @param callable $category_allowed function(string $category): bool —
	 *                                    the caller's own
	 *                                    caller_may_read_category().
	 * @return bool
	 */
	public static function is_approval_visible_to( array $record, $category_allowed ) {
		if ( ! is_callable( $category_allowed ) ) {
			return true;
		}

		foreach ( self::categories_for_approval( $record ) as $category ) {
			if ( ! call_user_func( $category_allowed, $category ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The exact WP_Error a genuinely-missing approval id returns
	 * (get_request(), get_private_request()) — and the shape #1025's
	 * category guard must be byte-identical to, so a hidden approval cannot
	 * be told apart from a missing one. One construction site, not several
	 * copies of the same literal, so "identical by construction" is actually
	 * true rather than merely true today.
	 *
	 * @return WP_Error
	 */
	public static function not_found_error() {
		return new WP_Error( 'approval_not_found', __( 'Approval request not found.', 'mcpwp' ), array( 'status' => 404 ) );
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
