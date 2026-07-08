<?php
/**
 * Workflow Templates
 *
 * Provides step-by-step workflow guides for common WordPress tasks.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflows class.
 *
 * Returns structured workflow templates that guide AI assistants
 * through multi-step tasks.
 */
class Mcpwp_Workflows {

	/**
	 * Get all available workflow summaries.
	 *
	 * Filters based on active plugins.
	 *
	 * @return array List of workflow summaries.
	 */
	public static function get_all() {
		$core         = new Mcpwp_Core();
		$capabilities = $core->get_capabilities();

		$has_elementor = ! empty( $capabilities['elementor'] );
		$has_seo       = ! empty( $capabilities['yoast'] )
			|| ! empty( $capabilities['rankmath'] )
			|| ! empty( $capabilities['aioseo'] )
			|| ! empty( $capabilities['seopress'] );
		$has_forms     = ! empty( $capabilities['cf7'] )
			|| ! empty( $capabilities['wpforms'] )
			|| ! empty( $capabilities['gravityforms'] )
			|| ! empty( $capabilities['ninjaforms'] );

		$workflows = array(
			array(
				'name'         => 'build_landing_page',
				'title'        => 'Build a Landing Page',
				'description'  => 'Create a new page with Elementor layout, SEO meta, and visual verification.',
				'requires'     => 'elementor',
				'steps_count'  => 7,
			),
			array(
				'name'         => 'build_from_parts_library',
				'title'        => 'Build From Parts Library',
				'description'  => 'Assemble pages from reusable Elementor templates and saved sections instead of rebuilding layouts from scratch.',
				'requires'     => 'elementor',
				'steps_count'  => 8,
			),
			array(
				'name'         => 'build_from_page_archetype',
				'title'        => 'Build From Page Archetype',
				'description'  => 'Use a stable page archetype for repeatable page classes like blog posts and service pages, then save strong new sections back into the parts library.',
				'requires'     => 'elementor',
				'steps_count'  => 8,
			),
			array(
				'name'         => 'build_from_figma_reference',
				'title'        => 'Build From Figma Reference',
				'description'  => 'Inspect an approved Figma file or frame, translate it into local archetypes and parts, then build the WordPress draft without bypassing the site library.',
				'requires'     => 'elementor',
				'steps_count'  => 8,
			),
			array(
				'name'         => 'build_from_design_reference',
				'title'        => 'Build From Design Reference',
				'description'  => 'Turn an uploaded screenshot or design image into a reusable local reference, then build a draft page, archetype, or part from it.',
				'requires'     => 'elementor',
				'steps_count'  => 8,
			),
			array(
				'name'         => 'build_product_from_archetype',
				'title'        => 'Build Product From Archetype',
				'description'  => 'Create WooCommerce products from a stable product archetype and preserve reusable structure across similar products.',
				'requires'     => null,
				'steps_count'  => 7,
			),
			array(
				'name'         => 'seo_audit',
				'title'        => 'SEO Audit & Fix',
				'description'  => 'Scan all pages for SEO issues, generate a report, and fix problems.',
				'requires'     => 'seo',
				'steps_count'  => 5,
			),
			array(
				'name'         => 'content_migration',
				'title'        => 'Content Migration',
				'description'  => 'Export, transform, and re-import page content across posts or pages.',
				'requires'     => null,
				'steps_count'  => 5,
			),
			array(
				'name'         => 'site_redesign',
				'title'        => 'Site Redesign',
				'description'  => 'Update site-wide design tokens, Elementor globals, and rebuild pages.',
				'requires'     => 'elementor',
				'steps_count'  => 6,
			),
			array(
				'name'         => 'menu_setup',
				'title'        => 'Menu Setup',
				'description'  => 'Create navigation menus and assign them to theme locations.',
				'requires'     => null,
				'steps_count'  => 5,
			),
			array(
				'name'         => 'media_management',
				'title'        => 'Media Management',
				'description'  => 'Audit media library, upload new assets, and set featured images.',
				'requires'     => null,
				'steps_count'  => 5,
			),
			array(
				'name'         => 'form_setup',
				'title'        => 'Form Setup & Embedding',
				'description'  => 'Detect forms, inspect fields, and embed into Elementor pages.',
				'requires'     => 'forms',
				'steps_count'  => 5,
			),
		);

		$capability_map = array(
			'elementor' => $has_elementor,
			'seo'       => $has_seo,
			'forms'     => $has_forms,
		);

		$filtered = array();
		foreach ( $workflows as $wf ) {
			$req = $wf['requires'];
			if ( null === $req || ( isset( $capability_map[ $req ] ) && $capability_map[ $req ] ) ) {
				unset( $wf['requires'] );
				$filtered[] = $wf;
			}
		}

		return $filtered;
	}

	/**
	 * Get a specific workflow by name.
	 *
	 * @param string $name Workflow name.
	 * @return array|WP_Error Workflow data or error.
	 */
	public static function get_workflow( $name ) {
		$method = 'workflow_' . $name;

		if ( ! method_exists( __CLASS__, $method ) ) {
			$available = wp_list_pluck( self::get_all(), 'name' );
			return new WP_Error(
				'invalid_workflow',
				sprintf(
					'Unknown workflow: %s. Available workflows: %s',
					$name,
					implode( ', ', $available )
				),
				array( 'status' => 404 )
			);
		}

		return call_user_func( array( __CLASS__, $method ) );
	}

	/**
	 * Build landing page workflow.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_build_landing_page() {
		return array(
			'name'          => 'build_landing_page',
			'title'         => 'Build a Landing Page',
			'description'   => 'Create a complete landing page with Elementor, from site context to final verification.',
			'prerequisites' => array( 'Elementor must be active' ),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Introspect the site',
					'tool'        => 'wp_introspect',
					'params'      => array(),
					'description' => 'Get site capabilities, layout mode, and available tools. Note the elementor_layout_mode (section vs container) — this determines your page structure.',
					'use_result'  => 'Save the layout_mode and capabilities for later steps.',
				),
				array(
					'step'        => 2,
					'title'       => 'Get site context',
					'tool'        => 'wp_get_site_context',
					'params'      => array(),
					'description' => 'Read the site style guide / design brief. This tells you the color palette, typography, header/footer rules, and predefined sections to use.',
					'use_result'  => 'Follow these design rules when building the page. If empty, ask the user for design preferences.',
				),
				array(
					'step'        => 3,
					'title'       => 'Create the page',
					'tool'        => 'wp_create_page',
					'params'      => array(
						'title'  => '(page title)',
						'status' => 'draft',
					),
					'description' => 'Create a draft page. Save the returned page ID for subsequent steps.',
					'use_result'  => 'Note the page ID.',
				),
				array(
					'step'        => 4,
					'title'       => 'Set page template',
					'tool'        => 'wp_update_page_template',
					'params'      => array(
						'id'       => '(page_id from step 3)',
						'template' => 'elementor_header_footer',
					),
					'description' => 'Set the Elementor template. Use elementor_header_footer for pages with theme header/footer, or elementor_canvas for full-width standalone pages.',
					'use_result'  => 'Confirm template was set.',
				),
				array(
					'step'        => 5,
					'title'       => 'Push Elementor data',
					'tool'        => 'wp_set_elementor',
					'params'      => array(
						'id'             => '(page_id from step 3)',
						'elementor_data' => '(JSON array of sections/containers with widgets)',
					),
					'description' => 'Push the full page layout. Use the correct layout mode from step 1. Check the response for warnings about invalid widgets or missing IDs.',
					'use_result'  => 'Review warnings array. Fix any issues and re-push if needed.',
				),
				array(
					'step'        => 6,
					'title'       => 'Set SEO meta',
					'tool'        => 'wp_set_seo',
					'params'      => array(
						'id'          => '(page_id from step 3)',
						'title'       => '(SEO title)',
						'description' => '(meta description)',
					),
					'description' => 'Set SEO title and description. Only if an SEO plugin is active (check capabilities from step 1).',
					'use_result'  => 'Confirm SEO was saved.',
				),
				array(
					'step'        => 7,
					'title'       => 'Verify with screenshot',
					'tool'        => 'wp_screenshot_url',
					'params'      => array(
						'url' => '(page preview URL)',
					),
					'description' => 'Take a screenshot to verify the page renders correctly. The page URL can be constructed from the site URL + page slug.',
					'use_result'  => 'Review the screenshot. If issues are found, use wp_edit_section or wp_edit_widget to fix specific parts.',
				),
			),
			'tips' => array(
				'Always check layout_mode before building — using sections in container mode (or vice versa) breaks the page.',
				'Use wp_get_elementor_widgets() to verify a widget type exists before using it.',
				'Use wp_get_widget_schema(widget_type) to get the correct settings keys for a widget.',
				'If the site has a site_context, follow its design rules strictly.',
				'If a new page contains a reusable hero, proof section, FAQ, CTA band, or author block, save it into the Elementor parts library before finishing the task.',
				'Publish the page only after verification: wp_update_page(id, status="publish").',
			),
		);
	}

	/**
	 * Build a page from reusable Elementor parts.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_build_from_parts_library() {
		return array(
			'name'          => 'build_from_parts_library',
			'title'         => 'Build From Parts Library',
			'description'   => 'Create pages by selecting, adapting, and applying reusable Elementor parts such as heroes, feature grids, testimonials, and CTA sections.',
			'prerequisites' => array( 'Elementor must be active', 'At least one reusable template or saved section should exist' ),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Read site context',
					'tool'        => 'wp_get_site_context',
					'params'      => array(),
					'description' => 'Read the site design rules, audience, and messaging before choosing any reusable parts.',
					'use_result'  => 'Keep the brand voice, typography, spacing, and offer hierarchy consistent across all reused sections.',
				),
				array(
					'step'        => 2,
					'title'       => 'Inspect reusable inventory',
					'tool'        => 'wp_list_elementor_parts',
					'params'      => array(),
					'description' => 'List the current parts library and look for reusable sections such as heroes, feature rows, pricing blocks, testimonials, FAQs, and CTA strips.',
					'use_result'  => 'Group parts by intent: hero, social proof, features, pricing, FAQ, CTA, footer band.',
				),
				array(
					'step'        => 3,
					'title'       => 'Inspect source pages for reusable sections',
					'tool'        => 'wp_get_elementor_summary',
					'params'      => array(
						'id' => '(source_page_id)',
					),
					'description' => 'If the library is incomplete, inspect a strong existing page to locate sections worth extracting into reusable parts.',
					'use_result'  => 'Note section or container IDs that should become templates.',
				),
				array(
					'step'        => 4,
					'title'       => 'Save missing parts into the library',
					'tool'        => 'wp_create_elementor_part_from_section',
					'params'      => array(
						'page_id'    => '(source_page_id)',
						'element_id' => '(section_or_container_id)',
						'title'      => '(descriptive part name)',
						'part_kind'  => '(hero_or_faq_or_cta)',
						'part_style' => '(optional_style_label)',
					),
					'description' => 'Extract strong sections from live pages and save them as reusable Elementor parts with explicit kind/style metadata.',
					'use_result'  => 'Name each part clearly, for example: SaaS Hero Dark, Testimonial Strip Light, FAQ Two Column.',
				),
				array(
					'step'        => 5,
					'title'       => 'Create the target page',
					'tool'        => 'wp_create_page',
					'params'      => array(
						'title'  => '(page title)',
						'status' => 'draft',
					),
					'description' => 'Create a draft page that will be assembled from reusable parts.',
					'use_result'  => 'Save the returned page ID.',
				),
				array(
					'step'        => 6,
					'title'       => 'Apply selected parts in sequence',
					'tool'        => 'wp_apply_elementor_part',
					'params'      => array(
						'id'       => '(part_id)',
						'page_id'  => '(target_page_id)',
						'mode'     => 'insert',
						'position' => 'end',
					),
					'description' => 'Insert the chosen parts in page order, typically hero -> proof -> features -> CTA, without replacing prior sections.',
					'use_result'  => 'Confirm the page structure exists before making copy or style refinements.',
				),
				array(
					'step'        => 7,
					'title'       => 'Adapt only what is page-specific',
					'tool'        => 'wp_edit_widget',
					'params'      => array(
						'id'        => '(target_page_id)',
						'widget_id' => '(widget_id)',
						'settings'  => '(copy_or_style_changes)',
					),
					'description' => 'Customize headlines, supporting copy, imagery, and CTA targets without rebuilding the section structure.',
					'use_result'  => 'Preserve reusable layout patterns while tailoring the message for the current page.',
				),
				array(
					'step'        => 8,
					'title'       => 'Verify and promote winning parts',
					'tool'        => 'wp_preview_elementor',
					'params'      => array(
						'id' => '(target_page_id)',
					),
					'description' => 'Preview the assembled page. If a custom section performs well, save it back into the template library for future reuse.',
					'use_result'  => 'The library should improve over time instead of pages being rebuilt from zero.',
				),
			),
			'tips' => array(
				'Think in reusable parts, not one-off pages.',
				'Keep a small canonical set of heroes, feature grids, proof sections, and CTA bands per design style.',
				'Name templates by intent and style, for example: Hero / SaaS / Dark, FAQ / Two Column / Light.',
				'Reuse structure first, then customize copy and media.',
				'If a page section is likely to appear twice, save it as a template before the second use.',
			),
		);
	}

	/**
	 * Build a repeatable page from a stable archetype, then promote new reusable parts.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_build_from_page_archetype() {
		return array(
			'name'          => 'build_from_page_archetype',
			'title'         => 'Build From Page Archetype',
			'description'   => 'Create pages from a stable archetype such as blog post, service page, landing page, case study, or about page, then promote strong new sections into the reusable parts library.',
			'prerequisites' => array( 'Elementor must be active', 'At least one page archetype template should exist for the target page class' ),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Choose the page class',
					'tool'        => 'wp_get_site_context',
					'params'      => array(),
					'description' => 'Determine the page class before building: blog post, landing page, service page, about page, case study, contact page, or another repeatable archetype.',
					'use_result'  => 'Do not design from zero. Pick the nearest stable archetype first.',
				),
				array(
					'step'        => 2,
					'title'       => 'Find the matching archetype',
					'tool'        => 'wp_list_elementor_archetypes',
					'params'      => array(),
					'description' => 'Inspect saved Elementor archetypes and choose the one that matches the page class and design system.',
					'use_result'  => 'Treat the chosen full-page template as the canonical archetype for this page class.',
				),
				array(
					'step'        => 3,
					'title'       => 'Create the new page',
					'tool'        => 'wp_create_page',
					'params'      => array(
						'title'  => '(page title)',
						'status' => 'draft',
					),
					'description' => 'Create the target draft page that will inherit the archetype structure.',
					'use_result'  => 'Save the page ID.',
				),
				array(
					'step'        => 4,
					'title'       => 'Apply the archetype',
					'tool'        => 'wp_apply_elementor_archetype',
					'params'      => array(
						'id'      => '(archetype_template_id)',
						'page_id' => '(target_page_id)',
					),
					'description' => 'Stamp the page from the chosen archetype so the layout, rhythm, and section order are stable.',
					'use_result'  => 'You now have the right skeleton for the page class without redesigning it.',
				),
				array(
					'step'        => 5,
					'title'       => 'Adapt the page-specific content',
					'tool'        => 'wp_edit_widget',
					'params'      => array(
						'id'        => '(target_page_id)',
						'widget_id' => '(widget_id)',
						'settings'  => '(page_specific_copy_media_links)',
					),
					'description' => 'Update copy, imagery, author details, CTAs, and proof for this specific page while keeping the archetype intact.',
					'use_result'  => 'Only change the parts that should vary per page.',
				),
				array(
					'step'        => 6,
					'title'       => 'Inspect custom sections',
					'tool'        => 'wp_get_elementor_summary',
					'params'      => array(
						'id' => '(target_page_id)',
					),
					'description' => 'Review the resulting page and identify any newly created or heavily improved sections that could be reused elsewhere.',
					'use_result'  => 'Mark candidates like custom hero variants, proof sections, CTA bands, FAQs, and author blocks.',
				),
				array(
					'step'        => 7,
					'title'       => 'Promote reusable sections to parts',
					'tool'        => 'wp_create_elementor_part_from_section',
					'params'      => array(
						'page_id'    => '(target_page_id)',
						'element_id' => '(candidate_section_id)',
						'title'      => '(descriptive part name)',
						'part_kind'  => '(hero_or_faq_or_cta_or_author_box)',
						'part_style' => '(optional_style_label)',
					),
					'description' => 'Save any section that is likely to be reused into the Elementor parts library before the workflow ends.',
					'use_result'  => 'The library grows automatically as good sections are discovered.',
				),
				array(
					'step'        => 8,
					'title'       => 'Preview and publish',
					'tool'        => 'wp_preview_elementor',
					'params'      => array(
						'id' => '(target_page_id)',
					),
					'description' => 'Preview the final page, verify the archetype stayed consistent, then publish when ready.',
					'use_result'  => 'Structured page creation becomes repeatable and the parts library improves over time.',
				),
			),
			'tips' => array(
				'Separate full-page archetypes from reusable parts. Archetypes define the whole page class; parts define repeatable sections.',
				'Blog posts, service pages, and case studies should each have their own canonical archetype instead of being redesigned every time.',
				'When a new page introduces a strong section, promote it to the parts library before finishing the task.',
				'Do not let one-off edits quietly fork the archetype. Either keep the archetype intact or intentionally create a new named variant.',
				'Use consistent naming like Blog Post / Editorial / Default or Service Page / Local SEO / Dark.',
			),
		);
	}

	/**
	 * Build from Figma reference workflow.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_build_from_figma_reference() {
		return array(
			'name'          => 'build_from_figma_reference',
			'title'         => 'Build From Figma Reference',
			'description'   => 'Use approved Figma context as design input, then convert it into local WordPress structure through archetypes and reusable parts.',
			'prerequisites' => array( 'Elementor must be active', 'Figma integration should be configured for best results' ),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Read site context first',
					'tool'        => 'wp_get_site_context',
					'params'      => array(),
					'description' => 'Read the site operating brief before interpreting any external design source.',
					'use_result'  => 'Treat this as the non-negotiable brand and structure filter.',
				),
				array(
					'step'        => 2,
					'title'       => 'Verify Figma availability',
					'tool'        => 'wp_figma_status',
					'params'      => array(),
					'description' => 'Check whether Figma is configured and whether a default file key exists.',
					'use_result'  => 'If Figma is unavailable, stop using this workflow and fall back to local archetypes.',
				),
				array(
					'step'        => 3,
					'title'       => 'Inspect the approved Figma source',
					'tool'        => 'wp_get_figma_file',
					'params'      => array(
						'file_key' => '(optional figma file key)',
						'depth'    => 2,
					),
					'description' => 'Read the file structure and identify the relevant canvases, frames, and sections.',
					'use_result'  => 'Find the approved frame or node that should become the page skeleton.',
				),
				array(
					'step'        => 4,
					'title'       => 'Pull the target frame or section',
					'tool'        => 'wp_get_figma_node',
					'params'      => array(
						'file_key' => '(optional figma file key)',
						'node_id'  => '(approved frame or section node id)',
						'depth'    => 2,
					),
					'description' => 'Inspect the specific approved frame or section you need to translate.',
					'use_result'  => 'Extract hierarchy, major sections, layout intent, and naming cues.',
				),
				array(
					'step'        => 5,
					'title'       => 'Map the Figma structure to local archetypes and parts',
					'tool'        => 'wp_list_elementor_archetypes',
					'params'      => array(),
					'description' => 'Check whether the design already matches an approved local archetype before creating anything new.',
					'use_result'  => 'Prefer the nearest existing archetype and existing parts over raw reproduction.',
				),
				array(
					'step'        => 6,
					'title'       => 'Build the draft from local structure',
					'tool'        => 'wp_create_landing_page',
					'params'      => array(
						'title'       => '(draft title)',
						'status'      => 'draft',
						'template_id' => '(optional archetype/template id)',
					),
					'description' => 'Create the draft page using local archetypes and parts informed by the approved Figma design.',
					'use_result'  => 'The result should feel like the approved design, but remain a first-class local asset.',
				),
				array(
					'step'        => 7,
					'title'       => 'Promote reusable sections back into the library',
					'tool'        => 'wp_create_elementor_part',
					'params'      => array(
						'title'      => '(reusable part title)',
						'part_kind'  => '(hero/faq/cta/etc)',
						'part_style' => '(style label)',
					),
					'description' => 'If the Figma design introduced a reusable section pattern, save it as a reusable Elementor part for future work.',
					'use_result'  => 'The library should improve after each approved Figma translation.',
				),
				array(
					'step'        => 8,
					'title'       => 'Document provenance',
					'tool'        => 'wp_set_post_meta',
					'params'      => array(
						'id'         => '(page id)',
						'meta_key'   => '_mcpwp_design_source',
						'meta_value' => '(Figma file key and node id)',
					),
					'description' => 'Preserve which Figma source informed the page or part so future updates remain traceable.',
					'use_result'  => 'Humans and models can audit where the design came from.',
				),
			),
			'tips'          => array(
				'Use Figma as approved design context, not as a reason to bypass site context or the local parts library.',
				'Do not try to mirror every nested detail literally if it fights Elementor or the established site system.',
				'When a Figma section becomes repeatable, save it as a reusable part so the next page does not need Figma to start.',
			),
		);
	}

	/**
	 * Build from a stored image-based design reference.
	 *
	 * @return array
	 */
	public static function workflow_build_from_design_reference() {
		return array(
			'name'          => 'build_from_design_reference',
			'title'         => 'Build From Design Reference',
			'description'   => 'Use an uploaded screenshot or design image as the approved visual reference, then convert it into local archetypes, reusable parts, and a draft page.',
			'prerequisites' => array( 'Elementor must be active', 'A design image should be available as URL, base64 upload, or existing media' ),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Read site context first',
					'tool'        => 'wp_get_site_context',
					'params'      => array(),
					'description' => 'Read the site operating brief before interpreting the design image.',
					'use_result'  => 'Use the site context as the filter for what should be carried into the build.',
				),
				array(
					'step'        => 2,
					'title'       => 'Store the design reference',
					'tool'        => 'wp_upload_design_reference',
					'params'      => array(
						'title'       => '(reference title)',
						'image_url'   => '(optional external image url)',
						'image_base64' => '(optional base64 image data)',
						'media_id'    => '(optional existing attachment id)',
						'page_intent' => '(landing_page/blog_post/product_page/etc)',
						'style'       => '(style label)',
						'notes'       => '(short design notes)',
					),
					'description' => 'Create a reusable design reference so the image is not lost in chat context.',
					'use_result'  => 'Every approved image should become a named reusable reference inside the site.',
				),
				array(
					'step'        => 3,
					'title'       => 'Inspect the stored reference',
					'tool'        => 'wp_get_design_reference',
					'params'      => array(
						'id' => '(design reference id)',
					),
					'description' => 'Read the stored image, intent, style, and reuse notes.',
					'use_result'  => 'Clarify whether the reference should drive a full page archetype, reusable parts, or both.',
				),
				array(
					'step'        => 4,
					'title'       => 'Check for matching archetypes first',
					'tool'        => 'wp_list_elementor_archetypes',
					'params'      => array(),
					'description' => 'Prefer an existing local archetype before inventing new structure.',
					'use_result'  => 'Reuse the nearest stable structure whenever possible.',
				),
				array(
					'step'        => 5,
					'title'       => 'Create the draft page from local structure',
					'tool'        => 'wp_create_landing_page',
					'params'      => array(
						'title'  => '(draft title)',
						'status' => 'draft',
					),
					'description' => 'Build the new page using Elementor and the reference as visual guidance, not as a literal one-off recreation.',
					'use_result'  => 'The page should fit the site character and still reflect the approved design reference. When possible, the build path should also save reusable sections back into the parts library.',
				),
				array(
					'step'        => 6,
					'title'       => 'Promote reusable sections',
					'tool'        => 'wp_create_elementor_part',
					'params'      => array(
						'title'      => '(part title)',
						'part_kind'  => '(hero/faq/cta/etc)',
						'part_style' => '(style label)',
					),
					'description' => 'Save strong sections from the page into the Elementor parts library.',
					'use_result'  => 'The next build should be able to start from reusable pieces instead of the raw image.',
				),
				array(
					'step'        => 7,
					'title'       => 'Link the reference to resulting assets',
					'tool'        => 'wp_update_design_reference',
					'params'      => array(
						'id'                  => '(design reference id)',
						'linked_archetype_ids' => array( '(optional archetype/template id)' ),
						'linked_part_ids'     => array( '(created part ids)' ),
					),
					'description' => 'Preserve which archetypes or parts came from this image reference.',
					'use_result'  => 'Humans and models can trace the lineage later.',
				),
				array(
					'step'        => 8,
					'title'       => 'Preview and verify',
					'tool'        => 'wp_preview_elementor',
					'params'      => array(
						'id' => '(page id)',
					),
					'description' => 'Verify the final draft respects both the reference and the site system.',
					'use_result'  => 'The output should be reusable site structure, not a one-off mockup.',
				),
			),
			'tips'          => array(
				'Use image-based design references as durable site memory, not as throwaway attachments.',
				'The reference should inform structure and tone, but the final page must still obey site context and existing archetypes.',
				'If the build flow can auto-save starter sections as reusable parts and link them back to the design reference, prefer that over leaving the reference unconnected.',
				'Whenever the reference yields a strong hero, proof block, pricing band, or CTA, save it back into the parts library.',
			),
		);
	}

	/**
	 * Build a WooCommerce product from a stable archetype.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_build_product_from_archetype() {
		return array(
			'name'          => 'build_product_from_archetype',
			'title'         => 'Build Product From Archetype',
			'description'   => 'Create WooCommerce products from a stable archetype such as simple physical product, variable apparel product, digital product, or bundle.',
			'prerequisites' => array( 'WooCommerce should be active', 'A canonical product archetype should be defined in the site context or product library' ),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Read the site and product context',
					'tool'        => 'wp_get_site_context',
					'params'      => array(),
					'description' => 'Read the site context and identify the expected product archetypes, merchandising tone, and pricing/content rules.',
					'use_result'  => 'Choose the nearest product archetype before creating anything.',
				),
				array(
					'step'        => 2,
					'title'       => 'Inspect similar products',
					'tool'        => 'wc_list_product_archetypes',
					'params'      => array(),
					'description' => 'Review stored product archetypes for the same class before inspecting live products.',
					'use_result'  => 'Identify the canonical product shape for this product class.',
				),
				array(
					'step'        => 3,
					'title'       => 'Apply the archetype to a new draft product',
					'tool'        => 'wc_apply_product_archetype',
					'params'      => array(
						'id'     => '(product_archetype_id)',
						'name'   => '(product name)',
						'status' => 'draft',
					),
					'description' => 'Create the draft WooCommerce product from the stored archetype instead of rebuilding the structure manually.',
					'use_result'  => 'Save the product ID.',
				),
				array(
					'step'        => 4,
					'title'       => 'Refine the product-specific fields',
					'tool'        => 'wc_update_product',
					'params'      => array(
						'id'                => '(product_id)',
						'short_description' => '(archetype_aligned_short_description)',
						'description'       => '(archetype_aligned_long_description)',
					),
					'description' => 'Fill in the product using the canonical description structure for this product class instead of inventing a new layout.',
					'use_result'  => 'Keep specs, proof, CTA order, and FAQ structure consistent.',
				),
				array(
					'step'        => 5,
					'title'       => 'Set taxonomy and merchandising data',
					'tool'        => 'wc_update_product',
					'params'      => array(
						'id'         => '(product_id)',
						'categories' => '(category_ids)',
						'tags'       => '(tag_ids)',
						'sku'        => '(sku_if_known)',
					),
					'description' => 'Apply categories, tags, pricing, inventory, and merchandising metadata consistently for the chosen archetype.',
					'use_result'  => 'The product should match the structure of similar products.',
				),
				array(
					'step'        => 6,
					'title'       => 'Save reusable Elementor sections if used',
					'tool'        => 'wp_create_elementor_part_from_section',
					'params'      => array(
						'page_id'    => '(product_or_related_landing_page_id)',
						'element_id' => '(candidate_section_id)',
						'title'      => '(descriptive part name)',
						'part_kind'  => '(faq_or_guarantee_or_feature_band)',
					),
					'description' => 'If the product uses Elementor for its product page or related landing page, promote any reusable product section into the parts library.',
					'use_result'  => 'Product-specific sections become reusable assets for future products.',
				),
				array(
					'step'        => 7,
					'title'       => 'Review and keep draft',
					'tool'        => 'wc_get_product',
					'params'      => array(
						'id' => '(product_id)',
					),
					'description' => 'Inspect the final product and keep it as draft unless the user explicitly asked to publish.',
					'use_result'  => 'The product should follow the archetype and be ready for review.',
				),
			),
			'tips' => array(
				'Products should follow stable archetypes just like pages do.',
				'Do not invent a new product description structure if a canonical one already exists.',
				'Keep similar products structurally consistent so merchandising scales cleanly.',
				'If Elementor is involved on product-facing pages, save reusable sections back into the parts library.',
			),
		);
	}

	/**
	 * SEO audit workflow.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_seo_audit() {
		return array(
			'name'          => 'seo_audit',
			'title'         => 'SEO Audit & Fix',
			'description'   => 'Comprehensive SEO audit: detect plugin, scan all pages, generate report, and fix issues.',
			'prerequisites' => array( 'An SEO plugin must be active (Yoast, RankMath, AIOSEO, or SEOPress)' ),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Detect SEO plugin',
					'tool'        => 'wp_detect_plugins',
					'params'      => array(),
					'description' => 'Confirm which SEO plugin is active and its version.',
					'use_result'  => 'Note the active SEO plugin name for context.',
				),
				array(
					'step'        => 2,
					'title'       => 'Check SEO status',
					'tool'        => 'wp_seo_status',
					'params'      => array(),
					'description' => 'Get SEO plugin configuration and overall status.',
					'use_result'  => 'Review plugin settings and identify configuration issues.',
				),
				array(
					'step'        => 3,
					'title'       => 'Scan pages for SEO data',
					'tool'        => 'wp_list_pages',
					'params'      => array(
						'per_page' => 100,
						'status'   => 'publish',
					),
					'description' => 'Get all published pages. Then for each page, call wp_get_seo(id) and wp_analyze_seo(id) to check SEO health.',
					'use_result'  => 'Build a report of pages missing titles, descriptions, or with low scores.',
				),
				array(
					'step'        => 4,
					'title'       => 'Fix SEO issues',
					'tool'        => 'wp_bulk_seo',
					'params'      => array(
						'items' => '(array of {id, title, description} for pages needing fixes)',
					),
					'description' => 'Batch update SEO meta for all pages with issues. Include title, description, and focus_keyword for each.',
					'use_result'  => 'Confirm all items were updated successfully.',
				),
				array(
					'step'        => 5,
					'title'       => 'Verify fixes',
					'tool'        => 'wp_analyze_seo',
					'params'      => array(
						'id' => '(spot-check a few pages)',
					),
					'description' => 'Re-analyze a sample of fixed pages to confirm scores improved.',
					'use_result'  => 'Present final report to user with before/after comparison.',
				),
			),
			'tips' => array(
				'Focus on published pages first — drafts do not affect SEO.',
				'Title should be 50-60 characters, description 150-160 characters.',
				'Every page should have a unique title and description.',
				'Use focus_keyword to align content with target search terms.',
				'Check for duplicate titles/descriptions across pages.',
			),
		);
	}

	/**
	 * Content migration workflow.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_content_migration() {
		return array(
			'name'          => 'content_migration',
			'title'         => 'Content Migration',
			'description'   => 'Migrate content between pages, or restructure existing content.',
			'prerequisites' => array(),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'List all content',
					'tool'        => 'wp_list_pages',
					'params'      => array(
						'per_page' => 100,
						'status'   => 'any',
					),
					'description' => 'Get a full inventory of pages. Also use wp_list_posts() for blog posts.',
					'use_result'  => 'Create a map of existing content and identify what needs to be migrated.',
				),
				array(
					'step'        => 2,
					'title'       => 'Export layouts',
					'tool'        => 'wp_get_elementor',
					'params'      => array(
						'id' => '(source page ID)',
					),
					'description' => 'For Elementor pages, export the layout data. For non-Elementor pages, use wp_fetch(id) to get the content.',
					'use_result'  => 'Save the layout/content data for transformation.',
				),
				array(
					'step'        => 3,
					'title'       => 'Transform content',
					'tool'        => null,
					'params'      => array(),
					'description' => 'Modify the exported content as needed: update text, change images, restructure sections. This is an AI reasoning step — no tool call needed.',
					'use_result'  => 'Prepare the modified content for import.',
				),
				array(
					'step'        => 4,
					'title'       => 'Import to targets',
					'tool'        => 'wp_set_elementor',
					'params'      => array(
						'id'             => '(target page ID)',
						'elementor_data' => '(transformed layout data)',
					),
					'description' => 'Push transformed content to target pages. For non-Elementor content, use wp_update_page(id, content=...).',
					'use_result'  => 'Check for warnings in the response.',
				),
				array(
					'step'        => 5,
					'title'       => 'Verify migration',
					'tool'        => 'wp_get_elementor_summary',
					'params'      => array(
						'id' => '(target page ID)',
					),
					'description' => 'Verify the migrated content is correct. Use wp_screenshot_url for visual verification.',
					'use_result'  => 'Confirm content matches expectations. Fix any issues.',
				),
			),
			'tips' => array(
				'Use wp_clone_page(id) for simple page duplication.',
				'Use wp_bulk_find_replace to update URLs or text across Elementor data.',
				'Back up important pages before overwriting: read the content first and save it.',
				'For large migrations, work in batches and verify after each batch.',
			),
		);
	}

	/**
	 * Site redesign workflow.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_site_redesign() {
		return array(
			'name'          => 'site_redesign',
			'title'         => 'Site Redesign',
			'description'   => 'Update site-wide design: colors, typography, globals, and rebuild pages to match.',
			'prerequisites' => array( 'Elementor must be active' ),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Set site context',
					'tool'        => 'wp_set_site_context',
					'params'      => array(
						'context' => '(markdown design brief: colors, fonts, spacing, layout rules)',
					),
					'description' => 'Define the new design system. This context will guide all page building. Include color palette, typography scale, spacing rules, and component patterns.',
					'use_result'  => 'Confirm context was saved.',
				),
				array(
					'step'        => 2,
					'title'       => 'Get current Elementor globals',
					'tool'        => 'wp_get_elementor_globals',
					'params'      => array(),
					'description' => 'Read the current global colors and typography settings.',
					'use_result'  => 'Understand current state before making changes.',
				),
				array(
					'step'        => 3,
					'title'       => 'Update Elementor globals',
					'tool'        => 'wp_set_elementor_globals',
					'params'      => array(
						'colors'     => '(array of global color definitions)',
						'typography' => '(array of global typography definitions)',
					),
					'description' => 'Set new global colors and typography. These affect all elements using global styles.',
					'use_result'  => 'Confirm globals were updated.',
				),
				array(
					'step'        => 4,
					'title'       => 'Update custom CSS',
					'tool'        => 'wp_set_custom_css',
					'params'      => array(
						'css'  => '(updated CSS rules)',
						'mode' => 'replace',
					),
					'description' => 'Replace site-wide custom CSS with new design rules if needed.',
					'use_result'  => 'Confirm CSS was updated.',
				),
				array(
					'step'        => 5,
					'title'       => 'Rebuild pages',
					'tool'        => 'wp_set_elementor',
					'params'      => array(
						'id'             => '(page ID)',
						'elementor_data' => '(new page layout following new design)',
					),
					'description' => 'Rebuild each page using the new design system. Start with the homepage, then other key pages. Use wp_get_site_context() to reference the design rules.',
					'use_result'  => 'Check warnings and verify each page.',
				),
				array(
					'step'        => 6,
					'title'       => 'Regenerate CSS and verify',
					'tool'        => 'wp_regenerate_elementor_css',
					'params'      => array(),
					'description' => 'Force Elementor to regenerate all CSS files, then screenshot key pages for verification.',
					'use_result'  => 'Review screenshots to confirm the redesign looks correct.',
				),
			),
			'tips' => array(
				'Start with the site_context — it acts as a living style guide for AI.',
				'Update globals before rebuilding pages — global color/font changes propagate automatically.',
				'Work on one page at a time and verify before moving to the next.',
				'Keep the old design data backed up (read before overwriting).',
				'Use wp_get_elementor(id) to reference existing page structures for consistency.',
			),
		);
	}

	/**
	 * Menu setup workflow.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_menu_setup() {
		return array(
			'name'          => 'menu_setup',
			'title'         => 'Menu Setup',
			'description'   => 'Create, populate, and assign navigation menus to theme locations.',
			'prerequisites' => array(),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'List existing menus and locations',
					'tool'        => 'wp_list_menus',
					'params'      => array(),
					'description' => 'See what menus already exist. Also call wp_list_menu_locations() to see available theme locations.',
					'use_result'  => 'Identify which locations need menus and if any existing menus can be reused.',
				),
				array(
					'step'        => 2,
					'title'       => 'List pages for menu items',
					'tool'        => 'wp_list_pages',
					'params'      => array(
						'status'   => 'publish',
						'per_page' => 100,
					),
					'description' => 'Get all published pages to determine which should be in the menu.',
					'use_result'  => 'Select page IDs for the menu.',
				),
				array(
					'step'        => 3,
					'title'       => 'Create menu with pages',
					'tool'        => 'wp_setup_menu',
					'params'      => array(
						'name'     => '(menu name)',
						'location' => '(theme location slug)',
						'page_ids' => '(array of page IDs)',
					),
					'description' => 'Create a new menu, add pages, and assign to a theme location in one call. For simple menus, this is all you need.',
					'use_result'  => 'Note the menu_id for additional items.',
				),
				array(
					'step'        => 4,
					'title'       => 'Add custom items and sub-menus',
					'tool'        => 'wp_add_menu_item',
					'params'      => array(
						'menu_id'   => '(menu_id from step 3)',
						'title'     => '(item title)',
						'type'      => 'custom',
						'url'       => '(URL)',
						'parent_id' => '(parent item ID for sub-menus, 0 for top level)',
					),
					'description' => 'Add custom links, external URLs, or sub-menu items as needed.',
					'use_result'  => 'Build out the full menu structure.',
				),
				array(
					'step'        => 5,
					'title'       => 'Reorder and verify',
					'tool'        => 'wp_reorder_menu_items',
					'params'      => array(
						'menu_id' => '(menu_id)',
						'items'   => '(array of {id, position, parent_id})',
					),
					'description' => 'Reorder items if needed. Then call wp_list_menu_items(menu_id) to verify the final structure.',
					'use_result'  => 'Confirm menu structure matches expectations.',
				),
			),
			'tips' => array(
				'Use wp_list_menu_locations() to find the correct location slug (e.g., "primary", "footer").',
				'wp_setup_menu is the quickest way to create a basic menu.',
				'For sub-menus, add parent items first, then children with parent_id set.',
				'Most themes support 2-3 levels of menu nesting.',
				'Test the menu by visiting the site after setup.',
			),
		);
	}

	/**
	 * Media management workflow.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_media_management() {
		return array(
			'name'          => 'media_management',
			'title'         => 'Media Management',
			'description'   => 'Audit, upload, and organize media assets.',
			'prerequisites' => array(),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Audit current media',
					'tool'        => 'wp_list_media',
					'params'      => array(
						'per_page' => 100,
					),
					'description' => 'List all media library items. Filter by mime_type if needed (e.g., "image" for images only).',
					'use_result'  => 'Identify existing assets, missing images, and items to replace.',
				),
				array(
					'step'        => 2,
					'title'       => 'Upload new assets',
					'tool'        => 'wp_upload_media_from_url',
					'params'      => array(
						'url' => '(image URL)',
					),
					'description' => 'Upload images from URLs. For AI-generated images, use wp_generate_image(prompt) if configured. For stock photos, use wp_search_stock_photos(query).',
					'use_result'  => 'Save the returned media IDs for use as featured images or in Elementor.',
				),
				array(
					'step'        => 3,
					'title'       => 'Set featured images',
					'tool'        => 'wp_set_featured_image',
					'params'      => array(
						'id'       => '(post/page ID)',
						'image_id' => '(media ID from step 2)',
					),
					'description' => 'Assign uploaded images as featured images for posts and pages.',
					'use_result'  => 'Confirm featured image was set.',
				),
				array(
					'step'        => 4,
					'title'       => 'Generate alt text',
					'tool'        => 'wp_generate_alt_text',
					'params'      => array(
						'id' => '(media ID)',
					),
					'description' => 'Auto-generate alt text for images using AI (requires OpenAI integration). Good for accessibility and SEO.',
					'use_result'  => 'Review generated alt text for accuracy.',
				),
				array(
					'step'        => 5,
					'title'       => 'Clean up unused media',
					'tool'        => 'wp_delete_media',
					'params'      => array(
						'id' => '(media ID to delete)',
					),
					'description' => 'Remove unused or duplicate media items to keep the library clean.',
					'use_result'  => 'Confirm deletion.',
				),
			),
			'tips' => array(
				'URL upload is the easiest method for AI assistants.',
				'Always set alt text on images for accessibility and SEO.',
				'Use wp_integrations_status() to check if AI image generation is configured.',
				'WordPress auto-generates multiple sizes for uploaded images.',
				'Use mime_type filter in wp_list_media to find specific file types.',
			),
		);
	}

	/**
	 * Form setup workflow.
	 *
	 * @return array Workflow data.
	 */
	public static function workflow_form_setup() {
		return array(
			'name'          => 'form_setup',
			'title'         => 'Form Setup & Embedding',
			'description'   => 'Detect forms, inspect their fields, and embed them into Elementor pages.',
			'prerequisites' => array( 'A forms plugin must be active (CF7, WPForms, Gravity Forms, or Ninja Forms)' ),
			'steps'         => array(
				array(
					'step'        => 1,
					'title'       => 'Detect form plugins',
					'tool'        => 'wp_forms_status',
					'params'      => array(),
					'description' => 'Check which form plugins are active and how many forms exist.',
					'use_result'  => 'Note the active plugin to determine the correct embedding method.',
				),
				array(
					'step'        => 2,
					'title'       => 'List available forms',
					'tool'        => 'wp_list_forms',
					'params'      => array(),
					'description' => 'Get all forms with their IDs, titles, and plugins.',
					'use_result'  => 'Choose the form to embed. Note the form ID.',
				),
				array(
					'step'        => 3,
					'title'       => 'Inspect form',
					'tool'        => 'wp_get_form',
					'params'      => array(
						'form_id' => '(form ID from step 2)',
					),
					'description' => 'Get form fields and settings to understand what the form collects.',
					'use_result'  => 'Use this info to decide placement and context on the page.',
				),
				array(
					'step'        => 4,
					'title'       => 'Embed in Elementor page',
					'tool'        => 'wp_set_elementor',
					'params'      => array(
						'id'             => '(page ID)',
						'elementor_data' => '(layout with form widget embedded)',
					),
					'description' => "Add the form widget to your Elementor layout.\n"
						. "- CF7: Use shortcode widget with [contact-form-7 id=\"X\"]\n"
						. "- WPForms: Use wpforms widget with form_id setting\n"
						. "- Gravity Forms: Use shortcode widget with [gravityform id=\"X\"]\n"
						. "See wp_get_guide(topic=\"forms\") for exact Elementor JSON syntax.",
					'use_result'  => 'Check response warnings for any issues.',
				),
				array(
					'step'        => 5,
					'title'       => 'Verify form renders',
					'tool'        => 'wp_screenshot_url',
					'params'      => array(
						'url' => '(page URL with form)',
					),
					'description' => 'Take a screenshot to verify the form renders correctly on the page.',
					'use_result'  => 'Confirm form appears and is properly styled.',
				),
			),
			'tips' => array(
				'CF7 and Gravity Forms use shortcode widgets in Elementor.',
				'WPForms has a native Elementor widget.',
				'If Elementor Pro is active, it has its own built-in form widget.',
				'Always check wp_forms_status() first — it tells you which embed method to use.',
				'Test form submission manually after embedding.',
			),
		);
	}
}
