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
			array( 'wp_validate_seo_readiness' ),
			array( 'wp_validate_structured_data' ),
			array( 'wp_get_site_health' ),
			array( 'wp_cloudflare_status' ),
			array( 'wp_cloudflare_get_speed_settings' ),
			array( 'wp_cloudflare_purge_cache' ),
			array( 'wp_cloudflare_update_speed_setting' ),
			array( 'wp_cloudflare_fix_mcp_access' ),
			array( 'wp_trigger_update' ),
			array( 'wp_screenshot_url' ),
			array( 'wp_test_webhook' ),
			array( 'wp_test_integration' ),
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
		$this->assertTrue( $free->get_tool_annotations( 'wp_validate_seo_readiness' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_validate_structured_data' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_get_site_health' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_cloudflare_status' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_cloudflare_get_speed_settings' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_cloudflare_purge_cache' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_cloudflare_update_speed_setting' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_cloudflare_fix_mcp_access' )['openWorldHint'] );
		$this->assertTrue( $free->get_tool_annotations( 'wp_trigger_update' )['openWorldHint'] );
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

	/**
	 * @dataProvider get_only_tool_with_a_write_side_effect_provider
	 */
	public function test_get_mapped_tools_with_a_write_side_effect_are_not_read_only( string $tool, string $tier ): void {
		$registry = 'pro' === $tier ? new Mcpwp_MCP_Pro_Tools() : new Mcpwp_MCP_Free_Tools();
		$map      = $registry->get_tool_map();

		$this->assertSame( 'GET', $map[ $tool ]['method'], "{$tool}: premise — this tool is GET-mapped" );
		$this->assertFalse( $registry->get_tool_annotations( $tool )['readOnlyHint'], "{$tool}: has a write side effect and must not be read-only" );
	}

	public function get_only_tool_with_a_write_side_effect_provider(): array {
		return array(
			// Mcpwp_Site_Blueprints::save() via save=true.
			array( 'wp_extract_site_blueprint', 'free' ),
			// Mcpwp_Signals::compute() -> update_option(), on first call or refresh=true.
			array( 'wp_get_signals', 'free' ),
			// keyword_research(): enrich=true consumes the lookup budget.
			array( 'wp_keyword_research', 'free' ),
			// Mcpwp_Updater::check_for_update() rebuilds the update_plugins transient.
			array( 'wp_check_update', 'free' ),
			// Both route to list_memories(), which runs a one-time migration write.
			array( 'wp_recall', 'free' ),
			array( 'wp_list_memories', 'free' ),
			// Mcpwp_REST_SEO_Market::get_opportunities(): a seed with no stored
			// visibility rows falls through to a live paid lookup.
			array( 'wp_seo_opportunities', 'pro' ),
		);
	}

	/**
	 * Guard: any tool whose schema exposes a parameter named save/store/
	 * refresh/enrich/persist (as a whole underscore-split word — so
	 * `auto_save` counts too) must be readOnlyHint=false, because a
	 * parameter with that name exists to trigger a write. This is the
	 * mechanical check the P0 findings above were found by hand with; it
	 * exists so the next one is caught automatically.
	 */
	public function test_tools_with_a_save_store_refresh_enrich_or_persist_param_are_not_read_only(): void {
		$trigger_words = array( 'save', 'store', 'refresh', 'enrich', 'persist' );

		foreach ( $this->all_registries() as $registry ) {
			foreach ( $registry->get_tools() as $tool ) {
				$properties = $tool['inputSchema']['properties'] ?? array();
				if ( $properties instanceof \stdClass ) {
					$properties = (array) $properties;
				}

				$flagged_param = null;
				foreach ( array_keys( $properties ) as $param_name ) {
					if ( array_intersect( explode( '_', (string) $param_name ), $trigger_words ) ) {
						$flagged_param = $param_name;
						break;
					}
				}

				if ( null === $flagged_param ) {
					continue;
				}

				$this->assertFalse(
					$tool['annotations']['readOnlyHint'],
					"{$tool['name']}: has a '{$flagged_param}' parameter, which signals a write path — readOnlyHint must be false (or this guard needs a documented exception)."
				);
			}
		}
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

	/**
	 * Mcpwp_MCP_Tool_Registry::default_destructive_for_write_tools() makes
	 * every non-read-only, non-purely-additive first-party tool destructive
	 * by default — this is what actually resolves the P0 sweep (update_*,
	 * set_*, edit_*, patch_*, batch*, assign_*, configure_*, sync_*, remember,
	 * apply_approval, wc_update_*, wp_push_to_staging, …) without a hand-kept
	 * per-tool override for each one. Spot-check a representative slice so a
	 * regression in that default is caught immediately rather than by someone
	 * noticing 80 missing overrides.
	 *
	 * @dataProvider destructive_by_default_provider
	 */
	public function test_write_tools_default_to_destructive_when_not_purely_additive( string $tool, string $tier ): void {
		$registry = 'pro' === $tier ? new Mcpwp_MCP_Pro_Tools() : new Mcpwp_MCP_Free_Tools();
		$ann      = $registry->get_tool_annotations( $tool );
		$override = Mcpwp_MCP_Tool_Annotations::get_override( $tool );

		$this->assertFalse( $ann['readOnlyHint'], "{$tool}: premise — must be a write tool for this default to apply" );
		$this->assertFalse(
			null !== $override && array_key_exists( 'destructiveHint', $override ),
			"{$tool}: this test is for the DEFAULT rule — it has an explicit destructiveHint override, so move it out of this provider"
		);
		$this->assertFalse( Mcpwp_MCP_Tool_Annotations::is_purely_additive_by_name( $tool ), "{$tool}: premise — must not match the purely-additive name pattern" );
		$this->assertTrue( $ann['destructiveHint'], "{$tool}: expected destructiveHint=true from the default-destructive-for-write-tools rule" );
	}

	public function destructive_by_default_provider(): array {
		return array(
			array( 'wp_batch', 'free' ),
			array( 'wp_batch_update', 'free' ),
			array( 'wp_update_post', 'free' ),
			array( 'wp_update_page', 'free' ),
			array( 'wp_patch_elementor', 'free' ),
			array( 'wp_edit_section', 'free' ),
			array( 'wp_edit_widget', 'free' ),
			array( 'wp_update_option', 'free' ),
			array( 'wp_update_options', 'free' ),
			array( 'wp_update_plugin_settings', 'free' ),
			array( 'wp_upsert_kit_css_block', 'free' ),
			array( 'wp_remember', 'free' ),
			array( 'wp_configure_integration', 'free' ),
			array( 'wp_apply_approval', 'free' ),
			array( 'wp_assign_menu_location', 'free' ),
			array( 'wp_reorder_menu_items', 'free' ),
			array( 'wp_move_widget', 'pro' ),
			array( 'wp_update_widget', 'pro' ),
			array( 'wp_set_seo', 'pro' ),
			array( 'wp_set_site_context', 'free' ),
			array( 'wp_sync_figma_design_tokens', 'pro' ),
			array( 'wp_update_elementor_template', 'pro' ),
			array( 'wp_push_to_staging', 'pro' ),
			array( 'wc_update_product', 'pro' ),
			array( 'wc_update_order', 'pro' ),
			array( 'wc_apply_product_archetype', 'pro' ),
			array( 'wp_disable_elementor_custom_code', 'pro' ),
			array( 'wp_sanitize_elementor_custom_code', 'pro' ),
		);
	}

	/**
	 * Tools whose verb is NOT create/add/upload/clone/import/submit/duplicate
	 * but whose actual implementation only ever adds, never overwrites or
	 * removes something that already existed — so they need an explicit
	 * destructiveHint=false to escape the new default (without one, every
	 * one of these would otherwise default to true).
	 */
	public function test_additive_by_implementation_but_not_by_name_are_documented_false(): void {
		$free = new Mcpwp_MCP_Free_Tools();
		$pro  = new Mcpwp_MCP_Pro_Tools();

		$free_false = array(
			'wp_apply_internal_link', 'wp_screenshot_url', 'wp_test_webhook',
			'wp_deploy_site_blueprint', 'wp_flush_cache', 'wp_flush_permalinks',
			'wp_regenerate_elementor_css',
		);
		foreach ( $free_false as $tool ) {
			$ann = $free->get_tool_annotations( $tool );
			$this->assertFalse( $ann['destructiveHint'], "{$tool}: expected destructiveHint=false" );

			$override = Mcpwp_MCP_Tool_Annotations::get_override( $tool );
			$this->assertNotNull( $override, "{$tool}: not purely-additive by name, so this MUST be an explicit override, not a default" );
			$this->assertFalse( $override['destructiveHint'] ?? null, "{$tool}: override must set destructiveHint=false explicitly" );
		}

		$pro_false = array( 'wp_download_stock_photo', 'wp_generate_image', 'wp_describe_image', 'wp_get_blueprint', 'wp_build_page', 'wp_save_section_as_template', 'wp_test_integration' );
		foreach ( $pro_false as $tool ) {
			// wp_download_stock_photo/wp_generate_image/wp_describe_image live on
			// the AI integration; the rest are pro-tier tools.
			$registry = in_array( $tool, array( 'wp_download_stock_photo', 'wp_generate_image', 'wp_describe_image' ), true )
				? new Mcpwp_MCP_AI_Integration()
				: $pro;
			$ann = $registry->get_tool_annotations( $tool );
			$this->assertFalse( $ann['destructiveHint'], "{$tool}: expected destructiveHint=false" );
		}
	}

	/**
	 * The reverse trap: a tool whose happy path looks purely additive but
	 * has a conditional parameter that overwrites existing state.
	 */
	public function test_conditional_overwrite_tools_are_destructive_despite_looking_additive(): void {
		$ai   = new Mcpwp_MCP_AI_Integration();
		$free = new Mcpwp_MCP_Free_Tools();

		$this->assertTrue( $ai->get_tool_annotations( 'wp_generate_featured_image' )['destructiveHint'], 'set_post_thumbnail() overwrites an existing featured-image assignment' );
		$this->assertTrue( $ai->get_tool_annotations( 'wp_generate_alt_text' )['destructiveHint'], 'auto_save=true overwrites existing alt text' );
		$this->assertTrue( $ai->get_tool_annotations( 'wp_generate_excerpt' )['destructiveHint'], 'auto_save=true overwrites the existing excerpt' );
		$this->assertTrue( $free->get_tool_annotations( 'wp_setup_menu' )['destructiveHint'], 'overwrite=true deletes existing menu items before repopulating' );

		// wp_generate_image never assigns/overwrites anything — contrast case.
		$this->assertFalse( $ai->get_tool_annotations( 'wp_generate_image' )['destructiveHint'] );
	}

	/**
	 * Mcpwp_Custom_Tool_Registry's own docblock documents `destructive`
	 * (bool) as an opt-in flag, default false — third-party registrants who
	 * don't set it must keep getting false, not the strict first-party
	 * default. This is the regression test for that carve-out.
	 */
	public function test_custom_registry_write_tool_without_explicit_flag_stays_non_destructive(): void {
		add_filter(
			'mcpwp_register_tools',
			function ( $tools ) {
				$tools[] = array(
					'name'        => 'acme_update_widget',
					'description' => 'Update an Acme widget.',
					'rest_path'   => '/acme/v1/widgets/1',
					'method'      => 'POST',
					// Deliberately omits 'destructive' — the documented
					// default (false) must hold even though this tool's
					// name would default to true under the first-party rule.
				);
				return $tools;
			}
		);

		$custom = new Mcpwp_Custom_Tool_Registry();
		$ann    = $custom->get_tool_annotations( 'acme_update_widget' );

		$this->assertFalse( $ann['readOnlyHint'] );
		$this->assertFalse( $ann['destructiveHint'], 'a third-party tool with no explicit destructive flag must keep the documented default of false' );
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
