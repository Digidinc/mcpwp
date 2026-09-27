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
 *      pattern. This ONLY ever downgrades a NEW publish to draft — it never
 *      touches an ALREADY-published post's content.
 *
 *      An edit to an ALREADY-published post is ADVISORY, by deliberate
 *      design (#957 adversarial review round 3 — the THIRD attempt at this
 *      behavior; see git history for round 1's BLOCK/round 2's WARN-1
 *      refuse-and-revert, both superseded). It is never reverted and never
 *      refused: the write is saved exactly as submitted, a warning is
 *      attached to the response the same way every other guardrails notice
 *      is, and a `content_guardrails` / `duplicate_edit` issue is recorded
 *      via Mcpwp_SEO_Audit_Store::upsert_issue() so Control Room's open-SEO-
 *      issues list shows it for a human to review. This is a deliberate
 *      trade-off, not an oversight: reverting or refusing a live edit
 *      (rounds 1 and 2) repeatedly produced false positives on genuine edits
 *      the reviewer had to special-case one at a time, AND could not see
 *      every write path that changes a published post's rendered content —
 *      most concretely, an Elementor page's real copy lives in the
 *      `_elementor_data` postmeta, written via a plain update_post_meta()
 *      call that never goes through wp_insert_post_data at all. Only a
 *      publish DECISION (a draft or new post becoming live) is worth
 *      blocking; an edit to something already live is worth flagging for a
 *      human, not silently mutating or bouncing.
 *
 *      The check runs only when the actually-rendered text changed — never
 *      on a title-only or meta-only edit:
 *        - post_content edits: on_insert_post_data() compares the OLD vs
 *          NEW post_content (see advise_on_published_content_edit()).
 *        - Elementor pages: `_elementor_data` doesn't flow through
 *          wp_insert_post_data, so this hooks WordPress core's generic
 *          `update_post_meta`/`updated_post_meta`/`added_post_meta` actions
 *          instead, filtered to meta_key === '_elementor_data' and to a
 *          currently-published post of a covered type (see
 *          maybe_advise_on_elementor_meta_change()) — Mcpwp_Elementor_Basic
 *          and every Elementor writer call site are untouched; they already
 *          all go through update_post_meta(), so hooking WordPress core's
 *          own meta actions covers every one of them for free.
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
 *   - The published-edit advisory is exactly that — advisory. It never
 *     blocks, reverts, or downgrades a live edit; it only flags one for
 *     human review. A caller that ignores the warning and the Control Room
 *     issue can still ship scaled/duplicate content onto an already-live
 *     page. This is the accepted cost of round 1/2's refuse-and-revert
 *     approach producing repeated false positives on genuine edits.
 *   - try_reserve_daily_slot() fails CLOSED (treats a reservation as
 *     denied, downgrading to draft) if 8 CAS retries are all lost to
 *     contention — safe (never lets the cap be exceeded) but means an
 *     extremely hot moment could downgrade one more post than strictly
 *     necessary. Never claim "never lost" beyond that bound.
 *   - A publish-slot reservation for a brand-new insert (no post ID exists
 *     yet at reservation time) is tracked on a LIFO stack, confirmed by the
 *     very next `wp_insert_post` action. An UPDATE's reservation IS keyed
 *     by its real post ID, so on_wp_insert_post() can only ever confirm its
 *     own — a nested update to a DIFFERENT post can never have its
 *     confirmation cross-attributed to the wrong one (#957 adversarial
 *     review round 3 NIT-b). The one gap this does NOT close: releasing an
 *     orphaned reservation mid-request (release_stale_reservation(), called
 *     before every new reservation attempt so a same-request retry can
 *     reuse a slot a failed write never used) distinguishes "genuinely
 *     failed" from "still legitimately in flight" by checking the post's
 *     CURRENT status in the database — accurate for a sequential retry (the
 *     earlier call has fully finished, failed or not, by the time the next
 *     one starts), but a truly NESTED reservation (post B's entire
 *     reserve-then-confirm cycle happening synchronously INSIDE post A's
 *     still-in-progress write, before A's own row lands) would see A's
 *     pre-write status and could release A's still-legitimate reservation
 *     early. This requires actual write-time reentrancy from another hook
 *     on the SAME `wp_insert_post_data` filter to trigger — not reachable
 *     from any call site in this codebase — and is documented here rather
 *     than solved with call-depth tracking, which would need to survive the
 *     exact same "a nested call that never completes" failure mode this
 *     bullet is already about.
 *   - crc32() is used to represent a 5-word shingle as an int instead of the
 *     phrase string itself (round 3 PERF) — a 32-bit hash, not a
 *     cryptographic one. A collision would silently treat two different
 *     shingles as the same one, nudging a Jaccard score slightly off. At a
 *     few thousand shingles per document this is a acceptable, negligible
 *     risk for a heuristic guardrail, not a security boundary.
 *   - The ramp cap only ever counts a write that reaches
 *     on_insert_post_data() while in_context() is true — every MCPWP call
 *     site does, since they all go through wp_insert_post()/
 *     wp_update_post(). A THIRD-PARTY plugin's own nested publish,
 *     triggered as a side effect of one of ours (e.g. a hook on
 *     `wp_insert_post`/`save_post` that creates a companion post), is
 *     UNDERCOUNTED whenever it either (a) writes via a raw $wpdb query
 *     instead of WordPress's own post API, invisible to any hook at all,
 *     or (b) targets a post type outside COVERED_POST_TYPES, out of scope
 *     by design (round 4 NIT-1). Accepted: closing this would mean
 *     auditing every third-party plugin's write path, which this class
 *     has no visibility into and no business gating.
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
	 * Max entries kept in the per-request candidate-analysis LRU cache —
	 * sized to HARD_DUPLICATE_COMPARE_CAP (#957 adversarial review round 3
	 * PERF: "per-request candidate token cache sized to the comparison
	 * window"), not the smaller, arbitrary 50 of round 1's WARN-4 fix. A
	 * single duplicate_check() call already compares against at most this
	 * many candidates, so sizing the cache to match means one call's own
	 * candidate window never evicts itself, and a BULK call re-running
	 * duplicate_check() many times against the same recent-posts window
	 * warms the whole window once and reuses it for every remaining item.
	 *
	 * @var int
	 */
	const MAX_TOKEN_CACHE_ENTRIES = 200;

	/**
	 * Soft memory ceiling on the candidate-analysis cache's own footprint
	 * (#957 adversarial review round 3 PERF: "add a memory guard: stop
	 * caching past ~32MB and fall back to recomputing"). Past this
	 * estimated size, cached_candidate_analysis() still returns a correct,
	 * freshly-computed result — it simply stops ADDING to the cache, so
	 * memory stays bounded instead of a legitimately huge document (or an
	 * unusually large comparison window) growing the cache without limit.
	 *
	 * @var int
	 */
	const MAX_TOKEN_CACHE_BYTES = 33554432; // ~32MB.

	/**
	 * Per-request LRU cache of one candidate post's derived analysis —
	 * `{tokens: string[], shingles: int[]}` — keyed by
	 * "{post_id}:{content hash}". PHP array insertion order is used as the
	 * recency order: re-touching a key moves it to the end via unset()+re-
	 * insert; the OLDEST (front) entry is evicted once the cache is full.
	 * Tokens are kept (not just shingles) because the city-swap heuristic
	 * needs the actual word list, not just the shingle set.
	 *
	 * @var array<string,array{tokens:string[],shingles:int[]}>
	 */
	private static $token_cache = array();

	/**
	 * Running estimate of $token_cache's own memory footprint, in bytes —
	 * see MAX_TOKEN_CACHE_BYTES.
	 *
	 * @var int
	 */
	private static $token_cache_bytes = 0;

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
	 * Publish-slot reservations made by try_reserve_daily_slot() inside
	 * on_insert_post_data() for an UPDATE (post ID already known), keyed by
	 * that post ID, not yet confirmed by a matching on_wp_insert_post()
	 * call for that SAME post (#957 adversarial review round 3 NIT-b: "key
	 * reservations by post ID and confirm the specific reservation for that
	 * post" — round 2's single scalar meant a nested update to post B could
	 * confirm/clear post A's still-outstanding reservation, or vice versa).
	 *
	 * @var array<int,int> post_id => reservation timestamp.
	 */
	private static $pending_reservation_by_post = array();

	/**
	 * Publish-slot reservations made for a brand-new INSERT (no post ID
	 * exists yet at reservation time), as a LIFO stack — correct for
	 * properly-nested completion (PHP's own call stack is LIFO), with the
	 * residual gap documented in the class docblock (a nested new insert
	 * that reserves and then fails silently leaves an orphaned entry until
	 * shutdown). Confirmed by popping the top entry in on_wp_insert_post()
	 * for a non-update insert.
	 *
	 * @var int[] Reservation timestamps, oldest first.
	 */
	private static $pending_reservation_new_stack = array();

	/**
	 * A candidate post's _elementor_data text, captured by
	 * on_pre_update_elementor_meta() just BEFORE WordPress core overwrites
	 * the stored postmeta row, keyed by post ID — there is no other way to
	 * see the OLD value once `updated_post_meta` fires, since WordPress
	 * core's own action passes the NEW value, not the old one.
	 *
	 * @var array<int,string>
	 */
	private static $pending_elementor_old_text = array();

	/**
	 * Set for exactly the one _elementor_data write a brand-new insert's
	 * OWN meta_input makes (see on_insert_post_data()'s new-publish branch)
	 * — that write was already duplicate-checked by on_insert_post_data()
	 * itself (the meta_input-lookahead fallback), so the generic
	 * added_post_meta hook must not ALSO advisory-check the very same
	 * content as a second, redundant finding. Consumed unconditionally by
	 * the very next added_post_meta/_elementor_data event, the same
	 * "consume unconditionally, scoped to the one write it was set for"
	 * idiom as $pending_exclude_ids (#957 adversarial review round 2
	 * WARN-2) — meta_input processing for one insert cannot itself trigger
	 * a nested insert, so no reentrancy gap here.
	 *
	 * @var bool
	 */
	private static $suppress_next_elementor_meta_advisory = false;

	/**
	 * True while apply_bulk_publish() is running a human-APPROVED batch
	 * through wp_update_post() — a human already decided to publish these,
	 * so the ramp cap does not apply to them (#957 adversarial review round
	 * 3 NIT-d: "human-approved applies ... are exempt from the cap ... but
	 * are still COUNTED"). Read by try_reserve_daily_slot() to skip the cap
	 * comparison while still atomically incrementing the count, so today's
	 * remaining-cap number for every OTHER, non-approved publish stays
	 * correct.
	 *
	 * @var bool
	 */
	private static $bulk_apply_exempt_from_cap = false;

	/**
	 * A HASH of the last NEW rendered text evaluate_content_edit_advisory()
	 * actually ran the duplicate check against, per post ID, this request
	 * (#957 adversarial review round 4 NIT-2: a hash, not the raw text
	 * itself — nothing needs the text back, only whether it repeats) —
	 * lets a second write carrying the identical final text (Elementor's
	 * own internal double-write — see evaluate_content_edit_advisory()'s
	 * docblock) collapse into the first instead of stacking a second,
	 * identical warning/issue-touch.
	 *
	 * @var array<int,string>
	 */
	private static $last_advised_text_by_post = array();

	/**
	 * A post_content edit's advisory decision, staged by
	 * advise_on_published_content_edit() (which runs BEFORE the row is
	 * written) until on_post_updated() confirms the write actually landed
	 * (#957 adversarial review round 4 NIT-2: "record the advisory only
	 * after the write succeeds ... so a failed write leaves no phantom
	 * issue"). Keyed by post ID; there is at most one pending entry per
	 * post at a time (the same post cannot be mid-update twice
	 * concurrently within one PHP request).
	 *
	 * @var array<int,array{post_type:string,result:array}>
	 */
	private static $pending_content_edit_advisory = array();

	/**
	 * How many advisory checks (the EXPENSIVE part — a duplicate_check()
	 * call) have actually run this request — see
	 * MAX_ADVISORY_CHECKS_PER_REQUEST.
	 *
	 * @var int
	 */
	private static $advisory_checks_this_request = 0;

	/**
	 * How many advisory checks were SKIPPED this request for having
	 * already hit MAX_ADVISORY_CHECKS_PER_REQUEST — surfaced as a single
	 * aggregate note by attach_notices_to_response(), not a per-post one.
	 *
	 * @var int
	 */
	private static $advisory_checks_skipped_count = 0;

	/**
	 * Hard ceiling on how many advisory duplicate-checks
	 * evaluate_content_edit_advisory() will actually run in one request
	 * (#957 adversarial review round 4 NIT-3: "bound the advisory work per
	 * request ... so a 100-page find-replace stays fast"). The cheap
	 * unchanged-text and already-processed-this-request bails above this
	 * limit in the calling code do NOT count against it — only a write
	 * that would otherwise trigger a real duplicate_check() call does.
	 *
	 * @var int
	 */
	const MAX_ADVISORY_CHECKS_PER_REQUEST = 20;

	/**
	 * Mark the rest of this request as MCPWP-authenticated. Idempotent.
	 *
	 * Resets every OTHER per-request static the FIRST time this is called
	 * for a request (#957 adversarial review round 3 NIT-c: reset at the
	 * START of a request too, not only at `shutdown` — a worker process
	 * (FrankenPHP, Swoole) that skips a clean shutdown, e.g. after a fatal
	 * error, must not carry a stale reservation or notice into the request
	 * that reuses it). A SECOND enter_context() call within the same
	 * request (e.g. apply_bulk_publish() entering context for an apply that
	 * arrived without one) must NOT reset anything — that would wipe
	 * notices/reservations this same request already accumulated.
	 *
	 * @return void
	 */
	public static function enter_context() {
		if ( ! self::$context_active ) {
			self::reset_per_request_state();
		}
		self::$context_active = true;
	}

	/**
	 * Force context active WITHOUT resetting per-request state — the
	 * internal bracket pattern apply_bulk_publish() uses to guarantee the
	 * guard runs even when its caller (wp-admin's Control Room) never went
	 * through verify_api_key(). Deliberately NOT the public enter_context()
	 * above: that resets on a false->true transition (NIT-c, this request's
	 * genuine start), but a bracket pattern toggling context off then back
	 * on again WITHIN the same request (e.g. two applies in one wp-admin
	 * page load) must not wipe notices/reservations the first one already
	 * left behind — that would be the exact same cross-post/cross-call
	 * contamination this round's NIT-b fix exists to prevent, just via a
	 * different mechanism.
	 *
	 * @return void
	 */
	private static function force_context_active_without_reset() {
		self::$context_active = true;
	}

	/**
	 * @return bool Whether the current request is MCPWP-authenticated.
	 */
	public static function in_context() {
		return self::$context_active;
	}

	/**
	 * Reset every per-request static. Run from the START of a request (the
	 * first enter_context() call — see its own docblock) and from
	 * `shutdown` (end of THIS one) so a long-lived worker process
	 * (FrankenPHP, Swoole) reusing this PHP process across requests can
	 * never carry context, a cached token, or an unconfirmed reservation
	 * from one request into another (#957 adversarial review round 2
	 * NIT-3, round 3 NIT-c: reset on entry too, not only at shutdown — a
	 * worker that skips a clean shutdown must still start clean). Also the
	 * test suite's reset — PHPUnit runs many "requests" in one process.
	 *
	 * @return void
	 */
	public static function reset_per_request_state() {
		self::release_stale_reservation();
		self::$context_active                        = false;
		self::$pending_notice                         = null;
		self::$notices_by_post_id                     = array();
		self::$pending_exclude_ids                    = array();
		self::$pending_reservation_by_post             = array();
		self::$pending_reservation_new_stack           = array();
		self::$pending_elementor_old_text              = array();
		self::$suppress_next_elementor_meta_advisory   = false;
		self::$bulk_apply_exempt_from_cap              = false;
		self::$last_advised_text_by_post              = array();
		self::$pending_content_edit_advisory          = array();
		self::$advisory_checks_this_request           = 0;
		self::$advisory_checks_skipped_count          = 0;
		self::$token_cache                            = array();
		self::$token_cache_bytes                       = 0;
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
		// Elementor's real copy lives in `_elementor_data` postmeta, written
		// via a plain update_post_meta() call that never touches
		// wp_insert_post_data — these three are WordPress core's own generic
		// meta hooks (every meta key, every post type; filtered to
		// '_elementor_data' inside the callbacks themselves), NOT a change to
		// Mcpwp_Elementor_Basic or any writer call site (#957 adversarial
		// review round 3 design change: "hook updated_post_meta/
		// added_post_meta ... don't touch the writer call sites"). The pre-
		// action (`update_post_meta`) exists ONLY to capture the OLD value
		// before WordPress overwrites it — see on_pre_update_elementor_meta().
		add_action( 'update_post_meta', array( __CLASS__, 'on_pre_update_elementor_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_updated_elementor_meta' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_added_elementor_meta' ), 10, 4 );
		// Fires only after WordPress core has actually written an UPDATE to
		// an existing post's row — confirms a post_content advisory staged
		// by advise_on_published_content_edit() (#957 adversarial review
		// round 4 NIT-2: a failed write must leave no phantom issue).
		add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 10, 3 );
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
		// published post, not a new publish event — no ramp reservation,
		// and NEVER reverted/refused (#957 adversarial review round 3
		// design change — see the class docblock). Advisory only: flag,
		// record, save exactly as submitted.
		if ( 'publish' === $previous_status && 'publish' === $new_status ) {
			return self::advise_on_published_content_edit( $data, $existing, $post_type, $exclude_ids_for_this_pass );
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

		$post_content_text = isset( $data['post_content'] )
			? wp_strip_all_tags( (string) $data['post_content'] )
			: ( $existing ? wp_strip_all_tags( (string) $existing->post_content ) : '' );

		// Builder-page detection lives in resolve_comparable_texts() — the
		// ONE function BOTH this gate and duplicate_check()'s candidate
		// side use, so they can never disagree about which field is a
		// post's real, rendered copy (#957 adversarial review round 4
		// BLOCK-1: the gate used to prefer post_content, falling back to
		// Elementor only when post_content was thin, while candidates
		// preferred Elementor — a draft with a cloned Elementor page AND
		// 30 unique filler words in post_content published unflagged,
		// because the gate scored the filler and never looked at the
		// clone). An existing post looks its OWN postmeta up directly; a
		// brand-new insert has no ID yet, UNLESS the caller front-loaded
		// _elementor_data/_elementor_edit_mode into $postarr['meta_input']
		// itself — a real wp_insert_post() feature (writes postmeta
		// atomically alongside the post) that clone_page() uses
		// specifically so a clone of an Elementor page is duplicate-
		// checked against its actual content even though it has no ID yet.
		if ( $post_id ) {
			$elementor_text = self::extract_text_from_elementor( get_post_meta( $post_id, '_elementor_data', true ) );
			$edit_mode      = (string) get_post_meta( $post_id, '_elementor_edit_mode', true );
		} else {
			$elementor_text = isset( $postarr['meta_input']['_elementor_data'] )
				? self::extract_text_from_elementor( $postarr['meta_input']['_elementor_data'] )
				: '';
			$edit_mode      = isset( $postarr['meta_input']['_elementor_edit_mode'] )
				? (string) $postarr['meta_input']['_elementor_edit_mode']
				: '';
		}
		$content_texts = self::resolve_comparable_texts( $elementor_text, $edit_mode, $post_content_text );
		// The PRIMARY variant (Elementor text for a builder page, else
		// post_content) is what word-count/thin-content reporting below
		// describes — an Elementor-first page's word count should reflect
		// its actual Elementor content, not an empty/placeholder
		// post_content (#957 adversarial review false-positive fix).
		$content_text = $content_texts[0];

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
		// $bulk_apply_exempt_from_cap bypasses the CAP COMPARISON only — a
		// human already approved this batch — while still atomically
		// incrementing the count (#957 adversarial review round 3 NIT-d).
		$reserved      = self::try_reserve_daily_slot( $now, $settings['daily_publish_cap'], self::$bulk_apply_exempt_from_cap );
		$ramp_exceeded = ! $reserved;
		if ( $reserved ) {
			// Keyed by post ID for an UPDATE (draft -> publish); a LIFO
			// stack for a brand-new insert, which has no ID yet (#957
			// adversarial review round 3 NIT-b — see the class docblock for
			// the residual nested-new-insert-failure gap this cannot close).
			if ( $post_id ) {
				self::$pending_reservation_by_post[ $post_id ] = $now;
			} else {
				self::$pending_reservation_new_stack[] = $now;
			}
		}
		// The count AFTER this reservation attempt, for the response body
		// — read fresh rather than inferred, so it is correct whether or
		// not this specific call got the slot.
		$daily_count = self::get_daily_count( $now );

		$word_count = self::word_count( $content_text );
		$thin       = $word_count < $settings['min_word_count'];

		$exclude_ids = array_merge( $post_id ? array( $post_id ) : array(), $exclude_ids_for_this_pass );
		$dup_limit   = min( self::HARD_DUPLICATE_COMPARE_CAP, $settings['duplicate_compare_limit'] );
		$dup         = self::duplicate_check( $post_type, $title, $content_texts, $dup_limit, $exclude_ids );
		$dup_flag    = $dup['city_swap'] || ( $dup['score'] >= $settings['duplicate_threshold'] );

		$downgraded = $ramp_exceeded || $dup_flag;

		// The reservation succeeded (ramp allowed it) but something else
		// (duplicate/city-swap) still downgrades this write — give the slot
		// back, since it will not be published after all.
		if ( $reserved && $dup_flag ) {
			self::release_daily_slot( $now );
			if ( $post_id ) {
				unset( self::$pending_reservation_by_post[ $post_id ] );
			} else {
				array_pop( self::$pending_reservation_new_stack );
			}
			$daily_count = self::get_daily_count( $now );
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

			// This brand-new insert's own meta_input, if it carries
			// _elementor_data, is ABOUT to fire added_post_meta below (WP
			// core processes meta_input right after the row insert) — the
			// duplicate check just above already covered that exact content
			// via the meta-lookahead fallback, so the generic Elementor-meta
			// advisory hook must skip this one write (#957 adversarial
			// review round 3 design change note — see the static's own
			// docblock).
			if ( isset( $postarr['meta_input']['_elementor_data'] ) ) {
				self::$suppress_next_elementor_meta_advisory = true;
			}
		}

		return $data;
	}

	/**
	 * Advise on a post_content edit to an ALREADY-published post staying
	 * published (#957 adversarial review round 3 — the design-change
	 * replacement for round 2's WARN-1 refuse-and-revert; see the class
	 * docblock). Never modifies $data — the write is always saved exactly
	 * as submitted. Only compares OLD vs NEW post_content text; the
	 * Elementor path is a completely separate chokepoint (see
	 * maybe_advise_on_elementor_meta_change()) since _elementor_data never
	 * flows through this filter at all.
	 *
	 * Deliberately cheap when post_content isn't even in this write (no
	 * query at all) — most updates (status, categories, a single meta
	 * field, or — the common case — a TITLE-ONLY edit) never touch content
	 * and must do zero duplicate work.
	 *
	 * @param array  $data        Sanitized fields about to be saved.
	 * @param object $existing    The post as it currently is, before this write.
	 * @param string $post_type   Post type.
	 * @param int[]  $exclude_ids Extra exclude IDs consumed for this pass.
	 * @return array $data, unmodified — always.
	 */
	private static function advise_on_published_content_edit( array $data, $existing, $post_type, array $exclude_ids = array() ) {
		if ( ! array_key_exists( 'post_content', $data ) ) {
			return $data; // Title-only/meta-only edit — zero duplicate work.
		}

		$old_text = wp_strip_all_tags( (string) $existing->post_content );
		$new_text = wp_strip_all_tags( (string) $data['post_content'] );
		$title    = isset( $data['post_title'] ) ? (string) $data['post_title'] : (string) $existing->post_title;
		$post_id  = (int) $existing->ID;

		$result = self::evaluate_content_edit_advisory( $post_id, $post_type, $title, $old_text, $new_text, $exclude_ids );
		if ( null !== $result ) {
			// Deferred to the `post_updated` action, fired only once
			// WordPress core has actually written this row (#957
			// adversarial review round 4 NIT-2: "record the advisory only
			// after the write succeeds ... so a failed write leaves no
			// phantom issue") — this filter runs BEFORE the write; a
			// validation failure after it returns must never have already
			// recorded a notice/issue for a change that never landed. The
			// Elementor path (maybe_advise_on_elementor_meta_change()
			// below) already runs from a POST-write action, so it records
			// immediately — no deferral needed there.
			self::$pending_content_edit_advisory[ $post_id ] = array(
				'post_type' => $post_type,
				'result'    => $result,
			);
		}

		return $data;
	}

	/**
	 * The `post_updated` action — fires ONLY after WordPress core has
	 * actually written an UPDATE to an existing post's row. Confirms (or
	 * discards) whatever advise_on_published_content_edit() staged for
	 * this exact post ID.
	 *
	 * @param int    $post_id    Post ID.
	 * @param object $post_after Post object after the update (unused).
	 * @param object $post_before Post object before the update (unused).
	 * @return void
	 */
	public static function on_post_updated( $post_id, $post_after, $post_before ) {
		$post_id = (int) $post_id;
		if ( ! isset( self::$pending_content_edit_advisory[ $post_id ] ) ) {
			return;
		}
		$pending = self::$pending_content_edit_advisory[ $post_id ];
		unset( self::$pending_content_edit_advisory[ $post_id ] );
		self::record_content_edit_advisory( $post_id, $pending['post_type'], $pending['result'] );
	}

	/**
	 * Shared advisory DECISION for BOTH published-edit paths (post_content
	 * via on_insert_post_data(), and _elementor_data via the meta hooks
	 * below). Cheap-bails the moment the rendered text hasn't actually
	 * changed — "compare like with like" (#957 adversarial review round
	 * 3): the caller passes exactly the old and new RENDERED text for the
	 * one field that changed, never a mix of the two paths. Returns the
	 * decision only — recording it (a notice + a Control Room issue) is a
	 * separate step (record_content_edit_advisory()), so a caller that
	 * must defer recording until a write is confirmed can (#957
	 * adversarial review round 4 NIT-2).
	 *
	 * Also enforces MAX_ADVISORY_CHECKS_PER_REQUEST (#957 adversarial
	 * review round 4 NIT-3: "bound the advisory work per request") — a
	 * bulk find-replace touching 100 pages must not run 100 full
	 * duplicate_check() calls; the 21st-and-later check in one request is
	 * skipped outright, counted, and surfaced as a single note by
	 * attach_notices_to_response() instead of a per-post one.
	 *
	 * @param int    $post_id     Post ID (always known — this only ever
	 *                            applies to an ALREADY-published post).
	 * @param string $post_type   Post type.
	 * @param string $title       Post title (as it will be after this write).
	 * @param string $old_text    Previously-stored rendered text.
	 * @param string $new_text    Newly-submitted rendered text.
	 * @param int[]  $exclude_ids Extra exclude IDs (e.g. a translation group).
	 * @return array|null {dup, warning}, or null if nothing to advise.
	 */
	private static function evaluate_content_edit_advisory( $post_id, $post_type, $title, $old_text, $new_text, array $exclude_ids = array() ) {
		if ( trim( $new_text ) === trim( $old_text ) ) {
			return null; // Nothing rendered actually changed — no duplicate work.
		}

		// A single logical Elementor save fires the underlying
		// update_post_meta()/updated_post_meta() actions TWICE for the SAME
		// final content in one request — Elementor's own Document::save()
		// writes _elementor_data once, and the writer trait explicitly
		// re-writes (overwrites) the same final value again right after, "to
		// prevent revision/cache divergence" (see trait-mcpwp-elementor-
		// writer.php). The second call's OWN old-vs-new comparison above
		// does not catch this (its "old" value is whatever the FIRST write
		// left in postmeta, which — while extracting the same visible text —
		// is not byte-identical at the JSON level to the plain $new_text
		// this second call also arrives at). Comparing a HASH of the last
		// NEW text this post was actually processed with THIS REQUEST does:
		// identical text means the same logical edit landing twice, not two
		// separate edits — skip re-running the check, but a genuinely
		// DIFFERENT second edit to the same post later in the same request
		// still runs (see the dedupe-per-post test, which relies on exactly
		// that — dedup lives at Mcpwp_SEO_Audit_Store::upsert_issue()'s
		// storage layer, not here). A hash, not the raw text itself, is
		// kept — #957 adversarial review round 4 NIT-2 — nothing needs the
		// text back, only whether it repeats.
		$new_hash = md5( trim( $new_text ) );
		if ( isset( self::$last_advised_text_by_post[ $post_id ] ) && self::$last_advised_text_by_post[ $post_id ] === $new_hash ) {
			return null;
		}

		if ( self::$advisory_checks_this_request >= self::MAX_ADVISORY_CHECKS_PER_REQUEST ) {
			self::$advisory_checks_skipped_count++;
			return null;
		}
		self::$advisory_checks_this_request++;
		self::$last_advised_text_by_post[ $post_id ] = $new_hash;

		$settings     = self::get_settings();
		$dup_limit    = min( self::HARD_DUPLICATE_COMPARE_CAP, $settings['duplicate_compare_limit'] );
		$full_exclude = array_merge( array( $post_id ), $exclude_ids );
		$dup          = self::duplicate_check( $post_type, $title, $new_text, $dup_limit, $full_exclude );
		$dup_flag     = $dup['city_swap'] || ( $dup['score'] >= $settings['duplicate_threshold'] );

		if ( ! $dup_flag ) {
			return null;
		}

		$warning = $dup['city_swap']
			? sprintf(
				/* translators: 1: post type, 2: matched post ID. */
				__( 'This edit now matches an existing %1$s (#%2$d) once the location/name in the title is set aside. Flagged for review in Control Room — the content was saved as submitted.', 'mcpwp' ),
				$post_type,
				(int) $dup['match_id']
			)
			: sprintf(
				/* translators: 1: similarity percentage, 2: post type, 3: matched post ID. */
				__( 'This edit is %1$d%% similar to an existing %2$s (#%3$d). Flagged for review in Control Room — the content was saved as submitted.', 'mcpwp' ),
				round( $dup['score'] * 100 ),
				$post_type,
				(int) $dup['match_id']
			);

		return array( 'dup' => $dup, 'warning' => $warning );
	}

	/**
	 * Record a decision evaluate_content_edit_advisory() already made — a
	 * notice attached the SAME way every other guardrails notice is (#957
	 * adversarial review round 3), merged onto whatever notice this post
	 * already has this request, plus a Control Room issue.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @param array  $result    {dup, warning} from evaluate_content_edit_advisory().
	 * @return void
	 */
	private static function record_content_edit_advisory( $post_id, $post_type, array $result ) {
		$dup      = $result['dup'];
		$settings = self::get_settings();

		$notice               = isset( self::$notices_by_post_id[ $post_id ] )
			? self::$notices_by_post_id[ $post_id ]
			: array( 'guardrails' => array(), 'warnings' => array() );
		$notice['guardrails'] = array_merge(
			$notice['guardrails'],
			array(
				'enabled'               => true,
				'covered'               => true,
				'content_edit_advisory' => true,
				'duplicate'             => array(
					'checked'   => true,
					'score'     => round( $dup['score'], 4 ),
					'threshold' => $settings['duplicate_threshold'],
					'match_id'  => $dup['match_id'],
					'city_swap' => $dup['city_swap'],
					'flagged'   => true,
				),
			)
		);
		$notice['warnings'][] = $result['warning'];
		self::$notices_by_post_id[ $post_id ] = $notice;

		self::record_duplicate_edit_issue( $post_id, $post_type, $dup );
	}

	/**
	 * Record (or refresh) an open SEO/content issue for a duplicate-content
	 * edit advisory, so Control Room's open-issues list surfaces it for a
	 * human (#957 adversarial review round 3: "record an SEO/content issue
	 * in the existing store ... so the Control Room shows it"). Uses
	 * Mcpwp_SEO_Audit_Store::upsert_issue(), a direct single-issue upsert
	 * added specifically for this call (see its own docblock for why
	 * store_issues_for_run() would have been the wrong method: it resolves
	 * a post's OTHER open issues that weren't part of the same "run").
	 * Deduping is "per post, not per call" for free — issue_key() derives
	 * from (post_id, category, code), so a repeat finding on the same post
	 * updates the same record instead of creating a new one each time.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @param array  $dup       duplicate_check() result.
	 * @return void
	 */
	private static function record_duplicate_edit_issue( $post_id, $post_type, array $dup ) {
		if ( ! class_exists( 'Mcpwp_SEO_Audit_Store' ) ) {
			return;
		}

		$post = get_post( $post_id );

		Mcpwp_SEO_Audit_Store::upsert_issue(
			array(
				'post_id'        => $post_id,
				'title'          => $post ? (string) $post->post_title : '',
				'url'            => (string) get_permalink( $post_id ),
				'type'           => $post_type,
				'category'       => 'content_guardrails',
				'code'           => 'duplicate_edit',
				'severity'       => 'warning',
				'message'        => $dup['city_swap']
					? sprintf(
						/* translators: %d: matched post ID. */
						__( 'An edit to this published %1$s now resembles #%2$d once the location/name in the title is set aside (%3$d%% similar).', 'mcpwp' ),
						$post_type,
						(int) $dup['match_id'],
						round( $dup['score'] * 100 )
					)
					: sprintf(
						/* translators: %d: matched post ID. */
						__( 'An edit to this published %1$s is %3$d%% similar to #%2$d.', 'mcpwp' ),
						$post_type,
						(int) $dup['match_id'],
						round( $dup['score'] * 100 )
					),
				'recommendation' => __( 'Review this edit for accidental scaled/duplicate content. If intentional (e.g. a real per-location page), no action needed.', 'mcpwp' ),
			)
		);
	}

	/**
	 * The `update_post_meta` action — WordPress core's PRE-write hook, fired
	 * for every meta key on every post type. Filters to '_elementor_data'
	 * immediately (cheapest possible check) and, only then, captures the
	 * CURRENTLY-stored value before it is overwritten — this is the ONLY
	 * place the old value is ever visible, since the POST-write actions
	 * below receive the new value, not the old one, from WordPress core
	 * itself.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value The NEW value about to be written (unused
	 *                           here — this hook exists only to read the
	 *                           OLD one before it's gone).
	 * @return void
	 */
	public static function on_pre_update_elementor_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( '_elementor_data' !== $meta_key || ! self::in_context() ) {
			return;
		}
		$object_id = (int) $object_id;
		self::$pending_elementor_old_text[ $object_id ] = self::extract_text_from_elementor( get_post_meta( $object_id, '_elementor_data', true ) );
	}

	/**
	 * The `updated_post_meta` action — fires AFTER an EXISTING _elementor_data
	 * row was overwritten.
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value The value just written.
	 * @return void
	 */
	public static function on_updated_elementor_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		self::maybe_advise_on_elementor_meta_change( $object_id, $meta_key, $meta_value, true );
	}

	/**
	 * The `added_post_meta` action — fires when _elementor_data DIDN'T exist
	 * before (a brand-new key on an existing post, OR a brand-new post's own
	 * meta_input — see $suppress_next_elementor_meta_advisory).
	 *
	 * @param int    $meta_id    Meta row ID (unused).
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value The value just written.
	 * @return void
	 */
	public static function on_added_elementor_meta( $meta_id, $object_id, $meta_key, $meta_value ) {
		self::maybe_advise_on_elementor_meta_change( $object_id, $meta_key, $meta_value, false );
	}

	/**
	 * Shared body for on_updated_elementor_meta()/on_added_elementor_meta().
	 * Only runs while in_context() is true, the post is a covered type, and
	 * — the whole point of this chokepoint — the post is CURRENTLY
	 * published (#957 adversarial review round 3: "only while the MCPWP
	 * context is active and the post is published").
	 *
	 * @param int    $object_id      Post ID.
	 * @param string $meta_key       Meta key.
	 * @param mixed  $meta_value     The value just written.
	 * @param bool   $had_old_value  True for updated_post_meta (an old value
	 *                                was captured by the pre-hook); false for
	 *                                added_post_meta (there wasn't one).
	 * @return void
	 */
	private static function maybe_advise_on_elementor_meta_change( $object_id, $meta_key, $meta_value, $had_old_value ) {
		if ( '_elementor_data' !== $meta_key || ! self::in_context() ) {
			return;
		}
		$object_id = (int) $object_id;

		if ( self::$suppress_next_elementor_meta_advisory ) {
			self::$suppress_next_elementor_meta_advisory = false;
			return;
		}

		$old_text = $had_old_value && isset( self::$pending_elementor_old_text[ $object_id ] )
			? self::$pending_elementor_old_text[ $object_id ]
			: '';
		unset( self::$pending_elementor_old_text[ $object_id ] );

		$post = get_post( $object_id );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, self::COVERED_POST_TYPES, true ) ) {
			return;
		}

		$new_text = self::extract_text_from_elementor( $meta_value );
		// This hook only ever fires AFTER WordPress core has already
		// written the row (`updated_post_meta`/`added_post_meta` are both
		// POST-write actions) — recording immediately is correct here; no
		// deferral needed the way the post_content path needs one (#957
		// adversarial review round 4 NIT-2).
		$result = self::evaluate_content_edit_advisory( $object_id, $post->post_type, $post->post_title, $old_text, $new_text );
		if ( null !== $result ) {
			self::record_content_edit_advisory( $object_id, $post->post_type, $result );
		}
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
		// The write landed — confirm the SPECIFIC reservation made for THIS
		// post (#957 adversarial review round 3 NIT-b): an update's
		// reservation is keyed by its own post ID, so this can only ever
		// confirm its own — a NESTED covered publish for a DIFFERENT post
		// cannot collide with it. A brand-new insert has no ID at
		// reservation time, so its confirmation pops the LIFO stack instead
		// (see the class docblock for the residual gap that leaves). If
		// this ISN'T cleared/popped by the time the very next
		// on_insert_post_data() pass (or shutdown) runs, that means the
		// insert never reached here — release_stale_reservation() gives the
		// slot back (#957 adversarial review round 2 WARN-3).
		if ( $update ) {
			unset( self::$pending_reservation_by_post[ (int) $post_id ] );
		} else {
			array_pop( self::$pending_reservation_new_stack );
		}

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
		if ( ! empty( self::$notices_by_post_id ) ) {
			self::attach_notices_recursive( $data, 0 );
			if ( ! empty( self::$notices_by_post_id ) ) {
				$data['guardrails_notices'] = array_values( self::$notices_by_post_id );
				self::$notices_by_post_id   = array();
			}
		}

		// ONE aggregate note, not a per-post one (#957 adversarial review
		// round 4 NIT-3) — a 100-page bulk operation that hit
		// MAX_ADVISORY_CHECKS_PER_REQUEST reports it once, here, rather
		// than stapling the same sentence onto 80 different items.
		if ( self::$advisory_checks_skipped_count > 0 ) {
			$data['guardrails_advisory_note'] = sprintf(
				/* translators: %d: number of pages/posts whose advisory check was skipped. */
				__( 'Advisory checks skipped for %d further pages in this bulk operation.', 'mcpwp' ),
				self::$advisory_checks_skipped_count
			);
			self::$advisory_checks_skipped_count = 0;
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

		$texts     = self::get_post_comparable_texts( $post );
		$settings  = self::get_settings();
		$dup_limit = min( self::HARD_DUPLICATE_COMPARE_CAP, $settings['duplicate_compare_limit'] );
		$dup       = self::duplicate_check( $post_type, $post->post_title, $texts, $dup_limit, array( $post_id ) );

		return array(
			'id'       => $post_id,
			'hash'     => self::content_hash( $post ),
			'findings' => array(
				'word_count'      => self::word_count( $texts[0] ),
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
	 * A GENUINE wp-admin human's apply is exempt from the RAMP CAP (they
	 * are the gate) — but the daily count still increments for every item
	 * that actually publishes, so today's remaining-cap number stays
	 * correct for every OTHER, non-approved publish (#957 adversarial
	 * review round 3 NIT-d, round 4 WARN-2 — see
	 * is_genuine_admin_human_apply() for exactly what "genuine" means: a
	 * logged-in user in a real wp-admin page request, never admin-ajax
	 * (Chat included), never an API key, never OAuth, and never a request
	 * already authenticated as an MCPWP API/OAuth principal, whatever
	 * admin_user it also happens to carry — a self-approve-then-apply
	 * via two API keys, or Chat approving its own batch, both stay
	 * capped). Duplicate/city-swap detection is NEVER exempt — it still
	 * runs, same as any publish.
	 *
	 * @param array  $request Approval record.
	 * @param string $field   'payload' to publish, 'rollback' to revert to draft.
	 * @param array  $context {admin_user?: int} — see is_genuine_admin_human_apply().
	 * @return true|WP_Error
	 */
	public static function apply_bulk_publish( array $request, $field, array $context = array() ) {
		// Applying (or rolling back) a bulk-publish approval always
		// re-runs the same cap/duplicate guard the original publish would
		// have — from wp-admin's Control Room just as much as from the
		// API (#957 adversarial review round 2 NIT-4: the docblock below
		// already claimed this; forcing context active here is what
		// actually makes it true regardless of which door apply came
		// through, since wp-admin never otherwise calls verify_api_key()).
		// force_context_active_without_reset(), NOT the public
		// enter_context() — see its own docblock for why (round 3 NIT-c
		// reset-on-entry must not fire on this internal bracket). Only turn
		// context OFF again afterward if THIS call is what turned it on —
		// an apply reached via the REST API (context already on) must
		// leave it on for the rest of that request.
		$context_was_active = self::in_context();

		// The cap exemption is decided BEFORE force_context_active_
		// without_reset() runs, using whether context was ALREADY active
		// going in — an apply that arrives already API/OAuth-authenticated
		// (context_was_active === true) is NEVER exempt, full stop,
		// regardless of any admin_user the context also carries (#957
		// adversarial review round 4 WARN-2: "an agent that holds two
		// principals ... must stay capped").
		$is_exempt = ! $context_was_active && self::is_genuine_admin_human_apply( $context );

		if ( ! $context_was_active ) {
			self::force_context_active_without_reset();
		}

		$exempt_was_active                = self::$bulk_apply_exempt_from_cap;
		self::$bulk_apply_exempt_from_cap = $is_exempt;

		$result = self::apply_bulk_publish_guarded( $request, $field );

		self::$bulk_apply_exempt_from_cap = $exempt_was_active;
		if ( ! $context_was_active ) {
			self::$context_active = false;
		}

		return $result;
	}

	/**
	 * Whether an apply/rollback call is a GENUINE wp-admin human — the only
	 * caller the ramp cap ever exempts (#957 adversarial review round 4
	 * WARN-2). ALL of:
	 *   - `$context['admin_user']` names a real, positive user ID;
	 *   - that ID matches the CURRENT session's actual logged-in user (a
	 *     caller cannot merely claim an admin_user it does not hold);
	 *   - `is_admin()` is true (a wp-admin-area request) AND `wp_doing_ajax()`
	 *     is false — admin-ajax.php (the Chat UI's transport) reports
	 *     `is_admin() === true` too, so DOING_AJAX is what actually tells
	 *     a real admin PAGE apart from an admin-ajax REQUEST;
	 *   - this is not a REST API request (`REST_REQUEST`) — every REST/MCP
	 *     caller, including Chat's own internal `rest_do_request()` to the
	 *     `/mcp` route, is excluded by this alone, on top of the context-
	 *     already-active exclusion the caller applies separately.
	 *
	 * @param array $context {admin_user?: int}.
	 * @return bool
	 */
	private static function is_genuine_admin_human_apply( array $context ) {
		$admin_user = isset( $context['admin_user'] ) ? (int) $context['admin_user'] : 0;
		if ( $admin_user < 1 ) {
			return false;
		}
		if ( function_exists( 'get_current_user_id' ) && get_current_user_id() !== $admin_user ) {
			return false;
		}
		if ( ! function_exists( 'is_admin' ) || ! is_admin() ) {
			return false;
		}
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}
		return true;
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
	 * @param int  $now         Unix timestamp.
	 * @param int  $cap         Today's cap.
	 * @param bool $bypass_cap  True: skip the cap COMPARISON — the count
	 *                          still increments atomically — for a human-
	 *                          approved bulk-publish apply, where the cap
	 *                          decision has already been made by a person
	 *                          (#957 adversarial review round 3 NIT-d: exempt
	 *                          from the cap, but still counted).
	 * @return bool True: slot reserved (count incremented). False: at cap,
	 *              or contention exhausted every retry (fails closed).
	 */
	private static function try_reserve_daily_slot( $now, $cap, $bypass_cap = false ) {
		$option_name = 'mcpwp_publish_count_' . self::day_key( $now );

		if ( ! class_exists( 'Mcpwp_Encryption' ) ) {
			// No usable $wpdb at all (e.g. a unit test without the double) —
			// best-effort, non-atomic fallback; the atomicity guarantee
			// above is a production property this path cannot provide.
			$current = (int) get_option( $option_name, 0 );
			if ( ! $bypass_cap && $current >= $cap ) {
				return false;
			}
			update_option( $option_name, $current + 1, false );
			return true;
		}

		for ( $attempt = 0; $attempt < 8; $attempt++ ) {
			$row = Mcpwp_Encryption::read_option_row( $option_name );

			if ( Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
				$current = (int) get_option( $option_name, 0 );
				if ( ! $bypass_cap && $current >= $cap ) {
					return false;
				}
				update_option( $option_name, $current + 1, false );
				return true;
			}

			if ( Mcpwp_Encryption::ROW_ABSENT === $row['state'] ) {
				if ( ! $bypass_cap && $cap < 1 ) {
					return false;
				}
				if ( Mcpwp_Encryption::insert_option_row_if_absent( $option_name, '1', 'no' ) ) {
					return true; // First reservation today — starts at 1.
				}
				continue; // Someone else just created it — re-read and CAS from there.
			}

			$current = (int) $row['value'];
			if ( ! $bypass_cap && $current >= $cap ) {
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
	 * Release reservations from an EARLIER on_insert_post_data() pass in
	 * this same request that never got confirmed by a matching
	 * on_wp_insert_post() call — meaning that insert failed somewhere
	 * between the data filter and the row actually being written (#957
	 * adversarial review round 2 WARN-3: "release the reservation if the
	 * insert then fails"). Called at the top of the next reservation
	 * attempt, and on `shutdown` for the last call in a request.
	 *
	 * For an UPDATE's reservation (keyed by post ID), the post ID IS known
	 * — so this checks the post's actual current status before releasing
	 * (#957 adversarial review round 3 NIT-b: "release at shutdown only
	 * reservations whose post isn't actually published"). A reservation
	 * still pending for a post that IS published means bookkeeping simply
	 * never got to clear it — releasing that slot would double-spend it
	 * (the post already legitimately holds it). Only a reservation for a
	 * post that is NOT published (the write failed, or never landed) is
	 * safe to give back. A brand-new insert's stack entries have no post to
	 * check — by construction, an entry still on the stack means that
	 * insert never reached on_wp_insert_post at all, so it is always safe
	 * to release.
	 *
	 * @return void
	 */
	private static function release_stale_reservation() {
		foreach ( self::$pending_reservation_by_post as $post_id => $reserved_at ) {
			$post = get_post( $post_id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				self::release_daily_slot( $reserved_at );
			}
			unset( self::$pending_reservation_by_post[ $post_id ] );
		}

		foreach ( self::$pending_reservation_new_stack as $reserved_at ) {
			self::release_daily_slot( $reserved_at );
		}
		self::$pending_reservation_new_stack = array();
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
	 * The single chokepoint deciding WHICH text is a post's real, rendered
	 * copy — used by BOTH the publish-time gate (on_insert_post_data) and
	 * the candidate side of duplicate_check() (#957 adversarial review
	 * round 4 BLOCK-1). Before this, the gate preferred post_content
	 * (falling back to Elementor only when post_content was thin) while
	 * candidates preferred Elementor (falling back to post_content) — two
	 * DIFFERENT priority orders let a draft be duplicate-checked on one
	 * field while visitors see the other: create a draft, wp_set_elementor
	 * a clone of an existing page onto it, then publish with 30 unique
	 * throwaway filler words in post_content — the gate scored the filler
	 * (>= MIN_COMPARABLE_WORDS, so it never fell back to Elementor) and let
	 * the clone through untouched.
	 *
	 * A "builder page" is one with non-empty extractable _elementor_data OR
	 * `_elementor_edit_mode` meta set to 'builder' — its real, rendered
	 * copy is the Elementor text, full stop. When it ALSO has MEANINGFUL
	 * post_content (>= MIN_COMPARABLE_WORDS — e.g. an SEO description field
	 * some themes duplicate into post_content, or exactly the bypass
	 * above), both are returned: the caller scores each independently and
	 * takes the MAX similarity, so neither field can hide behind the
	 * other.
	 *
	 * @param string $elementor_text    Extracted Elementor text ('' if none).
	 * @param string $edit_mode         `_elementor_edit_mode` meta value ('' if unset).
	 * @param string $post_content_text Plain post_content text ('' if none).
	 * @return string[] One or two text variants to score independently.
	 */
	private static function resolve_comparable_texts( $elementor_text, $edit_mode, $post_content_text ) {
		$is_builder = ( '' !== trim( (string) $elementor_text ) ) || ( 'builder' === $edit_mode );

		if ( ! $is_builder ) {
			return array( (string) $post_content_text );
		}

		$texts = array( (string) $elementor_text );
		if ( self::word_count( $post_content_text ) >= self::MIN_COMPARABLE_WORDS ) {
			$texts[] = (string) $post_content_text;
		}
		return $texts;
	}

	/**
	 * resolve_comparable_texts() for an EXISTING post (a duplicate_check()
	 * candidate, or an approval snapshot) — reads the three raw pieces from
	 * postmeta/post_content itself so every caller shares the exact same
	 * builder-page decision.
	 *
	 * @param object $post Post object.
	 * @return string[] One or two text variants — see resolve_comparable_texts().
	 */
	private static function get_post_comparable_texts( $post ) {
		return self::resolve_comparable_texts(
			self::extract_text_from_elementor( get_post_meta( $post->ID, '_elementor_data', true ) ),
			(string) get_post_meta( $post->ID, '_elementor_edit_mode', true ),
			wp_strip_all_tags( (string) $post->post_content )
		);
	}

	/**
	 * A single representative text for a post — the PRIMARY variant only
	 * (Elementor text for a builder page, post_content otherwise). For
	 * anything that needs to independently score every applicable variant
	 * (duplicate_check()'s own candidate loop), use get_post_comparable_texts()
	 * instead — this exists for callers that only ever wanted one number
	 * (e.g. a word-count to report), not a duplicate/city-swap verdict.
	 *
	 * @param object $post Post object.
	 * @return string Plain text.
	 */
	private static function get_post_effective_text( $post ) {
		$texts = self::get_post_comparable_texts( $post );
		return $texts[0];
	}

	/**
	 * Compare new content against the site's recent published posts of the
	 * same type. Both sides may carry more than one comparable text
	 * variant (a builder page with also-meaningful post_content — see
	 * resolve_comparable_texts()); every NEW variant is scored against
	 * every surviving CANDIDATE variant, and the single worst (most
	 * suspicious) pairing wins.
	 *
	 * @param string   $post_type     Post type.
	 * @param string   $title         New title.
	 * @param string[] $content_texts New content variant(s) (plain text) —
	 *                                see resolve_comparable_texts().
	 * @param int      $limit         Recent posts to compare against.
	 * @param int[]    $exclude_ids   Post IDs never compared against — e.g. the
	 *                                post being updated itself (re-checks at
	 *                                apply time), or a translation's own
	 *                                source/sibling posts in its translation
	 *                                group (#957 adversarial review).
	 * @return array { score: float, match_id: int|null, city_swap: bool }
	 */
	private static function duplicate_check( $post_type, $title, $content_texts, $limit, array $exclude_ids = array() ) {
		$limit = min( self::HARD_DUPLICATE_COMPARE_CAP, max( 1, (int) $limit ) );

		// Nothing meaningful to compare on the NEW side at all — an
		// Elementor-first page has no post_content yet at create time, a
		// contact/thank-you page may be legitimately near-empty. Reporting
		// "0% similar" would be misleading; reporting no match at all is
		// the honest answer.
		$new_variants = array();
		foreach ( (array) $content_texts as $text ) {
			$tokens = self::normalize_words( (string) $text );
			if ( count( $tokens ) < self::MIN_COMPARABLE_WORDS ) {
				continue;
			}
			$new_variants[] = array(
				'tokens'   => $tokens,
				'shingles' => self::shingles_from_tokens( $tokens ),
			);
		}
		if ( empty( $new_variants ) ) {
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

		$new_title_tokens = self::proper_noun_tokens( $title );

		$best = array(
			'score'     => 0.0,
			'match_id'  => null,
			'city_swap' => false,
		);

		foreach ( $recent as $post ) {
			// Tokens AND shingles cached together, per candidate TEXT
			// VARIANT, per request (#957 adversarial review round 3 PERF) —
			// the city-swap check below needs the raw word list, Jaccard
			// needs the shingle set, and a bulk call re-running this
			// comparison many times against the same recent-posts window
			// would otherwise redo both for every one of them.
			$cand_analyses = array();
			foreach ( self::get_post_comparable_texts( $post ) as $cand_text ) {
				$analysis = self::cached_candidate_analysis( (int) $post->ID, $cand_text );
				if ( count( $analysis['tokens'] ) < self::MIN_COMPARABLE_WORDS ) {
					continue; // No comparable text in THIS variant.
				}
				$cand_analyses[] = $analysis;
			}
			if ( empty( $cand_analyses ) ) {
				continue; // No comparable text in ANY variant.
			}

			$cand_tokens = self::proper_noun_tokens( $post->post_title );
			$tokens      = array_values( array_unique( array_merge( $new_title_tokens, $cand_tokens ) ) );

			// Every NEW variant against every CANDIDATE variant — a builder
			// page with also-meaningful post_content on either side must
			// not be able to hide a match behind whichever variant this
			// particular comparison happens to prefer (#957 adversarial
			// review round 4 BLOCK-1).
			foreach ( $new_variants as $nv ) {
				// array_diff() (C-implemented value comparison) in place of
				// array_filter() with a per-element PHP closure — cheaper
				// run many times per duplicate_check() call (#957
				// adversarial review round 3 PERF).
				$stripped_new = implode( ' ', array_diff( $nv['tokens'], $tokens ) );

				foreach ( $cand_analyses as $ca ) {
					$score         = self::jaccard( $nv['shingles'], $ca['shingles'] );
					$stripped_cand = implode( ' ', array_diff( $ca['tokens'], $tokens ) );
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
			}
		}

		return $best;
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
	 * Truncate a document to a generous CHARACTER margin before any
	 * tokenizing work — a cheap, up-front ceiling so a genuinely enormous
	 * document (megabytes of stored content) never even gets handed to a
	 * regex engine at full length. This is a pre-filter, NOT the real
	 * budget: normalize_words() below slices its TOKEN output down to
	 * exactly MAX_WORDS_PER_DOCUMENT, which is what actually equalizes cost
	 * across scripts (#957 adversarial review round 3 PERF: "cap analysis
	 * by TOKEN count ... instead of 30k chars" — a flat character cap alone
	 * let CJK's bigram tokenization produce roughly one token PER CHARACTER
	 * inside that same window, ~10x more shingles than the same character
	 * budget of Latin text, so CJK cost more even though both fit under the
	 * same char limit). The margin here stays generous enough that Latin
	 * text (~7 chars/word) reaches the real token cap on its own.
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
	 * Lower-cased token list, Unicode-aware, punctuation stripped, hard-
	 * capped at MAX_WORDS_PER_DOCUMENT tokens regardless of script (#957
	 * adversarial review round 3 PERF — see truncate_for_analysis()'s
	 * docblock for why the cap has to live here, on the TOKEN count, and
	 * not on the character count alone).
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
	 * @return string[] Tokens (words, or CJK character-bigrams), at most
	 *                  MAX_WORDS_PER_DOCUMENT of them.
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

		// The REAL cap — equalizes cost between a script that tokenizes at
		// roughly one token per character (CJK bigrams) and one that
		// tokenizes at roughly one token per several characters (Latin
		// words), which a character-only truncation could not.
		if ( count( $tokens ) > self::MAX_WORDS_PER_DOCUMENT ) {
			$tokens = array_slice( $tokens, 0, self::MAX_WORDS_PER_DOCUMENT );
		}

		return $tokens;
	}

	/**
	 * Unique 5-word shingles for a token list, represented as crc32() INT
	 * hashes of the joined phrase rather than the phrase string itself
	 * (#957 adversarial review round 3 PERF: "store shingles as int hashes
	 * ... to keep memory low") — array_flip()/array_intersect_key() in
	 * jaccard() work identically over int keys, and a PHP int key is far
	 * cheaper to store than a multi-word string one. See the class
	 * docblock for the (negligible, for a heuristic guardrail) collision
	 * risk this trades for.
	 *
	 * @param string[] $words Token list.
	 * @param int|null $n     Shingle size; defaults to SHINGLE_SIZE.
	 * @return int[] Shingle hashes.
	 */
	private static function shingles_from_tokens( array $words, $n = null ) {
		$n     = $n ? (int) $n : self::SHINGLE_SIZE;
		$count = count( $words );

		if ( 0 === $count ) {
			return array();
		}
		if ( $count < $n ) {
			return array( crc32( implode( ' ', $words ) ) );
		}

		$shingles = array();
		for ( $i = 0; $i <= $count - $n; $i++ ) {
			$shingles[ crc32( implode( ' ', array_slice( $words, $i, $n ) ) ) ] = true;
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
	 * @return int[] Shingle hashes.
	 */
	private static function shingles( $text, $n = null ) {
		return self::shingles_from_tokens( self::normalize_words( $text ), $n );
	}

	/**
	 * Estimate one cached candidate's own memory footprint, in bytes — see
	 * MAX_TOKEN_CACHE_BYTES.
	 *
	 * @param array $value { tokens: string[], shingles: int[] }.
	 * @return int Estimated bytes.
	 */
	private static function estimate_candidate_bytes( array $value ) {
		$bytes = 0;
		foreach ( $value['tokens'] as $token ) {
			// strlen() (byte length), plus PHP's own per-string-array-entry
			// overhead — a rough, deliberately conservative estimate, not an
			// exact accounting.
			$bytes += strlen( $token ) + 48;
		}
		// Int keys are far cheaper than string ones, but PHP's array
		// bucket itself still costs something per entry.
		$bytes += count( $value['shingles'] ) * 16;
		return $bytes;
	}

	/**
	 * One candidate post's derived analysis — normalized tokens AND their
	 * shingle-hash set, computed together and LRU-cached per request by
	 * "{post_id}:{content hash}" (#957 adversarial review round 3 PERF:
	 * "replace the 50-entry LRU with a per-request candidate token cache
	 * sized to the comparison window (200)"), so a bulk call or blueprint
	 * deploy checking many new items against the same recent-posts window
	 * does not redo either for the same candidate repeatedly.
	 *
	 * Past MAX_TOKEN_CACHE_BYTES of estimated cache footprint, this stops
	 * ADDING new entries and simply returns the freshly-computed result
	 * uncached (#957 adversarial review round 3 PERF: "add a memory guard
	 * ... fall back to recomputing") — correctness never depends on the
	 * cache; it is purely a speed optimization that bounds its own memory.
	 *
	 * @param int    $post_id Post ID (cache key component only).
	 * @param string $text    Candidate text.
	 * @return array { tokens: string[], shingles: int[] }.
	 */
	private static function cached_candidate_analysis( $post_id, $text ) {
		$key = $post_id . ':' . md5( $text );

		if ( isset( self::$token_cache[ $key ] ) ) {
			// Touch: move to the end (most-recently-used) without changing
			// the value.
			$value = self::$token_cache[ $key ];
			unset( self::$token_cache[ $key ] );
			self::$token_cache[ $key ] = $value;
			return $value;
		}

		$tokens = self::normalize_words( $text );
		$value  = array(
			'tokens'   => $tokens,
			'shingles' => self::shingles_from_tokens( $tokens ),
		);

		if ( count( self::$token_cache ) >= self::MAX_TOKEN_CACHE_ENTRIES ) {
			// array key order IS insertion/touch order here — evict the
			// least-recently-used (front) entry.
			$evicted_key = array_key_first( self::$token_cache );
			self::$token_cache_bytes -= self::estimate_candidate_bytes( self::$token_cache[ $evicted_key ] );
			array_shift( self::$token_cache );
		}

		$bytes = self::estimate_candidate_bytes( $value );
		if ( ( self::$token_cache_bytes + $bytes ) > self::MAX_TOKEN_CACHE_BYTES ) {
			return $value; // Memory guard: correct, just not cached.
		}

		self::$token_cache[ $key ] = $value;
		self::$token_cache_bytes  += $bytes;
		return $value;
	}

	/**
	 * Jaccard similarity between two shingle-hash sets.
	 *
	 * @param int[] $a Shingle hash set A.
	 * @param int[] $b Shingle hash set B.
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
	 * word-count and duplicate-shingle purposes. Not a renderer — recurses
	 * through every settings value (#957 adversarial review round 4
	 * WARN-1 — the fixed-list-of-keys version used to miss most widget
	 * copy: icon-box's title_text/description_text, icon_list/tabs/
	 * accordion repeater items, testimonial_content, and anything inside a
	 * referenced template were all invisible to duplicate detection).
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

		$texts             = array();
		$visited_templates = array();
		self::walk_elementor_text( $data, $texts, true, $visited_templates );
		return trim( implode( ' ', $texts ) );
	}

	/**
	 * Settings-key substrings that mark a value as non-copy metadata
	 * (dimensions, colors, typography, identifiers, markup/asset
	 * references, …) rather than visible text — matched against each
	 * underscore/non-alpha-delimited SEGMENT of a key, not a raw substring
	 * search, so a real copy key that happens to CONTAIN one of these as
	 * running text ("guide_text" contains "id") is never wrongly excluded
	 * (#957 adversarial review round 4 WARN-1).
	 *
	 * @var string[]
	 */
	const ELEMENTOR_TEXT_DENYLIST_KEY_TERMS = array(
		'url', 'link', 'href', 'src', 'id', 'css', 'class', 'color',
		'typography', 'size', 'width', 'height', 'margin', 'padding',
		'border', 'background', 'animation',
	);

	/**
	 * Whether a settings key is likely to hold visible copy (true) or
	 * layout/style/identifier metadata (false) — see
	 * ELEMENTOR_TEXT_DENYLIST_KEY_TERMS.
	 *
	 * @param string|int $key Settings key.
	 * @return bool
	 */
	private static function is_elementor_copy_key( $key ) {
		if ( ! is_string( $key ) ) {
			return true; // A numeric list index carries no key semantics of its own.
		}
		$key = strtolower( ltrim( $key, '_' ) );
		if ( '' === $key ) {
			return false;
		}
		foreach ( preg_split( '/[^a-z]+/', $key ) as $segment ) {
			if ( '' !== $segment && in_array( $segment, self::ELEMENTOR_TEXT_DENYLIST_KEY_TERMS, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a string value's own SHAPE (not its key) marks it as non-copy
	 * — a URL, a hex/rgb() color, a bare number, or an inline JSON blob
	 * (#957 adversarial review round 4 WARN-1: "plus values that look like
	 * URLs, colors, numbers or JSON").
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function looks_like_non_copy_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return true;
		}
		if ( is_numeric( $value ) ) {
			return true;
		}
		if ( preg_match( '#^(https?:)?//#i', $value ) || preg_match( '#^\.{0,2}/[^\s]*$#', $value ) ) {
			return true; // Absolute/protocol-relative URL, or a bare path.
		}
		if ( preg_match( '/^#[0-9a-f]{3,8}$/i', $value ) || preg_match( '/^rgba?\(/i', $value ) || preg_match( '/^hsla?\(/i', $value ) ) {
			return true; // Hex/rgb()/rgba()/hsl() color.
		}
		$first = $value[0];
		$last  = substr( $value, -1 );
		if ( ( '{' === $first && '}' === $last ) || ( '[' === $first && ']' === $last ) ) {
			// An inline (undecoded) JSON blob rather than prose — prose
			// essentially never starts AND ends with a bracket pair.
			json_decode( $value, true );
			if ( JSON_ERROR_NONE === json_last_error() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Recursively collect text-bearing widget settings — one element (or a
	 * list of elements/repeater items), then its children.
	 *
	 * @param mixed    $nodes                 One element (assoc array) or a list of elements.
	 * @param string[] $texts                 Accumulator, by reference.
	 * @param bool     $allow_template_lookup Whether a `template_id` setting
	 *                                        found here may pull in that
	 *                                        template's OWN text — true at
	 *                                        the top level, false once
	 *                                        already inside a looked-up
	 *                                        template (ONE level deep only).
	 * @param array    $visited_templates     Template post IDs already
	 *                                        expanded this call — cycle guard.
	 * @return void
	 */
	private static function walk_elementor_text( $nodes, array &$texts, $allow_template_lookup = true, array &$visited_templates = array() ) {
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
				self::walk_elementor_settings( $node['settings'], $texts );
				if ( $allow_template_lookup ) {
					self::maybe_walk_elementor_template_reference( $node['settings'], $texts, $visited_templates );
				}
			}
			if ( ! empty( $node['elements'] ) && is_array( $node['elements'] ) ) {
				self::walk_elementor_text( $node['elements'], $texts, $allow_template_lookup, $visited_templates );
			}
		}
	}

	/**
	 * Recurse through EVERY value of a settings array (or a nested
	 * repeater item / control found within it — icon_list, tabs, accordion
	 * items, and so on are all just arrays at this level, walked the same
	 * way regardless of depth), collecting copy-bearing strings and
	 * skipping denylisted keys / non-copy-shaped values (#957 adversarial
	 * review round 4 WARN-1).
	 *
	 * @param array    $settings Settings (or a nested item) to walk.
	 * @param string[] $texts    Accumulator, by reference.
	 * @return void
	 */
	private static function walk_elementor_settings( array $settings, array &$texts ) {
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				self::walk_elementor_settings( $value, $texts );
				continue;
			}
			if ( ! is_string( $value ) || ! self::is_elementor_copy_key( $key ) || self::looks_like_non_copy_value( $value ) ) {
				continue;
			}
			$stripped = trim( wp_strip_all_tags( $value ) );
			if ( '' !== $stripped ) {
				$texts[] = $stripped;
			}
		}
	}

	/**
	 * If this element's settings reference a template (a "Template"/
	 * "Global Widget" style `template_id` setting), pull that template's
	 * OWN Elementor text in — ONE level deep only, and never the same
	 * template twice in one extraction (#957 adversarial review round 4
	 * WARN-1: "include that template's Elementor text one level deep (no
	 * recursion into further templates; guard cycles)").
	 *
	 * @param array    $settings          Element settings.
	 * @param string[] $texts             Accumulator, by reference.
	 * @param array    $visited_templates Template post IDs already expanded, by reference.
	 * @return void
	 */
	private static function maybe_walk_elementor_template_reference( array $settings, array &$texts, array &$visited_templates ) {
		if ( empty( $settings['template_id'] ) ) {
			return;
		}
		$template_id = absint( $settings['template_id'] );
		if ( $template_id <= 0 || isset( $visited_templates[ $template_id ] ) ) {
			return;
		}
		$visited_templates[ $template_id ] = true;

		$template_data = get_post_meta( $template_id, '_elementor_data', true );
		if ( empty( $template_data ) ) {
			return;
		}
		$decoded = is_string( $template_data ) ? json_decode( $template_data, true ) : $template_data;
		if ( ! is_array( $decoded ) ) {
			return;
		}

		self::walk_elementor_text( $decoded, $texts, false, $visited_templates );
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
