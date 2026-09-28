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
							'description' => __( 'Option key to retrieve.', 'mcpwp' ),
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
							'description' => __( 'Option key to update.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'value' => array(
							'description' => __( 'Option value to set.', 'mcpwp' ),
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
		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		if ( empty( $params ) ) {
			return $this->error_response(
				'missing_settings',
				__( 'Settings data is required.', 'mcpwp' ),
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
			'posts_per_page' => 'posts_per_page',
		);

		// admin_email is write-protected on every surface, this one included:
		// a direct write skips the confirmation email and repoints password
		// resets. The refusal is by name so it is never mistaken for a
		// successful no-op.
		if ( isset( $params['admin_email'] ) ) {
			$this->log_activity( 'update_settings_refused', $request, array( 'option' => 'admin_email', 'rule' => 'write_protected:admin_email' ), 403 );
			return new WP_Error(
				'forbidden_option',
				__( 'admin_email is write-protected: a direct write skips the confirmation email and repoints password resets. Change it in WP Admin (rule: write_protected:admin_email).', 'mcpwp' ),
				array( 'status' => 403, 'rule' => 'write_protected:admin_email' )
			);
		}

		$this->log_activity( 'update_settings', $request );

		foreach ( $allowed as $key => $option ) {
			if ( isset( $params[ $key ] ) ) {
				$value = $params[ $key ];

				// Sanitize based on type
				if ( 'posts_per_page' === $key ) {
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
				__( 'No valid settings provided to update.', 'mcpwp' ),
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
				__( 'Settings data is required.', 'mcpwp' ),
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
			'option_allowlist'        => 'allowlist',
			// MCP tool profile (#907): 'full' (default, every tool) or
			// 'core' (~25 tools + mcpwp_find_tools/mcpwp_call). Resolved
			// per-request in Mcpwp_REST_MCP::resolve_mcp_tool_profile(),
			// which falls back to 'full' for anything not in the enum below
			// rather than erroring — this validation exists so a deliberate
			// write is caught at the door, not to be the only guard.
			'mcp_tool_profile'        => 'enum:full,core',
			// WebMCP in wp-admin (#981).
			'webmcp_admin_enabled'      => 'bool',
			'webmcp_origin_trial_token' => 'origin_trial_token',
		);

		foreach ( $allowed as $key => $rule ) {
			if ( ! isset( $params[ $key ] ) ) {
				continue;
			}

			$value = $params[ $key ];

			if ( 'bool' === $rule ) {
				$current[ $key ] = (bool) $value;
			} elseif ( 'allowlist' === $rule ) {
				// Exact keys or "prefix_*"; a blocked key cannot be allowlisted
				// and is reported by name instead of dropped.
				if ( ! is_array( $value ) ) {
					return $this->error_response(
						'invalid_allowlist',
						__( 'option_allowlist must be a JSON array of option names or "prefix_*" entries. A string would silently erase the list, so it is refused.', 'mcpwp' ),
						400
					);
				}
				$entries = $value;
				$clean   = array();
				$refused = array();
				foreach ( $entries as $entry ) {
					$ok = Mcpwp_Option_Access::sanitize_allowlist_entry( $entry );
					if ( '' === $ok ) {
						$refused[] = is_scalar( $entry ) ? (string) $entry : gettype( $entry );
						continue;
					}
					$clean[] = $ok;
				}
				if ( ! empty( $refused ) ) {
					return $this->error_response(
						'invalid_allowlist_entry',
						sprintf(
							/* translators: %s: entries */
							__( 'These allowlist entries are not usable: %s. Entries are exact option names or "prefix_*"; credential, entitlement and updater-control keys cannot be allowlisted.', 'mcpwp' ),
							implode( ', ', $refused )
						),
						400
					);
				}
				$current[ $key ] = array_slice( array_values( array_unique( $clean ) ), 0, 200 );
			} elseif ( 'origin_trial_token' === $rule ) {
				$token = Mcpwp_WebMCP::sanitize_origin_trial_token( $value );
				if ( null === $token ) {
					return $this->error_response(
						'invalid_' . $key,
						__( 'webmcp_origin_trial_token must be the origin-trial token exactly as Chrome issued it (base64: letters, digits, +, / and =), or an empty string to clear it.', 'mcpwp' ),
						400
					);
				}
				$current[ $key ] = $token;
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
			} elseif ( 0 === strpos( $rule, 'enum:' ) ) {
				$choices = explode( ',', substr( $rule, 5 ) );
				$val     = sanitize_key( (string) $value );
				if ( ! in_array( $val, $choices, true ) ) {
					return $this->error_response(
						'invalid_' . $key,
						sprintf(
							/* translators: 1: setting key, 2: comma-separated allowed values */
							__( '%1$s must be one of: %2$s.', 'mcpwp' ),
							$key,
							implode( ', ', $choices )
						),
						400
					);
				}
				$current[ $key ] = $val;
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
				__( 'Options data is required.', 'mcpwp' ),
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
				__( 'No valid options provided to update.', 'mcpwp' ),
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

		$key = Mcpwp_Option_Access::sanitize_option_name( $request->get_param( 'key' ) );
		if ( '' === $key ) {
			return $this->error_response( 'invalid_option_name', __( 'Option names are 1–191 characters of letters, digits, _ - . and :.', 'mcpwp' ), 400 );
		}

		$decision = Mcpwp_Option_Access::decide( $key );

		if ( ! $decision['allowed'] ) {
			return $this->forbidden_option( $key, $decision );
		}

		$masked = Mcpwp_Option_Access::redact( $key, get_option( $key ) );

		$body = array(
			'key'      => $key,
			'value'    => $masked['value'],
			'redacted' => $masked['redacted'],
			'rule'     => $decision['rule'],
		);
		if ( isset( $masked['decoded_from'] ) ) {
			$body['decoded_from'] = $masked['decoded_from'];
		}

		return $this->success_response( $body );
	}

	/**
	 * A refusal that names the rule that refused it (#800).
	 *
	 * @param string $key      Option name.
	 * @param array  $decision From Mcpwp_Option_Access::decide().
	 * @return WP_Error
	 */
	private function forbidden_option( $key, array $decision ) {
		return new WP_Error(
			'forbidden_option',
			sprintf(
				/* translators: 1: option key 2: the rule */
				__( 'Option "%1$s" is not accessible via the option API (rule: %2$s).', 'mcpwp' ),
				$key,
				$decision['rule']
			),
			array(
				'status' => 403,
				'rule'   => $decision['rule'],
				'hint'   => isset( $decision['hint'] ) ? $decision['hint'] : '',
			)
		);
	}

	public function update_option_handler( $request ) {
		// Logged after the gate, never before: a refused write must not put
		// the credential it refused into the activity log.
		$key = Mcpwp_Option_Access::sanitize_option_name( $request->get_param( 'key' ) );
		if ( '' === $key ) {
			return $this->error_response( 'invalid_option_name', __( 'Option names are 1–191 characters of letters, digits, _ - . and :.', 'mcpwp' ), 400 );
		}

		$value    = $request->get_param( 'value' );
		$decision = Mcpwp_Option_Access::decide( $key, 'write' );

		if ( ! $decision['allowed'] ) {
			// Refusals are audited too — the log is what gets consulted in
			// exactly the incident where a caller was probing. The redactor
			// makes the payload safe to keep.
			$this->log_activity( 'update_option_refused', $request, array( 'option' => $key, 'rule' => $decision['rule'] ), 403 );
			return $this->forbidden_option( $key, $decision );
		}

		// A masked read echoed back, or a tree sent without its credential
		// field, must never destroy the stored secret. The gate below looks at
		// what the caller SUBMITTED: a preserved field is not one being set.
		// Sanitised HERE, before the merge, so sanitisation only ever runs
		// over what the caller sent — never over a preserved credential,
		// which a '<' or '%' would otherwise silently mutate (round-4 P2-2).
		$submitted     = $this->sanitize_submitted_option_value( $key, $value );
		$allow_secrets = rest_sanitize_boolean( $request->get_param( 'allow_secrets' ) );

		// Markup that executes — script, event handlers, javascript: URLs,
		// frames — is stored only by an administrator. Today every path to
		// this handler is already admin: the /option route table and the
		// wp_update_option tool both require admin scope, and a request with
		// no key record is the in-process admin context (chat, an approved
		// apply). So this cannot fire for a live principal; it is the
		// belt-and-braces that holds if the route-level rule is ever relaxed
		// for a lesser scope — which is the change it exists to survive
		// (#800 rounds 7–8). The write-protection of widget_/sidebars_ is
		// what closed the rendered-raw case.
		if ( Mcpwp_Option_Access::contains_active_markup( $submitted ) ) {
			$record = $this->get_current_api_key_record();
			if ( is_array( $record ) && ! $this->key_has_scope( $record, 'admin' ) ) {
				$this->log_activity( 'update_option_refused', $request, array( 'option' => $key, 'rule' => 'active_markup_requires_admin' ), 403 );
				return new WP_Error(
					'active_markup_requires_admin',
					__( 'The value carries script, an event handler, a javascript: URL or an embedded frame. Storing that in an option needs an admin-scoped key. Nothing was written.', 'mcpwp' ),
					array( 'status' => 403, 'rule' => 'active_markup_requires_admin' )
				);
			}
		}
		try {
			$old_value = get_option( $key );
		} catch ( \Throwable $e ) {
			return $this->error_response( 'read_failed', sprintf( 'A plugin hook threw while reading "%s": %s', $key, $e->getMessage() ), 500 );
		}
		$merged        = Mcpwp_Option_Access::merge_preserving_secrets( $key, $old_value, $submitted, $allow_secrets );
		$value         = $merged['value'];

		if ( ! empty( $merged['refused'] ) ) {
			$this->log_activity( 'update_option_refused', $request, array( 'option' => $key, 'rule' => 'secret_overwrite_refused', 'secret_paths' => $merged['refused'] ), 409 );
			return new WP_Error(
				'secret_write_refused',
				sprintf(
					/* translators: %s: paths */
					__( 'The submitted value replaces a stored structure that holds credential-shaped field(s): %s. Nothing was written. Send the structure with the field omitted (it is preserved), or pass allow_secrets=true to confirm you mean to discard it.', 'mcpwp' ),
					implode( ', ', $merged['refused'] )
				),
				array( 'status' => 409, 'secret_paths' => $merged['refused'] )
			);
		}

		// A write that would set a credential — a gateway passcode, a licence
		// key, an SMTP password — is payment redirection or lockout when it
		// is wrong, and an agent cannot tell from a masked response that it
		// just broke checkout. It has to be asked for by name.
		$secret_paths = Mcpwp_Option_Access::secret_paths_in_write( $key, $submitted );
		if ( ! empty( $secret_paths ) && ! $allow_secrets ) {
			$this->log_activity( 'update_option_refused', $request, array( 'option' => $key, 'rule' => 'secret_write_refused', 'secret_paths' => $secret_paths ), 409 );
			return new WP_Error(
				'secret_write_refused',
				sprintf(
					/* translators: %s: paths */
					__( 'This write would set credential-shaped field(s): %s. Nothing was written. Pass allow_secrets=true to confirm you mean to replace them.', 'mcpwp' ),
					implode( ', ', array_map( function ( $p ) { return '' === $p ? '(value)' : $p; }, $secret_paths ) )
				),
				array(
					'status'       => 409,
					'secret_paths' => $secret_paths,
					'hint'         => 'Replacing a gateway passcode, licence key or SMTP password by accident is payment redirection or lockout, and a masked response cannot tell you it happened. Re-send with allow_secrets=true if that is the intent.',
				)
			);
		}

		// A redacted read is not data. Whatever the encoding, '***' at any
		// depth never reaches storage.
		if ( Mcpwp_Option_Access::contains_mask( $value ) ) {
			$this->log_activity( 'update_option_refused', $request, array( 'option' => $key, 'rule' => 'mask_in_write' ), 409 );
			return new WP_Error(
				'mask_in_write',
				__( 'The value contains "***", which is a redaction marker from a read, not data. Nothing was written. Send the real value, or omit the field to keep what is stored.', 'mcpwp' ),
				array( 'status' => 409 )
			);
		}

		// Overriding the type guard must not become a way past the secret
		// guard: replacing an array that holds credentials with a string
		// needs the same confirmation as touching the credentials.
		if ( rest_sanitize_boolean( $request->get_param( 'force_type_change' ) ) && ! $allow_secrets ) {
			$stored_secrets = Mcpwp_Option_Access::secret_paths_in_write( $key, $old_value );
			if ( ! empty( $stored_secrets ) ) {
				$this->log_activity( 'update_option_refused', $request, array( 'option' => $key, 'rule' => 'force_type_change_over_secrets' ), 409 );
				return new WP_Error(
					'secret_write_refused',
					__( 'force_type_change would discard credential-shaped fields the stored value holds. Pass allow_secrets=true as well if that is intended.', 'mcpwp' ),
					array( 'status' => 409, 'secret_paths' => $stored_secrets )
				);
			}
		}

		$this->log_activity( 'update_option', $request );

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
					sprintf( __( 'Option "%s" currently holds an array/object; refusing to overwrite it with a plain string. Send the value as a JSON object/array, or pass force_type_change=true to override.', 'mcpwp' ), $key ),
					409
				);
			}
		}

		// Core scalar keys with a fixed type.
		if ( in_array( $key, array( 'posts_per_page', 'posts_per_rss', 'page_on_front', 'page_for_posts', 'default_category', 'thumbnail_size_w', 'thumbnail_size_h', 'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h', 'site_icon' ), true ) ) {
			$value = absint( $value );
		} elseif ( 'blog_public' === $key ) {
			$value = $value ? 1 : 0;
		}

		// Core text keys are trimmed, as WordPress's own settings screens
		// trim them; every other value is stored as sent. permalink_structure,
		// category_base and tag_base are the ones where a stray space breaks
		// every URL on the site (sanitize_option() encodes it as %20) — #800
		// round 8.
		if ( is_string( $value ) && in_array( $key, array( 'blogname', 'blogdescription', 'timezone_string', 'date_format', 'time_format', 'WPLANG', 'permalink_structure', 'category_base', 'tag_base' ), true ) ) {
			$value = trim( $value );
		}

		// Wrap in try/catch: plugins like Elementor hook into update_option
		// and may throw exceptions in REST context (e.g. AJAX auth checks).
		// The verdict is the STORED state, not the exception: WordPress's own
		// sanitize_option() may alter a value (strip tags from blogname) and
		// still store it, and a hook may throw after the write landed. A
		// write that changed the stored value is reported as applied, with
		// what was stored; one that left it untouched is a refusal
		// (#800 round 7, P1-5).
		$threw = null;
		try {
			update_option( $key, $value );
		} catch ( \Throwable $e ) { // \Error too: a TypeError in a hook is still "the write may have landed".
			$threw = $e;
		}

		// A hook that throws on READ (pre_option_*/option_*) must be a
		// reported failure, never an uncaught fatal (#800 round 9).
		try {
			$stored = get_option( $key );
		} catch ( \Throwable $e ) {
			return $this->error_response( 'read_failed', sprintf( 'A plugin hook threw while reading "%s" after the write: %s', $key, $e->getMessage() ), 500 );
		}
		$landed  = $stored == $value || $stored != $old_value; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- serialisation may retype scalars.
		if ( ! $landed ) {
			return $this->error_response(
				'update_failed',
				null !== $threw ? $threw->getMessage() : __( 'The option was not changed: WordPress refused the value.', 'mcpwp' ),
				null !== $threw ? 500 : 409
			);
		}
		// Two different facts, two different warnings: the store changed the
		// bytes (worth acting on), or a hook threw after the write landed
		// (worth knowing, not the same thing) — #800 round 8.
		$write_warnings = array();
		if ( $stored != $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
			$write_warnings[] = 'WordPress altered the value on store (its own sanitize_option); the value reported is what was stored.';
		}
		if ( null !== $threw ) {
			$write_warnings[] = 'A plugin hook threw after the write landed: ' . $threw->getMessage();
		}

		// Writes may set a secret; reads never return one.
		$new = Mcpwp_Option_Access::redact( $key, $stored );
		$old = Mcpwp_Option_Access::redact( $key, $old_value );

		return $this->success_response(
			array(
				'key'       => $key,
				'value'     => $new['value'],
				'old_value' => $old['value'],
				'redacted'  => array_values( array_unique( array_merge( $new['redacted'], $old['redacted'] ) ) ),
				'preserved' => $merged['preserved'],
				'warnings'  => array_merge( isset( $merged['warnings'] ) ? $merged['warnings'] : array(), $write_warnings ),
				'updated'   => true,
				'rule'      => $decision['rule'],
			)
		);
	}

	/**
	 * Sanitise what the caller submitted, in whatever encoding it came: a
	 * tree leaf-by-leaf, an encoded string through its tree and back into
	 * the same encoding, a plain string as text. Runs before the merge so a
	 * preserved credential is never touched.
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Submitted value.
	 * @return mixed
	 */
	private function sanitize_submitted_option_value( $key, $value ) {
		// Options hold HTML templates, e-mail footers, CSS, %-encoded URLs,
		// keys. A text-field sanitiser destroys all of those and made every
		// GET → POST round trip lossy (#800 round 6). What is removed here
		// is only what can never be data: NUL bytes and invalid UTF-8.
		// Type-specific keys (emails, integers, flags) are handled after
		// the merge; whether HTML may be stored is decided by the option's
		// own allowlisting, not rewritten byte by byte.
		return Mcpwp_Option_Access::map_string_leaves(
			$value,
			function ( $leaf ) {
				$leaf = str_replace( "\0", '', (string) $leaf );
				if ( function_exists( 'wp_check_invalid_utf8' ) ) {
					$checked = wp_check_invalid_utf8( $leaf );
					if ( '' === $checked && '' !== $leaf ) {
						return '';
					}
					$leaf = $checked;
				}
				return $leaf;
			}
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
					__( 'Invalid media ID.', 'mcpwp' ),
					400
				);
			}

			// Verify it's an image
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				return $this->error_response(
					'not_image',
					__( 'Attachment must be an image.', 'mcpwp' ),
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

			// SSRF protection: block internal/private URLs (re-checked on every
			// redirect hop by safe_download_url() below).
			if ( class_exists( 'Mcpwp_Security' ) ) {
				$ssrf_check = Mcpwp_Security::validate_external_url( $url );
				if ( is_wp_error( $ssrf_check ) ) {
					return $ssrf_check;
				}
			}

			// Download and sideload the image via the SSRF-guarded downloader so a
			// redirect cannot bypass the check above.
			$tmp = class_exists( 'Mcpwp_Security' )
				? Mcpwp_Security::safe_download_url( $url )
				: download_url( $url );

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

			$attachment_id = media_handle_sideload( $file_array, 0, __( 'Site Icon', 'mcpwp' ) );

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
			__( 'Provide either "id" (media ID) or "url" (image URL).', 'mcpwp' ),
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
					'message' => __( 'No favicon was set.', 'mcpwp' ),
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
