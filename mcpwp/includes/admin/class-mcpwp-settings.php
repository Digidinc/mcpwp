<?php
/**
 * Settings functionality
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 */
class Mcpwp_Settings {

	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'mcpwp_settings';

	/**
	 * Rate-limit option name.
	 *
	 * @var string
	 */
	const RATE_LIMIT_OPTION_NAME = 'mcpwp_rate_limit_settings';

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting(
			'mcpwp_settings_group',
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_defaults(),
			)
		);

		register_setting(
			'mcpwp_rate_limit_group',
			self::RATE_LIMIT_OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_rate_limit_settings' ),
				'default'           => $this->get_rate_limit_defaults(),
			)
		);

		register_setting(
			'mcpwp_chat_group',
			'mcpwp_chat_model',
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $v ) {
					return in_array( $v, array( 'auto', 'openai', 'gemini', 'workers' ), true ) ? $v : 'auto';
				},
				'default'           => 'auto',
			)
		);

		// General section
		add_settings_section(
			'mcpwp_general_section',
			__( 'General Settings', 'mcpwp' ),
			array( $this, 'render_general_section' ),
			'mcpwp_settings'
		);

		// Logging
		add_settings_field(
			'enable_logging',
			__( 'Activity Logging', 'mcpwp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'enable_logging',
				'description' => __( 'Log API requests for analytics and debugging.', 'mcpwp' ),
			)
		);

		// Destructive operations need approval (#536). Settable here and
		// nowhere else: the settings API refuses it by design.
		add_settings_field(
			'destructive_requires_approval',
			__( 'Approval for destructive operations', 'mcpwp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'destructive_requires_approval',
				'description' => __( 'Permanent deletes requested through the API (pages, posts, media, products, all drafts …) wait for a second person to approve them. Trashing is never gated. Turning this off lets any admin-scoped key delete permanently in one call.', 'mcpwp' ),
			)
		);

		// Log retention
		add_settings_field(
			'log_retention_days',
			__( 'Log Retention', 'mcpwp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'log_retention_days',
				'description' => __( 'Number of days to keep activity logs.', 'mcpwp' ),
				'min'         => 1,
				'max'         => 365,
				'suffix'      => __( 'days', 'mcpwp' ),
			)
		);

		add_settings_field(
			'log_store_response_data',
			__( 'Store Response Data', 'mcpwp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'log_store_response_data',
				'description' => __( 'Store small response bodies for debugging (redacted). Disable for privacy.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'log_redaction_keys',
			__( 'Redaction Keys', 'mcpwp' ),
			array( $this, 'render_textarea_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'log_redaction_keys',
				'description' => __( 'Comma or newline-separated keys to redact from logged request/response data.', 'mcpwp' ),
				'placeholder' => "api_key\nauthorization\ntoken",
			)
		);

		add_settings_field(
			'alerts_enabled',
			__( 'Enable Alerts', 'mcpwp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_enabled',
				'description' => __( 'Send webhook alerts on API error spikes (requires a configured webhook subscribed to api.alert.* events).', 'mcpwp' ),
			)
		);

		add_settings_field(
			'alerts_window_minutes',
			__( 'Alert Window', 'mcpwp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_window_minutes',
				'description' => __( 'Time window for counting errors.', 'mcpwp' ),
				'min'         => 1,
				'max'         => 120,
				'suffix'      => __( 'minutes', 'mcpwp' ),
			)
		);

		add_settings_field(
			'alerts_5xx_threshold',
			__( '5xx Threshold', 'mcpwp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_5xx_threshold',
				'description' => __( 'Trigger api.alert.5xx_spike when 5xx count meets or exceeds this value.', 'mcpwp' ),
				'min'         => 1,
				'max'         => 10000,
			)
		);

		add_settings_field(
			'alerts_auth_threshold',
			__( '401/403 Threshold', 'mcpwp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_auth_threshold',
				'description' => __( 'Trigger api.alert.auth_spike when 401/403 count meets or exceeds this value.', 'mcpwp' ),
				'min'         => 1,
				'max'         => 10000,
			)
		);

		add_settings_field(
			'alerts_cooldown_minutes',
			__( 'Alert Cooldown', 'mcpwp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_cooldown_minutes',
				'description' => __( 'Minimum time between repeated alerts of the same type.', 'mcpwp' ),
				'min'         => 1,
				'max'         => 1440,
				'suffix'      => __( 'minutes', 'mcpwp' ),
			)
		);

		add_settings_field(
			'analytics_enabled',
			__( 'Usage Analytics', 'mcpwp' ),
			array( $this, 'render_analytics_enabled_field' ),
			'mcpwp_settings',
			'mcpwp_general_section'
		);

		add_settings_field(
			'mupot_enabled',
			__( 'mupot Connector', 'mcpwp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'mupot_enabled',
				'description' => __( 'Let connected WP staff see their own mupot task board in Control Room.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'mupot_instance_url',
			__( 'mupot Instance URL', 'mcpwp' ),
			array( $this, 'render_text_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'mupot_instance_url',
				'description' => __( 'Your mupot deployment. Leave default unless self-hosting a fork.', 'mcpwp' ),
				'placeholder' => 'https://mupot.mumega.com',
			)
		);

		// Allowed origins (CORS)
		add_settings_field(
			'allowed_origins',
			__( 'Allowed Origins', 'mcpwp' ),
			array( $this, 'render_textarea_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'allowed_origins',
				'description' => __( 'Comma-separated list of allowed origins for CORS. Leave empty to allow all.', 'mcpwp' ),
				'placeholder' => 'https://example.com, https://app.example.com',
			)
		);

		add_settings_field(
			'oauth_enabled',
			__( 'Allow AI apps to connect with WordPress sign-in (OAuth)', 'mcpwp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'oauth_enabled',
				'description' => __( 'ChatGPT, Claude and other AI apps can connect by sending you to your own WordPress login and a consent screen. They get read and write access at most, never administrator access, and only to this site\'s MCP endpoint. On by default; turning it off also turns off the OAuth token endpoint (/wp-json/mcpwp/v1/oauth/token).', 'mcpwp' ),
			)
		);

		add_settings_field(
			'oauth_client_id',
			__( 'OAuth Client ID', 'mcpwp' ),
			array( $this, 'render_text_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'oauth_client_id',
				'description' => __( 'Client ID used for token requests.', 'mcpwp' ),
				'placeholder' => 'site_pilot_ai',
			)
		);

		add_settings_field(
			'oauth_client_secret',
			__( 'OAuth Client Secret', 'mcpwp' ),
			array( $this, 'render_secret_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'oauth_client_secret',
				'description' => __( 'Set a new secret. Leave empty to keep the existing value.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'oauth_token_ttl',
			__( 'OAuth Token TTL', 'mcpwp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'oauth_token_ttl',
				'description' => __( 'Token lifetime in seconds.', 'mcpwp' ),
				'min'         => 300,
				'max'         => 86400,
				'suffix'      => __( 'seconds', 'mcpwp' ),
			)
		);

		add_settings_field(
			'oauth_client_credentials_max_scope',
			__( 'Machine Token Max Scope', 'mcpwp' ),
			array( $this, 'render_select_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'oauth_client_credentials_max_scope',
				'label_for'   => 'oauth_client_credentials_max_scope',
				'options'     => array(
					'read'  => __( 'Read only', 'mcpwp' ),
					'write' => __( 'Read and write (recommended)', 'mcpwp' ),
					'admin' => __( 'Full admin', 'mcpwp' ),
				),
				'description' => __( 'Highest scope a client_credentials (machine-to-machine) token can be granted, no matter what scope it requests. A request above this ceiling is downgraded, not refused. "Full admin" lets any holder of the client secret manage settings, API keys, and approvals — grant it only to a credential you control.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'mcp_tool_profile',
			__( 'MCP Tool Profile', 'mcpwp' ),
			array( $this, 'render_select_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'mcp_tool_profile',
				'label_for'   => 'mcp_tool_profile',
				'options'     => array(
					'core' => __( 'Core — ~25 tools + search/dispatch (default, under 5k tokens)', 'mcpwp' ),
					'full' => __( 'Full — every tool listed up front (tens of thousands of tokens)', 'mcpwp' ),
				),
				'description' => __( 'What an MCP client loads when it connects. "Core" lists the most-used tools; every other tool stays reachable through mcpwp_find_tools (search) and mcpwp_call (run). "Full" lists every tool, which costs each new connection tens of thousands of tokens before the agent does anything. A client can override this per connection with ?profile=core or ?profile=full on its MCP endpoint URL.', 'mcpwp' ),
			)
		);

		// GitHub Integration.
		add_settings_field(
			'github_token',
			__( 'GitHub Token', 'mcpwp' ),
			array( $this, 'render_secret_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'github_token',
				'description' => __( 'Personal access token with repo scope. Used to auto-create issues from AI feedback. Leave empty to keep existing value.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'github_repo',
			__( 'GitHub Repo', 'mcpwp' ),
			array( $this, 'render_text_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'github_repo',
				'description' => __( 'Repository in owner/repo format (e.g., Mumega-com/mcp-for-wp). Leave empty to disable GitHub integration.', 'mcpwp' ),
				'placeholder' => 'owner/repo',
			)
		);

		// WebMCP in wp-admin (#981).
		add_settings_section(
			'mcpwp_webmcp_section',
			__( 'WebMCP (browser agent in wp-admin)', 'mcpwp' ),
			array( $this, 'render_webmcp_section' ),
			'mcpwp_settings'
		);

		add_settings_field(
			'webmcp_admin_enabled',
			__( 'Enable WebMCP in wp-admin', 'mcpwp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_webmcp_section',
			array(
				'id'          => 'webmcp_admin_enabled',
				'description' => __( 'Offer MCPWP tools to the built-in agent of a WebMCP-capable browser while an Editor or Administrator is signed in to wp-admin.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'webmcp_origin_trial_token',
			__( 'Origin-trial token', 'mcpwp' ),
			array( $this, 'render_text_field' ),
			'mcpwp_settings',
			'mcpwp_webmcp_section',
			array(
				'id'          => 'webmcp_origin_trial_token',
				'description' => __( 'Optional. The Chrome WebMCP origin-trial token registered for this site\'s origin. Leave empty when testing with the chrome://flags/#enable-webmcp-testing flag.', 'mcpwp' ),
				'placeholder' => '',
			)
		);

		// Note: Screenshot Worker is now configured via Integrations page.

		// Action log retention — standalone option (not in mcpwp_settings array).
		register_setting(
			'mcpwp_settings_group',
			'mcpwp_action_log_retention_days',
			array(
				'type'              => 'integer',
				'sanitize_callback' => function( $val ) {
					$v = (int) $val;
					return $v > 0 ? $v : 90;
				},
				'default'           => 90,
			)
		);

		add_settings_field(
			'action_log_retention_days',
			__( 'Action Log Retention (days)', 'mcpwp' ),
			array( $this, 'render_action_log_retention_field' ),
			'mcpwp_settings',
			'mcpwp_general_section'
		);

		// White-label section — standalone option.
		register_setting(
			'mcpwp_settings_group',
			'mcpwp_white_label',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_white_label_settings' ),
				'default'           => class_exists( 'Mcpwp_White_Label' ) ? Mcpwp_White_Label::defaults() : array(),
			)
		);

		add_settings_section(
			'mcpwp_white_label_section',
			__( 'White-Label & Chat Widget', 'mcpwp' ),
			array( $this, 'render_white_label_section' ),
			'mcpwp_settings'
		);

		add_settings_field(
			'wl_enabled',
			__( 'Enable White-Label', 'mcpwp' ),
			array( $this, 'render_white_label_enabled_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section'
		);

		add_settings_field(
			'wl_agency_name',
			__( 'Agency Name', 'mcpwp' ),
			array( $this, 'render_white_label_text_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'agency_name',
				'description' => __( 'Name shown in the chat widget and admin footer.', 'mcpwp' ),
				'placeholder' => __( 'Acme Agency', 'mcpwp' ),
			)
		);

		add_settings_field(
			'wl_logo_url',
			__( 'Logo URL', 'mcpwp' ),
			array( $this, 'render_white_label_text_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'logo_url',
				'description' => __( 'Public HTTPS URL of your agency logo (shown in chat button). Leave empty to use text only.', 'mcpwp' ),
				'placeholder' => 'https://yoursite.com/logo.png',
			)
		);

		add_settings_field(
			'wl_primary_color',
			__( 'Primary Color', 'mcpwp' ),
			array( $this, 'render_white_label_color_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'primary_color',
				'description' => __( 'Hex color for chat button and admin accents.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'wl_chat_greeting',
			__( 'Chat Greeting', 'mcpwp' ),
			array( $this, 'render_white_label_text_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'chat_greeting',
				'description' => __( 'Default greeting shown in the chat widget.', 'mcpwp' ),
				'placeholder' => 'Hi! How can I help you today?',
			)
		);

		add_settings_field(
			'wl_hide_branding',
			__( 'Hide MCPWP Branding', 'mcpwp' ),
			array( $this, 'render_white_label_hide_branding_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'hide_mcpwp_branding',
				'description' => __( 'Remove "MCPWP" labels from admin pages and chat widget.', 'mcpwp' ),
			)
		);

		// Site Context section.
		register_setting(
			'mcpwp_site_context_group',
			'mcpwp_site_context',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_site_context' ),
				'default'           => '',
			)
		);

		add_settings_section(
			'mcpwp_site_context_section',
			__( 'AI Site Context', 'mcpwp' ),
			array( $this, 'render_site_context_section' ),
			'mcpwp_site_context_settings'
		);

		add_settings_field(
			'mcpwp_site_context',
			__( 'Site Context / AI Brief', 'mcpwp' ),
			array( $this, 'render_site_context_field' ),
			'mcpwp_site_context_settings',
			'mcpwp_site_context_section'
		);

		// Rate-limiting section.
		add_settings_section(
			'mcpwp_rate_limit_section',
			__( 'Rate Limiting', 'mcpwp' ),
			array( $this, 'render_rate_limit_section' ),
			'mcpwp_rate_limit_settings'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Rate Limiting', 'mcpwp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'enabled',
				'description' => __( 'Apply request limits per identifier.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'requests_per_minute',
			__( 'Requests Per Minute', 'mcpwp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'requests_per_minute',
				'description' => __( 'Maximum requests allowed per minute.', 'mcpwp' ),
				'min'         => 1,
				'max'         => 100000,
			)
		);

		add_settings_field(
			'requests_per_hour',
			__( 'Requests Per Hour', 'mcpwp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'requests_per_hour',
				'description' => __( 'Maximum requests allowed per hour.', 'mcpwp' ),
				'min'         => 1,
				'max'         => 100000,
			)
		);

		add_settings_field(
			'burst_limit',
			__( 'Burst Limit (10s)', 'mcpwp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'burst_limit',
				'description' => __( 'Maximum requests allowed in a short burst window.', 'mcpwp' ),
				'min'         => 1,
				'max'         => 100000,
			)
		);

		add_settings_field(
			'whitelist',
			__( 'Whitelist', 'mcpwp' ),
			array( $this, 'render_textarea_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'whitelist',
				'description' => __( 'Comma or newline-separated identifiers that bypass limits.', 'mcpwp' ),
				'placeholder' => "127.0.0.1\nkey:example-id",
			)
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array Defaults.
	 */
	public function get_defaults() {
		return array(
			'enable_logging'     => true,
			'destructive_requires_approval' => true,
			'log_retention_days' => 30,
			'log_store_response_data' => true,
			'log_redaction_keys' => array(
				'api_key',
				'x-api-key',
				'authorization',
				'password',
				'secret',
				'token',
				'access_token',
				'refresh_token',
				'client_secret',
			),
			'allowed_origins'    => '',
			// D10 (PR-2): on unless an administrator stored it off.  Readers
			// resolve an ABSENT key to true via
			// Mcpwp_OAuth_Well_Known::resolve_oauth_enabled(); this default is
			// what the form shows and what a save stores when it carries no value.
			'oauth_enabled'      => true,
			'oauth_client_id'    => 'site_pilot_ai',
			'oauth_client_secret_hash' => '',
			'oauth_token_ttl'    => 3600,
			// Ceiling for machine (client_credentials) tokens. 'write' by default:
			// a machine credential should be able to publish content without being
			// able to rewrite plugin settings, rotate API keys, or approve its own
			// staging pushes. Raising this to 'admin' is an explicit administrator
			// choice made in WP Admin > MCPWP > Settings.
			'oauth_client_credentials_max_scope' => 'write',
			// MCP tool profile (#907). 'core' (the default since #1064)
			// lists ~25 tools plus mcpwp_find_tools/mcpwp_call, which reach
			// every other tool; 'full' lists every tool up front. A
			// per-connection `?profile=` query arg on the /mcp URL overrides
			// this site-wide default. Must match
			// Mcpwp_REST_MCP::DEFAULT_TOOL_PROFILE.
			'mcp_tool_profile'        => 'core',
			'alerts_enabled'          => false,
			'alerts_window_minutes'   => 5,
			'alerts_cooldown_minutes' => 15,
			'alerts_5xx_threshold'    => 5,
			'alerts_auth_threshold'   => 10,
			'analytics_enabled'       => false,
			'environment_type'        => 'auto',
			'mupot_enabled'           => false,
			'mupot_instance_url'      => 'https://mupot.mumega.com',
			// WebMCP in wp-admin (#981): off until an administrator turns it on.
			'webmcp_admin_enabled'      => false,
			'webmcp_origin_trial_token' => '',
		);
	}

	/**
	 * Get default rate-limit settings.
	 *
	 * @return array Defaults.
	 */
	public function get_rate_limit_defaults() {
		return array(
			'enabled'             => true,
			'requests_per_minute' => 60,
			'requests_per_hour'   => 1000,
			'burst_limit'         => 10,
			'whitelist'           => array(),
		);
	}

	/**
	 * Get settings.
	 *
	 * @return array Settings.
	 */
	public function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_NAME, array() ),
			$this->get_defaults()
		);
	}

	/**
	 * Get rate-limit settings.
	 *
	 * @return array Settings.
	 */
	public function get_rate_limit_settings() {
		return wp_parse_args(
			get_option( self::RATE_LIMIT_OPTION_NAME, array() ),
			$this->get_rate_limit_defaults()
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Input values.
	 * @return array Sanitized values.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();
		$current   = $this->get_settings();
		$defaults  = $this->get_defaults();
		$input     = is_array( $input ) ? $input : array();

		// This callback runs on EVERY update_option() of the option — a
		// plugin, a migration, the REST plugin-settings path, WordPress
		// re-running it on a first save — not only on a form post, so a
		// checkbox absent from the input cannot mean "unchecked". The form
		// proves it rendered a box with a hidden __present marker; see
		// resolve_checkbox() for the rule every checkbox follows.
		$present = isset( $input['__present'] ) && is_array( $input['__present'] ) ? array_map( 'strval', $input['__present'] ) : array();
		$stored  = get_option( self::OPTION_NAME, array() );
		$stored  = is_array( $stored ) ? $stored : array();

		$sanitized['enable_logging'] = $this->resolve_checkbox( $input, $present, $stored, 'enable_logging' ) ?? $defaults['enable_logging'];

		// The gate's switch is stricter: it changes only when the form that
		// carries it was posted. Any other write of the option — the settings
		// API, the admin chat, a plugin — keeps the stored choice, and no
		// stored choice means on (#536 round 4).
		if ( in_array( 'destructive_requires_approval', $present, true ) ) {
			$sanitized['destructive_requires_approval'] = ! empty( $input['destructive_requires_approval'] );
		} elseif ( isset( $current['destructive_requires_approval'] ) ) {
			$sanitized['destructive_requires_approval'] = (bool) $current['destructive_requires_approval'];
		}

		$sanitized['log_retention_days'] = isset( $input['log_retention_days'] )
			? min( 365, max( 1, absint( $input['log_retention_days'] ) ) )
			: 30;

		$sanitized['log_store_response_data'] = $this->resolve_checkbox( $input, $present, $stored, 'log_store_response_data' ) ?? $defaults['log_store_response_data'];

		$raw_redaction = isset( $input['log_redaction_keys'] )
			? $input['log_redaction_keys']
			: ( $current['log_redaction_keys'] ?? $this->get_defaults()['log_redaction_keys'] );

		if ( is_string( $raw_redaction ) ) {
			$raw_redaction = preg_split( '/[\r\n,]+/', $raw_redaction );
		}

		$redaction = array();
		if ( is_array( $raw_redaction ) ) {
			foreach ( $raw_redaction as $item ) {
				$item = strtolower( trim( sanitize_text_field( (string) $item ) ) );
				if ( '' === $item ) {
					continue;
				}
				$redaction[] = $item;
			}
		}

		if ( empty( $redaction ) ) {
			$redaction = $this->get_defaults()['log_redaction_keys'];
		}

		$sanitized['log_redaction_keys'] = array_values( array_unique( $redaction ) );

		$sanitized['allowed_origins'] = isset( $input['allowed_origins'] )
			? sanitize_textarea_field( $input['allowed_origins'] )
			: '';

		// D10 (PR-2): "never stored" is a state of its own — it means ON, and
		// it is what the one-time admin notice keys on.  So, like
		// analytics_enabled, a write that neither carries the box nor finds a
		// stored value writes NO value, instead of freezing today's default
		// into an explicit choice nobody made.  The form (which renders the
		// box) and the Setup → Advanced switch still store explicit values.
		$oauth_enabled = $this->resolve_checkbox( $input, $present, $stored, 'oauth_enabled' );
		if ( null !== $oauth_enabled ) {
			$sanitized['oauth_enabled'] = $oauth_enabled;
		}
		$sanitized['oauth_client_id'] = isset( $input['oauth_client_id'] ) && '' !== $input['oauth_client_id']
			? sanitize_key( $input['oauth_client_id'] )
			: 'site_pilot_ai';
		$sanitized['oauth_token_ttl'] = isset( $input['oauth_token_ttl'] )
			? min( 86400, max( 300, absint( $input['oauth_token_ttl'] ) ) )
			: 3600;

		// Scope ceiling for the client_credentials (machine token) grant.
		// Validate the ceiling against an explicit allow-list (read/write/admin)
		// with a 'write' fallback: anything unknown or absent resolves to 'write' —
		// the safe direction is always down. sanitize_scopes() is intentionally not
		// used here. The same allow-list is applied again on the read side in
		// Mcpwp_Api_Auth::get_oauth_settings(), because this option can also be
		// written by WP-CLI, a migration, or a direct DB edit that never passes
		// through this callback.
		$cc_max_scope = isset( $input['oauth_client_credentials_max_scope'] )
			? sanitize_key( (string) $input['oauth_client_credentials_max_scope'] )
			: '';
		$sanitized['oauth_client_credentials_max_scope'] = in_array( $cc_max_scope, array( 'read', 'write', 'admin' ), true )
			? $cc_max_scope
			: 'write';

		// MCP tool profile (#907, #1064). A value is stored only when one is
		// chosen: the form's select, PUT /settings, or an existing stored
		// choice. This callback runs on EVERY update_option() of the option
		// (see the __present note above), so writing a fallback here would
		// turn "never chose" into a stored choice on the next unrelated
		// save — and an absent key is exactly what
		// Mcpwp_REST_MCP::resolve_mcp_tool_profile() reads as "use the
		// default" (core).
		$tool_profile = isset( $input['mcp_tool_profile'] ) ? Mcpwp_REST_MCP::normalize_tool_profile( $input['mcp_tool_profile'] ) : '';
		if ( '' === $tool_profile ) {
			$tool_profile = isset( $stored['mcp_tool_profile'] ) ? Mcpwp_REST_MCP::normalize_tool_profile( $stored['mcp_tool_profile'] ) : '';
		}
		if ( '' !== $tool_profile ) {
			$sanitized['mcp_tool_profile'] = $tool_profile;
		}

		// WebMCP in wp-admin (#981).
		$sanitized['webmcp_admin_enabled'] = $this->resolve_checkbox( $input, $present, $stored, 'webmcp_admin_enabled' ) ?? $defaults['webmcp_admin_enabled'];
		$sanitized['webmcp_origin_trial_token'] = $this->resolve_webmcp_token( $input, $stored );

		$new_secret = isset( $input['oauth_client_secret'] ) ? trim( (string) $input['oauth_client_secret'] ) : '';
		if ( '' !== $new_secret ) {
			$sanitized['oauth_client_secret_hash'] = wp_hash_password( $new_secret );
		} else {
			$sanitized['oauth_client_secret_hash'] = isset( $current['oauth_client_secret_hash'] ) ? (string) $current['oauth_client_secret_hash'] : '';
		}

		$sanitized['alerts_enabled'] = $this->resolve_checkbox( $input, $present, $stored, 'alerts_enabled' ) ?? $defaults['alerts_enabled'];
		$sanitized['alerts_window_minutes'] = isset( $input['alerts_window_minutes'] )
			? min( 120, max( 1, absint( $input['alerts_window_minutes'] ) ) )
			: 5;
		$sanitized['alerts_cooldown_minutes'] = isset( $input['alerts_cooldown_minutes'] )
			? min( 1440, max( 1, absint( $input['alerts_cooldown_minutes'] ) ) )
			: 15;
		$sanitized['alerts_5xx_threshold'] = isset( $input['alerts_5xx_threshold'] )
			? min( 10000, max( 1, absint( $input['alerts_5xx_threshold'] ) ) )
			: 5;
		$sanitized['alerts_auth_threshold'] = isset( $input['alerts_auth_threshold'] )
			? min( 10000, max( 1, absint( $input['alerts_auth_threshold'] ) ) )
			: 10;

		// Analytics is consent, so it is stricter than the other boxes: see
		// resolve_analytics_choice().
		$this->resolve_analytics_choice( $input, $present, $stored, $sanitized );

		// Environment declaration. 'auto' lets Mcpwp_Staging_Adapter_Generic work it
		// out from WP_ENVIRONMENT_TYPE and then, as a labelled guess, the site URL.
		// Declaring it here is what makes the answer authoritative — and an
		// authoritative 'staging' is the only thing that lowers the push-to-live
		// approval gate, so an absent key must keep the operator's stored choice
		// rather than silently reverting to 'auto'.
		$environment_type = isset( $input['environment_type'] )
			? (string) $input['environment_type']
			: ( isset( $current['environment_type'] ) ? (string) $current['environment_type'] : 'auto' );

		$sanitized['environment_type'] = in_array(
			$environment_type,
			array( 'auto', 'production', 'staging', 'development', 'local' ),
			true
		) ? $environment_type : 'auto';

		$sanitized['mupot_enabled'] = $this->resolve_checkbox( $input, $present, $stored, 'mupot_enabled' ) ?? $defaults['mupot_enabled'];
		$mupot_url = isset( $input['mupot_instance_url'] ) ? trim( (string) $input['mupot_instance_url'] ) : '';
		$sanitized['mupot_instance_url'] = '' !== $mupot_url ? esc_url_raw( $mupot_url ) : 'https://mupot.mumega.com';

		// Screenshot worker (legacy — now managed via Integrations page, preserved for backward compat).
		$sanitized['screenshot_worker_url'] = isset( $current['screenshot_worker_url'] ) ? $current['screenshot_worker_url'] : '';
		$sanitized['screenshot_worker_token'] = isset( $current['screenshot_worker_token'] ) ? (string) $current['screenshot_worker_token'] : '';

		// GitHub integration.
		$new_github_token = isset( $input['github_token'] ) ? trim( (string) $input['github_token'] ) : '';
		if ( '' !== $new_github_token ) {
			$sanitized['github_token'] = sanitize_text_field( $new_github_token );
		} else {
			$sanitized['github_token'] = isset( $current['github_token'] ) ? (string) $current['github_token'] : '';
		}

		$sanitized['github_repo'] = isset( $input['github_repo'] )
			? sanitize_text_field( trim( $input['github_repo'] ) )
			: ( isset( $current['github_repo'] ) ? $current['github_repo'] : '' );

		return $sanitized;
	}

	/**
	 * The WebMCP origin-trial token on a write of the option (#981).
	 *
	 * Written only when the write names it. A well-formed token (or '' to
	 * clear) is stored; a malformed one keeps the stored value and, on the
	 * settings form, says so — it is printed into every wp-admin page, so it
	 * is never stored in a shape Mcpwp_WebMCP::sanitize_origin_trial_token()
	 * would not accept.
	 *
	 * @param array $input  Input values.
	 * @param array $stored The option as stored before this write.
	 * @return string
	 */
	private function resolve_webmcp_token( array $input, array $stored ) {
		$previous = isset( $stored['webmcp_origin_trial_token'] ) ? Mcpwp_WebMCP::sanitize_origin_trial_token( $stored['webmcp_origin_trial_token'] ) : '';
		$previous = null === $previous ? '' : $previous;

		if ( ! array_key_exists( 'webmcp_origin_trial_token', $input ) ) {
			return $previous;
		}

		$token = Mcpwp_WebMCP::sanitize_origin_trial_token( $input['webmcp_origin_trial_token'] );
		if ( null === $token ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error(
					self::OPTION_NAME,
					'mcpwp_webmcp_token_invalid',
					__( 'The WebMCP origin-trial token was not saved: paste the token exactly as Chrome issued it (letters, digits, +, / and =).', 'mcpwp' )
				);
			}
			return $previous;
		}

		return $token;
	}

	/**
	 * The value of one mcpwp_settings checkbox on a write of the option.
	 *
	 * 1. Its form was posted (the __present marker names it): checked is on,
	 *    unchecked is off.
	 * 2. The write names the key without the form — the REST plugin-settings
	 *    path writes the whole stored array with one key changed, and
	 *    WordPress re-sanitizes this callback's own output on a first save:
	 *    that value.
	 * 3. Neither: the stored value is kept.
	 * 4. Nothing stored either: null, and the caller applies the default.
	 *
	 * destructive_requires_approval does not use this: only its form may
	 * change it (#536).
	 *
	 * @param array    $input   Input values.
	 * @param string[] $present Checkbox ids the posted form rendered.
	 * @param array    $stored  The option as stored before this write.
	 * @param string   $key     Checkbox id.
	 * @return bool|null
	 */
	private function resolve_checkbox( array $input, array $present, array $stored, $key ) {
		if ( in_array( $key, $present, true ) || array_key_exists( $key, $input ) ) {
			return ! empty( $input[ $key ] );
		}
		if ( array_key_exists( $key, $stored ) ) {
			return (bool) $stored[ $key ];
		}
		return null;
	}

	/**
	 * Resolve analytics_enabled, and the record that it was an explicit choice.
	 *
	 * A paying site with no stored choice is shown the box checked (the plan
	 * default). Saving the form must not turn that default into a stored
	 * opt-in, or it outlives the plan: after a downgrade to Free — or a
	 * database cloned to a Free site — telemetry would run without consent
	 * (review r1 P2-A). So the form posts the state it showed
	 * (__rendered[analytics_enabled]) and a value is stored only when the
	 * admin changed the box; it is then recorded as analytics_choice =
	 * explicit, which is the only thing Mcpwp_Analytics::is_enabled() counts
	 * as consent on Free.
	 *
	 * - Form posted, box left as shown: the stored value (and its record)
	 *   stays; nothing stored stays nothing stored, so the plan default applies.
	 * - Form posted, box changed: that value, recorded explicit.
	 * - Form posted without the shown state: an uncheck is stored (off is
	 *   always safe); a check is not, since no change can be proven.
	 * - A write that names the key without the form (WordPress re-sanitizing
	 *   its own output, the REST plugin-settings path writing the whole
	 *   array): that value; its record (explicit, or legacy for an opt-in
	 *   stored before 3.11.1) is kept only if the write carries it.
	 * - Anything else: the stored value and its record stay.
	 *
	 * @param array    $input     Input values.
	 * @param string[] $present   Checkbox ids the posted form rendered.
	 * @param array    $stored    The option as stored before this write.
	 * @param array    $sanitized Output, by reference.
	 */
	private function resolve_analytics_choice( array $input, array $present, array $stored, array &$sanitized ) {
		$key   = 'analytics_enabled';
		$write = null; // array( value, record or null ), or null to keep what is stored.
		$known = array( 'explicit', 'legacy' );

		if ( in_array( $key, $present, true ) ) {
			$posted = ! empty( $input[ $key ] );
			$shown  = isset( $input['__rendered'] ) && is_array( $input['__rendered'] ) && array_key_exists( $key, $input['__rendered'] )
				? ! empty( $input['__rendered'][ $key ] )
				: null;

			if ( null === $shown ) {
				$write = $posted ? null : array( false, 'explicit' );
			} elseif ( $posted !== $shown ) {
				$write = array( $posted, 'explicit' );
			}
		} elseif ( array_key_exists( $key, $input ) ) {
			$record = isset( $input['analytics_choice'] ) && in_array( $input['analytics_choice'], $known, true ) ? $input['analytics_choice'] : null;
			$write  = array( ! empty( $input[ $key ] ), $record );
		}

		if ( null === $write ) {
			if ( array_key_exists( $key, $stored ) ) {
				$sanitized[ $key ] = (bool) $stored[ $key ];
				if ( isset( $stored['analytics_choice'] ) && in_array( $stored['analytics_choice'], $known, true ) ) {
					$sanitized['analytics_choice'] = $stored['analytics_choice'];
				}
			}
			return;
		}

		$sanitized[ $key ] = $write[0];
		if ( null !== $write[1] ) {
			$sanitized['analytics_choice'] = $write[1];
		}
	}

	/**
	 * Sanitize rate-limit settings.
	 *
	 * @param array $input Input values.
	 * @return array Sanitized values.
	 */
	public function sanitize_rate_limit_settings( $input ) {
		$sanitized = $this->get_rate_limit_defaults();

		$sanitized['enabled'] = ! empty( $input['enabled'] );

		$sanitized['requests_per_minute'] = isset( $input['requests_per_minute'] )
			? max( 1, min( 100000, absint( $input['requests_per_minute'] ) ) )
			: $sanitized['requests_per_minute'];

		$sanitized['requests_per_hour'] = isset( $input['requests_per_hour'] )
			? max( 1, min( 100000, absint( $input['requests_per_hour'] ) ) )
			: $sanitized['requests_per_hour'];

		$sanitized['burst_limit'] = isset( $input['burst_limit'] )
			? max( 1, min( 100000, absint( $input['burst_limit'] ) ) )
			: $sanitized['burst_limit'];

		if ( $sanitized['burst_limit'] > $sanitized['requests_per_minute'] ) {
			$sanitized['burst_limit'] = $sanitized['requests_per_minute'];
		}

		$raw_whitelist = isset( $input['whitelist'] ) ? $input['whitelist'] : array();
		if ( is_string( $raw_whitelist ) ) {
			$raw_whitelist = preg_split( '/[\r\n,]+/', $raw_whitelist );
		}

		if ( is_array( $raw_whitelist ) ) {
			$whitelist = array();
			foreach ( $raw_whitelist as $item ) {
				$item = trim( sanitize_text_field( (string) $item ) );
				if ( '' === $item ) {
					continue;
				}
				$whitelist[] = $item;
			}
			$sanitized['whitelist'] = array_values( array_unique( $whitelist ) );
		}

		return $sanitized;
	}

	/**
	 * Render general section.
	 */
	public function render_general_section() {
		echo '<p>' . esc_html__( 'Configure general plugin settings.', 'mcpwp' ) . '</p>';
	}

	/**
	 * Render the WebMCP section: what it is, what it exposes, and its state.
	 */
	public function render_webmcp_section() {
		echo '<p>' . esc_html__( 'WebMCP lets the AI agent built into a supporting browser (Chrome 149+ with the WebMCP origin trial or the enable-webmcp-testing flag) use MCPWP while you are signed in to wp-admin — no API key: it acts as you, through your login. It can only read content and SEO data, create drafts, and propose edits that wait for approval in Control Room. It cannot publish, delete, change settings, or manage API keys, plugins or themes. Pages it reads may contain instructions planted by someone else; the draft-and-approval limits are what keep those harmless. Authors and Contributors are not offered it.', 'mcpwp' ) . '</p>';

		$enabled   = class_exists( 'Mcpwp_WebMCP' ) && Mcpwp_WebMCP::is_enabled();
		$token_set = class_exists( 'Mcpwp_WebMCP' ) && '' !== Mcpwp_WebMCP::get_origin_trial_token();

		printf(
			'<p><strong>%1$s</strong> %2$s &middot; <strong>%3$s</strong> %4$s</p>',
			esc_html__( 'Status:', 'mcpwp' ),
			$enabled ? esc_html__( 'enabled', 'mcpwp' ) : esc_html__( 'disabled', 'mcpwp' ),
			esc_html__( 'Origin-trial token:', 'mcpwp' ),
			$token_set ? esc_html__( 'set', 'mcpwp' ) : esc_html__( 'not set', 'mcpwp' )
		);
	}

	/**
	 * Render rate-limit section.
	 */
	public function render_rate_limit_section() {
		echo '<p>' . esc_html__( 'Configure request throttling and bypass identifiers.', 'mcpwp' ) . '</p>';
	}

	/**
	 * Render checkbox field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_checkbox_field( $args ) {
		$settings = $this->get_option_settings( $args );
		$value = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : false;
		$option_name = isset( $args['option_name'] ) ? $args['option_name'] : self::OPTION_NAME;

		// A hidden marker proves the form RENDERED this checkbox. Without it,
		// "absent from the input" would mean "unchecked" on every write of
		// the option — and sanitize_settings runs on every update_option(),
		// not only on a form post (#536 round 4).
		printf(
			'<input type="hidden" name="%1$s[__present][]" value="%2$s" /><label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( $option_name ),
			esc_attr( $args['id'] ),
			checked( $value, true, false ),
			esc_html( $args['description'] )
		);
	}

	/**
	 * Render number field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_number_field( $args ) {
		$settings = $this->get_option_settings( $args );
		$value = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
		$option_name = isset( $args['option_name'] ) ? $args['option_name'] : self::OPTION_NAME;

		printf(
			'<input type="number" name="%s[%s]" value="%s" min="%d" max="%d" class="small-text" /> %s',
			esc_attr( $option_name ),
			esc_attr( $args['id'] ),
			esc_attr( $value ),
			esc_attr( isset( $args['min'] ) ? $args['min'] : 0 ),
			esc_attr( isset( $args['max'] ) ? $args['max'] : 999999 ),
			esc_html( $args['suffix'] ?? '' )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render the action log retention field.
	 */
	public function render_action_log_retention_field() {
		$value = (int) get_option( 'mcpwp_action_log_retention_days', 90 );
		printf(
			'<input type="number" name="mcpwp_action_log_retention_days" value="%d" min="1" max="3650" class="small-text" /> %s',
			(int) $value,
			esc_html__( 'days', 'mcpwp' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'AI action log entries older than this are automatically deleted. Default: 90. Required for EU AI Act compliance (minimum 90 days recommended).', 'mcpwp' )
		);
	}

	/**
	 * Render textarea field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_textarea_field( $args ) {
		$settings = $this->get_option_settings( $args );
		$value = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
		$option_name = isset( $args['option_name'] ) ? $args['option_name'] : self::OPTION_NAME;
		if ( is_array( $value ) ) {
			$value = implode( "\n", $value );
		}

		printf(
			'<textarea name="%s[%s]" rows="3" class="large-text" placeholder="%s">%s</textarea>',
			esc_attr( $option_name ),
			esc_attr( $args['id'] ),
			esc_attr( $args['placeholder'] ?? '' ),
			esc_textarea( $value )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render text field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_text_field( $args ) {
		$settings = $this->get_option_settings( $args );
		$value = isset( $settings[ $args['id'] ] ) ? $settings[ $args['id'] ] : '';
		$option_name = isset( $args['option_name'] ) ? $args['option_name'] : self::OPTION_NAME;

		printf(
			'<input type="text" name="%s[%s]" value="%s" class="regular-text" placeholder="%s" />',
			esc_attr( $option_name ),
			esc_attr( $args['id'] ),
			esc_attr( $value ),
			esc_attr( $args['placeholder'] ?? '' )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render select field.
	 *
	 * The rendered value is never trusted on the way back in: sanitize_settings()
	 * re-checks the submitted value against its own allow-list, and the auth layer
	 * clamps again at read time. This renderer only decides what is offered.
	 *
	 * @param array $args Field arguments. Requires 'id' and 'options' (value => label).
	 */
	public function render_select_field( $args ) {
		$settings    = $this->get_option_settings( $args );
		$value       = isset( $settings[ $args['id'] ] ) ? (string) $settings[ $args['id'] ] : '';
		$option_name = isset( $args['option_name'] ) ? $args['option_name'] : self::OPTION_NAME;
		$options     = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();

		printf(
			'<select name="%s[%s]" id="%s">',
			esc_attr( $option_name ),
			esc_attr( $args['id'] ),
			esc_attr( $args['id'] )
		);

		foreach ( $options as $option_value => $option_label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option_value ),
				selected( $value, (string) $option_value, false ),
				esc_html( $option_label )
			);
		}

		echo '</select>';

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render secret field.
	 *
	 * @param array $args Field arguments.
	 */
	public function render_secret_field( $args ) {
		$settings = $this->get_option_settings( $args );
		$option_name = isset( $args['option_name'] ) ? $args['option_name'] : self::OPTION_NAME;
		$field_id    = $args['id'];

		// Detect whether a value is already stored for this secret field.
		$has_secret = false;
		if ( 'oauth_client_secret' === $field_id ) {
			$has_secret = ! empty( $settings['oauth_client_secret_hash'] );
		} else {
			$has_secret = ! empty( $settings[ $field_id ] );
		}

		printf(
			'<input type="password" name="%s[%s]" value="" class="regular-text" autocomplete="new-password" />',
			esc_attr( $option_name ),
			esc_attr( $field_id )
		);

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}

		if ( $has_secret ) {
			printf( '<p class="description">%s</p>', esc_html__( 'A value is already configured.', 'mcpwp' ) );
		}
	}

	/**
	 * Render site context section.
	 */
	public function render_site_context_section() {
		echo '<p>' . esc_html__( 'Define your site\'s design rules, style guide, and page structure. This is served to AI assistants when they connect, so they know how to build pages that match your brand.', 'mcpwp' ) . '</p>';
		echo '<p>' . esc_html__( 'This same context is also published publicly at /llms.txt so external AI crawlers and assistants can pick up the site\'s character, key pages, and preferred framing.', 'mcpwp' ) . '</p>';
	}

	/**
	 * Render site context field.
	 */
	public function render_site_context_field() {
		$value = get_option( 'mcpwp_site_context', '' );
		$updated = get_option( 'mcpwp_site_context_updated', '' );

		printf(
			'<textarea name="mcpwp_site_context" rows="20" class="large-text code" placeholder="%s">%s</textarea>',
			esc_attr( "# Site Style Guide\n\n## Colors\n- Primary: #1B4DFF\n- Background: #FFFFFF\n- Text: #0B1220\n\n## Typography\n- Headings: Poppins, bold\n- Body: Inter, regular\n\n## Header Rules\n- Always include logo + main navigation\n- Use sticky header on all pages\n\n## Footer Rules\n- 3-column layout: About, Quick Links, Contact\n- Always include copyright\n\n## Page Sections\n- Hero: Full-width with headline, subtext, CTA button\n- Features: 3-column grid with icons\n- Testimonials: Carousel or grid\n- CTA: Centered with background color\n- FAQ: Accordion style\n\n## Page Templates\n- Landing Page: Hero → Features → Testimonials → CTA\n- About Page: Hero → Story → Team → CTA\n- Service Page: Hero → Benefits → Pricing → FAQ → CTA" ),
			esc_textarea( $value )
		);

		echo '<p class="description">';
		esc_html_e( 'Write in Markdown. This text is included in the wp_introspect response, available via wp_get_site_context, and published in a public llms.txt summary. AI assistants will use this as their design reference when building or editing pages.', 'mcpwp' );
		echo '</p>';

		echo '<p class="description"><strong>';
		esc_html_e( 'This text is public: it is published in /llms.txt and readable by every API key.', 'mcpwp' );
		echo '</strong></p>';

		if ( '' !== $updated ) {
			printf(
				'<p class="description">%s %s</p>',
				esc_html__( 'Last updated:', 'mcpwp' ),
				esc_html( $updated )
			);
		}
	}

	/**
	 * Sanitize site context.
	 *
	 * @param string $input Input.
	 * @return string Sanitized.
	 */
	public function sanitize_site_context( $input ) {
		if ( ! is_string( $input ) ) {
			return '';
		}

		// Limit to 50KB.
		$input = substr( $input, 0, 51200 );

		// Allow markdown formatting but strip dangerous tags.
		$allowed = wp_kses_allowed_html( 'post' );
		$sanitized = wp_kses( $input, $allowed );

		// Update timestamp.
		update_option( 'mcpwp_site_context_updated', gmdate( 'Y-m-d H:i:s' ) );

		return $sanitized;
	}

	/**
	 * Resolve settings array based on field option.
	 *
	 * @param array $args Field arguments.
	 * @return array Settings array.
	 */
	private function get_option_settings( $args ) {
		$option_name = isset( $args['option_name'] ) ? $args['option_name'] : self::OPTION_NAME;

		if ( self::RATE_LIMIT_OPTION_NAME === $option_name ) {
			return $this->get_rate_limit_settings();
		}

		return $this->get_settings();
	}

	/**
	 * Render the analytics_enabled checkbox + site UUID display.
	 */
	public function render_analytics_enabled_field() {
		$settings = $this->get_settings();
		// Show the state in force: with no stored choice a paying site is on.
		// The form also posts the state it showed, so saving without touching
		// the box stores nothing (see resolve_analytics_choice()).
		$enabled = class_exists( 'Mcpwp_Analytics' ) ? Mcpwp_Analytics::is_enabled() : ! empty( $settings['analytics_enabled'] );
		$uuid    = class_exists( 'Mcpwp_Analytics' ) ? Mcpwp_Analytics::get_site_uuid() : '';
		?>
		<?php // Presence marker (see render_checkbox_field()) and the state shown. ?>
		<input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[__present][]" value="analytics_enabled" />
		<input type="hidden" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[__rendered][analytics_enabled]" value="<?php echo $enabled ? '1' : '0'; ?>" />
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[analytics_enabled]"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Share anonymous tool-usage data to help improve MCPWP', 'mcpwp' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Sends MCP tool names, success/failure status, and execution time to PostHog. No WordPress content or personal data is ever collected.', 'mcpwp' ); ?>
			<a href="https://mcpwp.net/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'mcpwp' ); ?></a>
		</p>
		<?php if ( $uuid ) : ?>
		<p class="description" style="margin-top:8px;">
			<?php esc_html_e( 'Your site support ID:', 'mcpwp' ); ?>
			<code id="mcpwp-site-uuid"><?php echo esc_html( $uuid ); ?></code>
			<button
				type="button"
				class="button button-small"
				onclick="navigator.clipboard.writeText('<?php echo esc_js( $uuid ); ?>').then(function(){this.textContent='<?php echo esc_js( __( 'Copied!', 'mcpwp' ) ); ?>';}.bind(this));"
			><?php esc_html_e( 'Copy', 'mcpwp' ); ?></button>
			<span class="description"><?php esc_html_e( 'Share this with support when contacting us about your site.', 'mcpwp' ); ?></span>
		</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Sanitize white-label settings submitted from the settings form.
	 *
	 * @param mixed $input Raw form input.
	 * @return array
	 */
	public function sanitize_white_label_settings( $input ): array {
		if ( class_exists( 'Mcpwp_White_Label' ) ) {
			$raw = is_array( $input ) ? $input : array();
			// Normalize bool fields from form checkboxes.
			$raw['enabled']             = ! empty( $raw['enabled'] );
			$raw['hide_mcpwp_branding'] = ! empty( $raw['hide_mcpwp_branding'] );
			Mcpwp_White_Label::save_settings( $raw );
			return Mcpwp_White_Label::get_settings();
		}
		return is_array( $input ) ? $input : array();
	}

	/**
	 * Render white-label section header.
	 *
	 * @return void
	 */
	public function render_white_label_section(): void {
		echo '<p>' . esc_html__( 'Apply your agency branding to the plugin UI and enable the [mcpwp_chat] shortcode for client-facing chat widgets.', 'mcpwp' ) . '</p>';
		if ( class_exists( 'Mcpwp_White_Label' ) ) {
			$s = Mcpwp_White_Label::get_settings();
			if ( $s['enabled'] && ! empty( $s['agency_name'] ) ) {
				echo '<p><code>[mcpwp_chat]</code> — ' . esc_html__( 'shortcode is active. Add it to any page to embed the chat widget.', 'mcpwp' ) . '</p>';
			}
		}
	}

	/**
	 * Render enabled toggle for white-label.
	 *
	 * @return void
	 */
	public function render_white_label_enabled_field(): void {
		$s       = class_exists( 'Mcpwp_White_Label' ) ? Mcpwp_White_Label::get_settings() : array();
		$checked = ! empty( $s['enabled'] );
		?>
		<label>
			<input type="checkbox" name="mcpwp_white_label[enabled]" value="1" <?php checked( $checked ); ?> />
			<?php esc_html_e( 'Enable white-label branding', 'mcpwp' ); ?>
		</label>
		<?php
	}

	/**
	 * Render hide branding checkbox.
	 *
	 * @param array $args Field args including 'key' and 'description'.
	 * @return void
	 */
	public function render_white_label_hide_branding_field( array $args ): void {
		$s       = class_exists( 'Mcpwp_White_Label' ) ? Mcpwp_White_Label::get_settings() : array();
		$key     = $args['key'] ?? 'hide_mcpwp_branding';
		$checked = ! empty( $s[ $key ] );
		?>
		<label>
			<input type="checkbox" name="mcpwp_white_label[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
			<?php echo esc_html( $args['description'] ?? '' ); ?>
		</label>
		<?php
	}

	/**
	 * Render a text input for a white-label field.
	 *
	 * @param array $args Field args: 'key', 'description', 'placeholder'.
	 * @return void
	 */
	public function render_white_label_text_field( array $args ): void {
		$s           = class_exists( 'Mcpwp_White_Label' ) ? Mcpwp_White_Label::get_settings() : array();
		$key         = $args['key'] ?? '';
		$value       = $s[ $key ] ?? '';
		$placeholder = $args['placeholder'] ?? '';
		$description = $args['description'] ?? '';
		?>
		<input
			type="text"
			name="mcpwp_white_label[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php echo esc_attr( $placeholder ); ?>"
			class="regular-text"
		/>
		<?php if ( $description ) : ?>
		<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a color picker input for white-label primary color.
	 *
	 * @param array $args Field args.
	 * @return void
	 */
	public function render_white_label_color_field( array $args ): void {
		$s           = class_exists( 'Mcpwp_White_Label' ) ? Mcpwp_White_Label::get_settings() : array();
		$key         = $args['key'] ?? 'primary_color';
		$value       = $s[ $key ] ?? '#7c3aed';
		$description = $args['description'] ?? '';
		?>
		<input
			type="color"
			name="mcpwp_white_label[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
		/>
		<code><?php echo esc_html( $value ); ?></code>
		<?php if ( $description ) : ?>
		<p class="description"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

}
