<?php
/**
 * RC 3.12.1 adversarial review, BLOCK 1 — the publish gate is defeated by one
 * leading space.
 *
 * param_value_targets_admin_scope() in trait-mcpwp-api-auth.php used to
 * compare strtolower( (string) $value ) against ('publish', 'private') with
 * an EXACT match. Five schema-less routes (POST /events, PUT /events/{id},
 * POST /elementor/clone, POST /elementor/landing-page, POST
 * /woocommerce/archetypes/{id}/apply) declared no 'args'/'enum' on their
 * write methods, so nothing upstream of the gate ever rejected an
 * out-of-enum value — and every one of their handlers normalizes the raw
 * value with sanitize_key() or sanitize_text_field() before using it, both
 * of which strip or collapse leading/trailing padding. A value like
 * ' publish' sailed past the old exact-match gate (scope stayed 'write')
 * while still landing on the literal 'publish' in the handler — a live
 * authorization bypass.
 *
 * This file exercises the fixed comparison directly, at two layers:
 *
 *   1. param_value_targets_admin_scope() itself (via reflection, since it is
 *      private) — the exact function the finding named — across a wide set
 *      of padding shapes: ASCII space/tab, CR/LF, a vertical tab and a NUL
 *      control character, and unicode whitespace (NBSP, zero-width space).
 *      Every "must catch" value is paired with an assertion that
 *      sanitize_key() — the more aggressive of the two downstream
 *      normalizers — really does reduce it to 'publish'/'private', so this
 *      test cannot pass by the gate simply becoming overly broad.
 *   2. get_required_scope_for_request() end-to-end, for all five routes
 *      BLOCK 1 named as exploitable, proving the bypass is closed at the
 *      actual chokepoint every dispatch path (direct REST, /batch, /mcp)
 *      re-checks — not just in the helper function.
 *
 * See PublishStatusHandlerAuditTest for the companion schema-invariant test
 * (every $publish_gated_routes route must declare 'enum' on 'status' in its
 * own register_routes() — the OTHER half of this fix) and
 * AdditionalPublishSurfacesRequireAdminScopeTest /
 * WooCommerceArchetypeApplyRequiresAdminScopeTest for the per-route
 * behavioral coverage this file does not duplicate (bulk shapes, archetype
 * merge semantics, etc.).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/traits/trait-mcpwp-api-auth.php';
// Deliberately loaded (not left to incidental load order): the archetype-apply
// route is dispatched through get_required_scope_for_request()'s
// Mcpwp_REST_WooCommerce::archetype_apply_targets_publish_status() branch
// whenever this class is loaded ANYWHERE in the same PHP process -- which,
// under a real PHPUnit run of the whole suite, it always is (another test
// file loads it first). Relying on class_exists() being false here would
// make this test's outcome depend on file load order across the whole suite,
// not on anything this file controls. Loading it explicitly, and matching
// what it actually does with a padded value, is what caught the second,
// independent exact-match bug this fix also closes in that method itself.
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';

/** Harness exposing both the public gate entrypoint and the private normalizer under test. */
class Mcpwp_Padded_Value_Gate_Harness {
	use Mcpwp_Api_Auth;

	public function scope_for( string $method, string $route, array $params = array() ): string {
		return $this->get_required_scope_for_request( new WP_REST_Request( $method, $route, $params ) );
	}

	/**
	 * Reflective passthrough to the private method the finding named.
	 * Reflection is used deliberately rather than widening the method's
	 * visibility just for tests — this is the exact function production
	 * code calls, unmodified.
	 */
	public function targets_admin_scope( array $params, string $key ): bool {
		$ref = new ReflectionMethod( $this, 'param_value_targets_admin_scope' );
		$ref->setAccessible( true );
		return $ref->invoke( $this, $params, $key );
	}
}

final class PublishGatePaddedValueBypassTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	/**
	 * Every value here was a real, exploitable bypass before this fix: the
	 * old exact strtolower() match let it through as scope 'write', while
	 * sanitize_key() — the more aggressive of the two normalizers the five
	 * previously schema-less handlers actually use — reduces every one of
	 * them to the literal 'publish' or 'private'.
	 */
	public function padded_publish_and_private_values(): array {
		return array(
			'leading space, publish'           => array( ' publish' ),
			'trailing space, publish'          => array( 'publish ' ),
			'leading tab, publish'             => array( "\tpublish" ),
			'trailing newline, publish'        => array( "publish\n" ),
			'leading+trailing space, publish'  => array( '  publish  ' ),
			'CRLF padded, publish'             => array( "\r\npublish\r\n" ),
			'leading space, private'           => array( ' private' ),
			'trailing space, private'          => array( 'private ' ),
			'leading+trailing space, private'  => array( '  private  ' ),
			'NBSP padded, publish'             => array( "\u{00A0}publish\u{00A0}" ),
			'zero-width space padded, publish' => array( "\u{200B}publish" ),
			'vertical tab padded, publish'     => array( "\x0Bpublish" ),
			'form feed padded, publish'        => array( "\x0Cpublish\x0C" ),
			'NUL padded, publish'              => array( "\x00publish" ),
			'mixed case + padding, PUBLISH'    => array( '  PUBLISH  ' ),
			'mixed case + padding, Private'    => array( "\tPrivate\t" ),
		);
	}

	/**
	 * @dataProvider padded_publish_and_private_values
	 */
	public function test_gate_now_catches_padded_publish_and_private_values( string $raw ): void {
		$harness = new Mcpwp_Padded_Value_Gate_Harness();

		$this->assertTrue(
			$harness->targets_admin_scope( array( 'status' => $raw ), 'status' ),
			sprintf( 'param_value_targets_admin_scope() must treat %s as targeting admin scope.', var_export( $raw, true ) )
		);

		// Prove this is not a stricter-than-necessary gate manufacturing its
		// own justification: the SAME raw value really does normalize to
		// 'publish'/'private' downstream via sanitize_key(), so a handler
		// using it really would have published/privated something.
		$normalized = sanitize_key( $raw );
		$this->assertContains(
			$normalized,
			array( 'publish', 'private' ),
			'This value must actually normalize to publish/private via sanitize_key() -- otherwise this assertion would be ' .
			'testing an overly broad gate, not a real downstream bypass.'
		);
	}

	/**
	 * Values that must NOT be caught. A gate that flags everything is not a
	 * fix — it is a denial-of-service against every legitimate draft/pending
	 * write. Also guards against a naive substring match ('publish' inside
	 * 'publishing') rather than a normalize-then-exact-match.
	 */
	public function benign_values(): array {
		return array(
			'draft'            => array( 'draft' ),
			'padded draft'     => array( '  draft  ' ),
			'pending'          => array( 'pending' ),
			'padded pending'   => array( "\tpending\n" ),
			'empty string'     => array( '' ),
			'only whitespace'  => array( '   ' ),
			'substring publish' => array( 'publishing' ),
			'substring private' => array( 'privately' ),
			'prefixed publish'  => array( 'unpublish' ),
		);
	}

	/**
	 * @dataProvider benign_values
	 */
	public function test_gate_does_not_false_positive_on_benign_values( string $raw ): void {
		$harness = new Mcpwp_Padded_Value_Gate_Harness();

		$this->assertFalse(
			$harness->targets_admin_scope( array( 'status' => $raw ), 'status' ),
			sprintf( 'param_value_targets_admin_scope() must NOT treat %s as targeting admin scope.', var_export( $raw, true ) )
		);
	}

	public function test_gate_ignores_non_scalar_and_absent_values(): void {
		$harness = new Mcpwp_Padded_Value_Gate_Harness();

		$this->assertFalse( $harness->targets_admin_scope( array(), 'status' ) );
		$this->assertFalse( $harness->targets_admin_scope( array( 'status' => null ), 'status' ) );
		$this->assertFalse( $harness->targets_admin_scope( array( 'status' => array( 'publish' ) ), 'status' ) );
	}

	/**
	 * End-to-end: the padded-value bypass is closed at the actual
	 * chokepoint every dispatch path (direct REST, /batch, MCP tools/call)
	 * re-checks, for every one of the five routes BLOCK 1 found exploitable.
	 * Mcpwp_REST_WooCommerce IS loaded in this file (see the require_once
	 * above), so the archetype-apply case exercises
	 * get_required_scope_for_request()'s REAL branch —
	 * Mcpwp_REST_WooCommerce::archetype_apply_targets_publish_status() — not
	 * a fallback. With no stored archetype seeded (mcpwp_test_options is
	 * empty), that method's own status resolution comes entirely from the
	 * request's override, exercising exactly the same padded-value shape as
	 * the other four routes. Archetype-STORED-status merge semantics are
	 * covered separately by WooCommerceArchetypeApplyRequiresAdminScopeTest.
	 */
	public function five_exploitable_routes(): array {
		return array(
			'events create'               => array( 'POST', '/mcpwp/v1/events', array() ),
			'events update'                => array( 'PUT', '/mcpwp/v1/events/1', array( 'id' => 1 ) ),
			'elementor clone'              => array( 'POST', '/mcpwp/v1/elementor/clone', array( 'source_id' => 1 ) ),
			'elementor landing-page'       => array( 'POST', '/mcpwp/v1/elementor/landing-page', array() ),
			'woocommerce archetype apply'  => array( 'POST', '/mcpwp/v1/woocommerce/archetypes/1/apply', array( 'id' => 1 ) ),
		);
	}

	/**
	 * @dataProvider five_exploitable_routes
	 */
	public function test_padded_values_are_admin_gated_on_every_exploitable_route( string $method, string $route, array $base_params ): void {
		$harness = new Mcpwp_Padded_Value_Gate_Harness();

		$this->assertSame(
			'admin',
			$harness->scope_for( $method, $route, $base_params + array( 'status' => ' publish' ) ),
			"$method $route with status=' publish' (leading space) must require admin scope -- BLOCK 1."
		);
		$this->assertSame(
			'admin',
			$harness->scope_for( $method, $route, $base_params + array( 'status' => "publish\n" ) ),
			"$method $route with status=\"publish\\n\" (trailing newline) must require admin scope -- BLOCK 1."
		);
		$this->assertSame(
			'admin',
			$harness->scope_for( $method, $route, $base_params + array( 'status' => "\tPUBLISH\t" ) ),
			"$method $route with status=\"\\tPUBLISH\\t\" (tab-padded, mixed case) must require admin scope -- BLOCK 1."
		);
		$this->assertSame(
			'admin',
			$harness->scope_for( $method, $route, $base_params + array( 'status' => ' private ' ) ),
			"$method $route with status=' private ' must require admin scope -- BLOCK 1 + WARN 3."
		);
		$this->assertSame(
			'write',
			$harness->scope_for( $method, $route, $base_params + array( 'status' => 'draft' ) ),
			"$method $route with status=draft must stay at write scope -- the fix must not over-block."
		);
		$this->assertSame(
			'write',
			$harness->scope_for( $method, $route, $base_params ),
			"$method $route with no status param at all must stay at write scope."
		);
	}
}
