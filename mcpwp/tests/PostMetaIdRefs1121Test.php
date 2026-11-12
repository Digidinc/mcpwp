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

	/** @return array<string,array{0:mixed,1:bool}> */
	public static function round_trip_values(): array {
		return array(
			'2^63 as float'              => array( 9.2233720368547758E18, true ),
			'PHP_INT_MAX as float'       => array( (float) PHP_INT_MAX, true ),
			'1e19'                       => array( 1e19, true ),
			'wraps to -2048'             => array( 18446744073709549568.0, true ),
			'wraps to 4096'              => array( 18446744073709547520.0, true ),
			'-1e19'                      => array( -1e19, true ),
			'INF'                        => array( INF, true ),
			'-INF'                       => array( -INF, true ),
			'NAN'                        => array( NAN, true ),
			'string 1e19'                => array( '1e19', true ),
			'string 18446744073709549568' => array( '18446744073709549568', true ),
			'string 1e400'               => array( '1e400', true ),
			'PHP_INT_MAX int'            => array( PHP_INT_MAX, false ),
			'float 8.0 (a whole number)' => array( 8.0, false ),
			'float 4095.99999999999'     => array( 4095.99999999999, true ),
			'string 5.0 (whole)'         => array( '5.0', false ),
			'string .5'                  => array( '.5', true ),
			'string 007'                 => array( '007', false ),
			'string +7'                  => array( '+7', false ),
			'string " 7 "'              => array( ' 7 ', false ),
			'2^53 int'                   => array( 9007199254740992, false ),
			'2^53 float'                 => array( 9007199254740992.0, true ),
			'string 4.096e3 whole is 4096' => array( '4.096e3', false ),
			'digits overflowing an int'  => array( '9223372036854775808', true ),
			'-0.0 (is not an id)'        => array( -0.0, false ),
			'int 8'                      => array( 8, false ),
			'string 8'                   => array( '8', false ),
		);
	}

	/**
	 * An id has to round-trip as a positive integer.
	 *
	 * @dataProvider round_trip_values
	 * @param mixed $value
	 */
	public function test_ids_that_do_not_round_trip_as_a_positive_integer_are_refused( $value, bool $refused ): void {
		$this->assertSame( $refused, Mcpwp_REST_API::is_ambiguous_post_ref_value( $value ) );
	}

	public function test_out_of_range_floats_are_refused_on_id_meta_values(): void {
		$big = 18446744073709549568.0;
		$cases = array(
			'_upsell_ids list'       => array( '_upsell_ids', array( $big ) ),
			'_children list'         => array( '_children', array( self::PUB, $big ) ),
			'_crosssell_ids list'    => array( '_crosssell_ids', array( $big ) ),
			'_thumbnail_id scalar'   => array( '_thumbnail_id', 18446744073709547520.0 ),
			'_THUMBNAIL_ID scalar'   => array( '_THUMBNAIL_ID', $big ),
			'gallery csv'            => array( '_product_image_gallery', self::ATT_FREE . ',1e19' ),
			'JSON array string'      => array( '_upsell_ids', '[18446744073709549568]' ),
			'JSON object string'     => array( '_upsell_ids', '{"0":18446744073709549568}' ),
			'JSON float, 1e19'       => array( '_upsell_ids', '[1.0e19]' ),
		);
		foreach ( $cases as $label => list( $key, $value ) ) {
			$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => $key, 'value' => $value ) ), $label );
		}
	}

	public function test_out_of_range_floats_are_refused_on_featured_image_references(): void {
		$a   = $this->token();
		$big = 18446744073709545472.0;
		$this->assertSame( 'invalid_post_ref/400', $this->code( $this->check( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'featured_image' => $big ), $a ) ), 'PUT /posts/{id}' );
		$this->assertSame( 'invalid_post_ref/400', $this->code( $this->check( 'POST', '/posts', array( 'title' => 'x', 'featured_image' => $big ), $a ) ), 'POST /posts' );
		$this->assertContains( $this->code( $this->check( 'POST', '/posts/bulk', array( 'posts' => array( array( 'title' => 'x', 'featured_image' => $big ) ) ), $a ) ), array( 'invalid_post_ref/400', 'invalid_item/400' ), 'POST /posts/bulk: refused before the handler.' );
		$this->assertSame( 'invalid_post_ref/400', $this->code( $this->check( 'POST', '/posts/' . self::OWN . '/featured-image', array( 'id' => self::OWN, 'media_id' => $big ), $a ) ), 'featured-image media_id' );
		$this->assertSame( 'invalid_post_ref/400', $this->code( $this->check( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'featured_image' => self::ATT_DRAFT - 0.00000001 ), $a ) ), 'A float that is not a whole number.' );
		$this->assertTrue( $this->check( 'PUT', '/posts/' . self::OWN, array( 'id' => self::OWN, 'featured_image' => self::ATT_FREE ), $a ), 'A readable id still passes.' );
	}

	public function test_values_that_are_not_positive_integers_are_refused_on_every_id_meta(): void {
		$bad = array(
			'float just under a whole number' => 4095.99999999999,
			'whole-number float'              => 5.0,
			'float 2^53'                      => 9007199254740992.0,
			'-0.0'                            => -0.0,
			'string 4.096e3'                  => '4.096e3',
			'string 1e3'                      => '1e3',
			'string 5.0'                      => '5.0',
			'string hex'                      => '0x10',
			'true'                            => true,
			'false'                           => false,
		);
		foreach ( array( '_thumbnail_id', '_THUMBNAIL_ID' ) as $key ) {
			foreach ( $bad as $label => $value ) {
				$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => $key, 'value' => $value ) ), "$key: $label" );
			}
		}
		foreach ( array( '_upsell_ids', '_crosssell_ids', '_children' ) as $key ) {
			foreach ( $bad as $label => $value ) {
				$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => $key, 'value' => array( self::PUB, $value ) ) ), "$key: list with $label" );
			}
			$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => $key, 'value' => array( self::PUB, null ) ) ), "$key: null in a list" );
			$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => $key, 'value' => array( self::PUB, 0 ) ) ), "$key: zero in a list" );
			$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => $key, 'value' => '[4095.99999999999]' ) ), "$key: JSON float" );
		}
		$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => '_product_image_gallery', 'value' => self::ATT_FREE . ',4.096e3' ) ) );
		$this->assertSame( 'invalid_post_ref/400', $this->meta( array( 'key' => '_product_image_gallery', 'value' => array( self::ATT_FREE, 5.5 ) ) ) );
	}

	public function test_accepted_values_are_stored_in_canonical_form(): void {
		$cases = array(
			array( '_thumbnail_id', 7, array( 7 ), 7 ),
			array( '_thumbnail_id', '7', array( 7 ), 7 ),
			array( '_thumbnail_id', ' 7 ', array( 7 ), 7 ),
			array( '_thumbnail_id', '007', array( 7 ), 7 ),
			array( '_thumbnail_id', '+7', array( 7 ), 7 ),
			array( '_thumbnail_id', '', array(), '' ),
			array( '_thumbnail_id', 0, array(), 0 ),
			array( '_thumbnail_id', '0', array(), '0' ),
			array( '_thumbnail_id', null, array(), null ),
			array( '_product_image_gallery', '5,6', array( 5, 6 ), '5,6' ),
			array( '_product_image_gallery', ' 5 , 006 ', array( 5, 6 ), '5,6' ),
			array( '_product_image_gallery', array( 5, '6' ), array( 5, 6 ), '5,6' ),
			array( '_product_image_gallery', '', array(), '' ),
			array( '_upsell_ids', '[5,"6"]', array( 5, 6 ), array( 5, 6 ) ),
			array( '_upsell_ids', '{"a":5,"b":"06"}', array( 5, 6 ), array( 5, 6 ) ),
			array( '_upsell_ids', '5,6', array( 5, 6 ), array( 5, 6 ) ),
			array( '_upsell_ids', array( 'x' => 5 ), array( 5 ), array( 5 ) ),
			array( '_children', '[]', array(), array() ),
			array( '_UPSELL_IDS', array( 9007199254740992 ), array( 9007199254740992 ), array( 9007199254740992 ) ),
		);
		foreach ( $cases as $i => list( $key, $in, $ids, $stored ) ) {
			$c = Mcpwp_REST_API::canonicalize_post_meta_value( $key, $in );
			$this->assertIsArray( $c, "case $i" );
			$this->assertSame( $ids, $c['ids'], "case $i ids" );
			$this->assertSame( $stored, $c['value'], "case $i stored value" );
		}
		$this->assertNull( Mcpwp_REST_API::canonicalize_post_meta_value( 'subtitle', 4095.99999999999 ), 'Not an id-meta: not touched.' );
		$this->assertSame( 'ALLOWED', $this->meta( array( 'key' => '_thumbnail_id', 'value' => ' ' . self::ATT_FREE . ' ' ) ) );
		$this->assertSame( 'ALLOWED', $this->meta( array( 'key' => '_upsell_ids', 'value' => '' ) ) );
	}

	public function test_the_handler_stores_what_the_guard_checked(): void {
		$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php' );
		$this->assertStringContainsString( 'self::canonicalize_post_meta_value( $key, $value )', $source );
		$this->assertStringContainsString( "\$value = \$canonical['value'];", $source, 'The handler stores the canonical value, not the raw input.' );
		$guard = (string) file_get_contents( dirname( __DIR__ ) . '/includes/traits/trait-mcpwp-api-auth.php' );
		$this->assertStringContainsString( 'self::post_ref_meta_ids(', $guard );
	}

	/**
	 * Property: for a generated corpus, an accepted value's STORED form, read
	 * the way consumers read it (absint / (int) of the stored string, a csv
	 * split, wp_parse_id_list-style), is exactly the id list the guard checks.
	 */
	public function test_property_consumer_reading_of_the_stored_value_equals_the_checked_ids(): void {
		$pool = array( 0, 1, 5, 7, 4095, 4096, 4097, 9007199254740991, 9007199254740992, PHP_INT_MAX, -1, -4096,
			0.0, -0.0, 5.0, 4095.99999999999, 4095.5, 4096.00000000001, 1e19, 1.8446744073709552E+19, 2 ** 53 + 0.0, 1.0E-5, INF, NAN,
			'', ' ', '0', '00', '5', ' 7 ', '007', '+7', '-7', '5.0', '4.096e3', '1e3', '0x10', '12abc', 'abc', '9223372036854775807', '9223372036854775808', '99999999999999999999',
			true, false, null, array( 5 ), array(), array( array( 5 ) ) );
		mt_srand( 1121 );
		$corpus = array();
		foreach ( $pool as $v ) {
			$corpus[] = $v;
			$corpus[] = array( $v );
			$corpus[] = array( 5, $v );
			$corpus[] = array( 'k' => $v );
			if ( is_scalar( $v ) ) {
				$corpus[] = '5,' . $v;
				$corpus[] = json_encode( array( $v ) );
				$corpus[] = json_encode( array( 'a' => $v ) );
			}
		}
		for ( $i = 0; $i < 400; $i++ ) {
			$corpus[] = array( $pool[ mt_rand( 0, count( $pool ) - 1 ) ], $pool[ mt_rand( 0, count( $pool ) - 1 ) ] );
		}
		$accepted = 0;
		foreach ( array_keys( Mcpwp_REST_API::get_post_ref_meta_value_keys() ) as $key ) {
			$shape = Mcpwp_REST_API::get_post_ref_meta_value_keys()[ $key ];
			foreach ( $corpus as $n => $value ) {
				$c = Mcpwp_REST_API::canonicalize_post_meta_value( strtoupper( $key ), $value );
				if ( ! is_array( $c ) ) {
					$this->assertInstanceOf( WP_Error::class, $c, "$key #$n is refused, never passed through" );
					continue;
				}
				$accepted++;
				$stored = $c['value'];
				// Storage stringifies scalars and serialises arrays; read it back.
				if ( 'scalar' === $shape ) {
					$read = null === $stored ? array() : ( 0 === absint( (string) $stored ) ? array() : array( absint( (string) $stored ) ) );
				} elseif ( 'csv' === $shape ) {
					$read = ( null === $stored || '' === $stored ) ? array() : array_map( 'absint', explode( ',', (string) $stored ) );
				} else {
					$read = array_map( static function ( $x ) {
						return absint( (string) $x );
					}, (array) $stored );
					if ( null === $stored ) {
						$read = array();
					}
				}
				$this->assertSame( $c['ids'], array_values( $read ), "$key #$n: stored form reads as the checked ids" );
				foreach ( $c['ids'] as $id ) {
					$this->assertIsInt( $id );
					$this->assertGreaterThan( 0, $id );
				}
			}
		}
		$this->assertGreaterThan( 100, $accepted, 'The corpus exercises accepted values too.' );
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
	 * An inventory of THIS plugin's own meta key literals in includes/ that
	 * look like id-metas (underscore-style names ending _id/_ids, plus
	 * _product_image_gallery and _children). It is not coverage of the
	 * ecosystem: hyphenated keys and other plugins' image-id keys (for
	 * example _yoast_wpseo_opengraph-image-id, ACF image fields) are not
	 * scanned or classified here — that scope is an owner decision.
	 *
	 * Every such literal, and what was decided about it. 'ref' keys are in get_post_ref_meta_value_keys();
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
