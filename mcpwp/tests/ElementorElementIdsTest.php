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
	//
	// Per-element lines only where the id feeds a live AJAX feature; every
	// other non-conforming id is folded into one summary line per write, so
	// a legacy page does not bury its real warnings under one line per
	// element.
	// ---------------------------------------------------------------------

	/** Id-related warnings only (the other validation passes also warn). */
	private function id_warnings( array $result ): array {
		return array_values(
			array_filter(
				$result['warnings'],
				function ( $warning ) {
					return false !== strpos( $warning, 'alphanumeric' );
				}
			)
		);
	}

	/** The summary line, or '' when there is none. */
	private function summary( array $id_warnings ): string {
		foreach ( $id_warnings as $warning ) {
			if ( preg_match( '/^\d+ element id/', $warning ) ) {
				return $warning;
			}
		}
		return '';
	}

	/**
	 * A page built by an earlier MCPWP: 150 elements, all 8-character ids.
	 * Two depend on their id — a Search widget and the Loop Grid a Taxonomy
	 * Filter targets. The filter's own id does not reach any endpoint.
	 */
	private function legacy_page(): array {
		$widgets = array();
		for ( $i = 0; $i < 145; $i++ ) {
			$widgets[] = $this->widget( sprintf( 'h%07d', $i ), 'heading', array( 'title' => 'Row ' . $i ) );
		}
		$widgets[] = $this->widget( 'srch0001', 'search', array( 'live_results' => 'yes' ) );
		$widgets[] = $this->widget( 'loop0001', 'loop-grid' );
		$widgets[] = $this->widget( 'filt0001', 'taxonomy-filter', array( 'selected_element' => 'loop0001' ) );

		return array( $this->section( 'legacy01', $widgets, 'legacyc1' ) );
	}

	public function test_ids_no_ajax_feature_depends_on_fold_into_one_summary_and_are_left_unchanged(): void {
		$tree = array(
			$this->section(
				'sec00001',
				array( $this->widget( 'hd1', 'heading', array( 'title' => 'Short id' ) ) ),
				'col0001'
			),
		);

		$out  = $this->validate( $tree );
		$ids  = $this->id_warnings( $out['result'] );

		$this->assertCount( 1, $ids, 'No AJAX feature depends on these ids, so they get one summary line, not one line each.' );
		$this->assertStringStartsWith( '2 element id', $ids[0] );
		$this->assertStringContainsString( "section[0] 'sec00001'", $ids[0] );
		$this->assertStringContainsString( "section[0].column[0].widget[0] 'hd1'", $ids[0] );
		$this->assertStringNotContainsString( "'col0001'", $ids[0], 'A conforming id is not part of the sample.' );
		$this->assertStringContainsString( 'render fine', $ids[0] );
		$this->assertStringContainsString( 'repair_ids', $ids[0] );
		$this->assertStringContainsString( 'CSS', $ids[0] );

		$this->assertSame( array( 'sec00001', 'col0001', 'hd1' ), $this->ids( $out['tree'] ), 'Ids are referenced from CSS and other widgets; the default path must never rewrite them.' );
		$this->assertStringNotContainsString( 'sec00001', implode( "\n", $out['result']['fixes'] ) );
		$this->assertArrayNotHasKey( 'id_repair', $out['result'], 'No repair was asked for, so none is reported.' );
	}

	public function test_seven_characters_that_are_not_alphanumeric_also_count(): void {
		$tree = array( $this->section( 'sec0001', array( $this->widget( 'ab-cd_e', 'heading', array( 'title' => 'x' ) ) ) ) );

		$ids = $this->id_warnings( $this->validate( $tree )['result'] );

		$this->assertCount( 1, $ids );
		$this->assertStringStartsWith( '1 element id', $ids[0] );
		$this->assertStringContainsString( "'ab-cd_e'", $ids[0] );
	}

	public function test_a_search_widget_gets_its_own_line_saying_live_results_will_fail(): void {
		$tree = array( $this->section( 'sec0001', array( $this->widget( 'wid00001', 'search' ) ) ) );

		$ids = $this->id_warnings( $this->validate( $tree )['result'] );

		$this->assertCount( 1, $ids, 'The only bad id is the search widget\'s; there is nothing left to summarise.' );
		$this->assertStringStartsWith( "section[0].column[0].widget[0]: element id 'wid00001'", $ids[0] );
		$this->assertStringContainsString( 'search', $ids[0] );
		$this->assertStringContainsString( 'refresh-search', $ids[0] );
		$this->assertStringContainsString( 'will fail', $ids[0] );
		$this->assertStringContainsString( 'repair_ids', $ids[0], 'The warning must name the way out.' );
	}

	public function test_a_loop_grid_a_taxonomy_filter_targets_gets_its_own_line_naming_the_filter(): void {
		$ids  = $this->id_warnings( $this->validate( $this->staging_tree() )['result'] );
		$line = '';
		foreach ( $ids as $warning ) {
			if ( 0 === strpos( $warning, "section[0].column[0].widget[0]: element id 'indstreams'" ) ) {
				$line = $warning;
			}
		}

		$this->assertNotSame( '', $line, 'The loop grid behind a filter must get its own line.' );
		$this->assertStringContainsString( 'loop-grid', $line );
		$this->assertStringContainsString( 'selected_element', $line );
		$this->assertStringContainsString( 'section[0].column[0].widget[1]', $line, 'Name the filter that will stop working.' );
		$this->assertStringContainsString( 'refresh-loop', $line );
		$this->assertStringContainsString( 'will fail', $line );
	}

	public function test_a_loop_grid_no_filter_targets_is_folded_into_the_summary(): void {
		$tree = array( $this->section( 'sec0001', array( $this->widget( 'lg000001', 'loop-grid' ) ) ) );

		$ids = $this->id_warnings( $this->validate( $tree )['result'] );

		$this->assertCount( 1, $ids );
		$this->assertStringStartsWith( '1 element id', $ids[0] );
		$this->assertStringContainsString( "'lg000001'", $ids[0] );
	}

	public function test_a_taxonomy_filter_pointing_at_a_bad_id_that_is_not_on_the_page_is_flagged_on_the_filter(): void {
		$tree = array(
			$this->section(
				'sec0001',
				array( $this->widget( 'tf00001', 'taxonomy-filter', array( 'selected_element' => 'gone0001' ) ) )
			),
		);

		$ids = $this->id_warnings( $this->validate( $tree )['result'] );

		$this->assertCount( 1, $ids );
		$this->assertStringStartsWith( 'section[0].column[0].widget[0]:', $ids[0] );
		$this->assertStringContainsString( "'gone0001'", $ids[0] );
		$this->assertStringContainsString( 'not found in this data', $ids[0] );
		$this->assertStringContainsString( 'will fail', $ids[0] );
	}

	public function test_a_150_element_legacy_page_yields_one_summary_plus_lines_only_for_ajax_dependent_ids(): void {
		$ids = $this->id_warnings( $this->validate( $this->legacy_page() )['result'] );

		$this->assertCount( 3, $ids, 'One summary for the 148 ids nothing depends on, one line each for the search widget and the filtered loop grid.' );

		$summary = $this->summary( $ids );
		$this->assertStringStartsWith( '148 element id', $summary );
		foreach ( array( "section[0] 'legacy01'", "section[0].column[0] 'legacyc1'", "section[0].column[0].widget[0] 'h0000000'", "section[0].column[0].widget[1] 'h0000001'", "section[0].column[0].widget[2] 'h0000002'" ) as $sample ) {
			$this->assertStringContainsString( $sample, $summary );
		}
		$this->assertStringNotContainsString( "'h0000003'", $summary, 'The sample is the first five, not the whole list.' );
		$this->assertStringContainsString( '143 more', $summary );
		$this->assertStringNotContainsString( "'srch0001'", $summary, 'AJAX-dependent ids have their own lines and are not double-counted.' );
		$this->assertStringNotContainsString( "'loop0001'", $summary );

		$text = implode( "\n", $ids );
		$this->assertStringContainsString( "section[0].column[0].widget[145]: element id 'srch0001'", $text );
		$this->assertStringContainsString( "section[0].column[0].widget[146]: element id 'loop0001'", $text );
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
		$this->assertSame( array( 'sec00001', 'indstreams', 'c0a00023' ), array_keys( (array) $repair['mapping'] ) );

		$ids = $this->ids( $tree );
		foreach ( $ids as $id ) {
			$this->assertMatchesRegularExpression( self::SEVEN, strtolower( $id ) );
		}
		$this->assertCount( count( $ids ), array_unique( $ids ) );

		$widgets = $tree[0]['elements'][0]['elements'];
		$this->assertSame( 'col0001', $tree[0]['elements'][0]['id'], 'Conforming ids are left alone.' );
		$this->assertSame( 'tf00001', $widgets[1]['id'] );
		$this->assertSame( ( (array) $repair['mapping'] )['indstreams'], $widgets[0]['id'] );
		$this->assertSame( ( (array) $repair['mapping'] )['c0a00023'], $widgets[2]['id'] );

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
		$this->assertSame( '{}', wp_json_encode( $out['result']['id_repair']['mapping'] ), 'The mapping is always a JSON object, empty or not, so typed clients get one shape.' );
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
		$this->assertCount( 3, (array) $result['id_repair']['mapping'] );

		$stored = json_decode( stripslashes( (string) $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ), true );
		$this->assertIsArray( $stored );
		$widgets = $stored[0]['elements'][0]['elements'];
		$this->assertSame( ( (array) $result['id_repair']['mapping'] )['indstreams'], $widgets[0]['id'] );
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
		$this->assertCount( 3, (array) $result['id_repair']['mapping'] );
		$this->assertArrayNotHasKey( '_elementor_data', $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ] ?? array() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_elementor_dry_run_folds_the_same_way(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$result = $this->writer()->set_elementor_data(
			self::PAGE_ID,
			array( 'elementor_data' => wp_json_encode( $this->legacy_page() ) ),
			true
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$ids = $this->id_warnings( $result );
		$this->assertCount( 3, $ids );
		$this->assertStringStartsWith( '148 element id', $this->summary( $ids ) );
	}

	// ---------------------------------------------------------------------
	// Review r1: references stored in encoded forms (P2-1).
	//
	// Elementor Pro's Off-canvas trigger does not store its target in a plain
	// setting. The dynamic tag is `[elementor-tag ... name="off-canvas"
	// settings="<urlencode(json)>"]` (core Dynamic_Tags Manager::tag_to_text)
	// and a hand-made or rendered link is `#elementor-action:action=off_canvas:
	// open&settings=<base64(json)>`, rawurlencoded by Frontend::
	// create_action_hash. The id sits between %22 and %22, where a plain token
	// search cannot see it, so a repair broke the trigger and said nothing.
	// ---------------------------------------------------------------------

	private function off_canvas_tag( string $target ): string {
		return '[elementor-tag id="a1b2c3d" name="off-canvas" settings="' . urlencode( wp_json_encode( array( 'action' => 'open', 'off_canvas' => $target ), JSON_FORCE_OBJECT ) ) . '"]';
	}

	private function tag_settings( string $tag ): array {
		preg_match( '/settings="(.*?(?="]))/', $tag, $m );
		return json_decode( urldecode( $m[1] ), true );
	}

	private function action_hash( string $target ): string {
		return '#' . rawurlencode( sprintf( 'elementor-action:action=%1$s&settings=%2$s', 'off_canvas:open', base64_encode( wp_json_encode( array( 'id' => $target, 'displayMode' => 'open' ) ) ) ) );
	}

	private function hash_settings( string $hash ): array {
		preg_match( '/&settings=(.*)$/', rawurldecode( substr( $hash, 1 ) ), $m );
		return json_decode( base64_decode( $m[1] ), true );
	}

	/**
	 * Two Off-canvas widgets whose ids share a prefix, a button opening the
	 * first through the dynamic tag, a button opening the second through an
	 * action link, and HTML that mentions the first in a form nothing resolves.
	 */
	private function off_canvas_tree(): array {
		return array(
			$this->section(
				'sec0001',
				array(
					$this->widget( 'ocanvas1', 'off-canvas' ),
					$this->widget( 'ocanvas12', 'off-canvas' ),
					$this->widget( 'btn0001', 'button', array( 'text' => 'Menu', 'link' => array( 'url' => '' ), '__dynamic__' => array( 'link' => $this->off_canvas_tag( 'ocanvas1' ) ) ) ),
					$this->widget( 'btn0002', 'button', array( 'text' => 'More', 'link' => array( 'url' => $this->action_hash( 'ocanvas12' ), 'is_external' => '' ) ) ),
					$this->widget( 'htm0001', 'html', array( 'html' => '<a data-x="%22ocanvas1%22">x</a> ocanvas1x' ) ),
				)
			),
		);
	}

	private function repair_off_canvas(): array {
		$out = $this->validate( $this->off_canvas_tree(), array( 'repair_ids' => true, 'id_seed' => (string) self::PAGE_ID ) );

		return array(
			'repair'  => $out['result']['id_repair'],
			'map'     => (array) $out['result']['id_repair']['mapping'],
			'widgets' => $out['tree'][0]['elements'][0]['elements'],
		);
	}

	public function test_repair_repoints_an_off_canvas_dynamic_tag_and_re_encodes_it_as_elementor_does(): void {
		$r   = $this->repair_off_canvas();
		$tag = $r['widgets'][2]['settings']['__dynamic__']['link'];

		$this->assertSame( $r['map']['ocanvas1'], $this->tag_settings( $tag )['off_canvas'], 'The menu button must still open its Off-canvas after the repair.' );
		$this->assertSame( $this->off_canvas_tag( $r['map']['ocanvas1'] ), $tag, 'Same tag id, same name, settings re-encoded exactly as Elementor encodes them.' );
		$this->assertContains(
			array(
				'path' => 'section[0].column[0].widget[2]',
				'key'  => '__dynamic__.link',
				'from' => 'ocanvas1',
				'to'   => $r['map']['ocanvas1'],
			),
			$r['repair']['references_repointed']
		);
	}

	public function test_repair_repoints_an_off_canvas_action_link_matching_the_exact_id(): void {
		$r   = $this->repair_off_canvas();
		$url = $r['widgets'][3]['settings']['link']['url'];

		$this->assertSame( $r['map']['ocanvas12'], $this->hash_settings( $url )['id'], "'ocanvas12' is its own id, not 'ocanvas1' plus a character." );
		$this->assertSame( $this->action_hash( $r['map']['ocanvas12'] ), $url, 'Re-encoded in the same rawurlencoded form.' );
		$this->assertContains(
			array(
				'path' => 'section[0].column[0].widget[3]',
				'key'  => 'link.url',
				'from' => 'ocanvas12',
				'to'   => $r['map']['ocanvas12'],
			),
			$r['repair']['references_repointed']
		);
	}

	public function test_repair_reports_an_encoded_mention_it_cannot_resolve_and_leaves_it_alone(): void {
		$r = $this->repair_off_canvas();

		$this->assertSame( '<a data-x="%22ocanvas1%22">x</a> ocanvas1x', $r['widgets'][4]['settings']['html'], 'An unrecognised form is reported, never rewritten.' );
		$this->assertContains(
			array(
				'path' => 'section[0].column[0].widget[4]',
				'key'  => 'html',
				'id'   => 'ocanvas1',
			),
			$r['repair']['possible_references'],
			'%22ocanvas1%22 is the id once URL-decoded.'
		);
		foreach ( $r['repair']['possible_references'] as $row ) {
			$this->assertNotSame( 'section[0].column[0].widget[2]', $row['path'], 'A repointed reference is not also a possible one.' );
			$this->assertNotSame( 'section[0].column[0].widget[3]', $row['path'] );
		}
	}

	public function test_the_repair_caution_says_what_was_and_was_not_searched(): void {
		$caution = $this->repair_off_canvas()['repair']['caution'];

		foreach ( array( 'dynamic tag', 'elementor-action', 'URL-encoded', 'kit CSS', 'Custom Code', 'cache' ) as $phrase ) {
			$this->assertStringContainsString( $phrase, $caution );
		}
	}

	// ---------------------------------------------------------------------
	// Review r1: a dry-run mapping is the mapping the write applies (P2-2).
	// ---------------------------------------------------------------------

	public function test_the_same_tree_on_the_same_page_always_repairs_to_the_same_ids(): void {
		$options = array( 'repair_ids' => true, 'id_seed' => (string) self::PAGE_ID );

		$first  = (array) $this->validate( $this->staging_tree(), $options )['result']['id_repair']['mapping'];
		$second = (array) $this->validate( $this->staging_tree(), $options )['result']['id_repair']['mapping'];

		$this->assertCount( 3, $first );
		$this->assertSame( $first, $second, 'A mapping that changes between calls cannot be previewed, and CSS updated from the preview names ids that never exist.' );
	}

	public function test_ids_generated_for_missing_ids_are_stable_for_the_same_page(): void {
		$tree = array(
			array(
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array( array( 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'x' ) ) ),
					),
				),
			),
		);
		$options = array( 'id_seed' => (string) self::PAGE_ID );

		$this->assertSame( $this->ids( $this->validate( $tree, $options )['tree'] ), $this->ids( $this->validate( $tree, $options )['tree'] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_dry_run_mapping_is_exactly_the_mapping_the_write_applies(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$dry = $this->writer()->set_elementor_data(
			self::PAGE_ID,
			array(
				'elementor_data' => wp_json_encode( $this->staging_tree() ),
				'repair_ids'     => true,
			),
			true
		);

		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( $this->staging_tree() );
		$real = $this->writer()->patch_elementor( self::PAGE_ID, array( 'repair_ids' => true ) );

		$this->assertNotInstanceOf( WP_Error::class, $dry );
		$this->assertNotInstanceOf( WP_Error::class, $real );
		$this->assertSame( (array) $dry['id_repair']['mapping'], (array) $real['id_repair']['mapping'] );

		$stored = json_decode( stripslashes( (string) $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ), true );
		$this->assertSame( ( (array) $dry['id_repair']['mapping'] )['c0a00023'], $stored[0]['elements'][0]['elements'][2]['id'] );
	}

	// ---------------------------------------------------------------------
	// Review r1: P3s.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider unusable_meta
	 *
	 * @param mixed  $raw     Stored meta.
	 * @param string $message What the refusal must say about the stored form.
	 */
	public function test_patch_operations_refuse_stored_data_that_is_not_an_element_list( $raw, string $message ): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = $raw;

		$result = $this->writer()->patch_elementor(
			self::PAGE_ID,
			array( 'operations' => array( array( 'op' => 'add', 'element' => $this->section( 'new0001', array() ) ) ) )
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Treating unreadable data as an empty page overwrote the whole page with the added section.' );
		$this->assertSame( 'invalid_elementor_data', $result->get_error_code() );
		$this->assertStringContainsString( $message, $result->get_error_message(), 'The refusal says which form the stored data is in and why it cannot be used.' );
		$this->assertSame( $raw, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] );
	}

	public static function unusable_meta(): array {
		return array(
			'corrupt json string'         => array( '[{"id":"sec0001","elType":', 'stored as text that does not decode to an element list' ),
			'json string that is no list' => array( '"just a string"', 'stored as JSON text that decodes to string' ),
			'array that is no list'       => array( array( 'title' => 'not an element' ), 'stored as an array that is not a list of elements' ),
		);
	}

	/**
	 * Imports and some plugins store `_elementor_data` as an array rather than
	 * JSON text, and Elementor renders it. Refusing it (75ce90d) broke pages
	 * that are fine; it is the decoded tree, and the write stores it back as
	 * JSON text like every other write.
	 */
	public function test_patch_operations_use_array_stored_data_as_the_tree_and_round_trip_it(): void {
		$original = array( $this->section( 'sec0001', array( $this->widget( 'hd00001', 'heading', array( 'title' => 'Kept' ) ) ) ) );
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = $original;

		$result = $this->writer()->patch_elementor(
			self::PAGE_ID,
			array( 'operations' => array( array( 'op' => 'add', 'element' => $this->section( 'new0001', array() ) ) ) )
		);

		$this->assertNotInstanceOf( WP_Error::class, $result, 'Array-stored data is a valid tree.' );

		$stored = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];
		$this->assertIsString( $stored, 'Written back as JSON text, the format every other write uses.' );

		$tree = json_decode( stripslashes( $stored ), true );
		$this->assertCount( 2, $tree, 'The existing section survives; the added one follows it.' );
		$this->assertSame( $original[0], $tree[0] );
		$this->assertSame( 'new0001', $tree[1]['id'] );
	}

	public function test_a_repair_only_patch_works_on_array_stored_data(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = $this->staging_tree();

		$result = $this->writer()->patch_elementor( self::PAGE_ID, array( 'repair_ids' => true ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertCount( 3, (array) $result['id_repair']['mapping'] );
	}

	public function test_patch_reports_the_repaired_id_of_an_element_it_added(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( array( $this->section( 'sec0001', array() ) ) );

		$result = $this->writer()->patch_elementor(
			self::PAGE_ID,
			array(
				'repair_ids' => true,
				'operations' => array( array( 'op' => 'add', 'element' => $this->section( 'newsec01', array() ) ) ),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$map = (array) $result['id_repair']['mapping'];
		$this->assertSame( $map['newsec01'], $result['operations'][0]['element_id'], 'A follow-up edit using the reported id must find the element.' );
		$this->assertSame( 'newsec01', $result['operations'][0]['element_id_submitted'] );
	}

	// ---------------------------------------------------------------------
	// Review r2.
	// ---------------------------------------------------------------------

	/**
	 * N2: a tree with numeric keys that are not 0..n — any PHP tool that
	 * unset()s an element and re-encodes writes one — renders in Elementor and
	 * patched on main. It is normalised to a list, not refused.
	 */
	public function test_patch_normalises_a_numerically_keyed_tree_to_a_list(): void {
		$sections = array(
			0 => $this->section( 'sec0001', array() ),
			2 => $this->section( 'sec0003', array(), 'col0003' ),
		);

		foreach ( array( 'object-keyed JSON text' => wp_json_encode( $sections ), 'numerically keyed array' => $sections ) as $label => $raw ) {
			$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = $raw;

			$result = $this->writer()->patch_elementor(
				self::PAGE_ID,
				array( 'operations' => array( array( 'op' => 'settings', 'element_id' => 'sec0001', 'settings' => array( 'a' => 1 ) ) ) )
			);

			$this->assertNotInstanceOf( WP_Error::class, $result, "{$label}: Elementor renders this tree and main patched it." );

			$tree = json_decode( stripslashes( (string) $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ), true );
			$this->assertSame( array( 0, 1 ), array_keys( $tree ), "{$label}: written back as a list." );
			$this->assertSame( 'sec0001', $tree[0]['id'] );
			$this->assertSame( 'sec0003', $tree[1]['id'] );
			$this->assertSame( 1, $tree[0]['settings']['a'] );
		}

		// Normalising is for keys, not contents: a non-element item is still refused.
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = array( 0 => $this->section( 'sec0001', array() ), 3 => 'junk' );
		$refused = $this->writer()->patch_elementor( self::PAGE_ID, array( 'operations' => array( array( 'op' => 'add', 'element' => $this->section( 'new0001', array() ) ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'invalid_elementor_data', $refused->get_error_code() );
	}

	public function test_a_repair_only_patch_on_an_empty_tree_answers_no_data_whatever_its_form(): void {
		foreach ( array( 'missing' => '', 'empty array' => array(), 'json []' => '[]', 'json null' => 'null' ) as $label => $raw ) {
			$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = $raw;

			$result = $this->writer()->patch_elementor( self::PAGE_ID, array( 'repair_ids' => true ) );

			$this->assertInstanceOf( WP_Error::class, $result, "{$label}: an empty page is one answer, not three." );
			$this->assertSame( 'no_elementor_data', $result->get_error_code(), $label );
		}
	}

	public function test_json_null_is_an_empty_page_for_operations(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = 'null';

		$result = $this->writer()->patch_elementor(
			self::PAGE_ID,
			array( 'operations' => array( array( 'op' => 'add', 'element' => $this->section( 'new0001', array() ) ) ) )
		);

		$this->assertNotInstanceOf( WP_Error::class, $result, 'Elementor renders "null" as an empty page.' );
		$tree = json_decode( stripslashes( (string) $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ), true );
		$this->assertCount( 1, $tree );
	}

	/**
	 * N3: the 422 for unusable stored data sends callers to wp_set_elementor,
	 * whose before/after count json_decode()d array meta — a TypeError on PHP 8.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_wp_set_elementor_works_on_a_page_whose_data_is_stored_as_an_array(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = new WP_Post(
			array(
				'ID'           => self::PAGE_ID,
				'post_type'    => 'page',
				'post_title'   => 'Array meta',
				'post_status'  => 'draft',
				'post_content' => 'Kept rendering of the Elementor page.',
			)
		);
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = array(
			$this->section( 'sec0001', array( $this->widget( 'hd00001', 'heading', array( 'title' => 'x' ) ) ) ),
		);

		try {
			$result = $this->writer()->set_elementor_data(
				self::PAGE_ID,
				array( 'elementor_data' => wp_json_encode( array( $this->section( 'sec0002', array(), 'col0002' ) ) ) ),
				false
			);
		} catch ( Throwable $e ) {
			$this->fail( 'wp_set_elementor threw on array-stored data: ' . get_class( $e ) . ': ' . $e->getMessage() );
		}

		$this->assertNotInstanceOf( WP_Error::class, $result, 'Array-stored data is Elementor data: the write is neither a crash nor a refused "conversion".' );
		$this->assertSame( 1, $result['structural_changes']['sections_before'], 'The stored array was read as the existing tree.' );
		$this->assertSame( 1, $result['structural_changes']['widgets_before'] );
	}

	/**
	 * N1: a link whose body was URL-encoded twice after a literal # still opens
	 * the Off-canvas — Elementor's runAction does decodeURI then
	 * decodeURIComponent.
	 */
	private function double_encoded_action_hash( string $target ): string {
		return '#' . rawurlencode( rawurlencode( sprintf( 'elementor-action:action=%1$s&settings=%2$s', 'off_canvas:open', base64_encode( wp_json_encode( array( 'id' => $target, 'displayMode' => 'open' ) ) ) ) ) );
	}

	public function test_repair_repoints_a_double_encoded_off_canvas_link_in_its_own_form(): void {
		$tree = array(
			$this->section(
				'sec0001',
				array(
					$this->widget( 'ocanvas1', 'off-canvas' ),
					$this->widget( 'btn0001', 'button', array( 'text' => 'Menu', 'link' => array( 'url' => $this->double_encoded_action_hash( 'ocanvas1' ) ) ) ),
				)
			),
		);

		$out    = $this->validate( $tree, array( 'repair_ids' => true, 'id_seed' => (string) self::PAGE_ID ) );
		$map    = (array) $out['result']['id_repair']['mapping'];
		$url    = $out['tree'][0]['elements'][0]['elements'][1]['settings']['link']['url'];

		$this->assertSame( $this->double_encoded_action_hash( $map['ocanvas1'] ), $url, 'Repointed, and still encoded twice.' );
		$this->assertContains(
			array(
				'path' => 'section[0].column[0].widget[1]',
				'key'  => 'link.url',
				'from' => 'ocanvas1',
				'to'   => $map['ocanvas1'],
			),
			$out['result']['id_repair']['references_repointed']
		);
	}

	public function test_the_caution_names_wordpress_menus_as_not_searched(): void {
		$this->assertStringContainsString( 'WordPress menus', $this->repair_off_canvas()['repair']['caution'], 'An "open the menu" custom link in Appearance > Menus is a common place for an Off-canvas trigger.' );
	}

	/**
	 * N4: an add whose id collides with an existing element must report the
	 * id the ADDED element was saved with, not the id the existing one got.
	 *
	 * @dataProvider duplicate_adds
	 */
	public function test_an_add_that_duplicates_an_existing_id_reports_the_added_elements_saved_id( string $id, bool $repair ): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( array( $this->section( $id, array(), 'colold1' ) ) );

		$data = array( 'operations' => array( array( 'op' => 'add', 'element' => $this->section( $id, array(), 'colnew1' ) ) ) );
		if ( $repair ) {
			$data['repair_ids'] = true;
		}

		$result = $this->writer()->patch_elementor( self::PAGE_ID, $data );
		$this->assertNotInstanceOf( WP_Error::class, $result );

		$tree = json_decode( stripslashes( (string) $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ), true );
		$this->assertCount( 2, $tree );
		$this->assertSame( 'colnew1', $tree[1]['elements'][0]['id'], 'The added section is second.' );
		$this->assertSame( $tree[1]['id'], $result['operations'][0]['element_id'], 'A follow-up edit with the reported id must change the element that was added.' );
		$this->assertSame( $id, $result['operations'][0]['element_id_submitted'] );
	}

	public static function duplicate_adds(): array {
		return array(
			'non-conforming id, repaired'  => array( 'dup88888', true ),
			'conforming id, de-duplicated' => array( 'dup0001', false ),
		);
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

	/**
	 * Scan roots for instructions that still say element ids have 8 characters.
	 *
	 * @param string[] $roots Files or directories.
	 * @return array{found:string[],scanned:int}
	 */
	private function scan_for_eight_character_wording( array $roots ): array {
		$patterns = array(
			'/\b8[- ]char(acter)?s? alphanumeric/i',
			'/\b8 alphanumeric/i',
			'/\b8-char (Elementor )?(element )?id\b/i',
			'/\b8[- ]char(acter)? (widget|element) id\b/i',
			'/\(8[- ]char\b/i',
			'/random 8-character element id/i',
		);

		$found   = array();
		$scanned = 0;
		foreach ( $roots as $root ) {
			$files = is_dir( $root )
				? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) )
				: array( new SplFileInfo( $root ) );
			foreach ( $files as $file ) {
				if ( ! in_array( strtolower( $file->getExtension() ), array( 'php', 'md', 'txt', 'js', 'json', 'yaml', 'yml' ), true ) ) {
					continue;
				}
				++$scanned;
				foreach ( file( $file->getPathname() ) as $n => $line ) {
					foreach ( $patterns as $pattern ) {
						if ( preg_match( $pattern, $line ) ) {
							$found[] = $file->getPathname() . ':' . ( $n + 1 );
						}
					}
				}
			}
		}

		return array(
			'found'   => $found,
			'scanned' => $scanned,
		);
	}

	public function test_plugin_files_do_not_tell_agents_to_use_eight_character_ids(): void {
		$plugin = dirname( __DIR__ );
		$roots  = array( $plugin . '/includes', $plugin . '/admin', $plugin . '/readme.txt' );

		foreach ( $roots as $root ) {
			$this->assertFileExists( $root, 'A plugin root that is missing would be scanned as "nothing found".' );
		}

		$scan = $this->scan_for_eight_character_wording( $roots );

		$this->assertGreaterThan( 0, $scan['scanned'] );
		$this->assertSame( array(), $scan['found'], 'Elementor Pro accepts only 7-character ids; an agent following these strings builds broken widgets.' );
	}

	/**
	 * The repo-level docs sit beside the plugin, not inside it. The house test
	 * command mounts only mcpwp/, where they cannot be reached, and failing
	 * there would turn every local run red; so an unreachable root is a
	 * visible skip locally and a failure in CI (which runs from the repo root,
	 * mcpwp-ci.yml). It is never a silent pass.
	 */
	public function test_repo_docs_do_not_tell_agents_to_use_eight_character_ids(): void {
		$repo    = dirname( dirname( __DIR__ ) );
		$roots   = array( $repo . '/docs', $repo . '/CLAUDE.md' );
		$missing = array_values( array_filter( $roots, function ( $root ) {
			return ! file_exists( $root );
		} ) );

		if ( ! empty( $missing ) ) {
			$reason = 'Repo-level docs are not reachable from this test root (' . implode( ', ', $missing ) . '); run from the repository root to scan them.';
			if ( false !== getenv( 'CI' ) && '' !== getenv( 'CI' ) ) {
				$this->fail( $reason );
			}
			$this->markTestSkipped( $reason );
		}

		$scan = $this->scan_for_eight_character_wording( $roots );

		$this->assertGreaterThan( 0, $scan['scanned'] );
		$this->assertSame( array(), $scan['found'], 'Elementor Pro accepts only 7-character ids; docs that say 8 teach agents to build broken widgets.' );
	}
}
