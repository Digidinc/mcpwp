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
					'args'                => $this->get_event_write_args(),
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
					'args'                => $this->get_event_write_args(),
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
				'description' => __( 'Items per page.', 'mcpwp' ),
			),
			'page'     => array(
				'type'        => 'integer',
				'default'     => 1,
				'minimum'     => 1,
				'description' => __( 'Page number.', 'mcpwp' ),
			),
			'status'   => array(
				'type'        => 'string',
				'default'     => 'publish',
				'enum'        => array( 'publish', 'draft', 'pending', 'private', 'any' ),
				'description' => __( 'Event status.', 'mcpwp' ),
			),
			'search'   => array(
				'type'        => 'string',
				'description' => __( 'Search term.', 'mcpwp' ),
			),
		);
	}

	/**
	 * Get create/update ("write") arguments for a single event.
	 *
	 * RC 3.12.1 adversarial review, BLOCK: create_event()/update_event() had
	 * no 'args' schema at all — unlike get_events_args() above, which
	 * already declares 'enum' on the LIST filter's 'status'. Without a
	 * declared enum, WordPress's has_valid_params() never runs before the
	 * callback, so a padded value (e.g. ' publish') that defeated the old
	 * exact-match publish-scope gate (get_required_scope_for_request() /
	 * param_value_targets_admin_scope() in trait-mcpwp-api-auth.php) could
	 * still reach Mcpwp_Events::create_event()/update_event(), which
	 * normalizes with sanitize_key() and would have accepted it as
	 * 'publish'. Declaring 'enum' here closes that gap the same way the
	 * schema-carrying routes (posts, pages, WooCommerce products) always
	 * were — WordPress itself rejects an out-of-enum value with
	 * rest_invalid_param before the gate or the handler ever sees it.
	 *
	 * @return array
	 */
	private function get_event_write_args() {
		return array(
			'status' => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
				'description' => __( 'Event status.', 'mcpwp' ),
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
		// Security clamp: a read-only key may only list published events. The
		// model uses this status as post_status directly, so a caller-supplied
		// draft/private/any would otherwise enumerate unpublished event titles.
		$status = $request->get_param( 'status' );
		if ( ! $this->requester_can_see_non_public() ) {
			$status = 'publish';
		}

		$args = array(
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
			'status'   => $status,
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
