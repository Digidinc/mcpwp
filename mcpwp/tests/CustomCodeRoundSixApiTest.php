<?php

use PHPUnit\Framework\TestCase;

final class CustomCodeRoundSixApiTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_wpdb_options'] = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
		Mcpwp_Custom_Code_Conditions::invalidate_cache_view();
	}

	public function test_oversized_create_is_rejected_before_insert_including_dry_run(): void {
		foreach ( array( false, true ) as $dry_run ) {
			$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
			$result = $controller->create_custom_code( new WP_REST_Request( 'POST', '/', array(
				'title' => 'Bounded', 'code' => '<div>safe</div>', 'status' => 'publish',
				'conditions' => array_fill( 0, 201, 'include/general' ), 'dry_run' => $dry_run,
			) ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'conditions_too_large', $result->get_error_code() );
			$this->assertSame( 413, $result->get_error_data()['status'] );
			$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
		}
	}

	public function test_admin_read_marks_oversized_conditions_without_hydrating_them(): void {
		$GLOBALS['_mcpwp_test_posts'][682] = new WP_Post( array( 'ID' => 682, 'post_type' => 'elementor_snippet', 'post_status' => 'publish', 'post_title' => 'Legacy' ) );
		$GLOBALS['mcpwp_test_wpdb_intercept'] = static function ( $method, $query ) {
			if ( 'get_var' === $method && false !== strpos( $query, 'OCTET_LENGTH(meta_value)' ) ) { return 6815744; }
			return MCPWP_TEST_WPDB_PASS;
		};
		try {
			$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
			$property = new ReflectionProperty( Mcpwp_REST_Elementor_Pro::class, 'current_api_key_record' );
			$property->setAccessible( true );
			$property->setValue( $controller, array( 'scopes' => array( 'admin' ), 'role' => 'admin' ) );
			$response = $controller->get_custom_code( new WP_REST_Request( 'GET', '/', array( 'id' => 682 ) ) );
			$snippet = $response->get_data()['snippet'];
			$this->assertTrue( $snippet['quarantined'] );
			$this->assertSame( 'conditions_too_large', $snippet['quarantine_reason'] );
			$this->assertSame( array(), $snippet['conditions'] );
			$this->assertArrayNotHasKey( 'debug_meta_excerpt', $snippet );
		} finally {
			unset( $GLOBALS['mcpwp_test_wpdb_intercept'] );
		}
	}

	public function test_publish_error_after_insert_retains_resource_receipt(): void {
		$fail_publish = static function ( $data, $postarr, $unsanitized, $update ) {
			if ( $update && 'publish' === ( $data['post_status'] ?? '' ) ) {
				$data['page_template'] = 'missing-template.php';
				$data['post_status'] = 'draft';
			}
			return $data;
		};
		add_filter( 'wp_insert_post_data', $fail_publish, 999, 4 );
		try {
			$result = ( new Mcpwp_REST_Elementor_Pro( new stdClass() ) )->create_custom_code( new WP_REST_Request( 'POST', '/', array( 'title' => 'Publish receipt', 'code' => '<div>safe</div>', 'status' => 'publish' ) ) );
		} finally {
			remove_filter( 'wp_insert_post_data', $fail_publish, 999 );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'custom_code_publish_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['partial_write'] );
		$this->assertSame( 'draft', get_post_status( $result->get_error_data()['resource_id'] ) );
	}

	public function test_unexpected_metadata_exception_retains_draft_id_receipt(): void {

		$GLOBALS['mcpwp_test_update_post_meta_throws'] = static function ( $id, $key ) { return '_elementor_code' === $key; };
		try {
			$result = ( new Mcpwp_REST_Elementor_Pro( new stdClass() ) )->create_custom_code( new WP_REST_Request( 'POST', '/', array( 'title' => 'Receipt', 'code' => '<div>safe</div>', 'status' => 'publish' ) ) );
		} finally {
			unset( $GLOBALS['mcpwp_test_update_post_meta_throws'] );
		}
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result->get_error_data()['partial_write'] );
		$this->assertSame( 'draft', get_post_status( $result->get_error_data()['resource_id'] ) );
		$this->assertStringNotContainsString( 'private internal path', $result->get_error_message() );
	}
}
