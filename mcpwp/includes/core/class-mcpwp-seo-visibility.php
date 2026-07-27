<?php
/**
 * Search visibility snapshots — where the site ranks in the market.
 *
 * Deliberately separate from Mcpwp_Search_Performance. That store holds
 * first-party traffic (clicks, impressions, CTR) from Search Console. This one
 * holds market position and volume. Sharing a table would force zeroed click
 * columns that render as "no traffic", and the two sources do not even measure
 * position the same way: Search Console reports an impression-weighted average,
 * DataForSEO reports a scraped SERP rank.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visibility snapshot store.
 */
class Mcpwp_SEO_Visibility {

	const OPTION_NAME = 'mcpwp_seo_visibility';

	/**
	 * Persist a snapshot, replacing any previous one.
	 *
	 * @param array  $rows   Normalized rows from the provider.
	 * @param string $target Domain the snapshot describes.
	 * @return array{snapshot_id:string,captured_at:int,count:int}
	 */
	public static function store_snapshot( $rows, $target ) {
		$captured_at = time();
		$snapshot_id = 'vis_' . md5( $target . '|' . $captured_at );

		$clean = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['keyword'] ) ) {
				continue;
			}

			$clean[] = array(
				'keyword'            => sanitize_text_field( (string) $row['keyword'] ),
				'position'           => isset( $row['position'] ) ? (int) $row['position'] : 0,
				'ranking_url'        => isset( $row['ranking_url'] ) ? esc_url_raw( (string) $row['ranking_url'] ) : '',
				'search_volume'      => isset( $row['search_volume'] ) ? (int) $row['search_volume'] : 0,
				'keyword_difficulty' => isset( $row['keyword_difficulty'] ) ? (int) $row['keyword_difficulty'] : 0,
			);
		}

		usort(
			$clean,
			function ( $a, $b ) {
				return $a['position'] - $b['position'];
			}
		);

		// Not autoloaded: a snapshot can hold up to 1000 rows (~150KB serialized),
		// and this option is read on two admin screens and two REST routes, not
		// on every front-end/admin request. Siblings Mcpwp_Approvals and
		// Mcpwp_Event_Store make the same call for the same reason.
		update_option(
			self::OPTION_NAME,
			array(
				'snapshot_id' => $snapshot_id,
				'captured_at' => $captured_at,
				'target'      => sanitize_text_field( (string) $target ),
				'rows'        => $clean,
			),
			false
		);

		return array(
			'snapshot_id' => $snapshot_id,
			'captured_at' => $captured_at,
			'count'       => count( $clean ),
		);
	}

	/**
	 * Read the latest snapshot.
	 *
	 * Distinguishes "never fetched" from "fetched and the site ranks for
	 * nothing" — the first is a gap, the second is an answer.
	 *
	 * @return array{snapshot_id:string|null,captured_at:int|null,target:string|null,rows:array,reason:string|null}
	 */
	public static function get_latest() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['snapshot_id'] ) ) {
			return array(
				'snapshot_id' => null,
				'captured_at' => null,
				'target'      => null,
				'rows'        => array(),
				'reason'      => 'never_fetched',
			);
		}

		$rows = isset( $stored['rows'] ) && is_array( $stored['rows'] ) ? $stored['rows'] : array();

		return array(
			'snapshot_id' => (string) $stored['snapshot_id'],
			'captured_at' => isset( $stored['captured_at'] ) ? (int) $stored['captured_at'] : null,
			'target'      => isset( $stored['target'] ) ? (string) $stored['target'] : null,
			'rows'        => $rows,
			'reason'      => empty( $rows ) ? 'no_rankings' : null,
		);
	}
}
