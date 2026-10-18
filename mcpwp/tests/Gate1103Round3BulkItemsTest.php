<?php
/**
 * #1103 round 3: the bulk-item parser differential, closed by a scope cut.
 *
 * The auth gate reads post types and post ids only from ARRAY items; the
 * bulk handlers used to json_decode a STRING item too, so a string item
 * created a product, or edited a post the per-post edit_post check never
 * saw. Now every bulk door refuses the WHOLE request (400 invalid_item)
 * when any item of a list the gate reads is not an object — before any
 * post-type or post-reference extraction — and normalize_bulk_items() no
 * longer decodes strings, so the handler cannot re-parse behind the gate.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-seo.php';

final class Gate1103Round3BulkItemsTest extends TestCase {

	const EDITOR     = 7;
	const AUTHOR     = 11;
	const ADMIN      = 1;
	const PRODUCT_ID = 9401;
	const ADMIN_POST = 9402;
	const PAGE_ID    = 9403;

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$this->saved_users                  = $GLOBALS['mcpwp_test_users'];
		foreach ( array( self::EDITOR, self::AUTHOR, self::ADMIN ) as $id ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $id, 'roles' => array(), 'user_login' => 'u' . $id );
		}
		$GLOBALS['mcpwp_test_user_caps'] = array(
			self::EDITOR => array_fill_keys( RouteCapabilityMapTest::EDITOR_CAPS, true ),
			self::AUTHOR => array_fill_keys( array( 'read', 'edit_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_published_posts', 'upload_files' ), true ),
			self::ADMIN  => array_fill_keys( array_merge( RouteCapabilityMapTest::EDITOR_CAPS, array( 'manage_options', 'activate_plugins' ) ), true ),
		);
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'product' );
		$GLOBALS['mcpwp_test_post_type_caps']    = array(
			'product' => array( 'create_posts' => 'edit_products', 'edit_posts' => 'edit_products', 'delete_posts' => 'delete_products' ),
		);
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PRODUCT_ID => new WP_Post( array( 'ID' => self::PRODUCT_ID, 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'Hoodie', 'post_author' => self::ADMIN ) ),
			self::ADMIN_POST => new WP_Post( array( 'ID' => self::ADMIN_POST, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Admin post', 'post_author' => self::ADMIN ) ),
			self::PAGE_ID    => new WP_Post( array( 'ID' => self::PAGE_ID, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Admin page', 'post_author' => self::ADMIN ) ),
		);
		// The Author may edit nothing of the admin's; the Editor may edit all three.
		$GLOBALS['mcpwp_test_post_caps'] = array(
			self::EDITOR => array(
				'read_post'   => array_fill_keys( array( self::PRODUCT_ID, self::ADMIN_POST, self::PAGE_ID ), true ),
				'edit_post'   => array_fill_keys( array( self::PRODUCT_ID, self::ADMIN_POST, self::PAGE_ID ), true ),
				'delete_post' => array_fill_keys( array( self::PRODUCT_ID, self::ADMIN_POST, self::PAGE_ID ), true ),
			),
			self::AUTHOR => array(
				'read_post' => array_fill_keys( array( self::ADMIN_POST, self::PAGE_ID ), true ),
			),
			self::ADMIN  => array(
				'read_post' => array_fill_keys( array( self::PRODUCT_ID, self::ADMIN_POST, self::PAGE_ID ), true ),
				'edit_post' => array_fill_keys( array( self::PRODUCT_ID, self::ADMIN_POST, self::PAGE_ID ), true ),
			),
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']             = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']         = array();
		$GLOBALS['mcpwp_test_current_user']      = 0;
		$GLOBALS['mcpwp_test_public_post_types'] = array();
		$GLOBALS['mcpwp_test_post_type_caps']    = array();
		$GLOBALS['_mcpwp_test_posts']            = array();
		$GLOBALS['mcpwp_test_post_caps']         = array();
	}

	// ── principals ─────────────────────────────────────────────────────────

	private static function headers( string $who ): array {
		if ( 'write key' === $who ) {
			$created = ( new Mcpwp_Api_Auth_Test_Harness() )->create_scoped_api_key( 'bot', array( 'read', 'write' ), 'custom', array( 'content', 'woocommerce', 'seo' ) );
			return array( 'X-API-Key' => $created['key'] );
		}
		$user  = array( 'editor token' => self::EDITOR, 'author token' => self::AUTHOR, 'admin token' => self::ADMIN )[ $who ];
		$token = ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => $user ) )['access_token'];
		return array( 'Authorization' => 'Bearer ' . $token );
	}

	private static function controller( string $route ) {
		if ( 0 === strpos( $route, '/mcpwp/v1/pages' ) ) {
			return new Mcpwp_REST_Pages();
		}
		if ( 0 === strpos( $route, '/mcpwp/v1/seo' ) ) {
			return new Mcpwp_REST_SEO( null );
		}
		return new Mcpwp_REST_Posts();
	}

	private static function handler( string $method, string $route ): string {
		$map = array(
			'POST /mcpwp/v1/posts/bulk' => 'bulk_create_posts',
			'PUT /mcpwp/v1/posts/bulk'  => 'bulk_update_posts',
			'POST /mcpwp/v1/pages/bulk' => 'bulk_create_pages',
			'PUT /mcpwp/v1/pages/bulk'  => 'bulk_update_pages',
			'POST /mcpwp/v1/seo/bulk'   => 'bulk_update',
		);
		return $map[ $method . ' ' . $route ];
	}

	private function snapshot(): array {
		$out = array();
		foreach ( $GLOBALS['_mcpwp_test_posts'] as $id => $post ) {
			$out[ $id ] = array( $post->post_type, $post->post_title, $post->post_status );
		}
		return $out;
	}

	// ── every bulk door × create/update × principal, string items ─────────

	/**
	 * Every bulk door the gate reads per item (bulk_item_list_params()):
	 * POST/PUT /posts/bulk (posts, updates), POST/PUT /pages/bulk (pages,
	 * updates), POST /seo/bulk (updates).
	 */
	public static function string_item_cases(): array {
		$doors = array(
			'posts/bulk create'         => array( 'POST', '/mcpwp/v1/posts/bulk', 'posts', array( 'post_type' => 'product', 'title' => 'Sneaky product', 'status' => 'draft' ) ),
			'posts/bulk update posts'   => array( 'PUT', '/mcpwp/v1/posts/bulk', 'posts', array( 'id' => self::PRODUCT_ID, 'title' => 'Pwned' ) ),
			'posts/bulk update updates' => array( 'PUT', '/mcpwp/v1/posts/bulk', 'updates', array( 'id' => self::ADMIN_POST, 'title' => 'Pwned' ) ),
			'pages/bulk create'         => array( 'POST', '/mcpwp/v1/pages/bulk', 'pages', array( 'title' => 'Sneaky page', 'status' => 'draft' ) ),
			'pages/bulk update pages'   => array( 'PUT', '/mcpwp/v1/pages/bulk', 'pages', array( 'id' => self::PAGE_ID, 'title' => 'Pwned' ) ),
			'pages/bulk update updates' => array( 'PUT', '/mcpwp/v1/pages/bulk', 'updates', array( 'id' => self::PAGE_ID, 'title' => 'Pwned' ) ),
			'seo/bulk update'           => array( 'POST', '/mcpwp/v1/seo/bulk', 'updates', array( 'id' => self::ADMIN_POST, 'title' => 'Pwned SEO title' ) ),
		);
		$out = array();
		foreach ( $doors as $door => $case ) {
			foreach ( array( 'write key', 'editor token', 'author token' ) as $who ) {
				$out[ "$door / $who" ] = array_merge( $case, array( $who ) );
			}
		}
		return $out;
	}

	/**
	 * @dataProvider string_item_cases
	 */
	public function test_a_string_item_refuses_the_whole_request( string $method, string $route, string $list, array $item, string $who ): void {
		$before = $this->snapshot();
		// A string item, alone and next to a harmless array item.
		foreach ( array( array( wp_json_encode( $item ) ), array( array( 'title' => 'ok' ), wp_json_encode( $item ) ) ) as $items ) {
			$request = new WP_REST_Request( $method, $route, array( $list => $items ), self::headers( $who ) );
			$result  = self::controller( $route )->check_permission( $request );
			$this->assertInstanceOf( WP_Error::class, $result, "$who $method $route" );
			$this->assertSame( 'invalid_item', $result->get_error_code(), "$who $method $route" );
			$this->assertSame( 400, $result->get_error_data()['status'] );
			$this->assertSame( $list, $result->get_error_data()['param'] );
		}
		$this->assertSame( $before, $this->snapshot(), 'Nothing was created or changed.' );
	}

	public function test_a_non_list_value_for_a_bulk_list_is_refused(): void {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/posts/bulk', array( 'posts' => wp_json_encode( array( array( 'post_type' => 'product', 'title' => 'x' ) ) ) ), self::headers( 'write key' ) );
		$result  = ( new Mcpwp_REST_Posts() )->check_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_item', $result->get_error_code() );
	}

	/**
	 * Behind the gate, the handlers no longer decode a string item: even if a
	 * future path skipped the gate, a string item changes nothing.
	 */
	public function test_handlers_do_not_decode_string_items(): void {
		$cases = array(
			array( 'POST', '/mcpwp/v1/posts/bulk', 'posts', array( 'post_type' => 'post', 'title' => 'From a string' ) ),
			array( 'PUT', '/mcpwp/v1/posts/bulk', 'updates', array( 'id' => self::ADMIN_POST, 'title' => 'Pwned' ) ),
			array( 'POST', '/mcpwp/v1/pages/bulk', 'pages', array( 'title' => 'From a string' ) ),
			array( 'PUT', '/mcpwp/v1/pages/bulk', 'updates', array( 'id' => self::PAGE_ID, 'title' => 'Pwned' ) ),
		);
		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;
		$before                             = $this->snapshot();
		foreach ( $cases as list( $method, $route, $list, $item ) ) {
			$controller = self::controller( $route );
			$result     = $controller->{ self::handler( $method, $route ) }( new WP_REST_Request( $method, $route, array( $list => array( wp_json_encode( $item ) ) ) ) );
			$data       = $result instanceof WP_REST_Response ? $result->get_data() : $result;
			$this->assertStringContainsString( 'must be an object', wp_json_encode( $data ), "$method $route" );
		}
		$this->assertSame( $before, $this->snapshot(), 'A string item created or changed nothing.' );
	}

	// ── declared per-item ids must read the same to the gate and the handler ──

	/**
	 * Every declared 'list[].field' post id on a bulk door × an id the
	 * gate's is_numeric() and a handler's absint() disagree on. Found while
	 * fixing the string-item bug: an Author token sending "9402abc" passed
	 * the per-post edit_post check (the gate skipped the non-numeric id) and
	 * absint() then edited post 9402; [9402] passed and became post 1.
	 */
	public static function ambiguous_id_cases(): array {
		$fields = array(
			array( 'PUT', '/mcpwp/v1/posts/bulk', 'posts', 'id' ),
			array( 'PUT', '/mcpwp/v1/posts/bulk', 'updates', 'id' ),
			array( 'PUT', '/mcpwp/v1/pages/bulk', 'pages', 'id' ),
			array( 'PUT', '/mcpwp/v1/pages/bulk', 'updates', 'id' ),
			array( 'POST', '/mcpwp/v1/seo/bulk', 'updates', 'id' ),
			array( 'POST', '/mcpwp/v1/seo/bulk', 'updates', 'post_id' ),
		);
		$values = array( 'suffix' => '9402abc', 'hex' => '0x24C2', 'list' => array( 9402 ), 'float' => 9402.0, 'padded' => ' 9402', 'negative' => -9402 );
		$out    = array();
		foreach ( $fields as list( $method, $route, $list, $field ) ) {
			foreach ( $values as $label => $value ) {
				foreach ( array( 'write key', 'editor token', 'author token' ) as $who ) {
					$out[ "$method $route {$list}[].{$field}=$label / $who" ] = array( $method, $route, $list, $field, $value, $who );
				}
			}
		}
		return $out;
	}

	/**
	 * @dataProvider ambiguous_id_cases
	 * @param mixed $value
	 */
	public function test_an_ambiguous_item_id_refuses_the_whole_request( string $method, string $route, string $list, string $field, $value, string $who ): void {
		$before  = $this->snapshot();
		$request = new WP_REST_Request( $method, $route, array( $list => array( array( $field => $value, 'title' => 'Pwned' ) ) ), self::headers( $who ) );
		$result  = self::controller( $route )->check_permission( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_item', $result->get_error_code() );
		$this->assertSame( 0, $result->get_error_data()['index'] );
		$this->assertSame( $before, $this->snapshot() );
	}

	public function test_canonical_item_ids_are_judged_as_before(): void {
		foreach ( array( self::ADMIN_POST, (string) self::ADMIN_POST ) as $id ) {
			$updates = array( 'updates' => array( array( 'id' => $id, 'title' => 'Pwned' ) ) );
			$this->assertSame( 'rest_cannot_edit', ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk', $updates, self::headers( 'author token' ) ) )->get_error_code(), var_export( $id, true ) );
			$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk', $updates, self::headers( 'editor token' ) ) ), var_export( $id, true ) );
		}
		// Empty ids are the handler's "id is required" answer, never a write.
		foreach ( array( null, '', 0, '0' ) as $empty ) {
			$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk', array( 'updates' => array( array( 'id' => $empty, 'title' => 'x' ) ) ), self::headers( 'editor token' ) ) ), var_export( $empty, true ) );
		}
	}

	// ── array-form controls behave as before ──────────────────────────────

	public function test_array_items_are_judged_exactly_as_before(): void {
		$product = array( array( 'post_type' => 'product', 'title' => 'x' ) );
		$this->assertSame( 'insufficient_scope', ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/posts/bulk', array( 'posts' => $product ), self::headers( 'write key' ) ) )->get_error_code() );
		$this->assertSame( 'rest_forbidden_capability', ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/posts/bulk', array( 'posts' => $product ), self::headers( 'editor token' ) ) )->get_error_code() );
		$this->assertSame( 'rest_cannot_edit', ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk', array( 'updates' => array( array( 'id' => self::ADMIN_POST, 'title' => 'Pwned' ) ) ), self::headers( 'author token' ) ) )->get_error_code() );

		$draft = array( array( 'title' => 'A draft', 'status' => 'draft' ) );
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/posts/bulk', array( 'posts' => $draft ), self::headers( 'write key' ) ) ) );
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/posts/bulk', array( 'posts' => $draft ), self::headers( 'author token' ) ) ) );
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk', array( 'updates' => array( array( 'id' => self::ADMIN_POST, 'title' => 'Edited' ) ) ), self::headers( 'editor token' ) ) ) );
		$this->assertTrue( ( new Mcpwp_REST_Pages() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/pages/bulk', array( 'updates' => array( array( 'id' => self::PAGE_ID, 'title' => 'Edited' ) ) ), self::headers( 'editor token' ) ) ) );
		// The SEO bulk row's own capability is the map's (admin-only for an Editor); an admin passes.
		$this->assertTrue( ( new Mcpwp_REST_SEO( null ) )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/seo/bulk', array( 'updates' => array( array( 'id' => self::ADMIN_POST, 'title' => 'T' ) ) ), self::headers( 'admin token' ) ) ) );
		$this->assertTrue( ( new Mcpwp_REST_SEO( null ) )->check_permission( new WP_REST_Request( 'POST', '/mcpwp/v1/seo/bulk', array( 'updates' => array( array( 'id' => self::ADMIN_POST, 'title' => 'T' ) ) ), self::headers( 'write key' ) ) ) );
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/bulk', array( 'updates' => array(), 'posts' => null ), self::headers( 'editor token' ) ) ), 'An empty or absent list is the handler\'s to answer.' );

		$GLOBALS['mcpwp_test_current_user'] = self::ADMIN;
		$count                              = count( $GLOBALS['_mcpwp_test_posts'] );
		( new Mcpwp_REST_Posts() )->bulk_create_posts( new WP_REST_Request( 'POST', '/mcpwp/v1/posts/bulk', array( 'posts' => $draft ) ) );
		$this->assertCount( $count + 1, $GLOBALS['_mcpwp_test_posts'], 'An array item is still created.' );
	}
}
