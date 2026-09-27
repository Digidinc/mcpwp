<?php
/**
 * Regression guard, #943 S2 adversarial review BLOCK-1.
 *
 * The category toggle (site-wide "disable a category", and a key's own
 * tool-category allowlist) is enforced at REST by get_category_for_route().
 * A route that resolves to '' is ungated, so a key without the category, or a
 * site with it disabled, can still call it directly, through /batch, or via
 * resources/read. The /graph routes shipped that way.
 *
 * This test iterates every route a Free or Pro tool dispatches to and asserts
 * the route resolves to exactly that tool's category — never '' and never a
 * different category.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/** Exposes the protected route classifier. */
class Mcpwp_Route_Category_Parity_Harness {
	use Mcpwp_Api_Auth;

	public function category( string $route ): string {
		return $this->get_category_for_route( $route );
	}
}

final class RouteCategoryToolParityTest extends TestCase {

	/**
	 * Ungated 'site' tool routes, "tool route" sorted.
	 *
	 * #1007 owner decision (2026-09-27): shrunk from all 64 'site'-category
	 * tool routes down to exactly the discovery allowlist
	 * (Mcpwp_Api_Auth::DISCOVERY_ALLOWLIST_ROUTES) — the other 61 (menus,
	 * memory, options, site-blueprints, custom-css, ...) are now mapped to
	 * 'site' in get_route_category_map() and enforced like any other category.
	 */
	const SITE_EXEMPT = array(
		'wp_introspect /mcpwp/v1/introspect',
		'wp_onboard /mcpwp/v1/onboard',
		'wp_site_info /mcpwp/v1/site-info',
	);

	/**
	 * @return array<string,array{0:string,1:string}> tool => [route, tool category]
	 */
	private function tools(): array {
		$out = array();
		foreach ( array( new Mcpwp_MCP_Free_Tools(), new Mcpwp_MCP_Pro_Tools() ) as $registry ) {
			$categories = $registry->get_tool_categories();
			foreach ( $registry->get_tool_map() as $name => $mapping ) {
				if ( isset( $mapping['callback'] ) || ! isset( $mapping['route'] ) ) {
					continue;
				}
				// {type} in /{type}s/{id}/translations is a post type; every other
				// placeholder is an id-like segment that never changes the match.
				$route        = preg_replace( '/\{[^}]+\}/', '1', str_replace( '{type}', 'post', '/mcpwp/v1' . $mapping['route'] ) );
				$out[ $name ] = array( $route, (string) ( $categories[ $name ] ?? $registry->get_tool_category( $name ) ) );
			}
		}
		return $out;
	}

	public function test_every_tool_route_resolves_to_the_tools_category(): void {
		$auth     = new Mcpwp_Route_Category_Parity_Harness();
		$tools    = $this->tools();
		$failures    = array();
		$site_exempt = array();
		$this->assertGreaterThan( 250, count( $tools ) );

		foreach ( $tools as $name => list( $route, $tool_category ) ) {
			$route_category = $auth->category( $route );
			// Documented split (get_category_for_route() docblock): the REST
			// '/events' prefix serves the Free event store as 'webhooks' while the
			// Pro event tools keep their own 'events' toggle. Both are gated.
			if ( 'events' === $tool_category && 'webhooks' === $route_category ) {
				continue;
			}
			// #1007 owner decision: the discovery allowlist
			// (DISCOVERY_ALLOWLIST_ROUTES) stays ungated at REST so every key,
			// any role — including migrated 2.8 spai_ keys — keeps /site-info,
			// /onboard and /introspect. Pinned by KeyCategoryRestrictionAuthPlaneTest
			// and BridgeAuthTest. Every other 'site' tool route is now mapped in
			// get_route_category_map(); anything else still resolving to '' fails
			// here.
			if ( 'site' === $tool_category && '' === $route_category ) {
				$site_exempt[] = $name . ' ' . $route;
				continue;
			}
			if ( '' === $route_category || $route_category !== $tool_category ) {
				$failures[] = sprintf( '%s -> %s: route category "%s", tool category "%s"', $name, $route, $route_category, $tool_category );
			}
		}

		$this->assertSame( array(), $failures, "Tool routes whose REST category gate disagrees with the tool:\n" . implode( "\n", $failures ) );
		// The exemption is pinned exactly (review round 2, NIT-1): any new,
		// removed or substituted ungated 'site' tool route fails here.
		sort( $site_exempt );
		$this->assertSame( self::SITE_EXEMPT, $site_exempt );
	}

	public function test_graph_routes_are_content(): void {
		$auth = new Mcpwp_Route_Category_Parity_Harness();
		foreach ( array( '/mcpwp/v1/graph', '/mcpwp/v1/graph/query', '/mcpwp/v1/graph/node', '/mcpwp/v1/graph/neighbors', '/mcpwp/v1/graph/rebuild' ) as $route ) {
			$this->assertSame( 'content', $auth->category( $route ), $route );
		}
	}

	/**
	 * Reviewer's proof (AdvGraphCategoryTest), as a regression test: through
	 * the real verify_api_key() chain, a designer-role read+write key (no
	 * `content` category) is refused every /graph route, and a site that has
	 * disabled `content` refuses /graph to every key, including admin.
	 */
	public function test_ADV_designer_key_and_disabled_content_are_refused_graph_routes(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_is_admin']     = false;
		$GLOBALS['mcpwp_test_filters']      = array();

		$auth     = new Mcpwp_Route_Category_Parity_Harness();
		$designer = $auth->create_scoped_api_key( 'designer', array( 'read', 'write' ), 'designer' );
		foreach ( array( '/mcpwp/v1/graph', '/mcpwp/v1/graph/query', '/mcpwp/v1/graph/node', '/mcpwp/v1/graph/neighbors' ) as $route ) {
			$res = $auth->verify_api_key( new WP_REST_Request( 'GET', $route, array(), array( 'X-API-Key' => $designer['key'] ) ) );
			$this->assertInstanceOf( WP_Error::class, $res, "designer GET {$route}" );
			$this->assertSame( 'forbidden_category', $res->get_error_code(), "designer GET {$route}" );
		}

		update_option( 'mcpwp_disabled_tool_categories', array( 'content' ) );
		$admin = $auth->create_scoped_api_key( 'adm', array( 'read', 'write', 'admin' ), 'admin' );
		foreach ( array( '/mcpwp/v1/pages', '/mcpwp/v1/graph', '/mcpwp/v1/graph/query' ) as $route ) {
			$res = $auth->verify_api_key( new WP_REST_Request( 'GET', $route, array(), array( 'X-API-Key' => $admin['key'] ) ) );
			$this->assertInstanceOf( WP_Error::class, $res, "content-disabled GET {$route}" );
			$this->assertSame( 'forbidden_category', $res->get_error_code(), "content-disabled GET {$route}" );
		}
		update_option( 'mcpwp_disabled_tool_categories', array() );
	}
}
