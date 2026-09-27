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
 * Validates JSON-decoded wire data against the JSON Schema subset tool
 * outputSchemas use: `type` (a name or a list of names), `properties`,
 * `required`, `items`, `additionalProperties` (bool or schema), `anyOf`,
 * `not`, `enum`.
 *
 * Why not rest_validate_value_from_schema(): WordPress core's validator is
 * built for coercing request input, so it is deliberately lenient where a
 * client's validator (Ajv in the reference SDK) is strict — it accepts the
 * string "7" as an integer and an empty or list array as an object, and it
 * has no `not`. A result it waved through could still make the client throw.
 *
 * Input is json_decode()d WITHOUT assoc, so a JSON object is a stdClass and
 * a JSON array a PHP list — the exact distinction the client sees. An
 * integral float (3.0) counts as an integer, as it does in JSON Schema.
 * Keywords outside the subset are ignored, never guessed at.
 */
class Mcpwp_MCP_Output_Schema {

	/**
	 * Validate a value.
	 *
	 * @param mixed        $value  JSON-decoded (non-assoc) value.
	 * @param array|object $schema Schema (PHP array or decoded object).
	 * @param string       $path   JSON path of $value, for messages.
	 * @return string[] Failures, each "path: reason". Empty when valid.
	 */
	public static function validate( $value, $schema, $path = '$' ) {
		if ( is_array( $schema ) ) {
			$schema = json_decode( wp_json_encode( $schema ) );
		}
		if ( ! is_object( $schema ) ) {
			return array();
		}

		if ( isset( $schema->type ) ) {
			$types = is_array( $schema->type ) ? $schema->type : array( $schema->type );
			$match = false;
			foreach ( $types as $type ) {
				if ( is_string( $type ) && self::is_type( $value, $type ) ) {
					$match = true;
					break;
				}
			}
			if ( ! $match ) {
				return array( sprintf( '%s: expected %s, got %s', $path, implode( '|', $types ), self::type_of( $value ) ) );
			}
		}

		$errors = array();

		if ( isset( $schema->enum ) && is_array( $schema->enum ) && ! in_array( $value, $schema->enum, false ) ) {
			$errors[] = $path . ': not one of the allowed values';
		}

		if ( $value instanceof stdClass ) {
			if ( isset( $schema->required ) && is_array( $schema->required ) ) {
				foreach ( $schema->required as $key ) {
					if ( is_string( $key ) && ! property_exists( $value, $key ) ) {
						$errors[] = sprintf( "%s: missing required '%s'", $path, $key );
					}
				}
			}
			$properties = isset( $schema->properties ) && is_object( $schema->properties ) ? get_object_vars( $schema->properties ) : array();
			$additional = isset( $schema->additionalProperties ) ? $schema->additionalProperties : true;
			foreach ( get_object_vars( $value ) as $key => $child ) {
				$child_path = $path . '.' . $key;
				if ( array_key_exists( $key, $properties ) ) {
					$errors = array_merge( $errors, self::validate( $child, $properties[ $key ], $child_path ) );
				} elseif ( false === $additional ) {
					$errors[] = $child_path . ': property not allowed';
				} elseif ( is_object( $additional ) ) {
					$errors = array_merge( $errors, self::validate( $child, $additional, $child_path ) );
				}
			}
		}

		if ( is_array( $value ) && isset( $schema->items ) && is_object( $schema->items ) ) {
			foreach ( $value as $index => $item ) {
				$errors = array_merge( $errors, self::validate( $item, $schema->items, $path . '[' . $index . ']' ) );
			}
		}

		if ( isset( $schema->anyOf ) && is_array( $schema->anyOf ) ) {
			$any = false;
			foreach ( $schema->anyOf as $branch ) {
				if ( array() === self::validate( $value, $branch, $path ) ) {
					$any = true;
					break;
				}
			}
			if ( ! $any ) {
				$errors[] = $path . ': matches no anyOf branch';
			}
		}

		if ( isset( $schema->not ) && is_object( $schema->not ) && array() === self::validate( $value, $schema->not, $path ) ) {
			$errors[] = $path . ': matches a "not" schema';
		}

		return $errors;
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
