<?php
/**
 * Checks rendered Elementor output against the tree that was saved (#989).
 *
 * A write used to report `render_verified: true` when every Elementor call in
 * the save pipeline returned without throwing. That says nothing about what
 * the page shows. #804 recorded pages that passed while the browser showed
 * Elementor's own defaults ("Add Your Heading Text Here", placeholder.png) and
 * elements that rendered nothing at all. This class reads the HTML Elementor
 * produced for the page and asserts the things a caller cares about:
 *
 * - every element id in the saved tree is present in the DOM (`data-id`), so
 *   an element that rendered no output is caught;
 * - no Elementor default placeholder is showing, unless the saved data asked
 *   for that exact text;
 * - no unrendered builder shortcode (`[et_pb_...]`, `[elementor-template]`)
 *   leaked into the visible text;
 * - a heading's title and a button's label appear inside that element.
 *
 * Buttons that link to a bare `#` are reported as warnings only, because a
 * `#` link can be deliberate.
 *
 * The class only reads strings. It never renders, fetches, or writes, so it can
 * be tested without Elementor.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mcpwp_Render_Inspector {

	/**
	 * Most element ids listed in a report. The count is always exact.
	 */
	const MAX_REPORTED_IDS = 20;

	/**
	 * Widget settings whose text must appear inside the widget's rendered
	 * element. Kept to plain-text settings, where a normalised substring test
	 * cannot be thrown off by markup the widget adds around the value.
	 *
	 * @var array<string,string> widgetType => settings key
	 */
	private static $text_settings = array(
		'heading' => 'title',
		'button'  => 'text',
	);

	/**
	 * Elementor's own default texts and images. Each shows on the page when a
	 * widget's setting was never saved, most often because the data used a
	 * wrong control key (`text` instead of `title` on a heading).
	 *
	 * `match` is the literal to look for, `in` is where (visible `text` or raw
	 * `html`), and `regex` marks a pattern instead of a literal. `exempt_if_saved`
	 * is the literal that, when present in the saved tree, means the caller
	 * asked for it, so it is not reported.
	 *
	 * @return array<string,array{label:string,match:string,in:string,regex:bool,exempt_if_saved:string}>
	 */
	public static function placeholder_patterns() {
		return array(
			'elementor_default_heading' => array(
				'label'           => 'Elementor default heading text "Add Your Heading Text Here"',
				'match'           => 'Add Your Heading Text Here',
				'in'              => 'text',
				'regex'           => false,
				'exempt_if_saved' => 'Add Your Heading Text Here',
			),
			'elementor_default_text'    => array(
				'label'           => 'Elementor default body text "Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus..."',
				'match'           => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus',
				'in'              => 'text',
				'regex'           => false,
				'exempt_if_saved' => 'Ut elit tellus',
			),
			'elementor_legacy_text'     => array(
				'label'           => 'Elementor legacy default text "I am text block. Click edit button to change this text."',
				'match'           => 'I am text block. Click edit button to change this text.',
				'in'              => 'text',
				'regex'           => false,
				'exempt_if_saved' => 'I am text block',
			),
			'elementor_default_box_title' => array(
				'label'           => 'Elementor default box title "This is the heading"',
				'match'           => 'This is the heading',
				'in'              => 'text',
				'regex'           => false,
				'exempt_if_saved' => 'This is the heading',
			),
			'elementor_placeholder_image' => array(
				'label'           => 'Elementor placeholder image (assets/images/placeholder.png)',
				'match'           => 'assets/images/placeholder.png',
				'in'              => 'html',
				'regex'           => false,
				'exempt_if_saved' => 'placeholder.png',
			),
			'elementor_default_button_text' => array(
				'label'           => 'Elementor default button label "Click here"',
				'match'           => '#<span[^>]*class="[^"]*\belementor-button-text\b[^"]*"[^>]*>\s*Click here\s*</span>#i',
				'in'              => 'html',
				'regex'           => true,
				'exempt_if_saved' => 'Click here',
			),
		);
	}

	/**
	 * Builder shortcodes that should never reach visible text. Their presence
	 * means a shortcode was stored but nothing rendered it (Divi deactivated,
	 * or Divi content pasted into an Elementor text widget).
	 *
	 * @return array<string,string> key => regex
	 */
	public static function shortcode_leak_patterns() {
		return array(
			'divi_shortcode'               => '/\[\/?et_pb_[a-z0-9_]*/i',
			'elementor_template_shortcode' => '/\[elementor-template\b/i',
		);
	}

	/**
	 * Check one render of a page against its saved Elementor tree.
	 *
	 * @param string $html     HTML Elementor produced for the page.
	 * @param array  $elements Saved element list (the stored tree).
	 * @return array{status:string,elements_expected:int,elements_rendered:int,elements_not_checked:int,missing_element_count:int,missing_element_ids:string[],placeholders:array,shortcode_leaks:string[],text_mismatches:array,failures:string[],warnings:string[]}
	 */
	public static function inspect_elementor_output( $html, array $elements ) {
		$expected    = array();
		$not_checked = 0;
		self::collect_expected( $elements, $expected, $not_checked );

		$report = array(
			'status'                => 'passed',
			'elements_expected'     => count( $expected ),
			'elements_rendered'     => 0,
			'elements_not_checked'  => $not_checked,
			'missing_element_count' => 0,
			'missing_element_ids'   => array(),
			'placeholders'          => array(),
			'shortcode_leaks'       => array(),
			'text_mismatches'       => array(),
			'failures'              => array(),
			'warnings'              => array(),
		);

		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			if ( ! empty( $expected ) ) {
				$report['status']     = 'failed';
				$report['failures'][] = sprintf(
					'dom_check: Elementor rendered no HTML for this page, though the saved data has %d element(s).',
					count( $expected )
				);
				$report['missing_element_count'] = count( $expected );
				$report['missing_element_ids']   = array_slice( array_keys( $expected ), 0, self::MAX_REPORTED_IDS );
			}

			return $report;
		}

		$doc = self::load_dom( $html );
		if ( null === $doc ) {
			$report['status']     = 'failed';
			$report['failures'][] = 'dom_check: The rendered HTML could not be parsed.';

			return $report;
		}

		$xpath = new DOMXPath( $doc );

		// First node per data-id. Nested templates add their own ids, which
		// are ignored: only the ids this page's own tree contains are expected.
		$nodes = array();
		foreach ( $xpath->query( '//*[@data-id]' ) as $node ) {
			$id = (string) $node->getAttribute( 'data-id' );
			if ( '' !== $id && ! isset( $nodes[ $id ] ) ) {
				$nodes[ $id ] = $node;
			}
		}

		$missing = array();
		foreach ( $expected as $id => $element ) {
			if ( isset( $nodes[ $id ] ) ) {
				++$report['elements_rendered'];
				self::check_text( $element, $nodes[ $id ], $report );
			} else {
				$missing[] = (string) $id;
			}
		}

		if ( ! empty( $missing ) ) {
			$report['missing_element_count'] = count( $missing );
			$report['missing_element_ids']   = array_slice( $missing, 0, self::MAX_REPORTED_IDS );
			$report['failures'][]            = sprintf(
				'dom_check: %1$d of %2$d saved element(s) rendered no output (data-id not in the rendered HTML): %3$s%4$s. A widget renders nothing when its main setting is empty or its type is not registered.',
				count( $missing ),
				count( $expected ),
				implode( ', ', $report['missing_element_ids'] ),
				count( $missing ) > self::MAX_REPORTED_IDS ? ', ...' : ''
			);
		}

		$body_nodes   = $xpath->query( '//body' );
		$visible_text = self::normalise_space( $body_nodes->length > 0 ? $body_nodes->item( 0 )->textContent : $doc->textContent );
		$saved_blob   = self::saved_blob( $elements );

		foreach ( self::placeholder_patterns() as $key => $pattern ) {
			if ( '' !== $pattern['exempt_if_saved'] && false !== strpos( $saved_blob, $pattern['exempt_if_saved'] ) ) {
				continue;
			}

			$haystack = 'text' === $pattern['in'] ? $visible_text : $html;
			$count    = $pattern['regex']
				? (int) preg_match_all( $pattern['match'], $haystack )
				: substr_count( $haystack, $pattern['match'] );

			if ( $count > 0 ) {
				$report['placeholders'][] = array(
					'type'  => $key,
					'label' => $pattern['label'],
					'count' => $count,
				);
				$report['failures'][]     = sprintf(
					'dom_check: The page shows %1$s (%2$d time(s)). The saved data did not set the widget setting this default fills, which usually means a wrong control key.',
					$pattern['label'],
					$count
				);
			}
		}

		foreach ( self::shortcode_leak_patterns() as $key => $regex ) {
			if ( preg_match( $regex, $visible_text ) ) {
				$report['shortcode_leaks'][] = $key;
				$report['failures'][]        = sprintf(
					'dom_check: Unrendered shortcode text is visible on the page (%s). The shortcode was stored but nothing rendered it.',
					$key
				);
			}
		}

		foreach ( $report['text_mismatches'] as $mismatch ) {
			$report['failures'][] = sprintf(
				'dom_check: %1$s %2$s rendered, but its %3$s "%4$s" is not in its rendered text.',
				$mismatch['widget_type'],
				$mismatch['element_id'],
				$mismatch['setting'],
				$mismatch['expected']
			);
		}

		$hash_buttons = $xpath->query( '//a[contains(concat(" ", normalize-space(@class), " "), " elementor-button ") and normalize-space(@href) = "#"]' );
		if ( $hash_buttons->length > 0 ) {
			$owners = array();
			foreach ( $hash_buttons as $button ) {
				$owner = self::owning_element_id( $button );
				if ( '' !== $owner ) {
					$owners[ $owner ] = true;
				}
			}
			$report['warnings'][] = sprintf(
				'dom_check: %1$d button(s) link to "#"%2$s. Set a link if the button should go somewhere.',
				$hash_buttons->length,
				empty( $owners ) ? '' : ' (' . implode( ', ', array_slice( array_keys( $owners ), 0, self::MAX_REPORTED_IDS ) ) . ')'
			);
		}

		if ( ! empty( $report['failures'] ) ) {
			$report['status'] = 'failed';
		}

		return $report;
	}

	/**
	 * Gather the element ids a render must contain.
	 *
	 * An element with Elementor Pro display conditions may be hidden on
	 * purpose, so it and everything inside it is counted as not checked.
	 *
	 * @param array $elements    Element list.
	 * @param array $expected    id => element, by reference.
	 * @param int   $not_checked Counter, by reference.
	 */
	private static function collect_expected( array $elements, array &$expected, &$not_checked ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();

			if ( ! empty( $settings['e_display_conditions'] ) ) {
				$not_checked += 1 + self::count_all( $children );
				continue;
			}

			$id = isset( $element['id'] ) && is_scalar( $element['id'] ) ? (string) $element['id'] : '';
			if ( '' !== $id ) {
				$expected[ $id ] = $element;
			} else {
				++$not_checked;
			}

			if ( ! empty( $children ) ) {
				self::collect_expected( $children, $expected, $not_checked );
			}
		}
	}

	/**
	 * Count every element in a subtree.
	 *
	 * @param array $elements Element list.
	 * @return int
	 */
	private static function count_all( array $elements ) {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			++$count;
			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += self::count_all( $element['elements'] );
			}
		}

		return $count;
	}

	/**
	 * Record a text mismatch when a heading title or button label the saved
	 * data set is not inside that element's rendered text.
	 *
	 * @param array   $element Saved element.
	 * @param DOMNode $node    Its rendered node.
	 * @param array   $report  Report, by reference.
	 */
	private static function check_text( array $element, $node, array &$report ) {
		$type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';
		if ( ! isset( self::$text_settings[ $type ] ) ) {
			return;
		}

		$key      = self::$text_settings[ $type ];
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();

		// A dynamic tag replaces the stored value at render time.
		if ( isset( $settings['__dynamic__'] ) && is_array( $settings['__dynamic__'] ) && ! empty( $settings['__dynamic__'][ $key ] ) ) {
			return;
		}

		if ( ! isset( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) ) {
			return;
		}

		$expected = self::normalise_text( wp_strip_all_tags( $settings[ $key ] ) );
		if ( '' === $expected ) {
			return;
		}

		$rendered = self::normalise_text( $node->textContent );
		if ( false !== strpos( $rendered, $expected ) ) {
			return;
		}

		$report['text_mismatches'][] = array(
			'element_id'  => (string) $element['id'],
			'widget_type' => $type,
			'setting'     => $key,
			'expected'    => function_exists( 'mb_substr' ) ? mb_substr( $expected, 0, 80 ) : substr( $expected, 0, 80 ),
		);
	}

	/**
	 * The nearest ancestor-or-self data-id of a node.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private static function owning_element_id( $node ) {
		while ( $node instanceof DOMElement ) {
			if ( $node->hasAttribute( 'data-id' ) ) {
				return (string) $node->getAttribute( 'data-id' );
			}
			$node = $node->parentNode;
		}

		return '';
	}

	/**
	 * Saved tree as one string, for "did the caller ask for this text" checks.
	 *
	 * @param array $elements Element list.
	 * @return string
	 */
	private static function saved_blob( array $elements ) {
		$json = wp_json_encode( $elements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? html_entity_decode( $json, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	}

	/**
	 * Parse an HTML string, fragment or full document.
	 *
	 * @param string $html HTML.
	 * @return DOMDocument|null
	 */
	public static function load_dom( $html ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return null;
		}

		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $ok ? $doc : null;
	}

	/**
	 * Collapse whitespace (including non-breaking spaces) and trim.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function normalise_space( $text ) {
		$text = str_replace( "\xC2\xA0", ' ', (string) $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Text normalised for a substring comparison: entities decoded, typographic
	 * quotes and dashes folded to ASCII (wptexturize changes them on output),
	 * whitespace collapsed, case folded.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalise_text( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = strtr(
			$text,
			array(
				"\xE2\x80\x98" => "'",
				"\xE2\x80\x99" => "'",
				"\xE2\x80\x9C" => '"',
				"\xE2\x80\x9D" => '"',
				"\xE2\x80\x93" => '-',
				"\xE2\x80\x94" => '-',
				"\xE2\x80\xA6" => '...',
				"\xE2\x80\xB3" => '"',
				"\xE2\x80\xB2" => "'",
			)
		);
		$text = self::normalise_space( $text );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}
}
