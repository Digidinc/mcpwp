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
							'description' => __( 'Comma-separated page IDs (max 25).', 'mumcp' ),
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
							'description' => __( 'Strip default widget settings to reduce payload size.', 'mumcp' ),
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
							'description' => __( 'Elementor data (JSON array or object).', 'mumcp' ),
							'type'        => array( 'string', 'array' ),
						),
						'elementor_json' => array(
							'description' => __( 'Elementor data as JSON string.', 'mumcp' ),
							'type'        => 'string',
						),
						'elementor_data_base64' => array(
							'description' => __( 'Base64-encoded Elementor JSON data. Use instead of elementor_data to avoid quoting/escaping issues with large HTML payloads.', 'mumcp' ),
							'type'        => 'string',
						),
						'dry_run' => array(
							'description' => __( 'If true, validate only — no changes are saved.', 'mumcp' ),
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
							'description' => __( 'Output format: "summary" (text + stats, no HTML — saves tokens), "text" (full text extraction), "html" (full rendered HTML).', 'mumcp' ),
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
							'description' => __( 'Elementor element ID to edit.', 'mumcp' ),
							'type'        => 'string',
						),
						'section_index'   => array(
							'description' => __( 'Top-level section index (0-based).', 'mumcp' ),
							'type'        => 'integer',
						),
						'find'            => array(
							'description' => __( 'Search criteria: {widgetType, "settings.key": value}.', 'mumcp' ),
							'type'        => 'object',
						),
						'settings'        => array(
							'description' => __( 'Settings to merge into the element.', 'mumcp' ),
							'type'        => 'object',
						),
						'delete_settings' => array(
							'description' => __( 'Setting keys to remove from the element.', 'mumcp' ),
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
							'description' => __( 'The new section/container element object.', 'mumcp' ),
							'required'    => true,
							'type'        => 'object',
						),
						'position' => array(
							'description' => __( 'Position: start, end, before:{id}, after:{id}.', 'mumcp' ),
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
							'description' => __( 'The Elementor element ID to remove.', 'mumcp' ),
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
							'description' => __( 'The Elementor element ID to replace.', 'mumcp' ),
							'required'    => true,
							'type'        => 'string',
						),
						'element'    => array(
							'description' => __( 'The replacement section/container element.', 'mumcp' ),
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
							'description' => __( 'Array of patch operations: {op, element_id, element, position, settings, delete_settings}.', 'mumcp' ),
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
							'description' => __( 'Elementor widget element ID (8-char alphanumeric).', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'settings'        => array(
							'description' => __( 'Settings to merge into the widget.', 'mumcp' ),
							'type'        => 'object',
						),
						'delete_settings' => array(
							'description' => __( 'Setting keys to remove from the widget.', 'mumcp' ),
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
							'description' => __( 'Page title.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'status'         => array(
							'description' => __( 'Page status.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
							'default'     => 'draft',
						),
						'elementor_data' => array(
							'description' => __( 'Initial Elementor data.', 'mumcp' ),
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
							'description' => __( 'Text to search for.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
							'minLength'   => 1,
						),
						'replace' => array(
							'description' => __( 'Replacement text.', 'mumcp' ),
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
							'description' => __( 'CSS to write to the Elementor kit (global stylesheet).', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'mode' => array(
							'description' => __( 'append (default) or replace.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( 'append', 'replace' ),
							'default'     => 'replace',
						),
					),
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
							'description' => __( 'Page ID. Omit to regenerate all site CSS.', 'mumcp' ),
							'type'        => 'integer',
						),
						'force' => array(
							'description' => __( 'Delete existing CSS files before regenerating.', 'mumcp' ),
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
			return $this->error_response( 'missing_param', __( 'ids parameter is required.', 'mumcp' ), 400 );
		}

		$page_ids = array_filter( array_map( 'absint', explode( ',', $ids_raw ) ) );

		if ( count( $page_ids ) > 25 ) {
			return $this->error_response( 'too_many_ids', __( 'Maximum 25 page IDs per request.', 'mumcp' ), 400 );
		}

		if ( empty( $page_ids ) ) {
			return $this->error_response( 'invalid_ids', __( 'No valid page IDs provided.', 'mumcp' ), 400 );
		}

		$result = $this->elementor->get_elementor_data_bulk( $page_ids );

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
		$result  = $this->elementor->get_elementor_summary( $page_id );

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
		$format  = $request->get_param( 'format' ) ? (string) $request->get_param( 'format' ) : 'summary';
		$result  = $this->elementor->get_rendered_content( $page_id, $format );

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
				__( 'No Elementor data found for this post.', 'mumcp' ),
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
				__( 'Could not decode existing Elementor data. The stored data may be corrupt.', 'mumcp' ),
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
					'message'      => __( 'Search text not found in Elementor data.', 'mumcp' ),
				)
			);
		}

		$updated = wp_json_encode( $updated_decoded );

		if ( false === $updated ) {
			return $this->error_response(
				'json_encode_failed',
				__( 'Failed to re-encode Elementor data after replacement. Aborting to prevent data loss.', 'mumcp' ),
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
			return $this->error_response( 'elementor_not_active', __( 'Elementor is not active.', 'mumcp' ), 400 );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit || ! $kit->get_id() ) {
			return $this->error_response( 'no_kit', __( 'No active Elementor kit found.', 'mumcp' ), 500 );
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
			return $this->error_response( 'elementor_not_active', __( 'Elementor is not active.', 'mumcp' ), 400 );
		}

		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit || ! $kit->get_id() ) {
			return $this->error_response( 'no_kit', __( 'No active Elementor kit found.', 'mumcp' ), 500 );
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
