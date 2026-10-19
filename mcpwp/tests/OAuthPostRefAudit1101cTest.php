<?php
/**
 * #1101 PR-3c — the per-post map under an Author minimum role.
 *
 * Drives the real verdict chain (verify_api_key() → the post-ref
 * chokepoint, what every controller's check_permission() runs) as an
 * Author's OAuth token:
 *  - an Author cannot write another user's post through any declared field;
 *  - an Author may reference a published post (or a public attachment)
 *    read-only — '@read' / '@read-user' refs need read_post, not edit_post;
 *  - an Author cannot reference another user's draft or private post (or an
 *    attachment of one), even read-only;
 *  - a value the guard and a handler's absint() read differently is refused;
 *  - bot keys keep their scope-only behaviour for the new nested refs.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

final class OAuthPostRefAudit1101cTest extends TestCase {

	const AUTHOR   = 11;
	const OTHER    = 12;
	const PAGE_EDITOR = 13; // edit_pages, but not edit_others_pages

	const OWN      = 9601; // Author's own published post
	const PUB      = 9602; // other's published post
	const DRAFT    = 9603; // other's draft
	const PRIV     = 9604; // other's private post
	const ATT_FREE = 9605; // unattached attachment (reads as published)
	const ATT_DRAFT = 9606; // attachment of other's draft
	const PAGE_SRC = 9607; // other's published page (clone source)

	const AUTHOR_CAPS = array( 'read' => true, 'edit_posts' => true, 'edit_published_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'delete_published_posts' => true, 'upload_files' => true );

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		foreach ( array( self::AUTHOR, self::OTHER, self::PAGE_EDITOR ) as $uid ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $uid, 'roles' => array(), 'user_login' => 'u' . $uid );
		}
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array(
			self::AUTHOR      => self::AUTHOR_CAPS,
			self::PAGE_EDITOR => array_merge( self::AUTHOR_CAPS, array( 'edit_pages' => true, 'edit_published_pages' => true, 'publish_pages' => true, 'edit_others_posts' => true ) ),
		);
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::OWN       => new WP_Post( array( 'ID' => self::OWN, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => self::AUTHOR, 'post_title' => 'own' ) ),
			self::PUB       => new WP_Post( array( 'ID' => self::PUB, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => self::OTHER, 'post_title' => 'pub' ) ),
			self::DRAFT     => new WP_Post( array( 'ID' => self::DRAFT, 'post_type' => 'post', 'post_status' => 'draft', 'post_author' => self::OTHER, 'post_title' => 'draft' ) ),
			self::PRIV      => new WP_Post( array( 'ID' => self::PRIV, 'post_type' => 'post', 'post_status' => 'private', 'post_author' => self::OTHER, 'post_title' => 'priv' ) ),
			self::ATT_FREE  => new WP_Post( array( 'ID' => self::ATT_FREE, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_author' => self::OTHER, 'post_parent' => 0, 'post_title' => 'img' ) ),
			self::ATT_DRAFT => new WP_Post( array( 'ID' => self::ATT_DRAFT, 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_author' => self::OTHER, 'post_parent' => self::DRAFT, 'post_title' => 'img2' ) ),
			self::PAGE_SRC  => new WP_Post( array( 'ID' => self::PAGE_SRC, 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => self::OTHER, 'post_title' => 'page' ) ),
		);
		// What WordPress answers for an Author (map_meta_cap): read their own
		// and published content; edit only their own.
		$GLOBALS['mcpwp_test_post_caps'] = array(
			self::AUTHOR      => array(
				'read_post'   => array( self::OWN => true, self::PUB => true, self::DRAFT => false, self::PRIV => false, self::ATT_FREE => true, self::ATT_DRAFT => false, self::PAGE_SRC => true ),
				'edit_post'   => array( self::OWN => true, self::PUB => false, self::DRAFT => false, self::PRIV => false, self::ATT_FREE => false, self::ATT_DRAFT => false, self::PAGE_SRC => false ),
				'delete_post' => array( self::OWN => true, self::PUB => false, self::DRAFT => false, self::PRIV => false, self::ATT_FREE => false, self::ATT_DRAFT => false, self::PAGE_SRC => false ),
			),
			self::PAGE_EDITOR => array(
				'read_post' => array( self::PAGE_SRC => true, self::OWN => true ),
				'edit_post' => array( self::PAGE_SRC => false, self::OWN => true ),
			),
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_users']        = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_post_caps']    = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	// ── helpers ───────────────────────────────────────────────────────────

	private function token( int $user = self::AUTHOR ): array {
		$t = ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => $user, 'client_id' => 'test_client' ) )['access_token'];
		return array( 'Authorization' => 'Bearer ' . $t );
	}

	private function bot(): array {
		$created = ( new Mcpwp_Api_Auth_Test_Harness() )->create_scoped_api_key( 'bot', array( 'read', 'write' ), 'custom', array( 'content', 'seo', 'elementor', 'media' ) );
		return array( 'X-API-Key' => $created['key'] );
	}

	private function check( string $method, string $route, array $params, array $headers ) {
		$saved  = $GLOBALS['mcpwp_test_current_user'];
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( $method, '/mcpwp/v1' . $route, $params, $headers ) );
		$GLOBALS['mcpwp_test_current_user'] = $saved;
		return $result;
	}

	private function assert_refused( $result, string $context ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertContains( $result->get_error_data()['status'] ?? 0, array( 403, 404 ), $context . ': ' . $result->get_error_code() );
	}

	private function assert_not_found( $result, string $context ): void {
		$this->assertInstanceOf( WP_Error::class, $result, $context );
		$this->assertSame( 'not_found', $result->get_error_code(), $context );
		$this->assertSame( 404, $result->get_error_data()['status'], $context );
	}

	// ── 1. no write to another user's post through any declared field ──────

	/** @return array<string,array> Every WRITE field on a route an Author reaches. */
	public static function write_fields(): array {
		return array(
			'PUT /posts/{id}'                => array( 'PUT', '/posts/' . self::PUB, array( 'id' => self::PUB, 'title' => 'x' ) ),
			'POST /posts/{id}'               => array( 'POST', '/posts/' . self::PUB, array( 'id' => self::PUB, 'title' => 'x' ) ),
			'DELETE /posts/{id}'             => array( 'DELETE', '/posts/' . self::PUB, array( 'id' => self::PUB ) ),
			'PUT /posts/bulk updates[].id'   => array( 'PUT', '/posts/bulk', array( 'updates' => array( array( 'id' => self::PUB, 'title' => 'x' ) ) ) ),
			'PUT /posts/bulk posts[].id'     => array( 'PUT', '/posts/bulk', array( 'posts' => array( array( 'id' => self::PUB, 'title' => 'x' ) ) ) ),
			'POST /posts/{id}/featured-image' => array( 'POST', '/posts/' . self::PUB . '/featured-image', array( 'id' => self::PUB, 'media_id' => self::ATT_FREE ) ),
			'POST /posts/{id}/translations'  => array( 'POST', '/posts/' . self::PUB . '/translations', array( 'id' => self::PUB, 'language' => 'fr' ) ),
			'POST /post-meta/{id}'           => array( 'POST', '/post-meta/' . self::PUB, array( 'id' => self::PUB, 'key' => 'k', 'value' => 'v' ) ),
			'POST /seo/{id}'                 => array( 'POST', '/seo/' . self::PUB, array( 'id' => self::PUB, 'title' => 'x' ) ),
			'POST /blocks/{id}'              => array( 'POST', '/blocks/' . self::PUB, array( 'id' => self::PUB, 'content' => 'x' ) ),
			'POST /elementor/{id}'           => array( 'POST', '/elementor/' . self::PUB, array( 'id' => self::PUB, 'elementor_data' => '[]' ) ),
			'POST /elementor/{id}/patch'     => array( 'POST', '/elementor/' . self::PUB . '/patch', array( 'id' => self::PUB ) ),
			'POST /elementor/regenerate-css' => array( 'POST', '/elementor/regenerate-css', array( 'id' => self::PUB ) ),
			'POST /content-graph/apply-link source_id' => array( 'POST', '/content-graph/apply-link', array( 'source_id' => self::PUB, 'target_id' => self::OWN, 'anchor' => 'a' ) ),
			'POST /media/{id} (another user\'s file)'  => array( 'POST', '/media/' . self::ATT_FREE, array( 'id' => self::ATT_FREE, 'title' => 'x' ) ),
			'DELETE /media/{id}'             => array( 'DELETE', '/media/' . self::ATT_FREE, array( 'id' => self::ATT_FREE ) ),
		);
	}

	/**
	 * @dataProvider write_fields
	 */
	public function test_an_author_cannot_write_another_users_post_through_any_declared_field( string $method, string $route, array $params ): void {
		$this->assert_refused( $this->check( $method, $route, $params, $this->token() ), "$method $route" );
	}

	public function test_an_author_still_writes_their_own_post(): void {
		$this->assertTrue( $this->check( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'title' => 'x' ), $this->token() ) );
		$this->assertTrue( ( new Mcpwp_REST_Posts() )->check_permission( new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/' . self::OWN, array( 'id' => self::OWN, 'title' => 'x' ), $this->token() ) ), 'Through the controller\'s check_permission too.' );
	}

	// ── 2. read-only refs: published is fine ──────────────────────────────

	/** @return array<string,array> */
	public static function readable_refs(): array {
		return array(
			'apply-link target_id (was edit_post)' => array( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => self::PUB, 'anchor' => 'see' ) ),
			'POST /posts featured_image'           => array( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => self::ATT_FREE ) ),
			'PUT /posts/{own} featured_image'      => array( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'featured_image' => self::ATT_FREE ) ),
			'POST /posts/{own}/featured-image'     => array( 'POST', '/posts/' . self::OWN . '/featured-image', array( 'id' => self::OWN, 'media_id' => self::ATT_FREE ) ),
			'POST /posts/bulk posts[].featured_image' => array( 'POST', '/posts/bulk', array( 'posts' => array( array( 'title' => 'x', 'featured_image' => self::ATT_FREE ) ) ) ),
			'GET /content-graph/suggestions'       => array( 'GET', '/content-graph/suggestions', array( 'source_id' => self::PUB ) ),
			'apply-link target a published page (no edit_pages)' => array( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => self::PAGE_SRC, 'anchor' => 'see' ) ),
		);
	}

	/**
	 * @dataProvider readable_refs
	 */
	public function test_an_author_may_reference_published_content_read_only( string $method, string $route, array $params ): void {
		$this->assertTrue( $this->check( $method, $route, $params, $this->token() ), "$method $route" );
	}

	// ── 3. read-only refs: another user's draft / private is not ──────────

	/** @return array<string,array> */
	public static function hidden_refs(): array {
		return array(
			'apply-link target draft'       => array( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => self::DRAFT, 'anchor' => 'a' ) ),
			'apply-link target private'     => array( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => self::PRIV, 'anchor' => 'a' ) ),
			'POST /posts featured_image of a draft\'s file' => array( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => self::ATT_DRAFT ) ),
			'PUT /posts/{own} featured_image'                => array( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'featured_image' => self::ATT_DRAFT ) ),
			'POST /posts/{own}/featured-image media_id'      => array( 'POST', '/posts/' . self::OWN . '/featured-image', array( 'id' => self::OWN, 'media_id' => self::ATT_DRAFT ) ),
			'POST /posts/bulk posts[].featured_image'         => array( 'POST', '/posts/bulk', array( 'posts' => array( array( 'title' => 'x', 'featured_image' => self::ATT_DRAFT ) ) ) ),
			'PUT /posts/bulk updates[].featured_image'        => array( 'PUT', '/posts/bulk', array( 'updates' => array( array( 'id' => self::OWN, 'featured_image' => self::ATT_DRAFT ) ) ) ),
			'GET /content-graph/suggestions draft'            => array( 'GET', '/content-graph/suggestions', array( 'source_id' => self::DRAFT ) ),
		);
	}

	/**
	 * @dataProvider hidden_refs
	 */
	public function test_an_author_cannot_reference_another_users_draft_even_read_only( string $method, string $route, array $params ): void {
		$this->assert_not_found( $this->check( $method, $route, $params, $this->token() ), "$method $route" );
	}

	public function test_a_draft_reference_reads_exactly_like_a_missing_one(): void {
		$draft   = $this->check( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => self::DRAFT, 'anchor' => 'a' ), $this->token() );
		$missing = $this->check( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => 9699, 'anchor' => 'a' ), $this->token() );
		$this->assertSame( array( $missing->get_error_code(), $missing->get_error_message(), $missing->get_error_data() ), array( $draft->get_error_code(), $draft->get_error_message(), $draft->get_error_data() ) );
	}

	// ── read class for a clone source (Editor-level route) ────────────────

	public function test_a_clone_source_needs_read_post_not_edit_post(): void {
		$GLOBALS['mcpwp_test_user_caps'][ self::PAGE_EDITOR ]['manage_options'] = false;
		$this->assertTrue( $this->check( 'POST', '/pages/' . self::PAGE_SRC . '/clone', array( 'id' => self::PAGE_SRC ), $this->token( self::PAGE_EDITOR ) ), 'Readable but not editable: a clone may copy it.' );
		$GLOBALS['mcpwp_test_post_caps'][ self::PAGE_EDITOR ]['read_post'][ self::PAGE_SRC ] = false;
		$this->assert_not_found( $this->check( 'POST', '/pages/' . self::PAGE_SRC . '/clone', array( 'id' => self::PAGE_SRC ), $this->token( self::PAGE_EDITOR ) ), 'Unreadable: hidden.' );
	}

	// ── ambiguous values ──────────────────────────────────────────────────

	/** @return array<string,array> */
	public static function ambiguous_values(): array {
		return array(
			'suffix' => array( self::DRAFT . 'abc' ),
			'true'   => array( true ),
			'list'   => array( array( self::DRAFT ) ),
		);
	}

	/**
	 * @dataProvider ambiguous_values
	 * @param mixed $value
	 */
	public function test_a_value_the_guard_and_a_handler_read_differently_is_refused( $value ): void {
		foreach ( array( $this->token(), $this->bot() ) as $headers ) {
			$result = $this->check( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => $value, 'anchor' => 'a' ), $headers );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_post_ref', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
		$this->assertSame( 'invalid_post_ref', $this->check( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => $value ), $this->token() )->get_error_code() );
	}

	public function test_an_ambiguous_nested_read_ref_in_a_bulk_item_is_refused_for_a_user_and_unchanged_for_a_bot(): void {
		$params = array( 'posts' => array( array( 'title' => 'x', 'featured_image' => self::ATT_FREE . 'abc' ) ) );
		$user   = $this->check( 'POST', '/posts/bulk', $params, $this->token() );
		$this->assertInstanceOf( WP_Error::class, $user );
		$this->assertSame( 'invalid_item', $user->get_error_code(), 'The bulk shape gate answers it before any reference is resolved (bulk_item_list_params() includes read-user fields for a user).' );
		$this->assertSame( 400, $user->get_error_data()['status'] );
		$this->assertTrue( $this->check( 'POST', '/posts/bulk', $params, $this->bot() ), 'A bot key is not checked on read-user refs.' );
	}

	/**
	 * A read-only source (a landing page's template, a clone's source) still
	 * counts as a target of the #1032 pre-existing-reference exemption: its
	 * stored refs are copied into the new post by the handler anyway, so a
	 * payload repeating them is not re-judged. A link target is not
	 * (written_post_ref_scalar_overrides()).
	 */
	public function test_read_only_sources_stay_in_the_template_exemption_set_and_link_targets_do_not(): void {
		$h = new class() {
			use Mcpwp_Api_Auth {
				collect_post_refs as public;
				written_post_ref_target_ids as public;
			}
			public function act_as( $record ) {
				$this->current_api_key_record = $record;
			}
		};
		$h->act_as( array( 'type' => 'api_key', 'scopes' => array( 'read', 'write' ) ) );
		foreach ( array(
			array( '/elementor/landing-page', array( 'template_id' => self::PUB, 'title' => 'x' ), array( self::PUB ) ),
			array( '/elementor/clone', array( 'source_id' => self::PUB ), array( self::PUB ) ),
			array( '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => self::PUB ), array( self::OWN ) ),
		) as list( $route, $params, $expected ) ) {
			$request = new WP_REST_Request( 'POST', '/mcpwp/v1' . $route, $params );
			$this->assertSame( $expected, $h->written_post_ref_target_ids( $request, $h->collect_post_refs( $request ) ), $route );
		}
	}

	public function test_a_list_filter_named_like_a_read_user_ref_is_not_judged_on_a_get(): void {
		$GLOBALS['_mcpwp_test_posts'][9608] = new WP_Post( array( 'ID' => 9608, 'post_type' => 'page', 'post_status' => 'draft', 'post_author' => self::OTHER, 'post_title' => 'hidden parent' ) );
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['read_post'][9608] = false;
		$this->assertTrue( $this->check( 'GET', '/pages', array( 'parent' => 9608 ), $this->token() ), 'GET /pages?parent= stays a list filter (results clamped to published), as before.' );
		$GLOBALS['mcpwp_test_post_caps'][ self::PAGE_EDITOR ]['read_post'][9608] = false;
		$this->assertTrue( $this->check( 'GET', '/pages', array( 'parent' => 9608 ), $this->token( self::PAGE_EDITOR ) ) );
		$this->assert_not_found( $this->check( 'POST', '/pages', array( 'title' => 'x', 'parent' => 9608 ), $this->token( self::PAGE_EDITOR ) ), 'Setting it as the parent of a write is judged (a user who can create pages).' );
	}

	// ── bots keep scope-only behaviour for the new nested refs ────────────

	public function test_a_bot_key_is_not_checked_on_the_new_nested_references(): void {
		$this->assertTrue( $this->check( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => self::ATT_DRAFT ), $this->bot() ) );
		$this->assertTrue( $this->check( 'POST', '/posts/' . self::OWN . '/featured-image', array( 'id' => self::OWN, 'media_id' => self::ATT_DRAFT ), $this->bot() ) );
		$this->assertTrue( $this->check( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => 9699 ), $this->bot() ), 'Not even a missing id: unchanged.' );
	}
}
