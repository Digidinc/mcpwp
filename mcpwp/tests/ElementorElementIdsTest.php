<?php
/**
 * Regression coverage for #842: element ids that are not exactly 7
 * alphanumeric characters silently break Elementor Pro's AJAX widgets.
 *
 * Elementor Pro validates `widget_id` on its REST endpoints with
 * Refresh_Base::is_widget_id_valid() (core/data/endpoints/refresh-base.php,
 * read on the rig's Pro 4.1.1):
 *
 *   return preg_match( '/^[a-zA-Z0-9]+$/', $widget_id ) && strlen( $widget_id ) === 7;
 *
 * Two endpoints use it: `refresh-search` (the Search widget's live results,
 * widget_id = the search widget's own id) and `refresh-loop` (the Taxonomy
 * Filter, widget_id = the Loop Grid its `selected_element` points at). Any
 * other id returns 400 rest_invalid_param and the front end does nothing.
 *
 * Reported on staging15 (MCPWP 3.11.0, Pro 4.2.3): 8- and 10-character ids
 * saved through wp_set_elementor / wp_edit_widget with zero warnings, rendered,
 * reported render_verified:true, and the search panel stayed empty. The plugin
 * generated 8-character ids itself and its instructions told agents to use 8.
 *
 * What this pins:
 *   - every id the plugin generates is 7 lowercase alphanumerics;
 *   - a non-conforming id warns (path + id) and is NOT rewritten by default —
 *     Elementor core stores 8-character ids that render, and ids are referenced
 *     from CSS (.elementor-element-<id>) and from other widgets' settings;
 *   - on the widgets whose AJAX depends on it, the warning says so plainly;
 *   - `repair_ids` rewrites them, repoints `selected_element`, and reports the
 *     mapping — and says so explicitly when there was nothing to rewrite.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorElementIdsTest extends TestCase {

	private const PAGE_ID = 8420;

	private const SEVEN = '/^[a-z0-9]{7}$/';

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
					'post_title'  => 'Element id probe',
					'post_name'   => 'element-id-probe',
					'post_status' => 'draft',
				)
			),
		);
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_meta']  = $this->meta_before;
		$GLOBALS['_mcpwp_test_posts'] = $this->posts_before;
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	private function writer(): Mcpwp_Elementor_Basic {
		return ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Run the validator over a tree.
	 *
	 * @return array{result:array,tree:array}
	 */
	private function validate( array $tree, array $options = array() ): array {
		$json   = wp_json_encode( $tree );
		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'validate_and_fix_elements' );
		$method->setAccessible( true );

		$result = $method->invokeArgs( $this->writer(), array( &$json, $options ) );

		return array(
			'result' => $result,
			'tree'   => json_decode( $json, true ),
		);
	}

	private function widget( string $id, string $type, array $settings = array() ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	private function section( string $id, array $widgets, string $column_id = 'col0001' ): array {
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

	/**
	 * The staging15 shape: a Loop Grid with a 10-character id, a Taxonomy
	 * Filter pointing at it, and a Search widget with an 8-character id.
	 * The heading's CSS anchor happens to equal the loop grid's old id — it is
	 * not a reference Elementor resolves, so repair must not rewrite it.
	 */
	private function staging_tree(): array {
		return array(
			$this->section(
				'sec00001',
				array(
					$this->widget( 'indstreams', 'loop-grid', array( 'posts_per_page' => 6 ) ),
					$this->widget( 'tf00001', 'taxonomy-filter', array( 'selected_element' => 'indstreams', 'taxonomy' => 'category' ) ),
					$this->widget( 'c0a00023', 'search', array( 'live_results' => 'yes' ) ),
					$this->widget( 'hd00001', 'heading', array( 'title' => 'Streams', '_element_id' => 'indstreams' ) ),
				)
			),
		);
	}

	/** Flatten every id in a tree, in document order. */
	private function ids( array $elements ): array {
		$out = array();
		foreach ( $elements as $el ) {
			$out[] = $el['id'];
			if ( ! empty( $el['elements'] ) ) {
				$out = array_merge( $out, $this->ids( $el['elements'] ) );
			}
		}
		return $out;
	}

	private function warnings_text( array $result ): string {
		return implode( "\n", $result['warnings'] );
	}

	// ---------------------------------------------------------------------
	// Generation.
	// ---------------------------------------------------------------------

	public function test_a_missing_id_is_generated_as_seven_lowercase_alphanumerics(): void {
		$widgets = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$widgets[] = array(
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => 'No id ' . $i ),
			);
		}

		$tree = array(
			array(
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => $widgets,
					),
				),
			),
		);

		$out = $this->validate( $tree );
		$ids = $this->ids( $out['tree'] );

		$this->assertCount( 27, $ids );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( self::SEVEN, $id, 'Elementor Pro rejects any generated id that is not exactly 7 alphanumerics.' );
		}
		$this->assertCount( 27, array_unique( $ids ), 'Generated ids must stay unique within the tree.' );
	}

	public function test_a_duplicate_id_is_reassigned_to_a_seven_character_id(): void {
		$tree = array(
			$this->section(
				'sec0001',
				array(
					$this->widget( 'dup0001', 'heading', array( 'title' => 'A' ) ),
					$this->widget( 'dup0001', 'heading', array( 'title' => 'B' ) ),
				)
			),
		);

		$out = $this->validate( $tree );
		$ids = $this->ids( $out['tree'] );

		$this->assertSame( 'dup0001', $ids[2], 'The first holder of an id keeps it.' );
		$this->assertMatchesRegularExpression( self::SEVEN, $ids[3] );
		$this->assertNotSame( 'dup0001', $ids[3] );
	}

	/**
	 * The two Pro builders carried their own 8-character generators.
	 *
	 * @dataProvider pro_generators
	 */
	public function test_the_pro_builders_generate_seven_character_ids( string $file, string $class, string $method ): void {
		require_once dirname( __DIR__ ) . '/includes/pro/core/' . $file;

		$instance = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
		$ref      = new ReflectionMethod( $class, $method );
		$ref->setAccessible( true );

		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertMatchesRegularExpression( self::SEVEN, $ref->invoke( $instance ), "{$class}::{$method}()" );
		}
	}

	public static function pro_generators(): array {
		return array(
			'page builder'  => array( 'class-mcpwp-page-builder.php', 'Mcpwp_Page_Builder', 'id' ),
			'elementor pro' => array( 'class-mcpwp-elementor-pro.php', 'Mcpwp_Elementor_Pro', 'generate_element_id' ),
		);
	}

	// ---------------------------------------------------------------------
	// Warning without rewriting.
	// ---------------------------------------------------------------------

	public function test_a_non_conforming_id_warns_with_its_path_and_is_left_unchanged(): void {
		$tree = array(
			$this->section(
				'sec00001',
				array( $this->widget( 'hd1', 'heading', array( 'title' => 'Short id' ) ) ),
				'col0001'
			),
		);

		$out  = $this->validate( $tree );
		$text = $this->warnings_text( $out['result'] );

		$this->assertStringContainsString( "section[0]: element id 'sec00001'", $text );
		$this->assertStringContainsString( "section[0].column[0].widget[0]: element id 'hd1'", $text );
		$this->assertStringNotContainsString( "'col0001'", $text, 'A conforming id must not warn.' );

		$this->assertSame( array( 'sec00001', 'col0001', 'hd1' ), $this->ids( $out['tree'] ), 'Ids are referenced from CSS and other widgets; the default path must never rewrite them.' );
		$this->assertStringNotContainsString( 'sec00001', implode( "\n", $out['result']['fixes'] ) );
		$this->assertArrayNotHasKey( 'id_repair', $out['result'], 'No repair was asked for, so none is reported.' );
	}

	public function test_seven_characters_that_are_not_alphanumeric_also_warn(): void {
		$tree = array( $this->section( 'sec0001', array( $this->widget( 'ab-cd_e', 'heading', array( 'title' => 'x' ) ) ) ) );

		$text = $this->warnings_text( $this->validate( $tree )['result'] );

		$this->assertStringContainsString( "element id 'ab-cd_e'", $text );
	}

	/**
	 * @dataProvider rest_backed_widgets
	 */
	public function test_a_rest_backed_widget_warning_says_its_ajax_features_will_fail( string $widget_type, string $endpoint ): void {
		$tree = array( $this->section( 'sec0001', array( $this->widget( 'wid00001', $widget_type ) ) ) );

		$warnings = $this->validate( $tree )['result']['warnings'];
		$line     = '';
		foreach ( $warnings as $warning ) {
			if ( 0 === strpos( $warning, "section[0].column[0].widget[0]: element id 'wid00001'" ) ) {
				$line = $warning;
			}
		}

		$this->assertNotSame( '', $line, 'The widget must get its own warning line.' );
		$this->assertStringContainsString( $widget_type, $line );
		$this->assertStringContainsString( $endpoint, $line );
		$this->assertStringContainsString( 'will fail', $line );
		$this->assertStringContainsString( 'repair_ids', $line, 'The warning must name the way out.' );
	}

	public static function rest_backed_widgets(): array {
		return array(
			'search live results'      => array( 'search', 'refresh-search' ),
			'loop grid behind filters' => array( 'loop-grid', 'refresh-loop' ),
		);
	}

	public function test_a_taxonomy_filter_pointing_at_a_non_conforming_loop_grid_is_flagged(): void {
		$text = $this->warnings_text( $this->validate( $this->staging_tree() )['result'] );

		$this->assertStringContainsString( 'section[0].column[0].widget[1]', $text );
		$this->assertMatchesRegularExpression( "/widget\\[1\\][^\n]*selected_element[^\n]*'indstreams'[^\n]*will fail/", $text );
	}

	// ---------------------------------------------------------------------
	// Opt-in repair.
	// ---------------------------------------------------------------------

	public function test_repair_rewrites_non_conforming_ids_and_repoints_selected_element(): void {
		$out    = $this->validate( $this->staging_tree(), array( 'repair_ids' => true ) );
		$result = $out['result'];
		$tree   = $out['tree'];

		$this->assertArrayHasKey( 'id_repair', $result );
		$repair = $result['id_repair'];

		$this->assertTrue( $repair['requested'] );
		$this->assertSame( array( 'sec00001', 'indstreams', 'c0a00023' ), array_keys( $repair['mapping'] ) );

		$ids = $this->ids( $tree );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( self::SEVEN, strtolower( $id ) );
		}
		$this->assertCount( count( $ids ), array_unique( $ids ) );

		$widgets = $tree[0]['elements'][0]['elements'];
		$this->assertSame( 'col0001', $tree[0]['elements'][0]['id'], 'Conforming ids are left alone.' );
		$this->assertSame( 'tf00001', $widgets[1]['id'] );
		$this->assertSame( $repair['mapping']['indstreams'], $widgets[0]['id'] );
		$this->assertSame( $repair['mapping']['c0a00023'], $widgets[2]['id'] );

		// The reference Elementor actually resolves follows the rename.
		$this->assertSame( $widgets[0]['id'], $widgets[1]['settings']['selected_element'] );
		$this->assertSame(
			array(
				array(
					'path' => 'section[0].column[0].widget[1]',
					'key'  => 'selected_element',
					'from' => 'indstreams',
					'to'   => $widgets[0]['id'],
				),
			),
			$repair['references_repointed']
		);

		// A value that merely equals an old id is not a reference: reported, not rewritten.
		$this->assertSame( 'indstreams', $widgets[3]['settings']['_element_id'] );
		$this->assertContains(
			array(
				'path' => 'section[0].column[0].widget[3]',
				'key'  => '_element_id',
				'id'   => 'indstreams',
			),
			$repair['possible_references']
		);

		$this->assertStringContainsString( 'CSS', $repair['caution'] );
		$this->assertStringContainsString( "rewrote id 'c0a00023' -> '{$widgets[2]['id']}'", implode( "\n", $result['fixes'] ) );
		$this->assertStringNotContainsString( "element id 'c0a00023'", $this->warnings_text( $result ), 'A repaired id no longer warrants a warning.' );
	}

	public function test_repair_requested_on_a_conforming_tree_says_so_rather_than_returning_nothing(): void {
		$tree = array( $this->section( 'sec0001', array( $this->widget( 'srch001', 'search' ) ) ) );

		$out = $this->validate( $tree, array( 'repair_ids' => true ) );

		$this->assertArrayHasKey( 'id_repair', $out['result'], 'Asked and nothing to do must not look like never asked.' );
		$this->assertTrue( $out['result']['id_repair']['requested'] );
		$this->assertSame( array(), $out['result']['id_repair']['mapping'] );
		$this->assertStringContainsString( '3', $out['result']['id_repair']['message'], 'The message states how many ids were checked.' );
		$this->assertSame( array( 'sec0001', 'col0001', 'srch001' ), $this->ids( $out['tree'] ) );
	}

	public function test_patch_elementor_repairs_the_stored_page_without_operations(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( $this->staging_tree() );

		$writer = $this->writer();

		// Unchanged contract: an empty patch is still an error unless a repair is asked for.
		$this->assertInstanceOf( WP_Error::class, $writer->patch_elementor( self::PAGE_ID, array( 'operations' => array() ) ) );

		$result = $writer->patch_elementor( self::PAGE_ID, array( 'repair_ids' => true ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( 'id_repair', $result );
		$this->assertCount( 3, $result['id_repair']['mapping'] );

		$stored = json_decode( stripslashes( (string) $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ), true );
		$this->assertIsArray( $stored );
		$widgets = $stored[0]['elements'][0]['elements'];
		$this->assertSame( $result['id_repair']['mapping']['indstreams'], $widgets[0]['id'] );
		$this->assertSame( $widgets[0]['id'], $widgets[1]['settings']['selected_element'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_elementor_dry_run_previews_the_repair_without_writing(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$result = $this->writer()->set_elementor_data(
			self::PAGE_ID,
			array(
				'elementor_data' => wp_json_encode( $this->staging_tree() ),
				'repair_ids'     => true,
			),
			true
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result['dry_run'] );
		$this->assertArrayHasKey( 'id_repair', $result );
		$this->assertCount( 3, $result['id_repair']['mapping'] );
		$this->assertArrayNotHasKey( '_elementor_data', $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ] ?? array() );
	}

	// ---------------------------------------------------------------------
	// Contract surface.
	// ---------------------------------------------------------------------

	public function test_the_write_tools_advertise_the_repair(): void {
		$defs = array();
		foreach ( ( new Mcpwp_MCP_Free_Tools() )->get_tools() as $tool ) {
			$defs[ $tool['name'] ] = $tool;
		}

		$patch = $defs['wp_patch_elementor']['inputSchema'];
		$this->assertArrayHasKey( 'repair_ids', $patch['properties'] );
		$this->assertNotContains( 'operations', $patch['required'] ?? array(), 'A repair-only patch carries no operations.' );

		$this->assertArrayHasKey( 'repair_ids', $defs['wp_set_elementor']['inputSchema']['properties'] );
	}

	public function test_no_shipped_string_still_tells_agents_to_use_eight_character_ids(): void {
		$patterns = array(
			'/\b8[- ]char(acter)?s? alphanumeric/i',
			'/\b8 alphanumeric/i',
			'/\b8-char (Elementor )?(element )?id\b/i',
			'/\(8[- ]char\b/i',
			'/random 8-character element id/i',
		);

		$root  = dirname( __DIR__ ) . '/includes';
		$found = array();
		$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			foreach ( file( $file->getPathname() ) as $n => $line ) {
				foreach ( $patterns as $pattern ) {
					if ( preg_match( $pattern, $line ) ) {
						$found[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . ( $n + 1 );
					}
				}
			}
		}

		$this->assertSame( array(), $found, 'Elementor Pro accepts only 7-character ids; an agent following these strings builds broken widgets.' );
	}
}
