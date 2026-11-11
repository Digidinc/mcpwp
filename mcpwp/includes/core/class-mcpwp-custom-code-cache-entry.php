<?php
/**
 * Per-document Conditions_Cache storage.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores each document's verified cache entry in its own small option row.
 * A normal write never reads, serializes, or compares Elementor Pro's
 * site-wide cache option.
 */
class Mcpwp_Custom_Code_Cache_Entry {
	const OPTION_PREFIX        = 'mcpwp_custom_code_cache_entry_';
	const MAX_ATTEMPTS         = 3;
	const MAX_CONDITIONS       = 200;
	const MAX_CONDITIONS_BYTES = 16384;
	const MAX_ENTRY_BYTES      = 17408;

	public static function row_name( $post_id ) {
		return self::OPTION_PREFIX . max( 0, (int) $post_id );
	}

	/** @return true|WP_Error */
	public static function sync( $post_id, $location, array $conditions ) {
		$post_id = (int) $post_id;
		$entry   = self::entry_payload( $post_id, $location, $conditions );
		if ( is_wp_error( $entry ) ) {
			return $entry;
		}
		$name    = self::row_name( $post_id );
		$encoded = maybe_serialize( $entry );

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt ) {
			try {
				$row = Mcpwp_Encryption::read_option_row( $name );
				if ( Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
					self::jitter( $attempt );
					continue;
				}
				if ( Mcpwp_Encryption::ROW_PRESENT === $row['state'] && self::matches( $row['value'], $entry ) ) {
					return true;
				}
				$written = Mcpwp_Encryption::ROW_ABSENT === $row['state']
					? Mcpwp_Encryption::insert_option_row_if_absent( $name, $encoded, 'no' )
					: self::compare_and_swap_hash( $name, $row['value'], $encoded );
				if ( $written ) {
					Mcpwp_Encryption::clean_option_cache( $name );
					if ( self::entry_matches( $name, $entry ) ) {
						self::invalidate_compatibility_view();
						return true;
					}
				}
			} catch ( \Throwable $exception ) {
				self::log_detail( $post_id, $exception );
			}
			self::jitter( $attempt );
		}

		return self::failure( $post_id );
	}

	/** @return true|WP_Error */
	public static function remove( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return true;
		}
		$name      = self::row_name( $post_id );
		$tombstone = array( 'id' => $post_id, 'deleted' => true );
		$encoded   = maybe_serialize( $tombstone );
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt ) {
			try {
				$row = Mcpwp_Encryption::read_option_row( $name );
				if ( Mcpwp_Encryption::ROW_PRESENT === $row['state'] && self::matches( $row['value'], $tombstone ) ) {
					return true;
				}
				$written = Mcpwp_Encryption::ROW_ABSENT === $row['state']
					? Mcpwp_Encryption::insert_option_row_if_absent( $name, $encoded, 'no' )
					: self::compare_and_swap_hash( $name, $row['value'], $encoded );
				if ( $written ) {
					Mcpwp_Encryption::clean_option_cache( $name );
					if ( self::entry_matches( $name, $tombstone ) ) {
						self::invalidate_compatibility_view();
						return true;
					}
				}
			} catch ( \Throwable $exception ) {
				self::log_detail( $post_id, $exception );
			}
			self::jitter( $attempt );
		}
		return self::failure( $post_id );
	}

	/** @return array|null */
	public static function entry_data( $post_id ) {
		$row = Mcpwp_Encryption::read_option_row( self::row_name( $post_id ) );
		if ( Mcpwp_Encryption::ROW_PRESENT !== $row['state'] ) {
			return null;
		}
		$entry = maybe_unserialize( $row['value'] );
		return self::valid_entry( $entry ) ? $entry : null;
	}

	/** @return array|null */
	public static function entry( $post_id ) {
		$entry = self::entry_data( $post_id );
		return is_array( $entry ) && empty( $entry['deleted'] ) ? $entry : null;
	}

	/**
	 * Bounded page of private entry rows. Compatibility code, never normal
	 * writes, may use this to project entries into Pro's legacy global view.
	 *
	 * @return array<int,array>
	 */
	public static function entries( $after_id = 0, $limit = 500 ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return array();
		}
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND OCTET_LENGTH(option_value) <= %d AND CAST(SUBSTRING(option_name, %d) AS UNSIGNED) > %d ORDER BY CAST(SUBSTRING(option_name, %d) AS UNSIGNED) ASC LIMIT %d",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				self::MAX_ENTRY_BYTES,
				strlen( self::OPTION_PREFIX ) + 1,
				(int) $after_id,
				strlen( self::OPTION_PREFIX ) + 1,
				$limit
			),
			ARRAY_A
		);
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
			if ( 0 !== strpos( $name, self::OPTION_PREFIX ) ) {
				continue;
			}
			$id    = (int) substr( $name, strlen( self::OPTION_PREFIX ) );
			$entry = maybe_unserialize( isset( $row['option_value'] ) ? $row['option_value'] : '' );
			if ( $id > (int) $after_id && self::valid_entry( $entry ) ) {
				$out[ $id ] = $entry;
			}
		}
		ksort( $out );
		return $out;
	}

	private static function entry_payload( $post_id, $location, array $conditions ) {
		if ( $post_id <= 0 || ! is_string( $location ) || '' === $location || count( $conditions ) > self::MAX_CONDITIONS || ! self::is_string_list( $conditions ) || self::conditions_bytes( $conditions ) > self::MAX_CONDITIONS_BYTES ) {
			return self::too_large( $post_id );
		}
		$entry = array( 'id' => $post_id, 'location' => $location, 'conditions' => array_values( $conditions ) );
		return strlen( maybe_serialize( $entry ) ) > self::MAX_ENTRY_BYTES ? self::too_large( $post_id ) : $entry;
	}

	private static function valid_entry( $entry ) {
		if ( ! is_array( $entry ) || ! isset( $entry['id'] ) || (int) $entry['id'] <= 0 ) {
			return false;
		}
		if ( ! empty( $entry['deleted'] ) ) {
			return true;
		}
		return isset( $entry['location'], $entry['conditions'] )
			&& is_string( $entry['location'] ) && '' !== $entry['location']
			&& is_array( $entry['conditions'] ) && count( $entry['conditions'] ) <= self::MAX_CONDITIONS
			&& self::is_string_list( $entry['conditions'] ) && self::conditions_bytes( $entry['conditions'] ) <= self::MAX_CONDITIONS_BYTES;
	}

	private static function matches( $raw, array $expected ) {
		$entry = maybe_unserialize( $raw );
		if ( ! self::valid_entry( $entry ) || (int) $entry['id'] !== (int) $expected['id'] ) {
			return false;
		}
		if ( ! empty( $expected['deleted'] ) ) {
			return ! empty( $entry['deleted'] );
		}
		return empty( $entry['deleted'] )
			&& $entry['location'] === $expected['location'] && $entry['conditions'] === $expected['conditions'];
	}

	private static function entry_matches( $name, array $expected ) {
		$row = Mcpwp_Encryption::read_option_row( $name );
		return Mcpwp_Encryption::ROW_PRESENT === $row['state'] && self::matches( $row['value'], $expected );
	}

	private static function compare_and_swap_hash( $name, $expected_raw, $next_raw ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND SHA2(option_value, 256) = %s",
				$next_raw, $name, hash( 'sha256', (string) $expected_raw )
			)
		);
		return 1 === (int) $rows;
	}

	private static function is_string_list( array $conditions ) {
		foreach ( $conditions as $condition ) {
			if ( ! is_string( $condition ) ) {
				return false;
			}
		}
		return true;
	}

	private static function conditions_bytes( array $conditions ) {
		$bytes = 0;
		foreach ( $conditions as $condition ) {
			if ( ! is_string( $condition ) ) {
				return self::MAX_CONDITIONS_BYTES + 1;
			}
			$bytes += strlen( $condition );
			if ( $bytes > self::MAX_CONDITIONS_BYTES ) {
				return $bytes;
			}
		}
		return $bytes;
	}

	private static function jitter( $attempt ) {
		if ( $attempt + 1 < self::MAX_ATTEMPTS ) {
			usleep( mt_rand( 20000, 90000 ) );
		}
	}

	private static function invalidate_compatibility_view() {
		if ( class_exists( 'Mcpwp_Custom_Code_Conditions' ) && method_exists( 'Mcpwp_Custom_Code_Conditions', 'invalidate_cache_view' ) ) {
			Mcpwp_Custom_Code_Conditions::invalidate_cache_view();
		}
	}

	private static function too_large( $post_id ) {
		return new WP_Error( 'conditions_too_large', __( 'Display conditions exceed the cache entry limit and were quarantined.', 'mcpwp' ), array( 'status' => 413, 'resource_id' => (int) $post_id, 'partial_write' => true, 'max_conditions' => self::MAX_CONDITIONS, 'max_bytes' => self::MAX_CONDITIONS_BYTES ) );
	}

	private static function failure( $post_id ) {
		return new WP_Error( 'conditions_cache_sync_failed', __( 'The snippet was saved but its display cache could not be verified. Read the snippet before retrying an update.', 'mcpwp' ), array( 'status' => 500, 'resource_id' => (int) $post_id, 'partial_write' => true, 'attempts' => self::MAX_ATTEMPTS ) );
	}

	private static function log_detail( $post_id, \Throwable $exception ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'MCPWP Custom Code cache entry update failed for snippet ' . (int) $post_id . ': ' . $exception->getMessage() );
	}
}
