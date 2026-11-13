<?php
/**
 * Regression coverage for #976 — Mcpwp_Theme_Builder::set_template_conditions()
 * used to write `elementor_pro_theme_builder_conditions` itself, through the
 * now-removed update_global_conditions_index(), shaped
 * `{ condition_key => [template_ids] }` (e.g. `{"include/general": [7]}`).
 *
 * Elementor Pro's own option — confirmed reading
 * `ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache` directly on
 * the installed plugin rather than assuming it — is the opposite axis:
 * `{ location => { template_id => [condition_strings] } }` (e.g.
 * `{"header": {7: ["include/general"]}}`). The wrong-axis write was masked
 * in the common case only because Elementor's own save_conditions() and the
 * explicit get_cache()->regenerate() called right after it both immediately
 * overwrite the option correctly; #975's own test suite documented a
 * rollback path (a regenerate() that throws mid-request) where that
 * overwrite never runs and the malformed write sticks — header/footer/
 * single templates can then stop rendering site-wide, per the issue.
 *
 * Fix: delete the wrong-axis write outright. set_template_conditions() now
 * delegates the option entirely to Elementor Pro's own Conditions_Manager /
 * Conditions_Cache — the same delegation #975 already uses for the delete
 * path via Conditions_Manager::purge_post_from_cache().
 *
 * This spies on every write to elementor_pro_theme_builder_conditions made
 * during one set_template_conditions() call and asserts none of them is
 * ever shaped like the old wrong axis — a stronger guard than checking only
 * the final value, since the original bug's own window was a write in the
 * MIDDLE of the call that a later write happened to cover up.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorProConditionsIndexShapeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		unset( $GLOBALS['mcpwp_test_option_store_after_hook'], $GLOBALS['mcpwp_test_raw_option_after_write'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_option_store_after_hook'], $GLOBALS['mcpwp_test_raw_option_after_write'] );
	}

	private function seed_template( int $id, string $status = 'publish' ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'          => $id,
			'post_type'   => 'elementor_library',
			'post_status' => $status,
			'post_title'  => 'Template ' . $id,
		);
	}

	/**
	 * Runs $call() with a spy on raw per-document cache-entry writes.
	 *
	 * @return array<int,array>
	 */
	private function record_condition_entry_writes( callable $call ): array {
		$writes = array();

		$GLOBALS['mcpwp_test_raw_option_after_write'] = function ( $name, $value ) use ( &$writes ) {
			if ( 0 === strpos( (string) $name, Mcpwp_Custom_Code_Cache_Entry::OPTION_PREFIX ) ) {
				$writes[] = $value;
			}
		};

		try {
			$call();
		} finally {
			unset( $GLOBALS['mcpwp_test_raw_option_after_write'] );
		}

		return $writes;
	}

	/**
	 * A write is "wrong-axis" when any top-level key looks like a condition
	 * string (contains "/", e.g. "include/general") rather than a Theme
	 * Builder location (e.g. "header") — the exact shape
	 * update_global_conditions_index() used to write.
	 */
	private function assertLocationFirstView( array $view, int $id, string $location, array $conditions ): void {
		$this->assertSame( $conditions, $view[ $location ][ $id ] ?? null );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_template_conditions_never_writes_the_wrong_axis(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}

		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';

		$this->seed_template( 7 );

		$builder = new Mcpwp_Theme_Builder();

		$writes = $this->record_condition_entry_writes(
			function () use ( $builder ) {
				$result = $builder->set_template_conditions(
					7,
					array(
						array(
							'type' => 'include',
							'name' => 'general',
						),
					)
				);

				$this->assertIsArray( $result, 'The happy path must still succeed.' );
				$this->assertArrayHasKey( 'conditions', $result );
				$this->assertArrayHasKey( 'warnings', $result );
				$this->assertSame( array(), $result['warnings'], 'A valid condition must not warn.' );
			}
		);

		$this->assertNotEmpty( $writes, 'A valid condition save must durably write this document entry.' );
		$this->assertSame( 7, $writes[0]['id'] ?? null );
		$this->assertSame( 'header', $writes[0]['location'] ?? null );
		$this->assertSame( array( 'include/general' ), $writes[0]['conditions'] ?? null );
		$this->assertLocationFirstView( Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() ), 7, 'header', array( 'include/general' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_removing_all_conditions_never_writes_the_wrong_axis_either(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}

		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';

		$this->seed_template( 9 );

		Mcpwp_Custom_Code_Cache_Entry::sync( 9, 'header', array( 'include/general' ) );
		$writes = $this->record_condition_entry_writes(
			function () {
				Mcpwp_Custom_Code_Cache_Entry::remove( 9 );
			}
		);

		$this->assertNotEmpty( $writes );
		$this->assertNotEmpty( array_filter( $writes, static function ( $write ) {
			return ! empty( $write['deleted'] );
		} ) );
		$this->assertNull( Mcpwp_Custom_Code_Cache_Entry::entry( 9 ) );
	}

	/**
	 * update_global_conditions_index() is gone outright, not merely
	 * unreachable — a future refactor that reintroduces it under the same
	 * name without wiring it back into set_template_conditions() would not
	 * be caught by the write-spy tests above, so this pins the method's
	 * absence directly.
	 */
	public function test_the_wrong_axis_method_no_longer_exists(): void {
		$this->assertFalse(
			method_exists( 'Mcpwp_Theme_Builder', 'update_global_conditions_index' ),
			'update_global_conditions_index() should stay deleted (#976) — its shape disagreed with Elementor Pro\'s real Conditions_Cache.'
		);
	}

	// ---------------------------------------------------------------
	// Mcpwp_REST_Elementor_Pro::save_custom_code_conditions() had the exact
	// same defect, in the same option, via its own now-removed
	// update_custom_code_conditions_index() — a sibling bug, not the one
	// #976 names, but the identical pattern against the identical option.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_custom_code_conditions_never_write_the_wrong_axis_either(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}

		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/elementor/custom-code',
			array(
				'title'      => 'Shape Regression',
				'code'       => '<script>ok()</script>',
				'location'   => 'head',
				'status'     => 'publish',
				'conditions' => array(
					array(
						'type' => 'include',
						'name' => 'general',
					),
				),
			)
		);

		$snippet_id = 0;
		$writes = $this->record_condition_entry_writes(
			function () use ( $controller, $request, &$snippet_id ) {
				$response = $controller->create_custom_code( $request );

				$this->assertSame( 201, $response->get_status(), 'The happy path must still succeed.' );
				$this->assertSame( 'mcpwp_conditions_cache_entry', $response->get_data()['snippet']['conditions_engine'] );
				$snippet_id = (int) $response->get_data()['id'];
			}
		);

		$this->assertNotEmpty( $writes, 'A Custom Code save must durably write its document entry.' );
		$entry = Mcpwp_Custom_Code_Cache_Entry::entry( $snippet_id );
		$this->assertSame( 'elementor_head', $entry['location'] ?? null );
		$this->assertSame( array( 'include/general' ), $entry['conditions'] ?? null );
		$this->assertLocationFirstView( Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() ), (int) $entry['id'], 'elementor_head', array( 'include/general' ) );
	}

	public function test_the_wrong_axis_custom_code_methods_no_longer_exist(): void {
		$this->assertFalse(
			method_exists( 'Mcpwp_REST_Elementor_Pro', 'update_custom_code_conditions_index' ),
			'update_custom_code_conditions_index() should stay deleted (#976) — its shape disagreed with Elementor Pro\'s real Conditions_Cache.'
		);
		$this->assertFalse(
			method_exists( 'Mcpwp_REST_Elementor_Pro', 'build_custom_code_condition_key' ),
			'build_custom_code_condition_key() was only used by the now-deleted update_custom_code_conditions_index().'
		);
	}

	// ---------------------------------------------------------------
	// #974/#976 review round 2, NIT-3: the fixture's regenerate() used to
	// be a no-op rebuild (clear(), straight to save() — always ending
	// empty), which made every test above's use of it vacuously
	// compatible with the real, status-filtered WP_Query behaviour
	// without ever actually exercising that filter. These two tests pin
	// the fixture itself, matching the real Conditions_Cache::regenerate()
	// (publish-only) and Conditions_Manager::save_conditions() (false for
	// a non-theme document) exactly.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_regenerate_keeps_other_published_entries_and_drops_drafts(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}

		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';

		$this->seed_template( 10, 'publish' );
		update_post_meta( 10, '_elementor_conditions', array( 'include/general' ) );

		$this->seed_template( 11, 'draft' );
		update_post_meta( 11, '_elementor_conditions', array( 'include/general' ) );

		// Seed a stale option too, so this proves a REBUILD (not merely a
		// first population) drops the draft — matching regenerate()'s
		// real contract of replacing the option wholesale from a fresh
		// query, not merging into whatever was there before.
		update_option(
			'elementor_pro_theme_builder_conditions',
			array( 'header' => array( 11 => array( 'include/general' ) ) )
		);

		$conditions_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
		$conditions_manager->get_cache()->regenerate();

		$option = get_option( 'elementor_pro_theme_builder_conditions', array() );

		$this->assertArrayHasKey( 'header', $option );
		$this->assertArrayHasKey(
			10,
			$option['header'],
			'A published post carrying real _elementor_conditions meta must survive a rebuild.'
		);
		$this->assertArrayNotHasKey(
			11,
			$option['header'],
			'A draft must NOT survive a rebuild — this is the real, status-filtered WP_Query behaviour that made W1 possible in production.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_conditions_returns_false_for_a_non_theme_document(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}

		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';

		$GLOBALS['mcpwp_theme_test_no_document_ids'] = array( 99 );

		$conditions_manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
		$result              = $conditions_manager->save_conditions(
			99,
			array(
				array(
					'type' => 'include',
					'name' => 'general',
				),
			)
		);

		$this->assertFalse( $result );
		$this->assertSame( array(), get_option( 'elementor_pro_theme_builder_conditions', array() ) );
	}
}
