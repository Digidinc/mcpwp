<?php
/**
 * Site graph signals — aggregate-only, per-node-per-period metrics from a
 * named source (PostHog, GSC, Cloudflare, GA4, …). No visitor-level data,
 * no PII (#943 S1).
 *
 * The fetchers themselves (#945, #947, #956) are out of scope here; this
 * class defines the one write entry point they all go through.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upsert + prune for `{prefix}mcpwp_graph_signals`.
 */
class Mcpwp_Graph_Signals {

	/** Cron hook for the daily retention prune. */
	const PRUNE_CRON_HOOK = 'mcpwp_graph_signals_prune';

	/** Default retention window in days — filterable via mcpwp_graph_signal_retention_days. */
	const DEFAULT_RETENTION_DAYS = 400;

	/**
	 * Upsert signal rows. Idempotent: a re-import of the same
	 * node_key+metric+source+period never duplicates, it replaces the value.
	 *
	 * @param array  $rows   Each: {node_key, metric, value, period_start?, period_end?, fetched_at?}.
	 * @param string $source Source tag (posthog|gsc|bing|cloudflare|ga4|clarity|manual).
	 * @return int Number of rows written.
	 */
	public static function upsert( array $rows, string $source ): int {
		if ( empty( $rows ) || '' === trim( $source ) ) {
			return 0;
		}

		global $wpdb;
		$table   = $wpdb->prefix . 'mcpwp_graph_signals';
		$written = 0;

		foreach ( $rows as $row ) {
			$node_key = (string) ( $row['node_key'] ?? '' );
			$metric   = (string) ( $row['metric'] ?? '' );
			if ( '' === $node_key || '' === $metric ) {
				continue;
			}

			$period_start = self::normalize_date( $row['period_start'] ?? null );
			$period_end   = self::normalize_date( $row['period_end'] ?? $row['period_start'] ?? null );
			$fetched_at   = ! empty( $row['fetched_at'] ) ? (string) $row['fetched_at'] : current_time( 'mysql' );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (node_key, metric, value, period_start, period_end, source, fetched_at)
					VALUES (%s, %s, %f, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE value = VALUES(value), fetched_at = VALUES(fetched_at)",
					$node_key,
					$metric,
					(float) ( $row['value'] ?? 0 ),
					$period_start,
					$period_end,
					$source,
					$fetched_at
				)
			);

			$written++;
		}

		return $written;
	}

	/**
	 * Normalize a period boundary to Y-m-d, defaulting to today.
	 *
	 * @param mixed $value Raw date value.
	 * @return string
	 */
	private static function normalize_date( $value ): string {
		if ( empty( $value ) ) {
			return current_time( 'Y-m-d' );
		}
		$ts = strtotime( (string) $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : current_time( 'Y-m-d' );
	}

	// -------------------------------------------------------------------------
	// Retention
	// -------------------------------------------------------------------------

	/**
	 * Schedule the daily prune cron.
	 */
	public static function schedule_prune(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( self::PRUNE_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_CRON_HOOK );
		}
	}

	/**
	 * Unschedule the daily prune cron.
	 */
	public static function unschedule_prune(): void {
		$ts = wp_next_scheduled( self::PRUNE_CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::PRUNE_CRON_HOOK );
		}
	}

	/**
	 * Delete signal rows older than the retention window.
	 *
	 * @return int Rows deleted (or affected, per $wpdb->query()'s return).
	 */
	public static function prune(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_graph_signals';

		/**
		 * Filters how many days of signal history are retained.
		 *
		 * @param int $days Retention window in days.
		 */
		$days   = (int) apply_filters( 'mcpwp_graph_signal_retention_days', self::DEFAULT_RETENTION_DAYS );
		$days   = max( 1, $days );
		$cutoff = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE period_end < %s", $cutoff ) );

		return false === $result ? 0 : (int) $result;
	}
}
