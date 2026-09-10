<?php
/**
 * #845: `_elementor_data` stored as a PHP array crashed every Elementor path
 * except wp_patch_elementor and wp_set_elementor.
 *
 * Imports and some plugins store the tree as an array rather than JSON text,
 * and Elementor renders it. 3.11.1 taught patch and set to read that form
 * (read_stored_tree()); every other reader still called json_decode() on the
 * meta, which is a TypeError on PHP 8. Some of them also treated data that
 * cannot be a tree as an empty page — add_section then overwrote the whole
 * page with the one section it added.
 *
 * What this pins, for each reader on Mcpwp_Elementor_Basic:
 *   - an array-stored tree (a list, or integer keys with gaps) is the tree,
 *     and a write stores it back as JSON text;
 *   - data that cannot be a tree is refused with 422 invalid_elementor_data
 *     and says how it is stored, and nothing is written;
 *   - a read reports unreadable data as unreadable, never as an empty page.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorStoredTreeReadersTest extends TestCase {

	private const PAGE_ID = 8450;

	/** @var array */
	private $meta_before = array();

	/** @var array */
	private $posts_before = array();

	protected function setUp(): void {
		$this->meta_before  = $GLOBALS['_mcpwp_test_meta'] ?? array();
		$this->posts_before = $GLOBALS['_mcpwp_test_posts'] ?? array();

		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID => new WP_Post(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'Stored tree probe',
					'post_name'   => 'stored-tree-probe',
					'post_status' => 'draft',
				)
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_meta']  = $this->meta_before;
		$GLOBALS['_mcpwp_test_posts'] = $this->posts_before;
		unset( $GLOBALS['mcpwp_test_meta_write_fails'] );
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	private function writer(): Mcpwp_Elementor_Basic {
		return ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
	}

	private static function widget( string $id, string $type, array $settings = array() ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	private static function section( string $id, array $widgets, string $column_id = 'col0001' ): array {
		return array(
			'id'       => $id,
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => $column_id,
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => $widgets,
				),
			),
		);
	}

	/** Two sections, the first holding one heading. */
	private static function tree(): array {
		return array(
			self::section( 'sec0001', array( self::widget( 'hd00001', 'heading', array( 'title' => 'Kept' ) ) ) ),
			self::section( 'sec0002', array( self::widget( 'tx00002', 'text-editor', array( 'editor' => 'Second' ) ) ), 'col0002' ),
		);
	}

	private function seed( $raw ): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = $raw;
	}

	private function stored() {
		return $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ?? null;
	}

	/** The stored tree, decoded from the JSON text a write must leave. */
	private function stored_tree(): array {
		$stored = $this->stored();
		$this->assertIsString( $stored, 'Written back as JSON text, the format every write uses.' );

		$tree = json_decode( $stored, true );
		$this->assertIsArray( $tree, 'The stored text decodes to a tree.' );

		return $tree;
	}

	/**
	 * Array-stored trees that are the tree: a list, and integer keys with gaps.
	 *
	 * @return array<string, array{0: array}>
	 */
	public static function array_trees(): array {
		$tree = self::tree();

		return array(
			'array list'              => array( $tree ),
			'numerically keyed array' => array( array( 0 => $tree[0], 2 => $tree[1] ) ),
		);
	}

	/**
	 * Stored values that cannot be a tree, with the gettype() they report.
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function unusable_meta(): array {
		return array(
			'corrupt json string'         => array( '[{"id":"sec0001","elType":', 'string' ),
			'json string that is no list' => array( '"just a string"', 'string' ),
			'array that is no list'       => array( array( 'title' => 'not an element' ), 'array' ),
		);
	}

	private function assertRefused( $result, string $stored_as, $raw, string $label = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $label . ' Data that cannot be a tree is refused, never treated as an empty page.' );
		$this->assertSame( 'invalid_elementor_data', $result->get_error_code(), $label );
		$data = $result->get_error_data();
		$this->assertSame( 422, $data['status'] ?? null, $label );
		$this->assertSame( $stored_as, $data['stored_as'] ?? null, $label . ' The refusal says how the data is stored.' );
		$this->assertSame( $raw, $this->stored(), $label . ' Nothing was written.' );
	}

	// ---------------------------------------------------------------------
	// add_section / remove_section / replace_section (no Elementor needed).
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider array_trees
	 */
	public function test_add_section_uses_array_stored_data_as_the_tree( array $raw ): void {
		$this->seed( $raw );

		$result = $this->writer()->add_section( self::PAGE_ID, array( 'element' => self::section( 'new0001', array(), 'col0009' ) ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$tree = $this->stored_tree();
		$this->assertSame( array( 0, 1, 2 ), array_keys( $tree ), 'The existing sections survive, as a list; the added one follows.' );
		$this->assertSame( array_values( $raw ), array_slice( $tree, 0, 2 ) );
		$this->assertSame( 'new0001', $tree[2]['id'] );
		$this->assertSame( 3, $result['section_count'] );
	}

	/**
	 * @dataProvider unusable_meta
	 */
	public function test_add_section_refuses_data_that_cannot_be_a_tree( $raw, string $stored_as ): void {
		$this->seed( $raw );

		$result = $this->writer()->add_section( self::PAGE_ID, array( 'element' => self::section( 'new0001', array() ) ) );

		$this->assertRefused( $result, $stored_as, $raw );
	}

	/**
	 * @dataProvider array_trees
	 */
	public function test_remove_section_uses_array_stored_data_as_the_tree( array $raw ): void {
		$this->seed( $raw );

		$result = $this->writer()->remove_section( self::PAGE_ID, array( 'element_id' => 'sec0001' ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$tree = $this->stored_tree();
		$this->assertCount( 1, $tree );
		$this->assertSame( 'sec0002', $tree[0]['id'] );
	}

	/**
	 * @dataProvider unusable_meta
	 */
	public function test_remove_section_refuses_data_that_cannot_be_a_tree( $raw, string $stored_as ): void {
		$this->seed( $raw );

		$this->assertRefused( $this->writer()->remove_section( self::PAGE_ID, array( 'element_id' => 'sec0001' ) ), $stored_as, $raw );
	}

	public function test_remove_section_on_an_empty_page_still_answers_no_data(): void {
		foreach ( array( 'missing' => '', 'empty array' => array(), 'json []' => '[]' ) as $label => $raw ) {
			$this->seed( $raw );

			$result = $this->writer()->remove_section( self::PAGE_ID, array( 'element_id' => 'sec0001' ) );

			$this->assertInstanceOf( WP_Error::class, $result, $label );
			$this->assertNotSame( 'invalid_elementor_data', $result->get_error_code(), "{$label}: an empty page is not unreadable data." );
			$this->assertSame( 404, $result->get_error_data()['status'], $label );
		}
	}

	/**
	 * @dataProvider array_trees
	 */
	public function test_replace_section_uses_array_stored_data_as_the_tree( array $raw ): void {
		$this->seed( $raw );

		$result = $this->writer()->replace_section(
			self::PAGE_ID,
			array(
				'element_id' => 'sec0002',
				'element'    => self::section( 'sec0002', array( self::widget( 'hd00003', 'heading', array( 'title' => 'Replaced' ) ) ), 'col0003' ),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$tree = $this->stored_tree();
		$this->assertSame( array( 0, 1 ), array_keys( $tree ) );
		$this->assertSame( 'Kept', $tree[0]['elements'][0]['elements'][0]['settings']['title'] );
		$this->assertSame( 'Replaced', $tree[1]['elements'][0]['elements'][0]['settings']['title'] );
	}

	/**
	 * @dataProvider unusable_meta
	 */
	public function test_replace_section_refuses_data_that_cannot_be_a_tree( $raw, string $stored_as ): void {
		$this->seed( $raw );

		$result = $this->writer()->replace_section(
			self::PAGE_ID,
			array(
				'element_id' => 'sec0001',
				'element'    => self::section( 'sec0001', array() ),
			)
		);

		$this->assertRefused( $result, $stored_as, $raw );
	}

	/**
	 * The save's read-back verification reads the meta again. When the host
	 * vetoes the write, what it reads is the array that was already there.
	 */
	public function test_a_vetoed_write_over_array_stored_data_is_reported_not_a_crash(): void {
		$this->seed( self::tree() );
		$GLOBALS['mcpwp_test_meta_write_fails'] = array( '_elementor_data' );

		try {
			$result = $this->writer()->add_section( self::PAGE_ID, array( 'element' => self::section( 'new0001', array(), 'col0009' ) ) );
		} catch ( Throwable $e ) {
			$this->fail( 'add_section threw reading back array-stored data: ' . get_class( $e ) . ': ' . $e->getMessage() );
		}

		if ( ! is_wp_error( $result ) ) {
			$this->assertFalse( $result['debug']['meta_verified'], 'Three sections were submitted and two are stored: not verified.' );
			$this->assertSame( 2, $result['debug']['sections_saved'], 'The array that is still stored is read as the tree it is.' );
		}
		$this->assertSame( self::tree(), $this->stored(), 'The vetoed write left the stored tree alone.' );
	}

	// ---------------------------------------------------------------------
	// Paths that need ELEMENTOR_VERSION: separate processes (#757).
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
	public function test_edit_section_uses_array_stored_data_and_refuses_unusable_data(): void {
		$this->elementor_installed();

		foreach ( self::array_trees() as $label => list( $raw ) ) {
			$this->seed( $raw );

			$result = $this->writer()->edit_section( self::PAGE_ID, array( 'element_id' => 'sec0002', 'settings' => array( 'gap' => 'wide' ) ) );

			$this->assertNotInstanceOf( WP_Error::class, $result, $label );
			$tree = $this->stored_tree();
			$this->assertSame( array( 0, 1 ), array_keys( $tree ), $label );
			$this->assertSame( 'wide', $tree[1]['settings']['gap'], $label );
			$this->assertSame( 'wide', $result['element']['settings']['gap'], "{$label}: the saved element is read back." );
		}

		foreach ( self::unusable_meta() as $label => list( $raw, $stored_as ) ) {
			$this->seed( $raw );

			$this->assertRefused( $this->writer()->edit_section( self::PAGE_ID, array( 'element_id' => 'sec0001', 'settings' => array( 'gap' => 'wide' ) ) ), $stored_as, $raw, "{$label}:" );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_edit_widget_uses_array_stored_data_and_refuses_unusable_data(): void {
		$this->elementor_installed();

		foreach ( self::array_trees() as $label => list( $raw ) ) {
			$this->seed( $raw );

			$result = $this->writer()->edit_widget( self::PAGE_ID, 'tx00002', array( 'editor' => 'Edited' ) );

			$this->assertNotInstanceOf( WP_Error::class, $result, $label );
			$tree = $this->stored_tree();
			$this->assertSame( array( 0, 1 ), array_keys( $tree ), $label );
			$this->assertSame( 'Edited', $tree[1]['elements'][0]['elements'][0]['settings']['editor'], $label );
			$this->assertSame( 'Edited', $result['widget']['settings']['editor'], "{$label}: the saved widget is read back." );
		}

		foreach ( self::unusable_meta() as $label => list( $raw, $stored_as ) ) {
			$this->seed( $raw );

			$this->assertRefused( $this->writer()->edit_widget( self::PAGE_ID, 'hd00001', array( 'title' => 'x' ) ), $stored_as, $raw, "{$label}:" );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_elementor_returns_array_stored_data_and_reports_unusable_data_as_unreadable(): void {
		$this->elementor_installed();

		foreach ( self::array_trees() as $label => list( $raw ) ) {
			$this->seed( $raw );

			$result = $this->writer()->get_elementor_data( self::PAGE_ID, array( 'include_raw' => true ) );

			$this->assertIsArray( $result, $label );
			$this->assertTrue( $result['has_elementor'], $label );
			$this->assertSame( array_values( $raw ), $result['elementor_data'], "{$label}: the tree, as a list." );
			$this->assertIsString( $result['elementor_json'], "{$label}: the raw form is JSON text whatever the stored form." );
			$this->assertSame( array_values( $raw ), array_values( json_decode( $result['elementor_json'], true ) ), $label );
			$this->assertArrayNotHasKey( 'elementor_data_error', $result, $label );
		}

		foreach ( self::unusable_meta() as $label => list( $raw, $stored_as ) ) {
			$this->seed( $raw );

			$result = $this->writer()->get_elementor_data( self::PAGE_ID );

			$this->assertIsArray( $result, $label );
			$this->assertTrue( $result['has_elementor'], "{$label}: the page has Elementor data." );
			$this->assertNull( $result['elementor_data'], $label );
			$this->assertSame( 'invalid_elementor_data', $result['elementor_data_error']['code'] ?? null, "{$label}: unreadable is said, not left to look like an empty page." );
			$this->assertSame( $stored_as, $result['elementor_data_error']['stored_as'] ?? null, $label );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_elementor_summary_reads_array_stored_data_and_refuses_unusable_data(): void {
		$this->elementor_installed();

		foreach ( self::array_trees() as $label => list( $raw ) ) {
			$this->seed( $raw );

			$result = $this->writer()->get_elementor_summary( self::PAGE_ID );

			$this->assertIsArray( $result, $label );
			$this->assertTrue( $result['has_elementor'], $label );
			$this->assertSame( 2, $result['section_count'], $label );
			$this->assertSame( 2, $result['widget_count'], $label );
			$this->assertSame( array( 0, 1 ), array_column( $result['sections'], 'index' ), "{$label}: indexes are list positions." );
		}

		foreach ( self::unusable_meta() as $label => list( $raw, $stored_as ) ) {
			$this->seed( $raw );

			$result = $this->writer()->get_elementor_summary( self::PAGE_ID );

			$this->assertInstanceOf( WP_Error::class, $result, "{$label}: an unreadable page is not summarised as a page with no Elementor data." );
			$this->assertSame( 'invalid_elementor_data', $result->get_error_code(), $label );
			$this->assertSame( $stored_as, $result->get_error_data()['stored_as'] ?? null, $label );
		}

		$this->seed( '' );
		$empty = $this->writer()->get_elementor_summary( self::PAGE_ID );
		$this->assertIsArray( $empty );
		$this->assertFalse( $empty['has_elementor'], 'A page with no data is still answered as one.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rendered_content_reads_array_stored_data_and_marks_unusable_stats_unreadable(): void {
		$this->elementor_installed();
		require_once __DIR__ . '/fixtures/elementor-render-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$this->seed( self::tree() );

		$result = $this->writer()->get_rendered_content( self::PAGE_ID, 'text' );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['stats']['widgets'], 'Stats are counted from the array-stored tree.' );
		$this->assertStringContainsString( 'Kept', $result['text'], 'The fallback text is read from the array-stored tree.' );

		$this->seed( '[{"id":"sec0001","elType":' );

		$result = $this->writer()->get_rendered_content( self::PAGE_ID, 'text' );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['stats']['unreadable'] ?? false, 'Zero counts for data that could not be read would claim an empty page.' );
		$this->assertSame( 'string', $result['stats']['stored_as'] ?? null );
	}

	/**
	 * The conversion guard reads the stored tree through the shared reader
	 * too. ContentConversionGuardTest pins that corrupt text is an unavailable
	 * source, not "already an Elementor page". An array that is not a tree
	 * was waved through as one — is_array() and non-empty was enough — so the
	 * same unreadable page got opposite answers depending on its form. Every
	 * unusable form now gets the text form's answer.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_conversion_guard_treats_unusable_data_as_unreadable_in_every_form(): void {
		$this->elementor_installed();

		foreach ( self::unusable_meta() as $label => list( $raw ) ) {
			$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ]->post_content = '<!-- wp:paragraph --><p>Real block content.</p><!-- /wp:paragraph -->';
			$this->seed( $raw );

			$result = $this->writer()->set_elementor_data(
				self::PAGE_ID,
				array( 'elementor_data' => wp_json_encode( array( self::section( 'sec0009', array(), 'col0009' ) ) ) ),
				false
			);

			$this->assertInstanceOf( WP_Error::class, $result, "{$label}: unreadable data is not proof the page is already Elementor's." );
			$this->assertSame( 'would_convert_existing_content', $result->get_error_code(), $label );
			$this->assertSame( $raw, $this->stored(), "{$label}: nothing was written." );
		}

		// A readable array tree is proof, like readable text.
		$this->seed( self::tree() );
		$this->assertNotInstanceOf(
			WP_Error::class,
			$this->writer()->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => wp_json_encode( array( self::section( 'sec0009', array(), 'col0009' ) ) ) ), false )
		);
	}
}
