<?php
/**
 * Server-side check of a tool result against its declared outputSchema (#906).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates JSON-decoded wire data against a JSON Schema, FAILING CLOSED.
 *
 * Supported: `type` (a name or a list of names), `properties`, `required`,
 * `items` (a single schema), `additionalProperties` (bool or schema),
 * `anyOf`, `oneOf`, `allOf`, `not`, `enum`, `const`, `minimum`, `maximum`,
 * `minLength`, `maxLength`, `pattern`. Annotation keywords (`description`,
 * `title`, `default`, `examples`, `$schema`, `$comment`) are ignored. ANY
 * other keyword — `$ref`, tuple `items`, `format`, `exclusiveMinimum`, … —
 * is a validation failure, never silently skipped: the client's validator
 * (Ajv in the reference SDK) does enforce them, so a result this class
 * waved through could still make that client throw. Failing closed means
 * the caller sends the error envelope instead, which every schema admits.
 *
 * Why not rest_validate_value_from_schema(): WordPress core's validator is
 * built for coercing request input — it accepts the string "7" as an
 * integer and an empty or list array as an object, and has no `not`.
 *
 * `pattern` must be ECMAScript-compatible: clients validate with Ajv in
 * unicode mode (JavaScript regex semantics), while this class runs the
 * pattern as PCRE with the `u` and `D` flags. Keep to the common subset —
 * no lookbehind-heavy or PCRE-only syntax — or the two may disagree.
 *
 * Input is json_decode()d WITHOUT assoc, so a JSON object is a stdClass and
 * a JSON array a PHP list — the exact distinction the client sees. An
 * integral float (3.0) is an integer, and equal to 3, as in JSON Schema.
 */
class Mcpwp_MCP_Output_Schema {

	/**
	 * Keywords that carry no assertion.
	 */
	const ANNOTATION_KEYWORDS = array( 'description', 'title', 'default', 'examples', '$schema', '$comment' );

	/**
	 * Keywords this class asserts.
	 */
	const ASSERTION_KEYWORDS = array(
		'type',
		'properties',
		'required',
		'items',
		'additionalProperties',
		'anyOf',
		'oneOf',
		'allOf',
		'not',
		'enum',
		'const',
		'minimum',
		'maximum',
		'minLength',
		'maxLength',
		'pattern',
	);

	/**
	 * Validate a value.
	 *
	 * @param mixed        $value  JSON-decoded (non-assoc) value.
	 * @param array|object $schema Schema (PHP array or decoded object).
	 * @param string       $path   JSON path of $value, for messages.
	 * @return string[] Failures, each "path: reason". Empty when valid.
	 */
	public static function validate( $value, $schema, $path = '$' ) {
		// An unsupported or malformed keyword ANYWHERE is fatal to the whole
		// validation — never a mere branch result. Inside `not` it would
		// otherwise make the `not` pass, and inside anyOf/oneOf it would
		// count as one failing branch among others.
		try {
			// Scan the whole schema first, so a keyword in a branch the data
			// never reaches (an absent property, an empty array's items) is
			// just as fatal as one it does.
			self::scan( is_array( $schema ) ? json_decode( (string) wp_json_encode( $schema ) ) : $schema, $path );
			return self::check( $value, $schema, $path );
		} catch ( Mcpwp_MCP_Output_Schema_Unsupported $e ) {
			return array( $e->getMessage() );
		}
	}

	/**
	 * Walk every subschema and throw on any keyword outside the supported
	 * and annotation sets.
	 *
	 * @param mixed  $schema Decoded schema.
	 * @param string $path   Schema path, for messages.
	 * @throws Mcpwp_MCP_Output_Schema_Unsupported On an unsupported keyword.
	 */
	private static function scan( $schema, $path ) {
		if ( is_bool( $schema ) ) {
			return;
		}
		if ( ! $schema instanceof stdClass ) {
			throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': schema is not an object' );
		}
		foreach ( get_object_vars( $schema ) as $keyword => $sub ) {
			if ( ! in_array( $keyword, self::ASSERTION_KEYWORDS, true ) && ! in_array( $keyword, self::ANNOTATION_KEYWORDS, true ) ) {
				throw new Mcpwp_MCP_Output_Schema_Unsupported( sprintf( "%s: unsupported schema keyword '%s'", $path, $keyword ) );
			}
			if ( 'properties' === $keyword && $sub instanceof stdClass ) {
				foreach ( get_object_vars( $sub ) as $name => $child ) {
					self::scan( $child, $path . '.' . $name );
				}
			} elseif ( 'items' === $keyword && is_array( $sub ) ) {
				throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': unsupported items form' );
			} elseif ( in_array( $keyword, array( 'items', 'additionalProperties', 'not' ), true ) ) {
				self::scan( $sub, $path );
			} elseif ( in_array( $keyword, array( 'anyOf', 'oneOf', 'allOf' ), true ) && is_array( $sub ) ) {
				foreach ( $sub as $branch ) {
					self::scan( $branch, $path );
				}
			}
		}
	}

	/**
	 * Recursive validation. Throws on a schema it cannot fully evaluate.
	 *
	 * @param mixed        $value  JSON-decoded (non-assoc) value.
	 * @param array|object $schema Schema.
	 * @param string       $path   JSON path.
	 * @return string[]
	 * @throws Mcpwp_MCP_Output_Schema_Unsupported On an unsupported or malformed keyword.
	 */
	private static function check( $value, $schema, $path ) {
		if ( is_array( $schema ) ) {
			$schema = json_decode( (string) wp_json_encode( $schema ) );
		}
		if ( true === $schema || ( $schema instanceof stdClass && array() === get_object_vars( $schema ) ) ) {
			return array();
		}
		if ( ! $schema instanceof stdClass ) {
			throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': schema is not an object' );
		}

		$keywords = get_object_vars( $schema );
		foreach ( array_keys( $keywords ) as $keyword ) {
			if ( ! in_array( $keyword, self::ASSERTION_KEYWORDS, true ) && ! in_array( $keyword, self::ANNOTATION_KEYWORDS, true ) ) {
				throw new Mcpwp_MCP_Output_Schema_Unsupported( sprintf( "%s: unsupported schema keyword '%s'", $path, $keyword ) );
			}
		}

		if ( isset( $keywords['type'] ) ) {
			$types = is_array( $schema->type ) ? $schema->type : array( $schema->type );
			$match = false;
			foreach ( $types as $type ) {
				if ( ! is_string( $type ) ) {
					throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': malformed type' );
				}
				if ( self::is_type( $value, $type ) ) {
					$match = true;
					break;
				}
			}
			if ( ! $match ) {
				return array( sprintf( '%s: expected %s, got %s', $path, implode( '|', $types ), self::type_of( $value ) ) );
			}
		}

		$errors = array();

		if ( array_key_exists( 'enum', $keywords ) ) {
			if ( ! is_array( $schema->enum ) ) {
				throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': malformed enum' );
			}
			$found = false;
			foreach ( $schema->enum as $allowed ) {
				if ( self::json_equal( $value, $allowed ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$errors[] = $path . ': not one of the allowed values';
			}
		}

		if ( array_key_exists( 'const', $keywords ) && ! self::json_equal( $value, $schema->const ) ) {
			$errors[] = $path . ': not the constant value';
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			foreach ( array( 'minimum', 'maximum' ) as $bound ) {
				if ( ! array_key_exists( $bound, $keywords ) ) {
					continue;
				}
				if ( ! is_int( $schema->$bound ) && ! is_float( $schema->$bound ) ) {
					throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': malformed ' . $bound );
				}
				if ( 'minimum' === $bound ? $value < $schema->$bound : $value > $schema->$bound ) {
					$errors[] = sprintf( '%s: %s %s', $path, 'minimum' === $bound ? 'below' : 'above', $bound );
				}
			}
		}

		if ( is_string( $value ) ) {
			$length = self::length( $value );
			foreach ( array( 'minLength', 'maxLength' ) as $bound ) {
				if ( ! array_key_exists( $bound, $keywords ) ) {
					continue;
				}
				if ( ! is_int( $schema->$bound ) ) {
					throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': malformed ' . $bound );
				}
				if ( 'minLength' === $bound ? $length < $schema->$bound : $length > $schema->$bound ) {
					$errors[] = sprintf( '%s: string length outside %s', $path, $bound );
				}
			}
			if ( array_key_exists( 'pattern', $keywords ) ) {
				if ( ! is_string( $schema->pattern ) ) {
					throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': malformed pattern' );
				}
				// ECMA-262 regex run as PCRE, unanchored like JSON Schema's,
				// with a delimiter that cannot collide. `D` so `$` matches only
				// at the very end, as in JavaScript (without it PCRE lets `$`
				// match before a trailing newline). A pattern PCRE cannot
				// compile is fatal, never a pass.
				$matched = @preg_match( "\x01" . $schema->pattern . "\x01uD", $value ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( false === $matched ) {
					throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': pattern could not be evaluated' );
				}
				if ( 0 === $matched ) {
					$errors[] = $path . ': does not match pattern';
				}
			}
		}

		if ( $value instanceof stdClass ) {
			if ( array_key_exists( 'required', $keywords ) ) {
				if ( ! is_array( $schema->required ) ) {
					throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': malformed required' );
				}
				foreach ( $schema->required as $key ) {
					if ( ! is_string( $key ) ) {
						throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': malformed required' );
					}
					if ( ! property_exists( $value, $key ) ) {
						$errors[] = sprintf( "%s: missing required '%s'", $path, $key );
					}
				}
			}
			$properties = array();
			if ( array_key_exists( 'properties', $keywords ) ) {
				if ( ! $schema->properties instanceof stdClass ) {
					throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': malformed properties' );
				}
				$properties = get_object_vars( $schema->properties );
			}
			$additional = array_key_exists( 'additionalProperties', $keywords ) ? $schema->additionalProperties : true;
			foreach ( get_object_vars( $value ) as $key => $child ) {
				$child_path = $path . '.' . $key;
				if ( array_key_exists( $key, $properties ) ) {
					$errors = array_merge( $errors, self::check( $child, $properties[ $key ], $child_path ) );
				} elseif ( false === $additional ) {
					$errors[] = $child_path . ': property not allowed';
				} elseif ( true !== $additional ) {
					$errors = array_merge( $errors, self::check( $child, $additional, $child_path ) );
				}
			}
		}

		if ( is_array( $value ) && array_key_exists( 'items', $keywords ) ) {
			if ( ! $schema->items instanceof stdClass && true !== $schema->items ) {
				// Tuple form (an array of schemas) is unsupported: fail closed.
				throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': unsupported items form' );
			}
			foreach ( $value as $index => $item ) {
				$errors = array_merge( $errors, self::check( $item, $schema->items, $path . '[' . $index . ']' ) );
			}
		}

		foreach ( array( 'anyOf', 'oneOf', 'allOf' ) as $combinator ) {
			if ( ! array_key_exists( $combinator, $keywords ) ) {
				continue;
			}
			if ( ! is_array( $schema->$combinator ) || array() === $schema->$combinator ) {
				throw new Mcpwp_MCP_Output_Schema_Unsupported( $path . ': malformed ' . $combinator );
			}
			$passing = 0;
			$failure = array();
			foreach ( $schema->$combinator as $branch ) {
				$branch_errors = self::check( $value, $branch, $path );
				if ( array() === $branch_errors ) {
					++$passing;
				} elseif ( array() === $failure ) {
					$failure = $branch_errors;
				}
			}
			if ( 'anyOf' === $combinator && 0 === $passing ) {
				$errors[] = $path . ': matches no anyOf branch';
			} elseif ( 'oneOf' === $combinator && 1 !== $passing ) {
				$errors[] = sprintf( '%s: matches %d oneOf branches, not exactly one', $path, $passing );
			} elseif ( 'allOf' === $combinator && array() !== $failure ) {
				$errors = array_merge( $errors, $failure );
			}
		}

		if ( array_key_exists( 'not', $keywords ) && array() === self::check( $value, $schema->not, $path ) ) {
			$errors[] = $path . ': matches a "not" schema';
		}

		return $errors;
	}

	/**
	 * JSON equality: same JSON type and value; numbers compare by value
	 * (1 equals 1.0), objects by keys regardless of order, arrays in order.
	 * Never PHP's loose `==` ("1" is not 1, true is not "x").
	 *
	 * @param mixed $a Decoded value.
	 * @param mixed $b Decoded value.
	 * @return bool
	 */
	public static function json_equal( $a, $b ) {
		$a_number = is_int( $a ) || is_float( $a );
		$b_number = is_int( $b ) || is_float( $b );
		if ( $a_number || $b_number ) {
			return $a_number && $b_number && (float) $a === (float) $b;
		}
		if ( $a instanceof stdClass || $b instanceof stdClass ) {
			if ( ! $a instanceof stdClass || ! $b instanceof stdClass ) {
				return false;
			}
			$a_vars = get_object_vars( $a );
			$b_vars = get_object_vars( $b );
			if ( count( $a_vars ) !== count( $b_vars ) ) {
				return false;
			}
			foreach ( $a_vars as $key => $child ) {
				if ( ! array_key_exists( $key, $b_vars ) || ! self::json_equal( $child, $b_vars[ $key ] ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_array( $a ) || is_array( $b ) ) {
			if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) !== count( $b ) ) {
				return false;
			}
			foreach ( array_values( $a ) as $index => $child ) {
				if ( ! self::json_equal( $child, array_values( $b )[ $index ] ) ) {
					return false;
				}
			}
			return true;
		}
		return $a === $b;
	}

	/**
	 * String length in code points, as JSON Schema counts it.
	 *
	 * @param string $value String.
	 * @return int
	 */
	private static function length( $value ) {
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $value, 'UTF-8' );
		}
		return (int) preg_match_all( '/./us', $value );
	}

	/**
	 * @param mixed  $value Decoded value.
	 * @param string $type  JSON Schema type name.
	 * @return bool
	 */
	private static function is_type( $value, $type ) {
		switch ( $type ) {
			case 'object':
				return $value instanceof stdClass;
			case 'array':
				return is_array( $value );
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value ) || ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value );
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
	 * @param mixed $value Decoded value.
	 * @return string JSON type name.
	 */
	private static function type_of( $value ) {
		if ( $value instanceof stdClass ) {
			return 'object';
		}
		if ( is_array( $value ) ) {
			return 'array';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( null === $value ) {
			return 'null';
		}
		return 'string';
	}
}

/**
 * Thrown inside Mcpwp_MCP_Output_Schema when a schema uses a keyword it
 * cannot evaluate; caught once at the top of validate() so the whole
 * validation fails closed.
 */
class Mcpwp_MCP_Output_Schema_Unsupported extends RuntimeException {
}
