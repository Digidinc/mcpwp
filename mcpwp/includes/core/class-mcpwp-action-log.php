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
	 * #1031 round 2 (adversarial review, DESIGN item 6): a before_snap/
	 * after_snap over this size is not stored — a single wp_set_elementor
	 * save produced a 1.5MB after_snap in the field, and even a modest
	 * number of large-page write tools makes every full-row read of this
	 * table (get_entry(), rollback(), the pre-round-2 filtered list path)
	 * select gigabytes of TEXT it never needed. list_entries()/export_csv()
	 * never select these columns at all (see LIST_COLUMNS); this cap bounds
	 * the OTHER two readers — get_entry_public() and rollback(), which do
	 * need before_snap to restore from.
	 *
	 * A capped snapshot is replaced with a small `{"withheld": "..."}`
	 * marker (see cap_snapshot()) — the same shape snapshot_option() has
	 * always used for a credential-shaped value, and apply_snapshot() now
	 * refuses BOTH uniformly with 'snapshot_withheld', so a caller gets one
	 * clear, existing error code rather than a new one meaning the same
	 * thing. `snapshot_skipped` records which read the size cap, so a
	 * caller can tell "too large" from "nothing to snapshot" without
	 * decoding the (possibly absent) blob itself.
	 */
	const SNAPSHOT_MAX_BYTES = 524288; // 512 KB.

	/**
	 * Explicit column list for the two read paths that must never load
	 * before_snap/after_snap (#1031 round 2, DESIGN item 2 / B1): listing
	 * and exporting only ever need the columns public_entry() actually
	 * emits, so `SELECT *` here would load a possibly-multi-MB blob per row
	 * for nothing, on top of paying that cost per key that reads the log —
	 * exactly what made the round-1 fix's scan-and-filter approach fatal
	 * at scale.
	 */
	const LIST_COLUMNS = 'log_id, timestamp, tool_name, category, api_key_id, resource_type, resource_id, resource_post_type, snapshot_skipped, duration_ms, success, error_code, rollback_supported, rolled_back, rolled_back_at';

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

		$resource = self::detect_resource( $tool_name, $arguments );

		// #1031 round 2 (DESIGN item 1/5): resolved from the LIVE post HERE,
		// before the tool runs — so a delete's entry keeps the real
		// post_type even though the post is gone by the time anyone reads
		// the log. Stored on the row (see insert()) rather than resolved
		// again at read time, which is what let a hard-deleted target
		// become unclassifiable (and, in round 1's design, hidden from
		// everyone) — see resolve_resource_post_type()'s own docblock.
		$resource_post_type = self::resolve_resource_post_type( $resource );

		$before_cap  = self::cap_snapshot( self::snapshot( $resource ) );
		$log_id      = self::generate_id();

		self::$pending[ $log_id ] = array(
			'resource'            => $resource,
			'resource_post_type'  => $resource_post_type,
			'before_snap'         => $before_cap['value'],
			'before_snap_skipped' => $before_cap['skipped'],
			'tool_name'           => $tool_name,
			'arguments'           => $arguments,
			'api_key_id'          => $api_key_id,
			'category'            => $category,
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

		$after_cap          = $success ? self::cap_snapshot( self::snapshot( $resource ) ) : array( 'value' => null, 'skipped' => false );
		$snapshot_skipped   = ( $pending['before_snap_skipped'] || $after_cap['skipped'] ) ? 'too_large' : '';
		$rollback_supported = $success && self::is_rollback_supported( $tool_name, $resource );

		self::insert(
			$log_id,
			$tool_name,
			$pending['category'],
			$pending['api_key_id'],
			$arguments,
			$pending['before_snap'],
			$after_cap['value'],
			$resource['type'],
			(string) $resource['id'],
			$pending['resource_post_type'],
			$snapshot_skipped,
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
	 * @param string        $log_id            Log entry ID.
	 * @param array|null    $visibility        #1031 round 2: optional visibility
	 *                                         descriptor — see list_entries()'s own
	 *                                         docblock for the shape
	 *                                         (`categories`/`post_types`/
	 *                                         `caller_key_id`) and
	 *                                         is_entry_visible_to() for how it is
	 *                                         applied to a single row. A hidden entry
	 *                                         denies with the exact same not_found
	 *                                         error a genuinely missing log_id
	 *                                         returns, so a category-restricted key
	 *                                         cannot use rollback as an existence
	 *                                         oracle for an entry /action-log itself
	 *                                         would not list. Null (default):
	 *                                         unfiltered, matching every existing
	 *                                         caller unchanged.
	 * @param callable|null $template_ref_gate #1032 round 2 (NIT): the SAME
	 *                                         gate every other write path
	 *                                         uses (Mcpwp_Api_Auth::template_ref_gate()),
	 *                                         injected by the REST layer
	 *                                         (which has the caller's own
	 *                                         auth context; this class does
	 *                                         not). A rollback restores
	 *                                         `_elementor_data`/
	 *                                         `post_content` bytes verbatim —
	 *                                         without this, restoring an
	 *                                         OLDER snapshot could
	 *                                         reintroduce a Template Library
	 *                                         reference the CURRENT caller
	 *                                         cannot read, exactly the write-
	 *                                         time hole this whole issue is
	 *                                         about, just reached through
	 *                                         history instead of a fresh
	 *                                         write. null (a caller with no
	 *                                         auth context — WP-CLI, a
	 *                                         trusted internal call) means
	 *                                         unrestricted, matching every
	 *                                         other injected gate in this
	 *                                         plugin.
	 * @return true|WP_Error
	 */
	public static function rollback( $log_id, $visibility = null, $template_ref_gate = null ) {
		$entry = self::get_entry( $log_id );

		if ( ! $entry || ( null !== $visibility && ! self::is_entry_visible_to( $entry, $visibility ) ) ) {
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

		// #1032 round 2 (NIT): scan whichever of the two content fields THIS
		// snapshot carries — a no-op (empty scan) for every other
		// resource_type (option, term, menu, ...) this method also restores.
		if ( is_callable( $template_ref_gate ) && class_exists( 'Mcpwp_Elementor_Template_Refs' ) ) {
			$ref_ids = array();

			if ( ! empty( $snap['elementor_data'] ) ) {
				$elements = is_array( $snap['elementor_data'] )
					? $snap['elementor_data']
					: json_decode( (string) $snap['elementor_data'], true );
				if ( is_array( $elements ) ) {
					$ref_ids = array_merge( $ref_ids, Mcpwp_Elementor_Template_Refs::find_ids( $elements ) );
				}
			}

			if ( ! empty( $snap['post_content'] ) && is_string( $snap['post_content'] ) ) {
				$ref_ids = array_merge( $ref_ids, Mcpwp_Elementor_Template_Refs::ids_in_text( $snap['post_content'] ) );
			}

			if ( ! empty( $ref_ids ) ) {
				$ref_check = call_user_func( $template_ref_gate, array_values( array_unique( $ref_ids ) ) );
				if ( is_wp_error( $ref_check ) ) {
					return $ref_check;
				}
			}
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
	 *                    resource_id, and `visibility` (#1031 round 2, optional array):
	 *                    - `categories`  (string[]|null) every tool category the
	 *                      caller's ROLE allows (Mcpwp_Api_Auth::
	 *                      resolve_key_tool_categories() — role only, NOT the
	 *                      site-wide `mcpwp_disabled_tool_categories` toggle:
	 *                      disabling a category must not erase an
	 *                      unrestricted key's own view of its history —
	 *                      #1031 round 2 W4).
	 *                    - `post_types`  (string[]) every registered post type
	 *                      whose Mcpwp_REST_API::category_for_post_type() is
	 *                      in `categories` — computed once per request by
	 *                      the REST controller (only it has the request's
	 *                      key context), the same split
	 *                      Mcpwp_Approvals::list_requests()'s own
	 *                      `$category_allowed` docblock explains.
	 *                    - `caller_key_id` (string) the requesting key's own
	 *                      id — an entry THIS key wrote is always visible to
	 *                      it regardless of category (#1031 round 2 W3/
	 *                      DESIGN item 3: a key can otherwise lose sight of
	 *                      its own just-completed action, e.g. an Elementor
	 *                      save on a page that also carries a category the
	 *                      key's role does not hold).
	 *                    A null `visibility` (default) is unfiltered,
	 *                    matching every existing caller (wp-admin's Activity
	 *                    Log / Control Room, a caller with no key context)
	 *                    unchanged.
	 *
	 *                    Every entry carries the category of the TOOL that
	 *                    wrote it AND (since #1031 round 2) the real
	 *                    post_type of the resource it names, captured once
	 *                    at write time (see resolve_resource_post_type()).
	 *                    A generic, post-type-agnostic write tool
	 *                    (wp_update_post, wp_delete_post,
	 *                    wp_bulk_update_posts, ...) used against a post
	 *                    belonging to a DIFFERENT domain (a WooCommerce
	 *                    product, an Elementor template) logs under its own
	 *                    door's category, not the resource's — the same
	 *                    additive gap #1020/#1025 closed for /post-meta and
	 *                    /approvals. An entry is visible when EITHER the
	 *                    caller wrote it OR it holds every category its
	 *                    tool and resource require.
	 *
	 *                    Filtering happens entirely in SQL — COUNT(*) and
	 *                    the LIMIT/OFFSET page share the exact same WHERE —
	 *                    so `total` is always exact and a hidden entry can
	 *                    never be counted or displace a visible one out of
	 *                    the page (#1031 round 2 W1, replacing round 1's
	 *                    PHP-side scan-then-filter, which both loaded
	 *                    before_snap/after_snap for a bounded scan window
	 *                    AND gave a wrong total/pagination past that
	 *                    window — round-1 review B1/W1).
	 * @return array{ entries: array[], total: int }
	 */
	public static function list_entries( $args = array() ) {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$limit  = min( 100, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 50 ) ) );
		$offset = max( 0, (int) ( isset( $args['offset'] ) ? $args['offset'] : 0 ) );

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

		if ( isset( $args['visibility'] ) && is_array( $args['visibility'] ) ) {
			list( $visibility_sql, $visibility_values ) = self::build_visibility_sql( $args['visibility'] );
			if ( '' !== $visibility_sql ) {
				$where[]  = $visibility_sql;
				$values   = array_merge( $values, $visibility_values );
			}
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) ( empty( $values )
			? $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where_sql" )
			: $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where_sql", $values ) ) );

		$columns      = self::LIST_COLUMNS;
		$select_args  = array_merge( $values, array( $limit, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- never selects before_snap/after_snap (#1031 round 2 B1): $columns is the fixed LIST_COLUMNS constant, not caller input.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT $columns FROM $table WHERE $where_sql ORDER BY timestamp DESC LIMIT %d OFFSET %d", $select_args ),
			ARRAY_A
		);

		return array(
			'entries' => array_map( array( __CLASS__, 'public_entry' ), $rows ? $rows : array() ),
			'total'   => $total,
		);
	}

	/**
	 * Build the SQL fragment + bound values for a `visibility` descriptor
	 * (#1031 round 2) — the SQL-side twin of is_entry_visible_to(), which
	 * applies the exact same rule PHP-side to one already-fetched row for
	 * get_entry_public()/rollback(). The two must stay in lock-step: a
	 * caller who can't see an entry in the list must also 404 fetching it
	 * directly, and vice versa.
	 *
	 * `null` categories means unrestricted (skip filtering, handled by the
	 * caller of this function never invoking it at all — see
	 * list_entries()'s own null-`visibility` fast path); this function is
	 * only ever reached with a REAL restriction to express.
	 *
	 * @param array $visibility `categories` (string[]), `post_types`
	 *                          (string[]), `caller_key_id` (string).
	 * @return array{0:string,1:array} SQL fragment (already parenthesised,
	 *                                 '' when nothing to filter) and its
	 *                                 bound values, in the order the
	 *                                 fragment's placeholders appear.
	 */
	private static function build_visibility_sql( array $visibility ) {
		$categories = isset( $visibility['categories'] ) && is_array( $visibility['categories'] )
			? array_values( array_unique( array_map( 'sanitize_key', $visibility['categories'] ) ) )
			: null;

		if ( null === $categories ) {
			// Unrestricted — no predicate at all.
			return array( '', array() );
		}

		$post_types = isset( $visibility['post_types'] ) && is_array( $visibility['post_types'] )
			? array_values( array_unique( array_map( 'sanitize_key', $visibility['post_types'] ) ) )
			: array();

		$caller_key_id = isset( $visibility['caller_key_id'] ) ? (string) $visibility['caller_key_id'] : '';

		$values = array();

		if ( empty( $categories ) ) {
			// resolve_key_tool_categories() only ever returns [] to mean
			// "unrestricted" (handled by the null-categories branch above),
			// so a REAL restricted list reaching here empty would mean the
			// key's role allows nothing — refuse every category rather than
			// (via an empty SQL IN()) accidentally match everything.
			$category_predicate = '0=1';
		} else {
			$category_predicate = 'category IN (' . implode( ',', array_fill( 0, count( $categories ), '%s' ) ) . ')';
			$values              = array_merge( $values, $categories );
		}

		// #1031 round 3 (adversarial review N2): a row that names a SPECIFIC
		// post/elementor resource (resource_type in ('post','elementor') AND
		// a resource_id that is a real, positive post id) but whose
		// resource_post_type has not (yet) been captured or backfilled must
		// NOT get a free pass — that would silently widen visibility for
		// exactly the not-yet-backfilled window the migration exists to
		// close, or any future write path that forgets to set it. Only a
		// row that is NOT that kind of resource at all — an 'option'/'other'
		// entry, or resource_id '0' for a create that never captured a real
		// id — skips the post-type gate. `resource_id` is always a bare
		// decimal string for these two resource types (see detect_resource()),
		// never zero-padded, so this regex is exact, not approximate.
		$identified_post = "(resource_type IN ('post','elementor') AND resource_id REGEXP '^[1-9][0-9]*$')";

		if ( empty( $post_types ) ) {
			$type_predicate = "NOT $identified_post";
		} else {
			$type_predicate = "(NOT $identified_post OR resource_post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . '))';
			$values          = array_merge( $values, $post_types );
		}

		$sql = "($category_predicate AND $type_predicate)";

		if ( '' !== $caller_key_id ) {
			$sql     .= ' OR api_key_id = %s';
			$values[] = $caller_key_id;
		}

		return array( "($sql)", $values );
	}

	/**
	 * Whether $entry is visible to a caller described by $visibility
	 * (#1031 round 2) — the PHP-side twin of build_visibility_sql(), used
	 * by get_entry_public() and rollback() on a single already-fetched row
	 * rather than a fresh query. See build_visibility_sql()'s own docblock
	 * for why the two must agree, and list_entries()'s docblock for the
	 * `visibility` shape.
	 *
	 * @param array $entry      Row (get_entry()'s ARRAY_A) or
	 *                          public_entry() shape — both carry
	 *                          'category', 'api_key_id' and (since round 2)
	 *                          'resource_post_type' under those names.
	 * @param array $visibility See build_visibility_sql().
	 * @return bool
	 */
	public static function is_entry_visible_to( array $entry, array $visibility ) {
		$caller_key_id = isset( $visibility['caller_key_id'] ) ? (string) $visibility['caller_key_id'] : '';
		if ( '' !== $caller_key_id && isset( $entry['api_key_id'] ) && $caller_key_id === (string) $entry['api_key_id'] ) {
			return true;
		}

		$categories = isset( $visibility['categories'] ) && is_array( $visibility['categories'] ) ? $visibility['categories'] : null;
		if ( null === $categories ) {
			return true; // Unrestricted.
		}

		// #1031 round 3 (N1): '' is never a free pass for a restricted
		// caller — matches build_visibility_sql()'s `category IN (...)`,
		// which a genuinely empty category string also never satisfies.
		$tool_category = isset( $entry['category'] ) ? (string) $entry['category'] : '';
		if ( ! in_array( $tool_category, $categories, true ) ) {
			return false;
		}

		// #1031 round 3 (N2): only a row that IDENTIFIES a specific post/
		// elementor resource (a real, positive resource_id) is subject to
		// the post-type gate at all — see build_visibility_sql()'s own
		// docblock for why, and why the two must agree.
		$resource_type = isset( $entry['resource_type'] ) ? (string) $entry['resource_type'] : '';
		$resource_id   = isset( $entry['resource_id'] ) ? (int) $entry['resource_id'] : 0;
		if ( ! in_array( $resource_type, array( 'post', 'elementor' ), true ) || $resource_id <= 0 ) {
			return true;
		}

		$post_type = isset( $entry['resource_post_type'] ) ? (string) $entry['resource_post_type'] : '';
		if ( '' === $post_type ) {
			return false; // Identified, but unresolved/not-yet-backfilled — fail closed.
		}

		$post_types = isset( $visibility['post_types'] ) ? (array) $visibility['post_types'] : array();
		return in_array( $post_type, $post_types, true );
	}

	/**
	 * Get one log entry (public fields).
	 *
	 * @param string     $log_id     Log entry ID.
	 * @param array|null $visibility #1031 round 2: optional visibility
	 *                               descriptor — see list_entries()'s
	 *                               docblock. A hidden entry returns null,
	 *                               the same shape a genuinely missing
	 *                               log_id returns, so this door cannot be
	 *                               used to confirm an entry exists that the
	 *                               caller could not see via the list door.
	 * @return array|null
	 */
	public static function get_entry_public( $log_id, $visibility = null ) {
		$entry = self::get_entry( $log_id );
		if ( ! $entry ) {
			return null;
		}

		if ( null !== $visibility && ! self::is_entry_visible_to( $entry, $visibility ) ) {
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
		$requested_limit = min( 1000, max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 1000 ) ) );

		// #1031 round 2 NIT-N2: list_entries() itself caps every single call
		// at 100 (its own REST page-size ceiling) — export previously asked
		// list_entries() for up to 1000 in one call and silently got 100
		// back. Page through list_entries()'s own SQL LIMIT/OFFSET instead,
		// so export can actually reach its documented ceiling.
		$entries = array();
		$offset  = (int) ( isset( $args['offset'] ) ? $args['offset'] : 0 );

		while ( count( $entries ) < $requested_limit ) {
			$page_args           = $args;
			$page_args['limit']  = min( 100, $requested_limit - count( $entries ) );
			$page_args['offset'] = $offset;

			$page = self::list_entries( $page_args );
			if ( empty( $page['entries'] ) ) {
				break;
			}

			$entries = array_merge( $entries, $page['entries'] );
			$offset += count( $page['entries'] );

			if ( count( $page['entries'] ) < $page_args['limit'] ) {
				break; // Last page.
			}
		}

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

	/**
	 * Resolve a resource's real post_type at the moment begin() runs —
	 * i.e. BEFORE the tool executes (#1031 round 2, DESIGN item 1/5). For a
	 * delete, this is what makes the captured type survive the delete: by
	 * the time anyone reads the log the post is gone, but the type was
	 * already stored on the row at write time, never re-resolved live.
	 *
	 * '' for anything that isn't a post-shaped resource ('option', 'other')
	 * or whose id didn't resolve (a create, which has no id yet when
	 * begin() runs) — is_entry_visible_to()/build_visibility_sql() both
	 * treat '' as "no post-type check beyond the tool's own category",
	 * which is also the value every not-yet-backfilled pre-round-2 row
	 * already has.
	 *
	 * @param array $resource detect_resource()'s result.
	 * @return string Post type, or ''.
	 */
	private static function resolve_resource_post_type( array $resource ) {
		if ( ! in_array( $resource['type'], array( 'post', 'elementor' ), true ) ) {
			return '';
		}

		$post_id = isset( $resource['id'] ) ? (int) $resource['id'] : 0;
		if ( $post_id <= 0 ) {
			return '';
		}

		$post = get_post( $post_id );
		return $post instanceof WP_Post ? (string) $post->post_type : '';
	}

	/**
	 * Enforce SNAPSHOT_MAX_BYTES on a computed snapshot JSON string
	 * (#1031 round 2, DESIGN item 6). Over the cap, the real snapshot is
	 * not stored at all — replaced with a small marker carrying a
	 * `withheld` key, the same shape snapshot_option() already uses for a
	 * credential-shaped value, so apply_snapshot()'s existing
	 * 'snapshot_withheld' refusal covers both uniformly (see its own
	 * docblock).
	 *
	 * @param string|null $json Snapshot JSON, or null (nothing to snapshot).
	 * @return array{value:?string,skipped:bool}
	 */
	private static function cap_snapshot( $json ) {
		if ( null === $json ) {
			return array( 'value' => null, 'skipped' => false );
		}

		if ( strlen( $json ) <= self::SNAPSHOT_MAX_BYTES ) {
			return array( 'value' => $json, 'skipped' => false );
		}

		$marker = wp_json_encode(
			array(
				'withheld' => sprintf(
					/* translators: %d: size cap in KB. */
					__( 'Snapshot exceeded %d KB and was not stored; rollback is not available for this entry.', 'mcpwp' ),
					(int) ( self::SNAPSHOT_MAX_BYTES / 1024 )
				),
			)
		);

		return array( 'value' => false === $marker ? null : $marker, 'skipped' => true );
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
		// #1031 round 2: a snapshot withheld for size (cap_snapshot(), any
		// resource type) OR — pre-existing — a credential-shaped option
		// value (snapshot_option()) both carry a `withheld` key instead of
		// restorable data. Refuse uniformly, before any resource-type-
		// specific parsing, so every withheld entry gets the same clear
		// error regardless of WHY it was withheld.
		if ( isset( $snap['withheld'] ) ) {
			return new WP_Error( 'snapshot_withheld', (string) $snap['withheld'], array( 'status' => 409 ) );
		}

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
				if ( 'mcpwp_integrations' === $name ) {
					if ( ! is_array( $snap['value'] ) || ! Mcpwp_Integration_Manager::get_instance()->restore_provider_records( $snap['value'] ) ) {
						return new WP_Error( 'restore_failed', __( 'Provider records could not be restored.', 'mcpwp' ), array( 'status' => 503 ) );
					}
					return true;
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
		$resource_post_type, $snapshot_skipped,
		$duration_ms, $success, $error_code, $rollback_supported, $redact = true
	) {
		global $wpdb;

		$safe_args = $arguments;
		unset( $safe_args['api_key'], $safe_args['X-API-Key'] );

		// The arguments column is display-only (rollback reads the
		// snapshots); a credential inside them — a wp_update_option value,
		// an integration key — must not sit in the table (#800 round 6).
		// $redact is false only for rows this class builds itself from
		// fields that are never credentials (approval-removal audit rows:
		// ids and sha256 hashes, which the value-shape heuristic would
		// otherwise blank out).
		if ( $redact && class_exists( 'Mcpwp_Option_Access' ) && method_exists( 'Mcpwp_Option_Access', 'redact_for_log' ) ) {
			$safe_args = Mcpwp_Option_Access::redact_for_log( $safe_args, Mcpwp_Option_Access::log_redaction_keys() );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $wpdb->insert(
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
				'resource_post_type' => (string) $resource_post_type,
				'snapshot_skipped'   => (string) $snapshot_skipped,
				'duration_ms'        => (int) $duration_ms,
				'success'            => (int) $success,
				'error_code'         => (string) $error_code,
				'rollback_supported' => (int) $rollback_supported,
				'rolled_back'        => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%d' )
		);
	}

	/**
	 * Whether an approval_record_removed row already exists for this
	 * approval (#1042: one audit row per removed approval, even when a
	 * removal is retried).
	 *
	 * @param string $approval_id Approval id.
	 * @return bool|null Null when the log could not be read.
	 */
	public static function approval_removal_logged( $approval_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name is prefix + constant; uses the resource index.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE resource_type = %s AND resource_id = %s AND tool_name = %s", 'approval', (string) $approval_id, 'approval_record_removed' ) );
		if ( null === $count || '' !== (string) $wpdb->last_error ) {
			return null;
		}
		return (int) $count > 0;
	}

	/**
	 * Audit row for an approval record removed from the approvals store
	 * (#981 review round 5; #1042: retention or a purge). One row per
	 * record; the summary (approval id, action, target, requester,
	 * approvers, final status, timestamps, content hash, reason) is the
	 * row's arguments.
	 *
	 * @param array $summary Summary built by Mcpwp_Approvals.
	 * @return bool Whether the row was written.
	 */
	public static function record_approval_removal( array $summary ) {
		$approval_id = isset( $summary['approval_id'] ) ? (string) $summary['approval_id'] : '';
		if ( '' === $approval_id ) {
			return false;
		}

		// Only the known, non-credential summary fields are stored verbatim.
		$summary = array_intersect_key(
			$summary,
			array_flip( array( 'approval_id', 'action', 'resource_type', 'resource_id', 'requester', 'approvers', 'final_status', 'created_at', 'decided_at', 'applied_at', 'rolled_back_at', 'content_hash', 'removal_reason' ) )
		);

		$written = self::insert(
			self::generate_id(),
			'approval_record_removed',
			'admin',
			substr( isset( $summary['requester'] ) ? (string) $summary['requester'] : '', 0, 100 ),
			$summary,
			null,
			null,
			'approval',
			$approval_id,
			'',
			'',
			0,
			1,
			'',
			0,
			false
		);

		return false !== $written && null !== $written;
	}

	private static function get_entry( $log_id ) {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$log_id = sanitize_text_field( (string) $log_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single row by unique log_id; rollback needs before_snap, so this (unlike list_entries()) intentionally selects the full row.
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
			// #1031 round 2, DESIGN item 6: a cheap column, safe to include
			// in even the lean LIST_COLUMNS select — '' unless a snapshot on
			// this row was withheld for size.
			'snapshot_skipped'   => isset( $row['snapshot_skipped'] ) && '' !== $row['snapshot_skipped'] ? $row['snapshot_skipped'] : '',
		);
	}

	private static function generate_id() {
		return 'act_' . str_replace( '-', '', wp_generate_uuid4() );
	}
}
