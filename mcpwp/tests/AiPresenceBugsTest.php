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
		$GLOBALS['mcpwp_test_filters']    = array();
		unset( $GLOBALS['mcpwp_test_home_path'] );
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

	/**
	 * #937 adversarial review WARN-4: Yoast/Rank Math ship llms.txt OFF by
	 * default (verified against Yoast 28.5 / Rank Math 1.0.279 source),
	 * so being merely ACTIVE must never be treated as evidence on its own.
	 */
	public function test_does_not_defer_to_yoast_when_active_but_its_llms_txt_feature_is_off(): void {
		$this->force_seo_plugin( 'yoast' );
		update_option( 'wpseo', array( 'enable_llms_txt' => false ) );

		$presence = new Mcpwp_AI_Presence();
		$this->assertTrue( $presence->should_serve_own_llms_txt(), 'Yoast active with its own feature off must not shadow MCPWP\'s llms.txt' );
	}

	public function test_defers_to_yoast_when_its_llms_txt_feature_is_on(): void {
		$this->force_seo_plugin( 'yoast' );
		update_option( 'wpseo', array( 'enable_llms_txt' => true ) );

		$presence = new Mcpwp_AI_Presence();
		$this->assertFalse( $presence->should_serve_own_llms_txt() );
	}

	public function test_does_not_defer_to_rankmath_when_active_but_its_llms_txt_module_is_inactive(): void {
		$this->force_seo_plugin( 'rankmath' );
		update_option( 'rank_math_modules', array( 'sitemap', 'seo-analysis' ) );

		$presence = new Mcpwp_AI_Presence();
		$this->assertTrue( $presence->should_serve_own_llms_txt(), 'Rank Math active without the llms-txt module must not shadow MCPWP\'s llms.txt' );
	}

	public function test_defers_to_rankmath_when_its_llms_txt_module_is_active(): void {
		$this->force_seo_plugin( 'rankmath' );
		update_option( 'rank_math_modules', array( 'sitemap', 'llms-txt' ) );

		$presence = new Mcpwp_AI_Presence();
		$this->assertFalse( $presence->should_serve_own_llms_txt() );
	}

	public function test_defers_when_a_physical_llms_txt_file_exists_regardless_of_any_flag(): void {
		$path = rtrim( ABSPATH, '/' ) . '/llms.txt';
		file_put_contents( $path, "# someone else's llms.txt\n" );
		try {
			$presence = new Mcpwp_AI_Presence();
			$this->assertFalse( $presence->should_serve_own_llms_txt() );
		} finally {
			unlink( $path );
		}
	}

	/**
	 * #937 NOTE-3: the physical-file check must use get_home_path() — the
	 * exact path Yoast's WordPress_File_System_Adapter writes to — not
	 * ABSPATH. They differ whenever WordPress lives in a subdirectory below
	 * the site's home path. Proven here by pointing get_home_path() at a
	 * DIFFERENT directory than ABSPATH: a file dropped in the home path is
	 * detected, and a file dropped in ABSPATH (where get_home_path() is NOT
	 * pointed) is not.
	 */
	public function test_physical_llms_txt_check_uses_get_home_path_not_abspath(): void {
		$home_dir = rtrim( sys_get_temp_dir(), '/' ) . '/mcpwp-test-home-' . uniqid();
		mkdir( $home_dir );
		$GLOBALS['mcpwp_test_home_path'] = $home_dir;

		try {
			$presence = new Mcpwp_AI_Presence();
			$this->assertTrue( $presence->should_serve_own_llms_txt(), 'premise: nothing at the home path yet' );

			// A file at ABSPATH (NOT the configured home path) must be ignored.
			$abspath_file = rtrim( ABSPATH, '/' ) . '/llms.txt';
			file_put_contents( $abspath_file, "# not at the home path\n" );
			try {
				$this->assertTrue( $presence->should_serve_own_llms_txt(), 'a file at ABSPATH must not count when get_home_path() points elsewhere' );
			} finally {
				unlink( $abspath_file );
			}

			// A file at the actual home path must be detected.
			file_put_contents( $home_dir . '/llms.txt', "# at the home path\n" );
			$this->assertFalse( $presence->should_serve_own_llms_txt(), 'a file at get_home_path() must be detected' );
		} finally {
			@unlink( $home_dir . '/llms.txt' );
			@rmdir( $home_dir );
		}
	}

	public function test_force_llms_txt_setting_overrides_deference(): void {
		$this->force_seo_plugin( 'yoast' );
		update_option( 'wpseo', array( 'enable_llms_txt' => true ) );
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
