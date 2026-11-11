<?php
/** Menu-item type parsing parity (#1112). */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-menus.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-site-manager.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-site-manager.php';

class Mcpwp_Menu_Type_1112_Harness {
	use Mcpwp_Api_Auth;

	public function check( $request ) {
		return $this->check_post_naming_params_category( $request );
	}
}

final class MenuItemTypeNormalization1112Test extends TestCase {
	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
		$GLOBALS['_mcpwp_test_nav_menus'] = array( 1 => (object) array( 'term_id' => 1, 'name' => 'Primary' ) );
		$GLOBALS['_mcpwp_test_nav_menu_item_writes'] = array();
	}

	public static function invalid_types(): array {
		$cases = array();
		foreach ( array( 'custom', 'post_type', 'taxonomy', 'post', 'page', 'category', 'tag', 'post_tag' ) as $type ) {
			foreach ( array(
				'upper' => strtoupper( $type ), 'mixed' => ucfirst( $type ),
				'leading' => ' ' . $type, 'trailing' => $type . ' ', 'tabs' => "\t" . $type . "\n",
				'nul' => $type . "\0", 'internal_nul' => substr( $type, 0, 1 ) . "\0" . substr( $type, 1 ),
				'unicode_padding' => "\u{200B}" . $type, 'lookalike' => "\u{0441}" . substr( $type, 1 ),
				'punctuation' => $type . '!', 'array' => array( $type ),
			) as $variant => $value ) {
				$cases[ $type . '-' . $variant ] = array( $value );
			}
		}
		foreach ( array( '', 'unknown', null, false, true, 1, array() ) as $index => $value ) {
			$cases[ 'other-' . $index ] = array( $value );
		}
		return $cases;
	}

	/** @dataProvider invalid_types */
	public function test_gate_refuses_invalid_types_on_single_and_nested_writes( $type ): void {
		$item = array( 'type' => $type, 'object_id' => 42 );
		foreach ( array(
			array( 'POST', '/menus/1/items', $item ),
			array( 'PUT', '/menus/1/items/2', $item ),
			array( 'PUT', '/menus/items/2', $item ),
			array( 'POST', '/menus', array( 'items' => array( $item ) ) ),
		) as list( $method, $route, $params ) ) {
			$result = ( new Mcpwp_Menu_Type_1112_Harness() )->check( new WP_REST_Request( $method, '/mcpwp/v1' . $route, $params ) );
			$this->assertInstanceOf( WP_Error::class, $result, $method . ' ' . $route );
			$this->assertSame( 'invalid_menu_item_type', $result->get_error_code() );
			$this->assertSame( 400, $result->get_error_data()['status'] );
		}
	}

	/** @dataProvider invalid_types */
	public function test_pro_handler_refuses_invalid_types_without_writing( $type ): void {
		$manager = new Mcpwp_Site_Manager();
		foreach ( array( 'add', 'update' ) as $operation ) {
			$data = array( 'type' => $type, 'title' => 'Item', 'object' => 'page', 'object_id' => 42, 'url' => 'https://example.test' );
			$result = 'add' === $operation ? $manager->add_menu_item( 1, $data ) : $manager->update_menu_item( 1, 2, $data );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_menu_item_type', $result->get_error_code() );
			$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
		}
	}

	/** @dataProvider invalid_types */
	public function test_free_handler_refuses_invalid_types_without_writing( $type ): void {
		$controller = new Mcpwp_REST_Menus();
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/menus/1/items', array( 'menu_id' => 1, 'type' => $type, 'title' => 'Item', 'object' => 'page', 'object_id' => 42, 'url' => 'https://example.test' ) );
		foreach ( array( 'add_menu_item', 'update_menu_item' ) as $handler ) {
			$result = $controller->$handler( $request );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_menu_item_type', $result->get_error_code() );
			$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
		}
	}

	/** @dataProvider invalid_types */
	public function test_pro_rest_handlers_refuse_invalid_types_without_writing( $type ): void {
		$controller = new Mcpwp_REST_Site_Manager( new Mcpwp_Site_Manager() );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/menus/1/items', array( 'menu_id' => 1, 'type' => $type, 'title' => 'Item', 'object' => 'page', 'object_id' => 42 ) );
		foreach ( array( 'add_menu_item', 'update_menu_item' ) as $handler ) {
			$result = $controller->$handler( $request );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'invalid_menu_item_type', $result->get_error_code() );
			$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
		}
	}

	public function test_pro_single_add_checks_object_visibility(): void {
		$GLOBALS['_mcpwp_test_posts'] = array( 42 => new WP_Post( array( 'ID' => 42, 'post_type' => 'product', 'post_status' => 'publish' ) ) );
		update_option( 'mcpwp_disabled_tool_categories', array( 'woocommerce' ) );
		$controller = new Mcpwp_REST_Site_Manager( new Mcpwp_Site_Manager() );
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/menus/1/items', array( 'menu_id' => 1, 'type' => 'page', 'title' => 'Item', 'object' => 'product', 'object_id' => 42 ) );
		$result = $controller->add_menu_item( $request );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'not_found', $result->get_error_code() );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'] );
	}

	public function test_normalizer_returns_the_canonical_type_and_alias_object(): void {
		foreach ( array(
			'custom' => array( 'custom', '' ), 'post_type' => array( 'post_type', '' ), 'taxonomy' => array( 'taxonomy', '' ),
			'post' => array( 'post_type', 'post' ), 'page' => array( 'post_type', 'page' ),
			'category' => array( 'taxonomy', 'category' ), 'tag' => array( 'taxonomy', 'post_tag' ), 'post_tag' => array( 'taxonomy', 'post_tag' ),
		) as $input => list( $type, $object ) ) {
			$this->assertSame( array( 'type' => $type, 'object' => $object ), Mcpwp_REST_API::normalize_menu_item_type( $input ) );
		}
	}

	public function test_canonical_types_have_the_same_post_classification_as_the_handler(): void {
		foreach ( array( 'custom', 'post_type', 'taxonomy', 'post', 'page', 'category', 'tag', 'post_tag' ) as $type ) {
			$GLOBALS['_mcpwp_test_nav_menu_item_writes'] = array();
			$result = ( new Mcpwp_Site_Manager() )->add_menu_item( 1, array( 'type' => $type, 'object' => 'page', 'object_id' => 42 ) );
			$this->assertIsInt( $result );
			$writes = $GLOBALS['_mcpwp_test_nav_menu_item_writes'];
			$this->assertSame( 'post_type' === end( $writes )['data']['menu-item-type'], Mcpwp_REST_API::menu_item_type_names_post( array( 'type' => $type ) ) );
		}
	}
}
