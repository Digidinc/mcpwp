<?php
/**
 * REST endpoints for structured site memory.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles /memory/* REST routes.
 */
class Mcpwp_REST_Site_Memory extends Mcpwp_REST_API {

	public function register_routes() {
		// List all memories (optionally by namespace).
		register_rest_route(
			$this->namespace,
			'/memory',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_memories' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'namespace' => array( 'type' => 'string', 'default' => '' ),
					'key'       => array( 'type' => 'string', 'default' => '' ),
					'query'     => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);

		// Store a memory entry.
		register_rest_route(
			$this->namespace,
			'/memory',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'remember' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'namespace' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'key'       => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
					'value'     => array( 'required' => true ),
					'ttl_days'  => array( 'type' => 'integer', 'default' => 0, 'minimum' => 0 ),
				),
			)
		);

		// Recall — single entry or keyword search.
		register_rest_route(
			$this->namespace,
			'/memory/(?P<namespace>[a-z0-9_-]+)/(?P<key>[a-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'recall' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'namespace' => array( 'type' => 'string', 'required' => true ),
					'key'       => array( 'type' => 'string', 'required' => true ),
				),
			)
		);

		// Forget — delete a single entry.
		register_rest_route(
			$this->namespace,
			'/memory/(?P<namespace>[a-z0-9_-]+)/(?P<key>[a-z0-9_-]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'forget' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'namespace' => array( 'type' => 'string', 'required' => true ),
					'key'       => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	public function list_memories( WP_REST_Request $request ): WP_REST_Response {
		Mcpwp_Site_Memory::maybe_migrate_site_context();
		$namespace = $request->get_param( 'namespace' ) ?: null;
		$key       = $request->get_param( 'key' ) ?: null;
		$query     = $request->get_param( 'query' ) ?: null;

		// Exact lookup: namespace + key, no query.
		if ( $namespace && $key && ! $query ) {
			$results = Mcpwp_Site_Memory::recall( $namespace, $key );
			if ( empty( $results ) ) {
				return new WP_REST_Response( array( 'memories' => array(), 'count' => 0 ), 200 );
			}
			return new WP_REST_Response( array( 'memories' => $results, 'count' => count( $results ) ), 200 );
		}

		// Keyword search.
		if ( $query || ( $namespace && ! $key ) ) {
			$results = Mcpwp_Site_Memory::recall( $namespace, $query );
			return new WP_REST_Response( array( 'memories' => $results, 'count' => count( $results ) ), 200 );
		}

		// Full list, optionally filtered by namespace.
		$grouped = Mcpwp_Site_Memory::list_all( $namespace );
		$flat    = array();
		foreach ( $grouped as $ns => $entries ) {
			foreach ( $entries as $k => $entry ) {
				$flat[] = array_merge( array( 'namespace' => $ns, 'key' => $k ), $entry );
			}
		}

		return new WP_REST_Response(
			array(
				'memories'   => $flat,
				'count'      => count( $flat ),
				'grouped'    => $grouped,
				'namespaces' => Mcpwp_Site_Memory::VALID_NAMESPACES,
			),
			200
		);
	}

	public function remember( WP_REST_Request $request ): WP_REST_Response {
		$namespace = $request->get_param( 'namespace' );
		$key       = $request->get_param( 'key' );
		$value     = $request->get_param( 'value' );
		$ttl_days  = (int) $request->get_param( 'ttl_days' );

		if ( ! in_array( $namespace, Mcpwp_Site_Memory::VALID_NAMESPACES, true ) ) {
			return new WP_REST_Response(
				array( 'success' => false, 'message' => 'Invalid namespace. Use: ' . implode( ', ', Mcpwp_Site_Memory::VALID_NAMESPACES ) ),
				400
			);
		}

		$ok = Mcpwp_Site_Memory::remember( $namespace, $key, $value, $ttl_days );
		return new WP_REST_Response( array( 'success' => $ok, 'namespace' => $namespace, 'key' => $key ), $ok ? 200 : 500 );
	}

	public function recall( WP_REST_Request $request ): WP_REST_Response {
		$namespace = $request->get_param( 'namespace' );
		$key       = $request->get_param( 'key' );

		$results = Mcpwp_Site_Memory::recall( $namespace, $key );
		if ( empty( $results ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'Not found' ), 404 );
		}

		return new WP_REST_Response( $results[0], 200 );
	}

	public function forget( WP_REST_Request $request ): WP_REST_Response {
		$namespace = $request->get_param( 'namespace' );
		$key       = $request->get_param( 'key' );

		$ok = Mcpwp_Site_Memory::forget( $namespace, $key );
		return new WP_REST_Response( array( 'success' => $ok ), $ok ? 200 : 404 );
	}
}
