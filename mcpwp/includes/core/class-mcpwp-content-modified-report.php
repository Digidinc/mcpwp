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
	 * Neutral explanation when WordPress (or MCPWP's pre-save strip) dropped
	 * constructs the caller cannot persist.
	 */
	const REMOVAL_MESSAGE = 'WordPress removed content your user cannot save; SVG can be added as media or by a user with unfiltered_html.';

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
		foreach ( array( 'script', 'iframe', 'object', 'embed', 'form', 'svg_elements', 'on_attributes' ) as $kind ) {
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
			'message' => self::REMOVAL_MESSAGE,
		);
	}

	/**
	 * Build a tag/attribute inventory using WP_HTML_Tag_Processor.
	 *
	 * Counts only — never stores attribute values.
	 *
	 * @param string $html HTML document fragment.
	 * @return array{
	 *     script:int,
	 *     iframe:int,
	 *     object:int,
	 *     embed:int,
	 *     form:int,
	 *     svg_elements:int,
	 *     on_attributes:int
	 * }
	 */
	private static function inventory( $html ) {
		$counts = array(
			'script'        => 0,
			'iframe'        => 0,
			'object'        => 0,
			'embed'         => 0,
			'form'          => 0,
			'svg_elements'  => 0,
			'on_attributes' => 0,
		);

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
		}

		return $counts;
	}
}
