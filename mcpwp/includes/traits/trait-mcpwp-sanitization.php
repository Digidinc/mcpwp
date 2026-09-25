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
	 * Maximum number of strip passes {@see strip_raw_text_elements()} runs
	 * over one string. A reassembly trick — e.g.
	 * `<scr<script>x</script>ipt>alert(1)</scr<script></script>ipt>` — can
	 * leave a genuine `<script>...</script>` pair assembled from the debris
	 * of two decoys that a single pass correctly removed; re-scanning the
	 * *output* of each pass catches that. Bounded so a pathological input
	 * cannot loop unboundedly; in practice real content stabilizes in one or
	 * two passes.
	 *
	 * @var int
	 */
	private static $raw_text_strip_max_passes = 10;

	/**
	 * Sanitize a post/page/attachment content body for a write.
	 *
	 * Replaces a bare `wp_kses_post( $content )` call. Before kses ever sees
	 * the string, `<script>`/`<style>` elements are removed whole — tag and
	 * body together — so their source code can never survive as visible page
	 * text (#808). kses still runs afterwards for every other disallowed tag,
	 * unchanged from today's behavior.
	 *
	 * Deliberately unconditional — no `unfiltered_html` bypass. Every API key
	 * runs as the shared `mcpwp_api_agent` user, the chat auth bridge runs as
	 * a real admin, and the Elementor writer grants that user a scoped
	 * `unfiltered_html` during its own saves, so exempting the capability
	 * here would let an MCP-driven write store a raw `<script>` where it
	 * could not before kses ran unconditionally on this path. That is a wider
	 * stored-XSS surface, not a fix for #808.
	 *
	 * Non-string input is handled the way a bare `wp_kses_post( $content )`
	 * call always was: a scalar (int/float/bool) is cast to a string first —
	 * the implicit coercion PHP's string functions already performed inside
	 * wp_kses_post() — and then goes through the same strip-then-kses pass as
	 * any other string. An array or object never went through any
	 * string-shaped logic before this fix either; it is still handed straight
	 * to wp_kses_post() so a caller that (mistakenly, or via a bulk payload)
	 * sends a non-scalar `content` gets exactly what main did with it, not a
	 * blanked page (a prior version of this fix turned any non-string into
	 * `''`, which is what let `wp_bulk_update_pages` blank a page whose
	 * content happened to arrive as something other than a string).
	 *
	 * @param mixed $content Raw content as submitted by the caller.
	 * @return array{content:string,removed:array<int,array{tag:string,count:int}>}
	 *               Sanitized content and a report of whole elements removed.
	 */
	protected function sanitize_content_body( $content ) {
		if ( ! is_string( $content ) ) {
			if ( is_scalar( $content ) ) {
				$content = (string) $content;
			} else {
				return array(
					'content' => wp_kses_post( $content ),
					'removed' => array(),
				);
			}
		}

		$counts   = array();
		$previous = null;

		for ( $pass = 0; $pass < self::$raw_text_strip_max_passes && $content !== $previous; $pass++ ) {
			$previous = $content;
			$stripped = $this->strip_raw_text_elements( $content );
			$content  = $stripped['content'];

			foreach ( $stripped['removed'] as $entry ) {
				$counts[ $entry['tag'] ] = ( isset( $counts[ $entry['tag'] ] ) ? $counts[ $entry['tag'] ] : 0 ) + $entry['count'];
			}
		}

		$removed = array();
		foreach ( $counts as $tag => $count ) {
			$removed[] = array(
				'tag'   => $tag,
				'count' => $count,
			);
		}

		return array(
			'content' => wp_kses_post( $content ),
			'removed' => $removed,
		);
	}

	/**
	 * Remove `<script>`/`<style>` elements — opening tag, body, and closing
	 * tag together — from one pass over $content.
	 *
	 * This is a small hand-rolled tokenizer, not a single regex, because a
	 * regex that matches "`<script`...`</script>`" greedily-but-lazily is
	 * exactly what makes this bug class exploitable: it cannot tell a real
	 * tag boundary from text that merely contains the same characters. Every
	 * rule below exists to fix one proven false-positive that made an
	 * earlier, regex-only version of this method eat real page content:
	 *
	 * - A tag name must start *immediately* after `<` (no `<\s*name`) and end
	 *   at whitespace, `/`, or `>` — never at `-` or another word character.
	 *   Without this, `<style-guide>` and `< style tags sparingly` were both
	 *   misread as the start of a `<style>` element.
	 * - HTML comments (`<!-- ... -->`) and CDATA sections (`<![CDATA[ ... ]]>`)
	 *   are skipped as opaque spans. Without this, `<!-- <style> -->` and
	 *   `<![CDATA[<script>]]>` were misread as containing a real tag.
	 * - Quoted attribute values are skipped as opaque spans while looking for
	 *   the `>` that ends a tag. Without this, `<p title="<style>">` had its
	 *   attribute value misread as a real `<style>` tag.
	 * - When a real `<script>`/`<style>` open tag has NO matching close tag
	 *   anywhere in the rest of the string, only the open tag itself is
	 *   removed — never the remainder of the document. An earlier version of
	 *   this method removed to the end of the string on the theory that a
	 *   browser's raw-text parser would do the same; in practice a false
	 *   "open tag" produced by any of the misreads above (or simply a stray
	 *   `<script` with no real body) turned into wholesale data loss on
	 *   unrelated, later content. kses then treats the remaining bare tag
	 *   exactly as it did before this fix (its own existing behavior, not
	 *   something introduced here).
	 *
	 * Once a genuine `<script>`/`<style>` open tag is found, the matching
	 * close tag is found the way a browser's tokenizer finds it for a
	 * raw-text element: by literal, case-insensitive text search for
	 * `</name` followed by whitespace, `/`, or `>` — comments, CDATA and
	 * quotes are NOT recognized inside a raw-text element's body, because
	 * they are not recognized there either.
	 *
	 * Callers loop this method ({@see sanitize_content_body()}) to catch a
	 * reassembly split across two decoy elements.
	 *
	 * @param string $content Content to scan (already a string).
	 * @return array{content:string,removed:array<int,array{tag:string,count:int}>}
	 */
	private function strip_raw_text_elements( $content ) {
		if ( '' === $content ) {
			return array(
				'content' => $content,
				'removed' => array(),
			);
		}

		$counts = array();
		$result = '';
		$cursor = 0;
		$length = strlen( $content );

		while ( $cursor < $length ) {
			$lt = strpos( $content, '<', $cursor );

			if ( false === $lt ) {
				$result .= substr( $content, $cursor );
				break;
			}

			// Plain text before this '<'.
			$result .= substr( $content, $cursor, $lt - $cursor );

			// HTML comment — copied through verbatim, never scanned for tags.
			if ( '<!--' === substr( $content, $lt, 4 ) ) {
				$end = strpos( $content, '-->', $lt + 4 );
				if ( false === $end ) {
					$result .= substr( $content, $lt );
					break;
				}
				$end     = $end + 3;
				$result .= substr( $content, $lt, $end - $lt );
				$cursor  = $end;
				continue;
			}

			// CDATA section — same treatment.
			if ( '<![CDATA[' === substr( $content, $lt, 9 ) ) {
				$end = strpos( $content, ']]>', $lt + 9 );
				if ( false === $end ) {
					$result .= substr( $content, $lt );
					break;
				}
				$end     = $end + 3;
				$result .= substr( $content, $lt, $end - $lt );
				$cursor  = $end;
				continue;
			}

			// A real tag name starts immediately after '<' (optionally '/'
			// for a close tag) — not after whitespace, which is just text.
			if ( ! preg_match( '/\G<(\/?)([a-zA-Z][a-zA-Z0-9]*)/', $content, $tag_match, 0, $lt ) ) {
				// '<' not followed by a valid tag name start — plain text.
				$result .= '<';
				$cursor  = $lt + 1;
				continue;
			}

			$is_close  = '/' === $tag_match[1];
			$tag_name  = strtolower( $tag_match[2] );
			$name_end  = $lt + strlen( $tag_match[0] );
			$boundary  = $name_end < $length ? $content[ $name_end ] : '>';

			// The character right after the name must end it (whitespace,
			// '/', or '>'); anything else (e.g. '-') means this is a longer,
			// unrelated name such as a custom element and not a real match.
			if ( ! preg_match( '/[\s\/>]/', $boundary ) ) {
				$result .= '<';
				$cursor  = $lt + 1;
				continue;
			}

			$tag_end = $this->find_unquoted_gt( $content, $name_end );

			if ( false === $tag_end ) {
				// Unterminated tag ('<' with no closing '>' anywhere) — copy
				// the rest through verbatim; there is nothing left to parse.
				$result .= substr( $content, $lt );
				break;
			}

			if ( ! $is_close && in_array( $tag_name, self::$raw_text_elements, true ) ) {
				$close = $this->find_raw_text_close( $content, $tag_end + 1, $tag_name );

				$counts[ $tag_name ] = ( isset( $counts[ $tag_name ] ) ? $counts[ $tag_name ] : 0 ) + 1;

				if ( false !== $close ) {
					// A real matching close tag: drop tag + body + close tag.
					$cursor = $close;
				} else {
					// No matching close tag anywhere: drop ONLY the open tag.
					// Whatever follows is left for kses, same as before this
					// fix — never removed as a guess.
					$cursor = $tag_end + 1;
				}
				continue;
			}

			// Any other tag (including a close tag with no matching open, or
			// script/style's own close tag reached without ever finding an
			// unmatched open — both handled above) passes through untouched;
			// kses decides its fate exactly as it did before this fix.
			$result .= substr( $content, $lt, $tag_end - $lt + 1 );
			$cursor  = $tag_end + 1;
		}

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
	 * Find the '>' that ends a tag starting at $from, skipping over any
	 * single- or double-quoted attribute value along the way so a literal
	 * '>' (or, via the caller, a literal "<style>") inside a quoted value is
	 * never mistaken for the tag boundary.
	 *
	 * @param string $content Content to scan.
	 * @param int    $from    Offset to start scanning from.
	 * @return int|false Offset of the terminating '>', or false if none.
	 */
	private function find_unquoted_gt( $content, $from ) {
		$length    = strlen( $content );
		$in_quote  = null;

		for ( $i = $from; $i < $length; $i++ ) {
			$char = $content[ $i ];

			if ( null !== $in_quote ) {
				if ( $char === $in_quote ) {
					$in_quote = null;
				}
				continue;
			}

			if ( '"' === $char || "'" === $char ) {
				$in_quote = $char;
				continue;
			}

			if ( '>' === $char ) {
				return $i;
			}
		}

		return false;
	}

	/**
	 * Find the end of the matching close tag for a raw-text element
	 * (`</script`/`</style`), the way a browser's tokenizer does: a literal,
	 * case-insensitive search for "</name" followed by whitespace, '/', or
	 * '>' — nothing inside a raw-text element's body (comments, CDATA,
	 * quotes, other tags) is recognized as anything but more raw text.
	 *
	 * @param string $content Content to scan.
	 * @param int    $from    Offset to start scanning from (just after the
	 *                        element's open tag).
	 * @param string $tag_name Lowercase element name, e.g. 'script'.
	 * @return int|false Offset one past the close tag's '>', or false if no
	 *                    real close tag exists anywhere after $from.
	 */
	private function find_raw_text_close( $content, $from, $tag_name ) {
		$length = strlen( $content );
		$needle = '</' . $tag_name;
		$pos    = $from;

		while ( $pos < $length ) {
			$found = stripos( $content, $needle, $pos );

			if ( false === $found ) {
				return false;
			}

			$after_idx  = $found + strlen( $needle );
			$after_char = $after_idx < $length ? $content[ $after_idx ] : '>';

			if ( preg_match( '/[\s\/>]/', $after_char ) ) {
				$close_gt = strpos( $content, '>', $after_idx );

				if ( false === $close_gt ) {
					return false;
				}

				return $close_gt + 1;
			}

			// A false match, e.g. "</scripting" — keep looking.
			$pos = $after_idx;
		}

		return false;
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
