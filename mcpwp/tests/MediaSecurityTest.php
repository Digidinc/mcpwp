<?php

use PHPUnit\Framework\TestCase;

final class MediaSecurityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_attachment_metadata'] = array();
		$GLOBALS['wp_filesystem'] = new Mcpwp_Test_Filesystem();
	}

	public function test_base64_upload_rejects_php_payload_before_move(): void {
		$media = new Mcpwp_Media();
		$result = $media->upload_from_base64( base64_encode( '<?php echo "owned";' ), 'proof.php' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_file_type', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->moves );
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

	public function test_removed_media_mutations_are_physically_absent(): void {
		$this->assertFalse( method_exists( Mcpwp_Media::class, 'update_media' ) );
		$this->assertFalse( method_exists( Mcpwp_Media::class, 'delete_media' ) );
	}
}
