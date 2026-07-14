<?php
/**
 * Media handler
 *
 * @package mumcp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle media operations.
 */
class Mcpwp_Media {

	/** File extensions that this content-only directory edition never stores. */
	private const DISALLOWED_CODE_EXTENSIONS = array(
		'php', 'php2', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'pht', 'phtm', 'phtml', 'phps', 'phar', 'inc',
		'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx', 'coffee', 'css', 'scss', 'sass', 'less',
		'html', 'htm', 'xhtml', 'shtml', 'shtm', 'svg', 'swf',
		'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'cfm',
		'exe', 'dll', 'com', 'bat', 'cmd', 'msi', 'jar', 'war',
	);

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

		if ( ! is_array( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'upload_error', __( 'The multipart upload did not complete successfully.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}
		if ( empty( $file['tmp_name'] ) ) {
			return new WP_Error(
				'no_file',
				__( 'No file uploaded.', 'mumega-mcp' ),
				array( 'status' => 400 )
			);
		}
		$declared_size = isset( $file['size'] ) ? (int) $file['size'] : -1;
		if ( $declared_size < 1 ) {
			return new WP_Error( 'empty_file', __( 'The uploaded file is empty.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
		if ( $declared_size > 10 * 1024 * 1024 ) {
			return new WP_Error( 'file_too_large', __( 'File exceeds maximum size of 10MB.', 'mumega-mcp' ), array( 'status' => 413 ) ); }
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'The file is not a genuine HTTP upload.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
		$actual_size = filesize( $file['tmp_name'] );
		if ( false === $actual_size || $actual_size < 1 || $actual_size > 10 * 1024 * 1024 || $actual_size !== $declared_size ) {
			return new WP_Error( 'invalid_upload_size', __( 'The uploaded file size is invalid.', 'mumega-mcp' ), array( 'status' => $actual_size > 10 * 1024 * 1024 ? 413 : 400 ) ); }
		$policy = $this->validate_local_media_file( $file['tmp_name'], $file['name'] ?? '', $file['type'] ?? '' );
		if ( is_wp_error( $policy ) ) {
			return $policy; }
		$file['name'] = $policy['filename'];
		$file['type'] = $policy['type'];

		// Upload the file.
		$upload = wp_handle_upload(
			$file,
			array( 'test_form' => false )
		);

		if ( isset( $upload['error'] ) ) {
			return new WP_Error(
				'media_upload_failed',
				__( 'WordPress could not store the uploaded media file.', 'mumega-mcp' ),
				array( 'status' => 400 )
			);
		}

		// Create the attachment.
		$attachment = array(
			'post_mime_type' => $upload['type'],
			'post_title'     => isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

		if ( is_wp_error( $attachment_id ) || ! is_int( $attachment_id ) || $attachment_id < 1 ) {
			wp_delete_file( $upload['file'] );
			return is_wp_error( $attachment_id ) ? $attachment_id : new WP_Error( 'attachment_insert_failed', __( 'WordPress could not create the media attachment.', 'mumega-mcp' ), array( 'status' => 500 ) );
		}

		$finalized = $this->finalize_attachment( $attachment_id, $upload['file'], $args );
		if ( is_wp_error( $finalized ) ) {
			return $finalized; }

		return $this->format_attachment( $attachment_id );
	}

	/**
	 * Validate a local upload path, filename, extension, and detected MIME type.
	 *
	 * @param string $path              Local temporary file path.
	 * @param string $original_filename Client-supplied filename.
	 * @param string $supplied_mime     Optional client-supplied MIME type.
	 * @return array<string,string>|WP_Error Normalized file data or validation error.
	 */
	private function validate_local_media_file( $path, $original_filename, $supplied_mime = '' ) {
		$original_filename = (string) $original_filename;
		$filename          = sanitize_file_name( $original_filename );
		$extension         = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$executable        = self::DISALLOWED_CODE_EXTENSIONS;
		$segments          = array_map( 'strtolower', explode( '.', $filename ) );
		$filetype          = wp_check_filetype( $filename );
		if ( '' === $filename || $filename !== $original_filename || basename( $original_filename ) !== $original_filename ) {
			return new WP_Error( 'invalid_filename', __( 'A valid filename is required.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
		if ( in_array( $extension, $executable, true ) || array_intersect( array_slice( $segments, 0, -1 ), $executable ) || empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			return new WP_Error( 'invalid_file_type', __( 'The file type is not allowed for local media uploads.', 'mumega-mcp' ), array( 'status' => 415 ) ); }
		$check = wp_check_filetype_and_ext( $path, $filename );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || $check['ext'] !== $filetype['ext'] || $check['type'] !== $filetype['type'] || 'image/svg+xml' === $check['type'] ) {
			return new WP_Error( 'invalid_file_type', __( 'The file extension or contents are not allowed by WordPress.', 'mumega-mcp' ), array( 'status' => 415 ) ); }
		if ( '' !== (string) $supplied_mime && sanitize_mime_type( $supplied_mime ) !== $check['type'] ) {
			return new WP_Error( 'mime_mismatch', __( 'The supplied MIME type does not match the detected file type.', 'mumega-mcp' ), array( 'status' => 415 ) ); }
		return array(
			'filename' => ! empty( $check['proper_filename'] ) ? $check['proper_filename'] : $filename,
			'type'     => $check['type'],
		);
	}

	/**
	 * Generate metadata and persist optional attachment text fields.
	 *
	 * @param int                 $attachment_id Attachment post ID.
	 * @param string              $file          Stored file path.
	 * @param array<string,mixed> $args          Optional attachment text fields.
	 * @return true|WP_Error True on success or an attachment-cleanup error.
	 */
	private function finalize_attachment( $attachment_id, $file, $args ) {
		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( is_wp_error( $metadata ) ) {
			return $this->discard_attachment( $attachment_id, $file, 'attachment_metadata_failed', __( 'WordPress could not create attachment metadata.', 'mumega-mcp' ) ); }
		if ( ! empty( $metadata ) ) {
			$updated = wp_update_attachment_metadata( $attachment_id, $metadata );
			if ( false === $updated && wp_get_attachment_metadata( $attachment_id ) !== $metadata ) {
				return $this->discard_attachment( $attachment_id, $file ); }
		}
		if ( ! empty( $args['alt'] ) ) {
			$alt     = sanitize_text_field( $args['alt'] );
			$updated = update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			if ( false === $updated && get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) !== $alt ) {
				return $this->discard_attachment( $attachment_id, $file ); }
		}
		if ( ! empty( $args['caption'] ) ) {
			$caption = sanitize_textarea_field( $args['caption'] );
			$updated = wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_excerpt' => $caption,
				),
				true
			);
			$post    = get_post( $attachment_id );
			if ( ( is_wp_error( $updated ) || ! $updated ) && ( ! $post || $caption !== $post->post_excerpt ) ) {
				return $this->discard_attachment( $attachment_id, $file ); }
		}
		return true;
	}

	/**
	 * Remove a failed attachment and its stored file.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $file          Stored file path.
	 * @param string $code          Error code.
	 * @param string $message       Optional public error message.
	 * @return WP_Error Attachment-cleanup error response.
	 */
	private function discard_attachment( $attachment_id, $file, $code = 'attachment_write_failed', $message = '' ) {
		wp_delete_attachment( $attachment_id, true );
		wp_delete_file( $file );
		return new WP_Error( $code, '' !== $message ? $message : __( 'WordPress could not save attachment details.', 'mumega-mcp' ), array( 'status' => 500 ) );
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

		$data_uri_mime = '';
		if ( 0 === strpos( $base64_data, 'data:' ) ) {
			if ( ! preg_match( '/^data:([a-z0-9.+-]+\/[a-z0-9.+-]+);base64,([A-Za-z0-9+\/=]+)$/D', $base64_data, $matches ) ) {
				return new WP_Error( 'invalid_base64', __( 'Invalid Base64 data URI.', 'mumega-mcp' ), array( 'status' => 400 ) ); }
			$data_uri_mime = $matches[1];
			$base64_data   = $matches[2];
		}
		if ( '' === $base64_data || ! preg_match( '/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $base64_data ) ) {
			return new WP_Error( '' === $base64_data ? 'empty_file' : 'invalid_base64', __( 'Base64 data must be non-empty and unwrapped.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}

		// Decode.
		$decoded = base64_decode( $base64_data, true );
		if ( false === $decoded ) {
			return new WP_Error(
				'invalid_base64',
				__( 'Invalid Base64 data.', 'mumega-mcp' ),
				array(
					'status' => 400,
					'hint'   => 'The Base64 data could not be decoded. Ensure the data is properly encoded. If using a data URI prefix (data:image/png;base64,...), it will be stripped automatically.',
				)
			);
		}
		if ( '' === $decoded ) {
			return new WP_Error( 'empty_file', __( 'The decoded file is empty.', 'mumega-mcp' ), array( 'status' => 400 ) ); }

		// Limit file size to 10MB.
		$max_size = 10 * 1024 * 1024;
		if ( strlen( $decoded ) > $max_size ) {
			return new WP_Error(
				'file_too_large',
				__( 'File exceeds maximum size of 10MB.', 'mumega-mcp' ),
				array(
					'status' => 413,
					'hint'   => 'The decoded file exceeds the 10MB limit. Use a smaller local file.',
				)
			);
		}

		// Reject path components, control characters, and executable/disallowed extensions.
		$original_filename     = (string) $filename;
		$filename              = sanitize_file_name( $original_filename );
		$extension             = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$executable_extensions = self::DISALLOWED_CODE_EXTENSIONS;
		$filename_segments     = array_map( 'strtolower', explode( '.', $filename ) );
		$filetype              = wp_check_filetype( $filename );
		if ( empty( $filename ) || $original_filename !== $filename || basename( $original_filename ) !== $original_filename ) {
			return new WP_Error(
				'invalid_filename',
				__( 'A valid filename is required.', 'mumega-mcp' ),
				array( 'status' => 400 )
			);
		}
		if ( in_array( $extension, $executable_extensions, true ) || empty( $filetype['ext'] ) || empty( $filetype['type'] ) ) {
			return new WP_Error( 'invalid_file_type', __( 'The file type is not allowed for local media uploads.', 'mumega-mcp' ), array( 'status' => 415 ) );
		}
		if ( array_intersect( array_slice( $filename_segments, 0, -1 ), $executable_extensions ) ) {
			return new WP_Error( 'invalid_filename', __( 'The filename contains a disallowed extension.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}

		// Write to temp file.
		$tmp_file = wp_tempnam( $filename );
		if ( ! $tmp_file ) {
			return new WP_Error(
				'tmp_error',
				__( 'Could not create temporary file.', 'mumega-mcp' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $tmp_file, $decoded );
		$policy = $this->validate_local_media_file( $tmp_file, $filename, $args['mime_type'] ?? $data_uri_mime );
		if ( is_wp_error( $policy ) ) {
			wp_delete_file( $tmp_file );
			return $policy; }
		$filename = $policy['filename'];

		// WordPress validates both the extension and file contents.
		$check = wp_check_filetype_and_ext( $tmp_file, $filename );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || $check['ext'] !== $filetype['ext'] || $check['type'] !== $filetype['type'] ) {
			wp_delete_file( $tmp_file );
			return new WP_Error( 'invalid_file_type', __( 'The file extension or contents are not allowed by WordPress.', 'mumega-mcp' ), array( 'status' => 400 ) );
		}

		// If caller supplied mime_type, it must match the detected type exactly.
		// A mismatch (e.g. uploading HTML claiming to be image/png) is rejected.
		if ( ! empty( $args['mime_type'] ) ) {
			$supplied = sanitize_mime_type( $args['mime_type'] );
			if ( $supplied !== $check['type'] ) {
				wp_delete_file( $tmp_file );
				return new WP_Error(
					'mime_mismatch',
					sprintf(
						/* translators: 1: supplied mime type, 2: detected mime type */
						__( 'Supplied mime_type "%1$s" does not match detected type "%2$s". Upload rejected.', 'mumega-mcp' ),
						$supplied,
						$check['type']
					),
					array( 'status' => 400 )
				);
			}
		}
		if ( '' !== $data_uri_mime && $data_uri_mime !== $check['type'] ) {
			wp_delete_file( $tmp_file );
			return new WP_Error( 'mime_mismatch', __( 'The data URI MIME type does not match the detected file type.', 'mumega-mcp' ), array( 'status' => 415 ) ); }

		// Block SVG uploads — SVGs can contain <script> tags (stored XSS).
		// WordPress does not ship an SVG sanitizer; block at the API layer.
		if ( 'image/svg+xml' === $check['type'] || '.svg' === substr( strtolower( $filename ), -4 ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error(
				'svg_not_allowed',
				__( 'SVG uploads are blocked (SVGs can contain <script> tags — stored XSS risk). Use PNG or WebP instead. To allow SVGs, install an SVG sanitizer plugin (e.g. "Safe SVG") and upload via the WordPress media library directly.', 'mumega-mcp' ),
				array(
					'status'       => 400,
					'hint'         => 'Convert the SVG to PNG or WebP before uploading.',
					'alternatives' => array( 'png', 'webp', 'jpg' ),
				)
			);
		}

		if ( ! empty( $check['proper_filename'] ) ) {
			$filename = $check['proper_filename'];
		}

		// Use the core local sideload path. WP_Filesystem may require FTP/SSH
		// credentials and is not the file-move API used for media uploads.
		$sideload_file = array(
			'name'     => $filename,
			'type'     => $check['type'],
			'tmp_name' => $tmp_file,
			'error'    => UPLOAD_ERR_OK,
			'size'     => strlen( $decoded ),
		);
		$sideload      = wp_handle_sideload( $sideload_file, array( 'test_form' => false ) );
		if ( ! empty( $sideload['error'] ) || empty( $sideload['file'] ) ) {
			wp_delete_file( $tmp_file );
			return new WP_Error(
				'media_upload_failed',
				__( 'WordPress could not store the uploaded media file.', 'mumega-mcp' ),
				array( 'status' => 500 )
			);
		}
		$new_file = $sideload['file'];

		// Create attachment.
		$attachment = array(
			'post_mime_type' => $check['type'],
			'post_title'     => isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : sanitize_file_name( pathinfo( $filename, PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $new_file );

		if ( is_wp_error( $attachment_id ) || ! is_int( $attachment_id ) || $attachment_id < 1 ) {
			wp_delete_file( $new_file );
			return is_wp_error( $attachment_id ) ? $attachment_id : new WP_Error( 'attachment_insert_failed', __( 'WordPress could not create the media attachment.', 'mumega-mcp' ), array( 'status' => 500 ) );
		}

		$finalized = $this->finalize_attachment( $attachment_id, $new_file, $args );
		if ( is_wp_error( $finalized ) ) {
			return $finalized; }

		return $this->format_attachment( $attachment_id );
	}

	/**
	 * List media.
	 *
	 * @param array $args Query arguments.
	 * @return array Media list.
	 */
	public function list_media( $args = array() ) {
		$per_page   = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 20;
		$page       = isset( $args['paged'] ) ? (int) $args['paged'] : 1;
		$orderby    = isset( $args['orderby'] ) ? $args['orderby'] : 'date';
		$order      = isset( $args['order'] ) && 'asc' === strtolower( $args['order'] ) ? 1 : -1;
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 100,
			'orderby'        => 'id' === $orderby ? 'ID' : $orderby,
			'order'          => 1 === $order ? 'ASC' : 'DESC',
		);

		// Filter by MIME type.
		if ( ! empty( $args['mime_type'] ) ) {
			$query_args['post_mime_type'] = sanitize_mime_type( $args['mime_type'] );
		}

		// Search by title or alternative text.
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}

		$visible    = array();
		$total      = 0;
		$offset     = ( $page - 1 ) * $per_page;
		$chunk_page = 1;
		do {
			$query_args['paged'] = $chunk_page;
			$query               = new WP_Query( $query_args );
			foreach ( $query->posts as $attachment ) {
				if ( ! current_user_can( 'read_post', $attachment->ID ) ) {
					continue; }
				if ( $total >= $offset && count( $visible ) < $per_page ) {
					$visible[] = $attachment; }
				++$total;
			}
			++$chunk_page;
		} while ( ! empty( $query->posts ) && $chunk_page <= (int) $query->max_num_pages );
		$media = array();

		foreach ( $visible as $attachment ) {
			$media[] = $this->format_attachment( $attachment->ID );
		}

		return array(
			'media'    => $media,
			'total'    => $total,
			'pages'    => (int) ceil( $total / $per_page ),
			'page'     => $page,
			'per_page' => $per_page,
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
		$metadata   = wp_get_attachment_metadata( $attachment_id );

		$data = array(
			'id'        => $attachment_id,
			'title'     => $attachment->post_title,
			'caption'   => $attachment->post_excerpt,
			'alt'       => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'mime_type' => $attachment->post_mime_type,
			'url'       => wp_get_attachment_url( $attachment_id ),
			'date'      => $attachment->post_date,
		);

		// Add image sizes when available.
		if ( $metadata && ! empty( $metadata['width'] ) ) {
			$data['width']  = $metadata['width'];
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
}
