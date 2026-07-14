<?php
/** Category and tag routes. @package mumcp */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Mcpwp_REST_Site_Taxonomy extends Mcpwp_REST_API {
	public function register_routes() {
		register_rest_route( $this->namespace, '/categories', $this->route_definition( WP_REST_Server::READABLE, 'list_categories', 'check_taxonomy_read_permission', 'taxonomy', 'read', array( 'read' ), false, $this->term_list_args() ) );
		register_rest_route( $this->namespace, '/tags', $this->route_definition( WP_REST_Server::READABLE, 'list_tags', 'check_taxonomy_read_permission', 'taxonomy', 'read', array( 'read' ), false, $this->term_list_args() ) );
		register_rest_route( $this->namespace, '/terms', $this->route_definition( WP_REST_Server::CREATABLE, 'create_term', 'check_create_term_permission', 'taxonomy', 'write', array( 'selected taxonomy manage_terms' ), true, $this->term_create_args() ) );
	}
	public function list_categories( $request ) { return $this->success_response( array( 'categories' => $this->format_terms( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) ) ) ) ); }
	public function list_tags( $request ) { return $this->success_response( array( 'tags' => $this->format_terms( get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => false ) ) ) ) ); }
	public function create_term( $request ) {
		$taxonomy = sanitize_key( (string) $request->get_param( 'taxonomy' ) );
		if ( ! in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) { return $this->error_response( 'invalid_taxonomy', __( 'Taxonomy must be category or post_tag.', 'mumega-mcp' ), 400 ); }
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) { return $this->error_response( 'missing_name', __( 'Term name is required.', 'mumega-mcp' ), 400 ); }
		$result = wp_insert_term( $name, $taxonomy, array( 'slug' => sanitize_title( (string) $request->get_param( 'slug' ) ), 'description' => sanitize_textarea_field( (string) $request->get_param( 'description' ) ) ) );
		return is_wp_error( $result ) ? $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 ) : $this->success_response( array( 'id' => (int) $result['term_id'], 'taxonomy' => $taxonomy ), 201 );
	}
	private function format_terms( $terms ) {
		if ( is_wp_error( $terms ) ) { return array(); }
		return array_map( static function ( $term ) { return array( 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'count' => (int) $term->count ); }, $terms );
	}
}
