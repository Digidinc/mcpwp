<?php
/**
 * Settings, options & favicon.
 *
 * Carved from the original Mcpwp_REST_Site (G1 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings, options & favicon.
 */
class Mcpwp_REST_Site_Settings extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/plugin-settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_plugin_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_plugin_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/options',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_options' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_options' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/option',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_option_handler' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'key' => array(
							'description' => __( 'Option key to retrieve.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_option_handler' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'key'   => array(
							'description' => __( 'Option key to update.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'value' => array(
							'description' => __( 'Option value to set.', 'mumcp' ),
							'required'    => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/favicon',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_favicon' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_favicon' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_favicon' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	public function get_settings( $request ) {
		$this->log_activity( 'get_settings', $request );

		$settings = array(
			'title'               => get_option( 'blogname' ),
			'tagline'             => get_option( 'blogdescription' ),
			'url'                 => get_option( 'siteurl' ),
			'home'                => get_option( 'home' ),
			'admin_email'         => get_option( 'admin_email' ),
			'timezone'            => get_option( 'timezone_string' ) ?: 'UTC',
			'date_format'         => get_option( 'date_format' ),
			'time_format'         => get_option( 'time_format' ),
			'language'            => get_option( 'WPLANG' ) ?: 'en_US',
			'posts_per_page'      => (int) get_option( 'posts_per_page' ),
			'permalink_structure' => get_option( 'permalink_structure' ),
			'show_on_front'       => get_option( 'show_on_front' ),
			'page_on_front'       => (int) get_option( 'page_on_front' ),
			'page_for_posts'      => (int) get_option( 'page_for_posts' ),
		);

		return $this->success_response( $settings );
	}

	public function update_settings( $request ) {
		$this->log_activity( 'update_settings', $request );

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		if ( empty( $params ) ) {
			return $this->error_response(
				'missing_settings',
				__( 'Settings data is required.', 'mumcp' ),
				400
			);
		}

		$updated = array();

		// Allowed settings to update
		$allowed = array(
			'title'          => 'blogname',
			'tagline'        => 'blogdescription',
			'timezone'       => 'timezone_string',
			'date_format'    => 'date_format',
			'time_format'    => 'time_format',
			'admin_email'    => 'admin_email',
			'posts_per_page' => 'posts_per_page',
		);

		foreach ( $allowed as $key => $option ) {
			if ( isset( $params[ $key ] ) ) {
				$value = $params[ $key ];

				// Sanitize based on type
				if ( 'admin_email' === $key ) {
					$value = sanitize_email( $value );
					if ( ! is_email( $value ) ) {
						continue;
					}
				} elseif ( 'posts_per_page' === $key ) {
					$value = absint( $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				update_option( $option, $value );
				$updated[ $key ] = $value;
			}
		}

		if ( empty( $updated ) ) {
			return $this->error_response(
				'no_valid_settings',
				__( 'No valid settings provided to update.', 'mumcp' ),
				400
			);
		}

		return $this->success_response(
			array(
				'updated'  => $updated,
				'settings' => $this->get_settings( $request )->get_data(),
			)
		);
	}

	public function get_plugin_settings( $request ) {
		$this->log_activity( 'get_plugin_settings', $request );

		$settings = get_option( 'mcpwp_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		// Redact sensitive values — show presence but not the value.
		$safe = array();
		$secret_keys = array( 'oauth_client_secret_hash', 'github_token', 'screenshot_worker_token' );
		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, $secret_keys, true ) ) {
				$safe[ $key ] = ! empty( $value ) ? '***configured***' : '';
			} else {
				$safe[ $key ] = $value;
			}
		}

		return $this->success_response( $safe );
	}

	public function update_plugin_settings( $request ) {
		$this->log_activity( 'update_plugin_settings', $request );

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			// Fallback to body params (for internal MCP dispatch).
			$params = $request->get_body_params();
		}
		if ( empty( $params ) ) {
			return $this->error_response(
				'missing_settings',
				__( 'Settings data is required.', 'mumcp' ),
				400
			);
		}

		$current = get_option( 'mcpwp_settings', array() );
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		$updated = array();

		// Allowed keys with their sanitization rules.
		$allowed = array(
			'enable_logging'          => 'bool',
			'log_retention_days'      => 'int:1:365',
			'log_store_response_data' => 'bool',
			'allowed_origins'         => 'text',
			'oauth_enabled'           => 'bool',
			'oauth_client_id'         => 'key',
			'oauth_client_secret'     => 'secret',
			'oauth_token_ttl'         => 'int:60:86400',
			'alerts_enabled'          => 'bool',
			'alerts_window_minutes'   => 'int:1:60',
			'alerts_cooldown_minutes' => 'int:1:1440',
			'alerts_5xx_threshold'    => 'int:1:10000',
			'alerts_auth_threshold'   => 'int:1:10000',
			'github_token'            => 'secret',
			'github_repo'             => 'text',
		);

		foreach ( $allowed as $key => $rule ) {
			if ( ! isset( $params[ $key ] ) ) {
				continue;
			}

			$value = $params[ $key ];

			if ( 'bool' === $rule ) {
				$current[ $key ] = (bool) $value;
			} elseif ( 'text' === $rule ) {
				$current[ $key ] = sanitize_text_field( (string) $value );
			} elseif ( 'key' === $rule ) {
				$current[ $key ] = sanitize_key( (string) $value );
			} elseif ( 'secret' === $rule ) {
				$val = trim( (string) $value );
				if ( '' !== $val ) {
					if ( 'oauth_client_secret' === $key ) {
						$current['oauth_client_secret_hash'] = wp_hash_password( $val );
					} else {
						$current[ $key ] = sanitize_text_field( $val );
					}
				}
			} elseif ( 0 === strpos( $rule, 'int:' ) ) {
				$parts = explode( ':', $rule );
				$min   = (int) $parts[1];
				$max   = (int) $parts[2];
				$current[ $key ] = max( $min, min( $max, absint( $value ) ) );
			}

			$updated[] = $key;
		}

		if ( empty( $updated ) ) {
			return $this->error_response(
				'no_valid_settings',
				sprintf(
					'No valid settings provided. Allowed keys: %s',
					implode( ', ', array_keys( $allowed ) )
				),
				400
			);
		}

		update_option( 'mcpwp_settings', $current );

		return $this->success_response( array(
			'updated' => $updated,
			'message' => sprintf( 'Updated %d setting(s).', count( $updated ) ),
		) );
	}

	public function get_options( $request ) {
		$this->log_activity( 'get_options', $request );

		$options = array(
			'show_on_front'  => get_option( 'show_on_front' ),
			'page_on_front'  => (int) get_option( 'page_on_front' ),
			'page_for_posts' => (int) get_option( 'page_for_posts' ),
			'posts_per_page' => (int) get_option( 'posts_per_page' ),
			'posts_per_rss'  => (int) get_option( 'posts_per_rss' ),
			'blog_public'    => (int) get_option( 'blog_public' ),
		);

		// Include page names for context
		if ( $options['page_on_front'] ) {
			$page                           = get_post( $options['page_on_front'] );
			$options['page_on_front_title'] = $page ? $page->post_title : null;
		}

		if ( $options['page_for_posts'] ) {
			$page                            = get_post( $options['page_for_posts'] );
			$options['page_for_posts_title'] = $page ? $page->post_title : null;
		}

		return $this->success_response( $options );
	}

	public function update_options( $request ) {
		$this->log_activity( 'update_options', $request );

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		if ( empty( $params ) ) {
			return $this->error_response(
				'missing_options',
				__( 'Options data is required.', 'mumcp' ),
				400
			);
		}

		$updated = array();

		// show_on_front: 'posts' or 'page'
		if ( isset( $params['show_on_front'] ) ) {
			$value = sanitize_key( $params['show_on_front'] );
			if ( in_array( $value, array( 'posts', 'page' ), true ) ) {
				update_option( 'show_on_front', $value );
				$updated['show_on_front'] = $value;
			}
		}

		// page_on_front: ID of front page
		if ( isset( $params['page_on_front'] ) ) {
			$page_id = absint( $params['page_on_front'] );
			if ( 0 === $page_id || get_post( $page_id ) ) {
				update_option( 'page_on_front', $page_id );
				$updated['page_on_front'] = $page_id;
			}
		}

		// page_for_posts: ID of posts page
		if ( isset( $params['page_for_posts'] ) ) {
			$page_id = absint( $params['page_for_posts'] );
			if ( 0 === $page_id || get_post( $page_id ) ) {
				update_option( 'page_for_posts', $page_id );
				$updated['page_for_posts'] = $page_id;
			}
		}

		// posts_per_page
		if ( isset( $params['posts_per_page'] ) ) {
			$value = absint( $params['posts_per_page'] );
			if ( $value > 0 ) {
				update_option( 'posts_per_page', $value );
				$updated['posts_per_page'] = $value;
			}
		}

		// posts_per_rss
		if ( isset( $params['posts_per_rss'] ) ) {
			$value = absint( $params['posts_per_rss'] );
			if ( $value > 0 ) {
				update_option( 'posts_per_rss', $value );
				$updated['posts_per_rss'] = $value;
			}
		}

		// blog_public (search engine visibility)
		if ( isset( $params['blog_public'] ) ) {
			$value = $params['blog_public'] ? 1 : 0;
			update_option( 'blog_public', $value );
			$updated['blog_public'] = $value;
		}

		if ( empty( $updated ) ) {
			return $this->error_response(
				'no_valid_options',
				__( 'No valid options provided to update.', 'mumcp' ),
				400
			);
		}

		return $this->success_response(
			array(
				'updated' => $updated,
				'options' => $this->get_options( $request )->get_data(),
			)
		);
	}

	public function get_option_handler( $request ) {
		$this->log_activity( 'get_option', $request );

		$key = sanitize_key( (string) $request->get_param( 'key' ) );

		if ( ! $this->is_option_allowed( $key ) ) {
			return $this->error_response(
				'forbidden_option',
				/* translators: %s: option key */
				sprintf( __( 'Option "%s" is not accessible via API. Allowed: core WP options and prefixes: elementor_*, wpseo_*, rank_math_*, astra_*, theme_mods_*, widget_*, woocommerce_*, mcpwp_*. Sensitive keys (passwords, tokens, secrets) are always blocked.', 'mumcp' ), $key ),
				403
			);
		}

		$value = get_option( $key );

		return $this->success_response(
			array(
				'key'   => $key,
				'value' => $value,
			)
		);
	}

	public function update_option_handler( $request ) {
		$this->log_activity( 'update_option', $request );

		$key   = sanitize_key( (string) $request->get_param( 'key' ) );
		$value = $request->get_param( 'value' );

		if ( ! $this->is_option_allowed( $key ) ) {
			return $this->error_response(
				'forbidden_option',
				/* translators: %s: option key */
				sprintf( __( 'Option "%s" is not accessible via API. Allowed: core WP options and prefixes: elementor_*, wpseo_*, rank_math_*, astra_*, theme_mods_*, widget_*, woocommerce_*, mcpwp_*. Sensitive keys (passwords, tokens, secrets) are always blocked.', 'mumcp' ), $key ),
				403
			);
		}

		$old_value = get_option( $key );

		// Type-preserving guard: when the stored option is an array/object,
		// a plain-string overwrite is almost always an accident (string-typed
		// MCP clients) and breaks consumers that expect arrays — e.g. a
		// stringified elementor_pro_theme_builder_conditions removes every
		// theme-builder location site-wide. Decode valid JSON; otherwise
		// refuse unless the caller explicitly forces the type change.
		if ( ( is_array( $old_value ) || is_object( $old_value ) ) && is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			} elseif ( ! rest_sanitize_boolean( $request->get_param( 'force_type_change' ) ) ) {
				return $this->error_response(
					'option_type_mismatch',
					/* translators: %s: option key */
					sprintf( __( 'Option "%s" currently holds an array/object; refusing to overwrite it with a plain string. Send the value as a JSON object/array, or pass force_type_change=true to override.', 'mumcp' ), $key ),
					409
				);
			}
		}

		// Type-specific sanitization
		if ( 'admin_email' === $key ) {
			$value = sanitize_email( $value );
			if ( ! is_email( $value ) ) {
				return $this->error_response( 'invalid_email', __( 'Invalid email address.', 'mumcp' ), 400 );
			}
		} elseif ( in_array( $key, array( 'posts_per_page', 'posts_per_rss', 'page_on_front', 'page_for_posts', 'default_category', 'thumbnail_size_w', 'thumbnail_size_h', 'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h', 'site_icon' ), true ) ) {
			$value = absint( $value );
		} elseif ( 'blog_public' === $key ) {
			$value = $value ? 1 : 0;
		} elseif ( is_string( $value ) ) {
			$value = sanitize_text_field( $value );
		} elseif ( is_array( $value ) ) {
			$value = map_deep( $value, 'sanitize_text_field' );
		}

		// Wrap in try/catch: plugins like Elementor hook into update_option
		// and may throw exceptions in REST context (e.g. AJAX auth checks).
		try {
			update_option( $key, $value );
		} catch ( \Exception $e ) {
			// Option was likely still updated before the hook threw.
			// Verify by re-reading.
			$current = get_option( $key );
			if ( $current !== $value ) {
				return $this->error_response(
					'update_failed',
					$e->getMessage(),
					500
				);
			}
		}

		return $this->success_response(
			array(
				'key'       => $key,
				'value'     => get_option( $key ),
				'old_value' => $old_value,
				'updated'   => true,
			)
		);
	}

	private function is_option_allowed( $key ) {
		// Exact match always wins.
		if ( in_array( $key, $this->get_allowed_option_keys(), true ) ) {
			return true;
		}

		// Check blocked patterns first — these override prefix matches.
		$lower_key = strtolower( $key );
		foreach ( $this->get_blocked_option_patterns() as $pattern ) {
			if ( false !== strpos( $lower_key, $pattern ) ) {
				return false;
			}
		}

		// Check allowed prefixes.
		foreach ( $this->get_allowed_option_prefixes() as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private function get_allowed_option_keys() {
		return array(
			// WordPress core.
			'blogname',
			'blogdescription',
			'siteurl',
			'home',
			'admin_email',
			'timezone_string',
			'date_format',
			'time_format',
			'WPLANG',
			'posts_per_page',
			'posts_per_rss',
			'show_on_front',
			'page_on_front',
			'page_for_posts',
			'blog_public',
			'permalink_structure',
			'default_category',
			'default_post_format',
			'thumbnail_size_w',
			'thumbnail_size_h',
			'medium_size_w',
			'medium_size_h',
			'large_size_w',
			'large_size_h',
			'site_icon',
			// Theme settings (hyphenated keys that don't match prefix rules).
			'astra-settings',
			'generate_settings',
			// MCPWP.
			'mcpwp_site_context',
			'mcpwp_site_context_updated',
			// Elementor.
			'elementor_pro_theme_builder_conditions',
		);
	}

	private function get_allowed_option_prefixes() {
		return array(
			'elementor_',
			'wpseo_',
			'rank_math_',
			'astra_',
			'ocean_',
			'theme_mods_',
			'widget_',
			'nav_menu_',
			'sidebars_',
			'mcpwp_',
			'woocommerce_',
			'wp_page_for_privacy_policy',
		);
	}

	private function get_blocked_option_patterns() {
		return array(
			'password',
			'secret',
			'token',
			'auth_key',
			'auth_salt',
			'logged_in_key',
			'logged_in_salt',
			'nonce_key',
			'nonce_salt',
			'secure_auth',
			'credential',
			'api_key',
			'private_key',
			'license_key',
			'stripe_',
			'paypal_',
			'_session',
			// Entitlement / bridge surfaces — must never be settable through the
			// options API. The licensing bridge (issue #505) reads legacy spai_ originals
			// only; block the writable mcpwp_ copies + the migration flag too,
			// as defence in depth against entitlement forgery (Warden 3.1.0 P0).
			'pro_license',
			'trial_started',
			'migrated_from',
			// Update-control options steer the self-updater (manifest + package
			// source). Writing mcpwp_update_info / mcpwp_version_url through the
			// options API is a write-scope→RCE / SSRF path — block both here.
			'update_info',
			'version_url',
			// Secret-bearing internal stores. No scope (including admin) should be
			// able to read or write these through the generic /option API — the
			// dedicated /plugin-settings and /integrations routes are the only
			// intended surface:
			//   mcpwp_settings      → holds oauth_client_secret_hash / github_token
			//   mcpwp_integrations  → encrypted provider blobs
			//   mcpwp_encryption_key→ sodium key that decrypts the blobs above
			// Full option names used as substrings (not bare 'settings'/'integrations')
			// to avoid over-blocking astra-settings / generate_settings / *_settings.
			'mcpwp_settings',
			'integrations',
			'encryption',
		);
	}

	public function get_favicon( $request ) {
		$this->log_activity( 'get_favicon', $request );

		$site_icon_id = get_option( 'site_icon' );

		if ( ! $site_icon_id ) {
			return $this->success_response(
				array(
					'has_favicon' => false,
					'id'          => null,
					'url'         => null,
					'sizes'       => array(),
				)
			);
		}

		$sizes      = array();
		$icon_sizes = array( 32, 180, 192, 270, 512 );

		foreach ( $icon_sizes as $size ) {
			$icon_url = get_site_icon_url( $size );
			if ( $icon_url ) {
				$sizes[ $size ] = $icon_url;
			}
		}

		return $this->success_response(
			array(
				'has_favicon' => true,
				'id'          => (int) $site_icon_id,
				'url'         => get_site_icon_url( 512 ),
				'sizes'       => $sizes,
			)
		);
	}

	public function update_favicon( $request ) {
		$this->log_activity( 'update_favicon', $request );

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		// Option 1: Set by media ID
		if ( ! empty( $params['id'] ) ) {
			$attachment_id = absint( $params['id'] );
			$attachment    = get_post( $attachment_id );

			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return $this->error_response(
					'invalid_attachment',
					__( 'Invalid media ID.', 'mumcp' ),
					400
				);
			}

			// Verify it's an image
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return $this->error_response(
					'not_image',
					__( 'Attachment must be an image.', 'mumcp' ),
					400
				);
			}

			update_option( 'site_icon', $attachment_id );

			return $this->success_response(
				array(
					'updated' => true,
					'favicon' => $this->get_favicon( $request )->get_data(),
				)
			);
		}

		// Option 2: Upload from URL
		if ( ! empty( $params['url'] ) ) {
			if ( ! function_exists( 'media_handle_sideload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			if ( ! function_exists( 'wp_handle_upload' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$url = esc_url_raw( $params['url'] );

			// SSRF protection: block internal/private URLs.
			if ( class_exists( 'Mcpwp_Security' ) ) {
				$ssrf_check = Mcpwp_Security::validate_external_url( $url );
				if ( is_wp_error( $ssrf_check ) ) {
					return $ssrf_check;
				}
			}

			// Download and sideload the image
			$tmp = download_url( $url );

			if ( is_wp_error( $tmp ) ) {
				return $this->error_response(
					'download_failed',
					$tmp->get_error_message(),
					400
				);
			}

			$file_array = array(
				'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
				'tmp_name' => $tmp,
			);

			$attachment_id = media_handle_sideload( $file_array, 0, __( 'Site Icon', 'mumcp' ) );

			if ( is_wp_error( $attachment_id ) ) {
				wp_delete_file( $tmp );
				return $this->error_response(
					'upload_failed',
					$attachment_id->get_error_message(),
					400
				);
			}

			update_option( 'site_icon', $attachment_id );

			return $this->success_response(
				array(
					'updated'  => true,
					'uploaded' => true,
					'favicon'  => $this->get_favicon( $request )->get_data(),
				),
				201
			);
		}

		return $this->error_response(
			'missing_param',
			__( 'Provide either "id" (media ID) or "url" (image URL).', 'mumcp' ),
			400
		);
	}

	public function delete_favicon( $request ) {
		$this->log_activity( 'delete_favicon', $request );

		$site_icon_id = get_option( 'site_icon' );

		if ( ! $site_icon_id ) {
			return $this->success_response(
				array(
					'deleted' => false,
					'message' => __( 'No favicon was set.', 'mumcp' ),
				)
			);
		}

		delete_option( 'site_icon' );

		return $this->success_response(
			array(
				'deleted'     => true,
				'previous_id' => (int) $site_icon_id,
			)
		);
	}

}
