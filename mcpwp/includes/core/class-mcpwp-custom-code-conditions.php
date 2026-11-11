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
			return;
		}
		$all = get_option( self::QUARANTINE_OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		$fingerprint = hash( 'sha256', wp_json_encode( $raw ) );
		$new = ! isset( $all[ (int) $post_id ] ) || $all[ (int) $post_id ] !== $fingerprint;
		if ( $new ) {
			$all[ (int) $post_id ] = $fingerprint;
			update_option( self::QUARANTINE_OPTION, $all, false );
		}
		self::evict_persisted_cache_entry( $post_id );
		if ( $new ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP Custom Code conditions skipped for snippet ' . (int) $post_id . '.' );
		}
	}

	public static function clear_quarantine( $post_id ) {
		$all = get_option( self::QUARANTINE_OPTION, array() );
		if ( is_array( $all ) && isset( $all[ (int) $post_id ] ) ) {
			unset( $all[ (int) $post_id ] );
			update_option( self::QUARANTINE_OPTION, $all, false );
		}
		unset( self::$request_skipped[ (int) $post_id ] );
	}

	/** @return int[] Current unsafe IDs, excluding repaired historical receipts. */
	public static function skipped_ids() {
		$ids = self::invalid_snippet_ids();
		foreach ( array_keys( self::$request_skipped ) as $id ) {
			if ( is_wp_error( self::convert( self::raw_conditions( $id ) ) ) ) {
				$ids[] = (int) $id;
			}
		}
		$all = get_option( self::QUARANTINE_OPTION, array() );
		if ( is_array( $all ) ) {
			foreach ( array_keys( $all ) as $id ) {
				if ( is_wp_error( self::convert( self::raw_conditions( $id ) ) ) ) {
					$ids[] = (int) $id;
				}
			}
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids );
		return $ids;
	}

	public static function annotate_response( array $data ) {
		$ids = self::skipped_ids();
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
		foreach ( self::skipped_ids() as $post_id ) {
			self::quarantine( $post_id, self::raw_conditions( $post_id ) );
		}
		$all = get_option( self::QUARANTINE_OPTION, array() );
		if ( ! is_array( $all ) ) {
			return;
		}
		foreach ( array_keys( $all ) as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || ! self::is_snippet( $post_id ) || ! is_wp_error( self::convert( self::raw_conditions( $post_id ) ) ) ) {
				self::clear_quarantine( $post_id );
			}
		}
	}

	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) || empty( self::skipped_ids() ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html( 'MCPWP skipped Custom Code conditions for snippet IDs: ' . implode( ', ', self::skipped_ids() ) . '.' ) . '</p></div>';
	}

	public static function register_site_health_test( $tests ) {
		$tests['direct']['mcpwp_custom_code_conditions'] = array( 'label' => __( 'Custom Code condition compatibility', 'mcpwp' ), 'test' => array( __CLASS__, 'site_health_test' ) );
		return $tests;
	}

	public static function site_health_test() {
		$ids = self::skipped_ids();
		return array(
			'label' => empty( $ids ) ? __( 'Custom Code conditions are compatible', 'mcpwp' ) : __( 'Some Custom Code conditions were skipped', 'mcpwp' ),
			'status' => empty( $ids ) ? 'good' : 'recommended',
			'badge' => array( 'label' => 'MCPWP', 'color' => 'blue' ),
			'description' => empty( $ids ) ? '<p>OK</p>' : '<p>' . esc_html( implode( ', ', $ids ) ) . '</p>',
			'actions' => '',
			'test' => 'mcpwp_custom_code_conditions',
		);
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
		self::$cache_bypass = true;
		try {
			$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
			if ( ! is_array( $cache ) ) {
				return;
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
