<?php

use PHPUnit\Framework\TestCase;

final class IndexNowTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']        = array();
		$GLOBALS['mcpwp_test_wpdb_options']    = array();
		$GLOBALS['mcpwp_test_filters']        = array();
		$GLOBALS['mcpwp_test_cron']           = array();
		$GLOBALS['mcpwp_test_transients']     = array();
		$GLOBALS['_mcpwp_test_posts']         = array();
		$GLOBALS['_mcpwp_test_meta']          = array();
		$GLOBALS['mcpwp_test_url_to_postid']  = array();
		putenv( 'WP_ENVIRONMENT_TYPE' );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
		putenv( 'WP_ENVIRONMENT_TYPE' );
	}

	private function enable( array $overrides = array() ): Mcpwp_IndexNow {
		$indexnow = new Mcpwp_IndexNow();
		$indexnow->update_settings( array_merge( array( 'enabled' => true ), $overrides ) );
		return $indexnow;
	}

	// -- WARN-1: opt-in, key generation gated on enable ---------------------

	public function test_disabled_by_default_for_a_brand_new_install(): void {
		$indexnow = new Mcpwp_IndexNow();
		$this->assertFalse( $indexnow->is_enabled() );
		$this->assertSame( 'disabled_by_setting', $indexnow->blocked_reason() );
	}

	public function test_no_key_is_generated_and_none_is_reported_while_disabled(): void {
		$indexnow = new Mcpwp_IndexNow();

		$this->assertSame( '', $indexnow->get_key() );
		$this->assertSame( '', $indexnow->key_url() );
		$this->assertSame( '', get_option( Mcpwp_IndexNow::KEY_OPTION, '' ), 'get_key() while disabled must not write the key option' );
	}

	public function test_enabling_generates_the_key_immediately(): void {
		$indexnow = $this->enable();

		$key = get_option( Mcpwp_IndexNow::KEY_OPTION, '' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertSame( $key, $indexnow->get_key() );
	}

	public function test_key_is_stable_across_calls_once_generated(): void {
		$indexnow = $this->enable();
		$first    = $indexnow->get_key();
		$this->assertSame( $first, $indexnow->get_key() );
	}

	public function test_key_url_is_served_at_site_root_when_enabled(): void {
		$indexnow = $this->enable();
		$this->assertSame( 'https://example.com/' . $indexnow->get_key() . '.txt', $indexnow->key_url() );
	}

	public function test_disabling_does_not_generate_a_new_key_and_stops_serving_the_file(): void {
		$before = ( new Mcpwp_IndexNow() )->get_stored_key();
		$this->assertSame( '', $before, 'nothing generated yet' );

		$indexnow = $this->enable();
		$key      = $indexnow->get_key();
		$this->assertNotSame( '', $key );

		$indexnow->update_settings( array( 'enabled' => false ) );

		// get_key()/key_url() may still report a key generated while the
		// feature was on (harmless — nothing new was written), but
		// maybe_serve_key_file() independently refuses to serve it, and
		// get_key() never generates one while disabled (WARN-1's actual
		// requirement — see test_no_key_is_generated_and_none_is_reported_while_disabled
		// for a install that never enabled it at all).
		$this->assertSame( $key, $indexnow->get_stored_key() );
	}

	// -- WARN-1: blog_public hard stop, environment gate ---------------------

	public function test_enabled_and_production_by_explicit_choice(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		$indexnow = $this->enable();
		$this->assertTrue( $indexnow->is_enabled() );
		$this->assertSame( '', $indexnow->blocked_reason() );
	}

	public function test_disabled_outside_production_even_once_turned_on(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=staging' );
		$indexnow = $this->enable();
		$this->assertFalse( $indexnow->is_enabled() );
		$this->assertSame( 'non_production_environment', $indexnow->blocked_reason() );
	}

	public function test_can_be_forced_on_outside_production(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=staging' );
		$indexnow = $this->enable( array( 'force_non_production' => true ) );
		$this->assertTrue( $indexnow->is_enabled() );
	}

	public function test_blog_public_off_blocks_regardless_of_the_setting(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		update_option( 'blog_public', '0' );
		$indexnow = $this->enable();

		$this->assertFalse( $indexnow->is_enabled() );
		$this->assertSame( 'site_discourages_search_engines', $indexnow->blocked_reason() );
		$this->assertFalse( $indexnow->blog_public_allows_indexing() );
	}

	public function test_blog_public_on_by_default(): void {
		$indexnow = new Mcpwp_IndexNow();
		$this->assertTrue( $indexnow->blog_public_allows_indexing() );
	}

	// -- Rank Math deferral -----------------------------------------------

	public function test_defers_to_rankmath_when_filter_reports_active(): void {
		add_filter( 'mcpwp_rankmath_indexnow_active', function () {
			return true;
		} );
		putenv( 'WP_ENVIRONMENT_TYPE=production' );

		$indexnow = $this->enable();
		$this->assertTrue( $indexnow->defer_to_rankmath() );
		$this->assertSame( 'rankmath_indexnow_active', $indexnow->blocked_reason() );
	}

	public function test_does_not_defer_when_filter_reports_inactive(): void {
		add_filter( 'mcpwp_rankmath_indexnow_active', function () {
			return false;
		} );

		$indexnow = new Mcpwp_IndexNow();
		$this->assertFalse( $indexnow->defer_to_rankmath() );
	}

	public function test_defers_based_on_rankmath_real_module_and_option_shape(): void {
		// Verified shape (2026-09-26, Rank Math 1.0.279 source): module id
		// 'instant-indexing' in `rank_math_modules`, auto-submit post types
		// in `rank-math-options-instant-indexing['bing_post_types']` — and,
		// per Rank Math's own installer, BOTH are true out of the box on a
		// fresh Rank Math activation (live-verified on the rig, 2026-09-26).
		add_filter( 'mcpwp_seo_plugin_active_override', function () {
			return 'rankmath';
		} );
		update_option( 'rank_math_modules', array( 'sitemap', 'instant-indexing' ) );
		update_option( 'rank-math-options-instant-indexing', array( 'bing_post_types' => array( 'post', 'page' ) ) );

		$indexnow = new Mcpwp_IndexNow();
		$this->assertTrue( $indexnow->defer_to_rankmath() );
	}

	/**
	 * WordPress does not clean up a plugin's options on deactivation. A
	 * site that deactivated (or removed) Rank Math must not be stuck
	 * deferring forever to a feature that no longer runs — live-verified
	 * on the rig, 2026-09-26: without this gate, IndexNow's blocked_reason()
	 * kept reporting rankmath_indexnow_active after Rank Math was deactivated.
	 */
	public function test_does_not_defer_on_stale_rankmath_options_once_the_plugin_is_inactive(): void {
		add_filter( 'mcpwp_seo_plugin_active_override', function () {
			return ''; // Rank Math (and everything else) inactive.
		} );
		update_option( 'rank_math_modules', array( 'sitemap', 'instant-indexing' ) );
		update_option( 'rank-math-options-instant-indexing', array( 'bing_post_types' => array( 'post', 'page' ) ) );

		$indexnow = new Mcpwp_IndexNow();
		$this->assertFalse( $indexnow->defer_to_rankmath() );
	}

	public function test_does_not_defer_when_module_active_but_no_post_types_configured(): void {
		update_option( 'rank_math_modules', array( 'sitemap', 'instant-indexing' ) );
		update_option( 'rank-math-options-instant-indexing', array( 'bing_post_types' => array() ) );

		$indexnow = new Mcpwp_IndexNow();
		$this->assertFalse( $indexnow->defer_to_rankmath() );
	}

	public function test_does_not_defer_when_module_inactive_even_with_post_types_configured(): void {
		update_option( 'rank_math_modules', array( 'sitemap' ) );
		update_option( 'rank-math-options-instant-indexing', array( 'bing_post_types' => array( 'post' ) ) );

		$indexnow = new Mcpwp_IndexNow();
		$this->assertFalse( $indexnow->defer_to_rankmath() );
	}

	// -- Indexability gates (#937 WARN-2) -----------------------------------

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

	public function test_blog_public_off_makes_every_post_non_indexable(): void {
		update_option( 'blog_public', '0' );
		$indexnow = new Mcpwp_IndexNow();
		$post     = (object) array( 'ID' => 7, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );

		$this->assertFalse( $indexnow->is_url_indexable( $post ) );
	}

	public function test_yoast_per_post_type_default_noindex_is_honored_absent_a_per_post_override(): void {
		add_filter( 'mcpwp_seo_plugin_active_override', function () {
			return 'yoast';
		} );
		update_option( 'wpseo_titles', array( 'noindex-page' => true ) );

		$indexnow = new Mcpwp_IndexNow();
		$post     = (object) array( 'ID' => 9, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );

		$this->assertFalse( $indexnow->is_url_indexable( $post ) );
	}

	public function test_yoast_per_post_explicit_index_overrides_the_site_default_noindex(): void {
		add_filter( 'mcpwp_seo_plugin_active_override', function () {
			return 'yoast';
		} );
		update_option( 'wpseo_titles', array( 'noindex-page' => true ) );
		update_post_meta( 11, '_yoast_wpseo_meta-robots-noindex', '2' );

		$indexnow = new Mcpwp_IndexNow();
		$post     = (object) array( 'ID' => 11, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );

		$this->assertTrue( $indexnow->is_url_indexable( $post ) );
	}

	public function test_rankmath_per_post_type_default_noindex_is_honored_absent_a_per_post_override(): void {
		add_filter( 'mcpwp_seo_plugin_active_override', function () {
			return 'rankmath';
		} );
		update_option( 'rank-math-options-titles', array( 'pt_page_default_robots' => array( 'noindex' ) ) );

		$indexnow = new Mcpwp_IndexNow();
		$post     = (object) array( 'ID' => 13, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );

		$this->assertFalse( $indexnow->is_url_indexable( $post ) );
	}

	// -- Queue / debounce / atomic append (#937 NOTE-2) ---------------------

	public function test_queue_url_dedupes_and_schedules_a_single_flush(): void {
		$indexnow = new Mcpwp_IndexNow();
		$indexnow->queue_url( 'https://example.com/a' );
		$indexnow->queue_url( 'https://example.com/b' );
		$indexnow->queue_url( 'https://example.com/a' ); // duplicate

		$pending = get_option( Mcpwp_IndexNow::PENDING_OPTION, array() );
		$this->assertCount( 2, $pending );

		$scheduled = $GLOBALS['mcpwp_test_cron'][ Mcpwp_IndexNow::FLUSH_HOOK ] ?? null;
		$this->assertNotNull( $scheduled, 'a single flush event must be scheduled' );

		$indexnow->queue_url( 'https://example.com/c' );
		$this->assertSame( $scheduled, $GLOBALS['mcpwp_test_cron'][ Mcpwp_IndexNow::FLUSH_HOOK ] );
	}

	public function test_queue_append_goes_through_the_atomic_cas_path_when_available(): void {
		$this->assertTrue( class_exists( 'Mcpwp_Encryption' ) );

		$indexnow = new Mcpwp_IndexNow();
		$indexnow->queue_url( 'https://example.com/a' );

		// A raw row now exists in the wpdb-options double, proving the CAS
		// primitive (insert_option_row_if_absent / compare_and_swap_option_row)
		// — not a plain update_option() — is what wrote it.
		$this->assertArrayHasKey( Mcpwp_IndexNow::PENDING_OPTION, $GLOBALS['mcpwp_test_wpdb_options'] );
	}

	public function test_concurrent_queue_url_calls_do_not_lose_either_url(): void {
		// Simulate a second publish event landing between this call's read
		// and its compare-and-swap.
		$indexnow = new Mcpwp_IndexNow();
		$indexnow->queue_url( 'https://example.com/first' );

		$GLOBALS['mcpwp_test_wpdb_before_update'] = function () {
			// A concurrent request appends its own URL first.
			$indexnow2 = new Mcpwp_IndexNow();
			$indexnow2->queue_url( 'https://example.com/concurrent' );
		};

		$indexnow->queue_url( 'https://example.com/second' );

		$pending = get_option( Mcpwp_IndexNow::PENDING_OPTION, array() );
		sort( $pending );
		$this->assertSame(
			array( 'https://example.com/concurrent', 'https://example.com/first', 'https://example.com/second' ),
			$pending
		);
	}

	// -- flush(): submission, re-validation, retry with backoff -------------

	public function test_flush_submits_re_validated_urls_and_keeps_last_result(): void {
		$captured = null;
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( &$captured ) {
			$captured = array( 'args' => $args, 'url' => $url );
			return array( 'response' => array( 'code' => 202 ), 'body' => '' );
		}, 10, 3 );

		$GLOBALS['_mcpwp_test_posts'][1] = (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['_mcpwp_test_posts'][2] = (object) array( 'ID' => 2, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/a'] = 1;
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/b'] = 2;

		$indexnow = $this->enable();
		$indexnow->queue_url( 'https://example.com/a' );
		$indexnow->queue_url( 'https://example.com/b' );

		$result = $indexnow->flush();

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['url_count'] );
		$this->assertSame( 0, $result['dropped_not_indexable'] );
		$this->assertSame( 'https://api.indexnow.org/indexnow', $captured['url'] );

		$body = json_decode( $captured['args']['body'], true );
		$this->assertSame( 'example.com', $body['host'] );
		$this->assertCount( 2, $body['urlList'] );

		$this->assertSame( array(), get_option( Mcpwp_IndexNow::PENDING_OPTION, array() ) );
		// submit() stores its own view before flush() enriches its returned
		// copy with dropped/over-cap bookkeeping — the stored option and
		// flush()'s return value are expected to diverge on exactly those
		// two keys.
		$stored = get_option( Mcpwp_IndexNow::LAST_RESULT_OPTION );
		$this->assertTrue( $stored['success'] );
		$this->assertSame( 2, $stored['url_count'] );
	}

	public function test_flush_drops_a_url_whose_post_became_noindex_during_the_debounce_window(): void {
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 202 ), 'body' => '' );
		} );

		$GLOBALS['_mcpwp_test_posts'][1] = (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['_mcpwp_test_posts'][2] = (object) array( 'ID' => 2, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/still-ok'] = 1;
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/now-noindex'] = 2;

		$indexnow = $this->enable();
		$indexnow->queue_url( 'https://example.com/still-ok' );
		$indexnow->queue_url( 'https://example.com/now-noindex' );

		// The SEO plugin's own meta-box save happened AFTER queueing
		// (post_updated fires before save_post) — exactly what flush()'s
		// re-validation exists to catch (#937 WARN-2).
		update_post_meta( 2, '_mcpwp_noindex', '1' );

		$result = $indexnow->flush();

		$this->assertSame( 1, $result['url_count'] );
		$this->assertSame( array( 'https://example.com/still-ok' ), $result['urls'] );
		$this->assertSame( 1, $result['dropped_not_indexable'] );
	}

	public function test_flush_drops_a_url_whose_post_was_unpublished_during_the_debounce_window(): void {
		$GLOBALS['_mcpwp_test_posts'][3] = (object) array( 'ID' => 3, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/unpublished'] = 3;

		$indexnow = $this->enable();
		$indexnow->queue_url( 'https://example.com/unpublished' );

		$GLOBALS['_mcpwp_test_posts'][3]->post_status = 'draft';

		$result = $indexnow->flush();
		$this->assertSame( array(), get_option( Mcpwp_IndexNow::PENDING_OPTION, array() ) );
		$this->assertSame( 1, $result['dropped_not_indexable'] );
	}

	public function test_flush_does_nothing_when_nothing_is_pending(): void {
		$indexnow = new Mcpwp_IndexNow();
		$this->assertNull( $indexnow->flush() );
	}

	public function test_flush_discards_the_queue_when_deferring_to_rankmath(): void {
		add_filter( 'mcpwp_rankmath_indexnow_active', function () {
			return true;
		} );
		add_filter( 'pre_http_request', function () {
			$this->fail( 'IndexNow must not ping the endpoint while deferring to Rank Math.' );
		} );

		$indexnow = $this->enable();
		$indexnow->queue_url( 'https://example.com/a' );

		$this->assertNull( $indexnow->flush() );
		$this->assertSame( array(), get_option( Mcpwp_IndexNow::PENDING_OPTION, array() ) );
	}

	/**
	 * #937 NOTE-2: "keep URLs until a 200/202 response, retry with backoff
	 * on failure" — a failed submission must not lose the batch.
	 */
	public function test_flush_requeues_and_backs_off_on_a_failed_submission(): void {
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 500 ), 'body' => 'server error' );
		} );

		$GLOBALS['_mcpwp_test_posts'][1] = (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/a'] = 1;

		$indexnow = $this->enable();
		$indexnow->queue_url( 'https://example.com/a' );

		$result = $indexnow->flush();

		$this->assertFalse( $result['success'] );
		$this->assertSame( 1, $result['retry_attempt'] );
		$this->assertGreaterThan( 0, $result['retry_in_seconds'] );

		// The URL is still queued, not lost.
		$this->assertSame( array( 'https://example.com/a' ), get_option( Mcpwp_IndexNow::PENDING_OPTION, array() ) );

		// A fresh flush event was scheduled for the backoff window (not the
		// plain 60s debounce).
		$scheduled = $GLOBALS['mcpwp_test_cron'][ Mcpwp_IndexNow::FLUSH_HOOK ] ?? null;
		$this->assertNotNull( $scheduled );
		$this->assertGreaterThanOrEqual( time() + $result['retry_in_seconds'] - 1, $scheduled );
	}

	/**
	 * #937 NOTE-4: a permanent 4xx (400/403/422) means the SAME batch will
	 * fail identically on retry — it must not be requeued or rescheduled,
	 * and the failure must stay visible in status rather than vanish.
	 */
	public function test_flush_does_not_retry_a_permanent_4xx_failure(): void {
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 422 ), 'body' => 'malformed url list' );
		} );

		$GLOBALS['_mcpwp_test_posts'][1] = (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/a'] = 1;

		$indexnow = $this->enable();
		$indexnow->queue_url( 'https://example.com/a' );
		$scheduled_before_flush = $GLOBALS['mcpwp_test_cron'][ Mcpwp_IndexNow::FLUSH_HOOK ] ?? null;

		$result = $indexnow->flush();

		$this->assertFalse( $result['success'] );
		$this->assertTrue( $result['permanent_failure'] );

		// Not requeued — the batch is done, not deferred.
		$this->assertSame( array(), get_option( Mcpwp_IndexNow::PENDING_OPTION, array() ) );

		// Not rescheduled for a backoff retry — the debounce event
		// queue_url() already set (if any) is left exactly as-is; flush()
		// does not push it out further for a batch it has given up on.
		$this->assertSame( $scheduled_before_flush, $GLOBALS['mcpwp_test_cron'][ Mcpwp_IndexNow::FLUSH_HOOK ] ?? null );

		// But recorded, and visible in status.
		$retry_state = get_option( Mcpwp_IndexNow::RETRY_STATE_OPTION, null );
		$this->assertNotNull( $retry_state );
		$this->assertTrue( $retry_state['permanent_failure'] );
		$this->assertSame( 422, $retry_state['response_code'] );

		$status = $indexnow->get_status();
		$this->assertTrue( $status['retry_state']['permanent_failure'] );
	}

	/**
	 * A transient failure (5xx) must still requeue and back off — the
	 * permanent-failure gate must not swallow the general retry path.
	 */
	public function test_flush_still_retries_a_5xx_failure(): void {
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 503 ), 'body' => 'unavailable' );
		} );

		$GLOBALS['_mcpwp_test_posts'][1] = (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/a'] = 1;

		$indexnow = $this->enable();
		$indexnow->queue_url( 'https://example.com/a' );

		$result = $indexnow->flush();

		$this->assertFalse( $result['success'] );
		$this->assertArrayNotHasKey( 'permanent_failure', $result );
		$this->assertSame( array( 'https://example.com/a' ), get_option( Mcpwp_IndexNow::PENDING_OPTION, array() ) );
		$this->assertNotNull( $GLOBALS['mcpwp_test_cron'][ Mcpwp_IndexNow::FLUSH_HOOK ] ?? null );
	}

	public function test_flush_clears_retry_state_after_a_subsequent_success(): void {
		$attempt = 0;
		add_filter( 'pre_http_request', function () use ( &$attempt ) {
			$attempt++;
			return array( 'response' => array( 'code' => 1 === $attempt ? 500 : 202 ), 'body' => '' );
		} );

		$GLOBALS['_mcpwp_test_posts'][1] = (object) array( 'ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/a'] = 1;

		$indexnow = $this->enable();
		$indexnow->queue_url( 'https://example.com/a' );

		$first = $indexnow->flush();
		$this->assertFalse( $first['success'] );
		$this->assertNotNull( get_option( Mcpwp_IndexNow::RETRY_STATE_OPTION, null ) );

		$second = $indexnow->flush();
		$this->assertTrue( $second['success'] );
		$this->assertNull( get_option( Mcpwp_IndexNow::RETRY_STATE_OPTION, null ) );
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

	public function test_submit_treats_202_as_success(): void {
		add_filter( 'pre_http_request', function () {
			return array( 'response' => array( 'code' => 202 ), 'body' => '' );
		} );

		$indexnow = new Mcpwp_IndexNow();
		$result   = $indexnow->submit( array( 'https://example.com/a' ) );

		$this->assertTrue( $result['success'] );
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

	// -- validate_explicit_urls() (#937 WARN-3) -----------------------------

	public function test_validate_explicit_urls_refuses_the_whole_call_over_the_cap(): void {
		$indexnow = new Mcpwp_IndexNow();
		$urls     = array();
		for ( $i = 0; $i < Mcpwp_IndexNow::MAX_EXPLICIT_PER_CALL + 1; $i++ ) {
			$urls[] = 'https://example.com/' . $i;
		}

		$result = $indexnow->validate_explicit_urls( $urls );
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_validate_explicit_urls_rejects_query_strings(): void {
		$GLOBALS['_mcpwp_test_posts'][5] = (object) array( 'ID' => 5, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/?s=foo']  = 0;
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/?p=5']    = 5;

		$indexnow = new Mcpwp_IndexNow();
		$result   = $indexnow->validate_explicit_urls( array( 'https://example.com/?s=foo', 'https://example.com/?p=5' ) );

		$this->assertSame( array(), $result['valid'] );
		$this->assertArrayHasKey( 'https://example.com/?s=foo', $result['rejected'] );
		$this->assertArrayHasKey( 'https://example.com/?p=5', $result['rejected'] );
	}

	public function test_validate_explicit_urls_rejects_a_url_that_does_not_resolve(): void {
		$indexnow = new Mcpwp_IndexNow();
		$result   = $indexnow->validate_explicit_urls( array( 'https://example.com/no-such-page/' ) );

		$this->assertSame( array(), $result['valid'] );
		$this->assertArrayHasKey( 'https://example.com/no-such-page/', $result['rejected'] );
	}

	public function test_validate_explicit_urls_rejects_a_url_belonging_to_another_host(): void {
		$indexnow = new Mcpwp_IndexNow();
		$result   = $indexnow->validate_explicit_urls( array( 'https://evil.example/x' ) );

		$this->assertArrayHasKey( 'https://evil.example/x', $result['rejected'] );
	}

	public function test_validate_explicit_urls_rejects_a_currently_noindex_post(): void {
		$GLOBALS['_mcpwp_test_posts'][6] = (object) array( 'ID' => 6, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/noindexed/'] = 6;
		update_post_meta( 6, '_mcpwp_noindex', '1' );

		$indexnow = new Mcpwp_IndexNow();
		$result   = $indexnow->validate_explicit_urls( array( 'https://example.com/noindexed/' ) );

		$this->assertSame( array(), $result['valid'] );
		$this->assertArrayHasKey( 'https://example.com/noindexed/', $result['rejected'] );
	}

	public function test_validate_explicit_urls_accepts_a_resolvable_indexable_url(): void {
		$GLOBALS['_mcpwp_test_posts'][8] = (object) array( 'ID' => 8, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['mcpwp_test_url_to_postid']['https://example.com/real-page/'] = 8;

		$indexnow = new Mcpwp_IndexNow();
		$result   = $indexnow->validate_explicit_urls( array( 'https://example.com/real-page/' ) );

		$this->assertSame( array( 'https://example.com/real-page/' ), $result['valid'] );
		$this->assertSame( array(), $result['rejected'] );
	}

	// -- Explicit-submit throttle (#937 WARN-3) -----------------------------

	public function test_submit_throttle_allows_one_call_then_blocks(): void {
		$indexnow = new Mcpwp_IndexNow();
		$this->assertTrue( $indexnow->check_and_consume_submit_throttle() );
		$this->assertFalse( $indexnow->check_and_consume_submit_throttle() );
	}

	// -- Status -------------------------------------------------------------

	public function test_status_reports_key_url_environment_and_pending_count(): void {
		putenv( 'WP_ENVIRONMENT_TYPE=production' );
		$indexnow = $this->enable();
		$indexnow->queue_url( 'https://example.com/a' );

		$status = $indexnow->get_status();

		$this->assertTrue( $status['enabled'] );
		$this->assertSame( 'production', $status['environment'] );
		$this->assertSame( $indexnow->key_url(), $status['key_url'] );
		$this->assertSame( 1, $status['pending_count'] );
		$this->assertFalse( $status['rankmath_active'] );
		$this->assertTrue( $status['blog_public'] );
	}

	public function test_status_reports_disabled_by_default(): void {
		$status = ( new Mcpwp_IndexNow() )->get_status();
		$this->assertFalse( $status['enabled'] );
		$this->assertSame( 'disabled_by_setting', $status['blocked_reason'] );
		$this->assertSame( '', $status['key_url'] );
	}
}
