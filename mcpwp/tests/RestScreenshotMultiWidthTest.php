<?php
/**
 * #804 — wp_screenshot_url had no way to sweep several viewport widths in
 * one call, and full_page was rejected outright ("Unknown parameter") even
 * though the underlying capture() already forwarded it to the Cloudflare
 * worker. These tests pin the REST-level wiring: widths fans out to one
 * capture() call per width (each surviving the others' failure) via
 * Mcpwp_Screenshot::capture_many(), webhook_url is refused together with
 * widths, full_page reaches capture()'s args, and the adversarial-review
 * follow-ups (#804 W3/W5/NIT-3): widths are clamped to 320-1920 BEFORE
 * de-duplicating, a sweep where every width failed is a whole WP_Error
 * (isError for MCP), and inline+widths+full_page is refused outright.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-screenshot.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-screenshot.php';

final class RestScreenshotMultiWidthTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
	}

	private function install_spy( Mcpwp_REST_Screenshot $controller, Mcpwp_Screenshot_MultiWidth_Spy $spy ): void {
		$prop = new ReflectionProperty( Mcpwp_REST_Screenshot::class, 'screenshot' );
		$prop->setAccessible( true );
		$prop->setValue( $controller, $spy );
	}

	private function request( array $params ): WP_REST_Request {
		return new WP_REST_Request( 'POST', '/mcpwp/v1/screenshot', $params );
	}

	public function test_widths_fans_out_to_one_capture_call_per_width(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		$resp = $controller->take_screenshot(
			$this->request( array( 'url' => 'https://example.com', 'widths' => array( 390, 767, 1024, 1440 ) ) )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$data = $resp->get_data();

		$this->assertSame( array( 390, 767, 1024, 1440 ), $data['widths'] );
		$this->assertCount( 4, $data['screenshots'] );
		$this->assertSame( 4, $data['succeeded'] );
		$this->assertSame( 0, $data['failed'] );
		$this->assertSame( array( 390, 767, 1024, 1440 ), array_column( $spy->calls, 'width' ) );

		foreach ( $data['screenshots'] as $i => $shot ) {
			$this->assertSame( $data['widths'][ $i ], $shot['width'] );
			$this->assertTrue( $shot['success'] );
		}
	}

	public function test_widths_is_capped_at_six(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		// Seven DISTINCT, already-valid (320-1920) widths — clamping is a
		// no-op here, so any reduction to 6 is unambiguously the cap, not an
		// incidental collapse from clamp-then-dedupe (that's covered by its
		// own test below).
		$resp = $controller->take_screenshot(
			$this->request( array( 'url' => 'https://example.com', 'widths' => array( 320, 400, 500, 600, 700, 800, 900 ) ) )
		);
		$data = $resp->get_data();

		$this->assertCount( 6, $data['screenshots'] );
		$this->assertSame( array( 320, 400, 500, 600, 700, 800 ), $data['widths'] );
	}

	// #804 NIT-3: clamp to 320-1920 FIRST, then de-duplicate. Two distinct
	// out-of-range widths that clamp to the same edge value must collapse
	// into one entry, not silently keep only one of the "duplicates" as if
	// the caller had sent it twice, and not clamp only after already
	// treating them as distinct.
	public function test_widths_are_clamped_before_deduping(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		$resp = $controller->take_screenshot(
			// 100 and 200 both clamp to 320; 5000 clamps to 1920.
			$this->request( array( 'url' => 'https://example.com', 'widths' => array( 100, 200, 5000, 800 ) ) )
		);
		$data = $resp->get_data();

		$this->assertSame( array( 320, 1920, 800 ), $data['widths'] );
		$this->assertCount( 3, $data['screenshots'] );
		$this->assertSame( array( 320, 1920, 800 ), array_column( $spy->calls, 'width' ) );
	}

	public function test_one_bad_width_does_not_block_the_others(): void {
		$controller      = new Mcpwp_REST_Screenshot();
		$spy             = new Mcpwp_Screenshot_MultiWidth_Spy();
		$spy->fail_width = 767;
		$this->install_spy( $controller, $spy );

		$resp = $controller->take_screenshot(
			$this->request( array( 'url' => 'https://example.com', 'widths' => array( 390, 767, 1024 ) ) )
		);
		$data = $resp->get_data();

		$this->assertCount( 3, $data['screenshots'] );
		$this->assertSame( 2, $data['succeeded'] );
		$this->assertSame( 1, $data['failed'] );
		$this->assertTrue( $data['screenshots'][0]['success'] );
		$this->assertFalse( $data['screenshots'][1]['success'] );
		$this->assertSame( 'mshots_rejected', $data['screenshots'][1]['code'] );
		$this->assertTrue( $data['screenshots'][2]['success'] );
	}

	// #804 W3: every width failing means the call did not do what it was
	// asked — it must be a whole WP_Error (so MCP dispatch sets isError),
	// not a 200 whose "success" envelope hides an all-false screenshots
	// array that a caller has to inspect to discover nothing worked.
	public function test_a_sweep_where_every_width_fails_is_a_whole_error(): void {
		$controller           = new Mcpwp_REST_Screenshot();
		$spy                  = new Mcpwp_Screenshot_MultiWidth_Spy();
		$spy->fail_all_widths = true;
		$this->install_spy( $controller, $spy );

		$resp = $controller->take_screenshot(
			$this->request( array( 'url' => 'https://example.com', 'widths' => array( 390, 767, 1024 ) ) )
		);

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'screenshot_sweep_failed', $resp->get_error_code() );
		$data = $resp->get_error_data();
		$this->assertSame( array( 390, 767, 1024 ), $data['widths'] );
		$this->assertCount( 3, $data['screenshots'] );
		foreach ( $data['screenshots'] as $shot ) {
			$this->assertFalse( $shot['success'] );
		}
	}

	public function test_widths_and_webhook_url_together_is_refused(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		$resp = $controller->take_screenshot(
			$this->request(
				array(
					'url'         => 'https://example.com',
					'widths'      => array( 390, 1024 ),
					'webhook_url' => 'https://example.com/hook',
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'incompatible_params', $resp->get_error_code() );
		$this->assertCount( 0, $spy->calls, 'capture() must not run when the params are refused.' );
	}

	// #804 W5: several full-page captures returned as raw base64 in one
	// response is the worst-case memory shape — refused outright rather
	// than silently accepted.
	public function test_inline_with_widths_and_full_page_together_is_refused(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		$resp = $controller->take_screenshot(
			$this->request(
				array(
					'url'       => 'https://example.com',
					'widths'    => array( 390, 1024 ),
					'full_page' => true,
					'inline'    => true,
				)
			)
		);

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'incompatible_params', $resp->get_error_code() );
		$this->assertCount( 0, $spy->calls, 'capture() must not run when the params are refused.' );
	}

	// inline+widths WITHOUT full_page is unaffected — the memory concern is
	// specifically several full-PAGE captures, not several ordinary ones.
	public function test_inline_with_widths_alone_is_still_allowed(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		$resp = $controller->take_screenshot(
			$this->request( array( 'url' => 'https://example.com', 'widths' => array( 390, 1024 ), 'inline' => true ) )
		);

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
	}

	public function test_full_page_reaches_capture_args(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		$controller->take_screenshot( $this->request( array( 'url' => 'https://example.com', 'full_page' => true ) ) );

		$this->assertNotEmpty( $spy->calls );
		$this->assertTrue( $spy->calls[0]['full_page'], 'full_page must reach capture() — the old route rejected this param entirely (#804).' );
	}
}

/**
 * Test double: records every capture() call and returns a canned success
 * per width, unless that width is in $fail_width (or $fail_all_widths is
 * set), in which case it returns a WP_Error shaped like the real
 * mshots-rejection error (#804).
 *
 * capture_many() is overridden too, mirroring the real contract's shape
 * (one capture() call per width, keyed by width) without going through the
 * real method's private CF-config/mshots-probe machinery, which would hit
 * the network — that machinery has its own dedicated tests.
 */
class Mcpwp_Screenshot_MultiWidth_Spy extends Mcpwp_Screenshot {

	/** @var array<int,array> */
	public $calls = array();

	/** @var int|null */
	public $fail_width = null;

	/** @var bool */
	public $fail_all_widths = false;

	public function capture( $url, $args = array() ) {
		$this->calls[] = $args;

		$width = isset( $args['width'] ) ? (int) $args['width'] : 0;

		if ( $this->fail_all_widths || ( null !== $this->fail_width && $width === $this->fail_width ) ) {
			return new WP_Error( 'mshots_rejected', 'WordPress.com mshots refused this host (HTTP 403).' );
		}

		return array(
			'success'        => true,
			'url'             => $url,
			'service'         => 'wordpress-mshots',
			'screenshot_url'  => 'https://s0.wp.com/mshots/v1/example?w=' . $width,
		);
	}

	public function capture_many( $url, array $widths, $args = array() ) {
		$results = array();
		foreach ( $widths as $width ) {
			$results[ $width ] = $this->capture( $url, array_merge( $args, array( 'width' => $width ) ) );
		}
		return $results;
	}
}
