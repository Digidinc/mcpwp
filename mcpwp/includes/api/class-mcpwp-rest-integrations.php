<?php
/**
 * REST Integrations Controller
 *
 * Handles REST API endpoints for third-party AI integrations.
 *
 * @package MCPWP
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for AI integrations.
 */
class Mcpwp_REST_Integrations extends Mcpwp_REST_API {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Provider management (admin scope).
		register_rest_route(
			$this->namespace,
			'/integrations/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_providers' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/providers/(?P<provider>[a-z]+)/key',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'store_key' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'key' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove_key' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/providers/(?P<provider>[a-z]+)/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_provider' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Integration management (MCP tools).
		register_rest_route(
			$this->namespace,
			'/integrations/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'integrations_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/configure',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'configure_integration' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'provider' => array(
						'required' => true,
						'type'     => 'string',
					),
					'key'      => array( 'type' => 'string' ),
					'config'   => array( 'type' => 'object' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_integration' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'provider' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/remove',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'remove_integration' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'provider' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		// Stock photos (free tier).
		register_rest_route(
			$this->namespace,
			'/integrations/stock-photos',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search_stock_photos' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'query'    => array(
						'required' => true,
						'type'     => 'string',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 10,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/stock-photos/download',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'download_stock_photo' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'photo_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'size'     => array(
						'type'    => 'string',
						'default' => 'large',
					),
					'alt'      => array( 'type' => 'string' ),
					'title'    => array( 'type' => 'string' ),
				),
			)
		);

		// AI generation endpoints (pro tier).
		register_rest_route(
			$this->namespace,
			'/integrations/generate-image',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_image' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'prompt'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'provider' => array( 'type' => 'string' ),
					'size'     => array(
						'type'    => 'string',
						'default' => '1024x1024',
					),
					'style'    => array(
						'type'    => 'string',
						'default' => 'vivid',
					),
					'alt'      => array( 'type' => 'string' ),
					'title'    => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/generate-featured-image',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_featured_image' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post_id'  => array(
						'required' => true,
						'type'     => 'integer',
					),
					'prompt'   => array(
						'required' => true,
						'type'     => 'string',
					),
					'provider' => array( 'type' => 'string' ),
					'size'     => array(
						'type'    => 'string',
						'default' => '1792x1024',
					),
					'style'    => array(
						'type'    => 'string',
						'default' => 'vivid',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/generate-alt-text',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_alt_text' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'attachment_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'provider'      => array( 'type' => 'string' ),
					'auto_save'     => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/describe-image',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'describe_image' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'attachment_id' => array(
						'required' => true,
						'type'     => 'integer',
					),
					'provider'      => array( 'type' => 'string' ),
					'instruction'   => array(
						'type'    => 'string',
						'default' => 'Describe this image in detail.',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/generate-excerpt',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'generate_excerpt' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'post_id'    => array(
						'required' => true,
						'type'     => 'integer',
					),
					'provider'   => array( 'type' => 'string' ),
					'max_length' => array(
						'type'    => 'integer',
						'default' => 160,
					),
					'auto_save'  => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/integrations/text-to-speech',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'text_to_speech' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'text'     => array(
						'required' => true,
						'type'     => 'string',
					),
					'voice_id' => array( 'type' => 'string' ),
					'title'    => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * List providers and their status.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_providers( $request ) {
		$manager = Mcpwp_Integration_Manager::get_instance();
		$this->log_activity( 'integrations_list_providers', $request );
		return $this->success_response( $manager->get_available_providers() );
	}

	/**
	 * Store a provider API key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function store_key( $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) );
		$key      = $request->get_param( 'key' );

		$manager = Mcpwp_Integration_Manager::get_instance();
		$result  = $manager->set_provider_key( $provider, $key );

		if ( ! $result ) {
			if ( 'invalid_config' === $manager->get_last_save_error() ) {
				return $this->error_response( 'invalid_config', $manager->get_last_save_error_message(), 400 );
			}
			if ( 'integration_write_failed' === $manager->get_last_save_error() ) {
				return $this->integration_write_failed( __( 'The API key could not be saved: the stored integrations changed or could not be read while saving, so nothing was written. Try again.', 'mcpwp' ) );
			}
			return $this->error_response( 'store_failed', __( 'Failed to store API key.', 'mcpwp' ) );
		}

		$this->log_activity( 'integrations_store_key', $request, array( 'provider' => $provider ) );
		return $this->success_response(
			array(
				'success'  => true,
				'provider' => $provider,
				'message'  => sprintf(
				/* translators: %s: Provider name */
					__( '%s API key saved.', 'mcpwp' ),
					isset( Mcpwp_Integration_Manager::PROVIDERS[ $provider ]['name'] ) ? Mcpwp_Integration_Manager::PROVIDERS[ $provider ]['name'] : $provider
				),
			)
		);
	}

	/**
	 * Remove a provider API key.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove_key( $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) );
		$manager  = Mcpwp_Integration_Manager::get_instance();
		$outcome  = $manager->remove_provider_record( $provider );

		// A lost removal leaves the key stored and in use (#845 review).
		if ( Mcpwp_Integration_Manager::WRITE_FAILED === $outcome ) {
			return $this->integration_write_failed( __( 'The API key could not be removed: the stored integrations changed or could not be read while saving. It is still stored. Try again.', 'mcpwp' ) );
		}

		$this->log_activity( 'integrations_remove_key', $request, array( 'provider' => $provider ) );
		return $this->success_response(
			array(
				'success'  => true,
				'provider' => $provider,
				'removed'  => Mcpwp_Integration_Manager::WRITE_STORED === $outcome,
			)
		);
	}

	/**
	 * The error every provider write returns when the write was lost: the
	 * stored integrations changed under every attempt, or could not be read.
	 * Never a success (#845 review).
	 *
	 * @param string $message What was not saved or removed.
	 * @return WP_Error integration_write_failed, 503.
	 */
	private function integration_write_failed( $message ) {
		return $this->error_response( 'integration_write_failed', $message, 503 );
	}

	/**
	 * Test a provider connection.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_provider( $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) );
		$manager  = Mcpwp_Integration_Manager::get_instance();
		$result   = $manager->test_provider( $provider );

		$this->log_activity( 'integrations_test', $request, $result );
		return $this->success_response( $result );
	}

	/**
	 * Get all integrations and their status (for MCP tool).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function integrations_status( $request ) {
		$manager   = Mcpwp_Integration_Manager::get_instance();
		$providers = $manager->get_available_providers();
		$this->log_activity( 'integrations_status', $request );
		return $this->success_response( $providers );
	}

	/**
	 * Configure an integration (for MCP tool).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function configure_integration( $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) );
		$manager  = Mcpwp_Integration_Manager::get_instance();

		if ( ! isset( Mcpwp_Integration_Manager::PROVIDERS[ $provider ] ) ) {
			return $this->error_response(
				'unknown_provider',
				sprintf( 'Unknown provider: %s. Available: %s', $provider, implode( ', ', array_keys( Mcpwp_Integration_Manager::PROVIDERS ) ) ),
				400
			);
		}

		if ( $manager->is_multi_field_provider( $provider ) ) {
			$config = $request->get_param( 'config' );
			if ( empty( $config ) || ! is_array( $config ) ) {
				$fields = Mcpwp_Integration_Manager::PROVIDERS[ $provider ]['fields'];
				return $this->error_response(
					'config_required',
					sprintf( 'This provider requires a config object with fields: %s', implode( ', ', array_keys( $fields ) ) ),
					400
				);
			}
			$sanitized = array_map( 'sanitize_text_field', $config );
			if ( isset( $sanitized['url'] ) ) {
				$sanitized['url'] = esc_url_raw( $sanitized['url'] );
			}
			if ( isset( $sanitized['endpoint'] ) ) {
				$sanitized['endpoint'] = esc_url_raw( $sanitized['endpoint'] );
			}
			$result = $manager->set_provider_config( $provider, $sanitized );
		} else {
			$key = $request->get_param( 'key' );
			if ( empty( $key ) ) {
				return $this->error_response( 'key_required', 'API key is required for this provider.', 400 );
			}
			$result = $manager->set_provider_key( $provider, sanitize_text_field( $key ) );
		}

		if ( ! $result ) {
			if ( 'invalid_config' === $manager->get_last_save_error() ) {
				return $this->error_response( 'invalid_config', $manager->get_last_save_error_message(), 400 );
			}
			if ( 'integration_write_failed' === $manager->get_last_save_error() ) {
				return $this->integration_write_failed( 'The configuration could not be saved: the stored integrations changed or could not be read while saving, so nothing was written. Try again.' );
			}
			return $this->error_response( 'configure_failed', 'Failed to save configuration.' );
		}

		$this->log_activity( 'integrations_configure', $request, array( 'provider' => $provider ) );
		return $this->success_response( array(
			'success'  => true,
			'provider' => $provider,
			'message'  => sprintf( '%s configured successfully.', Mcpwp_Integration_Manager::PROVIDERS[ $provider ]['name'] ),
		) );
	}

	/**
	 * Test an integration connection (for MCP tool).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function test_integration( $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) );
		$manager  = Mcpwp_Integration_Manager::get_instance();

		if ( ! isset( Mcpwp_Integration_Manager::PROVIDERS[ $provider ] ) ) {
			return $this->error_response(
				'unknown_provider',
				sprintf( 'Unknown provider: %s', $provider ),
				400
			);
		}

		$result = $manager->test_provider( $provider );
		$this->log_activity( 'integrations_test_mcp', $request, $result );
		return $this->success_response( $result );
	}

	/**
	 * Remove an integration (for MCP tool).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function remove_integration( $request ) {
		$provider = sanitize_key( $request->get_param( 'provider' ) );
		$manager  = Mcpwp_Integration_Manager::get_instance();

		if ( ! isset( Mcpwp_Integration_Manager::PROVIDERS[ $provider ] ) ) {
			return $this->error_response(
				'unknown_provider',
				sprintf( 'Unknown provider: %s', $provider ),
				400
			);
		}

		$outcome = $manager->remove_provider_record( $provider );
		$name    = Mcpwp_Integration_Manager::PROVIDERS[ $provider ]['name'];

		// A lost removal leaves the key stored and in use (#845 review).
		if ( Mcpwp_Integration_Manager::WRITE_FAILED === $outcome ) {
			return $this->integration_write_failed( sprintf( '%s configuration could not be removed: the stored integrations changed or could not be read while saving. It is still stored. Try again.', $name ) );
		}

		$removed = Mcpwp_Integration_Manager::WRITE_STORED === $outcome;
		$this->log_activity( 'integrations_remove_mcp', $request, array( 'provider' => $provider ) );
		return $this->success_response( array(
			'success'  => true,
			'provider' => $provider,
			'removed'  => $removed,
			'message'  => $removed
				? sprintf( '%s configuration removed.', $name )
				: sprintf( '%s was not configured; nothing to remove.', $name ),
		) );
	}

	/**
	 * Search stock photos.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_stock_photos( $request ) {
		$provider_key = $this->require_provider_key( 'pexels' );
		if ( is_wp_error( $provider_key ) ) {
			return $provider_key;
		}

		$provider = new Mcpwp_Provider_Pexels( $provider_key );
		$result   = $provider->search(
			sanitize_text_field( $request->get_param( 'query' ) ),
			absint( $request->get_param( 'per_page' ) ),
			absint( $request->get_param( 'page' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'search_stock_photos', $request, null, 200 );
		return $this->success_response( $result );
	}

	/**
	 * Download a stock photo to media library.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function download_stock_photo( $request ) {
		$provider_key = $this->require_provider_key( 'pexels' );
		if ( is_wp_error( $provider_key ) ) {
			return $provider_key;
		}

		$provider = new Mcpwp_Provider_Pexels( $provider_key );
		$result   = $provider->download_to_media(
			absint( $request->get_param( 'photo_id' ) ),
			array(
				'size'  => sanitize_text_field( $request->get_param( 'size' ) ),
				'alt'   => sanitize_text_field( $request->get_param( 'alt' ) ),
				'title' => sanitize_text_field( $request->get_param( 'title' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'download_stock_photo', $request, $result, 200 );
		return $this->success_response( $result );
	}

	/**
	 * Generate an AI image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_image( $request ) {
		$pro_check = $this->require_pro();
		if ( is_wp_error( $pro_check ) ) {
			return $pro_check;
		}

		$provider_slug = $this->resolve_provider( $request->get_param( 'provider' ), 'image_generation' );
		if ( is_wp_error( $provider_slug ) ) {
			return $provider_slug;
		}

		$manager  = Mcpwp_Integration_Manager::get_instance();
		$provider = $manager->get_provider_instance( $provider_slug );

		$result = $provider->generate_image_to_media(
			sanitize_text_field( $request->get_param( 'prompt' ) ),
			array(
				'size'  => sanitize_text_field( $request->get_param( 'size' ) ),
				'style' => sanitize_text_field( $request->get_param( 'style' ) ),
				'alt'   => sanitize_text_field( $request->get_param( 'alt' ) ),
				'title' => sanitize_text_field( $request->get_param( 'title' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'generate_image', $request, $result, 200 );
		return $this->success_response( $result );
	}

	/**
	 * Generate a featured image for a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_featured_image( $request ) {
		$pro_check = $this->require_pro();
		if ( is_wp_error( $pro_check ) ) {
			return $pro_check;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response( 'post_not_found', __( 'Post not found.', 'mcpwp' ), 404 );
		}

		$provider_slug = $this->resolve_provider( $request->get_param( 'provider' ), 'image_generation' );
		if ( is_wp_error( $provider_slug ) ) {
			return $provider_slug;
		}

		$manager  = Mcpwp_Integration_Manager::get_instance();
		$provider = $manager->get_provider_instance( $provider_slug );

		$result = $provider->generate_image_to_media(
			sanitize_text_field( $request->get_param( 'prompt' ) ),
			array(
				'size'  => sanitize_text_field( $request->get_param( 'size' ) ),
				'style' => sanitize_text_field( $request->get_param( 'style' ) ),
				'title' => sprintf( 'Featured Image - %s', $post->post_title ),
				'alt'   => $post->post_title,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		set_post_thumbnail( $post_id, $result['id'] );
		$result['set_as_featured'] = true;
		$result['post_id']         = $post_id;

		$this->log_activity( 'generate_featured_image', $request, $result, 200 );
		return $this->success_response( $result );
	}

	/**
	 * Generate alt text for an image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_alt_text( $request ) {
		$pro_check = $this->require_pro();
		if ( is_wp_error( $pro_check ) ) {
			return $pro_check;
		}

		$attachment_id = absint( $request->get_param( 'attachment_id' ) );
		$image_url     = wp_get_attachment_url( $attachment_id );
		if ( ! $image_url ) {
			return $this->error_response( 'attachment_not_found', __( 'Attachment not found.', 'mcpwp' ), 404 );
		}

		$provider_slug = $this->resolve_provider( $request->get_param( 'provider' ), 'vision' );
		if ( is_wp_error( $provider_slug ) ) {
			return $provider_slug;
		}

		$manager  = Mcpwp_Integration_Manager::get_instance();
		$provider = $manager->get_provider_instance( $provider_slug );

		$alt_text = $provider->analyze_image( $image_url, 'Generate a concise, descriptive alt text for this image suitable for web accessibility. Keep it under 125 characters. Return only the alt text, no quotes or labels.' );
		if ( is_wp_error( $alt_text ) ) {
			return $alt_text;
		}

		$alt_text  = sanitize_text_field( trim( $alt_text, '"' ) );
		$auto_save = $request->get_param( 'auto_save' );

		if ( $auto_save ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		$this->log_activity( 'generate_alt_text', $request, array( 'alt_text' => $alt_text ), 200 );
		return $this->success_response(
			array(
				'attachment_id' => $attachment_id,
				'alt_text'      => $alt_text,
				'saved'         => (bool) $auto_save,
				'provider'      => $provider_slug,
			)
		);
	}

	/**
	 * Describe an image.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function describe_image( $request ) {
		$pro_check = $this->require_pro();
		if ( is_wp_error( $pro_check ) ) {
			return $pro_check;
		}

		$attachment_id = absint( $request->get_param( 'attachment_id' ) );
		$image_url     = wp_get_attachment_url( $attachment_id );
		if ( ! $image_url ) {
			return $this->error_response( 'attachment_not_found', __( 'Attachment not found.', 'mcpwp' ), 404 );
		}

		$provider_slug = $this->resolve_provider( $request->get_param( 'provider' ), 'vision' );
		if ( is_wp_error( $provider_slug ) ) {
			return $provider_slug;
		}

		$manager     = Mcpwp_Integration_Manager::get_instance();
		$provider    = $manager->get_provider_instance( $provider_slug );
		$instruction = sanitize_text_field( $request->get_param( 'instruction' ) );

		$description = $provider->analyze_image( $image_url, $instruction );
		if ( is_wp_error( $description ) ) {
			return $description;
		}

		$this->log_activity( 'describe_image', $request, null, 200 );
		return $this->success_response(
			array(
				'attachment_id' => $attachment_id,
				'description'   => $description,
				'provider'      => $provider_slug,
			)
		);
	}

	/**
	 * Generate an excerpt for a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_excerpt( $request ) {
		$pro_check = $this->require_pro();
		if ( is_wp_error( $pro_check ) ) {
			return $pro_check;
		}

		$post_id = absint( $request->get_param( 'post_id' ) );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return $this->error_response( 'post_not_found', __( 'Post not found.', 'mcpwp' ), 404 );
		}

		$provider_slug = $this->resolve_provider( $request->get_param( 'provider' ), 'text' );
		if ( is_wp_error( $provider_slug ) ) {
			return $provider_slug;
		}

		$max_length = max( 50, min( 500, absint( $request->get_param( 'max_length' ) ) ) );
		$content    = wp_strip_all_tags( $post->post_content );
		if ( empty( $content ) ) {
			return $this->error_response( 'no_content', __( 'Post has no content to summarize.', 'mcpwp' ) );
		}

		$prompt = sprintf(
			'Write a compelling excerpt/summary for the following blog post. Keep it under %d characters. Return only the excerpt text, no quotes or labels.\n\nTitle: %s\n\nContent:\n%s',
			$max_length,
			$post->post_title,
			substr( $content, 0, 3000 )
		);

		$manager  = Mcpwp_Integration_Manager::get_instance();
		$provider = $manager->get_provider_instance( $provider_slug );
		$excerpt  = $provider->generate_text( $prompt, 200 );

		if ( is_wp_error( $excerpt ) ) {
			return $excerpt;
		}

		$excerpt   = sanitize_textarea_field( trim( $excerpt, '"' ) );
		$auto_save = $request->get_param( 'auto_save' );

		if ( $auto_save ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => $excerpt,
				)
			);
		}

		$this->log_activity( 'generate_excerpt', $request, array( 'excerpt' => $excerpt ), 200 );
		return $this->success_response(
			array(
				'post_id'  => $post_id,
				'excerpt'  => $excerpt,
				'saved'    => (bool) $auto_save,
				'provider' => $provider_slug,
			)
		);
	}

	/**
	 * Generate text-to-speech audio.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function text_to_speech( $request ) {
		$pro_check = $this->require_pro();
		if ( is_wp_error( $pro_check ) ) {
			return $pro_check;
		}

		$provider_key = $this->require_provider_key( 'elevenlabs' );
		if ( is_wp_error( $provider_key ) ) {
			return $provider_key;
		}

		$provider = new Mcpwp_Provider_ElevenLabs( $provider_key );
		$result   = $provider->text_to_speech_to_media(
			$request->get_param( 'text' ),
			array(
				'voice_id' => sanitize_text_field( $request->get_param( 'voice_id' ) ),
				'title'    => sanitize_text_field( $request->get_param( 'title' ) ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->log_activity( 'text_to_speech', $request, $result, 200 );
		return $this->success_response( $result );
	}

	/**
	 * Require a provider key.
	 *
	 * @param string $provider Provider slug.
	 * @return string|WP_Error Decrypted key or error.
	 */
	private function require_provider_key( $provider ) {
		$manager = Mcpwp_Integration_Manager::get_instance();
		$key     = $manager->get_provider_key( $provider );

		if ( false === $key ) {
			$name = isset( Mcpwp_Integration_Manager::PROVIDERS[ $provider ]['name'] )
				? Mcpwp_Integration_Manager::PROVIDERS[ $provider ]['name']
				: $provider;

			// A key that is stored but cannot be decrypted is not "not configured":
			// re-entering it may not help (the site's encryption key is the problem),
			// and the caller must be able to tell the two apart (#564).
			if ( $manager->has_provider_key( $provider ) ) {
				$reason = class_exists( 'Mcpwp_Encryption' ) ? Mcpwp_Encryption::get_instance()->get_last_error() : '';

				return $this->error_response(
					'provider_key_unreadable',
					sprintf(
						/* translators: 1: Provider name, 2: machine-readable reason */
						__( '%1$s API key is stored but could not be decrypted (%2$s). See the MCPWP encryption notice in WP Admin.', 'mcpwp' ),
						$name,
						'' !== $reason ? $reason : 'unknown'
					),
					500
				);
			}

			return $this->error_response(
				'provider_not_configured',
				sprintf(
					/* translators: %s: Provider name */
					__( '%s API key not configured. Go to MCPWP > Integrations to add it.', 'mcpwp' ),
					$name
				),
				400
			);
		}

		return $key;
	}

	/**
	 * Resolve provider for a capability.
	 *
	 * @param string|null $requested  Requested provider slug.
	 * @param string      $capability Capability needed.
	 * @return string|WP_Error Provider slug or error.
	 */
	private function resolve_provider( $requested, $capability ) {
		$manager = Mcpwp_Integration_Manager::get_instance();

		if ( ! empty( $requested ) ) {
			$requested = sanitize_key( $requested );
			if ( ! $manager->has_provider_key( $requested ) ) {
				return $this->error_response(
					'provider_not_configured',
					sprintf(
						/* translators: %s: Provider name */
						__( '%s API key not configured. Go to MCPWP > Integrations to add it.', 'mcpwp' ),
						$requested
					),
					400
				);
			}
			return $requested;
		}

		$preferred = $manager->get_preferred_provider( $capability );
		if ( ! $preferred ) {
			return $this->error_response(
				'no_provider_available',
				sprintf(
					/* translators: %s: Capability name */
					__( 'No AI provider configured for %s. Go to MCPWP > Integrations to add one.', 'mcpwp' ),
					$capability
				),
				400
			);
		}

		return $preferred;
	}
}
