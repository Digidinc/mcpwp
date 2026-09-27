<?php
/**
 * #804 — wp_screenshot_url had no way to sweep several viewport widths in
 * one call, and full_page was rejected outright ("Unknown parameter") even
 * though the underlying capture() already forwarded it to the Cloudflare
 * worker. These tests pin the REST-level wiring: widths fans out to one
 * capture() call per width (each surviving the others' failure), webhook_url
 * is refused together with widths, and full_page reaches capture()'s args.
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

	public function test_widths_fans_out_to_one_capture_call_per_width(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/screenshot',
			array(
				'url'    => 'https://example.com',
				'widths' => array( 390, 767, 1024, 1440 ),
			)
		);

		$resp = $controller->take_screenshot( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $resp );
		$data = $resp->get_data();

		$this->assertSame( array( 390, 767, 1024, 1440 ), $data['widths'] );
		$this->assertCount( 4, $data['screenshots'] );
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

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/screenshot',
			array(
				'url'    => 'https://example.com',
				'widths' => array( 100, 200, 300, 400, 500, 600, 700, 800 ),
			)
		);

		$data = $controller->take_screenshot( $request )->get_data();

		$this->assertCount( 6, $data['screenshots'] );
	}

	public function test_one_bad_width_does_not_block_the_others(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$spy->fail_width = 767;
		$this->install_spy( $controller, $spy );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/screenshot',
			array(
				'url'    => 'https://example.com',
				'widths' => array( 390, 767, 1024 ),
			)
		);

		$data = $controller->take_screenshot( $request )->get_data();

		$this->assertCount( 3, $data['screenshots'] );
		$this->assertTrue( $data['screenshots'][0]['success'] );
		$this->assertFalse( $data['screenshots'][1]['success'] );
		$this->assertSame( 'mshots_rejected', $data['screenshots'][1]['code'] );
		$this->assertTrue( $data['screenshots'][2]['success'] );
	}

	public function test_widths_and_webhook_url_together_is_refused(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/screenshot',
			array(
				'url'         => 'https://example.com',
				'widths'      => array( 390, 1024 ),
				'webhook_url' => 'https://example.com/hook',
			)
		);

		$resp = $controller->take_screenshot( $request );

		$this->assertInstanceOf( WP_Error::class, $resp );
		$this->assertSame( 'incompatible_params', $resp->get_error_code() );
		$this->assertCount( 0, $spy->calls, 'capture() must not run when the params are refused.' );
	}

	public function test_full_page_reaches_capture_args(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_MultiWidth_Spy();
		$this->install_spy( $controller, $spy );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/screenshot',
			array(
				'url'       => 'https://example.com',
				'full_page' => true,
			)
		);

		$controller->take_screenshot( $request );

		$this->assertNotEmpty( $spy->calls );
		$this->assertTrue( $spy->calls[0]['full_page'], 'full_page must reach capture() — the old route rejected this param entirely (#804).' );
	}
}

/**
 * Test double: records every capture() call and returns a canned success
 * per width, unless that width is in $fail_width, in which case it returns
 * a WP_Error shaped like the real mshots-rejection error (#804).
 */
class Mcpwp_Screenshot_MultiWidth_Spy extends Mcpwp_Screenshot {

	/** @var array<int,array> */
	public $calls = array();

	/** @var int|null */
	public $fail_width = null;

	public function capture( $url, $args = array() ) {
		$this->calls[] = $args;

		if ( null !== $this->fail_width && isset( $args['width'] ) && (int) $args['width'] === $this->fail_width ) {
			return new WP_Error( 'mshots_rejected', 'WordPress.com mshots refused this host (HTTP 403).' );
		}

		return array(
			'success'    => true,
			'url'        => $url,
			'service'    => 'wordpress-mshots',
			'screenshot_url' => 'https://s0.wp.com/mshots/v1/example?w=' . ( isset( $args['width'] ) ? $args['width'] : 0 ),
		);
	}
}
