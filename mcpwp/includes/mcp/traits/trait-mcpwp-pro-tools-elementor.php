<?php
/**
 * Pro-tier tool definitions — elementor category group.
 *
 * Carved verbatim from Mcpwp_MCP_Pro_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Pro_Tools_Elementor_Trait {

	/**
	 * @return array
	 */
	private function get_elementor_pro_pro_tools() {
		$pro_tools = array();
		// Elementor Pro Tools
		$pro_tools[] = $this->define_tool(
			'wp_list_elementor_templates',
			'List all saved Elementor templates (page, section, header, footer, popup). Use to find template IDs before applying.',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_elementor_template',
			'Get a single Elementor template (Theme Builder template lives in elementor_library)',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Template post ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_elementor_template',
			'Create a new Elementor template (Theme Builder template lives in elementor_library)',
			array(
				'title'          => array(
					'type'        => 'string',
					'description' => 'Template title',
					'required'    => true,
				),
				'type'           => array(
					'type'        => 'string',
					'description' => 'Template type (e.g. header, footer, single, archive, section, page)',
					'required'    => true,
				),
				'elementor_data' => array(
					'type'        => 'array',
					'description' => 'Optional Elementor data JSON (array). If omitted, Elementor creates a blank template.',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_update_elementor_template',
			'Update an existing Elementor template: title, content, or Elementor data.',
			array(
				'id'             => array(
					'type'        => 'number',
					'description' => 'Template post ID',
					'required'    => true,
				),
				'title'          => array(
					'type'        => 'string',
					'description' => 'Optional new title',
				),
				'elementor_data' => array(
					'type'        => 'array',
					'description' => 'Optional Elementor data JSON (array)',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_delete_elementor_template',
			'Delete an Elementor template. Permanent — cannot be undone. Confirm before deleting shared templates.',
			array(
				'id'    => array(
					'type'        => 'number',
					'description' => 'Template post ID',
					'required'    => true,
				),
				'force' => array(
					'type'        => 'boolean',
					'description' => 'Whether to force delete (bypass trash)',
					'default'     => false,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_apply_elementor_template',
			'Apply an Elementor template to a page. WARNING: This COPIES the template data and REPLACES all existing page content. For non-destructive insertion, get the template data via wp_get_elementor_template then use wp_add_section to insert specific sections.',
			array(
				'template_id' => array(
					'type'        => 'number',
					'description' => 'Template ID',
					'required'    => true,
				),
				'page_id'     => array(
					'type'        => 'number',
					'description' => 'Page ID to apply template to',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_list_elementor_archetypes',
			'List reusable Elementor archetypes. Archetypes are full-page or product-layout templates marked by scope and class, such as blog_post, service_page, landing_page, or simple_product.',
			array(
				'scope' => array(
					'type'        => 'string',
					'description' => 'Optional archetype scope such as page or product',
				),
				'archetype_class' => array(
					'type'        => 'string',
					'description' => 'Optional archetype class such as blog_post, service_page, landing_page, simple_product, variable_product',
				),
				'style' => array(
					'type'        => 'string',
					'description' => 'Optional style or variant label',
				),
				'search' => array(
					'type'        => 'string',
					'description' => 'Optional text search over archetype titles',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_elementor_archetype',
			'Get a single reusable Elementor archetype with its Elementor data and archetype metadata.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Archetype/template post ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_elementor_archetype',
			'Create a reusable Elementor archetype. Use this for canonical full-page structures like blog posts, service pages, landing pages, or product-layout archetypes.',
			array(
				'title' => array(
					'type'        => 'string',
					'description' => 'Archetype title',
					'required'    => true,
				),
				'elementor_data' => array(
					'type'        => 'array',
					'description' => 'Optional Elementor data JSON for the archetype',
				),
				'type' => array(
					'type'        => 'string',
					'description' => 'Optional Elementor template type. Defaults to page.',
				),
				'archetype_scope' => array(
					'type'        => 'string',
					'description' => 'Archetype scope, typically page or product',
					'required'    => true,
				),
				'archetype_class' => array(
					'type'        => 'string',
					'description' => 'Archetype class such as blog_post, service_page, landing_page, simple_product, variable_product',
					'required'    => true,
				),
				'archetype_style' => array(
					'type'        => 'string',
					'description' => 'Optional archetype style or variant label',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_apply_elementor_archetype',
			'Apply an Elementor archetype to a page. This is intended for page-scoped archetypes and replaces the page content with the canonical archetype structure.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Archetype/template ID',
					'required'    => true,
				),
				'page_id' => array(
					'type'        => 'number',
					'description' => 'Target page ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_list_elementor_parts',
			'List reusable Elementor parts from the parts library. Parts are metadata-backed Elementor templates meant for reuse across pages, such as heroes, FAQs, CTAs, pricing sections, and proof bands. Optional filters: kind, style, tag, search.',
			array(
				'kind'   => array(
					'type'        => 'string',
					'description' => 'Optional part kind, such as hero, faq, cta, pricing, features, proof, testimonial, footer_promo',
				),
				'style'  => array(
					'type'        => 'string',
					'description' => 'Optional style or variant label, such as dark, minimal, editorial, saas',
				),
				'tag'    => array(
					'type'        => 'string',
					'description' => 'Optional tag to filter by',
				),
				'search' => array(
					'type'        => 'string',
					'description' => 'Optional text search over part titles',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_elementor_part',
			'Get a single reusable Elementor part with its Elementor data and metadata.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Part/template post ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_elementor_part',
			'Create a reusable Elementor part directly from Elementor JSON. Use this for canonical building blocks you want to reuse across many pages.',
			array(
				'title'          => array(
					'type'        => 'string',
					'description' => 'Part title',
					'required'    => true,
				),
				'elementor_data' => array(
					'type'        => 'array',
					'description' => 'Elementor data JSON for the part',
					'required'    => true,
				),
				'type'           => array(
					'type'        => 'string',
					'description' => 'Optional template type. Defaults to section.',
				),
				'part_kind'      => array(
					'type'        => 'string',
					'description' => 'Part kind such as hero, faq, cta, pricing, features, proof, testimonial',
				),
				'part_style'     => array(
					'type'        => 'string',
					'description' => 'Optional style/variant label',
				),
				'part_tags'      => array(
					'type'        => 'array',
					'description' => 'Optional list of part tags',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_elementor_part_from_section',
			'Extract a live Elementor section or container from a page and save it as a reusable part in the parts library. This is the professional way to promote a good live section into a canonical reusable asset.',
			array(
				'page_id'    => array(
					'type'        => 'integer',
					'description' => 'Page ID containing the source section',
					'required'    => true,
				),
				'element_id' => array(
					'type'        => 'string',
					'description' => 'Element ID of the source section/container from wp_get_elementor_summary',
					'required'    => true,
				),
				'title'      => array(
					'type'        => 'string',
					'description' => 'Optional title for the saved part',
				),
				'part_kind'  => array(
					'type'        => 'string',
					'description' => 'Part kind such as hero, faq, cta, pricing, features, proof, testimonial',
				),
				'part_style' => array(
					'type'        => 'string',
					'description' => 'Optional style/variant label',
				),
				'part_tags'  => array(
					'type'        => 'array',
					'description' => 'Optional list of part tags',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_apply_elementor_part',
			'Apply a reusable Elementor part to a page. Use mode=\"replace\" to stamp the page from the part, or mode=\"insert\" to append/insert the part into an existing page without replacing other top-level sections.',
			array(
				'id'      => array(
					'type'        => 'number',
					'description' => 'Part/template ID',
					'required'    => true,
				),
				'page_id' => array(
					'type'        => 'number',
					'description' => 'Page ID to apply the part to',
					'required'    => true,
				),
				'mode'    => array(
					'type'        => 'string',
					'description' => 'replace (default) or insert',
					'default'     => 'replace',
				),
				'position' => array(
					'type'        => 'string',
					'description' => 'For mode=insert: start, end, before:{section_id}, after:{section_id}',
					'default'     => 'end',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_landing_page',
			'Create a new Elementor landing page from a template or blueprint. Returns page ID and edit URL.',
			array(
				'title'       => array(
					'type'        => 'string',
					'description' => 'Page title',
					'required'    => true,
				),
				'template_id' => array(
					'type'        => 'number',
					'description' => 'Optional template ID to use',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_clone_elementor_page',
			'Clone an Elementor page including all design data. Creates a draft copy with "(Copy)" appended to the title.',
			array(
				'source_id' => array(
					'type'        => 'number',
					'description' => 'Source page ID to clone',
					'required'    => true,
				),
				'title'     => array(
					'type'        => 'string',
					'description' => 'Title for the new cloned page',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_build_page',
			'Creates a NEW page from semantic section blueprints. Generates valid Elementor data automatically. For adding sections to EXISTING pages, use wp_get_blueprint + wp_add_section instead. Supported section types: hero, features, cta, pricing, faq, testimonials, text, gallery, contact_form, map, countdown, stats, logo_grid, video.',
			array(
				'title' => array(
					'type'        => 'string',
					'description' => 'Page title',
					'required'    => true,
				),
				'sections' => array(
					'type'        => 'array',
					'description' => 'Array of section objects. Each has "type" plus type-specific params. Hero: heading, subheading, cta_text, cta_url, background (color/#hex/gradient), image_url. Features: heading, columns, items[{icon, title, desc}]. CTA: heading, subheading, button_text, button_url, background. Pricing: heading, plans[{title, price, period, features[], button_text, button_url}]. FAQ: heading, items[{question, answer}]. Testimonials: heading, items[{text, name, title, image}]. Text: heading, content. Gallery: heading, images[], columns. Contact_form: heading, subheading, form_id, plugin (wpforms/cf7/gravity). Map: heading, address, zoom (1-20), height. Countdown: heading, due_date (YYYY-MM-DD HH:MM), subheading. Stats: heading, columns, items[{number, title, suffix}]. Logo_grid: heading, columns, items[{image, url}]. Video: heading, url (YouTube/Vimeo/hosted mp4), subheading.',
					'required'    => true,
				),
				'status' => array(
					'type'        => 'string',
					'description' => 'Page status: draft (default), publish, private',
					'default'     => 'draft',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_list_blueprints',
			'List all available section blueprint types with their parameter schemas. Use this to discover what section types can be generated with wp_get_blueprint. WORKFLOW: 1) wp_list_blueprints → see types + params 2) wp_get_blueprint(type, params) → get JSON 3) wp_add_section(page_id, element, position) → insert on page.',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_blueprint',
			'Generate a single Elementor section from a blueprint type. Returns ready-to-use Elementor JSON that can be inserted into any page via wp_add_section. WORKFLOW: 1) wp_list_blueprints → discover types 2) wp_get_blueprint(type, params) → generate section JSON 3) wp_add_section(page_id, element=<json>.elements[0], position) → insert 4) wp_get_elementor_summary → verify.',
			array(
				'type' => array(
					'type'        => 'string',
					'description' => 'Blueprint type: hero, features, cta, pricing, faq, testimonials, text, gallery, contact_form, map, countdown, stats, logo_grid, video',
					'required'    => true,
				),
				'heading'     => array( 'type' => 'string', 'description' => 'Section heading text' ),
				'subheading'  => array( 'type' => 'string', 'description' => 'Section subheading text' ),
				'cta_text'    => array( 'type' => 'string', 'description' => 'CTA button text (hero/cta)' ),
				'cta_url'     => array( 'type' => 'string', 'description' => 'CTA button URL (hero/cta)' ),
				'button_text' => array( 'type' => 'string', 'description' => 'Button text (cta/pricing)' ),
				'button_url'  => array( 'type' => 'string', 'description' => 'Button URL (cta/pricing)' ),
				'background'  => array( 'type' => 'string', 'description' => 'Color hex or "gradient" (hero/cta)' ),
				'image_url'   => array( 'type' => 'string', 'description' => 'Background image URL (hero)' ),
				'columns'     => array( 'type' => 'integer', 'description' => 'Number of columns (features/stats/gallery/logo_grid)' ),
				'items'       => array( 'type' => 'array', 'description' => 'Array of items (type-specific, see wp_list_blueprints for schemas)' ),
				'plans'       => array( 'type' => 'array', 'description' => 'Pricing plans array (pricing type)' ),
				'content'     => array( 'type' => 'string', 'description' => 'HTML content (text type)' ),
				'images'      => array( 'type' => 'array', 'description' => 'Image URLs array (gallery type)' ),
				'url'         => array( 'type' => 'string', 'description' => 'Video URL (video type)' ),
				'address'     => array( 'type' => 'string', 'description' => 'Address (map type)' ),
				'due_date'    => array( 'type' => 'string', 'description' => 'YYYY-MM-DD HH:MM (countdown type)' ),
				'form_id'     => array( 'type' => 'integer', 'description' => 'Form ID (contact_form type)' ),
				'plugin'      => array( 'type' => 'string', 'description' => 'Form plugin: wpforms, cf7, gravity (contact_form type)' ),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_save_section_as_template',
			'Extract a section/container from a live page and save it as a reusable Elementor template. WORKFLOW: 1) wp_get_elementor_summary → get section IDs 2) wp_save_section_as_template(page_id, element_id, title) → save as template 3) wp_list_elementor_templates → verify. The template can then be applied to other pages via wp_apply_elementor_template or its data retrieved via wp_get_elementor_template.',
			array(
				'page_id'    => array(
					'type'        => 'integer',
					'description' => 'Page ID containing the section',
					'required'    => true,
				),
				'element_id' => array(
					'type'        => 'string',
					'description' => 'Element ID of the section to save (from wp_get_elementor_summary)',
					'required'    => true,
				),
				'title'      => array(
					'type'        => 'string',
					'description' => 'Title for the saved template. Defaults to "Section from Page {id}"',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_elementor_globals',
			'Get Elementor global settings: global colors, global fonts, and kit settings applied site-wide.',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_set_elementor_globals',
			'Set Elementor global settings. Merges with existing kit page_settings. Accepts any valid Elementor Kit setting key — common ones listed below, but any kit setting works.',
			array(
				'system_colors' => array(
					'type'        => 'array',
					'description' => 'Array of {_id, title, color} objects for global colors',
				),
				'custom_colors' => array(
					'type'        => 'array',
					'description' => 'Array of {_id, title, color} objects for custom colors',
				),
				'system_typography' => array(
					'type'        => 'array',
					'description' => 'Array of typography objects with font_family, font_size, font_weight, etc.',
				),
				'custom_typography' => array(
					'type'        => 'array',
					'description' => 'Array of custom typography definitions',
				),
				'custom_css' => array(
					'type'        => 'string',
					'description' => 'Kit-level custom CSS. Applied site-wide via Elementor. Replaces existing kit CSS.',
				),
				'container_width' => array(
					'type'        => 'object',
					'description' => 'Default container max-width: {"size":1140,"unit":"px"}',
				),
				'space_between_widgets' => array(
					'type'        => 'object',
					'description' => 'Space between widgets: {"size":20,"unit":"px"}',
				),
				'page_title_selector' => array(
					'type'        => 'string',
					'description' => 'CSS selector for page title element (e.g. "h1.entry-title")',
				),
				'stretched_section_container' => array(
					'type'        => 'string',
					'description' => 'CSS selector for stretched sections container',
				),
				'default_generic_fonts' => array(
					'type'        => 'string',
					'description' => 'Fallback font stack (e.g. "Sans-serif")',
				),
				'viewport_md' => array(
					'type'        => 'number',
					'description' => 'Tablet breakpoint in px (default: 768)',
				),
				'viewport_lg' => array(
					'type'        => 'number',
					'description' => 'Desktop breakpoint in px (default: 1025)',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_list_elementor_custom_code',
			'List Elementor Pro Custom Code snippets with location and display-condition readback.',
			array(
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Maximum number of items per page',
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Page number',
				),
				'status'   => array(
					'type'        => 'string',
					'description' => 'Filter by post status: publish|draft|any',
				),
				'search'   => array(
					'type'        => 'string',
					'description' => 'Search by snippet title',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_elementor_custom_code',
			'Get one Elementor Pro Custom Code snippet with raw code, injection location, status, and display-condition readback.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Snippet post ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_elementor_custom_code',
			'Create an Elementor Pro Custom Code snippet with raw script/style preservation, injection location, and display conditions. Use dry_run=true before publishing tracking scripts.',
			array(
				'title'      => array(
					'type'        => 'string',
					'description' => 'Snippet title',
					'required'    => true,
				),
				'code'       => array(
					'type'        => 'string',
					'description' => 'Raw HTML, CSS, or JavaScript code. PHP is not supported.',
					'required'    => true,
				),
				'location'   => array(
					'type'        => 'string',
					'description' => 'Injection location: head, body_start, or body_end',
					'default'     => 'head',
				),
				'status'     => array(
					'type'        => 'string',
					'description' => 'Initial status: draft or publish',
					'default'     => 'draft',
				),
				'conditions' => array(
					'type'        => 'array',
					'description' => 'Display conditions. Default is entire site: [{"type":"include","name":"general"}]. Exclusions use type=exclude.',
				),
				'dry_run'    => array(
					'type'        => 'boolean',
					'description' => 'Validate and normalize without saving',
					'default'     => false,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_update_elementor_custom_code',
			'Update an Elementor Pro Custom Code snippet. Supports changing raw code, location, status, and display conditions with readback.',
			array(
				'id'         => array(
					'type'        => 'number',
					'description' => 'Snippet post ID',
					'required'    => true,
				),
				'title'      => array(
					'type'        => 'string',
					'description' => 'Snippet title',
				),
				'code'       => array(
					'type'        => 'string',
					'description' => 'Raw HTML, CSS, or JavaScript code. PHP is not supported.',
				),
				'location'   => array(
					'type'        => 'string',
					'description' => 'Injection location: head, body_start, or body_end',
				),
				'status'     => array(
					'type'        => 'string',
					'description' => 'Status: draft or publish',
				),
				'conditions' => array(
					'type'        => 'array',
					'description' => 'Display conditions, e.g. [{"type":"include","name":"general"}].',
				),
				'dry_run'    => array(
					'type'        => 'boolean',
					'description' => 'Validate and normalize without saving',
					'default'     => false,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_disable_elementor_custom_code',
			'Disable an Elementor Pro Custom Code snippet (sets status to draft)',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Snippet post ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_enable_elementor_custom_code',
			'Enable an Elementor Pro Custom Code snippet (sets status to publish)',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Snippet post ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_sanitize_elementor_custom_code',
			'Sanitize an Elementor Pro Custom Code snippet by stripping <html>/<head>/<body> tags from meta values',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Snippet post ID',
					'required'    => true,
				),
			)
		);

		return $pro_tools;
	}

	/**
	 * @return array
	 */
	private function get_theme_builder_pro_tools() {
		$pro_tools = array();
		// Theme Builder Tools
		$pro_tools[] = $this->define_tool(
			'wp_theme_builder_status',
			'Get Theme Builder availability, registered locations, and which templates are assigned',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_list_theme_templates',
			'List Theme Builder templates (header, footer, single, archive, etc.) with their display conditions',
			array(
				'type' => array(
					'type'        => 'string',
					'description' => 'Filter by template type: header, footer, single, single-post, single-page, archive, search-results, error-404',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_theme_template',
			'Get a single Theme Builder template with its current display conditions',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Template post ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_set_template_conditions',
			'Set display conditions on a Theme Builder template. Conditions are arrays like ["include","general","singular","post"] or ["exclude","general","singular","page"]',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Template post ID',
					'required'    => true,
				),
				'conditions' => array(
					'type'        => 'array',
					'description' => 'Array of condition arrays, e.g. [["include","general","singular","post"],["exclude","general","singular","page"]]',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_assign_template',
			'Shortcut to assign a Theme Builder template to a scope (entire_site, all_singular, all_archive, specific_posts, specific_post_type)',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Template post ID',
					'required'    => true,
				),
				'scope' => array(
					'type'        => 'string',
					'description' => 'Assignment scope: entire_site, all_singular, all_archive, specific_posts, specific_post_type',
					'default'     => 'entire_site',
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type for specific_post_type scope (e.g., post, page, tp_event, lp_course)',
				),
				'post_ids' => array(
					'type'        => 'array',
					'description' => 'Array of post IDs for specific_posts scope',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_create_theme_template',
			'Create a Theme Builder template (header, footer, single, archive) and assign it to a display scope in one step',
			array(
				'title' => array(
					'type'        => 'string',
					'description' => 'Template title',
					'required'    => true,
				),
				'type' => array(
					'type'        => 'string',
					'description' => 'Template type: header, footer, single, archive',
					'required'    => true,
				),
				'elementor_data' => array(
					'type'        => 'array',
					'description' => 'Optional Elementor JSON data for the template content',
				),
				'scope' => array(
					'type'        => 'string',
					'description' => 'Display scope: entire_site, specific_post_type, specific_posts, archive, front_page, 404',
					'default'     => 'entire_site',
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => 'Post type for specific_post_type scope (e.g., post, page, tp_event, lp_course)',
				),
				'post_ids' => array(
					'type'        => 'array',
					'description' => 'Array of post IDs for specific_posts scope',
				),
			)
		);

		return $pro_tools;
	}

	/**
	 * @return array
	 */
	private function get_widgets_sidebar_pro_tools() {
		$pro_tools = array();
		// Widget & Sidebar Management Tools
		$pro_tools[] = $this->define_tool(
			'wp_list_sidebars',
			'List all registered widget areas (sidebars) with widget counts',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_sidebar',
			'Get a single widget area (sidebar) and its metadata. Returns registered widget area ID, name, and description.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Sidebar ID (e.g., sidebar-1, footer-1)',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_sidebar_widgets',
			'Get all widgets in a specific sidebar (widget area). Returns widget types, IDs, and settings.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Sidebar ID',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_widget_types',
			'List all available widget types that can be added to sidebars',
			array()
		);

		$pro_tools[] = $this->define_tool(
			'wp_get_widget',
			'Get a single sidebar widget by ID with its full settings and configuration.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Widget ID (e.g., text-2, custom_html-3)',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_add_widget',
			'Add a new widget to a sidebar widget area. Use to insert text, HTML, navigation, or custom widgets.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Sidebar ID to add the widget to',
					'required'    => true,
				),
				'type' => array(
					'type'        => 'string',
					'description' => 'Widget type (id_base, e.g., text, custom_html, search)',
					'required'    => true,
				),
				'settings' => array(
					'type'        => 'object',
					'description' => 'Widget settings (varies by widget type)',
				),
				'position' => array(
					'type'        => 'number',
					'description' => 'Position in sidebar (0-based index)',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_update_widget',
			'Update settings for a sidebar widget by ID. Use to edit text, links, or configuration of an existing widget.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Widget ID (e.g., text-2)',
					'required'    => true,
				),
				'settings' => array(
					'type'        => 'object',
					'description' => 'New settings to merge with existing',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_delete_widget',
			'Delete a widget from its sidebar and remove its settings',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Widget ID (e.g., text-2)',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_move_widget',
			'Move a widget from one sidebar widget area to another. Use to reorganize widget placement across sidebars.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Widget ID to move',
					'required'    => true,
				),
				'sidebar' => array(
					'type'        => 'string',
					'description' => 'Target sidebar ID',
					'required'    => true,
				),
				'position' => array(
					'type'        => 'number',
					'description' => 'Position in target sidebar',
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_reorder_widgets',
			'Reorder widgets within a sidebar widget area. Changes the display order of widgets in a sidebar.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Sidebar ID',
					'required'    => true,
				),
				'widgets' => array(
					'type'        => 'array',
					'description' => 'Ordered array of widget IDs',
					'required'    => true,
				),
			)
		);

		$pro_tools[] = $this->define_tool(
			'wp_set_noindex',
			'Set or remove noindex on a page or post (controls search engine indexing). Convenience wrapper around wp_set_seo.',
			array(
				'id'      => array(
					'type'        => 'number',
					'description' => 'Page or post ID',
					'required'    => true,
				),
				'noindex' => array(
					'type'        => 'boolean',
					'description' => 'true to add noindex (hide from search), false to remove it (allow indexing)',
					'required'    => true,
				),
			)
		);

		// =====================================================================
		return $pro_tools;
	}
}
