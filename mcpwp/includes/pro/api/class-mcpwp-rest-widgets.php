<?php
/**
 * Widgets REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for widget and sidebar management.
 */
class Mcpwp_REST_Widgets extends Mcpwp_REST_API {

	/**
	 * Widgets handler.
	 *
	 * @var Mcpwp_Widgets
	 */
	private $widgets;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Widgets $widgets Widgets handler.
	 */
	public function __construct( $widgets ) {
		$this->widgets = $widgets;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Sidebars list.
		register_rest_route(
			$this->namespace,
			'/sidebars',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sidebars' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Widget types.
		register_rest_route(
			$this->namespace,
			'/widgets/types',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_widget_types' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Single sidebar.
		register_rest_route(
			$this->namespace,
			'/sidebars/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_sidebar' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Sidebar widgets.
		register_rest_route(
			$this->namespace,
			'/sidebars/(?P<id>[a-zA-Z0-9_-]+)/widgets',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_sidebar_widgets' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_widget' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Reorder widgets in sidebar.
		register_rest_route(
			$this->namespace,
			'/sidebars/(?P<id>[a-zA-Z0-9_-]+)/reorder',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reorder_widgets' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Single widget.
		register_rest_route(
			$this->namespace,
			'/widgets/(?P<id>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_widget' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_widget' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_widget' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Move widget.
		register_rest_route(
			$this->namespace,
			'/widgets/(?P<id>[a-zA-Z0-9_-]+)/move',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'move_widget' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Get all sidebars.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_sidebars( $request ) {
		$sidebars = $this->widgets->get_sidebars();

		$this->log_activity( 'get_sidebars', $request, array( 'count' => count( $sidebars ) ) );

		return $this->success_response( array(
			'sidebars' => $sidebars,
			'total'    => count( $sidebars ),
		) );
	}

	/**
	 * Get single sidebar.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_sidebar( $request ) {
		$sidebar_id = sanitize_text_field( $request->get_param( 'id' ) );
		$sidebar    = $this->widgets->get_sidebar( $sidebar_id );

		if ( is_wp_error( $sidebar ) ) {
			return $this->error_response( $sidebar->get_error_code(), $sidebar->get_error_message(), 404 );
		}

		$this->log_activity( 'get_sidebar', $request, array( 'sidebar_id' => $sidebar_id ) );

		return $this->success_response( $sidebar );
	}

	/**
	 * Get widgets in a sidebar.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_sidebar_widgets( $request ) {
		$sidebar_id = sanitize_text_field( $request->get_param( 'id' ) );
		$widgets    = $this->widgets->get_sidebar_widgets( $sidebar_id );

		$this->log_activity( 'get_sidebar_widgets', $request, array(
			'sidebar_id' => $sidebar_id,
			'count'      => count( $widgets ),
		) );

		return $this->success_response( array(
			'sidebar_id' => $sidebar_id,
			'widgets'    => $widgets,
			'total'      => count( $widgets ),
		) );
	}

	/**
	 * Get all widget types.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_widget_types( $request ) {
		$types = $this->widgets->get_widget_types();

		$this->log_activity( 'get_widget_types', $request, array( 'count' => count( $types ) ) );

		return $this->success_response( array(
			'types' => $types,
			'total' => count( $types ),
		) );
	}

	/**
	 * Get single widget.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_widget( $request ) {
		$widget_id = sanitize_text_field( $request->get_param( 'id' ) );
		$widget    = $this->widgets->get_widget( $widget_id );

		if ( is_wp_error( $widget ) ) {
			return $this->error_response( $widget->get_error_code(), $widget->get_error_message(), 404 );
		}

		$this->log_activity( 'get_widget', $request, array( 'widget_id' => $widget_id ) );

		return $this->success_response( $widget );
	}

	/**
	 * Add widget to sidebar.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function add_widget( $request ) {
		$sidebar_id  = sanitize_text_field( $request->get_param( 'id' ) );
		$widget_type = sanitize_text_field( $request->get_param( 'type' ) );
		$settings    = $request->get_param( 'settings' );
		$position    = $request->get_param( 'position' );

		if ( empty( $widget_type ) ) {
			return $this->error_response(
				'missing_type',
				__( 'Widget type is required.', 'mcpwp' ),
				400
			);
		}

		// Ensure settings is an array.
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$widget = $this->widgets->add_widget( $sidebar_id, $widget_type, $settings, $position );

		if ( is_wp_error( $widget ) ) {
			return $this->error_response( $widget->get_error_code(), $widget->get_error_message(), 400 );
		}

		$this->log_activity( 'add_widget', $request, array(
			'sidebar_id'  => $sidebar_id,
			'widget_type' => $widget_type,
			'widget_id'   => $widget['id'],
		), 201 );

		return $this->success_response( $widget, 201 );
	}

	/**
	 * Update widget settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_widget( $request ) {
		$widget_id = sanitize_text_field( $request->get_param( 'id' ) );
		$settings  = $request->get_param( 'settings' );

		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return $this->error_response(
				'missing_settings',
				__( 'Settings are required.', 'mcpwp' ),
				400
			);
		}

		$widget = $this->widgets->update_widget( $widget_id, $settings );

		if ( is_wp_error( $widget ) ) {
			return $this->error_response( $widget->get_error_code(), $widget->get_error_message(), 400 );
		}

		$this->log_activity( 'update_widget', $request, array( 'widget_id' => $widget_id ) );

		return $this->success_response( $widget );
	}

	/**
	 * Delete widget.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_widget( $request ) {
		$widget_id = sanitize_text_field( $request->get_param( 'id' ) );

		// A widget's settings are gone with it: approval by default (#536 round 2).
		if ( class_exists( 'Mcpwp_Destructive_Gate' ) && Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ) {
			// Resolve the target first, like every other gated site: a
			// reviewer must never be handed a no-op to approve (#536 round 8).
			$target = Mcpwp_Destructive_Gate::fingerprint_widget( $widget_id );
			if ( empty( $target['exists'] ) && empty( $target['unparseable'] ) ) {
				return $this->error_response( 'not_found', __( 'Widget not found.', 'mcpwp' ), 404, array( 'id' => $widget_id ) );
			}
			return $this->defer_destructive( 'delete_widget', $request, array( 'type' => 'widget', 'id' => $widget_id, 'title' => $widget_id ), sprintf( 'Permanently delete widget %s', $widget_id ) );
		}

		$result = $this->widgets->delete_widget( $widget_id );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'delete_widget', $request, array( 'widget_id' => $widget_id ) );

		return $this->success_response( array(
			'deleted' => true,
			'id'      => $widget_id,
		) );
	}

	/**
	 * Move widget to different sidebar.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function move_widget( $request ) {
		$widget_id  = sanitize_text_field( $request->get_param( 'id' ) );
		$sidebar_id = sanitize_text_field( $request->get_param( 'sidebar' ) );
		$position   = $request->get_param( 'position' );

		if ( empty( $sidebar_id ) ) {
			return $this->error_response(
				'missing_sidebar',
				__( 'Target sidebar is required.', 'mcpwp' ),
				400
			);
		}

		$widget = $this->widgets->move_widget( $widget_id, $sidebar_id, $position );

		if ( is_wp_error( $widget ) ) {
			return $this->error_response( $widget->get_error_code(), $widget->get_error_message(), 400 );
		}

		$this->log_activity( 'move_widget', $request, array(
			'widget_id'  => $widget_id,
			'sidebar_id' => $sidebar_id,
		) );

		return $this->success_response( $widget );
	}

	/**
	 * Reorder widgets in sidebar.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function reorder_widgets( $request ) {
		$sidebar_id = sanitize_text_field( $request->get_param( 'id' ) );
		$widget_ids = $request->get_param( 'widgets' );

		if ( ! is_array( $widget_ids ) || empty( $widget_ids ) ) {
			return $this->error_response(
				'missing_widgets',
				__( 'Widget IDs array is required.', 'mcpwp' ),
				400
			);
		}

		// Sanitize widget IDs.
		$widget_ids = array_map( 'sanitize_text_field', $widget_ids );

		$sidebar = $this->widgets->reorder_widgets( $sidebar_id, $widget_ids );

		if ( is_wp_error( $sidebar ) ) {
			return $this->error_response( $sidebar->get_error_code(), $sidebar->get_error_message(), 400 );
		}

		$this->log_activity( 'reorder_widgets', $request, array(
			'sidebar_id' => $sidebar_id,
			'count'      => count( $widget_ids ),
		) );

		return $this->success_response( $sidebar );
	}
}
