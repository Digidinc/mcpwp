<?php
/**
 * #1118 (PR-3c round 2) — the gate findings this PR keeps, pinned (the
 * /post-meta id-meta refs were cut in the scope cut; they get their own PR):
 *  P0-1 a negative / ambiguous id is refused 400 for every principal;
 *  P0-2 only a clone's or landing page's SOURCE is template-exempt — a
 *       featured image, parent or media_id never is;
 *  P0-3 /elementor/clone charges the create cap of the SOURCE's type;
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

final class PostRefRound2_1118Test extends TestCase {

	const AUTHOR      = 11;
	const OTHER       = 12;
	const PAGE_EDITOR = 13;

	const OWN       = 9601;
	const PUB       = 9602;
	const DRAFT     = 9603;
	const ATT_FREE  = 9605;
	const ATT_DRAFT = 9606;
	const PAGE_SRC  = 9607;
	const OWN_PAGE  = 9610;
	const PRODUCT   = 9609;
	const LIBRARY   = 9611;
	const REVISION  = 9620; // A revision of another user's draft.
	const INHERIT   = 9621; // A non-attachment custom type with status 'inherit'.

	const AUTHOR_CAPS = array( 'read' => true, 'edit_posts' => true, 'edit_published_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'upload_files' => true );

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
			self::PAGE_EDITOR => array_merge( self::AUTHOR_CAPS, array( 'edit_pages' => true, 'edit_published_pages' => true, 'publish_pages' => true ) ),
		);
		$P = static function ( $id, $type, $status, $author, $parent = 0 ) {
			return new WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_author' => $author, 'post_parent' => $parent, 'post_title' => 't' . $id ) );
		};
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::OWN       => $P( self::OWN, 'post', 'publish', self::AUTHOR ),
			self::OWN_PAGE  => $P( self::OWN_PAGE, 'page', 'publish', self::PAGE_EDITOR ),
			self::PUB       => $P( self::PUB, 'post', 'publish', self::OTHER ),
			self::DRAFT     => $P( self::DRAFT, 'post', 'draft', self::OTHER ),
			self::ATT_FREE  => $P( self::ATT_FREE, 'attachment', 'inherit', self::OTHER ),
			self::ATT_DRAFT => $P( self::ATT_DRAFT, 'attachment', 'inherit', self::OTHER, self::DRAFT ),
			self::PAGE_SRC  => $P( self::PAGE_SRC, 'page', 'publish', self::OTHER ),
			self::PRODUCT   => $P( self::PRODUCT, 'product', 'publish', self::OTHER ),
			self::LIBRARY   => $P( self::LIBRARY, 'elementor_library', 'publish', self::OTHER ),
			self::REVISION  => $P( self::REVISION, 'revision', 'inherit', self::OTHER, self::DRAFT ),
			self::INHERIT   => $P( self::INHERIT, 'mcpwp_thing', 'inherit', self::OTHER ),
		);
		$deny = array( self::DRAFT => false, self::ATT_DRAFT => false );
		// REVISION / INHERIT read as readable on purpose: the rule hides them
		// whatever read_post answers.
		$read = array( self::OWN => true, self::OWN_PAGE => true, self::PUB => true, self::ATT_FREE => true, self::PAGE_SRC => true, self::PRODUCT => true, self::LIBRARY => true, self::REVISION => true, self::INHERIT => true );
		$GLOBALS['mcpwp_test_post_caps'] = array(
			self::AUTHOR      => array( 'read_post' => $deny + $read, 'edit_post' => array( self::OWN => true ) ),
			self::PAGE_EDITOR => array( 'read_post' => $deny + $read, 'edit_post' => array( self::OWN_PAGE => true ) ),
		);
		update_option( 'mcpwp_settings', array( 'oauth_enabled' => true ) );
		update_option( Mcpwp_OAuth_Well_Known::MIN_ROLE_OPTION, 'author' );
		// WooCommerce's product type maps its caps to edit_products; pinned so
		// a type another test registered with the bootstrap's post-like
		// default cannot change the verdict (order independence).
		$this->saved_type_caps = $GLOBALS['mcpwp_test_post_type_caps'] ?? array();
		$GLOBALS['mcpwp_test_post_type_caps']['product'] = array( 'create_posts' => 'edit_products', 'edit_posts' => 'edit_products', 'delete_posts' => 'delete_products' );
	}
	/** @var array */
	private $saved_type_caps = array();

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_post_type_caps'] = $this->saved_type_caps;
		$GLOBALS['mcpwp_test_users']        = $this->saved_users;
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_post_caps']    = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	private function token( int $user = self::AUTHOR ): array {
		$t = ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => $user, 'client_id' => 'test_client' ) )['access_token'];
		return array( 'Authorization' => 'Bearer ' . $t );
	}

	private function bot( array $scopes = array( 'read', 'write' ) ): array {
		$created = ( new Mcpwp_Api_Auth_Test_Harness() )->create_scoped_api_key( 'bot', $scopes, in_array( 'admin', $scopes, true ) ? 'admin' : 'custom', in_array( 'admin', $scopes, true ) ? array() : array( 'content', 'seo', 'elementor', 'elementor-build', 'elementor-info', 'media' ) );
		return array( 'X-API-Key' => $created['key'] );
	}

	private function check( string $method, string $route, array $params, array $headers ) {
		$saved  = $GLOBALS['mcpwp_test_current_user'];
		$result = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( $method, '/mcpwp/v1' . $route, $params, $headers ) );
		$GLOBALS['mcpwp_test_current_user'] = $saved;
		return $result;
	}

	private function code( $result ): string {
		return true === $result ? 'ALLOWED' : ( $result instanceof WP_Error ? $result->get_error_code() . '/' . ( $result->get_error_data()['status'] ?? '?' ) : 'other' );
	}

	// ── P0-1 ──────────────────────────────────────────────────────────────

	/** @return array<string,array{0:mixed,1:bool}> */
	public static function values(): array {
		return array(
			'int -5'        => array( -5, true ),
			'string -5'     => array( '-5', true ),
			'padded -5'     => array( ' -5', true ),
			'hex -0x1'      => array( '-0x1', true ),
			'float -5.0'    => array( -5.0, true ),
			'string -5.0'   => array( '-5.0', true ),
			'string +12x'   => array( '+12x', true ),
			'0'             => array( 0, false ),
			'"0"'           => array( '0', false ),
			'""'            => array( '', false ),
			'null'          => array( null, false ),
			'false'         => array( false, false ),
			'"-0"'          => array( '-0', false ),
			'"0012"'        => array( '0012', false ),
			'positive int'  => array( 12, false ),
		);
	}

	/**
	 * @dataProvider values
	 * @param mixed $value
	 */
	public function test_negative_and_ambiguous_values_are_refused_and_non_ids_pass( $value, bool $refused ): void {
		$this->assertSame( $refused, Mcpwp_REST_API::is_ambiguous_post_ref_value( $value ) );
	}

	public function test_a_negative_id_is_refused_for_every_principal_on_every_ref_class(): void {
		$cases = array(
			'write source_id'      => array( 'POST', '/content-graph/apply-link', array( 'source_id' => '-' . self::PUB, 'target_id' => self::OWN ) ),
			'read target_id'       => array( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => -self::DRAFT ) ),
			'source source_id'     => array( 'POST', '/elementor/clone', array( 'source_id' => ' -' . self::DRAFT ) ),
			'path-family id'       => array( 'POST', '/elementor/regenerate-css', array( 'id' => -self::DRAFT ) ),
		);
		foreach ( array( 'author' => $this->token(), 'editor' => $this->token( self::PAGE_EDITOR ), 'bot' => $this->bot() ) as $who => $h ) {
			foreach ( $cases as $label => list( $m, $r, $p ) ) {
				if ( 'author' === $who && '/elementor/clone' === $r ) {
					continue; // An Author is refused at the route's edit_pages first.
				}
				$this->assertSame( 'invalid_post_ref/400', $this->code( $this->check( $m, $r, $p, $h ) ), "$who: $label" );
			}
		}
	}

	public function test_a_negative_menu_object_id_naming_a_post_is_refused(): void {
		$admin = $this->bot( array( 'read', 'write', 'admin' ) );
		$this->assertSame( 'invalid_post_ref/400', $this->code( $this->check( 'POST', '/menus/5/items', array( 'type' => 'post_type', 'object_id' => -self::DRAFT, 'title' => 'x' ), $admin ) ) );
		$this->assertSame( 'invalid_post_ref/400', $this->code( $this->check( 'POST', '/menus', array( 'name' => 'm', 'items' => array( array( 'type' => 'page', 'object_id' => '-' . self::DRAFT ) ) ), $admin ) ) );
		$this->assertTrue( $this->check( 'POST', '/menus/5/items', array( 'type' => 'custom', 'object_id' => -1, 'title' => 'x', 'url' => 'https://example.test' ), $admin ), 'A custom item has no object: untouched.' );
	}

	// ── P0-2 ──────────────────────────────────────────────────────────────

	public function test_only_a_copied_source_is_template_exempt(): void {
		$h = new class() {
			use Mcpwp_Api_Auth {
				collect_post_refs as public;
				written_post_ref_target_ids as public;
			}
			public function act_as( $record ) {
				$this->current_api_key_record = $record;
			}
		};
		$h->act_as( array( 'type' => 'oauth', 'acts_as_user' => true, 'user_id' => self::PAGE_EDITOR, 'scopes' => array( 'read', 'write' ) ) );
		$GLOBALS['mcpwp_test_current_user'] = self::PAGE_EDITOR;
		$cases = array(
			array( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => self::ATT_FREE ), array() ),
			array( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'featured_image' => self::ATT_FREE ), array( self::OWN ) ),
			array( 'POST', '/posts/' . self::OWN . '/featured-image', array( 'id' => self::OWN, 'media_id' => self::ATT_FREE ), array( self::OWN ) ),
			array( 'POST', '/pages', array( 'title' => 'x', 'parent' => self::PAGE_SRC ), array() ),
			array( 'POST', '/elementor/clone', array( 'source_id' => self::PAGE_SRC, 'parent' => self::OWN_PAGE ), array( self::PAGE_SRC ) ),
			array( 'POST', '/elementor/landing-page', array( 'template_id' => self::LIBRARY, 'title' => 'x' ), array( self::LIBRARY ) ),
			array( 'POST', '/pages/' . self::PAGE_SRC . '/clone', array( 'id' => self::PAGE_SRC ), array( self::PAGE_SRC ) ),
		);
		foreach ( $cases as list( $method, $route, $params, $expected ) ) {
			$request = new WP_REST_Request( $method, '/mcpwp/v1' . $route, $params );
			$refs    = $h->collect_post_refs( $request );
			$this->assertSame( $expected, $h->written_post_ref_target_ids( $request, is_array( $refs ) ? $refs : array( 'scalar' => array(), 'items' => array(), 'read' => array(), 'source' => array() ) ), "$method $route" );
		}
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	// ── P0-3 ──────────────────────────────────────────────────────────────

	public function test_elementor_clone_charges_the_create_cap_of_the_sources_type(): void {
		$e = $this->token( self::PAGE_EDITOR );
		$this->assertSame( 'rest_forbidden_capability/403', $this->code( $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::PRODUCT ), $e ) ), 'A product clone needs the product create cap.' );
		$GLOBALS['mcpwp_test_post_type_caps']['elementor_library'] = array( 'create_posts' => 'create_templates_custom' );
		$this->assertSame( 'rest_forbidden_capability/403', $this->code( $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::LIBRARY ), $e ) ), 'A template clone needs the template type\'s own create cap.' );
		unset( $GLOBALS['mcpwp_test_post_type_caps']['elementor_library'] );
		$this->assertTrue( $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::PAGE_SRC ), $e ), 'A page clone needs page create: held.' );
		$this->assertTrue( $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::PUB ), $e ), 'A post clone needs post create: held (edit_posts).' );
		$this->assertSame( 'rest_forbidden_capability/403', $this->code( $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::PAGE_SRC ), $this->token() ) ), 'An Author cannot create pages.' );
		$this->assertSame( 'not_found/404', $this->code( $this->check( 'POST', '/elementor/clone', array( 'source_id' => 9699 ), $e ) ), 'A missing source: the canonical 404, no type oracle.' );
		$GLOBALS['_mcpwp_test_posts'][9612] = new WP_Post( array( 'ID' => 9612, 'post_type' => 'product', 'post_status' => 'draft', 'post_author' => self::OTHER, 'post_title' => 'hidden product' ) );
		$GLOBALS['mcpwp_test_post_caps'][ self::PAGE_EDITOR ]['read_post'][9612] = false;
		$this->assertSame( 'not_found/404', $this->code( $this->check( 'POST', '/elementor/clone', array( 'source_id' => 9612 ), $e ) ), 'A HIDDEN source of a type the caller cannot create: still the 404, never a 403 that would confirm it exists.' );
	}

	/**
	 * The type rule itself, without the chokepoint in front of it: the
	 * admin-AJAX and destructive-replay paths call the type check directly,
	 * so a hidden source must contribute no type verdict there either.
	 */
	public function test_a_hidden_source_contributes_no_type_verdict(): void {
		$h = new class() {
			use Mcpwp_Api_Auth {
				generic_post_type_targets as public;
			}
			public function act_as( $record ) {
				$this->current_api_key_record = $record;
			}
		};
		$h->act_as( array( 'type' => 'oauth', 'acts_as_user' => true, 'user_id' => self::PAGE_EDITOR, 'scopes' => array( 'read', 'write' ) ) );
		$GLOBALS['mcpwp_test_current_user'] = self::PAGE_EDITOR;
		$targets = static function ( int $source ) use ( $h ) {
			return $h->generic_post_type_targets( new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/clone', array( 'source_id' => $source ) ) )['targets'];
		};
		$this->assertContains( array( 'product', 'create_posts' ), $targets( self::PRODUCT ), 'A readable source: its type is charged.' );
		$this->assertNotContains( array( 'post', 'create_posts' ), $targets( self::DRAFT ), 'A hidden source: nothing.' );
		$this->assertSame( array(), $targets( 9699 ), 'A missing source: nothing.' );
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	public function test_pages_clone_and_landing_page_charge_the_page_type_they_create(): void {
		$this->assertSame( 'rest_forbidden_capability/403', $this->code( $this->check( 'POST', '/pages/' . self::PAGE_SRC . '/clone', array( 'id' => self::PAGE_SRC ), $this->token() ) ) );
		$this->assertTrue( $this->check( 'POST', '/pages/' . self::PAGE_SRC . '/clone', array( 'id' => self::PAGE_SRC ), $this->token( self::PAGE_EDITOR ) ) );
		$this->assertTrue( $this->check( 'POST', '/elementor/landing-page', array( 'template_id' => self::LIBRARY, 'title' => 'x' ), $this->token( self::PAGE_EDITOR ) ), 'Creates a page from a readable template: page create, held.' );
	}

	public function test_clone_parent_is_checked_for_bots_too(): void {
		// Bot-visible change (#1118): '@read' refs get the hidden check for
		// every principal, so a missing parent is the canonical 404.
		$this->assertSame( 'not_found/404', $this->code( $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::PAGE_SRC, 'parent' => 9699 ), $this->bot() ) ) );
	}

	// ── scope cut P0: revisions are hidden refs for user-run principals ──

	public function test_a_revision_is_a_hidden_read_or_source_ref_for_user_run_principals(): void {
		$a = $this->token();
		$e = $this->token( self::PAGE_EDITOR );
		$cases = array(
			'editor clone source_id = revision (the live repro)' => array( 'POST', '/elementor/clone', array( 'source_id' => self::REVISION ), $e ),
			'editor /pages/{revision}/clone'                      => array( 'POST', '/pages/' . self::REVISION . '/clone', array( 'id' => self::REVISION ), $e ),
			'editor landing template_id = revision'               => array( 'POST', '/elementor/landing-page', array( 'template_id' => self::REVISION, 'title' => 'x' ), $e ),
			'editor POST /pages parent = revision'                => array( 'POST', '/pages', array( 'title' => 'x', 'parent' => self::REVISION ), $e ),
			'author featured_image = revision'                    => array( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => self::REVISION ), $a ),
			'author media_id = revision'                          => array( 'POST', '/posts/' . self::OWN . '/featured-image', array( 'id' => self::OWN, 'media_id' => self::REVISION ), $a ),
			'author apply-link target = revision'                 => array( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => self::REVISION ), $a ),
			'author apply-link target = inherit custom type'      => array( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => self::INHERIT ), $a ),
			'author bulk posts[].featured_image = revision'       => array( 'POST', '/posts/bulk', array( 'posts' => array( array( 'title' => 'x', 'featured_image' => self::REVISION ) ) ), $a ),
		);
		foreach ( $cases as $label => list( $m, $r, $p, $h ) ) {
			$this->assertSame( 'not_found/404', $this->code( $this->check( $m, $r, $p, $h ) ), $label );
		}
		$missing = $this->check( 'POST', '/elementor/clone', array( 'source_id' => 9699 ), $e );
		$hidden  = $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::REVISION ), $e );
		$this->assertSame( array( $missing->get_error_code(), $missing->get_error_message(), $missing->get_error_data() ), array( $hidden->get_error_code(), $hidden->get_error_message(), $hidden->get_error_data() ), 'The canonical 404: a revision reads exactly like a missing id.' );
	}

	public function test_a_revision_is_hidden_whatever_status_it_was_stored_with(): void {
		// Core stores revisions as 'inherit', but the TYPE is the rule: a
		// revision some importer left 'publish' is still a revision.
		$GLOBALS['_mcpwp_test_posts'][9622] = new WP_Post( array( 'ID' => 9622, 'post_type' => 'revision', 'post_status' => 'publish', 'post_author' => self::OTHER, 'post_parent' => self::DRAFT, 'post_title' => 'odd revision' ) );
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['read_post'][9622] = true;
		$this->assertSame( 'not_found/404', $this->code( $this->check( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => 9622 ), $this->token() ) ) );
	}

	public function test_attachments_keep_read_post_and_bots_are_unchanged_for_revisions(): void {
		$a = $this->token();
		$this->assertTrue( $this->check( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => self::ATT_FREE ), $a ), 'A readable attachment (inherit) still passes.' );
		$this->assertSame( 'not_found/404', $this->code( $this->check( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => self::ATT_DRAFT ), $a ) ), 'An unreadable one still does not.' );
		$this->assertTrue( $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::REVISION ), $this->bot( array( 'read', 'write', 'admin' ) ) ), 'A bot key: unchanged by this rule (an unrestricted key still clones it).' );
	}

	// ── the two PR-3c survivors (M8 / M9) ─────────────────────────────────

	public function test_pages_featured_image_is_a_read_ref_on_create_and_bulk(): void {
		$e = $this->token( self::PAGE_EDITOR );
		$this->assertSame( 'not_found/404', $this->code( $this->check( 'POST', '/pages', array( 'title' => 'x', 'featured_image' => self::ATT_DRAFT ), $e ) ), '/pages featured_image' );
		$this->assertSame( 'not_found/404', $this->code( $this->check( 'PUT', '/pages/bulk', array( 'updates' => array( array( 'id' => self::OWN_PAGE, 'featured_image' => self::ATT_DRAFT ) ) ), $e ) ), '/pages/bulk updates[].featured_image' );
		$this->assertSame( 'not_found/404', $this->code( $this->check( 'POST', '/pages/bulk', array( 'pages' => array( array( 'title' => 'x', 'featured_image' => self::ATT_DRAFT ) ) ), $e ) ), '/pages/bulk pages[].featured_image' );
		$this->assertTrue( $this->check( 'POST', '/pages', array( 'title' => 'x', 'featured_image' => self::ATT_FREE ), $e ) );
	}
}
