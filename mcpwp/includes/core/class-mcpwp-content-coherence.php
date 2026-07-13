<?php
/**
 * Content coherence scoring and recommendations.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores whether the WordPress site is coherent for humans, search, and agents.
 */
class Mcpwp_Content_Coherence {

	/**
	 * Build a coherence report from the current site state.
	 *
	 * @param array $args Report args.
	 * @return array
	 */
	public static function get_report( $args = array() ) {
		$state = class_exists( 'Mcpwp_Site_State' ) ? Mcpwp_Site_State::get_snapshot( $args ) : array();
		$dimensions = array(
			'context'   => self::score_context( $state ),
			'graph'     => self::score_graph( $state ),
			'content'   => self::score_content( $state ),
			'seo'       => self::score_seo( $state ),
			'governance' => self::score_governance( $state ),
		);

		$total = 0;
		foreach ( $dimensions as $dimension ) {
			$total += (int) $dimension['score'];
		}

		$score = (int) round( $total / max( 1, count( $dimensions ) ) );

		return array(
			'schema_version'      => '2026-05-20',
			'generated_at'        => gmdate( 'c' ),
			'score'               => $score,
			'status'              => self::status_for_score( $score ),
			'dimensions'          => $dimensions,
			'recommended_actions' => self::recommendations( $dimensions, $state ),
			'workflow'            => array(
				'read'  => 'Use after wp_get_site_state to prioritize coherent content work.',
				'fix'   => 'Choose one recommendation and follow wp_get_agent_playbook before mutating content.',
				'guard' => 'This report is read-only and does not make ranking promises.',
			),
		);
	}

	/**
	 * Score context readiness.
	 *
	 * @param array $state Site state.
	 * @return array
	 */
	private static function score_context( $state ) {
		$configured = ! empty( $state['context']['configured'] );
		return self::dimension(
			$configured ? 100 : 55,
			$configured ? 'pass' : 'warn',
			$configured ? __( 'Site context is configured.', 'mumega-mcp' ) : __( 'Site context is missing.', 'mumega-mcp' ),
			$configured ? '' : __( 'Define audience, brand, design rules, and content boundaries.', 'mumega-mcp' )
		);
	}

	/**
	 * Score graph connection.
	 *
	 * @param array $state Site state.
	 * @return array
	 */
	private static function score_graph( $state ) {
		$graph         = isset( $state['graph'] ) && is_array( $state['graph'] ) ? $state['graph'] : array();
		$orphan_count  = (int) ( $graph['orphan_pages']['count'] ?? 0 );
		$inspected     = max( 1, (int) ( $graph['inspected_count'] ?? 0 ) );
		$orphan_ratio  = min( 1, $orphan_count / $inspected );
		$score         = max( 0, 100 - (int) round( $orphan_ratio * 70 ) );

		return self::dimension(
			$score,
			$score < 70 ? 'warn' : 'pass',
			sprintf(
				/* translators: %d: orphan page count */
				_n( '%d orphan page found.', '%d orphan pages found.', $orphan_count, 'mumega-mcp' ),
				$orphan_count
			),
			$orphan_count > 0 ? __( 'Connect orphan pages through menus or contextual internal links.', 'mumega-mcp' ) : ''
		);
	}

	/**
	 * Score content depth and freshness.
	 *
	 * @param array $state Site state.
	 * @return array
	 */
	private static function score_content( $state ) {
		$graph       = isset( $state['graph'] ) && is_array( $state['graph'] ) ? $state['graph'] : array();
		$thin_count  = (int) ( $graph['thin_content']['count'] ?? 0 );
		$stale_count = (int) ( $graph['stale_content']['count'] ?? 0 );
		$inspected   = max( 1, (int) ( $graph['inspected_count'] ?? 0 ) );
		$penalty     = min( 80, (int) round( ( ( $thin_count + $stale_count ) / $inspected ) * 80 ) );
		$score       = max( 0, 100 - $penalty );

		return self::dimension(
			$score,
			$score < 70 ? 'warn' : 'pass',
			sprintf(
				/* translators: 1: thin content count, 2: stale content count */
				__( '%1$d thin and %2$d stale content items found.', 'mumega-mcp' ),
				$thin_count,
				$stale_count
			),
			( $thin_count + $stale_count ) > 0 ? __( 'Improve thin pages and refresh stale search-facing content.', 'mumega-mcp' ) : ''
		);
	}

	/**
	 * Score SEO issue state.
	 *
	 * @param array $state Site state.
	 * @return array
	 */
	private static function score_seo( $state ) {
		$summary  = isset( $state['seo']['summary'] ) && is_array( $state['seo']['summary'] ) ? $state['seo']['summary'] : array();
		$errors   = (int) ( $summary['error'] ?? 0 );
		$warnings = (int) ( $summary['warning'] ?? 0 );
		$score    = max( 0, 100 - min( 100, ( $errors * 25 ) + ( $warnings * 8 ) ) );

		return self::dimension(
			$score,
			$errors > 0 ? 'fail' : ( $warnings > 0 ? 'warn' : 'pass' ),
			sprintf(
				/* translators: 1: error count, 2: warning count */
				__( '%1$d SEO errors and %2$d SEO warnings are stored.', 'mumega-mcp' ),
				$errors,
				$warnings
			),
			( $errors + $warnings ) > 0 ? __( 'Resolve stored SEO issues by severity before broad content expansion.', 'mumega-mcp' ) : ''
		);
	}

	/**
	 * Score governance and event risk.
	 *
	 * @param array $state Site state.
	 * @return array
	 */
	private static function score_governance( $state ) {
		$pending = (int) ( $state['approvals']['counts']['pending'] ?? 0 );
		$events  = isset( $state['events']['items'] ) && is_array( $state['events']['items'] ) ? $state['events']['items'] : array();
		$high    = 0;

		foreach ( $events as $event ) {
			if ( isset( $event['risk_level'] ) && 'high' === $event['risk_level'] ) {
				$high++;
			}
		}

		$score = max( 0, 100 - min( 100, ( $pending * 15 ) + ( $high * 20 ) ) );

		return self::dimension(
			$score,
			$score < 70 ? 'warn' : 'pass',
			sprintf(
				/* translators: 1: pending approval count, 2: high-risk event count */
				__( '%1$d pending approvals and %2$d high-risk recent events.', 'mumega-mcp' ),
				$pending,
				$high
			),
			( $pending + $high ) > 0 ? __( 'Clear pending approvals and review high-risk events before new mutations.', 'mumega-mcp' ) : ''
		);
	}

	/**
	 * Build one scored dimension.
	 *
	 * @param int    $score          Score.
	 * @param string $status         Status.
	 * @param string $evidence       Evidence.
	 * @param string $recommendation Recommendation.
	 * @return array
	 */
	private static function dimension( $score, $status, $evidence, $recommendation ) {
		return array(
			'score'          => max( 0, min( 100, (int) $score ) ),
			'status'         => sanitize_key( $status ),
			'evidence'       => sanitize_text_field( $evidence ),
			'recommendation' => sanitize_text_field( $recommendation ),
		);
	}

	/**
	 * Overall status for score.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	private static function status_for_score( $score ) {
		if ( $score < 60 ) {
			return 'critical';
		}
		if ( $score < 80 ) {
			return 'needs_attention';
		}
		return 'healthy';
	}

	/**
	 * Prioritized recommendations from dimension scores and site state.
	 *
	 * @param array $dimensions Dimensions.
	 * @param array $state      Site state.
	 * @return array
	 */
	private static function recommendations( $dimensions, $state ) {
		$items = array();

		foreach ( $dimensions as $code => $dimension ) {
			if ( empty( $dimension['recommendation'] ) ) {
				continue;
			}

			$items[] = array(
				'priority' => (int) $dimension['score'] < 60 ? 'high' : 'medium',
				'code'     => sanitize_key( $code ),
				'message'  => $dimension['recommendation'],
				'playbook' => self::playbook_for_dimension( $code ),
			);
		}

		if ( empty( $items ) && ! empty( $state['recommended_actions'] ) ) {
			$items[] = array(
				'priority' => 'low',
				'code'     => 'continue_supervised_work',
				'message'  => __( 'Choose the next low-risk supervised improvement from the site-state recommendations.', 'mumega-mcp' ),
				'playbook' => 'seo_audit_triage',
			);
		}

		return array_slice( $items, 0, 8 );
	}

	/**
	 * Map dimension to playbook.
	 *
	 * @param string $dimension Dimension code.
	 * @return string
	 */
	private static function playbook_for_dimension( $dimension ) {
		$map = array(
			'context'    => 'build_gutenberg_page',
			'graph'      => 'internal_link_improvement',
			'content'    => 'update_gutenberg_section',
			'seo'        => 'seo_audit_triage',
			'governance' => 'rollback_change',
		);

		return isset( $map[ $dimension ] ) ? $map[ $dimension ] : 'seo_audit_triage';
	}
}
