<?php
/**
 * Destructive operations go through the approval workflow by default (#536).
 *
 * The plugin ships a full approval lifecycle (request → approve → apply →
 * rollback) and, until now, nothing used it by default: a force delete on a
 * fresh install was gone immediately. The operations gated here are the
 * ones with no undo — permanent deletes, purging every draft, removing an
 * attachment and its files, revoking a key.
 *
 * The gate is on by default and opt-out per site (a setting) or per key
 * (`allow_destructive` on the key record), so an operator who has a reason
 * to run unattended can say so, once, in a place that is audited.
 *
 * When gated, the handler does not run. An approval request of action
 * `destructive_operation` is stored with the route, method and parameters;
 * the response is 202 with the approval id. Approving and applying the
 * request re-dispatches the very same call with the gate lifted for that
 * one dispatch — so what runs later is exactly what was reviewed.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destructive-operation approval gate.
 */
class Mcpwp_Destructive_Gate {

	/**
	 * Set while an approved request is being applied, so the re-dispatch is
	 * not gated again. A PHP static, never a request header: nothing a
	 * caller sends can flip it.
	 *
	 * @var bool
	 */
	public static $applying = false;

	/**
	 * Whether this site gates destructive operations. Default on.
	 *
	 * @return bool
	 */
	public static function enabled_for_site() {
		$settings = get_option( 'mcpwp_settings', array() );

		if ( is_array( $settings ) && array_key_exists( 'destructive_requires_approval', $settings ) ) {
			return (bool) $settings['destructive_requires_approval'];
		}

		return true;
	}

	/**
	 * Whether a key may run destructive operations without approval.
	 *
	 * @param array|null $key_record Current key record.
	 * @return bool
	 */
	public static function key_is_exempt( $key_record ) {
		return is_array( $key_record ) && ! empty( $key_record['allow_destructive'] );
	}

	/**
	 * Decide whether a call must be turned into an approval request.
	 *
	 * @param array|null $key_record Current key record.
	 * @return bool
	 */
	public static function should_gate( $key_record ) {
		if ( self::$applying ) {
			return false;
		}

		if ( ! self::enabled_for_site() ) {
			return false;
		}

		return ! self::key_is_exempt( $key_record );
	}

	/**
	 * Store the call as a pending approval and describe it to the caller.
	 *
	 * @param string          $operation  Short name, e.g. delete_page.
	 * @param WP_REST_Request $request    The request that was gated.
	 * @param array           $resource   {type, id, title} for the record.
	 * @param string          $summary    One line a reviewer reads.
	 * @param array|null      $key_record Current key record.
	 * @return array|WP_Error 202 body, or the storage error.
	 */
	public static function defer( $operation, $request, array $resource, $summary, $key_record = null ) {
		$route  = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'DELETE';
		$params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : array();

		unset( $params['_locale'], $params['_wpnonce'], $params['rest_route'] );

		$approval = Mcpwp_Approvals::create_destructive_request(
			$operation,
			array(
				'route'  => $route,
				'method' => $method,
				'params' => $params,
			),
			$resource,
			array(
				'title'               => $summary,
				'requested_by_key_id' => is_array( $key_record ) && isset( $key_record['id'] ) ? (string) $key_record['id'] : '',
			)
		);

		if ( is_wp_error( $approval ) ) {
			return $approval;
		}

		return array(
			'approval_required' => true,
			'approval_id'       => $approval['id'],
			'status'            => 'pending',
			'operation'         => $operation,
			'message'           => sprintf(
				/* translators: 1: operation 2: approval id */
				__( '%1$s is a destructive operation and this site requires approval for it. Nothing was changed. Approval request %2$s is pending: a reviewer approves it (wp_approve_request) and applies it (wp_apply_approval), or an admin exempts this key (allow_destructive) or the site (destructive_requires_approval=false).', 'mcpwp' ),
				$operation,
				$approval['id']
			),
			'hint'              => 'Approval is the default for operations with no undo: permanent deletes, purging drafts, deleting media, revoking keys. Trashing (force=false) is not gated.',
		);
	}

	/**
	 * Re-dispatch a stored operation with the gate lifted for that dispatch.
	 *
	 * The re-dispatch runs through the route's own permission callback and
	 * authenticates as the principal APPLYING the approval — the reviewer's
	 * credential, forwarded on the internal request the same way the MCP
	 * dispatcher forwards it — never as the requester and never unauthenticated.
	 *
	 * @param array  $payload {route, method, params}.
	 * @param string $api_key The applying principal's API key, or '' when none.
	 * @return array|WP_Error Response data or error.
	 */
	public static function replay( array $payload, $api_key = '' ) {
		if ( empty( $payload['route'] ) || ! function_exists( 'rest_do_request' ) ) {
			return new WP_Error( 'destructive_replay_unavailable', __( 'The stored operation cannot be replayed.', 'mcpwp' ), array( 'status' => 500 ) );
		}

		if ( '' === (string) $api_key ) {
			return new WP_Error(
				'destructive_replay_unauthenticated',
				__( 'Applying a destructive approval needs the applying key so the operation runs as the reviewer. Apply it through the REST or MCP approvals surface.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		$request = new WP_REST_Request( isset( $payload['method'] ) ? $payload['method'] : 'DELETE', $payload['route'] );
		$request->set_header( 'X-API-Key', (string) $api_key );
		foreach ( isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array() as $k => $v ) {
			$request->set_param( $k, $v );
		}

		self::$applying = true;
		try {
			$response = rest_do_request( $request );
		} finally {
			self::$applying = false;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 200;
		$data   = method_exists( $response, 'get_data' ) ? $response->get_data() : null;

		if ( $status >= 400 ) {
			return new WP_Error(
				'destructive_replay_failed',
				sprintf(
					/* translators: %d: HTTP status */
					__( 'The approved operation failed when applied (HTTP %d).', 'mcpwp' ),
					$status
				),
				array(
					'status'   => $status,
					'response' => $data,
				)
			);
		}

		return is_array( $data ) ? $data : array( 'applied' => true );
	}
}
