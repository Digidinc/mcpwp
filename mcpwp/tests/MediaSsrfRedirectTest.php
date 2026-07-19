<?php
/**
 * Regression tests — SSRF via redirect in the media downloader.
 *
 * Core download_url() validates nothing and follows redirects, so a public URL
 * could 30x-redirect to a link-local/internal address (e.g. cloud metadata at
 * 169.254.169.254) and be fetched anyway. Mcpwp_Security::safe_download_url()
 * must re-validate every hop and refuse to fetch a private/reserved target,
 * while still following a redirect to another public URL.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-security.php';

final class MediaSsrfRedirectTest extends TestCase {

	/** IP literal on a public range (example.com) — no DNS lookup needed. */
	private const PUBLIC_IP = '93.184.216.34';

	/** Link-local cloud-metadata address. */
	private const METADATA_IP = '169.254.169.254';

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['_ssrf_requested']    = array();

		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				$GLOBALS['_ssrf_requested'][] = $url;

				// A public URL that redirects into the private network.
				if ( false !== strpos( $url, '/redirect-evil' ) ) {
					return array(
						'response' => array( 'code' => 302 ),
						'headers'  => array( 'location' => 'http://' . MediaSsrfRedirectTest::METADATA_IP . '/latest/meta-data/' ),
						'body'     => '',
					);
				}
				// A public URL that redirects to another public URL.
				if ( false !== strpos( $url, '/redirect-ok' ) ) {
					return array(
						'response' => array( 'code' => 302 ),
						'headers'  => array( 'location' => 'http://' . MediaSsrfRedirectTest::PUBLIC_IP . '/final.png' ),
						'body'     => '',
					);
				}

				// 2xx bodies. Simulate WP's streaming behaviour: when the caller
				// passes stream + filename, the body is written to that file and
				// the returned body is empty (this is what safe_download_url relies
				// on to avoid buffering in memory).
				// The internal target — must never be reached.
				$body = ( false !== strpos( $url, MediaSsrfRedirectTest::METADATA_IP ) )
					? 'SECRET-INSTANCE-METADATA'
					: 'PIXELDATA';

				if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
					file_put_contents( $args['filename'], $body );
					return array(
						'response' => array( 'code' => 200 ),
						'headers'  => array(),
						'body'     => '',
					);
				}

				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => $body,
				);
			},
			10,
			3
		);
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	public function test_redirect_to_link_local_ip_is_blocked_before_fetch(): void {
		$result = Mcpwp_Security::safe_download_url( 'http://' . self::PUBLIC_IP . '/redirect-evil' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ssrf_blocked', $result->get_error_code() );

		// The metadata endpoint must have been validated and rejected BEFORE any
		// HTTP request was made to it — the exfiltration primitive is closed.
		foreach ( $GLOBALS['_ssrf_requested'] as $requested ) {
			$this->assertStringNotContainsString(
				self::METADATA_IP,
				$requested,
				'safe_download_url must not fetch the private redirect target'
			);
		}
	}

	public function test_direct_public_url_downloads_to_temp_file(): void {
		$result = Mcpwp_Security::safe_download_url( 'http://' . self::PUBLIC_IP . '/pixel.png' );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );
		$this->assertSame( 'PIXELDATA', file_get_contents( $result ) );

		wp_delete_file( $result );
	}

	public function test_redirect_to_public_url_is_followed(): void {
		$result = Mcpwp_Security::safe_download_url( 'http://' . self::PUBLIC_IP . '/redirect-ok' );

		$this->assertIsString( $result );
		$this->assertFileExists( $result );
		$this->assertSame( 'PIXELDATA', file_get_contents( $result ) );

		wp_delete_file( $result );
	}
}
