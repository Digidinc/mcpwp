<?php
/**
 * Elementor Pro REST API Controller
 *
 * @package MCPWP_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for Elementor Pro features.
 *
 * Provides endpoints for templates, landing pages, cloning, and widgets.
 */
class Mcpwp_REST_Elementor_Pro extends Mcpwp_REST_API {
	/**
	 * Elementor Pro Custom Code post type.
	 *
	 * Elementor Pro registers this CPT for Custom Code snippets.
	 *
	 * @var string
	 */
	private $custom_code_cpt = 'elementor_snippet';

	/**
	 * Elementor Pro handler.
	 *
	 * @var Mcpwp_Elementor_Pro
	 */
	private $elementor_pro;

	/**
	 * Constructor.
	 *
	 * @param Mcpwp_Elementor_Pro $elementor_pro Elementor Pro handler.
	 */
	public function __construct( $elementor_pro ) {
		$this->elementor_pro = $elementor_pro;
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// Templates.
		register_rest_route(
			$this->namespace,
			'/elementor/templates',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Single template.
		register_rest_route(
			$this->namespace,
			'/elementor/templates/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Apply template to page.
		register_rest_route(
			$this->namespace,
			'/elementor/templates/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/archetypes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_archetypes' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_archetype' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/archetypes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_archetype' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_archetype' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/archetypes/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_archetype' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Reusable parts library.
		register_rest_route(
			$this->namespace,
			'/elementor/parts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_parts' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_part' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/parts/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_part' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_part' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/parts/(?P<id>\d+)/apply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'apply_part' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/elementor/parts/from-section',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_part_from_section' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Clone page.
		register_rest_route(
			$this->namespace,
			'/elementor/clone',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clone_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Landing page.
		register_rest_route(
			$this->namespace,
			'/elementor/landing-page',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_landing_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Widgets.
		register_rest_route(
			$this->namespace,
			'/elementor/widgets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_widgets' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'widget' => array(
						'description' => __( 'Widget type name to get full controls schema.', 'mcpwp' ),
						'type'        => 'string',
					),
				),
			)
		);

		// Globals.
		register_rest_route(
			$this->namespace,
			'/elementor/globals',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_globals' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'set_globals' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		if ( ! defined( 'MCPWP_WPORG_BUILD' ) ) {
			// Elementor Pro Custom Code (snippets) — not available in WP.org build.
			register_rest_route(
				$this->namespace,
				'/elementor/custom-code',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'list_custom_code' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => array_merge(
							$this->get_pagination_args(),
							array(
								'status' => array(
									'description' => __( 'Filter by post status: publish, draft, or any.', 'mcpwp' ),
									'type'        => 'string',
									'default'     => 'any',
								),
								'search' => array(
									'description' => __( 'Search by snippet title/content.', 'mcpwp' ),
									'type'        => 'string',
								),
							)
						),
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => array( $this, 'create_custom_code' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => $this->get_custom_code_write_args( true ),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/elementor/custom-code/(?P<id>\\d+)',
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => array( $this, 'get_custom_code' ),
						'permission_callback' => array( $this, 'check_permission' ),
					),
					array(
						'methods'             => WP_REST_Server::EDITABLE,
						'callback'            => array( $this, 'update_custom_code' ),
						'permission_callback' => array( $this, 'check_permission' ),
						'args'                => $this->get_custom_code_write_args( false ),
					),
				)
			);

			register_rest_route(
				$this->namespace,
				'/elementor/custom-code/(?P<id>\\d+)/disable',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'disable_custom_code' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			register_rest_route(
				$this->namespace,
				'/elementor/custom-code/(?P<id>\\d+)/enable',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'enable_custom_code' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);

			register_rest_route(
				$this->namespace,
				'/elementor/custom-code/(?P<id>\\d+)/sanitize',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'sanitize_custom_code' ),
					'permission_callback' => array( $this, 'check_permission' ),
				)
			);
		} // end if ( ! defined( 'MCPWP_WPORG_BUILD' ) )

		// Build page from section blueprints.
		register_rest_route(
			$this->namespace,
			'/elementor/build-page',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'build_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Blueprint catalog.
		register_rest_route(
			$this->namespace,
			'/elementor/blueprints',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_blueprints' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Single blueprint builder.
		register_rest_route(
			$this->namespace,
			'/elementor/blueprints/build',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_blueprint' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Save section as template.
		register_rest_route(
			$this->namespace,
			'/elementor/save-section-as-template',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_section_as_template' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	/**
	 * List Elementor Pro Custom Code snippets.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_custom_code( $request ) {
		$args = $this->sanitize_query_args(
			array(
				'per_page' => $request->get_param( 'per_page' ) ?: 50,
				'page'     => $request->get_param( 'page' ) ?: 1,
				'status'   => $request->get_param( 'status' ) ?: 'any',
				'search'   => $request->get_param( 'search' ),
			)
		);

		$args['post_type']      = $this->custom_code_cpt;
		$args['posts_per_page'] = isset( $args['posts_per_page'] ) ? $args['posts_per_page'] : 50;
		$args['paged']          = isset( $args['paged'] ) ? $args['paged'] : 1;

		$query = new WP_Query( $args );

		$snippets = array();
		foreach ( $query->posts as $post ) {
			$snippets[] = $this->format_custom_code_snippet( $post );
		}

		$this->log_activity( 'list_elementor_custom_code', $request, array( 'count' => count( $snippets ) ) );

		return $this->success_response(
			array(
				'snippets'  => $snippets,
				'total'     => (int) $query->found_posts,
				'page'      => (int) $args['paged'],
				'per_page'  => (int) $args['posts_per_page'],
				'post_type' => $this->custom_code_cpt,
			)
		);
	}

	/**
	 * Create an Elementor Pro Custom Code snippet.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_custom_code( $request ) {
		$prepared = $this->prepare_custom_code_payload( $request, true );
		if ( is_wp_error( $prepared ) ) {
			$this->log_activity( 'create_elementor_custom_code', $request, null, 400 );
			return $prepared;
		}

		if ( ! empty( $prepared['dry_run'] ) ) {
			return $this->success_response(
				array(
					'dry_run'  => true,
					'valid'    => true,
					'payload'  => $this->redact_custom_code_payload( $prepared ),
					'message'  => __( 'Custom Code payload is valid. Nothing was saved because dry_run=true.', 'mcpwp' ),
				)
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => $this->custom_code_cpt,
				'post_title'   => $prepared['title'],
				'post_content' => $prepared['code'],
				'post_status'  => $prepared['status'],
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			$this->log_activity( 'create_elementor_custom_code', $request, null, 400 );
			return $this->error_response( $post_id->get_error_code(), $post_id->get_error_message(), 400 );
		}

		$this->save_custom_code_meta( $post_id, $prepared );

		$data = array(
			'id'      => (int) $post_id,
			'snippet' => $this->format_custom_code_snippet( get_post( $post_id ), true ),
		);

		$this->log_activity( 'create_elementor_custom_code', $request, $this->redact_custom_code_payload( $data ) );

		return $this->success_response( $data, 201 );
	}

	/**
	 * Get a single Elementor Pro Custom Code snippet with readback.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_custom_code( $request ) {
		$post = $this->get_custom_code_post( $request->get_param( 'id' ) );
		if ( is_wp_error( $post ) ) {
			$this->log_activity( 'get_elementor_custom_code', $request, null, 404 );
			return $post;
		}

		$data = array(
			'snippet' => $this->format_custom_code_snippet( $post, true ),
		);

		$this->log_activity( 'get_elementor_custom_code', $request, array( 'id' => (int) $post->ID ) );

		return $this->success_response( $data );
	}

	/**
	 * Update an Elementor Pro Custom Code snippet.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_custom_code( $request ) {
		$post = $this->get_custom_code_post( $request->get_param( 'id' ) );
		if ( is_wp_error( $post ) ) {
			$this->log_activity( 'update_elementor_custom_code', $request, null, 404 );
			return $post;
		}

		$prepared = $this->prepare_custom_code_payload( $request, false );
		if ( is_wp_error( $prepared ) ) {
			$this->log_activity( 'update_elementor_custom_code', $request, null, 400 );
			return $prepared;
		}

		if ( ! empty( $prepared['dry_run'] ) ) {
			return $this->success_response(
				array(
					'dry_run' => true,
					'valid'   => true,
					'id'      => (int) $post->ID,
					'payload' => $this->redact_custom_code_payload( $prepared ),
					'message' => __( 'Custom Code update payload is valid. Nothing was saved because dry_run=true.', 'mcpwp' ),
				)
			);
		}

		$post_update = array( 'ID' => (int) $post->ID );
		if ( isset( $prepared['title'] ) ) {
			$post_update['post_title'] = $prepared['title'];
		}
		if ( array_key_exists( 'code', $prepared ) ) {
			$post_update['post_content'] = $prepared['code'];
		}
		if ( isset( $prepared['status'] ) ) {
			$post_update['post_status'] = $prepared['status'];
		}

		if ( count( $post_update ) > 1 ) {
			$updated = wp_update_post( $post_update, true );
			if ( is_wp_error( $updated ) ) {
				$this->log_activity( 'update_elementor_custom_code', $request, null, 400 );
				return $this->error_response( $updated->get_error_code(), $updated->get_error_message(), 400 );
			}
		}

		$this->save_custom_code_meta( (int) $post->ID, $prepared );

		$data = array(
			'id'      => (int) $post->ID,
			'snippet' => $this->format_custom_code_snippet( get_post( $post->ID ), true ),
		);

		$this->log_activity( 'update_elementor_custom_code', $request, $this->redact_custom_code_payload( $data ) );

		return $this->success_response( $data );
	}

	/**
	 * Disable a Custom Code snippet (set to draft).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function disable_custom_code( $request ) {
		return $this->set_custom_code_status( $request, 'draft' );
	}

	/**
	 * Enable a Custom Code snippet (set to publish).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function enable_custom_code( $request ) {
		return $this->set_custom_code_status( $request, 'publish' );
	}

	/**
	 * Sanitize a Custom Code snippet by stripping invalid wrapper tags.
	 *
	 * Scans all string post meta values and removes <html>/<head>/<body> tags.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function sanitize_custom_code( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || $this->custom_code_cpt !== $post->post_type ) {
			$this->log_activity( 'sanitize_elementor_custom_code', $request, null, 404 );
			return $this->error_response( 'not_found', __( 'Custom Code snippet not found.', 'mcpwp' ), 404 );
		}

		$meta    = get_post_meta( $id );
		$changed = 0;
		$matches = array();

		foreach ( $meta as $key => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}

				if ( ! $this->contains_wrapper_html_tags( $value ) ) {
					continue;
				}

				$sanitized = $this->strip_wrapper_html_tags( $value );
				if ( empty( $sanitized['changed'] ) ) {
					continue;
				}

				update_post_meta( $id, $key, $sanitized['content'], $value );
				$changed++;
				$matches[] = $key;
			}
		}

		$result = array(
			'id'                 => $id,
			'changed_meta_count' => $changed,
			'matching_meta_keys' => array_values( array_unique( $matches ) ),
			'snippet'            => $this->format_custom_code_snippet( get_post( $id ) ),
		);

		$this->log_activity( 'sanitize_elementor_custom_code', $request, $result );

		return $this->success_response( $result );
	}

	/**
	 * Set the post status of a snippet.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @param string          $status Desired status.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	private function set_custom_code_status( $request, $status ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );

		if ( ! $post || $this->custom_code_cpt !== $post->post_type ) {
			$this->log_activity( 'set_elementor_custom_code_status', $request, null, 404 );
			return $this->error_response( 'not_found', __( 'Custom Code snippet not found.', 'mcpwp' ), 404 );
		}

		$status = $this->validate_post_status( $status, array( 'publish', 'draft' ) );
		$update = wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $update ) ) {
			$this->log_activity( 'set_elementor_custom_code_status', $request, null, 400 );
			return $this->error_response( $update->get_error_code(), $update->get_error_message(), 400 );
		}

		$data = array(
			'id'      => $id,
			'status'  => $status,
			'snippet' => $this->format_custom_code_snippet( get_post( $id ) ),
		);

		$this->log_activity( 'set_elementor_custom_code_status', $request, $data );

		return $this->success_response( $data );
	}

	/**
	 * REST args for Custom Code create/update.
	 *
	 * @param bool $creating Whether this is the create route.
	 * @return array Args schema.
	 */
	private function get_custom_code_write_args( $creating ) {
		return array(
			'title'      => array(
				'description' => __( 'Snippet title.', 'mcpwp' ),
				'type'        => 'string',
				'required'    => (bool) $creating,
			),
			'code'       => array(
				'description' => __( 'Raw HTML, CSS, or JavaScript code. PHP is not supported by Elementor Custom Code.', 'mcpwp' ),
				'type'        => 'string',
				'required'    => (bool) $creating,
			),
			'content'    => array(
				'description' => __( 'Alias for code, for compatibility with generic post tools.', 'mcpwp' ),
				'type'        => 'string',
			),
			'location'   => array(
				'description' => __( 'Injection location: head, body_start, or body_end.', 'mcpwp' ),
				'type'        => 'string',
				'default'     => 'head',
			),
			'status'     => array(
				'description' => __( 'Post status: draft or publish.', 'mcpwp' ),
				'type'        => 'string',
				'default'     => 'draft',
			),
			'conditions' => array(
				'description' => __( 'Elementor display conditions. Default is include/general (entire site). Example: [{"type":"include","name":"general"}].', 'mcpwp' ),
				'type'        => 'array',
			),
			'dry_run'    => array(
				'description' => __( 'Validate and normalize without saving.', 'mcpwp' ),
				'type'        => 'boolean',
				'default'     => false,
			),
		);
	}

	/**
	 * Prepare Custom Code request payload.
	 *
	 * @param WP_REST_Request $request  Request object.
	 * @param bool            $creating Whether this is a create request.
	 * @return array|WP_Error Prepared payload or error.
	 */
	private function prepare_custom_code_payload( $request, $creating ) {
		if ( function_exists( 'post_type_exists' ) && ! post_type_exists( $this->custom_code_cpt ) ) {
			return $this->error_response(
				'elementor_custom_code_unavailable',
				__( 'Elementor Pro Custom Code is unavailable because the elementor_snippet post type is not registered.', 'mcpwp' ),
				400
			);
		}

		$payload = array(
			'dry_run' => (bool) $request->get_param( 'dry_run' ),
		);

		$title = $request->get_param( 'title' );
		if ( null !== $title ) {
			$title = sanitize_text_field( $title );
			if ( '' !== $title ) {
				$payload['title'] = $title;
			}
		}

		if ( $creating && empty( $payload['title'] ) ) {
			return $this->error_response( 'missing_title', __( 'Custom Code title is required.', 'mcpwp' ), 400 );
		}

		$raw_code = $request->get_param( 'code' );
		if ( null === $raw_code ) {
			$raw_code = $request->get_param( 'content' );
		}

		if ( null !== $raw_code ) {
			$code       = $this->normalize_custom_code_content( $raw_code );
			$payload['code'] = $code['content'];
			$payload['wrapper_tags_removed'] = (bool) $code['changed'];
		}

		if ( $creating && ( ! array_key_exists( 'code', $payload ) || '' === trim( $payload['code'] ) ) ) {
			return $this->error_response( 'missing_code', __( 'Custom Code raw code is required.', 'mcpwp' ), 400 );
		}

		if ( null !== $request->get_param( 'location' ) ) {
			$payload['location'] = $this->normalize_custom_code_location( $request->get_param( 'location' ) );
		} elseif ( $creating ) {
			$payload['location'] = 'head';
		}

		if ( null !== $request->get_param( 'status' ) ) {
			$payload['status'] = $this->validate_post_status( $request->get_param( 'status' ), array( 'publish', 'draft' ) );
		} elseif ( $creating ) {
			$payload['status'] = 'draft';
		}

		$conditions = $request->get_param( 'conditions' );
		if ( null !== $conditions ) {
			if ( ! is_array( $conditions ) ) {
				return $this->error_response( 'invalid_conditions', __( 'conditions must be an array.', 'mcpwp' ), 400 );
			}
			$payload['conditions'] = $this->normalize_custom_code_conditions( $conditions );
		} elseif ( $creating ) {
			$payload['conditions'] = $this->normalize_custom_code_conditions( array() );
		}

		return $payload;
	}

	/**
	 * Normalize raw Custom Code content without stripping script/style tags.
	 *
	 * @param string $content Raw content.
	 * @return array Normalized content and change flag.
	 */
	private function normalize_custom_code_content( $content ) {
		$content = (string) wp_unslash( $content );
		if ( $this->contains_wrapper_html_tags( $content ) ) {
			return $this->strip_wrapper_html_tags( $content );
		}

		return array(
			'content' => $content,
			'changed' => false,
		);
	}

	/**
	 * Normalize Elementor custom-code injection location.
	 *
	 * @param string $location Raw location.
	 * @return string Location.
	 */
	private function normalize_custom_code_location( $location ) {
		$location = sanitize_key( $location );
		$allowed  = array( 'head', 'body_start', 'body_end' );
		return in_array( $location, $allowed, true ) ? $location : 'head';
	}

	/**
	 * Normalize Elementor display conditions.
	 *
	 * @param array $conditions Raw conditions.
	 * @return array Normalized conditions.
	 */
	private function normalize_custom_code_conditions( $conditions ) {
		if ( empty( $conditions ) ) {
			return array(
				array(
					'type' => 'include',
					'name' => 'general',
				),
			);
		}

		$normalized = array();
		foreach ( $conditions as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$item = array(
				'type'     => isset( $condition['type'] ) && 'exclude' === $condition['type'] ? 'exclude' : 'include',
				'name'     => isset( $condition['name'] ) ? sanitize_text_field( $condition['name'] ) : 'general',
				'sub_name' => isset( $condition['sub_name'] ) ? sanitize_text_field( $condition['sub_name'] ) : '',
				'sub_id'   => isset( $condition['sub_id'] ) ? sanitize_text_field( $condition['sub_id'] ) : '',
			);

			if ( '' === $item['name'] ) {
				$item['name'] = 'general';
			}

			$normalized[] = array_filter(
				$item,
				function ( $value ) {
					return '' !== $value;
				}
			);
		}

		return empty( $normalized ) ? $this->normalize_custom_code_conditions( array() ) : $normalized;
	}

	/**
	 * Persist Elementor custom-code meta.
	 *
	 * @param int   $post_id  Snippet post ID.
	 * @param array $payload  Prepared payload.
	 */
	private function save_custom_code_meta( $post_id, $payload ) {
		if ( isset( $payload['location'] ) ) {
			update_post_meta( $post_id, '_elementor_location', $payload['location'] );
		}

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );

		if ( array_key_exists( 'wrapper_tags_removed', $payload ) ) {
			update_post_meta( $post_id, '_mcpwp_wrapper_tags_removed', (bool) $payload['wrapper_tags_removed'] ? '1' : '0' );
		}

		if ( isset( $payload['conditions'] ) ) {
			$result = $this->save_custom_code_conditions( $post_id, $payload['conditions'] );
			update_post_meta(
				$post_id,
				'_mcpwp_custom_code_conditions_engine',
				is_wp_error( $result ) ? 'fallback_meta' : (string) $result
			);
		}
	}

	/**
	 * Save Elementor display conditions through Elementor Pro when available.
	 *
	 * @param int   $post_id    Snippet post ID.
	 * @param array $conditions Normalized conditions.
	 * @return string|WP_Error Save engine name or error.
	 */
	private function save_custom_code_conditions( $post_id, $conditions ) {
		update_post_meta( $post_id, '_elementor_conditions', $conditions );
		$this->update_custom_code_conditions_index( $post_id, $conditions );

		if (
			class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' )
			&& class_exists( '\\Elementor\\Plugin' )
			&& method_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module', 'instance' )
		) {
			try {
				$conditions_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
				$conditions_manager->save_conditions( $post_id, $conditions );

				if ( method_exists( $conditions_manager, 'get_cache' ) ) {
					$cache = $conditions_manager->get_cache();
					if ( $cache && method_exists( $cache, 'regenerate' ) ) {
						$cache->regenerate();
					}
				}

				return 'elementor_pro_conditions_manager';
			} catch ( Exception $exception ) {
				return new WP_Error( 'conditions_save_failed', $exception->getMessage(), array( 'status' => 500 ) );
			}
		}

		return 'fallback_meta';
	}

	/**
	 * Get a Custom Code post or a typed error.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post|WP_Error Post or error.
	 */
	private function get_custom_code_post( $id ) {
		$id   = absint( $id );
		$post = get_post( $id );

		if ( ! $post || $this->custom_code_cpt !== $post->post_type ) {
			return $this->error_response( 'not_found', __( 'Custom Code snippet not found.', 'mcpwp' ), 404 );
		}

		return $post;
	}

	/**
	 * Read Elementor display conditions for a Custom Code snippet.
	 *
	 * @param int $post_id Snippet post ID.
	 * @return array Conditions.
	 */
	private function get_custom_code_conditions( $post_id ) {
		if (
			class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' )
			&& class_exists( '\\Elementor\\Plugin' )
			&& method_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module', 'instance' )
		) {
			try {
				$document = \Elementor\Plugin::instance()->documents->get( $post_id );
				if ( $document ) {
					$conditions_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
					$conditions         = $conditions_manager->get_document_conditions( $document );
					return is_array( $conditions ) ? $this->normalize_custom_code_conditions( $conditions ) : array();
				}
			} catch ( Exception $exception ) {
				// Fall back to stored meta below.
			}
		}

		$conditions = get_post_meta( $post_id, '_elementor_conditions', true );
		return is_array( $conditions ) ? $this->normalize_custom_code_conditions( $conditions ) : array();
	}

	/**
	 * Keep Elementor's global condition index in sync for fallback/debug visibility.
	 *
	 * @param int   $post_id    Snippet post ID.
	 * @param array $conditions Normalized conditions.
	 */
	private function update_custom_code_conditions_index( $post_id, $conditions ) {
		$all_conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );
		if ( ! is_array( $all_conditions ) ) {
			$all_conditions = array();
		}

		foreach ( $all_conditions as $key => $post_ids ) {
			if ( is_array( $post_ids ) ) {
				$all_conditions[ $key ] = array_values(
					array_filter(
						$post_ids,
						function ( $id ) use ( $post_id ) {
							return absint( $id ) !== absint( $post_id );
						}
					)
				);

				if ( empty( $all_conditions[ $key ] ) ) {
					unset( $all_conditions[ $key ] );
				}
			}
		}

		foreach ( $conditions as $condition ) {
			$key = $this->build_custom_code_condition_key( $condition );
			if ( ! isset( $all_conditions[ $key ] ) ) {
				$all_conditions[ $key ] = array();
			}
			if ( ! in_array( absint( $post_id ), array_map( 'absint', $all_conditions[ $key ] ), true ) ) {
				$all_conditions[ $key ][] = absint( $post_id );
			}
		}

		update_option( 'elementor_pro_theme_builder_conditions', $all_conditions );
	}

	/**
	 * Build Elementor condition index key.
	 *
	 * @param array $condition Condition.
	 * @return string Index key.
	 */
	private function build_custom_code_condition_key( $condition ) {
		$key = ( $condition['type'] ?? 'include' ) . '/' . ( $condition['name'] ?? 'general' );
		if ( ! empty( $condition['sub_name'] ) ) {
			$key .= '/' . $condition['sub_name'];
			if ( ! empty( $condition['sub_id'] ) ) {
				$key .= '/' . $condition['sub_id'];
			}
		}
		return $key;
	}

	/**
	 * Redact raw code from activity logs and dry-run summaries.
	 *
	 * @param array $payload Payload.
	 * @return array Redacted payload.
	 */
	private function redact_custom_code_payload( $payload ) {
		if ( isset( $payload['code'] ) ) {
			$payload['code_length'] = strlen( (string) $payload['code'] );
			unset( $payload['code'] );
		}

		if ( isset( $payload['snippet']['code'] ) ) {
			$payload['snippet']['code_length'] = strlen( (string) $payload['snippet']['code'] );
			unset( $payload['snippet']['code'] );
		}

		return $payload;
	}

	/**
	 * Format a Custom Code snippet post for API response.
	 *
	 * @param WP_Post $post Post object.
	 * @param bool    $include_code Include raw code.
	 * @return array Formatted snippet.
	 */
	private function format_custom_code_snippet( $post, $include_code = false ) {
		$meta = get_post_meta( $post->ID );

		$matching_meta_keys = array();
		foreach ( $meta as $key => $values ) {
			if ( ! is_array( $values ) ) {
				continue;
			}

			foreach ( $values as $value ) {
				if ( is_string( $value ) && $this->contains_wrapper_html_tags( $value ) ) {
					$matching_meta_keys[] = $key;
					break;
				}
			}
		}

		$debug_meta = array();
		foreach ( $meta as $key => $values ) {
			if ( ! is_array( $values ) || empty( $values ) ) {
				continue;
			}

			if ( preg_match( '/(code|snippet|location|condition)/i', (string) $key ) ) {
				$first = $values[0];
				if ( is_string( $first ) ) {
					$debug_meta[ $key ] = substr( $first, 0, 200 );
				} else {
					$debug_meta[ $key ] = $first;
				}
			}
		}

		$data = array(
			'id'                => (int) $post->ID,
			'title'             => (string) $post->post_title,
			'status'            => (string) $post->post_status,
			'modified_gmt'       => (string) $post->post_modified_gmt,
			'location'           => (string) ( get_post_meta( $post->ID, '_elementor_location', true ) ?: 'head' ),
			'conditions'         => $this->get_custom_code_conditions( $post->ID ),
			'conditions_engine'  => (string) ( get_post_meta( $post->ID, '_mcpwp_custom_code_conditions_engine', true ) ?: 'unknown' ),
			'code_length'        => strlen( (string) $post->post_content ),
			'wrapper_tags_removed' => '1' === (string) get_post_meta( $post->ID, '_mcpwp_wrapper_tags_removed', true ),
			'has_wrapper_tags'   => ! empty( $matching_meta_keys ),
			'matching_meta_keys' => array_values( array_unique( $matching_meta_keys ) ),
			'debug_meta_excerpt' => $debug_meta,
		);

		if ( $include_code ) {
			$data['code'] = (string) $post->post_content;
		}

		return $data;
	}

	/**
	 * Get all templates.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_templates( $request ) {
		$args = array(
			'per_page' => $request->get_param( 'per_page' ) ?: 50,
			'page'     => $request->get_param( 'page' ) ?: 1,
			'type'     => $request->get_param( 'type' ),
		);

		$templates = $this->elementor_pro->get_templates( $args );

		$this->log_activity( 'get_templates', $request, $templates );

		return $this->success_response( array(
			'templates' => $templates,
			'total'     => count( $templates ),
		) );
	}

	/**
	 * Get single template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_template( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$template    = $this->elementor_pro->get_template( $template_id );

		if ( is_wp_error( $template ) ) {
			$this->log_activity( 'get_template', $request, null, 404 );
			return $this->error_response( $template->get_error_code(), $template->get_error_message(), 404 );
		}

		$this->log_activity( 'get_template', $request, $template );

		return $this->success_response( $template );
	}

	/**
	 * Create a template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_template( $request ) {
		$data = array(
			'title'          => $request->get_param( 'title' ),
			'type'           => $request->get_param( 'type' ),
			'elementor_data' => $this->get_elementor_payload_from_request( $request ),
		);

		$template = $this->elementor_pro->create_template( $data );

		if ( is_wp_error( $template ) ) {
			$this->log_activity( 'create_template', $request, null, 400 );
			return $this->error_response( $template->get_error_code(), $template->get_error_message(), 400 );
		}

		$this->log_activity( 'create_template', $request, $template, 201 );

		return $this->success_response( $template, 201 );
	}

	/**
	 * Update a template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_template( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$data        = array();

		if ( $request->has_param( 'title' ) ) {
			$data['title'] = $request->get_param( 'title' );
		}

		if ( $this->has_elementor_payload_in_request( $request ) ) {
			$data['elementor_data'] = $this->get_elementor_payload_from_request( $request );
		}

		$template = $this->elementor_pro->update_template( $template_id, $data );

		if ( is_wp_error( $template ) ) {
			$this->log_activity( 'update_template', $request, null, 400 );
			return $this->error_response( $template->get_error_code(), $template->get_error_message(), 400 );
		}

		$this->log_activity( 'update_template', $request, $template );

		return $this->success_response( $template );
	}

	/**
	 * Delete a template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_template( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$force       = (bool) $request->get_param( 'force' );

		$result = $this->elementor_pro->delete_template( $template_id, $force );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'delete_template', $request, null, 400 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'delete_template', $request );

		return $this->success_response( array(
			'deleted' => true,
			'id'      => $template_id,
		) );
	}

	/**
	 * Apply template to page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function apply_template( $request ) {
		$template_id = absint( $request->get_param( 'id' ) );
		$page_id     = absint( $request->get_param( 'page_id' ) );

		if ( ! $page_id ) {
			return $this->error_response( 'missing_page_id', __( 'Page ID is required.', 'mcpwp' ), 400 );
		}

		$result = $this->elementor_pro->apply_template_to_page( $page_id, $template_id );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'apply_template', $request, null, 400 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'apply_template', $request );

		return $this->success_response( array(
			'applied'     => true,
			'template_id' => $template_id,
			'page_id'     => $page_id,
			'edit_url'    => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
		) );
	}

	/**
	 * List reusable Elementor archetypes.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_archetypes( $request ) {
		$args = array(
			'per_page'        => $request->get_param( 'per_page' ) ?: 50,
			'page'            => $request->get_param( 'page' ) ?: 1,
			'scope'           => $request->get_param( 'scope' ),
			'archetype_class' => $request->get_param( 'archetype_class' ),
			'style'           => $request->get_param( 'style' ),
			'search'          => $request->get_param( 'search' ),
		);

		$archetypes = $this->elementor_pro->get_archetypes( $args );

		$this->log_activity( 'get_archetypes', $request, $archetypes );

		return $this->success_response(
			array(
				'archetypes' => $archetypes,
				'total'      => count( $archetypes ),
			)
		);
	}

	/**
	 * Get a reusable Elementor archetype.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_archetype( $request ) {
		$archetype_id = absint( $request->get_param( 'id' ) );
		$archetype    = $this->elementor_pro->get_archetype( $archetype_id );

		if ( is_wp_error( $archetype ) ) {
			$this->log_activity( 'get_archetype', $request, null, 404 );
			return $this->error_response( $archetype->get_error_code(), $archetype->get_error_message(), 404 );
		}

		$this->log_activity( 'get_archetype', $request, $archetype );

		return $this->success_response( $archetype );
	}

	/**
	 * Create a reusable Elementor archetype.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_archetype( $request ) {
		$data = array(
			'title'           => $request->get_param( 'title' ),
			'type'            => $request->get_param( 'type' ),
			'elementor_data'  => $this->get_elementor_payload_from_request( $request ),
			'archetype_scope' => $request->get_param( 'archetype_scope' ),
			'archetype_class' => $request->get_param( 'archetype_class' ),
			'archetype_style' => $request->get_param( 'archetype_style' ),
		);

		$archetype = $this->elementor_pro->create_archetype( $data );

		if ( is_wp_error( $archetype ) ) {
			$this->log_activity( 'create_archetype', $request, null, 400 );
			return $this->error_response( $archetype->get_error_code(), $archetype->get_error_message(), 400 );
		}

		$this->log_activity( 'create_archetype', $request, $archetype, 201 );

		return $this->success_response( $archetype, 201 );
	}

	/**
	 * Update a reusable Elementor archetype.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_archetype( $request ) {
		$archetype_id = absint( $request->get_param( 'id' ) );
		$data         = array(
			'is_archetype' => true,
		);

		if ( $request->has_param( 'title' ) ) {
			$data['title'] = $request->get_param( 'title' );
		}

		if ( $this->has_elementor_payload_in_request( $request ) ) {
			$data['elementor_data'] = $this->get_elementor_payload_from_request( $request );
		}

		foreach ( array( 'archetype_scope', 'archetype_class', 'archetype_style' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		$archetype = $this->elementor_pro->update_template( $archetype_id, $data );

		if ( is_wp_error( $archetype ) ) {
			$this->log_activity( 'update_archetype', $request, null, 400 );
			return $this->error_response( $archetype->get_error_code(), $archetype->get_error_message(), 400 );
		}

		$this->log_activity( 'update_archetype', $request, $archetype );

		return $this->success_response( $archetype );
	}

	/**
	 * Apply a reusable archetype to a page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function apply_archetype( $request ) {
		$archetype_id = absint( $request->get_param( 'id' ) );
		$page_id      = absint( $request->get_param( 'page_id' ) );

		if ( ! $page_id ) {
			return $this->error_response( 'missing_page_id', __( 'Page ID is required.', 'mcpwp' ), 400 );
		}

		$result = $this->elementor_pro->apply_archetype_to_page( $archetype_id, $page_id );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'apply_archetype', $request, null, 400 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'apply_archetype', $request );

		return $this->success_response(
			array(
				'applied'      => true,
				'archetype_id' => $archetype_id,
				'page_id'      => $page_id,
				'edit_url'     => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
			)
		);
	}

	/**
	 * List reusable Elementor parts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_parts( $request ) {
		// wp_create_elementor_part uses part_tags (array); the list filter is a
		// single tag. Fall back to the first entry when tag isn't given directly (#634).
		$tag = $request->get_param( 'tag' );
		if ( ! $tag ) {
			$part_tags = $request->get_param( 'part_tags' );
			if ( is_array( $part_tags ) && ! empty( $part_tags ) ) {
				$tag = reset( $part_tags );
			} elseif ( is_string( $part_tags ) && '' !== $part_tags ) {
				$tag = $part_tags;
			}
		}

		$args = array(
			'per_page' => $request->get_param( 'per_page' ) ?: 50,
			'page'     => $request->get_param( 'page' ) ?: 1,
			'kind'     => $request->get_param( 'kind' ),
			'style'    => $request->get_param( 'style' ),
			'tag'      => $tag,
			'search'   => $request->get_param( 'search' ),
		);

		$parts = $this->elementor_pro->get_parts( $args );

		$this->log_activity( 'get_parts', $request, $parts );

		return $this->success_response(
			array(
				'parts' => $parts,
				'total' => count( $parts ),
			)
		);
	}

	/**
	 * Get a reusable Elementor part.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_part( $request ) {
		$part_id = absint( $request->get_param( 'id' ) );
		$part    = $this->elementor_pro->get_part( $part_id );

		if ( is_wp_error( $part ) ) {
			$this->log_activity( 'get_part', $request, null, 404 );
			return $this->error_response( $part->get_error_code(), $part->get_error_message(), 404 );
		}

		$this->log_activity( 'get_part', $request, $part );

		return $this->success_response( $part );
	}

	/**
	 * Create a reusable Elementor part.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_part( $request ) {
		$data = array(
			'title'          => $request->get_param( 'title' ),
			'type'           => $request->get_param( 'type' ),
			'elementor_data' => $this->get_elementor_payload_from_request( $request ),
			'part_kind'      => $request->get_param( 'part_kind' ),
			'part_style'     => $request->get_param( 'part_style' ),
			'part_tags'      => $request->get_param( 'part_tags' ),
		);

		$part = $this->elementor_pro->create_part( $data );

		if ( is_wp_error( $part ) ) {
			$this->log_activity( 'create_part', $request, null, 400 );
			return $this->error_response( $part->get_error_code(), $part->get_error_message(), 400 );
		}

		$this->log_activity( 'create_part', $request, $part, 201 );

		return $this->success_response( $part, 201 );
	}

	/**
	 * Update a reusable Elementor part.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_part( $request ) {
		$part_id = absint( $request->get_param( 'id' ) );
		$data    = array(
			'is_part' => true,
		);

		if ( $request->has_param( 'title' ) ) {
			$data['title'] = $request->get_param( 'title' );
		}

		if ( $this->has_elementor_payload_in_request( $request ) ) {
			$data['elementor_data'] = $this->get_elementor_payload_from_request( $request );
		}

		foreach ( array( 'part_kind', 'part_style', 'part_tags' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		$part = $this->elementor_pro->update_template( $part_id, $data );

		if ( is_wp_error( $part ) ) {
			$this->log_activity( 'update_part', $request, null, 400 );
			return $this->error_response( $part->get_error_code(), $part->get_error_message(), 400 );
		}

		$this->log_activity( 'update_part', $request, $part );

		return $this->success_response( $part );
	}

	/**
	 * Create a reusable part from a live section.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_part_from_section( $request ) {
		$page_id    = (int) $request->get_param( 'page_id' );
		$element_id = $request->get_param( 'element_id' );

		if ( ! $page_id || ! $element_id ) {
			return $this->error_response( 'missing_params', __( 'page_id and element_id are required.', 'mcpwp' ), 400 );
		}

		$data = array(
			'title'      => $request->get_param( 'title' ),
			'part_kind'  => $request->get_param( 'part_kind' ),
			'part_style' => $request->get_param( 'part_style' ),
			'part_tags'  => $request->get_param( 'part_tags' ),
		);

		$part = $this->elementor_pro->create_part_from_section( $page_id, $element_id, $data );

		if ( is_wp_error( $part ) ) {
			$this->log_activity( 'create_part_from_section', $request, null, 400 );
			return $this->error_response( $part->get_error_code(), $part->get_error_message(), 400 );
		}

		$this->log_activity( 'create_part_from_section', $request, $part, 201 );

		return $this->success_response( $part, 201 );
	}

	/**
	 * Resolve Elementor payload from request params.
	 *
	 * Shared-host WAFs often reject large nested JSON POST bodies. For those
	 * environments we accept `elementor_data_base64` in addition to the normal
	 * `elementor_data` field so clients can safely submit form-encoded payloads.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array|string|null
	 */
	private function get_elementor_payload_from_request( $request ) {
		$base64 = $request->get_param( 'elementor_data_base64' );
		if ( is_string( $base64 ) && '' !== $base64 ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$decoded = base64_decode( $base64, true );
			if ( false !== $decoded && '' !== $decoded ) {
				return $decoded;
			}
		}

		return $request->get_param( 'elementor_data' );
	}

	/**
	 * Check whether the request explicitly includes Elementor payload data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool
	 */
	private function has_elementor_payload_in_request( $request ) {
		return $request->has_param( 'elementor_data_base64' ) || $request->has_param( 'elementor_data' );
	}

	/**
	 * Apply a reusable part to a page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function apply_part( $request ) {
		$part_id = absint( $request->get_param( 'id' ) );
		$page_id = absint( $request->get_param( 'page_id' ) );
		// Default is insert (non-destructive) — omission must never silently erase
		// a page's existing content (#637). Callers opt into replace explicitly.
		$mode    = sanitize_key( (string) ( $request->get_param( 'mode' ) ?: 'insert' ) );
		$position = (string) ( $request->get_param( 'position' ) ?: 'end' );

		if ( ! $page_id ) {
			return $this->error_response( 'missing_page_id', __( 'Page ID is required.', 'mcpwp' ), 400 );
		}

		if ( 'insert' === $mode ) {
			$result = $this->elementor_pro->insert_part_into_page( $part_id, $page_id, $position );
		} else {
			$result = $this->elementor_pro->apply_part_to_page( $part_id, $page_id );
		}

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'apply_part', $request, null, 400 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'apply_part', $request );

		return $this->success_response(
			array(
				'applied'  => true,
				'part_id'  => $part_id,
				'page_id'  => $page_id,
				'mode'     => $mode,
				'position' => 'insert' === $mode ? $position : null,
				'details'  => $result,
				'edit_url' => admin_url( 'post.php?post=' . $page_id . '&action=elementor' ),
			)
		);
	}

	/**
	 * Clone a page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function clone_page( $request ) {
		$source_id = absint( $request->get_param( 'source_id' ) );

		if ( ! $source_id ) {
			return $this->error_response( 'missing_source_id', __( 'Source ID is required.', 'mcpwp' ), 400 );
		}

		$args = array(
			'title'  => $request->get_param( 'title' ),
			'status' => $request->get_param( 'status' ),
			'parent' => $request->get_param( 'parent' ),
		);

		$result = $this->elementor_pro->clone_page( $source_id, $args );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'clone_page', $request, null, 400 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'clone_page', $request, $result, 201 );

		return $this->success_response( $result, 201 );
	}

	/**
	 * Create a landing page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_landing_page( $request ) {
		$data = array(
			'title'          => $request->get_param( 'title' ),
			'status'         => $request->get_param( 'status' ),
			'template_id'    => $request->get_param( 'template_id' ),
			'sections'       => $request->get_param( 'sections' ),
			'elementor_data' => $request->get_param( 'elementor_data' ),
		);

		$result = $this->elementor_pro->create_landing_page( $data );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'create_landing_page', $request, null, 400 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		$this->log_activity( 'create_landing_page', $request, $result, 201 );

		return $this->success_response( $result, 201 );
	}

	/**
	 * Get available widgets.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_widgets( $request ) {
		$widget_name = $request->get_param( 'widget' );
		$result      = $this->elementor_pro->get_available_widgets( $widget_name );

		if ( is_wp_error( $result ) ) {
			$this->log_activity( 'get_widgets', $request, null, 404 );
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 404 );
		}

		$this->log_activity( 'get_widgets', $request );

		// Single widget returns the widget object directly.
		if ( $widget_name ) {
			return $this->success_response( $result );
		}

		return $this->success_response( array(
			'widgets' => $result,
			'total'   => count( $result ),
		) );
	}

	/**
	 * Get global settings.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_globals( $request ) {
		$globals = $this->elementor_pro->get_globals();

		if ( is_wp_error( $globals ) ) {
			$this->log_activity( 'get_globals', $request, null, 400 );
			return $this->error_response( $globals->get_error_code(), $globals->get_error_message(), 400 );
		}

		$this->log_activity( 'get_globals', $request, $globals );

		return $this->success_response( $globals );
	}

	/**
	 * Set Elementor global settings (colors, fonts, etc.).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function set_globals( $request ) {
		$this->log_activity( 'set_elementor_globals', $request );

		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $this->error_response( 'elementor_not_active', __( 'Elementor is not active.', 'mcpwp' ), 400 );
		}

		$params = $request->get_json_params();
		if ( empty( $params ) ) {
			$params = $request->get_params();
		}

		if ( empty( $params ) || ! is_array( $params ) ) {
			return $this->error_response( 'missing_params', __( 'Globals data is required.', 'mcpwp' ), 400 );
		}

		// Get the active kit
		$kit = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
		if ( ! $kit || ! $kit->get_id() ) {
			return $this->error_response( 'no_kit', __( 'No active Elementor kit found.', 'mcpwp' ), 500 );
		}

		$kit_id       = $kit->get_id();
		$existing     = get_post_meta( $kit_id, '_elementor_page_settings', true );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		// Merge provided settings
		$updated = array_replace_recursive( $existing, $params );

		update_post_meta( $kit_id, '_elementor_page_settings', $updated );

		// Clear Elementor caches
		if ( method_exists( \Elementor\Plugin::$instance->files_manager, 'clear_cache' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		// Warn about unscoped CSS selectors that could break header/footer templates (#205).
		$warnings = array();
		$custom_css = isset( $updated['custom_css'] ) ? $updated['custom_css'] : '';
		if ( $custom_css ) {
			$bare_selectors = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body', 'p', 'a', 'ul', 'ol', 'li', 'img', 'div', 'span', 'section', 'header', 'footer', 'nav', 'main' );
			foreach ( $bare_selectors as $sel ) {
				if ( preg_match( '/(?:^|\n)\s*' . preg_quote( $sel, '/' ) . '\s*\{/m', $custom_css ) ) {
					$warnings[] = sprintf(
						'Kit CSS contains unscoped "%s" selector — this will affect header/footer templates. Scope it to .site-main or .elementor to avoid breaking theme builder templates.',
						$sel
					);
				}
			}
		}

		$response = array(
			'kit_id'   => $kit_id,
			'settings' => $updated,
			'message'  => __( 'Elementor globals updated.', 'mcpwp' ),
		);
		if ( ! empty( $warnings ) ) {
			$response['warnings'] = $warnings;
		}

		return $this->success_response( $response );
	}

	/**
	 * Build a page from section blueprints.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function build_page( $request ) {
		$this->log_activity( 'build_page', $request );

		$title    = $request->get_param( 'title' );
		$sections = $request->get_param( 'sections' );
		$status   = $request->get_param( 'status' ) ?: 'draft';

		$builder = new Mcpwp_Page_Builder();
		$result  = $builder->build( $title, $sections, $status );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	/**
	 * List available blueprint types with parameter schemas.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_blueprints( $request ) {
		$this->log_activity( 'list_blueprints', $request );

		$catalog = Mcpwp_Page_Builder::get_blueprint_catalog();

		return $this->success_response( array(
			'count'      => count( $catalog ),
			'blueprints' => $catalog,
		) );
	}

	/**
	 * Build a single section from a blueprint type.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function get_blueprint( $request ) {
		$this->log_activity( 'get_blueprint', $request );

		$type   = $request->get_param( 'type' );
		$params = $request->get_params();

		if ( empty( $type ) ) {
			return new WP_Error( 'missing_type', 'Blueprint type is required.', array( 'status' => 400 ) );
		}

		$builder = new Mcpwp_Page_Builder();
		$result  = $builder->build_single_section( $type, $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Save a section from a live page as a reusable Elementor template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response or error.
	 */
	public function save_section_as_template( $request ) {
		$this->log_activity( 'save_section_as_template', $request );

		$page_id    = (int) $request->get_param( 'page_id' );
		$element_id = $request->get_param( 'element_id' );
		$title      = $request->get_param( 'title' );

		if ( ! $page_id || ! $element_id ) {
			return new WP_Error( 'missing_params', 'page_id and element_id are required.', array( 'status' => 400 ) );
		}

		$result = $this->elementor_pro->save_section_as_template( $page_id, $element_id, $title );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}
}
