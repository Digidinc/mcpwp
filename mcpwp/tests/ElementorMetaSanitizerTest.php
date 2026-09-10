<?php
/**
 * Regression coverage for #829: Kit CSS reported storage_repaired:true on
 * every write, and Elementor data lost its CSS child combinators.
 *
 * Root cause, found on WordPress 7.0.2 + Elementor 4.2.4 over real HTTP:
 * Elementor 4.2.x registers `_elementor_page_settings` and `_elementor_data`
 * (modules/wp-rest/classes/elementor-post-meta.php, on rest_api_init) with a
 * sanitize_callback that runs wp_kses_post() over every string whenever the
 * current user lacks `unfiltered_html`. update_metadata() calls sanitize_meta()
 * on every write, so inside a REST request every MCPWP write — the API key maps
 * onto the mcpwp_api_agent role, which lacks that capability — stored
 * `.a>.b` as `.a&gt;.b`:
 *
 *   - Kit CSS: set_kit_css_raw() then found the row different, rewrote it with
 *     $wpdb, and answered storage_repaired:true — on every write.
 *   - Elementor data: nothing compared the bytes, so a section's custom_css
 *     `selector .a > .b{}` was stored as `selector .a &gt; .b{}` and a heading
 *     title "A > B" as "A &gt; B", with a success response.
 *
 * Outside REST (wp-cli, cron) the sanitizer is not registered, which is why a
 * direct update_post_meta() round-tripped cleanly and the #762 investigation
 * did not find it.
 *
 * What this pins: an MCPWP write of these keys stores the caller's bytes; the
 * capability granted to get past that sanitizer does not outlive the write;
 * and when something else still alters the bytes, the repair says which
 * characters were altered.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorMetaSanitizerTest extends TestCase {

	private const KIT_ID = 4;

	private const PAGE_ID = 8290;

	private const API_USER = 829;

	/** @var array */
	private $globals_before = array();

	protected function setUp(): void {
		foreach ( array( '_mcpwp_test_meta', '_mcpwp_test_posts', '_mcpwp_test_registered_meta', 'mcpwp_test_current_user', 'mcpwp_test_user_caps', 'mcpwp_test_filters' ) as $key ) {
			$this->globals_before[ $key ] = $GLOBALS[ $key ] ?? null;
		}

		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::KIT_ID  => new WP_Post(
				array(
					'ID'          => self::KIT_ID,
					'post_type'   => 'elementor_library',
					'post_title'  => 'Default Kit',
					'post_status' => 'publish',
				)
			),
			self::PAGE_ID => new WP_Post(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'Sanitizer probe',
					'post_name'   => 'sanitizer-probe',
					'post_status' => 'draft',
				)
			),
		);
		$GLOBALS['mcpwp_test_kit_id'] = self::KIT_ID;

		// The API-key user: can edit, cannot post unfiltered HTML.
		$GLOBALS['mcpwp_test_current_user']                     = self::API_USER;
		$GLOBALS['mcpwp_test_user_caps'][ self::API_USER ]      = array( 'edit_posts' => true, 'edit_pages' => true );

		// Elementor 4.2.x's registrations, reduced to what they do to a value.
		foreach ( array( '_elementor_page_settings', '_elementor_data', '_unrelated_meta' ) as $key ) {
			$GLOBALS['_mcpwp_test_registered_meta'][ $key ] = array(
				'object_subtype'    => '',
				'sanitize_callback' => array( self::class, 'elementor_kses_deep' ),
			);
		}
	}

	protected function tearDown(): void {
		foreach ( $this->globals_before as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}
		$GLOBALS['mcpwp_test_meta_escapes_html'] = false;
	}

	/**
	 * Elementor_Post_Meta::sanitize_page_settings() / sanitize_elementor_data():
	 * untouched for a user with unfiltered_html, wp_kses_post() on every string
	 * otherwise. The escaping below is what that did to `>`, `<` and `&` on the
	 * rig.
	 *
	 * @param mixed $value Meta value.
	 * @return mixed
	 */
	public static function elementor_kses_deep( $value ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $value;
		}

		return map_deep(
			$value,
			static function ( $v ) {
				return is_string( $v ) ? str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $v ) : $v;
			}
		);
	}

	private function install_kit(): void {
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
		require_once __DIR__ . '/fixtures/elementor-kit-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {

			public function get_var( $query = '', $x = 0, $y = 0 ) {
				if ( false !== strpos( (string) $query, '_elementor_page_settings' ) ) {
					$settings = $GLOBALS['_mcpwp_test_meta'][4]['_elementor_page_settings'] ?? null;
					return null === $settings ? null : maybe_serialize( $settings );
				}
				return null;
			}

			public function update( $table, $data, $where, $f = null, $wf = null ) {
				$GLOBALS['_mcpwp_test_meta'][ (int) $where['post_id'] ][ $where['meta_key'] ] = maybe_unserialize( $data['meta_value'] );
				return 1;
			}
		};
	}

	private function upsert( string $name, string $css ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/kit-css/blocks' );
		$request->set_param( 'name', $name );
		$request->set_param( 'css', $css );

		$response = ( new Mcpwp_REST_Elementor() )->upsert_kit_css_block( $request );

		return $response instanceof WP_REST_Response ? $response->get_data() : $response;
	}

	private function assert_grant_is_gone(): void {
		$this->assertFalse( current_user_can( 'unfiltered_html' ), 'The capability used to get past the sanitizer must not outlive the write.' );
		$this->assertSame( 0, mcpwp_test_filter_count( 'user_has_cap' ), 'No grant filter is left installed.' );
		$this->assertSame( 0, mcpwp_test_filter_count( 'map_meta_cap' ) );

		update_post_meta( self::PAGE_ID, '_unrelated_meta', '.a>.b' );
		$this->assertSame( '.a&gt;.b', get_post_meta( self::PAGE_ID, '_unrelated_meta', true ), 'Writes made outside MCPWP\'s own write still meet the sanitizer.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_kit_css_written_by_the_api_user_is_stored_verbatim_and_needs_no_repair(): void {
		$this->install_kit();

		$css     = '.a>.b{color:red} .c + .d ~ .e{content:"&"}';
		$written = $this->upsert( 'hub', $css );

		$this->assertNotInstanceOf( WP_Error::class, $written );
		$this->assertTrue( $written['verified'] );
		$this->assertArrayNotHasKey( 'storage_repaired', $written, 'A write that stored the caller\'s bytes the first time must not claim a repair.' );

		$stored = $GLOBALS['_mcpwp_test_meta'][ self::KIT_ID ]['_elementor_page_settings']['custom_css'];
		$this->assertStringContainsString( $css, $stored, 'The first write itself stored the bytes; nothing was rewritten afterwards.' );

		$again = $this->upsert( 'hub', $css );
		$this->assertSame( 'skipped', $again['action'], 'An identical re-send is a no-op, not a rewrite.' );

		$this->assert_grant_is_gone();
	}

	/**
	 * When bytes are still altered — a host filter, another plugin — the repair
	 * stays, and it names what was altered.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_repair_that_did_happen_names_the_altered_characters(): void {
		$this->install_kit();
		$GLOBALS['mcpwp_test_meta_escapes_html'] = true;

		$written = $this->upsert( 'hub', '.a>.b{color:red}' );

		$this->assertTrue( $written['verified'] );
		$this->assertTrue( $written['storage_repaired'] );
		$this->assertSame( array( '>' ), $written['storage_altered_chars'] );
	}

	public function test_elementor_data_written_by_the_api_user_keeps_css_combinators_and_markup_characters(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode(
			array(
				array(
					'id'       => 'sec0001',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						array(
							'id'       => 'col0001',
							'elType'   => 'column',
							'settings' => array( '_column_size' => 100 ),
							'elements' => array(
								array(
									'id'         => 'hd00001',
									'elType'     => 'widget',
									'widgetType' => 'heading',
									'settings'   => array( 'title' => 'Before' ),
									'elements'   => array(),
								),
							),
						),
					),
				),
			)
		);

		$writer = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
		$result = $writer->patch_elementor(
			self::PAGE_ID,
			array(
				'operations' => array(
					array(
						'op'         => 'settings',
						'element_id' => 'sec0001',
						'settings'   => array( 'custom_css' => 'selector .a > .b{color:red}' ),
					),
					array(
						'op'         => 'settings',
						'element_id' => 'hd00001',
						'settings'   => array( 'title' => 'A > B & C' ),
					),
				),
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $result );

		$tree = json_decode( (string) $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'], true );
		$this->assertIsArray( $tree );
		$this->assertSame( 'selector .a > .b{color:red}', $tree[0]['settings']['custom_css'], 'A child combinator stored as &gt; kills the rule group.' );
		$this->assertSame( 'A > B & C', $tree[0]['elements'][0]['elements'][0]['settings']['title'] );

		$this->assert_grant_is_gone();
	}
}
