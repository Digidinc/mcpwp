<?php
/**
 * SEO REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for SEO features.
 *
 * Provides unified endpoints for Yoast, RankMath, AIOSEO, and SEOPress.
 */
class Mcpwp_REST_SEO extends Mcpwp_REST_API {

	/**
	 * SEO handler.
	 *
	 * @var Mcpwp_SEO
	 */
	private $seo;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_SEO $seo SEO handler.
	 */
	public function __construct( $seo ) {
		$this->seo = $seo;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// SEO status.
		register_rest_route(
			$this->namespace,
			'/seo/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Get SEO for single post.
		register_rest_route(
			$this->namespace,
			'/seo/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_post_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Bulk update SEO.
		register_rest_route(
			$this->namespace,
			'/seo/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk_update' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Analyze SEO.
		register_rest_route(
			$this->namespace,
			'/seo/(?P<id>\d+)/analyze',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'analyze_post' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Plugin-specific endpoints.
		register_rest_route(
			$this->namespace,
			'/seo/yoast/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_yoast_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_yoast_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/seo/rankmath/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_rankmath_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_rankmath_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/seo/aioseo/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_aioseo_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_aioseo_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Set noindex convenience endpoint.
		register_rest_route(
			$this->namespace,
			'/seo/(?P<id>\d+)/noindex',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_noindex' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'noindex' => array(
						'description' => __( 'Whether to noindex the page.', 'mumcp' ),
						'type'        => 'boolean',
						'required'    => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/seo/seopress/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_seopress_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_seopress_seo' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// SEO scan — bulk audit.
		register_rest_route(
			$this->namespace,
			'/seo/scan',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'scan_all' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'threshold' => array(
						'description' => __( 'Minimum word count for thin content detection.', 'mumcp' ),
						'type'        => 'integer',
						'default'     => 300,
					),
				),
			)
		);

		// SEO report — export metadata.
		register_rest_route(
			$this->namespace,
			'/seo/report',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_report' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post_type' => array(
						'description' => __( 'Filter by post type.', 'mumcp' ),
						'type'        => 'string',
					),
					'limit' => array(
						'description' => __( 'Maximum number of posts to return.', 'mumcp' ),
						'type'        => 'integer',
						'default'     => 100,
					),
				),
			)
		);
	}

	/**
	 * Get SEO status.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_status( $request ) {
		$status = $this->seo->get_status();

		$this->log_activity( 'seo_status', $request, $status );

		return $this->success_response( $status );
	}

	/**
	 * Get SEO data for a post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_post_seo( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$plugin  = $request->get_param( 'plugin' );

		$result = $this->seo->get_post_seo( $post_id, $plugin );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'get_seo', $request, null, 400 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'get_seo', $request, $result );

		return $this->success_response( $result );
	}

	/**
	 * Update SEO data for a post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_post_seo( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$plugin  = $request->get_param( 'plugin' );

		$data = array(
			'title'           => $request->get_param( 'title' ),
			'description'     => $request->get_param( 'description' ),
			'focus_keyword'   => $request->get_param( 'focus_keyword' ),
			'canonical'       => $request->get_param( 'canonical' ),
			'og_title'        => $request->get_param( 'og_title' ),
			'og_description'  => $request->get_param( 'og_description' ),
			'og_image'        => $request->get_param( 'og_image' ),
			'twitter_title'   => $request->get_param( 'twitter_title' ),
			'twitter_description' => $request->get_param( 'twitter_description' ),
			'twitter_image'   => $request->get_param( 'twitter_image' ),
			'noindex'         => $request->get_param( 'noindex' ),
			'nofollow'        => $request->get_param( 'nofollow' ),
			'robots_noindex'  => $request->get_param( 'robots_noindex' ),
			'robots_nofollow' => $request->get_param( 'robots_nofollow' ),
		);

		// Remove null values.
		$data = array_filter( $data, function( $v ) {
			return $v !== null;
		} );

		$result = $this->seo->update_post_seo( $post_id, $data, $plugin );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'update_seo', $request, null, 400 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'update_seo', $request, $result );

		return $this->success_response( $result );
	}

	/**
	 * Bulk update SEO data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function bulk_update( $request ) {
		$updates = $request->get_param( 'updates' );

		if ( empty( $updates ) || ! is_array( $updates ) ) {
			return $this->error_response( 'invalid_data', __( 'Updates array is required.', 'mumcp' ), 400 );
		}

		// Normalize items: accept both MCP flat format {id, title, description, ...}
		// and legacy format {post_id, data: {title, description, ...}}.
		$seo_fields = array( 'title', 'description', 'focus_keyword', 'canonical_url', 'canonical',
			'noindex', 'nofollow', 'robots_noindex', 'robots_nofollow',
			'og_title', 'og_description', 'og_image',
			'twitter_title', 'twitter_description', 'twitter_image' );

		foreach ( $updates as $i => $item ) {
			// Accept 'id' as alias for 'post_id'.
			if ( isset( $item['id'] ) && ! isset( $item['post_id'] ) ) {
				$updates[ $i ]['post_id'] = $item['id'];
			}

			// If flat SEO fields are present (no 'data' wrapper), collect them into 'data'.
			if ( ! isset( $item['data'] ) ) {
				$data = array();
				foreach ( $seo_fields as $field ) {
					if ( isset( $updates[ $i ][ $field ] ) ) {
						$data[ $field ] = $updates[ $i ][ $field ];
					}
				}
				if ( ! empty( $data ) ) {
					$updates[ $i ]['data'] = $data;
				}
			}

			// Normalize field aliases in the data sub-array.
			if ( isset( $updates[ $i ]['data'] ) ) {
				if ( isset( $updates[ $i ]['data']['canonical_url'] ) && ! isset( $updates[ $i ]['data']['canonical'] ) ) {
					$updates[ $i ]['data']['canonical'] = $updates[ $i ]['data']['canonical_url'];
					unset( $updates[ $i ]['data']['canonical_url'] );
				}
			}
		}

		$results = $this->seo->bulk_update( $updates );

		$success_count = count( array_filter( $results, function( $r ) {
			return $r['success'];
		} ) );

		$this->log_activity( 'bulk_seo_update', $request, array(
			'total'   => count( $updates ),
			'success' => $success_count,
		) );

		return $this->success_response( array(
			'results'       => $results,
			'total'         => count( $updates ),
			'success_count' => $success_count,
			'error_count'   => count( $updates ) - $success_count,
		) );
	}

	/**
	 * Analyze SEO for a post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function analyze_post( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );

		$result = $this->seo->analyze_post( $post_id );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'analyze_seo', $request, null, 404 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 404 );
		}

		$this->log_activity( 'analyze_seo', $request, $result );

		return $this->success_response( $result );
	}

	/**
	 * Get Yoast SEO data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_yoast_seo( $request ) {
		if ( ! $this->seo->is_yoast_active() ) {
			return $this->error_response( 'plugin_inactive', __( 'Yoast SEO is not active.', 'mumcp' ), 400 );
		}

		$post_id = absint( $request->get_param( 'id' ) );
		$result  = $this->seo->get_post_seo( $post_id, 'yoast' );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		return $this->success_response( $result );
	}

	/**
	 * Update Yoast SEO data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_yoast_seo( $request ) {
		if ( ! $this->seo->is_yoast_active() ) {
			return $this->error_response( 'plugin_inactive', __( 'Yoast SEO is not active.', 'mumcp' ), 400 );
		}

		return $this->update_post_seo_for_plugin( $request, 'yoast' );
	}

	/**
	 * Get RankMath SEO data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_rankmath_seo( $request ) {
		if ( ! $this->seo->is_rankmath_active() ) {
			return $this->error_response( 'plugin_inactive', __( 'RankMath is not active.', 'mumcp' ), 400 );
		}

		$post_id = absint( $request->get_param( 'id' ) );
		$result  = $this->seo->get_post_seo( $post_id, 'rankmath' );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		return $this->success_response( $result );
	}

	/**
	 * Update RankMath SEO data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_rankmath_seo( $request ) {
		if ( ! $this->seo->is_rankmath_active() ) {
			return $this->error_response( 'plugin_inactive', __( 'RankMath is not active.', 'mumcp' ), 400 );
		}

		return $this->update_post_seo_for_plugin( $request, 'rankmath' );
	}

	/**
	 * Get AIOSEO data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_aioseo_seo( $request ) {
		if ( ! $this->seo->is_aioseo_active() ) {
			return $this->error_response( 'plugin_inactive', __( 'AIOSEO is not active.', 'mumcp' ), 400 );
		}

		$post_id = absint( $request->get_param( 'id' ) );
		$result  = $this->seo->get_post_seo( $post_id, 'aioseo' );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		return $this->success_response( $result );
	}

	/**
	 * Update AIOSEO data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_aioseo_seo( $request ) {
		if ( ! $this->seo->is_aioseo_active() ) {
			return $this->error_response( 'plugin_inactive', __( 'AIOSEO is not active.', 'mumcp' ), 400 );
		}

		return $this->update_post_seo_for_plugin( $request, 'aioseo' );
	}

	/**
	 * Get SEOPress data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_seopress_seo( $request ) {
		if ( ! $this->seo->is_seopress_active() ) {
			return $this->error_response( 'plugin_inactive', __( 'SEOPress is not active.', 'mumcp' ), 400 );
		}

		$post_id = absint( $request->get_param( 'id' ) );
		$result  = $this->seo->get_post_seo( $post_id, 'seopress' );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		return $this->success_response( $result );
	}

	/**
	 * Update SEOPress data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_seopress_seo( $request ) {
		if ( ! $this->seo->is_seopress_active() ) {
			return $this->error_response( 'plugin_inactive', __( 'SEOPress is not active.', 'mumcp' ), 400 );
		}

		return $this->update_post_seo_for_plugin( $request, 'seopress' );
	}

	/**
	 * Helper to update SEO for specific plugin.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $plugin  Plugin identifier.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	private function update_post_seo_for_plugin( $request, $plugin ) {
		$post_id = absint( $request->get_param( 'id' ) );

		$data = array(
			'title'           => $request->get_param( 'title' ),
			'description'     => $request->get_param( 'description' ),
			'focus_keyword'   => $request->get_param( 'focus_keyword' ),
			'canonical'       => $request->get_param( 'canonical' ),
			'og_title'        => $request->get_param( 'og_title' ),
			'og_description'  => $request->get_param( 'og_description' ),
			'og_image'        => $request->get_param( 'og_image' ),
			'twitter_title'   => $request->get_param( 'twitter_title' ),
			'twitter_description' => $request->get_param( 'twitter_description' ),
			'twitter_image'   => $request->get_param( 'twitter_image' ),
			'noindex'         => $request->get_param( 'noindex' ),
			'nofollow'        => $request->get_param( 'nofollow' ),
			'robots_noindex'  => $request->get_param( 'robots_noindex' ),
			'robots_nofollow' => $request->get_param( 'robots_nofollow' ),
		);

		// Remove null values.
		$data = array_filter( $data, function( $v ) {
			return $v !== null;
		} );

		$result = $this->seo->update_post_seo( $post_id, $data, $plugin );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		return $this->success_response( $result );
	}

	/**
	 * Set noindex for a post.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function set_noindex( $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$noindex = (bool) $request->get_param( 'noindex' );

		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response( 'not_found', __( 'Post not found.', 'mumcp' ), 404 );
		}

		$data = array(
			'robots_noindex' => $noindex,
		);

		$result = $this->seo->update_post_seo( $post_id, $data );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'set_noindex', $request, array(
			'post_id' => $post_id,
			'noindex' => $noindex,
		) );

		return $this->success_response( array(
			'post_id' => $post_id,
			'title'   => $post->post_title,
			'noindex' => $noindex,
		) );
	}

	/**
	 * Scan all published content for SEO issues.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function scan_all( $request ) {
		$threshold = absint( $request->get_param( 'threshold' ) );
		if ( ! $threshold ) {
			$threshold = 300;
		}

		$results = $this->seo->scan_all( $threshold );

		$this->log_activity( 'seo_scan', $request, array( 'total' => count( $results ) ) );

		return $this->success_response( $results );
	}

	/**
	 * Export SEO metadata report for all published content.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function export_report( $request ) {
		$post_type = $request->get_param( 'post_type' );
		$limit     = absint( $request->get_param( 'limit' ) );
		if ( ! $limit ) {
			$limit = 100;
		}

		$results = $this->seo->export_report( $post_type, $limit );

		$this->log_activity( 'seo_report', $request, array( 'total' => count( $results ) ) );

		return $this->success_response( $results );
	}
}
