<?php

use PHPUnit\Framework\TestCase;

final class CustomCodeReviewRoundTwoTest extends TestCase {
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
		ini_set( 'error_log', $this->error_log );
	}

	private function seed( $id, $conditions ) {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => 'elementor_snippet', 'post_status' => 'publish', 'post_title' => 'Existing' ) );
		update_post_meta( $id, '_elementor_conditions', $conditions );
		update_post_meta( $id, '_elementor_location', 'elementor_head' );
	}

	private function admin_controller() {
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$record = new ReflectionProperty( Mcpwp_REST_Elementor_Pro::class, 'current_api_key_record' );
		$record->setAccessible( true );
		$record->setValue( $controller, array( 'scopes' => array( 'admin' ), 'role' => 'admin' ) );
		return $controller;
	}

	private function create( $dry_run, $conditions = null ) {
		$args = array( 'title' => 'Valid snippet', 'code' => '<div>Marker</div>', 'status' => 'publish', 'dry_run' => $dry_run );
		if ( null !== $conditions ) {
			$args['conditions'] = $conditions;
		}
		return $this->admin_controller()->create_custom_code( new WP_REST_Request( 'POST', '/', $args ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 * @dataProvider cache_write_provider
	 */
	public function test_cache_writes_never_keep_only_the_convertible_conditions( $operation ): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$raw = array( 'include/general', array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5 ' ) );
		$this->seed( 15, $raw );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'elementor_head' => array( 15 => $raw ) ) );
		$controller = $this->admin_controller();
		$request = new WP_REST_Request( 'POST', '/', array( 'id' => 15, 'location' => 'head', 'priority' => 1 ) );
		$response = 'enable' === $operation ? $controller->enable_custom_code( $request ) : $controller->update_custom_code( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
		$this->assertArrayNotHasKey( 15, $cache['elementor_head'] ?? array() );
		$this->assertSame( $raw, get_post_meta( 15, '_elementor_conditions', true ) );
		$this->assertContains( 15, $response->get_data()['skipped_custom_code_snippet_ids'] ?? array() );
	}

	public function cache_write_provider(): array {
		return array( array( 'update' ), array( 'enable' ) );
	}

	/** @dataProvider rejected_conditions_provider */
	public function test_validation_matches_conversion_before_insert_and_in_dry_run( $conditions, $dry_run ): void {
		$response = $this->create( $dry_run, $conditions );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_conditions', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	public function rejected_conditions_provider(): array {
		$cases = array(
			array( array( 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5/6' ) ),
			array( 'include/singular/page/5/extra' ),
			array( array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5 ' ) ),
		);
		$out = array();
		foreach ( $cases as $conditions ) {
			$out[] = array( $conditions, false );
			$out[] = array( $conditions, true );
		}
		return $out;
	}

	public function test_a_late_conversion_error_has_a_partial_write_receipt(): void {
		$this->seed( 24, array( 'include/general' ) );
		$method = new ReflectionMethod( Mcpwp_REST_Elementor_Pro::class, 'save_custom_code_conditions' );
		$method->setAccessible( true );
		$response = $method->invoke( new Mcpwp_REST_Elementor_Pro( new stdClass() ), 24, array( array( 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '5/6' ) ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 24, $response->get_error_data()['resource_id'] ?? null );
		$this->assertTrue( $response->get_error_data()['partial_write'] ?? false );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unconvertible_snippet_does_not_stop_migration_or_another_save(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$this->seed( 15, array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '5' ) ) );
		$this->seed( 16, array( array( 'type' => 'include', 'name' => 'general' ) ) );
		$this->assertTrue( Mcpwp_Activator::repair_custom_code_conditions() );
		$this->assertSame( 1, get_option( 'mcpwp_custom_code_conditions_repaired' ) );
		$this->assertSame( array( 'include/general' ), get_post_meta( 16, '_elementor_conditions', true ) );
		$response = $this->create( false );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 201, $response->get_status() );
		$this->assertContains( 15, $response->get_data()['skipped_custom_code_snippet_ids'] ?? array() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_upgrade_lock_does_not_refuse_an_unrelated_create(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$acquire = new ReflectionMethod( Mcpwp_Activator::class, 'acquire_migration_lock' );
		$acquire->setAccessible( true );
		$release = new ReflectionMethod( Mcpwp_Activator::class, 'release_migration_lock' );
		$release->setAccessible( true );
		$name = Mcpwp_Activator::CUSTOM_CODE_CONDITIONS_REPAIR_LOCK_OPTION;
		$token = $acquire->invoke( null, $name, 30 );
		$this->assertNotFalse( $token );
		try {
			$response = $this->create( false );
			$this->assertInstanceOf( WP_REST_Response::class, $response );
			$this->assertSame( 201, $response->get_status() );
		} finally {
			$release->invoke( null, $name, $token );
		}
	}
}
