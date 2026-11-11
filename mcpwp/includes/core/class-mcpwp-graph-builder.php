<?php
/**
 * Site graph builder — the single extraction + persistence chokepoint for the
 * `mcpwp_graph_nodes` / `mcpwp_graph_edges` tables (#943 S1, revised after
 * PR #993 review: queue table replaces the dirty option, save-path marks
 * only, batched link resolution, a change hash covering everything the
 * rebuild writes, trash-keeps-edges, and a readiness guard).
 *
 * Extraction here is the SAME parsing logic `class-mcpwp-rest-content-graph.php`
 * and `class-mcpwp-rest-api.php` already used for the live, request-scoped
 * content graph (link parsing, Elementor `_elementor_data` link/text
 * extraction, taxonomy, menu depth, freshness/hub/orphan scoring) — moved here
 * so there is exactly one parser, not two. Those REST classes delegate to the
 * static methods below; their request/response shapes are unchanged.
 *
 * ## Write-path shape (post #993 review)
 *
 * A WordPress hook (save_post, meta changes, term changes, trash/delete)
 * calls `mark()`, which does at most one `INSERT IGNORE` into
 * `mcpwp_graph_queue` and schedules the worker. It never rebuilds inline —
 * a bulk edit of 400 posts in one request stays fast because the request
 * only ever writes queue rows, not graph rows. The one exception: inside an
 * MCPWP-authenticated request — `mark()` itself reads
 * `Mcpwp_Content_Guardrails::in_context()` (#943 S1 review round 3, N1; set
 * by `verify_api_key()` the moment a request passes API-key auth, the same
 * chokepoint every route's permission_callback runs through) — `on_shutdown()`
 * rebuilds up to REQUEST_REBUILD_LIMIT of that request's own marked nodes
 * within REQUEST_REBUILD_BUDGET seconds, so the agent that made the edit sees
 * its own edges in the same response. Every other caller (wp-admin, another
 * agent's concurrent edit, a cron-driven import) only ever queues;
 * `run_worker_batch()` drains it, time-budgeted, on a WP-Cron single event
 * (60s out) or an Action Scheduler async action when present.
 *
 * Acceptance: edges reflect an edit by the next worker run (<=2 min), or the
 * same request for MCPWP-made edits.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and maintains the persisted site graph.
 */
class Mcpwp_Graph_Builder {

	/** Option: true only once SHOW TABLES has verified all 4 graph tables exist. */
	const READY_OPTION = 'mcpwp_graph_ready';

	/** Daily cron that retries table creation while READY_OPTION is not true. */
	const READY_RETRY_CRON_HOOK = 'mcpwp_graph_tables_retry';

	/** WP-Cron/Action-Scheduler hook that drains the queue table. */
	const WORKER_CRON_HOOK = 'mcpwp_graph_worker';

	/** Cron/Action-Scheduler hook for one backfill batch. */
	const BACKFILL_CRON_HOOK = 'mcpwp_graph_backfill';

	/** Highest post ID already backfilled. */
	const BACKFILL_CURSOR_OPTION = 'mcpwp_graph_backfill_cursor';

	/** pending|running|done. */
	const BACKFILL_STATUS_OPTION = 'mcpwp_graph_backfill_status';

	/** When the backfill last completed — the reconcile "since" watermark. */
	const BUILT_AT_OPTION = 'mcpwp_graph_built_at';

	/** pending|running|done — a cheap hash-comparison sweep after reactivation. */
	const RECONCILE_STATUS_OPTION = 'mcpwp_graph_reconcile_status';

	/** Highest post ID already reconcile-checked. */
	const RECONCILE_CURSOR_OPTION = 'mcpwp_graph_reconcile_cursor';

	/** Posts processed per backfill batch. */
	const BACKFILL_BATCH_SIZE = 50;

	/** Max seconds a single backfill batch may run. */
	const BACKFILL_TIME_BUDGET = 10.0;

	/** Posts hash-compared per reconcile batch (cheap: no rewrite unless changed). */
	const RECONCILE_BATCH_SIZE = 200;

	/** Max seconds a single worker run may spend draining the queue. */
	const WORKER_TIME_BUDGET = 5.0;

	/** Max node keys popped from the queue per worker pass. */
	const WORKER_POP_BATCH = 25;

	/** Max of THIS request's own marked nodes rebuilt at shutdown. */
	const REQUEST_REBUILD_LIMIT = 5;

	/** Max seconds the shutdown request-rebuild may spend. */
	const REQUEST_REBUILD_BUDGET = 1.0;

	/**
	 * Same-host, non-excluded href resolution falls back to url_to_postid()
	 * at most this many times per post (#943 S1 review round 2, BLOCK-2:
	 * raised from 10 — the cap is now spent only on genuinely ambiguous
	 * candidates, never on an already-excluded rewrite-base/search/external
	 * href, so 25 covers realistically link-heavy pages without the old
	 * risk of an excluded href silently eating the budget).
	 */
	const URL_TO_POSTID_FALLBACK_CAP = 25;

	/**
	 * Version of the link-resolution rules, folded into content_hash() so a
	 * change to how an href maps to a post makes the next backfill or
	 * reconcile re-resolve every post once, instead of leaving the edges the
	 * old rules wrote in place (a post with an unchanged hash is skipped).
	 * Bump it whenever resolve_hrefs_for_post() changes what it resolves.
	 * 2: #1000 single-segment permalink check, covered-type fallback.
	 */
	const LINK_RESOLVER_VERSION = 2;

	/** Max distinct anchor texts stored per links_to edge. */
	const MAX_EDGE_ANCHORS = 5;

	/** Post types never graphed as post nodes, regardless of public/queryable. */
	const EXCLUDED_POST_TYPES = array(
		'attachment',
		'revision',
		// Page-builder and theme internals that some builders register as
		// publicly queryable. They are not pages a visitor reaches, so they
		// would only show up as false orphans (Elementor's "Default Kit" did,
		// in the 3.14.3 release smoke test).
		'elementor_library',
		'elementor_snippet',
		'elementor_font',
		'elementor_icons',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
		'wp_font_family',
		'wp_font_face',
	);

	/**
	 * Node keys marked during THIS request, keyed by blog ID first
	 * (`[blog_id][node_key] => reason`), in mark() order (#943 S1 review
	 * round 2, W2). A single flat array flushed with $wpdb->prefix read at
	 * SHUTDOWN time — not at mark() time — mis-attributed every mark made
	 * inside a switch_to_blog() block to whichever blog happened to be
	 * current when shutdown ran, not the blog that was current when
	 * mark() was called. Grouping by the blog ID captured at mark() time
	 * lets on_shutdown() switch_to_blog() into each group before flushing
	 * it, so a save made while switched to blog 2 lands in blog 2's own
	 * `wp_2_mcpwp_graph_queue`, not blog 1's.
	 *
	 * Read by on_shutdown() for the MCPWP-authenticated-request
	 * same-response rebuild; always populated (cheap, in-memory) regardless
	 * of whether this request turns out to be MCPWP-authenticated, since
	 * mark() cannot know that in advance for a hook that fires before
	 * check_permission() on a nested/looped dispatch.
	 *
	 * @var array<int,array<string,string>>
	 */
	private static $request_marked = array();

	/**
	 * OR-accumulated inside `mark()` from `Mcpwp_Content_Guardrails::in_context()`
	 * (#943 S1 review round 3, N1) — never set directly from a request header
	 * or any other caller-controlled input. See `mark()`'s docblock for why
	 * this is captured there instead of read fresh at `on_shutdown()` time.
	 *
	 * @var bool
	 */
	private static $in_mcpwp_request = false;

	/**
	 * Per-run href -> post ID cache for the batched link resolver. Reset by
	 * whichever loop starts a "run" (one rebuild, one worker pass, one
	 * backfill/reconcile batch) so cross-post cache hits are free within
	 * that run without ever serving a stale answer across runs.
	 *
	 * @var array<string,int>
	 */
	private static $url_id_cache = array();

	/**
	 * Per-run memo of a post's own permalink path (post ID => normalized,
	 * query-less path, '' when it has no pretty permalink to compare).
	 * Same lifetime as $url_id_cache. #1000: one get_permalink() per
	 * candidate post per run, not per link.
	 *
	 * @var array<int,string>
	 */
	private static $permalink_path_cache = array();

	/** Whether a DB error has already been logged this request (log once). */
	private static $logged_db_error_this_request = false;

	// -------------------------------------------------------------------------
	// Readiness (W1, W6)
	// -------------------------------------------------------------------------

	/**
	 * Whether all 4 graph tables are known-present. Every hook handler checks
	 * this FIRST and returns with zero queries when false — a site mid
	 * upgrade, or one where table creation failed (a restricted DB user,
	 * `CREATE TABLE` denied), never throws from a hook or half-writes.
	 *
	 * READY_OPTION is autoloaded (update_option()'s third arg defaults to
	 * true for a new option, and Mcpwp_Activator::mark_graph_ready() passes
	 * true explicitly), so this is a read from the already-loaded alloptions
	 * cache, not a per-hook query.
	 *
	 * @return bool
	 */
	public static function ready() {
		return true === (bool) get_option( self::READY_OPTION, false );
	}

	// The daily re-verify (N3) lives at Mcpwp_Activator::retry_graph_tables_cron(),
	// which is what mcpwp.php actually wires to READY_RETRY_CRON_HOOK — it
	// re-checks every run, not only while not yet ready (see that method's
	// docblock).

	// -------------------------------------------------------------------------
	// Request-authenticated flag (for same-request rebuild)
	// -------------------------------------------------------------------------

	/**
	 * #943 S1 review round 3, N1: there is no graph-owned setter for this
	 * flag any more. `mark()` OR-in's `Mcpwp_Content_Guardrails::in_context()`
	 * every time it is called, which is #957's single definition of "an
	 * MCPWP-made edit" — the same chokepoint (`Mcpwp_REST_API::check_permission()`
	 * via `trait-mcpwp-api-auth.php`'s `verify_api_key()`) that used to set this
	 * class's own flag directly now sets Guardrails' flag instead, and this
	 * class just reads it. See `mark()`'s docblock for why that read happens
	 * there and not at `on_shutdown()` time.
	 *
	 * Test/introspection helper.
	 *
	 * @return bool
	 */
	public static function is_request_authenticated() {
		return self::$in_mcpwp_request;
	}

	/**
	 * Reset the authenticated-request flag at the start of a new request
	 * (wired on `init`, priority 1 — before any route's permission_callback
	 * can run, and before any hook's `mark()` call could OR in a stale
	 * Guardrails read left over from a previous request on a persistent
	 * process). N1: a persistent process must never carry "authenticated"
	 * over from the request that set it into a later, different request.
	 */
	public static function reset_for_new_request() {
		self::$in_mcpwp_request = false;
	}

	/**
	 * Test helper: reset all request-local state between tests.
	 */
	public static function reset_request_state() {
		self::$request_marked          = array();
		self::$in_mcpwp_request        = false;
		self::$url_id_cache            = array();
		self::$permalink_path_cache = array();
		self::$logged_db_error_this_request = false;
	}

	// -------------------------------------------------------------------------
	// Scope (W4, N1): which post types this graph covers at all.
	// -------------------------------------------------------------------------

	/**
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_covered_post_type( $post_type ) {
		$post_type = (string) $post_type;
		/**
		 * Filter the post types the site graph never covers.
		 *
		 * @param string[] $excluded Post type slugs.
		 */
		$excluded = function_exists( 'apply_filters' ) ? (array) apply_filters( 'mcpwp_graph_excluded_post_types', self::EXCLUDED_POST_TYPES ) : self::EXCLUDED_POST_TYPES;
		if ( in_array( $post_type, $excluded, true ) ) {
			return false;
		}
		$obj = get_post_type_object( $post_type );
		return (bool) ( $obj && ( ! empty( $obj->public ) || ! empty( $obj->publicly_queryable ) ) );
	}

	/**
	 * @return array<string> Covered, public/queryable, non-excluded post types.
	 */
	public static function covered_post_types() {
		$all = function_exists( 'get_post_types' ) ? array_values( get_post_types( array( 'public' => true ) ) ) : array( 'post', 'page' );
		return array_values( array_filter( $all, array( __CLASS__, 'is_covered_post_type' ) ) );
	}

	// -------------------------------------------------------------------------
	// Hook handlers — mark only (BLOCK-4). Filter scope BEFORE marking, not at
	// rebuild time, so an excluded post type never even reaches the queue.
	// -------------------------------------------------------------------------

	/**
	 * `save_post` handler.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public static function on_save_post( $post_id, $post = null ) {
		if ( ! self::ready() ) {
			return;
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = $post ?: get_post( $post_id );
		if ( ! $post || ! self::is_covered_post_type( $post->post_type ) ) {
			return;
		}
		self::mark( self::node_key_for_post( $post_id ), 'save_post' );
	}

	/**
	 * `deleted_post` handler — a PERMANENT delete (force-delete or empty
	 * trash), never a plain move-to-trash (that is a `save_post` with
	 * status=trash, see `on_save_post()` + `mark_node_trashed()`).
	 *
	 * BLOCK-2: before removing the node, requeue every post that links to it
	 * and every child (`parent_of`), so their own edges/hash get reconsidered
	 * once the target is actually gone.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_deleted_post( $post_id ) {
		if ( ! self::ready() ) {
			return;
		}
		$node_key = self::node_key_for_post( (int) $post_id );
		self::requeue_dependents_before_delete( $node_key );
		self::delete_node_and_edges( $node_key );
	}

	/**
	 * `untrashed_post` handler — mark for a normal full rebuild (status is no
	 * longer 'trash', and content may have changed while trashed).
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_untrashed_post( $post_id ) {
		if ( ! self::ready() ) {
			return;
		}
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post || ! self::is_covered_post_type( $post->post_type ) ) {
			return;
		}
		self::mark( self::node_key_for_post( $post_id ), 'untrash' );
	}

	/**
	 * `set_object_terms` handler — a post's in_term edges depend on its
	 * terms. Only fires for a covered post type (W4): a term change on a
	 * non-covered object type (e.g. a taxonomy attached to a CPT this graph
	 * excludes) never reaches the queue.
	 *
	 * @param int $object_id Object ID.
	 */
	public static function on_set_object_terms( $object_id ) {
		if ( ! self::ready() ) {
			return;
		}
		$post = get_post( (int) $object_id );
		if ( ! $post || ! self::is_covered_post_type( $post->post_type ) ) {
			return;
		}
		self::mark( self::node_key_for_post( (int) $object_id ), 'set_object_terms' );
	}

	/**
	 * `edited_term` handler.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy.
	 */
	public static function on_edited_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! self::ready() ) {
			return;
		}
		self::mark( self::node_key_for_term( (string) $taxonomy, (int) $term_id ), 'edited_term' );
	}

	/**
	 * `delete_term` handler.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy.
	 */
	public static function on_delete_term( $term_id, $tt_id, $taxonomy ) {
		if ( ! self::ready() ) {
			return;
		}
		self::mark( self::node_key_for_term( (string) $taxonomy, (int) $term_id ), 'delete_term' );
	}

	/**
	 * `updated_post_meta`/`added_post_meta`/`deleted_post_meta` handler
	 * (W3): these do NOT fire `save_post`, so a writer that updates meta
	 * directly — `wp_set_elementor`, find-replace, rollback, "set featured
	 * image" — was invisible to the old save_post-only wiring.
	 *
	 * @param int    $meta_id   Meta row ID (unused).
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 */
	public static function on_post_meta_changed( $meta_id, $object_id, $meta_key ) {
		if ( ! self::ready() ) {
			return;
		}
		if ( ! in_array( (string) $meta_key, array( '_elementor_data', '_thumbnail_id', '_wp_page_template' ), true ) ) {
			return;
		}
		$post_id = (int) $object_id;
		$post    = get_post( $post_id );
		if ( ! $post || ! self::is_covered_post_type( $post->post_type ) ) {
			return;
		}
		self::mark( self::node_key_for_post( $post_id ), 'meta:' . $meta_key );
	}

	/**
	 * Requeue every source of an inbound links_to edge and every child
	 * (parent_of) of a node about to be permanently deleted.
	 *
	 * @param string $node_key Node key about to be deleted.
	 */
	private static function requeue_dependents_before_delete( $node_key ) {
		global $wpdb;
		$table = self::edges_table();

		$sources = self::run_suppressed(
			function () use ( $wpdb, $table, $node_key ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT from_key FROM {$table} WHERE to_key = %s AND type = 'links_to'", $node_key ) );
			}
		);
		$children = self::run_suppressed(
			function () use ( $wpdb, $table, $node_key ) {
				return $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT to_key FROM {$table} WHERE from_key = %s AND type = 'parent_of'", $node_key ) );
			}
		);

		foreach ( array_unique( array_merge( (array) $sources, (array) $children ) ) as $key ) {
			self::mark( (string) $key, 'dependent_of_delete' );
		}
	}

	// -------------------------------------------------------------------------
	// Queue table (BLOCK-4 / W2 / W5): mark = one atomic INSERT IGNORE.
	// -------------------------------------------------------------------------

	/**
	 * Mark a node dirty. Buffers in memory for the rest of this request and
	 * lets `on_shutdown()` flush the whole request's marks as ONE (or one
	 * per <=500-row chunk) `INSERT IGNORE` — never one query per mark().
	 *
	 * Measured on this rig: 400 individual per-call `INSERT IGNORE`s cost
	 * ~10ms each (~4.05s total) — dominated by this container's MySQL commit
	 * latency, not PHP — which alone blew a bulk edit's request budget. The
	 * fix is the same one already applied to edges (BLOCK-4): batch the
	 * writes a single request produces into one statement, deferred to
	 * `shutdown`, exactly where the rebuild carve-out already defers to.
	 * `INSERT IGNORE` is still what lands in the table — this only changes
	 * WHEN the write happens, not what it is, so it stays atomic and
	 * duplicate-proof per the design.
	 *
	 * Trade-off: a mark is no longer durable the instant this call returns —
	 * it survives until `shutdown`, which WordPress runs via a PHP shutdown
	 * function (fires even after a fatal error) but not after a hard
	 * process kill. Accepted: the same window already existed for the
	 * authenticated-request rebuild carve-out, and a request that dies that
	 * hard has bigger problems than one missed graph mark — the NEXT save of
	 * that post (or the reconcile pass) still catches it.
	 *
	 * Groups by get_current_blog_id() AT MARK TIME (#943 S1 review round 2,
	 * W2) — not by $wpdb->prefix read later, at flush time, which is what a
	 * previous version of this method did directly. That was wrong on
	 * multisite: a save made inside a switch_to_blog(2) block, flushed from
	 * a shutdown handler that (by then) may have restored to blog 1, wrote
	 * blog 2's mark into blog 1's queue table. Grouping here and having
	 * on_shutdown() switch_to_blog() per group before flushing fixes that.
	 *
	 * @param string $node_key Node key.
	 * @param string $reason   Short reason tag (bookkeeping only).
	 */
	public static function mark( $node_key, $reason = 'edit' ) {
		$node_key = (string) $node_key;
		if ( '' === $node_key || ! self::ready() ) {
			return;
		}

		// N1 (#943 S1 review round 3): read #957's shared context flag HERE,
		// at mark() time, not at on_shutdown() time. Mcpwp_Content_Guardrails
		// resets its own context on `shutdown` at priority 0; this class's
		// on_shutdown() runs at priority 10 — by the time it would run, the
		// flag has already gone back to false, so reading it there would
		// always see false and silently kill the same-response rebuild for
		// every real MCPWP request. mark() itself runs earlier, from inside
		// the same request that is (or isn't) authenticated, so OR-ing the
		// current value in here — never overwriting it back to false —
		// accumulates "was this request ever MCPWP-authenticated at the time
		// any of its marks were made" correctly for on_shutdown() to read
		// later. This also lets the graph builder's own flag be deleted:
		// there is now exactly one definition of "an MCPWP-made edit",
		// Mcpwp_Content_Guardrails::in_context(), not two.
		if ( class_exists( 'Mcpwp_Content_Guardrails' ) ) {
			self::$in_mcpwp_request = self::$in_mcpwp_request || Mcpwp_Content_Guardrails::in_context();
		}

		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		if ( ! isset( self::$request_marked[ $blog_id ] ) ) {
			self::$request_marked[ $blog_id ] = array();
		}
		if ( ! isset( self::$request_marked[ $blog_id ][ $node_key ] ) ) {
			self::$request_marked[ $blog_id ][ $node_key ] = (string) $reason;
		}
	}

	/**
	 * Flush a set of {node_key => reason} marks to the CURRENT blog's queue
	 * table in as few queries as possible, then schedule that blog's worker.
	 * Caller is responsible for already being switched to the right blog
	 * (see on_shutdown()) — this function itself does not switch.
	 *
	 * @param array<string,string> $marks node_key => reason.
	 */
	private static function flush_marks_to_queue( array $marks ) {
		if ( empty( $marks ) ) {
			return;
		}

		global $wpdb;
		$table = self::queue_table();
		$now   = current_time( 'mysql' );

		foreach ( array_chunk( array_keys( $marks ), 500 ) as $chunk ) {
			$placeholders = array();
			$args         = array();
			foreach ( $chunk as $node_key ) {
				$placeholders[] = '(%s, %s, %s)';
				$args[]         = $node_key;
				$args[]         = $marks[ $node_key ];
				$args[]         = $now;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built with placeholders only, prepared below.
			$sql = "INSERT IGNORE INTO {$table} (node_key, reason, queued_at) VALUES " . implode( ',', $placeholders );

			self::run_suppressed(
				function () use ( $wpdb, $sql, $args ) {
					return $wpdb->query( $wpdb->prepare( $sql, $args ) );
				}
			);
		}

		self::schedule_worker();
	}

	/**
	 * @return string
	 */
	public static function queue_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcpwp_graph_queue';
	}

	/**
	 * Pop up to $limit node keys (with their reason — N4a needs it to tell a
	 * fresh failure from an already-retried one) off the queue (oldest
	 * first), deleting them as claimed. A worker-run "pop": fine for the
	 * single-consumer WP-Cron/AS scheduling this class uses; not a
	 * distributed lock.
	 *
	 * @param int $limit Max rows.
	 * @return array<string,string> node_key => reason.
	 */
	private static function pop_queue_batch( $limit ) {
		global $wpdb;
		$table = self::queue_table();

		$rows = self::run_suppressed(
			function () use ( $wpdb, $table, $limit ) {
				return $wpdb->get_results( $wpdb->prepare( "SELECT node_key, reason FROM {$table} ORDER BY queued_at ASC LIMIT %d", (int) $limit ), ARRAY_A );
			}
		);
		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $rows ) ) {
			return array();
		}

		$batch = array();
		foreach ( $rows as $row ) {
			$batch[ (string) $row['node_key'] ] = (string) $row['reason'];
		}

		$keys         = array_keys( $batch );
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		self::run_suppressed(
			function () use ( $wpdb, $table, $placeholders, $keys ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
				return $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE node_key IN ({$placeholders})", $keys ) );
			}
		);

		return $batch;
	}

	/**
	 * Whether the queue table currently has any rows.
	 *
	 * @return bool
	 */
	private static function queue_has_rows() {
		global $wpdb;
		$table = self::queue_table();
		$count = self::run_suppressed(
			function () use ( $wpdb, $table ) {
				return $wpdb->get_var( "SELECT COUNT(*) FROM {$table} LIMIT 1" );
			}
		);
		return (int) $count > 0;
	}

	// -------------------------------------------------------------------------
	// Worker (BLOCK-4): drains the queue, time-budgeted, self-rescheduling.
	// -------------------------------------------------------------------------

	/**
	 * Schedule one worker pass: an Action Scheduler async action when
	 * present, else a WP-Cron single event 60s out. Guards duplicates by
	 * checking for a PENDING scheduled action specifically (BLOCK-5's fix
	 * applied here too, for the same reason) — `as_has_scheduled_action()`
	 * alone can keep matching a long-finished action depending on AS's
	 * internal status handling, which would permanently stop new work being
	 * scheduled after the very first run.
	 */
	private static function schedule_worker() {
		if ( self::has_pending_scheduled_action( self::WORKER_CRON_HOOK ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::WORKER_CRON_HOOK, array(), 'mcpwp' );
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::WORKER_CRON_HOOK ) ) {
			return;
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 60, self::WORKER_CRON_HOOK );
		}
	}

	/**
	 * True when $hook has a PENDING Action Scheduler action (never checks
	 * WP-Cron here — that branch is handled by its own wp_next_scheduled()
	 * call at each call site).
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	private static function has_pending_scheduled_action( $hook ) {
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => 'pending',
					'per_page' => 1,
				),
				'ids'
			);
			return ! empty( $pending );
		}
		return false;
	}

	/**
	 * Drain the queue, time-budgeted; reschedule itself while work remains.
	 * Once the queue is empty, advance a reconcile pass (if one is pending)
	 * within whatever budget is left.
	 */
	public static function run_worker_batch() {
		if ( ! self::ready() ) {
			return;
		}

		$start = microtime( true );
		self::$url_id_cache = array();
		self::$permalink_path_cache = array();

		while ( ( microtime( true ) - $start ) < self::WORKER_TIME_BUDGET ) {
			$batch = self::pop_queue_batch( self::WORKER_POP_BATCH );
			if ( empty( $batch ) ) {
				break;
			}
			foreach ( $batch as $node_key => $reason ) {
				self::rebuild_node_safely( $node_key, $reason );
				if ( ( microtime( true ) - $start ) >= self::WORKER_TIME_BUDGET ) {
					break;
				}
			}
		}

		if ( self::queue_has_rows() ) {
			self::schedule_worker();
			return;
		}

		self::maybe_run_reconcile_batch( $start );
	}

	/**
	 * Rebuild one node, logging and skipping on any throw — a filter/hook a
	 * theme or another plugin attaches to get_post_meta(), wp_get_object_terms()
	 * etc. must never stall a worker/backfill run for every OTHER post
	 * (BLOCK-3's "a throwing filter doesn't stall the backfill", applied
	 * uniformly everywhere a node gets rebuilt).
	 *
	 * N4a: a failure gets exactly ONE retry, not an infinite loop. A fresh
	 * failure (any $reason other than 'retry') re-marks the node with
	 * reason='retry' so the next worker pass (or the same one, if the
	 * re-mark's flush is reached before this pass ends) tries again once.
	 * A failure on a node ALREADY carrying reason='retry' is logged and
	 * dropped for good — whatever is wrong with it is not transient.
	 *
	 * @param string $node_key Node key.
	 * @param string $reason   The mark's reason, so a retry can be told from
	 *                         a fresh failure. '' (the default) for callers
	 *                         that never went through the queue at all —
	 *                         backfill and the authenticated-request
	 *                         carve-out — which still get exactly one retry
	 *                         via mark(), same as everything else.
	 */
	public static function rebuild_node_safely( $node_key, $reason = '' ) {
		try {
			self::rebuild_node( $node_key );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MCPWP graph rebuild error for ' . $node_key . ': ' . $e->getMessage() );
			}
			if ( 'retry' === $reason ) {
				if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'MCPWP graph rebuild for ' . $node_key . ' failed again on retry — giving up.' );
				}
				return;
			}
			self::mark( $node_key, 'retry' );
		}
	}

	/**
	 * Dispatch a rebuild by node key prefix.
	 *
	 * @param string $node_key Node key.
	 */
	public static function rebuild_node( $node_key ) {
		if ( 0 === strpos( $node_key, 'post:' ) ) {
			self::rebuild_post_node( (int) substr( $node_key, 5 ) );
			return;
		}
		if ( 0 === strpos( $node_key, 'term:' ) ) {
			$parts = explode( ':', $node_key, 3 );
			if ( 3 === count( $parts ) ) {
				self::rebuild_term_node( $parts[1], (int) $parts[2] );
			}
		}
	}

	/**
	 * `shutdown` handler — the ONE place a request's mark() calls turn into
	 * writes. For an MCPWP-authenticated request, up to REQUEST_REBUILD_LIMIT
	 * of THIS request's own marked nodes are rebuilt within
	 * REQUEST_REBUILD_BUDGET seconds first, so the agent that made the edit
	 * sees its own edges in the same response (BLOCK-4's carve-out) — those
	 * are rebuilt directly and never queued at all (already fully handled).
	 * Whatever is left (every other request's marks entirely, or the tail
	 * past the limit/budget for an authenticated one) is flushed to the
	 * queue table in one batched `INSERT IGNORE` and the worker is scheduled;
	 * the worker handles it async.
	 *
	 * W2: marks are grouped by the blog ID that was current when mark() was
	 * called, and each group is flushed AFTER switch_to_blog() into that
	 * blog — so a save made inside a switch_to_blog(2) block lands in blog
	 * 2's own queue table, never blog 1's (which is whatever blog happens to
	 * be current when shutdown itself runs). The REQUEST_REBUILD_LIMIT/
	 * BUDGET carve-out is shared across every blog group in this one
	 * request, not per-group.
	 */
	public static function on_shutdown() {
		if ( empty( self::$request_marked ) ) {
			self::$in_mcpwp_request = false; // N1: never carried into a later request on a persistent process.
			return;
		}

		$groups = self::$request_marked;
		self::$request_marked = array();

		$start           = microtime( true );
		$rebuilt_so_far  = 0;
		$current_blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$is_multisite    = function_exists( 'is_multisite' ) && is_multisite();

		foreach ( $groups as $blog_id => $marks ) {
			$blog_id = (int) $blog_id;
			$switched = $is_multisite && $blog_id !== $current_blog_id && function_exists( 'switch_to_blog' );
			if ( $switched ) {
				switch_to_blog( $blog_id );
			}

			if ( self::$in_mcpwp_request && $rebuilt_so_far < self::REQUEST_REBUILD_LIMIT ) {
				self::$url_id_cache = array();
				self::$permalink_path_cache = array();
				$batch = array_slice( array_keys( $marks ), 0, self::REQUEST_REBUILD_LIMIT - $rebuilt_so_far );
				foreach ( $batch as $node_key ) {
					if ( ( microtime( true ) - $start ) > self::REQUEST_REBUILD_BUDGET ) {
						break;
					}
					self::rebuild_node_safely( $node_key, $marks[ $node_key ] );
					unset( $marks[ $node_key ] ); // Already fully handled — no need to also queue it.
					$rebuilt_so_far++;
				}
			}

			// N3(a) (#943 S1 review round 3): rebuild_node_safely() calls
			// mark() again, with reason='retry', when the rebuild it just
			// ran above throws. That mark() call writes into
			// self::$request_marked — which this method already reset to
			// empty at its own top, and is NOT the same variable as the
			// local $marks this loop is about to flush. Left alone, a retry
			// mark made during the rebuild loop just above is buffered,
			// then simply dropped: nothing after this point ever looks at
			// self::$request_marked again, so it survives until the NEXT
			// shutdown at the earliest — and if this was itself the last
			// mark() call of the whole request, there IS no next shutdown.
			// get_current_blog_id() at retry-mark time is still this same
			// $blog_id (we are switched into it for the whole rebuild
			// loop), so folding it straight into $marks before the flush
			// below is correct, not a guess.
			if ( ! empty( self::$request_marked[ $blog_id ] ) ) {
				$marks = array_merge( $marks, self::$request_marked[ $blog_id ] );
				unset( self::$request_marked[ $blog_id ] );
			}

			self::flush_marks_to_queue( $marks );

			if ( $switched ) {
				restore_current_blog();
			}
		}

		self::$in_mcpwp_request = false; // N1: reset at shutdown, same as reset_for_new_request() does at `init`.
	}

	// -------------------------------------------------------------------------
	// Node keys
	// -------------------------------------------------------------------------

	/**
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function node_key_for_post( $post_id ) {
		return 'post:' . (int) $post_id;
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @return string
	 */
	public static function node_key_for_term( $taxonomy, $term_id ) {
		return 'term:' . sanitize_key( (string) $taxonomy ) . ':' . (int) $term_id;
	}

	/**
	 * @param int $media_id Attachment ID.
	 * @return string
	 */
	public static function node_key_for_media( $media_id ) {
		return 'media:' . (int) $media_id;
	}

	// -------------------------------------------------------------------------
	// Post node rebuild
	// -------------------------------------------------------------------------

	/**
	 * Rebuild (or delete, or trash-mark) the node + builder-sourced edges for
	 * one post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function rebuild_post_node( $post_id ) {
		$post_id  = (int) $post_id;
		$node_key = self::node_key_for_post( $post_id );
		$post     = get_post( $post_id );

		if ( ! $post || 'auto-draft' === $post->post_status ) {
			self::delete_node_and_edges( $node_key );
			return;
		}

		if ( ! self::is_covered_post_type( $post->post_type ) ) {
			self::delete_node_and_edges( $node_key );
			return;
		}

		// BLOCK-2: trash is not delete. The node's OWN fields (including its
		// content_hash, so a later untrash's hash-compare stays correct — see
		// rebuild_trashed_node()) are kept current, but every edge is left
		// alone; readers filter by status.
		if ( 'trash' === $post->post_status ) {
			self::rebuild_trashed_node( $post, $node_key );
			return;
		}

		self::full_rebuild_post_node( $post, $node_key );
	}

	/**
	 * Upsert a trashed post's node fields (status='trash', and every other
	 * field a full rebuild would also write, including content_hash) WITHOUT
	 * touching any edge.
	 *
	 * content_hash must still be kept current here: skipping it would leave
	 * the stored hash reflecting the pre-trash (non-trash) state, and a
	 * later untrash — restoring the post to that same non-trash state with
	 * nothing else changed — would then wrongly hash-match and skip the
	 * rebuild that is supposed to flip status back and (if anything else
	 * changed while trashed) resync edges.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $node_key Node key.
	 */
	private static function rebuild_trashed_node( $post, $node_key ) {
		$post_id  = (int) $post->ID;
		$term_refs = self::extract_term_refs( $post );

		$props = array(
			'template'     => (string) ( function_exists( 'get_page_template_slug' ) ? get_page_template_slug( $post_id ) : '' ),
			'builder'      => self::detect_builder( $post_id, $post ),
			'lang'         => '',
			'parent'       => (int) $post->post_parent,
			'modified_gmt' => get_post_modified_time( 'c', true, $post ),
		);

		self::upsert_node(
			array(
				'node_key'     => $node_key,
				'kind'         => $post->post_type,
				'ref_id'       => $post_id,
				'label'        => get_the_title( $post ),
				'url'          => (string) get_permalink( $post ),
				'status'       => 'trash',
				'content_hash' => self::content_hash( $post, $term_refs ),
				'word_count'   => str_word_count( wp_strip_all_tags( (string) $post->post_content ) ),
				'props'        => wp_json_encode( $props ),
			)
		);
	}

	/**
	 * The full rebuild: change-hash skip, node upsert, and builder-owned edge
	 * replacement (links_to/in_term/uses_media/parent_of).
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $node_key Node key.
	 */
	private static function full_rebuild_post_node( $post, $node_key ) {
		$post_id = (int) $post->ID;

		$term_refs = self::extract_term_refs( $post );
		$new_hash  = self::content_hash( $post, $term_refs );

		$existing_hash = self::read_node_hash( $node_key );
		if ( null !== $existing_hash && $existing_hash === $new_hash ) {
			return; // Nothing that would change any written field.
		}

		$props = array(
			'template'     => (string) ( function_exists( 'get_page_template_slug' ) ? get_page_template_slug( $post_id ) : '' ),
			'builder'      => self::detect_builder( $post_id, $post ),
			'lang'         => '',
			'parent'       => (int) $post->post_parent,
			'modified_gmt' => get_post_modified_time( 'c', true, $post ),
		);

		self::upsert_node(
			array(
				'node_key'     => $node_key,
				'kind'         => $post->post_type,
				'ref_id'       => $post_id,
				'label'        => get_the_title( $post ),
				'url'          => (string) get_permalink( $post ),
				'status'       => $post->post_status,
				'content_hash' => $new_hash,
				'word_count'   => str_word_count( wp_strip_all_tags( (string) $post->post_content ) ),
				'props'        => wp_json_encode( $props ),
			)
		);

		self::delete_builder_edges_for_node( $node_key );

		$edge_rows = array();
		$now       = current_time( 'mysql' );

		// BLOCK-4 perf: one batched resolver call for every link on the post,
		// not one query per link. N4: anchors (<=5) + location kept as props.
		foreach ( self::build_link_edges_data( $post ) as $target_id => $data ) {
			$edge_rows[] = array(
				'from_key' => $node_key,
				'to_key'   => self::node_key_for_post( $target_id ),
				'type'     => 'links_to',
				'weight'   => 1.0,
				'source'   => 'builder',
				'props'    => array(
					'anchors'   => $data['anchors'],
					'locations' => array_keys( $data['locations'] ),
				),
			);
		}

		foreach ( $term_refs as $term_ref ) {
			self::ensure_term_node( $term_ref['taxonomy'], $term_ref['term_id'], $term_ref['name'] );
			$edge_rows[] = array(
				'from_key' => $node_key,
				'to_key'   => self::node_key_for_term( $term_ref['taxonomy'], $term_ref['term_id'] ),
				'type'     => 'in_term',
				'weight'   => 1.0,
				'source'   => 'builder',
				'props'    => array(),
			);
		}

		foreach ( self::extract_media_refs( $post ) as $media_id ) {
			self::ensure_media_node( $media_id );
			$edge_rows[] = array(
				'from_key' => $node_key,
				'to_key'   => self::node_key_for_media( $media_id ),
				'type'     => 'uses_media',
				'weight'   => 1.0,
				'source'   => 'builder',
				'props'    => array(),
			);
		}

		if ( $post->post_parent && (int) $post->post_parent !== $post_id ) {
			$edge_rows[] = array(
				'from_key' => self::node_key_for_post( (int) $post->post_parent ),
				'to_key'   => $node_key,
				'type'     => 'parent_of',
				'weight'   => 1.0,
				'source'   => 'builder',
				'props'    => array(),
			);
		}

		self::insert_edges_batch( $edge_rows, $now );
	}

	/**
	 * Rebuild (or delete) a term node. Term nodes carry no outgoing builder
	 * edges of their own — the in_term edges that reference them are owned
	 * by each post's rebuild.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 */
	public static function rebuild_term_node( $taxonomy, $term_id ) {
		$node_key = self::node_key_for_term( $taxonomy, $term_id );
		$term     = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			self::delete_node_and_edges( $node_key );
			return;
		}

		self::upsert_node(
			array(
				'node_key'     => $node_key,
				'kind'         => 'term',
				'ref_id'       => $term_id,
				'label'        => (string) $term->name,
				'url'          => (string) get_term_link( $term, $taxonomy ),
				'status'       => '',
				'content_hash' => '',
				'word_count'   => 0,
				'props'        => wp_json_encode( array( 'taxonomy' => $taxonomy ) ),
			)
		);
	}

	/**
	 * Ensure a term node exists with its real, current label/url. N3: this
	 * used to write url='' unconditionally, which meant every post that
	 * referenced the term CLOBBERED whatever real permalink
	 * rebuild_term_node() (or an earlier ensure) had written. Now computes
	 * the same get_term_link() every time, so it is idempotent instead of
	 * destructive.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @param string $name     Term name.
	 */
	private static function ensure_term_node( $taxonomy, $term_id, $name ) {
		$link = get_term_link( (int) $term_id, $taxonomy );
		self::upsert_node(
			array(
				'node_key'     => self::node_key_for_term( $taxonomy, $term_id ),
				'kind'         => 'term',
				'ref_id'       => $term_id,
				'label'        => (string) $name,
				'url'          => is_wp_error( $link ) ? '' : (string) $link,
				'status'       => '',
				'content_hash' => '',
				'word_count'   => 0,
				'props'        => wp_json_encode( array( 'taxonomy' => $taxonomy ) ),
			)
		);
	}

	/**
	 * Ensure a lightweight media node exists.
	 *
	 * @param int $media_id Attachment ID.
	 */
	private static function ensure_media_node( $media_id ) {
		$media_id = (int) $media_id;
		if ( ! self::is_attachment_id( $media_id ) ) {
			return; // W3: never label a media: node with a non-attachment's title.
		}
		$title    = function_exists( 'get_the_title' ) ? get_the_title( $media_id ) : '';
		$url      = function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( $media_id ) : '';

		self::upsert_node(
			array(
				'node_key'     => self::node_key_for_media( $media_id ),
				'kind'         => 'media',
				'ref_id'       => $media_id,
				'label'        => (string) $title,
				'url'          => $url,
				'status'       => '',
				'content_hash' => '',
				'word_count'   => 0,
				'props'        => wp_json_encode( array() ),
			)
		);
	}

	/**
	 * Which builder produced a post's content, for `props.builder`.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post, when already loaded.
	 * @return string elementor|gutenberg|classic
	 */
	private static function detect_builder( $post_id, $post = null ) {
		if ( self::is_elementor_built( $post_id ) ) {
			return 'elementor';
		}
		$post = $post ?: get_post( $post_id );
		if ( $post && function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
			return 'gutenberg';
		}
		return 'classic';
	}

	// -------------------------------------------------------------------------
	// Hashing (BLOCK-1): everything the rebuild writes, in one hash.
	// -------------------------------------------------------------------------

	/**
	 * sha1 over every input the rebuild's OWN writes depend on: post_content
	 * raw, `_elementor_data` raw, title, status, slug, parent, `_thumbnail_id`,
	 * `_wp_page_template`, menu_order, and the sorted term ids. A change to
	 * any of these — and only these — is what a rebuild would ever act on, so
	 * this single hash is the correct and complete "did anything change?"
	 * signal; there is no separate terms_hash any more.
	 *
	 * @param WP_Post    $post      Post.
	 * @param array|null $term_refs Pre-computed extract_term_refs() result, to
	 *                              avoid a second lookup when the caller
	 *                              already has it.
	 * @return string
	 */
	public static function content_hash( $post, $term_refs = null ) {
		$post_id       = (int) $post->ID;
		$elementor_raw = (string) get_post_meta( $post_id, '_elementor_data', true );
		$thumbnail_id  = (string) get_post_meta( $post_id, '_thumbnail_id', true );
		$page_template = (string) get_post_meta( $post_id, '_wp_page_template', true );

		if ( null === $term_refs ) {
			$term_refs = self::extract_term_refs( $post );
		}
		$term_ids = array();
		foreach ( $term_refs as $ref ) {
			$term_ids[] = (int) $ref['term_id'];
		}
		sort( $term_ids );

		$parts = array(
			(string) $post->post_content,
			$elementor_raw,
			(string) $post->post_title,
			(string) $post->post_status,
			(string) $post->post_name,
			(string) $post->post_parent,
			$thumbnail_id,
			$page_template,
			(string) $post->menu_order,
			implode( ',', $term_ids ),
			'resolver:' . self::LINK_RESOLVER_VERSION,
		);

		// \x1f (unit separator) delimits fields so no concatenation of two
		// different field splits can collide onto the same hash.
		return sha1( implode( "\x1f", $parts ) );
	}

	/**
	 * Read a node's stored content_hash, or null when the node does not
	 * exist yet.
	 *
	 * @param string $node_key Node key.
	 * @return string|null
	 */
	private static function read_node_hash( $node_key ) {
		global $wpdb;
		$table = self::nodes_table();

		$hash = self::run_suppressed(
			function () use ( $wpdb, $table, $node_key ) {
				return $wpdb->get_var( $wpdb->prepare( "SELECT content_hash FROM {$table} WHERE node_key = %s", $node_key ) );
			}
		);

		return null === $hash ? null : (string) $hash;
	}

	// -------------------------------------------------------------------------
	// Extraction — moved from class-mcpwp-rest-content-graph.php /
	// class-mcpwp-rest-api.php. Bodies unchanged; see class docblock.
	// -------------------------------------------------------------------------

	/**
	 * Parse requested graph post types and keep only public/queryable types.
	 *
	 * @param string $post_types Raw comma-separated post types.
	 * @return array Post types.
	 */
	public static function parse_graph_post_types( $post_types ) {
		$requested = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $post_types ) ) ) );
		if ( empty( $requested ) ) {
			$requested = array( 'page', 'post' );
		}

		$allowed = array();
		foreach ( $requested as $post_type ) {
			$obj = get_post_type_object( $post_type );
			if ( $obj && ( ! empty( $obj->public ) || ! empty( $obj->publicly_queryable ) ) ) {
				$allowed[] = $post_type;
			}
		}

		return empty( $allowed ) ? array( 'page', 'post' ) : array_values( array_unique( $allowed ) );
	}

	/**
	 * Get graph-safe term names for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array Term names.
	 */
	public static function get_graph_term_names( $post ) {
		$names      = array();
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$term_names = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			if ( is_wp_error( $term_names ) || empty( $term_names ) ) {
				continue;
			}

			$names = array_merge( $names, $term_names );
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );
	}

	/**
	 * Get post IDs included in navigation menus.
	 *
	 * @return array Menu post IDs.
	 */
	public static function get_menu_post_ids() {
		return array_keys( self::get_menu_post_depths() );
	}

	/**
	 * Get post IDs included in navigation menus with shallowest menu depth.
	 *
	 * @return array Post ID to depth map.
	 */
	public static function get_menu_post_depths() {
		$depths    = array();
		$locations = get_nav_menu_locations();

		foreach ( $locations as $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );
			if ( empty( $items ) || is_wp_error( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( 'post_type' === $item->type && ! empty( $item->object_id ) ) {
					$depth   = self::get_menu_item_depth( $item, $items );
					$post_id = (int) $item->object_id;

					if ( ! isset( $depths[ $post_id ] ) || $depth < $depths[ $post_id ] ) {
						$depths[ $post_id ] = $depth;
					}
				}
			}
		}

		return $depths;
	}

	/**
	 * Get depth for a nav menu item.
	 *
	 * @param WP_Post $item  Menu item.
	 * @param array   $items Menu items.
	 * @return int Depth.
	 */
	public static function get_menu_item_depth( $item, $items ) {
		$parent_id = (int) $item->menu_item_parent;
		$depth     = 0;
		$seen      = array();

		while ( $parent_id > 0 && ! isset( $seen[ $parent_id ] ) ) {
			$seen[ $parent_id ] = true;
			$depth++;
			$next_parent = 0;

			foreach ( $items as $candidate ) {
				if ( (int) $candidate->ID === $parent_id ) {
					$next_parent = (int) $candidate->menu_item_parent;
					break;
				}
			}

			$parent_id = $next_parent;
		}

		return $depth;
	}

	/**
	 * Calculate freshness score from age in days.
	 *
	 * @param int|null $freshness_days Days since modified.
	 * @return float Score from 0-1.
	 */
	public static function calculate_freshness_score( $freshness_days ) {
		if ( null === $freshness_days ) {
			return 0.0;
		}

		if ( $freshness_days <= 30 ) {
			return 1.0;
		}

		if ( $freshness_days >= 730 ) {
			return 0.0;
		}

		return round( max( 0, 1 - ( ( $freshness_days - 30 ) / 700 ) ), 3 );
	}

	/**
	 * Calculate a simple hub score for graph nodes.
	 *
	 * @param array $node Graph node.
	 * @return float Score.
	 */
	public static function calculate_hub_score( $node ) {
		$score = ( (int) $node['inbound_count'] * 2 ) + (int) $node['outbound_count'];

		if ( ! empty( $node['in_menu'] ) ) {
			$score += null === $node['menu_depth'] ? 2 : max( 1, 3 - (int) $node['menu_depth'] );
		}

		if ( ! empty( $node['parent_id'] ) ) {
			$score += 1;
		}

		$score += min( 3, count( $node['terms'] ) );

		return round( $score, 3 );
	}

	/**
	 * Calculate orphan severity for nodes.
	 *
	 * @param array $node Graph node.
	 * @return string Severity.
	 */
	public static function calculate_orphan_severity( $node ) {
		if ( ! empty( $node['in_menu'] ) || (int) $node['inbound_count'] > 0 ) {
			return 'none';
		}

		if ( 'page' === $node['type'] && (int) $node['word_count'] >= 300 ) {
			return 'high';
		}

		if ( (int) $node['word_count'] >= 150 ) {
			return 'medium';
		}

		return 'low';
	}

	/**
	 * Calculate a compact PageRank-style score for content-link edges.
	 *
	 * @param array $node_ids Node IDs.
	 * @param array $edges    Graph edges.
	 * @return array Scores.
	 */
	public static function calculate_graph_page_rank( $node_ids, $edges ) {
		$count = count( $node_ids );
		if ( 0 === $count ) {
			return array();
		}

		$ranks    = array_fill_keys( $node_ids, 1 / $count );
		$outgoing = array_fill_keys( $node_ids, array() );

		foreach ( $edges as $edge ) {
			if ( 'content_link' !== $edge['type'] || ! array_key_exists( $edge['from'], $outgoing ) ) {
				continue;
			}

			if ( isset( $outgoing[ $edge['from'] ], $ranks[ $edge['to'] ] ) ) {
				$outgoing[ $edge['from'] ][] = (int) $edge['to'];
			}
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$next = array_fill_keys( $node_ids, ( 1 - 0.85 ) / $count );

			foreach ( $node_ids as $node_id ) {
				$targets = array_values( array_unique( $outgoing[ $node_id ] ) );
				if ( empty( $targets ) ) {
					continue;
				}

				$share = ( $ranks[ $node_id ] * 0.85 ) / count( $targets );
				foreach ( $targets as $target_id ) {
					$next[ $target_id ] += $share;
				}
			}

			$ranks = $next;
		}

		foreach ( $ranks as $id => $score ) {
			$ranks[ $id ] = round( $score, 6 );
		}

		return $ranks;
	}

	/**
	 * Extract heading texts from block/raw content.
	 *
	 * @param string $content Post content.
	 * @return array Heading texts.
	 */
	public static function extract_heading_texts( $content ) {
		$headings = array();

		if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$text = trim( wp_strip_all_tags( $match[2] ) );
				if ( '' !== $text ) {
					$headings[] = array(
						'level' => (int) $match[1],
						'text'  => $text,
					);
				}
			}
		}

		return $headings;
	}

	/**
	 * Normalize internal URLs for graph lookup.
	 *
	 * @param string $url URL.
	 * @return string Normalized URL.
	 */
	public static function normalize_internal_graph_url( $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return strtolower( $path . $query );
	}

	/**
	 * Resolve an href to an internal post link, or null if external/non-content.
	 *
	 * @param string $href      Raw href value.
	 * @param string $anchor    Link anchor text.
	 * @param array  $url_to_id Normalized URL to post ID map.
	 * @return array|null
	 */
	public static function resolve_internal_graph_link( $href, $anchor, $url_to_id ) {
		$href = trim( $href );
		if ( '' === $href || 0 === strpos( $href, '#' ) || preg_match( '/^(mailto|tel|sms|javascript):/i', $href ) ) {
			return null;
		}

		$absolute = wp_http_validate_url( $href ) ? $href : home_url( $href );
		$home     = wp_parse_url( home_url() );
		$target   = wp_parse_url( $absolute );

		if ( empty( $target['host'] ) || empty( $home['host'] ) || strtolower( $target['host'] ) !== strtolower( $home['host'] ) ) {
			return null;
		}

		$normalized = self::normalize_internal_graph_url( $absolute );
		$target_id  = isset( $url_to_id[ $normalized ] ) ? (int) $url_to_id[ $normalized ] : url_to_postid( $absolute );

		if ( ! $target_id ) {
			return null;
		}

		return array(
			'target_id' => $target_id,
			'url'       => $absolute,
			'anchor'    => trim( $anchor ),
		);
	}

	/**
	 * Extract all internal links from post content.
	 *
	 * @param string $content   Post content HTML.
	 * @param array  $url_to_id Normalized URL to post ID map.
	 * @return array Links.
	 */
	public static function extract_internal_links_from_content( $content, $url_to_id ) {
		$links = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$link = self::resolve_internal_graph_link( $match[1], wp_strip_all_tags( $match[2] ), $url_to_id );
				if ( $link ) {
					$links[] = $link;
				}
			}
		}

		return $links;
	}

	/**
	 * Extract internal links embedded in an Elementor page's `_elementor_data`.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $url_to_id Normalized URL to post ID map.
	 * @return array Links.
	 */
	public static function extract_elementor_links_from_postmeta( $post_id, $url_to_id ) {
		$data = Mcpwp_Elementor_Stored_Tree::of_post( $post_id )['elements'];
		if ( empty( $data ) ) {
			return array();
		}

		$hrefs = array();
		self::collect_elementor_link_hrefs( $data, $hrefs );

		$links = array();
		foreach ( array_unique( $hrefs ) as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $href ) {
				continue;
			}
			$link = self::resolve_internal_graph_link( $href, '', $url_to_id );
			if ( $link ) {
				$links[] = $link;
			}
		}

		return $links;
	}

	/**
	 * Recursively collect link hrefs from an Elementor elements tree.
	 *
	 * @param array $elements Elementor elements array.
	 * @param array $hrefs    Accumulator passed by reference.
	 */
	public static function collect_elementor_link_hrefs( $elements, &$hrefs ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			if ( ! empty( $settings['link']['url'] ) && is_string( $settings['link']['url'] ) ) {
				$hrefs[] = $settings['link']['url'];
			}

			if ( ! empty( $settings['url']['url'] ) && is_string( $settings['url']['url'] ) ) {
				$hrefs[] = $settings['url']['url'];
			}

			foreach ( array( 'editor', 'html', 'content', 'inner_text' ) as $field ) {
				if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
					if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $settings[ $field ], $matches ) ) {
						foreach ( $matches[1] as $href ) {
							$hrefs[] = $href;
						}
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_elementor_link_hrefs( $element['elements'], $hrefs );
			}
		}
	}

	/**
	 * Whether an href points at this site, regardless of whether it resolves
	 * to a post. See class-mcpwp-rest-api.php's original docblock for the
	 * classification rationale (moved verbatim).
	 *
	 * @param string $href Raw href value.
	 * @return bool
	 */
	public static function is_same_host_href( $href ) {
		$href = trim( (string) $href );

		if ( '' === $href || 0 === strpos( $href, '#' )
			|| preg_match( '/^(mailto|tel|sms|javascript|data):/i', $href ) ) {
			return false;
		}

		$home = wp_parse_url( home_url() );
		if ( empty( $home['host'] ) ) {
			return false;
		}

		if ( 0 === strpos( $href, '//' ) ) {
			$target = wp_parse_url( 'https:' . $href );
		} elseif ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ) {
			$target = wp_parse_url( $href );
		} else {
			return true;
		}

		if ( empty( $target['host'] ) ) {
			return false;
		}

		return strtolower( $target['host'] ) === strtolower( $home['host'] );
	}

	/**
	 * Count links on a post that point at this site.
	 *
	 * @param WP_Post $post Post object.
	 * @return int Number of distinct same-host hrefs.
	 */
	public static function count_same_host_outbound_links( $post ) {
		$hrefs = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', (string) $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$hrefs[] = $href;
			}
		}

		$data = Mcpwp_Elementor_Stored_Tree::of_post( (int) $post->ID )['elements'];
		if ( ! empty( $data ) ) {
			self::collect_elementor_link_hrefs( $data, $hrefs );
		}

		$count = 0;
		foreach ( array_unique( $hrefs ) as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( self::is_same_host_href( $href ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Extract links from Elementor widget data in {href, anchor, source}
	 * format (moved from class-mcpwp-rest-content-graph.php, used by
	 * validate_internal_links — distinct from
	 * extract_elementor_links_from_postmeta()/collect_elementor_link_hrefs()
	 * above, which resolve against a url_to_id map instead of tagging source).
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Array of {href, anchor, source} records.
	 */
	public static function extract_raw_links_from_elementor( $post_id ) {
		$data = Mcpwp_Elementor_Stored_Tree::of_post( $post_id )['elements'];
		if ( empty( $data ) ) {
			return array();
		}

		$links = array();
		self::collect_elementor_raw_links( $data, $links );

		$seen  = array();
		$dedup = array();
		foreach ( $links as $link ) {
			$key = strtolower( trim( $link['href'] ) );
			if ( '' !== $key && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$dedup[]      = $link;
			}
		}
		return $dedup;
	}

	/**
	 * Recursively collect {href, anchor, source} records from Elementor elements.
	 *
	 * @param array $elements Elementor elements array.
	 * @param array $links    Accumulator passed by reference.
	 */
	public static function collect_elementor_raw_links( $elements, &$links ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			foreach ( array( 'link', 'url' ) as $url_key ) {
				if ( ! empty( $settings[ $url_key ]['url'] ) && is_string( $settings[ $url_key ]['url'] ) ) {
					$anchor  = trim( wp_strip_all_tags( (string) ( $settings['text'] ?? $settings['button_text'] ?? $settings['title'] ?? '' ) ) );
					$links[] = array(
						'href'   => $settings[ $url_key ]['url'],
						'anchor' => $anchor,
						'source' => 'elementor',
					);
				}
			}

			foreach ( array( 'editor', 'html', 'content', 'inner_text' ) as $field ) {
				if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
					if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $settings[ $field ], $matches, PREG_SET_ORDER ) ) {
						foreach ( $matches as $m ) {
							$links[] = array(
								'href'   => trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ),
								'anchor' => trim( wp_strip_all_tags( $m[2] ) ),
								'source' => 'elementor',
							);
						}
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_elementor_raw_links( $element['elements'], $links );
			}
		}
	}

	/**
	 * Whether a post is built with Elementor.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_elementor_built( $post_id ) {
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return true;
		}
		return ! empty( get_post_meta( $post_id, '_elementor_data', true ) );
	}

	// -------------------------------------------------------------------------
	// New extraction for the persisted graph (no REST-time equivalent existed).
	// -------------------------------------------------------------------------

	/**
	 * Gather every raw {href, anchor, source} link on a post (content +
	 * Elementor), unresolved.
	 *
	 * @param WP_Post $post Post.
	 * @return array[]
	 */
	private static function collect_post_raw_links( $post ) {
		$links = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', (string) $post->post_content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$links[] = array(
					'href'   => trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ),
					'anchor' => trim( wp_strip_all_tags( $m[2] ) ),
					'source' => 'content',
				);
			}
		}

		return array_merge( $links, self::extract_raw_links_from_elementor( (int) $post->ID ) );
	}

	/**
	 * BLOCK-4 perf: resolve every href on a post to a target post ID with ONE
	 * batched query for the common cases (plus, at most,
	 * URL_TO_POSTID_FALLBACK_CAP one-off fallbacks for genuinely ambiguous
	 * links) — not one query per link. Populates the per-run cache
	 * (self::$url_id_cache).
	 *
	 * BLOCK-2 (S1 review round 2): the previous version matched a URL's LAST
	 * path segment against ANY post_name across every post type, which gave
	 * wrong edges whenever that slug was not unique — `/services/seo/`
	 * resolving to an unrelated blog post named "seo" instead of the nested
	 * page actually at that path, or `/category/news/` resolving to a post
	 * named "news" instead of not being a post at all. Fixed by:
	 *   - matching the FULL path for hierarchical types via get_page_by_path()
	 *     (which walks the parent chain), not just the last segment;
	 *   - treating known rewrite bases (category/tag base, author, feed,
	 *     `page/<n>` pagination, a bare date archive, `?s=` search) as not a
	 *     post at all, before ever querying;
	 *   - sending anything still ambiguous (more than one covered-type
	 *     candidate for a slug/path) to the url_to_postid() fallback instead
	 *     of guessing;
	 *   - spending that fallback only on same-host, non-excluded links.
	 *
	 * Resolution order per href:
	 *   1. Not same-host, or a recognised rewrite-base/search path -> 0
	 *      (definitively not a post; never spends fallback budget).
	 *   2. Already cached (this run) -> cached value.
	 *   3. `?p=<id>` / `?page_id=<id>` -> a direct candidate ID.
	 *   4. A single-segment path -> a `post_name` candidate; resolved only
	 *      when exactly one covered-type row matches.
	 *   5. A multi-segment path -> the last segment is a candidate;
	 *      resolved via get_page_by_path() against the FULL path when
	 *      exactly one hierarchical-type row matches it (and that lookup
	 *      agrees), or directly when exactly one NON-hierarchical row
	 *      matches and no hierarchical one does (e.g. a `%category%/
	 *      %postname%/` permalink structure).
	 *   6. Anything still unresolved (ambiguous, or no candidate at all)
	 *      falls back to core's url_to_postid(), capped at
	 *      URL_TO_POSTID_FALLBACK_CAP per call, same-host/non-excluded links
	 *      only — a documented limit past which a link is simply not
	 *      resolved (no edge) rather than costing one query per remaining
	 *      link.
	 *
	 * @param array<string> $hrefs Raw hrefs.
	 * @return array<string,int> href => target post ID, or 0 when unresolved
	 *                            / external / excluded / not a covered type.
	 */
	private static function resolve_hrefs_for_post( array $hrefs ) {
		global $wpdb;

		$results          = array();
		$direct_ids       = array(); // normalized => int candidate ID
		$simple_slugs     = array(); // slug => [normalized, ...]                 (single path segment)
		$nested_paths     = array(); // normalized => {path, last_slug}          (2+ path segments)
		$candidate_href   = array(); // normalized => one representative raw href
		$compare_path     = array(); // normalized => lowercased path, no query/page suffix (#1000)
		$fallback_eligible = array(); // normalized => true — only genuine ambiguity/no-match, never an exclusion
		$fallback_nested   = array(); // normalized => true — nested paths; first in line for the capped fallback (#1000)
		$fallback_low      = array(); // normalized => true — a lone slug match whose permalink differs (#1000); spends the budget last

		foreach ( $hrefs as $href ) {
			$href = trim( (string) $href );
			if ( '' === $href || ! self::is_same_host_href( $href ) ) {
				$results[ $href ] = 0;
				continue;
			}

			$absolute   = wp_http_validate_url( $href ) ? $href : home_url( $href );
			$normalized = self::normalize_internal_graph_url( $absolute );

			if ( isset( self::$url_id_cache[ $normalized ] ) ) {
				$results[ $href ] = self::$url_id_cache[ $normalized ];
				continue;
			}

			$candidate_href[ $normalized ] = $href;

			$parts = wp_parse_url( $absolute );
			$query = array();
			if ( ! empty( $parts['query'] ) ) {
				parse_str( (string) $parts['query'], $query );
			}

			// A search query is never a post, whatever the path.
			if ( isset( $query['s'] ) ) {
				self::$url_id_cache[ $normalized ] = 0;
				$results[ $href ] = 0;
				unset( $candidate_href[ $normalized ] );
				continue;
			}

			if ( ! empty( $query['p'] ) && is_numeric( $query['p'] ) ) {
				$direct_ids[ $normalized ] = (int) $query['p'];
				continue;
			}
			if ( ! empty( $query['page_id'] ) && is_numeric( $query['page_id'] ) ) {
				$direct_ids[ $normalized ] = (int) $query['page_id'];
				continue;
			}

			$path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';

			// N2 (#943 S1 review round 3): a trailing /page/N/ is WordPress's
			// pagination suffix riding on an otherwise real path — a page
			// with children (/blog/news/page/2/) or a paginated singular.
			// The path IN FRONT of it still names a real object; discarding
			// the whole href instead of resolving what is left loses a
			// genuine edge for no reason. Strip it and resolve the
			// remainder normally. /page/N/ with nothing in front of it has
			// nothing left after stripping and correctly falls through to
			// the empty-path branch just below (still not a post).
			$path = (string) preg_replace( '#(^|/)page/\d+$#', '', $path );

			if ( '' === $path || self::is_excluded_rewrite_path( $path ) ) {
				self::$url_id_cache[ $normalized ] = 0;
				$results[ $href ] = 0;
				unset( $candidate_href[ $normalized ] );
				continue;
			}

			$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
			if ( empty( $segments ) ) {
				self::$url_id_cache[ $normalized ] = 0;
				$results[ $href ] = 0;
				unset( $candidate_href[ $normalized ] );
				continue;
			}

			$last_slug = sanitize_title( (string) end( $segments ) );
			if ( '' === $last_slug ) {
				self::$url_id_cache[ $normalized ] = 0;
				$results[ $href ] = 0;
				unset( $candidate_href[ $normalized ] );
				continue;
			}

			// #1000: what a candidate's own permalink is compared with is the
			// href's PATH. A ?query, a #fragment or a /page/N/ suffix is not
			// part of any post's permalink, so comparing the whole
			// normalized key would send every such link to the fallback.
			$compare_path[ $normalized ] = '/' . strtolower( $path );

			if ( 1 === count( $segments ) ) {
				$simple_slugs[ $last_slug ][] = $normalized;
			} else {
				$nested_paths[ $normalized ] = array(
					'path'      => $path,
					'last_slug' => $last_slug,
				);
			}
		}

		// One batched query for every direct ID + every last-segment slug
		// needed (single-segment candidates AND the coarse candidate filter
		// for nested paths — the parent-chain check for those happens after,
		// only for slugs this query actually found a hierarchical match for).
		$id_list = array_values( array_unique( array_map( 'intval', $direct_ids ) ) );
		$slugs   = array_values( array_unique( array_merge( array_keys( $simple_slugs ), wp_list_pluck( $nested_paths, 'last_slug' ) ) ) );

		$by_id   = array();
		$by_slug = array();

		if ( ! empty( $id_list ) || ! empty( $slugs ) ) {
			$where = array();
			$args  = array();

			if ( ! empty( $id_list ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $id_list ), '%d' ) );
				$where[]      = "ID IN ($placeholders)";
				$args          = array_merge( $args, $id_list );
			}
			if ( ! empty( $slugs ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );
				$where[]      = "post_name IN ($placeholders)";
				$args          = array_merge( $args, $slugs );
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built with placeholders only, prepared below.
			$sql = "SELECT ID, post_name, post_type, post_parent FROM {$wpdb->posts} WHERE (" . implode( ' OR ', $where ) . ") AND post_status != 'auto-draft'";

			$rows = self::run_suppressed(
				function () use ( $wpdb, $sql, $args ) {
					return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
				}
			);

			foreach ( (array) $rows as $row ) {
				$by_id[ (int) $row['ID'] ]     = $row;
				$by_slug[ $row['post_name'] ][] = $row;
			}
		}

		foreach ( $direct_ids as $normalized => $id ) {
			$row      = $by_id[ $id ] ?? null;
			$resolved = ( $row && self::is_covered_post_type( $row['post_type'] ) ) ? $id : 0;
			self::$url_id_cache[ $normalized ] = $resolved;
			$results[ $candidate_href[ $normalized ] ] = $resolved;
		}

		// #1000: every candidate whose permalink is about to be compared,
		// primed in one batch (posts, their meta and terms) so
		// get_permalink() does not cost a query or three per candidate.
		$permalink_ids = array();
		foreach ( $simple_slugs as $slug => $normalized_keys ) {
			$only = array_values( array_filter( $by_slug[ $slug ] ?? array(), array( __CLASS__, 'is_covered_post_type_row' ) ) );
			if ( 1 === count( $only ) ) {
				$permalink_ids[] = (int) $only[0]['ID'];
			}
		}
		foreach ( $nested_paths as $info ) {
			$only = array_values( array_filter( $by_slug[ $info['last_slug'] ] ?? array(), array( __CLASS__, 'is_covered_post_type_row' ) ) );
			if ( 1 === count( $only ) ) {
				$permalink_ids[] = (int) $only[0]['ID'];
			}
		}
		self::prime_permalink_candidates( $permalink_ids );

		// Single-segment paths: resolve ONLY when exactly one covered-type
		// row shares that slug. More than one -> genuinely ambiguous ->
		// fallback; that is precisely BLOCK-2's bug when left unresolved.
		foreach ( $simple_slugs as $slug => $normalized_keys ) {
			$matches = array_values( array_filter( $by_slug[ $slug ] ?? array(), array( __CLASS__, 'is_covered_post_type_row' ) ) );
			$candidate = 1 === count( $matches ) ? (int) $matches[0]['ID'] : 0;

			// #1000: the slug matching is not evidence the href names that
			// post. With a permalink structure that prefixes the slug
			// (/%year%/%monthnum%/%postname%/), /news/ shares a slug with a
			// post whose URL is /2026/09/news/. Apply the same check the
			// nested-path branch uses, per href: the candidate's own
			// permalink path must equal the href's path.
			//
			// A post WordPress gives no pretty URL (a draft, pending or
			// scheduled post carries a plain ?p=ID permalink) has nothing
			// to compare and keeps the slug match it always had.
			$candidate_path = $candidate ? self::permalink_path_for( $candidate ) : '';

			foreach ( $normalized_keys as $normalized ) {
				$resolved = ( $candidate && ( '' === $candidate_path || $candidate_path === $compare_path[ $normalized ] ) ) ? $candidate : 0;
				$results[ $candidate_href[ $normalized ] ] = $resolved;
				if ( 0 === $resolved ) {
					// A lone candidate whose permalink differs is a slug
					// coincidence, not genuine ambiguity: it queues behind
					// the nested and ambiguous links for the fallback.
					if ( $candidate ) {
						$fallback_low[ $normalized ] = true;
					} else {
						$fallback_eligible[ $normalized ] = true;
					}
				} else {
					self::$url_id_cache[ $normalized ] = $resolved;
				}
			}
		}

		// Multi-segment paths: prefer a hierarchical-type match verified
		// against the FULL path (parent chain), falling back to a single
		// unambiguous non-hierarchical match (e.g. %category%/%postname%/).
		$hierarchical_types = self::hierarchical_covered_post_types();
		foreach ( $nested_paths as $normalized => $info ) {
			$matches              = $by_slug[ $info['last_slug'] ] ?? array();
			$hierarchical_matches = array();
			$other_matches        = array();
			foreach ( $matches as $row ) {
				if ( ! self::is_covered_post_type_row( $row ) ) {
					continue;
				}
				if ( ! empty( $hierarchical_types ) && in_array( $row['post_type'], $hierarchical_types, true ) ) {
					$hierarchical_matches[] = $row;
				} else {
					$other_matches[] = $row;
				}
			}

			$resolved = 0;
			if ( 1 === count( $hierarchical_matches ) ) {
				$page = get_page_by_path( $info['path'], OBJECT, $hierarchical_types );
				if ( $page && (int) $page->ID === (int) $hierarchical_matches[0]['ID'] ) {
					$resolved = (int) $page->ID;
				}
			} elseif ( empty( $hierarchical_matches ) && 1 === count( $other_matches ) ) {
				// A non-hierarchical type has no parent chain to verify against
				// get_page_by_path() — its post_name matching the path's LAST
				// segment is not evidence the path actually names it.
				// /product-category/shoes/ and /shop/shoes/ share a last
				// segment with a real product's slug "shoes" without being
				// that product's URL at all (round 3 review, BLOCK-1). The
				// only trustworthy check left is: does this candidate's own
				// real permalink equal the href's actual normalized path? If
				// so the nested segments in front of it are exactly its own
				// rewrite structure (a CPT with an extra base, category+name
				// permalinks, etc.) and the match is genuine. If not, this is
				// a same-slug coincidence — treat it as unresolved here and
				// let url_to_postid() have the real say, same as any other
				// ambiguous case.
				$candidate_id   = (int) $other_matches[0]['ID'];
				$candidate_path = self::permalink_path_for( $candidate_id );
				if ( '' !== $candidate_path && $candidate_path === $compare_path[ $normalized ] ) {
					$resolved = $candidate_id;
				}
			}

			$results[ $candidate_href[ $normalized ] ] = $resolved;
			if ( 0 === $resolved ) {
				$fallback_nested[ $normalized ] = true;
			} else {
				self::$url_id_cache[ $normalized ] = $resolved;
			}
		}

		// Fallback: url_to_postid(), spent ONLY on links that reached a
		// genuine slug/path candidate and still could not be resolved
		// unambiguously — never on an external link or one already
		// classified as a rewrite base/search (those were resolved to 0,
		// cached, and never marked fallback_eligible above).
		$fallback_used = 0;
		foreach ( $fallback_nested + $fallback_eligible + $fallback_low as $normalized => $true ) {
			if ( $fallback_used >= self::URL_TO_POSTID_FALLBACK_CAP ) {
				break;
			}
			$fallback_used++;

			$href        = $candidate_href[ $normalized ];
			$absolute    = wp_http_validate_url( $href ) ? $href : home_url( $href );
			$fallback_id = (int) url_to_postid( $absolute );
			// #1000: core resolves any post type (an attachment page, a
			// non-covered CPT), which has no graph node. Keep only a covered type.
			if ( $fallback_id && self::is_covered_post_type( (string) get_post_type( $fallback_id ) ) ) {
				$results[ $href ] = $fallback_id;
				self::$url_id_cache[ $normalized ] = $fallback_id;
			}
		}

		return $results;
	}

	/**
	 * Prime the post, meta and term caches for a set of resolver candidates
	 * in one batch, so the get_permalink() calls that follow hit the cache.
	 *
	 * @param array<int> $ids Post IDs.
	 * @return void
	 */
	private static function prime_permalink_candidates( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) || ! function_exists( '_prime_post_caches' ) ) {
			return;
		}
		_prime_post_caches( $ids, true, false );
	}

	/**
	 * A post's own permalink as a comparable path (lowercased, no trailing
	 * slash, no query), memoised per run. '' means WordPress gave it no
	 * pretty permalink (a plain ?p=ID one), so there is nothing to compare.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function permalink_path_for( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( self::$permalink_path_cache[ $post_id ] ) ) {
			return self::$permalink_path_cache[ $post_id ];
		}

		$permalink = get_permalink( $post_id );
		$path      = '';
		if ( $permalink && '' === (string) wp_parse_url( $permalink, PHP_URL_QUERY ) ) {
			$path = self::normalize_internal_graph_url( $permalink );
		}

		self::$permalink_path_cache[ $post_id ] = $path;
		return $path;
	}

	/**
	 * @param array $row {..., post_type} as returned by the batched resolver query.
	 * @return bool
	 */
	private static function is_covered_post_type_row( $row ) {
		return self::is_covered_post_type( $row['post_type'] );
	}

	/**
	 * Hierarchical, covered post types (normally just 'page') — the set
	 * get_page_by_path() is restricted to when verifying a multi-segment
	 * candidate's full path.
	 *
	 * @return array<string>
	 */
	private static function hierarchical_covered_post_types() {
		$all = function_exists( 'get_post_types' )
			? array_values( get_post_types( array( 'hierarchical' => true, 'public' => true ) ) )
			: array( 'page' );

		return array_values( array_filter( $all, array( __CLASS__, 'is_covered_post_type' ) ) );
	}

	/**
	 * Whether $path (already trimmed of leading/trailing slashes) is a known
	 * rewrite base rather than a post permalink — category/tag archive base,
	 * author archive, feed, `page/<n>` pagination, or a bare date archive
	 * (BLOCK-2). Attachments are not path-detectable this way (their default
	 * permalink is indistinguishable in shape from a nested page's), so they
	 * are excluded instead by post_type filtering on the query result, same
	 * as every other non-covered type.
	 *
	 * A known, documented limitation: a site with fully custom rewrite rules
	 * (a "search" base other than the literal string checked here, etc.)
	 * may not have every one of its non-post paths caught by this function —
	 * those fall through to the url_to_postid() fallback instead, which is
	 * exactly the safety net this function exists to reduce reliance on, not
	 * eliminate.
	 *
	 * @param string $path Trimmed path (no leading/trailing slash).
	 * @return bool
	 */
	private static function is_excluded_rewrite_path( $path ) {
		// A trailing /page/N/ is stripped by the ONLY caller before this
		// runs (N2, round 3) so it can resolve the remaining path instead
		// of discarding the whole href — nothing left to check for here.
		if ( preg_match( '#^\d{4}(/\d{1,2}(/\d{1,2})?)?$#', $path ) ) {
			return true; // Date archive: the WHOLE path is just numeric segments.
		}

		$segments = explode( '/', $path );
		$first    = $segments[0];
		$last     = end( $segments );

		$category_base = trim( (string) get_option( 'category_base' ), '/' );
		if ( '' === $category_base ) {
			$category_base = 'category';
		}
		if ( $first === $category_base ) {
			return true;
		}

		$tag_base = trim( (string) get_option( 'tag_base' ), '/' );
		if ( '' === $tag_base ) {
			$tag_base = 'tag';
		}
		if ( $first === $tag_base ) {
			return true;
		}

		global $wp_rewrite;

		$author_base = ( isset( $wp_rewrite ) && is_object( $wp_rewrite ) && ! empty( $wp_rewrite->author_base ) )
			? trim( (string) $wp_rewrite->author_base, '/' )
			: 'author';
		if ( $first === $author_base ) {
			return true;
		}

		$feed_base = ( isset( $wp_rewrite ) && is_object( $wp_rewrite ) && ! empty( $wp_rewrite->feed_base ) )
			? trim( (string) $wp_rewrite->feed_base, '/' )
			: 'feed';
		if ( $first === $feed_base || $last === $feed_base ) {
			return true;
		}

		if ( 'search' === $first ) {
			return true; // A common custom search-results rewrite base.
		}

		return false;
	}

	/**
	 * Build {target_id => {anchors: [...<=5], locations: {content|elementor => true}}}
	 * for a post's outgoing same-host links, via the batched resolver.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function build_link_edges_data( $post ) {
		$raw   = self::collect_post_raw_links( $post );
		$hrefs = array_column( $raw, 'href' );
		$resolved = self::resolve_hrefs_for_post( $hrefs );

		$by_target = array();
		foreach ( $raw as $link ) {
			$target_id = (int) ( $resolved[ $link['href'] ] ?? 0 );
			if ( ! $target_id || $target_id === (int) $post->ID ) {
				continue;
			}
			if ( ! isset( $by_target[ $target_id ] ) ) {
				$by_target[ $target_id ] = array( 'anchors' => array(), 'locations' => array() );
			}
			if ( '' !== $link['anchor']
				&& count( $by_target[ $target_id ]['anchors'] ) < self::MAX_EDGE_ANCHORS
				&& ! in_array( $link['anchor'], $by_target[ $target_id ]['anchors'], true )
			) {
				$by_target[ $target_id ]['anchors'][] = $link['anchor'];
			}
			$loc = 'elementor' === $link['source'] ? 'elementor' : 'content';
			$by_target[ $target_id ]['locations'][ $loc ] = true;
		}

		return $by_target;
	}

	/**
	 * Resolve a post's outgoing same-host links (content + Elementor) to
	 * target post IDs — plain ID list, for callers/tests that don't need
	 * anchor/location props.
	 *
	 * @param WP_Post $post Post.
	 * @return array Unique target post IDs.
	 */
	public static function extract_post_link_targets( $post ) {
		return array_keys( self::build_link_edges_data( $post ) );
	}

	/**
	 * Extract {taxonomy, term_id, name} for every term attached to a post,
	 * across every taxonomy registered for its post type.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function extract_term_refs( $post ) {
		$refs       = array();
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			// #943 S2 review W4: a non-viewable taxonomy (private, not publicly
			// queryable) has no public archive, so its term names are not site-
			// level data and never become graph nodes a read key could list.
			if ( ! self::is_viewable_taxonomy( $taxonomy ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( ! is_object( $term ) || empty( $term->term_id ) ) {
					continue;
				}
				$refs[] = array(
					'taxonomy' => (string) $taxonomy,
					'term_id'  => (int) $term->term_id,
					'name'     => (string) $term->name,
				);
			}
		}

		return $refs;
	}

	/**
	 * Extract attachment IDs a post uses: featured image, inline
	 * `wp-image-<id>` classes in content, and Elementor image/gallery widget
	 * settings.
	 *
	 * @param WP_Post $post Post.
	 * @return array Unique attachment IDs.
	 */
	public static function extract_media_refs( $post ) {
		$ids = array();

		$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id > 0 ) {
			$ids[ $thumbnail_id ] = true;
		}

		if ( preg_match_all( '/wp-image-(\d+)/', (string) $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		$data = Mcpwp_Elementor_Stored_Tree::of_post( (int) $post->ID )['elements'];
		if ( ! empty( $data ) ) {
			self::collect_elementor_media_ids( $data, $ids );
		}

		unset( $ids[0] );

		// #943 S2 review W3: `wp-image-<id>` classes and Elementor widget ids
		// are author-controlled text; only a real attachment becomes a media:
		// node. Without this, `wp-image-<draft id>` made a node labelled with
		// that draft's title.
		return array_values(
			array_filter(
				array_keys( $ids ),
				static function ( $id ) {
					return self::is_attachment_id( $id );
				}
			)
		);
	}

	/**
	 * Whether an id names a real attachment.
	 *
	 * @param int $id Post ID.
	 * @return bool
	 */
	public static function is_attachment_id( $id ) {
		return (int) $id > 0 && 'attachment' === get_post_type( (int) $id );
	}

	/**
	 * Whether a taxonomy is publicly viewable (is_taxonomy_viewable(), WP 5.1+).
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return bool
	 */
	public static function is_viewable_taxonomy( $taxonomy ) {
		if ( function_exists( 'is_taxonomy_viewable' ) ) {
			return (bool) is_taxonomy_viewable( (string) $taxonomy );
		}
		$obj = function_exists( 'get_taxonomy' ) ? get_taxonomy( (string) $taxonomy ) : null;
		return (bool) ( $obj && ( ! empty( $obj->publicly_queryable ) || ! empty( $obj->public ) ) );
	}

	/**
	 * Recursively collect attachment IDs from Elementor image/gallery widget
	 * settings (`settings.image.id`, `settings.gallery[].id`,
	 * `settings.background_image.id`).
	 *
	 * @param array $elements Elementor elements array.
	 * @param array $ids      Accumulator passed by reference (id => true).
	 */
	private static function collect_elementor_media_ids( $elements, array &$ids ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			foreach ( array( 'image', 'background_image' ) as $field ) {
				if ( ! empty( $settings[ $field ]['id'] ) ) {
					$ids[ (int) $settings[ $field ]['id'] ] = true;
				}
			}

			if ( ! empty( $settings['gallery'] ) && is_array( $settings['gallery'] ) ) {
				foreach ( $settings['gallery'] as $item ) {
					if ( is_array( $item ) && ! empty( $item['id'] ) ) {
						$ids[ (int) $item['id'] ] = true;
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_elementor_media_ids( $element['elements'], $ids );
			}
		}
	}

	// -------------------------------------------------------------------------
	// DB error suppression + once-per-request logging (W6)
	// -------------------------------------------------------------------------

	/**
	 * Run a $wpdb call with errors suppressed, restoring the previous
	 * suppression state, and log at most once per request if it left an
	 * error behind — so a dropped/broken graph table degrades a REST save to
	 * "no graph update", never to a polluted or failed response.
	 *
	 * @param callable $fn Closure making exactly one $wpdb call.
	 * @return mixed The closure's return value.
	 */
	private static function run_suppressed( callable $fn ) {
		global $wpdb;
		$prev = $wpdb->suppress_errors( true );
		try {
			$result = $fn();
		} finally {
			$wpdb->suppress_errors( $prev );
		}
		if ( ! empty( $wpdb->last_error ) ) {
			self::log_db_error_once( (string) $wpdb->last_error );
		}
		return $result;
	}

	/**
	 * @param string $message Error message.
	 */
	private static function log_db_error_once( $message ) {
		if ( self::$logged_db_error_this_request ) {
			return;
		}
		self::$logged_db_error_this_request = true;
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP graph DB error: ' . $message );
		}
	}

	// -------------------------------------------------------------------------
	// Storage primitives
	// -------------------------------------------------------------------------

	/**
	 * @return string
	 */
	public static function nodes_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcpwp_graph_nodes';
	}

	/**
	 * @return string
	 */
	public static function edges_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcpwp_graph_edges';
	}

	/**
	 * @return string
	 */
	public static function signals_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcpwp_graph_signals';
	}

	/**
	 * Public entry point to run_suppressed() for the read surface (#943 S2):
	 * every read query goes through the SAME "never surface a raw DB error"
	 * guarantee the write path already has (W6), rather than the read
	 * surface growing its own, second copy of that suppression/log-once
	 * logic.
	 *
	 * @param callable $fn Closure making exactly one $wpdb call.
	 * @return mixed The closure's return value.
	 */
	public static function run_suppressed_query( callable $fn ) {
		return self::run_suppressed( $fn );
	}

	/**
	 * POST /graph/rebuild (admin scope): reschedule the backfill from
	 * scratch. Does NOT drop existing nodes/edges — a full re-walk simply
	 * re-upserts every covered post through the same content_hash-skips-
	 * unchanged path a normal backfill already uses, so this is safe to
	 * call on a live, populated graph (e.g. after a bulk import a site
	 * owner suspects the incremental hooks missed).
	 *
	 * @return void
	 */
	public static function force_rebuild() {
		update_option( self::BACKFILL_STATUS_OPTION, 'pending', false );
		update_option( self::BACKFILL_CURSOR_OPTION, 0, false );
		self::on_upgrade_schedule_backfill();
	}

	/**
	 * Upsert one node row. Timestamps are set here in PHP, never by a DB
	 * column default (W6 — dbDelta can then ALTER the column later without
	 * touching a DEFAULT clause it would otherwise have to reconcile).
	 *
	 * @param array $row {node_key, kind, ref_id, label, url, status, content_hash, word_count, props}.
	 */
	private static function upsert_node( array $row ) {
		global $wpdb;
		$table = self::nodes_table();
		$now   = current_time( 'mysql' );

		self::run_suppressed(
			function () use ( $wpdb, $table, $row, $now ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
				return $wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$table} (node_key, kind, ref_id, label, url, status, content_hash, word_count, props, updated_at)
						VALUES (%s, %s, %d, %s, %s, %s, %s, %d, %s, %s)
						ON DUPLICATE KEY UPDATE kind = VALUES(kind), ref_id = VALUES(ref_id), label = VALUES(label),
							url = VALUES(url), status = VALUES(status), content_hash = VALUES(content_hash),
							word_count = VALUES(word_count), props = VALUES(props), updated_at = VALUES(updated_at)",
						(string) $row['node_key'],
						(string) $row['kind'],
						isset( $row['ref_id'] ) ? (int) $row['ref_id'] : 0,
						(string) ( $row['label'] ?? '' ),
						(string) ( $row['url'] ?? '' ),
						(string) ( $row['status'] ?? '' ),
						(string) ( $row['content_hash'] ?? '' ),
						(int) ( $row['word_count'] ?? 0 ),
						(string) ( $row['props'] ?? '' ),
						$now
					)
				);
			}
		);
	}

	/**
	 * Batch-insert/refresh builder-sourced edges in as few queries as
	 * possible (BLOCK-4 perf) — one multi-row INSERT ... ON DUPLICATE KEY
	 * UPDATE per <=500-row chunk, instead of one query per edge.
	 *
	 * @param array  $rows Each: {from_key, to_key, type, weight, source, props (array)}.
	 * @param string $now  Timestamp to stamp every row with.
	 */
	private static function insert_edges_batch( array $rows, $now ) {
		if ( empty( $rows ) ) {
			return;
		}

		global $wpdb;
		$table = self::edges_table();

		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$placeholders = array();
			$args         = array();
			foreach ( $chunk as $row ) {
				$placeholders[] = '(%s, %s, %s, %f, %s, %s, %s)';
				$args[]         = (string) $row['from_key'];
				$args[]         = (string) $row['to_key'];
				$args[]         = (string) $row['type'];
				$args[]         = (float) ( $row['weight'] ?? 1.0 );
				$args[]         = (string) ( $row['source'] ?? 'builder' );
				$args[]         = wp_json_encode( $row['props'] ?? array() );
				$args[]         = $now;
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql built with placeholders only, prepared below.
			$sql = "INSERT INTO {$table} (from_key, to_key, type, weight, source, props, updated_at) VALUES "
				. implode( ',', $placeholders )
				. ' ON DUPLICATE KEY UPDATE weight = VALUES(weight), source = VALUES(source), props = VALUES(props), updated_at = VALUES(updated_at)';

			self::run_suppressed(
				function () use ( $wpdb, $sql, $args ) {
					return $wpdb->query( $wpdb->prepare( $sql, $args ) );
				}
			);
		}
	}

	/**
	 * Delete this node's builder-owned edges: its own outgoing
	 * links_to/in_term/uses_media, and the one incoming parent_of edge it is
	 * authoritative for.
	 *
	 * @param string $node_key Node key.
	 */
	private static function delete_builder_edges_for_node( $node_key ) {
		global $wpdb;
		$table = self::edges_table();

		self::run_suppressed(
			function () use ( $wpdb, $table, $node_key ) {
				return $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE from_key = %s AND source = 'builder' AND type IN ('links_to','in_term','uses_media')",
						$node_key
					)
				);
			}
		);
		self::run_suppressed(
			function () use ( $wpdb, $table, $node_key ) {
				return $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE to_key = %s AND source = 'builder' AND type = 'parent_of'",
						$node_key
					)
				);
			}
		);
	}

	/**
	 * Delete a node and every edge from or to it (both directions).
	 *
	 * @param string $node_key Node key.
	 */
	public static function delete_node_and_edges( $node_key ) {
		global $wpdb;
		$nodes_table = self::nodes_table();
		$edges_table = self::edges_table();

		self::run_suppressed(
			function () use ( $wpdb, $nodes_table, $node_key ) {
				return $wpdb->query( $wpdb->prepare( "DELETE FROM {$nodes_table} WHERE node_key = %s", $node_key ) );
			}
		);
		self::run_suppressed(
			function () use ( $wpdb, $edges_table, $node_key ) {
				return $wpdb->query( $wpdb->prepare( "DELETE FROM {$edges_table} WHERE from_key = %s OR to_key = %s", $node_key, $node_key ) );
			}
		);
	}

	// -------------------------------------------------------------------------
	// Backfill (BLOCK-3, BLOCK-5)
	// -------------------------------------------------------------------------

	/**
	 * Called from the mcpwp_db_version upgrade path / on activation. Kicks
	 * off (or resumes) the backfill unless it has already completed, in
	 * which case it enqueues a reconcile instead (W8: stale-after-reactivation).
	 */
	public static function on_upgrade_schedule_backfill() {
		if ( ! self::ready() ) {
			// Tables aren't confirmed yet; the readiness retry cron (or the
			// activator that called this) will get us here again once they are.
			return;
		}

		$status = get_option( self::BACKFILL_STATUS_OPTION, '' );
		if ( 'done' === $status ) {
			self::enqueue_reconcile();
			return;
		}
		if ( '' === $status ) {
			update_option( self::BACKFILL_STATUS_OPTION, 'pending', false );
		}
		self::schedule_next_backfill_batch();
	}

	/**
	 * Schedule one backfill batch. Guards duplicates by checking for a
	 * PENDING Action Scheduler action specifically (BLOCK-5) —
	 * `as_has_scheduled_action()` alone kept matching a long-completed
	 * action's still-present record, so the very first batch's own
	 * "is one already scheduled" check would block every batch after it,
	 * forever, once Action Scheduler's store had ANY record for this hook.
	 */
	private static function schedule_next_backfill_batch() {
		if ( self::has_pending_scheduled_action( self::BACKFILL_CRON_HOOK ) ) {
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::BACKFILL_CRON_HOOK, array(), 'mcpwp' );
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::BACKFILL_CRON_HOOK ) ) {
			return;
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 5, self::BACKFILL_CRON_HOOK );
		}
	}

	/**
	 * Run one backfill batch: process up to BACKFILL_BATCH_SIZE posts
	 * ordered by ID, bounded by BACKFILL_TIME_BUDGET. Every post goes
	 * through rebuild_node_safely() (BLOCK-3: a throwing filter must not
	 * stall the whole batch) and the cursor is saved after EVERY post, not
	 * once at the end, so a killed process loses at most one post's worth of
	 * progress. The next batch is scheduled unconditionally whenever this
	 * one did not just determine "no more rows" (BLOCK-3/5).
	 */
	public static function run_backfill_batch() {
		if ( ! self::ready() ) {
			return;
		}
		if ( 'done' === get_option( self::BACKFILL_STATUS_OPTION, '' ) ) {
			return;
		}

		update_option( self::BACKFILL_STATUS_OPTION, 'running', false );

		$start  = microtime( true );
		self::$url_id_cache = array();
		self::$permalink_path_cache = array();
		$cursor = (int) get_option( self::BACKFILL_CURSOR_OPTION, 0 );

		$ids = self::fetch_backfill_batch_ids( self::covered_post_types(), $cursor, self::BACKFILL_BATCH_SIZE );

		$processed = 0;
		foreach ( $ids as $id ) {
			if ( ( microtime( true ) - $start ) > self::BACKFILL_TIME_BUDGET ) {
				break;
			}
			self::rebuild_node_safely( self::node_key_for_post( (int) $id ) );
			update_option( self::BACKFILL_CURSOR_OPTION, (int) $id, false );
			$processed++;
		}

		$done = $processed === count( $ids ) && count( $ids ) < self::BACKFILL_BATCH_SIZE;

		if ( $done ) {
			update_option( self::BACKFILL_STATUS_OPTION, 'done', false );
			update_option( self::BUILT_AT_OPTION, current_time( 'mysql' ), false );
			return;
		}

		self::schedule_next_backfill_batch();
	}

	/**
	 * Fetch up to $limit post IDs with ID > $cursor, ordered by ID ascending,
	 * across $post_types. Raw $wpdb (not WP_Query) — cursor pagination at
	 * 20k-post scale must avoid OFFSET.
	 *
	 * @param array $post_types Post types (trusted — from covered_post_types(), never user input).
	 * @param int   $cursor     Highest ID already processed.
	 * @param int   $limit      Max rows.
	 * @return array<int> Post IDs.
	 */
	private static function fetch_backfill_batch_ids( array $post_types, $cursor, $limit ) {
		global $wpdb;

		if ( empty( $post_types ) ) {
			return array();
		}

		$post_types = array_map( 'sanitize_key', $post_types );
		$in_clause  = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in_clause is built only from covered_post_types() values (never user input); cursor/limit are prepared.
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in_clause}) AND post_status != 'auto-draft' AND ID > %d ORDER BY ID ASC LIMIT %d",
			(int) $cursor,
			(int) $limit
		);

		$ids = self::run_suppressed(
			function () use ( $wpdb, $sql ) {
				return $wpdb->get_col( $sql );
			}
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Backfill status for the read surface's `graph_status`.
	 *
	 * @return array{status: string, cursor: int}
	 */
	public static function backfill_status() {
		return array(
			'status' => (string) get_option( self::BACKFILL_STATUS_OPTION, '' ),
			'cursor' => (int) get_option( self::BACKFILL_CURSOR_OPTION, 0 ),
		);
	}

	// -------------------------------------------------------------------------
	// Reconcile (W8): stale-after-deactivation.
	// -------------------------------------------------------------------------

	/**
	 * Queue every covered post modified after `built_at`, and start a cheap
	 * hash-comparison sweep (run in batches by the worker) over everything
	 * else. Called when activation/upgrade finds the backfill already done —
	 * a plugin can be deactivated for weeks while content keeps changing (a
	 * theme's own save routine, WP-CLI, another plugin), and none of that
	 * ever reached the queue while this plugin's hooks were not registered.
	 */
	private static function enqueue_reconcile() {
		global $wpdb;

		$built_at   = (string) get_option( self::BUILT_AT_OPTION, '' );
		$since      = '' !== $built_at ? $built_at : '1970-01-01 00:00:00';
		$post_types = self::covered_post_types();

		if ( ! empty( $post_types ) ) {
			$in_clause = "'" . implode( "','", array_map( 'esc_sql', array_map( 'sanitize_key', $post_types ) ) ) . "'";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in_clause from covered_post_types(); $since is prepared.
			$ids = self::run_suppressed(
				function () use ( $wpdb, $in_clause, $since ) {
					return $wpdb->get_col(
						$wpdb->prepare(
							"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in_clause}) AND post_status != 'auto-draft' AND post_modified_gmt > %s",
							$since
						)
					);
				}
			);

			foreach ( (array) $ids as $id ) {
				self::mark( self::node_key_for_post( (int) $id ), 'reconcile_modified' );
			}
		}

		update_option( self::RECONCILE_STATUS_OPTION, 'pending', false );
		update_option( self::RECONCILE_CURSOR_OPTION, 0, false );
		self::schedule_worker();
	}

	/**
	 * One reconcile batch: hash-compare RECONCILE_BATCH_SIZE posts (cheap —
	 * only a rewrite when the hash actually differs), called from the worker
	 * once the queue is empty, within whatever time budget remains.
	 *
	 * @param float $start Worker run's own microtime(true) start, so the
	 *                      combined queue-drain + reconcile time stays within
	 *                      one WORKER_TIME_BUDGET window.
	 */
	private static function maybe_run_reconcile_batch( $start ) {
		$status = get_option( self::RECONCILE_STATUS_OPTION, '' );
		if ( 'pending' !== $status && 'running' !== $status ) {
			return;
		}
		if ( ( microtime( true ) - $start ) >= self::WORKER_TIME_BUDGET ) {
			self::schedule_worker();
			return;
		}

		update_option( self::RECONCILE_STATUS_OPTION, 'running', false );

		$cursor     = (int) get_option( self::RECONCILE_CURSOR_OPTION, 0 );
		$post_types = self::covered_post_types();
		$ids        = self::fetch_backfill_batch_ids( $post_types, $cursor, self::RECONCILE_BATCH_SIZE );

		foreach ( $ids as $id ) {
			if ( ( microtime( true ) - $start ) >= self::WORKER_TIME_BUDGET ) {
				update_option( self::RECONCILE_CURSOR_OPTION, (int) $cursor, false );
				self::schedule_worker();
				return;
			}

			$post = get_post( (int) $id );
			if ( $post && self::is_covered_post_type( $post->post_type ) ) {
				$new_hash      = self::content_hash( $post );
				$existing_hash = self::read_node_hash( self::node_key_for_post( (int) $id ) );
				if ( null === $existing_hash || $existing_hash !== $new_hash ) {
					self::mark( self::node_key_for_post( (int) $id ), 'reconcile_hash_diff' );
				}
			}

			$cursor = (int) $id;
			update_option( self::RECONCILE_CURSOR_OPTION, $cursor, false );
		}

		if ( count( $ids ) < self::RECONCILE_BATCH_SIZE ) {
			update_option( self::RECONCILE_STATUS_OPTION, 'done', false );
			// W1: refresh the watermark a FUTURE reconcile's "modified since"
			// query reads. Without this, every later reactivation re-scans
			// everything modified since the ORIGINAL backfill forever, doing
			// increasingly redundant work as a site ages.
			update_option( self::BUILT_AT_OPTION, current_time( 'mysql' ), false );
			return;
		}

		self::schedule_worker();
	}
}
