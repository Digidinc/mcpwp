<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-screenshot.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-screenshot.php';

/**
 * Bug #677: wp_screenshot_url / POST /screenshot creates media even when the
 * caller explicitly passed save_to_media=false, because the REST controller
 * unconditionally forced save_to_media=true whenever inline was not true.
 *
 * @see Mcpwp_REST_Screenshot::take_screenshot()
 */
final class RestScreenshotSaveToMediaTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
	}

	/**
	 * Swap in a test double for the controller's private Mcpwp_Screenshot
	 * instance so we can assert on the args it receives without touching
	 * the network / Cloudflare worker.
	 */
	private function install_capture_spy( Mcpwp_REST_Screenshot $controller, Mcpwp_Screenshot_Capture_Spy $spy ): void {
		$prop = new ReflectionProperty( Mcpwp_REST_Screenshot::class, 'screenshot' );
		$prop->setAccessible( true );
		$prop->setValue( $controller, $spy );
	}

	public function test_explicit_save_to_media_false_is_honored_when_not_inline(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_Capture_Spy();
		$this->install_capture_spy( $controller, $spy );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/screenshot',
			array(
				'url'           => 'https://example.com',
				'inline'        => false,
				'save_to_media' => false,
			)
		);

		$controller->take_screenshot( $request );

		$this->assertNotNull( $spy->last_args, 'Expected capture() to be called.' );
		$this->assertFalse(
			$spy->last_args['save_to_media'],
			'Explicit save_to_media=false must not be overridden to true (#677).'
		);
	}

	public function test_save_to_media_defaults_true_when_absent_and_not_inline(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_Capture_Spy();
		$this->install_capture_spy( $controller, $spy );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/screenshot',
			array(
				'url'    => 'https://example.com',
				'inline' => false,
				// save_to_media intentionally absent.
			)
		);

		$controller->take_screenshot( $request );

		$this->assertNotNull( $spy->last_args, 'Expected capture() to be called.' );
		$this->assertTrue(
			$spy->last_args['save_to_media'],
			'Absent save_to_media must default to true so a URL can be returned (#560).'
		);
	}

	public function test_inline_true_behavior_is_unchanged(): void {
		$controller = new Mcpwp_REST_Screenshot();
		$spy        = new Mcpwp_Screenshot_Capture_Spy();
		$this->install_capture_spy( $controller, $spy );

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/screenshot',
			array(
				'url'           => 'https://example.com',
				'inline'        => true,
				'save_to_media' => true,
			)
		);

		$controller->take_screenshot( $request );

		$this->assertNotNull( $spy->last_args, 'Expected capture() to be called.' );
		$this->assertTrue(
			$spy->last_args['save_to_media'],
			'inline=true with explicit save_to_media=true must still be honored.'
		);
	}
}

/**
 * Test double: records the args passed to capture() and returns a canned
 * success payload instead of hitting the network.
 */
class Mcpwp_Screenshot_Capture_Spy extends Mcpwp_Screenshot {

	/**
	 * Args received by the last capture() call.
	 *
	 * @var array|null
	 */
	public $last_args = null;

	public function capture( $url, $args = array() ) {
		$this->last_args = $args;

		return array(
			'screenshot' => 'ZmFrZQ==',
			'format'     => 'png',
			'media'      => ! empty( $args['save_to_media'] ) ? array( 'id' => 999, 'url' => 'https://example.com/fake.png' ) : null,
		);
	}
}
