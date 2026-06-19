<?php
/**
 * OpenAPI schema endpoint.
 *
 * Generates an OpenAPI 3.0 schema from the free-tools registry so ChatGPT
 * Actions (and any other OpenAPI consumer) can discover available operations.
 *
 * Route: GET /wp-json/mcpwp/v1/openapi.json  (public — no API key required)
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OpenAPI schema endpoint.
 */
class Mcpwp_REST_OpenAPI extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/openapi.json',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_openapi_schema' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * GET /openapi.json
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response OpenAPI 3.0 schema.
	 */
	public function get_openapi_schema( $request ) {
		$server_url  = get_site_url() . '/wp-json/mcpwp/v1';
		$free_tools  = new Mcpwp_MCP_Free_Tools();
		$tools       = $free_tools->get_tools();
		$tool_map    = $free_tools->get_tool_map();

		$paths = array();

		foreach ( $tools as $tool ) {
			$name = $tool['name'];

			if ( ! isset( $tool_map[ $name ] ) ) {
				continue;
			}

			$route        = $tool_map[ $name ]['route'];
			$http_method  = strtolower( $tool_map[ $name ]['method'] );
			$description  = $tool['description'];
			$input_schema = $tool['inputSchema'];

			// Extract path params from route e.g. {id}, {operation_id}.
			preg_match_all( '/\{([^}]+)\}/', $route, $m );
			$path_params = $m[1];

			$parameters  = array();
			$body_props  = array();
			$body_req    = array();

			// Path params always go as 'in: path'.
			foreach ( $path_params as $p ) {
				$parameters[] = array(
					'name'     => $p,
					'in'       => 'path',
					'required' => true,
					'schema'   => array( 'type' => 'string' ),
				);
			}

			$input_props    = ( isset( $input_schema['properties'] ) && is_array( $input_schema['properties'] ) )
				? $input_schema['properties']
				: array();
			$required_props = isset( $input_schema['required'] ) ? $input_schema['required'] : array();
			$is_read        = in_array( $http_method, array( 'get', 'head', 'delete' ), true );

			foreach ( $input_props as $prop_name => $prop_def ) {
				if ( in_array( $prop_name, $path_params, true ) ) {
					continue; // already declared as a path param
				}

				$prop_type = isset( $prop_def['type'] ) ? $prop_def['type'] : 'string';
				$prop_desc = isset( $prop_def['description'] ) ? $prop_def['description'] : '';
				$required  = in_array( $prop_name, $required_props, true );

				if ( $is_read ) {
					$parameters[] = array(
						'name'        => $prop_name,
						'in'          => 'query',
						'required'    => $required,
						'description' => $prop_desc,
						'schema'      => array( 'type' => $prop_type ),
					);
				} else {
					$body_props[ $prop_name ] = array(
						'type'        => $prop_type,
						'description' => $prop_desc,
					);
					if ( $required ) {
						$body_req[] = $prop_name;
					}
				}
			}

			$operation = array(
				'operationId' => $name,
				'summary'     => $description,
				'security'    => array( array( 'apiKey' => array() ) ),
				'parameters'  => $parameters,
				'responses'   => array(
					'200' => array( 'description' => 'Success' ),
					'401' => array( 'description' => 'Invalid or missing API key' ),
					'403' => array( 'description' => 'Insufficient scope' ),
				),
			);

			if ( ! empty( $body_props ) ) {
				$body_schema = array(
					'type'       => 'object',
					'properties' => $body_props,
				);
				if ( ! empty( $body_req ) ) {
					$body_schema['required'] = $body_req;
				}
				$operation['requestBody'] = array(
					'required' => true,
					'content'  => array(
						'application/json' => array( 'schema' => $body_schema ),
					),
				);
			}

			$openapi_path = '/' . ltrim( $route, '/' );
			if ( ! isset( $paths[ $openapi_path ] ) ) {
				$paths[ $openapi_path ] = array();
			}
			$paths[ $openapi_path ][ $http_method ] = $operation;
		}

		$schema = array(
			'openapi' => '3.0.0',
			'info'    => array(
				'title'       => get_bloginfo( 'name' ) . ' — MCPWP',
				'version'     => MCPWP_VERSION,
				'description' => 'WordPress site management API powered by MCPWP. Authenticate with X-API-Key header.',
			),
			'servers' => array(
				array( 'url' => $server_url ),
			),
			'components' => array(
				'securitySchemes' => array(
					'apiKey' => array(
						'type' => 'apiKey',
						'in'   => 'header',
						'name' => 'X-API-Key',
					),
				),
			),
			'paths' => $paths,
		);

		$response = new WP_REST_Response( $schema, 200 );
		$response->header( 'Content-Type', 'application/json; charset=utf-8' );
		return $response;
	}
}
