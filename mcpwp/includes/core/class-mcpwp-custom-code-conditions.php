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
	const MAX_CONDITIONS    = 200;
	const MAX_SERIALIZED_BYTES = 16384;
	const MAX_LEGACY_CACHE_BYTES = 1048576;
	const REASON_META       = '_mcpwp_conditions_quarantine_reason';
	const STORAGE_INITIALIZED_OPTION = 'mcpwp_custom_code_cache_entry_storage_initialized';

	private static $request_skipped = array();
	private static $cache_bypass    = false;
	private static $raw_read_depth  = 0;
	private static $inspection       = array();
	private static $condition_lengths = array();
	private static $meta_cache_guard = false;
	private static $legacy_cache_too_large = false;
	private static $entry_overlay    = null;

	public static function register_hooks() {
		// This is Elementor Pro's own rebuild query. Excluding unsafe IDs here
		// means Pro never calls explode() on their raw legacy arrays.
		add_filter( 'elementor/theme/conditions/cache/regenerate/query_args', array( __CLASS__, 'filter_regenerate_query' ) );
		add_filter( 'option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'filter_conditions_cache' ) );
		add_filter( 'pre_option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'filter_oversized_legacy_cache_option' ), 1, 3 );
		add_filter( 'update_post_metadata_cache', array( __CLASS__, 'filter_oversized_meta_cache_prime' ), 1, 2 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_oversized_snippet_meta' ), 1, 5 );
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
	 * Keep the legacy compatibility option non-autoloaded without reading its
	 * value. New installations get a small empty row; old oversized rows are
	 * never copied into alloptions on a later request.
	 */
	public static function initialize_legacy_cache_storage() {
		if ( get_option( self::STORAGE_INITIALIZED_OPTION ) ) {
			return;
		}
		$ok = true;
		if ( class_exists( 'Mcpwp_Encryption' ) ) {
			Mcpwp_Encryption::insert_option_row_if_absent( 'elementor_pro_theme_builder_conditions', maybe_serialize( array() ), 'no' );
		}
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) && ! empty( $wpdb->options ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deautoload without selecting the legacy blob.
			$ok = false !== $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET autoload = %s WHERE option_name = %s", 'no', 'elementor_pro_theme_builder_conditions' ) );
		}
		if ( class_exists( 'Mcpwp_Encryption' ) ) {
			Mcpwp_Encryption::clean_option_cache( 'elementor_pro_theme_builder_conditions' );
		}
		if ( $ok ) {
			wp_cache_delete( 'alloptions', 'options' );
			update_option( self::STORAGE_INITIALIZED_OPTION, 1, false );
		}
	}

	/**
	 * Convert every member or reject the whole condition set. A partial result
	 * could silently remove an exclusion and broaden executable code's scope.
	 *
	 * @param array $conditions Elementor maps or persisted strings.
	 * @return string[]|WP_Error
	 */
	public static function convert( array $conditions ) {
		if ( count( $conditions ) > self::MAX_CONDITIONS ) {
			return self::too_large();
		}
		$out = array();
		$bytes = 0;
		foreach ( $conditions as $condition ) {
			if ( is_string( $condition ) ) {
				if ( strlen( $condition ) > self::MAX_SERIALIZED_BYTES ) {
					return self::too_large();
				}
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
				if ( strlen( $raw ) > self::MAX_SERIALIZED_BYTES ) {
					return self::too_large();
				}
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
			$bytes += strlen( $string );
			if ( $bytes > self::MAX_SERIALIZED_BYTES ) {
				return self::too_large();
			}
			$out[] = $string;
		}
		return strlen( maybe_serialize( $out ) ) <= self::MAX_SERIALIZED_BYTES ? $out : self::too_large();
	}

	/**
	 * Inspect one conditions meta row after a bounded SQL length check.
	 *
	 * @return array{raw:array,conditions:string[]}|WP_Error
	 */
	public static function inspect( $post_id ) {
		$post_id = (int) $post_id;
		if ( isset( self::$inspection[ $post_id ] ) ) {
			return self::$inspection[ $post_id ];
		}
		$length = self::condition_meta_length( $post_id );
		if ( null !== $length && $length > self::MAX_SERIALIZED_BYTES ) {
			return self::$inspection[ $post_id ] = self::too_large( $post_id );
		}
		++self::$raw_read_depth;
		try {
			$value = get_post_meta( $post_id, '_elementor_conditions', true );
		} finally {
			--self::$raw_read_depth;
		}
		if ( ! is_array( $value ) ) {
			return self::$inspection[ $post_id ] = self::invalid( $post_id );
		}
		$conditions = self::convert( $value );
		if ( is_wp_error( $conditions ) ) {
			return self::$inspection[ $post_id ] = $conditions;
		}
		return self::$inspection[ $post_id ] = array( 'raw' => $value, 'conditions' => $conditions );
	}

	/** Compatibility wrapper; it never hydrates an oversized value. */
	public static function raw_conditions( $post_id ) {
		$inspection = self::inspect( $post_id );
		if ( ! is_wp_error( $inspection ) ) {
			return $inspection['raw'];
		}
		if ( 'conditions_too_large' === $inspection->get_error_code() ) {
			return array();
		}
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
	 * Avoid unserializing a legacy global cache large enough to exhaust PHP.
	 * Per-entry cache rows may still provide safe document entries separately.
	 */
	public static function filter_oversized_legacy_cache_option( $pre_option, $option, $default ) {
		$length = self::legacy_cache_option_length();
		if ( null !== $length && $length > self::MAX_LEGACY_CACHE_BYTES ) {
			self::$legacy_cache_too_large = true;
			// Keep bounded per-document rows visible even while the old global
			// option is deliberately never unserialized.
			return self::filter_conditions_cache( array() );
		}
		return $pre_option;
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
		$inspection = self::inspect( $post_id );
		$conditions = is_wp_error( $inspection ) ? $inspection : $inspection['conditions'];
		if ( is_wp_error( $conditions ) ) {
			self::mark_skipped( $post_id );
			$conditions = array( 'exclude/general' );
		}
		// get_metadata() unwraps this outer row for single=true. Returning an
		// array-of-values for both shapes is the core metadata filter contract.
		return array( $conditions );
	}

	/**
	 * Avoid WordPress priming all postmeta when a legacy conditions blob is
	 * over the bound. Elementor documents may ask for another key before the
	 * conditions key, which would otherwise hydrate the entire 6MB row.
	 */
	public static function filter_oversized_snippet_meta( $check, $post_id, $meta_key, $single, $meta_type ) {
		if ( null !== $check || 'post' !== $meta_type || self::$raw_read_depth > 0 || ! self::is_snippet( $post_id ) ) {
			return $check;
		}
		$length = self::condition_meta_length( $post_id );
		if ( null === $length || $length <= self::MAX_SERIALIZED_BYTES ) {
			return $check;
		}
		self::mark_skipped( $post_id );
		self::$inspection[ (int) $post_id ] = self::too_large( $post_id );
		if ( '_elementor_conditions' === $meta_key ) {
			return array( array( 'exclude/general' ) );
		}
		// Core unwraps the outer list for single reads. Empty data keeps an
		// oversized snippet from triggering a postmeta-cache load on any key.
		return $single ? array( '' ) : array();
	}

	/**
	 * WP_Query primes every postmeta row before get_post_metadata filters run.
	 * Replace only known-oversize snippet caches with a non-empty deny view.
	 */
	public static function filter_oversized_meta_cache_prime( $check, $post_ids ) {
		if ( self::$meta_cache_guard || ! function_exists( 'wp_cache_set' ) ) {
			return $check;
		}
		$unsafe = array();
		foreach ( (array) $post_ids as $post_id ) {
			if ( self::is_snippet( $post_id ) ) {
				$length = self::condition_meta_length( $post_id );
				if ( null !== $length && $length > self::MAX_SERIALIZED_BYTES ) {
					$unsafe[] = (int) $post_id;
					self::$inspection[ (int) $post_id ] = self::too_large( $post_id );
					self::mark_skipped( $post_id );
				}
			}
		}
		if ( empty( $unsafe ) ) {
			return $check;
		}
		self::$meta_cache_guard = true;
		try {
			foreach ( $unsafe as $post_id ) {
				wp_cache_set( $post_id, array(
					'_elementor_conditions' => array( maybe_serialize( array( 'exclude/general' ) ) ),
					'_elementor_code'       => array( '' ),
				), 'post_meta' );
			}
		} finally {
			self::$meta_cache_guard = false;
		}
		return true;
	}

	/** Remove unsafe cache entries when Pro reads the persisted cache. */
	public static function filter_conditions_cache( $cache ) {
		if ( self::$cache_bypass || ! is_array( $cache ) ) {
			return $cache;
		}
		// Prime post rows only. Conditions meta is inspected through a bounded
		// single-row read, so an old oversized blob never fills post-meta cache.
		$ids = array();
		foreach ( $cache as $templates ) {
			if ( is_array( $templates ) ) {
				$ids = array_merge( $ids, array_keys( $templates ) );
			}
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! empty( $ids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, false, false );
		}
		foreach ( $cache as $location => $templates ) {
			if ( ! is_array( $templates ) ) {
				continue;
			}
			foreach ( $templates as $post_id => $conditions ) {
				if ( ! self::is_snippet( $post_id ) ) {
					continue;
				}
				$inspection = self::inspect( $post_id );
				$converted = is_wp_error( $inspection ) ? $inspection : $inspection['conditions'];
				if ( is_wp_error( $converted ) ) {
					unset( $cache[ $location ][ $post_id ] );
					self::mark_skipped( $post_id );
				} else {
					$cache[ $location ][ $post_id ] = $converted;
				}
			}
		}
		foreach ( self::cache_entry_overlay() as $post_id => $entry ) {
			foreach ( $cache as $location => $templates ) {
				if ( is_array( $templates ) && array_key_exists( $post_id, $cache[ $location ] ) ) {
					unset( $cache[ $location ][ $post_id ] );
					if ( empty( $cache[ $location ] ) ) {
						unset( $cache[ $location ] );
					}
				}
			}
			if ( empty( $entry['deleted'] ) && self::is_snippet( $post_id ) ) {
				$inspection = self::inspect( $post_id );
				if ( is_wp_error( $inspection ) ) {
					self::mark_skipped( $post_id );
					continue;
				}
				$entry['conditions'] = $inspection['conditions'];
				$stored_location = get_post_meta( $post_id, '_elementor_location', true );
				if ( is_string( $stored_location ) && '' !== $stored_location ) {
					$entry['location'] = $stored_location;
				}
			}
			if ( empty( $entry['deleted'] ) ) {
				if ( ! isset( $cache[ $entry['location'] ] ) || ! is_array( $cache[ $entry['location'] ] ) ) {
					$cache[ $entry['location'] ] = array();
				}
				$cache[ $entry['location'] ][ $post_id ] = $entry['conditions'];
			}
		}
		return $cache;
	}

	/**
	 * Persist an operator receipt and evict this snippet from the on-disk cache.
	 * Request-only isolation never writes.
	 */
	public static function quarantine( $post_id, $raw, $persist = true ) {
		self::mark_skipped( $post_id );
		if ( ! $persist ) {
			return true;
		}
		return self::quarantine_locked( $post_id, $raw );
	}

	/** Record a bounded inspection refusal without constructing its raw blob. */
	public static function quarantine_inspection( $post_id, WP_Error $error, $persist = true ) {
		return self::quarantine( $post_id, $error, $persist );
	}

	private static function quarantine_locked( $post_id, $raw ) {
		$reason = is_wp_error( $raw ) ? self::reason( $raw ) : 'conditions_not_convertible';
		$fingerprint = is_wp_error( $raw ) ? $reason : hash( 'sha256', wp_json_encode( $raw ) );
		$new = $fingerprint !== (string) get_post_meta( $post_id, self::FINGERPRINT_META, true );
		$all = self::quarantine_ids();
		if ( ! in_array( (int) $post_id, $all, true ) ) {
			$all[] = (int) $post_id;
			sort( $all );
			update_option( self::QUARANTINE_OPTION, $all, false );
		}
		if ( $new ) {
			update_post_meta( $post_id, self::FINGERPRINT_META, $fingerprint );
			update_post_meta( $post_id, self::REASON_META, $reason );
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
		delete_post_meta( $post_id, self::REASON_META );
		unset( self::$request_skipped[ (int) $post_id ] );
		unset( self::$inspection[ (int) $post_id ] );
		self::bump_report_marker();
		return true;
	}

	/** @return int[] Current unsafe IDs, excluding repaired historical receipts. */
	public static function skipped_ids() {
		$ids = self::invalid_snippet_ids();
		foreach ( array_keys( self::$request_skipped ) as $id ) {
			if ( is_wp_error( self::inspect( $id ) ) ) {
				$ids[] = (int) $id;
			}
		}
		foreach ( self::quarantine_ids() as $id ) {
				if ( is_wp_error( self::inspect( $id ) ) ) {
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
			$rescan = Mcpwp_Activator::custom_code_cache_full_rescan_due();
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
		if ( empty( $ids ) && empty( $failed ) && ! self::$legacy_cache_too_large ) {
			return;
		}
		$summary = empty( $ids ) ? '' : self::report_summary( $ids );
		if ( ! empty( $failed ) ) {
			$summary .= ' ' . self::cache_repair_failure_summary( $failed );
		}
		if ( self::$legacy_cache_too_large ) {
			$summary .= ' Legacy Elementor conditions cache is over the safe size bound and was not loaded.';
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
		$healthy = empty( $ids ) && empty( $failed ) && ! self::$legacy_cache_too_large;
		$summary = empty( $ids ) ? '' : self::report_summary( $ids );
		if ( ! empty( $failed ) ) {
			$summary .= ' ' . self::cache_repair_failure_summary( $failed );
		}
		if ( self::$legacy_cache_too_large ) {
			$summary .= ' Legacy Elementor conditions cache is over the safe size bound and was not loaded.';
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
			$inspection = self::inspect( $post_id );
			$fingerprint = is_wp_error( $inspection ) ? self::reason( $inspection ) : hash( 'sha256', wp_json_encode( $inspection['raw'] ) );
			if ( $fingerprint === (string) get_post_meta( $post_id, self::FINGERPRINT_META, true ) ) {
				continue;
			}
			update_post_meta( $post_id, self::FINGERPRINT_META, $fingerprint );
			if ( is_wp_error( $inspection ) ) {
				update_post_meta( $post_id, self::REASON_META, self::reason( $inspection ) );
			}
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP Custom Code conditions skipped for snippet ' . $post_id . '.' );
		}
		return true;
	}

	/** Invalidate only when the underlying snippet set may have changed. */
	public static function invalidate_report_on_meta( $meta_id, $post_id, $meta_key ) {
		if ( '_elementor_conditions' === $meta_key && self::is_snippet( $post_id ) ) {
			unset( self::$inspection[ (int) $post_id ] );
			unset( self::$condition_lengths[ (int) $post_id ] );
			if ( class_exists( 'Mcpwp_Activator' ) && method_exists( 'Mcpwp_Activator', 'reset_custom_code_cache_repair_failure' ) ) {
				Mcpwp_Activator::reset_custom_code_cache_repair_failure( $post_id );
			}
			self::bump_report_marker();
		} elseif ( '_elementor_conditions' === $meta_key && self::is_library_document( $post_id ) ) {
			// Native Elementor editor writes the legacy global cache itself.
			// Remove a stale MCPWP per-entry override so the editor's new view
			// immediately wins; do not write a tombstone that would hide it.
			self::delete_entry_override( $post_id );
			self::invalidate_cache_view();
		}
	}

	/** Cache-entry CAS writers call this after changing one document row. */
	public static function invalidate_cache_view( $post_id = 0 ) {
		if ( $post_id > 0 ) {
			unset( self::$inspection[ (int) $post_id ] );
			unset( self::$condition_lengths[ (int) $post_id ] );
		}
		self::$entry_overlay = null;
	}

	/** @return array<int,array> Bounded per-document cache rows for Pro's view. */
	private static function cache_entry_overlay() {
		if ( null !== self::$entry_overlay ) {
			return self::$entry_overlay;
		}
		if ( ! class_exists( 'Mcpwp_Custom_Code_Cache_Entry' ) || ! method_exists( 'Mcpwp_Custom_Code_Cache_Entry', 'entries' ) ) {
			return self::$entry_overlay = array();
		}
		$after = 0;
		$all = array();
		do {
			$page = Mcpwp_Custom_Code_Cache_Entry::entries( $after, 500 );
			foreach ( $page as $post_id => $entry ) {
				$all[(int) $post_id] = $entry;
				$after = (int) $post_id;
			}
		} while ( 500 === count( $page ) );
		return self::$entry_overlay = $all;
	}

	public static function invalidate_report_on_post( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( $post && 'elementor_snippet' === $post->post_type ) {
			unset( self::$inspection[ (int) $post_id ] );
			unset( self::$condition_lengths[ (int) $post_id ] );
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
		return sprintf( 'Display-cache repair failed for %d snippet(s): %s%s.', count( $ids ), implode( ', ', array_slice( $ids, 0, self::REPORT_PREVIEW ) ), count( $ids ) > self::REPORT_PREVIEW ? ' …' : '' );
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
				if ( is_wp_error( self::inspect( $id ) ) ) {
					$bad[] = $id;
					self::mark_skipped( $id );
				}
				$cursor = $id;
			}
		} while ( 200 === count( $ids ) );
		return $bad;
	}

	public static function quarantine_reason( $post_id ) {
		$reason = (string) get_post_meta( (int) $post_id, self::REASON_META, true );
		if ( '' !== $reason ) {
			return $reason;
		}
		$inspection = self::inspect( $post_id );
		return is_wp_error( $inspection ) ? self::reason( $inspection ) : '';
	}

	private static function condition_meta_length( $post_id ) {
		$post_id = (int) $post_id;
		if ( array_key_exists( $post_id, self::$condition_lengths ) ) {
			return self::$condition_lengths[ $post_id ];
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || empty( $wpdb->postmeta ) ) {
			return self::$condition_lengths[ $post_id ] = null;
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- inspect byte length before loading the value.
		$length = $wpdb->get_var( $wpdb->prepare( "SELECT OCTET_LENGTH(meta_value) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id DESC LIMIT 1", $post_id, '_elementor_conditions' ) );
		return self::$condition_lengths[ $post_id ] = ( '' === $wpdb->last_error && null !== $length ? (int) $length : null );
	}

	private static function legacy_cache_option_length() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || empty( $wpdb->options ) ) {
			return null;
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- reject an oversized legacy blob before WordPress unserializes it.
		$length = $wpdb->get_var( $wpdb->prepare( "SELECT OCTET_LENGTH(option_value) FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'elementor_pro_theme_builder_conditions' ) );
		return '' === $wpdb->last_error && null !== $length ? (int) $length : null;
	}

	public static function legacy_cache_too_large() {
		return self::$legacy_cache_too_large;
	}

	private static function too_large( $post_id = 0 ) {
		return new WP_Error( 'conditions_too_large', __( 'Custom Code conditions exceed the supported cache bound.', 'mcpwp' ), array( 'status' => 413, 'resource_id' => (int) $post_id, 'reason' => 'conditions_too_large' ) );
	}

	private static function invalid( $post_id = 0 ) {
		return new WP_Error( 'invalid_custom_code_conditions', __( 'Custom Code conditions could not be converted safely.', 'mcpwp' ), array( 'resource_id' => (int) $post_id, 'reason' => 'conditions_not_convertible' ) );
	}

	private static function reason( WP_Error $error ) {
		$data = $error->get_error_data();
		return is_array( $data ) && ! empty( $data['reason'] ) ? (string) $data['reason'] : 'conditions_not_convertible';
	}

	private static function is_snippet( $post_id ) {
		$post = get_post( (int) $post_id );
		return $post && 'elementor_snippet' === $post->post_type;
	}

	private static function is_library_document( $post_id ) {
		$post = get_post( (int) $post_id );
		return $post && 'elementor_library' === $post->post_type;
	}

	private static function delete_entry_override( $post_id ) {
		if ( ! class_exists( 'Mcpwp_Custom_Code_Cache_Entry' ) ) {
			return;
		}
		$name = Mcpwp_Custom_Code_Cache_Entry::row_name( $post_id );
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'delete' ) && ! empty( $wpdb->options ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- remove the private override without reading its value.
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ), array( '%s' ) );
		}
		delete_option( $name );
		if ( class_exists( 'Mcpwp_Encryption' ) ) {
			Mcpwp_Encryption::clean_option_cache( $name );
		}
	}

	private static function mark_skipped( $post_id ) {
		self::$request_skipped[ (int) $post_id ] = true;
	}
}
