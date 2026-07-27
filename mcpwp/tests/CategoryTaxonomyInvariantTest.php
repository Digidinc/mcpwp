<?php
/**
 * Superset invariant — every tool-category that the REST route classifier can
 * emit, and every category any tool registry (free, pro, or a built-in
 * integration) assigns to a tool, MUST be a first-class toggle in
 * Mcpwp_Tools_Admin::get_category_meta().
 *
 * If the route classifier returns a slug that get_category_meta() does not list,
 * that route is UN-GATEABLE: the admin UI can never write the slug into
 * mcpwp_disabled_tool_categories, so no configuration can block the route — a
 * silent net-widening of the gate. If a tool registry emits a slug that the admin
 * UI cannot toggle, that whole family of tools cannot be disabled either.
 *
 * This test enumerates the required set from the SAME accessors the runtime uses
 * (get_all_route_categories() + each registry's get_tool_categories()) so it
 * cannot drift from them, and fails naming any slug the admin taxonomy is missing.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

// Not loaded by the bare bootstrap.
require_once dirname( __DIR__ ) . '/includes/admin/class-mcpwp-tools-admin.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-ai-integration.php';
require_once dirname( __DIR__ ) . '/includes/mcp/class-mcpwp-mcp-figma-integration.php';

/** Harness exposing the auth trait's route-category codomain accessor. */
class Mcpwp_Category_Taxonomy_Harness {
	use Mcpwp_Api_Auth;

	/** Public proxy to the protected route-classifier codomain accessor. */
	public function all_route_categories(): array {
		return $this->get_all_route_categories();
	}
}

final class CategoryTaxonomyInvariantTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']    = array();
		$GLOBALS['mcpwp_test_transients'] = array();
		$GLOBALS['mcpwp_test_filters']    = array();

		// Mirror Mcpwp_Loader::register_builtin_integrations(): the bare bootstrap
		// never fires the mcpwp_integrations filter, so resolve_all() would return an
		// empty set and the AI/Figma categories would go unchecked. clear_cache()
		// first so the static resolved-cache does not carry a prior test's result.
		Mcpwp_Integration::clear_cache();
		add_filter(
			'mcpwp_integrations',
			static function ( array $integrations ): array {
				$integrations[] = new Mcpwp_MCP_AI_Integration();
				$integrations[] = new Mcpwp_MCP_Figma_Integration();
				return $integrations;
			}
		);
	}

	protected function tearDown(): void {
		// Do not leak this file's filter-registered integrations into the rest of the
		// suite via the static resolved-cache.
		Mcpwp_Integration::clear_cache();
		$GLOBALS['mcpwp_test_filters'] = array();
	}

	/**
	 * Build the full required-category set from every runtime source.
	 *
	 * @return string[] Distinct category slugs the runtime can emit.
	 */
	private function required_categories(): array {
		$required = ( new Mcpwp_Category_Taxonomy_Harness() )->all_route_categories();

		$required = array_merge( $required, array_values( ( new Mcpwp_MCP_Free_Tools() )->get_tool_categories() ) );
		$required = array_merge( $required, array_values( ( new Mcpwp_MCP_Pro_Tools() )->get_tool_categories() ) );

		foreach ( Mcpwp_Integration::resolve_all() as $integration ) {
			$required = array_merge( $required, array_values( $integration->get_tool_categories() ) );
		}

		return array_values( array_unique( $required ) );
	}

	public function test_every_emittable_category_is_a_first_class_admin_toggle(): void {
		$required  = $this->required_categories();
		$meta_keys = array_keys( Mcpwp_Tools_Admin::get_category_meta() );

		$missing = array_values( array_diff( $required, $meta_keys ) );

		$this->assertSame(
			array(),
			$missing,
			'These categories are emitted by the route classifier or a tool registry but are '
			. 'NOT in Mcpwp_Tools_Admin::get_category_meta(), so they are un-gateable: '
			. implode( ', ', $missing )
		);
	}

	public function test_route_classifier_codomain_is_covered_by_admin_meta(): void {
		$codomain  = ( new Mcpwp_Category_Taxonomy_Harness() )->all_route_categories();
		$meta_keys = array_keys( Mcpwp_Tools_Admin::get_category_meta() );

		$missing = array_values( array_diff( $codomain, $meta_keys ) );

		$this->assertSame(
			array(),
			$missing,
			'Route classifier can emit un-gateable categories: ' . implode( ', ', $missing )
		);
	}

	/**
	 * The per-key custom-role category list (get_all_tool_category_labels) is a third
	 * enumeration; the admin taxonomy must cover it too, or a custom-role key could be
	 * restricted to a category the site can never actually toggle.
	 */
	public function test_all_tool_category_labels_are_covered_by_admin_meta(): void {
		$label_keys = array_keys( Mcpwp_Category_Taxonomy_Harness::get_all_tool_category_labels() );
		$meta_keys  = array_keys( Mcpwp_Tools_Admin::get_category_meta() );

		$missing = array_values( array_diff( $label_keys, $meta_keys ) );

		$this->assertSame(
			array(),
			$missing,
			'get_all_tool_category_labels() has slugs absent from get_category_meta(): ' . implode( ', ', $missing )
		);
	}
}
