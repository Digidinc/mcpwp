<?php

use PHPUnit\Framework\TestCase;

final class CustomCodeReviewRoundOneTest extends TestCase {
	private $error_log;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']     = array();
		$GLOBALS['_mcpwp_test_posts']      = array();
		$GLOBALS['_mcpwp_test_meta']       = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		$this->error_log = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->error_log );
	}

	private function controller() {
		return new Mcpwp_REST_Elementor_Pro( new stdClass() );
	}

	private function seed( $id, $conditions ) {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( array(
			'ID' => $id, 'post_type' => 'elementor_snippet', 'post_status' => 'publish', 'post_title' => 'Existing',
		) );
		update_post_meta( $id, '_elementor_location', 'elementor_head' );
		update_post_meta( $id, '_elementor_conditions', $conditions );
	}

	private function install_manager( $manager ) {
		$property = new ReflectionProperty( \ElementorPro\Modules\ThemeBuilder\Module::class, 'conditions_manager' );
		$property->setAccessible( true );
		$property->setValue( \ElementorPro\Modules\ThemeBuilder\Module::instance(), $manager );
	}

	/** @dataProvider missing_sub_name_provider */
	public function test_sub_id_requires_a_sub_name_before_any_post_is_written( $condition ): void {
		$response = $this->controller()->create_custom_code( new WP_REST_Request( 'POST', '/', array(
			'title' => 'Scoped', 'code' => '<div>Scoped</div>', 'status' => 'publish', 'conditions' => array( $condition ),
		) ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'invalid_conditions', $response->get_error_code() );
		$this->assertSame( 400, $response->get_error_data()['status'] );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	public function missing_sub_name_provider(): array {
		return array(
			array( array( 'type' => 'include', 'name' => 'singular', 'sub_id' => '6' ) ),
			array( array( 'type' => 'exclude', 'name' => 'singular', 'sub_name' => ' ', 'sub_id' => '6' ) ),
			array( 'include/singular//6' ),
			array( array( 'type' => 'include', 'name' => 'singular', 'sub_name' => '<b></b>' ) ),
			array( array( 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '<b></b>' ) ),
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unrelated_save_repairs_legacy_meta_before_regeneration(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$this->seed( 682, array( array( 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => '6' ) ) );
		$controller = $this->controller();
		// Reading is tolerant but must not mutate the legacy row.
		$controller->get_custom_code( new WP_REST_Request( 'GET', '/', array( 'id' => 682 ) ) );
		$this->assertIsArray( get_post_meta( 682, '_elementor_conditions', true )[0] );
		$response = $controller->create_custom_code( new WP_REST_Request( 'POST', '/', array(
			'title' => 'Unrelated', 'code' => '<div>Another</div>', 'status' => 'publish',
		) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'include/singular/page/6' ), get_post_meta( 682, '_elementor_conditions', true ) );
		$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
		foreach ( $cache as $snippets ) {
			foreach ( $snippets as $conditions ) {
				foreach ( $conditions as $condition ) {
					$this->assertIsString( $condition );
					$this->assertIsArray( explode( '/', $condition ) );
				}
			}
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_failed_create_is_a_draft_and_exception_details_stay_server_side(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$this->install_manager( new class extends \ElementorPro\Modules\ThemeBuilder\Mcpwp_Fake_Conditions_Manager {
			public function save_conditions( $id, $conditions ) {
				$GLOBALS['mcpwp_r1_status_during_save'] = get_post_status( $id );
				throw new TypeError( 'called in /var/www/html/private/site.php:123' );
			}
		} );
		$response = $this->controller()->create_custom_code( new WP_REST_Request( 'POST', '/', array(
			'title' => 'Failed create', 'code' => '<div>Never live</div>', 'status' => 'publish',
		) ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$id = $response->get_error_data()['resource_id'];
		$this->assertSame( 'draft', $GLOBALS['mcpwp_r1_status_during_save'] );
		$this->assertSame( 'draft', get_post_status( $id ) );
		$this->assertTrue( $response->get_error_data()['partial_write'] );
		$this->assertStringNotContainsString( '/var/www/', $response->get_error_message() );
		$this->assertStringNotContainsString( 'site.php', $response->get_error_message() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_exception_detail_is_logged_without_being_returned(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$this->seed( 682, array( 'include/general' ) );
		$this->install_manager( new class extends \ElementorPro\Modules\ThemeBuilder\Mcpwp_Fake_Conditions_Manager {
			public function save_conditions( $id, $conditions ) {
				throw new TypeError( 'called in /var/www/html/private/site.php:123' );
			}
		} );
		$log = tempnam( sys_get_temp_dir(), 'custom-code-error-' );
		ini_set( 'error_log', $log );
		try {
			$response = $this->controller()->update_custom_code( new WP_REST_Request( 'POST', '/', array(
				'id' => 682, 'conditions' => array( array( 'type' => 'exclude', 'name' => 'general' ) ),
			) ) );
			$this->assertInstanceOf( WP_Error::class, $response );
			$this->assertStringNotContainsString( '/var/www/', $response->get_error_message() );
			$this->assertStringContainsString( '/var/www/html/private/site.php:123', file_get_contents( $log ) );
		} finally {
			unlink( $log );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_failed_update_restores_previous_conditions_and_its_cache_entry(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$this->seed( 682, array( 'include/general' ) );
		$before = array( 'elementor_head' => array( 682 => array( 'include/general' ), 900 => array( 'exclude/general' ) ) );
		update_option( 'elementor_pro_theme_builder_conditions', $before );
		$this->install_manager( new class extends \ElementorPro\Modules\ThemeBuilder\Mcpwp_Fake_Conditions_Manager {
			public function save_conditions( $id, $conditions ) {
				parent::save_conditions( $id, $conditions );
				throw new TypeError( 'called in /var/www/html/private/site.php:123' );
			}
		} );
		$response = $this->controller()->update_custom_code( new WP_REST_Request( 'POST', '/', array(
			'id' => 682, 'conditions' => array( array( 'type' => 'exclude', 'name' => 'general' ) ),
		) ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( array( 'include/general' ), get_post_meta( 682, '_elementor_conditions', true ) );
		$cache = get_option( 'elementor_pro_theme_builder_conditions', array() );
		$this->assertSame( array( 'include/general' ), $cache['elementor_head'][682] );
	}
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_failed_update_cannot_publish_or_change_a_draft_post(): void {
		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
		$this->seed( 682, array( 'include/general' ) );
		$GLOBALS['_mcpwp_test_posts'][682]->post_status = 'draft';
		$GLOBALS['_mcpwp_test_posts'][682]->post_content = '<div>Old source</div>';
		update_post_meta( 682, '_elementor_code', '<div>Old source</div>' );
		update_option( 'elementor_pro_theme_builder_conditions', array( 'elementor_head' => array( 682 => array( 'include/general' ) ) ) );
		$this->install_manager( new class extends \ElementorPro\Modules\ThemeBuilder\Mcpwp_Fake_Conditions_Manager {
			public function save_conditions( $id, $conditions ) {
				throw new TypeError( 'save failed' );
			}
		} );
		$response = $this->controller()->update_custom_code( new WP_REST_Request( 'POST', '/', array(
			'id' => 682, 'status' => 'publish', 'title' => 'New title', 'code' => '<div>New source</div>',
			'conditions' => array( array( 'type' => 'exclude', 'name' => 'general' ) ),
		) ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'draft', get_post_status( 682 ) );
		$this->assertSame( 'Existing', get_post( 682 )->post_title );
		$this->assertSame( '<div>Old source</div>', get_post( 682 )->post_content );
		$this->assertSame( '<div>Old source</div>', get_post_meta( 682, '_elementor_code', true ) );
		$this->assertSame( array( 'include/general' ), get_post_meta( 682, '_elementor_conditions', true ) );
		$this->assertSame( array( 'include/general' ), get_option( 'elementor_pro_theme_builder_conditions' )['elementor_head'][682] );
	}

}
