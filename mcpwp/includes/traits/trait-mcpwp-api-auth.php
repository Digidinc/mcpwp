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

		$rate_limit_check = $this->check_rate_limit( $rate_identifier, $http_method, $matched_key );
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
	 * Returns an empty string when the route is not governed by any category
	 * (e.g. /mcp, /site-info, /settings) — those are always accessible.
	 *
	 * The mapping mirrors the tool-category assignments in
	 * Mcpwp_MCP_Free_Tools::get_tool_categories().
	 *
	 * @param string $route Full REST route, e.g. /mcpwp/v1/pages.
	 * @return string Category slug, or '' if not category-gated.
	 */
	protected function get_category_for_route( $route ) {
		$base = '/mcpwp/v1';
		$path = ( 0 === strpos( $route, $base ) ) ? substr( $route, strlen( $base ) ) : $route;

		// Ordered from most-specific to least-specific so that longer prefixes win.
		$route_category_map = array(
			// Elementor info (status, widget schemas) — check before generic /elementor.
			'/elementor/status'                        => 'elementor-info',
			'/elementor/widgets'                       => 'elementor-info',
			'/elementor/widget-help'                   => 'elementor-info',
			'/elementor/regenerate-css'                => 'elementor-info',

			// Elementor build (read/write page data).
			'/elementor'                               => 'elementor',

			// Gutenberg blocks.
			'/blocks'                                  => 'gutenberg',

			// Content: pages, posts, generic content.
			'/pages'                                   => 'content',
			'/posts'                                   => 'content',
			'/content'                                 => 'content',
			'/drafts'                                  => 'content',
			'/batch'                                   => 'content',
			'/post-meta'                               => 'content',

			// Media.
			'/media'                                   => 'media',
			'/screenshot'                              => 'media',

			// Taxonomy.
			'/categories'                              => 'taxonomy',
			'/tags'                                    => 'taxonomy',
			'/terms'                                   => 'taxonomy',

			// SEO.
			'/seo'                                     => 'seo',
			'/content-coherence'                       => 'seo',

			// Webhooks.
			'/webhooks'                                => 'webhooks',
			'/events'                                  => 'webhooks',

			// Admin — approvals, api-keys handled here; settings/options are
			// intentionally not gated since they are protected by the 'admin' scope.
			'/approvals'                               => 'admin',
			'/api-keys'                                => 'admin',
			'/rate-limit'                              => 'admin',
			'/integrations'                            => 'admin',
			'/feedback'                                => 'admin',
		);

		foreach ( $route_category_map as $prefix => $category ) {
			if ( 0 === strpos( $path, $prefix ) ) {
				return $category;
			}
		}

		return ''; // Not governed by any category toggle.
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

		$route    = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
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

		return true;
	}

	/**
	 * Check rate limit for current request.
	 *
	 * @param string $identifier Rate-limit bucket identifier.
	 * @param string $method     HTTP method (GET requests get higher limits).
	 * @return bool|WP_Error True if allowed, error if rate limited.
	 */
	protected function check_rate_limit( $identifier = null, $method = 'POST', $key_record = null ) {
		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return true;
		}

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		return $limiter->check_limit( $identifier, $method, $key_record );
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

		$rate_limit_check = $this->check_rate_limit( 'oauth:' . substr( hash( 'sha256', (string) $token ), 0, 16 ) );
		if ( is_wp_error( $rate_limit_check ) ) {
			return $rate_limit_check;
		}

		$key_record = array(
			'scopes' => $this->sanitize_scopes( (array) $record['scopes'] ),
		);
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
		$ip_keys = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare
			'HTTP_X_FORWARDED_FOR',  // Proxy
			'HTTP_X_REAL_IP',        // Nginx
			'REMOTE_ADDR',           // Standard
		);

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
		$route    = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
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
		if ( preg_match( '#^/mcpwp/v1/approvals/[^/]+/(approve|reject|apply|rollback)(/|$)#', $route ) ) {
			return 'admin';
		}

		// Deploying a stored site blueprint replays a full page/menu/option set onto
		// the live site — a destructive, high-blast-radius op. Require admin; saving,
		// listing, and extracting blueprints stay at their read/write defaults.
		if ( preg_match( '#^/mcpwp/v1/site-blueprints/[^/]+/deploy(/|$)#', $route ) ) {
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
				|| preg_match( '#^/mcpwp/v1/integrations/providers/[^/]+/(key|test)(/|$)#', $route )
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
		);

		if ( in_array( $tool_name, $admin_tools, true ) ) {
			return 'admin';
		}

		if ( preg_match( '/^wp_(create|update|delete|set|upload|bulk|generate|download)/', $tool_name ) ) {
			return 'write';
		}

		return 'read';
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
		if ( empty( $scopes ) ) {
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
			'scopes'          => $this->sanitize_scopes( isset( $record['scopes'] ) ? (array) $record['scopes'] : array() ),
			'role'            => $role,
			'tool_categories' => $tool_categories,
			'rate_limits'     => $this->sanitize_rate_limits( isset( $record['rate_limits'] ) ? $record['rate_limits'] : null ),
			'created_at'      => isset( $record['created_at'] ) ? (string) $record['created_at'] : null,
			'last_used_at'    => isset( $record['last_used_at'] ) ? (string) $record['last_used_at'] : null,
			'revoked_at'      => isset( $record['revoked_at'] ) ? (string) $record['revoked_at'] : null,
		);
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
			return $this->get_default_api_key_scopes();
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
			'content'    => __( 'Content', 'mcpwp' ),
			'media'      => __( 'Media', 'mcpwp' ),
			'taxonomy'   => __( 'Taxonomy', 'mcpwp' ),
			'elementor'           => __( 'Elementor', 'mcpwp' ),
			'elementor-build'     => __( 'Elementor Build', 'mcpwp' ),
			'elementor-templates' => __( 'Elementor Templates', 'mcpwp' ),
			'elementor-theme'     => __( 'Elementor Theme', 'mcpwp' ),
			'elementor-info'      => __( 'Elementor Info', 'mcpwp' ),
			'gutenberg'  => __( 'Gutenberg', 'mcpwp' ),
			'seo'        => __( 'SEO', 'mcpwp' ),
			'forms'      => __( 'Forms', 'mcpwp' ),
			'site'       => __( 'Site', 'mcpwp' ),
			'admin'      => __( 'Admin', 'mcpwp' ),
			'webhooks'   => __( 'Webhooks', 'mcpwp' ),
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

		return array(
			'oauth_enabled'            => ! empty( $settings['oauth_enabled'] ),
			'oauth_client_id'          => isset( $settings['oauth_client_id'] ) ? sanitize_key( (string) $settings['oauth_client_id'] ) : 'site_pilot_ai',
			'oauth_client_secret_hash' => isset( $settings['oauth_client_secret_hash'] ) ? (string) $settings['oauth_client_secret_hash'] : '',
			'oauth_token_ttl'          => isset( $settings['oauth_token_ttl'] ) ? max( 300, min( 86400, absint( $settings['oauth_token_ttl'] ) ) ) : 3600,
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
	public function issue_oauth_access_token( $scopes, $ttl ) {
		$ttl    = max( 300, min( 86400, absint( $ttl ) ) );
		$scopes = $this->sanitize_scopes( $scopes );
		$token  = 'mcpwp_at_' . bin2hex( random_bytes( 24 ) );

		$payload = array(
			'scopes'     => $scopes,
			'created_at' => time(),
			'expires_at' => time() + $ttl,
		);

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
