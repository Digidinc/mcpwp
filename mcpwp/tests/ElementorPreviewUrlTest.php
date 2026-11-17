<?php
/**
 * #1226 — browser-openable Elementor preview URLs must not use the editor iframe param.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorPreviewUrlTest extends TestCase {

	private const PAGE_ID = 12260;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts']     = array();
		$GLOBALS['mcpwp_test_permalinks'] = array();
	}

	private function seed_post( string $status ): void {
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = new WP_Post(
			array(
				'ID'          => self::PAGE_ID,
				'post_type'   => 'page',
				'post_status' => $status,
				'post_title'  => 'Preview URL test',
			)
		);
	}

	public function test_published_post_uses_permalink(): void {
		$this->seed_post( 'publish' );
		$GLOBALS['mcpwp_test_permalinks'][ self::PAGE_ID ] = 'https://example.com/published-page/';

		$url = Mcpwp_Elementor_Preview_Url::for_post( self::PAGE_ID );

		$this->assertSame( 'https://example.com/published-page/', $url );
		$this->assertStringNotContainsString( 'elementor-preview=', $url );
	}

	public function test_non_published_post_uses_wordpress_preview_link(): void {
		foreach ( array( 'draft', 'pending', 'private' ) as $status ) {
			$this->seed_post( $status );

			$url = Mcpwp_Elementor_Preview_Url::for_post( self::PAGE_ID );

			$this->assertStringContainsString( 'preview=true', $url );
			$this->assertStringNotContainsString( 'elementor-preview=', $url, $status );
		}
	}

	public function test_missing_post_returns_empty_string(): void {
		$this->assertSame( '', Mcpwp_Elementor_Preview_Url::for_post( 999999 ) );
	}

	public function test_trashed_post_has_no_browseable_preview_url(): void {
		$this->seed_post( 'trash' );

		$this->assertSame( '', Mcpwp_Elementor_Preview_Url::for_post( self::PAGE_ID ) );
	}

	public function test_production_code_does_not_build_elementor_preview_query_arg(): void {
		$includes = dirname( __DIR__ ) . '/includes';
		$iterator   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $includes, FilesystemIterator::SKIP_DOTS )
		);

		$helper_basename = 'class-mcpwp-elementor-preview-url.php';

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}
			$path     = $file->getPathname();
			$contents = (string) file_get_contents( $path );

			if ( $helper_basename === $file->getFilename() ) {
				$contents = (string) preg_replace( '#/\*\*.*?\*/#s', '', $contents, 1 );
			}

			$this->assertStringNotContainsString(
				'elementor-preview',
				$contents,
				$path
			);
		}
	}
}