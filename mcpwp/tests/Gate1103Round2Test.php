<?php
/**
 * #1103 gate, round 2: the generic post doors check the TARGET post type's
 * own capability for user-run principals (F1); Elementor page creators need
 * edit_pages (F2); DELETE /drafts/delete-all needs both delete_others_*
 * capabilities (F3); /feedback/relay is public by design (a); the admin-AJAX
 * and destructive-replay legs of the predicate are tested (b).  The
 * overlapping-row test (c) lives in RouteCapabilityMapTest.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-destructive-gate.php';

/** Exposes the predicate and the chokepoint. */
class Mcpwp_Gate_1103_Harness {
	use Mcpwp_Api_Auth {
		requester_acts_as_user as public;
		check_user_run_capability as public;
	}
}

final class Gate1103Round2Test extends TestCase {

	const EDITOR = 7;
	const AUTHOR = 11;
	const ADMIN  = 1;
	const POST_ID = 9401;

	/** @var array */
	private $saved_users;
	/** @var array */
	private $saved_post;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$this->saved_users                  = $GLOBALS['mcpwp_test_users'];
		$this->saved_post                   = $_POST;
		foreach ( array( self::EDITOR, self::AUTHOR, self::ADMIN ) as $id ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $id, 'roles' => array(), 'user_login' => 'u' . $id );
		}
		$editor = array_fill_keys( RouteCapabilityMapTest::EDITOR_CAPS, true );
		$GLOBALS['mcpwp_test_user_caps'] = array(
			self::EDITOR => $editor,
			// WordPress core's Author role.
			self::AUTHOR => array_fill_keys( array( 'read', 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_published_posts', 'upload_files' ), true ),
			self::ADMIN  => array_fill_keys( array_merge( RouteCapabilityMapTest::EDITOR_CAPS, array( 'manage_options', 'activate_plugins', 'edit_theme_options' ) ), true ),
		);
		// A WooCommerce-like and a LearnPress-like public type with their own cap objects.
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product', 'lp_course' );
		$GLOBALS['mcpwp_test_post_type_caps']    = array(
			'product'   => array( 'create_posts' => 'edit_products', 'edit_posts' => 'edit_products', 'delete_posts' => 'delete_products' ),
			'lp_course' => array( 'create_posts' => 'edit_lp_courses', 'edit_posts' => 'edit_lp_courses', 'delete_posts' => 'delete_lp_courses' ),
		);
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::POST_ID => new WP_Post( array( 'ID' => self::POST_ID, 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Hoodie' ) ),
		);
		$GLOBALS['mcpwp_test_post_caps'] = array();
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']             = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']         = array();
		$GLOBALS['mcpwp_test_current_user']      = 0;
		$GLOBALS['mcpwp_test_is_admin']          = false;
		$GLOBALS['mcpwp_test_public_post_types'] = array();
		$GLOBALS['mcpwp_test_post_type_caps']    = array();
		$GLOBALS['_mcpwp_test_posts']            = array();
		$GLOBALS['mcpwp_test_post_caps']         = array();
		$GLOBALS['mcpwp_test_ajax_referer_valid'] = false;
		$_POST                                   = $this->saved_post;
		$ref = new ReflectionProperty( 'Mcpwp_Destructive_Gate', 'applying_as_admin' );
		$ref->setAccessible( true );
		$ref->setValue( null, 0 );
	}

	private function token( int $user_id ): array {
		$token = ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => $user_id ) )['access_token'];
		return array( 'Authorization' => 'Bearer ' . $token );
	}

	private function check( string $method, string $route, array $headers, array $params = array() ) {
		return ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( $method, $route, $params, $headers ) );
	}

	private function assert_refused( $result, string $context, string $capability = '' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'rest_forbidden_capability', $result->get_error_code(), $context );
		$this->assertSame( 403, $result->get_error_data()['status'], $context );
		if ( '' !== $capability ) {
			$this->assertSame( $capability, $result->get_error_data()['required_capability'], $context );
		}
	}

	// ── F1: the target post type decides ───────────────────────────────────

	public function test_editor_cannot_create_a_product_through_post_posts(): void {
		$this->assert_refused( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::EDITOR ), array( 'title' => 'x', 'post_type' => 'product', 'status' => 'draft' ) ), 'product', 'edit_products' );
	}

	public function test_editor_can_create_a_post_draft_through_post_posts(): void {
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::EDITOR ), array( 'title' => 'x', 'post_type' => 'post', 'status' => 'draft' ) ) );
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::EDITOR ), array( 'title' => 'x', 'status' => 'draft' ) ), 'No post_type = post.' );
	}

	public function test_bulk_create_checks_every_items_type(): void {
		$this->assert_refused(
			$this->check( 'POST', '/mcpwp/v1/posts/bulk', $this->token( self::EDITOR ), array( 'posts' => array( array( 'title' => 'a' ), array( 'title' => 'b', 'post_type' => 'product' ) ) ) ),
			'bulk with one product',
			'edit_products'
		);
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/posts/bulk', $this->token( self::EDITOR ), array( 'posts' => array( array( 'title' => 'a' ), array( 'title' => 'b', 'post_type' => 'post' ) ) ) ) );
	}

	public function test_author_cannot_create_a_page_through_post_posts(): void {
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		$this->assert_refused( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::AUTHOR ), array( 'title' => 'x', 'post_type' => 'page', 'status' => 'draft' ) ), 'author page', 'edit_pages' );
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::AUTHOR ), array( 'title' => 'x', 'status' => 'draft' ) ), 'An Author may create a post.' );
	}

	public function test_a_plugin_cpt_follows_its_own_cap_object(): void {
		$this->assert_refused( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::EDITOR ), array( 'title' => 'x', 'post_type' => 'lp_course' ) ), 'lp_course', 'edit_lp_courses' );
		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['edit_lp_courses'] = true;
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::EDITOR ), array( 'title' => 'x', 'post_type' => 'lp_course' ) ), 'Holding the type\'s own cap is what counts.' );
	}

	public function test_unknown_type_or_missing_cap_object_fails_closed(): void {
		$this->assert_refused( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::EDITOR ), array( 'title' => 'x', 'post_type' => 'no_such_type' ) ), 'unknown type', 'unknown post type' );
		$this->assert_refused( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::EDITOR ), array( 'title' => 'x', 'post_type' => array( 'post' ) ) ), 'non-string type' );
	}

	public function test_editing_or_deleting_by_id_checks_the_existing_posts_type(): void {
		$GLOBALS['mcpwp_test_post_caps'][ self::EDITOR ] = array( 'read_post' => array( self::POST_ID => true ), 'edit_post' => array( self::POST_ID => true ), 'delete_post' => array( self::POST_ID => true ) );
		$this->assert_refused( $this->check( 'PUT', '/mcpwp/v1/posts/' . self::POST_ID, $this->token( self::EDITOR ), array( 'id' => self::POST_ID, 'title' => 'x' ) ), 'edit a product', 'edit_products' );
		$this->assert_refused( $this->check( 'DELETE', '/mcpwp/v1/posts/' . self::POST_ID, $this->token( self::EDITOR ), array( 'id' => self::POST_ID ) ), 'delete a product', 'delete_products' );
	}

	// ── Bot rule: non-admin keys write only posts and pages through the generic doors ──

	private function key( array $scopes, array $categories = array( 'content', 'woocommerce' ) ): array {
		$auth    = new Mcpwp_Api_Auth_Test_Harness();
		$created = in_array( 'admin', $scopes, true )
			? $auth->create_scoped_api_key( 'admin', $scopes )
			: $auth->create_scoped_api_key( 'bot', $scopes, 'custom', $categories );
		return array( 'X-API-Key' => $created['key'] );
	}

	private function assert_needs_admin_scope( $result, string $context, string $type = 'product' ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'insufficient_scope', $result->get_error_code(), $context );
		$this->assertSame( 'admin', $result->get_error_data()['required_scope'], $context );
		$this->assertSame( $type, $result->get_error_data()['post_type'], $context );
	}

	/** Was a pinned measurement (a content + woocommerce write key created a product: 201). Now refused. */
	public function test_write_key_creating_a_product_through_post_posts_needs_admin_scope(): void {
		$this->assert_needs_admin_scope( $this->check( 'POST', '/mcpwp/v1/posts', $this->key( array( 'read', 'write' ) ), array( 'title' => 'x', 'post_type' => 'product', 'status' => 'draft' ) ), 'create' );
		$this->assert_needs_admin_scope( $this->check( 'POST', '/mcpwp/v1/posts/bulk', $this->key( array( 'read', 'write' ) ), array( 'posts' => array( array( 'title' => 'a' ), array( 'title' => 'b', 'post_type' => 'product' ) ) ) ), 'bulk create' );
	}

	public function test_write_key_still_creates_post_and_page_drafts(): void {
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/posts', $this->key( array( 'read', 'write' ) ), array( 'title' => 'x', 'status' => 'draft' ) ) );
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/posts', $this->key( array( 'read', 'write' ) ), array( 'title' => 'x', 'post_type' => 'page', 'status' => 'draft' ) ) );
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/pages', $this->key( array( 'read', 'write' ) ), array( 'title' => 'x', 'status' => 'draft' ) ) );
	}

	public function test_admin_key_creates_a_product_through_post_posts(): void {
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/posts', $this->key( array( 'read', 'write', 'admin' ) ), array( 'title' => 'x', 'post_type' => 'product', 'status' => 'draft' ) ) );
	}

	public function test_write_key_updating_or_deleting_an_existing_product_needs_admin_scope(): void {
		$this->assert_needs_admin_scope( $this->check( 'PUT', '/mcpwp/v1/posts/' . self::POST_ID, $this->key( array( 'read', 'write' ) ), array( 'id' => self::POST_ID, 'title' => 'x' ) ), 'update' );
		$this->assert_needs_admin_scope( $this->check( 'POST', '/mcpwp/v1/posts/' . self::POST_ID, $this->key( array( 'read', 'write' ) ), array( 'id' => self::POST_ID, 'title' => 'x' ) ), 'update (POST)' );
		$this->assert_needs_admin_scope( $this->check( 'DELETE', '/mcpwp/v1/posts/' . self::POST_ID, $this->key( array( 'read', 'write' ) ), array( 'id' => self::POST_ID ) ), 'delete' );
		$this->assertTrue( $this->check( 'PUT', '/mcpwp/v1/posts/' . self::POST_ID, $this->key( array( 'read', 'write', 'admin' ) ), array( 'id' => self::POST_ID, 'title' => 'x' ) ), 'admin key' );
	}

	// ── AGREEMENT: every public custom post type, both principals, create/update/delete ──

	/** The fixture public CPTs; get_post_types() decides which are covered. */
	private static function register_fixture_cpts(): void {
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product', 'lp_course', 'tribe_events' );
		$GLOBALS['mcpwp_test_post_type_caps']    = array(
			'product'      => array( 'create_posts' => 'edit_products', 'edit_posts' => 'edit_products', 'delete_posts' => 'delete_products' ),
			'lp_course'    => array( 'create_posts' => 'edit_lp_courses', 'edit_posts' => 'edit_lp_courses', 'delete_posts' => 'delete_lp_courses' ),
			'tribe_events' => array( 'create_posts' => 'edit_tribe_events', 'edit_posts' => 'edit_tribe_events', 'delete_posts' => 'delete_tribe_events' ),
		);
	}

	/**
	 * Every PUBLIC post type other than post, page and attachment that
	 * get_post_types() reports, so a newly registered public CPT is covered
	 * without editing this test.
	 */
	public static function public_cpt_provider(): array {
		// Providers run at suite load, before any setUp — restore what we touch.
		$saved = array( $GLOBALS['mcpwp_test_public_post_types'] ?? null, $GLOBALS['mcpwp_test_post_type_caps'] ?? null );
		self::register_fixture_cpts();
		$out = array();
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( in_array( $type, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}
			$out[ $type ] = array( $type );
		}
		list( $GLOBALS['mcpwp_test_public_post_types'], $GLOBALS['mcpwp_test_post_type_caps'] ) = $saved;
		return $out;
	}

	/**
	 * @dataProvider public_cpt_provider
	 */
	public function test_a_public_cpt_is_unreachable_through_the_generic_doors( string $type ): void {
		self::register_fixture_cpts();
		$id                               = 9500;
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'x' ) );
		$GLOBALS['mcpwp_test_post_caps'][ self::EDITOR ] = array( 'read_post' => array( $id => true ), 'edit_post' => array( $id => true ), 'delete_post' => array( $id => true ) );
		$pto = get_post_type_object( $type );

		$requests = array(
			'create'      => array( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'post_type' => $type ) ),
			'bulk create' => array( 'POST', '/mcpwp/v1/posts/bulk', array( 'posts' => array( array( 'title' => 'x', 'post_type' => $type ) ) ) ),
			'update'      => array( 'PUT', '/mcpwp/v1/posts/' . $id, array( 'id' => $id, 'title' => 'y' ) ),
			'delete'      => array( 'DELETE', '/mcpwp/v1/posts/' . $id, array( 'id' => $id ) ),
		);
		foreach ( $requests as $label => list( $method, $route, $params ) ) {
			// A write key — even one holding every category the type could map to.
			$this->assert_needs_admin_scope(
				$this->check( $method, $route, $this->key( array( 'read', 'write' ), array( 'content', 'woocommerce', 'learnpress', 'events', 'site', 'seo', 'media' ) ), $params ),
				"write key, $type, $label",
				$type
			);
			// An Editor's OAuth token: the type's own capability decides, and an
			// Editor does not hold a plugin type's own capabilities.
			$cap_key = 'delete' === $label ? 'delete_posts' : ( 'update' === $label ? 'edit_posts' : 'create_posts' );
			$result  = $this->check( $method, $route, $this->token( self::EDITOR ), $params );
			$this->assert_refused( $result, "editor, $type, $label", $pto->cap->{$cap_key} );
		}
	}

	/**
	 * The nuance, stated rather than hidden: a public CPT that keeps
	 * capability_type 'post' (no capabilities of its own) is writable by an
	 * Editor through the generic doors, exactly as in wp-admin — the user
	 * branch follows the type's cap object. A write key is still refused.
	 */
	public function test_a_public_cpt_with_post_capabilities_follows_wp_admin_for_users(): void {
		$GLOBALS['mcpwp_test_public_post_types'][] = 'sm_team';
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/posts', $this->token( self::EDITOR ), array( 'title' => 'x', 'post_type' => 'sm_team' ) ) );
		$this->assert_needs_admin_scope( $this->check( 'POST', '/mcpwp/v1/posts', $this->key( array( 'read', 'write' ), array( 'content' ) ), array( 'title' => 'x', 'post_type' => 'sm_team' ) ), 'write key', 'sm_team' );
	}

	// ── F2 / F3 ────────────────────────────────────────────────────────────

	public static function elementor_page_creators(): array {
		return array(
			'page'             => array( '/mcpwp/v1/elementor/page' ),
			'build-page'       => array( '/mcpwp/v1/elementor/build-page' ),
			'landing-page'     => array( '/mcpwp/v1/elementor/landing-page' ),
			'clone'            => array( '/mcpwp/v1/elementor/clone' ),
			'blueprints/build' => array( '/mcpwp/v1/elementor/blueprints/build' ),
		);
	}

	/**
	 * @dataProvider elementor_page_creators
	 */
	public function test_elementor_page_creators_need_edit_pages( string $route ): void {
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		$this->assert_refused( $this->check( 'POST', $route, $this->token( self::AUTHOR ), array( 'title' => 'x' ) ), "author $route", 'edit_pages' );
		$this->assertTrue( $this->check( 'POST', $route, $this->token( self::EDITOR ), array( 'title' => 'x' ) ), "editor $route" );
	}

	/**
	 * F2: the page type's own create_posts applies to the Elementor page
	 * creators too — not just the edit_pages row. On a site that maps page
	 * creation to its own capability, that capability decides.
	 */
	public function test_elementor_page_creators_also_check_the_page_types_create_cap(): void {
		$GLOBALS['mcpwp_test_post_type_caps']['page'] = array( 'create_posts' => 'create_pages_custom' );
		// /elementor/clone creates the SOURCE's type (#1118 P0-3): name a page.
		$GLOBALS['_mcpwp_test_posts'][9850] = new WP_Post( array( 'ID' => 9850, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'src' ) );
		$GLOBALS['mcpwp_test_post_caps'][ self::EDITOR ]['read_post'][9850] = true;
		foreach ( array_column( self::elementor_page_creators(), 0 ) as $route ) {
			$params = '/mcpwp/v1/elementor/clone' === $route ? array( 'title' => 'x', 'source_id' => 9850 ) : array( 'title' => 'x' );
			$this->assert_refused( $this->check( 'POST', $route, $this->token( self::EDITOR ), $params ), $route, 'create_pages_custom' );
		}
		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['create_pages_custom'] = true;
		$this->assertTrue( $this->check( 'POST', '/mcpwp/v1/elementor/page', $this->token( self::EDITOR ), array( 'title' => 'x' ) ) );
	}

	public function test_delete_all_drafts_needs_both_delete_others_caps(): void {
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		$this->assert_refused( $this->check( 'DELETE', '/mcpwp/v1/drafts/delete-all', $this->token( self::AUTHOR ) ), 'author', 'delete_others_posts+delete_others_pages' );
		$this->assertTrue( $this->check( 'DELETE', '/mcpwp/v1/drafts/delete-all', $this->token( self::EDITOR ) ), 'editor holds both' );

		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['delete_others_pages'] = false;
		$this->assert_refused( $this->check( 'DELETE', '/mcpwp/v1/drafts/delete-all', $this->token( self::EDITOR ) ), 'one of the two is not enough' );
	}

	// ── (a) /feedback/relay is public by design ───────────────────────────

	public function test_feedback_relay_is_public_by_design_with_an_inert_scope_row(): void {
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		( new Mcpwp_REST_Feedback() )->register_routes();
		$found = null;
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $r ) {
			if ( '/feedback/relay' === $r['route'] ) {
				$found = $r['args'];
			}
		}
		$this->assertNotNull( $found );
		$handler = isset( $found['permission_callback'] ) ? $found : $found[0];
		$this->assertSame( '__return_true', $handler['permission_callback'], 'Public by design: no credential is consulted, so the scope row is inert.' );
		$this->assertSame( 'none', Mcpwp_Route_Capability_Map::rows()['POST /feedback/relay'][1] );
		$this->assertStringStartsWith( 'public by design (inert scope)', Mcpwp_Route_Capability_Map::rows()['POST /feedback/relay'][2] );
	}

	// ── (b) the admin-AJAX and destructive-replay legs ─────────────────────

	private function enter_trusted_admin_ajax(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		$GLOBALS['mcpwp_test_is_admin']          = true;
		$GLOBALS['mcpwp_test_ajax_referer_valid'] = true;
		$GLOBALS['mcpwp_test_current_user']      = self::ADMIN;
		$_POST['action']                         = 'mcpwp_chat_execute_tool';
	}

	public function test_trusted_admin_ajax_acts_as_a_user_and_is_capability_checked(): void {
		$this->enter_trusted_admin_ajax();
		$probe = new Mcpwp_Gate_1103_Harness();
		$this->assertTrue( $probe->requester_acts_as_user() );

		$posts = new Mcpwp_REST_Posts();
		$this->assertTrue( $posts->verify_api_key( new WP_REST_Request( 'GET', '/mcpwp/v1/seo/audit', array() ) ), 'The admin passes an admin-only-until-decided row.' );

		// The same admin without edit_theme_options is refused on a theme-options row.
		$GLOBALS['mcpwp_test_user_caps'][ self::ADMIN ]['edit_theme_options'] = false;
		$this->assert_refused( ( new Mcpwp_REST_Posts() )->verify_api_key( new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/kit-css', array( 'css' => 'a{}' ) ) ), 'chat tab', 'edit_theme_options' );
	}

	public function test_admin_destructive_replay_acts_as_a_user(): void {
		$ref = new ReflectionProperty( 'Mcpwp_Destructive_Gate', 'applying_as_admin' );
		$ref->setAccessible( true );
		$ref->setValue( null, self::ADMIN );
		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;

		$probe = new Mcpwp_Gate_1103_Harness();
		$this->assertTrue( $probe->requester_acts_as_user() );

		$GLOBALS['mcpwp_test_user_caps'][ self::ADMIN ]['delete_others_pages'] = false;
		$this->assert_refused( $probe->check_user_run_capability( new WP_REST_Request( 'DELETE', '/mcpwp/v1/drafts/delete-all', array() ) ), 'replay', 'delete_others_posts+delete_others_pages' );
	}

	/** M11: the replay leg is pinned THROUGH check_permission, so `return true` in that leg turns it red. */
	public function test_admin_destructive_replay_is_capability_checked_through_check_permission(): void {
		$ref = new ReflectionProperty( 'Mcpwp_Destructive_Gate', 'applying_as_admin' );
		$ref->setAccessible( true );
		$ref->setValue( null, self::ADMIN );
		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;
		$GLOBALS['mcpwp_test_post_caps'][ self::ADMIN ] = array( 'read_post' => array( self::POST_ID => true ), 'edit_post' => array( self::POST_ID => true ), 'delete_post' => array( self::POST_ID => true ) );

		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'DELETE', '/mcpwp/v1/drafts/delete-all', array() ) ), 'The admin passes with both delete_others_* caps.' );

		$GLOBALS['mcpwp_test_user_caps'][ self::ADMIN ]['delete_others_pages'] = false;
		$this->assert_refused( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'DELETE', '/mcpwp/v1/drafts/delete-all', array() ) ), 'replay, route capability', 'delete_others_posts+delete_others_pages' );
		// …and the post-type rule: the admin holds no delete_products.
		$this->assert_refused( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'DELETE', '/mcpwp/v1/posts/' . self::POST_ID, array( 'id' => self::POST_ID ) ) ), 'replay, post type', 'delete_products' );
	}

	/** M10: the admin-AJAX leg is subject to check_generic_post_type_access(). */
	public function test_trusted_admin_ajax_is_subject_to_the_post_type_rule(): void {
		$this->enter_trusted_admin_ajax();
		$this->assert_refused(
			( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'post_type' => 'product', 'status' => 'draft' ) ) ),
			'chat tab, product',
			'edit_products'
		);
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'status' => 'draft' ) ) ), 'A post is fine.' );
		$GLOBALS['mcpwp_test_user_caps'][ self::ADMIN ]['edit_products'] = true;
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x', 'post_type' => 'product', 'status' => 'draft' ) ) ), 'With the type\'s own cap, allowed.' );
	}

	/** M16: a translation creates a post of the SOURCE's type — its create_posts is checked. */
	public function test_translation_needs_the_source_types_create_posts(): void {
		$GLOBALS['mcpwp_test_post_type_caps']['product']['create_posts'] = 'create_products';
		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['edit_products'] = true;
		$GLOBALS['mcpwp_test_post_caps'][ self::EDITOR ] = array( 'read_post' => array( self::POST_ID => true ), 'edit_post' => array( self::POST_ID => true ) );
		$route = '/mcpwp/v1/posts/' . self::POST_ID . '/translations';

		$this->assert_refused( $this->check( 'POST', $route, $this->token( self::EDITOR ), array( 'id' => self::POST_ID, 'language' => 'fr' ) ), 'translation', 'create_products' );

		$GLOBALS['mcpwp_test_user_caps'][ self::EDITOR ]['create_products'] = true;
		$this->assertTrue( $this->check( 'POST', $route, $this->token( self::EDITOR ), array( 'id' => self::POST_ID, 'language' => 'fr' ) ) );
	}

	public function test_no_leg_fires_for_a_plain_request(): void {
		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;
		$this->assertFalse( ( new Mcpwp_Gate_1103_Harness() )->requester_acts_as_user(), 'No principal, no AJAX, no replay: not a user-run principal.' );
	}
}
