<?php
/**
 * #752 F6 — GET /rate-limit when the relay controller is not loaded.
 *
 * "Could not determine the admission mode" must not share an answer with
 * either real mode. In particular it must not read as "approximate", which
 * an operator would take as a statement about their object cache.
 *
 * Runs in a separate process because every other relay test file
 * require_once's the feedback controller, and a loaded class cannot be
 * unloaded. This file deliberately does not load it.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class RelayAdmissionStatusUnavailableTest extends TestCase {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_rate_limit_status_says_unavailable_when_the_relay_controller_is_absent(): void {
		$this->assertFalse( class_exists( 'Mcpwp_REST_Feedback', false ), 'Precondition: the relay controller is not loaded in this process.' );

		$GLOBALS['mcpwp_test_ext_object_cache'] = true;

		$controller = new Mcpwp_REST_Site_Updates();
		$data       = $controller->get_rate_limit_status( new WP_REST_Request( 'GET', '/mcpwp/v1/rate-limit' ) )->get_data();

		$this->assertArrayHasKey( 'relay_admission', $data );
		$this->assertSame( 'unavailable', $data['relay_admission'] );
		$this->assertArrayNotHasKey( 'relay_limits', $data, 'Limits of a controller that is not loaded are not known.' );
	}
}
