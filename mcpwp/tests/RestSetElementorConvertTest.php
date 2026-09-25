<?php
/**
 * Regression coverage for #883 — `wp_set_elementor` returned 409
 * `would_convert_existing_content` telling the caller to "Pass convert=true
 * if that is intended", but passing convert=true still returned the same
 * 409.
 *
 * The guard itself (Mcpwp_Elementor_Basic::guard_content_conversion(), via
 * set_elementor_data()) has always honoured `$data['convert']` correctly —
 * ContentConversionGuardTest and ElementorBuilderFlagTest exercise it
 * directly against the writer. The break was one layer up:
 * Mcpwp_REST_Elementor::set_elementor_data() built its `$data` array from
 * named `$request->get_param()` calls and simply never listed `convert`
 * among them, so the opt-in a caller sent in the request body never reached
 * the writer — every `wp_set_elementor` call (REST or MCP; the MCP dispatcher
 * builds a WP_REST_Request from the same tool arguments and routes it through
 * this exact handler) silently dropped it.
 *
 * The sibling surgical handlers (add-section, replace-section, patch) were
 * NOT affected: they forward `$request->get_params()` wholesale rather than
 * naming fields, so `convert` already reached save_elements_to_page() through
 * them. edit-section and edit-widget require a page that already has
 * Elementor data before they will do anything, so their conversion guard can
 * never actually fire — the missing field there is inert (see
 * `conversion_options()`'s doc), not user-visible.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';

final class RestSetElementorConvertTest extends TestCase {

	private const PAGE_ID = 9400;

	/** @var array */
	private $meta_before = array();

	/** @var array */
	private $posts_before = array();

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['mcpwp_test_is_admin']           = false;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();

		$this->meta_before  = $GLOBALS['_mcpwp_test_meta'] ?? array();
		$this->posts_before = $GLOBALS['_mcpwp_test_posts'] ?? array();

		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID => new WP_Post(
				array(
					'ID'           => self::PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'Convert forwarding probe',
					'post_name'    => 'convert-forwarding-probe',
					'post_status'  => 'draft',
					'post_content' => $this->gutenberg_content(),
				)
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_meta']  = $this->meta_before;
		$GLOBALS['_mcpwp_test_posts'] = $this->posts_before;
	}

	private function gutenberg_content(): string {
		return "<!-- wp:paragraph -->\n<p>Content someone wrote and would like to keep.</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * @return array One valid top-level section.
	 */
	private function section(): array {
		return array(
			'id'       => 'sec00002',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'col00002',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(),
				),
			),
		);
	}

	private function request( bool $convert ): WP_REST_Request {
		$params = array(
			'id'             => self::PAGE_ID,
			'elementor_data' => wp_json_encode( array( $this->section() ) ),
		);
		if ( $convert ) {
			$params['convert'] = true;
		}

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID, $params );
		$request->set_body_params( $params );

		return $request;
	}

	/**
	 * Satisfy Mcpwp_Elementor_Basic::is_active(), which is just
	 * defined( 'ELEMENTOR_VERSION' ). Separate process: the constant leaks
	 * across the shared PHPUnit run otherwise (#757).
	 */
	private function pretend_elementor_is_installed(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
	}

	/**
	 * Without convert, the REST handler must still refuse — this pins the
	 * guard's normal behaviour so the next test is proof of a real fix, not
	 * a guard that stopped refusing anything.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_without_convert_the_write_is_refused_with_409(): void {
		$this->pretend_elementor_is_installed();

		$controller = new Mcpwp_REST_Elementor();
		$result     = $controller->set_elementor_data( $this->request( false ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'would_convert_existing_content', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );

		// And the content really is untouched.
		$this->assertSame( $this->gutenberg_content(), get_post( self::PAGE_ID )->post_content );
	}

	/**
	 * The bug: this used to return the exact same 409 as the test above,
	 * because `convert` never reached the writer. It must now succeed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_convert_true_in_the_request_body_is_honoured(): void {
		$this->pretend_elementor_is_installed();

		$controller = new Mcpwp_REST_Elementor();
		$result     = $controller->set_elementor_data( $this->request( true ) );

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			'convert=true in the request must reach the writer and lift the would_convert_existing_content refusal. If this fails with would_convert_existing_content, the REST handler is dropping the convert param again.'
		);
		$this->assertInstanceOf( WP_REST_Response::class, $result );

		// The write actually happened: Elementor data is now stored, in place
		// of the previous non-Elementor content.
		$stored = get_post_meta( self::PAGE_ID, '_elementor_data', true );
		$this->assertNotSame( '', $stored );
		$decoded = json_decode( $stored, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'sec00002', $decoded[0]['id'] );
	}
}
