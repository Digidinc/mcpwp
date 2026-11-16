<?php
/**
 * Regression coverage for #1231 — preserve _wp_page_template on Elementor saves.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class PageTemplatePreserve1231Test extends TestCase {

	private const PAGE_ID = 12310;

	/** @var array */
	private $meta_before = array();

	/** @var array */
	private $posts_before = array();

	protected function setUp(): void {
		$this->meta_before  = $GLOBALS['_mcpwp_test_meta'] ?? array();
		$this->posts_before = $GLOBALS['_mcpwp_test_posts'] ?? array();

		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PAGE_ID => new WP_Post(
				array(
					'ID'          => self::PAGE_ID,
					'post_type'   => 'page',
					'post_title'  => 'Template preserve',
					'post_status' => 'draft',
				)
			),
		);

		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.4.1' );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_meta']  = $this->meta_before;
		$GLOBALS['_mcpwp_test_posts'] = $this->posts_before;
	}

	private function minimal_section_json(): string {
		return wp_json_encode(
			array(
				array(
					'id'       => 'sec1231',
					'elType'   => 'section',
					'settings' => array(),
					'elements' => array(
						array(
							'id'       => 'col1231',
							'elType'   => 'column',
							'settings' => array( '_column_size' => 100 ),
							'elements' => array(),
						),
					),
				),
			)
		);
	}

	private function stored_template(): string {
		return (string) ( $GLOBALS['_mcpwp_test_meta'][ self::PAGE_ID ]['_wp_page_template'] ?? '' );
	}

	/**
	 * @dataProvider preserved_stored_templates
	 */
	public function test_set_elementor_dry_run_preserves_existing_template( string $stored, string $label ): void {
		if ( '' !== $stored ) {
			update_post_meta( self::PAGE_ID, '_wp_page_template', $stored );
		}

		$before = $this->stored_template();

		$result = ( new Mcpwp_Elementor_Basic() )->set_elementor_data(
			self::PAGE_ID,
			array(
				'elementor_data' => $this->minimal_section_json(),
				'dry_run'        => true,
			),
			true
		);

		$this->assertIsArray( $result, $label );
		$this->assertSame( $before, $this->stored_template(), "{$label}: meta unchanged." );
		$this->assertArrayHasKey( 'template', $result, $label );
		$this->assertFalse( $result['template']['requested'] );
		$this->assertSame( Mcpwp_Page_Template::response_slug( $before ), $result['template']['before'] );
		$this->assertSame( $result['template']['before'], $result['template']['after'] );
	}

	public static function preserved_stored_templates(): array {
		return array(
			'empty meta'              => array( '', 'empty' ),
			'default slug'            => array( 'default', 'default slug' ),
			'elementor canvas'        => array( 'elementor_canvas', 'canvas' ),
			'custom theme template'   => array( 'templates/wide.php', 'custom' ),
			'elementor header footer' => array( 'elementor_header_footer', 'already full width' ),
		);
	}

	public function test_set_elementor_can_set_template_explicitly_in_dry_run(): void {
		$result = ( new Mcpwp_Elementor_Basic() )->set_elementor_data(
			self::PAGE_ID,
			array(
				'elementor_data' => $this->minimal_section_json(),
				'dry_run'        => true,
				'template'       => 'elementor_canvas',
			),
			true
		);

		$this->assertIsArray( $result );
		$this->assertSame( '', $this->stored_template(), 'dry_run must not persist template.' );
		$this->assertTrue( $result['template']['requested'] );
		$this->assertSame( 'default', $result['template']['before'] );
		$this->assertSame( 'elementor_canvas', $result['template']['after'] );
	}

	public function test_create_elementor_page_without_template_leaves_meta_unset(): void {
		$result = ( new Mcpwp_Elementor_Basic() )->create_elementor_page( array( 'title' => 'Fresh' ) );

		$this->assertIsArray( $result );
		$page_id = (int) $result['page_id'];
		$this->assertSame( '', (string) get_post_meta( $page_id, '_wp_page_template', true ) );
		$this->assertFalse( $result['template']['requested'] );
		$this->assertSame( 'default', $result['template']['after'] );
	}

	public function test_create_elementor_page_honors_explicit_template(): void {
		$result = ( new Mcpwp_Elementor_Basic() )->create_elementor_page(
			array(
				'title'    => 'Canvas page',
				'template' => 'elementor_canvas',
			)
		);

		$this->assertIsArray( $result );
		$page_id = (int) $result['page_id'];
		$this->assertSame( 'elementor_canvas', get_post_meta( $page_id, '_wp_page_template', true ) );
		$this->assertTrue( $result['template']['requested'] );
		$this->assertSame( 'elementor_canvas', $result['template']['after'] );
	}

	public function test_wp_create_page_reports_template_change_without_forcing_slug(): void {
		$pages  = new Mcpwp_Pages();
		$created = $pages->create_page( array( 'title' => 'Plain create' ) );

		$this->assertIsArray( $created );
		$this->assertArrayHasKey( 'template_change', $created );
		$this->assertFalse( $created['template_change']['requested'] );
		$this->assertSame( 'default', $created['template'] );
		$this->assertSame( '', (string) get_post_meta( (int) $created['id'], '_wp_page_template', true ) );
	}

	public function test_wp_create_page_sets_valid_explicit_template(): void {
		$pages   = new Mcpwp_Pages();
		$created = $pages->create_page(
			array(
				'title'    => 'Canvas create',
				'template' => 'elementor_header_footer',
			)
		);

		$this->assertIsArray( $created );
		$this->assertTrue( $created['template_change']['requested'] );
		$this->assertSame( 'elementor_header_footer', $created['template'] );
		$this->assertSame(
			'elementor_header_footer',
			get_post_meta( (int) $created['id'], '_wp_page_template', true )
		);
	}
}