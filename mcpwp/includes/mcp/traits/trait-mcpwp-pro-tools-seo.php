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
			'Submit one or more URLs to Google for indexing via the Indexing API. Requires Google Indexing API integration to be configured. Use action URL_UPDATED for new/updated pages and URL_DELETED for removed pages. Limited to 200 URLs per day by Google.',
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

		return $pro_tools;
	}
}
