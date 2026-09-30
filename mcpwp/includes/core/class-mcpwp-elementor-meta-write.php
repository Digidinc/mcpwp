<?php
/**
 * Elementor meta writes that never re-sanitize what the caller did not change.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one chokepoint for every `_elementor_data` / `_elementor_page_settings`
 * write made during an MCPWP request (#829).
 *
 * The defect. Elementor 4.2+ registers both keys for REST (modules/wp-rest/
 * classes/elementor-post-meta.php, on rest_api_init) with a sanitize_callback
 * that, for a user without `unfiltered_html`, runs wp_kses_post() over EVERY
 * string in the value. update_metadata() and add_metadata() call
 * sanitize_meta() on every write, and the mcpwp_api_agent role never has
 * `unfiltered_html` — so each MCPWP write re-sanitized the whole document, not
 * only the part the caller edited. One wp_edit_widget on a heading removed the
 * `<style>` of an unrelated html widget, left a `<script>`'s body behind as
 * visible text and deleted an inline `<svg>`; production lost its PostHog
 * snippet and its logo that way.
 *
 * The fix, at the only place every write passes through whoever makes it —
 * MCPWP's own update_post_meta() calls, `meta_input` on wp_insert_post(),
 * add_post_meta() in a clone, and Elementor's own Document::save():
 *
 * 1. `register_meta_args` wraps the key's sanitize_callback. Outside an MCPWP
 *    REST request the wrapper is a plain pass-through to Elementor's callback.
 *    Inside one, it runs that callback exactly as before — with MCPWP's own
 *    scoped `unfiltered_html` grants (#769/#772) suspended, so the callback
 *    judges the caller's real capability — and remembers what it was given
 *    and what it returned.
 * 2. `update_post_metadata` / `add_post_metadata` (which, unlike the sanitize
 *    filter, know the post) then merge the two, string by string:
 *      - a string the sanitizer left alone is stored as sent;
 *      - a string the sanitizer would change, but which is already stored on
 *        this post (anywhere in that meta value) or in a declared source
 *        (with_sources(): the page being cloned, the template being applied,
 *        the snapshot being restored), is NOT the caller's new content — it is
 *        what the site already holds — and is stored byte-for-byte as it was;
 *      - any other string the sanitizer changed is content this caller
 *        introduced, and gets the sanitizer's output: exactly what main
 *        stored for it.
 *    When the merge differs from the sanitizer's output the write is redone
 *    with the merged value, sanitizer bypassed for that one nested write.
 *
 * What a key may introduce is therefore unchanged: whatever Elementor's
 * sanitizer allows for the caller's real capability (for mcpwp_api_agent, what
 * wp_kses_post() keeps). What changes is that content the caller did not
 * introduce is no longer re-sanitized on the way through.
 *
 * Why the grants are suspended, and why the baseline is read per write: on a
 * normal write MCPWP calls Document::save() under the #772 grant, and
 * Elementor's save writes `_elementor_data` itself before MCPWP overwrites
 * it. Were the callback to see the grant, that inner write would store the
 * caller's raw bytes, and the overwrite would then find them "already stored"
 * and keep them — widening what a key may store. Judged on the real
 * capability, every stored state is already within policy, so "already on
 * this post" can never launder a string the caller just sent.
 */
final class Mcpwp_Elementor_Meta_Write {

	/**
	 * Keys Elementor stores element trees and document settings under.
	 */
	const KEYS = array( '_elementor_data', '_elementor_page_settings' );

	/**
	 * Sanitizer input/output awaiting the metadata filter that knows the post.
	 *
	 * @var array{key:string,intended:mixed,sanitized:mixed}|null
	 */
	private static $pending = null;

	/**
	 * Depth of MCPWP's own merged re-writes; the sanitizer is skipped inside.
	 *
	 * @var int
	 */
	private static $bypass = 0;

	/**
	 * Depth of sanitize_callback runs during which MCPWP's grants are off.
	 *
	 * @var int
	 */
	private static $suspend_grants = 0;

	/**
	 * Stack of string sets from declared sources (with_sources()).
	 *
	 * @var array<int,array<string,bool>>
	 */
	private static $sources = array();

	/**
	 * Hook the chokepoint. Must run before rest_api_init, where Elementor
	 * registers its meta.
	 *
	 * @return void
	 */
	public static function boot() {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter( 'register_meta_args', array( __CLASS__, 'wrap_meta_args' ), PHP_INT_MAX, 4 );
		add_filter( 'update_post_metadata', array( __CLASS__, 'on_update_post_metadata' ), PHP_INT_MAX, 5 );
		add_filter( 'add_post_metadata', array( __CLASS__, 'on_add_post_metadata' ), PHP_INT_MAX, 5 );
	}

	/**
	 * Whether MCPWP's scoped `unfiltered_html` grants must stand aside.
	 *
	 * True only while a wrapped sanitize_callback runs inside an MCPWP request,
	 * so the callback sees the caller's own capability.
	 *
	 * @return bool
	 */
	public static function grants_suspended() {
		return self::$suspend_grants > 0;
	}

	/**
	 * Run $fn with the strings of $sources counted as already on the site.
	 *
	 * For writes that copy content the site already holds from somewhere
	 * other than the post being written: a clone's source page, an applied
	 * template or part, a restored snapshot, the section a template is made
	 * from. Each source is a post ID (both Elementor keys of that post are
	 * read) or a value (a tree, a JSON string, or a plain string).
	 *
	 * @param array    $sources Post IDs and/or values.
	 * @param callable $fn      The write.
	 * @return mixed What $fn returned.
	 */
	public static function with_sources( array $sources, callable $fn ) {
		$set = array();

		foreach ( $sources as $source ) {
			if ( is_int( $source ) ) {
				if ( $source > 0 ) {
					foreach ( self::KEYS as $key ) {
						self::collect_strings( get_post_meta( $source, $key, true ), $set );
					}
				}
				continue;
			}

			self::collect_strings( $source, $set );
		}

		self::$sources[] = $set;

		try {
			return $fn();
		} finally {
			array_pop( self::$sources );
		}
	}

	/**
	 * `register_meta_args`: wrap the sanitize_callback of an Elementor key.
	 *
	 * @param array  $args        Registration args.
	 * @param array  $defaults    Defaults (unused).
	 * @param string $object_type Object type.
	 * @param string $meta_key    Meta key.
	 * @return array
	 */
	public static function wrap_meta_args( $args, $defaults = array(), $object_type = '', $meta_key = '' ) {
		if ( ! is_array( $args ) || 'post' !== $object_type || ! in_array( $meta_key, self::KEYS, true ) ) {
			return $args;
		}

		if ( empty( $args['sanitize_callback'] ) || ! is_callable( $args['sanitize_callback'] ) ) {
			return $args;
		}

		$inner = $args['sanitize_callback'];

		$args['sanitize_callback'] = static function ( $value, $key = '', $type = '', $subtype = '' ) use ( $inner ) {
			return Mcpwp_Elementor_Meta_Write::sanitize( $inner, $value, $key, $type, $subtype );
		};

		return $args;
	}

	/**
	 * The wrapped sanitize_callback.
	 *
	 * @param callable $inner   The registered callback (Elementor's).
	 * @param mixed    $value   Meta value, unslashed.
	 * @param string   $key     Meta key.
	 * @param string   $type    Object type.
	 * @param string   $subtype Object subtype.
	 * @return mixed
	 */
	public static function sanitize( $inner, $value, $key = '', $type = '', $subtype = '' ) {
		if ( self::$bypass > 0 ) {
			// MCPWP's own re-write of a value this class already merged.
			return $value;
		}

		if ( ! self::in_scope() ) {
			return call_user_func( $inner, $value, $key, $type, $subtype );
		}

		++self::$suspend_grants;
		try {
			$sanitized = call_user_func( $inner, $value, $key, $type, $subtype );
		} finally {
			--self::$suspend_grants;
		}

		// A later link of the same sanitize chain — the key registered more
		// than once (Elementor registers on every rest_api_init), or another
		// wrapped callback — is handed the previous link's output. What the
		// caller sent is still what the first link was given.
		$intended = $value;
		$prior    = self::$pending;
		if ( null !== $prior && $prior['key'] === (string) $key && $prior['sanitized'] === $value ) {
			$intended = $prior['intended'];
		}

		self::$pending = ( $sanitized === $intended ) ? null : array(
			'key'       => (string) $key,
			'intended'  => $intended,
			'sanitized' => $sanitized,
		);

		return $sanitized;
	}

	/**
	 * `update_post_metadata`: store the merge instead of the whole-document
	 * sanitizer output.
	 *
	 * @param mixed  $check      Short-circuit value from earlier filters.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Sanitized value core is about to store.
	 * @param mixed  $prev_value Previous-value condition.
	 * @return mixed
	 */
	public static function on_update_post_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		$merged = self::merged_value( $check, $object_id, $meta_key, $meta_value );
		if ( null === $merged ) {
			return $check;
		}

		++self::$bypass;
		try {
			return update_metadata( 'post', $object_id, $meta_key, wp_slash( $merged['value'] ), $prev_value );
		} finally {
			--self::$bypass;
		}
	}

	/**
	 * `add_post_metadata`: as on_update_post_metadata(), for a first write.
	 *
	 * @param mixed  $check      Short-circuit value from earlier filters.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Sanitized value core is about to store.
	 * @param bool   $unique     Unique flag.
	 * @return mixed
	 */
	public static function on_add_post_metadata( $check, $object_id, $meta_key, $meta_value, $unique = false ) {
		$merged = self::merged_value( $check, $object_id, $meta_key, $meta_value );
		if ( null === $merged ) {
			return $check;
		}

		++self::$bypass;
		try {
			return add_metadata( 'post', $object_id, $meta_key, wp_slash( $merged['value'] ), $unique );
		} finally {
			--self::$bypass;
		}
	}

	/**
	 * The value to store instead of core's, or null to let core proceed.
	 *
	 * @param mixed  $check      Short-circuit value from earlier filters.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value core is about to store.
	 * @return array{value:mixed}|null
	 */
	private static function merged_value( $check, $object_id, $meta_key, $meta_value ) {
		$pending       = self::$pending;
		self::$pending = null;

		if ( null !== $check || null === $pending || self::$bypass > 0 ) {
			return null;
		}

		// Only the write this sanitizer run belongs to: another filter that
		// changed the value after the callback, or a sanitize_meta() call
		// with no write behind it, leaves core's value alone.
		if ( $pending['key'] !== (string) $meta_key || $pending['sanitized'] !== $meta_value ) {
			return null;
		}

		$known = array();
		self::collect_strings( get_post_meta( (int) $object_id, (string) $meta_key, true ), $known );
		foreach ( self::$sources as $set ) {
			$known += $set;
		}

		$counts = array(
			'kept'      => 0,
			'sanitized' => 0,
		);
		$value  = self::merge_value( $pending['intended'], $pending['sanitized'], $known, $counts );

		if ( $value === $pending['sanitized'] ) {
			return null;
		}

		return array( 'value' => $value );
	}

	/**
	 * Merge the caller's value with the sanitizer's, string by string.
	 *
	 * @param mixed               $intended  What the caller wrote.
	 * @param mixed               $sanitized What the sanitizer returned.
	 * @param array<string,bool>  $known     Strings already on the site.
	 * @param array               $counts    kept/sanitized tallies, by reference.
	 * @return mixed
	 */
	private static function merge_value( $intended, $sanitized, array $known, array &$counts ) {
		if ( is_string( $intended ) && is_string( $sanitized ) ) {
			$intended_tree  = json_decode( $intended );
			$sanitized_tree = json_decode( $sanitized );

			if ( ( is_array( $intended_tree ) || is_object( $intended_tree ) ) && ( is_array( $sanitized_tree ) || is_object( $sanitized_tree ) ) ) {
				$merged = self::merge_node( $intended_tree, $sanitized_tree, $known, $counts );

				if ( $merged == $intended_tree && 0 === $counts['sanitized'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- structural equality of decoded trees.
					// Nothing the caller introduced needed sanitizing: the
					// caller's own bytes, not a re-encoding of them.
					return $intended;
				}

				$encoded = self::encode_like( $merged, $intended );

				return false === $encoded ? $sanitized : $encoded;
			}
		}

		return self::merge_node( $intended, $sanitized, $known, $counts );
	}

	/**
	 * Merge one node of two trees of the same shape.
	 *
	 * A shape the sanitizer changed (other than strings) is taken from the
	 * sanitizer: nothing here can say what it meant.
	 *
	 * @param mixed              $intended  Caller's node.
	 * @param mixed              $sanitized Sanitizer's node.
	 * @param array<string,bool> $known     Strings already on the site.
	 * @param array              $counts    kept/sanitized tallies, by reference.
	 * @return mixed
	 */
	private static function merge_node( $intended, $sanitized, array $known, array &$counts ) {
		if ( is_string( $intended ) && is_string( $sanitized ) ) {
			if ( $intended === $sanitized ) {
				return $intended;
			}

			if ( isset( $known[ $intended ] ) ) {
				++$counts['kept'];

				return $intended;
			}

			++$counts['sanitized'];

			return $sanitized;
		}

		if ( is_array( $intended ) && is_array( $sanitized ) ) {
			if ( array_keys( $intended ) !== array_keys( $sanitized ) ) {
				return $sanitized;
			}

			$out = array();
			foreach ( $intended as $k => $v ) {
				$out[ $k ] = self::merge_node( $v, $sanitized[ $k ], $known, $counts );
			}

			return $out;
		}

		// Elementor re-encodes from an associative decode, so an empty `{}`
		// comes back as `[]`: the same value, and not the caller's bytes.
		if ( is_object( $intended ) && array() === $sanitized && array() === get_object_vars( $intended ) ) {
			return $intended;
		}

		if ( is_object( $intended ) && is_object( $sanitized ) ) {
			$intended_vars  = get_object_vars( $intended );
			$sanitized_vars = get_object_vars( $sanitized );

			if ( array_keys( $intended_vars ) !== array_keys( $sanitized_vars ) ) {
				return $sanitized;
			}

			$out = new \stdClass();
			foreach ( $intended_vars as $k => $v ) {
				$out->{$k} = self::merge_node( $v, $sanitized_vars[ $k ], $known, $counts );
			}

			return $out;
		}

		return $sanitized;
	}

	/**
	 * Encode a merged tree with the JSON flags the caller's text was written in,
	 * so untouched elements keep their bytes.
	 *
	 * @param mixed  $tree     Merged tree (objects kept as objects).
	 * @param string $intended The caller's JSON text.
	 * @return string|false
	 */
	private static function encode_like( $tree, $intended ) {
		$decoded = json_decode( $intended );
		$chosen  = 0;

		foreach ( array( 0, JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) as $flags ) {
			if ( wp_json_encode( $decoded, $flags ) === $intended ) {
				$chosen = $flags;
				break;
			}
		}

		return wp_json_encode( $tree, $chosen );
	}

	/**
	 * Add every string in a meta value to $set: the value itself, its leaves,
	 * and the leaves of a JSON document stored as text.
	 *
	 * @param mixed              $value Meta value.
	 * @param array<string,bool> $set   Set, by reference.
	 * @return void
	 */
	private static function collect_strings( $value, array &$set ) {
		if ( is_string( $value ) ) {
			$set[ $value ] = true;

			$first = ltrim( $value );
			if ( '' !== $first && ( '[' === $first[0] || '{' === $first[0] ) ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					self::collect_strings( $decoded, $set );
				}
			}

			return;
		}

		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( is_string( $v ) ) {
					$set[ $v ] = true;
				} elseif ( is_array( $v ) || is_object( $v ) ) {
					self::collect_strings( $v, $set );
				}
			}
		}
	}

	/**
	 * Whether this request is MCPWP's: the REST route is under /mcpwp/.
	 *
	 * Elementor's own editor and other REST clients keep Elementor's behaviour
	 * untouched.
	 *
	 * @return bool
	 */
	private static function in_scope() {
		$route = '';

		if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && isset( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
			$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
		}

		return 0 === strpos( '/' . ltrim( $route, '/' ), '/mcpwp/' );
	}
}
