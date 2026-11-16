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
		$this->assertSame(
			Mcpwp_Content_Modified_Report::REMOVAL_MESSAGE,
			$created['content_modified']['message']
		);
		$this->assertSame( $created['content'], get_post_field( 'post_content', $created['id'] ) );
		$encoded = wp_json_encode( $created['content_modified'] );
		$this->assertStringNotContainsString( 'window.__x', $encoded );
		$this->assertStringNotContainsString( '<script>', $encoded );
		$this->assertStringNotContainsString( 'made safe', strtolower( $encoded ) );
		$this->assertStringNotContainsString( '<svg', $created['content'] );
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
}
