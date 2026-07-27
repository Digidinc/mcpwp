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
	 * Lower bound of striking distance (page two). A keyword already ranking
	 * above this position has already been won — it must not flip a site into
	 * 'mature' mode or receive the boost meant for near-miss terms.
	 */
	const STRIKING_DISTANCE_MIN = 11;

	/**
	 * Upper bound of striking distance (page two). Below this position a site
	 * is treated as still emerging.
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
	 * No visibility snapshot exists yet and no keyword metrics were supplied —
	 * genuinely unknown, not an answer.
	 */
	const REASON_NO_INPUTS = 'no_inputs';

	/**
	 * A visibility snapshot exists (the site was fetched) but it ranks for
	 * nothing. A real answer, distinct from REASON_NO_INPUTS — the caller
	 * should be pointed at seeding a keyword, not at connecting a provider.
	 */
	const REASON_NO_RANKINGS_NEEDS_SEED = 'no_rankings_needs_seed';

	/**
	 * A seed keyword was supplied and a paid lookup actually ran, but the
	 * provider genuinely returned zero suggestions for it. Distinct from
	 * REASON_NO_RANKINGS_NEEDS_SEED — that reason means no seed was given yet;
	 * this one means a seed was given and answered with nothing.
	 */
	const REASON_SEED_NO_SUGGESTIONS = 'seed_no_suggestions';

	/**
	 * Expected value of the incoming $visibility_reason when
	 * Mcpwp_SEO_Visibility::get_latest() fetched successfully and the site
	 * ranks for nothing. Owned by Mcpwp_SEO_Visibility's own reason enum;
	 * named here so the branch below never compares against a bare literal.
	 */
	const INPUT_REASON_NO_RANKINGS = 'no_rankings';

	/**
	 * Rank the work worth doing.
	 *
	 * @param array       $visibility_rows   Rows from Mcpwp_SEO_Visibility.
	 * @param array       $keyword_metrics   Rows from keyword enrichment.
	 * @param array       $audit_issues      Open issues from Mcpwp_SEO_Audit_Store.
	 * @param string|null $visibility_reason Reason from Mcpwp_SEO_Visibility::get_latest(),
	 *                                       e.g. 'never_fetched' or 'no_rankings'. Optional —
	 *                                       omitting it preserves prior behavior.
	 * @return array{mode:string,opportunities:array,reason:string|null}
	 */
	public static function rank( array $visibility_rows, array $keyword_metrics, array $audit_issues, $visibility_reason = null ) {
		if ( empty( $visibility_rows ) && empty( $keyword_metrics ) ) {
			if ( self::INPUT_REASON_NO_RANKINGS === $visibility_reason ) {
				return array(
					'mode'          => 'greenfield',
					'opportunities' => array(),
					'reason'        => self::REASON_NO_RANKINGS_NEEDS_SEED,
				);
			}

			return array(
				'mode'          => 'unknown',
				'opportunities' => array(),
				'reason'        => self::REASON_NO_INPUTS,
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
			if ( $position >= self::STRIKING_DISTANCE_MIN && $position <= self::STRIKING_DISTANCE_MAX ) {
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

			$striking = ( $position >= self::STRIKING_DISTANCE_MIN && $position <= self::STRIKING_DISTANCE_MAX );
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
					// PHP's sort is only stable from 8.0; the floor here is 7.4,
					// and tied scores are common (e.g. 100/10 and 200/20 both
					// score 10). Without an explicit tie-break, two identical
					// requests can return a different top-N ordering with no
					// underlying data change.
					return strcmp( $a['keyword'], $b['keyword'] );
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);

		return array_slice( $scored, 0, self::MAX_OPPORTUNITIES );
	}
}
