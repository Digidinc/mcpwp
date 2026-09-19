<?php
/**
 * fix/3121-future-status-coercion — the class of bug, not just the instance.
 *
 * PublishStatusHandlerAuditTest fingerprints every file that WRITES a status
 * (wp_insert_post()/wp_update_post() + 'post_status', ->set_status(),
 * wp_publish_post(), wp_transition_post_status(), a raw $wpdb write). That
 * fingerprint is structurally blind to a file that only ever STAGES a status
 * for someone else to consume later: create_product_archetype() and
 * update_product_archetype() (includes/pro/api/class-mcpwp-rest-woocommerce.php)
 * call nothing but update_option() — no wp_insert_post(), no ->set_status(),
 * no post_status literal anywhere near a write. No amount of widening that
 * fingerprint's write-shape list would ever catch it, because there is no
 * write to fingerprint at the point the bad value is introduced. This is
 * exactly how the RC 3.12.1.1 (post-RC) finding shipped: POST
 * /woocommerce/archetypes and PUT /woocommerce/archetypes/{id} had NO 'args'
 * schema at all, so a write-scoped key could store
 * product_data['status'] => 'future' on the archetype DEFINITION — an
 * update_option() call the write-shape audit cannot see — and later apply it
 * with zero request-time status param at all, reaching a real product write
 * through a path (Mcpwp_WooCommerce::set_product_data()) that also had no
 * allow-list of its own. WordPress's wp_insert_post() (wp-includes/post.php)
 * coerces 'future' straight to 'publish' whenever the post's date is not
 * itself at least a minute out, and this integration exposes no way to set a
 * product's date, so 'future' is unconditionally equivalent to 'publish'.
 *
 * This file adds the complementary check the fingerprint cannot: a
 * data-flow / fixture test that plants a value directly in STORAGE (the way
 * a stale pre-fix archetype, or any future sibling "definition then apply"
 * surface, actually would) and asserts what happens when it is CONSUMED,
 * without ever going through the defining route's own request-time
 * validation at all. If either layer of this fix ever regressed —
 * Mcpwp_WooCommerce::set_product_data()'s allow-list, or
 * Mcpwp_REST_WooCommerce::archetype_apply_targets_publish_status()'s
 * inclusion of 'future' — this file fails even though nothing in it ever
 * sends an HTTP request with status=future.
 *
 * In two halves:
 *
 *   1. test_every_option_backed_status_definition_is_triaged() — fingerprints
 *      every file under includes/ that calls update_option()/add_option()
 *      AND contains a quoted 'status'/"status" array-key literal anywhere in
 *      it. This is deliberately a DIFFERENT axis than
 *      PublishStatusHandlerAuditTest's fingerprint (storage calls, not write
 *      calls) precisely so it can see what that one cannot — and it does:
 *      pro/api/class-mcpwp-rest-woocommerce.php shows up here even though it
 *      is invisible to the write-shape fingerprint. Deliberately
 *      over-inclusive (rate limiter status, approval workflow status, SEO
 *      issue status, Figma connection status, etc. all match too) — a shape
 *      match that turns out unrelated to publish/private/future post
 *      visibility is a one-line triage entry in self::AUDITED, same
 *      philosophy as the sibling test. A shape that fails to match a file
 *      that DOES stage a real post/product status is the live gap this test
 *      exists to close.
 *
 *   2. test_a_status_staged_directly_in_storage_is_still_caught_on_apply()
 *      and test_set_product_data_never_forwards_an_unlisted_status() — the
 *      actual data-flow proof. Both seed/call storage directly, bypassing
 *      every REST route and its 'args' schema entirely, so they exercise
 *      exactly the "already-stored, no request-time schema in sight" shape a
 *      pre-existing archetype record has.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PublishStatusHandlerAuditTest.php';
require_once dirname( __DIR__ ) . '/includes/traits/trait-mcpwp-api-auth.php';
require_once dirname( __DIR__ ) . '/includes/pro/api/class-mcpwp-rest-woocommerce.php';
require_once dirname( __DIR__ ) . '/includes/pro/core/class-mcpwp-woocommerce.php';

if ( ! class_exists( 'Mcpwp_Test_WC_Product_Status_Spy' ) ) {
	/**
	 * The narrowest possible WC_Product double: records every status
	 * set_product_data() ever tries to apply, without needing the rest of
	 * WooCommerce loaded. set_product_data() only calls a setter when the
	 * matching $data key is present, so a $data array containing nothing but
	 * 'status' never touches any other method on this double.
	 */
	class Mcpwp_Test_WC_Product_Status_Spy { // phpcs:ignore
		/** @var string[] Every value passed to set_status(), in call order. */
		public $set_status_calls = array();

		public function set_status( $status ) {
			$this->set_status_calls[] = $status;
		}
	}
}

final class StagedStatusValueAuditTest extends TestCase {

	/**
	 * Every file, relative to includes/, that calls update_option()/
	 * add_option() AND contains a quoted 'status' array-key literal
	 * somewhere in it, as of this fix, and the disposition this audit gave
	 * it. A file newly matching the grep below and missing from this map
	 * fails test_every_option_backed_status_definition_is_triaged() — audit
	 * it the same way PublishStatusHandlerAuditTest::AUDITED is audited: is
	 * the staged 'status' actually a post/product publish-visibility status
	 * (publish/draft/pending/private/future), and if so, is it ever consumed
	 * by a write this plugin gates by scope? If yes to both and that write
	 * does not already independently re-validate the value at consumption
	 * time, that is this exact bug and needs the same two-layer fix
	 * (allow-list at the true chokepoint + a gate that treats the stored
	 * value as admin-requiring).
	 */
	private const AUDITED = array(
		// ── The actual finding this fix closes ──────────────────────────
		'pro/api/class-mcpwp-rest-woocommerce.php' => 'WAS the gap: create_product_archetype()/update_product_archetype() ' .
			'update_option() a record whose product_data[\'status\'] is later consumed by apply_product_archetype() -> ' .
			'Mcpwp_WooCommerce::set_product_data() with no request-time schema ever seeing the stored value. Closed by ' .
			'three layers: an allow-list at set_product_data() itself (the true chokepoint — catches a value staged before ' .
			'this fix shipped too), an \'enum\' on both archetype-definition routes\' own \'status\' arg (stops a NEW bad ' .
			'value being stored), and archetype_apply_targets_publish_status() now treating a stored/merged \'future\' as ' .
			'admin-requiring, the same as \'publish\'/\'private\'. See test_a_status_staged_directly_in_storage_is_still_caught_on_apply().',

		// ── Not caller-controlled via an API key at all ─────────────────
		'admin/traits/trait-mcpwp-admin-library.php' => 'not caller-controlled via API key: runs only inside wp-admin under ' .
			'the visiting user\'s own WP capabilities, same disposition as PublishStatusHandlerAuditTest::AUDITED gives this ' .
			'file for the sibling write-shape fingerprint — no REST scope gate applies here.',

		// ── A real staged/consumed value, but never a post- or product- ─
		// visibility status, or already unconditionally admin-gated ────
		'core/class-mcpwp-approvals.php' => 'staged value is the approval workflow\'s OWN status vocabulary ' .
			'(pending/approved/applied/rejected/rolled_back) — a completely different field to post_status/product ' .
			'status. Every transition away from \'pending\' (approve_request()/reject_request()/apply_request()/rollback) ' .
			'is set by the class itself from a hardcoded literal, never from a caller-supplied REST param at creation ' .
			'time (create_post_content_request()/create_destructive_request() both hardcode \'status\' => \'pending\'), ' .
			'and every state-changing route is already admin-scope-gated unconditionally (get_required_scope_for_request()\'s ' .
			'/approvals/{id}/(approve|reject|apply|rollback) regex) — there is no lower-scoped path that ever sets this ' .
			'field to anything.',
		'core/class-mcpwp-seo-audit-store.php' => 'staged value is a stored SEO issue\'s open/resolved/dismissed status — ' .
			'never fed into a WordPress or WooCommerce post/product status write.',
		'core/class-mcpwp-design-references.php' => 'staged value is a design-reference library entry\'s own metadata ' .
			'status field — the entries are reference material for the AI, never a live post/product visibility state.',
		'core/class-mcpwp-figma.php' => 'staged value is the Figma integration\'s connection/sync status — an external-API ' .
			'health flag, unrelated to post/product visibility.',
		'core/class-mcpwp-signals.php' => 'staged value is a site-health signal\'s computed status (ok/warn/critical) — ' .
			'read-only computed output, never caller-supplied and never consumed by a write.',
		'core/class-mcpwp-action-log.php' => 'staged value is an action-log entry\'s own bookkeeping status — the file is ' .
			'already blanket-admin-gated for every method via the /mcpwp/v1/action-log entry in $admin_write_routes ' .
			'(see PublishStatusHandlerAuditTest::AUDITED), so no lower-scoped path reaches it regardless of this field.',
		'class-mcpwp-rate-limiter.php' => 'staged value is rate-limit bucket bookkeeping (status of a throttle window) — ' .
			'unrelated to post/product visibility and not reachable via any caller-supplied param.',
		'class-mcpwp-migrate.php' => 'staged value is one-time migration bookkeeping (has this upgrade step run) — ' .
			'internal, never caller-supplied.',
		'api/class-mcpwp-rest-mcp.php' => 'staged value is MCP dispatch/idempotency bookkeeping (a replayed call\'s stored ' .
			'result status) — unrelated to post/product visibility.',
		'api/class-mcpwp-rest-site-info.php' => 'staged value is unrelated site-info/onboarding bookkeeping, never a post/' .
			'product status.',
		'api/class-mcpwp-rest-site-settings.php' => 'staged value is plugin settings bookkeeping, never a post/product status.',
		'pro/api/class-mcpwp-rest-elementor-pro.php' => 'staged value here is Elementor Custom Code\'s enabled/disabled ' .
			'status — the whole surface is already blanket-admin-gated for every method via the /mcpwp/v1/elementor/' .
			'custom-code entry in $admin_routes (see PublishStatusHandlerAuditTest::AUDITED), so no lower-scoped path can ' .
			'ever stage or consume it.',
		'pro/api/class-mcpwp-rest-staging.php' => 'staged value is a staging push operation\'s progress status — the ' .
			'entire /mcpwp/v1/staging surface is already blanket-admin-gated for every method (an $admin_routes entry), ' .
			'so no lower-scoped path can ever stage or consume it.',
		'pro/core/class-mcpwp-theme-builder.php' => 'staged value is a theme-builder template\'s condition/assignment ' .
			'status, not post_status — the file\'s actual post_status writes are hardcoded to \'publish\' with no caller ' .
			'input at all (see PublishStatusHandlerAuditTest::AUDITED\'s disposition for this same file).',

		// ── False positive from the grep's word boundary ────────────────
		'traits/trait-mcpwp-api-auth.php' => 'false positive: this file — docblocks/comments discussing \'status\' and ' .
			'calling update_option() for unrelated bookkeeping (e.g. rate-limit state), not a status-staging handler.',
	);

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']            = array();
		$GLOBALS['mcpwp_test_transients']         = array();
		$GLOBALS['mcpwp_test_current_user']       = 0;
		$GLOBALS['_mcpwp_test_registered_routes'] = array();
		if ( method_exists( 'Mcpwp_REST_API', 'reset_verdict_memo' ) ) {
			Mcpwp_REST_API::reset_verdict_memo();
		}
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
	 * The storage-axis fingerprint: does $src stage a status value into
	 * persistent storage via update_option()/add_option(), where the same
	 * file also contains a quoted 'status' array-key literal? Unlike
	 * PublishStatusHandlerAuditTest::file_matches_fingerprint() (which looks
	 * for WRITE shapes: wp_insert_post()/->set_status()/etc.), this looks
	 * for the STORAGE shape a define-then-apply surface actually has —
	 * exactly the shape create_product_archetype()/update_product_archetype()
	 * match and the write-shape fingerprint structurally cannot.
	 *
	 * @param string $src File contents.
	 * @return bool
	 */
	private function file_matches_staging_fingerprint( string $src ): bool {
		if ( ! preg_match( '/\b(?:update|add)_option\s*\(/', $src ) ) {
			return false;
		}

		return (bool) preg_match( '/[\'"]status[\'"]\s*(?:=>|:)/', $src );
	}

	public function test_every_option_backed_status_definition_is_triaged(): void {
		$root  = dirname( __DIR__ ) . '/includes';
		$found = array();

		foreach ( $this->source_files() as $path ) {
			$src = file_get_contents( $path );
			if ( false === $src ) {
				continue;
			}
			if ( ! $this->file_matches_staging_fingerprint( $src ) ) {
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
			"A file's status-staging fingerprint (update_option()/add_option() + a quoted 'status' literal — see " .
			'file_matches_staging_fingerprint()) changed since fix/3121-future-status-coercion audited this codebase. ' .
			"This axis is deliberately independent of PublishStatusHandlerAuditTest's write-shape fingerprint: it exists " .
			'to catch a file that STAGES a status for someone else to consume (only ever calling update_option(), never ' .
			"wp_insert_post()/->set_status()) — exactly how the WooCommerce archetype-definition routes shipped invisible " .
			'to that other audit. Triage any newly-listed file: does it stage a real post/product publish-visibility ' .
			"status (publish/draft/pending/private/future)? If so, is it later consumed by a write this plugin scope-" .
			'gates, and does that write already independently re-validate the value at consumption time (not just at the ' .
			'defining route)? If not, this is the same bug class and needs the same two-layer fix. Either way, add an ' .
			'entry here explaining the disposition.'
		);
	}

	/**
	 * The data-flow proof: plant 'future' directly into the stored archetype
	 * option — the same shape a pre-fix archetype record already on a real
	 * site has, or that ANY bug in the two request-time schemas (the routes'
	 * own 'enum', or a REST dispatch path that skips schema validation)
	 * would produce — with ZERO status param in the apply request itself,
	 * and confirm the gate still requires admin scope purely from what is in
	 * storage.
	 *
	 * @dataProvider staged_status_provider
	 */
	public function test_a_status_staged_directly_in_storage_is_still_caught_on_apply( string $stored_status, string $expected_scope ): void {
		// Seed storage directly -- no create_product_archetype() call, no
		// REST request, no 'args'/'enum' schema anywhere in this test. This
		// is the exact shape a value staged before this fix shipped (or by
		// any future bug in the defining route's own schema) would have.
		$GLOBALS['mcpwp_test_options'][ Mcpwp_REST_WooCommerce::PRODUCT_ARCHETYPES_OPTION_KEY ] = array(
			array(
				'id'              => 7,
				'name'            => 'Staged widget',
				'archetype_class' => '',
				'archetype_style' => '',
				'brief'           => '',
				'product_type'    => 'simple',
				'product_data'    => array( 'name' => 'Widget', 'status' => $stored_status ),
				'created_gmt'     => '2026-01-01 00:00:00',
				'updated_gmt'     => '2026-01-01 00:00:00',
			),
		);

		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/woocommerce/archetypes/7/apply',
			array( 'id' => 7 ) // Deliberately no 'status' override.
		);

		// Exercise archetype_apply_targets_publish_status() directly (the
		// static gate helper) --
		$this->assertSame(
			'admin' === $expected_scope,
			Mcpwp_REST_WooCommerce::archetype_apply_targets_publish_status( 7, $request ),
			"A stored status of '$stored_status' with no request override must " .
			( 'admin' === $expected_scope ? 'require' : 'NOT require' ) . ' admin scope.'
		);

		// -- and the full chokepoint every dispatch path (direct REST,
		// /batch, MCP tools/call) actually re-checks, end to end.
		$auth = new class() {
			use Mcpwp_Api_Auth;
			public function scope_for( $request ) {
				return $this->get_required_scope_for_request( $request );
			}
		};

		$this->assertSame(
			$expected_scope,
			$auth->scope_for( $request ),
			"get_required_scope_for_request() must resolve to '$expected_scope' for a stored status of '$stored_status' " .
			'with no request override.'
		);
	}

	public function staged_status_provider(): array {
		return array(
			// The bypass this fix closes: WordPress coerces 'future' to
			// 'publish' at write time whenever the post's date is not
			// itself in the future, and this integration exposes no way to
			// set a product's date.
			'future (the coercion bypass)' => array( 'future', 'admin' ),
			'publish'                      => array( 'publish', 'admin' ),
			'private'                      => array( 'private', 'admin' ),
			'draft'                        => array( 'draft', 'write' ),
			'pending'                      => array( 'pending', 'write' ),
		);
	}

	/**
	 * The other half of the chokepoint: even if BOTH request-time schemas
	 * (the archetype-definition routes' own 'enum', and the apply route's)
	 * were somehow bypassed or never existed, set_product_data() itself must
	 * never forward an out-of-allow-list value to the product's status
	 * setter. Reflection + a bare spy double, no WooCommerce classes needed
	 * — mirrors the pattern WooCommerceProductFormatTest already uses for
	 * this same private method.
	 *
	 * @dataProvider raw_status_provider
	 */
	public function test_set_product_data_never_forwards_an_unlisted_status( string $raw_status, string $expected_forwarded ): void {
		$product = new Mcpwp_Test_WC_Product_Status_Spy();

		$method = new ReflectionMethod( Mcpwp_WooCommerce::class, 'set_product_data' );
		$method->setAccessible( true );
		$method->invoke( new Mcpwp_WooCommerce(), $product, array( 'status' => $raw_status ) );

		$this->assertSame(
			array( $expected_forwarded ),
			$product->set_status_calls,
			"set_product_data() with status='$raw_status' must forward exactly '$expected_forwarded' to the product's " .
			'status setter, never the raw or partially-sanitized input.'
		);
	}

	public function raw_status_provider(): array {
		return array(
			'future is dropped to draft'                  => array( 'future', 'draft' ),
			'a padded future is dropped to draft too'      => array( ' future ', 'draft' ),
			'an arbitrary unknown string is dropped'       => array( 'new', 'draft' ),
			'an empty-after-sanitize value is dropped'     => array( '###', 'draft' ),
			'publish passes through unchanged'             => array( 'publish', 'publish' ),
			'private passes through unchanged'             => array( 'private', 'private' ),
			'draft passes through unchanged'               => array( 'draft', 'draft' ),
			'pending passes through unchanged'             => array( 'pending', 'pending' ),
		);
	}

	// ── Layer 2 schema check: the archetype-definition routes themselves ──

	/**
	 * @dataProvider archetype_write_route_provider
	 */
	public function test_archetype_definition_routes_declare_a_status_enum_excluding_future( string $method, string $route ): void {
		( new Mcpwp_REST_WooCommerce( null ) )->register_routes();

		$args = null;
		foreach ( $GLOBALS['_mcpwp_test_registered_routes'] as $registered ) {
			$pattern = '#^/' . trim( (string) $registered['namespace'], '/' ) . '/' . ltrim( (string) $registered['route'], '/' ) . '$#';
			if ( ! preg_match( $pattern, $route ) ) {
				continue;
			}
			$defs = isset( $registered['args']['methods'] ) ? array( $registered['args'] ) : $registered['args'];
			foreach ( $defs as $def ) {
				if ( ! is_array( $def ) || ! isset( $def['methods'] ) ) {
					continue;
				}
				if ( in_array( $method, array_map( 'trim', explode( ',', (string) $def['methods'] ) ), true ) ) {
					$args = isset( $def['args'] ) && is_array( $def['args'] ) ? $def['args'] : array();
					break 2;
				}
			}
		}

		$this->assertIsArray( $args, "No registered route matched $method $route." );
		$this->assertArrayHasKey(
			'status',
			$args,
			"$method $route declares no 'status' arg at all -- this is the exact fix/3121-future-status-coercion root " .
			"cause: without a declared arg, WordPress's has_valid_params() never runs, and an out-of-range value (like " .
			"'future') is stored verbatim for a later apply to pick up with no request-time check ever having seen it."
		);
		$this->assertArrayHasKey( 'enum', $args['status'], "$method $route declares 'status' but no 'enum' on it." );
		$this->assertNotContains(
			'future',
			$args['status']['enum'],
			"$method $route's 'status' enum must NOT include 'future' -- WordPress coerces it to 'publish' at write " .
			"time whenever the post's date is not itself in the future, and this integration has no way to set a " .
			"product's date, so 'future' can never be a legitimate value here."
		);
		$this->assertSame(
			array( 'draft', 'pending', 'private', 'publish' ),
			$args['status']['enum'],
			"$method $route's 'status' enum should be exactly the four values Mcpwp_WooCommerce::set_product_data() " .
			'actually accepts.'
		);
	}

	public function archetype_write_route_provider(): array {
		return array(
			'create archetype' => array( 'POST', '/mcpwp/v1/woocommerce/archetypes' ),
			'update archetype'  => array( 'PUT', '/mcpwp/v1/woocommerce/archetypes/7' ),
		);
	}
}
