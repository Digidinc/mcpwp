<?php

use PHPUnit\Framework\TestCase;

/** Minimal harness to exercise the two independent scope-resolution tables
 * for the /ai-crawlers/policy route, the same technique
 * ToolNameRouteScopeParityTest.php uses (kept local/uniquely-named here to
 * avoid any coupling between the two test files).
 */
class Mcpwp_AI_Crawler_Scope_Harness {
	use Mcpwp_Api_Auth;

	public function route_scope( string $method, string $route ): string {
		return $this->get_required_scope_for_request( new WP_REST_Request( $method, $route ) );
	}

	public function tool_scope( string $tool_name ): string {
		return $this->get_required_scope_for_tool_name( $tool_name );
	}
}

final class AiCrawlerPolicyTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
	}

	public function test_default_policy_allows_every_known_bot(): void {
		$policy = Mcpwp_AI_Crawler_Policy::get_policy();

		$this->assertNotEmpty( $policy );
		foreach ( $policy as $bot => $status ) {
			$this->assertSame( 'allow', $status, "$bot should default to allow" );
		}
	}

	public function test_known_bots_include_the_required_names_with_correct_type(): void {
		$bots = Mcpwp_AI_Crawler_Policy::known_bots();

		$expected_types = array(
			'OAI-SearchBot'     => Mcpwp_AI_Crawler_Policy::TYPE_SEARCH,
			'GPTBot'            => Mcpwp_AI_Crawler_Policy::TYPE_TRAINING,
			'ChatGPT-User'      => Mcpwp_AI_Crawler_Policy::TYPE_SEARCH,
			'ClaudeBot'         => Mcpwp_AI_Crawler_Policy::TYPE_TRAINING,
			'Claude-SearchBot'  => Mcpwp_AI_Crawler_Policy::TYPE_SEARCH,
			'Claude-User'       => Mcpwp_AI_Crawler_Policy::TYPE_SEARCH,
			'PerplexityBot'     => Mcpwp_AI_Crawler_Policy::TYPE_SEARCH,
			'Perplexity-User'   => Mcpwp_AI_Crawler_Policy::TYPE_SEARCH,
			'Google-Extended'   => Mcpwp_AI_Crawler_Policy::TYPE_TRAINING,
			'Applebot-Extended' => Mcpwp_AI_Crawler_Policy::TYPE_TRAINING,
			'CCBot'             => Mcpwp_AI_Crawler_Policy::TYPE_TRAINING,
			'Bytespider'        => Mcpwp_AI_Crawler_Policy::TYPE_TRAINING,
		);

		foreach ( $expected_types as $bot => $type ) {
			$this->assertArrayHasKey( $bot, $bots );
			$this->assertSame( $type, $bots[ $bot ]['type'], "$bot classification" );
		}
	}

	public function test_set_policy_persists_and_reports_applied(): void {
		$result = Mcpwp_AI_Crawler_Policy::set_policy( array( 'GPTBot' => 'deny' ) );

		$this->assertSame( array( 'GPTBot' => 'deny' ), $result['applied'] );
		$this->assertSame( array(), $result['rejected'] );
		$this->assertSame( 'deny', Mcpwp_AI_Crawler_Policy::get_policy()['GPTBot'] );
		$this->assertSame( 'allow', Mcpwp_AI_Crawler_Policy::get_policy()['ClaudeBot'] );
	}

	public function test_set_policy_rejects_unknown_bot_and_bad_value(): void {
		$result = Mcpwp_AI_Crawler_Policy::set_policy( array(
			'NotARealBot' => 'deny',
			'GPTBot'      => 'sometimes',
		) );

		$this->assertArrayHasKey( 'NotARealBot', $result['rejected'] );
		$this->assertArrayHasKey( 'GPTBot', $result['rejected'] );
		$this->assertSame( array(), $result['applied'] );
		// Nothing should have been written for the rejected bot.
		$this->assertSame( 'allow', Mcpwp_AI_Crawler_Policy::get_policy()['GPTBot'] );
	}

	public function test_robots_block_lists_only_denied_bots(): void {
		Mcpwp_AI_Crawler_Policy::set_policy( array( 'GPTBot' => 'deny', 'CCBot' => 'deny' ) );

		$block = Mcpwp_AI_Crawler_Policy::build_block( '' );

		$this->assertStringContainsString( 'User-agent: GPTBot', $block );
		$this->assertStringContainsString( 'User-agent: CCBot', $block );
		$this->assertStringNotContainsString( 'ClaudeBot', $block );
		$this->assertStringContainsString( 'Disallow: /', $block );
	}

	public function test_robots_block_is_empty_when_nothing_is_denied(): void {
		$this->assertSame( '', Mcpwp_AI_Crawler_Policy::build_block( '' ) );
	}

	public function test_append_to_robots_is_idempotent(): void {
		Mcpwp_AI_Crawler_Policy::set_policy( array( 'GPTBot' => 'deny' ) );

		$once  = Mcpwp_AI_Crawler_Policy::append_to_robots( "User-agent: *\nDisallow:\n" );
		$twice = Mcpwp_AI_Crawler_Policy::append_to_robots( $once );

		$this->assertSame( $once, $twice );
		$this->assertSame( 1, substr_count( $twice, Mcpwp_AI_Crawler_Policy::BLOCK_START ) );
	}

	public function test_does_not_duplicate_a_directive_an_seo_plugin_already_emitted_for_the_same_agent(): void {
		Mcpwp_AI_Crawler_Policy::set_policy( array( 'GPTBot' => 'deny', 'CCBot' => 'deny' ) );

		// Some other robots_txt filter already added its own GPTBot rule.
		$existing = "User-agent: *\nDisallow:\nUser-agent: GPTBot\nDisallow: /private\n";
		$block    = Mcpwp_AI_Crawler_Policy::build_block( $existing );

		$this->assertStringNotContainsString( 'User-agent: GPTBot', $block );
		$this->assertStringContainsString( 'User-agent: CCBot', $block );
	}

	public function test_physical_robots_file_detection(): void {
		$path = rtrim( ABSPATH, '/' ) . '/robots.txt';
		$this->assertFileDoesNotExist( $path );
		$this->assertFalse( Mcpwp_AI_Crawler_Policy::physical_robots_file_exists() );

		file_put_contents( $path, "User-agent: *\n" );
		try {
			$this->assertTrue( Mcpwp_AI_Crawler_Policy::physical_robots_file_exists() );
		} finally {
			unlink( $path );
		}
	}

	public function test_writing_the_policy_requires_admin_scope_reading_does_not(): void {
		$auth = new Mcpwp_AI_Crawler_Scope_Harness();

		$this->assertSame( 'admin', $auth->route_scope( 'PUT', '/mcpwp/v1/ai-crawlers/policy' ) );
		$this->assertSame( 'read', $auth->route_scope( 'GET', '/mcpwp/v1/ai-crawlers/policy' ) );

		$this->assertSame( 'admin', $auth->tool_scope( 'wp_set_ai_crawler_policy' ) );
		$this->assertSame( 'read', $auth->tool_scope( 'wp_get_ai_crawler_policy' ) );
	}
}
