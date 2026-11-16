<?php
/**
 * Saved-vs-submitted content modification report (#1212).
 *
 * After a content write, compare what the caller sent to the row that was
 * actually stored. Report only KIND + COUNT of removed constructs — never
 * echo payload or attribute values, and never claim the content was made safe.
 *
 * This class does not sanitise. Saving still follows origin/main behaviour
 * (MCPWP's existing sanitize_content_body where applicable, plus WordPress
 * core's own post-kses for principals without unfiltered_html).
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inventory-based content_modified reporting for post/page/block writes.
 */
class Mcpwp_Content_Modified_Report {

	/**
	 * Skip inventory comparison above this many bytes of submitted or saved
	 * content (UTF-8 string length via strlen).
	 */
	const MAX_COMPARE_BYTES = 1048576; // 1 MiB

	/**
	 * Inventory kind keys compared for removal deltas.
	 *
	 * @var array<int, string>
	 */
	private static $inventory_kinds = array(
		'script',
		'iframe',
		'object',
		'embed',
		'form',
		'svg_elements',
		'on_attributes',
		'unsafe_url_attributes',
		'style',
		'foreignobject',
		'math',
		'input',
		'button',
		'textarea',
		'select',
	);

	/**
	 * SVG-family element local names counted under kind `svg_elements`.
	 *
	 * @var array<string, true>
	 */
	private static $svg_family = array(
		'svg'            => true,
		'g'              => true,
		'path'           => true,
		'circle'         => true,
		'rect'           => true,
		'line'           => true,
		'polyline'       => true,
		'polygon'        => true,
		'ellipse'        => true,
		'defs'           => true,
		'lineargradient' => true,
		'radialgradient' => true,
		'stop'           => true,
		'use'            => true,
	);

	/**
	 * URL-bearing attributes checked for javascript:/data:/vbscript: schemes.
	 *
	 * @var array<int, string>
	 */
	private static $url_attributes = array( 'href', 'src' );

	/**
	 * Read post_content from the stored row (raw / unfiltered when possible).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function saved_post_content( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return '';
		}

		// Prefer the in-memory / DB object field — bypasses display filters.
		$post = get_post( $post_id );
		if ( $post && isset( $post->post_content ) ) {
			return (string) $post->post_content;
		}

		if ( function_exists( 'get_post_field' ) ) {
			return (string) get_post_field( 'post_content', $post_id, 'raw' );
		}

		return '';
	}

	/**
	 * Compare submitted content to the saved row; list removal kinds (counts only).
	 *
	 * When either side exceeds {@see MAX_COMPARE_BYTES}, returns
	 * `content_comparison_skipped` instead of inventoring.
	 *
	 * @param mixed $submitted Content the caller sent.
	 * @param mixed $saved     Content stored after the write.
	 * @return array{
	 *     removed?: array<int, array{kind:string, count:int}>,
	 *     message?: string,
	 *     content_comparison_skipped?: bool
	 * }
	 */
	public static function describe_modifications( $submitted, $saved ) {
		if ( ! is_string( $submitted ) ) {
			$submitted = is_scalar( $submitted ) ? (string) $submitted : '';
		}
		if ( ! is_string( $saved ) ) {
			$saved = is_scalar( $saved ) ? (string) $saved : '';
		}

		if ( strlen( $submitted ) > self::MAX_COMPARE_BYTES || strlen( $saved ) > self::MAX_COMPARE_BYTES ) {
			return array(
				'content_comparison_skipped' => true,
			);
		}

		if ( ! class_exists( 'WP_HTML_Tag_Processor', false ) ) {
			return array(
				'content_comparison_skipped' => true,
			);
		}

		$before = self::inventory( $submitted );
		$after  = self::inventory( $saved );

		$kinds = array();
		foreach ( self::$inventory_kinds as $kind ) {
			$delta = (int) $before[ $kind ] - (int) $after[ $kind ];
			if ( $delta > 0 ) {
				$kinds[] = array(
					'kind'  => $kind,
					'count' => $delta,
				);
			}
		}

		if ( empty( $kinds ) ) {
			// Inventories match — ignore whitespace / entity normalisation
			// differences between submitted and saved strings.
			return array();
		}

		return array(
			'removed' => $kinds,
			'message' => self::message_for_kinds( $kinds ),
		);
	}

	/**
	 * Attach a non-empty report onto a write result, merging kind counts when
	 * a report is already present (e.g. description + short_description).
	 *
	 * @param array $result Write response (by ref).
	 * @param array $report Report from {@see describe_modifications()}.
	 * @return void
	 */
	public static function attach_to_result( array &$result, array $report ) {
		if ( empty( $report['removed'] ) && empty( $report['content_comparison_skipped'] ) ) {
			return;
		}

		if ( empty( $result['content_modified'] ) || ! is_array( $result['content_modified'] ) ) {
			$result['content_modified'] = $report;
			return;
		}

		$existing = $result['content_modified'];
		if ( ! empty( $report['content_comparison_skipped'] ) || ! empty( $existing['content_comparison_skipped'] ) ) {
			$result['content_modified'] = array(
				'content_comparison_skipped' => true,
			);
			return;
		}

		$counts = array();
		foreach ( array( $existing, $report ) as $piece ) {
			if ( empty( $piece['removed'] ) || ! is_array( $piece['removed'] ) ) {
				continue;
			}
			foreach ( $piece['removed'] as $row ) {
				if ( ! is_array( $row ) || empty( $row['kind'] ) ) {
					continue;
				}
				$kind = (string) $row['kind'];
				$counts[ $kind ] = ( isset( $counts[ $kind ] ) ? (int) $counts[ $kind ] : 0 ) + (int) ( $row['count'] ?? 0 );
			}
		}

		$merged = array();
		foreach ( self::$inventory_kinds as $kind ) {
			if ( ! empty( $counts[ $kind ] ) ) {
				$merged[] = array(
					'kind'  => $kind,
					'count' => (int) $counts[ $kind ],
				);
			}
		}

		if ( empty( $merged ) ) {
			return;
		}

		$result['content_modified'] = array(
			'removed' => $merged,
			'message' => self::message_for_kinds( $merged ),
		);
	}

	/**
	 * Neutral per-kind guidance. Never names a capability (MCPWP kses is
	 * unconditional; unfiltered_html does not restore stripped markup).
	 *
	 * @param array<int, array{kind:string, count:int}> $kinds Removal rows.
	 * @return string
	 */
	private static function message_for_kinds( array $kinds ) {
		$set = array();
		foreach ( $kinds as $row ) {
			if ( is_array( $row ) && isset( $row['kind'] ) ) {
				$set[ (string) $row['kind'] ] = true;
			}
		}

		$parts = array();

		if ( isset( $set['svg_elements'] ) ) {
			$parts[] = 'SVG elements were removed when saving; convert the graphic to PNG or WebP, upload that as media and reference it with an image block.';
		}

		// script / iframe / object / embed / form / foreignObject share one sentence.
		$markup_kinds = array(
			'script',
			'iframe',
			'object',
			'embed',
			'form',
			'foreignobject',
		);
		foreach ( $markup_kinds as $kind ) {
			if ( isset( $set[ $kind ] ) ) {
				$parts[] = 'script, embed and form markup is not stored by this tool.';
				break;
			}
		}

		if ( isset( $set['on_attributes'] ) ) {
			$parts[] = 'event-handler attributes (onclick, onload ...) are not stored';
		}

		if ( isset( $set['math'] ) ) {
			$parts[] = 'MathML markup is not stored by this tool.';
		}

		$form_control_kinds = array(
			'input',
			'button',
			'textarea',
			'select',
		);
		foreach ( $form_control_kinds as $kind ) {
			if ( isset( $set[ $kind ] ) ) {
				$parts[] = 'form-control markup (input, button, textarea, select) is not stored by this tool.';
				break;
			}
		}

		if ( isset( $set['style'] ) ) {
			$parts[] = 'Inline and embedded style markup is not stored by this tool.';
		}

		if ( isset( $set['unsafe_url_attributes'] ) ) {
			$parts[] = 'Unsafe URL attributes were removed when saving.';
		}

		return implode( ' ', $parts );
	}

	/**
	 * Build a tag/attribute inventory using WP_HTML_Tag_Processor.
	 *
	 * Counts only — never stores attribute values.
	 *
	 * @param string $html HTML document fragment.
	 * @return array<string, int>
	 */
	private static function inventory( $html ) {
		$counts = array();
		foreach ( self::$inventory_kinds as $kind ) {
			$counts[ $kind ] = 0;
		}

		if ( '' === $html ) {
			return $counts;
		}

		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$tag = strtolower( (string) $processor->get_tag() );
			if ( '' === $tag ) {
				continue;
			}

			if ( isset( $counts[ $tag ] ) ) {
				++$counts[ $tag ];
			}
			if ( isset( self::$svg_family[ $tag ] ) ) {
				++$counts['svg_elements'];
			}

			$on_names = $processor->get_attribute_names_with_prefix( 'on' );
			if ( is_array( $on_names ) ) {
				foreach ( $on_names as $name ) {
					// Only count real event-handler attributes (on* followed by a letter).
					if ( is_string( $name ) && preg_match( '/^on[a-z]/i', $name ) ) {
						++$counts['on_attributes'];
					}
				}
			}

			if ( null !== $processor->get_attribute( 'style' ) ) {
				++$counts['style'];
			}

			foreach ( self::$url_attributes as $attr ) {
				$value = $processor->get_attribute( $attr );
				if ( is_string( $value ) && self::is_unsafe_url( $value ) ) {
					++$counts['unsafe_url_attributes'];
				}
			}
		}

		return $counts;
	}

	/**
	 * True when a URL attribute uses a non-http(s) active scheme.
	 *
	 * Counts only — the raw value is never stored or echoed.
	 *
	 * @param string $value Attribute value.
	 * @return bool
	 */
	private static function is_unsafe_url( $value ) {
		$value = html_entity_decode( trim( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = preg_replace( '/[\x00-\x1f\x7f\s]+/', '', $value );
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		return (bool) preg_match( '/^(javascript|vbscript|data)\s*:/i', $value );
	}
}
