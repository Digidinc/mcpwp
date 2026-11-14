<?php
/**
 * Custom Code conditions compatibility boundary.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-mcpwp-custom-code-cache-view.php';

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
	private static $request_invalid_entries = array();
	private static $filtered_views = array();
	private static $view_dirty = false;
	private static $view_refreshing = false;
	private static $view_input = null;
	private static $active_view = null;
	private static $pro_rebuild_candidate = false;
	private static $safe_meta_ids = array();
	private static $oversized_meta_ids = null;
	private static $pre_view_read = null;
	private static $view_errors = array();
	private static $view_failed = false;

	public static function register_hooks() {
		// This is Elementor Pro's own rebuild query. Excluding unsafe IDs here
		// means Pro never calls explode() on their raw legacy arrays.
		add_filter( 'elementor/theme/conditions/cache/regenerate/query_args', array( __CLASS__, 'filter_regenerate_query' ) );
		add_filter( 'option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'filter_conditions_cache' ) );
		add_filter( 'default_option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'default_conditions_cache' ) );
		add_action( 'update_option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'mark_view_dirty' ) );
		add_action( 'add_option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'mark_view_dirty' ) );
		add_action( 'delete_option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'mark_view_dirty' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'refresh_pending_view' ), PHP_INT_MAX - 20 );
		add_action( 'shutdown', array( __CLASS__, 'refresh_pending_view' ), 1 );
		add_filter( 'pre_option_elementor_pro_theme_builder_conditions', array( __CLASS__, 'filter_oversized_legacy_cache_option' ), 1, 3 );
		add_filter( 'update_post_metadata_cache', array( __CLASS__, 'filter_oversized_meta_cache_prime' ), 1, 2 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_oversized_snippet_meta' ), 1, 5 );
		add_action( 'admin_init', array( __CLASS__, 'refresh_admin_report' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_filter( 'site_status_tests', array( __CLASS__, 'register_site_health_test' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'invalidate_report_on_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'invalidate_report_on_meta' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'invalidate_report_on_meta' ), 10, 4 );
		add_action( 'save_post', array( __CLASS__, 'invalidate_report_on_post' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'invalidate_report_on_post' ), 10, 2 );
		foreach ( array( 'elementor_head', 'elementor_body_start', 'elementor_body_end' ) as $location ) {
			add_action( 'elementor/theme/before_do_' . $location, array( __CLASS__, 'begin_custom_code_render' ) );
			add_action( 'elementor/theme/after_do_' . $location, array( __CLASS__, 'end_custom_code_render' ) );
		}
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
			update_option( self::STORAGE_INITIALIZED_OPTION, 1, true );
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
			if ( is_string( $condition ) && strlen( $condition ) <= self::MAX_SERIALIZED_BYTES && preg_match( '~^(include|exclude)/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+){0,2}$~D', $condition ) ) {
				$bytes += strlen( $condition );
				if ( $bytes > self::MAX_SERIALIZED_BYTES ) {
					return self::too_large();
				}
				$out[] = $condition;
				continue;
			}
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
		if ( null === $length && ( '' === $value || null === $value ) ) {
			// Absent legacy meta follows Elementor Pro's own default condition.
			return self::$inspection[ $post_id ] = array( 'raw' => array(), 'conditions' => array(), 'missing' => true );
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
		self::$pro_rebuild_candidate = true;
		self::$inspection = array();
		self::$condition_lengths = array();
		self::$safe_meta_ids = array();
		self::$oversized_meta_ids = null;
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_oversized_snippet_meta' ), 1, 5 );
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_pro_rebuild_conditions_meta' ), 10, 5 );
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
		try {
			return self::read_legacy_cache_option( $pre_option, $option, $default );
		} catch ( \Throwable $exception ) {
			self::report_view_error( $exception );
			// An unmeasured legacy blob must not reach native unserialization.
			return self::recover_conditions_cache( array() );
		}
	}

	private static function read_legacy_cache_option( $pre_option, $option, $default ) {
		if ( false !== $pre_option ) {
			return $pre_option;
		}
		if ( ! self::$view_refreshing ) {
			if ( null === self::$pre_view_read ) {
				try {
					self::$pre_view_read = Mcpwp_Custom_Code_Cache_View::read();
				} catch ( \Throwable $exception ) {
					self::report_view_error( $exception );
					self::$pre_view_read = array( 'hit' => false );
				}
			}
			if ( ! empty( self::$pre_view_read['hit'] ) ) {
				self::$active_view = self::$pre_view_read['cache'];
				self::$request_invalid_entries = array_fill_keys( self::$pre_view_read['invalid_ids'], true );
				foreach ( self::$pre_view_read['skipped_ids'] as $post_id ) {
					self::mark_skipped( $post_id );
				}
				// Pro owns its mutable cache; keep request-validation aliases out
				// of the returned buckets to avoid copy-on-write during rendering.
				return unserialize( serialize( self::$active_view ), array( 'allowed_classes' => false ) );
			}
		}
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
		if ( null !== $check || 'post' !== $meta_type || '_elementor_conditions' !== $meta_key || self::$raw_read_depth > 0 || ! self::$pro_rebuild_candidate || ! self::is_snippet( $post_id ) || ! self::in_elementor_cache_regeneration() ) {
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
		if ( null !== $check || 'post' !== $meta_type || self::$raw_read_depth > 0 ) {
			return $check;
		}
		if ( isset( self::$safe_meta_ids[ (int) $post_id ] ) ) {
			return $check;
		}
		if ( array_key_exists( (int) $post_id, self::$condition_lengths ) && ( null === self::$condition_lengths[ (int) $post_id ] || self::$condition_lengths[ (int) $post_id ] <= self::MAX_SERIALIZED_BYTES ) ) {
			return $check;
		}
		if ( ! self::is_snippet( $post_id ) ) {
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

	/** Do not print a cached document after its live conditions have changed. */
	public static function filter_changed_view_source( $check, $post_id, $meta_key, $single, $meta_type ) {
		try {
			return self::validate_printed_source( $check, $post_id, $meta_key, $single, $meta_type );
		} catch ( \Throwable $exception ) {
			self::report_view_error( $exception );
			return self::printing_custom_code() ? array( '' ) : $check;
		}
	}

	private static function validate_printed_source( $check, $post_id, $meta_key, $single, $meta_type ) {
		if ( null !== $check || 'post' !== $meta_type || '_elementor_code' !== $meta_key || null === self::$active_view || self::$raw_read_depth > 0 ) {
			return $check;
		}
		$expected = null;
		$expected_location = null;
		foreach ( self::$active_view as $location => $entries ) {
			if ( is_array( $entries ) && isset( $entries[ $post_id ] ) ) {
				$expected = $entries[ $post_id ];
				$expected_location = $location;
				break;
			}
		}
		if ( ! self::printing_custom_code() ) {
			return $check;
		}
		if ( null === $expected ) {
			return self::$view_failed ? array( '' ) : $check;
		}
		$meta = wp_cache_get( $post_id, 'post_meta' );
		if ( is_array( $meta ) ) {
			$raw = isset( $meta['_elementor_conditions'][0] ) ? maybe_unserialize( $meta['_elementor_conditions'][0] ) : array();
		} else {
			// A document printed outside Pro's normal primed query still uses
			// the bounded reader before any metadata value is hydrated.
			$length = self::condition_meta_length( $post_id );
			if ( null !== $length && $length > self::MAX_SERIALIZED_BYTES ) {
				return array( '' );
			}
			++self::$raw_read_depth;
			try {
				$raw = get_post_meta( $post_id, '_elementor_conditions', true );
			} finally {
				--self::$raw_read_depth;
			}
		}
		$location = is_array( $meta ) ? ( $meta['_elementor_location'][0] ?? '' ) : get_post_meta( $post_id, '_elementor_location', true );
		if ( ! is_string( $location ) || $location !== $expected_location ) {
			return array( '' );
		}
		// Empty conditions are Elementor Pro's own default.
		if ( empty( $raw ) ) {
			return $check;
		}
		$current = is_array( $raw ) ? self::convert( $raw ) : null;
		return $current === $expected ? $check : array( '' );
	}

	public static function begin_custom_code_render() {
		add_filter( 'get_post_metadata', array( __CLASS__, 'filter_changed_view_source' ), 2, 5 );
	}

	public static function end_custom_code_render() {
		remove_filter( 'get_post_metadata', array( __CLASS__, 'filter_changed_view_source' ), 2 );
	}

	private static function printing_custom_code() {
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) {
			if ( ( $frame['class'] ?? '' ) === 'ElementorPro\\Modules\\CustomCode\\Document' && ( $frame['function'] ?? '' ) === 'print_content' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * WP_Query primes every postmeta row before get_post_metadata filters run.
	 * Replace only known-oversize snippet caches with a non-empty deny view.
	 */
	public static function filter_oversized_meta_cache_prime( $check, $post_ids ) {
		if ( self::$meta_cache_guard || ! function_exists( 'wp_cache_set' ) ) {
			return $check;
		}
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $post_ids ) ) ) );
		$unknown = array();
		$unsafe = array();
		foreach ( $post_ids as $post_id ) {
			if ( isset( self::$safe_meta_ids[ $post_id ] ) ) {
				continue;
			}
			if ( array_key_exists( $post_id, self::$condition_lengths ) ) {
				if ( self::$condition_lengths[ $post_id ] > self::MAX_SERIALIZED_BYTES ) {
					$unsafe[] = $post_id;
				} else {
					self::$safe_meta_ids[ $post_id ] = true;
				}
			} else {
				$unknown[] = $post_id;
			}
		}
		if ( count( $unknown ) <= 32 ) {
			foreach ( $unknown as $index => $post_id ) {
				$post = wp_cache_get( $post_id, 'posts' );
				if ( is_object( $post ) && isset( $post->post_type ) && 'elementor_snippet' !== $post->post_type ) {
					self::$safe_meta_ids[ $post_id ] = true;
					unset( $unknown[ $index ] );
				}
			}
			$unknown = array_values( $unknown );
		}
		if ( ! empty( $unknown ) ) {
			global $wpdb;
			if ( null === self::$oversized_meta_ids ) {
				$wpdb->last_error = '';
				// One indexed site-wide scan returns only oversized rows. An IN
				// list proportional to the page's document count is unnecessary.
				$rows = $wpdb->get_results( $wpdb->prepare(
					"SELECT post_id, OCTET_LENGTH(meta_value) AS bytes FROM {$wpdb->postmeta} WHERE meta_key = %s AND OCTET_LENGTH(meta_value) > %d",
					'_elementor_conditions', self::MAX_SERIALIZED_BYTES
				), ARRAY_A );
				if ( is_array( $rows ) && '' === $wpdb->last_error ) {
					self::$oversized_meta_ids = array();
					foreach ( $rows as $row ) {
						if ( self::is_snippet( (int) $row['post_id'] ) ) {
					self::$oversized_meta_ids[ (int) $row['post_id'] ] = (int) $row['bytes'];
					}
					}
				}
			}
			$rows = null;
			if ( is_array( self::$oversized_meta_ids ) ) {
				$rows = array();
				foreach ( $unknown as $post_id ) {
					if ( isset( self::$oversized_meta_ids[ $post_id ] ) ) {
						$rows[] = array( 'post_id' => $post_id, 'bytes' => self::$oversized_meta_ids[ $post_id ] );
					}
				}
			}
			if ( ! is_array( $rows ) ) {
				// Do not hydrate unmeasured values when the size probe failed.
				foreach ( $unknown as $post_id ) {
					if ( self::is_snippet( $post_id ) ) {
						$unsafe[] = $post_id;
					}
				}
			} else {
				foreach ( $rows as $row ) {
					$id = (int) $row['post_id'];
					self::$condition_lengths[ $id ] = (int) $row['bytes'];
					$unsafe[] = $id;
				}
				foreach ( array_diff( $unknown, $unsafe ) as $post_id ) {
					self::$safe_meta_ids[ $post_id ] = true;
				}
			}
		}
		if ( empty( $unsafe ) ) {
			return $check;
		}
		self::$meta_cache_guard = true;
		try {
			foreach ( $unsafe as $post_id ) {
				self::$inspection[ $post_id ] = self::too_large( $post_id );
				self::mark_skipped( $post_id );
				wp_cache_set( $post_id, array( '_elementor_conditions' => array( maybe_serialize( array( 'exclude/general' ) ) ), '_elementor_code' => array( '' ) ), 'post_meta' );
			}
			$safe = array_values( array_diff( $post_ids, $unsafe ) );
			if ( ! empty( $safe ) && function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'post', $safe );
			}
		} finally {
			self::$meta_cache_guard = false;
		}
		return true;
	}

	/** Remove unsafe cache entries when Pro reads the persisted cache. */
	public static function filter_conditions_cache( $cache ) {
		if ( self::$cache_bypass ) {
			return $cache;
		}
		if ( self::$view_failed && null !== self::$active_view ) {
			return self::$active_view;
		}
		try {
			return self::build_conditions_cache( $cache );
		} catch ( \Throwable $exception ) {
			self::report_view_error( $exception );
			return self::recover_conditions_cache( $cache );
		}
	}

	private static function build_conditions_cache( $cache ) {
		if ( self::$cache_bypass || ! is_array( $cache ) ) {
			return $cache;
		}
		$base_hash = hash( 'sha256', maybe_serialize( $cache ) );
		if ( ! self::$view_refreshing ) {
			if ( isset( self::$filtered_views[ $base_hash ] ) ) {
				return self::$filtered_views[ $base_hash ];
			}
			$snapshot = self::$pre_view_read ?? Mcpwp_Custom_Code_Cache_View::read( $cache );
			if ( ! empty( $snapshot['hit'] ) ) {
				self::$request_invalid_entries = array_fill_keys( $snapshot['invalid_ids'], true );
				foreach ( $snapshot['skipped_ids'] as $post_id ) {
					self::mark_skipped( $post_id );
				}
				self::$active_view = $snapshot['cache'];
				return self::$filtered_views[ $base_hash ] = $snapshot['cache'];
			}
		} else {
			self::$view_input = $cache;
		}
		$base = $cache;
		$generation = self::$view_refreshing ? null : Mcpwp_Custom_Code_Cache_View::generation();
		$fingerprint = self::$view_refreshing ? null : Mcpwp_Custom_Code_Cache_View::fingerprint();
		$overlay = self::cache_entry_overlay();
		$replaced = array_fill_keys( array_merge( array_keys( $overlay['entries'] ), $overlay['invalid_ids'] ), true );
		$ids = array_keys( $replaced );
		foreach ( $cache as $templates ) {
			if ( is_array( $templates ) ) {
				$ids = array_merge( $ids, array_keys( $templates ) );
			}
		}
		unset( $templates );
		$inspection = self::inspect_many( $ids );
		// Mutate each bucket once. Copying it for every overlaid ID makes a
		// large cache quadratic even when all database reads are batched.
		foreach ( $cache as $location => &$templates ) {
			if ( ! is_array( $templates ) ) {
				continue;
			}
			$removed = false;
			foreach ( $templates as $post_id => &$conditions ) {
				$result = $inspection[ (int) $post_id ] ?? null;
				if ( isset( $replaced[ $post_id ] ) || is_wp_error( $result ) ) {
					unset( $templates[ $post_id ] );
					$removed = true;
					if ( is_wp_error( $result ) ) {
						self::mark_skipped( $post_id );
					}
				} elseif ( is_array( $result ) && empty( $result['missing'] ) ) {
					$conditions = $result['conditions'];
				}
			}
			unset( $conditions );
			if ( $removed && empty( $templates ) ) {
				unset( $cache[ $location ] );
			}
		}
		unset( $templates );
		foreach ( $overlay['invalid_ids'] as $post_id ) {
			self::mark_entry_storage_invalid( $post_id );
		}
		foreach ( $overlay['entries'] as $post_id => $entry ) {
			if ( ! empty( $entry['deleted'] ) ) {
				continue;
			}
			if ( self::is_snippet( $post_id ) ) {
				$result = $inspection[ (int) $post_id ] ?? null;
				if ( is_wp_error( $result ) ) {
					self::mark_skipped( $post_id );
					continue;
				}
				if ( ! is_array( $result ) || ! empty( $result['missing'] ) ) {
					continue;
				}
				$entry['conditions'] = $result['conditions'];
				$location = get_post_meta( $post_id, '_elementor_location', true );
				if ( is_string( $location ) && '' !== $location ) {
					$entry['location'] = $location;
				}
			}
			$cache[ $entry['location'] ][ $post_id ] = $entry['conditions'];
		}
		self::$active_view = $cache;
		self::$filtered_views[ $base_hash ] = $cache;
		if ( ! self::$view_refreshing ) {
			// Recreate a missing acceleration row on the first cold request.
			// Only this derived cache is written; source metadata stays untouched.
			try {
				Mcpwp_Custom_Code_Cache_View::store( $base, $cache, array_map( 'intval', array_keys( self::$request_invalid_entries ) ), array_map( 'intval', array_keys( self::$request_skipped ) ), $fingerprint, $generation );
			} catch ( \Throwable $exception ) {
				self::report_view_error( $exception );
			}
		}
		return $cache;
	}

	/** Keep a provider/database fault from escaping into Pro's option read. */
	private static function recover_conditions_cache( $raw ) {
		self::$view_failed = true;
		try {
			$last = Mcpwp_Custom_Code_Cache_View::read_last_good();
			if ( ! empty( $last['hit'] ) ) {
				return self::$active_view = $last['cache'];
			}
		} catch ( \Throwable $exception ) {
			self::report_view_error( $exception );
		}
		// A failed batch must not discard healthy neighbours or trust an
		// unvalidated snippet. Retry independently, isolating each refusal.
		$safe = is_array( $raw ) ? $raw : array();
		foreach ( $safe as $location => &$entries ) {
			if ( ! is_array( $entries ) ) {
				unset( $safe[ $location ] );
				continue;
			}
			foreach ( $entries as $id => &$conditions ) {
				try {
					if ( self::is_snippet( $id ) ) {
						$result = self::inspect( $id );
						if ( is_wp_error( $result ) ) {
							unset( $entries[ $id ] );
							self::mark_skipped( $id );
						} else {
							$conditions = $result['conditions'];
						}
					}
				} catch ( \Throwable $exception ) {
					unset( $entries[ $id ] );
					self::mark_skipped( $id );
					self::report_view_error( $exception );
				}
			}
			unset( $conditions );
		}
		unset( $entries );
		return self::$active_view = $safe;
	}

	private static function report_view_error( \Throwable $exception ) {
		$cause = hash( 'sha256', get_class( $exception ) . ':' . $exception->getMessage() );
		if ( isset( self::$view_errors[ $cause ] ) ) {
			return;
		}
		self::$view_errors[ $cause ] = true;
		// Bound repeated identical faults across requests as well as callbacks.
		$key = 'mcpwp_custom_code_view_error_' . substr( $cause, 0, 16 );
		try {
			if ( get_transient( $key ) ) {
				return;
			}
			set_transient( $key, 1, self::REPORT_TTL );
		} catch ( \Throwable $ignored ) {
			// Logging must not depend on the failing cache/database provider.
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'MCPWP Custom Code cache view read failed: ' . $exception->getMessage() );
	}

	public static function default_conditions_cache( $default ) {
		return self::filter_conditions_cache( array() );
	}

	/** Source mutations invalidate request memo; persistent snapshots remain fingerprint-gated. */
	public static function mark_view_dirty() {
		self::$pre_view_read = null;
		self::$view_failed = false;
		$invalidated = Mcpwp_Custom_Code_Cache_View::invalidate();
		self::$active_view = null;
		self::$view_dirty = true;
		self::$filtered_views = array();
		return $invalidated;
	}

	/**
	 * Refresh the derived snapshot after source writes, before REST output.
	 * Cold readers may also populate it without changing source metadata.
	 * Concurrent source changes make store() decline the snapshot.
	 */
	public static function refresh_pending_view( $response = null ) {
		if ( ! self::$view_dirty || self::$view_refreshing ) {
			return $response;
		}
		self::$view_refreshing = true;
		try {
			// These tiny bootstrap booleans are read on every request. Promote
			// older non-autoloaded copies once during an authorized write.
			global $wpdb;
			$changed = $wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->options} SET autoload = 'yes' WHERE option_name IN (%s,%s) AND autoload NOT IN ('yes','on','auto','auto-on')",
				self::STORAGE_INITIALIZED_OPTION, 'mcpwp_custom_code_cache_lock_retired'
			) );
			if ( $changed ) {
				wp_cache_delete( 'alloptions', 'options' );
			}
			$generation = Mcpwp_Custom_Code_Cache_View::generation();
			$fingerprint = Mcpwp_Custom_Code_Cache_View::fingerprint();
			if ( null === $fingerprint ) {
				return $response;
			}
			self::$inspection = array();
			self::$condition_lengths = array();
			self::$safe_meta_ids = array();
			self::$oversized_meta_ids = null;
			self::$entry_overlay = null;
			self::$request_skipped = array();
			self::$request_invalid_entries = array();
			self::$filtered_views = array();
			self::$view_input = null;
			self::$pre_view_read = null;
			$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
			if ( null === self::$view_input ) {
				$cache = self::filter_conditions_cache( is_array( $cache ) ? $cache : array() );
			}
			Mcpwp_Custom_Code_Cache_View::store(
				self::$view_input ?? array(),
				is_array( $cache ) ? $cache : array(),
				array_map( 'intval', array_keys( self::$request_invalid_entries ) ),
				array_map( 'intval', array_keys( self::$request_skipped ) ),
				$fingerprint,
				$generation
			);
		} catch ( \Throwable $exception ) {
			// A cache miss retains the validated batch read, so snapshot failures
			// cannot turn a successful per-entry write into a missing document.
			self::report_view_error( $exception );
		} finally {
			self::$view_refreshing = false;
			self::$view_dirty = false;
			self::$filtered_views = array();
		}
		return $response;
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

	/** Invalid per-entry storage is reported without loading its option value. */
	public static function mark_entry_storage_invalid( $post_id ) {
		self::$request_invalid_entries[ (int) $post_id ] = true;
		return true;
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
		try {
			return self::annotate_available_report( $data, $allow_scan );
		} catch ( \Throwable $exception ) {
			self::report_view_error( $exception );
			$data['custom_code_conditions_report_unavailable'] = true;
			return $data;
		}
	}

	private static function annotate_available_report( array $data, $allow_scan ) {
		$ids = $allow_scan ? self::report_ids() : self::cached_report_ids();
		if ( ! empty( $ids ) ) {
			$data['skipped_custom_code_snippet_ids'] = $ids;
		}
		$failed = array_values( array_unique( array_merge( self::cache_repair_failure_ids(), array_map( 'intval', array_keys( self::$request_invalid_entries ) ) ) ) );
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
		$overlay = self::cache_entry_overlay();
		foreach ( $overlay['invalid_ids'] as $post_id ) {
			Mcpwp_Activator::observe_custom_code_cache_entry_failure( (int) $post_id );
		}
		if ( Mcpwp_Activator::custom_code_cache_maintenance_due() ) {
			self::mark_view_dirty();
			$rescan = Mcpwp_Activator::custom_code_cache_full_rescan_due();
			Mcpwp_Activator::repair_custom_code_conditions( 0.25, $rescan );
		}
		$marker = self::report_marker();
		$report = get_option( self::REPORT_OPTION, array() );
		if ( self::report_is_fresh( $report, $marker ) ) {
			return;
		}
		self::mark_view_dirty();
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
		$capacity_issue = Mcpwp_Custom_Code_Cache_View::capacity_issue();
		if ( empty( $ids ) && empty( $failed ) && ! self::$legacy_cache_too_large && ! $capacity_issue ) {
			return;
		}
		$summary = empty( $ids ) ? '' : self::report_summary( $ids );
		if ( ! empty( $failed ) ) {
			$summary .= ' ' . self::cache_repair_failure_summary( $failed );
		}
		if ( self::$legacy_cache_too_large ) {
			$summary .= ' Legacy Elementor conditions cache is over the safe size bound and was not loaded.';
		}
		if ( $capacity_issue ) {
			$summary .= ' The Custom Code conditions view exceeds its safe storage capacity; validated fallback reads are active.';
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
		$capacity_issue = Mcpwp_Custom_Code_Cache_View::capacity_issue();
		$healthy = empty( $ids ) && empty( $failed ) && ! self::$legacy_cache_too_large && ! $capacity_issue;
		$summary = empty( $ids ) ? '' : self::report_summary( $ids );
		if ( ! empty( $failed ) ) {
			$summary .= ' ' . self::cache_repair_failure_summary( $failed );
		}
		if ( self::$legacy_cache_too_large ) {
			$summary .= ' Legacy Elementor conditions cache is over the safe size bound and was not loaded.';
		}
		if ( $capacity_issue ) {
			$summary .= ' The Custom Code conditions view exceeds its safe storage capacity; validated fallback reads are active.';
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
		if ( '_elementor_conditions' === $meta_key ) {
			self::$oversized_meta_ids = null;
			add_filter( 'get_post_metadata', array( __CLASS__, 'filter_oversized_snippet_meta' ), 1, 5 );
		}
		if ( in_array( $meta_key, array( '_elementor_conditions', '_elementor_location' ), true ) && self::is_snippet( $post_id ) ) {
			unset( self::$inspection[ (int) $post_id ] );
			unset( self::$condition_lengths[ (int) $post_id ], self::$safe_meta_ids[ (int) $post_id ] );
			if ( class_exists( 'Mcpwp_Activator' ) && method_exists( 'Mcpwp_Activator', 'reset_custom_code_cache_repair_failure' ) ) {
				Mcpwp_Activator::reset_custom_code_cache_repair_failure( $post_id );
			}
			self::bump_report_marker();
			self::mark_view_dirty();
		} elseif ( in_array( $meta_key, array( '_elementor_conditions', '_elementor_location', '_elementor_template_type' ), true ) && self::is_library_document( $post_id ) ) {
			// Native Elementor editor writes the legacy global cache itself.
			// Remove a stale MCPWP per-entry override so the editor's new view
			// immediately wins; do not write a tombstone that would hide it.
			self::delete_entry_override( $post_id );
			self::invalidate_cache_view();
			self::mark_view_dirty();
		}
	}

	/** Cache-entry CAS writers call this after changing one document row. */
	public static function invalidate_cache_view( $post_id = 0 ) {
		self::$pre_view_read = null;
		self::$filtered_views = array();
		if ( $post_id > 0 ) {
			unset( self::$inspection[ (int) $post_id ] );
			unset( self::$condition_lengths[ (int) $post_id ], self::$safe_meta_ids[ (int) $post_id ] );
		}
		self::$entry_overlay = null;
	}

	/**
	 * Inspect Custom Code rows with one post prime and one bounded meta query.
	 * Missing conditions are deliberately absent from the map: Elementor's own
	 * default semantics then remain in force rather than being quarantined.
	 *
	 * @param int[] $post_ids IDs appearing in the legacy cache option.
	 * @return array<int,array{raw:array,conditions:string[]}|WP_Error>
	 */
	private static function inspect_many( array $post_ids ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $post_ids, false, false );
		}
		$snippet_ids = array();
		$out = array();
		foreach ( $post_ids as $post_id ) {
			if ( self::is_snippet( $post_id ) ) {
				if ( isset( self::$inspection[ $post_id ] ) ) {
					$out[ $post_id ] = self::$inspection[ $post_id ];
				} else {
					$snippet_ids[] = $post_id;
				}
			}
		}
		if ( empty( $snippet_ids ) ) {
			return $out;
		}
		if ( self::$view_refreshing ) {
			foreach ( $snippet_ids as $post_id ) {
				wp_cache_delete( $post_id, 'post_meta' );
			}
		}
		$lengths = self::condition_lengths_many( $snippet_ids );
		$safe = array();
		foreach ( $snippet_ids as $post_id ) {
			if ( isset( $lengths[ $post_id ] ) && $lengths[ $post_id ] > self::MAX_SERIALIZED_BYTES ) {
				$out[ $post_id ] = self::$inspection[ $post_id ] = self::too_large( $post_id );
			} else {
				$safe[] = $post_id;
			}
		}
		if ( ! empty( $safe ) && function_exists( 'update_meta_cache' ) ) {
			self::$meta_cache_guard = true;
			try {
				update_meta_cache( 'post', $safe );
			} finally {
				self::$meta_cache_guard = false;
			}
		}
		foreach ( $safe as $post_id ) {
			$out[ $post_id ] = self::inspect( $post_id );
		}
		return $out;
	}

	/** @return array{entries:array<int,array>,invalid_ids:int[]} */
	private static function cache_entry_overlay() {
		if ( null !== self::$entry_overlay ) {
			return self::$entry_overlay;
		}
		if ( ! class_exists( 'Mcpwp_Custom_Code_Cache_Entry' ) || ! method_exists( 'Mcpwp_Custom_Code_Cache_Entry', 'entries' ) ) {
			return self::$entry_overlay = array( 'entries' => array(), 'invalid_ids' => array() );
		}
		$rows = Mcpwp_Custom_Code_Cache_Entry::entries();
		self::$request_invalid_entries = array_fill_keys( isset( $rows['invalid_ids'] ) ? $rows['invalid_ids'] : array(), true );
		return self::$entry_overlay = is_array( $rows ) && isset( $rows['entries'], $rows['invalid_ids'] )
			? $rows : array( 'entries' => array(), 'invalid_ids' => array() );
	}

	public static function invalidate_report_on_post( $post_id, $post = null ) {
		$post = $post ? $post : get_post( $post_id );
		if ( $post && in_array( $post->post_type, array( 'elementor_snippet', 'elementor_library' ), true ) ) {
			self::mark_view_dirty();
		}
		if ( $post && 'elementor_snippet' === $post->post_type ) {
			unset( self::$inspection[ (int) $post_id ] );
			unset( self::$condition_lengths[ (int) $post_id ], self::$safe_meta_ids[ (int) $post_id ] );
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
		$overlay = self::cache_entry_overlay();
		return array_values( array_unique( array_merge( array_map( 'intval', (array) get_option( 'mcpwp_custom_code_cache_repair_failed_ids', array() ) ), $overlay['invalid_ids'] ) ) );
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

	/** @param int[] $post_ids @return array<int,int> */
	private static function condition_lengths_many( array $post_ids ) {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}
		$unknown = array();
		$out = array();
		foreach ( $post_ids as $post_id ) {
			if ( array_key_exists( $post_id, self::$condition_lengths ) ) {
				if ( null !== self::$condition_lengths[ $post_id ] ) {
					$out[ $post_id ] = self::$condition_lengths[ $post_id ];
				}
			} else {
				$unknown[] = $post_id;
			}
		}
		if ( empty( $unknown ) ) {
			return $out;
		}
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) || empty( $wpdb->postmeta ) ) {
			return $out;
		}
		$placeholders = implode( ',', array_fill( 0, count( $unknown ), '%d' ) );
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- bulk size probe before metadata cache hydration.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, OCTET_LENGTH(meta_value) AS bytes FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ($placeholders)", array_merge( array( '_elementor_conditions' ), $unknown ) ), ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return $out;
		}
		foreach ( $unknown as $post_id ) {
			self::$condition_lengths[ $post_id ] = null;
		}
		foreach ( $rows as $row ) {
			$post_id = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
			if ( $post_id > 0 && isset( $row['bytes'] ) ) {
				self::$condition_lengths[ $post_id ] = (int) $row['bytes'];
				$out[ $post_id ] = self::$condition_lengths[ $post_id ];
			}
		}
		return $out;
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
		return new WP_Error( 'conditions_too_large', __( 'Custom Code supports at most 200 conditions, 16,384 bytes for the serialized condition list and 17,408 bytes for the serialized cache entry, including overhead.', 'mcpwp' ), array( 'status' => 413, 'resource_id' => (int) $post_id, 'reason' => 'conditions_too_large' ) );
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
