<?php
/**
 * Regression coverage for #875 review finding L2:
 * wp_create_elementor_template's `type` parameter description listed
 * 'popup' as an example valid type. Verified on a real rig: Elementor Pro's
 * own Popup module gates registration of the 'popup' document type behind a
 * feature/tier check (ElementorPro\Modules\Popup\Module::can_use_popups()),
 * so an install without that feature enabled does not have 'popup' among
 * its actual usable template types — a caller following the tool's own
 * example got a template Elementor could not treat as a real popup, with no
 * warning from the description that this could happen.
 *
 * The fix is documentation-only: the six types that are core Elementor Pro
 * Theme Builder document types (always present on any Elementor Pro
 * activation) are named as the safe examples, and any other Elementor Pro
 * document type — popup and loop-item explicitly called out — is flagged as
 * depending on a feature this tool does not check.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorTemplateTypeDescriptionTest extends TestCase {

	private function get_create_template_tool_definition(): array {
		$registry = new Mcpwp_MCP_Pro_Tools();
		foreach ( $registry->get_tools() as $tool ) {
			if ( 'wp_create_elementor_template' === $tool['name'] ) {
				return $tool;
			}
		}
		$this->fail( 'wp_create_elementor_template tool definition not found' );
	}

	public function test_type_description_does_not_list_popup_as_a_plain_example(): void {
		$tool             = $this->get_create_template_tool_definition();
		$type_description = $tool['inputSchema']['properties']['type']['description'];

		// The always-present core Theme Builder types are still named, so a
		// caller has safe examples to reach for.
		foreach ( array( 'header', 'footer', 'single', 'archive', 'section', 'page' ) as $safe_type ) {
			$this->assertStringContainsString( $safe_type, $type_description );
		}

		// 'popup' may still be MENTIONED (to warn about it), but never as an
		// unqualified example alongside the always-available types — the
		// description must say it depends on a feature, not just list it.
		$this->assertStringContainsString( 'popup', $type_description, 'popup should be named so callers know it exists, but qualified' );
		$this->assertStringContainsString( 'depend', $type_description, 'the install-dependence of non-core types must be stated' );
	}

	/**
	 * Locks the literal defect: 'popup' must never sit in the SAME clause as
	 * the always-available types with no qualifier attached — that bare
	 * grouping (e.g. "header, footer, single, archive, section, page,
	 * popup") is exactly what made the original description misleading.
	 * Every sentence that mentions 'popup' must also carry a word that
	 * flags it as conditional, and the "Always available" clause itself
	 * must not contain it at all.
	 */
	public function test_type_description_never_regresses_to_a_bare_popup_example(): void {
		$tool             = $this->get_create_template_tool_definition();
		$type_description = $tool['inputSchema']['properties']['type']['description'];

		$sentences = preg_split( '/(?<=[.])\s+/', $type_description );
		$qualifiers = array( 'depend', 'not guarant', 'not verif', 'feature', 'may not' );

		foreach ( $sentences as $sentence ) {
			if ( false === stripos( $sentence, 'popup' ) ) {
				continue;
			}
			$this->assertStringNotContainsStringIgnoringCase(
				'Always available',
				$sentence,
				'the "Always available" list must never include popup'
			);

			$qualified = false;
			foreach ( $qualifiers as $qualifier ) {
				if ( false !== stripos( $sentence, $qualifier ) ) {
					$qualified = true;
					break;
				}
			}
			$this->assertTrue( $qualified, 'any sentence naming popup must qualify it as install/feature-dependent: "' . $sentence . '"' );
		}
	}
}
