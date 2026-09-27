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
		unset( $GLOBALS['mcpwp_test_option_store_after_hook'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_option_store_after_hook'] );
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
	 * Runs $call() with a spy installed on update_option() (via
	 * tests/bootstrap.php's mcpwp_test_option_store_after_hook, which fires
	 * after every value that actually lands) and returns every value written
	 * to elementor_pro_theme_builder_conditions, in write order.
	 *
	 * @return array<int,array>
	 */
	private function record_condition_option_writes( callable $call ): array {
		$writes = array();

		$GLOBALS['mcpwp_test_option_store_after_hook'] = function ( $name, $value ) use ( &$writes ) {
			if ( 'elementor_pro_theme_builder_conditions' === $name ) {
				$writes[] = $value;
			}
		};

		$call();

		return $writes;
	}

	/**
	 * A write is "wrong-axis" when any top-level key looks like a condition
	 * string (contains "/", e.g. "include/general") rather than a Theme
	 * Builder location (e.g. "header") — the exact shape
	 * update_global_conditions_index() used to write.
	 */
	private function assertNoWriteIsWrongAxis( array $writes ): void {
		foreach ( $writes as $i => $write ) {
			$this->assertIsArray( $write, sprintf( 'Write #%d must be an array.', $i ) );

			foreach ( array_keys( $write ) as $key ) {
				$this->assertStringNotContainsString(
					'/',
					(string) $key,
					sprintf(
						'Write #%d used a condition-string key ("%s") as a TOP-LEVEL key — the wrong axis. Elementor Pro\'s real option is { location => { template_id => [condition_strings] } }, location first.',
						$i,
						$key
					)
				);
			}
		}
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

		$writes = $this->record_condition_option_writes(
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

		$this->assertNotEmpty(
			$writes,
			'set_template_conditions() must actually write the option through Elementor Pro\'s own manager for this test to mean anything.'
		);
		$this->assertNoWriteIsWrongAxis( $writes );
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

		$builder = new Mcpwp_Theme_Builder();

		$writes = $this->record_condition_option_writes(
			function () use ( $builder ) {
				$builder->remove_from_locations( 9 );
			}
		);

		$this->assertNoWriteIsWrongAxis( $writes );
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

		$writes = $this->record_condition_option_writes(
			function () use ( $controller, $request ) {
				$response = $controller->create_custom_code( $request );

				$this->assertSame( 201, $response->get_status(), 'The happy path must still succeed.' );
				$this->assertSame( 'elementor_pro_conditions_manager', $response->get_data()['snippet']['conditions_engine'] );
			}
		);

		$this->assertNotEmpty(
			$writes,
			'save_custom_code_conditions() must actually write the option through Elementor Pro\'s own manager for this test to mean anything.'
		);
		$this->assertNoWriteIsWrongAxis( $writes );
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
}
