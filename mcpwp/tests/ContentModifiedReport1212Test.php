<?php
/**
 * #1212 — report content WordPress removed on save (reporting only).
 *
 * Defect (2): a write response can look like markup survived while a
 * persisted readback does not. After the write, compare submitted vs saved
 * inventories and report kind+count only. This suite does not allow SVG;
 * sanitising paths match origin/main.
 *
 * @package MCPWP
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-blocks.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-media.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-approvals.php';

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Mcpwp_Test_Content_Modified_Harness', false ) ) {
	/**
	 * Exposes content_modified helpers without a REST round-trip.
	 */
	class Mcpwp_Test_Content_Modified_Harness { // phpcs:ignore
		use Mcpwp_Sanitization;

		public function report( $submitted, $post_id ) {
			return $this->content_modified_from_saved( $submitted, $post_id );
		}

		public function attach( array &$result, $submitted, $post_id ) {
			$this->attach_content_modified_report( $result, $submitted, $post_id );
		}

		public function attach_strings( array &$result, $submitted, $saved ) {
			$this->attach_content_modified_from_strings( $result, $submitted, $saved );
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

final class ContentModifiedReport1212Test extends TestCase {

	private const AGENT_ID = 9212;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']          = array();
		$GLOBALS['_mcpwp_test_posts']           = array();
		$GLOBALS['_mcpwp_test_meta']            = array();
		$GLOBALS['_mcpwp_test_object_terms']    = array();
		$GLOBALS['_mcpwp_test_registered_meta'] = array();
		$GLOBALS['_mcpwp_test_taxonomies']      = array();
		$GLOBALS['mcpwp_test_user_caps']        = array(
			self::AGENT_ID => array( 'unfiltered_html' => false ),
		);
		$GLOBALS['mcpwp_test_filters']          = array();
		wp_set_current_user( self::AGENT_ID );
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts']    = array();
		$GLOBALS['_mcpwp_test_meta']     = array();
		$GLOBALS['mcpwp_test_user_caps'] = array();
		$GLOBALS['mcpwp_test_filters']   = array();
		wp_set_current_user( 0 );
	}

	private function harness(): Mcpwp_Test_Content_Modified_Harness {
		return new Mcpwp_Test_Content_Modified_Harness();
	}

	private function seed( int $id, string $type = 'page' ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
			array(
				'ID'                => $id,
				'post_type'         => $type,
				'post_title'        => 'Seed',
				'post_name'         => 'seed',
				'post_status'       => 'publish',
				'post_content'      => 'original',
				'post_excerpt'      => '',
				'post_parent'       => 0,
				'menu_order'        => 0,
				'post_author'       => 1,
				'post_date'         => '2026-06-06 00:00:00',
				'post_date_gmt'     => '2026-06-06 00:00:00',
				'post_modified'     => '2026-06-06 00:00:00',
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

	public function test_page_create_reports_script_and_svg_removal_without_echoing_payload(): void {
		$pages   = new Mcpwp_Pages();
		$raw     = '<p>Keep</p><script>window.__x=1</script><svg viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>';
		$created = $pages->create_page(
			array(
				'title'   => 'Report removals',
				'content' => $raw,
				'status'  => 'draft',
			)
		);

		$this->assertIsArray( $created );
		$this->assertArrayHasKey( 'content_modified', $created );
		$kinds = $this->removed_kinds( $created['content_modified'] );
		$this->assertArrayHasKey( 'script', $kinds );
		$this->assertArrayHasKey( 'svg_elements', $kinds );
		$message = (string) $created['content_modified']['message'];
		$this->assertStringContainsString( 'SVG elements were removed when saving', $message );
		$this->assertStringContainsString( 'convert the graphic to PNG or WebP, upload that as media and reference it with an image block', $message );
		$this->assertStringNotContainsString( 'add the image as a media file', $message );
		$this->assertStringContainsString( 'script, embed and form markup is not stored by this tool', $message );
		$this->assertStringNotContainsString( 'unfiltered_html', $message );
		$this->assertSame( $created['content'], get_post_field( 'post_content', $created['id'] ) );
		$encoded = wp_json_encode( $created['content_modified'] );
		$this->assertStringNotContainsString( 'window.__x', $encoded );
		$this->assertStringNotContainsString( '<script>', $encoded );
		$this->assertStringNotContainsString( 'made safe', strtolower( $encoded ) );
		$this->assertStringNotContainsString( '<svg', $created['content'] );
	}

	public function test_iframe_only_removal_does_not_mention_svg_or_capability(): void {
		$pages   = new Mcpwp_Pages();
		$created = $pages->create_page(
			array(
				'title'   => 'Iframe only',
				'content' => '<p>Keep</p><iframe src="https://example.com"></iframe>',
				'status'  => 'draft',
			)
		);

		$this->assertIsArray( $created );
		$this->assertArrayHasKey( 'content_modified', $created );
		$kinds = $this->removed_kinds( $created['content_modified'] );
		$this->assertArrayHasKey( 'iframe', $kinds );
		$this->assertArrayNotHasKey( 'svg_elements', $kinds );
		$message = (string) $created['content_modified']['message'];
		$this->assertStringContainsString( 'script, embed and form markup is not stored by this tool', $message );
		$this->assertStringNotContainsString( 'SVG', $message );
		$this->assertStringNotContainsString( 'unfiltered_html', $message );
	}

	public function test_page_update_reports_on_attributes_stripped(): void {
		$this->seed( 12120, 'page' );
		$pages   = new Mcpwp_Pages();
		$raw     = '<p onclick="alert(1)" class="ok">Hello</p>';
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
		$message = (string) $updated['content_modified']['message'];
		$this->assertStringContainsString( 'event-handler attributes (onclick, onload ...) are not stored', $message );
		$this->assertStringNotContainsString( 'script, embed and form markup is not stored by this tool', $message );
		$this->assertStringNotContainsString( 'unfiltered_html', $message );
	}

	public function test_reports_unsafe_url_and_style_kinds(): void {
		$this->seed( 12130, 'page' );
		$submitted = '<p style="color:red"><a href="javascript:alert(1)">x</a><img src="data:text/html,x"></p><style>.x{}</style>';
		$sanitized = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $submitted );
		$sanitized = wp_kses_post( $sanitized );
		$GLOBALS['_mcpwp_test_posts'][12130]->post_content = $sanitized;

		$report = $this->harness()->report( $submitted, 12130 );
		$kinds  = $this->removed_kinds( $report );
		$this->assertArrayHasKey( 'unsafe_url_attributes', $kinds );
		$this->assertArrayHasKey( 'style', $kinds );
		$encoded = wp_json_encode( $report );
		$this->assertStringNotContainsString( 'javascript:alert', $encoded );
		$this->assertStringNotContainsString( 'color:red', $encoded );
		$this->assertStringContainsString( 'Unsafe URL attributes were removed', (string) $report['message'] );
		$this->assertStringNotContainsString( 'unfiltered_html', (string) $report['message'] );
	}

	public function test_reports_foreignobject_math_and_form_controls(): void {
		$this->seed( 12131, 'page' );
		$submitted = '<p>A</p><math><mi>x</mi></math><svg><foreignObject width="1" height="1"><div>y</div></foreignObject></svg><form><input type="text"><button>Go</button><textarea></textarea><select><option>1</option></select></form>';
		$sanitized = wp_kses_post( $submitted );
		$GLOBALS['_mcpwp_test_posts'][12131]->post_content = $sanitized;

		$report = $this->harness()->report( $submitted, 12131 );
		$kinds  = $this->removed_kinds( $report );
		$this->assertArrayHasKey( 'math', $kinds );
		$this->assertArrayHasKey( 'foreignobject', $kinds );
		$this->assertArrayHasKey( 'form', $kinds );
		$this->assertArrayHasKey( 'input', $kinds );
		$this->assertArrayHasKey( 'button', $kinds );
		$this->assertArrayHasKey( 'textarea', $kinds );
		$this->assertArrayHasKey( 'select', $kinds );
		$message = (string) $report['message'];
		$this->assertStringContainsString( 'script, embed and form markup is not stored by this tool', $message );
		$this->assertStringContainsString( 'MathML markup is not stored by this tool', $message );
		$this->assertStringContainsString( 'form-control markup (input, button, textarea, select) is not stored by this tool', $message );
		$this->assertStringNotContainsString( 'event-handler attributes', $message );
	}

	public function test_no_false_positive_when_only_entity_or_whitespace_normalises(): void {
		$this->seed( 12124, 'page' );
		// Bare ampersand is entity-normalised by kses; inventories stay equal.
		$submitted = "<p>A & B</p>\n";
		$saved     = wp_kses_post( '<p>A & B</p>' );
		$GLOBALS['_mcpwp_test_posts'][12124]->post_content = $saved;

		$report = $this->harness()->report( $submitted, 12124 );
		$this->assertSame( array(), $report, 'Whitespace/entity-only diffs must not report content_modified.' );
	}

	public function test_identical_safe_markup_does_not_report(): void {
		$pages   = new Mcpwp_Pages();
		$raw     = '<p class="ok">Hello <strong>world</strong></p>';
		$created = $pages->create_page(
			array(
				'title'   => 'Clean',
				'content' => $raw,
				'status'  => 'draft',
			)
		);

		$this->assertIsArray( $created );
		$this->assertArrayNotHasKey( 'content_modified', $created );
	}

	public function test_large_content_skips_comparison(): void {
		$this->seed( 12125, 'page' );
		$big = str_repeat( 'a', Mcpwp_Content_Modified_Report::MAX_COMPARE_BYTES + 1 );
		$GLOBALS['_mcpwp_test_posts'][12125]->post_content = $big;

		$report = $this->harness()->report( $big, 12125 );
		$this->assertTrue( ! empty( $report['content_comparison_skipped'] ) );

		$result = array();
		$this->harness()->attach( $result, $big, 12125 );
		$this->assertArrayHasKey( 'content_modified', $result );
		$this->assertTrue( ! empty( $result['content_modified']['content_comparison_skipped'] ) );
	}

	public function test_post_update_content_modified_matches_saved_row(): void {
		$this->seed( 12121, 'post' );
		$posts   = new Mcpwp_Posts();
		$raw     = '<p>Hi</p><script>evil()</script>';
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
		$kinds = $this->removed_kinds( $updated['content_modified'] );
		$this->assertArrayHasKey( 'script', $kinds );
	}

	public function test_set_blocks_reports_from_saved_content_without_re_sanitising_untouched_markup(): void {
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
		$request->set_param( 'allow_restricted_blocks', true );
		$request->set_param( 'approval_note', '1212 reporting coverage' );
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
		$kinds = $this->removed_kinds( $data['content_modified'] );
		$this->assertArrayHasKey( 'on_attributes', $kinds );
		$this->assertStringNotContainsString( 'alert(1)', wp_json_encode( $data['content_modified'] ) );
	}

	public function test_patch_block_section_counts_blocks_from_saved_row(): void {
		// Bootstrap parse_blocks cannot emit named Gutenberg blocks, so the
		// full patch_block_section handler fails safety here. Pin the contract
		// the fix encodes: block_count must be derived from the saved row
		// (same as set_blocks), and content_modified compares pre-save vs saved.
		$this->seed( 12132, 'page' );
		$patched = '<!-- wp:paragraph --><p onclick="alert(1)">Patched</p><!-- /wp:paragraph -->';
		$saved   = wp_kses_post( $patched );
		$GLOBALS['_mcpwp_test_posts'][12132]->post_content = $saved;

		$controller = new Mcpwp_REST_Blocks();
		$clean      = new ReflectionMethod( Mcpwp_REST_Blocks::class, 'clean_blocks' );
		$clean->setAccessible( true );
		$from_saved = count( $clean->invoke( $controller, parse_blocks( (string) get_post_field( 'post_content', 12132 ) ) ) );

		$payload = array(
			'success'     => true,
			'block_count' => $from_saved,
		);
		$this->harness()->attach( $payload, $patched, 12132 );

		$src = file_get_contents( dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-blocks.php' );
		$this->assertMatchesRegularExpression(
			'/patch_block_section.*?\$saved_content\s*=\s*get_post_field\s*\(\s*[\'"]post_content[\'"]/s',
			$src,
			'patch_block_section must read saved post_content before block_count.'
		);
		$this->assertArrayHasKey( 'content_modified', $payload );
		$kinds = $this->removed_kinds( $payload['content_modified'] );
		$this->assertArrayHasKey( 'on_attributes', $kinds );
	}

	public function test_approval_apply_attaches_content_modified(): void {
		$this->seed( 12133, 'page' );
		$after = '<p>Hi</p><script>evil()</script>';
		$req   = Mcpwp_Approvals::create_post_content_request(
			12133,
			$after,
			array(
				'title'               => '1212 apply report',
				'note'                => 'test',
				'tool'                => 'wp_set_blocks',
				'requested_by_key_id' => 'k1212-req',
			)
		);
		$this->assertIsArray( $req, wp_json_encode( $req ) );
		$id = $req['id'];

		$approved = Mcpwp_Approvals::approve_request( $id, '', array( 'actor' => 'wp-user:1' ) );
		$this->assertIsArray( $approved, wp_json_encode( $approved ) );

		$applied = Mcpwp_Approvals::apply_request( $id, array( 'actor' => 'wp-user:2' ) );
		$this->assertIsArray( $applied, wp_json_encode( $applied ) );
		$this->assertArrayHasKey( 'content_modified', $applied );
		$kinds = $this->removed_kinds( $applied['content_modified'] );
		$this->assertArrayHasKey( 'script', $kinds );
		$this->assertStringNotContainsString( 'evil()', wp_json_encode( $applied['content_modified'] ) );
		$this->assertStringNotContainsString( 'unfiltered_html', (string) $applied['content_modified']['message'] );
	}

	public function test_media_description_attaches_content_modified(): void {
		$this->seed( 12134, 'attachment' );
		$GLOBALS['_mcpwp_test_posts'][12134]->post_mime_type = 'image/jpeg';
		$media   = new Mcpwp_Media();
		$updated = $media->update_media(
			12134,
			array( 'description' => '<p>Cap</p><script>x()</script>' )
		);
		$this->assertIsArray( $updated );
		$this->assertArrayHasKey( 'content_modified', $updated );
		$kinds = $this->removed_kinds( $updated['content_modified'] );
		$this->assertArrayHasKey( 'script', $kinds );
	}

	public function test_string_attach_merges_kinds(): void {
		$result = array();
		$this->harness()->attach_strings( $result, '<script>a</script>', '' );
		$this->harness()->attach_strings( $result, '<p style="x:y">b</p>', '<p>b</p>' );
		$this->assertArrayHasKey( 'content_modified', $result );
		$kinds = $this->removed_kinds( $result['content_modified'] );
		$this->assertArrayHasKey( 'script', $kinds );
		$this->assertArrayHasKey( 'style', $kinds );
	}

	public function test_helper_counts_kinds_only_never_attribute_values(): void {
		$this->seed( 12123, 'page' );
		$submitted = '<p onmouseover="x()">A</p><script>y()</script><svg onload="z()"><circle r="1"/></svg><iframe src="https://evil.example"></iframe>';
		// Mirror sanitize_content_body: strip script/style wholes, then kses.
		$sanitized = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $submitted );
		$sanitized = wp_kses_post( $sanitized );
		$GLOBALS['_mcpwp_test_posts'][12123]->post_content = $sanitized;

		$report = $this->harness()->report( $submitted, 12123 );
		$this->assertNotEmpty( $report['removed'] ?? array() );
		$encoded = wp_json_encode( $report );
		$this->assertStringNotContainsString( 'onmouseover="x()"', $encoded );
		$this->assertStringNotContainsString( 'y()', $encoded );
		$this->assertStringNotContainsString( 'evil.example', $encoded );
		$kinds = $this->removed_kinds( $report );
		$this->assertArrayHasKey( 'script', $kinds );
		$this->assertArrayHasKey( 'on_attributes', $kinds );
		$this->assertArrayHasKey( 'svg_elements', $kinds );
		$this->assertArrayHasKey( 'iframe', $kinds );
	}

	public function test_bootstrap_kses_lowercases_tag_names_like_core(): void {
		$out = wp_kses_post( '<P CLASS="x" OnClick="y()">Hi</P>' );
		$this->assertStringContainsString( '<p', $out );
		$this->assertStringNotContainsString( '<P', $out );
		$this->assertDoesNotMatchRegularExpression( '/\son[a-z]+\s*=/i', $out );
	}

	public function test_bootstrap_kses_flag_is_decided_at_set_current_user(): void {
		wp_set_current_user( self::AGENT_ID );
		$this->assertTrue( ! empty( $GLOBALS['mcpwp_test_kses_post_content'] ) );

		// Mutating caps after set_current_user must NOT flip the flag (core
		// timing: kses_init runs when the user is set).
		$GLOBALS['mcpwp_test_user_caps'][ self::AGENT_ID ] = array( 'unfiltered_html' => true );
		$this->assertTrue(
			! empty( $GLOBALS['mcpwp_test_kses_post_content'] ),
			'Flag must stay decided from the last set_current_user call.'
		);
		$this->assertTrue( current_user_can( 'unfiltered_html' ) );

		wp_set_current_user( self::AGENT_ID );
		$this->assertTrue(
			empty( $GLOBALS['mcpwp_test_kses_post_content'] ),
			'Re-setting the user refreshes the kses flag from current caps.'
		);
	}
}
