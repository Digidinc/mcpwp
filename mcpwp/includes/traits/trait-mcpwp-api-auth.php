<?php
/**
 * API Authentication Trait
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API authentication functionality.
 */
trait Mcpwp_Api_Auth {

	/**
	 * The matched API key record for the current request.
	 *
	 * Populated by verify_api_key() so downstream code (e.g. MCP controller)
	 * can check the key's role and tool category restrictions.
	 *
	 * @var array|null
	 */
	protected $current_api_key_record = null;

	/**
	 * Get the matched API key record for the current request.
	 *
	 * @return array|null Key record or null if not authenticated yet.
	 */
	public function get_current_api_key_record() {
		return $this->current_api_key_record;
	}

	/**
	 * Whether a post status is publicly viewable.
	 *
	 * Only statuses whose registered status object carries the `public` flag
	 * count as public — on a stock WordPress install that is exactly the
	 * built-in `publish` status. Draft, pending, private, future, trash and
	 * every internal status return false. Unknown or unregistered status
	 * strings also return false, so ambiguous input is treated as non-public.
	 *
	 * Shared gate: every REST controller that reads or renders post bodies
	 * (site-content, elementor, blocks, pages, posts, the SEO structured-data /
	 * media / readiness audits, content-quality, and the content-graph) uses this
	 * together with requester_can_see_non_public() so a read-only key can never
	 * reach an unpublished body through any read surface. The content-graph and
	 * /drafts surfaces enumerate rather than resolve a single post, so they clamp
	 * on requester_can_see_non_public() directly instead of calling
	 * guard_non_public_post().
	 *
	 * @param string $status Post status slug.
	 * @return bool True when the status is publicly viewable.
	 */
	protected function is_public_status( $status ) {
		$status_obj = get_post_status_object( (string) $status );
		return $status_obj && ! empty( $status_obj->public );
	}

	/**
	 * Whether the current caller may access non-public content.
	 *
	 * Non-public content (draft, pending, private, etc.) is limited to keys
	 * carrying write scope or higher (write or admin). A read-only key — or a
	 * request with no matched key record — is treated as unprivileged and sees
	 * published content only. Trusted in-dashboard admin-AJAX callers (admin
	 * capability plus a valid nonce) are also allowed, matching the plugin's
	 * other privileged read gates.
	 *
	 * @return bool True when the caller may access non-public content.
	 */
	protected function requester_can_see_non_public() {
		$key_record = $this->get_current_api_key_record();
		if ( $key_record && $this->key_has_scope( $key_record, 'write' ) ) {
			return true;
		}

		return $this->is_trusted_admin_ajax_request();
	}

	/**
	 * Status-parity guard for a resolved post on a read surface.
	 *
	 * Returns a 404 WP_Error when the post is non-public and the caller lacks
	 * write scope (or trusted admin-AJAX context); returns null when access is
	 * allowed. The 404 is intentionally indistinguishable from a genuinely
	 * missing post so the response never discloses that a hidden draft/pending/
	 * private post exists. Callers pass the exact not-found message their
	 * surface already uses so the parity is byte-for-byte.
	 *
	 * @param WP_Post|mixed $post    Resolved post object.
	 * @param string        $message Surface-specific "not found" message.
	 * @return WP_Error|null WP_Error(404) when blocked, null when allowed.
	 */
	protected function guard_non_public_post( $post, $message ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		if ( $this->is_public_status( $post->post_status ) || $this->requester_can_see_non_public() ) {
			return null;
		}

		// Build the 404 inline rather than via $this->error_response() so this
		// guard has no dependency on the base REST controller — the trait is
		// also composed into non-controller classes. Mirrors error_response()'s
		// hint enrichment so the response is identical to a genuine not-found.
		$error = new WP_Error( 'not_found', $message, array( 'status' => 404 ) );
		if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
			$error = Mcpwp_Error_Hints::enhance_error( $error );
		}

		return $error;
	}

	/**
	 * Verify API key from request.
	 *
	 * Accepts both current mcpwp_ keys (from mcpwp_api_keys / mcpwp_api_key)
	 * and legacy spai_ keys (from spai_api_keys / spai_api_key) so that
	 * existing AI connections continue to work through the 2.8.x → v3 cutover
	 * without requiring customers to re-key.  See find_legacy_spai_key().
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	public function verify_api_key( $request ) {
		// WordPress core re-runs every handler's permission_callback on the
		// matched route to build the Allow header (rest_send_allow_header on
		// rest_post_dispatch). /mcp has three handlers, so this ran three
		// times per request and charged three rate-limit slots — the
		// advertised 1000/hour was really ~333 (#802). The verdict for one
		// request object is computed once and reused.
		if ( is_object( $request ) ) {
			$memo_id = spl_object_id( $request );
			if ( isset( self::$mcpwp_verdicts[ $memo_id ] ) && self::$mcpwp_verdicts[ $memo_id ][0] === $request ) {
				return self::$mcpwp_verdicts[ $memo_id ][1];
			}

			$verdict = $this->compute_api_key_verdict( $request );

			// Holding the request keeps its object id from being reused for
			// another request within this process. Bounded: a long-lived
			// worker looping rest_do_request() must not retain every body.
			if ( count( self::$mcpwp_verdicts ) >= 64 ) {
				// Not array_shift(): it renumbers the object-id keys.
				unset( self::$mcpwp_verdicts[ array_key_first( self::$mcpwp_verdicts ) ] );
			}
			self::$mcpwp_verdicts[ $memo_id ] = array( $request, $verdict );

			return $verdict;
		}

		return $this->compute_api_key_verdict( $request );
	}

	/**
	 * Memoised verdicts, keyed by request object id.
	 *
	 * @var array<int, array{0:object,1:mixed}>
	 */
	private static $mcpwp_verdicts = array();

	/**
	 * Forget memoised verdicts. Tests only; a real process serves one request.
	 */
	public static function reset_verdict_memo() {
		self::$mcpwp_verdicts = array();
	}

	/**
	 * Diagnostic bucket ceilings (#802): small and absolute. Diagnostics stay
	 * reachable while the write budget is exhausted, and cannot be used to
	 * double a key's throughput.
	 *
	 * @return array
	 */
	protected function diagnostic_rate_limits( $key_record = null ) {
		$limits = array(
			'burst'      => 15,
			'per_minute' => 30,
			'per_hour'   => 300,
		);

		// The allowance is a floor for exhausted keys, never a lift above a
		// deliberate throttle — the key's own ceilings, or the site's.
		$ceilings = array();
		if ( class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			$site     = Mcpwp_Rate_Limiter::get_instance()->get_settings();
			$ceilings = array(
				'burst'      => isset( $site['burst_limit'] ) ? (int) $site['burst_limit'] : 0,
				'per_minute' => isset( $site['requests_per_minute'] ) ? (int) $site['requests_per_minute'] : 0,
				'per_hour'   => isset( $site['requests_per_hour'] ) ? (int) $site['requests_per_hour'] : 0,
			);
		}
		if ( is_array( $key_record ) && ! empty( $key_record['rate_limits'] ) && is_array( $key_record['rate_limits'] ) ) {
			foreach ( $key_record['rate_limits'] as $name => $value ) {
				if ( (int) $value > 0 ) {
					$ceilings[ $name ] = isset( $ceilings[ $name ] ) && $ceilings[ $name ] > 0 ? min( $ceilings[ $name ], (int) $value ) : (int) $value;
				}
			}
		}
		foreach ( $limits as $name => $value ) {
			if ( ! empty( $ceilings[ $name ] ) ) {
				$limits[ $name ] = min( $value, (int) $ceilings[ $name ] );
			}
		}

		return array( 'rate_limits' => $limits );
	}

	/**
	 * Identifier, method and key record of the last meter_request() call.
	 *
	 * @var array|null
	 */
	private $mcpwp_metered = null;

	/**
	 * Charge slots on top of the one the HTTP request already paid (#802).
	 *
	 * @param int $slots How many more.
	 * @return true|WP_Error
	 */
	protected function charge_additional_slots( $slots ) {
		$slots = (int) $slots;

		if ( $slots < 1 ) {
			return true;
		}

		if ( ! is_array( $this->mcpwp_metered ) ) {
			// Never metered (internal dispatch, admin chat): nothing to charge against.
			return true;
		}

		list( $identifier, $method, $key_record ) = $this->mcpwp_metered;

		return $this->check_rate_limit( $identifier, $method, $key_record, $slots );
	}

	/**
	 * Meter one authenticated request (#802).
	 *
	 * The single place that decides which bucket a request is charged to and
	 * how much. Every authentication path — API key, OAuth token, broker
	 * token — goes through here so they cannot drift.
	 *
	 * @param WP_REST_Request $request     Request.
	 * @param string          $identifier  Caller's bucket identifier.
	 * @param string          $http_method HTTP method.
	 * @param array|null      $key_record  Key record with optional per-key ceilings.
	 * @return bool|WP_Error
	 */
	protected function meter_request( $request, $identifier, $http_method = 'POST', $key_record = null ) {
		$weight = $this->request_rate_weight( $request );

		// Remembered so a tool that turns out to carry N operations can be
		// charged the other N-1 before it runs (charge_additional_slots).
		$this->mcpwp_metered = array( $identifier, $http_method, $key_record );

		if ( ! $this->is_diagnostic_request( $request ) ) {
			return $this->check_rate_limit( $identifier, $http_method, $key_record, $weight );
		}

		$check = $this->check_rate_limit( $identifier . ':diag', 'POST', $this->diagnostic_rate_limits( $key_record ), $weight );

		// The budget the caller is asking about is the write budget, not the
		// diagnostic slot just spent. Report that one.
		if ( ! is_wp_error( $check ) && class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			Mcpwp_Rate_Limiter::get_instance()->report_bucket( $identifier, $http_method, $key_record );
		}

		return $check;
	}

	/**
	 * The actual verification. See verify_api_key() for why it is wrapped.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	private function compute_api_key_verdict( $request ) {
		// A persistent process (WP-CLI, a rest_do_request() loop) reuses this
		// instance across requests; a nested charge must never land on the
		// previous request's bucket.
		$this->mcpwp_metered = null;

		// Bypass for internal server-side requests (Chat tab tool execution).
		// Requires: admin context + activate_plugins cap + AJAX request +
		// a valid nonce tied to a known admin action (prevents filter-hook abuse).
		// The open apply_filters() bypass was removed: any code that added a filter
		// returning true could silently skip API-key auth for all requests.
		if ( $this->is_trusted_admin_ajax_request() ) {
			return true;
		}

		// Skip rate limiting for batch sub-requests (already counted on the outer request).
		// Use a PHP static property — not an HTTP header — so external callers cannot
		// forge this bypass.  The flag is set/cleared by Mcpwp_REST_Batch::execute_operation()
		// and by Mcpwp_REST_MCP around its own internal sub-request dispatch.
		if ( class_exists( 'Mcpwp_REST_Batch' ) && Mcpwp_REST_Batch::$is_batch_sub_request ) {
			return $this->verify_api_key_auth_only( $request );
		}

		$http_method = method_exists( $request, 'get_method' ) ? $request->get_method() : 'POST';
		$api_key     = $this->get_api_key_from_request( $request );

		if ( empty( $api_key ) ) {
			$rate_limit_check = $this->check_rate_limit( 'missing:' . $this->get_client_ip(), $http_method );
			if ( is_wp_error( $rate_limit_check ) ) {
				if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
					Mcpwp_Error_Hints::enhance_error( $rate_limit_check );
				}
				return $rate_limit_check;
			}

			return new WP_Error(
				'missing_api_key',
				__( 'API key is required.', 'mcpwp' ),
				array(
					'status' => 401,
					'hint'   => 'Include your API key in the X-API-Key header or Authorization: Bearer header. Generate keys in WP Admin > MCPWP > Settings, or call wp_create_api_key if you have admin access.',
				)
			);
		}

		if ( $this->looks_like_oauth_access_token( $api_key ) ) {
			return $this->authenticate_oauth_access_token( $api_key, $request );
		}

		$matched_key = $this->find_scoped_api_key( $api_key );
		$legacy_key  = get_option( 'mcpwp_api_key' );

		if ( ! $matched_key && ! empty( $legacy_key ) && $this->is_api_key_match( $api_key, $legacy_key ) ) {
			// Auto-migrate legacy plain text keys to hashed storage.
			if ( hash_equals( $legacy_key, $api_key ) ) {
				$legacy_key = wp_hash_password( $api_key );
				update_option( 'mcpwp_api_key', $legacy_key );
			}

			$matched_key = $this->migrate_legacy_key_to_scoped_store( $legacy_key );
		}

		// Bridge: also accept keys stored under the old spai_* option names.
		// This allows customers on 2.8.x to keep their existing API keys working
		// through the folder-rename cutover without re-generating keys.
		// find_legacy_spai_key() is constant-time / hashed-compare identical to
		// find_scoped_api_key().
		if ( ! $matched_key ) {
			$matched_key = $this->find_legacy_spai_key( $api_key );
		}

		if ( ! $matched_key ) {
			// mumcp.com broker OAuth Bearer token — FALLBACK only, reached ONLY
			// after no local API key (scoped, legacy mcpwp_, or bridged spai_)
			// matched the presented credential. Ordering matters for two reasons:
			//   1. Secret egress: the plugin also accepts API keys on the
			//      Authorization: Bearer header (get_api_key_from_request), so a
			//      real API key must be resolved locally above and NEVER shipped
			//      to mumcp.com as an introspection token.
			//   2. Correctness: a valid local key must authenticate, not be
			//      rejected because introspection intercepted it first.
			// Only ever attempted for a genuine Authorization: Bearer header with
			// no X-API-Key header (never the ?api_key= query fallback), and only
			// when the site is actively enrolled — the kill-switch inside
			// Mcpwp_Broker_Auth::is_enrolled() means an unenrolled/disabled site
			// makes NO network call and falls straight through to the unchanged
			// invalid-key handling below.
			$auth_header        = method_exists( $request, 'get_header' ) ? $request->get_header( 'Authorization' ) : null;
			$has_bearer_header  = ! empty( $auth_header ) && 0 === strpos( (string) $auth_header, 'Bearer ' );
			$has_api_key_header = method_exists( $request, 'get_header' ) && ! empty( $request->get_header( 'X-API-Key' ) );

			if ( $has_bearer_header && ! $has_api_key_header && class_exists( 'Mcpwp_Broker_Auth' ) ) {
				$broker_auth = new Mcpwp_Broker_Auth();
				if ( $broker_auth->is_enrolled() ) {
					return $this->authenticate_broker_bearer_token( $api_key, $request, $broker_auth );
				}
			}

			$has_configured_keys = $this->has_configured_api_keys( $legacy_key );
			$rate_identifier     = $has_configured_keys ? 'invalid:' . $this->get_client_ip() : 'unconfigured:' . $this->get_client_ip();
			$rate_limit_check    = $this->check_rate_limit( $rate_identifier, $http_method );

			if ( is_wp_error( $rate_limit_check ) ) {
				return $rate_limit_check;
			}

			if ( ! $has_configured_keys ) {
				return new WP_Error(
					'api_not_configured',
					__( 'API key not configured. Please visit the MCPWP settings.', 'mcpwp' ),
					array(
						'status' => 500,
						'hint'   => 'No API keys have been configured yet. The site admin needs to visit WP Admin > MCPWP > Settings to generate an API key.',
					)
				);
			}

			$this->log_auth_failure( $request );

			return new WP_Error(
				'invalid_api_key',
				__( 'Invalid API key.', 'mcpwp' ),
				array(
					'status' => 401,
					'hint'   => 'The provided API key is not valid. Check for typos or whitespace. Keys start with "mcpwp_". Generate a new key in WP Admin > MCPWP > Settings.',
				)
			);
		}

		$rate_identifier = ! empty( $matched_key['id'] )
			? 'key:' . sanitize_key( $matched_key['id'] )
			: 'key:' . hash( 'sha256', $api_key );

		$rate_limit_check = $this->meter_request( $request, $rate_identifier, $http_method, $matched_key );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		$required_scope = $this->get_required_scope_for_request( $request );
		if ( ! $this->key_has_scope( $matched_key, $required_scope ) ) {
			$granted = isset( $matched_key['scopes'] ) ? $matched_key['scopes'] : array();
			return new WP_Error(
				'insufficient_scope',
				sprintf(
					/* translators: %s: scope name */
					__( 'API key lacks required scope: %s', 'mcpwp' ),
					$required_scope
				),
				array(
					'status'         => 403,
					'required_scope' => $required_scope,
					'granted_scopes' => $granted,
					'hint'           => sprintf(
						'This API key has scopes [%s] but needs "%s". Request a key with the required scope from the site admin, or use wp_create_api_key to create one with appropriate scopes.',
						implode( ', ', $granted ),
						$required_scope
					),
				)
			);
		}

		// Store the matched key for downstream use (MCP tool filtering, etc.).
		$this->current_api_key_record = $matched_key;

		if ( ! empty( $matched_key['id'] ) ) {
			$this->touch_api_key_last_used( $matched_key['id'] );
		}

		if ( ! $this->set_api_user_context() ) {
			return new WP_Error(
				'api_user_missing',
				__( 'API user context is not configured. Re-activate MCPWP to provision the service account.', 'mcpwp' ),
				array(
					'status' => 500,
					'hint'   => 'The plugin\'s internal service account is missing. Deactivate and reactivate the MCPWP plugin in WP Admin > Plugins to reprovision it.',
				)
			);
		}

		// Block direct REST calls to routes whose tool category has been disabled
		// by the site administrator via WP Admin > mcpwp > Tools.
		// This mirrors the MCP-layer check in Mcpwp_REST_MCP::handle_tools_call()
		// so that callers cannot bypass the category toggle by hitting the raw
		// REST endpoint instead of going through the MCP protocol.
		$category_check = $this->check_disabled_category_for_route( $request );
		if ( is_wp_error( $category_check ) ) {
			return $category_check;
		}

		return true;
	}

	/**
	 * Map a REST route to the tool-category it belongs to.
	 *
	 * INVARIANT (enforced by CategoryTaxonomyInvariantTest): every category this
	 * classifier can return MUST exist in Mcpwp_Tools_Admin::get_category_meta().
	 * A category the map emits but the admin UI cannot toggle is un-gateable — the
	 * route can never be blocked by any configuration, which is a silent net-widening
	 * of the gate. Best-effort second property (NOT test-enforced): where a route IS
	 * category-gated, its category SHOULD match the MCP tool-category of the tool that
	 * dispatches to it (free + pro registries, defaulting to 'site' for a tool in
	 * neither), because MCP tools dispatch through rest_do_request() and pass through
	 * this gate — if the two taxonomies disagree the MCP layer says "callable" while
	 * this gate answers 403, indistinguishable from a broken endpoint. This alignment
	 * is intentionally partial: many routes return '' (ungated) here — e.g. /settings,
	 * /site-info, /menus, /users — and are governed only by their MCP-tool category, so
	 * a returned category matching the tool taxonomy is a goal, not a guarantee. When
	 * adding a route, add its prefix here, finer prefixes BEFORE coarser ones (first
	 * match wins, iteration is insertion order).
	 *
	 * NOTE on /events: the free event-store endpoints (GET /events, GET /events/schema)
	 * are classified 'webhooks' at the REST layer. The Pro events tools keep the 'events'
	 * MCP-tool category — a first-class toggle present in get_category_meta() that hides
	 * them from tools/list when disabled. One REST prefix cannot serve two taxonomies, so
	 * the split (REST '/events' => 'webhooks' vs the 'events' MCP-tool category) is
	 * deliberate, not drift. The Pro plugin does register its own /events and /events/{id}
	 * routes; those fall under the same '/events' prefix and are gated as 'webhooks' here.
	 *
	 * @param string $route Full REST route, e.g. /mcpwp/v1/pages.
	 * @return string Category slug, or '' if not category-gated.
	 */
	protected function get_category_for_route( $route ) {
		$base = '/mcpwp/v1';
		$path = ( 0 === strpos( $route, $base ) ) ? substr( $route, strlen( $base ) ) : $route;

		// Path-parameter routes whose category cannot be expressed as a leading prefix.
		foreach ( $this->get_route_pattern_map() as $pattern => $pattern_category ) {
			if ( preg_match( $pattern, $path ) ) {
				return $pattern_category;
			}
		}

		// Longest/most-specific prefixes first; first match wins.
		foreach ( $this->get_route_category_map() as $prefix => $category ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return $category;
			}
		}

		return ''; // Not governed by any category toggle.
	}

	/**
	 * Regex route→category tier, checked before the prefix map. For routes whose
	 * distinguishing segment follows a path parameter (e.g. /pages/{id}/template).
	 *
	 * @return array<string,string> Regex pattern => category slug.
	 */
	protected function get_route_pattern_map() {
		return array(
			// wp_update_page_template is a 'site' tool (Mcpwp_MCP_Free_Tools), but its
			// route lives under /pages/{id}/ which the prefix map would claim as 'content'.
			// The id segment is restricted to digits so this only claims the numeric-id
			// template route (/pages/5/template). Non-numeric segments such as
			// /pages/by-slug/template are a content read and must fall through to
			// /pages => 'content' rather than being mis-gated as 'site'.
			'#^/pages/\d+/template(/|$)#' => 'site',
		);
	}

	/**
	 * Prefix route→category map. Ordered most-specific first.
	 *
	 * @return array<string,string> Route prefix => category slug.
	 */
	protected function get_route_category_map() {
		return array(
			// Elementor info (status, widget schemas). Before generic /elementor.
			'/elementor/status'                     => 'elementor-info',
			'/elementor/widgets'                    => 'elementor-info',
			'/elementor/widget-help'                => 'elementor-info',
			'/elementor/regenerate-css'             => 'elementor-info',

			// Elementor templates, archetypes, reusable parts (Pro).
			'/elementor/templates'                  => 'elementor-templates',
			'/elementor/archetypes'                 => 'elementor-templates',
			'/elementor/parts'                      => 'elementor-templates',

			// Elementor page-building helpers (Pro).
			'/elementor/blueprints'                 => 'elementor-build',
			'/elementor/build-page'                 => 'elementor-build',
			'/elementor/clone'                      => 'elementor-build',
			'/elementor/globals'                    => 'elementor-build',
			'/elementor/landing-page'               => 'elementor-build',
			'/elementor/save-section-as-template'   => 'elementor-build',

			// Elementor theme builder / custom code (Pro).
			'/elementor/custom-code'                => 'elementor-theme',

			// Elementor build (read/write page data) — catch-all; stays below every
			// finer /elementor prefix above.
			'/elementor'                            => 'elementor',

			// Block utilities absent from the MCP tool-category map (default 'site' at
			// the MCP layer). They must resolve 'site' here too, or disabling Gutenberg
			// would 403 tools that tools/list still advertises under Site.
			'/blocks/design-system'                 => 'site',
			'/blocks/parse'                         => 'site',
			'/blocks/serialize'                     => 'site',
			'/blocks/validate'                      => 'site',

			// Gutenberg blocks.
			'/blocks'                               => 'gutenberg',

			// SEO. /content-coherence MUST precede /content, which would otherwise
			// swallow it and gate an SEO tool behind the Content toggle.
			'/content-coherence'                    => 'seo',
			'/seo'                                  => 'seo',

			// Content.
			'/pages'                                => 'content',
			'/posts'                                => 'content',
			'/content'                              => 'content',
			'/drafts'                               => 'content',
			'/batch'                                => 'content',
			'/post-meta'                            => 'content',

			// Media.
			'/media'                                => 'media',
			'/screenshot'                           => 'media',

			// Taxonomy.
			'/categories'                           => 'taxonomy',
			'/tags'                                 => 'taxonomy',
			'/terms'                                => 'taxonomy',

			// Webhooks + MCP event store (see /events note in the method docblock).
			'/webhooks'                             => 'webhooks',
			'/events'                               => 'webhooks',

			// AI generation / stock-photo routes live under /integrations/*
			// but are MCP category 'ai', NOT integration management. They must resolve
			// 'ai' here, ahead of the generic /integrations => 'admin' below, so the REST
			// gate agrees with the tool taxonomy. All are still gateable ('ai' is in
			// get_category_meta()), so this realignment does not net-widen — it removes a
			// mismatch, not a control.
			'/integrations/stock-photos'            => 'ai',
			'/integrations/generate-image'          => 'ai',
			'/integrations/generate-featured-image' => 'ai',
			'/integrations/generate-alt-text'       => 'ai',
			'/integrations/describe-image'          => 'ai',
			'/integrations/generate-excerpt'        => 'ai',
			'/integrations/text-to-speech'          => 'ai',

			// Admin. Integration MANAGEMENT (status/configure/remove/test) stays 'admin';
			// settings/options are intentionally not gated (they carry the 'admin' scope).
			'/approvals'                            => 'admin',
			'/api-keys'                             => 'admin',
			'/rate-limit'                           => 'admin',
			'/integrations'                         => 'admin',
			'/feedback'                             => 'admin',

			// Granular plugin/admin families that the admin UI can toggle (all present
			// in get_category_meta()) but whose REST routes previously classified as ''
			// (ungated) — so disabling e.g. WooCommerce still let a direct call to
			// /woocommerce/* through. Each category here matches the dispatching MCP
			// tool's category, so the REST gate and tools/list agree. None of these
			// prefixes is a prefix of another and none shadows a finer route above, so
			// placing them at the end keeps the existing first-match ordering intact.
			'/woocommerce'                          => 'woocommerce',
			'/learnpress'                           => 'learnpress',
			'/network'                              => 'multisite',
			'/staging'                              => 'staging',
			'/theme-builder'                        => 'elementor-theme',
			'/forms'                                => 'forms',
			'/keyword-research'                     => 'seo',

			// wp_search is a 'content' tool; wp_list_block_types / wp_list_block_patterns
			// are 'gutenberg'. /block-types and /block-patterns do NOT begin with the
			// /blocks prefix above, so they need their own entries.
			'/search'                               => 'content',
			'/block-types'                          => 'gutenberg',
			'/block-patterns'                       => 'gutenberg',

			// Figma tools and wp_list_page_templates are 'site' tools.
			'/figma'                                => 'site',
			'/templates/page'                       => 'site',
		);
	}

	/**
	 * Codomain of get_category_for_route(): every category it can emit, from the same
	 * two maps the classifier uses (single source of truth for the invariant test).
	 *
	 * @return string[] Distinct category slugs the route classifier can return.
	 */
	protected function get_all_route_categories() {
		return array_values(
			array_unique(
				array_merge(
					array_values( $this->get_route_pattern_map() ),
					array_values( $this->get_route_category_map() )
				)
			)
		);
	}

	/**
	 * Check whether the route for the current request belongs to a
	 * site-administrator-disabled tool category.
	 *
	 * Called from verify_api_key() after authentication succeeds, so it only
	 * runs for valid, authenticated requests. Returns a WP_Error (HTTP 403)
	 * when the category is disabled; returns true otherwise.
	 *
	 * Note: ALL methods (GET and write) are blocked when a category is disabled.
	 * This mirrors the MCP layer, which removes disabled-category tools from
	 * tools/list entirely — both reads and writes are suppressed.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return true|WP_Error True when allowed; WP_Error when category is disabled.
	 */
	protected function check_disabled_category_for_route( $request ) {
		$disabled_categories = get_option( 'mcpwp_disabled_tool_categories', array() );

		// Fast path: nothing is disabled.
		if ( empty( $disabled_categories ) || ! is_array( $disabled_categories ) ) {
			return true;
		}

		$route    = $this->normalize_route_for_authorization( method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '' );
		$category = $this->get_category_for_route( $route );

		// Route is not category-gated — always allow.
		if ( '' === $category ) {
			return true;
		}

		if ( in_array( $category, $disabled_categories, true ) ) {
			return new WP_Error(
				'forbidden_category',
				sprintf(
					/* translators: %s: tool category name */
					__( 'The "%s" tool category has been disabled by the site administrator.', 'mcpwp' ),
					$category
				),
				array(
					'status'   => 403,
					'category' => $category,
					'hint'     => 'This tool category has been disabled in WP Admin > mcpwp > Tools. Contact the site administrator to re-enable it.',
				)
			);
		}

		return true;
	}

	/**
	 * Verify API key without rate limiting (for batch sub-requests).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True if valid, error otherwise.
	 */
	protected function verify_api_key_auth_only( $request ) {
		$api_key = $this->get_api_key_from_request( $request );

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'missing_api_key',
				__( 'API key is required.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		$matched_key = $this->find_scoped_api_key( $api_key );
		$legacy_key  = get_option( 'mcpwp_api_key' );

		if ( ! $matched_key && ! empty( $legacy_key ) && $this->is_api_key_match( $api_key, $legacy_key ) ) {
			$matched_key = $this->migrate_legacy_key_to_scoped_store( $legacy_key );
		}

		// Bridge: also accept keys from the old spai_* option store.
		if ( ! $matched_key ) {
			$matched_key = $this->find_legacy_spai_key( $api_key );
		}

		if ( ! $matched_key ) {
			return new WP_Error(
				'invalid_api_key',
				__( 'Invalid API key.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		$required_scope = $this->get_required_scope_for_request( $request );
		if ( ! $this->key_has_scope( $matched_key, $required_scope ) ) {
			return new WP_Error(
				'insufficient_scope',
				sprintf(
					/* translators: %s: scope name */
					__( 'API key lacks required scope: %s', 'mcpwp' ),
					$required_scope
				),
				array( 'status' => 403 )
			);
		}

		// Persist the matched key so downstream identity/role checks (self-approval,
		// per-key tool-category enforcement) see the real requester even on
		// batch/MCP sub-requests.
		$this->current_api_key_record = $matched_key;

		if ( ! $this->set_api_user_context() ) {
			return new WP_Error(
				'api_user_missing',
				__( 'API user context is not configured.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// Site-administrator tool-category toggle, same as the full verify_api_key()
		// tail. This function is the batch / MCP sub-request fast path
		// (Mcpwp_REST_Batch::$is_batch_sub_request), and it skipped the gate
		// entirely — so /batch with an operation targeting a disabled category
		// succeeded even though the identical call to the same route as a top-level
		// request returned 403. Wrapping a blocked route in a batch envelope must
		// not launder it.
		//
		// Only rate limiting is legitimately skipped on this path (already counted
		// on the outer request); authorisation decisions are not.
		$category_check = $this->check_disabled_category_for_route( $request );
		if ( is_wp_error( $category_check ) ) {
			return $category_check;
		}

		return true;
	}

	/**
	 * Check rate limit for current request.
	 *
	 * @param string $identifier Rate-limit bucket identifier.
	 * @param string $method     HTTP method (GET requests get higher limits).
	 * @return bool|WP_Error True if allowed, error if rate limited.
	 */
	/**
	 * Whether a request is purely diagnostic and belongs in the diagnostic
	 * rate-limit bucket rather than the caller's write bucket (#802).
	 *
	 * Default: nothing is. Controllers that own a diagnostic surface override
	 * this and must answer true only when EVERY operation in the request is
	 * diagnostic — one write beside an initialize makes the whole request a
	 * write, or the exemption is a bypass.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	protected function is_diagnostic_request( $request ) {
		return false;
	}

	/**
	 * How many rate-limit slots this request costs (#802).
	 *
	 * One, unless a controller carries several operations in one HTTP
	 * request — the JSON-RPC batch and REST /batch override this to charge
	 * per operation, so a bigger batch cap does not make the budget fiction.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int
	 */
	protected function request_rate_weight( $request ) {
		return 1;
	}

	protected function check_rate_limit( $identifier = null, $method = 'POST', $key_record = null, $weight = 1 ) {
		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return true;
		}

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		return $limiter->check_limit( $identifier, $method, $key_record, $weight );
	}

	/**
	 * Get API key from request.
	 *
	 * Checks header first.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string|null API key or null.
	 */
	protected function get_api_key_from_request( $request ) {
		// Check header first (X-API-Key)
		$api_key = $request->get_header( 'X-API-Key' );

		if ( ! empty( $api_key ) ) {
			return sanitize_text_field( $api_key );
		}

		// Check Authorization header (Bearer token)
		$auth_header = $request->get_header( 'Authorization' );
		if ( ! empty( $auth_header ) && 0 === strpos( $auth_header, 'Bearer ' ) ) {
			return sanitize_text_field( substr( $auth_header, 7 ) );
		}

		// Check query parameter (for Claude Desktop custom connectors and similar
		// MCP clients that don't support custom headers).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['api_key'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_text_field( wp_unslash( $_GET['api_key'] ) );
		}

		return null;
	}

	/**
	 * Check whether a token looks like a generated OAuth access token.
	 *
	 * Accepts both the v3 prefix (mcpwp_at_) and the legacy 2.8.x prefix
	 * (spai_at_) so that OAuth-connected clients (Claude Desktop, ChatGPT)
	 * continue to work through the slug-rename cutover without needing to
	 * re-authenticate.  The token lookup logic in authenticate_oauth_access_token
	 * handles the transient-key difference (spai_ tokens were stored under
	 * spai_oauth_token_<md5>; v3 stores under mcpwp_oauth_token_<md5>).
	 *
	 * @param string $token Access token.
	 * @return bool True when token has an OAuth prefix (v3 or legacy).
	 */
	protected function looks_like_oauth_access_token( $token ) {
		$token = (string) $token;
		return 0 === strpos( $token, 'mcpwp_at_' ) || 0 === strpos( $token, 'spai_at_' );
	}

	/**
	 * Validate and authenticate OAuth bearer access token.
	 *
	 * Handles both v3 tokens (mcpwp_at_) and legacy 2.8.x tokens (spai_at_).
	 * 2.8.56 stored OAuth tokens under the transient key spai_oauth_token_<md5(token)>.
	 * v3 stores them under mcpwp_oauth_token_<md5(token)>.  Because the md5 is of
	 * the full token string (including its prefix), the transient key differs and
	 * we must use the correct prefix-aware lookup.  For spai_at_ tokens we first
	 * check the legacy spai_oauth_token_ transient namespace.  OAuth must be
	 * enabled on this site for either path to work.
	 *
	 * @param string          $token   Access token.
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error True when valid, error otherwise.
	 */
	protected function authenticate_oauth_access_token( $token, $request ) {
		$oauth_settings = $this->get_oauth_settings();
		if ( empty( $oauth_settings['oauth_enabled'] ) ) {
			return new WP_Error(
				'invalid_api_key',
				__( 'Invalid API key.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		// For legacy spai_at_ tokens we check the legacy transient namespace first.
		// 2.8.56 used 'spai_oauth_token_' . md5($token); v3 uses 'mcpwp_oauth_token_' . md5($token).
		// We try the legacy key first when the token has the spai_ prefix, then fall
		// through to the standard path in case it was already re-issued under v3 naming.
		$record = false;
		if ( 0 === strpos( (string) $token, 'spai_at_' ) ) {
			$legacy_transient_key = 'spai_oauth_token_' . md5( (string) $token );
			$record               = get_transient( $legacy_transient_key );
		}
		if ( ! is_array( $record ) || empty( $record['scopes'] ) ) {
			$record = get_transient( $this->get_oauth_token_transient_key( $token ) );
		}

		if ( ! is_array( $record ) || empty( $record['scopes'] ) ) {
			$this->log_auth_failure( $request );
			return new WP_Error(
				'invalid_api_key',
				__( 'Invalid API key.', 'mcpwp' ),
				array( 'status' => 401 )
			);
		}

		$rate_limit_check = $this->meter_request( $request, 'oauth:' . substr( hash( 'sha256', (string) $token ), 0, 16 ), method_exists( $request, 'get_method' ) ? $request->get_method() : 'POST' );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		// Give the OAuth principal a stable, non-empty identity so downstream
		// separation-of-duties checks (check_self_approval) treat it as a real
		// remote caller rather than the id-less admin-AJAX human context. Prefer
		// the consenting user, then the client, then a token-derived fallback for
		// legacy tokens issued before the identity was persisted.
		if ( isset( $record['user_id'] ) && (int) $record['user_id'] > 0 ) {
			$principal_id = 'oauth-user:' . (int) $record['user_id'];
		} elseif ( ! empty( $record['client_id'] ) ) {
			$principal_id = 'oauth-client:' . sanitize_key( (string) $record['client_id'] );
		} else {
			$principal_id = 'oauth:' . substr( hash( 'sha256', (string) $token ), 0, 16 );
		}

		$key_record = array(
			'id'     => $principal_id,
			'type'   => 'oauth',
			'scopes' => $this->sanitize_scopes( (array) $record['scopes'] ),
		);
		$this->current_api_key_record = $key_record;
		$required_scope               = $this->get_required_scope_for_request( $request );

		if ( ! $this->key_has_scope( $key_record, $required_scope ) ) {
			return new WP_Error(
				'insufficient_scope',
				sprintf(
					/* translators: %s: scope name */
					__( 'API key lacks required scope: %s', 'mcpwp' ),
					$required_scope
				),
				array(
					'status'         => 403,
					'required_scope' => $required_scope,
					'granted_scopes' => $key_record['scopes'],
				)
			);
		}

		if ( ! $this->set_api_user_context() ) {
			return new WP_Error(
				'api_user_missing',
				__( 'API user context is not configured. Re-activate MCPWP to provision the service account.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// Site-administrator tool-category toggle — the same gate the X-API-Key
		// path applies at the end of verify_api_key() and the broker path applies
		// at the end of authenticate_broker_bearer_token().
		//
		// Without it, an OAuth bearer principal could reach a disabled category by
		// calling the REST route directly: the MCP layer refuses the equivalent
		// tool with -32003, so the toggle *looked* effective while the raw route
		// stayed wide open. A toggle that only holds on one of two entry points is
		// not a control.
		//
		// Placement is deliberate: after the token lookup, the rate-limit check and
		// the scope check. Running it earlier would let an unauthenticated caller
		// tell 403 "category disabled" apart from 401 and enumerate the site's
		// disabled categories without presenting a credential.
		$category_check = $this->check_disabled_category_for_route( $request );
		if ( is_wp_error( $category_check ) ) {
			return $category_check;
		}

		return true;
	}

	/**
	 * Authenticate a mumcp.com broker-issued opaque Bearer token.
	 *
	 * Alternative to X-API-Key auth. Delegates validation to
	 * Mcpwp_Broker_Auth::validate_token() (cached RFC 7662-shaped introspection
	 * against mumcp.com, fail-closed) and, on success, builds a $key_record in
	 * the SAME shape find_scoped_api_key() / authenticate_oauth_access_token()
	 * produce — 'id' / 'type' / 'scopes' — so every downstream consumer
	 * (scope enforcement via key_has_scope(), requester_can_see_non_public(),
	 * the #683 self-approval separation-of-duties identity, rate limiting,
	 * disabled-tool-category enforcement) treats a broker principal exactly
	 * like any other authenticated caller.
	 *
	 * Admin is impossible via this path: Mcpwp_Broker_Auth::scope_to_capability()
	 * only ever returns 'read' or 'write' (never 'admin', even defensively if a
	 * future/compromised broker response claimed it), and sanitize_scopes() is
	 * only ever fed that single capability string here — there is no code path
	 * by which 'admin' could enter $key_record['scopes'] for a broker principal.
	 *
	 * @param string            $token       Opaque bearer token (Bearer prefix already stripped).
	 * @param WP_REST_Request   $request     Request object.
	 * @param Mcpwp_Broker_Auth $broker_auth Validator instance (caller already confirmed is_enrolled()).
	 * @return bool|WP_Error True when valid, error otherwise.
	 */
	protected function authenticate_broker_bearer_token( $token, $request, $broker_auth ) {
		// DoS guard: throttle anonymous introspection attempts per client IP
		// BEFORE the outbound network call, mirroring the invalid-key limiter
		// ('invalid:'.$ip). Without this a Bearer token that is not a local key
		// would trigger one unthrottled wp_remote_post (HTTP_TIMEOUT) per attempt
		// — worker exhaustion locally and amplification against the broker. This
		// bucket is keyed on IP (not the token) so rotating tokens can't evade it,
		// and it runs ahead of validate_token()'s own per-token 'broker:' bucket.
		$http_method      = method_exists( $request, 'get_method' ) ? $request->get_method() : 'POST';
		$attempt_limit    = $this->check_rate_limit( 'broker-attempt:' . $this->get_client_ip(), $http_method );
		if ( is_wp_error( $attempt_limit ) ) {
			return $attempt_limit;
		}

		$result = $broker_auth->validate_token( $token );
		if ( is_wp_error( $result ) ) {
			// Fail-closed: not enrolled (kill-switch), transport error, non-200,
			// active !== true, or audience/site_id mismatch — validate_token()
			// already shaped the WP_Error/status for each case.
			return $result;
		}

		$rate_limit_check = $this->meter_request( $request, 'broker:' . substr( hash( 'sha256', (string) $token ), 0, 16 ), method_exists( $request, 'get_method' ) ? $request->get_method() : 'POST' );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		// Cap capability at read/write. scope_to_capability() never returns
		// 'admin'; sanitize_scopes() is only ever given that single string, so
		// 'admin' can never appear in the resulting scopes array.
		$scope      = isset( $result['scope'] ) ? (array) $result['scope'] : array();
		$capability = $broker_auth->scope_to_capability( $scope );
		$scopes     = $this->sanitize_scopes( array( $capability ) );

		// Stable, non-empty identity in its own namespace for the #683
		// self-approval separation-of-duties gate (current_requester_key_id() /
		// check_self_approval()) — a broker principal is `broker:<sub>`,
		// distinct from `oauth-user:`, `oauth-client:`, the legacy `oauth:`
		// fallback, and scoped-key UUIDs, so it can never collide with (and
		// thus never satisfy "same identity" against) any other principal type.
		$sub          = isset( $result['sub'] ) && '' !== (string) $result['sub'] ? (string) $result['sub'] : '';
		$principal_id = '' !== $sub ? 'broker:' . $sub : 'broker:' . substr( hash( 'sha256', (string) $token ), 0, 16 );

		$key_record = array(
			'id'     => $principal_id,
			'type'   => 'broker',
			'scopes' => $scopes,
		);
		$this->current_api_key_record = $key_record;

		$required_scope = $this->get_required_scope_for_request( $request );
		if ( ! $this->key_has_scope( $key_record, $required_scope ) ) {
			return new WP_Error(
				'insufficient_scope',
				sprintf(
					/* translators: %s: scope name */
					__( 'API key lacks required scope: %s', 'mcpwp' ),
					$required_scope
				),
				array(
					'status'         => 403,
					'required_scope' => $required_scope,
					'granted_scopes' => $key_record['scopes'],
				)
			);
		}

		if ( ! $this->set_api_user_context() ) {
			return new WP_Error(
				'api_user_missing',
				__( 'API user context is not configured. Re-activate MCPWP to provision the service account.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// Same site-administrator category toggle enforcement the X-API-Key
		// path applies (verify_api_key() line ~282) — a broker principal must
		// not be able to reach a disabled tool category any more than a scoped
		// API key can.
		$category_check = $this->check_disabled_category_for_route( $request );
		if ( is_wp_error( $category_check ) ) {
			return $category_check;
		}

		return true;
	}

	/**
	 * Log authentication failure.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	protected function log_auth_failure( $request ) {
		$settings = get_option( 'mcpwp_settings', array() );

		if ( empty( $settings['enable_logging'] ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcpwp_activity_log';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'action'      => 'auth_failure',
				'endpoint'    => $request->get_route(),
				'method'      => $request->get_method(),
				'status_code' => 401,
				'ip_address'  => $this->get_client_ip(),
				'user_agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get client IP address.
	 *
	 * @return string IP address.
	 */
	protected function get_client_ip() {
		// Proxy-supplied client-IP headers are trivially spoofable, so an
		// attacker could otherwise mint a fresh rate-limit/auth-throttle bucket
		// (or forge a whitelisted IP) per request. Only trust them behind an
		// explicit opt-in — the same MCPWP_TRUST_PROXY_HEADERS gate the OAuth
		// well-known scheme check uses; otherwise fall back to REMOTE_ADDR, which
		// the web server controls.
		$trust_proxy = defined( 'MCPWP_TRUST_PROXY_HEADERS' ) && MCPWP_TRUST_PROXY_HEADERS;

		$ip_keys = $trust_proxy
			? array(
				'HTTP_CF_CONNECTING_IP', // Cloudflare
				'HTTP_X_FORWARDED_FOR',  // Proxy
				'HTTP_X_REAL_IP',        // Nginx
				'REMOTE_ADDR',           // Standard
			)
			: array( 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				// Handle comma-separated IPs (X-Forwarded-For)
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return 'unknown';
	}

	/**
	 * Set the current user context for API requests.
	 *
	 * Sets the current user to the dedicated API agent role
	 * so that capability checks work correctly.
	 */
	protected function set_api_user_context() {
		// Try to find a user with the mcpwp_api_agent role
		$users = get_users( array(
			'role'    => 'mcpwp_api_agent',
			'number'  => 1,
			'orderby' => 'ID',
			'order'   => 'ASC',
		) );

		if ( ! empty( $users ) ) {
			$user = $users[0];

			// Multisite: ensure the bot user is a member of the current blog.
			if ( function_exists( 'is_multisite' ) && is_multisite() && ! is_user_member_of_blog( $user->ID, get_current_blog_id() ) ) {
				add_user_to_blog( get_current_blog_id(), $user->ID, 'mcpwp_api_agent' );
			}

			wp_set_current_user( $user->ID );
			return true;
		}

		// Multisite fallback: the bot user may exist on the main site but
		// get_users with role filter only searches the current blog's usermeta.
		// Look up the user by login directly.
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			$user = get_user_by( 'login', 'mcpwp_bot' );
			if ( $user ) {
				add_user_to_blog( get_current_blog_id(), $user->ID, 'mcpwp_api_agent' );
				wp_set_current_user( $user->ID );
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether incoming key matches stored key (hash or legacy plain text).
	 *
	 * @param string $api_key    Incoming API key.
	 * @param string $stored_key Stored API key value.
	 * @return bool True if key is valid.
	 */
	protected function is_api_key_match( $api_key, $stored_key ) {
		if ( empty( $api_key ) || empty( $stored_key ) ) {
			return false;
		}

		if ( wp_check_password( $api_key, $stored_key ) ) {
			return true;
		}

		return hash_equals( $stored_key, $api_key );
	}

	/**
	 * Check whether API keys are configured in any supported store.
	 *
	 * @param string $legacy_key Legacy single key option value.
	 * @return bool True if at least one key is configured.
	 */
	protected function has_configured_api_keys( $legacy_key ) {
		if ( ! empty( $legacy_key ) ) {
			return true;
		}

		$keys = $this->get_scoped_api_keys_raw();
		foreach ( $keys as $key ) {
			if ( empty( $key['revoked_at'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find an active scoped API key record matching a plaintext key.
	 *
	 * @param string $api_key Incoming plaintext API key.
	 * @return array|null Matching key record or null.
	 */
	protected function find_scoped_api_key( $api_key ) {
		$keys = $this->get_scoped_api_keys_raw();

		foreach ( $keys as $key ) {
			if ( ! empty( $key['revoked_at'] ) || empty( $key['hash'] ) ) {
				continue;
			}

			if ( $this->is_api_key_match( $api_key, $key['hash'] ) ) {
				return $this->normalize_api_key_record( $key );
			}
		}

		return null;
	}

	/**
	 * Find an active API key record from the legacy spai_* option store.
	 *
	 * This is the dual-prefix bridge for the 2.8.x → v3 cutover.  It checks
	 * two legacy options:
	 *
	 *   spai_api_keys  — the scoped multi-key store written by 2.8.x
	 *   spai_api_key   — the single-key legacy option (written by pre-multi-key builds)
	 *
	 * The comparison uses the same wp_check_password() / hash_equals() path as
	 * find_scoped_api_key(), so timing characteristics are identical.  No
	 * plaintext key material is logged or retained.
	 *
	 * When a match is found the method returns a normalized key record (same
	 * shape as find_scoped_api_key()) so the caller can apply scope checks
	 * uniformly.  It does NOT auto-migrate the key to the mcpwp_ store — that
	 * is intentional.  Migration is handled separately by Mcpwp_Migrate::run()
	 * so that the auth path stays simple and side-effect-free.
	 *
	 * @param string $api_key Incoming plaintext API key.
	 * @return array|null Matching (normalized) key record, or null.
	 */
	protected function find_legacy_spai_key( $api_key ) {
		// 1. Check the spai_api_keys scoped multi-key store.
		$spai_keys = get_option( 'spai_api_keys', array() );
		if ( is_array( $spai_keys ) ) {
			foreach ( $spai_keys as $key ) {
				if ( ! is_array( $key ) ) {
					continue;
				}
				// Skip revoked keys.
				if ( ! empty( $key['revoked_at'] ) ) {
					continue;
				}
				if ( empty( $key['hash'] ) ) {
					continue;
				}
				if ( $this->is_api_key_match( $api_key, $key['hash'] ) ) {
					return $this->normalize_api_key_record( $key );
				}
			}
		}

		// 2. Fall back to the single-key spai_api_key option.
		$spai_single = get_option( 'spai_api_key' );
		if ( ! empty( $spai_single ) && $this->is_api_key_match( $api_key, $spai_single ) ) {
			// Synthesise a minimal key record consistent with normalize_api_key_record().
			return $this->normalize_api_key_record(
				array(
					'id'         => 'spai_legacy_single',
					'label'      => 'Legacy spai_ key (bridge)',
					'hash'       => (string) $spai_single,
					'scopes'     => $this->get_default_api_key_scopes(),
					'role'       => 'admin',
					'created_at' => null,
				)
			);
		}

		return null;
	}

	/**
	 * Get required authorization scope for a request.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string Scope name: read|write|admin.
	 */
	protected function get_required_scope_for_request( $request ) {
		$route    = $this->normalize_route_for_authorization( method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '' );
		$method   = method_exists( $request, 'get_method' ) ? strtoupper( (string) $request->get_method() ) : 'GET';
		$is_write = ! in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true );

		// MCP JSON-RPC dispatch resolves scope from the tool payload.
		if ( 0 === strpos( $route, '/mcpwp/v1/mcp' ) ) {
			return $this->get_required_scope_for_mcp_request( $request );
		}

		// Approval state transitions (approve/reject/apply/rollback) require admin
		// scope so a plain write key cannot approve/apply its own pending request.
		// Listing/reading approvals stays read; creating one (via the
		// blocks/content-graph/staging controllers) stays at the write default.
		if ( preg_match( '#^/mcpwp/v1/approvals/[^/]+/(approve|reject|apply|rollback)(/|$)#i', $route ) ) {
			return 'admin';
		}

		// Deploying a stored site blueprint replays a full page/menu/option set onto
		// the live site — a destructive, high-blast-radius op. Require admin; saving,
		// listing, and extracting blueprints stay at their read/write defaults.
		if ( preg_match( '#^/mcpwp/v1/site-blueprints/[^/]+/deploy(/|$)#i', $route ) ) {
			return 'admin';
		}

		// Figma design-token sync makes an external Figma API call and overwrites
		// the cached mcpwp_figma_design_tokens option (read by a companion theme) —
		// require admin. Reading the cached tokens stays at the read default.
		if ( preg_match( '#^/mcpwp/v1/figma/design-tokens/sync(/|$)#i', $route ) ) {
			return 'admin';
		}

		// Integrations: reading, writing, deleting, or probing a stored provider
		// secret requires admin scope. Discovery (status/providers/
		// stock-photos) stays read; content-generation stays at the write default.
		if ( $this->route_matches( $route, '/mcpwp/v1/integrations' ) ) {
			if (
				$this->route_matches( $route, '/mcpwp/v1/integrations/configure' )
				|| $this->route_matches( $route, '/mcpwp/v1/integrations/remove' )
				|| $this->route_matches( $route, '/mcpwp/v1/integrations/test' )
				|| preg_match( '#^/mcpwp/v1/integrations/providers/[^/]+/(key|test)(/|$)#i', $route )
			) {
				return 'admin';
			}
			return $is_write ? 'write' : 'read';
		}

		// Blanket-admin routes: every method (including GET) requires admin scope.
		$admin_routes = array(
			'/mcpwp/v1/settings',
			'/mcpwp/v1/options',
			'/mcpwp/v1/menus',
			'/mcpwp/v1/content',
			'/mcpwp/v1/webhooks',
			'/mcpwp/v1/api-keys',
			'/mcpwp/v1/elementor/custom-code',
			// User management can create/modify accounts (privilege-escalation
			// surface) and exposes account PII (emails, roles, capabilities);
			// restrict the whole surface to admin scope.
			'/mcpwp/v1/users',
			// Plugin self-update is a code-execution surface: it can install an
			// arbitrary package and reactivate the plugin. Restrict to admin scope.
			'/mcpwp/v1/update',
			// Singular /option write can set update-control options
			// (mcpwp_update_info / mcpwp_version_url) that steer the updater.
			'/mcpwp/v1/option',
			// /plugin-settings exposes the GitHub token / OAuth client-secret hash and
			// other mcpwp_settings internals; a write-scoped key could overwrite OAuth
			// config (secret takeover). Require admin scope for both GET and PUT.
			'/mcpwp/v1/plugin-settings',
			// Staging routes: pushing environments (especially →live) is irreversible
			// without a host-level backup, so all staging endpoints require admin scope.
			'/mcpwp/v1/staging',
		);

		foreach ( $admin_routes as $admin_route ) {
			if ( $this->route_matches( $route, $admin_route ) ) {
				return 'admin';
			}
		}

		// Admin-on-write routes: mutating methods require admin scope; GET/HEAD/
		// OPTIONS stay readable. Used where reads are safe to expose but writes are
		// a high-blast-radius or secret-bearing surface.
		$admin_write_routes = array(
			// Rate-limit reset is an admin control (was an inline special case).
			'/mcpwp/v1/rate-limit',
			// Site-wide custom CSS is a persistent injection/defacement vector.
			// Standalone controller.
			'/mcpwp/v1/custom-css',
			// Theme customizer surface: /theme/mods overwrites all customizer state,
			// /theme/custom-css injects site-wide CSS. Boundary match
			// on /theme covers both mutating sub-routes; /theme/global-styles GET and
			// other GETs stay readable. Distinct from /themes (plural) below.
			'/mcpwp/v1/theme',
			// Pro theme reconfiguration: /themes/settings, /themes/astra/colors,
			// /themes/astra/typography are EDITABLE global-presentation writes; the
			// many GET discovery routes under /themes stay read.
			'/mcpwp/v1/themes',
			// Action-log rollback reverts an arbitrary historical mutation; the log
			// GET/export routes stay read.
			'/mcpwp/v1/action-log',
			// Cloudflare zone mutations (bot-check fix, cache purge, Speed settings)
			// change external infra config outside this site; status/speed-settings
			// GETs stay read.
			'/mcpwp/v1/cloudflare',
		);

		foreach ( $admin_write_routes as $admin_write_route ) {
			if ( $this->route_matches( $route, $admin_write_route ) ) {
				return $is_write ? 'admin' : 'read';
			}
		}

		// Default: GET/HEAD/OPTIONS → read, everything else → write.
		return $is_write ? 'write' : 'read';
	}

	/**
	 * Boundary-aware route prefix match.
	 *
	 * Matches only when $route equals $prefix or continues with a path separator,
	 * so '/mcpwp/v1/content' no longer over-matches '/mcpwp/v1/content-graph'.
	 *
	 * @param string $route  Full request route (with namespace).
	 * @param string $prefix Route prefix to test.
	 * @return bool True when $route is $prefix or a sub-path of it.
	 */
	protected function route_matches( $route, $prefix ) {
		return $route === $prefix || 0 === strpos( $route, $prefix . '/' );
	}

	/**
	 * Fold a request route to the form the authorization tables are written in.
	 *
	 * WordPress compiles route patterns with `@^…$@i` and never rewrites
	 * $request->get_route(), so `POST /mcpwp/v1/Api-Keys` dispatches to the
	 * `/api-keys` handler while every case-sensitive comparison in this trait
	 * misses it. A route that misses the admin table falls through to the
	 * write/read default, which is how a write-scoped credential reached an
	 * admin-only endpoint. Every route that feeds an authorization decision
	 * must come through here first.
	 *
	 * The fold is ASCII-only on purpose: strtolower() consults the C locale
	 * below PHP 8.2, and this plugin supports 7.4. Under a Turkish LC_CTYPE it
	 * maps "I" to a dotless "ı", which would match none of the prefixes below
	 * and reopen exactly this hole.
	 *
	 * @param string $route Raw request route.
	 * @return string Route folded to lower case.
	 */
	protected function normalize_route_for_authorization( $route ) {
		return strtr(
			(string) $route,
			'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
			'abcdefghijklmnopqrstuvwxyz'
		);
	}

	/**
	 * Infer required scope from MCP JSON-RPC payload.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return string Scope.
	 */
	protected function get_required_scope_for_mcp_request( $request ) {
		$payload = method_exists( $request, 'get_json_params' ) ? $request->get_json_params() : null;

		if ( empty( $payload ) ) {
			return 'read';
		}

		$messages = isset( $payload[0] ) && is_array( $payload ) ? $payload : array( $payload );
		$highest  = 'read';

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$method = isset( $message['method'] ) ? (string) $message['method'] : '';
			$scope  = 'read';

			if ( 'tools/call' === $method ) {
				$tool_name = isset( $message['params']['name'] ) ? (string) $message['params']['name'] : '';
				$scope = $this->get_required_scope_for_tool_name( $tool_name );
			} elseif ( ! in_array( $method, array( 'initialize', 'tools/list', 'resources/list', 'resources/read', 'ping', 'notifications/initialized' ), true ) ) {
				$scope = 'write';
			}

			if ( $this->scope_rank( $scope ) > $this->scope_rank( $highest ) ) {
				$highest = $scope;
			}
		}

		return $highest;
	}

	/**
	 * Determine required scope for a tool call name.
	 *
	 * @param string $tool_name MCP tool name.
	 * @return string Scope.
	 */
	protected function get_required_scope_for_tool_name( $tool_name ) {
		$admin_tools = array(
			'wp_delete_all_drafts',
			'wp_get_options',
			'wp_update_options',
			'wp_list_menus',
			'wp_list_menu_locations',
			'wp_list_menu_items',
			'wp_setup_menu',
			'wp_add_menu_item',
			'wp_update_menu_item',
			'wp_delete_menu_item',
			'wp_reorder_menu_items',
			'wp_delete_menu',
			'wp_assign_menu_location',
			'wp_batch_update',
			'wp_list_content',
			'wp_delete_content',
			'wp_languages',
			'wp_set_language',
			'wp_get_translations',
			'wp_create_translation',
			'wp_create_webhook',
			'wp_update_webhook',
			'wp_delete_webhook',
			'wp_test_webhook',
			'wp_list_webhooks',
			'wp_list_webhook_logs',
			'wp_list_webhook_events',
			'wp_get_event_schema',
			'wp_list_mcp_events',
			'wp_create_api_key',
			'wp_revoke_api_key',
			'wp_list_api_keys',
			'wp_update_rate_limit',
			'wp_reset_rate_limit',
			// Plugin self-update installs a package and reactivates the plugin —
			// a code-execution surface that must never be reachable by a
			// write-scoped key. Defense-in-depth alongside the REST admin gate.
			'wp_trigger_update',
			// Plugin settings hold the GitHub token / OAuth client-secret hash and
			// other secret-bearing config; write access must require admin scope.
			'wp_update_plugin_settings',
			// Staging tools require admin scope — staging→live push is a destructive,
			// irreversible operation that must not be accessible to write-scoped keys.
			'wp_get_staging_info',
			'wp_push_to_staging',
			'wp_push_to_live',
			'wp_get_staging_status',
			// Approval state transitions require admin scope so a plain write key
			// cannot approve/apply/reject/rollback a change — including its own
			// pending request. List/get stay read (not listed here).
			'wp_approve_request',
			'wp_reject_request',
			'wp_apply_approval',
			'wp_rollback_approval',
			// Integration credential management writes/removes/probes encrypted
			// third-party provider secrets (OpenAI/Gemini/ElevenLabs/…) — admin
			// only. Status/discovery stay read (not listed here).
			'wp_configure_integration',
			'wp_remove_integration',
			'wp_test_integration',
			// Site-wide custom CSS is a persistent injection surface; writing or
			// deleting it requires admin scope.
			'wp_set_custom_css',
			'wp_delete_custom_css',
			// Deploying a stored site blueprint replays a full page/menu/option set
			// onto the live site — a destructive op. The write-verb regex does not
			// match "deploy", so name it explicitly to mirror the REST deploy gate.
			'wp_deploy_site_blueprint',
			// Singular option get/update and plugin-settings read reach the same
			// update-control / secret-bearing options already admin-gated at the
			// REST layer (/option, /plugin-settings) — keep the tool table in sync
			// with those routes.
			'wp_get_option',
			'wp_update_option',
			'wp_get_plugin_settings',
			// Figma design-token sync calls the external Figma API and overwrites
			// the cached design-tokens option a companion theme reads — admin only,
			// mirroring the REST /figma/design-tokens/sync gate above.
			'wp_sync_figma_design_tokens',
		);

		if ( in_array( $tool_name, $admin_tools, true ) ) {
			$required_scope = 'admin';
		} elseif ( preg_match( '/^wp_(create|update|delete|set|upload|bulk|generate|download)/', $tool_name ) ) {
			$required_scope = 'write';
		} else {
			$required_scope = 'read';
		}

		/**
		 * Filters the required authorization scope for an MCP tool call.
		 *
		 * Implementations may raise, but cannot lower, the inferred requirement.
		 *
		 * @param string $required_scope Inferred scope requirement.
		 * @param string $tool_name      MCP tool name.
		 */
		$filtered_scope = apply_filters(
			'mcpwp_required_scope_for_tool',
			$required_scope,
			$tool_name
		);

		$scope_ranks = array(
			'read'  => 1,
			'write' => 2,
			'admin' => 3,
		);

		if (
			is_string( $filtered_scope )
			&& isset( $scope_ranks[ $filtered_scope ] )
			&& $scope_ranks[ $filtered_scope ] > $scope_ranks[ $required_scope ]
		) {
			return $filtered_scope;
		}

		return $required_scope;
	}

	/**
	 * Check if a key grants a required scope.
	 *
	 * @param array  $key_record    Key record.
	 * @param string $required_scope Scope required for request.
	 * @return bool True if granted.
	 */
	protected function key_has_scope( $key_record, $required_scope ) {
		$scopes = isset( $key_record['scopes'] ) ? (array) $key_record['scopes'] : array();
		$scopes = $this->sanitize_scopes( $scopes );

		if ( in_array( 'admin', $scopes, true ) ) {
			return true;
		}

		if ( 'write' === $required_scope && in_array( 'write', $scopes, true ) ) {
			return true;
		}

		if ( 'read' === $required_scope && ( in_array( 'read', $scopes, true ) || in_array( 'write', $scopes, true ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Rank scope for comparison.
	 *
	 * @param string $scope Scope value.
	 * @return int Rank value.
	 */
	protected function scope_rank( $scope ) {
		$ranks = array(
			'read'  => 1,
			'write' => 2,
			'admin' => 3,
		);

		return isset( $ranks[ $scope ] ) ? $ranks[ $scope ] : 1;
	}

	/**
	 * Create and store a scoped API key.
	 *
	 * @param string $label           Human-readable key label.
	 * @param array  $scopes          Scopes for key.
	 * @param string $role            Role slug (admin, author, designer, editor, custom).
	 * @param array  $tool_categories Tool category slugs (for custom role).
	 * @return array Key creation result, including plaintext key once.
	 */
	public function create_scoped_api_key( $label = '', $scopes = array(), $role = 'admin', $tool_categories = array() ) {
		$plain_key = $this->generate_api_key();
		$key_id    = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mcpwp_', true );
		$now       = current_time( 'mysql' );
		// Capture whether the caller supplied any recognisable scope BEFORE
		// sanitize_scopes() runs. It now fails CLOSED to array('read') on empty input,
		// which would otherwise mask "no scopes requested" as "read requested" and
		// demote an admin-role key created without explicit scopes to read-only. Key
		// CREATION is the sanctioned place to default UP to the role ceiling.
		$requested_scopes = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) $scopes ),
				array( 'read', 'write', 'admin' )
			)
		);
		$scopes    = $this->sanitize_scopes( $scopes );
		$role      = sanitize_key( (string) $role );

		// Validate role.
		$known_roles = array_keys( self::get_role_definitions() );
		if ( ! in_array( $role, $known_roles, true ) ) {
			$role = 'admin';
		}

		// Sanitize tool categories.
		$all_cats        = array_keys( self::get_all_tool_category_labels() );
		$tool_categories = array_values( array_intersect( array_map( 'sanitize_key', (array) $tool_categories ), $all_cats ) );

		// Cap scopes to the role's maximum ceiling — prevents a non-admin role from
		// claiming admin scope even if sanitize_scopes expanded it from 'admin' input.
		$allowed_ceiling = ( 'admin' === $role )
			? array( 'read', 'write', 'admin' )
			: array( 'read', 'write' );
		$scopes = array_values( array_intersect( $scopes, $allowed_ceiling ) );
		if ( empty( $requested_scopes ) || empty( $scopes ) ) {
			// No explicit scopes requested (or all exceeded the ceiling) → grant the
			// role's full ceiling. The ONLY sanctioned "default up".
			$scopes = $allowed_ceiling;
		}

		$record = array(
			'id'              => sanitize_key( (string) $key_id ),
			'label'           => '' !== $label ? sanitize_text_field( $label ) : __( 'API Key', 'mcpwp' ),
			'hash'            => wp_hash_password( $plain_key ),
			'scopes'          => $scopes,
			'role'            => $role,
			'tool_categories' => $tool_categories,
			'created_at'      => $now,
			'last_used_at'    => null,
			'revoked_at'      => null,
		);

		$keys   = $this->get_scoped_api_keys_raw();
		$keys[] = $record;
		update_option( $this->get_scoped_api_keys_option_name(), $keys );

		return array(
			'id'              => $record['id'],
			'label'           => $record['label'],
			'scopes'          => $record['scopes'],
			'role'            => $record['role'],
			'tool_categories' => $record['tool_categories'],
			'created_at'      => $record['created_at'],
			'key'             => $plain_key,
		);
	}

	/**
	 * List scoped API keys without exposing secrets.
	 *
	 * @param bool $include_revoked Whether to include revoked keys.
	 * @return array Key metadata list.
	 */
	public function list_scoped_api_keys( $include_revoked = false ) {
		$keys   = $this->get_scoped_api_keys_raw();
		$output = array();

		foreach ( $keys as $key ) {
			$normalized = $this->normalize_api_key_record( $key );
			if ( ! $include_revoked && ! empty( $normalized['revoked_at'] ) ) {
				continue;
			}

			$output[] = array(
				'id'              => $normalized['id'],
				'label'           => $normalized['label'],
				'scopes'          => $normalized['scopes'],
				'role'            => $normalized['role'],
				'tool_categories' => $normalized['tool_categories'],
				'created_at'      => $normalized['created_at'],
				'last_used_at'    => $normalized['last_used_at'],
				'revoked_at'      => $normalized['revoked_at'],
			);
		}

		return $output;
	}

	/**
	 * Revoke a scoped API key.
	 *
	 * @param string $key_id Key identifier.
	 * @return bool True if revoked.
	 */
	public function revoke_scoped_api_key( $key_id ) {
		$key_id = sanitize_key( (string) $key_id );
		if ( '' === $key_id ) {
			return false;
		}

		$keys     = $this->get_scoped_api_keys_raw();
		$updated  = false;

		foreach ( $keys as &$key ) {
			$normalized = $this->normalize_api_key_record( $key );
			if ( $normalized['id'] !== $key_id ) {
				continue;
			}

			if ( empty( $normalized['revoked_at'] ) ) {
				$key['revoked_at'] = current_time( 'mysql' );
				$updated = true;
			}
		}
		unset( $key );

		if ( $updated ) {
			update_option( $this->get_scoped_api_keys_option_name(), $keys );
		}

		return $updated;
	}

	/**
	 * Migrate legacy single-key storage to scoped key storage.
	 *
	 * @param string $legacy_key Legacy key option value.
	 * @return array|null Migrated key record.
	 */
	protected function migrate_legacy_key_to_scoped_store( $legacy_key ) {
		if ( empty( $legacy_key ) ) {
			return null;
		}

		$legacy_key      = (string) $legacy_key;
		$legacy_is_hashed = $this->looks_like_password_hash( $legacy_key );
		$legacy_hash      = $legacy_key;

		if ( ! $legacy_is_hashed ) {
			$legacy_hash = wp_hash_password( $legacy_key );
			update_option( 'mcpwp_api_key', $legacy_hash );
		}

		$keys = $this->get_scoped_api_keys_raw();
		if ( ! empty( $keys ) ) {
			foreach ( $keys as $key ) {
				$normalized = $this->normalize_api_key_record( $key );
				if ( ! empty( $normalized['revoked_at'] ) ) {
					continue;
				}
				$is_existing_match = $legacy_is_hashed
					? hash_equals( (string) $normalized['hash'], $legacy_hash )
					: $this->is_api_key_match( $legacy_key, $normalized['hash'] );

				if ( $is_existing_match ) {
					return $normalized;
				}
			}
		}

		$migrated = array(
			'id'           => sanitize_key( function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'mcpwp_', true ) ),
			'label'        => __( 'Primary API Key (migrated)', 'mcpwp' ),
			'hash'         => $legacy_hash,
			'scopes'       => $this->get_default_api_key_scopes(),
			'created_at'   => current_time( 'mysql' ),
			'last_used_at' => null,
			'revoked_at'   => null,
		);

		$keys[] = $migrated;
		update_option( $this->get_scoped_api_keys_option_name(), $keys );

		return $this->normalize_api_key_record( $migrated );
	}

	/**
	 * Update last_used_at on a scoped API key.
	 *
	 * @param string $key_id Key identifier.
	 */
	protected function touch_api_key_last_used( $key_id ) {
		$key_id = sanitize_key( (string) $key_id );
		if ( '' === $key_id ) {
			return;
		}

		$keys = $this->get_scoped_api_keys_raw();
		$now  = current_time( 'mysql' );
		$changed = false;

		foreach ( $keys as &$key ) {
			$normalized = $this->normalize_api_key_record( $key );
			if ( $normalized['id'] !== $key_id ) {
				continue;
			}

			$key['last_used_at'] = $now;
			$changed = true;
			break;
		}
		unset( $key );

		if ( $changed ) {
			update_option( $this->get_scoped_api_keys_option_name(), $keys );
		}
	}

	/**
	 * Get raw scoped API key storage.
	 *
	 * @return array Raw key records.
	 */
	protected function get_scoped_api_keys_raw() {
		$keys = get_option( $this->get_scoped_api_keys_option_name(), array() );
		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Normalize key record fields.
	 *
	 * @param array $record Key record.
	 * @return array Normalized key record.
	 */
	protected function normalize_api_key_record( $record ) {
		$record = is_array( $record ) ? $record : array();

		$role            = isset( $record['role'] ) ? sanitize_key( (string) $record['role'] ) : 'admin';
		$tool_categories = isset( $record['tool_categories'] ) ? array_map( 'sanitize_key', (array) $record['tool_categories'] ) : array();

		// Validate role against known definitions.
		$known_roles = array_keys( self::get_role_definitions() );
		if ( ! in_array( $role, $known_roles, true ) ) {
			$role = 'admin';
		}

		return array(
			'id'              => isset( $record['id'] ) ? sanitize_key( (string) $record['id'] ) : '',
			'label'           => isset( $record['label'] ) ? sanitize_text_field( (string) $record['label'] ) : __( 'API Key', 'mcpwp' ),
			'hash'            => isset( $record['hash'] ) ? (string) $record['hash'] : '',
			'scopes'          => $this->normalize_record_scopes( $record, $role ),
			'role'            => $role,
			'tool_categories' => $tool_categories,
			'rate_limits'     => $this->sanitize_rate_limits( isset( $record['rate_limits'] ) ? $record['rate_limits'] : null ),
			'created_at'      => isset( $record['created_at'] ) ? (string) $record['created_at'] : null,
			'last_used_at'    => isset( $record['last_used_at'] ) ? (string) $record['last_used_at'] : null,
			'revoked_at'      => isset( $record['revoked_at'] ) ? (string) $record['revoked_at'] : null,
		);
	}

	/**
	 * Resolve a stored key record's scopes, now that sanitize_scopes() fails closed.
	 *
	 * - 'scopes' key ENTIRELY ABSENT → a legacy record predating scoped keys; these were
	 *   effectively their role's full grant. Derive from the (already validated) role so
	 *   the fail-closed change does not silently demote every legacy admin key to read.
	 * - 'scopes' PRESENT but empties out (corrupt/hand-seeded) → genuinely ambiguous;
	 *   fail closed to array('read') via sanitize_scopes().
	 *
	 * @param array  $record Raw key record.
	 * @param string $role   Already-validated role slug (normalize coerces unknown→'admin').
	 * @return array Scope list.
	 */
	protected function normalize_record_scopes( $record, $role ) {
		if ( ! array_key_exists( 'scopes', $record ) ) {
			return ( 'admin' === $role )
				? array( 'read', 'write', 'admin' )
				: array( 'read', 'write' );
		}
		return $this->sanitize_scopes( (array) $record['scopes'] );
	}

	/**
	 * Sanitize and normalize scopes list.
	 *
	 * @param array $scopes Scopes input.
	 * @return array Sanitized scopes.
	 */
	/**
	 * Sanitize per-key rate limit overrides.
	 *
	 * Accepts an optional array with keys: burst, per_minute, per_hour.
	 * Returns null if no valid overrides are present, or a sanitized array.
	 *
	 * @param mixed $rate_limits Raw rate_limits value from key record.
	 * @return array|null Sanitized rate limits or null.
	 */
	protected function sanitize_rate_limits( $rate_limits ) {
		if ( ! is_array( $rate_limits ) ) {
			return null;
		}

		$sanitized = array();
		$valid_keys = array( 'burst', 'per_minute', 'per_hour' );

		foreach ( $valid_keys as $key ) {
			if ( isset( $rate_limits[ $key ] ) ) {
				$sanitized[ $key ] = max( 1, min( 100000, (int) $rate_limits[ $key ] ) );
			}
		}

		return empty( $sanitized ) ? null : $sanitized;
	}

	protected function sanitize_scopes( $scopes ) {
		$allowed = array( 'read', 'write', 'admin' );
		$scopes  = array_map( 'sanitize_key', (array) $scopes );
		$scopes  = array_values( array_intersect( $scopes, $allowed ) );

		if ( empty( $scopes ) ) {
			// Fail CLOSED. Empty/unrecognised stored scopes are ambiguous data, not a
			// grant — return the minimum privilege. get_default_api_key_scopes()
			// (read+write+admin) is reserved for the explicit key-CREATION path
			// (create_scoped_api_key) and legacy-key migration, never for runtime
			// validation of stored scopes. This is the canonical null-collapse-to-trusted
			// this repo had: key_has_scope(), issue_oauth_access_token() and
			// authenticate_oauth_access_token() all re-run this on every gated request.
			// normalize_api_key_record() preserves legacy scopeless admin keys without
			// this fallback (see normalize_record_scopes()).
			return array( 'read' );
		}

		if ( in_array( 'admin', $scopes, true ) ) {
			return array( 'read', 'write', 'admin' );
		}

		if ( in_array( 'write', $scopes, true ) && ! in_array( 'read', $scopes, true ) ) {
			$scopes[] = 'read';
		}

		return array_values( array_unique( $scopes ) );
	}

	/**
	 * Get default key scopes.
	 *
	 * @return array Default scopes.
	 */
	protected function get_default_api_key_scopes() {
		return array( 'read', 'write', 'admin' );
	}

	/**
	 * Get available role definitions.
	 *
	 * Each role maps to a set of tool categories the key can access.
	 *
	 * @return array Map of role_slug => array with 'label', 'description', 'categories'.
	 */
	public static function get_role_definitions() {
		return array(
			'admin'    => array(
				'label'       => __( 'Admin', 'mcpwp' ),
				'description' => __( 'Full access to all tool categories.', 'mcpwp' ),
				'categories'  => array(), // Empty = all categories allowed.
			),
			'author'   => array(
				'label'       => __( 'Author', 'mcpwp' ),
				'description' => __( 'Content writing — pages, posts, media, taxonomy.', 'mcpwp' ),
				'categories'  => array( 'content', 'media', 'taxonomy' ),
			),
			'designer' => array(
				'label'       => __( 'Designer', 'mcpwp' ),
				'description' => __( 'Visual building — Elementor, Gutenberg, media, site settings.', 'mcpwp' ),
				'categories'  => array( 'elementor', 'elementor-build', 'elementor-templates', 'elementor-theme', 'elementor-info', 'gutenberg', 'media', 'site' ),
			),
			'editor'   => array(
				'label'       => __( 'Editor', 'mcpwp' ),
				'description' => __( 'Content + SEO management.', 'mcpwp' ),
				'categories'  => array( 'content', 'media', 'taxonomy', 'seo', 'elementor', 'elementor-build', 'elementor-templates', 'elementor-theme', 'elementor-info' ),
			),
			'custom'   => array(
				'label'       => __( 'Custom', 'mcpwp' ),
				'description' => __( 'Pick individual tool categories.', 'mcpwp' ),
				'categories'  => array(), // User-defined.
			),
		);
	}

	/**
	 * Get all known tool category slugs.
	 *
	 * @return array Map of category_slug => label.
	 */
	public static function get_all_tool_category_labels() {
		return array(
			'content'             => __( 'Content', 'mcpwp' ),
			'media'               => __( 'Media', 'mcpwp' ),
			'taxonomy'            => __( 'Taxonomy', 'mcpwp' ),
			'elementor'           => __( 'Elementor', 'mcpwp' ),
			'elementor-build'     => __( 'Elementor Build', 'mcpwp' ),
			'elementor-templates' => __( 'Elementor Templates', 'mcpwp' ),
			'elementor-theme'     => __( 'Elementor Theme', 'mcpwp' ),
			'elementor-info'      => __( 'Elementor Info', 'mcpwp' ),
			'gutenberg'           => __( 'Gutenberg', 'mcpwp' ),
			'seo'                 => __( 'SEO', 'mcpwp' ),
			'forms'               => __( 'Forms', 'mcpwp' ),
			'site'                => __( 'Site', 'mcpwp' ),
			'admin'               => __( 'Admin', 'mcpwp' ),
			'webhooks'            => __( 'Webhooks', 'mcpwp' ),
			'ai'                  => __( 'AI', 'mcpwp' ),
			'woocommerce'         => __( 'WooCommerce', 'mcpwp' ),
			'learnpress'          => __( 'LearnPress', 'mcpwp' ),
			'events'              => __( 'Events', 'mcpwp' ),
			'multisite'           => __( 'Multisite', 'mcpwp' ),
			'staging'             => __( 'Staging', 'mcpwp' ),
		);
	}

	/**
	 * Resolve allowed tool categories for a key record.
	 *
	 * Admin role (or keys without a role) get empty array = all allowed.
	 *
	 * @param array $key_record Normalized key record.
	 * @return array Allowed category slugs, or empty for unrestricted.
	 */
	public function resolve_key_tool_categories( $key_record ) {
		$role = isset( $key_record['role'] ) ? (string) $key_record['role'] : 'admin';

		// Admin role or legacy keys: unrestricted.
		if ( 'admin' === $role || '' === $role ) {
			return array();
		}

		// Custom role: use the stored tool_categories.
		if ( 'custom' === $role ) {
			return isset( $key_record['tool_categories'] ) ? (array) $key_record['tool_categories'] : array();
		}

		// Predefined role: look up from definitions.
		$roles = self::get_role_definitions();
		if ( isset( $roles[ $role ] ) ) {
			return $roles[ $role ]['categories'];
		}

		// Unknown role fallback: unrestricted.
		return array();
	}

	/**
	 * Check whether a key is allowed to access a given tool category.
	 *
	 * @param array  $key_record Normalized key record.
	 * @param string $category   Tool category slug.
	 * @return bool True if allowed.
	 */
	public function key_allows_category( $key_record, $category ) {
		$allowed = $this->resolve_key_tool_categories( $key_record );

		// Empty = unrestricted (admin role).
		if ( empty( $allowed ) ) {
			return true;
		}

		return in_array( $category, $allowed, true );
	}

	/**
	 * Get OAuth settings.
	 *
	 * @return array OAuth settings.
	 */
	protected function get_oauth_settings() {
		$settings = get_option( 'mcpwp_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		// Ceiling for the client_credentials grant (machine tokens). Resolved
		// through an explicit allow-list (read/write/admin) rather than through the
		// scope helpers, so its semantics are self-contained and cannot drift with
		// them. An unrecognised or absent value falls back to 'write' — the documented
		// default. That fallback is a two-sided guarantee: it stops a bad value
		// widening the ceiling, and it stops an unset option narrowing every
		// machine client to read and breaking existing integrations.
		$cc_max_scope = isset( $settings['oauth_client_credentials_max_scope'] )
			? sanitize_key( (string) $settings['oauth_client_credentials_max_scope'] )
			: '';
		if ( ! in_array( $cc_max_scope, array( 'read', 'write', 'admin' ), true ) ) {
			$cc_max_scope = 'write';
		}

		return array(
			'oauth_enabled'                      => ! empty( $settings['oauth_enabled'] ),
			'oauth_client_id'                    => isset( $settings['oauth_client_id'] ) ? sanitize_key( (string) $settings['oauth_client_id'] ) : 'site_pilot_ai',
			'oauth_client_secret_hash'           => isset( $settings['oauth_client_secret_hash'] ) ? (string) $settings['oauth_client_secret_hash'] : '',
			'oauth_token_ttl'                    => isset( $settings['oauth_token_ttl'] ) ? max( 300, min( 86400, absint( $settings['oauth_token_ttl'] ) ) ) : 3600,
			'oauth_client_credentials_max_scope' => $cc_max_scope,
		);
	}

	/**
	 * Verify OAuth client credentials.
	 *
	 * @param string $client_id     Client ID.
	 * @param string $client_secret Client secret.
	 * @return bool True when credentials are valid.
	 */
	protected function verify_oauth_client_credentials( $client_id, $client_secret ) {
		$oauth_settings = $this->get_oauth_settings();

		if ( empty( $oauth_settings['oauth_enabled'] ) || empty( $oauth_settings['oauth_client_secret_hash'] ) ) {
			return false;
		}

		if ( sanitize_key( (string) $client_id ) !== $oauth_settings['oauth_client_id'] ) {
			return false;
		}

		return wp_check_password( (string) $client_secret, $oauth_settings['oauth_client_secret_hash'] );
	}

	/**
	 * Issue an OAuth bearer access token.
	 *
	 * @param array $scopes Scopes to grant.
	 * @param int   $ttl    Token TTL in seconds.
	 * @return array Token response payload.
	 */
	public function issue_oauth_access_token( $scopes, $ttl, $context = array() ) {
		$ttl    = max( 300, min( 86400, absint( $ttl ) ) );
		$scopes = $this->sanitize_scopes( $scopes );
		$token  = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );

		$payload = array(
			'scopes'     => $scopes,
			'created_at' => time(),
			'expires_at' => time() + $ttl,
		);

		// Persist the consenting principal (user or client) so the auth path can
		// give the token a stable, non-empty identity — required for the
		// separation-of-duties (self-approval) gate to distinguish it from the
		// trusted admin-AJAX human context.
		if ( isset( $context['user_id'] ) && (int) $context['user_id'] > 0 ) {
			$payload['user_id'] = (int) $context['user_id'];
		}
		if ( ! empty( $context['client_id'] ) ) {
			$payload['client_id'] = sanitize_key( (string) $context['client_id'] );
		}

		set_transient( $this->get_oauth_token_transient_key( $token ), $payload, $ttl );

		return array(
			'access_token' => $token,
			'token_type'   => 'Bearer',
			'expires_in'   => $ttl,
			'scope'        => implode( ' ', $scopes ),
		);
	}

	/**
	 * Get transient key for an OAuth access token.
	 *
	 * @param string $token Access token.
	 * @return string Transient key.
	 */
	protected function get_oauth_token_transient_key( $token ) {
		return 'mcpwp_oauth_token_' . md5( (string) $token );
	}

	/**
	 * Get scoped key option name.
	 *
	 * @return string Option name.
	 */
	protected function get_scoped_api_keys_option_name() {
		return 'mcpwp_api_keys';
	}

	/**
	 * Check whether a stored key value appears to be a password hash.
	 *
	 * @param string $value Stored key value.
	 * @return bool True when value looks hashed.
	 */
	protected function looks_like_password_hash( $value ) {
		$value = (string) $value;
		return '' !== $value && '$' === substr( $value, 0, 1 );
	}

	/**
	 * Check whether the current request is a trusted internal admin AJAX call.
	 *
	 * Replaces the former apply_filters('mcpwp_bypass_api_key_check') open bypass.
	 * All conditions must pass simultaneously:
	 *
	 *   1. is_admin() — request is served from the WP admin context.
	 *   2. activate_plugins capability — caller is a site admin.
	 *   3. DOING_AJAX constant — request went through wp-admin/admin-ajax.php.
	 *   4. $_POST['action'] is in the allowlist of known MCPWP admin AJAX actions.
	 *   5. check_ajax_referer() verifies the mcpwp_admin_chat nonce for the action.
	 *
	 * The GET ?api_key= query-param path is intentionally NOT touched here —
	 * that path is required for MCP clients (Claude Desktop, ChatGPT connectors)
	 * that cannot set custom request headers. See get_api_key_from_request()
	 * line ~431 for the exact location. Any product decision to restrict or
	 * remove that path belongs to Hadi/Kasra-core, not this security gate.
	 *
	 * @return bool True when all conditions are satisfied.
	 */
	protected function is_trusted_admin_ajax_request() {
		if ( ! is_admin() ) {
			return false;
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return false;
		}
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return false;
		}

		// Only bypass for known MCPWP admin actions — never a wildcard.
		$allowed_actions = array(
			'mcpwp_admin_chat',
			'mcpwp_chat_tool_call',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return false;
		}

		// Verify the nonce — die()s on failure (WP behaviour).
		// The nonce is generated server-side and sent to the admin JS bundle.
		return (bool) check_ajax_referer( 'mcpwp_admin_chat', 'nonce', false );
	}

	/**
	 * Generate a new API key.
	 *
	 * @return string New API key.
	 */
	public function generate_api_key() {
		return 'mcpwp_' . bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Regenerate API key.
	 *
	 * @return string New API key (plain text).
	 */
	public function regenerate_api_key() {
		$keys = $this->get_scoped_api_keys_raw();

		foreach ( $keys as &$key ) {
			if ( empty( $key['revoked_at'] ) ) {
				$key['revoked_at'] = current_time( 'mysql' );
			}
		}
		unset( $key );

		update_option( $this->get_scoped_api_keys_option_name(), $keys );

		$created = $this->create_scoped_api_key( __( 'Primary API Key', 'mcpwp' ), $this->get_default_api_key_scopes() );
		update_option( 'mcpwp_api_key', wp_hash_password( $created['key'] ) );

		return $created['key'];
	}
}
