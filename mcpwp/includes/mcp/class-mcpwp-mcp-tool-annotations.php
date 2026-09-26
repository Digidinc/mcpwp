<?php
/**
 * MCP Tool Annotation Overrides — single source of truth
 *
 * The base registry (Mcpwp_MCP_Tool_Registry) infers readOnlyHint from HTTP
 * method and destructiveHint from a DELETE method or an explicit per-registry
 * list. That heuristic is right most of the time but wrong for a known set of
 * tools — a GET tool that spends third-party credit or calls an external API
 * (openWorldHint), a POST tool that only validates/transforms input without
 * touching WordPress state (readOnlyHint), or a "set_*"/"replace_*" tool that
 * fully overwrites existing content (destructiveHint). Connector directory
 * review (2026-09-25 submission) checks these for correctness, so every
 * inaccuracy has to be fixed at one place, not scattered across registries.
 *
 * This class is that one place. `ANNOTATION_OVERRIDES` is consulted by
 * Mcpwp_MCP_Tool_Registry BEFORE the HTTP-method heuristic and before each
 * registry's own get_destructive_tools()/get_open_world_tools() lists — an
 * override here always wins. Every entry carries a `reason` so a future
 * change to the underlying tool can be checked against why the override
 * exists (docs/mcp-tool-annotations.md is the human-readable audit trail this
 * table backs).
 *
 * `TITLE_OVERRIDES` is the curated exception list for tool names the
 * humanizer (`humanize_tool_name()`) reads badly for; everything else gets an
 * automatic human title so no tool ships without one.
 *
 * @package MCPWP
 * @since   3.14.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static lookup tables + helpers for MCP tool titles and annotation overrides.
 */
class Mcpwp_MCP_Tool_Annotations {

	/**
	 * Per-tool annotation overrides, keyed by tool name.
	 *
	 * Each entry may carry any of: readOnlyHint, destructiveHint,
	 * openWorldHint, idempotentHint (all bool) plus a required `reason`
	 * (string) explaining why the HTTP-method heuristic or a registry's
	 * destructive/open-world list gets this tool wrong.
	 *
	 * A key's absence means "trust the heuristic" — this table exists
	 * specifically for the tools where that would be wrong.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	const ANNOTATION_OVERRIDES = array(

		// --- GET tools that read local state but also spend third-party
		// credit or otherwise reach an external system (openWorldHint was
		// false by default: pro-tier's get_open_world_tools() is empty, and
		// free tools' list only names a handful of upload/webhook tools).

		'wp_fetch'                  => array(
			'openWorldHint' => true,
			'reason'        => 'Fetches an arbitrary caller-supplied external URL (/fetch).',
		),
		'wp_keyword_research'       => array(
			'openWorldHint' => true,
			'reason'        => 'Free/keyless by default, but enrich=true routes through a configured DataForSEO provider and spends the site\'s lookup budget (Mcpwp_REST_SEO_Audit::keyword_research()).',
		),
		'wp_check_update'           => array(
			'openWorldHint' => true,
			'reason'        => 'Calls the plugin update manifest URL over HTTP (Mcpwp_Updater::check_for_update() -> wp_remote_get()).',
		),
		'wp_get_rendered_html'      => array(
			'openWorldHint' => true,
			'reason'        => 'Issues a live wp_remote_get() against the rendered front-end page, which can carry third-party embeds/widgets, not just local data.',
		),
		'wp_get_rendered_copy'      => array(
			'openWorldHint' => true,
			'reason'        => 'Same live-render fetch as wp_get_rendered_html, over the extracted-copy path.',
		),
		'wp_google_index_status'    => array(
			'openWorldHint' => true,
			'reason'        => 'Calls the Google Indexing API to check a URL\'s status (Mcpwp_REST_Google_Indexing::get_status()); pro-tier open-world list is empty by default.',
		),
		'wp_submit_to_google_index' => array(
			'openWorldHint' => true,
			'reason'        => 'Submits URLs to the Google Indexing API (Mcpwp_REST_Google_Indexing::submit_urls()).',
		),
		'wp_refresh_seo_visibility' => array(
			'openWorldHint' => true,
			'reason'        => 'Fetches a fresh ranked-keyword snapshot from the configured DataForSEO provider and stores it (Mcpwp_REST_SEO_Market::refresh_visibility()) — distinct from wp_seo_visibility, which only reads the stored snapshot.',
		),
		'wp_seo_opportunities'      => array(
			'openWorldHint' => true,
			'reason'        => 'Falls through to a live DataForSEO keyword lookup (spending the lookup budget) when a seed is given and no visibility rows are stored yet (Mcpwp_REST_SEO_Market::get_opportunities()).',
		),
		'wp_seo_audit_site'         => array(
			'readOnlyHint' => false,
			'reason'       => 'GET, but store=true persists the run to Mcpwp_SEO_Audit_Store (Mcpwp_REST_SEO_Audit::audit_seo_site()) — not a pure read on every call.',
		),

		// --- POST tools that validate/transform input without touching
		// WordPress state (readOnlyHint was false by default: POST implies
		// a write under the base heuristic, which is wrong for these three).

		'wp_parse_blocks'     => array(
			'readOnlyHint' => true,
			'reason'       => 'Parses HTML into a block structure and returns it; does not write to any post (POST is used only because the HTML body would not fit a GET).',
		),
		'wp_serialize_blocks' => array(
			'readOnlyHint' => true,
			'reason'       => 'Serializes a block structure to HTML and returns it; pure transform, no write.',
		),
		'wp_validate_blocks'  => array(
			'readOnlyHint' => true,
			'reason'       => 'Validates block structure and returns errors/warnings; pure check, no write.',
		),

		// --- destructiveHint corrections. destructiveHint means "this tool
		// can overwrite or remove existing content", independent of
		// readOnlyHint/HTTP method. wp_apply_elementor_template already sets
		// this precedent (#637: "replaces all existing Elementor content on
		// the target page"); wp_set_elementor does exactly the same thing to
		// the same page but was not flagged. Kept in one table so the same
		// judgment call isn't re-litigated tool-by-tool.

		'wp_set_elementor'         => array(
			'destructiveHint' => true,
			'reason'          => 'Fully replaces a page\'s Elementor data, same as wp_apply_elementor_template/_archetype/_part which already carry this hint.',
		),
		'wp_replace_section'       => array(
			'destructiveHint'  => true,
			'idempotentHint'   => true,
			'reason'           => 'Overwrites an existing section\'s content with new content; replacing with the same section twice yields the same page.',
		),
		'wp_remove_section'        => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Removes a section from a page; a second call on an already-removed section is a no-op with the same end state.',
		),
		'wp_set_blocks'            => array(
			'destructiveHint' => true,
			'reason'          => 'Fully replaces a post\'s Gutenberg block content.',
		),
		'wp_set_kit_css'           => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Can fully replace the site\'s global Elementor kit CSS.',
		),
		'wp_set_custom_css'        => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Fully replaces the site\'s custom CSS.',
		),
		'wp_set_elementor_globals' => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Replaces site-wide Elementor design globals (colors/typography) used across every page.',
		),
		'wp_set_curriculum'        => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Fully replaces a course\'s curriculum structure (LearnPress).',
		),
		'wp_set_template_conditions' => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Fully replaces a theme-builder template\'s display conditions; a misconfigured call can unassign the template everywhere.',
		),
		'wp_bulk_find_replace'     => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Mutates text across many pages at once with no per-page undo; matches the same pattern-name guard as other bulk-mutation tools.',
		),
		'wp_remove_integration'    => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Deletes a stored provider credential; removing an already-removed integration is a no-op with the same end state.',
		),
		'wp_rollback_approval'     => array(
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'reason'          => 'Overwrites current state with a prior snapshot, discarding whatever was applied after it.',
		),
		'wp_bulk_update_posts'     => array(
			'destructiveHint' => true,
			'reason'          => 'Overwrites fields on many posts in one call — same edit semantics as wp_update_post, at a much larger blast radius per mistake.',
		),
		'wp_bulk_update_pages'     => array(
			'destructiveHint' => true,
			'reason'          => 'Overwrites fields on many pages in one call — same reasoning as wp_bulk_update_posts.',
		),
		'wp_bulk_seo'              => array(
			'destructiveHint' => true,
			'reason'          => 'Overwrites SEO metadata on many posts in one call — same blast-radius reasoning as wp_bulk_update_posts/_pages.',
		),

		// --- destructiveHint false-positives: the guard test's name pattern
		// (delete|remove|revoke|rollback|replace|bulk|push_to_live) also
		// matches these, but they are purely additive or purely read-only —
		// listed explicitly (with reason) so the guard treats them as
		// reviewed exceptions rather than silent gaps.

		'wp_bulk_upload_media'  => array(
			'destructiveHint' => false,
			'reason'          => 'Uploads new media items; additive only, nothing existing is overwritten or removed.',
		),
		'wp_get_elementor_bulk' => array(
			'destructiveHint' => false,
			'readOnlyHint'    => true,
			'reason'          => 'Reads Elementor data for multiple pages; GET, no mutation despite the "bulk" name.',
		),
		'wp_bulk_create_pages'  => array(
			'destructiveHint' => false,
			'reason'          => 'Creates new pages; additive only.',
		),
		'wp_bulk_create_posts'  => array(
			'destructiveHint' => false,
			'reason'          => 'Creates new posts; additive only.',
		),

		// --- idempotentHint for meaningfully-idempotent write tools. Default
		// (no override) is: read-only tools are idempotent, other write
		// tools are not asserted either way (false). These are the write
		// tools where calling twice with the same arguments provably leaves
		// the site in the same state as calling once.

		'wp_delete_post'             => array( 'idempotentHint' => true, 'reason' => 'Deleting an already-deleted post is a no-op with the same end state.' ),
		'wp_delete_page'             => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_all_drafts'       => array( 'idempotentHint' => true, 'reason' => 'A second call finds no drafts left; same end state.' ),
		'wp_delete_menu'             => array( 'idempotentHint' => true, 'reason' => 'Deleting an already-deleted menu is a no-op with the same end state.' ),
		'wp_delete_menu_item'        => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_menu.' ),
		'wp_delete_media'            => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_webhook'          => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_term'             => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_content'          => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_custom_css'       => array( 'idempotentHint' => true, 'reason' => 'A second call finds the CSS already cleared; same end state.' ),
		'wp_delete_kit_css_block'    => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_custom_css.' ),
		'wp_delete_course_category'  => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_elementor_template' => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_delete_widget'           => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wc_delete_product'          => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_delete_post.' ),
		'wp_revoke_api_key'          => array( 'idempotentHint' => true, 'reason' => 'Revoking an already-revoked key is a no-op with the same end state.' ),
		'wp_reset_rate_limit'        => array( 'idempotentHint' => true, 'reason' => 'Resetting an already-reset counter is a no-op with the same end state.' ),
		'wp_forget'                  => array( 'idempotentHint' => true, 'reason' => 'Forgetting an already-forgotten memory key is a no-op with the same end state.' ),
		'wp_set_post_meta'           => array( 'idempotentHint' => true, 'reason' => 'Sets a meta value to a given value; setting the same value twice leaves the same state.' ),
		'wp_set_site_context'        => array( 'idempotentHint' => true, 'reason' => 'Replaces the stored site-context brief; same text twice leaves the same state.' ),
		'wp_set_noindex'             => array( 'idempotentHint' => true, 'reason' => 'Sets a boolean flag; same value twice leaves the same state.' ),
		'wp_set_language'            => array( 'idempotentHint' => true, 'reason' => 'Sets the current display language; same value twice leaves the same state.' ),
		'wp_set_seo'                 => array( 'idempotentHint' => true, 'reason' => 'Replaces a post\'s SEO fields with the given values; same values twice leave the same state.' ),
		'wp_upsert_kit_css_block'    => array( 'idempotentHint' => true, 'reason' => 'Upsert by name — same block content twice leaves the same state.' ),
		'wp_disable_elementor_custom_code' => array( 'idempotentHint' => true, 'reason' => 'Disabling an already-disabled snippet is a no-op with the same end state.' ),
		'wp_enable_elementor_custom_code'  => array( 'idempotentHint' => true, 'reason' => 'Enabling an already-enabled snippet is a no-op with the same end state.' ),
		'wp_assign_menu_location'    => array( 'idempotentHint' => true, 'reason' => 'Assigns a menu to a location; the same assignment twice leaves the same state.' ),
		'wp_assign_template'         => array( 'idempotentHint' => true, 'reason' => 'Assigns a theme-builder template; the same assignment twice leaves the same state.' ),
		'wp_reorder_menu_items'      => array( 'idempotentHint' => true, 'reason' => 'Sets an explicit final order; the same order twice leaves the same state.' ),
		'wp_reorder_widgets'         => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_reorder_menu_items.' ),
		'wp_move_widget'             => array( 'idempotentHint' => true, 'reason' => 'Moves a widget to an explicit target position; the same target twice leaves the same state.' ),
		'wp_flush_permalinks'        => array( 'idempotentHint' => true, 'reason' => 'Regenerates rewrite rules from current state; running it again produces the same rules.' ),
		'wp_flush_cache'             => array( 'idempotentHint' => true, 'reason' => 'Flushing an already-empty cache is a no-op with the same end state.' ),
		'wp_regenerate_elementor_css' => array( 'idempotentHint' => true, 'reason' => 'Deterministically regenerates CSS from current page data; same input produces the same output file.' ),
		'wp_update_rate_limit'       => array( 'idempotentHint' => true, 'reason' => 'Replaces the rate-limit config with the given values; same values twice leave the same state.' ),
		'wp_update_plugin_settings'  => array( 'idempotentHint' => true, 'reason' => 'Replaces settings with the given values; same values twice leave the same state.' ),
		'wp_update_options'          => array( 'idempotentHint' => true, 'reason' => 'Sets the given options to given values; same values twice leave the same state.' ),
		'wp_update_option'           => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_update_options.' ),
		'wp_push_to_live'            => array( 'idempotentHint' => true, 'reason' => 'Pushes the same source snapshot to live; the same push twice leaves live in the same state.' ),
		'wp_push_to_staging'         => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_push_to_live.' ),
		'wp_apply_elementor_template' => array( 'idempotentHint' => true, 'reason' => 'Applies the same template content each call; applying it twice leaves the page in the same state.' ),
		'wp_apply_elementor_archetype' => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_apply_elementor_template.' ),
		'wp_apply_elementor_part'    => array( 'idempotentHint' => true, 'reason' => 'Same reasoning as wp_apply_elementor_template.' ),
	);

	/**
	 * Curated title overrides for tool names the automatic humanizer reads
	 * badly for. Every other tool gets `humanize_tool_name( $name )`.
	 *
	 * @var array<string, string>
	 */
	const TITLE_OVERRIDES = array(
		'wp_bulk_find_replace'               => 'Find and replace across pages',
		'wp_get_elementor_bulk'               => 'Get multiple Elementor pages',
		'wp_cloudflare_fix_mcp_access'         => 'Fix Cloudflare MCP access',
		'wp_cloudflare_get_speed_settings'     => 'Get Cloudflare speed settings',
		'wp_cloudflare_update_speed_setting'   => 'Update Cloudflare speed setting',
		'wp_cloudflare_purge_cache'            => 'Purge Cloudflare cache',
		'wp_upsert_kit_css_block'              => 'Create or update kit CSS block',
		'wp_onboard'                           => 'Get onboarding briefing',
		'wp_introspect'                        => 'Get API introspection',
		'wp_recall'                            => 'Recall memory',
		'wp_remember'                          => 'Remember memory',
		'wp_forget'                            => 'Forget memory',
		'wp_text_to_speech'                    => 'Convert text to speech',

		// wc_* tools: the humanizer would prefix every one with "WooCommerce"
		// (from the wc_ stem), which is redundant once the tool is grouped
		// under the woocommerce category — curate plain, human titles instead
		// and reserve the "WooCommerce" prefix for the two tools that need it
		// to stand alone (status, analytics).
		'wc_status'                  => 'WooCommerce status',
		'wc_analytics'               => 'WooCommerce analytics',
		'wc_list_products'           => 'List products',
		'wc_get_product'             => 'Get product',
		'wc_list_product_archetypes' => 'List product archetypes',
		'wc_get_product_archetype'   => 'Get product archetype',
		'wc_create_product_archetype' => 'Create product archetype',
		'wc_apply_product_archetype' => 'Apply product archetype',
		'wc_create_product'          => 'Create product',
		'wc_update_product'          => 'Update product',
		'wc_delete_product'          => 'Delete product',
		'wc_list_product_categories' => 'List product categories',
		'wc_create_product_category' => 'Create product category',
		'wc_update_product_category' => 'Update product category',
		'wc_list_product_tags'       => 'List product tags',
		'wc_list_orders'             => 'List orders',
		'wc_get_order'               => 'Get order',
		'wc_update_order'            => 'Update order',
		'wc_list_order_statuses'     => 'List order statuses',
		'wc_list_customers'          => 'List customers',
		'wc_get_customer'            => 'Get customer',
	);

	/**
	 * Words rendered with fixed casing regardless of position — acronyms and
	 * product/brand names that ucfirst() alone would not get right in the
	 * middle of a title (e.g. "Submit to google index" instead of
	 * "Submit to Google index").
	 *
	 * @var array<string, string>
	 */
	const KNOWN_TERMS = array(
		'seo'         => 'SEO',
		'css'         => 'CSS',
		'api'         => 'API',
		'url'         => 'URL',
		'id'          => 'ID',
		'html'        => 'HTML',
		'ai'          => 'AI',
		'faq'         => 'FAQ',
		'mcp'         => 'MCP',
		'lms'         => 'LMS',
		'cta'         => 'CTA',
		'json'        => 'JSON',
		'oauth'       => 'OAuth',
		'elementor'   => 'Elementor',
		'woocommerce' => 'WooCommerce',
		'figma'       => 'Figma',
		'cloudflare'  => 'Cloudflare',
		'google'      => 'Google',
		'gutenberg'   => 'Gutenberg',
		'pexels'      => 'Pexels',
	);

	/**
	 * Get the human title for a tool: curated override, else auto-humanized.
	 *
	 * @param string $name Tool name.
	 * @return string Non-empty human-readable title.
	 */
	public static function get_title( $name ) {
		if ( isset( self::TITLE_OVERRIDES[ $name ] ) ) {
			return self::TITLE_OVERRIDES[ $name ];
		}
		return self::humanize_tool_name( $name );
	}

	/**
	 * Turn a tool name into a short human title.
	 *
	 * `wp_`/`wc_` prefixes are dropped, the remaining words are split on `_`;
	 * the first word is capitalized, known acronyms/brand names keep their
	 * fixed casing wherever they appear, everything else is lowercased. E.g.
	 * `wp_get_elementor_summary` -> "Get Elementor summary".
	 *
	 * @param string $name Tool name.
	 * @return string Human title. A real (non-empty) tool name always
	 *                produces a non-empty title; an empty/degenerate name is
	 *                passed through unchanged rather than throwing.
	 */
	public static function humanize_tool_name( $name ) {
		$name = (string) $name;
		if ( '' === $name ) {
			return $name;
		}

		$stem = $name;
		if ( 0 === strpos( $stem, 'wp_' ) || 0 === strpos( $stem, 'wc_' ) ) {
			$stem = substr( $stem, 3 );
		}

		$words = array_values( array_filter( explode( '_', $stem ), 'strlen' ) );
		if ( empty( $words ) ) {
			return $name;
		}

		$out = array();
		foreach ( $words as $i => $word ) {
			$lower = strtolower( $word );
			if ( isset( self::KNOWN_TERMS[ $lower ] ) ) {
				$out[] = self::KNOWN_TERMS[ $lower ];
			} elseif ( 0 === $i ) {
				$out[] = ucfirst( $lower );
			} else {
				$out[] = $lower;
			}
		}

		return implode( ' ', $out );
	}

	/**
	 * Get the annotation override entry for a tool, if any.
	 *
	 * @param string $name Tool name.
	 * @return array<string, mixed>|null Override array, or null if the tool
	 *                                   has no override (heuristic applies).
	 */
	public static function get_override( $name ) {
		return isset( self::ANNOTATION_OVERRIDES[ $name ] ) ? self::ANNOTATION_OVERRIDES[ $name ] : null;
	}
}
