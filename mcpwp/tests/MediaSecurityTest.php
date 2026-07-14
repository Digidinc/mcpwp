<?php

use PHPUnit\Framework\TestCase;

final class MediaSecurityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_attachment_metadata'] = array();
		$GLOBALS['wp_filesystem'] = new Mcpwp_Test_Filesystem();
		$GLOBALS['_mcpwp_test_insert_attachment_result'] = null;
		$GLOBALS['_mcpwp_test_update_attachment_metadata_result'] = null;
		$GLOBALS['_mcpwp_test_generate_attachment_metadata_result'] = null;
		$GLOBALS['_mcpwp_test_update_post_meta_result'] = null;
		$GLOBALS['_mcpwp_test_update_post_result'] = null;
		$GLOBALS['_mcpwp_test_extra_filetypes'] = array();
		unset( $GLOBALS['_mcpwp_test_handle_upload_result'], $GLOBALS['_mcpwp_test_handle_sideload_result'] );
		$GLOBALS['_mcpwp_test_wp_filesystem_calls'] = 0;
	}

	public function test_base64_upload_rejects_php_payload_before_move(): void {
		$media = new Mcpwp_Media();
		$result = $media->upload_from_base64( base64_encode( '<?php echo "owned";' ), 'proof.php' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_file_type', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moves );
	}

	/** @dataProvider codeLikeUploadExtensions */
	public function test_base64_upload_rejects_code_extensions_even_when_wordpress_mimes_are_expanded( $extension, $mime ): void {
		$GLOBALS['_mcpwp_test_extra_filetypes'][ $extension ] = $mime;
		$result = ( new Mcpwp_Media() )->upload_from_base64(
			base64_encode( 'reviewer-controlled code-like content' ),
			'payload.' . $extension,
			array( 'mime_type' => $mime )
		);

		$this->assertInstanceOf( WP_Error::class, $result, $extension );
		$this->assertSame( 'invalid_file_type', $result->get_error_code(), $extension );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moves, $extension );
	}

	public function codeLikeUploadExtensions(): array {
		return array(
			'CSS' => array( 'css', 'text/plain' ),
			'ES module' => array( 'mjs', 'application/javascript' ),
			'CommonJS' => array( 'cjs', 'application/javascript' ),
			'JSX' => array( 'jsx', 'text/plain' ),
			'TypeScript' => array( 'ts', 'text/plain' ),
			'TSX' => array( 'tsx', 'text/plain' ),
			'PHP include' => array( 'inc', 'text/plain' ),
			'PHP source' => array( 'phps', 'text/plain' ),
			'PHP 9' => array( 'php9', 'text/plain' ),
			'PHP 74' => array( 'php74', 'text/plain' ),
			'PHP test' => array( 'phpt', 'text/plain' ),
		);
	}

	public function test_base64_upload_rejects_extension_and_mime_mismatch_before_move(): void {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
		$result = ( new Mcpwp_Media() )->upload_from_base64( base64_encode( $png ), 'photo.png', array( 'mime_type' => 'image/jpeg' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mime_mismatch', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moves );
	}

	public function test_base64_upload_accepts_wordpress_allowed_png(): void {
		$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=' );
		$result = ( new Mcpwp_Media() )->upload_from_base64( base64_encode( $png ), 'photo.png', array( 'mime_type' => 'image/png' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertCount( 1, $GLOBALS['wp_filesystem']->moves );
	}

	public function test_base64_upload_does_not_initialize_credential_backed_filesystem(): void {
		$GLOBALS['wp_filesystem'] = null;
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
		$result = ( new Mcpwp_Media() )->upload_from_base64( $png, 'local-sideload.png', array( 'mime_type' => 'image/png' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 0, $GLOBALS['_mcpwp_test_wp_filesystem_calls'] );
		wp_delete_attachment( $result['id'], true );
	}

	public function test_base64_upload_accepts_empty_optional_image_metadata(): void {
		$GLOBALS['_mcpwp_test_generate_attachment_metadata_result'] = array();
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
		$result = ( new Mcpwp_Media() )->upload_from_base64( $png, 'no-metadata.png', array( 'mime_type' => 'image/png' ) );

		$this->assertIsArray( $result );
		wp_delete_attachment( $result['id'], true );
	}

	public function test_removed_media_mutations_are_physically_absent(): void {
		$this->assertFalse( method_exists( Mcpwp_Media::class, 'update_media' ) );
		$this->assertFalse( method_exists( Mcpwp_Media::class, 'delete_media' ) );
	}

	/** @dataProvider invalidBase64Inputs */
	public function test_base64_upload_rejects_empty_wrapped_double_extension_and_data_uri_mismatch( $data, $filename, $code ): void {
		$result = ( new Mcpwp_Media() )->upload_from_base64( $data, $filename );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moves );
	}

	public function invalidBase64Inputs(): array {
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
		return array(
			'empty' => array( '', 'empty.png', 'empty_file' ),
			'wrapped' => array( "iVBORw0KGgoAAAAN\nSUhEUgAAAAEAAAAB", 'wrapped.png', 'invalid_base64' ),
			'double executable extension' => array( $png, 'photo.php.png', 'invalid_filename' ),
			'double PHP-family extension' => array( $png, 'photo.php74.png', 'invalid_filename' ),
			'double PHP test extension' => array( $png, 'photo.phpt.png', 'invalid_filename' ),
			'data uri mismatch' => array( 'data:image/jpeg;base64,' . $png, 'photo.png', 'mime_mismatch' ),
		);
	}

	public function test_attachment_insert_zero_cleans_moved_file(): void {
		$GLOBALS['_mcpwp_test_insert_attachment_result'] = 0;
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
		$result = ( new Mcpwp_Media() )->upload_from_base64( $png, 'insert-zero.png', array( 'mime_type' => 'image/png' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'attachment_insert_failed', $result->get_error_code() );
		$this->assertFileDoesNotExist( $GLOBALS['wp_filesystem']->moves[0][1] );
	}

	public function test_media_controllers_reject_extra_parts_remote_keys_and_oversized_body(): void {
		$controller = new Mcpwp_REST_Media();
		$multipart = new WP_REST_Request( 'POST', '/mcpwp/v1/media' );
		$multipart->set_file_params( array( 'file' => array( 'name' => 'ok.png' ), 'extra' => array( 'name' => 'bad.png' ) ) );
		$this->assertSame( 'unknown_parameter', $controller->upload_media( $multipart )->get_data()['code'] );

		$remote = new WP_REST_Request( 'POST', '/mcpwp/v1/media/from-base64', array(), array(), array( 'data' => 'AA==', 'filename' => 'x.png', 'url' => 'https://example.com/x.png' ) );
		$this->assertSame( 'unknown_parameter', $controller->upload_from_base64( $remote )->get_data()['code'] );

		$oversize = new WP_REST_Request( 'POST', '/mcpwp/v1/media/from-base64', array(), array(), array( 'data' => 'AA==', 'filename' => 'x.png' ), str_repeat( 'x', 15 * 1024 * 1024 + 1 ) );
		$this->assertSame( 413, $controller->upload_from_base64( $oversize )->get_status() );
	}

	public function test_base64_metadata_failure_removes_attachment_and_file(): void {
		$GLOBALS['_mcpwp_test_generate_attachment_metadata_result'] = new WP_Error( 'image_editor_failed', 'Could not generate metadata.' );
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
		$result = ( new Mcpwp_Media() )->upload_from_base64( $png, 'metadata-fail.png', array( 'mime_type' => 'image/png' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'attachment_metadata_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
		$this->assertFileDoesNotExist( $GLOBALS['wp_filesystem']->moves[0][1] );
	}

	/** @dataProvider attachmentWriteFailures */
	public function test_real_attachment_write_failure_rolls_back_attachment_and_file( $failure, $args ): void {
		$GLOBALS[ $failure ] = false;
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
		$result = ( new Mcpwp_Media() )->upload_from_base64( $png, 'write-fail.png', array_merge( array( 'mime_type' => 'image/png' ), $args ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'attachment_write_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
		$this->assertFileDoesNotExist( $GLOBALS['wp_filesystem']->moves[0][1] );
	}

	public function attachmentWriteFailures(): array {
		return array(
			'metadata' => array( '_mcpwp_test_update_attachment_metadata_result', array() ),
			'alt' => array( '_mcpwp_test_update_post_meta_result', array( 'alt' => 'Expected alt' ) ),
			'caption' => array( '_mcpwp_test_update_post_result', array( 'caption' => 'Expected caption' ) ),
		);
	}

	public function test_metadata_false_is_accepted_only_when_stored_value_is_already_identical(): void {
		$GLOBALS['_mcpwp_test_update_attachment_metadata_result'] = false;
		$GLOBALS['_mcpwp_test_attachment_metadata'][1] = array( 'file' => 'metadata-noop.png' );
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
		$result = ( new Mcpwp_Media() )->upload_from_base64( $png, 'metadata-noop.png', array( 'mime_type' => 'image/png' ) );
		$this->assertIsArray( $result );
	}

	public function test_sideload_library_error_is_generic_bounded_and_path_free(): void {
		$GLOBALS['_mcpwp_test_handle_sideload_result'] = array( 'error' => 'Could not move /srv/wordpress/private/uploads/secret.png because /tmp/phpXYZ failed.' );
		$png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
		$result = ( new Mcpwp_Media() )->upload_from_base64( $png, 'generic-error.png', array( 'mime_type' => 'image/png' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'media_upload_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( '/srv/', $result->get_error_message() );
		$this->assertStringNotContainsString( '/tmp/', $result->get_error_message() );
		$this->assertLessThanOrEqual( 120, strlen( $result->get_error_message() ) );
	}

	public function test_multipart_and_base64_call_the_same_local_file_policy_and_finalizer(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php' );
		$this->assertGreaterThanOrEqual( 2, substr_count( $source, '$this->validate_local_media_file(' ) );
		$this->assertGreaterThanOrEqual( 2, substr_count( $source, '$this->finalize_attachment(' ) );
	}

	public function test_multipart_rejects_upload_error_size_and_non_uploaded_path(): void {
		$media = new Mcpwp_Media();
		$error = $media->upload_file( array( 'name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 1 ) );
		$this->assertSame( 'upload_error', $error->get_error_code() );
		$large = $media->upload_file( array( 'name' => 'x.png', 'type' => 'image/png', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10 * 1024 * 1024 + 1 ) );
		$this->assertSame( 'file_too_large', $large->get_error_code() );
		$path = tempnam( sys_get_temp_dir(), 'mcpwp-not-upload-' );
		file_put_contents( $path, 'bytes' );
		$not_uploaded = $media->upload_file( array( 'name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 5 ) );
		$this->assertSame( 'invalid_upload', $not_uploaded->get_error_code() );
		unlink( $path );
	}

	public function test_multipart_route_does_not_declare_file_as_a_rest_scalar_argument(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		( new Mcpwp_REST_Media() )->register_routes();
		$upload = null;
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registration ) {
			if ( '/media' !== $registration['route'] ) { continue; }
			foreach ( $registration['args'] as $configuration ) {
				if ( WP_REST_Server::CREATABLE === $configuration['methods'] ) { $upload = $configuration; }
			}
		}
		$this->assertIsArray( $upload );
		$this->assertArrayNotHasKey( 'file', $upload['args'] );
	}
}
