<?php
/**
 * Categories, tags & terms.
 *
 * Carved from the original Mcpwp_REST_Site (G1 split). Behavior-identical.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Categories, tags & terms.
 */
class Mcpwp_REST_Site_Taxonomy extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/categories',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_categories' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'per_page' => array(
							'description' => __( 'Results per page.', 'mumcp' ),
							'type'        => 'integer',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 200,
						),
						'search'   => array(
							'description' => __( 'Search term.', 'mumcp' ),
							'type'        => 'string',
						),
						'parent'   => array(
							'description' => __( 'Parent category ID.', 'mumcp' ),
							'type'        => 'integer',
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/tags',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_tags' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'per_page' => array(
							'description' => __( 'Results per page.', 'mumcp' ),
							'type'        => 'integer',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 200,
						),
						'search'   => array(
							'description' => __( 'Search term.', 'mumcp' ),
							'type'        => 'string',
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/terms',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_term' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'taxonomy'    => array(
							'description' => __( 'Taxonomy name.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'name'        => array(
							'description' => __( 'Term name.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'slug'        => array(
							'description' => __( 'Term slug.', 'mumcp' ),
							'type'        => 'string',
						),
						'description' => array(
							'description' => __( 'Term description.', 'mumcp' ),
							'type'        => 'string',
						),
						'parent'      => array(
							'description' => __( 'Parent term ID.', 'mumcp' ),
							'type'        => 'integer',
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/terms/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_term' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'taxonomy'    => array(
							'description' => __( 'Taxonomy name.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'name'        => array(
							'description' => __( 'New term name.', 'mumcp' ),
							'type'        => 'string',
						),
						'slug'        => array(
							'description' => __( 'New term slug.', 'mumcp' ),
							'type'        => 'string',
						),
						'description' => array(
							'description' => __( 'New term description.', 'mumcp' ),
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_term' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'taxonomy' => array(
							'description' => __( 'Taxonomy name.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
	}

	public function list_categories( $request ) {
		$this->log_activity( 'list_categories', $request );

		$args = array(
			'taxonomy'   => 'category',
			'number'     => min( 200, max( 1, absint( $request->get_param( 'per_page' ) ?: 100 ) ) ),
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$args['search'] = sanitize_text_field( $search );
		}

		$parent = $request->get_param( 'parent' );
		if ( null !== $parent ) {
			$args['parent'] = absint( $parent );
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$items = array_map( array( $this, 'format_term' ), $terms );

		return $this->success_response(
			array(
				'categories' => $items,
				'total'      => count( $items ),
			)
		);
	}

	public function list_tags( $request ) {
		$this->log_activity( 'list_tags', $request );

		$args = array(
			'taxonomy'   => 'post_tag',
			'number'     => min( 200, max( 1, absint( $request->get_param( 'per_page' ) ?: 100 ) ) ),
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$args['search'] = sanitize_text_field( $search );
		}

		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$items = array_map( array( $this, 'format_term' ), $terms );

		return $this->success_response(
			array(
				'tags'  => $items,
				'total' => count( $items ),
			)
		);
	}

	private function format_term( $term ) {
		return array(
			'id'          => $term->term_id,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'count'       => $term->count,
			'parent'      => $term->parent,
		);
	}

	public function create_term( $request ) {
		$this->log_activity( 'create_term', $request );

		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );
		$name     = sanitize_text_field( $request->get_param( 'name' ) );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->error_response( 'invalid_taxonomy', __( 'Taxonomy does not exist.', 'mumcp' ), 400 );
		}

		$args = array();
		if ( $request->get_param( 'slug' ) ) {
			$args['slug'] = sanitize_title( $request->get_param( 'slug' ) );
		}
		if ( $request->get_param( 'description' ) ) {
			$args['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
		}
		if ( $request->get_param( 'parent' ) ) {
			$args['parent'] = absint( $request->get_param( 'parent' ) );
		}

		$result = wp_insert_term( $name, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$term = get_term( $result['term_id'], $taxonomy );

		return $this->success_response(
			array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'taxonomy'    => $term->taxonomy,
				'description' => $term->description,
				'parent'      => $term->parent,
				'count'       => $term->count,
			),
			201
		);
	}

	public function update_term( $request ) {
		$this->log_activity( 'update_term', $request );

		$term_id  = absint( $request->get_param( 'id' ) );
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->error_response( 'invalid_taxonomy', __( 'Taxonomy does not exist.', 'mumcp' ), 400 );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error_response( 'not_found', __( 'Term not found.', 'mumcp' ), 404 );
		}

		$args = array();
		if ( $request->get_param( 'name' ) ) {
			$args['name'] = sanitize_text_field( $request->get_param( 'name' ) );
		}
		if ( $request->get_param( 'slug' ) ) {
			$args['slug'] = sanitize_title( $request->get_param( 'slug' ) );
		}
		if ( null !== $request->get_param( 'description' ) ) {
			$args['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
		}

		if ( empty( $args ) ) {
			return $this->error_response( 'no_changes', __( 'No fields to update.', 'mumcp' ), 400 );
		}

		$result = wp_update_term( $term_id, $taxonomy, $args );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$term = get_term( $result['term_id'], $taxonomy );

		return $this->success_response(
			array(
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'taxonomy'    => $term->taxonomy,
				'description' => $term->description,
				'parent'      => $term->parent,
				'count'       => $term->count,
			)
		);
	}

	public function delete_term( $request ) {
		$this->log_activity( 'delete_term', $request );

		$term_id  = absint( $request->get_param( 'id' ) );
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			return $this->error_response( 'invalid_taxonomy', __( 'Taxonomy does not exist.', 'mumcp' ), 400 );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			return $this->error_response( 'not_found', __( 'Term not found.', 'mumcp' ), 404 );
		}

		$result = wp_delete_term( $term_id, $taxonomy );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		if ( false === $result ) {
			return $this->error_response( 'delete_failed', __( 'Failed to delete term.', 'mumcp' ), 500 );
		}

		return $this->success_response(
			array(
				'deleted' => true,
				'term_id' => $term_id,
			)
		);
	}

}
