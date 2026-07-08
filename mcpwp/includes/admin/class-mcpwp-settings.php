<?php
/**
 * Settings functionality
 *
 * @package mumcp
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
			__( 'General Settings', 'mumcp' ),
			array( $this, 'render_general_section' ),
			'mcpwp_settings'
		);

		// Logging
		add_settings_field(
			'enable_logging',
			__( 'Activity Logging', 'mumcp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'enable_logging',
				'description' => __( 'Log API requests for analytics and debugging.', 'mumcp' ),
			)
		);

		// Log retention
		add_settings_field(
			'log_retention_days',
			__( 'Log Retention', 'mumcp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'log_retention_days',
				'description' => __( 'Number of days to keep activity logs.', 'mumcp' ),
				'min'         => 1,
				'max'         => 365,
				'suffix'      => __( 'days', 'mumcp' ),
			)
		);

		add_settings_field(
			'log_store_response_data',
			__( 'Store Response Data', 'mumcp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'log_store_response_data',
				'description' => __( 'Store small response bodies for debugging (redacted). Disable for privacy.', 'mumcp' ),
			)
		);

		add_settings_field(
			'log_redaction_keys',
			__( 'Redaction Keys', 'mumcp' ),
			array( $this, 'render_textarea_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'log_redaction_keys',
				'description' => __( 'Comma or newline-separated keys to redact from logged request/response data.', 'mumcp' ),
				'placeholder' => "api_key\nauthorization\ntoken",
			)
		);

		add_settings_field(
			'alerts_enabled',
			__( 'Enable Alerts', 'mumcp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_enabled',
				'description' => __( 'Send webhook alerts on API error spikes (requires a configured webhook subscribed to api.alert.* events).', 'mumcp' ),
			)
		);

		add_settings_field(
			'alerts_window_minutes',
			__( 'Alert Window', 'mumcp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_window_minutes',
				'description' => __( 'Time window for counting errors.', 'mumcp' ),
				'min'         => 1,
				'max'         => 120,
				'suffix'      => __( 'minutes', 'mumcp' ),
			)
		);

		add_settings_field(
			'alerts_5xx_threshold',
			__( '5xx Threshold', 'mumcp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_5xx_threshold',
				'description' => __( 'Trigger api.alert.5xx_spike when 5xx count meets or exceeds this value.', 'mumcp' ),
				'min'         => 1,
				'max'         => 10000,
			)
		);

		add_settings_field(
			'alerts_auth_threshold',
			__( '401/403 Threshold', 'mumcp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_auth_threshold',
				'description' => __( 'Trigger api.alert.auth_spike when 401/403 count meets or exceeds this value.', 'mumcp' ),
				'min'         => 1,
				'max'         => 10000,
			)
		);

		add_settings_field(
			'alerts_cooldown_minutes',
			__( 'Alert Cooldown', 'mumcp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'alerts_cooldown_minutes',
				'description' => __( 'Minimum time between repeated alerts of the same type.', 'mumcp' ),
				'min'         => 1,
				'max'         => 1440,
				'suffix'      => __( 'minutes', 'mumcp' ),
			)
		);

		add_settings_field(
			'analytics_enabled',
			__( 'Usage Analytics', 'mumcp' ),
			array( $this, 'render_analytics_enabled_field' ),
			'mcpwp_settings',
			'mcpwp_general_section'
		);

		// Allowed origins (CORS)
		add_settings_field(
			'allowed_origins',
			__( 'Allowed Origins', 'mumcp' ),
			array( $this, 'render_textarea_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'allowed_origins',
				'description' => __( 'Comma-separated list of allowed origins for CORS. Leave empty to allow all.', 'mumcp' ),
				'placeholder' => 'https://example.com, https://app.example.com',
			)
		);

		add_settings_field(
			'oauth_enabled',
			__( 'OAuth Token Endpoint', 'mumcp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'oauth_enabled',
				'description' => __( 'Enable OAuth2 client credentials at /wp-json/mcpwp/v1/oauth/token.', 'mumcp' ),
			)
		);

		add_settings_field(
			'oauth_client_id',
			__( 'OAuth Client ID', 'mumcp' ),
			array( $this, 'render_text_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'oauth_client_id',
				'description' => __( 'Client ID used for token requests.', 'mumcp' ),
				'placeholder' => 'site_pilot_ai',
			)
		);

		add_settings_field(
			'oauth_client_secret',
			__( 'OAuth Client Secret', 'mumcp' ),
			array( $this, 'render_secret_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'oauth_client_secret',
				'description' => __( 'Set a new secret. Leave empty to keep the existing value.', 'mumcp' ),
			)
		);

		add_settings_field(
			'oauth_token_ttl',
			__( 'OAuth Token TTL', 'mumcp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'oauth_token_ttl',
				'description' => __( 'Token lifetime in seconds.', 'mumcp' ),
				'min'         => 300,
				'max'         => 86400,
				'suffix'      => __( 'seconds', 'mumcp' ),
			)
		);

		// GitHub Integration.
		add_settings_field(
			'github_token',
			__( 'GitHub Token', 'mumcp' ),
			array( $this, 'render_secret_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'github_token',
				'description' => __( 'Personal access token with repo scope. Used to auto-create issues from AI feedback. Leave empty to keep existing value.', 'mumcp' ),
			)
		);

		add_settings_field(
			'github_repo',
			__( 'GitHub Repo', 'mumcp' ),
			array( $this, 'render_text_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'github_repo',
				'description' => __( 'Repository in owner/repo format (e.g., Mumega-com/mcp-for-wp). Leave empty to disable GitHub integration.', 'mumcp' ),
				'placeholder' => 'owner/repo',
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
			__( 'Action Log Retention (days)', 'mumcp' ),
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
			__( 'White-Label & Chat Widget', 'mumcp' ),
			array( $this, 'render_white_label_section' ),
			'mcpwp_settings'
		);

		add_settings_field(
			'wl_enabled',
			__( 'Enable White-Label', 'mumcp' ),
			array( $this, 'render_white_label_enabled_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section'
		);

		add_settings_field(
			'wl_agency_name',
			__( 'Agency Name', 'mumcp' ),
			array( $this, 'render_white_label_text_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'agency_name',
				'description' => __( 'Name shown in the chat widget and admin footer.', 'mumcp' ),
				'placeholder' => __( 'Acme Agency', 'mumcp' ),
			)
		);

		add_settings_field(
			'wl_logo_url',
			__( 'Logo URL', 'mumcp' ),
			array( $this, 'render_white_label_text_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'logo_url',
				'description' => __( 'Public HTTPS URL of your agency logo (shown in chat button). Leave empty to use text only.', 'mumcp' ),
				'placeholder' => 'https://yoursite.com/logo.png',
			)
		);

		add_settings_field(
			'wl_primary_color',
			__( 'Primary Color', 'mumcp' ),
			array( $this, 'render_white_label_color_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'primary_color',
				'description' => __( 'Hex color for chat button and admin accents.', 'mumcp' ),
			)
		);

		add_settings_field(
			'wl_chat_greeting',
			__( 'Chat Greeting', 'mumcp' ),
			array( $this, 'render_white_label_text_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'chat_greeting',
				'description' => __( 'Default greeting shown in the chat widget.', 'mumcp' ),
				'placeholder' => 'Hi! How can I help you today?',
			)
		);

		add_settings_field(
			'wl_hide_branding',
			__( 'Hide mumcp Branding', 'mumcp' ),
			array( $this, 'render_white_label_hide_branding_field' ),
			'mcpwp_settings',
			'mcpwp_white_label_section',
			array(
				'key'         => 'hide_mcpwp_branding',
				'description' => __( 'Remove "mumcp" labels from admin pages and chat widget.', 'mumcp' ),
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
			__( 'AI Site Context', 'mumcp' ),
			array( $this, 'render_site_context_section' ),
			'mcpwp_site_context_settings'
		);

		add_settings_field(
			'mcpwp_site_context',
			__( 'Site Context / AI Brief', 'mumcp' ),
			array( $this, 'render_site_context_field' ),
			'mcpwp_site_context_settings',
			'mcpwp_site_context_section'
		);

		// Rate-limiting section.
		add_settings_section(
			'mcpwp_rate_limit_section',
			__( 'Rate Limiting', 'mumcp' ),
			array( $this, 'render_rate_limit_section' ),
			'mcpwp_rate_limit_settings'
		);

		add_settings_field(
			'enabled',
			__( 'Enable Rate Limiting', 'mumcp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'enabled',
				'description' => __( 'Apply request limits per identifier.', 'mumcp' ),
			)
		);

		add_settings_field(
			'requests_per_minute',
			__( 'Requests Per Minute', 'mumcp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'requests_per_minute',
				'description' => __( 'Maximum requests allowed per minute.', 'mumcp' ),
				'min'         => 1,
				'max'         => 100000,
			)
		);

		add_settings_field(
			'requests_per_hour',
			__( 'Requests Per Hour', 'mumcp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'requests_per_hour',
				'description' => __( 'Maximum requests allowed per hour.', 'mumcp' ),
				'min'         => 1,
				'max'         => 100000,
			)
		);

		add_settings_field(
			'burst_limit',
			__( 'Burst Limit (10s)', 'mumcp' ),
			array( $this, 'render_number_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'burst_limit',
				'description' => __( 'Maximum requests allowed in a short burst window.', 'mumcp' ),
				'min'         => 1,
				'max'         => 100000,
			)
		);

		add_settings_field(
			'whitelist',
			__( 'Whitelist', 'mumcp' ),
			array( $this, 'render_textarea_field' ),
			'mcpwp_rate_limit_settings',
			'mcpwp_rate_limit_section',
			array(
				'option_name' => self::RATE_LIMIT_OPTION_NAME,
				'id'          => 'whitelist',
				'description' => __( 'Comma or newline-separated identifiers that bypass limits.', 'mumcp' ),
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
			'oauth_enabled'      => false,
			'oauth_client_id'    => 'site_pilot_ai',
			'oauth_client_secret_hash' => '',
			'oauth_token_ttl'    => 3600,
			'alerts_enabled'          => false,
			'alerts_window_minutes'   => 5,
			'alerts_cooldown_minutes' => 15,
			'alerts_5xx_threshold'    => 5,
			'alerts_auth_threshold'   => 10,
			'analytics_enabled'       => false,
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

		$sanitized['enable_logging'] = ! empty( $input['enable_logging'] );

		$sanitized['log_retention_days'] = isset( $input['log_retention_days'] )
			? min( 365, max( 1, absint( $input['log_retention_days'] ) ) )
			: 30;

		$sanitized['log_store_response_data'] = ! empty( $input['log_store_response_data'] );

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

		$sanitized['oauth_enabled'] = ! empty( $input['oauth_enabled'] );
		$sanitized['oauth_client_id'] = isset( $input['oauth_client_id'] ) && '' !== $input['oauth_client_id']
			? sanitize_key( $input['oauth_client_id'] )
			: 'site_pilot_ai';
		$sanitized['oauth_token_ttl'] = isset( $input['oauth_token_ttl'] )
			? min( 86400, max( 300, absint( $input['oauth_token_ttl'] ) ) )
			: 3600;

		$new_secret = isset( $input['oauth_client_secret'] ) ? trim( (string) $input['oauth_client_secret'] ) : '';
		if ( '' !== $new_secret ) {
			$sanitized['oauth_client_secret_hash'] = wp_hash_password( $new_secret );
		} else {
			$sanitized['oauth_client_secret_hash'] = isset( $current['oauth_client_secret_hash'] ) ? (string) $current['oauth_client_secret_hash'] : '';
		}

		$sanitized['alerts_enabled'] = ! empty( $input['alerts_enabled'] );
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

		$sanitized['analytics_enabled'] = ! empty( $input['analytics_enabled'] );

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
		echo '<p>' . esc_html__( 'Configure general plugin settings.', 'mumcp' ) . '</p>';
	}

	/**
	 * Render rate-limit section.
	 */
	public function render_rate_limit_section() {
		echo '<p>' . esc_html__( 'Configure request throttling and bypass identifiers.', 'mumcp' ) . '</p>';
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

		printf(
			'<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
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
			esc_html__( 'days', 'mumcp' )
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'AI action log entries older than this are automatically deleted. Default: 90. Required for EU AI Act compliance (minimum 90 days recommended).', 'mumcp' )
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
			printf( '<p class="description">%s</p>', esc_html__( 'A value is already configured.', 'mumcp' ) );
		}
	}

	/**
	 * Render site context section.
	 */
	public function render_site_context_section() {
		echo '<p>' . esc_html__( 'Define your site\'s design rules, style guide, and page structure. This is served to AI assistants when they connect, so they know how to build pages that match your brand.', 'mumcp' ) . '</p>';
		echo '<p>' . esc_html__( 'This same context is also published publicly at /llms.txt so external AI crawlers and assistants can pick up the site\'s character, key pages, and preferred framing.', 'mumcp' ) . '</p>';
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
		esc_html_e( 'Write in Markdown. This text is included in the wp_introspect response, available via wp_get_site_context, and published in a public llms.txt summary. AI assistants will use this as their design reference when building or editing pages.', 'mumcp' );
		echo '</p>';

		if ( '' !== $updated ) {
			printf(
				'<p class="description">%s %s</p>',
				esc_html__( 'Last updated:', 'mumcp' ),
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
		$enabled  = ! empty( $settings['analytics_enabled'] );
		$uuid     = class_exists( 'Mcpwp_Analytics' ) ? Mcpwp_Analytics::get_site_uuid() : '';
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME ); ?>[analytics_enabled]"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Share anonymous tool-usage data to help improve mumcp', 'mumcp' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Sends MCP tool names, success/failure status, and execution time to PostHog. No WordPress content or personal data is ever collected.', 'mumcp' ); ?>
			<a href="https://mumcp.com/privacy" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'mumcp' ); ?></a>
		</p>
		<?php if ( $uuid ) : ?>
		<p class="description" style="margin-top:8px;">
			<?php esc_html_e( 'Your site support ID:', 'mumcp' ); ?>
			<code id="mcpwp-site-uuid"><?php echo esc_html( $uuid ); ?></code>
			<button
				type="button"
				class="button button-small"
				onclick="navigator.clipboard.writeText('<?php echo esc_js( $uuid ); ?>').then(function(){this.textContent='<?php echo esc_js( __( 'Copied!', 'mumcp' ) ); ?>';}.bind(this));"
			><?php esc_html_e( 'Copy', 'mumcp' ); ?></button>
			<span class="description"><?php esc_html_e( 'Share this with support when contacting us about your site.', 'mumcp' ); ?></span>
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
		echo '<p>' . esc_html__( 'Apply your agency branding to the plugin UI and enable the [mcpwp_chat] shortcode for client-facing chat widgets.', 'mumcp' ) . '</p>';
		if ( class_exists( 'Mcpwp_White_Label' ) ) {
			$s = Mcpwp_White_Label::get_settings();
			if ( $s['enabled'] && ! empty( $s['agency_name'] ) ) {
				echo '<p><code>[mcpwp_chat]</code> — ' . esc_html__( 'shortcode is active. Add it to any page to embed the chat widget.', 'mumcp' ) . '</p>';
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
			<?php esc_html_e( 'Enable white-label branding', 'mumcp' ); ?>
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
