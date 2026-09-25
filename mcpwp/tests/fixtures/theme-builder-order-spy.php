<?php
/**
 * A Mcpwp_Theme_Builder subclass that records the template's post_status at
 * the moment assign_to_location() is invoked — used to assert the ordering
 * fix for the RC review BLOCK (2026-09-25): conditions must be assigned only
 * AFTER the template is published, never before.
 *
 * Deliberately NOT named *Test.php, and loaded with require_once from inside
 * a single test only, matching the other Elementor-double fixtures in this
 * directory (see #757 for why declarations do not belong in the main run) —
 * even though this class itself is Elementor-agnostic, keeping the loading
 * convention identical avoids a special case.
 *
 * @package MCPWP
 */

if ( ! class_exists( 'Mcpwp_Test_Theme_Builder_Order_Spy' ) ) {

	class Mcpwp_Test_Theme_Builder_Order_Spy extends Mcpwp_Theme_Builder {

		/** @var bool */
		public $assign_to_location_was_called = false;

		/** @var string|null */
		public $post_status_when_assign_to_location_ran = null;

		public function assign_to_location( $template_id, $location, $scope = 'entire_site', $options = array() ) {
			$this->assign_to_location_was_called = true;

			$post                                          = get_post( $template_id );
			$this->post_status_when_assign_to_location_ran = $post ? $post->post_status : null;

			return parent::assign_to_location( $template_id, $location, $scope, $options );
		}
	}
}
