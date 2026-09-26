<?php
/**
 * End-to-end coverage for #831's deprecated parameter-name aliases on
 * built-in (free-tier) tools, dispatched through the real
 * Mcpwp_REST_MCP::handle_mcp() -> handle_tools_call() -> resolve_tool_dispatch()
 * path, exactly as a live tools/call request would.
 *
 * The test harness's rest_do_request() fake (tests/bootstrap.php) does not
 * run the real REST controller's business logic for a route-based tool
 * (only a callback tool does that, in-process — see
 * CallbackToolDispatchTest.php) — it records the WP_REST_Request that would
 * have been dispatched. That is exactly what proves this fix: both the
 * canonical name and the deprecated alias must produce the identical
 * dispatched request (same route, same canonical param key, same value),
 * so a real REST controller downstream sees no difference between an old
 * caller and a new one.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ParamAliasesDispatchTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_current_user']        = 2;
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();

		// Several of these tools are gated on Elementor being active
		// (Mcpwp_Core::get_capabilities()['elementor'] === defined(
		// 'ELEMENTOR_VERSION' )) — same pattern as ElementorElementIdsTest.php
		// and its siblings.
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
	}

	private function call( string $tool, array $arguments ): array {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array(),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'tools/call',
				'params'  => array(
					'name'      => $tool,
					'arguments' => $arguments,
				),
			)
		);
		return $controller->handle_mcp( $request )->get_data();
	}

	private function assertDispatchedOk( array $data ): void {
		$this->assertArrayNotHasKey( 'error', $data, isset( $data['error'] ) ? 'Unexpected error: ' . $data['error']['message'] : '' );
	}

	private function lastDispatched(): WP_REST_Request {
		$requests = $GLOBALS['mcpwp_test_dispatched_requests'];
		$this->assertNotEmpty( $requests, 'No route was dispatched — the call was refused before reaching resolve_tool_dispatch().' );
		return end( $requests );
	}

	// -------------------------------------------------------------------
	// wp_add_section / wp_remove_section / wp_replace_section /
	// wp_patch_elementor / wp_edit_widget: page_id -> id (#831 row 1, 2 and
	// the three siblings found on the same sweep).
	// -------------------------------------------------------------------

	public function test_wp_add_section_accepts_deprecated_page_id(): void {
		$data = $this->call( 'wp_add_section', array( 'page_id' => 55, 'element' => array( 'elType' => 'section' ) ) );
		$this->assertDispatchedOk( $data );

		$req = $this->lastDispatched();
		$this->assertSame( '/mcpwp/v1/elementor/55/add-section', $req->get_route(), 'page_id must resolve to id and substitute into the path.' );
		$this->assertNull( $req->get_param( 'page_id' ), 'The deprecated key must not also reach the REST layer.' );
	}

	public function test_wp_add_section_accepts_canonical_id(): void {
		$data = $this->call( 'wp_add_section', array( 'id' => 55, 'element' => array( 'elType' => 'section' ) ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( '/mcpwp/v1/elementor/55/add-section', $this->lastDispatched()->get_route() );
	}

	public function test_wp_remove_section_accepts_deprecated_page_id(): void {
		$data = $this->call( 'wp_remove_section', array( 'page_id' => 12, 'element_id' => 'abc1234' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( '/mcpwp/v1/elementor/12/remove-section', $this->lastDispatched()->get_route() );
	}

	public function test_wp_replace_section_accepts_deprecated_page_id(): void {
		$data = $this->call(
			'wp_replace_section',
			array( 'page_id' => 12, 'element_id' => 'abc1234', 'element' => array( 'elType' => 'section' ) )
		);
		$this->assertDispatchedOk( $data );
		$this->assertSame( '/mcpwp/v1/elementor/12/replace-section', $this->lastDispatched()->get_route() );
	}

	public function test_wp_patch_elementor_accepts_deprecated_page_id(): void {
		$data = $this->call( 'wp_patch_elementor', array( 'page_id' => 88, 'operations' => array( array( 'op' => 'settings', 'element_id' => 'abc1234', 'settings' => array( 'title' => 'Hi' ) ) ) ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( '/mcpwp/v1/elementor/88/patch', $this->lastDispatched()->get_route() );
	}

	public function test_wp_edit_widget_accepts_deprecated_page_id(): void {
		$data = $this->call( 'wp_edit_widget', array( 'page_id' => 9, 'widget_id' => 'wid0001', 'settings' => array( 'title' => 'Hi' ) ) );
		$this->assertDispatchedOk( $data );

		$req = $this->lastDispatched();
		$this->assertSame( '/mcpwp/v1/elementor/9/edit-widget', $req->get_route() );
		$this->assertSame( 'wid0001', $req->get_param( 'widget_id' ), 'Only the id/page_id pair is aliased — widget_id is untouched.' );
	}

	public function test_wp_edit_widget_still_requires_widget_id(): void {
		// Sanity check the alias did not loosen the tool's real contract —
		// only page_id changed meaning, widget_id is still required.
		$data = $this->call( 'wp_edit_widget', array( 'id' => 9 ) );
		$this->assertArrayNotHasKey( 'error', $data, 'A resolved tool with a bad call is an isError result, not a protocol error.' );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertStringContainsString( 'widget_id', $data['result']['structuredContent']['error']['message'] );
	}

	// -------------------------------------------------------------------
	// wp_get_option / wp_update_option: name -> key (#831 row 14).
	// -------------------------------------------------------------------

	public function test_wp_get_option_accepts_deprecated_name(): void {
		$data = $this->call( 'wp_get_option', array( 'name' => 'blogname' ) );
		$this->assertDispatchedOk( $data );

		$req = $this->lastDispatched();
		$this->assertSame( '/mcpwp/v1/option', $req->get_route() );
		$this->assertSame( 'blogname', $req->get_param( 'key' ) );
		$this->assertNull( $req->get_param( 'name' ) );
	}

	public function test_wp_get_option_accepts_canonical_key(): void {
		$data = $this->call( 'wp_get_option', array( 'key' => 'blogname' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( 'blogname', $this->lastDispatched()->get_param( 'key' ) );
	}

	public function test_wp_update_option_accepts_deprecated_name(): void {
		$data = $this->call( 'wp_update_option', array( 'name' => 'blogname', 'value' => 'New Name' ) );
		$this->assertDispatchedOk( $data );

		$req = $this->lastDispatched();
		$this->assertSame( 'blogname', $req->get_param( 'key' ) );
		$this->assertSame( 'New Name', $req->get_param( 'value' ) );
	}

	public function test_wp_update_option_still_requires_value(): void {
		$data = $this->call( 'wp_update_option', array( 'name' => 'blogname' ) );
		$this->assertArrayNotHasKey( 'error', $data, 'A resolved tool with a bad call is an isError result, not a protocol error.' );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertStringContainsString( 'value', $data['result']['structuredContent']['error']['message'] );
	}

	// -------------------------------------------------------------------
	// wp_search / wp_fetch: type -> post_type, remapped back to the REST
	// layer's own 'type' param (#831 row 15 + sweep finding on wp_fetch).
	// -------------------------------------------------------------------

	public function test_wp_search_accepts_deprecated_type_and_still_reaches_rest_type_param(): void {
		$data = $this->call( 'wp_search', array( 'query' => 'hello', 'type' => 'post' ) );
		$this->assertDispatchedOk( $data );

		$req = $this->lastDispatched();
		$this->assertSame( '/mcpwp/v1/search', $req->get_route() );
		$this->assertSame( 'post', $req->get_param( 'type' ), 'post_type is remapped back to the REST endpoint\'s own "type" param.' );
	}

	public function test_wp_search_accepts_canonical_post_type(): void {
		$data = $this->call( 'wp_search', array( 'query' => 'hello', 'post_type' => 'page' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( 'page', $this->lastDispatched()->get_param( 'type' ) );
	}

	public function test_wp_fetch_accepts_deprecated_type(): void {
		$data = $this->call( 'wp_fetch', array( 'id' => 5, 'type' => 'page' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( 'page', $this->lastDispatched()->get_param( 'type' ) );
	}

	public function test_wp_fetch_accepts_canonical_post_type(): void {
		$data = $this->call( 'wp_fetch', array( 'id' => 5, 'post_type' => 'page' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( 'page', $this->lastDispatched()->get_param( 'type' ) );
	}

	// -------------------------------------------------------------------
	// wp_update_media / wp_upload_media_b64: alt_text -> alt,
	// base64 -> data (#831 rows 11 and 12).
	// -------------------------------------------------------------------

	public function test_wp_update_media_accepts_deprecated_alt_text(): void {
		$data = $this->call( 'wp_update_media', array( 'id' => 40, 'alt_text' => 'A hero image' ) );
		$this->assertDispatchedOk( $data );

		$req = $this->lastDispatched();
		$this->assertSame( 'A hero image', $req->get_param( 'alt' ) );
		$this->assertNull( $req->get_param( 'alt_text' ) );
	}

	public function test_wp_update_media_accepts_canonical_alt(): void {
		$data = $this->call( 'wp_update_media', array( 'id' => 40, 'alt' => 'A hero image' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( 'A hero image', $this->lastDispatched()->get_param( 'alt' ) );
	}

	public function test_wp_upload_media_b64_accepts_deprecated_base64_and_alt_text(): void {
		$data = $this->call( 'wp_upload_media_b64', array( 'base64' => 'ZmFrZQ==', 'filename' => 'hero.png', 'alt_text' => 'Hero' ) );
		$this->assertDispatchedOk( $data );

		$req = $this->lastDispatched();
		$this->assertSame( '/mcpwp/v1/media/from-base64', $req->get_route() );
		$this->assertSame( 'ZmFrZQ==', $req->get_param( 'data' ) );
		$this->assertSame( 'Hero', $req->get_param( 'alt' ) );
		$this->assertNull( $req->get_param( 'base64' ) );
		$this->assertNull( $req->get_param( 'alt_text' ) );
	}

	public function test_wp_upload_media_b64_accepts_canonical_data(): void {
		$data = $this->call( 'wp_upload_media_b64', array( 'data' => 'ZmFrZQ==', 'filename' => 'hero.png' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( 'ZmFrZQ==', $this->lastDispatched()->get_param( 'data' ) );
	}

	public function test_wp_upload_media_b64_still_requires_filename(): void {
		$data = $this->call( 'wp_upload_media_b64', array( 'base64' => 'ZmFrZQ==' ) );
		$this->assertArrayNotHasKey( 'error', $data, 'A resolved tool with a bad call is an isError result, not a protocol error.' );
		$this->assertTrue( $data['result']['isError'] );
		$this->assertStringContainsString( 'filename', $data['result']['structuredContent']['error']['message'] );
	}

	// -------------------------------------------------------------------
	// wp_get_elementor_widgets: widget -> widget_type, remapped to the
	// REST layer's 'type' param (#831 row 10 — this was also a live
	// dispatch bug: 'widget' was silently dropped, always returning the
	// full widget list regardless of what was passed).
	// -------------------------------------------------------------------

	public function test_wp_get_elementor_widgets_accepts_deprecated_widget_and_reaches_rest_type_param(): void {
		$data = $this->call( 'wp_get_elementor_widgets', array( 'widget' => 'heading' ) );
		$this->assertDispatchedOk( $data );

		$req = $this->lastDispatched();
		$this->assertSame( '/mcpwp/v1/elementor/widgets', $req->get_route() );
		$this->assertSame( 'heading', $req->get_param( 'type' ), 'widget_type is remapped to the REST route\'s own "type" param — before the fix, "widget" reached neither "type" nor anything the handler read.' );
	}

	public function test_wp_get_elementor_widgets_accepts_canonical_widget_type(): void {
		$data = $this->call( 'wp_get_elementor_widgets', array( 'widget_type' => 'heading' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( 'heading', $this->lastDispatched()->get_param( 'type' ) );
	}

	// -------------------------------------------------------------------
	// wp_get_kit_css_block / wp_delete_kit_css_block: block_id -> name
	// (#831 row 9, defensive).
	// -------------------------------------------------------------------

	public function test_wp_get_kit_css_block_accepts_deprecated_block_id(): void {
		$data = $this->call( 'wp_get_kit_css_block', array( 'block_id' => 'hero-cta' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( '/mcpwp/v1/elementor/kit-css/blocks/hero-cta', $this->lastDispatched()->get_route() );
	}

	public function test_wp_get_kit_css_block_accepts_canonical_name(): void {
		$data = $this->call( 'wp_get_kit_css_block', array( 'name' => 'hero-cta' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( '/mcpwp/v1/elementor/kit-css/blocks/hero-cta', $this->lastDispatched()->get_route() );
	}

	public function test_wp_delete_kit_css_block_accepts_deprecated_block_id(): void {
		$data = $this->call( 'wp_delete_kit_css_block', array( 'block_id' => 'hero-cta' ) );
		$this->assertDispatchedOk( $data );
		$this->assertSame( '/mcpwp/v1/elementor/kit-css/blocks/hero-cta', $this->lastDispatched()->get_route() );
	}

	// -------------------------------------------------------------------
	// wp_submit_feedback: message -> description (#831 row 21).
	// -------------------------------------------------------------------

	public function test_wp_submit_feedback_accepts_deprecated_message(): void {
		$data = $this->call(
			'wp_submit_feedback',
			array( 'type' => 'bug_report', 'title' => 'Something broke', 'message' => 'It broke when I clicked save.' )
		);
		$this->assertDispatchedOk( $data );

		$req = $this->lastDispatched();
		$this->assertSame( 'It broke when I clicked save.', $req->get_param( 'description' ) );
		$this->assertNull( $req->get_param( 'message' ) );
	}

	public function test_wp_submit_feedback_accepts_canonical_description(): void {
		$data = $this->call(
			'wp_submit_feedback',
			array( 'type' => 'bug_report', 'title' => 'Something broke', 'description' => 'It broke when I clicked save.' )
		);
		$this->assertDispatchedOk( $data );
		$this->assertSame( 'It broke when I clicked save.', $this->lastDispatched()->get_param( 'description' ) );
	}
}
