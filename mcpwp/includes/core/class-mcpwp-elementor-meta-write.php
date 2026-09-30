<?php
/**
 * Elementor meta writes made by MCPWP: unedited content kept in place,
 * introduced content sanitized by MCPWP's own capability policy.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one chokepoint for every `_elementor_data` / `_elementor_page_settings`
 * write MCPWP makes (#829).
 *
 * The defect. Elementor 4.2+ registers both keys (modules/wp-rest/classes/
 * elementor-post-meta.php, on rest_api_init) with a sanitize_callback that, for
 * a user without `unfiltered_html`, runs wp_kses_post() over EVERY string in
 * the value, and update_metadata()/add_metadata() run it on every write. The
 * mcpwp_api_agent role never has `unfiltered_html`, so each MCPWP write
 * re-sanitized the whole document: one wp_edit_widget on a heading removed the
 * `<style>`, `<script>` and `<svg>` of every html widget on the page. And the
 * other half: Elementor registers that callback only for post types in
 * get_post_types_by_support( 'elementor' ), and not at all below 4.2, so on
 * any other type an API key's raw `<script>` was stored as sent.
 *
 * The policy, applied by MCPWP itself on every write it makes, whatever the
 * post type, Elementor version, or whether Elementor registered anything:
 *
 * - The acting principal holds `unfiltered_html` (checked with MCPWP's own
 *   scoped grants suspended): the value is stored as sent.
 * - Otherwise, per element, all or nothing:
 *     - an element whose every field and setting (`__dynamic__`, `__globals__`,
 *       repeater items included) is identical to an element of the same id,
 *       elType and widgetType stored on this post keeps its raw strings. Each
 *       stored element vouches for one copy: it may move within the post, not
 *       be duplicated raw. Page settings: per top-level key, the whole value;
 *     - the one other trusted source is a rollback snapshot of the same post,
 *       and only while that post still holds exactly what the logged write
 *       left (snapshot option; see Mcpwp_Action_Log::snapshot_is_trusted());
 *     - a path a caller explicitly declares raw (raw_paths option: the Kit's
 *       custom_css, which its routes validate) is stored as sent, unless it
 *       could end its <style> element (css_breaks_out());
 *     - every string of every other element goes through wp_kses_post(), as
 *       Elementor's own callback does.
 *   Nothing else counts as known: not another element, not another post, not
 *   a template, part or clone source. A copy made by a key without
 *   `unfiltered_html` is a sanitized copy.
 *
 * update() takes the value exactly as it is to be stored and slashes it for
 * update_post_meta() itself, so no caller can lose backslashes or have
 * `<\/style` unslashed into `</style` after a check.
 *
 * "MCPWP is the writer" is a context its own write functions set around the
 * write (update(), as_writer()), scoped to a post ID — not the REST route, so
 * WebMCP, admin-AJAX chat, cron and WP-CLI writes get the same treatment.
 * While that context is live, Elementor's registered callback is skipped (its
 * whole-document pass is what this replaces) and `update_post_metadata` /
 * `add_post_metadata`, which know the post, store the policy's result instead.
 * Outside it, Elementor's callback runs exactly as before.
 */
final class Mcpwp_Elementor_Meta_Write {

	/**
	 * Keys Elementor stores element trees and document settings under.
	 */
	const KEYS = array( '_elementor_data', '_elementor_page_settings' );

	/**
	 * Live writer contexts, innermost last.
	 *
	 * @var array<int,array{post:int,raw_paths:string[],snapshot:mixed}>
	 */
	private static $contexts = array();

	/**
	 * Values this class is storing right now, by "post|key": the policy's
	 * result, re-written with every sanitizer skipped for that one write.
	 *
	 * @var array<string,mixed>
	 */
	private static $bypass = array();

	/**
	 * A sanitize run this class skipped, awaiting the metadata filter.
	 *
	 * @var array{key:string,value:mixed}|null
	 */
	private static $pending = null;

	/**
	 * Depth of capability checks during which MCPWP's grants stand aside.
	 *
	 * @var int
	 */
	private static $suspend_grants = 0;

	/**
	 * Hook the chokepoint. Runs at plugin load, before rest_api_init, where
	 * Elementor registers its meta.
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
	 * Whether MCPWP's scoped `unfiltered_html` grants must stand aside: true
	 * only while this class asks whether the acting principal holds it.
	 *
	 * @return bool
	 */
	public static function grants_suspended() {
		return self::$suspend_grants > 0;
	}

	/**
	 * Whether stylesheet text could end the <style> element it is printed in.
	 *
	 * Checked on the exact text that will be stored, canonicalised first: every
	 * whitespace character and backslash is dropped before looking for
	 * `</style`, so `</STYLE`, `</ style`, `< /style`, `<\/style` and
	 * `<\\/style` are all caught (#829 review rounds 1-2). CSS never needs any
	 * of them.
	 *
	 * @param mixed $css Stylesheet text.
	 * @return bool
	 */
	public static function css_breaks_out( $css ) {
		if ( ! is_string( $css ) || '' === $css ) {
			return false;
		}

		$canonical = preg_replace( '#[\s\\\\]+#', '', $css );

		return false !== stripos( (string) $canonical, '</style' );
	}

	/**
	 * update_post_meta() of an Elementor key, made by MCPWP.
	 *
	 * Takes the value EXACTLY as it is to be stored — never slashed — and
	 * slashes it itself for update_post_meta(), which unslashes (#829 review
	 * round 2). A caller that forgot wp_slash() on an array lost every
	 * backslash (`content:"\f101"` stored as `"f101"`) and turned `<\/style`
	 * into `</style` after the Kit CSS check had passed; the slashing
	 * contract now lives here, where no caller can get it wrong.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @param mixed  $value    Value to store, unslashed.
	 * @param array  $options  raw_paths (page settings keys stored as sent) and
	 *                         snapshot (a trusted rollback snapshot of this post).
	 * @return int|bool What update_post_meta() returned.
	 */
	public static function update( $post_id, $meta_key, $value, array $options = array() ) {
		return self::as_writer(
			(int) $post_id,
			static function () use ( $post_id, $meta_key, $value ) {
				return update_post_meta( (int) $post_id, $meta_key, wp_slash( $value ) );
			},
			$options
		);
	}

	/**
	 * Run $fn with MCPWP as the writer of $post_id's Elementor keys: every
	 * write of them inside — MCPWP's own, meta_input, add_post_meta(),
	 * Elementor's Document::save() — goes through the policy.
	 *
	 * @param int      $post_id Post ID. 0 only for a post wp_insert_post() is
	 *                          about to create (it has no ID yet): then any post
	 *                          the callable writes counts, and no option applies.
	 * @param callable $fn      The write.
	 * @param array    $options See update().
	 * @return mixed What $fn returned.
	 */
	public static function as_writer( $post_id, callable $fn, array $options = array() ) {
		$post_id = max( 0, (int) $post_id );

		self::$contexts[] = array(
			'post'      => $post_id,
			'raw_paths' => ( $post_id > 0 && isset( $options['raw_paths'] ) ) ? array_map( 'strval', (array) $options['raw_paths'] ) : array(),
			'snapshot'  => ( $post_id > 0 && array_key_exists( 'snapshot', $options ) ) ? $options['snapshot'] : null,
		);
		$depth = count( self::$contexts );

		try {
			return $fn();
		} finally {
			array_splice( self::$contexts, $depth - 1 );
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
		// MCPWP is writing, or re-writing a value its policy produced: the
		// policy runs in the metadata filter, which knows the post (the
		// sanitize filter does not), and which lets a re-write through only
		// for the post id + key it was produced for.
		if ( ! empty( self::$contexts ) || ! empty( self::$bypass ) ) {
			self::$pending = array(
				'key'   => (string) $key,
				'value' => $value,
			);

			return $value;
		}

		return call_user_func( $inner, $value, $key, $type, $subtype );
	}

	/**
	 * `update_post_metadata`: store the policy's value.
	 *
	 * @param mixed  $check      Short-circuit value from earlier filters.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value core is about to store, unslashed.
	 * @param mixed  $prev_value Previous-value condition.
	 * @return mixed
	 */
	public static function on_update_post_metadata( $check, $object_id, $meta_key, $meta_value, $prev_value = '' ) {
		$final = self::policy_value( $check, $object_id, $meta_key, $meta_value );
		if ( null === $final ) {
			return $check;
		}

		$slot                  = self::slot( $object_id, $meta_key );
		self::$bypass[ $slot ] = $final['value'];
		try {
			return update_metadata( 'post', $object_id, $meta_key, wp_slash( $final['value'] ), $prev_value );
		} finally {
			unset( self::$bypass[ $slot ] );
		}
	}

	/**
	 * `add_post_metadata`: as on_update_post_metadata(), for a first write.
	 *
	 * @param mixed  $check      Short-circuit value from earlier filters.
	 * @param int    $object_id  Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Value core is about to store, unslashed.
	 * @param bool   $unique     Unique flag.
	 * @return mixed
	 */
	public static function on_add_post_metadata( $check, $object_id, $meta_key, $meta_value, $unique = false ) {
		$final = self::policy_value( $check, $object_id, $meta_key, $meta_value );
		if ( null === $final ) {
			return $check;
		}

		$slot                  = self::slot( $object_id, $meta_key );
		self::$bypass[ $slot ] = $final['value'];
		try {
			return add_metadata( 'post', $object_id, $meta_key, wp_slash( $final['value'] ), $unique );
		} finally {
			unset( self::$bypass[ $slot ] );
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
	private static function policy_value( $check, $object_id, $meta_key, $meta_value ) {
		$pending       = self::$pending;
		self::$pending = null;

		if ( null !== $check || ! in_array( $meta_key, self::KEYS, true ) ) {
			return null;
		}

		$object_id = (int) $object_id;

		// Our own re-write of this post's key with the value the policy
		// produced for it: store it. Any other post, key or value is not.
		$slot = self::slot( $object_id, $meta_key );
		if ( array_key_exists( $slot, self::$bypass ) && self::$bypass[ $slot ] === $meta_value ) {
			return null;
		}

		// A sanitize run we skipped is always finished here, whichever post
		// it turns out to be for — never left unsanitized.
		$skipped = null !== $pending && $pending['key'] === $meta_key && $pending['value'] === $meta_value;

		if ( ! $skipped && ! self::is_writer_for( $object_id ) ) {
			return null;
		}

		$value = self::apply_policy( $object_id, $meta_key, $meta_value );

		return ( $value === $meta_value ) ? null : array( 'value' => $value );
	}

	/**
	 * Whether a live writer context covers this post.
	 *
	 * @param int $object_id Post ID.
	 * @return bool
	 */
	private static function is_writer_for( $object_id ) {
		foreach ( self::$contexts as $context ) {
			if ( 0 === $context['post'] || $object_id === $context['post'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The policy.
	 *
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $incoming  Value to store.
	 * @return mixed
	 */
	public static function apply_policy( $object_id, $meta_key, $incoming ) {
		if ( self::principal_may_store_raw() ) {
			return $incoming;
		}

		$raw_paths = array();
		$snapshots = array();
		foreach ( self::$contexts as $context ) {
			if ( (int) $object_id !== $context['post'] ) {
				continue;
			}
			$raw_paths = array_merge( $raw_paths, $context['raw_paths'] );
			if ( null !== $context['snapshot'] ) {
				$snapshots[] = $context['snapshot'];
			}
		}

		$stored = get_post_meta( (int) $object_id, $meta_key, true );

		if ( '_elementor_data' === $meta_key ) {
			return self::police_elementor_data( $incoming, $stored, $snapshots );
		}

		return self::police_settings( $incoming, $stored, $raw_paths );
	}

	/**
	 * Whether the acting principal holds `unfiltered_html` in its own right.
	 *
	 * @return bool
	 */
	private static function principal_may_store_raw() {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		++self::$suspend_grants;
		try {
			return (bool) current_user_can( 'unfiltered_html' );
		} finally {
			--self::$suspend_grants;
		}
	}

	// ---------------------------------------------------------------------
	// _elementor_data: per element, all or nothing.
	// ---------------------------------------------------------------------

	/**
	 * An element's raw strings are kept only when the WHOLE element is
	 * unchanged: the same id, elType and widgetType and every setting —
	 * `__dynamic__`, `__globals__`, repeater items included — identical to an
	 * element of the same id stored on this post (or in its trusted snapshot).
	 * Anything else in the element changed, and every string in it is
	 * sanitized: whether a string executes can depend on a neighbouring
	 * setting (a form field's field_type flipped to html activates its stale
	 * field_html; a removed `__dynamic__` override exposes the static value),
	 * so a string is never judged apart from its element (#829 review round 2).
	 *
	 * Children are judged as elements of their own. Each stored element can
	 * vouch for one incoming element only, so an unchanged element may move
	 * within the post but not be duplicated into a raw second copy.
	 *
	 * @param mixed $incoming  Tree as JSON text or array.
	 * @param mixed $stored    What the post holds now.
	 * @param array $snapshots Trusted rollback snapshots of the same post.
	 * @return mixed
	 */
	private static function police_elementor_data( $incoming, $stored, array $snapshots ) {
		$tree = is_string( $incoming ) ? json_decode( $incoming ) : $incoming;

		if ( ! is_array( $tree ) && ! is_object( $tree ) ) {
			// Not a tree: the whole value is one string, as Elementor treats it.
			if ( ! is_string( $incoming ) || $incoming === $stored ) {
				return $incoming;
			}
			foreach ( $snapshots as $snapshot ) {
				if ( $incoming === $snapshot ) {
					return $incoming;
				}
			}

			return wp_kses_post( $incoming );
		}

		$known = array();
		self::index_elements( self::as_list( $stored ), $known );
		foreach ( $snapshots as $snapshot ) {
			self::index_elements( self::as_list( $snapshot ), $known );
		}

		$changed = false;
		$out     = self::police_elements( $tree, $known, $changed );

		if ( ! $changed ) {
			// Nothing needed sanitizing: the caller's own bytes.
			return $incoming;
		}

		if ( ! is_string( $incoming ) ) {
			return $out;
		}

		$encoded = self::encode_like( $out, $incoming );

		// Never fall back to the unsanitized value.
		return false === $encoded ? '[]' : $encoded;
	}

	/**
	 * A stored `_elementor_data` value as a list of elements (assoc arrays).
	 *
	 * @param mixed $raw Stored value.
	 * @return array
	 */
	private static function as_list( $raw ) {
		if ( class_exists( 'Mcpwp_Elementor_Stored_Tree' ) ) {
			$read = Mcpwp_Elementor_Stored_Tree::read( $raw );

			return is_array( $read['elements'] ?? null ) ? $read['elements'] : array();
		}

		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Canonical form of one element without its children: every field and
	 * setting value exact, key order ignored, and the `isInner: false` default
	 * Elementor's save adds treated as absent.
	 *
	 * @param array|object $element Element.
	 * @return string
	 */
	private static function signature( $element ) {
		$fields = json_decode( (string) wp_json_encode( $element ), true );
		if ( ! is_array( $fields ) ) {
			return '';
		}

		unset( $fields['elements'] );
		if ( array_key_exists( 'isInner', $fields ) && false === $fields['isInner'] ) {
			unset( $fields['isInner'] );
		}

		$sort = static function ( $value ) use ( &$sort ) {
			if ( is_array( $value ) ) {
				ksort( $value );
				foreach ( $value as $k => $v ) {
					$value[ $k ] = $sort( $v );
				}
			}
			return $value;
		};

		return (string) wp_json_encode( $sort( $fields ) );
	}

	/**
	 * @param array|object $element Element.
	 * @return string Its id ('' when it has none).
	 */
	private static function element_id( $element ) {
		$id = is_array( $element ) ? ( $element['id'] ?? '' ) : ( $element->id ?? '' );

		return is_scalar( $id ) ? (string) $id : '';
	}

	/**
	 * Count every stored element's signature, by id.
	 *
	 * @param array $elements Elements (assoc).
	 * @param array $known    id => signature => count, by reference.
	 * @return void
	 */
	private static function index_elements( array $elements, array &$known ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$id = self::element_id( $element );
			if ( '' !== $id ) {
				$sig                  = self::signature( $element );
				$known[ $id ][ $sig ] = ( $known[ $id ][ $sig ] ?? 0 ) + 1;
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::index_elements( $element['elements'], $known );
			}
		}
	}

	/**
	 * Apply the policy to a list of elements (objects or arrays).
	 *
	 * @param array|object $elements Elements.
	 * @param array        $known    Stored signatures, consumed as matched.
	 * @param bool         $changed  Set when a string was sanitized.
	 * @return array|object
	 */
	private static function police_elements( $elements, array &$known, &$changed ) {
		$is_object = is_object( $elements );
		$items     = $is_object ? get_object_vars( $elements ) : $elements;

		foreach ( $items as $index => $element ) {
			if ( ! is_array( $element ) && ! is_object( $element ) ) {
				$items[ $index ] = self::police_value( $element, $changed );
				continue;
			}

			$id        = self::element_id( $element );
			$sig       = self::signature( $element );
			$unchanged = '' !== $id && ! empty( $known[ $id ][ $sig ] );
			if ( $unchanged ) {
				--$known[ $id ][ $sig ];
			}

			$fields = is_object( $element ) ? get_object_vars( $element ) : $element;
			foreach ( $fields as $field => $value ) {
				if ( 'elements' === $field && ( is_array( $value ) || is_object( $value ) ) ) {
					$fields[ $field ] = self::police_elements( $value, $known, $changed );
				} elseif ( ! $unchanged ) {
					$fields[ $field ] = self::police_value( $value, $changed );
				}
			}

			$items[ $index ] = is_object( $element ) ? (object) $fields : $fields;
		}

		return $is_object ? (object) $items : $items;
	}

	/**
	 * Sanitize every string in a value (arrays and objects walked).
	 *
	 * @param mixed $value   Value.
	 * @param bool  $changed Set when a string was sanitized.
	 * @return mixed
	 */
	private static function police_value( $value, &$changed ) {
		if ( is_string( $value ) ) {
			$clean = wp_kses_post( $value );
			if ( $clean !== $value ) {
				$changed = true;
			}

			return $clean;
		}

		if ( is_object( $value ) ) {
			$fields = get_object_vars( $value );
			foreach ( $fields as $k => $v ) {
				$fields[ $k ] = self::police_value( $v, $changed );
			}

			return (object) $fields;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::police_value( $v, $changed );
			}
		}

		return $value;
	}

	/**
	 * Encode a policed tree with the JSON flags the caller's text used, so
	 * unedited elements keep their bytes.
	 *
	 * @param mixed  $tree     Tree (objects kept as objects).
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

	// ---------------------------------------------------------------------
	// _elementor_page_settings: per top-level key, all or nothing.
	// ---------------------------------------------------------------------

	/**
	 * A top-level setting keeps its raw strings only when its whole value is
	 * identical to what the post stores under that key; otherwise every string
	 * in it is sanitized. A declared raw path (the Kit's custom_css) is stored
	 * as sent unless it could end its <style> element.
	 *
	 * @param mixed    $incoming  Settings to store.
	 * @param mixed    $stored    What the post holds now.
	 * @param string[] $raw_paths Top-level keys stored as sent.
	 * @return mixed
	 */
	private static function police_settings( $incoming, $stored, array $raw_paths ) {
		if ( is_string( $incoming ) ) {
			return ( $incoming === $stored ) ? $incoming : wp_kses_post( $incoming );
		}

		$was_object = is_object( $incoming );
		if ( $was_object ) {
			$incoming = get_object_vars( $incoming );
		}

		if ( ! is_array( $incoming ) ) {
			return $incoming;
		}

		$stored  = is_object( $stored ) ? get_object_vars( $stored ) : $stored;
		$stored  = is_array( $stored ) ? $stored : array();
		$changed = false;

		foreach ( $incoming as $k => $v ) {
			if ( in_array( (string) $k, $raw_paths, true ) && ! self::css_breaks_out( $v ) ) {
				continue;
			}
			if ( array_key_exists( $k, $stored ) && self::same_value( $v, $stored[ $k ] ) ) {
				continue;
			}
			$incoming[ $k ] = self::police_value( $v, $changed );
		}

		return $was_object ? (object) $incoming : $incoming;
	}

	/**
	 * Exact equality of two values, objects and arrays alike, key order ignored.
	 *
	 * @param mixed $a Value.
	 * @param mixed $b Value.
	 * @return bool
	 */
	private static function same_value( $a, $b ) {
		return self::signature( array( 'v' => $a ) ) === self::signature( array( 'v' => $b ) );
	}

	// ---------------------------------------------------------------------
	// Slots.
	// ---------------------------------------------------------------------

	/**
	 * @param int    $object_id Post ID.
	 * @param string $meta_key  Meta key.
	 * @return string
	 */
	private static function slot( $object_id, $meta_key ) {
		return (int) $object_id . '|' . (string) $meta_key;
	}
}
