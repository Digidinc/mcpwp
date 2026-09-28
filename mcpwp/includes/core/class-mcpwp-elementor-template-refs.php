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
 * Adversarial review round 2 findings folded in here (2026-09-28):
 *
 *  - The Popup dynamic tag (`modules/popup/tag.php`) IS now caught: its
 *    `__dynamic__`-encoded settings decode to `{"action":"open","popup":"N"}`,
 *    and `popup` is in SETTINGS_KEYS above, so find_ids_in_value()'s generic
 *    per-key coercion catches it the same way `template_id` is caught
 *    anywhere it appears — no dynamic-tag-specific parsing needed. Catching
 *    every OTHER dynamic tag that can name an arbitrary post id of ANY type
 *    (not just a Template Library one) remains out of scope — a materially
 *    larger, open-ended surface that deserves its own audit.
 *  - The `[elementor-template]` shortcode is now parsed with WordPress's own
 *    `get_shortcode_regex()`/`shortcode_parse_atts()` (shortcode_ids_in_text())
 *    instead of a hand-rolled regex — the same tokeniser Elementor's own
 *    shortcode callback is invoked through, so `id = "5"`, `id=" 5"`,
 *    `id="+5"`, `id="5" data-id="8"`, and an escaped `[[elementor-template]]`
 *    (never processed, exactly like WordPress's own escape rule) are all
 *    read exactly as WordPress/Elementor would read them. Falls back to a
 *    bracket-tolerant regex only where the real shortcode engine is not
 *    loaded (this plugin's own PHPUnit harness).
 *  - find_ids_in_value() is the ONE generic recursive scanner: arrays,
 *    objects, JSON-encoded strings, and urlencoded/base64 dynamic-tag
 *    `settings=` payloads are all walked the same way, checking every array
 *    key against is_reference_key() and every string against
 *    shortcode_ids_in_text(). The Elementor element-tree walker (find()/
 *    scan_settings()) and the request-body-wide chokepoint
 *    (Mcpwp_Api_Auth::check_request_template_refs()) both call it, so a
 *    reference cannot be found by one and missed by the other.
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
	const SETTINGS_KEYS = array( 'template_id', 'additional_template_select', 'customize_dashboard_select', 'popup' );

	/**
	 * Cap on how many nodes ONE find_ids_in_value() call will visit,
	 * independent of the depth cap its caller passes in — bounds a WIDE
	 * payload (many siblings at the same shallow depth) that a depth cap
	 * alone would not catch (#1032 round 2).
	 */
	const MAX_SCAN_NODES = 200000;

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
	 * Round-3 adversarial review NIT: every OTHER reference key
	 * (`template_id`, `templateID`, the `_template`/`template_id` suffix
	 * convention) is Elementor-specific enough by name that a bare match
	 * anywhere in a request tree is a reasonable bet. `popup` is not — it is
	 * an ordinary English word a completely unrelated route param could use
	 * (a form field, a UI-state flag) with no Elementor involvement at all,
	 * so treating it as a template reference unconditionally, EVERYWHERE the
	 * request-body-wide chokepoint walks (not just inside an Elementor
	 * `settings` object), is broader than the one real case it exists for:
	 * Elementor Pro's Popup dynamic tag, whose `__dynamic__`-decoded settings
	 * are shaped exactly `{"action":"open","popup":"N"}`
	 * (`modules/popup/tag.php`). Requiring that sibling shape — an `action`
	 * key in the SAME array equal to `'open'` — keeps the real case caught
	 * while an unrelated `popup` param elsewhere in a request never matches.
	 *
	 * A no-op for every key other than the bare `'popup'` match: the suffix
	 * and other exact-name matches stay unconditional, as they already were.
	 *
	 * @param string $key           The matched key.
	 * @param array  $sibling_array The array $key was found in (siblings live here).
	 * @return bool
	 */
	private static function is_reference_key_in_context( $key, array $sibling_array ) {
		if ( 'popup' !== $key ) {
			return true;
		}

		return isset( $sibling_array['action'] ) && 'open' === $sibling_array['action'];
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

		if ( is_string( $value ) ) {
			// PHP's own (int) cast already skips leading ASCII whitespace and
			// a leading sign ("+5"/" 5" both already resolve to 5), but NOT a
			// non-breaking space or the other Unicode space separators a rich
			// text editor can leave behind (" 5" casts to 0, not 5) —
			// adversarial review round 2's own named case. Stripped here so
			// this coercion is at least as liberal as Elementor's real one,
			// never less.
			$value = preg_replace( '/^[\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]+/u', '', $value );
			if ( null === $value ) {
				return 0; // Malformed UTF-8: preg_replace()'s own failure mode.
			}
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
	 * `[elementor-template]` shortcode embedded in any string value. A thin
	 * wrapper over find_ids_in_value() (#1032 round 2) so the element-tree
	 * walker and the request-body-wide chokepoint share one extractor.
	 *
	 * @param array  $settings Settings (or a nested settings sub-array).
	 * @param string $path     Owning element's path, for reporting.
	 * @param array  &$found   Accumulator.
	 */
	private static function scan_settings( array $settings, $path, array &$found ) {
		$ids = self::find_ids_in_value( $settings );
		if ( is_wp_error( $ids ) ) {
			return; // Oversized settings blob — the request-level chokepoint's own byte/depth caps are what refuse this; this walker only reports what it can.
		}
		foreach ( $ids as $id ) {
			$found[] = array(
				'path' => $path,
				'key'  => 'settings',
				'id'   => $id,
			);
		}
	}

	/**
	 * The ONE generic recursive scanner (#1032 round 2 — coordinator
	 * decision): finds every Template Library reference in ANY nested value
	 * tree, not just an Elementor element tree — a plain request body
	 * (sidebar widget instance settings, a WooCommerce product's
	 * description, a Gutenberg block queued for approval, a content-graph
	 * anchor, a media description, or anything else a caller can put an
	 * arbitrary string or array into). Two sources per node:
	 *
	 *  - Any ARRAY KEY matching is_reference_key() (template_id, templateID,
	 *    popup, the `_template`/`template_id` suffix convention, or a
	 *    filtered addition) — its value is coerced the way Elementor itself
	 *    would coerce it (coerce_id()).
	 *  - Any STRING VALUE is scanned for an `[elementor-template]` shortcode
	 *    (shortcode_ids_in_text(), using WordPress's own shortcode
	 *    tokeniser) and, if it decodes to JSON or a urlencoded/base64
	 *    dynamic-tag `settings=` payload, recursed into as a nested value —
	 *    so an elementor_data tree SENT AS A JSON STRING, or a Popup/
	 *    Shortcode dynamic tag's `__dynamic__`-encoded settings, are walked
	 *    exactly as if they had arrived as real nested structure.
	 *
	 * Bounded on two independent axes so cost cannot scale with an
	 * attacker-controlled payload: $max_depth (nesting) and
	 * self::MAX_SCAN_NODES (breadth — many siblings at one shallow depth).
	 * Hitting either returns a WP_Error (413) rather than silently
	 * truncating the scan, which could let an unscanned tail through
	 * undetected.
	 *
	 * @param mixed $value     Any decoded value — array, object, scalar.
	 * @param int   $max_depth Maximum nesting depth to walk.
	 * @return int[]|WP_Error Distinct positive ids, or a 413 WP_Error when
	 *                        the scan's own size/depth caps were exceeded.
	 */
	public static function find_ids_in_value( $value, $max_depth = 40 ) {
		$found  = array();
		$budget = array(
			'nodes'    => 0,
			'exceeded' => false,
		);

		self::walk_value( $value, $found, 0, (int) $max_depth, $budget );

		if ( $budget['exceeded'] ) {
			return new WP_Error(
				'payload_too_complex',
				__( 'Request body is too large or deeply nested to scan for template references.', 'mcpwp' ),
				array( 'status' => 413 )
			);
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * find_ids_in_value()'s own recursion. See that method's docblock.
	 *
	 * @param mixed $value     Current node.
	 * @param array &$found    Accumulator (plain int ids).
	 * @param int   $depth     Current depth.
	 * @param int   $max_depth Depth cap.
	 * @param array &$budget   {nodes:int, exceeded:bool} — shared across the whole walk.
	 */
	private static function walk_value( $value, array &$found, $depth, $max_depth, array &$budget ) {
		if ( $budget['exceeded'] ) {
			return;
		}
		if ( $depth > $max_depth ) {
			$budget['exceeded'] = true;
			return;
		}
		++$budget['nodes'];
		if ( $budget['nodes'] > self::MAX_SCAN_NODES ) {
			$budget['exceeded'] = true;
			return;
		}

		if ( is_object( $value ) ) {
			$value = (array) $value;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $sub_value ) {
				if ( $budget['exceeded'] ) {
					return;
				}
				if ( is_string( $key ) && self::is_reference_key( $key ) && self::is_reference_key_in_context( $key, $value ) ) {
					$id = self::coerce_id( $sub_value );
					if ( $id > 0 ) {
						$found[] = $id;
					}
				}
				self::walk_value( $sub_value, $found, $depth + 1, $max_depth, $budget );
			}
			return;
		}

		if ( is_string( $value ) && '' !== $value ) {
			foreach ( self::shortcode_ids_in_text( $value ) as $id ) {
				$found[] = $id;
			}

			foreach ( self::decode_nested_values( $value ) as $nested ) {
				self::walk_value( $nested, $found, $depth + 1, $max_depth, $budget );
			}
		}
	}

	/**
	 * The shared string scanner (#1032 B3), kept as a public alias of
	 * find_ids_in_value() for existing callers that scan a single string
	 * (post_content on posts/pages/content writes) rather than an arbitrary
	 * value tree.
	 *
	 * @param string $text Arbitrary text.
	 * @return int[] Distinct positive ids. (A string alone can never trip
	 *               find_ids_in_value()'s own size/depth caps in a way that
	 *               matters here — those exist for request-body-sized
	 *               trees — so this never returns a WP_Error.)
	 */
	public static function ids_in_text( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return array();
		}

		$ids = self::find_ids_in_value( $text, 10 );

		return is_wp_error( $ids ) ? array() : $ids;
	}

	/**
	 * Every `[elementor-template id="N"]` shortcode occurrence in $text,
	 * parsed exactly the way WordPress itself would: WordPress's own
	 * `get_shortcode_regex()` + `shortcode_parse_atts()` when the shortcode
	 * engine is loaded (production — this is a REAL WordPress request),
	 * handling `id = "5"`, `id=" 5"`, `id="+5"`, `id="5" data-id="8"`, and
	 * an escaped `[[elementor-template ...]]` (never processed — WordPress's
	 * own `do_shortcode_tag()` escape rule: `'[' === $m[1] && ']' === $m[6]`)
	 * identically to how Elementor's own callback would see them (#1032
	 * round 2 B1). Falls back to a bracket-tolerant regex only when the real
	 * shortcode engine is not loaded (this plugin's own PHPUnit harness,
	 * which does not boot WordPress's shortcode subsystem).
	 *
	 * @param string $text Text to scan.
	 * @return int[] Distinct positive ids.
	 */
	private static function shortcode_ids_in_text( $text ) {
		if ( false === stripos( $text, self::SHORTCODE_TAG ) && false === stripos( $text, 'template' ) ) {
			return array();
		}

		$ids = array();

		if ( function_exists( 'get_shortcode_regex' ) && function_exists( 'shortcode_parse_atts' ) ) {
			$pattern = get_shortcode_regex( array( self::SHORTCODE_TAG ) );
			if ( preg_match_all( '/' . $pattern . '/s', $text, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					// WordPress's own escape rule: [[elementor-template ...]] is
					// literal, never processed (do_shortcode_tag()).
					if ( isset( $m[1], $m[6] ) && '[' === $m[1] && ']' === $m[6] ) {
						continue;
					}
					$atts = shortcode_parse_atts( $m[3] );
					if ( is_array( $atts ) && isset( $atts['id'] ) ) {
						$id = self::coerce_id( $atts['id'] );
						if ( $id > 0 ) {
							$ids[] = $id;
						}
					}
				}
			}

			return $ids;
		}

		// Fallback: no real shortcode engine loaded. Bracket-tolerant, with
		// the same [[...]] escape rule, but a negative lookbehind (rather
		// than shortcode_parse_atts()'s real tokenising) to tell a bare `id`
		// attribute from a `data-id`/`xyz-id` one it must not match.
		if ( preg_match_all(
			'/(\[?)\[' . preg_quote( self::SHORTCODE_TAG, '/' ) . '\b([^\]]*)\](\]?)/i',
			$text,
			$matches,
			PREG_SET_ORDER
		) ) {
			foreach ( $matches as $m ) {
				if ( '[' === $m[1] && ']' === $m[3] ) {
					continue; // Escaped literal.
				}
				if ( preg_match( '/(?<![\w-])id\s*=\s*["\']?\+?\s*(\d+)/', $m[2], $id_match ) ) {
					$id = self::coerce_id( $id_match[1] );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				}
			}
		}

		return $ids;
	}

	/**
	 * Decoded candidate VALUES (arrays) hiding inside a string — a JSON blob
	 * sent as a string (an `elementor_data` param, or any nested payload),
	 * or a urlencoded-JSON/base64-JSON `settings=` value the way Elementor's
	 * Dynamic_Tags Manager encodes a tag's own settings (mirrors
	 * Mcpwp_Elementor_Element_Ids::decoded_forms()'s decoding of the same
	 * encodings). Each returned value is walked by the caller exactly like
	 * any other nested value — including its own further-nested strings —
	 * so a Popup/Shortcode dynamic tag's settings are scanned by the SAME
	 * generic key/string logic as everything else, not a dynamic-tag-
	 * specific special case.
	 *
	 * @param string $text Source text.
	 * @return array<int,array> Decoded values to recurse into.
	 */
	private static function decode_nested_values( $text ) {
		$out = array();

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

		foreach ( $forms as $form ) {
			$trimmed = ltrim( $form );
			if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
				$decoded = json_decode( $form, true );
				if ( is_array( $decoded ) ) {
					$out[] = $decoded;
				}
			}

			if ( preg_match_all( '/settings=["\']?([^"\'&\]\s]+)/', $form, $matches ) ) {
				foreach ( $matches[1] as $candidate ) {
					$decoded = json_decode( rawurldecode( $candidate ), true );
					if ( is_array( $decoded ) ) {
						$out[] = $decoded;
					}

					$binary = base64_decode( $candidate, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					if ( false !== $binary && '' !== $binary ) {
						$binary_decoded = json_decode( $binary, true );
						if ( is_array( $binary_decoded ) ) {
							$out[] = $binary_decoded;
						}
					}
				}
			}
		}

		return $out;
	}
}
