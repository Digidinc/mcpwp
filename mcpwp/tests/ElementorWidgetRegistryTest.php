<?php
/**
 * Regression coverage for #785 defects 1 and 2 — the widget registry.
 *
 * Reported from a live build: wp_set_elementor accepted widgetType
 * "archive-title", answered success:true with sections_saved:2,
 * meta_verified:true, render_verified:true and an EMPTY validation_warnings
 * array, and the page rendered no heading at all. The correct spelling is
 * "theme-archive-title" — Elementor Pro 3.0 renamed the theme widgets.
 *
 * The plugin already validated widget names. What it validated against was the
 * union of Elementor's live registry and two hardcoded reference lists, and
 * "archive-title" is in the hardcoded Pro list because it was a real widget
 * before the rename. So the fallback list was overruling Elementor rather than
 * standing in for its absence, and the union could only ever grow: no widget
 * that had ever existed could be reported as missing.
 *
 * The same call then compounded it. wp_elementor_widget_help, asked about
 * "theme-archive-title", answered unknown_widget with suggestions:[] and a
 * curated list of ~52 names that did not include it — pointing away from the
 * correct answer while the site had the widget registered and rendering.
 *
 * Both are one bug: an unavailable source and a negative answer produced the
 * same output. These tests pin the separation. live_types() returns null when
 * Elementor cannot be asked and an array when it can, and every caller is
 * required to say which of the two it is reporting.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorWidgetRegistryTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['mcpwp_test_widget_types'],
			$GLOBALS['mcpwp_test_widget_registry_throws']
		);
	}

	// ---------------------------------------------------------------
	// Without Elementor: "could not look" must not read as "not there".
	// ---------------------------------------------------------------

	public function test_live_types_is_null_when_elementor_is_absent(): void {
		$this->assertFalse(
			class_exists( '\Elementor\Plugin', false ),
			'This assertion is the premise of the rest of the test; if Elementor leaked into this process the result below means nothing.'
		);

		$this->assertNull(
			Mcpwp_Elementor_Registry::live_types(),
			'An unreadable registry must be null, never an empty array — an empty array says "no widget exists here", which would warn on every widget on the page.'
		);
	}

	public function test_is_registered_is_null_rather_than_false_when_elementor_is_absent(): void {
		$this->assertNull( Mcpwp_Elementor_Registry::is_registered( 'heading' ) );
	}

	public function test_widget_help_says_it_could_not_reach_the_registry(): void {
		$help = Mcpwp_Elementor_Widgets::get_widget_help( 'theme-archive-title' );

		$this->assertSame( 'unknown_widget', $help['error'] );
		$this->assertFalse(
			$help['registry_checked'],
			'Answering unknown_widget without saying the registry was never consulted is the shape of the original bug.'
		);
		$this->assertArrayHasKey( 'registry_note', $help );
		$this->assertArrayNotHasKey( 'registered_widget_count', $help );
	}

	public function test_all_widgets_is_labelled_as_curated_rather_than_the_site_registry(): void {
		$help = Mcpwp_Elementor_Widgets::get_widget_help( 'not-a-widget-at-all' );

		// The reporter read all_widgets as the site's registry and concluded
		// the correct widget did not exist. The list is fine; the label was
		// missing.
		$this->assertArrayHasKey( 'all_widgets_is', $help );
		$this->assertStringContainsString( 'curated', $help['all_widgets_is'] );
	}

	// ---------------------------------------------------------------
	// Suggestion: the rename gap Levenshtein cannot cross.
	// ---------------------------------------------------------------

	public function test_the_reported_name_suggests_the_renamed_one(): void {
		$candidates = array( 'heading', 'theme-archive-title', 'theme-post-title', 'image' );

		$this->assertGreaterThan(
			3,
			levenshtein( 'archive-title', 'theme-archive-title' ),
			'If this distance ever falls inside the fuzzy threshold the prefix rule below is redundant — but today it is 6, which is why the caller got no suggestion at all.'
		);

		$this->assertSame(
			'theme-archive-title',
			Mcpwp_Elementor_Registry::suggest( 'archive-title', $candidates )
		);
	}

	public function test_a_prefixed_name_suggests_the_unprefixed_one_when_that_is_what_is_registered(): void {
		$this->assertSame(
			'archive-title',
			Mcpwp_Elementor_Registry::suggest( 'theme-archive-title', array( 'archive-title', 'heading' ) )
		);
	}

	public function test_an_already_prefixed_unknown_is_not_prefixed_twice(): void {
		$this->assertNull(
			Mcpwp_Elementor_Registry::suggest( 'theme-nonsense', array( 'theme-theme-nonsense' ) ),
			'theme-theme-nonsense is not a correction anyone wants offered.'
		);
	}

	public function test_the_fuzzy_matcher_still_runs_when_no_prefix_rule_applies(): void {
		$fuzzy = static function ( $input, $candidates ) {
			foreach ( $candidates as $candidate ) {
				if ( levenshtein( $input, $candidate ) <= 2 ) {
					return $candidate;
				}
			}
			return null;
		};

		$this->assertSame(
			'heading',
			Mcpwp_Elementor_Registry::suggest( 'headng', array( 'heading', 'image' ), $fuzzy )
		);
	}

	public function test_suggest_returns_null_rather_than_a_bad_guess_when_nothing_matches(): void {
		$this->assertNull( Mcpwp_Elementor_Registry::suggest( 'zzzzzzzz', array( 'heading' ) ) );
		$this->assertNull( Mcpwp_Elementor_Registry::suggest( '', array( 'heading' ) ) );
		$this->assertNull( Mcpwp_Elementor_Registry::suggest( 'heading', array() ) );
	}

	// ---------------------------------------------------------------
	// With Elementor loaded. Separate processes: declaring
	// \Elementor\Plugin in the main run changes every Elementor-sensitive
	// test that follows it (#757).
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_live_registry_is_the_answer_when_it_can_be_read(): void {
		$GLOBALS['mcpwp_test_widget_types'] = array( 'heading', 'image', 'theme-archive-title' );

		require_once __DIR__ . '/fixtures/elementor-widget-registry-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$this->assertSame(
			array( 'heading', 'image', 'theme-archive-title' ),
			Mcpwp_Elementor_Registry::live_types()
		);

		$this->assertTrue( Mcpwp_Elementor_Registry::is_registered( 'theme-archive-title' ) );

		// The whole point: a name the hardcoded fallback list contains, which
		// this site does not register, now answers false instead of being
		// waved through by the union.
		$this->assertFalse(
			Mcpwp_Elementor_Registry::is_registered( 'archive-title' ),
			'archive-title is in the hardcoded Pro list; the live registry must still be allowed to say no.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registry_reporting_zero_types_is_treated_as_unreadable(): void {
		$GLOBALS['mcpwp_test_widget_types'] = array();

		require_once __DIR__ . '/fixtures/elementor-widget-registry-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		// Elementor with no widget types at all is Elementor mid-boot, not a
		// site where nothing is registered. Believing it would warn on every
		// widget of every save that happened to run early.
		$this->assertNull( Mcpwp_Elementor_Registry::live_types() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registry_that_throws_is_treated_as_unreadable(): void {
		$GLOBALS['mcpwp_test_widget_types']           = array( 'heading' );
		$GLOBALS['mcpwp_test_widget_registry_throws'] = true;

		require_once __DIR__ . '/fixtures/elementor-widget-registry-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$this->assertNull( Mcpwp_Elementor_Registry::live_types() );
		$this->assertNull( Mcpwp_Elementor_Registry::is_registered( 'heading' ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_widget_help_answers_from_the_live_registry_when_no_curated_guide_exists(): void {
		$GLOBALS['mcpwp_test_widget_types'] = array( 'heading', 'theme-archive-title' );

		require_once __DIR__ . '/fixtures/elementor-widget-registry-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$help = Mcpwp_Elementor_Widgets::get_widget_help( 'theme-archive-title' );

		$this->assertArrayNotHasKey(
			'error',
			$help,
			'The reported call answered unknown_widget for a widget the site had registered and rendering.'
		);
		$this->assertSame( 'theme-archive-title', $help['widget_type'] );
		$this->assertSame( 'live_registry', $help['source'] );
		$this->assertSame( array( 'title', 'header_size', 'align' ), $help['valid_keys'] );
		$this->assertSame( 'theme-elements', $help['category'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_widget_help_suggests_the_registered_name_for_the_reported_typo(): void {
		$GLOBALS['mcpwp_test_widget_types'] = array( 'heading', 'theme-archive-title' );

		require_once __DIR__ . '/fixtures/elementor-widget-registry-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$help = Mcpwp_Elementor_Widgets::get_widget_help( 'archive-title' );

		$this->assertSame( 'unknown_widget', $help['error'] );
		$this->assertTrue( $help['registry_checked'] );
		$this->assertSame( 2, $help['registered_widget_count'] );
		$this->assertContains(
			'theme-archive-title',
			$help['suggestions'],
			'The call that started this issue got suggestions:[] here.'
		);
	}

	// ---------------------------------------------------------------
	// The save path itself — the surface the report was actually about.
	// ---------------------------------------------------------------

	/**
	 * Run the validator over one heading-shaped widget of the given type.
	 *
	 * @param string $widget_type Widget type to plant in the tree.
	 * @return array Validation result: warnings, fixes, errors.
	 */
	private function validate_widget_type( string $widget_type ): array {
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
									'settings'   => array( 'title' => 'Upcoming Dates' ),
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

		return $method->invokeArgs( $basic, array( &$json ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_saving_the_reported_widget_type_warns_instead_of_passing_clean(): void {
		$GLOBALS['mcpwp_test_widget_types'] = array( 'heading', 'image', 'theme-archive-title' );

		require_once __DIR__ . '/fixtures/elementor-widget-registry-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$result = $this->validate_widget_type( 'archive-title' );

		$this->assertNotEmpty(
			$result['warnings'],
			'This is the reported defect verbatim: the save returned an empty validation_warnings array for a widget that renders nothing.'
		);

		$this->assertStringContainsString( "unknown widget 'archive-title'", $result['warnings'][0] );
		$this->assertStringContainsString( "did you mean 'theme-archive-title'", $result['warnings'][0] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_registered_widget_saves_without_a_warning(): void {
		$GLOBALS['mcpwp_test_widget_types'] = array( 'heading', 'image', 'theme-archive-title' );

		require_once __DIR__ . '/fixtures/elementor-widget-registry-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$result = $this->validate_widget_type( 'theme-archive-title' );

		$this->assertSame(
			array(),
			$result['warnings'],
			'Tightening the check is only correct if the correct spelling still passes silently.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_pro_widget_missing_from_a_free_site_says_so_rather_than_calling_it_a_typo(): void {
		// Elementor free: `form` is a real Pro widget name, spelled correctly,
		// that will render nothing here. "unknown widget type" would send the
		// caller hunting for a spelling mistake that does not exist.
		$GLOBALS['mcpwp_test_widget_types'] = array( 'heading', 'image', 'text-editor' );

		require_once __DIR__ . '/fixtures/elementor-widget-registry-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$result   = $this->validate_widget_type( 'form' );
		$warnings = implode( "\n", $result['warnings'] );

		$this->assertStringContainsString( "widget 'form' is an Elementor Pro widget", $warnings );
		$this->assertStringContainsString( 'not registered on this site', $warnings );
		$this->assertStringNotContainsString(
			'unknown widget type',
			$warnings,
			'"form" is spelled correctly; calling it unknown sends the caller hunting for a typo that is not there.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_unreadable_registry_falls_back_to_the_reference_lists(): void {
		$GLOBALS['mcpwp_test_widget_types']           = array( 'heading' );
		$GLOBALS['mcpwp_test_widget_registry_throws'] = true;

		require_once __DIR__ . '/fixtures/elementor-widget-registry-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		// With no live answer the hardcoded lists are all there is, and they
		// must still be used — a registry that could not be read is not a
		// licence to warn on every widget in the document. (Asserted on the
		// widget-type warnings only; `title` is not a control on `form`, so
		// that tree legitimately draws an unknown-key warning either way.)
		foreach ( array( 'heading', 'form' ) as $type ) {
			$warnings = implode( "\n", $this->validate_widget_type( $type )['warnings'] );

			$this->assertStringNotContainsString( 'unknown widget', $warnings );
			$this->assertStringNotContainsString( 'not registered on this site', $warnings );
		}
	}
}
