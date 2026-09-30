<?php
/**
 * tools/list weight budget (#1064).
 *
 * Every MCP connection pays for its tools/list before the agent does
 * anything, so what tools/list carries is trimmed here, in one place, at
 * emission time. The registries keep every tool's complete definition: the
 * full description, every property description, and the #912 `examples`.
 * Those stay reachable where they help at the moment of use —
 * mcpwp_find_tools results, invalid-argument error hints, and
 * wp_introspect(tool=...) — rather than in every tools/list payload.
 *
 * Nothing here changes what a tool accepts. `type`, `required`, `enum`,
 * `items`, `properties`, `default` and every other constraint are kept
 * verbatim; only annotation text is shortened or dropped, so argument
 * validation and the forgiving-params remap (#911), which read the
 * registries' full schemas, are unaffected. outputSchema (#906) is not
 * touched at all.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central tools/list slimming rules.
 */
class Mcpwp_MCP_Tool_List_Diet {

	/**
	 * Longest tool description tools/list carries, in characters.
	 */
	const DESCRIPTION_MAX_CHARS = 300;

	/**
	 * Longest inputSchema property description tools/list carries, in characters.
	 */
	const PROPERTY_DESCRIPTION_MAX_CHARS = 120;

	/**
	 * Abbreviations whose trailing period is not a sentence end.
	 *
	 * @var string[]
	 */
	const NON_TERMINAL_ABBREVIATIONS = array( 'e.g.', 'i.e.', 'etc.', 'vs.', 'approx.', 'incl.', 'min.', 'max.', 'no.' );

	/**
	 * Hand-written tools/list descriptions for the tools the central rules
	 * alone leave too heavy, or whose first 300 characters do not say what
	 * the tool is for. Each is a summary of the registry description, which
	 * stays the full text everywhere else.
	 *
	 * @var array<string,string>
	 */
	const LIST_DESCRIPTIONS = array(
		// `core` profile (#907) — every token here is paid on every connection.
		'wp_onboard'               => 'Call first: a short site briefing and next actions. detail="full" for everything.',
		'wp_site_info'             => 'Site name, URL, versions, theme, capabilities, license.',
		'wp_get_site_context'      => 'The site style guide. Read before building pages.',
		'wp_list_pages'            => 'List pages. ids and fields narrow the result.',
		'wp_get_page_by_slug'      => 'Fetch a page by its URL slug (e.g. "about").',
		'wp_create_page'           => 'Create a page (draft by default). Elementor pages: create, then wp_set_elementor.',
		'wp_update_page'           => 'Update a page; only fields you send change. Elementor layout: wp_edit_widget.',
		'wp_list_posts'            => 'List posts of any type. ids and fields narrow the result.',
		'wp_create_post'           => 'Create a post (draft by default).',
		'wp_update_post'           => 'Update a post; only fields you send change.',
		'wp_search'                => 'Search posts and pages.',
		'wp_get_elementor'         => 'Get a page\'s Elementor JSON.',
		'wp_set_elementor'         => 'Replace a page\'s whole Elementor data. Small edits: wp_edit_widget.',
		'wp_get_elementor_summary' => 'Call before editing an Elementor page: element IDs and widget types.',
		'wp_edit_widget'           => 'Change one Elementor widget\'s settings by ID (e.g. heading text).',
		'wp_build_page'            => 'Create an Elementor page from section blueprints.',
		'wp_upload_media_from_url' => 'Import a file from a URL into the media library.',
		'wp_list_media'            => 'List media (IDs, URLs, alt text). Reuse before uploading.',
		'wp_get_seo'               => 'Get a page or post\'s SEO fields.',
		'wp_set_seo'               => 'Set a page or post\'s SEO title, meta description and other SEO fields.',
		'wp_get_rendered_html'     => 'A page\'s rendered HTML, to verify output.',
		'wp_screenshot_url'        => 'Screenshot a URL.',
		'wp_graph_query'           => 'Find pages by links and metrics: orphans, inbound counts, stale or thin pages.',
		'wp_list_menus'            => 'List navigation menus.',
		'wp_list_approvals'        => 'List approval requests.',
		'mcpwp_find_tools'         => 'Find any tool on this site by keyword, including ones not in tools/list, with full schemas and examples. Run it with mcpwp_call.',
		'mcpwp_call'               => 'Run any tool by name, e.g. one found with mcpwp_find_tools. Same permissions as a direct call.',

		// Over the single-tool budget after the central rules.
		'wp_set_elementor_globals'   => 'Set Elementor global colors, typography, kit custom CSS and layout defaults. Each key you send replaces its current value; unknown keys are ignored and reported.',
		'wp_get_blueprint'           => 'Generate one Elementor section as JSON from a blueprint type, ready for wp_add_section. Types and their item fields: wp_list_blueprints.',
		'wp_upload_design_reference' => 'Save a design reference from an image (URL, base64 or media ID) to guide future page building.',
		'wp_update_plugin_settings'  => 'Update MCPWP plugin settings. Send only the keys to change.',
	);

	/**
	 * Hand-written tools/list property descriptions, tool => property =>
	 * text ('' drops the description), for the same heavy tools. The
	 * registry keeps the full text.
	 *
	 * @var array<string,array<string,string>>
	 */
	const LIST_PROPERTY_DESCRIPTIONS = array(
		'wp_onboard'               => array(
			'detail'   => '',
			'sections' => '',
		),
		'wp_list_pages'            => array(
			'per_page' => '1-100.',
			'page'     => '',
			'status'   => '',
			'search'   => '',
			'ids'      => 'Comma-separated IDs.',
			'fields'   => 'Comma-separated fields.',
		),
		'wp_list_posts'            => array(
			'post_type' => '',
			'per_page'  => '1-100.',
			'page'      => '',
			'status'    => '',
			'category'  => 'Category ID.',
			'search'    => '',
			'ids'       => 'Comma-separated IDs.',
			'fields'    => 'Comma-separated fields.',
		),
		'wp_create_page'           => array(
			'title'      => '',
			'content'    => 'HTML or block markup. Omit for Elementor pages.',
			'excerpt'    => '',
			'status'     => 'draft, publish, pending or private.',
			'slug'       => '',
			'parent'     => 'Parent page ID.',
			'template'   => 'e.g. elementor_header_footer, elementor_canvas.',
			'menu_order' => '',
			'terms'      => '{taxonomy: [terms]}',
			'meta'       => '',
		),
		'wp_update_page'           => array(
			'id'         => '',
			'content'    => 'HTML or block markup. Not for Elementor pages.',
			'excerpt'    => '',
			'status'     => 'publish, draft, pending or private.',
			'slug'       => 'Changing it breaks existing links.',
			'parent'     => 'Parent page ID; 0 for top level.',
			'template'   => '',
			'menu_order' => '',
			'terms'      => '{taxonomy: [terms]}',
			'meta'       => '',
		),
		'wp_create_post'           => array(
			'content'    => 'HTML or block markup.',
			'status'     => 'draft, publish, pending or private.',
			'post_type'  => '',
			'excerpt'    => '',
			'slug'       => '',
			'categories' => 'Category IDs.',
			'tags'       => 'Tag slugs or names; missing tags are created.',
			'terms'      => '{taxonomy: [terms]}',
			'meta'       => '',
		),
		'wp_update_post'           => array(
			'id'         => '',
			'content'    => 'HTML. Not for Elementor posts.',
			'status'     => 'publish, draft, pending or private.',
			'excerpt'    => '',
			'slug'       => 'Changing it breaks existing links.',
			'categories' => 'Category IDs (replaces all).',
			'tags'       => 'Tag slugs or names (replaces all).',
			'terms'      => '{taxonomy: [terms]}',
			'meta'       => '',
		),
		'wp_search'                => array(
			'post_type' => 'post, page or any.',
			'status'    => '',
			'per_page'  => '1-50.',
			'page'      => '',
		),
		'wp_get_elementor'         => array(
			'id'             => 'Page or post ID.',
			'strip_defaults' => 'Omit default widget settings (smaller).',
			'include_raw'    => '',
			'element_id'     => 'Return only this element\'s subtree.',
		),
		'wp_set_elementor'         => array(
			'id'                    => 'Page or post ID.',
			'elementor_data'        => 'Elementor JSON.',
			'elementor_data_base64' => 'Base64 Elementor JSON, for large payloads.',
			'dry_run'               => 'Validate without saving.',
			'repair_ids'            => 'Rewrite element ids that are not 7 alphanumeric characters.',
			'convert'               => 'Allow replacing existing non-Elementor content.',
		),
		'wp_get_page_by_slug'      => array(
			'slug' => '',
		),
		'wp_get_elementor_summary' => array(
			'id' => 'Page or post ID.',
		),
		'wp_edit_widget'           => array(
			'id'              => 'Page or post ID.',
			'widget_id'       => 'From wp_get_elementor_summary.',
			'settings'        => 'e.g. {"title": "New heading"}',
			'delete_settings' => 'Setting keys to remove.',
		),
		'wp_build_page'            => array(
			'sections' => 'Section objects, each with "type" plus its params.',
			'status'   => 'draft, publish or private.',
		),
		'wp_upload_media_from_url' => array(
			'url'      => '',
			'filename' => '',
			'title'    => '',
			'alt'      => '',
		),
		'wp_list_media'            => array(
			'per_page'  => '1-100.',
			'page'      => '',
			'mime_type' => 'e.g. image, image/png, video.',
			'search'    => '',
		),
		'wp_get_seo'               => array(
			'id' => 'Page or post ID.',
		),
		'wp_set_seo'               => array(
			'id'                  => 'Page or post ID.',
			'title'               => 'SEO title.',
			'description'         => 'Meta description.',
			'seo_title'           => '',
			'seo_description'     => '',
			'canonical'           => '',
			'canonical_url'       => '',
			'noindex'             => '',
			'nofollow'            => '',
			'og_title'            => '',
			'og_description'      => '',
			'og_image'            => '',
			'twitter_title'       => '',
			'twitter_description' => '',
			'twitter_image'       => '',
		),
		'wp_get_rendered_html'     => array(
			'id'        => 'Page or post ID (or url).',
			'url'       => 'Same-host URL (or id).',
			'selector'  => 'CSS selector, e.g. head, footer, #main.',
			'max_bytes' => '',
			'offset'    => '',
		),
		'wp_screenshot_url'        => array(
			'width'         => '',
			'height'        => '',
			'widths'        => 'e.g. [390,1024,1440]. Max 6.',
			'full_page'     => '',
			'save_to_media' => '',
			'inline'        => 'Return base64 instead of a URL.',
			'webhook_url'   => '',
		),
		'wp_graph_query'           => array(
			'kind'            => 'e.g. ["page","post"]. Omit for all.',
			'status'          => '',
			'orphan'          => 'No inbound links.',
			'min_inbound'     => '',
			'max_inbound'     => '',
			'has_metric'      => '',
			'metric'          => '',
			'no_edge_type'    => 'e.g. targets_query.',
			'modified_before' => 'Y-m-d.',
			'word_count_lt'   => '',
			'order_by'        => 'label, modified, inbound or a metric name.',
			'order_dir'       => '',
			'limit'           => '1-200.',
			'cursor'          => 'next_cursor from the previous page.',
		),
		'wp_list_approvals'        => array(
			'status' => 'pending, approved, applied, rejected or rolled_back.',
			'limit'  => '',
			'order'  => 'date or priority.',
		),
		'mcpwp_find_tools'         => array(
			'query'    => 'Keywords, e.g. "elementor heading".',
			'limit'    => '',
			'category' => 'e.g. content, seo, elementor, media.',
		),
		'mcpwp_call'               => array(
			'name'      => 'Tool name.',
			'arguments' => 'The tool\'s arguments.',
		),
		'wp_upload_design_reference' => array(
			'media_id'     => 'Media ID; the fallback if an upload fails.',
			'image_url'    => '',
			'image_base64' => '',
			'filename'     => '',
			'page_intent'  => 'e.g. landing_page, blog_post.',
			'style'        => 'e.g. editorial, minimal.',
			'source_type'  => '',
		),
		'wp_set_elementor_globals'   => array(
			'system_colors'               => '{_id, title, color} objects.',
			'custom_colors'               => '{_id, title, color} objects.',
			'system_typography'           => '',
			'custom_typography'           => '',
			'custom_css'                  => 'Kit-level CSS; replaces the current kit CSS.',
			'container_width'             => '{"size":1140,"unit":"px"}',
			'space_between_widgets'       => '',
			'page_title_selector'         => '',
			'stretched_section_container' => '',
			'default_generic_fonts'       => '',
			'viewport_md'                 => 'Tablet breakpoint, px.',
			'viewport_lg'                 => 'Desktop breakpoint, px.',
		),
		'wp_get_blueprint'           => array(
			'type'        => 'hero, features, cta, pricing, faq, testimonials, text, gallery, stats... (wp_list_blueprints).',
			'heading'     => '',
			'subheading'  => '',
			'cta_text'    => 'hero, cta.',
			'cta_url'     => 'hero, cta.',
			'button_text' => 'cta, pricing.',
			'button_url'  => 'cta, pricing.',
			'background'  => 'Hex color or "gradient".',
			'image_url'   => 'hero.',
			'columns'     => '',
			'items'       => 'Type-specific items.',
			'plans'       => 'pricing.',
			'content'     => 'HTML (text).',
			'images'      => 'gallery.',
			'url'         => 'Video URL.',
			'address'     => 'map.',
			'due_date'    => 'YYYY-MM-DD HH:MM (countdown).',
			'form_id'     => 'contact_form.',
			'plugin'      => 'wpforms, cf7 or gravity.',
		),
		'wp_update_plugin_settings'  => array(
			'enable_logging'          => '',
			'log_retention_days'      => '1-365.',
			'log_store_response_data' => '',
			'allowed_origins'         => 'Comma-separated URLs.',
			'oauth_enabled'           => '',
			'oauth_client_secret'     => 'Write-only.',
			'oauth_token_ttl'         => 'Seconds, 60-86400.',
			'alerts_enabled'          => '',
			'alerts_window_minutes'   => '1-60.',
			'alerts_cooldown_minutes' => '1-1440.',
			'alerts_5xx_threshold'    => '1-10000.',
			'alerts_auth_threshold'   => '1-10000.',
			'github_token'            => 'Write-only.',
			'github_repo'             => 'owner/repo',
			'mcp_tool_profile'        => 'Default tools/list profile.',
		),
		'wp_bulk_seo'                => array(
			'updates' => 'Objects with id plus SEO fields to set.',
			'items'   => 'Alias for updates.',
		),
		'wp_bulk_update_posts'       => array(
			'posts'   => 'Objects with id plus fields to change.',
			'updates' => 'Alias for posts.',
		),
	);

	/**
	 * Tools whose nested (below top-level) property descriptions tools/list
	 * leaves out: array-of-object parameters whose item fields are named
	 * plainly (id, title, color, font_family) and repeated per alias.
	 *
	 * @var string[]
	 */
	const LIST_BARE_NESTED = array( 'wp_set_elementor_globals', 'wp_get_blueprint', 'wp_bulk_seo', 'wp_bulk_update_posts' );

	/**
	 * Words that carry no meaning of their own in a property description.
	 *
	 * @var string[]
	 */
	const STOPWORDS = array( 'a', 'an', 'the', 'of', 'for', 'to', 'in', 'on', 'or', 'and', 'by', 'this', 'that', 'is', 'be', 'as', 'with', 'from', 'it', 'its' );

	/**
	 * Slim a list of tool definitions for tools/list.
	 *
	 * @param array[] $tools Tool definitions as the registries build them.
	 * @return array[]
	 */
	public static function slim_tools( array $tools ) {
		return array_map( array( __CLASS__, 'slim_tool' ), $tools );
	}

	/**
	 * Slim one tool definition for tools/list.
	 *
	 * @param array $tool Tool definition.
	 * @return array
	 */
	public static function slim_tool( $tool ) {
		if ( ! is_array( $tool ) ) {
			return $tool;
		}

		$name = isset( $tool['name'] ) && is_string( $tool['name'] ) ? $tool['name'] : '';

		if ( '' !== $name && isset( self::LIST_DESCRIPTIONS[ $name ] ) ) {
			$tool['description'] = self::ascii_punctuation( self::LIST_DESCRIPTIONS[ $name ] );
		} elseif ( isset( $tool['description'] ) && is_string( $tool['description'] ) ) {
			$tool['description'] = self::shorten( $tool['description'], self::DESCRIPTION_MAX_CHARS );
		}

		if ( isset( $tool['inputSchema'] ) && is_array( $tool['inputSchema'] ) ) {
			$tool['inputSchema'] = self::slim_schema( $tool['inputSchema'], true );
			$tool['inputSchema'] = self::drop_restated_descriptions( $tool['inputSchema'], $name, isset( $tool['title'] ) && is_string( $tool['title'] ) ? $tool['title'] : '' );
			if ( '' !== $name && isset( self::LIST_PROPERTY_DESCRIPTIONS[ $name ] ) && isset( $tool['inputSchema']['properties'] ) && is_array( $tool['inputSchema']['properties'] ) ) {
				foreach ( self::LIST_PROPERTY_DESCRIPTIONS[ $name ] as $property => $text ) {
					if ( ! isset( $tool['inputSchema']['properties'][ $property ] ) || ! is_array( $tool['inputSchema']['properties'][ $property ] ) ) {
						continue;
					}
					if ( '' === $text ) {
						unset( $tool['inputSchema']['properties'][ $property ]['description'] );
					} else {
						$tool['inputSchema']['properties'][ $property ]['description'] = $text;
					}
				}
			}
		}

		if ( in_array( $name, self::LIST_BARE_NESTED, true ) && isset( $tool['inputSchema']['properties'] ) && is_array( $tool['inputSchema']['properties'] ) ) {
			foreach ( $tool['inputSchema']['properties'] as $property => $subschema ) {
				if ( is_array( $subschema ) ) {
					$description = isset( $subschema['description'] ) ? $subschema['description'] : null;
					$subschema   = self::strip_descriptions( $subschema );
					if ( null !== $description ) {
						$subschema['description'] = $description;
					}
					$tool['inputSchema']['properties'][ $property ] = self::object_if_empty( $subschema );
				}
			}
		}

		// annotations.title exists for clients older than the 2025-06-18
		// top-level `title` (#934). When the top-level field is present and
		// says the same thing, the copy is pure weight. A client negotiated
		// to an older version never gets the top-level field (#906), so it
		// keeps annotations.title.
		if (
			isset( $tool['title'], $tool['annotations'] )
			&& is_array( $tool['annotations'] )
			&& isset( $tool['annotations']['title'] )
			&& $tool['annotations']['title'] === $tool['title']
		) {
			unset( $tool['annotations']['title'] );
		}

		// idempotentHint defaults to false in the MCP spec and is meaningful
		// only when readOnlyHint is false, so it is sent only when it says
		// something: a write tool that is safe to retry. readOnlyHint,
		// destructiveHint and openWorldHint are always sent — clients and
		// directory reviews (ChatGPT's among them) require all three.
		if ( isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) && array_key_exists( 'idempotentHint', $tool['annotations'] ) ) {
			$read_only = ! empty( $tool['annotations']['readOnlyHint'] );
			if ( $read_only || true !== $tool['annotations']['idempotentHint'] ) {
				unset( $tool['annotations']['idempotentHint'] );
			}
		}

		return $tool;
	}

	/**
	 * Slim an inputSchema (or a nested subschema): drop `examples`, drop
	 * empty descriptions, shorten long ones. Every structural keyword is
	 * kept exactly as authored.
	 *
	 * @param array $schema  Schema.
	 * @param bool  $is_root Whether this is the tool's top-level inputSchema
	 *                       (whose own description, if any, is not a property
	 *                       description).
	 * @return array
	 */
	public static function slim_schema( array $schema, $is_root = false ) {
		unset( $schema['examples'] );

		// A default of "" or false on an optional string/boolean says only
		// "nothing unless you pass it". JSON Schema `default` is an
		// annotation — no client fills it in and the server applies its own
		// — so dropping it changes nothing the tool accepts or does.
		if ( array_key_exists( 'default', $schema ) && ( '' === $schema['default'] || false === $schema['default'] ) ) {
			unset( $schema['default'] );
		}

		if ( array_key_exists( 'description', $schema ) ) {
			$description = is_string( $schema['description'] ) ? trim( $schema['description'] ) : '';
			if ( '' === $description ) {
				unset( $schema['description'] );
			} elseif ( ! $is_root ) {
				$schema['description'] = self::shorten( $description, self::PROPERTY_DESCRIPTION_MAX_CHARS );
			}
		}

		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $key => $subschema ) {
				if ( is_array( $subschema ) ) {
					$schema['properties'][ $key ] = self::object_if_empty( self::slim_schema( $subschema ) );
				}
			}
			$schema['properties'] = self::object_if_empty( $schema['properties'] );
		}

		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = self::is_list( $schema['items'] ) && array() !== $schema['items']
				? array_map( array( __CLASS__, 'slim_subschema' ), $schema['items'] )
				: self::object_if_empty( self::slim_schema( $schema['items'] ) );
		}

		if ( isset( $schema['additionalProperties'] ) && is_array( $schema['additionalProperties'] ) ) {
			$schema['additionalProperties'] = self::object_if_empty( self::slim_schema( $schema['additionalProperties'] ) );
		}

		foreach ( array( 'anyOf', 'oneOf', 'allOf' ) as $combinator ) {
			if ( isset( $schema[ $combinator ] ) && is_array( $schema[ $combinator ] ) ) {
				$schema[ $combinator ] = array_map( array( __CLASS__, 'slim_subschema' ), $schema[ $combinator ] );
			}
		}

		return $schema;
	}

	/**
	 * Drop a top-level property description that only restates the
	 * property's own name and the tool's name ("Post title" on
	 * wp_create_post's `title`, "Menu ID to delete" on wp_delete_menu's
	 * `menu_id`) — it tells the model nothing the key does not.
	 *
	 * @param array  $schema    Tool inputSchema.
	 * @param string $tool_name Tool name.
	 * @param string $title     Tool title.
	 * @return array
	 */
	public static function drop_restated_descriptions( array $schema, $tool_name, $title ) {
		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return $schema;
		}

		$tool_words = self::words( str_replace( '_', ' ', (string) $tool_name ) . ' ' . $title );

		foreach ( $schema['properties'] as $property => $subschema ) {
			if ( ! is_array( $subschema ) || ! isset( $subschema['description'] ) || ! is_string( $subschema['description'] ) ) {
				continue;
			}
			$known = array_merge( $tool_words, self::words( str_replace( '_', ' ', (string) $property ) ), array( 'id' ) );
			$said  = array_diff( self::words( $subschema['description'] ), self::STOPWORDS );
			if ( ! empty( $said ) && array() === array_diff( $said, $known ) ) {
				unset( $schema['properties'][ $property ]['description'] );
			}
		}

		return $schema;
	}

	/**
	 * Lowercased, crudely singularised words of a text.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function words( $text ) {
		preg_match_all( '/[a-z0-9]+/', strtolower( (string) $text ), $matches );
		return array_values(
			array_unique(
				array_map(
					static function ( $word ) {
						return ( strlen( $word ) > 3 && 's' === substr( $word, -1 ) ) ? substr( $word, 0, -1 ) : $word;
					},
					$matches[0]
				)
			)
		);
	}

	/**
	 * Remove every `description` in a subschema, at any depth (property
	 * maps are walked by value, so a property literally named
	 * "description" is kept).
	 *
	 * @param array $schema Subschema.
	 * @return array
	 */
	private static function strip_descriptions( array $schema ) {
		unset( $schema['description'] );
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as $key => $subschema ) {
				if ( is_array( $subschema ) ) {
					$schema['properties'][ $key ] = self::object_if_empty( self::strip_descriptions( $subschema ) );
				}
			}
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) && array() !== $schema['items'] && ! self::is_list( $schema['items'] ) ) {
			$schema['items'] = self::object_if_empty( self::strip_descriptions( $schema['items'] ) );
		}
		return $schema;
	}

	/**
	 * array_map() callback: slim a subschema that may not be an array.
	 *
	 * @param mixed $subschema Subschema.
	 * @return mixed
	 */
	public static function slim_subschema( $subschema ) {
		return is_array( $subschema ) ? self::object_if_empty( self::slim_schema( $subschema ) ) : $subschema;
	}

	/**
	 * A schema (or property map) left empty must still be a JSON object:
	 * PHP's array() encodes as `[]`, which is not a valid schema (#1064
	 * review N3). stdClass encodes as `{}`.
	 *
	 * @param mixed $value Schema or property map.
	 * @return mixed
	 */
	private static function object_if_empty( $value ) {
		return ( is_array( $value ) && array() === $value ) ? new \stdClass() : $value;
	}

	/**
	 * Shorten text to at most $max characters.
	 *
	 * Prefers the last sentence end in the back half of the allowance: `.`,
	 * `!`, `?` followed by whitespace (not an abbreviation such as "e.g."),
	 * `。`, `！`, `？` anywhere, `؟` and `۔` followed by whitespace or the end.
	 * Otherwise cuts at the last whitespace and appends "...". Either way
	 * the result never splits a grapheme cluster (a combining mark, a ZWJ
	 * emoji sequence), never ends inside an open parenthesis, and a word cut
	 * never ends on an abbreviation — each backs off to the previous
	 * boundary instead.
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum length in characters (codepoints).
	 * @return string
	 */
	public static function shorten( $text, $max ) {
		$text = trim( self::ascii_punctuation( $text ) );
		if ( self::length( $text ) <= $max ) {
			return $text;
		}

		$clusters = self::graphemes( $text );
		$count    = count( $clusters );

		// $ends[$i] = codepoint length of the first $i clusters.
		$ends = array( 0 );
		foreach ( $clusters as $i => $cluster ) {
			$ends[ $i + 1 ] = $ends[ $i ] + self::length( $cluster );
		}

		// 1. Sentence end in the back half of the allowance.
		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$length = $ends[ $i + 1 ];
			if ( $length > $max ) {
				continue;
			}
			if ( $length < (int) floor( $max / 2 ) ) {
				break;
			}
			if ( ! self::is_sentence_end( $clusters, $i ) ) {
				continue;
			}
			$candidate = rtrim( implode( '', array_slice( $clusters, 0, $i + 1 ) ) );
			if ( self::parentheses_balanced( $candidate ) ) {
				return $candidate;
			}
		}

		// 2. Word boundary, leaving room for the ellipsis.
		for ( $i = $count - 1; $i > 0; $i-- ) {
			if ( $ends[ $i ] > $max - 3 || ! preg_match( '/^\s+$/u', $clusters[ $i ] ) ) {
				continue;
			}
			$candidate = rtrim( implode( '', array_slice( $clusters, 0, $i ) ), " \t\n\r\0\x0B,;:-(" );
			if ( '' === $candidate || ! self::parentheses_balanced( $candidate ) || self::ends_with_abbreviation( $candidate ) ) {
				continue;
			}
			return $candidate . '...';
		}

		// 3. One unbroken run longer than the allowance: whole clusters only.
		$kept = 0;
		while ( $kept < $count && $ends[ $kept + 1 ] <= $max - 3 ) {
			$kept++;
		}
		return implode( '', array_slice( $clusters, 0, $kept ) ) . '...';
	}

	/**
	 * Whether cluster $i ends a sentence.
	 *
	 * @param string[] $clusters Grapheme clusters.
	 * @param int      $i        Index.
	 * @return bool
	 */
	private static function is_sentence_end( array $clusters, $i ) {
		$cluster  = $clusters[ $i ];
		$next     = isset( $clusters[ $i + 1 ] ) ? $clusters[ $i + 1 ] : '';
		$next_ws  = '' === $next || preg_match( '/^\s+$/u', $next );

		// CJK full stops need no following space.
		if ( in_array( $cluster, array( '。', '！', '？' ), true ) ) {
			return true;
		}
		if ( in_array( $cluster, array( '؟', '۔' ), true ) ) {
			return (bool) $next_ws;
		}
		if ( in_array( $cluster, array( '.', '!', '?' ), true ) && $next_ws ) {
			return ! ( '.' === $cluster && self::ends_with_abbreviation( implode( '', array_slice( $clusters, 0, $i + 1 ) ) ) );
		}
		return false;
	}

	/**
	 * Split text into extended grapheme clusters: grapheme_str_split() or
	 * PCRE's \X when available, else codepoints with every combining mark,
	 * variation selector, ZWJ and the codepoint a ZWJ joins merged into the
	 * cluster before it.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function graphemes( $text ) {
		if ( function_exists( 'grapheme_str_split' ) ) {
			$split = grapheme_str_split( $text );
			if ( is_array( $split ) ) {
				return $split;
			}
		}
		if ( preg_match_all( '/\X/u', $text, $matches ) ) {
			return $matches[0];
		}

		$codepoints = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $codepoints ) ) {
			return str_split( $text );
		}
		$clusters = array();
		$join     = false;
		foreach ( $codepoints as $codepoint ) {
			$extends = preg_match( '/^[\p{M}\x{200D}\x{FE0E}\x{FE0F}]$/u', $codepoint );
			if ( ( $extends || $join ) && ! empty( $clusters ) ) {
				$clusters[ count( $clusters ) - 1 ] .= $codepoint;
			} else {
				$clusters[] = $codepoint;
			}
			$join = ( "\u{200D}" === $codepoint );
		}
		return $clusters;
	}

	/**
	 * Whether every "(" in $text is closed.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function parentheses_balanced( $text ) {
		return substr_count( $text, '(' ) <= substr_count( $text, ')' );
	}

	/**
	 * Typographic punctuation replaced by its ASCII equivalent in tools/list
	 * text. WordPress sends JSON with non-ASCII escaped, so an em dash costs
	 * six bytes on the wire and a hyphen one; the meaning is the same.
	 *
	 * @var array<string,string>
	 */
	const ASCII_PUNCTUATION = array(
		'—' => '-',
		'–' => '-',
		'…' => '...',
		'→' => '->',
		'‘' => "'",
		'’' => "'",
		'“' => '"',
		'”' => '"',
		"\xC2\xA0" => ' ', // No-break space (U+00A0).
	);

	/**
	 * Replace typographic punctuation with ASCII.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function ascii_punctuation( $text ) {
		return strtr( (string) $text, self::ASCII_PUNCTUATION );
	}

	/**
	 * Whether text ends with a known abbreviation rather than a sentence.
	 *
	 * @param string $text Text ending in a period.
	 * @return bool
	 */
	private static function ends_with_abbreviation( $text ) {
		$lower = strtolower( $text );
		foreach ( self::NON_TERMINAL_ABBREVIATIONS as $abbreviation ) {
			$length = strlen( $abbreviation );
			if ( substr( $lower, -$length ) === $abbreviation ) {
				$before = strlen( $lower ) > $length ? substr( $lower, -$length - 1, 1 ) : ' ';
				if ( ! ctype_alnum( $before ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Whether an array is a JSON list rather than an object.
	 *
	 * @param array $value Array.
	 * @return bool
	 */
	private static function is_list( array $value ) {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Multibyte-safe length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}
