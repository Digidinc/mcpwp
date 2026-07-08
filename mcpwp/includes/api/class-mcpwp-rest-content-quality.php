<?php
/**
 * Content Quality REST Controller
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content quality audit REST endpoints.
 */
class Mcpwp_REST_Content_Quality extends Mcpwp_REST_API {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_routes();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Content quality and AI-search citation readiness audit.
		register_rest_route(
			$this->namespace,
			'/seo/content-quality/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'audit_content_quality' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Post or page ID to audit.', 'mumcp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Audit content quality and AI-search citation readiness.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function audit_content_quality( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( 'not_found', 'Post not found.', 404 );
		}

		$this->log_activity( 'audit_content_quality', $request, array( 'post_id' => $post_id ) );

		$content          = (string) $post->post_content;
		$text             = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $content ) ) );
		$word_count       = str_word_count( $text );
		$headings         = $this->extract_heading_texts( $content );
		$question_count   = $this->count_question_signals( $text, $headings );
		$entity_names     = $this->extract_content_entity_names( $text );
		$external_links   = $this->count_external_reference_links( $content );
		$trust_signals    = $this->detect_content_trust_signals( $text );
		$modified_ts      = (int) get_post_modified_time( 'U', true, $post );
		$freshness_days   = $modified_ts > 0 ? max( 0, floor( ( time() - $modified_ts ) / DAY_IN_SECONDS ) ) : null;
		$issues           = array();

		if ( $word_count < 180 ) {
			$issues[] = $this->make_content_quality_issue( 'low_answer_depth', 'warning', __( 'Content may not have enough depth to answer the topic.', 'mumcp' ), __( 'Add clear, useful detail that satisfies the page intent before publishing.', 'mumcp' ) );
		}

		if ( ! $this->has_summary_intro( $text ) ) {
			$issues[] = $this->make_content_quality_issue( 'missing_summary_intro', 'info', __( 'The page does not appear to open with a concise summary.', 'mumcp' ), __( 'Add a short intro that states who the page is for and what it answers.', 'mumcp' ) );
		}

		if ( 0 === $question_count ) {
			$issues[] = $this->make_content_quality_issue( 'no_question_coverage', 'info', __( 'No explicit question coverage was detected.', 'mumcp' ), __( 'Add question-style headings or FAQ content only when it matches real user intent.', 'mumcp' ) );
		}

		if ( count( $entity_names ) < 3 ) {
			$issues[] = $this->make_content_quality_issue( 'low_entity_coverage', 'info', __( 'Few entity-like names were detected.', 'mumcp' ), __( 'Mention relevant products, people, organizations, places, standards, tools, or concepts naturally where useful.', 'mumcp' ) );
		}

		if ( null !== $freshness_days && $freshness_days > 365 ) {
			$issues[] = $this->make_content_quality_issue( 'stale_content', 'warning', __( 'Content has not been updated in over a year.', 'mumcp' ), __( 'Review facts, screenshots, product names, and links before relying on this page.', 'mumcp' ), array( 'freshness_days' => $freshness_days ) );
		}

		if ( 0 === count( $trust_signals ) ) {
			$issues[] = $this->make_content_quality_issue( 'missing_trust_signals', 'info', __( 'No obvious trust signals were detected.', 'mumcp' ), __( 'Add visible author, date, source, policy, contact, proof, or process details where appropriate.', 'mumcp' ) );
		}

		if ( 0 === $external_links ) {
			$issues[] = $this->make_content_quality_issue( 'no_reference_hints', 'info', __( 'No external reference links were detected.', 'mumcp' ), __( 'Use references only when they help users verify claims; do not invent citations.', 'mumcp' ) );
		}

		$score   = $this->calculate_content_quality_score( $word_count, $question_count, count( $entity_names ), count( $trust_signals ), $external_links, $freshness_days );
		$summary = $this->summarize_content_quality_issues( $issues, $score );

		return $this->success_response(
			array(
				'post'       => array(
					'id'     => $post_id,
					'title'  => get_the_title( $post ),
					'type'   => $post->post_type,
					'status' => $post->post_status,
					'url'    => get_permalink( $post ),
				),
				'summary'    => $summary,
				'signals'    => array(
					'word_count'          => $word_count,
					'heading_count'       => count( $headings ),
					'question_count'      => $question_count,
					'entity_name_count'   => count( $entity_names ),
					'entity_names'        => array_slice( $entity_names, 0, 12 ),
					'external_references' => $external_links,
					'trust_signals'       => $trust_signals,
					'freshness_days'      => $freshness_days,
				),
				'issues'     => $issues,
				'workflow'   => array(
					'read'  => 'Use before publishing answer-oriented or AI-search-sensitive content.',
					'fix'   => 'Improve visible content through approval-first Gutenberg edits; do not invent facts, entities, or citations.',
					'guard' => 'This endpoint is read-only and does not mutate content.',
				),
			)
		);
	}

	/**
	 * Build internal content graph data.
	 *
	 * @param array $post_types     Post types.
	 * @param int   $limit          Maximum nodes.
	 * @param bool  $include_drafts Include drafts/private posts.
	 * @return array Graph data.
	 */

	// --- Private helpers ---

	/**
	 * Create a normalized content quality issue.
	 *
	 * @param string $code           Issue code.
	 * @param string $severity       Severity.
	 * @param string $message        Message.
	 * @param string $recommendation Recommendation.
	 * @param array  $extra          Extra fields.
	 * @return array Issue.
	 */
	protected function make_content_quality_issue( $code, $severity, $message, $recommendation, $extra = array() ) {
		return array_merge(
			array(
				'code'              => $code,
				'severity'          => $severity,
				'message'           => $message,
				'recommendation'    => $recommendation,
				'approval_required' => in_array( $severity, array( 'error', 'warning' ), true ),
			),
			$extra
		);
	}

	/**
	 * Summarize content quality issues.
	 *
	 * @param array $issues Issues.
	 * @param int   $score  Quality score.
	 * @return array Summary.
	 */
	protected function summarize_content_quality_issues( $issues, $score ) {
		$error_count   = 0;
		$warning_count = 0;
		$info_count    = 0;

		foreach ( $issues as $issue ) {
			if ( 'error' === $issue['severity'] ) {
				$error_count++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$warning_count++;
			} elseif ( 'info' === $issue['severity'] ) {
				$info_count++;
			}
		}

		return array(
			'status'        => 0 === $error_count ? ( $warning_count > 0 || $score < 70 ? 'warn' : 'pass' ) : 'fail',
			'quality_score' => $score,
			'issue_count'   => count( $issues ),
			'error_count'   => $error_count,
			'warning_count' => $warning_count,
			'info_count'    => $info_count,
		);
	}

	/**
	 * Count question signals from headings and body text.
	 *
	 * @param string $text     Plain text.
	 * @param array  $headings Heading records.
	 * @return int Question count.
	 */
	protected function count_question_signals( $text, $headings ) {
		$count = substr_count( $text, '?' );

		foreach ( $headings as $heading ) {
			if ( preg_match( '/^(what|why|how|when|where|who|can|should|is|are|does|do)\b/i', (string) $heading['text'] ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Detect whether content opens with a useful summary.
	 *
	 * @param string $text Plain text.
	 * @return bool True when summary-like.
	 */
	protected function has_summary_intro( $text ) {
		$words = preg_split( '/\s+/', trim( $text ) );
		if ( ! is_array( $words ) || count( $words ) < 18 ) {
			return false;
		}

		$intro = strtolower( implode( ' ', array_slice( $words, 0, 45 ) ) );
		return (bool) preg_match( '/\b(this|guide|page|article|post|overview|learn|explains|covers|helps|shows)\b/', $intro );
	}

	/**
	 * Extract simple entity-like names.
	 *
	 * @param string $text Plain text.
	 * @return array Entity-like names.
	 */
	protected function extract_content_entity_names( $text ) {
		$entities = array();

		if ( preg_match_all( '/\b([A-Z][A-Za-z0-9]+(?:\s+[A-Z][A-Za-z0-9]+){0,3})\b/', $text, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$match = trim( $match );
				if ( strlen( $match ) < 3 || in_array( strtolower( $match ), array( 'the', 'this', 'that', 'and', 'or' ), true ) ) {
					continue;
				}
				$entities[] = $match;
			}
		}

		return array_values( array_unique( array_slice( $entities, 0, 40 ) ) );
	}

	/**
	 * Count external reference links.
	 *
	 * @param string $content Content.
	 * @return int External link count.
	 */
	protected function count_external_reference_links( $content ) {
		$count     = 0;
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );

		foreach ( $this->extract_raw_anchor_links( $content ) as $link ) {
			$href = trim( (string) $link['href'] );
			if ( ! wp_http_validate_url( $href ) ) {
				continue;
			}

			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( $host && $home_host && strtolower( $host ) !== strtolower( $home_host ) ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Detect trust-signal keywords.
	 *
	 * @param string $text Plain text.
	 * @return array Signals.
	 */
	protected function detect_content_trust_signals( $text ) {
		$signals = array();
		$map     = array(
			'author'  => '/\b(author|written by|reviewed by|editor)\b/i',
			'date'    => '/\b(updated|published|last updated|reviewed on)\b/i',
			'contact' => '/\b(contact|support|email|phone|address)\b/i',
			'proof'   => '/\b(case study|testimonial|example|source|research|data|study)\b/i',
			'policy'  => '/\b(policy|privacy|terms|refund|guarantee)\b/i',
		);

		foreach ( $map as $signal => $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				$signals[] = $signal;
			}
		}

		return $signals;
	}

	/**
	 * Calculate a simple content quality score.
	 *
	 * @param int      $word_count     Word count.
	 * @param int      $question_count Question count.
	 * @param int      $entity_count   Entity count.
	 * @param int      $trust_count    Trust signal count.
	 * @param int      $external_links External link count.
	 * @param int|null $freshness_days Freshness days.
	 * @return int Score.
	 */
	protected function calculate_content_quality_score( $word_count, $question_count, $entity_count, $trust_count, $external_links, $freshness_days ) {
		$score = 30;
		$score += min( 30, (int) floor( $word_count / 20 ) );
		$score += min( 10, $question_count * 5 );
		$score += min( 15, $entity_count * 3 );
		$score += min( 10, $trust_count * 5 );
		$score += min( 5, $external_links * 2 );

		if ( null !== $freshness_days && $freshness_days > 365 ) {
			$score -= 10;
		}

		return max( 0, min( 100, $score ) );
	}

}
