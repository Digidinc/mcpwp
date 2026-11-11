<?php

use PHPUnit\Framework\TestCase;

final class CustomCodeCacheConvergenceTest extends TestCase {
	private $error_log;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$this->error_log = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
		ini_set( 'error_log', $this->error_log );
	}

	private function create() {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$property = new ReflectionProperty( Mcpwp_REST_Elementor_Pro::class, 'current_api_key_record' );
		$property->setAccessible( true );
		$property->setValue( $controller, array( 'role' => 'admin', 'scopes' => array( 'admin' ) ) );
		return $controller->create_custom_code( new WP_REST_Request( 'POST', '/', array(
			'title' => 'Convergent', 'code' => '<div>Convergent</div>', 'status' => 'publish',
			'conditions' => array( array( 'type' => 'include', 'name' => 'general' ) ),
		) ) );
	}

	private function drop_entry( $id ) {
		$key = Mcpwp_Custom_Code_Cache_Entry::row_name( $id );
		unset( $GLOBALS['mcpwp_test_wpdb_options'][ $key ], $GLOBALS['mcpwp_test_options'][ $key ] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_interleaved_rebuild_is_retried_until_the_expected_entry_is_persisted(): void {
		$reads = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept'] = function ( $method, $query ) use ( &$reads ) {
			if ( 'get_row' === $method && false !== strpos( $query, Mcpwp_Custom_Code_Cache_Entry::row_name( 1 ) ) && 'publish' === get_post_status( 1 ) ) {
				if ( ++$reads === 2 ) { $this->drop_entry( 1 ); }
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$response = $this->create();
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$id = $response->get_data()['id'];
		$this->assertSame( array( 'include/general' ), Mcpwp_Custom_Code_Cache_Entry::entry( $id )['conditions'] ?? null );
		$this->assertGreaterThanOrEqual( 4, $reads, 'The dropped verification forces a fresh attempt.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider persistent_failure_provider
	 */
	public function test_persistent_failure_is_a_bounded_partial_write( $mode ): void {
		$writes = 0;
		$GLOBALS['mcpwp_test_wpdb_intercept'] = function ( $method, $query ) use ( $mode, &$writes ) {
			if ( 'publish' === get_post_status( 1 ) && false !== strpos( $query, Mcpwp_Custom_Code_Cache_Entry::row_name( 1 ) ) ) {
				if ( 'get_row' === $method ) {
					if ( 'throw' === $mode ) { throw new RuntimeException( 'forced cache failure' ); }
					$this->drop_entry( 1 );
				}
				if ( 'query' === $method && 0 === strpos( $query, 'UPDATE ' ) ) { ++$writes; }
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$response = $this->create();
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'conditions_cache_sync_failed', $response->get_error_code() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertTrue( $response->get_error_data()['partial_write'] );
		$this->assertSame( 'publish', get_post_status( $response->get_error_data()['resource_id'] ) );
		$this->assertSame( 3, $response->get_error_data()['attempts'] );
		$this->assertLessThanOrEqual( 3, $writes );
	}

	public function persistent_failure_provider(): array { return array( array( 'always' ), array( 'throw' ) ); }

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_creates_never_claim_the_obsolete_global_lock(): void {
		$this->assertInstanceOf( WP_REST_Response::class, $this->create() );
		$this->assertFalse( get_option( 'mcpwp_custom_code_cache_write_lock', false ) );
		$this->assertArrayNotHasKey( 'mcpwp_custom_code_cache_write_lock', $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_successful_writes_do_not_scan_the_full_snippet_set(): void {
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( 'get_col' === $method && false !== strpos( $query, "post_type = 'elementor_snippet'" ) ) {
				throw new RuntimeException( 'A write tried to scan every snippet.' );
			}
			return MCPWP_TEST_WPDB_PASS;
		};
		$this->assertInstanceOf( WP_REST_Response::class, $this->create() );
	}
}
