<?php
/**
 * Coverage for tool `examples` (#912): every `examples` entry a tool's
 * inputSchema carries must itself validate against that SAME inputSchema —
 * an example that would be refused by the tool it documents is worse than
 * no example at all.
 *
 * Mcpwp_MCP_Output_Schema (server-side outputSchema validator) is NOT
 * reused here: its ASSERTION_KEYWORDS/ANNOTATION_KEYWORDS lists fail closed
 * on any keyword outside a small allowlist, and several inputSchemas here
 * carry `minItems`/`maxItems` (define_tool()'s SCHEMA_CONSTRAINT_KEYS),
 * which that class treats as an unsupported keyword and rejects the whole
 * schema over. inputSchema is also always `additionalProperties`-open
 * (define_tool() never sets it), which that class defaults closed-safe in
 * a way that does not match this surface. A minimal, separate validator
 * below covers exactly what define_tool() actually emits.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-figma.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-ai-integration.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-figma-integration.php';

final class McpToolExamplesTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();
		$GLOBALS['mcpwp_test_fs']         = new Mcpwp_Test_Fs_Stub( true, true, false, 'pro' );
		$ref                              = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_fs'] = null;
		$ref                      = new ReflectionProperty( Mcpwp_License::class, 'instance' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}

	/**
	 * @return Mcpwp_MCP_Tool_Registry[]
	 */
	private function all_registries(): array {
		return array(
			new Mcpwp_MCP_Free_Tools(),
			new Mcpwp_MCP_Pro_Tools(),
			new Mcpwp_MCP_AI_Integration(),
			new Mcpwp_MCP_Figma_Integration(),
		);
	}

	/**
	 * @return array<string, array> tool name => inputSchema, across every
	 *                              registry. First definition wins (none
	 *                              collide today).
	 */
	private function all_tool_schemas(): array {
		$schemas = array();
		foreach ( $this->all_registries() as $registry ) {
			foreach ( $registry->get_tools() as $tool ) {
				if ( ! isset( $schemas[ $tool['name'] ] ) ) {
					$schemas[ $tool['name'] ] = $tool['inputSchema'];
				}
			}
		}
		return $schemas;
	}

	/**
	 * At least the ~20 hardest-param tools named in #912 carry examples —
	 * a regression guard so a future refactor of these tool definitions
	 * cannot silently drop the annotation.
	 */
	public function test_the_hardest_param_tools_carry_examples(): void {
		$expected = array(
			'wp_set_elementor',
			'wp_edit_widget',
			'wp_patch_elementor',
			'wp_build_page',
			'wp_add_section',
			'wp_replace_section',
			'wp_setup_menu',
			'wp_set_seo',
			'wp_bulk_seo',
			'wp_set_template_conditions',
			'wp_create_elementor_custom_code',
			'wp_graph_query',
			'wp_batch',
			'wp_bulk_find_replace',
			'wp_upload_media_from_url',
			'wp_create_post',
			'wp_update_page',
			'wc_create_product',
			'wp_create_theme_template',
			'wp_apply_elementor_archetype',
		);

		$schemas = $this->all_tool_schemas();
		foreach ( $expected as $name ) {
			$this->assertArrayHasKey( $name, $schemas, "{$name}: tool not found in any registry" );
			$this->assertArrayHasKey( 'examples', $schemas[ $name ], "{$name}: expected an examples annotation" );
			$this->assertIsArray( $schemas[ $name ]['examples'], "{$name}: examples is not an array" );
			$this->assertNotEmpty( $schemas[ $name ]['examples'], "{$name}: examples is empty" );
		}
	}

	/**
	 * Every example on every tool across every registry validates against
	 * that same tool's own inputSchema. This is the load-bearing assertion
	 * #912 asked for: an example that would be refused by the tool it
	 * documents is worse than no example.
	 */
	public function test_every_tool_examples_validate_against_its_own_input_schema(): void {
		$checked = 0;
		foreach ( $this->all_tool_schemas() as $name => $schema ) {
			if ( ! isset( $schema['examples'] ) ) {
				continue;
			}
			$this->assertIsArray( $schema['examples'], "{$name}: examples must be an array of argument objects" );
			foreach ( $schema['examples'] as $index => $example ) {
				++$checked;
				$this->assertIsArray( $example, "{$name}: examples[{$index}] must be an object (associative array)" );
				$errors = self::validate_against_schema( $example, $schema );
				$this->assertSame(
					array(),
					$errors,
					sprintf( "%s: examples[%d] does not validate against its own inputSchema:\n%s", $name, $index, implode( "\n", $errors ) )
				);
			}
		}
		// Sanity: this test is meaningless if it silently checked nothing.
		$this->assertGreaterThanOrEqual( 20, $checked, 'Expected at least the 20 #912 example sets to be checked.' );
	}

	// ── Minimal JSON-Schema-subset validator for a tool's own inputSchema ──
	//
	// Supports exactly what Mcpwp_MCP_Tool_Registry::define_tool() emits:
	// type (string or list), properties, required, items (single schema),
	// enum, minItems, maxItems, minimum, maximum. additionalProperties is
	// always allowed (define_tool() never restricts it — an inputSchema is
	// never closed the way an outputSchema is), and unknown keywords
	// (description, default, format, title, examples) are ignored as
	// annotations rather than failing closed — unlike
	// Mcpwp_MCP_Output_Schema, this validator's job is only to catch a
	// wrong TYPE or a MISSING required key in a hand-authored example, not
	// to police an outputSchema-grade contract.

	/**
	 * @param mixed $value  PHP value (associative arrays for objects, lists
	 *                      for arrays — as authored directly in PHP, not
	 *                      JSON-decoded).
	 * @param array $schema JSON Schema fragment.
	 * @param string $path  For error messages.
	 * @return string[] Failures, empty when valid.
	 */
	private static function validate_against_schema( $value, array $schema, string $path = '$' ): array {
		$errors = array();

		if ( isset( $schema['type'] ) ) {
			$types = is_array( $schema['type'] ) ? $schema['type'] : array( $schema['type'] );
			$match = false;
			foreach ( $types as $type ) {
				if ( self::matches_type( $value, $type ) ) {
					$match = true;
					break;
				}
			}
			if ( ! $match ) {
				$errors[] = sprintf( '%s: expected type %s, got %s', $path, implode( '|', $types ), self::describe_type( $value ) );
				// A type mismatch makes every other check meaningless (e.g.
				// checking `properties` against a string). Stop here.
				return $errors;
			}
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) ) {
			$found = false;
			foreach ( $schema['enum'] as $allowed ) {
				if ( $allowed === $value ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$errors[] = sprintf( '%s: value is not one of the allowed enum values', $path );
			}
		}

		if ( is_array( $value ) && self::is_assoc( $value ) ) {
			// Object.
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
			foreach ( $properties as $prop_name => $prop_schema ) {
				if ( array_key_exists( $prop_name, $value ) ) {
					$errors = array_merge(
						$errors,
						self::validate_against_schema( $value[ $prop_name ], (array) $prop_schema, $path . '.' . $prop_name )
					);
				}
			}
			if ( isset( $schema['required'] ) && is_array( $schema['required'] ) ) {
				foreach ( $schema['required'] as $required_key ) {
					if ( ! array_key_exists( $required_key, $value ) ) {
						$errors[] = sprintf( "%s: missing required '%s'", $path, $required_key );
					}
				}
			}
		} elseif ( is_array( $value ) ) {
			// List (JSON array).
			if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) {
				$errors[] = sprintf( '%s: fewer than minItems (%d)', $path, $schema['minItems'] );
			}
			if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) {
				$errors[] = sprintf( '%s: more than maxItems (%d)', $path, $schema['maxItems'] );
			}
			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				foreach ( $value as $index => $item ) {
					$errors = array_merge(
						$errors,
						self::validate_against_schema( $item, $schema['items'], $path . '[' . $index . ']' )
					);
				}
			}
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			if ( isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
				$errors[] = sprintf( '%s: below minimum (%s)', $path, $schema['minimum'] );
			}
			if ( isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
				$errors[] = sprintf( '%s: above maximum (%s)', $path, $schema['maximum'] );
			}
		}

		return $errors;
	}

	/**
	 * @param mixed  $value PHP value.
	 * @param string $type  JSON Schema type name.
	 * @return bool
	 */
	private static function matches_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'object':
				return is_array( $value ) && ( self::is_assoc( $value ) || array() === $value );
			case 'array':
				return is_array( $value ) && ( ! self::is_assoc( $value ) || array() === $value );
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value ) || ( is_float( $value ) && floor( $value ) === $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'boolean':
				return is_bool( $value );
			case 'null':
				return null === $value;
		}
		return false;
	}

	/**
	 * @param mixed $value PHP value.
	 * @return string
	 */
	private static function describe_type( $value ): string {
		if ( is_array( $value ) ) {
			return self::is_assoc( $value ) ? 'object' : 'array';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( null === $value ) {
			return 'null';
		}
		return 'string';
	}

	/**
	 * PHP has no object/array distinction — an empty array or a list with
	 * sequential integer keys from 0 is treated as a JSON array; any other
	 * key shape (string keys, or a non-sequential/non-zero-based int list)
	 * is a JSON object. Matches how the examples in the tool trait files
	 * are authored (an object is always `array('key' => ...)`, a list is
	 * always `array(1, 2, 3)`).
	 *
	 * @param array $value
	 * @return bool
	 */
	private static function is_assoc( array $value ): bool {
		if ( array() === $value ) {
			return false;
		}
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
