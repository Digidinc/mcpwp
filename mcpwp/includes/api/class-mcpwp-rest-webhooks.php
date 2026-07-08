<?php
/**
 * Webhooks REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for webhook management.
 */
class Mcpwp_REST_Webhooks extends Mcpwp_REST_API {

	/**
	 * Webhooks handler.
	 *
	 * @var Mcpwp_Webhooks
	 */
	private $webhooks;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->webhooks = Mcpwp_Webhooks::get_instance();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// List available events
		register_rest_route(
			$this->namespace,
			'/webhooks/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/events/schema',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_event_schema' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_events' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'type'  => array(
							'type'    => 'string',
							'default' => '',
						),
						'limit' => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
			)
		);

		// List webhooks / Create webhook
		register_rest_route(
			$this->namespace,
			'/webhooks',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_webhooks' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'status'   => array(
							'type'    => 'string',
							'enum'    => array( 'active', 'disabled', 'all' ),
							'default' => null,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 100,
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_webhook' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name'   => array(
							'type'     => 'string',
							'required' => true,
						),
						'url'    => array(
							'type'     => 'string',
							'format'   => 'uri',
							'required' => true,
						),
						'events' => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'required' => true,
						),
						'secret' => array(
							'type' => 'string',
						),
					),
				),
			)
		);

		// Single webhook operations
		register_rest_route(
			$this->namespace,
			'/webhooks/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_webhook' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_webhook' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_webhook' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Test webhook
		register_rest_route(
			$this->namespace,
			'/webhooks/(?P<id>\d+)/test',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'test_webhook' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Webhook delivery logs
		register_rest_route(
			$this->namespace,
			'/webhooks/(?P<id>\d+)/logs',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_logs' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'per_page' => array(
							'type'    => 'integer',
							'default' => 50,
							'minimum' => 1,
							'maximum' => 100,
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
					),
				),
			)
		);
	}

	/**
	 * Get available webhook events.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_events( $request ) {
		$this->log_activity( 'webhook_events', $request );

		$events = $this->webhooks->get_events();

		// Group events by category
		$grouped = array();
		foreach ( $events as $event ) {
			list( $category, $action ) = explode( '.', $event );
			if ( ! isset( $grouped[ $category ] ) ) {
				$grouped[ $category ] = array();
			}
			$grouped[ $category ][] = $event;
		}

		return $this->success_response(
			array(
				'events'  => $events,
				'grouped' => $grouped,
				'total'   => count( $events ),
			)
		);
	}

	/**
	 * Get normalized event schema.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_event_schema( $request ) {
		$this->log_activity( 'event_schema', $request );

		$schema = class_exists( 'Mcpwp_Event_Store' ) ? Mcpwp_Event_Store::get_schema() : array();

		return $this->success_response(
			array(
				'events' => $schema,
				'total'  => count( $schema ),
			)
		);
	}

	/**
	 * List recent normalized events.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_events( $request ) {
		$this->log_activity( 'list_events', $request );

		$result = class_exists( 'Mcpwp_Event_Store' )
			? Mcpwp_Event_Store::list_events(
				array(
					'type'  => $request->get_param( 'type' ),
					'limit' => $request->get_param( 'limit' ),
				)
			)
			: array(
				'events' => array(),
				'total'  => 0,
			);

		return $this->success_response( $result );
	}

	/**
	 * List webhooks.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_webhooks( $request ) {
		$this->log_activity( 'list_webhooks', $request );

		$args = array(
			'status'   => $request->get_param( 'status' ),
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
		);

		if ( 'all' === $args['status'] ) {
			$args['status'] = null;
		}

		$result = $this->webhooks->list_webhooks( $args );

		// Mask secrets in response
		foreach ( $result['webhooks'] as &$webhook ) {
			$webhook['secret'] = substr( $webhook['secret'], 0, 8 ) . '...';
		}

		return $this->success_response( $result );
	}

	/**
	 * Create webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_webhook( $request ) {
		$this->log_activity( 'create_webhook', $request );

		$data = array(
			'name'   => $request->get_param( 'name' ),
			'url'    => $request->get_param( 'url' ),
			'events' => $request->get_param( 'events' ),
			'secret' => $request->get_param( 'secret' ),
		);

		$result = $this->webhooks->register( $data );

		if ( is_wp_error( $result ) ) {
			return $this->error_response(
				$result->get_error_code(),
				$result->get_error_message(),
				400
			);
		}

		$webhook = $this->webhooks->get( $result );

		return $this->success_response(
			array(
				'id'      => $result,
				'webhook' => $webhook,
				'message' => __( 'Webhook created successfully.', 'mumcp' ),
			),
			201
		);
	}

	/**
	 * Get single webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_webhook( $request ) {
		$this->log_activity( 'get_webhook', $request );

		$id      = (int) $request->get_param( 'id' );
		$webhook = $this->webhooks->get( $id );

		if ( ! $webhook ) {
			return $this->error_response(
				'not_found',
				__( 'Webhook not found.', 'mumcp' ),
				404
			);
		}

		// Mask secret
		$webhook['secret'] = substr( $webhook['secret'], 0, 8 ) . '...';

		return $this->success_response( $webhook );
	}

	/**
	 * Update webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_webhook( $request ) {
		$this->log_activity( 'update_webhook', $request );

		$id   = (int) $request->get_param( 'id' );
		$data = $request->get_json_params();
		if ( ! is_array( $data ) || empty( $data ) ) {
			$data = $request->get_params();
		}
		unset( $data['id'] );

		$result = $this->webhooks->update( $id, $data );

		if ( is_wp_error( $result ) ) {
			$status = 'not_found' === $result->get_error_code() ? 404 : 400;
			return $this->error_response(
				$result->get_error_code(),
				$result->get_error_message(),
				$status
			);
		}

		$webhook           = $this->webhooks->get( $id );
		$webhook['secret'] = substr( $webhook['secret'], 0, 8 ) . '...';

		return $this->success_response(
			array(
				'updated' => true,
				'webhook' => $webhook,
			)
		);
	}

	/**
	 * Delete webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_webhook( $request ) {
		$this->log_activity( 'delete_webhook', $request );

		$id     = (int) $request->get_param( 'id' );
		$result = $this->webhooks->delete( $id );

		if ( is_wp_error( $result ) ) {
			return $this->error_response(
				$result->get_error_code(),
				$result->get_error_message(),
				404
			);
		}

		return $this->success_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * Test webhook.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function test_webhook( $request ) {
		$this->log_activity( 'test_webhook', $request );

		$id     = (int) $request->get_param( 'id' );
		$result = $this->webhooks->test( $id );

		if ( is_wp_error( $result ) ) {
			return $this->error_response(
				$result->get_error_code(),
				$result->get_error_message(),
				404
			);
		}

		return $this->success_response( $result );
	}

	/**
	 * Get webhook delivery logs.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_logs( $request ) {
		$this->log_activity( 'webhook_logs', $request );

		$id = (int) $request->get_param( 'id' );

		// Verify webhook exists
		$webhook = $this->webhooks->get( $id );
		if ( ! $webhook ) {
			return $this->error_response(
				'not_found',
				__( 'Webhook not found.', 'mumcp' ),
				404
			);
		}

		$args = array(
			'per_page' => $request->get_param( 'per_page' ),
			'page'     => $request->get_param( 'page' ),
		);

		$result = $this->webhooks->get_logs( $id, $args );

		return $this->success_response( $result );
	}
}
