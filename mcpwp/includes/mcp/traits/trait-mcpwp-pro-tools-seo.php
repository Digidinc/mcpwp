<?php
/**
 * Pro-tier tool definitions — seo category group.
 *
 * Carved verbatim from Mcpwp_MCP_Pro_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Pro_Tools_Seo_Trait {

	/**
	 * @return array
	 */
	private function get_google_indexing_pro_tools() {
		$pro_tools = array();
		// Google Indexing API Tools.
		$pro_tools[] = $this->define_tool(
			'wp_submit_to_google_index',
			'Submit URLs to the Google Indexing API. IMPORTANT: Google only crawls submissions for pages carrying JobPosting structured data, or BroadcastEvent embedded in a VideoObject — "The Indexing API can only be used to crawl pages with either JobPosting or BroadcastEvent embedded in a VideoObject" (developers.google.com/search/apis/indexing-api). Submitting an ordinary page or blog post returns HTTP 200 and is then ignored, so a successful response here is NOT evidence the URL will be indexed. For general content use a sitemap and Search Console instead. Requires the google_indexing integration (a Google Cloud service-account JSON key). Use URL_UPDATED for new/updated pages, URL_DELETED for removed ones. Google caps this at 200 URLs per day.',
			array(
				'urls'   => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'description' => 'Array of URLs to submit for indexing',
					'required'    => true,
				),
				'action' => array(
					'type'        => 'string',
					'description' => 'Notification type: URL_UPDATED (new/updated page) or URL_DELETED (removed page)',
					'default'     => 'URL_UPDATED',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_google_index_status',
			'Check Google indexing status for a URL. Returns the latest update and removal notification times from the Indexing API. Requires Google Indexing API integration to be configured.',
			array(
				'url' => array(
					'type'        => 'string',
					'description' => 'URL to check indexing status for',
					'required'    => true,
				),
			)
		);

		return $pro_tools;
	}

	/**
	 * @return array
	 */
	private function get_multilanguage_pro_tools() {
		$pro_tools = array();
		// Multilanguage Tools (WPML, Polylang, TranslatePress).
		$pro_tools[] = $this->define_tool(
			'wp_languages',
			'Get multilingual plugin status and list of available site languages. Returns language codes and active language.',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_set_language',
			'Set the active language for subsequent content and translation operations. Use before reading or writing translated content.',
			array(
				'language' => array(
					'type'        => 'string',
					'description' => 'Language code (e.g., fa, en)',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_translations',
			'Get all translations for a specific post or page. Returns translated versions by language code.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Post/Page ID',
					'required'    => true,
				),
				'type' => array(
					'type'        => 'string',
					'description' => 'Content type (post or page)',
					'default'     => 'page',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_translation',
			'Create a translation for a post or page in a target language',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Source Post/Page ID',
					'required'    => true,
				),
				'type' => array(
					'type'        => 'string',
					'description' => 'Content type (post or page)',
					'default'     => 'page',
				),
				'language' => array(
					'type'        => 'string',
					'description' => 'Target language code',
					'required'    => true,
				),
				'title' => array(
					'type'        => 'string',
					'description' => 'Translated title',
				),
				'content' => array(
					'type'        => 'string',
					'description' => 'Translated content',
				),
				'excerpt' => array(
					'type'        => 'string',
					'description' => 'Translated excerpt',
				),
				'status' => array(
					'type'        => 'string',
					'description' => 'Translation post status',
					'default'     => 'draft',
				),
			)
		);

		return $pro_tools;
	}

	/**
	 * @return array
	 */
	private function get_seo_pro_tools() {
		$pro_tools = array();
		// SEO Tools
		$pro_tools[] = $this->define_tool(
			'wp_get_seo',
			'Get SEO metadata for a specific page or post (Yoast, Rank Math, etc.)',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_set_seo',
			'Set SEO metadata for a specific page or post. Uses normalized SEO fields; seo_title and seo_description remain accepted aliases.',
			array(
				'id'              => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'title'           => array(
					'type'        => 'string',
					'description' => 'SEO title',
				),
				'description'     => array(
					'type'        => 'string',
					'description' => 'SEO meta description',
				),
				'seo_title'       => array(
					'type'        => 'string',
					'description' => 'Alias for title',
				),
				'seo_description' => array(
					'type'        => 'string',
					'description' => 'Alias for description',
				),
				'focus_keyword'   => array(
					'type'        => 'string',
					'description' => 'Focus keyword',
				),
				'canonical'       => array(
					'type'        => 'string',
					'description' => 'Canonical URL',
				),
				'canonical_url'   => array(
					'type'        => 'string',
					'description' => 'Alias for canonical',
				),
				'noindex'         => array(
					'type'        => 'boolean',
					'description' => 'Set to true to add noindex meta robots tag',
				),
				'nofollow'        => array(
					'type'        => 'boolean',
					'description' => 'Set to true to add nofollow meta robots tag',
				),
				'og_title'        => array(
					'type'        => 'string',
					'description' => 'Open Graph title for social sharing',
				),
				'og_description'  => array(
					'type'        => 'string',
					'description' => 'Open Graph description for social sharing',
				),
				'og_image'        => array(
					'type'        => 'string',
					'description' => 'Open Graph image URL for social sharing',
				),
				'twitter_title'   => array(
					'type'        => 'string',
					'description' => 'Twitter card title',
				),
				'twitter_description' => array(
					'type'        => 'string',
					'description' => 'Twitter card description',
				),
				'twitter_image'   => array(
					'type'        => 'string',
					'description' => 'Twitter card image URL',
				),
			),
			null,
			array(
				array(
					'id' => 42,
					'title' => 'Best Coffee Beans Online | Acme Roasters',
					'description' => 'Shop small-batch, ethically sourced coffee beans roasted fresh weekly.',
					'focus_keyword' => 'coffee beans online',
					'canonical' => 'https://example.com/coffee-beans',
					'noindex' => false,
					'og_title' => 'Best Coffee Beans Online',
					'og_image' => 'https://example.com/wp-content/uploads/coffee-hero.jpg',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_analyze_seo',
			'Analyze SEO quality for a post or page. Returns score, keyword density, readability grade, and specific recommendations.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_bulk_seo',
			'Update SEO metadata for multiple posts or pages in one call. Batch-set titles, descriptions, and focus keywords.',
			array(
				'updates' => array(
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'                  => array( 'type' => 'integer', 'description' => 'Post/page ID' ),
							'title'               => array( 'type' => 'string', 'description' => 'SEO title' ),
							'description'         => array( 'type' => 'string', 'description' => 'SEO meta description' ),
							'focus_keyword'       => array( 'type' => 'string', 'description' => 'Focus keyword' ),
							'canonical_url'       => array( 'type' => 'string', 'description' => 'Canonical URL' ),
							'noindex'             => array( 'type' => 'boolean', 'description' => 'Set to true to add noindex meta robots tag' ),
							'nofollow'            => array( 'type' => 'boolean', 'description' => 'Set to true to add nofollow meta robots tag' ),
							'og_title'            => array( 'type' => 'string', 'description' => 'Open Graph title' ),
							'og_description'      => array( 'type' => 'string', 'description' => 'Open Graph description' ),
							'og_image'            => array( 'type' => 'string', 'description' => 'Open Graph image URL' ),
						),
						'required'   => array( 'id' ),
					),
					'description' => 'Array of objects. Each must have id (post/page ID) plus any SEO fields: title, description, focus_keyword, canonical_url, noindex (bool), nofollow (bool), og_title, og_description, og_image',
				),
				'items' => array(
					'type'        => 'array',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'id'                  => array( 'type' => 'integer', 'description' => 'Post/page ID' ),
							'title'               => array( 'type' => 'string', 'description' => 'SEO title' ),
							'description'         => array( 'type' => 'string', 'description' => 'SEO meta description' ),
							'focus_keyword'       => array( 'type' => 'string', 'description' => 'Focus keyword' ),
							'canonical_url'       => array( 'type' => 'string', 'description' => 'Canonical URL' ),
							'noindex'             => array( 'type' => 'boolean', 'description' => 'Set to true to add noindex meta robots tag' ),
							'nofollow'            => array( 'type' => 'boolean', 'description' => 'Set to true to add nofollow meta robots tag' ),
							'og_title'            => array( 'type' => 'string', 'description' => 'Open Graph title' ),
							'og_description'      => array( 'type' => 'string', 'description' => 'Open Graph description' ),
							'og_image'            => array( 'type' => 'string', 'description' => 'Open Graph image URL' ),
						),
						'required'   => array( 'id' ),
					),
					'description' => 'Alias for updates. Accepted for compatibility with the SEO guide examples.',
				),
			),
			null,
			array(
				array(
					'updates' => array(
						array(
							'id' => 12,
							'title' => 'Summer Sale | Acme',
							'description' => 'Save up to 40% storewide through August.',
							'focus_keyword' => 'summer sale',
						),
						array(
							'id' => 15,
							'title' => 'Returns & Exchanges | Acme',
							'description' => 'Free returns within 30 days.',
						),
					),
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_seo_status',
			'Get active SEO plugin status and configuration. Returns which plugin is active (Yoast, Rank Math, AIOSEO) and its settings.',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_seo_scan',
			'Scan all published content for SEO issues. Returns missing titles, descriptions, thin content, and more.',
			array(
				'threshold' => array(
					'type'        => 'number',
					'description' => 'Minimum word count for thin content detection (default: 300)',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_seo_report',
			'Export complete SEO metadata for all published content. Returns title, description, keyword, noindex, canonical, word count for every post and page.',
			array(
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Filter by post type (e.g. post, page)',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum number of posts to return (default: 100, max: 500)',
				),
			)
		);

		// SEO Market Data (DataForSEO). Read-only visibility + a separate,
		// explicit refresh — never on a timer, so an agent loop cannot quietly
		// spend the customer's DataForSEO credit overnight.
		$pro_tools[] = $this->define_tool(
			'wp_seo_visibility',
			'Get the last stored search visibility snapshot for this site: ranked keywords, position, search volume, and difficulty. Does not fetch fresh data — call wp_refresh_seo_visibility for that. Returns reason "never_fetched" if no snapshot exists yet, or "no_rankings" if the last fetch found no ranked keywords.',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_refresh_seo_visibility',
			'Fetch a fresh search visibility snapshot from DataForSEO for this site and store it, replacing the previous snapshot. Spends DataForSEO credit — call explicitly, not in a loop. Requires the DataForSEO integration to be configured; returns an error naming the reason (not configured, credentials rejected, out of credit, or provider unreachable) otherwise. Debounced to one fetch per 5 minutes: a call inside that window does not spend credit and instead returns the existing snapshot with reason "refresh_debounced" and a "retry_after" seconds count — rankings do not move that fast, so nothing is lost by waiting.',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_seo_opportunities',
			'Get a ranked list of the highest-value SEO opportunities, using the stored visibility snapshot and (when a seed is given and no visibility data exists yet) fresh keyword suggestions. The "mode" field tells you which case applies: greenfield (no rankings yet, needs new content), emerging, or mature (page-two terms worth pushing). Each opportunity carries the evidence behind its score.',
			array(
				'seed' => array(
					'type'        => 'string',
					'description' => 'Seed keyword to look up fresh suggestions for when there is no stored visibility data yet (enables greenfield mode). Ignored once a visibility snapshot exists.',
				),
			)
		);

		return $pro_tools;
	}
}
