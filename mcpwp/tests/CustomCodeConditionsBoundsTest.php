<?php

use PHPUnit\Framework\TestCase;

final class CustomCodeConditionsBoundsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['wpdb']->last_error = '';
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
		foreach ( array( 'request_skipped' => array(), 'cache_bypass' => false, 'raw_read_depth' => 0, 'inspection' => array(), 'condition_lengths' => array(), 'meta_cache_guard' => false ) as $name => $value ) {
			$property = new ReflectionProperty( Mcpwp_Custom_Code_Conditions::class, $name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
	}

	private function snippet( $id ) {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'elementor_snippet', 'post_status' => 'publish' ) );
	}

	public function test_legacy_one_hundred_fifty_thousand_conditions_are_quarantined_from_a_length_probe(): void {
		$this->snippet( 682 );
		// The test intentionally does not construct 150,000 PHP values. The
		// database length response is the proof that inspect rejects before
		// metadata hydration can allocate that historic payload.
		$reads = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) use ( &$reads ) {
			if ( 'get_var' === $method && false !== strpos( $query, 'OCTET_LENGTH(meta_value)' ) ) {
				++$reads;
				return 6815744;
			}
			if ( 'get_var' === $method && false !== strpos( $query, 'meta_value FROM' ) ) {
				throw new RuntimeException( 'oversize payload was fetched' );
			}
			return MCPWP_TEST_WPDB_PASS;
		};

		$result = Mcpwp_Custom_Code_Conditions::inspect( 682 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'conditions_too_large', $result->get_error_code() );
		$this->assertSame( 413, $result->get_error_data()['status'] );
		$this->assertSame( 1, $reads );
		$this->assertSame( 'conditions_too_large', Mcpwp_Custom_Code_Conditions::quarantine_reason( 682 ) );
		$this->assertSame( array(), Mcpwp_Custom_Code_Conditions::raw_conditions( 682 ) );
	}

	public function test_bounds_are_all_or_nothing_before_cache_entry_work(): void {
		$conditions = array_fill( 0, Mcpwp_Custom_Code_Conditions::MAX_CONDITIONS + 1, 'include/general' );
		$result = Mcpwp_Custom_Code_Conditions::convert( $conditions );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'conditions_too_large', $result->get_error_code() );
		$this->assertSame( 'conditions_too_large', $result->get_error_data()['reason'] );
	}

	public function test_admin_report_records_the_size_reason_without_hashing_the_payload(): void {
		$this->snippet( 682 );
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( 'get_var' === $method && false !== strpos( $query, 'OCTET_LENGTH(meta_value)' ) ) {
				return Mcpwp_Custom_Code_Conditions::MAX_SERIALIZED_BYTES + 1;
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		Mcpwp_Custom_Code_Conditions::quarantine( 682, Mcpwp_Custom_Code_Conditions::inspect( 682 ) );
		$this->assertSame( 'conditions_too_large', get_post_meta( 682, Mcpwp_Custom_Code_Conditions::REASON_META, true ) );
		$this->assertContains( 682, Mcpwp_Custom_Code_Conditions::skipped_ids() );
	}
}
