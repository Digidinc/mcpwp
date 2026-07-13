<?php
/**
 * Design reference library.
 *
 * Carved from the original Mcpwp_REST_Site (G1 split). Behavior-identical.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Design reference library.
 */
class Mcpwp_REST_Site_Library extends Mcpwp_REST_API {

	/** @var Mcpwp_Design_References */
	private $design_references;

	public function __construct() {
		$this->design_references = new Mcpwp_Design_References();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/design-references',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_design_references' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$this->get_pagination_args(),
						array(
							'query' => array(
								'description' => __( 'Optional text query.', 'mumega-mcp' ),
								'type'        => 'string',
							),
							'page_intent' => array(
								'description' => __( 'Optional page intent filter.', 'mumega-mcp' ),
								'type'        => 'string',
							),
							'archetype_class' => array(
								'description' => __( 'Optional archetype class filter.', 'mumega-mcp' ),
								'type'        => 'string',
							),
							'style' => array(
								'description' => __( 'Optional style filter.', 'mumega-mcp' ),
								'type'        => 'string',
							),
							'source_type' => array(
								'description' => __( 'Optional source type filter.', 'mumega-mcp' ),
								'type'        => 'string',
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_design_reference' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/design-references/(?P<id>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_design_reference' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_design_reference' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	public function list_design_references( $request ) {
		$this->log_activity( 'list_design_references', $request );

		$result = $this->design_references->list_references(
			array(
				'query'           => $request->get_param( 'query' ),
				'page_intent'     => $request->get_param( 'page_intent' ),
				'archetype_class' => $request->get_param( 'archetype_class' ),
				'style'           => $request->get_param( 'style' ),
				'source_type'     => $request->get_param( 'source_type' ),
				'per_page'        => $request->get_param( 'per_page' ),
				'page'            => $request->get_param( 'page' ),
			)
		);

		return $this->success_response( $result );
	}

	public function get_design_reference( $request ) {
		$this->log_activity( 'get_design_reference', $request );

		$result = $this->design_references->get_reference( $request['id'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	public function create_design_reference( $request ) {
		$this->log_activity( 'create_design_reference', $request );

		$result = $this->design_references->create_reference( $request->get_json_params() ?: $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	public function update_design_reference( $request ) {
		$this->log_activity( 'update_design_reference', $request );

		$result = $this->design_references->update_reference( $request['id'], $request->get_json_params() ?: $request->get_params() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

}
