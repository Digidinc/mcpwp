<?php
/**
 * Sanitization Trait
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Input sanitization and validation functionality.
 */
trait Mcpwp_Sanitization {

	/**
	 * Element tag names whose content model is raw text (CDATA-like), not
	 * markup — everything between the open and close tag is program source,
	 * never text meant to render on the page.
	 *
	 * wp_kses_post() is a markup-aware sanitizer: a disallowed tag it doesn't
	 * recognize is removed, but ordinary text between that tag's start and
	 * end stays, because for every *markup* element that is correct — the
	 * text is content. For `<script>`/`<style>` that "text" is JS/CSS source,
	 * so a stripped `<script>alert(1)</script>` turns into a paragraph
	 * reading "alert(1)" on the live page instead of disappearing (#808).
	 *
	 * `noscript` is deliberately excluded. Its body is ordinary markup — a
	 * fallback shown to JS-disabled browsers — not source code. Once the
	 * disallowed `noscript` wrapper itself is removed, the normal kses
	 * allow-list pass over the markup inside it is correct; there is no
	 * code-as-text leak to guard against, so treating it like script/style
	 * would only discard content a user could otherwise legitimately keep.
	 *
	 * @var string[]
	 */
	private static $raw_text_elements = array( 'script', 'style' );

	/**
	 * Sanitize a post/page/attachment content body for a write.
	 *
	 * Replaces a bare `wp_kses_post( $content )` call. Before kses ever sees
	 * the string, `<script>`/`<style>` elements are removed whole — tag and
	 * body together — so their source code can never survive as visible page
	 * text (#808). kses still runs afterwards for every other disallowed tag,
	 * unchanged from today's behavior.
	 *
	 * Skipped entirely for a user holding `unfiltered_html`: every MCPWP
	 * content write currently runs unconditionally through wp_kses_post()
	 * regardless of the writing user's capabilities, so — unlike the
	 * Elementor writer's scoped `unfiltered_html` grant — there was nothing
	 * for this fix to preserve for that population on this path; this simply
	 * makes the capability check exist here at all, matching how WordPress's
	 * own post editor and the plugin's Elementor writer already treat it.
	 *
	 * @param mixed $content Raw content as submitted by the caller.
	 * @return array{content:string,removed:array<int,array{tag:string,count:int}>}
	 *               Sanitized content and a report of whole elements removed.
	 */
	protected function sanitize_content_body( $content ) {
		if ( ! is_string( $content ) ) {
			$content = '';
		}

		if ( function_exists( 'current_user_can' ) && current_user_can( 'unfiltered_html' ) ) {
			return array(
				'content' => $content,
				'removed' => array(),
			);
		}

		$stripped = $this->strip_raw_text_elements( $content );

		return array(
			'content' => wp_kses_post( $stripped['content'] ),
			'removed' => $stripped['removed'],
		);
	}

	/**
	 * Remove every `<script>`/`<style>` element — opening tag, body, and
	 * closing tag — from $content, tolerating case, extra whitespace/attrs
	 * in the closing tag (e.g. `</SCRIPT >`), and a missing closing tag.
	 *
	 * Mirrors an HTML parser's tokenizer instead of a single greedy/lazy
	 * regex: once an opening `<script>`/`<style>` tag is found, everything up
	 * to that *same* element's matching closing tag is raw text and is
	 * discarded as one unit — including anything inside it that merely looks
	 * like another tag (e.g. a literal "<style>" inside a `<script>` body).
	 * If no matching closing tag exists anywhere after it, a browser treats
	 * the rest of the document as still inside the element, so this does the
	 * same and removes through the end of the string — that's what actually
	 * defeats an unclosed `<script>` bypass; a regex that requires a closing
	 * tag to match at all would leave the tag-less body right where it was.
	 *
	 * A single left-to-right scan (via preg_match with an offset, not
	 * `preg_replace`) also keeps this linear in input length and avoids the
	 * catastrophic-backtracking risk a `.*` between open/close tags invites
	 * on adversarial input.
	 *
	 * @param string $content Content to scan.
	 * @return array{content:string,removed:array<int,array{tag:string,count:int}>}
	 */
	private function strip_raw_text_elements( $content ) {
		if ( '' === $content ) {
			return array(
				'content' => $content,
				'removed' => array(),
			);
		}

		$tag_alternation = implode(
			'|',
			array_map(
				static function ( $tag ) {
					return preg_quote( $tag, '/' );
				},
				self::$raw_text_elements
			)
		);
		$open_pattern = '/<\s*(' . $tag_alternation . ')\b[^>]*>/i';

		$counts = array();
		$result = '';
		$cursor = 0;
		$length = strlen( $content );

		while ( $cursor < $length && preg_match( $open_pattern, $content, $open_match, PREG_OFFSET_CAPTURE, $cursor ) ) {
			$open_start = $open_match[0][1];
			$open_end   = $open_start + strlen( $open_match[0][0] );
			$tag        = strtolower( $open_match[1][0] );

			// Untouched text before the opening tag.
			$result .= substr( $content, $cursor, $open_start - $cursor );

			$close_pattern = '/<\s*\/\s*' . preg_quote( $tag, '/' ) . '\b[^>]*>/i';

			if ( preg_match( $close_pattern, $content, $close_match, PREG_OFFSET_CAPTURE, $open_end ) ) {
				$cursor = $close_match[0][1] + strlen( $close_match[0][0] );
			} else {
				// No closing tag anywhere after this — the rest of the
				// document is still "inside" the element; drop it all.
				$cursor = $length;
			}

			$counts[ $tag ] = isset( $counts[ $tag ] ) ? $counts[ $tag ] + 1 : 1;
		}

		$result .= substr( $content, $cursor );

		$removed = array();
		foreach ( $counts as $tag => $count ) {
			$removed[] = array(
				'tag'   => $tag,
				'count' => $count,
			);
		}

		return array(
			'content' => $result,
			'removed' => $removed,
		);
	}

	/**
	 * Check whether content contains wrapper HTML tags that should never be injected.
	 *
	 * @param string $content Content to scan.
	 * @return bool True when wrapper tags are present.
	 */
	protected function contains_wrapper_html_tags( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}

		return (bool) preg_match( '/<\\/?\\s*(html|head|body)\\b/i', $content );
	}

	/**
	 * Strip wrapper HTML tags (<html>, <head>, <body>) while preserving inner markup.
	 *
	 * These tags are invalid in injected snippets (e.g. wp_body_open) and can break the page.
	 *
	 * @param string $content Content to sanitize.
	 * @return array{content:string,changed:bool} Sanitized content and whether it changed.
	 */
	protected function strip_wrapper_html_tags( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return array(
				'content' => is_string( $content ) ? $content : '',
				'changed' => false,
			);
		}

		$original = $content;
		$content  = preg_replace( '/<\\/?\\s*(html|head|body)\\b[^>]*>/i', '', $content );

		return array(
			'content' => is_string( $content ) ? $content : '',
			'changed' => $content !== $original,
		);
	}


	/**
	 * Sanitize post data array.
	 *
	 * @param array $data Raw post data.
	 * @return array Sanitized data.
	 */
	protected function sanitize_post_data( $data ) {
		$sanitized = array();

		if ( isset( $data['title'] ) ) {
			$sanitized['title'] = sanitize_text_field( $data['title'] );
		}

		if ( isset( $data['content'] ) ) {
			$sanitized['content'] = wp_kses_post( $data['content'] );
		}

		if ( isset( $data['excerpt'] ) ) {
			$sanitized['excerpt'] = sanitize_textarea_field( $data['excerpt'] );
		}

		if ( isset( $data['status'] ) ) {
			$sanitized['status'] = sanitize_key( $data['status'] );
		}

		if ( isset( $data['slug'] ) ) {
			$sanitized['slug'] = sanitize_title( $data['slug'] );
		}

		if ( isset( $data['featured_image'] ) ) {
			$sanitized['featured_image'] = absint( $data['featured_image'] );
		}

		if ( isset( $data['categories'] ) ) {
			$sanitized['categories'] = array_map( 'absint', (array) $data['categories'] );
		}

		if ( isset( $data['tags'] ) ) {
			$sanitized['tags'] = array_map( 'sanitize_text_field', (array) $data['tags'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize page data array.
	 *
	 * @param array $data Raw page data.
	 * @return array Sanitized data.
	 */
	protected function sanitize_page_data( $data ) {
		$sanitized = $this->sanitize_post_data( $data );

		if ( isset( $data['parent'] ) ) {
			$sanitized['parent'] = absint( $data['parent'] );
		}

		if ( isset( $data['menu_order'] ) ) {
			$sanitized['menu_order'] = absint( $data['menu_order'] );
		}

		if ( isset( $data['template'] ) ) {
			$sanitized['template'] = sanitize_text_field( $data['template'] );
		}

		return $sanitized;
	}

	/**
	 * Sanitize query arguments.
	 *
	 * @param array $args Raw arguments.
	 * @return array Sanitized arguments.
	 */
	protected function sanitize_query_args( $args ) {
		$sanitized = array();

		if ( isset( $args['per_page'] ) ) {
			$sanitized['posts_per_page'] = min( absint( $args['per_page'] ), 100 );
		}

		if ( isset( $args['page'] ) ) {
			$sanitized['paged'] = max( 1, absint( $args['page'] ) );
		}

		if ( isset( $args['status'] ) ) {
			$valid_statuses = array( 'publish', 'draft', 'pending', 'private', 'any' );
			$status = sanitize_key( $args['status'] );
			if ( in_array( $status, $valid_statuses, true ) ) {
				$sanitized['post_status'] = $status;
			}
		}

		if ( isset( $args['orderby'] ) ) {
			$valid_orderby = array( 'date', 'modified', 'title', 'ID', 'menu_order', 'rand' );
			$orderby = sanitize_key( $args['orderby'] );
			if ( in_array( $orderby, $valid_orderby, true ) ) {
				$sanitized['orderby'] = $orderby;
			}
		}

		if ( isset( $args['order'] ) ) {
			$sanitized['order'] = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';
		}

		if ( isset( $args['category'] ) ) {
			$sanitized['cat'] = absint( $args['category'] );
		}

		if ( isset( $args['search'] ) ) {
			$sanitized['s'] = sanitize_text_field( $args['search'] );

			// WP_Query searches post_title, post_excerpt and post_content —
			// never post_name. A caller checking "does a page with this slug
			// already exist?" got an empty result and created a duplicate
			// (#785). Opt simple single-token searches into slug matching.
			if ( class_exists( 'Mcpwp_Slug_Search' )
				&& Mcpwp_Slug_Search::should_match_slug( $sanitized['s'] ) ) {
				$sanitized[ Mcpwp_Slug_Search::QUERY_VAR ] = true;
			}
		}

		if ( isset( $args['post_type'] ) ) {
			$sanitized['post_type'] = sanitize_key( $args['post_type'] );
		}

		if ( isset( $args['ids'] ) && '' !== $args['ids'] ) {
			$sanitized['post__in'] = array_filter( array_map( 'absint', explode( ',', (string) $args['ids'] ) ) );
		}

		if ( isset( $args['fields'] ) && '' !== $args['fields'] ) {
			$sanitized['_mcpwp_fields'] = array_map( 'sanitize_key', explode( ',', (string) $args['fields'] ) );
		}

		// Security clamp: a caller without non-public privilege (read-only key)
		// may only ever enumerate published content. Force post_status='publish'
		// here — the single choke point every list surface built on
		// sanitize_query_args() flows through (pages, posts, Elementor Pro custom
		// code). This overrides BOTH a caller-supplied draft/private/pending/any
		// status AND any non-public model default (the pages model defaults to
		// 'any'), so no such list can leak unpublished titles, URLs, or bodies to
		// an unprivileged key. Write/admin keys and trusted admin-AJAX are
		// unaffected and keep their requested (or default) status set.
		if ( ! ( method_exists( $this, 'requester_can_see_non_public' ) && $this->requester_can_see_non_public() ) ) {
			$sanitized['post_status'] = 'publish';
		}

		return $sanitized;
	}

	/**
	 * Validate post status.
	 *
	 * @param string $status Status to validate.
	 * @param array  $allowed Allowed statuses.
	 * @return string Valid status or default.
	 */
	protected function validate_post_status( $status, $allowed = null ) {
		if ( null === $allowed ) {
			$allowed = array( 'publish', 'draft', 'pending', 'private' );
		}

		$status = sanitize_key( $status );

		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/**
	 * Validate and sanitize URL.
	 *
	 * @param string $url URL to validate.
	 * @return string|false Sanitized URL or false.
	 */
	protected function validate_url( $url ) {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return false;
		}

		// Check for valid scheme
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return false;
		}

		return $url;
	}

	/**
	 * Sanitize JSON string.
	 *
	 * @param string $json JSON string.
	 * @return string|false Sanitized JSON or false on error.
	 */
	protected function sanitize_json( $json ) {
		if ( ! is_string( $json ) ) {
			return false;
		}

		// Try to decode and re-encode to validate
		$decoded = json_decode( $json, true );

		if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
			return false;
		}

		return wp_json_encode( $decoded );
	}

	/**
	 * Normalize a bulk-operation item list into per-item arrays.
	 *
	 * The MCP tool schemas used to advertise these parameters as a bare array
	 * with no item type (#707), so clients reasonably sent an array of JSON
	 * *strings*. Reading `$item['id']` off a string yields nothing, every item
	 * failed its required-field check, and the caller got "0 updated" with an
	 * error that described the wrong problem.
	 *
	 * The schemas now declare object items. This stays as the honest fallback:
	 * decode what is decodable, and for anything else say what was actually
	 * received instead of blaming a missing field.
	 *
	 * @param array  $items    Raw items from the request.
	 * @param string $singular Item label for error messages, e.g. 'page'.
	 * @return array Two keys: 'items' (index-preserving array of arrays) and
	 *               'errors' (list of array{index:int,message:string}).
	 */
	protected function normalize_bulk_items( $items, $singular ) {
		$normalized = array();
		$errors     = array();

		foreach ( (array) $items as $index => $item ) {
			if ( is_array( $item ) ) {
				$normalized[ $index ] = $item;
				continue;
			}

			if ( is_object( $item ) ) {
				$normalized[ $index ] = (array) $item;
				continue;
			}

			if ( is_string( $item ) ) {
				$decoded = json_decode( $item, true );

				if ( is_array( $decoded ) ) {
					$normalized[ $index ] = $decoded;
					continue;
				}
			}

			$errors[] = array(
				'index'   => $index,
				'message' => sprintf(
					/* translators: 1: item label e.g. "page", 2: PHP type received. */
					__( 'Each %1$s must be an object; received %2$s.', 'mcpwp' ),
					$singular,
					gettype( $item )
				),
			);
		}

		return array(
			'items'  => $normalized,
			'errors' => $errors,
		);
	}

	/**
	 * Sanitize filename.
	 *
	 * @param string $filename Filename.
	 * @return string Sanitized filename.
	 */
	protected function sanitize_filename( $filename ) {
		// Remove path components
		$filename = basename( $filename );

		// Use WordPress sanitization
		return sanitize_file_name( $filename );
	}
}
