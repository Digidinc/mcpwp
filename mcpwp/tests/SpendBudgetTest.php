<?php
/**
 * Coverage for #752 F9 — fan-out spend amplification.
 *
 * The sub-request COUNT of one HTTP request was bounded; its SPEND was not.
 * One JSON-RPC batch of tool calls, each a wp_batch of 100 operations, each
 * operation a bulk route or a call against the site's OpenAI key, multiplied
 * into thousands of writes or hundreds of paid generations from one request.
 * The rate limiter does not see it — and a whitelisted key, or a site with the
 * limiter switched off, sees nothing at all.
 *
 * The decision (#752, 2026-09-06): one spend budget per top-level HTTP request,
 * shared by its whole fan-out tree. Default 1000 units (filter
 * `mcpwp_request_spend_budget`). An ordinary operation costs 1, a bulk route the
 * items it processes, a credential-spending or egress route 25 per call or URL
 * item (filter `mcpwp_spend_weight`). Exhaustion refuses the remaining
 * operations one by one with `spend_budget_exhausted` — never a silent drop,
 * never undoing what already ran — and the response says what is left.
 *
 * Every test here runs with the rate limiter OFF: the budget is a separate
 * control and must bind without it.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-batch.php';
require_once dirname( __DIR__ ) . '/includes/api/class-mcpwp-rest-site-content.php';

final class SpendBudgetTest extends TestCase {

	const EGRESS = array(
		'/integrations/generate-image',
		'/integrations/generate-featured-image',
		'/integrations/text-to-speech',
		'/integrations/describe-image',
		'/integrations/generate-alt-text',
		'/integrations/generate-excerpt',
		'/media/from-url',
	);

	/** @var array */
	private $key;

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_current_user']      = 2;
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_refused_routes']    = array();
		$GLOBALS['mcpwp_test_wpdb_options']      = array();
		$GLOBALS['mcpwp_test_route_patterns']           = array();
		$GLOBALS['mcpwp_test_route_permission_denied'] = array();
		$GLOBALS['mcpwp_test_denied_routes']            = array();
		Mcpwp_REST_MCP::reset_verdict_memo();
		Mcpwp_REST_Batch::$is_batch_sub_request = false;

		$this->key = ( new Mcpwp_REST_MCP() )->create_scoped_api_key( 'spend', array( 'admin' ) );

		Mcpwp_Rate_Limiter::get_instance()->update_settings(
			array(
				'enabled'             => false,
				'requests_per_minute' => 60,
				'requests_per_hour'   => 1000,
				'burst_limit'         => 30,
				'whitelist'           => array(),
			)
		);

		add_filter( 'rest_dispatch_request', array( $this, 'route_batch' ), 10, 4 );
	}

	protected function tearDown(): void {
		$GLOBALS['mcpwp_test_options']           = array();
		$GLOBALS['mcpwp_test_transients']        = array();
		$GLOBALS['mcpwp_test_filters']           = array();
		$GLOBALS['mcpwp_test_dispatched_routes'] = array();
		$GLOBALS['mcpwp_test_refused_routes']    = array();
		$GLOBALS['mcpwp_test_route_patterns']           = array();
		$GLOBALS['mcpwp_test_route_permission_denied'] = array();
		$GLOBALS['mcpwp_test_denied_routes']            = array();
		unset( $GLOBALS['mcpwp_test_rest_response_override'] );
		Mcpwp_REST_Batch::$is_batch_sub_request = false;
	}

	/**
	 * Core's router, for the one route that fans out.
	 */
	public function route_batch( $result, $request, $route = '', $handler = array() ) {
		if ( null === $result && preg_match( '#^/mcpwp/v1/batch$#i', (string) $route ) ) {
			return ( new Mcpwp_REST_Batch() )->handle_batch( $request );
		}
		return $result;
	}

	private function budget( int $units ): void {
		add_filter(
			'mcpwp_request_spend_budget',
			function () use ( $units ) {
				return $units;
			}
		);
	}

	private function op( string $method, string $path, array $body = array() ): array {
		return array( 'method' => $method, 'path' => $path, 'body' => $body );
	}

	private function times( int $n, array $op ): array {
		return array_fill( 0, $n, $op );
	}

	private function image(): array {
		return $this->op( 'POST', '/integrations/generate-image', array( 'prompt' => 'a lighthouse' ) );
	}

	/**
	 * A path that looks ordinary, which core matches to a handler that fetches
	 * a URL: its price is only known at dispatch, from the matched pattern.
	 */
	private function alias(): array {
		return $this->op( 'POST', '/assets/import', array( 'src' => 'https://example.com/a.jpg' ) );
	}

	private function route_alias_to_paid_handler(): void {
		$GLOBALS['mcpwp_test_route_patterns']['#^/mcpwp/v1/assets/import$#i'] = '/mcpwp/v1/media/from-url';
	}

	private function batch_response( array $operations, array $extra_params = array() ) {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array(), array( 'X-API-Key' => $this->key['key'] ) );
		$request->set_param( 'operations', $operations );
		foreach ( $extra_params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		return ( new Mcpwp_REST_Batch() )->handle_batch( $request );
	}

	private function batch_refusal( array $operations, array $extra_params = array() ): WP_Error {
		$refusal = $this->batch_response( $operations, $extra_params );
		$this->assertInstanceOf( WP_Error::class, $refusal, 'Expected the whole batch to be refused.' );
		$this->assertSame( 'spend_budget_exceeded', $refusal->get_error_code() );
		return $refusal;
	}

	private function operation_dispatches(): array {
		return array_values(
			array_filter(
				$GLOBALS['mcpwp_test_dispatched_routes'],
				function ( $route ) {
					return '/mcpwp/v1/batch' !== $route;
				}
			)
		);
	}

	private function batch( array $operations, array $extra_params = array() ): array {
		$request = new WP_REST_Request( 'POST', '/mcpwp/v1/batch', array(), array( 'X-API-Key' => $this->key['key'] ) );
		$request->set_param( 'operations', $operations );
		foreach ( $extra_params as $name => $value ) {
			$request->set_param( $name, $value );
		}

		$response = ( new Mcpwp_REST_Batch() )->handle_batch( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );

		return $response->get_data();
	}

	private function spent( array $operations ): int {
		$data = $this->batch( $operations );
		$this->assertArrayHasKey( 'spend_budget', $data, 'The batch response reports its budget.' );
		return $data['spend_budget']['spent'];
	}

	private function tool( string $name, array $arguments, $id = 1, array $meta = array() ): array {
		$params = array( 'name' => $name, 'arguments' => $arguments );
		if ( ! empty( $meta ) ) {
			$params['_meta'] = $meta;
		}
		return array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => $params );
	}

	private function mcp( $body ) {
		$controller = new Mcpwp_REST_MCP();
		$request    = new WP_REST_Request( 'POST', '/mcpwp/v1/mcp', array(), array( 'X-API-Key' => $this->key['key'] ), $body );
		$this->assertTrue( $controller->verify_api_key( $request ) );

		return $controller->handle_mcp( $request )->get_data();
	}

	private function tool_body( array $response ): array {
		$this->assertArrayHasKey( 'result', $response, 'Expected a result, got: ' . wp_json_encode( $response ) );
		return json_decode( $response['result']['content'][0]['text'], true );
	}

	private function statuses( array $data ): array {
		return array_map(
			function ( $result ) {
				return $result['status'];
			},
			$data['results']
		);
	}

	// ---------------------------------------------------------------
	// The finding, on REST /batch.
	// ---------------------------------------------------------------

	public function test_a_batch_priced_over_the_budget_is_refused_whole_before_anything_runs(): void {
		$refusal = $this->batch_refusal( $this->times( 50, $this->image() ) );
		$data    = $refusal->get_error_data();

		$this->assertSame( 413, $data['status'] );
		$this->assertSame( 1250, $data['weight'], 'The price of the whole call.' );
		$this->assertSame( 1000, $data['remaining'] );
		$this->assertSame( 1000, $data['limit'] );
		$this->assertStringContainsString( 'split', $data['hint'] );
		$this->assertNotEmpty( $refusal->get_error_message() );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'Nothing ran, so nothing was paid for and nothing needs a retry.' );

		// A batch that fits exactly runs whole.
		$fits = $this->batch( $this->times( 40, $this->image() ) );
		$this->assertSame( 40, $fits['succeeded'] );
		$this->assertSame( 1000, $fits['spend_budget']['spent'] );
		$this->assertSame( array(), $fits['spend_budget']['refused'] );
	}

	// ---------------------------------------------------------------
	// #804 adversarial review, BLOCK-1: a screenshot widths sweep does one
	// render (and, with save_to_media, one attachment) per width — it must
	// be priced per width, not at the flat 25-unit price of one screenshot.
	// ---------------------------------------------------------------

	private function screenshot( array $widths = array() ): array {
		$body = array( 'url' => 'https://example.com/page' );
		if ( ! empty( $widths ) ) {
			$body['widths'] = $widths;
		}
		return $this->op( 'POST', '/screenshot', $body );
	}

	public function test_six_widths_costs_six_times_one_screenshot(): void {
		$one = $this->spent( array( $this->screenshot() ) );
		$six = $this->spent( array( $this->screenshot( array( 390, 500, 600, 700, 800, 900 ) ) ) );

		$this->assertSame( 25, $one, 'One screenshot, no widths, is still priced as one ordinary egress call.' );
		$this->assertSame(
			150,
			$six,
			'6 widths must cost 6x one screenshot (25 each) — not the flat 25 a single-width call costs, which would let a widths sweep do 6x the outbound renders/attachments for the same charge.'
		);
	}

	public function test_widths_also_scales_the_weighted_rate_limit_slot_count(): void {
		// Same fan-out, the other ceiling (#802): the weighted rate limiter
		// must see the same per-width scaling the spend budget does, via
		// LIST_GROUPS — not just SPEND_URL_ITEM_ROUTES.
		$this->assertSame( 0, Mcpwp_Operation_Weights::list_count( array( 'url' => 'https://example.com' ) ), 'No list-shaped argument at all — list_count itself does not clamp to 1.' );
		$this->assertSame(
			6,
			Mcpwp_Operation_Weights::list_count( array( 'url' => 'https://example.com', 'widths' => array( 390, 500, 600, 700, 800, 900 ) ) )
		);
	}

	// #804 round 3, N-R3-2: list_count() must price the EFFECTIVE width
	// count (after clamp/dedupe/cap), the same way SPEND_URL_ITEM_ROUTES
	// already does for the spend budget — 40 copies of the same width only
	// ever render one image, so it must cost one rate-limit slot, not 40.
	public function test_forty_copies_of_the_same_width_costs_one_rate_slot(): void {
		$this->assertSame(
			1,
			Mcpwp_Operation_Weights::list_count( array( 'url' => 'https://example.com', 'widths' => array_fill( 0, 40, 390 ) ) )
		);
	}

	public function test_a_wp_batch_of_forty_screenshots_at_six_widths_each_is_refused_whole(): void {
		// 40 operations x 6 widths x 25 units/width = 6000 — far over the
		// 1000-unit budget, and it must be refused whole, before anything
		// dispatches, exactly like the plain-screenshot case above.
		$refusal = $this->batch_refusal( $this->times( 40, $this->screenshot( array( 390, 500, 600, 700, 800, 900 ) ) ) );
		$data    = $refusal->get_error_data();

		$this->assertSame( 413, $data['status'] );
		$this->assertSame( 6000, $data['weight'], 'The price of the whole call: 40 x 6 widths x 25.' );
		$this->assertSame( 1000, $data['remaining'] );
		$this->assertSame( 1000, $data['limit'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'Nothing ran, so nothing was paid for and nothing needs a retry.' );

		// A batch of the same shape that fits the budget runs whole: 6 ops x
		// 6 widths x 25 = 900.
		$fits = $this->batch( $this->times( 6, $this->screenshot( array( 390, 500, 600, 700, 800, 900 ) ) ) );
		$this->assertSame( 6, $fits['succeeded'] );
		$this->assertSame( 900, $fits['spend_budget']['spent'] );
		$this->assertSame( array(), $fits['spend_budget']['refused'] );
	}

	// #804 round-2 review, N-R2-2: price the widths that will ACTUALLY run
	// (clamp 320-1920, dedupe, cap at 6) — not the raw length of whatever
	// list the caller sent.
	public function test_screenshot_widths_price_the_effective_count_not_the_raw_list(): void {
		// clamp: [100,200,500,600] -> [320,320,500,600]; dedupe -> [320,500,600].
		// Effective count 3, not the raw list's 4.
		$cost = $this->spent( array( $this->screenshot( array( 100, 200, 500, 600 ) ) ) );
		$this->assertSame( 75, $cost, '3 effective widths x 25 — not 4 (raw count) x 25 = 100.' );
	}

	// N-R2-2's other half: a widths sweep sent as a comma-separated string
	// (some MCP clients encode it that way, exactly like an id list) must
	// price the same as the equivalent array — not zero, and not skipped.
	public function test_screenshot_widths_as_comma_string_price_the_same_as_an_array(): void {
		$array_cost  = $this->spent( array( $this->screenshot( array( 390, 500, 600 ) ) ) );
		$string_cost = $this->spent( array( $this->op( 'POST', '/screenshot', array( 'url' => 'https://example.com/page', 'widths' => '390,500,600' ) ) ) );

		$this->assertSame( 75, $array_cost );
		$this->assertSame( $array_cost, $string_cost );
	}

	// ---------------------------------------------------------------
	// #804 round-2 review, W-R2-2/N-R2-1: /rendered-html's per-16KB size
	// pricing must actually bind a batch (the first pass's 64KB/unit priced
	// the 512KB ceiling at only 8 units — 100 ceiling reads was 800, still
	// under the 1000 budget) and must price the CLAMPED max_bytes, not
	// whatever oversized number a caller merely asked for.
	// ---------------------------------------------------------------

	private function rendered_html( int $max_bytes = null ): array {
		$body = array( 'url' => 'https://example.com/page' );
		if ( null !== $max_bytes ) {
			$body['max_bytes'] = $max_bytes;
		}
		return $this->op( 'GET', '/rendered-html', $body );
	}

	public function test_rendered_html_default_cost_and_ceiling_cost(): void {
		// No max_bytes sent at all: priced as the cheapest ordinary call —
		// unchanged, no regression for the overwhelmingly common case of a
		// caller who never names a size.
		$this->assertSame( 1, $this->spent( array( $this->rendered_html() ) ) );

		// #804 round 3 correction: an explicit max_bytes=51200 (the
		// handler's own default) must ALSO cost 1 — round 2's flat
		// 16KB/unit pricing quietly quadrupled this to 4 for every
		// existing default caller, which nothing asked it to do. A clamped
		// value at or under the baseline (51200) is free of the per-KB
		// meter entirely; only bytes requested ABOVE it are metered.
		$this->assertSame( 1, $this->spent( array( $this->rendered_html( 51200 ) ) ) );

		// The ceiling (512KB) costs 1 + ceil((524288-51200)/16384)
		// = 1 + 29 = 30 units — still enough that a batch of 100 ceiling
		// reads (3000) is refused whole against the 1000 budget.
		$this->assertSame( 30, $this->spent( array( $this->rendered_html( 524288 ) ) ) );
	}

	public function test_rendered_html_price_clamps_before_dividing(): void {
		// #804 N-R2-1: a request for far more than the ceiling is priced
		// from the CLAMPED value the handler will actually read (the
		// ceiling itself), not the raw, unrealistic number sent — the
		// handler never holds more than the ceiling in memory, so pricing
		// an unclamped number would price memory the call never allocates.
		$this->assertSame(
			30,
			$this->spent( array( $this->rendered_html( 999999999 ) ) ),
			'Priced as the 512KB ceiling (30 units), not as ~999MB worth of 16KB units.'
		);
	}

	public function test_a_batch_of_a_hundred_ceiling_rendered_html_reads_is_refused_whole(): void {
		// 100 x 30 = 3000 — far over the 1000-unit budget. Round 2's flat
		// 16KB/unit-from-zero pricing put the ceiling at 32 (same order of
		// magnitude); round 1's 64KB/unit put this same batch at only 800,
		// which fit — the batch-level ceiling this pricing exists to bind
		// never bound. This still binds it, without repricing the default.
		$refusal = $this->batch_refusal( $this->times( 100, $this->rendered_html( 524288 ) ) );
		$data    = $refusal->get_error_data();

		$this->assertSame( 413, $data['status'] );
		$this->assertSame( 3000, $data['weight'] );
		$this->assertSame( 1000, $data['remaining'] );
		$this->assertSame( 1000, $data['limit'] );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'Nothing ran.' );

		// A batch of the same shape that fits: 33 x 30 = 990 <= 1000.
		$fits = $this->batch( $this->times( 33, $this->rendered_html( 524288 ) ) );
		$this->assertSame( 33, $fits['succeeded'] );
		$this->assertSame( 990, $fits['spend_budget']['spent'] );
		$this->assertSame( array(), $fits['spend_budget']['refused'] );
	}

	// A batch of default-sized reads must still cost exactly its item
	// count — the regression round 3 exists to prevent, made explicit at
	// batch scale rather than only for one call.
	public function test_a_batch_of_a_hundred_default_rendered_html_reads_still_costs_a_hundred(): void {
		$fits = $this->batch( $this->times( 100, $this->rendered_html( 51200 ) ) );
		$this->assertSame( 100, $fits['succeeded'] );
		$this->assertSame( 100, $fits['spend_budget']['spent'] );
		$this->assertSame( array(), $fits['spend_budget']['refused'] );
	}

	// A future edit to either ceiling/baseline in isolation is a silent
	// price/memory mismatch — this guard makes that a failing test instead.
	public function test_rendered_html_pricing_ceiling_and_baseline_match_the_handlers(): void {
		$this->assertSame(
			Mcpwp_REST_Site_Content::RENDERED_HTML_MAX_BYTES_CEILING,
			Mcpwp_Operation_Weights::SPEND_SIZE_ROUTES['/rendered-html']['ceiling'],
			'Mcpwp_Operation_Weights::SPEND_SIZE_ROUTES[\'/rendered-html\'][\'ceiling\'] must be kept equal to Mcpwp_REST_Site_Content::RENDERED_HTML_MAX_BYTES_CEILING by hand.'
		);
		$this->assertSame(
			Mcpwp_REST_Site_Content::RENDERED_HTML_DEFAULT_MAX_BYTES,
			Mcpwp_Operation_Weights::SPEND_SIZE_ROUTES['/rendered-html']['baseline'],
			'Mcpwp_Operation_Weights::SPEND_SIZE_ROUTES[\'/rendered-html\'][\'baseline\'] must be kept equal to Mcpwp_REST_Site_Content::RENDERED_HTML_DEFAULT_MAX_BYTES by hand.'
		);
	}

	public function test_a_refusal_says_what_the_operation_needed_and_what_was_left(): void {
		$this->budget( 30 );
		$this->route_alias_to_paid_handler();
		$data    = $this->batch( array( $this->alias(), $this->alias() ) );
		$refusal = $data['results'][1]['data'];

		$this->assertSame( 'spend_budget_exhausted', $refusal['code'] );
		$this->assertNotEmpty( $refusal['message'] );
		$this->assertSame( 413, $refusal['data']['status'] );
		$this->assertSame( 25, $refusal['data']['weight'], 'Priced at dispatch from the pattern core matched.' );
		$this->assertSame( 5, $refusal['data']['remaining'] );
		$this->assertSame( 30, $refusal['data']['limit'] );
		$this->assertNotEmpty( $refusal['data']['hint'] );
	}

	public function test_after_the_first_refusal_the_refused_set_is_the_exact_tail(): void {
		// Every operation after the first refusal is refused without being
		// dispatched — including ones core or the controller would have
		// rejected for another reason — so `refused` is exactly what to resend.
		$this->budget( 30 );
		$this->route_alias_to_paid_handler();
		$data = $this->batch( array( $this->alias(), $this->alias(), $this->op( 'POST', '/mcp' ), $this->op( 'PATCH', '/posts/1' ), $this->op( 'GET', '/posts/1' ) ) );

		$this->assertSame( array( 200, 413, 413, 413, 413 ), $this->statuses( $data ) );
		foreach ( array( 1, 2, 3, 4 ) as $index ) {
			$this->assertSame( 'spend_budget_exhausted', $data['results'][ $index ]['data']['code'], 'index ' . $index );
		}
		$this->assertSame( array( 1, 2, 3, 4 ), $data['spend_budget']['refused'] );
		$this->assertSame( 25, $data['spend_budget']['spent'] );
		$this->assertSame( 5, $data['spend_budget']['remaining'] );
		$this->assertSame( array( '/mcpwp/v1/assets/import' ), $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	// ---------------------------------------------------------------
	// Weights.
	// ---------------------------------------------------------------

	public function test_every_listed_credential_or_egress_route_costs_twenty_five(): void {
		foreach ( self::EGRESS as $route ) {
			$this->assertSame( 25, $this->spent( array( $this->op( 'POST', $route ) ) ), $route );
		}
	}

	public function credential_or_caller_url_route_provider(): array {
		return array(
			'screenshot (worker token, caller URL)'   => array( 'POST', '/screenshot', array( 'url' => 'https://example.com/' ) ),
			'stock photo search (Pexels quota)'       => array( 'GET', '/integrations/stock-photos', array( 'query' => 'sea' ) ),
			'stock photo download (Pexels + fetch)'   => array( 'POST', '/integrations/stock-photos/download', array( 'photo_id' => 1 ) ),
			'integration test (stored credentials)'   => array( 'POST', '/integrations/test', array( 'provider' => 'openai' ) ),
			'provider test'                           => array( 'POST', '/integrations/providers/openai/test', array() ),
			'cloudflare status'                       => array( 'GET', '/cloudflare/status', array() ),
			'cloudflare purge'                        => array( 'POST', '/cloudflare/purge-cache', array() ),
			'cloudflare fix access'                   => array( 'POST', '/cloudflare/fix-mcp-access', array() ),
			'cloudflare speed read'                   => array( 'GET', '/cloudflare/speed-settings', array() ),
			'cloudflare speed write'                  => array( 'POST', '/cloudflare/speed-settings', array() ),
			'figma status (may refresh OAuth)'        => array( 'GET', '/figma/status', array() ),
			'figma file'                              => array( 'GET', '/figma/file', array() ),
			'figma node'                              => array( 'GET', '/figma/node', array() ),
			'figma tokens'                            => array( 'GET', '/figma/design-tokens', array() ),
			'figma sync'                              => array( 'POST', '/figma/design-tokens/sync', array() ),
			'google indexing submit'                  => array( 'POST', '/google-indexing/submit', array() ),
			'google indexing status'                  => array( 'GET', '/google-indexing/status', array() ),
			'staging environments'                    => array( 'GET', '/staging', array() ),
			'staging push'                            => array( 'POST', '/staging/push', array() ),
			'staging status'                          => array( 'GET', '/staging/status/abc', array() ),
			'keyword research (Suggest + DataForSEO)' => array( 'GET', '/keyword-research', array( 'query' => 'x' ) ),
			'visibility refresh (DataForSEO)'         => array( 'POST', '/seo/visibility/refresh', array() ),
			'opportunities seed lookup (DataForSEO)'  => array( 'GET', '/seo/opportunities', array( 'seed' => 'x' ) ),
			'site audit (Cloudflare when configured)' => array( 'GET', '/seo/audit', array() ),
			'webhook test (caller-chosen URL)'        => array( 'POST', '/webhooks/5/test', array() ),
			'feedback (GitHub token)'                 => array( 'POST', '/feedback', array( 'title' => 't' ) ),
			'feedback relay (GitHub token)'           => array( 'POST', '/feedback/relay', array() ),
			'favicon from URL'                        => array( 'POST', '/favicon', array( 'url' => 'https://example.com/i.png' ) ),
			'favicon from URL, PUT'                   => array( 'PUT', '/favicon', array( 'url' => 'https://example.com/i.png' ) ),
			'update from package URL'                 => array( 'POST', '/update', array( 'package_url' => 'https://example.com/p.zip' ) ),
			'design reference from image URL'         => array( 'POST', '/design-references', array( 'image_url' => 'https://example.com/i.png' ) ),
			'design reference update from image URL'  => array( 'PUT', '/design-references/ref_1', array( 'image_url' => 'https://example.com/i.png' ) ),
			'spelled differently'                     => array( 'POST', '/Webhooks//5/Test/', array() ),
			'spelled differently, prefix'             => array( 'POST', '/CLOUDFLARE/purge-cache?x=1', array() ),
		);
	}

	/**
	 * Every route that makes an outbound request with a site credential or to a
	 * caller-chosen URL costs 25, like the AI and URL-import routes.
	 *
	 * @dataProvider credential_or_caller_url_route_provider
	 */
	public function test_every_route_that_spends_a_credential_or_fetches_a_caller_url_costs_twenty_five( string $method, string $path, array $body ): void {
		$this->assertSame( 25, $this->spent( array( $this->op( $method, $path, $body ) ) ), $method . ' ' . $path );
	}

	public function test_a_matched_route_pattern_is_priced_like_the_route_it_matched(): void {
		// Core hands rest_dispatch_request the registered pattern, not the path.
		foreach ( array( '/mcpwp/v1/webhooks/(?P<id>\\d+)/test', '/mcpwp/v1/integrations/providers/(?P<provider>[a-z]+)/test', '/mcpwp/v1/staging/status/(?P<id>[a-zA-Z0-9_\\-]+)', '/mcpwp/v1/design-references/(?P<id>[A-Za-z0-9_-]+)' ) as $pattern ) {
			$this->assertSame( 25, Mcpwp_Operation_Weights::spend_weight( 'POST', $pattern, array() ), $pattern );
		}
		$this->assertSame( 25, Mcpwp_Operation_Weights::spend_weight( 'HEAD', '/integrations/stock-photos', array() ), 'HEAD runs the GET handler.' );
		$this->assertSame( 25, Mcpwp_Operation_Weights::spend_weight( '', '/favicon', array() ), 'A method that cannot be read is priced as the write.' );
	}

	public function test_local_reads_and_same_host_loopbacks_stay_ordinary(): void {
		$local = array(
			// /fetch resolves a URL to a local post (url_to_postid / get_page_by_path); it makes no request.
			array( 'GET', '/fetch', array( 'url' => 'https://example.com/elsewhere' ) ),
			// Same path, local read handler.
			array( 'GET', '/favicon', array() ),
			array( 'GET', '/feedback', array() ),
			array( 'GET', '/design-references', array() ),
			array( 'GET', '/design-references/ref_1', array() ),
			array( 'GET', '/webhooks/5/logs', array() ),
			array( 'GET', '/seo/visibility', array() ),
			array( 'POST', '/integrations/configure', array() ),
			// Loopbacks to this site's own permalinks behind a same-host guard.
			array( 'GET', '/rendered-html', array( 'id' => 5 ) ),
			array( 'GET', '/rendered-copy', array( 'id' => 5 ) ),
			array( 'GET', '/seo/readiness/5', array() ),
			array( 'GET', '/seo/structured-data/5', array() ),
			array( 'GET', '/elementor/5/preview', array() ),
		);
		foreach ( $local as $case ) {
			$this->assertSame( 1, $this->spent( array( $this->op( $case[0], $case[1], $case[2] ) ) ), $case[0] . ' ' . $case[1] );
		}
	}

	public function test_an_ordinary_operation_costs_one(): void {
		$operations = array();
		for ( $i = 1; $i <= 100; $i++ ) {
			$operations[] = 0 === $i % 2 ? $this->op( 'GET', '/posts/' . $i ) : $this->op( 'PUT', '/pages/' . $i, array( 'title' => 'T' ) );
		}
		$data = $this->batch( $operations );

		$this->assertSame( 100, $data['spend_budget']['spent'] );
		$this->assertSame( 900, $data['spend_budget']['remaining'] );
		$this->assertFalse( $data['spend_budget']['exhausted'] );
		$this->assertSame( array(), $data['spend_budget']['refused'] );
	}

	public function test_media_bulk_costs_twenty_five_per_url_item_whichever_key_carries_them(): void {
		$urls  = array_map( function ( $i ) { return 'https://example.com/' . $i . '.jpg'; }, range( 1, 20 ) );
		$items = array_map( function ( $url ) { return array( 'url' => $url ); }, $urls );

		$this->assertSame( 500, $this->spent( array( $this->op( 'POST', '/media/bulk', array( 'urls' => $urls ) ) ) ) );
		$this->assertSame( 500, $this->spent( array( $this->op( 'POST', '/media/bulk', array( 'items' => $items ) ) ) ) );
		$this->assertSame( 500, $this->spent( array( $this->op( 'POST', '/media/bulk', array( 'urls' => $urls, 'items' => array_slice( $items, 0, 3 ) ) ) ) ), 'Two spellings: the larger list is the price, whichever the handler reads.' );
	}

	public function test_a_bulk_route_costs_the_items_it_processes(): void {
		$this->assertSame( 50, $this->spent( array( $this->op( 'POST', '/posts/bulk', array( 'posts' => array_fill( 0, 50, array( 'title' => 'x' ) ) ) ) ) ) );
		$this->assertSame( 40, $this->spent( array( $this->op( 'PUT', '/pages/bulk', array( 'updates' => array_fill( 0, 40, array( 'id' => 1 ) ) ) ) ) ) );
		$this->assertSame( 30, $this->spent( array( $this->op( 'POST', '/seo/bulk', array( 'updates' => array_fill( 0, 30, array( 'id' => 1, 'title' => 't' ) ) ) ) ) ) );
		// /elementor/bulk carries its ids as one comma-separated string.
		$this->assertSame( 5, $this->spent( array( $this->op( 'GET', '/elementor/bulk', array( 'ids' => '1,2,3,4,5' ) ) ) ) );
		$this->assertSame( 3, $this->spent( array( $this->op( 'GET', '/elementor/bulk', array( 'ids' => ' 7 , 8,9,,' ) ) ) ) );
		$this->assertSame( 4, $this->spent( array( $this->op( 'GET', '/elementor/bulk', array( 'ids' => array( 1, 2, 3, 4 ) ) ) ) ) );
		$this->assertSame( 1, $this->spent( array( $this->op( 'GET', '/elementor/bulk', array( 'ids' => '' ) ) ) ), 'A malformed list still costs one operation, never zero.' );
	}

	public function test_the_fixed_weight_tools_cost_the_same_in_the_spend_budget(): void {
		$this->assertSame( 10, $this->spent( array( $this->op( 'POST', '/elementor/find-replace', array( 'search' => 'a', 'replace' => 'b', 'scope' => 'site' ) ) ) ) );
		$this->assertSame( 50, $this->spent( array( $this->op( 'POST', '/elementor/find-replace', array( 'search' => 'a', 'replace' => 'b', 'ids' => range( 1, 50 ) ) ) ) ) );
		$this->assertSame( 10, $this->spent( array( $this->op( 'DELETE', '/drafts/delete-all' ) ) ) );

		add_filter(
			'mcpwp_tool_operation_weight',
			function () {
				return 3;
			}
		);
		$this->assertSame( 3, $this->spent( array( $this->op( 'DELETE', '/drafts/delete-all' ) ) ), 'One map, one filter, both calculations.' );
	}

	public function test_the_spelling_of_a_route_does_not_change_its_price(): void {
		foreach ( array( '/Integrations/Generate-Image', 'integrations/generate-image', '//integrations//generate-image/', " /MEDIA/FROM-URL\n", '/media/from-url?x=1', '/media/from-url#f' ) as $path ) {
			$this->assertSame( 25, $this->spent( array( $this->op( 'POST', $path ) ) ), var_export( $path, true ) );
		}
	}

	public function test_weights_are_filterable_and_a_value_that_is_not_a_weight_keeps_the_default(): void {
		add_filter(
			'mcpwp_spend_weight',
			function ( $weight, $route ) {
				return 0 === strpos( $route, '/posts' ) ? 400 : $weight;
			},
			10,
			2
		);
		$this->assertSame( 1200, $this->batch_refusal( $this->times( 3, $this->op( 'GET', '/posts/1' ) ) )->get_error_data()['weight'], 'The filtered price is the price of the whole call too.' );
		$this->assertSame( array( 200, 200 ), $this->statuses( $this->batch( $this->times( 2, $this->op( 'GET', '/posts/1' ) ) ) ) );

		foreach ( array( 'free', -1, null, array(), NAN ) as $junk ) {
			$GLOBALS['mcpwp_test_filters'] = array();
			add_filter( 'rest_dispatch_request', array( $this, 'route_batch' ), 10, 4 );
			add_filter(
				'mcpwp_spend_weight',
				function () use ( $junk ) {
					return $junk;
				}
			);
			$this->assertSame( 50, $this->spent( $this->times( 2, $this->image() ) ), 'Unreadable weight is the computed default, never free: ' . var_export( $junk, true ) );
		}
	}

	public function test_the_budget_is_filterable_and_a_value_that_is_not_a_budget_is_the_default_not_unlimited(): void {
		$this->budget( 50 );
		$refusal = $this->batch_refusal( $this->times( 3, $this->image() ) )->get_error_data();
		$this->assertSame( array( 75, 50, 50 ), array( $refusal['weight'], $refusal['remaining'], $refusal['limit'] ) );
		$this->assertSame( array( 200, 200 ), $this->statuses( $this->batch( $this->times( 2, $this->image() ) ) ) );

		foreach ( array( 'lots', null, -5, array(), true, NAN ) as $junk ) {
			$GLOBALS['mcpwp_test_filters'] = array();
			add_filter( 'rest_dispatch_request', array( $this, 'route_batch' ), 10, 4 );
			add_filter(
				'mcpwp_request_spend_budget',
				function () use ( $junk ) {
					return $junk;
				}
			);
			$refusal = $this->batch_refusal( $this->times( 41, $this->image() ) )->get_error_data();
			$this->assertSame( 1000, $refusal['limit'], 'Still bounded: ' . var_export( $junk, true ) );
		}
	}

	public function test_a_client_cannot_raise_its_own_budget_or_lower_a_price(): void {
		$operations   = $this->times( 40, $this->image() );
		$operations[] = $this->op( 'POST', '/integrations/generate-image', array( 'spend_budget' => 1000000, 'weight' => 0, '_meta' => array( 'spend_budget' => 1000000 ) ) );

		$refusal = $this->batch_refusal( $operations, array( 'spend_budget' => 1000000, 'budget' => 1000000, '_meta' => array( 'spend_budget' => 1000000 ) ) )->get_error_data();

		$this->assertSame( 1000, $refusal['limit'] );
		$this->assertSame( 1025, $refusal['weight'], 'The forged "weight: 0" is not a price.' );

		$mcp = $this->mcp( $this->tool( 'wp_list_posts', array(), 1, array( 'spend_budget' => array( 'limit' => 1000000, 'spent' => 0 ) ) ) );
		$this->assertSame( 1000, $mcp['result']['_meta']['spend_budget']['limit'] );
	}

	// ---------------------------------------------------------------
	// One budget for the whole tree.
	// ---------------------------------------------------------------

	public function test_the_whole_fan_out_tree_of_one_request_shares_one_budget(): void {
		$calls = array();
		foreach ( array( 1, 2, 3 ) as $id ) {
			$calls[] = $this->tool( 'wp_batch', array( 'operations' => $this->times( 10, $this->image() ) ), $id );
		}

		$responses = $this->mcp( $calls );

		$this->assertCount( 3, $responses );
		foreach ( $responses as $response ) {
			$this->assertSame( 10, $this->tool_body( $response )['succeeded'] );
		}
		$third = $this->tool_body( $responses[2] );
		$this->assertSame( 750, $third['spend_budget']['spent'], 'The third nested batch drew on the same budget as the first two.' );
		$this->assertSame( 250, $responses[2]['result']['_meta']['spend_budget']['remaining'] );
		$this->assertCount( 30, $this->operation_dispatches() );

		// Three batches that do not fit together are refused together, before any runs.
		Mcpwp_REST_MCP::reset_verdict_memo();
		$over = array();
		foreach ( array( 1, 2, 3 ) as $id ) {
			$over[] = $this->tool( 'wp_batch', array( 'operations' => $this->times( 14, $this->image() ) ), $id );
		}
		$refused = $this->mcp( $over );
		$this->assertCount( 3, $refused );
		foreach ( $refused as $response ) {
			$this->assertSame( -32030, $response['error']['code'] );
			$this->assertSame( 'spend_budget_exceeded', $response['error']['data']['code'] );
			$this->assertSame( 1050, $response['error']['data']['weight'] );
		}
		$this->assertCount( 30, $this->operation_dispatches(), 'Nothing more ran.' );
	}

	public function test_a_nested_batch_joins_the_budget_it_finds_instead_of_opening_a_fresh_one(): void {
		$this->budget( 60 );
		$url = array( 'url' => 'https://example.com/a.jpg' );

		$responses = $this->mcp(
			array(
				$this->tool( 'wp_upload_media_from_url', $url, 1 ),
				$this->tool( 'wp_upload_media_from_url', $url, 2 ),
				$this->tool( 'wp_batch', array( 'operations' => array( $this->op( 'GET', '/posts/1' ), $this->op( 'GET', '/posts/2' ) ) ), 3 ),
			)
		);
		$inner = $this->tool_body( $responses[2] );
		$this->assertSame( 60, $inner['spend_budget']['limit'] );
		$this->assertSame( 52, $inner['spend_budget']['spent'], 'The nested batch saw the 50 units already spent, not a fresh 60.' );

		// And refuses on what is left of the shared budget, not of a fresh one.
		Mcpwp_REST_MCP::reset_verdict_memo();
		$this->route_alias_to_paid_handler();
		$responses = $this->mcp(
			array(
				$this->tool( 'wp_upload_media_from_url', $url, 1 ),
				$this->tool( 'wp_upload_media_from_url', $url, 2 ),
				$this->tool( 'wp_batch', array( 'operations' => array( $this->alias(), $this->op( 'GET', '/posts/1' ) ) ), 3 ),
			)
		);
		$this->assertSame( array( 413, 413 ), $this->statuses( $this->tool_body( $responses[2] ) ), '10 units were left when the nested batch began.' );
	}

	public function test_the_batch_container_itself_is_free_so_nothing_is_charged_twice(): void {
		$response = $this->mcp( $this->tool( 'wp_batch', array( 'operations' => $this->times( 3, $this->op( 'GET', '/posts/1' ) ) ) ) );

		$this->assertSame( 3, $response['result']['_meta']['spend_budget']['spent'] );
		$this->assertSame( 3, $this->tool_body( $response )['spend_budget']['spent'] );
	}

	// ---------------------------------------------------------------
	// JSON-RPC batches in /mcp.
	// ---------------------------------------------------------------

	public function test_a_json_rpc_batch_priced_over_the_budget_is_refused_whole(): void {
		$this->budget( 60 );
		$url = array( 'url' => 'https://example.com/a.jpg' );

		$responses = $this->mcp(
			array(
				$this->tool( 'wp_upload_media_from_url', $url, 1 ),
				$this->tool( 'wp_upload_media_from_url', $url, 2 ),
				$this->tool( 'wp_upload_media_from_url', $url, 3 ),
				$this->tool( 'wp_list_posts', array(), 4 ),
				array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'ping' ),
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ),
			)
		);

		$this->assertCount( 5, $responses, 'Every request is answered; the notification is not.' );
		foreach ( $responses as $position => $response ) {
			$this->assertSame( $position + 1, $response['id'] );
			$this->assertSame( -32030, $response['error']['code'] );
			$this->assertSame( 'spend_budget_exceeded', $response['error']['data']['code'] );
			$this->assertSame( 413, $response['error']['data']['status'] );
			$this->assertSame( 76, $response['error']['data']['weight'] );
			$this->assertSame( 60, $response['error']['data']['remaining'] );
			$this->assertSame( 60, $response['error']['data']['limit'] );
		}
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], 'Nothing ran.' );
	}

	public function test_an_operation_priced_only_at_dispatch_is_refused_on_its_own_and_the_batch_continues(): void {
		$this->budget( 60 );
		$this->route_alias_to_paid_handler();

		$responses = $this->mcp(
			array(
				$this->tool( 'wp_batch', array( 'operations' => $this->times( 3, $this->alias() ) ), 1 ),
				$this->tool( 'wp_list_posts', array(), 2 ),
				array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping' ),
			)
		);

		$this->assertCount( 3, $responses );
		$this->assertSame( array( 200, 200, 413 ), $this->statuses( $this->tool_body( $responses[0] ) ) );
		// wp_list_posts resolved to a real tool; the budget ran out AT DISPATCH
		// (not at admission), so this is an isError result, not a protocol error.
		$this->assertArrayNotHasKey( 'error', $responses[1] );
		$this->assertTrue( $responses[1]['result']['isError'] );
		$this->assertSame( 'spend_budget_exhausted', $responses[1]['result']['structuredContent']['error']['code'] );
		$this->assertSame( 10, $responses[1]['result']['structuredContent']['error']['remaining'] );
		$this->assertSame( 60, $responses[1]['result']['structuredContent']['error']['limit'] );
		$this->assertArrayHasKey( 'result', $responses[2], 'A method that dispatches nothing still answers.' );
		$this->assertCount( 2, $this->operation_dispatches() );
	}

	public function test_every_mcp_result_reports_the_budget(): void {
		$response = $this->mcp( $this->tool( 'wp_list_posts', array() ) );

		$this->assertSame( array( 'limit' => 1000, 'spent' => 1, 'remaining' => 999, 'exhausted' => false ), $response['result']['_meta']['spend_budget'] );
	}

	public function test_methods_that_dispatch_nothing_are_not_charged(): void {
		$responses = $this->mcp( array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ), array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ) ) );

		$this->assertSame( 0, $responses[1]['result']['_meta']['spend_budget']['spent'] );
	}

	public function test_an_over_budget_wp_batch_runs_nothing_and_neither_does_its_resend(): void {
		$this->budget( 30 );
		$call = $this->tool( 'wp_batch', array( 'operations' => array( $this->image(), $this->image() ) ), 1, array( 'request_id' => 'job:spend' ) );

		foreach ( array( 'first', 'resend' ) as $attempt ) {
			Mcpwp_REST_MCP::reset_verdict_memo();
			$response = $this->mcp( $call );
			$this->assertArrayNotHasKey( 'error', $response, $attempt );
			$this->assertTrue( $response['result']['isError'], $attempt );
			$this->assertSame( 'spend_budget_exceeded', $response['result']['structuredContent']['error']['code'], $attempt );
			$this->assertSame( 50, $response['result']['structuredContent']['error']['weight'], $attempt );
			$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'], $attempt . ': no generation ran.' );
		}
	}

	public function test_a_batch_refused_only_by_the_budget_is_pinned_so_a_resend_spends_nothing(): void {
		$this->budget( 60 );
		$this->route_alias_to_paid_handler();
		$call = $this->tool( 'wp_batch', array( 'operations' => $this->times( 3, $this->alias() ) ), 1, array( 'request_id' => 'job:alias' ) );

		$first = $this->mcp( $call );
		$body  = $this->tool_body( $first );
		$this->assertSame( 2, $body['succeeded'] );
		$this->assertSame( array( 2 ), $body['spend_budget']['refused'] );
		$this->assertSame( 'stored', $first['result']['_meta']['idempotency'], 'The two that ran are done; the one refused did not run.' );
		$this->assertStringContainsString( 'new request_id', $first['result']['_meta']['idempotency_reason'] );
		$this->assertCount( 2, $this->operation_dispatches() );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$resend = $this->mcp( $call );
		$this->assertTrue( $resend['result']['_meta']['replayed'] );
		$this->assertSame( array( 2 ), $this->tool_body( $resend )['spend_budget']['refused'], 'The replay still says which operation did not run.' );
		$this->assertSame( 'stored', $resend['result']['_meta']['idempotency'], 'A client that lost the first answer learns it from the replay.' );
		$this->assertStringContainsString( 'new request_id', $resend['result']['_meta']['idempotency_reason'] );
		$this->assertCount( 2, $this->operation_dispatches(), 'The resend paid for nothing.' );
	}

	public function test_a_batch_with_any_other_failure_beside_budget_refusals_is_not_pinned(): void {
		$this->budget( 60 );
		$this->route_alias_to_paid_handler();
		$response = $this->mcp( $this->tool( 'wp_batch', array( 'operations' => array( $this->alias(), $this->op( 'POST', '/mcp' ), $this->alias(), $this->alias() ) ), 1, array( 'request_id' => 'job:mixed' ) ) );

		$this->assertSame( 'not_stored', $response['result']['_meta']['idempotency'], 'The forbidden operation must stay retryable.' );
	}

	public function test_a_whole_refusal_prices_a_replay_as_free(): void {
		$first = $this->mcp( $this->tool( 'wp_batch', array( 'operations' => $this->times( 30, $this->image() ) ), 1, array( 'request_id' => 'job:r1' ) ) );
		$this->assertSame( 'stored', $first['result']['_meta']['idempotency'] );

		// 750 already spent in an earlier request and pinned; resent beside 500 new units.
		Mcpwp_REST_MCP::reset_verdict_memo();
		$responses = $this->mcp(
			array(
				$this->tool( 'wp_batch', array( 'operations' => $this->times( 30, $this->image() ) ), 1, array( 'request_id' => 'job:r1' ) ),
				$this->tool( 'wp_batch', array( 'operations' => $this->times( 20, $this->image() ) ), 2, array( 'request_id' => 'job:r2' ) ),
			)
		);

		$this->assertTrue( $responses[0]['result']['_meta']['replayed'] );
		$this->assertSame( 20, $this->tool_body( $responses[1] )['succeeded'], 'A replay dispatches nothing, so it does not count against the whole call.' );
		$this->assertCount( 50, $this->operation_dispatches() );
	}

	// ---------------------------------------------------------------
	// No leak across requests.
	// ---------------------------------------------------------------

	public function test_a_spent_budget_does_not_outlive_its_request(): void {
		$first = $this->batch( $this->times( 40, $this->image() ) );
		$this->assertSame( 0, $first['spend_budget']['remaining'] );

		$second = $this->batch( array( $this->image() ) );
		$this->assertSame( array( 200 ), $this->statuses( $second ) );
		$this->assertSame( 25, $second['spend_budget']['spent'] );
		$this->assertFalse( $second['spend_budget']['exhausted'] );

		$this->mcp( array( $this->tool( 'wp_batch', array( 'operations' => $this->times( 40, $this->image() ) ) ) ) );
		$third = $this->batch( array( $this->image() ) );
		$this->assertSame( 25, $third['spend_budget']['spent'], 'Nor does one opened by /mcp.' );
	}

	public function test_a_throw_mid_batch_does_not_leave_its_budget_behind(): void {
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request ) {
				if ( '/mcpwp/v1/boom' === $request->get_route() ) {
					throw new RuntimeException( 'handler blew up' );
				}
				return $result;
			},
			5,
			2
		);

		try {
			$this->batch( array( $this->image(), $this->op( 'GET', '/boom' ) ) );
			$this->fail( 'Test premise: the second operation throws.' );
		} catch ( RuntimeException $expected ) {
			$this->assertSame( 'handler blew up', $expected->getMessage() );
		}

		$after = $this->batch( array( $this->image() ) );
		$this->assertSame( 25, $after['spend_budget']['spent'], 'The thrown request\'s 25 units are not carried into the next request.' );
		$this->assertSame( 1000, $after['spend_budget']['limit'] );
	}

	// ---------------------------------------------------------------
	// Review r1: the dispatch contract, priced like core runs it.
	// ---------------------------------------------------------------

	public function test_the_requested_route_is_priced_when_core_matched_a_generic_pattern(): void {
		$GLOBALS['mcpwp_test_route_patterns']['#^/mcpwp/v1/integrations/generate-image$#i'] = '/mcpwp/v1/integrations/(?P<action>[a-z-]+)';

		$this->assertSame( 25, $this->spent( array( $this->image() ) ), 'The pattern alone prices as an ordinary operation; the route the caller asked for is the paid one.' );
	}

	public function test_the_matched_pattern_is_priced_when_the_requested_path_looks_ordinary(): void {
		$this->route_alias_to_paid_handler();

		$this->assertSame( 25, $this->spent( array( $this->alias() ) ) );
	}

	public function test_a_dispatch_another_filter_already_answered_is_not_charged(): void {
		add_filter(
			'rest_dispatch_request',
			function ( $result, $request ) {
				return '/mcpwp/v1/cached/1' === $request->get_route() ? new WP_REST_Response( array( 'cached' => true ), 200 ) : $result;
			},
			100,
			2
		);

		$data = $this->batch( array( $this->op( 'GET', '/cached/1' ), $this->image() ) );

		$this->assertSame( array( 200, 200 ), $this->statuses( $data ) );
		$this->assertSame( 25, $data['spend_budget']['spent'], 'The charge runs last: a handler another filter answered never ran and is not charged.' );
	}

	public function test_an_operation_the_key_may_not_run_is_not_charged(): void {
		$GLOBALS['mcpwp_test_route_permission_denied'][] = '#^/mcpwp/v1/integrations/generate-image$#i';

		$data = $this->batch( array( $this->image(), $this->op( 'GET', '/posts/1' ) ) );

		$this->assertSame( array( 403, 200 ), $this->statuses( $data ) );
		$this->assertSame( 1, $data['spend_budget']['spent'], 'Core refused it before rest_dispatch_request, where the charge lives.' );
		$this->assertSame( array(), $data['spend_budget']['refused'] );
	}

	public function test_a_call_after_the_budget_refused_is_turned_away_before_rate_slots_are_charged(): void {
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'enabled' => true, 'requests_per_minute' => 100, 'requests_per_hour' => 1000, 'burst_limit' => 100 ) );
		$this->budget( 60 );
		$this->route_alias_to_paid_handler();

		$responses = $this->mcp(
			array(
				$this->tool( 'wp_batch', array( 'operations' => $this->times( 3, $this->alias() ) ), 1 ),
				$this->tool( 'wp_bulk_create_posts', array( 'posts' => array_fill( 0, 5, array( 'title' => 'x' ) ) ), 2 ),
			)
		);

		$this->assertSame( array( 200, 200, 413 ), $this->statuses( $this->tool_body( $responses[0] ) ) );
		$this->assertSame( 'spend_budget_exhausted', $responses[1]['result']['structuredContent']['error']['code'] );
		$this->assertSame( 96, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], '2 for the HTTP request, 2 more for the 3-operation batch — and none for the 5 posts that were never going to run.' );
	}

	// ---------------------------------------------------------------
	// Review r1: P2 / P3 fixes.
	// ---------------------------------------------------------------

	public function test_an_operation_answering_with_object_data_does_not_break_the_batch(): void {
		$GLOBALS['mcpwp_test_rest_response_override'] = (object) array( 'a' => 1 );

		$data = $this->batch( array( $this->op( 'GET', '/posts/1' ), $this->op( 'GET', '/posts/2' ) ) );

		$this->assertSame( 2, $data['succeeded'], 'Every operation already ran; the report must not fatal after the fact.' );
		$this->assertEquals( (object) array( 'a' => 1 ), $data['results'][0]['data'] );
		$this->assertSame( array(), $data['spend_budget']['refused'] );
	}

	public function test_google_indexing_submit_costs_twenty_five_per_url(): void {
		$urls = function ( $n ) {
			return array_map( function ( $i ) { return 'https://example.com/p' . $i; }, range( 1, $n ) );
		};

		$this->assertSame( 75, $this->spent( array( $this->op( 'POST', '/google-indexing/submit', array( 'urls' => $urls( 3 ) ) ) ) ) );
		$this->assertSame( 1000, $this->spent( array( $this->op( 'POST', '/google-indexing/submit', array( 'urls' => $urls( 40 ) ) ) ) ) );
		$this->assertSame( 25, $this->spent( array( $this->op( 'POST', '/google-indexing/submit' ) ) ) );
		$this->assertSame( 'spend_budget_exceeded', $this->batch_refusal( array( $this->op( 'POST', '/google-indexing/submit', array( 'urls' => $urls( 41 ) ) ) ) )->get_error_code() );
	}

	public function test_a_paid_route_given_a_long_list_costs_at_least_the_list(): void {
		$this->assertSame( 30, $this->spent( array( $this->op( 'POST', '/cloudflare/purge-cache', array( 'files' => array_fill( 0, 30, 'https://example.com/x' ) ) ) ) ) );
		$this->assertSame( 25, $this->spent( array( $this->op( 'POST', '/cloudflare/purge-cache', array( 'files' => array_fill( 0, 3, 'https://example.com/x' ) ) ) ) ) );
	}

	// ---------------------------------------------------------------
	// Review r2.
	// ---------------------------------------------------------------

	public function test_a_wp_batch_over_the_cap_inside_a_json_rpc_batch_reaches_its_own_refusal(): void {
		// It runs nothing, so it costs nothing: pricing it would refuse the
		// calls beside it that fit.
		$responses = $this->mcp(
			array(
				$this->tool( 'wp_batch', array( 'operations' => $this->times( 101, $this->image() ) ), 1 ),
				$this->tool( 'wp_list_posts', array(), 2 ),
			)
		);

		$this->assertArrayNotHasKey( 'error', $responses[0] );
		$this->assertTrue( $responses[0]['result']['isError'] );
		$this->assertSame( 'batch_too_large', $responses[0]['result']['structuredContent']['error']['code'] );
		$this->assertArrayHasKey( 'result', $responses[1], 'The call that fits runs.' );
		$this->assertSame( array( '/mcpwp/v1/posts' ), $GLOBALS['mcpwp_test_dispatched_routes'] );
	}

	public function test_pricing_a_batch_over_the_cap_does_no_per_operation_work(): void {
		$priced = 0;
		add_filter(
			'mcpwp_spend_weight',
			function ( $weight ) use ( &$priced ) {
				++$priced;
				return $weight;
			}
		);

		$this->assertSame( 0, Mcpwp_REST_Batch::price( array_fill( 0, 100001, $this->image() ) ) );
		$this->assertSame( 0, Mcpwp_REST_Batch::price( array() ) );
		$this->assertSame( 0, $priced, 'A payload the controller refuses whole is not priced operation by operation.' );
		$this->assertSame( 2500, Mcpwp_REST_Batch::price( $this->times( 100, $this->image() ) ) );
	}

	public function test_a_success_body_that_echoes_the_refusal_code_is_not_a_refusal(): void {
		// Only the budget's own refusal — status 413 with its code — did not
		// run. A 200 that happens to carry that code ran, and pinning beside a
		// real failure would turn the failure into a silent drop.
		$GLOBALS['mcpwp_test_route_permission_denied'][] = '#^/mcpwp/v1/posts/1$#';
		$GLOBALS['mcpwp_test_rest_response_override']    = array( 'code' => 'spend_budget_exhausted', 'value' => 'stored by a user' );

		$response = $this->mcp( $this->tool( 'wp_batch', array( 'operations' => array( $this->op( 'GET', '/posts/1' ), $this->op( 'GET', '/memory/x' ) ) ), 1, array( 'request_id' => 'job:echo' ) ) );
		$body     = $this->tool_body( $response );

		$this->assertSame( array( 403, 200 ), $this->statuses( $body ) );
		$this->assertSame( 1, $body['failed'] );
		$this->assertSame( array(), $body['spend_budget']['refused'] );
		$this->assertSame( 'not_stored', $response['result']['_meta']['idempotency'] );
	}

	public function test_a_tool_call_that_cannot_fit_is_refused_before_its_extra_rate_slots_are_charged(): void {
		Mcpwp_Rate_Limiter::get_instance()->update_settings( array( 'enabled' => true, 'requests_per_minute' => 1000, 'requests_per_hour' => 5000, 'burst_limit' => 1000 ) );

		$batch = $this->mcp( $this->tool( 'wp_batch', array( 'operations' => $this->times( 50, $this->image() ) ) ) );
		$this->assertArrayNotHasKey( 'error', $batch );
		$this->assertTrue( $batch['result']['isError'] );
		$this->assertSame( 'spend_budget_exceeded', $batch['result']['structuredContent']['error']['code'] );
		$this->assertSame( 999, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], 'The HTTP request\'s own slot, and none of the 49 extra.' );

		Mcpwp_REST_MCP::reset_verdict_memo();
		$urls   = array_map( function ( $i ) { return 'https://example.com/' . $i . '.jpg'; }, range( 1, 41 ) );
		$upload = $this->mcp( $this->tool( 'wp_bulk_upload_media', array( 'urls' => $urls ), 2 ) );
		$this->assertArrayNotHasKey( 'error', $upload );
		$this->assertTrue( $upload['result']['isError'] );
		$this->assertSame( 'spend_budget_exceeded', $upload['result']['structuredContent']['error']['code'], 'Refused whole as sent, not at dispatch.' );
		$this->assertSame( 1025, $upload['result']['structuredContent']['error']['weight'] );
		$this->assertSame( 998, Mcpwp_Rate_Limiter::get_instance()->get_budget()['remaining'], 'Again only the HTTP slot, not 40 more for the URLs.' );
		$this->assertSame( array(), $GLOBALS['mcpwp_test_dispatched_routes'] );
	}
}
