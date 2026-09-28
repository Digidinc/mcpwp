<?php
/**
 * The one place that knows which Elementor widget settings embed a
 * Template Library (elementor_library) post id.
 *
 * #1032: an Elementor Pro Template widget, Global widget, Loop Grid/Loop
 * Carousel, or a handful of other widgets let a caller embed ANY
 * elementor_library id by number — regardless of whether the caller's API
 * key can read the 'elementor-templates'/'elementor-theme' tool category
 * that gates the Template Library everywhere else. A designer key with
 * `elementor-templates` disabled site-wide could still save a Template
 * widget pointing at a gated template, then read that template's content
 * back out through `/elementor/{id}/preview` — a write-time hole in the
 * #1024/#1030 category chokepoint.
 *
 * Enumerated against Elementor Pro 4.1.1 source (docker-copied read-only
 * from wp-rig-wp-dev, never committed):
 *
 *  - `modules/library/widgets/template.php` — the "Template" widget,
 *    `settings.template_id`.
 *  - `modules/global-widget/widgets/global-widget.php` (+ `module.php`'s
 *    `set_global_widget_included_posts_list()`/`get_document_data()`) — a
 *    Global widget stores the referenced id as a TOP-LEVEL element key,
 *    `templateID`, not inside `settings`.
 *  - `modules/loop-builder/widgets/base.php` + `loop-grid.php` +
 *    `loop-carousel.php` — `settings.template_id` (the loop's own display
 *    template) and, for Loop Grid's "alternate templates" repeater,
 *    `settings.alternate_templates[].template_id`.
 *  - `modules/search/widgets/search.php` — the Search widget's results
 *    template, `settings.template_id`.
 *  - `modules/woocommerce/widgets/cart.php` — `settings.additional_template_select`.
 *  - `modules/woocommerce/widgets/my-account.php` — `settings.customize_dashboard_select`.
 *
 * `template_id` is not reused for anything else in a stored element's
 * `settings` anywhere in Elementor core or Pro (confirmed by source grep —
 * every other `template_id` use in Elementor is an admin/ajax ARGUMENT to
 * the Template Library manager, never a value stored inside `_elementor_data`
 * itself), so a recursive scan for that one key name — including inside
 * repeater rows, which are themselves nested arrays under `settings` — is a
 * safe, unambiguous signal with no extra allowlisting needed.
 *
 * Not covered (documented gap, not silently dropped): Elementor Pro dynamic
 * tags that resolve a Popup by id (`modules/popup/tag.php`) store their
 * target inside an encoded `__dynamic__` hash, not a plain settings value —
 * a different, considerably larger attack surface (arbitrary dynamic tags
 * resolving arbitrary post ids) that deserves its own audit rather than a
 * bolt-on to this one.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mcpwp_Elementor_Template_Refs {

	/**
	 * Element-level keys (sit next to `elType`/`widgetType`, not inside
	 * `settings`) whose value is an elementor_library post id.
	 *
	 * @var string[]
	 */
	const ELEMENT_KEYS = array( 'templateID' );

	/**
	 * `settings` keys (searched recursively — a repeater row is itself a
	 * nested array under `settings`) whose value is an elementor_library
	 * post id.
	 *
	 * @var string[]
	 */
	const SETTINGS_KEYS = array( 'template_id', 'additional_template_select', 'customize_dashboard_select' );

	/**
	 * Find every Template Library reference in a submitted element tree.
	 *
	 * @param array $elements Element tree (list of elType/elements nodes).
	 * @return array<int,array{path:string,key:string,id:int}> One entry per
	 *         reference found. Not deduplicated — a caller that only needs
	 *         distinct ids should array_unique() the `id` column.
	 */
	public static function find( array $elements ) {
		$found = array();
		self::walk( $elements, '', $found );
		return $found;
	}

	/**
	 * Every distinct positive id find() turns up, as a plain int list.
	 *
	 * @param array $elements Element tree.
	 * @return int[]
	 */
	public static function find_ids( array $elements ) {
		$refs = self::find( $elements );
		return array_values( array_unique( array_map( static function ( $ref ) {
			return $ref['id'];
		}, $refs ) ) );
	}

	/**
	 * Recursively walk one level of the element tree.
	 *
	 * @param array  $elements Elements at this level.
	 * @param string $parent   Parent path, '' at the top (same format as
	 *                         Mcpwp_Elementor_Element_Ids::walk()).
	 * @param array  &$found   Accumulator.
	 */
	private static function walk( array $elements, $parent, array &$found ) {
		foreach ( $elements as $idx => $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}

			$type = isset( $el['elType'] ) && is_string( $el['elType'] ) && '' !== $el['elType'] ? $el['elType'] : 'element';
			$path = ( '' === $parent ? '' : $parent . '.' ) . "{$type}[{$idx}]";

			foreach ( self::ELEMENT_KEYS as $key ) {
				if ( isset( $el[ $key ] ) && is_numeric( $el[ $key ] ) && (int) $el[ $key ] > 0 ) {
					$found[] = array(
						'path' => $path,
						'key'  => $key,
						'id'   => (int) $el[ $key ],
					);
				}
			}

			if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
				self::scan_settings( $el['settings'], $path, $found );
			}

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				self::walk( $el['elements'], $path, $found );
			}
		}
	}

	/**
	 * Recursively scan a `settings` array (and any nested arrays inside it —
	 * repeater rows, groups) for the known reference keys.
	 *
	 * @param array  $settings Settings (or a nested settings sub-array).
	 * @param string $path     Owning element's path, for reporting.
	 * @param array  &$found   Accumulator.
	 */
	private static function scan_settings( array $settings, $path, array &$found ) {
		foreach ( $settings as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, self::SETTINGS_KEYS, true )
				&& is_numeric( $value ) && (int) $value > 0
			) {
				$found[] = array(
					'path' => $path,
					'key'  => $key,
					'id'   => (int) $value,
				);
				continue;
			}

			if ( is_array( $value ) ) {
				self::scan_settings( $value, $path, $found );
			}
		}
	}
}
