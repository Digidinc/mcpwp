<?php
/** Category and tag routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }

class Mcpwp_REST_Site_Taxonomy extends Mcpwp_REST_API {
	public function register_routes() {
		register_rest_route( $this->namespace, '/categories', $this->route_definition( WP_REST_Server::READABLE, 'list_categories', 'check_taxonomy_read_permission', 'taxonomy', 'read', array( 'read' ), false, $this->term_list_args() ) );
		register_rest_route( $this->namespace, '/tags', $this->route_definition( WP_REST_Server::READABLE, 'list_tags', 'check_taxonomy_read_permission', 'taxonomy', 'read', array( 'read' ), false, $this->term_list_args() ) );
		register_rest_route( $this->namespace, '/terms', $this->route_definition( WP_REST_Server::CREATABLE, 'create_term', 'check_create_term_permission', 'taxonomy', 'write', array( 'selected taxonomy manage_terms' ), true, $this->term_create_args() ) );
	}
	public function list_categories( $request ) {
		return $this->list_taxonomy( $request, 'category', 'categories' ); }
	public function list_tags( $request ) {
		return $this->list_taxonomy( $request, 'post_tag', 'tags' ); }
	public function create_term( $request ) {
		$data = $this->validated_json_body( $request, $this->term_create_args() );
		if ( is_wp_error( $data ) ) {
			return $this->validation_error_response( $data ); }
		$taxonomy = $data['taxonomy'];
		$name     = sanitize_text_field( $data['name'] );
		$args     = array();
		if ( isset( $data['slug'] ) ) {
			$args['slug'] = $data['slug']; }
		if ( isset( $data['description'] ) ) {
			$args['description'] = sanitize_textarea_field( $data['description'] ); }
		$result = wp_insert_term( $name, $taxonomy, $args );
		return is_wp_error( $result ) ? $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 ) : $this->success_response(
			array(
				'id'       => (int) $result['term_id'],
				'taxonomy' => $taxonomy,
			),
			201
		);
	}
	private function list_taxonomy( $request, $taxonomy, $response_key ) {
		$params = $this->validated_query_params( $request, $this->term_list_args() );
		if ( is_wp_error( $params ) ) {
			return $this->validation_error_response( $params ); }
		$per_page = isset( $params['per_page'] ) ? (int) $params['per_page'] : 50;
		$page     = isset( $params['page'] ) ? (int) $params['page'] : 1;
		$args     = array(
			'taxonomy'   => $taxonomy,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
			'search'     => isset( $params['search'] ) ? (string) $params['search'] : '',
			'hide_empty' => isset( $params['hide_empty'] ) ? (bool) $params['hide_empty'] : false,
			'orderby'    => isset( $params['orderby'] ) ? (string) $params['orderby'] : 'name',
			'order'      => strtoupper( isset( $params['order'] ) ? (string) $params['order'] : 'asc' ),
		);
		$terms    = get_terms( $args );
		if ( is_wp_error( $terms ) ) {
			return $this->validation_error_response( $terms ); }
		return $this->success_response( array( $response_key => $this->format_terms( $terms ) ) );
	}
	private function format_terms( $terms ) {
		return array_map(
			static function ( $term ) {
				return array(
					'id'    => (int) $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'count' => (int) $term->count,
				);
			},
			$terms
		);
	}
}
