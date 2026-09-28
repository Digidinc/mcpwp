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
		// than the resource's. Filtering (SQL-side, so `total` stays exact —
		// round 2 adversarial review B1/W1) lives inside list_entries()
		// itself; this only computes WHAT the caller may see.
		$result = Mcpwp_Action_Log::list_entries(
			array(
				'limit'         => (int) $request->get_param( 'limit' ),
				'offset'        => (int) $request->get_param( 'offset' ),
				'tool_name'     => sanitize_key( (string) $request->get_param( 'tool_name' ) ),
				'success'       => '' !== (string) $success_param ? (int) $success_param : '',
				'resource_type' => sanitize_key( (string) $request->get_param( 'resource_type' ) ),
				'resource_id'   => sanitize_text_field( (string) $request->get_param( 'resource_id' ) ),
				'visibility'    => $this->action_log_visibility(),
			)
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * #1031 round 2 (adversarial review DESIGN items 2-4): compute the
	 * `visibility` descriptor every action-log door (list, get, rollback,
	 * export) passes to Mcpwp_Action_Log — null for an unrestricted key
	 * (admin role, legacy key, or no key record at all — same "no filter"
	 * behavior wp-admin's own direct Mcpwp_Action_Log::list_entries() call
	 * gets), otherwise the caller's allowed tool categories, the registered
	 * post types those categories cover, and the caller's own key id (an
	 * entry a key wrote is always visible to it — W3).
	 *
	 * Deliberately `resolve_key_tool_categories()` (ROLE only), not
	 * `caller_may_read_category()` — the site-wide
	 * `mcpwp_disabled_tool_categories` toggle must not erase an
	 * UNRESTRICTED key's own view of its site's history just because an
	 * admin later disabled that category going forward (round 2 W4): a
	 * toggle governs what a key may DO now, not what the audit trail may
	 * SHOW about what already happened.
	 *
	 * @return array|null
	 */
	private function action_log_visibility() {
		$key_record = $this->get_current_api_key_record();
		$categories = $this->resolve_key_tool_categories( is_array( $key_record ) ? $key_record : array() );

		if ( empty( $categories ) ) {
			return null; // Unrestricted.
		}

		$post_types = array();
		foreach ( get_post_types() as $post_type ) {
			if ( in_array( Mcpwp_REST_API::category_for_post_type( (string) $post_type ), $categories, true ) ) {
				$post_types[] = (string) $post_type;
			}
		}

		return array(
			'categories'    => $categories,
			'post_types'    => $post_types,
			'caller_key_id' => is_array( $key_record ) && ! empty( $key_record['id'] ) ? (string) $key_record['id'] : '',
		);
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
		$entry  = Mcpwp_Action_Log::get_entry_public( $log_id, $this->action_log_visibility() );

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
		// #1032 round 2 (NIT): see Mcpwp_Action_Log::rollback()'s docblock.
		$result = Mcpwp_Action_Log::rollback( $log_id, $this->action_log_visibility(), $this->template_ref_gate() );

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
				'limit'         => (int) $request->get_param( 'limit' ),
				'tool_name'     => sanitize_key( (string) $request->get_param( 'tool_name' ) ),
				'resource_type' => sanitize_key( (string) $request->get_param( 'resource_type' ) ),
				'visibility'    => $this->action_log_visibility(),
			)
		);

		return new WP_REST_Response( $csv, 200 );
	}
}
