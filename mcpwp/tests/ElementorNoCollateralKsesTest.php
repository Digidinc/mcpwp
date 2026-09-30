<?php
/**
 * #829: an Elementor write by MCPWP stores what the caller did not change in
 * place, byte-for-byte, and sanitizes what the caller introduces by MCPWP's own
 * capability policy — whatever the post type or Elementor version.
 *
 * Production, 2026-09-30: MCPWP 3.13.0–3.14.10 wrote `_elementor_data` with
 * update_post_meta() inside a REST request. Elementor 4.2+ registers that key
 * (and `_elementor_page_settings`) with a sanitize_callback that runs
 * wp_kses_post() over every string when the user lacks `unfiltered_html`, on
 * every write. One wp_edit_widget on the footer removed the `<style>` of an
 * unrelated html widget; an edit to the header's nav removed the PostHog
 * `<script>` and the logo's `<svg>`. And where Elementor registers no callback
 * (a post type without Elementor support, Elementor below 4.2) an API key's
 * raw `<script>` was stored as sent.
 *
 * Pinned here, for an API user without `unfiltered_html`:
 *  - in place: three html widgets carrying `<script>`, `<style>` and `<svg>`
 *    stay byte-identical across wp_edit_widget, wp_edit_section, patch,
 *    wp_set_elementor, find/replace on unrelated text, a doubly-registered
 *    sanitizer, a writer outside any REST route, and a rollback;
 *  - nothing is known except in place: a string stored in another element
 *    (review round 1, BLOCK 1), in another widget type at the same id, or on
 *    another post (clone, template apply, part insert, save-as-template) is
 *    sanitized — including where Elementor registered no sanitizer at all
 *    (BLOCK 2);
 *  - a user who holds `unfiltered_html` stores raw, and MCPWP's own #772
 *    grant never counts as holding it;
 *  - Kit CSS: `.a>.b` stored as sent with no repair reported, and no route
 *    stores `</style` (WARN 1).
 *
 * `elementor_sanitize_data()` below is Elementor 4.3.2's callback
 * (modules/wp-rest/classes/elementor-post-meta.php), with a kses stand-in that
 * does to these fixtures what wp_kses_post() does on a real site. MCPWP's own
 * policy calls wp_kses_post() (the bootstrap's double), so its output is
 * asserted against that; the rig proves the real one. Each test
 * runs in its own process: the writer checks ELEMENTOR_VERSION, and a constant
 * cannot be undefined for the tests that follow (#757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-elementor-pro.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';

final class ElementorNoCollateralKsesTest extends TestCase {

	private const PAGE_ID     = 8291;
	private const TEMPLATE_ID = 8292;
	private const TARGET_ID   = 8293;
	private const POST_ID     = 8294;
	private const KIT_ID      = 4;
	private const API_USER    = 829;

	private const SCRIPT = '<script>window.__ph829=1;</script>';
	private const STYLE  = '<style>.ftr>.x{color:red}</style>';
	private const SVG    = '<svg viewBox="0 0 10 10"><path d="M0 0L10 10"/></svg>';

	protected function setUp(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.3.2' );
		}

		$GLOBALS['_mcpwp_test_meta']            = array();
		$GLOBALS['_mcpwp_test_registered_meta'] = array();
		$GLOBALS['_mcpwp_test_posts']           = array(
			self::PAGE_ID     => $this->post( self::PAGE_ID, 'page' ),
			self::TEMPLATE_ID => $this->post( self::TEMPLATE_ID, 'elementor_library' ),
			self::TARGET_ID   => $this->post( self::TARGET_ID, 'page' ),
			self::POST_ID     => $this->post( self::POST_ID, 'post' ),
		);

		// The mcpwp_api_agent user: no unfiltered_html.
		$this->as_user( false );

		// A REST request, though the policy no longer looks at the route.
		$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'rest_route' => '/mcpwp/v1/mcp' ) );

		// The plugin boots the chokepoint at load, before rest_api_init.
		if ( false === has_filter( 'update_post_metadata', array( 'Mcpwp_Elementor_Meta_Write', 'on_update_post_metadata' ) ) ) {
			Mcpwp_Elementor_Meta_Write::boot();
		}

		// Then Elementor registers its meta on rest_api_init — for pages and
		// its library, as get_post_types_by_support( 'elementor' ) does by
		// default; not for posts.
		$this->elementor_registers( 'page' );

		$this->seed( self::PAGE_ID );
	}

	// ---------------------------------------------------------------------
	// Elementor 4.3.2's sanitize callbacks, and a kses stand-in.
	// ---------------------------------------------------------------------

	private function elementor_registers( string $subtype ): void {
		register_meta( 'post', '_elementor_data', array( 'object_subtype' => $subtype, 'sanitize_callback' => array( self::class, 'elementor_sanitize_data' ) ) );
		register_meta( 'post', '_elementor_page_settings', array( 'object_subtype' => $subtype, 'sanitize_callback' => array( self::class, 'elementor_sanitize_settings' ) ) );
	}

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
		$GLOBALS['mcpwp_test_current_user']                = self::API_USER;
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

	private static function section( string $id, string $column_id, array $widgets ): array {
		return array(
			'id'       => $id,
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => $column_id,
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => $widgets,
				),
			),
		);
	}

	private static function tree(): array {
		return array(
			self::section(
				'sec8291',
				'col8291',
				array(
					self::widget( 'hd82901', 'heading', array( 'title' => 'Hello 829' ) ),
					self::widget( 'sc82901', 'html', array( 'html' => self::SCRIPT ) ),
					self::widget( 'st82901', 'html', array( 'html' => self::STYLE ) ),
					self::widget( 'sv82901', 'html', array( 'html' => self::SVG ) ),
				)
			),
		);
	}

	/** Stored raw, as an admin in the Elementor editor leaves it. */
	private function seed( int $post_id, ?array $tree = null ): void {
		$GLOBALS['_mcpwp_test_meta'][ $post_id ]['_elementor_data']      = wp_json_encode( $tree ?? self::tree() );
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

	private function html_widgets( int $post_id ): array {
		$out  = array();
		$walk = static function ( array $elements ) use ( &$walk, &$out ) {
			foreach ( $elements as $element ) {
				if ( 'html' === ( $element['widgetType'] ?? '' ) ) {
					$out[] = $element['settings']['html'] ?? null;
				}
				$walk( is_array( $element['elements'] ?? null ) ? $element['elements'] : array() );
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

	private function assert_html_widgets_sanitized( int $post_id, string $after ): void {
		$this->assertSame(
			array( wp_kses_post( self::SCRIPT ), wp_kses_post( self::STYLE ), wp_kses_post( self::SVG ) ),
			$this->html_widgets( $post_id ),
			"After {$after}, content that is not unchanged in place is sanitized for this caller."
		);
	}

	private function rest_request( string $method, string $route, array $params ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	private function data( $result ) {
		return $result instanceof WP_REST_Response ? $result->get_data() : $result;
	}

	private function assert_ok( $result, string $label ): void {
		$data = $this->data( $result );
		$this->assertNotInstanceOf( WP_Error::class, $data, $label . ': ' . ( $data instanceof WP_Error ? $data->get_error_message() : '' ) );
	}

	private function edit_widget( int $post_id, string $widget_id, array $settings ) {
		return ( new Mcpwp_REST_Elementor() )->edit_widget(
			$this->rest_request( 'POST', "/mcpwp/v1/elementor/{$post_id}/edit-widget", array( 'id' => $post_id, 'widget_id' => $widget_id, 'settings' => $settings ) )
		);
	}

	private function set_elementor( int $post_id, array $tree ) {
		return ( new Mcpwp_REST_Elementor() )->set_elementor_data(
			$this->rest_request( 'POST', "/mcpwp/v1/elementor/{$post_id}", array( 'id' => $post_id, 'elementor_data' => wp_json_encode( $tree ) ) )
		);
	}

	// ---------------------------------------------------------------------
	// In place: no collateral damage.
	// ---------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_edit_widget_on_a_heading_keeps_every_html_widget(): void {
		$this->assert_ok( $this->edit_widget( self::PAGE_ID, 'hd82901', array( 'title' => 'Edited 829' ) ), 'edit_widget' );

		$this->assertSame( 'Edited 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'wp_edit_widget on the heading' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_edit_section_keeps_every_html_widget(): void {
		$result = ( new Mcpwp_REST_Elementor() )->edit_section(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID . '/edit-section', array( 'id' => self::PAGE_ID, 'section_index' => 0, 'settings' => array( 'background_color' => '#eeeeee' ) ) )
		);

		$this->assert_ok( $result, 'edit_section' );
		$this->assertSame( '#eeeeee', self::find( $this->stored_tree( self::PAGE_ID ), 'sec8291' )['settings']['background_color'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'wp_edit_section' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
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

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_elementor_resending_the_html_widgets_keeps_them(): void {
		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][0]['settings']['title'] = 'Set 829';

		$this->assert_ok( $this->set_elementor( self::PAGE_ID, $tree ), 'set_elementor' );
		$this->assertSame( 'Set 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'wp_set_elementor with the html widgets resent unchanged' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_find_replace_on_unrelated_text_keeps_every_html_widget(): void {
		$result = ( new Mcpwp_REST_Elementor() )->find_replace(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/find-replace', array( 'id' => self::PAGE_ID, 'search' => 'Hello 829', 'replace' => 'Howdy 829' ) )
		);

		$this->assert_ok( $result, 'find_replace' );
		$this->assertSame( 'Howdy 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'find/replace on the heading text' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_registered_twice_still_keeps_every_html_widget(): void {
		// Elementor registers its meta on every rest_api_init, so the sanitize
		// hook can carry two wrapped copies of its callback.
		$first = $GLOBALS['_mcpwp_test_registered_meta']['_elementor_data']['sanitize_callback'];
		add_filter( 'sanitize_post_meta__elementor_data_for_page', $first, 10, 4 );
		$this->elementor_registers( 'page' );

		$this->edit_widget( self::PAGE_ID, 'hd82901', array( 'title' => 'Twice 829' ) );

		$this->assertSame( 'Twice 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'a write through a doubly-registered sanitizer' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_writer_outside_any_rest_route_keeps_every_html_widget(): void {
		// Review round 1, WARN 2: WebMCP, admin-AJAX chat, cron and WP-CLI are
		// not /mcpwp/ REST routes. The policy follows the writer, not the
		// route — here a WebMCP-style dispatch with no REST route at all.
		unset( $GLOBALS['wp'] );
		$previous = class_exists( 'Mcpwp_WebMCP' ) ? Mcpwp_WebMCP::begin_dispatch( self::API_USER ) : 0;

		try {
			$result = ( new Mcpwp_Elementor_Basic() )->edit_widget( self::PAGE_ID, 'hd82901', array( 'title' => 'Dispatched 829' ) );
		} finally {
			if ( class_exists( 'Mcpwp_WebMCP' ) ) {
				Mcpwp_WebMCP::end_dispatch( $previous );
			}
		}

		$this->assert_ok( $result, 'edit_widget outside REST' );
		$this->assertSame( 'Dispatched 829', self::find( $this->stored_tree( self::PAGE_ID ), 'hd82901' )['settings']['title'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'a write made outside any REST route' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rollback_restores_the_snapshot_of_the_same_post(): void {
		// The page as a bad write left it: script body as text, style and svg
		// gone. The server-side snapshot holds what it was before, and nothing
		// has written the page since the logged write.
		$stripped = self::tree();
		foreach ( array( 1, 2, 3 ) as $i ) {
			$html = $stripped[0]['elements'][0]['elements'][ $i ]['settings']['html'];
			$stripped[0]['elements'][0]['elements'][ $i ]['settings']['html'] = self::kses( $html );
		}
		$this->seed( self::PAGE_ID, $stripped );

		$snap  = array(
			'post_id'        => self::PAGE_ID,
			'elementor_data' => wp_json_encode( self::tree() ),
		);
		$entry = array(
			'resource_type' => 'elementor',
			'after_snap'    => wp_json_encode( array( 'post_id' => self::PAGE_ID, 'elementor_data' => wp_json_encode( $stripped ) ) ),
		);

		$this->assertTrue( $this->rollback( $entry, $snap ) );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'a rollback of a post nothing has changed since' );
	}

	/**
	 * Restore a before-snapshot exactly as Mcpwp_Action_Log::rollback() does:
	 * trusted only when the post still holds the logged write's after_snap.
	 */
	private function rollback( array $entry, array $snap ) {
		$trusted = new ReflectionMethod( Mcpwp_Action_Log::class, 'snapshot_is_trusted' );
		$trusted->setAccessible( true );
		$apply = new ReflectionMethod( Mcpwp_Action_Log::class, 'apply_snapshot' );
		$apply->setAccessible( true );

		return $apply->invoke( null, 'elementor', $snap, $trusted->invoke( null, $entry, $snap ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_rollback_does_not_restore_a_script_an_admin_has_since_removed(): void {
		// Review round 2, WARN 1. The key's logged write left the page with
		// the script (after_snap). An admin then removed it. The key rolls
		// back: the snapshot is no longer "what this write replaced" on a
		// post nothing else touched, so it is sanitized like new content.
		$logged_after = self::tree();
		$logged_after[0]['elements'][0]['elements'][0]['settings']['title'] = 'Key edit';

		$admin_removed = $logged_after;
		$admin_removed[0]['elements'][0]['elements'][1]['settings']['html'] = '<p>removed by admin</p>';
		$this->seed( self::PAGE_ID, $admin_removed );

		$entry = array(
			'resource_type' => 'elementor',
			'after_snap'    => wp_json_encode( array( 'post_id' => self::PAGE_ID, 'elementor_data' => wp_json_encode( $logged_after ) ) ),
		);
		$snap  = array(
			'post_id'        => self::PAGE_ID,
			'elementor_data' => wp_json_encode( self::tree() ),
		);

		$this->assertTrue( $this->rollback( $entry, $snap ) );

		$stored = self::find( $this->stored_tree( self::PAGE_ID ), 'sc82901' )['settings']['html'];
		$this->assertStringNotContainsString( '<script', $stored, 'The script the admin removed is not restored.' );
		$this->assertSame( wp_kses_post( self::SCRIPT ), $stored );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_page_settings_the_caller_did_not_change_are_kept(): void {
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_page_settings'] = array(
			'custom_css' => 'selector .a > .b{color:red}',
			'hide_title' => 'yes',
		);

		$settings               = get_post_meta( self::PAGE_ID, '_elementor_page_settings', true );
		$settings['hide_title'] = 'no';
		Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_page_settings', $settings );

		$this->assertSame(
			array(
				'custom_css' => 'selector .a > .b{color:red}',
				'hide_title' => 'no',
			),
			get_post_meta( self::PAGE_ID, '_elementor_page_settings', true )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_when_nothing_is_introduced_the_callers_bytes_are_stored(): void {
		// Unicode, emoji, backslashes, dynamic tags and globals, all unedited:
		// the stored text is exactly the text sent, not a re-encoding.
		$tree   = self::tree();
		$tree[] = self::section(
			'sec8292',
			'col8292',
			array(
				self::widget(
					'tx82902',
					'text-editor',
					array(
						'editor'      => 'Café 🎉 C:\\path\\to <b>bold</b>',
						'__dynamic__' => array( 'link' => '[elementor-tag id="1a2b3c4" name="post-url" settings="%7B%22a%22%3A1%7D"]' ),
						'__globals__' => array( 'text_color' => 'globals/colors?id=primary' ),
					)
				),
			)
		);
		$json = wp_json_encode( $tree, JSON_UNESCAPED_UNICODE );
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = $json;

		// update() takes the value as it is to be stored and slashes it itself.
		Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_data', $json );

		$this->assertSame( $json, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] );
	}

	/**
	 * Every MCPWP writer of these keys goes through the chokepoint. Scans whole
	 * statements (multi-line calls included) for: a meta write naming either
	 * key; a meta write whose key is a variable; a `meta_input` array naming
	 * either key; and a direct `$wpdb` write to postmeta. Each known-safe one
	 * is allowlisted below with the reason it is safe (review round 2, NIT).
	 */
	public function test_no_writer_of_these_keys_bypasses_the_chokepoint(): void {
		$allow = array(
			// Keys from a fixed map of non-Elementor keys in the same function.
			'pro/core/class-mcpwp-events.php|update_post_meta( $post_id, $meta_key,'                         => 'tp_event_* map',
			'pro/core/class-mcpwp-learnpress.php|update_post_meta( $post_id, $meta_key,'                     => '_lp_* maps',
			'pro/core/class-mcpwp-seo.php|update_post_meta( $post_id, $meta_map[ $key ],'                    => 'SEO plugin key maps',
			'pro/core/class-mcpwp-elementor-pro.php|update_post_meta( $template_id, $this->part_'            => 'part flag/tags keys',
			'pro/core/class-mcpwp-elementor-pro.php|update_post_meta( $template_id, $this->archetype_'       => 'archetype flag key',
			'pro/core/class-mcpwp-elementor-pro.php|update_post_meta( $template_id, $meta_key, $value )'     => 'part/archetype metadata maps',
			// Caller-named keys, refused for every `_elementor*` prefix by $blocked_prefixes.
			'core/class-mcpwp-post-fields.php|update_post_meta( $post_id, $key, $value )'                    => 'blocked _elementor prefix',
			// Elementor keys take the chokepoint branch just above this call.
			'pro/api/class-mcpwp-rest-elementor-pro.php|update_post_meta( $id, $key, $sanitized[\'content\'], $value )' => 'KEYS routed to update()',
			// Inside Mcpwp_Elementor_Meta_Write::as_writer( $new_id ).
			'pro/core/class-mcpwp-elementor-pro.php|add_post_meta( $new_id, $key,'                            => 'clone copy loop in as_writer',
			// meta_input arrays, each passed to a wp_insert_post() inside as_writer( 0 ).
			'pro/core/class-mcpwp-elementor-pro.php|$new_post[\'meta_input\'] = array( \'_elementor_data\''    => 'clone insert in as_writer',
			'pro/core/class-mcpwp-elementor-pro.php|$page_data[\'meta_input\'] = array( \'_elementor_data\''   => 'landing insert in as_writer',
			'pro/core/class-mcpwp-page-builder.php|\'meta_input\'  => array( \'_elementor_data\''              => 'page builder insert in as_writer',
			// The Kit CSS byte repair: writes the exact bytes the route already
			// validated (no `</style`), and only when the first write was altered.
			'api/class-mcpwp-rest-elementor.php|$wpdb->update(\n\t\t\t\t$wpdb->postmeta'                     => 'Kit CSS repair',
		);

		$root     = dirname( __DIR__ ) . '/includes';
		$offences = array();
		$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		$keys     = "('_elementor_data'|'_elementor_page_settings'|self::META_KEY|Mcpwp_Elementor_Page_Settings::META_KEY)";

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || 'class-mcpwp-elementor-meta-write.php' === $file->getFilename() ) {
				continue;
			}
			$rel  = substr( $file->getPathname(), strlen( $root ) + 1 );
			$code = self::code_without_comments( (string) file_get_contents( $file->getPathname() ) );

			$patterns = array(
				// A meta write naming either key, or with a variable key.
				'#(?<!::)\b(update_post_meta|add_post_meta|update_metadata|add_metadata)\s*\((?:[^;]*?' . $keys . '|\s*[^,;]+,\s*\$)[^;]*;#s',
				// A meta_input array naming either key (an assignment, not a read).
				"#[^\\n;]*('meta_input'\\s*=>|\\['meta_input'\\]\\s*=(?!=))[^\\n;]*" . $keys . '[^\n;]*#',
				// A direct write to postmeta.
				'#\$wpdb->(update|insert|replace|query)\s*\([^;]*postmeta[^;]*;#s',
			);

			foreach ( $patterns as $pattern ) {
				if ( ! preg_match_all( $pattern, $code, $m, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				foreach ( $m[0] as list( $statement, $offset ) ) {
					if ( false !== strpos( $statement, 'Mcpwp_Elementor_Meta_Write::' ) || false !== strpos( substr( $code, max( 0, $offset - 30 ), 30 ), 'Mcpwp_Elementor_Meta_Write::' ) ) {
						continue;
					}
					// Only the options/term/user meta of other plugins' tables are
					// out of scope: a postmeta $wpdb write must name one of our keys.
					if ( 0 === strpos( ltrim( $statement ), '$wpdb' ) && ! preg_match( '#_elementor_page_settings|_elementor_data|meta_key#', $statement ) ) {
						continue;
					}
					$allowed = false;
					foreach ( $allow as $sig => $why ) {
						list( $f, $needle ) = explode( '|', $sig, 2 );
						if ( $f === $rel && false !== strpos( $statement, stripcslashes( $needle ) ) ) {
							$allowed = true;
							break;
						}
					}
					if ( ! $allowed ) {
						$line       = substr_count( substr( $code, 0, $offset ), "\n" ) + 1;
						$offences[] = "{$rel}:{$line}: " . preg_replace( '#\s+#', ' ', trim( $statement ) );
					}
				}
			}
		}

		$this->assertSame( array(), $offences, 'Write these keys with Mcpwp_Elementor_Meta_Write::update() or inside as_writer() — or allowlist a proven-safe writer with its reason (#829).' );
	}

	// ---------------------------------------------------------------------
	// Nothing is known except in place.
	// ---------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_stored_string_moved_into_another_element_is_sanitized(): void {
		// Review round 1, BLOCK 1: an admin's code-highlight widget prints this
		// script as text. The same string in an html widget executes.
		$code = '<script>window.__rv1083move=1</script>';
		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][] = self::widget( 'cd82901', 'code-highlight', array( 'code' => $code ) );
		$this->seed( self::PAGE_ID, $tree );

		$tree[0]['elements'][0]['elements'][] = self::widget( 'htm0001', 'html', array( 'html' => $code ) );
		$this->set_elementor( self::PAGE_ID, $tree );

		$stored = $this->stored_tree( self::PAGE_ID );
		$this->assertSame( wp_kses_post( $code ), self::find( $stored, 'htm0001' )['settings']['html'], 'Not in place: sanitized.' );
		$this->assertStringNotContainsString( '<script', self::find( $stored, 'htm0001' )['settings']['html'] );
		$this->assertSame( $code, self::find( $stored, 'cd82901' )['settings']['code'], 'The admin widget itself is untouched.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_same_id_as_another_widget_type_is_not_in_place(): void {
		// Keeping an element's id and setting path while changing what kind of
		// widget reads it changes what the string does.
		$code = '<script>window.__retype829=1</script>';
		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][] = self::widget( 'rt82901', 'custom-inert', array( 'html' => $code ) );
		$this->seed( self::PAGE_ID, $tree );

		$last                                 = count( $tree[0]['elements'][0]['elements'] ) - 1;
		$tree[0]['elements'][0]['elements'][ $last ]['widgetType'] = 'html';
		$this->set_elementor( self::PAGE_ID, $tree );

		$stored = $this->stored_tree( self::PAGE_ID );
		$this->assertSame( wp_kses_post( $code ), self::find( $stored, 'rt82901' )['settings']['html'] );
		$this->assertSame( self::SCRIPT, self::find( $stored, 'sc82901' )['settings']['html'] );
		$this->assertSame( self::STYLE, self::find( $stored, 'st82901' )['settings']['html'] );
		$this->assertSame( self::SVG, self::find( $stored, 'sv82901' )['settings']['html'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_raw_script_on_a_type_elementor_does_not_sanitize_is_sanitized(): void {
		// Review round 1, BLOCK 2 (a hole on main): Elementor registers its
		// sanitizer only for get_post_types_by_support( 'elementor' ), and not
		// at all below 4.2. MCPWP applies the policy itself.
		$this->seed( self::POST_ID, array() );

		$this->assert_ok( $this->set_elementor( self::POST_ID, self::tree() ), 'set_elementor on a post' );

		$this->assert_html_widgets_sanitized( self::POST_ID, 'wp_set_elementor on a post type without Elementor\'s sanitizer' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_block_2_chain_save_as_template_then_apply_stays_sanitized(): void {
		// The chain the review built: raw script stored on a type Elementor
		// does not sanitize (here, as a pre-existing row), saved as a
		// template, applied to a page. Every copy is a write by this key.
		$this->seed( self::POST_ID );

		$saved = ( new Mcpwp_Elementor_Pro() )->save_section_as_template( self::POST_ID, 'sec8291', 'b829 section' );
		$this->assert_ok( $saved, 'save_section_as_template' );
		$this->assert_html_widgets_sanitized( (int) $saved['template_id'], 'wp_save_section_as_template' );

		$applied = ( new Mcpwp_Elementor_Pro() )->apply_template_to_page( self::TARGET_ID, (int) $saved['template_id'] );
		$this->assert_ok( $applied, 'apply_template_to_page' );
		$this->assert_html_widgets_sanitized( self::TARGET_ID, 'wp_apply_elementor_template of that template' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_applying_a_template_is_a_sanitized_copy_for_a_key(): void {
		$this->seed( self::TEMPLATE_ID );

		$this->assert_ok( ( new Mcpwp_Elementor_Pro() )->apply_template_to_page( self::TARGET_ID, self::TEMPLATE_ID ), 'apply_template_to_page' );

		$this->assert_html_widgets_sanitized( self::TARGET_ID, 'wp_apply_elementor_template' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_inserting_a_part_is_a_sanitized_copy_for_a_key(): void {
		$this->seed( self::TEMPLATE_ID );
		$this->seed( self::TARGET_ID, array() );

		$pro = new class() extends Mcpwp_Elementor_Pro {
			public function get_part( $part_id ) {
				return array(
					'id'             => (int) $part_id,
					'elementor_data' => get_post_meta( (int) $part_id, '_elementor_data', true ),
				);
			}
		};

		$this->assert_ok( $pro->insert_part_into_page( self::TEMPLATE_ID, self::TARGET_ID, 'end' ), 'insert_part_into_page' );
		$this->assert_html_widgets_sanitized( self::TARGET_ID, 'wp_apply_elementor_part (insert)' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cloning_a_page_is_a_sanitized_copy_for_a_key(): void {
		$result = ( new Mcpwp_Elementor_Pro() )->clone_page( self::PAGE_ID, array( 'title' => 'b829 clone' ) );

		$this->assert_ok( $result, 'clone_page' );
		$this->assert_html_widgets_sanitized( (int) $result['id'], 'wp_clone_elementor_page (the copy)' );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'wp_clone_elementor_page (the source)' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_admin_clone_keeps_the_script(): void {
		$this->as_user( true );

		$result = ( new Mcpwp_Elementor_Pro() )->clone_page( self::PAGE_ID, array( 'title' => 'b829 admin clone' ) );

		$this->assert_ok( $result, 'clone_page' );
		$this->assert_html_widgets_intact( (int) $result['id'], 'a clone by a user holding unfiltered_html' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_flipping_a_form_field_to_html_sanitizes_its_stale_field_html(): void {
		// Review round 2, WARN 2: in place is per element, all or nothing. A
		// form field stored as text carries an inert field_html; flipping its
		// field_type to html would activate it.
		$form = self::widget(
			'fm82901',
			'form',
			array(
				'form_fields' => array(
					array( '_id' => 'fld0001', 'field_type' => 'text', 'field_label' => 'Name', 'field_html' => '<script>window.__form829=1</script>' ),
				),
			)
		);
		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][] = $form;
		$this->seed( self::PAGE_ID, $tree );

		$last = count( $tree[0]['elements'][0]['elements'] ) - 1;
		$tree[0]['elements'][0]['elements'][ $last ]['settings']['form_fields'][0]['field_type'] = 'html';
		$this->set_elementor( self::PAGE_ID, $tree );

		$stored = $this->stored_tree( self::PAGE_ID );
		$this->assertSame( wp_kses_post( '<script>window.__form829=1</script>' ), self::find( $stored, 'fm82901' )['settings']['form_fields'][0]['field_html'] );
		$this->assertSame( 'html', self::find( $stored, 'fm82901' )['settings']['form_fields'][0]['field_type'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'changing one element\'s neighbouring setting' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_removing_a_dynamic_override_sanitizes_the_static_value_it_exposes(): void {
		// Review round 2, WARN 2: with a __dynamic__ override the static html
		// never rendered; removing the override exposes it.
		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][] = self::widget(
			'dy82901',
			'html',
			array(
				'html'        => '<script>window.__dyn829=1</script>',
				'__dynamic__' => array( 'html' => '[elementor-tag id="d1e2f3a" name="post-title" settings="%7B%7D"]' ),
			)
		);
		$this->seed( self::PAGE_ID, $tree );

		$last = count( $tree[0]['elements'][0]['elements'] ) - 1;
		unset( $tree[0]['elements'][0]['elements'][ $last ]['settings']['__dynamic__'] );
		$this->set_elementor( self::PAGE_ID, $tree );

		$stored = $this->stored_tree( self::PAGE_ID );
		$this->assertSame( wp_kses_post( '<script>window.__dyn829=1</script>' ), self::find( $stored, 'dy82901' )['settings']['html'] );
		$this->assertSame( self::SCRIPT, self::find( $stored, 'sc82901' )['settings']['html'] );
		$this->assertSame( self::STYLE, self::find( $stored, 'st82901' )['settings']['html'] );
		$this->assertSame( self::SVG, self::find( $stored, 'sv82901' )['settings']['html'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unchanged_element_may_move_but_not_be_duplicated_raw(): void {
		// Accepted: moving an unchanged element within the same post keeps it.
		// Each stored element vouches for one copy, so a duplicate is sanitized.
		$tree    = self::tree();
		$widgets = $tree[0]['elements'][0]['elements'];
		$moved   = array( $widgets[0], $widgets[3], $widgets[2], $widgets[1], $widgets[1] );
		$tree[0]['elements'][0]['elements'] = $moved;

		$this->set_elementor( self::PAGE_ID, $tree );

		$this->assertSame(
			array( self::SVG, self::STYLE, self::SCRIPT, wp_kses_post( self::SCRIPT ) ),
			$this->html_widgets( self::PAGE_ID )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_bypass_is_bound_to_the_post_it_was_produced_for(): void {
		// Review round 2, NIT: while the policy re-writes post A, a write of
		// the same key and value to post B is not let through.
		$json = wp_json_encode( self::tree() );
		$fired = false;
		add_action(
			'updated_post_meta',
			static function ( $meta_id, $object_id, $key ) use ( $json, &$fired ) {
				if ( ! $fired && ElementorNoCollateralKsesTest::page_id() === (int) $object_id && '_elementor_data' === $key ) {
					$fired = true;
					update_post_meta( ElementorNoCollateralKsesTest::target_id(), '_elementor_data', wp_slash( $json ) );
				}
			},
			10,
			3
		);

		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][0]['settings']['title'] = 'Bound 829';
		$tree[0]['elements'][0]['elements'][1]['settings']['html']  = self::SCRIPT . '<p>changed</p>';
		$this->set_elementor( self::PAGE_ID, $tree );

		$this->assertTrue( $fired );
		$this->assert_html_widgets_sanitized( self::TARGET_ID, 'a same-key write to another post during the re-write' );
	}

	/** PHP source with comments blanked (newlines kept, so line numbers hold). */
	private static function code_without_comments( string $source ): string {
		$out = '';
		foreach ( token_get_all( $source ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$out .= str_repeat( "\n", substr_count( $token[1], "\n" ) );
				continue;
			}
			$out .= is_array( $token ) ? $token[1] : $token;
		}

		return $out;
	}

	public static function target_id(): int {
		return self::TARGET_ID;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_page_settings_objects_are_policed(): void {
		// Review round 2, NIT: objects, and objects nested in arrays.
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_page_settings'] = array(
			'kept' => (object) array( 'html' => self::SCRIPT ),
		);

		$settings = array(
			'kept'   => (object) array( 'html' => self::SCRIPT ),
			'nested' => array( (object) array( 'html' => '<script>window.__obj829=1</script>' ) ),
			'object' => (object) array( 'deep' => (object) array( 'html' => '<script>window.__obj829b=1</script>' ) ),
		);
		Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_page_settings', $settings );

		$stored = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_page_settings'];
		$this->assertSame( self::SCRIPT, $stored['kept']->html, 'An unchanged key is kept.' );
		$this->assertSame( wp_kses_post( '<script>window.__obj829=1</script>' ), $stored['nested'][0]->html );
		$this->assertSame( wp_kses_post( '<script>window.__obj829b=1</script>' ), $stored['object']->deep->html );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_page_settings_keep_their_backslashes(): void {
		// Review round 2, BLOCK: arrays reached update_post_meta() unslashed,
		// so every backslash was lost.
		$settings = array(
			'custom_css' => 'selector .i::before{content:"\\f101"}',
			'hide_title' => 'yes',
		);
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_page_settings'] = $settings;

		$settings['hide_title'] = 'no';
		Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_page_settings', $settings );

		$this->assertSame( 'selector .i::before{content:"\\f101"}', $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_page_settings']['custom_css'] );
	}

	// ---------------------------------------------------------------------
	// Review round 3.
	// ---------------------------------------------------------------------

	/** Every html widget's html, decoded associatively as Elementor does. */
	private function stored_assoc_html( int $post_id ): array {
		$out  = array();
		$walk = static function ( array $elements ) use ( &$walk, &$out ) {
			foreach ( $elements as $element ) {
				if ( is_array( $element ) && 'html' === ( $element['widgetType'] ?? '' ) ) {
					$out[ $element['id'] ] = $element['settings']['html'] ?? null;
				}
				if ( is_array( $element ) && is_array( $element['elements'] ?? null ) ) {
					$walk( $element['elements'] );
				}
			}
		};
		$walk( $this->stored_tree( $post_id ) );

		return $out;
	}

	private function assert_no_nul_key( int $post_id ): void {
		$this->assertStringNotContainsString( '\u0000', $GLOBALS['_mcpwp_test_meta'][ $post_id ]['_elementor_data'], 'No NUL-prefixed key is stored.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_nul_prefixed_key_does_not_turn_the_tree_into_text_via_set_elementor(): void {
		// Round 3, BLOCK 1: object-mode json_decode() returns null on a key
		// starting with \u0000, so the document counted as "not a tree" and
		// wp_kses_post() ran over the JSON text — which cannot see markup
		// written as <...>. Elementor decodes associatively and
		// would render the script.
		$json = substr( wp_json_encode( self::tree() ), 0, -5 )
			. ',{"id":"nul8291","elType":"widget","widgetType":"html","settings":{"\u0000x":"1","html":"<script>window.__nul829=1</script>"},"elements":[]}]}]}]';
		$this->assertIsArray( json_decode( $json, true ), 'precondition: the payload is a tree to Elementor' );
		$this->assertNull( json_decode( $json ), 'precondition: object mode cannot decode it' );

		$result = ( new Mcpwp_REST_Elementor() )->set_elementor_data(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/' . self::PAGE_ID, array( 'id' => self::PAGE_ID, 'elementor_data' => $json ) )
		);
		$this->assert_ok( $result, 'set_elementor' );

		$html = $this->stored_assoc_html( self::PAGE_ID );
		$this->assertArrayHasKey( 'nul8291', $html );
		$this->assertStringNotContainsString( '<script', (string) $html['nul8291'] );
		$this->assert_no_nul_key( self::PAGE_ID );
		$this->assertSame( self::SCRIPT, $html['sc82901'], 'Unedited widgets are still kept.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_nul_prefixed_key_does_not_turn_the_tree_into_text_via_edit_widget(): void {
		$this->assert_ok(
			$this->edit_widget( self::PAGE_ID, 'hd82901', array( "\0x" => '1', 'title' => '<script>window.__nulw829=1</script>' ) ),
			'edit_widget'
		);

		$tree  = $this->stored_tree( self::PAGE_ID );
		$title = self::find( $tree, 'hd82901' )['settings']['title'] ?? '';
		$this->assertStringNotContainsString( '<script', $title );
		$this->assert_no_nul_key( self::PAGE_ID );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'an edit carrying a NUL-prefixed key' );
	}

	public static function non_trees(): array {
		return array(
			'object'            => array( '{"id":"sec0001","elType":"section"}' ),
			'string'            => array( '"<script>window.__str829=1</script>"' ),
			'number'            => array( '42' ),
			'broken json'       => array( '[{"id":"sec0001"' ),
			'list of scalars'   => array( '["<script>x</script>"]' ),
			'associative array' => array( array( 'a' => array( 'id' => 'x' ) ) ),
		);
	}

	/**
	 * @dataProvider non_trees
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_data_that_is_not_a_tree_is_refused_and_nothing_is_stored( $value ): void {
		$before = $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'];

		$result = Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_data', $value );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'elementor_data_not_a_tree', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] );

		// The same through any other write MCPWP makes (Document::save(),
		// meta_input): the metadata filter refuses it too.
		$stored = Mcpwp_Elementor_Meta_Write::as_writer(
			self::PAGE_ID,
			static function () use ( $value ) {
				return update_post_meta( ElementorNoCollateralKsesTest::page_id(), '_elementor_data', wp_slash( $value ) );
			}
		);
		$this->assertFalse( (bool) $stored );
		$this->assertSame( $before, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_non_list_elements_field_is_never_unchanged(): void {
		// Round 3, WARN 1: `elements` as a string on an otherwise identical
		// element used to be dropped from the signature and kept raw.
		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][] = array(
			'id'         => 'el82901',
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array( 'title' => 'x' ),
			'elements'   => '<script>window.__elm829=1</script>',
		);
		$this->seed( self::PAGE_ID, $tree );

		Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_data', wp_json_encode( $tree ) );

		$stored = self::find( $this->stored_tree( self::PAGE_ID ), 'el82901' );
		$this->assertStringNotContainsString( '<script', (string) $stored['elements'] );
		$this->assert_html_widgets_intact( self::PAGE_ID, 'a write carrying a non-list elements field' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_huge_integers_compare_exactly(): void {
		// Round 3, NIT 1: two integers past PHP_INT_MAX decode to the same
		// float unless kept as strings; a changed one is a changed element.
		$tree = self::tree();
		$json = wp_json_encode( $tree );
		$seed = str_replace( '"html":"<script>', '"n":12345678901234567890123,"html":"<script>', $json );
		$next = str_replace( '"html":"<script>', '"n":12345678901234567890124,"html":"<script>', $json );
		$this->assertNotSame( $seed, $json, 'precondition' );
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = $seed;

		Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_data', $next );

		$this->assertStringNotContainsString( '<script', (string) self::find( $this->stored_tree( self::PAGE_ID ), 'sc82901' )['settings']['html'] );

		// And an unchanged huge integer is still unchanged.
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = $seed;
		Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_data', $seed );
		$this->assertSame( $seed, $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_admin_edit_between_begin_and_the_write_voids_rollback_trust(): void {
		// Round 3, NIT 2: begin() snapshots the page with the script; an
		// admin then removes it; the key's write lands. The snapshot is not
		// what that write replaced, so a rollback may not restore it raw.
		$with_script = wp_json_encode( self::tree() );
		Mcpwp_Elementor_Meta_Write::forget_replaced( self::PAGE_ID ); // begin()
		$before_snap = wp_json_encode( array( 'post_id' => self::PAGE_ID, 'elementor_data' => $with_script ) );

		$admin = self::tree();
		$admin[0]['elements'][0]['elements'][1]['settings']['html'] = '<p>removed by admin</p>';
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( $admin );

		$keyed = $admin;
		$keyed[0]['elements'][0]['elements'][0]['settings']['title'] = 'Key edit';
		Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_data', wp_json_encode( $keyed ) );

		$mark = new ReflectionMethod( Mcpwp_Action_Log::class, 'mark_raced_snapshot' );
		$mark->setAccessible( true );
		$snap = json_decode( $mark->invoke( null, array( 'type' => 'elementor', 'id' => self::PAGE_ID ), $before_snap ), true );
		$this->assertTrue( $snap['raced'] );

		$entry = array(
			'resource_type' => 'elementor',
			'after_snap'    => wp_json_encode( array( 'post_id' => self::PAGE_ID, 'elementor_data' => $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] ) ),
		);
		$this->assertTrue( $this->rollback( $entry, $snap ) );
		$this->assertStringNotContainsString( '<script', (string) self::find( $this->stored_tree( self::PAGE_ID ), 'sc82901' )['settings']['html'] );

		// Without the intervening edit the snapshot is not marked.
		$this->seed( self::PAGE_ID );
		Mcpwp_Elementor_Meta_Write::forget_replaced( self::PAGE_ID );
		Mcpwp_Elementor_Meta_Write::update( self::PAGE_ID, '_elementor_data', wp_json_encode( $keyed ) );
		$clean = json_decode( $mark->invoke( null, array( 'type' => 'elementor', 'id' => self::PAGE_ID ), $before_snap ), true );
		$this->assertArrayNotHasKey( 'raced', $clean );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_custom_code_sanitize_writes_page_settings_as_an_array(): void {
		// Round 3, NIT 3: get_post_meta( $id ) returns page settings as a
		// serialized string on a real site; it was stripped as text and
		// stored back as a string.
		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-elementor-pro.php';
		$snippet = 8295;
		$GLOBALS['_mcpwp_test_posts'][ $snippet ] = $this->post( $snippet, 'elementor_snippet' );
		$GLOBALS['_mcpwp_test_meta'][ $snippet ]['_elementor_page_settings'] = array(
			'custom_css' => 'a{}',
			'note'       => '<html><body>hello</body></html>',
		);

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$controller->sanitize_custom_code( $this->rest_request( 'POST', "/mcpwp/v1/elementor/custom-code/{$snippet}/sanitize", array( 'id' => $snippet ) ) );

		$stored = $GLOBALS['_mcpwp_test_meta'][ $snippet ]['_elementor_page_settings'];
		$this->assertIsArray( $stored, 'Still settings, not a serialized string.' );
		$this->assertSame( 'a{}', $stored['custom_css'] );
		$this->assertStringNotContainsString( '<html', $stored['note'] );
		$this->assertStringContainsString( 'hello', $stored['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_event_handlers_javascript_urls_and_svg_introduced_by_a_key_are_sanitized(): void {
		// Round 3, NIT 4: the kses double now removes what real kses does
		// for these; the rig proves it against real kses.
		$this->edit_widget( self::PAGE_ID, 'sc82901', array( 'html' => '<img src=x onerror="window.__img829=1"><a href="javascript:window.__a829=1">a</a><svg onload=x><path/></svg>t' ) );

		$html = (string) self::find( $this->stored_tree( self::PAGE_ID ), 'sc82901' )['settings']['html'];
		$this->assertStringNotContainsString( 'onerror', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( '<svg', $html );
		$this->assertSame( self::STYLE, self::find( $this->stored_tree( self::PAGE_ID ), 'st82901' )['settings']['html'] );
	}

	// ---------------------------------------------------------------------
	// Content the caller introduces.
	// ---------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_script_the_caller_introduces_is_sanitized(): void {
		$this->assert_ok( $this->edit_widget( self::PAGE_ID, 'sc82901', array( 'html' => '<script>window.__new829=1;</script><p>kept</p>' ) ), 'edit_widget' );

		$tree = $this->stored_tree( self::PAGE_ID );
		$this->assertSame( wp_kses_post( '<script>window.__new829=1;</script><p>kept</p>' ), self::find( $tree, 'sc82901' )['settings']['html'] );
		$this->assertSame( self::STYLE, self::find( $tree, 'st82901' )['settings']['html'] );
		$this->assertSame( self::SVG, self::find( $tree, 'sv82901' )['settings']['html'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_user_with_unfiltered_html_stores_introduced_content_raw(): void {
		$this->as_user( true );

		$this->edit_widget( self::PAGE_ID, 'sc82901', array( 'html' => '<script>window.__admin829=1;</script>' ) );

		$this->assertSame( '<script>window.__admin829=1;</script>', self::find( $this->stored_tree( self::PAGE_ID ), 'sc82901' )['settings']['html'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_772_grant_does_not_count_as_unfiltered_html(): void {
		// Document::save() runs under MCPWP's scoped grant (#772) and writes
		// _elementor_data itself. That write is judged on the caller's own
		// capability, or the raw bytes it stored would be "in place" for the
		// write after it.
		add_filter( 'content_save_pre', 'wp_filter_post_kses' );

		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][1]['settings']['html'] = '<script>window.__granted829=1;</script>';
		$json = wp_json_encode( $tree );

		$writer = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
		$call   = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'attempt_elementor_call' );
		$call->setAccessible( true );
		$debug = array();
		$saw   = null;

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
				self::PAGE_ID,
			)
		);
		remove_filter( 'content_save_pre', 'wp_filter_post_kses' );

		$this->assertTrue( $saw, 'The #772 grant is live around the Elementor call.' );
		$stored = $this->stored_tree( self::PAGE_ID );
		$this->assertStringNotContainsString( '<script', self::find( $stored, 'sc82901' )['settings']['html'], 'The grant did not reach the policy.' );
		$this->assertSame( self::STYLE, self::find( $stored, 'st82901' )['settings']['html'] );
	}

	public static function page_id(): int {
		return self::PAGE_ID;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_write_that_is_not_mcpwps_keeps_elementors_behaviour(): void {
		$tree = self::tree();
		$tree[0]['elements'][0]['elements'][0]['settings']['title'] = 'Core REST';
		update_post_meta( self::PAGE_ID, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );

		$this->assertSame( self::kses( self::STYLE ), self::find( $this->stored_tree( self::PAGE_ID ), 'st82901' )['settings']['html'] );
	}

	// ---------------------------------------------------------------------
	// Kit CSS (#761/#762): byte-for-byte, a repair only when one happened,
	// and never `</style` (review round 1, WARN 1).
	// ---------------------------------------------------------------------

	private function install_kit(): Mcpwp_REST_Elementor {
		require_once __DIR__ . '/fixtures/elementor-kit-doubles.php';
		\Elementor\Plugin::$instance                  = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_kit_id']                 = self::KIT_ID;
		$GLOBALS['_mcpwp_test_posts'][ self::KIT_ID ] = $this->post( self::KIT_ID, 'elementor_library' );
		$this->elementor_registers( 'elementor_library' );

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

	private function assert_breakout_refused( $response, string $label ): void {
		$data = $this->data( $response );
		$code = $data instanceof WP_Error ? $data->get_error_code() : ( $data['code'] ?? null );
		$this->assertSame( 'kit_css_style_breakout', $code, $label );
		$status = $response instanceof WP_REST_Response ? $response->get_status() : ( $data instanceof WP_Error ? ( $data->get_error_data()['status'] ?? null ) : null );
		$this->assertSame( 400, $status, $label );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_kit_css_block_is_stored_as_sent_and_reports_no_repair(): void {
		$controller = $this->install_kit();

		$data = $this->data(
			$controller->upsert_kit_css_block(
				$this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css/blocks', array( 'name' => 'b829', 'css' => '.a>.b{color:red}' ) )
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $data );
		$this->assertStringContainsString( '.a>.b{color:red}', $this->kit_css(), 'Stored as sent on the first write.' );
		$this->assertArrayNotHasKey( 'storage_repaired', $data, 'A clean write is not reported as repaired.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_kit_css_append_keeps_the_existing_stylesheet(): void {
		$controller = $this->install_kit();
		$GLOBALS['_mcpwp_test_meta'][ self::KIT_ID ]['_elementor_page_settings'] = array( 'custom_css' => '.old>.x{color:blue}' );

		$controller->set_kit_css(
			$this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css', array( 'css' => '.new>.y{color:red}', 'mode' => 'append' ) )
		);

		$this->assertSame( ".old>.x{color:blue}\n\n.new>.y{color:red}", $this->kit_css() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_real_repair_is_reported_with_the_characters_it_fixed(): void {
		$controller = $this->install_kit();
		// Something other than Elementor's sanitizer (a host filter, another
		// plugin) escapes the row: that is what the repair is still for.
		$GLOBALS['mcpwp_test_meta_escapes_html'] = true;

		try {
			$data = $this->data(
				$controller->upsert_kit_css_block(
					$this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css/blocks', array( 'name' => 'b829r', 'css' => '.a>.b{color:red}' ) )
				)
			);
		} finally {
			$GLOBALS['mcpwp_test_meta_escapes_html'] = false;
		}

		$this->assertTrue( $data['storage_repaired'] );
		$this->assertSame( array( '>' ), $data['storage_altered_chars'] );
		$this->assertStringContainsString( '.a>.b{color:red}', $this->kit_css() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_backslash_escape_round_trips_on_replace_and_upsert(): void {
		// Review round 2, BLOCK: `content:"\f101"` was stored as `"f101"`.
		$controller = $this->install_kit();
		$css        = '.i::before{content:"\\f101"}';

		$controller->set_kit_css( $this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css', array( 'css' => $css, 'mode' => 'replace' ) ) );
		$this->assertSame( $css, $this->kit_css(), 'wp_set_kit_css replace stores the backslash.' );

		$data = $this->data(
			$controller->upsert_kit_css_block( $this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css/blocks', array( 'name' => 'b829bs', 'css' => $css ) ) )
		);
		$this->assertStringContainsString( "\n" . $css . "\n", $this->kit_css(), 'The upserted block stores the backslash.' );
		$this->assertTrue( $data['verified'] );
		$this->assertArrayNotHasKey( 'storage_repaired', $data, 'A clean backslash write is not a repair.' );
	}

	public static function breakouts(): array {
		return array(
			'plain'      => array( '.a{color:red}</style><script>window.__kit829=1</script>' ),
			'upper case' => array( '.a{}</STYLE><script>x()</script>' ),
			'spaced'     => array( '.a{}</ style><script>x()</script>' ),
			'spaced <'   => array( ".a{}< /\tStyle><script>x()</script>" ),
			// Review round 2, BLOCK: backslash spellings, which update_post_meta()
			// used to unslash into a real `</style` after the check had passed.
			'<\\/style'   => array( 'a{}<\\/style><script>window.w1=1</script>' ),
			'<\\\\/style'  => array( 'a{}<\\\\/style><script>window.w1=1</script>' ),
			'mixed \\'    => array( "a{}< \\/ StYlE ><script>x()</script>" ),
		);
	}

	/**
	 * @dataProvider breakouts
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_kit_css_refuses_a_style_breakout( string $css ): void {
		$controller = $this->install_kit();
		$GLOBALS['_mcpwp_test_meta'][ self::KIT_ID ]['_elementor_page_settings'] = array( 'custom_css' => '.old{}' );

		foreach ( array( 'replace', 'append' ) as $mode ) {
			$this->assert_breakout_refused(
				$controller->set_kit_css( $this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css', array( 'css' => $css, 'mode' => $mode ) ) ),
				"set_kit_css {$mode}"
			);
		}
		$this->assertSame( '.old{}', $this->kit_css(), 'Nothing was saved.' );
	}

	/**
	 * @dataProvider breakouts
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_upsert_kit_css_block_refuses_a_style_breakout( string $css ): void {
		$controller = $this->install_kit();

		$this->assert_breakout_refused(
			$controller->upsert_kit_css_block( $this->rest_request( 'POST', '/mcpwp/v1/elementor/kit-css/blocks', array( 'name' => 'b829x', 'css' => $css ) ) ),
			'wp_upsert_kit_css_block'
		);
		$this->assertSame( '', $this->kit_css(), 'Nothing was saved.' );
	}

	/**
	 * @dataProvider breakouts
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_kit_css_raw_refuses_a_style_breakout( string $css ): void {
		$controller = $this->install_kit();

		$method = new ReflectionMethod( Mcpwp_REST_Elementor::class, 'set_kit_css_raw' );
		$method->setAccessible( true );
		$result = $method->invoke( $controller, self::KIT_ID, $css );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kit_css_style_breakout', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( '', $this->kit_css(), 'Nothing was saved.' );
	}
}
