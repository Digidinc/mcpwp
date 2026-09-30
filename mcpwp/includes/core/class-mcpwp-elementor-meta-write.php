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
 * `_elementor_data` is decoded once, associatively and exactly as Elementor
 * decodes it; a value that is not a list of elements is refused and never
 * stored (update() answers WP_Error 400 elementor_data_not_a_tree), and keys
 * containing a NUL byte are removed (#829 review round 3).
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
	 * `_elementor_data` each post held before its first MCPWP write since
	 * forget_replaced(), by post id.
	 *
	 * @var array<int,mixed>
	 */
	private static $replaced = array();

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
	 * Start recording what the next MCPWP write of this post's
	 * `_elementor_data` replaces (Mcpwp_Action_Log::begin()).
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function forget_replaced( $post_id ) {
		unset( self::$replaced[ (int) $post_id ] );
	}

	/**
	 * What the first MCPWP write of this post's `_elementor_data` since
	 * forget_replaced() replaced.
	 *
	 * @param int $post_id Post ID.
	 * @return array{written:bool,value:mixed}
	 */
	public static function replaced( $post_id ) {
		$post_id = (int) $post_id;

		return array_key_exists( $post_id, self::$replaced )
			? array( 'written' => true, 'value' => self::$replaced[ $post_id ] )
			: array( 'written' => false, 'value' => null );
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
		$refusal = '_elementor_data' === $meta_key ? self::tree_refusal( $value ) : self::settings_refusal( $value );
		if ( null !== $refusal ) {
			return $refusal;
		}

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
				'key'     => (string) $key,
				'value'   => $value,
				'inner'   => $inner,
				'type'    => $type,
				'subtype' => $subtype,
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
		if ( ! empty( $final['refused'] ) ) {
			// Not a tree: nothing is stored (#829 review round 3).
			return false;
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
		if ( ! empty( $final['refused'] ) ) {
			// Not a tree: nothing is stored (#829 review round 3).
			return false;
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

		if ( ! self::is_writer_for( $object_id ) ) {
			if ( ! $skipped ) {
				return null;
			}

			// Not MCPWP's write — another plugin's, or core's, landing while
			// MCPWP writes a different post. It gets exactly what Elementor's
			// own callback gives it, never MCPWP's policy or a refusal
			// (#829 review round 4).
			++self::$suspend_grants;
			try {
				$value = call_user_func( $pending['inner'], $meta_value, $meta_key, $pending['type'], $pending['subtype'] );
			} finally {
				--self::$suspend_grants;
			}

			return ( $value === $meta_value ) ? null : array( 'value' => $value );
		}

		if ( '_elementor_data' === $meta_key ) {
			// What this write replaces, for the action log's rollback trust
			// (#829 review round 3): the first MCPWP write per post since
			// forget_replaced() records it.
			if ( ! array_key_exists( $object_id, self::$replaced ) ) {
				self::$replaced[ $object_id ] = get_post_meta( $object_id, '_elementor_data', true );
			}

			// Whoever writes, a value that is not a tree is not stored.
			if ( null === self::as_tree( $meta_value ) ) {
				return array( 'refused' => true );
			}
		} elseif ( null !== self::settings_refusal( $meta_value ) ) {
			return array( 'refused' => true );
		}

		$value = self::apply_policy( $object_id, $meta_key, $meta_value );

		if ( is_array( $value ) && ! empty( $value['__mcpwp_refused'] ) ) {
			return array( 'refused' => true );
		}

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
		// One decode, associative, as Elementor decodes (#829 review round 3):
		// object mode returns null on a key that starts with "\u0000", which
		// made a valid tree look like "not a tree" and sent the raw JSON text
		// through wp_kses_post() — which cannot see JSON-escaped markup.
		$tree = self::as_tree( $incoming );
		if ( null === $tree ) {
			return self::refused();
		}

		$changed = false;
		$tree    = self::strip_nul_keys( $tree, $changed );

		$known = array();
		self::index_elements( (array) self::as_tree( $stored, true ), $known );
		foreach ( $snapshots as $snapshot ) {
			self::index_elements( (array) self::as_tree( $snapshot, true ), $known );
		}

		$out = self::police_elements( $tree, $known, $changed );

		if ( ! $changed ) {
			// Nothing needed sanitizing: the caller's own bytes.
			return $incoming;
		}

		if ( ! is_string( $incoming ) ) {
			return $out;
		}

		$encoded = self::encode_like( $out, $incoming );

		// Never fall back to the unsanitized value, or to kses'd JSON text.
		return false === $encoded ? self::refused() : $encoded;
	}

	/**
	 * Decode flags for every `_elementor_data` decode here: associative, and
	 * integers too large for PHP kept exact as strings, so two different huge
	 * ints never compare equal.
	 */
	const DECODE_FLAGS = JSON_BIGINT_AS_STRING;

	/**
	 * A `_elementor_data` value as a list of elements (assoc arrays), decoded
	 * once and associatively as Elementor decodes it; null when it is not one.
	 *
	 * @param mixed $raw JSON text or array.
	 * @return array|null
	 */
	private static function as_tree( $raw, $allow_non_finite = false ) {
		if ( null === $raw || '' === $raw ) {
			return array();
		}

		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true, 512, self::DECODE_FLAGS );
		}

		if ( ! is_array( $raw ) ) {
			return null;
		}

		// INF/-INF/NAN: `1e999` decodes to INF without an error, and no JSON
		// encoder (Elementor's included) can write one back, so no genuine
		// document holds one (#829 review round 4). Refused on input; a stored
		// one is read (leniently) but can never vouch for anything.
		if ( ! $allow_non_finite && self::contains_non_finite( $raw ) ) {
			return null;
		}

		if ( class_exists( 'Mcpwp_Elementor_Stored_Tree' ) ) {
			return Mcpwp_Elementor_Stored_Tree::element_list( $raw );
		}

		foreach ( $raw as $key => $element ) {
			if ( ! is_int( $key ) || ! is_array( $element ) ) {
				return null;
			}
		}

		return array_values( $raw );
	}

	/**
	 * Whether a value holds INF, -INF or NAN anywhere.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function contains_non_finite( $value ) {
		if ( is_float( $value ) ) {
			return ! is_finite( $value );
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( self::contains_non_finite( $v ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Why a `_elementor_data` value cannot be stored: it does not decode, as
	 * Elementor decodes it, to a list of elements. Null when it can.
	 *
	 * @param mixed $value Value to store.
	 * @return WP_Error|null
	 */
	public static function tree_refusal( $value ) {
		if ( null !== self::as_tree( $value ) ) {
			return null;
		}

		$decoded = is_string( $value ) ? json_decode( $value, true, 512, self::DECODE_FLAGS ) : $value;
		if ( null !== self::as_tree( $value, true ) || ( is_array( $decoded ) && self::contains_non_finite( $decoded ) ) ) {
			return self::non_finite_refusal( 'elementor_data_non_finite_number' );
		}

		return new WP_Error(
			'elementor_data_not_a_tree',
			__( 'The Elementor data is not a list of elements (JSON that decodes to an array of element objects), so nothing was stored. Send the page as an element list, e.g. [{"id":"abc1234","elType":"section","settings":{},"elements":[]}].', 'mcpwp' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Why page settings cannot be stored; null when they can.
	 *
	 * @param mixed $value Settings.
	 * @return WP_Error|null
	 */
	public static function settings_refusal( $value ) {
		return self::contains_non_finite( $value ) ? self::non_finite_refusal( 'elementor_settings_non_finite_number' ) : null;
	}

	/**
	 * @param string $code Error code.
	 * @return WP_Error
	 */
	private static function non_finite_refusal( $code ) {
		return new WP_Error(
			$code,
			__( 'The value holds a number JSON cannot represent (Infinity or NaN, for example from 1e999), which no Elementor document contains, so nothing was stored. Send a finite number or a string.', 'mcpwp' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Marker for a value the policy refuses to store at all.
	 *
	 * @return array
	 */
	private static function refused() {
		return array( '__mcpwp_refused' => true );
	}

	/**
	 * Remove every array key containing a NUL byte, at any depth. No
	 * Elementor setting has one; PHP's object-mode decode cannot represent
	 * one, which is what made a tree look like text (#829 review round 3).
	 *
	 * @param mixed $value   Value.
	 * @param bool  $changed Set when a key was removed.
	 * @return mixed
	 */
	private static function strip_nul_keys( $value, &$changed ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && false !== strpos( $k, "\0" ) ) {
				unset( $value[ $k ] );
				$changed = true;
				continue;
			}
			$value[ $k ] = self::strip_nul_keys( $v, $changed );
		}

		return $value;
	}

	/**
	 * The fields of one element that decide what it renders: everything but
	 * its list of children (judged on their own), with Elementor's
	 * `isInner: false` default treated as absent. A non-list `elements` stays
	 * in, so it can never compare equal to a list (#829 review round 3).
	 *
	 * @param array $element Element (assoc).
	 * @return array
	 */
	private static function element_fields( array $element ) {
		if ( isset( $element['elements'] ) && self::is_list_of_elements( $element['elements'] ) ) {
			unset( $element['elements'] );
		}
		if ( array_key_exists( 'isInner', $element ) && false === $element['isInner'] ) {
			unset( $element['isInner'] );
		}

		return $element;
	}

	/**
	 * Strict, fail-closed equality of two decoded values (#829 review round 4).
	 *
	 * No encoding step: an earlier signature string turned an encode failure
	 * (INF) into '' and made every such element "equal". Here anything that is
	 * not provably the same value Elementor would see is different:
	 *  - types must match: 1 vs 1.0 vs "1" vs true differ; null vs "" differ;
	 *  - floats must be finite and identical including sign (-0.0 vs 0.0);
	 *  - a digits-only string past PHP_INT_MAX may have been a JSON number
	 *    (JSON_BIGINT_AS_STRING) or a string — ambiguous, so different;
	 *  - objects (only page settings hold them) must be the same class with
	 *    strictly equal properties;
	 *  - lists compare in order; maps compare by key set, key order ignored
	 *    (Elementor reads settings by key). `{}` and `[]` both decode to an
	 *    empty array, as they do for Elementor itself, so they are equal.
	 *
	 * @param mixed $a Value.
	 * @param mixed $b Value.
	 * @return bool
	 */
	public static function deep_equal( $a, $b ) {
		if ( is_array( $a ) || is_array( $b ) ) {
			if ( ! is_array( $a ) || ! is_array( $b ) || count( $a ) !== count( $b ) ) {
				return false;
			}
			$a_list = self::is_list( $a );
			if ( self::is_list( $b ) !== $a_list ) {
				return false;
			}
			foreach ( $a as $k => $v ) {
				if ( ! array_key_exists( $k, $b ) || ! self::deep_equal( $v, $b[ $k ] ) ) {
					return false;
				}
			}

			return true;
		}

		if ( is_object( $a ) || is_object( $b ) ) {
			// Only page settings can hold objects (unserialized): the same
			// class with strictly equal properties, nothing looser.
			return is_object( $a ) && is_object( $b ) && get_class( $a ) === get_class( $b )
				&& self::deep_equal( self::as_map( get_object_vars( $a ) ), self::as_map( get_object_vars( $b ) ) );
		}

		if ( is_float( $a ) || is_float( $b ) ) {
			if ( ! is_float( $a ) || ! is_float( $b ) || ! is_finite( $a ) || ! is_finite( $b ) ) {
				return false;
			}

			return var_export( $a, true ) === var_export( $b, true );
		}

		if ( is_string( $a ) && is_string( $b ) && ( self::is_big_integer_string( $a ) || self::is_big_integer_string( $b ) ) ) {
			return false;
		}

		return $a === $b;
	}

	/**
	 * Object properties as a map that never reads as a list, so {"0":x} on
	 * an object is not compared as a list.
	 *
	 * @param array $vars Properties.
	 * @return array
	 */
	private static function as_map( array $vars ) {
		return array( '__mcpwp_object' => true ) + $vars;
	}

	/**
	 * @param array $value Array.
	 * @return bool Keys are exactly 0..n-1 in order.
	 */
	private static function is_list( array $value ) {
		$i = 0;
		foreach ( $value as $k => $unused ) {
			if ( $k !== $i ) {
				return false;
			}
			++$i;
		}

		return true;
	}

	/**
	 * A digits-only string outside PHP's integer range: what
	 * JSON_BIGINT_AS_STRING makes of a huge JSON number, and indistinguishable
	 * after the decode from a string that looked the same.
	 *
	 * @param string $value String.
	 * @return bool
	 */
	private static function is_big_integer_string( $value ) {
		if ( ! preg_match( '/^-?[0-9]{19,}$/', $value ) ) {
			return false;
		}

		return (string) (int) $value !== ltrim( $value, '+' );
	}

	/**
	 * Whether a value is a list of elements (integer keys, array items).
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_list_of_elements( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $k => $v ) {
			if ( ! is_int( $k ) || ! is_array( $v ) ) {
				return false;
			}
		}

		return true;
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
	 * Index every stored element's fields by id; each entry may vouch once.
	 *
	 * @param array $elements Elements (assoc).
	 * @param array $known    id => list of array{fields:array,used:bool}, by reference.
	 * @return void
	 */
	private static function index_elements( array $elements, array &$known ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$id = self::element_id( $element );
			if ( '' !== $id ) {
				$known[ $id ][] = array(
					'fields' => self::element_fields( $element ),
					'used'   => false,
				);
			}

			if ( isset( $element['elements'] ) && is_array( $element['elements'] ) ) {
				self::index_elements( $element['elements'], $known );
			}
		}
	}

	/**
	 * Consume the first unused stored element strictly equal to this one.
	 *
	 * @param array  $known  Index, by reference.
	 * @param string $id     Element id.
	 * @param array  $fields Incoming element's fields.
	 * @return bool Whether one vouched for it.
	 */
	private static function vouch( array &$known, $id, array $fields ) {
		if ( '' === $id || empty( $known[ $id ] ) ) {
			return false;
		}

		foreach ( $known[ $id ] as $i => $candidate ) {
			if ( ! $candidate['used'] && self::deep_equal( $fields, $candidate['fields'] ) ) {
				$known[ $id ][ $i ]['used'] = true;

				return true;
			}
		}

		return false;
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

			$id       = self::element_id( $element );
			$children = is_array( $element ) ? ( $element['elements'] ?? null ) : ( $element->elements ?? null );
			// A present but non-list `elements` is never "unchanged" (#829
			// review round 3): it is policed like any other value. An object
			// element is never unchanged either (the decode never makes one).
			$bad_children = null !== $children && ! self::is_list_of_elements( $children );
			$unchanged    = ! $bad_children && is_array( $element ) && self::vouch( $known, $id, self::element_fields( $element ) );

			$fields = is_object( $element ) ? get_object_vars( $element ) : $element;
			foreach ( $fields as $field => $value ) {
				if ( 'elements' === $field && ! $bad_children ) {
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
		$decoded = json_decode( $intended, true, 512, self::DECODE_FLAGS );
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

		$stored   = is_object( $stored ) ? get_object_vars( $stored ) : $stored;
		$stored   = is_array( $stored ) ? $stored : array();
		$changed  = false;
		$incoming = self::strip_nul_keys( $incoming, $changed );

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
		return self::deep_equal( $a, $b );
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
