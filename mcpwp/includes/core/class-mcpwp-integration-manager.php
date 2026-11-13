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

	/** Live high-water counters, retained when a provider is removed. */
	const GENERATIONS_KEY = '_mcpwp_generations';

	/**
	 * How many times a provider write reads the records again after another
	 * writer changed them between its read and its write (#845).
	 */
	const WRITE_ATTEMPTS = 5;

	/**
	 * Outcomes of write_records().
	 */
	const WRITE_STORED    = 'stored';
	const WRITE_UNCHANGED = 'unchanged';
	const WRITE_FAILED    = 'failed';

	/**
	 * Why the last set_provider_key() / set_provider_config() returned false:
	 * 'unknown_provider', 'encryption_failed', 'integration_write_failed' or
	 * 'invalid_config' or 'generation_conflict' ('' after a save that succeeded).
	 *
	 * @var string
	 */
	private $last_save_error = '';

	/**
	 * Human-readable reason when last_save_error is 'invalid_config'.
	 *
	 * @var string
	 */
	private $last_save_error_message = '';

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
			'destination_rules' => array( 'url' => array( 'rtrim_slash' => true ) ),
			'secret_bindings' => array(
				'url' => array( 'token' ),
			),
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
			'secret_bindings' => array(
				'oauth_client_id' => array( 'oauth_client_secret', 'access_token', 'refresh_token', 'token_type', 'expires_at' ),
			),
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
			'destination_rules' => array( 'host' => array( 'default' => 'https://us.i.posthog.com', 'default_constant' => 'MCPWP_POSTHOG_DEFAULT_HOST', 'empty_default' => true, 'rtrim_slash' => true ) ),
			'secret_bindings' => array(
				'host' => array( 'token' ),
			),
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
			'secret_bindings' => array(
				'login' => array( 'password' ),
			),
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
			'secret_bindings' => array(
				'install_id' => array( 'api_token' ),
			),
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
			'secret_bindings' => array(
				'site_id' => array( 'api_key' ),
			),
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
			'secret_bindings' => array(
				'site_id' => array( 'api_token' ),
			),
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
			'secret_bindings' => array(
				'zone_id' => array( 'api_token' ),
			),
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
		'jev' => array(
			'name'        => 'Jev (TypeSafe / Cloudflare Workers AI)',
			'url'         => 'https://typesafe.ai/',
			'key_prefix'  => '',
			'tier'        => 'free',
			'description' => 'Optional: TypeSafe\'s Jev evaluation model ranks the approval queue by priority so the most urgent pending changes surface first. Either bring your own TypeSafe API key (billed to you directly, input tokens only, $0.042/1M), or run the same model through Cloudflare Workers AI: enter your Cloudflare account ID and create a Workers AI token with Workers AI:Read permission for your account (same pricing, https://developers.cloudflare.com/ai/models/typesafe/jev/). Jev never uses the Cloudflare integration\'s zone token. A TypeSafe key is preferred when both are set; the transport setting forces one, or "off" stops all sending. Removing this integration deletes every Jev credential. Jev only routes — it never approves, rejects, applies, or blocks anything, and it has no say over scope checks or safety reports.',
			'destination_rules' => array( 'endpoint' => array( 'default' => 'https://api.typesafe.ai/v1/systemone', 'default_constant' => 'Mcpwp_Jev::DEFAULT_ENDPOINT', 'trim' => true ) ),
			'secret_bindings' => array(
				'endpoint' => array( 'api_key' ),
				'cf_account_id' => array( 'cf_api_token' ),
			),
			'fields'      => array(
				'api_key'       => array(
					'label'       => 'API Key (TypeSafe)',
					'type'        => 'password',
					'placeholder' => 'TypeSafe API key',
				),
				'endpoint'      => array(
					'label'       => 'TypeSafe endpoint override (advanced)',
					'type'        => 'url',
					'placeholder' => 'https://api.typesafe.ai/v1/systemone',
				),
				'cf_account_id' => array(
					'label'       => 'Cloudflare Account ID (Workers AI)',
					'type'        => 'text',
					'placeholder' => '32-character Cloudflare account ID',
				),
				'cf_api_token'  => array(
					'label'       => 'Cloudflare Workers AI API Token',
					'type'        => 'password',
					'placeholder' => 'Token with Workers AI:Read permission for your account',
				),
				'transport'     => array(
					'label'   => 'Transport',
					'type'    => 'select',
					'options' => array(
						'auto'       => 'Auto (TypeSafe key if set, else Cloudflare Workers AI)',
						'typesafe'   => 'TypeSafe only',
						'cloudflare' => 'Cloudflare Workers AI only',
						'off'        => 'Off (send nothing)',
					),
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

	/** Register the guard for WordPress option imports and WP-CLI option writes. */
	public static function register_option_guard() {
		add_filter( 'sanitize_option_' . self::OPTION_NAME, array( __CLASS__, 'reject_raw_option_write' ) );
	}

	/** Raw replacement can replay version metadata; restore through CAS instead. */
	public static function reject_raw_option_write( $value ) {
		throw new RuntimeException( 'Provider records require Mcpwp_Integration_Manager::restore_provider_records(); raw option replacement is not supported.' );
	}

	/** Whether a generation can be advanced without integer overflow. */
	private static function generation_can_advance( $generation ) {
		return is_int( $generation ) && $generation >= 0 && $generation < PHP_INT_MAX - 1;
	}

	/**
	 * Assign a version for any provider mutation, including ciphertext migration.
	 * Backup versions are a floor only; their incarnation is never trusted.
	 *
	 * @return array|WP_Error Versioned record, or unsafe generation metadata.
	 */
	public static function advance_record_version( $current, array $next, $floor = 0, $restore = false ) {
		$live   = is_array( $current ) && array_key_exists( 'generation', $current ) ? $current['generation'] : 0;
		$saved  = $restore && array_key_exists( 'generation', $next ) ? $next['generation'] : 0;
		foreach ( array( $live, $saved, $floor ) as $value ) {
			if ( ! self::generation_can_advance( $value ) ) {
				return new WP_Error( 'invalid_generation', __( 'Provider generation metadata cannot be advanced safely.', 'mcpwp' ) );
			}
		}
		$generation = max( $live, $saved, $floor );
		$record_id  = is_array( $current ) && isset( $current['record_id'] ) ? $current['record_id'] : '';
		$next['generation'] = $generation + 1;
		$next['record_id']  = ! $restore && '' !== $record_id ? $record_id : bin2hex( random_bytes( 16 ) );
		return $next;
	}

	/**
	 * Restore encrypted records atomically. Live counters survive deletion and
	 * override backup metadata; every restored provider gets a fresh incarnation.
	 * Internal restore API only: no generic option endpoint may write this state.
	 *
	 * @param array $backup Encrypted provider records from a trusted backup.
	 * @return bool Whether the restored records committed.
	 */
	public function restore_provider_records( array $backup ) {
		unset( $backup[ self::GENERATIONS_KEY ] );
		foreach ( $backup as $provider => $record ) {
			if ( ! is_array( $record ) || ! isset( $record['key_encrypted'] ) || ! is_string( $record['key_encrypted'] )
				|| ( array_key_exists( 'generation', $record ) && ! self::generation_can_advance( $record['generation'] ) ) ) {
				$this->last_save_error = 'invalid_config';
				return false;
			}
		}
		$outcome = $this->write_records(
			static function ( array $records ) use ( $backup ) {
				$counters = isset( $records[ self::GENERATIONS_KEY ] ) && is_array( $records[ self::GENERATIONS_KEY ] ) ? $records[ self::GENERATIONS_KEY ] : array();
				$restored = array();
				foreach ( $records as $provider => $record ) {
					if ( self::GENERATIONS_KEY !== $provider && is_array( $record ) && ! isset( $backup[ $provider ] ) ) {
						$removed = self::advance_record_version( $record, array(), $counters[ $provider ] ?? 0 );
						if ( is_wp_error( $removed ) ) {
							return $removed;
						}
						$counters[ $provider ] = $removed['generation'];
					}
				}
				foreach ( $backup as $provider => $record ) {
					$restored[ $provider ] = self::advance_record_version( $records[ $provider ] ?? null, $record, $counters[ $provider ] ?? 0, true );
					if ( is_wp_error( $restored[ $provider ] ) ) {
						return $restored[ $provider ];
					}
					$counters[ $provider ] = $restored[ $provider ]['generation'];
				}
				$restored[ self::GENERATIONS_KEY ] = $counters;
				return $restored;
			}
		);
		$this->last_save_error = self::WRITE_STORED === $outcome ? '' : 'integration_write_failed';
		return self::WRITE_STORED === $outcome;
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
		$this->last_save_error = '';

		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			$this->last_save_error = 'unknown_provider';
			return false;
		}

		if ( $this->is_multi_field_provider( $provider ) ) {
			$this->last_save_error         = 'invalid_config';
			$this->last_save_error_message = __( 'This provider requires named config fields. Use /integrations/configure to update its configuration.', 'mcpwp' );
			return false;
		}

		$encryption = Mcpwp_Encryption::get_instance();
		$encrypted  = $encryption->encrypt( $key );
		if ( false === $encrypted ) {
			$this->last_save_error = 'encryption_failed';
			return false;
		}

		return $this->save_provider_record(
			$provider,
			array(
				'key_encrypted' => $encrypted,
				'configured_at' => current_time( 'mysql' ),
				'last_tested'   => null,
				'test_status'   => null,
			)
		);
	}

	/**
	 * Merge multi-field config for a provider (e.g. screenshot worker URL + token).
	 *
	 * @param string $provider Provider slug.
	 * @param array      $config   Associative array of field values.
	 * @param array|null $expected Internal snapshot for a conditional completion.
	 * @return bool True on success.
	 */
	public function set_provider_config( $provider, $config, $expected = null ) {
		$this->last_save_error = '';

		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			$this->last_save_error = 'unknown_provider';
			return false;
		}

		$this->last_save_error_message = '';

		$config     = is_array( $config ) ? $config : array();
		$error      = null;
		$encryption = Mcpwp_Encryption::get_instance();
		$outcome    = $this->write_provider_record(
			$provider,
			function ( $record ) use ( $provider, $config, $encryption, &$error ) {
				$stored = array();
				if ( $this->is_multi_field_provider( $provider ) && null !== $record ) {
					$decrypted = is_array( $record ) && ! empty( $record['key_encrypted'] ) ? $encryption->decrypt( $record['key_encrypted'] ) : false;
					$stored    = false !== $decrypted && ! empty( $record['multi_field'] ) ? json_decode( $decrypted, true ) : false;
					if ( ! is_array( $stored ) ) {
						$error = new WP_Error( 'invalid_config', __( 'The stored provider configuration could not be read. Remove it explicitly before configuring it again.', 'mcpwp' ) );
						return $error;
					}
				}

				// Merge inside each compare-and-swap attempt, so a retry keeps
				// concurrent field changes too. Explicit empty values clear fields.
				$merged = $this->validate_provider_config( $provider, array_merge( $stored, $config ) );
				if ( is_wp_error( $merged ) ) {
					$error = new WP_Error( 'invalid_config', $merged->get_error_message() );
					return $error;
				}

				foreach ( self::PROVIDERS[ $provider ]['secret_bindings'] ?? array() as $destination => $secrets ) {
					if ( array_key_exists( $destination, $config ) && $this->binding_destination( $provider, $destination, $stored ) !== $this->binding_destination( $provider, $destination, $merged ) ) {
						foreach ( $secrets as $secret ) {
							if ( ! array_key_exists( $secret, $config ) ) {
								unset( $merged[ $secret ] );
							}
						}
					}
				}

				$encrypted = $encryption->encrypt( wp_json_encode( $merged ) );
				if ( false === $encrypted ) {
					$error = new WP_Error( 'encryption_failed' );
					return $error;
				}

				return array(
					'key_encrypted' => $encrypted,
					'configured_at' => current_time( 'mysql' ),
					'last_tested'   => null,
					'test_status'   => null,
					'multi_field'   => true,
				);
			},
			$expected
		);

		if ( self::WRITE_STORED !== $outcome ) {
			$this->last_save_error         = $error ? $error->get_error_code() : ( 'generation_conflict' === $this->last_save_error ? 'generation_conflict' : 'integration_write_failed' );
			$this->last_save_error_message = $error ? $error->get_error_message() : '';
			return false;
		}

		if ( class_exists( 'Mcpwp_Encryption_Migration' ) ) {
			Mcpwp_Encryption_Migration::clear_provider_failure( $provider );
		}
		return true;
	}

	/** Compare the effective destination using its provider-declared send rules. */
	private function binding_destination( $provider, $field, array $config ) {
		$value = (string) ( $config[ $field ] ?? '' );
		$rules = self::PROVIDERS[ $provider ]['destination_rules'][ $field ] ?? array();
		if ( ! empty( $rules['trim'] ) ) {
			$value = trim( $value );
		}
		if ( '' === $value || ( ! empty( $rules['empty_default'] ) && empty( $value ) ) ) {
			$value = isset( $rules['default_constant'] ) && defined( $rules['default_constant'] )
				? constant( $rules['default_constant'] ) : ( $rules['default'] ?? '' );
		}
		return ! empty( $rules['rtrim_slash'] ) ? rtrim( $value, '/' ) : $value;
	}

	/**
	 * Validate a multi-field config before it is stored — one chokepoint for
	 * the REST, MCP and wp-admin save paths.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $config   Submitted config.
	 * @return array|WP_Error Config to store, or why it was refused.
	 */
	private function validate_provider_config( $provider, array $config ) {
		if ( 'jev' === $provider && class_exists( 'Mcpwp_Jev' ) ) {
			return Mcpwp_Jev::validate_config( $config );
		}
		return $config;
	}

	/**
	 * Human-readable reason for the last 'invalid_config' save failure.
	 *
	 * @return string
	 */
	public function get_last_save_error_message() {
		return $this->last_save_error_message;
	}

	/**
	 * Get multi-field config for a provider.
	 *
	 * @param string $provider Provider slug.
	 * @return array|false Config array or false.
	 */
	public function get_provider_config( $provider ) {
		$snapshot = $this->get_provider_snapshot( $provider );
		return false === $snapshot ? false : $snapshot['config'];
	}

	/**
	 * Read config and its version from the same record, before external work.
	 * Legacy records start at generation zero and acquire an incarnation on write.
	 *
	 * @param string $provider Provider slug.
	 * @return array|false Internal snapshot; never return it through an API.
	 */
	public function get_provider_snapshot( $provider ) {
		$data = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $data ) || empty( $data[ $provider ]['key_encrypted'] ) ) {
			return false;
		}
		$record    = $data[ $provider ];
		$decrypted = Mcpwp_Encryption::get_instance()->decrypt( $record['key_encrypted'] );
		if ( false === $decrypted ) {
			return false;
		}
		$config = ! empty( $record['multi_field'] ) ? json_decode( $decrypted, true ) : array( 'key' => $decrypted );
		if ( ! is_array( $config ) ) {
			return false;
		}
		return array(
			'config'     => $config,
			'generation' => isset( $record['generation'] ) ? (int) $record['generation'] : 0,
			'record_id'  => isset( $record['record_id'] ) ? $record['record_id'] : '',
		);
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
		return self::WRITE_STORED === $this->remove_provider_record( $provider );
	}

	/**
	 * Remove a provider's record and say what happened (#845 review).
	 *
	 * A caller must tell "removed", "there was nothing to remove" and "the
	 * write was lost, the key is still stored" apart: the last one must never
	 * be reported as a removal.
	 *
	 * @param string $provider Provider slug.
	 * @return string WRITE_STORED (removed), WRITE_UNCHANGED (nothing was
	 *                stored for it) or WRITE_FAILED (it is still stored).
	 */
	public function remove_provider_record( $provider ) {
		$outcome = $this->write_provider_record(
			$provider,
			static function () {
				return null;
			}
		);
		if ( self::WRITE_STORED === $outcome && class_exists( 'Mcpwp_Encryption_Migration' ) ) {
			Mcpwp_Encryption_Migration::clear_provider_failure( $provider );
		}
		return $outcome;
	}

	/**
	 * Why the last set_provider_key() / set_provider_config() returned false.
	 *
	 * @return string 'unknown_provider', 'encryption_failed',
	 *                'integration_write_failed', or '' after a save that succeeded.
	 */
	public function get_last_save_error() {
		return $this->last_save_error;
	}

	/**
	 * Replace or remove one provider's record, and nothing else (#845).
	 *
	 * This used to read the whole providers array, change one entry and write
	 * the whole array back with update_option(). Two saves of different
	 * providers that overlapped — or a save overlapping the encryption
	 * migration's own compare-and-swap — kept only the last writer's copy, so
	 * one provider's key was dropped while both calls reported success.
	 *
	 * A provider whose key was just re-entered or removed no longer needs
	 * re-entering, so its encryption-migration failure is cleared — only when
	 * the write succeeded (#564 P3-8).
	 *
	 * @param string     $provider Provider slug.
	 * @param array|null $record   The provider's new record, or null to remove it.
	 * @return bool True when the change is stored. False when the write could
	 *              not be made, or there was no such provider to remove.
	 */
	private function save_provider_record( $provider, $record ) {
		$saved = self::WRITE_STORED === $this->write_provider_record(
			$provider,
			static function () use ( $record ) {
				return $record;
			}
		);

		if ( $saved && class_exists( 'Mcpwp_Encryption_Migration' ) ) {
			Mcpwp_Encryption_Migration::clear_provider_failure( $provider );
		}

		$this->last_save_error = $saved ? '' : 'integration_write_failed';

		return $saved;
	}

	/**
	 * The single provider-write chokepoint (configure, key, remove, OAuth, status).
	 *
	 * The options-row CAS is the optimistic lock: version checks and mutation
	 * are evaluated against the same row on every attempt. Any successful write
	 * to this provider invalidates older OAuth work; unrelated providers may retry.
	 * A new incarnation prevents remove/recreate from matching an old generation.
	 *
	 * @param string     $provider Provider slug.
	 * @param callable   $change   Current record -> record, null removes, false skips.
	 * @param array|null $expected Snapshot version; null for an operator write.
	 * @return string WRITE_STORED, WRITE_UNCHANGED or WRITE_FAILED.
	 */
	private function write_provider_record( $provider, callable $change, $expected = null ) {
		$conflict = false;
		$outcome = $this->write_records(
			function ( array $records ) use ( $provider, $change, $expected, &$conflict ) {
				$counters   = isset( $records[ self::GENERATIONS_KEY ] ) && is_array( $records[ self::GENERATIONS_KEY ] ) ? $records[ self::GENERATIONS_KEY ] : array();
				$current    = isset( $records[ $provider ] ) ? $records[ $provider ] : null;
				$generation = is_array( $current ) && isset( $current['generation'] ) ? (int) $current['generation'] : 0;
				$record_id  = is_array( $current ) && isset( $current['record_id'] ) ? $current['record_id'] : '';
				if ( null !== $expected && ( null === $current || $generation !== $expected['generation'] || $record_id !== $expected['record_id'] ) ) {
					$conflict = true;
					return new WP_Error( 'generation_conflict' );
				}

				$next = call_user_func( $change, $current );
				if ( is_wp_error( $next ) ) {
					return $next;
				}
				if ( false === $next ) {
					return null;
				}
				if ( null === $next ) {
					if ( null === $current ) {
						return null;
					}
					unset( $records[ $provider ] );
					$removed = self::advance_record_version( $current, array(), $counters[ $provider ] ?? 0 );
					if ( is_wp_error( $removed ) ) {
						return $removed;
					}
					$counters[ $provider ] = $removed['generation'];
				} else {
					$next = self::advance_record_version( $current, $next, $counters[ $provider ] ?? 0 );
					if ( is_wp_error( $next ) ) {
						return $next;
					}
					$counters[ $provider ] = $next['generation'];
					$records[ $provider ] = $next;
				}
				$records[ self::GENERATIONS_KEY ] = $counters;
				return $records;
			}
		);

		$this->last_save_error = $conflict ? 'generation_conflict' : ( self::WRITE_FAILED === $outcome ? 'integration_write_failed' : '' );
		return $outcome;
	}

	/**
	 * Record a connection test's outcome on the provider's record.
	 *
	 * Only the two status fields and record version change. A provider with no record — removed
	 * while its test ran, or never stored — has nothing to record, and a stale
	 * copy must not bring it back.
	 *
	 * @param string $provider Provider slug.
	 * @param bool   $success  Whether the test passed.
	 * @return bool|null True when recorded, false when the write could not be
	 *                   made, null when there was no record to update.
	 */
	private function record_test_status( $provider, $success ) {
		$tested_at = current_time( 'mysql' );

		$outcome = $this->write_provider_record(
			$provider,
			static function ( $record ) use ( $success, $tested_at ) {
				if ( ! is_array( $record ) ) {
					return false;
				}
				$record['last_tested'] = $tested_at;
				$record['test_status'] = $success ? 'ok' : 'failed';
				return $record;
			}
		);

		return self::WRITE_UNCHANGED === $outcome ? null : self::WRITE_STORED === $outcome;
	}

	/**
	 * Attach whether a test's status was recorded to the test's result.
	 *
	 * The connection test and the write that records it are separate
	 * outcomes: a test that ran is never reported as recorded when the write
	 * was lost.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $result   Test result.
	 * @return array
	 */
	private function with_recorded_test_status( $provider, array $result ) {
		$recorded = $this->record_test_status( $provider, ! empty( $result['success'] ) );

		if ( null !== $recorded ) {
			$result['test_status_saved'] = $recorded;
		}

		// Said, not only flagged: every caller returns this result, and a lost
		// status write left out of it reads as recorded (#845 review).
		if ( false === $recorded ) {
			$result['status_error'] = array(
				'code'    => 'integration_status_not_saved',
				'message' => __( 'The connection test ran, but its result could not be recorded: the stored integrations changed or could not be read while saving. The status shown in Integrations is out of date.', 'mcpwp' ),
			);
		}

		return $result;
	}

	/**
	 * Apply one change to the provider records and store it only if no other
	 * writer changed them in between.
	 *
	 * The records are read straight from their row, the change is applied to
	 * that copy, and the write is a compare-and-swap against the exact value
	 * read — the pattern the encryption migration uses. When the row changed,
	 * the records are read again and the change re-applied, a bounded number
	 * of times. When the row does not exist yet, it is created by a plain
	 * INSERT, so two first saves cannot overwrite each other either.
	 *
	 * @param callable $change Receives the current records; returns the new
	 *                         records, null when there is nothing to write, or
	 *                         WP_Error to abort.
	 * @return string WRITE_STORED, WRITE_UNCHANGED or WRITE_FAILED.
	 */
	private function write_records( callable $change ) {
		for ( $attempt = 0; $attempt < self::WRITE_ATTEMPTS; $attempt++ ) {
			$row = Mcpwp_Encryption::read_option_row( self::OPTION_NAME );

			// A failed read is never an empty store: building on it would
			// replace every provider with the one being saved.
			if ( Mcpwp_Encryption::ROW_ERROR === $row['state'] ) {
				return self::WRITE_FAILED;
			}

			$present = Mcpwp_Encryption::ROW_PRESENT === $row['state'];
			$records = $present ? maybe_unserialize( $row['value'] ) : array();
			$next    = call_user_func( $change, is_array( $records ) ? $records : array() );

			if ( is_wp_error( $next ) ) {
				return self::WRITE_FAILED;
			}

			if ( null === $next ) {
				return self::WRITE_UNCHANGED;
			}

			$value = maybe_serialize( $next );

			if ( $present && $value === $row['value'] ) {
				// An UPDATE that changes nothing matches no row, which would
				// read as a conflict; the value is already what was asked for.
				return self::WRITE_STORED;
			}

			$written = $present
				? $this->compare_and_swap_records( $row['value'], $value )
				: Mcpwp_Encryption::insert_option_row_if_absent( self::OPTION_NAME, $value, 'yes' );

			if ( $written ) {
				self::forget_cached_records();

				// Stored means the row holds these bytes (#845 review). A value
				// the column could not hold exactly, or a writer landing right
				// after this one, is read again rather than reported as stored.
				if ( $this->row_holds( $value ) ) {
					return self::WRITE_STORED;
				}
			}

			// The row changed, or was created, after it was read: read again.
		}

		return self::WRITE_FAILED;
	}

	/**
	 * Replace the records only if the row still holds $expected_raw.
	 *
	 * @param string $expected_raw The stored value the change was built on.
	 * @param string $value        The serialized new records.
	 * @return bool True when exactly this row was updated.
	 */
	private function compare_and_swap_records( $expected_raw, $value ) {
		// Byte-exact (#845 review): a plain option_value = %s compares with
		// the column's case-insensitive, pad-space collation.
		$swapped = Mcpwp_Encryption::compare_and_swap_option_row( self::OPTION_NAME, $expected_raw, $value );

		// A miss can still have raced a write that landed; drop the caches.
		self::forget_cached_records();

		return $swapped;
	}

	/**
	 * Whether the records row holds exactly $value, read past every cache.
	 *
	 * @param string $value Serialized records.
	 * @return bool
	 */
	private function row_holds( $value ) {
		$row = Mcpwp_Encryption::read_option_row( self::OPTION_NAME );

		return Mcpwp_Encryption::ROW_PRESENT === $row['state'] && $value === $row['value'];
	}

	/**
	 * Drop the cached copies of the records after a write behind the options
	 * API (the option is autoloaded, so the alloptions copy too).
	 */
	private static function forget_cached_records() {
		Mcpwp_Encryption::clean_option_cache( self::OPTION_NAME );

		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
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

		if ( 'jev' === $provider ) {
			return $this->test_jev_provider();
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

		return $this->with_recorded_test_status( $provider, $result );
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

		return $this->with_recorded_test_status( 'screenshot', $result );
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

		return $this->with_recorded_test_status( 'google_indexing', $result );
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

		return $this->with_recorded_test_status( 'figma', $result );
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

		return $this->with_recorded_test_status( 'cloudflare', $result );
	}

	/**
	 * Test the Jev connection — whichever transport (TypeSafe or Cloudflare
	 * Workers AI) resolves for this site — and say which one answered.
	 *
	 * @return array{success: bool, message: string}
	 */
	private function test_jev_provider() {
		if ( ! class_exists( 'Mcpwp_Jev' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Jev support is not available in this build.', 'mcpwp' ),
			);
		}

		$result = Mcpwp_Jev::test_connection();

		return $this->with_recorded_test_status(
			'jev',
			array(
				'success'   => (bool) $result['success'],
				'message'   => (string) $result['message'],
				'transport' => (string) $result['transport'],
			)
		);
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

			// Jev can run with no 'jev' record at all (Cloudflare Workers AI
			// credentials alone), so 'configured' above cannot say whether it
			// is on. Say which transport is active and why (#987).
			if ( 'jev' === $slug && class_exists( 'Mcpwp_Jev' ) ) {
				$jev_status                        = Mcpwp_Jev::status();
				$provider_data['available']        = '' !== $jev_status['transport'];
				$provider_data['transport']        = $jev_status['transport'];
				$provider_data['transport_reason'] = $jev_status['reason'];
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
