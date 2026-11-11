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
	const MAX_CONDITIONS_BYTES = 16384; // Serialized condition-list bytes.
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
				$row = self::read_bounded_row( $name );
				if ( 'error' === $row['state'] ) {
					self::jitter( $attempt );
					continue;
				}
				if ( 'oversized' === $row['state'] ) {
					return self::too_large( $post_id );
				}
				if ( 'present' === $row['state'] && self::matches( $row['value'], $entry ) ) {
					return self::invalidate_compatibility_view() ? true : self::failure( $post_id );
				}
				$written = 'absent' === $row['state']
					? Mcpwp_Encryption::insert_option_row_if_absent( $name, $encoded, 'no' )
					: self::compare_and_swap_hash( $name, $row['hash'], $encoded );
				if ( $written ) {
					Mcpwp_Encryption::clean_option_cache( $name );
					if ( self::entry_matches( $name, $entry ) ) {
						return self::invalidate_compatibility_view() ? true : self::failure( $post_id );
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
				$row = self::read_bounded_row( $name );
				if ( 'present' === $row['state'] && self::matches( $row['value'], $tombstone ) ) {
					return self::invalidate_compatibility_view() ? true : self::failure( $post_id );
				}
				if ( 'error' === $row['state'] ) {
					self::jitter( $attempt );
					continue;
				}
				$written = 'absent' === $row['state']
					? Mcpwp_Encryption::insert_option_row_if_absent( $name, $encoded, 'no' )
					: self::compare_and_swap_hash( $name, $row['hash'], $encoded );
				if ( $written ) {
					Mcpwp_Encryption::clean_option_cache( $name );
					if ( self::entry_matches( $name, $tombstone ) ) {
						return self::invalidate_compatibility_view() ? true : self::failure( $post_id );
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
		$row = self::read_bounded_row( self::row_name( $post_id ) );
		if ( 'present' !== $row['state'] ) {
			return null;
		}
		$entry = maybe_unserialize( $row['value'] );
		return self::valid_entry( $entry ) && (int) $entry['id'] === (int) $post_id ? $entry : null;
	}

	/** @return array|null */
	public static function entry( $post_id ) {
		$entry = self::entry_data( $post_id );
		return is_array( $entry ) && empty( $entry['deleted'] ) ? $entry : null;
	}

	/**
	 * One bounded query for private entry rows. Oversize or malformed rows are
	 * named without selecting their payload so the compatibility view can fail
	 * closed and report the document ID without loading an arbitrary blob.
	 *
	 * @return array{entries:array<int,array>,invalid_ids:int[]}
	 */
	public static function entries() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return array( 'entries' => array(), 'invalid_ids' => array() );
		}
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS option_value, OCTET_LENGTH(option_value) AS entry_bytes FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
				self::MAX_ENTRY_BYTES,
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
			),
			ARRAY_A
		);
		$out     = array();
		$invalid = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
			if ( 0 !== strpos( $name, self::OPTION_PREFIX ) ) {
				continue;
			}
			$suffix = substr( $name, strlen( self::OPTION_PREFIX ) );
			if ( ! ctype_digit( $suffix ) || (int) $suffix <= 0 ) {
				continue;
			}
			$id = (int) $suffix;
			if ( ! isset( $row['option_value'] ) || null === $row['option_value'] ) {
				$invalid[] = $id;
				continue;
			}
			$entry = maybe_unserialize( $row['option_value'] );
			if ( self::valid_entry( $entry ) && (int) $entry['id'] === $id ) {
				$out[ $id ] = $entry;
			} else {
				$invalid[] = $id;
			}
		}
		ksort( $out );
		$invalid = array_values( array_unique( array_map( 'intval', $invalid ) ) );
		sort( $invalid );
		return array( 'entries' => $out, 'invalid_ids' => $invalid );
	}

	private static function entry_payload( $post_id, $location, array $conditions ) {
		if ( $post_id <= 0 || ! is_string( $location ) || '' === $location || count( $conditions ) > self::MAX_CONDITIONS || ! self::is_string_list( $conditions ) || self::conditions_serialized_bytes( $conditions ) > self::MAX_CONDITIONS_BYTES ) {
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
			&& self::is_string_list( $entry['conditions'] ) && self::conditions_serialized_bytes( $entry['conditions'] ) <= self::MAX_CONDITIONS_BYTES;
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
		$row = self::read_bounded_row( $name );
		return 'present' === $row['state'] && self::matches( $row['value'], $expected );
	}

	private static function compare_and_swap_hash( $name, $expected_hash, $next_raw ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND SHA2(option_value, 256) = %s",
				$next_raw, $name, (string) $expected_hash
			)
		);
		return 1 === (int) $rows;
	}

	/**
	 * Read length and digest before selecting an entry payload. This protects
	 * maintenance and normal writes from a malformed private row many MB long.
	 *
	 * @return array{state:string,value?:string,hash?:string,bytes?:int}
	 */
	private static function read_bounded_row( $name ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return array( 'state' => 'error' );
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT CASE WHEN OCTET_LENGTH(option_value) <= %d THEN option_value ELSE NULL END AS option_value, OCTET_LENGTH(option_value) AS entry_bytes, SHA2(option_value, 256) AS option_hash FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::MAX_ENTRY_BYTES,
				$name
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			return array( 'state' => 'error' );
		}
		if ( ! is_array( $row ) || ! array_key_exists( 'entry_bytes', $row ) ) {
			return array( 'state' => 'absent' );
		}
		$bytes = (int) $row['entry_bytes'];
		$hash  = isset( $row['option_hash'] ) ? (string) $row['option_hash'] : '';
		if ( $bytes > self::MAX_ENTRY_BYTES || ! isset( $row['option_value'] ) || null === $row['option_value'] ) {
			return array( 'state' => 'oversized', 'hash' => $hash, 'bytes' => $bytes );
		}
		return array( 'state' => 'present', 'value' => (string) $row['option_value'], 'hash' => $hash, 'bytes' => $bytes );
	}

	private static function is_string_list( array $conditions ) {
		foreach ( $conditions as $condition ) {
			if ( ! is_string( $condition ) ) {
				return false;
			}
		}
		return true;
	}

	private static function conditions_serialized_bytes( array $conditions ) {
		$raw_bytes = 0;
		foreach ( $conditions as $condition ) {
			if ( ! is_string( $condition ) ) {
				return self::MAX_CONDITIONS_BYTES + 1;
			}
			$raw_bytes += strlen( $condition );
			if ( $raw_bytes > self::MAX_CONDITIONS_BYTES ) {
				return $raw_bytes;
			}
		}
		return strlen( maybe_serialize( array_values( $conditions ) ) );
	}

	private static function jitter( $attempt ) {
		if ( $attempt + 1 < self::MAX_ATTEMPTS ) {
			usleep( mt_rand( 20000, 90000 ) );
		}
	}

	private static function invalidate_compatibility_view() {
		if ( class_exists( 'Mcpwp_Custom_Code_Conditions' ) && method_exists( 'Mcpwp_Custom_Code_Conditions', 'invalidate_cache_view' ) ) {
			Mcpwp_Custom_Code_Conditions::invalidate_cache_view();
			return Mcpwp_Custom_Code_Conditions::mark_view_dirty();
		}
		return true;
	}

	private static function too_large( $post_id ) {
		return new WP_Error(
			'conditions_too_large',
			__( 'Display conditions exceed the cache limit: at most 200 conditions, 16,384 serialized condition-list bytes, and 17,408 serialized entry bytes.', 'mcpwp' ),
			array(
				'status'                       => 413,
				'resource_id'                  => (int) $post_id,
				'partial_write'                => true,
				'max_conditions'               => self::MAX_CONDITIONS,
				'max_condition_serialized_bytes' => self::MAX_CONDITIONS_BYTES,
				'max_entry_serialized_bytes'   => self::MAX_ENTRY_BYTES,
			)
		);
	}

	private static function failure( $post_id ) {
		return new WP_Error( 'conditions_cache_sync_failed', __( 'The snippet was saved but its display cache could not be verified. Read the snippet before retrying an update.', 'mcpwp' ), array( 'status' => 500, 'resource_id' => (int) $post_id, 'partial_write' => true, 'attempts' => self::MAX_ATTEMPTS ) );
	}

	private static function log_detail( $post_id, \Throwable $exception ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'MCPWP Custom Code cache entry update failed for snippet ' . (int) $post_id . ': ' . $exception->getMessage() );
	}
}
