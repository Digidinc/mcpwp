<?php
/**
 * The one reader of a stored Elementor tree (#845).
 *
 * Elementor renders `_elementor_data` stored as JSON text or as a PHP array
 * (imports and some plugins store the array), and a tree whose integer keys
 * are not 0..n. 3.11.1 taught wp_patch_elementor and wp_set_elementor to read
 * every one of those forms; every other reader still called json_decode() on
 * the meta — a TypeError on PHP 8 for the array form — or skipped anything
 * that was not a string, which turned a working page into "no Elementor data".
 *
 * Every path that decodes a stored tree reads it here, so the forms that are a
 * tree, the forms that are an empty page, and the forms that cannot be a tree
 * have one answer each. Writers store the list back as JSON text.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mcpwp_Elementor_Stored_Tree {

	/**
	 * The meta key the tree is stored under.
	 */
	const META_KEY = '_elementor_data';

	/**
	 * Read a stored Elementor tree, whatever form it was stored in.
	 *
	 * JSON text and arrays are the tree; a tree with numeric keys that are not
	 * 0..n (what a PHP tool writes after unset() and re-encode) is normalised
	 * to a list, which is what every write stores. Missing, empty and JSON
	 * `null` are an empty page. Only values that cannot be a tree are
	 * unusable, and the reason says which form they are in.
	 *
	 * @param mixed $raw Value of get_post_meta( $id, '_elementor_data', true ).
	 * @return array{elements:array|null,unusable:string,stored_as:string}
	 *         `elements` is a list ([] for no data), or null when unusable.
	 */
	public static function read( $raw ) {
		$stored_as = gettype( $raw );

		if ( '' === $raw || null === $raw || false === $raw ) {
			return array( 'elements' => array(), 'unusable' => '', 'stored_as' => $stored_as );
		}

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );

			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				return array( 'elements' => null, 'unusable' => 'is stored as text that does not decode to an element list (' . json_last_error_msg() . ')', 'stored_as' => $stored_as );
			}
			if ( null === $decoded ) {
				return array( 'elements' => array(), 'unusable' => '', 'stored_as' => $stored_as );
			}
			if ( ! is_array( $decoded ) ) {
				return array( 'elements' => null, 'unusable' => 'is stored as JSON text that decodes to ' . gettype( $decoded ) . ', not an element list', 'stored_as' => $stored_as );
			}

			$list = self::element_list( $decoded );

			return null === $list
				? array( 'elements' => null, 'unusable' => 'is stored as JSON text that is not a list of elements', 'stored_as' => $stored_as )
				: array( 'elements' => $list, 'unusable' => '', 'stored_as' => $stored_as );
		}

		if ( is_array( $raw ) ) {
			$list = self::element_list( $raw );

			return null === $list
				? array( 'elements' => null, 'unusable' => 'is stored as an array that is not a list of elements', 'stored_as' => $stored_as )
				: array( 'elements' => $list, 'unusable' => '', 'stored_as' => $stored_as );
		}

		return array( 'elements' => null, 'unusable' => 'is stored as ' . $stored_as . ', which is neither JSON text nor an element array', 'stored_as' => $stored_as );
	}

	/**
	 * Read the stored tree of a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{elements:array|null,unusable:string,stored_as:string}
	 */
	public static function of_post( $post_id ) {
		return self::read( get_post_meta( (int) $post_id, self::META_KEY, true ) );
	}

	/**
	 * Whether a read found data that cannot be a tree.
	 *
	 * @param array $stored Result of read().
	 * @return bool
	 */
	public static function is_unusable( array $stored ) {
		return null === $stored['elements'];
	}

	/**
	 * Normalise a stored tree to a list: integer keys in any order or with
	 * gaps become 0..n, keeping their order. Null when a key is a string or an
	 * item is not an array — written back, that would be a JSON object
	 * Elementor cannot read.
	 *
	 * @param array $elements Candidate tree.
	 * @return array|null
	 */
	public static function element_list( array $elements ) {
		foreach ( $elements as $key => $element ) {
			if ( ! is_int( $key ) || ! is_array( $element ) ) {
				return null;
			}
		}

		return array_values( $elements );
	}

	/**
	 * The refusal every write returns when the stored data cannot be a tree.
	 *
	 * Treating it as an empty page ran the change against nothing and
	 * overwrote the whole page with the result, so nothing is written.
	 *
	 * @param array  $stored  Result of read(); must be unusable.
	 * @param string $subject What the data belongs to, for the message.
	 * @return WP_Error 422 invalid_elementor_data, with `stored_as`.
	 */
	public static function refusal( array $stored, $subject = "This page's Elementor data" ) {
		return new WP_Error(
			'invalid_elementor_data',
			"{$subject} {$stored['unusable']}, so nothing was changed. Replace it with a clean tree through wp_set_elementor.",
			array(
				'status'    => 422,
				'stored_as' => $stored['stored_as'],
			)
		);
	}

	/**
	 * The refusal a read returns when it cannot answer without the tree
	 * (a summary, a section to cut): unreadable, not "no Elementor data".
	 *
	 * @param array $stored Result of read(); must be unusable.
	 * @return WP_Error 422 invalid_elementor_data, with `stored_as`.
	 */
	public static function unreadable( array $stored ) {
		$error = self::read_error( $stored );

		return new WP_Error(
			$error['code'],
			$error['message'],
			array(
				'status'    => 422,
				'stored_as' => $error['stored_as'],
			)
		);
	}

	/**
	 * How a read reports data it could not read, beside a null tree.
	 *
	 * An unreadable page and an empty one must never produce the same output.
	 *
	 * @param array $stored Result of read(); must be unusable.
	 * @return array{code:string,message:string,stored_as:string}
	 */
	public static function read_error( array $stored ) {
		return array(
			'code'      => 'invalid_elementor_data',
			'message'   => "The stored Elementor data {$stored['unusable']}. Replace it with a clean tree through wp_set_elementor.",
			'stored_as' => $stored['stored_as'],
		);
	}
}
