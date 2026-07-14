<?php
/**
 * Drafts handler
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle draft operations.
 */
class Mcpwp_Drafts {

	/**
	 * List all drafts.
	 *
	 * @param array $args Query arguments.
	 * @return array Drafts list.
	 */
	public function list_drafts( $args = array() ) {
		$type = isset( $args['type'] ) && in_array( $args['type'], array( 'post', 'page' ), true ) ? $args['type'] : 'all';
		$types = 'all' === $type ? array( 'post', 'page' ) : array( $type );
		$posts = array();
		foreach ( $types as $post_type ) {
			$plural = 'page' === $post_type ? 'pages' : 'posts';
			if ( ! current_user_can( 'edit_' . $plural ) ) { continue; }
			$query_args = array(
				'post_type' => $post_type, 'post_status' => 'draft', 'posts_per_page' => -1,
				'orderby' => isset( $args['orderby'] ) ? $args['orderby'] : 'modified',
				'order' => isset( $args['order'] ) ? strtoupper( $args['order'] ) : 'DESC',
			);
			if ( ! current_user_can( 'edit_others_' . $plural ) ) { $query_args['author'] = get_current_user_id(); }
			$query = new WP_Query( $query_args );
			$posts = array_merge( $posts, $query->posts );
		}

		$orderby = isset( $args['orderby'] ) ? $args['orderby'] : 'modified';
		$order = isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ? 1 : -1;
		usort( $posts, function ( $left, $right ) use ( $orderby, $order ) {
			$fields = array( 'modified' => 'post_modified', 'date' => 'post_date', 'title' => 'post_title', 'id' => 'ID' );
			$field = isset( $fields[ $orderby ] ) ? $fields[ $orderby ] : 'post_modified';
			$comparison = 'ID' === $field ? (int) $left->$field <=> (int) $right->$field : strcasecmp( (string) $left->$field, (string) $right->$field );
			if ( 0 === $comparison ) { $comparison = (int) $left->ID <=> (int) $right->ID; }
			return $comparison * $order;
		} );
		$total_posts = count( array_filter( $posts, function ( $post ) { return 'post' === $post->post_type; } ) );
		$total_pages = count( $posts ) - $total_posts;
		$per_page = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 20;
		$paged = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
		$posts = array_slice( $posts, ( $paged - 1 ) * $per_page, $per_page );

		$drafts = array();
		foreach ( $posts as $post ) {
			$draft = array(
				'id'       => $post->ID,
				'type'     => $post->post_type,
				'title'    => $post->post_title ?: __( '(no title)', 'mumega-mcp' ),
				'created'  => $post->post_date,
				'modified' => $post->post_modified,
				'author'   => array(
					'id'   => (int) $post->post_author,
					'name' => get_the_author_meta( 'display_name', $post->post_author ),
				),
			);
			if ( current_user_can( 'edit_post', $post->ID ) ) { $draft['edit_url'] = get_edit_post_link( $post->ID, 'raw' ); }
			$drafts[] = $draft;
		}

		return array(
			'drafts' => $drafts,
			'total'  => $total_posts + $total_pages,
			'posts'  => $total_posts,
			'pages'  => $total_pages,
		);
	}

	/**
	 * Delete one draft.
	 *
	 * @param int $post_id Post or page ID.
	 * @return array Result.
	 */
	public function delete_draft( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || 'draft' !== $post->post_status ) {
			return new WP_Error( 'not_a_draft', __( 'The requested post or page is not a draft.', 'mumega-mcp' ), array( 'status' => 404 ) );
		}
		if ( ! wp_delete_post( $post_id, false ) ) {
			return new WP_Error( 'delete_failed', __( 'The draft could not be deleted.', 'mumega-mcp' ), array( 'status' => 500 ) );
		}
		return array( 'deleted' => true, 'id' => (int) $post_id );
	}
}
