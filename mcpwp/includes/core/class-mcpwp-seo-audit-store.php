<?php
/**
 * SEO audit run and issue store.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores recent SEO audit runs and normalized issue records.
 */
class Mcpwp_SEO_Audit_Store {

	/**
	 * Audit runs option.
	 *
	 * @var string
	 */
	const RUNS_OPTION = 'mcpwp_seo_audit_runs';

	/**
	 * SEO issues option.
	 *
	 * @var string
	 */
	const ISSUES_OPTION = 'mcpwp_seo_issues';

	/**
	 * Maximum stored audit runs.
	 *
	 * @var int
	 */
	const MAX_RUNS = 30;

	/**
	 * Maximum stored issues.
	 *
	 * @var int
	 */
	const MAX_ISSUES = 1000;

	/**
	 * Guardrail advisories option — DELIBERATELY separate from
	 * ISSUES_OPTION (#957 adversarial review round 4 WARN-3: "guardrail
	 * advisories must not live and die with SEO audit runs"). Kept in its
	 * own array/option so store_issues_for_run() — which resolves every
	 * OTHER open issue a run's own url-rows didn't re-report, correct for
	 * a full SEO audit pass but wrong for an unrelated ad-hoc finding —
	 * structurally cannot see or resolve these records at all, and so a
	 * flood of advisories (e.g. a bulk find-replace touching hundreds of
	 * pages) can never evict a real SEO issue from ISSUES_OPTION's own
	 * MAX_ISSUES cap.
	 *
	 * @var string
	 */
	const GUARDRAIL_ISSUES_OPTION = 'mcpwp_content_guardrail_issues';

	/**
	 * Maximum stored guardrail advisories — capped SEPARATELY from
	 * MAX_ISSUES (#957 adversarial review round 4 WARN-3), so a flood of
	 * advisories only ever evicts OTHER advisories, never a real SEO issue.
	 *
	 * @var int
	 */
	const MAX_GUARDRAIL_ISSUES = 200;

	/**
	 * Directly upsert ONE guardrail advisory, into GUARDRAIL_ISSUES_OPTION
	 * — a store completely separate from the one store_issues_for_run()
	 * operates on (see GUARDRAIL_ISSUES_OPTION's own docblock for why).
	 * Content guardrails (#957) uses this for a published-edit duplicate-
	 * content advisory; nothing here is a general-purpose "add an SEO
	 * issue" API for other callers.
	 *
	 * Reuses issue_key()'s existing (post_id, category, code) derivation, so
	 * calling this again for the SAME post+category+code updates the same
	 * record (seen_count, last_seen_at) instead of creating a second one —
	 * "dedupe per post, not per call" falls out of the existing key shape
	 * for free. list_issues() merges this store with the SEO-audit one so
	 * Control Room's open-issues view still shows both together.
	 *
	 * @param array $issue { post_id, title, url, type, category, code, severity, message, recommendation, approval_required }.
	 * @return string The issue's stable key.
	 */
	public static function upsert_issue( array $issue ) {
		$issues = self::get_guardrail_issues();
		$key    = self::issue_key(
			array( 'id' => $issue['post_id'] ?? 0 ),
			array( 'category' => $issue['category'] ?? '', 'code' => $issue['code'] ?? '' )
		);
		$now = gmdate( 'c' );

		// Concurrent upserts to the SAME key (two requests advising the
		// same post at once) can race — read, merge, write is not atomic.
		// The loser's write still lands (seen_count/last_seen_at just
		// reflect whichever call wrote last), so no advisory is silently
		// dropped; the only cost is an undercounted seen_count on a rare
		// double-flag, an accepted, minor imprecision for a "how many
		// times has this been flagged" counter rather than a correctness
		// boundary (#957 adversarial review round 4 NIT-4).
		$record = isset( $issues[ $key ] ) && is_array( $issues[ $key ] ) ? $issues[ $key ] : array(
			'id'                => $key,
			'first_seen_at'     => $now,
			'first_seen_run_id' => '',
			'seen_count'        => 0,
		);

		$record = array_merge(
			$record,
			array(
				'status'            => 'open',
				'last_seen_at'      => $now,
				'last_seen_run_id'  => '',
				'seen_count'        => (int) ( $record['seen_count'] ?? 0 ) + 1,
				'post_id'           => (int) ( $issue['post_id'] ?? 0 ),
				'title'             => sanitize_text_field( (string) ( $issue['title'] ?? '' ) ),
				'type'              => sanitize_key( (string) ( $issue['type'] ?? '' ) ),
				'url'               => esc_url_raw( (string) ( $issue['url'] ?? '' ) ),
				'category'          => sanitize_key( (string) ( $issue['category'] ?? '' ) ),
				'code'              => sanitize_key( (string) ( $issue['code'] ?? '' ) ),
				'representation'    => sanitize_key( (string) ( $issue['representation'] ?? '' ) ),
				'severity'          => sanitize_key( (string) ( $issue['severity'] ?? '' ) ),
				'message'           => sanitize_text_field( (string) ( $issue['message'] ?? '' ) ),
				'recommendation'    => sanitize_text_field( (string) ( $issue['recommendation'] ?? '' ) ),
				'approval_required' => ! empty( $issue['approval_required'] ),
				'priority_score'    => self::priority_score( $issue ),
			)
		);
		unset( $record['resolved_at'], $record['resolved_run_id'] );

		$issues[ $key ] = $record;
		self::save_guardrail_issues( $issues );

		return $key;
	}

	/**
	 * Mark ONE guardrail advisory resolved, IF it is currently open (#957
	 * adversarial review round 5 NIT-4: "guardrail issues resolve" — a
	 * later advisory check on the same post that scores below the
	 * threshold marks its open duplicate_edit issue resolved, so Control
	 * Room's list reflects the CURRENT state of the post, not every
	 * finding it has ever had). Uses the same (post_id, category, code)
	 * key derivation upsert_issue() writes with, so this resolves exactly
	 * the record a matching upsert_issue() call would update.
	 *
	 * When $representation is given, resolves ONLY if it matches the
	 * representation STORED on the open issue (#957 adversarial review
	 * round 6 NIT-1: "resolve a duplicate_edit issue only when the SAME
	 * representation that raised it is re-checked") — a published post
	 * can be flagged by its Elementor data and separately have an
	 * unrelated, genuinely clean post_content edit; that clean
	 * post_content check must not resolve an issue the Elementor side
	 * raised, since the Elementor content that caused it was never
	 * re-examined at all.
	 *
	 * @param int    $post_id        Post ID.
	 * @param string $category       Issue category.
	 * @param string $code           Issue code.
	 * @param string $representation 'post_content' or 'elementor' — the
	 *                               representation THIS check just ran
	 *                               against. Empty string matches any
	 *                               (back-compat for a caller that does not
	 *                               track representations at all).
	 * @return bool True if an open, representation-matching issue was found and resolved.
	 */
	public static function resolve_issue_if_open( $post_id, $category, $code, $representation = '' ) {
		$issues = self::get_guardrail_issues();
		$key    = self::issue_key(
			array( 'id' => $post_id ),
			array( 'category' => $category, 'code' => $code )
		);

		if ( ! isset( $issues[ $key ] ) || 'open' !== ( $issues[ $key ]['status'] ?? '' ) ) {
			return false;
		}

		if ( '' !== $representation && ( $issues[ $key ]['representation'] ?? '' ) !== $representation ) {
			return false; // A DIFFERENT representation raised this — not this check's to resolve.
		}

		$issues[ $key ]['status']           = 'resolved';
		$issues[ $key ]['resolved_at']       = gmdate( 'c' );
		$issues[ $key ]['resolved_run_id']   = '';
		self::save_guardrail_issues( $issues );

		return true;
	}

	/**
	 * Store an audit run and normalize issue records.
	 *
	 * @param array $audit Audit payload.
	 * @param array $args  Store metadata.
	 * @return array Stored run summary.
	 */
	public static function store_run( $audit, $args = array() ) {
		$run_id = self::generate_run_id();
		$now    = gmdate( 'c' );
		$urls   = isset( $audit['urls'] ) && is_array( $audit['urls'] ) ? $audit['urls'] : array();

		$run = array(
			'id'             => $run_id,
			'created_at'     => $now,
			'post_types'     => isset( $args['post_types'] ) && is_array( $args['post_types'] ) ? array_values( array_map( 'sanitize_key', $args['post_types'] ) ) : array(),
			'limit'          => isset( $args['limit'] ) ? (int) $args['limit'] : 0,
			'include_drafts' => ! empty( $args['include_drafts'] ),
			'summary'        => isset( $audit['summary'] ) && is_array( $audit['summary'] ) ? $audit['summary'] : array(),
			'category_counts' => isset( $audit['category_counts'] ) && is_array( $audit['category_counts'] ) ? $audit['category_counts'] : array(),
			'top_issue_codes' => isset( $audit['top_issue_codes'] ) && is_array( $audit['top_issue_codes'] ) ? $audit['top_issue_codes'] : array(),
		);

		self::upsert_run( $run );
		$issue_count = self::store_issues_for_run( $run_id, $urls, $now );

		$stored = array(
			'id'          => $run_id,
			'created_at'  => $now,
			'issue_count' => $issue_count,
		);

		if ( class_exists( 'Mcpwp_Event_Store' ) ) {
			Mcpwp_Event_Store::emit(
				'seo.audit_completed',
				array(
					'run'             => $stored,
					'summary'         => $run['summary'],
					'category_counts' => $run['category_counts'],
					'top_issue_codes' => $run['top_issue_codes'],
				),
				array(
					'resource'           => array(
						'type' => 'seo_audit',
						'id'   => $run_id,
					),
					'risk_level'         => ! empty( $run['summary']['error_count'] ) ? 'high' : ( ! empty( $run['summary']['warning_count'] ) ? 'medium' : 'low' ),
					'seo_state'          => isset( $run['summary']['status'] ) ? sanitize_key( (string) $run['summary']['status'] ) : '',
					'recommended_action' => __( 'Review stored SEO issues.', 'mcpwp' ),
				)
			);
		}

		return $stored;
	}

	/**
	 * List stored SEO issues.
	 *
	 * @param array $args Filters.
	 * @return array Result.
	 */
	public static function list_issues( $args = array() ) {
		// Merges the SEO-audit-owned store with the separately-capped
		// guardrail-advisory store (#957 adversarial review round 4
		// WARN-3) — Control Room's open-issues view shows both together,
		// even though store_issues_for_run() can only ever resolve the
		// former.
		$issues = array_values( array_merge( self::get_issues(), self::get_guardrail_issues() ) );
		$status   = isset( $args['status'] ) ? sanitize_key( (string) $args['status'] ) : '';
		$severity = isset( $args['severity'] ) ? sanitize_key( (string) $args['severity'] ) : '';
		$category = isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : '';
		$run_id   = isset( $args['run_id'] ) ? sanitize_key( (string) $args['run_id'] ) : '';
		$post_id  = isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0;
		$limit    = isset( $args['limit'] ) ? min( 200, max( 1, absint( $args['limit'] ) ) ) : 50;

		// Fail-closed chokepoint. Every read of stored issues flows through here,
		// so the non-public clamp is the DEFAULT, not an opt-in a consumer can
		// forget (prior passes left /site-state, /signals, and /seo/autofix-plan
		// reading the un-clamped set). An unprivileged caller must not see stored
		// issues that carry a draft/private post's title/url — a run stored by a
		// write key with include_drafts persists those. Unless the caller proves
		// it may see non-public content ($args['reveal_non_public'] === true), any
		// issue that REFERENCES a non-public (or missing) post is dropped. Issues
		// with no post reference (post_id <= 0, i.e. site-level) are kept — they
		// carry no hidden post title/url. Kept BEFORE summarize_issues() so the
		// summary counts match the visible set.
		$reveal_non_public = ! empty( $args['reveal_non_public'] );

		// Caller-category clamp (#1030 round-4 WARN-1): an issue carries its
		// post's title and url, so an issue about a post whose type the
		// caller's category cannot read (a product for a key without
		// woocommerce) is dropped here too — before summarize_issues(), so
		// the counts describe only what the caller receives. The REST layer
		// supplies the predicate; with none, nothing is dropped for it.
		$post_readable = isset( $args['post_readable'] ) && is_callable( $args['post_readable'] ) ? $args['post_readable'] : null;

		$issues = array_values(
			array_filter(
				$issues,
				function ( $issue ) use ( $status, $severity, $category, $run_id, $post_id, $reveal_non_public, $post_readable ) {
					if ( ! $reveal_non_public
						&& (int) ( $issue['post_id'] ?? 0 ) > 0
						&& ! self::issue_post_is_public( $issue ) ) {
						return false;
					}
					if ( null !== $post_readable
						&& (int) ( $issue['post_id'] ?? 0 ) > 0
						&& ! call_user_func( $post_readable, (int) $issue['post_id'] ) ) {
						return false;
					}
					if ( '' !== $status && ( $issue['status'] ?? '' ) !== $status ) {
						return false;
					}
					if ( '' !== $severity && ( $issue['severity'] ?? '' ) !== $severity ) {
						return false;
					}
					if ( '' !== $category && ( $issue['category'] ?? '' ) !== $category ) {
						return false;
					}
					if ( '' !== $run_id && ( $issue['last_seen_run_id'] ?? '' ) !== $run_id && ( $issue['first_seen_run_id'] ?? '' ) !== $run_id ) {
						return false;
					}
					if ( $post_id > 0 && (int) ( $issue['post_id'] ?? 0 ) !== $post_id ) {
						return false;
					}
					return true;
				}
			)
		);

		usort(
			$issues,
			function ( $a, $b ) {
				$score_a = (int) ( $a['priority_score'] ?? 0 );
				$score_b = (int) ( $b['priority_score'] ?? 0 );
				if ( $score_a === $score_b ) {
					return strcmp( $b['last_seen_at'] ?? '', $a['last_seen_at'] ?? '' );
				}
				return $score_b <=> $score_a;
			}
		);

		return array(
			'summary' => self::summarize_issues( $issues ),
			'issues'  => array_slice( $issues, 0, $limit ),
		);
	}

	/**
	 * Whether a stored issue's referenced post is currently publicly viewable.
	 *
	 * Used to hide draft/private issues from unprivileged readers. Resolves the
	 * live post status (so a post unpublished after the run is also hidden) and
	 * fails closed: a missing post or unregistered status is treated as
	 * non-public, since the stored title/url may still describe hidden content.
	 *
	 * @param array $issue Stored issue record.
	 * @return bool True when the post is public.
	 */
	private static function issue_post_is_public( $issue ) {
		$post_id = (int) ( $issue['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		$status_obj = get_post_status_object( (string) $post->post_status );
		return $status_obj && ! empty( $status_obj->public );
	}

	/**
	 * Store issues for one run.
	 *
	 * @param string $run_id Run ID.
	 * @param array  $urls   Audit URL rows.
	 * @param string $now    Timestamp.
	 * @return int Stored issue count for the run.
	 */
	private static function store_issues_for_run( $run_id, $urls, $now ) {
		$issues           = self::get_issues();
		$current_keys     = array();
		$current_post_ids = array();
		$count            = 0;

		foreach ( $urls as $url_row ) {
			$post_id = (int) ( $url_row['id'] ?? 0 );
			if ( $post_id > 0 ) {
				$current_post_ids[ $post_id ] = true;
			}

			$row_issues = isset( $url_row['top_issues'] ) && is_array( $url_row['top_issues'] ) ? $url_row['top_issues'] : array();
			foreach ( $row_issues as $issue ) {
				$key = self::issue_key( $url_row, $issue );
				$current_keys[ $key ] = true;
				$count++;

				$record = isset( $issues[ $key ] ) && is_array( $issues[ $key ] ) ? $issues[ $key ] : array(
					'id'                => $key,
					'first_seen_at'     => $now,
					'first_seen_run_id' => $run_id,
					'seen_count'        => 0,
				);

				$record = array_merge(
					$record,
					array(
						'status'           => 'open',
						'last_seen_at'     => $now,
						'last_seen_run_id' => $run_id,
						'seen_count'       => (int) ( $record['seen_count'] ?? 0 ) + 1,
						'post_id'          => (int) ( $url_row['id'] ?? 0 ),
						'title'            => sanitize_text_field( (string) ( $url_row['title'] ?? '' ) ),
						'type'             => sanitize_key( (string) ( $url_row['type'] ?? '' ) ),
						'url'              => esc_url_raw( (string) ( $url_row['url'] ?? '' ) ),
						'category'         => sanitize_key( (string) ( $issue['category'] ?? '' ) ),
						'code'             => sanitize_key( (string) ( $issue['code'] ?? '' ) ),
						'severity'         => sanitize_key( (string) ( $issue['severity'] ?? '' ) ),
						'message'          => sanitize_text_field( (string) ( $issue['message'] ?? '' ) ),
						'recommendation'   => sanitize_text_field( (string) ( $issue['recommendation'] ?? '' ) ),
						'approval_required' => ! empty( $issue['approval_required'] ),
						'priority_score'   => self::priority_score( $issue ),
					)
				);

				unset( $record['resolved_at'], $record['resolved_run_id'] );
				$issues[ $key ] = $record;
			}
		}

		foreach ( $issues as $key => $issue ) {
			$issue_post_id = (int) ( $issue['post_id'] ?? 0 );
			if ( 'open' === ( $issue['status'] ?? '' ) && isset( $current_post_ids[ $issue_post_id ] ) && ! isset( $current_keys[ $key ] ) ) {
				$issues[ $key ]['status']          = 'resolved';
				$issues[ $key ]['resolved_at']     = $now;
				$issues[ $key ]['resolved_run_id'] = $run_id;
			}
		}

		self::save_issues( $issues );
		return $count;
	}

	/**
	 * Summarize issues.
	 *
	 * @param array $issues Issues.
	 * @return array Summary.
	 */
	private static function summarize_issues( $issues ) {
		$summary = array(
			'total'    => count( $issues ),
			'open'     => 0,
			'resolved' => 0,
			'error'    => 0,
			'warning'  => 0,
			'info'     => 0,
		);

		foreach ( $issues as $issue ) {
			$status = $issue['status'] ?? '';
			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}
			$severity = $issue['severity'] ?? '';
			if ( isset( $summary[ $severity ] ) ) {
				$summary[ $severity ]++;
			}
		}

		return $summary;
	}

	/**
	 * Build issue key.
	 *
	 * @param array $url_row URL row.
	 * @param array $issue   Issue.
	 * @return string Issue key.
	 */
	private static function issue_key( $url_row, $issue ) {
		$raw = implode(
			'|',
			array(
				(int) ( $url_row['id'] ?? 0 ),
				sanitize_key( (string) ( $issue['category'] ?? '' ) ),
				sanitize_key( (string) ( $issue['code'] ?? '' ) ),
			)
		);

		return 'sei_' . substr( hash( 'sha256', $raw ), 0, 24 );
	}

	/**
	 * Priority score for an issue.
	 *
	 * @param array $issue Issue.
	 * @return int Score.
	 */
	private static function priority_score( $issue ) {
		$severity = $issue['severity'] ?? '';
		if ( 'error' === $severity ) {
			return 100;
		}
		if ( 'warning' === $severity ) {
			return 50;
		}
		return 10;
	}

	/**
	 * Upsert one run.
	 *
	 * @param array $run Run.
	 * @return void
	 */
	private static function upsert_run( $run ) {
		$runs = self::get_runs();
		$runs[ $run['id'] ] = $run;
		uasort(
			$runs,
			function ( $a, $b ) {
				return strcmp( $b['created_at'] ?? '', $a['created_at'] ?? '' );
			}
		);
		update_option( self::RUNS_OPTION, array_slice( $runs, 0, self::MAX_RUNS, true ), false );
	}

	/**
	 * Save issue records.
	 *
	 * @param array $issues Issues.
	 * @return void
	 */
	private static function save_issues( $issues ) {
		uasort(
			$issues,
			function ( $a, $b ) {
				return strcmp( $b['last_seen_at'] ?? '', $a['last_seen_at'] ?? '' );
			}
		);
		update_option( self::ISSUES_OPTION, array_slice( $issues, 0, self::MAX_ISSUES, true ), false );
	}

	/**
	 * Save guardrail advisory records — MAX_GUARDRAIL_ISSUES, a separate
	 * cap from save_issues()'s MAX_ISSUES (#957 adversarial review round 4
	 * WARN-3), so a flood of advisories can only ever evict OTHER
	 * advisories, never a real SEO issue.
	 *
	 * @param array $issues Guardrail advisory records.
	 * @return void
	 */
	private static function save_guardrail_issues( $issues ) {
		uasort(
			$issues,
			function ( $a, $b ) {
				return strcmp( $b['last_seen_at'] ?? '', $a['last_seen_at'] ?? '' );
			}
		);
		update_option( self::GUARDRAIL_ISSUES_OPTION, array_slice( $issues, 0, self::MAX_GUARDRAIL_ISSUES, true ), false );
	}

	/**
	 * Get stored runs.
	 *
	 * @return array Runs.
	 */
	private static function get_runs() {
		$runs = get_option( self::RUNS_OPTION, array() );
		return is_array( $runs ) ? $runs : array();
	}

	/**
	 * Get stored issues (the SEO-audit-owned store only — see
	 * get_guardrail_issues() for the separate advisory store).
	 *
	 * @return array Issues.
	 */
	private static function get_issues() {
		$issues = get_option( self::ISSUES_OPTION, array() );
		return is_array( $issues ) ? $issues : array();
	}

	/**
	 * Get stored guardrail advisories — see GUARDRAIL_ISSUES_OPTION's own
	 * docblock for why this is a separate store from get_issues(). Sweeps
	 * expired OPEN advisories to 'resolved' on every read (#957 adversarial
	 * review round 5 NIT-4: "expire guardrail issues after 30 days") — a
	 * lazy, self-healing sweep rather than a cron: nothing here runs on a
	 * timer, matching this plugin's existing "every lookup is an explicit
	 * action" convention (see CLAUDE.md's SEO Market Data section).
	 *
	 * @return array Guardrail advisory records.
	 */
	private static function get_guardrail_issues() {
		$issues = get_option( self::GUARDRAIL_ISSUES_OPTION, array() );
		$issues = is_array( $issues ) ? $issues : array();
		return self::expire_stale_guardrail_issues( $issues );
	}

	/**
	 * How long an OPEN guardrail advisory stays open with no re-flag
	 * before it is swept to 'resolved' on its own (#957 adversarial review
	 * round 5 NIT-4). A post nobody has re-checked in this long is either
	 * genuinely fixed or simply not being worked on — either way, it
	 * should not sit in Control Room's open list forever.
	 *
	 * @var int
	 */
	const GUARDRAIL_ISSUE_EXPIRY_DAYS = 30;

	/**
	 * Resolve any OPEN guardrail advisory whose last_seen_at is older than
	 * GUARDRAIL_ISSUE_EXPIRY_DAYS, persisting the change if anything
	 * actually expired.
	 *
	 * @param array $issues Guardrail advisory records.
	 * @return array The same records, with any expired ones now 'resolved'.
	 */
	private static function expire_stale_guardrail_issues( array $issues ) {
		$cutoff  = time() - ( self::GUARDRAIL_ISSUE_EXPIRY_DAYS * DAY_IN_SECONDS );
		$changed = false;

		foreach ( $issues as $key => $issue ) {
			if ( 'open' !== ( $issue['status'] ?? '' ) ) {
				continue;
			}
			$last_seen = isset( $issue['last_seen_at'] ) ? strtotime( (string) $issue['last_seen_at'] ) : false;
			if ( false !== $last_seen && $last_seen < $cutoff ) {
				$issues[ $key ]['status']         = 'resolved';
				$issues[ $key ]['resolved_at']     = gmdate( 'c' );
				$issues[ $key ]['resolved_run_id'] = '';
				$changed                           = true;
			}
		}

		if ( $changed ) {
			self::save_guardrail_issues( $issues );
		}

		return $issues;
	}

	/**
	 * Generate run ID.
	 *
	 * @return string Run ID.
	 */
	private static function generate_run_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'sea_' . str_replace( '-', '', wp_generate_uuid4() );
		}
		return 'sea_' . bin2hex( random_bytes( 12 ) );
	}
}
