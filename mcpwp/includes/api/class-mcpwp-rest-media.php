<?php
/**
 * Media REST Controller
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media REST controller.
 */
class Mcpwp_REST_Media extends Mcpwp_REST_API {

	/**
	 * Media handler.
	 *
	 * @var Mcpwp_Media
	 */
	private $media;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->media = new Mcpwp_Media();
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		// Upload media
		register_rest_route(
			$this->namespace,
			'/media',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_media' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array_merge(
						$this->get_pagination_args(),
						array(
							'mime_type' => array(
								'description' => __( 'Filter by mime type (e.g., "image", "image/png", "video").', 'mcpwp' ),
								'type'        => 'string',
							),
							'search'    => array(
								'description' => __( 'Search media by title or filename.', 'mcpwp' ),
								'type'        => 'string',
							),
						)
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_media' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'title' => array(
							'description' => __( 'Media title.', 'mcpwp' ),
							'type'        => 'string',
						),
						'alt'   => array(
							'description' => __( 'Alt text.', 'mcpwp' ),
							'type'        => 'string',
						),
					),
				),
			)
		);

		// Upload from URL
		register_rest_route(
			$this->namespace,
			'/media/from-url',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_from_url' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'url'      => array(
							'description' => __( 'External URL.', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
							'format'      => 'uri',
						),
						'title'    => array(
							'description' => __( 'Media title.', 'mcpwp' ),
							'type'        => 'string',
						),
						'alt'      => array(
							'description' => __( 'Alt text.', 'mcpwp' ),
							'type'        => 'string',
						),
						'filename' => array(
							'description' => __( 'Custom filename.', 'mcpwp' ),
							'type'        => 'string',
						),
					),
				),
			)
		);

		// Bulk upload from URLs
		register_rest_route(
			$this->namespace,
			'/media/bulk',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'bulk_upload' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'urls'  => array(
							'description' => __( 'Array of URLs to upload.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'string',
							),
						),
						'items' => array(
							'description' => __( 'Array of items with url, title, alt.', 'mcpwp' ),
							'type'        => 'array',
							'items'       => array(
								'type' => 'object',
							),
						),
					),
				),
			)
		);

		// Upload from Base64
		register_rest_route(
			$this->namespace,
			'/media/from-base64',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_from_base64' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'data'     => array(
							'description' => __( 'Base64-encoded file content. Optionally prefixed with data URI (e.g., data:image/png;base64,...).', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'filename' => array(
							'description' => __( 'Filename with extension (e.g., logo.png).', 'mcpwp' ),
							'type'        => 'string',
							'required'    => true,
						),
						'title'     => array(
							'description' => __( 'Media title.', 'mcpwp' ),
							'type'        => 'string',
						),
						'alt'       => array(
							'description' => __( 'Alt text.', 'mcpwp' ),
							'type'        => 'string',
						),
						'mime_type' => array(
							'description' => __( 'MIME type (e.g. image/png, image/svg+xml). Inferred from filename when omitted.', 'mcpwp' ),
							'type'        => 'string',
						),
					),
				),
			)
		);

		// Update / delete media by ID.
		register_rest_route(
			$this->namespace,
			'/media/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_media' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'alt'         => array(
							'description' => __( 'Image alt text.', 'mcpwp' ),
							'type'        => 'string',
						),
						'title'       => array(
							'description' => __( 'Attachment title.', 'mcpwp' ),
							'type'        => 'string',
						),
						'caption'     => array(
							'description' => __( 'Attachment caption (short description).', 'mcpwp' ),
							'type'        => 'string',
						),
						'description' => array(
							'description' => __( 'Attachment description (long description).', 'mcpwp' ),
							'type'        => 'string',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_media' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'force' => array(
							'description' => __( 'Permanently delete instead of trashing.', 'mcpwp' ),
							'type'        => 'boolean',
							'default'     => false,
						),
					),
				),
			)
		);
	}

	/**
	 * List media.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response.
	 */
	public function list_media( $request ) {
		$this->log_activity( 'list_media', $request );

		$args = array(
			'posts_per_page' => $request->get_param( 'per_page' ) ?: 20,
			'paged'          => $request->get_param( 'page' ) ?: 1,
			'mime_type'      => $request->get_param( 'mime_type' ),
			'search'         => $request->get_param( 'search' ),
		);

		$result = $this->media->list_media( $args );

		return $this->success_response( $result );
	}

	/**
	 * Upload media file.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function upload_media( $request ) {
		$this->log_activity( 'upload_media', $request );

		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return $this->error_response(
				'no_file',
				__( 'No file uploaded. Send file as multipart/form-data with "file" field.', 'mcpwp' ),
				400
			);
		}

		$args = array(
			'title' => $request->get_param( 'title' ),
			'alt'   => $request->get_param( 'alt' ),
		);

		$result = $this->media->upload_file( $files['file'], $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	/**
	 * Upload media from URL.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function upload_from_url( $request ) {
		$this->log_activity( 'upload_from_url', $request );

		$url = $request->get_param( 'url' );

		if ( empty( $url ) ) {
			return $this->error_response(
				'missing_url',
				__( 'URL is required.', 'mcpwp' ),
				400
			);
		}

		$args = array(
			'title'    => $request->get_param( 'title' ),
			'alt'      => $request->get_param( 'alt' ),
			'filename' => $request->get_param( 'filename' ),
		);

		$result = $this->media->upload_from_url( $url, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	/**
	 * Bulk upload media from URLs.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function bulk_upload( $request ) {
		$this->log_activity( 'bulk_upload', $request );

		$urls  = $request->get_param( 'urls' );
		$items = $request->get_param( 'items' );

		// Normalize input - support both 'urls' array and 'items' array
		$to_upload = array();

		if ( ! empty( $items ) && is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( ! empty( $item['url'] ) ) {
					$to_upload[] = array(
						'url'      => $item['url'],
						'title'    => isset( $item['title'] ) ? $item['title'] : null,
						'alt'      => isset( $item['alt'] ) ? $item['alt'] : null,
						'filename' => isset( $item['filename'] ) ? $item['filename'] : null,
					);
				}
			}
		} elseif ( ! empty( $urls ) && is_array( $urls ) ) {
			foreach ( $urls as $url ) {
				$to_upload[] = array(
					'url'      => $url,
					'title'    => null,
					'alt'      => null,
					'filename' => null,
				);
			}
		}

		if ( empty( $to_upload ) ) {
			return $this->error_response(
				'missing_urls',
				__( 'Provide either "urls" array or "items" array with url properties.', 'mcpwp' ),
				400
			);
		}

		// Limit bulk uploads
		$max_uploads = 20;
		if ( count( $to_upload ) > $max_uploads ) {
			return $this->error_response(
				'too_many_files',
				/* translators: %d: maximum number of files */
				sprintf( __( 'Maximum %d files per request.', 'mcpwp' ), $max_uploads ),
				400
			);
		}

		$results = array(
			'success' => array(),
			'failed'  => array(),
		);

		foreach ( $to_upload as $item ) {
			$args = array(
				'title'    => $item['title'],
				'alt'      => $item['alt'],
				'filename' => $item['filename'],
			);

			$result = $this->media->upload_from_url( $item['url'], $args );

			if ( is_wp_error( $result ) ) {
				$results['failed'][] = array(
					'url'   => $item['url'],
					'error' => $result->get_error_message(),
				);
			} else {
				$results['success'][] = $result;
			}
		}

		return $this->success_response(
			array(
				'uploaded' => count( $results['success'] ),
				'failed'   => count( $results['failed'] ),
				'media'    => $results['success'],
				'errors'   => $results['failed'],
			),
			201
		);
	}

	/**
	 * Upload media from Base64.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function upload_from_base64( $request ) {
		$this->log_activity( 'upload_from_base64', $request );

		$data     = $request->get_param( 'data' );
		$filename = $request->get_param( 'filename' );

		if ( empty( $data ) || empty( $filename ) ) {
			return $this->error_response(
				'missing_params',
				__( 'Both "data" (Base64 string) and "filename" are required.', 'mcpwp' ),
				400
			);
		}

		$args = array(
			'title'     => $request->get_param( 'title' ),
			'alt'       => $request->get_param( 'alt' ),
			'mime_type' => $request->get_param( 'mime_type' ),
		);

		$result = $this->media->upload_from_base64( $data, $filename, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result, 201 );
	}

	/**
	 * Update media attachment metadata.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function update_media( $request ) {
		$this->log_activity( 'update_media', $request );

		$attachment_id = absint( $request->get_param( 'id' ) );
		$args          = array_filter(
			array(
				'alt'         => $request->get_param( 'alt' ),
				'title'       => $request->get_param( 'title' ),
				'caption'     => $request->get_param( 'caption' ),
				'description' => $request->get_param( 'description' ),
			),
			static function ( $v ) {
				return null !== $v;
			}
		);

		if ( empty( $args ) ) {
			return $this->error_response( 'no_fields', __( 'Provide at least one field to update: alt, title, caption, or description.', 'mcpwp' ), 400 );
		}

		$result = $this->media->update_media( $attachment_id, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}

	/**
	 * Delete a media attachment.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response.
	 */
	public function delete_media( $request ) {
		$this->log_activity( 'delete_media', $request );

		$attachment_id = absint( $request->get_param( 'id' ) );
		$force         = rest_sanitize_boolean( $request->get_param( 'force' ) );

		// Deleting media removes files from disk: approval by default (#536).
		if ( $force && Mcpwp_Destructive_Gate::should_gate( $this->get_current_api_key_record() ) ) {
			$attachment = get_post( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return $this->error_response( 'not_found', __( 'Media not found.', 'mcpwp' ), 404, array( 'id' => $attachment_id ) );
			}
			return $this->defer_destructive( 'delete_media', $request, array( 'type' => 'attachment', 'id' => $attachment_id, 'title' => (string) $attachment->post_title ), sprintf( 'Permanently delete media #%d', $attachment_id ) );
		}

		$result = $this->media->delete_media( $attachment_id, $force );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->success_response( $result );
	}
}
