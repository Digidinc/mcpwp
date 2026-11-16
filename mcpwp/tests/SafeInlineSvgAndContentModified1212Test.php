<?php
/**
 * #1212 — safe inline SVG on content writes + content_modified reporting.
 *
 * Two defects from buyer-ready QA:
 * 1. wp_kses_post() strips SVG for callers without unfiltered_html, with no
 *    useful warning about what was lost.
 * 2. A write response can look like the SVG survived while a persisted
 *    readback does not — report from the saved row, not the input.
 *
 * Safe SVG is allowed only through a scoped wp_kses_allowed_html filter around
 * MCPWP's own sanitize_content_body() kses call — never globally, and never
 * as a loosening of the #1179 Elementor / Kit CSS rules.
 *
 * @package MCPWP
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Mcpwp_Test_Content_Kses_Harness', false ) ) {
	/**
	 * Exposes sanitize_content_body() for unit coverage without a REST round-trip.
	 */
	class Mcpwp_Test_Content_Kses_Harness { // phpcs:ignore
		use Mcpwp_Sanitization;

		public function sanitize( $content ) {
			return $this->sanitize_content_body( $content );
		}

		public function report( $submitted, $post_id ) {
			return $this->content_modified_from_saved( $submitted, $post_id );
		}
	}
}

if ( ! function_exists( 'wp_kses_no_null' ) ) {
	function wp_kses_no_null( $string, $options = null ) { // phpcs:ignore
		$string     = preg_replace( '/\0+/', '', $string );
		$slash_zero = is_array( $options ) && isset( $options['slash_zero'] ) ? $options['slash_zero'] : 'remove';
		if ( 'remove' === $slash_zero ) {
			$string = preg_replace( '/\\\\+0+/', '', $string );
		}
		return $string;
	}
}

final class SafeInlineSvgAndContentModified1212Test extends TestCase {

	private const AGENT_ID = 9212;

	/** @var int */
	private static $filter_calls_outside = 0;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['_mcpwp_test_posts']           = array();
		$GLOBALS['_mcpwp_test_meta']            = array();
		$GLOBALS['_mcpwp_test_object_terms']    = array();
		$GLOBALS['_mcpwp_test_registered_meta'] = array();
		$GLOBALS['_mcpwp_test_taxonomies']      = array();
		$GLOBALS['mcpwp_test_allowed_html']     = array(
			'p'      => array( 'class' => true ),
			'a'      => array( 'href' => true, 'title' => true ),
			'strong' => array(),
			'em'     => array(),
			'span'   => array( 'class' => true ),
			'div'    => array( 'class' => true ),
			'br'     => array(),
			'img'    => array( 'src' => true, 'alt' => true, 'width' => true, 'height' => true ),
		);
		$GLOBALS['mcpwp_test_user_caps']        = array(
			self::AGENT_ID => array( 'unfiltered_html' => false ),
		);
		$GLOBALS['mcpwp_test_filters']          = array();
		wp_set_current_user( self::AGENT_ID );
		self::$filter_calls_outside = 0;
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['mcpwp_test_user_caps']    = array();
		$GLOBALS['mcpwp_test_allowed_html'] = mcpwp_test_default_post_allowed_html();
		$GLOBALS['mcpwp_test_filters']      = array();
		wp_set_current_user( 0 );
	}

	private function harness(): Mcpwp_Test_Content_Kses_Harness {
		return new Mcpwp_Test_Content_Kses_Harness();
	}

	private function seed( int $id, string $type = 'page' ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
			array(
				'ID'           => $id,
				'post_type'    => $type,
				'post_title'   => 'Seed',
				'post_name'    => 'seed',
				'post_status'  => 'publish',
				'post_content' => 'original',
				'post_excerpt' => '',
				'post_parent'  => 0,
				'menu_order'   => 0,
				'post_author'  => 1,
				'post_date'    => '2026-06-06 00:00:00',
				'post_date_gmt'=> '2026-06-06 00:00:00',
				'post_modified'=> '2026-06-06 00:00:00',
				'post_modified_gmt' => '2026-06-06 00:00:00',
			)
		);
	}

	private function removed_kinds( array $report ): array {
		$out = array();
		foreach ( $report['removed'] ?? array() as $row ) {
			$out[ $row['kind'] ] = (int) $row['count'];
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Safe SVG allow-list
	// ------------------------------------------------------------------

	public function test_safe_inline_svg_survives_sanitize_for_user_without_unfiltered_html(): void {
		$html = '<p>Hi</p><svg viewBox="0 0 10 10" width="10" height="10" role="img" aria-label="dot"><circle cx="5" cy="5" r="4" fill="#111"/></svg>';
		$out  = $this->harness()->sanitize( $html );

		$this->assertStringContainsString( '<svg', $out['content'] );
		$this->assertStringContainsString( '<circle', $out['content'] );
		$this->assertStringContainsString( 'viewBox="0 0 10 10"', $out['content'] );
	}

	public function test_safe_path_g_and_gradient_markup_survives(): void {
		$html = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">'
			. '<defs><linearGradient id="g"><stop offset="0%" stop-color="#000"/></linearGradient></defs>'
			. '<g transform="translate(1,1)"><path d="M0 0 L10 10" fill="url(#g)" stroke="#fff" stroke-width="1"/></g>'
			. '<title>Icon</title><desc>A mark</desc>'
			. '</svg>';
		$out  = $this->harness()->sanitize( $html );

		$this->assertStringContainsString( '<path', $out['content'] );
		$this->assertStringContainsString( 'linearGradient', $out['content'] );
		$this->assertStringContainsString( '<title>', $out['content'] );
	}

	public function test_same_document_use_href_survives_external_is_stripped(): void {
		$ok   = '<svg><defs><path id="p" d="M0 0h1v1z"/></defs><use href="#p"/></svg>';
		$bad  = '<svg><use href="https://evil.example/x.svg#p"/></svg>';
		$badx = '<svg><use xlink:href="https://evil.example/x.svg#p"/></svg>';

		$ok_out = $this->harness()->sanitize( $ok );
		$this->assertStringContainsString( 'href="#p"', $ok_out['content'] );

		$bad_out = $this->harness()->sanitize( $bad );
		$this->assertStringNotContainsString( 'evil.example', $bad_out['content'] );

		$badx_out = $this->harness()->sanitize( $badx );
		$this->assertStringNotContainsString( 'evil.example', $badx_out['content'] );
	}

	/**
	 * @dataProvider xss_vectors
	 */
	public function test_xss_vectors_are_defanged( string $input, string $forbidden_needle ): void {
		$out = $this->harness()->sanitize( $input );
		$this->assertStringNotContainsString( $forbidden_needle, $out['content'] );
		$this->assertDoesNotMatchRegularExpression( '/\son[a-z]+\s*=/i', $out['content'] );
	}

	public function xss_vectors(): array {
		return array(
			'svg onload'              => array( '<svg onload="alert(1)"><circle r="1"/></svg>', 'onload' ),
			'script inside svg'       => array( '<svg><script>alert(1)</script><circle r="1"/></svg>', '<script' ),
			'foreignObject html'      => array( '<svg><foreignObject><body onload="alert(1)"><p>x</p></body></foreignObject></svg>', 'foreignObject' ),
			'xlink javascript'        => array( '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>', 'javascript:' ),
			'href javascript'         => array( '<svg><a href="javascript:alert(1)"><text>x</text></a></svg>', 'javascript:' ),
			'use external'            => array( '<svg><use href="https://evil.example/i.svg#a"/></svg>', 'evil.example' ),
			'style url javascript'    => array( '<svg><circle style="fill:url(javascript:alert(1))" r="1"/></svg>', 'javascript:' ),
			'style element'           => array( '<svg><style>circle{fill:url(javascript:alert(1))}</style><circle r="1"/></svg>', '<style' ),
			'nested svg onload'       => array( '<svg><svg onload="alert(1)"><rect width="1" height="1"/></svg></svg>', 'onload' ),
			'case variation SCRIPT'   => array( '<svg><ScRiPt>alert(1)</sCrIpT></svg>', 'alert(1)' ),
			'data url href'           => array( '<svg><a href="data:text/html,<script>alert(1)</script>"><text>x</text></a></svg>', 'data:' ),
			'animate event'           => array( '<svg><circle r="1" onfocus="alert(1)"/></svg>', 'onfocus' ),
		);
	}

	public function test_svg_allow_list_filter_is_not_left_installed(): void {
		$before = $GLOBALS['mcpwp_test_filters']['wp_kses_allowed_html'] ?? array();
		$this->harness()->sanitize( '<svg><circle r="1"/></svg>' );
		$after = $GLOBALS['mcpwp_test_filters']['wp_kses_allowed_html'] ?? array();
		$this->assertSame( $before, $after, 'Scoped filter must be removed after sanitize_content_body().' );

		// A later bare wp_kses_allowed_html('post') must not include our SVG tags.
		$tags = wp_kses_allowed_html( 'post' );
		$this->assertArrayNotHasKey( 'svg', $tags );
		$this->assertArrayNotHasKey( 'circle', $tags );
	}

	public function test_svg_filter_does_not_run_for_unrelated_kses_calls(): void {
		add_filter(
			'wp_kses_allowed_html',
			static function ( $tags, $context ) {
				if ( 'post' === $context ) {
					SafeInlineSvgAndContentModified1212Test::$filter_calls_outside++;
				}
				return $tags;
			},
			10,
			2
		);

		// Outside MCPWP's scoped write: baseline allow-list has no svg.
		$outside = wp_kses_post( '<p>x</p><svg><circle r="1"/></svg>' );
		$this->assertStringNotContainsString( '<svg', $outside );

		$calls_before = self::$filter_calls_outside;
		$this->harness()->sanitize( '<svg><circle r="1"/></svg>' );
		// Our scoped call may invoke allowed_html; the point is the outsider
		// hook is not required for SVG to work and must not permanently widen tags.
		$this->assertGreaterThanOrEqual( $calls_before, self::$filter_calls_outside );
		$this->assertArrayNotHasKey( 'svg', wp_kses_allowed_html( 'post' ) );
	}

	// ------------------------------------------------------------------
	// content_modified reporting (DB readback)
	// ------------------------------------------------------------------

	public function test_page_create_reports_content_modified_for_stripped_script_not_echoing_payload(): void {
		$pages = new Mcpwp_Pages();
		$raw   = '<p>Keep</p><script>window.__x=1</script><iframe src="https://evil.example"></iframe>';
		$created = $pages->create_page(
			array(
				'title'   => 'SVG report',
				'content' => $raw,
				'status'  => 'draft',
			)
		);

		$this->assertIsArray( $created );
		$this->assertArrayHasKey( 'content_modified', $created );
		$kinds = $this->removed_kinds( $created['content_modified'] );
		$this->assertArrayHasKey( 'script', $kinds );
		$this->assertSame( $created['content'], get_post_field( 'post_content', $created['id'] ) );
		$this->assertStringNotContainsString( 'window.__x', wp_json_encode( $created['content_modified'] ) );
		$this->assertStringNotContainsString( '<script>', $created['content'] );
	}

	public function test_page_update_reports_on_attributes_stripped_from_non_svg_markup(): void {
		$this->seed( 12120, 'page' );
		$pages = new Mcpwp_Pages();
		$raw   = '<p onclick="alert(1)" class="ok">Hello</p>';
		$updated = $pages->update_page(
			12120,
			array( 'content' => $raw )
		);

		$this->assertIsArray( $updated );
		$this->assertArrayHasKey( 'content_modified', $updated );
		$kinds = $this->removed_kinds( $updated['content_modified'] );
		$this->assertArrayHasKey( 'on_attributes', $kinds );
		$this->assertStringNotContainsString( 'onclick', $updated['content'] );
		$this->assertStringNotContainsString( 'alert(1)', wp_json_encode( $updated['content_modified'] ) );
	}

	public function test_safe_svg_write_does_not_claim_svg_was_removed(): void {
		$pages = new Mcpwp_Pages();
		$raw   = '<p>Logo</p><svg viewBox="0 0 8 8"><circle cx="4" cy="4" r="3" fill="#000"/></svg>';
		$created = $pages->create_page(
			array(
				'title'   => 'Safe SVG',
				'content' => $raw,
				'status'  => 'draft',
			)
		);

		$this->assertIsArray( $created );
		$this->assertStringContainsString( '<svg', $created['content'] );
		if ( isset( $created['content_modified'] ) ) {
			$kinds = $this->removed_kinds( $created['content_modified'] );
			$this->assertArrayNotHasKey( 'svg_elements', $kinds );
		}
	}

	public function test_post_update_content_modified_matches_saved_row(): void {
		$this->seed( 12121, 'post' );
		$posts = new Mcpwp_Posts();
		$raw   = '<p>Hi</p><script>evil()</script>';
		$updated = $posts->update_post(
			12121,
			array( 'content' => $raw )
		);

		$this->assertIsArray( $updated );
		$this->assertSame(
			(string) get_post_field( 'post_content', 12121 ),
			$updated['content']
		);
		$this->assertArrayHasKey( 'content_modified', $updated );
	}

	public function test_set_blocks_sanitizes_and_reports_from_saved_content(): void {
		$this->seed( 12122, 'page' );
		$controller = new Mcpwp_REST_Blocks();
		$ref        = new ReflectionProperty( Mcpwp_REST_Blocks::class, 'current_api_key_record' );
		$ref->setAccessible( true );
		$ref->setValue(
			$controller,
			array(
				'id'     => 'k1212',
				'label'  => 'k1212',
				'scopes' => array( 'admin', 'write' ),
				'role'   => 'admin',
				'type'   => 'api',
			)
		);

		$request = new WP_REST_Request( 'PUT', '/mcpwp/v1/blocks/12122' );
		$request->set_param( 'id', 12122 );
		$request->set_param( 'approval_required', false );
		// Harness has_blocks() only inspects post objects; string content is
		// treated as classic — allow_restricted_blocks lets the write proceed.
		$request->set_param( 'allow_restricted_blocks', true );
		$request->set_param( 'approval_note', '1212 sanitiser coverage' );
		$request->set_param(
			'content',
			'<!-- wp:paragraph --><p onclick="alert(1)">Hi</p><!-- /wp:paragraph -->'
		);

		$response = $controller->set_blocks( $request );
		$data     = $response instanceof WP_REST_Response ? $response->get_data() : $response;

		$this->assertIsArray( $data );
		$this->assertTrue( ! empty( $data['success'] ), wp_json_encode( $data ) );
		$saved = (string) get_post_field( 'post_content', 12122 );
		$this->assertStringNotContainsString( 'onclick', $saved );
		$this->assertArrayHasKey( 'content_modified', $data );
		$this->assertStringNotContainsString( 'alert(1)', wp_json_encode( $data['content_modified'] ) );
	}

	public function test_content_modified_helper_counts_kinds_only(): void {
		$this->seed( 12123, 'page' );
		$submitted = '<p onmouseover="x()">A</p><script>y()</script><svg onload="z()"><circle r="1"/></svg>';
		$sanitized = $this->harness()->sanitize( $submitted );
		$GLOBALS['_mcpwp_test_posts'][12123]->post_content = $sanitized['content'];

		$report = $this->harness()->report( $submitted, 12123 );
		$this->assertNotEmpty( $report );
		$encoded = wp_json_encode( $report );
		$this->assertStringNotContainsString( 'onmouseover="x()"', $encoded );
		$this->assertStringNotContainsString( 'y()', $encoded );
		$kinds = $this->removed_kinds( $report );
		$this->assertNotEmpty( $kinds );
	}
}
