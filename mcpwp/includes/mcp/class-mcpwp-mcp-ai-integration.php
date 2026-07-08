<?php
/**
 * MCP AI Integration
 *
 * Extends Mcpwp_Integration to register AI-powered MCP tools
 * (image generation, vision, TTS, stock photos) with mumcp.
 *
 * @package mumcp
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI integration for MCP.
 */
class Mcpwp_MCP_AI_Integration extends Mcpwp_Integration {

	/**
	 * Get integration metadata.
	 *
	 * @return array
	 */
	public function get_info() {
		return array(
			'slug'    => 'ai-integrations',
			'name'    => 'AI Integrations',
			'version' => '1.0.0',
		);
	}

	/**
	 * Get capabilities for site-info.
	 *
	 * @return array
	 */
	public function get_capabilities() {
		$manager   = Mcpwp_Integration_Manager::get_instance();
		$providers = $manager->get_available_providers();

		$configured = array();
		foreach ( $providers as $slug => $info ) {
			if ( $info['configured'] ) {
				$configured[] = $slug;
			}
		}

		return array(
			'ai_integrations'          => ! empty( $configured ),
			'ai_configured_providers'  => $configured,
		);
	}

	/**
	 * Get tool category mappings for AI integration.
	 *
	 * @return array Map of tool_name => category_slug.
	 */
	public function get_tool_categories() {
		return array(
			'wp_search_stock_photos'     => 'ai',
			'wp_download_stock_photo'    => 'ai',
			'wp_generate_image'          => 'ai',
			'wp_generate_featured_image' => 'ai',
			'wp_generate_alt_text'       => 'ai',
			'wp_describe_image'          => 'ai',
			'wp_generate_excerpt'        => 'ai',
			'wp_text_to_speech'          => 'ai',
		);
	}

	/**
	 * Get tool definitions.
	 *
	 * Free tools always included. Pro tools only when licensed.
	 *
	 * @return array
	 */
	public function get_tools() {
		$tools = array();

		// Free tier: Stock photos (Pexels).
		$tools[] = $this->define_tool(
			'wp_search_stock_photos',
			'Search Pexels for free stock photos. Returns photo IDs, URLs, dimensions, and photographer info.',
			array(
				'query'    => array(
					'type'        => 'string',
					'description' => 'Search query (e.g., "sunset beach", "office workspace")',
					'required'    => true,
				),
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Results per page (1-80, default 10)',
					'default'     => 10,
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Page number',
					'default'     => 1,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_download_stock_photo',
			'Download a Pexels photo to the WordPress media library. Use wp_search_stock_photos first to find photo_id.',
			array(
				'photo_id' => array(
					'type'        => 'number',
					'description' => 'Pexels photo ID from search results',
					'required'    => true,
				),
				'size'     => array(
					'type'        => 'string',
					'description' => 'Image size: original, large2x, large, medium, small (default: large)',
					'default'     => 'large',
				),
				'alt'      => array(
					'type'        => 'string',
					'description' => 'Alt text for the image',
				),
				'title'    => array(
					'type'        => 'string',
					'description' => 'Title for the media library item',
				),
			)
		);

		// AI generation tools.
		$tools[] = $this->define_tool(
				'wp_generate_image',
				'Generate an AI image using GPT-Image-1-Mini (OpenAI) or Imagen 3 (Gemini) and upload to WordPress media library.',
				array(
					'prompt'   => array(
						'type'        => 'string',
						'description' => 'Detailed image generation prompt',
						'required'    => true,
					),
					'provider' => array(
						'type'        => 'string',
						'description' => 'AI provider: openai or gemini (auto-selects if omitted)',
					),
					'size'     => array(
						'type'        => 'string',
						'description' => 'Image size: 1024x1024, 1536x1024 (landscape), 1024x1536 (portrait)',
						'default'     => '1024x1024',
					),
					'quality'  => array(
						'type'        => 'string',
						'description' => 'OpenAI quality: low, medium, high (default: medium)',
						'default'     => 'medium',
					),
					'alt'      => array(
						'type'        => 'string',
						'description' => 'Alt text for the uploaded image',
					),
					'title'    => array(
						'type'        => 'string',
						'description' => 'Title for the media library item',
					),
				)
			);

			$tools[] = $this->define_tool(
				'wp_generate_featured_image',
				'Generate an AI image and set it as the featured image for a post/page.',
				array(
					'post_id'  => array(
						'type'        => 'number',
						'description' => 'Post or page ID to set featured image on',
						'required'    => true,
					),
					'prompt'   => array(
						'type'        => 'string',
						'description' => 'Image generation prompt',
						'required'    => true,
					),
					'provider' => array(
						'type'        => 'string',
						'description' => 'AI provider: openai or gemini (auto-selects if omitted)',
					),
					'size'     => array(
						'type'        => 'string',
						'description' => 'Image size (default: 1792x1024 for featured images)',
						'default'     => '1792x1024',
					),
					'style'    => array(
						'type'        => 'string',
						'description' => 'OpenAI only: vivid or natural',
						'default'     => 'vivid',
					),
				)
			);

			$tools[] = $this->define_tool(
				'wp_generate_alt_text',
				'Use AI vision to generate alt text for an existing image in the media library.',
				array(
					'attachment_id' => array(
						'type'        => 'number',
						'description' => 'WordPress attachment ID of the image',
						'required'    => true,
					),
					'provider'      => array(
						'type'        => 'string',
						'description' => 'AI provider: openai or gemini (auto-selects if omitted)',
					),
					'auto_save'     => array(
						'type'        => 'boolean',
						'description' => 'If true, saves the generated alt text to the attachment (default: false)',
						'default'     => false,
					),
				)
			);

			$tools[] = $this->define_tool(
				'wp_describe_image',
				'Use AI vision to get a detailed description of an image in the media library.',
				array(
					'attachment_id' => array(
						'type'        => 'number',
						'description' => 'WordPress attachment ID of the image',
						'required'    => true,
					),
					'provider'      => array(
						'type'        => 'string',
						'description' => 'AI provider: openai or gemini (auto-selects if omitted)',
					),
					'instruction'   => array(
						'type'        => 'string',
						'description' => 'Custom instruction for the vision model (default: "Describe this image in detail.")',
						'default'     => 'Describe this image in detail.',
					),
				)
			);

			$tools[] = $this->define_tool(
				'wp_generate_excerpt',
				'Use AI to generate a compelling excerpt/summary for a post based on its content.',
				array(
					'post_id'    => array(
						'type'        => 'number',
						'description' => 'Post or page ID',
						'required'    => true,
					),
					'provider'   => array(
						'type'        => 'string',
						'description' => 'AI provider: openai or gemini (auto-selects if omitted)',
					),
					'max_length' => array(
						'type'        => 'number',
						'description' => 'Maximum excerpt length in characters (default: 160)',
						'default'     => 160,
					),
					'auto_save'  => array(
						'type'        => 'boolean',
						'description' => 'If true, saves the excerpt to the post (default: false)',
						'default'     => false,
					),
				)
			);

			$tools[] = $this->define_tool(
				'wp_text_to_speech',
				'Convert text to speech using ElevenLabs and upload the MP3 to WordPress media library.',
				array(
					'text'     => array(
						'type'        => 'string',
						'description' => 'Text to convert to speech',
						'required'    => true,
					),
					'voice_id' => array(
						'type'        => 'string',
						'description' => 'ElevenLabs voice ID (uses default Rachel voice if omitted)',
					),
					'title'    => array(
						'type'        => 'string',
						'description' => 'Title for the audio file in media library',
					),
				)
			);

		return $tools;
	}

	/**
	 * Get tool-to-REST route mapping.
	 *
	 * @return array
	 */
	public function get_tool_map() {
		return array(
			'wp_search_stock_photos'     => array(
				'route'  => '/integrations/stock-photos',
				'method' => 'GET',
			),
			'wp_download_stock_photo'    => array(
				'route'  => '/integrations/stock-photos/download',
				'method' => 'POST',
			),
			'wp_generate_image'          => array(
				'route'  => '/integrations/generate-image',
				'method' => 'POST',
			),
			'wp_generate_featured_image' => array(
				'route'  => '/integrations/generate-featured-image',
				'method' => 'POST',
			),
			'wp_generate_alt_text'       => array(
				'route'  => '/integrations/generate-alt-text',
				'method' => 'POST',
			),
			'wp_describe_image'          => array(
				'route'  => '/integrations/describe-image',
				'method' => 'POST',
			),
			'wp_generate_excerpt'        => array(
				'route'  => '/integrations/generate-excerpt',
				'method' => 'POST',
			),
			'wp_text_to_speech'          => array(
				'route'  => '/integrations/text-to-speech',
				'method' => 'POST',
			),
		);
	}

	/**
	 * Get open world tools (all tools call external APIs).
	 *
	 * @return array
	 */
	protected function get_open_world_tools() {
		return array(
			'wp_search_stock_photos',
			'wp_download_stock_photo',
			'wp_generate_image',
			'wp_generate_featured_image',
			'wp_generate_alt_text',
			'wp_describe_image',
			'wp_generate_excerpt',
			'wp_text_to_speech',
		);
	}

	/**
	 * Register REST routes.
	 *
	 * Delegates to the dedicated REST controller.
	 */
	public function register_routes() {
		$controller = new Mcpwp_REST_Integrations();
		$controller->register_routes();
	}
}
