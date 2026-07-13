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
		$defaults = array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'draft',
			'posts_per_page' => 50,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		// Allow filtering by post type
		if ( ! empty( $args['type'] ) ) {
			$type = sanitize_key( $args['type'] );
			if ( in_array( $type, array( 'post', 'page' ), true ) ) {
				$defaults['post_type'] = $type;
			}
		}

		$query_args = wp_parse_args( $args, $defaults );
		$query = new WP_Query( $query_args );

		$drafts = array();
		foreach ( $query->posts as $post ) {
			$drafts[] = array(
				'id'       => $post->ID,
				'type'     => $post->post_type,
				'title'    => $post->post_title ?: __( '(no title)', 'mumega-mcp' ),
				'created'  => $post->post_date,
				'modified' => $post->post_modified,
				'author'   => array(
					'id'   => (int) $post->post_author,
					'name' => get_the_author_meta( 'display_name', $post->post_author ),
				),
				'edit_url' => get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return array(
			'drafts' => $drafts,
			'total'  => $query->found_posts,
			'posts'  => count( array_filter( $drafts, fn( $d ) => 'post' === $d['type'] ) ),
			'pages'  => count( array_filter( $drafts, fn( $d ) => 'page' === $d['type'] ) ),
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
