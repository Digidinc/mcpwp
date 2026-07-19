<?php
/**
 * Regression tests for the menu taxonomy/post_type type-contract (GH #676).
 *
 * The documented tool schema (wp_add_menu_item / wp_create_menu) exposes
 * type = custom | post_type | taxonomy with object + object_id. The legacy
 * Mcpwp_Site_Manager callback historically switched on post|page|category|tag
 * and dropped the documented `taxonomy`/`post_type` types to a blank `custom`
 * item, silently producing broken navigation.
 *
 * These tests pin the aligned contract:
 *   - taxonomy + object + object_id  -> menu-item-type=taxonomy
 *   - post_type + object + object_id -> menu-item-type=post_type
 *   - back-compat aliases category|tag|post|page still work
 *   - custom link keeps its URL
 *   - taxonomy/post_type without object/object_id -> WP_Error
 *   - create_menu reports item-level failures instead of silent partial menus
 *
 * @package SitePilotAI\Tests
 */

require_once dirname(__DIR__) . '/includes/pro/core/class-mcpwp-site-manager.php';

class SiteManagerMenuItemTest extends PHPUnit\Framework\TestCase {

	/** @var Mcpwp_Site_Manager */
	private $manager;

	protected function setUp(): void {
		$this->manager = new Mcpwp_Site_Manager();

		// Register a single existing menu (term_id 1).
		$GLOBALS['_mcpwp_test_nav_menus'] = array(
			1 => (object) array( 'term_id' => 1, 'name' => 'Primary', 'slug' => 'primary', 'description' => '', 'count' => 0 ),
		);
		$GLOBALS['_mcpwp_test_nav_menu_item_writes'] = array();
		$GLOBALS['_mcpwp_test_menu_page_ids']        = array();
	}

	/** Return the item-data array captured from the most recent write. */
	private function last_write() {
		$writes = $GLOBALS['_mcpwp_test_nav_menu_item_writes'];
		$this->assertNotEmpty( $writes, 'Expected wp_update_nav_menu_item to be called.' );
		return end( $writes )['data'];
	}

	// ── taxonomy (the reported failure) ────────────────────────

	public function test_taxonomy_category_item_maps_to_taxonomy_type() {
		$id = $this->manager->add_menu_item( 1, array(
			'title'     => 'News',
			'type'      => 'taxonomy',
			'object'    => 'category',
			'object_id' => 5,
		) );

		$this->assertIsInt( $id );
		$data = $this->last_write();
		$this->assertSame( 'taxonomy', $data['menu-item-type'] );
		$this->assertSame( 'category', $data['menu-item-object'] );
		$this->assertSame( 5, $data['menu-item-object-id'] );
	}

	public function test_taxonomy_tag_item_maps_to_taxonomy_type() {
		$this->manager->add_menu_item( 1, array(
			'title'     => 'Featured',
			'type'      => 'taxonomy',
			'object'    => 'post_tag',
			'object_id' => 9,
		) );

		$data = $this->last_write();
		$this->assertSame( 'taxonomy', $data['menu-item-type'] );
		$this->assertSame( 'post_tag', $data['menu-item-object'] );
		$this->assertSame( 9, $data['menu-item-object-id'] );
	}

	// ── post_type ──────────────────────────────────────────────

	public function test_post_type_item_maps_to_post_type() {
		$this->manager->add_menu_item( 1, array(
			'title'     => 'About',
			'type'      => 'post_type',
			'object'    => 'page',
			'object_id' => 12,
		) );

		$data = $this->last_write();
		$this->assertSame( 'post_type', $data['menu-item-type'] );
		$this->assertSame( 'page', $data['menu-item-object'] );
		$this->assertSame( 12, $data['menu-item-object-id'] );
	}

	// ── back-compat aliases ────────────────────────────────────

	public function test_category_alias_still_works() {
		$this->manager->add_menu_item( 1, array(
			'title'     => 'News',
			'type'      => 'category',
			'object_id' => 5,
		) );

		$data = $this->last_write();
		$this->assertSame( 'taxonomy', $data['menu-item-type'] );
		$this->assertSame( 'category', $data['menu-item-object'] );
		$this->assertSame( 5, $data['menu-item-object-id'] );
	}

	public function test_tag_alias_maps_to_post_tag() {
		$this->manager->add_menu_item( 1, array(
			'title'     => 'Tagged',
			'type'      => 'tag',
			'object_id' => 7,
		) );

		$data = $this->last_write();
		$this->assertSame( 'taxonomy', $data['menu-item-type'] );
		$this->assertSame( 'post_tag', $data['menu-item-object'] );
	}

	public function test_page_alias_maps_to_post_type() {
		$this->manager->add_menu_item( 1, array(
			'title'     => 'Home',
			'type'      => 'page',
			'object_id' => 2,
		) );

		$data = $this->last_write();
		$this->assertSame( 'post_type', $data['menu-item-type'] );
		$this->assertSame( 'page', $data['menu-item-object'] );
	}

	// ── custom links keep URL ──────────────────────────────────

	public function test_custom_link_keeps_url() {
		$this->manager->add_menu_item( 1, array(
			'title' => 'External',
			'type'  => 'custom',
			'url'   => 'https://example.com/x',
		) );

		$data = $this->last_write();
		$this->assertSame( 'custom', $data['menu-item-type'] );
		$this->assertSame( 'https://example.com/x', $data['menu-item-url'] );
	}

	// ── validation of required object/object_id ────────────────

	public function test_taxonomy_without_object_id_is_rejected() {
		$result = $this->manager->add_menu_item( 1, array(
			'title'  => 'Broken',
			'type'   => 'taxonomy',
			'object' => 'category',
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_object', $result->get_error_code() );
		$this->assertEmpty( $GLOBALS['_mcpwp_test_nav_menu_item_writes'], 'No menu item should be written for an invalid taxonomy item.' );
	}

	public function test_post_type_without_object_is_rejected() {
		$result = $this->manager->add_menu_item( 1, array(
			'title'     => 'Broken',
			'type'      => 'post_type',
			'object_id' => 12,
		) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'missing_object', $result->get_error_code() );
	}

	// ── create_menu reports item-level failures ────────────────

	public function test_create_menu_reports_item_level_failures() {
		$menu = $this->manager->create_menu( array(
			'name'  => 'Launch',
			'items' => array(
				array( 'title' => 'Good', 'type' => 'taxonomy', 'object' => 'category', 'object_id' => 5 ),
				array( 'title' => 'Bad',  'type' => 'taxonomy', 'object' => 'category' ), // missing object_id
			),
		) );

		$this->assertIsArray( $menu );
		$this->assertArrayHasKey( 'item_errors', $menu, 'create_menu must surface item-level failures.' );
		$this->assertCount( 1, $menu['item_errors'] );
		$this->assertSame( 'missing_object', $menu['item_errors'][0]['code'] );
		$this->assertSame( 1, $menu['item_errors'][0]['index'] );
	}
}
