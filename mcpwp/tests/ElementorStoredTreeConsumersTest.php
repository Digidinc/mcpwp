<?php
/**
 * #845: readers of `_elementor_data` outside Mcpwp_Elementor_Basic.
 *
 * Every one of them decoded the meta with json_decode() — a TypeError on PHP 8
 * when the tree is stored as an array, as imports store it — or skipped
 * anything that was not a string, which turned an array-stored page into "no
 * Elementor data". They now read through the one shared reader.
 *
 *   find/replace (one post)  array tree rewritten as JSON text; unusable → 422
 *   find/replace (many)      array tree scanned; unusable listed as unreadable
 *   clone page               array tree cloned as JSON text; unusable → 422
 *   internal-link readers    array tree links found
 *   blueprint extraction     array tree read; unusable flagged, not blank
 *   broken-Elementor signal  array tree healthy; unusable array flagged
 *   design-reference parts   cut from an array tree
 *   approval rollback        array snapshot restored as JSON text; escaped
 *                            characters survive the meta API's unslash
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-content-graph.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-blueprints.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';

final class ElementorStoredTreeConsumersTest extends TestCase {

	private const PAGE_ID = 8460;

	/** @var array */
	private $saved = array();

	protected function setUp(): void {
		foreach ( array( '_mcpwp_test_meta', '_mcpwp_test_posts', 'mcpwp_test_options', 'mcpwp_test_transients' ) as $global ) {
			$this->saved[ $global ] = $GLOBALS[ $global ] ?? array();
			$GLOBALS[ $global ]     = array();
		}
		$GLOBALS['mcpwp_test_current_user'] = 1;
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $global => $value ) {
			$GLOBALS[ $global ] = $value;
		}
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------

	private function page( int $id, $raw, string $slug = '' ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'            => $id,
			'post_type'     => 'page',
			'post_title'    => 'Page ' . $id,
			'post_name'     => '' !== $slug ? $slug : 'page-' . $id,
			'post_status'   => 'publish',
			'post_date'     => '2026-09-10 00:00:00',
			'post_modified' => '2026-09-10 00:00:00',
			'post_parent'   => 0,
			'menu_order'    => 0,
			'post_author'   => 1,
			'post_content'  => '',
			'post_excerpt'  => '',
		);
		$GLOBALS['_mcpwp_test_meta'][ $id ]['_elementor_data'] = $raw;
	}

	private static function tree( string $title = 'Old Brand grows crops' ): array {
		return array(
			array(
				'id'       => 'sec0001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col0001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'hd00001',
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => $title ),
								'elements'   => array(),
							),
							array(
								'id'         => 'bt00001',
								'elType'     => 'widget',
								'widgetType' => 'button',
								'settings'   => array( 'text' => 'About', 'link' => array( 'url' => 'https://example.com/about/' ) ),
								'elements'   => array(),
							),
							array(
								'id'         => 'tx00001',
								'elType'     => 'widget',
								'widgetType' => 'text-editor',
								'settings'   => array( 'editor' => '<p>Say "hi" on the <a href="https://example.com/contact/">contact page</a>.</p>' ),
								'elements'   => array(),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function unusable_meta(): array {
		return array(
			'corrupt json string'         => array( '[{"id":"sec0001","elType":', 'string' ),
			'json string that is no list' => array( '"just Old Brand"', 'string' ),
			'array that is no list'       => array( array( 'title' => 'Old Brand' ), 'array' ),
		);
	}

	private function meta( int $id ) {
		return $GLOBALS['_mcpwp_test_meta'][ $id ]['_elementor_data'] ?? null;
	}

	private function assertRefused( $result, string $stored_as ): void {
		$this->assertInstanceOf( WP_Error::class, $result, 'Data that cannot be a tree is refused.' );
		$this->assertSame( 'invalid_elementor_data', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 422, $data['status'] ?? null );
		$this->assertSame( $stored_as, $data['stored_as'] ?? null, 'The refusal says how the data is stored.' );
	}

	// ---------------------------------------------------------------------
	// Find/replace.
	// ---------------------------------------------------------------------

	private function find_replace( int $id, string $search, string $replace ) {
		return ( new Mcpwp_REST_Elementor() )->find_replace(
			new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/' . $id . '/find-replace', array( 'id' => $id, 'search' => $search, 'replace' => $replace ) )
		);
	}

	public function test_find_replace_rewrites_array_stored_data_as_json_text(): void {
		$this->page( self::PAGE_ID, self::tree() );

		$response = $this->find_replace( self::PAGE_ID, 'Old Brand', 'New Brand' );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 1, $response->get_data()['replacements'] );
		$stored = $this->meta( self::PAGE_ID );
		$this->assertIsString( $stored );
		$this->assertSame( 'New Brand grows crops', json_decode( $stored, true )[0]['elements'][0]['elements'][0]['settings']['title'] );
	}

	/**
	 * @dataProvider unusable_meta
	 */
	public function test_find_replace_refuses_data_that_cannot_be_a_tree( $raw, string $stored_as ): void {
		$this->page( self::PAGE_ID, $raw );

		$this->assertRefused( $this->find_replace( self::PAGE_ID, 'Old Brand', 'New Brand' ), $stored_as );
		$this->assertSame( $raw, $this->meta( self::PAGE_ID ), 'Nothing was written.' );
	}

	public function test_bulk_find_replace_scans_array_stored_pages_and_lists_unusable_ones_as_unreadable(): void {
		$this->page( 8461, self::tree() );
		$this->page( 8462, '[{"id":"sec0001","elType":' );
		$this->page( 8463, self::tree( 'Nothing to change' ) );
		$this->page( 8464, array( 'title' => 'Old Brand' ) );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/find-replace' );
		foreach ( array( 'ids' => array( 8461, 8462, 8463, 8464 ), 'search' => 'Old Brand', 'replace' => 'New Brand' ) as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = ( new Mcpwp_REST_Elementor() )->find_replace_many( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( array( 8461 ), array_column( $data['matched'], 'id' ), 'The array-stored page was scanned, not skipped as empty.' );
		$this->assertSame( array( 8462, 8464 ), array_column( $data['unreadable'], 'id' ), 'An array that is not a tree is unreadable, like corrupt text.' );
		$this->assertSame( 0, $data['skipped_empty'], 'No page here is empty.' );
		$this->assertSame( array( array( 'id' => 8463, 'reason' => 'no_match' ) ), $data['not_touched'] );
		$this->assertSame( 'New Brand grows crops', json_decode( $this->meta( 8461 ), true )[0]['elements'][0]['elements'][0]['settings']['title'] );
	}

	// ---------------------------------------------------------------------
	// Clone.
	// ---------------------------------------------------------------------

	private function clone_page( int $id ) {
		return ( new Mcpwp_REST_Pages() )->clone_page( new WP_REST_Request( 'POST', '/mcpwp/v1/pages/' . $id . '/clone', array( 'id' => $id ) ) );
	}

	public function test_clone_page_copies_array_stored_data_as_json_text(): void {
		$this->page( self::PAGE_ID, self::tree() );

		$this->clone_page( self::PAGE_ID );

		$ids = array_keys( $GLOBALS['_mcpwp_test_posts'] );
		$this->assertCount( 2, $ids, 'The clone was created.' );
		$new = max( $ids );

		$stored = $this->meta( $new );
		$this->assertIsString( $stored, 'The clone stores JSON text, the form every write uses.' );
		$this->assertSame( self::tree(), json_decode( $stored, true ) );
		$this->assertSame( 'builder', $GLOBALS['_mcpwp_test_meta'][ $new ]['_elementor_edit_mode'] ?? null );
	}

	/**
	 * @dataProvider unusable_meta
	 */
	public function test_clone_page_refuses_a_source_whose_data_cannot_be_a_tree( $raw, string $stored_as ): void {
		$this->page( self::PAGE_ID, $raw );

		$this->assertRefused( $this->clone_page( self::PAGE_ID ), $stored_as );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_posts'], 'No half-made clone is left behind.' );
	}

	// ---------------------------------------------------------------------
	// Internal-link readers.
	// ---------------------------------------------------------------------

	private function invoke( $object, string $method, array $args ) {
		$ref = new ReflectionMethod( $object, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $object, $args );
	}

	public function test_internal_link_readers_find_links_in_array_stored_trees(): void {
		$this->page( self::PAGE_ID, self::tree() );
		$graph = ( new ReflectionClass( Mcpwp_REST_Content_Graph::class ) )->newInstanceWithoutConstructor();
		$post  = $GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ];

		$this->assertSame( 2, $this->invoke( $graph, 'count_same_host_outbound_links', array( $post ) ), 'The button and the text-editor link.' );

		$links = $this->invoke( $graph, 'extract_elementor_links_from_postmeta', array( self::PAGE_ID, array( '/about' => 11, '/contact' => 12 ) ) );
		$targets = array_column( $links, 'target_id' );
		sort( $targets );
		$this->assertSame( array( 11, 12 ), $targets );

		$raw_links = $this->invoke( $graph, 'extract_raw_links_from_elementor', array( self::PAGE_ID ) );
		$hrefs     = array_column( $raw_links, 'href' );
		sort( $hrefs );
		$this->assertSame( array( 'https://example.com/about/', 'https://example.com/contact/' ), $hrefs );

		$this->assertTrue( $this->invoke( $graph, 'is_elementor_built', array( self::PAGE_ID ) ), 'Array-stored data is Elementor data (and reading it raises no warning).' );
	}

	// ---------------------------------------------------------------------
	// Blueprint extraction.
	// ---------------------------------------------------------------------

	public function test_blueprint_extraction_reads_array_stored_pages_and_flags_unreadable_ones(): void {
		$this->page( 8465, self::tree(), 'array-page' );
		$this->page( 8466, '[{"id":"sec0001","elType":', 'corrupt-page' );

		$pages = array();
		foreach ( Mcpwp_Site_Blueprints::extract()['pages'] as $entry ) {
			$pages[ $entry['slug'] ] = $entry;
		}

		$this->assertSame( array( 'heading', 'button', 'text-editor' ), $pages['array-page']['sections'], 'Widget types are read from the array-stored tree.' );
		$this->assertArrayNotHasKey( 'elementor_unreadable', $pages['array-page'] );
		$this->assertTrue( $pages['corrupt-page']['elementor_unreadable'] ?? false, 'No detected sections because the data could not be read is not "no sections".' );
	}

	// ---------------------------------------------------------------------
	// Broken-Elementor signal (needs ELEMENTOR_VERSION: separate process).
	// ---------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_broken_elementor_signal_treats_array_trees_as_healthy_and_flags_unusable_arrays(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$gapped = self::tree();
		$gapped = array( 3 => $gapped[0] );

		$this->page( 8467, self::tree() );
		$this->page( 8468, $gapped );
		$this->page( 8469, array( 'title' => 'not an element' ) );
		$this->page( 8470, '[{"id":"sec0001","elType":' );
		$this->page( 8471, '"just a string"' );

		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			public function get_results( $query = '', $output = ARRAY_A ) {
				$rows = array();
				foreach ( array( 8467, 8468, 8469, 8470, 8471 ) as $id ) {
					$rows[] = array( 'ID' => $id, 'post_title' => 'Page ' . $id );
				}
				return $rows;
			}
		};

		$method = new ReflectionMethod( Mcpwp_Signals::class, 'compute_broken_elementor' );
		$method->setAccessible( true );

		$by_id = array();
		foreach ( $method->invoke( null ) as $signal ) {
			$by_id[ $signal['entity_id'] ] = $signal['severity'];
		}
		ksort( $by_id );

		$this->assertSame(
			array( 8469 => 'medium', 8470 => 'high', 8471 => 'medium' ),
			$by_id,
			'Array trees (a list, or gapped integer keys) render; an array that is no tree does not.'
		);
	}

	/**
	 * Review round 2, P2-1: the signal decoded the text once and handed the
	 * decoded value to the shared reader, which decodes a string again and
	 * calls false or "" an empty page. Double-encoded JSON, JSON false and
	 * JSON "" were reported healthy while every edit tool refuses the same
	 * pages with 422. The signal and the writers must agree.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_broken_elementor_signal_flags_text_the_writers_refuse(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		$unusable = array(
			8472 => wp_json_encode( wp_json_encode( self::tree() ) ), // double-encoded
			8473 => 'false',
			8474 => '""',
		);
		foreach ( $unusable as $id => $raw ) {
			$this->page( $id, $raw );
		}
		$this->page( 8475, wp_json_encode( self::tree() ) );

		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			public function get_results( $query = '', $output = ARRAY_A ) {
				$rows = array();
				foreach ( array( 8472, 8473, 8474, 8475 ) as $id ) {
					$rows[] = array( 'ID' => $id, 'post_title' => 'Page ' . $id );
				}
				return $rows;
			}
		};

		$method = new ReflectionMethod( Mcpwp_Signals::class, 'compute_broken_elementor' );
		$method->setAccessible( true );

		$by_id = array();
		foreach ( $method->invoke( null ) as $signal ) {
			$by_id[ $signal['entity_id'] ] = $signal['severity'];
		}
		ksort( $by_id );

		$this->assertSame( array( 8472 => 'medium', 8473 => 'medium', 8474 => 'medium' ), $by_id, 'Text that decodes to something other than a tree is broken; well-formed JSON text is healthy.' );

		foreach ( array_keys( $unusable ) as $id ) {
			$this->assertTrue(
				Mcpwp_Elementor_Stored_Tree::is_unusable( Mcpwp_Elementor_Stored_Tree::of_post( $id ) ),
				"Page {$id}: the writers refuse it, so the signal must not call it healthy."
			);
		}
	}

	// ---------------------------------------------------------------------
	// Design-reference parts (needs ELEMENTOR_VERSION: separate process).
	// ---------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_design_reference_parts_are_cut_from_an_array_stored_page(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		$GLOBALS['mcpwp_test_did_action']['elementor/loaded'] = 1;
		if ( ! class_exists( 'Mcpwp_Admin' ) ) {
			foreach ( array( 'setup', 'control-room', 'chat', 'library', 'settings', 'activity', 'mupot' ) as $trait ) {
				require_once dirname( __DIR__ ) . '/includes/admin/traits/trait-mcpwp-admin-' . $trait . '.php';
			}
			require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-admin.php';
		}

		$second        = self::tree( 'Second' );
		$second[0]['id'] = 'sec0002';
		$this->page( self::PAGE_ID, array( self::tree()[0], $second[0] ) );

		$admin  = ( new ReflectionClass( Mcpwp_Admin::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( Mcpwp_Admin::class, 'create_parts_from_design_reference_page' );
		$method->setAccessible( true );

		$parts = $method->invoke( $admin, self::PAGE_ID, array( 'title' => 'Reference', 'section_outline' => array( 'hero', 'features' ) ) );

		$this->assertCount( 2, $parts, 'One part per top-level section of the array-stored page.' );
	}

	// ---------------------------------------------------------------------
	// Approval rollback.
	// ---------------------------------------------------------------------

	private function rollback( $elementor_data ) {
		$method = new ReflectionMethod( Mcpwp_Action_Log::class, 'apply_snapshot' );
		$method->setAccessible( true );

		return $method->invoke( null, 'elementor', array( 'post_id' => self::PAGE_ID, 'elementor_data' => $elementor_data ) );
	}

	public function test_rollback_restores_an_array_snapshot_as_json_text(): void {
		$this->page( self::PAGE_ID, '[]' );

		$this->assertTrue( $this->rollback( self::tree() ) );

		$stored = $this->meta( self::PAGE_ID );
		$this->assertIsString( $stored, 'Restored as JSON text, the form every write uses.' );
		$this->assertSame( self::tree(), json_decode( $stored, true ) );
	}

	/**
	 * update_post_meta() unslashes what it stores. Elementor JSON escapes
	 * every quote inside a setting, so a snapshot restored without slashing
	 * lost those backslashes and the page it "restored" could not be decoded.
	 */
	public function test_rollback_restores_json_text_byte_for_byte_including_escaped_quotes(): void {
		$snapshot = wp_json_encode( self::tree() );
		$this->assertStringContainsString( '\\"', $snapshot, 'precondition: the snapshot carries escaped quotes' );
		$this->page( self::PAGE_ID, '[]' );

		$this->assertTrue( $this->rollback( $snapshot ) );

		$this->assertSame( $snapshot, $this->meta( self::PAGE_ID ) );
		$this->assertSame( self::tree(), json_decode( $this->meta( self::PAGE_ID ), true ) );
	}

	public function test_rollback_refuses_an_unreadable_snapshot_and_changes_nothing(): void {
		// #829 review round 3: `_elementor_data` that does not decode, as
		// Elementor decodes it, to a list of elements is never stored — not
		// by a rollback either. Stored as text it could not be sanitized
		// (kses cannot see JSON-escaped markup) and the page could not render.
		// Before #829 a rollback restored it exactly as it was.
		$snapshot = '[{"id":"sec0001","settings":{"title":"a \\"b';
		$this->page( self::PAGE_ID, '[]' );

		$result = $this->rollback( $snapshot );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'elementor_data_not_a_tree', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( '[]', $this->meta( self::PAGE_ID ), 'Nothing was written.' );
	}
}
