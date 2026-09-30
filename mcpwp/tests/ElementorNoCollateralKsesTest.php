<?php
/**
 * #829: an Elementor write through MCPWP must never strip what the caller did
 * not change.
 *
 * Production, 2026-09-30: MCPWP 3.13.0–3.14.10 wrote `_elementor_data` with
 * update_post_meta() inside a REST request. Elementor 4.2+ registers that key
 * (and `_elementor_page_settings`) for REST with a sanitize_callback that runs
 * wp_kses_post() over every string when the user lacks `unfiltered_html`, and
 * update_metadata() runs it on every write. One wp_edit_widget on the footer
 * removed the `<style>` of an unrelated html widget; an edit to the header's
 * nav removed the PostHog `<script>` and the logo's `<svg>`.
 *
 * What is pinned here, through the controllers a REST request reaches, for an
 * API user without `unfiltered_html`:
 *  - three html widgets carrying `<script>`, `<style>` and `<svg>` stay
 *    byte-identical across wp_edit_widget, wp_edit_section, wp_patch_elementor,
 *    wp_set_elementor, find/replace on unrelated text, template apply, part
 *    insert, clone, save-section-as-template and a rollback;
 *  - content the caller introduces still gets exactly what Elementor's
 *    sanitizer gives it for that caller (and raw for a user who holds
 *    `unfiltered_html`) — including while MCPWP's own #772 grant is active;
 *  - outside an MCPWP request Elementor's behaviour is untouched.
 *
 * `elementor_sanitize_data()` below is Elementor 4.3.2's callback
 * (modules/wp-rest/classes/elementor-post-meta.php) with a kses stand-in that
 * does to these fixtures what wp_kses_post() does on a real site: removes the
 * script/style/svg/path tags, keeps their text, and escapes a bare `>`.
 *
 * Each test runs in its own process: the writer checks ELEMENTOR_VERSION,
 * and a constant cannot be undefined for the tests that run after (#757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class ElementorNoCollateralKsesTest extends TestCase {

	private const PAGE_ID     = 8291;
	private const TEMPLATE_ID = 8292;
	private const TARGET_ID   = 8293;
	private const API_USER    = 829;

	private const SCRIPT = '<script>window.__ph829=1;</script>';
	private const STYLE  = '<style>.ftr>.x{color:red}</style>';
	private const SVG    = '<svg viewBox="0 0 10 10"><path d="M0 0L10 10"/></svg>';

	/** @var array */
	private $saved = array();

	protected function setUp(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.3.2' );
		}

		foreach ( array( '_mcpwp_test_meta', '_mcpwp_test_posts', '_mcpwp_test_registered_meta', 'mcpwp_test_current_user', 'mcpwp_test_user_caps', 'wp', 'mcpwp_test_filters' ) as $global ) {
			$this->saved[ $global ] = $GLOBALS[ $global ] ?? null;
		}

		$GLOBALS['_mcpwp_test_meta']            = array();
		$GLOBALS['_mcpwp_test_registered_meta'] = array();
		$GLOBALS['_mcpwp_test_posts']           = array(
			self::PAGE_ID     => $this->post( self::PAGE_ID, 'page' ),
			self::TEMPLATE_ID => $this->post( self::TEMPLATE_ID, 'elementor_library' ),
			self::TARGET_ID   => $this->post( self::TARGET_ID, 'page' ),
		);

		// The mcpwp_api_agent user: no unfiltered_html.
		$this->as_user( false );

		// Every MCPWP call is a REST request under /mcpwp/.
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/mcpwp/v1/mcp' ) );

		// The plugin boots the chokepoint at load, before rest_api_init.
		if ( class_exists( 'Mcpwp_Elementor_Meta_Write' ) && false === has_filter( 'update_post_metadata', array( 'Mcpwp_Elementor_Meta_Write', 'on_update_post_metadata' ) ) ) {
			Mcpwp_Elementor_Meta_Write::boot();
		}

		// Then Elementor registers its meta on rest_api_init.
		register_meta( 'post', '_elementor_data', array( 'object_subtype' => 'page', 'sanitize_callback' => array( self::class, 'elementor_sanitize_data' ) ) );
		register_meta( 'post', '_elementor_page_settings', array( 'object_subtype' => 'page', 'sanitize_callback' => array( self::class, 'elementor_sanitize_settings' ) ) );

		$this->seed( self::PAGE_ID );
	}

	protected function tearDown(): void {
		foreach ( $this->saved as $global => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $global ] );
			} else {
				$GLOBALS[ $global ] = $value;
			}
		}
	}

	// ---------------------------------------------------------------------
	// Elementor 4.3.2's sanitize callbacks, and a kses stand-in.
	// ---------------------------------------------------------------------

	public static function elementor_sanitize_data( $value ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $value;
		}

		if ( ! is_string( $value ) ) {
			return self::kses_deep( $value );
		}

		$elementor_data = json_decode( $value, true );

		if ( JSON_ERROR_NONE !== json_last_error() || empty( $elementor_data ) ) {
			return self::kses( $value );
		}

		return wp_json_encode( self::kses_deep( $elementor_data ) );
	}

	public static function elementor_sanitize_settings( $value ) {
		if ( current_user_can( 'unfiltered_html' ) ) {
			return $value;
		}

		return self::kses_deep( $value );
	}

	private static function kses_deep( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'kses_deep' ), $value );
		}

		return is_string( $value ) ? self::kses( $value ) : $value;
	}

	/** What wp_kses_post() does to these fixtures on a real site. */
	public static function kses( string $text ): string {
		$parts = preg_split( '/(<[^>]*>)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';

		foreach ( $parts as $part ) {
			if ( '' !== $part && '<' === $part[0] ) {
				$out .= preg_match( '#^</?\s*(script|style|svg|path|iframe)\b#i', $part ) ? '' : $part;
			} else {
				$out .= str_replace( '>', '&gt;', $part );
			}
		}

		return $out;
	}

	// ---------------------------------------------------------------------
	// Fixtures.
	// ---------------------------------------------------------------------

	private function post( int $id, string $type ): WP_Post {
		return new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => $type,
				'post_title'  => "b829 {$type} {$id}",
				'post_name'   => "b829-{$id}",
				'post_status' => 'publish',
			)
		);
	}

	private function as_user( bool $unfiltered_html ): void {
		$GLOBALS['mcpwp_test_current_user']                   = self::API_USER;
		$GLOBALS['mcpwp_test_user_caps'][ self::API_USER ] = array(
			'edit_posts'      => true,
			'edit_pages'      => true,
			'publish_pages'   => true,
			'unfiltered_html' => $unfiltered_html,
		);
	}

	private static function widget( string $id, string $type, array $settings ): array {
		return array(
			'id'         => $id,
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	private static function tree(): array {
		return array(
			array(
				'id'       => 'sec8291',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col8291',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							self::widget( 'hd82901', 'heading', array( 'title' => 'Hello 829' ) ),
							self::widget( 'sc82901', 'html', array( 'html' => self::SCRIPT ) ),
							self::widget( 'st82901', 'html', array( 'html' => self::STYLE ) ),
							self::widget( 'sv82901', 'html', array( 'html' => self::SVG ) ),
						),
					),
				),
			),
		);
	}

	/** Stored raw, as an admin in the Elementor editor leaves it. */
	private function seed( int $post_id ): void {
		$GLOBALS['_mcpwp_test_meta'][ $post_id ]['_elementor_data']      = wp_json_encode( self::tree() );
		$GLOBALS['_mcpwp_test_meta'][ $post_id ]['_elementor_edit_mode'] = 'builder';
	}

	private function stored_tree( int $post_id ): array {
		$raw = $GLOBALS['_mcpwp_test_meta'][ $post_id ]['_elementor_data'] ?? null;
		$this->assertIsString( $raw, "post {$post_id} has _elementor_data" );
		$tree = json_decode( $raw, true );
		$this->assertIsArray( $tree, "post {$post_id}'s _elementor_data decodes" );

		return $tree;
	}

	private static function find( array $elements, string $id ): ?array {
		foreach ( $elements as $element ) {
			if ( ( $element['id'] ?? '' ) === $id ) {
				return $element;
			}
			$found = self::find( $element['elements'] ?? array(), $id );
			if ( null !== $found ) {
				return $found;
			}
		}

		return null;
	}

	/** The html widgets, found by their html (ids may be regenerated on a copy). */
	private function html_widgets( int $post_id ): array {
		$out  = array();
		$walk = static function ( array $elements ) use ( &$walk, &$out ) {
			foreach ( $elements as $element ) {
				if ( 'html' === ( $element['widgetType'] ?? '' ) ) {
					$out[] = $element['settings']['html'] ?? null;
				}
				$walk( $element['elements'] ?? array() );
			}
		};
		$walk( $this->stored_tree( $post_id ) );

		return $out;
	}

	private function assert_html_widgets_intact( int $post_id, string $after ): void {
		$this->assertSame(
			array( self::SCRIPT, self::STYLE, self::SVG ),
			$this->html_widgets( $post_id ),
			"After {$after}, every html widget the caller did not touch is stored byte-for-byte as it was."
		);
	}

	private function rest_request( string $method, string $route, array $params ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	private function assert_ok( $result, string $label ): void {
		$data = $result instanceof WP_REST_Response ? $result->get_data() : $result;
		$this->assertNotInstanceOf( WP_Error::class, $data, $label . ': ' . ( $data instanceof WP_Error ? $data->get_error_message() : '' ) );
	}

	// ---------------------------------------------------------------------
	// No collateral damage.
	// ---------------------------------------------------------------------

	public function test_edit_widget_on_a_heading_keeps_every_html_widget(): void {
		$controller = new Mcpwp_REST_Elementor();
		$result     = $controller->edit_widget(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID . '/edit-widget', array( 'id' => self::PAGE_ID, 'widget_id' => 'hd82901', 'settings' => array( 'title' => 'Edited 829' ) ) )
		);

		$this->assert_ok( $result, 'edit_widget' );
		$this->assertSame( 'Edited 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'wp_edit_widget on the heading' );
	}

	public function test_edit_section_keeps_every_html_widget(): void {
		$controller = new Mcpwp_REST_Elementor();
		$result     = $controller->edit_section(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID . '/edit-section', array( 'id' => self::PAGE_ID, 'section_index' => 0, 'settings' => array( 'background_color' => '#eeeeee' ) ) )
		);

		$this->assert_ok( $result, 'edit_section' );
		$this->assertSame( '#eeeeee', self::find( $this->stored_tree( self::PAGE_ID ), 'sec8291' )['settings']['background_color'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'wp_edit_section' );
	}

	public function test_patch_keeps_every_html_widget(): void {
		// The /patch route hands its params straight to this method.
		$result = ( new Mcpwp_Elementor_Basic() )->patch_elementor(
			self::PAGE_ID,
			array(
				'id'         => self::PAGE_ID,
				'operations' => array(
					array(
						'op'         => 'settings',
						'element_id' => 'hd82901',
						'settings'   => array( 'title' => 'Patched 829' ),
					),
				),
			)
		);

		$this->assert_ok( $result, 'patch' );
		$this->assertSame( 'Patched 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'wp_patch_elementor' );
	}

	public function test_set_elementor_resending_the_html_widgets_keeps_them(): void {
		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][0]['settings']['title'] = 'Set 829';

		$controller = new Mcpwp_REST_Elementor();
		$result     = $controller->set_elementor_data(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID, array( 'id' => self::PAGE_ID, 'elementor_data' => wp_json_encode( $tree ) ) )
		);

		$this->assert_ok( $result, 'set_elementor' );
		$this->assertSame( 'Set 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'wp_set_elementor with the html widgets resent unchanged' );
	}

	public function test_find_replace_on_unrelated_text_keeps_every_html_widget(): void {
		$controller = new Mcpwp_REST_Elementor();
		$result     = $controller->find_replace(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/find-replace', array( 'id' => self::PAGE_ID, 'search' => 'Hello 829', 'replace' => 'Howdy 829' ) )
		);

		$this->assert_ok( $result, 'find_replace' );
		$this->assertSame( 'Howdy 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'find/replace on the heading text' );
	}

	public function test_applying_a_template_stores_its_html_widgets_as_the_template_holds_them(): void {
		$this->seed( self::TEMPLATE_ID );

		$result = ( new Mcpwp_Elementor_Pro() )->apply_template_to_page( self::TARGET_ID, self::TEMPLATE_ID );

		$this->assert_ok( $result, 'apply_template_to_page' );
		$this->assert_html_widgets_intact( self::TARGET_ID, 'wp_apply_elementor_template' );
	}

	public function test_inserting_a_part_stores_its_html_widgets_as_the_part_holds_them(): void {
		$this->seed( self::TEMPLATE_ID );
		$GLOBALS['_mcpwp_test_meta'][ self::TEMPLATE_ID ]['_elementor_template_type'] = 'section';
		$GLOBALS['_mcpwp_test_meta'][ self::TEMPLATE_ID ]['_mcpwp_is_part']           = 'yes';
		$GLOBALS['_mcpwp_test_meta'][ self::TARGET_ID ]['_elementor_data']            = '[]';

		$pro = new class() extends Mcpwp_Elementor_Pro {
			public function get_part( $part_id ) {
				return array(
					'id'             => (int) $part_id,
					'elementor_data' => get_post_meta( (int) $part_id, '_elementor_data', true ),
				);
			}
		};

		$result = $pro->insert_part_into_page( self::TEMPLATE_ID, self::TARGET_ID, 'end' );

		$this->assert_ok( $result, 'insert_part_into_page' );
		$this->assert_html_widgets_intact( self::TARGET_ID, 'wp_apply_elementor_part (insert)' );
	}

	public function test_cloning_a_page_stores_its_html_widgets_as_the_source_holds_them(): void {
		$result = ( new Mcpwp_Elementor_Pro() )->clone_page( self::PAGE_ID, array( 'title' => 'b829 clone' ) );

		$this->assert_ok( $result, 'clone_page' );
		$this->assert_html_widgets_intact( (int) $result['id'], 'wp_clone_elementor_page' );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'wp_clone_elementor_page (source)' );
	}

	public function test_saving_a_section_as_a_template_stores_its_html_widgets_as_the_page_holds_them(): void {
		$result = ( new Mcpwp_Elementor_Pro() )->save_section_as_template( self::PAGE_ID, 'sec8291', 'b829 section' );

		$this->assert_ok( $result, 'save_section_as_template' );
		$this->assert_html_widgets_intact( (int) $result['template_id'], 'wp_save_section_as_template' );
	}

	public function test_a_rollback_restores_the_snapshot_as_it_was(): void {
		// The page as a bad write left it (production, pre-fix): script body
		// as text, style and svg gone. The snapshot holds what it was before.
		$stripped = self::tree();
		foreach ( array( 1, 2, 3 ) as $i ) {
			$html = $stripped[0]['elements'][0]['elements'][ $i ]['settings']['html'];
			$stripped[0]['elements'][0]['elements'][ $i ]['settings']['html'] = self::kses( $html );
		}
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( $stripped );

		$method = new ReflectionMethod( Mcpwp_Action_Log::class, 'apply_snapshot' );
		$method->setAccessible( true );
		$result = $method->invoke(
			null,
			'elementor',
			array(
				'post_id'        => self::PAGE_ID,
				'elementor_data' => wp_json_encode( self::tree() ),
			)
		);

		$this->assertTrue( $result );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'an action-log rollback' );
	}

	public function test_page_settings_the_caller_did_not_change_are_kept(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_page_settings'] = array(
			'custom_css' => 'selector .a > .b{color:red}',
			'hide_title' => 'yes',
		);

		$settings               = get_post_meta( self::PAGE_ID, '_elementor_page_settings', true );
		$settings['hide_title'] = 'no';
		update_post_meta( self::PAGE_ID, '_elementor_page_settings', $settings );

		$this->assertSame(
			array(
				'custom_css' => 'selector .a > .b{color:red}',
				'hide_title' => 'no',
			),
			get_post_meta( self::PAGE_ID, '_elementor_page_settings', true )
		);
	}

	public function test_a_key_registered_twice_still_keeps_every_html_widget(): void {
		// Found on the rig: Elementor registers its meta on every
		// rest_api_init, so the sanitize hook can carry two wrapped copies of
		// its callback. The second is handed the first's output; what the
		// caller sent must still be what the merge compares against.
		$first = $GLOBALS['_mcpwp_test_registered_meta']['_elementor_data']['sanitize_callback'];
		add_filter( 'sanitize_post_meta__elementor_data_for_page', $first, 10, 4 );
		register_meta( 'post', '_elementor_data', array( 'object_subtype' => 'page', 'sanitize_callback' => array( self::class, 'elementor_sanitize_data' ) ) );

		$controller = new Mcpwp_REST_Elementor();
		$controller->edit_widget(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID . '/edit-widget', array( 'id' => self::PAGE_ID, 'widget_id' => 'hd82901', 'settings' => array( 'title' => 'Twice 829' ) ) )
		);

		$this->assertSame( 'Twice 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'a write through a doubly-registered sanitizer' );
	}

	// ---------------------------------------------------------------------
	// Kit CSS (#761/#762): byte-for-byte, and a repair only when one happened.
	// ---------------------------------------------------------------------

	private const KIT_ID = 4;

	private function install_kit(): Mcpwp_REST_Elementor {
		require_once __DIR__ . '/fixtures/elementor-kit-doubles.php';
		\Elementor\Plugin::$instance       = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_kit_id']       = self::KIT_ID;
		$GLOBALS['_mcpwp_test_posts'][ self::KIT_ID ] = $this->post( self::KIT_ID, 'elementor_library' );

		// The Kit CSS path reads the row directly to verify it and repairs it
		// through $wpdb; both act on the store the meta doubles use.
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

		return new Mcpwp_REST_Elementor();
	}

	private function kit_css(): string {
		return (string) ( $GLOBALS['_mcpwp_test_meta'][ self::KIT_ID ]['_elementor_page_settings']['custom_css'] ?? '' );
	}

	public function test_a_kit_css_block_is_stored_as_sent_and_reports_no_repair(): void {
		$controller = $this->install_kit();

		$response = $controller->upsert_kit_css_block(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css/blocks', array( 'name' => 'b829', 'css' => '.a>.b{color:red}' ) )
		);
		$data     = $response instanceof WP_REST_Response ? $response->get_data() : $response;

		$this->assertNotInstanceOf( WP_Error::class, $data );
		$this->assertStringContainsString( '.a>.b{color:red}', $this->kit_css(), 'Stored as sent on the first write.' );
		$this->assertStringNotContainsString( '&gt;', $this->kit_css() );
		$this->assertArrayNotHasKey( 'storage_repaired', $data, 'A clean write is not reported as repaired.' );
	}

	public function test_set_kit_css_append_keeps_the_existing_stylesheet(): void {
		$controller = $this->install_kit();
		$GLOBALS['_mcpwp_test_meta'][ self::KIT_ID ]['_elementor_page_settings'] = array( 'custom_css' => '.old>.x{color:blue}' );

		$controller->set_kit_css(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css', array( 'css' => '.new>.y{color:red}', 'mode' => 'append' ) )
		);

		$this->assertSame( ".old>.x{color:blue}\n\n.new>.y{color:red}", $this->kit_css() );
	}

	public function test_a_real_repair_is_reported_with_the_characters_it_fixed(): void {
		$controller = $this->install_kit();
		// Something other than Elementor's sanitizer (a host filter, another
		// plugin) escapes the row: that is what the repair is still for.
		$GLOBALS['_mcpwp_test_registered_meta']  = array();
		$GLOBALS['mcpwp_test_meta_escapes_html'] = true;

		try {
			$response = $controller->upsert_kit_css_block(
				$this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css/blocks', array( 'name' => 'b829r', 'css' => '.a>.b{color:red}' ) )
			);
		} finally {
			$GLOBALS['mcpwp_test_meta_escapes_html'] = false;
		}
		$data = $response instanceof WP_REST_Response ? $response->get_data() : $response;

		$this->assertTrue( $data['storage_repaired'] );
		$this->assertSame( array( '>' ), $data['storage_altered_chars'] );
		$this->assertStringContainsString( '.a>.b{color:red}', $this->kit_css() );
	}

	// ---------------------------------------------------------------------
	// Content the caller introduces keeps the existing policy.
	// ---------------------------------------------------------------------

	public function test_a_script_the_caller_introduces_gets_what_elementor_gives_this_caller(): void {
		$controller = new Mcpwp_REST_Elementor();
		$result     = $controller->edit_widget(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID . '/edit-widget', array( 'id' => self::PAGE_ID, 'widget_id' => 'sc82901', 'settings' => array( 'html' => '<script>window.__new829=1;</script><p>kept</p>' ) ) )
		);

		$this->assert_ok( $result, 'edit_widget' );
		$tree = $this->stored_tree( self::PAGE_ID );
		$this->assertSame( self::kses( '<script>window.__new829=1;</script><p>kept</p>' ), self::find( $tree, 'sc82901' )['settings']['html'], 'Exactly what main stores for it: Elementor\'s sanitizer output.' );
		$this->assertStringNotContainsString( '<script', self::find( $tree, 'sc82901' )['settings']['html'] );
		$this->assertSame( self::STYLE, self::find( $tree, 'st82901' )['settings']['html'] );
		$this->assertSame( self::SVG, self::find( $tree, 'sv82901' )['settings']['html'] );
	}

	public function test_a_user_with_unfiltered_html_stores_introduced_content_raw(): void {
		$this->as_user( true );

		$controller = new Mcpwp_REST_Elementor();
		$controller->edit_widget(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID . '/edit-widget', array( 'id' => self::PAGE_ID, 'widget_id' => 'sc82901', 'settings' => array( 'html' => '<script>window.__admin829=1;</script>' ) ) )
		);

		$this->assertSame( '<script>window.__admin829=1;</script>', self::find( $this->stored_tree( self::PAGE_ID ), 'sc82901' )['settings']['html'] );
	}

	public function test_a_new_element_copying_a_stored_string_is_not_new_content(): void {
		// A new widget whose html is byte-identical to one already on the page
		// (e.g. a section the caller read and wrote back with fresh ids):
		// what the site already holds, not something this caller introduced.
		$tree   = self::tree();
		$tree[] = array(
			'id'       => 'sec8292',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'col8292',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array( self::widget( 'st82902', 'html', array( 'html' => self::STYLE ) ) ),
				),
			),
		);

		update_post_meta( self::PAGE_ID, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );

		$this->assertSame( self::STYLE, self::find( $this->stored_tree( self::PAGE_ID ), 'st82902' )['settings']['html'] );
	}

	public function test_the_772_grant_does_not_reach_the_meta_sanitizer(): void {
		// MCPWP calls Document::save() under a scoped unfiltered_html grant
		// (#772), and Elementor's save writes _elementor_data from inside it.
		// That write must be judged on the caller's own capability: otherwise
		// it would store the introduced script raw, and the overwrite that
		// follows would find it "already on the post".
		add_filter( 'content_save_pre', 'wp_filter_post_kses' );

		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][1]['settings']['html'] = '<script>window.__granted829=1;</script>';
		$json = wp_json_encode( $tree );

		$writer = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
		$call   = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'attempt_elementor_call' );
		$call->setAccessible( true );
		$debug  = array();
		$saw    = null;

		$call->invokeArgs(
			$writer,
			array(
				static function () use ( $json, &$saw ) {
					$saw = current_user_can( 'unfiltered_html' );
					update_post_meta( ElementorNoCollateralKsesTest::page_id(), '_elementor_data', wp_slash( $json ) );
					return true;
				},
				&$debug,
				'document_save',
			)
		);
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );

		$this->assertTrue( $saw, 'The #772 grant is live around the Elementor call.' );
		$stored = self::find( $this->stored_tree( self::PAGE_ID ), 'sc82901' )['settings']['html'];
		$this->assertStringNotContainsString( '<script', $stored, 'The grant did not reach the sanitizer.' );
		$this->assertSame( self::STYLE, self::find( $this->stored_tree( self::PAGE_ID ), 'st82901' )['settings']['html'] );
	}

	public static function page_id(): int {
		return self::PAGE_ID;
	}

	public function test_outside_an_mcpwp_request_elementor_behaviour_is_untouched(): void {
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/wp/v2/pages/' . self::PAGE_ID ) );

		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][0]['settings']['title'] = 'Core REST';
		update_post_meta( self::PAGE_ID, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );

		$this->assertSame( self::kses( self::STYLE ), self::find( $this->stored_tree( self::PAGE_ID ), 'st82901' )['settings']['html'] );
	}
}
