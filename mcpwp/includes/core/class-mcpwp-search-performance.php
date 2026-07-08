<?php
/**
 * Search performance import store and reporting.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores provider-neutral Search Console/Bing/manual search performance rows.
 */
class Mcpwp_Search_Performance {

	const IMPORTS_OPTION = 'mcpwp_search_performance_imports';
	const ROWS_OPTION    = 'mcpwp_search_performance_rows';
	const MAX_IMPORTS    = 50;
	const MAX_ROWS       = 5000;

	/**
	 * Import normalized search performance rows.
	 *
	 * @param array $args Import args.
	 * @return array Import result.
	 */
	public static function import_rows( $args = array() ) {
		$provider = self::normalize_provider( isset( $args['provider'] ) ? $args['provider'] : 'manual' );
		$source   = sanitize_text_field( (string) ( $args['source'] ?? '' ) );
		$rows     = isset( $args['rows'] ) && is_array( $args['rows'] ) ? $args['rows'] : array();
		$now      = gmdate( 'c' );
		$import_id = self::generate_import_id();

		$normalized = array();
		foreach ( $rows as $row ) {
			$item = self::normalize_row( $row, $provider, $import_id, $now );
			if ( ! empty( $item ) ) {
				$normalized[] = $item;
			}
		}

		$imports = self::get_imports();
		array_unshift(
			$imports,
			array(
				'id'          => $import_id,
				'provider'    => $provider,
				'source'      => $source,
				'imported_at' => $now,
				'row_count'   => count( $normalized ),
			)
		);
		$imports = array_slice( $imports, 0, self::MAX_IMPORTS );

		$stored_rows = array_merge( $normalized, self::get_rows() );
		$stored_rows = array_slice( $stored_rows, 0, self::MAX_ROWS );

		update_option( self::IMPORTS_OPTION, $imports );
		update_option( self::ROWS_OPTION, $stored_rows );

		$result = array(
			'id'          => $import_id,
			'provider'    => $provider,
			'source'      => $source,
			'imported_at' => $now,
			'row_count'   => count( $normalized ),
			'ignored'     => max( 0, count( $rows ) - count( $normalized ) ),
		);

		if ( class_exists( 'Mcpwp_Event_Store' ) ) {
			Mcpwp_Event_Store::emit(
				'seo.search_performance_imported',
				array( 'import' => $result ),
				array(
					'resource'           => array(
						'type' => 'search_performance_import',
						'id'   => $import_id,
					),
					'risk_level'         => 'low',
					'seo_state'          => 'evidence_imported',
					'recommended_action' => __( 'Review search performance trends before changing SEO content.', 'mumcp' ),
				)
			);
		}

		return $result;
	}

	/**
	 * Get aggregate report.
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function get_report( $args = array() ) {
		$provider = isset( $args['provider'] ) ? self::normalize_provider( $args['provider'] ) : '';
		$url      = isset( $args['url'] ) ? esc_url_raw( (string) $args['url'] ) : '';
		$query    = isset( $args['query'] ) ? sanitize_text_field( (string) $args['query'] ) : '';
		$days     = isset( $args['days'] ) ? max( 1, min( 365, absint( $args['days'] ) ) ) : 90;
		$limit    = isset( $args['limit'] ) ? max( 1, min( 100, absint( $args['limit'] ) ) ) : 20;
		$cutoff   = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		$rows = array_values(
			array_filter(
				self::get_rows(),
				static function ( $row ) use ( $provider, $url, $query, $cutoff ) {
					if ( '' !== $provider && ( $row['provider'] ?? '' ) !== $provider ) {
						return false;
					}
					if ( '' !== $url && ( $row['url'] ?? '' ) !== $url ) {
						return false;
					}
					if ( '' !== $query && false === stripos( (string) ( $row['query'] ?? '' ), $query ) ) {
						return false;
					}
					return (string) ( $row['date'] ?? '' ) >= $cutoff;
				}
			)
		);

		$summary = self::summarize_rows( $rows );

		return array(
			'schema_version' => '2026-05-20',
			'summary'        => $summary,
			'filters'        => array(
				'provider' => $provider,
				'url'      => $url,
				'query'    => $query,
				'days'     => $days,
				'limit'    => $limit,
			),
			'imports'        => array_slice( self::get_imports(), 0, 10 ),
			'top_queries'    => self::aggregate_dimension( $rows, 'query', $limit ),
			'top_urls'       => self::aggregate_dimension( $rows, 'url', $limit ),
			'daily'          => self::aggregate_dimension( $rows, 'date', min( 100, $days ) ),
			'workflow'       => array(
				'import'   => 'Import exports from Google Search Console, Bing Webmaster Tools, rank trackers, or manual CSV parsing.',
				'analyze'  => 'Use queries and URLs as evidence for SEO briefs, content refreshes, internal links, and WooCommerce SEO prioritization.',
				'guard'    => 'This report is read-only evidence. It does not fetch external APIs or mutate SEO content.',
				'approval' => 'Any content, metadata, schema, or link change based on this evidence still needs an approval-first workflow.',
			),
		);
	}

	/**
	 * Normalize one row.
	 *
	 * @param array  $row       Input row.
	 * @param string $provider  Provider.
	 * @param string $import_id Import ID.
	 * @param string $now       Import timestamp.
	 * @return array
	 */
	private static function normalize_row( $row, $provider, $import_id, $now ) {
		if ( ! is_array( $row ) ) {
			return array();
		}

		$date = self::normalize_date( $row['date'] ?? '' );
		$url  = esc_url_raw( (string) ( $row['url'] ?? $row['page'] ?? '' ) );
		if ( '' === $date || '' === $url ) {
			return array();
		}

		$clicks      = max( 0, (int) ( $row['clicks'] ?? 0 ) );
		$impressions = max( 0, (int) ( $row['impressions'] ?? 0 ) );
		$ctr         = isset( $row['ctr'] ) ? max( 0, (float) $row['ctr'] ) : ( $impressions > 0 ? $clicks / $impressions : 0 );
		$position    = isset( $row['position'] ) ? max( 0, (float) $row['position'] ) : 0;

		return array(
			'id'          => md5( implode( '|', array( $provider, $import_id, $date, $url, (string) ( $row['query'] ?? '' ) ) ) ),
			'import_id'   => $import_id,
			'imported_at' => $now,
			'provider'    => $provider,
			'date'        => $date,
			'url'         => $url,
			'query'       => sanitize_text_field( (string) ( $row['query'] ?? '' ) ),
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => $ctr,
			'position'    => $position,
		);
	}

	/**
	 * Summarize rows.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	private static function summarize_rows( $rows ) {
		$summary = array(
			'rows'        => count( $rows ),
			'clicks'      => 0,
			'impressions' => 0,
			'ctr'         => 0,
			'position'    => 0,
			'providers'   => array(),
		);
		$weighted_position = 0;
		$position_weight   = 0;

		foreach ( $rows as $row ) {
			$clicks               = (int) ( $row['clicks'] ?? 0 );
			$impressions          = (int) ( $row['impressions'] ?? 0 );
			$summary['clicks']   += $clicks;
			$summary['impressions'] += $impressions;
			$provider             = sanitize_key( (string) ( $row['provider'] ?? 'manual' ) );
			if ( ! isset( $summary['providers'][ $provider ] ) ) {
				$summary['providers'][ $provider ] = 0;
			}
			$summary['providers'][ $provider ]++;

			if ( ! empty( $row['position'] ) && $impressions > 0 ) {
				$weighted_position += (float) $row['position'] * $impressions;
				$position_weight   += $impressions;
			}
		}

		$summary['ctr']      = $summary['impressions'] > 0 ? round( $summary['clicks'] / $summary['impressions'], 4 ) : 0;
		$summary['position'] = $position_weight > 0 ? round( $weighted_position / $position_weight, 2 ) : 0;

		return $summary;
	}

	/**
	 * Aggregate one row dimension.
	 *
	 * @param array  $rows      Rows.
	 * @param string $dimension Dimension key.
	 * @param int    $limit     Limit.
	 * @return array
	 */
	private static function aggregate_dimension( $rows, $dimension, $limit ) {
		$groups = array();
		foreach ( $rows as $row ) {
			$key = (string) ( $row[ $dimension ] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					$dimension    => $key,
					'rows'        => 0,
					'clicks'      => 0,
					'impressions' => 0,
					'ctr'         => 0,
					'position'    => 0,
				);
			}
			$groups[ $key ]['rows']++;
			$groups[ $key ]['clicks']      += (int) ( $row['clicks'] ?? 0 );
			$groups[ $key ]['impressions'] += (int) ( $row['impressions'] ?? 0 );
			if ( ! empty( $row['position'] ) && ! empty( $row['impressions'] ) ) {
				$groups[ $key ]['position'] += (float) $row['position'] * (int) $row['impressions'];
			}
		}

		foreach ( $groups as $key => $group ) {
			$groups[ $key ]['ctr'] = $group['impressions'] > 0 ? round( $group['clicks'] / $group['impressions'], 4 ) : 0;
			$groups[ $key ]['position'] = $group['impressions'] > 0 ? round( $group['position'] / $group['impressions'], 2 ) : 0;
		}

		usort(
			$groups,
			static function ( $a, $b ) {
				if ( (int) $a['clicks'] === (int) $b['clicks'] ) {
					return (int) $b['impressions'] <=> (int) $a['impressions'];
				}
				return (int) $b['clicks'] <=> (int) $a['clicks'];
			}
		);

		return array_slice( $groups, 0, $limit );
	}

	/**
	 * Normalize provider.
	 *
	 * @param string $provider Provider.
	 * @return string
	 */
	private static function normalize_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );
		$aliases  = array(
			'gsc'                   => 'google_search_console',
			'google'                => 'google_search_console',
			'search_console'        => 'google_search_console',
			'bing'                  => 'bing_webmaster',
			'bing_webmaster_tools'  => 'bing_webmaster',
			'manual_csv'            => 'manual',
		);
		if ( isset( $aliases[ $provider ] ) ) {
			return $aliases[ $provider ];
		}
		return '' !== $provider ? $provider : 'manual';
	}

	/**
	 * Normalize date as Y-m-d.
	 *
	 * @param string $date Date.
	 * @return string
	 */
	private static function normalize_date( $date ) {
		$date = trim( (string) $date );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}
		$timestamp = strtotime( $date );
		return false === $timestamp ? '' : gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Get imports.
	 *
	 * @return array
	 */
	private static function get_imports() {
		$imports = get_option( self::IMPORTS_OPTION, array() );
		return is_array( $imports ) ? $imports : array();
	}

	/**
	 * Get rows.
	 *
	 * @return array
	 */
	private static function get_rows() {
		$rows = get_option( self::ROWS_OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Generate import ID.
	 *
	 * @return string
	 */
	private static function generate_import_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return 'spi_' . sanitize_key( wp_generate_uuid4() );
		}
		return 'spi_' . md5( uniqid( '', true ) );
	}
}
