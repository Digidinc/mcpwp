<?php
/**
 * Media handler
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle media operations.
 */
class Mcpwp_Media {

	use Mcpwp_Sanitization;

	/**
	 * Upload media from file.
	 *
	 * @param array $file File data from $_FILES.
	 * @param array $args Additional arguments.
	 * @return array|WP_Error Attachment data or error.
	 */
	public function upload_file( $file, $args = array() ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// Validate file
		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error(
				'no_file',
				__( 'No file uploaded.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		// Upload the file
		$upload = wp_handle_upload(
			$file,
			array( 'test_form' => false )
		);

		if ( isset( $upload['error'] ) ) {
			return new WP_Error(
				'upload_error',
				$upload['error'],
				array( 'status' => 400 )
			);
		}

		// Create attachment
		$attachment = array(
			'post_mime_type' => $upload['type'],
			'post_title'     => isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate metadata
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Set alt text
		if ( ! empty( $args['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
		}

		// Set caption
		if ( ! empty( $args['caption'] ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => sanitize_textarea_field( $args['caption'] ),
				)
			);
		}

		return $this->format_attachment( $attachment_id );
	}

	/**
	 * Upload media from URL.
	 *
	 * @param string $url  External URL.
	 * @param array  $args Additional arguments.
	 * @return array|WP_Error Attachment data or error.
	 */
	public function upload_from_url( $url, $args = array() ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// Validate URL
		$url = esc_url_raw( $url );
		if ( empty( $url ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Invalid URL provided.', 'mcpwp' ),
				array(
					'status' => 400,
					'hint'   => 'Provide a fully qualified URL starting with http:// or https://. The URL must point to a publicly accessible file.',
				)
			);
		}

		// SSRF protection: block internal/private URLs — validated here for an
		// early, clear error, and again on every redirect hop inside
		// safe_download_url() so a 30x to an internal IP can't slip past.
		if ( class_exists( 'Mcpwp_Security' ) ) {
			$ssrf_check = Mcpwp_Security::validate_external_url( $url );
			if ( is_wp_error( $ssrf_check ) ) {
				return $ssrf_check;
			}
		}

		// Download the file. Use the SSRF-guarded downloader (not core
		// download_url(), which follows redirects with no re-validation) so a
		// public URL cannot redirect into the private network.
		$tmp = class_exists( 'Mcpwp_Security' )
			? Mcpwp_Security::safe_download_url( $url )
			: download_url( $url );

		if ( is_wp_error( $tmp ) ) {
			return new WP_Error(
				'download_error',
				$tmp->get_error_message(),
				array(
					'status' => 400,
					'hint'   => 'Failed to download the file from the URL. Check that the URL is publicly accessible and returns a valid file. Supported formats: jpg, png, gif, webp, pdf. (SVG is not accepted — stored-XSS risk.)',
				)
			);
		}

		// Get file info
		$file_array = array(
			'name'     => isset( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : basename( wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp,
		);

		// If no extension, try to detect
		if ( ! pathinfo( $file_array['name'], PATHINFO_EXTENSION ) ) {
			$mime = mime_content_type( $tmp );
			$ext = $this->mime_to_extension( $mime );
			if ( $ext ) {
				$file_array['name'] .= '.' . $ext;
			}
		}

		// Block SVG, same as the base64 path — an SVG can carry <script> (stored
		// XSS) and WordPress ships no sanitizer. Check both the detected content
		// type and the resolved filename so a mislabeled URL can't slip through.
		$detected_mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp ) : '';
		if ( 'image/svg+xml' === $detected_mime || '.svg' === substr( strtolower( $file_array['name'] ), -4 ) ) {
			wp_delete_file( $tmp );
			return new WP_Error(
				'svg_not_allowed',
				__( 'SVG uploads are blocked (SVGs can contain <script> tags — stored XSS risk). Use PNG or WebP instead. To allow SVGs, install an SVG sanitizer plugin (e.g. "Safe SVG") and upload via the WordPress media library directly.', 'mcpwp' ),
				array(
					'status'       => 400,
					'hint'         => 'Convert the SVG to PNG/WebP for API upload. Inline SVG code can be embedded directly in HTML widgets without uploading.',
					'alternatives' => array( 'png', 'webp', 'jpg' ),
				)
			);
		}

		// Upload
		$attachment_id = media_handle_sideload( $file_array, 0, isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '' );

		// Clean up temp file
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Set alt text
		if ( ! empty( $args['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
		}

		// Set caption
		if ( ! empty( $args['caption'] ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => sanitize_textarea_field( $args['caption'] ),
				)
			);
		}

		return $this->format_attachment( $attachment_id );
	}

	/**
	 * Upload media from Base64 encoded string.
	 *
	 * Bypasses multipart/form-data which can trigger ModSecurity on shared hosts.
	 *
	 * @param string $base64_data Base64 encoded file content.
	 * @param string $filename    Desired filename with extension.
	 * @param array  $args        Additional arguments (title, alt, caption).
	 * @return array|WP_Error Attachment data or error.
	 */
	public function upload_from_base64( $base64_data, $filename, $args = array() ) {
		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'media_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// Strip optional data URI prefix (e.g. "data:image/png;base64,").
		if ( preg_match( '/^data:[^;]+;base64,/', $base64_data ) ) {
			$base64_data = preg_replace( '/^data:[^;]+;base64,/', '', $base64_data );
		}

		// Decode.
		$decoded = base64_decode( $base64_data, true );
		if ( false === $decoded ) {
			return new WP_Error(
				'invalid_base64',
				__( 'Invalid Base64 data.', 'mcpwp' ),
				array(
					'status' => 400,
					'hint'   => 'The Base64 data could not be decoded. Ensure the data is properly encoded. If using a data URI prefix (data:image/png;base64,...), it will be stripped automatically.',
				)
			);
		}

		// Limit file size to 10MB.
		$max_size = 10 * 1024 * 1024;
		if ( strlen( $decoded ) > $max_size ) {
			return new WP_Error(
				'file_too_large',
				__( 'File exceeds maximum size of 10MB.', 'mcpwp' ),
				array(
					'status' => 400,
					'hint'   => 'The decoded file exceeds the 10MB limit. Use a smaller file, or upload via URL with wp_upload_media_from_url if the server supports larger files.',
				)
			);
		}

		// Sanitize filename.
		$filename = sanitize_file_name( $filename );
		if ( empty( $filename ) ) {
			return new WP_Error(
				'invalid_filename',
				__( 'A valid filename is required.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		// Write to temp file.
		$tmp_file = wp_tempnam( $filename );
		if ( ! $tmp_file ) {
			return new WP_Error(
				'tmp_error',
				__( 'Could not create temporary file.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_file, $decoded );

		// Detect mime type from filename and content — never trust caller alone.
		$mime = wp_check_filetype( $filename );
		if ( empty( $mime['type'] ) ) {
			$finfo         = new finfo( FILEINFO_MIME_TYPE );
			$detected_mime = $finfo->buffer( $decoded );
			$mime['type']  = $detected_mime ?: 'application/octet-stream';
		}

		// If caller supplied mime_type, it must match the detected type exactly.
		// A mismatch (e.g. uploading HTML claiming to be image/png) is rejected.
		if ( ! empty( $args['mime_type'] ) ) {
			$supplied = sanitize_mime_type( $args['mime_type'] );
			if ( $supplied !== $mime['type'] ) {
				wp_delete_file( $tmp_file );
				return new WP_Error(
					'mime_mismatch',
					sprintf(
						/* translators: 1: supplied mime type, 2: detected mime type */
						__( 'Supplied mime_type "%1$s" does not match detected type "%2$s". Upload rejected.', 'mcpwp' ),
						$supplied,
						$mime['type']
					),
					array( 'status' => 400 )
				);
			}
		}

		// Block SVG uploads — SVGs can contain <script> tags (stored XSS).
		// WordPress does not ship an SVG sanitizer; block at the API layer.
		if ( 'image/svg+xml' === $mime['type'] || '.svg' === substr( strtolower( $filename ), -4 ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error(
				'svg_not_allowed',
				__( 'SVG uploads are blocked (SVGs can contain <script> tags — stored XSS risk). Use PNG or WebP instead. To allow SVGs, install an SVG sanitizer plugin (e.g. "Safe SVG") and upload via the WordPress media library directly.', 'mcpwp' ),
				array(
					'status'      => 400,
					'hint'        => 'Convert the SVG to PNG/WebP for API upload. Inline SVG code can be embedded directly in HTML widgets without uploading.',
					'alternatives' => array( 'png', 'webp', 'jpg' ),
				)
			);
		}

		// Validate allowed file types.
		$check = wp_check_filetype_and_ext( $tmp_file, $filename );
		if ( ! empty( $check['proper_filename'] ) ) {
			$filename = $check['proper_filename'];
		}

		// Get upload directory.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error(
				'upload_dir_error',
				$upload_dir['error'],
				array( 'status' => 500 )
			);
		}

		// Move to uploads directory.
		$unique_filename = wp_unique_filename( $upload_dir['path'], $filename );
		$new_file = $upload_dir['path'] . '/' . $unique_filename;

		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		if ( ! $wp_filesystem->move( $tmp_file, $new_file, true ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error(
				'move_error',
				__( 'Could not move file to uploads directory.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// Set file permissions to match parent directory.
		$stat = stat( dirname( $new_file ) );
		$perms = $stat['mode'] & 0000666;
		$wp_filesystem->chmod( $new_file, $perms );

		// Create attachment.
		$attachment = array(
			'post_mime_type' => $mime['type'],
			'post_title'     => isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $new_file );

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $new_file );
			return $attachment_id;
		}

		// Generate metadata.
		$metadata = wp_generate_attachment_metadata( $attachment_id, $new_file );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Set alt text.
		if ( ! empty( $args['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
		}

		// Set caption.
		if ( ! empty( $args['caption'] ) ) {
			wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => sanitize_textarea_field( $args['caption'] ),
				)
			);
		}

		return $this->format_attachment( $attachment_id );
	}

	/**
	 * List media.
	 *
	 * @param array $args Query arguments.
	 * @return array Media list.
	 */
	public function list_media( $args = array() ) {
		$defaults = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 20,
			'paged'          => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		// Filter by mime type
		if ( ! empty( $args['mime_type'] ) ) {
			$args['post_mime_type'] = sanitize_mime_type( $args['mime_type'] );
		}

		// Search by title/alt
		if ( ! empty( $args['search'] ) ) {
			$args['s'] = sanitize_text_field( $args['search'] );
		}

		$query = new WP_Query( $args );
		$media = array();

		foreach ( $query->posts as $attachment ) {
			$media[] = $this->format_attachment( $attachment->ID );
		}

		return array(
			'media'    => $media,
			'total'    => $query->found_posts,
			'pages'    => $query->max_num_pages,
			'page'     => absint( $args['paged'] ),
			'per_page' => absint( $args['posts_per_page'] ),
		);
	}

	/**
	 * Format attachment for API response.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array Formatted attachment.
	 */
	protected function format_attachment( $attachment_id ) {
		$attachment = get_post( $attachment_id );
		$metadata = wp_get_attachment_metadata( $attachment_id );

		$data = array(
			'id'          => $attachment_id,
			'title'       => $attachment->post_title,
			'caption'     => $attachment->post_excerpt,
			'alt'         => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'mime_type'   => $attachment->post_mime_type,
			'url'         => wp_get_attachment_url( $attachment_id ),
			'date'        => $attachment->post_date,
		);

		// Add image sizes if available
		if ( $metadata && ! empty( $metadata['width'] ) ) {
			$data['width'] = $metadata['width'];
			$data['height'] = $metadata['height'];

			$data['sizes'] = array();
			if ( ! empty( $metadata['sizes'] ) ) {
				foreach ( $metadata['sizes'] as $size => $size_data ) {
					$src = wp_get_attachment_image_src( $attachment_id, $size );
					if ( $src ) {
						$data['sizes'][ $size ] = array(
							'url'    => $src[0],
							'width'  => $src[1],
							'height' => $src[2],
						);
					}
				}
			}
		}

		return $data;
	}

	/**
	 * Delete a media attachment.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $force         True to permanently delete (skip trash).
	 * @return array|WP_Error Deleted attachment info or error.
	 */
	public function delete_media( $attachment_id, $force = false ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'not_found',
				__( 'Attachment not found.', 'mcpwp' ),
				array(
					'status' => 404,
					'hint'   => sprintf(
						'Media attachment ID %d not found. Use wp_list_media to see available media items and their IDs.',
						absint( $attachment_id )
					),
				)
			);
		}

		// Capture info before deletion.
		$info = $this->format_attachment( $attachment_id );

		$result = wp_delete_attachment( $attachment_id, $force );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete attachment.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		$info['deleted'] = true;
		$info['force']   = $force;

		return $info;
	}

	/**
	 * Convert mime type to file extension.
	 *
	 * @param string $mime Mime type.
	 * @return string|false Extension or false.
	 */
	/**
	 * Update attachment metadata (alt text, title, caption, description).
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $args          Fields to update: alt, title, caption, description.
	 * @return array|WP_Error Updated attachment data or error.
	 */
	public function update_media( $attachment_id, $args = array() ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'not_found', 'Attachment not found.', array( 'status' => 404 ) );
		}

		$post_data = array( 'ID' => $attachment_id );

		if ( isset( $args['title'] ) ) {
			$post_data['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['caption'] ) ) {
			$post_data['post_excerpt'] = sanitize_textarea_field( $args['caption'] );
		}
		if ( isset( $args['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $args['description'] );
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $args['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
		}

		return $this->format_attachment( $attachment_id );
	}

	protected function mime_to_extension( $mime ) {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/gif'  => 'gif',
			'image/webp' => 'webp',
			'image/svg+xml' => 'svg',
			'application/pdf' => 'pdf',
		);

		return isset( $map[ $mime ] ) ? $map[ $mime ] : false;
	}
}
