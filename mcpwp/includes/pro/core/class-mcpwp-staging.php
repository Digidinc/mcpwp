<?php
/**
 * Staging Orchestrator
 *
 * Detects the hosting environment and delegates staging operations to the
 * appropriate adapter: WP Engine, Kinsta, Pressable, or Generic (WP-CLI).
 *
 * @package MCPWP_Pro
 * @since   3.3.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter interface every hosting provider must satisfy.
 */
interface Mcpwp_Staging_Adapter_Interface {

	/**
	 * List environments available on this install.
	 *
	 * @return array<int,array{id:string,name:string,type:string,url:string}>
	 */
	public function list_environments(): array;

	/**
	 * Copy one environment to another.
	 *
	 * @param string $source  Source environment ID.
	 * @param string $dest    Destination environment ID.
	 * @param array  $options Options: include_db (bool), include_files (bool).
	 * @return array{success:bool,operation_id:string|null,message:string}
	 */
	public function push( string $source, string $dest, array $options ): array;

	/**
	 * Poll the status of an asynchronous operation.
	 *
	 * @param string $operation_id Operation ID returned by push().
	 * @return array{status:string,message:string,progress:int|null}
	 */
	public function get_status( string $operation_id ): array;
}

// ---------------------------------------------------------------------------
// WP Engine adapter
// ---------------------------------------------------------------------------

/**
 * Adapter for WP Engine's Installations API v1.
 */
class Mcpwp_Staging_Adapter_WPEngine implements Mcpwp_Staging_Adapter_Interface {

	/** @var string */
	private $install_id;

	/** @var string */
	private $api_token;

	/** @var string */
	const API_BASE = 'https://api.wpengineapi.com/v1';

	/**
	 * Constructor.
	 *
	 * @param string $install_id WP Engine install ID.
	 * @param string $api_token  WP Engine API token (username:password base64 or raw).
	 */
	public function __construct( string $install_id, string $api_token ) {
		$this->install_id = $install_id;
		$this->api_token  = $api_token;
	}

	/** {@inheritdoc} */
	public function list_environments(): array {
		$response = wp_remote_get(
			self::API_BASE . '/installs/' . rawurlencode( $this->install_id ),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Basic ' . $this->api_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return array();
		}

		// WP Engine returns install details; environment list comes from /environments endpoint.
		$envs = array();
		if ( ! empty( $body['environments'] ) && is_array( $body['environments'] ) ) {
			foreach ( $body['environments'] as $env ) {
				$envs[] = array(
					'id'   => isset( $env['id'] ) ? (string) $env['id'] : '',
					'name' => isset( $env['name'] ) ? (string) $env['name'] : '',
					'type' => isset( $env['environment'] ) ? (string) $env['environment'] : 'staging',
					'url'  => isset( $env['cname'] ) ? 'https://' . $env['cname'] : '',
				);
			}
		}

		return $envs;
	}

	/** {@inheritdoc} */
	public function push( string $source, string $dest, array $options ): array {
		$body = array(
			'source_environment_id'      => $source,
			'destination_environment_id' => $dest,
			'include_files'              => ! empty( $options['include_files'] ),
			'include_db'                 => ! empty( $options['include_db'] ),
		);

		$response = wp_remote_post(
			self::API_BASE . '/installs/' . rawurlencode( $this->install_id ) . '/copy',
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Basic ' . $this->api_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'      => false,
				'operation_id' => null,
				'message'      => $response->get_error_message(),
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			return array(
				'success'      => true,
				'operation_id' => isset( $payload['id'] ) ? (string) $payload['id'] : null,
				'message'      => 'WP Engine copy initiated.',
			);
		}

		$message = isset( $payload['message'] ) ? $payload['message'] : "HTTP $code";
		return array(
			'success'      => false,
			'operation_id' => null,
			'message'      => $message,
		);
	}

	/** {@inheritdoc} */
	public function get_status( string $operation_id ): array {
		$response = wp_remote_get(
			self::API_BASE . '/transfers/' . rawurlencode( $operation_id ),
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Basic ' . $this->api_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'unknown', 'message' => $response->get_error_message(), 'progress' => null );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );
		$status  = is_array( $payload ) && isset( $payload['status'] ) ? (string) $payload['status'] : 'unknown';

		return array(
			'status'   => $status,
			'message'  => is_array( $payload ) && isset( $payload['message'] ) ? $payload['message'] : '',
			'progress' => null,
		);
	}
}

// ---------------------------------------------------------------------------
// Kinsta adapter
// ---------------------------------------------------------------------------

/**
 * Adapter for Kinsta's Environments API v2.
 */
class Mcpwp_Staging_Adapter_Kinsta implements Mcpwp_Staging_Adapter_Interface {

	/** @var string */
	private $site_id;

	/** @var string */
	private $api_key;

	/** @var string */
	const API_BASE = 'https://api.kinsta.com/v2';

	/**
	 * Constructor.
	 *
	 * @param string $site_id Kinsta site ID.
	 * @param string $api_key Kinsta API key.
	 */
	public function __construct( string $site_id, string $api_key ) {
		$this->site_id = $site_id;
		$this->api_key = $api_key;
	}

	/** {@inheritdoc} */
	public function list_environments(): array {
		$response = wp_remote_get(
			self::API_BASE . '/sites/' . rawurlencode( $this->site_id ) . '/environments',
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['site']['environments'] ) ) {
			return array();
		}

		$envs = array();
		foreach ( $body['site']['environments'] as $env ) {
			$envs[] = array(
				'id'   => isset( $env['id'] ) ? (string) $env['id'] : '',
				'name' => isset( $env['display_name'] ) ? (string) $env['display_name'] : '',
				'type' => isset( $env['name'] ) && 'live' === $env['name'] ? 'production' : 'staging',
				'url'  => isset( $env['domains'][0]['name'] ) ? 'https://' . $env['domains'][0]['name'] : '',
			);
		}

		return $envs;
	}

	/** {@inheritdoc} */
	public function push( string $source, string $dest, array $options ): array {
		$body = array(
			'source_env_id' => $source,
			'target_env_id' => $dest,
			'push_db'       => ! empty( $options['include_db'] ),
			'push_files'    => ! empty( $options['include_files'] ),
		);

		$response = wp_remote_request(
			self::API_BASE . '/sites/' . rawurlencode( $this->site_id ) . '/environments',
			array(
				'method'      => 'PUT',
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'      => false,
				'operation_id' => null,
				'message'      => $response->get_error_message(),
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		// Kinsta returns 202 Accepted for async operations.
		if ( 202 === $code || ( $code >= 200 && $code < 300 ) ) {
			return array(
				'success'      => true,
				'operation_id' => isset( $payload['operation_id'] ) ? (string) $payload['operation_id'] : null,
				'message'      => 'Kinsta environment push initiated.',
			);
		}

		$message = isset( $payload['message'] ) ? $payload['message'] : "HTTP $code";
		return array(
			'success'      => false,
			'operation_id' => null,
			'message'      => $message,
		);
	}

	/** {@inheritdoc} */
	public function get_status( string $operation_id ): array {
		$response = wp_remote_get(
			self::API_BASE . '/operations/' . rawurlencode( $operation_id ),
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'status' => 'unknown', 'message' => $response->get_error_message(), 'progress' => null );
		}

		$payload  = json_decode( wp_remote_retrieve_body( $response ), true );
		$status   = is_array( $payload ) && isset( $payload['status'] ) ? (string) $payload['status'] : 'unknown';
		$progress = is_array( $payload ) && isset( $payload['progress'] ) ? (int) $payload['progress'] : null;

		return array(
			'status'   => $status,
			'message'  => is_array( $payload ) && isset( $payload['message'] ) ? $payload['message'] : '',
			'progress' => $progress,
		);
	}
}

// ---------------------------------------------------------------------------
// Pressable adapter
// ---------------------------------------------------------------------------

/**
 * Adapter for Pressable's Site API v1.
 */
class Mcpwp_Staging_Adapter_Pressable implements Mcpwp_Staging_Adapter_Interface {

	/** @var string */
	private $site_id;

	/** @var string */
	private $api_token;

	/** @var string */
	const API_BASE = 'https://my.pressable.com/v1';

	/**
	 * Constructor.
	 *
	 * @param string $site_id   Pressable site ID.
	 * @param string $api_token Pressable OAuth2 bearer token.
	 */
	public function __construct( string $site_id, string $api_token ) {
		$this->site_id   = $site_id;
		$this->api_token = $api_token;
	}

	/** {@inheritdoc} */
	public function list_environments(): array {
		$response = wp_remote_get(
			self::API_BASE . '/sites/' . rawurlencode( $this->site_id ),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->api_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['data'] ) ) {
			return array();
		}

		$site = $body['data'];
		$envs = array();

		// Main site = production.
		$envs[] = array(
			'id'   => (string) $this->site_id,
			'name' => isset( $site['name'] ) ? $site['name'] . ' (live)' : 'Live',
			'type' => 'production',
			'url'  => isset( $site['url'] ) ? (string) $site['url'] : '',
		);

		// Staging clone if present.
		if ( ! empty( $site['staging_site_id'] ) ) {
			$envs[] = array(
				'id'   => (string) $site['staging_site_id'],
				'name' => isset( $site['name'] ) ? $site['name'] . ' (staging)' : 'Staging',
				'type' => 'staging',
				'url'  => isset( $site['staging_url'] ) ? (string) $site['staging_url'] : '',
			);
		}

		return $envs;
	}

	/** {@inheritdoc} */
	public function push( string $source, string $dest, array $options ): array {
		// Pressable does "clone" for staging→live equivalent push.
		$response = wp_remote_post(
			self::API_BASE . '/sites/' . rawurlencode( $source ) . '/clone',
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $this->api_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'        => wp_json_encode(
					array(
						'destination_site_id' => $dest,
						'copy_db'             => ! empty( $options['include_db'] ),
						'copy_files'          => ! empty( $options['include_files'] ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'      => false,
				'operation_id' => null,
				'message'      => $response->get_error_message(),
			);
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 200 && $code < 300 ) {
			$op_id = null;
			if ( is_array( $payload ) && isset( $payload['data']['id'] ) ) {
				$op_id = (string) $payload['data']['id'];
			}
			return array(
				'success'      => true,
				'operation_id' => $op_id,
				'message'      => 'Pressable clone/push initiated.',
			);
		}

		$message = is_array( $payload ) && isset( $payload['message'] ) ? $payload['message'] : "HTTP $code";
		return array(
			'success'      => false,
			'operation_id' => null,
			'message'      => $message,
		);
	}

	/** {@inheritdoc} */
	public function get_status( string $operation_id ): array {
		// Pressable does not have a generic operations endpoint; check site state.
		return array(
			'status'   => 'unknown',
			'message'  => 'Pressable does not expose an operation status endpoint. Check your Pressable dashboard.',
			'progress' => null,
		);
	}
}

// ---------------------------------------------------------------------------
// Generic (WP-CLI) adapter
// ---------------------------------------------------------------------------

/**
 * Fallback adapter using WP-CLI shell commands for hosts without a REST API.
 */
class Mcpwp_Staging_Adapter_Generic implements Mcpwp_Staging_Adapter_Interface {

	/**
	 * WP-CLI binary path.
	 *
	 * @var string
	 */
	private $wp_cli = '/usr/local/bin/wp';

	/**
	 * Allowed WP-CLI binary paths (allowlist for shell exec).
	 *
	 * @var string[]
	 */
	private const ALLOWED_WP_CLI_PATHS = array(
		'/usr/local/bin/wp',
		'/usr/bin/wp',
		'/usr/local/wp-cli/wp',
	);

	/**
	 * Resolve and validate the WP-CLI binary path.
	 *
	 * @return string|false Validated path or false.
	 */
	private function resolve_wp_cli() {
		foreach ( self::ALLOWED_WP_CLI_PATHS as $candidate ) {
			if ( file_exists( $candidate ) && is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		return false;
	}

	/**
	 * Check whether shell_exec is available and not disabled.
	 *
	 * @return bool
	 */
	public function is_shell_exec_available(): bool {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) );
		if ( in_array( 'shell_exec', $disabled, true ) ) {
			return false;
		}

		return true;
	}

	/** {@inheritdoc} */
	public function list_environments(): array {
		// Generic host has no staging concept — return only the current environment.
		return array(
			array(
				'id'   => 'current',
				'name' => 'Current environment',
				'type' => 'production',
				'url'  => get_site_url(),
			),
		);
	}

	/** {@inheritdoc} */
	public function push( string $source, string $dest, array $options ): array {
		if ( ! $this->is_shell_exec_available() ) {
			return array(
				'success'      => false,
				'operation_id' => null,
				'message'      => 'shell_exec is disabled on this server. Generic (WP-CLI) staging push is not available. Configure a supported host integration (WP Engine, Kinsta, or Pressable) via mumcp > Integrations.',
			);
		}

		$wp_cli = $this->resolve_wp_cli();
		if ( ! $wp_cli ) {
			return array(
				'success'      => false,
				'operation_id' => null,
				'message'      => 'WP-CLI binary not found at any known path. Install WP-CLI or configure a host integration.',
			);
		}

		// Build an export + search-replace sequence.  The caller is expected to
		// define source/dest as filesystem paths or identifiers, but on generic
		// hosts there is typically only one env, so this is a local DB snapshot.
		$abspath       = escapeshellarg( rtrim( ABSPATH, '/' ) );
		$export_file   = escapeshellarg( sys_get_temp_dir() . '/mcpwp-staging-' . time() . '.sql' );
		$wp_cli_arg    = escapeshellarg( $wp_cli );
		$allow_root    = '--allow-root';

		$cmd = "$wp_cli_arg db export $export_file --path=$abspath $allow_root 2>&1";
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$out = shell_exec( $cmd );

		return array(
			'success'      => ( false !== strpos( (string) $out, 'Success' ) ),
			'operation_id' => null,
			'message'      => 'WP-CLI db export output: ' . wp_strip_all_tags( (string) $out ),
		);
	}

	/** {@inheritdoc} */
	public function get_status( string $operation_id ): array {
		return array(
			'status'   => 'unknown',
			'message'  => 'Generic adapter does not support async operation tracking.',
			'progress' => null,
		);
	}
}

// ---------------------------------------------------------------------------
// Orchestrator
// ---------------------------------------------------------------------------

/**
 * Staging orchestrator.
 *
 * Detects the hosting provider and routes all staging operations to the
 * correct adapter.
 */
class Mcpwp_Staging {

	/**
	 * Known host slugs.
	 */
	const HOST_WPENGINE   = 'wpengine';
	const HOST_KINSTA     = 'kinsta';
	const HOST_PRESSABLE  = 'pressable';
	const HOST_GENERIC    = 'generic';

	/**
	 * Detect the current hosting environment.
	 *
	 * Checks constants and server variables that each host injects at runtime.
	 *
	 * @return string One of the HOST_* constants.
	 */
	public function detect_host(): string {
		// WP Engine sets a constant or a server var.
		if ( defined( 'WPE_APIKEY' ) || defined( 'WP_ENGINE_ENVIRONMENT' ) || ! empty( $_SERVER['IS_WPE'] ) ) {
			return self::HOST_WPENGINE;
		}

		// Kinsta sets KINSTA_CACHE_ZONE or a DB host containing 'kinsta'.
		if ( defined( 'KINSTA_CACHE_ZONE' ) || ( defined( 'DB_HOST' ) && false !== strpos( DB_HOST, 'kinsta' ) ) ) {
			return self::HOST_KINSTA;
		}

		// Pressable sets IS_PRESSABLE or a recognisable DB host.
		if ( defined( 'IS_PRESSABLE' ) || ( defined( 'PRESSABLE_DB_HOST' ) ) || ! empty( $_SERVER['IS_PRESSABLE'] ) ) {
			return self::HOST_PRESSABLE;
		}

		return self::HOST_GENERIC;
	}

	/**
	 * Build the adapter for a given host slug.
	 *
	 * Config is loaded from Integration Manager using the provider slug.
	 *
	 * @param string $host Host slug.
	 * @return Mcpwp_Staging_Adapter_Interface|WP_Error Adapter instance or error.
	 */
	public function get_provider( string $host ) {
		$manager = Mcpwp_Integration_Manager::get_instance();

		switch ( $host ) {
			case self::HOST_WPENGINE:
				$config = $manager->get_provider_config( 'wpengine_staging' );
				if ( ! $config || empty( $config['install_id'] ) || empty( $config['api_token'] ) ) {
					return new WP_Error(
						'staging_not_configured',
						'WP Engine staging integration is not configured. Add install_id and api_token via mumcp > Integrations > WP Engine Staging.',
						array( 'status' => 400 )
					);
				}
				return new Mcpwp_Staging_Adapter_WPEngine( $config['install_id'], $config['api_token'] );

			case self::HOST_KINSTA:
				$config = $manager->get_provider_config( 'kinsta_staging' );
				if ( ! $config || empty( $config['site_id'] ) || empty( $config['api_key'] ) ) {
					return new WP_Error(
						'staging_not_configured',
						'Kinsta staging integration is not configured. Add site_id and api_key via mumcp > Integrations > Kinsta Staging.',
						array( 'status' => 400 )
					);
				}
				return new Mcpwp_Staging_Adapter_Kinsta( $config['site_id'], $config['api_key'] );

			case self::HOST_PRESSABLE:
				$config = $manager->get_provider_config( 'pressable_staging' );
				if ( ! $config || empty( $config['site_id'] ) || empty( $config['api_token'] ) ) {
					return new WP_Error(
						'staging_not_configured',
						'Pressable staging integration is not configured. Add site_id and api_token via mumcp > Integrations > Pressable Staging.',
						array( 'status' => 400 )
					);
				}
				return new Mcpwp_Staging_Adapter_Pressable( $config['site_id'], $config['api_token'] );

			default:
				return new Mcpwp_Staging_Adapter_Generic();
		}
	}

	/**
	 * List all environments on this site.
	 *
	 * @return array<int,array{id:string,name:string,type:string,url:string}>|WP_Error
	 */
	public function list_environments() {
		$host    = $this->detect_host();
		$adapter = $this->get_provider( $host );

		if ( is_wp_error( $adapter ) ) {
			// For generic host, still return the single-env list.
			if ( self::HOST_GENERIC === $host ) {
				$generic = new Mcpwp_Staging_Adapter_Generic();
				return $generic->list_environments();
			}
			return $adapter;
		}

		return $adapter->list_environments();
	}

	/**
	 * Validate that source and destination IDs belong to this site's env list.
	 *
	 * @param array  $environments List from list_environments().
	 * @param string $source       Source env ID.
	 * @param string $dest         Destination env ID.
	 * @return true|WP_Error
	 */
	public function validate_env_ids( array $environments, string $source, string $dest ) {
		$valid_ids = array_column( $environments, 'id' );

		if ( ! in_array( $source, $valid_ids, true ) ) {
			return new WP_Error(
				'invalid_source_env',
				sprintf( 'Source environment "%s" is not in the authenticated site\'s environment list.', esc_html( $source ) ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $dest, $valid_ids, true ) ) {
			return new WP_Error(
				'invalid_dest_env',
				sprintf( 'Destination environment "%s" is not in the authenticated site\'s environment list.', esc_html( $dest ) ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Determine whether a given environment ID is of production type.
	 *
	 * @param array  $environments Environment list from list_environments().
	 * @param string $env_id       Environment ID to check.
	 * @return bool
	 */
	public function is_production_env( array $environments, string $env_id ): bool {
		foreach ( $environments as $env ) {
			if ( isset( $env['id'] ) && $env['id'] === $env_id ) {
				return isset( $env['type'] ) && 'production' === $env['type'];
			}
		}
		return false;
	}

	/**
	 * Execute a push between two environments.
	 *
	 * Callers are responsible for obtaining approval before calling this with a
	 * production destination.
	 *
	 * @param string $source  Source environment ID.
	 * @param string $dest    Destination environment ID.
	 * @param array  $options include_db (bool), include_files (bool).
	 * @return array|WP_Error Result or error.
	 */
	public function push( string $source, string $dest, array $options ) {
		$host    = $this->detect_host();
		$adapter = $this->get_provider( $host );

		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		return $adapter->push( $source, $dest, $options );
	}

	/**
	 * Get push operation status.
	 *
	 * @param string $operation_id Operation ID from push().
	 * @return array|WP_Error
	 */
	public function get_operation_status( string $operation_id ) {
		$host    = $this->detect_host();
		$adapter = $this->get_provider( $host );

		if ( is_wp_error( $adapter ) ) {
			return $adapter;
		}

		return $adapter->get_status( $operation_id );
	}

	/**
	 * Build a lightweight snapshot of the current live environment.
	 *
	 * Used as the "before" state in approval requests.
	 *
	 * @return array{page_count:int,post_count:int,plugin_list:string[],active_theme:string,timestamp:string}
	 */
	public function snapshot_live_state(): array {
		return array(
			'page_count'   => (int) wp_count_posts( 'page' )->publish,
			'post_count'   => (int) wp_count_posts( 'post' )->publish,
			'plugin_list'  => array_keys( get_plugins() ),
			'active_theme' => get_stylesheet(),
			'timestamp'    => gmdate( 'c' ),
		);
	}
}
