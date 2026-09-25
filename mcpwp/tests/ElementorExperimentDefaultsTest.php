<?php
/**
 * Regression coverage for #883 — a fresh Elementor 4.x install has the
 * Container experiment active by DEFAULT (Elementor registers no
 * `elementor_experiment-container` option row for a default-active feature
 * until an admin overrides it), but every read in this plugin used
 * `get_option( 'elementor_experiment-container', '' )` and treated the
 * missing row as "inactive".
 *
 * Observed on a fresh WordPress 7.0.2 + Elementor 4.3.2 install:
 *   - wp_site_info / wp_onboard reported elementor_layout_mode: "section"
 *   - wp_set_elementor rejected container payloads with "This site is in
 *     classic 'section' layout mode (Elementor's Container experiment is
 *     not active)" — a false rejection, not a real layout mismatch.
 *
 * Every one of the eight call sites this plugin had for
 * `elementor_experiment-*` now goes through Mcpwp_Elementor_Experiments,
 * which asks Elementor's own Experiments_Manager first (it already folds a
 * feature's registered default together with whatever the site overrode) and
 * only falls back to the raw option when the manager is unreachable.
 *
 * The double in fixtures/elementor-experiments-doubles.php is the one prior
 * \Elementor\Plugin test doubles in this suite lacked: an `experiments`
 * property that can report a feature active while wp_options says nothing
 * about it at all — exactly the fresh-install shape.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorExperimentDefaultsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_active_experiments'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['mcpwp_test_active_experiments'] );
	}

	// ---------------------------------------------------------------
	// No Elementor at all.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reports_inactive_when_elementor_is_not_installed(): void {
		$this->assertFalse( Mcpwp_Elementor_Experiments::is_container_active() );
		$this->assertFalse( Mcpwp_Elementor_Experiments::is_atomic_active() );
	}

	// ---------------------------------------------------------------
	// Elementor installed, but no Experiments_Manager reachable (every
	// pre-existing \Elementor\Plugin double in this suite is this shape) —
	// the fallback must reproduce the plugin's prior, option-only behaviour
	// exactly, so nothing regresses for a site the manager cannot be asked.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fallback_reads_active_option(): void {
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		update_option( 'elementor_experiment-container', 'active' );

		$this->assertTrue( Mcpwp_Elementor_Experiments::is_container_active() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fallback_reads_explicit_default_option(): void {
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		update_option( 'elementor_experiment-container', 'default' );

		$this->assertTrue( Mcpwp_Elementor_Experiments::is_container_active() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fallback_reads_inactive_option(): void {
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		update_option( 'elementor_experiment-container', 'inactive' );

		$this->assertFalse( Mcpwp_Elementor_Experiments::is_container_active() );
	}

	/**
	 * This is the fallback's known blind spot, not a bug this test is
	 * papering over: with no Experiments_Manager to ask, an entirely absent
	 * option cannot be told apart from "inactive". It exists only to pin the
	 * fallback's documented behaviour; the fix is that the manager path
	 * (below) is tried FIRST and does not have this blind spot.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fallback_treats_a_missing_option_as_inactive(): void {
		define( 'ELEMENTOR_VERSION', '4.2.4' );

		$this->assertFalse( Mcpwp_Elementor_Experiments::is_container_active() );
	}

	// ---------------------------------------------------------------
	// The actual fix: Elementor's own Experiments_Manager is asked first,
	// and it can say a feature is active with NO option row at all — the
	// fresh-install shape #883 was filed for.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_manager_reports_container_active_with_no_option_stored(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		\Elementor\Plugin::$instance             = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_active_experiments'] = array( 'container' );

		// No update_option() call at all — the fresh-install shape.
		$this->assertSame( '', get_option( 'elementor_experiment-container', '' ) );

		$this->assertTrue(
			Mcpwp_Elementor_Experiments::is_container_active(),
			'A default-active Container experiment with no option row must still report active — asking Elementor itself, not the option, is the point of #883.'
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_manager_reports_atomic_active_with_no_option_stored(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		\Elementor\Plugin::$instance             = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_active_experiments'] = array( 'e_atomic_elements' );

		$this->assertTrue( Mcpwp_Elementor_Experiments::is_atomic_active() );
		// Independent of container — the two are not mutually exclusive.
		$this->assertFalse( Mcpwp_Elementor_Experiments::is_container_active() );
	}

	/**
	 * The manager is authoritative even when the stale option disagrees with
	 * it (e.g. an admin toggled it off after this plugin last cached
	 * anything, or the option is a leftover from a downgrade) — Elementor's
	 * own answer wins, not the option this plugin used to read alone.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_manager_wins_over_a_stale_disagreeing_option(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		update_option( 'elementor_experiment-container', 'inactive' );
		$GLOBALS['mcpwp_test_active_experiments'] = array( 'container' );

		$this->assertTrue( Mcpwp_Elementor_Experiments::is_container_active() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_neither_experiment_active_reports_false_for_both(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$GLOBALS['mcpwp_test_active_experiments'] = array();

		$this->assertFalse( Mcpwp_Elementor_Experiments::is_container_active() );
		$this->assertFalse( Mcpwp_Elementor_Experiments::is_atomic_active() );
	}

	// ---------------------------------------------------------------
	// Integration: Mcpwp_Core::get_capabilities() must report the same
	// thing wp_site_info / wp_onboard hand the caller — this is the exact
	// field (elementor_layout_mode) the bug report showed as wrong.
	// ---------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_capabilities_report_container_layout_on_a_fresh_install(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		\Elementor\Plugin::$instance             = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_active_experiments'] = array( 'container' );

		$caps = ( new Mcpwp_Core() )->get_capabilities();

		$this->assertSame(
			'container',
			$caps['elementor_layout_mode'],
			'This is the field wp_site_info / wp_onboard hand the caller — reporting "section" here on a fresh install is what made wp_set_elementor reject valid container payloads.'
		);
		$this->assertFalse( $caps['elementor_atomic_active'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_capabilities_report_atomic_layout_on_a_fresh_install(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		\Elementor\Plugin::$instance             = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_active_experiments'] = array( 'e_atomic_elements' );

		$caps = ( new Mcpwp_Core() )->get_capabilities();

		$this->assertSame( 'atomic', $caps['elementor_layout_mode'] );
		$this->assertTrue( $caps['elementor_atomic_active'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_capabilities_report_section_when_no_experiment_is_active(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$caps = ( new Mcpwp_Core() )->get_capabilities();

		$this->assertSame( 'section', $caps['elementor_layout_mode'] );
	}

	// ---------------------------------------------------------------
	// Integration: the layout-mode mismatch guard in set_elementor_data()
	// must not refuse a container payload when the manager (not the option)
	// says container mode is active — this is the write path that returned
	// "This site is in classic 'section' layout mode" on the rig.
	// ---------------------------------------------------------------

	/** @var array */
	private $meta_before = array();

	/** @var array */
	private $posts_before = array();

	private const PAGE_ID = 9300;

	private function seed_elementor_page(): void {
		$this->meta_before  = $GLOBALS['_mcpwp_test_meta'] ?? array();
		$this->posts_before = $GLOBALS['_mcpwp_test_posts'] ?? array();

		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID => new WP_Post(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'Fresh-install container probe',
					'post_name'   => 'fresh-install-container-probe',
					'post_status' => 'draft',
				)
			),
		);
	}

	private function container_element(): array {
		return array(
			'id'       => 'ctr00001',
			'elType'   => 'container',
			'settings' => array(),
			'elements' => array(),
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_set_elementor_data_accepts_container_elements_when_the_manager_says_active_with_no_option(): void {
		require_once __DIR__ . '/fixtures/elementor-experiments-doubles.php';
		define( 'ELEMENTOR_VERSION', '4.2.4' );
		\Elementor\Plugin::$instance             = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_active_experiments'] = array( 'container' );

		$this->seed_elementor_page();

		// The fresh-install shape: no elementor_experiment-container row.
		$this->assertSame( '', get_option( 'elementor_experiment-container', '' ) );

		$writer = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
		$result = $writer->set_elementor_data(
			self::PAGE_ID,
			array( 'elementor_data' => wp_json_encode( array( $this->container_element() ) ) )
		);

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			'The write must not be rejected as a layout-mode mismatch when Elementor itself reports Container active, even with no option row.'
		);
		if ( $result instanceof WP_Error ) {
			return;
		}
		$this->assertNotSame( 'layout_mode_mismatch', is_array( $result ) ? ( $result['code'] ?? '' ) : '' );
	}
}
