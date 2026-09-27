<?php
/**
 * Elementor + Elementor Pro doubles for Mcpwp_Theme_Builder::create_theme_template()
 * atomicity coverage (#828 part 2 / #759 residual gap).
 *
 * Mcpwp_Fake_Document::save() mirrors the one property of the real
 * Core\Base\Document::save() these tests need: it runs the
 * `elementor/document/save/data` filter internally, exactly where Elementor's
 * own content-sanitizer module hooks in and (unguarded, #759) throws for any
 * non-widget element when the current user lacks manage_options. That lets a
 * test assert "the sanitizer did not run while suspended" the same way
 * ElementorWriteFidelityTest does, and "a throw here is survived" the same way
 * ElementorSaveThrowableTest does — without needing a real Elementor install.
 *
 * Deliberately NOT named *Test.php: phpunit.xml collects the tests directory
 * with the default Test.php suffix, and \Elementor\Plugin / \ElementorPro\...
 * must never be declared in the main single-process run — doing so changes
 * the behaviour of every Elementor-sensitive test that follows (see #757).
 *
 * Loaded with require_once from inside a @runInSeparateProcess test only.
 *
 * @package MCPWP
 */

namespace Elementor {

	/**
	 * Stands in for \Elementor\Modules\ContentSanitizer\Module. Only the piece
	 * that matters is modelled: a sanitize_content() callback hooked on
	 * elementor/document/save/data.
	 */
	class Mcpwp_Fake_Content_Sanitizer {

		public function sanitize_content( $data, $document = null ) {
			$GLOBALS['mcpwp_theme_test_sanitizer_ran'] = ( $GLOBALS['mcpwp_theme_test_sanitizer_ran'] ?? 0 ) + 1;

			return $data;
		}
	}

	class Mcpwp_Fake_Modules_Manager {

		public $sanitizer;

		public function __construct() {
			$this->sanitizer = new Mcpwp_Fake_Content_Sanitizer();
		}

		public function get_modules( $name ) {
			if ( 'content-sanitizer' === $name ) {
				return $this->sanitizer;
			}

			return null;
		}
	}

	/**
	 * Stands in for an Elementor document. Runs the save-data filter exactly
	 * where the real Document::save() does, and can be told to throw after.
	 */
	class Mcpwp_Fake_Document {

		/** @var int */
		private $id;

		public function __construct( $id = 0 ) {
			$this->id = (int) $id;
		}

		public function save( $data ) {
			$GLOBALS['mcpwp_theme_test_save_calls'] = ( $GLOBALS['mcpwp_theme_test_save_calls'] ?? 0 ) + 1;

			// Mirrors Core\Base\Document::save(): the content-sanitizer hooks
			// exactly this filter, from inside save().
			\apply_filters( 'elementor/document/save/data', $data, $this );

			if ( ! empty( $GLOBALS['mcpwp_theme_test_throw'] ) ) {
				// Shape of the real #759 failure: Elementor's own unguarded
				// `$element['widgetType']` read on a non-widget element. The
				// specific Throwable class is not the contract under test.
				throw new \TypeError( 'Undefined array key "widgetType"' );
			}

			if ( ! empty( $GLOBALS['mcpwp_theme_test_decline'] ) ) {
				// The #951 shape: Core\Base\Document::save() is documented
				// `@return bool` and returns exactly `false` — no exception,
				// no WP_Error — when is_editable_by_current_user() fails.
				return false;
			}

			return true;
		}

		/**
		 * Mcpwp_Theme_Builder::format_template() calls this unconditionally on
		 * whatever Plugin::instance()->documents->get() returns.
		 */
		public function get_edit_url() {
			return 'https://example.test/wp-admin/post.php?action=elementor';
		}

		/**
		 * Mirrors Theme_Document::get_main_id() as far as these tests need:
		 * every document here is a plain post, never a "section"
		 * sub-document, so the main id is just the post id.
		 */
		public function get_main_id() {
			return $this->id;
		}

		/**
		 * Mirrors Theme_Document::get_location() (#974/#976 review, W1/W2):
		 * a document-TYPE-level fixed property when the real document
		 * class sets one (a real Header/Footer/etc document does, in
		 * get_properties()), else the per-instance `_elementor_location`
		 * meta — which is what Custom Code's real Document class always
		 * falls through to, since it never sets that type property
		 * (confirmed reading custom-code/document.php). No Theme Builder
		 * test here ever seeds `_elementor_location` meta, so the 'header'
		 * default preserves that suite's pre-existing behaviour exactly;
		 * the Custom Code cache-sync tests set the meta explicitly and get
		 * it back.
		 */
		public function get_location() {
			$meta = get_post_meta( $this->id, '_elementor_location', true );

			return $meta ? $meta : 'header';
		}
	}

	class Mcpwp_Fake_Documents {

		/**
		 * Real Elementor returns null for a post ID that isn't a
		 * recognised document at all. #974/#976 review round 2, NIT-3:
		 * modelled via a global id-list so a test can simulate "not a
		 * theme document" and prove save_conditions() returns false for
		 * it, matching the real Conditions_Manager::save_conditions()'s
		 * own `if ( ! $document ) { return false; }` guard.
		 */
		public function get( $id, $from_cache = true ) {
			if ( in_array( (int) $id, $GLOBALS['mcpwp_theme_test_no_document_ids'] ?? array(), true ) ) {
				return null;
			}

			return new Mcpwp_Fake_Document( $id );
		}
	}

	class Mcpwp_Fake_Files_Manager {

		public function clear_cache() {}
	}

	/**
	 * Minimal stand-in for \Elementor\Plugin as the theme-builder and writer
	 * trait use it: Plugin::$instance->documents and ->modules_manager.
	 */
	class Plugin {

		public static $instance;

		public $documents;
		public $modules_manager;
		public $files_manager;

		public function __construct() {
			$this->documents       = new Mcpwp_Fake_Documents();
			$this->modules_manager = new Mcpwp_Fake_Modules_Manager();
			$this->files_manager   = new Mcpwp_Fake_Files_Manager();
		}

		// Mcpwp_Theme_Builder reads Plugin via the static instance() accessor
		// (format_template(), set_template_conditions(), ...); the writer trait
		// reads Plugin::$instance directly. Both must resolve to the same
		// object, so instance() lazily fills the same static property.
		public static function instance() {
			if ( empty( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}
}

namespace ElementorPro\Modules\ThemeBuilder {

	/**
	 * get_template()/get_template_conditions() call this unconditionally on
	 * every read, even when nothing was ever bound to a location — as in
	 * these tests, which deliberately use a scope that fails
	 * Mcpwp_Theme_Builder::assign_to_location()'s own validation before it
	 * ever reaches save_conditions()/get_cache(). Only the read side is
	 * modelled; nothing here needs to record a write.
	 */
	class Mcpwp_Fake_Conditions_Manager {

		/**
		 * @var Mcpwp_Fake_Conditions_Cache
		 */
		private $cache;

		public function get_document_conditions( $document ) {
			return array();
		}

		/**
		 * #952 coverage: writes into the REAL shape Elementor Pro's own
		 * Conditions_Cache uses — `{ location => { template_id =>
		 * [condition_strings] } }`, verified against
		 * ElementorPro\Modules\ThemeBuilder\Classes\Conditions_Cache on a
		 * real install. Mirrors the real method: fetches the document the
		 * same way (`\Elementor\Plugin::instance()->documents->get()`) and
		 * calls `add($document, $conditions)` on THIS request's shared
		 * cache instance (get_cache()), not straight to the option, so a
		 * later regenerate()/purge on that same instance sees it — the
		 * exact staleness this fix's WARN-2 hardening exists for.
		 *
		 * #974/#976 review W1/W2 coverage: also overwrites
		 * `_elementor_conditions` post meta with the SAME stringified form,
		 * mirroring the real Conditions_Manager::save_conditions()'s
		 * `$document->update_meta('_elementor_conditions', $conditions_to_save)`
		 * exactly (confirmed reading the installed conditions-manager.php
		 * directly). Without this, Mcpwp_REST_Elementor_Pro::
		 * sync_custom_code_conditions_cache() — which deliberately reads
		 * that same meta key as its source of truth, the same way
		 * regenerate() does — would read back MCPWP's own pre-conversion
		 * object-array shape instead and push the wrong shape into the
		 * cache right after this method just wrote the correct one.
		 *
		 * #974/#976 review round 2, NIT-3: this used to call
		 * `add($document, ...)->save()` directly, a targeted write with NO
		 * status filter — the real method never calls `add()` itself at
		 * all; it delegates the entire cache write to `regenerate()`,
		 * which DOES filter by status (see that method below). Calling
		 * `add()` directly here made this fixture more forgiving than
		 * production, masking the exact gap
		 * sync_custom_code_conditions_cache() exists to close. Also
		 * returns `false` without writing anything when the id isn't a
		 * recognised document at all, matching the real method's own
		 * `if ( ! $document ) { return false; }` guard.
		 */
		public function save_conditions( $template_id, $conditions ) {
			$document = \Elementor\Plugin::instance()->documents->get( $template_id );

			if ( ! $document ) {
				return false;
			}

			$conditions_to_save = array_map(
				function ( $condition ) {
					return is_array( $condition ) ? rtrim( implode( '/', $condition ), '/' ) : (string) $condition;
				},
				$conditions
			);

			if ( empty( $conditions_to_save ) ) {
				delete_post_meta( $template_id, '_elementor_conditions' );
			} else {
				update_post_meta( $template_id, '_elementor_conditions', $conditions_to_save );
			}

			$this->get_cache()->regenerate();

			return true;
		}

		/**
		 * #952 coverage: mirrors Elementor Pro's real
		 * Conditions_Manager::purge_post_from_cache() exactly —
		 * `$this->cache->remove($post_id)->save()` on THIS request's one
		 * shared Cache instance. Deliberately does NOT refresh from the
		 * option itself (real Elementor Pro doesn't either): that is
		 * exactly the hazard WARN-2 found, and this double exists so a
		 * test can prove Mcpwp_Theme_Builder::cleanup_after_template_delete()
		 * refreshes the cache itself before calling this, not rely on this
		 * method to be safe on its own.
		 */
		public function purge_post_from_cache( $post_id ) {
			if ( ! empty( $GLOBALS['mcpwp_theme_test_purge_throws'] ) ) {
				// #952 coverage: a broken purge must not block the delete
				// itself — the caller only needs to see this NOT propagate.
				throw new \RuntimeException( 'conditions cache purge failed (test double)' );
			}

			$GLOBALS['mcpwp_theme_test_cache_purge_calls'] = ( $GLOBALS['mcpwp_theme_test_cache_purge_calls'] ?? 0 ) + 1;

			$this->get_cache()->remove( $post_id )->save();
		}

		/**
		 * Real Elementor Pro constructs exactly one Conditions_Cache in its
		 * own constructor and returns that same instance from every
		 * get_cache() call for the life of the request — never a fresh one.
		 * Modelling that (lazily, since these tests build the Manager
		 * before touching the option) is what makes the WARN-2 staleness
		 * scenario reproducible at all: without a shared instance, nothing
		 * here could ever go stale.
		 */
		public function get_cache() {
			if ( null === $this->cache ) {
				$this->cache = new Mcpwp_Fake_Conditions_Cache();
			}

			return $this->cache;
		}
	}

	/**
	 * #952 coverage: mirrors Elementor Pro's real Conditions_Cache — an
	 * in-memory `$conditions` array loaded from the option once at
	 * construction (real shape), only ever written back to the option by
	 * an explicit save(), and only ever re-synced with the option by an
	 * explicit refresh() or regenerate(). This statefulness is the point:
	 * WARN-2's hazard is a stale IN-MEMORY copy getting saved over a
	 * fresher on-disk one, which a stateless fake could never reproduce.
	 */
	class Mcpwp_Fake_Conditions_Cache {

		private $conditions;

		public function __construct() {
			$this->refresh();
		}

		/**
		 * Mirrors the real `Conditions_Cache::add(Theme_Document $document,
		 * array $conditions)` exactly (#974/#976 review, W1/W2) — location
		 * and main id both come from the document, never a bare string
		 * passed in by the caller. See Mcpwp_Fake_Document::get_location()/
		 * get_main_id() above.
		 */
		public function add( $document, array $conditions ) {
			$this->conditions[ $document->get_location() ][ (int) $document->get_main_id() ] = $conditions;

			return $this;
		}

		/**
		 * Mirrors the real Conditions_Cache::update(): remove then add,
		 * both keyed off the SAME document — see Mcpwp_Rest_Elementor_Pro::
		 * sync_custom_code_conditions_cache()'s primary path.
		 */
		public function update( $document, $conditions ) {
			return $this->remove( $document->get_main_id() )->add( $document, $conditions );
		}

		public function remove( $post_id ) {
			$post_id = absint( $post_id );

			foreach ( $this->conditions as $location => $templates ) {
				if ( is_array( $templates ) && array_key_exists( $post_id, $templates ) ) {
					unset( $this->conditions[ $location ][ $post_id ] );
				}
			}

			return $this;
		}

		public function save() {
			return update_option( 'elementor_pro_theme_builder_conditions', $this->conditions );
		}

		/**
		 * Re-reads the option into this instance's in-memory state,
		 * discarding whatever it held before. This is the operation #952's
		 * WARN-2 fix calls before every purge_post_from_cache(), and the
		 * one thing this whole fixture exists to prove is actually called.
		 */
		public function refresh() {
			$this->conditions = get_option( 'elementor_pro_theme_builder_conditions', array() );

			return $this;
		}

		public function clear() {
			$this->conditions = array();

			return $this;
		}

		/**
		 * Mirrors the real regenerate(): clear the in-memory state first,
		 * THEN rebuild it from a query, then save(). A crash anywhere in
		 * that rebuild — modelled here as happening immediately after
		 * clear(), the worst case — leaves $conditions empty in memory
		 * while the option on disk is untouched (save() is never
		 * reached), exactly the staleness WARN-2 found.
		 *
		 * #974/#976 review round 2, NIT-3: the rebuild itself used to be
		 * a no-op (clear(), then straight to save() — always ending
		 * empty). The real WP_Query in Conditions_Cache::regenerate()
		 * carries no explicit `post_status` (confirmed reading the
		 * installed conditions-cache.php directly), so outside
		 * `is_admin()` it matches PUBLISHED posts only — the actual root
		 * cause of #974/#976 review W1. Rebuilding from every published
		 * test post carrying `_elementor_conditions` meta (skipping
		 * drafts, matching that same real behaviour) is what makes
		 * ElementorCustomCodeConditionsCacheSyncTest.php's proof that
		 * sync_custom_code_conditions_cache() compensates for it
		 * meaningful, instead of vacuously true against a double that
		 * never populated anything either way.
		 */
		public function regenerate() {
			$this->clear();

			if ( ! empty( $GLOBALS['mcpwp_theme_test_regenerate_throws'] ) ) {
				throw new \RuntimeException( 'conditions cache regenerate failed (test double)' );
			}

			foreach ( $GLOBALS['_mcpwp_test_posts'] ?? array() as $post_id => $post ) {
				if ( ! isset( $post->post_status ) || 'publish' !== $post->post_status ) {
					continue;
				}

				$conditions = get_post_meta( $post_id, '_elementor_conditions', true );
				if ( empty( $conditions ) || ! is_array( $conditions ) ) {
					continue;
				}

				$document = \Elementor\Plugin::instance()->documents->get( $post_id );
				if ( ! $document ) {
					continue;
				}

				$this->add( $document, $conditions );
			}

			$GLOBALS['mcpwp_theme_test_cache_regenerate_calls'] = ( $GLOBALS['mcpwp_theme_test_cache_regenerate_calls'] ?? 0 ) + 1;
			$this->save();

			return $this;
		}
	}

	/**
	 * Only needed so Mcpwp_Theme_Builder::is_available() reports true and
	 * get_template_conditions() has something to call.
	 */
	class Module {

		private static $instance;

		private $conditions_manager;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function get_conditions_manager() {
			if ( null === $this->conditions_manager ) {
				$this->conditions_manager = new Mcpwp_Fake_Conditions_Manager();
			}

			return $this->conditions_manager;
		}
	}
}
