<?php
/**
 * Plugin update, rate limiting, API keys & OAuth.
 *
 * Carved from the original Mcpwp_REST_Site (G1 split). Behavior-identical.
 *
 * @package mumcp
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
							'description' => __( 'Enable or disable rate limiting.', 'mumega-mcp' ),
							'type'        => 'boolean',
						),
						'requests_per_minute' => array(
							'description' => __( 'Requests allowed per minute.', 'mumega-mcp' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'requests_per_hour'   => array(
							'description' => __( 'Requests allowed per hour.', 'mumega-mcp' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'burst_limit'         => array(
							'description' => __( 'Requests allowed in short burst window.', 'mumega-mcp' ),
							'type'        => 'integer',
							'minimum'     => 1,
						),
						'whitelist'           => array(
							'description' => __( 'Identifiers to bypass rate limiting.', 'mumega-mcp' ),
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
							'description' => __( 'Rate-limit identifier to reset (for example: key:<id> or IP).', 'mumega-mcp' ),
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
							'description' => __( 'Include revoked keys.', 'mumega-mcp' ),
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
							'description' => __( 'Key label.', 'mumega-mcp' ),
							'type'        => 'string',
						),
						'scopes' => array(
							'description' => __( 'Scopes for key. Allowed: read, write, admin. Defaults to read-only when omitted.', 'mumega-mcp' ),
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

	public function check_update( $request ) {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return $this->error_response( 'not_available', __( 'Update check is not available in the WordPress.org build.', 'mumega-mcp' ), 404 );
		}

		$this->log_activity( 'check_update', $request );

		$current_version = defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : '0.0.0';
		$option_version  = null;
		$option_package  = null;
		$remote_version  = null;
		$remote_package  = null;
		$selected_source = null;

		$option_data = get_option( 'mcpwp_update_info' );
		if ( is_string( $option_data ) ) {
			$option_data = json_decode( $option_data, true );
		}
		if ( is_array( $option_data ) && ! empty( $option_data['version'] ) ) {
			$option_version = (string) $option_data['version'];
			if ( ! empty( $option_data['download_url'] ) ) {
				$option_package = esc_url_raw( $option_data['download_url'] );
			}
		}

		$version_url = get_option( 'mcpwp_version_url', 'https://mumega.com/mcp-updates/version.json' );
		if ( empty( $version_url ) ) {
			$version_url = 'https://mumega.com/mcp-updates/version.json';
		}
		// Never fetch a version manifest over plain HTTP — mirrors the guard already
		// applied in Mcpwp_Updater::get_remote_manifest() (class-mcpwp-updater.php:153).
		// A non-HTTPS mcpwp_version_url is an SSRF/downgrade vector for the updater.
		$remote_response = Mcpwp_Security::is_https_url( $version_url )
			? wp_remote_get(
				$version_url,
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			)
			: new WP_Error( 'insecure_version_url', __( 'mcpwp_version_url must be HTTPS.', 'mumega-mcp' ) );
		if ( ! is_wp_error( $remote_response ) && 200 === wp_remote_retrieve_response_code( $remote_response ) ) {
			$remote_body = json_decode( wp_remote_retrieve_body( $remote_response ), true );
			if ( is_array( $remote_body ) && ! empty( $remote_body['version'] ) ) {
				$remote_version = (string) $remote_body['version'];
				if ( ! empty( $remote_body['download_url'] ) ) {
					$remote_package = esc_url_raw( $remote_body['download_url'] );
				}
			}
		}

		// Clear update caches and force a fresh check.
		delete_site_transient( 'update_plugins' );
		delete_transient( 'mcpwp_update_check' );

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		$update_plugins = get_site_transient( 'update_plugins' );
		$plugin_file    = defined( 'MCPWP_PLUGIN_BASENAME' ) ? MCPWP_PLUGIN_BASENAME : 'mcpwp/mcpwp.php';

		$update_available = false;
		$new_version      = null;
		$package          = null;

		if ( ! empty( $update_plugins->response[ $plugin_file ] ) ) {
			$plugin_update    = $update_plugins->response[ $plugin_file ];
			$new_version      = is_object( $plugin_update ) ? $plugin_update->new_version : null;
			$package          = is_object( $plugin_update ) ? $plugin_update->package : null;
			$update_available = ! empty( $new_version ) && version_compare( $new_version, $current_version, '>' );
		}

		if ( ! empty( $new_version ) ) {
			if ( ! empty( $remote_version ) && version_compare( $new_version, $remote_version, '=' ) ) {
				$selected_source = 'remote';
			} elseif ( ! empty( $option_version ) && version_compare( $new_version, $option_version, '=' ) ) {
				$selected_source = 'option';
			}
		} elseif ( ! empty( $remote_version ) || ! empty( $option_version ) ) {
			if ( ! empty( $remote_version ) && ( empty( $option_version ) || version_compare( $remote_version, $option_version, '>=' ) ) ) {
				$selected_source = 'remote';
			} else {
				$selected_source = 'option';
			}
		}

		$selected_package = $package;
		if ( empty( $selected_package ) ) {
			if ( 'remote' === $selected_source ) {
				$selected_package = $remote_package;
			} elseif ( 'option' === $selected_source ) {
				$selected_package = $option_package;
			}
		}

		return $this->success_response(
			array(
				'current_version'  => $current_version,
				'update_available' => $update_available,
				'new_version'      => $new_version,
				'has_package'      => ! empty( $selected_package ),
				'source'           => $selected_source,
				'option_version'   => $option_version,
				'remote_version'   => $remote_version,
			)
		);
	}

	public function trigger_update( $request ) {
		if ( defined( 'MCPWP_WPORG_BUILD' ) ) {
			return $this->error_response( 'not_available', __( 'Plugin self-update is not available in the WordPress.org build.', 'mumega-mcp' ), 404 );
		}

		$this->log_activity( 'trigger_update', $request );

		// Defense-in-depth: when the request authenticated with a scoped API key,
		// require the admin scope. The REST admin-route gate already enforces this,
		// but the inner check guards against callers on the shared bot user whose
		// WP capability is always present regardless of the key's granted scope.
		$key_record = $this->get_current_api_key_record();
		if ( is_array( $key_record ) && ! $this->key_has_scope( $key_record, 'admin' ) ) {
			return $this->error_response( 'insufficient_scope', __( 'Admin scope is required to update the plugin.', 'mumega-mcp' ), 403 );
		}

		if ( ! $this->can_manage_api_keys() ) {
			return $this->error_response(
				'forbidden',
				__( 'You do not have permission to update the plugin.', 'mumega-mcp' ),
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
					__( 'Direct package installs are not supported on WordPress.org builds. Update via the WordPress dashboard.', 'mumega-mcp' ),
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
				return $this->error_response( 'untrusted_package', __( 'The package_url host is not in the trusted update allowlist.', 'mumega-mcp' ), 400 );
			}

			$inject              = new stdClass();
			$inject->slug        = 'mcpwp';
			$inject->plugin      = $plugin_file;
			$inject->new_version = '999.0.0';
			$inject->package     = esc_url_raw( $package_url );
			$update_plugins->response[ $plugin_file ] = $inject;
			set_site_transient( 'update_plugins', $update_plugins );

			$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( $plugin_file );

			if ( is_wp_error( $result ) ) {
				return $this->error_response( 'update_failed', $result->get_error_message(), 500 );
			}

			if ( true !== $result ) {
				return $this->error_response( 'update_failed', __( 'Plugin update failed.', 'mumega-mcp' ), 500 );
			}

			if ( ! is_plugin_active( $plugin_file ) ) {
				activate_plugin( $plugin_file );
			}

			$clear_update_state();

			return $this->success_response(
				array(
					'updated'     => true,
					'source'      => 'package_url',
					'message'     => __( 'Plugin updated from provided URL.', 'mumega-mcp' ),
				)
			);
		}

		// Standard flow: check for updates via WP transient.
		delete_site_transient( 'update_plugins' );
		delete_transient( 'mcpwp_update_check' );

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		$update_plugins = get_site_transient( 'update_plugins' );

		if ( empty( $update_plugins->response[ $plugin_file ] ) ) {
			return $this->success_response(
				array(
					'updated' => false,
					'message' => __( 'No update available.', 'mumega-mcp' ),
					'version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
				)
			);
		}

		$plugin_update = $update_plugins->response[ $plugin_file ];
		if ( empty( $plugin_update->package ) ) {
			return $this->error_response(
				'no_package',
				__( 'Update package URL is not available.', 'mumega-mcp' ),
				400
			);
		}

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
				__( 'Plugin update failed.', 'mumega-mcp' ),
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
				'message'     => __( 'Plugin updated successfully.', 'mumega-mcp' ),
			)
		);
	}

	public function get_rate_limit_status( $request ) {
		$this->log_activity( 'rate_limit_status', $request );

		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return $this->success_response(
				array(
					'enabled' => false,
					'message' => __( 'Rate limiting is not available.', 'mumega-mcp' ),
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
				__( 'You do not have permission to manage rate limiting.', 'mumega-mcp' ),
				403
			);
		}

		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return $this->error_response(
				'rate_limiter_unavailable',
				__( 'Rate limiting is not available.', 'mumega-mcp' ),
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
				__( 'No rate-limit settings provided.', 'mumega-mcp' ),
				400
			);
		}

		$limiter = Mcpwp_Rate_Limiter::get_instance();
		$updated = $limiter->update_settings( $settings );

		if ( ! $updated ) {
			return $this->error_response(
				'update_failed',
				__( 'Failed to update rate-limit settings.', 'mumega-mcp' ),
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
				__( 'You do not have permission to reset rate limits.', 'mumega-mcp' ),
				403
			);
		}

		if ( ! class_exists( 'Mcpwp_Rate_Limiter' ) ) {
			return $this->error_response(
				'rate_limiter_unavailable',
				__( 'Rate limiting is not available.', 'mumega-mcp' ),
				500
			);
		}

		$identifier = sanitize_text_field( (string) $request->get_param( 'identifier' ) );
		if ( '' === $identifier ) {
			return $this->error_response(
				'missing_identifier',
				__( 'Identifier is required.', 'mumega-mcp' ),
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
				__( 'You do not have permission to manage API keys.', 'mumega-mcp' ),
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
				__( 'You do not have permission to manage API keys.', 'mumega-mcp' ),
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
				__( 'You do not have permission to manage API keys.', 'mumega-mcp' ),
				403
			);
		}

		$key_id  = (string) $request->get_param( 'id' );
		$revoked = $this->revoke_scoped_api_key( $key_id );

		if ( ! $revoked ) {
			return $this->error_response(
				'not_found',
				__( 'API key not found or already revoked.', 'mumega-mcp' ),
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
