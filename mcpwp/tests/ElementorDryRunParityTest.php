<?php
/**
 * Regression coverage for #828: dry runs that never met the write path, and a
 * failed wp_create_theme_template that left a published orphan.
 *
 * Reported on staging15 (MCPWP 3.10.2 / 3.10.3, Elementor 4.2.4 + Pro 4.2.3):
 *
 *  1. wp_set_elementor{dry_run:true} answered "Validation complete — no changes
 *     saved." for a payload the identical real call then refused or fataled
 *     on. The dry run returned before the checks the write runs — among them
 *     the content-conversion guard — so it could pass what the write refuses.
 *  2. wp_create_theme_template with elementor_data returned 500 while the
 *     template post it had already inserted as `publish` stayed behind, and
 *     showed up in wp_list_theme_templates.
 *
 * What this pins:
 *   - a dry run runs the same preparation as the write (conversion guard,
 *     validation) and refuses what the write refuses;
 *   - a dry run persists nothing — no meta at all, not only _elementor_data —
 *     and reports what the write would do;
 *   - a theme template whose data cannot be saved leaves no post behind, and
 *     the error says it was rolled back;
 *   - its dry run refuses what its write refuses, creates nothing, and is
 *     reachable through the tool (the REST handler used to drop dry_run).
 *
 * Every test that defines ELEMENTOR_VERSION / ELEMENTOR_PRO_VERSION runs in a
 * separate process (#757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorDryRunParityTest extends TestCase {

	private const PAGE_ID = 8280;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID => new WP_Post(
				array(
					'ID'           => self::PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'Dry run probe',
					'post_name'    => 'dry-run-probe',
					'post_status'  => 'draft',
					'post_content' => '',
				)
			),
		);
	}

	private function elementor_installed(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
	}

	private function theme_builder_installed(): void {
		$this->elementor_installed();
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}
		require_once __DIR__ . '/fixtures/elementor-pro-theme-builder-module-stub.php';
		require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-theme-builder.php';
	}

	private function writer(): Mcpwp_Elementor_Basic {
		return ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
	}

	private function section( string $id ): array {
		return array(
			'id'       => $id,
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => substr( $id, 0, 3 ) . 'col1',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(
						array(
							'id'         => substr( $id, 0, 3 ) . 'wid1',
							'elType'     => 'widget',
							'widgetType' => 'heading',
							'settings'   => array( 'title' => 'Hi' ),
							'elements'   => array(),
						),
					),
				),
			),
		);
	}

	private function template_posts(): array {
		return array_filter(
			$GLOBALS['_mcpwp_test_posts'],
			static function ( $post ) {
				return 'elementor_library' === $post->post_type;
			}
		);
	}

	// ---------------------------------------------------------------------
	// wp_set_elementor dry_run.
	// ---------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_dry_run_refuses_what_the_write_refuses(): void {
		$this->elementor_installed();
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ]->post_content = '<!-- wp:paragraph --><p>Block content</p><!-- /wp:paragraph -->';

		$payload = array( 'elementor_data' => array( $this->section( 'aaa0001' ) ) );

		$real = $this->writer()->set_elementor_data( self::PAGE_ID, $payload, false );
		$this->assertInstanceOf( WP_Error::class, $real, 'Premise: the write refuses to convert a page with block content.' );

		$dry = $this->writer()->set_elementor_data( self::PAGE_ID, $payload, true );
		$this->assertInstanceOf( WP_Error::class, $dry, 'A dry run that passes what the write refuses gives false confidence.' );
		$this->assertSame( $real->get_error_code(), $dry->get_error_code() );

		$this->assertSame( array(), $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ] ?? array(), 'Neither call wrote any meta.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_dry_run_persists_nothing_and_reports_what_the_write_would_do(): void {
		$this->elementor_installed();
		$GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_elementor_data'] = wp_json_encode( array( $this->section( 'old0001' ) ) );

		$meta_before = $GLOBALS['_mcpwp_test_meta'];
		$post_before = clone $GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ];
		$payload     = array( 'elementor_data' => array( $this->section( 'old0001' ), $this->section( 'new0001' ) ) );

		$dry = $this->writer()->set_elementor_data( self::PAGE_ID, $payload, true );

		$this->assertNotInstanceOf( WP_Error::class, $dry );
		$this->assertTrue( $dry['dry_run'] );
		$this->assertFalse( $dry['persisted'] );
		$this->assertSame( $meta_before, $GLOBALS['_mcpwp_test_meta'], 'No meta of any kind: not _elementor_data, not the template, edit-mode or version keys.' );
		$this->assertEquals( $post_before, $GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ], 'The post itself is untouched.' );

		$would = $dry['would_write'];
		$this->assertSame( 1, $would['sections_before'] );
		$this->assertSame( 2, $would['sections_after'] );
		$this->assertSame( 1, $would['widgets_before'] );
		$this->assertSame( 2, $would['widgets_after'] );
		$this->assertContains( '_elementor_data', $would['meta_keys'] );
		$this->assertContains( '_elementor_edit_mode', $would['meta_keys'] );

		$real = $this->writer()->set_elementor_data( self::PAGE_ID, $payload, false );
		$this->assertNotInstanceOf( WP_Error::class, $real );
		$this->assertSame( $would['sections_after'], $real['structural_changes']['sections_after'], 'The dry run described the write that followed.' );
		$this->assertSame( $would['widgets_after'], $real['structural_changes']['widgets_after'] );
	}

	// ---------------------------------------------------------------------
	// wp_create_theme_template.
	// ---------------------------------------------------------------------

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_template_whose_data_does_not_persist_leaves_no_post_behind(): void {
		$this->theme_builder_installed();
		$GLOBALS['mcpwp_test_meta_write_fails'] = array( '_elementor_data' );

		$result = ( new Mcpwp_Theme_Builder() )->create_theme_template(
			array(
				'title'          => 'ZZ Probe',
				'type'           => 'header',
				'elementor_data' => array( $this->section( 'hdr0001' ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'A template whose data did not land is not a created template.' );
		$this->assertSame( array(), $this->template_posts(), 'No orphan template post, published or otherwise.' );
		$this->assertTrue( $result->get_error_data()['rolled_back'] ?? null, 'The error says the post it created was removed.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_template_whose_elementor_save_throws_is_rolled_back_and_reported(): void {
		$this->theme_builder_installed();
		require_once __DIR__ . '/fixtures/elementor-kses-doubles.php';
		\Elementor\Plugin::$instance        = new \Elementor\Plugin();
		$GLOBALS['mcpwp_test_force_throw']  = true;
		$GLOBALS['mcpwp_test_allowed_html'] = array( 'strong' => array() );

		$result = ( new Mcpwp_Theme_Builder() )->create_theme_template(
			array(
				'title'          => 'ZZ Probe',
				'type'           => 'header',
				'elementor_data' => array( $this->section( 'hdr0001' ) ),
			)
		);

		$this->assertInstanceOf( WP_Error::class, $result, 'Elementor\'s save threw; that must not be a 500 with the post left published.' );
		$this->assertStringContainsString( 'synthetic Elementor failure', $result->get_error_message() );
		$this->assertTrue( $result->get_error_data()['rolled_back'] ?? null );
		$this->assertSame( array(), $this->template_posts() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_template_dry_run_refuses_what_the_write_refuses_and_creates_nothing(): void {
		$this->theme_builder_installed();

		$data = array(
			'title'          => 'ZZ Probe',
			'type'           => 'footer',
			'elementor_data' => array( 'not an element' ),
		);

		$dry = ( new Mcpwp_Theme_Builder() )->create_theme_template( array_merge( $data, array( 'dry_run' => true ) ) );
		$this->assertInstanceOf( WP_Error::class, $dry, 'A dry run that calls this payload valid gives false confidence.' );

		$real = ( new Mcpwp_Theme_Builder() )->create_theme_template( $data );
		$this->assertInstanceOf( WP_Error::class, $real );
		$this->assertSame( $real->get_error_code(), $dry->get_error_code() );

		$this->assertSame( array(), $this->template_posts(), 'A refused payload creates no post.' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_template_dry_run_reports_what_the_write_would_create(): void {
		$this->theme_builder_installed();

		$dry = ( new Mcpwp_Theme_Builder() )->create_theme_template(
			array(
				'title'          => 'Site header',
				'type'           => 'header',
				'elementor_data' => array( $this->section( 'hdr0001' ) ),
				'dry_run'        => true,
			)
		);

		$this->assertNotInstanceOf( WP_Error::class, $dry );
		$this->assertTrue( $dry['dry_run'] );
		$this->assertFalse( $dry['persisted'] );
		$this->assertSame( 1, $dry['would_create']['elements'] );
		$this->assertSame( 'header', $dry['would_create']['template_type'] );
		$this->assertSame( array(), $this->template_posts() );
		$this->assertSame( array(), $GLOBALS['_mcpwp_test_meta'], 'No meta written anywhere.' );
	}

	public function test_the_template_tool_accepts_dry_run_and_the_handler_forwards_it(): void {
		$defs = array();
		foreach ( ( new Mcpwp_MCP_Pro_Tools() )->get_tools() as $tool ) {
			$defs[ $tool['name'] ] = $tool;
		}
		$this->assertArrayHasKey( 'dry_run', $defs['wp_create_theme_template']['inputSchema']['properties'] );

		require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';

		$builder = new class() {
			/** @var array */
			public $received = array();

			public function create_theme_template( $data ) {
				$this->received = $data;
				return array( 'dry_run' => true, 'persisted' => false );
			}
		};

		$controller = ( new ReflectionClass( Mcpwp_REST_Theme_Builder::class ) )->newInstanceWithoutConstructor();
		$property   = new ReflectionProperty( Mcpwp_REST_Theme_Builder::class, 'theme_builder' );
		$property->setAccessible( true );
		$property->setValue( $controller, $builder );

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/theme-builder/templates' );
		$request->set_param( 'title', 'T' );
		$request->set_param( 'type', 'header' );
		$request->set_param( 'dry_run', true );

		$controller->create_theme_template( $request );

		$this->assertTrue( $builder->received['dry_run'] ?? null, 'The REST handler dropped dry_run, so a "dry run" through the tool created the template.' );
	}
}
