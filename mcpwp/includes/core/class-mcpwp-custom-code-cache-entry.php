<?php
/**
 * Per-snippet Conditions_Cache option convergence.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates one document entry with compare-and-swap rather than rebuilding
 * Elementor Pro's site-wide conditions cache under a process-wide mutex.
 */
class Mcpwp_Custom_Code_Cache_Entry {
	const OPTION       = 'elementor_pro_theme_builder_conditions';
	const MAX_ATTEMPTS = 3;

	/**
	 * Persist this document's exact entry, preserving every other document.
	 *
	 * @param int      $post_id    Snippet ID.
	 * @param string   $location   Elementor internal location.
	 * @param string[] $conditions Persisted condition strings.
	 * @return true|WP_Error
	 */
	public static function sync( $post_id, $location, array $conditions ) {
		if ( $post_id <= 0 || '' === $location || ! self::is_string_list( $conditions ) ) {
			return self::failure( $post_id );
		}

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt ) {
			try {
				$row = Mcpwp_Encryption::read_option_row( self::OPTION );
				if ( Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
					if ( $attempt + 1 < self::MAX_ATTEMPTS ) {
						usleep( mt_rand( 20000, 90000 ) );
					}
					continue;
				}

				$current = Mcpwp_Encryption::ROW_PRESENT === $row['state'] ? maybe_unserialize( $row['value'] ) : array();
				if ( ! is_array( $current ) ) {
					break;
				}
				$next = self::with_entry( $current, $post_id, $location, $conditions );
				// Cache maps are unordered; an already correct entry needs no CAS.
				// That is already success when the authoritative raw row agrees.
				if ( $next == $current && self::entry_matches( $post_id, $location, $conditions ) ) {
					return true;
				}
				$raw  = maybe_serialize( $next );
				$written = Mcpwp_Encryption::ROW_ABSENT === $row['state']
					? Mcpwp_Encryption::insert_option_row_if_absent( self::OPTION, $raw, 'no' )
					: Mcpwp_Encryption::compare_and_swap_option_row( self::OPTION, $row['value'], $raw );

				if ( $written ) {
					self::clean_option_caches();
					if ( self::entry_matches( $post_id, $location, $conditions ) ) {
						return true;
					}
				}
			} catch ( \Throwable $exception ) {
				self::log_detail( $post_id, $exception );
			}

			if ( $attempt + 1 < self::MAX_ATTEMPTS ) {
				usleep( mt_rand( 20000, 90000 ) );
			}
		}

		return self::failure( $post_id );
	}

	/** Remove one document from every cached location without touching peers. */
	public static function remove( $post_id ) {
		if ( $post_id <= 0 ) {
			return true;
		}
		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt ) {
			try {
				$row = Mcpwp_Encryption::read_option_row( self::OPTION );
				if ( Mcpwp_Encryption::ROW_ABSENT === $row['state'] ) {
					return true;
				}
				if ( Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
					break;
				}
				$current = maybe_unserialize( $row['value'] );
				if ( ! is_array( $current ) ) {
					break;
				}
				$next = self::without_entry( $current, $post_id, true );
				if ( $next === $current ) {
					return true;
				}
				if ( Mcpwp_Encryption::compare_and_swap_option_row( self::OPTION, $row['value'], maybe_serialize( $next ) ) ) {
					self::clean_option_caches();
					return true;
				}
			} catch ( \Throwable $exception ) {
				self::log_detail( $post_id, $exception );
			}
			if ( $attempt + 1 < self::MAX_ATTEMPTS ) {
				usleep( mt_rand( 20000, 90000 ) );
			}
		}
		return self::failure( $post_id );
	}

	private static function with_entry( array $cache, $post_id, $location, array $conditions ) {
		$cache = self::without_entry( $cache, $post_id );
		if ( ! isset( $cache[ $location ] ) || ! is_array( $cache[ $location ] ) ) {
			$cache[ $location ] = array();
		}
		$cache[ $location ][ (int) $post_id ] = array_values( $conditions );
		return $cache;
	}

	private static function without_entry( array $cache, $post_id, $drop_empty_locations = false ) {
		foreach ( $cache as $location => $documents ) {
			if ( is_array( $documents ) && array_key_exists( (int) $post_id, $documents ) ) {
				unset( $cache[ $location ][ (int) $post_id ] );
				if ( $drop_empty_locations && empty( $cache[ $location ] ) ) {
					unset( $cache[ $location ] );
				}
			}
		}
		return $cache;
	}

	private static function entry_matches( $post_id, $location, array $conditions ) {
		$row = Mcpwp_Encryption::read_option_row( self::OPTION );
		if ( Mcpwp_Encryption::ROW_PRESENT !== $row['state'] ) {
			return false;
		}
		$stored = maybe_unserialize( $row['value'] );
		return is_array( $stored )
			&& isset( $stored[ $location ] )
			&& is_array( $stored[ $location ] )
			&& isset( $stored[ $location ][ (int) $post_id ] )
			&& $stored[ $location ][ (int) $post_id ] === array_values( $conditions );
	}

	private static function clean_option_caches() {
		Mcpwp_Encryption::clean_option_cache( self::OPTION );
		wp_cache_delete( 'alloptions', 'options' );
	}

	private static function is_string_list( array $conditions ) {
		foreach ( $conditions as $condition ) {
			if ( ! is_string( $condition ) ) {
				return false;
			}
		}
		return true;
	}

	private static function failure( $post_id ) {
		return new WP_Error(
			'conditions_cache_sync_failed',
			__( 'The snippet was saved but its display cache could not be verified. Read the snippet before retrying an update.', 'mcpwp' ),
			array( 'status' => 500, 'resource_id' => (int) $post_id, 'partial_write' => true, 'attempts' => self::MAX_ATTEMPTS )
		);
	}

	private static function log_detail( $post_id, \Throwable $exception ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'MCPWP Custom Code cache entry update failed for snippet ' . (int) $post_id . ': ' . $exception->getMessage() );
	}
}
