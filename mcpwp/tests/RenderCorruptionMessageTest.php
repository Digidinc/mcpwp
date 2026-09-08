<?php
/**
 * Regression coverage for #612's remaining surface — a warning that reads as
 * though nothing is wrong, and never says why anything is.
 *
 * #612 has a long history: "wp_set_elementor reports success but immediate
 * readback is empty". The original cause (a layout-mode mismatch) was fixed in
 * 3.4.8, and the destructive step the reporter isolated afterwards —
 * Elementor's own post-save rebuild discarding a known-good tree — is now
 * detected, the exact submitted bytes restored, and render_verified set false.
 *
 * What was still wrong is what the caller is told. Reproduced on 3.10.8
 * against real Elementor on the rig, writing as a user without
 * `unfiltered_html`:
 *
 *     html_rebuild_corrupted_data: true
 *     before/after:                32 / 32
 *     warning: "Elementor rendered a truncated or otherwise changed tree
 *               (32 of 32 elements)"
 *
 * "32 of 32" reads as nothing being wrong. The comparator is SEMANTIC — it
 * fires when the tree changed, which is not the same as the tree shrinking —
 * but the message was built only from element counts, so the case where
 * settings inside surviving elements were altered came out looking benign. A
 * real warning that invites dismissal is barely better than no warning, which
 * is the whole complaint behind this issue family.
 *
 * The same run showed the cause was known and unsaid: MCPWP had recorded
 * `unfiltered_html_grant_declined` in its debug bag, so it knew the content had
 * been sanitized and why, and reported neither.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class RenderCorruptionMessageTest extends TestCase {

	/**
	 * @param array $debug Debug bag from a write.
	 * @return array Render errors.
	 */
	private function errors( array $debug ): array {
		$writer = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'collect_render_errors' );
		$method->setAccessible( true );

		return $method->invoke( $writer, $debug );
	}

	private function corruption( int $before, int $after, bool $grant_declined = false ): array {
		$debug = array(
			'document_save'                => true,
			'html_cache_rebuilt'           => true,
			'html_rebuild_corrupted_data'  => true,
			'html_rebuild_elements_before' => $before,
			'html_rebuild_elements_after'  => $after,
		);

		if ( $grant_declined ) {
			$debug['unfiltered_html_grant_declined'] = 'core content_save_pre KSES gate is not installed';
		}

		return $debug;
	}

	public function test_equal_counts_report_a_content_change_not_a_truncation(): void {
		$text = implode( "\n", $this->errors( $this->corruption( 32, 32 ) ) );

		// The reported wording. "32 of 32 elements" alongside the word
		// "truncated" is a warning a reader talks themselves out of.
		$this->assertStringNotContainsString( '32 of 32', $text );
		$this->assertStringNotContainsString( 'truncated', $text );

		$this->assertStringContainsString( 'changed the CONTENT', $text );
		$this->assertStringContainsString( 'all 32 elements', $text );
		$this->assertStringContainsString( 'settings inside them differ', $text );
	}

	public function test_a_real_truncation_still_says_truncation(): void {
		$text = implode( "\n", $this->errors( $this->corruption( 41, 0 ) ) );

		// #612's own decisive control test: 41 elements in, 0 out.
		$this->assertStringContainsString( 'dropped elements', $text );
		$this->assertStringContainsString( '0 of 41 survived', $text );
		$this->assertStringContainsString( 'truncated version', $text );
	}

	public function test_both_shapes_say_the_stored_data_is_intact(): void {
		// The restore is the reason this is a warning and not a failure, and
		// the caller cannot know that unless told — otherwise the reasonable
		// response is to rewrite the page, which achieves nothing.
		foreach ( array( $this->corruption( 32, 32 ), $this->corruption( 41, 0 ) ) as $debug ) {
			$this->assertStringContainsString(
				'restored to the database',
				implode( "\n", $this->errors( $debug ) )
			);
		}
	}

	public function test_the_cause_is_named_when_it_is_known(): void {
		$text = implode( "\n", $this->errors( $this->corruption( 32, 32, true ) ) );

		$this->assertStringContainsString( 'unfiltered_html', $text );
		$this->assertStringContainsString( 'KSES gate', $text );

		// Actionable specifics, not just a diagnosis: these are the things a
		// caller will find missing from the rendered page.
		$this->assertStringContainsString( 'iframes', $text );
		$this->assertStringContainsString( 'data-*', $text );
	}

	public function test_the_cause_is_not_invented_when_it_is_not_known(): void {
		$text = implode( "\n", $this->errors( $this->corruption( 32, 32 ) ) );

		// The grant was not declined here, so sanitization is not the
		// explanation. Offering it anyway would send the caller chasing a
		// capability problem they do not have.
		$this->assertStringNotContainsString( 'unfiltered_html', $text );
	}

	public function test_a_declined_grant_alone_is_not_reported_as_an_error(): void {
		// Declining is the correct, safe outcome on a site where core is not
		// filtering post_content. It is only worth raising as the explanation
		// for a corruption that actually happened.
		$errors = $this->errors(
			array(
				'document_save'                  => true,
				'unfiltered_html_grant_declined' => 'core content_save_pre KSES gate is not installed',
			)
		);

		$this->assertSame( array(), $errors );
	}

	public function test_a_clean_write_produces_no_errors(): void {
		$this->assertSame(
			array(),
			$this->errors( array( 'document_save' => true, 'html_cache_rebuilt' => true ) )
		);
	}
}
