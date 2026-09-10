<?php
/**
 * #843 — wc_get_product reports `purchasable` and `catalog_visibility`.
 *
 * The product formatter never emitted either fact, so a client saw null for
 * both and read "not purchasable / no visibility" for a product WooCommerce
 * would happily sell. Pinned here: both come from WC_Product; when one cannot
 * be computed (method missing, or it throws) the field is null and
 * unknown_reasons names it and why. Never the string 'unknown': it is truthy,
 * so `if (product.purchasable)` would build a buy link for a product nobody
 * verified. null fails safe, and the reason tells it apart from false.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-woocommerce.php';

if ( ! class_exists( 'Mcpwp_Test_WC_Product_Without_Facts' ) ) {
	/**
	 * A WC_Product double with every getter the formatter reads, except the
	 * two facts under test (an older or stripped-down product class).
	 */
	class Mcpwp_Test_WC_Product_Without_Facts { // phpcs:ignore
		/** @var array */
		public $o;
		public function __construct( array $o = array() ) {
			$this->o = $o + array( 'id' => 336313, 'type' => 'simple' );
		}
		public function get_id() { return $this->o['id']; }
		public function get_name() { return 'STEAM Hub course'; }
		public function get_slug() { return 'steam-hub-course'; }
		public function get_type() { return $this->o['type']; }
		public function is_type( $t ) { return $t === $this->o['type']; }
		public function get_status() { return 'publish'; }
		public function get_sku() { return 'STEAM-1'; }
		public function get_price() { return '49'; }
		public function get_regular_price() { return '49'; }
		public function get_sale_price() { return ''; }
		public function is_on_sale() { return false; }
		public function get_stock_status() { return 'instock'; }
		public function get_stock_quantity() { return null; }
		public function get_manage_stock() { return false; }
		public function get_permalink() { return 'https://example.com/product/steam-hub-course/'; }
		public function get_date_created() { return null; }
		public function get_date_modified() { return null; }
		public function get_description() { return 'd'; }
		public function get_short_description() { return 's'; }
		public function get_weight() { return ''; }
		public function get_length() { return ''; }
		public function get_width() { return ''; }
		public function get_height() { return ''; }
		public function get_image_id() { return 0; }
		public function get_gallery_image_ids() { return array(); }
		public function get_attributes() { return array(); }
		public function is_virtual() { return true; }
		public function is_downloadable() { return false; }
	}

	/**
	 * The same double with both facts; each answers a value, or throws when
	 * handed a Throwable.
	 */
	class Mcpwp_Test_WC_Product_With_Facts extends Mcpwp_Test_WC_Product_Without_Facts { // phpcs:ignore
		public function is_purchasable() {
			if ( $this->o['purchasable'] instanceof Throwable ) {
				throw $this->o['purchasable'];
			}
			return $this->o['purchasable'];
		}
		public function get_catalog_visibility() {
			if ( $this->o['catalog_visibility'] instanceof Throwable ) {
				throw $this->o['catalog_visibility'];
			}
			return $this->o['catalog_visibility'];
		}
	}
}

final class WooCommerceProductFormatTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']       = array();
		$GLOBALS['_mcpwp_test_object_terms'] = array();
	}

	private function format( $product, $detailed = true ): array {
		$method = new ReflectionMethod( Mcpwp_WooCommerce::class, 'format_product' );
		$method->setAccessible( true );
		return $method->invoke( new Mcpwp_WooCommerce(), $product, $detailed );
	}

	public function test_a_purchasable_visible_product_says_so(): void {
		$out = $this->format( new Mcpwp_Test_WC_Product_With_Facts( array( 'purchasable' => true, 'catalog_visibility' => 'visible' ) ) );

		$this->assertArrayHasKey( 'purchasable', $out );
		$this->assertTrue( $out['purchasable'] );
		$this->assertSame( 'visible', $out['catalog_visibility'] );
		$this->assertArrayNotHasKey( 'unknown_reasons', $out, 'Nothing was unknown.' );
	}

	public function test_a_real_no_is_false_and_a_real_visibility_is_passed_through(): void {
		$out = $this->format( new Mcpwp_Test_WC_Product_With_Facts( array( 'purchasable' => false, 'catalog_visibility' => 'hidden' ) ) );

		$this->assertFalse( $out['purchasable'] );
		$this->assertSame( 'hidden', $out['catalog_visibility'] );
	}

	public function test_the_list_shape_carries_both_facts_too(): void {
		$out = $this->format( new Mcpwp_Test_WC_Product_With_Facts( array( 'purchasable' => true, 'catalog_visibility' => 'catalog' ) ), false );

		$this->assertTrue( $out['purchasable'] );
		$this->assertSame( 'catalog', $out['catalog_visibility'] );
	}

	public function test_a_fact_that_throws_is_null_with_its_reason(): void {
		$out = $this->format(
			new Mcpwp_Test_WC_Product_With_Facts(
				array(
					'purchasable'        => new RuntimeException( 'add-on plugin exploded' ),
					'catalog_visibility' => 'visible',
				)
			)
		);

		$this->assertArrayHasKey( 'purchasable', $out, 'The field is still reported.' );
		$this->assertNull( $out['purchasable'], 'An uncomputable fact is null (fails safe), never a truthy placeholder.' );
		$this->assertSame( 'visible', $out['catalog_visibility'], 'One failure does not blank the other fact.' );
		$this->assertArrayHasKey( 'purchasable', $out['unknown_reasons'] );
		$this->assertStringContainsString( 'is_purchasable', $out['unknown_reasons']['purchasable'] );
		$this->assertStringContainsString( 'add-on plugin exploded', $out['unknown_reasons']['purchasable'] );
		$this->assertArrayNotHasKey( 'catalog_visibility', $out['unknown_reasons'] );
	}

	public function test_missing_methods_are_null_with_their_reason(): void {
		$out = $this->format( new Mcpwp_Test_WC_Product_Without_Facts() );

		$this->assertArrayHasKey( 'purchasable', $out );
		$this->assertArrayHasKey( 'catalog_visibility', $out );
		$this->assertNull( $out['purchasable'] );
		$this->assertNull( $out['catalog_visibility'] );
		$this->assertStringContainsString( 'is_purchasable', $out['unknown_reasons']['purchasable'] );
		$this->assertStringContainsString( 'get_catalog_visibility', $out['unknown_reasons']['catalog_visibility'] );
	}

	public function test_an_empty_visibility_answer_is_null_with_its_reason(): void {
		$out = $this->format( new Mcpwp_Test_WC_Product_With_Facts( array( 'purchasable' => true, 'catalog_visibility' => '' ) ) );

		$this->assertArrayHasKey( 'catalog_visibility', $out );
		$this->assertNull( $out['catalog_visibility'] );
		$this->assertArrayHasKey( 'catalog_visibility', $out['unknown_reasons'] );
	}

	public function test_a_null_fact_always_has_a_reason_and_a_known_one_never_does(): void {
		$cases = array(
			new Mcpwp_Test_WC_Product_With_Facts( array( 'purchasable' => true, 'catalog_visibility' => 'visible' ) ),
			new Mcpwp_Test_WC_Product_With_Facts( array( 'purchasable' => false, 'catalog_visibility' => 'search' ) ),
			new Mcpwp_Test_WC_Product_With_Facts( array( 'purchasable' => new RuntimeException( 'x' ), 'catalog_visibility' => new RuntimeException( 'y' ) ) ),
			new Mcpwp_Test_WC_Product_Without_Facts(),
		);
		foreach ( $cases as $product ) {
			foreach ( array( true, false ) as $detailed ) {
				$out     = $this->format( $product, $detailed );
				$reasons = $out['unknown_reasons'] ?? array();
				foreach ( array( 'purchasable', 'catalog_visibility' ) as $field ) {
					$this->assertArrayHasKey( $field, $out );
					$this->assertNotSame( 'unknown', $out[ $field ], "$field: never the truthy 'unknown' string." );
					if ( null === $out[ $field ] ) {
						$this->assertArrayHasKey( $field, $reasons, "$field: null carries a reason." );
					} else {
						$this->assertArrayNotHasKey( $field, $reasons, "$field: a real value carries no reason." );
					}
				}
				$this->assertIsBool( $out['purchasable'] ?? false );
			}
		}
	}
}
