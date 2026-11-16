<?php
/**
 * SVG media-upload refusal stays blocked, with a hint that does not loop
 * agents back into inline SVG (which content saves strip / report).
 *
 * @package MCPWP
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_handle_upload' ) ) {
	function wp_handle_upload( $file, $overrides = array(), $time = null ) { // phpcs:ignore
		return array( 'error' => 'not used in this test' );
	}
}

if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
	function wp_generate_attachment_metadata( $attachment_id, $file ) { // phpcs:ignore
		return array();
	}
}

if ( ! function_exists( 'media_handle_upload' ) ) {
	function media_handle_upload( $file_id, $post_id, $post_data = array(), $overrides = array() ) { // phpcs:ignore
		return new WP_Error( 'not_used', 'not used in this test' );
	}
}

if ( ! function_exists( 'wp_check_filetype' ) ) {
	function wp_check_filetype( $filename, $mimes = null ) { // phpcs:ignore
		$name = strtolower( (string) $filename );
		if ( '.svg' === substr( $name, -4 ) ) {
			return array(
				'ext'  => 'svg',
				'type' => 'image/svg+xml',
			);
		}
		return array(
			'ext'  => false,
			'type' => false,
		);
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) { // phpcs:ignore
		return preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $filename );
	}
}

if ( ! function_exists( 'sanitize_mime_type' ) ) {
	function sanitize_mime_type( $mime_type ) { // phpcs:ignore
		return strtolower( trim( (string) $mime_type ) );
	}
}

final class MediaSvgUploadRefusalTest extends TestCase {

	public function test_base64_svg_upload_refused_with_raster_hint_not_inline_svg(): void {
		$media = new Mcpwp_Media();
		$svg   = base64_encode( '<svg xmlns="http://www.w3.org/2000/svg"><circle r="1"/></svg>' );

		$result = $media->upload_from_base64( $svg, 'icon.svg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'svg_not_allowed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $data['status'] ?? null );
		$hint = (string) ( $data['hint'] ?? '' );
		$this->assertStringContainsString( 'Convert the graphic to PNG or WebP, upload that as media and reference it with an image block', $hint );
		$this->assertStringNotContainsString( 'Inline SVG', $hint );
		$this->assertStringNotContainsString( 'HTML widgets', $hint );
		$this->assertSame( array( 'png', 'webp', 'jpg' ), $data['alternatives'] ?? null );
	}
}
