<?php
/**
 * REST: Elementor revision history and revert (#820).
 *
 * GET  /elementor/{id}/revisions — wp_list_elementor_revisions
 * POST /elementor/{id}/revert    — wp_revert_elementor
 *
 * Both are thin: the history is WordPress's revisions and the revert is a
 * normal write through Mcpwp_Elementor_Basic::set_elementor_data(). See
 * Mcpwp_Elementor_Revisions.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor revisions controller.
 */
class Mcpwp_REST_Elementor_Revisions extends Mcpwp_REST_API {

	/**
	 * The Elementor write path a revert goes through.
	 *
	 * @var Mcpwp_Elementor_Basic
	 */
	private $elementor;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Elementor_Basic|null $elementor Write path (injectable for tests).
	 */
	public function __construct( $elementor = null ) {
		$this->elementor = null !== $elementor ? $elementor : new Mcpwp_Elementor_Basic();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/revisions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_revisions' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'       => array(
							'description'       => __( 'Page, post or Elementor template ID.', 'mcpwp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'description'       => __( 'Revisions per page (1-100).', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => Mcpwp_Elementor_Revisions::DEFAULT_PER_PAGE,
							'sanitize_callback' => 'absint',
						),
						'page'     => array(
							'description'       => __( 'Page number.', 'mcpwp' ),
							'type'              => 'integer',
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/(?P<id>\d+)/revert',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'revert' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'          => array(
							'description'       => __( 'Page, post or Elementor template ID.', 'mcpwp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'revision_id' => array(
							'description'       => __( 'Revision to restore, from wp_list_elementor_revisions or a write response\'s revision.previous_revision_id.', 'mcpwp' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'dry_run'     => array(
							'description' => __( 'Report what would change without writing.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
						'convert'     => array(
							'description' => __( 'Allow replacing existing non-Elementor content (#788).', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);
	}

	/**
	 * List a post's revisions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_revisions( $request ) {
		$this->log_activity( 'list_elementor_revisions', $request );

		$result = Mcpwp_Elementor_Revisions::list_revisions(
			absint( $request->get_param( 'id' ) ),
			array(
				'per_page' => $request->get_param( 'per_page' ),
				'page'     => $request->get_param( 'page' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Restore a post's Elementor document from a revision.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revert( $request ) {
		$this->log_activity( 'revert_elementor', $request );

		$result = Mcpwp_Elementor_Revisions::revert(
			$this->elementor,
			absint( $request->get_param( 'id' ) ),
			absint( $request->get_param( 'revision_id' ) ),
			array(
				'dry_run' => rest_sanitize_boolean( $request->get_param( 'dry_run' ) ),
				'convert' => rest_sanitize_boolean( $request->get_param( 'convert' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}
}
