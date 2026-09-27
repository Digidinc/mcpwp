<?php
/**
 * #913 — three new MCP prompt recipes ported from the agent playbooks /
 * mcpwp:operate loop (brand-first, verify-before-done): build-landing-page,
 * fix-page-from-screenshot, publish-post-safely. Plus a registry-wide guard:
 * a prompt that tells an agent to call a tool that does not exist is worse
 * than no prompt at all (mirrors McpPromptsSeoLoopTest's existing check for
 * seo-loop, generalized to every prompt prompts/list advertises).
 *
 * build-landing-page depends on wp_build_page, a Pro tool — #1015
 * adversarial review round 1, N4, flagged that a Free-tier caller who
 * followed it would have every step after the first refused with
 * pro_required, and that the generic tool-name guard below checked a prompt
 * against the FREE+PRO registry regardless of which tier actually produced
 * the prompts/list it was reading. Both are fixed together here: the tier is
 * set explicitly per test (setTier(), mirroring FigmaProGateTest's own
 * pattern), Free-tier tests check prompts/list against the Free-only
 * registry, and Pro-tier tests check it against Free+Pro.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class McpPromptsResourceRecipesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$this->setTier( false );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs'] = null;
		$this->resetLicense();
	}

	private function resetLicense(): void {
		$ref = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * @param bool $pro Whether Pro should be active for the rest of this test.
	 */
	private function setTier( bool $pro ): void {
		$GLOBALS['mcpwp_test_fs'] = $pro ? new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' ) : null;
		$this->resetLicense();
		$this->assertSame( $pro, Mcpwp_License::get_instance()->is_pro(), 'tier precondition' );
	}

	private function dispatch( $method, $params = array(), $id = 1 ) {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => $method,
				'params'  => $params,
			)
		);
		return $controller->handle_mcp( $request )->get_data();
	}

	/** @return string[] Every registered free tool name. */
	private function free_tool_names(): array {
		return array_values(
			array_filter(
				array_column( ( new Mcpwp_MCP_Free_Tools() )->get_tools(), 'name' )
			)
		);
	}

	/** @return string[] Every registered free + pro tool name. */
	private function free_and_pro_tool_names(): array {
		return array_merge( $this->free_tool_names(), array_column( ( new Mcpwp_MCP_Pro_Tools() )->get_tools(), 'name' ) );
	}

	// ── Tier gating on build-landing-page (#1015 review round 1, N4) ──────

	public function test_build_landing_page_is_hidden_from_prompts_list_on_free(): void {
		$data  = $this->dispatch( 'prompts/list' );
		$names = array_column( $data['result']['prompts'], 'name' );

		$this->assertNotContains( 'build-landing-page', $names, 'wp_build_page is Pro-only; the prompt must not be advertised on Free.' );
	}

	public function test_build_landing_page_prompts_get_refuses_on_free_like_an_unknown_prompt(): void {
		$unknown = $this->dispatch( 'prompts/get', array( 'name' => 'definitely-not-a-real-prompt' ) );
		$gated   = $this->dispatch(
			'prompts/get',
			array(
				'name'      => 'build-landing-page',
				'arguments' => array( 'title' => 'x', 'goal' => 'y' ),
			)
		);

		$this->assertArrayHasKey( 'error', $gated );
		$this->assertSame( $unknown['error']['code'], $gated['error']['code'] );
	}

	public function test_build_landing_page_is_listed_and_works_on_pro(): void {
		$this->setTier( true );

		$data  = $this->dispatch( 'prompts/list' );
		$names = array_column( $data['result']['prompts'], 'name' );
		$this->assertContains( 'build-landing-page', $names );

		$data = $this->dispatch(
			'prompts/get',
			array(
				'name'      => 'build-landing-page',
				'arguments' => array(
					'title' => 'Spring Sale',
					'goal'  => 'capture leads for the spring sale',
				),
			)
		);
		$this->assertArrayHasKey( 'result', $data );
		$text = $data['result']['messages'][0]['content']['text'];
		$this->assertStringContainsString( 'Spring Sale', $text );
		$this->assertStringContainsString( 'mcpwp://brand', $text );
		$this->assertStringContainsString( 'wp_build_page', $text );
		$this->assertStringContainsString( 'wp_get_rendered_html', $text );
		// N4: wp_screenshot_url takes a url, not an id — the fixed wording
		// must say so, not just mention the tool name.
		$this->assertStringContainsString( 'wp_screenshot_url takes a url, not an id', $text );
	}

	public function test_build_landing_page_requires_title_and_goal_on_pro(): void {
		$this->setTier( true );

		$data = $this->dispatch( 'prompts/get', array( 'name' => 'build-landing-page' ) );
		$this->assertArrayHasKey( 'error', $data, 'Missing required arguments must be refused.' );
	}

	// ── The other two recipes are Free-only, no gating ─────────────────────

	public function test_fix_page_from_screenshot_is_listed_on_free_and_requires_page_id(): void {
		$data  = $this->dispatch( 'prompts/list' );
		$names = array_column( $data['result']['prompts'], 'name' );
		$this->assertContains( 'fix-page-from-screenshot', $names );

		$data = $this->dispatch( 'prompts/get', array( 'name' => 'fix-page-from-screenshot' ) );
		$this->assertArrayHasKey( 'error', $data );

		$data = $this->dispatch(
			'prompts/get',
			array(
				'name'      => 'fix-page-from-screenshot',
				'arguments' => array( 'page_id' => 42 ),
			)
		);
		$this->assertArrayHasKey( 'result', $data );
		$text = $data['result']['messages'][0]['content']['text'];
		$this->assertStringContainsString( '42', $text );
		$this->assertStringContainsString( 'mcpwp://brand', $text );
		$this->assertStringContainsString( 'wp_screenshot_url', $text );
		$this->assertStringContainsString( 'wp_get_elementor', $text );
		$this->assertStringContainsString( 'wp_list_approvals', $text );
		// N4: wp_screenshot_url takes a url, not an id.
		$this->assertStringContainsString( 'wp_screenshot_url takes a url, not an id', $text );
	}

	public function test_publish_post_safely_is_listed_on_free_and_never_claims_wp_list_approvals_creates_one(): void {
		$data  = $this->dispatch( 'prompts/list' );
		$names = array_column( $data['result']['prompts'], 'name' );
		$this->assertContains( 'publish-post-safely', $names );

		$data = $this->dispatch( 'prompts/get', array( 'name' => 'publish-post-safely' ) );
		$this->assertArrayHasKey( 'error', $data );

		$data = $this->dispatch(
			'prompts/get',
			array(
				'name'      => 'publish-post-safely',
				'arguments' => array( 'post_id' => 7 ),
			)
		);
		$this->assertArrayHasKey( 'result', $data );
		$text = $data['result']['messages'][0]['content']['text'];
		$this->assertStringContainsString( 'mcpwp://brand', $text );
		$this->assertStringContainsString( 'wp_validate_seo_readiness', $text );
		$this->assertStringContainsString( 'wp_list_approvals', $text );
		$this->assertStringContainsString( 'Do not call wp_update_post/wp_update_page with status=publish yourself', $text );
		// N4: wp_list_approvals only lists — it must not be described as
		// creating an approval request.
		$this->assertStringContainsString( 'There is no tool to create a publish approval on demand', $text );
		$this->assertStringNotContainsString( 'wp_list_approvals so a human approves', $text, 'Must not claim wp_list_approvals itself creates/raises an approval.' );
	}

	// ── Registry-wide guard, checked against the tier's own registry ──────

	/**
	 * Every wp_-prefixed tool name mentioned in ANY Free-advertised prompt's
	 * text must be a real, registered FREE tool — generalizes
	 * McpPromptsSeoLoopTest's seo-loop-only check to the whole Free
	 * prompts/list surface. Checking against the free-only registry (not
	 * free+pro) is what actually verifies "a Free caller following this
	 * prompt only ever calls tools Free access can run" (#1015 adversarial
	 * review round 1, N4) — build-landing-page's own gating above is what
	 * keeps this check honest by construction, since it is the only prompt
	 * that ever mentions a Pro-only tool.
	 */
	public function test_every_tool_mentioned_in_every_free_prompt_is_in_the_free_registry(): void {
		$this->assert_every_mentioned_tool_is_registered( $this->free_tool_names() );
	}

	/**
	 * Same guard, run again on Pro — where build-landing-page (mentioning
	 * the Pro-only wp_build_page) is now advertised too, checked against
	 * Free+Pro.
	 */
	public function test_every_tool_mentioned_in_every_pro_prompt_is_in_the_free_or_pro_registry(): void {
		$this->setTier( true );
		$this->assert_every_mentioned_tool_is_registered( $this->free_and_pro_tool_names() );
	}

	private function assert_every_mentioned_tool_is_registered( array $registered ): void {
		$this->assertNotEmpty( $registered );

		$list_data = $this->dispatch( 'prompts/list' );

		foreach ( $list_data['result']['prompts'] as $prompt ) {
			$name = $prompt['name'];

			$arguments = array();
			foreach ( $prompt['arguments'] as $arg ) {
				if ( ! empty( $arg['required'] ) ) {
					// A stub value per type is enough — these prompts only
					// sanitize/interpolate arguments into text, they never
					// resolve them against real data.
					$arguments[ $arg['name'] ] = 'id' === $arg['name'] || false !== strpos( $arg['name'], '_id' ) ? 1 : 'test-value';
				}
			}

			$get_data = $this->dispatch( 'prompts/get', array( 'name' => $name, 'arguments' => $arguments ) );
			$this->assertArrayHasKey( 'result', $get_data, "prompts/get for \"{$name}\" must succeed with stubbed required arguments." );

			$text = $get_data['result']['messages'][0]['content']['text'];
			preg_match_all( '/\bwp_[a-z0-9_]+\b/', $text, $matches );

			foreach ( array_unique( $matches[0] ) as $mentioned_tool ) {
				$this->assertContains(
					$mentioned_tool,
					$registered,
					"Prompt \"{$name}\" mentions \"{$mentioned_tool}\", which is not a registered tool on this tier."
				);
			}
		}
	}
}
