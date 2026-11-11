<?php

use PHPUnit\Framework\TestCase;

final class CustomCodeCacheWriteLockTest extends TestCase {
	private const LOCK = 'mcpwp_custom_code_cache_write_lock';
	private $previous_db_error;

	protected function setUp(): void {
		$this->previous_db_error = $GLOBALS['wpdb']->last_error ?? '';
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
	}

	protected function tearDown(): void {
		// Duplicate-claim errors are intentional in these tests. The fake
		// database does not reset them on every read as real wpdb does.
		$GLOBALS['wpdb']->last_error = $this->previous_db_error;
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_holds_a_shared_lock_while_pro_rebuilds_and_releases_afterward(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$manager = new class extends \ElementorPro\Modules\ThemeBuilder\Mcpwp_Fake_Conditions_Manager {
			public function save_conditions( $id, $conditions ) {
				$GLOBALS['cache_write_lock_observed'] = isset( $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_custom_code_cache_write_lock'] );
				return parent::save_conditions( $id, $conditions );
			}
		};
		$property = new ReflectionProperty( \ElementorPro\Modules\ThemeBuilder\Module::class, 'conditions_manager' );
		$property->setAccessible( true );
		$property->setValue( \ElementorPro\Modules\ThemeBuilder\Module::instance(), $manager );
		$response = ( new Mcpwp_REST_Elementor_Pro( new stdClass() ) )->create_custom_code( new WP_REST_Request( 'POST', '/', array( 'title' => 'Serialized', 'code' => '<div>Source</div>', 'status' => 'publish' ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertTrue( $GLOBALS['cache_write_lock_observed'] );
		$this->assertArrayNotHasKey( self::LOCK, $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	public function test_waiting_claim_retries_after_the_other_writer_releases(): void {
		$GLOBALS['mcpwp_test_wpdb_options'][self::LOCK] = (string) ( time() + 90 );
		$attempts = 0;
		$hook = null;
		$hook = function ( $name ) use ( &$hook, &$attempts ) {
			if ( self::LOCK === $name ) {
				++$attempts;
				if ( 3 === $attempts ) {
					unset( $GLOBALS['mcpwp_test_wpdb_options'][self::LOCK] );
				} else {
					$GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
				}
			}
		};
		$GLOBALS['mcpwp_test_wpdb_before_insert'] = $hook;
		$token = Mcpwp_Activator::acquire_custom_code_cache_lock( 0.5 );
		$this->assertIsString( $token );
		$this->assertSame( 3, $attempts );
		Mcpwp_Activator::release_custom_code_cache_lock( $token );
		$this->assertArrayNotHasKey( self::LOCK, $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	public function test_lock_is_reentrant_and_a_timeout_does_not_release_someone_elses_claim(): void {
		$token = Mcpwp_Activator::acquire_custom_code_cache_lock( 0.1 );
		$this->assertSame( $token, Mcpwp_Activator::acquire_custom_code_cache_lock( 0.1 ) );
		Mcpwp_Activator::release_custom_code_cache_lock( $token );
		$this->assertArrayHasKey( self::LOCK, $GLOBALS['mcpwp_test_wpdb_options'] );
		Mcpwp_Activator::release_custom_code_cache_lock( $token );
		$GLOBALS['mcpwp_test_wpdb_options'][self::LOCK] = (string) ( time() + 90 );
		$result = Mcpwp_Activator::acquire_custom_code_cache_lock( 0.01 );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertArrayHasKey( self::LOCK, $GLOBALS['mcpwp_test_wpdb_options'] );
		unset( $GLOBALS['mcpwp_test_wpdb_options'][self::LOCK] );
	}
}
