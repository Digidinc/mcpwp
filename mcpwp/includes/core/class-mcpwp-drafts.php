<?php
/**
 * Drafts handler
 *
 * @package MCPWP
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
				'title'    => $post->post_title ?: __( '(no title)', 'mumcp' ),
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
	 * Delete all drafts.
	 *
	 * @param array $args Arguments.
	 * @return array Result.
	 */
	public function delete_all_drafts( $args = array() ) {
		$post_types = array( 'post', 'page' );

		// Filter by type
		if ( ! empty( $args['type'] ) ) {
			$type = sanitize_key( $args['type'] );
			if ( in_array( $type, array( 'post', 'page' ), true ) ) {
				$post_types = array( $type );
			}
		}

		$force = ! empty( $args['force'] );

		$query = new WP_Query(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$deleted = 0;
		$failed = 0;

		foreach ( $query->posts as $post_id ) {
			$result = wp_delete_post( $post_id, $force );
			if ( $result ) {
				++$deleted;
			} else {
				++$failed;
			}
		}

		return array(
			'success'  => true,
			'deleted'  => $deleted,
			'failed'   => $failed,
			'message'  => sprintf(
				/* translators: %d: number of deleted drafts */
				__( '%d drafts deleted.', 'mumcp' ),
				$deleted
			),
			'force'    => $force,
		);
	}
}
