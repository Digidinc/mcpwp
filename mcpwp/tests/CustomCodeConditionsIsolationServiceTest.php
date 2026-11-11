<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-custom-code-conditions.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-custom-code-cache-entry.php';

final class CustomCodeConditionsIsolationServiceTest extends TestCase {
	private $error_log;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$GLOBALS['mcpwp_test_user_caps'] = array( 1 => array( 'manage_options' => true ) );
		delete_option( Mcpwp_Custom_Code_Conditions::REPORT_OPTION );
		delete_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION );
		delete_option( 'mcpwp_custom_code_cache_repair_failed_ids' );
		$GLOBALS['wpdb']->last_error = '';
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
		foreach ( array( 'request_skipped' => array(), 'cache_bypass' => false, 'raw_read_depth' => 0, 'inspection' => array(), 'condition_lengths' => array(), 'meta_cache_guard' => false, 'legacy_cache_too_large' => false, 'entry_overlay' => null, 'request_invalid_entries' => array() ) as $property_name => $value ) {
			$property = new ReflectionProperty( Mcpwp_Custom_Code_Conditions::class, $property_name );
			$property->setAccessible( true );
			$property->setValue( null, $value );
		}
		$this->error_log = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->error_log );
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
	}

	private function snippet( $id, $conditions ) {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'elementor_snippet', 'post_status' => 'publish' ) );
		update_post_meta( $id, '_elementor_conditions', $conditions );
	}

	public function test_converter_is_all_or_nothing_for_maps_and_strings(): void {
		$this->assertSame( array( 'include/general', 'exclude/singular/page/5' ), Mcpwp_Custom_Code_Conditions::convert( array( 'include/general', array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Custom_Code_Conditions::convert( array( 'include/general', array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5 ' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Mcpwp_Custom_Code_Conditions::convert( array( 'include/singular/page/5/extra' ) ) );
	}

	public function test_converter_refuses_more_than_two_hundred_conditions_or_sixteen_kib(): void {
		$many = array_fill( 0, Mcpwp_Custom_Code_Conditions::MAX_CONDITIONS + 1, 'include/general' );
		$too_many = Mcpwp_Custom_Code_Conditions::convert( $many );
		$this->assertInstanceOf( WP_Error::class, $too_many );
		$this->assertSame( 'conditions_too_large', $too_many->get_error_code() );

		$large = array( array( 'type' => 'include', 'name' => str_repeat( 'a', Mcpwp_Custom_Code_Conditions::MAX_SERIALIZED_BYTES ) ) );
		$this->assertSame( 'conditions_too_large', Mcpwp_Custom_Code_Conditions::convert( $large )->get_error_code() );
	}

	public function test_inspection_uses_the_length_probe_before_loading_an_oversize_value(): void {
		$this->snippet( 15, array( str_repeat( 'x', Mcpwp_Custom_Code_Conditions::MAX_SERIALIZED_BYTES + 1 ) ) );
		$probes = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) use ( &$probes ) {
			if ( 'get_var' === $method && false !== strpos( $query, 'OCTET_LENGTH(meta_value)' ) ) {
				++$probes;
				return Mcpwp_Custom_Code_Conditions::MAX_SERIALIZED_BYTES + 1;
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$result = Mcpwp_Custom_Code_Conditions::inspect( 15 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'conditions_too_large', $result->get_error_code() );
		$this->assertSame( 1, $probes );
		$this->assertSame( array(), Mcpwp_Custom_Code_Conditions::raw_conditions( 15 ) );

		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'elementor_head' => array( 15 => array( 'include/general' ) ) ) );
		$this->assertArrayNotHasKey( 15, $cache['elementor_head'] ?? array() );
	}

	public function test_oversize_meta_cache_priming_installs_a_nonempty_deny_view_without_hydrating_raw_meta(): void {
		$this->snippet( 15, array( str_repeat( 'x', Mcpwp_Custom_Code_Conditions::MAX_SERIALIZED_BYTES + 1 ) ) );
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( 'get_var' === $method && false !== strpos( $query, 'OCTET_LENGTH(meta_value)' ) ) {
				return Mcpwp_Custom_Code_Conditions::MAX_SERIALIZED_BYTES + 1;
			}
			if ( 'get_results' === $method && false !== strpos( $query, 'OCTET_LENGTH(meta_value)' ) ) {
				return array( array( 'post_id' => '15', 'bytes' => (string) ( Mcpwp_Custom_Code_Conditions::MAX_SERIALIZED_BYTES + 1 ), 'meta_value' => null ) );
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$this->assertTrue( Mcpwp_Custom_Code_Conditions::filter_oversized_meta_cache_prime( null, array( 15 ) ) );
		$cached = wp_cache_get( 15, 'post_meta' );
		$this->assertSame( array( 'exclude/general' ), maybe_unserialize( $cached['_elementor_conditions'][0] ) );
		$this->assertSame( array( '' ), $cached['_elementor_code'] );
	}

	public function test_oversize_legacy_cache_option_is_rejected_before_unserialization(): void {
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( 'get_var' === $method && false !== strpos( $query, 'OCTET_LENGTH(option_value)' ) ) {
				return Mcpwp_Custom_Code_Conditions::MAX_LEGACY_CACHE_BYTES + 1;
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$this->assertSame( array(), Mcpwp_Custom_Code_Conditions::filter_oversized_legacy_cache_option( false, 'elementor_pro_theme_builder_conditions', false ) );
		$this->assertTrue( Mcpwp_Custom_Code_Conditions::legacy_cache_too_large() );
	}

	public function test_per_entry_rows_overlay_the_legacy_cache_without_rewriting_it(): void {
		$this->snippet( 15, array( 'include/general' ) );
		$this->snippet( 16, array( 'include/general' ) );
		update_post_meta( 15, '_elementor_location', 'elementor_head' );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 15 ) ] = maybe_serialize(
			array( 'id' => 15, 'location' => 'elementor_body_end', 'conditions' => array( 'include/singular/page/5' ) )
		);
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 16 ) ] = maybe_serialize( array( 'id' => 16, 'deleted' => true ) );
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( 'get_results' === $method && false !== strpos( $query, 'option_name LIKE' ) ) {
				return array(
					array( 'option_name' => Mcpwp_Custom_Code_Cache_Entry::row_name( 15 ), 'option_value' => $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 15 ) ] ),
					array( 'option_name' => Mcpwp_Custom_Code_Cache_Entry::row_name( 16 ), 'option_value' => $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 16 ) ] ),
				);
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache(
			array( 'elementor_head' => array( 15 => array( 'include/general' ), 16 => array( 'include/general' ), 99 => array( 'include/general' ) ) )
		);
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][15] );
		$this->assertArrayNotHasKey( 16, $cache['elementor_head'] );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][99] );
	}

	public function test_per_entry_overlay_never_reenables_an_entry_after_its_meta_becomes_oversized(): void {
		$this->snippet( 15, array( 'include/general' ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 15 ) ] = maybe_serialize(
			array( 'id' => 15, 'location' => 'elementor_head', 'conditions' => array( 'include/general' ) )
		);
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( 'get_results' === $method && false !== strpos( $query, 'option_name LIKE' ) ) {
				return array( array( 'option_name' => Mcpwp_Custom_Code_Cache_Entry::row_name( 15 ), 'option_value' => $GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 15 ) ] ) );
			}
			if ( 'get_var' === $method && false !== strpos( $query, 'OCTET_LENGTH(meta_value)' ) ) {
				return Mcpwp_Custom_Code_Conditions::MAX_SERIALIZED_BYTES + 1;
			}
			if ( 'get_results' === $method && false !== strpos( $query, 'OCTET_LENGTH(meta_value)' ) ) {
				return array( array( 'post_id' => '15', 'bytes' => (string) ( Mcpwp_Custom_Code_Conditions::MAX_SERIALIZED_BYTES + 1 ), 'meta_value' => null ) );
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'elementor_head' => array( 15 => array( 'include/general' ) ) ) );
		$this->assertArrayNotHasKey( 15, $cache['elementor_head'] ?? array() );
		$this->assertContains( 15, Mcpwp_Custom_Code_Conditions::skipped_ids() );
	}

	public function test_native_library_conditions_update_removes_a_stale_per_entry_override(): void {
		$GLOBALS['_mcpwp_test_posts'][44] = new WP_Post( array( 'ID' => 44, 'post_type' => 'elementor_library', 'post_status' => 'publish' ) );
		$GLOBALS['mcpwp_test_wpdb_options'][ Mcpwp_Custom_Code_Cache_Entry::row_name( 44 ) ] = maybe_serialize(
			array( 'id' => 44, 'location' => 'header', 'conditions' => array( 'include/general' ) )
		);
		update_post_meta( 44, '_elementor_conditions', array( 'exclude/singular/page/5' ) );
		Mcpwp_Custom_Code_Conditions::invalidate_report_on_meta( 1, 44, '_elementor_conditions' );
		$this->assertArrayNotHasKey( Mcpwp_Custom_Code_Cache_Entry::row_name( 44 ), $GLOBALS['mcpwp_test_wpdb_options'] );
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'header' => array( 44 => array( 'exclude/singular/page/5' ) ) ) );
		$this->assertSame( array( 'exclude/singular/page/5' ), $cache['header'][44] );
	}

	public function test_missing_conditions_meta_is_not_quarantined_or_replaced_with_an_empty_entry(): void {
		$GLOBALS['_mcpwp_test_posts'][15] = new WP_Post( array( 'ID' => 15, 'post_type' => 'elementor_snippet', 'post_status' => 'publish' ) );
		$inspection = Mcpwp_Custom_Code_Conditions::inspect( 15 );
		$this->assertIsArray( $inspection );
		$this->assertTrue( $inspection['missing'] );
		$this->assertSame( array(), Mcpwp_Custom_Code_Conditions::skipped_ids() );
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'elementor_head' => array( 15 => array( 'include/general' ) ) ) );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][15] );
	}

	public function test_cache_view_query_count_is_near_constant_from_fifty_to_five_hundred_entries(): void {
		$measure = function ( $count ) {
			$GLOBALS['_mcpwp_test_posts'] = array();
			$GLOBALS['_mcpwp_test_meta'] = array();
			foreach ( array( 'entry_overlay' => null, 'inspection' => array(), 'condition_lengths' => array(), 'request_invalid_entries' => array() ) as $name => $value ) {
				$property = new ReflectionProperty( Mcpwp_Custom_Code_Conditions::class, $name );
				$property->setAccessible( true );
				$property->setValue( null, $value );
			}
			$cache = array( 'elementor_head' => array() );
			for ( $id = 1; $id <= $count; ++$id ) {
				$this->snippet( $id, array( 'include/general' ) );
				$cache['elementor_head'][ $id ] = array( 'include/general' );
			}
			$queries = 0;
			$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) use ( &$queries ) {
				if ( in_array( $method, array( 'get_results', 'get_var', 'get_row', 'get_col', 'query' ), true ) ) {
					++$queries;
				}
				return MCPWP_TEST_WPDB_PASS;
			};
			$result = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( $cache );
			$this->assertCount( $count, $result['elementor_head'] );
			Mcpwp_Custom_Code_Conditions::filter_oversized_meta_cache_prime( null, range( 1, $count ) );
			foreach ( range( 1, $count ) as $id ) {
				Mcpwp_Custom_Code_Conditions::filter_oversized_snippet_meta( null, $id, '_elementor_code', true, 'post' );
			}
			$first_queries = $queries;
			Mcpwp_Custom_Code_Conditions::filter_conditions_cache( $cache );
			$this->assertSame( $first_queries, $queries, 'Unchanged option reads reuse request inspection and overlay.' );
			return $queries;
		};
		$small = $measure( 50 );
		$large = $measure( 500 );
		$this->assertLessThanOrEqual( $small + 5, $large, 'Normal cache reads must use bounded batches, not one query per entry.' );
		$this->assertLessThanOrEqual( 6, $small );
	}

	public function test_same_request_meta_changes_replace_conditions_and_location(): void {
		Mcpwp_Custom_Code_Conditions::register_hooks();
		$this->snippet( 15, array( 'include/general' ) );
		update_post_meta( 15, '_elementor_location', 'elementor_head' );
		$this->assertTrue( Mcpwp_Custom_Code_Cache_Entry::sync( 15, 'elementor_head', array( 'include/general' ) ) );
		$first = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() );
		$this->assertSame( array( 'include/general' ), $first['elementor_head'][15] );
		update_post_meta( 15, '_elementor_conditions', array( 'exclude/general' ) );
		update_post_meta( 15, '_elementor_location', 'elementor_body_end' );
		$second = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array() );
		$this->assertSame( array( 'exclude/general' ), $second['elementor_body_end'][15] );
		$this->assertArrayNotHasKey( 15, $second['elementor_head'] ?? array() );
	}

	public function test_cache_filter_removes_one_bad_snippet_without_shortening_or_touching_others(): void {
		$this->snippet( 15, array( 'include/general', array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5 ' ) ) );
		$this->snippet( 16, array( 'include/general' ) );
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'elementor_head' => array( 15 => array( 'include/general' ), 16 => array( 'include/general' ), 99 => array( 'include/general' ) ) ) );
		$this->assertArrayNotHasKey( 15, $cache['elementor_head'] );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][16] );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][99] );
	}

	public function test_regenerate_query_excludes_bad_and_unpersisted_legacy_entries(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		$this->snippet( 16, array( 'include/general' ) );
		$args = Mcpwp_Custom_Code_Conditions::filter_regenerate_query( array( 'post__not_in' => array( 99 ) ) );
		$this->assertSame( array( 99 ), $args['post__not_in'], 'A cold report preserves callers\' exclusions and avoids a full scan; Pro-only metadata isolation handles late records.' );
		$this->assertNotContains( 16, $args['post__not_in'] );
		$this->assertContains( 99, $args['post__not_in'] );
	}

	public function test_raw_reader_and_quarantine_report_without_mutating_the_shared_cache(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'elementor_head' => array( 15 => array( 'include/general' ), 99 => array( 'include/general' ) ) ) );
		$this->assertSame( array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ), Mcpwp_Custom_Code_Conditions::raw_conditions( 15 ) );
		Mcpwp_Custom_Code_Conditions::quarantine( 15, Mcpwp_Custom_Code_Conditions::raw_conditions( 15 ) );
		$this->assertArrayHasKey( 15, $GLOBALS['mcpwp_test_options']['elementor_pro_theme_builder_conditions']['elementor_head'] );
		$this->assertSame( array( 'include/general' ), $GLOBALS['mcpwp_test_options']['elementor_pro_theme_builder_conditions']['elementor_head'][99] );
		$this->assertSame( array( 15 ), Mcpwp_Custom_Code_Conditions::annotate_response( array() )['skipped_custom_code_snippet_ids'] );
	}

	public function test_quarantine_logs_one_exact_id_per_fingerprint_and_does_not_rewrite_registry(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		$log = tempnam( sys_get_temp_dir(), 'mcpwp-conditions-' );
		ini_set( 'error_log', $log );
		try {
			$raw = Mcpwp_Custom_Code_Conditions::raw_conditions( 15 );
			Mcpwp_Custom_Code_Conditions::quarantine( 15, $raw );
			$first = get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION );
			Mcpwp_Custom_Code_Conditions::quarantine( 15, $raw );
			$this->assertSame( $first, get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION ) );
		$this->assertGreaterThanOrEqual( 1, substr_count( file_get_contents( $log ), 'snippet 15' ) );
		} finally {
			unlink( $log );
		}
	}

	public function test_admin_notice_and_site_health_name_only_current_unsafe_ids(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		Mcpwp_Custom_Code_Conditions::quarantine( 15, Mcpwp_Custom_Code_Conditions::raw_conditions( 15 ) );
		ob_start();
		Mcpwp_Custom_Code_Conditions::render_admin_notice();
		$notice = ob_get_clean();
		$this->assertStringContainsString( '15', $notice );
		$health = Mcpwp_Custom_Code_Conditions::site_health_test();
		$this->assertSame( 'recommended', $health['status'] );
		$this->assertStringContainsString( '15', $health['description'] );
		update_post_meta( 15, '_elementor_conditions', array( 'include/general' ) );
		Mcpwp_Custom_Code_Conditions::clear_quarantine( 15 );
		$this->assertSame( 'good', Mcpwp_Custom_Code_Conditions::site_health_test()['status'] );
	}

	public function test_query_guard_repairs_convertible_raw_meta_before_rebuild_and_preserves_raw_on_cache_read(): void {
		$this->snippet( 16, array( array( 'type' => 'include', 'name' => 'general' ) ) );
		$raw = Mcpwp_Custom_Code_Conditions::raw_conditions( 16 );
		$cache = Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'elementor_head' => array( 16 => $raw ) ) );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][16] );
		$this->assertSame( $raw, Mcpwp_Custom_Code_Conditions::raw_conditions( 16 ) );
		$guarded = Mcpwp_Custom_Code_Conditions::filter_regenerate_query( array() );
		$this->assertArrayNotHasKey( 'post__not_in', $guarded );
		$this->assertSame( $raw, Mcpwp_Custom_Code_Conditions::raw_conditions( 16 ) );
	}

	public function test_admin_refresh_records_late_unsafe_ids_once_while_cache_reads_stay_write_free(): void {
		$this->snippet( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		Mcpwp_Custom_Code_Conditions::filter_conditions_cache( array( 'elementor_head' => array( 15 => array( 'include/general' ) ) ) );
		$this->assertFalse( get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION, false ) );
		$log = tempnam( sys_get_temp_dir(), 'mcpwp-conditions-admin-' );
		ini_set( 'error_log', $log );
		try {
			Mcpwp_Custom_Code_Conditions::refresh_admin_report();
			$first = get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION );
			Mcpwp_Custom_Code_Conditions::refresh_admin_report();
			$this->assertSame( $first, get_option( Mcpwp_Custom_Code_Conditions::QUARANTINE_OPTION ) );
			$this->assertGreaterThanOrEqual( 1, substr_count( file_get_contents( $log ), 'snippet 15' ) );
			ob_start();
			Mcpwp_Custom_Code_Conditions::render_admin_notice();
			$this->assertStringContainsString( '15', ob_get_clean() );
		} finally {
			unlink( $log );
		}
	}
}
