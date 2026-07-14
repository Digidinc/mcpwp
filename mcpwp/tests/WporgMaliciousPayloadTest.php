<?php

use PHPUnit\Framework\TestCase;

final class WporgMaliciousPayloadTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts'] = array();
		$GLOBALS['_mcpwp_test_meta'] = array();
		$GLOBALS['_mcpwp_test_term_inserts'] = array();
		$GLOBALS['_mcpwp_test_terms_result'] = array();
		$GLOBALS['mcpwp_test_current_user'] = 2;
		$GLOBALS['mcpwp_test_user_caps'] = array();
		$GLOBALS['mcpwp_test_options'] = array( 'mcpwp_rate_limit_settings' => array( 'enabled' => false ) );
		$GLOBALS['_mcpwp_test_existing_terms'] = array();
		$GLOBALS['_mcpwp_test_page_templates'] = array( 'templates/landing.php' => 'Landing' );
	}

	public function test_post_create_rejects_unknown_key_before_insert(): void {
		$response = $this->create_post( array( 'title' => 'Allowed', 'post_type' => 'page' ) );
		$this->assertError( 'unknown_parameter', 400, $response );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	public function test_post_create_requires_json_and_does_not_fall_back_to_query(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'Query title', 'status' => 'publish' ) );
		$response = ( new Mcpwp_REST_Posts() )->create_post( $request );
		$this->assertError( 'invalid_json_body', 400, $response );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	/** @dataProvider invalidPostBodies */
	public function test_post_create_rejects_type_range_enum_and_uniqueness_confusion( $body ): void {
		$response = $this->create_post( $body );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	public function test_raw_json_types_are_validated_before_wordpress_route_coercion(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/pages', array(), array(), array( 'title' => 'Page', 'menu_order' => 1 ), '{"title":"Page","menu_order":"1"}' );
		$response = ( new Mcpwp_REST_Pages() )->create_page( $request );
		$this->assertError( 'invalid_parameter', 400, $response );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );

		$request = new WP_REST_Request( 'PATCH', '/mcpwp/v1/plugin-settings', array(), array(), array( 'enable_logging' => false ), '{"enable_logging":"false"}' );
		$response = ( new Mcpwp_REST_Site_Settings() )->update_plugin_settings( $request );
		$this->assertError( 'invalid_parameter', 400, $response );
	}

	/** @dataProvider sanitizerEmptyBodies */
	public function test_values_that_sanitize_to_empty_fail_before_content_or_term_mutation( $kind, $body ): void {
		if ( 'term' === $kind ) {
			$response = ( new Mcpwp_REST_Site_Taxonomy() )->create_term( new WP_REST_Request( 'POST', '/mcpwp/v1/terms', array(), array(), $body ) );
			$this->assertSame( array(), $GLOBALS['_mcpwp_test_term_inserts'] );
		} else {
			$response = $this->create_post( $body );
			$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
		}
		$this->assertError( 'invalid_parameter', 400, $response );
	}

	public function sanitizerEmptyBodies(): array {
		return array(
			'post title' => array( 'post', array( 'title' => '<script></script>' ) ),
			'post tag' => array( 'post', array( 'title' => 'Valid', 'tags' => array( '<script></script>' ) ) ),
			'term name' => array( 'term', array( 'taxonomy' => 'category', 'name' => '<script></script>' ) ),
		);
	}

	public function invalidPostBodies(): array {
		return array(
			'numeric title' => array( array( 'title' => 123 ) ),
			'boolean status' => array( array( 'title' => 'x', 'status' => true ) ),
			'unknown status' => array( array( 'title' => 'x', 'status' => 'trash' ) ),
			'numeric-string category' => array( array( 'title' => 'x', 'categories' => array( '2' ) ) ),
			'duplicate categories' => array( array( 'title' => 'x', 'categories' => array( 2, 2 ) ) ),
			'duplicate tags' => array( array( 'title' => 'x', 'tags' => array( 'same', 'same' ) ) ),
			'too many tags' => array( array( 'title' => 'x', 'tags' => array_fill( 0, 101, 'tag' ) ) ),
			'empty title' => array( array( 'title' => '' ) ),
			'overlong title' => array( array( 'title' => str_repeat( 'a', 201 ) ) ),
			'invalid slug' => array( array( 'title' => 'x', 'slug' => '../bad slug' ) ),
		);
	}

	/** @dataProvider unsafeHtml */
	public function test_post_create_rejects_unsafe_html_instead_of_stripping_it( $html ): void {
		$response = $this->create_post( array( 'title' => 'x', 'content' => $html ) );
		$this->assertError( 'unsafe_html', 400, $response );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	public function unsafeHtml(): array {
		return array(
			'script' => array( '<ScRiPt>alert(1)</sCrIpT><p>ok</p>' ),
			'style' => array( '<style>body{display:none}</style>' ),
			'wrapper' => array( '<body><p>ok</p></body>' ),
			'event handler' => array( '<a oNclick = "alert(1)">x</a>' ),
			'javascript url' => array( '<a href=" javascript:alert(1)">x</a>' ),
			'php' => array( '<?php echo "bad"; ?><p>ok</p>' ),
		);
	}

	public function test_post_update_rejects_empty_partial_body(): void {
		$this->seed_post( 7, 'post', 'draft' );
		$request = new WP_REST_Request( 'PATCH', '/mcpwp/v1/posts/7', array( 'id' => 7 ), array(), array() );
		$response = ( new Mcpwp_REST_Posts() )->update_post( $request );
		$this->assertError( 'empty_update', 400, $response );
		$this->assertSame( 'Original', $GLOBALS['_mcpwp_test_posts'][7]->post_title );
	}

	public function test_page_create_rejects_non_integer_menu_order_and_unknown_template(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/pages', array(), array(), array( 'title' => 'Page', 'menu_order' => '1', 'template' => '../evil.php' ) );
		$response = ( new Mcpwp_REST_Pages() )->create_page( $request );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	public function test_query_cannot_override_internal_post_type(): void {
		$this->seed_post( 1, 'post', 'publish' );
		$this->seed_post( 2, 'page', 'publish' );
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/posts', array( 'post_type' => 'page', 'posts_per_page' => 20, 'paged' => 1 ) );
		$response = ( new Mcpwp_REST_Posts() )->list_posts( $request );
		$this->assertError( 'unknown_parameter', 400, $response );
	}

	public function test_json_size_utf8_depth_and_key_count_limits_apply_before_insert(): void {
		$oversize = new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array(), array(), array( 'title' => 'x' ), str_repeat( 'x', 1048577 ) );
		$this->assertError( 'payload_too_large', 413, ( new Mcpwp_REST_Posts() )->create_post( $oversize ) );

		$invalid_utf8 = $this->create_post( array( 'title' => "bad\xC3\x28" ) );
		$this->assertError( 'invalid_json_body', 400, $invalid_utf8 );

		$nested = 'leaf';
		for ( $index = 0; $index < 9; $index++ ) { $nested = array( 'nested' => $nested ); }
		$this->assertError( 'input_too_complex', 400, $this->create_post( array( 'title' => 'x', 'content' => $nested ) ) );

		$wide = array( 'title' => 'x' );
		for ( $index = 0; $index < 32; $index++ ) { $wide[ 'extra_' . $index ] = $index; }
		$this->assertError( 'input_too_complex', 400, $this->create_post( $wide ) );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_posts'] );
	}

	public function test_exact_title_and_content_boundaries_and_safe_html_are_preserved(): void {
		$response = $this->create_post( array( 'title' => str_repeat( 'a', 200 ), 'content' => '<p><strong>safe</strong></p>' ) );
		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( '<p><strong>safe</strong></p>', $GLOBALS['_mcpwp_test_posts'][1]->post_content );
	}

	public function test_term_create_rejects_unknown_and_wrong_type_before_insert(): void {
		$controller = new Mcpwp_REST_Site_Taxonomy();
		$unknown = new WP_REST_Request( 'POST', '/mcpwp/v1/terms', array(), array(), array( 'taxonomy' => 'category', 'name' => 'News', 'parent' => 2 ) );
		$this->assertError( 'unknown_parameter', 400, $controller->create_term( $unknown ) );
		$wrong_type = new WP_REST_Request( 'POST', '/mcpwp/v1/terms', array(), array(), array( 'taxonomy' => 'category', 'name' => 123 ) );
		$this->assertError( 'invalid_parameter', 400, $controller->create_term( $wrong_type ) );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_term_inserts'] );
	}

	public function test_taxonomy_list_applies_exact_query_and_propagates_errors(): void {
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/categories', array( 'per_page' => 7, 'page' => 3, 'search' => 'new', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'desc' ) );
		$response = ( new Mcpwp_REST_Site_Taxonomy() )->list_categories( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'taxonomy' => 'category', 'number' => 7, 'offset' => 14, 'search' => 'new', 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC' ), $GLOBALS['_mcpwp_test_last_term_args'] );

		$GLOBALS['_mcpwp_test_terms_result'] = new WP_Error( 'term_query_failed', 'No terms.', array( 'status' => 400 ) );
		$this->assertError( 'term_query_failed', 400, ( new Mcpwp_REST_Site_Taxonomy() )->list_categories( $request ) );
	}

	public function test_plugin_settings_reject_unknown_empty_and_coerced_values_without_write(): void {
		$controller = new Mcpwp_REST_Site_Settings();
		$before = array( 'enable_logging' => true, 'log_retention_days' => 30, 'stale' => 'remove' );
		update_option( 'mcpwp_settings', $before );
		foreach ( array( array(), array( 'enable_logging' => 'false' ), array( 'log_retention_days' => '30' ), array( 'unknown' => true ) ) as $body ) {
			$response = $controller->update_plugin_settings( new WP_REST_Request( 'PATCH', '/mcpwp/v1/plugin-settings', array(), array(), $body ) );
			$this->assertSame( 400, $response->get_status() );
			$this->assertSame( $before, get_option( 'mcpwp_settings' ) );
		}
	}

	public function test_plugin_settings_preserve_false_and_store_only_canonical_keys(): void {
		update_option( 'mcpwp_settings', array( 'enable_logging' => true, 'log_retention_days' => 30, 'stale' => 'remove' ) );
		$response = ( new Mcpwp_REST_Site_Settings() )->update_plugin_settings( new WP_REST_Request( 'PATCH', '/mcpwp/v1/plugin-settings', array(), array(), array( 'enable_logging' => false ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'enable_logging' => false, 'log_retention_days' => 30 ), get_option( 'mcpwp_settings' ) );
	}

	public function test_rate_settings_reject_invalid_combination_and_reset_unknown_key(): void {
		$controller = new Mcpwp_REST_Rate_Limit();
		$before = array( 'enabled' => true, 'requests_per_minute' => 60, 'requests_per_hour' => 1000, 'burst_limit' => 10, 'whitelist' => array() );
		update_option( 'mcpwp_rate_limit_settings', $before );
		$response = $controller->save_settings( new WP_REST_Request( 'PATCH', '/mcpwp/v1/rate-limit', array(), array(), array( 'requests_per_minute' => 5, 'burst_limit' => 6 ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( $before, get_option( 'mcpwp_rate_limit_settings' ) );

		$reset = $controller->reset_counter( new WP_REST_Request( 'POST', '/mcpwp/v1/rate-limit/reset', array(), array(), array( 'identifier' => 'key:not-stored' ) ) );
		$this->assertError( 'invalid_identifier', 400, $reset );
	}

	public function test_api_key_create_rejects_authority_fields_and_binds_authenticated_owner(): void {
		$controller = new Mcpwp_REST_API_Keys();
		$this->seed_authenticated_admin_key( $controller );
		$bad = new WP_REST_Request( 'POST', '/mcpwp/v1/api-keys', array(), array(), array( 'label' => 'Bad', 'scopes' => array( 'read' ), 'role' => 'administrator' ) );
		$this->assertError( 'unknown_parameter', 400, $controller->create_api_key( $bad ) );

		$good = new WP_REST_Request( 'POST', '/mcpwp/v1/api-keys', array(), array(), array( 'label' => 'Child', 'scopes' => array( 'read' ), 'tool_categories' => array( 'content' ) ) );
		$response = $controller->create_api_key( $good );
		$this->assertSame( 201, $response->get_status() );
		$records = get_option( 'mcpwp_api_keys' );
		$this->assertSame( 2, $records[1]['user_id'] );
		$this->assertArrayNotHasKey( 'role', $records[1] );
	}

	public function test_content_relationships_are_validated_before_post_or_meta_write(): void {
		$invalid_category = $this->create_post( array( 'title' => 'Bad category', 'categories' => array( 404 ) ) );
		$this->assertError( 'invalid_category', 400, $invalid_category );

		$this->seed_post( 50, 'post', 'publish' );
		$GLOBALS['_mcpwp_test_posts'][50]->post_mime_type = 'text/plain';
		$invalid_image = $this->create_post( array( 'title' => 'Bad image', 'featured_image' => 50 ) );
		$this->assertError( 'invalid_featured_image', 400, $invalid_image );

		$this->seed_post( 60, 'post', 'publish' );
		$page = new WP_REST_Request( 'POST', '/mcpwp/v1/pages', array(), array(), array( 'title' => 'Child', 'parent' => 60 ) );
		$this->assertError( 'invalid_parent', 400, ( new Mcpwp_REST_Pages() )->create_page( $page ) );
		$this->assertArrayNotHasKey( 61, $GLOBALS['_mcpwp_test_posts'] );
	}

	public function test_page_update_rejects_self_parent_and_accepts_exact_installed_template(): void {
		$this->seed_post( 70, 'page', 'draft' );
		$self = new WP_REST_Request( 'PATCH', '/mcpwp/v1/pages/70', array( 'id' => 70 ), array(), array( 'parent' => 70 ) );
		$this->assertError( 'invalid_parent', 400, ( new Mcpwp_REST_Pages() )->update_page( $self ) );
		$template = new WP_REST_Request( 'PATCH', '/mcpwp/v1/pages/70', array( 'id' => 70 ), array(), array( 'template' => 'templates/landing.php' ) );
		$this->assertSame( 200, ( new Mcpwp_REST_Pages() )->update_page( $template )->get_status() );
		$this->assertSame( 'templates/landing.php', get_post_meta( 70, '_wp_page_template', true ) );
	}

	private function create_post( $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array(), array(), $body );
		return ( new Mcpwp_REST_Posts() )->create_post( $request );
	}

	private function seed_post( $id, $type, $status ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID' => $id, 'post_type' => $type, 'post_title' => 'Original', 'post_name' => 'original', 'post_status' => $status,
			'post_date' => '2026-01-01', 'post_date_gmt' => '2026-01-01', 'post_modified' => '2026-01-02', 'post_modified_gmt' => '2026-01-02',
			'post_parent' => 0, 'menu_order' => 0, 'post_author' => 2, 'post_content' => '', 'post_excerpt' => '',
		);
	}

	private function seed_authenticated_admin_key( $controller ): void {
		update_option( 'mcpwp_api_keys', array( array( 'id' => 'admin-key', 'label' => 'Admin', 'hash' => 'admin-key', 'user_id' => 2, 'scopes' => array( 'read', 'write', 'admin' ), 'tool_categories' => array( 'site' ), 'created_at' => '', 'last_used_at' => null, 'revoked_at' => null ) ) );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/api-keys', array(), array( 'X-API-Key' => 'admin-key' ), array( 'label' => 'Seed', 'scopes' => array( 'read' ) ) );
		$this->assertTrue( $controller->check_admin_permission( $request ) );
	}

	private function assertError( $code, $status, $response ): void {
		$this->assertSame( $status, $response->get_status() );
		$this->assertSame( $code, $response->get_data()['code'] );
	}
}
