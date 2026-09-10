<?php
/**
 * Destructive operations go through the approval workflow by default (#536).
 *
 * The plugin ships a full approval lifecycle (request → approve → apply →
 * rollback) and, until now, nothing used it by default: a force delete on a
 * fresh install was gone immediately. The operations gated here are the
 * ones with no undo — permanent deletes of content and media, purging every
 * draft.
 *
 * The first cut was gameable in a way the adversarial review put plainly:
 * every principal the gate applied to could also switch it off, at the same
 * scope, in one ungated call — mint a key with allow_destructive, or flip
 * the site setting through the settings API. A gate whose exemption comes
 * from the thing it gates is an audit record, not a veto. So:
 *
 *   - `allow_destructive` on a key and the site-wide switch are honoured
 *     only from the WordPress admin (a logged-in administrator acting in
 *     wp-admin), never from an API or MCP principal. An API caller that
 *     sends `allow_destructive` is told it was ignored.
 *   - Approving and applying live in the store, not the controller: the
 *     principal that requested an operation cannot approve or apply it,
 *     whichever door it comes through.
 *   - What was reviewed is what runs: parameters are sanitised on store, a
 *     fingerprint of the target is taken at defer and re-checked at apply,
 *     and a drift refuses the apply and says what changed.
 *   - Revoking a key is NOT gated. It is the incident-response lever, and
 *     it is reversible in the sense that matters (mint a replacement).
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
	 * Operations gated, by short name. Anything on the plugin's own
	 * destructive-tool list and not here is either reversible (trash,
	 * menus, webhooks) or incident response (revoke, reset) — see the test
	 * that ties the two lists together.
	 */
	const GATED = array( 'delete_page', 'delete_post', 'delete_content', 'delete_all_drafts', 'delete_media' );

	/**
	 * How many pending requests one principal may hold. A flood of gated
	 * calls must not evict the queue other people are reviewing.
	 */
	const MAX_PENDING_PER_REQUESTER = 20;

	/**
	 * Set while an approved request is being applied; the re-dispatch is
	 * not gated again. Private, saved and restored around the dispatch —
	 * never a request header, never left set.
	 *
	 * @var bool
	 */
	private static $applying = false;

	/**
	 * WordPress user id an admin-context apply runs as, while it runs.
	 *
	 * @var int
	 */
	private static $applying_as_admin = 0;

	/**
	 * @return bool
	 */
	public static function is_applying() {
		return self::$applying;
	}

	/**
	 * The admin user an apply is running as, or 0.
	 *
	 * @return int
	 */
	public static function applying_as_admin() {
		return (int) self::$applying_as_admin;
	}

	/**
	 * Whether this site gates destructive operations. Default on. The switch
	 * is read from the settings option but is NOT in the settings API's
	 * allowlist: it is changed from wp-admin or not at all.
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
	 * Whether the current request comes from an API/MCP principal, as
	 * opposed to a logged-in administrator in wp-admin.
	 *
	 * @param array|null $key_record Current key record.
	 * @return bool
	 */
	public static function is_api_principal( $key_record ) {
		return is_array( $key_record ) && ! empty( $key_record['id'] );
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

	// ---------------------------------------------------------------
	// Defer.
	// ---------------------------------------------------------------

	/**
	 * Store the call as a pending approval and describe it to the caller.
	 *
	 * @param string          $operation  One of GATED.
	 * @param WP_REST_Request $request    The request that was gated.
	 * @param array           $resource   {type, id, title} for the record.
	 * @param string          $summary    One line a reviewer reads.
	 * @param array|null      $key_record Current key record.
	 * @return array|WP_Error 202 body, or the refusal.
	 */
	public static function defer( $operation, $request, array $resource, $summary, $key_record = null ) {
		$route  = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		$method = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'DELETE';
		$params = method_exists( $request, 'get_params' ) ? (array) $request->get_params() : array();

		unset( $params['_locale'], $params['_wpnonce'], $params['rest_route'], $params['_method'] );

		$approval = Mcpwp_Approvals::create_destructive_request(
			$operation,
			array(
				'route'  => $route,
				'method' => $method,
				'params' => self::sanitize_params( $params ),
				'target' => self::fingerprint( $resource ),
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
				__( '%1$s is a destructive operation and this site requires approval for it. Nothing was changed. Approval request %2$s is pending: a different reviewer approves it (wp_approve_request) and applies it (wp_apply_approval), or a WordPress administrator does so from the Control Room.', 'mcpwp' ),
				$operation,
				$approval['id']
			),
			'hint'              => 'Approval is the default for operations with no undo: permanent deletes of pages, posts, other content and media, and purging all drafts. Trashing (force=false) is not gated. Exemptions (a key\'s allow_destructive, the site switch) are set by an administrator in wp-admin, not through the API.',
		);
	}

	/**
	 * Requester-controlled parameters are stored sanitised and bounded:
	 * they are shown to a reviewer, who may be another model.
	 *
	 * @param mixed $value Params.
	 * @param int   $depth Depth so far.
	 * @return mixed
	 */
	public static function sanitize_params( $value, $depth = 0 ) {
		if ( $depth > 4 ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$out = array();
			$n   = 0;
			foreach ( $value as $k => $v ) {
				if ( ++$n > 50 ) {
					break;
				}
				$key = is_string( $k ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', $k ) : $k;
				if ( '' === $key ) {
					continue;
				}
				$out[ $key ] = self::sanitize_params( $v, $depth + 1 );
			}
			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return mb_substr( sanitize_text_field( (string) $value ), 0, 500 );
	}

	/**
	 * A fingerprint of the target at defer time, re-checked at apply.
	 *
	 * @param array $resource {type, id}.
	 * @return array
	 */
	public static function fingerprint( array $resource ) {
		if ( empty( $resource['id'] ) || ! is_numeric( $resource['id'] ) || in_array( $resource['type'], array( 'api_key', 'drafts' ), true ) ) {
			return array();
		}

		$post = get_post( (int) $resource['id'] );
		if ( ! $post ) {
			return array( 'exists' => false );
		}

		return array(
			'exists'   => true,
			'type'     => (string) $post->post_type,
			'status'   => (string) $post->post_status,
			'modified' => isset( $post->post_modified_gmt ) ? (string) $post->post_modified_gmt : '',
			'title'    => (string) $post->post_title,
			'hash'     => hash( 'sha256', (string) $post->post_title . "\0" . (string) $post->post_content ),
		);
	}

	// ---------------------------------------------------------------
	// Apply.
	// ---------------------------------------------------------------

	/**
	 * Re-dispatch a stored operation with the gate lifted for that dispatch.
	 *
	 * The re-dispatch runs through the route's own permission callback and
	 * authenticates as the principal APPLYING the approval: an API key
	 * forwarded on the internal request, or a WordPress administrator
	 * applying from wp-admin. Never the requester, never nobody.
	 *
	 * @param array $payload {route, method, params, target}.
	 * @param array $context {api_key?: string, admin_user?: int}.
	 * @return array|WP_Error Response data or error.
	 */
	public static function replay( array $payload, array $context = array() ) {
		if ( empty( $payload['route'] ) || ! function_exists( 'rest_do_request' ) ) {
			return new WP_Error( 'destructive_replay_unavailable', __( 'The stored operation cannot be replayed.', 'mcpwp' ), array( 'status' => 500 ) );
		}

		$api_key    = isset( $context['api_key'] ) ? (string) $context['api_key'] : '';
		$admin_user = isset( $context['admin_user'] ) ? (int) $context['admin_user'] : 0;

		if ( '' === $api_key && $admin_user < 1 ) {
			return new WP_Error(
				'destructive_replay_unauthenticated',
				__( 'Applying a destructive approval needs the applying principal — a reviewer\'s key, or an administrator in wp-admin — so the operation runs as them.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		// What was reviewed is what runs.
		$drift = self::drift( $payload );
		if ( is_wp_error( $drift ) ) {
			return $drift;
		}

		$request = new WP_REST_Request( isset( $payload['method'] ) ? $payload['method'] : 'DELETE', $payload['route'] );
		foreach ( isset( $payload['params'] ) && is_array( $payload['params'] ) ? $payload['params'] : array() as $k => $v ) {
			$request->set_param( $k, $v );
		}
		if ( '' !== $api_key ) {
			$request->set_header( 'X-API-Key', $api_key );
		}

		$prev_applying = self::$applying;
		$prev_admin    = self::$applying_as_admin;
		self::$applying          = true;
		self::$applying_as_admin = '' === $api_key ? $admin_user : 0;
		try {
			$response = rest_do_request( $request );
		} finally {
			self::$applying          = $prev_applying;
			self::$applying_as_admin = $prev_admin;
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

	/**
	 * Refuse an apply whose target no longer matches what was reviewed.
	 *
	 * @param array $payload Stored payload.
	 * @return true|WP_Error
	 */
	private static function drift( array $payload ) {
		$target = isset( $payload['target'] ) && is_array( $payload['target'] ) ? $payload['target'] : array();
		if ( empty( $target ) || empty( $target['exists'] ) ) {
			return true;
		}

		$id = 0;
		if ( isset( $payload['params']['id'] ) ) {
			$id = (int) $payload['params']['id'];
		} elseif ( preg_match( '#/(\d+)(?:/|$)#', (string) $payload['route'], $m ) ) {
			$id = (int) $m[1];
		}
		if ( $id < 1 ) {
			return true;
		}

		$now = self::fingerprint( array( 'type' => $target['type'], 'id' => $id ) );
		if ( empty( $now['exists'] ) ) {
			return true; // Already gone; the route will answer 404.
		}

		if ( $now['hash'] !== $target['hash'] || $now['type'] !== $target['type'] ) {
			return new WP_Error(
				'target_changed',
				sprintf(
					/* translators: 1: id 2: title reviewed 3: title now */
					__( 'Post #%1$d changed after this request was reviewed ("%2$s" → "%3$s"). Nothing was applied. Review it again with a new request.', 'mcpwp' ),
					$id,
					$target['title'],
					$now['title']
				),
				array(
					'status'   => 409,
					'reviewed' => array( 'title' => $target['title'], 'status' => $target['status'], 'modified' => $target['modified'] ),
					'current'  => array( 'title' => $now['title'], 'status' => $now['status'], 'modified' => $now['modified'] ),
				)
			);
		}

		return true;
	}
}
