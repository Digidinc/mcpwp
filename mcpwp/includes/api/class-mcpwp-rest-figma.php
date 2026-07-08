<?php
/**
 * Figma REST API Controller
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes read-only Figma design intake routes.
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
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/figma/file',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_file' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'file_key' => array(
						'type'        => 'string',
						'description' => __( 'Figma file key. Optional if a default file key is configured.', 'mumcp' ),
					),
					'depth'    => array(
						'type'        => 'integer',
						'description' => __( 'Outline depth to request from Figma.', 'mumcp' ),
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
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'file_key' => array(
						'type'        => 'string',
						'description' => __( 'Figma file key. Optional if a default file key is configured.', 'mumcp' ),
					),
					'node_id'  => array(
						'type'        => 'string',
						'description' => __( 'Figma node ID to fetch, such as 12:34.', 'mumcp' ),
						'required'    => true,
					),
					'depth'    => array(
						'type'        => 'integer',
						'description' => __( 'Subtree depth to request from Figma.', 'mumcp' ),
						'default'     => 2,
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
}
