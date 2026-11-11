<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-custom-code-cache-entry.php';

final class CustomCodeCacheEntryTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$GLOBALS['mcpwp_test_wpdb_before_update'] = null;
		unset( $GLOBALS['mcpwp_test_wpdb_read_fails'] );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
		$GLOBALS['mcpwp_test_wpdb_before_update'] = null;
		unset( $GLOBALS['mcpwp_test_wpdb_read_fails'] );
	}

	private function raw_cache( array $cache ) {
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::OPTION ] = maybe_serialize( $cache );
	}

	private function cache() {
		return maybe_unserialize( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::OPTION ] ?? '' );
	}

	public function test_sync_preserves_other_entries_and_moves_only_its_own_id(): void {
		$this->raw_cache( array(
			'elementor_head' => array( 11 => array( 'include/general' ), 22 => array( 'exclude/general' ) ),
			'elementor_body_end' => array( 11 => array( 'include/general' ) ),
		) );
		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::sync( 11, 'elementor_footer', array( 'include/singular/page/5' ) ) );
		$cache = $this->cache();
		$this->assertArrayNotHasKey( 11, $cache['elementor_head'] );
		$this->assertArrayNotHasKey( 11, $cache['elementor_body_end'] );
		$this->assertSame( array( 'exclude/general' ), $cache['elementor_head'][22] );
		$this->assertSame( array( 'include/singular/page/5' ), $cache['elementor_footer'][11] );
		$this->assertArrayNotHasKey( 'mcpwp_custom_code_cache_write_lock', $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	public function test_interleaved_drop_retries_and_restores_its_entry(): void {
		$this->raw_cache( array( 'elementor_head' => array( 22 => array( 'include/general' ) ) ) );
		$once = true;
		$GLOBALS['mcpwp_test_wpdb_before_update'] = function () use ( &$once ) {
			if ( ! $once ) {
				return;
			}
			$once = false;
			$cache = maybe_unserialize( $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::OPTION ] );
			unset( $cache['elementor_head'][11] );
			$cache['elementor_head'][33] = array( 'include/general' );
			$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::OPTION ] = maybe_serialize( $cache );
		};
		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::sync( 11, 'elementor_head', array( 'include/general' ) ) );
		$cache = $this->cache();
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][11] );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][22] );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][33] );
	}

		public function test_an_already_matching_entry_succeeds_without_a_compare_and_swap(): void {
		$this->raw_cache( array( 'elementor_head' => array( 11 => array( 'include/general' ), 22 => array( 'include/general' ) ) ) );
		$writes = 0;
		$hook = null;
		$hook = static function () use ( &$hook, &$writes ) {
			++$writes;
			$GLOBALS['mcpwp_test_wpdb_before_update'] = $hook;
			throw new RuntimeException( 'CAS must not run for an identical entry.' );
		};
		$GLOBALS['mcpwp_test_wpdb_before_update'] = $hook;
		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::sync( 11, 'elementor_head', array( 'include/general' ) ) );
		$this->assertSame( 0, $writes );

	}

	public function test_persistent_compare_and_swap_conflict_returns_partial_write_after_three_attempts(): void {
		$this->raw_cache( array() );
		$hook = null;
		$changes = 0;
		$hook = function () use ( &$hook, &$changes ) {
			++$changes;
			$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::OPTION ] = maybe_serialize( array( 'elementor_head' => array( 99 + $changes => array( 'include/general' ) ) ) );
			$GLOBALS['mcpwp_test_wpdb_before_update'] = $hook;
		};
		$GLOBALS['mcpwp_test_wpdb_before_update'] = $hook;
		$result = Mcpwp_Custom_Code_Cache_Entry::sync( 11, 'elementor_head', array( 'include/general' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'conditions_cache_sync_failed', $result->get_error_code() );
		$this->assertSame( 11, $result->get_error_data()['resource_id'] );
		$this->assertTrue( $result->get_error_data()['partial_write'] );
		$this->assertSame( 3, $result->get_error_data()['attempts'] );
	}

	public function test_remove_prunes_only_an_empty_target_location_and_preserves_peers(): void {
		$this->raw_cache(
			array(
				'elementor_head'     => array( 11 => array( 'include/general' ) ),
				'elementor_body_end' => array( 22 => array( 'exclude/general' ), 11 => array( 'include/general' ) ),
			)
		);
		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::remove( 11 ) );
		$cache = $this->cache();
		$this->assertArrayNotHasKey( 'elementor_head', $cache );
		$this->assertSame( array( 'exclude/general' ), $cache['elementor_body_end'][22] );
		$this->assertArrayNotHasKey( 11, $cache['elementor_body_end'] );
	}
	public function test_a_transient_raw_read_failure_is_retried(): void {
		$this->raw_cache( array() );
		$reads = 0;
		$GLOBALS['mcpwp_test_wpdb_read_fails'] = static function ( $name ) use ( &$reads ) {
			return Mcpwp_Custom_Code_Cache_Entry::OPTION === $name && 1 === ++$reads;
		};
		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::sync( 11, 'elementor_head', array( 'include/general' ) ) );
		$this->assertSame( 3, $reads, 'A fresh read and verification follow the failed read.' );
	}

}
