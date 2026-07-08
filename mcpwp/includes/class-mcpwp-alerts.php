<?php
/**
 * Alerts & monitoring
 *
 * Sends webhook alerts when error rates spike.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alerts helper.
 */
class Mcpwp_Alerts {

	/**
	 * Webhook event for 5xx spike.
	 */
	const EVENT_5XX_SPIKE = 'api.alert.5xx_spike';

	/**
	 * Webhook event for 401/403 spike.
	 */
	const EVENT_AUTH_SPIKE = 'api.alert.auth_spike';

	/**
	 * Check alerts and trigger webhooks if thresholds exceeded.
	 */
	public function check_alerts() {
		$settings = get_option( 'mcpwp_settings', array() );
		if ( empty( $settings['alerts_enabled'] ) ) {
			return;
		}

		$window_minutes = isset( $settings['alerts_window_minutes'] ) ? max( 1, absint( $settings['alerts_window_minutes'] ) ) : 5;
		$cooldown       = isset( $settings['alerts_cooldown_minutes'] ) ? max( 1, absint( $settings['alerts_cooldown_minutes'] ) ) : 15;
		$thresh_5xx     = isset( $settings['alerts_5xx_threshold'] ) ? max( 1, absint( $settings['alerts_5xx_threshold'] ) ) : 5;
		$thresh_auth    = isset( $settings['alerts_auth_threshold'] ) ? max( 1, absint( $settings['alerts_auth_threshold'] ) ) : 10;

		$since = gmdate( 'Y-m-d H:i:s', time() - ( $window_minutes * MINUTE_IN_SECONDS ) );

		$stats_5xx  = $this->get_status_stats( $since, 500, 599 );
		$stats_auth = $this->get_status_in_stats( $since, array( 401, 403 ) );

		if ( $stats_5xx['count'] >= $thresh_5xx ) {
			$this->maybe_trigger_alert(
				self::EVENT_5XX_SPIKE,
				$cooldown,
				array(
					'window_minutes' => $window_minutes,
					'threshold'      => $thresh_5xx,
					'count'          => $stats_5xx['count'],
					'top_endpoints'  => $stats_5xx['top_endpoints'],
				)
			);
		}

		if ( $stats_auth['count'] >= $thresh_auth ) {
			$this->maybe_trigger_alert(
				self::EVENT_AUTH_SPIKE,
				$cooldown,
				array(
					'window_minutes' => $window_minutes,
					'threshold'      => $thresh_auth,
					'count'          => $stats_auth['count'],
					'top_endpoints'  => $stats_auth['top_endpoints'],
				)
			);
		}
	}

	/**
	 * Trigger an alert with cooldown.
	 *
	 * @param string $event Event name.
	 * @param int    $cooldown_minutes Cooldown window.
	 * @param array  $payload Payload.
	 */
	private function maybe_trigger_alert( $event, $cooldown_minutes, $payload ) {
		$cooldown_key = 'mcpwp_alert_cooldown_' . md5( (string) $event );
		if ( get_transient( $cooldown_key ) ) {
			return;
		}

		$webhooks = Mcpwp_Webhooks::get_instance();
		$webhooks->trigger( $event, $payload );

		set_transient( $cooldown_key, 1, $cooldown_minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Get aggregated status stats for a status code range.
	 *
	 * @param string $since MySQL datetime cutoff.
	 * @param int    $min Minimum status.
	 * @param int    $max Maximum status.
	 * @return array Stats.
	 */
	private function get_status_stats( $since, $min, $max ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status_code BETWEEN %d AND %d",
				$since,
				$min,
				$max
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$top = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT endpoint, COUNT(*) as count
				 FROM {$table}
				 WHERE created_at >= %s AND status_code BETWEEN %d AND %d
				 GROUP BY endpoint
				 ORDER BY count DESC
				 LIMIT 5",
				$since,
				$min,
				$max
			),
			ARRAY_A
		);

		return array(
			'count'         => $count,
			'top_endpoints' => is_array( $top ) ? $top : array(),
		);
	}

	/**
	 * Get aggregated stats for a list of status codes.
	 *
	 * @param string $since Cutoff.
	 * @param array  $codes Status codes.
	 * @return array Stats.
	 */
	private function get_status_in_stats( $since, $codes ) {
		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';

		$codes = array_values( array_filter( array_map( 'absint', (array) $codes ) ) );
		if ( empty( $codes ) ) {
			return array( 'count' => 0, 'top_endpoints' => array() );
		}

		$placeholders = implode( ',', array_fill( 0, count( $codes ), '%d' ) );
		$params = array_merge( array( $since ), $codes );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND status_code IN ({$placeholders})",
				$params
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$top = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT endpoint, COUNT(*) as count
				 FROM {$table}
				 WHERE created_at >= %s AND status_code IN ({$placeholders})
				 GROUP BY endpoint
				 ORDER BY count DESC
				 LIMIT 5",
				$params
			),
			ARRAY_A
		);

		return array(
			'count'         => $count,
			'top_endpoints' => is_array( $top ) ? $top : array(),
		);
	}
}

