<?php

use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the three ai-presence bugs fixed alongside the AI
 * crawler policy / IndexNow feature:
 *
 *   1. /llms.txt served unconditionally, shadowing an active SEO plugin's
 *      own llms.txt.
 *   2. robots.txt always advertised /wp-sitemap.xml, even when the core
 *      sitemap is not actually serving.
 *   3. llms.txt body was run through esc_html(), HTML-entity-corrupting a
 *      text/plain markdown file.
 *
 * The serving methods themselves (maybe_serve_llms_txt()) call exit() on a
 * match and are intentionally not invoked here — see BrokerWellKnownTest's
 * docblock for the same rule. Every pure builder they call IS covered.
 */
final class AiPresenceBugsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']   = array();
		$GLOBALS['mcpwp_test_filters']   = array();
		$GLOBALS['_mcpwp_test_posts']    = array();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	private function force_seo_plugin( $slug ) {
		add_filter( 'mcpwp_seo_plugin_active_override', function () use ( $slug ) {
			return $slug;
		} );
	}

	// -- Bug 1: llms.txt deference -----------------------------------------

	public function test_serves_own_llms_txt_when_no_seo_plugin_active(): void {
		$presence = new Mcpwp_AI_Presence();
		$this->assertTrue( $presence->should_serve_own_llms_txt() );
	}

	public function test_defers_to_yoast_llms_txt_by_default(): void {
		$this->force_seo_plugin( 'yoast' );
		$presence = new Mcpwp_AI_Presence();
		$this->assertFalse( $presence->should_serve_own_llms_txt() );
	}

	public function test_defers_to_rankmath_llms_txt_by_default(): void {
		$this->force_seo_plugin( 'rankmath' );
		$presence = new Mcpwp_AI_Presence();
		$this->assertFalse( $presence->should_serve_own_llms_txt() );
	}

	public function test_force_llms_txt_setting_overrides_deference(): void {
		$this->force_seo_plugin( 'yoast' );
		update_option( Mcpwp_AI_Presence::SETTINGS_OPTION, array( 'force_llms_txt' => true ) );

		$presence = new Mcpwp_AI_Presence();
		$this->assertTrue( $presence->should_serve_own_llms_txt() );
	}

	// -- Bug 2: conditional Sitemap: line -----------------------------------

	public function test_robots_txt_advertises_sitemap_when_core_sitemaps_active(): void {
		$presence = new Mcpwp_AI_Presence();
		$output   = $presence->filter_robots_txt( '', true );

		$this->assertStringContainsString( 'Sitemap: https://example.com/wp-sitemap.xml', $output );
	}

	public function test_robots_txt_omits_sitemap_when_core_sitemaps_disabled_by_filter(): void {
		add_filter( 'wp_sitemaps_enabled', function () {
			return false;
		} );

		$presence = new Mcpwp_AI_Presence();
		$output   = $presence->filter_robots_txt( '', true );

		$this->assertStringNotContainsStringIgnoringCase( 'wp-sitemap.xml', $output );
	}

	public function test_robots_txt_omits_own_sitemap_when_seo_plugin_active(): void {
		// An active SEO plugin disables the core sitemap and serves its own
		// (at a different URL) — advertising /wp-sitemap.xml here would be a
		// Sitemap: line that 404s or redirects.
		$this->force_seo_plugin( 'yoast' );

		$presence = new Mcpwp_AI_Presence();
		$output   = $presence->filter_robots_txt( '', true );

		$this->assertStringNotContainsStringIgnoringCase( 'wp-sitemap.xml', $output );
	}

	public function test_robots_txt_never_duplicates_an_existing_sitemap_line(): void {
		$presence = new Mcpwp_AI_Presence();
		$existing = "User-agent: *\nSitemap: https://example.com/custom-sitemap.xml\n";

		$output = $presence->filter_robots_txt( $existing, true );

		$this->assertSame( 1, substr_count( strtolower( $output ), 'sitemap:' ) );
		$this->assertStringContainsString( 'custom-sitemap.xml', $output );
	}

	// -- Bug 3: plain-text body, no HTML entity corruption ------------------

	public function test_llms_txt_body_does_not_html_escape_ampersands(): void {
		update_option( 'mcpwp_site_context', 'Ben & Jerry, "quoted" and <b>bold</b>' );

		$presence = new Mcpwp_AI_Presence();
		$body     = $presence->generate_llms_txt();

		$this->assertStringContainsString( 'Ben & Jerry, "quoted" and bold', $body );
		$this->assertStringNotContainsString( '&amp;', $body );
		$this->assertStringNotContainsString( '&lt;', $body );
		$this->assertStringNotContainsString( '<b>', $body );
	}

	public function test_llms_txt_decodes_entities_left_by_wptexturize(): void {
		$this->add_page( 1, 'Rock &#8217;n&#8217; Roll' );
		update_option( 'page_on_front', 0 );

		$presence = new Mcpwp_AI_Presence();
		$body     = $presence->generate_llms_txt();

		$this->assertStringContainsString( "Rock \xe2\x80\x99n\xe2\x80\x99 Roll", $body );
		$this->assertStringNotContainsString( '&#8217;', $body );
	}

	public function test_llms_txt_strips_crlf_from_a_page_title_to_prevent_line_injection(): void {
		$this->add_page( 1, "Legit Title\r\n## Fake Section\r\nInjected line" );

		$presence = new Mcpwp_AI_Presence();
		$body     = $presence->generate_llms_txt();

		// The whole malicious value collapses onto one line: no bare CR or
		// LF anywhere inside it that could start a new "line" in the file.
		$this->assertStringContainsString( 'Legit Title ## Fake Section Injected line', $body );
	}

	public function test_llms_txt_sitemap_line_is_conditional_like_robots(): void {
		$this->force_seo_plugin( 'yoast' );

		$presence = new Mcpwp_AI_Presence();
		$body     = $presence->generate_llms_txt();

		$this->assertStringNotContainsString( 'wp-sitemap.xml', $body );
	}

	/**
	 * Register a published page for get_important_urls() to pick up.
	 *
	 * @param int    $id    Post ID.
	 * @param string $title Post title.
	 * @return void
	 */
	private function add_page( $id, $title ) {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = (object) array(
			'ID'          => $id,
			'post_type'   => 'page',
			'post_title'  => $title,
			'post_status' => 'publish',
			'menu_order'  => 0,
		);
	}
}
