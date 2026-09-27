<?php
/**
 * Content guardrails against scaled and duplicate publishing (#957).
 *
 * Owner concern: the self-improving loop is allowed to run on its own, but it
 * must not be usable as a mass thin-page, link-spam, or "parasite SEO" tool —
 * the patterns Google's spam policies describe as scaled content abuse and
 * site reputation abuse.
 *
 * Architecture (rewritten after adversarial review of the first version —
 * see git history on #957 for what BLOCK-1/BLOCK-2 were): this is NOT a
 * function every create/publish call site remembers to call. It is a
 * WordPress core hook — `wp_insert_post_data`, which fires before EVERY
 * wp_insert_post() AND wp_update_post() — so "create as a draft, then
 * update to publish" is not a second path to gate, it is the exact same
 * call every write already makes. See on_insert_post_data() for the full
 * design note and enter_context()/in_context() for how it stays scoped to
 * an MCPWP-authenticated request only (never wp-admin, WP-CLI, or another
 * plugin's save):
 *
 *   1. Publishing ramp — at most N new *published* posts+pages per site per
 *      UTC calendar day through MCPWP (site-level; admin keys included). Over
 *      the cap, the write still happens, just as a draft, with a short
 *      teaching message. Drafts are never capped. The slot is RESERVED
 *      atomically INSIDE on_insert_post_data(), before the row is written —
 *      not read, decided on, and incremented later once the write is
 *      confirmed, which is exactly the gap that let two requests racing the
 *      cap boundary both slip through in the first round (#957 adversarial
 *      review round 2 WARN-3) — see the "Publish counting" section below
 *      for the primitive, and for why this trades the issue's original
 *      "rolling 24h" wording for a fixed daily bucket.
 *   2. Bulk creation — a single bulk call that would publish more than M
 *      items goes through the existing approval pipeline instead: every item
 *      is created as a draft and one approval request covers publishing the
 *      whole batch, with each item's content hash + findings snapshotted at
 *      queue time so apply can refuse anything edited since — see
 *      apply_bulk_publish(). A bulk call that only creates drafts is never
 *      gated.
 *   3. Thin/near-duplicate detection — a word-count floor for published
 *      content (WARNING ONLY, never a reason to downgrade — a contact page
 *      or an Elementor-first page that legitimately publishes with 0 words
 *      at create time must still publish), plus a 5-word-shingle Jaccard
 *      comparison against the site's own recent posts of the same type, plus
 *      a "city-swap" heuristic for the classic local-SEO clone-and-rename
 *      pattern. Duplicate detection only ever downgrades a NEW publish; an
 *      edit to an ALREADY-published post that turns its content into a
 *      duplicate/city-swap copy is refused instead (see
 *      guard_published_content_edit()) — downgrading a live, possibly
 *      already-indexed page to draft would unpublish it, a far larger
 *      blast radius than declining one edit.
 *   4. Anomaly signal — a `content.publish_spike` event when today's
 *      published count crosses both an absolute floor and 2x the trailing
 *      30-day daily average, so Control Room/webhooks see it.
 *
 * Known, accepted limits (not goals — see the PR description):
 *   - The duplicate check compares against at most 200 recent posts, so a
 *     spammer publishing faster than that window turns over evades it.
 *   - Rewording content enough to drop Jaccard similarity below threshold
 *     evades detection — this is a guardrail against casual/automated
 *     scaled publishing, not an adversarial-proof filter.
 *   - An edit that changes _elementor_data via `meta_input` on an
 *     ALREADY-published post cannot be reverted by
 *     guard_published_content_edit() — WordPress core applies meta_input
 *     from the original $postarr, not from this filter's returned $data,
 *     so nothing this filter does can stop that specific write. Detected
 *     and warned regardless; no existing call site in this codebase
 *     updates an existing post's Elementor data this way (they all go
 *     through Mcpwp_Elementor_Basic::set_elementor_data(), a separate
 *     update_post_meta() call this class does not (yet) hook).
 *   - try_reserve_daily_slot() fails CLOSED (treats a reservation as
 *     denied, downgrading to draft) if 8 CAS retries are all lost to
 *     contention — safe (never lets the cap be exceeded) but means an
 *     extremely hot moment could downgrade one more post than strictly
 *     necessary. Never claim "never lost" beyond that bound.
 *
 * Deliberately NOT reused: Mcpwp_Rate_Limiter (per-identifier request-rate
 * windows, a different problem) and Mcpwp_Destructive_Gate's per-requester
 * pending caps (built for irreversible deletes, not creation).
 *
 * Scope: posts and pages only (Mcpwp_Approvals-style content types), per the
 * issue's own wording for the ramp and thin-content rules. WooCommerce
 * products go through WC_Product::save(), not wp_insert_post, and were
 * reviewed but left out — see the PR description for the reasoning.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content guardrails gate.
 */
class Mcpwp_Content_Guardrails {

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'mcpwp_content_guardrails';

	/**
	 * Hard architectural ceiling on how many recent posts a duplicate check
	 * will ever compare against, regardless of the configured setting (#957:
	 * "cap work: compare against at most 200 recent posts").
	 *
	 * @var int
	 */
	const HARD_DUPLICATE_COMPARE_CAP = 200;

	/**
	 * Post types the ramp cap, thin-content floor, and duplicate detection
	 * apply to. Anything else (custom post types, elementor_snippet, etc.)
	 * passes through evaluate() unchanged.
	 *
	 * @var string[]
	 */
	const COVERED_POST_TYPES = array( 'post', 'page' );

	/**
	 * Shingle size for the near-duplicate Jaccard comparison (#957: "5-word
	 * shingle").
	 *
	 * @var int
	 */
	const SHINGLE_SIZE = 5;

	/**
	 * Hard cap on how many WORDS of any single document (new content or a
	 * candidate post) are ever tokenized/shingled. Adversarial-review WARN-4:
	 * without this, a 10,000-word post costs the same as reading the whole
	 * thing on every comparison; with it, cost per document is bounded
	 * regardless of how long the stored content actually is.
	 *
	 * @var int
	 */
	const MAX_WORDS_PER_DOCUMENT = 3000;

	/**
	 * Max entries kept in the per-request candidate-token LRU cache
	 * (WARN-4: "cap shingle cache, e.g. 50 entries, LRU"). Deliberately
	 * smaller than HARD_DUPLICATE_COMPARE_CAP — a single duplicate_check()
	 * call re-reads more candidates than this, but a BULK call re-running
	 * duplicate_check() for many new items in one request tends to re-see
	 * the same most-recent candidates repeatedly, which is exactly what an
	 * LRU keeps warm.
	 *
	 * @var int
	 */
	const MAX_TOKEN_CACHE_ENTRIES = 50;

	/**
	 * Per-request LRU cache of a candidate post's normalized word tokens,
	 * keyed by "{post_id}:{content hash}". PHP array insertion order is
	 * used as the recency order: re-touching a key moves it to the end via
	 * unset()+re-insert; the OLDEST (front) entry is evicted once the cache
	 * is full (#957 WARN-4: "cache normalized tokens per candidate").
	 * Shingles are derived from these cached tokens on demand — cheap once
	 * tokenizing itself is cached.
	 *
	 * @var array<string,string[]>
	 */
	private static $token_cache = array();

	// -------------------------------------------------------------------------
	// Settings

	/**
	 * Default settings.
	 *
	 * @return array Defaults.
	 */
	public static function default_settings() {
		return array(
			'enabled'                 => true,
			'daily_publish_cap'       => 25,
			'bulk_approval_threshold' => 10,
			'min_word_count'          => 150,
			'duplicate_threshold'     => 0.6,
			'duplicate_compare_limit' => 200,
			'anomaly_multiplier'      => 2.0,
			'anomaly_min_count'       => 10,
		);
	}

	/**
	 * Get current settings, sanitized and bounds-clamped.
	 *
	 * @return array Settings.
	 */
	public static function get_settings() {
		return self::sanitize_settings( get_option( self::OPTION_NAME, array() ) );
	}

	/**
	 * Persist new settings (bounds-clamped).
	 *
	 * @param array $input Raw input, e.g. a programmatic partial update.
	 * @return array Stored (sanitized) settings.
	 */
	public static function update_settings( $input ) {
		$sanitized = self::sanitize_settings( is_array( $input ) ? $input : array() );
		update_option( self::OPTION_NAME, $sanitized, false );
		return $sanitized;
	}

	/**
	 * Clamp every field to a sane bound.
	 *
	 * Falls back to the CURRENTLY STORED value for any field $input doesn't
	 * mention — never straight to the hardcoded default. This is the fix
	 * for #957's adversarial-review WARN-6: WordPress's Settings API invokes
	 * this sanitize_callback DIRECTLY from options.php on every settings-page
	 * save, never through update_settings() above, and an HTML checkbox
	 * submits NOTHING when unchecked. Falling back to the default here would
	 * mean unchecking "Enabled" and saving silently re-enables it (the
	 * default is true) instead of storing false — and, more generally,
	 * means any single-field save (or a partial programmatic call) resets
	 * every OTHER field back to default too. render_field_enabled()'s hidden
	 * `value="0"` input makes the checkbox itself unambiguous when a real
	 * form submits it; this fallback is what protects every other field, and
	 * every caller that isn't that form.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		$defaults = self::default_settings();
		$stored   = get_option( self::OPTION_NAME, array() );
		$stored   = is_array( $stored ) ? array_merge( $defaults, $stored ) : $defaults;
		$input    = is_array( $input ) ? $input : array();

		$pick = function ( $key ) use ( $input, $stored ) {
			return array_key_exists( $key, $input ) ? $input[ $key ] : $stored[ $key ];
		};

		return array(
			'enabled'                 => ! empty( $pick( 'enabled' ) ),
			'daily_publish_cap'       => max( 1, min( 100000, absint( $pick( 'daily_publish_cap' ) ) ) ),
			'bulk_approval_threshold' => max( 1, min( 1000, absint( $pick( 'bulk_approval_threshold' ) ) ) ),
			'min_word_count'          => max( 0, min( 5000, absint( $pick( 'min_word_count' ) ) ) ),
			'duplicate_threshold'     => max( 0.1, min( 1.0, (float) $pick( 'duplicate_threshold' ) ) ),
			// Never above the hard architectural cap, whatever is stored.
			'duplicate_compare_limit' => max( 1, min( self::HARD_DUPLICATE_COMPARE_CAP, absint( $pick( 'duplicate_compare_limit' ) ) ) ),
			'anomaly_multiplier'      => max( 1.0, min( 10.0, (float) $pick( 'anomaly_multiplier' ) ) ),
			'anomaly_min_count'       => max( 1, min( 1000, absint( $pick( 'anomaly_min_count' ) ) ) ),
		);
	}

	/**
	 * Whether guardrails are enabled site-wide.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enabled'] );
	}

	// -------------------------------------------------------------------------
	// The chokepoint (#957 adversarial review, BLOCK-1/BLOCK-2 fix)
	//
	// Every earlier version of this class was consulted per call site
	// (Mcpwp_Posts::create_post(), Mcpwp_Pages::create_page(), clone_page(),
	// etc.) — which meant any write path nobody remembered to wire in
	// (a free wp_clone_page route, POST /elementor/page, any *update* to an
	// already-created draft) published straight through, uncapped and
	// unchecked. update_post()/update_page()/bulk updates/wp_batch's
	// update operations are ordinary wp_update_post() calls; "create as
	// draft, then update to publish" is not a separate code path to find
	// and gate, it is the SAME call WordPress core itself always makes.
	//
	// The fix: one filter (`wp_insert_post_data`) that WordPress core itself
	// runs before EVERY insert and update, and one action
	// (`transition_post_status`) that fires after a write actually lands.
	// Both are active only while an MCPWP-authenticated request is being
	// served — see enter_context()/in_context(), set from
	// trait-mcpwp-api-auth.php's verify_api_key() the moment a request is
	// confirmed to be an MCPWP API-key/OAuth/broker principal. That is
	// deliberately never true for wp-admin, WP-CLI, or another plugin's
	// unrelated post save — Control Room's approve/apply IS an exception on
	// purpose: apply_bulk_publish() below explicitly enters context for its
	// own duration regardless of caller, because applying a bulk-publish
	// approval is always a real publish decision, wp-admin included (#957
	// adversarial review round 2 NIT-4).
	//
	// $context_active is a static, so a long-lived worker process
	// (FrankenPHP, Swoole) that reuses this PHP process across requests
	// could otherwise leak it from one request into the next. Reset on
	// `rest_pre_dispatch` (start of the next REST request) and on
	// `shutdown` (end of this one, and the only reliable place to release
	// a publish-slot reservation an insert never confirmed — see
	// try_reserve_daily_slot()) — see reset_per_request_state().

	/**
	 * Whether the request currently being served has been confirmed as an
	 * MCPWP-authenticated request (REST route or MCP tools/call/batch/
	 * resources sub-request under mcpwp/v1). Set once per request and never
	 * cleared mid-request — once true, the rest of that request IS an MCPWP
	 * call, by definition.
	 *
	 * @var bool
	 */
	private static $context_active = false;

	/**
	 * A notice recorded by on_insert_post_data() for a not-yet-assigned post
	 * ID (a brand new insert — the filter runs BEFORE the row exists).
	 * Claimed by on_wp_insert_post() the moment the real ID is known.
	 *
	 * @var array|null
	 */
	private static $pending_notice = null;

	/**
	 * Per-request notices, post_id => {guardrails, warnings}, surfaced into
	 * REST/MCP responses generically — see Mcpwp_REST_API::success_response().
	 *
	 * @var array<int,array>
	 */
	private static $notices_by_post_id = array();

	/**
	 * Post IDs a caller wants excluded from the NEXT duplicate_check() this
	 * request performs — e.g. a translation's own source post and sibling
	 * translations, which are SUPPOSED to closely resemble each other
	 * (#957 adversarial review: "translations are exempt from duplicate
	 * checks against posts in their own translation group"). Set by
	 * Mcpwp_Multilang immediately before its wp_insert_post()/
	 * wp_update_post() call, consumed (and cleared) by the very next
	 * on_insert_post_data() invocation.
	 *
	 * @var int[]
	 */
	private static $pending_exclude_ids = array();

	/**
	 * A publish-slot reservation made by try_reserve_daily_slot() inside
	 * on_insert_post_data(), not yet confirmed by a matching
	 * on_wp_insert_post() call. If the very next on_insert_post_data() pass
	 * (or end of request) finds this still set, the write it was reserved
	 * for never actually landed — release it (#957 adversarial review
	 * round 2 WARN-3: "release the reservation if the insert then fails").
	 *
	 * @var array{now:int}|null
	 */
	private static $pending_reservation = null;

	/**
	 * Mark the rest of this request as MCPWP-authenticated. Idempotent.
	 *
	 * @return void
	 */
	public static function enter_context() {
		self::$context_active = true;
	}

	/**
	 * @return bool Whether the current request is MCPWP-authenticated.
	 */
	public static function in_context() {
		return self::$context_active;
	}

	/**
	 * Reset every per-request static. Registered on `rest_pre_dispatch`
	 * (start of the NEXT REST request) and `shutdown` (end of THIS one) so
	 * a long-lived worker process (FrankenPHP, Swoole) reusing this PHP
	 * process across requests can never carry context, a cached token, or
	 * an unconfirmed reservation from one request into another (#957
	 * adversarial review round 2 NIT-3). Also the test suite's reset —
	 * PHPUnit runs many "requests" in one process.
	 *
	 * @return void
	 */
	public static function reset_per_request_state() {
		self::release_stale_reservation();
		self::$context_active      = false;
		self::$pending_notice       = null;
		self::$notices_by_post_id   = array();
		self::$pending_exclude_ids  = array();
		self::$pending_reservation  = null;
		self::$token_cache          = array();
	}

	/**
	 * @deprecated Alias for reset_per_request_state() — kept for
	 * call-site clarity in tests.
	 * @return void
	 */
	public static function reset_context_for_tests() {
		self::reset_per_request_state();
	}

	/**
	 * Register the WordPress core hooks this whole class runs on, plus the
	 * two per-request reset points. Called once, unconditionally, at the
	 * bottom of this file — every callback below no-ops instantly unless
	 * in_context() is true, so registering it always (production load AND
	 * every test that requires this file) costs nothing on a request/test
	 * that never enters context.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'on_insert_post_data' ), 10, 4 );
		add_action( 'wp_insert_post', array( __CLASS__, 'on_wp_insert_post' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition_post_status' ), 10, 3 );
		// `shutdown` — not `rest_pre_dispatch` — deliberately: rest_pre_dispatch
		// fires on EVERY nested rest_do_request() too (batch's per-operation
		// sub-dispatch, MCP's tools/call sub-dispatch), which would wipe
		// notices/reservation state accumulated by EARLIER operations in the
		// same outer request. `shutdown` is a PHP request-lifecycle event —
		// it fires exactly once per top-level HTTP request regardless of how
		// many nested dispatches happened inside it — the correct boundary
		// for "the NEXT request must not see anything from this one," and
		// also the only reliable place to release a reservation an insert
		// never confirmed (#957 adversarial review round 2 NIT-3).
		add_action( 'shutdown', array( __CLASS__, 'reset_per_request_state' ), 0 );
	}

	/**
	 * Exclude a set of post IDs from the very next duplicate_check() this
	 * request performs.
	 *
	 * @param int[] $ids Post IDs.
	 * @return void
	 */
	public static function set_duplicate_exclude_ids( array $ids ) {
		self::$pending_exclude_ids = array_map( 'absint', $ids );
	}

	/**
	 * Read and clear the exclude-list set by set_duplicate_exclude_ids().
	 *
	 * @return int[] Post IDs.
	 */
	private static function consume_duplicate_exclude_ids() {
		$ids                       = self::$pending_exclude_ids;
		self::$pending_exclude_ids = array();
		return $ids;
	}

	/**
	 * The `wp_insert_post_data` filter — fires for EVERY wp_insert_post()
	 * AND wp_update_post() call while in_context() is true, before the row
	 * is written. This is the single place a caller-controlled status is
	 * ever gated, regardless of which of the many call sites reached it.
	 *
	 * @param array $data                Sanitized fields about to be saved.
	 * @param array $postarr             Original input array (carries 'ID' on an update).
	 * @param array $unsanitized_postarr Unused here; part of core's signature.
	 * @param bool  $update              True for an update, false for a new post.
	 * @return array Possibly-modified $data.
	 */
	public static function on_insert_post_data( $data, $postarr = array(), $unsanitized_postarr = array(), $update = null ) {
		// Consumed UNCONDITIONALLY, on every single pass, before any early
		// return — a translation created as a DRAFT used to leave this set
		// for whatever the NEXT filter pass in the same request happened to
		// be, e.g. a wp_batch: create_translation (draft) then create_post
		// (publish) of an unrelated copy (#957 adversarial review round 2
		// WARN-2). Given PHP's synchronous, single-threaded execution and
		// that Mcpwp_Multilang calls the setter immediately before its own
		// wp_insert_post()/wp_update_post() call with nothing in between,
		// the very next pass IS always the intended target — consuming here
		// unconditionally both fixes the leak and is the scoping the setter
		// needs, with no post-ID keying possible (a new translation has no
		// ID yet when the setter runs).
		$exclude_ids_for_this_pass = self::consume_duplicate_exclude_ids();

		if ( ! self::in_context() || ! self::is_enabled() || ! is_array( $data ) ) {
			return $data;
		}

		// On an update, WordPress core's real wp_insert_post_data filter
		// receives $data already merged with the existing row's fields —
		// every column is present even if the caller's own $postarr only
		// mentioned 'status'. Look the existing row up explicitly rather
		// than assume that of $data, so a minimal update call (only ID +
		// status) is judged correctly by post_type/title/content that
		// already exists, not silently defaulted to 'post'/''/''.
		$post_id         = ! empty( $postarr['ID'] ) ? absint( $postarr['ID'] ) : 0;
		$existing        = $post_id ? get_post( $post_id ) : null;
		$previous_status = $existing ? (string) $existing->post_status : '';

		$post_type = isset( $data['post_type'] )
			? sanitize_key( (string) $data['post_type'] )
			: ( $existing ? $existing->post_type : 'post' );
		if ( ! in_array( $post_type, self::COVERED_POST_TYPES, true ) ) {
			return $data;
		}

		$new_status = isset( $data['post_status'] )
			? (string) $data['post_status']
			: ( $existing ? (string) $existing->post_status : 'draft' );

		// Already live AND staying live: a CONTENT EDIT to an already-
		// published post, not a new publish event — no ramp reservation.
		// But its content can still be swapped for a duplicate/city-swap
		// copy of another page: publish unique text once, then edit it
		// into a clone (#957 adversarial review round 2 WARN-1).
		if ( 'publish' === $previous_status && 'publish' === $new_status ) {
			return self::guard_published_content_edit( $data, $postarr, $existing, $post_type, $exclude_ids_for_this_pass );
		}

		// 'future' is coerced to 'publish' by WordPress core once the post
		// date arrives; treat scheduling as a publish attempt now, not later.
		if ( ! in_array( $new_status, array( 'publish', 'future' ), true ) ) {
			return $data;
		}

		// Reaching here with previous_status still 'publish' means the post
		// is moving OFF publish while somehow also asking for publish/future
		// (an inconsistent, not-really-a-case) — not a fresh publish, skip.
		if ( 'publish' === $previous_status ) {
			return $data;
		}

		$title = isset( $data['post_title'] )
			? (string) $data['post_title']
			: ( $existing ? (string) $existing->post_title : '' );

		$content_text = isset( $data['post_content'] )
			? wp_strip_all_tags( (string) $data['post_content'] )
			: ( $existing ? wp_strip_all_tags( (string) $existing->post_content ) : '' );

		// An existing post being updated may carry its real content in
		// _elementor_data rather than post_content — check it whenever
		// post_content itself is empty/too short to judge from (#957
		// adversarial review false-positive fix). A brand-new insert has no
		// ID yet to look postmeta up by, UNLESS the caller front-loaded
		// _elementor_data into $postarr['meta_input'] itself — a real
		// wp_insert_post() feature (writes postmeta atomically alongside the
		// post) that clone_page() uses specifically so a clone of an
		// Elementor page is duplicate-checked against its actual content
		// even though it has no ID yet. Anything that does NOT do this
		// (build_page(), create_landing_page()) legitimately publishes with
		// 0 words at create time — the same false-positive fix.
		if ( self::word_count( $content_text ) < self::MIN_COMPARABLE_WORDS ) {
			$meta_text = '';
			if ( $post_id ) {
				$meta_text = self::extract_text_from_elementor( get_post_meta( $post_id, '_elementor_data', true ) );
			} elseif ( isset( $postarr['meta_input']['_elementor_data'] ) ) {
				$meta_text = self::extract_text_from_elementor( $postarr['meta_input']['_elementor_data'] );
			}
			if ( '' !== trim( $meta_text ) ) {
				$content_text = $meta_text;
			}
		}

		$settings = self::get_settings();
		$now      = time();

		// Release any reservation a PRIOR call in this same request made
		// but never got confirmed (its insert must have failed before
		// wp_insert_post fired) — see try_reserve_daily_slot()'s docblock.
		self::release_stale_reservation();

		// Reserve the slot ATOMICALLY, inside this filter, before the row
		// is written — not "read the count now, increment it later once
		// the write is confirmed," which left a window where N parallel
		// requests all read count = cap-1 and all decided "under the cap"
		// before any of them incremented anything (#957 adversarial review
		// round 2 WARN-3). A successful reservation IS the count for
		// today; on_transition_post_status() below only checks for an
		// anomaly once the write is confirmed, it does not count again.
		$reserved      = self::try_reserve_daily_slot( $now, $settings['daily_publish_cap'] );
		$ramp_exceeded = ! $reserved;
		if ( $reserved ) {
			self::$pending_reservation = array( 'now' => $now );
		}
		// The count AFTER this reservation attempt, for the response body
		// — read fresh rather than inferred, so it is correct whether or
		// not this specific call got the slot.
		$daily_count = self::get_daily_count( $now );

		$word_count = self::word_count( $content_text );
		$thin       = $word_count < $settings['min_word_count'];

		$exclude_ids = array_merge( $post_id ? array( $post_id ) : array(), $exclude_ids_for_this_pass );
		$dup_limit   = min( self::HARD_DUPLICATE_COMPARE_CAP, $settings['duplicate_compare_limit'] );
		$dup         = self::duplicate_check( $post_type, $title, $content_text, $dup_limit, $exclude_ids );
		$dup_flag    = $dup['city_swap'] || ( $dup['score'] >= $settings['duplicate_threshold'] );

		$downgraded = $ramp_exceeded || $dup_flag;

		// The reservation succeeded (ramp allowed it) but something else
		// (duplicate/city-swap) still downgrades this write — give the slot
		// back, since it will not be published after all.
		if ( $reserved && $dup_flag ) {
			self::release_daily_slot( $now );
			self::$pending_reservation = null;
			$daily_count               = self::get_daily_count( $now );
		}

		$guardrails = array(
			'enabled'     => true,
			'covered'     => true,
			'downgraded'  => $downgraded,
			'ramp'        => array(
				'published_today' => $daily_count,
				'cap'              => $settings['daily_publish_cap'],
				'remaining'        => max( 0, $settings['daily_publish_cap'] - $daily_count ),
			),
			'thin_content' => array(
				'word_count'     => $word_count,
				'min_word_count' => $settings['min_word_count'],
				'is_thin'        => $thin,
			),
			'duplicate'    => array(
				'checked'   => true,
				'score'     => round( $dup['score'], 4 ),
				'threshold' => $settings['duplicate_threshold'],
				'match_id'  => $dup['match_id'],
				'city_swap' => $dup['city_swap'],
				'flagged'   => $dup_flag,
			),
		);

		$warnings = array();

		// Thin content is WARNING ONLY — never a reason to downgrade
		// (#957 adversarial review false-positive fix: contact/thank-you
		// pages and Elementor-first pages that publish with 0 words at
		// create time must still publish).
		if ( $thin ) {
			$warnings[] = sprintf(
				/* translators: 1: word count, 2: minimum word count. */
				__( 'Content is %1$d words, below the usual %2$d-word floor for published content. If this is a short intentional page (contact, thank-you), no action needed.', 'mcpwp' ),
				$word_count,
				$settings['min_word_count']
			);
		}

		if ( $downgraded ) {
			$data['post_status'] = 'draft';

			if ( $ramp_exceeded ) {
				$warnings[] = sprintf(
					/* translators: 1: published count today, 2: daily cap. */
					__( 'Publishing cap reached (%1$d/%2$d published today) — created as a draft instead. Publish it manually from wp-admin, or wait for the next UTC day.', 'mcpwp' ),
					$daily_count,
					$settings['daily_publish_cap']
				);
			}
			if ( $dup_flag ) {
				$warnings[] = $dup['city_swap']
					? sprintf(
						/* translators: 1: post type, 2: matched post ID. */
						__( 'Content matches an existing %1$s (#%2$d) once the location/name in the title is set aside — created as a draft instead. Add real per-location differentiation before publishing.', 'mcpwp' ),
						$post_type,
						(int) $dup['match_id']
					)
					: sprintf(
						/* translators: 1: similarity percentage, 2: post type, 3: matched post ID. */
						__( 'Content is %1$d%% similar to an existing %2$s (#%3$d) — created as a draft instead. Add unique content before publishing.', 'mcpwp' ),
						round( $dup['score'] * 100 ),
						$post_type,
						(int) $dup['match_id']
					);
			}
		}

		$notice = array( 'guardrails' => $guardrails, 'warnings' => $warnings );
		if ( $post_id ) {
			// Updates: the ID already exists, stamp the notice directly.
			self::$notices_by_post_id[ $post_id ] = $notice;
		} else {
			// Creates: no ID yet — claimed by on_wp_insert_post() below.
			self::$pending_notice = $notice;
		}

		return $data;
	}

	/**
	 * Guard a content edit to an ALREADY-published post staying published
	 * (#957 adversarial review round 2 WARN-1). Refuses the content change
	 * — reverting post_content/post_title back to what is currently live —
	 * rather than either (a) letting a live page silently become a
	 * duplicate/city-swap copy, or (b) downgrading it to draft, which would
	 * UNPUBLISH an already-live, possibly already-indexed page: a much
	 * larger, more surprising blast radius than declining one edit. The
	 * rest of the same write (categories, template, meta, ...) still
	 * applies; only the content-bearing fields revert.
	 *
	 * Deliberately cheap when nothing content-bearing is even in this
	 * write (no query at all) — most updates (status, categories, a single
	 * meta field) never touch content and must stay free.
	 *
	 * @param array  $data        Sanitized fields about to be saved.
	 * @param array  $postarr     Original input array.
	 * @param object $existing    The post as it currently is, before this write.
	 * @param string $post_type   Post type.
	 * @param int[]  $exclude_ids Extra exclude IDs consumed for this pass.
	 * @return array Possibly-modified $data.
	 */
	private static function guard_published_content_edit( array $data, array $postarr, $existing, $post_type, array $exclude_ids = array() ) {
		$post_id = (int) $existing->ID;

		$content_provided   = array_key_exists( 'post_content', $data );
		$elementor_provided = isset( $postarr['meta_input']['_elementor_data'] );

		if ( ! $content_provided && ! $elementor_provided ) {
			return $data; // Nothing content-bearing here — cheap, no query.
		}

		$old_text = self::get_post_effective_text( $existing );

		$new_text = $elementor_provided
			? self::extract_text_from_elementor( $postarr['meta_input']['_elementor_data'] )
			: '';
		if ( '' === trim( $new_text ) && $content_provided ) {
			$new_text = wp_strip_all_tags( (string) $data['post_content'] );
		}

		// Cheap bail: content is unchanged (an edit to title/categories/
		// meta alone, or a resubmission of the same body) — nothing new to
		// duplicate-check.
		if ( trim( $new_text ) === trim( $old_text ) ) {
			return $data;
		}

		$settings    = self::get_settings();
		$title       = isset( $data['post_title'] ) ? (string) $data['post_title'] : (string) $existing->post_title;
		$dup_limit   = min( self::HARD_DUPLICATE_COMPARE_CAP, $settings['duplicate_compare_limit'] );
		$full_exclude = array_merge( array( $post_id ), $exclude_ids );
		$dup         = self::duplicate_check( $post_type, $title, $new_text, $dup_limit, $full_exclude );
		$dup_flag    = $dup['city_swap'] || ( $dup['score'] >= $settings['duplicate_threshold'] );

		if ( ! $dup_flag ) {
			return $data;
		}

		if ( $content_provided ) {
			$data['post_content'] = $existing->post_content;
		}
		if ( isset( $data['post_title'] ) ) {
			$data['post_title'] = $existing->post_title;
		}
		// NOTE (known limit — see the class docblock): an _elementor_data
		// change delivered via meta_input cannot be reverted from here.
		// WordPress core applies meta_input from the ORIGINAL $postarr, not
		// from this filter's returned $data, so nothing this filter does
		// can stop that specific write landing. Detected and warned
		// regardless.

		self::$notices_by_post_id[ $post_id ] = array(
			'guardrails' => array(
				'enabled'              => true,
				'covered'              => true,
				'content_edit_refused' => true,
				'duplicate'            => array(
					'checked'   => true,
					'score'     => round( $dup['score'], 4 ),
					'threshold' => $settings['duplicate_threshold'],
					'match_id'  => $dup['match_id'],
					'city_swap' => $dup['city_swap'],
					'flagged'   => true,
				),
			),
			'warnings'   => array(
				$dup['city_swap']
					? sprintf(
						/* translators: 1: post type, 2: matched post ID. */
						__( 'This content change was refused: it matches an existing %1$s (#%2$d) once the location/name in the title is set aside — the live content was left unchanged. Add real per-location differentiation, then save again.', 'mcpwp' ),
						$post_type,
						(int) $dup['match_id']
					)
					: sprintf(
						/* translators: 1: similarity percentage, 2: post type, 3: matched post ID. */
						__( 'This content change was refused: it is %1$d%% similar to an existing %2$s (#%3$d) — the live content was left unchanged. Add unique content, then save again.', 'mcpwp' ),
						round( $dup['score'] * 100 ),
						$post_type,
						(int) $dup['match_id']
					),
			),
		);

		return $data;
	}

	/**
	 * The `wp_insert_post` action — fires after a NEW row (and only a new
	 * one; updates are already stamped directly above) is written, with the
	 * real post ID now known. Claims the pending, ID-less notice.
	 *
	 * @param int    $post_id Post ID.
	 * @param object $post    Post object.
	 * @param bool   $update  True for an update.
	 * @return void
	 */
	public static function on_wp_insert_post( $post_id, $post, $update ) {
		// The write landed — confirm (clear) any reservation
		// on_insert_post_data() made for it. If this ISN'T cleared by the
		// time the very next on_insert_post_data() pass (or shutdown) runs,
		// that means the insert never reached here — release_stale_
		// reservation() gives the slot back (#957 adversarial review
		// round 2 WARN-3: "release the reservation if the insert then fails").
		self::$pending_reservation = null;

		if ( ! $update && null !== self::$pending_notice ) {
			self::$notices_by_post_id[ (int) $post_id ] = self::$pending_notice;
			self::$pending_notice = null;
		}
	}

	/**
	 * The `transition_post_status` action — fires after a write actually
	 * lands, with the FINAL status (i.e. whatever on_insert_post_data()
	 * above left it as). Counting/reservation already happened ATOMICALLY
	 * inside on_insert_post_data(), before this write was even attempted
	 * (try_reserve_daily_slot()) — this only checks for an anomaly using
	 * the now-confirmed count. It must NOT increment anything itself, or
	 * the count would be off by one for every successful publish (#957
	 * adversarial review round 2 WARN-3: "the transition hook must not
	 * count it a second time").
	 *
	 * @param string $new_status New status.
	 * @param string $old_status Old status ('new' for a brand-new post).
	 * @param object $post       Post object.
	 * @return void
	 */
	public static function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! self::in_context() ) {
			return;
		}
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! is_object( $post ) || ! in_array( $post->post_type, self::COVERED_POST_TYPES, true ) ) {
			return;
		}

		self::maybe_emit_anomaly( $post->post_type, time() );
	}

	/**
	 * Merge any notice recorded for a post ID somewhere in this request into
	 * a REST/MCP response, generically — called once from
	 * Mcpwp_REST_API::success_response() so every tool reports guardrails
	 * results without each individual controller method needing to ask for
	 * them (#957 adversarial review design fix: "so every tool reports it,
	 * no per-site plumbing").
	 *
	 * Walks the response tree looking for a numeric 'id' key at any of the
	 * first few levels (covers a single-item response like create_page()'s
	 * own return, and a bulk response's `created[]` list) and merges that
	 * post's notice directly onto that node. Any notice left unclaimed after
	 * the walk (a response shape with no matching 'id' anywhere — e.g. one
	 * that only ever returns a bare int) is still surfaced, under a
	 * top-level `guardrails_notices` fallback, so nothing is silently lost.
	 *
	 * @param array $data Response data, modified in place.
	 * @return void
	 */
	public static function attach_notices_to_response( array &$data ) {
		if ( empty( self::$notices_by_post_id ) ) {
			return;
		}
		self::attach_notices_recursive( $data, 0 );
		if ( ! empty( self::$notices_by_post_id ) ) {
			$data['guardrails_notices'] = array_values( self::$notices_by_post_id );
			self::$notices_by_post_id   = array();
		}
	}

	/**
	 * @param array $node  Response subtree, modified in place.
	 * @param int   $depth Recursion guard.
	 * @return void
	 */
	private static function attach_notices_recursive( array &$node, $depth ) {
		if ( $depth > 3 || empty( self::$notices_by_post_id ) ) {
			return;
		}

		if ( isset( $node['id'] ) && is_numeric( $node['id'] ) && isset( self::$notices_by_post_id[ (int) $node['id'] ] ) ) {
			$notice = self::$notices_by_post_id[ (int) $node['id'] ];
			if ( ! empty( $notice['guardrails'] ) ) {
				$node['guardrails'] = $notice['guardrails'];
			}
			if ( ! empty( $notice['warnings'] ) ) {
				$node['warnings'] = array_merge( isset( $node['warnings'] ) ? (array) $node['warnings'] : array(), $notice['warnings'] );
			}
			unset( self::$notices_by_post_id[ (int) $node['id'] ] );
		}

		foreach ( $node as &$value ) {
			if ( is_array( $value ) ) {
				self::attach_notices_recursive( $value, $depth + 1 );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Bulk-call chokepoint (rule 2)

	/**
	 * Decide whether a bulk creation call (bulk_create_pages/posts, a
	 * blueprint deploy, etc.) must go through the approval flow before any
	 * of its items are actually published.
	 *
	 * @param array $items List of item definitions, each optionally carrying
	 *                     a 'status' key.
	 * @return array { requires_approval: bool, publish_count: int, threshold: int }
	 */
	public static function plan_bulk_publish( array $items ) {
		$settings      = self::get_settings();
		$publish_count = 0;

		foreach ( $items as $item ) {
			if ( is_array( $item ) && isset( $item['status'] ) && 'publish' === sanitize_key( (string) $item['status'] ) ) {
				$publish_count++;
			}
		}

		$threshold = $settings['bulk_approval_threshold'];

		return array(
			'requires_approval' => self::is_enabled() && $publish_count > $threshold,
			'publish_count'     => $publish_count,
			'threshold'         => $threshold,
		);
	}

	/**
	 * Queue an approval request to publish an already-drafted batch at once.
	 *
	 * The batch was already written as drafts by the caller — this only
	 * queues the decision to flip them all to 'publish'. Snapshots each
	 * post's content hash + findings NOW (#957 adversarial review WARN-1),
	 * so the approver sees why (if anything) each item was flagged, and
	 * apply_bulk_publish() below can refuse to publish anything a human
	 * edited after this snapshot was taken. Delegates the actual request
	 * storage to Mcpwp_Approvals, which owns the lifecycle.
	 *
	 * @param int[]  $post_ids  IDs already created as drafts.
	 * @param string $post_type 'post' or 'page'.
	 * @param array  $args      title/note/tool/requested_by_key_id.
	 * @return array|WP_Error Public approval record.
	 */
	public static function request_bulk_publish_approval( array $post_ids, $post_type, array $args = array() ) {
		if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
			return new WP_Error( 'approvals_unavailable', __( 'The approval pipeline is not available.', 'mcpwp' ), array( 'status' => 500 ) );
		}

		$items = array();
		foreach ( $post_ids as $post_id ) {
			$snapshot = self::snapshot_for_approval( absint( $post_id ), $post_type );
			if ( null !== $snapshot ) {
				$items[] = $snapshot;
			}
		}

		return Mcpwp_Approvals::create_bulk_publish_request( $items, $post_type, $args );
	}

	/**
	 * Content hash + findings for one post, at the moment it is queued for
	 * bulk-publish approval.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type 'post' or 'page'.
	 * @return array|null { id, hash, findings } or null if the post is gone.
	 */
	public static function snapshot_for_approval( $post_id, $post_type ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$text      = self::get_post_effective_text( $post );
		$settings  = self::get_settings();
		$dup_limit = min( self::HARD_DUPLICATE_COMPARE_CAP, $settings['duplicate_compare_limit'] );
		$dup       = self::duplicate_check( $post_type, $post->post_title, $text, $dup_limit, array( $post_id ) );

		return array(
			'id'       => $post_id,
			'hash'     => self::content_hash( $post ),
			'findings' => array(
				'word_count'      => self::word_count( $text ),
				'duplicate_score' => round( $dup['score'], 4 ),
				'city_swap'       => $dup['city_swap'],
			),
		);
	}

	/**
	 * Meta keys, beyond post_content/_elementor_data, whose edit after
	 * queueing a bulk-publish approval must ALSO count as "this post
	 * changed" — the common SEO-plugin title/description fields (#957
	 * adversarial review round 2 NIT-5). Read defensively (get_post_meta()
	 * on an absent key just returns '') — no plugin-detection needed, an
	 * inactive SEO plugin's keys are simply always empty on both sides of
	 * the comparison.
	 *
	 * @var string[]
	 */
	const CONTENT_HASH_SEO_META_KEYS = array(
		'_yoast_wpseo_title',
		'_yoast_wpseo_metadesc',
		'rank_math_title',
		'rank_math_description',
		'_aioseo_title',
		'_aioseo_description',
	);

	/**
	 * A post's content fingerprint — title, slug, post_content,
	 * _elementor_data, and the common SEO title/description meta keys —
	 * used to detect whether a post was edited between being queued for
	 * bulk-publish approval and the approval actually being applied.
	 * post_title/post_name are included alongside body content because a
	 * title-only edit (the exact field a city-swap rename touches) must
	 * also be caught (#957 adversarial review round 2 NIT-5: hashing only
	 * post_content/_elementor_data missed exactly that).
	 *
	 * @param object $post Post object.
	 * @return string SHA-256 hex digest.
	 */
	public static function content_hash( $post ) {
		$meta = get_post_meta( $post->ID, '_elementor_data', true );

		$seo_values = array();
		foreach ( self::CONTENT_HASH_SEO_META_KEYS as $key ) {
			$seo_values[] = (string) get_post_meta( $post->ID, $key, true );
		}

		return hash(
			'sha256',
			(string) $post->post_title . '|' .
			(string) $post->post_name . '|' .
			(string) $post->post_content . '|' .
			(string) $meta . '|' .
			implode( '|', $seo_values )
		);
	}

	/**
	 * Apply (or roll back) a bulk-publish approval request. Called from
	 * Mcpwp_Approvals::apply_payload() — never called directly by a
	 * controller, so the approval lifecycle (status transitions, actor
	 * checks) stays entirely inside Mcpwp_Approvals.
	 *
	 * Apply re-checks EVERY item at the moment it runs (#957 adversarial
	 * review WARN-1): a post whose content hash no longer matches its
	 * approval-time snapshot is skipped, not published — someone edited it
	 * since a human reviewed the batch, so what would publish is not what
	 * was approved. The ramp cap and duplicate check are ALSO re-run for
	 * free: wp_update_post() below runs through on_insert_post_data() like
	 * any other write in this class, so a batch approved yesterday but
	 * applied today still respects today's cap.
	 *
	 * Rollback only reverts posts THIS SPECIFIC apply actually published,
	 * and only while they are still exactly where apply left them (#957
	 * adversarial review WARN-2) — never a post this apply skipped, and
	 * never one a human has since touched again.
	 *
	 * @param array  $request Approval record.
	 * @param string $field   'payload' to publish, 'rollback' to revert to draft.
	 * @return true|WP_Error
	 */
	public static function apply_bulk_publish( array $request, $field ) {
		// Applying (or rolling back) a bulk-publish approval always
		// re-runs the same cap/duplicate guard the original publish would
		// have — from wp-admin's Control Room just as much as from the
		// API (#957 adversarial review round 2 NIT-4: the docblock below
		// already claimed this; entering context here is what actually
		// makes it true regardless of which door apply came through, since
		// wp-admin never otherwise calls verify_api_key()). Only turn
		// context OFF again afterward if THIS call is what turned it on —
		// an apply reached via the REST API (context already on) must
		// leave it on for the rest of that request.
		$context_was_active = self::in_context();
		if ( ! $context_was_active ) {
			self::enter_context();
		}

		$result = self::apply_bulk_publish_guarded( $request, $field );

		if ( ! $context_was_active ) {
			self::$context_active = false;
		}

		return $result;
	}

	/**
	 * The actual apply/rollback logic — see apply_bulk_publish() for the
	 * context-guard wrapper around this.
	 *
	 * @param array  $request Approval record.
	 * @param string $field   'payload' to publish, 'rollback' to revert to draft.
	 * @return true|WP_Error
	 */
	private static function apply_bulk_publish_guarded( array $request, $field ) {
		$data      = isset( $request[ $field ] ) && is_array( $request[ $field ] ) ? $request[ $field ] : array();
		$post_type = isset( $data['post_type'] ) ? sanitize_key( (string) $data['post_type'] ) : 'post';
		$items     = self::normalize_bulk_publish_items( $data );

		if ( empty( $items ) ) {
			return new WP_Error( 'no_items', __( 'No items recorded on this approval request.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		if ( 'rollback' === $field ) {
			return self::rollback_bulk_publish( $request, $items );
		}

		$published = array();
		$skipped   = array();

		foreach ( $items as $item ) {
			$post_id = (int) $item['id'];
			$post    = get_post( $post_id );

			if ( ! $post ) {
				$skipped[] = array( 'id' => $post_id, 'reason' => 'not_found' );
				continue;
			}
			if ( null !== $item['hash'] && self::content_hash( $post ) !== $item['hash'] ) {
				$skipped[] = array( 'id' => $post_id, 'reason' => 'content_changed_since_approval' );
				continue;
			}

			$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true );
			if ( is_wp_error( $result ) ) {
				$skipped[] = array( 'id' => $post_id, 'reason' => $result->get_error_message() );
				continue;
			}

			// on_insert_post_data() may have independently downgraded this
			// to draft (ramp cap / duplicate, re-checked live) — the write
			// above can succeed while still not landing as 'publish'.
			$after = get_post( $post_id );
			if ( ! $after || 'publish' !== $after->post_status ) {
				$skipped[] = array( 'id' => $post_id, 'reason' => 'guardrails_downgraded_at_apply' );
				continue;
			}

			$published[] = $post_id;
		}

		self::record_apply_result( isset( $request['id'] ) ? (string) $request['id'] : '', $published, $skipped );

		return true;
	}

	/**
	 * Roll back a bulk-publish apply — see apply_bulk_publish()'s docblock
	 * for the WARN-2 scope rules.
	 *
	 * @param array $request Approval record (needs 'id' and 'metadata').
	 * @param array $items   Normalized {id, hash} items.
	 * @return true
	 */
	private static function rollback_bulk_publish( array $request, array $items ) {
		$applied_ids = isset( $request['metadata']['applied_ids'] )
			? array_map( 'absint', (array) $request['metadata']['applied_ids'] )
			: array();

		$reverted = array();
		foreach ( $items as $item ) {
			$post_id = (int) $item['id'];

			// Never touched by THIS apply — nothing here for rollback to undo.
			if ( ! in_array( $post_id, $applied_ids, true ) ) {
				continue;
			}
			$post = get_post( $post_id );
			// Not still exactly where apply left it (a human already
			// changed it again) — don't stomp on that.
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}

			$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
			if ( ! is_wp_error( $result ) ) {
				$reverted[] = $post_id;
			}
		}

		self::record_apply_result( isset( $request['id'] ) ? (string) $request['id'] : '', array(), array(), $reverted );

		return true;
	}

	/**
	 * Accept either the current {items: [{id,hash,findings}]} payload shape
	 * or an older {post_ids: [...]} record created before this rework
	 * shipped, so an approval queued on the previous version of this class
	 * still applies (as unconditional publishes — no hash to compare, no
	 * findings to show) instead of fataling.
	 *
	 * @param array $data Payload or rollback array.
	 * @return array[] Normalized {id: int, hash: string|null} items.
	 */
	private static function normalize_bulk_publish_items( array $data ) {
		if ( ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
			$items = array();
			foreach ( $data['items'] as $item ) {
				if ( is_array( $item ) && isset( $item['id'] ) ) {
					$items[] = array(
						'id'   => absint( $item['id'] ),
						'hash' => isset( $item['hash'] ) ? (string) $item['hash'] : null,
					);
				}
			}
			return $items;
		}
		if ( ! empty( $data['post_ids'] ) ) {
			return array_map(
				function ( $id ) {
					return array( 'id' => absint( $id ), 'hash' => null );
				},
				(array) $data['post_ids']
			);
		}
		return array();
	}

	/**
	 * Persist what an apply/rollback actually did onto the approval record
	 * itself, so a subsequent rollback knows which IDs are its to touch
	 * (WARN-2) and a human reviewing the record later can see what
	 * happened. Reaches directly into Mcpwp_Approvals's option store —
	 * the same pattern includes/pro/api/class-mcpwp-rest-staging.php
	 * already uses via the public Mcpwp_Approvals::OPTION_NAME constant.
	 *
	 * @param string $request_id Approval ID.
	 * @param int[]  $published  IDs actually flipped to publish.
	 * @param array  $skipped    {id, reason} entries not published/reverted.
	 * @param int[]  $reverted   IDs actually flipped back to draft (rollback).
	 * @return void
	 */
	private static function record_apply_result( $request_id, array $published = array(), array $skipped = array(), array $reverted = array() ) {
		if ( '' === $request_id || ! class_exists( 'Mcpwp_Approvals' ) ) {
			return;
		}
		$items = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
		if ( ! isset( $items[ $request_id ] ) || ! is_array( $items[ $request_id ] ) ) {
			return;
		}

		if ( ! empty( $published ) ) {
			$items[ $request_id ]['metadata']['applied_ids'] = $published;
		}
		if ( ! empty( $published ) || ! empty( $skipped ) ) {
			$items[ $request_id ]['metadata']['apply_summary'] = array(
				'published' => $published,
				'skipped'   => $skipped,
			);
		}
		if ( ! empty( $reverted ) ) {
			$items[ $request_id ]['metadata']['reverted_ids'] = $reverted;
		}

		update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );
	}

	// -------------------------------------------------------------------------
	// Publish counting + anomaly signal (rule 4)
	//
	// One option per UTC calendar day (`mcpwp_publish_count_YYYYMMDD`). The
	// slot is RESERVED atomically inside on_insert_post_data() — a
	// compare-and-increment that only succeeds below the cap
	// (try_reserve_daily_slot()) — BEFORE the row is written, not read
	// separately and incremented later once the write is confirmed. That
	// separation was round 1's actual bug (#957 adversarial review round 2
	// WARN-3): read count=24, decide "under 25," write the post, increment
	// to 25 afterward — with N requests doing exactly that at the same
	// instant, all N read 24, all N decide "under 25," and cap+(N-1) posts
	// land, not cap. Reserving the slot AS the gate closes the window: the
	// reservation primitive is the compare-and-swap on the option row
	// itself, so only as many callers as there are remaining slots can
	// ever get the reservation, however many ask at the exact same instant
	// — proven on the rig with 50 genuinely parallel requests at cap-1:
	// exactly 1 published (see the PR description for the run).
	//
	// This trades the issue's original "rolling 24h" wording for a UTC
	// calendar day — the atomicity primitive above needs a bucket with a
	// fixed key, and a rolling window has none. The tradeoff (a burst right
	// at UTC midnight can publish close to 2x the cap within an hour) is a
	// known, accepted limit, not the intended behavior — see the PR
	// description.

	/**
	 * @param int $now Unix timestamp.
	 * @return string 'YYYYMMDD', UTC.
	 */
	private static function day_key( $now ) {
		return gmdate( 'Ymd', $now );
	}

	/**
	 * Current UTC day's published-post count.
	 *
	 * Reads via the same raw options-row path increment_daily_count() writes
	 * through (Mcpwp_Encryption::read_option_row(), which explicitly bypasses
	 * the object cache) rather than the cached Options API — a value just
	 * written by a raw SQL UPDATE is not necessarily reflected by a cached
	 * get_option() read in the same request otherwise.
	 *
	 * @param int|null $now Unix timestamp; defaults to time().
	 * @return int Count.
	 */
	public static function get_daily_count( $now = null ) {
		$now         = null === $now ? time() : (int) $now;
		$option_name = 'mcpwp_publish_count_' . self::day_key( $now );

		if ( class_exists( 'Mcpwp_Encryption' ) ) {
			$row = Mcpwp_Encryption::read_option_row( $option_name );
			if ( Mcpwp_Encryption::ROW_PRESENT === $row['state'] ) {
				return (int) $row['value'];
			}
			if ( Mcpwp_Encryption::ROW_ABSENT === $row['state'] ) {
				return 0;
			}
			// ROW_ERROR (no usable $wpdb) — fall through to the Options API.
		}

		return (int) get_option( $option_name, 0 );
	}

	/**
	 * Atomically reserve one publish slot for today, IFF the count is
	 * currently below $cap — a compare-and-INCREMENT, not a plain
	 * increment. Reading the count and incrementing it later (once the
	 * write is confirmed) leaves a window where N requests all read
	 * count = cap-1, all decide "under the cap," and all publish — cap+
	 * (N-1) posts land, not cap (#957 adversarial review round 2 WARN-3).
	 * Reserving BEFORE the write, gated on the cap, closes that window:
	 * only as many callers as there are remaining slots can ever get
	 * `true` back, however many call this at the exact same instant.
	 *
	 * Reuses Mcpwp_Encryption's existing, already-tested first-writer-wins
	 * insert + byte-exact compare-and-swap primitives (the same shape
	 * Mcpwp_Approvals::set_triage() already relies on) instead of a
	 * hand-rolled `option_value + 1` UPDATE — a raw arithmetic UPDATE is
	 * not portable the way a plain string compare-and-swap is, and reusing
	 * tested infrastructure beats re-deriving the same guarantee twice.
	 *
	 * Retries a bounded number of times, WITH jitter so many callers
	 * retrying a lost race don't all immediately collide again, against
	 * whatever another writer's concurrent reservation left behind. If
	 * every attempt is exhausted under contention, this FAILS CLOSED —
	 * returns false (treated as "at cap," downgraded to draft) rather than
	 * falling through to a non-atomic read-then-write that could silently
	 * let the cap be exceeded (#957 adversarial review round 2 NIT-2: never
	 * claim a guarantee this does not actually have under contention).
	 *
	 * @param int $now Unix timestamp.
	 * @param int $cap Today's cap.
	 * @return bool True: slot reserved (count incremented). False: at cap,
	 *              or contention exhausted every retry (fails closed).
	 */
	private static function try_reserve_daily_slot( $now, $cap ) {
		$option_name = 'mcpwp_publish_count_' . self::day_key( $now );

		if ( ! class_exists( 'Mcpwp_Encryption' ) ) {
			// No usable $wpdb at all (e.g. a unit test without the double) —
			// best-effort, non-atomic fallback; the atomicity guarantee
			// above is a production property this path cannot provide.
			$current = (int) get_option( $option_name, 0 );
			if ( $current >= $cap ) {
				return false;
			}
			update_option( $option_name, $current + 1, false );
			return true;
		}

		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$row = Mcpwp_Encryption::read_option_row( $option_name );

			if ( Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
				$current = (int) get_option( $option_name, 0 );
				if ( $current >= $cap ) {
					return false;
				}
				update_option( $option_name, $current + 1, false );
				return true;
			}

			if ( Mcpwp_Encryption::ROW_ABSENT === $row['state'] ) {
				if ( $cap < 1 ) {
					return false;
				}
				if ( Mcpwp_Encryption::insert_option_row_if_absent( $option_name, '1', 'no' ) ) {
					return true; // First reservation today — starts at 1.
				}
				continue; // Someone else just created it — re-read and CAS from there.
			}

			$current = (int) $row['value'];
			if ( $current >= $cap ) {
				return false; // At/over cap — no reservation, no state change.
			}

			$next = (string) ( $current + 1 );
			if ( Mcpwp_Encryption::compare_and_swap_option_row( $option_name, $row['value'], $next ) ) {
				Mcpwp_Encryption::clean_option_cache( $option_name );
				return true;
			}

			// Lost the race to a concurrent reserver — retry against
			// whatever is there now, with jitter so many losers of the
			// same race don't all retry in lockstep and collide again.
			usleep( random_int( 1000, 5000 ) );
		}

		// Exhausted every retry under heavy contention — fail closed.
		return false;
	}

	/**
	 * Give back a reservation made by try_reserve_daily_slot() that turned
	 * out not to be needed after all (a duplicate/city-swap hit downgraded
	 * the write even though the ramp cap allowed it) — or one whose insert
	 * never actually landed, via release_stale_reservation() below.
	 *
	 * @param int $now Unix timestamp.
	 * @return void
	 */
	private static function release_daily_slot( $now ) {
		$option_name = 'mcpwp_publish_count_' . self::day_key( $now );

		if ( ! class_exists( 'Mcpwp_Encryption' ) ) {
			$current = (int) get_option( $option_name, 0 );
			update_option( $option_name, max( 0, $current - 1 ), false );
			return;
		}

		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$row = Mcpwp_Encryption::read_option_row( $option_name );
			if ( Mcpwp_Encryption::ROW_PRESENT !== $row['state'] ) {
				return; // Nothing there to release.
			}
			$current = (int) $row['value'];
			if ( $current <= 0 ) {
				return;
			}
			$next = (string) ( $current - 1 );
			if ( Mcpwp_Encryption::compare_and_swap_option_row( $option_name, $row['value'], $next ) ) {
				Mcpwp_Encryption::clean_option_cache( $option_name );
				return;
			}
			usleep( random_int( 1000, 5000 ) );
		}
	}

	/**
	 * Release a reservation from an EARLIER on_insert_post_data() pass in
	 * this same request that never got confirmed by a matching
	 * on_wp_insert_post() call — meaning that insert failed somewhere
	 * between the data filter and the row actually being written (#957
	 * adversarial review round 2 WARN-3: "release the reservation if the
	 * insert then fails"). Called at the top of the next reservation
	 * attempt, and on `shutdown` for the last call in a request.
	 *
	 * @return void
	 */
	private static function release_stale_reservation() {
		if ( null !== self::$pending_reservation ) {
			self::release_daily_slot( self::$pending_reservation['now'] );
			self::$pending_reservation = null;
		}
	}

	/**
	 * Read-only status block for wp_onboard / wp_site_info.
	 *
	 * @param int|null $now Unix timestamp; defaults to time().
	 * @return array Status summary.
	 */
	public static function get_status_summary( $now = null ) {
		$settings = self::get_settings();
		$count    = self::get_daily_count( $now );

		return array(
			'enabled'                 => $settings['enabled'],
			'published_today_utc'     => $count,
			'daily_publish_cap'       => $settings['daily_publish_cap'],
			'remaining_today'         => max( 0, $settings['daily_publish_cap'] - $count ),
			'bulk_approval_threshold' => $settings['bulk_approval_threshold'],
			'min_word_count'          => $settings['min_word_count'],
		);
	}

	/**
	 * Emit 'content.publish_spike' the moment today's count crosses both an
	 * absolute floor and 2x (by default) the trailing 30-day daily average
	 * — fires once per crossing (the increment that pushes the count from
	 * at-or-below the threshold to above it), not on every publish once
	 * already over the line.
	 *
	 * @param string $post_type Post type just published.
	 * @param int    $now       Unix timestamp, AFTER today's count was
	 *                          already incremented by the caller.
	 * @return void
	 */
	private static function maybe_emit_anomaly( $post_type, $now ) {
		if ( ! class_exists( 'Mcpwp_Event_Store' ) ) {
			return;
		}

		$settings    = self::get_settings();
		$today_count = self::get_daily_count( $now );
		$before      = $today_count - 1;

		$baseline_total = 0;
		for ( $i = 1; $i <= 30; $i++ ) {
			$baseline_total += self::get_daily_count( $now - ( $i * DAY_IN_SECONDS ) );
		}
		$daily_avg = $baseline_total / 30;
		$threshold = max( (float) $settings['anomaly_min_count'], $settings['anomaly_multiplier'] * $daily_avg );

		if ( $today_count >= $settings['anomaly_min_count'] && $today_count > $threshold && $before <= $threshold ) {
			Mcpwp_Event_Store::emit(
				'content.publish_spike',
				array(
					'published_today_utc' => $today_count,
					'daily_average_30d'   => round( $daily_avg, 2 ),
					'threshold'           => round( $threshold, 2 ),
					'post_type'           => sanitize_key( (string) $post_type ),
				),
				array(
					'risk_level'         => 'medium',
					'recommended_action' => __( 'Review recent publishing activity for scaled or automated content.', 'mcpwp' ),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Thin-content / duplicate detection

	/**
	 * Minimum word count a document (new content, or a candidate post) must
	 * have before it is even worth comparing — below this, "no comparable
	 * text" (an Elementor-first page whose builder hasn't run yet, a
	 * contact-form shortcode-only page, etc.) is the honest answer, not
	 * "0% similar" or a spurious match (#957 adversarial review: "skip
	 * posts with no comparable text").
	 *
	 * @var int
	 */
	const MIN_COMPARABLE_WORDS = 10;

	/**
	 * Compare new content against the site's recent published posts of the
	 * same type.
	 *
	 * @param string $post_type    Post type.
	 * @param string $title        New title.
	 * @param string $content_text New content (plain text).
	 * @param int    $limit        Recent posts to compare against.
	 * @param int[]  $exclude_ids  Post IDs never compared against — e.g. the
	 *                             post being updated itself (re-checks at
	 *                             apply time), or a translation's own
	 *                             source/sibling posts in its translation
	 *                             group (#957 adversarial review).
	 * @return array { score: float, match_id: int|null, city_swap: bool }
	 */
	private static function duplicate_check( $post_type, $title, $content_text, $limit, array $exclude_ids = array() ) {
		$limit = min( self::HARD_DUPLICATE_COMPARE_CAP, max( 1, (int) $limit ) );

		// Nothing meaningful to compare on the NEW side — an Elementor-first
		// page has no post_content yet at create time, a contact/thank-you
		// page may be legitimately near-empty. Reporting "0% similar" would
		// be misleading; reporting no match at all is the honest answer.
		if ( self::word_count( $content_text ) < self::MIN_COMPARABLE_WORDS ) {
			return array( 'score' => 0.0, 'match_id' => null, 'city_swap' => false );
		}

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		if ( ! empty( $exclude_ids ) ) {
			$query_args['post__not_in'] = array_map( 'absint', $exclude_ids );
		}
		$recent = get_posts( $query_args );

		$new_tokens_words = self::normalize_words( $content_text );
		$new_shingles     = self::shingles_from_tokens( $new_tokens_words );
		$new_title_tokens = self::proper_noun_tokens( $title );

		$best = array(
			'score'     => 0.0,
			'match_id'  => null,
			'city_swap' => false,
		);

		foreach ( $recent as $post ) {
			$cand_text = self::get_post_effective_text( $post );

			// Skip posts with no comparable text at all (no post_content,
			// no _elementor_data with any extractable copy).
			if ( self::word_count( $cand_text ) < self::MIN_COMPARABLE_WORDS ) {
				continue;
			}

			$cand_words    = self::cached_tokens( (int) $post->ID, $cand_text );
			$cand_shingles = self::shingles_from_tokens( $cand_words );
			$score         = self::jaccard( $new_shingles, $cand_shingles );

			$cand_tokens   = self::proper_noun_tokens( $post->post_title );
			$tokens        = array_values( array_unique( array_merge( $new_title_tokens, $cand_tokens ) ) );
			$tokens_flip   = array_flip( $tokens );
			$stripped_new  = implode( ' ', array_values( array_filter( $new_tokens_words, function ( $w ) use ( $tokens_flip ) {
				return ! isset( $tokens_flip[ $w ] );
			} ) ) );
			$stripped_cand = implode( ' ', array_values( array_filter( $cand_words, function ( $w ) use ( $tokens_flip ) {
				return ! isset( $tokens_flip[ $w ] );
			} ) ) );
			$city_swap     = ( '' !== $stripped_new && $stripped_new === $stripped_cand );

			if ( $city_swap && ! $best['city_swap'] ) {
				$best = array(
					'score'     => max( $score, $best['score'] ),
					'match_id'  => (int) $post->ID,
					'city_swap' => true,
				);
			} elseif ( ! $best['city_swap'] && $score > $best['score'] ) {
				$best = array(
					'score'     => $score,
					'match_id'  => (int) $post->ID,
					'city_swap' => false,
				);
			}
		}

		return $best;
	}

	/**
	 * A post's comparable text: the Elementor-rendered text when the post
	 * has one (most MCPWP-built pages carry the real content in
	 * `_elementor_data`, not post_content), falling back to post_content.
	 *
	 * @param object $post Post object.
	 * @return string Plain text.
	 */
	private static function get_post_effective_text( $post ) {
		$elementor_json = get_post_meta( $post->ID, '_elementor_data', true );
		if ( ! empty( $elementor_json ) ) {
			$text = self::extract_text_from_elementor( $elementor_json );
			if ( '' !== trim( $text ) ) {
				return $text;
			}
		}
		return wp_strip_all_tags( (string) $post->post_content );
	}

	/**
	 * Word count of a text, Unicode-aware and CJK-aware.
	 *
	 * Two separate reasons str_word_count() (ASCII-only) and a naive
	 * whitespace split both fail here:
	 *   - Latin/Cyrillic/etc. words are whitespace-separated, so a
	 *     `\p{L}\p{N}` token match works.
	 *   - CJK (Chinese/Japanese/Korean-script) text is NOT
	 *     whitespace-separated at all — a whole CJK paragraph is one
	 *     "word" to a whitespace splitter, undercounting by 10-50x and
	 *     wrongly flagging a substantial CJK page as thin. Each CJK
	 *     character carries roughly the density of half a Latin word, so
	 *     CJK characters are counted separately and divided by 2 (#957
	 *     adversarial review: "count CJK characters ÷ 2").
	 *
	 * @param string $text Text.
	 * @return int Word count.
	 */
	private static function word_count( $text ) {
		$text = self::truncate_for_analysis( trim( wp_strip_all_tags( (string) $text ) ) );
		if ( '' === $text ) {
			return 0;
		}

		$cjk_matches = array();
		preg_match_all( self::CJK_PATTERN, $text, $cjk_matches );
		$cjk_count = count( $cjk_matches[0] );

		// Strip CJK characters before counting Latin-style words, so a
		// mixed-script document (e.g. a Latin brand name inside CJK body
		// copy) does not double-count anything.
		$non_cjk_text = preg_replace( self::CJK_PATTERN, ' ', $text );
		$matches      = array();
		preg_match_all( '/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u', $non_cjk_text, $matches );

		return count( $matches[0] ) + (int) floor( $cjk_count / 2 );
	}

	/**
	 * Unicode ranges for CJK ideographs/kana/hangul, as bare character-class
	 * content (no enclosing `[...]` or delimiters) so it can be composed
	 * into more than one regex — see word_count() and normalize_words().
	 *
	 * @var string
	 */
	const CJK_CHAR_CLASS = '\x{4E00}-\x{9FFF}\x{3400}-\x{4DBF}\x{3040}-\x{30FF}\x{AC00}-\x{D7A3}';

	/**
	 * Unicode ranges for CJK ideographs/kana/hangul — see word_count().
	 *
	 * @var string
	 */
	const CJK_PATTERN = '/[' . self::CJK_CHAR_CLASS . ']/u';

	/**
	 * Truncate a document to MAX_WORDS_PER_DOCUMENT *characters worth of a
	 * reasonable margin* before any tokenizing work — a hard, cheap ceiling
	 * on per-document cost regardless of how long the stored content
	 * actually is (#957 WARN-4). Truncating on a generous character bound
	 * (average English word ~7 chars including the space) is far cheaper
	 * than tokenizing the whole string first just to slice the word list.
	 *
	 * @param string $text Text.
	 * @return string Possibly-truncated text.
	 */
	private static function truncate_for_analysis( $text ) {
		$char_limit = self::MAX_WORDS_PER_DOCUMENT * 10;
		if ( function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text, 'UTF-8' ) > $char_limit ? mb_substr( $text, 0, $char_limit, 'UTF-8' ) : $text;
		}
		return strlen( $text ) > $char_limit ? substr( $text, 0, $char_limit ) : $text;
	}

	/**
	 * Lower-cased token list, Unicode-aware, punctuation stripped.
	 *
	 * A run of CJK characters (Chinese/Japanese/Korean script) has no
	 * spaces to split on — treating the WHOLE run as a single token (the
	 * naive `\p{L}\p{N}+` match everything else here uses) means a
	 * multi-paragraph CJK document tokenizes as ONE "word," so a 5-word
	 * shingle can never even form: duplicate detection over CJK content
	 * was silently a no-op (#957 adversarial review round 2 NIT-1). Each
	 * CJK run is tokenized as overlapping 2-character bigrams instead —
	 * the standard technique for shingling CJK text without a word-
	 * segmentation dictionary — while Latin/Cyrillic/etc. text keeps its
	 * normal word-boundary tokenization.
	 *
	 * @param string $text Text.
	 * @return string[] Tokens (words, or CJK character-bigrams).
	 */
	private static function normalize_words( $text ) {
		$text = strtolower( self::truncate_for_analysis( wp_strip_all_tags( (string) $text ) ) );
		if ( '' === $text ) {
			return array();
		}

		$tokens = array();
		$runs   = array();
		preg_match_all( '/[' . self::CJK_CHAR_CLASS . ']+|[^' . self::CJK_CHAR_CLASS . ']+/u', $text, $runs );

		foreach ( $runs[0] as $run ) {
			if ( 1 !== preg_match( '/^[' . self::CJK_CHAR_CLASS . ']/u', $run ) ) {
				$word_matches = array();
				preg_match_all( '/[\p{L}\p{N}]+/u', $run, $word_matches );
				foreach ( $word_matches[0] as $word ) {
					$tokens[] = $word;
				}
				continue;
			}

			$chars = preg_split( '//u', $run, -1, PREG_SPLIT_NO_EMPTY );
			$count = count( $chars );
			if ( 1 === $count ) {
				$tokens[] = $chars[0];
				continue;
			}
			for ( $i = 0; $i < $count - 1; $i++ ) {
				$tokens[] = $chars[ $i ] . $chars[ $i + 1 ];
			}
		}

		return $tokens;
	}

	/**
	 * Unique 5-word shingles for a text.
	 *
	 * @param string   $text Text.
	 * @param int|null $n    Shingle size; defaults to SHINGLE_SIZE.
	 * @return string[] Shingles.
	 */
	private static function shingles_from_tokens( array $words, $n = null ) {
		$n     = $n ? (int) $n : self::SHINGLE_SIZE;
		$count = count( $words );

		if ( 0 === $count ) {
			return array();
		}
		if ( $count < $n ) {
			return array( implode( ' ', $words ) );
		}

		$shingles = array();
		for ( $i = 0; $i <= $count - $n; $i++ ) {
			$shingles[ implode( ' ', array_slice( $words, $i, $n ) ) ] = true;
		}
		return array_keys( $shingles );
	}

	/**
	 * Shingles for arbitrary (non-cached) text — used for the NEW content
	 * side of a comparison, which by construction is only ever tokenized
	 * once per check.
	 *
	 * @param string   $text Text.
	 * @param int|null $n    Shingle size; defaults to SHINGLE_SIZE.
	 * @return string[] Shingles.
	 */
	private static function shingles( $text, $n = null ) {
		return self::shingles_from_tokens( self::normalize_words( $text ), $n );
	}

	/**
	 * Normalized word tokens for a candidate post, LRU-cached per request
	 * by "{post_id}:{content hash}" so a bulk call or blueprint deploy
	 * checking many new items against the same recent-posts window does not
	 * re-tokenize each candidate repeatedly (#957: "precompute shingles
	 * cheaply" / WARN-4: "cache normalized tokens per candidate", capped and
	 * LRU-evicted so the cache itself cannot grow unbounded).
	 *
	 * @param int    $post_id Post ID (cache key component only).
	 * @param string $text    Candidate text.
	 * @return string[] Normalized tokens.
	 */
	private static function cached_tokens( $post_id, $text ) {
		$key = $post_id . ':' . md5( $text );

		if ( isset( self::$token_cache[ $key ] ) ) {
			// Touch: move to the end (most-recently-used) without changing
			// the value.
			$value = self::$token_cache[ $key ];
			unset( self::$token_cache[ $key ] );
			self::$token_cache[ $key ] = $value;
			return $value;
		}

		if ( count( self::$token_cache ) >= self::MAX_TOKEN_CACHE_ENTRIES ) {
			// array key order IS insertion/touch order here — evict the
			// least-recently-used (front) entry.
			array_shift( self::$token_cache );
		}

		self::$token_cache[ $key ] = self::normalize_words( $text );
		return self::$token_cache[ $key ];
	}

	/**
	 * Jaccard similarity between two shingle sets.
	 *
	 * @param string[] $a Shingle set A.
	 * @param string[] $b Shingle set B.
	 * @return float 0.0–1.0.
	 */
	private static function jaccard( array $a, array $b ) {
		if ( empty( $a ) && empty( $b ) ) {
			return 0.0;
		}
		$a_flip       = array_flip( $a );
		$b_flip       = array_flip( $b );
		$intersection = count( array_intersect_key( $a_flip, $b_flip ) );
		$union        = count( $a_flip ) + count( $b_flip ) - $intersection;
		return $union > 0 ? $intersection / $union : 0.0;
	}

	/**
	 * Likely location/proper-noun tokens from a title: capitalized words of
	 * 3+ characters that aren't common stopwords. Heuristic, not NLP — good
	 * enough to catch "Toronto Plumbing Services" vs "Ottawa Plumbing
	 * Services" (#957's "city-swap" pattern).
	 *
	 * ASCII/Latin-script only (the regex below is `[A-Za-z]`, deliberately
	 * — there is no "capital letter" concept to key off in CJK, Arabic,
	 * Hebrew, etc.) — the city-swap heuristic does not catch a location-name
	 * swap written in one of those scripts (#957 adversarial review round 2
	 * NIT-1 note). The plain Jaccard duplicate score above still applies to
	 * ANY script, including CJK (see normalize_words()) — only this
	 * specific heuristic is Latin-only.
	 *
	 * @param string $title Title.
	 * @return string[] Lower-cased tokens.
	 */
	private static function proper_noun_tokens( $title ) {
		static $stopwords = array( 'the', 'a', 'an', 'of', 'for', 'in', 'on', 'and', 'to', 'with', 'at', 'by', 'is', 'are' );

		$matches = array();
		preg_match_all( '/[A-Za-z][A-Za-z0-9]*/', (string) $title, $matches );

		$tokens = array();
		foreach ( $matches[0] as $word ) {
			if ( strlen( $word ) < 3 ) {
				continue;
			}
			if ( in_array( strtolower( $word ), $stopwords, true ) ) {
				continue;
			}
			if ( ctype_upper( $word[0] ) ) {
				$tokens[] = strtolower( $word );
			}
		}
		return array_values( array_unique( $tokens ) );
	}

	// -------------------------------------------------------------------------
	// Elementor text extraction (build_page / landing pages / blueprint pages
	// carry their real content in an Elementor element tree, not post_content)

	/**
	 * Best-effort plain-text extraction from an Elementor element tree, for
	 * word-count and duplicate-shingle purposes. Not a renderer — just pulls
	 * the handful of settings keys that carry visible copy.
	 *
	 * @param mixed $data Elementor data: JSON string, or already-decoded array.
	 * @return string Extracted text.
	 */
	public static function extract_text_from_elementor( $data ) {
		if ( is_string( $data ) ) {
			$decoded = json_decode( $data, true );
			$data    = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $data ) ) {
			return '';
		}

		$texts = array();
		self::walk_elementor_text( $data, $texts );
		return trim( implode( ' ', $texts ) );
	}

	/**
	 * Recursively collect text-bearing widget settings.
	 *
	 * @param mixed    $nodes One element (assoc array) or a list of elements.
	 * @param string[] $texts Accumulator, by reference.
	 * @return void
	 */
	private static function walk_elementor_text( $nodes, array &$texts ) {
		if ( ! is_array( $nodes ) ) {
			return;
		}
		if ( isset( $nodes['elType'] ) || isset( $nodes['settings'] ) ) {
			$nodes = array( $nodes );
		}

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			if ( isset( $node['settings'] ) && is_array( $node['settings'] ) ) {
				foreach ( array( 'title', 'editor', 'text', 'description', 'sub_title', 'content', 'caption', 'html' ) as $key ) {
					if ( ! empty( $node['settings'][ $key ] ) && is_string( $node['settings'][ $key ] ) ) {
						$texts[] = wp_strip_all_tags( $node['settings'][ $key ] );
					}
				}
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::walk_elementor_text( $node['elements'], $texts );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Admin Settings UI (registers into the existing "mcpwp_settings" page —
	// see admin/partials/mcpwp-settings-display.php's do_settings_sections())

	/**
	 * Register the settings field + admin UI section. Hooked to admin_init
	 * from includes/class-mcpwp-loader.php, same pattern as Mcpwp_Settings
	 * and Mcpwp_AI_Presence_Settings.
	 *
	 * @return void
	 */
	public static function register_settings_ui() {
		register_setting(
			'mcpwp_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::default_settings(),
			)
		);

		add_settings_section(
			'mcpwp_content_guardrails_section',
			__( 'Content Guardrails', 'mcpwp' ),
			array( __CLASS__, 'render_section_intro' ),
			'mcpwp_settings'
		);

		add_settings_field(
			'mcpwp_cg_enabled',
			__( 'Enabled', 'mcpwp' ),
			array( __CLASS__, 'render_field_enabled' ),
			'mcpwp_settings',
			'mcpwp_content_guardrails_section'
		);

		add_settings_field(
			'mcpwp_cg_daily_publish_cap',
			__( 'Daily publish cap', 'mcpwp' ),
			array( __CLASS__, 'render_numeric_field' ),
			'mcpwp_settings',
			'mcpwp_content_guardrails_section',
			array(
				'key'         => 'daily_publish_cap',
				'min'         => 1,
				'max'         => 100000,
				'step'        => 1,
				'description' => __( 'Max new posts+pages this site will publish through MCPWP per UTC calendar day (applies to admin keys too). Over the cap, new content is created as a draft instead of being dropped.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'mcpwp_cg_bulk_approval_threshold',
			__( 'Bulk approval threshold', 'mcpwp' ),
			array( __CLASS__, 'render_numeric_field' ),
			'mcpwp_settings',
			'mcpwp_content_guardrails_section',
			array(
				'key'         => 'bulk_approval_threshold',
				'min'         => 1,
				'max'         => 1000,
				'step'        => 1,
				'description' => __( 'A single bulk-create call publishing more than this many items is created as drafts plus one approval request instead. Bulk calls that only create drafts are never gated.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'mcpwp_cg_min_word_count',
			__( 'Minimum word count', 'mcpwp' ),
			array( __CLASS__, 'render_numeric_field' ),
			'mcpwp_settings',
			'mcpwp_content_guardrails_section',
			array(
				'key'         => 'min_word_count',
				'min'         => 0,
				'max'         => 5000,
				'step'        => 1,
				'description' => __( 'Published posts/pages below this word count are created as drafts instead. Ignored for drafts.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'mcpwp_cg_duplicate_threshold',
			__( 'Duplicate threshold', 'mcpwp' ),
			array( __CLASS__, 'render_numeric_field' ),
			'mcpwp_settings',
			'mcpwp_content_guardrails_section',
			array(
				'key'         => 'duplicate_threshold',
				'min'         => 0.1,
				'max'         => 1.0,
				'step'        => 0.05,
				'description' => __( '5-word-shingle Jaccard similarity (0.1–1.0) against recent posts of the same type, above which publishing is downgraded to draft.', 'mcpwp' ),
			)
		);
	}

	/**
	 * Section intro text.
	 *
	 * @return void
	 */
	public static function render_section_intro() {
		echo '<p>' . esc_html__( 'Guardrails that keep AI-driven publishing from becoming scaled or duplicate content — the patterns Google\'s spam policies describe as scaled content abuse and site reputation abuse. Applies to posts and pages created or published through MCPWP tools.', 'mcpwp' ) . '</p>';
	}

	/**
	 * "Enabled" checkbox field.
	 *
	 * @return void
	 */
	public static function render_field_enabled() {
		$settings = self::get_settings();
		// A hidden '0' sent BEFORE the checkbox (same name) means an
		// unchecked box still submits an explicit 'enabled' key — WordPress
		// checkboxes send nothing at all when unchecked, which is otherwise
		// indistinguishable from the field simply not being in this form
		// submission (WARN-6).
		printf(
			'<input type="hidden" name="%1$s[enabled]" value="0" />' .
			'<label><input type="checkbox" name="%1$s[enabled]" value="1" %2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $settings['enabled'], true, false ),
			esc_html__( 'Enable content guardrails', 'mcpwp' )
		);
	}

	/**
	 * Generic numeric settings field renderer.
	 *
	 * @param array $args { key, min, max, step, description }.
	 * @return void
	 */
	public static function render_numeric_field( $args ) {
		$settings = self::get_settings();
		$key      = isset( $args['key'] ) ? $args['key'] : '';
		$value    = isset( $settings[ $key ] ) ? $settings[ $key ] : '';

		printf(
			'<input type="number" step="%1$s" min="%2$s" max="%3$s" name="%4$s[%5$s]" value="%6$s" class="small-text" /> <p class="description">%7$s</p>',
			esc_attr( isset( $args['step'] ) ? $args['step'] : 1 ),
			esc_attr( isset( $args['min'] ) ? $args['min'] : 0 ),
			esc_attr( isset( $args['max'] ) ? $args['max'] : '' ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $key ),
			esc_attr( $value ),
			esc_html( isset( $args['description'] ) ? $args['description'] : '' )
		);
	}
}

// Unconditional, idempotent (add_filter/add_action de-dupe identical
// callbacks): every callback registered here no-ops instantly unless
// Mcpwp_Content_Guardrails::in_context() is true, so registering it on
// every load — production via mcpwp.php's require, or a test's own
// require_once of this file — costs nothing on a request/test that never
// enters context.
Mcpwp_Content_Guardrails::register_hooks();
