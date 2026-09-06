<?php
/**
 * Elementor doubles whose Document::save() walks the KSES allow-list the way
 * Elementor 4.2.4 does.
 *
 * Deliberately NOT named *Test.php: phpunit.xml collects the tests directory
 * with the default Test.php suffix, and \Elementor\Plugin must never be
 * declared in the main single-process run — doing so changes the behaviour of
 * every Elementor-sensitive test that follows (see #757).
 *
 * Loaded with require_once from inside a @runInSeparateProcess test only.
 *
 * @package MCPWP
 */

namespace Elementor {

	/**
	 * Reproduces Elementor\Widget_Heading::sanitize(), the method the
	 * content-sanitizer module calls on every heading saved by a user without
	 * manage_options. Verbatim shape of includes/widgets/heading.php:125-134 in
	 * 4.2.4 (file SHA-256 6d09c2a5df1ab7d490a7c76df2c99c12b11d0578db1b41bf5a643589cb816ae6):
	 * read the 'post' allow-list, then hand every value to array_filter().
	 *
	 * A tag mapped to `true` — which WordPress core supports and acts on in
	 * wp_kses_attr() — is a TypeError here. That is #765.
	 */
	class Mcpwp_Fake_Document {

		public function save( $data ) {
			$GLOBALS['mcpwp_test_save_calls'] = isset( $GLOBALS['mcpwp_test_save_calls'] )
				? $GLOBALS['mcpwp_test_save_calls'] + 1
				: 1;

			// Recorded before any throw: a test needs to see the allow-list as it
			// looked INSIDE the call, which is the only place the normaliser is
			// meant to be installed.
			$GLOBALS['mcpwp_test_allow_list_seen'] = wp_kses_allowed_html( 'post' );

			if ( ! empty( $GLOBALS['mcpwp_test_force_throw'] ) ) {
				throw new \RuntimeException( 'synthetic Elementor failure' );
			}

			if ( ! empty( $GLOBALS['mcpwp_test_fail_first_save_only'] ) && 1 === $GLOBALS['mcpwp_test_save_calls'] ) {
				throw new \RuntimeException( 'synthetic first-save failure' );
			}

			if ( ! empty( $GLOBALS['mcpwp_test_force_wp_error'] ) ) {
				return new \WP_Error( 'synthetic_elementor_error', 'synthetic returned Elementor failure' );
			}

			if ( ! empty( $GLOBALS['mcpwp_test_corrupt_rebuild'] ) && 2 === $GLOBALS['mcpwp_test_save_calls'] ) {
				\update_post_meta( $GLOBALS['mcpwp_test_corrupt_page_id'], '_elementor_data', '[]' );
			}

			$allowed_tags = $GLOBALS['mcpwp_test_allow_list_seen'];

			foreach ( $allowed_tags as $tag => $attributes ) {
				array_filter(
					$attributes,
					static function ( $attribute ) {
						return ! substr( $attribute, 0, 5 ) === 'data-';
					},
					ARRAY_FILTER_USE_KEY
				);
			}

			return true;
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

	class Plugin {

		public static $instance;

		public $documents;
		public $files_manager;

		public function __construct() {
			$this->documents     = new Mcpwp_Fake_Documents();
			$this->files_manager = new Mcpwp_Fake_Files_Manager();
		}
	}
}
