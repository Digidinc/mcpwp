<?php
/**
 * Standing guard for the shape of set_template_conditions()'s return.
 *
 * #785 changed that method from returning a list of conditions to returning
 * `array{conditions, warnings}` — necessary, because a response that carries
 * warnings only when something went wrong is a response nobody writes the
 * warning-reading branch for.
 *
 * The change then broke a caller I did not update. `assign_to_location()` ends
 * in `return $this->set_template_conditions(...)`, so `wp_assign_template`
 * started answering
 *
 *     {"conditions": {"conditions": [...], "warnings": []}, "assigned": true}
 *
 * A client reading `result.conditions[0].name` gets undefined. A client
 * counting `result.conditions.length` gets 2 — the number of keys in the
 * wrapper — no matter how many conditions were actually bound. That is the
 * same silent-shape defect this issue exists to fix, reintroduced one function
 * over, and found by review rather than by a test.
 *
 * So this is a structural check, not a test of that one handler: it asserts
 * that no response in the Theme Builder controller passes a wrapped result
 * straight into a field named for the thing inside it. A future method that
 * gains a wrapper is covered without anyone remembering to extend this file.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ThemeBuilderResponseShapeTest extends TestCase {

	private function controller_source(): string {
		$path = dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-theme-builder.php';

		$this->assertFileExists( $path );

		$source = file_get_contents( $path );

		$this->assertIsString( $source );

		return $source;
	}

	/**
	 * Methods on Mcpwp_Theme_Builder that return array{conditions, warnings}.
	 *
	 * @return array<int,string>
	 */
	private function wrapping_methods(): array {
		return array( 'set_template_conditions', 'assign_to_location', 'remove_from_locations' );
	}

	public function test_the_wrapping_methods_still_wrap(): void {
		// The premise. If someone unwraps set_template_conditions() again, the
		// assertions below stop describing reality and this test should be the
		// thing that says so — not a mystery failure in a client.
		$source = file_get_contents( dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-theme-builder.php' );

		$this->assertIsString( $source );
		$this->assertStringContainsString(
			"'conditions' => \$this->get_template_conditions( \$template_id ),",
			$source,
			'set_template_conditions() no longer returns the documented wrapper.'
		);
		$this->assertStringContainsString( "'warnings'   => \$warnings,", $source );
	}

	public function test_no_handler_assigns_a_wrapped_result_straight_into_conditions(): void {
		$source = $this->controller_source();

		// The exact defect: `'conditions'  => $result,` where $result is the
		// wrapper. Written as a pattern so any spelling of the whitespace is
		// caught, and so a new handler making the same mistake is caught too.
		$this->assertSame(
			0,
			preg_match_all( "/'conditions'\s*=>\s*\\\$result\s*,/", $source ),
			'A handler is passing a wrapped result into the "conditions" field. Read $result[\'conditions\'] instead — a client counting result.conditions.length would get 2 regardless of how many conditions were bound.'
		);
	}

	public function test_every_call_to_a_wrapping_method_unwraps_it(): void {
		$source = $this->controller_source();

		foreach ( $this->wrapping_methods() as $method ) {
			if ( ! preg_match_all( '/\$(\w+)\s*=\s*\$this->theme_builder->' . preg_quote( $method, '/' ) . '\(/', $source, $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $variable ) {
				// Either the handler reads a key off it, or it discards it
				// entirely (remove_conditions does). What it must not do is
				// hand the whole wrapper to a client under a name that
				// promises the list.
				$reads_a_key = preg_match( '/\$' . preg_quote( $variable, '/' ) . "\['(conditions|warnings)'\]/", $source );
				$passes_raw  = preg_match( "/=>\s*\\\$" . preg_quote( $variable, '/' ) . '\s*,/', $source );

				$this->assertTrue(
					(bool) $reads_a_key || ! $passes_raw,
					sprintf(
						'The result of %s() is put into a response without unwrapping it. It is array{conditions, warnings}, not a list of conditions.',
						$method
					)
				);
			}
		}
	}

	public function test_both_condition_handlers_surface_warnings(): void {
		$source = $this->controller_source();

		// Unwrapping alone would fix the shape and quietly drop the warnings,
		// which is how a caller ends up with fewer conditions than it asked
		// for and no way to see it.
		//
		// Counted, not merely found: set_conditions and assign_to_location each
		// carry this line, so an assertion that only checks presence still
		// passes with either one deleted — which is precisely how the assign
		// handler was missed to begin with.
		$this->assertSame(
			2,
			preg_match_all( '/\'warnings\'\s*=>\s*\$result\[\'warnings\'\]/', $source ),
			'Both set_conditions and assign_to_location must surface the warnings their result carries.'
		);
	}
}
