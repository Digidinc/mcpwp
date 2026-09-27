<?php
/**
 * #913 — resources/templates/list plus the four composed resource templates
 * (mcpwp://page/{id}, mcpwp://brand, mcpwp://memory/{namespace},
 * mcpwp://guide/{topic}) resources/read resolves them to.
 *
 * Each template must enforce exactly what its equivalent tool enforces:
 *  - page/{id}: same "content" category and the same guard_non_public_post()
 *    visibility gate wp_update_page/wp_update_post already apply, proven here
 *    with real GET /pages/{id} and /posts/{id} dispatch (RestPagesPostsDraftLeakTest's
 *    fixture pattern), not the stubbed rest_do_request() double.
 *  - memory/{namespace} and guide/{topic}: the "site" category wp_recall and
 *    wp_get_guide are enforced under at the tools/call layer, even though the
 *    underlying REST routes are deliberately NOT category-gated (see
 *    get_route_category_map()'s docblock) — resources/read must not be a
 *    laxer entry point to the same data than the tool itself is.
 *  - brand: composes site-context (same "site" gate) and Elementor globals
 *    (best-effort, its own category) independently.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-posts.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-posts.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-pages.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-elementor.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-info.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-network.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-memory.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-site-memory.php';
require_once dirname( __DIR__ ) . '/includes/core/class-mcpwp-guides.php';

final class McpResourceTemplatesTest extends TestCase {

	private const PUB_PAGE_ID     = 8501;
	private const DRAFT_PAGE_ID   = 8502;
	private const PUB_POST_ID     = 8601;
	private const ATTACHMENT_ID   = 8701;
	private const PRIVATE_POST_ID = 8602;
	private const TRASHED_PAGE_ID = 8503;
	private const FUTURE_POST_ID  = 8603;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']             = array();
		$GLOBALS['mcpwp_test_transients']          = array();
		$GLOBALS['mcpwp_test_current_user']        = 0;
		$GLOBALS['mcpwp_test_is_admin']            = false;
		$GLOBALS['_mcpwp_test_registered_routes']  = array();
		$GLOBALS['_mcpwp_test_meta']               = array();
		$GLOBALS['mcpwp_test_dispatched_routes']   = array();
		$GLOBALS['mcpwp_test_dispatched_requests'] = array();
		$GLOBALS['mcpwp_test_filters']             = array();

		Mcpwp_REST_API::reset_verdict_memo();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => true,
				'requests_per_minute' => 1000,
				'requests_per_hour'   => 5000,
				'burst_limit'         => 1000,
				'whitelist'           => array(),
			)
		);

		$GLOBALS['_mcpwp_test_posts'] = array(
			self::PUB_PAGE_ID   => new WP_Post(
				array(
					'ID'           => self::PUB_PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'Public Landing Page',
					'post_name'    => 'public-landing-page',
					'post_status'  => 'publish',
					'post_content' => 'Public page body.',
				)
			),
			self::DRAFT_PAGE_ID => new WP_Post(
				array(
					'ID'           => self::DRAFT_PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'Secret Draft Page',
					'post_name'    => 'secret-draft-page',
					'post_status'  => 'draft',
					'post_content' => 'SECRET page body.',
				)
			),
			self::PUB_POST_ID   => new WP_Post(
				array(
					'ID'           => self::PUB_POST_ID,
					'post_type'    => 'post',
					'post_title'   => 'Public Post',
					'post_name'    => 'public-post',
					'post_status'  => 'publish',
					'post_content' => 'Public post body.',
				)
			),
			self::ATTACHMENT_ID => new WP_Post(
				array(
					'ID'          => self::ATTACHMENT_ID,
					'post_type'   => 'attachment',
					'post_title'  => 'some-image.jpg',
					'post_status' => 'inherit',
				)
			),
			self::PRIVATE_POST_ID => new WP_Post(
				array(
					'ID'           => self::PRIVATE_POST_ID,
					'post_type'    => 'post',
					'post_title'   => 'Private Post',
					'post_name'    => 'private-post',
					'post_status'  => 'private',
					'post_content' => 'Private post body.',
				)
			),
			self::TRASHED_PAGE_ID => new WP_Post(
				array(
					'ID'           => self::TRASHED_PAGE_ID,
					'post_type'    => 'page',
					'post_title'   => 'Trashed Page',
					'post_name'    => 'trashed-page',
					'post_status'  => 'trash',
					'post_content' => 'Trashed page body.',
				)
			),
			self::FUTURE_POST_ID => new WP_Post(
				array(
					'ID'           => self::FUTURE_POST_ID,
					'post_type'    => 'post',
					'post_title'   => 'Scheduled Post',
					'post_name'    => 'scheduled-post',
					'post_status'  => 'future',
					'post_content' => 'Scheduled post body.',
				)
			),
		);

		add_filter( 'rest_dispatch_request', array( $this, 'route_internal' ), 10, 4 );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		Mcpwp_REST_Batch::$is_batch_sub_request  = false;

		$instance = ( new ReflectionClass( Mcpwp_Rate_Limiter::class ) )->getProperty( 'instance' );
		$instance->setAccessible( true );
		$instance->setValue( null );
	}

	/**
	 * Core's router for the routes under test — the stubbed rest_do_request()
	 * does not match registered patterns, so it is done here. Each real
	 * controller instance gets ITS OWN verify_api_key() call first, mirroring
	 * how a real permission_callback and callback share one registered
	 * controller instance in production (the credential forwarded onto the
	 * sub-request by forward_caller_credential() is what verify_api_key()
	 * reads here, exactly as the real permission_callback would).
	 */
	public function route_internal( $result, $request, $route = '', $handler = array() ) {
		if ( null !== $result ) {
			return $result;
		}
		$path = (string) $request->get_route();

		if ( preg_match( '#^/mcpwp/v1/pages/(\d+)$#', $path, $m ) ) {
			$request->set_param( 'id', (int) $m[1] );
			$c = new Mcpwp_REST_Pages();
			$c->verify_api_key( $request );
			return $c->get_page( $request );
		}
		if ( preg_match( '#^/mcpwp/v1/posts/(\d+)$#', $path, $m ) ) {
			$request->set_param( 'id', (int) $m[1] );
			$c = new Mcpwp_REST_Posts();
			$c->verify_api_key( $request );
			return $c->get_post( $request );
		}
		if ( preg_match( '#^/mcpwp/v1/elementor/(\d+)/summary$#', $path, $m ) ) {
			$request->set_param( 'id', (int) $m[1] );
			$c = new Mcpwp_REST_Elementor();
			$c->verify_api_key( $request );
			return $c->get_elementor_summary( $request );
		}
		if ( '/mcpwp/v1/site-context' === $path ) {
			$c = new Mcpwp_REST_Site_Info();
			$c->verify_api_key( $request );
			return $c->get_site_context( $request );
		}
		if ( '/mcpwp/v1/memory' === $path ) {
			$c = new Mcpwp_REST_Site_Memory();
			$c->verify_api_key( $request );
			return $c->list_memories( $request );
		}
		if ( preg_match( '#^/mcpwp/v1/guides/([a-z_]+)$#', $path, $m ) ) {
			$request->set_param( 'topic', $m[1] );
			$c = new Mcpwp_REST_Site_Network();
			$c->verify_api_key( $request );
			return $c->get_guide_topic( $request );
		}

		return $result;
	}

	private function dispatch( Mcpwp_REST_MCP $controller, $method, $params, $key ) {
		$request = new WP_REST_Request(
			'POST',
			'/mcpwp/v1/mcp',
			array(),
			array( 'X-API-Key' => $key ),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => $method,
				'params'  => $params,
			)
		);
		$this->assertTrue( $controller->verify_api_key( $request ), 'Test premise: the outer key authenticates.' );
		return $controller->handle_mcp( $request )->get_data();
	}

	// ── resources/templates/list ─────────────────────────────────────────

	public function test_resources_templates_list_advertises_the_four_templates(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/templates/list', array(), $key );

		$this->assertArrayHasKey( 'result', $data );
		$templates = array_column( $data['result']['resourceTemplates'], 'uriTemplate' );

		$this->assertContains( 'mcpwp://page/{id}', $templates );
		$this->assertContains( 'mcpwp://brand', $templates );
		$this->assertContains( 'mcpwp://memory/{namespace}', $templates );
		$this->assertContains( 'mcpwp://guide/{topic}', $templates );

		foreach ( $data['result']['resourceTemplates'] as $template ) {
			$this->assertArrayHasKey( 'name', $template );
			$this->assertArrayHasKey( 'description', $template );
			$this->assertArrayHasKey( 'mimeType', $template );
		}
	}

	// ── mcpwp://page/{id} ─────────────────────────────────────────────────

	public function test_page_template_requires_positive_integer_id(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/0' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32002, $data['error']['code'] );
	}

	public function test_page_template_unknown_id_is_not_found(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/999999' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32002, $data['error']['code'] );
	}

	public function test_page_template_non_page_non_post_type_is_not_found(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . self::ATTACHMENT_ID ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32002, $data['error']['code'] );
	}

	public function test_page_template_denies_a_role_that_lacks_content_category(): void {
		$controller = new Mcpwp_REST_MCP();
		// designer: elementor*, gutenberg, media, site — no "content".
		$key = $controller->create_scoped_api_key( 'designer', array( 'read', 'write' ), 'designer' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . self::PUB_PAGE_ID ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( 'scope_denied', $data['error']['data']['code'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'A category-denied read must never reach the inner /pages dispatch.' );
	}

	public function test_page_template_draft_hidden_from_read_only_key(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'reader', array( 'read' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . self::DRAFT_PAGE_ID ), $key );

		// Folded into the single shared not-found shape (#1015 review round
		// 1, B1) — no route-specific 'code' survives; see
		// test_page_template_hidden_missing_and_wrong_type_all_answer_identically().
		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32002, $data['error']['code'] );
	}

	public function test_page_template_draft_visible_to_write_key(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'writer', array( 'read', 'write' ), 'admin' )['key'];

		$data    = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . self::DRAFT_PAGE_ID ), $key );
		$summary = json_decode( $data['result']['contents'][0]['text'], true );

		$this->assertSame( 'draft', $summary['status'] );
		$this->assertSame( 'page', $summary['type'] );
		$this->assertSame( 'Secret Draft Page', $summary['title'] );
	}

	public function test_page_template_returns_summary_fields_for_a_published_page(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data    = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . self::PUB_PAGE_ID ), $key );
		$summary = json_decode( $data['result']['contents'][0]['text'], true );

		$this->assertSame( self::PUB_PAGE_ID, $summary['id'] );
		$this->assertSame( 'page', $summary['type'] );
		$this->assertSame( 'publish', $summary['status'] );
		$this->assertSame( 'Public Landing Page', $summary['title'] );
		$this->assertArrayHasKey( 'url', $summary );
		$this->assertArrayHasKey( 'template', $summary );
		// This fixture has no Elementor data, so the summary must not fabricate one.
		$this->assertNull( $summary['elementor'] );
	}

	public function test_page_template_resolves_a_post_id_through_the_posts_route(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data    = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . self::PUB_POST_ID ), $key );
		$summary = json_decode( $data['result']['contents'][0]['text'], true );

		$this->assertSame( 'post', $summary['type'] );
		$this->assertSame( 'Public Post', $summary['title'] );
		// Posts carry no template meta — the field must not be fabricated.
		$this->assertNull( $summary['template'] );
	}

	/**
	 * #1015 adversarial review round 1, B1: a hidden draft/private/future/
	 * trashed post, an attachment id, and a genuinely nonexistent id must
	 * answer with the exact same error — same code, same message, same
	 * data shape (only the echoed `uri` differs, and that is already known
	 * to the caller). Before this fix, missing/wrong-type used -32002 with a
	 * fixed message while a hidden post fell through to -32003 carrying the
	 * inner route's own "Page not found."/"Post not found." — an oracle that
	 * let a read-only caller learn a hidden post exists, and its type,
	 * purely from which error shape came back.
	 */
	public function test_page_template_hidden_missing_and_wrong_type_all_answer_identically(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'reader', array( 'read' ), 'admin' )['key'];

		$cases = array(
			'nonexistent id'  => 999999,
			'attachment id'   => self::ATTACHMENT_ID,
			'draft page'      => self::DRAFT_PAGE_ID,
			'private post'    => self::PRIVATE_POST_ID,
			'trashed page'    => self::TRASHED_PAGE_ID,
			'future post'     => self::FUTURE_POST_ID,
		);

		$errors = array();
		foreach ( $cases as $label => $post_id ) {
			$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . $post_id ), $key );
			$this->assertArrayHasKey( 'error', $data, "Expected an error for {$label}." );
			// Strip the echoed uri (the only field allowed to differ — it is
			// exactly what the caller supplied, so it discloses nothing new)
			// before comparing the rest byte-for-byte.
			$error = $data['error'];
			unset( $error['data']['uri'] );
			$errors[ $label ] = $error;
		}

		$canonical = $errors['nonexistent id'];
		foreach ( $errors as $label => $error ) {
			$this->assertSame( $canonical, $error, "{$label} must answer identically to a nonexistent id." );
		}
	}

	public function test_page_template_draft_visible_to_write_key_is_still_distinguishable_from_not_found(): void {
		// Regression guard alongside the identical-error test above: a
		// VISIBLE draft (write-scope key) must still return real data, not
		// the shared not-found — the fix must not have over-corrected into
		// hiding everything.
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'writer', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . self::DRAFT_PAGE_ID ), $key );
		$this->assertArrayHasKey( 'result', $data );
	}

	/**
	 * #1015 adversarial review round 1, W1: a query string on a template URI
	 * must be refused outright, never layered onto the inner request's
	 * params — mcpwp://page/{id} never reads its own query string at all
	 * (unlike the six FIXED resources, e.g. /pages?per_page=…, where an
	 * explicit override is intentional and unrelated to this fix), so any
	 * query string here is refused up front rather than silently ignored.
	 */
	public function test_page_template_rejects_a_query_string(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . self::PUB_PAGE_ID . '?foo=bar' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'A rejected query string must never reach the inner dispatch.' );
	}

	/**
	 * #1015 adversarial review round 1, N1: `$` in a non-multiline PCRE
	 * pattern also matches just before a trailing "\n" — a URI carrying one
	 * must not be treated as a valid id.
	 */
	public function test_page_template_rejects_a_trailing_newline(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://page/' . self::PUB_PAGE_ID . "\n" ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32002, $data['error']['code'] );
	}

	// ── mcpwp://memory/{namespace} ────────────────────────────────────────

	public function test_memory_template_denies_a_role_that_lacks_site_category(): void {
		$controller = new Mcpwp_REST_MCP();
		// author: content, media, taxonomy — no "site".
		$key = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://memory/identity' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( 'scope_denied', $data['error']['data']['code'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'A category-denied read must never reach the inner /memory dispatch.' );
	}

	public function test_memory_template_returns_stored_entries_for_an_allowed_key(): void {
		Mcpwp_Site_Memory::remember( 'identity', 'brand_voice', 'Friendly and direct' );
		Mcpwp_Site_Memory::remember( 'history', 'unrelated', 'should not appear' );

		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data   = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://memory/identity' ), $key );
		$result = json_decode( $data['result']['contents'][0]['text'], true );

		$this->assertSame( 1, $result['count'] );
		$this->assertSame( 'identity', $result['memories'][0]['namespace'] );
		$this->assertSame( 'brand_voice', $result['memories'][0]['key'] );
		$this->assertSame( 'Friendly and direct', $result['memories'][0]['value'] );
	}

	/**
	 * #1015 adversarial review round 1, W1: resolve_mcp_resource() strips the
	 * query string when extracting the KEY ("memory/identity"), so the
	 * namespace it puts on the sub-request's query params is always
	 * "identity" — but handle_resources_read()'s own query-override block
	 * (kept, on purpose, for the six FIXED resources) then re-parsed the
	 * ORIGINAL uri's query string and overwrote that param, so
	 * mcpwp://memory/identity?namespace=constraints actually served
	 * "constraints" under the "identity" URI, and "?namespace=" (empty)
	 * silently cleared the filter to "everything". Both must now be refused
	 * outright instead.
	 */
	public function test_memory_template_rejects_a_query_string_override(): void {
		Mcpwp_Site_Memory::remember( 'identity', 'id_key', 'identity value' );
		Mcpwp_Site_Memory::remember( 'constraints', 'con_key', 'constraints value' );

		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://memory/identity?namespace=constraints' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'A rejected query override must never reach the inner /memory dispatch.' );

		// The "clear the filter" variant must be refused too.
		$data2 = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://memory/identity?namespace=' ), $key );
		$this->assertArrayHasKey( 'error', $data2 );
		$this->assertSame( -32602, $data2['error']['code'] );
	}

	/**
	 * #1015 adversarial review round 1, N2: the memory/guide key match used
	 * to be case-INsensitive while page/brand were already case-sensitive.
	 * Now consistently case-sensitive across all four.
	 */
	public function test_memory_template_namespace_match_is_case_sensitive(): void {
		Mcpwp_Site_Memory::remember( 'identity', 'k', 'v' );

		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://Memory/identity' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32002, $data['error']['code'] );
	}

	// ── mcpwp://guide/{topic} ─────────────────────────────────────────────

	public function test_guide_template_denies_a_role_that_lacks_site_category(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://guide/onboarding' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( 'scope_denied', $data['error']['data']['code'] );
	}

	public function test_guide_template_returns_real_guide_content(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data  = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://guide/elementor' ), $key );
		$guide = json_decode( $data['result']['contents'][0]['text'], true );

		$this->assertSame( 'elementor', $guide['topic'] );
	}

	public function test_guide_template_unknown_topic_reports_the_real_error(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://guide/not_a_real_topic' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( 'invalid_topic', $data['error']['data']['code'] );
	}

	public function test_guide_template_rejects_a_query_string(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://guide/elementor?topic=seo' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'A rejected query string must never reach the inner /guides dispatch.' );
	}

	public function test_guide_template_topic_match_is_case_sensitive(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://guide/ELEMENTOR' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32002, $data['error']['code'] );
	}

	// ── mcpwp://brand ─────────────────────────────────────────────────────

	public function test_brand_template_denies_a_role_that_lacks_site_category(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'author', array( 'read', 'write' ), 'author' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://brand' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( 'scope_denied', $data['error']['data']['code'] );
	}

	public function test_brand_template_returns_site_context_for_an_allowed_key(): void {
		update_option( 'mcpwp_site_context', 'Warm, direct, no corporate jargon.' );

		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data  = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://brand' ), $key );
		$brand = json_decode( $data['result']['contents'][0]['text'], true );

		$this->assertArrayHasKey( 'site_context', $brand );
		$this->assertArrayHasKey( 'elementor_globals', $brand );
	}

	public function test_brand_template_rejects_a_query_string(): void {
		$controller = new Mcpwp_REST_MCP();
		$key        = $controller->create_scoped_api_key( 'admin', array( 'read', 'write' ), 'admin' )['key'];

		$data = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://brand?foo=bar' ), $key );

		$this->assertArrayHasKey( 'error', $data );
		$this->assertSame( -32602, $data['error']['code'] );
		$this->assertEmpty( $GLOBALS['mcpwp_test_dispatched_routes'], 'A rejected query string must never reach the inner /site-context dispatch.' );
	}

	public function test_brand_template_omits_globals_when_the_key_lacks_that_category_but_still_returns_context(): void {
		update_option( 'mcpwp_site_context', 'Warm, direct, no corporate jargon.' );

		$controller = new Mcpwp_REST_MCP();
		// Custom role scoped to exactly "site" — enough for site-context, not
		// enough for wp_get_elementor_globals's category.
		$key = $controller->create_scoped_api_key( 'site-only', array( 'read', 'write' ), 'custom', array( 'site' ) )['key'];

		$data  = $this->dispatch( $controller, 'resources/read', array( 'uri' => 'mcpwp://brand' ), $key );
		$brand = json_decode( $data['result']['contents'][0]['text'], true );

		$this->assertArrayHasKey( 'site_context', $brand, 'site-context must be returned independently of the globals gate.' );
		$this->assertTrue( $brand['elementor_globals']['unavailable'] );
		$this->assertSame( 'scope_denied', $brand['elementor_globals']['reason'] );
	}
}
