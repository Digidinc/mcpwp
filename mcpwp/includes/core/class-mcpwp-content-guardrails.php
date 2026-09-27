<?php
/**
 * Content guardrails against scaled and duplicate publishing (#957).
 *
 * Owner concern: the self-improving loop is allowed to run on its own, but it
 * must not be usable as a mass thin-page, link-spam, or "parasite SEO" tool —
 * the patterns Google's spam policies describe as scaled content abuse and
 * site reputation abuse. This class is the single place every create/publish
 * tool path consults before a post, page, or Elementor-built page goes live
 * through MCPWP:
 *
 *   1. Publishing ramp — at most N new *published* posts+pages per site per
 *      rolling 24h through MCPWP (site-level; admin keys included). Over the
 *      cap, the write still happens, just as a draft, with a short teaching
 *      message. Drafts are never capped.
 *   2. Bulk creation — a single bulk call that would publish more than M
 *      items goes through the existing approval pipeline instead: every item
 *      is created as a draft and one approval request covers publishing the
 *      whole batch. A bulk call that only creates drafts is never gated.
 *   3. Thin/near-duplicate detection — a word-count floor for published
 *      content, plus a 5-word-shingle Jaccard comparison against the site's
 *      own recent posts of the same type, plus a "city-swap" heuristic for
 *      the classic local-SEO clone-and-rename pattern. Only enforced when the
 *      write is actually publishing; drafts are informational-only (skipped
 *      entirely — see evaluate()).
 *   4. Anomaly signal — a `content.publish_spike` event when the 24h
 *      published count crosses both an absolute floor and 2x the trailing
 *      30-day daily average, so Control Room/webhooks see it.
 *
 * Deliberately NOT reused: Mcpwp_Rate_Limiter (per-identifier request-rate
 * windows, a different problem) and Mcpwp_Destructive_Gate's per-requester
 * pending caps (built for irreversible deletes, not creation). This class
 * follows the same *shape* as Mcpwp_Destructive_Gate — a small policy gate
 * that calls into Mcpwp_Approvals's public API rather than duplicating its
 * storage — without sharing its constants.
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
	 * Rolling publish-event log option name. Array of {t: unix timestamp,
	 * type: post type}, pruned to the last 31 days on every write (30 days
	 * of anomaly baseline, plus the current rolling 24h).
	 *
	 * @var string
	 */
	const PUBLISH_LOG_OPTION = 'mcpwp_content_guardrails_publish_log';

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
	 * Per-request shingle cache, keyed by "{post_id}:{content hash}" so a
	 * bulk call or blueprint deploy that checks many new items against the
	 * same recent-posts window does not re-tokenize each candidate post once
	 * per new item (#957: "precompute shingles cheaply").
	 *
	 * @var array<string,string[]>
	 */
	private static $shingle_cache = array();

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
	 * Get current settings, merged with defaults and bounds-clamped.
	 *
	 * @return array Settings.
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::sanitize_settings( array_merge( self::default_settings(), $stored ) );
	}

	/**
	 * Persist new settings (bounds-clamped).
	 *
	 * @param array $input Raw input, e.g. from a settings form or REST body.
	 * @return array Stored (sanitized) settings.
	 */
	public static function update_settings( $input ) {
		$sanitized = self::sanitize_settings( is_array( $input ) ? $input : array() );
		update_option( self::OPTION_NAME, $sanitized, false );
		return $sanitized;
	}

	/**
	 * Clamp every field to a sane bound. Always starts from defaults, so a
	 * partial or malformed input never leaves a field unset.
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		$defaults  = self::default_settings();
		$input     = is_array( $input ) ? $input : array();
		$sanitized = $defaults;

		$sanitized['enabled'] = array_key_exists( 'enabled', $input )
			? ! empty( $input['enabled'] )
			: $defaults['enabled'];

		$sanitized['daily_publish_cap'] = isset( $input['daily_publish_cap'] )
			? max( 1, min( 100000, absint( $input['daily_publish_cap'] ) ) )
			: $defaults['daily_publish_cap'];

		$sanitized['bulk_approval_threshold'] = isset( $input['bulk_approval_threshold'] )
			? max( 1, min( 1000, absint( $input['bulk_approval_threshold'] ) ) )
			: $defaults['bulk_approval_threshold'];

		$sanitized['min_word_count'] = isset( $input['min_word_count'] )
			? max( 0, min( 5000, absint( $input['min_word_count'] ) ) )
			: $defaults['min_word_count'];

		$sanitized['duplicate_threshold'] = isset( $input['duplicate_threshold'] )
			? max( 0.1, min( 1.0, (float) $input['duplicate_threshold'] ) )
			: $defaults['duplicate_threshold'];

		// Never above the hard architectural cap, whatever is stored.
		$sanitized['duplicate_compare_limit'] = isset( $input['duplicate_compare_limit'] )
			? max( 1, min( self::HARD_DUPLICATE_COMPARE_CAP, absint( $input['duplicate_compare_limit'] ) ) )
			: $defaults['duplicate_compare_limit'];

		$sanitized['anomaly_multiplier'] = isset( $input['anomaly_multiplier'] )
			? max( 1.0, min( 10.0, (float) $input['anomaly_multiplier'] ) )
			: $defaults['anomaly_multiplier'];

		$sanitized['anomaly_min_count'] = isset( $input['anomaly_min_count'] )
			? max( 1, min( 1000, absint( $input['anomaly_min_count'] ) ) )
			: $defaults['anomaly_min_count'];

		return $sanitized;
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
	// Single-item chokepoint

	/**
	 * Evaluate a single post/page create-or-publish call. Called from every
	 * single-item write path (Mcpwp_Posts::create_post(),
	 * Mcpwp_Pages::create_page(), clone_page(), create_landing_page(),
	 * Mcpwp_Page_Builder::build(), the WPML/Polylang translation creators)
	 * BEFORE wp_insert_post()/wp_update_post() runs, so the caller can use
	 * the (possibly downgraded) effective status.
	 *
	 * Bulk creation reuses this too — bulk tools loop over create_post()/
	 * create_page() per item, so the ramp cap and thin/duplicate checks
	 * apply per item automatically. The bulk-approval threshold (rule 2) is a
	 * separate, call-level decision — see plan_bulk_publish().
	 *
	 * @param array $args {
	 *     @type string $post_type Post type. Only 'post'/'page' are covered.
	 *     @type string $status    Requested status, already allow-listed by
	 *                             the caller to publish/draft/pending/private.
	 *     @type string $title     Title, for the city-swap heuristic.
	 *     @type string $content   Body text (HTML or plain; stripped here).
	 *     @type int    $now       Optional Unix timestamp, for tests.
	 * }
	 * @return array {
	 *     @type string $status     Effective status to actually write.
	 *     @type bool   $downgraded Whether publish was downgraded to draft.
	 *     @type string[] $warnings Teaching messages for the API response.
	 *     @type array  $guardrails Structured detail for the response body.
	 * }
	 */
	public static function evaluate( array $args ) {
		$post_type    = isset( $args['post_type'] ) ? sanitize_key( (string) $args['post_type'] ) : 'post';
		$status       = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : 'draft';
		$title        = isset( $args['title'] ) ? (string) $args['title'] : '';
		$content_text = isset( $args['content'] ) ? wp_strip_all_tags( (string) $args['content'] ) : '';
		$now          = isset( $args['now'] ) ? (int) $args['now'] : time();

		$enabled = self::is_enabled();
		$covered = in_array( $post_type, self::COVERED_POST_TYPES, true );

		$result = array(
			'status'     => $status,
			'downgraded' => false,
			'warnings'   => array(),
			'guardrails' => array(
				'enabled' => $enabled,
				'covered' => $covered,
			),
		);

		if ( ! $enabled || ! $covered ) {
			return $result;
		}

		// Rule 1/3: never enforced for drafts/pending/private — only a write
		// that is actually publishing is capped, floor-checked, or
		// duplicate-checked.
		if ( 'publish' !== $status ) {
			return $result;
		}

		$settings = self::get_settings();

		$published_24h = self::count_published_last_24h( $now );
		$ramp_exceeded = $published_24h >= $settings['daily_publish_cap'];

		$word_count = self::word_count( $content_text );
		$thin       = $word_count < $settings['min_word_count'];

		$dup_limit      = min( self::HARD_DUPLICATE_COMPARE_CAP, $settings['duplicate_compare_limit'] );
		$dup            = self::duplicate_check( $post_type, $title, $content_text, $dup_limit );
		$duplicate_flag = $dup['city_swap'] || ( $dup['score'] >= $settings['duplicate_threshold'] );

		$result['guardrails']['ramp'] = array(
			'published_last_24h' => $published_24h,
			'cap'                => $settings['daily_publish_cap'],
			'remaining'          => max( 0, $settings['daily_publish_cap'] - $published_24h ),
		);
		$result['guardrails']['thin_content'] = array(
			'word_count'     => $word_count,
			'min_word_count' => $settings['min_word_count'],
			'is_thin'        => $thin,
		);
		$result['guardrails']['duplicate'] = array(
			'checked'   => true,
			'score'     => round( $dup['score'], 4 ),
			'threshold' => $settings['duplicate_threshold'],
			'match_id'  => $dup['match_id'],
			'city_swap' => $dup['city_swap'],
			'flagged'   => $duplicate_flag,
		);

		if ( $ramp_exceeded || $thin || $duplicate_flag ) {
			$result['status']     = 'draft';
			$result['downgraded'] = true;

			if ( $ramp_exceeded ) {
				$result['warnings'][] = sprintf(
					/* translators: 1: published count in the last 24h, 2: daily cap. */
					__( 'Publishing cap reached (%1$d/%2$d published in the last 24h) — created as a draft instead. Publish it manually, or wait for the daily window to roll over.', 'mcpwp' ),
					$published_24h,
					$settings['daily_publish_cap']
				);
			}
			if ( $thin ) {
				$result['warnings'][] = sprintf(
					/* translators: 1: word count, 2: minimum word count. */
					__( 'Content is %1$d words, below the %2$d-word floor for published content — created as a draft instead. Add more original content, then publish.', 'mcpwp' ),
					$word_count,
					$settings['min_word_count']
				);
			}
			if ( $duplicate_flag ) {
				$result['warnings'][] = $dup['city_swap']
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
		} else {
			self::record_publish( $post_type, 1, $now );
		}

		return $result;
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
	 * queues the decision to flip them all to 'publish'. Delegates the
	 * actual request storage to Mcpwp_Approvals, which owns the lifecycle;
	 * see apply_bulk_publish() for the other half, called back from
	 * Mcpwp_Approvals::apply_request()/rollback_request().
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

		return Mcpwp_Approvals::create_bulk_publish_request( $post_ids, $post_type, $args );
	}

	/**
	 * Apply (or roll back) a bulk-publish approval request. Called from
	 * Mcpwp_Approvals::apply_payload() — never called directly by a
	 * controller, so the approval lifecycle (status transitions, actor
	 * checks) stays entirely inside Mcpwp_Approvals.
	 *
	 * @param array  $request Approval record (payload/rollback both carry
	 *                        the same {post_ids, post_type} shape here).
	 * @param string $field   'payload' to publish, 'rollback' to revert to draft.
	 * @return true|WP_Error
	 */
	public static function apply_bulk_publish( array $request, $field ) {
		$data      = isset( $request[ $field ] ) && is_array( $request[ $field ] ) ? $request[ $field ] : array();
		$post_ids  = isset( $data['post_ids'] ) ? array_map( 'absint', (array) $data['post_ids'] ) : array();
		$post_type = isset( $data['post_type'] ) ? sanitize_key( (string) $data['post_type'] ) : 'post';

		if ( empty( $post_ids ) ) {
			return new WP_Error( 'no_items', __( 'No items recorded on this approval request.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$target_status = ( 'rollback' === $field ) ? 'draft' : 'publish';
		$applied       = 0;

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_status === $target_status ) {
				continue;
			}
			$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => $target_status ), true );
			if ( ! is_wp_error( $result ) ) {
				$applied++;
			}
		}

		if ( 'publish' === $target_status && $applied > 0 ) {
			self::record_publish( $post_type, $applied );
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Rolling publish log + anomaly signal (rule 4)

	/**
	 * Count posts+pages published through MCPWP in the last rolling 24h.
	 *
	 * @param int|null $now Unix timestamp; defaults to time().
	 * @return int Count.
	 */
	public static function count_published_last_24h( $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$log    = self::prune_log( self::get_publish_log(), $now );
		$cutoff = $now - DAY_IN_SECONDS;
		$count  = 0;

		foreach ( $log as $entry ) {
			if ( isset( $entry['t'] ) && (int) $entry['t'] >= $cutoff ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Record N successful publishes and check for an anomaly.
	 *
	 * @param string   $post_type Post type published.
	 * @param int      $count     Number of items published in this call.
	 * @param int|null $now       Unix timestamp; defaults to time().
	 * @return void
	 */
	public static function record_publish( $post_type, $count = 1, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$count = max( 1, (int) $count );
		$log   = self::prune_log( self::get_publish_log(), $now );

		$day_ago    = $now - DAY_IN_SECONDS;
		$before_24h = 0;
		foreach ( $log as $entry ) {
			if ( isset( $entry['t'] ) && (int) $entry['t'] >= $day_ago ) {
				$before_24h++;
			}
		}

		for ( $i = 0; $i < $count; $i++ ) {
			$log[] = array(
				't'    => $now,
				'type' => sanitize_key( (string) $post_type ),
			);
		}

		update_option( self::PUBLISH_LOG_OPTION, $log, false );

		self::maybe_emit_anomaly( $post_type, $log, $now, $before_24h, $before_24h + $count );
	}

	/**
	 * Read-only status block for wp_onboard / wp_site_info.
	 *
	 * @param int|null $now Unix timestamp; defaults to time().
	 * @return array Status summary.
	 */
	public static function get_status_summary( $now = null ) {
		$settings = self::get_settings();
		$count    = self::count_published_last_24h( $now );

		return array(
			'enabled'                 => $settings['enabled'],
			'published_last_24h'      => $count,
			'daily_publish_cap'       => $settings['daily_publish_cap'],
			'remaining_today'         => max( 0, $settings['daily_publish_cap'] - $count ),
			'bulk_approval_threshold' => $settings['bulk_approval_threshold'],
			'min_word_count'          => $settings['min_word_count'],
		);
	}

	/**
	 * Get the raw publish log.
	 *
	 * @return array Log entries.
	 */
	private static function get_publish_log() {
		$log = get_option( self::PUBLISH_LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Drop entries older than the anomaly baseline window (31 days: 30 days
	 * of baseline plus the current rolling 24h) so the option never grows
	 * unbounded.
	 *
	 * @param array $log Log entries.
	 * @param int   $now Unix timestamp.
	 * @return array Pruned log.
	 */
	private static function prune_log( array $log, $now ) {
		$cutoff = $now - ( 31 * DAY_IN_SECONDS );
		return array_values(
			array_filter(
				$log,
				function ( $entry ) use ( $cutoff ) {
					return isset( $entry['t'] ) && (int) $entry['t'] >= $cutoff;
				}
			)
		);
	}

	/**
	 * Emit 'content.publish_spike' the moment the rolling 24h count crosses
	 * both an absolute floor and 2x (by default) the trailing 30-day daily
	 * average — fires once per crossing, not on every publish once already
	 * over the line, because it compares the count immediately before this
	 * call against the count immediately after.
	 *
	 * @param string $post_type  Post type just published.
	 * @param array  $log        Full (pruned) log, including this publish.
	 * @param int    $now        Unix timestamp.
	 * @param int    $before_24h 24h count before this publish.
	 * @param int    $after_24h  24h count after this publish.
	 * @return void
	 */
	private static function maybe_emit_anomaly( $post_type, array $log, $now, $before_24h, $after_24h ) {
		if ( ! class_exists( 'Mcpwp_Event_Store' ) ) {
			return;
		}

		$settings       = self::get_settings();
		$day_ago        = $now - DAY_IN_SECONDS;
		$baseline_start = $now - ( 31 * DAY_IN_SECONDS );

		$baseline_count = 0;
		foreach ( $log as $entry ) {
			$t = isset( $entry['t'] ) ? (int) $entry['t'] : 0;
			if ( $t >= $baseline_start && $t < $day_ago ) {
				$baseline_count++;
			}
		}

		$daily_avg = $baseline_count / 30;
		$threshold = max( (float) $settings['anomaly_min_count'], $settings['anomaly_multiplier'] * $daily_avg );

		if ( $after_24h >= $settings['anomaly_min_count'] && $after_24h > $threshold && $before_24h <= $threshold ) {
			Mcpwp_Event_Store::emit(
				'content.publish_spike',
				array(
					'published_last_24h' => $after_24h,
					'daily_average_30d'  => round( $daily_avg, 2 ),
					'threshold'          => round( $threshold, 2 ),
					'post_type'          => sanitize_key( (string) $post_type ),
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
	 * Compare new content against the site's recent published posts of the
	 * same type.
	 *
	 * @param string $post_type    Post type.
	 * @param string $title        New title.
	 * @param string $content_text New content (plain text).
	 * @param int    $limit        Recent posts to compare against.
	 * @return array { score: float, match_id: int|null, city_swap: bool }
	 */
	private static function duplicate_check( $post_type, $title, $content_text, $limit ) {
		$limit = min( self::HARD_DUPLICATE_COMPARE_CAP, max( 1, (int) $limit ) );

		$recent = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$new_shingles = self::shingles( $content_text );
		$new_tokens   = self::proper_noun_tokens( $title );

		$best = array(
			'score'     => 0.0,
			'match_id'  => null,
			'city_swap' => false,
		);

		foreach ( $recent as $post ) {
			$cand_text     = self::get_post_effective_text( $post );
			$cand_shingles = self::cached_shingles( (int) $post->ID, $cand_text );
			$score         = self::jaccard( $new_shingles, $cand_shingles );

			$cand_tokens   = self::proper_noun_tokens( $post->post_title );
			$tokens        = array_values( array_unique( array_merge( $new_tokens, $cand_tokens ) ) );
			$stripped_new  = self::strip_tokens( $content_text, $tokens );
			$stripped_cand = self::strip_tokens( $cand_text, $tokens );
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
	 * Word count of a text, Unicode-aware (not str_word_count(), which is
	 * ASCII-only and would undercount non-English content).
	 *
	 * @param string $text Text.
	 * @return int Word count.
	 */
	private static function word_count( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $text ) {
			return 0;
		}
		$matches = array();
		preg_match_all( '/[\p{L}\p{N}]+(?:[\'’-][\p{L}\p{N}]+)*/u', $text, $matches );
		return count( $matches[0] );
	}

	/**
	 * Lower-cased word list, Unicode-aware, punctuation stripped.
	 *
	 * @param string $text Text.
	 * @return string[] Words.
	 */
	private static function normalize_words( $text ) {
		$text    = strtolower( wp_strip_all_tags( (string) $text ) );
		$matches = array();
		preg_match_all( '/[\p{L}\p{N}]+/u', $text, $matches );
		return $matches[0];
	}

	/**
	 * Unique 5-word shingles for a text.
	 *
	 * @param string   $text Text.
	 * @param int|null $n    Shingle size; defaults to SHINGLE_SIZE.
	 * @return string[] Shingles.
	 */
	private static function shingles( $text, $n = null ) {
		$n     = $n ? (int) $n : self::SHINGLE_SIZE;
		$words = self::normalize_words( $text );
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
	 * Shingles for a post, cached per request by content hash so a bulk call
	 * or blueprint deploy checking many new items against the same recent
	 * posts does not re-tokenize each candidate repeatedly.
	 *
	 * @param int    $post_id Post ID (cache key component only).
	 * @param string $text    Candidate text.
	 * @return string[] Shingles.
	 */
	private static function cached_shingles( $post_id, $text ) {
		$key = $post_id . ':' . md5( $text );
		if ( ! isset( self::$shingle_cache[ $key ] ) ) {
			// Simple unbounded-growth guard for a long-lived worker process.
			if ( count( self::$shingle_cache ) > 512 ) {
				self::$shingle_cache = array();
			}
			self::$shingle_cache[ $key ] = self::shingles( $text );
		}
		return self::$shingle_cache[ $key ];
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

	/**
	 * Normalize a text and remove a set of tokens from it entirely — used to
	 * test whether two bodies are identical once the location/name in the
	 * title is discounted.
	 *
	 * @param string   $text   Text.
	 * @param string[] $tokens Lower-cased tokens to remove.
	 * @return string Normalized, token-stripped text.
	 */
	private static function strip_tokens( $text, array $tokens ) {
		$words = self::normalize_words( $text );
		if ( empty( $tokens ) ) {
			return implode( ' ', $words );
		}
		$tokens_flip = array_flip( $tokens );
		$filtered    = array_values(
			array_filter(
				$words,
				function ( $w ) use ( $tokens_flip ) {
					return ! isset( $tokens_flip[ $w ] );
				}
			)
		);
		return implode( ' ', $filtered );
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
				'description' => __( 'Max new posts+pages this site will publish through MCPWP per rolling 24 hours (applies to admin keys too). Over the cap, new content is created as a draft instead of being dropped.', 'mcpwp' ),
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
		printf(
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
