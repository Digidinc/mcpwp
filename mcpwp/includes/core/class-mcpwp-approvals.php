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
	 * The option the approvals store lived in before #1042. Read only by the
	 * one-time migration into the table (Mcpwp_Activator::
	 * migrate_approvals_to_table()), which then renames it to
	 * LEGACY_BACKUP_OPTION. Nothing else reads or writes it.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'mcpwp_approval_requests';

	/**
	 * Table suffix (after $wpdb->prefix) holding one row per approval (#1042).
	 *
	 * @var string
	 */
	const TABLE = 'mcpwp_approvals';

	/**
	 * Set once every record of the legacy option is in the table.
	 *
	 * @var string
	 */
	const MIGRATED_FLAG = 'mcpwp_approvals_table_migrated';

	/**
	 * The legacy option is renamed to this (autoload off) after the copy is
	 * verified, never deleted in the same release, so a downgrade or a bad
	 * migration can be recovered by hand.
	 *
	 * @var string
	 */
	const LEGACY_BACKUP_OPTION = 'mcpwp_approval_requests_pre_3149';

	/**
	 * Schema of the table this code expects; below it,
	 * Mcpwp_Activator::migrate_approvals_to_table() dbDelta()s the table and
	 * backfills the new columns before the store answers again.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 2;

	/**
	 * Option holding the table's schema version.
	 *
	 * @var string
	 */
	const SCHEMA_OPTION = 'mcpwp_approvals_schema_version';

	/**
	 * Ids of legacy records the migration could not copy (not an array, a
	 * bad id, or not encodable); they stay in LEGACY_BACKUP_OPTION.
	 *
	 * @var string
	 */
	const SKIPPED_OPTION = 'mcpwp_approvals_migration_skipped';

	/**
	 * Maximum OPEN requests (open_statuses()) the site holds from capped
	 * principals. A capped create past it is refused (429); closed history
	 * does not count against it, and an exempt principal (a wp-admin user,
	 * an unrestricted key — requester_is_exempt_from_caps()) is never
	 * refused by count.
	 *
	 * @var int
	 */
	const MAX_ITEMS = 200;

	/**
	 * Pending post_content_update requests one requester may hold (#981
	 * review WARN-2). Applies to category-restricted API keys and WebMCP
	 * users; an unrestricted key or a wp-admin user could make the change
	 * directly and is exempt (#981 review round 2, WARN-C). Filterable: see
	 * content_limits().
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
	 * protection for every reader of the record, not a content policy.
	 */
	const MAX_PROPOSED_CONTENT_BYTES_API = 1048576;

	/**
	 * A pending post_content_update older than this expires (status
	 * 'expired'), seconds. Filterable: mcpwp_approval_pending_ttl.
	 */
	const PENDING_CONTENT_TTL = 2592000;

	/**
	 * A post body larger than this is not copied into the approval as its
	 * rollback source; a revision is referenced instead, bytes.
	 */
	const MAX_ROLLBACK_INLINE_BYTES = 131072;

	/**
	 * Bytes of open requests one capped requester may hold (#981 review
	 * round 4). It was 1/20 of the old 8 MB whole-store budget; that budget
	 * is gone with the option (#1042), the per-requester share stays.
	 * Filterable through mcpwp_approval_content_limits ('requester_bytes').
	 */
	const REQUESTER_BYTE_QUOTA = 419430;

	/**
	 * How long an applied request keeps its rollback bodies (its rollback
	 * window), seconds. After it the record is compacted.
	 */
	const CLOSED_RETENTION = 604800;

	/**
	 * Days a closed record is kept before the daily retention cron deletes
	 * it (each deletion audited first). Filterable:
	 * mcpwp_approvals_closed_retention_days.
	 */
	const CLOSED_RETENTION_DAYS = 90;

	/**
	 * Daily cron hook for retention and compaction.
	 */
	const RETENTION_CRON_HOOK = 'mcpwp_approvals_retention';

	/**
	 * Rows the retention cron handles per batch.
	 */
	const RETENTION_BATCH = 500;

	/**
	 * Rows list_requests() fetches per batch while filling a page of
	 * visible records.
	 */
	const LIST_BATCH = 100;

	/**
	 * Optimistic-concurrency attempts before update_one() answers
	 * approvals_busy.
	 */
	const UPDATE_ATTEMPTS = 5;

	/**
	 * Seconds to wait for the create lock before refusing.
	 */
	const LOCK_TIMEOUT = 5;

	/**
	 * Whether this request already ran the expiry sweep.
	 *
	 * @var bool
	 */
	private static $expiry_swept = false;

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
		// proposed body (plus a diff) in its approval record. Without
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

		// Count and insert under the create lock, so parallel proposals can
		// neither overshoot the caps nor slip past one another (#1042: the
		// only operation that takes a lock; every other write is per row).
		$stored = self::insert_new(
			$request,
			static function () use ( $request, $requester, $capped, $limits ) {
				if ( ! $capped ) {
					return true;
				}
				// A request whose requester was exempt when it was created (see
				// requester_is_exempt_from_caps(); the cap_exempt column, written
				// by row_from_record()) never counts.
				$not_exempt   = array( 'cap_exempt =', 0 );
				$pending_mine = self::count_where(
					array(
						array( 'status =', 'pending' ),
						array( 'action =', 'post_content_update' ),
						array( 'requested_by_key_id =', $requester ),
						$not_exempt,
					)
				);
				if ( is_wp_error( $pending_mine ) ) {
					return $pending_mine;
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
				// #981 review round 4: a byte quota per capped requester, so
				// one principal cannot hold an outsized share of the queue.
				$held = self::open_bytes_of( $requester );
				if ( is_wp_error( $held ) ) {
					return $held;
				}
				$mine_bytes = (int) self::quota_size( $request ) + $held;
				if ( $mine_bytes > self::requester_byte_quota() ) {
					return new WP_Error(
						'requester_store_quota',
						sprintf(
							/* translators: %d: quota in KB */
							__( 'This requester\'s open approval requests would exceed its share of the approval queue (%d KB). Have some reviewed first, or propose a smaller change.', 'mcpwp' ),
							(int) ( self::requester_byte_quota() / 1024 )
						),
						array( 'status' => 429 )
					);
				}
				$pending_all = self::count_where(
					array(
						array( 'status =', 'pending' ),
						array( 'action =', 'post_content_update' ),
						$not_exempt,
					)
				);
				if ( is_wp_error( $pending_all ) ) {
					return $pending_all;
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
				return true;
			},
			! $capped
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

		// A flood of gated calls must not fill the queue other people review
		// — from one principal, or from many minted for the purpose. Counted
		// under the create lock, so parallel requests cannot all pass.
		// Exempt principals (a wp-admin user, an unrestricted key) are never
		// refused by a SITE-wide count (#1042 review round 1, B1); the
		// per-principal cap below still binds everyone.
		$exempt = self::requester_is_exempt_from_caps( $requester, is_array( $args ) ? $args : array() );
		$stored = self::insert_new(
			$request,
			static function () use ( $requester, $exempt ) {
				$pending_mine = self::count_where(
					array(
						array( 'status =', 'pending' ),
						array( 'requested_by_key_id =', $requester ),
					)
				);
				if ( is_wp_error( $pending_mine ) ) {
					return $pending_mine;
				}
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
				if ( $exempt ) {
					return true;
				}
				$pending_api = self::count_where(
					array(
						array( 'status =', 'pending' ),
						array( 'requested_by_key_id NOT LIKE', self::esc_like( 'wp-user:' ) . '%' ),
					)
				);
				if ( is_wp_error( $pending_api ) ) {
					return $pending_api;
				}
				if ( $pending_api >= Mcpwp_Destructive_Gate::MAX_PENDING_TOTAL ) {
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
				return true;
			},
			$exempt
		);
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

		$stored = self::insert_new( $request, null, self::requester_is_exempt_from_caps( $request['requested_by_key_id'], is_array( $args ) ? $args : array() ) );
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
	 * @return array|WP_Error Requests; a WP_Error (503) when the store cannot
	 *                        be read — never an empty list that would look
	 *                        like "no approvals".
	 */
	public static function list_requests( $status = '', $limit = 50, $reveal_non_public = false, $order = 'date', $category_allowed = null ) {
		$status = sanitize_key( (string) $status );
		$limit  = min( 100, max( 1, (int) $limit ) );

		$ready = self::ensure_store();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		self::maybe_sweep_expired();

		// Status filter, order and paging run in SQL (#1042). Visibility does
		// NOT: is_approval_visible_to() stays the one chokepoint (a second,
		// SQL implementation of the category rules is how #1025-class leaks
		// happen). Because it runs after the fetch, rows are read in batches
		// until $limit VISIBLE records are collected or rows run out — a
		// restricted key still gets full pages, and a hidden item never
		// displaces a visible one.
		//
		// Ties on created_at keep insertion order (seq ascending), the order
		// the old option held them in — so a migrated queue lists exactly as
		// it did before.
		$order_by = 'priority' === sanitize_key( (string) $order )
			? 'triage_rank DESC, created_at DESC, seq ASC'
			: 'created_at DESC, seq ASC';
		$where    = '' !== $status ? array( array( 'status =', $status ) ) : array();

		$items  = array();
		$seen   = array();
		$offset = 0;
		$batch  = self::LIST_BATCH;
		while ( count( $items ) < $limit ) {
			$rows = self::select_rows( $where, $order_by, $batch, $offset );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( $rows as $row ) {
				// A row inserted above the window between two batches shifts
				// the next one down by one: never list the same record twice.
				if ( isset( $seen[ $row['id'] ] ) ) {
					continue;
				}
				$seen[ $row['id'] ] = true;

				$item = self::expire_stale_one( $row['record'] );
				if ( '' !== $status && ( ! isset( $item['status'] ) || $status !== $item['status'] ) ) {
					continue;
				}
				if ( is_callable( $category_allowed ) && ! self::is_approval_visible_to( $item, $category_allowed ) ) {
					continue;
				}
				$items[] = $item;
				if ( count( $items ) >= $limit ) {
					break;
				}
			}
			if ( count( $rows ) < $batch ) {
				break;
			}
			$offset += $batch;
		}

		$records = array_map( array( __CLASS__, 'public_record' ), $items );

		if ( ! $reveal_non_public ) {
			$records = array_map( array( __CLASS__, 'redact_source_for_unprivileged' ), $records );
		}

		return $records;
	}

	/**
	 * Most closed rows count_by_status() reads for a caller whose visibility
	 * must be checked per record; past it the closed counts are marked
	 * truncated rather than silently short.
	 */
	const COUNT_CLOSED_SCAN_LIMIT = 5000;

	/**
	 * Approvals per status (#1042 review round 1, W4) — every consumer of
	 * approval counts (/site-state, content coherence, Control Room) reads
	 * this, never a count of one listed page.
	 *
	 * With no $category_allowed (an unrestricted caller): exact, one SQL
	 * `COUNT(*) … GROUP BY status`. With one: every open-status row is
	 * checked through is_approval_visible_to() (the one visibility
	 * chokepoint — never a second SQL version of it), and closed rows up to
	 * COUNT_CLOSED_SCAN_LIMIT; past that the answer says truncated => true.
	 *
	 * @param callable|null $category_allowed See list_requests().
	 * @return array{counts:array<string,int>,truncated:bool}|WP_Error
	 */
	public static function count_by_status( $category_allowed = null ) {
		$ready = self::ensure_store();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		self::maybe_sweep_expired();

		global $wpdb;
		$counts = array();

		if ( ! is_callable( $category_allowed ) ) {
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is prefix + constant.
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				return self::store_read_failed_error();
			}
			foreach ( $rows as $row ) {
				$counts[ (string) $row['status'] ] = (int) $row['n'];
			}
			return array(
				'counts'    => $counts,
				'truncated' => false,
			);
		}

		$truncated = false;
		foreach ( array( 'open', 'closed' ) as $pass ) {
			$scanned = 0;
			$after   = '';
			while ( true ) {
				$rows = self::select_rows(
					array(
						array( 'open' === $pass ? 'status IN' : 'status NOT IN', self::open_statuses() ),
						array( 'id >', $after ),
					),
					'id ASC',
					self::LIST_BATCH
				);
				if ( is_wp_error( $rows ) ) {
					return $rows;
				}
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					$after = $row['id'];
					$item  = self::expire_stale_one( $row['record'] );
					if ( ! self::is_approval_visible_to( $item, $category_allowed ) ) {
						continue;
					}
					$status            = isset( $item['status'] ) ? (string) $item['status'] : '';
					$counts[ $status ] = ( isset( $counts[ $status ] ) ? $counts[ $status ] : 0 ) + 1;
				}
				$scanned += count( $rows );
				if ( 'closed' === $pass && $scanned >= self::COUNT_CLOSED_SCAN_LIMIT ) {
					$more = self::select_rows(
						array(
							array( 'status NOT IN', self::open_statuses() ),
							array( 'id >', $after ),
						),
						'id ASC',
						1
					);
					$truncated = is_wp_error( $more ) || ! empty( $more );
					break;
				}
			}
		}

		return array(
			'counts'    => $counts,
			'truncated' => $truncated,
		);
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
	 * Store a validated Jev triage result on an approval record. Never
	 * called from, and never read by, approve_request()/reject_request()/
	 * apply_request()/apply_payload() — triage is display/sort metadata
	 * only, not a gate (see class docblock on Mcpwp_Jev).
	 *
	 * A record that fails sanitize_triage() is dropped: nothing is stored,
	 * and the existing (or absent) triage on the record is left untouched.
	 *
	 * Merges ONLY the `triage` key onto the row as it is right now, through
	 * update_one()'s optimistic read-modify-write — an approve/apply/reject
	 * landing on this id at the same moment is retried onto, never reverted.
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
		if ( '' === $id ) {
			return false;
		}

		$result = self::update_one(
			$id,
			static function ( array $record ) use ( $sanitized ) {
				$record['triage'] = $sanitized;
				return $record;
			}
		);

		return ! is_wp_error( $result );
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
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		return self::public_record( $request );
	}

	/**
	 * Who requested an approval, for the REST controller's self-approval
	 * check (it needs the internal field, which the public record keeps too,
	 * but a failed read must not look like a missing record there).
	 *
	 * @param string $id Request ID.
	 * @return string|null|WP_Error The requester ('' when the record names
	 *                              none), null when there is no such record,
	 *                              WP_Error when the store could not be read.
	 */
	public static function requester_of( $id ) {
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return 'approval_not_found' === $request->get_error_code() ? null : $request;
		}

		return isset( $request['requested_by_key_id'] ) ? (string) $request['requested_by_key_id'] : '';
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

		return self::transition_request( $id, 'approved', 'approved_at', $note, self::actor_from( $context ) );
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
	public static function reject_request( $id, $note = '', $context = array() ) {
		return self::transition_request( $id, 'rejected', 'rejected_at', $note, self::actor_from( $context ) );
	}

	/**
	 * Apply an approved request.
	 *
	 * A claim state machine (#981 review round 3, BLOCK-1) — the payload
	 * runs outside any write (it can be slow, and may itself write the
	 * store), so the record is CLAIMED first:
	 *
	 *  1. one conditional write: status must be `approved` → `applying` (claimant
	 *     and time recorded). A second, parallel apply sees `applying` and
	 *     gets 409 without running anything.
	 *  2. apply_payload() runs.
	 *  3. one conditional write (retried): `applying` → `applied`; on a payload
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
	public static function rollback_request( $id, $context = array() ) {
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		return self::run_claimed( $id, array( 'applied', 'apply_failed' ), 'rolling_back', 'rolled_back', 'rolled_back_at', 'rollback', is_array( $context ) ? $context : array() );
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

		$claimed = self::transition_atomically(
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
				return self::unconfirmed_error( $field, $id, $final );
			}
			return $result;
		}

		// Who applied / rolled back, kept separately from claimed_by (which
		// the next claim overwrites) for the audit trail (#981 round 5).
		$finalise_extra[ 'payload' === $field ? 'applied_by' : 'rolled_back_by' ] = $claimant;
		$final = self::finalise( $id, $claim, $done, $time_field, $finalise_extra );
		if ( is_wp_error( $final ) ) {
			return self::unconfirmed_error( $field, $id, $final );
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
	 * Write the end state of a claim, retrying a busy store a few times.
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
			$final = self::transition_atomically( $id, array( $claim ), $status, $time_field, $extra );
			if ( ! is_wp_error( $final ) || ! in_array( $final->get_error_code(), array( 'approvals_busy', 'approvals_store_failed' ), true ) ) {
				return $final;
			}
		}
		return $final;
	}

	/**
	 * The change ran but its record could not be finalised.
	 *
	 * @param string        $field payload / rollback.
	 * @param string        $id    Request id.
	 * @param WP_Error|null $cause The finalise error, for an accurate message.
	 * @return WP_Error
	 */
	private static function unconfirmed_error( $field, $id, $cause = null ) {
		$what = 'rollback' === $field ? __( 'rolled back', 'mcpwp' ) : __( 'applied', 'mcpwp' );
		$code = $cause instanceof WP_Error ? $cause->get_error_code() : '';
		if ( 'approvals_busy' === $code ) {
			/* translators: 1: applied/rolled back, 2: request id */
			$format = __( 'The change WAS %1$s, but the approval record %2$s could not be updated: other writes to it kept landing first. It stays marked in progress and is flagged for attention in Control Room; do not apply it again.', 'mcpwp' );
		} else {
			/* translators: 1: applied/rolled back, 2: request id */
			$format = __( 'The change WAS %1$s, but the approval record %2$s could not be saved (a database write failed). It stays marked in progress and is flagged for attention in Control Room; do not apply it again.', 'mcpwp' );
		}
		return new WP_Error(
			'approval_record_unconfirmed',
			sprintf( $format, $what, $id ),
			array( 'status' => 202, 'approval_id' => $id, 'cause' => $code )
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

		// #981 review round 4, WARN-2: a revision kept only briefly would be
		// pruned by the very apply that needs it for rollback. Unlimited
		// (-1) or at least 3 kept, or no large-post proposal at all.
		$keep = function_exists( 'wp_revisions_to_keep' ) ? (int) wp_revisions_to_keep( $post ) : -1;
		if ( -1 !== $keep && $keep < 3 ) {
			return new WP_Error(
				'rollback_unavailable',
				__( 'This post is too large to keep a rollback copy in the approval queue, and WordPress keeps too few revisions of it (fewer than 3) for a revision to survive the change. Raise WP_POST_REVISIONS, or edit the post in wp-admin.', 'mcpwp' ),
				array( 'status' => 409 )
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
	private static function transition_request( $id, $status, $time_field, $note, $actor = '' ) {
		$extra = array( 'review_note' => sanitize_textarea_field( (string) $note ) );
		if ( '' !== $actor ) {
			// Who decided it — kept on the record and in the audit row
			// written if the record is ever removed (#981 review round 5).
			$extra[ 'approved' === $status ? 'approved_by' : 'rejected_by' ] = $actor;
		}
		$request = self::transition_atomically( $id, array( 'pending', 'approved' ), $status, $time_field, $extra );
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
			// Never apply a change that could not be rolled back: the
			// referenced revision must still be there (#981 review round 4).
			if ( isset( $request['rollback']['before_revision_id'] ) ) {
				$source = self::rollback_content( $request, $post_id );
				if ( is_wp_error( $source ) ) {
					return new WP_Error(
						'rollback_source_missing',
						__( 'The revision this change would be rolled back to no longer exists, so it was not applied. Reject this request and propose the change again.', 'mcpwp' ),
						array( 'status' => 409 )
					);
				}
			}
			$content = isset( $request['payload']['after_content'] ) ? (string) $request['payload']['after_content'] : '';
		}

		// #1032 round 3: re-run the Template Library reference scan on the
		// content this write is ABOUT TO PERSIST, against the REQUESTER's
		// own permissions (the closure the REST controller built from
		// requested_by_key_id) — never the admin applying it, whose own
		// category access would otherwise launder a reference the
		// requester was never allowed to introduce. Pre-existing refs
		// already on the post right now (before THIS write lands) are
		// exempt, same "don't re-judge what was already there" rule
		// check_request_template_refs() applies to every other write path.
		if ( class_exists( 'Mcpwp_Elementor_Template_Refs' ) ) {
			$template_ref_gate = is_array( $context ) && isset( $context['template_ref_gate'] ) && is_callable( $context['template_ref_gate'] )
				? $context['template_ref_gate']
				: null;

			if ( $template_ref_gate ) {
				$preexisting_ids = Mcpwp_Elementor_Template_Refs::ids_in_text( (string) $post->post_content );
				$found_ids       = Mcpwp_Elementor_Template_Refs::ids_in_text( $content );
				$new_ids         = array_values( array_diff( $found_ids, $preexisting_ids ) );

				if ( ! empty( $new_ids ) ) {
					$gate_result = call_user_func( $template_ref_gate, $new_ids );
					if ( is_wp_error( $gate_result ) ) {
						return $gate_result;
					}
				}
			}
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
		$id = sanitize_key( (string) $id );
		if ( '' === $id ) {
			return self::not_found_error();
		}

		$ready = self::ensure_store();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$row = self::read_row( $id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( null === $row || empty( $row['record'] ) ) {
			return self::not_found_error();
		}

		// Lazy expiry, as every read applied it before (#981 round 2): a
		// reader never sees a stale proposal as pending. update_one()
		// persists it on the next write.
		return self::expire_stale_one( $row['record'] );
	}

	/**
	 * Remove private payload content before returning a record.
	 *
	 * @param array $request Request.
	 * @return array Public request.
	 */
	private static function public_record( $request ) {
		// #981 review round 5: whether rollback can still work from what the
		// record keeps (compacted away / revision gone → false). Computed
		// from the private record, before its bodies are stripped below.
		if ( isset( $request['status'] ) && in_array( $request['status'], array( 'applied', 'apply_failed' ), true ) ) {
			$request['rollback_available'] = self::rollback_available( $request );
		}

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
	 * The acting principal: context actor, else the signed-in wp-admin user.
	 *
	 * @param mixed $context Context.
	 * @return string
	 */
	private static function actor_from( $context ) {
		if ( is_array( $context ) && ! empty( $context['actor'] ) ) {
			return (string) $context['actor'];
		}
		return function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 ? 'wp-user:' . get_current_user_id() : '';
	}

	/**
	 * Move a request from one of $from to $status, atomically: the status
	 * check and the write happen in one optimistic read-modify-write of
	 * the row (update_one()), so two reviewers (or a reviewer and the
	 * expiry sweep, or two parallel applies) cannot both "win" the same
	 * transition — the loser's conditional UPDATE matches no row, it
	 * re-reads, and the status check refuses it.
	 *
	 * @param string   $id         Request id.
	 * @param string[] $from       Statuses the request may be in.
	 * @param string   $status     New status.
	 * @param string   $time_field Timestamp field to set.
	 * @param array    $extra      Extra fields to set.
	 * @return array|WP_Error The updated private record.
	 */
	private static function transition_atomically( $id, array $from, $status, $time_field, array $extra = array() ) {
		return self::update_one(
			sanitize_key( (string) $id ),
			static function ( array $record ) use ( $from, $status, $time_field, $extra ) {
				$current = isset( $record['status'] ) ? (string) $record['status'] : '';
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
				$now                   = gmdate( 'c' );
				$record['status']      = $status;
				$record[ $time_field ] = $now;
				$record['updated_at']  = $now;
				foreach ( $extra as $field => $value ) {
					if ( 'metadata' === $field && is_array( $value ) ) {
						$record['metadata'] = array_merge( isset( $record['metadata'] ) && is_array( $record['metadata'] ) ? $record['metadata'] : array(), $value );
						continue;
					}
					$record[ $field ] = $value;
				}
				return $record;
			}
		);
	}

	/**
	 * Change one approval record (#1042) — the public door to update_one()
	 * for code outside this class that records something on an existing
	 * approval (Mcpwp_Content_Guardrails' apply result, the staging push).
	 * $change receives the record as stored right now and returns the new
	 * record, or a WP_Error to write nothing. It may be called more than
	 * once (it is re-run on fresh data after a lost race), so it must not
	 * have side effects.
	 *
	 * @param string   $id     Approval id.
	 * @param callable $change function( array $record ): array|WP_Error.
	 * @return array|WP_Error The record as written.
	 */
	public static function update_request( $id, callable $change ) {
		return self::update_one( sanitize_key( (string) $id ), $change );
	}

	/**
	 * Store a new approval record built outside this class (the staging
	 * push, Mcpwp_REST_Staging), through the same create path — create
	 * lock, open-request cap, verified INSERT — as every create_*_request().
	 *
	 * @param array $record Complete record; must carry an 'id'.
	 * @param array $args   {requester_unrestricted?: bool} — whether the
	 *                      requester is exempt from the open-request cap.
	 * @return true|WP_Error
	 */
	public static function insert_request( array $record, array $args = array() ) {
		if ( empty( $record['id'] ) || sanitize_key( (string) $record['id'] ) !== (string) $record['id'] ) {
			return new WP_Error( 'approvals_invalid_record', __( 'The approval request has no valid id. Nothing was stored.', 'mcpwp' ), array( 'status' => 400 ) );
		}
		$requester = isset( $record['requested_by_key_id'] ) ? (string) $record['requested_by_key_id'] : '';
		return self::insert_new( $record, null, self::requester_is_exempt_from_caps( $requester, $args ) );
	}

	/**
	 * Copy the legacy option's records into the table — for
	 * Mcpwp_Activator::migrate_approvals_to_table() only. INSERT IGNORE by
	 * id, so a crashed half-migration simply re-runs and an id already in
	 * the table is never overwritten; afterwards the table must hold at
	 * least as many rows as there were copyable records, or this fails and
	 * the migration is not marked done. Records go in in the option's order,
	 * so `seq` keeps the order they listed in.
	 *
	 * A record that is not an array, has an unusable id, or cannot be
	 * encoded (e.g. nested deeper than JSON allows) is skipped and its id
	 * returned — one bad record must not block every approval on the site
	 * forever (#1042 review round 1, W2). It stays in the renamed option.
	 *
	 * @param array $items id => record, exactly as the option held them.
	 * @return array{records:int,skipped:string[]}|WP_Error
	 */
	public static function import_legacy_items( array $items ) {
		global $wpdb;
		$table = self::table_name();

		// The table must answer before anything is copied into it.
		$before = self::count_where( array() );
		if ( is_wp_error( $before ) ) {
			return $before;
		}

		$records = 0;
		$skipped = array();
		foreach ( $items as $key => $record ) {
			$id = (string) $key;
			if ( ! is_array( $record ) || '' === $id || strlen( $id ) > 64 ) {
				$skipped[] = substr( $id, 0, 64 );
				continue;
			}
			$fields = self::row_from_record( $record );
			if ( is_wp_error( $fields ) ) {
				$skipped[] = $id;
				continue;
			}
			$records++;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- table name is prefix + constant.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (id, status, action, requested_by_key_id, created_at, updated_at, triage_rank, cap_exempt, quota_bytes, version, record) VALUES (%s, %s, %s, %s, %s, %s, %d, %d, %d, 0, %s)",
					$id,
					$fields['status'],
					$fields['action'],
					$fields['requested_by_key_id'],
					$fields['created_at'],
					$fields['updated_at'],
					$fields['triage_rank'],
					$fields['cap_exempt'],
					$fields['quota_bytes'],
					$fields['record']
				)
			);
			if ( false === $inserted ) {
				return self::store_failed_error();
			}
		}

		$after = self::count_where( array() );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		if ( $after < $records ) {
			return self::store_failed_error();
		}
		return array(
			'records' => $records,
			'skipped' => $skipped,
		);
	}

	/**
	 * Recompute the derived columns of every row (schema upgrades that add
	 * a column, e.g. cap_exempt / quota_bytes in schema 2) — for
	 * Mcpwp_Activator::migrate_approvals_to_table() only, under its lock.
	 * Column-only: the record and its version are untouched.
	 *
	 * @return true|WP_Error
	 */
	public static function backfill_derived_columns() {
		global $wpdb;
		$table = self::table_name();
		$after = '';
		while ( true ) {
			$rows = self::select_rows( array( array( 'id >', $after ) ), 'id ASC', self::RETENTION_BATCH );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			if ( empty( $rows ) ) {
				return true;
			}
			foreach ( $rows as $row ) {
				$after  = $row['id'];
				$fields = self::row_from_record( $row['record'] );
				if ( is_wp_error( $fields ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is prefix + constant.
				$done = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET cap_exempt = %d, quota_bytes = %d, updated_at = %s WHERE id = %s", $fields['cap_exempt'], $fields['quota_bytes'], $fields['updated_at'], $row['id'] ) );
				if ( false === $done ) {
					return self::store_failed_error();
				}
			}
		}
	}

	// ── Store (#1042): one row per approval ──────────────────────────────
	//
	// {prefix}mcpwp_approvals holds each record whole, JSON-encoded, in
	// `record`, exactly as the old option held it — every reader keeps
	// working on the same array. status, action, requested_by_key_id,
	// created_at, updated_at and triage_rank are copies used only for
	// filtering, ordering and counting, written by row_from_record() alone
	// so they cannot drift from the record. `version` is the optimistic-
	// concurrency counter; `seq` is insertion order (ties on created_at list
	// in it, as the option's array order did).

	/**
	 * Prefixed table name.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * The store is readable: the one-time copy out of the legacy option has
	 * finished. While another request is running that copy, every read and
	 * write answers approvals_busy (503) — there is no dual-read path; the
	 * copy is bounded (the option never held much more than MAX_ITEMS
	 * records) and finishes inside one request.
	 *
	 * @return true|WP_Error
	 */
	private static function ensure_store() {
		if ( get_option( self::MIGRATED_FLAG ) && (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA_VERSION ) {
			return true;
		}
		if ( ! class_exists( 'Mcpwp_Activator' ) ) {
			require_once dirname( __DIR__ ) . '/class-mcpwp-activator.php';
		}
		return Mcpwp_Activator::migrate_approvals_to_table();
	}

	/**
	 * approvals_busy — the one "try again" answer (503, retry_after 2).
	 *
	 * @return WP_Error
	 */
	public static function busy_error() {
		return new WP_Error(
			'approvals_busy',
			__( 'The approval queue is busy. Nothing was changed; try again in a moment.', 'mcpwp' ),
			array( 'status' => 503, 'retry_after' => 2 )
		);
	}

	/**
	 * approvals_store_failed — a database WRITE failed (503).
	 *
	 * @return WP_Error
	 */
	public static function store_failed_error() {
		return new WP_Error(
			'approvals_store_failed',
			__( 'The approval queue could not be saved. Nothing was changed; try again, and if it persists ask an administrator to review the approval queue.', 'mcpwp' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * approvals_store_failed — a database READ failed (503). Same code as a
	 * failed write (callers retry both the same way), its own message: a
	 * read changes nothing and saves nothing.
	 *
	 * @return WP_Error
	 */
	public static function store_read_failed_error() {
		return new WP_Error(
			'approvals_store_failed',
			__( 'The approval queue could not be read. Try again in a moment; if it persists, ask an administrator to check the database.', 'mcpwp' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * approvals_store_failed for one row whose stored record does not
	 * decode. Carries reason=corrupt_record so a caller that cannot prove the
	 * record is visible to its principal answers not-found instead
	 * (Mcpwp_REST_Approvals::resolve_visible_approval()).
	 *
	 * @return WP_Error
	 */
	public static function corrupt_record_error() {
		return new WP_Error(
			'approvals_store_failed',
			__( 'This approval request is stored damaged and cannot be read. Ask an administrator to check the approvals table.', 'mcpwp' ),
			array( 'status' => 503, 'reason' => 'corrupt_record' )
		);
	}

	/**
	 * Read one row. null when there is no such row; WP_Error when the read
	 * itself failed ($wpdb->get_row() answers null for both, so last_error
	 * decides — "couldn't check" must never read as "not there"). A row
	 * whose record does not decode is a store failure too.
	 *
	 * @param string $id Approval id.
	 * @return array{version:int,record:array}|null|WP_Error
	 */
	private static function read_row( $id ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is prefix + constant; a cached read would defeat the version check.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT version, record FROM {$table} WHERE id = %s", $id ), ARRAY_A );
		if ( null === $row ) {
			return '' !== (string) $wpdb->last_error ? self::store_read_failed_error() : null;
		}
		$record = json_decode( (string) $row['record'], true );
		if ( ! is_array( $record ) ) {
			return self::corrupt_record_error();
		}
		return array(
			'version' => (int) $row['version'],
			'record'  => $record,
		);
	}

	/**
	 * The one way to change an existing approval (#1042). Reads the row
	 * (record + version), applies lazy expiry, calls $change, compacts a
	 * record that is due, and writes with
	 * `UPDATE … SET …, version = version + 1 WHERE id = %s AND version = %d`.
	 * No row matched means another writer won since the read: the whole
	 * read-modify-write is retried on fresh data, up to UPDATE_ATTEMPTS
	 * times, then approvals_busy (503). A $wpdb failure is
	 * approvals_store_failed (503). A success is never reported without the
	 * row having been written.
	 *
	 * @param string   $id     Approval id (already sanitized).
	 * @param callable $change function( array $record ): array|WP_Error.
	 * @return array|WP_Error The record as written.
	 */
	private static function update_one( $id, callable $change ) {
		if ( '' === (string) $id ) {
			return self::not_found_error();
		}
		$ready = self::ensure_store();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		global $wpdb;
		$table = self::table_name();

		for ( $attempt = 0; $attempt < self::UPDATE_ATTEMPTS; $attempt++ ) {
			$row = self::read_row( $id );
			if ( is_wp_error( $row ) ) {
				return $row;
			}
			if ( null === $row ) {
				return self::not_found_error();
			}

			// Expiry first, so $change never acts on a request that has
			// already expired (e.g. approving a 31-day-old proposal).
			$record = $change( self::expire_stale_one( $row['record'] ) );
			if ( is_wp_error( $record ) ) {
				return $record;
			}
			if ( ! is_array( $record ) ) {
				return new WP_Error( 'approvals_write_failed', __( 'The approval queue could not be updated.', 'mcpwp' ), array( 'status' => 500 ) );
			}
			$record = self::compact_if_due( $record );

			if ( $record === $row['record'] ) {
				return $record; // Nothing to write.
			}

			$fields = self::row_from_record( $record );
			if ( is_wp_error( $fields ) ) {
				return $fields;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional (version-checked) write; table name is prefix + constant.
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, action = %s, requested_by_key_id = %s, created_at = %s, updated_at = %s, triage_rank = %d, cap_exempt = %d, quota_bytes = %d, record = %s, version = version + 1 WHERE id = %s AND version = %d",
					$fields['status'],
					$fields['action'],
					$fields['requested_by_key_id'],
					$fields['created_at'],
					$fields['updated_at'],
					$fields['triage_rank'],
					$fields['cap_exempt'],
					$fields['quota_bytes'],
					$fields['record'],
					$id,
					$row['version']
				)
			);
			if ( false === $affected ) {
				return self::store_failed_error();
			}
			if ( (int) $affected > 0 ) {
				return $record;
			}
			// 0 rows: another writer bumped the version first. Re-read.
		}

		return self::busy_error();
	}

	/**
	 * Insert a new record under the create lock: $admit (quota checks, run
	 * against the table with the lock held), then — for a capped principal
	 * only — the open-request cap (MAX_ITEMS), then a plain INSERT. The lock
	 * is held for exactly check-plus-insert and nothing else — no other
	 * operation takes a global lock.
	 *
	 * An exempt principal (requester_is_exempt_from_caps(): a wp-admin user,
	 * an unrestricted key) is never refused by count: capped keys filling
	 * every open slot must not lock the site's own administrators out of the
	 * queue (#1042 review round 1, B1).
	 *
	 * @param array         $request Complete record.
	 * @param callable|null $admit   function(): true|WP_Error.
	 * @param bool          $exempt  Whether the requester is exempt from caps.
	 * @return true|WP_Error
	 */
	private static function insert_new( array $request, $admit = null, $exempt = false ) {
		$ready = self::ensure_store();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
		$fields = self::row_from_record( $request );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		// A stale pending proposal must not count against a quota.
		self::maybe_sweep_expired();

		global $wpdb;
		$lock = self::create_lock_name();
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) );
		if ( '1' !== (string) $got ) {
			return self::busy_error();
		}

		try {
			if ( is_callable( $admit ) ) {
				$admitted = call_user_func( $admit );
				if ( is_wp_error( $admitted ) ) {
					return $admitted;
				}
			}

			$open = $exempt ? 0 : self::count_where( array( array( 'status IN', self::open_statuses() ) ) );
			if ( is_wp_error( $open ) ) {
				return $open;
			}
			if ( ! $exempt && $open >= self::MAX_ITEMS ) {
				return new WP_Error(
					'approval_queue_full',
					sprintf(
						/* translators: %d: cap */
						__( 'This site already has %d approval requests open. Nothing was queued; have the queue reviewed first.', 'mcpwp' ),
						self::MAX_ITEMS
					),
					array( 'status' => 429 )
				);
			}

			$table = self::table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery -- table name is prefix + constant.
			$inserted = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (id, status, action, requested_by_key_id, created_at, updated_at, triage_rank, cap_exempt, quota_bytes, version, record) VALUES (%s, %s, %s, %s, %s, %s, %d, %d, %d, 0, %s)",
					$fields['id'],
					$fields['status'],
					$fields['action'],
					$fields['requested_by_key_id'],
					$fields['created_at'],
					$fields['updated_at'],
					$fields['triage_rank'],
					$fields['cap_exempt'],
					$fields['quota_bytes'],
					$fields['record']
				)
			);
			if ( 1 !== (int) $inserted ) {
				return self::store_failed_error();
			}
			return true;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Per-site create-lock name (the database may be shared by several sites).
	 *
	 * @return string
	 */
	private static function create_lock_name() {
		global $wpdb;
		$prefix = ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ) ? (string) $wpdb->prefix : '';
		return substr( 'mcpwp_approvals_create_' . $prefix, 0, 64 );
	}

	/**
	 * $wpdb->esc_like(), for the LIKE patterns below.
	 *
	 * @param string $text Literal text.
	 * @return string
	 */
	private static function esc_like( $text ) {
		global $wpdb;
		return $wpdb->esc_like( (string) $text );
	}

	/**
	 * Build a WHERE clause from fixed column/operator pairs (never input):
	 * array( array( 'status =', 'pending' ), array( 'status IN', [...] ), … ).
	 *
	 * @param array $conditions Conditions, ANDed.
	 * @return string Prepared SQL fragment, '' for none.
	 */
	private static function where_sql( array $conditions ) {
		global $wpdb;
		$parts = array();
		foreach ( $conditions as $condition ) {
			list( $left, $value ) = $condition;
			if ( is_array( $value ) ) {
				$parts[] = $wpdb->prepare( $left . ' (' . implode( ', ', array_fill( 0, count( $value ), '%s' ) ) . ')', array_values( $value ) );
				continue;
			}
			$parts[] = $wpdb->prepare( $left . ( is_int( $value ) ? ' %d' : ' %s' ), $value );
		}
		return empty( $parts ) ? '' : ' WHERE ' . implode( ' AND ', $parts );
	}

	/**
	 * SELECT COUNT(*) under $conditions.
	 *
	 * @param array $conditions See where_sql().
	 * @return int|WP_Error
	 */
	private static function count_where( array $conditions ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- where_sql() prepares every value.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" . self::where_sql( $conditions ) );
		if ( null === $count || '' !== (string) $wpdb->last_error ) {
			return self::store_read_failed_error();
		}
		return (int) $count;
	}

	/**
	 * Bytes of the open records $requester holds, measured as unescaped JSON
	 * (the quota_bytes column) whatever the storage encoding.
	 *
	 * @param string $requester requested_by_key_id.
	 * @return int|WP_Error
	 */
	private static function open_bytes_of( $requester ) {
		global $wpdb;
		$table = self::table_name();
		$where = self::where_sql(
			array(
				array( 'requested_by_key_id =', (string) $requester ),
				array( 'status IN', self::open_statuses() ),
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- where_sql() prepares every value.
		$bytes = $wpdb->get_var( "SELECT COALESCE(SUM(quota_bytes), 0) FROM {$table}" . $where );
		if ( null === $bytes || '' !== (string) $wpdb->last_error ) {
			return self::store_read_failed_error();
		}
		return (int) $bytes;
	}

	/**
	 * A page of rows, decoded. Rows whose record does not decode are
	 * skipped here (they can be neither shown nor acted on).
	 *
	 * @param array  $conditions See where_sql().
	 * @param string $order_by   Fixed ORDER BY list (never input).
	 * @param int    $limit      Rows.
	 * @param int    $offset     Offset.
	 * @return array<int,array{id:string,version:int,record:array}>|WP_Error
	 */
	private static function select_rows( array $conditions, $order_by, $limit, $offset = 0 ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- where_sql() prepares every value; $order_by is a fixed string.
		$rows = $wpdb->get_results( "SELECT id, version, record FROM {$table}" . self::where_sql( $conditions ) . ' ORDER BY ' . $order_by . $wpdb->prepare( ' LIMIT %d OFFSET %d', (int) $limit, (int) $offset ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return self::store_read_failed_error();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$record = json_decode( (string) $row['record'], true );
			if ( ! is_array( $record ) ) {
				continue;
			}
			$out[] = array(
				'id'      => (string) $row['id'],
				'version' => (int) $row['version'],
				'record'  => $record,
			);
		}
		return $out;
	}

	/**
	 * Delete one row, but only if nobody changed it since it was read.
	 *
	 * @param string $id      Approval id.
	 * @param int    $version Version read.
	 * @return bool|WP_Error True when deleted, false when it changed meanwhile.
	 */
	private static function delete_row( $id, $version ) {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is prefix + constant.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %s AND version = %d", $id, (int) $version ) );
		if ( false === $deleted ) {
			return self::store_failed_error();
		}
		return (int) $deleted > 0;
	}

	/**
	 * The column copies of a record — the ONLY place they are derived, so
	 * they cannot drift from the record they index.
	 *
	 * @param array $record Record.
	 * @return array|WP_Error
	 */
	private static function row_from_record( array $record ) {
		$json = self::encode_record( $record, true );
		$size = self::quota_size( $record );
		if ( ! is_string( $json ) || '' === $json || false === $size ) {
			return new WP_Error( 'approvals_record_unencodable', __( 'This approval request could not be stored: its content cannot be encoded. Nothing was changed.', 'mcpwp' ), array( 'status' => 422 ) );
		}
		$triage    = self::sanitize_triage( isset( $record['triage'] ) ? $record['triage'] : null );
		$requester = isset( $record['requested_by_key_id'] ) ? (string) $record['requested_by_key_id'] : '';
		$metadata  = isset( $record['metadata'] ) && is_array( $record['metadata'] ) ? $record['metadata'] : array();
		// A record with no parseable updated_at is dated by its created_at
		// (retention reads this column); run_retention() never deletes one
		// with neither.
		$updated = isset( $record['updated_at'] ) && is_string( $record['updated_at'] ) && false !== strtotime( $record['updated_at'] )
			? $record['updated_at']
			: ( isset( $record['created_at'] ) ? $record['created_at'] : '' );
		return array(
			'id'                  => substr( isset( $record['id'] ) ? (string) $record['id'] : '', 0, 64 ),
			'status'              => substr( isset( $record['status'] ) ? (string) $record['status'] : '', 0, 20 ),
			'action'              => substr( isset( $record['action'] ) ? (string) $record['action'] : '', 0, 64 ),
			'requested_by_key_id' => substr( isset( $record['requested_by_key_id'] ) ? (string) $record['requested_by_key_id'] : '', 0, 191 ),
			'created_at'          => self::sql_datetime( isset( $record['created_at'] ) ? $record['created_at'] : '' ),
			'updated_at'          => self::sql_datetime( $updated ),
			'triage_rank'         => null === $triage ? -1 : self::triage_priority_rank( $triage['priority'] ),
			'cap_exempt'          => self::requester_is_exempt_from_caps( $requester, $metadata ) ? 1 : 0,
			'quota_bytes'         => $size,
			'record'              => $json,
		);
	}

	/**
	 * JSON for a record. Stored unescaped (JSON_UNESCAPED_UNICODE |
	 * JSON_UNESCAPED_SLASHES) on a utf8mb4 connection, so Persian or CJK text
	 * is not stored at 2-3x its size; escaped otherwise, so a non-utf8mb4
	 * table never receives a character it cannot hold.
	 *
	 * @param array $record     Record.
	 * @param bool  $for_storage Whether this is the stored form.
	 * @return string|false
	 */
	private static function encode_record( array $record, $for_storage ) {
		global $wpdb;
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		if ( $for_storage && ! ( isset( $wpdb->charset ) && 'utf8mb4' === $wpdb->charset ) ) {
			$flags = 0;
		}
		return wp_json_encode( $record, $flags );
	}

	/**
	 * Bytes a record counts against its requester's quota: always the
	 * unescaped JSON length, whatever encoding it is stored in.
	 *
	 * @param array $record Record.
	 * @return int|false
	 */
	private static function quota_size( array $record ) {
		$json = self::encode_record( $record, false );
		return is_string( $json ) ? strlen( $json ) : false;
	}

	/**
	 * An ISO-8601 record timestamp as a UTC DATETIME ('1970-01-01 00:00:00'
	 * when missing or unparseable, which sorts last, as '' did).
	 *
	 * @param mixed $value Timestamp.
	 * @return string
	 */
	private static function sql_datetime( $value ) {
		$time = is_string( $value ) && '' !== $value ? strtotime( $value ) : false;
		return gmdate( 'Y-m-d H:i:s', false === $time || $time < 0 ? 0 : $time );
	}

	/**
	 * Expire one pending post_content_update older than the TTL (#981
	 * review round 2, WARN-C) — in memory. Applied to every read, so a
	 * reader never sees a stale proposal as pending, and persisted by
	 * update_one() / the sweep.
	 *
	 * @param array $item Record.
	 * @return array
	 */
	private static function expire_stale_one( array $item ) {
		/**
		 * Seconds a pending content-change approval stays open.
		 *
		 * @param int $ttl Default 30 days.
		 */
		$ttl = (int) apply_filters( 'mcpwp_approval_pending_ttl', self::PENDING_CONTENT_TTL );
		if ( $ttl < 1 || ! isset( $item['status'], $item['action'], $item['created_at'] ) ) {
			return $item;
		}
		if ( 'pending' !== $item['status'] || 'post_content_update' !== $item['action'] ) {
			return $item;
		}
		$created = strtotime( (string) $item['created_at'] );
		if ( false !== $created && $created < time() - $ttl ) {
			$item['status']     = 'expired';
			$item['expired_at'] = gmdate( 'c', $created + $ttl );
			$item['updated_at'] = $item['expired_at'];
		}
		return $item;
	}

	/**
	 * Persist expiry for every pending proposal past the TTL — at most once
	 * per request (list time and before a create's quota count). Each row
	 * goes through update_one(), so the record and its status column change
	 * together and a concurrent approve is never overwritten.
	 *
	 * @return void
	 */
	private static function maybe_sweep_expired() {
		if ( self::$expiry_swept ) {
			return;
		}
		self::$expiry_swept = true;

		/** This filter is documented in expire_stale_one(). */
		$ttl = (int) apply_filters( 'mcpwp_approval_pending_ttl', self::PENDING_CONTENT_TTL );
		if ( $ttl < 1 ) {
			return;
		}

		global $wpdb;
		$table = self::table_name();
		$where = self::where_sql(
			array(
				array( 'status =', 'pending' ),
				array( 'action =', 'post_content_update' ),
				array( 'created_at <', gmdate( 'Y-m-d H:i:s', time() - $ttl ) ),
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- where_sql() prepares every value.
		$ids = $wpdb->get_col( "SELECT id FROM {$table}" . $where . ' ORDER BY id ASC LIMIT ' . self::RETENTION_BATCH );
		foreach ( (array) $ids as $id ) {
			self::update_one(
				(string) $id,
				static function ( array $record ) {
					return $record; // update_one() applies the expiry itself.
				}
			);
		}
	}

	/**
	 * Forget per-request state (tests; a long-lived process).
	 *
	 * @return void
	 */
	public static function reset_request_state() {
		self::$expiry_swept = false;
	}

	/**
	 * Bytes one capped requester (a WebMCP user, a category-restricted key)
	 * may hold in open requests (#981 review round 4). Filterable through
	 * mcpwp_approval_content_limits ('requester_bytes').
	 *
	 * @return int
	 */
	public static function requester_byte_quota() {
		$limits = self::content_limits();
		return isset( $limits['requester_bytes'] ) && $limits['requester_bytes'] > 0
			? (int) $limits['requester_bytes']
			: self::REQUESTER_BYTE_QUOTA;
	}

	/**
	 * The statuses in which a request is still open (not decided). Never
	 * compacted or deleted.
	 *
	 * @return string[]
	 */
	public static function open_statuses() {
		return array( 'pending', 'approved', 'applying', 'rolling_back', 'apply_failed' );
	}

	/**
	 * Whether a record is closed AND past the point anyone still needs its
	 * bodies: rejected, expired, rolled back — or applied more than
	 * CLOSED_RETENTION seconds ago (its rollback window).
	 *
	 * @param array $item Record.
	 * @return bool
	 */
	private static function is_compactable( array $item ) {
		$status = isset( $item['status'] ) ? (string) $item['status'] : '';
		if ( in_array( $status, array( 'rejected', 'expired', 'rolled_back' ), true ) ) {
			return true;
		}
		if ( 'applied' === $status ) {
			$applied = isset( $item['applied_at'] ) ? strtotime( (string) $item['applied_at'] ) : false;
			/**
			 * Seconds an applied approval keeps its rollback bodies.
			 *
			 * @param int $seconds Default 7 days.
			 */
			$retention = (int) apply_filters( 'mcpwp_approval_applied_retention', self::CLOSED_RETENTION );
			return false !== $applied && $applied < time() - $retention;
		}
		return false;
	}

	/**
	 * Shrink a closed record to what an audit needs (#981 review round 4):
	 * drop proposed and rollback bodies and any other large payload string,
	 * keep hashes, bounded excerpts and revision references.
	 *
	 * @param array $item Record.
	 * @return array
	 */
	private static function compact_if_due( array $item ) {
		if ( ! empty( $item['compacted'] ) || ! self::is_compactable( $item ) ) {
			return $item;
		}
		if ( isset( $item['payload'] ) && is_array( $item['payload'] ) ) {
			unset( $item['payload']['after_content'] );
			$item['payload'] = self::drop_large_strings( $item['payload'] );
		}
		if ( isset( $item['rollback'] ) && is_array( $item['rollback'] ) ) {
			unset( $item['rollback']['before_content'] );
			$item['rollback'] = self::drop_large_strings( $item['rollback'] );
		}
		$item['compacted'] = gmdate( 'c' );
		return $item;
	}
	/**
	 * Replace every string over 4 KB in a nested array with its sha256.
	 *
	 * @param array $value Array.
	 * @return array
	 */
	private static function drop_large_strings( array $value ) {
		foreach ( $value as $key => $inner ) {
			if ( is_array( $inner ) ) {
				$value[ $key ] = self::drop_large_strings( $inner );
			} elseif ( is_string( $inner ) && strlen( $inner ) > 4096 ) {
				$value[ $key ] = '[compacted sha256:' . hash( 'sha256', $inner ) . ']';
			}
		}
		return $value;
	}

	/**
	 * Whether a record can still be rolled back from what it stores — the
	 * inline copy, or a revision that still exists with the recorded hash
	 * (#981 review round 5).
	 *
	 * @param array $item Private record.
	 * @return bool
	 */
	public static function rollback_available( array $item ) {
		$status = isset( $item['status'] ) ? (string) $item['status'] : '';
		if ( ! in_array( $status, array( 'applied', 'apply_failed' ), true ) ) {
			return false;
		}
		$action = isset( $item['action'] ) ? (string) $item['action'] : '';
		if ( 'destructive_operation' === $action ) {
			return false;
		}
		if ( 'post_content_update' === $action ) {
			$post_id = isset( $item['resource']['id'] ) ? (int) $item['resource']['id'] : 0;
			return $post_id > 0 && ! is_wp_error( self::rollback_content( $item, $post_id ) );
		}
		if ( 'bulk_content_publish' === $action ) {
			return ! empty( $item['metadata']['applied_ids'] );
		}
		return true;
	}

	/**
	 * Write one action-log row summarising an approval record that is about
	 * to be removed from the store (#981 review round 5). Returns false when
	 * the row could not be written — the caller then keeps the record.
	 *
	 * @param array  $item   Private record.
	 * @param string $reason retention | purged.
	 * @return bool
	 */
	private static function audit_removal( array $item, $reason ) {
		if ( ! class_exists( 'Mcpwp_Action_Log' ) || ! method_exists( 'Mcpwp_Action_Log', 'record_approval_removal' ) ) {
			return false;
		}

		$hash = '';
		if ( isset( $item['diff']['after_hash'] ) ) {
			$hash = (string) $item['diff']['after_hash'];
		} elseif ( isset( $item['payload'] ) ) {
			$hash = hash( 'sha256', (string) wp_json_encode( $item['payload'] ) );
		}

		$approvers = array();
		foreach ( array( 'approved_by', 'rejected_by', 'applied_by', 'rolled_back_by', 'claimed_by' ) as $field ) {
			if ( ! empty( $item[ $field ] ) ) {
				$approvers[ $field ] = (string) $item[ $field ];
			}
		}
		if ( ! empty( $item['resolved']['by'] ) ) {
			$approvers['resolved_by'] = (string) $item['resolved']['by'];
		}

		$summary = array(
			'approval_id'    => isset( $item['id'] ) ? (string) $item['id'] : '',
			'action'         => isset( $item['action'] ) ? (string) $item['action'] : '',
			'resource_type'  => isset( $item['resource']['type'] ) ? (string) $item['resource']['type'] : '',
			'resource_id'    => isset( $item['resource']['id'] ) ? (string) $item['resource']['id'] : '',
			'requester'      => isset( $item['requested_by_key_id'] ) ? (string) $item['requested_by_key_id'] : '',
			'approvers'      => $approvers,
			'final_status'   => isset( $item['status'] ) ? (string) $item['status'] : '',
			'created_at'     => isset( $item['created_at'] ) ? $item['created_at'] : null,
			'decided_at'     => isset( $item['approved_at'] ) && $item['approved_at'] ? $item['approved_at'] : ( isset( $item['rejected_at'] ) ? $item['rejected_at'] : ( isset( $item['expired_at'] ) ? $item['expired_at'] : null ) ),
			'applied_at'     => isset( $item['applied_at'] ) ? $item['applied_at'] : null,
			'rolled_back_at' => isset( $item['rolled_back_at'] ) ? $item['rolled_back_at'] : null,
			'content_hash'   => $hash,
			'removal_reason' => (string) $reason,
		);

		return Mcpwp_Action_Log::record_approval_removal( $summary );
	}

	/**
	 * Remove every closed record now (Control Room / REST "purge closed
	 * approvals", admin only). An applied change inside its rollback
	 * window is not "done" yet and is kept (#981 review round 5). Each
	 * removal writes its audit row first — no audit row, no removal.
	 *
	 * @return int|WP_Error Records removed.
	 */
	public static function purge_closed() {
		$ready = self::ensure_store();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$removed = 0;
		$after   = '';
		while ( true ) {
			$rows = self::select_rows(
				array(
					array( 'status NOT IN', self::open_statuses() ),
					array( 'id >', $after ),
				),
				'id ASC',
				self::RETENTION_BATCH
			);
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			if ( empty( $rows ) ) {
				break;
			}
			foreach ( $rows as $row ) {
				$after  = $row['id'];
				$item   = $row['record'];
				$status = isset( $item['status'] ) ? (string) $item['status'] : '';
				if ( 'applied' === $status && ! self::is_compactable( $item ) ) {
					continue;
				}
				$deleted = self::audited_delete( $row, 'purged' );
				if ( is_wp_error( $deleted ) ) {
					return $deleted;
				}
				if ( $deleted ) {
					$removed++;
				}
			}
		}
		return $removed;
	}

	/**
	 * Daily retention (#1042), replacing the old whole-store byte budget and
	 * the 7-day eviction: closed records are kept CLOSED_RETENTION_DAYS
	 * (filter mcpwp_approvals_closed_retention_days) after they were last
	 * decided, then deleted in batches of RETENTION_BATCH — each one only
	 * after its approval_record_removed audit row is written. Open records
	 * are never touched. Applied records past their rollback window are
	 * compacted here too (their bodies dropped), since there is no longer
	 * a whole-store write to do it on.
	 *
	 * @param float|null $time_budget Seconds; tests pass a small one.
	 * @return int|WP_Error Records deleted.
	 */
	public static function run_retention( $time_budget = null ) {
		$ready = self::ensure_store();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$deadline = microtime( true ) + ( is_numeric( $time_budget ) ? (float) $time_budget : 20.0 );
		$removed  = 0;

		/**
		 * Days a closed approval record is kept before the daily cron
		 * deletes it (its audit row stays in the action log).
		 *
		 * @param int $days Default 90. Below 1 disables deletion.
		 */
		$days = (int) apply_filters( 'mcpwp_approvals_closed_retention_days', self::CLOSED_RETENTION_DAYS );
		$undated = array();
		if ( $days > 0 ) {
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
			$after  = '';
			while ( microtime( true ) < $deadline ) {
				$rows = self::select_rows(
					array(
						array( 'status NOT IN', self::open_statuses() ),
						array( 'updated_at <', $cutoff ),
						array( 'id >', $after ),
					),
					'id ASC',
					self::RETENTION_BATCH
				);
				if ( is_wp_error( $rows ) ) {
					return $rows;
				}
				foreach ( $rows as $row ) {
					$after = $row['id'];
					// The column prefilter is a copy; the record decides. No
					// usable date at all: never deleted, reported instead.
					$decided = self::decided_time( $row['record'] );
					if ( false === $decided ) {
						$undated[] = $row['id'];
						continue;
					}
					if ( $decided >= time() - $days * DAY_IN_SECONDS ) {
						continue;
					}
					$deleted = self::audited_delete( $row, 'retention' );
					if ( is_wp_error( $deleted ) ) {
						return $deleted;
					}
					if ( $deleted ) {
						$removed++;
					}
				}
				if ( count( $rows ) < self::RETENTION_BATCH ) {
					break;
				}
			}
		}
		if ( ! empty( $undated ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP approvals retention: kept closed records with no updated_at or created_at: ' . implode( ', ', $undated ) );
		}

		global $wpdb;
		$table = self::table_name();
		/** This filter is documented in is_compactable(). */
		$window = (int) apply_filters( 'mcpwp_approval_applied_retention', self::CLOSED_RETENTION );
		$after  = '';
		while ( microtime( true ) < $deadline ) {
			$where = self::where_sql(
				array(
					array( 'status =', 'applied' ),
					array( 'updated_at <', gmdate( 'Y-m-d H:i:s', time() - max( 0, $window ) ) ),
					array( 'id >', $after ),
				)
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- where_sql() prepares every value.
			$ids = $wpdb->get_col( "SELECT id FROM {$table}" . $where . ' ORDER BY id ASC LIMIT ' . self::RETENTION_BATCH );
			if ( ! is_array( $ids ) || '' !== (string) $wpdb->last_error ) {
				return self::store_read_failed_error();
			}
			foreach ( $ids as $id ) {
				$after = (string) $id;
				self::update_one(
					$after,
					static function ( array $record ) {
						return $record; // update_one() compacts a record that is due.
					}
				);
			}
			if ( count( $ids ) < self::RETENTION_BATCH ) {
				break;
			}
		}

		return $removed;
	}

	/**
	 * When a record was last decided: updated_at, else created_at, else
	 * false (unknown — retention never deletes such a record).
	 *
	 * @param array $record Record.
	 * @return int|false
	 */
	private static function decided_time( array $record ) {
		foreach ( array( 'updated_at', 'created_at' ) as $field ) {
			$time = isset( $record[ $field ] ) && is_string( $record[ $field ] ) && '' !== $record[ $field ] ? strtotime( $record[ $field ] ) : false;
			if ( false !== $time ) {
				return $time;
			}
		}
		return false;
	}

	/**
	 * Remove one row, audit row first — exactly one audit row per removed
	 * approval (#1042 review round 1, NIT c). The audit row is written
	 * before the version-guarded DELETE (no audit, no removal); when that
	 * DELETE then loses to a concurrent write, the row stays and a later
	 * run retries it. That retry finds the approval_record_removed row the
	 * first attempt already wrote and does not write a second one. A failed
	 * check skips the row this run: "could not check" is never treated as
	 * "not logged yet".
	 *
	 * @param array  $row    {id, version, record}.
	 * @param string $reason Removal reason.
	 * @return bool|WP_Error True when removed.
	 */
	private static function audited_delete( array $row, $reason ) {
		if ( ! class_exists( 'Mcpwp_Action_Log' ) || ! method_exists( 'Mcpwp_Action_Log', 'approval_removal_logged' ) ) {
			return false;
		}
		$logged = Mcpwp_Action_Log::approval_removal_logged( $row['id'] );
		if ( null === $logged ) {
			return false;
		}
		if ( ! $logged && ! self::audit_removal( $row['record'], $reason ) ) {
			return false;
		}
		return self::delete_row( $row['id'], $row['version'] );
	}

	/**
	 * Cron entry point for RETENTION_CRON_HOOK.
	 *
	 * @return void
	 */
	public static function retention_cron() {
		self::run_retention();
	}
	/**
	 * Resolve an apply/rollback that is stuck in flight (#981 review round
	 * 4, WARN-1) — admin only, and only once it is stale. A person checks
	 * the content and says what actually happened:
	 *
	 *  - applying,     completed     → applied      (rollback then works)
	 *  - applying,     not_completed → approved     (verify the content first)
	 *  - rolling_back, completed     → rolled_back
	 *  - rolling_back, not_completed → applied
	 *
	 * @param string $id      Request id.
	 * @param string $outcome completed | not_completed.
	 * @param array  $context {actor?: string}.
	 * @return array|WP_Error Public record.
	 */
	public static function resolve_stale( $id, $outcome, $context = array() ) {
		$request = self::get_private_request( $id );
		if ( is_wp_error( $request ) ) {
			return $request;
		}

		$status = isset( $request['status'] ) ? (string) $request['status'] : '';
		$map    = array(
			'applying'     => array( 'completed' => 'applied', 'not_completed' => 'approved' ),
			'rolling_back' => array( 'completed' => 'rolled_back', 'not_completed' => 'applied' ),
		);
		if ( ! isset( $map[ $status ] ) ) {
			return new WP_Error( 'approval_not_in_flight', __( 'Only an apply or rollback that is stuck in progress can be resolved.', 'mcpwp' ), array( 'status' => 409 ) );
		}
		if ( ! isset( $map[ $status ][ $outcome ] ) ) {
			return new WP_Error( 'invalid_outcome', __( 'outcome must be "completed" or "not_completed".', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$public = self::public_record( $request );
		if ( empty( $public['needs_attention'] ) ) {
			return new WP_Error( 'approval_in_progress', __( 'This apply or rollback is still running. It can be resolved by hand once it has been in progress for a while.', 'mcpwp' ), array( 'status' => 409 ) );
		}

		$target = $map[ $status ][ $outcome ];
		$fields = array( 'applied' => 'applied_at', 'approved' => 'resolved_at', 'rolled_back' => 'rolled_back_at' );
		$actor  = is_array( $context ) && isset( $context['actor'] ) ? (string) $context['actor'] : '';

		$updated = self::transition_atomically(
			$id,
			array( $status ),
			$target,
			$fields[ $target ],
			array(
				'resolved' => array(
					'from'    => $status,
					'outcome' => $outcome,
					'by'      => $actor,
					'at'      => gmdate( 'c' ),
				),
			)
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

		$public = self::public_record( $updated );
		if ( 'approved' === $target ) {
			$public['warning'] = __( 'Marked not applied. Check the post content before applying again — the change may have partly landed.', 'mcpwp' );
		}
		return $public;
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
