<?php
/**
 * Auto-purge Cloudflare cache for the edited permalink after a content write.
 *
 * Stale cached HTML after an edit delays freshness signals reaching crawlers
 * and visitors. No-ops entirely unless the Cloudflare integration is
 * configured; failures are logged, never fatal to the write itself.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mcpwp_Cloudflare_Cache_Sync {

	const LOG_PREFIX = '[MCPWP-cloudflare-cache-sync]';

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_elementor_meta_write' ), 20, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_elementor_meta_write' ), 20, 4 );
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::maybe_purge( $post_id );
	}

	/**
	 * @param int    $meta_id    Meta ID.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function on_elementor_meta_write( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( '_elementor_data' !== $meta_key ) {
			return;
		}
		self::maybe_purge( $post_id );
	}

	/**
	 * @param int $post_id Post ID.
	 */
	private static function maybe_purge( $post_id ) {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return;
		}

		if ( ! class_exists( 'Mcpwp_Integration_Manager' ) || ! Mcpwp_Integration_Manager::get_instance()->has_provider_key( 'cloudflare' ) ) {
			return;
		}

		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return;
		}

		$cloudflare = new Mcpwp_Provider_Cloudflare();
		$result     = $cloudflare->purge_cache( array( $url ) );

		if ( is_wp_error( $result ) ) {
			error_log( sprintf( '%s purge failed for post=%d url=%s error=%s', self::LOG_PREFIX, $post_id, $url, $result->get_error_message() ) );
		}
	}
}
