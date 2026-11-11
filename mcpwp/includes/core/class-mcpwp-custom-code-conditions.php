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
	const REPORT_TTL        = 600;

	private static $request_skipped = array();
	private static $cache_bypass    = false;
	private static $raw_read_depth  = 0;

	public static function register_hooks() {
		// This is Elementor Pro's own rebuild query. Excluding unsafe IDs here
		// means Pro never calls explode() on their raw legacy arrays.
		add_filter( 'elementor/theme/conditions/cache/regenerate/query_args', array( __CLASS__, 'filter_regenerate_query' ) );
		add_filter( 'option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'filter_conditions_cache' ) );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_pro_rebuild_conditions_meta' ), 10, 5 );
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
		++self::$raw_read_depth;
		try {
			$value = get_post_meta( (int) $post_id, '_elementor_conditions', true );
		} finally {
			--self::$raw_read_depth;
		}
		return is_array( $value ) ? $value : array();
	}

	/** Add unsafe snippets to Pro's own regeneration query exclusion list. */
	public static function filter_regenerate_query( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		// A regeneration must not trigger a full-site repair scan. The cached
		// operator report excludes known unsafe records; the metadata boundary
		// below safely denies a late, out-of-band record during this rebuild.
		$ids = self::cached_report_ids();
		if ( ! empty( $ids ) ) {
			$args['post__not_in'] = array_values( array_unique( array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $ids ) ) );
		}
		return $args;
	}

	/**
	 * Elementor Pro reads this meta while rebuilding its cache. Only on that
	 * exact call stack, give an unconvertible snippet a safe deny condition.
	 * API reads use raw_conditions() and therefore still receive the stored
	 * value unchanged.
	 *
	 * @param mixed  $check     Short-circuit value.
	 * @param int    $post_id   Post ID.
	 * @param string $meta_key  Meta key.
	 * @param bool   $single    Whether one value was requested.
	 * @param string $meta_type Metadata type.
	 * @return mixed
	 */
	public static function filter_pro_rebuild_conditions_meta( $check, $post_id, $meta_key, $single, $meta_type ) {
		if ( null !== $check || 'post' !== $meta_type || '_elementor_conditions' !== $meta_key || self::$raw_read_depth > 0 || ! self::is_snippet( $post_id ) || ! self::in_elementor_cache_regeneration() ) {
			return $check;
		}
		$raw = self::raw_conditions( $post_id );
		$conditions = self::convert( $raw );
		if ( is_wp_error( $conditions ) ) {
			self::mark_skipped( $post_id );
			$conditions = array( 'exclude/general' );
		}
		// get_metadata() unwraps this outer row for single=true. Returning an
		// array-of-values for both shapes is the core metadata filter contract.
		return array( $conditions );
	}

	/** Remove unsafe cache entries when Pro reads the persisted cache. */
	public static function filter_conditions_cache( $cache ) {
		if ( self::$cache_bypass || ! is_array( $cache ) ) {
			return $cache;
		}
		// A cache option can contain thousands of template rows. Prime posts
		// and meta in bulk before classifying each Custom Code entry so the
		// safety filter does not turn one option read into N+1 queries.
		$ids = array();
		foreach ( $cache as $templates ) {
			if ( is_array( $templates ) ) {
				$ids = array_merge( $ids, array_keys( $templates ) );
			}
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! empty( $ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, true );
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
		return self::quarantine_locked( $post_id, $raw );
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
		if ( $new ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP Custom Code conditions skipped for snippet ' . (int) $post_id . '.' );
		}
		return true;
	}

	public static function clear_quarantine( $post_id ) {
		return self::clear_quarantine_locked( $post_id );
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

	public static function annotate_response( array $data, $allow_scan = true ) {
		$ids = $allow_scan ? self::report_ids() : self::cached_report_ids();
		if ( ! empty( $ids ) ) {
			$data['skipped_custom_code_snippet_ids'] = $ids;
		}
		$failed = self::cache_repair_failure_ids();
		if ( ! empty( $failed ) ) {
			$data['custom_code_cache_repair_failed_ids'] = $failed;
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
		require_once dirname( __DIR__ ) . '/class-mcpwp-activator.php';
		if ( Mcpwp_Activator::custom_code_cache_maintenance_due() ) {
			$failed = get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_REPAIR_FAILED_OPTION, array() );
			$rescan = get_option( Mcpwp_Activator::CUSTOM_CODE_CACHE_BACKFILL_DONE_OPTION ) && empty( $failed );
			Mcpwp_Activator::repair_custom_code_conditions( 0.25, $rescan );
		}
		$marker = self::report_marker();
		$report = get_option( self::REPORT_OPTION, array() );
		if ( self::report_is_fresh( $report, $marker ) ) {
			return;
		}
		$ids = self::skipped_ids();
		self::quarantine_many( $ids );
		update_option( self::REPORT_OPTION, array( 'marker' => $marker, 'ids' => self::quarantine_ids(), 'expires_at' => time() + self::REPORT_TTL ), false );
	}

	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ids = self::report_ids();
		$failed = self::cache_repair_failure_ids();
		if ( empty( $ids ) && empty( $failed ) ) {
			return;
		}
		$summary = empty( $ids ) ? '' : self::report_summary( $ids );
		if ( ! empty( $failed ) ) {
			$summary .= ' ' . self::cache_repair_failure_summary( $failed );
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( trim( $summary ) ) . '</p></div>';
	}

	public static function register_site_health_test( $tests ) {
		$tests['direct']['mcpwp_custom_code_conditions'] = array( 'label' => __( 'Custom Code condition compatibility', 'mcpwp' ), 'test' => array( __CLASS__, 'site_health_test' ) );
		return $tests;
	}

	public static function site_health_test() {
		$ids = self::report_ids();
		$failed = self::cache_repair_failure_ids();
		$healthy = empty( $ids ) && empty( $failed );
		$summary = empty( $ids ) ? '' : self::report_summary( $ids );
		if ( ! empty( $failed ) ) {
			$summary .= ' ' . self::cache_repair_failure_summary( $failed );
		}
		return array(
			'label' => $healthy ? __( 'Custom Code conditions are compatible', 'mcpwp' ) : __( 'Some Custom Code display entries need attention', 'mcpwp' ),
			'status' => $healthy ? 'good' : 'recommended',
			'badge' => array( 'label' => 'MCPWP', 'color' => 'blue' ),
			'description' => $healthy ? '<p>OK</p>' : '<p>' . esc_html( trim( $summary ) ) . '</p>',
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
		if ( self::report_is_fresh( $report, self::report_marker() ) ) {
			return array_values( array_unique( array_map( 'intval', $report['ids'] ) ) );
		}
		// API reads stay write-free, but an expired report must not leave a
		// direct SQL edit invisible indefinitely. This scan is bounded by the
		// ten-minute report TTL and never persists a new report.
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

	/** Persist many unsafe IDs with one compact list write. */
	private static function quarantine_many( array $ids ) {
		return self::quarantine_many_locked( $ids );
	}

	private static function quarantine_many_locked( array $ids ) {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		if ( $ids !== get_option( self::QUARANTINE_OPTION, array() ) ) {
			update_option( self::QUARANTINE_OPTION, $ids, false );
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

	private static function report_is_fresh( $report, $marker ) {
		return is_array( $report )
			&& isset( $report['marker'], $report['ids'], $report['expires_at'] )
			&& (string) $report['marker'] === $marker
			&& is_array( $report['ids'] )
			&& (int) $report['expires_at'] >= time();
	}

	/** @return int[] Cached report IDs without initiating a live scan. */
	private static function cached_report_ids() {
		$report = get_option( self::REPORT_OPTION, array() );
		if ( self::report_is_fresh( $report, self::report_marker() ) ) {
			return array_values( array_unique( array_map( 'intval', $report['ids'] ) ) );
		}
		return self::quarantine_ids();
	}

	private static function in_elementor_cache_regeneration() {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			if ( isset( $frame['class'], $frame['function'] ) && 'ElementorPro\\Modules\\ThemeBuilder\\Classes\\Conditions_Cache' === $frame['class'] && 'regenerate' === $frame['function'] ) {
				return true;
			}
		}
		return false;
	}

	private static function bump_report_marker() {
		update_option( self::REPORT_MARKER, wp_generate_uuid4(), false );
	}

	private static function cache_repair_failure_ids() {
		return array_values( array_unique( array_map( 'intval', (array) get_option( 'mcpwp_custom_code_cache_repair_failed_ids', array() ) ) ) );
	}

	private static function cache_repair_failure_summary( array $ids ) {
		return sprintf( 'Display-cache repair is pending for %d snippet(s): %s%s.', count( $ids ), implode( ', ', array_slice( $ids, 0, self::REPORT_PREVIEW ) ), count( $ids ) > self::REPORT_PREVIEW ? ' …' : '' );
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
			$wpdb->last_error = '';
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
