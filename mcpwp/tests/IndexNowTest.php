<?php

use PHPUnit\Framework\TestCase;

final class IndexNowTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['mcpwp_test_filters'] = array();
		$GLOBALS['mcpwp_test_cron']    = array();
		$GLOBALS['_mcpwp_test_posts']  = array();
		$GLOBALS['_mcpwp_test_meta']   = array();
		putenv( 'WP_ENVIRONMENT_TYPE' );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
		putenv( 'WP_ENVIRONMENT_TYPE' );
	}

	// -- Key management -------------------------------------------------

	public function test_key_is_32_lowercase_hex_chars_and_stable_across_calls(): void {
		$indexnow = new Mcpwp_IndexNow();
		$key      = $indexnow->get_key();

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertSame( $key, $indexnow->get_key(), 'key must be generated once and reused' );
	}

	public function test_key_url_is_served_at_site_root(): void {
		$indexnow = new Mcpwp_IndexNow();
		$this->assertSame( 'https://example.com/' . $indexnow->get_key() . '.txt', $indexnow->key_url() );
	}

	// -- Environment / off-switch gating ---------------------------------

	public function test_enabled_by_default_in_production(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		$indexnow = new Mcpwp_IndexNow();
		$this->assertTrue( $indexnow->is_enabled() );
		$this->assertSame( '', $indexnow->blocked_reason() );
	}

	public function test_disabled_outside_production_by_default(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=staging' );
		$indexnow = new Mcpwp_IndexNow();
		$this->assertFalse( $indexnow->is_enabled() );
		$this->assertSame( 'non_production_environment', $indexnow->blocked_reason() );
	}

	public function test_can_be_forced_on_outside_production(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=staging' );
		$indexnow = new Mcpwp_IndexNow();
		$indexnow->update_settings( array( 'force_non_production' => true ) );

		$this->assertTrue( $indexnow->is_enabled() );
	}

	public function test_off_switch_wins_even_in_production(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		$indexnow = new Mcpwp_IndexNow();
		$indexnow->update_settings( array( 'enabled' => false ) );

		$this->assertFalse( $indexnow->is_enabled() );
		$this->assertSame( 'disabled_by_setting', $indexnow->blocked_reason() );
	}

	// -- Rank Math deferral -----------------------------------------------

	public function test_defers_to_rankmath_when_its_indexnow_module_is_reported_active(): void {
		add_filter( 'mcpwp_rankmath_indexnow_active', function () {
			return true;
		} );

		$indexnow = new Mcpwp_IndexNow();
		$this->assertTrue( $indexnow->defer_to_rankmath() );
		$this->assertSame( 'rankmath_indexnow_active', $indexnow->blocked_reason() );
	}

	public function test_does_not_defer_when_rankmath_module_reported_inactive(): void {
		add_filter( 'mcpwp_rankmath_indexnow_active', function () {
			return false;
		} );

		$indexnow = new Mcpwp_IndexNow();
		$this->assertFalse( $indexnow->defer_to_rankmath() );
	}

	// -- Indexability gates -------------------------------------------------

	public function test_only_publish_status_is_indexable(): void {
		$indexnow = new Mcpwp_IndexNow();
		$draft    = (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'draft', 'post_password' => '' );

		$this->assertFalse( $indexnow->is_url_indexable( $draft ) );
	}

	public function test_password_protected_post_is_not_indexable(): void {
		$indexnow = new Mcpwp_IndexNow();
		$post     = (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => 'secret' );

		$this->assertFalse( $indexnow->is_url_indexable( $post ) );
	}

	public function test_non_public_post_type_is_not_indexable(): void {
		$GLOBALS['mcpwp_test_public_post_types'] = array();
		$indexnow = new Mcpwp_IndexNow();
		$post     = (object) array( 'ID' => 1, 'post_type' => 'mcpwp_internal', 'post_status' => 'publish', 'post_password' => '' );

		$this->assertFalse( $indexnow->is_url_indexable( $post ) );
	}

	public function test_noindex_meta_excludes_a_post(): void {
		$indexnow = new Mcpwp_IndexNow();
		$post     = (object) array( 'ID' => 42, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		update_post_meta( 42, '_mcpwp_noindex', '1' );

		$this->assertFalse( $indexnow->is_url_indexable( $post ) );
	}

	public function test_ordinary_published_page_is_indexable(): void {
		$indexnow = new Mcpwp_IndexNow();
		$post     = (object) array( 'ID' => 7, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );

		$this->assertTrue( $indexnow->is_url_indexable( $post ) );
	}

	// -- Queue / debounce / batching --------------------------------------

	public function test_queue_url_dedupes_and_schedules_a_single_flush(): void {
		$indexnow = new Mcpwp_IndexNow();
		$indexnow->queue_url( 'https://example.com/a' );
		$indexnow->queue_url( 'https://example.com/b' );
		$indexnow->queue_url( 'https://example.com/a' ); // duplicate

		$pending = get_option( Mcpwp_IndexNow::PENDING_OPTION, array() );
		$this->assertCount( 2, $pending );

		$scheduled = $GLOBALS['mcpwp_test_cron'][ Mcpwp_IndexNow::FLUSH_HOOK ] ?? null;
		$this->assertNotNull( $scheduled, 'a single flush event must be scheduled' );

		// Queuing more must not schedule a second event.
		$indexnow->queue_url( 'https://example.com/c' );
		$this->assertSame( $scheduled, $GLOBALS['mcpwp_test_cron'][ Mcpwp_IndexNow::FLUSH_HOOK ] );
	}

	public function test_flush_submits_and_clears_pending_and_records_last_result(): void {
		$captured = null;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = array( 'args' => $args, 'url' => $url );
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"ok":true}',
			);
		}, 10, 3 );

		$indexnow = new Mcpwp_IndexNow();
		$indexnow->queue_url( 'https://example.com/a' );
		$indexnow->queue_url( 'https://example.com/b' );

		$result = $indexnow->flush();

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['url_count'] );
		$this->assertSame( self::ENDPOINT(), $captured['url'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'example.com', $body['host'] );
		$this->assertSame( $indexnow->get_key(), $body['key'] );
		$this->assertCount( 2, $body['urlList'] );

		$this->assertSame( array(), get_option( Mcpwp_IndexNow::PENDING_OPTION, array() ) );
		$this->assertSame( $result, get_option( Mcpwp_IndexNow::LAST_RESULT_OPTION ) );
	}

	public function test_flush_does_nothing_when_nothing_is_pending(): void {
		$indexnow = new Mcpwp_IndexNow();
		$this->assertNull( $indexnow->flush() );
	}

	public function test_flush_does_not_submit_when_deferring_to_rankmath(): void {
		add_filter( 'mcpwp_rankmath_indexnow_active', function () {
			return true;
		} );
		add_filter( 'pre_http_request', function () {
			$this->fail( 'IndexNow must not ping the endpoint while deferring to Rank Math.' );
		} );

		$indexnow = new Mcpwp_IndexNow();
		$indexnow->queue_url( 'https://example.com/a' );

		$this->assertNull( $indexnow->flush() );
	}

	// -- submit() own-URL filtering ----------------------------------------

	public function test_submit_drops_urls_that_do_not_belong_to_this_site(): void {
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 200 ), 'body' => '' );
		} );

		$indexnow = new Mcpwp_IndexNow();
		$result   = $indexnow->submit( array( 'https://example.com/mine', 'https://evil.example/not-mine' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['url_count'] );
		$this->assertSame( array( 'https://example.com/mine' ), $result['urls'] );
	}

	public function test_submit_refuses_when_no_urls_belong_to_this_site(): void {
		$indexnow = new Mcpwp_IndexNow();
		$result   = $indexnow->submit( array( 'https://evil.example/x' ) );

		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_submit_records_failure_result_on_non_2xx_response(): void {
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 422 ), 'body' => 'bad key' );
		} );

		$indexnow = new Mcpwp_IndexNow();
		$result   = $indexnow->submit( array( 'https://example.com/a' ) );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 422, $result['response_code'] );
		$this->assertSame( 'bad key', $result['error'] );
	}

	// -- Status -------------------------------------------------------------

	public function test_status_reports_key_url_environment_and_pending_count(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		$indexnow = new Mcpwp_IndexNow();
		$indexnow->queue_url( 'https://example.com/a' );

		$status = $indexnow->get_status();

		$this->assertTrue( $status['enabled'] );
		$this->assertSame( 'production', $status['environment'] );
		$this->assertSame( $indexnow->key_url(), $status['key_url'] );
		$this->assertSame( 1, $status['pending_count'] );
		$this->assertFalse( $status['rankmath_active'] );
	}

	private static function ENDPOINT(): string {
		return 'https://api.indexnow.org/indexnow';
	}
}
