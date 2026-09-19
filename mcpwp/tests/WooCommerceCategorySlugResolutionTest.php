<?php
/**
 * #831 — wc_list_products' `category` filter matched a slug only.
 * wc_get_products()'s own `category` query arg only ever compares against
 * product_cat *slugs*; a caller passing the numeric category (term) ID that
 * wp_list_product_categories or a prior wc_list_products call just handed
 * back matched nothing (Invalid parameter(s): category, from the issue's
 * repro). Mcpwp_WooCommerce::resolve_category_slug() resolves a numeric
 * value to its slug before it reaches wc_get_products(); a non-numeric
 * value (already a slug) passes through unchanged.
 *
 * is_active() gates get_products() before this code ever runs (no
 * WooCommerce class exists in this test environment), so this exercises
 * the extracted resolver directly via reflection — the same style
 * WooCommerceProductFormatTest.php uses for format_product().
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-woocommerce.php';

final class WooCommerceCategorySlugResolutionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['_mcpwp_test_taxonomies'] = array(
			'product_cat' => array(
				'terms' => array(
					12 => array( 'slug' => 'hoodies', 'name' => 'Hoodies' ),
					13 => array( 'slug' => 'hats', 'name' => 'Hats' ),
				),
			),
		);
	}

	private function resolve( $category ) {
		$method = new ReflectionMethod( Mcpwp_WooCommerce::class, 'resolve_category_slug' );
		$method->setAccessible( true );
		return $method->invoke( new Mcpwp_WooCommerce(), $category );
	}

	public function test_a_numeric_category_id_resolves_to_its_slug(): void {
		$this->assertSame( 'hoodies', $this->resolve( 12 ) );
	}

	public function test_a_numeric_string_category_id_resolves_too(): void {
		// JSON-RPC arguments regularly arrive as strings.
		$this->assertSame( 'hats', $this->resolve( '13' ) );
	}

	public function test_a_slug_passes_through_unchanged(): void {
		$this->assertSame( 'hoodies', $this->resolve( 'hoodies' ) );
	}

	public function test_an_unknown_numeric_id_falls_back_to_the_original_value(): void {
		// No 999 term: rather than silently filtering to nothing resolvable,
		// wc_get_products() still gets the value the caller sent — the same
		// "invalid parameter" it would have surfaced before this fix, not a
		// newly-invented failure mode.
		$this->assertSame( 999, $this->resolve( 999 ) );
	}
}
