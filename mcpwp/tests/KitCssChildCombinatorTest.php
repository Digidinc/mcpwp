<?php
/**
 * Regression coverage: Kit CSS blocks must round-trip byte-for-byte.
 *
 * Reported from staging (2026-09-05), MCPWP 3.10.3: a 12,143-byte stylesheet
 * containing literal ">" child combinators was upserted, the response reported
 * the correct content hash, and a fresh read returned 12,184 bytes with every
 * ">" stored as "&gt;" — and some as double-escaped "&amp;gt;". The selectors
 * no longer matched anything.
 *
 * Reproduced locally on WordPress 7.0.2 with Elementor 4.2.4: the value is
 * clean on entry to update_post_meta() and HTML-escaped by the time it reaches
 * the postmeta row, for any request whose user lacks unfiltered_html — which
 * is every API-key request. The browser is unaffected because an administrator
 * has that capability.
 *
 * CSS is not HTML. Escaping it is not a safety control, it is data corruption:
 *  - ".a>.b" becomes ".a&gt;.b" and stops matching;
 *  - the stored block no longer hashes to the hash written in its own marker,
 *    so a later idempotent upsert reports "skipped" over already-broken CSS.
 *
 * These tests do not assert WHICH layer escapes — that is host- and
 * version-specific. They pin the property that must hold regardless: what the
 * caller sent is what the kit stores and returns, and a write that cannot be
 * verified is reported rather than announced as success.
 *
 * Each test runs in a separate process because the fixture declares
 * \Elementor\Plugin. A class cannot be undeclared once loaded and this suite
 * runs in one process, so declaring it inline would poison later tests (#757).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';

final class KitCssChildCombinatorTest extends TestCase {

	private const KIT_ID = 4;

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_posts']              = array();
		$GLOBALS['_mcpwp_test_meta']               = array();
		$GLOBALS['mcpwp_test_kit_id']              = self::KIT_ID;
		$GLOBALS['mcpwp_test_meta_escapes_html']   = false;
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_meta_escapes_html'] = false;
	}

	/**
	 * Install the Elementor kit doubles and a $wpdb that reflects the meta store.
	 *
	 * @param bool $host_escapes Whether the simulated host HTML-escapes meta writes.
	 */
	private function install( bool $host_escapes ): void {
		require_once __DIR__ . '/fixtures/elementor-kit-doubles.php';
		\Elementor\Plugin::$instance = new \Elementor\Plugin();

		$GLOBALS['mcpwp_test_meta_escapes_html'] = $host_escapes;

		// The production code reads the row directly (bypassing the object
		// cache) to verify the write, and repairs via $wpdb->update(). Both
		// have to act on the same store the meta stubs use, or the test would
		// only be checking the stub against itself.
		$GLOBALS['wpdb'] = new class() extends Mcpwp_Test_WPDB {

			public function get_var( $query = '', $x = 0, $y = 0 ) {
				if ( false !== strpos( (string) $query, '_elementor_page_settings' ) ) {
					$settings = $GLOBALS['_mcpwp_test_meta'][ KitCssChildCombinatorTest::kit_id() ]['_elementor_page_settings'] ?? null;
					return null === $settings ? null : maybe_serialize( $settings );
				}
				return null;
			}

			public function update( $table, $data, $where, $f = null, $wf = null ) {
				// A direct row write: it must NOT pass through the escaping
				// meta layer, which is the whole point of the repair path.
				$GLOBALS['_mcpwp_test_meta'][ (int) $where['post_id'] ][ $where['meta_key'] ] =
					maybe_unserialize( $data['meta_value'] );
				return 1;
			}
		};
	}

	public static function kit_id(): int {
		return self::KIT_ID;
	}

	private function upsert( string $name, string $css ) {
		$controller = new Mcpwp_REST_Elementor();

		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/kit-css/blocks' );
		$request->set_param( 'name', $name );
		$request->set_param( 'css', $css );

		return $controller->upsert_kit_css_block( $request );
	}

	private function read_block( string $name ) {
		$controller = new Mcpwp_REST_Elementor();

		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/kit-css/blocks/' . $name );
		$request->set_param( 'name', $name );

		return $controller->get_kit_css_block( $request );
	}

	private function data( $response ) {
		return $response instanceof WP_REST_Response ? $response->get_data() : $response;
	}

	private function list_blocks() {
		$controller = new Mcpwp_REST_Elementor();

		$request = new WP_REST_Request( 'GET', '/mcpwp/v1/elementor/kit-css/blocks' );

		return $controller->list_kit_css_blocks( $request );
	}

	/**
	 * Seed a block directly into the meta store whose stored body does NOT
	 * hash to the hash recorded in its own marker — the exact shape a
	 * pre-3.10.4 write left behind on an escaping host: the marker still
	 * carries the hash of the original, correct bytes, while the body on
	 * disk is the mangled ones.
	 *
	 * Deliberately bypasses upsert_kit_css_block(): driving the escaping host
	 * through the real write path now gets repaired by the 73f8f12 fix before
	 * it ever lands, so it can no longer produce this shape. Writing the row
	 * directly is the only way left to construct it.
	 *
	 * @param string $name        Block name.
	 * @param string $correct_css The CSS the marker's hash actually describes.
	 * @param string $stored_css  The (corrupted) bytes actually on disk.
	 */
	private function seed_corrupt_block( string $name, string $correct_css, string $stored_css ): void {
		$hash  = substr( hash( 'sha256', $correct_css ), 0, 16 );
		$block = sprintf(
			"/* mcpwp:block:start name=\"%s\" hash=\"%s\" */\n%s\n/* mcpwp:block:end name=\"%s\" */",
			$name,
			$hash,
			$stored_css,
			$name
		);

		$GLOBALS['_mcpwp_test_meta'][ self::KIT_ID ]['_elementor_page_settings'] = array(
			'custom_css' => $block,
		);
	}

	/**
	 * The reported defect, at its smallest.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_child_combinator_round_trips_byte_for_byte_on_an_escaping_host(): void {
		$this->install( true );

		$css = '.a>.b{color:red}';

		$written = $this->data( $this->upsert( 'combinator', $css ) );
		$this->assertNotInstanceOf( WP_Error::class, $written );

		$read = $this->data( $this->read_block( 'combinator' ) );
		$this->assertNotInstanceOf( WP_Error::class, $read );

		$this->assertSame(
			$css,
			$read['css'],
			'A child combinator must survive the round trip exactly — ".a&gt;.b" is a different stylesheet.'
		);
		$this->assertSame( strlen( $css ), $read['bytes'] );
	}

	/**
	 * The stored block must still hash to the hash recorded in its own marker.
	 * When it does not, a later identical upsert reports "skipped" over CSS
	 * that is already broken — the corruption becomes permanent and silent.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_stored_block_still_matches_its_own_recorded_hash(): void {
		$this->install( true );

		$css = '.nav>.item>a{display:block}';
		$this->upsert( 'nav', $css );

		$read = $this->data( $this->read_block( 'nav' ) );

		$this->assertSame(
			substr( hash( 'sha256', $read['css'] ), 0, 16 ),
			$read['hash'],
			'The marker hash must describe the CSS actually stored, or idempotency silently protects corrupt content.'
		);
	}

	/**
	 * A literal ampersand and a literal entity are ordinary CSS content and
	 * must survive untouched. This is why the fix compares bytes and repairs a
	 * mismatch, rather than unescaping the stored value — blind unescaping
	 * would corrupt exactly this case in the opposite direction.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_literal_ampersand_and_entity_text_round_trip_unchanged(): void {
		$this->install( true );

		$css = '.a[data-x="a&b"]::after{content:"&gt; & <"}';

		$this->upsert( 'entities', $css );
		$read = $this->data( $this->read_block( 'entities' ) );

		$this->assertSame(
			$css,
			$read['css'],
			'Literal &, < and an existing &gt; sequence must come back exactly as written.'
		);
	}

	/**
	 * On a host that does not mangle meta the fix must be inert: same bytes,
	 * and no repair claimed.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_clean_host_round_trips_and_reports_no_repair(): void {
		$this->install( false );

		$css = '.a>.b{color:red}';

		$written = $this->data( $this->upsert( 'clean', $css ) );
		$read    = $this->data( $this->read_block( 'clean' ) );

		$this->assertSame( $css, $read['css'] );
		$this->assertTrue( $written['verified'] );
		$this->assertArrayNotHasKey(
			'storage_repaired',
			$written,
			'A host that stored the bytes correctly must not be reported as having needed repair.'
		);
	}

	/**
	 * When the host mangles the write, the response must say so. A repair that
	 * happened silently and a write that was correct first time must not look
	 * identical to the caller.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_repaired_write_is_reported_not_swallowed(): void {
		$this->install( true );

		$written = $this->data( $this->upsert( 'reported', '.a>.b{color:red}' ) );

		$this->assertTrue( $written['verified'] );
		$this->assertTrue(
			$written['storage_repaired'],
			'The caller must be told the host altered the first write.'
		);
	}

	/**
	 * Part B of #762: re-upserting the correct CSS over a block whose stored
	 * body does not hash to its own marker must NOT return "skipped" — that
	 * is precisely the natural operator retry that the bug makes a no-op over
	 * already-broken content. It must rewrite, and a readback must return the
	 * correct bytes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reupserting_correct_css_over_a_hash_corrupt_block_rewrites_instead_of_skipping(): void {
		$this->install( true );

		$correct_css = '.a>.b{color:red}';
		$stored_css  = '.a&gt;.b{color:red}';
		$this->seed_corrupt_block( 'corrupt', $correct_css, $stored_css );

		$written = $this->data( $this->upsert( 'corrupt', $correct_css ) );

		$this->assertNotInstanceOf( WP_Error::class, $written );
		$this->assertNotSame(
			'skipped',
			$written['action'],
			'A hash match against a corrupt existing block must not be treated as "nothing to do".'
		);
		$this->assertTrue(
			$written['corrupt_block_rewritten'],
			'The response must say a corrupt block was rewritten, not just report an ordinary update.'
		);

		$read = $this->data( $this->read_block( 'corrupt' ) );
		$this->assertSame(
			$correct_css,
			$read['css'],
			'The readback after repair must return the correct bytes, not the corrupted ones that were on disk.'
		);
	}

	/**
	 * Part A of #762: list_kit_css_blocks() must flag a block whose stored
	 * body does not hash to its own marker.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_list_flags_a_hash_corrupt_block(): void {
		$this->install( true );

		$correct_css = '.nav>.item{display:block}';
		$stored_css  = '.nav&gt;.item{display:block}';
		$this->seed_corrupt_block( 'flagged', $correct_css, $stored_css );

		$blocks = $this->data( $this->list_blocks() )['blocks'];

		$this->assertCount( 1, $blocks );
		$this->assertTrue(
			$blocks[0]['hash_mismatch'],
			'A block whose stored body does not hash to its marker must be reported as mismatched.'
		);
	}

	/**
	 * The idempotency guarantee (#641) must survive #762's fix: an identical
	 * re-upsert over a healthy block is still a genuine no-op.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_healthy_block_still_reports_skipped_on_identical_reupsert(): void {
		$this->install( true );

		$css = '.healthy{color:blue}';
		$this->upsert( 'healthy', $css );

		$second = $this->data( $this->upsert( 'healthy', $css ) );

		$this->assertSame( 'skipped', $second['action'] );
		$this->assertArrayNotHasKey(
			'corrupt_block_rewritten',
			$second,
			'A healthy block must not be reported as a corrupt-block repair.'
		);
	}

	/**
	 * A healthy block — stored bytes that do hash to their own marker — must
	 * not be flagged as mismatched.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_list_does_not_flag_a_healthy_block(): void {
		$this->install( true );

		$this->upsert( 'healthy', '.healthy{color:blue}' );

		$blocks = $this->data( $this->list_blocks() )['blocks'];

		$this->assertCount( 1, $blocks );
		$this->assertFalse(
			$blocks[0]['hash_mismatch'],
			'A block whose stored body matches its own marker must not be flagged.'
		);
	}
}
