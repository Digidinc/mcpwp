<?php
/**
 * SEO Flags — act-worthy signals distilled from imported search performance.
 *
 * Deliberately narrow: everything that is not one of the six flags below is
 * ignored by design, so an agent gets a short, prioritized punch list instead
 * of a full analytics dump. See Mcpwp_Search_Performance for the underlying
 * Search Console/Bing/manual store this reads from (Performance, never
 * Visibility — see the "SEO Market Data" note in CLAUDE.md).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes the six act-worthy SEO signals from search performance rows.
 */
class Mcpwp_Seo_Flags {

	const DEFAULT_DAYS            = 28;
	const DEFAULT_MIN_IMPRESSIONS = 500;
	const DEFAULT_LIMIT           = 20;

	// almost_there: impression-weighted avg position in [MIN,MAX], non-brand.
	const ALMOST_THERE_MIN_POSITION    = 3;
	const ALMOST_THERE_MAX_POSITION    = 20;
	const ALMOST_THERE_IMPRESSION_FLOOR = 50;

	// no_clicks: real impressions, effectively no clicks.
	const NO_CLICKS_CTR_CEILING = 0.005;

	// decaying: needs a real base of clicks before "down 30%" means anything.
	const DECAY_MIN_BASE_CLICKS = 10;
	const DECAY_DROP_THRESHOLD  = 0.30;

	// ai_mode: long, conversational queries — 7 or more words.
	const AI_MODE_MIN_WORDS = 7;

	// wrong_intent thresholds (heuristic; see classify_intent()/is_intent_mismatch()).
	const COMPARISON_THIN_WORD_COUNT = 400;
	const HOW_TO_THIN_WORD_COUNT     = 200;

	/**
	 * MCP tool names that implement each flag's suggested action. Kept as a
	 * single map so a rename anywhere in the tool registry has one place to
	 * fix here too.
	 *
	 * @var array<string,array<int,string>>
	 */
	const TOOLS_BY_FLAG = array(
		'almost_there' => array( 'wp_update_page', 'wp_update_post', 'wp_apply_internal_link' ),
		'no_clicks'    => array( 'wp_set_seo', 'wp_update_page', 'wp_update_post' ),
		'decaying'     => array( 'wp_audit_content_quality', 'wp_update_page', 'wp_update_post', 'wp_submit_to_google_index' ),
		'untargeted'   => array( 'wp_create_page', 'wp_create_post', 'wp_apply_internal_link' ),
		'wrong_intent' => array( 'wp_update_page', 'wp_update_post' ),
		'ai_mode'      => array( 'wp_create_page', 'wp_create_post' ),
	);

	/**
	 * Suggested-action templates. `%1$s` = query, `%2$s` = url (some flags
	 * don't use both — sprintf ignores unused positional args).
	 *
	 * @var array<string,string>
	 */
	const ACTION_TEMPLATES = array(
		'almost_there' => 'add one sentence with the exact query to %2$s, or create a page and link from %2$s',
		'no_clicks'    => 'put the query in title, meta description, H1, first sentence; answer in first two lines; check page type',
		'decaying'     => 'refresh: re-check query coverage, update, request indexing',
		'untargeted'   => 'decide: target it on the ranking page or create a dedicated page',
		'wrong_intent' => 'match page type to intent (comparison/pricing/how-to)',
		'ai_mode'      => "consider a page/FAQ in the query's exact wording",
	);

	/**
	 * Build the full wp_seo_flags result: pulls rows/imports from
	 * Mcpwp_Search_Performance and content signals from live WordPress
	 * content, then delegates to the pure compute().
	 *
	 * @param array $args {
	 *     @type int   $days            Lookback window in days. Default 28.
	 *     @type int   $min_impressions Impression floor for no_clicks. Default 500.
	 *     @type array $brand_terms     Explicit brand terms; falls back to site name/domain + wp_recall.
	 *     @type int   $limit           Max items per flag. Default 20.
	 * }
	 * @return array
	 */
	public static function build( $args = array() ) {
		if ( ! class_exists( 'Mcpwp_Search_Performance' ) ) {
			return self::empty_result( $args, '' );
		}

		if ( ! Mcpwp_Search_Performance::has_any_rows() ) {
			return self::empty_result( $args, '' );
		}

		$rows          = Mcpwp_Search_Performance::get_all_rows();
		$imports       = Mcpwp_Search_Performance::get_all_imports();
		$last_import_at = Mcpwp_Search_Performance::get_last_import_at();
		$content_index = self::build_content_index();

		if ( empty( $args['brand_terms'] ) ) {
			$args['brand_terms'] = self::resolve_brand_terms();
		}

		return self::compute( $rows, $content_index, $imports, $args, $last_import_at );
	}

	/**
	 * Pure computation over an explicit row/content/import set. Kept free of
	 * WordPress storage calls so it can be unit tested with fixtures.
	 *
	 * @param array  $rows           All stored search performance rows (unfiltered).
	 * @param array  $content_index  Output of build_content_index() or an equivalent fixture.
	 * @param array  $imports        All stored imports, newest first.
	 * @param array  $args           See build().
	 * @param string $last_import_at Timestamp of the most recent import, if known.
	 * @return array
	 */
	public static function compute( array $rows, array $content_index = array(), array $imports = array(), array $args = array(), $last_import_at = '' ) {
		if ( empty( $rows ) ) {
			return self::empty_result( $args, $last_import_at );
		}

		$days            = isset( $args['days'] ) ? max( 1, min( 365, (int) $args['days'] ) ) : self::DEFAULT_DAYS;
		$min_impressions = isset( $args['min_impressions'] ) ? max( 0, (int) $args['min_impressions'] ) : self::DEFAULT_MIN_IMPRESSIONS;
		$limit           = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : self::DEFAULT_LIMIT;
		$brand_terms     = isset( $args['brand_terms'] ) && is_array( $args['brand_terms'] ) ? $args['brand_terms'] : array();

		$cutoff       = self::days_ago_date( self::max_row_date( $rows ), $days );
		$window_rows  = self::rows_since( $rows, $cutoff );
		$groups       = self::aggregate_rows( $window_rows );

		$flags = array(
			'almost_there' => self::detect_almost_there( $groups, $brand_terms, $limit ),
			'no_clicks'    => self::detect_no_clicks( $groups, $min_impressions, $limit ),
			'decaying'     => self::detect_decaying( $rows, $imports, $limit ),
			'untargeted'   => self::detect_untargeted( $groups, $content_index, $limit ),
			'wrong_intent' => self::detect_wrong_intent( $groups, $content_index, $limit ),
			'ai_mode'      => self::detect_ai_mode( $groups, $limit ),
		);

		$summary = array();
		foreach ( $flags as $flag => $items ) {
			$summary[ $flag ] = count( $items );
		}

		return array(
			'schema_version'  => '2026-09-26',
			'flags'           => $flags,
			'summary'         => $summary,
			'data_window'     => array(
				'days'   => $days,
				'cutoff' => $cutoff,
			),
			'last_import_at'  => $last_import_at,
			'filters'         => array(
				'days'            => $days,
				'min_impressions' => $min_impressions,
				'brand_terms'     => $brand_terms,
				'limit'           => $limit,
			),
			'guard'           => 'Returns only act-worthy signals — six named flags. Everything else in the imported search performance data is ignored by design.',
		);
	}

	/**
	 * Empty result shape used when there is no search performance data at
	 * all. Still a success — the agent's next move is to import data, not
	 * to treat this as an error.
	 *
	 * @param array  $args           Original args (for echoing filters back).
	 * @param string $last_import_at Known last import timestamp, if any.
	 * @return array
	 */
	private static function empty_result( $args, $last_import_at ) {
		$flags = array();
		foreach ( array_keys( self::TOOLS_BY_FLAG ) as $flag ) {
			$flags[ $flag ] = array();
		}

		return array(
			'schema_version' => '2026-09-26',
			'flags'          => $flags,
			'summary'        => array_fill_keys( array_keys( self::TOOLS_BY_FLAG ), 0 ),
			'data_window'    => array(
				'days'   => isset( $args['days'] ) ? (int) $args['days'] : self::DEFAULT_DAYS,
				'cutoff' => '',
			),
			'last_import_at' => $last_import_at,
			'filters'        => array(
				'days'            => isset( $args['days'] ) ? (int) $args['days'] : self::DEFAULT_DAYS,
				'min_impressions' => isset( $args['min_impressions'] ) ? (int) $args['min_impressions'] : self::DEFAULT_MIN_IMPRESSIONS,
				'brand_terms'     => isset( $args['brand_terms'] ) && is_array( $args['brand_terms'] ) ? $args['brand_terms'] : array(),
				'limit'           => isset( $args['limit'] ) ? (int) $args['limit'] : self::DEFAULT_LIMIT,
			),
			'next_step'      => 'No search performance data has been imported yet. Ask the site owner for a Google Search Console (or Bing Webmaster Tools) export, then call wp_import_search_performance with the exported rows before calling wp_seo_flags again.',
			'guard'          => 'Returns only act-worthy signals — six named flags. Everything else in the imported search performance data is ignored by design.',
		);
	}

	// ------------------------------------------------------------------
	// Aggregation helpers
	// ------------------------------------------------------------------

	/**
	 * Aggregate rows by (query, url), summing clicks/impressions and taking
	 * an impression-weighted average position — same convention as
	 * Mcpwp_Search_Performance::summarize_rows().
	 *
	 * @param array $rows Rows to aggregate.
	 * @return array Keyed by "query||url".
	 */
	private static function aggregate_rows( array $rows ) {
		$groups = array();

		foreach ( $rows as $row ) {
			$query = trim( (string) ( $row['query'] ?? '' ) );
			if ( '' === $query ) {
				continue;
			}
			$url = (string) ( $row['url'] ?? '' );
			$key = $query . '||' . $url;

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'query'         => $query,
					'url'           => $url,
					'clicks'        => 0,
					'impressions'   => 0,
					'_pos_weighted' => 0.0,
					'_pos_weight'   => 0,
				);
			}

			$clicks      = max( 0, (int) ( $row['clicks'] ?? 0 ) );
			$impressions = max( 0, (int) ( $row['impressions'] ?? 0 ) );

			$groups[ $key ]['clicks']      += $clicks;
			$groups[ $key ]['impressions'] += $impressions;

			if ( $impressions > 0 && isset( $row['position'] ) && (float) $row['position'] > 0 ) {
				$groups[ $key ]['_pos_weighted'] += (float) $row['position'] * $impressions;
				$groups[ $key ]['_pos_weight']   += $impressions;
			}
		}

		foreach ( $groups as $key => $g ) {
			$groups[ $key ]['ctr']      = $g['impressions'] > 0 ? round( $g['clicks'] / $g['impressions'], 4 ) : 0.0;
			$groups[ $key ]['position'] = $g['_pos_weight'] > 0 ? round( $g['_pos_weighted'] / $g['_pos_weight'], 2 ) : 0.0;
			unset( $groups[ $key ]['_pos_weighted'], $groups[ $key ]['_pos_weight'] );
		}

		return $groups;
	}

	/**
	 * Rows with date >= $cutoff (inclusive). Rows with no/invalid date are excluded.
	 *
	 * @param array  $rows   Rows.
	 * @param string $cutoff Y-m-d cutoff.
	 * @return array
	 */
	private static function rows_since( array $rows, $cutoff ) {
		if ( '' === $cutoff ) {
			return $rows;
		}
		return array_values(
			array_filter(
				$rows,
				static function ( $row ) use ( $cutoff ) {
					return (string) ( $row['date'] ?? '' ) >= $cutoff;
				}
			)
		);
	}

	/**
	 * Latest date present in a row set, or '' if none carry a date. Anchoring
	 * "recent" to the data's own latest date (rather than wall-clock now())
	 * keeps this correct for a stale-but-real import and deterministic for tests.
	 *
	 * @param array $rows Rows.
	 * @return string
	 */
	private static function max_row_date( array $rows ) {
		$max = '';
		foreach ( $rows as $row ) {
			$date = (string) ( $row['date'] ?? '' );
			if ( '' !== $date && ( '' === $max || $date > $max ) ) {
				$max = $date;
			}
		}
		return $max;
	}

	/**
	 * $anchor_date minus $days, as Y-m-d. Empty anchor -> empty cutoff (no filtering).
	 *
	 * @param string $anchor_date Y-m-d anchor.
	 * @param int    $days        Days to subtract.
	 * @return string
	 */
	private static function days_ago_date( $anchor_date, $days ) {
		if ( '' === $anchor_date ) {
			return '';
		}
		$ts = strtotime( $anchor_date );
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d', $ts - ( max( 0, (int) $days ) * DAY_IN_SECONDS ) );
	}

	// ------------------------------------------------------------------
	// Flag detectors
	// ------------------------------------------------------------------

	/**
	 * almost_there: ranking on page 1-2 (position 3-20), non-brand, with
	 * enough impressions that the ranking is real rather than a fluke.
	 *
	 * @param array $groups      Aggregated (query,url) groups.
	 * @param array $brand_terms Brand terms to exclude.
	 * @param int   $limit       Max items.
	 * @return array
	 */
	private static function detect_almost_there( array $groups, array $brand_terms, $limit ) {
		$items = array();

		foreach ( $groups as $g ) {
			if ( self::is_brand_query( $g['query'], $brand_terms ) ) {
				continue;
			}
			if ( $g['position'] < self::ALMOST_THERE_MIN_POSITION || $g['position'] > self::ALMOST_THERE_MAX_POSITION ) {
				continue;
			}
			if ( $g['impressions'] < self::ALMOST_THERE_IMPRESSION_FLOOR ) {
				continue;
			}

			$items[] = self::build_item(
				'almost_there',
				$g,
				sprintf( 'Avg position %.1f over %d impressions — page 1-2 but not converting to clicks yet.', $g['position'], $g['impressions'] )
			);
		}

		return self::sort_and_cap( $items, 'impressions', $limit );
	}

	/**
	 * no_clicks: real visibility, essentially no clicks.
	 *
	 * @param array $groups          Aggregated (query,url) groups.
	 * @param int   $min_impressions Impression floor.
	 * @param int   $limit           Max items.
	 * @return array
	 */
	private static function detect_no_clicks( array $groups, $min_impressions, $limit ) {
		$items = array();

		foreach ( $groups as $g ) {
			if ( $g['impressions'] <= $min_impressions ) {
				continue;
			}
			if ( $g['ctr'] >= self::NO_CLICKS_CTR_CEILING ) {
				continue;
			}

			$items[] = self::build_item(
				'no_clicks',
				$g,
				sprintf( '%d impressions, CTR %.2f%% — visible but nobody clicks.', $g['impressions'], $g['ctr'] * 100 )
			);
		}

		return self::sort_and_cap( $items, 'impressions', $limit );
	}

	/**
	 * decaying: clicks down >=30% comparing the most recent 7-day window to
	 * the 7 days before it (anchored to the data's own latest date). Falls
	 * back to comparing the two most recent imports when the row set doesn't
	 * have per-day granularity (e.g. one aggregated row per import).
	 *
	 * @param array $rows    All stored rows (unfiltered — decay looks past the display window).
	 * @param array $imports All stored imports, newest first.
	 * @param int   $limit   Max items.
	 * @return array
	 */
	private static function detect_decaying( array $rows, array $imports, $limit ) {
		$dates = array();
		foreach ( $rows as $row ) {
			$date = (string) ( $row['date'] ?? '' );
			if ( '' !== $date ) {
				$dates[ $date ] = true;
			}
		}
		$dates = array_keys( $dates );
		sort( $dates );

		if ( count( $dates ) >= 2 ) {
			$max_date     = end( $dates );
			$last_start   = self::days_ago_date( $max_date, 6 );
			$prev_start   = self::days_ago_date( $max_date, 13 );
			$prev_end     = self::days_ago_date( $max_date, 7 );

			$last_bucket = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $last_start, $max_date ) {
						$d = (string) ( $row['date'] ?? '' );
						return '' !== $d && $d >= $last_start && $d <= $max_date;
					}
				)
			);
			$prev_bucket = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $prev_start, $prev_end ) {
						$d = (string) ( $row['date'] ?? '' );
						return '' !== $d && $d >= $prev_start && $d <= $prev_end;
					}
				)
			);
		} else {
			// Only one distinct date across the whole store (e.g. a manual
			// CSV import that stamps every row with the export's report
			// date) — per-day windowing can't distinguish "recent" from
			// "before". Compare import boundaries instead.
			if ( count( $imports ) < 2 ) {
				return array();
			}
			$latest_id = (string) ( $imports[0]['id'] ?? '' );
			$prev_id   = (string) ( $imports[1]['id'] ?? '' );

			$last_bucket = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $latest_id ) {
						return (string) ( $row['import_id'] ?? '' ) === $latest_id;
					}
				)
			);
			$prev_bucket = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $prev_id ) {
						return (string) ( $row['import_id'] ?? '' ) === $prev_id;
					}
				)
			);
		}

		$last_groups = self::aggregate_rows( $last_bucket );
		$prev_groups = self::aggregate_rows( $prev_bucket );

		$items = array();
		foreach ( $prev_groups as $key => $prev_g ) {
			if ( $prev_g['clicks'] < self::DECAY_MIN_BASE_CLICKS ) {
				continue;
			}
			$last_g      = $last_groups[ $key ] ?? array( 'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'position' => 0.0 );
			$drop_amount = $prev_g['clicks'] - $last_g['clicks'];
			$drop_ratio  = $drop_amount / $prev_g['clicks'];

			if ( $drop_ratio < self::DECAY_DROP_THRESHOLD ) {
				continue;
			}

			$item = self::build_item(
				'decaying',
				array(
					'query'       => $prev_g['query'],
					'url'         => $prev_g['url'],
					'clicks'      => $last_g['clicks'],
					'impressions' => $last_g['impressions'],
					'ctr'         => $last_g['ctr'],
					'position'    => $last_g['position'],
				),
				sprintf( 'Clicks down %d%% (%d -> %d) vs the prior window.', (int) round( $drop_ratio * 100 ), $prev_g['clicks'], $last_g['clicks'] )
			);
			$item['_drop_ratio'] = $drop_ratio;
			$items[]             = $item;
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $b['_drop_ratio'] <=> $a['_drop_ratio'];
			}
		);
		foreach ( $items as $key => $item ) {
			unset( $items[ $key ]['_drop_ratio'] );
		}

		return array_slice( $items, 0, max( 1, (int) $limit ) );
	}

	/**
	 * untargeted: impressions exist, but no indexed page/post deliberately
	 * targets the query (title, slug, or SEO focus keyword).
	 *
	 * @param array $groups        Aggregated (query,url) groups.
	 * @param array $content_index Output of build_content_index().
	 * @param int   $limit         Max items.
	 * @return array
	 */
	private static function detect_untargeted( array $groups, array $content_index, $limit ) {
		$items = array();

		foreach ( $groups as $g ) {
			if ( $g['impressions'] <= 0 ) {
				continue;
			}
			if ( self::is_targeted( $g['query'], $content_index ) ) {
				continue;
			}

			$items[] = self::build_item(
				'untargeted',
				$g,
				sprintf( '%d impressions but no page/post title, slug, or focus keyword covers this query.', $g['impressions'] )
			);
		}

		return self::sort_and_cap( $items, 'impressions', $limit );
	}

	/**
	 * wrong_intent: the query's apparent intent (rule-based pattern match)
	 * doesn't match the page type currently ranking for it. Only flags clear
	 * mismatches; every item carries confidence "heuristic".
	 *
	 * @param array $groups        Aggregated (query,url) groups.
	 * @param array $content_index Output of build_content_index().
	 * @param int   $limit         Max items.
	 * @return array
	 */
	private static function detect_wrong_intent( array $groups, array $content_index, $limit ) {
		$items = array();

		foreach ( $groups as $g ) {
			$intent = self::classify_intent( $g['query'] );
			if ( null === $intent ) {
				continue;
			}

			$page = self::find_content_item_for_url( $g['url'], $content_index );
			if ( null === $page ) {
				continue; // Can't judge a mismatch without knowing the landing page's signals.
			}

			if ( ! self::is_intent_mismatch( $intent, $page ) ) {
				continue;
			}

			$item               = self::build_item(
				'wrong_intent',
				$g,
				sprintf( 'Query reads as "%s" intent but lands on a %s that does not match it.', $intent, $page['post_type'] ?? 'page' )
			);
			$item['intent']     = $intent;
			$item['confidence'] = 'heuristic';
			$items[]            = $item;
		}

		return self::sort_and_cap( $items, 'impressions', $limit );
	}

	/**
	 * ai_mode: long, conversational queries (7+ words) — the shape of
	 * queries an AI answer engine relays rather than a human typing keywords.
	 *
	 * @param array $groups Aggregated (query,url) groups.
	 * @param int   $limit  Max items.
	 * @return array
	 */
	private static function detect_ai_mode( array $groups, $limit ) {
		$items = array();

		foreach ( $groups as $g ) {
			if ( self::word_count( $g['query'] ) < self::AI_MODE_MIN_WORDS ) {
				continue;
			}

			$items[] = self::build_item(
				'ai_mode',
				$g,
				sprintf( '%d-word query — long-tail/conversational phrasing typical of AI answer engines.', self::word_count( $g['query'] ) )
			);
		}

		return self::sort_and_cap( $items, 'impressions', $limit );
	}

	// ------------------------------------------------------------------
	// Shared item shaping
	// ------------------------------------------------------------------

	/**
	 * Build one flag item in the shared output shape.
	 *
	 * @param string $flag     Flag name (key into TOOLS_BY_FLAG/ACTION_TEMPLATES).
	 * @param array  $g        Group data with query/url/clicks/impressions/ctr/position.
	 * @param string $evidence Human-readable evidence sentence.
	 * @return array
	 */
	private static function build_item( $flag, array $g, $evidence ) {
		$query = (string) ( $g['query'] ?? '' );
		$url   = (string) ( $g['url'] ?? '' );

		return array(
			'query'            => $query,
			'url'              => $url,
			'clicks'           => (int) ( $g['clicks'] ?? 0 ),
			'impressions'      => (int) ( $g['impressions'] ?? 0 ),
			'ctr'               => (float) ( $g['ctr'] ?? 0 ),
			'position'         => (float) ( $g['position'] ?? 0 ),
			'evidence'         => $evidence,
			'suggested_action' => sprintf( self::ACTION_TEMPLATES[ $flag ], $query, $url ),
			'tools'            => self::TOOLS_BY_FLAG[ $flag ],
		);
	}

	/**
	 * Sort items by a numeric key descending and cap to $limit.
	 *
	 * @param array  $items Items.
	 * @param string $key   Sort key.
	 * @param int    $limit Max items.
	 * @return array
	 */
	private static function sort_and_cap( array $items, $key, $limit ) {
		usort(
			$items,
			static function ( $a, $b ) use ( $key ) {
				return ( $b[ $key ] ?? 0 ) <=> ( $a[ $key ] ?? 0 );
			}
		);
		return array_slice( $items, 0, max( 1, (int) $limit ) );
	}

	// ------------------------------------------------------------------
	// Brand / intent / tokenizing helpers
	// ------------------------------------------------------------------

	/**
	 * Whether a query contains one of the brand terms.
	 *
	 * @param string $query       Query text.
	 * @param array  $brand_terms Brand terms.
	 * @return bool
	 */
	private static function is_brand_query( $query, array $brand_terms ) {
		if ( empty( $brand_terms ) ) {
			return false;
		}
		$q = strtolower( $query );
		foreach ( $brand_terms as $term ) {
			$term = strtolower( trim( (string) $term ) );
			if ( '' === $term ) {
				continue;
			}
			if ( false !== strpos( $q, $term ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rule-based intent classification. Returns null when no pattern is a
	 * confident match — "no clear intent signal" is not the same as "no
	 * mismatch", so callers must treat null as "skip", not "match".
	 *
	 * @param string $query Query text.
	 * @return string|null One of comparison|price|how_to|local, or null.
	 */
	private static function classify_intent( $query ) {
		$q = ' ' . strtolower( trim( (string) $query ) ) . ' ';

		if ( preg_match( '/\b(vs|versus|compare|comparison|alternative|alternatives|best)\b/', $q ) ) {
			return 'comparison';
		}
		if ( preg_match( '/\b(how much|cost|costs|price|pricing)\b/', $q ) ) {
			return 'price';
		}
		if ( preg_match( '/\b(how to|guide|tutorial)\b/', $q ) ) {
			return 'how_to';
		}
		if ( preg_match( '/\bnear me\b/', $q ) || preg_match( '/\bin\s+[a-z]+\s*$/', $q ) ) {
			return 'local';
		}
		return null;
	}

	/**
	 * Whether a page's type/depth signals mismatch a query's classified intent.
	 *
	 * @param string $intent One of comparison|price|how_to|local.
	 * @param array  $page   Content index item (post_type, has_table, word_count).
	 * @return bool
	 */
	private static function is_intent_mismatch( $intent, array $page ) {
		$post_type  = (string) ( $page['post_type'] ?? '' );
		$word_count = (int) ( $page['word_count'] ?? 0 );
		$has_table  = ! empty( $page['has_table'] );

		switch ( $intent ) {
			case 'comparison':
				// A comparison/best-of query expects a substantial page — a
				// table, or enough depth to actually compare options.
				return ! $has_table && $word_count < self::COMPARISON_THIN_WORD_COUNT;
			case 'price':
				// A pricing query landing on a generic blog post (rather than
				// a page/product) is a page-type mismatch.
				return 'post' === $post_type;
			case 'how_to':
				return $word_count < self::HOW_TO_THIN_WORD_COUNT;
			case 'local':
				return 'post' === $post_type;
			default:
				return false;
		}
	}

	/**
	 * Word count via whitespace splitting — equivalent to matching
	 * `(\b\w+\b\s){6,}\b\w+\b` (7+ words) against the query.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function word_count( $text ) {
		$words = preg_split( '/\s+/', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Normalize text into a set of significant lowercase word tokens, with
	 * common stopwords removed so token-set matching isn't defeated by
	 * "how"/"the"/"for" noise.
	 *
	 * @param string $text Text.
	 * @return array
	 */
	private static function normalize_tokens( $text ) {
		$text  = strtolower( (string) $text );
		$text  = preg_replace( '/[^a-z0-9\s-]/', ' ', $text );
		$words = preg_split( '/[\s-]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return array();
		}

		static $stopwords = array(
			'a', 'an', 'the', 'and', 'or', 'to', 'of', 'in', 'on', 'for', 'is',
			'are', 'how', 'what', 'why', 'best', 'near', 'me', 'vs', 'versus',
			'with', 'your', 'you', 'it', 'this', 'that', 'do', 'does', 'can', 'i',
		);

		return array_values( array_unique( array_diff( $words, $stopwords ) ) );
	}

	/**
	 * A query is "targeted" when some content index item's tokens (title +
	 * slug + focus keyword combined) cover every significant token in the
	 * query. An empty query (all stopwords) is trivially considered targeted.
	 *
	 * @param string $query         Query text.
	 * @param array  $content_index Output of build_content_index().
	 * @return bool
	 */
	private static function is_targeted( $query, array $content_index ) {
		$query_tokens = self::normalize_tokens( $query );
		if ( empty( $query_tokens ) ) {
			return true;
		}

		foreach ( $content_index as $item ) {
			$item_tokens = isset( $item['tokens'] ) && is_array( $item['tokens'] ) ? $item['tokens'] : array();
			if ( empty( array_diff( $query_tokens, $item_tokens ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find the content index item whose URL matches (by path) the given URL.
	 *
	 * @param string $url           URL to match.
	 * @param array  $content_index Output of build_content_index().
	 * @return array|null
	 */
	private static function find_content_item_for_url( $url, array $content_index ) {
		$path = self::url_path( $url );
		if ( '' === $path ) {
			return null;
		}
		foreach ( $content_index as $item ) {
			if ( self::url_path( $item['url'] ?? '' ) === $path ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * Normalized comparable path for a URL: lowercase, no scheme/host, no
	 * trailing slash.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function url_path( $url ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( null === $path || false === $path ) {
			$path = $url;
		}
		$path = strtolower( rtrim( (string) $path, '/' ) );
		return '' === $path ? '/' : $path;
	}

	// ------------------------------------------------------------------
	// WordPress-dependent builders (not exercised by pure unit tests)
	// ------------------------------------------------------------------

	/**
	 * Build a cheap, once-per-call index of titles/slugs/focus keywords for
	 * every published page/post, used by detect_untargeted()/detect_wrong_intent().
	 * Capped so a large site can't blow the request's time budget.
	 *
	 * @param int $limit Max posts to inspect.
	 * @return array
	 */
	public static function build_content_index( $limit = 500 ) {
		if ( ! function_exists( 'get_posts' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, min( 2000, (int) $limit ) ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);

		$index = array();
		foreach ( (array) $posts as $post ) {
			$title         = (string) ( $post->post_title ?? '' );
			$slug          = (string) ( $post->post_name ?? '' );
			$focus_keyword = (string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true );
			if ( '' === $focus_keyword ) {
				$focus_keyword = (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true );
			}

			$content    = (string) ( $post->post_content ?? '' );
			$plain      = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $content ) : preg_replace( '/<[^>]*>/', ' ', $content );
			$word_count = self::word_count( $plain );
			$has_table  = false !== stripos( $content, '<table' ) || false !== stripos( $content, 'wp-block-table' );

			$tokens = array_values(
				array_unique(
					array_merge(
						self::normalize_tokens( $title ),
						self::normalize_tokens( str_replace( '-', ' ', $slug ) ),
						self::normalize_tokens( $focus_keyword )
					)
				)
			);

			$index[] = array(
				'id'            => (int) $post->ID,
				'url'           => (string) get_permalink( $post ),
				'post_type'     => (string) ( $post->post_type ?? 'post' ),
				'title'         => $title,
				'slug'          => $slug,
				'focus_keyword' => $focus_keyword,
				'has_table'     => $has_table,
				'word_count'    => $word_count,
				'tokens'        => $tokens,
			);
		}

		return $index;
	}

	/**
	 * Resolve brand terms: site name + domain tokens, plus anything stored
	 * under a wp_recall entry keyed "brand_terms" (namespace not assumed —
	 * Mcpwp_Site_Memory::recall(null, ...) searches every namespace).
	 *
	 * @return array
	 */
	public static function resolve_brand_terms() {
		$terms = array();

		if ( function_exists( 'get_bloginfo' ) ) {
			$site_name = (string) get_bloginfo( 'name' );
			foreach ( self::normalize_tokens( $site_name ) as $token ) {
				if ( strlen( $token ) >= 3 ) {
					$terms[] = $token;
				}
			}
			if ( '' !== trim( $site_name ) ) {
				$terms[] = trim( $site_name );
			}
		}

		if ( function_exists( 'home_url' ) ) {
			$host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $host ) {
				$host = preg_replace( '/^www\./', '', (string) $host );
				$host = preg_replace( '/\.[a-z]{2,}$/', '', $host ); // Strip a single TLD.
				foreach ( self::normalize_tokens( str_replace( array( '.', '-' ), ' ', (string) $host ) ) as $token ) {
					if ( strlen( $token ) >= 3 ) {
						$terms[] = $token;
					}
				}
			}
		}

		if ( class_exists( 'Mcpwp_Site_Memory' ) ) {
			foreach ( Mcpwp_Site_Memory::recall( null, 'brand_terms' ) as $entry ) {
				if ( 'brand_terms' !== ( $entry['key'] ?? '' ) ) {
					continue;
				}
				$value = $entry['value'] ?? null;
				if ( is_array( $value ) ) {
					foreach ( $value as $term ) {
						if ( is_string( $term ) && '' !== trim( $term ) ) {
							$terms[] = trim( $term );
						}
					}
				} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
					foreach ( explode( ',', $value ) as $term ) {
						if ( '' !== trim( $term ) ) {
							$terms[] = trim( $term );
						}
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $terms ) ) );
	}
}
