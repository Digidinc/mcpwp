<?php
/**
 * Regression coverage for #974/#976 adversarial review findings W1, W2, and
 * #991 (folded into the same fix — same underlying gap, same chokepoint).
 *
 * #976 made Mcpwp_Theme_Builder::set_template_conditions() and
 * Mcpwp_REST_Elementor_Pro::save_custom_code_conditions() delegate the
 * shared `elementor_pro_theme_builder_conditions` option entirely to
 * Elementor Pro's own Conditions_Manager/Conditions_Cache instead of writing
 * it themselves under the wrong axis. Correct shape, but review of #990
 * found the delegation wasn't complete:
 *
 *  - W1: `Conditions_Cache::regenerate()`'s rebuild query carries no
 *    explicit `post_status` (confirmed reading the installed
 *    conditions-cache.php), so outside `is_admin()` — every REST request
 *    this plugin serves — it matches PUBLISHED posts only. A snippet
 *    created as draft (with conditions already assigned) never entered
 *    the cache at create time; enabling it later
 *    (enable_custom_code()/update status:publish) is a bare
 *    wp_update_post() with no cache touch at all. Genuinely published,
 *    correctly conditioned, never rendered.
 *  - W2: changing ONLY `location` never ran
 *    save_custom_code_conditions() at all (it only runs when `conditions`
 *    is part of the payload), so the cache kept filing the snippet under
 *    its stale former location.
 *  - #991: force-deleting an `elementor_snippet` left its entry in the
 *    cache forever — #975's `before_delete_post` purge covered
 *    `elementor_library` (Theme Builder templates) only.
 *
 * The fix for all three is Mcpwp_REST_Elementor_Pro::
 * sync_custom_code_conditions_cache() — called unconditionally at the end
 * of save_custom_code_meta() (create + every update, whichever fields
 * changed) and from set_custom_code_status() (enable/disable) — which
 * writes THIS post's current location + conditions directly via Elementor
 * Pro's own Conditions_Cache::update()/add() API, bypassing the
 * status-filtered regenerate() query entirely; and widening
 * Mcpwp_Theme_Builder::handle_before_delete_post()'s post-type gate to
 * also cover `elementor_snippet`, reusing #975's existing (already
 * post-type-agnostic) cleanup_after_template_delete().
 *
 * Each test starts from a KNOWN state (a missing entry, a stale location)
 * rather than relying on this fixture's regenerate() double to faithfully
 * reproduce Elementor's own status-filtering quirk — that reproduction is
 * the rig proof (real Elementor Pro) in the PR description; this file
 * proves the FIX's own mechanism runs at each chokepoint and produces the
 * real `{location => {post_id => [condition_strings]}}` shape.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

final class ElementorCustomCodeConditionsCacheSyncTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['_mcpwp_test_posts']       = array();
		$GLOBALS['_mcpwp_test_meta']        = array();
		$GLOBALS['mcpwp_test_current_user'] = 1;
	}

	private function install_elementor_pro_doubles(): void {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			define( 'ELEMENTOR_VERSION', '4.2.4' );
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );
		}

		require_once __DIR__ . '/fixtures/theme-builder-elementor-doubles.php';
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_syncs_the_cache_entry_immediately_even_as_a_draft(): void {
		$this->install_elementor_pro_doubles();

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->create_custom_code(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/elementor/custom-code',
				array(
					'title'      => 'Fresh draft',
					'code'       => '<div>x</div>',
					'location'   => 'head',
					'status'     => 'draft',
					'conditions' => array(
						array(
							'type' => 'include',
							'name' => 'general',
						),
					),
				)
			)
		);

		$this->assertFalse( is_wp_error( $response ) );
		$post_id = $response->get_data()['id'];

		$option = get_option( 'elementor_pro_theme_builder_conditions', array() );

		$this->assertArrayHasKey(
			'elementor_head',
			$option,
			'A draft snippet must still enter the cache at create time — the whole point of not relying on the status-filtered regenerate().'
		);
		$this->assertArrayHasKey( $post_id, $option['elementor_head'] );
		$this->assertSame( array( 'include/general' ), $option['elementor_head'][ $post_id ] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_refuses_when_pro_cannot_load_the_new_document_for_conditions(): void {
		$this->install_elementor_pro_doubles();
		add_action( 'wp_insert_post', static function ( $post_id, $post ) {
			if ( 'elementor_snippet' === $post->post_type ) {
				$GLOBALS['mcpwp_theme_test_no_document_ids'][] = $post_id;
			}
		}, 1, 2 );
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );

		$result = $controller->create_custom_code( new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code', array( 'title' => 'No Pro document', 'code' => '<div>example</div>', 'status' => 'publish', 'conditions' => array( array( 'type' => 'include', 'name' => 'general' ) ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_refuses_when_pro_conditions_cache_cannot_save(): void {
		$this->install_elementor_pro_doubles();
		$GLOBALS['mcpwp_theme_test_cache_save_throws'] = true;
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );

		$result = $controller->create_custom_code( new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code', array( 'title' => 'No cache', 'code' => '<div>example</div>', 'status' => 'publish', 'conditions' => array( array( 'type' => 'include', 'name' => 'general' ) ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_create_refuses_when_the_cache_reports_a_value_the_database_lacks(): void {
		$this->install_elementor_pro_doubles();
		$GLOBALS['mcpwp_test_option_store_after_hook'] = static function ( $name ) {
			if ( 'elementor_pro_theme_builder_conditions' === $name ) {
				$GLOBALS['mcpwp_test_wpdb_options'][ $name ] = maybe_serialize( array() );
			}
		};
		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );

		$result = $controller->create_custom_code( new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code', array( 'title' => 'False cache', 'code' => '<div>example</div>', 'status' => 'publish', 'conditions' => array( array( 'type' => 'include', 'name' => 'general' ) ) ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_posts'] );
	}

	/**
	 * W1's exact shape: a snippet whose cache entry is missing (as it would
	 * be for any pre-fix data, or any path that ever skipped the
	 * chokepoint), enabled — the /enable route alone must add it.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enabling_a_snippet_whose_cache_entry_is_missing_adds_it(): void {
		$this->install_elementor_pro_doubles();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'elementor_snippet',
				'post_title'  => 'Legacy, never synced',
				'post_status' => 'draft',
			),
			true
		);
		update_post_meta( $post_id, '_elementor_location', 'elementor_body_end' );
		update_post_meta( $post_id, '_elementor_conditions', array( 'include/general' ) );
		update_option( 'elementor_pro_theme_builder_conditions', array() );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->enable_custom_code(
			new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code/' . $post_id . '/enable', array( 'id' => $post_id ) )
		);

		$this->assertFalse( is_wp_error( $response ) );
		$this->assertSame( 'publish', get_post_status( $post_id ) );

		$option = get_option( 'elementor_pro_theme_builder_conditions', array() );

		$this->assertArrayHasKey( 'elementor_body_end', $option );
		$this->assertArrayHasKey( $post_id, $option['elementor_body_end'] );
		$this->assertSame( array( 'include/general' ), $option['elementor_body_end'][ $post_id ] );
	}

	/**
	 * Disabling must sync too, not just enabling — the chokepoint is one
	 * method shared by both routes.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabling_a_snippet_whose_cache_entry_is_missing_adds_it_too(): void {
		$this->install_elementor_pro_doubles();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'elementor_snippet',
				'post_title'  => 'Legacy, never synced',
				'post_status' => 'publish',
			),
			true
		);
		update_post_meta( $post_id, '_elementor_location', 'elementor_head' );
		update_post_meta( $post_id, '_elementor_conditions', array( 'include/general' ) );
		update_option( 'elementor_pro_theme_builder_conditions', array() );

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->disable_custom_code(
			new WP_REST_Request( 'POST', '/mcpwp/v1/elementor/custom-code/' . $post_id . '/disable', array( 'id' => $post_id ) )
		);

		$this->assertFalse( is_wp_error( $response ) );

		$option = get_option( 'elementor_pro_theme_builder_conditions', array() );

		$this->assertArrayHasKey( 'elementor_head', $option );
		$this->assertArrayHasKey( $post_id, $option['elementor_head'] );
	}

	/**
	 * W2's exact shape: an update that changes ONLY location (no
	 * `conditions` in the payload at all — the field that used to be the
	 * only thing that touched the cache) must still move the cache entry.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_location_only_update_moves_the_cache_entry(): void {
		$this->install_elementor_pro_doubles();

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'elementor_snippet',
				'post_title'  => 'Mover',
				'post_status' => 'publish',
			),
			true
		);
		update_post_meta( $post_id, '_elementor_location', 'elementor_head' );
		update_post_meta( $post_id, '_elementor_priority', 1 );
		update_post_meta( $post_id, '_elementor_conditions', array( 'include/general' ) );
		update_option(
			'elementor_pro_theme_builder_conditions',
			array( 'elementor_head' => array( $post_id => array( 'include/general' ) ) )
		);

		$controller = new Mcpwp_REST_Elementor_Pro( new stdClass() );
		$response   = $controller->update_custom_code(
			new WP_REST_Request(
				'PUT',
				'/mcpwp/v1/elementor/custom-code/' . $post_id,
				array(
					'id'       => $post_id,
					'location' => 'body_end',
				)
			)
		);

		$this->assertFalse( is_wp_error( $response ) );
		$this->assertSame( 'body_end', $response->get_data()['snippet']['location'] );

		$option = get_option( 'elementor_pro_theme_builder_conditions', array() );

		$this->assertArrayNotHasKey(
			$post_id,
			$option['elementor_head'] ?? array(),
			'The stale former-location entry must be gone — this is exactly the bug: a location-only update never used to touch the cache at all.'
		);
		$this->assertArrayHasKey( 'elementor_body_end', $option );
		$this->assertArrayHasKey( $post_id, $option['elementor_body_end'] );
		$this->assertSame( array( 'include/general' ), $option['elementor_body_end'][ $post_id ] );
	}

	/**
	 * #991: force-deleting an elementor_snippet must purge its entry —
	 * #975's before_delete_post cleanup covered elementor_library only.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_force_deleting_a_snippet_purges_its_cache_entry(): void {
		$this->install_elementor_pro_doubles();

		$theme_builder = new Mcpwp_Theme_Builder();
		add_action( 'before_delete_post', array( $theme_builder, 'handle_before_delete_post' ), 10, 2 );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'elementor_snippet',
				'post_title'  => 'To be force-deleted',
				'post_status' => 'publish',
			),
			true
		);
		update_option(
			'elementor_pro_theme_builder_conditions',
			array(
				'elementor_head' => array(
					$post_id => array( 'include/general' ),
					999      => array( 'include/general' ),
				),
			)
		);

		wp_delete_post( $post_id, true );

		$option = get_option( 'elementor_pro_theme_builder_conditions', array() );

		$this->assertArrayNotHasKey(
			$post_id,
			$option['elementor_head'] ?? array(),
			'Force-deleting the snippet must purge its own entry.'
		);
		$this->assertArrayHasKey(
			999,
			$option['elementor_head'] ?? array(),
			'An unrelated snippet\'s entry under the same location must survive.'
		);
	}

	/**
	 * The hook must still ignore an ordinary post — widening the gate to
	 * include elementor_snippet must not turn it into "every post type".
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_deleting_an_unrelated_post_type_does_not_touch_the_cache(): void {
		$this->install_elementor_pro_doubles();

		$theme_builder = new Mcpwp_Theme_Builder();
		add_action( 'before_delete_post', array( $theme_builder, 'handle_before_delete_post' ), 10, 2 );

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Just a page',
				'post_status' => 'publish',
			),
			true
		);
		update_option(
			'elementor_pro_theme_builder_conditions',
			array( 'elementor_head' => array( $post_id => array( 'include/general' ) ) )
		);

		wp_delete_post( $post_id, true );

		$option = get_option( 'elementor_pro_theme_builder_conditions', array() );

		$this->assertSame(
			array( $post_id => array( 'include/general' ) ),
			$option['elementor_head'],
			'A page is not a Theme Builder template or a Custom Code snippet — the hook must leave the option untouched.'
		);
	}
}
