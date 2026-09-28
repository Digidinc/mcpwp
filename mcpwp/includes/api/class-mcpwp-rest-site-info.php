<?php
/**
 * Discovery, onboarding, site management & context.
 *
 * Carved from the original Mcpwp_REST_Site (G1 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovery, onboarding, site management & context.
 */
class Mcpwp_REST_Site_Info extends Mcpwp_REST_API {

	/** @var Mcpwp_Core */
	private $core;

	/** @var Mcpwp_Design_References */
	private $design_references;

	public function __construct() {
		$this->core              = new Mcpwp_Core();
		$this->design_references = new Mcpwp_Design_References();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/site-info',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_site_info' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/introspect',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_introspect' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'full' => array(
							'description'       => __( 'Return every tool with its full input schema (200KB+). Compact name+description list is the default.', 'mcpwp' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'tool' => array(
							'description' => __( 'Return only this tool\'s full entry (name, description, inputSchema, annotations, route) instead of the tool list. Takes precedence over full.', 'mcpwp' ),
							'type'        => 'string',
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/onboard',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_onboard' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'detail' => array(
							'description' => __( 'Response detail level. Default "slim" (~3k tokens or less) returns only first-contact essentials: identity, builder mode, what is connected, the top next actions, and pointers to more (tools/list, wp_get_guide, this param). Pass "full" for the complete legacy briefing, including the full tool catalog grouped by category, quick reference, suggested first tools, content inventory, and site context text.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'slim', 'full' ),
							'default'     => 'slim',
						),
						'sections' => array(
							'description' => __( 'Allowlist of top-level sections to include from the full briefing (e.g. ["site_identity","content_inventory"]). Implies detail=full. available_tools alone is the majority of that payload.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/site-context',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_site_context' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'scope' => array(
							'description' => __( 'Optional context scope such as page or product.', 'mcpwp' ),
							'type'        => 'string',
						),
						'archetype_class' => array(
							'description' => __( 'Optional archetype class for inherited context lookup.', 'mcpwp' ),
							'type'        => 'string',
						),
						'style' => array(
							'description' => __( 'Optional archetype style for inherited context lookup.', 'mcpwp' ),
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_site_context' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'context' => array(
							'description' => __( 'Site context markdown text (AI brief, style guide, rules).', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/site-state',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_site_state' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'graph_limit' => array(
							'description'       => __( 'Maximum content records to inspect for graph health.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 100,
							'minimum'           => 1,
							'maximum'           => 250,
							'sanitize_callback' => 'absint',
						),
						'event_limit' => array(
							'description'       => __( 'Maximum recent events to include.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 50,
							'sanitize_callback' => 'absint',
						),
						'include_drafts' => array(
							'description'       => __( 'Include draft/private content in graph health.', 'mcpwp' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
						'include_plugins' => array(
							'description'       => __( 'Include active plugin file names in capability output.', 'mcpwp' ),
							'type'              => 'boolean',
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/content-coherence',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_content_coherence' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/agent-playbooks',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_agent_playbook' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name' => array(
							'description'       => __( 'Optional playbook name.', 'mcpwp' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/theme-info',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_theme_info' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/site-health',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_site_health' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/flush-permalinks',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'flush_permalinks' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/plugins',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_plugins' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/analytics',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_analytics' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'days' => array(
							'description' => __( 'Number of days.', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => 30,
							'minimum'     => 1,
							'maximum'     => 365,
						),
					),
				),
			)
		);
	}

	public function get_site_info( $request ) {
		$this->log_activity( 'site_info', $request );

		$info = $this->core->get_site_info();

		return $this->success_response( $info );
	}

	public function get_introspect( $request ) {
		$this->log_activity( 'introspect', $request );

		if ( ! class_exists( 'Mcpwp_REST_MCP' ) ) {
			return $this->success_response(
				array(
					'plugin'  => array(
						'name'    => 'MCPWP',
						'version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
					),
					'message' => 'MCP controller not available.',
				)
			);
		}

		$mcp = new Mcpwp_REST_MCP();
		if ( ! method_exists( $mcp, 'get_introspection_data' ) ) {
			return $this->success_response(
				array(
					'plugin'  => array(
						'name'    => 'MCPWP',
						'version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
					),
					'message' => 'Introspection is not supported in this version.',
				)
			);
		}

		$data = $mcp->get_introspection_data();

		// A single-tool lookup answers the exact gap #830 reported: the compact
		// list below has no schema, and ?full=true dumps every tool's schema to
		// get one. Return just the requested tool's full entry — same shape a
		// tool would have inside the legacy full payload — and skip building
		// the compact list entirely. Wins over `full`, which asked for
		// "everything"; naming one tool is a narrower, more specific request.
		$tool_filter = (string) $request->get_param( 'tool' );
		if ( '' !== $tool_filter && isset( $data['tools'] ) && is_array( $data['tools'] ) ) {
			foreach ( $data['tools'] as $tool_entry ) {
				if ( isset( $tool_entry['name'] ) && $tool_entry['name'] === $tool_filter ) {
					return $this->success_response(
						array(
							'plugin' => $data['plugin'] ?? array(),
							'tool'   => $tool_entry,
						)
					);
				}
			}
			return $this->error_response(
				'unknown_tool',
				sprintf( __( 'Unknown tool "%s".', 'mcpwp' ), $tool_filter ),
				404,
				array( 'hint' => 'Call wp_introspect with no arguments for the compact tool list, or wp_introspect(full=true) for every tool.' )
			);
		}

		// Compact by default: the full output (every tool with its input
		// schema and route map) exceeds 200KB / 5,000+ lines, which overflows
		// AI-client contexts. Summarize tools to name + one-line description;
		// schemas stay available via MCP tools/list (with Tool Search), a
		// single-tool lookup (?tool=<name>, above), or ?full=true for the
		// legacy payload.
		$full = rest_sanitize_boolean( $request->get_param( 'full' ) );
		if ( ! $full && isset( $data['tools'] ) && is_array( $data['tools'] ) ) {
			$summary = array();
			foreach ( $data['tools'] as $tool ) {
				$name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
				if ( '' === $name ) {
					continue;
				}
				$desc = isset( $tool['description'] ) ? (string) $tool['description'] : '';
				// First sentence, capped — enough to pick a tool, not to call it.
				$cut  = strpos( $desc, '. ' );
				if ( false !== $cut && $cut < 140 ) {
					$desc = substr( $desc, 0, $cut + 1 );
				} elseif ( strlen( $desc ) > 140 ) {
					$desc = substr( $desc, 0, 137 ) . '...';
				}
				$summary[] = array(
					'name'        => $name,
					'description' => $desc,
				);
			}
			$data['tools_count'] = count( $summary );
			$data['tools']       = $summary;
			$data['tools_note']  = 'Compact tool list (name + summary). Full input schemas: MCP tools/list, or GET /introspect?full=true.';
		}

		// Include site context if set. #1007 review round 2 owner decision:
		// site context is public brand copy — it is already published,
		// unauthenticated, at /llms.txt (Mcpwp_AI_Presence::generate_llms_txt()
		// reads the same mcpwp_site_context option). READING it is therefore
		// on the discovery allowlist for every key regardless of role or the
		// site-wide toggle (see get_discovery_allowlist()'s 'wp_get_site_context'
		// entry, 'read_only' => true — GET /site-context and mcpwp://site-context
		// carry the identical exemption). WRITING it (wp_set_site_context,
		// POST/PUT /site-context) stays gated to the 'site' category — that is
		// the site owner's own edit, not published data.
		$site_context = get_option( 'mcpwp_site_context', '' );
		if ( '' !== $site_context ) {
			$data['site_context'] = $site_context;
		}

		return $this->success_response( $data );
	}

	public function get_onboard( $request ) {
		$this->log_activity( 'onboard', $request );

		// #905: slim is the default now — an explicit `sections` allowlist
		// (#830) still narrows the full legacy briefing built below, so it
		// implies detail=full rather than being filtered out of the slim
		// shape (which has no `available_tools`/`quick_reference`/etc. keys
		// to select from in the first place).
		$sections    = $request->get_param( 'sections' );
		$has_sections = is_array( $sections ) && ! empty( $sections );
		$full        = $has_sections || ( 'full' === sanitize_key( (string) $request->get_param( 'detail' ) ) );

		$site_info    = $this->core->get_site_info();
		$capabilities = $site_info['capabilities'] ?? array();

		// 1. Site identity.
		$identity = array(
			// Decoded for agents (#903) — see Mcpwp_Agent_Text. get_site_info()
			// already decodes 'name'/'description'; the ?? fallback is
			// wrapped too, defensively, in case that key is ever absent.
			'name'        => $site_info['name'] ?? Mcpwp_Agent_Text::decode( get_bloginfo( 'name' ) ),
			'description' => $site_info['description'] ?? Mcpwp_Agent_Text::decode( get_bloginfo( 'description' ) ),
			'url'         => $site_info['url'] ?? home_url(),
			'admin_url'   => $site_info['admin_url'] ?? admin_url(),
			'language'    => $site_info['language'] ?? get_locale(),
			'timezone'    => $site_info['timezone'] ?? wp_timezone_string(),
			'is_rtl'      => $site_info['is_rtl'] ?? false,
			'wp_version'  => $site_info['wp_version'] ?? $GLOBALS['wp_version'],
			'theme'       => $site_info['theme'] ?? array(),
			'plugin'      => $site_info['plugin'] ?? array(
				'name'    => 'MCPWP',
				'version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
			),
		);

		// 2. Content inventory.
		$counts     = wp_count_posts( 'post' );
		$page_counts = wp_count_posts( 'page' );

		$inventory = array(
			'posts'   => array(
				'published' => (int) ( $counts->publish ?? 0 ),
				'drafts'    => (int) ( $counts->draft ?? 0 ),
				'total'     => (int) ( ( $counts->publish ?? 0 ) + ( $counts->draft ?? 0 ) + ( $counts->private ?? 0 ) ),
			),
			'pages'   => array(
				'published' => (int) ( $page_counts->publish ?? 0 ),
				'drafts'    => (int) ( $page_counts->draft ?? 0 ),
				'total'     => (int) ( ( $page_counts->publish ?? 0 ) + ( $page_counts->draft ?? 0 ) + ( $page_counts->private ?? 0 ) ),
			),
			'media'   => array_sum( array_map( 'intval', (array) wp_count_attachments() ) ),
			'categories' => (int) wp_count_terms( 'category' ),
			'tags'       => (int) wp_count_terms( 'post_tag' ),
		);

		// Add product count if WooCommerce is active.
		if ( ! empty( $capabilities['woocommerce'] ) && post_type_exists( 'product' ) ) {
			$product_counts          = wp_count_posts( 'product' );
			$inventory['products'] = array(
				'published' => (int) ( $product_counts->publish ?? 0 ),
				'drafts'    => (int) ( $product_counts->draft ?? 0 ),
			);
		}

		// Recent updates and the design reference inventory are only needed for
		// the full legacy briefing (#905) — skip both the query and the bytes
		// on the slim default path.
		if ( $full ) {
			// Security clamp: a read-only key must not see draft/private
			// titles/statuses in the onboarding brief — only widen beyond
			// published for write scope (or trusted admin-AJAX).
			$recent_statuses = $this->requester_can_see_non_public()
				? array( 'publish', 'draft', 'private' )
				: array( 'publish' );
			$recent_posts = get_posts(
				array(
					'post_type'      => array( 'post', 'page' ),
					'post_status'    => $recent_statuses,
					'orderby'        => 'modified',
					'order'          => 'DESC',
					'posts_per_page' => 5,
				)
			);

			$recent_updates = array();
			foreach ( $recent_posts as $p ) {
				$recent_updates[] = array(
					'id'       => $p->ID,
					'title'    => Mcpwp_Agent_Text::decode( $p->post_title ),
					'type'     => $p->post_type,
					'status'   => $p->post_status,
					'modified' => $p->post_modified,
				);
			}
			$inventory['recent_updates'] = $recent_updates;
			$design_reference_inventory  = $this->design_references->list_references(
				array(
					'per_page' => 3,
					'page'     => 1,
				)
			);
			$inventory['design_references'] = array(
				'total'  => (int) ( $design_reference_inventory['total'] ?? 0 ),
				'recent' => $design_reference_inventory['references'] ?? array(),
			);
		}

		// 3. Active integrations.
		$integrations = array();

		if ( ! empty( $capabilities['elementor'] ) ) {
			$integrations['elementor'] = array(
				'active'      => true,
				'version'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : 'unknown',
				'pro'         => ! empty( $capabilities['elementor_pro'] ),
				'layout_mode' => $capabilities['elementor_layout_mode'] ?? 'section',
			);

			// Atomic mode implies the Container experiment is also active, but
			// Elementor's own render pipeline is currently dropping atomic
			// elements (e-div-block, e-heading, ...) after save. Steer agents
			// toward what renders today rather than what the mode name suggests.
			if ( 'atomic' === ( $capabilities['elementor_layout_mode'] ?? '' ) ) {
				$integrations['elementor']['note'] = 'Build with container + classic widgets — they render correctly. Atomic elements are accepted on write but not yet confirmed to render; check render_verified in the wp_set_elementor response.';
			}
		}

		if ( ! empty( $capabilities['gutenberg'] ) ) {
			$integrations['gutenberg'] = array(
				'active' => true,
			);
		}

		// SEO plugin detection.
		$seo_plugins = array(
			'yoast'    => 'Yoast SEO',
			'rankmath' => 'RankMath',
			'aioseo'   => 'AIOSEO',
			'seopress' => 'SEOPress',
		);
		foreach ( $seo_plugins as $key => $label ) {
			if ( ! empty( $capabilities[ $key ] ) ) {
				$integrations['seo'] = array(
					'active' => true,
					'plugin' => $label,
				);
				break;
			}
		}

		// Forms plugin detection.
		$form_plugins = array(
			'cf7'          => 'Contact Form 7',
			'wpforms'      => 'WPForms',
			'gravityforms' => 'Gravity Forms',
			'ninjaforms'   => 'Ninja Forms',
		);
		foreach ( $form_plugins as $key => $label ) {
			if ( ! empty( $capabilities[ $key ] ) ) {
				$integrations['forms'] = array(
					'active' => true,
					'plugin' => $label,
				);
				break;
			}
		}

		if ( ! empty( $capabilities['woocommerce'] ) ) {
			$integrations['woocommerce'] = array(
				'active' => true,
			);
		}

		$is_pro = class_exists( 'Mcpwp_License' ) && Mcpwp_License::get_instance()->is_pro();

		// 4. Available tools grouped by category. This is the single largest
		// contributor to this endpoint's payload — every tool with its
		// description, duplicating tools/list (#830 measured it at ~95% of
		// the full briefing). #905: only build it for the full briefing.
		$tools_by_category = array();
		if ( $full && class_exists( 'Mcpwp_REST_MCP' ) ) {
			$mcp       = new Mcpwp_REST_MCP();
			$all_tools = method_exists( $mcp, 'get_introspection_data' )
				? ( $mcp->get_introspection_data()['tools'] ?? array() )
				: array();

			foreach ( $all_tools as $tool ) {
				$cat  = $tool['annotations']['category'] ?? 'site';
				$name = $tool['name'] ?? '';
				if ( '' === $name ) {
					continue;
				}
				if ( $this->requester_is_webmcp() && ! Mcpwp_WebMCP::is_allowlisted( $name ) ) {
					continue;
				}
				if ( ! isset( $tools_by_category[ $cat ] ) ) {
					$tools_by_category[ $cat ] = array();
				}
				$tools_by_category[ $cat ][] = array(
					'name'        => $name,
					'description' => $tool['description'] ?? '',
					'tier'        => ( ! empty( $tool['annotations']['tier'] ) ) ? $tool['annotations']['tier'] : 'free',
				);
			}
		}

		// 5. Site context. Full markdown text (up to 50KB, see set_site_context)
		// is deferred to the full briefing (#905) — the slim default only
		// needs to know whether one is configured; wp_get_site_context reads
		// the text directly.
		$site_context    = get_option( 'mcpwp_site_context', '' );
		$context_section = array(
			'configured' => '' !== $site_context,
		);
		if ( $full ) {
			if ( '' !== $site_context ) {
				// #1007 review round 2 owner decision: site context is public
				// brand copy, already published unauthenticated at /llms.txt —
				// reading it is on the discovery allowlist for every key (see
				// get_discovery_allowlist()'s 'wp_get_site_context' entry).
				$context_section['context'] = $site_context;
			} else {
				$context_section['hint'] = 'No site context configured. Use wp_set_site_context to define design rules, color palette, typography, and layout guidelines.';
			}
		}

		// 6. Recommended first actions.
		$actions = array();

		if ( '' === $site_context ) {
			$actions[] = array(
				'action'      => 'Set up site context',
				'tool'        => 'wp_set_site_context',
				'description' => 'Define your site design rules, color palette, typography, and layout guidelines so AI assistants follow your brand.',
			);
		}

		if ( $inventory['pages']['published'] === 0 ) {
			$actions[] = array(
				'action'      => 'Create your first page',
				'tool'        => 'wp_create_page',
				'description' => 'No published pages found. Create a homepage or landing page to get started.',
			);
		}

		if ( ! empty( $capabilities['elementor'] ) ) {
			$actions[] = array(
				'action'      => 'Review Elementor status',
				'tool'        => 'wp_elementor_status',
				'description' => 'Check which pages use Elementor and the current layout mode.',
			);
		}

		if ( isset( $integrations['seo'] ) ) {
			$actions[] = array(
				'action'      => 'Audit SEO metadata',
				'tool'        => 'wp_seo_status',
				'description' => 'Check SEO coverage across your pages and identify missing meta descriptions.',
			);
		}

		if ( $inventory['posts']['published'] > 0 ) {
			$actions[] = array(
				'action'      => 'Review recent content',
				'tool'        => 'wp_list_posts',
				'description' => 'Browse existing blog posts to understand current content.',
			);
		}

		// #1007 WARN-2: /onboard is on the discovery allowlist, so it stays
		// reachable by every key, but its own SUGGESTIONS must not point a
		// category-restricted key (or any key while a category is disabled
		// site-wide) at a tool that same key's next tools/call would refuse.
		$actions = $this->filter_actions_by_caller_categories( $actions );

		if ( ! $full ) {
			// #905: slim by default — everything an agent needs in its first
			// 30 seconds (identity, builder mode, what's connected, the top 3
			// next actions) and nothing else. No tool catalog dump — that
			// duplicated tools/list and was ~95% of this endpoint's payload
			// (#830 measured it; this makes the fix the default instead of
			// opt-in). Every field below `available_tools`, `quick_reference`,
			// `suggested_first_tools`, full `recommended_actions`, recent
			// content, design references, and the full site context text
			// stays reachable via detail=full or a `sections` allowlist —
			// nothing here is deleted, only deferred.
			$builder_mode = null;
			if ( ! empty( $integrations['elementor'] ) ) {
				$builder_mode = 'elementor:' . $integrations['elementor']['layout_mode'];
			} elseif ( ! empty( $integrations['gutenberg'] ) ) {
				$builder_mode = 'gutenberg';
			}

			$data = array(
				'site_identity' => array(
					'name'       => $identity['name'],
					'url'        => $identity['url'],
					'wp_version' => $identity['wp_version'],
					'plugin'     => $identity['plugin'],
				),
				'builder_mode'            => $builder_mode,
				'connected'               => $integrations,
				'content_summary'         => array(
					'pages' => array(
						'published' => $inventory['pages']['published'],
						'total'     => $inventory['pages']['total'],
					),
					'posts' => array(
						'published' => $inventory['posts']['published'],
						'total'     => $inventory['posts']['total'],
					),
					'media' => $inventory['media'],
				),
				'site_context_configured' => $context_section['configured'],
				'next_actions'            => array_slice( $actions, 0, 3 ),
				// #1007 review round 2, NIT-2: 'guide' points at wp_get_guide
				// ('site' category, still genuinely gated) — must not be
				// offered to a key that cannot call it. 'site_context' points
				// at wp_get_site_context, which is on the discovery allowlist
				// (round 2 owner decision: public brand copy) and therefore
				// always survives the filter; 'tools'/'full_briefing' name no
				// single gated tool at all. filter_actions_by_caller_categories()
				// is the same filter next_actions/quick_reference/
				// suggested_first_tools already use, so this can never
				// disagree with them.
				'more'                    => array_column(
					$this->filter_actions_by_caller_categories(
						array(
							array( 'key' => 'tools', 'tool' => '', 'text' => 'Call MCP tools/list (or GET /introspect) for the full tool catalog with input schemas.' ),
							array( 'key' => 'guide', 'tool' => 'wp_get_guide', 'text' => 'Call wp_get_guide(topic="...") for a topic guide — e.g. onboarding, elementor, seo, workflows.' ),
							array( 'key' => 'site_context', 'tool' => 'wp_get_site_context', 'text' => 'Call wp_get_site_context for the full site brief and design rules.' ),
							array( 'key' => 'full_briefing', 'tool' => '', 'text' => 'Call wp_onboard(detail="full") — or GET /onboard?detail=full — for the complete legacy briefing: full tool catalog, quick reference, suggested first tools, recent content, design references, and site context text.' ),
						)
					),
					'text',
					'key'
				),
				'pro_active'              => $is_pro,
				'detail'                  => 'slim',
			);

			return $this->success_response( $data );
		}

		// 7. Quick reference card — top 10 most-used tools.
		$quick_reference = array(
			array( 'tool' => 'wp_onboard', 'use' => 'First-connection site briefing (you are here)' ),
			array( 'tool' => 'wp_get_site_context', 'use' => 'Read site design rules and style guide' ),
			array( 'tool' => 'wp_list_pages', 'use' => 'List all pages with status and IDs' ),
			array( 'tool' => 'wp_list_posts', 'use' => 'List blog posts with filters' ),
			array( 'tool' => 'wp_create_page', 'use' => 'Create a new page' ),
			array( 'tool' => 'wp_search', 'use' => 'Search posts and pages by keyword' ),
			array( 'tool' => 'wp_fetch', 'use' => 'Get full content for a post or page by ID' ),
			array( 'tool' => 'wp_upload_media_from_url', 'use' => 'Upload an image from a URL' ),
			array( 'tool' => 'wp_detect_plugins', 'use' => 'Discover active plugins and capabilities' ),
			array( 'tool' => 'wp_site_info', 'use' => 'Get site name, URL, theme, and version info' ),
		);

		// Add Elementor tools to quick reference if active.
		if ( ! empty( $capabilities['elementor'] ) ) {
			$quick_reference[] = array( 'tool' => 'wp_get_elementor', 'use' => 'Read Elementor page builder data' );
			$quick_reference[] = array( 'tool' => 'wp_set_elementor', 'use' => 'Update Elementor page builder data' );
		}

		// 8. Suggested first tools — context-aware ordered list for agents on first connection.
		$suggested_first_tools = array(
			array(
				'tool'   => 'wp_site_info',
				'reason' => 'Get layout mode, active plugins, and capabilities',
			),
			array(
				'tool'   => 'wp_get_signals',
				'reason' => 'Check site health before making changes',
			),
		);

		if ( ! empty( $capabilities['elementor'] ) ) {
			$suggested_first_tools[] = array(
				'tool'   => 'wp_list_pages',
				'reason' => 'See existing pages before creating new ones',
			);
			$suggested_first_tools[] = array(
				'tool'   => 'wp_elementor_status',
				'reason' => 'Confirm layout mode (classic vs flexbox) before building',
			);
		} else {
			$suggested_first_tools[] = array(
				'tool'   => 'wp_list_pages',
				'reason' => 'See existing pages',
			);
			$suggested_first_tools[] = array(
				'tool'   => 'wp_list_block_types',
				'reason' => 'Discover available Gutenberg blocks',
			);
		}

		$suggested_first_tools[] = array(
			'tool'   => 'wp_get_site_context',
			'reason' => 'Read the site brief and design rules',
		);
		$suggested_first_tools[] = array(
			'tool'   => 'wp_list_posts',
			'reason' => 'Review recent content before editing',
		);

		// #1007 WARN-2: same caller-category filter as $actions above.
		$quick_reference       = $this->filter_actions_by_caller_categories( $quick_reference );
		$suggested_first_tools = $this->filter_actions_by_caller_categories( $suggested_first_tools );

		$quick_start = 'Start with wp_site_info, then wp_get_signals, then review existing content before making any changes.';

		$data = array(
			'site_identity'         => $identity,
			'content_inventory'     => $inventory,
			'active_integrations'   => $integrations,
			'available_tools'       => $tools_by_category,
			'site_context'          => $context_section,
			'recommended_actions'   => $actions,
			'quick_reference'       => $quick_reference,
			'suggested_first_tools' => $suggested_first_tools,
			'quick_start'           => $quick_start,
			'pro_active'            => $is_pro,
			// Read-only content-guardrails status (#957): current UTC-day
			// published count vs the cap, so an agent sees headroom before
			// it starts publishing.
			'guardrails_status'     => class_exists( 'Mcpwp_Content_Guardrails' ) ? Mcpwp_Content_Guardrails::get_status_summary() : array(),
		);

		// #830: an explicit `sections` allowlist still narrows the full
		// legacy briefing built above — unchanged behavior.
		if ( $has_sections ) {
			$data = array_intersect_key( $data, array_flip( $sections ) );
		}

		return $this->success_response( $data );
	}

	public function get_site_context( $request ) {
		$this->log_activity( 'get_site_context', $request );

		$context         = get_option( 'mcpwp_site_context', '' );
		$scope           = sanitize_key( (string) $request->get_param( 'scope' ) );
		$archetype_class = sanitize_key( (string) $request->get_param( 'archetype_class' ) );
		$style           = sanitize_text_field( (string) $request->get_param( 'style' ) );
		$effective       = $this->build_effective_site_context( $context, $scope, $archetype_class, $style );

		return $this->success_response(
			array(
				'context'           => $context,
				'effective_context' => $effective['effective_context'],
				'inheritance'       => $effective['inheritance'],
				'updated_at'        => get_option( 'mcpwp_site_context_updated', '' ),
				'hint'              => '' === $context
					? 'No site context configured. Use wp_set_site_context to define your site style guide, header/footer rules, predefined sections, and page structure guidelines. This will be included in wp_introspect so AI assistants automatically follow your design rules.'
					: null,
			)
		);
	}

	public function set_site_context( $request ) {
		$this->log_activity( 'set_site_context', $request );

		$context = $request->get_param( 'context' );

		if ( null === $context ) {
			return $this->error_response( 'missing_context', 'The context parameter is required.', 400 );
		}

		// Limit to 50KB to prevent abuse.
		if ( strlen( $context ) > 51200 ) {
			return $this->error_response( 'context_too_large', 'Site context must be under 50KB.', 400 );
		}

		update_option( 'mcpwp_site_context', $context );
		update_option( 'mcpwp_site_context_updated', gmdate( 'Y-m-d H:i:s' ) );

		return $this->success_response(
			array(
				'success'    => true,
				'length'     => strlen( $context ),
				'updated_at' => get_option( 'mcpwp_site_context_updated' ),
			)
		);
	}

	/**
	 * Filter a list of {'tool': 'wp_x', ...} recommendation entries (onboard's
	 * $actions / $quick_reference / $suggested_first_tools) down to tools the
	 * CURRENT caller could actually invoke through tools/call right now
	 * (#1007 WARN-2).
	 *
	 * /onboard itself is on the discovery allowlist (get_discovery_allowlist_routes()),
	 * so it stays reachable by every key regardless of role or the site-wide
	 * toggle — deliberately, for first contact. But before this fix its OWN
	 * suggestions were not filtered at all: an author-role key (categories:
	 * content, media, taxonomy) got pointed at wp_get_guide and
	 * wp_get_site_context ('site' category) — tools the identical key's next
	 * tools/call for either one would refuse with scope_denied. Mirrors the
	 * same two gates handle_tools_call() enforces (site-wide disabled
	 * category, per-key role category) plus the shared discovery-tool
	 * exemption, so a suggestion here and a tools/call for it never disagree.
	 *
	 * @param array $entries Each with a 'tool' key naming an MCP tool.
	 * @return array Filtered list, same shape and order.
	 */
	private function filter_actions_by_caller_categories( array $entries ) {
		// A WebMCP user (#981) can call the WebMCP allowlist and nothing
		// else — so point it at nothing else.
		if ( $this->requester_is_webmcp() ) {
			return array_values(
				array_filter(
					$entries,
					static function ( $entry ) {
						return isset( $entry['tool'] ) && Mcpwp_WebMCP::is_allowlisted( (string) $entry['tool'] );
					}
				)
			);
		}

		$key_record           = $this->get_current_api_key_record();
		$allowed_categories   = is_array( $key_record ) ? $this->resolve_key_tool_categories( $key_record ) : array();
		$disabled_categories  = get_option( 'mcpwp_disabled_tool_categories', array() );
		$disabled_categories  = is_array( $disabled_categories ) ? $disabled_categories : array();

		// Nothing restricts anything — skip building the tool-category map.
		if ( empty( $allowed_categories ) && empty( $disabled_categories ) ) {
			return $entries;
		}

		$tool_categories = array();
		if ( class_exists( 'Mcpwp_MCP_Free_Tools' ) ) {
			$tool_categories = ( new Mcpwp_MCP_Free_Tools() )->get_tool_categories();
		}
		if ( class_exists( 'Mcpwp_MCP_Pro_Tools' ) ) {
			$tool_categories = array_merge( $tool_categories, ( new Mcpwp_MCP_Pro_Tools() )->get_tool_categories() );
		}

		$discovery_exempt = self::get_discovery_allowlist_tool_names();

		return array_values(
			array_filter(
				$entries,
				function ( $entry ) use ( $allowed_categories, $disabled_categories, $tool_categories, $discovery_exempt ) {
					$name = isset( $entry['tool'] ) ? (string) $entry['tool'] : '';
					if ( '' === $name || in_array( $name, $discovery_exempt, true ) ) {
						return true;
					}
					$category = isset( $tool_categories[ $name ] ) ? (string) $tool_categories[ $name ] : '';
					if ( '' !== $category && in_array( $category, $disabled_categories, true ) ) {
						return false;
					}
					if ( ! empty( $allowed_categories ) ) {
						// Same fail-closed convention as filter_tools_by_key_role():
						// an uncategorised tool is denied to a category-restricted key.
						return '' !== $category && in_array( $category, $allowed_categories, true );
					}
					return true;
				}
			)
		);
	}

	private function build_effective_site_context( $base_context, $scope, $archetype_class, $style ) {
		$inheritance = array(
			'scope'           => $scope,
			'archetype_class' => $archetype_class,
			'style'           => $style,
			'matched'         => false,
		);

		if ( '' === $scope || '' === $archetype_class ) {
			return array(
				'effective_context' => $base_context,
				'inheritance'       => $inheritance,
			);
		}

		$matched = $this->find_context_override( $scope, $archetype_class, $style );
		if ( empty( $matched['brief'] ) ) {
			return array(
				'effective_context' => $base_context,
				'inheritance'       => $inheritance,
			);
		}

		$effective = trim( (string) $base_context );
		if ( '' !== $effective ) {
			$effective .= "\n\n";
		}

		$effective .= "## Page-Type Override\n";
		$effective .= '- Scope: ' . $scope . "\n";
		$effective .= '- Class: ' . $archetype_class . "\n";
		if ( '' !== $style ) {
			$effective .= '- Style: ' . $style . "\n";
		}
		if ( ! empty( $matched['title'] ) ) {
			$effective .= '- Source: ' . $matched['title'] . "\n";
		}
		$effective .= "\n" . trim( (string) $matched['brief'] );

		$inheritance['matched'] = true;
		$inheritance['source']  = $matched;

		return array(
			'effective_context' => $effective,
			'inheritance'       => $inheritance,
		);
	}

	/**
	 * #1007 review round 3, BLOCK-1. build_effective_site_context() is the
	 * one function every door to site context goes through — REST
	 * GET /site-context, tools/call wp_get_site_context, resources/read
	 * mcpwp://site-context, and mcpwp://brand's site-context half (all four
	 * dispatch to get_site_context() above, directly or via an internal
	 * sub-request) — so this is the single chokepoint to gate, not each door
	 * separately.
	 *
	 * The round-2 owner decision made the BASE mcpwp_site_context text
	 * public (it is published, unauthenticated, at /llms.txt). It said
	 * nothing about a scope='product'/archetype_class page-type OVERRIDE:
	 * that pulls a WooCommerce product archetype brief
	 * (find_product_context_override(), backed by the 'mcpwp_wc_product_archetypes'
	 * option — same data wc_get_product_archetype/wc_list_product_archetypes
	 * gate behind the 'woocommerce' category) or an Elementor page archetype
	 * brief (find_page_context_override(), backed by
	 * Mcpwp_Elementor_Pro::get_archetypes() — same data
	 * wp_get_elementor_archetype/wp_list_elementor_archetypes gate behind
	 * 'elementor-templates'). Neither brief is in /llms.txt. Live evidence
	 * from the review: an author read-key with 'site' AND 'woocommerce' AND
	 * 'elementor-templates' all disabled still got the full product/page
	 * brief plus its archetype title and id back from every one of the four
	 * doors, while a direct call to the archetype's OWN route
	 * (GET /woocommerce/archetypes) correctly 403'd.
	 *
	 * Fix: apply the override only when the CURRENT caller may read the
	 * archetype's own category (site-wide toggle + per-key role, same two
	 * gates every other category check in this plugin applies — admin, and
	 * any context with no key record at all, are unaffected). When the
	 * caller may not, return array() — exactly the "no archetype matched"
	 * shape build_effective_site_context() already falls back to: the base
	 * context only, inheritance.matched stays false, and no 'source' key —
	 * so a denied caller sees nothing that reveals an archetype exists,
	 * let alone its title, id, or brief.
	 *
	 * @param string $scope           'product' or a page-archetype scope.
	 * @param string $archetype_class Archetype class to match.
	 * @param string $style           Optional archetype style to match.
	 * @return array Override {type,id,title,brief}, or array() (no match / not permitted).
	 */
	private function find_context_override( $scope, $archetype_class, $style ) {
		if ( 'product' === $scope ) {
			if ( ! $this->caller_may_read_category( 'woocommerce' ) ) {
				return array();
			}
			return $this->find_product_context_override( $archetype_class, $style );
		}

		if ( ! $this->caller_may_read_category( 'elementor-templates' ) ) {
			return array();
		}
		return $this->find_page_context_override( $scope, $archetype_class, $style );
	}

	// caller_may_read_category() was promoted to the shared Mcpwp_Api_Auth
	// trait (trait-mcpwp-api-auth.php) so #1020's post-type category guard
	// could reuse the exact same "site-wide toggle + per-key role" pair
	// this chokepoint pioneered, instead of a second copy of it. This
	// controller inherits it via Mcpwp_REST_API; see the trait for the
	// canonical implementation.

	private function find_page_context_override( $scope, $archetype_class, $style ) {
		if ( ! class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			return array();
		}

		$elementor  = new Mcpwp_Elementor_Pro();
		$archetypes = $elementor->get_archetypes(
			array(
				'scope'           => $scope,
				'archetype_class' => $archetype_class,
				'style'           => $style,
				'posts_per_page'  => 1,
			)
		);

		if ( empty( $archetypes ) || ! is_array( $archetypes ) ) {
			return array();
		}

		$item = reset( $archetypes );
		if ( empty( $item['archetype_brief'] ) ) {
			return array();
		}

		return array(
			'type'  => 'elementor_archetype',
			'id'    => isset( $item['id'] ) ? (int) $item['id'] : 0,
			'title' => isset( $item['title'] ) ? (string) $item['title'] : '',
			'brief' => (string) $item['archetype_brief'],
		);
	}

	private function find_product_context_override( $archetype_class, $style ) {
		$items = get_option( 'mcpwp_wc_product_archetypes', array() );
		if ( ! is_array( $items ) ) {
			return array();
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( $archetype_class !== (string) ( $item['archetype_class'] ?? '' ) ) {
				continue;
			}
			if ( '' !== $style && $style !== (string) ( $item['archetype_style'] ?? '' ) ) {
				continue;
			}
			if ( empty( $item['brief'] ) ) {
				continue;
			}

			return array(
				'type'  => 'product_archetype',
				'id'    => isset( $item['id'] ) ? (int) $item['id'] : 0,
				'title' => isset( $item['name'] ) ? (string) $item['name'] : '',
				'brief' => (string) $item['brief'],
			);
		}

		return array();
	}

	public function get_site_state( $request ) {
		$this->log_activity( 'get_site_state', $request );

		$snapshot = class_exists( 'Mcpwp_Site_State' )
			? Mcpwp_Site_State::get_snapshot(
				array(
					'graph_limit'     => min( 250, max( 1, absint( $request->get_param( 'graph_limit' ) ) ) ),
					'event_limit'     => min( 50, max( 1, absint( $request->get_param( 'event_limit' ) ) ) ),
					// Security clamp: caller-controlled include_drafts only honored for
					// a key that may see non-public content; a read-only key can never
					// pull draft/private nodes into the site-state graph snapshot.
					'include_drafts'  => rest_sanitize_boolean( $request->get_param( 'include_drafts' ) ) && $this->requester_can_see_non_public(),
					'include_plugins' => rest_sanitize_boolean( $request->get_param( 'include_plugins' ) ),
					// Approval + SEO summaries carry source-derived title/url/status/
					// excerpt fields; only a privileged caller sees non-public ones.
					'reveal_non_public' => $this->requester_can_see_non_public(),
					// #1025: the approvals and events sections of this snapshot embed
					// full approval records verbatim (pending_items/approved_items/
					// rollback_ready, events.items) — the same leak fixed on
					// /approvals and /events themselves, reached through yet another
					// category gate (/site-state is 'site').
					'category_allowed'  => array( $this, 'caller_may_read_category' ),
				)
			)
			: array();

		return $this->success_response( $snapshot );
	}

	public function get_content_coherence( $request ) {
		$this->log_activity( 'get_content_coherence', $request );

		// #1025 round 2, WARN-2: score_governance() reads
		// $state['approvals']['counts']['pending'] and
		// $state['events']['items'] straight from Mcpwp_Site_State::get_snapshot()
		// — an unfiltered snapshot handed an unfiltered pending-approval count
		// and high-risk-event count to /content-coherence (category 'seo')
		// for ANY key holding 'seo', regardless of whether that key may read
		// the categories the counted approvals/events actually target. Must
		// be computed from the SAME filtered set this key is allowed to see.
		$report = class_exists( 'Mcpwp_Content_Coherence' )
			? Mcpwp_Content_Coherence::get_report(
				array( 'category_allowed' => array( $this, 'caller_may_read_category' ) )
			)
			: array();

		return $this->success_response( $report );
	}

	public function get_agent_playbook( $request ) {
		$name     = $request->get_param( 'name' );
		$playbook = class_exists( 'Mcpwp_Agent_Playbooks' ) ? Mcpwp_Agent_Playbooks::get_playbook( $name ) : array();

		if ( is_wp_error( $playbook ) ) {
			return $playbook;
		}

		$this->log_activity( 'get_agent_playbook', $request, array( 'name' => sanitize_key( (string) $name ) ) );

		return $this->success_response( $playbook );
	}

	public function get_theme_info( $request ) {
		$this->log_activity( 'get_theme_info', $request );

		$theme  = wp_get_theme();
		$parent = $theme->parent();

		$info = array(
			'name'           => $theme->get( 'Name' ),
			'version'        => $theme->get( 'Version' ),
			'author'         => $theme->get( 'Author' ),
			'author_uri'     => $theme->get( 'AuthorURI' ),
			'theme_uri'      => $theme->get( 'ThemeURI' ),
			'description'    => $theme->get( 'Description' ),
			'text_domain'    => $theme->get( 'TextDomain' ),
			'is_child'       => (bool) $parent,
			'parent'         => $parent ? array(
				'name'    => $parent->get( 'Name' ),
				'version' => $parent->get( 'Version' ),
			) : null,
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
			'template'       => $theme->get_template(),
			'stylesheet'     => $theme->get_stylesheet(),
		);

		// Page templates
		$templates              = $theme->get_page_templates();
		$info['page_templates'] = array();
		foreach ( $templates as $slug => $name ) {
			$info['page_templates'][] = array(
				'slug' => $slug,
				'name' => $name,
			);
		}

		// Elementor compatibility
		$info['elementor'] = array(
			'active'      => defined( 'ELEMENTOR_VERSION' ),
			'version'     => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
			'layout_mode' => 'section',
		);

		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			if ( Mcpwp_Elementor_Experiments::is_container_active() ) {
				$info['elementor']['layout_mode'] = 'container';
			}
		}

		return $this->success_response( $info );
	}

	public function get_site_health( $request ) {
		$this->log_activity( 'get_site_health', $request );

		// Content counts by status.
		$post_counts = array();
		foreach ( array( 'post', 'page' ) as $type ) {
			$counts               = (array) wp_count_posts( $type );
			$post_counts[ $type ] = array();
			foreach ( $counts as $status => $count ) {
				if ( (int) $count > 0 ) {
					$post_counts[ $type ][ $status ] = (int) $count;
				}
			}
		}

		// Pages not in any menu.
		$menu_page_ids = array();
		$menus         = wp_get_nav_menus();
		foreach ( $menus as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			if ( $items ) {
				foreach ( $items as $item ) {
					if ( 'page' === $item->object ) {
						$menu_page_ids[] = (int) $item->object_id;
					}
				}
			}
		}
		$menu_page_ids = array_unique( $menu_page_ids );

		$all_pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$orphan_pages = array_diff( $all_pages, $menu_page_ids );
		$orphan_list  = array();
		foreach ( array_slice( $orphan_pages, 0, 20 ) as $pid ) {
			$orphan_list[] = array(
				'id'    => $pid,
				'title' => Mcpwp_Agent_Text::decode( get_the_title( $pid ) ),
				'slug'  => get_post_field( 'post_name', $pid ),
			);
		}

		// Pages missing featured images.
		$no_thumb    = array();
		$pages_query = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'NOT EXISTS',
				),
				),
			)
		);
		foreach ( array_slice( $pages_query, 0, 20 ) as $pid ) {
			$no_thumb[] = array(
				'id'    => $pid,
				'title' => Mcpwp_Agent_Text::decode( get_the_title( $pid ) ),
			);
		}

		// Active plugins.
		$active_plugins = get_option( 'active_plugins', array() );
		$plugins_list   = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all_plugins = get_plugins();
		foreach ( $active_plugins as $plugin_file ) {
			if ( isset( $all_plugins[ $plugin_file ] ) ) {
				$plugins_list[] = array(
					'name'    => $all_plugins[ $plugin_file ]['Name'],
					'version' => $all_plugins[ $plugin_file ]['Version'],
				);
			}
		}

		return $this->success_response(
			array(
				'content_counts'          => $post_counts,
				'orphan_pages'            => array(
					'count' => count( $orphan_pages ),
					'items' => $orphan_list,
				),
				'pages_missing_thumbnail' => array(
					'count' => count( $pages_query ),
					'items' => $no_thumb,
				),
				'active_plugins'          => $plugins_list,
				// Whether this site can still receive plugin releases. Health
				// reports that omit it read as green while security updates
				// are silently unreachable (#716).
				'update_channel'          => class_exists( 'Mcpwp_Update_Channel_Health' )
					? Mcpwp_Update_Channel_Health::get_status()
					: null,
				'wp_version'              => get_bloginfo( 'version' ),
				'php_version'             => PHP_VERSION,
				'permalink_structure'     => get_option( 'permalink_structure' ),
			)
		);
	}

	public function flush_permalinks( $request ) {
		$this->log_activity( 'flush_permalinks', $request );

		flush_rewrite_rules();

		return $this->success_response(
			array(
				'success'   => true,
				'message'   => __( 'Permalink rewrite rules flushed.', 'mcpwp' ),
				'structure' => get_option( 'permalink_structure' ),
			)
		);
	}

	public function get_plugins( $request ) {
		$this->log_activity( 'plugins', $request );

		$plugins = $this->core->detect_plugins();

		return $this->success_response(
			array(
				'plugins'      => $plugins,
				'capabilities' => $this->core->get_capabilities(),
			)
		);
	}

	public function get_analytics( $request ) {
		$this->log_activity( 'analytics', $request );

		$days      = $request->get_param( 'days' );
		$analytics = $this->core->get_analytics( $days );

		return $this->success_response( $analytics );
	}

}
