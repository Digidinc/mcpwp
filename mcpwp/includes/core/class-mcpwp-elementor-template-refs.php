<?php
/**
 * The one place that knows which Elementor widget settings embed a
 * Template Library (elementor_library) post id.
 *
 * #1032: an Elementor Pro Template widget, Global widget, Loop Grid/Loop
 * Carousel, the `[elementor-template id="N"]` shortcode, or a handful of
 * other widgets let a caller embed ANY elementor_library id by number —
 * regardless of whether the caller's API key can read the
 * 'elementor-templates'/'elementor-theme' tool category that gates the
 * Template Library everywhere else. A designer key with
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
 *  - `modules/library/classes/shortcode.php` — the `[elementor-template
 *    id="N"]` shortcode, registered site-wide and reachable from ANY text
 *    that runs through `do_shortcode()`/`the_content` — a Text Editor/HTML
 *    widget's own content, or a plain post's `post_content` with no
 *    Elementor involvement at all.
 *  - `modules/dynamic-tags/tags/shortcode.php` — Elementor Pro's "Shortcode"
 *    dynamic tag calls `do_shortcode()` on an arbitrary, caller-controlled
 *    `settings.shortcode` string, which can itself BE an
 *    `[elementor-template id="N"]` shortcode. Its `__dynamic__`-encoded
 *    settings are decoded and scanned the same way core's own tag/action
 *    encodings already are for id-repair (Mcpwp_Elementor_Element_Ids).
 *
 * Adversarial review round 1 findings folded in here (2026-09-28):
 *
 *  - B1: a naive `is_numeric()` check missed "5abc"/"6x" — Elementor itself
 *    resolves a template/global-widget id with a plain `(int)` cast (see
 *    `get_post()`/`absint()` throughout `Plugin::elementor()->documents`),
 *    so "5abc" renders template 5 exactly as "5" would. find()/scan_settings()
 *    now mirror that coercion for every candidate, not just cleanly numeric
 *    ones.
 *  - W3: the settings-key list is filterable (`mcpwp_elementor_template_ref_keys`)
 *    for a third-party/addon widget this enumeration cannot see, and any
 *    settings key ending in `template_id`/`templateID`/`_template`
 *    (case-insensitive) is treated as a reference on the same suspicion —
 *    cheap insurance against the next Pro module this list has not been
 *    updated for yet.
 *
 * Not covered (documented gap, not silently dropped): Elementor Pro's Popup
 * dynamic tag (`modules/popup/tag.php`) also resolves a library template id,
 * but the READ side is already covered generically — see
 * Mcpwp_REST_API::guard_elementor_template_shortcode()'s docblock — while
 * catching every dynamic tag that can name an arbitrary post id as a WRITE
 * refusal is a materially larger, open-ended surface (arbitrary dynamic tags
 * resolving arbitrary post ids of any type) that deserves its own audit
 * rather than a bolt-on here.
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
	 * post id. Filterable (W3) via `mcpwp_elementor_template_ref_keys` for a
	 * third-party widget this enumeration does not know about; see
	 * reference_keys().
	 *
	 * @var string[]
	 */
	const SETTINGS_KEYS = array( 'template_id', 'additional_template_select', 'customize_dashboard_select' );

	/**
	 * Real Elementor/Elementor Pro controls that end in `_template` but are
	 * NOT a post-id reference (source-grepped alongside SETTINGS_KEYS above,
	 * every `add_control()` name matching the suffix rule below) — excluded
	 * from is_reference_key()'s suffix match so W3's generic net does not
	 * misfire on them:
	 *
	 *  - `page_template` — a WP page-template SLUG (string, e.g.
	 *    "elementor_canvas"), never a post id. Already harmless in practice
	 *    (coerce_id() of a non-numeric slug is 0), listed for clarity.
	 *  - `alternate_template` — Loop Grid's "Apply Once" SWITCHER control
	 *    ('yes'/''), a sibling of the real reference `alternate_templates`
	 *    (plural) repeater. Same reasoning: harmless in practice, listed for
	 *    clarity.
	 *  - `repeat_template` — Loop Grid alternate-templates repeater's
	 *    "Position in grid" NUMBER control (e.g. `3`) — a small positive
	 *    integer that WOULD false-positive as a post id without this
	 *    exclusion, refusing a write over a setting that names no template at
	 *    all.
	 *
	 * @var string[]
	 */
	const SUFFIX_EXCLUSIONS = array( 'page_template', 'alternate_template', 'repeat_template' );

	/**
	 * Elementor Pro's Template Library shortcode tag name
	 * (`modules/library/classes/shortcode.php::SHORTCODE`).
	 */
	const SHORTCODE_TAG = 'elementor-template';

	/**
	 * The filtered, final settings-key list (W3). Public so a caller that
	 * needs to know "is this literally one of the named keys" (as opposed to
	 * a suffix match or a shortcode found in free text) can ask the same
	 * question this class answers internally.
	 *
	 * @return string[]
	 */
	public static function reference_keys() {
		/**
		 * Filters the settings keys treated as a Template Library reference.
		 *
		 * @param string[] $keys Default: Mcpwp_Elementor_Template_Refs::SETTINGS_KEYS.
		 */
		$keys = apply_filters( 'mcpwp_elementor_template_ref_keys', self::SETTINGS_KEYS );

		return is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : self::SETTINGS_KEYS;
	}

	/**
	 * Whether a settings key names a Template Library reference by
	 * convention — the exact keys reference_keys() lists, OR any key ending
	 * in `template_id`, `templateID`, or `_template` (case-insensitive; W3).
	 * The suffix rule is deliberately generic: a Pro module this file has
	 * not been updated for is far more likely to follow Elementor's own
	 * `..._template_id`/`..._template` naming convention than to invent a
	 * new one, so this is cheap insurance rather than a real enumeration.
	 *
	 * @param string $key Settings key.
	 * @return bool
	 */
	public static function is_reference_key( $key ) {
		if ( ! is_string( $key ) || '' === $key ) {
			return false;
		}

		if ( in_array( $key, self::reference_keys(), true ) ) {
			return true;
		}

		if ( in_array( $key, self::SUFFIX_EXCLUSIONS, true ) ) {
			return false;
		}

		$lower = strtolower( $key );

		return self::ends_with( $lower, 'template_id' )
			|| self::ends_with( $lower, 'templateid' )
			|| self::ends_with( $lower, '_template' );
	}

	/**
	 * @param string $haystack Already-lowercased subject.
	 * @param string $needle   Already-lowercased suffix.
	 * @return bool
	 */
	private static function ends_with( $haystack, $needle ) {
		$len = strlen( $needle );
		return 0 === $len || ( strlen( $haystack ) >= $len && substr( $haystack, -$len ) === $needle );
	}

	/**
	 * Elementor's own coercion of a settings value into a post id: a plain
	 * `(int)` cast (mirrors `absint()`/`get_post()` throughout
	 * `Plugin::elementor()->documents` and `templates_manager`) — takes the
	 * leading numeric run of a string and ignores everything after it, so
	 * "5abc" and "5#" both resolve exactly as "5" does (#1032 B1). Only
	 * scalar, non-boolean values are eligible: a boolean's `(int)` cast (0/1)
	 * is never a real post id, and would either silently do nothing (0) or
	 * false-positive on id 1 for every unrelated boolean setting that
	 * happens to sit under a suffix-matched key (W3's generic suffix rule
	 * makes that collision far more likely than it would be for the small,
	 * exact SETTINGS_KEYS list alone).
	 *
	 * @param mixed $value Candidate settings value.
	 * @return int Positive id, or 0 when $value is not a usable reference.
	 */
	public static function coerce_id( $value ) {
		if ( is_bool( $value ) || is_array( $value ) || null === $value ) {
			return 0;
		}
		if ( ! is_string( $value ) && ! is_int( $value ) && ! is_float( $value ) ) {
			return 0;
		}

		$id = (int) $value;

		return $id > 0 ? $id : 0;
	}

	/**
	 * Find every Template Library reference in a submitted element tree —
	 * named-key settings values (coerced the way Elementor itself would
	 * resolve them) AND any `[elementor-template id="N"]` shortcode found in
	 * free text anywhere in the tree (a Text Editor/HTML widget's content,
	 * or a raw string setting carrying it another way).
	 *
	 * @param array $elements Element tree (list of elType/elements nodes).
	 * @return array<int,array{path:string,key:string,id:int}> One entry per
	 *         reference found. Not deduplicated — a caller that only needs
	 *         distinct ids should use find_ids() instead.
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
				if ( ! array_key_exists( $key, $el ) ) {
					continue;
				}
				$id = self::coerce_id( $el[ $key ] );
				if ( $id > 0 ) {
					$found[] = array(
						'path' => $path,
						'key'  => $key,
						'id'   => $id,
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
	 * repeater rows, groups) for the known reference keys AND for a
	 * `[elementor-template]` shortcode embedded in any string value.
	 *
	 * @param array  $settings Settings (or a nested settings sub-array).
	 * @param string $path     Owning element's path, for reporting.
	 * @param array  &$found   Accumulator.
	 */
	private static function scan_settings( array $settings, $path, array &$found ) {
		foreach ( $settings as $key => $value ) {
			if ( is_array( $value ) ) {
				self::scan_settings( $value, $path, $found );
				continue;
			}

			if ( is_string( $key ) && self::is_reference_key( $key ) ) {
				$id = self::coerce_id( $value );
				if ( $id > 0 ) {
					$found[] = array(
						'path' => $path,
						'key'  => $key,
						'id'   => $id,
					);
					continue;
				}
			}

			// Any string setting can carry the shortcode as free text
			// (a Text Editor/HTML widget's own content, a link's __dynamic__
			// hash, ...) whether or not its key is a known reference key.
			if ( is_string( $value ) && '' !== $value ) {
				foreach ( self::ids_in_text( $value ) as $id ) {
					$found[] = array(
						'path' => $path,
						'key'  => (string) $key,
						'id'   => $id,
					);
				}
			}
		}
	}

	/**
	 * The shared string scanner (#1032 B3): every distinct id an
	 * `[elementor-template id="N"]` shortcode names in $text, however it got
	 * there — typed directly, URL-encoded (Elementor's dynamic-tag encoding),
	 * or nested inside a "Shortcode" dynamic tag's own `__dynamic__`-encoded
	 * `settings.shortcode` string (urlencoded-JSON or base64-JSON form,
	 * mirroring Mcpwp_Elementor_Element_Ids::decoded_forms()'s own decoding
	 * of the same Elementor encodings).
	 *
	 * Used by scan_settings() above for a widget setting, and directly by
	 * every write path that saves a post's plain post_content (posts, pages,
	 * generic content, bulk updates) — the ONE scanner both the Elementor
	 * tree walker and the post-content write guard call, so the two can never
	 * disagree about what counts as a reference.
	 *
	 * @param string $text Arbitrary text.
	 * @return int[] Distinct positive ids named by a shortcode occurrence.
	 */
	public static function ids_in_text( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return array();
		}

		// Cheap bail-out: nothing to find unless the shortcode tag appears
		// literally, URL-encoded (both %-encoded forms decoded_text_forms()
		// checks), OR the text carries a `settings=` payload that might
		// decode to a nested shortcode string (the dynamic tag case) — the
		// %-encoded form of the tag name always contains a literal '-' and
		// 'template' survives URL-encoding unchanged, so checking for either
		// substring directly, without decoding first, is enough to rule out
		// plain text that could never match.
		if ( false === stripos( $text, self::SHORTCODE_TAG )
			&& false === stripos( $text, 'template' ) // survives rawurlencode() unchanged
			&& false === strpos( $text, 'settings=' )
		) {
			return array();
		}

		$ids = array();
		foreach ( self::decoded_text_forms( $text ) as $form ) {
			if ( preg_match_all(
				'/\[' . preg_quote( self::SHORTCODE_TAG, '/' ) . '\b[^\]]*\bid=["\']?(\d+)["\']?[^\]]*\]/i',
				$form,
				$matches
			) ) {
				foreach ( $matches[1] as $raw_id ) {
					$id = (int) $raw_id;
					if ( $id > 0 ) {
						$ids[ $id ] = true;
					}
				}
			}
		}

		return array_keys( $ids );
	}

	/**
	 * $text itself, its URL-decoded forms (up to two levels — Elementor's
	 * action-hash encoding can be doubled, same as
	 * Mcpwp_Elementor_Element_Ids::decoded_forms()), and the "Shortcode"
	 * dynamic tag's own nested shortcode string wherever a `settings=`
	 * payload (urlencoded-JSON or base64-JSON, both forms Elementor's
	 * Dynamic_Tags Manager produces) decodes to one.
	 *
	 * @param string $text Source text.
	 * @return string[] Forms to scan, including $text itself.
	 */
	private static function decoded_text_forms( $text ) {
		$forms   = array( $text );
		$current = $text;

		for ( $level = 0; $level < 2 && false !== strpos( $current, '%' ); $level++ ) {
			$next = rawurldecode( $current );
			if ( $next === $current ) {
				break;
			}
			$forms[] = $next;
			$current = $next;
		}

		$extra = array();
		foreach ( $forms as $form ) {
			if ( ! preg_match_all( '/settings=["\']?([^"\'&\]\s]+)/', $form, $matches ) ) {
				continue;
			}
			foreach ( $matches[1] as $candidate ) {
				foreach ( array( rawurldecode( $candidate ), $candidate ) as $maybe_json ) {
					$decoded = json_decode( $maybe_json, true );
					if ( is_array( $decoded ) && isset( $decoded['shortcode'] ) && is_string( $decoded['shortcode'] ) ) {
						$extra[] = $decoded['shortcode'];
					}
				}

				$binary = base64_decode( $candidate, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( false !== $binary && '' !== $binary ) {
					$extra[] = $binary;
					$binary_decoded = json_decode( $binary, true );
					if ( is_array( $binary_decoded ) && isset( $binary_decoded['shortcode'] ) && is_string( $binary_decoded['shortcode'] ) ) {
						$extra[] = $binary_decoded['shortcode'];
					}
				}
			}
		}

		return array_values( array_unique( array_merge( $forms, $extra ) ) );
	}
}
