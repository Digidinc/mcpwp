<?php
/**
 * Approval-safe SEO autofix planning.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds deterministic SEO fix plans from stored SEO issues.
 */
class Mcpwp_SEO_Autofix {

	/**
	 * Planner schema version.
	 */
	const SCHEMA_VERSION = '2026-05-20';

	/**
	 * Build an approval-safe fix plan from stored SEO issues.
	 *
	 * @param array $args Planner filters.
	 * @return array
	 */
	public static function get_plan( $args = array() ) {
		// Fail-closed: the plan surfaces each issue's title/url in actions[]. The
		// reveal flag is threaded from the REST handler; default false keeps a
		// forgetful caller safe. The model excludes non-public issues by default.
		$reveal_non_public = ! empty( $args['reveal_non_public'] );

		$issue_result = class_exists( 'Mcpwp_SEO_Audit_Store' ) ? Mcpwp_SEO_Audit_Store::list_issues(
			array(
				'status'   => 'open',
				'severity' => isset( $args['severity'] ) ? sanitize_key( (string) $args['severity'] ) : '',
				'category' => isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : '',
				'post_id'  => isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0,
				'run_id'   => isset( $args['run_id'] ) ? sanitize_key( (string) $args['run_id'] ) : '',
				'limit'    => isset( $args['limit'] ) ? absint( $args['limit'] ) : 50,
				'reveal_non_public' => $reveal_non_public,
				'category_allowed'  => $args['category_allowed'] ?? null,
			)
		) : array(
			'summary' => array(),
			'issues'  => array(),
		);

		$issue_id = isset( $args['issue_id'] ) ? sanitize_key( (string) $args['issue_id'] ) : '';
		$issues   = isset( $issue_result['issues'] ) && is_array( $issue_result['issues'] ) ? $issue_result['issues'] : array();
		if ( '' !== $issue_id ) {
			$issues = array_values(
				array_filter(
					$issues,
					static function ( $issue ) use ( $issue_id ) {
						return $issue_id === (string) ( $issue['id'] ?? '' );
					}
				)
			);
		}

		$actions = array();
		foreach ( $issues as $issue ) {
			$actions[] = self::action_for_issue( $issue );
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'summary'        => self::summarize_actions( $actions, $issues ),
			'filters'        => array(
				'status'   => 'open',
				'severity' => isset( $args['severity'] ) ? sanitize_key( (string) $args['severity'] ) : '',
				'category' => isset( $args['category'] ) ? sanitize_key( (string) $args['category'] ) : '',
				'post_id'  => isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0,
				'run_id'   => isset( $args['run_id'] ) ? sanitize_key( (string) $args['run_id'] ) : '',
				'issue_id' => $issue_id,
				'limit'    => isset( $args['limit'] ) ? min( 200, max( 1, absint( $args['limit'] ) ) ) : 50,
			),
			'actions'        => $actions,
			'workflow'       => array(
				'read'     => 'Run wp_seo_audit_site with store=true, then call this planner before preparing changes.',
				'prepare'  => 'Use the action tool and playbook to draft the smallest possible fix.',
				'approve'  => 'Any publish-facing change must pass through an approval request or human action.',
				'validate' => 'After approval apply, rerun wp_validate_seo_readiness or wp_seo_audit_site with store=true.',
				'guard'    => 'This endpoint is read-only. It never updates content, media, SEO metadata, robots, sitemap, or schema.',
			),
		);
	}

	/**
	 * Convert a stored SEO issue to a deterministic action.
	 *
	 * @param array $issue Stored issue.
	 * @return array
	 */
	private static function action_for_issue( $issue ) {
		$code    = sanitize_key( (string) ( $issue['code'] ?? '' ) );
		$post_id = absint( $issue['post_id'] ?? 0 );
		$base    = array(
			'issue_id'          => (string) ( $issue['id'] ?? '' ),
			'post_id'           => $post_id,
			'title'             => sanitize_text_field( (string) ( $issue['title'] ?? '' ) ),
			'url'               => esc_url_raw( (string) ( $issue['url'] ?? '' ) ),
			'code'              => $code,
			'category'          => sanitize_key( (string) ( $issue['category'] ?? '' ) ),
			'severity'          => sanitize_key( (string) ( $issue['severity'] ?? '' ) ),
			'message'           => sanitize_text_field( (string) ( $issue['message'] ?? '' ) ),
			'recommendation'    => sanitize_text_field( (string) ( $issue['recommendation'] ?? '' ) ),
			'approval_required' => true,
			'can_auto_prepare'  => false,
			'can_auto_apply'    => false,
			'guardrails'        => array(
				'Do not invent facts, entities, claims, schema, citations, or keyword targets.',
				'Do not overwrite plugin SEO metadata without a human-approved request.',
				'Keep fixes scoped to the stored issue and validate after approval apply.',
			),
		);

		$strategies = self::strategies();
		if ( isset( $strategies[ $code ] ) ) {
			return array_merge( $base, $strategies[ $code ] );
		}

		return array_merge(
			$base,
			array(
				'strategy'         => 'manual_review',
				'tool'             => 'wp_get_seo_issues',
				'playbook'         => 'seo_audit_triage',
				'can_auto_prepare' => false,
				'next_step'        => __( 'Review this issue manually and choose the matching approval-safe playbook.', 'mcpwp' ),
				'reason'           => __( 'No deterministic autofix strategy exists for this issue code yet.', 'mcpwp' ),
			)
		);
	}

	/**
	 * Supported fix strategies.
	 *
	 * @return array
	 */
	private static function strategies() {
		return array(
			'missing_meta_description' => array(
				'strategy'         => 'seo_meta_description',
				'tool'             => 'wp_validate_seo_readiness',
				'playbook'         => 'seo_audit_triage',
				'targets'          => array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description' ),
				'can_auto_prepare' => true,
				'next_step'        => __( 'Draft a concise meta description from visible page content, then request human approval before writing SEO metadata.', 'mcpwp' ),
				'reason'           => __( 'SEO metadata changes affect search snippets and must remain approval-gated.', 'mcpwp' ),
			),
			'meta_description_length' => array(
				'strategy'         => 'seo_meta_description',
				'tool'             => 'wp_validate_seo_readiness',
				'playbook'         => 'seo_audit_triage',
				'targets'          => array( '_yoast_wpseo_metadesc', 'rank_math_description', '_aioseo_description' ),
				'can_auto_prepare' => true,
				'next_step'        => __( 'Prepare a replacement meta description that preserves the page promise and ask for approval before writing it.', 'mcpwp' ),
				'reason'           => __( 'Snippet copy is search-facing and should be approved before mutation.', 'mcpwp' ),
			),
			'missing_image_alt' => array(
				'strategy'         => 'media_alt_text',
				'tool'             => 'wp_audit_media_seo',
				'playbook'         => 'seo_audit_triage',
				'can_auto_prepare' => true,
				'next_step'        => __( 'Inspect the image in page context, draft factual alt text, and require human approval before media metadata changes.', 'mcpwp' ),
				'reason'           => __( 'Alt text must describe the actual image and visible context, not guessed keywords.', 'mcpwp' ),
			),
			'no_internal_outbound_links' => array(
				'strategy'         => 'internal_link_suggestion',
				'tool'             => 'wp_suggest_internal_links',
				'playbook'         => 'internal_link_improvement',
				'can_auto_prepare' => true,
				'next_step'        => __( 'Suggest a relevant internal link from the content graph, then use approval-first link insertion.', 'mcpwp' ),
				'reason'           => __( 'Internal links change editorial meaning and should be added through approval-first content edits.', 'mcpwp' ),
			),
			'orphan_content' => array(
				'strategy'         => 'internal_link_suggestion',
				'tool'             => 'wp_suggest_internal_links',
				'playbook'         => 'internal_link_improvement',
				'can_auto_prepare' => true,
				'next_step'        => __( 'Find relevant source pages in the content graph and propose inbound links through approval-first edits.', 'mcpwp' ),
				'reason'           => __( 'Orphan fixes require editorially relevant links, not automatic keyword stuffing.', 'mcpwp' ),
			),
			'thin_content' => array(
				'strategy'         => 'content_patch',
				'tool'             => 'wp_patch_block_section',
				'playbook'         => 'update_gutenberg_section',
				'can_auto_prepare' => true,
				'next_step'        => __( 'Prepare one Gutenberg section that adds useful, sourced substance and submit it as an approval request.', 'mcpwp' ),
				'reason'           => __( 'Content expansion affects page claims and must be reviewed before publication.', 'mcpwp' ),
			),
			'missing_h1' => array(
				'strategy'         => 'content_patch',
				'tool'             => 'wp_patch_block_section',
				'playbook'         => 'update_gutenberg_section',
				'can_auto_prepare' => true,
				'next_step'        => __( 'Prepare a native Heading block that matches the visible page topic and submit an approval request.', 'mcpwp' ),
				'reason'           => __( 'Primary headings are user-visible and must match the page intent.', 'mcpwp' ),
			),
			'heading_order_skip' => array(
				'strategy'         => 'content_patch',
				'tool'             => 'wp_patch_block_section',
				'playbook'         => 'update_gutenberg_section',
				'can_auto_prepare' => true,
				'next_step'        => __( 'Prepare the smallest heading-level correction in native blocks and submit it for approval.', 'mcpwp' ),
				'reason'           => __( 'Heading hierarchy changes visible structure and accessibility semantics.', 'mcpwp' ),
			),
			'multiple_h1' => array(
				'strategy'         => 'content_patch',
				'tool'             => 'wp_patch_block_section',
				'playbook'         => 'update_gutenberg_section',
				'can_auto_prepare' => true,
				'next_step'        => __( 'Prepare a minimal heading hierarchy correction and submit it for approval.', 'mcpwp' ),
				'reason'           => __( 'Heading hierarchy changes visible structure and accessibility semantics.', 'mcpwp' ),
			),
			'schema_hint_missing' => array(
				'strategy'         => 'structured_data_review',
				'tool'             => 'wp_validate_structured_data',
				'playbook'         => 'seo_audit_triage',
				'can_auto_prepare' => false,
				'next_step'        => __( 'Validate visible content and choose schema only when the page supports it.', 'mcpwp' ),
				'reason'           => __( 'Schema markup can create search-facing claims and should not be inferred automatically.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Summarize planned actions.
	 *
	 * @param array $actions Planned actions.
	 * @param array $issues  Inspected issues.
	 * @return array
	 */
	private static function summarize_actions( $actions, $issues ) {
		$summary = array(
			'issues_inspected' => count( $issues ),
			'actions'          => count( $actions ),
			'can_prepare'      => 0,
			'can_auto_apply'   => 0,
			'needs_approval'   => 0,
			'manual_review'    => 0,
			'by_strategy'      => array(),
		);

		foreach ( $actions as $action ) {
			if ( ! empty( $action['can_auto_prepare'] ) ) {
				$summary['can_prepare']++;
			}
			if ( ! empty( $action['can_auto_apply'] ) ) {
				$summary['can_auto_apply']++;
			}
			if ( ! empty( $action['approval_required'] ) ) {
				$summary['needs_approval']++;
			}
			if ( 'manual_review' === ( $action['strategy'] ?? '' ) || empty( $action['can_auto_prepare'] ) ) {
				$summary['manual_review']++;
			}
			$strategy = sanitize_key( (string) ( $action['strategy'] ?? 'unknown' ) );
			if ( ! isset( $summary['by_strategy'][ $strategy ] ) ) {
				$summary['by_strategy'][ $strategy ] = 0;
			}
			$summary['by_strategy'][ $strategy ]++;
		}

		return $summary;
	}
}
