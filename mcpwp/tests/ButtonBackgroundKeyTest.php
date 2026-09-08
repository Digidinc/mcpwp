<?php
/**
 * Regression coverage for #789 — a key that validated clean and produced no CSS.
 *
 * Reported: `button_background_color` set on 19 `button` widgets via
 * wp_patch_elementor. Every operation returned success:true with no validation
 * warning, and Elementor generated NO background-color declaration for any of
 * them — every button fell through to the global Kit accent (#FA4616), so
 * ghost, white and text-style buttons all rendered as orange pills. It took a
 * full browser-measurement pass to find.
 *
 * The cause was not a missing check. This plugin's curated widget schema
 * DECLARED `button_background_color` as a valid key on the button widget,
 * labelled "Pro variant key". It is not a control on that widget at all. The
 * reference data manufactured the false positive: the validator consulted it,
 * found the key listed, and stayed silent.
 *
 * Measured on the rig against real Elementor CSS generation before changing
 * anything:
 *
 *   background_color         CSS emits the colour   YES   no warning
 *   button_background_color  CSS emits the colour   NO    no warning
 *   button_hover_color       CSS emits the colour   NO    warned correctly
 *
 * The key IS real on the `form` widget, where it styles the submit button —
 * presumably how it reached the button schema. It is per-widget, so it stays
 * there.
 *
 * A note on the fix that was NOT made. The obvious generalisation is to treat
 * Elementor's live `get_controls()` as authoritative for keys, the way the
 * live registry is now authoritative for widget TYPES (#785). Measurement says
 * no: of 391 curated keys across the 49 curated widgets registered on the rig,
 * 277 are absent from `get_controls()` on Elementor 4.2.4 — including `align`,
 * `title_color` and `typography_font_size` on `heading`, which demonstrably
 * work. `get_controls()` is not a complete list of accepted settings keys, so
 * making it authoritative would bury real pages in false warnings. That is why
 * this fix is narrow and evidence-led rather than structural.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ButtonBackgroundKeyTest extends TestCase {

	/**
	 * Validate a single widget carrying one setting, returning the result and
	 * the settings as they ended up.
	 *
	 * @param string $widget_type Widget type.
	 * @param array  $settings    Settings to plant.
	 * @return array{result:array,settings:array}
	 */
	private function validate( string $widget_type, array $settings ): array {
		$json = wp_json_encode(
			array(
				array(
					'id'       => 'sec00001',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						array(
							'id'       => 'col00001',
							'elType'   => 'column',
							'settings' => array( '_column_size' => 100 ),
							'elements' => array(
								array(
									'id'         => 'wid00001',
									'elType'     => 'widget',
									'widgetType' => $widget_type,
									'settings'   => $settings,
								),
							),
						),
					),
				),
			)
		);

		$basic  = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'validate_and_fix_elements' );
		$method->setAccessible( true );

		$result = $method->invokeArgs( $basic, array( &$json ) );
		$tree   = json_decode( $json, true );

		return array(
			'result'   => $result,
			'settings' => $tree[0]['elements'][0]['elements'][0]['settings'],
		);
	}

	public function test_the_reported_key_is_corrected_rather_than_accepted_silently(): void {
		$out = $this->validate( 'button', array( 'text' => 'Probe', 'button_background_color' => '#123456' ) );

		// Renamed rather than only warned: the caller's intent is
		// unambiguous and the correct key is known, so the button styles as
		// asked instead of merely being told it will not.
		$this->assertSame( '#123456', $out['settings']['background_color'] );
		$this->assertArrayNotHasKey( 'button_background_color', $out['settings'] );

		$this->assertStringContainsString(
			"renamed 'button_background_color' -> 'background_color'",
			implode( "\n", $out['result']['fixes'] ),
			'The reported call returned success with an empty warnings array; silence is what cost a browser-measurement pass to undo.'
		);
	}

	public function test_the_schema_no_longer_claims_the_key_is_valid(): void {
		// The root cause. While the curated schema listed it, the validator
		// looked it up, found it, and stayed quiet — so removing the
		// declaration is the fix and the rename above is the courtesy.
		$this->assertNotContains(
			'button_background_color',
			Mcpwp_Elementor_Widgets::get_valid_keys( 'button' ),
			'The button schema declares a key Elementor has no control for; anything set through it produces no CSS.'
		);
	}

	public function test_the_correct_key_passes_untouched(): void {
		$out = $this->validate( 'button', array( 'text' => 'Probe', 'background_color' => '#123456' ) );

		$this->assertSame( '#123456', $out['settings']['background_color'] );
		$this->assertSame( array(), $out['result']['fixes'] );
		$this->assertSame( array(), $out['result']['warnings'] );
	}

	public function test_the_form_widget_keeps_the_key_because_there_it_is_real(): void {
		// On `form` this styles the submit button and works. The rename is
		// keyed on widgetType precisely so fixing one widget does not break
		// the other — a blanket key ban would have.
		$out = $this->validate( 'form', array( 'button_background_color' => '#123456' ) );

		$this->assertSame( '#123456', $out['settings']['button_background_color'] );
		$this->assertContains( 'button_background_color', Mcpwp_Elementor_Widgets::get_valid_keys( 'form' ) );
		$this->assertStringNotContainsString( 'button_background_color', implode( "\n", $out['result']['fixes'] ) );
	}

	public function test_an_explicit_correct_key_is_not_overwritten_by_the_rename(): void {
		// Both present: the rename must not clobber a value the caller set
		// deliberately under the right name.
		$out = $this->validate(
			'button',
			array(
				'background_color'        => '#AAAAAA',
				'button_background_color' => '#123456',
			)
		);

		$this->assertSame( '#AAAAAA', $out['settings']['background_color'] );
	}

	public function test_the_hover_key_that_already_warned_still_warns(): void {
		// The reporter noted button_hover_color was correctly rejected. The
		// fix must not quiet an existing, working warning.
		$out = $this->validate( 'button', array( 'text' => 'Probe', 'button_hover_color' => '#123456' ) );

		$this->assertStringContainsString(
			"unknown key 'button_hover_color'",
			implode( "\n", $out['result']['warnings'] )
		);
	}
}
