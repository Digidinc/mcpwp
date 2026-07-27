<?php
/**
 * Plugin update, rate limiting, API keys & OAuth.
 *
 * Carved from the original Mcpwp_REST_Site (G1 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin update, rate limiting, API keys & OAuth.
 */
class Mcpwp_REST_Site_Updates extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// The /update endpoint fetches from mumega.com — excluded from the WordPress.org build
		// which must not contain third-party update paths (WP.org policy).
		if ( ! defined( 'MCPWP_WPORG_BUILD' ) ) {
			register_rest_route(
				$this->namespace,
				'/update',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'check_update' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'trigger_update' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
				)
			);
		}
		register_rest_route(
			$this->namespace,
			'/rate-limit',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rate_limit_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_rate_limit_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'enabled'             => array(
							'description' => __( 'Enable or disable rate limiting.', 'mcpwp' ),
							'type'        => 'boolean',
						),
						'requests_per_minute' => array(
							'description' => __( 'Requests allowed per minute.', 'mcpwp' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'requests_per_hour'   => array(
							'description' => __( 'Requests allowed per hour.', 'mcpwp' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'burst_limit'         => array(
							'description' => __( 'Requests allowed in short burst window.', 'mcpwp' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'whitelist'           => array(
							'description' => __( 'Identifiers to bypass rate limiting.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
							),
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/rate-limit/reset',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reset_rate_limit' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'identifier' => array(
							'description' => __( 'Rate-limit identifier to reset (for example: key:<id> or IP).', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/api-keys',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_api_keys' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'include_revoked' => array(
							'description' => __( 'Include revoked keys.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_api_key' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'label'  => array(
							'description' => __( 'Key label.', 'mcpwp' ),
							'type'        => 'string',
						),
						'scopes' => array(
							'description' => __( 'Scopes for key. Allowed: read, write, admin. Defaults to read-only when omitted.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
								'enum' => array( 'read', 'write', 'admin' ),
							),
							'default'     => array( 'read' ),
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/api-keys/(?P<id>[a-z0-9\\-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke_api_key' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Whether this build ships the self-hosted update channel.
	 *
	 * `.distignore` prunes class-mcpwp-updater.php from the Freemius build,
	 * where the license provider owns updates instead. Every entry point that
	 * reaches for Mcpwp_Updater has to ask first — calling it unguarded is a
	 * fatal on every premium install, not a degraded response (#723).
	 *
	 * @return bool
	 */
	private function has_self_hosted_updater() {
		return class_exists( 'Mcpwp_Updater' );
	}

	/**
	 * Response for builds whose updates are managed outside this plugin.
	 *
	 * Not an error: the site is working as designed, so this reports the same
	 * shape check_update() always returns rather than a failure the caller has
	 * to special-case.
	 *
	 * @return array
	 */
	private function externally_managed_state() {
		return array(
			'current_version'  => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
			'update_available' => false,
			'new_version'      => null,
			'has_package'      => false,
			'source'           => null,
			'option_version'   => null,
			'remote_version'   => null,
			'reason'           => 'not_applicable',
		);
	}

	public function check_update( $request ) {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return $this->error_response( 'not_available', __( 'Update check is not available in the WordPress.org build.', 'mcpwp' ), 404 );
		}

		$this->log_activity( 'check_update', $request );

		if ( ! $this->has_self_hosted_updater() ) {
			return $this->success_response( $this->externally_managed_state() );
		}

		$state = Mcpwp_Updater::instance()->get_update_state( true );

		// Refresh WordPress's own view so the Plugins screen agrees with what we
		// just resolved. Advisory only — our answer comes from the manifest, not
		// from the transient, which stays empty whenever core cannot reach
		// api.wordpress.org (#716).
		// get_update_state( true ) already refreshed the manifest cache, so leave it
		// in place for wp_update_plugins() to reuse rather than refetching.
		delete_site_transient( 'update_plugins' );
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		return $this->success_response(
			array(
				'current_version'  => $state['current_version'],
				'update_available' => $state['update_available'],
				'new_version'      => $state['update_available'] ? $state['selected_version'] : null,
				'has_package'      => ! empty( $state['package'] ),
				'source'           => $state['source'],
				'option_version'   => $state['option_version'],
				'remote_version'   => $state['remote_version'],
				'reason'           => $state['reason'],
			)
		);
	}

	/**
	 * Explain why no update is being installed.
	 *
	 * "No update available" is the same sentence whether the site is genuinely
	 * current or the manifest was unreachable or rejected — which made #716
	 * impossible to diagnose from the response alone.
	 *
	 * @param array $state Update state from Mcpwp_Updater::get_update_state().
	 * @return string Human-readable explanation.
	 */
	private function describe_no_update( array $state ) {
		if ( ! $this->has_self_hosted_updater() ) {
			return __( 'This build does not use the self-hosted update channel.', 'mcpwp' );
		}

		return Mcpwp_Updater::describe_reason( isset( $state['reason'] ) ? $state['reason'] : null );
	}

	public function trigger_update( $request ) {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return $this->error_response( 'not_available', __( 'Plugin self-update is not available in the WordPress.org build.', 'mcpwp' ), 404 );
		}

		$this->log_activity( 'trigger_update', $request );

		// Defense-in-depth: when the request authenticated with a scoped API key,
		// require the admin scope. The REST admin-route gate already enforces this,
		// but the inner check guards against callers on the shared bot user whose
		// WP capability is always present regardless of the key's granted scope.
		$key_record = $this->get_current_api_key_record();
		if ( is_array( $key_record ) && ! $this->key_has_scope( $key_record, 'admin' ) ) {
			return $this->error_response( 'insufficient_scope', __( 'Admin scope is required to update the plugin.', 'mcpwp' ), 403 );
		}

		if ( ! $this->can_manage_api_keys() ) {
			return $this->error_response(
				'forbidden',
				__( 'You do not have permission to update the plugin.', 'mcpwp' ),
				403
			);
		}

		$plugin_file = defined( 'MCPWP_PLUGIN_BASENAME' ) ? MCPWP_PLUGIN_BASENAME : 'mcpwp/mcpwp.php';
		$package_url = $request->get_param( 'package_url' );

		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$clear_update_state = static function () {
			delete_option( 'mcpwp_update_info' );
			delete_transient( 'mcpwp_capabilities_cache' );
			delete_transient( 'mcpwp_update_check' );
			delete_site_transient( 'update_plugins' );
		};

		// If a package_url is provided, install directly from that URL.
		// Not available on WordPress.org builds — updates come via the WP.org update channel.
		if ( ! empty( $package_url ) ) {
			if ( defined( 'MCPWP_WPORG_BUILD' ) && MCPWP_WPORG_BUILD ) {
				return $this->error_response(
					'not_supported',
					__( 'Direct package installs are not supported on WordPress.org builds. Update via the WordPress dashboard.', 'mcpwp' ),
					400
				);
			}

			// Inject into transient so WP_Upgrader finds it.
			$update_plugins = get_site_transient( 'update_plugins' );
			if ( ! is_object( $update_plugins ) ) {
				$update_plugins = new stdClass();
				$update_plugins->response = array();
			}

			// Hard-restrict caller-supplied package URLs to HTTPS + trusted hosts.
			// This blocks a write-scope→RCE path where an untrusted zip could be
			// installed and the plugin reactivated from it.
			if ( ! Mcpwp_Security::is_trusted_package_url( $package_url ) ) {
				return $this->error_response( 'untrusted_package', __( 'The package_url host is not in the trusted update allowlist.', 'mcpwp' ), 400 );
			}

			// Flagged as caller-owned so Mcpwp_Updater::check_for_update() — which
			// re-enters on set_site_transient() — does not overwrite this forced
			// entry with the manifest version. Builds without the self-hosted
			// updater have nothing to re-enter, so the plain entry is enough and
			// a forced install still works there (#723).
			if ( $this->has_self_hosted_updater() ) {
				$update_plugins->response[ $plugin_file ] = Mcpwp_Updater::instance()->build_forced_entry(
					esc_url_raw( $package_url ),
					'999.0.0'
				);
			} else {
				$forced              = new stdClass();
				$forced->slug        = 'mcpwp';
				$forced->plugin      = $plugin_file;
				$forced->new_version = '999.0.0';
				$forced->package     = esc_url_raw( $package_url );

				$update_plugins->response[ $plugin_file ] = $forced;
			}
			set_site_transient( 'update_plugins', $update_plugins );

			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( $plugin_file );

			if ( is_wp_error( $result ) ) {
				return $this->error_response( 'update_failed', $result->get_error_message(), 500 );
			}

			if ( true !== $result ) {
				return $this->error_response( 'update_failed', __( 'Plugin update failed.', 'mcpwp' ), 500 );
			}

			if ( ! is_plugin_active( $plugin_file ) ) {
				activate_plugin( $plugin_file );
			}

			$clear_update_state();

			return $this->success_response(
				array(
					'updated'     => true,
					'source'      => 'package_url',
					'message'     => __( 'Plugin updated from provided URL.', 'mcpwp' ),
				)
			);
		}

		// Standard flow. The manifest is authoritative — WordPress's transient is
		// only populated when core's own api.wordpress.org request succeeds, and
		// depending on it stranded sites on outdated releases (#716).
		delete_site_transient( 'update_plugins' );
		delete_transient( 'mcpwp_update_check' );

		// No self-hosted channel to consult on this build — the license provider
		// installs updates through its own updater, so there is nothing for this
		// endpoint to resolve (#723).
		if ( ! $this->has_self_hosted_updater() ) {
			return $this->success_response(
				array(
					'updated'        => false,
					'message'        => $this->describe_no_update( array() ),
					'reason'         => 'not_applicable',
					'version'        => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
					'remote_version' => null,
				)
			);
		}

		$updater = Mcpwp_Updater::instance();
		$state   = $updater->get_update_state( true );

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		if ( ! $state['update_available'] ) {
			return $this->success_response(
				array(
					'updated'        => false,
					'message'        => $this->describe_no_update( $state ),
					'reason'         => $state['reason'],
					'version'        => $state['current_version'],
					'remote_version' => $state['remote_version'],
				)
			);
		}

		if ( empty( $state['package'] ) ) {
			return $this->error_response(
				'no_package',
				__( 'Update package URL is not available.', 'mcpwp' ),
				400
			);
		}

		// Core may have left the transient without an entry for this plugin (its
		// own update request can fail independently of ours). Plugin_Upgrader
		// reads the package from that transient, so seed it from the manifest.
		$update_plugins = get_site_transient( 'update_plugins' );
		if ( ! is_object( $update_plugins ) ) {
			$update_plugins = new stdClass();
		}
		if ( ! isset( $update_plugins->response ) || ! is_array( $update_plugins->response ) ) {
			$update_plugins->response = array();
		}

		$update_plugins->response[ $plugin_file ] = $updater->build_forced_entry(
			$state['package'],
			$state['selected_version']
		);
		set_site_transient( 'update_plugins', $update_plugins );

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			return $this->error_response(
				'update_failed',
				$result->get_error_message(),
				500
			);
		}

		if ( true !== $result ) {
			return $this->error_response(
				'update_failed',
				__( 'Plugin update failed.', 'mcpwp' ),
				500
			);
		}

		// Reactivate if needed.
		if ( ! is_plugin_active( $plugin_file ) ) {
			activate_plugin( $plugin_file );
		}

		$clear_update_state();

		return $this->success_response(
			array(
				'updated'     => true,
				'new_version' => is_object( $plugin_update ) ? $plugin_update->new_version : null,
				'message'     => __( 'Plugin updated successfully.', 'mcpwp' ),
			)
		);
	}

	public function get_rate_limit_status( $request ) {
		$this->log_activity( 'rate_limit_status', $request );

		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return $this->success_response(
				array(
					'enabled' => false,
					'message' => __( 'Rate limiting is not available.', 'mcpwp' ),
				)
			);
		}

		$limiter  = Mcpwp_Rate_Limiter::get_instance();
		$settings = $limiter->get_settings();

		// Use the same identifier the rate limiter used when processing this request.
		$api_key     = $this->get_api_key_from_request( $request );
		$matched_key = null;
		if ( $api_key && ! $this->looks_like_oauth_access_token( $api_key ) ) {
			$matched_key = $this->find_scoped_api_key( $api_key );
			if ( ! $matched_key ) {
				$matched_key = $this->find_legacy_spai_key( $api_key );
			}
		}
		$rate_identifier = ( $matched_key && ! empty( $matched_key['id'] ) )
			? 'key:' . sanitize_key( $matched_key['id'] )
			: null;
		$usage = $limiter->get_usage( $rate_identifier );

		return $this->success_response(
			array(
				'enabled' => $settings['enabled'],
				'limits'  => array(
					'burst'      => $settings['burst_limit'],
					'per_minute' => $settings['requests_per_minute'],
					'per_hour'   => $settings['requests_per_hour'],
				),
				'usage'   => $usage,
			)
		);
	}

	public function update_rate_limit_settings( $request ) {
		$this->log_activity( 'update_rate_limit_settings', $request );

		if ( ! $this->can_manage_api_keys() ) {
			return $this->error_response(
				'forbidden',
				__( 'You do not have permission to manage rate limiting.', 'mcpwp' ),
				403
			);
		}

		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return $this->error_response(
				'rate_limiter_unavailable',
				__( 'Rate limiting is not available.', 'mcpwp' ),
				500
			);
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) || empty( $params ) ) {
			$params = $request->get_params();
		}

		$allowed  = array( 'enabled', 'requests_per_minute', 'requests_per_hour', 'burst_limit', 'whitelist' );
		$settings = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$settings[ $key ] = $params[ $key ];
			}
		}

		if ( empty( $settings ) ) {
			return $this->error_response(
				'missing_settings',
				__( 'No rate-limit settings provided.', 'mcpwp' ),
				400
			);
		}

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$updated = $limiter->update_settings( $settings );

		if ( ! $updated ) {
			return $this->error_response(
				'update_failed',
				__( 'Failed to update rate-limit settings.', 'mcpwp' ),
				500
			);
		}

		return $this->success_response(
			array(
				'updated'  => true,
				'settings' => $limiter->get_settings(),
			)
		);
	}

	public function reset_rate_limit( $request ) {
		$this->log_activity( 'reset_rate_limit', $request );

		if ( ! $this->can_manage_api_keys() ) {
			return $this->error_response(
				'forbidden',
				__( 'You do not have permission to reset rate limits.', 'mcpwp' ),
				403
			);
		}

		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return $this->error_response(
				'rate_limiter_unavailable',
				__( 'Rate limiting is not available.', 'mcpwp' ),
				500
			);
		}

		$identifier = sanitize_text_field( (string) $request->get_param( 'identifier' ) );
		if ( '' === $identifier ) {
			return $this->error_response(
				'missing_identifier',
				__( 'Identifier is required.', 'mcpwp' ),
				400
			);
		}

		Mcpwp_Rate_Limiter::get_instance()->reset_limit( $identifier );

		return $this->success_response(
			array(
				'reset'      => true,
				'identifier' => $identifier,
			)
		);
	}

	public function list_api_keys( $request ) {
		$this->log_activity( 'list_api_keys', $request );

		if ( ! $this->can_manage_api_keys() ) {
			return $this->error_response(
				'forbidden',
				__( 'You do not have permission to manage API keys.', 'mcpwp' ),
				403
			);
		}

		$include_revoked = (bool) $request->get_param( 'include_revoked' );
		$keys            = $this->list_scoped_api_keys( $include_revoked );

		return $this->success_response(
			array(
				'keys'  => $keys,
				'total' => count( $keys ),
			)
		);
	}

	public function create_api_key( $request ) {
		$this->log_activity( 'create_api_key', $request );

		if ( ! $this->can_manage_api_keys() ) {
			return $this->error_response(
				'forbidden',
				__( 'You do not have permission to manage API keys.', 'mcpwp' ),
				403
			);
		}

		$label           = (string) $request->get_param( 'label' );
		$scopes          = $request->get_param( 'scopes' );
		$scopes          = is_array( $scopes ) ? $scopes : array();
		$role            = (string) $request->get_param( 'role' );
		$tool_categories = $request->get_param( 'tool_categories' );
		$tool_categories = is_array( $tool_categories ) ? $tool_categories : array();

		if ( '' === $role ) {
			$role = 'admin';
		}

		$created = $this->create_scoped_api_key( $label, $scopes, $role, $tool_categories );

		return $this->success_response(
			array(
				'api_key' => $created,
			),
			201
		);
	}

	public function revoke_api_key( $request ) {
		$this->log_activity( 'revoke_api_key', $request );

		if ( ! $this->can_manage_api_keys() ) {
			return $this->error_response(
				'forbidden',
				__( 'You do not have permission to manage API keys.', 'mcpwp' ),
				403
			);
		}

		$key_id  = (string) $request->get_param( 'id' );
		$revoked = $this->revoke_scoped_api_key( $key_id );

		if ( ! $revoked ) {
			return $this->error_response(
				'not_found',
				__( 'API key not found or already revoked.', 'mcpwp' ),
				404
			);
		}

		return $this->success_response(
			array(
				'revoked' => true,
				'id'      => sanitize_key( $key_id ),
			)
		);
	}

	private function can_manage_api_keys() {
		return function_exists( 'current_user_can' ) && current_user_can( 'mcpwp_manage_settings' );
	}

}
