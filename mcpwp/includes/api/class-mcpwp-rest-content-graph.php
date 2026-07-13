<?php
/**
 * Content Graph REST Controller
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content graph and internal link REST endpoints.
 */
class Mcpwp_REST_Content_Graph extends Mcpwp_REST_API {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->register_routes();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Internal content graph for SEO and internal linking workflows.
		register_rest_route(
			$this->namespace,
			'/content-graph',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_content_graph' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'post_types' => array(
							'description' => __( 'Comma-separated post types to include.', 'mumega-mcp' ),
							'type'        => 'string',
							'default'     => 'page,post',
						),
						'limit' => array(
							'description'       => __( 'Maximum number of content nodes.', 'mumega-mcp' ),
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 500,
							'sanitize_callback' => 'absint',
						),
						'include_drafts' => array(
							'description'       => __( 'Include draft/private content nodes.', 'mumega-mcp' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		// Internal link suggestions from the content graph.
		register_rest_route(
			$this->namespace,
			'/content-graph/suggestions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_internal_link_suggestions' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_id' => array(
							'description'       => __( 'Source post or page ID that needs internal links.', 'mumega-mcp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'post_types' => array(
							'description' => __( 'Comma-separated post types to include.', 'mumega-mcp' ),
							'type'        => 'string',
							'default'     => 'page,post',
						),
						'limit' => array(
							'description'       => __( 'Maximum number of graph nodes to inspect.', 'mumega-mcp' ),
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 500,
							'sanitize_callback' => 'absint',
						),
						'max_suggestions' => array(
							'description'       => __( 'Maximum suggestions to return.', 'mumega-mcp' ),
							'type'              => 'integer',
							'default'           => 5,
							'minimum'           => 1,
							'maximum'           => 20,
							'sanitize_callback' => 'absint',
						),
						'include_drafts' => array(
							'description'       => __( 'Include draft/private content candidates.', 'mumega-mcp' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		// Approval-first internal link application from graph targets.
		register_rest_route(
			$this->namespace,
			'/content-graph/apply-link',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'apply_internal_link' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'source_id' => array(
							'description'       => __( 'Source post or page ID to update.', 'mumega-mcp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'target_id' => array(
							'description'       => __( 'Existing graph target post or page ID.', 'mumega-mcp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'anchor' => array(
							'description' => __( 'Optional link anchor text. Defaults to target title.', 'mumega-mcp' ),
							'type'        => 'string',
						),
						'approval_required' => array(
							'description'       => __( 'Create an approval request instead of saving immediately. Defaults to true.', 'mumega-mcp' ),
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'approval_note' => array(
							'description' => __( 'Optional human review note.', 'mumega-mcp' ),
							'type'        => 'string',
						),
					),
				),
			)
		);

		// Read-only internal link validation for SEO and publishing checks.
		register_rest_route(
			$this->namespace,
			'/content-graph/validate-links',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'validate_internal_links' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'post_types' => array(
							'description' => __( 'Comma-separated post types to include.', 'mumega-mcp' ),
							'type'        => 'string',
							'default'     => 'page,post',
						),
						'limit' => array(
							'description'       => __( 'Maximum number of content nodes to inspect.', 'mumega-mcp' ),
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 500,
							'sanitize_callback' => 'absint',
						),
						'include_drafts' => array(
							'description'       => __( 'Include draft/private source content.', 'mumega-mcp' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);
	}

	/**
	 * Get a lightweight internal content graph for agents.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_content_graph( $request ) {
		$this->log_activity( 'get_content_graph', $request );

		$post_types = $this->parse_graph_post_types( (string) $request->get_param( 'post_types' ) );
		$limit      = min( 500, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$graph      = $this->build_content_graph_data( $post_types, $limit, rest_sanitize_boolean( $request->get_param( 'include_drafts' ) ) );

		return $this->success_response( $graph );
	}

	/**
	 * Get internal link suggestions from the graph.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_internal_link_suggestions( $request ) {
		$source_id = absint( $request->get_param( 'source_id' ) );
		$source    = get_post( $source_id );

		if ( ! $source ) {
			return $this->error_response( 'not_found', 'Source post not found.', 404 );
		}

		$this->log_activity( 'suggest_internal_links', $request, array( 'source_id' => $source_id ) );

		$post_types      = $this->parse_graph_post_types( (string) $request->get_param( 'post_types' ) );
		$limit           = min( 500, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$max_suggestions = min( 20, max( 1, absint( $request->get_param( 'max_suggestions' ) ) ) );
		$graph           = $this->build_content_graph_data( $post_types, $limit, rest_sanitize_boolean( $request->get_param( 'include_drafts' ) ) );
		$nodes_by_id     = array();

		foreach ( $graph['nodes'] as $node ) {
			$nodes_by_id[ (int) $node['id'] ] = $node;
		}

		if ( empty( $nodes_by_id[ $source_id ] ) ) {
			return $this->error_response( 'source_not_in_graph', 'Source post is not included in the content graph.', 404 );
		}

		$already_linked = array();
		foreach ( $graph['edges'] as $edge ) {
			if ( 'content_link' === $edge['type'] && (int) $edge['from'] === $source_id ) {
				$already_linked[] = (int) $edge['to'];
			}
		}

		$source_node   = $nodes_by_id[ $source_id ];
		$source_tokens = $this->build_link_suggestion_tokens( $source_node, $source->post_content );
		$suggestions   = array();

		foreach ( $nodes_by_id as $candidate_id => $candidate ) {
			if ( $candidate_id === $source_id || in_array( $candidate_id, $already_linked, true ) || 'publish' !== $candidate['status'] ) {
				continue;
			}

			$candidate_post   = get_post( $candidate_id );
			$candidate_tokens = $this->build_link_suggestion_tokens( $candidate, $candidate_post ? $candidate_post->post_content : '' );
			$overlap          = array_values( array_intersect( $source_tokens, $candidate_tokens ) );
			$shared_terms     = array_values( array_intersect( $source_node['terms'], $candidate['terms'] ) );
			$score            = ( count( $overlap ) * 2 ) + ( count( $shared_terms ) * 3 );

			if ( $source_node['type'] === $candidate['type'] ) {
				$score++;
			}

			if ( 0 === (int) $candidate['inbound_count'] ) {
				$score += 2;
			}

			if ( $score < 3 ) {
				continue;
			}

			$anchor = $this->choose_internal_link_anchor( $candidate, $overlap );

			$suggestions[] = array(
				'target_id'         => $candidate_id,
				'title'             => $candidate['title'],
				'url'               => $candidate['url'],
				'anchor'            => $anchor,
				'score'             => $score,
				'reasons'           => array_values(
					array_filter(
						array(
							! empty( $overlap ) ? 'Shared topic terms: ' . implode( ', ', array_slice( $overlap, 0, 5 ) ) : '',
							! empty( $shared_terms ) ? 'Shared taxonomy terms: ' . implode( ', ', array_slice( $shared_terms, 0, 5 ) ) : '',
							0 === (int) $candidate['inbound_count'] ? 'Candidate has no inbound links in the current graph.' : '',
						)
					)
				),
				'approval_diff'     => array(
					'action'      => 'insert_internal_link',
					'source_id'   => $source_id,
					'target_id'   => $candidate_id,
					'link_html'   => sprintf( '<a href="%s">%s</a>', esc_url( $candidate['url'] ), esc_html( $anchor ) ),
					'insert_hint' => 'Place this link where the anchor topic is already discussed. Do not add unrelated or repeated links.',
				),
				'approval_required' => true,
			);
		}

		usort(
			$suggestions,
			function ( $a, $b ) {
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);

		return $this->success_response(
			array(
				'source'      => array(
					'id'    => $source_id,
					'title' => $source_node['title'],
					'url'   => $source_node['url'],
				),
				'suggestions' => array_slice( $suggestions, 0, $max_suggestions ),
				'workflow'    => array(
					'read'     => 'Review suggested links and anchors before editing content.',
					'apply'    => 'Use wp_patch_block_section or wp_set_blocks with approval_required=true to add accepted links.',
					'guardrail' => 'Suggestions use existing graph URLs only; agents should not invent internal URLs.',
				),
			)
		);
	}

	/**
	 * Apply an accepted internal link suggestion through approval-first content update.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function apply_internal_link( $request ) {
		$source_id = absint( $request->get_param( 'source_id' ) );
		$target_id = absint( $request->get_param( 'target_id' ) );
		$source    = get_post( $source_id );
		$target    = get_post( $target_id );

		if ( ! $source ) {
			return $this->error_response( 'not_found', 'Source post not found.', 404 );
		}

		if ( ! $target || 'publish' !== $target->post_status ) {
			return $this->error_response( 'target_not_found', 'Target post must be an existing published graph node.', 404 );
		}

		if ( $source_id === $target_id ) {
			return $this->error_response( 'self_link_rejected', 'Internal link target cannot be the source post.', 400 );
		}

		$target_url = get_permalink( $target );
		if ( ! $target_url ) {
			return $this->error_response( 'target_url_unavailable', 'Target post does not have a permalink.', 400 );
		}

		$url_to_id = array(
			$this->normalize_internal_graph_url( $target_url ) => $target_id,
		);
		$existing_links          = $this->extract_internal_links_from_content( $source->post_content, $url_to_id );
		$existing_elementor_links = $this->extract_elementor_links_from_postmeta( $source_id, $url_to_id );
		if ( ! empty( $existing_links ) || ! empty( $existing_elementor_links ) ) {
			return $this->error_response( 'duplicate_internal_link', 'Source post already links to this target.', 409 );
		}

		$anchor = trim( (string) $request->get_param( 'anchor' ) );
		if ( '' === $anchor ) {
			$anchor = get_the_title( $target );
		}

		$anchor = wp_strip_all_tags( $anchor );
		if ( '' === $anchor ) {
			return $this->error_response( 'missing_anchor', 'Internal link anchor cannot be empty.', 400 );
		}

		$link_block       = $this->build_internal_link_paragraph_block( $target_url, $anchor );
		$patched_content = rtrim( (string) $source->post_content ) . "\n\n" . $link_block;
		$approval_required = null === $request->get_param( 'approval_required' )
			? true
			: rest_sanitize_boolean( $request->get_param( 'approval_required' ) );
		$approval_note = (string) $request->get_param( 'approval_note' );

		if ( $approval_required ) {
			if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
				return $this->error_response( 'approvals_unavailable', 'Approval pipeline is not available.', 500 );
			}

			$approval = Mcpwp_Approvals::create_post_content_request(
				$source_id,
				$patched_content,
				array(
					'title'               => sprintf( 'Add internal link from #%d to #%d', (int) $source_id, (int) $target_id ),
					'note'                => $approval_note,
					'tool'                => 'wp_apply_internal_link',
					'requested_by_key_id' => $this->current_requester_key_id(),
					'metadata'            => array(
						'source_id'  => $source_id,
						'target_id'  => $target_id,
						'target_url' => $target_url,
						'anchor'     => $anchor,
						'placement'  => 'append_related_paragraph',
					),
				)
			);

			if ( is_wp_error( $approval ) ) {
				return $approval;
			}

			$this->log_activity( 'request_internal_link_approval', $request, array( 'source_id' => $source_id, 'target_id' => $target_id, 'approval_id' => $approval['id'] ) );

			return $this->success_response(
				array(
					'success'  => true,
					'status'   => 'approval_required',
					'source_id' => $source_id,
					'target_id' => $target_id,
					'link'      => array(
						'url'    => $target_url,
						'anchor' => $anchor,
						'html'   => sprintf( '<a href="%s">%s</a>', esc_url( $target_url ), esc_html( $anchor ) ),
					),
					'approval' => $approval,
				),
				202
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $source_id,
				'post_content' => $patched_content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_response( 'update_failed', $result->get_error_message(), 500 );
		}

		$this->log_activity( 'apply_internal_link', $request, array( 'source_id' => $source_id, 'target_id' => $target_id ) );

		return $this->success_response(
			array(
				'success'  => true,
				'source_id' => $source_id,
				'target_id' => $target_id,
				'link'      => array(
					'url'    => $target_url,
					'anchor' => $anchor,
					'html'   => sprintf( '<a href="%s">%s</a>', esc_url( $target_url ), esc_html( $anchor ) ),
				),
			)
		);
	}

	/**
	 * Validate existing internal links without mutating content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function validate_internal_links( $request ) {
		$this->log_activity( 'validate_internal_links', $request );

		$post_types     = $this->parse_graph_post_types( (string) $request->get_param( 'post_types' ) );
		$limit          = min( 500, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$include_drafts = rest_sanitize_boolean( $request->get_param( 'include_drafts' ) );
		$graph          = $this->build_content_graph_data( $post_types, $limit, $include_drafts );
		$url_to_id      = array();
		$nodes_by_id    = array();
		$issues         = array();

		foreach ( $graph['nodes'] as $node ) {
			$nodes_by_id[ (int) $node['id'] ] = $node;
			$url_to_id[ $this->normalize_internal_graph_url( $node['url'] ) ] = (int) $node['id'];
		}

		foreach ( array_keys( $nodes_by_id ) as $source_id ) {
			$source = get_post( $source_id );
			if ( ! $source ) {
				continue;
			}

			$seen_targets = array();

			// Merge post_content <a> links with Elementor widget links so Elementor-only
			// pages (where post_content is empty) are fully covered.
			$content_links  = $this->extract_raw_anchor_links( $source->post_content );
			$elementor_links = $this->extract_raw_links_from_elementor( $source_id );
			$links           = array_merge( $content_links, $elementor_links );

			foreach ( $links as $link ) {
				$resolved = $this->resolve_internal_link_for_validation( $link['href'], $url_to_id );
				if ( null === $resolved ) {
					continue;
				}

				$target_id   = (int) $resolved['target_id'];
				$target      = $target_id ? get_post( $target_id ) : null;
				$is_elementor = 'elementor' === ( $link['source'] ?? '' );

				// Anchor-quality checks apply to post_content links only; Elementor button
				// "anchors" are button labels, not traditional hyperlink anchor text.
				if ( ! $is_elementor ) {
					if ( '' === trim( $link['anchor'] ) ) {
						$issues[] = $this->make_internal_link_issue( 'empty_anchor', 'warning', $source_id, $target_id, $link, __( 'Use descriptive anchor text for internal links.', 'mumega-mcp' ) );
					} elseif ( $this->is_weak_internal_link_anchor( $link['anchor'] ) ) {
						$issues[] = $this->make_internal_link_issue( 'weak_anchor', 'warning', $source_id, $target_id, $link, __( 'Replace generic anchor text with a descriptive phrase.', 'mumega-mcp' ) );
					}
				}

				if ( ! $target ) {
					$issues[] = $this->make_internal_link_issue( 'missing_target', 'error', $source_id, 0, $link, __( 'Link points to an internal URL that does not resolve to known content.', 'mumega-mcp' ) );
					continue;
				}

				if ( $source_id === $target_id ) {
					$issues[] = $this->make_internal_link_issue( 'self_link', 'warning', $source_id, $target_id, $link, __( 'Remove self-links unless they point to a useful in-page anchor.', 'mumega-mcp' ) );
				}

				if ( 'publish' !== $target->post_status ) {
					$issues[] = $this->make_internal_link_issue( 'unpublished_target', 'error', $source_id, $target_id, $link, __( 'Link target is not published.', 'mumega-mcp' ) );
				}

				if ( isset( $seen_targets[ $target_id ] ) ) {
					$issues[] = $this->make_internal_link_issue( 'duplicate_target', 'warning', $source_id, $target_id, $link, __( 'Source content links to the same internal target more than once.', 'mumega-mcp' ) );
				}
				$seen_targets[ $target_id ] = true;

				$canonical = get_permalink( $target );
				if ( $canonical && $this->normalize_internal_graph_url( $canonical ) !== $this->normalize_internal_graph_url( $resolved['absolute'] ) ) {
					$issues[] = $this->make_internal_link_issue( 'non_canonical_url', 'warning', $source_id, $target_id, $link, __( 'Use the canonical permalink for this internal target.', 'mumega-mcp' ), array( 'canonical_url' => $canonical ) );
				}
			}
		}

		$error_count   = 0;
		$warning_count = 0;
		foreach ( $issues as $issue ) {
			if ( 'error' === $issue['severity'] ) {
				$error_count++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$warning_count++;
			}
		}

		return $this->success_response(
			array(
				'summary' => array(
					'status'        => 0 === $error_count ? ( $warning_count > 0 ? 'warn' : 'pass' ) : 'fail',
					'issue_count'   => count( $issues ),
					'error_count'   => $error_count,
					'warning_count' => $warning_count,
					'node_count'    => count( $nodes_by_id ),
				),
				'issues'           => $issues,
				'validation_basis' => 'stored_content_and_elementor_data',
				'validation_note'  => 'Links verified against stored post_content and Elementor widget data (_elementor_data). Rendered-HTML link crawl (catching JS-rendered or theme-injected links) is not performed — reachability issues caused by JS rendering or server-side redirects will not appear here.',
				'workflow' => array(
					'read'  => 'Use before publishing or applying internal link suggestions.',
					'fix'   => 'Use approval-first Gutenberg edits to fix accepted issues.',
					'guard' => 'This endpoint is read-only and does not mutate content.',
				),
			)
		);
	}

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

			// Also extract links embedded in Elementor widget settings (buttons, text editors, HTML widgets, etc.)
			// post_content is empty/minimal for Elementor pages, so <a> regex alone misses all rendered links.
			$elementor_links = $this->extract_elementor_links_from_postmeta( $from_id, $url_to_id );
			$links           = array_merge( $links, $elementor_links );

			$linked_to = array();

			foreach ( $links as $link ) {
				$to_id = $link['target_id'];
				if ( $from_id === $to_id || ! isset( $nodes_by_id[ $to_id ] ) ) {
					continue;
				}

				// Deduplicate edges per source→target pair so Elementor + post_content don't double-count.
				if ( isset( $linked_to[ $to_id ] ) ) {
					continue;
				}
				$linked_to[ $to_id ] = true;

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
	 * Extract internal links from post content.
	 *
	 * @param string $content   Post content.
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
	 * Resolve an internal link for validation.
	 *
	 * @param string $href      Raw href.
	 * @param array  $url_to_id Normalized URL map.
	 * @return array|null Resolved link, null for external/non-content links.
	 */
	protected function resolve_internal_link_for_validation( $href, $url_to_id ) {
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

		return array(
			'target_id'  => $target_id,
			'absolute'   => $absolute,
			'normalized' => $normalized,
		);
	}

	/**
	 * Determine whether anchor text is too generic.
	 *
	 * @param string $anchor Anchor text.
	 * @return bool True when weak.
	 */
	protected function is_weak_internal_link_anchor( $anchor ) {
		$anchor = strtolower( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $anchor ) ) ) );
		return in_array(
			$anchor,
			array(
				'click here',
				'here',
				'learn more',
				'read more',
				'more',
				'this',
				'link',
				'page',
			),
			true
		);
	}

	/**
	 * Create a normalized internal link issue.
	 *
	 * @param string $code           Issue code.
	 * @param string $severity       Severity.
	 * @param int    $source_id      Source post ID.
	 * @param int    $target_id      Target post ID.
	 * @param array  $link           Link data.
	 * @param string $recommendation Recommendation.
	 * @param array  $extra          Extra fields.
	 * @return array Issue.
	 */
	protected function make_internal_link_issue( $code, $severity, $source_id, $target_id, $link, $recommendation, $extra = array() ) {
		return array_merge(
			array(
				'code'              => $code,
				'severity'          => $severity,
				'source_id'         => (int) $source_id,
				'target_id'         => (int) $target_id,
				'url'               => (string) $link['href'],
				'anchor'            => (string) $link['anchor'],
				'recommendation'    => $recommendation,
				'approval_required' => true,
			),
			$extra
		);
	}

	/**
	 * Create a normalized SEO readiness issue.
	 *
	 * @param string $code           Issue code.
	 * @param string $severity       Severity.
	 * @param string $message        Message.
	 * @param string $recommendation Recommendation.
	 * @param array  $extra          Extra fields.
	 * @return array Issue.
	 */

	/**
	 * Build normalized tokens for link suggestion scoring.
	 *
	 * @param array  $node    Graph node.
	 * @param string $content Raw content.
	 * @return array Tokens.
	 */
	protected function build_link_suggestion_tokens( $node, $content = '' ) {
		$text_parts = array(
			$node['title'] ?? '',
			$node['slug'] ?? '',
			$node['excerpt'] ?? '',
			wp_strip_all_tags( $content ),
		);

		if ( ! empty( $node['terms'] ) ) {
			$text_parts[] = implode( ' ', $node['terms'] );
		}

		if ( ! empty( $node['headings'] ) ) {
			foreach ( $node['headings'] as $heading ) {
				if ( ! empty( $heading['text'] ) ) {
					$text_parts[] = $heading['text'];
				}
			}
		}

		$text   = strtolower( html_entity_decode( implode( ' ', $text_parts ), ENT_QUOTES, get_bloginfo( 'charset' ) ) );
		$tokens = preg_split( '/[^a-z0-9]+/', $text );
		$stop   = array(
			'a',
			'an',
			'and',
			'are',
			'as',
			'at',
			'be',
			'by',
			'for',
			'from',
			'how',
			'in',
			'is',
			'it',
			'of',
			'on',
			'or',
			'our',
			'the',
			'this',
			'to',
			'we',
			'with',
			'you',
			'your',
		);

		$tokens = array_filter(
			$tokens,
			function ( $token ) use ( $stop ) {
				return strlen( $token ) >= 4 && ! in_array( $token, $stop, true );
			}
		);

		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Choose a conservative anchor for a suggested internal link.
	 *
	 * @param array $candidate Candidate node.
	 * @param array $overlap   Shared tokens.
	 * @return string Anchor.
	 */
	protected function choose_internal_link_anchor( $candidate, $overlap ) {
		$title = trim( (string) ( $candidate['title'] ?? '' ) );
		if ( '' !== $title ) {
			return $title;
		}

		if ( ! empty( $overlap ) ) {
			return implode( ' ', array_slice( $overlap, 0, 3 ) );
		}

		return trim( (string) ( $candidate['slug'] ?? __( 'Related content', 'mumega-mcp' ) ) );
	}

	/**
	 * Build a native Gutenberg paragraph containing a deterministic internal link.
	 *
	 * @param string $url    Target URL.
	 * @param string $anchor Anchor text.
	 * @return string Block markup.
	 */
	protected function build_internal_link_paragraph_block( $url, $anchor ) {
		$link = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $anchor ) );
		return sprintf(
			'<!-- wp:paragraph --><p>%s %s</p><!-- /wp:paragraph -->',
			esc_html__( 'Related:', 'mumega-mcp' ),
			$link
		);
	}

	/**
	 * Resolve a URL to a graph link if it points to known local content.
	 *
	 * @param string $href      Raw href.
	 * @param string $anchor    Anchor text.
	 * @param array  $url_to_id Normalized URL to post ID map.
	 * @return array|null Link record.
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
	 * Extract internal links from Elementor widget JSON stored in _elementor_data post meta.
	 *
	 * Elementor pages store content in post meta, not post_content, so the standard
	 * <a> regex extracts nothing. This method walks the JSON tree and reads link URLs
	 * from widget settings (buttons, text editors, HTML widgets, icon boxes, images).
	 *
	 * @param int   $post_id   Post ID.
	 * @param array $url_to_id Normalized URL to post ID map.
	 * @return array Link records compatible with extract_internal_links_from_content().
	 */
	protected function extract_elementor_links_from_postmeta( $post_id, $url_to_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return array();
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$hrefs = array();
		$this->collect_elementor_link_hrefs( $data, $hrefs );

		$links = array();
		foreach ( array_unique( $hrefs ) as $href ) {
			$href = trim( html_entity_decode( $href, ENT_QUOTES, 'UTF-8' ) );
			if ( '' === $href ) {
				continue;
			}
			$link = $this->resolve_internal_graph_link( $href, '', $url_to_id );
			if ( $link ) {
				$links[] = $link;
			}
		}

		return $links;
	}

	/**
	 * Recursively collect link hrefs from an Elementor elements tree.
	 *
	 * Covers the most common link-bearing widget settings:
	 *   - settings.link.url   (button, icon-box, image, image-box, call-to-action, flip-box)
	 *   - settings.url.url    (icon widget)
	 *   - settings.editor     (text-editor: HTML content with <a> tags)
	 *   - settings.html       (html widget: raw HTML with <a> tags)
	 *   - settings.content    (some rich-text widgets)
	 *
	 * @param array $elements Elementor elements array.
	 * @param array $hrefs    Accumulator passed by reference.
	 */
	protected function collect_elementor_link_hrefs( $elements, &$hrefs ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			// button, icon-box, image, image-box, call-to-action, flip-box — link object.
			if ( ! empty( $settings['link']['url'] ) && is_string( $settings['link']['url'] ) ) {
				$hrefs[] = $settings['link']['url'];
			}

			// icon widget — url object.
			if ( ! empty( $settings['url']['url'] ) && is_string( $settings['url']['url'] ) ) {
				$hrefs[] = $settings['url']['url'];
			}

			// text-editor, html, rich-text content — parse HTML for <a href>.
			foreach ( array( 'editor', 'html', 'content', 'inner_text' ) as $field ) {
				if ( ! empty( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
					if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $settings[ $field ], $matches ) ) {
						foreach ( $matches[1] as $href ) {
							$hrefs[] = $href;
						}
					}
				}
			}

			// Recurse into child elements.
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collect_elementor_link_hrefs( $element['elements'], $hrefs );
			}
		}
	}

	/**
	 * Extract links from Elementor widget data in {href, anchor, source} format
	 * compatible with the validate_internal_links loop.
	 *
	 * anchor is best-effort: widget button_text / text for URL-only links, actual anchor
	 * text for HTML-content widgets. source is always 'elementor' so the validator can
	 * skip pure-anchor-quality checks (empty/weak anchor) that don't apply to buttons.
	 *
	 * @param int $post_id Post ID.
	 * @return array[] Array of {href, anchor, source} records.
	 */
	protected function extract_raw_links_from_elementor( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( empty( $raw ) || ! is_string( $raw ) ) {
			return array();
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$links = array();
		$this->collect_elementor_raw_links( $data, $links );

		// Deduplicate by href.
		$seen  = array();
		$dedup = array();
		foreach ( $links as $link ) {
			$key = strtolower( trim( $link['href'] ) );
			if ( '' !== $key && ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				$dedup[]       = $link;
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
	protected function collect_elementor_raw_links( $elements, &$links ) {
		if ( ! is_array( $elements ) ) {
			return;
		}

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

			// URL-typed settings: link.url, url.url. Anchor = button text or title where available.
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

			// HTML-content settings: parse <a> tags to get real hrefs + anchors.
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

			// Recurse into child elements.
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->collect_elementor_raw_links( $element['elements'], $links );
			}
		}
	}

}
