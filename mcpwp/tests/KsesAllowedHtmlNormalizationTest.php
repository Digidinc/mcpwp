<?php
/**
 * Regression coverage for #765: a third-party `wp_kses_allowed_html` filter
 * takes down the whole MCPWP Elementor write path.
 *
 * Reported from staging 2026-09-05 (MCPWP 3.10.4, WP 7.1, Elementor 4.2.4 +
 * Pro 4.2.3). wp_apply_elementor_part returned applied=true, meta_verified=true
 * and sections_saved=1 while both document_save and the HTML-cache rebuild
 * carried the same TypeError:
 *
 *   array_filter(): Argument #1 ($array) must be of type array, true given
 *   at heading.php:134
 *
 * Reproduced locally on WP 7.0.2 + Elementor 4.2.4 with one extra filter:
 *
 *   add_filter( 'wp_kses_allowed_html', fn( $t ) => $t + array( 'my-embed' => true ) );
 *
 * `'tag' => true` is not malformed. WordPress core reads it deliberately —
 * wp_kses_attr() tests `true === $allowed_html[ $element_low ]` and returns the
 * bare tag, meaning "tag allowed, no attributes" — and plugins write it. Core's
 * own $allowedposttags contains no such entry, in 7.0.2 or 7.1, so the producer
 * is always a third-party filter and any site can carry one.
 *
 * Elementor is what cannot read it: Widget_Heading::sanitize() hands each value
 * straight to array_filter(). Its content-sanitizer module calls that on every
 * heading saved by a user without manage_options — so on every MCPWP API-key
 * write and never in an admin's own browser, which is why the same page saved
 * fine from the Elementor editor. Same gate as #759, different throw.
 *
 * Two properties are asserted here:
 *
 * 1. The allow-list Elementor sees during an MCPWP write has no non-array
 *    value, so an unrelated plugin's filter cannot fatal our save. The rewrite
 *    is `true` -> array(), the array spelling of the same core rule: wp_kses_attr()
 *    returns the identical bare tag for both. Nothing is newly allowed.
 * 2. When an Elementor call throws anyway — which this cannot prevent in
 *    general — the response says so at the top level. A page whose data
 *    persisted but whose render pipeline died is not a delivered page, and
 *    before this it was indistinguishable from one that rendered.
 *
 * Each test runs in a separate process because the fixture declares
 * \Elementor\Plugin and ELEMENTOR_VERSION (see #757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class KsesAllowedHtmlNormalizationTest extends TestCase {

	private const PAGE_ID = 7650;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_force_throw']  = false;
		$GLOBALS['mcpwp_test_allowed_html'] = $this->core_allow_list();
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters']      = array();
		$GLOBALS['mcpwp_test_allowed_html'] = array();
		$GLOBALS['mcpwp_test_force_throw']  = false;
	}

	/**
	 * A trimmed $allowedposttags: every tag maps to an array, as core ships it.
	 */
	private function core_allow_list(): array {
		return array(
			'a'      => array( 'href' => true, 'rel' => true ),
			'strong' => array(),
			'span'   => array( 'class' => true ),
			'img'    => array( 'src' => true, 'alt' => true ),
		);
	}

	public static function page_id(): int {
		return self::PAGE_ID;
	}

	// ---------------------------------------------------------------------
	// The normaliser itself.
	// ---------------------------------------------------------------------

	public function test_a_true_tag_value_becomes_an_empty_attribute_array(): void {
		$normalized = Mcpwp_Elementor_Basic::normalize_kses_allowed_html(
			array( 'my-embed' => true, 'strong' => array() ),
			'post'
		);

		$this->assertSame( array(), $normalized['my-embed'], '`true` is core shorthand for "no attributes"; array() is its array spelling.' );
		$this->assertSame( array(), $normalized['strong'], 'An already-empty array must survive unchanged.' );
	}

	public function test_attribute_maps_are_returned_untouched(): void {
		$tags       = $this->core_allow_list();
		$normalized = Mcpwp_Elementor_Basic::normalize_kses_allowed_html( $tags, 'post' );

		$this->assertSame(
			$tags,
			$normalized,
			'Nothing may be added to or removed from an allow-list that is already well formed — this must not become an attribute filter.'
		);
	}

	public function test_non_post_contexts_are_left_alone(): void {
		// The 'entities' context is a flat list of entity-name strings, not a
		// tag => attributes map. Rewriting its values would corrupt it.
		$entities = array( 'nbsp', 'amp', 'quot' );

		$this->assertSame(
			$entities,
			Mcpwp_Elementor_Basic::normalize_kses_allowed_html( $entities, 'entities' ),
			'Only the "post" context carries a tag => attributes map.'
		);
	}

	public function test_a_non_array_allow_list_is_passed_through(): void {
		$this->assertNull( Mcpwp_Elementor_Basic::normalize_kses_allowed_html( null, 'post' ) );
	}

	// ---------------------------------------------------------------------
	// The write path.
	// ---------------------------------------------------------------------

	private function install_elementor(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}

		require_once __DIR__ . '/fixtures/elementor-kses-doubles.php';

		\Elementor\Plugin::$instance = new \Elementor\Plugin();
	}

	private function install_reflecting_wpdb(): void {
		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {
			public function get_var( $query = '', $x = 0, $y = 0 ) {
				if ( false !== strpos( (string) $query, '_elementor_data' ) ) {
					return $GLOBALS['_mcpwp_test_meta'][ KsesAllowedHtmlNormalizationTest::page_id() ]['_elementor_data'] ?? null;
				}
				return null;
			}
		};
	}

	/**
	 * The staging site's extra filter, in the shape plugins actually write it.
	 */
	private function install_hostile_filter(): void {
		add_filter(
			'wp_kses_allowed_html',
			static function ( $tags, $context = '' ) {
				if ( 'post' === $context && is_array( $tags ) ) {
					$tags['my-embed'] = true;
				}
				return $tags;
			},
			10,
			2
		);
	}

	private function seed_page(): void {
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = (object) array(
			'ID'          => self::PAGE_ID,
			'post_type'   => 'page',
			'post_title'  => 'KSES fixture',
			'post_status' => 'draft',
		);
	}

	private function heading_payload(): string {
		return wp_json_encode(
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
									'widgetType' => 'heading',
									'settings'   => array( 'title' => 'Close CTA' ),
								),
							),
						),
					),
				),
			)
		);
	}

	/**
	 * The reported failure. Unpatched, Elementor's sanitize loop raises the
	 * TypeError and the guard from #760 records it as document_save_exception.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_true_tag_value_no_longer_breaks_an_elementor_save(): void {
		$this->install_elementor();
		$this->install_reflecting_wpdb();
		$this->install_hostile_filter();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => $this->heading_payload() ) );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertArrayNotHasKey(
			'document_save_exception',
			$result['debug'],
			'Elementor must survive an allow-list entry WordPress core itself supports.'
		);
		$this->assertTrue( $result['render_verified'], 'Nothing threw, so the page rendered.' );
	}

	/**
	 * The scoped filter must not outlive the call it was installed for —
	 * including when that call throws.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_normalizing_filter_does_not_outlive_the_elementor_call(): void {
		$this->install_elementor();
		$this->install_reflecting_wpdb();
		$this->install_hostile_filter();
		$this->seed_page();

		$before = mcpwp_test_filter_count( 'wp_kses_allowed_html' );

		$GLOBALS['mcpwp_test_allow_list_seen'] = null;
		$GLOBALS['mcpwp_test_force_throw']     = true;

		$basic = new Mcpwp_Elementor_Basic();
		$basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => $this->heading_payload() ) );

		$this->assertSame(
			array(),
			$GLOBALS['mcpwp_test_allow_list_seen']['my-embed'] ?? null,
			'Inside the Elementor call the normaliser must be in force — otherwise the removal assertions below prove nothing.'
		);

		$this->assertSame(
			$before,
			mcpwp_test_filter_count( 'wp_kses_allowed_html' ),
			'Every Elementor call adds the normaliser and must remove it again, or a long-running request accumulates copies and KSES stays altered for unrelated code.'
		);

		$this->assertSame(
			true,
			wp_kses_allowed_html( 'post' )['my-embed'],
			'Outside our own Elementor calls the site\'s allow-list must be exactly what its plugins made it.'
		);
	}

	/**
	 * A page that saved but did not render must not report as a plain success.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_failed_render_is_reported_at_the_top_level(): void {
		$this->install_elementor();
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$GLOBALS['mcpwp_test_force_throw'] = true;

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => $this->heading_payload() ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'], 'The data really was written and verified; the caller must not be told to retry it.' );
		$this->assertFalse(
			$result['render_verified'],
			'success alone said the page was delivered when its render pipeline had died — that is the defect.'
		);

		$this->assertNotEmpty( $result['render_errors'] );
		$this->assertStringContainsString(
			'document_save',
			implode( ' ', $result['render_errors'] ),
			'The report must name which Elementor call failed, not just that something did.'
		);
		$this->assertStringContainsString(
			'synthetic Elementor failure',
			implode( ' ', $result['warnings'] ),
			'The failure has to reach warnings, where callers already look.'
		);
	}

	/**
	 * The complement: a clean save must claim a verified render, or the flag
	 * carries no information.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_clean_save_reports_a_verified_render(): void {
		$this->install_elementor();
		$this->install_reflecting_wpdb();
		$this->seed_page();

		$basic  = new Mcpwp_Elementor_Basic();
		$result = $basic->set_elementor_data( self::PAGE_ID, array( 'elementor_data' => $this->heading_payload() ) );

		$this->assertTrue( $result['render_verified'] );
		$this->assertArrayNotHasKey( 'render_errors', $result );
	}
}
