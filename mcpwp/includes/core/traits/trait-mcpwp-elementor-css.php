<?php
/**
 * Elementor engine — css.
 *
 * Carved verbatim from Mcpwp_Elementor_Basic (G4 split). Mixed back via trait — same class, same $this.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Elementor_Css_Trait {

	/**
	 * Purge page cache across common WordPress caching plugins.
	 *
	 * @param int $page_id Post ID to purge.
	 */
	private function purge_page_cache( $page_id ) {
		// WordPress core.
		clean_post_cache( $page_id );

		$url = get_permalink( $page_id );

		// SiteGround SG Optimizer — purge both URL and full cache for aggressive configs.
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $url );
		}
		if ( class_exists( '\SiteGround_Optimizer\Supercacher\Supercacher' ) ) {
			\SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $page_id );
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_post' ) ) {
			w3tc_flush_post( $page_id );
		}

		// WP Rocket.
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $page_id );
		}

		// LiteSpeed Cache.
		if ( method_exists( 'LiteSpeed_Cache_API', 'purge_post' ) ) {
			LiteSpeed_Cache_API::purge_post( $page_id );
		} elseif ( class_exists( 'LiteSpeed\Purge' ) && method_exists( 'LiteSpeed\Purge', 'purge_post' ) ) {
			LiteSpeed\Purge::purge_post( $page_id );
		}

		// WP Fastest Cache.
		if ( function_exists( 'wpfc_clear_post_cache_by_id' ) ) {
			wpfc_clear_post_cache_by_id( $page_id );
		}

		// Autoptimize.
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			autoptimizeCache::clearall();
		}
	}

	/**
	 * Clear the per-document caches Elementor's own Document::save() clears
	 * on every normal write, for a write path that updates `_elementor_data`
	 * directly and never calls Document::save() (#891).
	 *
	 * Elementor's save() (core/base/document.php) always does two things after
	 * writing elements: deletes the post's CSS file (`Post_CSS::create( $id )
	 * ->delete()`) and deletes the post's render cache
	 * (`delete_cache()`, i.e. the `_elementor_element_cache` meta —
	 * `\Elementor\Core\Base\Document::CACHE_META_KEY`). A write that bypasses
	 * Document::save() and only calls update_post_meta() on `_elementor_data`
	 * — find/replace, before this fix — leaves both stale.
	 *
	 * The element cache is the one that actually served stale content: when
	 * Elementor's element-caching module is enabled
	 * (`elementor_element_cache_ttl` not `disable`), `Document::print_elements()`
	 * serves this cached copy verbatim until its TTL lapses, regardless of
	 * what `_elementor_data` now says. That is why the bug reproduced as a
	 * "written: true" response with the live page still serving the pre-write
	 * text, fixable only by the site-wide `wp_regenerate_elementor_css`, whose
	 * no-id path calls `Files_Manager::clear_cache()` — which deletes this
	 * same meta key across every post (core/files/manager.php).
	 *
	 * The meta key is a literal string, not the class constant: this trait's
	 * callers must not require `\Elementor\Core\Base\Document` to be
	 * autoloadable by name, only `\Elementor\Plugin` (already the class_exists
	 * guard every other Elementor call in this codebase uses), and the key is
	 * Elementor's own stable public contract — `Files_Manager::clear_cache()`
	 * deletes it site-wide by this exact literal.
	 *
	 * @param int $page_id Post ID.
	 */
	public function invalidate_document_cache( $page_id ) {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$post_css = \Elementor\Core\Files\CSS\Post::create( $page_id );
			if ( $post_css ) {
				$post_css->delete();
			}
		}

		// \Elementor\Core\Base\Document::CACHE_META_KEY.
		delete_post_meta( $page_id, '_elementor_element_cache' );

		clean_post_cache( $page_id );
	}

	/**
	 * Regenerate Elementor CSS for a specific page or the entire site.
	 *
	 * @param int|null $page_id Page ID, or null for full site regeneration.
	 * @param bool     $force   If true, delete existing CSS files before regenerating.
	 * @return array|WP_Error Result or error.
	 */
	public function regenerate_css( $page_id = null, $force = false ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return new WP_Error(
				'elementor_not_loaded',
				__( 'Elementor plugin class not available.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		$plugin = \Elementor\Plugin::$instance;
		$upload_dir = wp_upload_dir();
		$css_dir    = $upload_dir['basedir'] . '/elementor/css/';

		if ( $page_id ) {
			$page_id = absint( $page_id );
			$page    = get_post( $page_id );

			if ( ! $page ) {
				return new WP_Error(
					'not_found',
					__( 'Page not found.', 'mcpwp' ),
					array( 'status' => 404 )
				);
			}

			$method = 'cache_clear';

			// Clear this page's element-render cache regardless of which branch
			// below runs (#891). Before this, only the no-id site-wide path —
			// via files_manager->clear_cache() — ever cleared
			// `_elementor_element_cache`, so an id + force=true call here
			// reported "Cache cleared" or "CSS regenerated" while a page with
			// element caching enabled kept serving the pre-clear render until
			// its TTL lapsed.
			delete_post_meta( $page_id, '_elementor_element_cache' );

			// Force: delete existing CSS files before regenerating.
			if ( $force ) {
				delete_post_meta( $page_id, '_elementor_css' );
				$old_css_path = $css_dir . 'post-' . $page_id . '.css';
				if ( file_exists( $old_css_path ) ) {
					wp_delete_file( $old_css_path );
				}
			}

			// Regenerate CSS for specific post.
			if ( ! empty( $plugin->documents ) ) {
				$document = $plugin->documents->get( $page_id );
				if ( $document ) {
					$css_file = \Elementor\Core\Files\CSS\Post::create( $page_id );
					$css_file->update();
					$method = 'css_regenerated';
				}
			}

			if ( 'cache_clear' === $method ) {
				$plugin->files_manager->clear_cache();
			}

			// Check resulting CSS file.
			$css_path = $css_dir . 'post-' . $page_id . '.css';
			$css_size = file_exists( $css_path ) ? filesize( $css_path ) : 0;

			// If CSS is empty/tiny, delete meta to force frontend regeneration and prime it.
			$css_deferred = false;
			$css_primed   = false;
			if ( $css_size < 10 && 'css_regenerated' === $method ) {
				delete_post_meta( $page_id, '_elementor_css' );
				$css_deferred = true;
				$permalink = get_permalink( $page_id );
				if ( $permalink ) {
					wp_remote_get(
						add_query_arg( 'mcpwp_prime_css', wp_rand(), $permalink ),
						array(
							'timeout'   => 15,
							'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
							'blocking'  => false,
						)
					);
					$css_primed = true;
				}
			}

			$has_elementor_data = ! empty( get_post_meta( $page_id, '_elementor_data', true ) );

			$result = array(
				'success'      => true,
				'page_id'      => $page_id,
				'title'        => get_the_title( $page_id ),
				'method'       => $method,
				'force'        => $force,
				'css_file'     => 'post-' . $page_id . '.css',
				'css_size'     => $css_size,
				'css_deferred' => $css_deferred,
				'css_primed'   => $css_primed,
			);

			if ( 'css_regenerated' === $method ) {
				$result['regenerated'] = array( $page_id );
				$result['skipped']     = array();
				$result['message']     = __( 'CSS regenerated for page.', 'mcpwp' );
			} else {
				$result['regenerated'] = array();
				$reason = ! $has_elementor_data ? 'no_elementor_data' : 'document_not_found';
				$result['skipped']     = array(
					array(
						'page_id' => $page_id,
						'reason'  => $reason,
					),
				);
				$result['message'] = __( 'Elementor cache cleared (document not found, CSS will regenerate on next page load).', 'mcpwp' );
			}

			return $result;
		}

		// Full site CSS regeneration — find all Elementor posts first.
		$elementor_posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page', 'elementor_library', 'elementor_snippet' ),
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'meta_key'       => '_elementor_data',
				'posts_per_page' => 200,
				'fields'         => 'ids',
			)
		);

		$plugin->files_manager->clear_cache();

		// Force: delete all existing CSS files.
		if ( $force ) {
			foreach ( $elementor_posts as $pid ) {
				delete_post_meta( $pid, '_elementor_css' );
				$old_css_path = $css_dir . 'post-' . $pid . '.css';
				if ( file_exists( $old_css_path ) ) {
					wp_delete_file( $old_css_path );
				}
			}
		}

		$regenerated = array();
		$skipped     = array();
		$failed      = array();

		foreach ( $elementor_posts as $pid ) {
			if ( ! empty( $plugin->documents ) ) {
				$document = $plugin->documents->get( $pid );
				if ( ! $document ) {
					$skipped[] = array(
						'id'     => $pid,
						'title'  => get_the_title( $pid ),
						'reason' => 'Elementor document not found — page has _elementor_data meta but Elementor cannot load it as a document',
					);
					continue;
				}

				// When not forcing, check if CSS file already exists and is fresh.
				if ( ! $force ) {
					$existing_css_path = $css_dir . 'post-' . $pid . '.css';
					$css_meta          = get_post_meta( $pid, '_elementor_css', true );
					$post_modified     = get_post_modified_time( 'U', true, $pid );

					if ( file_exists( $existing_css_path ) && filesize( $existing_css_path ) > 10 && ! empty( $css_meta ) ) {
						// CSS meta stores the timestamp when CSS was last generated.
						$css_time = is_array( $css_meta ) && isset( $css_meta['time'] ) ? (int) $css_meta['time'] : 0;
						if ( $css_time > 0 && $css_time >= $post_modified ) {
							$skipped[] = array(
								'id'       => $pid,
								'title'    => get_the_title( $pid ),
								'reason'   => 'CSS already up-to-date (generated after last post modification). Use force=true to regenerate anyway.',
								'css_file' => 'post-' . $pid . '.css',
								'css_size' => filesize( $existing_css_path ),
							);
							continue;
						}
					}
				}

				try {
					$css_file = \Elementor\Core\Files\CSS\Post::create( $pid );
					$css_file->update();

					$css_path = $css_dir . 'post-' . $pid . '.css';
					$css_size = file_exists( $css_path ) ? filesize( $css_path ) : 0;

					$regen_entry = array(
						'id'       => $pid,
						'title'    => get_the_title( $pid ),
						'css_file' => 'post-' . $pid . '.css',
						'css_size' => $css_size,
					);

					// If CSS is empty/tiny, delete meta to force frontend regeneration and prime it.
					if ( $css_size < 10 ) {
						delete_post_meta( $pid, '_elementor_css' );
						$regen_entry['css_deferred'] = true;
						$permalink = get_permalink( $pid );
						if ( $permalink ) {
							wp_remote_get(
								add_query_arg( 'mcpwp_prime_css', wp_rand(), $permalink ),
								array(
									'timeout'   => 15,
									'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
									'blocking'  => false,
								)
							);
							$regen_entry['css_primed'] = true;
						}
					}

					$regenerated[] = $regen_entry;
				} catch ( \Exception $e ) {
					$failed[] = array(
						'id'    => $pid,
						'title' => get_the_title( $pid ),
						'error' => $e->getMessage(),
					);
				}
			}
		}

		// Regenerate the global Elementor kit CSS.
		$global_kit_regenerated = false;
		if ( method_exists( $plugin, 'kits_manager' ) ) {
			$kit_id = $plugin->kits_manager->get_active_id();
			if ( $kit_id ) {
				try {
					$kit_css = \Elementor\Core\Files\CSS\Post::create( $kit_id );
					$kit_css->update();
					$global_kit_regenerated = true;
				} catch ( \Exception $e ) {
					// Kit CSS regeneration failed — not critical.
					$global_kit_regenerated = false;
				}
			}
		}

		$result = array(
			'success'                => true,
			'force'                  => $force,
			'global_kit_regenerated' => $global_kit_regenerated,
			'total_pages'            => count( $elementor_posts ),
			'regenerated_count'      => count( $regenerated ),
			'skipped_count'          => count( $skipped ),
			'failed_count'           => count( $failed ),
			'regenerated'            => $regenerated,
			'skipped'                => $skipped,
			'failed'                 => $failed,
			'message'                => sprintf(
				/* translators: 1: regenerated count 2: total found 3: skipped count 4: failed count 5: global kit status */
				__( 'CSS regenerated for %1$d of %2$d Elementor pages (%3$d skipped, %4$d failed). Global kit CSS: %5$s. Cache cleared.', 'mcpwp' ),
				count( $regenerated ),
				count( $elementor_posts ),
				count( $skipped ),
				count( $failed ),
				$global_kit_regenerated ? 'regenerated' : 'not regenerated'
			),
		);

		return $result;
	}
}
