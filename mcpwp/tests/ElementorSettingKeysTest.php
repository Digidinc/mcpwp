<?php
/**
 * #824 — widget settings checked against Elementor's live control definitions.
 *
 * Reported from a live build (staging15, Elementor 4.2.x + Pro): 56
 * `title_typography_*` keys on heading widgets, a `post-info` repeater filled
 * with `post_info_type` instead of `type`, and `button_background_color` — all
 * accepted with zero warnings, stored, and ignored at render. The save
 * validator did look at setting keys, but against the union of Elementor's
 * controls and MCPWP's hand-written reference, never inside a repeater, and
 * with every `_`/`__` key waved through. A key the reference wrongly lists was
 * therefore valid whatever Elementor said, which is the #785 shape again: a
 * second copy overruling the source it was meant to stand in for.
 *
 * Facts the doubles are built on, observed on a real install (WordPress 7.0.2,
 * Elementor 4.2.4, 2026-09-10) inside a REST request:
 *   - get_controls() = own controls + common Advanced-tab controls; group
 *     controls expand to one key per field; repeaters carry `fields`.
 *   - The curated reference disagrees with live controls on real widgets
 *     (counter `typography_font_size` is `typography_number_font_size`).
 *   - Free Elementor registers Pro widget names as Pro_Widget_Promotion
 *     placeholders with no controls.
 *   - Outside REST/admin/preview requests Performance::should_optimize_controls()
 *     drops every style control from get_controls() (heading: 176 controls
 *     under WP-CLI against 276 over REST).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorSettingKeysTest extends TestCase {

	/**
	 * Install the control doubles.
	 *
	 * @param array $overrides Globals to set before Elementor is declared.
	 */
	private function boot( array $overrides = array() ): void {
		$GLOBALS['mcpwp_test_common_controls'] = array(
			'_element_id'  => array( 'type' => 'text', 'name' => '_element_id' ),
			'_css_classes' => array( 'type' => 'text', 'name' => '_css_classes' ),
			'_margin'      => array( 'type' => 'dimensions', 'name' => '_margin' ),
		);

		$GLOBALS['mcpwp_test_widget_controls'] = array(
			'heading'     => array(
				'section_title'               => array( 'type' => 'section' ),
				'title'                       => array( 'type' => 'textarea' ),
				'header_size'                 => array( 'type' => 'select' ),
				'align'                       => array( 'type' => 'choose' ),
				'align_tablet'                => array( 'type' => 'choose' ),
				'align_mobile'                => array( 'type' => 'choose' ),
				'typography_typography'       => array( 'type' => 'popover_toggle' ),
				'typography_font_family'      => array( 'type' => 'font' ),
				'typography_font_size'        => array( 'type' => 'slider' ),
				'typography_font_size_tablet' => array( 'type' => 'slider' ),
				'typography_font_size_mobile' => array( 'type' => 'slider' ),
			),
			'counter'     => array(
				'ending_number'                => array( 'type' => 'number' ),
				'title'                        => array( 'type' => 'text' ),
				'number_color'                 => array( 'type' => 'color' ),
				'title_color'                  => array( 'type' => 'color' ),
				'typography_number_typography' => array( 'type' => 'popover_toggle' ),
				'typography_number_font_size'  => array( 'type' => 'slider' ),
				'typography_title_typography'  => array( 'type' => 'popover_toggle' ),
				'typography_title_font_size'   => array( 'type' => 'slider' ),
			),
			'post-info'   => array(
				'view'      => array( 'type' => 'select' ),
				'icon_list' => array(
					'type'   => 'repeater',
					'fields' => array(
						'_id'         => array( 'type' => 'hidden', 'name' => '_id' ),
						'type'        => array( 'type' => 'select', 'name' => 'type' ),
						'date_format' => array( 'type' => 'select', 'name' => 'date_format' ),
						'text_prefix' => array( 'type' => 'text', 'name' => 'text_prefix' ),
					),
				),
			),
			'nested-tabs' => array(
				'tabs' => array(
					'type'   => 'repeater',
					'fields' => array(
						'_id'       => array( 'type' => 'hidden', 'name' => '_id' ),
						'tab_title' => array( 'type' => 'text', 'name' => 'tab_title' ),
					),
				),
			),
		);

		$GLOBALS['mcpwp_test_promotion_widgets'] = array( 'form' );

		foreach ( $overrides as $key => $value ) {
			$GLOBALS[ $key ] = $value;
		}

		require_once __DIR__ . '/fixtures/elementor-control-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();
	}

	/**
	 * One section, one column, the given widgets.
	 *
	 * @param array $widgets List of array( type, settings ).
	 * @return array Element tree.
	 */
	private function page( array $widgets ): array {
		$elements = array();

		foreach ( array_values( $widgets ) as $i => $widget ) {
			$elements[] = array(
				'id'         => 'w' . sprintf( '%06d', $i ),
				'elType'     => 'widget',
				'widgetType' => $widget[0],
				'settings'   => $widget[1],
			);
		}

		return array(
			array(
				'id'       => 'sec0001',
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'col0001',
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => $elements,
					),
				),
			),
		);
	}

	/**
	 * Run the save validator.
	 *
	 * @param array $tree Element tree.
	 * @return array{result: array, tree: array}
	 */
	private function validate( array $tree ): array {
		$json   = wp_json_encode( $tree );
		$basic  = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'validate_and_fix_elements' );
		$method->setAccessible( true );

		$result = $method->invokeArgs( $basic, array( &$json ) );

		return array(
			'result' => $result,
			'tree'   => json_decode( $json, true ),
		);
	}

	/**
	 * Warnings mentioning a string.
	 *
	 * @param array  $result Validation result.
	 * @param string $needle Text.
	 * @return array
	 */
	private function warnings_with( array $result, string $needle ): array {
		return array_values(
			array_filter(
				$result['warnings'],
				static function ( $warning ) use ( $needle ) {
					return false !== strpos( $warning, $needle );
				}
			)
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_group_key_under_the_wrong_prefix_warns_and_names_the_real_key(): void {
		$this->boot();

		$out = $this->validate(
			$this->page( array( array( 'heading', array( 'title' => 'Hero', 'title_typography_font_family' => 'Outfit' ) ) ) )
		);

		$warning = $this->warnings_with( $out['result'], "'title_typography_font_family'" );

		$this->assertCount( 1, $warning, 'The reported key must draw exactly one warning.' );
		$this->assertStringContainsString( "unknown setting 'title_typography_font_family' on heading widget", $warning[0] );
		$this->assertStringContainsString( "did you mean 'typography_font_family'", $warning[0] );
		$this->assertSame( 'live_registry', $out['result']['settings_check']['source'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_key_only_the_static_reference_lists_is_flagged_by_the_live_controls(): void {
		$this->boot();

		// MCPWP's curated counter guide lists `typography_font_size`; Elementor
		// registers `typography_number_font_size`. The union let the curated
		// key pass on every site that had Elementor's truth available.
		$out = $this->validate(
			$this->page( array( array( 'counter', array( 'ending_number' => 90, 'typography_font_size' => array( 'size' => 48 ) ) ) ) )
		);

		$this->assertCount( 1, $this->warnings_with( $out['result'], "unknown setting 'typography_font_size' on counter widget" ) );
		$this->assertSame( 'live_registry', $out['result']['settings_check']['unknown_settings'][0]['source'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_repeater_fields_are_checked_against_the_repeater_schema(): void {
		$this->boot();

		$out = $this->validate(
			$this->page(
				array(
					array(
						'post-info',
						array(
							'icon_list' => array(
								array( '_id' => 'a000001', 'type' => 'terms' ),
								array( '_id' => 'a000002', 'post_info_type' => 'author' ),
							),
						),
					),
				)
			)
		);

		$warning = $this->warnings_with( $out['result'], "'post_info_type'" );

		$this->assertCount( 1, $warning, 'Every row defaulted to `date` on the reported site because this field name was never checked.' );
		$this->assertStringContainsString( "unknown field 'post_info_type' in repeater 'icon_list'", $warning[0] );
		$this->assertStringContainsString( 'icon_list[1]', $warning[0] );
		$this->assertStringContainsString( "did you mean 'type'", $warning[0] );
		$this->assertCount( 1, $out['result']['settings_check']['unknown_settings'], 'The valid row and the valid fields must not be reported.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_generated_and_internal_keys_are_accepted_and_global_references_are_checked(): void {
		$this->boot();

		$out = $this->validate(
			$this->page(
				array(
					array(
						'heading',
						array(
							'title'                       => 'Hero',
							'typography_font_size_tablet' => array( 'size' => 32 ),
							// A breakpoint that is not active here: Elementor keeps
							// the stored value and applies it when it is enabled.
							'align_laptop'                => 'left',
							'_element_id'                 => 'hero',
							'_title'                      => 'Navigator label',
							'__dynamic__'                 => array( 'title' => '[elementor-tag id="x" name="post-title"]' ),
							'__globals__'                 => array(
								'typography_typography' => 'globals/typography?id=primary',
								'title_colour'          => 'globals/colors?id=primary',
							),
						),
					),
				)
			)
		);

		$unknown = $out['result']['settings_check']['unknown_settings'];

		$this->assertCount( 1, $unknown, 'Only the misspelled global reference is a mistake: ' . wp_json_encode( $unknown ) );
		$this->assertSame( 'title_colour', $unknown[0]['key'] );
		$this->assertSame( '__globals__', $unknown[0]['in'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unknown_key_is_reported_but_never_removed_or_refused(): void {
		$this->boot();

		$out = $this->validate(
			$this->page( array( array( 'heading', array( 'title' => 'Hero', 'title_typography_font_family' => 'Outfit' ) ) ) )
		);

		$this->assertSame( array(), $out['result']['errors'] );
		$this->assertSame(
			'Outfit',
			$out['tree'][0]['elements'][0]['elements'][0]['settings']['title_typography_font_family'],
			'A warning must not strip the value: the caller may know something the check does not.'
		);
		$this->assertSame( 'section[0].column[0].widget[0]', $out['result']['settings_check']['unknown_settings'][0]['path'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_same_mistake_on_many_widgets_is_one_warning(): void {
		$this->boot();

		$widgets = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$widgets[] = array( 'heading', array( 'title' => "H{$i}", 'title_typography_font_family' => 'Outfit' ) );
		}

		$out = $this->validate( $this->page( $widgets ) );

		$warning = $this->warnings_with( $out['result'], "'title_typography_font_family'" );

		$this->assertCount( 1, $warning, 'Thirty copies of one mistake is one thing to fix.' );
		$this->assertStringContainsString( '29 more', $warning[0] );
		$this->assertSame( 30, $out['result']['settings_check']['unknown_settings'][0]['count'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_page_of_distinct_mistakes_is_capped_with_a_pointer_to_the_full_list(): void {
		$this->boot();

		$settings = array( 'title' => 'Hero' );
		for ( $i = 0; $i < 60; $i++ ) {
			$settings[ "bogus_key_{$i}" ] = 'x';
		}

		$out = $this->validate( $this->page( array( array( 'heading', $settings ) ) ) );

		$this->assertLessThanOrEqual( 26, count( $this->warnings_with( $out['result'], 'bogus_key_' ) ) + count( $this->warnings_with( $out['result'], 'more unknown setting' ) ) );
		$this->assertCount( 1, $this->warnings_with( $out['result'], 'more unknown setting' ) );
		$this->assertCount( 60, $out['result']['settings_check']['unknown_settings'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unreadable_registry_falls_back_to_the_static_reference_and_says_so(): void {
		$this->boot( array( 'mcpwp_test_widget_registry_throws' => true ) );

		$out   = $this->validate(
			$this->page(
				array(
					array( 'heading', array( 'title' => 'Hero' ) ),
					array( 'nested-tabs', array( 'tabs' => array() ) ),
				)
			)
		);
		$check = $out['result']['settings_check'];

		$this->assertSame( 'static_reference', $check['source'] );
		$this->assertNotEmpty( $check['live_unavailable_reason'], '"Could not check" must never read like "valid".' );
		$this->assertContains( 'nested-tabs', array_column( $check['widgets_not_checked'], 'widget_type' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_controls_elementor_trims_outside_rest_requests_are_not_trusted(): void {
		$this->boot( array( 'mcpwp_test_optimize_controls' => true ) );

		$out   = $this->validate( $this->page( array( array( 'heading', array( 'title' => 'Hero' ) ) ) ) );
		$check = $out['result']['settings_check'];

		$this->assertSame( 'static_reference', $check['source'] );
		$this->assertStringContainsString( 'style controls', $check['live_unavailable_reason'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_pro_promotion_placeholder_is_reported_rather_than_checked(): void {
		$this->boot();

		$out = $this->validate(
			$this->page( array( array( 'form', array( 'form_name' => 'Contact', 'email_to' => 'a@example.com' ) ) ) )
		);

		$this->assertCount( 1, $this->warnings_with( $out['result'], 'promotion placeholder' ) );
		$this->assertSame( array(), $this->warnings_with( $out['result'], "'form_name'" ), 'A placeholder has no controls; calling each key unknown would bury the real problem.' );

		$not_checked = array_column( $out['result']['settings_check']['widgets_not_checked'], 'reason', 'widget_type' );
		$this->assertArrayHasKey( 'form', $not_checked );
		$this->assertStringContainsString( 'placeholder', $not_checked['form'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_widget_help_takes_valid_keys_from_the_live_controls(): void {
		$this->boot();

		$help = Mcpwp_Elementor_Widgets::get_widget_help( 'heading' );

		$this->assertSame( 'live_registry', $help['valid_keys_source'] );
		$this->assertContains( 'typography_font_size_tablet', $help['valid_keys'] );
		$this->assertNotContains( 'section_title', $help['valid_keys'], 'A section is layout in the panel, not a setting.' );
		$this->assertContains( 'title_color', $help['curated_keys_not_in_live'], 'The curated guide names a key these controls do not have; say so instead of listing it as valid.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_widget_help_lists_the_live_registry_for_an_unknown_widget(): void {
		$this->boot();

		$help = Mcpwp_Elementor_Widgets::get_widget_help( 'nested-tab' );

		$this->assertSame( 'unknown_widget', $help['error'] );
		$this->assertContains( 'nested-tabs', $help['all_widgets'], 'A widget the site registers must be in the list the caller is told to pick from.' );
		$this->assertNotContains( 'form', $help['all_widgets'], 'A Pro placeholder renders nothing and is not a widget to pick.' );
		$this->assertStringContainsString( 'live', $help['all_widgets_is'] );
		$this->assertContains( 'nested-tabs', $help['suggestions'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_widget_help_does_not_call_a_promotion_placeholder_registered(): void {
		$this->boot();

		$help = Mcpwp_Elementor_Widgets::get_widget_help( 'form' );

		$this->assertFalse( $help['registered_on_this_site'] );
		$this->assertStringContainsString( 'placeholder', $help['warning'] );
	}

	public function test_the_curated_gallery_guide_uses_the_real_widget_name(): void {
		// Elementor registers the basic gallery as `image-gallery` (title
		// "Basic Gallery") and stores its images in `wp_gallery`. A guide under
		// `basic-gallery` described a widget that exists nowhere.
		$this->assertNull( Mcpwp_Elementor_Widgets::get( 'basic-gallery' ) );
		$this->assertNotNull( Mcpwp_Elementor_Widgets::get( 'image-gallery' ) );
		$this->assertContains( 'wp_gallery', Mcpwp_Elementor_Widgets::get_valid_keys( 'image-gallery' ) );
	}
}
