<?php
/**
 * Coverage for #911 — forgiving MCP tool arguments: an unknown top-level
 * argument key that is an unambiguous near-miss of a real param is silently
 * remapped (and noted, for the caller to learn); everything else unresolved
 * is refused before dispatch, naming the unknown key, its closest valid
 * key(s), and the tool's required parameters.
 *
 * Includes the fixes from PR #986's round-1 adversarial review:
 *  - BLOCK-1: the id/page_id/post_id and content/content_html synonym
 *    groups were removed entirely — PARAM_SYNONYM_GROUPS is empty (see its
 *    docblock). `id` means a different resource on ~105 different tools;
 *    mapping a near-miss onto it assumed a uniform meaning that does not
 *    exist. Tests below prove the group is gone, not just that it "still
 *    works for the safe case" — the whole point is that it is no longer
 *    reachable at all.
 *  - BLOCK-2: a near-miss whose sole candidate is already supplied under
 *    its real name is dropped ONLY when the two values are equal; a
 *    conflicting value is refused, never silently picked.
 *  - WARN-1: PRIVILEGED_PARAM_MARKERS is substrings now, not exact names —
 *    covers force/status/allow/purge/overwrite/publish/enabled/noindex
 *    anywhere in a real param name.
 *  - WARN-2 + NIT: pricing (message_spend_price()) and idempotency hashing
 *    both canonicalize arguments the same way dispatch does, via the shared
 *    canonical_tool_arguments().
 *  - NIT: a stray integer/list-shaped argument key is refused again, not
 *    silently skipped.
 *
 * Three layers, matching this file's siblings:
 *  - Mcpwp_REST_MCP::remap_near_miss_params() / validate_tool_arguments() /
 *    canonical_tool_arguments() / message_spend_price() exercised directly
 *    via reflection (ParamAliasResolutionTest.php's style) for edge cases a
 *    real tool schema does not naturally produce;
 *  - real tools dispatched through handle_mcp() (ParamAliasesDispatchTest.php's
 *    style) for the cases production schemas do produce;
 *  - a handful of WARN-1's confirmed real near-misses, checked directly
 *    against their real tool schemas (mirrors the reviewer's own probe.php).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ForgivingParamsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_current_user']        = 2;
		$GLOBALS['mcpwp_test_filters']             = array();
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();

		// wp_bulk_find_replace is gated behind the 'elementor' capability at
		// tools/list time (Mcpwp_MCP_Free_Tools::get_required_capabilities());
		// without it, get_all_tools() filters the tool out entirely and
		// validate_tool_arguments() never finds a schema to check against.
		// Same constant, same style as ParamAliasesDispatchTest.php and its
		// Elementor-suite siblings.
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
	}

	// -------------------------------------------------------------------
	// Unit layer: remap_near_miss_params() via reflection.
	// -------------------------------------------------------------------

	private function remap( array $known_params, array $arguments ): array {
		$controller = new Mcpwp_REST_MCP();
		$method     = new ReflectionMethod( Mcpwp_REST_MCP::class, 'remap_near_miss_params' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $known_params, $arguments );
	}

	public function test_exact_match_known_params_are_never_touched(): void {
		$out = $this->remap( array( 'id', 'force' ), array( 'id' => 5, 'force' => true ) );

		$this->assertSame( array( 'id' => 5, 'force' => true ), $out['arguments'] );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertSame( array(), $out['refused'] );
	}

	public function test_camel_case_key_maps_to_its_unique_snake_case_param(): void {
		$out = $this->remap( array( 'post_id', 'prompt' ), array( 'postId' => 42, 'prompt' => 'hi' ) );

		// assertEquals (not assertSame): mapping a key does not promise to
		// preserve original key order, only key/value identity — same rule
		// ParamAliasResolutionTest.php documents for resolve_param_aliases().
		$this->assertEquals( array( 'post_id' => 42, 'prompt' => 'hi' ), $out['arguments'] );
		$this->assertSame(
			array( array( 'from' => 'postId', 'to' => 'post_id', 'reason' => 'near_miss' ) ),
			$out['fixes']
		);
		$this->assertSame( array(), $out['refused'] );
	}

	/**
	 * BLOCK-1 regression: the id/page_id/post_id synonym group is REMOVED,
	 * not merely narrowed. Before the fix, this exact case (schema carries
	 * only post_id) mapped page_id -> post_id via PARAM_SYNONYM_GROUPS. It
	 * must not any more — page_id now has zero candidates, same as any
	 * other genuinely unrelated word.
	 */
	public function test_page_id_no_longer_maps_to_post_id_even_when_schema_has_only_post_id(): void {
		$out = $this->remap( array( 'post_id', 'prompt' ), array( 'page_id' => 9, 'prompt' => 'hi' ) );

		$this->assertSame( array( 'page_id' => 9, 'prompt' => 'hi' ), $out['arguments'], 'page_id must be left completely untouched — zero candidates, not a mapped near-miss.' );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertSame( array(), $out['refused'], 'Zero candidates, not a refused near-miss — it is simply unknown.' );
	}

	/**
	 * BLOCK-1 regression: content_html no longer maps to content either —
	 * the schema evidence (course/lesson/quiz/event descriptions, raw block
	 * markup, translations) showed "content" does not uniformly mean "post
	 * content", so this pairing was dropped along with id/page_id/post_id.
	 */
	public function test_content_html_no_longer_maps_to_content(): void {
		$out = $this->remap( array( 'content' ), array( 'content_html' => '<p>hi</p>' ) );

		$this->assertSame( array( 'content_html' => '<p>hi</p>' ), $out['arguments'] );
		$this->assertSame( array(), $out['fixes'] );
	}

	public function test_param_synonym_groups_table_is_empty(): void {
		$this->assertSame( array(), Mcpwp_REST_MCP::PARAM_SYNONYM_GROUPS, 'BLOCK-1: both groups were removed, not narrowed.' );
	}

	/**
	 * A tie is still possible and still refused — just only via the generic
	 * normalizer now, since the synonym-group table is empty. A tool whose
	 * schema (implausibly, but not impossibly for a custom/third-party tool)
	 * carries two case/separator-distinct spellings of the same concept
	 * leaves an ambiguous near-miss unresolved rather than guessing.
	 */
	public function test_tie_between_two_case_variant_known_params_is_never_mapped(): void {
		$out = $this->remap( array( 'userId', 'user_id' ), array( 'USER_ID' => 3 ) );

		$this->assertSame( array( 'USER_ID' => 3 ), $out['arguments'], 'Unresolved — the original key must survive untouched.' );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertArrayHasKey( 'USER_ID', $out['refused'] );
		$this->assertSame( 'ambiguous', $out['refused']['USER_ID']['reason'] );
	}

	public function test_privileged_candidate_is_refused_not_mapped(): void {
		$out = $this->remap( array( 'id', 'force' ), array( 'Force' => true ) );

		$this->assertSame( array( 'Force' => true ), $out['arguments'], 'A privileged near-miss target must never be auto-mapped.' );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertArrayHasKey( 'Force', $out['refused'] );
		$this->assertSame( 'force', $out['refused']['Force']['candidate'] );
		$this->assertSame( 'privileged', $out['refused']['Force']['reason'] );
	}

	public function test_a_privileged_param_named_exactly_is_untouched_and_never_flagged(): void {
		// The gate is on the MAPPING target, not on a key that is already
		// the real, exact name — a caller sending 'force' on purpose is not
		// second-guessed.
		$out = $this->remap( array( 'id', 'force' ), array( 'id' => 1, 'force' => true ) );

		$this->assertSame( array( 'id' => 1, 'force' => true ), $out['arguments'] );
		$this->assertSame( array(), $out['refused'] );
	}

	/**
	 * WARN-1: PRIVILEGED_PARAM_MARKERS is substrings, not exact names — a
	 * near-miss of a schema param whose name merely CONTAINS a marker word
	 * (postStatus, forceTypeChange, ...) is refused too, not just an exact
	 * "force"/"status".
	 */
	public function test_privileged_substring_marker_catches_a_compound_param_name(): void {
		$out = $this->remap( array( 'id', 'post_status' ), array( 'postStatus' => 'publish' ) );

		$this->assertSame( array( 'postStatus' => 'publish' ), $out['arguments'] );
		$this->assertArrayHasKey( 'postStatus', $out['refused'] );
		$this->assertSame( 'post_status', $out['refused']['postStatus']['candidate'] );
		$this->assertSame( 'privileged', $out['refused']['postStatus']['reason'] );
	}

	/**
	 * BLOCK-2: caller supplies the near-miss AND the real key with the SAME
	 * value (a case-only variant now, since page_id/id no longer applies) —
	 * the redundant near-miss is dropped silently, the real key's value wins.
	 */
	public function test_caller_supplying_both_the_near_miss_and_the_real_key_with_equal_values_drops_the_near_miss(): void {
		$out = $this->remap( array( 'id' ), array( 'id' => 5, 'Id' => 5 ) );

		$this->assertSame( array( 'id' => 5 ), $out['arguments'], 'The real key wins; the redundant near-miss is dropped, not left behind.' );
		$this->assertSame(
			array( array( 'from' => 'Id', 'to' => 'id', 'reason' => 'duplicate_dropped' ) ),
			$out['fixes']
		);
		$this->assertSame( array(), $out['refused'], 'A dropped duplicate is not a refusal — the call still succeeds on "id".' );
	}

	/**
	 * BLOCK-2: a numeric string and a numeric value for the same concept are
	 * still "equal" — the coercion must not read "5" vs 5 as a conflict.
	 */
	public function test_caller_supplying_both_with_equal_values_of_different_php_types_still_drops_silently(): void {
		$out = $this->remap( array( 'id' ), array( 'id' => 5, 'Id' => '5' ) );

		$this->assertSame( array( 'id' => 5 ), $out['arguments'] );
		$this->assertSame( array(), $out['refused'] );
	}

	/**
	 * BLOCK-2: caller supplies the near-miss and the real key with DIFFERENT
	 * values — never silently pick one. Refused as a conflict, not mapped,
	 * not dropped.
	 */
	public function test_caller_supplying_both_with_conflicting_values_is_refused_not_guessed(): void {
		$out = $this->remap( array( 'id' ), array( 'id' => 5, 'Id' => 9 ) );

		$this->assertSame( array( 'id' => 5, 'Id' => 9 ), $out['arguments'], 'Neither value is picked — both survive untouched for the caller to see in the refusal.' );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertArrayHasKey( 'Id', $out['refused'] );
		$this->assertSame( 'id', $out['refused']['Id']['candidate'] );
		$this->assertSame( 'conflict', $out['refused']['Id']['reason'] );
	}

	/**
	 * BLOCK-2: "this also settles which key wins when two near-misses target
	 * the same param" — two DIFFERENT near-miss spellings of the same real
	 * key, with the same value, collapse to a single fix; neither is the
	 * caller's "real" key, so this proves the settling logic (value-compare
	 * against whatever is in $arguments so far) works even when the first
	 * near-miss processed is the one that ends up occupying the real slot.
	 */
	public function test_two_near_misses_of_the_same_param_with_equal_values_are_settled_not_conflicted(): void {
		$out = $this->remap( array( 'id' ), array( 'Id' => 5, 'ID' => 5 ) );

		// The first near-miss processed (insertion order) fills the real
		// "id" slot; the second then compares against IT (not against the
		// caller's real key, since there wasn't one) and — equal value —
		// is dropped as a duplicate rather than refused as a conflict.
		// Neither is ever silently PREFERRED over the other: this is the
		// "settling" BLOCK-2 asks for, not a coin flip.
		$this->assertSame( array( 'id' => 5 ), $out['arguments'] );
		$this->assertCount( 2, $out['fixes'], 'One near_miss mapping (Id -> id) plus one duplicate_dropped (ID, equal value) — not a silently discarded second fix.' );
		$this->assertSame( 'near_miss', $out['fixes'][0]['reason'] );
		$this->assertSame( 'duplicate_dropped', $out['fixes'][1]['reason'] );
		$this->assertSame( array(), $out['refused'], 'Equal values never conflict, regardless of which key arrived "first".' );
	}

	public function test_two_near_misses_of_the_same_param_with_different_values_conflict(): void {
		$out = $this->remap( array( 'id' ), array( 'Id' => 5, 'ID' => 9 ) );

		// The first near-miss (Id) fills "id"; the second (ID) then
		// conflicts with IT, exactly as it would have conflicted with a
		// caller-supplied real "id" — the settling rule is the same either
		// way.
		$this->assertEquals( array( 'id' => 5, 'ID' => 9 ), $out['arguments'] );
		$this->assertArrayHasKey( 'ID', $out['refused'] );
		$this->assertSame( 'conflict', $out['refused']['ID']['reason'] );
	}

	public function test_a_key_with_zero_candidates_is_left_for_the_fuzzy_matcher(): void {
		$out = $this->remap( array( 'title' ), array( 'totally_unrelated_key' => 'x' ) );

		$this->assertSame( array( 'totally_unrelated_key' => 'x' ), $out['arguments'] );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertSame( array(), $out['refused'], 'Zero candidates is genuinely unknown, not a refused near-miss — validate_tool_arguments() falls back to its fuzzy did-you-mean for this case.' );
	}

	/**
	 * NIT: a non-string (integer/list-index) key is never a mapping
	 * candidate — remap_near_miss_params() must leave it alone (not crash,
	 * not attempt to normalize it) so validate_tool_arguments()'s final
	 * unknown-parameter check is the thing that catches it.
	 */
	public function test_a_non_string_key_is_left_untouched_by_the_remapper(): void {
		$out = $this->remap( array( 'id' ), array( 0 => 'x', 'id' => 1 ) );

		$this->assertSame( array( 0 => 'x', 'id' => 1 ), $out['arguments'] );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertSame( array(), $out['refused'] );
	}

	// -------------------------------------------------------------------
	// Unit layer: validate_tool_arguments() via reflection.
	// -------------------------------------------------------------------

	private function validate( string $tool_name, array $arguments ): array {
		$controller = new Mcpwp_REST_MCP();
		$method     = new ReflectionMethod( Mcpwp_REST_MCP::class, 'validate_tool_arguments' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $tool_name, $arguments );
	}

	public function test_missing_required_parameters_are_all_listed_not_just_the_first(): void {
		// wp_set_featured_image requires both id and media_id.
		$out = $this->validate( 'wp_set_featured_image', array() );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'id', $out['error'] );
		$this->assertStringContainsString( 'media_id', $out['error'] );
	}

	public function test_a_tool_schema_declaring_additional_properties_true_is_left_alone(): void {
		$controller = new Mcpwp_REST_MCP();
		$cache_prop = new ReflectionProperty( Mcpwp_REST_MCP::class, 'tools_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue(
			$controller,
			array(
				array(
					'name'        => 'free_form_test_tool',
					'inputSchema' => array(
						'type'                 => 'object',
						'properties'           => array( 'id' => array( 'type' => 'number' ) ),
						'additionalProperties' => true,
					),
				),
			)
		);

		$method = new ReflectionMethod( Mcpwp_REST_MCP::class, 'validate_tool_arguments' );
		$method->setAccessible( true );
		$out = $method->invoke( $controller, 'free_form_test_tool', array( 'id' => 1, 'whatever_extra_key' => 'kept as-is' ) );

		$this->assertNull( $out['error'] );
		$this->assertSame( array(), $out['fixes'] );
		$this->assertSame( array( 'id' => 1, 'whatever_extra_key' => 'kept as-is' ), $out['arguments'] );
	}

	/**
	 * NIT: an integer key is refused (as before this PR), not silently
	 * skipped just because it is not a string — even alongside an otherwise
	 * complete, valid call.
	 */
	public function test_a_stray_integer_key_alongside_a_valid_call_is_refused(): void {
		$out = $this->validate( 'wp_delete_page', array( 0 => 'x', 'id' => 1 ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: 0', $out['error'] );
	}

	/**
	 * NIT (continued): a purely list-shaped arguments value (every key an
	 * integer index) is refused the same way — the first stray index (0)
	 * is reported unknown once id is also missing.
	 */
	public function test_list_shaped_arguments_are_refused(): void {
		$out = $this->validate( 'wp_delete_page', array( 'x', 'y', 'z' ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Missing required parameter: id', $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: 0', $out['error'] );
	}

	/**
	 * A call missing a required param AND carrying an unresolved unknown key
	 * reports BOTH in one message — one round trip teaches the whole gap,
	 * not just the first problem found (BLOCK-1's wp_delete_term/wp_delete_webhook
	 * regression tests below rely on exactly this: page_id/post_id no longer
	 * silently satisfies the "id" requirement, so both facts must surface).
	 */
	public function test_missing_required_and_unknown_parameter_are_both_reported_together(): void {
		$out = $this->validate( 'wp_delete_webhook', array( 'page_id' => 3 ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Missing required parameter: id', $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: page_id', $out['error'] );
	}

	// -------------------------------------------------------------------
	// BLOCK-1 regressions on real tool schemas (the reviewer's probe cases).
	// -------------------------------------------------------------------

	public function test_block1_wp_delete_term_post_id_is_refused_not_mapped_to_id(): void {
		// Before the fix: post_id silently mapped to id via the removed
		// synonym group, and this call would have deleted TERM 12.
		$out = $this->validate( 'wp_delete_term', array( 'post_id' => 12, 'taxonomy' => 'category' ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Missing required parameter: id', $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: post_id', $out['error'] );
	}

	public function test_block1_wp_delete_term_post_id_alongside_real_id_is_still_refused(): void {
		// id is supplied for real this time — post_id must still be refused
		// as unknown, not silently dropped as though it meant the same id.
		$out = $this->validate( 'wp_delete_term', array( 'id' => 5, 'taxonomy' => 'category', 'post_id' => 9 ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: post_id', $out['error'] );
	}

	public function test_block1_wp_delete_webhook_page_id_is_refused(): void {
		$out = $this->validate( 'wp_delete_webhook', array( 'page_id' => 3 ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: page_id', $out['error'] );
	}

	public function test_block2_wp_delete_media_id_and_post_id_together_is_refused(): void {
		$out = $this->validate( 'wp_delete_media', array( 'id' => 44, 'post_id' => 10 ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: post_id', $out['error'] );
	}

	// -------------------------------------------------------------------
	// WARN-1: real near-misses of real schema params, confirmed privileged.
	// -------------------------------------------------------------------

	public function test_warn1_force_type_change_near_miss_is_refused(): void {
		$out = $this->validate( 'wp_update_option', array( 'key' => 'x', 'value' => 'y', 'forceTypeChange' => true ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: forceTypeChange', $out['error'] );
		$this->assertStringContainsString( 'privileged', $out['error'] );
	}

	public function test_warn1_post_status_near_miss_is_refused(): void {
		$out = $this->validate( 'wp_deploy_site_blueprint', array( 'id' => 'saas', 'postStatus' => 'publish' ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: postStatus', $out['error'] );
		$this->assertStringContainsString( 'privileged', $out['error'] );
	}

	/**
	 * wp_set_blocks' real schema carries `allow_restricted_blocks` (verified
	 * against the reviewer's tools2.json schema dump) — its own tool is
	 * gated behind the 'gutenberg' capability at tools/list time
	 * (function_exists('register_block_type')), which this PHPUnit harness's
	 * WordPress fakes never satisfy (no wp-admin/includes/plugin.php-style
	 * block registrar), so it is exercised against remap_near_miss_params()
	 * directly with its real property name rather than through the
	 * unreachable full tool lookup.
	 */
	public function test_warn1_allow_restricted_blocks_near_miss_is_refused(): void {
		$out = $this->remap( array( 'id', 'content', 'allow_restricted_blocks' ), array( 'allowRestrictedBlocks' => true ) );

		$this->assertSame( array( 'allowRestrictedBlocks' => true ), $out['arguments'] );
		$this->assertArrayHasKey( 'allowRestrictedBlocks', $out['refused'] );
		$this->assertSame( 'allow_restricted_blocks', $out['refused']['allowRestrictedBlocks']['candidate'] );
		$this->assertSame( 'privileged', $out['refused']['allowRestrictedBlocks']['reason'] );
	}

	public function test_warn1_allow_compounding_near_miss_is_refused(): void {
		$out = $this->validate( 'wp_bulk_find_replace', array( 'search' => 'a', 'replace' => 'b', 'allowCompounding' => true ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: allowCompounding', $out['error'] );
		$this->assertStringContainsString( 'privileged', $out['error'] );
	}

	public function test_warn1_purge_everything_near_miss_is_refused(): void {
		$out = $this->validate( 'wp_cloudflare_purge_cache', array( 'purgeEverything' => true ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: purgeEverything', $out['error'] );
		$this->assertStringContainsString( 'privileged', $out['error'] );
	}

	public function test_warn1_overwrite_case_variant_is_refused(): void {
		$out = $this->validate( 'wp_setup_menu', array( 'name' => 'Main', 'OverWrite' => true ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: OverWrite', $out['error'] );
		$this->assertStringContainsString( 'privileged', $out['error'] );
	}

	public function test_warn1_enabled_case_variant_is_refused(): void {
		$out = $this->validate( 'wp_update_rate_limit', array( 'Enabled' => true ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: Enabled', $out['error'] );
		$this->assertStringContainsString( 'privileged', $out['error'] );
	}

	/**
	 * wp_set_noindex's real schema requires BOTH `id` and `noindex`
	 * (verified against tools2.json). Its own tool is gated behind the
	 * 'seo' capability (an active Yoast/RankMath/AIOSEO/SEOPress), which
	 * this harness never satisfies, so its exact schema shape is injected
	 * directly via the same tools_cache technique
	 * test_a_tool_schema_declaring_additional_properties_true_is_left_alone()
	 * uses, and run through the real validate_tool_arguments() — proving
	 * the combined "missing + privileged-refused" message this WARN-1 case
	 * specifically calls out: refusing the mapping means `noindex` is ALSO
	 * reported missing, since the value never landed under its real name.
	 */
	public function test_warn1_no_index_near_miss_is_refused_and_still_reports_the_now_missing_required(): void {
		$controller = new Mcpwp_REST_MCP();
		$cache_prop = new ReflectionProperty( Mcpwp_REST_MCP::class, 'tools_cache' );
		$cache_prop->setAccessible( true );
		$cache_prop->setValue(
			$controller,
			array(
				array(
					'name'        => 'wp_set_noindex',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'id'      => array( 'type' => 'number' ),
							'noindex' => array( 'type' => 'boolean' ),
						),
						'required'   => array( 'id', 'noindex' ),
					),
				),
			)
		);

		$method = new ReflectionMethod( Mcpwp_REST_MCP::class, 'validate_tool_arguments' );
		$method->setAccessible( true );
		$out = $method->invoke( $controller, 'wp_set_noindex', array( 'id' => 5, 'noIndex' => true ) );

		$this->assertNotNull( $out['error'] );
		$this->assertStringContainsString( 'Missing required parameter: noindex', $out['error'] );
		$this->assertStringContainsString( 'Unknown parameter: noIndex', $out['error'] );
		$this->assertStringContainsString( 'privileged', $out['error'] );
	}

	/**
	 * wc_create_product's real schema carries `stock_status` (verified
	 * against tools2.json) — its own tool is gated behind the 'woocommerce'
	 * capability (class_exists('WooCommerce')), which this harness never
	 * satisfies, so it is exercised directly against remap_near_miss_params().
	 */
	public function test_warn1_stock_status_near_miss_is_refused(): void {
		$out = $this->remap( array( 'name', 'stock_status' ), array( 'stockStatus' => 'instock' ) );

		$this->assertSame( array( 'stockStatus' => 'instock' ), $out['arguments'] );
		$this->assertArrayHasKey( 'stockStatus', $out['refused'] );
		$this->assertSame( 'stock_status', $out['refused']['stockStatus']['candidate'] );
		$this->assertSame( 'privileged', $out['refused']['stockStatus']['reason'] );
	}

	// -------------------------------------------------------------------
	// Unit layer: canonical_tool_arguments() / message_spend_price() via
	// reflection — WARN-2 and its NIT.
	// -------------------------------------------------------------------

	private function canonicalArgs( string $tool_name, array $mapping, array $arguments ): array {
		$controller = new Mcpwp_REST_MCP();
		$method     = new ReflectionMethod( Mcpwp_REST_MCP::class, 'canonical_tool_arguments' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $tool_name, $mapping, $arguments );
	}

	public function test_canonical_tool_arguments_applies_the_same_remap_dispatch_uses(): void {
		$mapping = array( 'method' => 'DELETE', 'route' => '/pages/{id}' );
		$out     = $this->canonicalArgs( 'wp_delete_page', $mapping, array( 'Id' => 7 ) );

		$this->assertSame( array( 'id' => 7 ), $out );
	}

	/**
	 * WARN-2's NIT: two spellings of the same call must hash identically for
	 * idempotency, which is exactly what "canonicalize before hashing" buys —
	 * prove it by hashing both forms with canonical_tool_arguments() in the
	 * loop, the same way handle_tools_call_idempotent() now does.
	 */
	public function test_canonical_tool_arguments_makes_two_spellings_hash_identically(): void {
		$mapping = array( 'method' => 'DELETE', 'route' => '/pages/{id}' );

		$hash_a = hash( 'sha256', 'wp_delete_page|' . wp_json_encode( $this->canonicalArgs( 'wp_delete_page', $mapping, array( 'Id' => 7 ) ) ) );
		$hash_b = hash( 'sha256', 'wp_delete_page|' . wp_json_encode( $this->canonicalArgs( 'wp_delete_page', $mapping, array( 'id' => 7 ) ) ) );

		$this->assertSame( $hash_b, $hash_a );
	}

	private function messageSpendPrice( array $message, array $tool_map ): int {
		$controller = new Mcpwp_REST_MCP();
		$method     = new ReflectionMethod( Mcpwp_REST_MCP::class, 'message_spend_price' );
		$method->setAccessible( true );
		return $method->invoke( $controller, $message, $tool_map );
	}

	/**
	 * WARN-2: a batch-priced wp_batch call must be priced on its REMAPPED
	 * operations key, or an un-normalized "Operations" prices as though the
	 * call carried none — undermining the whole-batch-refused-before-any-of-
	 * it-runs guarantee for exactly the calls that carry the most weight.
	 */
	public function test_warn2_batch_operations_key_case_variant_is_priced_correctly(): void {
		if ( ! class_exists( 'Mcpwp_REST_Batch' ) || ! class_exists( 'Mcpwp_Operation_Weights' ) ) {
			$this->markTestSkipped( 'Batch pricing classes not present in this build.' );
		}

		$tool_map = array(
			'wp_batch' => array( 'method' => 'POST', 'route' => '/batch' ),
		);

		$operations = array(
			array( 'method' => 'POST', 'path' => '/posts' ),
			array( 'method' => 'POST', 'path' => '/posts' ),
			array( 'method' => 'POST', 'path' => '/posts' ),
		);

		$canonical_price = $this->messageSpendPrice(
			array( 'method' => 'tools/call', 'params' => array( 'name' => 'wp_batch', 'arguments' => array( 'operations' => $operations ) ) ),
			$tool_map
		);
		$camel_price = $this->messageSpendPrice(
			array( 'method' => 'tools/call', 'params' => array( 'name' => 'wp_batch', 'arguments' => array( 'Operations' => $operations ) ) ),
			$tool_map
		);

		$this->assertGreaterThan( 0, $canonical_price, 'Sanity: a real 3-operation batch must not price at 0.' );
		$this->assertSame( $canonical_price, $camel_price, 'A case-variant key must price identically to the canonical one — it must reach the same operations list.' );
	}

	/**
	 * WARN-2 (path-placeholder angle): if the near-miss key is not remapped
	 * before resolve_tool_dispatch() runs, the {id} placeholder is never
	 * substituted, so the route priced is the literal template — not the
	 * real, weighted route.
	 */
	public function test_warn2_path_placeholder_case_variant_is_priced_under_the_real_route(): void {
		if ( ! class_exists( 'Mcpwp_Operation_Weights' ) ) {
			$this->markTestSkipped( 'Mcpwp_Operation_Weights not present in this build.' );
		}

		$tool_map = array(
			'wp_delete_page' => array( 'method' => 'DELETE', 'route' => '/pages/{id}' ),
		);

		$canonical_price = $this->messageSpendPrice(
			array( 'method' => 'tools/call', 'params' => array( 'name' => 'wp_delete_page', 'arguments' => array( 'id' => 7 ) ) ),
			$tool_map
		);
		$case_variant_price = $this->messageSpendPrice(
			array( 'method' => 'tools/call', 'params' => array( 'name' => 'wp_delete_page', 'arguments' => array( 'Id' => 7 ) ) ),
			$tool_map
		);

		$this->assertSame( $canonical_price, $case_variant_price, 'A case-variant "Id" must price the same as "id" — both must resolve the {id} placeholder before pricing.' );
	}

	// -------------------------------------------------------------------
	// End-to-end layer: real tools dispatched through handle_mcp().
	// wp_delete_page: id (required) + force (privileged) — no param_aliases
	// declared, so #831's per-tool alias table never intercepts these keys
	// before they reach the #911 remap.
	// -------------------------------------------------------------------

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

	public function test_wp_delete_page_maps_case_variant_id_near_miss_and_dispatches(): void {
		$data = $this->call( 'wp_delete_page', array( 'Id' => 77 ) );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertFalse( $data['result']['isError'], isset( $data['result']['structuredContent']['error'] ) ? $data['result']['structuredContent']['error']['message'] : '' );

		$this->assertNotEmpty( $GLOBALS['mcpwp_test_dispatched_requests'], 'The near-miss must have resolved to "id" and reached dispatch.' );
		$req = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$this->assertSame( '/mcpwp/v1/pages/77', $req->get_route() );
		$this->assertNull( $req->get_param( 'Id' ) );

		$this->assertSame(
			array( array( 'from' => 'Id', 'to' => 'id', 'reason' => 'near_miss' ) ),
			$data['result']['_meta']['param_fixes'],
			'The agent must be told what was fixed, so it learns the real name.'
		);
	}

	public function test_wp_delete_page_page_id_is_now_refused_not_mapped(): void {
		// BLOCK-1: this used to map page_id -> id and delete the page. It
		// must now be refused — page_id is simply unknown for wp_delete_page.
		$data = $this->call( 'wp_delete_page', array( 'page_id' => 77 ) );

		$this->assertTrue( $data['result']['isError'] );
		$message = $data['result']['structuredContent']['error']['message'];
		$this->assertStringContainsString( 'page_id', $message );
		$this->assertStringContainsString( 'Missing required parameter: id', $message );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_requests'], 'A refused call must never reach dispatch.' );
	}

	public function test_wp_delete_page_exact_match_is_unaffected_and_carries_no_param_fixes_note(): void {
		$data = $this->call( 'wp_delete_page', array( 'id' => 5 ) );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertFalse( $data['result']['isError'] );
		$this->assertArrayNotHasKey( 'param_fixes', $data['result']['_meta'] ?? array() );
	}

	public function test_wp_delete_page_privileged_near_miss_force_is_refused_before_dispatch(): void {
		// "Force" is an unambiguous case-only near-miss of the real "force"
		// param — but force is destructive/privileged, so it must be refused,
		// not guessed.
		$data = $this->call( 'wp_delete_page', array( 'id' => 5, 'Force' => true ) );

		$this->assertArrayNotHasKey( 'error', $data, 'A resolved tool with a bad call is an isError result, not a protocol error.' );
		$this->assertTrue( $data['result']['isError'] );

		$message = $data['result']['structuredContent']['error']['message'];
		$this->assertStringContainsString( 'Force', $message );
		$this->assertStringContainsString( 'force', $message );
		$this->assertStringContainsString( 'privileged', $message );
		// Required params still surface, per #911's "or refuse with the fix".
		$this->assertStringContainsString( 'id', $message );

		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_requests'], 'A refused call must never reach dispatch.' );
	}

	public function test_wp_delete_page_caller_supplying_both_id_case_variants_with_equal_values_drops_the_alias(): void {
		$data = $this->call( 'wp_delete_page', array( 'id' => 5, 'Id' => 5 ) );

		$this->assertArrayNotHasKey( 'error', $data );
		$this->assertFalse( $data['result']['isError'] );

		$req = end( $GLOBALS['mcpwp_test_dispatched_requests'] );
		$this->assertSame( '/mcpwp/v1/pages/5', $req->get_route() );

		$this->assertSame(
			array( array( 'from' => 'Id', 'to' => 'id', 'reason' => 'duplicate_dropped' ) ),
			$data['result']['_meta']['param_fixes']
		);
	}

	/**
	 * BLOCK-2 end-to-end: caller supplies the near-miss and the real key
	 * with DIFFERENT values — refused as a conflict before dispatch, never
	 * silently deleting whichever id happened to win.
	 */
	public function test_wp_delete_page_caller_supplying_conflicting_id_values_is_refused(): void {
		$data = $this->call( 'wp_delete_page', array( 'id' => 5, 'Id' => 9 ) );

		$this->assertTrue( $data['result']['isError'] );
		$message = $data['result']['structuredContent']['error']['message'];
		$this->assertStringContainsString( 'Id', $message );
		$this->assertStringContainsString( 'already supplied with a different value', $message );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_requests'], 'Neither id may be picked — nothing may be deleted on a conflicting call.' );
	}

	public function test_wp_delete_page_a_truly_unknown_key_is_refused_with_did_you_mean_and_required_list(): void {
		$data = $this->call( 'wp_delete_page', array( 'id' => 5, 'pge_id' => 5 ) );

		$this->assertTrue( $data['result']['isError'] );
		$message = $data['result']['structuredContent']['error']['message'];
		$this->assertStringContainsString( 'pge_id', $message );
		$this->assertStringContainsString( 'Required:', $message );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_requests'] );
	}
}
