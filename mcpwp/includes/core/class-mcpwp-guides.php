<?php
/**
 * Guides & Documentation
 *
 * Provides context-aware guides for AI assistants on how to use
 * MCPWP tools effectively.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guides class.
 *
 * Returns detailed, topic-specific guides for AI assistants.
 * Topics are dynamically filtered based on active plugins.
 */
class Mcpwp_Guides {

	/**
	 * Get all available guide topics with descriptions.
	 *
	 * Filters topics based on active plugins/capabilities.
	 *
	 * @return array List of available topics.
	 */
	public static function get_topics() {
		$core         = new Mcpwp_Core();
		$capabilities = $core->get_capabilities();

		$topics = array(
			array(
				'topic'       => 'elementor',
				'title'       => 'Elementor Page Builder',
				'description' => 'Widget reference, layout modes (section vs container), nesting rules, responsive breakpoints, and common widget settings.',
				'requires'    => 'elementor',
			),
			array(
				'topic'       => 'seo',
				'title'       => 'SEO Management',
				'description' => 'Yoast vs RankMath field mapping, bulk SEO operations, noindex/nofollow, Open Graph and Twitter meta.',
				'requires'    => 'seo',
			),
			array(
				'topic'       => 'menus',
				'title'       => 'Navigation Menus',
				'description' => 'Menu structure, theme locations, item types (custom link, page, category), nesting and ordering.',
				'requires'    => null,
			),
			array(
				'topic'       => 'media',
				'title'       => 'Media Management',
				'description' => 'Upload methods (file, URL, base64), supported formats, featured images, and media library management.',
				'requires'    => null,
			),
			array(
				'topic'       => 'content',
				'title'       => 'Content Management',
				'description' => 'Post types, taxonomies, block editor vs classic, custom fields, bulk operations, and content search.',
				'requires'    => null,
			),
			array(
				'topic'       => 'forms',
				'title'       => 'Forms Integration',
				'description' => 'CF7, WPForms, and Gravity Forms detection, listing, inspection, and embedding via Elementor.',
				'requires'    => 'forms',
			),
			array(
				'topic'       => 'woocommerce',
				'title'       => 'WooCommerce',
				'description' => 'Products, orders, and categories management via MCPWP tools.',
				'requires'    => 'woocommerce',
			),
			array(
				'topic'       => 'learnpress',
				'title'       => 'LearnPress LMS',
				'description' => 'Course management, curriculum structure (sections, lessons, quizzes), meta fields, categories, and enrollment stats.',
				'requires'    => 'learnpress',
			),
			array(
				'topic'       => 'workflows',
				'title'       => 'Workflow Templates',
				'description' => 'Step-by-step guides for common tasks: building landing pages, SEO audits, site redesign, menu setup, and more.',
				'requires'    => null,
			),
			array(
				'topic'       => 'onboarding',
				'title'       => 'MCP Onboarding',
				'description' => 'Required startup sequence for a newly connected model: introspection, site context, archetypes, reusable parts, and execution rules.',
				'requires'    => null,
			),
			array(
				'topic'       => 'troubleshooting',
				'title'       => 'Troubleshooting',
				'description' => 'Common errors, debugging tips, and fixes for frequent issues with MCPWP tools.',
				'requires'    => null,
			),
			array(
				'topic'       => 'cloudflare',
				'title'       => 'Cloudflare Integration',
				'description' => 'Connecting a Cloudflare zone, fixing Bot Fight Mode blocking MCP requests, cache purge, and Speed settings that affect Core Web Vitals.',
				'requires'    => null,
			),
		);

		// Filter based on active capabilities.
		$has_seo   = ! empty( $capabilities['yoast'] )
			|| ! empty( $capabilities['rankmath'] )
			|| ! empty( $capabilities['aioseo'] )
			|| ! empty( $capabilities['seopress'] );
		$has_forms = ! empty( $capabilities['cf7'] )
			|| ! empty( $capabilities['wpforms'] )
			|| ! empty( $capabilities['gravityforms'] )
			|| ! empty( $capabilities['ninjaforms'] );

		$capability_map = array(
			'elementor'   => ! empty( $capabilities['elementor'] ),
			'seo'         => $has_seo,
			'forms'       => $has_forms,
			'woocommerce' => ! empty( $capabilities['woocommerce'] ),
			'learnpress'  => ! empty( $capabilities['learnpress'] ),
		);

		$filtered = array();
		foreach ( $topics as $topic ) {
			$req = $topic['requires'];
			if ( null === $req || ( isset( $capability_map[ $req ] ) && $capability_map[ $req ] ) ) {
				unset( $topic['requires'] );
				$filtered[] = $topic;
			}
		}

		return $filtered;
	}

	/**
	 * Get a guide by topic.
	 *
	 * @param string $topic Topic slug.
	 * @return array|WP_Error Guide content or error.
	 */
	public static function get_guide( $topic ) {
		$method = 'guide_' . $topic;

		if ( ! method_exists( __CLASS__, $method ) ) {
			return new WP_Error(
				'invalid_topic',
				sprintf( 'Unknown guide topic: %s. Call wp_get_guide() with no topic to see available topics.', $topic ),
				array( 'status' => 404 )
			);
		}

		return call_user_func( array( __CLASS__, $method ) );
	}

	/**
	 * Elementor guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_elementor() {
		$core         = new Mcpwp_Core();
		$capabilities = $core->get_capabilities();
		$layout_mode  = isset( $capabilities['elementor_layout_mode'] ) ? $capabilities['elementor_layout_mode'] : 'section';

		return array(
			'topic'   => 'elementor',
			'title'   => 'Elementor Page Builder Guide',
			'layout_mode' => $layout_mode,
			'sections' => array(
				array(
					'heading' => 'Layout Modes',
					'content' => 'Elementor has three possible layout modes. This site uses: ' . $layout_mode . ".\n\n"
						. "**Section mode (classic):** section -> column(s) -> widget(s)\n"
						. "**Container mode (flexbox):** container -> container(s) -> widget(s)\n"
						. "**Atomic mode (Editor V4):** e-div-block/e-flexbox/e-grid -> more of the same -> leaf elements (e-heading, e-button, ...). See the 'Atomic Elements' section below — the data shape is different from the other two modes.\n\n"
						. "Always check the layout mode via wp_site_info or wp_introspect before building pages. Sending the wrong element types for this site's mode (e.g. container elements on a section-mode site, or atomic elements on a site without Atomic Elements active) is rejected up front with a clear error — it will not silently corrupt the page, but it also will not work, so check first.",
				),
				array(
					'heading' => 'Element Structure',
					'content' => "Every element MUST have a unique `id` of exactly 7 alphanumeric characters (`a-z`, `A-Z`, `0-9`). The plugin auto-generates missing IDs.\n\n"
						. "**Why exactly 7.** Elementor Pro's REST endpoints check `widget_id` with `is_widget_id_valid()`: 7 characters, alphanumeric, nothing else. The Search widget sends its own id to `refresh-search` for live results, and a Taxonomy Filter sends the id of the Loop Grid named in its `selected_element` to `refresh-loop`. Any other id gets `400 rest_invalid_param` and the front end does nothing: the page renders, the results panel stays empty, the filter chips do not filter. Elementor's editor generates 7-character ids, but pages built by earlier MCPWP versions (which generated 8) or by other tools may carry other lengths.\n\n"
						. "**What the plugin does about it.** Every Elementor write that validates the tree gives each non-conforming id an AJAX feature depends on (a Search widget, a Loop Grid a Taxonomy Filter targets) its own warning, with its path, saying that feature will fail. Every other non-conforming id renders fine, so they are folded into one summary warning with a count and a sample of paths. It does not rewrite existing ids on its own, because ids are CSS selectors (`.elementor-element-<id>`) and other widgets point at them. To rewrite them, call `wp_patch_elementor` with `repair_ids=true` (no operations needed; like any validated write it also applies the standard fixes listed in its warnings), or pass `repair_ids=true` to `wp_set_elementor` (with `dry_run=true` to preview). New ids are derived from the page and the old id, so the same data on the same page always gets the same ids and a preview is exactly what the write applies. The response's `id_repair` lists every old -> new id; the references it repointed (Taxonomy Filter `selected_element`, Off-canvas dynamic tags and `elementor-action` links); and `possible_references`: other settings in this page's data that mention an old id, including URL-encoded or base64 text, which were left alone. Page-level custom CSS, kit CSS, Custom Code and other pages are not searched: update any that name an old id by hand, and purge page caches so visitors get the new ids.\n\n"
						. "**Section mode structure:**\n"
						. "```json\n"
						. "[{\n"
						. "  \"id\": \"sec1234\", \"elType\": \"section\",\n"
						. "  \"settings\": {},\n"
						. "  \"elements\": [{\n"
						. "    \"id\": \"col1234\", \"elType\": \"column\",\n"
						. "    \"settings\": {\"_column_size\": 100},\n"
						. "    \"elements\": [{ \"id\": \"wid1234\", \"elType\": \"widget\", \"widgetType\": \"heading\", \"settings\": {\"title\": \"Hello\"} }]\n"
						. "  }]\n"
						. "}]\n"
						. "```\n\n"
						. "**Container mode structure:**\n"
						. "```json\n"
						. "[{\n"
						. "  \"id\": \"con1234\", \"elType\": \"container\",\n"
						. "  \"settings\": {\"flex_direction\": \"row\"},\n"
						. "  \"elements\": [{ \"id\": \"wid1234\", \"elType\": \"widget\", \"widgetType\": \"heading\", \"settings\": {\"title\": \"Hello\"} }]\n"
						. "}]\n"
						. "```",
				),
				array(
					'heading' => 'Atomic Elements (Editor V4)',
					'content' => "Only applies when elementor_layout_mode is \"atomic\" (Elementor's Atomic Elements / Editor V4 experiment active). Do not send these element types otherwise — they will be rejected up front.\n\n"
						. "**Key difference from section/container mode:** atomic elements are typed purely by `elType` — there is no separate `widgetType`. A heading is `{\"elType\": \"e-heading\", ...}`, not `{\"elType\": \"widget\", \"widgetType\": \"e-heading\"}`.\n\n"
						. "**Container-style elements** (can nest anything, including each other): `e-div-block`, `e-flexbox`, `e-grid`.\n"
						. "**Leaf content elements:** `e-heading`, `e-paragraph`, `e-button`, `e-image`, `e-svg`, `e-divider`, `e-youtube`, `e-self-hosted-video`.\n\n"
						. "**Every prop value must be wrapped**, not a plain scalar:\n"
						. "```json\n"
						. "{\"\$\$type\": \"<kind>\", \"value\": <actual>}\n"
						. "```\n"
						. "e.g. `{\"title\": {\"\$\$type\": \"string\", \"value\": \"Hello\"}}` — a plain `{\"title\": \"Hello\"}` is invalid and will be flagged by dry_run.\n\n"
						. "**Example structure:**\n"
						. "```json\n"
						. "[{\n"
						. "  \"id\": \"div123ab\", \"elType\": \"e-div-block\",\n"
						. "  \"settings\": {}, \"styles\": {},\n"
						. "  \"elements\": [{\n"
						. "    \"id\": \"hed456cd\", \"elType\": \"e-heading\",\n"
						. "    \"settings\": {\n"
						. "      \"tag\": {\"\$\$type\": \"string\", \"value\": \"h2\"},\n"
						. "      \"title\": {\"\$\$type\": \"string\", \"value\": \"Hello Atomic World\"}\n"
						. "    },\n"
						. "    \"styles\": {}\n"
						. "  }]\n"
						. "}]\n"
						. "```\n\n"
						. "**Always call `wp_get_widget_schema(widget_type)`** before setting props on an atomic element (e.g. `wp_get_widget_schema(\"e-heading\")`) — it returns the real prop list and the exact \"kind\" each one expects (string, union with an enum, etc.), taken directly from Elementor's own schema, not guessed.\n\n"
						. "**Not yet supported:** full atomic write support is still gated on stability — Elementor's own Atomic Editor has open upstream bugs around saving. wp_set_elementor/wp_add_section/wp_edit_widget do work with atomic elements today (validated with a full recursive integrity check that catches and restores anything Elementor's own save silently drops), but treat atomic pages as more likely to need a retry/verify pass than section/container pages.",
				),
				array(
					'heading' => 'Multi-Column Sections',
					'content' => "In section mode, multi-column layouts need the `structure` setting on the section:\n"
						. "- 2 columns: `\"structure\": \"20\"` with `_column_size` of 50 + 50\n"
						. "- 3 columns: `\"structure\": \"30\"` with `_column_size` of 33 + 33 + 33\n"
						. "- 4 columns: `\"structure\": \"40\"` with `_column_size` of 25 + 25 + 25 + 25\n\n"
						. "Column sizes MUST sum to 100.",
				),
				array(
					'heading' => 'Common Widget Types',
					'content' => "| Widget | widgetType | Key Settings |\n"
						. "|--------|-----------|---------------|\n"
						. "| Heading | `heading` | title, header_size (h1-h6), align |\n"
						. "| Text Editor | `text-editor` | editor (HTML content) |\n"
						. "| Image | `image` | image.url, image.id, image_size |\n"
						. "| Button | `button` | text, link.url, link.is_external, size, button_type |\n"
						. "| Icon | `icon` | selected_icon.value, selected_icon.library |\n"
						. "| Spacer | `spacer` | space.size, space.unit |\n"
						. "| Divider | `divider` | style, weight, color, width |\n"
						. "| Image Box | `image-box` | image.url, title_text, description_text |\n"
						. "| Icon Box | `icon-box` | selected_icon, title_text, description_text |\n"
						. "| Star Rating | `star-rating` | rating.value, star_style |\n"
						. "| Counter | `counter` | starting_number, ending_number, prefix, suffix |\n"
						. "| Progress Bar | `progress-bar` | title, percent |\n"
						. "| Tabs | `tabs` | tabs[].tab_title, tabs[].tab_content |\n"
						. "| Accordion | `accordion` | tabs[].tab_title, tabs[].tab_content |\n"
						. "| Video | `video` | video_type, youtube_url, vimeo_url |\n"
						. "| Google Maps | `google_maps` | address, zoom.size |\n"
						. "| Form | `form` | form_name, form_fields[] |\n\n"
						. "Use `wp_get_elementor_widgets` to list all available widgets on this site.\n"
						. "Use `wp_get_widget_schema(widget_type=\"heading\")` to get the full schema for a specific widget.",
				),
				array(
					'heading' => 'Responsive Breakpoints',
					'content' => "Elementor supports responsive settings via suffixes:\n"
						. "- Desktop (default): `align`, `padding`\n"
						. "- Tablet: `align_tablet`, `padding_tablet`\n"
						. "- Mobile: `align_mobile`, `padding_mobile`\n\n"
						. "Hide on specific devices:\n"
						. "```json\n"
						. "{\"hide_desktop\": \"yes\", \"hide_tablet\": \"\", \"hide_mobile\": \"\"}\n"
						. "```",
				),
				array(
					'heading' => 'Background & Styling',
					'content' => "Common styling keys:\n"
						. "- Background: `background_background: \"classic\"`, `background_color: \"#FFFFFF\"`\n"
						. "- Background image: `background_image.url`, `background_position`, `background_size`\n"
						. "- Background overlay: `background_overlay_background: \"classic\"`, `background_overlay_color`\n"
						. "- Padding: `padding: {top: \"40\", right: \"20\", bottom: \"40\", left: \"20\", unit: \"px\"}`\n"
						. "- Margin: `margin: {top: \"0\", bottom: \"0\", unit: \"px\"}`\n"
						. "- Typography: `typography_typography: \"custom\"`, `typography_font_family`, `typography_font_size`, `typography_font_weight`\n"
						. "- Color: `title_color`, `text_color`, `color` (varies by widget)",
				),
				array(
					'heading' => 'Relevant Tools',
					'content' => "- `wp_get_elementor(id)` — Get Elementor data for a page\n"
						. "- `wp_get_elementor_summary(id)` — Get a compact summary of the page structure\n"
						. "- `wp_set_elementor(id, elementor_data)` — Set full Elementor data for a page\n"
						. "- For large Elementor payloads on shared hosts, prefer `elementor_data_base64` or form-encoded requests to avoid WAF issues\n"
						. "- `wp_edit_section(id, section_id, elements)` — Replace a single section/container\n"
						. "- `wp_edit_widget(id, widget_id, settings)` — Update a single widget's settings\n"
						. "- `wp_get_elementor_widgets()` — List all registered widgets\n"
						. "- `wp_get_widget_schema(widget_type)` — Get the full control schema for a widget\n"
						. "- `wp_elementor_status()` — Check Elementor version and configuration\n"
						. "- `wp_preview_elementor(id)` — Get a rendered preview of the page\n"
						. "- `wp_regenerate_elementor_css()` — Force CSS regeneration after changes\n"
						. "- `wp_bulk_find_replace(id, search, replace)` — Find and replace in Elementor data",
				),
				array(
					'heading' => 'Reusable Parts Library',
					'content' => "Use Elementor professionally by treating sections as reusable parts rather than rebuilding every page from scratch.\n\n"
						. "**Recommended part categories:**\n"
						. "- Hero\n"
						. "- Social proof / logo strip\n"
						. "- Features grid\n"
						. "- Pricing block\n"
						. "- FAQ\n"
						. "- CTA band\n"
						. "- Footer promo strip\n\n"
						. "**Recommended naming pattern:**\n"
						. "- `Hero / SaaS / Dark`\n"
						. "- `Features / 3 Card / Light`\n"
						. "- `CTA / Trial / Centered`\n\n"
						. "**Professional workflow:**\n"
						. "1. Build or identify a strong section on a live page\n"
						. "2. Save it as a reusable part with kind/style metadata\n"
						. "3. Apply that part to new pages\n"
						. "4. Change only copy, media, and links for the new context\n"
						. "5. Promote good customizations back into the library as new variants\n\n"
						. "This keeps structure consistent, speeds up page creation, and reduces layout drift across the site.",
				),
				array(
					'heading' => 'Page Archetypes',
					'content' => "Treat repeatable page classes as stable archetypes.\n\n"
						. "**Examples of page archetypes:**\n"
						. "- Blog Post\n"
						. "- Service Page\n"
						. "- Landing Page\n"
						. "- About Page\n"
						. "- Case Study\n"
						. "- Contact Page\n\n"
						. "**Rule:** use one canonical full-page Elementor template for each page class instead of redesigning the whole page every time.\n\n"
						. "**Useful archetype tools:**\n"
						. "- `wp_list_elementor_archetypes()`\n"
						. "- `wp_get_elementor_archetype(id)`\n"
						. "- `wp_create_elementor_archetype(title, archetype_scope, archetype_class, archetype_style)`\n"
						. "- `wp_apply_elementor_archetype(id, page_id)`\n\n"
						. "**Professional model:**\n"
						. "- Archetype = the full-page skeleton and section order\n"
						. "- Part = a reusable section inside or across archetypes\n\n"
						. "For example, a blog post archetype might define hero/title area, author block, featured image area, table of contents, article body wrapper, CTA band, and related posts block. A service page archetype might define hero, proof, problem/solution, features, FAQ, and CTA.",
				),
				array(
					'heading' => 'Parts Workflow',
					'content' => "Use these tools together when building from reusable parts:\n"
						. "- `wp_list_elementor_parts()` — inspect the current parts library\n"
						. "- `wp_get_elementor_part(id)` — inspect a saved part and its metadata\n"
						. "- `wp_get_elementor_summary(id)` — inspect source pages and find section IDs\n"
						. "- `wp_create_elementor_part_from_section(page_id, element_id, title, part_kind, part_style, part_tags)` — extract a reusable part from a live page\n"
						. "- `wp_create_elementor_part(title, elementor_data, part_kind, part_style, part_tags)` — create a canonical part directly from Elementor JSON\n"
						. "- `wp_create_page(title, status)` — create the destination page\n"
						. "- `wp_apply_elementor_part(id, page_id, mode, position)` — insert a reusable part into a page or replace the full page from the part\n"
						. "- `wp_edit_widget(id, widget_id, settings)` — adapt copy and links without rebuilding structure\n"
						. "- `wp_preview_elementor(id)` — verify the assembled page before publishing\n\n"
						. "The goal is not just to generate pages. The goal is to build a growing library of stable, reusable sections that make future pages faster and more consistent.",
				),
				array(
					'heading' => 'Structured Creation Rules',
					'content' => "When creating pages with Elementor, follow these rules:\n"
						. "1. Choose the page archetype first. Do not invent the page structure from zero if the page belongs to an existing class.\n"
						. "2. Reuse the canonical archetype template for that page class.\n"
						. "3. Reuse existing parts before creating new ones.\n"
						. "4. If a new or improved section is reusable, save it to the parts library before finishing the page.\n"
						. "5. Name both archetypes and parts clearly by intent and style.\n\n"
						. "The system should get more structured over time, not less. Every good page should either reuse a known structure or improve the library for future pages.",
				),
			),
		);
	}

	/**
	 * SEO guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_seo() {
		$core         = new Mcpwp_Core();
		$capabilities = $core->get_capabilities();

		$active_plugin = 'none';
		if ( ! empty( $capabilities['yoast'] ) ) {
			$active_plugin = 'yoast';
		} elseif ( ! empty( $capabilities['rankmath'] ) ) {
			$active_plugin = 'rankmath';
		} elseif ( ! empty( $capabilities['aioseo'] ) ) {
			$active_plugin = 'aioseo';
		} elseif ( ! empty( $capabilities['seopress'] ) ) {
			$active_plugin = 'seopress';
		}

		return array(
			'topic'        => 'seo',
			'title'        => 'SEO Management Guide',
			'active_plugin' => $active_plugin,
			'sections'     => array(
				array(
					'heading' => 'SEO Plugin Detection',
					'content' => 'Active SEO plugin: **' . $active_plugin . "**\n\n"
						. "MCPWP auto-detects and normalizes SEO fields across plugins. The `wp_get_seo` and `wp_set_seo` tools work with any supported SEO plugin.\n\n"
						. "Use `wp_detect_plugins()` to confirm which SEO plugin is active.",
				),
				array(
					'heading' => 'Field Mapping (Normalized)',
					'content' => "MCPWP normalizes SEO fields so you can use the same keys regardless of plugin:\n\n"
						. "| Field | Description |\n"
						. "|-------|-------------|\n"
						. "| `title` | SEO title / meta title |\n"
						. "| `description` | Meta description |\n"
						. "| `focus_keyword` | Primary keyword / keyphrase |\n"
						. "| `noindex` | Prevent search engine indexing (boolean) |\n"
						. "| `nofollow` | Prevent link following (boolean) |\n"
						. "| `canonical_url` | Canonical URL override |\n"
						. "| `og_title` | Open Graph title |\n"
						. "| `og_description` | Open Graph description |\n"
						. "| `og_image` | Open Graph image URL |\n"
						. "| `twitter_title` | Twitter card title |\n"
						. "| `twitter_description` | Twitter card description |\n"
						. "| `twitter_image` | Twitter card image URL |",
				),
				array(
					'heading' => 'Bulk SEO Operations',
					'content' => "Use `wp_bulk_seo` to update SEO fields for multiple posts/pages at once:\n"
						. "```json\n"
						. "wp_bulk_seo(updates=[\n"
						. "  {\"id\": 10, \"title\": \"My Page Title\", \"description\": \"Page description\"},\n"
						. "  {\"id\": 20, \"title\": \"Another Page\", \"noindex\": true}\n"
						. "])\n"
						. "```\n\n"
						. "Use `wp_analyze_seo(id)` to get an SEO analysis and score for a specific page.\n"
						. "Use `wp_seo_status()` to see which SEO plugin is active and its version.",
				),
				array(
					'heading' => 'Noindex / Nofollow',
					'content' => "To prevent a page from appearing in search engines:\n"
						. "```json\n"
						. "wp_set_seo(id=123, noindex=true)\n"
						. "```\n\n"
						. "For the noindex convenience wrapper, include the page or post ID:\n"
						. "```json\n"
						. "wp_set_noindex(id=123, noindex=true)\n"
						. "```\n\n"
						. "Use `wp_update_options(blog_public=false)` to discourage search engines via WordPress settings.",
				),
				array(
					'heading' => 'Relevant Tools',
					'content' => "- `wp_get_seo(id)` — Get SEO meta for a post/page\n"
						. "- `wp_set_seo(id, title, description, ...)` — Set normalized SEO meta fields\n"
						. "- `wp_analyze_seo(id)` — Analyze SEO and get score/recommendations\n"
						. "- `wp_bulk_seo(updates)` — Bulk update SEO for multiple items (`items` is accepted as an alias)\n"
						. "- `wp_seo_status()` — Check active SEO plugin and config\n"
						. "- `wp_set_noindex(id, noindex)` — Set per-page noindex",
				),
			),
		);
	}

	/**
	 * Menus guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_menus() {
		return array(
			'topic'    => 'menus',
			'title'    => 'Navigation Menus Guide',
			'sections' => array(
				array(
					'heading' => 'Menu Structure',
					'content' => "WordPress menus consist of:\n"
						. "- **Menu** — a named collection of items (e.g., \"Main Menu\", \"Footer Menu\")\n"
						. "- **Menu Location** — a theme-defined slot (e.g., \"primary\", \"footer\")\n"
						. "- **Menu Item** — a link in the menu (page, post, category, custom URL)\n\n"
						. "Menus are assigned to locations. A location can have one menu, but a menu can be assigned to multiple locations.",
				),
				array(
					'heading' => 'Menu Item Types',
					'content' => "| Type | Description | Required Fields |\n"
						. "|------|-------------|------------------|\n"
						. "| `custom` | Custom URL link | title, url |\n"
						. "| `post_type` | Link to a page/post | title, object (\"page\"/\"post\"), object_id |\n"
						. "| `taxonomy` | Link to a category/tag | title, object (\"category\"/\"post_tag\"), object_id |",
				),
				array(
					'heading' => 'Sub-menus (Nesting)',
					'content' => "Create sub-menu items by setting `parent_id` to the ID of the parent item:\n"
						. "```json\n"
						. "wp_add_menu_item(menu_id=5, title=\"Services\", type=\"custom\", url=\"/services\")\n"
						. "// Returns item_id: 101\n"
						. "wp_add_menu_item(menu_id=5, title=\"Web Design\", type=\"custom\", url=\"/services/web-design\", parent_id=101)\n"
						. "```\n\n"
						. "Most themes support 2-3 levels of nesting. Deeper nesting may not render properly.",
				),
				array(
					'heading' => 'Quick Setup',
					'content' => "Use `wp_setup_menu` for a one-shot menu creation:\n"
						. "```json\n"
						. "wp_setup_menu(name=\"Main Menu\", location=\"primary\", page_ids=[10, 20, 30])\n"
						. "```\n\n"
						. "This creates the menu, adds the specified pages as items, and assigns it to the location.",
				),
				array(
					'heading' => 'Relevant Tools',
					'content' => "- `wp_list_menus()` — List all menus\n"
						. "- `wp_list_menu_locations()` — List theme locations and assigned menus\n"
						. "- `wp_setup_menu(name, location, page_ids)` — Quick menu setup\n"
						. "- `wp_list_menu_items(menu_id)` — List items in a menu\n"
						. "- `wp_add_menu_item(menu_id, title, ...)` — Add an item\n"
						. "- `wp_update_menu_item(menu_id, item_id, ...)` — Update an item\n"
						. "- `wp_delete_menu_item(menu_id, item_id)` — Remove an item\n"
						. "- `wp_reorder_menu_items(menu_id, items)` — Reorder items\n"
						. "- `wp_delete_menu(menu_id)` — Delete a menu\n"
						. "- `wp_assign_menu_location(menu_id, location)` — Assign menu to location",
				),
			),
		);
	}

	/**
	 * Media guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_media() {
		return array(
			'topic'    => 'media',
			'title'    => 'Media Management Guide',
			'sections' => array(
				array(
					'heading' => 'Upload Methods',
					'content' => "MCPWP supports three upload methods:\n\n"
						. "1. **From URL** (recommended for AI): `wp_upload_media_from_url(url=\"https://example.com/photo.jpg\")`\n"
						. "2. **Base64**: `wp_upload_media_b64(data=\"/9j/4AAQ...\", filename=\"photo.jpg\", mime_type=\"image/jpeg\")`\n"
						. "3. **File upload**: `wp_upload_media(file=...)` — multipart form data\n\n"
						. "URL uploads are the simplest for AI assistants. The plugin downloads the file and adds it to the media library.",
				),
				array(
					'heading' => 'Supported Formats',
					'content' => "WordPress supports these formats by default:\n"
						. "- **Images**: jpg, jpeg, png, gif, webp, svg (if enabled), ico\n"
						. "- **Documents**: pdf, doc, docx, ppt, pptx, odt, xls, xlsx\n"
						. "- **Audio**: mp3, ogg, wav, m4a\n"
						. "- **Video**: mp4, m4v, mov, wmv, avi, webm, ogv\n\n"
						. "SVG support depends on plugins or theme. Maximum upload size varies by server config.",
				),
				array(
					'heading' => 'Featured Images',
					'content' => "Set a featured image (thumbnail) for any post or page:\n"
						. "```json\n"
						. "// Upload first, then set as featured\n"
						. "wp_upload_media_from_url(url=\"https://example.com/hero.jpg\")\n"
						. "// Returns: {id: 456, url: \"...\"}\n"
						. "wp_set_featured_image(id=123, image_id=456)\n"
						. "```\n\n"
						. "Or set during page/post creation:\n"
						. "```json\n"
						. "wp_create_page(title=\"My Page\", featured_media=456)\n"
						. "```",
				),
				array(
					'heading' => 'Stock Photos & AI Images',
					'content' => "If integrations are configured:\n"
						. "- `wp_search_stock_photos(query)` — Search Pexels stock photos\n"
						. "- `wp_download_stock_photo(photo_id)` — Download and add to media library\n"
						. "- `wp_generate_image(prompt)` — Generate an image with AI (DALL-E)\n"
						. "- `wp_generate_featured_image(id, prompt)` — Generate and set as featured image\n"
						. "- `wp_generate_alt_text(id)` — Auto-generate alt text for an image\n"
						. "- `wp_describe_image(id)` — Get AI description of an image\n\n"
						. "Use `wp_integrations_status()` to check if these integrations are configured.",
				),
				array(
					'heading' => 'Design References',
					'content' => "When a human shares a screenshot, exported mockup, or approved design image, store it as a reusable design reference instead of treating it like ordinary media.\n\n"
						. "- `wp_upload_design_reference(...)` — Create a reusable design reference from an image URL, base64 image, or existing media item\n"
						. "- `wp_list_design_references()` — Review stored references\n"
						. "- `wp_get_design_reference(id)` — Read the image, intent, style, and reuse notes\n"
						. "- `wp_update_design_reference(id, ...)` — Link resulting archetypes or parts back to the source image\n\n"
						. "Design references are the right intake path for screenshots from ChatGPT, Gemini, Figma exports, Stitch exports, or client-provided mockups.",
				),
				array(
					'heading' => 'Relevant Tools',
					'content' => "- `wp_list_media(per_page, page, mime_type)` — List media library items\n"
						. "- `wp_upload_media_from_url(url)` — Upload from URL\n"
						. "- `wp_upload_media_b64(data, filename, mime_type)` — Upload from base64\n"
						. "- `wp_upload_media(file)` — Upload file\n"
						. "- `wp_upload_design_reference(...)` — Store a design screenshot as a reusable reference\n"
						. "- `wp_list_design_references()` — List stored design references\n"
						. "- `wp_delete_media(id)` — Delete media item\n"
						. "- `wp_set_featured_image(id, image_id)` — Set featured image\n"
						. "- `wp_screenshot_url(url)` — Take a screenshot of a URL",
				),
			),
		);
	}

	/**
	 * Content guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_content() {
		return array(
			'topic'    => 'content',
			'title'    => 'Content Management Guide',
			'sections' => array(
				array(
					'heading' => 'Post Types',
					'content' => "WordPress has built-in and custom post types:\n\n"
						. "| Type | Tool Prefix | Description |\n"
						. "|------|------------|-------------|\n"
						. "| `post` | `wp_list_posts`, `wp_create_post` | Blog posts |\n"
						. "| `page` | `wp_list_pages`, `wp_create_page` | Static pages |\n"
						. "| (custom) | `wp_list_content(post_type=...)` | Products, courses, etc. |\n\n"
						. "Use `wp_detect_plugins()` to discover registered custom post types.",
				),
				array(
					'heading' => 'Taxonomies',
					'content' => "Taxonomies organize content:\n"
						. "- **Categories** — hierarchical (parent/child). Use `wp_list_categories()`.\n"
						. "- **Tags** — flat labels. Use `wp_list_tags()`.\n"
						. "- **Custom taxonomies** — registered by plugins (e.g., product_cat for WooCommerce).\n\n"
						. "Manage terms with `wp_create_term`, `wp_update_term`, `wp_delete_term`.",
				),
				array(
					'heading' => 'Block Editor vs Classic',
					'content' => "WordPress 5.0+ uses the Gutenberg block editor by default.\n\n"
						. "- Use `wp_get_blocks(id)` and `wp_set_blocks(id, blocks)` for block content.\n"
						. "- Use `wp_list_block_types()` to see available blocks.\n"
						. "- If Classic Editor is active, use `content` field in `wp_create_post/page`.\n\n"
						. "**Elementor pages bypass both editors.** Use `wp_set_elementor()` instead.",
				),
				array(
					'heading' => 'Custom Fields (Post Meta)',
					'content' => "Read and write custom fields with:\n"
						. "```json\n"
						. "wp_get_post_meta(id=123)\n"
						. "// Returns all meta keys and values\n\n"
						. "wp_set_post_meta(id=123, meta_key=\"my_field\", meta_value=\"hello\")\n"
						. "```\n\n"
						. "Common meta keys vary by theme and plugins. Use `wp_get_post_meta` first to discover existing keys.",
				),
				array(
					'heading' => 'Bulk Operations',
					'content' => "- `wp_bulk_create_pages(pages=[...])` — Create multiple pages at once\n"
						. "- `wp_bulk_create_posts(posts=[...])` — Create multiple posts at once\n"
						. "- `wp_bulk_update_pages(pages=[...])` — Update multiple pages\n"
						. "- `wp_bulk_update_posts(posts=[...])` — Update multiple posts\n"
						. "- `wp_batch_update(operations=[...])` — Mixed batch operations\n"
						. "- `wp_delete_all_drafts()` — Clean up all draft posts and pages",
				),
				array(
					'heading' => 'Search & Fetch',
					'content' => "- `wp_search(query, type, status)` — Search posts/pages by keyword\n"
						. "- `wp_fetch(id)` or `wp_fetch(url)` — Get a single post/page by ID or URL\n"
						. "- `wp_get_page_by_slug(slug)` — Find a page by its URL slug\n"
						. "- `wp_list_content(post_type)` — List any custom post type",
				),
				array(
					'heading' => 'Relevant Tools',
					'content' => "- `wp_list_posts`, `wp_create_post`, `wp_update_post`, `wp_delete_post`\n"
						. "- `wp_list_pages`, `wp_create_page`, `wp_update_page`, `wp_delete_page`\n"
						. "- `wp_clone_page(id)` — Duplicate a page\n"
						. "- `wp_list_categories()`, `wp_list_tags()`\n"
						. "- `wp_create_term`, `wp_update_term`, `wp_delete_term`\n"
						. "- `wp_list_drafts()`, `wp_delete_all_drafts()`",
				),
			),
		);
	}

	/**
	 * Forms guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_forms() {
		$core         = new Mcpwp_Core();
		$capabilities = $core->get_capabilities();

		$active_plugins = array();
		if ( ! empty( $capabilities['cf7'] ) ) {
			$active_plugins[] = 'Contact Form 7';
		}
		if ( ! empty( $capabilities['wpforms'] ) ) {
			$active_plugins[] = 'WPForms';
		}
		if ( ! empty( $capabilities['gravityforms'] ) ) {
			$active_plugins[] = 'Gravity Forms';
		}
		if ( ! empty( $capabilities['ninjaforms'] ) ) {
			$active_plugins[] = 'Ninja Forms';
		}

		return array(
			'topic'          => 'forms',
			'title'          => 'Forms Integration Guide',
			'active_plugins' => $active_plugins,
			'sections'       => array(
				array(
					'heading' => 'Detected Form Plugins',
					'content' => 'Active: ' . ( ! empty( $active_plugins ) ? implode( ', ', $active_plugins ) : 'None detected' )
						. "\n\nUse `wp_forms_status()` for detailed plugin and form counts.",
				),
				array(
					'heading' => 'Working with Forms',
					'content' => "1. **List forms**: `wp_list_forms()` — shows all forms across plugins\n"
						. "2. **Inspect form**: `wp_get_form(form_id)` — get form fields and settings\n"
						. "3. **View entries**: `wp_get_form_entries(form_id)` — see submitted data\n\n"
						. "Forms are identified by their plugin-specific IDs. The listing includes the plugin source.",
				),
				array(
					'heading' => 'Embedding Forms in Elementor',
					'content' => "Each form plugin has an Elementor widget:\n\n"
						. "**Contact Form 7:**\n"
						. "```json\n"
						. "{\"elType\": \"widget\", \"widgetType\": \"shortcode\", \"settings\": {\"shortcode\": \"[contact-form-7 id=\\\"123\\\" title=\\\"Contact\\\"]\"}}\n"
						. "```\n\n"
						. "**WPForms:**\n"
						. "```json\n"
						. "{\"elType\": \"widget\", \"widgetType\": \"wpforms\", \"settings\": {\"form_id\": \"123\"}}\n"
						. "```\n\n"
						. "**Gravity Forms:**\n"
						. "```json\n"
						. "{\"elType\": \"widget\", \"widgetType\": \"shortcode\", \"settings\": {\"shortcode\": \"[gravityform id=\\\"1\\\" title=\\\"true\\\"]\"}}\n"
						. "```",
				),
				array(
					'heading' => 'Relevant Tools',
					'content' => "- `wp_forms_status()` — Check form plugin status\n"
						. "- `wp_list_forms()` — List all forms\n"
						. "- `wp_get_form(form_id)` — Get form details and fields\n"
						. "- `wp_get_form_entries(form_id)` — Get submitted entries",
				),
			),
		);
	}

	/**
	 * WooCommerce guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_woocommerce() {
		return array(
			'topic'    => 'woocommerce',
			'title'    => 'WooCommerce Guide',
			'sections' => array(
				array(
					'heading' => 'Overview',
					'content' => "WooCommerce products are a custom post type (`product`). Use `wp_list_content(post_type=\"product\")` to list them.\n\n"
						. "WooCommerce data is stored in post meta and custom tables. Use `wp_get_post_meta(id)` to inspect product meta.",
				),
				array(
					'heading' => 'Product Management',
					'content' => "**List products:**\n"
						. "```json\n"
						. "wp_list_content(post_type=\"product\", status=\"publish\")\n"
						. "```\n\n"
						. "**Product meta keys:**\n"
						. "| Key | Description |\n"
						. "|-----|-------------|\n"
						. "| `_regular_price` | Regular price |\n"
						. "| `_sale_price` | Sale price |\n"
						. "| `_sku` | Stock keeping unit |\n"
						. "| `_stock` | Stock quantity |\n"
						. "| `_stock_status` | instock / outofstock |\n"
						. "| `_weight` | Product weight |\n"
						. "| `_thumbnail_id` | Featured image ID |",
				),
				array(
					'heading' => 'Product Categories',
					'content' => "WooCommerce uses the `product_cat` taxonomy:\n"
						. "```json\n"
						. "wp_create_term(taxonomy=\"product_cat\", name=\"Electronics\")\n"
						. "```",
				),
				array(
					'heading' => 'Product Archetypes',
					'content' => "Treat repeatable product classes as stable archetypes, just like repeatable page classes.\n\n"
						. "**Examples of product archetypes:**\n"
						. "- Simple physical product\n"
						. "- Variable apparel product\n"
						. "- Digital download\n"
						. "- Course / membership product\n"
						. "- Bundle / kit\n\n"
						. "**Rule:** do not reinvent product structure every time. Reuse a canonical shape for title style, short description pattern, long description sections, specs, proof, FAQ, shipping/returns, and CTA placement.\n\n"
						. "**Useful product archetype tools:**\n"
						. "- `wc_list_product_archetypes()`\n"
						. "- `wc_get_product_archetype(id)`\n"
						. "- `wc_create_product_archetype(name, archetype_class, product_type, ...)`\n"
						. "- `wc_apply_product_archetype(id, product_id|name, ...)`\n\n"
						. "When a product page uses Elementor, the same parts-library rule applies: strong product-specific sections like feature bands, comparison tables, guarantee strips, and FAQs should be saved as reusable Elementor parts.",
				),
				array(
					'heading' => 'Structured Product Rules',
					'content' => "When creating or updating products:\n"
						. "1. Identify the product archetype first.\n"
						. "2. Reuse the canonical field structure for that archetype.\n"
						. "3. Keep titles, benefit bullets, specs, pricing blocks, and FAQ structure consistent across similar products.\n"
						. "4. If the product page introduces a reusable section in Elementor, save it back into the parts library.\n"
						. "5. Do not quietly fork a product archetype without naming the new variant.",
				),
				array(
					'heading' => 'Relevant Tools',
					'content' => "- `wp_list_content(post_type=\"product\")` — List products\n"
						. "- `wp_delete_content(post_type=\"product\", id)` — Delete a product\n"
						. "- `wp_get_post_meta(id)` — Get product meta\n"
						. "- `wp_set_post_meta(id, meta_key, meta_value)` — Update product meta\n"
						. "- `wp_create_term(taxonomy=\"product_cat\", ...)` — Create product category\n"
						. "- `wp_set_featured_image(id, image_id)` — Set product image",
				),
			),
		);
	}

	/**
	 * MCP onboarding guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_onboarding() {
		return array(
			'topic'    => 'onboarding',
			'title'    => 'MCP Onboarding Guide',
			'sections' => array(
				array(
					'heading' => 'Startup Sequence',
					'content' => "When a new model connects through MCP, follow this order:\n"
						. "1. `wp_introspect()` — discover capabilities, enabled plugins, layout mode, and available tools\n"
						. "2. `wp_get_site_context()` — read the site's operating brief, design system, and content rules\n"
						. "3. `wp_get_guide(topic=\"workflows\")` — inspect the available structured workflows\n"
						. "4. If Figma is configured, call `wp_figma_status()` and then pull the approved file/frame with `wp_get_figma_file()` or `wp_get_figma_node()`\n"
						. "5. If the human supplied a screenshot or design image, store it with `wp_upload_design_reference()` and inspect it with `wp_get_design_reference()`\n"
						. "6. If working with Elementor, read `wp_get_guide(topic=\"elementor\")`\n"
						. "7. If working with products, read `wp_get_guide(topic=\"woocommerce\")`\n\n"
						. "Do not start building blind. Introspect first, then follow the relevant guide.",
				),
				array(
					'heading' => 'Operating Model',
					'content' => "The system is designed around structure reuse:\n"
						. "- Site context defines the site-level rules\n"
						. "- Page archetypes define repeatable full-page classes\n"
						. "- Product archetypes define repeatable product classes\n"
						. "- Elementor parts define reusable sections across those archetypes\n\n"
						. "The model should prefer reuse over reinvention. New work should either follow an existing archetype or improve the library for future work.",
				),
				array(
					'heading' => 'Required Rules',
					'content' => "Always follow these rules:\n"
						. "1. Read the site context before building or editing pages/products.\n"
						. "2. If an approved Figma source exists, inspect it before choosing structure.\n"
						. "3. If an approved design image exists, store it as a design reference before building.\n"
						. "4. Choose the correct archetype before writing structure.\n"
						. "5. Reuse existing Elementor parts before creating new ones.\n"
						. "6. Save strong new reusable sections back into Elementor parts before finishing.\n"
						. "7. Default new content to draft unless explicitly asked to publish.",
				),
				array(
					'heading' => 'Useful Tools',
					'content' => "- `wp_introspect()` — startup capability check\n"
						. "- `wp_get_site_context()` — site-level brief and rules\n"
						. "- `wp_figma_status()` — verify whether design context is available from Figma\n"
						. "- `wp_get_figma_file()` / `wp_get_figma_node()` — inspect approved Figma structure before building\n"
						. "- `wp_upload_design_reference()` / `wp_get_design_reference()` — preserve approved screenshots and design images as reusable site assets\n"
						. "- `wp_get_guide(topic)` — detailed domain guide\n"
						. "- `wp_get_workflow(name)` — structured task flow\n"
						. "- `wp_list_elementor_archetypes()` — discover canonical archetypes\n"
						. "- `wp_list_elementor_templates()` — full-page archetypes and templates\n"
						. "- `wp_list_elementor_parts()` — reusable sections\n"
						. "- `wc_list_products()` / `wc_get_product(id)` — product inspection\n"
						. "- `wp_get_elementor_summary(id)` — inspect reusable sections on a page",
				),
			),
		);
	}

	/**
	 * LearnPress LMS guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_learnpress() {
		return array(
			'topic'    => 'learnpress',
			'title'    => 'LearnPress LMS Guide',
			'sections' => array(
				array(
					'heading' => 'Course Structure',
					'content' => "LearnPress uses a hierarchical structure:\n\n"
						. "**Course** (`lp_course` CPT) -> **Sections** -> **Items** (Lessons/Quizzes)\n\n"
						. "- A course has metadata (price, duration, level) and a curriculum\n"
						. "- The curriculum is stored in custom tables (`learnpress_sections`, `learnpress_section_items`)\n"
						. "- Sections contain ordered items (lessons or quizzes)\n"
						. "- Lessons (`lp_lesson` CPT) hold content with optional preview\n"
						. "- Quizzes (`lp_quiz` CPT) have settings like passing grade and retake count\n"
						. "- Questions are linked to quizzes via the `learnpress_quiz_questions` table",
				),
				array(
					'heading' => 'Key Meta Fields',
					'content' => "**Course meta:**\n"
						. "| Key | Format | Example |\n"
						. "|-----|--------|--------|\n"
						. "| `_lp_regular_price` | String | \"99.00\" |\n"
						. "| `_lp_sale_price` | String | \"49.00\" |\n"
						. "| `_lp_price` | String | \"49.00\" (active price) |\n"
						. "| `_lp_duration` | String | \"10 week\", \"3 month\" |\n"
						. "| `_lp_level` | String | \"all\", \"beginner\", \"intermediate\", \"advanced\" |\n"
						. "| `_lp_requirements` | Serialized array | [\"Basic HTML\", \"CSS knowledge\"] |\n"
						. "| `_lp_target_audiences` | Serialized array | [\"Beginners\", \"Designers\"] |\n"
						. "| `_lp_key_features` | Serialized array | [\"Certificate\", \"24/7 support\"] |\n"
						. "| `_lp_faqs` | Serialized array | [[\"Q1\", \"A1\"], [\"Q2\", \"A2\"]] |\n"
						. "| `_lp_featured_review` | String | Review text |\n\n"
						. "**Lesson meta:**\n"
						. "| Key | Format | Example |\n"
						. "|-----|--------|--------|\n"
						. "| `_lp_duration` | String | \"30 minute\" |\n"
						. "| `_lp_preview` | String | \"yes\" / \"no\" |\n\n"
						. "**Quiz meta:**\n"
						. "| Key | Format | Example |\n"
						. "|-----|--------|--------|\n"
						. "| `_lp_duration` | String | \"40 minute\" |\n"
						. "| `_lp_passing_grade` | String | \"80\" (percentage) |\n"
						. "| `_lp_retake_count` | String | \"3\" (0=unlimited) |\n"
						. "| `_lp_instant_check` | String | \"yes\" / \"no\" |\n"
						. "| `_lp_review` | String | \"yes\" / \"no\" |",
				),
				array(
					'heading' => 'Curriculum Management Workflow',
					'content' => "To build a course curriculum:\n\n"
						. "1. **Create lessons** using `wp_create_lesson` (returns lesson IDs)\n"
						. "2. **Create quizzes** using `wp_create_quiz` (returns quiz IDs)\n"
						. "3. **Set the curriculum** using `wp_set_curriculum` with the lesson/quiz IDs organized into sections:\n"
						. "```json\n"
						. "wp_set_curriculum(id=123, sections=[\n"
						. "  {\n"
						. "    \"name\": \"Introduction\",\n"
						. "    \"description\": \"Getting started\",\n"
						. "    \"items\": [\n"
						. "      {\"id\": 456, \"type\": \"lp_lesson\"},\n"
						. "      {\"id\": 457, \"type\": \"lp_lesson\"},\n"
						. "      {\"id\": 458, \"type\": \"lp_quiz\"}\n"
						. "    ]\n"
						. "  },\n"
						. "  {\n"
						. "    \"name\": \"Advanced Topics\",\n"
						. "    \"items\": [\n"
						. "      {\"id\": 459, \"type\": \"lp_lesson\"},\n"
						. "      {\"id\": 460, \"type\": \"lp_quiz\"}\n"
						. "    ]\n"
						. "  }\n"
						. "])\n"
						. "```\n\n"
						. "**Important:** `wp_set_curriculum` replaces the entire curriculum. Always retrieve the current curriculum first with `wp_get_curriculum` if you only want to modify it.",
				),
				array(
					'heading' => 'Course Categories',
					'content' => "LearnPress uses the `course_category` taxonomy:\n"
						. "- `wp_list_course_categories()` — List all categories\n"
						. "- `wp_create_course_category(name=\"Web Development\")` — Create a category\n"
						. "- `wp_update_course_category(id=5, name=\"Programming\")` — Update\n"
						. "- `wp_delete_course_category(id=5)` — Delete\n\n"
						. "Assign categories when creating/updating courses:\n"
						. "```json\n"
						. "wp_create_course(title=\"PHP Basics\", categories=[\"Web Development\", \"Programming\"])\n"
						. "```",
				),
				array(
					'heading' => 'Common Mistakes',
					'content' => "1. **Duration format** — Must be a number followed by a unit: \"10 week\", \"30 minute\", \"2 hour\". Not \"10 weeks\" or \"30min\".\n"
						. "2. **Serialized arrays** — Requirements, audiences, features, and FAQs are stored as serialized PHP arrays. Always pass them as JSON arrays in the API.\n"
						. "3. **FAQs format** — FAQs are arrays of 2-element arrays: `[[\"Question?\", \"Answer.\"], [\"Q2?\", \"A2.\"]]`\n"
						. "4. **Curriculum order** — Items appear in the order you specify in `wp_set_curriculum`. The order field is auto-assigned.\n"
						. "5. **Lesson/Quiz creation before curriculum** — You must create lessons and quizzes first, then reference their IDs in the curriculum.",
				),
				array(
					'heading' => 'Relevant Tools',
					'content' => "**Courses:**\n"
						. "- `wp_list_courses(search, status, category)` — List courses\n"
						. "- `wp_get_course(id)` — Get full course details\n"
						. "- `wp_create_course(title, ...)` — Create a course\n"
						. "- `wp_update_course(id, ...)` — Update a course\n\n"
						. "**Curriculum:**\n"
						. "- `wp_get_curriculum(id)` — Get curriculum structure\n"
						. "- `wp_set_curriculum(id, sections)` — Set/replace curriculum\n\n"
						. "**Lessons:**\n"
						. "- `wp_list_lessons(course_id)` — List lessons\n"
						. "- `wp_create_lesson(title, content, duration)` — Create lesson\n"
						. "- `wp_update_lesson(id, ...)` — Update lesson\n\n"
						. "**Quizzes:**\n"
						. "- `wp_list_quizzes(course_id)` — List quizzes\n"
						. "- `wp_create_quiz(title, duration, passing_grade)` — Create quiz\n"
						. "- `wp_update_quiz(id, ...)` — Update quiz\n"
						. "- `wp_get_quiz_questions(id)` — Get quiz questions\n\n"
						. "**Categories & Stats:**\n"
						. "- `wp_list_course_categories()` — List categories\n"
						. "- `wp_create_course_category(name)` — Create category\n"
						. "- `wp_lms_stats()` — LMS dashboard statistics",
				),
			),
		);
	}

	/**
	 * Workflows guide (delegates to Mcpwp_Workflows).
	 *
	 * @return array Guide content.
	 */
	public static function guide_workflows() {
		$workflows = Mcpwp_Workflows::get_all();

		return array(
			'topic'       => 'workflows',
			'title'       => 'Workflow Templates',
			'description' => 'Step-by-step guides for common tasks. Use wp_get_workflow(name="...") to get the full workflow for a specific task.',
			'workflows'   => $workflows,
		);
	}

	/**
	 * Troubleshooting guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_troubleshooting() {
		return array(
			'topic'    => 'troubleshooting',
			'title'    => 'Troubleshooting Guide',
			'sections' => array(
				array(
					'heading' => 'Elementor Data Not Rendering',
					'content' => "**Symptom:** Page is blank or shows wrong content after `wp_set_elementor`.\n\n"
						. "**Causes and fixes:**\n"
						. "1. **Wrong layout mode** — Check `elementor_layout_mode` in `wp_site_info()`. Use sections or containers accordingly.\n"
						. "2. **Missing element IDs** — Every element needs a unique `id`. The plugin auto-generates but check the warnings in the response.\n"
						. "3. **Invalid widget type** — Check the `warnings` array in the response. Use `wp_get_elementor_widgets()` to see valid types.\n"
						. "4. **CSS cache** — Run `wp_regenerate_elementor_css()` after making changes.\n"
						. "5. **Page template** — Elementor pages need the right template. Use `wp_update_page_template(id, template=\"elementor_header_footer\")`.",
				),
				array(
					'heading' => 'API Key Issues',
					'content' => "**401 Unauthorized:**\n"
						. "- Verify key is sent in `X-API-Key` header\n"
						. "- Key may be revoked — check with site admin\n"
						. "- Key may have expired\n\n"
						. "**403 Forbidden (category restriction):**\n"
						. "- API key role may not include the tool category\n"
						. "- Use `wp_list_api_keys()` to check key permissions\n"
						. "- Error message includes allowed categories",
				),
				array(
					'heading' => 'Rate Limiting',
					'content' => "**429 Too Many Requests:**\n"
						. "- Default: 60 requests per minute\n"
						. "- Check with `wp_rate_limit_status()`\n"
						. "- Admin can adjust with `wp_update_rate_limit()`\n"
						. "- Wait for the cooldown period and retry",
				),
				array(
					'heading' => 'Tool Not Found',
					'content' => "**\"Unknown tool\" error:**\n"
						. "- Tool may require a plugin that is not active (e.g., Elementor tools need Elementor)\n"
						. "- Tool may require a paid plan or trial — check `wp_introspect()` for available tools\n"
						. "- Tool category may be disabled by admin\n"
						. "- Use `wp_introspect()` to see all available tools",
				),
				array(
					'heading' => 'Elementor Column Size Errors',
					'content' => "**Warning: column sizes do not sum to 100:**\n"
						. "- In section mode, all `_column_size` values in a section must sum to 100\n"
						. "- For 2 columns: 50 + 50, or 33 + 67, etc.\n"
						. "- For 3 columns: 33 + 33 + 33 (rounding is OK)\n"
						. "- In container mode, `_column_size` is not used — use `flex_direction` and `width` instead",
				),
				array(
					'heading' => 'SEO Fields Not Saving',
					'content' => "**SEO tool returns error:**\n"
						. "- Verify an SEO plugin is installed: `wp_detect_plugins()`\n"
						. "- Check the right fields: `wp_get_seo(id)` to see current values\n"
						. "- Some fields are plugin-specific — use normalized field names",
				),
				array(
					'heading' => 'General Debugging',
					'content' => "1. `wp_introspect()` — Full system overview\n"
						. "2. `wp_detect_plugins()` — Check active plugins\n"
						. "3. `wp_site_info()` — WordPress and PHP version\n"
						. "4. `wp_get_site_health()` — WordPress site health report\n"
						. "5. `wp_elementor_status()` — Elementor-specific status",
				),
			),
		);
	}

	/**
	 * Cloudflare guide.
	 *
	 * @return array Guide content.
	 */
	public static function guide_cloudflare() {
		return array(
			'topic' => 'cloudflare',
			'title' => 'Cloudflare Integration Guide',
			'sections' => array(
				array(
					'heading' => 'Connecting a Zone',
					'content' => "Create a scoped Cloudflare API Token (not the Global API Key) at https://developers.cloudflare.com/fundamentals/api/get-started/create-token/ with permissions: **Zone Settings: Edit**, **Zone WAF: Edit**, **Cache Purge: Purge**, scoped to the one zone this site's domain lives in.\n\n"
						. "Configure with `wp_configure_integration(provider=\"cloudflare\", config={\"api_token\": \"...\", \"zone_id\": \"...\"})` — find the Zone ID on the Cloudflare dashboard's Overview page for the domain, right-hand sidebar under API. Test with `wp_test_integration(provider=\"cloudflare\")`, or check overall status with `wp_cloudflare_status()`.",
				),
				array(
					'heading' => 'Fixing Bot Fight Mode / Security Level Blocking MCP',
					'content' => "The most common Cloudflare problem: free-tier **Bot Fight Mode** or a strict **Security Level** silently returns a challenge/block page for MCP API requests instead of reaching WordPress — this looks like a connection failure or empty response, not an obvious \"blocked\" error.\n\n"
						. "Call `wp_cloudflare_fix_mcp_access()` once the integration is connected. It creates (or updates, idempotently — safe to call again) a Configuration Rule that drops Security Level to \"essentially_off\" for this site's own `/wp-json/mcpwp/v1/*` path — this is the confirmed fix, verified live against a real blocked site. It also best-effort adds a WAF skip rule for Super Bot Fight Mode / Managed Rules (Pro-plan features) on the same path; that part can fail independently without affecting the primary fix.\n\n"
						. "This only touches the MCP API path — it does not disable bot protection anywhere else on the site.",
				),
				array(
					'heading' => 'Cache Purge',
					'content' => "Once connected, publishing or updating a page/post, or writing Elementor data to a published page, automatically purges that page's URL from Cloudflare's cache — so freshness reaches crawlers and visitors immediately instead of waiting out the cache TTL. This is fire-and-forget: a purge failure is logged, never blocks the content write.\n\n"
						. "For ad-hoc purges, call `wp_cloudflare_purge_cache(urls=[...])` for specific URLs, or `wp_cloudflare_purge_cache(purge_everything=true)` to clear the whole zone (use sparingly — it drops cache for the entire site, not just one page).",
				),
				array(
					'heading' => 'Speed Settings (Core Web Vitals)',
					'content' => "`wp_cloudflare_get_speed_settings()` reads the zone's current Early Hints, HTTP/3, Rocket Loader, and Polish (image optimization) settings — all of which affect LCP/INP, the ranking-relevant Core Web Vitals. `wp_cloudflare_update_speed_setting(setting, value)` toggles one: early_hints/http3/rocket_loader take \"on\"/\"off\"; polish takes \"off\"/\"lossless\"/\"lossy\"/\"webp\". These same checks also surface as recommendations in `wp_seo_audit_site()`'s `technical_signals` block when Cloudflare is connected.\n\n"
						. "Mirage is intentionally not supported — Cloudflare deprecated it as a settable zone setting in September 2025.",
				),
			),
		);
	}
}
