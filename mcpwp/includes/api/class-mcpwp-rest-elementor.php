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
							'description' => __( 'Array of patch operations: {op, element_id, element, position, settings, delete_settings}.', 'mcpwp' ),
							'required'    => true,
							'type'        => 'array',
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
							'description' => __( 'Elementor widget element ID (8-char alphanumeric).', 'mcpwp' ),
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

		// Widget schema — list all widget types.
		register_rest_route(
			$this->namespace,
			'/elementor/widgets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_widget_schema' ),
					'permission_callback' => array( $this, 'check_permission' ),
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
		$denied = $this->guard_non_public_post(
			get_post( absint( $page_id ) ),
			__( 'Post not found or unsupported type.', 'mcpwp' )
		);
		if ( $denied ) {
			return $denied;
		}

		$options = array(
			'strip_defaults' => (bool) $request->get_param( 'strip_defaults' ),
			'include_raw'    => (bool) $request->get_param( 'include_raw' ),
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

		// Non-public gate for the batch: a read-only key must not receive even a
		// single draft/pending/private body. Drop the ids the caller can't see
		// and record them in the errors map with the same message a genuinely
		// missing id gets, so a hidden draft is indistinguishable from absent.
		$blocked = array();
		if ( ! $this->requester_can_see_non_public() ) {
			$visible = array();
			foreach ( $page_ids as $pid ) {
				$post = get_post( absint( $pid ) );
				if ( $post instanceof WP_Post && ! $this->is_public_status( $post->post_status ) ) {
					$blocked[ (int) $pid ] = __( 'Post not found or unsupported type.', 'mcpwp' );
				} else {
					$visible[] = $pid;
				}
			}
			$page_ids = $visible;
		}

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
		$denied = $this->guard_non_public_post(
			get_post( absint( $page_id ) ),
			__( 'Post not found or unsupported type.', 'mcpwp' )
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
		$denied = $this->guard_non_public_post(
			get_post( absint( $page_id ) ),
			__( 'Post not found or unsupported type.', 'mcpwp' )
		);
		if ( $denied ) {
			return $denied;
		}

		$format = $request->get_param( 'format' ) ? (string) $request->get_param( 'format' ) : 'summary';
		$result = $this->elementor->get_rendered_content( $page_id, $format );

		if ( is_wp_error( $result ) ) {
			return $result;
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

		$widget_type = $request->get_param( 'type' );
		$result      = $this->elementor->get_widget_schema( $widget_type ? (string) $widget_type : '' );

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
		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->error_response(
				'invalid_json',
				__( 'Could not decode existing Elementor data. The stored data may be corrupt.', 'mcpwp' ),
				500
			);
		}

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

		// Save updated data.
		update_post_meta( $page_id, '_elementor_data', wp_slash( $updated ) );

		// Clear Elementor CSS cache for this post.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$post_css = \Elementor\Core\Files\CSS\Post::create( $page_id );
			if ( $post_css ) {
				$post_css->delete();
			}
		}

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

		update_post_meta( $kit_id, '_elementor_page_settings', $settings );

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
	 * @return void
	 */
	private function set_kit_css_raw( $kit_id, $css ) {
		$settings               = get_post_meta( $kit_id, '_elementor_page_settings', true );
		$settings               = is_array( $settings ) ? $settings : array();
		$settings['custom_css'] = $css;
		update_post_meta( $kit_id, '_elementor_page_settings', $settings );

		// Verify the exact bytes landed, and repair them if they did not.
		//
		// CSS is not HTML. A child combinator (".a>.b") is ordinary, valid CSS,
		// but somewhere in WordPress's meta write path it can be stored
		// HTML-escaped as ".a&gt;.b" — reproduced on WP 7.0.2 / Elementor 4.2.4
		// for a request whose user lacks unfiltered_html, which is every
		// API-key request. That is silent data corruption, not a safety
		// control: the escaped text is no longer the stylesheet the caller
		// wrote, the selector stops matching, and the stored block no longer
		// hashes to the hash recorded in its own marker — so a later idempotent
		// upsert reports "skipped" over content that is already broken.
		//
		// Escaping is not reversed blindly here: a caller may legitimately
		// write a literal "&gt;" and that must survive untouched. Instead the
		// exact bytes we intended are compared against what the row actually
		// holds, and only a mismatch is repaired, by writing the row directly.
		$repaired = false;
		$stored   = $this->read_kit_css_from_db( $kit_id );

		if ( null !== $stored && $stored !== $css ) {
			global $wpdb;

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
			'verified' => ( null !== $stored && $stored === $css ),
			'repaired' => $repaired,
		);
	}

	/**
	 * Read the Kit's custom_css straight from the postmeta row.
	 *
	 * Deliberately bypasses get_post_meta(): a persistent object cache can be
	 * populated optimistically with the value we asked to write, independent of
	 * what the underlying row actually holds, which would make the round-trip
	 * check above agree with itself and prove nothing.
	 *
	 * @param int $kit_id Kit post ID.
	 * @return string|null Stored CSS, or null when the row cannot be read.
	 */
	private function read_kit_css_from_db( $kit_id ) {
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
			function ( $block ) {
				return array(
					'name'  => $block['name'],
					'hash'  => $block['hash'],
					'bytes' => strlen( $block['css'] ),
				);
			},
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

		if ( $existing && $existing['hash'] === $new_hash ) {
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
			// Surfaced rather than swallowed: the host mangled the first write,
			// which is worth knowing even though the stored bytes are now correct.
			$response['storage_repaired'] = true;
			$response['storage_note']     = 'The first write was altered by the host (HTML-escaped) and was rewritten directly so the stored CSS matches what you sent.';
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

		$this->set_kit_css_raw( $raw['kit_id'], $updated_css );

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
