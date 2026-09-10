<?php
/**
 * #845: readers of `_elementor_data` in the Elementor Pro library class.
 *
 *   template summary     array tree counted; unusable reported as unreadable
 *   get template / part  array tree returned; unusable reported, not []
 *   insert part          unusable part refused, not "part has no elements"
 *   apply template       array tree (gapped keys too) applied as a list;
 *                        unusable refused before the target page is touched
 *   save section         cut from an array tree; unusable refused (422)
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';

final class ElementorStoredTreeProTest extends TestCase {

	private const TEMPLATE_ID = 8480;
	private const PAGE_ID     = 8481;

	/** @var array */
	private $saved = array();

	protected function setUp(): void {
		foreach ( array( '_mcpwp_test_meta', '_mcpwp_test_posts' ) as $global ) {
			$this->saved[ $global ] = $GLOBALS[ $global ] ?? array();
			$GLOBALS[ $global ]     = array();
		}

		// Mcpwp_Elementor_Pro::is_elementor_active() reads did_action().
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $global => $value ) {
			$GLOBALS[ $global ] = $value;
		}
		unset( $GLOBALS['mcpwp_test_did_action']['elementor/loaded'] );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	private static function section( string $id, string $column_id, string $title ): array {
		return array(
			'id'       => $id,
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => $column_id,
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(
						array(
							'id'         => 'w' . substr( $id, 1 ),
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => $title ),
							'elements'   => array(),
						),
					),
				),
			),
		);
	}

	private static function tree(): array {
		return array( self::section( 'sec0001', 'col0001', 'First' ), self::section( 'sec0002', 'col0002', 'Second' ) );
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function unusable_meta(): array {
		return array(
			'corrupt json string'         => array( '[{"id":"sec0001","elType":', 'string' ),
			'json string that is no list' => array( '"just a string"', 'string' ),
			'array that is no list'       => array( array( 'title' => 'not an element' ), 'array' ),
		);
	}

	private function post( int $id, string $type, $raw ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_title'  => 'Post ' . $id,
				'post_name'   => 'post-' . $id,
				'post_status' => 'publish',
			)
		);
		$GLOBALS['_mcpwp_test_meta'][ $id ]['_elementor_data'] = $raw;
	}

	private function template( $raw ): void {
		$this->post( self::TEMPLATE_ID, 'elementor_library', $raw );
		$GLOBALS['_mcpwp_test_meta'][ self::TEMPLATE_ID ]['_elementor_template_type'] = 'section';
	}

	private function pro(): Mcpwp_Elementor_Pro {
		return new Mcpwp_Elementor_Pro();
	}

	private function invoke( $object, string $method, array $args ) {
		$ref = new ReflectionMethod( $object, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $object, $args );
	}

	private function meta( int $id, string $key = '_elementor_data' ) {
		return $GLOBALS['_mcpwp_test_meta'][ $id ][ $key ] ?? null;
	}

	private function assertRefused( $result, string $stored_as, string $label = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $label . ' Data that cannot be a tree is refused.' );
		$this->assertSame( 'invalid_elementor_data', $result->get_error_code(), $label );
		$data = $result->get_error_data();
		$this->assertSame( 422, $data['status'] ?? null, $label );
		$this->assertSame( $stored_as, $data['stored_as'] ?? null, $label . ' The refusal says how the data is stored.' );
	}

	// ---------------------------------------------------------------------
	// Shared process.
	// ---------------------------------------------------------------------

	public function test_template_summary_counts_array_stored_trees_and_reports_unusable_data_as_unreadable(): void {
		$this->template( array( 0 => self::tree()[0], 4 => self::tree()[1] ) );

		$summary = $this->invoke( $this->pro(), 'summarize_template_data', array( self::TEMPLATE_ID ) );

		$this->assertTrue( $summary['has_data'], 'An array-stored template stores data.' );
		$this->assertSame( 2, $summary['section_count'] );
		$this->assertSame( 2, $summary['widget_count'] );
		$this->assertSame( array( 'sec0001', 'sec0002' ), $summary['top_level_ids'] );
		$this->assertArrayNotHasKey( 'unreadable', $summary );

		foreach ( self::unusable_meta() as $label => list( $raw ) ) {
			$this->template( $raw );

			$summary = $this->invoke( $this->pro(), 'summarize_template_data', array( self::TEMPLATE_ID ) );

			$this->assertTrue( $summary['has_data'], "{$label}: there is data." );
			$this->assertTrue( $summary['unreadable'] ?? false, "{$label}: and it could not be read — not a template that stores nothing." );
		}
	}

	public function test_get_template_returns_array_stored_trees_and_reports_unusable_data(): void {
		$this->template( array( 0 => self::tree()[0], 4 => self::tree()[1] ) );

		$template = $this->pro()->get_template( self::TEMPLATE_ID );

		$this->assertIsArray( $template );
		$this->assertSame( self::tree(), $template['elementor_data'], 'The tree, as a list.' );
		$this->assertArrayNotHasKey( 'elementor_data_error', $template );

		foreach ( self::unusable_meta() as $label => list( $raw, $stored_as ) ) {
			$this->template( $raw );

			$template = $this->pro()->get_template( self::TEMPLATE_ID );

			$this->assertIsArray( $template, $label );
			$this->assertNull( $template['elementor_data'], "{$label}: unreadable is not an empty []." );
			$this->assertSame( 'invalid_elementor_data', $template['elementor_data_error']['code'] ?? null, $label );
			$this->assertSame( $stored_as, $template['elementor_data_error']['stored_as'] ?? null, $label );
		}
	}

	/**
	 * @dataProvider unusable_meta
	 */
	public function test_inserting_a_part_whose_data_cannot_be_a_tree_is_refused_as_unreadable( $raw, string $stored_as ): void {
		$pro = $this->pro();
		$this->template( $raw );
		$this->invoke( $pro, 'save_part_metadata', array( self::TEMPLATE_ID, array( 'is_part' => true ) ) );
		$this->post( self::PAGE_ID, 'page', wp_json_encode( self::tree() ) );

		$result = $pro->insert_part_into_page( self::TEMPLATE_ID, self::PAGE_ID );

		$this->assertRefused( $result, $stored_as, 'Not "the part has no elements":' );
		$this->assertSame( wp_json_encode( self::tree() ), $this->meta( self::PAGE_ID ), 'The page was not touched.' );
	}

	// ---------------------------------------------------------------------
	// Separate processes: these reach set_elementor_data(), which needs
	// ELEMENTOR_VERSION (#757).
	// ---------------------------------------------------------------------

	private function elementor_installed(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_apply_template_applies_an_array_stored_tree_and_refuses_unusable_data_before_touching_the_page(): void {
		$this->elementor_installed();

		foreach ( array( 'array list' => self::tree(), 'numerically keyed array' => array( 1 => self::tree()[0], 5 => self::tree()[1] ) ) as $label => $raw ) {
			$this->template( $raw );
			$this->post( self::PAGE_ID, 'page', '' );

			$result = $this->pro()->apply_template_to_page( self::PAGE_ID, self::TEMPLATE_ID );

			$this->assertNotInstanceOf( WP_Error::class, $result, $label );
			$this->assertIsString( $this->meta( self::PAGE_ID ), $label );
			$this->assertSame( self::tree(), json_decode( $this->meta( self::PAGE_ID ), true ), "{$label}: applied as a list." );
		}

		foreach ( self::unusable_meta() as $label => list( $raw, $stored_as ) ) {
			$this->template( $raw );
			$this->post( self::PAGE_ID, 'page', '' );
			unset( $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_edit_mode'], $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_wp_page_template'] );

			$this->assertRefused( $this->pro()->apply_template_to_page( self::PAGE_ID, self::TEMPLATE_ID ), $stored_as, "{$label}:" );
			$this->assertNull( $this->meta( self::PAGE_ID, '_elementor_edit_mode' ), "{$label}: the page was not switched to Elementor for a template that cannot be applied." );
			$this->assertNull( $this->meta( self::PAGE_ID, '_wp_page_template' ), $label );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_section_as_template_cuts_from_an_array_stored_page_and_refuses_unusable_data(): void {
		$this->elementor_installed();

		$this->post( self::PAGE_ID, 'page', array( 0 => self::tree()[0], 2 => self::tree()[1] ) );

		$result = $this->pro()->save_section_as_template( self::PAGE_ID, 'sec0002', 'Saved' );

		$this->assertIsArray( $result );
		$this->assertNotInstanceOf( WP_Error::class, $result );
		$template_id = (int) $result['template_id'];
		$this->assertSame( array( self::tree()[1] ), json_decode( $this->meta( $template_id ), true ), 'The section found in the array-stored tree.' );

		foreach ( self::unusable_meta() as $label => list( $raw, $stored_as ) ) {
			$this->post( self::PAGE_ID, 'page', $raw );
			$before = count( $GLOBALS['_mcpwp_test_posts'] );

			$this->assertRefused( $this->pro()->save_section_as_template( self::PAGE_ID, 'sec0001' ), $stored_as, "{$label}:" );
			$this->assertCount( $before, $GLOBALS['_mcpwp_test_posts'], "{$label}: no template was created." );
		}
	}
}
