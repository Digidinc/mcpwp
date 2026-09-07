<?php
/**
 * Elementor engine — writer.
 *
 * Carved verbatim from Mcpwp_Elementor_Basic (G4 split). Mixed back via trait — same class, same $this.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Elementor_Writer_Trait {

	/**
	 * How many Elementor calls are currently in flight under this trait.
	 *
	 * Guards the scoped wp_kses_allowed_html normaliser: it is installed on the
	 * way into the outermost call and removed only on the way out of it.
	 *
	 * @var int
	 */
	private static $kses_normalizer_depth = 0;

	/**
	 * How many content-bearing Elementor calls are in flight under this trait.
	 *
	 * Guards the scoped `unfiltered_html` grant exactly the way
	 * $kses_normalizer_depth guards the allow-list normaliser: installed on the
	 * way into the outermost content-bearing call, removed on the way out of it.
	 *
	 * @var int
	 */
	private static $unfiltered_html_depth = 0;

	/**
	 * The one user the scoped `unfiltered_html` grant applies to.
	 *
	 * Captured when the grant is installed, so a capability check performed for
	 * some OTHER user during the same call — a revision author, a notification
	 * recipient, a hook that walks users — cannot pick the grant up.
	 *
	 * @var int
	 */
	private static $unfiltered_html_user = 0;

	/**
	 * The Elementor calls that hand our element tree to Elementor.
	 *
	 * Only these get the scoped grant. A cache clear and a CSS regeneration
	 * carry no caller content, so they have no reason to touch capabilities.
	 *
	 * @var string[]
	 */
	private static $content_bearing_labels = array( 'document_save', 'html_cache_rebuild' );

	/**
	 * Elementor's content-sanitizer callback while it is unhooked, or null.
	 *
	 * @var callable|null
	 */
	private static $suspended_content_sanitizer = null;

	/**
	 * Run one call into Elementor's own API and survive a Throwable from it.
	 *
	 * Every Document::save() call below already handled a RETURNED WP_Error.
	 * None of them handled a THROWN error, so anything Elementor raised
	 * internally — a TypeError against a changed signature, an Error from a
	 * class removed between majors — propagated out of the REST request and
	 * became an HTTP 500. That mattered far more than an ordinary crash,
	 * because the raw-meta fallback that exists precisely for "Document::save()
	 * did not work" sits after the call: a throw skipped the fallback entirely
	 * and the write was lost, while the response carried no explanation.
	 *
	 * Observed on staging 2026-09-05 (MCPWP 3.10.3, WP 7.1, PHP 8.4.25,
	 * Elementor 4.2.4 + Pro 4.2.3): dry-run clean, real save 500, readback
	 * empty, while Elementor's own editor saved the same post fine.
	 *
	 * Root cause, reproduced 2026-09-05 on PHP 8.4.25 + Elementor 4.2.4:
	 * Elementor's own content-sanitizer hooks elementor/document/save/data,
	 * so it runs INSIDE Document::save(). Its is_target_widget() reads
	 * $element['widgetType'] unguarded (modules/content-sanitizer/module.php
	 * line 45) while iterating every element — and sections, columns and
	 * containers have no widgetType. That is an undefined-key warning per
	 * non-widget element, which is fatal wherever warnings are promoted to
	 * exceptions.
	 *
	 * Crucially the sanitizer early-returns for anyone with manage_options.
	 * A logged-in admin in the browser never reaches it; an API-key request
	 * (no manage_options) always does. That, not the document type, is why
	 * Elementor's own editor saved the same page that MCPWP could not.
	 *
	 * The upstream defect is Elementor's. This guard is what keeps our write
	 * path alive through it, and what reports it instead of a blank 500.
	 *
	 * A caught Throwable is recorded under "<label>_exception"; a returned
	 * WP_Error is recorded under "<label>_error". Both MUST leave the caller on
	 * the degraded path. An Elementor API that failed and one that worked must
	 * not produce the same response.
	 *
	 * @param callable $call   The Elementor call to attempt.
	 * @param array    $debug  Debug bag, written by reference on failure.
	 * @param string   $label  Key prefix for the debug entry.
	 * @return array{ok:bool,value:mixed} ok=false when the call threw or returned WP_Error.
	 */
	private function attempt_elementor_call( callable $call, array &$debug, $label ) {
		// Depth-counted so a nested call cannot tear the filter out from under
		// the call that installed it. Nothing nests today; a future edit that
		// starts to would otherwise silently reopen #765 with no failing test.
		if ( 0 === self::$kses_normalizer_depth ) {
			add_filter( 'wp_kses_allowed_html', array( __CLASS__, 'normalize_kses_allowed_html' ), PHP_INT_MAX, 2 );
		}
		++self::$kses_normalizer_depth;

		// Scoped `unfiltered_html` for the calls that carry our element tree.
		// grant_unfiltered_html_cap() carries the reasoning; #769 is what it
		// repairs. Depth-counted for the same reason as the normaliser above.
		//
		// core_post_content_kses_gate_installed() is a precondition, not a
		// nicety: Elementor's save writes post_content from caller markup while
		// this grant is active, and it is WordPress's own latched
		// content_save_pre filter — not anything here — that keeps that write
		// filtered. If that gate is missing we decline to grant, and #769 comes
		// back as an honest render_verified=false instead of being fixed by
		// quietly widening what a write can store.
		$is_content_bearing     = in_array( $label, self::$content_bearing_labels, true ) && get_current_user_id() > 0;
		$grants_unfiltered_html = $is_content_bearing && self::core_post_content_kses_gate_installed();

		if ( $is_content_bearing && ! $grants_unfiltered_html ) {
			// Never silent: the caller is about to get a render verdict that
			// depends on this, and "we chose not to" must not look like "there
			// was nothing to do".
			$debug['unfiltered_html_grant_declined'] = 'core content_save_pre KSES gate is not installed';
		}

		if ( $grants_unfiltered_html ) {
			if ( 0 === self::$unfiltered_html_depth ) {
				self::$unfiltered_html_user = get_current_user_id();
				add_filter( 'map_meta_cap', array( __CLASS__, 'map_unfiltered_html_cap' ), PHP_INT_MAX, 3 );
				add_filter( 'user_has_cap', array( __CLASS__, 'grant_unfiltered_html_cap' ), PHP_INT_MAX, 4 );
				self::$suspended_content_sanitizer = self::suspend_elementor_content_sanitizer();

				if ( null !== self::$suspended_content_sanitizer ) {
					$debug['content_sanitizer_suspended'] = true;
				}
			}
			++self::$unfiltered_html_depth;
		}

		try {
			$value = $call();

			if ( is_wp_error( $value ) ) {
				$debug[ $label . '_error' ] = $value->get_error_message();

				return array(
					'ok'    => false,
					'value' => $value,
				);
			}

			return array(
				'ok'    => true,
				'value' => $value,
			);
		} catch ( \Throwable $e ) {
			$debug[ $label . '_exception' ] = array(
				'class'   => get_class( $e ),
				'message' => $e->getMessage(),
				'at'      => basename( $e->getFile() ) . ':' . $e->getLine(),
			);

			return array(
				'ok'    => false,
				'value' => null,
			);
		} finally {
			--self::$kses_normalizer_depth;

			if ( 0 === self::$kses_normalizer_depth ) {
				remove_filter( 'wp_kses_allowed_html', array( __CLASS__, 'normalize_kses_allowed_html' ), PHP_INT_MAX );
			}

			if ( $grants_unfiltered_html ) {
				--self::$unfiltered_html_depth;

				if ( 0 === self::$unfiltered_html_depth ) {
					remove_filter( 'map_meta_cap', array( __CLASS__, 'map_unfiltered_html_cap' ), PHP_INT_MAX );
					remove_filter( 'user_has_cap', array( __CLASS__, 'grant_unfiltered_html_cap' ), PHP_INT_MAX );
					self::$unfiltered_html_user = 0;

					if ( null !== self::$suspended_content_sanitizer ) {
						add_filter( 'elementor/document/save/data', self::$suspended_content_sanitizer, 10, 2 );
						self::$suspended_content_sanitizer = null;
					}
				}
			}
		}
	}

	/**
	 * Grant `unfiltered_html` to the writing user, for the duration of one
	 * Elementor call that we hand our own element tree to.
	 *
	 * Elementor's Document::save() runs the whole payload through
	 * Utils::kses_post_deep() whenever the current user lacks `unfiltered_html`
	 * (core/base/document.php). Every MCPWP write is such a request — the API
	 * key maps onto the mcpwp_api_agent role, which has no such capability —
	 * so Elementor rewrote the caller's settings on the way in: `style=` and
	 * `<script>` stripped out of widget settings, among others. Reproduced on
	 * WP 7.1 / Elementor 4.2.4 (#769).
	 *
	 * That mattered because MCPWP calls save() a second time purely to rebuild
	 * the render cache. The rebuild wrote back a semantically different tree
	 * with an IDENTICAL element count, our integrity check correctly refused
	 * it, restored the known-good bytes, and reported render_verified=false on
	 * a page that had in fact been written correctly. Content with a style
	 * attribute could therefore never come back verified.
	 *
	 * This grants nothing that was not already true of the request:
	 *
	 * - What is STORED does not change. MCPWP writes _elementor_data itself,
	 *   from the caller's bytes, before Elementor is called at all, and
	 *   restores those bytes over any rebuild that disagrees. The kses pass
	 *   never reached the stored row.
	 * - What is SERVED does not change. Elementor's frontend builds from
	 *   _elementor_data at render time; post_content holds only the plain-text
	 *   fallback. The unfiltered content already rendered.
	 *
	 * So the pass was not a safety control here — it was the sole cause of a
	 * disagreement between what we store and what Elementor renders. What
	 * governs whether a caller may write raw HTML at all remains the API key,
	 * its role scope and the plugin's own validation, exactly as before.
	 *
	 * Deliberately narrow: one capability, one user, one call, removed in a
	 * `finally` so a Throwable cannot leave it installed.
	 *
	 * @param array    $allcaps All capabilities of the user being tested.
	 * @param string[] $caps    Required primitive capabilities.
	 * @param array    $args    Arguments of the check.
	 * @param \WP_User $user    The user being tested.
	 * @return array Capabilities, possibly with the scoped grant added.
	 */
	public static function grant_unfiltered_html_cap( $allcaps, $caps = array(), $args = array(), $user = null ) {
		if ( self::$unfiltered_html_depth < 1 || ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		$user_id = ( $user instanceof \WP_User ) ? (int) $user->ID : 0;

		if ( $user_id < 1 || $user_id !== self::$unfiltered_html_user ) {
			return $allcaps;
		}

		$allcaps['unfiltered_html'] = true;

		return $allcaps;
	}

	/**
	 * Whether WordPress's own post_content KSES gate is installed for this request.
	 *
	 * This is the precondition for granting `unfiltered_html` at all, and the
	 * reason the grant cannot widen what lands in wp_posts.post_content.
	 *
	 * Elementor's Document::save() does more than write our meta: save_elements()
	 * calls DB::save_plain_text(), which wp_update_post()s a post_content built
	 * from each widget's render_plain_content(). That is NOT plain text — the
	 * default implementation is render_content(), and Widget_Text_Editor echoes
	 * its setting unescaped by design. So post_content is written from caller
	 * markup, inside the window where this grant is active.
	 *
	 * What protects it is core, not us. kses_init() decides ONCE, on `init` /
	 * `set_current_user`, whether to hook wp_filter_post_kses onto
	 * content_save_pre, and that decision is latched: a capability granted later
	 * in the request does not un-hook it. MCPWP's API user is resolved during the
	 * REST permission_callback, long before any Elementor call, so by the time we
	 * grant anything the gate is already installed and stays installed.
	 *
	 * Verified on the rig (WP 7.0.2, Elementor 4.2.4, mcpwp_api_agent user):
	 * a text-editor setting of `<p>Hello <img src="x" onerror="alert(1)"> world</p>`
	 * saved through this path leaves post_content as
	 * `<p>Hello <img src="x"> world</p>` — the executable attribute is gone, with
	 * the grant active. has_filter( 'content_save_pre', 'wp_filter_post_kses' )
	 * reads 10 before the grant, during it, and after it.
	 *
	 * The two states are therefore exhaustive, and neither is made worse here:
	 *
	 * - gate installed → the user lacked `unfiltered_html` at kses_init, so
	 *   post_content is filtered whatever we grant afterwards
	 * - gate absent    → the user already had `unfiltered_html` at kses_init, so
	 *   post_content was never filtered for them and this grant adds nothing
	 *
	 * Requiring the gate turns that from a fortunate ordering into a stated
	 * precondition. If some other plugin has removed core's gate while our user
	 * still lacks the capability, we decline to grant: #769 comes back as an
	 * honest render_verified=false rather than being fixed by quietly widening
	 * what a write can store.
	 *
	 * @return bool Whether core's content_save_pre KSES filter is installed.
	 */
	private static function core_post_content_kses_gate_installed() {
		if ( ! function_exists( 'has_filter' ) ) {
			return false;
		}

		return false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
	}

	/**
	 * Unhook Elementor's content-sanitizer for the duration of one of our saves.
	 *
	 * The sanitizer (modules/content-sanitizer/module.php) hooks
	 * elementor/document/save/data and rewrites the `title` of every heading
	 * widget through wp_kses() with an allow-list it builds itself. Two things
	 * make that fatal for an API writer:
	 *
	 * 1. It early-returns for anyone with `manage_options`, so it runs on every
	 *    MCPWP write and never in an admin's own browser — the same asymmetry
	 *    behind #759 and #765.
	 * 2. Its own attribute filter is inverted: `! substr( $a, 0, 5 ) === 'data-'`
	 *    parses as `( ! substr(...) ) === 'data-'`, which is always false, so
	 *    array_filter() keeps nothing and EVERY attribute is stripped from every
	 *    tag — `style`, `class`, `href`, all of it. Verified against 4.2.4.
	 *
	 * So a heading whose title contains any markup with an attribute came back
	 * from Elementor changed, with an identical element count. Our integrity
	 * check then correctly refused the rebuild, restored the caller's bytes and
	 * reported render_verified=false on a page that had been written correctly
	 * (#769).
	 *
	 * Suspending it changes nothing that reaches a visitor: MCPWP has already
	 * written and verified _elementor_data from the caller's bytes, restores
	 * them over any rebuild that disagrees, and Elementor's frontend renders
	 * from that meta. The sanitizer's output only ever reached the render cache
	 * we are rebuilding — which is exactly the copy that must agree with what
	 * we stored.
	 *
	 * Returns the callback so the caller can hook it straight back on. Returns
	 * null when the module is not present or not hooked, in which case nothing
	 * was changed and nothing needs restoring.
	 *
	 * @return callable|null The removed callback, or null if nothing was removed.
	 */
	private static function suspend_elementor_content_sanitizer() {
		if ( ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance->modules_manager ) ) {
			return null;
		}

		try {
			$module = \Elementor\Plugin::$instance->modules_manager->get_modules( 'content-sanitizer' );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_object( $module ) || ! method_exists( $module, 'sanitize_content' ) ) {
			return null;
		}

		$callback = array( $module, 'sanitize_content' );

		if ( ! remove_filter( 'elementor/document/save/data', $callback, 10 ) ) {
			return null;
		}

		return $callback;
	}

	/**
	 * Keep `unfiltered_html` reachable for the scoped grant above.
	 *
	 * On multisite, map_meta_cap() rewrites `unfiltered_html` to `do_not_allow`
	 * for anyone who is not a super admin, before user_has_cap is consulted at
	 * all. Without this the grant would be a silent no-op there — a single-site
	 * fix that reports success on multisite and changes nothing, which is the
	 * failure shape this codebase keeps paying for.
	 *
	 * @param string[] $caps    Primitive capabilities the check was mapped to.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User the check is for.
	 * @return string[] Mapped capabilities.
	 */
	public static function map_unfiltered_html_cap( $caps, $cap = '', $user_id = 0 ) {
		if ( self::$unfiltered_html_depth < 1 || 'unfiltered_html' !== $cap ) {
			return $caps;
		}

		$user_id = (int) $user_id;

		if ( $user_id < 1 || $user_id !== self::$unfiltered_html_user ) {
			return $caps;
		}

		return array( 'unfiltered_html' );
	}

	/**
	 * Give supported bare-tag entries in a 'post'-context KSES allow-list an
	 * array of attributes Elementor can inspect.
	 *
	 * `'tag' => true` is a shorthand WordPress core supports and acts on
	 * deliberately: wp_kses_attr() tests `true === $allowed_html[ $element_low ]`
	 * and returns the bare tag, meaning "this tag is allowed, none of its
	 * attributes are". Third-party `wp_kses_allowed_html` filters use it, and
	 * core has no objection.
	 *
	 * Elementor 4.2.4 does. Widget_Heading::sanitize() (includes/widgets/heading.php)
	 * walks the same allow-list and hands each value straight to array_filter(),
	 * so a single `true` entry is a TypeError. The content-sanitizer module calls
	 * that method on every heading saved by a user without manage_options — i.e.
	 * on every MCPWP API-key write, and never in an admin's own browser — so one
	 * unrelated plugin's filter takes down the whole Elementor write path (#765).
	 *
	 * Rewriting `true` and other non-null empty values to `array()` is the array
	 * spelling of the same rule, not a relaxation of it: wp_kses_attr()'s
	 * `empty()` branch and its `true` branch return the identical bare tag. A
	 * null value is different: wp_kses_split2() rejects that tag at its earlier
	 * isset() gate, so the key must be removed to preserve the denial. Unsupported
	 * non-empty scalar and object values remain unchanged so the render failure
	 * stays observable instead of silently allowing a tag WordPress would reject.
	 *
	 * Only the 'post' context is touched. Other contexts carry differently shaped
	 * arrays — 'entities' is a flat list of entity-name strings — and rewriting
	 * those would corrupt them.
	 *
	 * Installed for the duration of one Elementor call by attempt_elementor_call()
	 * and removed in its `finally`, so it never alters KSES for anything else in
	 * the request.
	 *
	 * @param mixed  $tags    Allowed-HTML map from the wp_kses_allowed_html filter.
	 * @param string $context KSES context.
	 * @return mixed The semantically equivalent map in an Elementor-safe shape.
	 */
	public static function normalize_kses_allowed_html( $tags, $context = '' ) {
		if ( 'post' !== $context || ! is_array( $tags ) ) {
			return $tags;
		}

		foreach ( $tags as $tag => $attributes ) {
			if ( null === $attributes ) {
				unset( $tags[ $tag ] );
				continue;
			}

			if ( ! is_array( $attributes ) && ( true === $attributes || empty( $attributes ) ) ) {
				$tags[ $tag ] = array();
			}
		}

		return $tags;
	}

	/**
	 * Whether a decoded value is a strict legacy Elementor leaf widget.
	 *
	 * Elementor 4.2.4 may materialize an omitted `elements` field as an empty
	 * list while rebuilding a widget. That is harmless only on a recognizable
	 * leaf: containers can carry children, malformed objects must stay visible,
	 * and element-shaped values inside arbitrary settings maps are not elements.
	 *
	 * @param mixed $element Candidate decoded element.
	 * @return bool Whether the value is a strict leaf widget.
	 */
	private function is_strict_elementor_leaf_widget( $element ) {
		if ( ! is_array( $element )
			|| ! isset( $element['id'], $element['elType'], $element['widgetType'], $element['settings'] )
			|| ! is_string( $element['id'] )
			// Elementor's own IDs are short hex strings, while existing MCPWP
			// libraries also contain stable alphanumeric IDs of other lengths.
			// Identity is compared exactly after this shape check, so length is not
			// a safety boundary; requiring it made the live six-character `hbev1d`
			// leaf miss the harmless empty-elements normalization (#769).
			|| 1 !== preg_match( '/^[A-Za-z0-9]+\z/', $element['id'] )
			|| 'widget' !== $element['elType']
			|| ! is_string( $element['widgetType'] )
			|| '' === $element['widgetType']
			|| ! is_array( $element['settings'] )
		) {
			return false;
		}

		return ! array_key_exists( 'elements', $element )
			|| ( is_array( $element['elements'] ) && empty( $element['elements'] ) );
	}

	/**
	 * Compare decoded Elementor values while preserving list order and ignoring
	 * associative-object key order.
	 *
	 * @param mixed  $expected Submitted Elementor value.
	 * @param mixed  $actual   Value Elementor wrote back.
	 * @param string $context  Traversal context: elements, element, or value.
	 * @return bool Whether both values are semantically identical.
	 */
	private function elementor_values_match( $expected, $actual, $context = 'elements' ) {
		if ( ! is_array( $expected ) || ! is_array( $actual ) ) {
			return $expected === $actual;
		}

		$expected_is_list = empty( $expected )
			|| array_keys( $expected ) === range( 0, count( $expected ) - 1 );
		$actual_is_list   = empty( $actual )
			|| array_keys( $actual ) === range( 0, count( $actual ) - 1 );

		if ( $expected_is_list !== $actual_is_list ) {
			return false;
		}

		// Elementor 4.2.4 can add `elements: []` to a leaf widget during
		// Document::save(). Treat the absent and empty spellings as equivalent only
		// for two strict element-list nodes. The traversal context prevents a
		// lookalike object inside a widget setting from receiving this exception.
		if ( 'element' === $context
			&& $this->is_strict_elementor_leaf_widget( $expected )
			&& $this->is_strict_elementor_leaf_widget( $actual )
		) {
			if ( ! array_key_exists( 'elements', $expected ) ) {
				$expected['elements'] = array();
			}
			if ( ! array_key_exists( 'elements', $actual ) ) {
				$actual['elements'] = array();
			}
		}

		// Elementor's Element_Base::get_raw_data() materializes an omitted
		// `isInner` default as boolean false. Widget_Base removes the field again.
		// Both shapes describe the same element, and a saved library part can carry
		// either one. Normalize only that framework-owned default on recognizable
		// element objects; IDs, types, order, settings, content and true inner-state
		// remain exact integrity checks.
		if ( 'element' === $context
			&& ! $expected_is_list
			&& isset( $expected['id'], $expected['elType'], $actual['id'], $actual['elType'] )
			&& is_string( $expected['id'] )
			&& is_string( $expected['elType'] )
			&& is_string( $actual['id'] )
			&& is_string( $actual['elType'] )
			&& isset( $expected['settings'], $expected['elements'], $actual['settings'], $actual['elements'] )
			&& is_array( $expected['settings'] )
			&& is_array( $expected['elements'] )
			&& is_array( $actual['settings'] )
			&& is_array( $actual['elements'] )
		) {
			if ( ! array_key_exists( 'isInner', $expected ) ) {
				$expected['isInner'] = false;
			}
			if ( ! array_key_exists( 'isInner', $actual ) ) {
				$actual['isInner'] = false;
			}
		}

		if ( count( $expected ) !== count( $actual ) ) {
			return false;
		}

		foreach ( $expected as $key => $value ) {
			$child_context = 'value';
			if ( 'elements' === $context && $expected_is_list ) {
				$child_context = 'element';
			} elseif ( 'element' === $context && 'elements' === $key ) {
				$child_context = 'elements';
			}

			if ( ! array_key_exists( $key, $actual ) || ! $this->elementor_values_match( $value, $actual[ $key ], $child_context ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * List the Elementor calls that threw or returned WP_Error during a write.
	 *
	 * attempt_elementor_call() records each caught Throwable under
	 * "<label>_exception" and each returned WP_Error under "<label>_error".
	 * This turns those entries into flat, readable strings so a caller sees what
	 * failed without digging through debug. A successful final HTML-cache rebuild
	 * supersedes an initial document-save failure because the later save proves
	 * the persisted elements reached Elementor's render pipeline.
	 *
	 * @param array $debug Debug bag from a write.
	 * @return string[] One entry per Elementor call that threw; empty when none did.
	 */
	private function collect_render_errors( array $debug ) {
		$errors                    = array();
		$initial_save_was_recovered = true === ( $debug['html_cache_rebuilt'] ?? false );

		if ( ! empty( $debug['html_rebuild_corrupted_data'] ) ) {
			$errors[] = sprintf(
				'html_cache_rebuild: Elementor rendered a truncated or otherwise changed tree (%d of %d elements); the restored meta has not been rendered.',
				isset( $debug['html_rebuild_elements_after'] ) ? (int) $debug['html_rebuild_elements_after'] : 0,
				isset( $debug['html_rebuild_elements_before'] ) ? (int) $debug['html_rebuild_elements_before'] : 0
			);
		}

		foreach ( $debug as $key => $value ) {
			if ( $initial_save_was_recovered && in_array( $key, array( 'document_save_exception', 'document_save_error' ), true ) ) {
				continue;
			}

			if ( '_exception' === substr( (string) $key, -10 ) ) {
				if ( ! is_array( $value ) || ! isset( $value['class'], $value['message'], $value['at'] ) ) {
					continue;
				}

				$errors[] = sprintf(
					'%s: %s: %s at %s',
					substr( (string) $key, 0, -10 ),
					$value['class'],
					$value['message'],
					$value['at']
				);
				continue;
			}

			if ( '_error' === substr( (string) $key, -6 ) && is_string( $value ) && '' !== $value ) {
				$errors[] = sprintf(
					'%s: WP_Error: %s',
					substr( (string) $key, 0, -6 ),
					$value
				);
			}
		}

		return $errors;
	}

	/**
	 * Flag a post as built with Elementor, so Elementor renders its data.
	 *
	 * The flag is the `_elementor_edit_mode` meta, read through
	 * Document::is_built_with_elementor(). Elementor's frontend checks it
	 * before rendering anything, and Document::save() does not set it — only
	 * the editor does. Writing data without it produces a page that stores
	 * everything correctly and displays none of it.
	 *
	 * Prefers Elementor's own setter so the meaning of the flag stays
	 * Elementor's to define, and falls back to the meta write when the
	 * document API is unavailable — which is the same situation in which the
	 * rest of this class falls back to raw meta.
	 *
	 * @param int $page_id Post ID.
	 * @return bool True when the post is flagged after this call.
	 */
	private function mark_built_with_elementor( $page_id, array &$debug = null ) {
		// A local bag only when the caller did not offer one. Everywhere else
		// in this file attempt_elementor_call() gets the shared $debug, and
		// its contract is that a caught Throwable lands under
		// <label>_exception — "an Elementor API that failed and one that
		// worked must not produce the same response". Discarding the bag here
		// meant a documents->get() that throws (the Elementor 4.2.4 / PHP 8.4
		// case this file documents) fell through to the raw meta write, that
		// write succeeded, and the response said renders_via_elementor:true
		// with no trace that Elementor's document API is broken.
		if ( null === $debug ) {
			$debug = array();
		}

		if ( class_exists( '\Elementor\Plugin' ) && ! empty( \Elementor\Plugin::$instance->documents ) ) {
			$attempt = $this->attempt_elementor_call(
				static function () use ( $page_id ) {
					$document = \Elementor\Plugin::$instance->documents->get( $page_id, false );

					if ( ! $document || ! method_exists( $document, 'set_is_built_with_elementor' ) ) {
						return false;
					}

					$document->set_is_built_with_elementor( true );

					return true;
				},
				$debug,
				'builder_flag'
			);

			// Fall through to the raw write rather than returning, when the
			// setter ran but the value did not land. Elementor's setter
			// ignores update_meta()'s return value, so its closure reports
			// success regardless — meaning the one failure mode the closure
			// cannot see would otherwise be the only one with no retry.
			if ( $attempt['ok'] && true === $attempt['value']
				&& 'builder' === get_post_meta( $page_id, '_elementor_edit_mode', true ) ) {
				return true;
			}
		}

		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );

		// Read it back rather than trusting update_post_meta's return, which
		// is false both when the write failed and when the value was already
		// what we asked for.
		return 'builder' === get_post_meta( $page_id, '_elementor_edit_mode', true );
	}

	/**
	 * State, at the top level of a write response, whether the page actually renders.
	 *
	 * Persisting _elementor_data and rendering the page are separate outcomes, and
	 * before this they collapsed into one `success: true`. A page whose data is on
	 * disk but whose Elementor render pipeline threw is not a delivered page: it
	 * has no HTML cache, and depending on which call failed it may not display as
	 * an Elementor layout at all. Reporting that as an unqualified success is the
	 * same defect class as an unavailable source returning an empty result (#765).
	 *
	 * `success` stays true because the data really was written and verified — the
	 * caller must not be told to retry a completed write. `render_verified` is the
	 * field that says whether the result is usable.
	 *
	 * @param array $result Response being built.
	 * @param array $debug  Debug bag from the write.
	 * @return array $result with render_verified, render_errors and a warning.
	 */
	private function decorate_render_status( array $result, array $debug ) {
		$render_errors    = $this->collect_render_errors( $debug );
		$render_completed = ! empty( $debug['document_save'] ) || true === ( $debug['html_cache_rebuilt'] ?? false );

		if ( empty( $render_errors ) && ! $render_completed ) {
			$render_errors[] = 'render_pipeline: No completed Elementor render call was observed; the data is saved but its rendered output is unverified.';
		}

		// Whether Elementor will render this page at all.
		//
		// Everything above is about calls that did not throw. That is not the
		// same as output existing, and the gap was reported: a page answered
		// render_verified:true while Elementor produced zero bytes for it,
		// because the document was never flagged as built with Elementor
		// (#785). A verification that cannot fail on a page rendering nothing
		// is not a verification.
		if ( array_key_exists( 'built_with_elementor', $debug ) ) {
			$result['renders_via_elementor'] = (bool) $debug['built_with_elementor'];

			if ( ! $debug['built_with_elementor'] ) {
				$render_errors[] = 'builder_flag: The post is not marked as built with Elementor, so Elementor will not render its data — the content will appear as unstyled HTML.';
			}
		}

		$result['render_verified'] = empty( $render_errors );

		if ( empty( $render_errors ) ) {
			return $result;
		}

		$result['render_errors'] = $render_errors;
		$result['message']       = __( 'Elementor data saved, but the page did not finish rendering — see render_errors.', 'mcpwp' );

		$warning = sprintf(
			/* translators: %s: semicolon-separated list of Elementor errors. */
			__( 'Elementor could not complete the render pipeline, so this page is saved but may not display as an Elementor layout. Verify it before treating the change as delivered. Errors: %s', 'mcpwp' ),
			implode( '; ', $render_errors )
		);

		$warnings   = isset( $result['warnings'] ) && is_array( $result['warnings'] ) ? $result['warnings'] : array();
		$warnings[] = $warning;

		$result['warnings'] = $warnings;

		return $result;
	}

	/**
	 * Set Elementor data for a page.
	 *
	 * @param int   $page_id  Page ID.
	 * @param array $data     Elementor data.
	 * @param bool  $dry_run  If true, validate only — no DB writes, no cache clear.
	 * @return array|WP_Error Result or error.
	 */
	public function set_elementor_data( $page_id, $data, $dry_run = false ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mcpwp' ),
				array(
					'status' => 400,
					'hint'   => 'Elementor is not installed or active on this site. Use wp_introspect to check available page builders. For content editing without Elementor, use wp_update_page with HTML content, or wp_set_blocks for Gutenberg.',
				)
			);
		}

		$page = $this->validate_post( $page_id );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		// Determine layout mode for hints.
		$layout_hint = '';
		if ( class_exists( 'Mcpwp_Error_Hints' ) ) {
			$experiment   = get_option( 'elementor_experiment-container', '' );
			$is_flexbox   = in_array( $experiment, array( 'active', 'default' ), true );
			$layout_hint  = $is_flexbox
				? 'This site uses flexbox layout mode. Use "container" as the top-level elType.'
				: 'This site uses classic layout mode. Use "section" > "column" > widget structure.';
		}

		$structure_hint = 'Elementor data must be a JSON array of element objects. Each element needs: id (8-char alphanumeric), elType ("section"/"column"/"widget" or "container"), settings (object), elements (array). ' . $layout_hint . ' Use wp_get_elementor on an existing page to see the expected format.';

		// Validate and encode data
		$elementor_json = null;

		// --- Base64-encoded payload (bypass all quoting/escaping issues) ---
		if ( ! empty( $data['elementor_data_base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$raw_decoded = base64_decode( $data['elementor_data_base64'], true );
			if ( false === $raw_decoded ) {
				return new WP_Error(
					'invalid_base64',
					__( 'Invalid base64 encoding in elementor_data_base64.', 'mcpwp' ),
					array(
						'status' => 400,
						'hint'   => 'The elementor_data_base64 value is not valid base64. Encode your JSON string with base64_encode() or btoa() before sending.',
					)
				);
			}
			$decoded = json_decode( $raw_decoded, true );
			if ( null === $decoded && json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error(
					'invalid_json',
					__( 'Base64-decoded data is not valid JSON.', 'mcpwp' ),
					array(
						'status'          => 400,
						'decoded_length'  => strlen( $raw_decoded ),
						'hint'            => 'Decoded ' . strlen( $raw_decoded ) . ' bytes but JSON parse failed: ' . json_last_error_msg() . '. If the base64 string was LLM-generated, it may have been truncated mid-stream. Try wp_set_elementor with elementor_data (plain JSON) for payloads under 100KB, or split into sections with wp_add_section.',
						'guide'           => 'wp_get_guide(topic=\'elementor\')',
					)
				);
			}
			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'invalid_structure',
					__( 'Elementor data must decode to an array.', 'mcpwp' ),
					array(
						'status' => 400,
						'hint'   => $structure_hint,
						'guide'  => 'wp_get_guide(topic=\'elementor\')',
					)
				);
			}
			$elementor_json = $raw_decoded;
		} elseif ( isset( $data['elementor_data'] ) ) {
			// If array, validate structure and encode to JSON
			if ( is_array( $data['elementor_data'] ) ) {
				if ( ! $this->is_valid_elementor_structure( $data['elementor_data'] ) ) {
					return new WP_Error(
						'invalid_structure',
						__( 'Elementor data must be an array of element objects.', 'mcpwp' ),
						array(
							'status' => 400,
							'hint'   => $structure_hint,
							'guide'  => 'wp_get_guide(topic=\'elementor\')',
						)
					);
				}
				$elementor_json = wp_json_encode( $data['elementor_data'] );
			} else {
				// Validate JSON string — with recovery for common MCP corruption.
				$decoded = $this->try_json_decode( $data['elementor_data'] );
				if ( null === $decoded ) {
					return new WP_Error(
						'invalid_json',
						__( 'Invalid Elementor JSON data.', 'mcpwp' ),
						array(
							'status' => 400,
							'hint'   => 'The provided string is not valid JSON even after recovery attempts (stripslashes, double-encoding unwrap). Error: ' . json_last_error_msg() . '. Consider using elementor_data_base64 to avoid quoting issues — base64-encode your JSON before sending.',
							'guide'  => 'wp_get_guide(topic=\'elementor\')',
						)
					);
				}
				if ( ! is_array( $decoded ) ) {
					return new WP_Error(
						'invalid_structure',
						__( 'Elementor data must decode to an array.', 'mcpwp' ),
						array(
							'status' => 400,
							'hint'   => $structure_hint,
							'guide'  => 'wp_get_guide(topic=\'elementor\')',
						)
					);
				}
				// Re-encode from decoded data to ensure clean JSON.
				$elementor_json = wp_json_encode( $decoded );
			}
		} elseif ( isset( $data['elementor_json'] ) ) {
			// Direct JSON string — with recovery.
			$decoded = $this->try_json_decode( $data['elementor_json'] );
			if ( null === $decoded ) {
				return new WP_Error(
					'invalid_json',
					__( 'Invalid Elementor JSON data.', 'mcpwp' ),
					array(
						'status' => 400,
						'hint'   => 'The provided string is not valid JSON even after recovery attempts. Error: ' . json_last_error_msg() . '. Consider using elementor_data_base64 to avoid quoting issues.',
						'guide'  => 'wp_get_guide(topic=\'elementor\')',
					)
				);
			}
			if ( ! is_array( $decoded ) ) {
				return new WP_Error(
					'invalid_structure',
					__( 'Elementor data must decode to an array.', 'mcpwp' ),
					array(
						'status' => 400,
						'hint'   => $structure_hint,
						'guide'  => 'wp_get_guide(topic=\'elementor\')',
					)
				);
			}
			// Re-encode from decoded data to ensure clean JSON.
			$elementor_json = wp_json_encode( $decoded );
		}

		// Validate JSON size and nesting depth (prevent DoS).
		if ( ! empty( $elementor_json ) && class_exists( 'Mcpwp_Security' ) ) {
			$size_check = Mcpwp_Security::validate_json_payload( $elementor_json, 5 * 1024 * 1024, 30 );
			if ( is_wp_error( $size_check ) ) {
				return $size_check;
			}
		}

		if ( empty( $elementor_json ) ) {
			return new WP_Error(
				'no_data',
				__( 'No Elementor data provided.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		// --- Layout-mode mismatch guard (#612) ---
		// 'container' elements only render/persist correctly when the site's
		// Elementor Container experiment is active. On a site still in classic
		// 'section' mode, a container payload passes Document::save() once, but a
		// later internal re-save (used to rebuild post_content/HTML cache, below)
		// can silently drop elements Elementor's own registry doesn't recognize as
		// valid in that mode — producing a false "success" followed by an empty
		// page on readback, with no error surfaced anywhere. Catch it up front.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$container_experiment = get_option( 'elementor_experiment-container', '' );
			$containers_active    = in_array( $container_experiment, array( 'active', 'default' ), true );
			if ( ! $containers_active ) {
				$decoded_for_check = json_decode( $elementor_json, true );
				if ( is_array( $decoded_for_check ) && $this->elementor_tree_contains_type( $decoded_for_check, 'container' ) ) {
					return new WP_Error(
						'layout_mode_mismatch',
						__( 'This site is in classic "section" layout mode (Elementor\'s Container experiment is not active), but the submitted data uses "container" elements. Container elements are not supported in this mode and will be silently lost after saving. Rebuild the payload as section > column(_column_size) > widget instead, or ask the site admin to enable it under Elementor > Settings > Experiments > Container.', 'mcpwp' ),
						array(
							'status' => 400,
						'hint'   => 'Always check elementor_layout_mode from wp_site_info before building a page — this site currently reports "section".',
							'guide'  => 'wp_get_guide(topic=\'elementor\')',
						)
					);
				}
			}

			// --- Atomic layout-mode mismatch guard (#618/#619) ---
			// Same failure class as the container guard above: Elementor v4 Atomic
			// elements (e-div-block, e-heading, etc.) only persist correctly when the
			// site's 'e_atomic_elements' experiment is active. Unlike container mode,
			// atomic is additive — it can be on alongside section or container mode —
			// so this check is independent of the containers_active check above.
			$atomic_experiment = get_option( 'elementor_experiment-e_atomic_elements', '' );
			$atomic_active     = in_array( $atomic_experiment, array( 'active', 'default' ), true );
			if ( ! $atomic_active ) {
				$decoded_for_atomic_check = json_decode( $elementor_json, true );
				$known_atomic_types       = array( 'e-div-block', 'e-flexbox', 'e-grid', 'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg', 'e-divider', 'e-youtube', 'e-self-hosted-video' );
				if ( is_array( $decoded_for_atomic_check ) ) {
					foreach ( $known_atomic_types as $atomic_type ) {
						if ( $this->elementor_tree_contains_type( $decoded_for_atomic_check, $atomic_type ) ) {
							return new WP_Error(
								'layout_mode_mismatch',
								__( 'This site does not have Elementor\'s Atomic Elements experiment active, but the submitted data uses atomic element types (e.g. e-div-block, e-heading). Atomic elements are not supported until enabled and will be silently lost after saving. Rebuild the payload with classic section/container elements instead, or ask the site admin to enable it under Elementor > Settings > Experiments > Atomic Elements.', 'mcpwp' ),
								array(
									'status'      => 400,
									'atomic_type' => $atomic_type,
									'hint'        => 'Always check elementor_layout_mode from wp_site_info before building a page — this site does not currently report "atomic".',
									'guide'       => 'wp_get_guide(topic=\'elementor\')',
								)
							);
						}
					}
				}
			}
		}

		// --- Dry-run mode: validate only, no DB writes ---
		if ( $dry_run ) {
			$dry_json   = $elementor_json; // work on a copy
			$validation = $this->validate_and_fix_elements( $dry_json );

			if ( ! empty( $validation['errors'] ) ) {
				return new WP_Error(
					'invalid_elementor_structure',
					implode( ' | ', $validation['errors'] ),
					array(
						'status'            => 400,
						'validation_errors' => $validation['errors'],
					)
				);
			}

			$all_warnings = array_merge(
				$validation['warnings'],
				$validation['fixes']
			);

			// Check RTL issues if site is RTL.
			if ( function_exists( 'is_rtl' ) && is_rtl() ) {
				$rtl_warnings = $this->check_rtl_issues( $dry_json );
				$all_warnings = array_merge( $all_warnings, $rtl_warnings );
			}

			$result = array(
				'success'  => true,
				'dry_run'  => true,
				'page_id'  => (int) $page_id,
				'message'  => __( 'Validation complete — no changes saved.', 'mcpwp' ),
			);

			if ( ! empty( $all_warnings ) ) {
				$result['warnings'] = $all_warnings;
			}

			$result['debug'] = array(
				'validation_fixes'    => $validation['fixes'],
				'validation_warnings' => $validation['warnings'],
			);

			return $result;
		}

		// Capture current structure for before/after diff.
		$existing_raw      = get_post_meta( $page_id, '_elementor_data', true );
		$existing_decoded  = $existing_raw ? json_decode( $existing_raw, true ) : null;
		$sections_before   = is_array( $existing_decoded ) ? count( $existing_decoded ) : 0;
		$widgets_before    = is_array( $existing_decoded ) ? $this->count_elementor_widgets( $existing_decoded ) : 0;

		$save_debug    = array();
		$elementor_ok  = class_exists( '\Elementor\Plugin' );

		// --- 1. Set ALL required Elementor meta keys ---

		// Page template.
		$current_template = get_post_meta( $page_id, '_wp_page_template', true );
		if ( ! $current_template || 'default' === $current_template ) {
			update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );
		}

		// Edit mode must be 'builder', and the result has to be recorded.
		//
		// This was a bare write whose return nobody looked at and which put
		// nothing in the debug bag, so decorate_render_status()'s check —
		// keyed on `built_with_elementor` being present — was skipped for
		// wp_set_elementor, the most-used write tool of all. On a host where
		// the meta write silently no-ops it answered render_verified:true for
		// a page rendering zero bytes: the same defect the surgical paths were
		// just fixed for, on the busier path.
		//
		// Only when there is something to render. Flagging an empty write
		// would contradict the rule the surgical paths follow, and leave
		// wp_set_elementor(id, "[]") claiming a page for Elementor while
		// removing its last section does not — one operation, two tools,
		// opposite semantics.
		$incoming_elements = json_decode( $elementor_json, true );

		if ( is_array( $incoming_elements ) && ! empty( $incoming_elements ) ) {
			$save_debug['built_with_elementor'] = $this->mark_built_with_elementor( $page_id, $save_debug );
		}

		// Template type — required for frontend rendering.
		$template_type = get_post_meta( $page_id, '_elementor_template_type', true );
		if ( empty( $template_type ) ) {
			$post_type = get_post_type( $page_id );
			$type_value = ( 'elementor_library' === $post_type ) ? 'section' : 'wp-page';
			update_post_meta( $page_id, '_elementor_template_type', $type_value );
			$save_debug['set_template_type'] = $type_value;
		}

		// Elementor version — prevents unnecessary migrations.
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
			$save_debug['elementor_version'] = ELEMENTOR_VERSION;
		}

		// Pro version — required for Pro widget rendering.
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_pro_version', ELEMENTOR_PRO_VERSION );
			$save_debug['elementor_pro_version'] = ELEMENTOR_PRO_VERSION;
		}

		// --- 2. Validate and fix element tree BEFORE writing ---

		$input_count = count( json_decode( $elementor_json, true ) ?: array() );
		$save_debug['sections_submitted'] = $input_count;

		$validation = $this->validate_and_fix_elements( $elementor_json );
		if ( ! empty( $validation['errors'] ) ) {
			return new WP_Error(
				'invalid_elementor_structure',
				implode( ' | ', $validation['errors'] ),
				array(
					'status'            => 400,
					'validation_errors' => $validation['errors'],
				)
			);
		}
		if ( ! empty( $validation['fixes'] ) || ! empty( $validation['warnings'] ) ) {
			$save_debug['validation_fixes']    = $validation['fixes'];
			$save_debug['validation_warnings'] = $validation['warnings'];
		}

		// Safety check: ensure validation did not corrupt or truncate the data.
		$post_validation_decoded = json_decode( $elementor_json, true );
		if ( ! is_array( $post_validation_decoded ) ) {
			return new WP_Error(
				'validation_corrupted',
				__( 'Elementor data was corrupted during validation. Please retry with elementor_data_base64.', 'mcpwp' ),
				array( 'status' => 500, 'sections_submitted' => $input_count )
			);
		}
		$post_validation_count = count( $post_validation_decoded );
		if ( $post_validation_count !== $input_count ) {
				return new WP_Error(
					'validation_data_loss',
					sprintf(
						/* translators: 1: submitted section count, 2: section count after validation */
						__( 'Validation changed section count from %1$d to %2$d. Aborting to prevent data loss.', 'mcpwp' ),
						$input_count,
						$post_validation_count
					),
				array( 'status' => 500, 'sections_submitted' => $input_count, 'sections_after_validation' => $post_validation_count )
			);
		}

		// --- 3. Save via Elementor Document API (preferred) or meta fallback ---

		$save_method   = 'meta_direct';
		$document_saved = false;

		if ( $elementor_ok ) {
			// Try Document::save() — this updates _elementor_data, post_content, and CSS in one go.
			$documents_manager = \Elementor\Plugin::$instance->documents;
			if ( $documents_manager && method_exists( $documents_manager, 'get' ) ) {
				wp_cache_delete( $page_id, 'post_meta' );
				clean_post_cache( $page_id );

				$document = $documents_manager->get( $page_id, false );
				if ( $document && method_exists( $document, 'save' ) ) {
					// A throw here must fall through to the raw-meta fallback
					// below, not abort the request — see attempt_elementor_call().
					$attempt = $this->attempt_elementor_call(
						static function () use ( $document, $post_validation_decoded ) {
							return $document->save( array( 'elements' => $post_validation_decoded ) );
						},
						$save_debug,
						'document_save'
					);

					$save_result = $attempt['value'];

					if ( $attempt['ok'] && ! is_wp_error( $save_result ) ) {
						// (#198) Always overwrite raw meta after Document::save() to
						// prevent revision/cache divergence on subsequent reads.
						update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );

						wp_cache_delete( $page_id, 'post_meta' );
						clean_post_cache( $page_id );
						if ( function_exists( 'wp_cache_flush_group' ) ) {
							wp_cache_flush_group( 'post_meta' );
						}

						$stored_after_document = get_post_meta( $page_id, '_elementor_data', true );
						$decoded_after_document = json_decode( $stored_after_document, true );
						$stored_after_count     = is_array( $decoded_after_document ) ? count( $decoded_after_document ) : 0;

						if ( $stored_after_count === $input_count ) {
							$document_saved              = true;
							$save_method                 = 'document_save_with_meta_overwrite';
							$save_debug['document_save'] = true;
						} else {
							$save_debug['document_save_persist_mismatch'] = array(
								'sections_expected' => $input_count,
								'sections_saved'    => $stored_after_count,
							);
						}
					} elseif ( is_wp_error( $save_result ) ) {
						$save_debug['document_save_error'] = $save_result->get_error_message();
					}
					// When the call threw, $save_result is null and the throw is
					// already recorded as document_save_exception. There is no else
					// branch on purpose: get_error_message() on null would replace
					// one fatal with another.
				}
			}
		}

		// Fallback: direct meta write if Document::save() is unavailable or failed.
		if ( ! $document_saved ) {
			global $wpdb;
			update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );
			$save_debug['meta_written'] = true;
			if ( $wpdb->last_error ) {
				// (#612) update_post_meta() swallows $wpdb errors — a query that fails
				// (e.g. max_allowed_packet on cheap shared hosting) still returns from
				// update_post_meta() silently. Surface it so we don't report a false success.
				$save_debug['db_error_on_write'] = $wpdb->last_error;
			}

			// (#187) Update post_content so Elementor's front-end renderer doesn't
			// short-circuit on stale/empty post_content from a previous save attempt.
			wp_update_post( array(
				'ID'           => $page_id,
				'post_content' => '',
			) );

			// (#187) Flush Elementor's in-memory document cache. Document::save() may
			// have cached an empty elements array; force a fresh load from the DB so
			// subsequent CSS regeneration and any same-request renders see the new data.
			if ( $elementor_ok && ! empty( \Elementor\Plugin::$instance->documents ) ) {
				clean_post_cache( $page_id );
				wp_cache_delete( $page_id, 'post_meta' );
				\Elementor\Plugin::$instance->documents->get( $page_id, false );
				$save_debug['document_cache_flushed'] = true;
			}
		}

		// Verify data was stored correctly — read the raw DB row directly instead
		// of get_post_meta(). (#612) get_post_meta() can be served by a persistent
		// object cache (Redis, LiteSpeed Object Cache, etc.) that was populated
		// optimistically when update_post_meta() was called, independent of whether
		// the underlying UPDATE actually committed. On hosts where that write
		// silently fails (locks, max_allowed_packet, disk quota) the cache can keep
		// reporting the intended value while the DB row never changed — which made
		// this exact check lie about success. Querying $wpdb directly bypasses any
		// such cache and reflects only what MySQL actually has right now.
		global $wpdb;
		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' ORDER BY meta_id DESC LIMIT 1",
				$page_id
			)
		);
		$db_error_on_verify = $wpdb->last_error ?: null;
		wp_cache_delete( $page_id, 'post_meta' ); // Keep WP's cache in sync with the DB truth we just read.

		$stored_decoded = $stored ? json_decode( $stored, true ) : null;
		$stored_count   = is_array( $stored_decoded ) ? count( $stored_decoded ) : 0;
		$save_debug['meta_verified']    = ( $stored_count === $input_count );
		$save_debug['sections_saved']   = $stored_count;
		if ( $db_error_on_verify ) {
			$save_debug['db_error_on_verify'] = $db_error_on_verify;
		}

		if ( $stored_count !== $input_count ) {
				return new WP_Error(
					'meta_write_truncated',
					sprintf(
						/* translators: 1: submitted section count, 2: stored section count */
						__( 'Data truncated during save: %1$d sections submitted but only %2$d stored. Try using elementor_data_base64 for large payloads.', 'mcpwp' ),
						$input_count,
						$stored_count
					),
				array(
					'status'              => 500,
					'sections_submitted'  => $input_count,
					'sections_saved'      => $stored_count,
					'hint'                => 'Base64-encode your JSON and send via elementor_data_base64 parameter to bypass size limits.',
					'db_error'            => $db_error_on_verify,
				)
			);
		}

		// --- 4. Rebuild CSS + HTML rendering cache ---
		// Always regenerate — meta overwrite may leave post_content (HTML cache) stale.

		if ( $elementor_ok ) {
			if ( ! empty( \Elementor\Plugin::$instance->files_manager ) ) {
				$cleared = $this->attempt_elementor_call(
					static function () {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
						return true;
					},
					$save_debug,
					'cache_clear'
				);
				$save_debug['cache_cleared'] = $cleared['ok'];
			}

			delete_post_meta( $page_id, '_elementor_css' );

			// Force fresh document re-save to rebuild post_content (HTML rendering).
			if ( ! empty( \Elementor\Plugin::$instance->documents ) ) {
				$fresh_doc = \Elementor\Plugin::$instance->documents->get( $page_id, false );
				if ( $fresh_doc && method_exists( $fresh_doc, 'save' ) ) {
					$fresh_elements = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
					if ( is_array( $fresh_elements ) && ! empty( $fresh_elements ) ) {
						// Keep the recursive total for diagnostics. The semantic comparison
						// below also catches same-size changes to IDs, types, order, settings,
						// and content.
						$pre_rebuild_total = $this->count_elements_recursive( $fresh_elements );

						// The data is already persisted and verified at this point, so a
						// throw here costs only the rendering cache — it must never turn a
						// completed save into an HTTP 500.
						$rebuild = $this->attempt_elementor_call(
							static function () use ( $fresh_doc, $fresh_elements ) {
								return $fresh_doc->save( array( 'elements' => $fresh_elements ) );
							},
							$save_debug,
							'html_cache_rebuild'
						);
						$save_debug['html_cache_rebuilt'] = $rebuild['ok'];
						if ( ! $rebuild['ok'] ) {
							// Elementor may have partially written before throwing. Restore
							// our known-good bytes, exactly as the corruption guard below does.
							update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );
							wp_cache_delete( $page_id, 'post_meta' );
						}

						// (#612, #618) This is Elementor's own save path, not ours — if its
						// internal element registry silently drops anything (e.g. element
						// types/props it doesn't recognize as valid), it can overwrite
						// _elementor_data with less content than we just verified, with no
						// error surfaced anywhere. Re-check (recursively, whole tree — not
						// just top-level count) and restore our known-good bytes rather than
						// let a "successful" write end up partially or fully empty on disk.
						$post_rebuild = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' ORDER BY meta_id DESC LIMIT 1",
								$page_id
							)
						);
						$post_rebuild_decoded = $post_rebuild ? json_decode( $post_rebuild, true ) : null;
						$post_rebuild_total    = is_array( $post_rebuild_decoded ) ? $this->count_elements_recursive( $post_rebuild_decoded ) : 0;
						if ( ! $this->elementor_values_match( $fresh_elements, $post_rebuild_decoded ) ) {
							update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );
							wp_cache_delete( $page_id, 'post_meta' );
							$save_debug['html_rebuild_corrupted_data'] = true;
							$save_debug['html_rebuild_elements_before'] = $pre_rebuild_total;
							$save_debug['html_rebuild_elements_after']  = $post_rebuild_total;
						}
					}
				}
			}

			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				// Same Elementor API surface as the saves above: an unguarded throw
				// here would still 500 a request whose data is already persisted.
				$css_regen = $this->attempt_elementor_call(
					static function () use ( $page_id ) {
						$css_file = \Elementor\Core\Files\CSS\Post::create( $page_id );
						$css_file->update();
						return true;
					},
					$save_debug,
					'css_regeneration'
				);
				$save_debug['css_regenerated'] = $css_regen['ok'];
			}
		}

		// Report CSS file size for debugging.
		if ( $elementor_ok ) {
			$upload_dir = wp_upload_dir();
			$css_path   = $upload_dir['basedir'] . '/elementor/css/post-' . $page_id . '.css';
			$css_size   = file_exists( $css_path ) ? filesize( $css_path ) : 0;
			$save_debug['css_file_size'] = $css_size;

			// If CSS is still empty/tiny, prime it via loopback request.
			if ( $css_size < 10 ) {
				delete_post_meta( $page_id, '_elementor_css' );
				$save_debug['css_deferred'] = true;
				$permalink = get_permalink( $page_id );
				if ( $permalink ) {
					wp_remote_get(
						add_query_arg( 'mcpwp_prime_css', wp_rand(), $permalink ),
						array(
							'timeout'   => 15,
							'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
							'blocking'  => false,
						)
					);
					$save_debug['css_primed'] = true;
				}
			}

			// Also regenerate the global CSS (kit styles) if applicable.
			if ( ! $document_saved && class_exists( '\Elementor\Core\Files\CSS\Global_CSS' ) ) {
				$global_css = \Elementor\Core\Files\CSS\Global_CSS::create( 'global.css' );
				if ( $global_css ) {
					$global_css->update();
					$save_debug['global_css_regenerated'] = true;
				}
			}
		}

		// --- 5. Page-level settings (custom CSS, etc.) (#81) ---

		if ( ! empty( $data['page_settings'] ) && is_array( $data['page_settings'] ) ) {
			$allowed_keys = array( 'custom_css', 'background_background', 'background_color', 'padding', 'hide_title' );
			$page_settings = get_post_meta( $page_id, '_elementor_page_settings', true );
			if ( ! is_array( $page_settings ) ) {
				$page_settings = array();
			}
			foreach ( $data['page_settings'] as $key => $value ) {
				if ( in_array( $key, $allowed_keys, true ) ) {
					$page_settings[ $key ] = $value;
				}
			}
			update_post_meta( $page_id, '_elementor_page_settings', $page_settings );
			$save_debug['page_settings_updated'] = array_keys( $data['page_settings'] );
		}

		// --- 6. Purge page caches (#89) ---

		$this->purge_page_cache( $page_id );
		$save_debug['page_cache_purged'] = true;

		// --- 7. Check RTL issues if site is RTL ---

		if ( function_exists( 'is_rtl' ) && is_rtl() ) {
			$rtl_warnings = $this->check_rtl_issues( $elementor_json );
			if ( ! empty( $rtl_warnings ) ) {
				$save_debug['rtl_warnings'] = $rtl_warnings;
			}
		}

		// Build top-level warnings from validation results.
		$all_warnings = array();
		if ( ! empty( $save_debug['validation_warnings'] ) ) {
			$all_warnings = array_merge( $all_warnings, $save_debug['validation_warnings'] );
		}
		if ( ! empty( $save_debug['validation_fixes'] ) ) {
			$all_warnings = array_merge( $all_warnings, $save_debug['validation_fixes'] );
		}
		if ( ! empty( $save_debug['rtl_warnings'] ) ) {
			$all_warnings = array_merge( $all_warnings, $save_debug['rtl_warnings'] );
		}

		$widgets_after = $this->count_elementor_widgets( $post_validation_decoded );

		$result = array(
			'success'            => true,
			'page_id'            => (int) $page_id,
			'message'            => empty( $this->collect_render_errors( $save_debug ) )
				? __( 'Elementor data updated.', 'mcpwp' )
				: __( 'Elementor data saved, but the page did not finish rendering — see render_errors.', 'mcpwp' ),
			'sections_saved'     => $save_debug['sections_saved'],
			'sections_submitted' => $save_debug['sections_submitted'],
			'save_method'        => $save_method,
			'structural_changes' => array(
				'sections_before' => $sections_before,
				'sections_after'  => $save_debug['sections_saved'],
				'widgets_before'  => $widgets_before,
				'widgets_after'   => $widgets_after,
				'was_empty'       => 0 === $sections_before,
			),
			'preview_url'        => add_query_arg( 'elementor-preview', $page_id, get_permalink( $page_id ) ),
			'css_regenerated'    => ! empty( $save_debug['css_regenerated'] ),
			'debug'              => $save_debug,
			'edit_url'           => admin_url( "post.php?post={$page_id}&action=elementor" ),
			'next_step'          => sprintf( 'Call wp_get_elementor_summary(id=%d) to verify the page structure.', $page_id ),
		);

		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		$result = $this->decorate_render_status( $result, $save_debug );

		return $result;
	}

	/**
	 * Create a simple page with Elementor enabled.
	 *
	 * @param array $data Page data.
	 * @return array|WP_Error Created page data or error.
	 */
	public function create_elementor_page( $data ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		// Create page
		$page_data = array(
			'post_type'    => 'page',
			'post_title'   => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : __( 'New Page', 'mcpwp' ),
			'post_status'  => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
			'post_content' => '',
		);

		$page_id = wp_insert_post( $page_data, true );

		if ( is_wp_error( $page_id ) ) {
			return $page_id;
		}

		// Enable Elementor with all required meta keys (#88).
		//
		// Through the helper so a host that vetoes the meta write is visible
		// rather than assumed away — a page created here and reported as
		// created is a page the caller will write content into next.
		$builder_flag_debug = array();
		$built_with_elementor = $this->mark_built_with_elementor( $page_id, $builder_flag_debug );
		update_post_meta( $page_id, '_wp_page_template', 'elementor_header_footer' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $page_id, '_elementor_version', ELEMENTOR_VERSION );
		}

		$save_result = null;

		// Set initial Elementor data if provided
		if ( ! empty( $data['elementor_data'] ) || ! empty( $data['elementor_json'] ) ) {
			$save_result = $this->set_elementor_data( $page_id, $data );
			if ( is_wp_error( $save_result ) ) {
				// Clean up page on error
				wp_delete_post( $page_id, true );
				return $save_result;
			}
		} else {
			// Set empty Elementor data
			update_post_meta( $page_id, '_elementor_data', '[]' );
		}

		$response = array(
			'success'               => true,
			'page_id'               => (int) $page_id,
			'title'                 => $page_data['post_title'],
			'status'                => $page_data['post_status'],
			'url'                   => get_permalink( $page_id ),
			'edit_url'              => admin_url( "post.php?post={$page_id}&action=elementor" ),
			// A created page that Elementor will not render is not a usable
			// page, and the caller is about to write content into it.
			'renders_via_elementor' => $built_with_elementor,
		);

		if ( ! $built_with_elementor ) {
			$response['warnings'] = array(
				__( 'The page was created, but it could not be marked as built with Elementor, so Elementor will not render its content. Check whether something on this site blocks writes to the _elementor_edit_mode post meta.', 'mcpwp' ),
			);
		}

		if ( is_array( $save_result ) ) {
			$merged = array_intersect_key(
				$save_result,
				array(
					'render_verified' => true,
					'render_errors'   => true,
					'warnings'        => true,
				)
			);

			// Merge rather than overwrite, so the creation warning above is
			// not dropped by a save that had warnings of its own.
			if ( isset( $merged['warnings'], $response['warnings'] ) ) {
				$merged['warnings'] = array_merge( $response['warnings'], (array) $merged['warnings'] );
			}

			$response = array_merge( $response, $merged );
		}

		return $response;
	}

	/**
	 * Generate a random 8-character element ID matching Elementor's format.
	 *
	 * @return string Random ID.
	 */
	private function generate_element_id() {
		$chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
		$id    = '';
		for ( $i = 0; $i < 8; $i++ ) {
			$id .= $chars[ wp_rand( 0, 35 ) ];
		}
		return $id;
	}

	/**
	 * Surgical edit of a single Elementor element without full JSON round-trip.
	 *
	 * Finds an element by ID, section index, or search criteria, merges
	 * settings, saves back, and returns only the modified element.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $args    {
	 *     @type string $element_id    Find element by its Elementor ID.
	 *     @type int    $section_index Find top-level section by 0-based index.
	 *     @type array  $find          Search criteria: {widgetType, settings.key => value}.
	 *     @type array  $settings      Settings to merge into the found element.
	 *     @type array  $delete_settings Setting keys to remove.
	 * }
	 * @return array|WP_Error Result with the modified element, or error.
	 */
	public function edit_section( $page_id, $args ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		$page = $this->validate_post( $page_id );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$elementor_data = get_post_meta( $page_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			return new WP_Error(
				'no_elementor_data',
				__( 'This page has no Elementor data.', 'mcpwp' ),
				array( 'status' => 404 )
			);
		}

		$elements = json_decode( $elementor_data, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error(
				'invalid_elementor_data',
				__( 'Elementor data is not valid JSON.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// --- Locate the target element ---

		$element_id    = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';
		$section_index = isset( $args['section_index'] ) ? (int) $args['section_index'] : -1;
		$find          = isset( $args['find'] ) && is_array( $args['find'] ) ? $args['find'] : array();

		$found    = null;
		$found_path = '';

		if ( '' !== $element_id ) {
			// Find by element ID (recursive).
			$found =& $this->find_element_by_id( $elements, $element_id, $found_path );
		} elseif ( $section_index >= 0 ) {
			// Find by top-level index.
			if ( isset( $elements[ $section_index ] ) ) {
				$found      =& $elements[ $section_index ];
				$found_path = ( isset( $found['elType'] ) ? $found['elType'] : 'element' ) . "[{$section_index}]";
			}
		} elseif ( ! empty( $find ) ) {
			// Find by search criteria.
			$found =& $this->find_element_by_criteria( $elements, $find, $found_path );
		} else {
			return new WP_Error(
				'no_selector',
				__( 'Provide element_id, section_index, or find criteria to locate the target element.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		if ( null === $found ) {
			return new WP_Error(
				'element_not_found',
				__( 'No matching element found in the Elementor tree.', 'mcpwp' ),
				array( 'status' => 404 )
			);
		}

		// --- Apply patches ---

		$changes = array();

		// Merge settings.
		if ( ! empty( $args['settings'] ) && is_array( $args['settings'] ) ) {
			if ( ! isset( $found['settings'] ) || ! is_array( $found['settings'] ) ) {
				$found['settings'] = array();
			}
			foreach ( $args['settings'] as $key => $value ) {
				$found['settings'][ $key ] = $value;
				$changes[] = "set {$key}";
			}
		}

		// Delete settings.
		if ( ! empty( $args['delete_settings'] ) && is_array( $args['delete_settings'] ) ) {
			foreach ( $args['delete_settings'] as $key ) {
				if ( isset( $found['settings'][ $key ] ) ) {
					unset( $found['settings'][ $key ] );
					$changes[] = "removed {$key}";
				}
			}
		}

		if ( empty( $changes ) ) {
			return new WP_Error(
				'no_changes',
				__( 'No settings or delete_settings provided — nothing to change.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		$save_debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $save_debug ) ) {
			return $save_debug;
		}

		// Re-read the saved element to return its final state.
		$saved_data = get_post_meta( $page_id, '_elementor_data', true );
		$saved_elements = json_decode( $saved_data, true );
		$saved_path = '';
		$saved_element = null;

		if ( '' !== $element_id ) {
			$saved_element = $this->find_element_by_id_readonly( $saved_elements, $element_id );
		} elseif ( $section_index >= 0 && isset( $saved_elements[ $section_index ] ) ) {
			$saved_element = $saved_elements[ $section_index ];
		} elseif ( ! empty( $find ) ) {
			$saved_element = $this->find_element_by_criteria_readonly( $saved_elements, $find );
		}

		$result = array(
			'success'  => true,
			'page_id'  => (int) $page_id,
			'path'     => $found_path,
			'changes'  => $changes,
			'element'  => $saved_element ? $saved_element : $found,
			'edit_url' => admin_url( "post.php?post={$page_id}&action=elementor" ),
		);
		if ( ! empty( $save_debug ) ) {
			$result['css_debug'] = array_intersect_key(
				$save_debug,
				array(
					'css_regenerated' => true,
					'css_file_size'   => true,
					'save_method'     => true,
					'meta_verified'   => true,
					'sections_saved'  => true,
				)
			);
		}

		$all_warnings = array_merge(
			isset( $save_debug['validation_warnings'] ) ? $save_debug['validation_warnings'] : array(),
			isset( $save_debug['validation_fixes'] ) ? $save_debug['validation_fixes'] : array()
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		$result = $this->decorate_render_status( $result, $save_debug );

		return $result;
	}

	/**
	 * Edit a single widget's settings by widget ID.
	 *
	 * Lightweight alternative to edit_section that targets widgets specifically.
	 * Loads current elementor_data, finds the widget by its 8-char ID, merges
	 * new settings, and saves the updated data.
	 *
	 * @param int    $page_id   Page/post ID.
	 * @param string $widget_id The 8-char Elementor element ID of the widget.
	 * @param array  $settings  Settings to merge into the widget.
	 * @param array  $delete_settings Optional setting keys to remove.
	 * @return array|WP_Error Result with the modified widget, or error.
	 */
	public function edit_widget( $page_id, $widget_id, $settings, $delete_settings = array() ) {
		if ( ! $this->is_active() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or active.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		$page = $this->validate_post( $page_id );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		if ( empty( $widget_id ) ) {
			return new WP_Error(
				'missing_widget_id',
				__( 'widget_id is required.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $settings ) && empty( $delete_settings ) ) {
			return new WP_Error(
				'no_changes',
				__( 'No settings or delete_settings provided.', 'mcpwp' ),
				array( 'status' => 400 )
			);
		}

		$elementor_data = get_post_meta( $page_id, '_elementor_data', true );
		if ( empty( $elementor_data ) ) {
			return new WP_Error(
				'no_elementor_data',
				__( 'This page has no Elementor data.', 'mcpwp' ),
				array( 'status' => 404 )
			);
		}

		$elements = json_decode( $elementor_data, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error(
				'invalid_elementor_data',
				__( 'Elementor data is not valid JSON.', 'mcpwp' ),
				array( 'status' => 500 )
			);
		}

		// Find widget by ID.
		$found_path = '';
		$found      =& $this->find_element_by_id( $elements, (string) $widget_id, $found_path );

		if ( null === $found ) {
			return new WP_Error(
				'widget_not_found',
				sprintf(
					/* translators: %s: widget ID */
					__( 'No element with ID "%s" found in the Elementor tree.', 'mcpwp' ),
					$widget_id
				),
				array( 'status' => 404 )
			);
		}

		// Verify it is actually a widget (or an Elementor v4 Atomic content
		// element — those are self-typed via elType directly, e.g. e-heading,
		// with no 'widget' wrapper, unlike legacy widgets). (#618/#620)
		static $atomic_leaf_types = array(
			'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg',
			'e-divider', 'e-youtube', 'e-self-hosted-video',
		);
		$el_type = isset( $found['elType'] ) ? $found['elType'] : '';
		if ( 'widget' !== $el_type && ! in_array( $el_type, $atomic_leaf_types, true ) ) {
			return new WP_Error(
				'not_a_widget',
				sprintf(
					/* translators: 1: element ID, 2: actual elType */
					__( 'Element "%1$s" is a %2$s, not a widget. Use wp_edit_section for non-widget elements.', 'mcpwp' ),
					$widget_id,
					$el_type
				),
				array( 'status' => 400 )
			);
		}

		// Apply settings merge.
		$changes = array();
		if ( ! isset( $found['settings'] ) || ! is_array( $found['settings'] ) ) {
			$found['settings'] = array();
		}

		if ( ! empty( $settings ) && is_array( $settings ) ) {
			foreach ( $settings as $key => $value ) {
				$found['settings'][ $key ] = $value;
				$changes[] = "set {$key}";
			}
		}

		// Delete settings.
		if ( ! empty( $delete_settings ) && is_array( $delete_settings ) ) {
			foreach ( $delete_settings as $key ) {
				if ( isset( $found['settings'][ $key ] ) ) {
					unset( $found['settings'][ $key ] );
					$changes[] = "removed {$key}";
				}
			}
		}

		$save_debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $save_debug ) ) {
			return $save_debug;
		}

		// Re-read the widget to return its final state.
		$saved_data     = get_post_meta( $page_id, '_elementor_data', true );
		$saved_elements = json_decode( $saved_data, true );
		$saved_widget   = $this->find_element_by_id_readonly( $saved_elements, (string) $widget_id );

		$result = array(
			'success'     => true,
			'page_id'     => (int) $page_id,
			'widget_id'   => $widget_id,
			'widget_type' => isset( $found['widgetType'] ) ? $found['widgetType'] : $el_type,
			'path'        => $found_path,
			'changes'     => $changes,
			'widget'      => $saved_widget ? $saved_widget : $found,
			'edit_url'    => admin_url( "post.php?post={$page_id}&action=elementor" ),
		);
		if ( ! empty( $save_debug ) ) {
			$result['css_debug'] = array_intersect_key(
				$save_debug,
				array(
					'css_regenerated' => true,
					'css_file_size'   => true,
					'save_method'     => true,
					'meta_verified'   => true,
					'sections_saved'  => true,
				)
			);
		}

		$all_warnings = array_merge(
			isset( $save_debug['validation_warnings'] ) ? $save_debug['validation_warnings'] : array(),
			isset( $save_debug['validation_fixes'] ) ? $save_debug['validation_fixes'] : array()
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		$result = $this->decorate_render_status( $result, $save_debug );

		return $result;
	}

	// -------------------------------------------------------------------------
	// Section-level operations: save helper, add, remove, replace, patch, strip
	// -------------------------------------------------------------------------

	/**
	 * Shared helper: save an element array to a page with validation, CSS regen, and cache clear.
	 *
	 * @param int   $page_id  Page/post ID.
	 * @param array $elements Full top-level element array.
	 * @param array $options  Reserved for future options.
	 * @return array|WP_Error Debug info array or WP_Error on failure.
	 */
	protected function save_elements_to_page( $page_id, $elements, $options = array() ) {
		$debug = array();
		$elementor_json = wp_json_encode( $elements );

		// Count input.
		$input_count = count( $elements );
		$debug['sections_submitted'] = $input_count;

		// Validate and fix (before write).
		$validation = $this->validate_and_fix_elements( $elementor_json );
		if ( ! empty( $validation['errors'] ) ) {
			return new WP_Error( 'invalid_elementor_structure', implode( ' | ', $validation['errors'] ), array( 'status' => 400, 'validation_errors' => $validation['errors'] ) );
		}
		$debug['validation_fixes']    = $validation['fixes'];
		$debug['validation_warnings'] = $validation['warnings'];

		// Safety check: validation didn't corrupt data.
		$post_val = json_decode( $elementor_json, true );
		if ( ! is_array( $post_val ) || count( $post_val ) !== $input_count ) {
			return new WP_Error( 'validation_corrupted', 'Data corrupted during validation.', array( 'status' => 500 ) );
		}

		// Save via Elementor Document API (preferred) or meta fallback.
		$document_saved = false;
		$elementor_ok   = class_exists( '\Elementor\Plugin' );

		if ( $elementor_ok ) {
			$documents_manager = \Elementor\Plugin::$instance->documents;
			if ( $documents_manager && method_exists( $documents_manager, 'get' ) ) {
				wp_cache_delete( $page_id, 'post_meta' );
				clean_post_cache( $page_id );

				$document = $documents_manager->get( $page_id, false );
				if ( $document && method_exists( $document, 'save' ) ) {
					// Shared by six surgical operations (add/remove/replace/patch/edit/
					// strip). Same contract as the full-set path: a throw degrades to
					// the raw-meta fallback below rather than aborting the request.
					$attempt = $this->attempt_elementor_call(
						static function () use ( $document, $post_val ) {
							return $document->save( array( 'elements' => $post_val ) );
						},
						$debug,
						'document_save'
					);
					$save_result = $attempt['value'];
					if ( $attempt['ok'] && ! is_wp_error( $save_result ) ) {
						// (#198) Always do a direct meta overwrite after Document::save()
						// to guarantee the raw _elementor_data meta matches what we sent.
						// Document::save() may create revisions or write to internal caches
						// that diverge from the post meta on subsequent reads.
						update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );

						wp_cache_delete( $page_id, 'post_meta' );
						clean_post_cache( $page_id );

						// Flush persistent object cache if available.
						if ( function_exists( 'wp_cache_flush_group' ) ) {
							wp_cache_flush_group( 'post_meta' );
						}

						$stored_after_document = get_post_meta( $page_id, '_elementor_data', true );
						$decoded_after_document = json_decode( $stored_after_document, true );
						$stored_after_count     = is_array( $decoded_after_document ) ? count( $decoded_after_document ) : 0;

						if ( $stored_after_count === $input_count ) {
							$document_saved         = true;
							$debug['document_save'] = true;
							$debug['save_method']   = 'document_save_with_meta_overwrite';
						} else {
							$debug['document_save_persist_mismatch'] = array(
								'sections_expected' => $input_count,
								'sections_saved'    => $stored_after_count,
							);
						}
					} elseif ( is_wp_error( $save_result ) ) {
						$debug['document_save_error'] = $save_result->get_error_message();
					}
					// No else: a thrown call leaves $save_result null and is already
					// recorded as document_save_exception.
				}
			}
		}

		// Fallback: direct meta write.
		if ( ! $document_saved ) {
			update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );
			$debug['save_method'] = 'meta_direct';

			// (#187) Update post_content so front-end renderer doesn't short-circuit.
			wp_update_post( array(
				'ID'           => $page_id,
				'post_content' => '',
			) );

			// (#187) Flush Elementor's in-memory document cache so CSS regen
			// and same-request renders read the new data from DB.
			if ( $elementor_ok && ! empty( \Elementor\Plugin::$instance->documents ) ) {
				clean_post_cache( $page_id );
				wp_cache_delete( $page_id, 'post_meta' );
				\Elementor\Plugin::$instance->documents->get( $page_id, false );
				$debug['document_cache_flushed'] = true;
			}
		}

		$debug['meta_written'] = true;

		// Verify.
		wp_cache_delete( $page_id, 'post_meta' );
		$stored         = get_post_meta( $page_id, '_elementor_data', true );
		$stored_decoded = json_decode( $stored, true );
		$stored_count   = is_array( $stored_decoded ) ? count( $stored_decoded ) : 0;
		$debug['sections_saved'] = $stored_count;
		$debug['meta_verified']  = ( $stored_count === $input_count );

		// Rebuild Elementor's CSS + HTML rendering cache.
		// Always regenerate — Document::save() may have used stale data before
		// our meta overwrite, leaving post_content (HTML cache) stale.
		if ( $elementor_ok ) {
			if ( ! empty( \Elementor\Plugin::$instance->files_manager ) ) {
				$cleared = $this->attempt_elementor_call(
					static function () {
						\Elementor\Plugin::$instance->files_manager->clear_cache();
						return true;
					},
					$debug,
					'cache_clear'
				);
			}
			delete_post_meta( $page_id, '_elementor_css' );

			// Force a fresh document load and re-save to rebuild post_content (HTML rendering).
			if ( ! empty( \Elementor\Plugin::$instance->documents ) ) {
				$fresh_doc = \Elementor\Plugin::$instance->documents->get( $page_id, false );
				if ( $fresh_doc && method_exists( $fresh_doc, 'save' ) ) {
					$fresh_elements = json_decode( get_post_meta( $page_id, '_elementor_data', true ), true );
					if ( is_array( $fresh_elements ) && ! empty( $fresh_elements ) ) {
						$pre_rebuild_total = $this->count_elements_recursive( $fresh_elements );
						$rebuild = $this->attempt_elementor_call(
							static function () use ( $fresh_doc, $fresh_elements ) {
								return $fresh_doc->save( array( 'elements' => $fresh_elements ) );
							},
							$debug,
							'html_cache_rebuild'
						);
						$debug['html_cache_rebuilt'] = $rebuild['ok'];
						if ( ! $rebuild['ok'] ) {
							update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );
							wp_cache_delete( $page_id, 'post_meta' );
						}

						// Mirror the full-set save's semantic integrity guard. Elementor can
						// return success after silently changing nested content, leaving the
						// HTML cache inconsistent with the exact meta restored below.
						global $wpdb;
						$post_rebuild = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_elementor_data' ORDER BY meta_id DESC LIMIT 1",
								$page_id
							)
						);
						$post_rebuild_decoded = $post_rebuild ? json_decode( $post_rebuild, true ) : null;
						$post_rebuild_total    = is_array( $post_rebuild_decoded ) ? $this->count_elements_recursive( $post_rebuild_decoded ) : 0;
						if ( ! $this->elementor_values_match( $fresh_elements, $post_rebuild_decoded ) ) {
							update_post_meta( $page_id, '_elementor_data', wp_slash( $elementor_json ) );
							wp_cache_delete( $page_id, 'post_meta' );
							$debug['html_rebuild_corrupted_data'] = true;
							$debug['html_rebuild_elements_before'] = $pre_rebuild_total;
							$debug['html_rebuild_elements_after']  = $post_rebuild_total;
						}
					}
				}
			}

			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				// Same Elementor API surface as the saves above: an unguarded throw
				// here would still 500 a request whose data is already persisted.
				$css_regen = $this->attempt_elementor_call(
					static function () use ( $page_id ) {
						$css_file = \Elementor\Core\Files\CSS\Post::create( $page_id );
						$css_file->update();
						return true;
					},
					$debug,
					'css_regeneration'
				);
				$debug['css_regenerated'] = $css_regen['ok'];
			}
		}

		// Mark the post as built with Elementor.
		//
		// Elementor's frontend renders _elementor_data only for a document
		// whose is_built_with_elementor() is true, and that flag is the
		// _elementor_edit_mode meta. Document::save() does NOT set it —
		// only Elementor's own editor does (editor.php calls
		// set_is_built_with_elementor). So a page written entirely through
		// this API and never opened in the editor had its data on disk,
		// answered document_save:true, html_cache_rebuilt:true and
		// render_verified:true, and rendered ZERO bytes of Elementor
		// output: the plain-text post_content that Document::save() wrote
		// went out as bare HTML directly in <body>, with no section
		// wrappers and no styling (#785 defect 4, #784).
		//
		// set_elementor_data() and create_elementor_page() already did
		// this. Every surgical operation that routes through here —
		// add/remove/replace/patch/edit, and therefore
		// wp_apply_elementor_part — did not.
		//
		// Outside the `$elementor_ok` branch on purpose. The flag is plain
		// post meta and it belongs with the data wherever that data was
		// written; making it conditional on Elementor being loaded at write
		// time would leave the same page unrenderable through the raw-meta
		// fallback path this method already has.
		//
		// Only when there are elements: writing an empty array is how a
		// page gets cleared, and flagging that would hand an empty
		// Elementor document control of a page that renders fine without
		// it.
		if ( ! empty( $post_val ) ) {
			$debug['built_with_elementor'] = $this->mark_built_with_elementor( $page_id, $debug );
		}

		// Prime CSS if still empty.
		if ( $elementor_ok ) {
			$upload_dir = wp_upload_dir();
			$css_path   = $upload_dir['basedir'] . '/elementor/css/post-' . $page_id . '.css';
			$css_size   = file_exists( $css_path ) ? filesize( $css_path ) : 0;
			$debug['css_file_size'] = $css_size;
			if ( $css_size < 10 ) {
				delete_post_meta( $page_id, '_elementor_css' );
				$permalink = get_permalink( $page_id );
				if ( $permalink ) {
					wp_remote_get(
						add_query_arg( 'mcpwp_prime_css', wp_rand(), $permalink ),
						array(
							'timeout'   => 15,
							'sslverify' => ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
							'blocking'  => false,
						)
					);
				}
			}
		}

		$this->purge_page_cache( $page_id );

		$debug['next_step'] = sprintf( 'Call wp_get_elementor_summary(id=%d) to verify the page structure.', $page_id );

		return $debug;
	}

	/**
	 * Add a section/container at a specific position on a page.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $data    {
	 *     @type array  $element  The element to add (must have elType).
	 *     @type string $position Where to insert: 'start', 'end', 'before:{id}', 'after:{id}'.
	 * }
	 * @return array|WP_Error Result or error.
	 */
	public function add_section( $page_id, $data ) {
		// Validate page exists and has Elementor.
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		// Get current elements.
		$raw      = get_post_meta( $page_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			$elements = array();
		}

		// Validate new element.
		$new_element = isset( $data['element'] ) ? $data['element'] : null;
		if ( ! is_array( $new_element ) || empty( $new_element['elType'] ) ) {
			return new WP_Error( 'invalid_element', 'element must be an object with elType.', array( 'status' => 400 ) );
		}

		// Auto-generate ID if missing.
		if ( empty( $new_element['id'] ) ) {
			$new_element['id'] = $this->generate_element_id();
		}

		// Parse position.
		$position = isset( $data['position'] ) ? $data['position'] : 'end';
		$inserted = false;

		if ( 'start' === $position ) {
			array_unshift( $elements, $new_element );
			$inserted = true;
		} elseif ( 'end' === $position ) {
			$elements[] = $new_element;
			$inserted = true;
		} elseif ( preg_match( '/^(before|after):(.+)$/', $position, $m ) ) {
			$rel    = $m[1];
			$ref_id = $m[2];
			foreach ( $elements as $idx => $el ) {
				if ( isset( $el['id'] ) && $el['id'] === $ref_id ) {
					$insert_at = ( 'after' === $rel ) ? $idx + 1 : $idx;
					array_splice( $elements, $insert_at, 0, array( $new_element ) );
					$inserted = true;
					break;
				}
			}
			if ( ! $inserted ) {
				return new WP_Error( 'ref_not_found', "Reference element '$ref_id' not found in top-level sections.", array( 'status' => 404 ) );
			}
		} else {
			return new WP_Error( 'invalid_position', "Invalid position '$position'. Use: start, end, before:{id}, after:{id}.", array( 'status' => 400 ) );
		}

		// Save.
		$debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $debug ) ) {
			return $debug;
		}

		$all_warnings = array_merge(
			isset( $debug['validation_warnings'] ) ? $debug['validation_warnings'] : array(),
			isset( $debug['validation_fixes'] ) ? $debug['validation_fixes'] : array()
		);

		$result = array(
			'success'       => true,
			'page_id'       => (int) $page_id,
			'element_id'    => $new_element['id'],
			'position'      => $position,
			'section_count' => count( $elements ),
			'debug'         => $debug,
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		$result = $this->decorate_render_status( $result, $debug );

		return $result;
	}

	/**
	 * Remove a section/container/widget by element ID.
	 *
	 * Searches top-level first, then recursively in nested elements.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $data    {
	 *     @type string $element_id The Elementor element ID to remove.
	 * }
	 * @return array|WP_Error Result or error.
	 */
	public function remove_section( $page_id, $data ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		$element_id = isset( $data['element_id'] ) ? $data['element_id'] : '';
		if ( empty( $element_id ) ) {
			return new WP_Error( 'missing_id', 'element_id is required.', array( 'status' => 400 ) );
		}

		$raw      = get_post_meta( $page_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'no_data', 'Page has no Elementor data.', array( 'status' => 404 ) );
		}

		$before_count = count( $elements );
		$removed      = null;

		// Search top-level first.
		foreach ( $elements as $idx => $el ) {
			if ( isset( $el['id'] ) && $el['id'] === $element_id ) {
				$removed = $el;
				array_splice( $elements, $idx, 1 );
				break;
			}
		}

		if ( ! $removed ) {
			// Search recursively in nested elements.
			$removed = $this->remove_nested_element( $elements, $element_id );
		}

		if ( ! $removed ) {
			return new WP_Error( 'not_found', "Element '$element_id' not found.", array( 'status' => 404 ) );
		}

		$debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $debug ) ) {
			return $debug;
		}

		$all_warnings = array_merge(
			isset( $debug['validation_warnings'] ) ? $debug['validation_warnings'] : array(),
			isset( $debug['validation_fixes'] ) ? $debug['validation_fixes'] : array()
		);

		$result = array(
			'success'       => true,
			'page_id'       => (int) $page_id,
			'removed_id'    => $element_id,
			'removed_type'  => isset( $removed['elType'] ) ? $removed['elType'] : 'unknown',
			'section_count' => count( $elements ),
			'debug'         => $debug,
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		$result = $this->decorate_render_status( $result, $debug );

		return $result;
	}

	/**
	 * Recursively remove an element by ID from nested elements arrays.
	 *
	 * @param array  &$elements Element tree (by reference).
	 * @param string $target_id The element ID to remove.
	 * @return array|null The removed element, or null if not found.
	 */
	private function remove_nested_element( &$elements, $target_id ) {
		foreach ( $elements as $idx => &$el ) {
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				foreach ( $el['elements'] as $child_idx => $child ) {
					if ( isset( $child['id'] ) && $child['id'] === $target_id ) {
						$removed = $child;
						array_splice( $el['elements'], $child_idx, 1 );
						return $removed;
					}
				}
				$found = $this->remove_nested_element( $el['elements'], $target_id );
				if ( $found ) {
					return $found;
				}
			}
		}
		unset( $el );
		return null;
	}

	/**
	 * Replace a section/container/widget by element ID.
	 *
	 * Searches top-level first, then recursively in nested elements.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $data    {
	 *     @type string $element_id The Elementor element ID to replace.
	 *     @type array  $element    The new element (must have elType).
	 * }
	 * @return array|WP_Error Result or error.
	 */
	public function replace_section( $page_id, $data ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		$element_id  = isset( $data['element_id'] ) ? $data['element_id'] : '';
		$new_element = isset( $data['element'] ) ? $data['element'] : null;

		if ( empty( $element_id ) ) {
			return new WP_Error( 'missing_id', 'element_id is required.', array( 'status' => 400 ) );
		}
		if ( ! is_array( $new_element ) || empty( $new_element['elType'] ) ) {
			return new WP_Error( 'invalid_element', 'element must be an object with elType.', array( 'status' => 400 ) );
		}

		// Preserve or generate ID.
		if ( empty( $new_element['id'] ) ) {
			$new_element['id'] = $element_id; // keep same ID by default.
		}

		$raw      = get_post_meta( $page_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			return new WP_Error( 'no_data', 'Page has no Elementor data.', array( 'status' => 404 ) );
		}

		$replaced = false;

		// Search top-level.
		foreach ( $elements as $idx => $el ) {
			if ( isset( $el['id'] ) && $el['id'] === $element_id ) {
				$elements[ $idx ] = $new_element;
				$replaced = true;
				break;
			}
		}

		if ( ! $replaced ) {
			// Search recursively.
			$replaced = $this->replace_nested_element( $elements, $element_id, $new_element );
		}

		if ( ! $replaced ) {
			return new WP_Error( 'not_found', "Element '$element_id' not found.", array( 'status' => 404 ) );
		}

		$debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $debug ) ) {
			return $debug;
		}

		$all_warnings = array_merge(
			isset( $debug['validation_warnings'] ) ? $debug['validation_warnings'] : array(),
			isset( $debug['validation_fixes'] ) ? $debug['validation_fixes'] : array()
		);

		$result = array(
			'success'       => true,
			'page_id'       => (int) $page_id,
			'replaced_id'   => $element_id,
			'new_id'        => $new_element['id'],
			'section_count' => count( $elements ),
			'debug'         => $debug,
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		$result = $this->decorate_render_status( $result, $debug );

		return $result;
	}

	/**
	 * Recursively replace an element by ID in nested elements arrays.
	 *
	 * @param array  &$elements   Element tree (by reference).
	 * @param string $target_id   The element ID to replace.
	 * @param array  $new_element The replacement element.
	 * @return bool True if replaced, false if not found.
	 */
	private function replace_nested_element( &$elements, $target_id, $new_element ) {
		foreach ( $elements as $idx => &$el ) {
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				foreach ( $el['elements'] as $child_idx => $child ) {
					if ( isset( $child['id'] ) && $child['id'] === $target_id ) {
						$el['elements'][ $child_idx ] = $new_element;
						return true;
					}
				}
				if ( $this->replace_nested_element( $el['elements'], $target_id, $new_element ) ) {
					return true;
				}
			}
		}
		unset( $el );
		return false;
	}

	/**
	 * Batch patch Elementor data: add, remove, replace, or update settings in one request.
	 *
	 * Applies up to 20 operations sequentially, then saves once.
	 *
	 * @param int   $page_id Page/post ID.
	 * @param array $data    {
	 *     @type array $operations Array of operations, each with:
	 *         @type string $op             Operation type: 'add', 'remove', 'replace', 'settings'.
	 *         @type string $element_id     Target element ID (for remove, replace, settings).
	 *         @type array  $element        New element (for add, replace).
	 *         @type string $position       Insert position (for add): 'start', 'end', 'before:{id}', 'after:{id}'.
	 *         @type array  $settings       Settings to merge (for settings op).
	 *         @type array  $delete_settings Setting keys to remove (for settings op).
	 * }
	 * @return array|WP_Error Result with per-operation results, or error.
	 */
	public function patch_elementor( $page_id, $data ) {
		$post = get_post( $page_id );
		if ( ! $post ) {
			return new WP_Error( 'invalid_page', 'Page not found.', array( 'status' => 404 ) );
		}

		$operations = isset( $data['operations'] ) ? $data['operations'] : array();
		if ( empty( $operations ) || ! is_array( $operations ) ) {
			return new WP_Error( 'no_operations', 'operations array is required.', array( 'status' => 400 ) );
		}

		if ( count( $operations ) > self::MAX_PATCH_OPERATIONS ) {
			return new WP_Error(
				'too_many_ops',
				sprintf( 'Maximum %d operations per patch.', self::MAX_PATCH_OPERATIONS ),
				array( 'status' => 400 )
			);
		}

		$raw      = get_post_meta( $page_id, '_elementor_data', true );
		$elements = json_decode( $raw, true );
		if ( ! is_array( $elements ) ) {
			$elements = array();
		}

		$results = array();

		foreach ( $operations as $i => $op ) {
			$type   = isset( $op['op'] ) ? $op['op'] : '';
			$el_id  = isset( $op['element_id'] ) ? $op['element_id'] : '';
			$result = array(
				'op'    => $type,
				'index' => $i,
			);

			switch ( $type ) {
				case 'add':
					$new_el = isset( $op['element'] ) ? $op['element'] : null;
					if ( ! is_array( $new_el ) ) {
						$result['error'] = 'element required';
						break;
					}
					if ( empty( $new_el['id'] ) ) {
						$new_el['id'] = $this->generate_element_id();
					}
					$position = isset( $op['position'] ) ? $op['position'] : 'end';
					if ( 'start' === $position ) {
						array_unshift( $elements, $new_el );
					} elseif ( 'end' === $position ) {
						$elements[] = $new_el;
					} elseif ( preg_match( '/^(before|after):(.+)$/', $position, $m ) ) {
						$found_ref = false;
						foreach ( $elements as $idx => $el ) {
							if ( isset( $el['id'] ) && $el['id'] === $m[2] ) {
								$insert_at = ( 'after' === $m[1] ) ? $idx + 1 : $idx;
								array_splice( $elements, $insert_at, 0, array( $new_el ) );
								$found_ref = true;
								break;
							}
						}
						if ( ! $found_ref ) {
							$result['error'] = "ref '{$m[2]}' not found";
							break;
						}
					}
					$result['success']    = true;
					$result['element_id'] = $new_el['id'];
					break;

				case 'remove':
					if ( empty( $el_id ) ) {
						$result['error'] = 'element_id required';
						break;
					}
					$removed = null;
					foreach ( $elements as $idx => $el ) {
						if ( isset( $el['id'] ) && $el['id'] === $el_id ) {
							$removed = $el;
							array_splice( $elements, $idx, 1 );
							break;
						}
					}
					if ( ! $removed ) {
						$removed = $this->remove_nested_element( $elements, $el_id );
					}
					if ( $removed ) {
						$result['success'] = true;
					} else {
						$result['error'] = "element '$el_id' not found";
					}
					break;

				case 'replace':
					if ( empty( $el_id ) ) {
						$result['error'] = 'element_id required';
						break;
					}
					$new_el = isset( $op['element'] ) ? $op['element'] : null;
					if ( ! is_array( $new_el ) ) {
						$result['error'] = 'element required';
						break;
					}
					if ( empty( $new_el['id'] ) ) {
						$new_el['id'] = $el_id;
					}
					$op_replaced = false;
					foreach ( $elements as $idx => $el ) {
						if ( isset( $el['id'] ) && $el['id'] === $el_id ) {
							$elements[ $idx ] = $new_el;
							$op_replaced = true;
							break;
						}
					}
					if ( ! $op_replaced ) {
						$op_replaced = $this->replace_nested_element( $elements, $el_id, $new_el );
					}
					$result['success'] = $op_replaced;
					if ( ! $op_replaced ) {
						$result['error'] = "element '$el_id' not found";
					}
					break;

				case 'settings':
					if ( empty( $el_id ) ) {
						$result['error'] = 'element_id required';
						break;
					}
					$dummy_path = '';
					$target     =& $this->find_element_by_id( $elements, $el_id, $dummy_path );
					if ( null === $target ) {
						$result['error'] = "element '$el_id' not found";
						break;
					}
					if ( ! isset( $target['settings'] ) || ! is_array( $target['settings'] ) ) {
						$target['settings'] = array();
					}
					if ( ! empty( $op['settings'] ) && is_array( $op['settings'] ) ) {
						foreach ( $op['settings'] as $k => $v ) {
							$target['settings'][ $k ] = $v;
						}
					}
					if ( ! empty( $op['delete_settings'] ) && is_array( $op['delete_settings'] ) ) {
						foreach ( $op['delete_settings'] as $k ) {
							unset( $target['settings'][ $k ] );
						}
					}
					unset( $target );
					$result['success'] = true;
					break;

				default:
					$result['error'] = "unknown op '$type' — use: add, remove, replace, settings";
					break;
			}

			$results[] = $result;
		}

		// Save once.
		$debug = $this->save_elements_to_page( $page_id, $elements );
		if ( is_wp_error( $debug ) ) {
			return $debug;
		}

		$all_warnings = array_merge(
			isset( $debug['validation_warnings'] ) ? $debug['validation_warnings'] : array(),
			isset( $debug['validation_fixes'] ) ? $debug['validation_fixes'] : array()
		);

		$result = array(
			'success'       => true,
			'page_id'       => (int) $page_id,
			'operations'    => $results,
			'section_count' => count( $elements ),
			'debug'         => $debug,
		);
		if ( ! empty( $all_warnings ) ) {
			$result['warnings'] = $all_warnings;
		}

		$result = $this->decorate_render_status( $result, $debug );

		return $result;
	}

	/**
	 * Strip default widget settings from an element tree.
	 *
	 * Compares each widget's settings against the known schema defaults
	 * and removes any settings that match the default value, reducing payload size.
	 *
	 * @param array &$elements Element tree (by reference, modified in place).
	 */
	public function strip_element_defaults( &$elements ) {
		foreach ( $elements as &$el ) {
			if ( isset( $el['elType'] ) && 'widget' === $el['elType'] && ! empty( $el['widgetType'] ) ) {
				$schema = Mcpwp_Elementor_Widgets::get( $el['widgetType'] );
				if ( $schema && ! empty( $schema['settings'] ) && ! empty( $el['settings'] ) ) {
					foreach ( $schema['settings'] as $key => $def ) {
						if ( isset( $el['settings'][ $key ] ) && isset( $def['default'] )
							&& $el['settings'][ $key ] === $def['default'] ) {
							unset( $el['settings'][ $key ] );
						}
					}
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->strip_element_defaults( $el['elements'] );
			}
		}
		unset( $el );
	}

	/**
	 * Find an element by ID recursively (returns reference).
	 *
	 * @param array  &$elements Element tree (by reference for modification).
	 * @param string $id        Target element ID.
	 * @param string &$path     Populated with the path to the found element.
	 * @return array|null Reference to the found element or null.
	 */
	private function &find_element_by_id( &$elements, $id, &$path ) {
		$null = null;
		foreach ( $elements as $idx => &$el ) {
			$el_type = isset( $el['elType'] ) ? $el['elType'] : 'element';
			$current_path = "{$el_type}[{$idx}]";

			if ( isset( $el['id'] ) && (string) $el['id'] === $id ) {
				$path = $current_path;
				return $el;
			}

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$child_path = '';
				$found =& $this->find_element_by_id( $el['elements'], $id, $child_path );
				if ( null !== $found ) {
					$path = $current_path . '.' . $child_path;
					return $found;
				}
			}
		}
		unset( $el );
		return $null;
	}

	/**
	 * Find an element by search criteria recursively (returns reference).
	 *
	 * @param array  &$elements Element tree (by reference).
	 * @param array  $criteria  Search criteria (widgetType, settings.key => value).
	 * @param string &$path     Populated with the path to the found element.
	 * @return array|null Reference to the found element or null.
	 */
	private function &find_element_by_criteria( &$elements, $criteria, &$path ) {
		$null = null;
		foreach ( $elements as $idx => &$el ) {
			$el_type = isset( $el['elType'] ) ? $el['elType'] : 'element';
			$current_path = "{$el_type}[{$idx}]";

			if ( $this->element_matches_criteria( $el, $criteria ) ) {
				$path = $current_path;
				return $el;
			}

			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$child_path = '';
				$found =& $this->find_element_by_criteria( $el['elements'], $criteria, $child_path );
				if ( null !== $found ) {
					$path = $current_path . '.' . $child_path;
					return $found;
				}
			}
		}
		unset( $el );
		return $null;
	}

	/**
	 * Check if an element matches search criteria.
	 *
	 * @param array $element  The element to check.
	 * @param array $criteria Search criteria.
	 * @return bool True if all criteria match.
	 */
	private function element_matches_criteria( $element, $criteria ) {
		foreach ( $criteria as $key => $value ) {
			if ( 'widgetType' === $key ) {
				if ( ! isset( $element['widgetType'] ) || $element['widgetType'] !== $value ) {
					return false;
				}
			} elseif ( 'elType' === $key ) {
				if ( ! isset( $element['elType'] ) || $element['elType'] !== $value ) {
					return false;
				}
			} elseif ( 0 === strpos( $key, 'settings.' ) ) {
				$setting_key = substr( $key, 9 );
				$settings    = isset( $element['settings'] ) ? $element['settings'] : array();
				if ( ! isset( $settings[ $setting_key ] ) || $settings[ $setting_key ] !== $value ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Recursively count widget elements in an Elementor element tree.
	 *
	 * @param array $elements Element tree.
	 * @return int Widget count.
	 */
	private function count_elementor_widgets( array $elements ) {
		// (#618/#620) Atomic content elements (e-heading, e-button, ...) are the
		// widget-equivalent for Editor V4 — self-typed via elType, no 'widget' wrapper.
		static $atomic_leaf_types = array(
			'e-heading', 'e-paragraph', 'e-button', 'e-image', 'e-svg',
			'e-divider', 'e-youtube', 'e-self-hosted-video',
		);

		$count = 0;
		foreach ( $elements as $element ) {
			if ( isset( $element['elType'] ) && ( 'widget' === $element['elType'] || in_array( $element['elType'], $atomic_leaf_types, true ) ) ) {
				++$count;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_elementor_widgets( $element['elements'] );
			}
		}
		return $count;
	}

	/**
	 * Recursively count every element node in an Elementor element tree
	 * (containers and leaf elements alike). Used for whole-tree data-loss
	 * detection where a shallow top-level count() would miss nested content
	 * silently stripped while the top-level array length stays the same. (#618)
	 *
	 * @param array $elements Element tree.
	 * @return int Total element count, all depths.
	 */
	private function count_elements_recursive( array $elements ) {
		$count = 0;
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			++$count;
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$count += $this->count_elements_recursive( $element['elements'] );
			}
		}
		return $count;
	}

	/**
	 * Recursively check whether an Elementor element tree contains a given elType.
	 *
	 * @param array  $elements Element tree.
	 * @param string $type     elType to look for (e.g. 'container').
	 * @return bool
	 */
	private function elementor_tree_contains_type( array $elements, $type ) {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( isset( $element['elType'] ) && $type === $element['elType'] ) {
				return true;
			}
			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				if ( $this->elementor_tree_contains_type( $element['elements'], $type ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
