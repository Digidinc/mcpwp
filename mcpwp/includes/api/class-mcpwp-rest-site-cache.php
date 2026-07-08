<?php
/**
 * Cache flush endpoint.
 *
 * POST /mcpwp/v1/cache-flush
 * Flushes WordPress object cache + known page-cache plugins so agents can
 * purge stale content after bulk edits.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache flush REST controller.
 */
class Mcpwp_REST_Site_Cache extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/cache-flush',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'flush_cache' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'url' => array(
							'description' => __( 'Optional URL to purge. Omit for a site-wide flush.', 'mumcp' ),
							'type'        => 'string',
							'format'      => 'uri',
						),
					),
				),
			)
		);
	}

	/**
	 * Flush the WordPress page cache.
	 *
	 * Calls wp_cache_flush() unconditionally, then attempts each known
	 * page-cache plugin API in turn, recording which ones were invoked.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function flush_cache( $request ) {
		$this->log_activity( 'cache_flush', $request );

		$url     = (string) $request->get_param( 'url' );
		$url     = '' !== $url ? esc_url_raw( $url ) : '';
		$methods = array();

		// 1. WordPress object cache.
		wp_cache_flush();
		$methods[] = 'wp_core';

		// 2. W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$methods[] = 'w3tc';
		} elseif ( '' !== $url && function_exists( 'w3tc_flush_url' ) ) {
			w3tc_flush_url( $url );
			$methods[] = 'w3tc_url';
		}

		// 3. WP Super Cache.
		if ( function_exists( 'wp_cache_clean_cache' ) ) {
			global $file_prefix, $cache_path;
			// wp_cache_clean_cache expects the file prefix string and a boolean
			// for the "all blogs" flag.  Defaults are safe even if the globals
			// are not yet defined on this request.
			$prefix = isset( $file_prefix ) ? $file_prefix : 'wp-cache-';
			wp_cache_clean_cache( $prefix, true );
			$methods[] = 'wp_super_cache';
		} elseif ( '' !== $url && function_exists( 'wpsc_delete_url_from_cache' ) ) {
			wpsc_delete_url_from_cache( $url );
			$methods[] = 'wp_super_cache_url';
		}

		// 4. LiteSpeed Cache — class-based API (LSCWP 3.x+).
		if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
			LiteSpeed_Cache_API::purge_all();
			$methods[] = 'litespeed';
		}

		// 5. LiteSpeed Cache — action-based API (LSCWP 4.x+).
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			if ( ! in_array( 'litespeed', $methods, true ) ) {
				$methods[] = 'litespeed';
			}
		}

		// 6. WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$methods[] = 'wp_rocket';
		} elseif ( '' !== $url && function_exists( 'rocket_clean_post' ) ) {
			// rocket_clean_post() needs the post ID; fall back to domain purge.
			rocket_clean_domain();
			$methods[] = 'wp_rocket';
		}

		// 7. Cache Enabler.
		if ( has_action( 'cache_enabler_clear_site_cache' ) ) {
			do_action( 'cache_enabler_clear_site_cache' );
			$methods[] = 'cache_enabler';
		}

		// 8. Breeze (Cloudways).
		if ( class_exists( 'Breeze_PurgeCache' ) && method_exists( 'Breeze_PurgeCache', 'breeze_cache_flush' ) ) {
			Breeze_PurgeCache::breeze_cache_flush();
			$methods[] = 'breeze';
		}

		// 9. Cloudflare plugin (WordPress plugin — not the Cloudflare edge).
		if ( class_exists( 'CF\WordPress\Hooks' ) ) {
			do_action( 'cloudflare_purge_everything_hook' );
			$methods[] = 'cloudflare_plugin';
		}

		return $this->success_response(
			array(
				'flushed' => true,
				'url'     => '' !== $url ? $url : null,
				'methods' => $methods,
			)
		);
	}
}
