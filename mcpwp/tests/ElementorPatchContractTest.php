<?php
/**
 * Regression coverage for #825: wp_patch_elementor's contract.
 *
 * Reported on staging15 (MCPWP 3.10.5 - 3.10.8, Elementor 4.2.x):
 *
 *  1. The 20-operation cap was discovered by hitting it: "Maximum 20
 *     operations per patch." named neither the parameter nor what happened to
 *     the page.
 *  2. There was no documented way to remove a setting key, so dead keys could
 *     only be removed by rewriting the whole document.
 *  3. `add` with `parent_id` naming a column put the widget at the top level of
 *     the page and answered success.
 *
 * What this pins:
 *   - the cap is in the schema, and exceeding it is refused before anything is
 *     written, naming the cap;
 *   - `settings` ops remove keys through `unset` (and the older
 *     `delete_settings`), reporting what was removed and what was not there;
 *   - `add` with `parent_id` inserts into that element's children at the given
 *     position; an unknown parent, a parent that cannot hold that child, or a
 *     position that is not one of the parent's children is refused;
 *   - one failed operation refuses the whole patch: nothing is written, and the
 *     response says which operation failed;
 *   - an element added inside a parent is reported with the id it was saved
 *     with, even when the save de-duplicated or repaired it.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorPatchContractTest extends TestCase {

	private const PAGE_ID = 8250;

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
					'post_title'  => 'Patch contract probe',
					'post_name'   => 'patch-contract-probe',
					'post_status' => 'draft',
				)
			),
		);

		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( $this->page() );
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

	private function widget( string $id, string $type, array $settings = array() ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	private function section( string $id, string $column_id, array $widgets ): array {
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

	private function page(): array {
		return array(
			$this->section(
				'sec0001',
				'col0001',
				array(
					$this->widget( 'wid0001', 'heading', array( 'title' => 'Hello' ) ),
					$this->widget(
						'wid0002',
						'button',
						array(
							'text'                    => 'Go',
							'button_background_color' => '#ff0000',
							'obsolete_key'            => 'x',
							'tabs'                    => array(
								array(
									'_id'       => 'tab0001',
									'tab_title' => 'A',
									'junk'      => 1,
								),
							),
						)
					),
				)
			),
			$this->section( 'sec0002', 'col0002', array() ),
		);
	}

	private function stored_raw(): string {
		return (string) $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];
	}

	private function stored(): array {
		$tree = json_decode( stripslashes( $this->stored_raw() ), true );
		$this->assertIsArray( $tree );

		return $tree;
	}

	private function ids( array $elements ): array {
		return array_map(
			static function ( $el ) {
				return $el['id'];
			},
			$elements
		);
	}

	private function patch( array $operations, array $extra = array() ) {
		return $this->writer()->patch_elementor( self::PAGE_ID, array_merge( array( 'operations' => $operations ), $extra ) );
	}

	private function assert_nothing_written( string $before ): void {
		$this->assertSame( $before, $this->stored_raw(), 'A refused patch must leave the stored page exactly as it was.' );
	}

	// ---------------------------------------------------------------------
	// 1. The operation cap.
	// ---------------------------------------------------------------------

	public function test_a_patch_over_the_cap_is_refused_naming_the_cap_and_writes_nothing(): void {
		$before = $this->stored_raw();
		$max    = Mcpwp_Elementor_Basic::MAX_PATCH_OPERATIONS;

		$operations = array();
		for ( $i = 0; $i <= $max; $i++ ) {
			$operations[] = array(
				'op'         => 'settings',
				'element_id' => 'wid0001',
				'settings'   => array( 'title' => 'Title ' . $i ),
			);
		}

		$result = $this->patch( $operations );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'too_many_ops', $result->get_error_code(), 'The code callers already match on is kept.' );
		$this->assertStringContainsString( (string) $max, $result->get_error_message() );
		$this->assertStringContainsString( (string) ( $max + 1 ), $result->get_error_message(), 'The refusal says how many operations it received.' );
		$this->assertStringContainsString( 'Nothing was written', $result->get_error_message(), 'The refusal says the page was not touched — not a partial apply.' );

		$data = $result->get_error_data();
		$this->assertSame( $max, $data['max_operations'] ?? null, 'The cap is machine-readable in the refusal.' );
		$this->assertSame( $max + 1, $data['operations_received'] ?? null );
		$this->assertFalse( $data['written'] ?? null );

		$this->assert_nothing_written( $before );
	}

	public function test_the_schema_states_the_cap_and_documents_unset_and_parent_id(): void {
		$defs = array();
		foreach ( ( new Mcpwp_MCP_Free_Tools() )->get_tools() as $tool ) {
			$defs[ $tool['name'] ] = $tool;
		}

		$tool       = $defs['wp_patch_elementor'];
		$operations = $tool['inputSchema']['properties']['operations'];
		$max        = Mcpwp_Elementor_Basic::MAX_PATCH_OPERATIONS;

		$this->assertSame( $max, $operations['maxItems'] );
		$this->assertStringContainsString( 'max ' . $max, $tool['description'] );

		$properties = $operations['items']['properties'];
		$this->assertArrayHasKey( 'unset', $properties, 'Removing a setting key is a documented part of the settings op.' );
		$this->assertSame( 'array', $properties['unset']['type'] );
		$this->assertArrayHasKey( 'parent_id', $properties, 'add honours parent_id, so the schema has to say so.' );
		$this->assertStringContainsString( 'refused', $properties['parent_id']['description'], 'The schema says an unusable parent is refused, not placed at the top level.' );
		$this->assertStringContainsString( 'Nothing is written', $operations['description'], 'The schema says a failed operation refuses the whole patch.' );
	}

	// ---------------------------------------------------------------------
	// 2. Removing setting keys.
	// ---------------------------------------------------------------------

	public function test_settings_unset_removes_keys_and_reports_what_was_removed_and_what_was_absent(): void {
		$result = $this->patch(
			array(
				array(
					'op'              => 'settings',
					'element_id'      => 'wid0002',
					'settings'        => array( 'text' => 'Go now' ),
					'unset'           => array( 'button_background_color', 'never_set' ),
					'delete_settings' => array( 'obsolete_key' ),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$settings = $this->stored()[0]['elements'][0]['elements'][1]['settings'];
		$this->assertArrayNotHasKey( 'button_background_color', $settings, 'unset removes the key from the stored element.' );
		$this->assertArrayNotHasKey( 'obsolete_key', $settings, 'delete_settings keeps working.' );
		$this->assertSame( 'Go now', $settings['text'], 'The merge in the same op still applies.' );

		$op = $result['operations'][0];
		$this->assertTrue( $op['success'] );
		$this->assertSame( array( 'button_background_color', 'obsolete_key' ), $op['removed_keys'] );
		$this->assertSame( array( 'never_set' ), $op['keys_not_present'], 'A key that was not there is reported, not claimed as removed.' );
	}

	public function test_unset_follows_a_dotted_path_into_nested_values(): void {
		$result = $this->patch(
			array(
				array(
					'op'         => 'settings',
					'element_id' => 'wid0002',
					'unset'      => array( 'tabs.0.junk', 'tabs.3.junk' ),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$tab = $this->stored()[0]['elements'][0]['elements'][1]['settings']['tabs'][0];
		$this->assertSame( array( '_id' => 'tab0001', 'tab_title' => 'A' ), $tab );
		$this->assertSame( array( 'tabs.0.junk' ), $result['operations'][0]['removed_keys'] );
		$this->assertSame( array( 'tabs.3.junk' ), $result['operations'][0]['keys_not_present'] );
	}

	public function test_a_settings_op_with_nothing_to_do_is_refused(): void {
		$before = $this->stored_raw();

		$result = $this->patch( array( array( 'op' => 'settings', 'element_id' => 'wid0002' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result, 'A settings op carrying neither settings nor keys to remove changed nothing and must not answer success.' );
		$this->assert_nothing_written( $before );
	}

	// ---------------------------------------------------------------------
	// 3. add honours parent_id.
	// ---------------------------------------------------------------------

	/**
	 * @dataProvider child_positions
	 */
	public function test_add_with_parent_id_lands_inside_the_parent_at_the_position( string $position, array $expected ): void {
		$op = array(
			'op'        => 'add',
			'parent_id' => 'col0001',
			'element'   => $this->widget( 'new0001', 'heading', array( 'title' => 'Added' ) ),
		);
		if ( '' !== $position ) {
			$op['position'] = $position;
		}

		$result = $this->patch( array( $op ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$tree = $this->stored();
		$this->assertSame( array( 'sec0001', 'sec0002' ), $this->ids( $tree ), 'Nothing lands at the top level.' );
		$this->assertSame( $expected, $this->ids( $tree[0]['elements'][0]['elements'] ) );

		$this->assertSame( 'new0001', $result['operations'][0]['element_id'] );
		$this->assertSame( 'col0001', $result['operations'][0]['parent_id'] );
	}

	public static function child_positions(): array {
		return array(
			'default is end' => array( '', array( 'wid0001', 'wid0002', 'new0001' ) ),
			'end'            => array( 'end', array( 'wid0001', 'wid0002', 'new0001' ) ),
			'start'          => array( 'start', array( 'new0001', 'wid0001', 'wid0002' ) ),
			'before'         => array( 'before:wid0002', array( 'wid0001', 'new0001', 'wid0002' ) ),
			'after'          => array( 'after:wid0001', array( 'wid0001', 'new0001', 'wid0002' ) ),
		);
	}

	public function test_add_into_an_empty_column_works(): void {
		$result = $this->patch(
			array(
				array(
					'op'        => 'add',
					'parent_id' => 'col0002',
					'element'   => $this->widget( 'new0002', 'heading' ),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( array( 'new0002' ), $this->ids( $this->stored()[1]['elements'][0]['elements'] ) );
	}

	public function test_add_with_an_unknown_parent_is_refused_and_nothing_is_written(): void {
		$before = $this->stored_raw();

		$result = $this->patch(
			array(
				array(
					'op'        => 'add',
					'parent_id' => 'nope001',
					'element'   => $this->widget( 'new0001', 'heading' ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'An unknown parent must never fall back to the top level.' );
		$this->assertStringContainsString( 'nope001', $result->get_error_message() );
		$this->assert_nothing_written( $before );
	}

	/**
	 * @dataProvider unusable_parents
	 */
	public function test_add_refuses_a_child_the_parent_cannot_hold( string $parent_id, array $element, string $message ): void {
		$before = $this->stored_raw();

		$result = $this->patch(
			array(
				array(
					'op'        => 'add',
					'parent_id' => $parent_id,
					'element'   => $element,
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( $message, $result->get_error_message() );
		$this->assert_nothing_written( $before );
	}

	public function unusable_parents(): array {
		return array(
			'widget into a section' => array( 'sec0001', $this->widget( 'new0001', 'heading' ), "'widget' cannot be placed inside a 'section'" ),
			'anything into a widget' => array( 'wid0001', $this->widget( 'new0001', 'heading' ), "'widget' cannot be placed inside a 'widget'" ),
		);
	}

	public function test_add_position_must_name_one_of_the_parents_children(): void {
		$before = $this->stored_raw();

		$result = $this->patch(
			array(
				array(
					'op'        => 'add',
					'parent_id' => 'col0001',
					'position'  => 'before:sec0002',
					'element'   => $this->widget( 'new0001', 'heading' ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'sec0002 exists, but not among col0001\'s children.' );
		$this->assertStringContainsString( 'sec0002', $result->get_error_message() );
		$this->assert_nothing_written( $before );
	}

	public function test_an_invalid_position_is_refused_rather_than_ignored(): void {
		$before = $this->stored_raw();

		$result = $this->patch(
			array(
				array(
					'op'       => 'add',
					'position' => 'middle',
					'element'  => $this->section( 'new0001', 'newcol1', array() ),
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'An unrecognised position used to insert nothing and still report success.' );
		$this->assert_nothing_written( $before );
	}

	// ---------------------------------------------------------------------
	// Atomicity.
	// ---------------------------------------------------------------------

	public function test_one_failed_operation_refuses_the_whole_patch(): void {
		$before = $this->stored_raw();

		$result = $this->patch(
			array(
				array(
					'op'         => 'settings',
					'element_id' => 'wid0001',
					'settings'   => array( 'title' => 'Changed' ),
				),
				array(
					'op'         => 'remove',
					'element_id' => 'missing1',
				),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'A patch whose second operation failed used to save the first and answer success.' );
		$this->assertSame( 'patch_operation_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Nothing was written', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertFalse( $data['written'] );
		$this->assertSame( 1, $data['failed_operation'] );
		$this->assertStringContainsString( 'missing1', $data['operations'][1]['error'] );

		$this->assert_nothing_written( $before );
	}

	// ---------------------------------------------------------------------
	// Id reporting for nested adds (3.11.1 id repair).
	// ---------------------------------------------------------------------

	public function test_a_nested_add_that_duplicates_an_id_reports_the_id_it_was_saved_with(): void {
		$result = $this->patch(
			array(
				array(
					'op'        => 'add',
					'parent_id' => 'col0002',
					'element'   => $this->widget( 'wid0001', 'heading', array( 'title' => 'Copy' ) ),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$tree  = $this->stored();
		$added = $tree[1]['elements'][0]['elements'][0];
		$this->assertSame( 'Copy', $added['settings']['title'] );
		$this->assertSame( 'wid0001', $tree[0]['elements'][0]['elements'][0]['id'], 'The existing element keeps its id.' );
		$this->assertNotSame( 'wid0001', $added['id'], 'The save de-duplicated the added copy.' );
		$this->assertSame( $added['id'], $result['operations'][0]['element_id'], 'A follow-up edit with the reported id must change the element that was added.' );
		$this->assertSame( 'wid0001', $result['operations'][0]['element_id_submitted'] );
	}

	public function test_a_nested_add_reports_its_repaired_id(): void {
		$result = $this->patch(
			array(
				array(
					'op'        => 'add',
					'parent_id' => 'col0002',
					'element'   => $this->widget( 'toolong88', 'heading' ),
				),
			),
			array( 'repair_ids' => true )
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$saved = $this->stored()[1]['elements'][0]['elements'][0]['id'];
		$map   = (array) $result['id_repair']['mapping'];
		$this->assertSame( $map['toolong88'], $saved );
		$this->assertSame( $saved, $result['operations'][0]['element_id'] );
		$this->assertSame( 'toolong88', $result['operations'][0]['element_id_submitted'] );
	}
}
