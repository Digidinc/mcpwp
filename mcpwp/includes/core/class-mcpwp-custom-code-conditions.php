<?php
/**
 * Custom Code conditions compatibility boundary.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps an unconvertible snippet out of Elementor Pro's cache without
 * changing its stored metadata during a read.
 */
class Mcpwp_Custom_Code_Conditions {
	const QUARANTINE_OPTION = 'mcpwp_custom_code_conditions_quarantined';
	const REPORT_OPTION     = 'mcpwp_custom_code_conditions_report';
	const REPORT_MARKER     = 'mcpwp_custom_code_conditions_report_marker';
	const FINGERPRINT_META  = '_mcpwp_conditions_quarantine_fingerprint';
	const REPORT_PREVIEW    = 20;

	private static $request_skipped = array();
	private static $cache_bypass    = false;

	public static function register_hooks() {
		// This is Elementor Pro's own rebuild query. Excluding unsafe IDs here
		// means Pro never calls explode() on their raw legacy arrays.
		add_filter( 'elementor/theme/conditions/cache/regenerate/query_args', array( __CLASS__, 'filter_regenerate_query' ) );
		add_filter( 'option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'filter_conditions_cache' ) );
		add_action( 'admin_init', array( __CLASS__, 'refresh_admin_report' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'invalidate_report_on_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'invalidate_report_on_meta' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'invalidate_report_on_meta' ), 10, 4 );
		add_action( 'save_post', array( __CLASS__, 'invalidate_report_on_post' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'invalidate_report_on_post' ), 10, 2 );
	}

	/**
	 * Convert every member or reject the whole condition set. A partial result
	 * could silently remove an exclusion and broaden executable code's scope.
	 *
	 * @param array $conditions Elementor maps or persisted strings.
	 * @return string[]|WP_Error
	 */
	public static function convert( array $conditions ) {
		$out = array();
		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) ) {
				$parts = explode( '/', $condition );
				if ( count( $parts ) < 2 || count( $parts ) > 4 ) {
					return self::invalid();
				}
				$condition = array(
					'type'     => $parts[0],
					'name'     => $parts[1],
					'sub_name' => isset( $parts[2] ) ? $parts[2] : '',
					'sub_id'   => isset( $parts[3] ) ? $parts[3] : '',
				);
			}
			if ( ! is_array( $condition ) || ! isset( $condition['type'], $condition['name'] ) ) {
				return self::invalid();
			}
			$parts = array();
			foreach ( array( 'type', 'name', 'sub_name', 'sub_id' ) as $key ) {
				$value = isset( $condition[ $key ] ) ? $condition[ $key ] : '';
				if ( ! is_scalar( $value ) ) {
					return self::invalid();
				}
				$raw = (string) $value;
				if ( '' !== $raw && $raw !== sanitize_text_field( $raw ) ) {
					return self::invalid();
				}
				if ( false !== strpos( $raw, '/' ) ) {
					return self::invalid();
				}
				$parts[ $key ] = $raw;
			}
			if ( ! in_array( $parts['type'], array( 'include', 'exclude' ), true ) || '' === $parts['name'] || ( '' !== $parts['sub_id'] && '' === $parts['sub_name'] ) ) {
				return self::invalid();
			}
			$string = $parts['type'] . '/' . $parts['name'];
			if ( '' !== $parts['sub_name'] ) {
				$string .= '/' . $parts['sub_name'];
			}
			if ( '' !== $parts['sub_id'] ) {
				$string .= '/' . $parts['sub_id'];
			}
			$out[] = $string;
		}
		return $out;
	}

	/** Raw stored conditions; API reads must use this instead of a cache view. */
	public static function raw_conditions( $post_id ) {
		$value = get_post_meta( (int) $post_id, '_elementor_conditions', true );
		return is_array( $value ) ? $value : array();
	}

	/** Add unsafe snippets to Pro's own regeneration query exclusion list. */
	public static function filter_regenerate_query( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		// This callback runs only on Elementor's write-side rebuild. Repair
		// convertible legacy maps before Pro sees them. A failed concurrent
		// compare-and-swap still leaves a non-string value below, so it is
		// excluded from THIS rebuild rather than risking explode(array).
		if ( class_exists( 'Mcpwp_Activator' ) && method_exists( 'Mcpwp_Activator', 'ensure_custom_code_conditions_repaired' ) ) {
			Mcpwp_Activator::ensure_custom_code_conditions_repaired();
		}
		$ids = self::regenerate_excluded_ids();
		if ( ! empty( $ids ) ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $ids ) ) );
		}
		return $args;
	}

	/** Remove unsafe cache entries when Pro reads the persisted cache. */
	public static function filter_conditions_cache( $cache ) {
		if ( self::$cache_bypass || ! is_array( $cache ) ) {
			return $cache;
		}
		foreach ( $cache as $location => $templates ) {
			if ( ! is_array( $templates ) ) {
				continue;
			}
			foreach ( $templates as $post_id => $conditions ) {
				if ( ! self::is_snippet( $post_id ) ) {
					continue;
				}
				$converted = self::convert( self::raw_conditions( $post_id ) );
				if ( is_wp_error( $converted ) ) {
					unset( $cache[ $location ][ $post_id ] );
					self::mark_skipped( $post_id );
				} else {
					$cache[ $location ][ $post_id ] = $converted;
				}
			}
		}
		return $cache;
	}

	/**
	 * Persist an operator receipt and evict this snippet from the on-disk cache.
	 * Request-only isolation never writes.
	 */
	public static function quarantine( $post_id, array $raw, $persist = true ) {
		self::mark_skipped( $post_id );
		if ( ! $persist ) {
			return true;
		}
		return self::with_cache_write_lock(
			static function () use ( $post_id, $raw ) {
				return self::quarantine_locked( $post_id, $raw );
			}
		);
	}

	private static function quarantine_locked( $post_id, array $raw ) {
		$fingerprint = hash( 'sha256', wp_json_encode( $raw ) );
		$new = $fingerprint !== (string) get_post_meta( $post_id, self::FINGERPRINT_META, true );
		$all = self::quarantine_ids();
		if ( ! in_array( (int) $post_id, $all, true ) ) {
			$all[] = (int) $post_id;
			sort( $all );
			update_option( self::QUARANTINE_OPTION, $all, false );
		}
		if ( $new ) {
			update_post_meta( $post_id, self::FINGERPRINT_META, $fingerprint );
		}
		$result = self::evict_persisted_cache_entry( $post_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $new ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP Custom Code conditions skipped for snippet ' . (int) $post_id . '.' );
		}
		return true;
	}

	public static function clear_quarantine( $post_id ) {
		return self::with_cache_write_lock(
			static function () use ( $post_id ) {
				return self::clear_quarantine_locked( $post_id );
			}
		);
	}

	private static function clear_quarantine_locked( $post_id ) {
		$all = array_values( array_diff( self::quarantine_ids(), array( (int) $post_id ) ) );
		if ( $all !== self::quarantine_ids() ) {
			update_option( self::QUARANTINE_OPTION, $all, false );
		}
		delete_post_meta( $post_id, self::FINGERPRINT_META );
		unset( self::$request_skipped[ (int) $post_id ] );
		return true;
	}

	/** @return int[] Current unsafe IDs, excluding repaired historical receipts. */
	public static function skipped_ids() {
		$ids = self::invalid_snippet_ids();
		foreach ( array_keys( self::$request_skipped ) as $id ) {
			if ( is_wp_error( self::convert( self::raw_conditions( $id ) ) ) ) {
				$ids[] = (int) $id;
			}
		}
		foreach ( self::quarantine_ids() as $id ) {
				if ( is_wp_error( self::convert( self::raw_conditions( $id ) ) ) ) {
					$ids[] = (int) $id;
				}
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		return $ids;
	}

	public static function annotate_response( array $data ) {
		$ids = self::report_ids();
		if ( ! empty( $ids ) ) {
			$data['skipped_custom_code_snippet_ids'] = $ids;
		}
		return $data;
	}

	/**
	 * Persist operator receipts only on an administrator-controlled request.
	 * Runtime cache/API guards deliberately do not write while serving reads.
	 */
	public static function refresh_admin_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$marker = self::report_marker();
		$report = get_option( self::REPORT_OPTION, array() );
		if ( is_array( $report ) && isset( $report['marker'], $report['ids'] ) && (string) $report['marker'] === $marker && is_array( $report['ids'] ) ) {
			return;
		}
		$ids = self::skipped_ids();
		$result = self::quarantine_many( $ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Keep the marker sampled before the scan. A concurrent snippet change
		// then leaves this report stale and forces the next admin refresh.
		update_option( self::REPORT_OPTION, array( 'marker' => $marker, 'ids' => self::quarantine_ids() ), false );
	}

	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ids = self::report_ids();
		if ( empty( $ids ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( self::report_summary( $ids ) ) . '</p></div>';
	}

	public static function register_site_health_test( $tests ) {
		$tests['direct']['mcpwp_custom_code_conditions'] = array( 'label' => __( 'Custom Code condition compatibility', 'mcpwp' ), 'test' => array( __CLASS__, 'site_health_test' ) );
		return $tests;
	}

	public static function site_health_test() {
		$ids = self::report_ids();
		return array(
			'label' => empty( $ids ) ? __( 'Custom Code conditions are compatible', 'mcpwp' ) : __( 'Some Custom Code conditions were skipped', 'mcpwp' ),
			'status' => empty( $ids ) ? 'good' : 'recommended',
			'badge' => array( 'label' => 'MCPWP', 'color' => 'blue' ),
			'description' => empty( $ids ) ? '<p>OK</p>' : '<p>' . esc_html( self::report_summary( $ids ) ) . '</p>',
			'actions' => '',
			'test' => 'mcpwp_custom_code_conditions',
		);
	}

	/**
	 * Full cached ID list for an authorized API response. A cold or stale
	 * report is calculated without writing so a normal API read stays pure.
	 *
	 * @return int[]
	 */
	public static function report_ids() {
		$report = get_option( self::REPORT_OPTION, array() );
		if ( is_array( $report ) && isset( $report['marker'], $report['ids'] ) && (string) $report['marker'] === self::report_marker() && is_array( $report['ids'] ) ) {
			return array_values( array_unique( array_map( 'intval', $report['ids'] ) ) );
		}
		return self::skipped_ids();
	}

	/** @return int[] Compact durable list; accepts the old fingerprint map. */
	private static function quarantine_ids() {
		$stored = get_option( self::QUARANTINE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		if ( empty( $stored ) ) {
			return array();
		}
		// New storage is a compact value list. Earlier releases used
		// `{ post_id => fingerprint }`; distinguish them without array_is_list
		// so PHP 7.4 remains supported.
		$is_list = array_keys( $stored ) === range( 0, count( $stored ) - 1 );
		$ids = $is_list ? array_values( $stored ) : array_keys( $stored );
		$ids = array_map( 'intval', $ids );
		$ids = array_values( array_filter( array_unique( $ids ) ) );
		sort( $ids );
		return $ids;
	}

	/** Persist many unsafe IDs with one list write and one cache write. */
	private static function quarantine_many( array $ids ) {
		return self::with_cache_write_lock(
			static function () use ( $ids ) {
				return self::quarantine_many_locked( $ids );
			}
		);
	}

	private static function quarantine_many_locked( array $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		if ( $ids !== get_option( self::QUARANTINE_OPTION, array() ) ) {
			update_option( self::QUARANTINE_OPTION, $ids, false );
		}

		$cache = self::persisted_cache_without_locked( $ids );
		if ( null !== $cache ) {
			self::$cache_bypass = true;
			try {
				update_option( 'elementor_pro_theme_builder_conditions', $cache, false );
			} finally {
				self::$cache_bypass = false;
			}
		}

		foreach ( $ids as $post_id ) {
			$raw = self::raw_conditions( $post_id );
			$fingerprint = hash( 'sha256', wp_json_encode( $raw ) );
			if ( $fingerprint === (string) get_post_meta( $post_id, self::FINGERPRINT_META, true ) ) {
				continue;
			}
			update_post_meta( $post_id, self::FINGERPRINT_META, $fingerprint );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP Custom Code conditions skipped for snippet ' . $post_id . '.' );
		}
		return true;
	}

	/** @return array|null Changed persisted cache, or null when no change. */
	private static function persisted_cache_without( array $ids ) {
		return self::with_cache_write_lock(
			static function () use ( $ids ) {
				return self::persisted_cache_without_locked( $ids );
			}
		);
	}

	/** @return array|null Changed persisted cache, or null when no change. */
	private static function persisted_cache_without_locked( array $ids ) {
		self::$cache_bypass = true;
		try {
			$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
		} finally {
			self::$cache_bypass = false;
		}
		if ( ! is_array( $cache ) ) {
			return null;
		}
		$changed = false;
		foreach ( $cache as $location => $templates ) {
			if ( ! is_array( $templates ) ) {
				continue;
			}
			foreach ( $ids as $post_id ) {
				if ( array_key_exists( $post_id, $cache[ $location ] ) ) {
					unset( $cache[ $location ][ $post_id ] );
					$changed = true;
				}
			}
		}
		return $changed ? $cache : null;
	}

	/**
	 * Service writers share the document/cache mutex with REST and Theme
	 * Builder writers. Isolated service tests intentionally run without the
	 * activator, where there is no shared cache writer to serialize against.
	 */
	private static function with_cache_write_lock( callable $callback ) {
		if ( ! class_exists( 'Mcpwp_Activator' ) || ! method_exists( 'Mcpwp_Activator', 'acquire_custom_code_cache_lock' ) ) {
			return call_user_func( $callback );
		}
		$token = Mcpwp_Activator::acquire_custom_code_cache_lock();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		try {
			return call_user_func( $callback );
		} finally {
			Mcpwp_Activator::release_custom_code_cache_lock( $token );
		}
	}

	/** Invalidate only when the underlying snippet set may have changed. */
	public static function invalidate_report_on_meta( $meta_id, $post_id, $meta_key ) {
		if ( '_elementor_conditions' === $meta_key && self::is_snippet( $post_id ) ) {
			self::bump_report_marker();
		}
	}

	public static function invalidate_report_on_post( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( $post && 'elementor_snippet' === $post->post_type ) {
			self::bump_report_marker();
		}
	}

	private static function report_marker() {
		return (string) get_option( self::REPORT_MARKER, '' );
	}

	private static function bump_report_marker() {
		update_option( self::REPORT_MARKER, wp_generate_uuid4(), false );
	}

	private static function report_summary( array $ids ) {
		$preview = array_slice( $ids, 0, self::REPORT_PREVIEW );
		$tail = count( $ids ) > self::REPORT_PREVIEW ? ' …' : '';
		return sprintf( 'MCPWP skipped Custom Code conditions for %d snippet(s): %s%s.', count( $ids ), implode( ', ', $preview ), $tail );
	}

	private static function invalid_snippet_ids() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}
		$cursor = 0;
		$bad = array();
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d", 'elementor_snippet', $cursor, 200 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) ) {
				return $bad;
			}
			foreach ( $ids as $id ) {
				$id = (int) $id;
				if ( is_wp_error( self::convert( self::raw_conditions( $id ) ) ) ) {
					$bad[] = $id;
					self::mark_skipped( $id );
				}
				$cursor = $id;
			}
		} while ( 200 === count( $ids ) );
		return $bad;
	}

	/** @return int[] Invalid and not-yet-persisted legacy records. */
	private static function regenerate_excluded_ids() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}
		$cursor = 0;
		$excluded = array();
		do {
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d", 'elementor_snippet', $cursor, 200 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared
			if ( ! is_array( $ids ) || ! empty( $wpdb->last_error ) ) {
				return $excluded;
			}
			foreach ( $ids as $id ) {
				$id = (int) $id;
				$raw = self::raw_conditions( $id );
				$non_string = false;
				foreach ( $raw as $condition ) {
					if ( ! is_string( $condition ) ) {
						$non_string = true;
						break;
					}
				}
				if ( $non_string || is_wp_error( self::convert( $raw ) ) ) {
					$excluded[] = $id;
					self::mark_skipped( $id );
				}
				$cursor = $id;
			}
		} while ( 200 === count( $ids ) );
		return $excluded;
	}

	private static function evict_persisted_cache_entry( $post_id ) {
		return self::with_cache_write_lock(
			static function () use ( $post_id ) {
				return self::evict_persisted_cache_entry_locked( $post_id );
			}
		);
	}

	private static function evict_persisted_cache_entry_locked( $post_id ) {
		self::$cache_bypass = true;
		try {
			$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
			if ( ! is_array( $cache ) ) {
				return true;
			}
			foreach ( $cache as $location => $templates ) {
				if ( is_array( $templates ) ) {
					unset( $cache[ $location ][ (int) $post_id ] );
				}
			}
			update_option( 'elementor_pro_theme_builder_conditions', $cache, false );
		} finally {
			self::$cache_bypass = false;
		}
		return true;
	}

	private static function invalid() {
		return new WP_Error( 'invalid_custom_code_conditions', __( 'Custom Code conditions could not be converted safely.', 'mcpwp' ) );
	}

	private static function is_snippet( $post_id ) {
		$post = get_post( (int) $post_id );
		return $post && 'elementor_snippet' === $post->post_type;
	}

	private static function mark_skipped( $post_id ) {
		self::$request_skipped[ (int) $post_id ] = true;
	}
}
