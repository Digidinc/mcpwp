<?php
/**
 * #981 adversarial review round 2.
 *
 *  WARN-A  meta/taxonomy gates run on the keys the handler will write
 *          (validate_meta() trims; sanitize_key() normalises taxonomies).
 *  WARN-B  every approvals-store write is a read-modify-write under a MySQL
 *          named lock with a fresh re-read; no lock → 503, nothing stored.
 *  WARN-C  unrestricted keys / wp-admin exempt from the pending caps; 30-day
 *          lazy expiry of pending content approvals; 1 MB API / 256 KB
 *          WebMCP body cap; limits and TTL filterable.
 *  NIT     onboard inventory counts only published content for WebMCP.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';

final class WebMcpReviewRound2Test extends TestCase {

	private const EDITOR = 5;
	private const POST   = 9830;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['mcpwp_test_transients']       = array();
		$GLOBALS['mcpwp_test_filters']          = array();
		$GLOBALS['mcpwp_test_named_lock_calls'] = array();
		$GLOBALS['_mcpwp_test_taxonomies']      = array(
			'restricted_tax' => array( 'object_type' => array( 'post' ), 'cap' => array( 'assign_terms' => 'manage_options' ) ),
		);
		$GLOBALS['mcpwp_test_user_caps']        = array(
			self::EDITOR => array( 'edit_posts' => true, 'edit_pages' => true, 'edit_others_posts' => true ),
		);
		$GLOBALS['mcpwp_test_current_user']     = self::EDITOR;
		$GLOBALS['_mcpwp_test_posts']           = array(
			self::POST => new WP_Post( array( 'ID' => self::POST, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'P', 'post_content' => 'body' ) ),
		);
		unset( $GLOBALS['mcpwp_test_get_lock_result'], $GLOBALS['mcpwp_test_on_lock_granted'] );
		Mcpwp_REST_MCP::reset_verdict_memo();
		Mcpwp_WebMCP::end_dispatch( 0 );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_get_lock_result'], $GLOBALS['mcpwp_test_on_lock_granted'], $GLOBALS['_mcpwp_test_taxonomies'] );
		Mcpwp_WebMCP::end_dispatch( 0 );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	private function sub_request( array $params ) {
		Mcpwp_REST_MCP::reset_verdict_memo();
		$prev = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		try {
			$harness = new class() extends Mcpwp_REST_API {
				public function register_routes() {}
			};
			return $harness->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array_merge( array( 'title' => 'x', 'status' => 'draft' ), $params ) ) );
		} finally {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
	}

	private function stored(): array {
		$items = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
		return is_array( $items ) ? $items : array();
	}

	// ── WARN-A ─────────────────────────────────────────────────────────────

	public function test_meta_gate_sees_the_key_that_will_be_written(): void {
		add_filter(
			'auth_post_meta_featured_flag_for_post',
			static function () {
				return current_user_can( 'manage_options' );
			},
			10,
			6
		);

		foreach ( array( 'featured_flag ', ' featured_flag', "\tfeatured_flag\n", "  featured_flag  " ) as $padded ) {
			$verdict = $this->sub_request( array( 'meta' => array( $padded => 'yes' ) ) );
			$this->assertInstanceOf( WP_Error::class, $verdict, json_encode( $padded ) );
			$this->assertSame( 'webmcp_meta_forbidden', $verdict->get_error_code(), json_encode( $padded ) );
		}

		// Whatever validate_meta() itself refuses is refused, not skipped.
		$this->assertInstanceOf( WP_Error::class, $this->sub_request( array( 'meta' => 'featured_flag' ) ) );
		$this->assertInstanceOf( WP_Error::class, $this->sub_request( array( 'meta' => array( 'bad key!' => 'x' ) ) ) );

		$this->assertTrue( $this->sub_request( array( 'meta' => array( ' mood ' => 'calm' ) ) ) );
	}

	public function test_taxonomy_gate_sees_the_taxonomy_that_will_be_written(): void {
		foreach ( array( ' restricted_tax', 'restricted_tax ', 'Restricted_Tax', "restricted_tax\n" ) as $spelling ) {
			$verdict = $this->sub_request( array( 'terms' => array( $spelling => array( 'a' ) ) ) );
			$this->assertInstanceOf( WP_Error::class, $verdict, json_encode( $spelling ) );
			$this->assertSame( 'webmcp_terms_forbidden', $verdict->get_error_code(), json_encode( $spelling ) );
		}
	}

	// ── WARN-B ─────────────────────────────────────────────────────────────

	public function test_every_write_takes_and_releases_the_named_lock(): void {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'v1', array( 'requested_by_key_id' => 'k1' ) );
		$this->assertIsArray( $created );
		Mcpwp_Approvals::approve_request( $created['id'] );

		$this->assertSame( array( 'get', 'release', 'get', 'release' ), $GLOBALS['mcpwp_test_named_lock_calls'] );
	}

	public function test_a_writer_that_committed_before_we_got_the_lock_is_not_overwritten(): void {
		$first = Mcpwp_Approvals::create_post_content_request( self::POST, 'first', array( 'requested_by_key_id' => 'k1' ) );

		// The interleaving that lost 9 of 10 proposals: another request
		// stores its record between our read and our write. With the fix our
		// read happens only after the lock is granted, so it sees that record.
		$GLOBALS['mcpwp_test_on_lock_granted'] = static function () {
			$items                 = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
			$items['apr_parallel'] = array( 'id' => 'apr_parallel', 'status' => 'pending', 'action' => 'post_content_update', 'requested_by_key_id' => 'k2', 'created_at' => gmdate( 'c' ) );
			update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );
		};
		$second = Mcpwp_Approvals::create_post_content_request( self::POST, 'second', array( 'requested_by_key_id' => 'k1' ) );

		$stored = $this->stored();
		$this->assertArrayHasKey( $first['id'], $stored );
		$this->assertArrayHasKey( 'apr_parallel', $stored, 'The concurrent writer survives.' );
		$this->assertArrayHasKey( $second['id'], $stored );
	}

	public function test_transitions_are_checked_and_written_under_the_lock(): void {
		$created = Mcpwp_Approvals::create_post_content_request( self::POST, 'v', array( 'requested_by_key_id' => 'k1' ) );

		// A concurrent reviewer rejects it just before our approve gets the lock.
		$GLOBALS['mcpwp_test_on_lock_granted'] = static function () use ( $created ) {
			$items                              = get_option( Mcpwp_Approvals::OPTION_NAME, array() );
			$items[ $created['id'] ]['status']  = 'rejected';
			update_option( Mcpwp_Approvals::OPTION_NAME, $items, false );
		};
		$approve = Mcpwp_Approvals::approve_request( $created['id'] );

		$this->assertInstanceOf( WP_Error::class, $approve );
		$this->assertSame( 'approval_closed', $approve->get_error_code() );
		$this->assertSame( 'rejected', $this->stored()[ $created['id'] ]['status'] );
	}

	public function test_no_lock_means_a_503_and_nothing_stored(): void {
		$GLOBALS['mcpwp_test_get_lock_result'] = '0';
		$result = Mcpwp_Approvals::create_post_content_request( self::POST, 'v', array( 'requested_by_key_id' => 'k1' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'approvals_busy', $result->get_error_code() );
		$this->assertSame( 503, $result->get_error_data()['status'] );
		$this->assertSame( array(), $this->stored() );
		$this->assertNotContains( 'release', $GLOBALS['mcpwp_test_named_lock_calls'], 'A lock never taken is never released.' );

		// Via WebMCP the refusal reaches the agent, not a fake success.
		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['edit_post'] = true;
		$prev = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		try {
			$proposal = Mcpwp_WebMCP::propose_content_change( array( 'post_id' => self::POST, 'content' => 'x' ) );
		} finally {
			Mcpwp_WebMCP::end_dispatch( $prev );
		}
		$this->assertSame( 'approvals_busy', $proposal->get_error_code() );
	}

	public function test_nested_writes_take_the_lock_once(): void {
		$result = Mcpwp_Approvals::mutate(
			static function ( array $items ) {
				Mcpwp_Approvals::mutate(
					static function ( array $inner ) {
						$inner['apr_inner'] = array( 'id' => 'apr_inner', 'status' => 'rejected', 'created_at' => gmdate( 'c' ) );
						return $inner;
					}
				);
				return $items;
			}
		);
		$this->assertTrue( $result );
		$this->assertSame( array( 'get', 'release' ), $GLOBALS['mcpwp_test_named_lock_calls'] );
	}

	// ── WARN-C ─────────────────────────────────────────────────────────────

	public function test_unrestricted_keys_and_wp_admin_are_exempt_from_the_pending_caps(): void {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, "a$i", array( 'requested_by_key_id' => 'admin-key', 'requester_unrestricted' => true ) ), "unrestricted #$i" );
			$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, "w$i", array( 'requested_by_key_id' => 'wp-user:1' ) ), "wp-admin #$i" );
		}

		// Exempt requests do not use up the capped requesters' budget either.
		for ( $i = 0; $i < Mcpwp_Approvals::MAX_PENDING_CONTENT_PER_REQUESTER; $i++ ) {
			$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, "r$i", array( 'requested_by_key_id' => 'restricted-key' ) ) );
		}
		$this->assertSame( 'too_many_pending', Mcpwp_Approvals::create_post_content_request( self::POST, 'r-over', array( 'requested_by_key_id' => 'restricted-key' ) )->get_error_code() );

		// A WebMCP user is always capped, even if a caller claimed otherwise.
		for ( $i = 0; $i < Mcpwp_Approvals::MAX_PENDING_CONTENT_PER_REQUESTER; $i++ ) {
			Mcpwp_Approvals::create_post_content_request( self::POST, "m$i", array( 'requested_by_key_id' => 'webmcp:user:5', 'requester_unrestricted' => true ) );
		}
		$this->assertSame( 'too_many_pending', Mcpwp_Approvals::create_post_content_request( self::POST, 'm-over', array( 'requested_by_key_id' => 'webmcp:user:5', 'requester_unrestricted' => true ) )->get_error_code() );
	}

	public function test_size_caps_differ_for_webmcp_and_api_keys_and_are_filterable(): void {
		$kb300 = str_repeat( 'a', 300 * 1024 );
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, $kb300, array( 'requested_by_key_id' => 'k1' ) ) );
		$this->assertSame( 'content_too_large', Mcpwp_Approvals::create_post_content_request( self::POST, $kb300, array( 'requested_by_key_id' => 'webmcp:user:5' ) )->get_error_code() );
		$this->assertSame( 'content_too_large', Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'a', 1048577 ), array( 'requested_by_key_id' => 'k1' ) )->get_error_code() );

		add_filter(
			'mcpwp_approval_content_limits',
			static function ( $limits ) {
				$limits['per_requester'] = 1;
				$limits['max_bytes']     = 10;
				return $limits;
			}
		);
		$this->assertSame( 'content_too_large', Mcpwp_Approvals::create_post_content_request( self::POST, str_repeat( 'a', 11 ), array( 'requested_by_key_id' => 'k9' ) )->get_error_code() );
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, 'ok', array( 'requested_by_key_id' => 'k9' ) ) );
		$this->assertSame( 'too_many_pending', Mcpwp_Approvals::create_post_content_request( self::POST, 'ok2', array( 'requested_by_key_id' => 'k9' ) )->get_error_code() );
	}

	public function test_pending_content_approvals_expire_after_the_ttl(): void {
		$old = gmdate( 'c', time() - 31 * DAY_IN_SECONDS );
		update_option(
			Mcpwp_Approvals::OPTION_NAME,
			array(
				'apr_old'   => array( 'id' => 'apr_old', 'status' => 'pending', 'action' => 'post_content_update', 'requested_by_key_id' => 'k1', 'created_at' => $old, 'resource' => array( 'type' => 'post', 'id' => self::POST ) ),
				'apr_other' => array( 'id' => 'apr_other', 'status' => 'pending', 'action' => 'destructive_operation', 'requested_by_key_id' => 'k1', 'created_at' => $old ),
			),
			false
		);

		// Read: already expired, before anything is written.
		$read = Mcpwp_Approvals::get_request( 'apr_old' );
		$this->assertSame( 'expired', $read['status'] );

		// It can no longer be approved, and it frees the requester's budget.
		$this->assertSame( 'approval_closed', Mcpwp_Approvals::approve_request( 'apr_old' )->get_error_code() );
		$this->assertIsArray( Mcpwp_Approvals::create_post_content_request( self::POST, 'new', array( 'requested_by_key_id' => 'k1' ) ) );

		// Persisted on the write; other actions are untouched.
		$stored = $this->stored();
		$this->assertSame( 'expired', $stored['apr_old']['status'] );
		$this->assertNotEmpty( $stored['apr_old']['expired_at'] );
		$this->assertSame( 'pending', $stored['apr_other']['status'] );

		// Filterable TTL.
		add_filter(
			'mcpwp_approval_pending_ttl',
			static function () {
				return 60 * DAY_IN_SECONDS;
			}
		);
		update_option( Mcpwp_Approvals::OPTION_NAME, array( 'apr_40' => array( 'id' => 'apr_40', 'status' => 'pending', 'action' => 'post_content_update', 'created_at' => gmdate( 'c', time() - 40 * DAY_IN_SECONDS ) ) ), false );
		$this->assertSame( 'pending', Mcpwp_Approvals::get_request( 'apr_40' )['status'] );
	}

	// ── NIT ────────────────────────────────────────────────────────────────

	/**
	 * @runInSeparateProcess
	 */
	public function test_onboard_inventory_counts_only_published_for_webmcp(): void {
		foreach ( array(
			'wp_timezone_string'   => 'UTC',
			'get_locale'           => 'en_US',
		) as $fn => $value ) {
			if ( ! function_exists( $fn ) ) {
				eval( "function $fn() { return '$value'; }" ); // phpcs:ignore Squiz.PHP.Eval
			}
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			eval( 'function is_plugin_active( $p ) { return false; }' ); // phpcs:ignore Squiz.PHP.Eval
		}
		if ( ! function_exists( 'wp_get_theme' ) ) {
			eval( 'function wp_get_theme() { return new class() { public function get( $h ) { return "Stub"; } }; }' ); // phpcs:ignore Squiz.PHP.Eval
		}
		if ( ! function_exists( 'wp_count_attachments' ) ) {
			eval( 'function wp_count_attachments() { return (object) array( "image/jpeg" => 0 ); }' ); // phpcs:ignore Squiz.PHP.Eval
		}
		if ( ! function_exists( 'wp_count_terms' ) ) {
			eval( 'function wp_count_terms( $t ) { return 0; }' ); // phpcs:ignore Squiz.PHP.Eval
		}
		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-core.php';
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';

		$GLOBALS['_mcpwp_test_posts'][9831] = new WP_Post( array( 'ID' => 9831, 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'D' ) );
		$GLOBALS['_mcpwp_test_posts'][9832] = new WP_Post( array( 'ID' => 9832, 'post_type' => 'post', 'post_status' => 'private', 'post_title' => 'Pr' ) );

		$info    = new Mcpwp_REST_Site_Info();
		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/onboard', array( 'detail' => 'full' ) );
		$prev    = Mcpwp_WebMCP::begin_dispatch( self::EDITOR );
		Mcpwp_REST_Batch::$is_batch_sub_request = true;
		try {
			$this->assertTrue( $info->verify_api_key( $request ) );
			$data = $info->get_onboard( $request )->get_data();
		} finally {
			Mcpwp_REST_Batch::$is_batch_sub_request = false;
			Mcpwp_WebMCP::end_dispatch( $prev );
		}

		$posts = $data['content_inventory']['posts'] ?? ( $data['inventory']['posts'] ?? null );
		$this->assertNotNull( $posts, wp_json_encode( array_keys( $data ) ) );
		$this->assertSame( 1, $posts['published'] );
		$this->assertNull( $posts['drafts'] );
		$this->assertSame( 1, $posts['total'] );
	}
}
