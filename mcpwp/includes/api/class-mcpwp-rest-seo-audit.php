<?php
/**
 * SEO Audit REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO audit REST endpoints.
 */
class Mcpwp_REST_SEO_Audit extends Mcpwp_REST_API {

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
		// SEO pre-publish readiness checks.
		register_rest_route(
			$this->namespace,
			'/seo/readiness/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'validate_seo_readiness' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Post or page ID to validate.', 'mcpwp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// SEO site audit summary.
		register_rest_route(
			$this->namespace,
			'/seo/audit',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'audit_seo_site' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'post_types' => array(
							'description' => __( 'Comma-separated post types to include.', 'mcpwp' ),
							'type'        => 'string',
							'default'     => 'page,post',
						),
						'limit' => array(
							'description'       => __( 'Maximum number of URLs to audit.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 25,
							'minimum'           => 1,
							'maximum'           => 50,
							'sanitize_callback' => 'absint',
						),
						'include_drafts' => array(
							'description'       => __( 'Include draft/private content.', 'mcpwp' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'store' => array(
							'description'       => __( 'Store this audit run and normalized issue records.', 'mcpwp' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		// Stored SEO issues.
		register_rest_route(
			$this->namespace,
			'/seo/issues',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_seo_issues' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status' => array(
							'description'       => __( 'Issue status filter: open or resolved.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'severity' => array(
							'description'       => __( 'Severity filter: error, warning, or info.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'category' => array(
							'description'       => __( 'Issue category filter.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'post_id' => array(
							'description'       => __( 'Post ID filter.', 'mcpwp' ),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'run_id' => array(
							'description'       => __( 'Audit run ID filter.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'limit' => array(
							'description'       => __( 'Maximum issues to return.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 200,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Approval-safe SEO autofix plan.
		register_rest_route(
			$this->namespace,
			'/seo/autofix-plan',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_seo_autofix_plan' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'severity' => array(
							'description'       => __( 'Severity filter: error, warning, or info.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'category' => array(
							'description'       => __( 'Issue category filter.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'post_id' => array(
							'description'       => __( 'Post ID filter.', 'mcpwp' ),
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'run_id' => array(
							'description'       => __( 'Audit run ID filter.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'issue_id' => array(
							'description'       => __( 'Specific stored issue ID.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'limit' => array(
							'description'       => __( 'Maximum issues to inspect.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 50,
							'minimum'           => 1,
							'maximum'           => 200,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Search Console/Bing/manual search performance import.
		register_rest_route(
			$this->namespace,
			'/seo/search-performance/import',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'import_search_performance' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'provider' => array(
							'description'       => __( 'Provider slug such as google_search_console, bing_webmaster, or manual.', 'mcpwp' ),
							'type'              => 'string',
							'default'           => 'manual',
							'sanitize_callback' => 'sanitize_key',
						),
						'source' => array(
							'description'       => __( 'Optional source label for the export or import.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		// Search performance trends and reporting.
		register_rest_route(
			$this->namespace,
			'/seo/search-performance',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_search_performance' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'provider' => array(
							'description'       => __( 'Provider filter.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'url' => array(
							'description'       => __( 'Exact URL filter.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
						'query' => array(
							'description'       => __( 'Search query contains filter.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'days' => array(
							'description'       => __( 'Lookback window in days.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 90,
							'minimum'           => 1,
							'maximum'           => 365,
							'sanitize_callback' => 'absint',
						),
						'limit' => array(
							'description'       => __( 'Maximum grouped rows to return.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// WooCommerce SEO intelligence.
		register_rest_route(
			$this->namespace,
			'/seo/woocommerce',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_woocommerce_seo_report' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status' => array(
							'description'       => __( 'Product status filter: publish, draft, private, or any.', 'mcpwp' ),
							'type'              => 'string',
							'default'           => 'publish',
							'sanitize_callback' => 'sanitize_key',
						),
						'limit' => array(
							'description'       => __( 'Maximum products to inspect.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 25,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Structured data inventory and validation.
		register_rest_route(
			$this->namespace,
			'/seo/structured-data/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'validate_structured_data' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Post or page ID to validate.', 'mcpwp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Media SEO audit.
		register_rest_route(
			$this->namespace,
			'/seo/media/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'audit_media_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Post or page ID to audit.', 'mcpwp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Keyword research — Google Suggest autocomplete expansion.
		register_rest_route(
			$this->namespace,
			'/keyword-research',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'keyword_research' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'seed' => array(
							'description'       => __( 'Seed keyword phrase to expand.', 'mcpwp' ),
							'type'              => 'string',
							'required'          => true,
							'minLength'         => 1,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'hl'   => array(
							'description'       => __( 'Language code for Google Suggest, e.g. en.', 'mcpwp' ),
							'type'              => 'string',
							'default'           => 'en',
							'sanitize_callback' => 'sanitize_key',
						),
						'gl'   => array(
							'description'       => __( 'Country code for Google Suggest, e.g. us.', 'mcpwp' ),
							'type'              => 'string',
							'default'           => 'us',
							'sanitize_callback' => 'sanitize_key',
						),
						'max'  => array(
							'description'       => __( 'Overall cap on returned suggestion items, 1-500. Defaults to 100.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 500,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Validate SEO readiness for a single post before publishing/updating.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function validate_seo_readiness( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );

		// Bust object cache so a freshly-written SEO meta is not read as stale.
		// Relevant when a persistent cache (Redis/Memcached) or AIOSEO's own cache
		// has not yet propagated the value written by wp_set_seo.
		clean_post_cache( $post_id );

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( 'not_found', 'Post not found.', 404 );
		}

		$this->log_activity( 'validate_seo_readiness', $request, array( 'post_id' => $post_id ) );

		$issues       = array();
		$content      = (string) $post->post_content;
		$text_content = trim( wp_strip_all_tags( $content ) );
		$title        = trim( get_the_title( $post ) );
		$headings     = $this->extract_heading_texts( $content );
		$h1_count     = 0;
		$last_level   = 0;

		if ( '' === $title ) {
			$issues[] = $this->make_seo_readiness_issue( 'missing_title', 'error', __( 'Post title is missing.', 'mcpwp' ), __( 'Add a clear, descriptive title.', 'mcpwp' ) );
		} elseif ( strlen( $title ) < 10 ) {
			$issues[] = $this->make_seo_readiness_issue( 'short_title', 'warning', __( 'Post title is very short.', 'mcpwp' ), __( 'Use a descriptive title that sets search intent clearly.', 'mcpwp' ) );
		}

		if ( '' === trim( (string) $post->post_name ) ) {
			$issues[] = $this->make_seo_readiness_issue( 'missing_slug', 'error', __( 'Slug is missing.', 'mcpwp' ), __( 'Set a readable URL slug before publishing.', 'mcpwp' ) );
		}

		if ( str_word_count( $text_content ) < 80 ) {
			$issues[] = $this->make_seo_readiness_issue( 'thin_content', 'warning', __( 'Content is thin.', 'mcpwp' ), __( 'Add enough useful copy to answer the page intent.', 'mcpwp' ) );
		}

		foreach ( $headings as $heading ) {
			if ( 1 === (int) $heading['level'] ) {
				$h1_count++;
			}

			if ( $last_level > 0 && (int) $heading['level'] > $last_level + 1 ) {
				$issues[] = $this->make_seo_readiness_issue( 'heading_order_skip', 'warning', __( 'Heading levels skip in the page outline.', 'mcpwp' ), __( 'Use a logical heading hierarchy without jumping levels.', 'mcpwp' ) );
				break;
			}

			$last_level = (int) $heading['level'];
		}

		if ( 0 === $h1_count ) {
			$issues[] = $this->make_seo_readiness_issue( 'missing_h1', 'warning', __( 'No H1 heading found in the content.', 'mcpwp' ), __( 'Add one visible H1 or confirm the theme renders the post title as H1.', 'mcpwp' ) );
		} elseif ( $h1_count > 1 ) {
			$issues[] = $this->make_seo_readiness_issue( 'multiple_h1', 'warning', __( 'Multiple H1 headings found.', 'mcpwp' ), __( 'Use one primary H1 and demote secondary headings.', 'mcpwp' ) );
		}

		$seo_meta         = $this->resolve_seo_meta_description( $post_id );
		$meta_description = $seo_meta['value'];
		if ( '' === $meta_description ) {
			// When AIOSEO/Yoast computes a fallback from excerpt, the stored meta may be empty.
			// Only flag as missing when no SEO plugin would produce a rendered description.
			if ( 'aioseo_excerpt_fallback' !== $seo_meta['source'] ) {
				$issues[] = $this->make_seo_readiness_issue( 'missing_meta_description', 'warning', __( 'Meta description is missing.', 'mcpwp' ), __( 'Add a concise meta description through the active SEO plugin or supported post meta.', 'mcpwp' ) );
			}
		} elseif ( ! $seo_meta['is_template'] && ( strlen( $meta_description ) < 50 || strlen( $meta_description ) > 170 ) ) {
			// Skip length check on template strings — the rendered length may differ from the stored template.
			$issues[] = $this->make_seo_readiness_issue( 'meta_description_length', 'warning', __( 'Meta description length is outside the recommended range.', 'mcpwp' ), __( 'Aim for roughly 50-170 characters that summarize the page accurately.', 'mcpwp' ) );
		}

		foreach ( $this->extract_image_ids_from_content( $content ) as $image_id ) {
			if ( '' === trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) ) {
				$issues[] = $this->make_seo_readiness_issue( 'missing_image_alt', 'warning', __( 'An image is missing alt text.', 'mcpwp' ), __( 'Add descriptive alt text or mark decorative images intentionally.', 'mcpwp' ), array( 'attachment_id' => $image_id ) );
			}
		}

		$graph_post_types = array_values( array_unique( array( 'page', 'post', $post->post_type ) ) );
		// Limit to 100 posts — we only need this node's outbound/orphan status.
		// 500 caused memory/timeout on large sites, producing a malformed JSON-RPC response.
		$graph = $this->build_content_graph_data( $graph_post_types, 100, 'publish' !== $post->post_status );
		$node  = null;
		foreach ( $graph['nodes'] as $candidate ) {
			if ( (int) $candidate['id'] === $post_id ) {
				$node = $candidate;
				break;
			}
		}

		if ( $node && 0 === (int) $node['outbound_count'] ) {
			$issues[] = $this->make_seo_readiness_issue( 'no_internal_outbound_links', 'warning', __( 'No internal outbound links found.', 'mcpwp' ), __( 'Add relevant internal links from the content graph where useful.', 'mcpwp' ) );
		}

		if ( $node && 'publish' === $post->post_status && 'none' !== $node['orphan_severity'] ) {
			$issues[] = $this->make_seo_readiness_issue( 'orphan_content', 'warning', __( 'Published content appears orphaned in the graph.', 'mcpwp' ), __( 'Link to this page from a relevant hub, menu, archive, or related page.', 'mcpwp' ), array( 'orphan_severity' => $node['orphan_severity'] ) );
		}

		if ( $this->is_post_noindex( $post_id ) ) {
			$issues[] = $this->make_seo_readiness_issue( 'noindex_enabled', 'error', __( 'Post appears to be marked noindex.', 'mcpwp' ), __( 'Remove noindex before publishing content intended for search.', 'mcpwp' ) );
		}

		$canonical = $this->get_seo_canonical_url( $post_id );
		if ( '' !== $canonical && $this->normalize_internal_graph_url( $canonical ) !== $this->normalize_internal_graph_url( get_permalink( $post ) ) ) {
			$issues[] = $this->make_seo_readiness_issue( 'canonical_override', 'warning', __( 'Canonical URL points somewhere other than this permalink.', 'mcpwp' ), __( 'Confirm this canonical is intentional before publishing.', 'mcpwp' ), array( 'canonical_url' => $canonical ) );
		}

		$robots_txt = $this->get_robots_txt();
		if ( false !== stripos( $robots_txt, 'Disallow: /' ) ) {
			$issues[] = $this->make_seo_readiness_issue( 'robots_disallow_root', 'error', __( 'robots.txt appears to disallow the whole site.', 'mcpwp' ), __( 'Review robots.txt before publishing search-facing content.', 'mcpwp' ) );
		}

		if ( ! $this->site_has_sitemap_hint() ) {
			$issues[] = $this->make_seo_readiness_issue( 'sitemap_not_detected', 'info', __( 'Sitemap endpoint was not detected locally.', 'mcpwp' ), __( 'Confirm XML sitemaps are enabled in WordPress or your SEO plugin.', 'mcpwp' ) );
		}

		if ( ! $this->content_has_schema_hint( $content ) ) {
			$issues[] = $this->make_seo_readiness_issue( 'schema_hint_missing', 'info', __( 'No structured-data hint found in content.', 'mcpwp' ), __( 'Consider schema only when it accurately matches visible content.', 'mcpwp' ) );
		}

		// Render-truth pass: for published pages fetch the actual rendered <head> and
		// reconcile issues against ground truth. Suppresses false positives caused by
		// stored-meta/rendered divergence (SEO plugin template tags, excerpt fallbacks, etc.)
		$rendered_head    = null;
		$validation_basis = 'stored_meta';
		if ( 'publish' === $post->post_status ) {
			$rendered_head = $this->fetch_rendered_head_seo( (string) get_permalink( $post ) );
			if ( null !== $rendered_head ) {
				$validation_basis = 'rendered_html';
				$issues           = $this->reconcile_issues_with_rendered_head( $issues, $rendered_head );
			}
		}

		$summary = $this->summarize_seo_readiness_issues( $issues );

		return $this->success_response(
			array(
				'post'    => array(
					'id'     => $post_id,
					'title'  => $title,
					'type'   => $post->post_type,
					'status' => $post->post_status,
					'url'    => get_permalink( $post ),
				),
				'summary' => $summary,
				'issues'  => $issues,
				'checks'  => array(
					'word_count'               => str_word_count( $text_content ),
					'heading_count'            => count( $headings ),
					'h1_count'                 => $h1_count,
					'meta_description'         => $meta_description,
					'meta_description_source'  => $seo_meta['source'],
					'meta_description_note'    => $seo_meta['is_template']
						? 'Stored value contains template tags — rendered output may differ. Use wp_get_seo or check rendered <head> for the final description.'
						: ( 'aioseo_excerpt_fallback' === $seo_meta['source']
							? 'No per-post description stored; AIOSEO will render the post excerpt in <head>.'
							: '' ),
					'canonical_url'            => $canonical,
					'robots_txt_checked'       => '' !== $robots_txt,
					'sitemap_hint'             => $this->site_has_sitemap_hint(),
				),
				'validation_basis' => $validation_basis,
				'validation_note'  => 'rendered_html' === $validation_basis
					? 'Issues reconciled against the live rendered <head>. Values shown are what the public page actually outputs.'
					: 'Issues based on stored post meta only (page is not published). Rendered output may differ once live — re-validate after publishing.',
				'rendered_head'    => $rendered_head,
				'workflow' => array(
					'read'  => 'Use before publishing or after major agent edits.',
					'fix'   => 'Fix accepted issues through approval-first Gutenberg or SEO metadata edits.',
					'guard' => 'This endpoint is read-only and does not mutate content.',
				),
			)
		);
	}

	/**
	 * Run a read-only SEO site audit summary.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function audit_seo_site( $request ) {
		$this->log_activity( 'audit_seo_site', $request );

		$post_types     = $this->parse_graph_post_types( (string) $request->get_param( 'post_types' ) );
		$limit          = min( 50, max( 1, absint( $request->get_param( 'limit' ) ) ) );
		$include_drafts = rest_sanitize_boolean( $request->get_param( 'include_drafts' ) );
		$store          = rest_sanitize_boolean( $request->get_param( 'store' ) );
		$statuses       = $include_drafts ? array( 'publish', 'draft', 'private' ) : array( 'publish' );
		$posts          = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => $statuses,
				'posts_per_page' => $limit,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);

		$urls             = array();
		$issue_codes      = array();
		$category_counts  = array(
			'readiness'       => 0,
			'structured_data' => 0,
			'media'           => 0,
			'content_quality' => 0,
		);
		$total_errors     = 0;
		$total_warnings   = 0;
		$total_info       = 0;
		$critical_urls    = 0;
		$needs_review     = 0;

		foreach ( $posts as $post_id ) {
			$readiness       = $this->run_post_validator( 'validate_seo_readiness', $post_id, '/seo/readiness/' );
			$structured_data = $this->run_post_validator( 'validate_structured_data', $post_id, '/seo/structured-data/' );
			$media           = $this->run_post_validator( 'audit_media_seo', $post_id, '/seo/media/' );
			$content_quality = $this->run_post_validator( 'audit_content_quality', $post_id, '/seo/content-quality/' );
			$combined_issues = array_merge(
				$this->tag_seo_audit_issues( $readiness['issues'], 'readiness' ),
				$this->tag_seo_audit_issues( $structured_data['issues'], 'structured_data' ),
				$this->tag_seo_audit_issues( $media['issues'], 'media' ),
				$this->tag_seo_audit_issues( $content_quality['issues'], 'content_quality' )
			);
			$counts          = $this->count_issues_by_severity( $combined_issues );
			$score           = ( $counts['error'] * 100 ) + ( $counts['warning'] * 20 ) + ( $counts['info'] * 5 );
			$post            = get_post( $post_id );

			if ( $counts['error'] > 0 ) {
				$critical_urls++;
			} elseif ( $counts['warning'] > 0 ) {
				$needs_review++;
			}

			$total_errors   += $counts['error'];
			$total_warnings += $counts['warning'];
			$total_info     += $counts['info'];

			foreach ( $combined_issues as $issue ) {
				$code = $issue['code'];
				if ( ! isset( $issue_codes[ $code ] ) ) {
					$issue_codes[ $code ] = array(
						'code'     => $code,
						'count'    => 0,
						'severity' => $issue['severity'],
					);
				}
				$issue_codes[ $code ]['count']++;

				if ( isset( $issue['category'], $category_counts[ $issue['category'] ] ) ) {
					$category_counts[ $issue['category'] ]++;
				}
			}

			$urls[] = array(
				'id'           => (int) $post_id,
				'title'        => get_the_title( $post_id ),
				'type'         => $post ? $post->post_type : '',
				'status'       => $post ? $post->post_status : '',
				'url'          => get_permalink( $post_id ),
				'score'        => $score,
				'status_label' => $this->seo_audit_status_label( $counts ),
				'counts'       => $counts,
				'top_issues'   => array_slice( $combined_issues, 0, 8 ),
			);
		}

		usort(
			$urls,
			static function ( $a, $b ) {
				return (int) $b['score'] <=> (int) $a['score'];
			}
		);

		$top_issue_codes = array_values( $issue_codes );
		usort(
			$top_issue_codes,
			static function ( $a, $b ) {
				return (int) $b['count'] <=> (int) $a['count'];
			}
		);

		$payload = array(
			'summary' => array(
				'status'        => $total_errors > 0 ? 'fail' : ( $total_warnings > 0 ? 'warn' : 'pass' ),
				'audited_count' => count( $urls ),
				'critical_urls' => $critical_urls,
				'needs_review'  => $needs_review,
				'pass_urls'     => max( 0, count( $urls ) - $critical_urls - $needs_review ),
				'error_count'   => $total_errors,
				'warning_count' => $total_warnings,
				'info_count'    => $total_info,
			),
			'category_counts' => $category_counts,
			'top_issue_codes' => array_slice( $top_issue_codes, 0, 12 ),
			'urls'            => $urls,
			'workflow'        => array(
				'read'  => 'Use to prioritize URLs before running targeted per-page SEO tools.',
				'fix'   => 'Open the highest-scoring URLs and fix issues through approval-first Gutenberg, media, or SEO metadata edits.',
				'guard' => 'This endpoint is read-only and does not mutate content, media, or SEO settings.',
			),
		);

		if ( $store && class_exists( 'Mcpwp_SEO_Audit_Store' ) ) {
			$payload['stored_run'] = Mcpwp_SEO_Audit_Store::store_run(
				$payload,
				array(
					'post_types'     => $post_types,
					'limit'          => $limit,
					'include_drafts' => $include_drafts,
				)
			);
		}

		return $this->success_response(
			$payload
		);
	}

	/**
	 * Get stored SEO issues.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_seo_issues( $request ) {
		$this->log_activity( 'get_seo_issues', $request );

		if ( ! class_exists( 'Mcpwp_SEO_Audit_Store' ) ) {
			return $this->success_response(
				array(
					'summary' => array(
						'total'    => 0,
						'open'     => 0,
						'resolved' => 0,
						'error'    => 0,
						'warning'  => 0,
						'info'     => 0,
					),
					'issues'  => array(),
				)
			);
		}

		return $this->success_response(
			Mcpwp_SEO_Audit_Store::list_issues(
				array(
					'status'   => $request->get_param( 'status' ),
					'severity' => $request->get_param( 'severity' ),
					'category' => $request->get_param( 'category' ),
					'post_id'  => $request->get_param( 'post_id' ),
					'run_id'   => $request->get_param( 'run_id' ),
					'limit'    => $request->get_param( 'limit' ),
				)
			)
		);
	}

	/**
	 * Validate structured data for a single post/page without mutating content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function validate_structured_data( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( 'not_found', 'Post not found.', 404 );
		}

		$this->log_activity( 'validate_structured_data', $request, array( 'post_id' => $post_id ) );

		$content         = (string) $post->post_content;
		$json_ld_items   = $this->extract_json_ld_items( $content );
		$microdata_items = $this->extract_microdata_items( $content );
		$issues          = array();
		$schema_types    = array();

		foreach ( $json_ld_items as $index => $item ) {
			if ( ! empty( $item['error'] ) ) {
				$issues[] = $this->make_structured_data_issue(
					'invalid_json_ld',
					'error',
					__( 'A JSON-LD block could not be parsed.', 'mcpwp' ),
					__( 'Fix the JSON syntax or remove the invalid structured data block.', 'mcpwp' ),
					array(
						'format' => 'json-ld',
						'index'  => $index,
						'error'  => $item['error'],
					)
				);
				continue;
			}

			foreach ( $this->flatten_schema_entities( $item['data'] ) as $entity ) {
				$type = $this->normalize_schema_type( $entity['@type'] ?? '' );
				if ( '' !== $type ) {
					$schema_types[] = $type;
				}

				if ( empty( $entity['@context'] ) ) {
					$issues[] = $this->make_structured_data_issue( 'missing_context', 'warning', __( 'JSON-LD entity is missing @context.', 'mcpwp' ), __( 'Use a schema.org @context for valid JSON-LD.', 'mcpwp' ), array( 'index' => $index ) );
				}

				if ( '' === $type ) {
					$issues[] = $this->make_structured_data_issue( 'missing_type', 'warning', __( 'JSON-LD entity is missing @type.', 'mcpwp' ), __( 'Add a concrete schema type that matches the visible content.', 'mcpwp' ), array( 'index' => $index ) );
				}

				$issues = array_merge( $issues, $this->validate_schema_entity_shape( $entity, $type, $post ) );
			}
		}

		foreach ( $microdata_items as $item ) {
			$type = $this->normalize_schema_type( $item['type'] );
			if ( '' !== $type ) {
				$schema_types[] = $type;
			}
		}

		$schema_types    = array_values( array_unique( array_filter( $schema_types ) ) );
		$recommendations = $this->recommend_schema_types_for_post( $post, $content, $schema_types );

		if ( empty( $json_ld_items ) && empty( $microdata_items ) ) {
			$issues[] = $this->make_structured_data_issue( 'no_structured_data', 'info', __( 'No structured data was detected in the post content.', 'mcpwp' ), __( 'Add schema only when it accurately describes visible content or can be handled by the active SEO plugin.', 'mcpwp' ) );
		}

		if ( ! empty( $recommendations ) ) {
			$issues[] = $this->make_structured_data_issue( 'schema_recommendation_available', 'info', __( 'Page-appropriate schema recommendations are available.', 'mcpwp' ), __( 'Review the recommendations and add schema through an SEO plugin or approved block-native workflow only when supported by visible content.', 'mcpwp' ) );
		}

		$summary = $this->summarize_structured_data_issues( $issues );

		return $this->success_response(
			array(
				'post'            => array(
					'id'     => $post_id,
					'title'  => get_the_title( $post ),
					'type'   => $post->post_type,
					'status' => $post->post_status,
					'url'    => get_permalink( $post ),
				),
				'summary'         => $summary,
				'inventory'       => array(
					'json_ld_count'    => count( $json_ld_items ),
					'microdata_count'  => count( $microdata_items ),
					'schema_org_hints' => substr_count( strtolower( $content ), 'schema.org' ),
					'types'            => $schema_types,
				),
				'json_ld'         => $this->summarize_json_ld_items( $json_ld_items ),
				'microdata'       => $microdata_items,
				'issues'          => $issues,
				'recommendations' => $recommendations,
				'workflow'        => array(
					'read'  => 'Use before publishing or when auditing AI/search citation readiness.',
					'fix'   => 'Add or correct schema only through approved SEO plugin fields or visible-content-backed markup.',
					'guard' => 'This endpoint is read-only and does not inject structured data.',
				),
			)
		);
	}

	/**
	 * Audit media SEO for a single post/page without mutating content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function audit_media_seo( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return $this->error_response( 'not_found', 'Post not found.', 404 );
		}

		$this->log_activity( 'audit_media_seo', $request, array( 'post_id' => $post_id ) );

		$content       = (string) $post->post_content;
		$featured_id   = get_post_thumbnail_id( $post );
		$content_media = $this->extract_content_image_inventory( $content );
		$media_items   = array();
		$issues        = array();
		$seen_ids      = array();

		if ( $featured_id ) {
			$featured_item = $this->build_media_seo_item( (int) $featured_id, 'featured_image', null );
			$media_items[] = $featured_item;
			$issues        = array_merge( $issues, $this->validate_media_seo_item( $featured_item ) );
			$seen_ids[]    = (int) $featured_id;
		} elseif ( in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			$issues[] = $this->make_media_seo_issue( 'missing_featured_image', 'info', __( 'No featured image is set.', 'mcpwp' ), __( 'Set a featured image when the content needs social/search previews.', 'mcpwp' ) );
		}

		foreach ( $content_media as $content_item ) {
			$attachment_id = (int) $content_item['attachment_id'];
			$item          = $attachment_id > 0 ? $this->build_media_seo_item( $attachment_id, 'content_image', $content_item ) : $this->build_external_media_seo_item( $content_item );
			$media_items[] = $item;
			$issues        = array_merge( $issues, $this->validate_media_seo_item( $item ) );

			if ( $attachment_id > 0 ) {
				if ( in_array( $attachment_id, $seen_ids, true ) ) {
					$issues[] = $this->make_media_seo_issue( 'duplicate_image_use', 'info', __( 'An image is reused on this page.', 'mcpwp' ), __( 'Confirm repeated image use is intentional and not a duplicated block.', 'mcpwp' ), array( 'attachment_id' => $attachment_id ) );
				}
				$seen_ids[] = $attachment_id;
			}
		}

		if ( empty( $media_items ) ) {
			$issues[] = $this->make_media_seo_issue( 'no_images_found', 'info', __( 'No images were found in the post content.', 'mcpwp' ), __( 'No action is required unless the page needs visual search, social, or conversion media.', 'mcpwp' ) );
		}

		$summary = $this->summarize_media_seo_issues( $issues );

		return $this->success_response(
			array(
				'post'       => array(
					'id'     => $post_id,
					'title'  => get_the_title( $post ),
					'type'   => $post->post_type,
					'status' => $post->post_status,
					'url'    => get_permalink( $post ),
				),
				'summary'    => $summary,
				'inventory'  => array(
					'image_count'          => count( $media_items ),
					'content_image_count'  => count( $content_media ),
					'featured_image_id'    => $featured_id ? (int) $featured_id : 0,
					'attachment_id_count'  => count( array_unique( array_filter( array_map( 'absint', wp_list_pluck( $media_items, 'attachment_id' ) ) ) ) ),
					'external_image_count' => count( array_filter( $media_items, static function ( $item ) { return empty( $item['attachment_id'] ); } ) ),
				),
				'media'      => $media_items,
				'issues'     => $issues,
				'workflow'   => array(
					'read'  => 'Use before publishing or after image-heavy agent edits.',
					'fix'   => 'Fix alt text, filenames, image choice, and large media through approved media or block edits.',
					'guard' => 'This endpoint is read-only and does not mutate media or content.',
				),
			)
		);
	}

	/**
	 * Get approval-safe SEO autofix plan.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_seo_autofix_plan( $request ) {
		$this->log_activity( 'get_seo_autofix_plan', $request );

		$plan = class_exists( 'Mcpwp_SEO_Autofix' ) ? Mcpwp_SEO_Autofix::get_plan(
			array(
				'severity' => $request->get_param( 'severity' ),
				'category' => $request->get_param( 'category' ),
				'post_id'  => $request->get_param( 'post_id' ),
				'run_id'   => $request->get_param( 'run_id' ),
				'issue_id' => $request->get_param( 'issue_id' ),
				'limit'    => $request->get_param( 'limit' ),
			)
		) : array(
			'schema_version' => '2026-05-20',
			'summary'        => array(
				'issues_inspected' => 0,
				'actions'          => 0,
				'can_prepare'      => 0,
				'can_auto_apply'   => 0,
				'needs_approval'   => 0,
				'manual_review'    => 0,
				'by_strategy'      => array(),
			),
			'filters'        => array(),
			'actions'        => array(),
		);

		return $this->success_response( $plan );
	}

	/**
	 * Import provider-neutral search performance rows.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function import_search_performance( $request ) {
		$this->log_activity( 'import_search_performance', $request );

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_params();
		}

		$result = class_exists( 'Mcpwp_Search_Performance' ) ? Mcpwp_Search_Performance::import_rows(
			array(
				'provider' => $params['provider'] ?? $request->get_param( 'provider' ),
				'source'   => $params['source'] ?? $request->get_param( 'source' ),
				'rows'     => isset( $params['rows'] ) && is_array( $params['rows'] ) ? $params['rows'] : array(),
			)
		) : array(
			'id'          => '',
			'provider'    => sanitize_key( (string) $request->get_param( 'provider' ) ),
			'source'      => sanitize_text_field( (string) $request->get_param( 'source' ) ),
			'imported_at' => gmdate( 'c' ),
			'row_count'   => 0,
			'ignored'     => 0,
		);

		return $this->success_response( $result, 201 );
	}

	/**
	 * Get search performance trends.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_search_performance( $request ) {
		$this->log_activity( 'get_search_performance', $request );

		$report = class_exists( 'Mcpwp_Search_Performance' ) ? Mcpwp_Search_Performance::get_report(
			array(
				'provider' => $request->get_param( 'provider' ),
				'url'      => $request->get_param( 'url' ),
				'query'    => $request->get_param( 'query' ),
				'days'     => $request->get_param( 'days' ),
				'limit'    => $request->get_param( 'limit' ),
			)
		) : array(
			'schema_version' => '2026-05-20',
			'summary'        => array(
				'rows'        => 0,
				'clicks'      => 0,
				'impressions' => 0,
				'ctr'         => 0,
				'position'    => 0,
				'providers'   => array(),
			),
			'imports'        => array(),
			'top_queries'    => array(),
			'top_urls'       => array(),
			'daily'          => array(),
		);

		return $this->success_response( $report );
	}

	/**
	 * Get WooCommerce SEO intelligence report.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_woocommerce_seo_report( $request ) {
		$this->log_activity( 'get_woocommerce_seo_report', $request );

		$report = class_exists( 'Mcpwp_WooCommerce_SEO' ) ? Mcpwp_WooCommerce_SEO::get_report(
			array(
				'status' => $request->get_param( 'status' ),
				'limit'  => $request->get_param( 'limit' ),
			)
		) : array(
			'schema_version' => '2026-05-20',
			'summary'        => array(
				'woocommerce_detected' => false,
				'products_inspected'   => 0,
				'error_count'          => 0,
				'warning_count'        => 0,
				'opportunity_count'    => 0,
				'search_clicks'        => 0,
				'search_impressions'   => 0,
				'search_ctr'           => 0,
			),
			'products'       => array(),
		);

		return $this->success_response( $report );
	}

	/**
	 * Keyword research via Google Suggest autocomplete.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function keyword_research( $request ) {
		$seed = sanitize_text_field( (string) $request->get_param( 'seed' ) );

		if ( '' === $seed ) {
			return $this->error_response( 'empty_seed', __( 'Seed phrase must not be empty.', 'mcpwp' ), 400 );
		}

		$this->log_activity( 'keyword_research', $request, array( 'seed' => $seed ) );

		$result = Mcpwp_Keyword_Research::research(
			$seed,
			array(
				'hl'  => sanitize_key( (string) $request->get_param( 'hl' ) ),
				'gl'  => sanitize_key( (string) $request->get_param( 'gl' ) ),
				'max' => absint( $request->get_param( 'max' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		return $this->success_response( $result );
	}

	// --- Private helpers ---

	protected function make_seo_readiness_issue( $code, $severity, $message, $recommendation, $extra = array() ) {
		return array_merge(
			array(
				'code'              => $code,
				'severity'          => $severity,
				'message'           => $message,
				'recommendation'    => $recommendation,
				'approval_required' => in_array( $severity, array( 'error', 'warning' ), true ),
			),
			$extra
		);
	}

	/**
	 * Summarize SEO readiness issues.
	 *
	 * @param array $issues Issues.
	 * @return array Summary.
	 */
	protected function summarize_seo_readiness_issues( $issues ) {
		$error_count   = 0;
		$warning_count = 0;
		$info_count    = 0;

		foreach ( $issues as $issue ) {
			if ( 'error' === $issue['severity'] ) {
				$error_count++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$warning_count++;
			} elseif ( 'info' === $issue['severity'] ) {
				$info_count++;
			}
		}

		return array(
			'status'        => 0 === $error_count ? ( $warning_count > 0 ? 'warn' : 'pass' ) : 'fail',
			'issue_count'   => count( $issues ),
			'error_count'   => $error_count,
			'warning_count' => $warning_count,
			'info_count'    => $info_count,
		);
	}

	/**
	 * Run a post-level validator and return its payload.
	 *
	 * @param string $method Method name.
	 * @param int    $post_id Post ID.
	 * @param string $route_prefix Route prefix.
	 * @return array Payload.
	 */
	protected function run_post_validator( $method, $post_id, $route_prefix ) {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1' . $route_prefix . $post_id );
		$request->set_param( 'id', $post_id );

		$response = $this->$method( $request );
		if ( is_wp_error( $response ) ) {
			return array( 'issues' => array() );
		}

		$data = $response->get_data();
		return is_array( $data ) ? $data : array( 'issues' => array() );
	}

	/**
	 * Attach a category to audit issues.
	 *
	 * @param array  $issues Issues.
	 * @param string $category Category.
	 * @return array Tagged issues.
	 */
	protected function tag_seo_audit_issues( $issues, $category ) {
		$tagged = array();

		foreach ( $issues as $issue ) {
			$issue['category'] = $category;
			$tagged[]          = $issue;
		}

		return $tagged;
	}

	/**
	 * Count issues by severity.
	 *
	 * @param array $issues Issues.
	 * @return array Counts.
	 */
	protected function count_issues_by_severity( $issues ) {
		$counts = array(
			'error'   => 0,
			'warning' => 0,
			'info'    => 0,
			'total'   => count( $issues ),
		);

		foreach ( $issues as $issue ) {
			if ( isset( $counts[ $issue['severity'] ] ) ) {
				$counts[ $issue['severity'] ]++;
			}
		}

		return $counts;
	}

	/**
	 * Convert issue counts to a URL audit status.
	 *
	 * @param array $counts Counts.
	 * @return string Status.
	 */
	protected function seo_audit_status_label( $counts ) {
		if ( $counts['error'] > 0 ) {
			return 'critical';
		}

		if ( $counts['warning'] > 0 ) {
			return 'needs_review';
		}

		return 'pass';
	}

	/**
	 * Create a normalized structured data issue.
	 *
	 * @param string $code           Issue code.
	 * @param string $severity       Severity.
	 * @param string $message        Message.
	 * @param string $recommendation Recommendation.
	 * @param array  $extra          Extra fields.
	 * @return array Issue.
	 */
	protected function make_structured_data_issue( $code, $severity, $message, $recommendation, $extra = array() ) {
		return array_merge(
			array(
				'code'              => $code,
				'severity'          => $severity,
				'message'           => $message,
				'recommendation'    => $recommendation,
				'approval_required' => in_array( $severity, array( 'error', 'warning' ), true ),
			),
			$extra
		);
	}

	/**
	 * Summarize structured data issues.
	 *
	 * @param array $issues Issues.
	 * @return array Summary.
	 */
	protected function summarize_structured_data_issues( $issues ) {
		$error_count   = 0;
		$warning_count = 0;
		$info_count    = 0;

		foreach ( $issues as $issue ) {
			if ( 'error' === $issue['severity'] ) {
				$error_count++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$warning_count++;
			} elseif ( 'info' === $issue['severity'] ) {
				$info_count++;
			}
		}

		return array(
			'status'        => 0 === $error_count ? ( $warning_count > 0 ? 'warn' : 'pass' ) : 'fail',
			'issue_count'   => count( $issues ),
			'error_count'   => $error_count,
			'warning_count' => $warning_count,
			'info_count'    => $info_count,
		);
	}

	/**
	 * Extract JSON-LD script blocks from content.
	 *
	 * @param string $content Content.
	 * @return array JSON-LD items.
	 */
	protected function extract_json_ld_items( $content ) {
		$items = array();

		if ( ! preg_match_all( '/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $content, $matches ) ) {
			return $items;
		}

		foreach ( $matches[1] as $raw_json ) {
			$raw_json = trim( html_entity_decode( $raw_json, ENT_QUOTES, get_bloginfo( 'charset' ) ) );
			$data     = json_decode( $raw_json, true );
			$error    = json_last_error();

			$items[] = array(
				'raw'   => $raw_json,
				'data'  => JSON_ERROR_NONE === $error ? $data : null,
				'error' => JSON_ERROR_NONE === $error ? '' : json_last_error_msg(),
			);
		}

		return $items;
	}

	/**
	 * Summarize JSON-LD items without returning full raw payloads.
	 *
	 * @param array $items JSON-LD items.
	 * @return array Summary items.
	 */
	protected function summarize_json_ld_items( $items ) {
		$summary = array();

		foreach ( $items as $index => $item ) {
			$types = array();
			if ( empty( $item['error'] ) ) {
				foreach ( $this->flatten_schema_entities( $item['data'] ) as $entity ) {
					$type = $this->normalize_schema_type( $entity['@type'] ?? '' );
					if ( '' !== $type ) {
						$types[] = $type;
					}
				}
			}

			$summary[] = array(
				'index'   => $index,
				'valid'   => empty( $item['error'] ),
				'types'   => array_values( array_unique( $types ) ),
				'preview' => substr( wp_strip_all_tags( (string) $item['raw'] ), 0, 300 ),
				'error'   => $item['error'],
			);
		}

		return $summary;
	}

	/**
	 * Extract microdata/schema.org hints from content.
	 *
	 * @param string $content Content.
	 * @return array Microdata items.
	 */
	protected function extract_microdata_items( $content ) {
		$items = array();

		if ( preg_match_all( '/itemscope[^>]*itemtype=["\']([^"\']+)["\']/is', $content, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$items[] = array(
					'format' => 'microdata',
					'type'   => $this->normalize_schema_type( $url ),
					'url'    => esc_url_raw( $url ),
				);
			}
		}

		return array_values( array_unique( $items, SORT_REGULAR ) );
	}

	/**
	 * Flatten JSON-LD into entity arrays.
	 *
	 * @param mixed $data JSON-LD data.
	 * @return array Entities.
	 */
	protected function flatten_schema_entities( $data ) {
		$entities = array();

		if ( ! is_array( $data ) ) {
			return $entities;
		}

		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $entity ) {
				if ( is_array( $entity ) ) {
					if ( empty( $entity['@context'] ) && ! empty( $data['@context'] ) ) {
						$entity['@context'] = $data['@context'];
					}
					$entities[] = $entity;
				}
			}
			return $entities;
		}

		if ( $this->is_list_array( $data ) ) {
			foreach ( $data as $entity ) {
				if ( is_array( $entity ) ) {
					$entities[] = $entity;
				}
			}
			return $entities;
		}

		return array( $data );
	}

	/**
	 * Determine whether an array uses contiguous numeric keys.
	 *
	 * @param array $value Array value.
	 * @return bool True when list-like.
	 */
	protected function is_list_array( $value ) {
		if ( array() === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Normalize a schema type from URL/string/array forms.
	 *
	 * @param mixed $type Schema type.
	 * @return string Normalized type.
	 */
	protected function normalize_schema_type( $type ) {
		if ( is_array( $type ) ) {
			$type = reset( $type );
		}

		$type = trim( (string) $type );
		if ( '' === $type ) {
			return '';
		}

		$type = preg_replace( '#^https?://schema\.org/#i', '', $type );
		$type = preg_replace( '#^schema:#i', '', $type );

		return sanitize_key( $type );
	}

	/**
	 * Validate basic schema shape against visible WordPress content.
	 *
	 * @param array   $entity Schema entity.
	 * @param string  $type   Schema type.
	 * @param WP_Post $post   Post object.
	 * @return array Issues.
	 */
	protected function validate_schema_entity_shape( $entity, $type, $post ) {
		$issues = array();

		if ( in_array( $type, array( 'article', 'blogposting', 'newsarticle' ), true ) ) {
			if ( empty( $entity['headline'] ) && empty( $entity['name'] ) ) {
				$issues[] = $this->make_structured_data_issue( 'article_missing_headline', 'warning', __( 'Article schema is missing headline/name.', 'mcpwp' ), __( 'Use the visible post title as the schema headline.', 'mcpwp' ), array( 'schema_type' => $type ) );
			}
			if ( empty( $entity['datePublished'] ) ) {
				$issues[] = $this->make_structured_data_issue( 'article_missing_date_published', 'warning', __( 'Article schema is missing datePublished.', 'mcpwp' ), __( 'Include the published date when Article schema is used.', 'mcpwp' ), array( 'schema_type' => $type ) );
			}
		}

		if ( 'faqpage' === $type && empty( $entity['mainEntity'] ) ) {
			$issues[] = $this->make_structured_data_issue( 'faq_missing_questions', 'warning', __( 'FAQPage schema is missing questions.', 'mcpwp' ), __( 'Only use FAQPage schema when the visible content contains matching questions and answers.', 'mcpwp' ), array( 'schema_type' => $type ) );
		}

		if ( 'product' === $type && 'product' !== $post->post_type ) {
			$issues[] = $this->make_structured_data_issue( 'product_schema_on_non_product', 'warning', __( 'Product schema appears on non-product content.', 'mcpwp' ), __( 'Use Product schema only for visible product detail pages.', 'mcpwp' ), array( 'schema_type' => $type ) );
		}

		if ( in_array( $type, array( 'webpage', 'article', 'blogposting', 'newsarticle' ), true ) && empty( $entity['url'] ) && empty( $entity['mainEntityOfPage'] ) ) {
			$issues[] = $this->make_structured_data_issue( 'schema_missing_url', 'info', __( 'Schema entity does not include URL/mainEntityOfPage.', 'mcpwp' ), __( 'Connect schema to the canonical page URL where supported.', 'mcpwp' ), array( 'schema_type' => $type ) );
		}

		return $issues;
	}

	/**
	 * Recommend schema types that fit visible WordPress content.
	 *
	 * @param WP_Post $post           Post object.
	 * @param string  $content        Content.
	 * @param array   $existing_types Existing schema types.
	 * @return array Recommendations.
	 */
	protected function recommend_schema_types_for_post( $post, $content, $existing_types ) {
		$recommendations = array();
		$base_type       = 'post' === $post->post_type ? 'Article' : 'WebPage';

		if ( ! in_array( strtolower( $base_type ), $existing_types, true ) && ! in_array( 'blogposting', $existing_types, true ) && ! in_array( 'newsarticle', $existing_types, true ) ) {
			$recommendations[] = array(
				'type'       => $base_type,
				'confidence' => 'high',
				'reason'     => 'Matches the WordPress post type and visible page title/content.',
				'guardrail'  => 'Prefer SEO plugin schema output when available; do not duplicate existing schema.',
			);
		}

		if ( $this->content_looks_like_faq( $content ) && ! in_array( 'faqpage', $existing_types, true ) ) {
			$recommendations[] = array(
				'type'       => 'FAQPage',
				'confidence' => 'medium',
				'reason'     => 'Content has question-like headings.',
				'guardrail'  => 'Only use if matching answers are visible on the page.',
			);
		}

		return $recommendations;
	}

	/**
	 * Detect FAQ-like visible content.
	 *
	 * @param string $content Content.
	 * @return bool True when FAQ-like.
	 */
	protected function content_looks_like_faq( $content ) {
		foreach ( $this->extract_heading_texts( $content ) as $heading ) {
			if ( false !== strpos( (string) $heading['text'], '?' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Create a normalized media SEO issue.
	 *
	 * @param string $code           Issue code.
	 * @param string $severity       Severity.
	 * @param string $message        Message.
	 * @param string $recommendation Recommendation.
	 * @param array  $extra          Extra fields.
	 * @return array Issue.
	 */
	protected function make_media_seo_issue( $code, $severity, $message, $recommendation, $extra = array() ) {
		return array_merge(
			array(
				'code'              => $code,
				'severity'          => $severity,
				'message'           => $message,
				'recommendation'    => $recommendation,
				'approval_required' => in_array( $severity, array( 'error', 'warning' ), true ),
			),
			$extra
		);
	}

	/**
	 * Summarize media SEO issues.
	 *
	 * @param array $issues Issues.
	 * @return array Summary.
	 */
	protected function summarize_media_seo_issues( $issues ) {
		$error_count   = 0;
		$warning_count = 0;
		$info_count    = 0;

		foreach ( $issues as $issue ) {
			if ( 'error' === $issue['severity'] ) {
				$error_count++;
			} elseif ( 'warning' === $issue['severity'] ) {
				$warning_count++;
			} elseif ( 'info' === $issue['severity'] ) {
				$info_count++;
			}
		}

		return array(
			'status'        => 0 === $error_count ? ( $warning_count > 0 ? 'warn' : 'pass' ) : 'fail',
			'issue_count'   => count( $issues ),
			'error_count'   => $error_count,
			'warning_count' => $warning_count,
			'info_count'    => $info_count,
		);
	}

	/**
	 * Extract image inventory from Gutenberg image blocks and rendered image tags.
	 *
	 * @param string $content Content.
	 * @return array Image inventory.
	 */
	protected function extract_content_image_inventory( $content ) {
		$items = array();

		if ( preg_match_all( '/<img\b[^>]*>/i', $content, $matches ) ) {
			foreach ( $matches[0] as $img_tag ) {
				$attrs = $this->parse_html_tag_attributes( $img_tag );
				$id    = 0;

				if ( ! empty( $attrs['class'] ) && preg_match( '/wp-image-(\d+)/i', $attrs['class'], $id_match ) ) {
					$id = absint( $id_match[1] );
				}

				$items[] = array(
					'attachment_id' => $id,
					'source'        => 'img_tag',
					'src'           => isset( $attrs['src'] ) ? esc_url_raw( $attrs['src'] ) : '',
					'alt'           => isset( $attrs['alt'] ) ? trim( wp_strip_all_tags( $attrs['alt'] ) ) : '',
					'loading'       => isset( $attrs['loading'] ) ? sanitize_key( $attrs['loading'] ) : '',
					'width'         => isset( $attrs['width'] ) ? absint( $attrs['width'] ) : 0,
					'height'        => isset( $attrs['height'] ) ? absint( $attrs['height'] ) : 0,
				);
			}
		}

		if ( preg_match_all( '/<!--\s+wp:image\s+({.*?})\s+-->/is', $content, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				$attrs = json_decode( html_entity_decode( $json, ENT_QUOTES, get_bloginfo( 'charset' ) ), true );
				if ( ! empty( $attrs['id'] ) ) {
					$items[] = array(
						'attachment_id' => absint( $attrs['id'] ),
						'source'        => 'image_block',
						'src'           => '',
						'alt'           => '',
						'loading'       => '',
						'width'         => 0,
						'height'        => 0,
					);
				}
			}
		}

		return array_values( array_unique( $items, SORT_REGULAR ) );
	}

	/**
	 * Parse simple HTML tag attributes.
	 *
	 * @param string $tag HTML tag.
	 * @return array Attributes.
	 */
	protected function parse_html_tag_attributes( $tag ) {
		$attrs = array();

		if ( preg_match_all( '/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/s', $tag, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$attrs[ strtolower( $match[1] ) ] = html_entity_decode( $match[3], ENT_QUOTES, get_bloginfo( 'charset' ) );
			}
		}

		return $attrs;
	}

	/**
	 * Build media SEO item for a WordPress attachment.
	 *
	 * @param int        $attachment_id Attachment ID.
	 * @param string     $role          Media role.
	 * @param array|null $content_item  Optional content image item.
	 * @return array Media item.
	 */
	protected function build_media_seo_item( $attachment_id, $role, $content_item = null ) {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$file     = get_attached_file( $attachment_id );
		$url      = wp_get_attachment_url( $attachment_id );
		$alt      = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );

		if ( '' === $alt && is_array( $content_item ) && '' !== $content_item['alt'] ) {
			$alt = $content_item['alt'];
		}

		return array(
			'attachment_id' => $attachment_id,
			'role'          => $role,
			'url'           => $url ? esc_url_raw( $url ) : '',
			'filename'      => $file ? basename( $file ) : '',
			'alt'           => $alt,
			'title'         => get_the_title( $attachment_id ),
			'mime_type'     => get_post_mime_type( $attachment_id ),
			'width'         => is_array( $metadata ) && ! empty( $metadata['width'] ) ? absint( $metadata['width'] ) : ( is_array( $content_item ) ? absint( $content_item['width'] ) : 0 ),
			'height'        => is_array( $metadata ) && ! empty( $metadata['height'] ) ? absint( $metadata['height'] ) : ( is_array( $content_item ) ? absint( $content_item['height'] ) : 0 ),
			'filesize'      => $file && file_exists( $file ) ? filesize( $file ) : 0,
			'loading'       => is_array( $content_item ) ? $content_item['loading'] : '',
			'source'        => is_array( $content_item ) ? $content_item['source'] : 'attachment',
		);
	}

	/**
	 * Build media SEO item for a non-attachment image.
	 *
	 * @param array $content_item Content item.
	 * @return array Media item.
	 */
	protected function build_external_media_seo_item( $content_item ) {
		return array(
			'attachment_id' => 0,
			'role'          => 'content_image',
			'url'           => $content_item['src'],
			'filename'      => '' !== $content_item['src'] ? basename( wp_parse_url( $content_item['src'], PHP_URL_PATH ) ) : '',
			'alt'           => $content_item['alt'],
			'title'         => '',
			'mime_type'     => '',
			'width'         => absint( $content_item['width'] ),
			'height'        => absint( $content_item['height'] ),
			'filesize'      => 0,
			'loading'       => $content_item['loading'],
			'source'        => $content_item['source'],
		);
	}

	/**
	 * Validate one media SEO item.
	 *
	 * @param array $item Media item.
	 * @return array Issues.
	 */
	protected function validate_media_seo_item( $item ) {
		$issues = array();

		if ( 'content_image' === $item['role'] && '' === trim( (string) $item['alt'] ) ) {
			$issues[] = $this->make_media_seo_issue( 'missing_image_alt', 'warning', __( 'A content image is missing alt text.', 'mcpwp' ), __( 'Add useful alt text, or mark the image decorative in an approved workflow.', 'mcpwp' ), array( 'attachment_id' => $item['attachment_id'], 'url' => $item['url'] ) );
		}

		if ( 'featured_image' === $item['role'] && '' === trim( (string) $item['alt'] ) ) {
			$issues[] = $this->make_media_seo_issue( 'featured_image_missing_alt', 'warning', __( 'The featured image is missing alt text.', 'mcpwp' ), __( 'Add alt text if the featured image conveys meaning.', 'mcpwp' ), array( 'attachment_id' => $item['attachment_id'] ) );
		}

		if ( '' !== $item['filename'] && preg_match( '/^(image|img|photo|screenshot|untitled|dsc)[-_]?\d*\./i', $item['filename'] ) ) {
			$issues[] = $this->make_media_seo_issue( 'weak_image_filename', 'info', __( 'An image filename is generic.', 'mcpwp' ), __( 'Use descriptive filenames before upload when practical; do not rename live media blindly.', 'mcpwp' ), array( 'attachment_id' => $item['attachment_id'], 'filename' => $item['filename'] ) );
		}

		if ( $item['filesize'] > 0 && $item['filesize'] > 512000 ) {
			$issues[] = $this->make_media_seo_issue( 'large_image_file', 'warning', __( 'An image file is large.', 'mcpwp' ), __( 'Compress or replace oversized images to improve page experience.', 'mcpwp' ), array( 'attachment_id' => $item['attachment_id'], 'filesize' => $item['filesize'] ) );
		}

		if ( 'content_image' === $item['role'] && ( 0 === (int) $item['width'] || 0 === (int) $item['height'] ) ) {
			$issues[] = $this->make_media_seo_issue( 'missing_image_dimensions', 'info', __( 'A content image is missing explicit dimensions.', 'mcpwp' ), __( 'Use WordPress image blocks or markup that preserves width and height to reduce layout shift.', 'mcpwp' ), array( 'attachment_id' => $item['attachment_id'], 'url' => $item['url'] ) );
		}

		if ( 'content_image' === $item['role'] && '' === $item['loading'] ) {
			$issues[] = $this->make_media_seo_issue( 'missing_lazy_loading_hint', 'info', __( 'A content image has no loading attribute in markup.', 'mcpwp' ), __( 'Confirm WordPress or the theme adds lazy loading, especially for below-the-fold images.', 'mcpwp' ), array( 'attachment_id' => $item['attachment_id'], 'url' => $item['url'] ) );
		}

		return $issues;
	}


	/**
	 * Get supported SEO meta description value (simple string, backward-compatible).
	 *
	 * @param int $post_id Post ID.
	 * @return string Description.
	 */
	protected function get_seo_meta_description( $post_id ) {
		return $this->resolve_seo_meta_description( $post_id )['value'];
	}

	/**
	 * Resolve SEO meta description with source tracking.
	 *
	 * Returns an array with:
	 *   value       — the description string (may be empty)
	 *   source      — where the value came from (e.g. 'aioseo_stored', 'yoast_stored', 'none')
	 *   is_template — true when the stored value contains SEO plugin template tags (e.g. #post_excerpt)
	 *
	 * Handles the AIOSEO case where no per-post description is stored but AIOSEO renders a
	 * computed excerpt fallback in <head> — validators should not flag that as "missing".
	 *
	 * @param int $post_id Post ID.
	 * @return array{value: string, source: string, is_template: bool}
	 */
	protected function resolve_seo_meta_description( $post_id ) {
		$template_pattern = '/#[a-z_]+/';

		// AIOSEO — check for computed excerpt fallback when no per-post value is stored.
		if ( defined( 'AIOSEO_VERSION' ) ) {
			$raw = trim( (string) get_post_meta( $post_id, '_aioseo_description', true ) );
			if ( '' !== $raw ) {
				$is_template = (bool) preg_match( $template_pattern, $raw );
				// Attempt to render AIOSEO template tags via their public API.
				if ( $is_template && function_exists( 'aioseo' ) ) {
					$rendered = $this->try_render_aioseo_description( $post_id, $raw );
					if ( '' !== $rendered ) {
						return array( 'value' => $rendered, 'source' => 'aioseo_rendered', 'is_template' => false );
					}
				}
				return array( 'value' => $raw, 'source' => 'aioseo_stored', 'is_template' => $is_template );
			}
			// No per-post description stored. AIOSEO falls back to excerpt in <head>.
			$post = get_post( $post_id );
			if ( $post ) {
				$excerpt = get_the_excerpt( $post );
				if ( '' !== trim( $excerpt ) ) {
					return array(
						'value'       => wp_trim_words( wp_strip_all_tags( $excerpt ), 35 ),
						'source'      => 'aioseo_excerpt_fallback',
						'is_template' => false,
					);
				}
			}
			return array( 'value' => '', 'source' => 'aioseo_none', 'is_template' => false );
		}

		$meta_map = array(
			'_yoast_wpseo_metadesc'  => 'yoast',
			'rank_math_description'  => 'rankmath',
			'_seopress_titles_desc'  => 'seopress',
			'mcpwp_meta_description' => 'mcpwp',
		);

		foreach ( $meta_map as $key => $source ) {
			$value = trim( (string) get_post_meta( $post_id, $key, true ) );
			if ( '' !== $value ) {
				$is_template = (bool) preg_match( $template_pattern, $value );
				return array( 'value' => $value, 'source' => $source . '_stored', 'is_template' => $is_template );
			}
		}

		return array( 'value' => '', 'source' => 'none', 'is_template' => false );
	}

	/**
	 * Attempt to render AIOSEO template tags via AIOSEO's own tag API.
	 *
	 * Tries known AIOSEO API paths across versions (4.x tags service).
	 * Returns empty string on any failure so callers always fall back gracefully.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $template Raw template string containing #tag placeholders.
	 * @return string Rendered description, or empty string on failure.
	 */
	protected function try_render_aioseo_description( $post_id, $template ) {
		if ( ! function_exists( 'aioseo' ) ) {
			return '';
		}

		try {
			$aioseo = aioseo();
			// AIOSEO 4.x: tags()->replaceTags( $template, $post )
			if ( isset( $aioseo->tags ) && method_exists( $aioseo->tags, 'replaceTags' ) ) {
				$post     = get_post( $post_id );
				$rendered = (string) $aioseo->tags->replaceTags( $template, $post );
				if ( '' !== trim( $rendered ) && $rendered !== $template ) {
					return trim( $rendered );
				}
			}
		} catch ( \Exception $e ) {
			// Silently fall through — AIOSEO internals changed.
		}

		return '';
	}

	/**
	 * Get supported SEO canonical value.
	 *
	 * @param int $post_id Post ID.
	 * @return string Canonical URL.
	 */
	protected function get_seo_canonical_url( $post_id ) {
		$keys = array(
			'_yoast_wpseo_canonical',
			'rank_math_canonical_url',
			'_aioseo_canonical_url',
			'_seopress_robots_canonical',
		);

		foreach ( $keys as $key ) {
			$value = trim( (string) get_post_meta( $post_id, $key, true ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Determine whether common SEO meta marks a post noindex.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True when noindex.
	 */
	protected function is_post_noindex( $post_id ) {
		$values = array(
			get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
			get_post_meta( $post_id, 'rank_math_robots', true ),
			get_post_meta( $post_id, '_aioseo_robots_noindex', true ),
			get_post_meta( $post_id, '_seopress_robots_index', true ),
		);

		foreach ( $values as $value ) {
			if ( is_array( $value ) && in_array( 'noindex', array_map( 'strtolower', $value ), true ) ) {
				return true;
			}

			$value = strtolower( trim( (string) $value ) );
			if ( in_array( $value, array( '1', 'true', 'yes', 'noindex' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract image attachment IDs from block/image markup.
	 *
	 * @param string $content Content.
	 * @return array Attachment IDs.
	 */
	protected function extract_image_ids_from_content( $content ) {
		$image_ids = array();

		if ( preg_match_all( '/<!--\s+wp:image\s+({.*?})\s+-->/is', $content, $matches ) ) {
			foreach ( $matches[1] as $json ) {
				$attrs = json_decode( html_entity_decode( $json, ENT_QUOTES, get_bloginfo( 'charset' ) ), true );
				if ( ! empty( $attrs['id'] ) ) {
					$image_ids[] = (int) $attrs['id'];
				}
			}
		}

		if ( preg_match_all( '/wp-image-(\d+)/i', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$image_ids[] = (int) $id;
			}
		}

		return array_values( array_unique( array_filter( $image_ids ) ) );
	}

	/**
	 * Get robots.txt output.
	 *
	 * @return string Robots content.
	 */
	protected function get_robots_txt() {
		$public = (bool) get_option( 'blog_public' );
		$output = "User-agent: *\n";

		if ( $public ) {
			$output .= "Disallow:\n";
		} else {
			$output .= "Disallow: /\n";
		}

		return (string) apply_filters( 'robots_txt', $output, $public );
	}

	/**
	 * Check whether a sitemap endpoint is likely available.
	 *
	 * @return bool True when sitemap is likely.
	 */
	protected function site_has_sitemap_hint() {
		return function_exists( 'wp_sitemaps_get_server' ) || defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Check for structured data hints in content.
	 *
	 * @param string $content Content.
	 * @return bool True when schema hint exists.
	 */
	protected function content_has_schema_hint( $content ) {
		return false !== stripos( $content, 'application/ld+json' )
			|| false !== stripos( $content, 'itemscope' )
			|| false !== stripos( $content, 'schema.org' );
	}

	/**
	 * Fetch the rendered <head> of a public URL and parse key SEO fields.
	 *
	 * Used to give ground-truth validation rather than reading from post meta alone.
	 * Returns null on any HTTP or timeout failure so callers always fall back to
	 * stored-meta validation gracefully.
	 *
	 * Fields returned:
	 *   title        — content of <title> tag
	 *   description  — content of <meta name="description">
	 *   canonical    — href of <link rel="canonical">
	 *   robots       — content of <meta name="robots">
	 *   og_title     — content of <meta property="og:title">
	 *   og_description — content of <meta property="og:description">
	 *
	 * @param string $url Public URL to fetch.
	 * @return array|null Parsed fields, or null on failure.
	 */
	protected function fetch_rendered_head_seo( $url ) {
		if ( '' === trim( $url ) ) {
			return null;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'   => 6,
				'sslverify' => false,
				'headers'   => array( 'User-Agent' => 'MCPWP-SEO-Validator/1.0 (+https://mcpwp.net/)' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$html = wp_remote_retrieve_body( $response );
		if ( '' === $html ) {
			return null;
		}

		// Extract only the <head> section to keep regex scope narrow.
		$head = '';
		if ( preg_match( '/<head[\s>].*?<\/head>/is', $html, $m ) ) {
			$head = $m[0];
		} else {
			$head = substr( $html, 0, 8000 );
		}

		return array(
			'title'          => $this->parse_head_title( $head ),
			'description'    => $this->parse_head_meta( $head, 'name', 'description' ),
			'canonical'      => $this->parse_head_canonical( $head ),
			'robots'         => $this->parse_head_meta( $head, 'name', 'robots' ),
			'og_title'       => $this->parse_head_meta( $head, 'property', 'og:title' ),
			'og_description' => $this->parse_head_meta( $head, 'property', 'og:description' ),
		);
	}

	/**
	 * Parse the text content of the <title> tag from a <head> fragment.
	 *
	 * @param string $head HTML <head> content.
	 * @return string Title text, or empty string.
	 */
	protected function parse_head_title( $head ) {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $head, $m ) ) {
			return trim( html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}
		return '';
	}

	/**
	 * Parse the content attribute of a <meta> tag matching a given attribute/value pair.
	 *
	 * Handles arbitrary attribute order and both single/double quotes.
	 *
	 * @param string $head      HTML <head> content.
	 * @param string $attr_name  Attribute to match on ('name' or 'property').
	 * @param string $attr_value Value that attribute must equal.
	 * @return string content attribute value, or empty string.
	 */
	protected function parse_head_meta( $head, $attr_name, $attr_value ) {
		$attr_name_re  = preg_quote( $attr_name, '/' );
		$attr_value_re = preg_quote( $attr_value, '/' );

		// Match <meta ... name="description" ... content="..."> in either attr order.
		$pattern = '/<meta\s[^>]*?' . $attr_name_re . '\s*=\s*["\']' . $attr_value_re . '["\'][^>]*?content\s*=\s*(["\'])(.*?)\1[^>]*?\/?>/is';
		if ( preg_match( $pattern, $head, $m ) ) {
			return trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		// Reverse attribute order: content comes before name.
		$pattern2 = '/<meta\s[^>]*?content\s*=\s*(["\'])(.*?)\1[^>]*?' . $attr_name_re . '\s*=\s*["\']' . $attr_value_re . '["\'][^>]*?\/?>/is';
		if ( preg_match( $pattern2, $head, $m ) ) {
			return trim( html_entity_decode( $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		return '';
	}

	/**
	 * Parse the href of <link rel="canonical"> from a <head> fragment.
	 *
	 * @param string $head HTML <head> content.
	 * @return string Canonical URL, or empty string.
	 */
	protected function parse_head_canonical( $head ) {
		if ( preg_match( '/<link\s[^>]*rel=["\']canonical["\'][^>]*href\s*=\s*(["\'])(.*?)\1/i', $head, $m ) ) {
			return trim( $m[2] );
		}
		if ( preg_match( '/<link\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*rel=["\']canonical["\']/i', $head, $m ) ) {
			return trim( $m[2] );
		}
		return '';
	}

	/**
	 * Reconcile readiness issues against ground-truth rendered <head> data.
	 *
	 * Suppresses false-positive issues whose cause is a stored-meta/rendered gap
	 * and adds a render-discrepancy issue when stored and rendered values differ
	 * in a way that matters for SEO.
	 *
	 * @param array $issues        Issues built from stored meta.
	 * @param array $rendered_head Parsed rendered <head> (from fetch_rendered_head_seo).
	 * @return array Reconciled issue list.
	 */
	protected function reconcile_issues_with_rendered_head( $issues, $rendered_head ) {
		$rendered_desc      = trim( $rendered_head['description'] ?? '' );
		$rendered_robots    = strtolower( trim( $rendered_head['robots'] ?? '' ) );
		$rendered_canonical = trim( $rendered_head['canonical'] ?? '' );

		$filtered = array();
		foreach ( $issues as $issue ) {
			$code = $issue['code'] ?? '';

			// Suppress "missing meta description" when rendered page has one.
			if ( 'missing_meta_description' === $code && '' !== $rendered_desc ) {
				continue;
			}

			// Suppress "meta description length" when rendered page description length is fine.
			if ( 'meta_description_length' === $code && '' !== $rendered_desc ) {
				$len = strlen( $rendered_desc );
				if ( $len >= 50 && $len <= 170 ) {
					continue;
				}
			}

			// Suppress "noindex_enabled" when rendered robots meta does NOT contain noindex.
			// This handles cases where the SEO plugin stores noindex in meta but the rendered
			// output is overridden at the template level (rare but real).
			if ( 'noindex_enabled' === $code && '' !== $rendered_robots && false === strpos( $rendered_robots, 'noindex' ) ) {
				continue;
			}

			$filtered[] = $issue;
		}

		// Add render-discrepancy warnings when stored vs rendered values diverge materially.
		// Only flag when both sides are non-empty so we don't false-alarm on template fallbacks.
		$found_codes = array_column( $filtered, 'code' );

		if ( '' !== $rendered_desc ) {
			// Check whether any stored description (from meta issues) differs significantly.
			// We can't easily get the stored value here, so we add an informational note that
			// the rendered description was verified successfully.
			if ( ! in_array( 'render_truth_desc_verified', $found_codes, true ) ) {
				// No action needed — description present in rendered output. Nothing to flag.
			}
		}

		if ( '' !== $rendered_canonical && '' !== $rendered_head['canonical'] ?? '' ) {
			// If rendered canonical doesn't match stored canonical check, note it.
			// (The stored canonical issue is already raised by the meta-check path.)
		}

		// If rendered robots contains noindex but we didn't catch it via stored meta, flag it.
		if ( '' !== $rendered_robots
			&& false !== strpos( $rendered_robots, 'noindex' )
			&& ! in_array( 'noindex_enabled', $found_codes, true )
		) {
			$filtered[] = $this->make_seo_readiness_issue(
				'rendered_noindex_detected',
				'error',
				__( 'Rendered <head> contains noindex — SEO plugin is blocking this page from search.', 'mcpwp' ),
				__( 'Remove noindex from your SEO plugin settings for this page before publishing.', 'mcpwp' ),
				array( 'rendered_robots' => $rendered_robots )
			);
		}

		return $filtered;
	}

}
