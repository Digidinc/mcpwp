<?php
/**
 * MCP Free Tools Registry
 *
 * Contains all free (always available) MCP tool definitions and route mappings.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Free tools registry for MCP.
 *
 * Provides tool definitions and route mappings for all free tier tools.
 */
class Mcpwp_MCP_Free_Tools extends Mcpwp_MCP_Tool_Registry {

	use Mcpwp_Free_Tools_Site_Trait;
	use Mcpwp_Free_Tools_Content_Trait;
	use Mcpwp_Free_Tools_Media_Trait;
	use Mcpwp_Free_Tools_Elementor_Trait;
	use Mcpwp_Free_Tools_Blocks_Trait;
	use Mcpwp_Free_Tools_Ops_Trait;

	/**
	 * Get destructive tool names for free tier.
	 *
	 * @return array Destructive tool names.
	 */
	protected function get_destructive_tools() {
		return array(
			'wp_delete_post',
			'wp_delete_page',
			'wp_delete_all_drafts',
			'wp_delete_menu',
			'wp_delete_menu_item',
			'wp_revoke_api_key',
			'wp_reset_rate_limit',
			'wp_delete_webhook',
			'wp_delete_media',
		);
	}

	/**
	 * Get open world tool names for free tier.
	 *
	 * @return array Open world tool names.
	 */
	protected function get_open_world_tools() {
		return array(
			'wp_upload_media_from_url',
			'wp_upload_design_reference',
			'wp_test_webhook',
			'wp_screenshot_url',
		);
	}

	/**
	 * Get tool category mappings for free tier.
	 *
	 * @return array Map of tool_name => category_slug.
	 */
	public function get_tool_categories() {
		$categories = array(
			// Site & Analytics
			'wp_site_info'               => 'site',
			'wp_introspect'              => 'site',
			'wp_onboard'                 => 'site',
			'wp_analytics'               => 'site',
			'wp_detect_plugins'          => 'site',
			'wp_get_options'             => 'site',
			'wp_update_options'          => 'site',
			'wp_get_site_context'        => 'site',
			'wp_set_site_context'        => 'site',
			'wp_get_site_state'          => 'site',
			'wp_get_content_graph'       => 'content',
			'wp_suggest_internal_links'  => 'content',
			'wp_apply_internal_link'     => 'content',
			'wp_validate_internal_links' => 'content',
			'wp_validate_seo_readiness'  => 'seo',
			'wp_validate_structured_data' => 'seo',
			'wp_audit_media_seo'         => 'seo',
			'wp_seo_audit_site'          => 'seo',
			'wp_audit_content_quality'   => 'seo',
			'wp_get_seo_issues'          => 'seo',
			'wp_run_seo_autofix_plan'    => 'seo',
			'wp_import_search_performance' => 'seo',
			'wp_get_seo_trends'          => 'seo',
			'wp_seo_flags'               => 'seo',
			'wp_get_woocommerce_seo_report' => 'seo',
			'wp_get_custom_css'          => 'site',
			'wp_set_custom_css'          => 'site',
			'wp_delete_custom_css'       => 'site',
			'wp_get_css_length'          => 'site',
			'wp_get_rendered_html'       => 'site',
			'wp_get_rendered_copy'       => 'site',
			'wp_list_menus'              => 'site',
			'wp_list_menu_locations'     => 'site',
			'wp_setup_menu'              => 'site',
			'wp_list_menu_items'         => 'site',
			'wp_add_menu_item'           => 'site',
			'wp_update_menu_item'        => 'site',
			'wp_delete_menu_item'        => 'site',
			'wp_update_menu_item_auto'   => 'site',
			'wp_delete_menu_item_auto'   => 'site',
			'wp_reorder_menu_items'      => 'site',
			'wp_delete_menu'             => 'site',
			'wp_assign_menu_location'    => 'site',
			'wp_update_page_template'    => 'site',
			'wp_list_page_templates'     => 'site',
			'wp_get_option'              => 'site',
			'wp_update_option'           => 'site',
			'wp_get_theme_info'          => 'site',
			'wp_flush_permalinks'        => 'site',
			'wp_get_site_health'         => 'site',
			'wp_list_design_references'  => 'site',
			'wp_get_design_reference'    => 'site',
			'wp_upload_design_reference' => 'site',
			'wp_update_design_reference' => 'site',

			// Content
			'wp_list_content'            => 'content',
			'wp_delete_content'          => 'content',
			'wp_search'                  => 'content',
			'wp_fetch'                   => 'content',
			'wp_list_posts'              => 'content',
			'wp_create_post'             => 'content',
			'wp_update_post'             => 'content',
			'wp_delete_post'             => 'content',
			'wp_list_pages'              => 'content',
			'wp_create_page'             => 'content',
			'wp_update_page'             => 'content',
			'wp_delete_page'             => 'content',
			'wp_clone_page'              => 'content',
			'wp_get_page_by_slug'        => 'content',
			'wp_set_featured_image'      => 'content',
			'wp_list_drafts'             => 'content',
			'wp_delete_all_drafts'       => 'content',
			'wp_batch_update'            => 'content',
			'wp_batch'                   => 'content',
			'wp_bulk_create_pages'       => 'content',
			'wp_bulk_create_posts'       => 'content',
			'wp_bulk_update_posts'       => 'content',
			'wp_bulk_update_pages'       => 'content',
			'wp_get_post_meta'           => 'content',
			'wp_set_post_meta'           => 'content',

			// Media
			'wp_list_media'              => 'media',
			'wp_upload_media'            => 'media',
			'wp_upload_media_from_url'   => 'media',
			'wp_bulk_upload_media'       => 'media',
			'wp_upload_media_b64'        => 'media',
			'wp_update_media'            => 'media',
			'wp_delete_media'            => 'media',
			'wp_screenshot_url'          => 'media',

			// Taxonomy
			'wp_list_categories'         => 'taxonomy',
			'wp_list_tags'               => 'taxonomy',
			'wp_create_term'             => 'taxonomy',
			'wp_update_term'             => 'taxonomy',
			'wp_delete_term'             => 'taxonomy',

			// Elementor — core read/write/edit
			'wp_get_elementor'           => 'elementor',
			'wp_get_elementor_bulk'      => 'elementor',
			'wp_get_elementor_summary'   => 'elementor',
			'wp_edit_section'            => 'elementor',
			'wp_add_section'             => 'elementor',
			'wp_remove_section'          => 'elementor',
			'wp_replace_section'         => 'elementor',
			'wp_patch_elementor'         => 'elementor',
			'wp_edit_widget'             => 'elementor',
			'wp_set_elementor'           => 'elementor',
			'wp_preview_elementor'       => 'elementor',
			'wp_bulk_find_replace'       => 'elementor',

			// Elementor kit CSS — global CSS injection, free tier (no Elementor Pro needed)
			'wp_get_kit_css'             => 'elementor',
			'wp_set_kit_css'             => 'elementor',
			'wp_list_kit_css_blocks'     => 'elementor',
			'wp_get_kit_css_block'       => 'elementor',
			'wp_upsert_kit_css_block'    => 'elementor',
			'wp_delete_kit_css_block'    => 'elementor',

			// Elementor Info — reference/status
			'wp_elementor_status'        => 'elementor-info',
			'wp_regenerate_elementor_css' => 'elementor-info',
			'wp_get_elementor_widgets'   => 'elementor-info',
			'wp_get_widget_schema'       => 'elementor-info',
			'wp_elementor_widget_help'   => 'elementor-info',

			// Gutenberg
			'wp_get_blocks'              => 'gutenberg',
			'wp_set_blocks'              => 'gutenberg',
			'wp_patch_block_section'     => 'gutenberg',
			'wp_list_block_types'        => 'gutenberg',
			'wp_list_block_patterns'     => 'gutenberg',

			// Block utilities — categorized 'site' to match their REST route
			// classification (/blocks/parse|serialize|validate|design-system),
			// so the disabled-category gate agrees between the MCP and REST layers.
			'wp_parse_blocks'            => 'site',
			'wp_serialize_blocks'        => 'site',
			'wp_validate_blocks'         => 'site',
			'wp_get_block_design_system' => 'site',

			'wp_list_approvals'          => 'admin',
			'wp_get_approval'            => 'admin',
			'wp_approve_request'         => 'admin',
			'wp_reject_request'          => 'admin',
			'wp_apply_approval'          => 'admin',
			'wp_rollback_approval'       => 'admin',

			// API Keys & Rate Limiting
			'wp_list_api_keys'           => 'admin',
			'wp_create_api_key'          => 'admin',
			'wp_revoke_api_key'          => 'admin',
			'wp_rate_limit_status'       => 'admin',
			'wp_update_rate_limit'       => 'admin',
			'wp_reset_rate_limit'        => 'admin',

			// Plugin Settings
			'wp_get_plugin_settings'     => 'admin',
			'wp_update_plugin_settings'  => 'admin',

			// Plugin Updates
			'wp_check_update'            => 'admin',
			'wp_trigger_update'          => 'admin',

			// Integrations
			'wp_integrations_status'     => 'admin',
			'wp_configure_integration'   => 'admin',
			'wp_test_integration'        => 'admin',
			'wp_remove_integration'      => 'admin',

			// Cloudflare
			'wp_cloudflare_status'              => 'admin',
			'wp_cloudflare_fix_mcp_access'       => 'admin',
			'wp_cloudflare_purge_cache'          => 'admin',
			'wp_cloudflare_get_speed_settings'   => 'admin',
			'wp_cloudflare_update_speed_setting' => 'admin',

			// Webhooks
			'wp_list_webhook_events'     => 'webhooks',
			'wp_get_event_schema'        => 'webhooks',
			'wp_list_mcp_events'         => 'webhooks',
			'wp_list_webhooks'           => 'webhooks',
			'wp_create_webhook'          => 'webhooks',
			'wp_update_webhook'          => 'webhooks',
			'wp_delete_webhook'          => 'webhooks',
			'wp_test_webhook'            => 'webhooks',
			'wp_list_webhook_logs'       => 'webhooks',

			// Feedback
			'wp_submit_feedback'         => 'admin',
			'wp_list_feedback'           => 'admin',

			// Guides & Workflows
			'wp_get_guide'               => 'site',
			'wp_get_workflow'            => 'site',
			'wp_get_agent_playbook'      => 'site',
			'wp_get_content_coherence_report' => 'seo',
			'wp_keyword_research'            => 'seo',

			// Site Memory (#362)
			'wp_remember'                => 'site',
			'wp_recall'                  => 'site',
			'wp_forget'                  => 'site',
			'wp_list_memories'           => 'site',

			// Signals (#363)
			'wp_get_signals'             => 'site',

			// Site Blueprints (#364)
			'wp_list_site_blueprints'    => 'site',
			'wp_get_site_blueprint'      => 'site',
			'wp_create_site_blueprint'   => 'site',
			'wp_deploy_site_blueprint'   => 'site',
			'wp_extract_site_blueprint'  => 'site',
		);

		// Remove custom CSS tool categories in WP.org build.
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			unset( $categories['wp_get_custom_css'], $categories['wp_set_custom_css'], $categories['wp_delete_custom_css'], $categories['wp_get_css_length'] );
		}

		return $categories;
	}

	/**
	 * Get required capabilities for free tools.
	 *
	 * @return array Map of tool_name => capability_key.
	 */
	public function get_required_capabilities() {
		return array(
			'wp_get_elementor'            => 'elementor',
			'wp_get_elementor_bulk'       => 'elementor',
			'wp_get_elementor_summary'    => 'elementor',
			'wp_edit_section'             => 'elementor',
			'wp_add_section'              => 'elementor',
			'wp_remove_section'           => 'elementor',
			'wp_replace_section'          => 'elementor',
			'wp_patch_elementor'          => 'elementor',
			'wp_edit_widget'              => 'elementor',
			'wp_set_elementor'            => 'elementor',
			'wp_elementor_status'         => 'elementor',
			'wp_regenerate_elementor_css' => 'elementor',
			'wp_bulk_find_replace'        => 'elementor',
			'wp_get_kit_css'              => 'elementor',
			'wp_set_kit_css'              => 'elementor',
			'wp_list_kit_css_blocks'      => 'elementor',
			'wp_get_kit_css_block'        => 'elementor',
			'wp_upsert_kit_css_block'     => 'elementor',
			'wp_delete_kit_css_block'     => 'elementor',
			'wp_get_elementor_widgets'    => 'elementor',
			'wp_get_widget_schema'        => 'elementor',
			'wp_preview_elementor'        => 'elementor',
			'wp_get_blocks'               => 'gutenberg',
			'wp_set_blocks'               => 'gutenberg',
			'wp_list_block_types'         => 'gutenberg',
			'wp_list_block_patterns'      => 'gutenberg',
		);
	}

	/**
	 * Get tool definitions for free tier.
	 *
	 * @return array Tool definitions.
	 */
	public function get_tools() {
		return array_merge(
			$this->get_site_analytics_tools(),
			$this->get_site_context_tools(),
			$this->get_site_memory_tools(),
			$this->get_site_signals_tools(),
			$this->get_site_blueprints_tools(),
			$this->get_posts_tools(),
			$this->get_pages_tools(),
			$this->get_media_tools(),
			$this->get_drafts_tools(),
			$this->get_elementor_basic_tools(),
			$this->get_api_keys_tools(),
			$this->get_rate_limiting_tools(),
			$this->get_plugin_settings_tools(),
			$this->get_plugin_updates_tools(),
			$this->get_integration_mgmt_tools(),
			$this->get_cloudflare_tools(),
			$this->get_webhooks_tools(),
			$this->get_feedback_tools(),
			$this->get_post_meta_tools(),
			$this->get_blocks_tools(),
			$this->get_option_mgmt_tools(),
			$this->get_bulk_create_pages_tools(),
			$this->get_bulk_create_posts_tools(),
			$this->get_bulk_update_posts_tools(),
			$this->get_bulk_update_pages_tools(),
			$this->get_taxonomy_tools(),
			$this->get_theme_info_tools(),
			$this->get_flush_permalinks_tools(),
			$this->get_site_health_tools(),
			$this->get_guides_workflows_tools(),
		);
	}

	/**
	 * Get tool map for free tier.
	 *
	 * @return array Tool mappings.
	 */
	public function get_tool_map() {
		$map = array(
			// Site & Analytics
			'wp_site_info'      => array(
				'method' => 'GET',
				'route'  => '/site-info',
			),
			'wp_introspect'     => array(
				'method' => 'GET',
				'route'  => '/introspect',
			),
			'wp_onboard'        => array(
				'method' => 'GET',
				'route'  => '/onboard',
			),
			'wp_analytics'      => array(
				'method' => 'GET',
				'route'  => '/analytics',
			),
			'wp_detect_plugins' => array(
				'method' => 'GET',
				'route'  => '/plugins',
			),
			'wp_get_options'    => array(
				'method' => 'GET',
				'route'  => '/options',
			),
			'wp_update_options' => array(
				'method' => 'POST',
				'route'  => '/options',
			),
			'wp_get_site_context' => array(
				'method' => 'GET',
				'route'  => '/site-context',
			),
			'wp_set_site_context' => array(
				'method' => 'POST',
				'route'  => '/site-context',
			),
			'wp_remember' => array(
				'method' => 'POST',
				'route'  => '/memory',
			),
			'wp_recall' => array(
				'method' => 'GET',
				'route'  => '/memory',
			),
			'wp_forget' => array(
				'method' => 'DELETE',
				'route'  => '/memory/{namespace}/{key}',
			),
			'wp_list_memories' => array(
				'method' => 'GET',
				'route'  => '/memory',
			),
			'wp_get_signals' => array(
				'method' => 'GET',
				'route'  => '/signals',
			),
			'wp_list_site_blueprints' => array(
				'method' => 'GET',
				'route'  => '/site-blueprints',
			),
			'wp_get_site_blueprint' => array(
				'method' => 'GET',
				'route'  => '/site-blueprints/{id}',
			),
			'wp_create_site_blueprint' => array(
				'method' => 'POST',
				'route'  => '/site-blueprints',
			),
			'wp_deploy_site_blueprint' => array(
				'method' => 'POST',
				'route'  => '/site-blueprints/{id}/deploy',
				'pin'    => false, // its 200 absorbs failed steps; a replay could hide them (#802).
			),
			'wp_extract_site_blueprint' => array(
				'method' => 'GET',
				'route'  => '/site-blueprints/extract',
			),
			'wp_get_site_state' => array(
				'method' => 'GET',
				'route'  => '/site-state',
			),
			'wp_get_content_graph' => array(
				'method' => 'GET',
				'route'  => '/content-graph',
			),
			'wp_suggest_internal_links' => array(
				'method' => 'GET',
				'route'  => '/content-graph/suggestions',
			),
			'wp_apply_internal_link' => array(
				'method' => 'POST',
				'route'  => '/content-graph/apply-link',
			),
			'wp_validate_internal_links' => array(
				'method' => 'GET',
				'route'  => '/content-graph/validate-links',
			),
			'wp_validate_seo_readiness' => array(
				'method' => 'GET',
				'route'  => '/seo/readiness/{id}',
			),
			'wp_validate_structured_data' => array(
				'method' => 'GET',
				'route'  => '/seo/structured-data/{id}',
			),
			'wp_audit_media_seo' => array(
				'method' => 'GET',
				'route'  => '/seo/media/{id}',
			),
			'wp_seo_audit_site' => array(
				'method' => 'GET',
				'route'  => '/seo/audit',
			),
			'wp_get_seo_issues' => array(
				'method' => 'GET',
				'route'  => '/seo/issues',
			),
			'wp_run_seo_autofix_plan' => array(
				'method' => 'GET',
				'route'  => '/seo/autofix-plan',
			),
			'wp_import_search_performance' => array(
				'method' => 'POST',
				'route'  => '/seo/search-performance/import',
			),
			'wp_get_seo_trends' => array(
				'method' => 'GET',
				'route'  => '/seo/search-performance',
			),
			'wp_seo_flags' => array(
				'method' => 'GET',
				'route'  => '/seo/flags',
			),
			'wp_get_woocommerce_seo_report' => array(
				'method' => 'GET',
				'route'  => '/seo/woocommerce',
			),
			'wp_audit_content_quality' => array(
				'method' => 'GET',
				'route'  => '/seo/content-quality/{id}',
			),
			'wp_keyword_research' => array(
				'method' => 'GET',
				'route'  => '/keyword-research',
			),
			'wp_get_custom_css' => array(
				'method' => 'GET',
				'route'  => '/custom-css',
			),
			'wp_set_custom_css' => array(
				'method' => 'POST',
				'route'  => '/custom-css',
			),
			'wp_delete_custom_css' => array(
				'method' => 'DELETE',
				'route'  => '/custom-css',
			),
			'wp_get_css_length' => array(
				'method' => 'GET',
				'route'  => '/custom-css/length',
			),
			'wp_get_rendered_html' => array(
				'method' => 'GET',
				'route'  => '/rendered-html',
			),
			'wp_get_rendered_copy' => array(
				'method' => 'GET',
				'route'  => '/rendered-copy',
			),
			'wp_list_menus'          => array(
				'method' => 'GET',
				'route'  => '/menus',
			),
			'wp_list_menu_locations' => array(
				'method' => 'GET',
				'route'  => '/menus/locations',
			),
			'wp_setup_menu'          => array(
				'method' => 'POST',
				'route'  => '/menus/setup',
			),
			'wp_list_menu_items'     => array(
				'method' => 'GET',
				'route'  => '/menus/{menu_id}/items',
			),
			'wp_add_menu_item'       => array(
				'method' => 'POST',
				'route'  => '/menus/{menu_id}/items',
			),
			'wp_update_menu_item'    => array(
				'method' => 'POST',
				'route'  => '/menus/{menu_id}/items/{item_id}',
			),
			'wp_delete_menu_item'    => array(
				'method' => 'DELETE',
				'route'  => '/menus/{menu_id}/items/{item_id}',
			),
			'wp_update_menu_item_auto' => array(
				'method' => 'POST',
				'route'  => '/menus/items/{item_id}',
			),
			'wp_delete_menu_item_auto' => array(
				'method' => 'DELETE',
				'route'  => '/menus/items/{item_id}',
			),
			'wp_reorder_menu_items'  => array(
				'method' => 'POST',
				'route'  => '/menus/{menu_id}/items/reorder',
			),
			'wp_delete_menu'         => array(
				'method' => 'DELETE',
				'route'  => '/menus/{menu_id}',
			),
			'wp_assign_menu_location' => array(
				'method' => 'POST',
				'route'  => '/menus/assign-location',
			),
			'wp_update_page_template' => array(
				'method' => 'POST',
				'route'  => '/pages/{id}/template',
			),
			'wp_list_page_templates'  => array(
				'method' => 'GET',
				'route'  => '/templates/page',
			),
			'wp_bulk_find_replace'   => array(
				'method' => 'POST',
				'route'  => '/elementor/find-replace',
			),
			'wp_get_kit_css'         => array(
				'method' => 'GET',
				'route'  => '/elementor/kit-css',
			),
			'wp_set_kit_css'         => array(
				'method' => 'POST',
				'route'  => '/elementor/kit-css',
			),
			'wp_list_kit_css_blocks' => array(
				'method' => 'GET',
				'route'  => '/elementor/kit-css/blocks',
			),
			'wp_get_kit_css_block'   => array(
				'method'        => 'GET',
				'route'         => '/elementor/kit-css/blocks/{name}',
				// Already 'name' everywhere including wp_list_kit_css_blocks'
				// output; 'block_id' accepted defensively (#831) since it is
				// the name issue #831 reported reaching for.
				'param_aliases' => array( 'block_id' => 'name' ),
			),
			'wp_upsert_kit_css_block' => array(
				'method' => 'POST',
				'route'  => '/elementor/kit-css/blocks',
			),
			'wp_delete_kit_css_block' => array(
				'method'        => 'DELETE',
				'route'         => '/elementor/kit-css/blocks/{name}',
				'param_aliases' => array( 'block_id' => 'name' ),
			),
			'wp_list_media'          => array(
				'method' => 'GET',
				'route'  => '/media',
			),
			'wp_list_content'   => array(
				'method' => 'GET',
				'route'  => '/content',
			),
			'wp_delete_content' => array(
				'method' => 'DELETE',
				'route'  => '/content/{post_type}/{id}',
			),
			'wp_search'         => array(
				'method'        => 'GET',
				'route'         => '/search',
				// REST endpoint param is still 'type' (#831) — canonical MCP
				// name is 'post_type', matching the majority of sibling
				// content tools (wp_list_posts, wp_create_post, ...).
				'param_remap'   => array( 'post_type' => 'type' ),
				'param_aliases' => array( 'type' => 'post_type' ),
			),
			'wp_fetch'          => array(
				'method'        => 'GET',
				'route'         => '/fetch',
				// Same rename as wp_search, for the same reason (#831 sweep).
				'param_remap'   => array( 'post_type' => 'type' ),
				'param_aliases' => array( 'type' => 'post_type' ),
			),

			// Posts
			'wp_list_posts'     => array(
				'method' => 'GET',
				'route'  => '/posts',
			),
			'wp_create_post'    => array(
				'method' => 'POST',
				'route'  => '/posts',
			),
			'wp_update_post'    => array(
				'method' => 'POST',
				'route'  => '/posts/{id}',
			),
			'wp_delete_post'    => array(
				'method' => 'DELETE',
				'route'  => '/posts/{id}',
			),

			// Pages
			'wp_list_pages'     => array(
				'method' => 'GET',
				'route'  => '/pages',
			),
			'wp_create_page'    => array(
				'method' => 'POST',
				'route'  => '/pages',
			),
			'wp_update_page'    => array(
				'method' => 'POST',
				'route'  => '/pages/{id}',
			),
			'wp_delete_page'    => array(
				'method' => 'DELETE',
				'route'  => '/pages/{id}',
			),
			'wp_clone_page'     => array(
				'method' => 'POST',
				'route'  => '/pages/{id}/clone',
			),
			'wp_get_page_by_slug' => array(
				'method' => 'GET',
				'route'  => '/pages/by-slug/{slug}',
			),
			'wp_set_featured_image' => array(
				'method' => 'POST',
				'route'  => '/posts/{id}/featured-image',
			),
			'wp_list_categories' => array(
				'method' => 'GET',
				'route'  => '/categories',
			),
			'wp_list_tags'       => array(
				'method' => 'GET',
				'route'  => '/tags',
			),
			'wp_batch_update'    => array(
				'method' => 'POST',
				'route'  => '/batch',
			),
			'wp_batch'           => array(
				'method' => 'POST',
				'route'  => '/batch',
			),

			// Media
			'wp_upload_media'          => array(
				'method' => 'POST',
				'route'  => '/media',
			),
			'wp_upload_media_from_url' => array(
				'method'        => 'POST',
				'route'         => '/media/from-url',
				// This plugin's convention is `alt` (WordPress's own REST
				// API calls the same field `alt_text`) — accept the WP-core
				// name as a deprecated alias across all media write tools
				// (#831 sweep).
				'param_aliases' => array( 'alt_text' => 'alt' ),
			),
			'wp_bulk_upload_media'     => array(
				'method' => 'POST',
				'route'  => '/media/bulk',
			),
			'wp_upload_media_b64'      => array(
				'method'        => 'POST',
				'route'         => '/media/from-base64',
				'param_aliases' => array(
					'base64'   => 'data',
					'alt_text' => 'alt',
				),
			),
			'wp_list_design_references' => array(
				'method' => 'GET',
				'route'  => '/design-references',
			),
			'wp_get_design_reference' => array(
				'method' => 'GET',
				'route'  => '/design-references/{id}',
			),
			'wp_upload_design_reference' => array(
				'method' => 'POST',
				'route'  => '/design-references',
			),
			'wp_update_design_reference' => array(
				'method' => 'POST',
				'route'  => '/design-references/{id}',
			),
			'wp_update_media'          => array(
				'method'        => 'POST',
				'route'         => '/media/{id}',
				'param_aliases' => array( 'alt_text' => 'alt' ),
			),
			'wp_delete_media'          => array(
				'method' => 'DELETE',
				'route'  => '/media/{id}',
			),

			// Drafts
			'wp_list_drafts'           => array(
				'method' => 'GET',
				'route'  => '/drafts',
			),
			'wp_delete_all_drafts'     => array(
				'method' => 'DELETE',
				'route'  => '/drafts/delete-all',
			),

			// Elementor Basic
			'wp_get_elementor'         => array(
				'method' => 'GET',
				'route'  => '/elementor/{id}',
			),
			'wp_get_elementor_bulk'    => array(
				'method' => 'GET',
				'route'  => '/elementor/bulk',
			),
			'wp_get_elementor_summary' => array(
				'method' => 'GET',
				'route'  => '/elementor/{id}/summary',
			),
			'wp_edit_section'          => array(
				'method' => 'POST',
				'route'  => '/elementor/{id}/edit-section',
			),
			'wp_add_section'           => array(
				'method'        => 'POST',
				// {page_id} was this tool's own placeholder token, not the
				// underlying REST route's URL param (which has always been
				// `id` — /elementor/(?P<id>\d+)/add-section). Renamed to
				// `id` for consistency with every sibling Elementor tool
				// that addresses one page (#831); `page_id` kept as a
				// deprecated alias below.
				'route'         => '/elementor/{id}/add-section',
				'param_aliases' => array( 'page_id' => 'id' ),
			),
			'wp_remove_section'        => array(
				'method'        => 'POST',
				'route'         => '/elementor/{id}/remove-section',
				'param_aliases' => array( 'page_id' => 'id' ),
			),
			'wp_replace_section'       => array(
				'method'        => 'POST',
				'route'         => '/elementor/{id}/replace-section',
				'param_aliases' => array( 'page_id' => 'id' ),
			),
			'wp_patch_elementor'       => array(
				'method'        => 'POST',
				'route'         => '/elementor/{id}/patch',
				'param_aliases' => array( 'page_id' => 'id' ),
			),
			'wp_edit_widget'           => array(
				'method'        => 'POST',
				'route'         => '/elementor/{id}/edit-widget',
				// The reported confusion (#831) was sending `page_id` where
				// this tool has always wanted `id` — same alias as its
				// siblings above, added for the same reason.
				'param_aliases' => array( 'page_id' => 'id' ),
			),
			'wp_set_elementor'         => array(
				'method' => 'POST',
				'route'  => '/elementor/{id}',
			),
			'wp_elementor_status'      => array(
				'method' => 'GET',
				'route'  => '/elementor/status',
			),
			'wp_regenerate_elementor_css'  => array(
				'method' => 'POST',
				'route'  => '/elementor/regenerate-css',
			),
			'wp_get_elementor_widgets'     => array(
				'method'        => 'GET',
				'route'         => '/elementor/widgets',
				// Canonical name matches the two sibling tools that already
				// use it (wp_get_widget_schema, wp_elementor_widget_help).
				// This was previously 'widget', which the REST handler this
				// bare route dispatches to never read (it reads 'type') —
				// so passing it silently fell back to listing every widget
				// instead of filtering (#831). param_remap fixes the
				// dispatch bug and the rename together.
				'param_remap'   => array( 'widget_type' => 'type' ),
				'param_aliases' => array( 'widget' => 'widget_type' ),
			),
			'wp_get_widget_schema'         => array(
				'method' => 'GET',
				'route'  => '/elementor/widgets/{widget_type}',
			),
			'wp_elementor_widget_help'     => array(
				'method' => 'GET',
				'route'  => '/elementor/widget-help/{widget_type}',
			),
			'wp_preview_elementor'         => array(
				'method' => 'GET',
				'route'  => '/elementor/{id}/preview',
			),
			'wp_screenshot_url'            => array(
				'method' => 'POST',
				'route'  => '/screenshot',
			),

			// API Keys
			'wp_list_api_keys'        => array(
				'method' => 'GET',
				'route'  => '/api-keys',
			),
			'wp_create_api_key'       => array(
				'method' => 'POST',
				'route'  => '/api-keys',
			),
			'wp_revoke_api_key'       => array(
				'method' => 'DELETE',
				'route'  => '/api-keys/{id}',
			),

			// Rate Limiting
			'wp_rate_limit_status'    => array(
				'method' => 'GET',
				'route'  => '/rate-limit',
			),
			'wp_update_rate_limit'    => array(
				'method' => 'POST',
				'route'  => '/rate-limit',
			),
			'wp_reset_rate_limit'     => array(
				'method' => 'POST',
				'route'  => '/rate-limit/reset',
			),

			// Plugin Settings
			'wp_get_plugin_settings'     => array(
				'method' => 'GET',
				'route'  => '/plugin-settings',
			),
			'wp_update_plugin_settings'  => array(
				'method' => 'PUT',
				'route'  => '/plugin-settings',
			),

			// Plugin Updates
			'wp_check_update'            => array(
				'method' => 'GET',
				'route'  => '/update',
			),
			'wp_trigger_update'          => array(
				'method' => 'POST',
				'route'  => '/update',
			),

			// Integrations
			'wp_integrations_status'     => array(
				'method' => 'GET',
				'route'  => '/integrations/status',
			),
			'wp_configure_integration'   => array(
				'method' => 'POST',
				'route'  => '/integrations/configure',
			),
			'wp_test_integration'        => array(
				'method' => 'POST',
				'route'  => '/integrations/test',
			),
			'wp_remove_integration'      => array(
				'method' => 'POST',
				'route'  => '/integrations/remove',
			),

			// Cloudflare
			'wp_cloudflare_status'              => array(
				'method' => 'GET',
				'route'  => '/cloudflare/status',
			),
			'wp_cloudflare_fix_mcp_access'       => array(
				'method' => 'POST',
				'route'  => '/cloudflare/fix-mcp-access',
			),
			'wp_cloudflare_purge_cache'          => array(
				'method' => 'POST',
				'route'  => '/cloudflare/purge-cache',
			),
			'wp_cloudflare_get_speed_settings'   => array(
				'method' => 'GET',
				'route'  => '/cloudflare/speed-settings',
			),
			'wp_cloudflare_update_speed_setting' => array(
				'method' => 'POST',
				'route'  => '/cloudflare/speed-settings',
			),

			// Webhooks
			'wp_list_webhook_events'  => array(
				'method' => 'GET',
				'route'  => '/webhooks/events',
			),
			'wp_get_event_schema'        => array(
				'method' => 'GET',
				'route'  => '/events/schema',
			),
			'wp_list_mcp_events'         => array(
				'method' => 'GET',
				'route'  => '/events',
			),
			'wp_list_webhooks'        => array(
				'method' => 'GET',
				'route'  => '/webhooks',
			),
			'wp_create_webhook'       => array(
				'method' => 'POST',
				'route'  => '/webhooks',
			),
			'wp_update_webhook'       => array(
				'method' => 'POST',
				'route'  => '/webhooks/{id}',
			),
			'wp_delete_webhook'       => array(
				'method' => 'DELETE',
				'route'  => '/webhooks/{id}',
			),
			'wp_test_webhook'         => array(
				'method' => 'POST',
				'route'  => '/webhooks/{id}/test',
			),
			'wp_list_webhook_logs'    => array(
				'method' => 'GET',
				'route'  => '/webhooks/{id}/logs',
			),

			// Gutenberg Blocks
			'wp_get_blocks'          => array(
				'method' => 'GET',
				'route'  => '/blocks/{id}',
			),
			'wp_set_blocks'          => array(
				'method' => 'POST',
				'route'  => '/blocks/{id}',
			),
			'wp_patch_block_section' => array(
				'method' => 'POST',
				'route'  => '/blocks/{id}/section',
			),
			'wp_list_block_types'    => array(
				'method' => 'GET',
				'route'  => '/block-types',
			),
			'wp_list_block_patterns' => array(
				'method' => 'GET',
				'route'  => '/block-patterns',
			),
			'wp_parse_blocks'        => array(
				'method' => 'POST',
				'route'  => '/blocks/parse',
			),
			'wp_serialize_blocks'    => array(
				'method' => 'POST',
				'route'  => '/blocks/serialize',
			),
			'wp_validate_blocks'     => array(
				'method' => 'POST',
				'route'  => '/blocks/validate',
			),
			'wp_get_block_design_system' => array(
				'method' => 'GET',
				'route'  => '/blocks/design-system',
			),
			'wp_list_approvals'     => array(
				'method' => 'GET',
				'route'  => '/approvals',
			),
			'wp_get_approval'       => array(
				'method' => 'GET',
				'route'  => '/approvals/{id}',
			),
			'wp_approve_request'    => array(
				'method' => 'POST',
				'route'  => '/approvals/{id}/approve',
			),
			'wp_reject_request'     => array(
				'method' => 'POST',
				'route'  => '/approvals/{id}/reject',
			),
			'wp_apply_approval'     => array(
				'method' => 'POST',
				'route'  => '/approvals/{id}/apply',
			),
			'wp_rollback_approval'  => array(
				'method' => 'POST',
				'route'  => '/approvals/{id}/rollback',
			),

			// Post Meta
			'wp_get_post_meta'   => array(
				'method' => 'GET',
				'route'  => '/post-meta/{id}',
			),
			'wp_set_post_meta'   => array(
				'method' => 'POST',
				'route'  => '/post-meta/{id}',
			),

			// Option Management
			'wp_get_option'      => array(
				'method'        => 'GET',
				'route'         => '/option',
				// 'name' accepted defensively (#831) — the obvious guess for
				// "which WordPress option", and the actual name WP core's
				// own get_option()/update_option() functions use.
				'param_aliases' => array( 'name' => 'key' ),
			),
			'wp_update_option'   => array(
				'method'        => 'POST',
				'route'         => '/option',
				'param_aliases' => array( 'name' => 'key' ),
			),

			// Feedback
			'wp_submit_feedback'     => array(
				'method'        => 'POST',
				'route'         => '/feedback',
				'param_aliases' => array( 'message' => 'description' ),
			),
			'wp_list_feedback'       => array(
				'method' => 'GET',
				'route'  => '/feedback',
			),

			// Bulk Pages
			'wp_bulk_create_pages'   => array(
				'method' => 'POST',
				'route'  => '/pages/bulk',
			),

			// Bulk Posts
			'wp_bulk_create_posts'   => array(
				'method' => 'POST',
				'route'  => '/posts/bulk',
			),
			'wp_bulk_update_posts'   => array(
				'method' => 'PUT',
				'route'  => '/posts/bulk',
			),
			'wp_bulk_update_pages'   => array(
				'method' => 'PUT',
				'route'  => '/pages/bulk',
			),

			// Taxonomy Management
			'wp_create_term'         => array(
				'method' => 'POST',
				'route'  => '/terms',
			),
			'wp_update_term'         => array(
				'method' => 'POST',
				'route'  => '/terms/{id}',
			),
			'wp_delete_term'         => array(
				'method' => 'DELETE',
				'route'  => '/terms/{id}',
			),

			// Theme & Site Utilities
			'wp_get_theme_info'      => array(
				'method' => 'GET',
				'route'  => '/theme-info',
			),
			'wp_flush_permalinks'    => array(
				'method' => 'POST',
				'route'  => '/flush-permalinks',
			),
			'wp_get_site_health'     => array(
				'method' => 'GET',
				'route'  => '/site-health',
			),

			// Guides & Workflows
			'wp_get_guide'           => array(
				'method' => 'GET',
				'route'  => '/guides',
			),
			'wp_get_workflow'        => array(
				'method' => 'GET',
				'route'  => '/workflows/{name}',
			),
			'wp_get_agent_playbook'  => array(
				'method' => 'GET',
				'route'  => '/agent-playbooks',
			),
			'wp_get_content_coherence_report' => array(
				'method' => 'GET',
				'route'  => '/content-coherence',
			),
		);

		// Remove custom CSS tools in WP.org build.
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			unset( $map['wp_get_custom_css'], $map['wp_set_custom_css'], $map['wp_delete_custom_css'], $map['wp_get_css_length'] );
		}

		return $map;
	}
}
