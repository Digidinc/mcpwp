<?php
/**
 * The one definition of how Elementor page settings are read, written and
 * carried between a template and a page.
 *
 * `_elementor_page_settings` holds the document-level half of an Elementor
 * page: which page template it uses, whether the title is hidden, the body
 * background, page padding, and the page's own custom CSS. A "full-page"
 * template that carries only `_elementor_data` carries the layout and loses
 * the document — apply it and the page comes out with the right sections and
 * the wrong frame (#638).
 *
 * Before this, the settings were readable (get responses exposed them) but not
 * writable through any create or update path, so a template could be built,
 * stored and read back with `page_settings: ""` while its source page had
 * them. The one write path that existed took a five-key allowlist and
 * reported `page_settings_updated` listing the keys the CALLER SENT — not the
 * keys it accepted — so a dropped key was reported as an applied one.
 *
 * Two rules follow from that, and they are why this class exists rather than a
 * sixth copy of the logic:
 *
 *   - A key that is not written is reported as skipped, by name. Silently
 *     dropping half a document and answering with the full list is the failure
 *     this whole issue family is about.
 *
 *   - Absent settings read back as null, never as an empty array. "This
 *     template has no page settings" and "this template was never asked" are
 *     different answers and the caller acts on them differently.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor document-level page settings.
 */
class Mcpwp_Elementor_Page_Settings {

	/**
	 * Meta key Elementor stores document settings under.
	 */
	const META_KEY = '_elementor_page_settings';

	/**
	 * How an apply treats the target's existing settings.
	 */
	const MODES = array( 'preserve', 'replace', 'merge' );

	/**
	 * Exact setting names this plugin will write.
	 *
	 * @var array
	 */
	private static $allowed_keys = array(
		// Document frame.
		'template',
		'hide_title',
		'post_status',
		// Page-level CSS.
		'custom_css',
		// Layout.
		'content_width',
		'section_gap',
		'padding',
		'margin',
		// Elementor's own page-settings toggles.
		'viewport_md',
		'viewport_lg',
	);

	/**
	 * Prefixes that admit a family of Elementor controls wholesale.
	 *
	 * Elementor generates these — `background_background`,
	 * `background_color`, `background_image`, `background_overlay_*`, and a
	 * responsive variant of nearly all of them. Enumerating them by hand is
	 * how the previous five-key allowlist ended up dropping most of a real
	 * document, so the families are admitted as families.
	 *
	 * @var array
	 */
	private static $allowed_prefixes = array(
		'background_',
		'padding_',
		'margin_',
		'content_width_',
		'section_gap_',
		'body_',
		'page_',
	);

	/**
	 * Read a post's page settings.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null Settings, or null when there are none / unreadable.
	 */
	public static function read( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META_KEY, true );

		if ( is_array( $raw ) ) {
			return empty( $raw ) ? null : $raw;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		// Unreadable is not empty. Returning array() here would let a caller
		// conclude the document has no settings when in fact this could not
		// read the ones it has — and then overwrite them.
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Whether a setting name is one this plugin will write.
	 *
	 * @param string $key Setting name.
	 * @return bool
	 */
	public static function is_allowed_key( $key ) {
		$key = (string) $key;

		if ( '' === $key ) {
			return false;
		}

		if ( in_array( $key, self::$allowed_keys, true ) ) {
			return true;
		}

		foreach ( self::$allowed_prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalise a submitted settings map into what will actually be stored.
	 *
	 * @param mixed $settings Submitted settings.
	 * @return array{settings:array,skipped:array} Accepted settings and the names of everything refused.
	 */
	public static function normalize( $settings ) {
		if ( ! is_array( $settings ) ) {
			return array(
				'settings' => array(),
				'skipped'  => array(),
			);
		}

		$accepted = array();
		$skipped  = array();

		foreach ( $settings as $key => $value ) {
			if ( ! self::is_allowed_key( $key ) ) {
				$skipped[] = (string) $key;
				continue;
			}

			// Elementor stores booleans, numbers, and nested arrays (a
			// dimensions control is {top,right,bottom,left,unit}). Coercing
			// everything through sanitize_text_field would flatten those into
			// strings and quietly change the document — type loss is
			// explicitly what the acceptance criteria rule out.
			$accepted[ $key ] = self::sanitize_value( $key, $value );
		}

		return array(
			'settings' => $accepted,
			'skipped'  => $skipped,
		);
	}

	/**
	 * Sanitize one setting value while preserving its type.
	 *
	 * @param string $key   Setting name.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	private static function sanitize_value( $key, $value ) {
		if ( is_array( $value ) ) {
			$out = array();

			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) ) {
					// NOT sanitize_key(): it lowercases, and Elementor's
					// controls use camelCase inner keys — `isLinked` on a
					// dimensions control, `isInner` on a container. Lowercasing
					// them silently changes the document into one Elementor
					// reads differently. Restrict the character set instead and
					// leave the case alone; a key that survives nothing is
					// dropped rather than mangled.
					$clean = preg_replace( '/[^A-Za-z0-9_\-]/', '', $k );

					if ( '' === $clean ) {
						continue;
					}

					$out[ $clean ] = self::sanitize_value( $key, $v );
					continue;
				}

				$out[ $k ] = self::sanitize_value( $key, $v );
			}

			return $out;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		$value = (string) $value;

		// custom_css is stylesheet text. sanitize_text_field() strips newlines
		// and angle brackets, which turns a working stylesheet into one line
		// of broken CSS — the setting this feature exists to carry would be
		// the one it destroys. Same treatment the kit-CSS surface already
		// gives it: stored as written, with no HTML smuggled through.
		if ( 'custom_css' === $key ) {
			return wp_strip_all_tags( $value );
		}

		return sanitize_textarea_field( $value );
	}

	/**
	 * Write page settings, replacing what is there.
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $settings Already-normalised settings.
	 * @return bool True when the stored value matches what was asked for.
	 */
	public static function write( $post_id, array $settings ) {
		$post_id = (int) $post_id;

		if ( empty( $settings ) ) {
			delete_post_meta( $post_id, self::META_KEY );

			return null === self::read( $post_id );
		}

		// An MCPWP write: unedited settings kept in place, the rest sanitized
		// for the acting principal (#829).
		$written = Mcpwp_Elementor_Meta_Write::update( $post_id, self::META_KEY, $settings );
		if ( is_wp_error( $written ) ) {
			return false;
		}

		// Read back rather than trusting update_post_meta()'s return, which is
		// false both when the write failed and when the value was already what
		// was asked for.
		$stored = self::read( $post_id );

		return is_array( $stored ) && $stored == $settings; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- meta round-trips ints as strings on some stores; equality of content is the property under test.
	}

	/**
	 * Carry a template's page settings onto a target page.
	 *
	 * @param int        $target_id Page receiving the template.
	 * @param array|null $incoming  The template's settings, or null when it has none.
	 * @param string     $mode      One of preserve|replace|merge.
	 * @return array Report of what happened.
	 */
	public static function apply( $target_id, $incoming, $mode = 'preserve' ) {
		$mode = is_string( $mode ) ? strtolower( trim( $mode ) ) : '';

		if ( ! in_array( $mode, self::MODES, true ) ) {
			$mode = 'preserve';
		}

		$existing = self::read( $target_id );

		$report = array(
			'page_settings_mode' => $mode,
			'applied'            => array(),
			'preserved'          => array(),
			'skipped'            => array(),
		);

		// Nothing to carry. Say so rather than reporting an empty apply, which
		// reads the same as an apply that did nothing wrong.
		if ( ! is_array( $incoming ) || empty( $incoming ) ) {
			$report['page_settings_source'] = 'template has no page settings';
			$report['preserved']            = is_array( $existing ) ? array_keys( $existing ) : array();

			return $report;
		}

		$normalized        = self::normalize( $incoming );
		$report['skipped'] = $normalized['skipped'];
		$incoming          = $normalized['settings'];

		if ( 'preserve' === $mode ) {
			// The default, and the safe one: the target's own document wins.
			// Only settings it does not have are taken from the template, so
			// applying a template never silently restyles a page's frame.
			$result = is_array( $existing ) ? $existing : array();

			foreach ( $incoming as $key => $value ) {
				if ( array_key_exists( $key, $result ) ) {
					$report['preserved'][] = $key;
					continue;
				}

				$result[ $key ]       = $value;
				$report['applied'][] = $key;
			}
		} elseif ( 'replace' === $mode ) {
			$result              = $incoming;
			$report['applied']   = array_keys( $incoming );
			$report['preserved'] = array();
		} else {
			// merge: the template wins per key, the target keeps everything
			// the template does not mention.
			$result            = array_merge( is_array( $existing ) ? $existing : array(), $incoming );
			$report['applied'] = array_keys( $incoming );

			if ( is_array( $existing ) ) {
				$report['preserved'] = array_values( array_diff( array_keys( $existing ), array_keys( $incoming ) ) );
			}
		}

		$report['written'] = self::write( $target_id, $result );

		if ( ! $report['written'] ) {
			$report['warning'] = 'The page settings could not be stored, so this page keeps the document settings it had. The layout was still applied.';
		}

		return $report;
	}
}
