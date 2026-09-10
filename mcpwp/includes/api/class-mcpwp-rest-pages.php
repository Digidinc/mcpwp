<?php
/**
 * Pages REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pages REST controller.
 */
class Mcpwp_REST_Pages extends Mcpwp_REST_API {

	/**
	 * Pages handler.
	 *
	 * @var Mcpwp_Pages
	 */
	private $pages;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->pages = new Mcpwp_Pages();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// List pages
		register_rest_route(
			$this->namespace,
			'/pages',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_pages' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$this->get_pagination_args(),
						array(
							'status' => array(
								'description' => __( 'Page status filter.', 'mcpwp' ),
								'type'        => 'string',
								'default'     => 'any',
							),
							'parent' => array(
								'description' => __( 'Parent page ID.', 'mcpwp' ),
								'type'        => 'integer',
							),
							'ids'    => array(
								'description' => __( 'Comma-separated page IDs to fetch.', 'mcpwp' ),
								'type'        => 'string',
							),
							'fields' => array(
								'description' => __( 'Comma-separated field names to return (e.g. id,title,word_count,content).', 'mcpwp' ),
								'type'        => 'string',
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_page' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						array(
						'title'    => array(
							'description' => __( 'Page title.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'content'  => array(
							'description' => __( 'Page content.', 'mcpwp' ),
							'type'        => 'string',
							'default'     => '',
						),
						'excerpt'  => array(
							'description' => __( 'Page excerpt (used as the hero deck on some templates).', 'mcpwp' ),
							'type'        => 'string',
							'default'     => '',
						),
						'status'   => array(
							'description' => __( 'Page status.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
							'default'     => 'draft',
						),
						'parent'   => array(
							'description' => __( 'Parent page ID.', 'mcpwp' ),
							'type'        => 'integer',
						),
						'template' => array(
							'description' => __( 'Page template.', 'mcpwp' ),
							'type'        => 'string',
						),
						),
						array_intersect_key( $this->get_post_args(), array( 'terms' => 1, 'meta' => 1 ) )
					),
				),
			)
		);

		// Single page
		register_rest_route(
			$this->namespace,
			'/pages/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_page' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $this->get_post_update_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_page' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'force' => array(
							'description' => __( 'Bypass trash and force deletion.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);

		// Bulk create/update pages
		register_rest_route(
			$this->namespace,
			'/pages/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_create_pages' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'pages' => array(
							'description' => __( 'Array of page objects to create.', 'mcpwp' ),
							'type'        => 'array',
							'required'    => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'bulk_update_pages' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'pages' => array(
							'description' => __( 'Array of page objects to update. Each must have id.', 'mcpwp' ),
							'type'        => 'array',
							'required'    => false,
						),
						'updates' => array(
							'description' => __( 'Array of objects to update (alias of pages). Each carries id plus the fields to change.', 'mcpwp' ),
							'type'        => 'array',
							'required'    => false,
						),
					),
				),
			)
		);

		// Clone page
		register_rest_route(
			$this->namespace,
			'/pages/(?P<id>\d+)/clone',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'clone_page' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'title'  => array(
							'description' => __( 'Title for the cloned page. Defaults to original title with (Copy) suffix.', 'mcpwp' ),
							'type'        => 'string',
						),
						'status' => array(
							'description' => __( 'Status for the cloned page.', 'mcpwp' ),
							'type'        => 'string',
							'enum'        => array( 'publish', 'draft', 'pending', 'private' ),
							'default'     => 'draft',
						),
					),
				),
			)
		);

		// Get page by slug
		register_rest_route(
			$this->namespace,
			'/pages/by-slug/(?P<slug>[a-z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page_by_slug' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		// Update page template
		register_rest_route(
			$this->namespace,
			'/pages/(?P<id>\d+)/template',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_page_template' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'template' => array(
							'description' => __( 'Template slug (e.g., default, elementor_header_footer, elementor_canvas).', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
					),
				),
			)
		);

		// Page templates list
		register_rest_route(
			$this->namespace,
			'/templates/page',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_page_templates' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * List pages.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_pages( $request ) {
		$this->log_activity( 'list_pages', $request );

		$args = $this->sanitize_query_args( $request->get_params() );

		if ( $request->get_param( 'parent' ) ) {
			$args['post_parent'] = absint( $request->get_param( 'parent' ) );
		}

		$result = $this->pages->list_pages( $args );

		return $this->success_response( $result );
	}

	/**
	 * Get single page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_page( $request ) {
		$this->log_activity( 'get_page', $request );

		$page_id = $request->get_param( 'id' );

		// Non-public gate: get_page() formats the post regardless of status, so a
		// read-only key could otherwise read a draft/pending/private body. Return
		// the same 404 as a missing page so existence is never disclosed.
		$denied = $this->guard_non_public_post(
			get_post( absint( $page_id ) ),
			__( 'Page not found.', 'mcpwp' )
		);
		if ( $denied ) {
			return $denied;
		}

		$result = $this->pages->get_page( $page_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Create page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function create_page( $request ) {
		$this->log_activity( 'create_page', $request );

		$data   = Mcpwp_Posts::body_fields( $request );
		$result = $this->pages->create_page( $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	/**
	 * Update page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_page( $request ) {
		$this->log_activity( 'update_page', $request );

		$page_id = $request->get_param( 'id' );
		$data    = Mcpwp_Posts::body_fields( $request );
		unset( $data['id'] );

		$result = $this->pages->update_page( $page_id, $data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Delete page.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_page( $request ) {
		$this->log_activity( 'delete_page', $request );

		$page_id = absint( $request->get_param( 'id' ) );
		$force   = (bool) $request->get_param( 'force' );

		// A permanent delete has no undo: approval by default (#536). Only for
		// a page that exists — a deferral is not a way to manufacture records.
		$page = Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ? get_post( $page_id ) : null;
		if ( $page && Mcpwp_Destructive_Gate::delete_is_permanent( 'page', $force, (string) $page->post_status ) ) {
			if ( 'page' !== $page->post_type ) {
				return $this->error_response( 'not_found', __( 'Page not found.', 'mcpwp' ), 404, array( 'id' => $page_id ) );
			}
			return $this->defer_destructive( 'delete_page', $request, array( 'type' => 'page', 'id' => $page_id, 'title' => (string) $page->post_title ), sprintf( 'Permanently delete page #%d', $page_id ) );
		}

		$result = $this->pages->delete_page( $page_id, $force );

		if ( is_wp_error( $result ) ) {
			return $this->error_response( $result->get_error_code(), $result->get_error_message(), 400 );
		}

		return $this->success_response(
			array(
				'deleted' => true,
				'id'      => $page_id,
				'trashed' => ! $force,
			)
		);
	}

	/**
	 * Update a page's template.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_page_template( $request ) {
		$this->log_activity( 'update_page_template', $request );

		$page_id  = absint( $request->get_param( 'id' ) );
		$template = sanitize_text_field( (string) $request->get_param( 'template' ) );

		$page = get_post( $page_id );
		if ( ! $page || 'page' !== $page->post_type ) {
			return $this->error_response(
				'not_found',
				__( 'Page not found.', 'mcpwp' ),
				404,
				array( 'id' => $page_id )
			);
		}

		$old_template = get_post_meta( $page_id, '_wp_page_template', true ) ?: 'default';

		if ( 'default' === $template ) {
			delete_post_meta( $page_id, '_wp_page_template' );
		} else {
			update_post_meta( $page_id, '_wp_page_template', $template );
		}

		return $this->success_response(
			array(
				'success'      => true,
				'page_id'      => $page_id,
				'title'        => $page->post_title,
				'old_template' => $old_template,
				'new_template' => $template,
			)
		);
	}

	/**
	 * Clone a page with its Elementor data.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function clone_page( $request ) {
		$this->log_activity( 'clone_page', $request );

		$page_id = absint( $request->get_param( 'id' ) );
		$page    = get_post( $page_id );

		if ( ! $page || 'page' !== $page->post_type ) {
			return $this->error_response(
				'not_found',
				__( 'Page not found.', 'mcpwp' ),
				404,
				array( 'id' => $page_id )
			);
		}

		$title  = $request->get_param( 'title' );
		$status = $request->get_param( 'status' ) ?: 'draft';

		if ( empty( $title ) ) {
			/* translators: %s: original page title */
			$title = sprintf( __( '%s (Copy)', 'mcpwp' ), $page->post_title );
		}

		// Read the source tree first, in whatever form it is stored (#845). Data
		// that cannot be a tree is refused before a clone exists, so no
		// half-made page is left behind and nothing unreadable is duplicated.
		$source_tree = Mcpwp_Elementor_Stored_Tree::of_post( $page_id );
		if ( Mcpwp_Elementor_Stored_Tree::is_unusable( $source_tree ) ) {
			return Mcpwp_Elementor_Stored_Tree::refusal( $source_tree, "The source page's Elementor data" );
		}

		// Create the clone.
		$new_page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => sanitize_text_field( $title ),
				'post_content' => $page->post_content,
				'post_status'  => sanitize_key( $status ),
				'post_parent'  => $page->post_parent,
				'menu_order'   => $page->menu_order,
			),
			true
		);

		if ( is_wp_error( $new_page_id ) ) {
			return $new_page_id;
		}

		// Copy page template.
		$template = get_post_meta( $page_id, '_wp_page_template', true );
		if ( $template ) {
			update_post_meta( $new_page_id, '_wp_page_template', $template );
		}

		// Copy Elementor data. Text is copied byte for byte; an array-stored
		// tree is written as JSON text, the form every write stores (#845).
		$elementor_data = get_post_meta( $page_id, '_elementor_data', true );
		if ( is_array( $elementor_data ) ) {
			$elementor_data = empty( $source_tree['elements'] ) ? '' : (string) wp_json_encode( $source_tree['elements'] );
		}
		if ( $elementor_data ) {
			// Cloning copies meta byte for byte, so a source page still holding
			// the pre-#678 `css_id` produced a new page that never passes
			// through the validator either (#737).
			if ( class_exists( 'Mcpwp_Elementor_Element_Keys' ) ) {
				$elementor_data = Mcpwp_Elementor_Element_Keys::normalize_json( $elementor_data );
			}

			Mcpwp_Elementor_Meta_Write::update( $new_page_id, '_elementor_data', wp_slash( $elementor_data ) );
			update_post_meta( $new_page_id, '_elementor_edit_mode', 'builder' );

			$template_type = get_post_meta( $page_id, '_elementor_template_type', true );
			if ( $template_type ) {
				update_post_meta( $new_page_id, '_elementor_template_type', $template_type );
			}
		}

		// Copy featured image.
		$thumbnail_id = get_post_thumbnail_id( $page_id );
		if ( $thumbnail_id ) {
			set_post_thumbnail( $new_page_id, $thumbnail_id );
		}

		$result = $this->pages->get_page( $new_page_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	/**
	 * Get a page by slug.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function get_page_by_slug( $request ) {
		$this->log_activity( 'get_page_by_slug', $request );

		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );

		$page = get_page_by_path( $slug, OBJECT, 'page' );

		if ( ! $page ) {
			return $this->error_response(
				'not_found',
				__( 'Page not found.', 'mcpwp' ),
				404,
				array( 'id' => $slug )
			);
		}

		// Non-public gate: WordPress's get_page_by_path() resolves non-public
		// statuses too, so a read-only key must not read a draft body via slug.
		$denied = $this->guard_non_public_post( $page, __( 'Page not found.', 'mcpwp' ) );
		if ( $denied ) {
			return $denied;
		}

		$result = $this->pages->get_page( $page->ID );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Get available page templates.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function get_page_templates( $request ) {
		$this->log_activity( 'get_page_templates', $request );

		$templates = wp_get_theme()->get_page_templates();

		$formatted = array(
			array(
				'slug' => 'default',
				'name' => __( 'Default Template', 'mcpwp' ),
			),
		);

		foreach ( $templates as $slug => $name ) {
			$formatted[] = array(
				'slug' => $slug,
				'name' => $name,
			);
		}

		return $this->success_response(
			array(
				'templates' => $formatted,
				'total'     => count( $formatted ),
			)
		);
	}

	/**
	 * Bulk create pages.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function bulk_create_pages( $request ) {
		$this->log_activity( 'bulk_create_pages', $request );

		$pages_data = $request->get_param( 'pages' );

		if ( ! is_array( $pages_data ) || empty( $pages_data ) ) {
			return $this->error_response( 'invalid_pages', __( 'Pages must be a non-empty array.', 'mcpwp' ), 400 );
		}

		if ( count( $pages_data ) > 50 ) {
			return $this->error_response( 'too_many_pages', __( 'Maximum 50 pages per batch.', 'mcpwp' ), 400 );
		}

		$normalized = $this->normalize_bulk_items( $pages_data, 'page' );
		$pages_data = $normalized['items'];

		$created = array();
		$errors  = $normalized['errors'];

		foreach ( $pages_data as $index => $page_item ) {
			if ( empty( $page_item['title'] ) ) {
				$errors[] = array(
					'index'   => $index,
					'message' => __( 'Title is required.', 'mcpwp' ),
				);
				continue;
			}

			$result = $this->pages->create_page( $page_item );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'index'   => $index,
					'title'   => $page_item['title'],
					'message' => $result->get_error_message(),
				);
			} else {
				$created[] = $result;
			}
		}

		return $this->success_response(
			array(
				'created' => $created,
				'errors'  => $errors,
				'total'   => count( $created ),
			),
			201
		);
	}

	/**
	 * Bulk update pages.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function bulk_update_pages( $request ) {
		$this->log_activity( 'bulk_update_pages', $request );

		$pages_data = $request->get_param( 'pages' );

		// `updates` is what the tool name promises; `pages` is the original
		// spelling. Either is fine, and the error names both (#803).
		if ( ! is_array( $pages_data ) || empty( $pages_data ) ) {
			$pages_data = $request->get_param( 'updates' );
		}

		if ( ! is_array( $pages_data ) || empty( $pages_data ) ) {
			return $this->error_response( 'invalid_pages', __( 'Pass the items to update as `updates` (or `pages`): a non-empty array of objects, each with an id.', 'mcpwp' ), 400 );
		}

		if ( count( $pages_data ) > 50 ) {
			return $this->error_response( 'too_many_pages', __( 'Maximum 50 pages per batch.', 'mcpwp' ), 400 );
		}

		$normalized = $this->normalize_bulk_items( $pages_data, 'page' );
		$pages_data = $normalized['items'];

		$updated = array();
		$errors  = $normalized['errors'];

		foreach ( $pages_data as $index => $page_item ) {
			if ( empty( $page_item['id'] ) ) {
				$errors[] = array(
					'index'   => $index,
					'message' => __( 'id is required for each page.', 'mcpwp' ),
				);
				continue;
			}

			$page_id = absint( $page_item['id'] );
			$data    = $page_item;
			unset( $data['id'] );

			$result = $this->pages->update_page( $page_id, $data );

			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'index'   => $index,
					'id'      => $page_id,
					'message' => $result->get_error_message(),
				);
			} else {
				$updated[] = $result;
			}
		}

		return $this->success_response(
			array(
				'updated' => $updated,
				'errors'  => $errors,
				'total'   => count( $updated ),
			)
		);
	}
}
