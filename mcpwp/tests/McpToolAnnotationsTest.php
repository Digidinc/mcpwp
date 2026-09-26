<?php
/**
 * Coverage for MCP tool `title` + accurate readOnly/destructive/openWorld/
 * idempotent annotations — the Claude connector directory's public
 * submission (opened 2026-09-25) requires every tool to carry a human
 * `title` and correctness-checked hints.
 *
 * Mcpwp_MCP_Tool_Annotations::ANNOTATION_OVERRIDES is the single source of
 * truth consulted before the base registry's HTTP-method heuristic. This
 * test pins: every listed tool has a non-empty title and boolean hints, each
 * override category actually changes what the heuristic would have said (so
 * a fixed underlying implementation can't silently make the override a
 * no-op nobody notices), and a guard against new destructive-sounding tools
 * shipping without a true destructiveHint or a reviewed exception.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-figma.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-ai-integration.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-figma-integration.php';

final class McpToolAnnotationsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
		$GLOBALS['mcpwp_test_fs']         = null;
		$this->resetLicense();
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

	private function setPro( bool $pro ): void {
		$GLOBALS['mcpwp_test_fs'] = $pro ? new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' ) : null;
		$this->resetLicense();
	}

	/**
	 * Every registry this submission has to cover: free + pro tools always;
	 * Figma only once Pro is switched on (its get_tools() returns nothing on
	 * Free by design).
	 *
	 * @return Mcpwp_MCP_Tool_Registry[]
	 */
	private function all_registries(): array {
		$this->setPro( true );
		return array(
			new Mcpwp_MCP_Free_Tools(),
			new Mcpwp_MCP_Pro_Tools(),
			new Mcpwp_MCP_AI_Integration(),
			new Mcpwp_MCP_Figma_Integration(),
		);
	}

	// ── Every tool has a title + boolean hints ─────────────────────────

	public function test_every_tool_across_every_registry_has_a_title_and_boolean_hints(): void {
		foreach ( $this->all_registries() as $registry ) {
			foreach ( $registry->get_tools() as $tool ) {
				$name = $tool['name'];

				$this->assertArrayHasKey( 'title', $tool, "{$name}: missing top-level title" );
				$this->assertIsString( $tool['title'], "{$name}: title is not a string" );
				$this->assertNotSame( '', $tool['title'], "{$name}: title is empty" );

				$ann = $tool['annotations'];
				foreach ( array( 'readOnlyHint', 'destructiveHint', 'openWorldHint', 'idempotentHint' ) as $key ) {
					$this->assertArrayHasKey( $key, $ann, "{$name}: missing {$key}" );
					$this->assertIsBool( $ann[ $key ], "{$name}: {$key} is not boolean" );
				}

				$this->assertArrayHasKey( 'title', $ann, "{$name}: annotations.title missing (pre-2025-06-18 clients read this)" );
				$this->assertSame( $tool['title'], $ann['title'], "{$name}: top-level title and annotations.title disagree" );
			}
		}
	}

	public function test_titles_are_human_not_raw_tool_names(): void {
		$free = new Mcpwp_MCP_Free_Tools();

		$this->assertSame( 'Get Elementor summary', $free->get_tool_annotations( 'wp_get_elementor_summary' )['title'] );
		$this->assertSame( 'List pages', $free->get_tool_annotations( 'wp_list_pages' )['title'] );
		$this->assertSame( 'Delete media', $free->get_tool_annotations( 'wp_delete_media' )['title'] );
	}

	public function test_curated_title_overrides_win_over_the_humanizer(): void {
		$this->assertSame( 'Find and replace across pages', Mcpwp_MCP_Tool_Annotations::get_title( 'wp_bulk_find_replace' ) );
		$this->assertSame( 'WooCommerce status', Mcpwp_MCP_Tool_Annotations::get_title( 'wc_status' ) );
		$this->assertSame( 'Get product', Mcpwp_MCP_Tool_Annotations::get_title( 'wc_get_product' ) );
	}

	public function test_known_terms_keep_fixed_casing_at_any_position(): void {
		$this->assertSame( 'Get SEO', Mcpwp_MCP_Tool_Annotations::humanize_tool_name( 'wp_get_seo' ) );
		$this->assertSame( 'SEO status', Mcpwp_MCP_Tool_Annotations::humanize_tool_name( 'wp_seo_status' ) );
		$this->assertSame( 'Get CSS length', Mcpwp_MCP_Tool_Annotations::humanize_tool_name( 'wp_get_css_length' ) );
	}

	public function test_google_is_capitalized_mid_title(): void {
		$this->assertSame( 'Submit to Google index', Mcpwp_MCP_Tool_Annotations::humanize_tool_name( 'wp_submit_to_google_index' ) );
		$this->assertSame( 'Get Figma file', Mcpwp_MCP_Tool_Annotations::humanize_tool_name( 'wp_get_figma_file' ) );
	}

	public function test_humanizer_handles_third_party_and_degenerate_names(): void {
		// A real, non-empty name always produces a real title.
		$this->assertNotSame( '', Mcpwp_MCP_Tool_Annotations::humanize_tool_name( 'digid_list_listings' ) );

		// An empty/malformed name is passed through unchanged rather than
		// throwing — get_title() is what guarantees every *actual* tool
		// (whose name is never empty) gets a non-empty title.
		$this->assertSame( '', Mcpwp_MCP_Tool_Annotations::humanize_tool_name( '' ) );
	}

	// ── openWorldHint overrides actually change the heuristic's answer ──

	/**
	 * @dataProvider open_world_override_provider
	 */
	public function test_open_world_overrides_are_true_and_would_have_been_false_without_them( string $tool ): void {
		$override = Mcpwp_MCP_Tool_Annotations::get_override( $tool );

		$this->assertNotNull( $override, "{$tool}: expected an override entry" );
		$this->assertTrue( $override['openWorldHint'], "{$tool}: openWorldHint override must be true" );
		$this->assertArrayHasKey( 'reason', $override, "{$tool}: override must document why" );
		$this->assertNotSame( '', $override['reason'] );
	}

	public function open_world_override_provider(): array {
		return array(
			array( 'wp_fetch' ),
			array( 'wp_keyword_research' ),
			array( 'wp_check_update' ),
			array( 'wp_get_rendered_html' ),
			array( 'wp_get_rendered_copy' ),
			array( 'wp_google_index_status' ),
			array( 'wp_submit_to_google_index' ),
			array( 'wp_refresh_seo_visibility' ),
			array( 'wp_seo_opportunities' ),
		);
	}

	public function test_open_world_override_is_the_effective_annotation(): void {
		$free = new Mcpwp_MCP_Free_Tools();
		$pro  = new Mcpwp_MCP_Pro_Tools();

		$this->assertTrue( $free->get_tool_annotations( 'wp_fetch' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_keyword_research' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_check_update' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_get_rendered_html' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_get_rendered_copy' )['openWorldHint'] );
		$this->assertTrue( $pro->get_tool_annotations( 'wp_google_index_status' )['openWorldHint'] );
		$this->assertTrue( $pro->get_tool_annotations( 'wp_submit_to_google_index' )['openWorldHint'] );
		$this->assertTrue( $pro->get_tool_annotations( 'wp_refresh_seo_visibility' )['openWorldHint'] );
		$this->assertTrue( $pro->get_tool_annotations( 'wp_seo_opportunities' )['openWorldHint'] );

		// Baseline: wp_seo_visibility (the plain, stored-snapshot read) stays
		// closed-world — only the refresh/opportunities paths that can spend
		// DataForSEO credit are flagged.
		$this->assertFalse( $pro->get_tool_annotations( 'wp_seo_visibility' )['openWorldHint'] );
	}

	// ── readOnlyHint overrides ───────────────────────────────────────────

	public function test_validate_parse_serialize_tools_are_read_only_despite_being_post(): void {
		$free = new Mcpwp_MCP_Free_Tools();
		$map  = $free->get_tool_map();

		foreach ( array( 'wp_parse_blocks', 'wp_serialize_blocks', 'wp_validate_blocks' ) as $tool ) {
			$this->assertSame( 'POST', $map[ $tool ]['method'], "{$tool}: premise — this tool is POST-mapped" );
			$this->assertTrue( $free->get_tool_annotations( $tool )['readOnlyHint'], "{$tool}: should be read-only despite POST" );
		}
	}

	public function test_seo_audit_site_is_not_read_only_because_store_param_persists_a_snapshot(): void {
		$free = new Mcpwp_MCP_Free_Tools();
		$map  = $free->get_tool_map();

		$this->assertSame( 'GET', $map['wp_seo_audit_site']['method'], 'premise — this tool is GET-mapped' );
		$this->assertFalse(
			$free->get_tool_annotations( 'wp_seo_audit_site' )['readOnlyHint'],
			'wp_seo_audit_site can persist a stored snapshot when store=true and must not claim to be pure-read'
		);
	}

	// ── destructiveHint corrections ──────────────────────────────────────

	public function test_full_replacement_tools_are_destructive_like_apply_elementor_template(): void {
		$free = new Mcpwp_MCP_Free_Tools();
		$pro  = new Mcpwp_MCP_Pro_Tools();

		// Baseline this is modeled on: already true in the codebase (#637).
		$this->assertTrue( $pro->get_tool_annotations( 'wp_apply_elementor_template' )['destructiveHint'] );

		$this->assertTrue( $free->get_tool_annotations( 'wp_set_elementor' )['destructiveHint'], 'wp_set_elementor fully replaces a page, same as wp_apply_elementor_template' );
		$this->assertTrue( $free->get_tool_annotations( 'wp_replace_section' )['destructiveHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_remove_section' )['destructiveHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_set_blocks' )['destructiveHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_set_kit_css' )['destructiveHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_set_custom_css' )['destructiveHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_bulk_find_replace' )['destructiveHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_remove_integration' )['destructiveHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_rollback_approval' )['destructiveHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_bulk_update_posts' )['destructiveHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_bulk_update_pages' )['destructiveHint'] );
		$this->assertTrue( $pro->get_tool_annotations( 'wp_set_elementor_globals' )['destructiveHint'] );
		$this->assertTrue( $pro->get_tool_annotations( 'wp_set_curriculum' )['destructiveHint'] );
		$this->assertTrue( $pro->get_tool_annotations( 'wp_set_template_conditions' )['destructiveHint'] );
		$this->assertTrue( $pro->get_tool_annotations( 'wp_bulk_seo' )['destructiveHint'] );
	}

	public function test_additive_bulk_tools_are_documented_reviewed_exceptions_not_destructive(): void {
		$free = new Mcpwp_MCP_Free_Tools();

		foreach ( array( 'wp_bulk_upload_media', 'wp_get_elementor_bulk', 'wp_bulk_create_pages', 'wp_bulk_create_posts' ) as $tool ) {
			$this->assertFalse( $free->get_tool_annotations( $tool )['destructiveHint'], "{$tool}: additive, must not be destructive" );

			$override = Mcpwp_MCP_Tool_Annotations::get_override( $tool );
			$this->assertNotNull( $override, "{$tool}: matches the destructive-name guard pattern and must be an explicit, reviewed exception" );
			$this->assertArrayHasKey( 'destructiveHint', $override );
			$this->assertFalse( $override['destructiveHint'] );
			$this->assertArrayHasKey( 'reason', $override );
		}
	}

	// ── idempotentHint ───────────────────────────────────────────────────

	public function test_read_only_tools_default_to_idempotent(): void {
		$free = new Mcpwp_MCP_Free_Tools();

		$ann = $free->get_tool_annotations( 'wp_list_pages' );
		$this->assertTrue( $ann['readOnlyHint'] );
		$this->assertTrue( $ann['idempotentHint'], 'a read-only tool changes nothing, so it is idempotent by construction' );
	}

	public function test_write_tools_default_to_not_idempotent_without_an_override(): void {
		$free = new Mcpwp_MCP_Free_Tools();

		$ann = $free->get_tool_annotations( 'wp_create_post' );
		$this->assertFalse( $ann['readOnlyHint'] );
		$this->assertFalse( $ann['idempotentHint'], 'creating a post twice creates two posts — must not be asserted idempotent' );
	}

	public function test_explicitly_idempotent_write_tools(): void {
		$free = new Mcpwp_MCP_Free_Tools();
		$pro  = new Mcpwp_MCP_Pro_Tools();

		foreach ( array( 'wp_delete_post', 'wp_set_post_meta', 'wp_set_noindex', 'wp_reorder_menu_items', 'wp_flush_permalinks', 'wp_update_options' ) as $tool ) {
			$this->assertTrue( $free->get_tool_annotations( $tool )['idempotentHint'], "{$tool}: expected idempotentHint=true" );
		}
		$this->assertTrue( $pro->get_tool_annotations( 'wp_push_to_live' )['idempotentHint'] );
		$this->assertTrue( $pro->get_tool_annotations( 'wp_apply_elementor_template' )['idempotentHint'] );
	}

	// ── Figma + AI integration tools inherit the same machinery ──────────

	public function test_figma_tools_have_titles_and_correct_open_world_hints(): void {
		$this->setPro( true );
		$figma = new Mcpwp_MCP_Figma_Integration();
		$tools = array_column( $figma->get_tools(), null, 'name' );

		$this->assertNotEmpty( $tools, 'premise: Figma tools are present once Pro is on' );
		$this->assertSame( 'Get Figma file', $tools['wp_get_figma_file']['title'] );
		$this->assertTrue( $tools['wp_get_figma_file']['annotations']['openWorldHint'] );
		$this->assertTrue( $tools['wp_get_figma_file']['annotations']['readOnlyHint'] );
		$this->assertFalse( $tools['wp_sync_figma_design_tokens']['annotations']['readOnlyHint'], 'sync writes the cached token option' );
	}

	public function test_ai_integration_tools_have_titles(): void {
		$ai    = new Mcpwp_MCP_AI_Integration();
		$tools = array_column( $ai->get_tools(), null, 'name' );

		$this->assertSame( 'Convert text to speech', $tools['wp_text_to_speech']['title'] );
		$this->assertSame( 'Search stock photos', $tools['wp_search_stock_photos']['title'] );
		$this->assertTrue( $tools['wp_search_stock_photos']['annotations']['openWorldHint'] );
	}

	// ── Guard: a new destructive-sounding tool cannot ship unflagged ─────

	/**
	 * Mirrors the brief's regex verbatim. Any tool whose name matches it must
	 * carry destructiveHint=true, UNLESS Mcpwp_MCP_Tool_Annotations has an
	 * explicit override entry that sets destructiveHint (true or false) —
	 * i.e. it was actually reviewed, not silently defaulted.
	 */
	public function test_destructive_named_tools_are_flagged_or_explicitly_reviewed(): void {
		$pattern = '/delete|remove|revoke|rollback|replace|bulk|push_to_live/';

		foreach ( $this->all_registries() as $registry ) {
			foreach ( $registry->get_tools() as $tool ) {
				$name = $tool['name'];
				if ( ! preg_match( $pattern, $name ) ) {
					continue;
				}

				if ( true === $tool['annotations']['destructiveHint'] ) {
					continue; // Correctly flagged — nothing to check.
				}

				// destructiveHint is false and NOT heuristic-derived-true — this
				// is only acceptable when it was explicitly reviewed and
				// recorded, not silently defaulted.
				$override = Mcpwp_MCP_Tool_Annotations::get_override( $name );
				$this->assertNotNull(
					$override,
					"{$name}: name matches the destructive pattern, destructiveHint is false, and there is no override entry documenting why. " .
					'Either fix the annotation or add a reviewed exception to Mcpwp_MCP_Tool_Annotations::ANNOTATION_OVERRIDES.'
				);
				$this->assertArrayHasKey(
					'destructiveHint',
					$override,
					"{$name}: has an override entry but it does not address destructiveHint — add one explicitly (true or documented false)."
				);
				$this->assertFalse(
					$override['destructiveHint'],
					"{$name}: override says destructiveHint=true but the effective annotation is false — the override is not being applied."
				);
			}
		}
	}

	public function test_custom_registry_tool_still_gets_a_title(): void {
		add_filter(
			'mcpwp_register_tools',
			function ( $tools ) {
				$tools[] = array(
					'name'        => 'acme_list_widgets',
					'description' => 'List Acme widgets.',
					'rest_path'   => '/acme/v1/widgets',
					'method'      => 'GET',
				);
				return $tools;
			}
		);

		$custom = new Mcpwp_Custom_Tool_Registry();
		$tools  = array_column( $custom->get_tools(), null, 'name' );

		$this->assertArrayHasKey( 'acme_list_widgets', $tools );
		$this->assertNotSame( '', $tools['acme_list_widgets']['title'] );
		$this->assertTrue( $tools['acme_list_widgets']['annotations']['readOnlyHint'] );
	}
}
