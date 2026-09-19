<?php
/**
 * Elementor doubles whose Document::save() DECLINES — returns the literal
 * bool `false` that Elementor itself returns when
 * is_editable_by_current_user() fails, rather than throwing or returning a
 * WP_Error.
 *
 * This is the real shape of #861: on a PUBLISHED page, the mcpwp_api_agent
 * role has edit_pages/edit_others_pages but not edit_published_pages, so
 * WordPress's own meta-capability map denies edit_post for that page and
 * \Elementor\Core\Base\Document::save() returns exactly `false` — verified on
 * a real WP 7.0.2 + Elementor 4.2.4 rig (see the PR description). No
 * exception, no WP_Error: the two failure modes ElementorSaveThrowableTest
 * already covers. Before the fix, attempt_elementor_call() only recognised
 * those two, so this third one read as `ok => true` and every caller reported
 * document_save/html_cache_rebuild as having succeeded.
 *
 * Deliberately NOT named *Test.php — same reason as
 * elementor-throwable-doubles.php: phpunit.xml's default Test.php suffix
 * would pick this up in the single-process run and \Elementor\Plugin can only
 * be declared once per process (#757). Loaded with require_once from inside a
 * @runInSeparateProcess test only.
 *
 * @package MCPWP
 */

namespace Elementor {

	/**
	 * Stands in for an Elementor document. Declines save() (returns bool
	 * false) per $GLOBALS['mcpwp_test_decline_on']; otherwise behaves like a
	 * real successful save.
	 *
	 * @param string $decline_on 'all'     decline every save() call — the
	 *                                     real #861 shape: the same current
	 *                                     user and the same published page
	 *                                     fail is_editable_by_current_user()
	 *                                     identically on every call within
	 *                                     one request, so the preferred save
	 *                                     AND the html-cache-rebuild save
	 *                                     both decline;
	 *                           'rebuild' let the first save succeed and
	 *                                     decline only the rebuild save —
	 *                                     isolates that code path's own
	 *                                     reporting, the way
	 *                                     ElementorSaveThrowableTest isolates
	 *                                     it for a thrown error;
	 *                           'never'   both saves succeed (control).
	 */
	class Mcpwp_Fake_Declining_Document {

		public function save( $data ) {
			$GLOBALS['mcpwp_test_save_calls'] = ( $GLOBALS['mcpwp_test_save_calls'] ?? 0 ) + 1;

			$is_first   = 1 === $GLOBALS['mcpwp_test_save_calls'];
			$decline_on = $GLOBALS['mcpwp_test_decline_on'] ?? 'all';

			if ( 'all' === $decline_on || ( 'rebuild' === $decline_on && ! $is_first ) ) {
				// This is exactly what Elementor's own core/base/document.php
				// does: `if ( ! $this->is_editable_by_current_user() ) { return
				// false; }` — before touching $data at all. No side effect, no
				// revision, no after_save hook.
				return false;
			}

			return true;
		}
	}

	class Mcpwp_Fake_Declining_Documents {

		public function get( $id, $from_cache = true ) {
			return new Mcpwp_Fake_Declining_Document();
		}
	}

	class Mcpwp_Fake_Declining_Files_Manager {

		public function clear_cache() {}
	}

	/**
	 * Minimal stand-in for \Elementor\Plugin as the writer trait uses it:
	 * Plugin::$instance->documents and Plugin::$instance->files_manager.
	 */
	class Plugin {

		public static $instance;

		public $documents;
		public $files_manager;

		public function __construct() {
			$this->documents     = new Mcpwp_Fake_Declining_Documents();
			$this->files_manager = new Mcpwp_Fake_Declining_Files_Manager();
		}
	}
}
