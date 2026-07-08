<?php
/**
 * Google Indexing REST API Controller
 *
 * @package MCPWP_Pro
 * @since   1.1.22
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for Google Indexing API operations.
 */
class Mcpwp_REST_Google_Indexing extends Mcpwp_REST_API {

	/**
	 * Google Indexing handler.
	 *
	 * @var Mcpwp_Google_Indexing
	 */
	private $indexing;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Google_Indexing $indexing Google Indexing handler.
	 */
	public function __construct( $indexing ) {
		$this->indexing = $indexing;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Submit URLs for indexing.
		register_rest_route(
			$this->namespace,
			'/google-indexing/submit',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'submit_urls' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'urls'   => array(
							'description' => __( 'Array of URLs to submit for indexing.', 'mumcp' ),
							'type'        => 'array',
							'required'    => true,
							'items'       => array( 'type' => 'string' ),
						),
						'action' => array(
							'description' => __( 'Indexing action: URL_UPDATED or URL_DELETED.', 'mumcp' ),
							'type'        => 'string',
							'enum'        => array( 'URL_UPDATED', 'URL_DELETED' ),
							'default'     => 'URL_UPDATED',
						),
					),
				),
			)
		);

		// Get indexing status for a URL.
		register_rest_route(
			$this->namespace,
			'/google-indexing/status',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'url' => array(
							'description' => __( 'URL to check indexing status for.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Submit URLs to Google for indexing.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function submit_urls( $request ) {
		$this->log_activity( 'google_indexing_submit', $request );

		$urls   = $request->get_param( 'urls' );
		$action = $request->get_param( 'action' ) ?: 'URL_UPDATED';
		$result = $this->indexing->submit_urls( $urls, $action );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Get indexing status for a URL.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_status( $request ) {
		$this->log_activity( 'google_indexing_status', $request );

		$url    = $request->get_param( 'url' );
		$result = $this->indexing->get_status( $url );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}
}
