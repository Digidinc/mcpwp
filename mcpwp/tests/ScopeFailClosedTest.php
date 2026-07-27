<?php
/**
 * sanitize_scopes() must fail CLOSED for runtime validation of stored scopes —
 * empty or unrecognised input collapses to array('read'), never to the maximal
 * read+write+admin default. That default belongs only to the explicit key-CREATION
 * and legacy-migration paths.
 *
 * This locks both halves of the fix:
 *   - fail-closed: empty/junk stored scopes → read only, and a present-but-empty
 *     scope set on a record is not treated as admin (key_has_scope / normalize).
 *   - no-regression: a scopeLESS legacy record still resolves to its role's full
 *     grant (legacy admin keys are not silently demoted), and key CREATION without
 *     explicit scopes still defaults UP to the role ceiling.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

/** Harness exposing the scope-resolution surface of the auth trait. */
class Mcpwp_Scope_Fail_Closed_Harness {
	use Mcpwp_Api_Auth;

	/** @param array $scopes */
	public function scopes( $scopes ): array {
		return $this->sanitize_scopes( $scopes );
	}

	/** @param array $record */
	public function has_scope( $record, string $required ): bool {
		return $this->key_has_scope( $record, $required );
	}

	/** @param array $record */
	public function normalize( $record ): array {
		return $this->normalize_api_key_record( $record );
	}
}

final class ScopeFailClosedTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
		$GLOBALS['mcpwp_test_filters']      = array();
	}

	private function harness(): Mcpwp_Scope_Fail_Closed_Harness {
		return new Mcpwp_Scope_Fail_Closed_Harness();
	}

	// 1. Empty / unrecognised stored scopes fail closed to read-only.
	public function test_sanitize_scopes_fails_closed_on_empty_and_junk(): void {
		$auth = $this->harness();

		$this->assertSame( array( 'read' ), $auth->scopes( array() ) );
		$this->assertSame( array( 'read' ), $auth->scopes( array( 'bogus' ) ) );
	}

	// 2. A present-but-empty scope set is not write and not admin.
	public function test_present_but_empty_scope_set_is_not_privileged(): void {
		$auth = $this->harness();

		$this->assertFalse( $auth->has_scope( array( 'scopes' => array() ), 'write' ) );
		$this->assertFalse( $auth->has_scope( array( 'scopes' => array() ), 'admin' ) );
	}

	// 3. A corrupt present-but-empty record normalises to read-only.
	public function test_normalize_present_but_empty_scopes_fails_closed(): void {
		$this->assertSame(
			array( 'read' ),
			$this->harness()->normalize( array( 'scopes' => array() ) )['scopes']
		);
	}

	// 4. No-regression: a scopeLESS legacy record keeps its role's full grant.
	public function test_normalize_scopeless_legacy_admin_record_keeps_full_grant(): void {
		$this->assertSame(
			array( 'read', 'write', 'admin' ),
			$this->harness()->normalize( array( 'id' => 'x', 'hash' => 'h' ) )['scopes']
		);
	}

	// 5. No-regression: key CREATION without explicit scopes defaults UP to the role
	//    ceiling, while an explicit request is honoured and never widened.
	public function test_key_creation_defaults_up_but_honours_explicit_request(): void {
		$auth = $this->harness();

		$admin_default = $auth->create_scoped_api_key( 'L', array(), 'admin' );
		$this->assertContains( 'admin', $admin_default['scopes'], 'An admin key created without explicit scopes must not be demoted.' );

		$editor_default = $auth->create_scoped_api_key( 'L', array(), 'editor' );
		$this->assertSame( array( 'read', 'write' ), $editor_default['scopes'] );

		$admin_explicit = $auth->create_scoped_api_key( 'L', array( 'write' ), 'admin' );
		$this->assertEqualsCanonicalizing( array( 'read', 'write' ), $admin_explicit['scopes'] );
		$this->assertNotContains( 'admin', $admin_explicit['scopes'], 'An explicit non-admin request must not be widened to admin.' );
	}
}
