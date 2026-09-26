<?php

use PHPUnit\Framework\TestCase;

/**
 * #903: names/titles returned to agents were HTML-entity encoded
 * ("Crumb &amp; Co") because WordPress stores blogname/post_title with
 * entities already encoded — correct for a theme echoing the value straight
 * into markup, wrong for an AI agent reading plain text. These tests pin
 * that the MCPWP surfaces named in the issue (serverInfo.name, page/post
 * titles in tool results, site-info/introspect/onboard identity) decode for
 * agents, and that decoding never touches an HTML body (post content),
 * where "&amp;" can legitimately be literal text an author wrote.
 */
final class AgentTextDecodeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']              = array();
		$GLOBALS['mcpwp_test_transients']            = array();
		$GLOBALS['_mcpwp_test_posts']                = array();
		$GLOBALS['_mcpwp_test_meta']                 = array();
		$GLOBALS['mcpwp_test_bloginfo_name']         = 'Example Site';
		$GLOBALS['mcpwp_test_bloginfo_description']  = '';
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_bloginfo_name'], $GLOBALS['mcpwp_test_bloginfo_description'] );
	}

	public function test_decode_helper_handles_ampersand_apostrophe_and_non_strings(): void {
		$this->assertSame( "Crumb & Co", Mcpwp_Agent_Text::decode( 'Crumb &amp; Co' ) );
		$this->assertSame( "Kids' Menu & Drinks", Mcpwp_Agent_Text::decode( 'Kids&#39; Menu &amp; Drinks' ) );
		// Non-strings and empty strings pass through unchanged.
		$this->assertNull( Mcpwp_Agent_Text::decode( null ) );
		$this->assertSame( 42, Mcpwp_Agent_Text::decode( 42 ) );
		$this->assertSame( '', Mcpwp_Agent_Text::decode( '' ) );
		// A body deliberately containing literal "&amp;" text is out of
		// scope for this helper — callers must never invoke it on HTML
		// bodies, only on isolated title/name fields (documented, not
		// enforced by the helper itself).
	}

	/**
	 * Mcpwp_Core::get_site_info() also calls wp_get_theme()/wp_timezone_string()/
	 * get_locale(), which the shared bootstrap does not stub (same gap
	 * ResponseProjectionTest/OnboardingFigmaTierTest/AtomicModeGuidanceTest
	 * already work around). Declared only inside this @runInSeparateProcess
	 * test so the stub cannot leak into the shared run.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_site_info_name_and_description_decoded_for_agents(): void {
		if ( ! function_exists( 'wp_timezone_string' ) ) {
			function wp_timezone_string() {
				return 'UTC';
			}
		}
		if ( ! function_exists( 'get_locale' ) ) {
			function get_locale() {
				return 'en_US';
			}
		}
		if ( ! function_exists( 'wp_get_theme' ) ) {
			function wp_get_theme() {
				return new class() {
					public function get( $header ) {
						return 'Stub Theme';
					}
				};
			}
		}

		$GLOBALS['mcpwp_test_bloginfo_name']        = 'Crumb &amp; Co';
		$GLOBALS['mcpwp_test_bloginfo_description'] = 'Fresh bread &#8212; daily';

		$info = ( new Mcpwp_Core() )->get_site_info();

		$this->assertSame( 'Crumb & Co', $info['name'] );
		$this->assertSame( 'Fresh bread — daily', $info['description'] );
	}

	public function test_mcp_server_info_name_decoded(): void {
		$GLOBALS['mcpwp_test_bloginfo_name'] = 'Crumb &amp; Co';

		$controller = new Mcpwp_REST_MCP();
		$response   = $controller->handle_mcp(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/mcp',
				array(),
				array(),
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'initialize',
					'params'  => array(
						'clientInfo' => array( 'name' => 'phpunit', 'version' => '1.0' ),
					),
				)
			)
		);

		$name = $response->get_data()['result']['serverInfo']['name'];

		$this->assertSame( 'mcpwp:Crumb & Co', $name );
		// Never double-decoded into something the site owner didn't type.
		$this->assertStringNotContainsString( '&amp;', $name );
	}

	public function test_page_title_decoded_in_list_and_get_but_content_untouched(): void {
		$GLOBALS['_mcpwp_test_posts'][1] = (object) array(
			'ID'             => 1,
			'post_type'      => 'page',
			'post_title'     => 'Kids&#39; Menu &amp; Drinks',
			'post_name'      => 'kids-menu',
			'post_status'    => 'publish',
			'post_date'      => '2026-06-01 00:00:00',
			'post_date_gmt'  => '2026-06-01 00:00:00',
			'post_modified'  => '2026-06-02 00:00:00',
			'post_parent'    => 0,
			'menu_order'     => 0,
			'post_author'    => 1,
			// A body that legitimately contains the literal text "&amp;" —
			// must survive unchanged, never decoded into a literal "&".
			'post_content'   => 'Terms: rock &amp; roll night every Friday.',
			'post_excerpt'   => '',
		);

		$pages = new Mcpwp_Pages();

		$list = $pages->list_pages();
		$this->assertSame( "Kids' Menu & Drinks", $list['pages'][0]['title'] );

		$single = $pages->get_page( 1 );
		$this->assertSame( "Kids' Menu & Drinks", $single['title'] );
		$this->assertSame( 'Terms: rock &amp; roll night every Friday.', $single['content'] );
	}

	public function test_post_title_decoded_in_list_and_get(): void {
		$GLOBALS['_mcpwp_test_posts'][1] = (object) array(
			'ID'            => 1,
			'post_type'     => 'post',
			'post_title'    => 'Crumb &amp; Co opens downtown',
			'post_name'     => 'crumb-and-co-opens',
			'post_status'   => 'publish',
			'post_date'     => '2026-06-01 00:00:00',
			'post_date_gmt' => '2026-06-01 00:00:00',
			'post_modified' => '2026-06-02 00:00:00',
			'post_modified_gmt' => '2026-06-02 00:00:00',
			'post_author'   => 1,
			'post_content'  => 'Body text.',
			'post_excerpt'  => '',
		);

		$posts = new Mcpwp_Posts();

		$list = $posts->list_posts();
		$this->assertSame( 'Crumb & Co opens downtown', $list['posts'][0]['title'] );

		$single = $posts->get_post( 1 );
		$this->assertSame( 'Crumb & Co opens downtown', $single['title'] );
	}

	public function test_draft_title_decoded(): void {
		$GLOBALS['_mcpwp_test_posts'][1] = (object) array(
			'ID'            => 1,
			'post_type'     => 'post',
			'post_title'    => 'Draft: Crumb &amp; Co teaser',
			'post_name'     => 'draft-teaser',
			'post_status'   => 'draft',
			'post_date'     => '2026-06-01 00:00:00',
			'post_modified' => '2026-06-02 00:00:00',
			'post_author'   => 1,
			'post_content'  => '',
		);

		$drafts = ( new Mcpwp_Drafts() )->list_drafts();

		$this->assertSame( 'Draft: Crumb & Co teaser', $drafts['drafts'][0]['title'] );
	}
}
