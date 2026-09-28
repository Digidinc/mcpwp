<?php
/**
 * REST endpoints for the site graph read surface (#943 S2).
 *
 * Every route here is a data-egress surface reading `Mcpwp_Graph_Reader`
 * (which owns the actual visibility/allowlist logic — see that class's
 * docblock). This controller's only job is: resolve params, compute
 * `requester_can_see_non_public()` ONCE per request and pass it down, and
 * shape the response. /graph/rebuild is the one write route and requires
 * admin scope (wired in trait-mcpwp-api-auth.php's route table, not here —
 * this controller only registers the route and its own permission_callback
 * still runs through the same check_permission() chokepoint).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles /graph/* REST routes.
 */
class Mcpwp_REST_Graph extends Mcpwp_REST_API {

	/**
	 * Argument names accepted by /graph/query. Argument VALUES are validated
	 * by Mcpwp_Graph_Reader::parse_query_args(), the one place that owns the
	 * allowlists — route args here deliberately carry no `type` or
	 * `sanitize_callback`, which would silently coerce a bad value (absint()
	 * turns -5 into 5) instead of rejecting it.
	 */
	const QUERY_ARGS = array( 'kind', 'status', 'orphan', 'min_inbound', 'max_inbound', 'has_metric', 'metric', 'no_edge_type', 'modified_before', 'word_count_lt', 'order_by', 'order_dir', 'limit', 'cursor' );

	/**
	 * Register the /graph routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/graph',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_summary' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		$identifier_args = array(
			'node_key' => array( 'description' => 'Node key, e.g. post:123, term:category:5, media:9.' ),
			'post_id'  => array( 'description' => 'Post ID, shorthand for node_key=post:{post_id}.' ),
		);

		register_rest_route(
			$this->namespace,
			'/graph/node',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_node' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $identifier_args,
			)
		);

		register_rest_route(
			$this->namespace,
			'/graph/neighbors',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_neighbors' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array_merge(
					$identifier_args,
					array(
						'direction' => array( 'description' => 'out (default), in, or both.' ),
						'types'     => array( 'description' => 'Edge types to include: array or comma-separated string. Empty = all.' ),
						'depth'     => array( 'description' => '1 (default) or 2.' ),
						'limit'     => array( 'description' => 'Edges per page, 1-' . Mcpwp_Graph_Reader::NEIGHBORS_LIMIT_MAX . '.' ),
						'cursor'    => array( 'description' => 'Opaque cursor from a previous page.' ),
					)
				),
			)
		);

		$query_args = array();
		foreach ( self::QUERY_ARGS as $name ) {
			$query_args[ $name ] = array( 'description' => 'See wp_graph_query.' );
		}
		register_rest_route(
			$this->namespace,
			'/graph/query',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'query' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $query_args,
			)
		);

		// Admin scope: '/mcpwp/v1/graph/rebuild' is in the admin-on-write
		// route table in trait-mcpwp-api-auth.php, enforced by the same
		// check_permission() chokepoint every route runs through.
		register_rest_route(
			$this->namespace,
			'/graph/rebuild',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rebuild' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * GET /graph: the mcpwp://graph resource body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_summary( WP_REST_Request $request ) {
		return new WP_REST_Response( Mcpwp_Graph_Reader::resource_summary( $this->requester_can_see_non_public() ), 200 );
	}

	/**
	 * GET /graph/node.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_node( WP_REST_Request $request ) {
		$identifier = $this->resolve_identifier_param( $request );
		if ( is_wp_error( $identifier ) ) {
			return $this->error_response_from_wp_error( $identifier );
		}

		$can_see = $this->requester_can_see_non_public();
		$node    = Mcpwp_Graph_Reader::get_node( Mcpwp_Graph_Reader::resolve_node_key( $identifier ), $can_see );

		if ( $node instanceof WP_Error ) {
			return $this->error_response_from_wp_error( $node );
		}
		if ( null === $node || $this->graph_node_category_denied( (string) $node['node_key'] ) ) {
			// A hidden node, a category-gated one (#1024), and a missing one
			// read identically.
			return $this->error_response( 'not_found', 'Node not found.', 404 );
		}

		$node['graph_status'] = Mcpwp_Graph_Reader::graph_status( $can_see );
		return new WP_REST_Response( $node, 200 );
	}

	/**
	 * GET /graph/neighbors.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_neighbors( WP_REST_Request $request ) {
		$identifier = $this->resolve_identifier_param( $request );
		if ( is_wp_error( $identifier ) ) {
			return $this->error_response_from_wp_error( $identifier );
		}

		$can_see = $this->requester_can_see_non_public();
		$result  = Mcpwp_Graph_Reader::get_neighbors(
			array(
				'node_key'  => Mcpwp_Graph_Reader::resolve_node_key( $identifier ),
				'direction' => $request->get_param( 'direction' ),
				'types'     => $request->get_param( 'types' ),
				'depth'     => $request->get_param( 'depth' ),
				'limit'     => $request->get_param( 'limit' ),
				'cursor'    => $request->get_param( 'cursor' ),
			),
			$can_see
		);

		if ( $result instanceof WP_Error ) {
			return $this->error_response_from_wp_error( $result );
		}
		if ( null === $result || $this->graph_node_category_denied( (string) $result['center']['node_key'] ) ) {
			// The center itself may be a category-gated node (a product,
			// #1024) — same "missing" shape as a status-hidden or absent one.
			return $this->error_response( 'not_found', 'Node not found.', 404 );
		}

		$result = $this->filter_graph_neighbors_by_category( $result );

		$result['graph_status'] = Mcpwp_Graph_Reader::graph_status( $can_see );
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * GET /graph/query.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function query( WP_REST_Request $request ) {
		$args = array();
		foreach ( self::QUERY_ARGS as $name ) {
			$value = $request->get_param( $name );
			if ( null !== $value ) {
				$args[ $name ] = $value;
			}
		}

		$can_see = $this->requester_can_see_non_public();
		$result  = Mcpwp_Graph_Reader::query( $args, $can_see );

		if ( $result instanceof WP_Error ) {
			return $this->error_response_from_wp_error( $result );
		}

		// Category filter (#1024): Mcpwp_Graph_Reader's own visibility is
		// purely post-status (see its class docblock) — it has no concept of
		// MCPWP tool categories, so a 'woocommerce'/'elementor-templates'-
		// disabled key could otherwise read a product's or template's
		// word_count/label straight through wp_graph_query, which only ever
		// required the route's own 'content' category. Filtered after
		// pagination, same tradeoff this reader already accepts elsewhere
		// for status-hidden rows (a page can come back under its nominal
		// limit).
		$result['results'] = array_values(
			array_filter(
				$result['results'],
				function ( $item ) {
					return ! $this->graph_node_category_denied( (string) $item['node_key'] );
				}
			)
		);
		$result['count'] = count( $result['results'] );

		$result['graph_status'] = Mcpwp_Graph_Reader::graph_status( $can_see );
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Drop neighbor nodes this caller's category cannot see, and the edges
	 * that touch them — extending the "edges touching a hidden node are
	 * omitted rather than redacted" rule Mcpwp_Graph_Reader's own status
	 * visibility already applies (its class docblock) to tool-category
	 * visibility too (#1024). The reader itself stays status-only; this is
	 * the REST layer's own chokepoint on top of it.
	 *
	 * @param array $result {center, nodes, edges, ...} from
	 *                      Mcpwp_Graph_Reader::get_neighbors().
	 * @return array Same shape, category-hidden nodes/edges removed.
	 */
	private function filter_graph_neighbors_by_category( array $result ) {
		$hidden = array();
		$kept   = array();
		foreach ( $result['nodes'] as $node ) {
			if ( $this->graph_node_category_denied( (string) $node['node_key'] ) ) {
				$hidden[ (string) $node['node_key'] ] = true;
				continue;
			}
			$kept[] = $node;
		}
		$result['nodes'] = $kept;

		if ( ! empty( $hidden ) ) {
			$result['edges'] = array_values(
				array_filter(
					$result['edges'],
					function ( $edge ) use ( $hidden ) {
						return ! isset( $hidden[ (string) $edge['from_key'] ] ) && ! isset( $hidden[ (string) $edge['to_key'] ] );
					}
				)
			);
		}

		return $result;
	}

	/**
	 * Whether $node_key's own post type belongs to a tool category this
	 * caller does not hold — the same category_for_post_type()/
	 * caller_may_read_category() chokepoint from #1020/#1022, applied to the
	 * site graph read surface (wp_graph_node/neighbors/query), which has no
	 * category concept of its own (Mcpwp_Graph_Reader's visibility is purely
	 * post-status). Without this, a key with 'woocommerce' or
	 * 'elementor-templates' disabled could still read a product's or
	 * template's word_count/label/excerpt straight through the graph, which
	 * only ever required the route's own 'content' category (#1024).
	 *
	 * elementor_library/elementor_snippet nodes cannot actually occur here —
	 * Mcpwp_Graph_Builder::EXCLUDED_POST_TYPES excludes both from ingestion
	 * entirely (page-builder internals, not content the graph tracks) — but
	 * this stays type-driven rather than hardcoding that exclusion a second
	 * time, so a future covered type is gated automatically. Only a
	 * post-backed node_key ("post:{id}") carries a post type; term and media
	 * node keys are unaffected.
	 *
	 * @param string $node_key Node key.
	 * @return bool
	 */
	private function graph_node_category_denied( $node_key ) {
		if ( ! Mcpwp_Graph_Reader::is_post_key( $node_key ) ) {
			return false;
		}

		$post_id   = Mcpwp_Graph_Reader::post_id_from_key( $node_key );
		$post_type = $post_id ? get_post_type( $post_id ) : false;
		if ( ! $post_type ) {
			return false;
		}

		return ! $this->caller_may_read_category( $this->category_for_post_type( $post_type ) );
	}

	/**
	 * POST /graph/rebuild (admin scope): restart the backfill from the first
	 * post. Existing nodes and edges stay; unchanged posts are skipped by the
	 * builder's content_hash check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rebuild( WP_REST_Request $request ) {
		if ( ! Mcpwp_Graph_Builder::ready() ) {
			return $this->error_response( 'graph_not_ready', 'The site graph tables are not ready yet.', 503 );
		}
		// Review NIT: a backfill already walking the posts is not restarted
		// from the first post (that would throw away its cursor and re-walk
		// everything on every repeat call).
		if ( 'running' === get_option( Mcpwp_Graph_Builder::BACKFILL_STATUS_OPTION, '' ) ) {
			return new WP_REST_Response(
				array(
					'success'         => true,
					'already_running' => true,
					'message'         => 'A backfill is already running; it was not restarted. graph_status.backfill shows progress.',
					'graph_status'    => Mcpwp_Graph_Reader::graph_status( true ),
				),
				202
			);
		}
		Mcpwp_Graph_Builder::force_rebuild();
		return new WP_REST_Response(
			array(
				'success'      => true,
				'message'      => 'Backfill rescheduled from the first post. graph_status.backfill shows progress.',
				'graph_status' => Mcpwp_Graph_Reader::graph_status( true ),
			),
			200
		);
	}

	/**
	 * node_key, or post_id as a digit string. WP_Error when neither (or a
	 * malformed one) was supplied.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|WP_Error
	 */
	private function resolve_identifier_param( WP_REST_Request $request ) {
		$node_key = $request->get_param( 'node_key' );
		if ( null !== $node_key && '' !== $node_key ) {
			if ( ! is_string( $node_key ) || strlen( $node_key ) > 191 ) {
				return new WP_Error( 'invalid_node_key', 'node_key must be a string of at most 191 characters.', array( 'status' => 400 ) );
			}
			return $node_key;
		}
		$post_id = $request->get_param( 'post_id' );
		if ( null !== $post_id && '' !== $post_id ) {
			if ( ( is_int( $post_id ) && $post_id > 0 ) || ( is_string( $post_id ) && 1 === preg_match( '/^[1-9]\d{0,19}$/D', $post_id ) ) || ( is_float( $post_id ) && $post_id >= 1 && floor( $post_id ) === $post_id ) ) {
				return (string) (int) $post_id;
			}
			return new WP_Error( 'invalid_post_id', 'post_id must be a positive integer.', array( 'status' => 400 ) );
		}
		return new WP_Error( 'missing_identifier', 'Provide node_key or post_id.', array( 'status' => 400 ) );
	}
}
