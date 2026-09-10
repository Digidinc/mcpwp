<?php
/**
 * Integration Manager
 *
 * Stores and retrieves encrypted API keys for third-party AI providers.
 *
 * @package MCPWP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages third-party AI provider integrations.
 */
class Mcpwp_Integration_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var Mcpwp_Integration_Manager|null
	 */
	private static $instance = null;

	/**
	 * WP option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'mcpwp_integrations';

	/**
	 * Supported providers.
	 *
	 * @var array
	 */
	const PROVIDERS = array(
		'openai'     => array(
			'name'       => 'OpenAI',
			'url'        => 'https://platform.openai.com/api-keys',
			'key_prefix' => 'sk-',
			'tier'       => 'pro',
		),
		'gemini'     => array(
			'name'       => 'Google Gemini',
			'url'        => 'https://aistudio.google.com/apikey',
			'key_prefix' => '',
			'tier'       => 'pro',
		),
		'elevenlabs' => array(
			'name'       => 'ElevenLabs',
			'url'        => 'https://elevenlabs.io/settings/api-keys',
			'key_prefix' => '',
			'tier'       => 'pro',
		),
		'pexels'     => array(
			'name'       => 'Pexels',
			'url'        => 'https://www.pexels.com/api/',
			'key_prefix' => '',
			'tier'       => 'free',
		),
		'screenshot' => array(
			'name'        => 'Screenshot Worker',
			'url'         => 'https://mcpwp.net/docs/screenshot-worker/',
			'key_prefix'  => '',
			'tier'        => 'free',
			'description' => 'Cloudflare Browser Rendering for high-quality headless Chromium screenshots. Without this, screenshots use WordPress mshots (lower quality, delayed).',
			'fields'      => array(
				'url'   => array(
					'label'       => 'Worker URL',
					'type'        => 'url',
					'placeholder' => 'https://mcpwp-screenshot.your-subdomain.workers.dev',
				),
				'token' => array(
					'label'       => 'Auth Token',
					'type'        => 'password',
					'placeholder' => 'Your worker auth token',
				),
			),
		),
		'google_indexing' => array(
			'name'        => 'Google Indexing API',
			'url'         => 'https://console.cloud.google.com/apis/credentials',
			'key_prefix'  => '',
			'tier'        => 'pro',
			'description' => 'Submit URLs to the Google Indexing API. Google only crawls submissions for pages with JobPosting structured data, or BroadcastEvent inside a VideoObject — ordinary pages and posts are accepted and then ignored. For general content, use a sitemap and Search Console. Setup requires a Google Cloud project with the Indexing API enabled, a service account, a downloaded JSON key, and that service account granted access in Search Console.',
			'fields'      => array(
				'service_account_json' => array(
					'label'       => 'Service Account JSON',
					'type'        => 'textarea',
					'placeholder' => 'Paste your Google service account JSON key',
				),
			),
		),
		'figma' => array(
			'name'        => 'Figma',
			'url'         => 'https://developers.figma.com/docs/figma-mcp-server/',
			'key_prefix'  => '',
			'tier'        => 'pro',
			'description' => 'Read Figma design context into MCPWP so models can inspect approved frames and then turn them into archetypes, parts, and site briefs. Supports either a personal access token or a Figma OAuth app.',
			'fields'      => array(
				'personal_access_token' => array(
					'label'       => 'Personal Access Token',
					'type'        => 'password',
					'placeholder' => 'Optional: paste a Figma personal access token',
				),
				'oauth_client_id'       => array(
					'label'       => 'OAuth Client ID',
					'type'        => 'text',
					'placeholder' => 'Optional: Figma OAuth app client ID',
				),
				'oauth_client_secret'   => array(
					'label'       => 'OAuth Client Secret',
					'type'        => 'password',
					'placeholder' => 'Optional: Figma OAuth app client secret',
				),
				'default_file_key'      => array(
					'label'       => 'Default File Key',
					'type'        => 'text',
					'placeholder' => 'Optional default file key for this site',
				),
			),
		),
		'posthog'    => array(
			'name'        => 'PostHog',
			'url'         => 'https://us.posthog.com/settings/project-details',
			'tier'        => 'free',
			'description' => 'Product analytics for the MCPWP admin interface. Tracks key admin actions (API key copy, connection test, upgrade clicks) to help understand plugin adoption.',
			'fields'      => array(
				'token' => array(
					'label'       => 'Project API Key',
					'placeholder' => 'phc_...',
					'type'        => 'text',
				),
				'host'  => array(
					'label'       => 'API Host',
					'placeholder' => 'https://us.i.posthog.com',
					'type'        => 'text',
				),
			),
		),
		'dataforseo' => array(
			'name'        => 'DataForSEO',
			'url'         => 'https://app.dataforseo.com/api-access',
			'tier'        => 'pro',
			'description' => 'Keyword search volume, difficulty and search visibility. You bring your own DataForSEO account; usage is billed to you directly.',
			'fields'      => array(
				'login'    => array(
					'label'       => 'API Login',
					'placeholder' => 'you@example.com',
					'type'        => 'text',
				),
				'password' => array(
					'label'       => 'API Password',
					'placeholder' => '',
					'type'        => 'password',
				),
			),
		),
		'wpengine_staging' => array(
			'name'        => 'WP Engine Staging',
			'url'         => 'https://developers.wpengine.com/',
			'key_prefix'  => '',
			'tier'        => 'pro',
			'description' => 'Enables staging push/pull via the WP Engine Installs API. Only needed on WP Engine hosted sites.',
			'fields'      => array(
				'install_id' => array(
					'label'       => 'Install ID',
					'type'        => 'text',
					'placeholder' => 'e.g. abc123def456',
				),
				'api_token'  => array(
					'label'       => 'API Token (Base64 user:pass)',
					'type'        => 'password',
					'placeholder' => 'Base64-encoded username:password',
				),
			),
		),
		'kinsta_staging' => array(
			'name'        => 'Kinsta Staging',
			'url'         => 'https://api-docs.kinsta.com/',
			'key_prefix'  => '',
			'tier'        => 'pro',
			'description' => 'Enables staging push/pull via the Kinsta Environments API. Only needed on Kinsta hosted sites.',
			'fields'      => array(
				'site_id' => array(
					'label'       => 'Site ID',
					'type'        => 'text',
					'placeholder' => 'Kinsta site UUID',
				),
				'api_key' => array(
					'label'       => 'API Key',
					'type'        => 'password',
					'placeholder' => 'Kinsta API key',
				),
			),
		),
		'pressable_staging' => array(
			'name'        => 'Pressable Staging',
			'url'         => 'https://my.pressable.com/documentation/api/v1',
			'key_prefix'  => '',
			'tier'        => 'pro',
			'description' => 'Enables staging push/pull via the Pressable Site API. Only needed on Pressable hosted sites.',
			'fields'      => array(
				'site_id'   => array(
					'label'       => 'Site ID',
					'type'        => 'text',
					'placeholder' => 'Pressable numeric site ID',
				),
				'api_token' => array(
					'label'       => 'OAuth2 Bearer Token',
					'type'        => 'password',
					'placeholder' => 'Pressable OAuth2 access token',
				),
			),
		),
		'cloudflare' => array(
			'name'        => 'Cloudflare',
			'url'         => 'https://developers.cloudflare.com/fundamentals/api/get-started/create-token/',
			'key_prefix'  => '',
			'tier'        => 'free',
			'description' => 'Connect this site\'s Cloudflare zone to fix Bot Fight Mode/Security Level blocking MCP requests, purge cache after content edits, and manage Speed settings (Early Hints, HTTP/3, Rocket Loader, Polish) that affect Core Web Vitals. Use a scoped API Token (Zone Settings: Edit, Zone WAF: Edit, Cache Purge: Purge) — not the Global API Key.',
			'fields'      => array(
				'api_token' => array(
					'label'       => 'API Token',
					'type'        => 'password',
					'placeholder' => 'Scoped Cloudflare API Token for this zone',
				),
				'zone_id'   => array(
					'label'       => 'Zone ID',
					'type'        => 'text',
					'placeholder' => 'Zone ID (Cloudflare dashboard > this domain > Overview > API)',
				),
			),
		),
	);

	/**
	 * Provider capability mapping for auto-selection.
	 *
	 * @var array
	 */
	const CAPABILITY_PROVIDERS = array(
		'image_generation' => array( 'openai', 'gemini' ),
		'vision'           => array( 'openai', 'gemini' ),
		'text'             => array( 'openai', 'gemini' ),
		'tts'              => array( 'elevenlabs' ),
		'stock_photos'     => array( 'pexels' ),
		'screenshots'      => array( 'screenshot' ),
		'indexing'          => array( 'google_indexing' ),
		'design_context'   => array( 'figma' ),
		'cdn'              => array( 'cloudflare' ),
		// posthog omitted: analytics-only integration, not capability-selectable via get_preferred_provider()
	);

	/**
	 * Get singleton instance.
	 *
	 * @return Mcpwp_Integration_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if a provider uses multi-field config (e.g. URL + token).
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public function is_multi_field_provider( $provider ) {
		return isset( self::PROVIDERS[ $provider ]['fields'] );
	}

	/**
	 * Store an API key for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @param string $key      Plaintext API key.
	 * @return bool True on success.
	 */
	public function set_provider_key( $provider, $key ) {
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return false;
		}

		$encryption = Mcpwp_Encryption::get_instance();
		$encrypted  = $encryption->encrypt( $key );
		if ( false === $encrypted ) {
			return false;
		}

		$data = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data[ $provider ] = array(
			'key_encrypted' => $encrypted,
			'configured_at' => current_time( 'mysql' ),
			'last_tested'   => null,
			'test_status'   => null,
		);

		return $this->save_providers( $provider, $data );
	}

	/**
	 * Store multi-field config for a provider (e.g. screenshot worker URL + token).
	 *
	 * @param string $provider Provider slug.
	 * @param array  $config   Associative array of field values.
	 * @return bool True on success.
	 */
	public function set_provider_config( $provider, $config ) {
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			return false;
		}

		$encryption = Mcpwp_Encryption::get_instance();
		$encrypted  = $encryption->encrypt( wp_json_encode( $config ) );
		if ( false === $encrypted ) {
			return false;
		}

		$data = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$data[ $provider ] = array(
			'key_encrypted' => $encrypted,
			'configured_at' => current_time( 'mysql' ),
			'last_tested'   => null,
			'test_status'   => null,
			'multi_field'   => true,
		);

		return $this->save_providers( $provider, $data );
	}

	/**
	 * Get multi-field config for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @return array|false Config array or false.
	 */
	public function get_provider_config( $provider ) {
		$data = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $data ) || empty( $data[ $provider ]['key_encrypted'] ) ) {
			return false;
		}

		$encryption = Mcpwp_Encryption::get_instance();
		$decrypted  = $encryption->decrypt( $data[ $provider ]['key_encrypted'] );
		if ( false === $decrypted ) {
			return false;
		}

		// Multi-field providers store JSON.
		if ( ! empty( $data[ $provider ]['multi_field'] ) ) {
			$config = json_decode( $decrypted, true );
			return is_array( $config ) ? $config : false;
		}

		// Single-key fallback.
		return array( 'key' => $decrypted );
	}

	/**
	 * Get PostHog analytics config with safe defaults.
	 *
	 * Returns an array with 'token' (empty string if not configured) and
	 * 'host' (defaults to us.i.posthog.com). Callers must check token is non-empty.
	 *
	 * @return array{token: string, host: string}
	 */
	public function get_posthog_config() {
		$config = $this->get_provider_config( 'posthog' );
		return array(
			'token' => ( is_array( $config ) && ! empty( $config['token'] ) ) ? $config['token'] : '',
			'host'  => ( is_array( $config ) && ! empty( $config['host'] ) ) ? $config['host'] : MCPWP_POSTHOG_DEFAULT_HOST,
		);
	}

	/**
	 * Get decrypted API key for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @return string|false Plaintext key or false.
	 */
	public function get_provider_key( $provider ) {
		$data = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $data ) || empty( $data[ $provider ]['key_encrypted'] ) ) {
			return false;
		}

		$encryption = Mcpwp_Encryption::get_instance();
		return $encryption->decrypt( $data[ $provider ]['key_encrypted'] );
	}

	/**
	 * Remove API key for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @return bool True on success.
	 */
	public function remove_provider_key( $provider ) {
		$data = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $data ) || ! isset( $data[ $provider ] ) ) {
			return false;
		}

		unset( $data[ $provider ] );
		return $this->save_providers( $provider, $data );
	}

	/**
	 * Write the provider records after one provider changed.
	 *
	 * A provider whose key was just re-entered or removed no longer needs
	 * re-entering, so its encryption-migration failure is cleared — only when
	 * the write succeeded (#564 P3-8).
	 *
	 * @param string $provider Provider slug that changed.
	 * @param array  $data     All provider records.
	 * @return bool True on success.
	 */
	private function save_providers( $provider, array $data ) {
		$saved = update_option( self::OPTION_NAME, $data );

		if ( $saved && class_exists( 'Mcpwp_Encryption_Migration' ) ) {
			Mcpwp_Encryption_Migration::clear_provider_failure( $provider );
		}

		return $saved;
	}

	/**
	 * Check if a provider has a configured key.
	 *
	 * @param string $provider Provider slug.
	 * @return bool
	 */
	public function has_provider_key( $provider ) {
		$data = get_option( self::OPTION_NAME, array() );
		return is_array( $data ) && ! empty( $data[ $provider ]['key_encrypted'] );
	}

	/**
	 * Test a provider connection.
	 *
	 * @param string $provider Provider slug.
	 * @return array{success: bool, message: string}
	 */
	public function test_provider( $provider ) {
		// Screenshot worker has its own test logic.
		if ( 'screenshot' === $provider ) {
			return $this->test_screenshot_provider();
		}

		// Google Indexing uses multi-field config with its own test logic.
		if ( 'google_indexing' === $provider ) {
			return $this->test_google_indexing_provider();
		}

		if ( 'figma' === $provider ) {
			return $this->test_figma_provider();
		}

		if ( 'dataforseo' === $provider ) {
			return $this->test_dataforseo_provider();
		}

		if ( 'cloudflare' === $provider ) {
			return $this->test_cloudflare_provider();
		}

		$key = $this->get_provider_key( $provider );
		if ( false === $key ) {
			return array(
				'success' => false,
				'message' => __( 'No API key configured for this provider.', 'mcpwp' ),
			);
		}

		$provider_instance = $this->get_provider_instance( $provider );
		if ( ! $provider_instance ) {
			return array(
				'success' => false,
				'message' => __( 'Unknown provider.', 'mcpwp' ),
			);
		}

		$result = $provider_instance->test_connection();

		// Update test status.
		$data = get_option( self::OPTION_NAME, array() );
		if ( is_array( $data ) && isset( $data[ $provider ] ) ) {
			$data[ $provider ]['last_tested'] = current_time( 'mysql' );
			$data[ $provider ]['test_status'] = $result['success'] ? 'ok' : 'failed';
			update_option( self::OPTION_NAME, $data );
		}

		return $result;
	}

	/**
	 * Test the screenshot worker connection.
	 *
	 * @return array{success: bool, message: string}
	 */
	private function test_screenshot_provider() {
		$config = $this->get_provider_config( 'screenshot' );
		if ( ! $config || empty( $config['url'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Screenshot worker URL not configured.', 'mcpwp' ),
			);
		}

		$headers = array( 'Content-Type' => 'application/json' );
		if ( ! empty( $config['token'] ) ) {
			$headers['X-Auth-Token'] = $config['token'];
		}

		$response = wp_remote_post(
			rtrim( $config['url'], '/' ),
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => wp_json_encode( array(
					'url'    => home_url(),
					'width'  => 320,
					'height' => 240,
					'wait'   => 1000,
				) ),
			)
		);

		$result = array( 'success' => false, 'message' => '' );

		if ( is_wp_error( $response ) ) {
			$result['message'] = $response->get_error_message();
		} else {
			$code = wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 200 === $code && ! empty( $body['success'] ) ) {
				$result['success'] = true;
				$result['message'] = __( 'Screenshot worker is responding correctly.', 'mcpwp' );
			} else {
				$result['message'] = isset( $body['error'] ) ? $body['error'] : sprintf( 'Worker returned HTTP %d', $code );
			}
		}

		// Update test status.
		$data = get_option( self::OPTION_NAME, array() );
		if ( is_array( $data ) && isset( $data['screenshot'] ) ) {
			$data['screenshot']['last_tested'] = current_time( 'mysql' );
			$data['screenshot']['test_status'] = $result['success'] ? 'ok' : 'failed';
			update_option( self::OPTION_NAME, $data );
		}

		return $result;
	}

	/**
	 * Test the Google Indexing API connection.
	 *
	 * @return array{success: bool, message: string}
	 */
	private function test_google_indexing_provider() {
		if ( ! class_exists( 'Mcpwp_Google_Indexing' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Google Indexing API requires a configured service account.', 'mcpwp' ),
			);
		}

		$indexing = new Mcpwp_Google_Indexing();
		$result   = $indexing->test_connection();

		// Update test status.
		$data = get_option( self::OPTION_NAME, array() );
		if ( is_array( $data ) && isset( $data['google_indexing'] ) ) {
			$data['google_indexing']['last_tested'] = current_time( 'mysql' );
			$data['google_indexing']['test_status'] = $result['success'] ? 'ok' : 'failed';
			update_option( self::OPTION_NAME, $data );
		}

		return $result;
	}

	/**
	 * Test the Figma API connection.
	 *
	 * @return array{success: bool, message: string}
	 */
	private function test_figma_provider() {
		if ( ! class_exists( 'Mcpwp_Figma' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Figma support is not available in this build.', 'mcpwp' ),
			);
		}

		$figma  = new Mcpwp_Figma();
		$result = $figma->test_connection();

		$data = get_option( self::OPTION_NAME, array() );
		if ( is_array( $data ) && isset( $data['figma'] ) ) {
			$data['figma']['last_tested'] = current_time( 'mysql' );
			$data['figma']['test_status'] = $result['success'] ? 'ok' : 'failed';
			update_option( self::OPTION_NAME, $data );
		}

		return $result;
	}

	/**
	 * Verify DataForSEO credentials and surface the remaining credit.
	 *
	 * The balance is shown because every lookup spends the customer's money —
	 * they should be able to see what is left without leaving WP admin.
	 *
	 * @return array{success:bool,message:string}
	 */
	private function test_dataforseo_provider() {
		$config = $this->get_provider_config( 'dataforseo' );

		if ( empty( $config['login'] ) || empty( $config['password'] ) ) {
			return array(
				'success' => false,
				'message' => Mcpwp_Provider_Dataforseo::describe_reason( 'not_configured' ),
			);
		}

		$provider = new Mcpwp_Provider_Dataforseo( $config['login'], $config['password'] );
		$result   = $provider->test_connection();

		return array(
			'success' => (bool) $result['success'],
			'message' => (string) $result['message'],
		);
	}

	/**
	 * Test the Cloudflare API connection.
	 *
	 * @return array{success: bool, message: string}
	 */
	private function test_cloudflare_provider() {
		if ( ! class_exists( 'Mcpwp_Provider_Cloudflare' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Cloudflare support is not available in this build.', 'mcpwp' ),
			);
		}

		$cloudflare = new Mcpwp_Provider_Cloudflare();
		$result     = $cloudflare->test_connection();

		$data = get_option( self::OPTION_NAME, array() );
		if ( is_array( $data ) && isset( $data['cloudflare'] ) ) {
			$data['cloudflare']['last_tested'] = current_time( 'mysql' );
			$data['cloudflare']['test_status'] = $result['success'] ? 'ok' : 'failed';
			update_option( self::OPTION_NAME, $data );
		}

		return $result;
	}

	/**
	 * Get list of available providers with status.
	 *
	 * @return array
	 */
	public function get_available_providers() {
		$data      = get_option( self::OPTION_NAME, array() );
		$providers = array();

		foreach ( self::PROVIDERS as $slug => $info ) {
			$stored = is_array( $data ) && isset( $data[ $slug ] ) ? $data[ $slug ] : array();
			$provider_data = array(
				'name'          => $info['name'],
				'url'           => $info['url'],
				'tier'          => $info['tier'],
				'configured'    => ! empty( $stored['key_encrypted'] ),
				'configured_at' => isset( $stored['configured_at'] ) ? $stored['configured_at'] : null,
				'last_tested'   => isset( $stored['last_tested'] ) ? $stored['last_tested'] : null,
				'test_status'   => isset( $stored['test_status'] ) ? $stored['test_status'] : null,
			);

			if ( isset( $info['description'] ) ) {
				$provider_data['description'] = $info['description'];
			}
			if ( isset( $info['fields'] ) ) {
				$provider_data['fields'] = $info['fields'];
			}

			if ( 'figma' === $slug ) {
				$config = $this->get_provider_config( 'figma' );
				$config = is_array( $config ) ? $config : array();

				$provider_data['oauth_ready']     = ! empty( $config['oauth_client_id'] ) && ! empty( $config['oauth_client_secret'] );
				$provider_data['oauth_connected'] = ! empty( $config['access_token'] ) || ! empty( $config['refresh_token'] );
				$provider_data['auth_mode']       = ! empty( $config['personal_access_token'] ) ? 'personal_token' : ( $provider_data['oauth_connected'] ? 'oauth' : 'unconfigured' );
			}

			$providers[ $slug ] = $provider_data;
		}

		return $providers;
	}

	/**
	 * Get the preferred provider for a capability.
	 *
	 * Returns the first configured provider that supports the capability.
	 *
	 * @param string $capability Capability (image_generation, vision, text, tts, stock_photos).
	 * @return string|null Provider slug or null.
	 */
	public function get_preferred_provider( $capability ) {
		if ( ! isset( self::CAPABILITY_PROVIDERS[ $capability ] ) ) {
			return null;
		}

		foreach ( self::CAPABILITY_PROVIDERS[ $capability ] as $provider ) {
			if ( $this->has_provider_key( $provider ) ) {
				return $provider;
			}
		}

		return null;
	}

	/**
	 * Get a provider instance.
	 *
	 * @param string $provider Provider slug.
	 * @return object|null Provider instance.
	 */
	public function get_provider_instance( $provider ) {
		$key = $this->get_provider_key( $provider );
		if ( false === $key ) {
			return null;
		}

		switch ( $provider ) {
			case 'openai':
				return new Mcpwp_Provider_OpenAI( $key );
			case 'gemini':
				return new Mcpwp_Provider_Gemini( $key );
			case 'elevenlabs':
				return new Mcpwp_Provider_ElevenLabs( $key );
			case 'pexels':
				return new Mcpwp_Provider_Pexels( $key );
			default:
				return null;
		}
	}
}
