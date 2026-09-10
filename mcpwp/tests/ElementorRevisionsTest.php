<?php
/**
 * #820 — undo for Elementor writes, built on WordPress revisions.
 *
 * What a real install did before this change (WordPress 7.0.2, Elementor
 * 4.2.4, MCPWP main c6cd59f, 2026-09-10), writing over HTTP with an API key:
 * wp_set_elementor twice, wp_edit_widget, wp_edit_section, wp_add_section,
 * wp_patch_elementor, wp_replace_section, wp_remove_section, find/replace,
 * template create/update/apply, part apply, kit CSS and a kit CSS block —
 * and not one revision was created for the page, the template or the kit.
 *
 * The reason is not that Elementor lacks revisions — its own Document::save()
 * run as an administrator made one per save. It is that MCPWP writes as the
 * `mcpwp_bot` API user, whose role has no `edit_published_pages`, so
 * Document::is_editable_by_current_user() is false and Document::save()
 * returns false before it saves anything. MCPWP's raw meta overwrite is what
 * actually stores the data, and WordPress never hears about it. On a draft,
 * where Elementor does save, the revision it makes holds Elementor's copy of
 * the tree from before that overwrite.
 *
 * So every MCPWP Elementor write now makes the revision itself, through one
 * helper: before the write it keeps the state being replaced (unless the
 * newest revision already holds it), after the write exactly one revision
 * holds what was stored.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/**
 * The write path as the revert sees it: validate_post() and
 * set_elementor_data(). Like the real writer it wraps its own write in the
 * revision helper, so a revert exercises the nesting.
 */
final class Mcpwp_Test_Revert_Writer {

	/** @var array */
	public $calls = array();

	/** @var bool False models a write path that reports success and stores nothing. */
	public $write = true;

	public function validate_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, array( 'page', 'post', 'elementor_library' ), true ) ) {
			return new WP_Error( 'not_found', 'Post not found or unsupported type.', array( 'status' => 404 ) );
		}

		return $post;
	}

	public function set_elementor_data( $post_id, $data, $dry_run = false ) {
		$this->calls[] = array(
			'post_id' => $post_id,
			'data'    => $data,
			'dry_run' => $dry_run,
		);

		if ( $dry_run ) {
			return array(
				'success'  => true,
				'dry_run'  => true,
				'warnings' => array(),
			);
		}

		$token = Mcpwp_Elementor_Revisions::begin( $post_id );

		if ( $this->write ) {
			update_post_meta( $post_id, '_elementor_data', wp_slash( $data['elementor_data'] ) );
		}

		return array(
			'success'         => true,
			'render_verified' => true,
			'debug'           => array( 'revision' => Mcpwp_Elementor_Revisions::commit( $token ) ),
		);
	}
}

final class ElementorRevisionsTest extends TestCase {

	private const PAGE  = 10;
	private const OTHER = 11;
	private const KIT   = 12;

	private function boot(): void {
		require_once __DIR__ . '/fixtures/wp-revision-doubles.php';

		$GLOBALS['_mcpwp_test_posts']            = array();
		$GLOBALS['_mcpwp_test_meta']             = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_deleted_revisions'] = array();

		foreach ( array( self::PAGE, self::OTHER ) as $id ) {
			$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
				array(
					'ID'          => $id,
					'post_type'   => 'page',
					'post_status' => 'publish',
					'post_title'  => "Page {$id}",
					'post_name'   => "page-{$id}",
				)
			);
		}

		$this->store( self::PAGE, $this->tree( 'V1' ) );
	}

	private function tree( string $title, int $sections = 1 ): array {
		$tree = array();

		for ( $i = 0; $i < $sections; $i++ ) {
			$tree[] = array(
				'id'       => 's' . sprintf( '%06d', $i ),
				'elType'   => 'section',
				'settings' => array(),
				'elements' => array(
					array(
						'id'       => 'c' . sprintf( '%06d', $i ),
						'elType'   => 'column',
						'settings' => array( '_column_size' => 100 ),
						'elements' => array(
							array(
								'id'         => 'h' . sprintf( '%06d', $i ),
								'elType'     => 'widget',
								'widgetType' => 'heading',
								'settings'   => array( 'title' => $title ),
							),
						),
					),
				),
			);
		}

		return $tree;
	}

	private function store( int $id, array $tree ): void {
		update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $tree ) ) );
	}

	private function stored_title( $id ): ?string {
		$tree = json_decode( (string) get_post_meta( (int) $id, '_elementor_data', true ), true );

		return is_array( $tree ) ? $tree[0]['elements'][0]['elements'][0]['settings']['title'] : null;
	}

	private function revisions( int $id ): array {
		return wp_get_post_revisions( $id, array( 'check_enabled' => false ) );
	}

	private function write( int $id, array $tree ): array {
		$token = Mcpwp_Elementor_Revisions::begin( $id );
		$this->store( $id, $tree );

		return Mcpwp_Elementor_Revisions::commit( $token );
	}

	private function classic_revision( int $parent, int $id = 8000 ): int {
		$GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(
			array(
				'ID'          => $id,
				'post_type'   => 'revision',
				'post_parent' => $parent,
				'post_name'   => "{$parent}-revision-v1",
				'post_author' => 1,
				'post_date'   => '2026-01-01 00:00:00',
			)
		);

		return $id;
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_write_keeps_the_state_it_replaced_and_the_state_it_wrote(): void {
		$this->boot();

		$report = $this->write( self::PAGE, $this->tree( 'V2' ) );

		$this->assertTrue( $report['revisions_enabled'] );
		$this->assertSame( 'V1', $this->stored_title( $report['previous_revision_id'] ), 'The page had never been revisioned; without a checkpoint V1 is gone for good.' );
		$this->assertSame( 'V2', $this->stored_title( $report['revision_id'] ) );
		$this->assertCount( 2, $this->revisions( self::PAGE ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_no_checkpoint_is_added_when_the_newest_revision_already_holds_the_page(): void {
		$this->boot();

		$first  = $this->write( self::PAGE, $this->tree( 'V2' ) );
		$second = $this->write( self::PAGE, $this->tree( 'V3' ) );

		$this->assertSame( $first['revision_id'], $second['previous_revision_id'] );
		$this->assertCount( 3, $this->revisions( self::PAGE ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_revision_made_mid_write_is_corrected_to_what_was_stored(): void {
		$this->boot();

		$token = Mcpwp_Elementor_Revisions::begin( self::PAGE );

		// Elementor's Document::save() on a draft: it stores its own copy of
		// the tree and WordPress makes a revision of that.
		$this->store( self::PAGE, $this->tree( 'ELEMENTOR-COPY' ) );
		$GLOBALS['_mcpwp_test_posts'][ self::PAGE ]->post_content = 'ELEMENTOR-COPY';
		$mid = wp_save_post_revision( self::PAGE );
		update_metadata( 'post', $mid, '_elementor_data', wp_slash( wp_json_encode( $this->tree( 'ELEMENTOR-COPY' ) ) ) );

		// Then MCPWP's raw overwrite, which is what the page really holds.
		$this->store( self::PAGE, $this->tree( 'FINAL' ) );

		$report = Mcpwp_Elementor_Revisions::commit( $token );

		$this->assertSame( 'FINAL', $this->stored_title( $report['revision_id'] ) );
		$this->assertCount( 2, $this->revisions( self::PAGE ), 'One write, one new revision.' );

		foreach ( $this->revisions( self::PAGE ) as $revision ) {
			$this->assertNotSame( 'ELEMENTOR-COPY', $this->stored_title( $revision->ID ), 'A revision of a tree the page never held would be a wrong undo target.' );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_write_that_changed_nothing_adds_no_revision(): void {
		$this->boot();

		$first = $this->write( self::PAGE, $this->tree( 'V2' ) );
		$count = count( $this->revisions( self::PAGE ) );
		$again = $this->write( self::PAGE, $this->tree( 'V2' ) );

		$this->assertCount( $count, $this->revisions( self::PAGE ) );
		$this->assertFalse( $again['created'] );
		$this->assertSame( $first['revision_id'], $again['revision_id'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_revisions_are_said_rather_than_shown_as_empty_history(): void {
		$this->boot();
		$GLOBALS['mcpwp_test_revisions_to_keep'] = 0;

		$report = $this->write( self::PAGE, $this->tree( 'V2' ) );

		$this->assertFalse( $report['revisions_enabled'] );
		$this->assertNull( $report['revision_id'] );
		$this->assertStringContainsString( 'disabled', $report['reason'] );

		$list = Mcpwp_Elementor_Revisions::list_revisions( self::PAGE );

		$this->assertFalse( $list['revisions_enabled'] );
		$this->assertSame( array(), $list['revisions'] );
		$this->assertStringContainsString( 'disabled', $list['note'], 'An empty list with nothing else reads as "this page has no history".' );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_revision_limit_is_reported_in_the_list(): void {
		$this->boot();
		$GLOBALS['mcpwp_test_revisions_to_keep'] = 3;

		$this->write( self::PAGE, $this->tree( 'V2' ) );
		$list = Mcpwp_Elementor_Revisions::list_revisions( self::PAGE );

		$this->assertTrue( $list['revisions_enabled'] );
		$this->assertSame( 3, $list['limit'] );
		$this->assertStringContainsString( '3', $list['note'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_list_describes_each_revision(): void {
		$this->boot();

		$this->classic_revision( self::PAGE );
		$this->write( self::PAGE, $this->tree( 'V2', 3 ) );

		$list = Mcpwp_Elementor_Revisions::list_revisions( self::PAGE );
		$rows = array_column( $list['revisions'], null, 'id' );

		$this->assertSame( 3, $list['total'] );
		$this->assertFalse( $rows[8000]['has_elementor_data'] );
		$this->assertNull( $rows[8000]['sections'] );

		$newest = $list['revisions'][0];
		$this->assertTrue( $newest['has_elementor_data'] );
		$this->assertSame( 3, $newest['sections'] );
		$this->assertSame( 3, $newest['widgets'] );
		$this->assertTrue( $newest['matches_current'] );
		$this->assertSame( 2, $newest['author']['id'] );

		$older = $list['revisions'][1];
		$this->assertSame( 1, $older['sections'] );
		$this->assertFalse( $older['matches_current'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_revert_refuses_anything_but_an_elementor_revision_of_this_post(): void {
		$this->boot();
		$writer = new Mcpwp_Test_Revert_Writer();

		$this->write( self::PAGE, $this->tree( 'V2' ) );
		$this->store( self::OTHER, $this->tree( 'O1' ) );
		$other   = $this->write( self::OTHER, $this->tree( 'O2' ) );
		$classic = $this->classic_revision( self::PAGE );

		$cases = array(
			'revision_not_found'             => 123456,
			'not_a_revision'                 => self::OTHER,
			'revision_of_another_post'       => $other['previous_revision_id'],
			'revision_has_no_elementor_data' => $classic,
		);

		foreach ( $cases as $code => $revision_id ) {
			$result = Mcpwp_Elementor_Revisions::revert( $writer, self::PAGE, $revision_id );

			$this->assertInstanceOf( WP_Error::class, $result, $code );
			$this->assertSame( $code, $result->get_error_code() );
		}

		$this->assertSame( array(), $writer->calls, 'A refused revert must not reach the write path.' );
		$this->assertSame( 'V2', $this->stored_title( self::PAGE ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_revert_goes_through_the_write_path_and_can_itself_be_reverted(): void {
		$this->boot();
		$writer = new Mcpwp_Test_Revert_Writer();

		$first  = $this->write( self::PAGE, $this->tree( 'V2' ) );
		$result = Mcpwp_Elementor_Revisions::revert( $writer, self::PAGE, $first['previous_revision_id'] );

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'V1', $this->stored_title( self::PAGE ) );

		$this->assertCount( 1, $writer->calls );
		$this->assertFalse( $writer->calls[0]['dry_run'] );
		$restored = json_decode( $writer->calls[0]['data']['elementor_data'], true );
		$this->assertSame( 'V1', $restored[0]['elements'][0]['elements'][0]['settings']['title'] );

		$this->assertSame( 'V2', $this->stored_title( $result['undo']['revision_id'] ), 'The state the revert replaced must be a revision the caller can return to.' );
		$this->assertSame( 'V1', $this->stored_title( $result['revision']['revision_id'] ) );
		$this->assertCount( 3, $this->revisions( self::PAGE ), 'The writer wraps its own write too; a revert is still one revision, not two.' );

		$back = Mcpwp_Elementor_Revisions::revert( $writer, self::PAGE, $result['undo']['revision_id'] );

		$this->assertIsArray( $back );
		$this->assertSame( 'V2', $this->stored_title( self::PAGE ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_revert_that_did_not_change_the_stored_data_is_not_a_success(): void {
		$this->boot();
		$writer        = new Mcpwp_Test_Revert_Writer();
		$writer->write = false;

		$first  = $this->write( self::PAGE, $this->tree( 'V2' ) );
		$result = Mcpwp_Elementor_Revisions::revert( $writer, self::PAGE, $first['previous_revision_id'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'revert_not_applied', $result->get_error_code() );
		$this->assertSame( 'V2', $this->stored_title( self::PAGE ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_reverting_to_the_state_the_page_already_holds_is_refused(): void {
		$this->boot();
		$writer = new Mcpwp_Test_Revert_Writer();

		$first  = $this->write( self::PAGE, $this->tree( 'V2' ) );
		$result = Mcpwp_Elementor_Revisions::revert( $writer, self::PAGE, $first['revision_id'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'revert_no_change', $result->get_error_code() );
		$this->assertSame( array(), $writer->calls );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_dry_run_changes_nothing(): void {
		$this->boot();
		$writer = new Mcpwp_Test_Revert_Writer();

		$first  = $this->write( self::PAGE, $this->tree( 'V2', 2 ) );
		$before = count( $this->revisions( self::PAGE ) );
		$result = Mcpwp_Elementor_Revisions::revert( $writer, self::PAGE, $first['previous_revision_id'], array( 'dry_run' => true ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['dry_run'] );
		$this->assertTrue( $result['would_change']['elementor_data'] );
		$this->assertSame( 2, $result['current']['sections'] );
		$this->assertSame( 1, $result['revision']['sections'] );
		$this->assertSame( 'V2', $this->stored_title( self::PAGE ) );
		$this->assertCount( $before, $this->revisions( self::PAGE ) );

		foreach ( $writer->calls as $call ) {
			$this->assertTrue( $call['dry_run'] );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_revert_is_refused_when_it_could_not_be_undone(): void {
		$this->boot();
		$writer = new Mcpwp_Test_Revert_Writer();

		$first = $this->write( self::PAGE, $this->tree( 'V2' ) );
		$GLOBALS['mcpwp_test_revisions_to_keep'] = 0;

		$result = Mcpwp_Elementor_Revisions::revert( $writer, self::PAGE, $first['previous_revision_id'] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'revisions_disabled', $result->get_error_code() );
		$this->assertSame( array(), $writer->calls );
		$this->assertSame( 'V2', $this->stored_title( self::PAGE ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_kit_settings_are_restored_from_a_revision(): void {
		$this->boot();
		$writer = new Mcpwp_Test_Revert_Writer();

		// Elementor registers `revisions` support on elementor_library (observed
		// on the real install; the kit had 0 revisions after four kit writes).
		$GLOBALS['mcpwp_test_post_type_supports']['elementor_library'] = array( 'revisions', 'elementor' );
		$GLOBALS['_mcpwp_test_posts'][ self::KIT ]                    = new WP_Post(
			array(
				'ID'          => self::KIT,
				'post_type'   => 'elementor_library',
				'post_status' => 'publish',
				'post_title'  => 'Default Kit',
			)
		);
		$colors = array( array( '_id' => 'primary', 'color' => '#111111' ) );
		update_post_meta( self::KIT, '_elementor_template_type', 'kit' );
		update_post_meta( self::KIT, '_elementor_page_settings', array( 'custom_css' => '.a{color:red}', 'system_colors' => $colors ) );

		$token = Mcpwp_Elementor_Revisions::begin( self::KIT );
		update_post_meta( self::KIT, '_elementor_page_settings', array( 'custom_css' => '.a{color:blue}', 'system_colors' => $colors ) );
		$report = Mcpwp_Elementor_Revisions::commit( $token );

		$this->assertNotNull( $report['revision_id'], 'Kit CSS and globals are the kit post\'s page settings; they need the same history.' );

		$result = Mcpwp_Elementor_Revisions::revert( $writer, self::KIT, $report['previous_revision_id'] );

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$settings = get_post_meta( self::KIT, '_elementor_page_settings', true );
		$this->assertSame( '.a{color:red}', $settings['custom_css'] );
		$this->assertSame( $colors, $settings['system_colors'] );
		$this->assertSame( array(), $writer->calls, 'The kit has no element tree to send through the element write path.' );
		$this->assertSame( '.a{color:blue}', get_post_meta( $result['undo']['revision_id'], '_elementor_page_settings', true )['custom_css'] );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_the_surgical_write_path_leaves_a_revision(): void {
		$this->boot();

		$basic  = ( new ReflectionClass( Mcpwp_Elementor_Basic::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( Mcpwp_Elementor_Basic::class, 'save_elements_to_page' );
		$method->setAccessible( true );

		$debug = $method->invoke( $basic, self::PAGE, $this->tree( 'SURGICAL' ), array() );

		$this->assertIsArray( $debug, $debug instanceof WP_Error ? $debug->get_error_message() : '' );
		$this->assertArrayHasKey( 'revision', $debug, 'add/remove/replace/patch/edit and part insert all save through here.' );
		$this->assertSame( 'SURGICAL', $this->stored_title( $debug['revision']['revision_id'] ) );
		$this->assertSame( 'V1', $this->stored_title( $debug['revision']['previous_revision_id'] ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_find_replace_leaves_a_revision(): void {
		$this->boot();
		require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';

		$controller = new Mcpwp_REST_Elementor();
		$response   = $controller->find_replace(
			new WP_REST_Request(
				'POST',
				'/mcpwp/v1/elementor/' . self::PAGE . '/find-replace',
				array(
					'id'      => self::PAGE,
					'search'  => 'V1',
					'replace' => 'V9',
				)
			)
		);
		$data       = $response->get_data();

		$this->assertSame( 'V9', $this->stored_title( self::PAGE ) );
		$this->assertArrayHasKey( 'revision', $data, 'find/replace writes _elementor_data directly; it must not be the one write with no undo.' );
		$this->assertSame( 'V1', $this->stored_title( $data['revision']['previous_revision_id'] ) );
	}

	public function test_the_tools_are_registered_with_scope_category_capability_and_route(): void {
		$tools = ( new ReflectionClass( Mcpwp_MCP_Free_Tools::class ) )->newInstanceWithoutConstructor();
		$names = array_column( $tools->get_tools(), 'name' );
		$cats  = $tools->get_tool_categories();
		$caps  = $tools->get_required_capabilities();
		$map   = $tools->get_tool_map();

		foreach ( array( 'wp_list_elementor_revisions', 'wp_revert_elementor' ) as $tool ) {
			$this->assertContains( $tool, $names );
			$this->assertSame( 'elementor', $cats[ $tool ] ?? null, $tool );
			$this->assertSame( 'elementor', $caps[ $tool ] ?? null, $tool );
		}

		$this->assertSame( array( 'method' => 'GET', 'route' => '/elementor/{id}/revisions' ), $map['wp_list_elementor_revisions'] );
		$this->assertSame( array( 'method' => 'POST', 'route' => '/elementor/{id}/revert' ), $map['wp_revert_elementor'] );

		$api   = ( new ReflectionClass( Mcpwp_REST_MCP::class ) )->newInstanceWithoutConstructor();
		$scope = new ReflectionMethod( $api, 'get_required_scope_for_tool_name' );
		$scope->setAccessible( true );

		$this->assertSame( 'write', $scope->invoke( $api, 'wp_revert_elementor' ), 'A revert writes the page; a read-scoped key must not reach it.' );
		$this->assertSame( 'read', $scope->invoke( $api, 'wp_list_elementor_revisions' ) );

		require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-action-log.php';
		$is_write = new ReflectionMethod( Mcpwp_Action_Log::class, 'is_write_tool' );
		$is_write->setAccessible( true );
		$detect = new ReflectionMethod( Mcpwp_Action_Log::class, 'detect_resource' );
		$detect->setAccessible( true );

		$this->assertTrue( $is_write->invoke( null, 'wp_revert_elementor' ) );
		$this->assertSame( array( 'type' => 'elementor', 'id' => 7 ), $detect->invoke( null, 'wp_revert_elementor', array( 'id' => 7, 'revision_id' => 9 ) ) );
	}
}
