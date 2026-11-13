<?php
/** Menu creation validates items before changing menu state (#1112). */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-site-manager.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-menus.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-site-manager.php';

final class MenuCreateValidation1112Test extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['_mcpwp_test_nav_menus'] = array();
		$GLOBALS['_mcpwp_test_menu_page_ids'] = array();
		$GLOBALS['_mcpwp_test_nav_menu_item_writes'] = array();
		$GLOBALS['_mcpwp_test_nav_menu_deletions'] = array();
		unset( $GLOBALS['_mcpwp_test_nav_menu_item_write_result'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_mcpwp_test_nav_menu_item_write_result'] );
	}

	public static function invalid_items(): array {
		$cases = array();
		foreach ( array( 'string' => 'x', 'null' => null, 'integer' => 5, 'list' => array( array( 'title' => 'Example' ) ), 'empty' => array(), 'object' => (object) array( 'title' => 'Example' ) ) as $label => $item ) {
			$cases[ $label ] = array( array( $item ) );
			$cases[ 'mixed-' . $label ] = array( array( array( 'type' => 'custom', 'title' => 'Example', 'url' => 'https://example.test' ), $item ) );
		}
		$cases['missing-object'] = array( array( array( 'type' => 'post_type' ) ) );
		$cases['invalid-field'] = array( array( array( 'type' => 'custom', 'title' => array( 'Example' ) ) ) );
		return $cases;
	}

	/** @dataProvider invalid_items */
	public function test_invalid_items_do_not_create_a_menu( $items ): void {
		$result = ( new Mcpwp_Site_Manager() )->create_menu( array( 'name' => 'Example', 'items' => $items ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'item_errors', $result->get_error_data() );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menus'] );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
	}

	/** @dataProvider invalid_items */
	public function test_single_add_returns_a_validation_error_for_invalid_item_shapes( $items ): void {
		$menu_id = wp_create_nav_menu( 'Existing' );
		$result = ( new Mcpwp_Site_Manager() )->add_menu_item( $menu_id, end( $items ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
	}

	public function test_a_write_error_does_not_leave_an_empty_menu(): void {
		$GLOBALS['_mcpwp_test_nav_menu_item_write_result'] = static function () { return new WP_Error( 'write_failed', 'Write failed.' ); };
		$result = ( new Mcpwp_Site_Manager() )->create_menu( array( 'name' => 'Example', 'items' => array( array( 'type' => 'custom', 'title' => 'Example' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menus'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_nav_menu_deletions'] );
	}

	public function test_failed_item_ids_do_not_leave_an_empty_menu(): void {
		foreach ( array( 0, false ) as $item_id ) {
			$GLOBALS['_mcpwp_test_nav_menu_item_write_result'] = static function () use ( $item_id ) { return $item_id; };
			$result = ( new Mcpwp_Site_Manager() )->create_menu( array( 'name' => 'Example', 'items' => array( array( 'title' => 'Example' ) ) ) );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menus'] );
		}
	}

	public function test_validation_happens_before_a_visibility_callback_failure(): void {
		$result = ( new Mcpwp_Site_Manager() )->create_menu( array(
			'name' => 'Example', 'items' => array( array( 'type' => 'post_type', 'object' => 'page', 'object_id' => 42 ) ),
			'item_object_visible' => static function () { throw new RuntimeException( 'Visibility check failed.' ); },
		) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menus'] );
	}

	public function test_valid_items_and_intentionally_empty_menus_still_succeed(): void {
		$manager = new Mcpwp_Site_Manager();
		$this->assertIsArray( $manager->create_menu( array( 'name' => 'Empty', 'items' => array() ) ) );
		$this->assertIsArray( $manager->create_menu( array( 'name' => 'Example', 'items' => array( array( 'type' => 'custom', 'title' => 'Example', 'url' => 'https://example.test' ) ) ) ) );
		$this->assertCount( 2, $GLOBALS['_mcpwp_test_nav_menus'] );
		$this->assertCount( 1, $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
	}

	public function test_a_throwable_after_a_successful_item_rolls_back_the_menu(): void {
		$calls = 0;
		$GLOBALS['_mcpwp_test_nav_menu_item_write_result'] = static function () use ( &$calls ) {
			if ( ++$calls === 2 ) { throw new TypeError( 'Item write failed.' ); }
			return 1001;
		};
		$result = ( new Mcpwp_Site_Manager() )->create_menu( array( 'name' => 'Example', 'items' => array( array( 'title' => 'First' ), array( 'title' => 'Second' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'menu_create_failed', $result->get_error_code() );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menus'] );
	}

	public function test_a_failed_readback_rolls_back_the_menu(): void {
		$manager = new class extends Mcpwp_Site_Manager {
			public function get_menu( $menu_id ) { return new WP_Error( 'read_failed', 'Read failed.' ); }
		};
		$result = $manager->create_menu( array( 'name' => 'Example' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menus'] );
	}

	public function test_type_accessor_matches_query_fallback_under_json_null(): void {
		wp_create_nav_menu( 'Existing' );
		$plain = new WP_REST_Request( 'POST', '/mcpwp/v1/menus/1/items' );
		$plain->set_query_params( array( 'type' => 'post_type', 'menu_id' => 1, 'title' => 'Example' ) );
		$mixed = clone $plain;
		$mixed->set_json_params( array( 'type' => null ) );
		$this->assertNull( $mixed->get_params()['type'] );
		$this->assertSame( 'post_type', $mixed->get_param( 'type' ) );
		foreach ( array( new Mcpwp_REST_Menus(), new Mcpwp_REST_Site_Manager( new Mcpwp_Site_Manager() ) ) as $controller ) {
			foreach ( array( $plain, $mixed ) as $request ) {
				$result = $controller->add_menu_item( $request );
				$this->assertInstanceOf( WP_Error::class, $result );
				$this->assertSame( 'missing_object', $result->get_error_code() );
			}
		}
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
	}
}
