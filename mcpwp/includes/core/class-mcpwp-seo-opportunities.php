<?php
/**
 * Ranks SEO work by fusing visibility, market metrics and audit issues.
 *
 * The signal adapts to how mature the site is. An earlier design used striking
 * distance (positions 11-20) as the entry condition; tested against a real
 * domain it returned nothing, because that site ranked only at positions 64-91.
 * Most installs are new or thin sites, so they are the primary case and
 * striking distance is a multiplier, never a gate.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opportunity ranker. Pure functions — no I/O.
 */
class Mcpwp_SEO_Opportunities {

	/**
	 * Below this position a site is treated as still emerging.
	 */
	const STRIKING_DISTANCE_MAX = 20;

	/**
	 * Score multiplier applied to keywords already within striking distance.
	 */
	const STRIKING_DISTANCE_BOOST = 3.0;

	/**
	 * Substituted for a zero difficulty so the ratio stays finite.
	 */
	const MIN_DIFFICULTY = 1;

	const MAX_OPPORTUNITIES = 20;

	/**
	 * Rank the work worth doing.
	 *
	 * @param array $visibility_rows Rows from Mcpwp_SEO_Visibility.
	 * @param array $keyword_metrics Rows from keyword enrichment.
	 * @param array $audit_issues    Open issues from Mcpwp_SEO_Audit_Store.
	 * @return array{mode:string,opportunities:array,reason:string|null}
	 */
	public static function rank( array $visibility_rows, array $keyword_metrics, array $audit_issues ) {
		if ( empty( $visibility_rows ) && empty( $keyword_metrics ) ) {
			return array(
				'mode'          => 'unknown',
				'opportunities' => array(),
				'reason'        => 'no_inputs',
			);
		}

		if ( empty( $visibility_rows ) ) {
			return array(
				'mode'          => 'greenfield',
				'opportunities' => self::from_keyword_metrics( $keyword_metrics ),
				'reason'        => null,
			);
		}

		$mode = self::detect_mode( $visibility_rows );

		return array(
			'mode'          => $mode,
			'opportunities' => self::from_visibility( $visibility_rows ),
			'reason'        => null,
		);
	}

	/**
	 * Classify the site by how well it already ranks.
	 *
	 * @param array $rows Visibility rows.
	 * @return string 'mature' or 'emerging'.
	 */
	private static function detect_mode( array $rows ) {
		foreach ( $rows as $row ) {
			$position = isset( $row['position'] ) ? (int) $row['position'] : 0;
			if ( $position > 0 && $position <= self::STRIKING_DISTANCE_MAX ) {
				return 'mature';
			}
		}

		return 'emerging';
	}

	/**
	 * Score keywords the site already ranks for.
	 *
	 * @param array $rows Visibility rows.
	 * @return array Ranked opportunities.
	 */
	private static function from_visibility( array $rows ) {
		$scored = array();

		foreach ( $rows as $row ) {
			$keyword = isset( $row['keyword'] ) ? (string) $row['keyword'] : '';
			if ( '' === $keyword ) {
				continue;
			}

			$volume     = isset( $row['search_volume'] ) ? (int) $row['search_volume'] : 0;
			$difficulty = isset( $row['keyword_difficulty'] ) ? (int) $row['keyword_difficulty'] : 0;
			$position   = isset( $row['position'] ) ? (int) $row['position'] : 0;

			$score = $volume / max( self::MIN_DIFFICULTY, $difficulty );

			$striking = ( $position > 0 && $position <= self::STRIKING_DISTANCE_MAX );
			if ( $striking ) {
				$score *= self::STRIKING_DISTANCE_BOOST;
			}

			$scored[] = array(
				'keyword'            => $keyword,
				'position'           => $position,
				'search_volume'      => $volume,
				'keyword_difficulty' => $difficulty,
				'ranking_url'        => isset( $row['ranking_url'] ) ? (string) $row['ranking_url'] : '',
				'score'              => (float) $score,
				'reason'             => $striking
					? sprintf(
						/* translators: 1: current position, 2: monthly search volume. */
						__( 'Ranks %1$d for %2$d monthly searches — close enough to reach page one.', 'mcpwp' ),
						$position,
						$volume
					)
					: sprintf(
						/* translators: 1: monthly search volume, 2: difficulty score, 3: current position. */
						__( '%1$d monthly searches at difficulty %2$d, currently ranking %3$d — winnable with focused work.', 'mcpwp' ),
						$volume,
						$difficulty,
						$position
					),
			);
		}

		return self::sort_and_cap( $scored );
	}

	/**
	 * Score keywords for a site that ranks for nothing yet.
	 *
	 * @param array $metrics Keyword metric rows.
	 * @return array Ranked opportunities.
	 */
	private static function from_keyword_metrics( array $metrics ) {
		$scored = array();

		foreach ( $metrics as $row ) {
			$keyword = isset( $row['keyword'] ) ? (string) $row['keyword'] : '';
			if ( '' === $keyword ) {
				continue;
			}

			$volume     = isset( $row['search_volume'] ) ? (int) $row['search_volume'] : 0;
			$difficulty = isset( $row['keyword_difficulty'] ) ? (int) $row['keyword_difficulty'] : 0;

			$scored[] = array(
				'keyword'            => $keyword,
				'position'           => 0,
				'search_volume'      => $volume,
				'keyword_difficulty' => $difficulty,
				'ranking_url'        => '',
				'score'              => (float) ( $volume / max( self::MIN_DIFFICULTY, $difficulty ) ),
				'reason'             => sprintf(
					/* translators: 1: monthly search volume, 2: difficulty score. */
					__( 'Not ranking yet. %1$d monthly searches at difficulty %2$d — worth targeting with new content.', 'mcpwp' ),
					$volume,
					$difficulty
				),
			);
		}

		return self::sort_and_cap( $scored );
	}

	/**
	 * Sort by score descending and cap the list.
	 *
	 * @param array $scored Scored opportunities.
	 * @return array
	 */
	private static function sort_and_cap( array $scored ) {
		usort(
			$scored,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);

		return array_slice( $scored, 0, self::MAX_OPPORTUNITIES );
	}
}
