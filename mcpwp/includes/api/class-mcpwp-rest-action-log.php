<?php
/**
 * REST endpoints for the AI action audit log.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles /action-log/* REST routes.
 */
class Mcpwp_REST_Action_Log extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/action-log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_entries' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'limit'         => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100, 'sanitize_callback' => 'absint' ),
					'offset'        => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0, 'sanitize_callback' => 'absint' ),
					'tool_name'     => array( 'type' => 'string', 'default' => '' ),
					'success'       => array( 'type' => 'string', 'default' => '' ),
					'resource_type' => array( 'type' => 'string', 'default' => '' ),
					'resource_id'   => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/action-log/export',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_csv' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'limit'         => array( 'type' => 'integer', 'default' => 1000, 'minimum' => 1, 'maximum' => 1000, 'sanitize_callback' => 'absint' ),
					'tool_name'     => array( 'type' => 'string', 'default' => '' ),
					'resource_type' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		// Specific log entry — must be registered BEFORE the rollback route so WP matches correctly.
		register_rest_route(
			$this->namespace,
			'/action-log/(?P<log_id>[a-z0-9_]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_entry' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/action-log/(?P<log_id>[a-z0-9_]+)/rollback',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rollback_entry' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * GET /action-log
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function list_entries( $request ) {
		if ( ! class_exists( 'Mcpwp_Action_Log' ) ) {
			return new WP_REST_Response( array( 'entries' => array(), 'total' => 0 ), 200 );
		}

		$success_param = $request->get_param( 'success' );

		// #1031: a key with 'admin' but not, say, 'woocommerce' must not see
		// a product's write-tool activity just because it reached the log
		// through a generic, post-type-agnostic tool (wp_update_post,
		// wp_delete_post, ...) that logged under its OWN category rather
		// than the resource's. Filtering (and the total it produces) lives
		// inside list_entries() itself, ahead of its own offset/limit slice
		// — see its docblock.
		$result = Mcpwp_Action_Log::list_entries(
			array(
				'limit'            => (int) $request->get_param( 'limit' ),
				'offset'           => (int) $request->get_param( 'offset' ),
				'tool_name'        => sanitize_key( (string) $request->get_param( 'tool_name' ) ),
				'success'          => '' !== (string) $success_param ? (int) $success_param : '',
				'resource_type'    => sanitize_key( (string) $request->get_param( 'resource_type' ) ),
				'resource_id'      => sanitize_text_field( (string) $request->get_param( 'resource_id' ) ),
				'category_allowed' => array( $this, 'caller_may_read_category' ),
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /action-log/{log_id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_entry( $request ) {
		if ( ! class_exists( 'Mcpwp_Action_Log' ) ) {
			return new WP_Error( 'not_found', __( 'Log entry not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		// #1031: same category gate as the list door, so a direct log_id
		// lookup cannot be used to read (or merely confirm the existence of)
		// an entry the caller could not see via GET /action-log.
		$log_id = sanitize_text_field( (string) $request->get_param( 'log_id' ) );
		$entry  = Mcpwp_Action_Log::get_entry_public( $log_id, array( $this, 'caller_may_read_category' ) );

		if ( ! $entry ) {
			return new WP_Error( 'not_found', __( 'Log entry not found.', 'mcpwp' ), array( 'status' => 404 ) );
		}

		return new WP_REST_Response( $entry, 200 );
	}

	/**
	 * POST /action-log/{log_id}/rollback
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rollback_entry( $request ) {
		if ( ! class_exists( 'Mcpwp_Action_Log' ) ) {
			return new WP_Error( 'unavailable', __( 'Action log not available.', 'mcpwp' ), array( 'status' => 503 ) );
		}

		// #1031: same category gate — rolling back an entry the caller
		// cannot see is refused with the same not_found shape as a genuinely
		// missing log_id.
		$log_id = sanitize_text_field( (string) $request->get_param( 'log_id' ) );
		$result = Mcpwp_Action_Log::rollback( $log_id, array( $this, 'caller_may_read_category' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'status' => 'rolled_back', 'log_id' => $log_id ), 200 );
	}

	/**
	 * GET /action-log/export — returns CSV content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function export_csv( $request ) {
		if ( ! class_exists( 'Mcpwp_Action_Log' ) ) {
			return new WP_REST_Response( '', 200 );
		}

		// #1031: same category gate as the list door — an export must not be
		// a wider door than the listing it exports.
		$csv = Mcpwp_Action_Log::export_csv(
			array(
				'limit'            => (int) $request->get_param( 'limit' ),
				'tool_name'        => sanitize_key( (string) $request->get_param( 'tool_name' ) ),
				'resource_type'    => sanitize_key( (string) $request->get_param( 'resource_type' ) ),
				'category_allowed' => array( $this, 'caller_may_read_category' ),
			)
		);

		return new WP_REST_Response( $csv, 200 );
	}
}
