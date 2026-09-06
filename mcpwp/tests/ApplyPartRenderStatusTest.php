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

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';

final class Mcpwp_Apply_Part_Basic_Double {

	/** @var array<int,array> */
	public $add_results = array();

	/** @var array|WP_Error */
	public $save_result;

	public function add_section( $page_id, $data ) {
		return array_shift( $this->add_results );
	}

	public function set_elementor_data( $page_id, $payload ) {
		return $this->save_result;
	}
}

final class Mcpwp_Apply_Part_Pro_Double extends Mcpwp_Elementor_Pro {

	/** @var array */
	public $part;

	public function get_part( $part_id ) {
		return $this->part;
	}
}

final class ApplyPartRenderStatusTest extends TestCase {

	private function pro_with_basic( Mcpwp_Apply_Part_Basic_Double $basic ): Mcpwp_Apply_Part_Pro_Double {
		$pro      = new Mcpwp_Apply_Part_Pro_Double();
		$property = new ReflectionProperty( Mcpwp_Elementor_Pro::class, 'basic_handler' );
		$property->setAccessible( true );
		$property->setValue( $pro, $basic );

		return $pro;
	}

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

	public function test_multi_section_insert_returns_every_save_verdict_for_aggregation(): void {
		$basic = new Mcpwp_Apply_Part_Basic_Double();
		$basic->add_results = array(
			array(
				'element_id'      => 'first001',
				'render_verified' => false,
				'render_errors'   => array( 'document_save: RuntimeException: first failed at x.php:1' ),
			),
			array(
				'element_id'      => 'second01',
				'render_verified' => true,
			),
		);

		$pro       = $this->pro_with_basic( $basic );
		$pro->part = array(
			'elementor_data' => array(
				array( 'id' => 'first001' ),
				array( 'id' => 'second01' ),
			),
		);

		$result = $pro->insert_part_into_page( 91, 92, 'end' );

		$this->assertCount( 2, $result['operations'], 'Dropping earlier operations makes a later clean save hide an earlier render failure.' );
		$this->assertFalse( $result['operations'][0]['render_verified'] );
		$this->assertTrue( $result['operations'][1]['render_verified'] );
	}

	public function test_replace_mode_returns_the_actual_save_verdict(): void {
		$template_id = 93;
		$page_id     = 94;
		$elements    = array( array( 'id' => 'replace1' ) );

		$GLOBALS['_mcpwp_test_posts'][ $template_id ] = new WP_Post(
			array(
				'ID'        => $template_id,
				'post_type' => 'elementor_library',
			)
		);
		$GLOBALS['_mcpwp_test_posts'][ $page_id ] = new WP_Post(
			array(
				'ID'        => $page_id,
				'post_type' => 'page',
			)
		);
		$GLOBALS['_mcpwp_test_meta'][ $template_id ]['_elementor_data'] = wp_json_encode( $elements );

		$basic = new Mcpwp_Apply_Part_Basic_Double();
		$basic->save_result = array(
			'success'         => true,
			'render_verified' => false,
			'render_errors'   => array( 'html_cache_rebuild: RuntimeException: replace failed at x.php:1' ),
		);

		$pro       = $this->pro_with_basic( $basic );
		$pro->part = array( 'elementor_data' => $elements );

		$result = $pro->apply_part_to_page( $template_id, $page_id );

		$this->assertIsArray( $result, 'Returning literal true discards the render verdict needed by the REST response.' );
		$this->assertFalse( $result['render_verified'] );
		$this->assertSame( $basic->save_result['render_errors'], $result['render_errors'] );
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
