<?php
/**
 * The one place that knows which Elementor settings keys are wrong.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalises Elementor settings keys that persist and round-trip perfectly
 * while rendering nothing.
 *
 * This exists because "did this data get normalised?" used to depend on which
 * endpoint happened to write it. The #678 rename lived inside
 * validate_and_fix_elements(), reached only from set_elementor_data() and
 * save_elements_to_page(). Four other paths write _elementor_data directly and
 * skipped it entirely — approval rollback, find/replace, page clone and the
 * theme builder (#737).
 *
 * Rollback was the one that mattered: a snapshot taken before the fix holds
 * `css_id`, so restoring it put the broken key back and dropped `_element_id`.
 * The fix silently un-applied itself, the section's anchor stopped working
 * again, and the user saw a successful rollback. Clone is the same shape one
 * step removed — a pre-fix page copied into a new page that never passes
 * through the validator.
 *
 * Every _elementor_data write should go through here, so a new write path
 * inherits the rule instead of having to remember it.
 */
class Mcpwp_Elementor_Element_Keys {

	/**
	 * Settings keys Elementor stores but never reads, and what it reads instead.
	 *
	 * `_element_id` is the ID control on Section, Column, Container AND Widget:
	 * Element_Base's shared render path is the only thing that writes the
	 * wrapper id, and it reads `_element_id`. `css_id` is not a control on any
	 * of them.
	 *
	 * Note the asymmetry that makes `css_id` a natural mistake: the CLASSES
	 * control really is `css_classes` on section/column/container (and
	 * `_css_classes` on widgets), so `css_classes` works. Renaming that too
	 * would break every element currently styled correctly — it is deliberately
	 * not in this map.
	 *
	 * @var array<string,string>
	 */
	const SETTINGS_RENAMES = array(
		'css_id' => '_element_id',
	);

	/**
	 * Rename the known-wrong keys in one element's settings.
	 *
	 * An existing correct key always wins: a caller that already set
	 * `_element_id` keeps it, and the stray `css_id` is dropped rather than
	 * overwriting a working id.
	 *
	 * @param array $settings One element's settings.
	 * @return array{settings:array,renamed:string[]} Settings, and the keys renamed.
	 */
	public static function rename_settings( array $settings ) {
		$renamed = array();

		foreach ( self::SETTINGS_RENAMES as $wrong => $right ) {
			if ( ! array_key_exists( $wrong, $settings ) ) {
				continue;
			}

			if ( ! array_key_exists( $right, $settings ) ) {
				$settings[ $right ] = $settings[ $wrong ];
				$renamed[]          = $wrong;
			}

			unset( $settings[ $wrong ] );
		}

		return array(
			'settings' => $settings,
			'renamed'  => $renamed,
		);
	}

	/**
	 * Rename the known-wrong keys throughout a decoded element tree.
	 *
	 * @param array $elements Decoded _elementor_data.
	 * @param int   $renamed  Out: how many keys were renamed, by reference.
	 * @return array The tree, normalised.
	 */
	public static function normalize_tree( array $elements, &$renamed = 0 ) {
		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$result                          = self::rename_settings( $element['settings'] );
				$element['settings']             = $result['settings'];
				$renamed                        += count( $result['renamed'] );
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$element['elements'] = self::normalize_tree( $element['elements'], $renamed );
			}

			$elements[ $index ] = $element;
		}

		return $elements;
	}

	/**
	 * Rename the known-wrong keys in an unslashed _elementor_data JSON string.
	 *
	 * Returns the input **byte for byte** when there is nothing to change, when
	 * it does not decode, or when re-encoding fails. A normaliser must never be
	 * the reason a write loses data: an input this cannot parse is passed
	 * through exactly as it arrived, and the caller stores what it meant to
	 * store. $renamed is how a caller tells "nothing needed fixing" apart from
	 * "could not look" — they must not read the same.
	 *
	 * @param string $json    Unslashed _elementor_data JSON.
	 * @param int    $renamed Out: how many keys were renamed, by reference.
	 * @return string Normalised JSON, or the original string unchanged.
	 */
	public static function normalize_json( $json, &$renamed = 0 ) {
		$renamed = 0;

		if ( ! is_string( $json ) || '' === $json ) {
			return $json;
		}

		$decoded = json_decode( $json, true );

		if ( ! is_array( $decoded ) ) {
			return $json;
		}

		$normalized = self::normalize_tree( $decoded, $renamed );

		if ( 0 === $renamed ) {
			return $json;
		}

		$encoded = wp_json_encode( $normalized );

		if ( false === $encoded ) {
			// Re-encoding failed on data that decoded a moment ago. Keep the
			// caller's bytes; a broken id is survivable, a lost page is not.
			$renamed = 0;
			return $json;
		}

		return $encoded;
	}
}
