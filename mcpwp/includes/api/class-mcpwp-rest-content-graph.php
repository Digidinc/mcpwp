<?php
/**
 * Content Graph REST Controller
 *
 * @package MCPWP
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
							'description' => __( 'Comma-separated post types to include.', 'mcpwp' ),
							'type'        => 'string',
							'default'     => 'page,post',
						),
						'limit' => array(
							'description'       => __( 'Maximum number of content nodes.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 500,
							'sanitize_callback' => 'absint',
						),
						'include_drafts' => array(
							'description'       => __( 'Include draft/private content nodes.', 'mcpwp' ),
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
							'description'       => __( 'Source post or page ID that needs internal links.', 'mcpwp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'post_types' => array(
							'description' => __( 'Comma-separated post types to include.', 'mcpwp' ),
							'type'        => 'string',
							'default'     => 'page,post',
						),
						'limit' => array(
							'description'       => __( 'Maximum number of graph nodes to inspect.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 500,
							'sanitize_callback' => 'absint',
						),
						'max_suggestions' => array(
							'description'       => __( 'Maximum suggestions to return.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 5,
							'minimum'           => 1,
							'maximum'           => 20,
							'sanitize_callback' => 'absint',
						),
						'include_drafts' => array(
							'description'       => __( 'Include draft/private content candidates.', 'mcpwp' ),
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
							'description'       => __( 'Source post or page ID to update.', 'mcpwp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'target_id' => array(
							'description'       => __( 'Existing graph target post or page ID.', 'mcpwp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'anchor' => array(
							'description' => __( 'Optional link anchor text. Defaults to target title.', 'mcpwp' ),
							'type'        => 'string',
						),
						'approval_required' => array(
							'description'       => __( 'Create an approval request instead of saving immediately. Defaults to true.', 'mcpwp' ),
							'type'              => 'boolean',
							'default'           => true,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'approval_note' => array(
							'description' => __( 'Optional human review note.', 'mcpwp' ),
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
							'description' => __( 'Comma-separated post types to include.', 'mcpwp' ),
							'type'        => 'string',
							'default'     => 'page,post',
						),
						'limit' => array(
							'description'       => __( 'Maximum number of content nodes to inspect.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 500,
							'sanitize_callback' => 'absint',
						),
						'include_drafts' => array(
							'description'       => __( 'Include draft/private source content.', 'mcpwp' ),
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

		// Status-parity guard: a read-only key must not be able to read a
		// draft/private source body — apply_internal_link echoes the source into
		// the approval record's public_record.diff (before_excerpt) and persists
		// it. Reject a non-public source with the same 404 a missing post returns,
		// so the endpoint never confirms a hidden draft exists or leaks its body.
		$source_guard = $this->guard_non_public_post( $source, 'Source post not found.' );
		if ( is_wp_error( $source_guard ) ) {
			return $source_guard;
		}

		// Category-parity guard (#1024, follow-up to #1022's WARN-2): a source
		// of a gated type (a WooCommerce product, an Elementor template) must
		// read exactly like a missing one — the same existence-oracle concern
		// the status guard above protects against, applied to category via
		// Mcpwp_Api_Auth::category_for_post_type(). Checked, and refused,
		// before anything about the source is read or an approval is queued.
		if ( ! $this->caller_may_read_category( $this->category_for_post_type( $source->post_type ) ) ) {
			return $this->error_response( 'not_found', 'Source post not found.', 404 );
		}

		if ( ! $target || 'publish' !== $target->post_status || ! $this->caller_may_read_category( $this->category_for_post_type( $target->post_type ) ) ) {
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

		// Approval-gate floor (admin-only bypass): an explicit approval_required=false is
		// honored only for an admin-scoped API key or the trusted admin-AJAX context. A
		// plain write/read key — or ANY OAuth connector, regardless of scope — is silently
		// forced through approval. OAuth now carries a key record (type 'oauth'), so it is
		// excluded here explicitly rather than by relying on a null record.
		if ( false === $approval_required ) {
			$requester_key = $this->get_current_api_key_record();
			$can_bypass    = $requester_key && ! $this->requester_is_oauth() && $this->key_has_scope( $requester_key, 'admin' );
			if ( ! ( $can_bypass || $this->is_trusted_admin_ajax_request() ) ) {
				$approval_required = true;
			}
		}

		if ( $approval_required ) {
			if ( ! class_exists( 'Mcpwp_Approvals' ) ) {
				return $this->error_response( 'approvals_unavailable', 'Approval pipeline is not available.', 500 );
			}

			$identity_guard = $this->guard_stable_identity_for_approvals();
			if ( is_wp_error( $identity_guard ) ) {
				return $identity_guard;
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
						$issues[] = $this->make_internal_link_issue( 'empty_anchor', 'warning', $source_id, $target_id, $link, __( 'Use descriptive anchor text for internal links.', 'mcpwp' ) );
					} elseif ( $this->is_weak_internal_link_anchor( $link['anchor'] ) ) {
						$issues[] = $this->make_internal_link_issue( 'weak_anchor', 'warning', $source_id, $target_id, $link, __( 'Replace generic anchor text with a descriptive phrase.', 'mcpwp' ) );
					}
				}

				if ( ! $target ) {
					$issues[] = $this->make_internal_link_issue( 'missing_target', 'error', $source_id, 0, $link, __( 'Link points to an internal URL that does not resolve to known content.', 'mcpwp' ) );
					continue;
				}

				if ( $source_id === $target_id ) {
					$issues[] = $this->make_internal_link_issue( 'self_link', 'warning', $source_id, $target_id, $link, __( 'Remove self-links unless they point to a useful in-page anchor.', 'mcpwp' ) );
				}

				if ( 'publish' !== $target->post_status ) {
					$issues[] = $this->make_internal_link_issue( 'unpublished_target', 'error', $source_id, $target_id, $link, __( 'Link target is not published.', 'mcpwp' ) );
				}

				if ( isset( $seen_targets[ $target_id ] ) ) {
					$issues[] = $this->make_internal_link_issue( 'duplicate_target', 'warning', $source_id, $target_id, $link, __( 'Source content links to the same internal target more than once.', 'mcpwp' ) );
				}
				$seen_targets[ $target_id ] = true;

				$canonical = get_permalink( $target );
				if ( $canonical && $this->normalize_internal_graph_url( $canonical ) !== $this->normalize_internal_graph_url( $resolved['absolute'] ) ) {
					$issues[] = $this->make_internal_link_issue( 'non_canonical_url', 'warning', $source_id, $target_id, $link, __( 'Use the canonical permalink for this internal target.', 'mcpwp' ), array( 'canonical_url' => $canonical ) );
				}
			}
		}

		$error_count   = 0;
		$warning_count = 0;
		$by_source     = array(
			'post_content' => 0,
			'elementor'    => 0,
		);

		foreach ( $issues as $issue ) {
			if ( 'error' === $issue['severity'] ) {
				$error_count++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$warning_count++;
			}

			$issue_source = isset( $issue['source'] ) ? (string) $issue['source'] : 'post_content';
			if ( isset( $by_source[ $issue_source ] ) ) {
				$by_source[ $issue_source ]++;
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
					'by_source'     => $by_source,
				),
				'issues'           => $issues,
				'validation_basis' => 'stored_content_and_elementor_data',
				'validation_note'  => 'Links verified against stored post_content and Elementor widget data (_elementor_data), read live on every call — nothing here is cached. Each issue carries the "source" it was read from, and summary.by_source counts them: the two copies are edited by different tools, so a repair applied to one leaves the other reporting the same link. Rendered-HTML link crawl (catching JS-rendered or theme-injected links) is not performed — reachability issues caused by JS rendering or server-side redirects will not appear here.',
				'workflow' => array(
					'read'  => 'Use before publishing or applying internal link suggestions.',
					'fix'   => 'Use approval-first Gutenberg edits to fix accepted issues.',
					'guard' => 'This endpoint is read-only and does not mutate content.',
				),
			)
		);
	}

	// --- Private helpers ---
	//
	// parse_graph_post_types(), get_graph_term_names(), get_menu_post_ids(),
	// get_menu_post_depths(), get_menu_item_depth(), calculate_freshness_score(),
	// calculate_hub_score(), calculate_orphan_severity(), calculate_graph_page_rank()
	// and extract_heading_texts() used to be redefined here byte-for-byte
	// identical to class-mcpwp-rest-api.php's copies (dead duplication — this
	// subclass's override shadowed the parent's without differing from it).
	// Removed (#943 S1): calls now fall through to the parent, which itself
	// delegates to Mcpwp_Graph_Builder — one parser, not two.

	/**
	 * Extract internal links from post content.
	 *
	 * @param string $content   Post content.
	 * @param array  $url_to_id Normalized URL to post ID map.
	 * @return array Links.
	 */
	protected function extract_internal_links_from_content( $content, $url_to_id ) {
		return Mcpwp_Graph_Builder::extract_internal_links_from_content( $content, $url_to_id );
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
					// Tagged so a reported issue can say WHICH stored copy holds
					// the link. The two are edited by different tools, and a
					// repair to one leaves the other untouched (#728).
					'source' => 'post_content',
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
				// Which stored copy the link was read from: 'post_content' or
				// 'elementor'. Without it the report cannot be acted on. An
				// operator who repairs _elementor_data and re-runs the check
				// sees the same issue again, verifies with wp_get_elementor
				// that the old string is gone, and has no way to learn the
				// remaining copy is in post_content — reported as a stale-cache
				// bug precisely because the report could not say (#728).
				'source'            => isset( $link['source'] ) && 'elementor' === $link['source'] ? 'elementor' : 'post_content',
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

		return trim( (string) ( $candidate['slug'] ?? __( 'Related content', 'mcpwp' ) ) );
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
			esc_html__( 'Related:', 'mcpwp' ),
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
		return Mcpwp_Graph_Builder::resolve_internal_graph_link( $href, $anchor, $url_to_id );
	}

	/**
	 * Normalize internal URLs for graph lookup.
	 *
	 * @param string $url URL.
	 * @return string Normalized URL.
	 */
	protected function normalize_internal_graph_url( $url ) {
		return Mcpwp_Graph_Builder::normalize_internal_graph_url( $url );
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
		return Mcpwp_Graph_Builder::extract_raw_links_from_elementor( $post_id );
	}

	/**
	 * Recursively collect {href, anchor, source} records from Elementor elements.
	 *
	 * @param array $elements Elementor elements array.
	 * @param array $links    Accumulator passed by reference.
	 */
	protected function collect_elementor_raw_links( $elements, &$links ) {
		Mcpwp_Graph_Builder::collect_elementor_raw_links( $elements, $links );
	}

}
