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
	 * Pending post_content_update requests one requester may hold (#981
	 * review WARN-2). Every request carries its full proposed body in the
	 * one approvals option, so an unbounded queue is an unbounded option.
	 * Applies to category-restricted API keys and WebMCP users; an
	 * unrestricted key or a wp-admin user could make the change directly and
	 * is exempt (#981 review round 2, WARN-C). Filterable: see content_limits().
	 */
	const MAX_PENDING_CONTENT_PER_REQUESTER = 20;

	/**
	 * Pending post_content_update requests the whole site may hold from
	 * capped requesters.
	 */
	const MAX_PENDING_CONTENT_TOTAL = 100;

	/**
	 * Largest proposed body a WebMCP user may propose, bytes.
	 */
	const MAX_PROPOSED_CONTENT_BYTES = 262144;

	/**
	 * Largest proposed body any other requester may propose, bytes — memory
	 * protection for the one approvals option, not a content policy.
	 */
	const MAX_PROPOSED_CONTENT_BYTES_API = 1048576;

	/**
	 * A pending post_content_update older than this expires (status
	 * 'expired'), seconds. Filterable: mcpwp_approval_pending_ttl.
	 */
	const PENDING_CONTENT_TTL = 2592000;

	/**
	 * Budget for the whole serialized approvals store, bytes.
	 */
	const STORE_MAX_BYTES = 8388608;

	/**
	 * A post body larger than this is not copied into the approval as its
	 * rollback source; a revision is referenced instead, bytes.
	 */
	const MAX_ROLLBACK_INLINE_BYTES = 262144;

	/**
	 * Seconds to wait for the approvals store lock before refusing.
	 */
	const LOCK_TIMEOUT = 5;

	/**
	 * Re-entrancy depth of the approvals store lock in this process.
	 *
	 * @var int
	 */
	private static $lock_depth = 0;

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

		$requester = isset( $args['requested_by_key_id'] ) ? sanitize_text_field( (string) $args['requested_by_key_id'] ) : '';
		$limits    = self::content_limits();

		// #981 review WARN-2 / round 2 WARN-C: each request stores the whole
		// proposed body (plus a diff) in the one approvals option. Without
		// bounds a caller could grow it until every later read of the queue
		// ran the site out of memory — for everyone.
		$max_bytes = 0 === strpos( $requester, 'webmcp:' ) ? $limits['max_bytes_webmcp'] : $limits['max_bytes'];
		if ( strlen( $after_content ) > $max_bytes ) {
			return new WP_Error(
				'content_too_large',
				sprintf(
					/* translators: %d: size limit in KB */
					__( 'The proposed content is larger than %d KB. Split the change or edit the post in wp-admin.', 'mcpwp' ),
					(int) ( $max_bytes / 1024 )
				),
				array( 'status' => 413 )
			);
		}

		$capped = ! self::requester_is_exempt_from_caps( $requester, $args );

		$rollback = self::rollback_source( $post, $before_content );
		if ( is_wp_error( $rollback ) ) {
			return $rollback;
		}

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
			'rollback'    => $rollback,
			'metadata'    => array_merge(
				isset( $args['metadata'] ) && is_array( $args['metadata'] ) ? $args['metadata'] : array(),
				// Remembered so the pending caps count this request the same
				// way for its whole life (see requester_is_exempt_from_caps()).
				$capped ? array() : array( 'cap_exempt' => true )
			),
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

		// Count and insert under the store lock, so parallel proposals can
		// neither overshoot the caps nor overwrite one another.
		$stored = self::mutate(
			static function ( array $items ) use ( $request, $requester, $capped, $limits ) {
				if ( $capped ) {
					$pending_mine = 0;
					$pending_all  = 0;
					foreach ( $items as $existing ) {
						if ( ! is_array( $existing ) || ! isset( $existing['status'], $existing['action'] ) || 'pending' !== $existing['status'] || 'post_content_update' !== $existing['action'] ) {
							continue;
						}
						$by = isset( $existing['requested_by_key_id'] ) ? (string) $existing['requested_by_key_id'] : '';
						if ( self::requester_is_exempt_from_caps( $by, isset( $existing['metadata'] ) && is_array( $existing['metadata'] ) ? $existing['metadata'] : array() ) ) {
							continue;
						}
						$pending_all++;
						if ( $by === $requester ) {
							$pending_mine++;
						}
					}
					if ( $pending_mine >= $limits['per_requester'] ) {
						return new WP_Error(
							'too_many_pending',
							sprintf(
								/* translators: %d: cap */
								__( 'This requester already has %d content changes waiting for review. Have them approved or rejected before proposing more.', 'mcpwp' ),
								$limits['per_requester']
							),
							array( 'status' => 429 )
						);
					}
					if ( $pending_all >= $limits['total'] ) {
						return new WP_Error(
							'approval_queue_full',
							sprintf(
								/* translators: %d: cap */
								__( 'This site already has %d content changes waiting for review. Nothing was queued; have the queue reviewed first.', 'mcpwp' ),
								$limits['total']
							),
							array( 'status' => 429 )
						);
					}
				}
				$items[ $request['id'] ] = $request;
				return $items;
			}
		);
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

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

		$stored = self::upsert( $request );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
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

		$stored = self::upsert( $request );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

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
	 * A claim state machine (#981 review round 3, BLOCK-1) — the payload
	 * runs outside the store lock (it can be slow, and may itself write the
	 * store), so the record is CLAIMED first:
	 *
	 *  1. under the lock: status must be `approved` → `applying` (claimant
	 *     and time recorded). A second, parallel apply sees `applying` and
	 *     gets 409 without running anything.
	 *  2. apply_payload() runs.
	 *  3. under the lock (retried): `applying` → `applied`; on a payload
	 *     error → back to `approved` with the error recorded, or
	 *     `apply_failed` when the payload reports it partially applied.
	 *
	 * If step 3 cannot be written, the answer is a 202-status WP_Error that
	 * says the change WAS applied and the record is left `applying` —
	 * never "nothing was changed" after the payload ran. A stale
	 * `applying` record is flagged needs_attention (public_record()) for
	 * a person to resolve; it is never re-applied automatically.
	 *
	 * @param string $id      Request ID.
	 * @param array  $context Actor context.
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

		return self::run_claimed(
			$id,
			array( 'approved' ),
			'applying',
			'applied',
			'applied_at',
			'payload',
			is_array( $context ) ? $context : array()
		);
	}

	/**
	 * Roll back an applied request — the same claim state machine as
	 * apply_request(), through `rolling_back`. A partially applied
	 * (`apply_failed`) request can be rolled back too.
	 *
	 * @param string $id Request ID.
	 * @return array|WP_Error Request.
	 */
	public static function rollback_request( $id ) {
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		return self::run_claimed( $id, array( 'applied', 'apply_failed' ), 'rolling_back', 'rolled_back', 'rolled_back_at', 'rollback', array() );
	}

	/**
	 * Claim → run → finalise. See apply_request().
	 *
	 * @param string   $id          Request id.
	 * @param string[] $from        Statuses the claim may start from.
	 * @param string   $claim       In-flight status (applying / rolling_back).
	 * @param string   $done        Final status on success.
	 * @param string   $time_field  Timestamp for the final status.
	 * @param string   $field       apply_payload() field (payload / rollback).
	 * @param array    $context     apply_payload() context.
	 * @return array|WP_Error
	 */
	private static function run_claimed( $id, array $from, $claim, $done, $time_field, $field, array $context ) {
		$claimant = isset( $context['actor'] ) ? (string) $context['actor'] : ( function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 ? 'wp-user:' . get_current_user_id() : '' );

		$claimed = self::transition_under_lock(
			$id,
			$from,
			$claim,
			$claim . '_at',
			array( 'claimed_by' => $claimant )
		);
		if ( is_wp_error( $claimed ) ) {
			return $claimed;
		}

		$result = self::apply_payload( $claimed, $field, $context );

		// The payload ran completely; only its own bookkeeping write failed
		// (e.g. bulk publish's applied_ids). Record that at finalise.
		$finalise_extra = array();
		if ( is_wp_error( $result ) && 'apply_result_unrecorded' === $result->get_error_code() ) {
			$data           = $result->get_error_data();
			$finalise_extra = is_array( $data ) && isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? array( 'metadata' => $data['metadata'] ) : array();
			$result         = true;
		}

		if ( is_wp_error( $result ) ) {
			$data    = $result->get_error_data();
			$data    = is_array( $data ) ? $data : array();
			$partial = ! empty( $data['partial'] );
			$extra   = array(
				'last_error' => array(
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
					'at'      => gmdate( 'c' ),
				),
			);
			if ( isset( $data['metadata'] ) && is_array( $data['metadata'] ) ) {
				$extra['metadata'] = $data['metadata'];
			}
			// Nothing ran: back to where it was, retryable. Something ran:
			// apply_failed, for a person (rollback is allowed from there).
			$back = $partial ? 'apply_failed' : $from[0];
			if ( 'rollback' === $field && $partial ) {
				$back = 'apply_failed';
			}
			$final = self::finalise( $id, $claim, $back, $partial ? 'apply_failed_at' : 'last_error_at', $extra );
			if ( is_wp_error( $final ) && $partial ) {
				return self::unconfirmed_error( $field, $id );
			}
			return $result;
		}

		$final = self::finalise( $id, $claim, $done, $time_field, $finalise_extra );
		if ( is_wp_error( $final ) ) {
			return self::unconfirmed_error( $field, $id );
		}

		$public = self::public_record( $final );
		if ( 'payload' === $field ) {
			self::emit_event( 'approval.applied', $public, 'applied', 'medium', __( 'Monitor applied change and keep rollback available.', 'mcpwp' ) );
		} else {
			self::emit_event( 'approval.rolled_back', $public, 'rolled_back', 'low', __( 'Review rollback result.', 'mcpwp' ) );
		}

		return $public;
	}

	/**
	 * Write the end state of a claim, retrying a busy lock a few times.
	 *
	 * @param string $id         Request id.
	 * @param string $claim      In-flight status the record must still hold.
	 * @param string $status     End status.
	 * @param string $time_field Timestamp field.
	 * @param array  $extra      Extra fields (metadata is merged).
	 * @return array|WP_Error
	 */
	private static function finalise( $id, $claim, $status, $time_field, array $extra ) {
		$attempts = (int) apply_filters( 'mcpwp_approval_finalise_attempts', 3 );
		$final    = null;
		for ( $i = 0; $i < max( 1, $attempts ); $i++ ) {
			$final = self::transition_under_lock( $id, array( $claim ), $status, $time_field, $extra );
			if ( ! is_wp_error( $final ) || ! in_array( $final->get_error_code(), array( 'approvals_busy', 'approvals_store_failed' ), true ) ) {
				return $final;
			}
		}
		return $final;
	}

	/**
	 * The change ran but its record could not be finalised.
	 *
	 * @param string $field payload / rollback.
	 * @param string $id    Request id.
	 * @return WP_Error
	 */
	private static function unconfirmed_error( $field, $id ) {
		$what = 'rollback' === $field ? __( 'rolled back', 'mcpwp' ) : __( 'applied', 'mcpwp' );
		return new WP_Error(
			'approval_record_unconfirmed',
			sprintf(
				/* translators: 1: applied/rolled back, 2: request id */
				__( 'The change WAS %1$s, but the approval record %2$s could not be updated (the approval queue was busy). It stays marked in progress and is flagged for attention in Control Room; do not apply it again.', 'mcpwp' ),
				$what,
				$id
			),
			array( 'status' => 202, 'approval_id' => $id )
		);
	}

	/**
	 * What a post_content_update keeps so it can be rolled back (#981
	 * review round 3, BLOCK-2 (2)). A body up to MAX_ROLLBACK_INLINE_BYTES
	 * is copied as before; a larger one is NOT — copying a multi-megabyte
	 * draft into every proposal is what grew the store past memory and
	 * max_allowed_packet. It is saved as a WordPress revision instead and
	 * referenced by id, with its sha256 and a bounded excerpt.
	 *
	 * @param WP_Post $post           Target.
	 * @param string  $before_content Its current body.
	 * @return array|WP_Error
	 */
	private static function rollback_source( $post, $before_content ) {
		$hash = hash( 'sha256', $before_content );
		if ( strlen( $before_content ) <= self::MAX_ROLLBACK_INLINE_BYTES ) {
			return array(
				'before_content' => $before_content,
				'before_hash'    => $hash,
			);
		}

		$revision_id = 0;
		if ( function_exists( 'wp_save_post_revision' ) ) {
			$saved       = wp_save_post_revision( $post->ID );
			$revision_id = is_numeric( $saved ) ? (int) $saved : 0;
		}
		if ( $revision_id < 1 && function_exists( 'wp_get_post_revisions' ) ) {
			// No new revision (the latest already matches, or revisions are
			// throttled): use the latest one if it holds exactly this body.
			foreach ( (array) wp_get_post_revisions( $post->ID, array( 'numberposts' => 1 ) ) as $revision ) {
				if ( $revision instanceof WP_Post && hash( 'sha256', (string) $revision->post_content ) === $hash ) {
					$revision_id = (int) $revision->ID;
				}
			}
		}

		if ( $revision_id < 1 ) {
			return new WP_Error(
				'rollback_unavailable',
				__( 'This post is too large to keep a rollback copy in the approval queue, and WordPress revisions are not available for it. Enable revisions for this post type, or edit the post in wp-admin.', 'mcpwp' ),
				array( 'status' => 409 )
			);
		}

		return array(
			'before_revision_id' => $revision_id,
			'before_hash'        => $hash,
			'before_length'      => strlen( $before_content ),
			'before_excerpt'     => function_exists( 'mb_substr' ) ? mb_substr( $before_content, 0, 1000 ) : substr( $before_content, 0, 1000 ),
		);
	}

	/**
	 * The body a rollback restores: the inline copy, or the referenced
	 * revision — refused cleanly when the revision is gone or no longer
	 * holds the exact body the approval recorded.
	 *
	 * @param array $request Private record.
	 * @param int   $post_id Target.
	 * @return string|WP_Error
	 */
	private static function rollback_content( array $request, $post_id ) {
		$rollback = isset( $request['rollback'] ) && is_array( $request['rollback'] ) ? $request['rollback'] : array();
		if ( array_key_exists( 'before_content', $rollback ) ) {
			return (string) $rollback['before_content'];
		}

		$revision_id = isset( $rollback['before_revision_id'] ) ? (int) $rollback['before_revision_id'] : 0;
		$revision    = $revision_id > 0 ? get_post( $revision_id ) : null;
		$expected    = isset( $rollback['before_hash'] ) ? (string) $rollback['before_hash'] : '';

		if ( ! $revision || (int) $revision->post_parent !== (int) $post_id || 'revision' !== $revision->post_type || hash( 'sha256', (string) $revision->post_content ) !== $expected ) {
			return new WP_Error(
				'rollback_source_missing',
				__( 'The revision this approval would roll back to no longer exists (or has changed). Restore the post from its revision history in wp-admin instead.', 'mcpwp' ),
				array( 'status' => 409 )
			);
		}

		return (string) $revision->post_content;
	}

	/**
	 * 40 words, and never more than 500 characters.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	private static function bounded_excerpt( $content ) {
		$source  = strlen( $content ) > 65536 ? substr( $content, 0, 65536 ) : $content;
		$excerpt = wp_trim_words( wp_strip_all_tags( $source ), 40 );
		return function_exists( 'mb_substr' ) ? mb_substr( $excerpt, 0, 500 ) : substr( $excerpt, 0, 500 );
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
			// Bounded in bytes too: wp_trim_words() counts words, so a body
			// with no spaces came through whole (#981 review round 3).
			'before_excerpt' => self::bounded_excerpt( $before ),
			'after_excerpt'  => self::bounded_excerpt( $after ),
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
		$request = self::transition_under_lock( $id, array( 'pending', 'approved' ), $status, $time_field, array( 'review_note' => sanitize_textarea_field( (string) $note ) ) );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

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

		if ( 'rollback' === $field ) {
			$content = self::rollback_content( $request, $post_id );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
		} else {
			$content = isset( $request['payload']['after_content'] ) ? (string) $request['payload']['after_content'] : '';
		}

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
		unset( $request['payload']['after_content'], $request['rollback']['before_content'], $request['rollback']['before_excerpt'] );

		// The target fingerprint is for drift(), which reads the private
		// record. Publicly it would hand a read-scope key a private page's
		// exact title, status, modification time and a hash that confirms
		// guessed body text offline (#536 round 2, P1-2).
		if ( isset( $request['payload']['target'] ) && is_array( $request['payload']['target'] ) ) {
			$target = $request['payload']['target'];
			$request['payload']['target'] = array_intersect_key( $target, array( 'exists' => 1, 'type' => 1, 'count' => 1 ) );
		}

		// #981 review round 3: an apply/rollback that ran but could not be
		// finalised, or that stalled, is surfaced for a person — never
		// retried automatically.
		$status = isset( $request['status'] ) ? (string) $request['status'] : '';
		if ( 'apply_failed' === $status ) {
			$request['needs_attention'] = 'partially_applied';
		} elseif ( in_array( $status, array( 'applying', 'rolling_back' ), true ) ) {
			$since = isset( $request[ $status . '_at' ] ) ? strtotime( (string) $request[ $status . '_at' ] ) : false;
			/**
			 * Seconds after which an in-flight apply/rollback is reported stale.
			 *
			 * @param int $seconds Default 10 minutes.
			 */
			$stale_after = (int) apply_filters( 'mcpwp_approval_claim_stale_after', 600 );
			if ( false === $since || $since < time() - $stale_after ) {
				$request['needs_attention'] = 'stale_' . $status;
			}
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
	 * Move a request from one of $from to $status, atomically: the status
	 * check and the write happen under the store lock against a fresh read,
	 * so two reviewers (or a reviewer and the expiry sweep) cannot both
	 * "win" the same transition.
	 *
	 * @param string   $id         Request id.
	 * @param string[] $from       Statuses the request may be in.
	 * @param string   $status     New status.
	 * @param string   $time_field Timestamp field to set.
	 * @param array    $extra      Extra fields to set.
	 * @return array|WP_Error The updated private record.
	 */
	private static function transition_under_lock( $id, array $from, $status, $time_field, array $extra = array() ) {
		$id      = sanitize_key( (string) $id );
		$updated = null;

		$result = self::mutate(
			static function ( array $items ) use ( $id, $from, $status, $time_field, $extra, &$updated ) {
				if ( ! isset( $items[ $id ] ) || ! is_array( $items[ $id ] ) ) {
					return self::not_found_error();
				}
				$current = isset( $items[ $id ]['status'] ) ? (string) $items[ $id ]['status'] : '';
				if ( ! in_array( $current, $from, true ) ) {
					if ( in_array( $current, array( 'applying', 'rolling_back' ), true ) ) {
						return new WP_Error( 'approval_in_progress', __( 'This approval request is already being applied or rolled back.', 'mcpwp' ), array( 'status' => 409 ) );
					}
					if ( 'applying' === $status ) {
						return new WP_Error( 'approval_not_ready', __( 'Approval request must be approved before it can be applied.', 'mcpwp' ), array( 'status' => 409 ) );
					}
					if ( 'rolling_back' === $status ) {
						return new WP_Error( 'approval_not_applied', __( 'Only applied approval requests can be rolled back.', 'mcpwp' ), array( 'status' => 409 ) );
					}
					if ( 'applied' === $status ) {
						return new WP_Error( 'approval_not_ready', __( 'Approval request must be approved before it can be applied.', 'mcpwp' ), array( 'status' => 409 ) );
					}
					if ( 'rolled_back' === $status ) {
						return new WP_Error( 'approval_not_applied', __( 'Only applied approval requests can be rolled back.', 'mcpwp' ), array( 'status' => 409 ) );
					}
					return new WP_Error( 'approval_closed', __( 'Approval request is already closed.', 'mcpwp' ), array( 'status' => 409 ) );
				}
				$now                        = gmdate( 'c' );
				$items[ $id ]['status']      = $status;
				$items[ $id ][ $time_field ] = $now;
				$items[ $id ]['updated_at']  = $now;
				foreach ( $extra as $field => $value ) {
					if ( 'metadata' === $field && is_array( $value ) ) {
						$items[ $id ]['metadata'] = array_merge( isset( $items[ $id ]['metadata'] ) && is_array( $items[ $id ]['metadata'] ) ? $items[ $id ]['metadata'] : array(), $value );
						continue;
					}
					$items[ $id ][ $field ] = $value;
				}
				$updated = $items[ $id ];
				return $items;
			}
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $updated;
	}

	/**
	 * Upsert request.
	 *
	 * @param array $request Request.
	 * @return true|WP_Error
	 */
	private static function upsert( $request ) {
		return self::mutate(
			static function ( array $items ) use ( $request ) {
				$items[ $request['id'] ] = $request;
				return $items;
			}
		);
	}

	/**
	 * The one way to change the approvals store (#981 review round 2,
	 * WARN-B). Every writer — create, every status transition, the
	 * apply-result metadata, the staging push, eviction and expiry — used
	 * to read the whole option, change it and write it back with nothing
	 * in between, so two concurrent writers each wrote their own copy and
	 * the later silently erased the earlier (10 parallel proposals, 10
	 * distinct ids reported, 1 stored).
	 *
	 * Now: take a MySQL named lock, re-read the option from the database
	 * (not a cache primed before the lock), apply $change, apply expiry and
	 * eviction, write, release. $change gets the fresh items and returns
	 * the new items, or a WP_Error to write nothing. If the lock cannot be
	 * had within LOCK_TIMEOUT seconds the answer is a 503 — never a success
	 * that was not stored. Re-entrant within one process.
	 *
	 * @param callable $change function( array $items ): array|WP_Error.
	 * @return true|WP_Error
	 */
	public static function mutate( callable $change ) {
		$locked = self::acquire_lock();
		if ( is_wp_error( $locked ) ) {
			return $locked;
		}

		try {
			// Expiry first, so $change never acts on a request that has
			// already expired (e.g. approving a 31-day-old proposal).
			$stored = self::read_fresh();
			$before = self::expire_stale( $stored );
			$items  = $change( $before );
			if ( is_wp_error( $items ) ) {
				return $items;
			}
			if ( ! is_array( $items ) ) {
				return new WP_Error( 'approvals_write_failed', __( 'The approval queue could not be updated.', 'mcpwp' ), array( 'status' => 500 ) );
			}

			$items = self::expire_stale( $items );
			$items = self::evict( $items );

			// #981 review round 3, BLOCK-2 (3): a byte budget for the whole
			// store. A write that ADDS a request is refused once the store
			// would exceed it — no requester is exempt from bytes. Status
			// changes to existing requests always go through, so a full
			// queue can still be worked down.
			$serialized = maybe_serialize( $items );
			if ( count( array_diff_key( $items, $before ) ) > 0 && strlen( (string) $serialized ) > self::store_max_bytes() ) {
				return new WP_Error(
					'approvals_store_full',
					__( 'The approval queue is full (its storage budget is used up). Nothing was queued; review, reject or apply pending requests first.', 'mcpwp' ),
					array( 'status' => 507 )
				);
			}

			// Nothing changed: nothing to write (update_option() would report
			// false for an identical value, which is not a failure).
			if ( $serialized === maybe_serialize( $stored ) ) {
				return true;
			}

			// #981 review round 3, BLOCK-2 (1): update_option() can fail —
			// most visibly a value over MySQL's max_allowed_packet. A failed
			// write must never be reported as stored. Confirm against the
			// database before calling it a failure (update_option() also
			// returns false in a few benign cases).
			if ( ! update_option( self::OPTION_NAME, $items, false ) && maybe_serialize( self::read_fresh() ) !== $serialized ) {
				return new WP_Error(
					'approvals_store_failed',
					__( 'The approval queue could not be saved. Nothing was changed; try again, and if it persists ask an administrator to review the approval queue.', 'mcpwp' ),
					array( 'status' => 503 )
				);
			}
			return true;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Take the store lock (MySQL GET_LOCK, re-entrant in-process).
	 *
	 * @return true|WP_Error
	 */
	private static function acquire_lock() {
		if ( self::$lock_depth > 0 ) {
			self::$lock_depth++;
			return true;
		}

		global $wpdb;
		$got = ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) )
			? $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name(), self::LOCK_TIMEOUT ) )
			: null;

		if ( '1' !== (string) $got ) {
			return new WP_Error(
				'approvals_busy',
				__( 'The approval queue is busy. Nothing was changed; try again in a moment.', 'mcpwp' ),
				array( 'status' => 503, 'retry_after' => 2 )
			);
		}

		self::$lock_depth = 1;
		return true;
	}

	/**
	 * Release one level of the store lock.
	 *
	 * @return void
	 */
	private static function release_lock() {
		if ( self::$lock_depth < 1 ) {
			return;
		}
		self::$lock_depth--;
		if ( 0 === self::$lock_depth ) {
			global $wpdb;
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
		}
	}

	/**
	 * Per-site lock name (the database may be shared by several sites).
	 *
	 * @return string
	 */
	private static function lock_name() {
		global $wpdb;
		$prefix = ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ) ? (string) $wpdb->prefix : '';
		return substr( 'mcpwp_approvals_' . $prefix, 0, 64 );
	}

	/**
	 * Read the option from the database, bypassing any value cached before
	 * the lock was taken.
	 *
	 * @return array
	 */
	private static function read_fresh() {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		$items = get_option( self::OPTION_NAME, array() );
		return is_array( $items ) ? $items : array();
	}

	/**
	 * Only closed records are evicted; a pending or approved request is
	 * someone's unfinished review and stays until it is decided.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	private static function evict( array $items ) {
		uasort(
			$items,
			function ( $a, $b ) {
				return strcmp( isset( $b['created_at'] ) ? $b['created_at'] : '', isset( $a['created_at'] ) ? $a['created_at'] : '' );
			}
		);

		if ( count( $items ) > self::MAX_ITEMS ) {
			$open_states = array( 'pending', 'approved', 'applying', 'rolling_back', 'apply_failed' );
			$open   = array_filter( $items, function ( $item ) use ( $open_states ) { return isset( $item['status'] ) && in_array( $item['status'], $open_states, true ); } );
			$closed = array_filter( $items, function ( $item ) use ( $open_states ) { return ! isset( $item['status'] ) || ! in_array( $item['status'], $open_states, true ); } );
			$closed = array_slice( $closed, 0, max( 0, self::MAX_ITEMS - count( $open ) ), true );
			$items  = $open + $closed;
		}

		return $items;
	}

	/**
	 * Expire pending post_content_update requests older than the TTL
	 * (#981 review round 2, WARN-C). Evaluated lazily: applied to every
	 * read (so a reader never sees a stale proposal as pending) and
	 * persisted on the next write.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	private static function expire_stale( array $items ) {
		/**
		 * Seconds a pending content-change approval stays open.
		 *
		 * @param int $ttl Default 30 days.
		 */
		$ttl = (int) apply_filters( 'mcpwp_approval_pending_ttl', self::PENDING_CONTENT_TTL );
		if ( $ttl < 1 ) {
			return $items;
		}

		$cutoff = time() - $ttl;
		foreach ( $items as $id => $item ) {
			if ( ! is_array( $item ) || ! isset( $item['status'], $item['action'], $item['created_at'] ) ) {
				continue;
			}
			if ( 'pending' !== $item['status'] || 'post_content_update' !== $item['action'] ) {
				continue;
			}
			$created = strtotime( (string) $item['created_at'] );
			if ( false !== $created && $created < $cutoff ) {
				$items[ $id ]['status']     = 'expired';
				$items[ $id ]['expired_at'] = gmdate( 'c', $created + $ttl );
				$items[ $id ]['updated_at'] = $items[ $id ]['expired_at'];
			}
		}
		return $items;
	}

	/**
	 * Byte budget for the whole serialized approvals store (#981 review
	 * round 3, BLOCK-2). Filterable: mcpwp_approval_store_max_bytes.
	 *
	 * @return int
	 */
	public static function store_max_bytes() {
		$bytes = (int) apply_filters( 'mcpwp_approval_store_max_bytes', self::STORE_MAX_BYTES );
		return $bytes > 0 ? $bytes : self::STORE_MAX_BYTES;
	}

	/**
	 * Content-approval limits, filterable (#981 review round 2, WARN-C).
	 *
	 * @return array{per_requester:int,total:int,max_bytes:int,max_bytes_webmcp:int}
	 */
	public static function content_limits() {
		$defaults = array(
			'per_requester'    => self::MAX_PENDING_CONTENT_PER_REQUESTER,
			'total'            => self::MAX_PENDING_CONTENT_TOTAL,
			'max_bytes'        => self::MAX_PROPOSED_CONTENT_BYTES_API,
			'max_bytes_webmcp' => self::MAX_PROPOSED_CONTENT_BYTES,
		);
		/**
		 * Limits on pending post_content_update approvals.
		 *
		 * @param array $limits per_requester, total, max_bytes, max_bytes_webmcp.
		 */
		$limits = apply_filters( 'mcpwp_approval_content_limits', $defaults );
		$limits = is_array( $limits ) ? $limits : array();
		foreach ( $defaults as $key => $value ) {
			$limits[ $key ] = isset( $limits[ $key ] ) && (int) $limits[ $key ] > 0 ? (int) $limits[ $key ] : $value;
		}
		return $limits;
	}

	/**
	 * Whether a requester is exempt from the pending caps: a wp-admin user
	 * (wp-user:<id>, or the trusted admin-AJAX context), or an API key the
	 * caller identified as unrestricted (admin role / all categories) — each
	 * of those could make the change directly anyway (#981 review round 2,
	 * WARN-C). Category-restricted keys and WebMCP users are capped.
	 *
	 * @param string $requester requested_by_key_id.
	 * @param array  $args      Creation args or stored metadata ('requester_unrestricted' / 'cap_exempt').
	 * @return bool
	 */
	private static function requester_is_exempt_from_caps( $requester, array $args ) {
		if ( 0 === strpos( (string) $requester, 'webmcp:' ) ) {
			return false;
		}
		if ( 0 === strpos( (string) $requester, 'wp-user:' ) ) {
			return true;
		}
		return ! empty( $args['requester_unrestricted'] ) || ! empty( $args['cap_exempt'] );
	}

	/**
	 * Get all requests (with lazy expiry applied).
	 *
	 * @return array Requests.
	 */
	private static function get_all() {
		$items = get_option( self::OPTION_NAME, array() );
		return self::expire_stale( is_array( $items ) ? $items : array() );
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
