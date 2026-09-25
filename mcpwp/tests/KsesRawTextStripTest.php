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
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

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
	private function write_content( string $surface, string $content, int $id ) {
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

	public function test_an_unclosed_script_tag_does_not_leak_its_body(): void {
		// Kept as a single explicit call (not the dataProvider loop above) so
		// the "to end of string" behaviour is easy to read directly.
		$result = $this->write_content( 'create_page', '<p>Before</p><script>doStuff(); // no closing tag', 94 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '<p>Before</p>', $result['content'] );
		$this->assertSame( array( 'script' => 1 ), $this->removed_map( $result ) );
	}

	public function test_an_unclosed_script_tag_is_stripped_on_every_surface(): void {
		foreach ( $this->surfaces() as $surface ) {
			$result = $this->write_content( $surface[0], '<p>Before</p><script>doStuff(); // no closing tag', 94 );

			$this->assertIsArray( $result, $surface[0] . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
			$this->assertSame( '<p>Before</p>', $result['content'], $surface[0] );
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
	public function test_a_user_with_unfiltered_html_is_not_regressed( string $surface ): void {
		$GLOBALS['mcpwp_test_user_caps'][ self::AGENT_ID ] = array( 'unfiltered_html' => true );

		$content = '<p>Keep</p><script>alert(1)</script>';
		$result  = $this->write_content( $surface, $content, 97 );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $content, $result['content'], 'A user with unfiltered_html is not put through the new strip step.' );
		$this->assertArrayNotHasKey( 'sanitized', $result );
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
}
