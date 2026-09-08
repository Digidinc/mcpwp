<?php
/**
 * Regression coverage for #788 — surgical Elementor writes converted a page
 * built with something else, and destroyed its content.
 *
 * The surgical operations accept any post ID `get_post()` resolves. There was
 * no post-type check and nothing that asked whether the page was built with
 * Elementor — despite a comment in add_section() that reads "Validate page
 * exists and has Elementor" above a bare `get_post()` call.
 *
 * On a Gutenberg or classic page, `_elementor_data` decodes to nothing, the
 * element list starts empty, the new section is appended, and the write goes
 * through. The page's original content does not survive: the fallback branch
 * blanks `post_content` outright, and the document branch has Elementor
 * rewrite it to its own plain-text render. The response reported success.
 *
 * This predates the builder flag. What the flag changed is that the conversion
 * now completes visibly — the page renders the Elementor tree rather than
 * rendering blank — which is more honest and makes the missing precondition
 * worth enforcing rather than merely describing.
 *
 * The guard is deliberately narrow: content that exists, and no Elementor data
 * to explain it. A page that already has Elementor data is an Elementor page;
 * an empty page has nothing to lose. Both keep working untouched, which
 * matters because the empty-page case is the ordinary one and is exactly what
 * #785 defect 4 was about.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ContentConversionGuardTest extends TestCase {

	private const PAGE_ID = 9200;

	/** @var array */
	private $meta_before = array();

	/** @var array */
	private $posts_before = array();

	protected function setUp(): void {
		$this->meta_before  = $GLOBALS['_mcpwp_test_meta'] ?? array();
		$this->posts_before = $GLOBALS['_mcpwp_test_posts'] ?? array();

		$GLOBALS['_mcpwp_test_meta']  = array();
		$GLOBALS['_mcpwp_test_posts'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['_mcpwp_test_meta']  = $this->meta_before;
		$GLOBALS['_mcpwp_test_posts'] = $this->posts_before;
	}

	/**
	 * @param string $content Existing post_content.
	 */
	private function seed_post( string $content = '' ): void {
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = new WP_Post(
			array(
				'ID'           => self::PAGE_ID,
				'post_type'    => 'page',
				'post_title'   => 'Conversion guard probe',
				'post_name'    => 'conversion-guard-probe',
				'post_status'  => 'draft',
				'post_content' => $content,
			)
		);
	}

	private function gutenberg_content(): string {
		return "<!-- wp:paragraph -->\n<p>Content someone wrote and would like to keep.</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * @return array One valid top-level section.
	 */
	private function section(): array {
		return array(
			'id'       => 'sec00001',
			'elType'   => 'section',
			'settings' => array(),
			'elements' => array(
				array(
					'id'       => 'col00001',
					'elType'   => 'column',
					'settings' => array( '_column_size' => 100 ),
					'elements' => array(),
				),
			),
		);
	}

	private function writer(): Mcpwp_Elementor_Basic {
		return ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
	}

	private function save( array $elements, array $options = array() ) {
		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'save_elements_to_page' );
		$method->setAccessible( true );

		return $method->invokeArgs( $this->writer(), array( self::PAGE_ID, $elements, $options ) );
	}

	// ---------------------------------------------------------------
	// The refusal.
	// ---------------------------------------------------------------

	public function test_a_page_with_block_content_and_no_elementor_data_is_refused(): void {
		$this->seed_post( $this->gutenberg_content() );

		$result = $this->save( array( $this->section() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'would_convert_existing_content', $result->get_error_code() );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	public function test_the_refusal_says_how_much_content_is_at_stake_and_how_to_proceed(): void {
		$content = $this->gutenberg_content();
		$this->seed_post( $content );

		$data = $this->save( array( $this->section() ) )->get_error_data();

		// A refusal that does not say what it is protecting, or how to
		// override it, gets worked around by guessing.
		$this->assertSame( strlen( $content ), $data['existing_content_bytes'] );
		$this->assertStringContainsString( 'convert=true', $data['hint'] );
	}

	public function test_the_content_is_still_there_after_a_refusal(): void {
		$this->seed_post( $this->gutenberg_content() );

		$this->save( array( $this->section() ) );

		// The whole point. A guard that refuses and writes anyway is worse
		// than none, because it also lies.
		$this->assertSame( $this->gutenberg_content(), get_post( self::PAGE_ID )->post_content );
		$this->assertSame( '', get_post_meta( self::PAGE_ID, '_elementor_data', true ) );
	}

	public function test_classic_content_counts_too(): void {
		// Not just blocks. Plain classic content is destroyed by the same
		// write, so "has block markup" would be the wrong condition.
		$this->seed_post( '<p>A classic-editor page.</p>' );

		$this->assertInstanceOf( WP_Error::class, $this->save( array( $this->section() ) ) );
	}

	// ---------------------------------------------------------------
	// What must keep working. A guard that refuses the ordinary case is
	// a worse bug than the one it fixes.
	// ---------------------------------------------------------------

	public function test_an_empty_page_is_written_normally(): void {
		$this->seed_post( '' );

		$this->assertIsArray(
			$this->save( array( $this->section() ) ),
			'A fresh draft has nothing to lose — and this is the ordinary case, including every page wp_apply_elementor_part targets.'
		);
	}

	public function test_whitespace_only_content_is_treated_as_empty(): void {
		$this->seed_post( "  \n\t " );

		$this->assertIsArray( $this->save( array( $this->section() ) ) );
	}

	public function test_a_page_that_already_has_elementor_data_is_written_normally(): void {
		$this->seed_post( 'Elementor plain-text render of the existing layout' );
		update_post_meta( self::PAGE_ID, '_elementor_data', wp_json_encode( array( $this->section() ) ) );

		// post_content is non-empty here precisely BECAUSE Elementor wrote it.
		// Refusing on that would block every second write to every Elementor
		// page in existence.
		$this->assertIsArray( $this->save( array( $this->section() ) ) );
	}

	public function test_clearing_a_page_is_not_a_conversion(): void {
		$this->seed_post( $this->gutenberg_content() );

		$this->assertIsArray(
			$this->save( array() ),
			'Writing an empty list removes Elementor data; it does not hand the page to Elementor.'
		);
	}

	public function test_the_explicit_opt_in_is_honoured(): void {
		$this->seed_post( $this->gutenberg_content() );

		$this->assertIsArray( $this->save( array( $this->section() ), array( 'convert' => true ) ) );
	}

	// ---------------------------------------------------------------
	// Robustness of the guard itself.
	// ---------------------------------------------------------------

	public function test_a_post_object_without_post_content_does_not_fatal(): void {
		// get_post() can return an object lacking the property — a filtered
		// post, a stub. A guard that fatals on the shape of the thing it is
		// protecting protects nothing, and "no readable content" is the
		// permissive answer.
		$stub     = new stdClass();
		$stub->ID = self::PAGE_ID;

		$GLOBALS['_mcpwp_test_posts'][ self::PAGE_ID ] = $stub;

		$this->assertIsArray( $this->save( array( $this->section() ) ) );
	}

	public function test_unreadable_elementor_data_does_not_wave_the_write_through(): void {
		// Corrupt meta must not read as "already an Elementor page" — that
		// would be an unavailable source and a positive answer producing the
		// same result, which is the failure mode this whole issue family is
		// about.
		$this->seed_post( $this->gutenberg_content() );
		update_post_meta( self::PAGE_ID, '_elementor_data', '{not json' );

		$this->assertInstanceOf( WP_Error::class, $this->save( array( $this->section() ) ) );
	}

	public function test_empty_elementor_data_does_not_wave_the_write_through(): void {
		// "[]" is an Elementor page with nothing on it — but it is also what a
		// page that has never touched Elementor looks like, so it cannot on
		// its own justify overwriting real content.
		$this->seed_post( $this->gutenberg_content() );
		update_post_meta( self::PAGE_ID, '_elementor_data', '[]' );

		$this->assertInstanceOf( WP_Error::class, $this->save( array( $this->section() ) ) );
	}
}
