<?php
/**
 * Regression coverage for #785 defect 3 — condition strings read one character
 * at a time.
 *
 * Reported: wp_set_template_conditions called with
 *
 *     conditions: ["include/singular/post"]
 *
 * answered updated:true and stored
 *
 *     {"type":"include","name":"n","sub_name":"c","sub_id":"l"}
 *
 * Those are characters 1, 2 and 3 of the word "include". The template was
 * bound to a condition named "n", which matches nothing, and the call reported
 * success.
 *
 * The cause is a PHP asymmetry that reads as a type check but is not one. The
 * branch was:
 *
 *     if ( isset( $condition[0] ) && ! isset( $condition['type'] ) )
 *
 * For a string, `isset($str[0])` is TRUE — that is a string offset, the first
 * character — and `isset($str['type'])` is FALSE, because 'type' is not a
 * numeric offset. So a string satisfies both halves of a test written to
 * identify a positional ARRAY, and every subsequent $condition[1], [2], [3]
 * read a character.
 *
 * `include/singular/post` is not an odd thing to send: it is Elementor's own
 * storage format, the one its Conditions_Manager parses.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ThemeBuilderConditionStringTest extends TestCase {

	/**
	 * @param string $condition Condition string under test.
	 * @return array|null
	 */
	private function parse( string $condition ) {
		$method = new ReflectionMethod( Mcpwp_Theme_Builder::class, 'parse_condition_string' );
		$method->setAccessible( true );

		return $method->invoke( null, $condition );
	}

	public function test_the_reported_string_parses_into_the_condition_it_names(): void {
		$this->assertSame(
			array(
				'type'     => 'include',
				'name'     => 'singular',
				'sub_name' => 'post',
				'sub_id'   => '',
			),
			$this->parse( 'include/singular/post' )
		);
	}

	public function test_the_characterwise_result_is_gone(): void {
		$parsed = $this->parse( 'include/singular/post' );

		// Pinned as its own assertion because this exact shape is what the
		// site stored, and it is the thing a future refactor would silently
		// bring back.
		$this->assertNotSame( 'n', $parsed['name'] );
		$this->assertNotSame( 'c', $parsed['sub_name'] );
		$this->assertNotSame( 'l', $parsed['sub_id'] );
	}

	public function test_exclude_is_carried_through(): void {
		$this->assertSame(
			array(
				'type'     => 'exclude',
				'name'     => 'singular',
				'sub_name' => 'page',
				'sub_id'   => '42',
			),
			$this->parse( 'exclude/singular/page/42' )
		);
	}

	public function test_a_bare_condition_name_defaults_to_include(): void {
		$this->assertSame(
			array(
				'type'     => 'include',
				'name'     => 'general',
				'sub_name' => '',
				'sub_id'   => '',
			),
			$this->parse( 'general' )
		);
	}

	public function test_whitespace_around_the_segments_is_tolerated(): void {
		$this->assertSame(
			array(
				'type'     => 'include',
				'name'     => 'archive',
				'sub_name' => 'product',
				'sub_id'   => '',
			),
			$this->parse( ' include / archive / product ' )
		);
	}

	/**
	 * @dataProvider unparseable_conditions
	 * @param string $condition Input that cannot be a condition.
	 * @param string $why       Why refusing beats guessing.
	 */
	public function test_unparseable_strings_are_refused_rather_than_guessed( string $condition, string $why ): void {
		$this->assertNull( $this->parse( $condition ), $why );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function unparseable_conditions(): array {
		return array(
			'empty'            => array( '', 'An empty condition names nothing.' ),
			'whitespace only'  => array( "  \t ", 'Neither does whitespace.' ),
			'type with no name' => array(
				'include',
				'"include" on its own is half a condition. Defaulting the name would bind the template to something the caller never asked for.',
			),
			'unknown type'     => array(
				'sometimes/singular/post',
				'The old code coerced anything that was not "exclude" to "include", so a typo in the type silently inverted the caller\'s intent.',
			),
			'missing name'     => array( 'include//post', 'A condition with an empty name matches nothing.' ),
		);
	}

	public function test_the_type_is_not_silently_coerced_to_include(): void {
		// The object and positional branches both still do this — anything
		// that is not the literal 'exclude' becomes 'include'. For a string,
		// where the type is the segment most likely to be mistyped, refusing
		// is the honest answer.
		$this->assertNull( $this->parse( 'exlcude/singular/post' ) );
	}

	// ---------------------------------------------------------------
	// The dispatch. This, not the parser, is where the bug lived: a
	// string was never routed to a parser at all, because the test that
	// was supposed to recognise a positional array accepted it.
	// ---------------------------------------------------------------

	/**
	 * @param array $conditions Raw conditions as a caller would send them.
	 * @return array{conditions:array,warnings:array}
	 */
	private function format( array $conditions ): array {
		$method = new ReflectionMethod( Mcpwp_Theme_Builder::class, 'format_conditions' );
		$method->setAccessible( true );

		return $method->invoke( null, $conditions );
	}

	public function test_the_reported_call_end_to_end(): void {
		$result = $this->format( array( 'include/singular/post' ) );

		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame(
			array(
				array(
					'type'     => 'include',
					'name'     => 'singular',
					'sub_name' => 'post',
					'sub_id'   => '',
				),
			),
			$result['conditions'],
			'This is the exact call from the report. It stored name "n".'
		);
	}

	public function test_the_php_asymmetry_that_caused_it(): void {
		// Pinned so the reason survives, not just the fix. The old branch was
		// `isset($condition[0]) && ! isset($condition['type'])`, written to
		// mean "a positional array". Both halves are true for a string, which
		// is why the string fell through to $condition[1], [2], [3].
		$condition = 'include/singular/post';

		$this->assertTrue( isset( $condition[0] ) );
		$this->assertFalse( isset( $condition['type'] ) );
		$this->assertSame( 'i', $condition[0] );
		$this->assertSame( 'n', $condition[1] );
	}

	public function test_the_positional_array_form_still_works(): void {
		// #210's form. The string fix must not cost it.
		$result = $this->format( array( array( 'exclude', 'singular', 'product' ) ) );

		$this->assertSame(
			array(
				array(
					'type'     => 'exclude',
					'name'     => 'singular',
					'sub_name' => 'product',
					'sub_id'   => '',
				),
			),
			$result['conditions']
		);
	}

	public function test_the_object_form_still_works(): void {
		$result = $this->format(
			array(
				array(
					'type'     => 'include',
					'name'     => 'singular',
					'sub_name' => 'post',
				),
			)
		);

		$this->assertSame( 'singular', $result['conditions'][0]['name'] );
		$this->assertSame( 'post', $result['conditions'][0]['sub_name'] );
	}

	public function test_a_bad_condition_beside_a_good_one_is_reported_not_swallowed(): void {
		$result = $this->format( array( 'include/singular/post', 'include' ) );

		$this->assertCount( 1, $result['conditions'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'Ignored condition "include"', $result['warnings'][0] );
	}

	// ---------------------------------------------------------------
	// The object and positional forms had the same coercions the string
	// form was hardened against. Fixing one input shape of three left
	// the other two able to do worse than the reported bug.
	// ---------------------------------------------------------------

	public function test_a_typo_in_an_object_type_does_not_invert_the_caller_intent(): void {
		// Every branch used to coerce anything that was not the literal
		// 'exclude' to 'include'. One transposed pair of letters therefore
		// turned "keep this template off posts" into "put it on posts", and
		// reported success.
		$result = $this->format(
			array(
				array( 'type' => 'exlcude', 'name' => 'singular', 'sub_name' => 'post' ),
			)
		);

		$this->assertSame( array(), $result['conditions'] );
		$this->assertCount( 1, $result['warnings'] );
	}

	public function test_an_object_with_no_name_does_not_become_the_entire_site(): void {
		// A missing name became 'general' — Elementor's ENTIRE SITE condition
		// — and save_conditions() replaces the whole set, so this detached a
		// header from everything it matched and rebound it site-wide under
		// updated:true.
		$result = $this->format( array( array( 'type' => 'include' ) ) );

		$this->assertSame( array(), $result['conditions'] );
		$this->assertStringContainsString( 'name must not be empty', $result['warnings'][0] );
	}

	public function test_a_positional_array_with_no_name_is_refused_too(): void {
		$result = $this->format( array( array( 'include' ) ) );

		$this->assertSame( array(), $result['conditions'] );
		$this->assertCount( 1, $result['warnings'] );
	}

	public function test_an_omitted_type_still_defaults_to_include(): void {
		// The one coercion worth keeping: silence has a single reading, and
		// callers listing conditions without a type mean include. A type that
		// is PRESENT but wrong has a second reading, which is why that one is
		// refused instead.
		$result = $this->format( array( array( 'name' => 'singular', 'sub_name' => 'post' ) ) );

		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame( 'include', $result['conditions'][0]['type'] );
		$this->assertSame( 'singular', $result['conditions'][0]['name'] );
	}

	public function test_a_numeric_sub_id_survives_normalisation(): void {
		$result = $this->format(
			array(
				array( 'type' => 'include', 'name' => 'singular', 'sub_name' => 'page', 'sub_id' => 42 ),
			)
		);

		$this->assertSame( '42', $result['conditions'][0]['sub_id'] );
	}

	public function test_a_non_string_non_array_condition_is_reported(): void {
		$result = $this->format( array( 42 ) );

		$this->assertSame( array(), $result['conditions'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'integer', $result['warnings'][0] );
	}

	public function test_mixed_forms_in_one_call_all_land(): void {
		$result = $this->format(
			array(
				'include/singular/post',
				array( 'exclude', 'singular', 'page' ),
				array( 'type' => 'include', 'name' => 'archive' ),
			)
		);

		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame(
			array( 'singular', 'singular', 'archive' ),
			array_column( $result['conditions'], 'name' )
		);
		$this->assertSame(
			array( 'include', 'exclude', 'include' ),
			array_column( $result['conditions'], 'type' )
		);
	}
}
