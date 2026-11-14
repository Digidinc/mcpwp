<?php
/**
 * Elementor REST Controller (Basic - FREE)
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Basic Elementor REST controller.
 *
 * FREE tier includes:
 * - Get/set Elementor data
 * - Check Elementor status
 * - Create Elementor-enabled page
 *
 * PRO endpoints registered via mcpwp-pro plugin.
 */
class Mcpwp_REST_Elementor extends Mcpwp_REST_API {

	/**
	 * Elementor handler.
	 *
	 * @var Mcpwp_Elementor_Basic
	 */
	private $elementor;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->elementor = new Mcpwp_Elementor_Basic();
	}

	/**
	 * Authenticate, then wire the category check into Mcpwp_Elementor_Basic
	 * (#1024, follow-up to #1022's WARN-2: elementor_library/elementor_snippet
	 * read and written through the generic /elementor door needed only the
	 * coarse 'elementor' category, bypassing their own elementor-templates/
	 * elementor-theme category — mirrors Mcpwp_REST_Posts::check_permission()).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function check_permission( $request ) {
		$result = parent::check_permission( $request );

		if ( true === $result ) {
			$this->elementor->set_category_allowed_callback(
				function ( $post_type ) {
					return $this->caller_may_read_category( $this->category_for_post_type( $post_type ) );
				}
			);

			// #1032: refuse a write that embeds a Template Library reference
			// (Template/Global widget, Loop Grid/Carousel, ...) this caller
			// cannot read, and redact one on the way out of a preview render.
			$this->elementor->set_template_ref_gate( $this->template_ref_gate() );
			$this->elementor->set_nested_render_gate( $this->template_render_gate() );
		}

		return $result;
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Elementor status
		register_rest_route(
			$this->namespace,
			'/elementor/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Bulk get Elementor data (register BEFORE /elementor/{id} to avoid route conflicts).
		register_rest_route(
			$this->namespace,
			'/elementor/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_elementor_data_bulk' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'ids' => array(
							'description' => __( 'Comma-separated page IDs (max 25).', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Get/set page Elementor data
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_elementor_data' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'strip_defaults' => array(
							'description' => __( 'Strip default widget settings to reduce payload size.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'include_raw'    => array(
							'description' => __( 'Also include elementor_json (raw JSON string duplicate of elementor_data). Off by default — doubles the payload.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'element_id'     => array(
							'description' => __( 'Return only the subtree of this one element (by id, from wp_get_elementor_summary) instead of the full page. 404 if not found.', 'mcpwp' ),
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_elementor_data' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'elementor_data' => array(
							'description' => __( 'Elementor data (JSON array or object).', 'mcpwp' ),
							'type'        => array( 'string', 'array' ),
						),
						'elementor_json' => array(
							'description' => __( 'Elementor data as JSON string.', 'mcpwp' ),
							'type'        => 'string',
						),
						'elementor_data_base64' => array(
							'description' => __( 'Base64-encoded Elementor JSON data. Use instead of elementor_data to avoid quoting/escaping issues with large HTML payloads.', 'mcpwp' ),
							'type'        => 'string',
						),
						'dry_run' => array(
							'description' => __( 'If true, validate only — no changes are saved.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'repair_ids' => array(
							'description' => __( 'Rewrite element ids that are not exactly 7 alphanumeric characters and repoint the references Elementor resolves to them (#842).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'convert' => array(
							'description' => __( 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Get Elementor summary (lightweight).
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/summary',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_elementor_summary' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Get rendered HTML preview.
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/preview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_elementor_preview' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'format' => array(
							'description' => __( 'Output format: "summary" (text + stats, no HTML — saves tokens), "text" (full text extraction), "html" (full rendered HTML).', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'summary', 'text', 'html' ),
							'default'     => 'summary',
						),
					),
				),
			)
		);

		// Edit a single Elementor element (surgical patch).
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/edit-section',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'edit_section' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'element_id'      => array(
							'description' => __( 'Elementor element ID to edit.', 'mcpwp' ),
							'type'        => 'string',
						),
						'section_index'   => array(
							'description' => __( 'Top-level section index (0-based).', 'mcpwp' ),
							'type'        => 'integer',
						),
						'find'            => array(
							'description' => __( 'Search criteria: {widgetType, "settings.key": value}.', 'mcpwp' ),
							'type'        => 'object',
						),
						'settings'        => array(
							'description' => __( 'Settings to merge into the element.', 'mcpwp' ),
							'type'        => 'object',
						),
						'delete_settings' => array(
							'description' => __( 'Setting keys to remove from the element.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
						'convert' => array(
							'description' => __( 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Add a new section/container to an Elementor page.
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/add-section',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_section' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'       => array(
							'required' => true,
							'type'     => 'integer',
						),
						'element'  => array(
							'description' => __( 'The new section/container element object.', 'mcpwp' ),
							'required'    => true,
							'type'        => 'object',
						),
						'position' => array(
							'description' => __( 'Position: start, end, before:{id}, after:{id}.', 'mcpwp' ),
							'required'    => false,
							'type'        => 'string',
							'default'     => 'end',
						),
						'convert' => array(
							'description' => __( 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Remove a section/container from an Elementor page.
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/remove-section',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'remove_section' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'         => array(
							'required' => true,
							'type'     => 'integer',
						),
						'element_id' => array(
							'description' => __( 'The Elementor element ID to remove.', 'mcpwp' ),
							'required'    => true,
							'type'        => 'string',
						),
					),
				),
			)
		);

		// Replace an entire section/container in an Elementor page.
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/replace-section',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'replace_section' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'         => array(
							'required' => true,
							'type'     => 'integer',
						),
						'element_id' => array(
							'description' => __( 'The Elementor element ID to replace.', 'mcpwp' ),
							'required'    => true,
							'type'        => 'string',
						),
						'element'    => array(
							'description' => __( 'The replacement section/container element.', 'mcpwp' ),
							'required'    => true,
							'type'        => 'object',
						),
						'convert' => array(
							'description' => __( 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Apply multiple patch operations to an Elementor page.
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/patch',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'patch_elementor' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'         => array(
							'required' => true,
							'type'     => 'integer',
						),
						'operations' => array(
							'description' => __( 'Array of patch operations: {op, element_id, element, position, settings, delete_settings}. Required unless repair_ids is true.', 'mcpwp' ),
							'type'        => 'array',
						),
						'repair_ids' => array(
							'description' => __( 'Rewrite element ids that are not exactly 7 alphanumeric characters and repoint the references Elementor resolves to them (#842).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'convert' => array(
							'description' => __( 'Set true to convert a page that has existing non-Elementor content. Without it the write is refused rather than destroying that content (#788).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Edit a single widget's settings by widget ID (lightweight patch).
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/edit-widget',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'edit_widget' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'widget_id'       => array(
							'description' => __( 'Elementor widget element ID.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'settings'        => array(
							'description' => __( 'Settings to merge into the widget.', 'mcpwp' ),
							'type'        => 'object',
						),
						'delete_settings' => array(
							'description' => __( 'Setting keys to remove from the widget.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		// Create Elementor page
		register_rest_route(
			$this->namespace,
			'/elementor/page',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_elementor_page' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'title'          => array(
							'description' => __( 'Page title.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'status'         => array(
							'description' => __( 'Page status.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
							'default'     => 'draft',
						),
						'elementor_data' => array(
							'description' => __( 'Initial Elementor data.', 'mcpwp' ),
							'type'        => array( 'string', 'array' ),
						),
					),
				),
			)
		);

		// Find and replace in Elementor data
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/find-replace',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'find_replace' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'search'  => array(
							'description' => __( 'Text to search for.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
							'minLength'   => 1,
						),
						'replace' => array(
							'description' => __( 'Replacement text.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Find/replace across the site, a post type, a list of ids, or one id (#803).
		register_rest_route(
			$this->namespace,
			'/elementor/find-replace',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'find_replace_many' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'search'    => array(
							'description' => __( 'Text to search for.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
							'minLength'   => 1,
						),
						'replace'   => array(
							'description' => __( 'Replacement text.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'id'        => array(
							'description' => __( 'One post ID.', 'mcpwp' ),
							'type'        => 'integer',
						),
						'ids'       => array(
							'description' => __( 'Post IDs to limit the run to.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'integer' ),
						),
						'post_type' => array(
							'description' => __( 'Limit to one post type.', 'mcpwp' ),
							'type'        => 'string',
						),
						'scope'     => array(
							'description' => __( 'Pass "site" to run across every Elementor post. Site-wide is opt-in, never a fallthrough.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'site' ),
						),
						'offset'    => array(
							'description' => __( 'Resume point returned as next_offset by a truncated run.', 'mcpwp' ),
							'type'        => 'integer',
							'default'     => 0,
						),
						'allow_compounding' => array(
							'description' => __( 'Confirm a replacement that contains its own search text.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'dry_run'   => array(
							'description' => __( 'Report without writing.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Widget schema — list all widget types.
		register_rest_route(
			$this->namespace,
			'/elementor/widgets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_widget_schema' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'search'   => array(
							'description' => __( 'Case-insensitive substring match against widget name, title, and description.', 'mcpwp' ),
							'type'        => 'string',
						),
						'category' => array(
							'description' => __( 'Filter to widgets whose reference category matches exactly.', 'mcpwp' ),
							'type'        => 'string',
						),
						'fields'   => array(
							'description' => __( 'Allowlist of per-widget keys to return (e.g. ["name","category"]). Omit for every field.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		// Widget schema — get schema for a specific widget type.
		register_rest_route(
			$this->namespace,
			'/elementor/widgets/(?P<type>[\w-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_widget_schema' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'tab'    => array(
							'description' => __( 'Return controls from only this tab (content, style, or advanced).', 'mcpwp' ),
							'type'        => 'string',
						),
						'fields' => array(
							'description' => __( 'Allowlist of top-level result keys to return (e.g. ["tabs"] or ["description","example","common_mistakes"]). Omit for every field.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		// Widget help — get offline reference data for a widget type.
		register_rest_route(
			$this->namespace,
			'/elementor/widget-help/(?P<widget_type>[\w-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_widget_help' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Elementor kit CSS — read/write global CSS without Elementor Pro.
		// The kit is a standard post; custom_css lives in _elementor_page_settings.
		// wp_set_elementor_globals is Pro-only because it includes global colors/fonts,
		// but CSS injection works on any Elementor install.
		register_rest_route(
			$this->namespace,
			'/elementor/kit-css',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_kit_css' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_kit_css' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'css'  => array(
							'description' => __( 'CSS to write to the Elementor kit (global stylesheet).', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'mode' => array(
							'description' => __( 'append (default) or replace.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'append', 'replace' ),
							'default'     => 'replace',
						),
					),
				),
			)
		);

		// Elementor kit CSS blocks — named, hash-tagged, idempotent regions
		// inside the same kit CSS (#641). Complements the whole-stylesheet
		// replace/append modes above with an upsert/delete primitive that
		// never duplicates content on retries and never touches unrelated CSS.
		register_rest_route(
			$this->namespace,
			'/elementor/kit-css/blocks',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_kit_css_blocks' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upsert_kit_css_block' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'          => array(
							'description' => __( 'Block name — lowercase letters, digits, hyphens, underscores.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'css'           => array(
							'description' => __( 'CSS body for this named block.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'expected_hash' => array(
							'description' => __( 'Optional optimistic-concurrency hash from a prior read; rejected with a conflict if the block changed since.', 'mcpwp' ),
							'type'        => 'string',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/kit-css/blocks/(?P<name>[\w-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_kit_css_block' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_kit_css_block' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Regenerate CSS
		register_rest_route(
			$this->namespace,
			'/elementor/regenerate-css',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'regenerate_css' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array(
							'description' => __( 'Page ID. Omit to regenerate all site CSS.', 'mcpwp' ),
							'type'        => 'integer',
						),
						'force' => array(
							'description' => __( 'Delete existing CSS files before regenerating.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Get Elementor status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_status( $request ) {
		$this->log_activity( 'elementor_status', $request );

		$status = $this->elementor->get_status();

		// Add info about available features
		$status['features'] = array(
			'free' => array(
				'get_data'    => true,
				'set_data'    => true,
				'create_page' => true,
			),
			'pro'  => array(
				'templates'    => false,
				'landing_page' => false,
				'clone'        => false,
				'widgets'      => false,
				'globals'      => false,
			),
		);

		// Check if Pro is active
		if ( class_exists( 'Mcpwp_Elementor_Pro' ) ) {
			$status['features']['pro'] = array(
				'templates'    => true,
				'landing_page' => true,
				'clone'        => true,
				'widgets'      => true,
				'globals'      => true,
			);
		}

		return $this->success_response( $status );
	}

	/**
	 * Get Elementor data for page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_elementor_data( $request ) {
		$this->log_activity( 'get_elementor', $request );

		$page_id = $request->get_param( 'id' );

		// Non-public gate: a read-only key must not reach the full Elementor body
		// of a draft/pending/private page. Return the same 404 as validate_post()
		// for a genuinely missing/unsupported post so existence is never disclosed.
		// Hint built by the SAME helper validate_post() uses for its own
		// missing/unsupported branch (#1026) so the two 404s are byte-identical.
		$denied = $this->guard_non_public_post(
			get_post( absint( $page_id ) ),
			// #1030 round-3, item 2: matches Mcpwp_Elementor_Basic::validate_post()'s
			// own message — see not_found_hint()'s docblock for why.
			__( 'The requested resource was not found.', 'mcpwp' ),
			$this->elementor->not_found_hint( $page_id )
		);
		if ( $denied ) {
			return $denied;
		}

		$options = array(
			'strip_defaults' => (bool) $request->get_param( 'strip_defaults' ),
			'include_raw'    => (bool) $request->get_param( 'include_raw' ),
			'element_id'     => (string) $request->get_param( 'element_id' ),
		);
		$result  = $this->elementor->get_elementor_data( $page_id, $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Get Elementor data for multiple pages in bulk.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_elementor_data_bulk( $request ) {
		$this->log_activity( 'get_elementor_bulk', $request );

		$ids_raw = $request->get_param( 'ids' );
		if ( empty( $ids_raw ) ) {
			return $this->error_response( 'missing_param', __( 'ids parameter is required.', 'mcpwp' ), 400 );
		}

		$page_ids = array_filter( array_map( 'absint', explode( ',', $ids_raw ) ) );

		if ( count( $page_ids ) > 25 ) {
			return $this->error_response( 'too_many_ids', __( 'Maximum 25 page IDs per request.', 'mcpwp' ), 400 );
		}

		if ( empty( $page_ids ) ) {
			return $this->error_response( 'invalid_ids', __( 'No valid page IDs provided.', 'mcpwp' ), 400 );
		}

		// One per-item resolver for every bulk door (#1030 round-5): an id
		// that is missing, a draft a read-only key may not see, or a post
		// whose category this caller cannot read is never loaded, and its
		// errors entry is the one message a missing id gets — the same
		// message validate_post() returns for an unsupported type.
		$blocked = array();
		$visible = array();
		foreach ( $page_ids as $pid ) {
			if ( $this->post_ref_item_visible( $request, $pid ) ) {
				$visible[] = $pid;
			} else {
				$blocked[ (int) $pid ] = self::post_ref_not_found_message();
			}
		}
		$page_ids = $visible;

		$result = $this->elementor->get_elementor_data_bulk( $page_ids );

		if ( ! empty( $blocked ) ) {
			// Union (+) not array_merge: page IDs are integer keys and array_merge
			// would renumber them, dropping the id→message mapping.
			$existing         = isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array();
			$result['errors'] = $existing + $blocked;
		}

		return $this->success_response( $result );
	}

	/**
	 * Get lightweight Elementor summary for page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_elementor_summary( $request ) {
		$this->log_activity( 'get_elementor_summary', $request );

		$page_id = $request->get_param( 'id' );

		// Non-public gate — a read-only key must not read a draft's structure/copy.
		// Hint built by the SAME helper validate_post() uses for its own
		// missing/unsupported branch (#1026) so the two 404s are byte-identical.
		$denied = $this->guard_non_public_post(
			get_post( absint( $page_id ) ),
			// #1030 round-3, item 2: matches Mcpwp_Elementor_Basic::validate_post()'s
			// own message — see not_found_hint()'s docblock for why.
			__( 'The requested resource was not found.', 'mcpwp' ),
			$this->elementor->not_found_hint( $page_id )
		);
		if ( $denied ) {
			return $denied;
		}

		$result = $this->elementor->get_elementor_summary( $page_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Get rendered HTML preview of Elementor content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_elementor_preview( $request ) {
		$this->log_activity( 'get_elementor_preview', $request );

		$page_id = $request->get_param( 'id' );

		// Non-public gate — get_rendered_content() renders the full body in-process
		// (reaching unpublished content directly), so a read-only key must be
		// stopped here before any render happens.
		// Hint built by the SAME helper validate_post() uses for its own
		// missing/unsupported branch (#1026) so the two 404s are byte-identical.
		$denied = $this->guard_non_public_post(
			get_post( absint( $page_id ) ),
			// #1030 round-3, item 2: matches Mcpwp_Elementor_Basic::validate_post()'s
			// own message — see not_found_hint()'s docblock for why.
			__( 'The requested resource was not found.', 'mcpwp' ),
			$this->elementor->not_found_hint( $page_id )
		);
		if ( $denied ) {
			return $denied;
		}

		$format = $request->get_param( 'format' ) ? (string) $request->get_param( 'format' ) : 'summary';
		$result = $this->elementor->get_rendered_content( $page_id, $format );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// A preview is rendered from inside WordPress. Whether a visitor can
		// reach the same thing is a separate question, and on a gated staging
		// site the answers differ (#693).
		if ( is_array( $result ) ) {
			$result['public_route'] = Mcpwp_Public_Route::check( $page_id );

			if ( ! empty( $result['public_route']['warning'] ) ) {
				$result['warning'] = $result['public_route']['warning'];
			}
		}

		return $this->success_response( $result );
	}

	/**
	 * Set Elementor data for page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function set_elementor_data( $request ) {
		$this->log_activity( 'set_elementor', $request );

		$page_id = $request->get_param( 'id' );
		$dry_run = (bool) $request->get_param( 'dry_run' );
		$data    = array(
			'elementor_data'        => $request->get_param( 'elementor_data' ),
			'elementor_json'        => $request->get_param( 'elementor_json' ),
			'elementor_data_base64' => $request->get_param( 'elementor_data_base64' ),
			'repair_ids'            => rest_sanitize_boolean( $request->get_param( 'repair_ids' ) ),
			// Dropped here previously: the conversion-guard opt-in never
			// reached the writer, so `convert=true` could not un-stick a
			// would_convert_existing_content 409 (#883).
			'convert'               => rest_sanitize_boolean( $request->get_param( 'convert' ) ),
		);

		$result = $this->elementor->set_elementor_data( $page_id, $data, $dry_run );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Edit a single Elementor element (surgical patch).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function edit_section( $request ) {
		$this->log_activity( 'edit_elementor_section', $request );

		$page_id = absint( $request->get_param( 'id' ) );
		$args    = array(
			'element_id'      => $request->get_param( 'element_id' ),
			'section_index'   => $request->get_param( 'section_index' ),
			'find'            => $request->get_param( 'find' ),
			'settings'        => $request->get_param( 'settings' ),
			'delete_settings' => $request->get_param( 'delete_settings' ),
		);

		$result = $this->elementor->edit_section( $page_id, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Add a new section/container to an Elementor page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function add_section( $request ) {
		$this->log_activity( 'add_elementor_section', $request );

		return rest_ensure_response( $this->elementor->add_section( $request->get_param( 'id' ), $request->get_params() ) );
	}

	/**
	 * Remove a section/container from an Elementor page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function remove_section( $request ) {
		$this->log_activity( 'remove_elementor_section', $request );

		return rest_ensure_response( $this->elementor->remove_section( $request->get_param( 'id' ), $request->get_params() ) );
	}

	/**
	 * Replace an entire section/container in an Elementor page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function replace_section( $request ) {
		$this->log_activity( 'replace_elementor_section', $request );

		return rest_ensure_response( $this->elementor->replace_section( $request->get_param( 'id' ), $request->get_params() ) );
	}

	/**
	 * Apply multiple patch operations to an Elementor page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function patch_elementor( $request ) {
		$this->log_activity( 'patch_elementor', $request );

		return rest_ensure_response( $this->elementor->patch_elementor( $request->get_param( 'id' ), $request->get_params() ) );
	}

	/**
	 * Create Elementor page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	/**
	 * Edit a single widget's settings by widget ID.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function edit_widget( $request ) {
		$this->log_activity( 'edit_elementor_widget', $request );

		$page_id         = absint( $request->get_param( 'id' ) );
		$widget_id       = (string) $request->get_param( 'widget_id' );
		$settings        = $request->get_param( 'settings' );
		$delete_settings = $request->get_param( 'delete_settings' );

		$result = $this->elementor->edit_widget(
			$page_id,
			$widget_id,
			is_array( $settings ) ? $settings : array(),
			is_array( $delete_settings ) ? $delete_settings : array()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	public function create_elementor_page( $request ) {
		$this->log_activity( 'create_elementor_page', $request );

		$data = array(
			'title'          => $request->get_param( 'title' ),
			'status'         => $request->get_param( 'status' ),
			'elementor_data' => $request->get_param( 'elementor_data' ),
		);

		$result = $this->elementor->create_elementor_page( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	/**
	 * Get widget help (offline reference data).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_widget_help( $request ) {
		$this->log_activity( 'get_widget_help', $request );

		$widget_type = (string) $request->get_param( 'widget_type' );
		$result      = Mcpwp_Elementor_Widgets::get_widget_help( $widget_type );

		return $this->success_response( $result );
	}

	/**
	 * Get widget schema (list all or schema for a specific type).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_widget_schema( $request ) {
		$this->log_activity( 'get_widget_schema', $request );

		// 'type' is this route's own param name. 'widget' also accepted
		// (#831) — the Pro build registers a same-path route whose handler
		// reads 'widget', so a caller must not have to know which controller
		// answered /elementor/widgets on this install.
		$widget_type = $request->get_param( 'type' );
		// Both halves are load-bearing and #830 and #831 each wrote only one of
		// them, so this is the one hunk where taking either side wholesale
		// silently drops a shipped fix:
		//
		// #831 — accept the deprecated `widget` name as well as `type`. On main
		// the schema advertised `widget` while this handler read only `type`, so
		// the filter was silently dropped and every call listed all widgets.
		// #830 — pass the projection args through, turning a 119KB schema
		// response into ~3KB when a caller asks for one tab or a field subset.
		//
		// Keeping only #831 loses the projection; keeping only #830 re-breaks
		// the dropped-parameter bug for callers still on `widget`.
		if ( ! $widget_type ) {
			$widget_type = $request->get_param( 'widget' );
		}

		$fields = $request->get_param( 'fields' );

		// This arg's schema declares type=array but no sanitize_callback, so
		// WP_REST_Request::sanitize_params() never coerces it — a caller
		// sending the common REST convention of a comma-separated string
		// (?fields=tab,description) reaches here as a literal string and,
		// before this, was silently treated as "no filter", over-returning
		// the full ~119KB schema instead of projecting it down (#830's whole
		// point). Recover that intent rather than 400 it: this is a
		// read-only projection, not a security-relevant param, and CSV is
		// exactly how an array-typed query param is normally written. Absent
		// (null) and an already-decoded array both fall through unchanged.
		if ( is_string( $fields ) && '' !== $fields ) {
			$fields = array_values(
				array_filter(
					array_map( 'trim', explode( ',', $fields ) ),
					static function ( $field ) {
						return '' !== $field;
					}
				)
			);
		}

		$args        = array(
			'search'   => (string) $request->get_param( 'search' ),
			'category' => (string) $request->get_param( 'category' ),
			'tab'      => (string) $request->get_param( 'tab' ),
			'fields'   => is_array( $fields ) ? $fields : array(),
		);
		$result      = $this->elementor->get_widget_schema( $widget_type ? (string) $widget_type : '', $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Regenerate Elementor CSS.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function regenerate_css( $request ) {
		$this->log_activity( 'regenerate_elementor_css', $request );

		$page_id = $request->get_param( 'id' );
		$force   = (bool) $request->get_param( 'force' );
		$result  = $this->elementor->regenerate_css( $page_id ? absint( $page_id ) : null, $force );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Find and replace text in Elementor data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function find_replace( $request ) {
		$this->log_activity( 'elementor_find_replace', $request );

		$page_id = absint( $request->get_param( 'id' ) );
		$search  = (string) $request->get_param( 'search' );
		$replace = (string) $request->get_param( 'replace' );

		// Validate the post.
		$post = $this->elementor->validate_post( $page_id );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		// Get current Elementor data.
		$raw = get_post_meta( $page_id, '_elementor_data', true );
		if ( empty( $raw ) ) {
			return $this->error_response(
				'no_elementor_data',
				__( 'No Elementor data found for this post.', 'mcpwp' ),
				404
			);
		}

		// Decode first, then match on the decoded element tree. A raw-JSON substring
		// check produces false negatives: Elementor stores '<' as <, '>' as >,
		// and '/' as \/, so any search containing HTML tags or URL slashes never matches
		// the raw string even when the text is plainly present. Replacing on the raw JSON
		// string would also corrupt serialization, so the decode is required regardless.
		// In whatever form it is stored (#845): an array is the tree, and data
		// that cannot be a tree is refused before anything is replaced in it.
		$stored = Mcpwp_Elementor_Stored_Tree::read( $raw );
		if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $stored ) ) {
			return Mcpwp_Elementor_Stored_Tree::refusal( $stored, "This post's Elementor data" );
		}
		$decoded = $stored['elements'];

		$count           = 0;
		$updated_decoded = $this->recursive_str_replace( $search, $replace, $decoded, $count );

		// Nothing matched on the decoded tree — report cleanly without a write.
		if ( 0 === $count ) {
			return $this->success_response(
				array(
					'replacements' => 0,
					'post_id'      => $page_id,
					'message'      => __( 'Search text not found in Elementor data.', 'mcpwp' ),
				)
			);
		}

		$updated = wp_json_encode( $updated_decoded );

		if ( false === $updated ) {
			return $this->error_response(
				'json_encode_failed',
				__( 'Failed to re-encode Elementor data after replacement. Aborting to prevent data loss.', 'mcpwp' ),
				500
			);
		}

		// #1032 B2 (adversarial review round 1): find/replace writes the
		// POST-replacement tree straight to postmeta, bypassing
		// validate_and_fix_elements()/check_template_references() entirely —
		// a search/replace that turns an untouched, category-exempt page/post
		// value into a Template Library reference (or introduces one via the
		// replacement text itself) must be refused exactly like any other
		// write that embeds one this caller cannot read.
		$ref_check = $this->refuse_if_template_refs_unreadable(
			is_array( $updated_decoded ) ? $updated_decoded : array(),
			is_array( $decoded ) ? $decoded : array()
		);
		if ( is_wp_error( $ref_check ) ) {
			return $this->error_response_from_wp_error( $ref_check );
		}

		// Save updated data. Normalised first: this path never reaches
		// validate_and_fix_elements(), so a find/replace over a page still
		// carrying `css_id` used to write it straight back (#737).
		if ( class_exists( 'Mcpwp_Elementor_Element_Keys' ) ) {
			$updated = Mcpwp_Elementor_Element_Keys::normalize_json( $updated );
		}

		$written = Mcpwp_Elementor_Meta_Write::update( $page_id, '_elementor_data', $updated );
		if ( is_wp_error( $written ) ) {
			return $this->error_response_from_wp_error( $written );
		}

		// Clear the same per-document caches Document::save() clears on a
		// normal write — CSS file and element-render cache (#891). This path
		// writes _elementor_data directly and never calls Document::save(),
		// so without this the live page kept serving the pre-replace render
		// from `_elementor_element_cache` until a site-wide cache clear.
		$this->elementor->invalidate_document_cache( $page_id );

		return $this->success_response(
			array(
				'replacements' => $count,
				'post_id'      => $page_id,
				'search'       => $search,
				'replace'      => $replace,
			)
		);
	}

	/**
	 * How many posts one find/replace run touches. A truncated run says so
	 * and hands back next_offset; nothing silently stops mid-loop.
	 */
	const FIND_REPLACE_MAX_POSTS = 200;

	/**
	 * Wall-clock budget for one run, in seconds. A host timeout at post 120
	 * of 200 would leave 120 rewritten with no resume point; stopping early
	 * with next_offset is the honest version of the same limit.
	 */
	const FIND_REPLACE_TIME_BUDGET = 20;

	/**
	 * The time budget actually in force for a run, in seconds.
	 *
	 * Filterable so a host with a shorter PHP limit can lower it and so the
	 * out-of-time branch is reachable under test — a branch that no test can
	 * enter is dead code with a comment on it (#803 round 3).
	 *
	 * @return float Non-negative.
	 */
	public static function find_replace_time_budget() {
		$budget = apply_filters( 'mcpwp_find_replace_time_budget', self::FIND_REPLACE_TIME_BUDGET );

		return is_numeric( $budget ) ? max( 0.0, (float) $budget ) : (float) self::FIND_REPLACE_TIME_BUDGET;
	}

	/**
	 * Whether a find/replace request is a wide run — a whole type or the whole
	 * site — rather than a run over named posts. The auth route table and the
	 * handler both ask this, so they cannot disagree.
	 *
	 * @param array $params Request params.
	 * @return bool
	 */
	public static function find_replace_is_wide( array $params ) {
		// Unvalidated input: an array where a scalar is expected is "set",
		// never cast (a cast emits a notice inside the permission callback).
		$present = function ( $key ) use ( $params ) {
			if ( ! isset( $params[ $key ] ) ) {
				return false;
			}
			$v = $params[ $key ];
			return is_array( $v ) ? ! empty( $v ) : ( is_scalar( $v ) && '' !== trim( (string) $v ) );
		};

		$named = $present( 'ids' ) || ( $present( 'id' ) && ( ! is_scalar( $params['id'] ) || 0 !== (int) $params['id'] ) );

		if ( $named ) {
			return false;
		}

		return $present( 'scope' ) || $present( 'post_type' );
	}

	/**
	 * Find/replace across many posts (#803).
	 *
	 * Scope is explicit: id, ids, post_type, or scope=site. `dry_run` reports
	 * per-post counts without writing. The response keeps apart: nothing
	 * scanned, nothing matched, data that could not be read, and — for an
	 * id scope — ids that were asked for and not touched, each with why.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function find_replace_many( $request ) {
		$this->log_activity( 'elementor_find_replace_many', $request );

		$search  = (string) $request->get_param( 'search' );
		$replace = (string) $request->get_param( 'replace' );
		$dry_run = (bool) $request->get_param( 'dry_run' );
		$offset  = max( 0, (int) $request->get_param( 'offset' ) );

		if ( '' === $search ) {
			return $this->error_response( 'missing_search', __( 'search is required.', 'mcpwp' ), 400 );
		}

		// "Acme" → "Acme Group" applied twice yields "Acme Group Group". A
		// retry after a lost response is the common case, so a replacement
		// that contains its own search text is refused unless asked for.
		if ( false !== strpos( $replace, $search ) && ! rest_sanitize_boolean( $request->get_param( 'allow_compounding' ) ) ) {
			return $this->error_response(
				'compounding_replacement',
				__( 'replace contains search, so running this twice would compound ("Acme" → "Acme Group" → "Acme Group Group"). Pass allow_compounding=true if that is intended, and never retry it blind.', 'mcpwp' ),
				400
			);
		}

		// Scope is explicit: exactly one of id, ids, post_type, scope=site.
		// A scope that resolves to nothing is a refusal, never a widening —
		// `ids: []` from an agent whose candidate list came back empty must be
		// a no-op, not a site-wide rewrite.
		$scope = $this->resolve_find_replace_scope( $request );
		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		// A run across a whole type or the whole site rewrites content the
		// caller has not named. That is the class of operation this plugin
		// keeps behind admin scope (wp_deploy_site_blueprint, wp_push_to_live,
		// wp_delete_all_drafts); a named-id run stays at write scope.
		// The auth route table refuses this earlier for a scoped key (see
		// get_required_scope_for_request); this is the same rule at the
		// handler so a caller that reaches it another way meets it too.
		if ( in_array( $scope['label'], array( 'site' ), true ) || 0 === strpos( $scope['label'], 'post_type:' ) ) {
			$record = $this->get_current_api_key_record();
			if ( is_array( $record ) && ! $this->key_has_scope( $record, 'admin' ) ) {
				return $this->error_response(
					'admin_scope_required',
					__( 'A site-wide or per-type find/replace needs an admin-scoped key. Name the posts with id or ids to run at write scope.', 'mcpwp' ),
					403
				);
			}
		}

		$query_args = array(
			'post_type'      => $scope['post_types'],
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => self::FIND_REPLACE_MAX_POSTS + 1,
			'offset'         => $offset,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'meta_key'       => '_elementor_data', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- scoped to one meta key; this is the whole point of the query.
			'no_found_rows'  => true,
		);
		// One per-item resolver for every bulk door (#1030 round-5): a named
		// id that is missing or hidden from this caller is never queried,
		// never scanned, never written — it is reported below exactly as a
		// missing id is. A list that names only such ids scans nothing: an
		// empty post__in would otherwise widen the query to every post.
		$visible_ids = array();
		if ( ! empty( $scope['ids'] ) ) {
			foreach ( $scope['ids'] as $wanted_id ) {
				if ( $this->post_ref_item_visible( $request, $wanted_id ) ) {
					$visible_ids[] = $wanted_id;
				}
			}
			$query_args['post__in'] = $visible_ids;
		}

		$found = array();
		if ( empty( $scope['ids'] ) || ! empty( $visible_ids ) ) {
			$query = new WP_Query( $query_args );
			$found = is_array( $query->posts ) ? $query->posts : array();
		}
		$truncated = count( $found ) > self::FIND_REPLACE_MAX_POSTS;
		if ( $truncated ) {
			$found = array_slice( $found, 0, self::FIND_REPLACE_MAX_POSTS );
		}

		$matched    = array();
		$unreadable = array();
		$scanned    = 0;
		$empty      = 0;
		$total      = 0;
		$started       = microtime( true );
		$budget        = self::find_replace_time_budget();
		$processed     = 0;
		$processed_ids = array();
		$out_of_time   = false;
		$remaining     = count( $found );

		foreach ( $found as $candidate ) {
			// The budget is checked after each post, never before the first:
			// a run always makes progress, so next_offset = offset + processed
			// can never point at the post that was just finished, and a
			// budget of zero still processes exactly one post per call.
			if ( $processed > 0 && $processed < $remaining && ( microtime( true ) - $started ) > $budget ) {
				$out_of_time = true;
				break;
			}
			$processed++;

			$post_id         = is_object( $candidate ) ? (int) $candidate->ID : (int) $candidate;
			$processed_ids[] = $post_id;

			// The same gate the single-post route applies: never a type this
			// plugin does not treat as Elementor content, and never a type
			// this caller's category cannot read (#1024).
			$candidate_type = get_post_type( $post_id );
			if ( ! in_array( $candidate_type, $scope['post_types'], true ) || $this->find_replace_category_denied( $candidate_type ) ) {
				continue;
			}

			// In whatever form it is stored (#845): an array is scanned like
			// text rather than skipped as empty, and data that cannot be a
			// tree is unreadable whatever its form.
			$raw = get_post_meta( $post_id, '_elementor_data', true );

			if ( empty( $raw ) ) {
				$empty++;
				continue;
			}

			$scanned++;

			$stored = Mcpwp_Elementor_Stored_Tree::read( $raw );
			if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $stored ) ) {
				$unreadable[] = array(
					'id'        => $post_id,
					'title'     => get_the_title( $post_id ),
					'stored_as' => $stored['stored_as'],
				);
				continue;
			}

			$decoded = $stored['elements'];

			$count   = 0;
			$updated = $this->recursive_str_replace( $search, $replace, $decoded, $count );

			if ( 0 === $count ) {
				continue;
			}

			$entry = array(
				'id'           => $post_id,
				'title'        => get_the_title( $post_id ),
				'post_type'    => get_post_type( $post_id ),
				'replacements' => $count,
			);
			$total += $count;

			if ( ! $dry_run ) {
				// #1032 B2 (adversarial review round 1): same refusal as the
				// single-post find_replace() above, applied to THIS post's
				// post-replacement tree before it is written.
				$ref_check = $this->refuse_if_template_refs_unreadable(
					is_array( $updated ) ? $updated : array(),
					is_array( $decoded ) ? $decoded : array()
				);
				if ( is_wp_error( $ref_check ) ) {
					$entry['written'] = false;
					$entry['error']   = $ref_check->get_error_code();
					$matched[]        = $entry;
					continue;
				}

				$encoded = wp_json_encode( $updated );
				if ( false === $encoded ) {
					$entry['written'] = false;
					$entry['error']   = 'json_encode_failed';
					$matched[]        = $entry;
					continue;
				}
				if ( class_exists( 'Mcpwp_Elementor_Element_Keys' ) ) {
					$encoded = Mcpwp_Elementor_Element_Keys::normalize_json( $encoded );
				}
				$written = Mcpwp_Elementor_Meta_Write::update( $post_id, '_elementor_data', $encoded );
				if ( is_wp_error( $written ) ) {
					$entry['written'] = false;
					$entry['error']   = $written->get_error_code();
					$matched[]        = $entry;
					continue;
				}
				// Same per-document cache clear find_replace() does (#891) —
				// this loop is the same bypass-of-Document::save() write path,
				// once per matched post.
				$this->elementor->invalidate_document_cache( $post_id );
				$entry['written'] = true;
			}

			$matched[] = $entry;
		}

		// For a named-id scope, every id asked for and not touched is reported
		// with why. A miss must never read as an empty success — and a post
		// the run never opened must never read as "no match" (#803 round 4):
		// that is a claim about content that was not read.
		$not_touched = array();
		if ( ! empty( $scope['ids'] ) ) {
			$touched   = array_merge( array_column( $matched, 'id' ), array_column( $unreadable, 'id' ) );
			$candidate = array_map( function ( $c ) { return is_object( $c ) ? (int) $c->ID : (int) $c; }, $found );
			foreach ( $scope['ids'] as $wanted ) {
				if ( in_array( $wanted, $touched, true ) ) {
					continue;
				}
				if ( ( $out_of_time || $truncated ) && in_array( $wanted, $candidate, true ) && ! in_array( $wanted, $processed_ids, true ) ) {
					$not_touched[] = array( 'id' => $wanted, 'reason' => 'not_processed' );
					continue;
				}
				$post = in_array( $wanted, $visible_ids, true ) ? get_post( $wanted ) : null;
				if ( ! $post ) {
					// Missing and hidden (gated category, or a draft a
					// read-only key may not see) are the same answer, from
					// the same resolver (#1030 round-5) — "type_not_supported"
					// would still disclose that the id resolves to SOMETHING.
					$reason = 'not_found';
				} elseif ( ! in_array( $post->post_type, $scope['post_types'], true ) ) {
					$reason = 'type_not_supported';
				} elseif ( empty( get_post_meta( $wanted, '_elementor_data', true ) ) ) {
					// empty(), not a (string) cast: array-stored data is data (#845).
					$reason = 'no_elementor_data';
				} else {
					$reason = 'no_match';
				}
				$not_touched[] = array( 'id' => $wanted, 'reason' => $reason );
			}
		}

		$response = array(
			'dry_run'            => $dry_run,
			'scope'              => $scope['label'],
			'ids'                => $scope['ids'],
			'search'             => $search,
			'replace'            => $replace,
			'scanned'            => $scanned,
			'skipped_empty'      => $empty,
			'matched'            => $matched,
			'unreadable'         => $unreadable,
			'not_touched'        => $not_touched,
			'total_replacements' => $total,
			'truncated'          => $truncated || $out_of_time,
		);

		if ( $truncated || $out_of_time ) {
			// Out of time: every post up to $processed is finished (the check
			// runs after a post, not before), so the resume point is exactly
			// offset + processed. Capped: the full page was processed.
			$response['next_offset'] = max( 0, $offset + ( $out_of_time ? $processed : self::FIND_REPLACE_MAX_POSTS ) );
			$response['warning']     = $out_of_time
				? sprintf(
					/* translators: %s: seconds */
					__( 'Stopped after %s seconds so the host timeout could not cut the run mid-way. Call again with the same scope and offset = next_offset to continue.', 'mcpwp' ),
					rtrim( rtrim( number_format( $budget, 2, '.', '' ), '0' ), '.' )
				)
				: sprintf(
					/* translators: %d: post cap */
					__( 'Only the first %d posts in scope were processed. Call again with the same scope (same ids) and offset = next_offset to continue.', 'mcpwp' ),
					self::FIND_REPLACE_MAX_POSTS
				);
		}

		return $this->success_response( $response );
	}

	/**
	 * Post types a find/replace may touch: what the single-post route allows,
	 * plus public custom types that are not blocked outright.
	 *
	 * @return array
	 */
	private function find_replace_allowed_types() {
		// Exactly what validate_post() lets every other Elementor route read
		// and write. A type this plugin cannot read back through its own
		// tools must not be rewritable through this one.
		return array( 'page', 'post', 'elementor_library', 'elementor_snippet' );
	}

	/**
	 * Whether $post_type needs a category the caller does not hold, ON TOP
	 * of the /elementor/find-replace route's own 'elementor' category —
	 * the SAME exemption Mcpwp_Elementor_Basic::validate_post() applies
	 * (#1024, follow-up to #1022's WARN-2): page/post are this feature's
	 * base case and need nothing more (the designer role holds 'elementor'
	 * but deliberately not 'content'); elementor_library/elementor_snippet
	 * are ALSO rewritable here but keep their own narrower category
	 * (elementor-templates/elementor-theme —
	 * Mcpwp_Api_Auth::category_for_post_type()), so a site-wide toggle that
	 * disables just those still blocks a find/replace across them.
	 *
	 * @param string $post_type Candidate post type.
	 * @return bool
	 */
	private function find_replace_category_denied( $post_type ) {
		if ( in_array( $post_type, array( 'page', 'post' ), true ) ) {
			return false;
		}

		return ! $this->caller_may_read_category( $this->category_for_post_type( $post_type ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array|WP_Error {ids, post_types, label}
	 */
	private function resolve_find_replace_scope( $request ) {
		$allowed  = $this->find_replace_allowed_types();
		$has_id   = null !== $request->get_param( 'id' ) && '' !== $request->get_param( 'id' );
		$has_ids  = null !== $request->get_param( 'ids' );
		$has_type = null !== $request->get_param( 'post_type' ) && '' !== $request->get_param( 'post_type' );
		$scope_param = $request->get_param( 'scope' );
		$has_site    = 'site' === $scope_param;

		if ( null !== $scope_param && '' !== $scope_param && ! $has_site ) {
			return $this->error_response(
				'invalid_scope',
				sprintf(
					/* translators: %s: the value */
					__( 'scope "%s" is not recognised. The only value is "site"; otherwise name id, ids or post_type.', 'mcpwp' ),
					is_scalar( $scope_param ) ? (string) $scope_param : gettype( $scope_param )
				),
				400
			);
		}

		$given = (int) $has_id + (int) $has_ids + (int) $has_type + (int) $has_site;

		if ( 0 === $given ) {
			return $this->error_response(
				'missing_scope',
				__( 'Say what to touch: id, ids, post_type, or scope="site". A site-wide run is opt-in.', 'mcpwp' ),
				400
			);
		}

		if ( $given > 1 ) {
			return $this->error_response(
				'ambiguous_scope',
				__( 'Pass exactly one of id, ids, post_type, or scope="site".', 'mcpwp' ),
				400
			);
		}

		if ( $has_id || $has_ids ) {
			$raw = $has_id ? array( $request->get_param( 'id' ) ) : $request->get_param( 'ids' );
			if ( ! is_array( $raw ) || empty( $raw ) ) {
				return $this->error_response(
					'invalid_ids',
					__( 'ids must be a non-empty list of post IDs. An empty list is refused rather than widened to the whole site.', 'mcpwp' ),
					400
				);
			}
			$ids = array();
			foreach ( $raw as $value ) {
				if ( ! is_numeric( $value ) || (int) $value < 1 ) {
					return $this->error_response(
						'invalid_ids',
						sprintf(
							/* translators: %s: the offending value */
							__( 'ids must be positive post IDs; "%s" is not.', 'mcpwp' ),
							is_scalar( $value ) ? (string) $value : gettype( $value )
						),
						400
					);
				}
				$ids[] = (int) $value;
			}

			$ids = array_values( array_unique( $ids ) );
			if ( count( $ids ) > self::FIND_REPLACE_MAX_POSTS ) {
				return $this->error_response(
					'too_many_ids',
					sprintf(
						/* translators: %d: cap */
						__( 'At most %d ids per run. Split the list.', 'mcpwp' ),
						self::FIND_REPLACE_MAX_POSTS
					),
					400
				);
			}

			return array(
				'ids'        => $ids,
				'post_types' => $allowed,
				'label'      => $has_id ? 'id' : 'ids',
			);
		}

		if ( $has_type ) {
			$type = sanitize_key( (string) $request->get_param( 'post_type' ) );
			if ( ! in_array( $type, $allowed, true ) ) {
				return $this->error_response(
					'invalid_post_type',
					sprintf(
						/* translators: 1: type 2: allowed list */
						__( 'post_type "%1$s" is not an Elementor content type. Allowed: %2$s.', 'mcpwp' ),
						$type,
						implode( ', ', $allowed )
					),
					400
				);
			}

			// Naming a whole post type up front is not an existence oracle
			// (the caller named it, nothing about a specific record is
			// disclosed either way) — same 403 forbidden_category shape
			// guard_post_type_category_for_type() already uses for this
			// exact situation elsewhere (#1024). page/post are exempt: see
			// find_replace_category_denied()'s docblock.
			if ( ! in_array( $type, array( 'page', 'post' ), true ) ) {
				$category_denied = $this->guard_post_type_category_for_type( $type );
				if ( is_wp_error( $category_denied ) ) {
					return $category_denied;
				}
			}

			return array(
				'ids'        => array(),
				'post_types' => array( $type ),
				'label'      => 'post_type:' . $type,
			);
		}

		return array(
			'ids'        => array(),
			'post_types' => $allowed,
			'label'      => 'site',
		);
	}

	/**
	 * Get the Elementor kit's custom_css setting.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_kit_css( $request ) {
		$this->log_activity( 'get_kit_css', $request );

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $this->error_response( 'elementor_not_active', __( 'Elementor is not active.', 'mcpwp' ), 400 );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit || ! $kit->get_id() ) {
			return $this->error_response( 'no_kit', __( 'No active Elementor kit found.', 'mcpwp' ), 500 );
		}

		$settings   = get_post_meta( $kit->get_id(), '_elementor_page_settings', true );
		$custom_css = is_array( $settings ) && isset( $settings['custom_css'] ) ? $settings['custom_css'] : '';

		return $this->success_response(
			array(
				'kit_id'     => $kit->get_id(),
				'custom_css' => $custom_css,
				'length'     => strlen( $custom_css ),
			)
		);
	}

	/**
	 * Set the Elementor kit's custom_css setting.
	 *
	 * Works on any Elementor install — does not require Elementor Pro.
	 * Replaces or appends to the kit's global CSS.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function set_kit_css( $request ) {
		$this->log_activity( 'set_kit_css', $request );

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $this->error_response( 'elementor_not_active', __( 'Elementor is not active.', 'mcpwp' ), 400 );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit || ! $kit->get_id() ) {
			return $this->error_response( 'no_kit', __( 'No active Elementor kit found.', 'mcpwp' ), 500 );
		}

		$kit_id  = $kit->get_id();
		$new_css = (string) $request->get_param( 'css' );
		$mode    = (string) ( $request->get_param( 'mode' ) ?: 'replace' );

		$settings = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$existing_css = isset( $settings['custom_css'] ) ? $settings['custom_css'] : '';

		if ( 'append' === $mode ) {
			$settings['custom_css'] = $existing_css . "\n\n" . $new_css;
		} else {
			$settings['custom_css'] = $new_css;
		}

		$breakout = $this->kit_css_breakout_refusal( $settings['custom_css'] );
		if ( null !== $breakout ) {
			return $this->error_response_from_wp_error( $breakout );
		}

		// Raw Kit CSS is a privilege (#829 review round 5): WordPress core
		// requires `edit_css` (`unfiltered_html`) for custom CSS, so a bare
		// write-scoped key does not get it. An admin-scoped key, or a user who
		// holds `unfiltered_html`, stores the CSS as sent — in append mode the
		// existing stylesheet rides in this same string, and sanitizing it
		// would escape every `>` of CSS the caller never touched. Anyone else's
		// CSS goes through the chokepoint's sanitizer like any other setting.
		// Either way: no `</style` ever (the refusal above).
		$raw     = $this->caller_may_store_raw_kit_css();
		$written = Mcpwp_Elementor_Meta_Write::update( $kit_id, '_elementor_page_settings', $settings, $raw ? array( 'raw_paths' => array( 'custom_css' ) ) : array() );
		if ( is_wp_error( $written ) ) {
			return $this->error_response_from_wp_error( $written );
		}
		if ( ! $raw ) {
			$stored = get_post_meta( $kit_id, '_elementor_page_settings', true );
			$settings['custom_css'] = is_array( $stored ) && isset( $stored['custom_css'] ) ? (string) $stored['custom_css'] : $settings['custom_css'];
		}

		// Clear Elementor CSS cache so the new kit CSS is regenerated.
		if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return $this->success_response(
			array(
				'kit_id'     => $kit_id,
				'custom_css' => $settings['custom_css'],
				'length'     => strlen( $settings['custom_css'] ),
				'mode'       => $mode,
			)
		);
	}

	/**
	 * Marker pattern for named, hash-tagged Kit CSS blocks (#641).
	 *
	 * Blocks live inline inside the same custom_css string that
	 * get_kit_css/set_kit_css already read and write — no new storage, and
	 * any CSS written outside a block (e.g. by the plain replace/append
	 * modes, or by pre-existing sites) is left untouched.
	 *
	 * @var string
	 */
	private $kit_css_block_pattern = '/\/\* mcpwp:block:start name="([a-z0-9][a-z0-9_-]{0,63})" hash="([a-f0-9]{16})" \*\/\n(.*?)\n\/\* mcpwp:block:end name="\1" \*\//s';

	/**
	 * Parse all named blocks out of a Kit CSS string.
	 *
	 * @param string $css Full Kit custom_css value.
	 * @return array<string,array{name:string,hash:string,css:string,raw:string}> Keyed by block name.
	 */
	private function parse_kit_css_blocks( $css ) {
		$blocks = array();
		if ( ! is_string( $css ) || '' === $css ) {
			return $blocks;
		}
		if ( preg_match_all( $this->kit_css_block_pattern, $css, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$blocks[ $match[1] ] = array(
					'name' => $match[1],
					'hash' => $match[2],
					'css'  => $match[3],
					'raw'  => $match[0],
				);
			}
		}
		return $blocks;
	}

	/**
	 * Render one named block's marker-delimited text.
	 *
	 * @param string $name Block name.
	 * @param string $css  Block CSS body.
	 * @param string $hash Content hash.
	 * @return string
	 */
	private function render_kit_css_block( $name, $css, $hash ) {
		return sprintf(
			"/* mcpwp:block:start name=\"%s\" hash=\"%s\" */\n%s\n/* mcpwp:block:end name=\"%s\" */",
			$name,
			$hash,
			rtrim( $css ),
			$name
		);
	}

	/**
	 * Whether a parsed block's stored body actually hashes to the hash
	 * recorded in its own marker (#762).
	 *
	 * A block written before 3.10.4 on a host that HTML-escapes meta on write
	 * can hold corrupted bytes while its marker still records the hash of the
	 * original, correct CSS — the hash that was true when the block was
	 * written, not the hash of what is actually stored now. That mismatch is
	 * exact and cheap to detect: re-hash the stored body and compare.
	 *
	 * @param array{name:string,hash:string,css:string,raw:string} $block Parsed block.
	 * @return bool True when the stored body does not match its own marker hash.
	 */
	private function kit_css_block_hash_mismatch( $block ) {
		return substr( hash( 'sha256', $block['css'] ), 0, 16 ) !== $block['hash'];
	}

	/**
	 * Get the current Kit custom_css value and its kit ID.
	 *
	 * @return array{kit_id:int,css:string}|WP_Error
	 */
	private function get_kit_css_raw() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error( 'elementor_not_active', __( 'Elementor is not active.', 'mcpwp' ), array( 'status' => 400 ) );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit || ! $kit->get_id() ) {
			return new WP_Error( 'no_kit', __( 'No active Elementor kit found.', 'mcpwp' ), array( 'status' => 500 ) );
		}

		$settings = get_post_meta( $kit->get_id(), '_elementor_page_settings', true );
		$css      = is_array( $settings ) && isset( $settings['custom_css'] ) ? $settings['custom_css'] : '';

		return array(
			'kit_id' => $kit->get_id(),
			'css'    => $css,
		);
	}

	/**
	 * Persist a full Kit custom_css value and clear the CSS cache.
	 *
	 * @param int    $kit_id Kit post ID.
	 * @param string $css    Full custom_css value to store.
	 * @return array{verified:bool,repaired:bool,altered_chars:string[]}|WP_Error
	 *         WP_Error (400) when the CSS could end its <style> element; nothing written.
	 */
	private function set_kit_css_raw( $kit_id, $css ) {
		$breakout = $this->kit_css_breakout_refusal( $css );
		if ( null !== $breakout ) {
			return $breakout;
		}

		// Named blocks are stored byte for byte and verified (and, on a
		// mismatch, repaired straight in the database) against those bytes, so
		// they cannot run through a sanitizer: raw or not at all (#829).
		if ( ! $this->caller_may_store_raw_kit_css() ) {
			return new WP_Error(
				'kit_css_raw_not_permitted',
				__( 'Named Kit CSS blocks are stored exactly as sent, which needs an admin-scoped API key or a user with the unfiltered_html capability (WordPress requires it for custom CSS). Use an admin-scoped key, or set the Kit CSS with wp_set_kit_css, which sanitizes it.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		$settings               = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$settings               = is_array( $settings ) ? $settings : array();
		$settings['custom_css'] = $css;

		// Stored as sent, past Elementor's REST meta sanitizer (#829): that
		// sanitizer escaped `>` on every API write, so the repair below fired —
		// and reported storage_repaired — on every write. custom_css is the one
		// raw path; every other setting follows the caller's policy.
		$written = Mcpwp_Elementor_Meta_Write::update( $kit_id, '_elementor_page_settings', $settings, array( 'raw_paths' => array( 'custom_css' ), 'defer_readback' => true ) );
		if ( is_wp_error( $written ) ) {
			return $written;
		}

		// Verify the exact bytes landed, and repair them if they did not.
		//
		// CSS is not HTML. A child combinator (".a>.b") is ordinary, valid CSS,
		// but a meta sanitizer can store it HTML-escaped as ".a&gt;.b". That is
		// silent data corruption, not a safety control: the escaped text is no
		// longer the stylesheet the caller wrote, the selector stops matching,
		// and the stored block no longer hashes to the hash recorded in its own
		// marker — so a later idempotent upsert reports "skipped" over content
		// that is already broken.
		//
		// The layer that did it on every API write was Elementor 4.2+'s own
		// REST meta sanitizer (#829); the write above goes past it, so on a
		// host with nothing else in the way the first write is exact and this
		// check finds nothing. It stays for anything else that alters the row
		// — another plugin's sanitizer, a host filter — and only a byte
		// mismatch is repaired, by writing the row directly. Escaping is never
		// reversed blindly: a caller may legitimately write a literal "&gt;".
		$repaired = false;
		$altered  = array();
		$stored   = $this->read_kit_css_from_db( $kit_id );

		if ( null !== $stored && $stored !== $css ) {
			global $wpdb;

			$altered = $this->escaped_characters( $css, $stored );

			$settings['custom_css'] = $css;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->postmeta,
				array( 'meta_value' => maybe_serialize( $settings ) ),
				array(
					'post_id'  => $kit_id,
					'meta_key' => '_elementor_page_settings',
				)
			);

			wp_cache_delete( $kit_id, 'post_meta' );
			clean_post_cache( $kit_id );

			$stored   = $this->read_kit_css_from_db( $kit_id );
			$repaired = true;
		}

		if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		return array(
			'verified'      => ( null !== $stored && $stored === $css && $this->read_kit_settings_from_db( $kit_id ) === $settings ),
			'repaired'      => $repaired,
			'altered_chars' => $altered,
		);
	}

	/**
	 * Which characters a write stored as HTML entities that the caller did not
	 * send as entities — so a repair can say what was altered.
	 *
	 * @param string $intended Bytes the caller sent.
	 * @param string $stored   Bytes the row held.
	 * @return string[] Characters, e.g. [">"]; empty when the difference was not escaping.
	 */
	/**
	 * Whether this caller may store Kit CSS exactly as sent: an admin-scoped
	 * key, or a user who holds `unfiltered_html` in their own right.
	 *
	 * @return bool
	 */
	private function caller_may_store_raw_kit_css() {
		$record = $this->get_current_api_key_record();
		if ( is_array( $record ) && $this->key_has_scope( $record, 'admin' ) ) {
			return true;
		}

		return function_exists( 'current_user_can' ) && current_user_can( 'unfiltered_html' );
	}

	/**
	 * Refuse Kit CSS that could end the <style> element it is printed in.
	 *
	 * The Kit's custom_css is stored as sent (#762/#829) and Elementor prints
	 * it inside a <style> element. Checked on the exact text that is stored —
	 * Mcpwp_Elementor_Meta_Write::update() slashes for update_post_meta(), so
	 * nothing is unslashed after this check — and canonicalised by
	 * Mcpwp_Elementor_Meta_Write::css_breaks_out().
	 *
	 * @param string $css Kit CSS as it would be stored.
	 * @return WP_Error|null
	 */
	private function kit_css_breakout_refusal( $css ) {
		if ( ! Mcpwp_Elementor_Meta_Write::css_breaks_out( (string) $css ) ) {
			return null;
		}

		return new WP_Error(
			'kit_css_style_breakout',
			__( 'Kit CSS must not contain "</style" (in any letter case, spacing, or with backslashes such as <\\/style): the stylesheet is printed inside a <style> element, and that text could end it and let what follows run as HTML. Nothing was saved. Remove it and send the CSS again.', 'mcpwp' ),
			array( 'status' => 400 )
		);
	}

	private function escaped_characters( $intended, $stored ) {
		$entities = array(
			'>' => '&gt;',
			'<' => '&lt;',
			'&' => '&amp;',
			'"' => '&quot;',
			"'" => '&#039;',
		);

		$chars = array();
		foreach ( $entities as $char => $entity ) {
			if ( substr_count( $stored, $entity ) > substr_count( $intended, $entity ) ) {
				$chars[] = $char;
			}
		}

		return $chars;
	}

	/**
	 * Read the Kit's complete page settings straight from the postmeta row.
	 *
	 * Deliberately bypasses get_post_meta(): a persistent object cache can be
	 * populated optimistically with the value we asked to write, independent of
	 * what the underlying row actually holds, which would make the round-trip
	 * check above agree with itself and prove nothing.
	 *
	 * @param int $kit_id Kit post ID.
	 * @return array|null Stored settings, or null when the row cannot be read.
	 */
	private function read_kit_settings_from_db( $kit_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$raw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_page_settings' ORDER BY meta_id DESC LIMIT 1",
				$kit_id
			)
		);

		if ( null === $raw ) {
			return null;
		}

		$settings = maybe_unserialize( $raw );
		return is_array( $settings ) ? $settings : null;
	}

	/**
	 * @param int $kit_id Kit post ID.
	 * @return string|null Stored CSS, or null when the row cannot be read.
	 */
	private function read_kit_css_from_db( $kit_id ) {
		$settings = $this->read_kit_settings_from_db( $kit_id );

		return is_array( $settings ) && isset( $settings['custom_css'] ) ? (string) $settings['custom_css'] : null;
	}

	/**
	 * Validate a block name against the marker pattern's allowed charset.
	 *
	 * @param string $name Candidate block name.
	 * @return bool
	 */
	private function is_valid_kit_css_block_name( $name ) {
		return is_string( $name ) && (bool) preg_match( '/^[a-z0-9][a-z0-9_-]{0,63}$/', $name );
	}

	/**
	 * List all named Kit CSS blocks (name, hash, byte count — not the CSS body).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function list_kit_css_blocks( $request ) {
		$this->log_activity( 'list_kit_css_blocks', $request );

		$raw = $this->get_kit_css_raw();
		if ( is_wp_error( $raw ) ) {
			return $this->error_response( $raw->get_error_code(), $raw->get_error_message(), $raw->get_error_data()['status'] ?? 400 );
		}

		$blocks = array_values( array_map(
			array( $this, 'describe_kit_css_block_for_list' ),
			$this->parse_kit_css_blocks( $raw['css'] )
		) );

		return $this->success_response(
			array(
				'blocks' => $blocks,
				'total'  => count( $blocks ),
			)
		);
	}

	/**
	 * Build one block's summary row for list_kit_css_blocks(), including the
	 * hash_mismatch flag (#762).
	 *
	 * @param array{name:string,hash:string,css:string,raw:string} $block Parsed block.
	 * @return array{name:string,hash:string,bytes:int,hash_mismatch:bool}
	 */
	private function describe_kit_css_block_for_list( $block ) {
		return array(
			'name'          => $block['name'],
			'hash'          => $block['hash'],
			'bytes'         => strlen( $block['css'] ),
			'hash_mismatch' => $this->kit_css_block_hash_mismatch( $block ),
		);
	}

	/**
	 * Get one named Kit CSS block's full CSS body.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_kit_css_block( $request ) {
		$this->log_activity( 'get_kit_css_block', $request );

		$name = (string) $request->get_param( 'name' );
		$raw  = $this->get_kit_css_raw();
		if ( is_wp_error( $raw ) ) {
			return $this->error_response( $raw->get_error_code(), $raw->get_error_message(), $raw->get_error_data()['status'] ?? 400 );
		}

		$blocks = $this->parse_kit_css_blocks( $raw['css'] );
		if ( ! isset( $blocks[ $name ] ) ) {
			return $this->error_response( 'block_not_found', sprintf( __( 'No Kit CSS block named "%s".', 'mcpwp' ), $name ), 404 );
		}

		return $this->success_response(
			array(
				'name'  => $name,
				'hash'  => $blocks[ $name ]['hash'],
				'css'   => $blocks[ $name ]['css'],
				'bytes' => strlen( $blocks[ $name ]['css'] ),
			)
		);
	}

	/**
	 * Create or update a named Kit CSS block idempotently.
	 *
	 * Re-upserting identical CSS is a no-op (skipped) rather than growing the
	 * kit — this is the gap #641 reports: append-only editing tripled the
	 * kit's CSS on repeated build passes. Updating one block never touches
	 * any other block or any unmanaged CSS elsewhere in the kit.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function upsert_kit_css_block( $request ) {
		$this->log_activity( 'upsert_kit_css_block', $request );

		$name = (string) $request->get_param( 'name' );
		if ( ! $this->is_valid_kit_css_block_name( $name ) ) {
			return $this->error_response(
				'invalid_name',
				__( 'Block name must be 1-64 lowercase letters, digits, hyphens, or underscores, starting with a letter or digit.', 'mcpwp' ),
				400
			);
		}

		$css = (string) $request->get_param( 'css' );
		if ( false !== strpos( $css, 'mcpwp:block:' ) ) {
			return $this->error_response(
				'reserved_marker_in_css',
				__( 'CSS must not contain the literal text "mcpwp:block:" — that marker is reserved for block boundaries.', 'mcpwp' ),
				400
			);
		}

		$breakout = $this->kit_css_breakout_refusal( $css );
		if ( null !== $breakout ) {
			return $this->error_response_from_wp_error( $breakout );
		}

		$expected_hash = $request->get_param( 'expected_hash' );

		$raw = $this->get_kit_css_raw();
		if ( is_wp_error( $raw ) ) {
			return $this->error_response( $raw->get_error_code(), $raw->get_error_message(), $raw->get_error_data()['status'] ?? 400 );
		}

		$blocks   = $this->parse_kit_css_blocks( $raw['css'] );
		$existing = isset( $blocks[ $name ] ) ? $blocks[ $name ] : null;
		$new_hash = substr( hash( 'sha256', $css ), 0, 16 );

		if ( $existing && $expected_hash && (string) $expected_hash !== $existing['hash'] ) {
			return $this->error_response(
				'hash_conflict',
				sprintf( __( 'Block "%s" hash is %s, not the expected %s — someone else changed it since you last read it.', 'mcpwp' ), $name, $existing['hash'], $expected_hash ),
				409
			);
		}

		// A block whose stored body does not hash to its own marker is corrupt
		// (#762) — most likely a pre-3.10.4 write that an escaping host
		// mangled after the correct hash was recorded. Skipping it because the
		// incoming hash "matches" would compare against a hash that describes
		// bytes which are no longer what is stored, silently preserving the
		// corruption forever. Fall through and rewrite instead.
		$existing_corrupt = null !== $existing && $this->kit_css_block_hash_mismatch( $existing );

		if ( $existing && $existing['hash'] === $new_hash && ! $existing_corrupt ) {
			return $this->success_response(
				array(
					'name'    => $name,
					'action'  => 'skipped',
					'old_hash' => $existing['hash'],
					'new_hash' => $new_hash,
					'bytes'   => strlen( $css ),
				)
			);
		}

		$new_block_raw = $this->render_kit_css_block( $name, $css, $new_hash );

		if ( $existing ) {
			$updated_css = str_replace( $existing['raw'], $new_block_raw, $raw['css'] );
			$action      = 'updated';
		} else {
			$updated_css = '' === trim( $raw['css'] ) ? $new_block_raw : rtrim( $raw['css'] ) . "\n\n" . $new_block_raw;
			$action      = 'created';
		}

		$write = $this->set_kit_css_raw( $raw['kit_id'], $updated_css );

		if ( is_wp_error( $write ) ) {
			return $this->error_response_from_wp_error( $write );
		}

		if ( ! $write['verified'] ) {
			return $this->error_response(
				'kit_css_write_unverified',
				__( 'The Kit CSS was written but did not read back byte-for-byte, so the stored stylesheet cannot be trusted. Nothing was reported as saved.', 'mcpwp' ),
				500
			);
		}

		$response = array(
			'name'            => $name,
			'action'          => $action,
			'old_hash'        => $existing ? $existing['hash'] : null,
			'new_hash'        => $new_hash,
			'bytes'           => strlen( $css ),
			'total_kit_bytes' => strlen( $updated_css ),
			'verified'        => true,
		);

		if ( $write['repaired'] ) {
			// Only when bytes really differed (#829): the flag used to be on for
			// every write, because Elementor's own sanitizer escaped each one,
			// so it carried no information. Now it means something else on this
			// site altered the stored CSS, and says what.
			$response['storage_repaired']      = true;
			$response['storage_altered_chars'] = $write['altered_chars'];
			$response['storage_note']          = empty( $write['altered_chars'] )
				? 'The first write did not store the bytes you sent (something on this site altered them on the way into the database) and was rewritten directly so the stored CSS matches what you sent.'
				: sprintf( 'The first write stored %s as HTML entities (a meta sanitizer or host filter on this site escaped them) and was rewritten directly so the stored CSS matches what you sent.', implode( ' ', $write['altered_chars'] ) );
		}

		if ( $existing_corrupt ) {
			// Distinguish this from an ordinary content update: the incoming
			// content hash may equal the block's own marker hash, which is
			// exactly the case that used to return "skipped" over already-
			// broken CSS (#762). A silent repair and a genuine no-op must not
			// look identical to the caller.
			$response['corrupt_block_rewritten'] = true;
			$response['corrupt_block_note']      = 'The existing block\'s stored bytes did not hash to its own marker (pre-3.10.4 corruption) — it was rewritten rather than left in place, even though the incoming content hash matched the marker.';
		}

		return $this->success_response( $response );
	}

	/**
	 * Delete a named Kit CSS block, leaving all other CSS untouched.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_kit_css_block( $request ) {
		$this->log_activity( 'delete_kit_css_block', $request );

		$name = (string) $request->get_param( 'name' );
		$raw  = $this->get_kit_css_raw();
		if ( is_wp_error( $raw ) ) {
			return $this->error_response( $raw->get_error_code(), $raw->get_error_message(), $raw->get_error_data()['status'] ?? 400 );
		}

		$blocks = $this->parse_kit_css_blocks( $raw['css'] );
		if ( ! isset( $blocks[ $name ] ) ) {
			return $this->error_response( 'block_not_found', sprintf( __( 'No Kit CSS block named "%s".', 'mcpwp' ), $name ), 404 );
		}

		// Remove the block and one adjoining blank-line gap it was separated by.
		$updated_css = preg_replace(
			'/\n*' . preg_quote( $blocks[ $name ]['raw'], '/' ) . '\n*/',
			"\n",
			$raw['css'],
			1
		);
		$updated_css = trim( (string) $updated_css ) . ( '' !== trim( (string) $updated_css ) ? "\n" : '' );

		$write = $this->set_kit_css_raw( $raw['kit_id'], $updated_css );
		if ( is_wp_error( $write ) ) {
			return $this->error_response_from_wp_error( $write );
		}
		if ( ! $write['verified'] ) {
			return $this->error_response( 'kit_css_write_unverified', __( 'The Kit CSS did not read back byte-for-byte, so the deletion was not confirmed.', 'mcpwp' ), 500 );
		}

		return $this->success_response(
			array(
				'name'            => $name,
				'deleted'         => true,
				'old_hash'        => $blocks[ $name ]['hash'],
				'total_kit_bytes' => strlen( $updated_css ),
			)
		);
	}

	/**
	 * Recursively replace a string in all string values of a decoded JSON structure.
	 *
	 * @param string $search  Search string.
	 * @param string $replace Replacement string.
	 * @param mixed  $data    Decoded JSON value (array, string, scalar).
	 * @param int    $count   Running replacement count (passed by reference).
	 * @return mixed Updated structure.
	 */
	private function recursive_str_replace( $search, $replace, $data, &$count ) {
		// Structural Elementor keys whose string values are identifiers, not user
		// content. Replacing inside them silently breaks rendering — e.g. searching
		// "section" would clobber every "elType":"section". Never descend into these.
		static $protected_keys = array( 'id', 'elType', 'widgetType', 'isInner', 'structure' );

		if ( is_string( $data ) ) {
			$new_data = str_replace( $search, $replace, $data, $n );
			$count   += $n;
			return $new_data;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( is_string( $key ) && in_array( $key, $protected_keys, true ) ) {
					continue; // Leave structural identifiers untouched.
				}
				$data[ $key ] = $this->recursive_str_replace( $search, $replace, $value, $count );
			}
		}
		return $data;
	}
}
