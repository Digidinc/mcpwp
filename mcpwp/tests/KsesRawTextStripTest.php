<?php
/**
 * Coverage for #808 — wp_kses_post() strips a `<script>`/`<style>` tag but
 * keeps its body as visible page text, and the write response says nothing
 * was removed.
 *
 * A pasted embed script survived as a paragraph of raw JavaScript on a live
 * page because MCPWP called wp_kses_post( $data['content'] ) directly at
 * every write site: kses is markup-aware, so a disallowed tag is dropped but
 * the "ordinary text" between its start and end tag is not, and for
 * `<script>`/`<style>` that text is program source, not content.
 *
 * The fix (Mcpwp_Sanitization::sanitize_content_body(), used at all four
 * pinned sites — Mcpwp_Pages::create_page()/update_page() and
 * Mcpwp_Posts::create_post()/update_post()) removes the whole element, tag
 * and body together, before kses ever runs, and reports what it removed as
 * `sanitized: [{tag, count}]` on the write response — same shape family as
 * the existing `terms`/`meta_applied`/`slug_warning` echo-back fields.
 *
 * The strip-then-kses pass is unconditional — no `unfiltered_html` bypass.
 * kses ran on these four sites for every caller before this fix regardless
 * of capability, so a bypass would newly let an MCP-driven write (every API
 * key runs as the shared mcpwp_api_agent user, which the Elementor writer
 * scopes `unfiltered_html` to during its own saves) store a raw `<script>`
 * where it could not before — a wider stored-XSS surface, not a fix.
 *
 * Round 2 (adversarial review of the first version, same issue): a
 * regex-only matcher for the open/close tags was provably unsafe — it read
 * `< style` (space before the name), `<style-guide>` (a custom element),
 * a literal "<style>" inside an HTML comment / CDATA section / a quoted
 * attribute value, all as real `<style>` tags, and then — because "no
 * matching close tag" removed to the END of the string — deleted everything
 * after a false-positive "open tag" for the rest of the document. The
 * `false_positive_inputs()` cases below are the proofs that broke it.
 * `strip_raw_text_elements()` is now a small hand-rolled tokenizer: a tag
 * name must start immediately after '<' and end at whitespace/'/'/'>', HTML
 * comments and CDATA are skipped as opaque spans, quoted attribute values are
 * skipped while looking for a tag's '>', and — the key safety property — an
 * unmatched open tag now removes ONLY that tag, never anything after it.
 * `sanitize_content_body()` loops the tokenizer to a fixed point (bounded)
 * so a reassembly split across two decoy elements (see
 * `test_reassembly_across_two_decoy_elements_leaves_no_visible_payload`)
 * cannot survive as a real element in the final output. Non-string content
 * is handled the way a bare `wp_kses_post()` call always was: scalars are
 * cast to a string first, arrays/objects go straight to `wp_kses_post()`
 * untouched (an earlier version blanked them, which is what let
 * `wp_bulk_update_pages` erase a page's content — see
 * `test_non_scalar_content_is_passed_through_like_before_the_fix`).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-events.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-learnpress.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-multilang.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-woocommerce.php';

if ( ! class_exists( 'Mcpwp_Test_WC_Product_Description_Spy' ) ) {
	/**
	 * A bare WC_Product double recording set_description()/
	 * set_short_description() calls — no WooCommerce classes needed, same
	 * pattern StagedStatusValueAuditTest.php uses for set_product_data().
	 */
	class Mcpwp_Test_WC_Product_Description_Spy { // phpcs:ignore
		/** @var array<int,string> */
		public $descriptions = array();
		/** @var array<int,string> */
		public $short_descriptions = array();

		public function set_description( $value ) {
			$this->descriptions[] = $value;
		}

		public function set_short_description( $value ) {
			$this->short_descriptions[] = $value;
		}
	}
}

if ( ! class_exists( 'Mcpwp_Test_Sanitization_Harness' ) ) {
	/**
	 * A bare object using the trait directly, for asserting
	 * sanitize_content_body()'s own contract in isolation from the
	 * post/page write pipeline. An array or object 'content' value breaks
	 * unrelated formatting code (e.g. Mcpwp_Pages::format_page()'s word-count
	 * calculation via wp_strip_all_tags()) on both main and this branch —
	 * that is pre-existing and out of scope here; this harness isolates the
	 * one thing #808's fix actually changed: what sanitize_content_body()
	 * itself does with a non-string value.
	 */
	class Mcpwp_Test_Sanitization_Harness { // phpcs:ignore
		use Mcpwp_Sanitization;

		public function sanitize( $content ) {
			return $this->sanitize_content_body( $content );
		}
	}
}

if ( ! function_exists( 'wp_get_attachment_metadata' ) ) {
	/**
	 * Nothing in this file's tests exercises real attachment metadata —
	 * Mcpwp_Media::format_attachment() only needs this to exist and return
	 * an array so it does not fatal on a bare mock attachment post.
	 */
	function wp_get_attachment_metadata( $attachment_id ) { // phpcs:ignore
		return array();
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) { // phpcs:ignore
		return 'https://example.com/wp-content/uploads/mock-' . (int) $attachment_id . '.jpg';
	}
}

final class KsesRawTextStripTest extends TestCase {

	/** A user id deliberately configured to lack `unfiltered_html` — the
	 * ordinary case for an API-key-driven agent, and the one #808 was filed
	 * against (staging15.stemminds.com writing through wp_update_page). */
	private const AGENT_ID = 9001;

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
		wp_set_current_user( self::AGENT_ID );
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_posts']        = array();
		$GLOBALS['_mcpwp_test_meta']         = array();
		$GLOBALS['_mcpwp_test_object_terms'] = array();
		$GLOBALS['_mcpwp_test_taxonomies']   = array();
		$GLOBALS['mcpwp_test_user_caps']     = array();
		wp_set_current_user( 0 );
	}

	private function seed( int $id, string $type ): void {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
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
		);
	}

	/**
	 * Run one of the four pinned write sites and return its result.
	 *
	 * @return array|WP_Error
	 */
	private function write_content( string $surface, $content, int $id ) {
		switch ( $surface ) {
			case 'create_page':
				return ( new Mcpwp_Pages() )->create_page( array( 'title' => 'New page', 'content' => $content ) );
			case 'update_page':
				$this->seed( $id, 'page' );
				return ( new Mcpwp_Pages() )->update_page( $id, array( 'content' => $content ) );
			case 'create_post':
				return ( new Mcpwp_Posts() )->create_post( array( 'title' => 'New post', 'post_type' => 'post', 'content' => $content ) );
			case 'update_post':
				$this->seed( $id, 'post' );
				return ( new Mcpwp_Posts() )->update_post( $id, array( 'content' => $content ) );
			default:
				throw new InvalidArgumentException( $surface );
		}
	}

	public function surfaces(): array {
		return array(
			'create page' => array( 'create_page' ),
			'update page' => array( 'update_page' ),
			'create post' => array( 'create_post' ),
			'update post' => array( 'update_post' ),
		);
	}

	/**
	 * Reduce a `sanitized` report to a tag => count map so assertions do not
	 * depend on array order.
	 */
	private function removed_map( array $result ): array {
		$map = array();
		foreach ( $result['sanitized'] ?? array() as $entry ) {
			$map[ $entry['tag'] ] = $entry['count'];
		}
		return $map;
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_script_body_is_not_in_saved_content( string $surface ): void {
		$result = $this->write_content( $surface, '<p>Keep me</p><script>alert(document.cookie)</script>', 90 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringContainsString( '<p>Keep me</p>', $result['content'] );
		$this->assertStringNotContainsString( 'alert(document.cookie)', $result['content'], 'The script body must not leak as visible text.' );
		$this->assertStringNotContainsString( '<script', strtolower( $result['content'] ) );
		$this->assertSame( array( 'script' => 1 ), $this->removed_map( $result ) );
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_style_body_is_not_in_saved_content( string $surface ): void {
		$result = $this->write_content( $surface, '<p>Keep me</p><style>body{background:url(javascript:alert(1))}</style>', 91 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringContainsString( '<p>Keep me</p>', $result['content'] );
		$this->assertStringNotContainsString( 'background:url', $result['content'], 'The style body must not leak as visible text.' );
		$this->assertStringNotContainsString( '<style', strtolower( $result['content'] ) );
		$this->assertSame( array( 'style' => 1 ), $this->removed_map( $result ) );
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_response_reports_both_removed_elements_with_tag_and_count( string $surface ): void {
		$content = '<script>a()</script><p>Middle</p><style>.x{}</style><script>b()</script>';
		$result  = $this->write_content( $surface, $content, 92 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame(
			array(
				'script' => 2,
				'style'  => 1,
			),
			$this->removed_map( $result )
		);
		$this->assertStringContainsString( '<p>Middle</p>', $result['content'] );
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_benign_html_is_left_untouched( string $surface ): void {
		$content = '<p>Hello <strong>world</strong></p><ul><li>one</li><li>two</li></ul>';
		$result  = $this->write_content( $surface, $content, 93 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $content, $result['content'] );
		$this->assertArrayNotHasKey( 'sanitized', $result, 'Nothing was removed; the field should not appear at all.' );
	}

	public function test_an_unclosed_script_tag_removes_only_the_opening_tag(): void {
		// Adversarial review finding: removing "to end of string" for an
		// unclosed tag is exactly what let a naive open-with-no-matching-close
		// case eat the REST of a real document whenever the "close tag" the
		// first version thought it found was actually a false positive
		// (a comment, a quoted attribute, text that merely looks like a tag).
		// The safe, required behaviour is narrower: with no matching close
		// tag anywhere, only the opening tag itself is removed, and kses
		// handles whatever text follows exactly as it always did — nothing
		// new is deleted on the strength of a guess.
		//
		// Kept as a single explicit call (not the dataProvider loop above) so
		// this narrower behaviour is easy to read directly.
		$result = $this->write_content( 'create_page', '<p>Before</p><script>doStuff(); // no closing tag', 94 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '<p>Before</p>doStuff(); // no closing tag', $result['content'] );
		$this->assertSame( array( 'script' => 1 ), $this->removed_map( $result ) );
	}

	public function test_an_unclosed_script_tag_removes_only_the_opening_tag_on_every_surface(): void {
		foreach ( $this->surfaces() as $surface ) {
			$result = $this->write_content( $surface[0], '<p>Before</p><script>doStuff(); // no closing tag', 94 );

			$this->assertIsArray( $result, $surface[0] . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			$this->assertSame( '<p>Before</p>doStuff(); // no closing tag', $result['content'], $surface[0] );
			$this->assertSame( array( 'script' => 1 ), $this->removed_map( $result ), $surface[0] );
		}
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_uppercase_tags_are_stripped( string $surface ): void {
		$result = $this->write_content( $surface, '<p>A</p><SCRIPT TYPE="text/javascript">alert(1)</SCRIPT><p>B</p>', 95 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '<p>A</p><p>B</p>', $result['content'] );
		$this->assertSame( array( 'script' => 1 ), $this->removed_map( $result ) );
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_a_closing_tag_with_extra_whitespace_or_attributes_is_still_matched( string $surface ): void {
		$result = $this->write_content( $surface, '<p>A</p><script>alert(1)</script  ><p>B</p>', 96 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '<p>A</p><p>B</p>', $result['content'] );
		$this->assertSame( array( 'script' => 1 ), $this->removed_map( $result ) );
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_a_user_with_unfiltered_html_still_gets_script_bodies_stripped( string $surface ): void {
		// Every API key runs as the shared mcpwp_api_agent user, the chat
		// auth bridge runs as a real admin, and the Elementor writer grants
		// that user a scoped unfiltered_html during its own saves — so this
		// capability is reachable on these write paths in practice, not a
		// hypothetical. kses ran unconditionally here before #808's fix and
		// must keep doing so for everyone, or this pinned-content-body path
		// becomes a wider stored-XSS surface than it was.
		$GLOBALS['mcpwp_test_user_caps'][ self::AGENT_ID ] = array( 'unfiltered_html' => true );

		$content = '<p>Keep</p><script>alert(1)</script>';
		$result  = $this->write_content( $surface, $content, 97 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '<p>Keep</p>', $result['content'], 'unfiltered_html does not exempt this path from stripping.' );
		$this->assertStringNotContainsString( 'alert(1)', $result['content'] );
		$this->assertSame( array( 'script' => 1 ), $this->removed_map( $result ) );
	}

	public function test_rest_layer_surfaces_the_sanitized_field_on_create_and_update(): void {
		$create_request = new WP_REST_Request( 'POST', '/mcpwp/v1/pages', array(
			'title'   => 'Landing',
			'content' => '<p>Hi</p><script>alert(1)</script>',
		) );
		$create_response = ( new Mcpwp_REST_Pages() )->create_page( $create_request );
		$created         = $create_response->get_data();

		$this->assertSame( array( array( 'tag' => 'script', 'count' => 1 ) ), $created['sanitized'] );
		$this->assertStringNotContainsString( 'alert(1)', $created['content'] );

		$this->seed( 200, 'post' );
		$update_request = new WP_REST_Request( 'PUT', '/mcpwp/v1/posts/200', array(
			'id'      => 200,
			'content' => '<p>Hi</p><style>a{color:red}</style>',
		) );
		$update_response = ( new Mcpwp_REST_Posts() )->update_post( $update_request );
		$updated         = $update_response->get_data();

		$this->assertSame( array( array( 'tag' => 'style', 'count' => 1 ) ), $updated['sanitized'] );
		$this->assertStringNotContainsString( 'color:red', $updated['content'] );
	}

	// ---------------------------------------------------------------
	// Round 2 — adversarial review: proven false-positive inputs that made
	// the regex-only matcher eat real page content. Each case pins the exact
	// input that broke it and the rest of the document that must survive.
	// ---------------------------------------------------------------

	public function false_positive_inputs(): array {
		return array(
			'a real but unclosed script tag mid-document keeps everything after it' => array(
				'<p>Add <code><script async src="x.js"></code> to your header.</p><h2>Step 2</h2><p>REST OF TUTORIAL</p>',
				'<p>Add <code></code> to your header.</p><h2>Step 2</h2><p>REST OF TUTORIAL</p>',
			),
			'a literal tag inside an HTML comment is not a real tag' => array(
				'<p>A</p><!-- <style> --><p>IMPORTANT BODY</p>',
				'<p>A</p><!-- <style> --><p>IMPORTANT BODY</p>',
			),
			'a space between < and the name is not a tag start' => array(
				'<p>Use < style tags sparingly.</p><p>REST OF PAGE</p>',
				'<p>Use < style tags sparingly.</p><p>REST OF PAGE</p>',
			),
			'a literal tag inside a quoted attribute value is not a real tag' => array(
				'<p title="<style>">A</p><p>REST</p>',
				'<p title="<style>">A</p><p>REST</p>',
			),
			'a literal tag inside a CDATA section is not a real tag' => array(
				'<![CDATA[<script>]]><p>REST</p>',
				'<![CDATA[<script>]]><p>REST</p>',
			),
		);
	}

	/**
	 * @dataProvider false_positive_inputs
	 */
	public function test_false_positive_inputs_preserve_the_rest_of_the_page( string $input, string $expected ): void {
		$result = $this->write_content( 'create_page', $input, 300 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $expected, $result['content'] );
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_custom_elements_keep_their_text( string $surface ): void {
		$content = '<style-guide>hello</style-guide> and <script-loader>hi</script-loader>';

		$result = $this->write_content( $surface, $content, 301 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $content, $result['content'], 'A custom element is not script/style — its text must survive byte-for-byte.' );
		$this->assertArrayNotHasKey( 'sanitized', $result );
	}

	public function test_reassembly_across_two_decoy_elements_leaves_no_visible_payload(): void {
		// Two harmless-looking, individually-closed <script> "decoys" whose
		// leftover fragments — once each decoy is correctly removed on its
		// own — reassemble into a genuine <script>alert(1)</script>. A
		// single strip pass is not enough; sanitize_content_body() must loop.
		$content = '<scr<script>x</script>ipt>alert(1)</scr<script></script>ipt>';

		$result = $this->write_content( 'create_page', $content, 302 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertStringNotContainsString( 'alert(1)', $result['content'] );
		$this->assertSame( '', $result['content'], 'Every fragment of the decoys and the reassembled tag is gone.' );
	}

	/**
	 * @dataProvider surfaces
	 */
	public function test_numeric_content_is_preserved_not_blanked( string $surface ): void {
		// #808 round-2 WARN: turning any non-string into '' is what let a
		// bulk payload carrying a numeric content value blank a page. A
		// scalar is cast to a string, exactly what wp_kses_post() did with
		// it before this fix (implicit coercion), not discarded.
		$result = $this->write_content( $surface, 12345, 303 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '12345', $result['content'] );
		$this->assertArrayNotHasKey( 'sanitized', $result );
	}

	public function test_non_scalar_content_is_passed_through_like_before_the_fix(): void {
		// An array/object was never routed through any string-shaped logic
		// even before #808's fix — the call site was a bare
		// wp_kses_post( $data['content'] ). This asserts sanitize_content_body()
		// still hands it straight to wp_kses_post() rather than blanking it
		// (a prior version of this fix turned any non-string into '', which
		// is what let a bulk payload blank a page — see the class docblock).
		$content = array( 'unexpected' => 'shape' );
		$harness = new Mcpwp_Test_Sanitization_Harness();

		$result = $harness->sanitize( $content );

		$this->assertSame( wp_kses_post( $content ), $result['content'] );
		$this->assertSame( array(), $result['removed'] );

		$object = (object) array( 'unexpected' => 'shape' );
		$object_result = $harness->sanitize( $object );
		$this->assertSame( wp_kses_post( $object ), $object_result['content'] );
	}

	// ---------------------------------------------------------------
	// Round 2 — WARN: surfaces that still called plain wp_kses_post() on a
	// content body, now routed through the same strip-then-kses chokepoint.
	// ---------------------------------------------------------------

	public function test_media_description_is_routed_through_the_stripper(): void {
		$GLOBALS['_mcpwp_test_posts'][400] = (object) array(
			'ID'           => 400,
			'post_type'    => 'attachment',
			'post_title'   => 'Photo',
			'post_name'    => 'photo',
			'post_status'  => 'inherit',
			'post_content' => '',
			'post_excerpt' => '',
			'post_mime_type' => 'image/jpeg',
			'post_parent'  => 0,
			'post_author'  => 1,
			'post_date'    => '2026-06-06 00:00:00',
			'post_modified' => '2026-06-06 00:00:00',
			'menu_order'   => 0,
		);

		$result = ( new Mcpwp_Media() )->update_media( 400, array(
			'description' => '<p>Keep</p><script>alert(1)</script>',
		) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '<p>Keep</p>', $GLOBALS['_mcpwp_test_posts'][400]->post_content );
		$this->assertSame( array( array( 'tag' => 'script', 'count' => 1 ) ), $result['sanitized'] );
	}

	public function test_events_create_and_update_are_routed_through_the_stripper(): void {
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'tp_event' );

		$events = new Mcpwp_Events();

		$created = $events->create_event( array(
			'title'   => 'Launch Party',
			'content' => '<p>Details</p><script>alert(1)</script>',
		) );
		$this->assertIsArray( $created, is_wp_error( $created ) ? $created->get_error_message() : '' );
		$this->assertStringNotContainsString( 'alert(1)', $created['content'] );
		$this->assertSame( array( array( 'tag' => 'script', 'count' => 1 ) ), $created['sanitized'] );

		$this->seed( 401, 'tp_event' );
		$updated = $events->update_event( 401, array(
			'content' => '<p>Updated</p><style>a{color:red}</style>',
		) );
		$this->assertIsArray( $updated, is_wp_error( $updated ) ? $updated->get_error_message() : '' );
		$this->assertStringNotContainsString( 'color:red', $updated['content'] );
		$this->assertSame( array( array( 'tag' => 'style', 'count' => 1 ) ), $updated['sanitized'] );
	}

	public function test_learnpress_course_lesson_and_quiz_are_routed_through_the_stripper(): void {
		$GLOBALS['mcpwp_test_public_post_types'] = array( 'lp_course' );

		$lp = new Mcpwp_LearnPress();

		$course = $lp->create_course( array(
			'title'   => 'Intro',
			'content' => '<p>Syllabus</p><script>alert(1)</script>',
		) );
		$this->assertIsArray( $course, is_wp_error( $course ) ? $course->get_error_message() : '' );
		$this->assertStringNotContainsString( 'alert(1)', $course['content'] );

		$this->seed( 402, 'lp_lesson' );
		$lesson = $lp->update_lesson( 402, array(
			'content' => '<p>Lesson body</p><style>a{color:red}</style>',
		) );
		$this->assertIsArray( $lesson, is_wp_error( $lesson ) ? $lesson->get_error_message() : '' );
		$this->assertStringNotContainsString( 'color:red', $lesson['content'] );

		$quiz = $lp->create_quiz( array(
			'title'   => 'Quiz 1',
			'content' => '<p>Quiz body</p><script>evil()</script>',
		) );
		$this->assertIsArray( $quiz, is_wp_error( $quiz ) ? $quiz->get_error_message() : '' );
		$this->assertStringNotContainsString( 'evil()', $quiz['content'] );
	}

	public function test_woocommerce_product_description_is_routed_through_the_stripper(): void {
		$product = new Mcpwp_Test_WC_Product_Description_Spy();

		$method = new ReflectionMethod( Mcpwp_WooCommerce::class, 'set_product_data' );
		$method->setAccessible( true );
		$method->invoke( new Mcpwp_WooCommerce(), $product, array(
			'description'       => '<p>Long</p><script>alert(1)</script>',
			'short_description'  => '<p>Short</p><style>a{color:red}</style>',
		) );

		$this->assertSame( array( '<p>Long</p>' ), $product->descriptions );
		$this->assertSame( array( '<p>Short</p>' ), $product->short_descriptions );
	}

	public function test_multilang_translations_are_routed_through_the_stripper(): void {
		$this->seed( 403, 'post' );

		$multilang = new Mcpwp_Multilang();

		$wpml = new ReflectionMethod( Mcpwp_Multilang::class, 'create_wpml_translation' );
		$wpml->setAccessible( true );
		$wpml_id = $wpml->invoke( $multilang, 403, 'fr', array(
			'content' => '<p>Bonjour</p><script>alert(1)</script>',
		) );
		$this->assertIsInt( $wpml_id, is_wp_error( $wpml_id ) ? $wpml_id->get_error_message() : '' );
		$this->assertStringNotContainsString( 'alert(1)', $GLOBALS['_mcpwp_test_posts'][ $wpml_id ]->post_content );

		$polylang = new ReflectionMethod( Mcpwp_Multilang::class, 'create_polylang_translation' );
		$polylang->setAccessible( true );
		$polylang_id = $polylang->invoke( $multilang, 403, 'de', array(
			'content' => '<p>Hallo</p><style>a{color:red}</style>',
		) );
		$this->assertIsInt( $polylang_id, is_wp_error( $polylang_id ) ? $polylang_id->get_error_message() : '' );
		$this->assertStringNotContainsString( 'color:red', $GLOBALS['_mcpwp_test_posts'][ $polylang_id ]->post_content );
	}
}
