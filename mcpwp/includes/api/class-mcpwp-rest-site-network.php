<?php
/**
 * Multisite network + guides & workflows.
 *
 * Carved from the original Mcpwp_REST_Site (G1 split). Behavior-identical.
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Multisite network + guides & workflows.
 */
class Mcpwp_REST_Site_Network extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/guides',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_guides' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'topic' => array(
							'description' => __( 'Guide topic slug. Omit to list all available topics.', 'mumcp' ),
							'type'        => 'string',
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/guides/(?P<topic>[a-z_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_guide_topic' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'topic' => array(
							'description' => __( 'Guide topic slug.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
		register_rest_route(
			$this->namespace,
			'/workflows/(?P<name>[a-z_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_workflow' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'name' => array(
							'description' => __( 'Workflow name.', 'mumcp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);
		if ( is_multisite() ) {
			register_rest_route(
				$this->namespace,
				'/network/sites',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_network_sites' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => array(
							'per_page' => array(
								'description' => __( 'Results per page.', 'mumcp' ),
								'type'        => 'integer',
								'default'     => 50,
								'minimum'     => 1,
								'maximum'     => 200,
							),
							'search'   => array(
								'description' => __( 'Search term.', 'mumcp' ),
								'type'        => 'string',
							),
						),
					),
				)
			);
			register_rest_route(
				$this->namespace,
				'/network/switch',
				array(
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'switch_site' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => array(
							'blog_id' => array(
								'description' => __( 'Blog ID to switch to.', 'mumcp' ),
								'type'        => 'integer',
								'required'    => true,
							),
						),
					),
				)
			);
			register_rest_route(
				$this->namespace,
				'/network/stats',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_network_stats' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
				)
			);
		}
	}

	public function get_network_sites( $request ) {
		if ( ! is_multisite() ) {
			return $this->error_response( 'not_multisite', 'This is not a multisite installation.', 400 );
		}

		$this->log_activity( 'network_sites', $request );

		$per_page = $request->get_param( 'per_page' ) ?: 50;
		$search   = $request->get_param( 'search' );

		$args = array(
			'number' => $per_page,
			'fields' => 'ids',
		);
		if ( $search ) {
			$args['search'] = $search;
		}

		$site_ids = get_sites( $args );
		$sites    = array();

		foreach ( $site_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			$sites[] = array(
				'blog_id'        => (int) $blog_id,
				'name'           => get_bloginfo( 'name' ),
				'url'            => get_bloginfo( 'url' ),
				'admin_url'      => admin_url(),
				'plugin_active'  => is_plugin_active( MCPWP_PLUGIN_BASENAME )
					|| ( function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( MCPWP_PLUGIN_BASENAME ) ),
				'plugin_version' => defined( 'MCPWP_VERSION' ) ? MCPWP_VERSION : null,
				'has_api_key'    => ! empty( get_option( 'mcpwp_api_key' ) ) || ! empty( get_option( 'mcpwp_api_keys' ) ),
			);
			restore_current_blog();
		}

		return $this->success_response(
			array(
				'sites'        => $sites,
				'total'        => count( $sites ),
				'is_multisite' => true,
			)
		);
	}

	public function switch_site( $request ) {
		if ( ! is_multisite() ) {
			return $this->error_response( 'not_multisite', 'Not a multisite installation.', 400 );
		}

		$this->log_activity( 'network_switch', $request );

		$blog_id = absint( $request->get_param( 'blog_id' ) );
		$blog    = get_blog_details( $blog_id );

		if ( ! $blog ) {
			return $this->error_response( 'invalid_site', 'Site not found.', 404 );
		}

		// Get target site details.
		switch_to_blog( $blog_id );
		$site_url = get_bloginfo( 'url' );
		$has_key  = ! empty( get_option( 'mcpwp_api_key' ) ) || ! empty( get_option( 'mcpwp_api_keys' ) );
		restore_current_blog();

		return $this->success_response(
			array(
				'blog_id'      => $blog_id,
				'name'         => $blog->blogname,
				'url'          => $site_url,
				'mcp_endpoint' => trailingslashit( $site_url ) . 'wp-json/mcpwp/v1/mcp',
				'has_api_key'  => $has_key,
				'hint'         => $has_key
					? 'Connect to the MCP endpoint above with the site\'s API key.'
					: 'This site needs an API key. Generate one from WP Admin > mumcp.',
			)
		);
	}

	public function get_network_stats( $request ) {
		if ( ! is_multisite() ) {
			return $this->error_response( 'not_multisite', 'Not a multisite installation.', 400 );
		}

		$this->log_activity( 'network_stats', $request );

		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 100,
			)
		);

		$stats = array(
			'site_count' => count( $site_ids ),
			'sites'      => array(),
		);

		foreach ( $site_ids as $blog_id ) {
			switch_to_blog( $blog_id );
			$stats['sites'][] = array(
				'blog_id' => (int) $blog_id,
				'name'    => get_bloginfo( 'name' ),
				'posts'   => (int) wp_count_posts( 'post' )->publish,
				'pages'   => (int) wp_count_posts( 'page' )->publish,
				'media'   => array_sum( array_map( 'intval', (array) wp_count_attachments() ) ),
			);
			restore_current_blog();
		}

		return $this->success_response( $stats );
	}

	public function get_guides( $request ) {
		$topic = $request->get_param( 'topic' );

		if ( ! empty( $topic ) ) {
			$guide = Mcpwp_Guides::get_guide( $topic );
			if ( is_wp_error( $guide ) ) {
				return $guide;
			}
			return $this->success_response( $guide );
		}

		$topics = Mcpwp_Guides::get_topics();
		return $this->success_response( array(
			'description' => 'Available guide topics. Use wp_get_guide(topic="...") to get the full guide for a topic.',
			'topics'      => $topics,
		) );
	}

	public function get_guide_topic( $request ) {
		$topic = $request->get_param( 'topic' );
		$guide = Mcpwp_Guides::get_guide( $topic );

		if ( is_wp_error( $guide ) ) {
			return $guide;
		}

		return $this->success_response( $guide );
	}

	public function get_workflow( $request ) {
		$name     = $request->get_param( 'name' );
		$workflow = Mcpwp_Workflows::get_workflow( $name );

		if ( is_wp_error( $workflow ) ) {
			return $workflow;
		}

		return $this->success_response( $workflow );
	}

}
