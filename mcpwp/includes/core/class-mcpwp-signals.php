<?php
/**
 * Proactive site signal feed — computes and stores actionable signals for AI consumption.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Computes, stores, and retrieves site signals.
 *
 * Signal types: stale_content, broken_elementor, missing_featured_image,
 *               draft_accumulation, pending_update, seo_issue
 */
class Mcpwp_Signals {

	const OPTION_KEY     = 'mcpwp_signals';
	const META_KEY       = 'mcpwp_signals_meta';
	const SETTINGS_KEY   = 'mcpwp_signal_settings';
	const CRON_HOOK      = 'mcpwp_compute_signals';
	const CRON_INTERVAL  = 'daily';

	const SIGNAL_TYPES = array(
		'stale_content',
		'broken_elementor',
		'missing_featured_image',
		'draft_accumulation',
		'pending_update',
		'seo_issue',
	);

	// -------------------------------------------------------------------------
	// Cron setup

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	// -------------------------------------------------------------------------
	// Public API

	/**
	 * Retrieve stored signals, optionally filtered.
	 *
	 * @param array  $types  Signal type filter.
	 * @param string $since  ISO timestamp — return signals detected after this time.
	 * @param int    $limit  Max results.
	 * @return array
	 */
	public static function get_signals( array $types = array(), string $since = '', int $limit = 50 ): array {
		$all = self::load();

		$results = array();
		foreach ( $all as $signal ) {
			if ( ! empty( $types ) && ! in_array( $signal['type'] ?? '', $types, true ) ) {
				continue;
			}
			if ( $since && ( $signal['detected_at'] ?? '' ) < $since ) {
				continue;
			}
			$results[] = $signal;
		}

		usort(
			$results,
			function ( $a, $b ) {
				$sev = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
				$sa  = $sev[ $a['severity'] ?? 'low' ] ?? 2;
				$sb  = $sev[ $b['severity'] ?? 'low' ] ?? 2;
				if ( $sa !== $sb ) {
					return $sa - $sb;
				}
				return strcmp( $b['detected_at'] ?? '', $a['detected_at'] ?? '' );
			}
		);

		return array_slice( $results, 0, max( 1, $limit ) );
	}

	/**
	 * Compute signals and persist. Returns the signals computed in this run.
	 *
	 * Safe to call from a web request: pass a $time_budget and computation
	 * stops between signal types once the budget is exhausted (at least one
	 * type is always computed). Skipped types keep their previously stored
	 * signals. Cron calls with no budget compute everything.
	 *
	 * @param array $types       Compute only these types (empty = all).
	 * @param float $time_budget Max seconds to spend; 0 = unlimited.
	 * @return array
	 */
	public static function compute( array $types = array(), float $time_budget = 0 ): array {
		$start     = microtime( true );
		$settings  = self::get_settings();
		$requested = empty( $types )
			? self::SIGNAL_TYPES
			: array_values( array_intersect( $types, self::SIGNAL_TYPES ) );
		$signals   = array();
		$computed  = array();
		$skipped   = array();
		$now       = gmdate( 'c' );

		foreach ( $requested as $type ) {
			if ( $time_budget > 0 && ! empty( $computed ) && ( microtime( true ) - $start ) > $time_budget ) {
				$skipped[] = $type;
				continue;
			}

			// Respect per-type enabled setting. A disabled type still counts as
			// computed so its stale stored signals are cleared below.
			if ( isset( $settings['enabled_types'] ) && is_array( $settings['enabled_types'] )
				&& ! in_array( $type, $settings['enabled_types'], true ) ) {
				$computed[] = $type;
				continue;
			}

			$result = self::compute_type( $type, $settings );
			foreach ( $result as $signal ) {
				$signal['detected_at'] = $now;
				$signals[]             = $signal;
			}
			$computed[] = $type;
		}

		// Replace stored signals of the types actually computed; preserve the
		// rest (including any types skipped by the time budget).
		$existing = array_values(
			array_filter(
				self::load(),
				function ( $s ) use ( $computed ) {
					return ! in_array( $s['type'] ?? '', $computed, true );
				}
			)
		);
		update_option( self::OPTION_KEY, array_merge( $existing, $signals ), false );

		update_option(
			self::META_KEY,
			array(
				'last_computed'  => $now,
				'duration_ms'    => (int) round( ( microtime( true ) - $start ) * 1000 ),
				'computed_types' => $computed,
				'skipped_types'  => $skipped,
				'partial'        => ! empty( $skipped ),
			),
			false
		);

		// Fire webhooks for HIGH-severity signals.
		$high = array_filter( $signals, fn( $s ) => ( $s['severity'] ?? '' ) === 'high' );
		if ( ! empty( $high ) ) {
			self::maybe_fire_webhooks( array_values( $high ) );
		}

		return $signals;
	}

	/**
	 * Computation metadata — lets consumers distinguish "no issues" from
	 * "signals were never computed" and detect partial runs.
	 *
	 * @return array { last_computed, duration_ms, computed_types, skipped_types, partial }
	 */
	public static function get_meta(): array {
		$defaults = array(
			'last_computed'  => '',
			'duration_ms'    => 0,
			'computed_types' => array(),
			'skipped_types'  => array(),
			'partial'        => false,
		);
		$meta = get_option( self::META_KEY, array() );
		return array_merge( $defaults, is_array( $meta ) ? $meta : array() );
	}

	/**
	 * Seconds of compute we can safely spend inside a web request.
	 *
	 * @return float
	 */
	public static function request_time_budget(): float {
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max <= 0 ) {
			return 15.0;
		}
		return max( 5.0, min( 15.0, (float) $max - 5.0 ) );
	}

	// -------------------------------------------------------------------------
	// Settings

	public static function get_settings(): array {
		$defaults = array(
			'enabled_types'       => self::SIGNAL_TYPES,
			'stale_days'          => 90,
			'draft_accumulation'  => 10,
			'cron_frequency'      => 'daily',
		);
		$stored = get_option( self::SETTINGS_KEY, array() );
		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	public static function save_settings( array $settings ): void {
		$clean = array(
			'enabled_types'      => array_intersect( $settings['enabled_types'] ?? self::SIGNAL_TYPES, self::SIGNAL_TYPES ),
			'stale_days'         => max( 1, absint( $settings['stale_days'] ?? 90 ) ),
			'draft_accumulation' => max( 1, absint( $settings['draft_accumulation'] ?? 10 ) ),
			'cron_frequency'     => in_array( $settings['cron_frequency'] ?? 'daily', array( 'hourly', 'twicedaily', 'daily' ), true )
								? $settings['cron_frequency']
								: 'daily',
		);
		update_option( self::SETTINGS_KEY, $clean, false );
	}

	// -------------------------------------------------------------------------
	// Signal computers

	private static function compute_type( string $type, array $settings ): array {
		switch ( $type ) {
			case 'stale_content':
				return self::compute_stale_content( $settings );
			case 'broken_elementor':
				return self::compute_broken_elementor();
			case 'missing_featured_image':
				return self::compute_missing_featured_image();
			case 'draft_accumulation':
				return self::compute_draft_accumulation( $settings );
			case 'pending_update':
				return self::compute_pending_updates();
			case 'seo_issue':
				return self::compute_seo_issues();
			default:
				return array();
		}
	}

	private static function compute_stale_content( array $settings ): array {
		$days    = absint( $settings['stale_days'] ?? 90 );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$signals = array();

		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'date_query'     => array(
				array(
					'column' => 'post_modified_gmt',
					'before' => $cutoff,
				),
			),
			'orderby'        => 'modified',
			'order'          => 'ASC',
		) );

		foreach ( $posts as $post ) {
			$signals[] = array(
				'type'         => 'stale_content',
				'severity'     => 'low',
				'entity_id'    => $post->ID,
				'entity_title' => $post->post_title,
				'entity_type'  => $post->post_type,
				'detail'       => sprintf( 'Not updated in over %d days (last: %s)', $days, $post->post_modified ),
				'action_hint'  => 'Consider reviewing and refreshing this content.',
			);
		}

		return $signals;
	}

	private static function compute_broken_elementor(): array {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return array();
		}

		global $wpdb;
		$signals = array();

		// Find pages with _elementor_data meta that is invalid JSON. Fetch IDs
		// only — loading 100 Elementor data blobs in one result set can exhaust
		// memory on Elementor-heavy sites. The blobs are read one at a time.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- uses only core $wpdb table names; no user input in query.
		$results = $wpdb->get_results(
			"SELECT p.ID, p.post_title
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_status = 'publish'
			   AND pm.meta_key = '_elementor_data'
			   AND pm.meta_value <> ''
			 LIMIT 100",
			ARRAY_A
		);

		foreach ( $results as $row ) {
			$raw = get_post_meta( (int) $row['ID'], '_elementor_data', true );
			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}
			$decoded = json_decode( $raw, true );
			unset( $raw );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$signals[] = array(
					'type'         => 'broken_elementor',
					'severity'     => 'high',
					'entity_id'    => (int) $row['ID'],
					'entity_title' => $row['post_title'],
					'entity_type'  => 'page',
					'detail'       => 'Elementor data contains invalid JSON — page may render broken.',
					'action_hint'  => 'Use wp_get_elementor then wp_set_elementor to rebuild the page data.',
				);
			} elseif ( ! is_array( $decoded ) ) {
				$signals[] = array(
					'type'         => 'broken_elementor',
					'severity'     => 'medium',
					'entity_id'    => (int) $row['ID'],
					'entity_title' => $row['post_title'],
					'entity_type'  => 'page',
					'detail'       => 'Elementor data is not a JSON array — likely malformed.',
					'action_hint'  => 'Use wp_get_elementor then wp_set_elementor to rebuild the page data.',
				);
			}
		}

		return $signals;
	}

	private static function compute_missing_featured_image(): array {
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'meta_query'     => array(
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				),
			),
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );

		$signals = array();
		foreach ( $posts as $post ) {
			// Only flag if theme/plugin actually supports featured images for this type.
			if ( ! post_type_supports( $post->post_type, 'thumbnail' ) ) {
				continue;
			}
			$signals[] = array(
				'type'         => 'missing_featured_image',
				'severity'     => 'low',
				'entity_id'    => $post->ID,
				'entity_title' => $post->post_title,
				'entity_type'  => $post->post_type,
				'detail'       => 'Published ' . $post->post_type . ' has no featured image.',
				'action_hint'  => 'Set a featured image with wp_set_featured_image.',
			);
		}

		return $signals;
	}

	private static function compute_draft_accumulation( array $settings ): array {
		$threshold = absint( $settings['draft_accumulation'] ?? 10 );
		$count     = (int) wp_count_posts( 'post' )->draft + (int) wp_count_posts( 'page' )->draft;

		if ( $count < $threshold ) {
			return array();
		}

		return array(
			array(
				'type'         => 'draft_accumulation',
				'severity'     => 'low',
				'entity_id'    => 0,
				'entity_title' => 'Drafts',
				'entity_type'  => 'site',
				'detail'       => sprintf( '%d drafts accumulated (threshold: %d).', $count, $threshold ),
				'action_hint'  => 'Use wp_list_drafts to review and publish or delete old drafts.',
			),
		);
	}

	private static function compute_pending_updates(): array {
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			if ( ! file_exists( ABSPATH . 'wp-admin/includes/update.php' ) ) {
				return array();
			}
			require_once ABSPATH . 'wp-admin/includes/update.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Only refresh the update cache from cron — wp_update_plugins() makes
		// remote requests to wordpress.org for every installed plugin and must
		// never run inside a web/REST request (it can exceed the request budget
		// on its own). Web requests read the existing update_plugins transient.
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() && function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		$updates = get_plugin_updates();
		if ( empty( $updates ) ) {
			return array();
		}

		$signals = array();
		foreach ( $updates as $slug => $plugin ) {
			$signals[] = array(
				'type'         => 'pending_update',
				'severity'     => 'medium',
				'entity_id'    => 0,
				'entity_title' => $plugin->Name ?? $slug,
				'entity_type'  => 'plugin',
				'detail'       => sprintf(
					'%s: %s → %s',
					$plugin->Name ?? $slug,
					$plugin->Version ?? '?',
					$plugin->update->new_version ?? '?'
				),
				'action_hint'  => 'Update via WP Admin > Plugins.',
			);
		}

		return $signals;
	}

	private static function compute_seo_issues(): array {
		if ( ! class_exists( 'Mcpwp_SEO_Audit_Store' ) || ! method_exists( 'Mcpwp_SEO_Audit_Store', 'list_issues' ) ) {
			return array();
		}

		// Stored-issue severities are error/warning/info; surface open errors.
		$issues = Mcpwp_SEO_Audit_Store::list_issues(
			array(
				'status'   => 'open',
				'severity' => 'error',
				'limit'    => 10,
			)
		);
		if ( empty( $issues['issues'] ) ) {
			return array();
		}

		$signals = array();
		foreach ( $issues['issues'] as $issue ) {
			$signals[] = array(
				'type'         => 'seo_issue',
				'severity'     => 'medium',
				'entity_id'    => (int) ( $issue['post_id'] ?? 0 ),
				'entity_title' => $issue['title'] ?? 'Unknown',
				'entity_type'  => $issue['type'] ?? 'post',
				'detail'       => $issue['message'] ?? $issue['code'] ?? 'SEO issue detected',
				'action_hint'  => 'Use wp_seo_audit_site or wp_run_seo_autofix_plan.',
			);
		}

		return $signals;
	}

	// -------------------------------------------------------------------------
	// Webhook integration

	private static function maybe_fire_webhooks( array $high_signals ): void {
		if ( ! class_exists( 'Mcpwp_Webhooks' ) ) {
			return;
		}

		do_action(
			'mcpwp_event_emitted',
			'site_signals_high',
			array(
				'signals' => $high_signals,
				'count'   => count( $high_signals ),
			)
		);
	}

	// -------------------------------------------------------------------------

	private static function load(): array {
		$data = get_option( self::OPTION_KEY, array() );
		return is_array( $data ) ? $data : array();
	}
}
