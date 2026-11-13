<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mcpwp-activator.php';

final class CustomCodeConditionsRepairMigrationTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['_mcpwp_test_posts']             = array();
		$GLOBALS['_mcpwp_test_meta']              = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		Mcpwp_Custom_Code_Conditions::initialize_legacy_cache_storage();
		Mcpwp_Custom_Code_Conditions::register_hooks();
	}

	private function seed( int $id, array $conditions ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'          => $id,
			'post_type'   => 'elementor_snippet',
			'post_status' => 'publish',
		);
		update_post_meta( $id, '_elementor_conditions', $conditions );
	}

	public function test_converter_preserves_valid_strings_and_legacy_maps_without_broadening(): void {
		$result = Mcpwp_Activator::coerce_custom_code_conditions_to_strings(
			array(
				'include/general',
				array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '6' ),
			)
		);

		$this->assertSame( array( 'include/general', 'exclude/singular/page/6' ), $result );
		$this->assertInstanceOf(
			WP_Error::class,
			Mcpwp_Activator::coerce_custom_code_conditions_to_strings(
				array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '6' ) )
			)
		);
		$this->assertInstanceOf(
			WP_Error::class,
			Mcpwp_Activator::coerce_custom_code_conditions_to_strings(
				array( array( 'type' => 'include', 'name' => '<b>singular</b>' ) )
			)
		);
	}

	public function test_migration_repairs_legacy_arrays_and_preserves_existing_strings(): void {
		$this->seed( 1, array( array( 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '6' ) ) );
		$this->seed( 2, array( 'exclude/general' ) );

		$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions() );
		$this->assertSame( array( 'include/singular/page/6' ), get_post_meta( 1, '_elementor_conditions', true ) );
		$this->assertSame( array( 'exclude/general' ), get_post_meta( 2, '_elementor_conditions', true ) );
		$this->assertSame( 1, get_option( 'mcpwp_custom_code_conditions_repaired' ) );
	}

	public function test_migration_checkpoints_and_resumes_legacy_arrays(): void {
		for ( $id = 1; $id <= Mcpwp_Activator::CUSTOM_CODE_REPAIR_BATCH_SIZE + 1; $id++ ) {
			$this->seed( $id, array( array( 'type' => 'include', 'name' => 'general' ) ) );
		}

		$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions( 0.0 ) );
		$this->assertSame( Mcpwp_Activator::CUSTOM_CODE_REPAIR_BATCH_SIZE, get_option( Mcpwp_Activator::CUSTOM_CODE_CONDITIONS_REPAIR_CURSOR_OPTION ) );
		$this->assertFalse( get_option( 'mcpwp_custom_code_conditions_repaired', false ) );
		$this->assertSame( array( 'include/general' ), get_post_meta( 1, '_elementor_conditions', true ) );
		$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions( 30.0 ) );
		$this->assertSame( 1, get_option( 'mcpwp_custom_code_conditions_repaired' ) );
		$this->assertSame( array( 'include/general' ), get_post_meta( Mcpwp_Activator::CUSTOM_CODE_REPAIR_BATCH_SIZE + 1, '_elementor_conditions', true ) );
	}

	public function test_regenerate_guard_repairs_late_legacy_meta_even_after_migration_completed(): void {
		update_option( 'mcpwp_custom_code_conditions_repaired', 1, false );
		$this->seed( 682, array( array( 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '6' ) ) );

		$this->assertTrue( Mcpwp_Activator::ensure_custom_code_conditions_repaired() );
		$this->assertSame( array( 'include/singular/page/6' ), get_post_meta( 682, '_elementor_conditions', true ) );
	}

	public function test_migration_repairs_only_the_legacy_snippet_cache_entry(): void {
		$this->seed( 682, array( 'include/singular/page/6' ) );
		update_option(
			'elementor_pro_theme_builder_conditions',
			array(
				'elementor_head' => array(
					682 => array( array( 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '6' ) ),
					999 => array( 'exclude/general' ),
				),
			)
		);

		$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions() );
		$cache = get_option( 'elementor_pro_theme_builder_conditions' );
		$this->assertSame( array( 'include/singular/page/6' ), $cache['elementor_head'][682] );
		$this->assertSame( array( 'exclude/general' ), $cache['elementor_head'][999] );
	}

	public function test_unconvertible_legacy_entry_is_reported_without_blocking_repair(): void {
		$legacy = array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '6' ) );
		$this->seed( 682, $legacy );

		$result = Mcpwp_Activator::repair_custom_code_conditions();
		$this->assertTrue( $result );
		$this->assertContains( 682, Mcpwp_Custom_Code_Conditions::skipped_ids() );
		$this->assertSame( $legacy, get_post_meta( 682, '_elementor_conditions', true ) );
		$this->assertSame( 1, get_option( 'mcpwp_custom_code_conditions_repaired', false ) );
	}
}
