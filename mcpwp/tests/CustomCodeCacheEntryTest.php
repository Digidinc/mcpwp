<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-custom-code-cache-entry.php';

final class CustomCodeCacheEntryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_wpdb_options']       = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$GLOBALS['mcpwp_test_wpdb_before_update'] = null;
		unset( $GLOBALS['mcpwp_test_wpdb_read_fails'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_before_insert'], $GLOBALS['mcpwp_test_wpdb_before_update'], $GLOBALS['mcpwp_test_wpdb_read_fails'], $GLOBALS['mcpwp_test_wpdb_intercept'] );
	}

	private function raw_entry( $id, $location = 'elementor_head', array $conditions = array( 'include/general' ) ) {
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( $id ) ] = maybe_serialize(
			array( 'id' => $id, 'location' => $location, 'conditions' => $conditions )
		);
	}

	public function test_sync_touches_only_its_small_private_row_and_preserves_peers(): void {
		$this->raw_entry( 22, 'elementor_body_end', array( 'exclude/general' ) );
		$global = array();
		for ( $id = 1; $id <= 1500; ++$id ) {
			$global['elementor_head'][ $id ] = array( 'include/general' );
		}
		$GLOBALS['mcpwp_test_wpdb_options']['elementor_pro_theme_builder_conditions'] = maybe_serialize( $global );

		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::sync( 11, 'elementor_footer', array( 'include/singular/page/5' ) ) );
		$entry = Mcpwp_Custom_Code_Cache_Entry::entry( 11 );
		$this->assertSame( 'elementor_footer', $entry['location'] );
		$this->assertSame( array( 'include/singular/page/5' ), $entry['conditions'] );
		$this->assertSame( 'elementor_body_end', Mcpwp_Custom_Code_Cache_Entry::entry( 22 )['location'] );
		$this->assertSame( maybe_serialize( $global ), $GLOBALS['mcpwp_test_wpdb_options']['elementor_pro_theme_builder_conditions'] );
		$queries = implode( "\n", $GLOBALS['wpdb']->queries );
		$this->assertLessThan( 4096, strlen( $queries ), 'Normal entry CAS SQL must not grow with the 1,500-entry legacy cache.' );
	}

	public function test_interleaved_same_entry_cas_retries_to_the_latest_write(): void {
		$this->raw_entry( 11 );
		$once = true;
		$GLOBALS['mcpwp_test_wpdb_before_update'] = function ( $name ) use ( &$once ) {
			if ( ! $once || Mcpwp_Custom_Code_Cache_Entry::row_name( 11 ) !== $name ) {
				return;
			}
			$once = false;
			$GLOBALS['mcpwp_test_wpdb_options'][ $name ] = maybe_serialize(
				array( 'id' => 11, 'location' => 'elementor_head', 'conditions' => array( 'exclude/general' ) )
			);
		};
		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::sync( 11, 'elementor_body_end', array( 'include/singular/page/5' ) ) );
		$this->assertSame( 'elementor_body_end', Mcpwp_Custom_Code_Cache_Entry::entry( 11 )['location'] );
	}

	public function test_oversized_conditions_are_refused_before_any_option_row_is_written(): void {
		$conditions = array_fill( 0, Mcpwp_Custom_Code_Cache_Entry::MAX_CONDITIONS + 1, 'include/general' );
		$result = Mcpwp_Custom_Code_Cache_Entry::sync( 11, 'elementor_head', $conditions );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'conditions_too_large', $result->get_error_code() );
		$this->assertSame( 413, $result->get_error_data()['status'] );
		$this->assertArrayNotHasKey( Mcpwp_Custom_Code_Cache_Entry::row_name( 11 ), $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	public function test_serialized_size_limit_rejects_few_but_large_conditions(): void {
		$conditions = array_fill( 0, 2, str_repeat( 'x', Mcpwp_Custom_Code_Cache_Entry::MAX_CONDITIONS_BYTES ) );
		$result = Mcpwp_Custom_Code_Cache_Entry::sync( 11, 'elementor_head', $conditions );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'conditions_too_large', $result->get_error_code() );
	}

	public function test_serialized_list_boundary_leaves_room_for_the_entry_envelope(): void {
		$fits = array( str_repeat( 'x', 16350 ) );
		$this->assertLessThanOrEqual( Mcpwp_Custom_Code_Cache_Entry::MAX_CONDITIONS_BYTES, strlen( maybe_serialize( $fits ) ) );
		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::sync( 101, 'elementor_head', $fits ) );
		$this->assertLessThanOrEqual(
			Mcpwp_Custom_Code_Cache_Entry::MAX_ENTRY_BYTES,
			strlen( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 101 ) ] )
		);

		$too_large = array( str_repeat( 'x', 16384 ) );
		$this->assertSame( 'conditions_too_large', Mcpwp_Custom_Code_Cache_Entry::sync( 102, 'elementor_head', $too_large )->get_error_code() );
	}

	public function test_remove_deletes_only_the_target_private_row(): void {
		$this->raw_entry( 11 );
		$this->raw_entry( 22, 'elementor_body_end' );
		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::remove( 11 ) );
		$this->assertNull( Mcpwp_Custom_Code_Cache_Entry::entry( 11 ) );
		$this->assertSame( 'elementor_body_end', Mcpwp_Custom_Code_Cache_Entry::entry( 22 )['location'] );
	}

	public function test_entries_use_one_bounded_query_for_a_large_overlay(): void {
		for ( $id = 1; $id <= 1200; ++$id ) {
			$this->raw_entry( $id );
		}
		$reads = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) use ( &$reads ) {
			if ( 'get_results' === $method ) {
				++$reads;
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$entries = Mcpwp_Custom_Code_Cache_Entry::entries();
		$this->assertCount( 1200, $entries['entries'] );
		$this->assertSame( 1, array_key_first( $entries['entries'] ) );
		$this->assertSame( 1200, array_key_last( $entries['entries'] ) );
		$this->assertSame( array(), $entries['invalid_ids'] );
		$this->assertSame( 1, $reads, 'A normal compatibility view reads all entry rows in one bounded query.' );
	}

	public function test_storage_initialization_flag_is_not_a_corrupt_document_row(): void {
		$this->raw_entry( 11 );
		update_option( Mcpwp_Custom_Code_Conditions::STORAGE_INITIALIZED_OPTION, 1, false );
		$result = Mcpwp_Custom_Code_Cache_Entry::entries();
		$this->assertSame( array(11), array_keys( $result['entries'] ) );
		$this->assertSame( array(), $result['invalid_ids'] );
	}

	public function test_oversize_or_malformed_private_rows_are_reported_without_loading_payloads(): void {
		$this->raw_entry( 11 );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 12 ) ] = str_repeat( 'x', Mcpwp_Custom_Code_Cache_Entry::MAX_ENTRY_BYTES + 1 );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 13 ) ] = maybe_serialize( array( 'id' => 13, 'location' => '' ) );
		$entries = Mcpwp_Custom_Code_Cache_Entry::entries();
		$this->assertArrayHasKey( 11, $entries['entries'] );
		$this->assertSame( array( 12, 13 ), $entries['invalid_ids'] );
	}
}
