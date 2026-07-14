<?php
/**
 * Free-tier tool definitions — ops category group.
 *
 * Carved verbatim from Mcpwp_MCP_Free_Tools::get_tools() (G2 split). Behavior-identical.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ops free tool providers. Mixed into Mcpwp_MCP_Free_Tools.
 */
trait Mcpwp_Free_Tools_Ops_Trait {

	/**
	 * @return array
	 */
	private function get_api_keys_tools() {
		$tools = array();
		// API Keys
		$tools[] = $this->define_tool(
			'wp_list_api_keys',
			'List all MCPWP API keys with scopes and metadata. Does not return secret values.',
			array(
				'include_revoked' => array(
					'type'        => 'boolean',
					'description' => 'Include revoked keys in results',
					'default'     => false,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_create_api_key',
			'Create a role-based API key. Roles limit which MCP tool categories the key can access. Returns plaintext key once.',
			array(
				'label' => array(
					'type'        => 'string',
					'description' => 'Human-readable key label',
				),
				'role' => array(
					'type'        => 'string',
					'description' => 'Access role: admin (all tools), author (content/media/taxonomy), designer (elementor/gutenberg/media/site), editor (content/media/taxonomy/seo), custom (pick categories)',
					'enum'        => array( 'admin', 'author', 'designer', 'editor', 'custom' ),
				),
				'tool_categories' => array(
					'type'        => 'array',
					'description' => 'Tool categories to allow (only for custom role). Options: content, media, taxonomy, elementor, gutenberg, seo, forms, site, admin, webhooks',
				),
				'scopes' => array(
					'type'        => 'array',
					'description' => 'Key scopes (read, write, admin). Auto-set for non-admin roles.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_revoke_api_key',
			'Revoke a scoped API key by ID. Immediately disables the key — any agent using it will get 401 errors.',
			array(
				'id' => array(
					'type'        => 'string',
					'description' => 'Scoped API key id',
					'required'    => true,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_rate_limiting_tools() {
		$tools = array();
		// Rate Limiting
		$tools[] = $this->define_tool(
			'wp_rate_limit_status',
			'Get current rate-limit settings and usage for the calling identifier',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_update_rate_limit',
			'Update rate-limit settings for this site (admin only). Sets max requests per window for MCP calls.',
			array(
				'enabled'             => array(
					'type'        => 'boolean',
					'description' => 'Enable or disable rate limiting',
				),
				'requests_per_minute' => array(
					'type'        => 'number',
					'description' => 'Requests allowed per minute',
				),
				'requests_per_hour'   => array(
					'type'        => 'number',
					'description' => 'Requests allowed per hour',
				),
				'burst_limit'         => array(
					'type'        => 'number',
					'description' => 'Requests allowed in short burst window',
				),
				'whitelist'           => array(
					'type'        => 'array',
					'description' => 'Identifiers to bypass rate limiting',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_reset_rate_limit',
			'Reset rate-limit counters for an identifier (admin only)',
			array(
				'identifier' => array(
					'type'        => 'string',
					'description' => 'Identifier to reset (for example key:<id> or IP)',
					'required'    => true,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_plugin_settings_tools() {
		$tools = array();
		// Plugin Settings
		$tools[] = $this->define_tool(
			'wp_get_plugin_settings',
			'Get MCPWP plugin settings. Returns: activity logging config, CORS allowed origins, OAuth settings, alert thresholds, GitHub integration status. Secrets are redacted. Use wp_update_plugin_settings to change values.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_update_plugin_settings',
			'Update MCPWP plugin settings. Pass only the keys you want to change. Allowed keys: enable_logging (bool), log_retention_days (1-365), log_store_response_data (bool), allowed_origins (comma-separated URLs), oauth_enabled (bool), oauth_client_id, oauth_client_secret, oauth_token_ttl (60-86400 seconds), alerts_enabled (bool), alerts_window_minutes (1-60), alerts_cooldown_minutes (1-1440), alerts_5xx_threshold (1-10000), alerts_auth_threshold (1-10000), github_token, github_repo (owner/repo).',
			array(
				'enable_logging' => array(
					'type'        => 'boolean',
					'description' => 'Enable API activity logging',
				),
				'log_retention_days' => array(
					'type'        => 'number',
					'description' => 'Days to retain activity logs (1-365)',
				),
				'log_store_response_data' => array(
					'type'        => 'boolean',
					'description' => 'Store response data in activity logs',
				),
				'allowed_origins' => array(
					'type'        => 'string',
					'description' => 'CORS allowed origins (comma-separated URLs, or empty for default)',
				),
				'oauth_enabled' => array(
					'type'        => 'boolean',
					'description' => 'Enable OAuth client credentials flow',
				),
				'oauth_client_id' => array(
					'type'        => 'string',
					'description' => 'OAuth client ID',
				),
				'oauth_client_secret' => array(
					'type'        => 'string',
					'description' => 'OAuth client secret (write-only, stored hashed)',
				),
				'oauth_token_ttl' => array(
					'type'        => 'number',
					'description' => 'OAuth token TTL in seconds (60-86400)',
				),
				'alerts_enabled' => array(
					'type'        => 'boolean',
					'description' => 'Enable error/auth failure alerts',
				),
				'alerts_window_minutes' => array(
					'type'        => 'number',
					'description' => 'Alert detection window in minutes (1-60)',
				),
				'alerts_cooldown_minutes' => array(
					'type'        => 'number',
					'description' => 'Cooldown between repeated alerts (1-1440)',
				),
				'alerts_5xx_threshold' => array(
					'type'        => 'number',
					'description' => 'Server errors in window before alert fires (1-10000)',
				),
				'alerts_auth_threshold' => array(
					'type'        => 'number',
					'description' => 'Auth failures in window before alert fires (1-10000)',
				),
				'github_token' => array(
					'type'        => 'string',
					'description' => 'GitHub personal access token (write-only)',
				),
				'github_repo' => array(
					'type'        => 'string',
					'description' => 'GitHub repository in owner/repo format',
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_plugin_updates_tools() {
		$tools = array();
		// Plugin Updates
		$tools[] = $this->define_tool(
			'wp_check_update',
			'Check if a newer version of MCPWP is available. Returns current version, latest version, and download URL.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_trigger_update',
			'Download and install the latest version of MCPWP. The plugin will be upgraded in place. Requires administrator privileges.',
			array()
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_integration_mgmt_tools() {
		$tools = array();
		// Integration Management
		$tools[] = $this->define_tool(
			'wp_integrations_status',
			'List all available integrations and their configuration status. Shows which providers are configured, when they were set up, and their last test result. Providers include: pexels (stock photos), openai/gemini (AI image gen, vision), elevenlabs (TTS), screenshot (Cloudflare Browser Rendering for screenshots), google_indexing, and figma (design context intake).',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_configure_integration',
			'Configure a third-party integration. For single-key providers (pexels, openai, gemini, elevenlabs), pass "key". For multi-field providers like "screenshot", "google_indexing", and "figma", pass "config". Example: provider="figma", config={"access_token":"your-token","default_file_key":"abc123"}',
			array(
				'provider' => array(
					'type'        => 'string',
					'description' => 'Provider slug: pexels, openai, gemini, elevenlabs, screenshot, google_indexing, figma',
					'required'    => true,
					'enum'        => array( 'pexels', 'openai', 'gemini', 'elevenlabs', 'screenshot', 'google_indexing', 'figma', 'cloudflare' ),
				),
				'key' => array(
					'type'        => 'string',
					'description' => 'API key (for single-key providers: pexels, openai, gemini, elevenlabs)',
				),
				'config' => array(
					'type'        => 'object',
					'description' => 'Configuration object for multi-field providers. For screenshot: {"url": "worker_url", "token": "auth_token"}. For figma: {"access_token": "personal_access_token", "default_file_key": "optional_file_key"}.',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_test_integration',
			'Test a configured integration connection. For screenshot, sends a test request to the worker. For figma, validates the API token and optional default file access. For API providers, validates the API key.',
			array(
				'provider' => array(
					'type'        => 'string',
					'description' => 'Provider slug to test',
					'required'    => true,
					'enum'        => array( 'pexels', 'openai', 'gemini', 'elevenlabs', 'screenshot', 'google_indexing', 'figma', 'cloudflare' ),
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_remove_integration',
			'Remove a configured integration. Deletes the stored API key or configuration for the provider.',
			array(
				'provider' => array(
					'type'        => 'string',
					'description' => 'Provider slug to remove',
					'required'    => true,
					'enum'        => array( 'pexels', 'openai', 'gemini', 'elevenlabs', 'screenshot', 'google_indexing', 'figma', 'cloudflare' ),
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_cloudflare_tools() {
		$tools   = array();
		$setting_names = array( 'early_hints', 'http3', 'rocket_loader', 'polish' );

		$tools[] = $this->define_tool(
			'wp_cloudflare_status',
			'Check whether Cloudflare is connected for this site (API token + zone ID configured under Integrations) and report the zone name, status, and plan.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_cloudflare_fix_mcp_access',
			'Self-service fix for the most common Cloudflare/MCP problem: Bot Fight Mode or Security Level silently blocking MCP requests to this site. Creates or updates a Cloudflare Configuration Rule that drops Security Level to "essentially_off" for this site\'s own /wp-json/mcpwp/v1/* path (the confirmed fix), and best-effort adds a WAF skip rule for Super Bot Fight Mode / Managed Rules on the same path. Requires the Cloudflare integration to be configured first.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_cloudflare_purge_cache',
			'Purge Cloudflare cache for specific URLs (e.g. right after editing a page) or the entire zone. Pass urls as an array, or purge_everything=true.',
			array(
				'urls'             => array(
					'type'        => 'array',
					'description' => 'URLs to purge (max 30). Omit if purge_everything is true.',
					'items'       => array( 'type' => 'string' ),
				),
				'purge_everything' => array(
					'type'        => 'boolean',
					'description' => 'Purge the entire zone cache instead of specific URLs.',
					'default'     => false,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_cloudflare_get_speed_settings',
			'Read this zone\'s current Cloudflare Speed settings that affect Core Web Vitals: early_hints, http3, rocket_loader, polish (image optimization).',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_cloudflare_update_speed_setting',
			'Toggle one Cloudflare Speed setting for this zone. Valid settings: early_hints, http3, rocket_loader (values: "on"/"off"), polish (values: "off"/"lossless"/"lossy"/"webp").',
			array(
				'setting' => array(
					'type'        => 'string',
					'description' => 'Setting name.',
					'required'    => true,
					'enum'        => $setting_names,
				),
				'value'   => array(
					'description' => 'New value for the setting.',
					'required'    => true,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_webhooks_tools() {
		$tools = array();
		// Webhooks
		$tools[] = $this->define_tool(
			'wp_list_webhook_events',
			'List all available webhook event names and descriptions. Use to discover events before creating a webhook subscription.',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_get_event_schema',
			'Get normalized AI-first event schema with WordPress hook names for agents and webhook subscribers',
			array()
		);

		$tools[] = $this->define_tool(
			'wp_list_mcp_events',
			'List recent normalized MCPWP events emitted by approvals, SEO audits, and other agent workflows',
			array(
				'type'  => array(
					'type'        => 'string',
					'description' => 'Optional event type filter, for example approval.created or seo.audit_completed',
				),
				'limit' => array(
					'type'        => 'number',
					'description' => 'Maximum events to return',
					'default'     => 50,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_webhooks',
			'List registered webhook endpoints with URL, events, and delivery status. Use to audit webhook subscriptions.',
			array(
				'status'   => array(
					'type'        => 'string',
					'description' => 'Status filter (active, disabled, all)',
				),
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Results per page',
					'default'     => 50,
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Page number',
					'default'     => 1,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_create_webhook',
			'Create a new webhook endpoint subscription. Registers a URL to receive POST notifications on specified events.',
			array(
				'name'   => array(
					'type'        => 'string',
					'description' => 'Webhook display name',
					'required'    => true,
				),
				'url'    => array(
					'type'        => 'string',
					'description' => 'Webhook target URL',
					'required'    => true,
				),
				'events' => array(
					'type'        => 'array',
					'description' => 'Events to subscribe to',
					'required'    => true,
				),
				'secret' => array(
					'type'        => 'string',
					'description' => 'Optional signing secret',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_update_webhook',
			'Update an existing webhook: URL, events, secret, or enabled/disabled status.',
			array(
				'id'     => array(
					'type'        => 'number',
					'description' => 'Webhook ID',
					'required'    => true,
				),
				'name'   => array(
					'type'        => 'string',
					'description' => 'Webhook display name',
				),
				'url'    => array(
					'type'        => 'string',
					'description' => 'Webhook target URL',
				),
				'events' => array(
					'type'        => 'array',
					'description' => 'Updated event list',
				),
				'status' => array(
					'type'        => 'string',
					'description' => 'Webhook status (active or disabled)',
				),
				'secret' => array(
					'type'        => 'string',
					'description' => 'Webhook signing secret',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_delete_webhook',
			'Permanently delete a webhook subscription and stop all future deliveries.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Webhook ID',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_test_webhook',
			'Send a test delivery payload to a webhook endpoint. Use to verify the receiving server is working.',
			array(
				'id' => array(
					'type'        => 'number',
					'description' => 'Webhook ID',
					'required'    => true,
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_webhook_logs',
			'List delivery logs for a webhook. Returns timestamps, response codes, and payload details for debugging.',
			array(
				'id'       => array(
					'type'        => 'number',
					'description' => 'Webhook ID',
					'required'    => true,
				),
				'per_page' => array(
					'type'        => 'number',
					'description' => 'Results per page',
					'default'     => 50,
				),
				'page'     => array(
					'type'        => 'number',
					'description' => 'Page number',
					'default'     => 1,
				),
			)
		);

		return $tools;
	}

	/**
	 * @return array
	 */
	private function get_feedback_tools() {
		$tools = array();
		// Feedback
		$tools[] = $this->define_tool(
			'wp_submit_feedback',
			'Submit feedback, a bug report, or a feature request to the site owner. Optionally creates a GitHub issue if configured.',
			array(
				'type'        => array(
					'type'        => 'string',
					'description' => 'Feedback type: bug_report, feature_request, or feedback',
					'required'    => true,
					'enum'        => array( 'bug_report', 'feature_request', 'feedback' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => 'Short summary',
					'required'    => true,
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Detailed description',
					'required'    => true,
				),
				'priority'    => array(
					'type'        => 'string',
					'description' => 'Priority: low, medium, high, critical',
					'enum'        => array( 'low', 'medium', 'high', 'critical' ),
					'default'     => 'medium',
				),
				'meta'        => array(
					'type'        => 'object',
					'description' => 'Extra context (page_id, tool_name, error_message, steps_to_reproduce)',
				),
			)
		);

		$tools[] = $this->define_tool(
			'wp_list_feedback',
			'List submitted feedback entries with optional filters for type and status',
			array(
				'type'   => array(
					'type'        => 'string',
					'description' => 'Filter by type: bug_report, feature_request, feedback',
					'enum'        => array( 'bug_report', 'feature_request', 'feedback' ),
				),
				'status' => array(
					'type'        => 'string',
					'description' => 'Filter by status: open, acknowledged, resolved, closed, all',
					'default'     => 'open',
				),
				'limit'  => array(
					'type'        => 'number',
					'description' => 'Max results (1-100)',
					'default'     => 20,
				),
			)
		);

		return $tools;
	}
}
