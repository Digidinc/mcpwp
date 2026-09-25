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

			return true;
		}

		/**
		 * Mcpwp_Theme_Builder::format_template() calls this unconditionally on
		 * whatever Plugin::instance()->documents->get() returns.
		 */
		public function get_edit_url() {
			return 'https://example.test/wp-admin/post.php?action=elementor';
		}
	}

	class Mcpwp_Fake_Documents {

		public function get( $id, $from_cache = true ) {
			return new Mcpwp_Fake_Document();
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

		public function get_document_conditions( $document ) {
			return array();
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
