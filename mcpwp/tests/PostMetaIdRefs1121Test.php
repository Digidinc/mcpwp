<?php
/**
 * #1121 — POST /post-meta/{id}: the value of a known id-meta names posts, so a
 * user-run principal must be able to read every one of them (read_post).
 * Cut from #1118 after two bypasses; both are pinned here:
 *  1. a case-variant key ('_THUMBNAIL_ID' is the '_thumbnail_id' row: meta_key
 *     collates case-insensitively) — the key is lowercased before the lookup;
 *  2. a JSON-object value ('{"0":8}') — the guard reads the value through the
 *     handler's own decoder, Mcpwp_REST_API::decode_post_meta_value().
 * Also: a nav-menu item's object type is compared the way the handler reads it.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/class-mcpwp-oauth-well-known.php';

final class PostMetaIdRefs1121Test extends TestCase {

	const AUTHOR = 11;
	const OTHER  = 12;

	const OWN       = 9601;
	const PUB       = 9602;
	const DRAFT     = 9603;
	const ATT_FREE  = 9605;
	const ATT_DRAFT = 9606;

	const AUTHOR_CAPS = array( 'read' => true, 'edit_posts' => true, 'edit_published_posts' => true, 'publish_posts' => true, 'delete_posts' => true, 'upload_files' => true );

	/** @var array */
	private $saved_users;

	protected function setUp(): void {
		$this->saved_users = $GLOBALS['mcpwp_test_users'];
		foreach ( array( self::AUTHOR, self::OTHER ) as $uid ) {
			$GLOBALS['mcpwp_test_users'][] = (object) array( 'ID' => $uid, 'roles' => array(), 'user_login' => 'u' . $uid );
		}
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_user_caps']    = array( self::AUTHOR => self::AUTHOR_CAPS );
		$P = static function ( $id, $type, $status, $author, $parent = 0 ) {
			return new WP_Post( array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_author' => $author, 'post_parent' => $parent, 'post_title' => 't' . $id ) );
		};
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::OWN       => $P( self::OWN, 'post', 'publish', self::AUTHOR ),
			self::PUB       => $P( self::PUB, 'post', 'publish', self::OTHER ),
			self::DRAFT     => $P( self::DRAFT, 'post', 'draft', self::OTHER ),
			self::ATT_FREE  => $P( self::ATT_FREE, 'attachment', 'inherit', self::OTHER ),
			self::ATT_DRAFT => $P( self::ATT_DRAFT, 'attachment', 'inherit', self::OTHER, self::DRAFT ),
		);
		$GLOBALS['mcpwp_test_post_caps'] = array(
			self::AUTHOR => array(
				'read_post' => array( self::OWN => true, self::PUB => true, self::ATT_FREE => true, self::DRAFT => false, self::ATT_DRAFT => false ),
				'edit_post' => array( self::OWN => true ),
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

	private function token(): array {
		$t = ( new Mcpwp_Api_Auth_Test_Harness() )->issue_oauth_access_token( array( 'read', 'write' ), 3600, array( 'user_id' => self::AUTHOR, 'client_id' => 'test_client' ) )['access_token'];
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

	private function meta( array $params, ?array $headers = null, string $method = 'POST' ): string {
		return $this->code( $this->check( $method, '/post-meta/' . self::OWN, array( 'id' => self::OWN ) + $params, $headers ?? $this->token() ) );
	}

	// ── the acceptance matrix ─────────────────────────────────────────────

	public function test_id_meta_values_on_post_meta_are_read_refs(): void {
		$cases = array(
			'thumbnail of a draft'          => array( array( 'key' => '_thumbnail_id', 'value' => self::ATT_DRAFT ), 'not_found/404' ),
			'thumbnail as a string'         => array( array( 'key' => '_thumbnail_id', 'value' => (string) self::ATT_DRAFT ), 'not_found/404' ),
			'thumbnail public'              => array( array( 'key' => '_thumbnail_id', 'value' => self::ATT_FREE ), 'ALLOWED' ),
			'thumbnail cleared'             => array( array( 'key' => '_thumbnail_id', 'value' => '' ), 'ALLOWED' ),
			'thumbnail null'                => array( array( 'key' => '_thumbnail_id', 'value' => null ), 'ALLOWED' ),
			'gallery csv with a draft file' => array( array( 'key' => '_product_image_gallery', 'value' => self::ATT_FREE . ', ' . self::ATT_DRAFT ), 'not_found/404' ),
			'gallery csv public'            => array( array( 'key' => '_product_image_gallery', 'value' => self::ATT_FREE . ',' . self::ATT_FREE ), 'ALLOWED' ),
			'upsells array'                 => array( array( 'key' => '_upsell_ids', 'value' => array( self::PUB, self::DRAFT ) ), 'not_found/404' ),
			'upsells public'                => array( array( 'key' => '_upsell_ids', 'value' => array( self::PUB ) ), 'ALLOWED' ),
			'upsells JSON array string'     => array( array( 'key' => '_upsell_ids', 'value' => '[' . self::DRAFT . ']' ), 'not_found/404' ),
			'crosssells'                    => array( array( 'key' => '_crosssell_ids', 'value' => array( self::DRAFT ) ), 'not_found/404' ),
			'grouped children'              => array( array( 'key' => '_children', 'value' => array( self::DRAFT ) ), 'not_found/404' ),
			'padded key'                    => array( array( 'key' => ' _thumbnail_id ', 'value' => self::ATT_DRAFT ), 'not_found/404' ),
			'an ordinary key'               => array( array( 'key' => 'subtitle', 'value' => (string) self::DRAFT ), 'ALLOWED' ),
		);
		foreach ( $cases as $label => list( $params, $expected ) ) {
			$this->assertSame( $expected, $this->meta( $params ), $label );
		}
	}

	/** Bypass 1: the case-variant key lands on the same wp_postmeta row. */
	public function test_case_variant_keys_are_judged_like_the_real_key(): void {
		$cases = array(
			'_THUMBNAIL_ID'          => array( '_THUMBNAIL_ID', self::ATT_DRAFT ),
			'_Thumbnail_Id'          => array( '_Thumbnail_Id', self::ATT_DRAFT ),
			'_PRODUCT_IMAGE_GALLERY' => array( '_PRODUCT_IMAGE_GALLERY', self::ATT_FREE . ',' . self::ATT_DRAFT ),
			'_UPSELL_IDS'            => array( '_UPSELL_IDS', array( self::DRAFT ) ),
			'_Crosssell_Ids'         => array( '_Crosssell_Ids', array( self::DRAFT ) ),
			'_CHILDREN'              => array( '_CHILDREN', array( self::DRAFT ) ),
		);
		foreach ( $cases as $label => list( $key, $value ) ) {
			$this->assertSame( 'not_found/404', $this->meta( array( 'key' => $key, 'value' => $value ) ), $label );
		}
		$this->assertSame( 'ALLOWED', $this->meta( array( 'key' => '_THUMBNAIL_ID', 'value' => self::ATT_FREE ) ), 'A readable id through a case variant still passes.' );
		$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => '_THUMBNAIL_ID', 'value' => -self::ATT_DRAFT ) ) );
	}

	/** Bypass 2: a JSON-object value is decoded by the handler; the guard decodes it the same way. */
	public function test_json_object_values_are_decoded_like_the_handler_does(): void {
		$cases = array(
			'object string'              => '{"0":' . self::DRAFT . '}',
			'object string, padded'      => '  {"a":' . self::DRAFT . ',"b":' . self::PUB . '}',
			'array string, padded'       => "\n[" . self::DRAFT . ']',
			'object with a string id'    => '{"0":"' . self::DRAFT . '"}',
		);
		foreach ( array( '_upsell_ids', '_crosssell_ids', '_children', '_UPSELL_IDS' ) as $key ) {
			foreach ( $cases as $label => $value ) {
				$this->assertSame( 'not_found/404', $this->meta( array( 'key' => $key, 'value' => $value ) ), "$key: $label" );
			}
		}
		$this->assertSame( 'ALLOWED', $this->meta( array( 'key' => '_upsell_ids', 'value' => '{"0":' . self::PUB . '}' ) ), 'A readable id in an object string passes.' );
		$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => '_upsell_ids', 'value' => '{"0":-' . self::DRAFT . '}' ) ) );
		$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => '_upsell_ids', 'value' => '{"0":{"1":' . self::DRAFT . '}}' ) ), 'A nested value is ambiguous.' );
		$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => '_thumbnail_id', 'value' => '[' . self::ATT_DRAFT . ']' ) ), 'A list under a scalar key is ambiguous.' );
	}

	/** The guard and the handler share ONE decoder, so they cannot disagree. */
	public function test_the_handler_and_the_guard_use_the_same_decoder(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php' );
		$this->assertStringContainsString( 'self::decode_post_meta_value( $value )', $source, 'The handler decodes through the shared method.' );
		$this->assertStringNotContainsString( 'json_decode', $source, 'No second, hand-rolled decoder in the handler.' );

		$this->assertSame( array( 0 => 8 ), Mcpwp_REST_API::decode_post_meta_value( '{"0":8}' ) );
		$this->assertSame( array( 8 ), Mcpwp_REST_API::decode_post_meta_value( ' [8]' ) );
		$this->assertSame( '{oops', Mcpwp_REST_API::decode_post_meta_value( '{oops' ) );
		$this->assertSame( '8,9', Mcpwp_REST_API::decode_post_meta_value( '8,9' ) );
		$this->assertSame( 'null', Mcpwp_REST_API::decode_post_meta_value( 'null' ) );
		$this->assertSame( array( self::DRAFT ), Mcpwp_REST_API::post_ref_meta_ids( '_UPSELL_IDS', '{"0":' . self::DRAFT . '}' ) );
	}

	public function test_negative_signed_and_ambiguous_values_are_refused(): void {
		$cases = array(
			'thumbnail -N'            => array( '_thumbnail_id', -self::ATT_DRAFT ),
			'thumbnail "-N"'          => array( '_thumbnail_id', '-' . self::ATT_DRAFT ),
			'thumbnail "+Nx"'         => array( '_thumbnail_id', '+' . self::ATT_DRAFT . 'x' ),
			'thumbnail true'          => array( '_thumbnail_id', true ),
			'gallery with -1'         => array( '_product_image_gallery', self::ATT_FREE . ',-1' ),
			'gallery with 12abc'      => array( '_product_image_gallery', self::ATT_FREE . ',12abc' ),
			'upsells with -N'         => array( '_upsell_ids', array( self::PUB, -self::DRAFT ) ),
			'upsells with a nested []' => array( '_upsell_ids', array( array( self::DRAFT ) ) ),
		);
		foreach ( $cases as $label => list( $key, $value ) ) {
			$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => $key, 'value' => $value ) ), $label );
		}
	}

	public function test_a_list_longer_than_the_post_ref_cap_is_refused_before_any_lookup(): void {
		$ids = range( 1, 501 );
		$this->assertSame( 'too_many_ids/400', $this->meta( array( 'key' => '_upsell_ids', 'value' => $ids ) ) );
		$this->assertSame( 'too_many_ids/400', $this->meta( array( 'key' => '_product_image_gallery', 'value' => implode( ',', $ids ) ) ) );
	}

	public function test_bots_and_reads_are_unchanged(): void {
		$bot = $this->bot();
		foreach ( array(
			array( '_thumbnail_id', self::ATT_DRAFT ),
			array( '_THUMBNAIL_ID', 9699 ),
			array( '_upsell_ids', '{"0":' . self::DRAFT . '}' ),
			array( '_thumbnail_id', -self::ATT_DRAFT ),
		) as list( $key, $value ) ) {
			$this->assertSame( 'ALLOWED', $this->meta( array( 'key' => $key, 'value' => $value ), $bot ), 'A bot key is scope-only, as before: ' . $key );
		}
		$this->assertSame( 'ALLOWED', $this->meta( array( 'key' => '_thumbnail_id', 'value' => self::ATT_DRAFT ), null, 'GET' ), 'A read is not a write: nothing is judged.' );
		$this->assertSame( array( 'id' ), Mcpwp_REST_API::post_ref_specs_for_path( '/post-meta/1' ), 'The declared map still names only the path id.' );
	}

	// ── nav menu object type, compared the way the handler reads it ──────

	public function test_a_case_variant_menu_item_type_still_names_a_post(): void {
		foreach ( array( 'Page', 'PAGE', ' page ', 'Post_Type', 'POST', "post_type\n" ) as $type ) {
			$admin = $this->bot( array( 'read', 'write', 'admin' ) ); // One key per type: the per-key rate limit is not under test.
			$label = json_encode( $type );
			$this->assertSame( 'invalid_post_ref/400', $this->code( $this->check( 'POST', '/menus/5/items', array( 'type' => $type, 'object_id' => -self::DRAFT, 'title' => 'x' ), $admin ) ), "item $label, negative id" );
			$this->assertSame( 'invalid_post_ref/400', $this->code( $this->check( 'POST', '/menus', array( 'name' => 'm', 'items' => array( array( 'type' => $type, 'object_id' => '-' . self::DRAFT ) ) ), $admin ) ), "menu item $label, negative id" );
			// A variant is judged exactly like the canonical 'post_type' (here: a post that
			// is not there), whatever that verdict is for this principal.
			$this->assertSame( $this->code( $this->check( 'POST', '/menus/5/items', array( 'type' => 'post_type', 'object_id' => 9699, 'title' => 'x' ), $admin ) ), $this->code( $this->check( 'POST', '/menus/5/items', array( 'type' => $type, 'object_id' => 9699, 'title' => 'x' ), $admin ) ), "item $label, missing post" );
			$this->assertSame( $this->code( $this->check( 'POST', '/menus', array( 'name' => 'm', 'items' => array( array( 'type' => 'post_type', 'object_id' => 9699 ) ) ), $admin ) ), $this->code( $this->check( 'POST', '/menus', array( 'name' => 'm', 'items' => array( array( 'type' => $type, 'object_id' => 9699 ) ) ), $admin ) ), "menu item $label, missing post" );
		}
		$admin = $this->bot( array( 'read', 'write', 'admin' ) );
		foreach ( array( 'Custom', 'TAXONOMY', 'category' ) as $type ) {
			$this->assertTrue( $this->check( 'POST', '/menus/5/items', array( 'type' => $type, 'object_id' => -1, 'title' => 'x', 'url' => 'https://example.test' ), $admin ), "$type is not a post type: untouched." );
		}
	}

	// ── two-way pin: a new id-meta key has to be classified ───────────────

	/**
	 * Every meta key literal in includes/ that looks like an id-meta, and what
	 * was decided about it. 'ref' keys are in get_post_ref_meta_value_keys();
	 * 'not' keys were reviewed and name no post a /post-meta write could reach.
	 */
	const CLASSIFIED = array(
		'_thumbnail_id'                => 'ref',
		'_product_image_gallery'       => 'ref',
		'_upsell_ids'                  => 'ref',
		'_crosssell_ids'               => 'ref',
		'_children'                    => 'ref',
		'_mcpwp_design_reference_id'   => 'not: reserved _mcpwp prefix, refused by /post-meta; a design-reference id, not a post',
		'_mcpwp_blueprint_id'          => 'not: reserved _mcpwp prefix; a blueprint id',
		'_mcpwp_part_source_page_id'   => 'not: reserved _mcpwp prefix; written by the plugin only',
		'_mcpwp_part_source_element_id' => 'not: reserved _mcpwp prefix; an Elementor element id',
		'_bulk_approval_ids'           => 'not: an approval id list the plugin writes; approvals are not posts',
		'_element_id'                  => 'not: an Elementor element id, inside _elementor_data',
		'_product_id'                  => 'not: WooCommerce ORDER-ITEM meta (woocommerce_order_itemmeta), not post meta',
	);

	/** @return array<string,true> Literals in includes/ that follow the id-meta naming convention. */
	private function id_meta_literals(): array {
		$found = array();
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( dirname( __DIR__ ) . '/includes', FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			if ( preg_match_all( "/['\"](_[a-z0-9_]*(?:_ids?|_image_gallery)|_children)['\"]/", (string) file_get_contents( $file->getPathname() ), $m ) ) {
				foreach ( $m[1] as $literal ) {
					$found[ $literal ] = true;
				}
			}
		}
		return $found;
	}

	public function test_the_ref_classification_is_exactly_the_declared_key_list(): void {
		$declared = array_keys( Mcpwp_REST_API::get_post_ref_meta_value_keys() );
		$ref      = array_keys( array_filter( self::CLASSIFIED, static function ( $v ) {
			return 'ref' === $v;
		} ) );
		sort( $declared );
		sort( $ref );
		$this->assertSame( $ref, $declared, 'CLASSIFIED (ref) and get_post_ref_meta_value_keys() must list the same keys.' );
		foreach ( $declared as $key ) {
			$this->assertSame( strtolower( $key ), $key, 'Declared keys are lowercase: post_ref_meta_ids() lowercases the request key.' );
		}
	}

	public function test_every_id_meta_key_in_the_code_is_classified(): void {
		$unclassified = array_diff( array_keys( $this->id_meta_literals() ), array_keys( self::CLASSIFIED ) );
		$this->assertSame(
			array(),
			array_values( $unclassified ),
			'A new id-meta key appeared in includes/. If its value names posts, add it to Mcpwp_REST_API::get_post_ref_meta_value_keys() and to CLASSIFIED as "ref"; if not, add it to CLASSIFIED as "not: <why>".'
		);
	}

	public function test_every_classified_key_still_exists_in_the_code(): void {
		$stale = array_diff( array_keys( self::CLASSIFIED ), array_keys( $this->id_meta_literals() ) );
		$this->assertSame( array(), array_values( $stale ), 'A classified key is no longer in includes/: drop it from CLASSIFIED (and from the declared list if it was a ref).' );
	}
}
