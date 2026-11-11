<?php
/**
 * Bounded persistent compatibility-view cache for Custom Code conditions.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A revisioned acceleration snapshot. It is deliberately never a source of
 * truth: source revisions invalidate it and printing checks live conditions.
 */
class Mcpwp_Custom_Code_Cache_View {
	const OPTION             = 'mcpwp_custom_code_conditions_view_v1';
	const GENERATION_OPTION = 'mcpwp_custom_code_conditions_view_generation';
	/* A repeated-condition view can exceed one MiB without being unsafe. */
	const MAX_SNAPSHOT_BYTES     = 4194304;
	const MAX_UNCOMPRESSED_BYTES = 16777216;
	const COMPRESSION_THRESHOLD  = 1048576;
	const CAPACITY_TRANSIENT     = 'mcpwp_custom_code_view_capacity';
	const MAX_META_BYTES     = 16384;
	const MAX_ENTRY_BYTES    = 17408;

	/** @var string|null The latest declined store reason for this request. */
	private static $last_store_failure = null;

	/** @return string|null A bounded reason suitable for an operator report. */
	public static function last_store_failure(): ?string {
		return self::$last_store_failure;
	}

	/** True while a bounded projection could not be retained. */
	public static function capacity_issue(): bool {
		return false !== get_transient( self::CAPACITY_TRANSIENT );
	}

	/** A short revision, updated only by WordPress source/cache mutation hooks. */
	public static function invalidate(): bool {
		if ( update_option( self::GENERATION_OPTION, wp_generate_uuid4(), false ) ) {
			return true;
		}
		// An unavailable revision row must never leave a stale snapshot live.
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) || false === $wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION ), array( '%s' ) ) ) {
			return false;
		}
		delete_option( self::OPTION );
		Mcpwp_Encryption::clean_option_cache( self::OPTION );
		return true;
	}

	public static function generation(): ?string {
		global $wpdb;
		$wpdb->last_error = '';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT CASE WHEN OCTET_LENGTH(option_value) <= 64 THEN option_value ELSE NULL END AS generation FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", self::GENERATION_OPTION
		), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return null === $row ? '' : ( isset( $row['generation'] ) && is_string( $row['generation'] ) ? $row['generation'] : null );
	}

	/** @return string|null A stable, bounded source fingerprint. */
	public static function fingerprint(): ?string {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return null;
		}

		/*
		 * The length sentinels make a too-large source row distinguishable
		 * without asking MySQL to materialize it for PHP. Domains prevent a
		 * postmeta row and an option row with identical fields cancelling out.
		 */
		$sql = "SELECT COUNT(*) AS row_count, "
			. "LOWER(LPAD(HEX(BIT_XOR(CAST(CONV(SUBSTRING(digest, 1, 16), 16, 10) AS UNSIGNED))), 16, '0')) AS xor_a, "
			. "LOWER(LPAD(HEX(BIT_XOR(CAST(CONV(SUBSTRING(digest, 17, 16), 16, 10) AS UNSIGNED))), 16, '0')) AS xor_b "
			. "FROM ("
			. "SELECT SHA2(CONCAT('meta|', pm.meta_id, '|', p.ID, '|', p.post_type, '|', p.post_status, '|', pm.meta_key, '|', CASE WHEN OCTET_LENGTH(pm.meta_value) > %d THEN CONCAT('oversize:', OCTET_LENGTH(pm.meta_value)) ELSE pm.meta_value END), 256) AS digest "
			. "FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id "
			. "WHERE p.post_type IN ('elementor_snippet', 'elementor_library') AND pm.meta_key IN ('_elementor_conditions', '_elementor_location', '_elementor_template_type') "
			. "UNION ALL "
			. "SELECT SHA2(CONCAT('entry|', option_name, '|', CASE WHEN OCTET_LENGTH(option_value) > %d THEN CONCAT('oversize:', OCTET_LENGTH(option_value)) ELSE option_value END), 256) AS digest "
			. "FROM {$wpdb->options} WHERE option_name LIKE %s"
			. ") AS mcpwp_custom_code_view_rows";

		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				$sql,
				self::MAX_META_BYTES,
				self::MAX_ENTRY_BYTES,
				$wpdb->esc_like( Mcpwp_Custom_Code_Cache_Entry::OPTION_PREFIX ) . '%'
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || ! isset( $row['row_count'], $row['xor_a'], $row['xor_b'] ) ) {
			return null;
		}
		$count = (string) $row['row_count'];
		$a     = strtolower( (string) $row['xor_a'] );
		$b     = strtolower( (string) $row['xor_b'] );
		if ( ! ctype_digit( $count ) || ! preg_match( '/^[0-9a-f]{16}$/', $a ) || ! preg_match( '/^[0-9a-f]{16}$/', $b ) ) {
			return null;
		}
		return $count . ':' . $a . ':' . $b;
	}

	/**
	 * @param array $base The freshly-built compatibility-view input.
	 * @return array{hit:bool,fingerprint?:string|null,cache?:array,invalid_ids?:array,skipped_ids?:array}
	 */
	public static function read( ?array $base = null ): array {
		$row = self::snapshot_raw();
		if ( null === $row || '' === $row['option_value'] ) {
			return array( 'hit' => false );
		}
		$snapshot = self::safe_snapshot( $row['option_value'] );
		if ( null === $snapshot || ( null !== $base && ! hash_equals( $snapshot['base_hash'], self::base_hash( $base ) ) ) ) {
			return array( 'hit' => false );
		}
		$current = array_key_exists( 'generation', $row ) ? $row['generation'] : self::generation();
		if ( null === $current || ! hash_equals( $snapshot['generation'], $current ) ) {
			return array( 'hit' => false, 'generation' => $current );
		}
		return array(
			'hit'         => true,
			'fingerprint' => $snapshot['fingerprint'],
			'cache'       => $snapshot['cache'],
			'invalid_ids' => $snapshot['invalid_ids'],
			'skipped_ids' => $snapshot['skipped_ids'],
		);
	}

	/**
	 * Return a structurally valid stored projection even when its source
	 * revision has moved. Callers use this only as a failure fallback; the
	 * rendering guard still checks live source data before code can execute.
	 */
	public static function read_last_good(): array {
		$row = self::snapshot_raw();
		if ( null === $row || '' === $row['option_value'] ) {
			return array( 'hit' => false );
		}
		$snapshot = self::safe_snapshot( $row['option_value'] );
		if ( null === $snapshot ) {
			return array( 'hit' => false );
		}
		return array(
			'hit'         => true,
			'fingerprint' => $snapshot['fingerprint'],
			'cache'       => $snapshot['cache'],
			'invalid_ids' => $snapshot['invalid_ids'],
			'skipped_ids' => $snapshot['skipped_ids'],
		);
	}

	/**
	 * Store only after a write event has rebuilt the complete view. The second
	 * fingerprint closes the read/build/write window without global locking.
	 */
	public static function store( array $base, array $cache, array $invalid_ids, array $skipped_ids, $fingerprint_before, $generation_before = null ): bool {
		self::$last_store_failure = null;
		if ( ! is_string( $fingerprint_before ) || '' === $fingerprint_before || ! self::valid_id_list( $invalid_ids ) || ! self::valid_id_list( $skipped_ids ) || ! self::valid_cache( $cache ) ) {
			self::$last_store_failure = 'invalid_snapshot';
			return false;
		}
		$generation_before = null === $generation_before ? self::generation() : $generation_before;
		if ( null === $generation_before || self::generation() !== $generation_before ) {
			self::$last_store_failure = 'generation_changed';
			return false;
		}
		$current = self::fingerprint();
		if ( null === $current || ! hash_equals( $fingerprint_before, $current ) ) {
			self::$last_store_failure = 'source_changed';
			return false;
		}
		if ( self::generation() !== $generation_before ) {
			self::$last_store_failure = 'generation_changed';
			return false;
		}
		$snapshot = array(
			'fingerprint' => $current,
			'generation' => $generation_before,
			'base_hash'   => self::base_hash( $base ),
			'cache'       => $cache,
			'invalid_ids' => array_values( $invalid_ids ),
			'skipped_ids' => array_values( $skipped_ids ),
			'built_at'    => time(),
		);
		$stored = self::compact_snapshot( $snapshot );
		if ( null === $stored ) {
			self::$last_store_failure = 'snapshot_too_large';
			self::note_capacity_issue();
			return false;
		}
		$stored_ok = (bool) update_option( self::OPTION, $stored, false );
		if ( $stored_ok ) {
			delete_transient( self::CAPACITY_TRANSIENT );
		}
		return $stored_ok;
	}

	/** @return array|null Read the bounded snapshot and its revision together. */
	private static function snapshot_raw(): ?array {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return null;
		}
		$wpdb->last_error = '';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT CASE WHEN OCTET_LENGTH(v.option_value) <= %d THEN v.option_value ELSE NULL END AS option_value, OCTET_LENGTH(v.option_value) AS snapshot_bytes, CASE WHEN g.option_value IS NULL THEN '' WHEN OCTET_LENGTH(g.option_value) <= 64 THEN g.option_value ELSE NULL END AS generation FROM {$wpdb->options} v LEFT JOIN {$wpdb->options} g ON g.option_name = %s WHERE v.option_name = %s LIMIT 1",
				self::MAX_SNAPSHOT_BYTES,
				self::GENERATION_OPTION,
				self::OPTION
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error || ! is_array( $row ) || ! isset( $row['snapshot_bytes'] ) || (int) $row['snapshot_bytes'] > self::MAX_SNAPSHOT_BYTES || ! array_key_exists( 'option_value', $row ) || null === $row['option_value'] ) {
			return null;
		}
		return is_string( $row['option_value'] ) ? $row : null;
	}

	/** @return array|null */
	private static function safe_snapshot( $raw ): ?array {
		if ( ! is_string( $raw ) || strlen( $raw ) > self::MAX_SNAPSHOT_BYTES || ! is_serialized( $raw ) ) {
			return null;
		}
		$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
		if ( is_array( $value ) && isset( $value['mcpwp_encoding'], $value['bytes'], $value['payload'] ) && 'deflate' === $value['mcpwp_encoding'] ) {
			$value = self::inflate_snapshot( $value );
		}
		if ( ! is_array( $value ) || ! isset( $value['fingerprint'], $value['base_hash'], $value['cache'], $value['invalid_ids'], $value['skipped_ids'], $value['built_at'], $value['generation'] ) || ! is_string( $value['fingerprint'] ) || ! is_string( $value['base_hash'] ) || ! is_array( $value['cache'] ) || ! self::valid_cache( $value['cache'] ) || ! is_array( $value['invalid_ids'] ) || ! is_array( $value['skipped_ids'] ) || ! self::valid_id_list( $value['invalid_ids'] ) || ! self::valid_id_list( $value['skipped_ids'] ) || ! is_int( $value['built_at'] ) || ! is_string( $value['generation'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $value['base_hash'] ) ) {
			return null;
		}
		return $value;
	}

	/** @return array|null */
	private static function inflate_snapshot( array $value ): ?array {
		$bytes = $value['bytes'];
		$payload = $value['payload'];
		if ( ! is_int( $bytes ) || $bytes < self::COMPRESSION_THRESHOLD || $bytes > self::MAX_UNCOMPRESSED_BYTES || ! is_string( $payload ) || ! function_exists( 'gzinflate' ) ) {
			return null;
		}
		$compressed = base64_decode( $payload, true );
		if ( false === $compressed || strlen( $compressed ) > self::MAX_SNAPSHOT_BYTES ) {
			return null;
		}
		$raw = @gzinflate( $compressed, self::MAX_UNCOMPRESSED_BYTES + 1 );
		if ( false === $raw || strlen( $raw ) !== $bytes || ! is_serialized( $raw ) ) {
			return null;
		}
		$decoded = @unserialize( $raw, array( 'allowed_classes' => false ) );
		return is_array( $decoded ) ? $decoded : null;
	}

	/** @return array|null A direct small snapshot or a bounded compressed wrapper. */
	private static function compact_snapshot( array $snapshot ): ?array {
		$raw = maybe_serialize( $snapshot );
		$bytes = strlen( $raw );
		if ( $bytes <= self::COMPRESSION_THRESHOLD ) {
			return $snapshot;
		}
		if ( $bytes > self::MAX_UNCOMPRESSED_BYTES ) {
			return null;
		}
		/* Hosts without zlib still retain a bounded four-MiB raw projection. */
		if ( ! function_exists( 'gzdeflate' ) ) {
			return $bytes <= self::MAX_SNAPSHOT_BYTES ? $snapshot : null;
		}
		$compressed = @gzdeflate( $raw, 6 );
		if ( false === $compressed ) {
			return $bytes <= self::MAX_SNAPSHOT_BYTES ? $snapshot : null;
		}
		$stored = array(
			'mcpwp_encoding' => 'deflate',
			'bytes'           => $bytes,
			'payload'         => base64_encode( $compressed ),
		);
		return strlen( maybe_serialize( $stored ) ) <= self::MAX_SNAPSHOT_BYTES ? $stored : null;
	}

	/** Record an over-cap view once per ten-minute reporting window. */
	private static function note_capacity_issue(): void {
		if ( false !== get_transient( self::CAPACITY_TRANSIENT ) ) {
			return;
		}
		set_transient( self::CAPACITY_TRANSIENT, 'snapshot_too_large', 10 * MINUTE_IN_SECONDS );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'MCPWP Custom Code cache view exceeds its bounded storage capacity.' );
	}

	private static function base_hash( array $base ): string {
		return hash( 'sha256', maybe_serialize( $base ) );
	}

	private static function valid_id_list( array $ids ): bool {
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 0 ) {
				return false;
			}
		}
		return true;
	}

	/** The projection is a location map of document IDs to condition strings. */
	private static function valid_cache( array $cache ): bool {
		foreach ( $cache as $location => $entries ) {
			if ( ! is_string( $location ) || ! is_array( $entries ) ) {
				return false;
			}
			foreach ( $entries as $conditions ) {
				if ( ! is_array( $conditions ) || count( $conditions ) > 200 ) {
					return false;
				}
				foreach ( $conditions as $condition ) {
					if ( ! is_string( $condition ) ) {
						return false;
					}
				}
			}
		}
		return true;
	}
}
