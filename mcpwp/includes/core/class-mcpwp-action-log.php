<?php
/**
 * Immutable action audit log for MCP tool calls.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records every write-tool execution with before/after state for compliance and rollback.
 */
class Mcpwp_Action_Log {

	const TABLE = 'mcpwp_action_log';
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * #1031: when a category-restricted caller reads the log, visibility is
	 * decided in PHP (see is_entry_visible_to()) rather than in SQL — a
	 * resolved post's category can't be expressed as a WHERE clause the way
	 * the table's own columns can. That means the row set has to be fetched
	 * BEFORE the caller's requested LIMIT/OFFSET slice, or a hidden row could
	 * displace a visible one out of the page (the same reasoning
	 * Mcpwp_Approvals::list_requests()'s own $category_allowed docblock
	 * gives). Unlike approvals (an in-memory option, inherently bounded),
	 * this table has no such bound beyond the retention prune — so the
	 * pre-slice scan is capped here rather than left open-ended. A site
	 * whose matching row count exceeds this cap gets a correct (never
	 * over-disclosing) but possibly under-counted `total`/page beyond the
	 * cap window; it never shows a row the caller may not see.
	 */
	const CATEGORY_FILTER_SCAN_CAP = 5000;

	private static $post_write_tools = array(
		'wp_update_post', 'wp_update_page',
		'wp_bulk_update_posts', 'wp_bulk_update_pages',
		'wp_set_featured_image',
	);

	private static $elementor_write_tools = array(
		'wp_set_elementor', 'wp_edit_section', 'wp_add_section',
		'wp_remove_section', 'wp_edit_widget', 'wp_replace_section',
		'wp_patch_elementor',
	);

	private static $write_prefixes = array(
		'wp_create_', 'wp_update_', 'wp_delete_', 'wp_set_',
		'wp_add_', 'wp_remove_', 'wp_replace_', 'wp_edit_',
		'wp_bulk_', 'wp_patch_', 'wp_flush_', 'wp_trigger_',
		'wp_apply_', 'wp_assign_', 'wp_setup_', 'wp_configure_',
		'wp_import_', 'wp_regenerate_', 'wp_revoke_',
	);

	private static $pending = array();

	// ---- Public API ----

	/**
	 * Called BEFORE tool executes. Captures before-state. Returns log_id or null for non-write tools.
	 *
	 * @param string $tool_name  MCP tool name.
	 * @param array  $arguments  Tool arguments.
	 * @param string $api_key_id API key identifier.
	 * @param string $category   Tool category.
	 * @return string|null log_id or null.
	 */
	public static function begin( $tool_name, $arguments, $api_key_id = '', $category = '' ) {
		if ( ! self::is_write_tool( $tool_name ) ) {
			return null;
		}

		$resource    = self::detect_resource( $tool_name, $arguments );
		$before_snap = self::snapshot( $resource );
		$log_id      = self::generate_id();

		self::$pending[ $log_id ] = array(
			'resource'    => $resource,
			'before_snap' => $before_snap,
			'tool_name'   => $tool_name,
			'arguments'   => $arguments,
			'api_key_id'  => $api_key_id,
			'category'    => $category,
		);

		return $log_id;
	}

	/**
	 * Called AFTER tool executes. Writes full log row to DB.
	 *
	 * @param string|null $log_id      From begin(), null = skip.
	 * @param int         $duration_ms Execution duration.
	 * @param bool        $success     Whether the tool succeeded.
	 * @param string      $error_code  Error code or '' on success.
	 * @return void
	 */
	public static function complete( $log_id, $duration_ms, $success, $error_code = '' ) {
		if ( null === $log_id || ! isset( self::$pending[ $log_id ] ) ) {
			return;
		}

		$pending   = self::$pending[ $log_id ];
		$resource  = $pending['resource'];
		$tool_name = $pending['tool_name'];
		$arguments = $pending['arguments'];

		$after_snap         = $success ? self::snapshot( $resource ) : null;
		$rollback_supported = $success && self::is_rollback_supported( $tool_name, $resource );

		self::insert(
			$log_id,
			$tool_name,
			$pending['category'],
			$pending['api_key_id'],
			$arguments,
			$pending['before_snap'],
			$after_snap,
			$resource['type'],
			(string) $resource['id'],
			$duration_ms,
			$success ? 1 : 0,
			$error_code,
			$rollback_supported ? 1 : 0
		);

		unset( self::$pending[ $log_id ] );
	}

	/**
	 * Rollback a logged action by restoring its before-state.
	 *
	 * @param string        $log_id           Log entry ID.
	 * @param callable|null $category_allowed #1031: optional
	 *                                        `function(string $category): bool`
	 *                                        — the caller's own
	 *                                        `caller_may_read_category()`. A
	 *                                        hidden entry denies with the
	 *                                        exact same not_found error a
	 *                                        genuinely missing log_id
	 *                                        returns, so a category-
	 *                                        restricted key cannot use
	 *                                        rollback as an existence oracle
	 *                                        for an entry /action-log itself
	 *                                        would not list. Null (default):
	 *                                        unfiltered, matching every
	 *                                        existing caller unchanged.
	 * @return true|WP_Error
	 */
	public static function rollback( $log_id, $category_allowed = null ) {
		$entry = self::get_entry( $log_id );

		if ( ! $entry || ( is_callable( $category_allowed ) && ! self::is_entry_visible_to( $entry, $category_allowed ) ) ) {
			return new WP_Error( 'not_found', __( 'Log entry not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		if ( ! $entry['rollback_supported'] ) {
			return new WP_Error( 'not_rollbackable', __( 'This action does not support rollback.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		if ( $entry['rolled_back'] ) {
			return new WP_Error( 'already_rolled_back', __( 'This action has already been rolled back.', 'mcpwp' ), array( 'status' => 409 ) );
		}

		if ( empty( $entry['before_snap'] ) ) {
			return new WP_Error( 'no_snapshot', __( 'No before-snapshot available for rollback.', 'mcpwp' ), array( 'status' => 500 ) );
		}

		$snap = json_decode( $entry['before_snap'], true );
		if ( ! is_array( $snap ) ) {
			return new WP_Error( 'corrupt_snapshot', __( 'Before-snapshot is corrupt.', 'mcpwp' ), array( 'status' => 500 ) );
		}

		$result = self::apply_snapshot( $entry['resource_type'], $snap );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$rollback_log_id = self::begin( 'mcpwp_rollback', array( 'target_log_id' => $log_id ), '', 'audit' );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . self::TABLE,
			array(
				'rolled_back'    => 1,
				'rolled_back_at' => current_time( 'mysql', true ),
				'rolled_back_by' => $rollback_log_id ? $rollback_log_id : '',
			),
			array( 'log_id' => $log_id ),
			array( '%d', '%s', '%s' ),
			array( '%s' )
		);

		if ( $rollback_log_id ) {
			self::complete( $rollback_log_id, 0, true, '' );
		}

		return true;
	}

	/**
	 * List log entries.
	 *
	 * @param array $args Query args: limit, offset, tool_name, success, resource_type,
	 *                    resource_id, and `category_allowed` (#1031, optional):
	 *                    `function(string $category): bool` — the caller's own
	 *                    `caller_may_read_category()`, since only the REST
	 *                    controller holding the request has that context
	 *                    (same split as Mcpwp_Approvals::list_requests()'s own
	 *                    `$category_allowed` param). Every entry carries the
	 *                    category of the TOOL that wrote it; a generic,
	 *                    post-type-agnostic write tool (wp_update_post,
	 *                    wp_delete_post, wp_bulk_update_posts, ...) used
	 *                    against a post belonging to a DIFFERENT domain
	 *                    (a WooCommerce product, an Elementor template) logs
	 *                    under its own door's category, not the resource's —
	 *                    the same additive gap #1020/#1025 closed for
	 *                    /post-meta and /approvals. When given, an entry is
	 *                    dropped unless the caller holds BOTH the tool's own
	 *                    category AND, when the entry names a resolvable
	 *                    post (resource_type 'post' or 'elementor'), that
	 *                    post's real post_type category — see
	 *                    is_entry_visible_to(). Filtering happens BEFORE
	 *                    `total` is computed and before the offset/limit
	 *                    slice, so a hidden entry never displaces a visible
	 *                    one out of the page and is indistinguishable from
	 *                    one that was never written. Null (default):
	 *                    unfiltered, matching every existing caller
	 *                    (wp-admin's Activity Log / Control Room, CSV export
	 *                    with no category context) unchanged.
	 * @return array{ entries: array[], total: int }
	 */
	public static function list_entries( $args = array() ) {
		global $wpdb;

		$table             = $wpdb->prefix . self::TABLE;
		$limit             = min( 100, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 50 ) ) );
		$offset            = max( 0, (int) ( isset( $args['offset'] ) ? $args['offset'] : 0 ) );
		$category_allowed  = isset( $args['category_allowed'] ) && is_callable( $args['category_allowed'] ) ? $args['category_allowed'] : null;

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['tool_name'] ) ) {
			$where[]  = 'tool_name = %s';
			$values[] = sanitize_key( (string) $args['tool_name'] );
		}

		if ( isset( $args['success'] ) && '' !== $args['success'] ) {
			$where[]  = 'success = %d';
			$values[] = (int) $args['success'];
		}

		if ( ! empty( $args['resource_type'] ) ) {
			$where[]  = 'resource_type = %s';
			$values[] = sanitize_key( (string) $args['resource_type'] );
		}

		if ( ! empty( $args['resource_id'] ) ) {
			$where[]  = 'resource_id = %s';
			$values[] = sanitize_text_field( (string) $args['resource_id'] );
		}

		$where_sql = implode( ' AND ', $where );

		if ( null !== $category_allowed ) {
			// Visibility can't be expressed in the WHERE clause (it depends
			// on a resolved post's category, not a stored column) — fetch an
			// ordered, bounded working set matching the caller's OWN filters
			// first, then filter+slice in PHP. See CATEGORY_FILTER_SCAN_CAP's
			// docblock for the bound and its tradeoff.
			$scan_prepare_args = array_merge(
				array( "SELECT * FROM $table WHERE $where_sql ORDER BY timestamp DESC LIMIT %d" ),
				$values,
				array( self::CATEGORY_FILTER_SCAN_CAP )
			);
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$scan_rows = $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $scan_prepare_args ), ARRAY_A );

			$visible = array_values(
				array_filter(
					$scan_rows ? $scan_rows : array(),
					function ( $row ) use ( $category_allowed ) {
						return self::is_entry_visible_to( $row, $category_allowed );
					}
				)
			);

			return array(
				'entries' => array_map( array( __CLASS__, 'public_entry' ), array_slice( $visible, $offset, $limit ) ),
				'total'   => count( $visible ),
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_sql" );

		if ( ! empty( $values ) ) {
			$prepare_args = array_merge( array( "SELECT * FROM $table WHERE $where_sql ORDER BY timestamp DESC LIMIT %d OFFSET %d" ), $values, array( $limit, $offset ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( call_user_func_array( array( $wpdb, 'prepare' ), $prepare_args ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE 1=1 ORDER BY timestamp DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		}

		return array(
			'entries' => array_map( array( __CLASS__, 'public_entry' ), $rows ? $rows : array() ),
			'total'   => $total,
		);
	}

	/**
	 * Whether $entry is visible to a caller whose permission is decided by
	 * $category_allowed (#1031) — the single chokepoint list_entries(),
	 * get_entry_public() and rollback() all call, so "which entries exist at
	 * all for this key" cannot drift between the list door and the by-id
	 * doors, the same discipline Mcpwp_Approvals::is_approval_visible_to()
	 * already applies to approvals/events.
	 *
	 * Checks the tool's OWN category first — the one recorded on the row at
	 * write time (begin()'s $category, the tool→category map entry for
	 * whichever tool ran) — then, additively, the category of the POST the
	 * entry names, when it names a resolvable one. Both must pass: a
	 * WooCommerce product edited through the generic wp_update_post /
	 * wp_bulk_update_posts / wp_delete_post doors logs under 'content' (the
	 * door's own category), not 'woocommerce', so the tool-category check
	 * alone would still leak the product's id and activity to an
	 * [admin, content] key holding no woocommerce access — precisely #1031's
	 * own PoC, and the same additive reasoning #1025 round 2 (BLOCK-1)
	 * applied to a destructive delete queued through a generic approval
	 * door.
	 *
	 * @param array    $entry            Row (get_entry()'s ARRAY_A) or
	 *                                    public_entry() shape — both carry
	 *                                    'category', 'resource_type' and
	 *                                    'resource_id' under those names.
	 * @param callable $category_allowed function(string $category): bool —
	 *                                    the caller's own
	 *                                    caller_may_read_category().
	 * @return bool
	 */
	public static function is_entry_visible_to( array $entry, $category_allowed ) {
		if ( ! is_callable( $category_allowed ) ) {
			return true;
		}

		$tool_category = isset( $entry['category'] ) ? (string) $entry['category'] : '';
		if ( '' !== $tool_category && ! call_user_func( $category_allowed, $tool_category ) ) {
			return false;
		}

		$post_category = self::resource_post_category( $entry );
		if ( '' !== $post_category && ! call_user_func( $category_allowed, $post_category ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the tool-category home of the post an entry names, for
	 * is_entry_visible_to() (#1031) — '' when the entry's resource_type
	 * isn't a post-shaped kind ('post', 'elementor'; 'option' and 'other'
	 * never name a post) or the entry names no resource at all, meaning no
	 * additional check applies beyond the tool's own category.
	 *
	 * The action log stores only a generic resource_type ('post'/
	 * 'elementor'/'option'/'other') and a bare numeric id — never the
	 * post's real post_type — so, unlike Mcpwp_Approvals'
	 * compute_visibility_categories() (which captures the real type at
	 * creation time), this can only resolve the LIVE post. A post that has
	 * since been hard-deleted is treated as unresolvable rather than
	 * silently skipping the extra check — Mcpwp_Approvals::
	 * UNRESOLVABLE_CATEGORY makes that visible only to an unrestricted key,
	 * the same fail-closed answer #1025 round 2 gives a live approval whose
	 * target category can't be determined, reused here rather than a second
	 * sentinel meaning the same thing.
	 *
	 * @param array $entry Row or public_entry() shape.
	 * @return string Category slug, or '' when no additional check applies.
	 */
	private static function resource_post_category( array $entry ) {
		$resource_type = isset( $entry['resource_type'] ) ? (string) $entry['resource_type'] : '';
		if ( ! in_array( $resource_type, array( 'post', 'elementor' ), true ) ) {
			return '';
		}

		$resource_id = isset( $entry['resource_id'] ) ? (int) $entry['resource_id'] : 0;
		if ( $resource_id <= 0 ) {
			return '';
		}

		$post = get_post( $resource_id );
		if ( ! $post instanceof WP_Post ) {
			return class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::UNRESOLVABLE_CATEGORY : '';
		}

		return class_exists( 'Mcpwp_REST_API' ) ? Mcpwp_REST_API::category_for_post_type( $post->post_type ) : '';
	}

	/**
	 * Get one log entry (public fields).
	 *
	 * @param string        $log_id           Log entry ID.
	 * @param callable|null $category_allowed #1031: optional
	 *                                        `function(string $category): bool`
	 *                                        — see list_entries()'s docblock.
	 *                                        A hidden entry returns null, the
	 *                                        same shape a genuinely missing
	 *                                        log_id returns, so this door
	 *                                        cannot be used to confirm an
	 *                                        entry exists that the caller
	 *                                        could not see via the list door.
	 * @return array|null
	 */
	public static function get_entry_public( $log_id, $category_allowed = null ) {
		$entry = self::get_entry( $log_id );
		if ( ! $entry ) {
			return null;
		}

		if ( is_callable( $category_allowed ) && ! self::is_entry_visible_to( $entry, $category_allowed ) ) {
			return null;
		}

		return self::public_entry( $entry );
	}

	/**
	 * Export log entries as CSV string.
	 *
	 * @param array $args Same as list_entries args, max 1000.
	 * @return string CSV content.
	 */
	public static function export_csv( $args = array() ) {
		$args['limit']  = min( 1000, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 1000 ) ) );
		$args['offset'] = 0;
		$result         = self::list_entries( $args );
		$entries        = $result['entries'];

		$cols = array(
			'log_id', 'timestamp', 'tool_name', 'category', 'api_key_id',
			'resource_type', 'resource_id', 'duration_ms', 'success',
			'error_code', 'rollback_supported', 'rolled_back', 'rolled_back_at',
		);

		ob_start();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- writing to php://output stream for CSV export; WP_Filesystem does not support stream wrappers.
		$handle = fopen( 'php://output', 'w' );
		fputcsv( $handle, $cols );

		foreach ( $entries as $entry ) {
			$row = array();
			foreach ( $cols as $col ) {
				$row[] = isset( $entry[ $col ] ) ? $entry[ $col ] : '';
			}
			fputcsv( $handle, $row );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing php://output stream; WP_Filesystem not applicable here.
		fclose( $handle );
		return ob_get_clean();
	}

	/**
	 * Delete entries older than $days days.
	 *
	 * @param int $days Retention window.
	 * @return int Rows deleted.
	 */
	public static function prune( $days = 0 ) {
		global $wpdb;

		if ( $days <= 0 ) {
			$days = (int) get_option( 'mcpwp_action_log_retention_days', self::DEFAULT_RETENTION_DAYS );
		}

		if ( $days <= 0 ) {
			return 0;
		}

		$table  = $wpdb->prefix . self::TABLE;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE timestamp < %s", $cutoff ) );
	}

	// ---- Private helpers ----

	private static function is_write_tool( $tool_name ) {
		foreach ( self::$write_prefixes as $prefix ) {
			if ( 0 === strpos( $tool_name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_rollback_supported( $tool_name, $resource ) {
		if ( 'post' === $resource['type'] && in_array( $tool_name, self::$post_write_tools, true ) ) {
			return true;
		}
		if ( 'elementor' === $resource['type'] && in_array( $tool_name, self::$elementor_write_tools, true ) ) {
			return true;
		}
		return false;
	}

	private static function detect_resource( $tool_name, $arguments ) {
		if ( in_array( $tool_name, self::$elementor_write_tools, true ) ) {
			$id = isset( $arguments['id'] ) ? $arguments['id'] : ( isset( $arguments['page_id'] ) ? $arguments['page_id'] : '' );
			return array( 'type' => 'elementor', 'id' => (int) $id );
		}

		if ( in_array( $tool_name, self::$post_write_tools, true ) ) {
			$id = isset( $arguments['id'] ) ? $arguments['id'] : '';
			return array( 'type' => 'post', 'id' => (int) $id );
		}

		if ( preg_match( '/^wp_(create|delete|clone)_(post|page)$/', $tool_name ) ) {
			$id = isset( $arguments['id'] ) ? $arguments['id'] : '';
			return array( 'type' => 'post', 'id' => (int) $id );
		}

		if ( in_array( $tool_name, array( 'wp_update_option', 'wp_update_plugin_settings', 'wp_update_rate_limit' ), true ) ) {
			// The tool's argument is `key`; the older names are kept for
			// callers that still send them.
			$key = '';
			foreach ( array( 'key', 'option', 'option_key' ) as $name ) {
				if ( isset( $arguments[ $name ] ) && is_string( $arguments[ $name ] ) && '' !== $arguments[ $name ] ) {
					$key = $arguments[ $name ];
					break;
				}
			}
			return array( 'type' => 'option', 'id' => class_exists( 'Mcpwp_Option_Access' ) ? Mcpwp_Option_Access::sanitize_option_name( $key ) : sanitize_key( (string) $key ) );
		}

		return array( 'type' => 'other', 'id' => '' );
	}

	private static function snapshot( $resource ) {
		if ( empty( $resource['id'] ) && 'option' !== $resource['type'] ) {
			return null;
		}

		switch ( $resource['type'] ) {
			case 'post':
				return self::snapshot_post( (int) $resource['id'] );
			case 'elementor':
				return self::snapshot_elementor( (int) $resource['id'] );
			case 'option':
				return self::snapshot_option( (string) $resource['id'] );
			default:
				return null;
		}
	}

	private static function snapshot_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		return wp_json_encode(
			array(
				'post_id'      => $post_id,
				'post_title'   => $post->post_title,
				'post_content' => $post->post_content,
				'post_status'  => $post->post_status,
				'post_name'    => $post->post_name,
				'post_excerpt' => $post->post_excerpt,
				'post_type'    => $post->post_type,
				'thumbnail_id' => (int) get_post_thumbnail_id( $post_id ),
			)
		);
	}

	private static function snapshot_elementor( $post_id ) {
		if ( ! $post_id ) {
			return null;
		}
		return wp_json_encode(
			array(
				'post_id'        => $post_id,
				'elementor_data' => get_post_meta( $post_id, '_elementor_data', true ),
			)
		);
	}

	private static function snapshot_option( $option_key ) {
		if ( ! $option_key ) {
			return null;
		}

		$value = get_option( $option_key );

		// The action log is readable by anyone who can read the log; a
		// credential must never sit in it. An option that holds one is
		// recorded as withheld and cannot be rolled back through this path.
		if ( class_exists( 'Mcpwp_Option_Access' ) && ! empty( Mcpwp_Option_Access::secret_paths_in_write( $option_key, $value ) ) ) {
			return wp_json_encode(
				array(
					'option_key' => $option_key,
					'withheld'   => 'credential-shaped fields; rollback unavailable for this option',
				)
			);
		}

		return wp_json_encode(
			array(
				'option_key' => $option_key,
				'value'      => $value,
			)
		);
	}

	private static function apply_snapshot( $resource_type, $snap ) {
		switch ( $resource_type ) {
			case 'post':
				if ( empty( $snap['post_id'] ) ) {
					return new WP_Error( 'invalid_snap', __( 'Snapshot missing post_id.', 'mcpwp' ), array( 'status' => 500 ) );
				}
				$result = wp_update_post(
					array(
						'ID'           => (int) $snap['post_id'],
						'post_title'   => isset( $snap['post_title'] ) ? $snap['post_title'] : '',
						'post_content' => isset( $snap['post_content'] ) ? $snap['post_content'] : '',
						'post_status'  => isset( $snap['post_status'] ) ? $snap['post_status'] : 'publish',
						'post_name'    => isset( $snap['post_name'] ) ? $snap['post_name'] : '',
						'post_excerpt' => isset( $snap['post_excerpt'] ) ? $snap['post_excerpt'] : '',
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( isset( $snap['thumbnail_id'] ) ) {
					if ( $snap['thumbnail_id'] ) {
						set_post_thumbnail( (int) $snap['post_id'], (int) $snap['thumbnail_id'] );
					} else {
						delete_post_thumbnail( (int) $snap['post_id'] );
					}
				}
				return true;

			case 'elementor':
				if ( empty( $snap['post_id'] ) ) {
					return new WP_Error( 'invalid_snap', __( 'Snapshot missing post_id.', 'mcpwp' ), array( 'status' => 500 ) );
				}
				// A snapshot taken before #678 holds `css_id`, which Elementor
				// stores and never renders. Restoring it verbatim silently
				// un-applied that fix and the section's anchor stopped working,
				// while the rollback reported success (#737).
				$snap_data = isset( $snap['elementor_data'] ) ? $snap['elementor_data'] : '';

				// A snapshot of an array-stored tree is restored as JSON text,
				// the form every write stores (#845). Anything that is not a
				// tree is restored exactly as it was: that is what the page held.
				if ( is_array( $snap_data ) && class_exists( 'Mcpwp_Elementor_Stored_Tree' ) ) {
					$snap_tree = Mcpwp_Elementor_Stored_Tree::read( $snap_data );
					if ( is_array( $snap_tree['elements'] ) ) {
						$snap_data = (string) wp_json_encode( $snap_tree['elements'] );
					}
				}

				if ( class_exists( 'Mcpwp_Elementor_Element_Keys' ) ) {
					$snap_data = Mcpwp_Elementor_Element_Keys::normalize_json( $snap_data );
				}

				// Slashed: update_post_meta() unslashes what it stores, and
				// Elementor JSON escapes every quote inside a setting. Restored
				// unslashed, the page could not be decoded (#845).
				update_post_meta( (int) $snap['post_id'], '_elementor_data', wp_slash( $snap_data ) );
				delete_post_meta( (int) $snap['post_id'], '_elementor_css' );
				return true;

			case 'option':
				if ( empty( $snap['option_key'] ) ) {
					return new WP_Error( 'invalid_snap', __( 'Snapshot missing option_key.', 'mcpwp' ), array( 'status' => 500 ) );
				}
				if ( ! array_key_exists( 'value', $snap ) ) {
					return new WP_Error( 'snapshot_withheld', __( 'This option held credential-shaped fields, so its snapshot was withheld from the log. Rollback is not available; restore it in WP Admin.', 'mcpwp' ), array( 'status' => 409 ) );
				}
				$name = class_exists( 'Mcpwp_Option_Access' ) ? Mcpwp_Option_Access::sanitize_option_name( $snap['option_key'] ) : sanitize_key( $snap['option_key'] );
				if ( '' === $name ) {
					return new WP_Error( 'invalid_snap', __( 'Snapshot option_key is not a usable option name.', 'mcpwp' ), array( 'status' => 500 ) );
				}
				update_option( $name, $snap['value'] );
				return true;

			default:
				return new WP_Error( 'unsupported_type', __( 'Rollback not supported for this resource type.', 'mcpwp' ), array( 'status' => 400 ) );
		}
	}

	private static function insert(
		$log_id, $tool_name, $category, $api_key_id, $arguments,
		$before_snap, $after_snap, $resource_type, $resource_id,
		$duration_ms, $success, $error_code, $rollback_supported
	) {
		global $wpdb;

		$safe_args = $arguments;
		unset( $safe_args['api_key'], $safe_args['X-API-Key'] );

		// The arguments column is display-only (rollback reads the
		// snapshots); a credential inside them — a wp_update_option value,
		// an integration key — must not sit in the table (#800 round 6).
		if ( class_exists( 'Mcpwp_Option_Access' ) && method_exists( 'Mcpwp_Option_Access', 'redact_for_log' ) ) {
			$safe_args = Mcpwp_Option_Access::redact_for_log( $safe_args, Mcpwp_Option_Access::log_redaction_keys() );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'log_id'             => (string) $log_id,
				'timestamp'          => current_time( 'mysql', true ),
				'tool_name'          => (string) $tool_name,
				'category'           => (string) $category,
				'api_key_id'         => (string) $api_key_id,
				'arguments'          => wp_json_encode( $safe_args ) ? wp_json_encode( $safe_args ) : '',
				'before_snap'        => $before_snap,
				'after_snap'         => $after_snap,
				'resource_type'      => (string) $resource_type,
				'resource_id'        => (string) $resource_id,
				'duration_ms'        => (int) $duration_ms,
				'success'            => (int) $success,
				'error_code'         => (string) $error_code,
				'rollback_supported' => (int) $rollback_supported,
				'rolled_back'        => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d' )
		);
	}

	private static function get_entry( $log_id ) {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$log_id = sanitize_text_field( (string) $log_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE log_id = %s", $log_id ), ARRAY_A );

		return $row ? $row : null;
	}

	private static function public_entry( $row ) {
		if ( ! is_array( $row ) ) {
			return array();
		}
		return array(
			'log_id'             => $row['log_id'],
			'timestamp'          => $row['timestamp'],
			'tool_name'          => $row['tool_name'],
			'category'           => $row['category'],
			'api_key_id'         => $row['api_key_id'],
			'resource_type'      => $row['resource_type'],
			'resource_id'        => $row['resource_id'],
			'duration_ms'        => (int) $row['duration_ms'],
			'success'            => (bool) $row['success'],
			'error_code'         => $row['error_code'],
			'rollback_supported' => (bool) $row['rollback_supported'],
			'rolled_back'        => (bool) $row['rolled_back'],
			'rolled_back_at'     => $row['rolled_back_at'],
		);
	}

	private static function generate_id() {
		return 'act_' . str_replace( '-', '', wp_generate_uuid4() );
	}
}
