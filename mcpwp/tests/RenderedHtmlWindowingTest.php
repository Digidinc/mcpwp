<?php
/**
 * #804 — wp_get_rendered_html truncated a large document at ~51 KB with no
 * way to learn the real size or reach the rest, reliably losing the tail
 * (theme footer, Custom Code snippets) with no signal that anything was
 * missing. These tests pin the fix: every response reports total_bytes, a
 * truncated window says so and gives next_offset, and paging with offset
 * reaches the tail of a document far larger than the default window.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// The controller under test is not loaded by the shared bootstrap.
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';

final class RenderedHtmlWindowingTest extends TestCase {

	/** Footer marker placed at the very end of the mocked document. */
	private const FOOTER_MARKER = '<footer id="colophon">Copyright 2026 — Example Site</footer></body></html>';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		$GLOBALS['_mcpwp_test_posts']             = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	/**
	 * Build a document of exactly $body_bytes of filler before the footer
	 * marker, so the total size and the footer's position are both known.
	 */
	private function big_document( int $body_bytes ): string {
		$head = '<html><head><title>Big page</title></head><body>';
		$fill = str_repeat( 'x', $body_bytes );
		return $head . $fill . self::FOOTER_MARKER;
	}

	/**
	 * Mock wp_remote_get for any URL to return the given HTML body with a
	 * 200 status, mirroring how the controller's own tests short-circuit
	 * pre_http_request instead of hitting the network.
	 */
	private function mock_remote_html( string $html ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $html ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => $html,
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	private function authed_request( array $params ): array {
		$controller = new Mcpwp_REST_Site_Content();
		$created    = $controller->create_scoped_api_key( 'test key', array( 'read' ), 'custom' );

		$request = new WP_REST_Request(
			'GET',
			'/mcpwp/v1/rendered-html',
			$params,
			array( 'X-API-Key' => $created['key'] )
		);

		$this->assertTrue( $controller->verify_api_key( $request ) );

		return array( $controller, $request );
	}

	// ── A response is never a silent cut: total_bytes is always reported ──

	public function test_default_window_reports_total_bytes_and_truncation(): void {
		$html = $this->big_document( 100000 ); // ~100 KB body + head/footer.
		$this->mock_remote_html( $html );

		[ $controller, $request ] = $this->authed_request( array( 'url' => 'https://example.com/big' ) );
		$resp = $controller->get_rendered_html( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$data = $resp->get_data();

		$this->assertSame( strlen( $html ), $data['total_bytes'], 'total_bytes must reflect the full document, not the window.' );
		$this->assertTrue( $data['truncated'], 'A ~100KB document must be reported truncated at the default window.' );
		$this->assertSame( 0, $data['offset'] );
		$this->assertSame( Mcpwp_REST_Site_Content::RENDERED_HTML_DEFAULT_MAX_BYTES, $data['length'] );
		$this->assertNotNull( $data['next_offset'], 'A truncated response must say where to continue.' );
		$this->assertIsString( $data['note'] );
		$this->assertStringContainsString( (string) $data['total_bytes'], $data['note'] );

		// The footer must NOT be present in the default window of a page
		// this size — this is the bug's failure mode being pinned, not the
		// fix's behavior.
		$this->assertStringNotContainsString( 'colophon', $data['html'] );
	}

	// ── Paging with next_offset eventually reaches the tail/footer ───────

	public function test_paging_with_offset_reaches_footer(): void {
		$html = $this->big_document( 100000 );
		$this->mock_remote_html( $html );

		[ $controller, $request ] = $this->authed_request( array( 'url' => 'https://example.com/big' ) );
		$first = $controller->get_rendered_html( $request )->get_data();

		$this->assertTrue( $first['truncated'] );
		$next_offset = $first['next_offset'];
		$this->assertNotNull( $next_offset );

		// Jump straight to a window that covers the end of the document —
		// exactly what an agent computes from total_bytes and max_bytes.
		$tail_offset = max( 0, $first['total_bytes'] - Mcpwp_REST_Site_Content::RENDERED_HTML_DEFAULT_MAX_BYTES );

		[ $controller2, $request2 ] = $this->authed_request(
			array( 'url' => 'https://example.com/big', 'offset' => $tail_offset )
		);
		$tail = $controller2->get_rendered_html( $request2 )->get_data();

		$this->assertStringContainsString( 'colophon', $tail['html'], 'Paging to the tail must include the theme footer.' );
		$this->assertStringContainsString( 'Copyright 2026', $tail['html'] );
		$this->assertSame( $first['total_bytes'], $tail['total_bytes'], 'total_bytes must be stable across pages of the same document.' );
		$this->assertNull( $tail['next_offset'], 'The final window has nothing further to page to.' );
	}

	// ── A document that fits in the (raised) ceiling comes back whole ────

	public function test_document_under_ceiling_is_not_truncated_when_max_bytes_covers_it(): void {
		$html = $this->big_document( 100000 ); // ~100 KB, well under the 512KB ceiling.
		$this->mock_remote_html( $html );

		[ $controller, $request ] = $this->authed_request(
			array( 'url' => 'https://example.com/big', 'max_bytes' => 500000 )
		);
		$data = $controller->get_rendered_html( $request )->get_data();

		$this->assertFalse( $data['truncated'] );
		$this->assertNull( $data['note'] );
		$this->assertSame( $data['total_bytes'], $data['length'] );
		$this->assertStringContainsString( 'colophon', $data['html'] );
	}

	// ── max_bytes is still capped at the documented ceiling ───────────────

	public function test_max_bytes_is_capped_at_ceiling(): void {
		$html = $this->big_document( 2000000 ); // ~2 MB, well over the 512KB ceiling.
		$this->mock_remote_html( $html );

		[ $controller, $request ] = $this->authed_request(
			array( 'url' => 'https://example.com/big', 'max_bytes' => 10000000 )
		);
		$data = $controller->get_rendered_html( $request )->get_data();

		$this->assertSame( Mcpwp_REST_Site_Content::RENDERED_HTML_MAX_BYTES_CEILING, $data['length'] );
		$this->assertTrue( $data['truncated'] );
	}

	// ── A small document is never falsely reported as truncated ──────────

	public function test_small_document_is_not_truncated(): void {
		$html = '<html><head><title>Small</title></head><body>Hi</body></html>';
		$this->mock_remote_html( $html );

		[ $controller, $request ] = $this->authed_request( array( 'url' => 'https://example.com/small' ) );
		$data = $controller->get_rendered_html( $request )->get_data();

		$this->assertFalse( $data['truncated'] );
		$this->assertNull( $data['next_offset'] );
		$this->assertNull( $data['note'] );
		$this->assertSame( strlen( $html ), $data['total_bytes'] );
		$this->assertSame( strlen( $html ), $data['length'] );
	}

	// ── selector extraction still bypasses windowing entirely ─────────────

	public function test_selector_extraction_reaches_footer_regardless_of_size(): void {
		$html = $this->big_document( 100000 );
		$this->mock_remote_html( $html );

		[ $controller, $request ] = $this->authed_request(
			array( 'url' => 'https://example.com/big', 'selector' => 'footer' )
		);
		$data = $controller->get_rendered_html( $request )->get_data();

		$this->assertTrue( $data['selector_found'] );
		$this->assertStringContainsString( 'Copyright 2026', $data['html'] );
		$this->assertFalse( $data['truncated'], 'The extracted footer tag is small; it should fit the default window whole.' );
	}

	// ── #804 NIT-1: a negative offset/max_bytes is refused, not absint'd ──

	public function test_negative_offset_is_refused_with_400(): void {
		$html = $this->big_document( 1000 );
		$this->mock_remote_html( $html );

		[ $controller, $request ] = $this->authed_request(
			array( 'url' => 'https://example.com/big', 'offset' => -5000 )
		);
		$resp = $controller->get_rendered_html( $request );

		$this->assertInstanceOf( WP_Error::class, $resp, 'A negative offset must be refused, not silently absint\'d into a positive one.' );
		$this->assertSame( 400, $resp->get_error_data()['status'] );
	}

	public function test_negative_max_bytes_is_refused_with_400(): void {
		$html = $this->big_document( 1000 );
		$this->mock_remote_html( $html );

		[ $controller, $request ] = $this->authed_request(
			array( 'url' => 'https://example.com/big', 'max_bytes' => -1 )
		);
		$resp = $controller->get_rendered_html( $request );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 400, $resp->get_error_data()['status'] );
	}

	public function test_zero_offset_and_max_bytes_are_not_refused(): void {
		// Zero is a legitimate, non-negative value — only strictly negative
		// values are refused.
		$html = $this->big_document( 1000 );
		$this->mock_remote_html( $html );

		[ $controller, $request ] = $this->authed_request(
			array( 'url' => 'https://example.com/big', 'offset' => 0 )
		);
		$resp = $controller->get_rendered_html( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
	}

	// ── #804 W4: byte windows must not split a UTF-8 character ────────────

	public function test_utf8_multibyte_content_never_split_across_pages(): void {
		// Farsi text plus emoji, repeated densely enough that a fixed-size
		// byte window is virtually guaranteed to land mid-character
		// somewhere across the whole paging run — exactly the case this
		// exists for. mb_strlen/strlen differ hugely here (2-4 bytes per
		// character), unlike the ASCII filler the other tests use.
		$farsi_emoji_block = 'سلام دنیا 😀🌍✨ این یک متن آزمایشی است 🎉 ';
		$body              = str_repeat( $farsi_emoji_block, 4000 );
		$html              = '<html><head><title>UTF8</title></head><body>' . $body . self::FOOTER_MARKER;

		$this->assertGreaterThan(
			Mcpwp_REST_Site_Content::RENDERED_HTML_DEFAULT_MAX_BYTES * 2,
			strlen( $html ),
			'Sanity check: the document must span several default-sized pages for this test to mean anything.'
		);

		$this->mock_remote_html( $html );

		$reconstructed = '';
		$offset        = 0;
		$pages         = 0;
		$max_pages     = 50; // Guards the test itself against a stuck-offset bug hanging it.

		do {
			[ $controller, $request ] = $this->authed_request(
				array( 'url' => 'https://example.com/multibyte', 'offset' => $offset )
			);
			$data = $controller->get_rendered_html( $request )->get_data();

			$this->assertTrue(
				mb_check_encoding( $data['html'], 'UTF-8' ),
				"Page at offset {$offset} is not valid UTF-8 — a character was split across the window edge."
			);

			$reconstructed .= $data['html'];
			$offset         = $data['next_offset'];
			++$pages;
		} while ( null !== $offset && $pages < $max_pages );

		$this->assertLessThan( $max_pages, $pages, 'Paging did not terminate within the guard — likely a stuck offset.' );
		$this->assertGreaterThan( 1, $pages, 'Sanity check: this should have taken more than one page.' );
		$this->assertSame( strlen( $html ), strlen( $reconstructed ) );
		$this->assertSame( $html, $reconstructed, 'Concatenated pages must equal the original document exactly, byte for byte — nothing dropped, nothing duplicated.' );
	}
}
