<?php
/**
 * Coherent site-state snapshot for agents and the Control Room.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a compact whole-site state model for deterministic agent starts.
 */
class Mcpwp_Site_State {

	/**
	 * Build the current site-state snapshot.
	 *
	 * @param array $args Snapshot arguments.
	 * @return array Snapshot.
	 */
	public static function get_snapshot( $args = array() ) {
		$args = wp_parse_args(
			is_array( $args ) ? $args : array(),
			array(
				'graph_limit'       => 100,
				'event_limit'       => 20,
				'include_drafts'    => false,
				'include_plugins'   => false,
				'reveal_non_public' => false,
				'category_allowed'  => null,
			)
		);

		// Fail-closed: the approval and SEO summaries embed source-derived
		// title/url/status/excerpt fields, so they are redacted unless the REST
		// handler proved the caller may see non-public content. Defaults to false
		// so any caller that forgets to pass it (e.g. content-coherence) stays safe.
		$reveal_non_public = ! empty( $args['reveal_non_public'] );

		// #1025: same fail-closed default as reveal_non_public above, for the
		// same reason — a caller that forgets to pass this (content-coherence)
		// gets NO category filtering rather than an error, matching its existing,
		// pre-#1025 behavior exactly (it never surfaces `approvals.pending_items`/
		// `events.items` to its own response, only aggregate counts computed
		// from them — see its class docblock). Mcpwp_REST_Site_Info::get_site_state()
		// is the one caller that DOES pass this, since /site-state returns the
		// full snapshot verbatim, including those item arrays.
		$category_allowed = is_callable( $args['category_allowed'] ) ? $args['category_allowed'] : null;

		$content_counts = self::get_content_counts();
		$graph          = self::get_graph_summary( (int) $args['graph_limit'], ! empty( $args['include_drafts'] ) );
		$approvals      = self::get_approval_summary( $reveal_non_public, $category_allowed );
		$seo            = self::get_seo_summary( $reveal_non_public, $category_allowed );
		$event_result   = class_exists( 'Mcpwp_Event_Store' )
			? Mcpwp_Event_Store::list_events(
				array(
					'limit'             => min( 50, max( 1, absint( $args['event_limit'] ) ) ),
					'category_allowed'  => $category_allowed,
				)
			)
			: array( 'events' => array(), 'total' => 0 );
		$capabilities   = self::get_capabilities( ! empty( $args['include_plugins'] ) );
		$context        = self::get_context_summary();
		$recommendations = self::get_recommendations( $content_counts, $graph, $approvals, $seo, $context );

		return array(
			'schema_version'      => '2026-05-20',
			'generated_at'        => gmdate( 'c' ),
			'site'                => self::get_site_identity(),
			'context'             => $context,
			'content'             => $content_counts,
			'graph'               => $graph,
			'approvals'           => $approvals,
			'seo'                 => $seo,
			'events'              => array(
				'count' => isset( $event_result['total'] ) ? (int) $event_result['total'] : 0,
				'items' => isset( $event_result['events'] ) && is_array( $event_result['events'] ) ? $event_result['events'] : array(),
			),
			'capabilities'        => $capabilities,
			'recommended_actions' => $recommendations,
			'workflow'            => array(
				'read_first' => true,
				'next'       => array( 'choose_playbook', 'inspect_target_records', 'create_approval_for_mutations', 'validate_before_publish' ),
				'guard'      => __( 'Use this snapshot before multi-step agent work. Mutations should remain approval-first.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Site identity summary.
	 *
	 * @return array
	 */
	private static function get_site_identity() {
		return array(
			'name'          => get_bloginfo( 'name' ),
			'url'           => get_site_url(),
			'admin_url'     => admin_url(),
			'wp_version'    => get_bloginfo( 'version' ),
			'plugin_name'   => 'MCPWP',
			'plugin_version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '',
		);
	}

	/**
	 * Content counts by post type and status.
	 *
	 * @return array
	 */
	private static function get_content_counts() {
		$summary = array();

		foreach ( array( 'post', 'page', 'attachment' ) as $type ) {
			$counts          = (array) wp_count_posts( $type );
			$summary[ $type ] = array(
				'publish' => isset( $counts['publish'] ) ? (int) $counts['publish'] : 0,
				'draft'   => isset( $counts['draft'] ) ? (int) $counts['draft'] : 0,
				'private' => isset( $counts['private'] ) ? (int) $counts['private'] : 0,
				'trash'   => isset( $counts['trash'] ) ? (int) $counts['trash'] : 0,
			);
			$summary[ $type ]['total'] = array_sum( $summary[ $type ] );
		}

		return $summary;
	}

	/**
	 * Compact graph and navigation health summary.
	 *
	 * @param int  $limit          Maximum posts to inspect.
	 * @param bool $include_drafts Include drafts/private content.
	 * @return array
	 */
	private static function get_graph_summary( $limit, $include_drafts ) {
		$limit    = min( 250, max( 1, absint( $limit ) ) );
		$statuses = $include_drafts ? array( 'publish', 'draft', 'private' ) : array( 'publish' );
		$posts    = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => $statuses,
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$menu_page_ids = self::get_menu_page_ids();
		$nodes         = array();
		$orphan_pages  = array();
		$thin_content  = array();
		$stale_content = array();
		$total_words   = 0;
		$now           = time();

		foreach ( $posts as $post ) {
			$post_id        = (int) $post->ID;
			$word_count     = str_word_count( wp_strip_all_tags( (string) $post->post_content ) );
			$modified_ts    = (int) get_post_modified_time( 'U', true, $post );
			$freshness_days = $modified_ts > 0 ? (int) floor( max( 0, $now - $modified_ts ) / DAY_IN_SECONDS ) : null;
			$total_words   += $word_count;

			$node = array(
				'id'             => $post_id,
				'type'           => $post->post_type,
				'title'          => get_the_title( $post ),
				'status'         => $post->post_status,
				'url'            => get_permalink( $post ),
				'word_count'     => $word_count,
				'freshness_days' => $freshness_days,
				'in_menu'        => in_array( $post_id, $menu_page_ids, true ),
			);

			$nodes[] = $node;

			if ( 'page' === $post->post_type && 'publish' === $post->post_status && ! $node['in_menu'] ) {
				$orphan_pages[] = $node;
			}

			if ( $word_count > 0 && $word_count < 300 ) {
				$thin_content[] = $node;
			}

			if ( null !== $freshness_days && $freshness_days > 365 ) {
				$stale_content[] = $node;
			}
		}

		return array(
			'inspected_count'    => count( $nodes ),
			'average_word_count' => count( $nodes ) > 0 ? (int) round( $total_words / count( $nodes ) ) : 0,
			'orphan_pages'       => array(
				'count' => count( $orphan_pages ),
				'items' => array_slice( $orphan_pages, 0, 10 ),
			),
			'thin_content'       => array(
				'count' => count( $thin_content ),
				'items' => array_slice( $thin_content, 0, 10 ),
			),
			'stale_content'      => array(
				'count' => count( $stale_content ),
				'items' => array_slice( $stale_content, 0, 10 ),
			),
		);
	}

	/**
	 * Approval state summary.
	 *
	 * @param bool          $reveal_non_public When true, embed full source fields; when
	 *                                         false (default) non-public sources are redacted.
	 * @param callable|null $category_allowed  #1025: `function(string $category): bool`,
	 *                                         forwarded to Mcpwp_Approvals::list_requests()
	 *                                         so `counts` and `rollback_ready` (both derived
	 *                                         from THIS same filtered $items, not a second,
	 *                                         unfiltered read) can never disagree with the
	 *                                         `pending_items`/`approved_items` this filter
	 *                                         hides — a count next to a filtered list is
	 *                                         itself the oracle this exists to prevent.
	 * @return array
	 */
	private static function get_approval_summary( $reveal_non_public = false, $category_allowed = null ) {
		$counts = array(
			'pending'     => 0,
			'approved'    => 0,
			'applied'     => 0,
			'rejected'    => 0,
			'rolled_back' => 0,
		);
		$items          = class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::list_requests( 'applied', 5, $reveal_non_public, 'date', $category_allowed ) : array();
		$pending_items  = class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::list_requests( 'pending', 5, $reveal_non_public, 'date', $category_allowed ) : array();
		$approved_items = class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::list_requests( 'approved', 5, $reveal_non_public, 'date', $category_allowed ) : array();

		// #1042: the approvals store can fail a read. Say so — zero counts
		// and empty lists would read as "nothing waiting for review".
		foreach ( array( $items, $pending_items, $approved_items ) as $read ) {
			if ( is_wp_error( $read ) ) {
				return array(
					'counts'         => $counts,
					'pending_items'  => array(),
					'approved_items' => array(),
					'rollback_ready' => array(),
					'error'          => array(
						'code'    => $read->get_error_code(),
						'message' => $read->get_error_message(),
					),
				);
			}
		}

		// #1042 W4: counts come from the whole store, never from the 100
		// newest listed records; a restricted caller's count is still
		// filtered through the same visibility chokepoint, and says so when
		// it had to stop short.
		$counted = class_exists( 'Mcpwp_Approvals' ) ? Mcpwp_Approvals::count_by_status( $category_allowed ) : array( 'counts' => array(), 'truncated' => false );
		if ( is_wp_error( $counted ) ) {
			return array(
				'counts'         => $counts,
				'pending_items'  => array(),
				'approved_items' => array(),
				'rollback_ready' => array(),
				'error'          => array(
					'code'    => $counted->get_error_code(),
					'message' => $counted->get_error_message(),
				),
			);
		}
		foreach ( $counts as $status => $zero ) {
			$counts[ $status ] = isset( $counted['counts'][ $status ] ) ? (int) $counted['counts'][ $status ] : 0;
		}

		$summary = array(
			'counts'         => $counts,
			'pending_items'  => $pending_items,
			'approved_items' => $approved_items,
			'rollback_ready' => $items,
		);
		if ( ! empty( $counted['truncated'] ) ) {
			$summary['counts_truncated'] = true;
		}
		return $summary;
	}

	/**
	 * Stored SEO issue summary.
	 *
	 * @param bool $reveal_non_public When true, include non-public issues; when
	 *                                false (default) they are excluded.
	 * @param callable|null $category_allowed Caller's category predicate; issues
	 *                                about posts it cannot read are dropped.
	 * @return array
	 */
	private static function get_seo_summary( $reveal_non_public = false, $category_allowed = null ) {
		if ( ! class_exists( 'Mcpwp_SEO_Audit_Store' ) ) {
			return array(
				'summary'    => array( 'total' => 0, 'open' => 0, 'resolved' => 0, 'error' => 0, 'warning' => 0, 'info' => 0 ),
				'top_issues' => array(),
			);
		}

		// #1030 round-5 WARN-1: the same caller-category clamp /seo/issues
		// applies — top_issues carry each post's title and url, and the
		// summary counts must describe only those.
		$issues = Mcpwp_SEO_Audit_Store::list_issues(
			array(
				'limit'             => 8,
				'reveal_non_public' => $reveal_non_public,
				'category_allowed'  => $category_allowed,
			)
		);

		return array(
			'summary'    => isset( $issues['summary'] ) && is_array( $issues['summary'] ) ? $issues['summary'] : array(),
			'top_issues' => isset( $issues['issues'] ) && is_array( $issues['issues'] ) ? $issues['issues'] : array(),
		);
	}

	/**
	 * Capability summary.
	 *
	 * @param bool $include_plugins Include active plugin labels.
	 * @return array
	 */
	private static function get_capabilities( $include_plugins ) {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$plugin_files   = array_map( 'sanitize_text_field', $active_plugins );
		$capabilities   = array(
			'gutenberg'       => true,
			'elementor'       => self::has_active_plugin_slug( $plugin_files, 'elementor/' ),
			'woocommerce'     => self::has_active_plugin_slug( $plugin_files, 'woocommerce/' ),
			'seo_plugins'     => array(
				'yoast'    => self::has_active_plugin_slug( $plugin_files, 'wordpress-seo/' ),
				'rankmath' => self::has_active_plugin_slug( $plugin_files, 'seo-by-rank-math/' ),
				'aioseo'   => self::has_active_plugin_slug( $plugin_files, 'all-in-one-seo-pack/' ),
			),
			'approval_first'  => class_exists( 'Mcpwp_Approvals' ),
			'event_hooks'     => class_exists( 'Mcpwp_Event_Store' ),
			'stored_seo'      => class_exists( 'Mcpwp_SEO_Audit_Store' ),
		);

		if ( $include_plugins ) {
			$capabilities['active_plugins'] = $plugin_files;
		}

		return $capabilities;
	}

	/**
	 * Context configuration summary.
	 *
	 * @return array
	 */
	private static function get_context_summary() {
		$context = (string) get_option( 'mcpwp_site_context', '' );

		return array(
			'configured' => '' !== trim( $context ),
			'length'     => strlen( $context ),
			'hint'       => '' === trim( $context ) ? __( 'Set site context before asking agents to make broad content or design changes.', 'mcpwp' ) : '',
		);
	}

	/**
	 * Recommended next actions from the whole-site state.
	 *
	 * @param array $content_counts Content counts.
	 * @param array $graph          Graph summary.
	 * @param array $approvals      Approval summary.
	 * @param array $seo            SEO summary.
	 * @param array $context        Context summary.
	 * @return array
	 */
	private static function get_recommendations( $content_counts, $graph, $approvals, $seo, $context ) {
		$actions      = array();
		$seo_summary  = isset( $seo['summary'] ) && is_array( $seo['summary'] ) ? $seo['summary'] : array();
		$approval_map = isset( $approvals['counts'] ) && is_array( $approvals['counts'] ) ? $approvals['counts'] : array();

		if ( empty( $context['configured'] ) ) {
			$actions[] = self::recommendation( 'medium', 'set_site_context', 'wp_set_site_context', __( 'Define site context so agents know brand, audience, design rules, and content boundaries.', 'mcpwp' ) );
		}

		if ( ! empty( $approval_map['pending'] ) ) {
			$actions[] = self::recommendation( 'high', 'review_pending_approvals', 'wp_list_approvals', __( 'Review pending human approvals before starting more production changes.', 'mcpwp' ) );
		}

		if ( ! empty( $seo_summary['error'] ) ) {
			$actions[] = self::recommendation( 'high', 'fix_seo_errors', 'wp_get_seo_issues', __( 'Resolve stored SEO errors before lower-priority content expansion.', 'mcpwp' ) );
		}

		if ( ! empty( $graph['orphan_pages']['count'] ) ) {
			$actions[] = self::recommendation( 'medium', 'connect_orphan_pages', 'wp_suggest_internal_links', __( 'Connect orphan pages through menus or internal links before creating more pages.', 'mcpwp' ) );
		}

		if ( ! empty( $graph['thin_content']['count'] ) ) {
			$actions[] = self::recommendation( 'medium', 'improve_thin_content', 'wp_audit_content_quality', __( 'Improve thin pages with useful visible content, summaries, and clear answer coverage.', 'mcpwp' ) );
		}

		if ( empty( $content_counts['page']['publish'] ) ) {
			$actions[] = self::recommendation( 'high', 'create_foundational_page', 'wp_create_page', __( 'Create at least one foundational published page before optimization work.', 'mcpwp' ) );
		}

		if ( empty( $actions ) ) {
			$actions[] = self::recommendation( 'low', 'run_next_audit', 'wp_seo_audit_site', __( 'Run or refresh a stored SEO audit, then choose a deterministic playbook for the next change.', 'mcpwp' ) );
		}

		return array_slice( $actions, 0, 8 );
	}

	/**
	 * Build one recommendation.
	 *
	 * @param string $priority Priority.
	 * @param string $code     Code.
	 * @param string $tool     Tool.
	 * @param string $message  Message.
	 * @return array
	 */
	private static function recommendation( $priority, $code, $tool, $message ) {
		return array(
			'priority' => sanitize_key( $priority ),
			'code'     => sanitize_key( $code ),
			'tool'     => sanitize_key( $tool ),
			'message'  => sanitize_text_field( $message ),
		);
	}

	/**
	 * Get page IDs included in navigation menus.
	 *
	 * @return array
	 */
	private static function get_menu_page_ids() {
		$page_ids = array();
		$menus    = function_exists( 'wp_get_nav_menus' ) ? wp_get_nav_menus() : array();

		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( empty( $items ) ) {
				continue;
			}
			foreach ( $items as $item ) {
				if ( isset( $item->object, $item->object_id ) && 'page' === $item->object ) {
					$page_ids[] = (int) $item->object_id;
				}
			}
		}

		return array_values( array_unique( $page_ids ) );
	}

	/**
	 * Check active plugin slug prefix.
	 *
	 * @param array  $plugin_files Plugin files.
	 * @param string $slug         Slug prefix.
	 * @return bool
	 */
	private static function has_active_plugin_slug( $plugin_files, $slug ) {
		foreach ( $plugin_files as $plugin_file ) {
			if ( 0 === strpos( (string) $plugin_file, $slug ) ) {
				return true;
			}
		}

		return false;
	}
}
