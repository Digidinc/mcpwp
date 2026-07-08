<?php
/**
 * TP Events REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events REST controller class.
 */
class Mcpwp_REST_Events extends Mcpwp_REST_API {

	/**
	 * Events handler instance.
	 *
	 * @var Mcpwp_Events
	 */
	private $handler;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Events $handler Handler instance.
	 */
	public function __construct( $handler ) {
		$this->handler = $handler;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_events' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_events_args(),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_event' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/events/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_event' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_event' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	// =========================================================================
	// Route Arguments
	// =========================================================================

	/**
	 * Get events query arguments.
	 *
	 * @return array
	 */
	private function get_events_args() {
		return array(
			'per_page' => array(
				'type'        => 'integer',
				'default'     => 50,
				'minimum'     => 1,
				'maximum'     => 100,
				'description' => __( 'Items per page.', 'mumcp' ),
			),
			'page'     => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page number.', 'mumcp' ),
			),
			'status'   => array(
				'type'        => 'string',
				'default'     => 'publish',
				'enum'        => array( 'publish', 'draft', 'pending', 'private', 'any' ),
				'description' => __( 'Event status.', 'mumcp' ),
			),
			'search'   => array(
				'type'        => 'string',
				'description' => __( 'Search term.', 'mumcp' ),
			),
		);
	}

	// =========================================================================
	// Route Callbacks
	// =========================================================================

	/**
	 * List events.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function list_events( $request ) {
		$args = array(
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
			'status'   => $request->get_param( 'status' ),
			'search'   => $request->get_param( 'search' ),
		);

		return rest_ensure_response( $this->handler->list_events( $args ) );
	}

	/**
	 * Get a single event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_event( $request ) {
		$result = $this->handler->get_event( $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Create an event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_event( $request ) {
		$data = $request->get_params();

		$result = $this->handler->create_event( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update an event.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_event( $request ) {
		$id   = $request->get_param( 'id' );
		$data = $request->get_params();

		$result = $this->handler->update_event( $id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
