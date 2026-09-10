<?php
/**
 * Figma REST API Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes read-only Figma design intake routes.
 *
 * Figma design intake is a Pro capability (docs/FREE_PRO_SPLIT.md). Every
 * route answers 403 pro_required on Free, on every dispatch path (#564).
 */
class Mcpwp_REST_Figma extends Mcpwp_REST_API {

	/**
	 * Figma client.
	 *
	 * @var Mcpwp_Figma
	 */
	private $figma;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Figma|null $figma Figma client.
	 */
	public function __construct( $figma = null ) {
		$this->figma = $figma instanceof Mcpwp_Figma ? $figma : new Mcpwp_Figma();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/figma/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_pro_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/figma/file',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_file' ),
				'permission_callback' => array( $this, 'check_pro_permission' ),
				'args'                => array(
					'file_key' => array(
						'type'        => 'string',
						'description' => __( 'Figma file key. Optional if a default file key is configured.', 'mcpwp' ),
					),
					'depth'    => array(
						'type'        => 'integer',
						'description' => __( 'Outline depth to request from Figma.', 'mcpwp' ),
						'default'     => 2,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/figma/node',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_node' ),
				'permission_callback' => array( $this, 'check_pro_permission' ),
				'args'                => array(
					'file_key' => array(
						'type'        => 'string',
						'description' => __( 'Figma file key. Optional if a default file key is configured.', 'mcpwp' ),
					),
					'node_id'  => array(
						'type'        => 'string',
						'description' => __( 'Figma node ID to fetch, such as 12:34.', 'mcpwp' ),
						'required'    => true,
					),
					'depth'    => array(
						'type'        => 'integer',
						'description' => __( 'Subtree depth to request from Figma.', 'mcpwp' ),
						'default'     => 2,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/figma/design-tokens',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_design_tokens' ),
				'permission_callback' => array( $this, 'check_pro_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/figma/design-tokens/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'sync_design_tokens' ),
				'permission_callback' => array( $this, 'check_pro_permission' ),
				'args'                => array(
					'file_key' => array(
						'type'        => 'string',
						'description' => __( 'Figma file key. Optional if a default file key is configured.', 'mcpwp' ),
					),
				),
			)
		);
	}

	/**
	 * Get Figma integration status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_status( $request ) {
		$result = $this->figma->get_status();
		$this->log_activity( 'figma_status', $request, $result );
		return $this->success_response( $result );
	}

	/**
	 * Get file summary.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_file( $request ) {
		$result = $this->figma->get_file(
			(string) $request->get_param( 'file_key' ),
			(int) $request->get_param( 'depth' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'figma_get_file', $request, array( 'file_key' => $result['file_key'] ) );
		return $this->success_response( $result );
	}

	/**
	 * Get node summary.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_node( $request ) {
		$result = $this->figma->get_node(
			(string) $request->get_param( 'file_key' ),
			(string) $request->get_param( 'node_id' ),
			(int) $request->get_param( 'depth' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity(
			'figma_get_node',
			$request,
			array(
				'file_key' => $result['file_key'],
				'node_id'  => $result['node_id'],
			)
		);

		return $this->success_response( $result );
	}

	/**
	 * Get the cached design-tokens payload (no Figma API call).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_design_tokens( $request ) {
		$result = $this->figma->get_cached_design_tokens();
		$this->log_activity( 'figma_get_design_tokens', $request, array( 'file_key' => $result['file_key'] ) );
		return $this->success_response( $result );
	}

	/**
	 * Trigger a fresh design-tokens sync from Figma's shared styles.
	 *
	 * Makes an external Figma API call and overwrites the cached
	 * mcpwp_figma_design_tokens option — admin scope required (see
	 * Mcpwp_Api_Auth::get_required_scope_for_request()).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sync_design_tokens( $request ) {
		$result = $this->figma->sync_design_tokens( (string) $request->get_param( 'file_key' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity(
			'figma_sync_design_tokens',
			$request,
			array(
				'file_key'  => $result['file_key'],
				'colors'    => count( $result['colors'] ),
				'typography' => count( $result['typography'] ),
			)
		);

		return $this->success_response( $result );
	}
}
