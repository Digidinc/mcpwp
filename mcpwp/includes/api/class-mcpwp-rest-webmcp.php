<?php
/**
 * REST endpoint for WebMCP tool calls from wp-admin (#981).
 *
 * POST /mcpwp/v1/webmcp/call  {name, arguments}
 *
 * Called only by admin/js/mcpwp-webmcp.js, from a wp-admin page, on behalf of
 * the browser's built-in agent. Authentication is the WordPress login cookie
 * plus a valid `wp_rest` nonce — and nothing else: an API key or Bearer token
 * is refused, and an Application Password never authenticates here (the
 * login cookie is required), because this surface is "the signed-in person,
 * in their own browser", not a machine credential.
 *
 * The tool call itself is dispatched through the SAME /mcp tools/call
 * chokepoint every MCP client uses (rate limit, spend budget, output schema,
 * next-step hints, idempotency, analytics, action log), under the WebMCP
 * principal (see Mcpwp_WebMCP and Mcpwp_Api_Auth::authenticate_webmcp_principal()).
 *
 * Page content returned by these tools can contain prompt-injection text;
 * the containment is the allowlist + drafts + approvals described in
 * Mcpwp_WebMCP's class docblock.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WebMCP call endpoint.
 */
class Mcpwp_REST_WebMCP extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/webmcp/call',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_call' ),
					'permission_callback' => array( $this, 'check_webmcp_permission' ),
					'args'                => array(
						'name'      => array(
							'type'     => 'string',
							'required' => true,
						),
						'arguments' => array(
							'type' => 'object',
						),
						// Optional idempotency key, forwarded as the /mcp
						// tools/call params._meta.request_id: a re-send with
						// the same id replays the stored result instead of
						// writing twice (principal webmcp:user:<id>).
						'request_id' => array(
							'type' => 'string',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission callback. Order matters — each refusal answers the first
	 * thing that is wrong, and nothing about the tool is looked at before
	 * the caller is known to be the signed-in user on this site.
	 *
	 *  1. WebMCP switched off            → 404 (the route does not exist)
	 *  2. a machine credential presented → 403
	 *  3. cross-origin / no origin proof → 403
	 *  4. no valid WordPress login cookie → 401
	 *  5. missing or invalid wp_rest nonce → 403
	 *
	 * WordPress core runs first: a request that carries an X-WP-Nonce but
	 * no valid login cookie (or a cookie with a bad nonce) is refused by
	 * core itself with 403 rest_cookie_invalid_nonce before this callback
	 * runs. So 401 is the answer only for a request with neither a valid
	 * cookie nor a nonce; a valid cookie without a nonce is a 403 here.
	 *  6. user lacks the base capability → 403
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function check_webmcp_permission( $request ) {
		if ( ! Mcpwp_WebMCP::is_enabled() ) {
			return new WP_Error(
				'rest_no_route',
				__( 'No route was found matching the URL and request method.', 'mcpwp' ),
				array( 'status' => 404 )
			);
		}

		if ( $this->presents_machine_credential( $request ) ) {
			return new WP_Error(
				'webmcp_credential_refused',
				__( 'WebMCP accepts only the signed-in WordPress session. Remove the API key or token; use /mcpwp/v1/mcp for API-key access.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->is_same_origin( $request ) ) {
			return new WP_Error(
				'webmcp_cross_origin',
				__( 'WebMCP calls must come from this site\'s own wp-admin.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		// Core sets this to boolean true only when a valid logged_in cookie
		// authenticated the request (rest_cookie_collect_status); a missing
		// or bad cookie leaves a reason STRING in it ('malformed', 'expired',
		// 'bad_hash' …), so only a strict true counts. It stays true even
		// when core then drops the user for a missing nonce, which is what
		// lets "no cookie" (401) and "cookie but no nonce" (403) be told
		// apart. Application Passwords and other non-cookie logins never set
		// it to true.
		if ( ! isset( $GLOBALS['wp_rest_auth_cookie'] ) || true !== $GLOBALS['wp_rest_auth_cookie'] ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be signed in to wp-admin to use WebMCP.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) || ! wp_verify_nonce( (string) $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Missing or expired security token. Reload the wp-admin page and try again.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be signed in to wp-admin to use WebMCP.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		if ( ! Mcpwp_WebMCP::user_may_use( $user_id ) ) {
			return new WP_Error(
				'webmcp_forbidden',
				__( 'Your account cannot use WebMCP on this site.', 'mcpwp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Whether the request carries a machine credential: an MCPWP API key or
	 * token (header, ?api_key= or access_token) or any Bearer token. Presence
	 * alone refuses — a request carrying two identities is ambiguous about
	 * which one acts.
	 *
	 * HTTP Basic is deliberately NOT refused: browsers send it automatically
	 * to a password-protected staging site, on every request. It cannot act
	 * as an identity here anyway — an Application Password never sets the
	 * login-cookie flag the permission callback requires, and WordPress
	 * resolves the cookie user before it ever consults Basic credentials.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function presents_machine_credential( $request ) {
		if ( ! empty( $request->get_header( 'X-API-Key' ) ) ) {
			return true;
		}

		$authorization = (string) $request->get_header( 'Authorization' );
		if ( '' !== $authorization && preg_match( '/^\s*Bearer\b/i', $authorization ) ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
		return ! empty( $_GET['api_key'] ) || null !== $request->get_param( 'api_key' ) || null !== $request->get_param( 'access_token' );
	}

	/**
	 * Same-origin check. A browser always sends Origin on a same-origin
	 * POST fetch; when a proxy strips it, Referer must be present and point
	 * at this site. Neither → refused (fail closed).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private function is_same_origin( $request ) {
		$allowed = array();
		foreach ( array( home_url( '/' ), site_url( '/' ), admin_url( '/' ) ) as $url ) {
			$origin = self::origin_of( $url );
			if ( '' !== $origin ) {
				$allowed[ $origin ] = true;
			}
		}

		$origin_header = (string) $request->get_header( 'Origin' );
		if ( '' !== $origin_header ) {
			// "null" (sandboxed frame, file://, some redirects) is never ours.
			$origin = self::origin_of( $origin_header );
			return '' !== $origin && isset( $allowed[ $origin ] );
		}

		$referer = (string) $request->get_header( 'Referer' );
		if ( '' === $referer ) {
			return false;
		}
		$origin = self::origin_of( $referer );
		return '' !== $origin && isset( $allowed[ $origin ] );
	}

	/**
	 * scheme://host[:port], lower-cased, default ports dropped; '' when the
	 * value is not an absolute http(s) URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function origin_of( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		return $scheme . '://' . strtolower( $parts['host'] ) . ( $port ? ':' . $port : '' );
	}

	/**
	 * Handle one WebMCP tool call.
	 *
	 * Answers 200 with an MCP CallToolResult (content / structuredContent /
	 * isError) for anything the tool itself decided, so the agent can read
	 * the reason and self-correct; refuses with a WP_Error for anything the
	 * WebMCP gate decided (tool not allowlisted, capability, draft-only) or
	 * the auth chokepoint decided (rate limit, scope).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_call( $request ) {
		$user_id   = get_current_user_id();
		$tool_name = $request->get_param( 'name' );
		$arguments = $request->get_param( 'arguments' );

		if ( ! Mcpwp_WebMCP::is_allowlisted( $tool_name ) ) {
			return $this->refuse(
				$request,
				'webmcp_tool_not_allowed',
				__( 'This tool is not available through WebMCP. WebMCP offers reading, draft creation and proposing edits for approval only.', 'mcpwp' ),
				403,
				is_string( $tool_name ) ? $tool_name : ''
			);
		}

		if ( ! Mcpwp_WebMCP::current_user_may_call( $tool_name ) ) {
			return $this->refuse(
				$request,
				'webmcp_tool_forbidden',
				__( 'Your WordPress role cannot use this tool.', 'mcpwp' ),
				403,
				$tool_name
			);
		}

		if ( null === $arguments ) {
			$arguments = array();
		}
		if ( ! is_array( $arguments ) ) {
			return $this->refuse( $request, 'invalid_arguments', __( '"arguments" must be an object.', 'mcpwp' ), 400, $tool_name );
		}

		$arguments = Mcpwp_WebMCP::enforce_draft( $tool_name, $arguments );
		if ( is_wp_error( $arguments ) ) {
			return $this->refuse( $request, $arguments->get_error_code(), $arguments->get_error_message(), 403, $tool_name );
		}

		$params = array(
			'name'      => $tool_name,
			'arguments' => empty( $arguments ) ? new stdClass() : $arguments,
		);
		$request_id = $request->get_param( 'request_id' );
		if ( null !== $request_id ) {
			// Validated by the /mcp idempotency layer, which answers a
			// malformed id with its own error.
			$params['_meta'] = array( 'request_id' => is_scalar( $request_id ) ? (string) $request_id : '' );
		}

		$internal = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp' );
		$internal->set_header( 'Content-Type', 'application/json' );
		$internal->set_body(
			wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'tools/call',
					'params'  => $params,
				)
			)
		);

		$previous = Mcpwp_WebMCP::begin_dispatch( $user_id );
		try {
			$response = rest_do_request( $internal );
		} finally {
			Mcpwp_WebMCP::end_dispatch( $previous );
		}

		$status = is_object( $response ) && method_exists( $response, 'get_status' ) ? (int) $response->get_status() : 500;
		$data   = is_object( $response ) && method_exists( $response, 'get_data' ) ? $response->get_data() : null;

		// Refused before any tool ran (rate limit, scope, category): pass
		// the refusal through with its status.
		if ( $status >= 400 ) {
			$code    = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'webmcp_call_failed';
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : __( 'The tool call was refused.', 'mcpwp' );
			$extra   = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
			return $this->refuse( $request, $code, $message, $status, $tool_name, $extra, true );
		}

		$result = $this->to_call_tool_result( $data );
		$this->log_webmcp_call( $request, $tool_name, $user_id, $result, 200 );

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Turn the /mcp JSON-RPC answer into an MCP CallToolResult. A JSON-RPC
	 * protocol error (unknown tool, bad params) becomes an isError result
	 * with its message, the same shape a tool failure has.
	 *
	 * @param mixed $data JSON-RPC response body.
	 * @return array
	 */
	private function to_call_tool_result( $data ) {
		if ( is_array( $data ) && isset( $data['result'] ) && is_array( $data['result'] ) ) {
			$result = $data['result'];
			if ( ! isset( $result['isError'] ) ) {
				$result['isError'] = false;
			}
			return $result;
		}

		$message = __( 'The tool returned no result.', 'mcpwp' );
		$error   = array();
		if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ) {
			$error   = $data['error'];
			$message = isset( $error['message'] ) ? (string) $error['message'] : $message;
			if ( isset( $error['data']['hint'] ) && is_string( $error['data']['hint'] ) ) {
				$message .= ' ' . $error['data']['hint'];
			}
		}

		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}

	/**
	 * Build and log a refusal.
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param string          $code      Error code.
	 * @param string          $message   Message.
	 * @param int             $status    HTTP status.
	 * @param string          $tool_name Tool.
	 * @param array           $extra     Extra error data.
	 * @param bool            $already_metered Whether /mcp already charged this call.
	 * @return WP_Error
	 */
	private function refuse( $request, $code, $message, $status, $tool_name, array $extra = array(), $already_metered = false ) {
		// A refusal the endpoint decides itself (not allowlisted, capability,
		// draft-only) never reaches /mcp, where calls are metered — charge it
		// here, BEFORE writing the activity-log row, so refused calls cannot
		// be used to flood the log without limit. A refusal that came back
		// from /mcp (rate limit, scope) was already charged there.
		if ( ! $already_metered ) {
			$user_id = get_current_user_id();
			$limited = $this->check_rate_limit( 'webmcp:user:' . $user_id, 'POST', Mcpwp_WebMCP::principal_record( $user_id ) );
			if ( is_wp_error( $limited ) ) {
				return $limited;
			}
		}
		$this->log_webmcp_call( $request, $tool_name, get_current_user_id(), array( 'error' => $code ), $status );
		return new WP_Error( $code, $message, array_merge( $extra, array( 'status' => (int) $status ) ) );
	}

	/**
	 * Activity-log row for a WebMCP call: source=webmcp and the user id,
	 * alongside what /mcp itself logs. (Write tools also land in the action
	 * log with api_key_id webmcp:user:<id> — see Mcpwp_WebMCP::principal_id().)
	 *
	 * @param WP_REST_Request $request   Request.
	 * @param string          $tool_name Tool.
	 * @param int             $user_id   User.
	 * @param array           $result    Result or error summary.
	 * @param int             $status    HTTP status.
	 * @return void
	 */
	private function log_webmcp_call( $request, $tool_name, $user_id, array $result, $status ) {
		$this->log_activity(
			'webmcp_tool_call',
			$request,
			array(
				'source'   => Mcpwp_WebMCP::SOURCE,
				'user_id'  => (int) $user_id,
				'tool'     => (string) $tool_name,
				'is_error' => ! empty( $result['isError'] ) || isset( $result['error'] ),
				'error'    => isset( $result['error'] ) ? $result['error'] : null,
			),
			$status
		);
	}
}
