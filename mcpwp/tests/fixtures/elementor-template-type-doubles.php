<?php
/**
 * Elementor doubles for the live template-type registry tests (#827).
 *
 * Deliberately NOT named *Test.php: phpunit.xml collects the tests directory
 * with the default Test.php suffix, and \Elementor\Plugin must never be
 * declared in the main single-process run — doing so changes the behaviour of
 * every Elementor-sensitive test that follows (see #757).
 *
 * Loaded with require_once from inside a @runInSeparateProcess test only.
 *
 * The document type map comes from a global rather than being baked in, so
 * one process can model a registry that offers "single-post" as its own type
 * (Elementor Pro 4.1+) and another can model an install where the registry
 * cannot be read at all.
 *
 * @package MCPWP
 */

namespace Elementor {

	/**
	 * A fake Elementor document class. Every type under test gets its own
	 * subclass (Elementor's own document types are one class per type, and
	 * get_property() is called statically on the class name Documents_Manager
	 * hands back — a single class with a settable property would not model
	 * that shape).
	 */
	abstract class Mcpwp_Fake_Document {

		/**
		 * @param string $key Property name.
		 * @return mixed
		 */
		public static function get_property( $key ) {
			$props = $GLOBALS['mcpwp_test_document_properties'][ static::class ] ?? array();

			return array_key_exists( $key, $props ) ? $props[ $key ] : null;
		}
	}

	class Mcpwp_Fake_Doc_Page extends Mcpwp_Fake_Document {}
	class Mcpwp_Fake_Doc_Section extends Mcpwp_Fake_Document {}
	class Mcpwp_Fake_Doc_Single extends Mcpwp_Fake_Document {}
	class Mcpwp_Fake_Doc_Single_Post extends Mcpwp_Fake_Document {}
	class Mcpwp_Fake_Doc_Single_Page extends Mcpwp_Fake_Document {}
	class Mcpwp_Fake_Doc_Post extends Mcpwp_Fake_Document {}
	class Mcpwp_Fake_Doc_Widget extends Mcpwp_Fake_Document {}

	class Mcpwp_Fake_Documents_Manager {

		/**
		 * @param array $args Ignored — Elementor's own signature takes args; the
		 *                    plugin never passes any.
		 * @return array Type slug => document class name.
		 */
		public function get_document_types( $args = array() ) {
			if ( ! empty( $GLOBALS['mcpwp_test_documents_manager_missing'] ) ) {
				// Models get_document_types() itself being unreachable — a
				// method_exists() check away from where a live install would
				// actually fail. See the "registry missing entirely" test.
				return array();
			}

			return $GLOBALS['mcpwp_test_document_types'] ?? array();
		}
	}

	class Plugin {

		public static $instance;

		public $documents;

		public function __construct() {
			$this->documents = ( ! empty( $GLOBALS['mcpwp_test_no_documents_manager'] ) )
				? null
				: new Mcpwp_Fake_Documents_Manager();
		}

		public static function instance() {
			if ( empty( self::$instance ) ) {
				self::$instance = new self();
			}

			return self::$instance;
		}
	}
}
