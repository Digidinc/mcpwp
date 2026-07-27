<?php
/**
 * MCP Pro Tools Registry
 *
 * Contains all pro tier MCP tool definitions and route mappings.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro tools registry for MCP.
 *
 * Provides tool definitions and route mappings for pro tier tools.
 */
class Mcpwp_MCP_Pro_Tools extends Mcpwp_MCP_Tool_Registry {

	use Mcpwp_Pro_Tools_Seo_Trait;
	use Mcpwp_Pro_Tools_Forms_Trait;
	use Mcpwp_Pro_Tools_Elementor_Trait;
	use Mcpwp_Pro_Tools_Menus_Trait;
	use Mcpwp_Pro_Tools_Commerce_Trait;
	use Mcpwp_Pro_Tools_Network_Trait;
	use Mcpwp_Pro_Tools_Staging_Trait;

	/**
	 * Get destructive tool names for pro tier.
	 *
	 * @return array Destructive tool names.
	 */
	protected function get_destructive_tools() {
		return array(
			'wp_delete_widget',
			'wc_delete_product',
			'wp_delete_course_category',
			'wp_push_to_live',
			// Replace all existing Elementor content on the target page (#637).
			'wp_apply_elementor_template',
			'wp_apply_elementor_archetype',
			'wp_apply_elementor_part',
		);
	}

	/**
	 * Get open world tool names for pro tier.
	 *
	 * @return array Open world tool names.
	 */
	protected function get_open_world_tools() {
		return array();
	}

	/**
	 * Get tool category mappings for pro tier.
	 *
	 * @return array Map of tool_name => category_slug.
	 */
	public function get_tool_categories() {
		return array(
			// Google Indexing
			'wp_submit_to_google_index'          => 'seo',
			'wp_google_index_status'             => 'seo',

			// Multilanguage
			'wp_languages'                      => 'content',
			'wp_set_language'                    => 'content',
			'wp_get_translations'                => 'content',
			'wp_create_translation'              => 'content',

			// SEO
			'wp_get_seo'                         => 'seo',
			'wp_set_seo'                         => 'seo',
			'wp_analyze_seo'                     => 'seo',
			'wp_bulk_seo'                        => 'seo',
			'wp_seo_status'                      => 'seo',
			'wp_set_noindex'                     => 'seo',
			'wp_seo_scan'                        => 'seo',
			'wp_seo_report'                      => 'seo',
			'wp_seo_visibility'                  => 'seo',
			'wp_refresh_seo_visibility'          => 'seo',
			'wp_seo_opportunities'               => 'seo',

			// Forms
			'wp_list_forms'                      => 'forms',
			'wp_get_form'                        => 'forms',
			'wp_get_form_entries'                => 'forms',
			'wp_forms_status'                    => 'forms',

			// Elementor Templates — templates, archetypes, reusable parts
			'wp_list_elementor_templates'        => 'elementor-templates',
			'wp_get_elementor_template'          => 'elementor-templates',
			'wp_create_elementor_template'       => 'elementor-templates',
			'wp_update_elementor_template'       => 'elementor-templates',
			'wp_delete_elementor_template'       => 'elementor-templates',
			'wp_apply_elementor_template'        => 'elementor-templates',
			'wp_list_elementor_archetypes'       => 'elementor-templates',
			'wp_get_elementor_archetype'         => 'elementor-templates',
			'wp_create_elementor_archetype'      => 'elementor-templates',
			'wp_apply_elementor_archetype'       => 'elementor-templates',
			'wp_list_elementor_parts'            => 'elementor-templates',
			'wp_get_elementor_part'              => 'elementor-templates',
			'wp_create_elementor_part'           => 'elementor-templates',
			'wp_create_elementor_part_from_section' => 'elementor-templates',
			'wp_apply_elementor_part'            => 'elementor-templates',

			// Elementor Build — page building tools
			'wp_create_landing_page'             => 'elementor-build',
			'wp_clone_elementor_page'            => 'elementor-build',
			'wp_get_elementor_globals'           => 'elementor-build',
			'wp_set_elementor_globals'           => 'elementor-build',
			'wp_build_page'                      => 'elementor-build',
			'wp_list_blueprints'                 => 'elementor-build',
			'wp_get_blueprint'                   => 'elementor-build',
			'wp_save_section_as_template'        => 'elementor-build',

			// Elementor Theme — theme builder + custom code
			'wp_list_elementor_custom_code'      => 'elementor-theme',
			'wp_get_elementor_custom_code'       => 'elementor-theme',
			'wp_create_elementor_custom_code'    => 'elementor-theme',
			'wp_update_elementor_custom_code'    => 'elementor-theme',
			'wp_disable_elementor_custom_code'   => 'elementor-theme',
			'wp_enable_elementor_custom_code'    => 'elementor-theme',
			'wp_sanitize_elementor_custom_code'  => 'elementor-theme',
			'wp_theme_builder_status'            => 'elementor-theme',
			'wp_list_theme_templates'            => 'elementor-theme',
			'wp_get_theme_template'              => 'elementor-theme',
			'wp_set_template_conditions'         => 'elementor-theme',
			'wp_assign_template'                 => 'elementor-theme',
			'wp_create_theme_template'           => 'elementor-theme',

			// Menu Management (Pro)
			'wp_get_menu'                        => 'site',
			'wp_create_menu'                     => 'site',
			'wp_update_menu'                     => 'site',

			// WooCommerce
			'wc_status'                          => 'woocommerce',
			'wc_list_products'                   => 'woocommerce',
			'wc_get_product'                     => 'woocommerce',
			'wc_list_product_archetypes'         => 'woocommerce',
			'wc_get_product_archetype'           => 'woocommerce',
			'wc_create_product_archetype'        => 'woocommerce',
			'wc_apply_product_archetype'         => 'woocommerce',
			'wc_create_product'                  => 'woocommerce',
			'wc_update_product'                  => 'woocommerce',
			'wc_delete_product'                  => 'woocommerce',
			'wc_list_product_categories'         => 'woocommerce',
			'wc_create_product_category'         => 'woocommerce',
			'wc_update_product_category'         => 'woocommerce',
			'wc_list_product_tags'               => 'woocommerce',
			'wc_list_orders'                     => 'woocommerce',
			'wc_get_order'                       => 'woocommerce',
			'wc_update_order'                    => 'woocommerce',
			'wc_list_order_statuses'             => 'woocommerce',
			'wc_list_customers'                  => 'woocommerce',
			'wc_get_customer'                    => 'woocommerce',
			'wc_analytics'                       => 'woocommerce',

			// Cache
			'wp_flush_cache'                     => 'site',

			// Widgets & Sidebars
			'wp_list_sidebars'                   => 'site',
			'wp_get_sidebar'                     => 'site',
			'wp_get_sidebar_widgets'             => 'site',
			'wp_get_widget_types'                => 'site',
			'wp_get_widget'                      => 'site',
			'wp_add_widget'                      => 'site',
			'wp_update_widget'                   => 'site',
			'wp_delete_widget'                   => 'site',
			'wp_move_widget'                     => 'site',
			'wp_reorder_widgets'                 => 'site',

			// LearnPress LMS
			'wp_list_courses'                    => 'learnpress',
			'wp_get_course'                      => 'learnpress',
			'wp_create_course'                   => 'learnpress',
			'wp_update_course'                   => 'learnpress',
			'wp_get_curriculum'                  => 'learnpress',
			'wp_set_curriculum'                  => 'learnpress',
			'wp_list_lessons'                    => 'learnpress',
			'wp_create_lesson'                   => 'learnpress',
			'wp_update_lesson'                   => 'learnpress',
			'wp_list_quizzes'                    => 'learnpress',
			'wp_create_quiz'                     => 'learnpress',
			'wp_update_quiz'                     => 'learnpress',
			'wp_get_quiz_questions'              => 'learnpress',
			'wp_list_course_categories'          => 'learnpress',
			'wp_create_course_category'          => 'learnpress',
			'wp_update_course_category'          => 'learnpress',
			'wp_delete_course_category'          => 'learnpress',
			'wp_lms_stats'                       => 'learnpress',

			// Events
			'wp_list_events'                     => 'events',
			'wp_get_event'                       => 'events',
			'wp_create_event'                    => 'events',
			'wp_update_event'                    => 'events',

			// Multisite
			'wp_network_sites'                   => 'multisite',
			'wp_network_switch'                  => 'multisite',
			'wp_network_stats'                   => 'multisite',

			// Staging
			'wp_get_staging_info'                => 'staging',
			'wp_push_to_staging'                 => 'staging',
			'wp_push_to_live'                    => 'staging',
			'wp_get_staging_status'              => 'staging',
		);
	}

	/**
	 * Get required capabilities for pro tools.
	 *
	 * @return array Map of tool_name => capability_key.
	 */
	public function get_required_capabilities() {
		return array(
			// SEO tools — any SEO plugin.
			'wp_get_seo'            => 'seo',
			'wp_set_seo'            => 'seo',
			'wp_analyze_seo'        => 'seo',
			'wp_bulk_seo'           => 'seo',
			'wp_seo_status'         => 'seo',
			'wp_set_noindex'        => 'seo',
			'wp_seo_scan'           => 'seo',
			'wp_seo_report'         => 'seo',
			// Forms tools — any forms plugin.
			'wp_list_forms'         => 'forms',
			'wp_get_form'           => 'forms',
			'wp_get_form_entries'   => 'forms',
			'wp_forms_status'       => 'forms',
			// Elementor Pro tools.
			'wp_list_elementor_templates'         => 'elementor',
			'wp_get_elementor_template'           => 'elementor',
			'wp_create_elementor_template'        => 'elementor',
			'wp_update_elementor_template'        => 'elementor',
			'wp_delete_elementor_template'        => 'elementor',
			'wp_apply_elementor_template'         => 'elementor',
			'wp_list_elementor_archetypes'        => 'elementor',
			'wp_get_elementor_archetype'          => 'elementor',
			'wp_create_elementor_archetype'       => 'elementor',
			'wp_apply_elementor_archetype'        => 'elementor',
			'wp_list_elementor_parts'             => 'elementor',
			'wp_get_elementor_part'               => 'elementor',
			'wp_create_elementor_part'            => 'elementor',
			'wp_create_elementor_part_from_section' => 'elementor',
			'wp_apply_elementor_part'             => 'elementor',
			'wp_create_landing_page'              => 'elementor',
			'wp_clone_elementor_page'             => 'elementor',
			'wp_get_elementor_globals'            => 'elementor',
			'wp_set_elementor_globals'            => 'elementor',
			'wp_list_elementor_custom_code'       => 'elementor_pro',
			'wp_get_elementor_custom_code'        => 'elementor_pro',
			'wp_create_elementor_custom_code'     => 'elementor_pro',
			'wp_update_elementor_custom_code'     => 'elementor_pro',
			'wp_disable_elementor_custom_code'    => 'elementor_pro',
			'wp_enable_elementor_custom_code'     => 'elementor_pro',
			'wp_sanitize_elementor_custom_code'   => 'elementor_pro',
			// WooCommerce tools.
			'wc_status'                          => 'woocommerce',
			'wc_list_products'                   => 'woocommerce',
			'wc_get_product'                     => 'woocommerce',
			'wc_list_product_archetypes'         => 'woocommerce',
			'wc_get_product_archetype'           => 'woocommerce',
			'wc_create_product_archetype'        => 'woocommerce',
			'wc_apply_product_archetype'         => 'woocommerce',
			'wc_create_product'                  => 'woocommerce',
			'wc_update_product'                  => 'woocommerce',
			'wc_delete_product'                  => 'woocommerce',
			'wc_list_product_categories'         => 'woocommerce',
			'wc_create_product_category'         => 'woocommerce',
			'wc_update_product_category'         => 'woocommerce',
			'wc_list_product_tags'               => 'woocommerce',
			'wc_list_orders'                     => 'woocommerce',
			'wc_get_order'                       => 'woocommerce',
			'wc_update_order'                    => 'woocommerce',
			'wc_list_order_statuses'             => 'woocommerce',
			'wc_list_customers'                  => 'woocommerce',
			'wc_get_customer'                    => 'woocommerce',
			'wc_analytics'                       => 'woocommerce',
			// Theme Builder tools.
			'wp_theme_builder_status'             => 'elementor',
			'wp_list_theme_templates'             => 'elementor',
			'wp_get_theme_template'               => 'elementor',
			'wp_set_template_conditions'          => 'elementor',
			'wp_assign_template'                  => 'elementor',
			'wp_create_theme_template'            => 'elementor',
			'wp_build_page'                       => 'elementor',
			'wp_list_blueprints'                  => 'elementor',
			'wp_get_blueprint'                    => 'elementor',
			'wp_save_section_as_template'         => 'elementor',
			// LearnPress tools.
			'wp_list_courses'                    => 'learnpress',
			'wp_get_course'                      => 'learnpress',
			'wp_create_course'                   => 'learnpress',
			'wp_update_course'                   => 'learnpress',
			'wp_get_curriculum'                  => 'learnpress',
			'wp_set_curriculum'                  => 'learnpress',
			'wp_list_lessons'                    => 'learnpress',
			'wp_create_lesson'                   => 'learnpress',
			'wp_update_lesson'                   => 'learnpress',
			'wp_list_quizzes'                    => 'learnpress',
			'wp_create_quiz'                     => 'learnpress',
			'wp_update_quiz'                     => 'learnpress',
			'wp_get_quiz_questions'              => 'learnpress',
			'wp_list_course_categories'          => 'learnpress',
			'wp_create_course_category'          => 'learnpress',
			'wp_update_course_category'          => 'learnpress',
			'wp_delete_course_category'          => 'learnpress',
			'wp_lms_stats'                       => 'learnpress',
			// Events tools.
			'wp_list_events'                     => 'tp_events',
			'wp_get_event'                       => 'tp_events',
			'wp_create_event'                    => 'tp_events',
			'wp_update_event'                    => 'tp_events',
			// Multisite tools.
			'wp_network_sites'                   => 'multisite',
			'wp_network_switch'                  => 'multisite',
			'wp_network_stats'                   => 'multisite',
		);
	}

	/**
	 * Get tool definitions for pro tier.
	 *
	 * @return array Tool definitions.
	 */
	public function get_tools() {
		return array_merge(
			$this->get_google_indexing_pro_tools(),
			$this->get_multilanguage_pro_tools(),
			$this->get_seo_pro_tools(),
			$this->get_forms_pro_tools(),
			$this->get_elementor_pro_pro_tools(),
			$this->get_theme_builder_pro_tools(),
			$this->get_menus_pro_pro_tools(),
			$this->get_widgets_sidebar_pro_tools(),
			$this->get_woocommerce_pro_tools(),
			$this->get_learnpress_pro_tools(),
			$this->get_tp_events_pro_tools(),
			$this->get_multisite_pro_tools(),
			$this->get_cache_pro_tools(),
			$this->get_staging_pro_tools(),
		);
	}

	/**
	 * Cache flush tool.
	 *
	 * @return array
	 */
	private function get_cache_pro_tools() {
		return array(
			$this->define_tool(
				'wp_flush_cache',
				'Flush the WordPress page cache after editing content. Call after bulk edits to ensure the public site serves fresh content. Triggers wp_cache_flush() plus known plugin-specific cache APIs (W3TC, WP Super Cache, LiteSpeed, WP Rocket, Cache Enabler).',
				array(
					'url' => array(
						'type'        => 'string',
						'description' => 'Optional URL to purge. Omit to flush the entire site cache.',
					),
				)
			),
		);
	}

	/**
	 * Get tool map for pro tier.
	 *
	 * @return array Tool mappings.
	 */
	public function get_tool_map() {
		return array(
			// SEO
			'wp_get_seo'                     => array(
				'method' => 'GET',
				'route'  => '/seo/{id}',
			),
			'wp_set_seo'                     => array(
				'method' => 'POST',
				'route'  => '/seo/{id}',
				'param_remap' => array(
					'seo_title'       => 'title',
					'seo_description' => 'description',
					'canonical_url'   => 'canonical',
				),
			),
			'wp_analyze_seo'                 => array(
				'method' => 'GET',
				'route'  => '/seo/{id}/analyze',
			),
			'wp_bulk_seo'                    => array(
				'method' => 'POST',
				'route'  => '/seo/bulk',
				'param_remap' => array(
					'items' => 'updates',
				),
			),
			'wp_seo_status'                  => array(
				'method' => 'GET',
				'route'  => '/seo/status',
			),
			'wp_set_noindex'                 => array(
				'method' => 'POST',
				'route'  => '/seo/{id}/noindex',
			),
			'wp_seo_scan'                    => array(
				'method' => 'GET',
				'route'  => '/seo/scan',
			),
			'wp_seo_report'                  => array(
				'method' => 'GET',
				'route'  => '/seo/report',
			),
			'wp_seo_visibility'         => array(
				'method' => 'GET',
				'route'  => '/seo/visibility',
			),
			'wp_refresh_seo_visibility' => array(
				'method' => 'POST',
				'route'  => '/seo/visibility/refresh',
			),
			'wp_seo_opportunities'      => array(
				'method' => 'GET',
				'route'  => '/seo/opportunities',
			),

			// Forms
			'wp_list_forms'                  => array(
				'method' => 'GET',
				'route'  => '/forms',
			),
			'wp_get_form'                    => array(
				'method' => 'GET',
				'route'  => '/forms/{plugin}/{id}',
			),
			'wp_get_form_entries'            => array(
				'method' => 'GET',
				'route'  => '/forms/{plugin}/{id}/entries',
			),
			'wp_forms_status'                => array(
				'method' => 'GET',
				'route'  => '/forms/status',
			),

			// Elementor Pro
			'wp_list_elementor_templates'    => array(
				'method' => 'GET',
				'route'  => '/elementor/templates',
			),
			'wp_get_elementor_template'      => array(
				'method' => 'GET',
				'route'  => '/elementor/templates/{id}',
			),
			'wp_create_elementor_template'   => array(
				'method' => 'POST',
				'route'  => '/elementor/templates',
			),
			'wp_update_elementor_template'   => array(
				'method' => 'POST',
				'route'  => '/elementor/templates/{id}',
			),
			'wp_delete_elementor_template'   => array(
				'method' => 'DELETE',
				'route'  => '/elementor/templates/{id}',
			),
			'wp_apply_elementor_template'    => array(
				'method' => 'POST',
				'route'  => '/elementor/templates/{template_id}/apply',
			),
			'wp_list_elementor_archetypes'   => array(
				'method'      => 'GET',
				'route'       => '/elementor/archetypes',
				// Accept wp_create_elementor_archetype's param names as aliases (#634).
				'param_remap' => array(
					'archetype_scope' => 'scope',
					'archetype_style' => 'style',
				),
			),
			'wp_get_elementor_archetype'     => array(
				'method' => 'GET',
				'route'  => '/elementor/archetypes/{id}',
			),
			'wp_create_elementor_archetype'  => array(
				'method' => 'POST',
				'route'  => '/elementor/archetypes',
			),
			'wp_apply_elementor_archetype'   => array(
				'method' => 'POST',
				'route'  => '/elementor/archetypes/{id}/apply',
			),
			'wp_list_elementor_parts'        => array(
				'method'      => 'GET',
				'route'       => '/elementor/parts',
				// Accept wp_create_elementor_part's param names as aliases (#634).
				// part_tags -> tag is NOT remapped here (array vs scalar) — the
				// REST handler itself reduces part_tags to a single tag.
				'param_remap' => array(
					'part_kind'  => 'kind',
					'part_style' => 'style',
				),
			),
			'wp_get_elementor_part'          => array(
				'method' => 'GET',
				'route'  => '/elementor/parts/{id}',
			),
			'wp_create_elementor_part'       => array(
				'method' => 'POST',
				'route'  => '/elementor/parts',
			),
			'wp_create_elementor_part_from_section' => array(
				'method' => 'POST',
				'route'  => '/elementor/parts/from-section',
			),
			'wp_apply_elementor_part'        => array(
				'method' => 'POST',
				'route'  => '/elementor/parts/{id}/apply',
			),
			'wp_create_landing_page'         => array(
				'method' => 'POST',
				'route'  => '/elementor/landing-page',
			),
			'wp_clone_elementor_page'        => array(
				'method' => 'POST',
				'route'  => '/elementor/clone',
			),
			'wp_build_page'                  => array(
				'method' => 'POST',
				'route'  => '/elementor/build-page',
			),
			'wp_list_blueprints'             => array(
				'method' => 'GET',
				'route'  => '/elementor/blueprints',
			),
			'wp_get_blueprint'               => array(
				'method' => 'POST',
				'route'  => '/elementor/blueprints/build',
			),
			'wp_save_section_as_template'    => array(
				'method' => 'POST',
				'route'  => '/elementor/save-section-as-template',
			),
			'wp_get_elementor_globals'       => array(
				'method' => 'GET',
				'route'  => '/elementor/globals',
			),
			'wp_set_elementor_globals'       => array(
				'method' => 'POST',
				'route'  => '/elementor/globals',
			),
			'wp_list_elementor_custom_code'  => array(
				'method' => 'GET',
				'route'  => '/elementor/custom-code',
			),
			'wp_get_elementor_custom_code' => array(
				'method' => 'GET',
				'route'  => '/elementor/custom-code/{id}',
			),
			'wp_create_elementor_custom_code' => array(
				'method' => 'POST',
				'route'  => '/elementor/custom-code',
			),
			'wp_update_elementor_custom_code' => array(
				'method' => 'POST',
				'route'  => '/elementor/custom-code/{id}',
			),
			'wp_disable_elementor_custom_code' => array(
				'method' => 'POST',
				'route'  => '/elementor/custom-code/{id}/disable',
			),
			'wp_enable_elementor_custom_code' => array(
				'method' => 'POST',
				'route'  => '/elementor/custom-code/{id}/enable',
			),
			'wp_sanitize_elementor_custom_code' => array(
				'method' => 'POST',
				'route'  => '/elementor/custom-code/{id}/sanitize',
			),

			// Theme Builder
			'wp_theme_builder_status'      => array(
				'method' => 'GET',
				'route'  => '/theme-builder/status',
			),
			'wp_list_theme_templates'      => array(
				'method' => 'GET',
				'route'  => '/theme-builder/templates',
			),
			'wp_get_theme_template'        => array(
				'method' => 'GET',
				'route'  => '/theme-builder/templates/{id}',
			),
			'wp_set_template_conditions'   => array(
				'method' => 'POST',
				'route'  => '/theme-builder/templates/{id}/conditions',
			),
			'wp_assign_template'           => array(
				'method' => 'POST',
				'route'  => '/theme-builder/templates/{id}/assign',
			),
			'wp_create_theme_template'     => array(
				'method' => 'POST',
				'route'  => '/theme-builder/templates',
			),

			// Menu Management (Pro)
			'wp_get_menu'           => array(
				'method' => 'GET',
				'route'  => '/menus/{id}',
			),
			'wp_create_menu'        => array(
				'method' => 'POST',
				'route'  => '/menus',
			),
			'wp_update_menu'        => array(
				'method' => 'POST',
				'route'  => '/menus/{id}',
			),

			// Widgets & Sidebars
			'wp_list_sidebars'      => array(
				'method' => 'GET',
				'route'  => '/sidebars',
			),
			'wp_get_sidebar'        => array(
				'method' => 'GET',
				'route'  => '/sidebars/{id}',
			),
			'wp_get_sidebar_widgets' => array(
				'method' => 'GET',
				'route'  => '/sidebars/{id}/widgets',
			),
			'wp_get_widget_types'   => array(
				'method' => 'GET',
				'route'  => '/widgets/types',
			),
			'wp_get_widget'         => array(
				'method' => 'GET',
				'route'  => '/widgets/{id}',
			),
			'wp_add_widget'         => array(
				'method' => 'POST',
				'route'  => '/sidebars/{id}/widgets',
			),
			'wp_update_widget'      => array(
				'method' => 'PUT',
				'route'  => '/widgets/{id}',
			),
			'wp_delete_widget'      => array(
				'method' => 'DELETE',
				'route'  => '/widgets/{id}',
			),
			'wp_move_widget'        => array(
				'method' => 'POST',
				'route'  => '/widgets/{id}/move',
			),
			'wp_reorder_widgets'    => array(
				'method' => 'POST',
				'route'  => '/sidebars/{id}/reorder',
			),

			// WooCommerce
			'wc_status'                  => array(
				'method' => 'GET',
				'route'  => '/woocommerce/status',
			),
			'wc_list_products'           => array(
				'method' => 'GET',
				'route'  => '/woocommerce/products',
			),
			'wc_get_product'             => array(
				'method' => 'GET',
				'route'  => '/woocommerce/products/{id}',
			),
			'wc_list_product_archetypes' => array(
				'method' => 'GET',
				'route'  => '/woocommerce/archetypes',
			),
			'wc_get_product_archetype'   => array(
				'method' => 'GET',
				'route'  => '/woocommerce/archetypes/{id}',
			),
			'wc_create_product_archetype' => array(
				'method' => 'POST',
				'route'  => '/woocommerce/archetypes',
			),
			'wc_apply_product_archetype' => array(
				'method' => 'POST',
				'route'  => '/woocommerce/archetypes/{id}/apply',
			),
			'wc_create_product'          => array(
				'method' => 'POST',
				'route'  => '/woocommerce/products',
			),
			'wc_update_product'          => array(
				'method' => 'PUT',
				'route'  => '/woocommerce/products/{id}',
			),
			'wc_delete_product'          => array(
				'method' => 'DELETE',
				'route'  => '/woocommerce/products/{id}',
			),
			'wc_list_product_categories' => array(
				'method' => 'GET',
				'route'  => '/woocommerce/products/categories',
			),
			'wc_create_product_category' => array(
				'method' => 'POST',
				'route'  => '/woocommerce/products/categories',
			),
			'wc_update_product_category' => array(
				'method' => 'PUT',
				'route'  => '/woocommerce/products/categories/{id}',
			),
			'wc_list_product_tags'       => array(
				'method' => 'GET',
				'route'  => '/woocommerce/products/tags',
			),
			'wc_list_orders'             => array(
				'method' => 'GET',
				'route'  => '/woocommerce/orders',
			),
			'wc_get_order'               => array(
				'method' => 'GET',
				'route'  => '/woocommerce/orders/{id}',
			),
			'wc_update_order'            => array(
				'method' => 'PUT',
				'route'  => '/woocommerce/orders/{id}',
			),
			'wc_list_order_statuses'     => array(
				'method' => 'GET',
				'route'  => '/woocommerce/orders/statuses',
			),
			'wc_list_customers'          => array(
				'method' => 'GET',
				'route'  => '/woocommerce/customers',
			),
			'wc_get_customer'            => array(
				'method' => 'GET',
				'route'  => '/woocommerce/customers/{id}',
			),
			'wc_analytics'               => array(
				'method' => 'GET',
				'route'  => '/woocommerce/analytics',
			),

			// Google Indexing
			'wp_submit_to_google_index'  => array(
				'method' => 'POST',
				'route'  => '/google-indexing/submit',
			),
			'wp_google_index_status'     => array(
				'method' => 'GET',
				'route'  => '/google-indexing/status',
			),

			// Multilanguage (map these at the end after Widgets)
			'wp_languages'       => array(
				'method' => 'GET',
				'route'  => '/languages',
			),
			'wp_set_language'     => array(
				'method' => 'POST',
				'route'  => '/languages/current',
			),
			'wp_get_translations' => array(
				'method' => 'GET',
				'route'  => '/{type}s/{id}/translations',
			),
			'wp_create_translation' => array(
				'method' => 'POST',
				'route'  => '/{type}s/{id}/translations',
			),

			// LearnPress LMS
			'wp_list_courses'            => array(
				'method' => 'GET',
				'route'  => '/learnpress/courses',
			),
			'wp_get_course'              => array(
				'method' => 'GET',
				'route'  => '/learnpress/courses/{id}',
			),
			'wp_create_course'           => array(
				'method' => 'POST',
				'route'  => '/learnpress/courses',
			),
			'wp_update_course'           => array(
				'method' => 'PUT',
				'route'  => '/learnpress/courses/{id}',
			),
			'wp_get_curriculum'          => array(
				'method' => 'GET',
				'route'  => '/learnpress/courses/{id}/curriculum',
			),
			'wp_set_curriculum'          => array(
				'method' => 'PUT',
				'route'  => '/learnpress/courses/{id}/curriculum',
			),
			'wp_list_lessons'            => array(
				'method' => 'GET',
				'route'  => '/learnpress/lessons',
			),
			'wp_create_lesson'           => array(
				'method' => 'POST',
				'route'  => '/learnpress/lessons',
			),
			'wp_update_lesson'           => array(
				'method' => 'PUT',
				'route'  => '/learnpress/lessons/{id}',
			),
			'wp_list_quizzes'            => array(
				'method' => 'GET',
				'route'  => '/learnpress/quizzes',
			),
			'wp_create_quiz'             => array(
				'method' => 'POST',
				'route'  => '/learnpress/quizzes',
			),
			'wp_update_quiz'             => array(
				'method' => 'PUT',
				'route'  => '/learnpress/quizzes/{id}',
			),
			'wp_get_quiz_questions'      => array(
				'method' => 'GET',
				'route'  => '/learnpress/quizzes/{id}/questions',
			),
			'wp_list_course_categories'  => array(
				'method' => 'GET',
				'route'  => '/learnpress/categories',
			),
			'wp_create_course_category'  => array(
				'method' => 'POST',
				'route'  => '/learnpress/categories',
			),
			'wp_update_course_category'  => array(
				'method' => 'PUT',
				'route'  => '/learnpress/categories/{id}',
			),
			'wp_delete_course_category'  => array(
				'method' => 'DELETE',
				'route'  => '/learnpress/categories/{id}',
			),
			'wp_lms_stats'               => array(
				'method' => 'GET',
				'route'  => '/learnpress/stats',
			),

			// Events
			'wp_list_events'             => array(
				'method' => 'GET',
				'route'  => '/events',
			),
			'wp_get_event'               => array(
				'method' => 'GET',
				'route'  => '/events/{id}',
			),
			'wp_create_event'            => array(
				'method' => 'POST',
				'route'  => '/events',
			),
			'wp_update_event'            => array(
				'method' => 'PUT',
				'route'  => '/events/{id}',
			),

			// Multisite
			'wp_network_sites'           => array(
				'method' => 'GET',
				'route'  => '/network/sites',
			),
			'wp_network_switch'          => array(
				'method' => 'POST',
				'route'  => '/network/switch',
			),
			'wp_network_stats'           => array(
				'method' => 'GET',
				'route'  => '/network/stats',
			),

			// Cache
			'wp_flush_cache'             => array(
				'method' => 'POST',
				'route'  => '/cache-flush',
			),

			// Staging
			'wp_get_staging_info'        => array(
				'method' => 'GET',
				'route'  => '/staging',
			),
			'wp_push_to_staging'         => array(
				'method' => 'POST',
				'route'  => '/staging/push',
			),
			'wp_push_to_live'            => array(
				'method' => 'POST',
				'route'  => '/staging/push',
			),
			'wp_get_staging_status'      => array(
				'method' => 'GET',
				'route'  => '/staging/status/{operation_id}',
			),
		);
	}
}
