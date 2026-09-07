<?php
/**
 * Theme Builder REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for Elementor Theme Builder.
 *
 * Manages theme locations and display conditions.
 */
class Mcpwp_REST_Theme_Builder extends Mcpwp_REST_API {

	/**
	 * Theme Builder handler.
	 *
	 * @var Mcpwp_Theme_Builder
	 */
	private $theme_builder;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Theme_Builder $theme_builder Theme Builder handler.
	 */
	public function __construct( $theme_builder ) {
		$this->theme_builder = $theme_builder;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Status.
		register_rest_route(
			$this->namespace,
			'/theme-builder/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Locations.
		register_rest_route(
			$this->namespace,
			'/theme-builder/locations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_locations' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Available conditions.
		register_rest_route(
			$this->namespace,
			'/theme-builder/conditions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_available_conditions' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Templates list + create.
		register_rest_route(
			$this->namespace,
			'/theme-builder/templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_theme_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Single template.
		register_rest_route(
			$this->namespace,
			'/theme-builder/templates/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Template conditions.
		register_rest_route(
			$this->namespace,
			'/theme-builder/templates/(?P<id>\d+)/conditions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conditions' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'set_conditions' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_conditions' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Assign to location (shortcut).
		register_rest_route(
			$this->namespace,
			'/theme-builder/templates/(?P<id>\d+)/assign',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'assign_to_location' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Get Theme Builder status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_status( $request ) {
		$status = $this->theme_builder->get_status();

		$this->log_activity( 'theme_builder_status', $request, $status );

		return $this->success_response( $status );
	}

	/**
	 * Get all theme locations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_locations( $request ) {
		if ( ! $this->theme_builder->is_available() ) {
			return $this->error_response(
				'not_available',
				__( 'Elementor Pro Theme Builder is not available.', 'mcpwp' ),
				400
			);
		}

		$locations = $this->theme_builder->get_locations();

		$this->log_activity( 'get_theme_locations', $request, array( 'count' => count( $locations ) ) );

		return $this->success_response( array(
			'locations' => $locations,
		) );
	}

	/**
	 * Get available condition options.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_available_conditions( $request ) {
		$conditions = $this->theme_builder->get_available_conditions();

		$this->log_activity( 'get_available_conditions', $request );

		return $this->success_response( $conditions );
	}

	/**
	 * Get Theme Builder templates.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_templates( $request ) {
		if ( ! $this->theme_builder->is_available() ) {
			return $this->error_response(
				'not_available',
				__( 'Elementor Pro Theme Builder is not available.', 'mcpwp' ),
				400
			);
		}

		$args = array(
			'per_page' => $request->get_param( 'per_page' ) ?: 50,
			'type'     => $request->get_param( 'type' ),
		);

		$templates = $this->theme_builder->get_templates( $args );

		$this->log_activity( 'get_theme_builder_templates', $request, array( 'count' => count( $templates ) ) );

		return $this->success_response( array(
			'templates' => $templates,
			'total'     => count( $templates ),
		) );
	}

	/**
	 * Get single template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_template( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$template = $this->theme_builder->get_template( $template_id );

		if ( is_wp_error( $template ) ) {
			return $this->error_response( $template->get_error_code(), $template->get_error_message(), 404 );
		}

		$this->log_activity( 'get_theme_builder_template', $request, array( 'template_id' => $template_id ) );

		return $this->success_response( $template );
	}

	/**
	 * Get template conditions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_conditions( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$conditions = $this->theme_builder->get_template_conditions( $template_id );

		$this->log_activity( 'get_template_conditions', $request, array( 'template_id' => $template_id ) );

		return $this->success_response( array(
			'template_id' => $template_id,
			'conditions'  => $conditions,
		) );
	}

	/**
	 * Set template conditions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function set_conditions( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$conditions = $request->get_param( 'conditions' );

		if ( ! is_array( $conditions ) ) {
			return $this->error_response(
				'invalid_conditions',
				__( 'Conditions must be an array.', 'mcpwp' ),
				400
			);
		}

		$result = $this->theme_builder->set_template_conditions( $template_id, $conditions );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'set_template_conditions', $request, array(
			'template_id' => $template_id,
			'count'       => count( $conditions ),
		) );

		return $this->success_response( array(
			'template_id' => $template_id,
			'conditions'  => $result['conditions'],
			// Conditions the caller sent that could not be understood. A
			// template bound to fewer conditions than were requested, reported
			// as a bare updated:true, is how #785 went unnoticed on the site.
			'warnings'    => $result['warnings'],
			'updated'     => true,
		) );
	}

	/**
	 * Remove template conditions.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function remove_conditions( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$result = $this->theme_builder->remove_from_locations( $template_id );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'remove_template_conditions', $request, array( 'template_id' => $template_id ) );

		return $this->success_response( array(
			'template_id' => $template_id,
			'conditions'  => array(),
			'removed'     => true,
		) );
	}

	/**
	 * Create a Theme Builder template and assign to a location.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_theme_template( $request ) {
		$data = array(
			'title'          => $request->get_param( 'title' ),
			'type'           => $request->get_param( 'type' ),
			'elementor_data' => $request->get_param( 'elementor_data' ),
			'scope'          => $request->get_param( 'scope' ),
			'post_type'      => $request->get_param( 'post_type' ),
			'post_ids'       => $request->get_param( 'post_ids' ),
		);

		$result = $this->theme_builder->create_theme_template( $data );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'create_theme_template', $request, array(
			'template_id' => $result['id'] ?? null,
			'type'        => $data['type'],
			'scope'       => $data['scope'] ?? 'entire_site',
		) );

		return $this->success_response( $result );
	}

	/**
	 * Assign template to location (shortcut).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function assign_to_location( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$scope = $request->get_param( 'scope' ) ?: 'entire_site';

		$options = array(
			'post_type'    => $request->get_param( 'post_type' ),
			'archive_type' => $request->get_param( 'archive_type' ),
			'post_ids'     => $request->get_param( 'post_ids' ),
		);

		$result = $this->theme_builder->assign_to_location( $template_id, '', $scope, $options );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'assign_template_to_location', $request, array(
			'template_id' => $template_id,
			'scope'       => $scope,
		) );

		return $this->success_response( array(
			'template_id' => $template_id,
			'scope'       => $scope,
			'conditions'  => $result,
			'assigned'    => true,
		) );
	}
}
