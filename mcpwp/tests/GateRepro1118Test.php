<?php
/**
 * #1118 round-1 gate repros (copied from the gate's scripts/GateRepro1118Test.php).
 * The original printed a verdict matrix and asserted nothing; here each test
 * records every verdict and asserts the expected one — so each FAILS on
 * 436702b0 and passes with the round-2 fix.
 *
 * @package MCPWP
 */
use PHPUnit\Framework\TestCase;
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';
require_once __DIR__ . '/ApiAuthTest.php';

final class GateRepro1118Test extends TestCase {
	const AUTHOR = 11; const OTHER = 12; const PAGE_EDITOR = 13;
	const OWN = 9601; const PUB = 9602; const DRAFT = 9603; const ATT_DRAFT = 9606; const PAGE_SRC = 9607; const HID_PAGE = 9608; const PRODUCT = 9609; const OWN_PAGE = 9610;
	const AUTHOR_CAPS = array( 'read' => true, 'edit_posts' => true, 'edit_published_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'delete_published_posts' => true, 'upload_files' => true );
	private $saved_users;
	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		foreach ( array( self::AUTHOR, self::OTHER, self::PAGE_EDITOR ) as $uid ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $uid, 'roles' => array(), 'user_login' => 'u' . $uid );
		}
		$GLOBALS['mcpwp_test_options'] = array(); $GLOBALS['mcpwp_test_transients'] = array(); $GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps'] = array(
			self::AUTHOR => self::AUTHOR_CAPS,
			self::PAGE_EDITOR => array_merge( self::AUTHOR_CAPS, array( 'edit_pages' => true, 'edit_published_pages' => true, 'publish_pages' => true ) ),
		);
		$P = function ( $id, $type, $status, $author, $parent = 0 ) { return new WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_author' => $author, 'post_parent' => $parent, 'post_title' => 't' . $id ) ); };
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::OWN => $P( self::OWN, 'post', 'publish', self::AUTHOR ),
			self::OWN_PAGE => $P( self::OWN_PAGE, 'page', 'publish', self::PAGE_EDITOR ),
			self::PUB => $P( self::PUB, 'post', 'publish', self::OTHER ),
			self::DRAFT => $P( self::DRAFT, 'post', 'draft', self::OTHER ),
			self::ATT_DRAFT => $P( self::ATT_DRAFT, 'attachment', 'inherit', self::OTHER, self::DRAFT ),
			self::PAGE_SRC => $P( self::PAGE_SRC, 'page', 'publish', self::OTHER ),
			self::HID_PAGE => $P( self::HID_PAGE, 'page', 'private', self::OTHER ),
			self::PRODUCT => $P( self::PRODUCT, 'product', 'publish', self::OTHER ),
		);
		$deny = array( self::DRAFT => false, self::ATT_DRAFT => false, self::HID_PAGE => false );
		$GLOBALS['mcpwp_test_post_caps'] = array(
			self::AUTHOR => array( 'read_post' => $deny + array( self::OWN => true, self::PUB => true, self::PAGE_SRC => true, self::PRODUCT => true ), 'edit_post' => array( self::OWN => true ) ),
			self::PAGE_EDITOR => array( 'read_post' => $deny + array( self::OWN_PAGE => true, self::PUB => true, self::PAGE_SRC => true, self::PRODUCT => true ), 'edit_post' => array( self::OWN_PAGE => true ) ),
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
		$GLOBALS['mcpwp_test_users'] = $this->saved_users; $GLOBALS['mcpwp_test_user_caps'] = array(); $GLOBALS['mcpwp_test_post_caps'] = array(); $GLOBALS['_mcpwp_test_posts'] = array(); $GLOBALS['mcpwp_test_current_user'] = 0;
	}
	private function token( int $user = self::AUTHOR ): array {
		$t = ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => $user, 'client_id' => 'test_client' ) )['access_token'];
		return array( 'Authorization' => 'Bearer ' . $t );
	}
	private function check( string $method, string $route, array $params, array $headers ) {
		$saved = $GLOBALS['mcpwp_test_current_user'];
		$r = ( new Mcpwp_Api_Auth_Test_Harness() )->verify_api_key( new WP_REST_Request( $method, '/mcpwp/v1' . $route, $params, $headers ) );
		$GLOBALS['mcpwp_test_current_user'] = $saved;
		return $r;
	}
	/** @var array<string,string> */
	private $seen = array();
	private function verdict( $r ): string { return true === $r ? 'ALLOWED' : ( is_wp_error( $r ) ? $r->get_error_code() . '/' . ( $r->get_error_data()['status'] ?? '?' ) : var_export( $r, true ) ); }
	private function assert_verdicts( array $expected ): void {
		$this->assertSame( $expected, array_intersect_key( $this->seen, $expected ) );
		$this->assertSame( array(), array_diff_key( $this->seen, $expected ), 'Every verdict is pinned.' );
	}
	private function show( $label, $r ) { $this->seen[ $label ] = $this->verdict( $r ); if ( getenv( 'GATE_REPRO_VERBOSE' ) ) fwrite( STDERR, sprintf( "%-60s => %s\n", $label, true === $r ? 'ALLOWED' : ( is_wp_error( $r ) ? $r->get_error_code() . '/' . ( $r->get_error_data()['status'] ?? '?' ) : var_export( $r, true ) ) ) ); }

	public function test_matrix(): void {
		$a = $this->token(); $e = $this->token( self::PAGE_EDITOR );
		// controls (positive = draft attachment refused)
		$this->show( 'CTRL media_id=ATT_DRAFT', $this->check( 'POST', '/posts/' . self::OWN . '/featured-image', array( 'id' => self::OWN, 'media_id' => self::ATT_DRAFT ), $a ) );
		$this->show( 'NEG media_id=-ATT_DRAFT (int)', $this->check( 'POST', '/posts/' . self::OWN . '/featured-image', array( 'id' => self::OWN, 'media_id' => -self::ATT_DRAFT ), $a ) );
		$this->show( 'NEG PUT featured_image="-9606"', $this->check( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'featured_image' => '-' . self::ATT_DRAFT ), $a ) );
		$this->show( 'NEG POST /posts featured_image=-9606', $this->check( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => -self::ATT_DRAFT ), $a ) );
		$this->show( 'NEG bulk posts[].featured_image=-9606', $this->check( 'POST', '/posts/bulk', array( 'posts' => array( array( 'title' => 'x', 'featured_image' => -self::ATT_DRAFT ) ) ), $a ) );
		$this->show( 'NEG bulk updates[].featured_image=-9606', $this->check( 'PUT', '/posts/bulk', array( 'updates' => array( array( 'id' => self::OWN, 'featured_image' => -self::ATT_DRAFT ) ) ), $a ) );
		$this->show( 'CTRL POST /pages parent=HID', $this->check( 'POST', '/pages', array( 'title' => 'x', 'parent' => self::HID_PAGE ), $e ) );
		$this->show( 'NEG POST /pages parent=-HID', $this->check( 'POST', '/pages', array( 'title' => 'x', 'parent' => -self::HID_PAGE ), $e ) );
		$this->show( 'NEG PUT /pages/bulk updates[].parent=-HID', $this->check( 'PUT', '/pages/bulk', array( 'updates' => array( array( 'id' => self::OWN_PAGE, 'parent' => -self::HID_PAGE ) ) ), $e ) );
		$this->show( 'CTRL /elementor/clone parent=HID', $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::PAGE_SRC, 'parent' => self::HID_PAGE ), $e ) );
		$this->show( 'NEG /elementor/clone parent=-HID', $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::PAGE_SRC, 'parent' => -self::HID_PAGE ), $e ) );
		$this->show( 'NEG apply-link target=-DRAFT', $this->check( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => -self::DRAFT ), $a ) );
		$this->show( 'NEG apply-link source=-PUB (write)', $this->check( 'POST', '/content-graph/apply-link', array( 'source_id' => -self::PUB, 'target_id' => self::OWN ), $a ) );
		$this->show( 'CTRL apply-link source=PUB (write)', $this->check( 'POST', '/content-graph/apply-link', array( 'source_id' => self::PUB, 'target_id' => self::OWN ), $a ) );
		// clone of other-type source
		$this->show( 'CLONE elementor product source (edit_pages user)', $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::PRODUCT ), $e ) );
		$this->show( 'CLONE elementor others post source', $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::PUB ), $e ) );
		$this->show( 'CLONE pages/{PAGE_SRC}/clone', $this->check( 'POST', '/pages/' . self::PAGE_SRC . '/clone', array( 'id' => self::PAGE_SRC ), $e ) );
		$this->show( 'SAVE-SECTION page_id=PAGE_SRC (author)', $this->check( 'POST', '/elementor/save-section-as-template', array( 'page_id' => self::PAGE_SRC, 'section_id' => 'abc', 'title' => 't' ), $a ) );
		$this->show( 'LANDING template_id=PUB (editor)', $this->check( 'POST', '/elementor/landing-page', array( 'template_id' => self::PUB, 'title' => 't' ), $e ) );
		// value-shape regressions (real clients)
		foreach ( array( '0012' => '00' . self::OWN, 'sp' => ' ' . self::OWN . ' ', 'float' => 9601.0, 'flstr' => '9601.0', 'e' => '9.601e3', 'tab' => "9601\n", 'false' => false, 'null' => null, 'empty' => '', 'zero' => 0, 'hex' => '0x2581', 'csvish' => '9601 9602' ) as $k => $v ) {
			$this->show( "SHAPE apply-link target_id[$k]", $this->check( 'POST', '/content-graph/apply-link', array( 'source_id' => self::OWN, 'target_id' => $v ), $a ) );
		}
		$this->assert_verdicts( array(
			'CTRL media_id=ATT_DRAFT' => 'not_found/404',
			'NEG media_id=-ATT_DRAFT (int)' => 'invalid_post_ref/400',
			'NEG PUT featured_image="-9606"' => 'invalid_post_ref/400',
			'NEG POST /posts featured_image=-9606' => 'invalid_post_ref/400',
			'NEG bulk posts[].featured_image=-9606' => 'invalid_item/400',
			'NEG bulk updates[].featured_image=-9606' => 'invalid_item/400',
			'CTRL POST /pages parent=HID' => 'not_found/404',
			'NEG POST /pages parent=-HID' => 'invalid_post_ref/400',
			'NEG PUT /pages/bulk updates[].parent=-HID' => 'invalid_item/400',
			'CTRL /elementor/clone parent=HID' => 'not_found/404',
			'NEG /elementor/clone parent=-HID' => 'invalid_post_ref/400',
			'NEG apply-link target=-DRAFT' => 'invalid_post_ref/400',
			'NEG apply-link source=-PUB (write)' => 'invalid_post_ref/400',
			'CTRL apply-link source=PUB (write)' => 'rest_cannot_edit/403',
			'CLONE elementor product source (edit_pages user)' => 'rest_forbidden_capability/403',
			'CLONE elementor others post source' => 'ALLOWED',
			'CLONE pages/{PAGE_SRC}/clone' => 'ALLOWED',
			'SAVE-SECTION page_id=PAGE_SRC (author)' => 'ALLOWED',
			'LANDING template_id=PUB (editor)' => 'ALLOWED',
			'SHAPE apply-link target_id[0012]' => 'ALLOWED',
			'SHAPE apply-link target_id[sp]' => 'ALLOWED',
			'SHAPE apply-link target_id[float]' => 'ALLOWED',
			'SHAPE apply-link target_id[flstr]' => 'ALLOWED',
			'SHAPE apply-link target_id[e]' => 'ALLOWED',
			'SHAPE apply-link target_id[tab]' => 'ALLOWED',
			'SHAPE apply-link target_id[false]' => 'ALLOWED',
			'SHAPE apply-link target_id[null]' => 'ALLOWED',
			'SHAPE apply-link target_id[empty]' => 'ALLOWED',
			'SHAPE apply-link target_id[zero]' => 'ALLOWED',
			'SHAPE apply-link target_id[hex]' => 'invalid_post_ref/400',
			'SHAPE apply-link target_id[csvish]' => 'invalid_post_ref/400',
		) );
	}

	public function test_neg_sources(): void {
		$e = $this->token( self::PAGE_EDITOR );
		$created = ( new Mcpwp_Api_Auth_Test_Harness() )->create_scoped_api_key( 'bot', array( 'read', 'write' ), 'custom', array( 'content', 'seo', 'elementor', 'elementor-build', 'media' ) );
		$b = array( 'X-API-Key' => $created['key'] );
		$rk = ( new Mcpwp_Api_Auth_Test_Harness() )->create_scoped_api_key( 'ro', array( 'read' ), 'custom', array( 'content', 'elementor', 'elementor-build' ) );
		$r = array( 'X-API-Key' => $rk['key'] );
		foreach ( array( 'editor' => $e, 'bot-rw' => $b ) as $who => $h ) {
			$this->show( "$who CTRL clone source=DRAFT", $this->check( 'POST', '/elementor/clone', array( 'source_id' => self::DRAFT ), $h ) );
			$this->show( "$who NEG clone source=-DRAFT", $this->check( 'POST', '/elementor/clone', array( 'source_id' => -self::DRAFT ), $h ) );
			$this->show( "$who NEG clone source=\"-9603\"", $this->check( 'POST', '/elementor/clone', array( 'source_id' => '-' . self::DRAFT ), $h ) );
			$this->show( "$who CTRL landing template=DRAFT", $this->check( 'POST', '/elementor/landing-page', array( 'template_id' => self::DRAFT, 'title' => 't' ), $h ) );
			$this->show( "$who NEG landing template=-DRAFT", $this->check( 'POST', '/elementor/landing-page', array( 'template_id' => -self::DRAFT, 'title' => 't' ), $h ) );
		}
		$this->assert_verdicts( array(
			'editor CTRL clone source=DRAFT' => 'not_found/404',
			'editor NEG clone source=-DRAFT' => 'invalid_post_ref/400',
			'editor NEG clone source="-9603"' => 'invalid_post_ref/400',
			'editor CTRL landing template=DRAFT' => 'not_found/404',
			'editor NEG landing template=-DRAFT' => 'invalid_post_ref/400',
			'bot-rw CTRL clone source=DRAFT' => 'ALLOWED',
			'bot-rw NEG clone source=-DRAFT' => 'invalid_post_ref/400',
			'bot-rw NEG clone source="-9603"' => 'invalid_post_ref/400',
			'bot-rw CTRL landing template=DRAFT' => 'ALLOWED',
			'bot-rw NEG landing template=-DRAFT' => 'invalid_post_ref/400',
		) );
	}

	public function test_template_launder(): void {
		if ( ! class_exists( 'Mcpwp_Elementor_Template_Refs' ) ) { require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-elementor-template-refs.php'; }
		$T = 9700; $P = 9701;
		$GLOBALS['_mcpwp_test_posts'][ $T ] = new WP_Post( array( 'ID' => $T, 'post_type' => 'elementor_library', 'post_status' => 'private', 'post_author' => self::OTHER, 'post_title' => 'secret tpl', 'post_content' => '' ) );
		$GLOBALS['_mcpwp_test_posts'][ $P ] = new WP_Post( array( 'ID' => $P, 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => self::OTHER, 'post_title' => 'pub page', 'post_content' => '[elementor-template id="' . $T . '"]' ) );
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['read_post'][ $T ] = false;
		$GLOBALS['mcpwp_test_post_caps'][ self::AUTHOR ]['read_post'][ $P ] = true;
		$GLOBALS['mcpwp_test_post_caps'][ self::PAGE_EDITOR ]['read_post'][ $T ] = false;
		$GLOBALS['mcpwp_test_post_caps'][ self::PAGE_EDITOR ]['read_post'][ $P ] = true;
		$a = $this->token(); $e = $this->token( self::PAGE_EDITOR );
		$sc = '[elementor-template id="' . $T . '"]';
		$this->show( 'TPL CTRL POST /posts content=sc', $this->check( 'POST', '/posts', array( 'title' => 'x', 'content' => $sc ), $a ) );
		$this->show( 'TPL LAUNDER POST /posts content=sc featured_image=P', $this->check( 'POST', '/posts', array( 'title' => 'x', 'content' => $sc, 'featured_image' => $P ), $a ) );
		$this->show( 'TPL CTRL PUT /posts/own content=sc', $this->check( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'content' => $sc ), $a ) );
		$this->show( 'TPL LAUNDER PUT /posts/own featured_image=P', $this->check( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'content' => $sc, 'featured_image' => $P ), $a ) );
		$this->show( 'TPL CTRL POST /pages content=sc (editor)', $this->check( 'POST', '/pages', array( 'title' => 'x', 'content' => $sc ), $e ) );
		$this->show( 'TPL LAUNDER POST /pages parent=P (editor)', $this->check( 'POST', '/pages', array( 'title' => 'x', 'content' => $sc, 'parent' => $P ), $e ) );
		$this->show( 'TPL LAUNDER PUT /posts/bulk updates[].featured_image=P', $this->check( 'PUT', '/posts/bulk', array( 'updates' => array( array( 'id' => self::OWN, 'content' => $sc, 'featured_image' => $P ) ) ), $a ) );
		$this->show( "TPL CTRL POST /posts meta=sc", $this->check( "POST", "/posts", array( "title" => "x", "meta" => array( "hero" => $sc ) ), $a ) );
		$this->show( "TPL LAUNDER POST /posts meta=sc featured_image=P", $this->check( "POST", "/posts", array( "title" => "x", "meta" => array( "hero" => $sc ), "featured_image" => $P ), $a ) );
		$this->show( "TPL LAUNDER POST /posts excerpt=sc featured_image=P", $this->check( "POST", "/posts", array( "title" => "x", "excerpt" => $sc, "featured_image" => $P ), $a ) );
		$this->assert_verdicts( array(
			'TPL CTRL POST /posts content=sc' => 'not_found/404',
			'TPL LAUNDER POST /posts content=sc featured_image=P' => 'not_found/404',
			'TPL CTRL PUT /posts/own content=sc' => 'not_found/404',
			'TPL LAUNDER PUT /posts/own featured_image=P' => 'not_found/404',
			'TPL CTRL POST /pages content=sc (editor)' => 'not_found/404',
			'TPL LAUNDER POST /pages parent=P (editor)' => 'not_found/404',
			'TPL LAUNDER PUT /posts/bulk updates[].featured_image=P' => 'not_found/404',
			'TPL CTRL POST /posts meta=sc' => 'not_found/404',
			'TPL LAUNDER POST /posts meta=sc featured_image=P' => 'not_found/404',
			'TPL LAUNDER POST /posts excerpt=sc featured_image=P' => 'not_found/404',
		) );
	}
}
