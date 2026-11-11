<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-custom-code-cache-entry.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-custom-code-cache-view.php';

final class CustomCodeCacheViewTest extends TestCase {
	private $fingerprints = array();
	private $queries = array();

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$this->fingerprints = array( '2:0000000000000001:0000000000000002' );
		$this->queries = array();
		$GLOBALS['mcpwp_test_wpdb_intercept'] = function ( $method, $query ) {
			if ( 'get_row' !== $method ) {
				return MCPWP_TEST_WPDB_PASS;
			}
			if ( false !== strpos( $query, 'mcpwp_custom_code_view_rows' ) ) {
				$fp = reset( $this->fingerprints );
				if ( false === $fp ) {
					return null;
				}
				$parts = explode( ':', $fp );
				return array( 'row_count' => $parts[0], 'xor_a' => $parts[1], 'xor_b' => $parts[2] );
			}
			if ( false !== strpos( $query, Mcpwp_Custom_Code_Cache_View::OPTION ) ) {
				$this->queries[] = $query;
				$raw = $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_View::OPTION ] ?? ( isset( $GLOBALS['mcpwp_test_options'][ Mcpwp_Custom_Code_Cache_View::OPTION ] ) ? maybe_serialize( $GLOBALS['mcpwp_test_options'][ Mcpwp_Custom_Code_Cache_View::OPTION ] ) : null );
				return null === $raw ? null : array( 'option_value' => $raw, 'snapshot_bytes' => strlen( $raw ) );
			}
			return MCPWP_TEST_WPDB_PASS;
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
	}

	public function test_read_hits_only_when_base_and_source_generation_match(): void {
		$base = array( 'elementor_head' => array( 9 => array( 'include/general' ) ) );
		$fp = Mcpwp_Custom_Code_Cache_View::fingerprint();
		$this->assertTrue( Mcpwp_Custom_Code_Cache_View::store( $base, array( 'elementor_head' => array( 9 => array( 'include/general' ) ) ), array( 7 ), array( 8 ), $fp ) );
		$this->assertTrue( Mcpwp_Custom_Code_Cache_View::read( $base )['hit'] );
		Mcpwp_Custom_Code_Cache_View::invalidate();
		$this->assertFalse( Mcpwp_Custom_Code_Cache_View::read( $base )['hit'], 'WordPress source mutations invalidate the view.' );
		$this->fingerprints = array( $fp );
		$this->assertTrue( Mcpwp_Custom_Code_Cache_View::store( $base, array( 'elementor_head' => array( 9 => array( 'include/general' ) ) ), array( 7 ), array( 8 ), $fp ) );
		$this->assertFalse( Mcpwp_Custom_Code_Cache_View::read( array( 'changed' => true ) )['hit'], 'Base input changes invalidate the view.' );

	}

	public function test_store_writes_only_after_a_stable_fingerprint_and_read_never_mutates_options(): void {
		$base = array( 'base' => true );
		$before = Mcpwp_Custom_Code_Cache_View::fingerprint();
		$this->fingerprints = array( '3:0000000000000001:0000000000000002' );
		$this->assertFalse( Mcpwp_Custom_Code_Cache_View::store( $base, array(), array(), array(), $before ) );
		$this->assertArrayNotHasKey( Mcpwp_Custom_Code_Cache_View::OPTION, $GLOBALS['mcpwp_test_options'] );
		$this->assertFalse( Mcpwp_Custom_Code_Cache_View::read( $base )['hit'] );
		$this->assertArrayNotHasKey( Mcpwp_Custom_Code_Cache_View::OPTION, $GLOBALS['mcpwp_test_options'], 'Read is never an option mutation.' );
	}

	public function test_nested_condition_arrays_in_a_snapshot_are_rejected_without_reaching_pro(): void {
		$base = array();
		$fp = Mcpwp_Custom_Code_Cache_View::fingerprint();
		$this->assertTrue( Mcpwp_Custom_Code_Cache_View::store( $base, array(), array(), array(), $fp ) );
		$snapshot = $GLOBALS['mcpwp_test_options'][Mcpwp_Custom_Code_Cache_View::OPTION];
		$snapshot['cache'] = array( 'elementor_head' => array( 9 => array( array('type'=>'include','name'=>'general') ) ) );
		$GLOBALS['mcpwp_test_options'][Mcpwp_Custom_Code_Cache_View::OPTION] = $snapshot;
		$this->assertFalse( Mcpwp_Custom_Code_Cache_View::read( $base )['hit'] );
	}

	public function test_snapshot_query_is_bounded_and_store_uses_nonautoload_option(): void {
		$base = array( 'base' => true );
		$fp = Mcpwp_Custom_Code_Cache_View::fingerprint();
		$this->assertTrue( Mcpwp_Custom_Code_Cache_View::store( $base, array(), array(), array(), $fp ) );
		$this->assertSame( false, $GLOBALS['mcpwp_test_options_autoload'][ Mcpwp_Custom_Code_Cache_View::OPTION ] );
		$this->assertTrue( Mcpwp_Custom_Code_Cache_View::read( $base )['hit'] );
		$query = implode( "\n", $this->queries );
		$this->assertStringContainsString( 'OCTET_LENGTH(v.option_value) <= 1048576', $query );
	}
}
