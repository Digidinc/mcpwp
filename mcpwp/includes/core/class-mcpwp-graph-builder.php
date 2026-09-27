<?php
/**
 * Site graph builder — the single extraction + persistence chokepoint for the
 * `mcpwp_graph_nodes` / `mcpwp_graph_edges` tables (#943 S1).
 *
 * Extraction here is the SAME parsing logic `class-mcpwp-rest-content-graph.php`
 * and `class-mcpwp-rest-api.php` already used for the live, request-scoped
 * content graph (link parsing, Elementor `_elementor_data` link/text
 * extraction, taxonomy, menu depth, freshness/hub/orphan scoring) — moved here
 * so there is exactly one parser, not two. Those REST classes now delegate to
 * the static methods below; their request/response shapes are unchanged
 * (verified by the existing content-graph/SEO-audit/content-quality test
 * coverage staying green).
 *
 * New in this class (nothing to move — the REST-time graph never persisted or
 * tracked these): the incremental dirty-set + shutdown rebuild, the WP-Cron/
 * Action Scheduler backfill, and media/term/parent edge extraction for the
 * persisted graph.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and maintains the persisted site graph.
 */
class Mcpwp_Graph_Builder {

	/** Option holding node keys that overflowed the per-request shutdown budget. */
	const DIRTY_OPTION = 'mcpwp_graph_dirty';

	/** Cron hook that drains DIRTY_OPTION on a schedule, independent of backfill state. */
	const DIRTY_DRAIN_CRON_HOOK = 'mcpwp_graph_dirty_drain';

	/** Cron/Action-Scheduler hook for one backfill batch. */
	const BACKFILL_CRON_HOOK = 'mcpwp_graph_backfill';

	/** Highest post ID already backfilled. */
	const BACKFILL_CURSOR_OPTION = 'mcpwp_graph_backfill_cursor';

	/** pending|running|done. */
	const BACKFILL_STATUS_OPTION = 'mcpwp_graph_backfill_status';

	/** Posts processed per backfill batch. */
	const BACKFILL_BATCH_SIZE = 50;

	/** Max seconds a single backfill batch may run. */
	const BACKFILL_TIME_BUDGET = 10.0;

	/** Max dirty nodes rebuilt inline at `shutdown`. */
	const SHUTDOWN_LIMIT = 25;

	/** Max dirty-option entries drained per DIRTY_DRAIN_CRON_HOOK run. */
	const DIRTY_DRAIN_BATCH = 25;

	/** Request-local dirty set — node_key => true, insertion order preserved. */
	private static $dirty_nodes = array();

	// -------------------------------------------------------------------------
	// Hook handlers — mark dirty only. The actual rebuild happens at shutdown
	// (or in a later cron batch), so a request that touches many posts pays
	// for parsing once per node, not once per hook fire.
	// -------------------------------------------------------------------------

	/**
	 * `save_post` handler.
	 *
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public static function on_save_post( $post_id, $post = null ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::mark_dirty( self::node_key_for_post( $post_id ) );
	}

	/**
	 * `deleted_post` handler. Fires after the row is gone, so the shutdown
	 * rebuild's "post not found" branch removes the node — no separate
	 * delete path needed.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_deleted_post( $post_id ) {
		self::mark_dirty( self::node_key_for_post( (int) $post_id ) );
	}

	/**
	 * `set_object_terms` handler — a post's in_term edges depend on its terms.
	 *
	 * @param int $object_id Object (post) ID.
	 */
	public static function on_set_object_terms( $object_id ) {
		self::mark_dirty( self::node_key_for_post( (int) $object_id ) );
	}

	/**
	 * `edited_term` handler.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy.
	 */
	public static function on_edited_term( $term_id, $tt_id, $taxonomy ) {
		self::mark_dirty( self::node_key_for_term( (string) $taxonomy, (int) $term_id ) );
	}

	/**
	 * `delete_term` handler.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy.
	 */
	public static function on_delete_term( $term_id, $tt_id, $taxonomy ) {
		self::mark_dirty( self::node_key_for_term( (string) $taxonomy, (int) $term_id ) );
	}

	/**
	 * Add a node key to the request-local dirty set.
	 *
	 * @param string $node_key Node key.
	 */
	public static function mark_dirty( $node_key ) {
		$node_key = (string) $node_key;
		if ( '' === $node_key ) {
			return;
		}
		self::$dirty_nodes[ $node_key ] = true;
	}

	/**
	 * Read the current request-local dirty set (test/introspection helper).
	 *
	 * @return array Node keys.
	 */
	public static function get_pending_dirty_nodes() {
		return array_keys( self::$dirty_nodes );
	}

	/**
	 * Clear the request-local dirty set without rebuilding (test helper).
	 */
	public static function reset_pending_dirty_nodes() {
		self::$dirty_nodes = array();
	}

	// -------------------------------------------------------------------------
	// Shutdown rebuild
	// -------------------------------------------------------------------------

	/**
	 * `shutdown` handler: rebuild up to SHUTDOWN_LIMIT dirty nodes inline;
	 * anything left over goes to DIRTY_OPTION for the drain cron to pick up.
	 */
	public static function on_shutdown() {
		$keys = array_keys( self::$dirty_nodes );
		self::$dirty_nodes = array();

		if ( empty( $keys ) ) {
			return;
		}

		$batch    = array_slice( $keys, 0, self::SHUTDOWN_LIMIT );
		$overflow = array_slice( $keys, self::SHUTDOWN_LIMIT );

		foreach ( $batch as $node_key ) {
			self::rebuild_node_safely( $node_key );
		}

		if ( ! empty( $overflow ) ) {
			self::add_to_dirty_option( $overflow );
		}
	}

	/**
	 * Drain up to DIRTY_DRAIN_BATCH entries from the overflow option. Runs on
	 * its own cron independent of backfill state, and is also called
	 * opportunistically at the top of every backfill batch.
	 */
	public static function drain_dirty_batch() {
		$existing = get_option( self::DIRTY_OPTION, array() );
		if ( ! is_array( $existing ) || empty( $existing ) ) {
			return;
		}

		$batch     = array_slice( $existing, 0, self::DIRTY_DRAIN_BATCH );
		$remaining = array_slice( $existing, self::DIRTY_DRAIN_BATCH );

		// Persist the remainder BEFORE rebuilding, so a fatal mid-batch does
		// not silently drop the tail back into an unbounded option forever.
		update_option( self::DIRTY_OPTION, $remaining, false );

		foreach ( $batch as $node_key ) {
			self::rebuild_node_safely( $node_key );
		}
	}

	/**
	 * Rebuild one node, never letting a parse/DB error fatal the request.
	 *
	 * @param string $node_key Node key.
	 */
	private static function rebuild_node_safely( $node_key ) {
		try {
			self::rebuild_node( $node_key );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'MCPWP graph rebuild error for ' . $node_key . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Merge overflowed node keys into DIRTY_OPTION (deduplicated).
	 *
	 * @param array $node_keys Node keys.
	 */
	private static function add_to_dirty_option( array $node_keys ) {
		$existing = get_option( self::DIRTY_OPTION, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$merged = array_values( array_unique( array_merge( $existing, $node_keys ) ) );
		update_option( self::DIRTY_OPTION, $merged, false );
	}

	/**
	 * Dispatch a rebuild by node key prefix.
	 *
	 * @param string $node_key Node key.
	 */
	public static function rebuild_node( $node_key ) {
		if ( 0 === strpos( $node_key, 'post:' ) ) {
			self::rebuild_post_node( (int) substr( $node_key, 5 ) );
			return;
		}
		if ( 0 === strpos( $node_key, 'term:' ) ) {
			$parts = explode( ':', $node_key, 3 );
			if ( 3 === count( $parts ) ) {
				self::rebuild_term_node( $parts[1], (int) $parts[2] );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Node keys
	// -------------------------------------------------------------------------

	/**
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function node_key_for_post( $post_id ) {
		return 'post:' . (int) $post_id;
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @return string
	 */
	public static function node_key_for_term( $taxonomy, $term_id ) {
		return 'term:' . sanitize_key( (string) $taxonomy ) . ':' . (int) $term_id;
	}

	/**
	 * @param int $media_id Attachment ID.
	 * @return string
	 */
	public static function node_key_for_media( $media_id ) {
		return 'media:' . (int) $media_id;
	}

	// -------------------------------------------------------------------------
	// Post node rebuild
	// -------------------------------------------------------------------------

	/**
	 * Rebuild (or delete) the node + builder-sourced edges for one post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function rebuild_post_node( $post_id ) {
		$post_id  = (int) $post_id;
		$node_key = self::node_key_for_post( $post_id );
		$post     = get_post( $post_id );

		if ( ! $post || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			self::delete_node_and_edges( $node_key );
			return;
		}

		$post_type_obj = get_post_type_object( $post->post_type );
		if ( ! $post_type_obj || ( empty( $post_type_obj->public ) && empty( $post_type_obj->publicly_queryable ) ) ) {
			self::delete_node_and_edges( $node_key );
			return;
		}

		$term_refs  = self::extract_term_refs( $post );
		$terms_hash = self::terms_hash( $term_refs );
		$new_hash   = self::content_hash( $post );

		$existing = self::read_node_hashes( $node_key );
		if ( $existing && $existing['content_hash'] === $new_hash && $existing['terms_hash'] === $terms_hash ) {
			return; // Nothing changed — skip the rebuild entirely.
		}

		$props = array(
			'template'     => (string) ( function_exists( 'get_page_template_slug' ) ? get_page_template_slug( $post_id ) : '' ),
			'builder'      => self::detect_builder( $post_id ),
			'lang'         => '',
			'parent'       => (int) $post->post_parent,
			'modified_gmt' => get_post_modified_time( 'c', true, $post ),
			'terms_hash'   => $terms_hash,
		);

		self::upsert_node(
			array(
				'node_key'     => $node_key,
				'kind'         => $post->post_type,
				'ref_id'       => $post_id,
				'label'        => get_the_title( $post ),
				'url'          => (string) get_permalink( $post ),
				'status'       => $post->post_status,
				'content_hash' => $new_hash,
				'word_count'   => str_word_count( wp_strip_all_tags( (string) $post->post_content ) ),
				'props'        => wp_json_encode( $props ),
			)
		);

		// Replace this node's builder-owned edges: its own outgoing
		// links_to/in_term/uses_media, and the one incoming parent_of edge
		// it is authoritative for (parent_of is stored parent -> child, but
		// only the CHILD's post_parent determines whether it exists).
		self::delete_builder_edges_for_node( $node_key );

		foreach ( self::extract_post_link_targets( $post ) as $target_id ) {
			self::insert_edge( $node_key, self::node_key_for_post( $target_id ), 'links_to', 1.0, 'builder', array() );
		}

		foreach ( $term_refs as $term_ref ) {
			$term_key = self::node_key_for_term( $term_ref['taxonomy'], $term_ref['term_id'] );
			self::ensure_term_node( $term_ref['taxonomy'], $term_ref['term_id'], $term_ref['name'] );
			self::insert_edge( $node_key, $term_key, 'in_term', 1.0, 'builder', array() );
		}

		foreach ( self::extract_media_refs( $post ) as $media_id ) {
			$media_key = self::node_key_for_media( $media_id );
			self::ensure_media_node( $media_id );
			self::insert_edge( $node_key, $media_key, 'uses_media', 1.0, 'builder', array() );
		}

		if ( $post->post_parent && (int) $post->post_parent !== $post_id ) {
			self::insert_edge( self::node_key_for_post( (int) $post->post_parent ), $node_key, 'parent_of', 1.0, 'builder', array() );
		}
	}

	/**
	 * Rebuild (or delete) a term node. Term nodes carry no outgoing builder
	 * edges of their own — the in_term edges that reference them are owned
	 * by each post's rebuild.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 */
	public static function rebuild_term_node( $taxonomy, $term_id ) {
		$node_key = self::node_key_for_term( $taxonomy, $term_id );
		$term     = get_term( $term_id, $taxonomy );

		if ( ! $term || is_wp_error( $term ) ) {
			self::delete_node_and_edges( $node_key );
			return;
		}

		self::upsert_node(
			array(
				'node_key'     => $node_key,
				'kind'         => 'term',
				'ref_id'       => $term_id,
				'label'        => (string) $term->name,
				'url'          => (string) get_term_link( $term, $taxonomy ),
				'status'       => '',
				'content_hash' => '',
				'word_count'   => 0,
				'props'        => wp_json_encode( array( 'taxonomy' => $taxonomy ) ),
			)
		);
	}

	/**
	 * Ensure a lightweight term node exists (label/url kept current).
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param int    $term_id  Term ID.
	 * @param string $name     Term name.
	 */
	private static function ensure_term_node( $taxonomy, $term_id, $name ) {
		self::upsert_node(
			array(
				'node_key'     => self::node_key_for_term( $taxonomy, $term_id ),
				'kind'         => 'term',
				'ref_id'       => $term_id,
				'label'        => (string) $name,
				'url'          => '',
				'status'       => '',
				'content_hash' => '',
				'word_count'   => 0,
				'props'        => wp_json_encode( array( 'taxonomy' => $taxonomy ) ),
			)
		);
	}

	/**
	 * Ensure a lightweight media node exists.
	 *
	 * @param int $media_id Attachment ID.
	 */
	private static function ensure_media_node( $media_id ) {
		$media_id = (int) $media_id;
		$title    = function_exists( 'get_the_title' ) ? get_the_title( $media_id ) : '';
		$url      = function_exists( 'wp_get_attachment_url' ) ? (string) wp_get_attachment_url( $media_id ) : '';

		self::upsert_node(
			array(
				'node_key'     => self::node_key_for_media( $media_id ),
				'kind'         => 'media',
				'ref_id'       => $media_id,
				'label'        => (string) $title,
				'url'          => $url,
				'status'       => '',
				'content_hash' => '',
				'word_count'   => 0,
				'props'        => wp_json_encode( array() ),
			)
		);
	}

	/**
	 * Which builder produced a post's content, for `props.builder`.
	 *
	 * @param int $post_id Post ID.
	 * @return string elementor|gutenberg|classic
	 */
	private static function detect_builder( $post_id ) {
		if ( self::is_elementor_built( $post_id ) ) {
			return 'elementor';
		}
		$post = get_post( $post_id );
		if ( $post && function_exists( 'has_blocks' ) && has_blocks( $post ) ) {
			return 'gutenberg';
		}
		return 'classic';
	}

	// -------------------------------------------------------------------------
	// Hashing
	// -------------------------------------------------------------------------

	/**
	 * sha1 of normalized text (title + stripped body). Terms are hashed
	 * separately (terms_hash, stored in props) so a taxonomy-only change is
	 * still detected without folding term names into the content signal.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function content_hash( $post ) {
		$normalized = trim( (string) $post->post_title ) . "\n" . trim( wp_strip_all_tags( (string) $post->post_content ) );
		return sha1( strtolower( $normalized ) );
	}

	/**
	 * sha1 of a post's sorted term refs.
	 *
	 * @param array $term_refs {taxonomy, term_id, name}[].
	 * @return string
	 */
	public static function terms_hash( array $term_refs ) {
		$keys = array();
		foreach ( $term_refs as $ref ) {
			$keys[] = $ref['taxonomy'] . ':' . $ref['term_id'];
		}
		sort( $keys );
		return sha1( implode( ',', $keys ) );
	}

	/**
	 * Read a node's stored content_hash + terms_hash (from props), or null
	 * when the node does not exist yet.
	 *
	 * @param string $node_key Node key.
	 * @return array{content_hash: string, terms_hash: string}|null
	 */
	private static function read_node_hashes( $node_key ) {
		global $wpdb;
		$table = self::nodes_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT content_hash, props FROM {$table} WHERE node_key = %s", $node_key ), ARRAY_A );

		if ( ! $row ) {
			return null;
		}

		$props      = json_decode( (string) ( $row['props'] ?? '' ), true );
		$terms_hash = is_array( $props ) ? (string) ( $props['terms_hash'] ?? '' ) : '';

		return array(
			'content_hash' => (string) ( $row['content_hash'] ?? '' ),
			'terms_hash'   => $terms_hash,
		);
	}

	// -------------------------------------------------------------------------
	// Extraction — moved from class-mcpwp-rest-content-graph.php /
	// class-mcpwp-rest-api.php. Bodies unchanged; see class docblock.
	// -------------------------------------------------------------------------

	/**
	 * Parse requested graph post types and keep only public/queryable types.
	 *
	 * @param string $post_types Raw comma-separated post types.
	 * @return array Post types.
	 */
	public static function parse_graph_post_types( $post_types ) {
		$requested = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $post_types ) ) ) );
		if ( empty( $requested ) ) {
			$requested = array( 'page', 'post' );
		}

		$allowed = array();
		foreach ( $requested as $post_type ) {
			$obj = get_post_type_object( $post_type );
			if ( $obj && ( ! empty( $obj->public ) || ! empty( $obj->publicly_queryable ) ) ) {
				$allowed[] = $post_type;
			}
		}

		return empty( $allowed ) ? array( 'page', 'post' ) : array_values( array_unique( $allowed ) );
	}

	/**
	 * Get graph-safe term names for a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array Term names.
	 */
	public static function get_graph_term_names( $post ) {
		$names      = array();
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$term_names = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			if ( is_wp_error( $term_names ) || empty( $term_names ) ) {
				continue;
			}

			$names = array_merge( $names, $term_names );
		}

		return array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );
	}

	/**
	 * Get post IDs included in navigation menus.
	 *
	 * @return array Menu post IDs.
	 */
	public static function get_menu_post_ids() {
		return array_keys( self::get_menu_post_depths() );
	}

	/**
	 * Get post IDs included in navigation menus with shallowest menu depth.
	 *
	 * @return array Post ID to depth map.
	 */
	public static function get_menu_post_depths() {
		$depths    = array();
		$locations = get_nav_menu_locations();

		foreach ( $locations as $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );
			if ( empty( $items ) || is_wp_error( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( 'post_type' === $item->type && ! empty( $item->object_id ) ) {
					$depth   = self::get_menu_item_depth( $item, $items );
					$post_id = (int) $item->object_id;

					if ( ! isset( $depths[ $post_id ] ) || $depth < $depths[ $post_id ] ) {
						$depths[ $post_id ] = $depth;
					}
				}
			}
		}

		return $depths;
	}

	/**
	 * Get depth for a nav menu item.
	 *
	 * @param WP_Post $item  Menu item.
	 * @param array   $items Menu items.
	 * @return int Depth.
	 */
	public static function get_menu_item_depth( $item, $items ) {
		$parent_id = (int) $item->menu_item_parent;
		$depth     = 0;
		$seen      = array();

		while ( $parent_id > 0 && ! isset( $seen[ $parent_id ] ) ) {
			$seen[ $parent_id ] = true;
			$depth++;
			$next_parent = 0;

			foreach ( $items as $candidate ) {
				if ( (int) $candidate->ID === $parent_id ) {
					$next_parent = (int) $candidate->menu_item_parent;
					break;
				}
			}

			$parent_id = $next_parent;
		}

		return $depth;
	}

	/**
	 * Calculate freshness score from age in days.
	 *
	 * @param int|null $freshness_days Days since modified.
	 * @return float Score from 0-1.
	 */
	public static function calculate_freshness_score( $freshness_days ) {
		if ( null === $freshness_days ) {
			return 0.0;
		}

		if ( $freshness_days <= 30 ) {
			return 1.0;
		}

		if ( $freshness_days >= 730 ) {
			return 0.0;
		}

		return round( max( 0, 1 - ( ( $freshness_days - 30 ) / 700 ) ), 3 );
	}

	/**
	 * Calculate a simple hub score for graph nodes.
	 *
	 * @param array $node Graph node.
	 * @return float Score.
	 */
	public static function calculate_hub_score( $node ) {
		$score = ( (int) $node['inbound_count'] * 2 ) + (int) $node['outbound_count'];

		if ( ! empty( $node['in_menu'] ) ) {
			$score += null === $node['menu_depth'] ? 2 : max( 1, 3 - (int) $node['menu_depth'] );
		}

		if ( ! empty( $node['parent_id'] ) ) {
			$score += 1;
		}

		$score += min( 3, count( $node['terms'] ) );

		return round( $score, 3 );
	}

	/**
	 * Calculate orphan severity for nodes.
	 *
	 * @param array $node Graph node.
	 * @return string Severity.
	 */
	public static function calculate_orphan_severity( $node ) {
		if ( ! empty( $node['in_menu'] ) || (int) $node['inbound_count'] > 0 ) {
			return 'none';
		}

		if ( 'page' === $node['type'] && (int) $node['word_count'] >= 300 ) {
			return 'high';
		}

		if ( (int) $node['word_count'] >= 150 ) {
			return 'medium';
		}

		return 'low';
	}

	/**
	 * Calculate a compact PageRank-style score for content-link edges.
	 *
	 * @param array $node_ids Node IDs.
	 * @param array $edges    Graph edges.
	 * @return array Scores.
	 */
	public static function calculate_graph_page_rank( $node_ids, $edges ) {
		$count = count( $node_ids );
		if ( 0 === $count ) {
			return array();
		}

		$ranks    = array_fill_keys( $node_ids, 1 / $count );
		$outgoing = array_fill_keys( $node_ids, array() );

		foreach ( $edges as $edge ) {
			if ( 'content_link' !== $edge['type'] || ! array_key_exists( $edge['from'], $outgoing ) ) {
				continue;
			}

			if ( isset( $outgoing[ $edge['from'] ], $ranks[ $edge['to'] ] ) ) {
				$outgoing[ $edge['from'] ][] = (int) $edge['to'];
			}
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$next = array_fill_keys( $node_ids, ( 1 - 0.85 ) / $count );

			foreach ( $node_ids as $node_id ) {
				$targets = array_values( array_unique( $outgoing[ $node_id ] ) );
				if ( empty( $targets ) ) {
					continue;
				}

				$share = ( $ranks[ $node_id ] * 0.85 ) / count( $targets );
				foreach ( $targets as $target_id ) {
					$next[ $target_id ] += $share;
				}
			}

			$ranks = $next;
		}

		foreach ( $ranks as $id => $score ) {
			$ranks[ $id ] = round( $score, 6 );
		}

		return $ranks;
	}

	/**
	 * Extract heading texts from block/raw content.
	 *
	 * @param string $content Post content.
	 * @return array Heading texts.
	 */
	public static function extract_heading_texts( $content ) {
		$headings = array();

		if ( preg_match_all( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$text = trim( wp_strip_all_tags( $match[2] ) );
				if ( '' !== $text ) {
					$headings[] = array(
						'level' => (int) $match[1],
						'text'  => $text,
					);
				}
			}
		}

		return $headings;
	}

	/**
	 * Normalize internal URLs for graph lookup.
	 *
	 * @param string $url URL.
	 * @return string Normalized URL.
	 */
	public static function normalize_internal_graph_url( $url ) {
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		return strtolower( $path . $query );
	}

	/**
	 * Resolve an href to an internal post link, or null if external/non-content.
	 *
	 * @param string $href      Raw href value.
	 * @param string $anchor    Link anchor text.
	 * @param array  $url_to_id Normalized URL to post ID map.
	 * @return array|null
	 */
	public static function resolve_internal_graph_link( $href, $anchor, $url_to_id ) {
		$href = trim( $href );
		if ( '' === $href || 0 === strpos( $href, '#' ) || preg_match( '/^(mailto|tel|sms|javascript):/i', $href ) ) {
			return null;
		}

		$absolute = wp_http_validate_url( $href ) ? $href : home_url( $href );
		$home     = wp_parse_url( home_url() );
		$target   = wp_parse_url( $absolute );

		if ( empty( $target['host'] ) || empty( $home['host'] ) || strtolower( $target['host'] ) !== strtolower( $home['host'] ) ) {
			return null;
		}

		$normalized = self::normalize_internal_graph_url( $absolute );
		$target_id  = isset( $url_to_id[ $normalized ] ) ? (int) $url_to_id[ $normalized ] : url_to_postid( $absolute );

		if ( ! $target_id ) {
			return null;
		}

		return array(
			'target_id' => $target_id,
			'url'       => $absolute,
			'anchor'    => trim( $anchor ),
		);
	}

	/**
	 * Extract all internal links from post content.
	 *
	 * @param string $content   Post content HTML.
	 * @param array  $url_to_id Normalized URL to post ID map.
	 * @return array Links.
	 */
	public static function extract_internal_links_from_content( $content, $url_to_id ) {
		$links = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$link = self::resolve_internal_graph_link( $match[1], wp_strip_all_tags( $match[2] ), $url_to_id );
				if ( $link ) {
					$links[] = $link;
				}
			}
		}

		return $links;
	}

	/**
	 * Extract internal links embedded in an Elementor page's `_elementor_data`.
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $url_to_id Normalized URL to post ID map.
	 * @return array Links.
	 */
	public static function extract_elementor_links_from_postmeta( $post_id, $url_to_id ) {
		$data = Mcpwp_Elementor_Stored_Tree::of_post( $post_id )['elements'];
		if ( empty( $data ) ) {
			return array();
		}

		$hrefs = array();
		self::collect_elementor_link_hrefs( $data, $hrefs );

		$links = array();
		foreach ( array_unique( $hrefs ) as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $href ) {
				continue;
			}
			$link = self::resolve_internal_graph_link( $href, '', $url_to_id );
			if ( $link ) {
				$links[] = $link;
			}
		}

		return $links;
	}

	/**
	 * Recursively collect link hrefs from an Elementor elements tree.
	 *
	 * @param array $elements Elementor elements array.
	 * @param array $hrefs    Accumulator passed by reference.
	 */
	public static function collect_elementor_link_hrefs( $elements, &$hrefs ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			if ( ! empty( $settings['link']['url'] ) && is_string( $settings['link']['url'] ) ) {
				$hrefs[] = $settings['link']['url'];
			}

			if ( ! empty( $settings['url']['url'] ) && is_string( $settings['url']['url'] ) ) {
				$hrefs[] = $settings['url']['url'];
			}

			foreach ( array( 'editor', 'html', 'content', 'inner_text' ) as $field ) {
				if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
					if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $settings[ $field ], $matches ) ) {
						foreach ( $matches[1] as $href ) {
							$hrefs[] = $href;
						}
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_elementor_link_hrefs( $element['elements'], $hrefs );
			}
		}
	}

	/**
	 * Whether an href points at this site, regardless of whether it resolves
	 * to a post. See class-mcpwp-rest-api.php's original docblock for the
	 * classification rationale (moved verbatim).
	 *
	 * @param string $href Raw href value.
	 * @return bool
	 */
	public static function is_same_host_href( $href ) {
		$href = trim( (string) $href );

		if ( '' === $href || 0 === strpos( $href, '#' )
			|| preg_match( '/^(mailto|tel|sms|javascript|data):/i', $href ) ) {
			return false;
		}

		$home = wp_parse_url( home_url() );
		if ( empty( $home['host'] ) ) {
			return false;
		}

		if ( 0 === strpos( $href, '//' ) ) {
			$target = wp_parse_url( 'https:' . $href );
		} elseif ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $href ) ) {
			$target = wp_parse_url( $href );
		} else {
			return true;
		}

		if ( empty( $target['host'] ) ) {
			return false;
		}

		return strtolower( $target['host'] ) === strtolower( $home['host'] );
	}

	/**
	 * Count links on a post that point at this site.
	 *
	 * @param WP_Post $post Post object.
	 * @return int Number of distinct same-host hrefs.
	 */
	public static function count_same_host_outbound_links( $post ) {
		$hrefs = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', (string) $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$hrefs[] = $href;
			}
		}

		$data = Mcpwp_Elementor_Stored_Tree::of_post( (int) $post->ID )['elements'];
		if ( ! empty( $data ) ) {
			self::collect_elementor_link_hrefs( $data, $hrefs );
		}

		$count = 0;
		foreach ( array_unique( $hrefs ) as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( self::is_same_host_href( $href ) ) {
				$count++;
			}
		}

		return $count;
	}

	// -------------------------------------------------------------------------
	// New extraction for the persisted graph (no REST-time equivalent existed).
	// -------------------------------------------------------------------------

	/**
	 * Resolve a post's outgoing same-host links (content + Elementor) to
	 * target post IDs, using core's url_to_postid() rather than a prebuilt
	 * url_to_id map — right for a single-node incremental rebuild, where
	 * building that map up front costs more than it saves.
	 *
	 * @param WP_Post $post Post.
	 * @return array Unique target post IDs.
	 */
	public static function extract_post_link_targets( $post ) {
		$hrefs = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', (string) $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $href ) {
				$hrefs[] = $href;
			}
		}

		$data = Mcpwp_Elementor_Stored_Tree::of_post( (int) $post->ID )['elements'];
		if ( ! empty( $data ) ) {
			self::collect_elementor_link_hrefs( $data, $hrefs );
		}

		$targets = array();
		foreach ( array_unique( $hrefs ) as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $href || ! self::is_same_host_href( $href ) ) {
				continue;
			}

			$absolute  = wp_http_validate_url( $href ) ? $href : home_url( $href );
			$target_id = (int) url_to_postid( $absolute );

			if ( $target_id && $target_id !== (int) $post->ID ) {
				$targets[ $target_id ] = true;
			}
		}

		return array_keys( $targets );
	}

	/**
	 * Extract {taxonomy, term_id, name} for every term attached to a post,
	 * across every taxonomy registered for its post type.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function extract_term_refs( $post ) {
		$refs       = array();
		$taxonomies = get_object_taxonomies( $post->post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( ! is_object( $term ) || empty( $term->term_id ) ) {
					continue;
				}
				$refs[] = array(
					'taxonomy' => (string) $taxonomy,
					'term_id'  => (int) $term->term_id,
					'name'     => (string) $term->name,
				);
			}
		}

		return $refs;
	}

	/**
	 * Extract attachment IDs a post uses: featured image, inline
	 * `wp-image-<id>` classes in content, and Elementor image/gallery widget
	 * settings.
	 *
	 * @param WP_Post $post Post.
	 * @return array Unique attachment IDs.
	 */
	public static function extract_media_refs( $post ) {
		$ids = array();

		$thumbnail_id = (int) get_post_thumbnail_id( $post->ID );
		if ( $thumbnail_id > 0 ) {
			$ids[ $thumbnail_id ] = true;
		}

		if ( preg_match_all( '/wp-image-(\d+)/', (string) $post->post_content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		$data = Mcpwp_Elementor_Stored_Tree::of_post( (int) $post->ID )['elements'];
		if ( ! empty( $data ) ) {
			self::collect_elementor_media_ids( $data, $ids );
		}

		unset( $ids[0] );

		return array_keys( $ids );
	}

	/**
	 * Recursively collect attachment IDs from Elementor image/gallery widget
	 * settings (`settings.image.id`, `settings.gallery[].id`,
	 * `settings.background_image.id`).
	 *
	 * @param array $elements Elementor elements array.
	 * @param array $ids      Accumulator passed by reference (id => true).
	 */
	private static function collect_elementor_media_ids( $elements, array &$ids ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			foreach ( array( 'image', 'background_image' ) as $field ) {
				if ( ! empty( $settings[ $field ]['id'] ) ) {
					$ids[ (int) $settings[ $field ]['id'] ] = true;
				}
			}

			if ( ! empty( $settings['gallery'] ) && is_array( $settings['gallery'] ) ) {
				foreach ( $settings['gallery'] as $item ) {
					if ( is_array( $item ) && ! empty( $item['id'] ) ) {
						$ids[ (int) $item['id'] ] = true;
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_elementor_media_ids( $element['elements'], $ids );
			}
		}
	}

	/**
	 * Extract links from Elementor widget data in {href, anchor, source}
	 * format (moved from class-mcpwp-rest-content-graph.php, used by
	 * validate_internal_links — distinct from
	 * extract_elementor_links_from_postmeta()/collect_elementor_link_hrefs()
	 * above, which resolve against a url_to_id map instead of tagging source).
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Array of {href, anchor, source} records.
	 */
	public static function extract_raw_links_from_elementor( $post_id ) {
		$data = Mcpwp_Elementor_Stored_Tree::of_post( $post_id )['elements'];
		if ( empty( $data ) ) {
			return array();
		}

		$links = array();
		self::collect_elementor_raw_links( $data, $links );

		$seen  = array();
		$dedup = array();
		foreach ( $links as $link ) {
			$key = strtolower( trim( $link['href'] ) );
			if ( '' !== $key && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$dedup[]      = $link;
			}
		}
		return $dedup;
	}

	/**
	 * Recursively collect {href, anchor, source} records from Elementor elements.
	 *
	 * @param array $elements Elementor elements array.
	 * @param array $links    Accumulator passed by reference.
	 */
	public static function collect_elementor_raw_links( $elements, &$links ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			foreach ( array( 'link', 'url' ) as $url_key ) {
				if ( ! empty( $settings[ $url_key ]['url'] ) && is_string( $settings[ $url_key ]['url'] ) ) {
					$anchor  = trim( wp_strip_all_tags( (string) ( $settings['text'] ?? $settings['button_text'] ?? $settings['title'] ?? '' ) ) );
					$links[] = array(
						'href'   => $settings[ $url_key ]['url'],
						'anchor' => $anchor,
						'source' => 'elementor',
					);
				}
			}

			foreach ( array( 'editor', 'html', 'content', 'inner_text' ) as $field ) {
				if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
					if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $settings[ $field ], $matches, PREG_SET_ORDER ) ) {
						foreach ( $matches as $m ) {
							$links[] = array(
								'href'   => trim( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ),
								'anchor' => trim( wp_strip_all_tags( $m[2] ) ),
								'source' => 'elementor',
							);
						}
					}
				}
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::collect_elementor_raw_links( $element['elements'], $links );
			}
		}
	}

	/**
	 * Whether a post is built with Elementor (moved from class-mcpwp-rest-api.php).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_elementor_built( $post_id ) {
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return true;
		}
		return ! empty( get_post_meta( $post_id, '_elementor_data', true ) );
	}

	// -------------------------------------------------------------------------
	// Storage primitives
	// -------------------------------------------------------------------------

	/**
	 * @return string
	 */
	private static function nodes_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcpwp_graph_nodes';
	}

	/**
	 * @return string
	 */
	private static function edges_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcpwp_graph_edges';
	}

	/**
	 * Upsert one node row.
	 *
	 * @param array $row {node_key, kind, ref_id, label, url, status, content_hash, word_count, props}.
	 */
	private static function upsert_node( array $row ) {
		global $wpdb;
		$table = self::nodes_table();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (node_key, kind, ref_id, label, url, status, content_hash, word_count, props, updated_at)
				VALUES (%s, %s, %d, %s, %s, %s, %s, %d, %s, %s)
				ON DUPLICATE KEY UPDATE kind = VALUES(kind), ref_id = VALUES(ref_id), label = VALUES(label),
					url = VALUES(url), status = VALUES(status), content_hash = VALUES(content_hash),
					word_count = VALUES(word_count), props = VALUES(props), updated_at = VALUES(updated_at)",
				(string) $row['node_key'],
				(string) $row['kind'],
				isset( $row['ref_id'] ) ? (int) $row['ref_id'] : 0,
				(string) ( $row['label'] ?? '' ),
				(string) ( $row['url'] ?? '' ),
				(string) ( $row['status'] ?? '' ),
				(string) ( $row['content_hash'] ?? '' ),
				(int) ( $row['word_count'] ?? 0 ),
				(string) ( $row['props'] ?? '' ),
				$now
			)
		);
	}

	/**
	 * Insert/refresh one builder-sourced edge.
	 *
	 * @param string $from   From node key.
	 * @param string $to     To node key.
	 * @param string $type   Edge type.
	 * @param float  $weight Weight.
	 * @param string $source Source tag (builder|gsc|bing|agent|manual).
	 * @param array  $props  Extra JSON-able props.
	 */
	private static function insert_edge( $from, $to, $type, $weight, $source, array $props = array() ) {
		global $wpdb;
		$table = self::edges_table();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (from_key, to_key, type, weight, source, props, updated_at)
				VALUES (%s, %s, %s, %f, %s, %s, %s)
				ON DUPLICATE KEY UPDATE weight = VALUES(weight), source = VALUES(source), props = VALUES(props), updated_at = VALUES(updated_at)",
				(string) $from,
				(string) $to,
				(string) $type,
				(float) $weight,
				(string) $source,
				wp_json_encode( $props ),
				$now
			)
		);
	}

	/**
	 * Delete this node's builder-owned edges: its own outgoing
	 * links_to/in_term/uses_media, and the one incoming parent_of edge it is
	 * authoritative for.
	 *
	 * @param string $node_key Node key.
	 */
	private static function delete_builder_edges_for_node( $node_key ) {
		global $wpdb;
		$table = self::edges_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE from_key = %s AND source = 'builder' AND type IN ('links_to','in_term','uses_media')",
				$node_key
			)
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE to_key = %s AND source = 'builder' AND type = 'parent_of'",
				$node_key
			)
		);
	}

	/**
	 * Delete a node and every edge from or to it (both directions).
	 *
	 * @param string $node_key Node key.
	 */
	public static function delete_node_and_edges( $node_key ) {
		global $wpdb;
		$nodes_table = self::nodes_table();
		$edges_table = self::edges_table();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$nodes_table} WHERE node_key = %s", $node_key ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built via $wpdb->prepare().
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$edges_table} WHERE from_key = %s OR to_key = %s", $node_key, $node_key ) );
	}

	// -------------------------------------------------------------------------
	// Backfill
	// -------------------------------------------------------------------------

	/**
	 * Called from the mcpwp_db_version upgrade path. Kicks off (or resumes)
	 * the backfill unless it has already completed.
	 */
	public static function on_upgrade_schedule_backfill() {
		$status = get_option( self::BACKFILL_STATUS_OPTION, '' );
		if ( 'done' === $status ) {
			return;
		}
		if ( '' === $status ) {
			update_option( self::BACKFILL_STATUS_OPTION, 'pending', false );
		}
		self::schedule_next_backfill_batch( 0 );
	}

	/**
	 * Schedule one backfill batch, preferring Action Scheduler when present
	 * (WooCommerce ships it) over WP-Cron, which is traffic-dependent.
	 *
	 * @param int $delay_seconds Delay before the batch may run (WP-Cron path only).
	 */
	private static function schedule_next_backfill_batch( $delay_seconds = 5 ) {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::BACKFILL_CRON_HOOK ) ) {
				return;
			}
			as_enqueue_async_action( self::BACKFILL_CRON_HOOK, array(), 'mcpwp' );
			return;
		}

		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::BACKFILL_CRON_HOOK ) ) {
			return;
		}

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay_seconds ), self::BACKFILL_CRON_HOOK );
		}
	}

	/**
	 * Run one backfill batch: drain a slice of the incremental overflow
	 * first, then process up to BACKFILL_BATCH_SIZE posts ordered by ID,
	 * bounded by BACKFILL_TIME_BUDGET. Self-reschedules until done.
	 */
	public static function run_backfill_batch() {
		self::drain_dirty_batch();

		if ( 'done' === get_option( self::BACKFILL_STATUS_OPTION, '' ) ) {
			return;
		}

		update_option( self::BACKFILL_STATUS_OPTION, 'running', false );

		$start      = microtime( true );
		$cursor     = (int) get_option( self::BACKFILL_CURSOR_OPTION, 0 );
		$post_types = function_exists( 'get_post_types' ) ? array_values( get_post_types( array( 'public' => true ) ) ) : array();
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}

		$ids = self::fetch_backfill_batch_ids( $post_types, $cursor, self::BACKFILL_BATCH_SIZE );

		$processed = 0;
		$last_id   = $cursor;

		foreach ( $ids as $id ) {
			if ( ( microtime( true ) - $start ) > self::BACKFILL_TIME_BUDGET ) {
				break;
			}
			self::rebuild_post_node( (int) $id );
			$last_id = (int) $id;
			$processed++;
		}

		update_option( self::BACKFILL_CURSOR_OPTION, $last_id, false );

		$time_capped     = $processed < count( $ids );
		$more_rows_likely = count( $ids ) === self::BACKFILL_BATCH_SIZE;

		if ( $time_capped || $more_rows_likely ) {
			self::schedule_next_backfill_batch( 5 );
			return;
		}

		update_option( self::BACKFILL_STATUS_OPTION, 'done', false );
	}

	/**
	 * Fetch up to $limit post IDs with ID > $cursor, ordered by ID ascending,
	 * across $post_types. Raw $wpdb (not WP_Query) — cursor pagination at
	 * 20k-post scale must avoid OFFSET.
	 *
	 * @param array $post_types Post types (trusted — from get_post_types(), never user input).
	 * @param int   $cursor     Highest ID already processed.
	 * @param int   $limit      Max rows.
	 * @return array<int> Post IDs.
	 */
	private static function fetch_backfill_batch_ids( array $post_types, $cursor, $limit ) {
		global $wpdb;

		$post_types = array_map( 'sanitize_key', $post_types );
		$in_clause  = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in_clause is built only from get_post_types() values (never user input); cursor/limit are prepared.
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in_clause}) AND post_status != 'auto-draft' AND ID > %d ORDER BY ID ASC LIMIT %d",
			(int) $cursor,
			(int) $limit
		);

		$ids = $wpdb->get_col( $sql );
		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Backfill status for the read surface's `graph_status`.
	 *
	 * @return array{status: string, cursor: int}
	 */
	public static function backfill_status() {
		return array(
			'status' => (string) get_option( self::BACKFILL_STATUS_OPTION, '' ),
			'cursor' => (int) get_option( self::BACKFILL_CURSOR_OPTION, 0 ),
		);
	}
}
