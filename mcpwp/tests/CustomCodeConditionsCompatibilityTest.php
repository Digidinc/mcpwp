<?php

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';

final class CustomCodeConditionsCompatibilityTest extends TestCase {
	private $error_log;

	protected function setUp(): void {
		$this->error_log = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
		$GLOBALS['mcpwp_test_options']     = array();
		$GLOBALS['_mcpwp_test_posts']      = array();
		$GLOBALS['_mcpwp_test_meta']       = array();
		$GLOBALS['mcpwp_test_wpdb_rows']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->error_log );
	}

	private function controller(): Mcpwp_REST_Elementor_Pro {
		return new Mcpwp_REST_Elementor_Pro( new stdClass() );
	}

	private function create( $controller ) {
		return $controller->create_custom_code( new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code', array(
			'title'      => 'Conditions regression',
			'code'       => '<div>Marker</div>',
			'conditions' => array(
				array( 'type' => 'include', 'name' => 'general' ),
				array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '42' ),
			),
		) ) );
	}

	public function test_fallback_save_uses_pro_string_meta_and_round_trips_exclusions(): void {
		$controller = $this->controller();
		$data       = $this->create( $controller )->get_data();
		$id         = $data['snippet']['id'];

		$this->assertSame( array( 'include/general', 'exclude/singular/page/42' ), get_post_meta( $id, '_elementor_conditions', true ) );
		$read = $controller->get_custom_code( new WP_REST_Request( 'GET', '/', array( 'id' => $id ) ) )->get_data();
		$this->assertSame( array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '42' ), $read['snippet']['conditions'][1] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_legacy_array_meta_does_not_fatal_on_single_or_list_reads(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$manager = new class {
			public function get_document_conditions( $document ) {
				// Pro's parse_condition() calls explode() on each stored value.
				return array_map( function ( $condition ) { return explode( '/', $condition ); }, get_post_meta( $document->get_main_id(), '_elementor_conditions', true ) );
			}
		};
		$property = new ReflectionProperty( \ElementorPro\Modules\ThemeBuilder\Module::class, 'conditions_manager' );
		$property->setAccessible( true );
		$property->setValue( \ElementorPro\Modules\ThemeBuilder\Module::instance(), $manager );
		$GLOBALS['_mcpwp_test_posts'][682] = new WP_Post( array( 'ID' => 682, 'post_type' => 'elementor_snippet', 'post_status' => 'publish', 'post_title' => 'Legacy' ) );
		$conditions = array( array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '42' ) );
		update_post_meta( 682, '_elementor_conditions', $conditions );
		$controller = $this->controller();
		$read = $controller->get_custom_code( new WP_REST_Request( 'GET', '/', array( 'id' => 682 ) ) )->get_data();
		$this->assertSame( $conditions, $read['snippet']['conditions'] );
		$list = $controller->list_custom_code( new WP_REST_Request( 'GET', '/' ) )->get_data();
		$this->assertSame( $conditions, $list['snippets'][0]['conditions'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_save_type_error_leaves_parseable_meta_and_returns_a_typed_error(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$manager = new class {
			public function save_conditions( $id, $conditions ) {
				throw new TypeError( 'Pro conditions failure' );
			}
		};
		$property = new ReflectionProperty( \ElementorPro\Modules\ThemeBuilder\Module::class, 'conditions_manager' );
		$property->setAccessible( true );
		$property->setValue( \ElementorPro\Modules\ThemeBuilder\Module::instance(), $manager );
		$method = new ReflectionMethod( Mcpwp_REST_Elementor_Pro::class, 'save_custom_code_conditions' );
		$method->setAccessible( true );
		$result = $method->invoke( $this->controller(), 682, array( array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '42' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'conditions_save_failed', $result->get_error_code() );
		$this->assertSame( '', get_post_meta( 682, '_elementor_conditions', true ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_public_create_and_update_report_pro_save_errors(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$manager = new class {
			public function save_conditions( $id, $conditions ) {
				throw new TypeError( 'Pro conditions failure' );
			}
		};
		$property = new ReflectionProperty( \ElementorPro\Modules\ThemeBuilder\Module::class, 'conditions_manager' );
		$property->setAccessible( true );
		$property->setValue( \ElementorPro\Modules\ThemeBuilder\Module::instance(), $manager );
		$controller = $this->controller();
		$response   = $this->create( $controller );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertSame( 'conditions_save_failed', $response->get_error_code() );
		$id = array_key_first( $GLOBALS['_mcpwp_test_posts'] );
		$this->assertSame( $id, $response->get_error_data()['resource_id'] );
		$this->assertTrue( $response->get_error_data()['partial_write'] ?? false );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertSame( '', get_post_meta( $id, '_elementor_conditions', true ) );
		update_post_meta( $id, '_elementor_conditions', array( 'exclude/general' ) );
		$response = $controller->update_custom_code( new WP_REST_Request( 'POST', '/', array(
			'id' => $id, 'conditions' => array( array( 'type' => 'include', 'name' => 'general' ) ),
		) ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 500, $response->get_error_data()['status'] );
		$this->assertSame( 'conditions_save_failed', $response->get_error_code() );
		$this->assertSame( $id, $response->get_error_data()['resource_id'] );
		$this->assertTrue( $response->get_error_data()['partial_write'] ?? false );
		$this->assertSame( array( 'exclude/general' ), get_post_meta( $id, '_elementor_conditions', true ) );
	}

	public function test_custom_code_writes_capture_snippet_id_without_enabling_rollback(): void {
		// The shared wp_insert_post double stores stdClass; real get_post()
		// returns WP_Post, which the audit post-type gate requires.
		$real_post = function ( $id ) {
			$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( (array) $GLOBALS['_mcpwp_test_posts'][ $id ] );
		};
		$actions = $GLOBALS['mcpwp_test_actions']['wp_insert_post'] ?? array();
		add_action( 'wp_insert_post', $real_post );
		$log_id = Mcpwp_Action_Log::begin( 'wp_create_elementor_custom_code', array(), 'key', 'elementor_pro' );
		try {
			$data = $this->create( $this->controller() )->get_data();
		} finally {
			$GLOBALS['mcpwp_test_actions']['wp_insert_post'] = $actions;
		}
		$id     = $data['snippet']['id'];
		Mcpwp_Action_Log::complete( $log_id, 1, true );
		foreach ( array( 'wp_update_elementor_custom_code', 'wp_enable_elementor_custom_code', 'wp_disable_elementor_custom_code', 'wp_sanitize_elementor_custom_code' ) as $tool ) {
			$log_id = Mcpwp_Action_Log::begin( $tool, array( 'id' => $id ), 'key', 'elementor-theme' );
			$this->assertNotNull( $log_id, $tool );
			Mcpwp_Action_Log::complete( $log_id, 1, true );
		}
		$rows = $GLOBALS['mcpwp_test_wpdb_rows']['wp_mcpwp_action_log'];
		$this->assertCount( 5, $rows );
		foreach ( $rows as $row ) {
			$this->assertSame( (string) $id, $row['resource_id'] );
			$this->assertSame( 'elementor_snippet', $row['resource_post_type'] );
			$this->assertSame( 0, $row['rollback_supported'] );
		}
	}
}
