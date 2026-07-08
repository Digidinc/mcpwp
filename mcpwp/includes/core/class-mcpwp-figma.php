<?php
/**
 * Figma Design Context Client
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight client for Figma design intake.
 */
class Mcpwp_Figma {

	/**
	 * Figma OAuth authorize URL.
	 *
	 * @var string
	 */
	private $oauth_authorize_url = 'https://www.figma.com/oauth';

	/**
	 * Figma OAuth token URL.
	 *
	 * @var string
	 */
	private $oauth_token_url = 'https://api.figma.com/v1/oauth/token';

	/**
	 * Figma REST API base URL.
	 *
	 * @var string
	 */
	private $api_base = 'https://api.figma.com/v1';

	/**
	 * Integration manager.
	 *
	 * @var Mcpwp_Integration_Manager
	 */
	private $manager;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->manager = Mcpwp_Integration_Manager::get_instance();
	}

	/**
	 * Get Figma integration status.
	 *
	 * @return array
	 */
	public function get_status() {
		$config = $this->get_raw_config();
		$access = $this->get_access_token();

		return array(
			'configured'       => ! is_wp_error( $access ),
			'auth_mode'        => ! empty( $config['personal_access_token'] ) ? 'personal_token' : ( ! empty( $config['access_token'] ) || ! empty( $config['refresh_token'] ) ? 'oauth' : 'unconfigured' ),
			'oauth_ready'      => ! empty( $config['oauth_client_id'] ) && ! empty( $config['oauth_client_secret'] ),
			'oauth_connected'  => ! empty( $config['access_token'] ) || ! empty( $config['refresh_token'] ),
			'default_file_key' => is_array( $config ) && ! empty( $config['default_file_key'] ) ? (string) $config['default_file_key'] : '',
			'redirect_uri'     => $this->get_oauth_redirect_uri(),
			'api_base'         => $this->api_base,
		);
	}

	/**
	 * Test the Figma connection.
	 *
	 * @return array{success: bool, message: string}
	 */
	public function test_connection() {
		$config = $this->get_raw_config();
		$access = $this->get_access_token();
		if ( is_wp_error( $access ) ) {
			return array(
				'success' => false,
				'message' => $access->get_error_message(),
			);
		}

		$mode_label = ! empty( $config['personal_access_token'] ) ? __( 'personal token', 'mumcp' ) : __( 'OAuth', 'mumcp' );
		$me = $this->request( '/me' );
		if ( ! is_wp_error( $me ) ) {
			$handle = isset( $me['handle'] ) ? (string) $me['handle'] : __( 'authenticated user', 'mumcp' );
			return array(
				'success' => true,
				/* translators: 1: Figma handle 2: auth mode */
				'message' => sprintf( __( 'Connected to Figma as %1$s using %2$s.', 'mumcp' ), $handle, $mode_label ),
			);
		}

		if ( is_wp_error( $access ) ) {
			return array(
				'success' => false,
				'message' => $access->get_error_message(),
			);
		}

		if ( ! empty( $config['default_file_key'] ) ) {
			$file = $this->request(
				'/files/' . rawurlencode( $config['default_file_key'] ),
				array( 'depth' => 1 )
			);

			if ( ! is_wp_error( $file ) ) {
				$title = isset( $file['name'] ) ? (string) $file['name'] : $config['default_file_key'];
				return array(
					'success' => true,
					/* translators: %s: Figma file title */
					'message' => sprintf( __( 'Connected to Figma and reached file %s.', 'mumcp' ), $title ),
				);
			}
		}

		return array(
			'success' => false,
			'message' => $me->get_error_message(),
		);
	}

	/**
	 * Get the admin callback URI for Figma OAuth.
	 *
	 * @return string
	 */
	public function get_oauth_redirect_uri() {
		return admin_url( 'admin-post.php?action=mcpwp_figma_oauth_callback' );
	}

	/**
	 * Check whether OAuth app credentials are present.
	 *
	 * @return bool
	 */
	public function is_oauth_ready() {
		$config = $this->get_raw_config();
		return ! empty( $config['oauth_client_id'] ) && ! empty( $config['oauth_client_secret'] );
	}

	/**
	 * Start an OAuth handshake and return the Figma authorize URL.
	 *
	 * @return string|WP_Error
	 */
	public function get_oauth_authorize_url() {
		$config = $this->get_raw_config();
		if ( empty( $config['oauth_client_id'] ) || empty( $config['oauth_client_secret'] ) ) {
			return new WP_Error( 'figma_oauth_not_ready', __( 'Figma OAuth requires both a client ID and client secret.', 'mumcp' ) );
		}

		$state = wp_generate_password( 32, false, false );
		set_transient(
			'mcpwp_figma_oauth_state_' . $state,
			array(
				'created_at' => time(),
			),
			10 * MINUTE_IN_SECONDS
		);

		return add_query_arg(
			array(
				'client_id'     => $config['oauth_client_id'],
				'redirect_uri'  => $this->get_oauth_redirect_uri(),
				'scope'         => 'file_content:read current_user:read',
				'state'         => $state,
				'response_type' => 'code',
			),
			$this->oauth_authorize_url
		);
	}

	/**
	 * Exchange an OAuth code for tokens and persist them.
	 *
	 * @param string $code  Authorization code.
	 * @param string $state State token.
	 * @return array|WP_Error
	 */
	public function exchange_oauth_code( $code, $state ) {
		$config = $this->get_raw_config();
		if ( empty( $config['oauth_client_id'] ) || empty( $config['oauth_client_secret'] ) ) {
			return new WP_Error( 'figma_oauth_not_ready', __( 'Figma OAuth credentials are missing.', 'mumcp' ) );
		}

		$stored_state = get_transient( 'mcpwp_figma_oauth_state_' . $state );
		delete_transient( 'mcpwp_figma_oauth_state_' . $state );
		if ( empty( $stored_state ) ) {
			return new WP_Error( 'figma_invalid_state', __( 'The Figma OAuth state is invalid or expired. Start the connection again.', 'mumcp' ) );
		}

		$response = wp_remote_post(
			$this->oauth_token_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $config['oauth_client_id'] . ':' . $config['oauth_client_secret'] ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => http_build_query(
					array(
						'redirect_uri' => $this->get_oauth_redirect_uri(),
						'code'         => $code,
						'grant_type'   => 'authorization_code',
					),
					'',
					'&'
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_status = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code_status < 200 || $code_status >= 300 || ! is_array( $payload ) ) {
			$message = is_array( $payload ) && ! empty( $payload['error_description'] ) ? (string) $payload['error_description'] : __( 'Figma OAuth token exchange failed.', 'mumcp' );
			return new WP_Error( 'figma_token_exchange_failed', $message, array( 'status' => $code_status ) );
		}

		$config['access_token']  = isset( $payload['access_token'] ) ? (string) $payload['access_token'] : '';
		$config['refresh_token'] = isset( $payload['refresh_token'] ) ? (string) $payload['refresh_token'] : '';
		$config['token_type']    = isset( $payload['token_type'] ) ? (string) $payload['token_type'] : 'Bearer';
		$config['expires_at']    = ! empty( $payload['expires_in'] ) ? gmdate( 'Y-m-d H:i:s', time() + (int) $payload['expires_in'] ) : '';

		if ( ! $this->manager->set_provider_config( 'figma', $config ) ) {
			return new WP_Error( 'figma_save_failed', __( 'Figma OAuth succeeded, but the tokens could not be saved.', 'mumcp' ) );
		}

		return $this->test_connection();
	}

	/**
	 * Get a Figma file summary and outline.
	 *
	 * @param string $file_key Figma file key.
	 * @param int    $depth    Requested depth.
	 * @return array|WP_Error
	 */
	public function get_file( $file_key = '', $depth = 2 ) {
		$file_key = $this->resolve_file_key( $file_key );
		if ( is_wp_error( $file_key ) ) {
			return $file_key;
		}

		$response = $this->request(
			'/files/' . rawurlencode( $file_key ),
			array(
				'depth' => max( 1, min( 4, (int) $depth ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$document = isset( $response['document'] ) && is_array( $response['document'] ) ? $response['document'] : array();
		$children = isset( $document['children'] ) && is_array( $document['children'] ) ? $document['children'] : array();

		return array(
			'file_key'        => $file_key,
			'name'            => isset( $response['name'] ) ? (string) $response['name'] : '',
			'last_modified'   => isset( $response['lastModified'] ) ? (string) $response['lastModified'] : '',
			'thumbnail_url'   => isset( $response['thumbnailUrl'] ) ? (string) $response['thumbnailUrl'] : '',
			'version'         => isset( $response['version'] ) ? (string) $response['version'] : '',
			'role'            => isset( $response['role'] ) ? (string) $response['role'] : '',
			'editor_type'     => isset( $response['editorType'] ) ? (string) $response['editorType'] : '',
			'components'      => isset( $response['components'] ) && is_array( $response['components'] ) ? count( $response['components'] ) : 0,
			'component_sets'  => isset( $response['componentSets'] ) && is_array( $response['componentSets'] ) ? count( $response['componentSets'] ) : 0,
			'styles'          => isset( $response['styles'] ) && is_array( $response['styles'] ) ? count( $response['styles'] ) : 0,
			'canvases'        => $this->summarize_children( $children, 0, 1 ),
			'document_outline' => $this->summarize_node_tree( $document, 0, 2 ),
		);
	}

	/**
	 * Get a specific Figma node/frame summary.
	 *
	 * @param string $file_key Figma file key.
	 * @param string $node_id  Node ID.
	 * @param int    $depth    Requested depth.
	 * @return array|WP_Error
	 */
	public function get_node( $file_key, $node_id, $depth = 2 ) {
		$file_key = $this->resolve_file_key( $file_key );
		if ( is_wp_error( $file_key ) ) {
			return $file_key;
		}

		$node_id = trim( (string) $node_id );
		if ( '' === $node_id ) {
			return new WP_Error( 'missing_node_id', __( 'A Figma node_id is required.', 'mumcp' ) );
		}

		$response = $this->request(
			'/files/' . rawurlencode( $file_key ) . '/nodes',
			array(
				'ids'   => $node_id,
				'depth' => max( 1, min( 4, (int) $depth ) ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$node = isset( $response['nodes'][ $node_id ]['document'] ) && is_array( $response['nodes'][ $node_id ]['document'] )
			? $response['nodes'][ $node_id ]['document']
			: null;

		if ( ! is_array( $node ) ) {
			return new WP_Error( 'node_not_found', __( 'The requested Figma node was not found in the file response.', 'mumcp' ) );
		}

		return array(
			'file_key' => $file_key,
			'node_id'  => $node_id,
			'summary'  => $this->summarize_single_node( $node ),
			'outline'  => $this->summarize_node_tree( $node, 0, 3 ),
		);
	}

	/**
	 * Resolve the effective file key.
	 *
	 * @param string $file_key Requested file key.
	 * @return string|WP_Error
	 */
	private function resolve_file_key( $file_key ) {
		$file_key = trim( (string) $file_key );
		if ( '' !== $file_key ) {
			return $file_key;
		}

		$config = $this->get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( empty( $config['default_file_key'] ) ) {
			return new WP_Error( 'missing_file_key', __( 'No Figma file_key was provided and no default_file_key is configured.', 'mumcp' ) );
		}

		return (string) $config['default_file_key'];
	}

	/**
	 * Get stored config.
	 *
	 * @return array|WP_Error
	 */
	private function get_config() {
		$config = $this->manager->get_provider_config( 'figma' );
		if ( ! is_array( $config ) ) {
			return new WP_Error( 'figma_not_configured', __( 'Figma is not configured. Add a personal token or OAuth app in mumcp → Integrations.', 'mumcp' ) );
		}

		return $config;
	}

	/**
	 * Get raw stored config with array fallback.
	 *
	 * @return array
	 */
	private function get_raw_config() {
		$config = $this->manager->get_provider_config( 'figma' );
		return is_array( $config ) ? $config : array();
	}

	/**
	 * Get a valid access token, refreshing OAuth tokens when needed.
	 *
	 * @return string|WP_Error
	 */
	private function get_access_token() {
		$config = $this->get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		if ( ! empty( $config['personal_access_token'] ) ) {
			return (string) $config['personal_access_token'];
		}

		if ( ! empty( $config['access_token'] ) && ! $this->is_token_expired( $config ) ) {
			return (string) $config['access_token'];
		}

		if ( ! empty( $config['refresh_token'] ) ) {
			$refreshed = $this->refresh_oauth_token( $config );
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return (string) $refreshed['access_token'];
		}

		return new WP_Error( 'figma_not_configured', __( 'Figma is not configured. Add a personal token or complete the OAuth connection in mumcp → Integrations.', 'mumcp' ) );
	}

	/**
	 * Check whether a stored OAuth token is expired.
	 *
	 * @param array $config Figma config.
	 * @return bool
	 */
	private function is_token_expired( $config ) {
		if ( empty( $config['expires_at'] ) ) {
			return false;
		}

		$expires_at = strtotime( (string) $config['expires_at'] );
		if ( false === $expires_at ) {
			return false;
		}

		return $expires_at <= ( time() + 60 );
	}

	/**
	 * Refresh a stored OAuth token.
	 *
	 * @param array $config Figma config.
	 * @return array|WP_Error
	 */
	private function refresh_oauth_token( $config ) {
		if ( empty( $config['oauth_client_id'] ) || empty( $config['oauth_client_secret'] ) || empty( $config['refresh_token'] ) ) {
			return new WP_Error( 'figma_refresh_unavailable', __( 'Figma OAuth refresh is unavailable because credentials or refresh token are missing.', 'mumcp' ) );
		}

		$response = wp_remote_post(
			$this->oauth_token_url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $config['oauth_client_id'] . ':' . $config['oauth_client_secret'] ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => http_build_query(
					array(
						'refresh_token' => $config['refresh_token'],
						'grant_type'    => 'refresh_token',
					),
					'',
					'&'
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_status = (int) wp_remote_retrieve_response_code( $response );
		$payload     = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code_status < 200 || $code_status >= 300 || ! is_array( $payload ) ) {
			$message = is_array( $payload ) && ! empty( $payload['error_description'] ) ? (string) $payload['error_description'] : __( 'Figma OAuth token refresh failed.', 'mumcp' );
			return new WP_Error( 'figma_refresh_failed', $message, array( 'status' => $code_status ) );
		}

		$config['access_token'] = isset( $payload['access_token'] ) ? (string) $payload['access_token'] : '';
		if ( ! empty( $payload['refresh_token'] ) ) {
			$config['refresh_token'] = (string) $payload['refresh_token'];
		}
		$config['token_type'] = isset( $payload['token_type'] ) ? (string) $payload['token_type'] : 'Bearer';
		$config['expires_at'] = ! empty( $payload['expires_in'] ) ? gmdate( 'Y-m-d H:i:s', time() + (int) $payload['expires_in'] ) : '';

		if ( ! $this->manager->set_provider_config( 'figma', $config ) ) {
			return new WP_Error( 'figma_refresh_save_failed', __( 'Figma OAuth token refresh succeeded, but the new token could not be saved.', 'mumcp' ) );
		}

		return $config;
	}

	/**
	 * Perform a Figma API request.
	 *
	 * @param string $path  API path.
	 * @param array  $query Query args.
	 * @return array|WP_Error
	 */
	private function request( $path, $query = array() ) {
		$config = $this->get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$url = trailingslashit( $this->api_base ) . ltrim( $path, '/' );
		if ( ! empty( $query ) ) {
			$url = add_query_arg(
				array_filter(
					$query,
					function ( $value ) {
						return null !== $value && '' !== $value;
					}
				),
				$url
			);
		}

		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['err'] )
				? (string) $data['err']
				/* translators: %d: HTTP response status code */
				: sprintf( __( 'Figma API returned HTTP %d.', 'mumcp' ), $code );
			return new WP_Error( 'figma_request_failed', $message, array( 'status' => $code ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'figma_invalid_response', __( 'Figma returned an invalid JSON response.', 'mumcp' ) );
		}

		return $data;
	}

	/**
	 * Summarize a node subtree for AI consumption.
	 *
	 * @param array $node      Node data.
	 * @param int   $depth     Current depth.
	 * @param int   $max_depth Maximum depth.
	 * @return array
	 */
	private function summarize_node_tree( $node, $depth, $max_depth ) {
		$summary = $this->summarize_single_node( $node );

		if ( $depth >= $max_depth || empty( $node['children'] ) || ! is_array( $node['children'] ) ) {
			return $summary;
		}

		$summary['children'] = $this->summarize_children( $node['children'], $depth + 1, $max_depth );
		return $summary;
	}

	/**
	 * Summarize children nodes.
	 *
	 * @param array $children  Child nodes.
	 * @param int   $depth     Current depth.
	 * @param int   $max_depth Maximum depth.
	 * @return array
	 */
	private function summarize_children( $children, $depth, $max_depth ) {
		$result = array();
		$index  = 0;

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			$result[] = $this->summarize_node_tree( $child, $depth, $max_depth );
			++$index;

			if ( $index >= 12 ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Summarize a single Figma node.
	 *
	 * @param array $node Node data.
	 * @return array
	 */
	private function summarize_single_node( $node ) {
		$summary = array(
			'id'            => isset( $node['id'] ) ? (string) $node['id'] : '',
			'name'          => isset( $node['name'] ) ? (string) $node['name'] : '',
			'type'          => isset( $node['type'] ) ? (string) $node['type'] : '',
			'visible'       => ! isset( $node['visible'] ) || (bool) $node['visible'],
			'children_count' => isset( $node['children'] ) && is_array( $node['children'] ) ? count( $node['children'] ) : 0,
		);

		if ( ! empty( $node['absoluteBoundingBox'] ) && is_array( $node['absoluteBoundingBox'] ) ) {
			$bounds = $node['absoluteBoundingBox'];
			$summary['bounds'] = array(
				'x'      => isset( $bounds['x'] ) ? (float) $bounds['x'] : 0,
				'y'      => isset( $bounds['y'] ) ? (float) $bounds['y'] : 0,
				'width'  => isset( $bounds['width'] ) ? (float) $bounds['width'] : 0,
				'height' => isset( $bounds['height'] ) ? (float) $bounds['height'] : 0,
			);
		}

		if ( isset( $node['layoutMode'] ) && '' !== $node['layoutMode'] ) {
			$summary['layout_mode'] = (string) $node['layoutMode'];
		}

		return $summary;
	}
}
