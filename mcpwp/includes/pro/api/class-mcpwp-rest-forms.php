<?php
/**
 * Forms REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for Forms features.
 *
 * Provides unified endpoints for CF7, WPForms, Gravity Forms, and Ninja Forms.
 */
class Mcpwp_REST_Forms extends Mcpwp_REST_API {

	/**
	 * Forms handler.
	 *
	 * @var Mcpwp_Forms
	 */
	private $forms;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Forms $forms Forms handler.
	 */
	public function __construct( $forms ) {
		$this->forms = $forms;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Forms status.
		register_rest_route(
			$this->namespace,
			'/forms/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get all forms from all plugins.
		register_rest_route(
			$this->namespace,
			'/forms',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_all_forms' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get forms by plugin.
		register_rest_route(
			$this->namespace,
			'/forms/(?P<plugin>cf7|wpforms|gravityforms|ninjaforms)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_forms' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get single form.
		register_rest_route(
			$this->namespace,
			'/forms/(?P<plugin>cf7|wpforms|gravityforms|ninjaforms)/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_form' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get form entries.
		register_rest_route(
			$this->namespace,
			'/forms/(?P<plugin>cf7|wpforms|gravityforms|ninjaforms)/(?P<id>\d+)/entries',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_entries' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * Get forms status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_status( $request ) {
		$status = $this->forms->get_status();

		$this->log_activity( 'forms_status', $request, $status );

		return $this->success_response( $status );
	}

	/**
	 * Get all forms from all plugins.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_all_forms( $request ) {
		$args = array(
			'per_page' => $request->get_param( 'per_page' ) ?: 50,
		);

		$forms = $this->forms->get_all_forms( $args );

		// Count total forms across all plugins.
		$total = 0;
		foreach ( $forms as $plugin_forms ) {
			$total += count( $plugin_forms );
		}

		$this->log_activity( 'get_all_forms', $request, array( 'total' => $total ) );

		return $this->success_response( array(
			'forms' => $forms,
			'total' => $total,
		) );
	}

	/**
	 * Get forms by plugin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_forms( $request ) {
		$plugin = $request->get_param( 'plugin' );
		$args   = array(
			'per_page' => $request->get_param( 'per_page' ) ?: 50,
		);

		$forms = $this->forms->get_forms( $plugin, $args );

		if ( is_wp_error( $forms ) ) {
			$status = $this->get_wp_error_status( $forms );
			$this->log_activity( 'get_forms', $request, null, $status );
			return $this->error_response_from_wp_error( $forms );
		}

		$this->log_activity( 'get_forms', $request, array(
			'plugin' => $plugin,
			'count'  => count( $forms ),
		) );

		return $this->success_response( array(
			'plugin' => $plugin,
			'forms'  => $forms,
			'total'  => count( $forms ),
		) );
	}

	/**
	 * Get single form.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_form( $request ) {
		$plugin  = $request->get_param( 'plugin' );
		$form_id = absint( $request->get_param( 'id' ) );

		$form = $this->forms->get_form( $plugin, $form_id );

		if ( is_wp_error( $form ) ) {
			$this->log_activity( 'get_form', $request, null, 404 );
			return $this->error_response( $form->get_error_code(), $form->get_error_message(), 404 );
		}

		$this->log_activity( 'get_form', $request, array(
			'plugin'  => $plugin,
			'form_id' => $form_id,
		) );

		return $this->success_response( $form );
	}

	/**
	 * Get form entries.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_entries( $request ) {
		$plugin  = $request->get_param( 'plugin' );
		$form_id = absint( $request->get_param( 'id' ) );
		$args    = array(
			'per_page' => $request->get_param( 'per_page' ) ?: 50,
			'offset'   => $request->get_param( 'offset' ) ?: 0,
		);

		$entries = $this->forms->get_entries( $plugin, $form_id, $args );

		if ( is_wp_error( $entries ) ) {
			$status = $this->get_wp_error_status( $entries );
			$this->log_activity( 'get_entries', $request, null, $status );
			return $this->error_response_from_wp_error( $entries );
		}

		$this->log_activity( 'get_entries', $request, array(
			'plugin'  => $plugin,
			'form_id' => $form_id,
			'count'   => isset( $entries['total'] ) ? $entries['total'] : count( $entries['entries'] ),
		) );

		return $this->success_response( array(
			'plugin'  => $plugin,
			'form_id' => $form_id,
			'entries' => $entries['entries'],
			'total'   => $entries['total'] ?? count( $entries['entries'] ),
			'notice'  => $entries['notice'] ?? null,
		) );
	}
}
