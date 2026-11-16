<?php
/**
 * Browser-openable preview URLs for Elementor-backed posts (#1226).
 *
 * `elementor-preview` is Elementor's editor-iframe query arg; as a standalone
 * link it renders theme chrome without the page body. WordPress preview links
 * and public permalinks are the safe choices for agents and humans opening a
 * saved page in a browser.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One definition of how MCPWP builds preview/browse URLs for Elementor posts.
 */
class Mcpwp_Elementor_Preview_Url {

	/**
	 * URL to open the post's front-end view (not the Elementor editor iframe).
	 *
	 * @param int|WP_Post $post Post ID or object.
	 * @return string Empty when the post does not exist.
	 */
	public static function for_post( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		if ( 'publish' === $post->post_status ) {
			$url = get_permalink( $post );
			return is_string( $url ) ? $url : '';
		}

		// Trashed posts: core preview links 404 in the browser — send editors to wp-admin.
		if ( 'trash' === $post->post_status ) {
			$edit = admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' );
			return is_string( $edit ) ? $edit : '';
		}

		$url = get_preview_post_link( $post );
		return is_string( $url ) ? $url : '';
	}
}