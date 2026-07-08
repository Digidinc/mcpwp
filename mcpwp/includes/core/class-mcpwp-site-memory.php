<?php
/**
 * Structured site memory — persists AI decisions across sessions.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages structured site memory stored in wp_options.
 *
 * Memory is organized by namespace (identity, constraints, history, preferences, contacts)
 * and keyed entries. Each entry carries a value, optional TTL, and timestamps.
 */
class Mcpwp_Site_Memory {

	const OPTION_KEY = 'mcpwp_site_memory';

	const VALID_NAMESPACES = array( 'identity', 'constraints', 'history', 'preferences', 'contacts' );

	const MAX_ENTRIES_PER_NS = 200;

	/**
	 * Store or update a memory entry.
	 *
	 * @param string $namespace
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl_days  0 = no expiry.
	 * @return bool
	 */
	public static function remember( string $namespace, string $key, $value, int $ttl_days = 0 ): bool {
		$namespace = sanitize_key( $namespace );
		$key       = sanitize_key( $key );

		if ( ! in_array( $namespace, self::VALID_NAMESPACES, true ) ) {
			return false;
		}

		$memory = self::load();

		if ( ! isset( $memory[ $namespace ] ) ) {
			$memory[ $namespace ] = array();
		}

		// Enforce per-namespace cap (evict oldest on overflow).
		if ( ! isset( $memory[ $namespace ][ $key ] ) && count( $memory[ $namespace ] ) >= self::MAX_ENTRIES_PER_NS ) {
			uasort(
				$memory[ $namespace ],
				function ( $a, $b ) {
					return strcmp( $a['created_at'] ?? '', $b['created_at'] ?? '' );
				}
			);
			reset( $memory[ $namespace ] );
			unset( $memory[ $namespace ][ key( $memory[ $namespace ] ) ] );
		}

		$now = gmdate( 'c' );
		$memory[ $namespace ][ $key ] = array(
			'value'       => $value,
			'created_at'  => $memory[ $namespace ][ $key ]['created_at'] ?? $now,
			'updated_at'  => $now,
			'expires_at'  => $ttl_days > 0 ? gmdate( 'c', time() + $ttl_days * DAY_IN_SECONDS ) : null,
		);

		return update_option( self::OPTION_KEY, $memory, false );
	}

	/**
	 * Retrieve memory entries, optionally filtered by namespace and/or keyword query.
	 *
	 * @param string|null $namespace
	 * @param string|null $query     Keyword filter applied to key + value.
	 * @return array
	 */
	public static function recall( ?string $namespace = null, ?string $query = null ): array {
		$memory = self::load();
		$now    = gmdate( 'c' );
		$results = array();

		foreach ( $memory as $ns => $entries ) {
			if ( $namespace !== null && sanitize_key( $namespace ) !== $ns ) {
				continue;
			}

			foreach ( $entries as $key => $entry ) {
				// Skip expired.
				if ( ! empty( $entry['expires_at'] ) && $entry['expires_at'] < $now ) {
					continue;
				}

				// Keyword filter.
				if ( $query !== null ) {
					$haystack = strtolower( $key . ' ' . ( is_string( $entry['value'] ) ? $entry['value'] : wp_json_encode( $entry['value'] ) ) );
					if ( strpos( $haystack, strtolower( $query ) ) === false ) {
						continue;
					}
				}

				$results[] = array(
					'namespace'  => $ns,
					'key'        => $key,
					'value'      => $entry['value'],
					'updated_at' => $entry['updated_at'],
					'expires_at' => $entry['expires_at'],
				);
			}
		}

		return $results;
	}

	/**
	 * Delete a memory entry.
	 *
	 * @param string $namespace
	 * @param string $key
	 * @return bool
	 */
	public static function forget( string $namespace, string $key ): bool {
		$namespace = sanitize_key( $namespace );
		$key       = sanitize_key( $key );
		$memory    = self::load();

		if ( ! isset( $memory[ $namespace ][ $key ] ) ) {
			return false;
		}

		unset( $memory[ $namespace ][ $key ] );
		return update_option( self::OPTION_KEY, $memory, false );
	}

	/**
	 * List all memory entries, grouped by namespace.
	 *
	 * @param string|null $namespace
	 * @return array
	 */
	public static function list_all( ?string $namespace = null ): array {
		$memory = self::load();
		$now    = gmdate( 'c' );
		$out    = array();

		foreach ( $memory as $ns => $entries ) {
			// Skip internal markers (e.g. _migrated_site_context) — these are
			// bookkeeping flags, not namespaces, and must not leak into wp_recall.
			if ( '' !== $ns && '_' === $ns[0] ) {
				continue;
			}
			if ( ! is_array( $entries ) ) {
				continue;
			}
			if ( $namespace !== null && sanitize_key( $namespace ) !== $ns ) {
				continue;
			}

			$out[ $ns ] = array();
			foreach ( $entries as $key => $entry ) {
				if ( ! empty( $entry['expires_at'] ) && $entry['expires_at'] < $now ) {
					continue;
				}
				$out[ $ns ][ $key ] = array(
					'value'      => $entry['value'],
					'updated_at' => $entry['updated_at'],
					'expires_at' => $entry['expires_at'],
				);
			}
		}

		return $out;
	}

	/**
	 * Purge expired entries from all namespaces.
	 */
	public static function prune_expired(): void {
		$memory  = self::load();
		$now     = gmdate( 'c' );
		$changed = false;

		foreach ( $memory as $ns => $entries ) {
			foreach ( $entries as $key => $entry ) {
				if ( ! empty( $entry['expires_at'] ) && $entry['expires_at'] < $now ) {
					unset( $memory[ $ns ][ $key ] );
					$changed = true;
				}
			}
		}

		if ( $changed ) {
			update_option( self::OPTION_KEY, $memory, false );
		}
	}

	/**
	 * Migrate legacy mcpwp_site_context blob into identity namespace on first load.
	 */
	public static function maybe_migrate_site_context(): void {
		$memory = self::load();

		// Already migrated or no legacy context.
		if ( isset( $memory['_migrated_site_context'] ) ) {
			return;
		}

		$legacy = get_option( 'mcpwp_site_context', '' );
		if ( ! empty( $legacy ) ) {
			if ( ! isset( $memory['identity'] ) ) {
				$memory['identity'] = array();
			}

			if ( ! isset( $memory['identity']['site_context'] ) ) {
				$now = gmdate( 'c' );
				$memory['identity']['site_context'] = array(
					'value'      => $legacy,
					'created_at' => $now,
					'updated_at' => $now,
					'expires_at' => null,
				);
			}
		}

		$memory['_migrated_site_context'] = true;
		update_option( self::OPTION_KEY, $memory, false );
	}

	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array<string, array>>
	 */
	private static function load(): array {
		$data = get_option( self::OPTION_KEY, array() );
		return is_array( $data ) ? $data : array();
	}
}
