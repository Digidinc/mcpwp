<?php
/**
 * REST endpoints for site blueprint library.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles /site-blueprints/* REST routes.
 */
class Mcpwp_REST_Site_Blueprints extends Mcpwp_REST_API {

	public function register_routes() {
		// List all blueprints.
		register_rest_route(
			$this->namespace,
			'/site-blueprints',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_blueprints' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'category'         => array( 'type' => 'string', 'default' => '' ),
						'include_starters' => array( 'type' => 'boolean', 'default' => true ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_blueprint' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Get / delete one blueprint.
		register_rest_route(
			$this->namespace,
			'/site-blueprints/(?P<id>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_blueprint' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array( 'type' => 'string', 'required' => true ),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_blueprint' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id' => array( 'type' => 'string', 'required' => true ),
					),
				),
			)
		);

		// Deploy a blueprint.
		register_rest_route(
			$this->namespace,
			'/site-blueprints/(?P<id>[a-z0-9_-]+)/deploy',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deploy_blueprint' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'          => array( 'type' => 'string', 'required' => true ),
					'post_status' => array( 'type' => 'string', 'default' => 'draft', 'enum' => array( 'draft', 'publish' ) ),
					'name_prefix' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		// Extract blueprint from current site.
		register_rest_route(
			$this->namespace,
			'/site-blueprints/extract',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'extract_blueprint' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'save' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Save as a custom blueprint after extracting.' ),
				),
			)
		);
	}

	public function list_blueprints( WP_REST_Request $request ): WP_REST_Response {
		$include_starters = (bool) $request->get_param( 'include_starters' );
		$category         = $request->get_param( 'category' ) ?: null;

		$all = Mcpwp_Site_Blueprints::list_all( $include_starters );

		if ( $category ) {
			$all = array_values( array_filter( $all, fn( $bp ) => ( $bp['category'] ?? '' ) === $category ) );
		}

		return new WP_REST_Response( array( 'blueprints' => $all, 'count' => count( $all ) ), 200 );
	}

	public function create_blueprint( WP_REST_Request $request ): WP_REST_Response {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body['name'] ) || empty( $body['pages'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'name and pages are required.' ), 400 );
		}

		$blueprint = Mcpwp_Site_Blueprints::save( $body );
		return new WP_REST_Response( array( 'success' => true, 'blueprint' => $blueprint ), 201 );
	}

	public function get_blueprint( WP_REST_Request $request ): WP_REST_Response {
		$id        = $request->get_param( 'id' );
		$blueprint = Mcpwp_Site_Blueprints::get( $id );

		if ( ! $blueprint ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => "Blueprint '{$id}' not found." ), 404 );
		}

		return new WP_REST_Response( $blueprint, 200 );
	}

	public function delete_blueprint( WP_REST_Request $request ): WP_REST_Response {
		$id = $request->get_param( 'id' );
		$ok = Mcpwp_Site_Blueprints::delete( $id );

		if ( ! $ok ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => "Blueprint '{$id}' not found or is a starter (cannot delete)." ), 404 );
		}

		return new WP_REST_Response( array( 'success' => true, 'deleted_id' => $id ), 200 );
	}

	public function deploy_blueprint( WP_REST_Request $request ): WP_REST_Response {
		$id       = $request->get_param( 'id' );
		$overrides = array(
			'post_status' => $request->get_param( 'post_status' ) ?: 'draft',
			'name_prefix' => $request->get_param( 'name_prefix' ) ?: '',
		);

		$result = Mcpwp_Site_Blueprints::deploy( $id, $overrides );
		$status = ( $result['success'] ?? false ) ? 200 : 404;

		return new WP_REST_Response( $result, $status );
	}

	public function extract_blueprint( WP_REST_Request $request ): WP_REST_Response {
		$blueprint = Mcpwp_Site_Blueprints::extract();

		if ( $request->get_param( 'save' ) ) {
			$blueprint = Mcpwp_Site_Blueprints::save( $blueprint );
		}

		return new WP_REST_Response( array( 'blueprint' => $blueprint, 'saved' => (bool) $request->get_param( 'save' ) ), 200 );
	}
}
