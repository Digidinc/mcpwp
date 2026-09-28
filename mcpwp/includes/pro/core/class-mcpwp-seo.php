<?php
/**
 * SEO Handler
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO functionality.
 *
 * Provides unified interface for Yoast SEO, RankMath, AIOSEO, and SEOPress.
 */
class Mcpwp_SEO {

	/**
	 * Check if Yoast SEO is active.
	 *
	 * @return bool
	 */
	public function is_yoast_active() {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Check if RankMath is active.
	 *
	 * @return bool
	 */
	public function is_rankmath_active() {
		return class_exists( 'RankMath' );
	}

	/**
	 * Check if AIOSEO is active.
	 *
	 * @return bool
	 */
	public function is_aioseo_active() {
		return defined( 'AIOSEO_VERSION' );
	}

	/**
	 * Check if SEOPress is active.
	 *
	 * @return bool
	 */
	public function is_seopress_active() {
		return defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Get active SEO plugin.
	 *
	 * @return string|null Plugin identifier or null.
	 */
	public function get_active_plugin() {
		if ( $this->is_yoast_active() ) {
			return 'yoast';
		}
		if ( $this->is_rankmath_active() ) {
			return 'rankmath';
		}
		if ( $this->is_aioseo_active() ) {
			return 'aioseo';
		}
		if ( $this->is_seopress_active() ) {
			return 'seopress';
		}
		return null;
	}

	/**
	 * Get SEO status and detected plugins.
	 *
	 * @return array Status information.
	 */
	public function get_status() {
		$active = $this->get_active_plugin();

		return array(
			'active_plugin' => $active,
			'plugins'       => array(
				'yoast'    => array(
					'active'  => $this->is_yoast_active(),
					'version' => $this->is_yoast_active() ? WPSEO_VERSION : null,
				),
				'rankmath' => array(
					'active'  => $this->is_rankmath_active(),
					'version' => $this->is_rankmath_active() && defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : null,
				),
				'aioseo'   => array(
					'active'  => $this->is_aioseo_active(),
					'version' => $this->is_aioseo_active() ? AIOSEO_VERSION : null,
				),
				'seopress' => array(
					'active'  => $this->is_seopress_active(),
					'version' => $this->is_seopress_active() ? SEOPRESS_VERSION : null,
				),
			),
		);
	}

	/**
	 * Get SEO data for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $plugin  Optional. Force specific plugin.
	 * @return array|WP_Error SEO data or error.
	 */
	public function get_post_seo( $post_id, $plugin = null ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'mcpwp' ) );
		}

		$plugin = $plugin ?: $this->get_active_plugin();

		if ( ! $plugin ) {
			return new WP_Error( 'no_seo_plugin', __( 'No SEO plugin is active.', 'mcpwp' ) );
		}

		$method = 'get_' . $plugin . '_data';
		if ( method_exists( $this, $method ) ) {
			return $this->$method( $post_id );
		}

		return new WP_Error( 'unsupported_plugin', __( 'SEO plugin not supported.', 'mcpwp' ) );
	}

	/**
	 * Update SEO data for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param array  $data    SEO data.
	 * @param string $plugin  Optional. Force specific plugin.
	 * @return array|WP_Error Updated data or error.
	 */
	public function update_post_seo( $post_id, $data, $plugin = null ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'mcpwp' ) );
		}

		$plugin = $plugin ?: $this->get_active_plugin();

		if ( ! $plugin ) {
			return new WP_Error( 'no_seo_plugin', __( 'No SEO plugin is active.', 'mcpwp' ) );
		}

		$method = 'set_' . $plugin . '_data';
		if ( method_exists( $this, $method ) ) {
			return $this->$method( $post_id, $data );
		}

		return new WP_Error( 'unsupported_plugin', __( 'SEO plugin not supported.', 'mcpwp' ) );
	}

	/**
	 * Get Yoast SEO data.
	 *
	 * @param int $post_id Post ID.
	 * @return array SEO data.
	 */
	private function get_yoast_data( $post_id ) {
		$noindex_raw  = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		$nofollow_raw = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );

		return array(
			'plugin'          => 'yoast',
			'title'           => get_post_meta( $post_id, '_yoast_wpseo_title', true ),
			'description'     => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
			'focus_keyword'   => get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
			'canonical'       => get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
			'og_title'        => get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true ),
			'og_description'  => get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true ),
			'og_image'        => get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ),
			'twitter_title'   => get_post_meta( $post_id, '_yoast_wpseo_twitter-title', true ),
			'twitter_description' => get_post_meta( $post_id, '_yoast_wpseo_twitter-description', true ),
			'twitter_image'   => get_post_meta( $post_id, '_yoast_wpseo_twitter-image', true ),
			'noindex'         => ( '1' === $noindex_raw ),
			'nofollow'        => ( '1' === $nofollow_raw ),
			'robots_index'    => $noindex_raw,
			'robots_follow'   => $nofollow_raw,
			'schema_type'     => get_post_meta( $post_id, '_yoast_wpseo_schema_page_type', true ),
			'cornerstone'     => get_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', true ),
		);
	}

	/**
	 * Set Yoast SEO data.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    SEO data.
	 * @return array Updated data.
	 */
	private function set_yoast_data( $post_id, $data ) {
		// Normalize aliases: noindex/nofollow -> robots_noindex/robots_nofollow.
		if ( array_key_exists( 'noindex', $data ) && ! array_key_exists( 'robots_noindex', $data ) ) {
			$data['robots_noindex'] = $data['noindex'];
			unset( $data['noindex'] );
		}
		if ( array_key_exists( 'nofollow', $data ) && ! array_key_exists( 'robots_nofollow', $data ) ) {
			$data['robots_nofollow'] = $data['nofollow'];
			unset( $data['nofollow'] );
		}

		$meta_map = array(
			'title'           => '_yoast_wpseo_title',
			'description'     => '_yoast_wpseo_metadesc',
			'focus_keyword'   => '_yoast_wpseo_focuskw',
			'canonical'       => '_yoast_wpseo_canonical',
			'og_title'        => '_yoast_wpseo_opengraph-title',
			'og_description'  => '_yoast_wpseo_opengraph-description',
			'og_image'        => '_yoast_wpseo_opengraph-image',
			'twitter_title'   => '_yoast_wpseo_twitter-title',
			'twitter_description' => '_yoast_wpseo_twitter-description',
			'twitter_image'   => '_yoast_wpseo_twitter-image',
			'robots_noindex'  => '_yoast_wpseo_meta-robots-noindex',
			'robots_nofollow' => '_yoast_wpseo_meta-robots-nofollow',
			'schema_type'     => '_yoast_wpseo_schema_page_type',
			'cornerstone'     => '_yoast_wpseo_is_cornerstone',
		);

		// Boolean fields that map to '1' or '' in Yoast.
		$boolean_fields = array( 'robots_noindex', 'robots_nofollow', 'cornerstone' );

		foreach ( $data as $key => $value ) {
			if ( isset( $meta_map[ $key ] ) ) {
				// Convert booleans to Yoast format ('1' or delete).
				if ( in_array( $key, $boolean_fields, true ) ) {
					if ( $value && '0' !== $value ) {
						update_post_meta( $post_id, $meta_map[ $key ], '1' );
					} else {
						delete_post_meta( $post_id, $meta_map[ $key ] );
					}
				} elseif ( empty( $value ) && ! is_numeric( $value ) ) {
					delete_post_meta( $post_id, $meta_map[ $key ] );
				} else {
					update_post_meta( $post_id, $meta_map[ $key ], sanitize_text_field( $value ) );
				}
			}
		}

		return $this->get_yoast_data( $post_id );
	}

	/**
	 * Get RankMath SEO data.
	 *
	 * @param int $post_id Post ID.
	 * @return array SEO data.
	 */
	private function get_rankmath_data( $post_id ) {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots = is_array( $robots ) ? $robots : array();

		return array(
			'plugin'          => 'rankmath',
			'title'           => get_post_meta( $post_id, 'rank_math_title', true ),
			'description'     => get_post_meta( $post_id, 'rank_math_description', true ),
			'focus_keyword'   => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
			'canonical'       => get_post_meta( $post_id, 'rank_math_canonical_url', true ),
			'og_title'        => get_post_meta( $post_id, 'rank_math_facebook_title', true ),
			'og_description'  => get_post_meta( $post_id, 'rank_math_facebook_description', true ),
			'og_image'        => get_post_meta( $post_id, 'rank_math_facebook_image', true ),
			'twitter_title'   => get_post_meta( $post_id, 'rank_math_twitter_title', true ),
			'twitter_description' => get_post_meta( $post_id, 'rank_math_twitter_description', true ),
			'twitter_image'   => get_post_meta( $post_id, 'rank_math_twitter_image', true ),
			'noindex'         => in_array( 'noindex', $robots, true ),
			'nofollow'        => in_array( 'nofollow', $robots, true ),
			'robots'          => $robots,
			'schema_type'     => get_post_meta( $post_id, 'rank_math_rich_snippet', true ),
			'pillar_content'  => get_post_meta( $post_id, 'rank_math_pillar_content', true ),
			'seo_score'       => get_post_meta( $post_id, 'rank_math_seo_score', true ),
		);
	}

	/**
	 * Set RankMath SEO data.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    SEO data.
	 * @return array Updated data.
	 */
	private function set_rankmath_data( $post_id, $data ) {
		$meta_map = array(
			'title'           => 'rank_math_title',
			'description'     => 'rank_math_description',
			'focus_keyword'   => 'rank_math_focus_keyword',
			'canonical'       => 'rank_math_canonical_url',
			'og_title'        => 'rank_math_facebook_title',
			'og_description'  => 'rank_math_facebook_description',
			'og_image'        => 'rank_math_facebook_image',
			'twitter_title'   => 'rank_math_twitter_title',
			'twitter_description' => 'rank_math_twitter_description',
			'twitter_image'   => 'rank_math_twitter_image',
			'schema_type'     => 'rank_math_rich_snippet',
			'pillar_content'  => 'rank_math_pillar_content',
		);

		// Handle noindex/nofollow booleans by updating the robots array.
		if ( isset( $data['noindex'] ) || isset( $data['nofollow'] ) || isset( $data['robots_noindex'] ) || isset( $data['robots_nofollow'] ) ) {
			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			$robots = is_array( $robots ) ? $robots : array();

			$noindex  = $data['noindex'] ?? $data['robots_noindex'] ?? null;
			$nofollow = $data['nofollow'] ?? $data['robots_nofollow'] ?? null;

			if ( null !== $noindex ) {
				$robots = array_diff( $robots, array( 'noindex', 'index' ) );
				$robots[] = $noindex ? 'noindex' : 'index';
			}
			if ( null !== $nofollow ) {
				$robots = array_diff( $robots, array( 'nofollow', 'follow' ) );
				$robots[] = $nofollow ? 'nofollow' : 'follow';
			}

			update_post_meta( $post_id, 'rank_math_robots', array_values( array_unique( $robots ) ) );
		}

		// Handle robots separately (array).
		if ( isset( $data['robots'] ) ) {
			$robots = is_array( $data['robots'] ) ? $data['robots'] : array( $data['robots'] );
			update_post_meta( $post_id, 'rank_math_robots', $robots );
		}

		foreach ( $data as $key => $value ) {
			if ( isset( $meta_map[ $key ] ) ) {
				if ( empty( $value ) ) {
					delete_post_meta( $post_id, $meta_map[ $key ] );
				} else {
					update_post_meta( $post_id, $meta_map[ $key ], sanitize_text_field( $value ) );
				}
			}
		}

		return $this->get_rankmath_data( $post_id );
	}

	/**
	 * Get AIOSEO data.
	 *
	 * @param int $post_id Post ID.
	 * @return array SEO data.
	 */
	private function get_aioseo_data( $post_id ) {
		global $wpdb;

		// AIOSEO stores data in a separate table.
		$table = $wpdb->prefix . 'aioseo_posts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$aioseo_data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE post_id = %d",
				$post_id
			),
			ARRAY_A
		);

		if ( ! $aioseo_data ) {
			return array(
				'plugin'          => 'aioseo',
				'title'           => '',
				'description'     => '',
				'focus_keyword'   => '',
				'canonical'       => '',
				'og_title'        => '',
				'og_description'  => '',
				'og_image'        => '',
				'twitter_title'   => '',
				'twitter_description' => '',
				'twitter_image'   => '',
			);
		}

		return array(
			'plugin'          => 'aioseo',
			'title'           => $aioseo_data['title'] ?? '',
			'description'     => $aioseo_data['description'] ?? '',
			'focus_keyword'   => $aioseo_data['keyphrases'] ?? '',
			'canonical'       => $aioseo_data['canonical_url'] ?? '',
			'og_title'        => $aioseo_data['og_title'] ?? '',
			'og_description'  => $aioseo_data['og_description'] ?? '',
			'og_image'        => $aioseo_data['og_image_custom_url'] ?? '',
			'twitter_title'   => $aioseo_data['twitter_title'] ?? '',
			'twitter_description' => $aioseo_data['twitter_description'] ?? '',
			'twitter_image'   => $aioseo_data['twitter_image_custom_url'] ?? '',
			'robots_noindex'  => $aioseo_data['robots_noindex'] ?? false,
			'robots_nofollow' => $aioseo_data['robots_nofollow'] ?? false,
			'seo_score'       => $aioseo_data['seo_score'] ?? 0,
		);
	}

	/**
	 * Set AIOSEO data.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    SEO data.
	 * @return array Updated data.
	 */
	private function set_aioseo_data( $post_id, $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'aioseo_posts';

		// Canonicalize the noindex/nofollow aliases onto robots_* so both the
		// write and the readback verification below operate on one key.
		if ( isset( $data['noindex'] ) && ! isset( $data['robots_noindex'] ) ) {
			$data['robots_noindex'] = $data['noindex'];
		}
		if ( isset( $data['nofollow'] ) && ! isset( $data['robots_nofollow'] ) ) {
			$data['robots_nofollow'] = $data['nofollow'];
		}
		unset( $data['noindex'], $data['nofollow'] );

		// Check if row exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE post_id = %d",
				$post_id
			)
		);

		$db_data = array(
			'post_id' => $post_id,
		);

		$field_map = array(
			'title'           => 'title',
			'description'     => 'description',
			'focus_keyword'   => 'keyphrases',
			'canonical'       => 'canonical_url',
			'og_title'        => 'og_title',
			'og_description'  => 'og_description',
			'og_image'        => 'og_image_custom_url',
			'twitter_title'   => 'twitter_title',
			'twitter_description' => 'twitter_description',
			'twitter_image'   => 'twitter_image_custom_url',
			'robots_noindex'  => 'robots_noindex',
			'robots_nofollow' => 'robots_nofollow',
		);

		$robots_provided = false;
		foreach ( $data as $key => $value ) {
			if ( ! isset( $field_map[ $key ] ) ) {
				continue;
			}
			$column = $field_map[ $key ];
			if ( 'robots_noindex' === $column || 'robots_nofollow' === $column ) {
				// Robots columns are tinyint flags, not text.
				$db_data[ $column ] = $this->to_bool_flag( $value );
				$robots_provided    = true;
			} else {
				$db_data[ $column ] = sanitize_text_field( $value );
			}
		}

		// AIOSEO ignores the per-post robots_noindex/robots_nofollow flags unless
		// robots_default is disabled; without this the values are stored but never
		// take effect and read back as 0 (GH #672).
		if ( $robots_provided ) {
			$db_data['robots_default'] = 0;
		}

		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$written = $wpdb->update( $table, $db_data, array( 'post_id' => $post_id ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$written = $wpdb->insert( $table, $db_data );
		}

		if ( false === $written ) {
			return new WP_Error(
				'seo_write_failed',
				__( 'The SEO integration rejected the write.', 'mcpwp' ),
				array( 'db_error' => $wpdb->last_error )
			);
		}

		// Read back and verify the submitted fields actually persisted, so the
		// caller cannot mistake an echoed request for a stored update (GH #672).
		$stored      = $this->get_aioseo_data( $post_id );
		$unpersisted = array();
		foreach ( $data as $key => $value ) {
			if ( ! isset( $field_map[ $key ] ) || ! array_key_exists( $key, $stored ) ) {
				continue;
			}
			$column = $field_map[ $key ];
			if ( 'robots_noindex' === $column || 'robots_nofollow' === $column ) {
				if ( $this->to_bool_flag( $value ) !== $this->to_bool_flag( $stored[ $key ] ) ) {
					$unpersisted[ $key ] = $stored[ $key ];
				}
			} elseif ( sanitize_text_field( $value ) !== (string) $stored[ $key ] ) {
				$unpersisted[ $key ] = $stored[ $key ];
			}
		}

		if ( ! empty( $unpersisted ) ) {
			return new WP_Error(
				'seo_not_persisted',
				__( 'The SEO plugin did not persist one or more submitted fields.', 'mcpwp' ),
				array(
					'unpersisted_fields' => array_keys( $unpersisted ),
					'stored'             => $stored,
				)
			);
		}

		return $stored;
	}

	/**
	 * Normalize a truthy/falsey SEO flag to an integer 1/0.
	 *
	 * @param mixed $value Raw flag value (bool, int, or string).
	 * @return int 1 when truthy, 0 otherwise.
	 */
	private function to_bool_flag( $value ) {
		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			if ( '' === $value || 'false' === $value || '0' === $value || 'no' === $value ) {
				return 0;
			}
			return 1;
		}
		return $value ? 1 : 0;
	}

	/**
	 * Get SEOPress data.
	 *
	 * @param int $post_id Post ID.
	 * @return array SEO data.
	 */
	private function get_seopress_data( $post_id ) {
		return array(
			'plugin'          => 'seopress',
			'title'           => get_post_meta( $post_id, '_seopress_titles_title', true ),
			'description'     => get_post_meta( $post_id, '_seopress_titles_desc', true ),
			'focus_keyword'   => get_post_meta( $post_id, '_seopress_analysis_target_kw', true ),
			'canonical'       => get_post_meta( $post_id, '_seopress_robots_canonical', true ),
			'og_title'        => get_post_meta( $post_id, '_seopress_social_fb_title', true ),
			'og_description'  => get_post_meta( $post_id, '_seopress_social_fb_desc', true ),
			'og_image'        => get_post_meta( $post_id, '_seopress_social_fb_img', true ),
			'twitter_title'   => get_post_meta( $post_id, '_seopress_social_twitter_title', true ),
			'twitter_description' => get_post_meta( $post_id, '_seopress_social_twitter_desc', true ),
			'twitter_image'   => get_post_meta( $post_id, '_seopress_social_twitter_img', true ),
			'robots_noindex'  => get_post_meta( $post_id, '_seopress_robots_index', true ),
			'robots_nofollow' => get_post_meta( $post_id, '_seopress_robots_follow', true ),
			'primary_category' => get_post_meta( $post_id, '_seopress_robots_primary_cat', true ),
		);
	}

	/**
	 * Set SEOPress data.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    SEO data.
	 * @return array Updated data.
	 */
	private function set_seopress_data( $post_id, $data ) {
		$meta_map = array(
			'title'           => '_seopress_titles_title',
			'description'     => '_seopress_titles_desc',
			'focus_keyword'   => '_seopress_analysis_target_kw',
			'canonical'       => '_seopress_robots_canonical',
			'og_title'        => '_seopress_social_fb_title',
			'og_description'  => '_seopress_social_fb_desc',
			'og_image'        => '_seopress_social_fb_img',
			'twitter_title'   => '_seopress_social_twitter_title',
			'twitter_description' => '_seopress_social_twitter_desc',
			'twitter_image'   => '_seopress_social_twitter_img',
			'robots_noindex'  => '_seopress_robots_index',
			'robots_nofollow' => '_seopress_robots_follow',
			'primary_category' => '_seopress_robots_primary_cat',
		);

		foreach ( $data as $key => $value ) {
			if ( isset( $meta_map[ $key ] ) ) {
				if ( empty( $value ) ) {
					delete_post_meta( $post_id, $meta_map[ $key ] );
				} else {
					update_post_meta( $post_id, $meta_map[ $key ], sanitize_text_field( $value ) );
				}
			}
		}

		return $this->get_seopress_data( $post_id );
	}

	/**
	 * Bulk update SEO for multiple posts.
	 *
	 * @param array $updates Array of [ 'post_id' => ID, 'data' => SEO data ].
	 * @return array Results for each post.
	 */
	public function bulk_update( $updates ) {
		$results = array();

		// SEO fields that can appear at the top level of each update item.
		$seo_fields = array(
			'title', 'description', 'focus_keyword', 'canonical', 'canonical_url',
			'noindex', 'nofollow', 'robots_noindex', 'robots_nofollow',
			'og_title', 'og_description', 'og_image',
			'twitter_title', 'twitter_description', 'twitter_image',
		);

		foreach ( $updates as $update ) {
			// Accept 'id' as alias for 'post_id'.
			if ( ! empty( $update['id'] ) && empty( $update['post_id'] ) ) {
				$update['post_id'] = $update['id'];
			}

			// If flat SEO fields exist (no 'data' wrapper), collect them into 'data'.
			if ( ! isset( $update['data'] ) ) {
				$data = array();
				foreach ( $seo_fields as $field ) {
					if ( array_key_exists( $field, $update ) ) {
						$data[ $field ] = $update[ $field ];
					}
				}
				$update['data'] = $data;
			}

			// Normalize field aliases in data.
			$data = $update['data'];
			if ( isset( $data['canonical_url'] ) && ! isset( $data['canonical'] ) ) {
				$data['canonical'] = $data['canonical_url'];
				unset( $data['canonical_url'] );
			}

			$post_id = absint( $update['post_id'] ?? 0 );

			$result = $this->update_post_seo( $post_id, $data );

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'post_id' => $post_id,
					'success' => false,
					'error'   => $result->get_error_message(),
				);
			} else {
				$results[] = array(
					'post_id' => $post_id,
					'success' => true,
					'data'    => $result,
				);
			}
		}

		return $results;
	}

	/**
	 * Analyze SEO for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array Analysis results.
	 */
	public function analyze_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Post not found.', 'mcpwp' ) );
		}

		$seo_data = $this->get_post_seo( $post_id );
		// #1024/#1030 round-2 review, BLOCK-A: get_post_seo() returns a
		// WP_Error (no_seo_plugin) when no SEO plugin is active. Every
		// `$seo_data['...']` access below fatals on a WP_Error (it carries
		// no ArrayAccess) — "Post not found" vs "No SEO plugin is active"
		// used to be the difference between a 500 and a clean answer,
		// itself an existence-adjacent information leak. Treated as "no
		// stored SEO data" so the analysis still runs against the post's
		// own title/content.
		if ( is_wp_error( $seo_data ) ) {
			$seo_data = array();
		}
		$analysis = array(
			'post_id'      => $post_id,
			'title'        => $post->post_title,
			'issues'       => array(),
			'warnings'     => array(),
			'suggestions'  => array(),
		);

		// Check title length.
		$title = ! empty( $seo_data['title'] ) ? $seo_data['title'] : $post->post_title;
		$title_len = strlen( $title );
		if ( $title_len < 30 ) {
			$analysis['warnings'][] = __( 'SEO title is too short (under 30 characters).', 'mcpwp' );
		} elseif ( $title_len > 60 ) {
			$analysis['warnings'][] = __( 'SEO title is too long (over 60 characters).', 'mcpwp' );
		}

		// Check meta description.
		$desc = $seo_data['description'] ?? '';
		if ( empty( $desc ) ) {
			$analysis['issues'][] = __( 'Missing meta description.', 'mcpwp' );
		} else {
			$desc_len = strlen( $desc );
			if ( $desc_len < 120 ) {
				$analysis['warnings'][] = __( 'Meta description is too short (under 120 characters).', 'mcpwp' );
			} elseif ( $desc_len > 160 ) {
				$analysis['warnings'][] = __( 'Meta description is too long (over 160 characters).', 'mcpwp' );
			}
		}

		// Check focus keyword.
		$keyword = $seo_data['focus_keyword'] ?? '';
		if ( empty( $keyword ) ) {
			$analysis['suggestions'][] = __( 'Consider adding a focus keyword.', 'mcpwp' );
		} else {
			// Check if keyword is in title.
			if ( stripos( $title, $keyword ) === false ) {
				$analysis['warnings'][] = __( 'Focus keyword not found in SEO title.', 'mcpwp' );
			}
			// Check if keyword is in description.
			if ( ! empty( $desc ) && stripos( $desc, $keyword ) === false ) {
				$analysis['suggestions'][] = __( 'Consider adding focus keyword to meta description.', 'mcpwp' );
			}
		}

		// Check content length.
		$content_len = str_word_count( wp_strip_all_tags( $post->post_content ) );
		if ( $content_len < 300 ) {
			$analysis['warnings'][] = sprintf(
				/* translators: %d: word count */
				__( 'Content is short (%d words). Consider expanding to at least 300 words.', 'mcpwp' ),
				$content_len
			);
		}

		// Score calculation.
		$score = 100;
		$score -= count( $analysis['issues'] ) * 20;
		$score -= count( $analysis['warnings'] ) * 10;
		$score -= count( $analysis['suggestions'] ) * 5;
		$analysis['score'] = max( 0, $score );

		return $analysis;
	}

	/**
	 * Scan all published content for SEO issues.
	 *
	 * @param int $threshold Minimum word count for thin content detection.
	 * @return array Array of posts with their SEO issues.
	 */
	public function scan_all( $threshold = 300 ) {
		$threshold = max( 1, absint( $threshold ) );

		$query = new WP_Query(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			)
		);

		$results      = array();
		$descriptions = array();

		foreach ( $query->posts as $post_id ) {
			$post     = get_post( $post_id );
			$seo_data = $this->get_post_seo( $post_id );
			$issues   = array();

			// Get SEO title.
			$seo_title = '';
			if ( ! is_wp_error( $seo_data ) ) {
				$seo_title = $seo_data['title'] ?? '';
			}

			if ( empty( $seo_title ) ) {
				$issues[] = array(
					'type'     => 'missing_title',
					'severity' => 'high',
					'message'  => 'Missing SEO title.',
				);
			}

			// Check meta description.
			$description = '';
			if ( ! is_wp_error( $seo_data ) ) {
				$description = $seo_data['description'] ?? '';
			}

			if ( empty( $description ) ) {
				$issues[] = array(
					'type'     => 'missing_description',
					'severity' => 'high',
					'message'  => 'Missing meta description.',
				);
			} else {
				$descriptions[ $post_id ] = $description;
			}

			// Check thin content.
			$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
			if ( $word_count < $threshold ) {
				$issues[] = array(
					'type'     => 'thin_content',
					'severity' => 'medium',
					'message'  => sprintf( 'Thin content: %d words (threshold: %d).', $word_count, $threshold ),
				);
			}

			// Check focus keyword.
			$focus_keyword = '';
			if ( ! is_wp_error( $seo_data ) ) {
				$focus_keyword = $seo_data['focus_keyword'] ?? '';
			}

			if ( empty( $focus_keyword ) ) {
				$issues[] = array(
					'type'     => 'missing_focus_keyword',
					'severity' => 'low',
					'message'  => 'No focus keyword set.',
				);
			}

			// Check noindex.
			$noindex = false;
			if ( ! is_wp_error( $seo_data ) ) {
				$noindex = ! empty( $seo_data['robots_index'] ) || ! empty( $seo_data['robots_noindex'] );
			}

			if ( $noindex ) {
				$issues[] = array(
					'type'     => 'noindex',
					'severity' => 'medium',
					'message'  => 'Page is set to noindex.',
				);
			}

			$results[] = array(
				'post_id'   => $post_id,
				'url'       => get_permalink( $post_id ),
				'title'     => $post->post_title,
				'post_type' => $post->post_type,
				'issues'    => $issues,
			);
		}

		// Second pass: detect duplicate descriptions.
		$desc_counts = array_count_values( $descriptions );
		foreach ( $results as &$result ) {
			$pid = $result['post_id'];
			if ( isset( $descriptions[ $pid ] ) && $desc_counts[ $descriptions[ $pid ] ] > 1 ) {
				$result['issues'][] = array(
					'type'     => 'duplicate_description',
					'severity' => 'medium',
					'message'  => 'Duplicate meta description shared with other posts.',
				);
			}
		}
		unset( $result );

		return $results;
	}

	/**
	 * Export complete SEO metadata report for all published content.
	 *
	 * @param string|null $post_type Optional. Filter by post type.
	 * @param int         $limit     Maximum number of posts to return.
	 * @return array Array of posts with their SEO metadata.
	 */
	public function export_report( $post_type = null, $limit = 100 ) {
		$limit = min( max( 1, absint( $limit ) ), 500 );

		$query_args = array(
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		);

		if ( ! empty( $post_type ) ) {
			$query_args['post_type'] = sanitize_text_field( $post_type );
		} else {
			$query_args['post_type'] = array( 'post', 'page' );
		}

		$query   = new WP_Query( $query_args );
		$results = array();

		foreach ( $query->posts as $post_id ) {
			$post     = get_post( $post_id );
			$seo_data = $this->get_post_seo( $post_id );

			$seo_title       = '';
			$description     = '';
			$focus_keyword   = '';
			$canonical       = '';
			$noindex         = false;

			if ( ! is_wp_error( $seo_data ) ) {
				$seo_title     = $seo_data['title'] ?? '';
				$description   = $seo_data['description'] ?? '';
				$focus_keyword = $seo_data['focus_keyword'] ?? '';
				$canonical     = $seo_data['canonical'] ?? '';
				$noindex       = ! empty( $seo_data['robots_index'] ) || ! empty( $seo_data['robots_noindex'] );
			}

			$results[] = array(
				'post_id'          => $post_id,
				'url'              => get_permalink( $post_id ),
				'title'            => $post->post_title,
				'post_type'        => $post->post_type,
				'word_count'       => str_word_count( wp_strip_all_tags( $post->post_content ) ),
				'seo_title'        => $seo_title,
				'meta_description' => $description,
				'focus_keyword'    => $focus_keyword,
				'noindex'          => $noindex,
				'canonical'        => $canonical,
				'last_modified'    => $post->post_modified,
			);
		}

		return $results;
	}
}
