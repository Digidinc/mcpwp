<?php
/**
 * REST API Base Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base REST API controller.
 */
abstract class Mcpwp_REST_API {

	use Mcpwp_Api_Auth;
	use Mcpwp_Sanitization;
	use Mcpwp_Logging;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcpwp/v1';

	/**
	 * Register routes.
	 */
	abstract public function register_routes();

	/**
	 * Check if request has valid API key.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid.
	 */
	public function check_permission( $request ) {
		return $this->verify_api_key( $request );
	}

	/**
	 * Current requester's API key id, or '' when none (admin-AJAX / cookie context).
	 *
	 * Used to stamp approval requests with their creator so the same key cannot
	 * later approve or apply its own request. Populated on direct,
	 * batch, and MCP sub-request paths.
	 *
	 * @return string Key id used to stamp approval requests for self-approval checks.
	 */
	protected function current_requester_key_id() {
		$key_record = $this->get_current_api_key_record();
		return ( is_array( $key_record ) && ! empty( $key_record['id'] ) ) ? (string) $key_record['id'] : '';
	}

	/**
	 * Whether the current OAuth principal lacks a stable identity.
	 *
	 * A legacy OAuth access token issued before per-token identity was persisted
	 * (or a 2.8.56 `spai_at_` bridge token) carries no user_id/client_id, so it
	 * is identified only by a token-hash fallback (`oauth:<hash>`). Refreshing
	 * such a grant mints a differently-keyed `oauth-user:<id>` token, so an
	 * unstable-identity principal must not create or approve approval requests —
	 * otherwise the self-approval gate can be sidestepped by creating with the
	 * legacy token and approving with the refreshed one. All other operations
	 * (read/write) remain available; reconnecting re-mints an identified token.
	 *
	 * @return bool
	 */
	protected function requester_has_unstable_oauth_identity() {
		$key_record = $this->get_current_api_key_record();
		if ( ! is_array( $key_record ) || empty( $key_record['id'] ) ) {
			return false;
		}
		// Strong identities are `oauth-user:` / `oauth-client:` / scoped-key ids;
		// only the weak fallback is the bare `oauth:` prefix.
		return 0 === strpos( (string) $key_record['id'], 'oauth:' );
	}

	/**
	 * Whether the current principal authenticated via an OAuth access token.
	 *
	 * OAuth records are tagged `type => 'oauth'` at authentication; scoped API
	 * keys never carry that tag. Used to keep OAuth connectors subject to the
	 * approval floor even when they hold admin scope — only a provisioned
	 * admin-scoped API key (or admin-AJAX) may bypass approval.
	 *
	 * @return bool
	 */
	protected function requester_is_oauth() {
		$key_record = $this->get_current_api_key_record();
		return is_array( $key_record ) && isset( $key_record['type'] ) && 'oauth' === $key_record['type'];
	}

	/**
	 * Abort the approval surface for an unstable-identity OAuth principal.
	 *
	 * @return WP_Error|null WP_Error when the caller must be refused, else null.
	 */
	protected function guard_stable_identity_for_approvals() {
		if ( $this->requester_has_unstable_oauth_identity() ) {
			return new WP_Error(
				'oauth_identity_unstable',
				__( 'This OAuth session predates the current security update and is not stably identified. Reconnect the connector, then retry — approval requests require a re-authenticated session.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}
		return null;
	}

	/**
	 * Prepare success response.
	 *
	 * @param mixed $data Response data.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response Response object.
	 */
	protected function success_response( $data, $status = 200 ) {
		$response = new WP_REST_Response( $data, $status );

		// Add rate limit headers.
		$this->add_rate_limit_headers( $response );

		return $response;
	}

	/**
	 * Add rate limit headers to response.
	 *
	 * @param WP_REST_Response $response Response object.
	 */
	protected function add_rate_limit_headers( $response ) {
		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return;
		}

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$headers = $limiter->get_headers();

		foreach ( $headers as $key => $value ) {
			$response->header( $key, $value );
		}
	}

	/**
	 * Prepare error response.
	 *
	 * Automatically enhances the error with actionable hints when available.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @param array  $context Optional context for dynamic hint generation.
	 * @return WP_Error Error object with hints.
	 */
	protected function error_response( $code, $message, $status = 400, $context = array() ) {
		$error = new WP_Error( $code, $message, array( 'status' => $status ) );

		if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
			$error = Mcpwp_Error_Hints::enhance_error( $error, $context );
		}

		return $error;
	}

	/**
	 * Get pagination args schema.
	 *
	 * @return array Schema.
	 */
	protected function get_pagination_args() {
		return array(
			'per_page' => array(
				'description' => __( 'Maximum number of items per page.', 'mcpwp' ),
				'type'        => 'integer',
				'default'     => 10,
				'minimum'     => 1,
				'maximum'     => 100,
			),
			'page'     => array(
				'description' => __( 'Current page number.', 'mcpwp' ),
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
			),
		);
	}

	/**
	 * Get common post args schema.
	 *
	 * @return array Schema.
	 */
	protected function get_post_args() {
		return array(
			'title'   => array(
				'description' => __( 'Post title.', 'mcpwp' ),
				'type'        => 'string',
				'required'    => true,
			),
			'content' => array(
				'description' => __( 'Post content.', 'mcpwp' ),
				'type'        => 'string',
				'default'     => '',
			),
			'status'  => array(
				'description' => __( 'Post status.', 'mcpwp' ),
				'type'        => 'string',
				'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
				'default'     => 'draft',
			),
			'excerpt' => array(
				'description' => __( 'Post excerpt.', 'mcpwp' ),
				'type'        => 'string',
				'default'     => '',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Shared content-graph utilities (used by content graph, SEO audit, and
	// content quality controllers).
	// -------------------------------------------------------------------------

	// --- Private helpers ---

	protected function build_content_graph_data( $post_types, $limit, $include_drafts = false ) {
		$statuses = $include_drafts ? array( 'publish', 'draft', 'private' ) : array( 'publish' );

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => $statuses,
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$nodes_by_id       = array();
		$url_to_id         = array();
		$menu_post_depths  = $this->get_menu_post_depths();
		$menu_post_ids     = array_keys( $menu_post_depths );
		$terms_to_post_ids = array();
		$now               = time();

		foreach ( $posts as $post ) {
			$permalink  = get_permalink( $post );
			$term_names = $this->get_graph_term_names( $post );
			$headings   = $this->extract_heading_texts( $post->post_content );
			$modified_ts = (int) get_post_modified_time( 'U', true, $post );
			$freshness_days = $modified_ts > 0 ? max( 0, floor( ( $now - $modified_ts ) / DAY_IN_SECONDS ) ) : null;

			$node = array(
				'id'             => (int) $post->ID,
				'type'           => $post->post_type,
				'status'         => $post->post_status,
				'title'          => get_the_title( $post ),
				'url'            => $permalink,
				'slug'           => $post->post_name,
				'excerpt'        => has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 32 ),
				'modified'       => get_post_modified_time( DATE_ATOM, true, $post ),
				'parent_id'      => (int) $post->post_parent,
				'in_menu'        => in_array( (int) $post->ID, $menu_post_ids, true ),
				'menu_depth'     => isset( $menu_post_depths[ (int) $post->ID ] ) ? (int) $menu_post_depths[ (int) $post->ID ] : null,
				'terms'          => $term_names,
				'headings'       => $headings,
				'word_count'     => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				'freshness_days' => $freshness_days,
				'freshness_score' => $this->calculate_freshness_score( $freshness_days ),
				'inbound_count'  => 0,
				'outbound_count' => 0,
				'hub_score'      => 0,
				'orphan_severity' => 'none',
				'rank_score'     => 0,
				'anchors_in'     => array(),
				'anchors_out'    => array(),
			);

			$nodes_by_id[ (int) $post->ID ] = $node;
			$url_to_id[ $this->normalize_internal_graph_url( $permalink ) ] = (int) $post->ID;

			foreach ( $term_names as $term_name ) {
				$term_key = sanitize_title( $term_name );
				if ( '' === $term_key ) {
					continue;
				}

				if ( empty( $terms_to_post_ids[ $term_key ] ) ) {
					$terms_to_post_ids[ $term_key ] = array(
						'name' => $term_name,
						'ids'  => array(),
					);
				}
				$terms_to_post_ids[ $term_key ]['ids'][] = (int) $post->ID;
			}
		}

		$edges = array();

		foreach ( $posts as $post ) {
			$from_id = (int) $post->ID;
			$links   = $this->extract_internal_links_from_content( $post->post_content, $url_to_id );

			foreach ( $links as $link ) {
				$to_id = $link['target_id'];
				if ( $from_id === $to_id || ! isset( $nodes_by_id[ $to_id ] ) ) {
					continue;
				}

				$edges[] = array(
					'from'   => $from_id,
					'to'     => $to_id,
					'type'   => 'content_link',
					'anchor' => $link['anchor'],
					'url'    => $link['url'],
				);

				$nodes_by_id[ $from_id ]['outbound_count']++;
				$nodes_by_id[ $to_id ]['inbound_count']++;
				$nodes_by_id[ $from_id ]['anchors_out'][] = $link['anchor'];
				$nodes_by_id[ $to_id ]['anchors_in'][]    = $link['anchor'];
			}

			if ( $post->post_parent && isset( $nodes_by_id[ (int) $post->post_parent ] ) ) {
				$edges[] = array(
					'from' => (int) $post->post_parent,
					'to'   => $from_id,
					'type' => 'parent_child',
				);
			}
		}

		foreach ( $terms_to_post_ids as $term ) {
			$term_ids = array_values( array_unique( $term['ids'] ) );
			sort( $term_ids );

			for ( $i = 0; $i < count( $term_ids ); $i++ ) {
				for ( $j = $i + 1; $j < count( $term_ids ); $j++ ) {
					$edges[] = array(
						'from'   => $term_ids[ $i ],
						'to'     => $term_ids[ $j ],
						'type'   => 'shared_taxonomy',
						'term'   => $term['name'],
						'weight' => 2,
					);
				}
			}
		}

		$front_page_id = (int) get_option( 'page_on_front' );
		$orphans       = array();
		$page_rank     = $this->calculate_graph_page_rank( array_keys( $nodes_by_id ), $edges );

		foreach ( $nodes_by_id as $id => $node ) {
			$nodes_by_id[ $id ]['anchors_in']  = array_values( array_unique( array_filter( $node['anchors_in'] ) ) );
			$nodes_by_id[ $id ]['anchors_out'] = array_values( array_unique( array_filter( $node['anchors_out'] ) ) );
			$nodes_by_id[ $id ]['hub_score']   = $this->calculate_hub_score( $nodes_by_id[ $id ] );
			$nodes_by_id[ $id ]['rank_score']  = isset( $page_rank[ $id ] ) ? $page_rank[ $id ] : 0;

			if ( 'publish' === $node['status'] && 0 === $node['inbound_count'] && ! $node['in_menu'] && $front_page_id !== $id ) {
				$severity = $this->calculate_orphan_severity( $nodes_by_id[ $id ] );
				$nodes_by_id[ $id ]['orphan_severity'] = $severity;
				$orphans[] = array(
					'id'    => $id,
					'title' => $node['title'],
					'type'  => $node['type'],
					'url'   => $node['url'],
					'severity' => $severity,
				);
			}
		}

		$nodes = array_values( $nodes_by_id );

		return array(
			'summary' => array(
				'node_count'   => count( $nodes ),
				'edge_count'   => count( $edges ),
				'orphan_count' => count( $orphans ),
				'post_types'   => $post_types,
				'statuses'     => $statuses,
				'signals'      => array( 'hub_score', 'rank_score', 'freshness_score', 'orphan_severity', 'menu_depth', 'shared_taxonomy' ),
			),
			'nodes'   => $nodes,
			'edges'   => $edges,
			'orphans' => $orphans,
			'workflow' => array(
				'read'     => 'Use this graph before creating pages or adding internal links.',
				'suggest'  => 'Choose candidate links from nodes and edges; do not invent internal URLs.',
				'approval' => 'Return a link diff before applying links to page content.',
			),
		);
	}

	/**
	 * Parse requested graph post types and keep only public/queryable types.
	 *
	 * @param string $post_types Raw comma-separated post types.
	 * @return array Post types.
	 */
	protected function parse_graph_post_types( $post_types ) {
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
	protected function get_graph_term_names( $post ) {
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
	protected function get_menu_post_ids() {
		return array_keys( $this->get_menu_post_depths() );
	}

	/**
	 * Get post IDs included in navigation menus with shallowest menu depth.
	 *
	 * @return array Post ID to depth map.
	 */
	protected function get_menu_post_depths() {
		$depths    = array();
		$locations = get_nav_menu_locations();

		foreach ( $locations as $menu_id ) {
			$items = wp_get_nav_menu_items( $menu_id );
			if ( empty( $items ) || is_wp_error( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				if ( 'post_type' === $item->type && ! empty( $item->object_id ) ) {
					$depth   = $this->get_menu_item_depth( $item, $items );
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
	protected function get_menu_item_depth( $item, $items ) {
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
	protected function calculate_freshness_score( $freshness_days ) {
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
	protected function calculate_hub_score( $node ) {
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
	protected function calculate_orphan_severity( $node ) {
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
	protected function calculate_graph_page_rank( $node_ids, $edges ) {
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
	protected function extract_heading_texts( $content ) {
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
	 * Extract raw anchor links from content.
	 *
	 * @param string $content Post content.
	 * @return array Links.
	 */
	protected function extract_raw_anchor_links( $content ) {
		$links = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$links[] = array(
					'href'   => trim( html_entity_decode( $match[1], ENT_QUOTES, get_bloginfo( 'charset' ) ) ),
					'anchor' => trim( wp_strip_all_tags( $match[2] ) ),
				);
			}
		}

		return $links;
	}

	/**
	 * Normalize internal URLs for graph lookup.
	 *
	 * @param string $url URL.
	 * @return string Normalized URL.
	 */
	protected function normalize_internal_graph_url( $url ) {
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
	protected function resolve_internal_graph_link( $href, $anchor, $url_to_id ) {
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

		$normalized = $this->normalize_internal_graph_url( $absolute );
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
	protected function extract_internal_links_from_content( $content, $url_to_id ) {
		$links = array();

		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$link = $this->resolve_internal_graph_link( $match[1], wp_strip_all_tags( $match[2] ), $url_to_id );
				if ( $link ) {
					$links[] = $link;
				}
			}
		}

		return $links;
	}
}
