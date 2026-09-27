<?php
/**
 * RC 3.12.1 adversarial review, Requirement 2 — close the parity test's
 * blind spot.
 *
 * ToolNameRouteScopeParityTest asserts tool-scope >= route-scope for every
 * route-mapped MCP tool. That catches a tool-name table that UNDER-classifies
 * a route the ROUTE TABLE already gates correctly — it cannot catch a route
 * table that is itself too permissive, because both sides of that comparison
 * come from the same (wrong) source. That is exactly how BLOCK 1 (LearnPress)
 * and BLOCK 2 (WooCommerce archetype apply) shipped in the RC:
 * ToolNameRouteScopeParityTest was green on both, because the tool-name table
 * agreed with a route table that had simply never heard of either surface.
 * "A test that can be satisfied by the wrong answer is worse than no test."
 *
 * This file adds the test that actually would have caught them, in two
 * halves:
 *
 *   1. test_every_caller_controlled_post_status_write_is_triaged() — greps
 *      every file under includes/ for any of several fingerprints of "this
 *      code writes a status the caller controls" (see file_matches_fingerprint()
 *      below), and fails if that set of files ever differs from self::AUDITED.
 *      This is independent of $publish_gated_routes' own contents: it does
 *      not ask "does the route table gate this route" (the question BLOCK
 *      1/2 already answered "no" to, silently), it asks "does a NEW file
 *      exist that can even raise the question at all." A future sibling
 *      surface (a Podcasts CPT, say) making the exact same 'defaults to
 *      publish' mistake fails this test the moment it is written, before
 *      anyone has to think to check the route table for it.
 *
 *      RC 3.12.1 adversarial review, Requirement 2 hardening (this fix):
 *      the ORIGINAL version of this fingerprint — wp_insert_post()/
 *      wp_update_post() sitting next to the single-quoted literal
 *      'post_status' — was itself blind to two things:
 *        a. A double-quoted "post_status" literal escaped detection
 *           entirely (strpos() looked for the single-quoted form only).
 *        b. Any object-setter/ORM shape that changes a status WITHOUT ever
 *           calling wp_insert_post()/wp_update_post() or writing the
 *           literal 'post_status' key — e.g. WC_Product::set_status()/
 *           WC_Order::set_status(). This is not a hypothetical: it is
 *           EXACTLY how includes/pro/core/class-mcpwp-woocommerce.php (the
 *           file backing BLOCK 2, the WooCommerce archetype-apply bypass)
 *           was invisible to this audit's own grep even after BLOCK 2 was
 *           fixed by hand — the fingerprint that was supposed to make this
 *           class of miss impossible could not see the file that caused it.
 *      file_matches_fingerprint() now also matches ->set_status(),
 *      wp_publish_post(), wp_transition_post_status(), and a raw $wpdb
 *      write (update/insert/replace/query) whose own statement touches
 *      post_status directly — every shape that changes a WordPress/
 *      WooCommerce object's status by a path other than the literal
 *      wp_insert_post()/wp_update_post()+'post_status' pattern.
 *
 *   2. test_every_conditionally_gated_route_actually_requires_admin_for_publish_and_private()
 *      — for every route self::AUDITED says is gated by $publish_gated_routes
 *      (as opposed to already blanket-admin, or never caller-controlled),
 *      actually dispatches get_required_scope_for_request() with
 *      status=publish / status=private / status=future / status=draft / no
 *      status at all, and asserts admin / admin / admin / write / write
 *      (the 'future' case is fix/3121-future-status-coercion: WordPress
 *      coerces 'future' to 'publish' at write time whenever the post's date
 *      is not itself in the future, and no surface in this plugin exposes a
 *      way to set one, so it is unconditionally equivalent to 'publish'
 *      everywhere this gate applies). This is the part
 *      PublishRequiresAdminScopeTest-style per-route tests already cover
 *      individually — collecting it here as one parametrized sweep means a
 *      route ever REMOVED from $publish_gated_routes (not just one never
 *      added) fails loudly too.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/traits/trait-mcpwp-api-auth.php';

/** Harness exposing the protected scope resolver under test, with full params. */
class Mcpwp_Publish_Audit_Auth_Harness {
	use Mcpwp_Api_Auth;

	public function scope_for( string $method, string $route, array $params = array() ): string {
		return $this->get_required_scope_for_request( new WP_REST_Request( $method, $route, $params ) );
	}
}

final class PublishStatusHandlerAuditTest extends TestCase {

	/**
	 * Every file, relative to includes/, that calls wp_insert_post()/
	 * wp_update_post() with a literal 'post_status' key, as of this fix, and
	 * the disposition this audit gave it. A file newly matching the grep
	 * below and missing from this map fails
	 * test_every_caller_controlled_post_status_write_is_triaged() — see that
	 * test's docblock for what to do about it.
	 */
	private const AUDITED = array(
		// ── Conditionally admin-gated: caller-controlled status, reachable
		// through a $publish_gated_routes entry or dynamic-segment regex in
		// get_required_scope_for_request(). See ROUTE_SCOPE_CASES below for
		// the routes these back and the direct scope assertions on them.
		'core/class-mcpwp-posts.php'                   => 'gated: /mcpwp/v1/posts',
		'core/class-mcpwp-pages.php'                   => 'gated: /mcpwp/v1/pages',
		// clone_page() lives in the REST controller itself, not in
		// core/class-mcpwp-pages.php — its route is /pages/{id}/clone, still
		// covered by the /mcpwp/v1/pages prefix above.
		'api/class-mcpwp-rest-pages.php'               => 'gated: /mcpwp/v1/pages (clone_page() -> /pages/{id}/clone)',
		'pro/core/class-mcpwp-learnpress.php'          => 'gated: /mcpwp/v1/learnpress/{courses,lessons,quizzes}',
		'pro/core/class-mcpwp-events.php'              => 'gated: /mcpwp/v1/events',
		'pro/core/class-mcpwp-page-builder.php'        => 'gated: /mcpwp/v1/elementor/build-page',
		'core/traits/trait-mcpwp-elementor-writer.php' => 'gated: /mcpwp/v1/elementor/page',
		'pro/core/class-mcpwp-elementor-pro.php'       => 'gated: create_landing_page() -> /mcpwp/v1/elementor/landing-page, clone_page() -> /mcpwp/v1/elementor/clone. The same file also wp_insert_post()s template-library entries with post_status hardcoded to \'publish\' (never caller input) — not a gating concern.',
		// Translations are caller-controlled too, but their own routes
		// (/posts/{id}/translations, /pages/{id}/translations) are already
		// covered by the /mcpwp/v1/posts and /mcpwp/v1/pages prefixes above —
		// no separate $publish_gated_routes entry needed.
		'pro/core/class-mcpwp-multilang.php'           => 'gated: rides the /mcpwp/v1/posts and /mcpwp/v1/pages prefixes (routes are /posts/{id}/translations, /pages/{id}/translations)',
		// RC 3.12.1 adversarial review, Requirement 2 fix: this file never
		// calls wp_insert_post()/wp_update_post() and never writes the
		// literal 'post_status' array key at all — it goes through
		// WC_Product::set_status()/WC_Order::set_status() instead, an
		// object-setter/ORM shape the OLD fingerprint could not see at all
		// (not a false-negative TRIAGE miss — the file was structurally
		// invisible to the grep). This is exactly BLOCK 2's shape: it is why
		// the WooCommerce archetype-apply bypass was gated only by a human
		// noticing, never by this test. The widened fingerprint below
		// (->set_status() shape) now catches it.
		'pro/core/class-mcpwp-woocommerce.php'         => 'gated: $product->set_status() at set_product_data() backs /mcpwp/v1/woocommerce/products AND /mcpwp/v1/woocommerce/archetypes/{id}/apply (the BLOCK 2 route — see archetype_apply_targets_publish_status() in api/class-mcpwp-rest-woocommerce.php). $order->set_status() in update_order() is a DIFFERENT, unrelated status vocabulary (WooCommerce order statuses like \'processing\'/\'completed\' — never \'publish\'/\'private\') and carries no publish-gating concern at all; it is listed here only because this file matches the ->set_status() shape the fingerprint now looks for.',

		// ── Already blanket-admin for every request regardless of status —
		// no conditional gate needed because the WHOLE route already
		// requires admin scope unconditionally.
		'pro/api/class-mcpwp-rest-elementor-pro.php'   => 'blanket-admin: Custom Code create/update/status routes live under /mcpwp/v1/elementor/custom-code, a full $admin_routes entry',
		'core/class-mcpwp-site-blueprints.php'         => 'blanket-admin: only reachable via /mcpwp/v1/site-blueprints/{id}/deploy, admin for every request via the deploy regex',
		'core/class-mcpwp-action-log.php'              => 'blanket-admin: rollback is only reachable via /mcpwp/v1/action-log, an $admin_write_routes entry',
		// #957: Mcpwp_Content_Guardrails::apply_bulk_publish() is the only
		// wp_update_post()+'post_status' write in this file — it flips a
		// bulk-publish approval's already-created drafts to 'publish' (or
		// back to 'draft' on rollback). It is reachable only from
		// Mcpwp_Approvals::apply_payload()/rollback_request(), which in turn
		// is reachable only via POST /approvals/{id}/(apply|rollback) — the
		// approvals regex above already requires admin scope for that route
		// unconditionally. $target_status itself is never a caller
		// parameter: it is hardcoded to 'publish' for $field==='payload' and
		// 'draft' for $field==='rollback' by the calling method, not read
		// from request input.
		'core/class-mcpwp-content-guardrails.php'      => 'blanket-admin: apply_bulk_publish() only runs via POST /approvals/{id}/(apply|rollback), already admin-gated by the approvals regex; target_status is hardcoded publish/draft, never a caller parameter',

		// ── Not caller-controlled at all — post_status is a hardcoded
		// literal or restricted to a context with no API key involved.
		'pro/core/class-mcpwp-theme-builder.php'       => 'not caller-controlled: post_status hardcoded to \'publish\'',
		'core/class-mcpwp-media.php'                   => 'not caller-controlled: post_status hardcoded to \'inherit\' (attachments)',
		'admin/traits/trait-mcpwp-admin-library.php'   => 'not caller-controlled: post_status hardcoded to \'draft\', and only runs inside wp-admin under the visiting user\'s own WP capabilities — no API key involved',

		// ── False positive from the grep's word boundary — this trait itself
		// (docblocks/comments discussing post_status, not a handler).
		'traits/trait-mcpwp-api-auth.php'              => 'false positive: this file — docblocks/comments discussing post_status, not a handler',
	);
	// NOT in this map, and correctly so: core/class-mcpwp-approvals.php and
	// api/class-mcpwp-rest-content-graph.php each call wp_update_post() next
	// to a *property read* ($post->post_status), never the array-key write
	// ('post_status' => ...) this audit's regex looks for — both were
	// manually checked (approvals only ever restores an already-approved,
	// already-admin-gated target; content-graph only ever compares status for
	// a publish check) and never matched the heuristic below in the first
	// place, so they are intentionally absent rather than pinned here.

	/**
	 * Routes backed by a file in self::AUDITED marked 'gated:' above,
	 * checked directly against get_required_scope_for_request() — the exact
	 * chokepoint every dispatch path (direct REST, /batch, MCP tools/call)
	 * re-checks. Each entry: [method, route, extra params (e.g. an id in the
	 * body), whether the route has a caller-supplied top-level 'status'
	 * field at all].
	 */
	private const ROUTE_SCOPE_CASES = array(
		'posts create'                => array( 'POST', '/mcpwp/v1/posts', array( 'title' => 'x' ) ),
		'posts update'                => array( 'PUT', '/mcpwp/v1/posts/1', array( 'id' => 1 ) ),
		'pages create'                => array( 'POST', '/mcpwp/v1/pages', array( 'title' => 'x' ) ),
		'pages clone'                 => array( 'POST', '/mcpwp/v1/pages/1/clone', array( 'id' => 1 ) ),
		'woocommerce products create' => array( 'POST', '/mcpwp/v1/woocommerce/products', array( 'name' => 'x' ) ),
		'learnpress course create'    => array( 'POST', '/mcpwp/v1/learnpress/courses', array( 'title' => 'x' ) ),
		'learnpress course update'    => array( 'PUT', '/mcpwp/v1/learnpress/courses/1', array( 'id' => 1 ) ),
		'learnpress lesson create'    => array( 'POST', '/mcpwp/v1/learnpress/lessons', array( 'title' => 'x' ) ),
		'learnpress lesson update'    => array( 'PUT', '/mcpwp/v1/learnpress/lessons/1', array( 'id' => 1 ) ),
		'learnpress quiz create'      => array( 'POST', '/mcpwp/v1/learnpress/quizzes', array( 'title' => 'x' ) ),
		'learnpress quiz update'      => array( 'PUT', '/mcpwp/v1/learnpress/quizzes/1', array( 'id' => 1 ) ),
		'events create'               => array( 'POST', '/mcpwp/v1/events', array( 'title' => 'x' ) ),
		'events update'               => array( 'PUT', '/mcpwp/v1/events/1', array( 'id' => 1 ) ),
		'elementor build-page'        => array( 'POST', '/mcpwp/v1/elementor/build-page', array( 'title' => 'x' ) ),
		'elementor landing-page'      => array( 'POST', '/mcpwp/v1/elementor/landing-page', array( 'title' => 'x' ) ),
		'elementor clone'             => array( 'POST', '/mcpwp/v1/elementor/clone', array( 'source_id' => 1 ) ),
		'elementor page'              => array( 'POST', '/mcpwp/v1/elementor/page', array( 'title' => 'x' ) ),
		// BLOCK 2's route. Whether get_required_scope_for_request() takes its
		// Mcpwp_REST_WooCommerce::archetype_apply_targets_publish_status()
		// branch or its request_targets_publish_status() fallback here
		// depends on whether that class happens to be loaded elsewhere in
		// this PHP process by the time this test runs (PHPUnit requires
		// every test file during suite construction, so in a full-suite run
		// it is virtually always loaded) -- this file does not require it
		// itself either way. With no archetype seeded in
		// mcpwp_test_options, BOTH branches resolve 'status' from nothing
		// but this request's own top-level param, so this assertion is
		// valid regardless of which one actually ran; see
		// PublishGatePaddedValueBypassTest, which requires that class
		// explicitly so its outcome does not depend on load order, and
		// which is what caught archetype_apply_targets_publish_status()'s
		// own independent exact-match bug (now fixed alongside this one).
		// The archetype's OWN stored status (the part only that method can
		// see) is covered separately by
		// WooCommerceArchetypeApplyRequiresAdminScopeTest.
		'woocommerce archetype apply' => array( 'POST', '/mcpwp/v1/woocommerce/archetypes/1/apply', array( 'id' => 1 ) ),
	);

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']      = array();
		$GLOBALS['mcpwp_test_transients']   = array();
		$GLOBALS['mcpwp_test_current_user'] = 0;
	}

	/** @return string[] Absolute paths of every PHP file under includes/. */
	private function source_files(): array {
		$root = dirname( __DIR__ ) . '/includes';
		$out  = array();
		$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
				$out[] = $file->getPathname();
			}
		}
		return $out;
	}

	/**
	 * Does $src contain any known shape of "this file writes a status the
	 * caller (eventually) controls"? See this file's top docblock for why
	 * each shape was added.
	 *
	 * Deliberately over-inclusive, not under-inclusive: a shape matching a
	 * file that turns out NOT to be caller-controlled (e.g. a hardcoded
	 * literal, or a ->set_status() on something unrelated to publish/
	 * private post visibility) is a one-line triage entry in self::AUDITED.
	 * A shape that fails to match a file that IS caller-controlled is a
	 * live, silent security gap — exactly what BLOCK 1/2 were.
	 *
	 * @param string $src File contents.
	 * @return bool
	 */
	private function file_matches_fingerprint( string $src ): bool {
		// Shape 1 (original): wp_insert_post()/wp_update_post() sitting
		// anywhere in the file alongside a 'post_status' or "post_status"
		// array-key literal (either quote style — the original only matched
		// the single-quoted form).
		if (
			preg_match( '/\bwp_(?:insert|update)_post\s*\(/', $src )
			&& preg_match( '/[\'"]post_status[\'"]/', $src )
		) {
			return true;
		}

		// Shape 2: object-setter/ORM shapes that change a status without
		// ever calling wp_insert_post()/wp_update_post() or writing the
		// literal 'post_status' key at all — e.g. WC_Product::set_status()/
		// WC_Order::set_status(). This is the shape that made
		// class-mcpwp-woocommerce.php (BLOCK 2) invisible to shape 1.
		if ( preg_match( '/->set_status\s*\(/', $src ) ) {
			return true;
		}

		// Shape 3: core helpers that transition a post's status by a path
		// other than wp_insert_post()/wp_update_post().
		if ( preg_match( '/\bwp_publish_post\s*\(/', $src ) || preg_match( '/\bwp_transition_post_status\s*\(/', $src ) ) {
			return true;
		}

		// Shape 4: a raw $wpdb write (update/insert/replace/query) whose OWN
		// statement touches post_status directly, bypassing every WP post
		// API this audit would otherwise see. Scoped to the same statement
		// (up to the next ';') rather than "anywhere in the file" so this
		// does not fire on unrelated $wpdb calls sharing a file with
		// unrelated post_status text (e.g. a docblock or a SELECT nowhere
		// near a write).
		if ( preg_match( '/\$wpdb\s*->\s*(?:update|insert|replace|query)\s*\([^;]*post_status/is', $src ) ) {
			return true;
		}

		return false;
	}

	public function test_every_caller_controlled_post_status_write_is_triaged(): void {
		$root  = dirname( __DIR__ ) . '/includes';
		$found = array();

		foreach ( $this->source_files() as $path ) {
			$src = file_get_contents( $path );
			if ( false === $src ) {
				continue;
			}
			if ( ! $this->file_matches_fingerprint( $src ) ) {
				continue;
			}
			$found[] = ltrim( str_replace( $root, '', $path ), '/' );
		}

		sort( $found );
		$expected = array_keys( self::AUDITED );
		sort( $expected );

		$this->assertSame(
			$expected,
			$found,
			"A file's caller-controlled-status fingerprint (see file_matches_fingerprint()) changed since the RC 3.12.1 " .
			'adversarial-review fix audited this codebase. This is exactly how BLOCK 1/2 were missed the first time ' .
			'(enumerating routes by hand instead of grepping the handlers) — audit any newly-listed file: is its status ' .
			'caller-controlled? If so, is the whole route already blanket-admin regardless of status (an $admin_routes / ' .
			'$admin_write_routes entry, or a dynamic-segment regex like the approvals/site-blueprints ones)? If neither, add a ' .
			'$publish_gated_routes entry (or regex) in get_required_scope_for_request(), a direct scope assertion to ' .
			'ROUTE_SCOPE_CASES below, and an entry here explaining the disposition.'
		);
	}

	/**
	 * @dataProvider route_scope_case_provider
	 */
	public function test_every_conditionally_gated_route_actually_requires_admin_for_publish_and_private(
		string $method,
		string $route,
		array $base_params
	): void {
		$auth = new Mcpwp_Publish_Audit_Auth_Harness();

		$this->assertSame(
			'admin',
			$auth->scope_for( $method, $route, $base_params + array( 'status' => 'publish' ) ),
			"$method $route with status=publish must require admin scope."
		);
		$this->assertSame(
			'admin',
			$auth->scope_for( $method, $route, $base_params + array( 'status' => 'private' ) ),
			"$method $route with status=private must require admin scope (WARN 3)."
		);
		$this->assertSame(
			'admin',
			$auth->scope_for( $method, $route, $base_params + array( 'status' => 'future' ) ),
			"$method $route with status=future must require admin scope (fix/3121-future-status-coercion: WordPress " .
			"coerces 'future' to 'publish' at write time whenever the post's date is not itself in the future, and no " .
			'surface in this plugin exposes a way to set a post/page/product\'s date, so \'future\' is unconditionally ' .
			"equivalent to 'publish' here)."
		);
		$this->assertSame(
			'write',
			$auth->scope_for( $method, $route, $base_params + array( 'status' => 'draft' ) ),
			"$method $route with status=draft must stay at write scope."
		);
		$this->assertSame(
			'write',
			$auth->scope_for( $method, $route, $base_params ),
			"$method $route with no status param at all must stay at write scope (the dangerous 'defaults to publish with " .
			'zero arguments\' case is closed by the route\'s own schema default, not by this bare check, and is covered ' .
			'separately by the per-surface *RequiresAdminScopeTest files).'
		);
	}

	public function route_scope_case_provider(): array {
		return self::ROUTE_SCOPE_CASES;
	}

	/**
	 * Public static accessor so PublishGateSchemaInvariantTest (RC 3.12.1
	 * adversarial review, WARN) can reuse this exact [method, route, ...]
	 * list rather than maintaining a second, driftable copy of "every route
	 * this fix cares about".
	 *
	 * @return array
	 */
	public static function gated_route_scope_cases(): array {
		return self::ROUTE_SCOPE_CASES;
	}

	/**
	 * Sanity: self::AUDITED and self::ROUTE_SCOPE_CASES must not silently
	 * drift apart — every file marked 'gated:' above must be exercised by at
	 * least one case (a file marked gated with zero route assertions is as
	 * unverified as an ungated one).
	 */
	public function test_every_gated_file_has_at_least_one_route_scope_case(): void {
		$gated_files = array();
		foreach ( self::AUDITED as $file => $disposition ) {
			if ( 0 === strpos( $disposition, 'gated:' ) ) {
				$gated_files[] = $file;
			}
		}

		$this->assertNotEmpty( $gated_files );
		$this->assertGreaterThanOrEqual(
			count( $gated_files ),
			count( self::ROUTE_SCOPE_CASES ),
			'Every file marked \'gated:\' in self::AUDITED should be backed by at least one case in ROUTE_SCOPE_CASES.'
		);
	}
}
