<?php
/**
 * #765: wp_apply_elementor_part reported applied=true, meta_written=true,
 * meta_verified=true and sections_saved=1 for a draft that did not render as
 * Elementor at all. The writer's verdict existed — it was buried under
 * details.last_operation, several levels below where any caller reads.
 *
 * These tests pin the lift: the render verdict of the nested write has to
 * appear at the top level of the apply_part response, and a single failed
 * insert among several has to fail the whole operation.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ApplyPartRenderStatusTest extends TestCase {

	/**
	 * @param array $response Response under construction.
	 * @param mixed $details  Nested write result.
	 * @return array
	 */
	private function lift( array $response, $details ): array {
		$controller = ( new ReflectionClass( Mcpwp_Rest_Elementor_Pro::class ) )->newInstanceWithoutConstructor();
		$method     = new ReflectionMethod( Mcpwp_Rest_Elementor_Pro::class, 'lift_render_status' );
		$method->setAccessible( true );

		return $method->invoke( $controller, $response, $details );
	}

	/**
	 * The reported shape: insert_part_into_page() wraps each add_section()
	 * result under last_operation.
	 */
	public function test_a_failed_render_two_levels_down_reaches_the_top_level(): void {
		$response = $this->lift(
			array( 'applied' => true ),
			array(
				'inserted'       => true,
				'inserted_count' => 1,
				'last_operation' => array(
					'success'         => true,
					'render_verified' => false,
					'render_errors'   => array( 'document_save: TypeError: array_filter(): Argument #1 ($array) must be of type array, true given at heading.php:134' ),
				),
			)
		);

		$this->assertFalse( $response['render_verified'], 'applied=true must not be the only thing a caller can read.' );
		$this->assertSame(
			array( 'document_save: TypeError: array_filter(): Argument #1 ($array) must be of type array, true given at heading.php:134' ),
			$response['render_errors']
		);
		$this->assertStringContainsString( 'did not finish rendering', $response['message'] );
	}

	public function test_a_clean_insert_reports_a_verified_render(): void {
		$response = $this->lift(
			array( 'applied' => true ),
			array( 'last_operation' => array( 'render_verified' => true ) )
		);

		$this->assertTrue( $response['render_verified'] );
		$this->assertArrayNotHasKey( 'render_errors', $response );
		$this->assertArrayNotHasKey( 'message', $response );
	}

	/**
	 * A multi-section part inserts once per section. One dead render makes the
	 * page wrong, so the operation is not verified — averaging or last-wins
	 * would report the exact failure this issue is about.
	 */
	public function test_one_failed_insert_among_several_fails_the_operation(): void {
		$response = $this->lift(
			array( 'applied' => true ),
			array(
				'operations' => array(
					array( 'render_verified' => true ),
					array( 'render_verified' => false, 'render_errors' => array( 'html_cache_rebuild: RuntimeException: boom at x.php:1' ) ),
					array( 'render_verified' => true ),
				),
			)
		);

		$this->assertFalse( $response['render_verified'] );
		$this->assertSame( array( 'html_cache_rebuild: RuntimeException: boom at x.php:1' ), $response['render_errors'] );
	}

	/**
	 * A result carrying no verdict is not a passing verdict. Claiming
	 * render_verified=true for a path that never reported one would recreate
	 * the defect in the reporting layer itself.
	 */
	public function test_a_result_without_a_verdict_claims_nothing(): void {
		$response = $this->lift( array( 'applied' => true ), array( 'inserted' => true ) );

		$this->assertArrayNotHasKey( 'render_verified', $response );
		$this->assertArrayNotHasKey( 'render_errors', $response );
	}
}
