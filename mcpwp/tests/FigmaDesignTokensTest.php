<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-integration-manager.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-figma.php';

/**
 * Figma design-tokens sync — styles fetch, batched node resolve, color/typography
 * extraction, and the cached-option read path.
 */
final class FigmaDesignTokensTest extends TestCase
{
    /**
     * Leaves no pre_http_request mock behind for the next class. See #757 —
     * cleaning up only in setUp leaves the last test's mock live for whoever
     * runs next, which under --order-by=random is a different file each run.
     */
    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_filters'] = array();
        $GLOBALS['mcpwp_test_fs']      = null;
        $this->resetLicense();
    }

    /**
     * Design-token sync is Pro (#564): every Figma call refuses without an
     * entitlement, so this file states the Pro precondition instead of
     * relying on whatever tier the previous test left behind.
     */
    private function resetLicense(): void
    {
        $ref = new ReflectionProperty(Mcpwp_License::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']    = array();
        $GLOBALS['mcpwp_test_filters']    = array();
        $GLOBALS['mcpwp_test_actions']    = array();
        $GLOBALS['mcpwp_test_transients'] = array();
        $GLOBALS['mcpwp_test_fs']         = new Mcpwp_Test_Fs_Stub(true, true, false, 'pro');
        $this->resetLicense();

        $ref      = new ReflectionClass(Mcpwp_Encryption::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    private function configure(array $overrides = array()): void
    {
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('figma', array_merge(array(
            'personal_access_token' => 'test-token',
            'default_file_key'      => 'file-abc',
        ), $overrides));
    }

    /**
     * Registers a pre_http_request mock that branches on URL: any URL
     * containing "/nodes" gets $nodesBody, everything else (the /styles
     * fetch) gets $stylesBody. Returns the (mutable) captured-requests store,
     * same ArrayObject pattern used in CloudflareProviderTest.
     *
     * @return ArrayObject<int, array{url:string, method:string}>
     */
    private function mockHttp(array $stylesBody, array $nodesBody): ArrayObject
    {
        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured, $stylesBody, $nodesBody) {
            $captured[] = array( 'url' => $url, 'method' => $args['method'] );

            $body = false !== strpos($url, '/nodes') ? $nodesBody : $stylesBody;

            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode($body),
            );
        }, 10, 3);

        return $captured;
    }

    // ── Failure paths ─────────────────────────────────────────────

    public function test_sync_not_configured_returns_wp_error(): void
    {
        $figma  = new Mcpwp_Figma();
        $result = $figma->sync_design_tokens();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('figma_not_configured', $result->get_error_code());
    }

    public function test_sync_missing_file_key_returns_wp_error(): void
    {
        $this->configure(array( 'default_file_key' => '' ));

        $figma  = new Mcpwp_Figma();
        $result = $figma->sync_design_tokens();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('missing_file_key', $result->get_error_code());
    }

    public function test_sync_api_error_on_styles_fetch_returns_wp_error(): void
    {
        $this->configure();
        add_filter('pre_http_request', function () {
            return array(
                'response' => array( 'code' => 500 ),
                'body'     => wp_json_encode(array( 'err' => 'Internal Server Error' )),
            );
        }, 10, 3);

        $figma  = new Mcpwp_Figma();
        $result = $figma->sync_design_tokens();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('figma_request_failed', $result->get_error_code());
    }

    // ── Empty-styles path (valid, not an error) ───────────────────

    public function test_sync_empty_styles_returns_valid_empty_shape_not_error(): void
    {
        $this->configure();
        $this->mockHttp(array( 'meta' => array( 'styles' => array() ) ), array());

        $figma  = new Mcpwp_Figma();
        $result = $figma->sync_design_tokens();

        $this->assertIsArray($result);
        $this->assertSame('file-abc', $result['file_key']);
        $this->assertSame(array(), $result['colors']);
        $this->assertSame(array(), $result['typography']);
        $this->assertIsInt($result['synced_at']);

        // Cached read must match what was just synced.
        $this->assertSame($result, $figma->get_cached_design_tokens());
    }

    public function test_sync_ignores_effect_and_grid_styles_only_returns_empty(): void
    {
        $this->configure();
        $this->mockHttp(
            array(
                'meta' => array(
                    'styles' => array(
                        array( 'node_id' => '5:5', 'style_type' => 'EFFECT', 'name' => 'Shadow/Card' ),
                        array( 'node_id' => '6:6', 'style_type' => 'GRID', 'name' => 'Layout/12col' ),
                    ),
                ),
            ),
            array()
        );

        $figma  = new Mcpwp_Figma();
        $result = $figma->sync_design_tokens();

        $this->assertIsArray($result);
        $this->assertSame(array(), $result['colors']);
        $this->assertSame(array(), $result['typography']);
    }

    // ── Extraction: FILL (opaque hex) + TEXT, batched into one /nodes call ──

    public function test_sync_extracts_fill_and_text_styles_via_single_batched_nodes_call(): void
    {
        $this->configure();

        $stylesBody = array(
            'meta' => array(
                'styles' => array(
                    array( 'node_id' => '1:1', 'style_type' => 'FILL', 'name' => 'Brand/Primary' ),
                    array( 'node_id' => '2:2', 'style_type' => 'TEXT', 'name' => 'Heading/H1' ),
                    array( 'node_id' => '3:3', 'style_type' => 'EFFECT', 'name' => 'Shadow/Card' ),
                ),
            ),
        );

        $nodesBody = array(
            'nodes' => array(
                '1:1' => array(
                    'document' => array(
                        'fills' => array(
                            array( 'type' => 'SOLID', 'color' => array( 'r' => 0.184, 'g' => 0.486, 'b' => 1.0, 'a' => 1.0 ) ),
                        ),
                    ),
                ),
                '2:2' => array(
                    'document' => array(
                        'style' => array(
                            'fontFamily'   => 'Inter',
                            'fontWeight'   => 700,
                            'fontSize'     => 48,
                            'lineHeightPx' => 56,
                        ),
                    ),
                ),
            ),
        );

        $captured = $this->mockHttp($stylesBody, $nodesBody);

        $figma  = new Mcpwp_Figma();
        $result = $figma->sync_design_tokens();

        $this->assertIsArray($result);

        // FILL → hex color, hierarchical name slugified.
        $this->assertArrayHasKey('brand-primary', $result['colors']);
        $this->assertSame('#2F7CFF', $result['colors']['brand-primary']);

        // TEXT → typography record, matching the documented schema exactly.
        $this->assertArrayHasKey('heading-h1', $result['typography']);
        $this->assertSame(
            array(
                'fontFamily'   => 'Inter',
                'fontWeight'   => 700,
                'fontSize'     => 48,
                'lineHeightPx' => 56,
            ),
            $result['typography']['heading-h1']
        );

        // EFFECT style produced neither a color nor a typography entry.
        $this->assertCount(1, $result['colors']);
        $this->assertCount(1, $result['typography']);

        // Exactly one /styles request and exactly one /nodes request — the two
        // relevant node ids must be resolved in a single batched call, not
        // one request per style.
        $requests   = iterator_to_array($captured);
        $node_calls = array_values(array_filter($requests, fn ($c) => false !== strpos($c['url'], '/nodes')));
        $this->assertCount(1, $node_calls);
        $this->assertStringContainsString('ids=1:1,2:2', $node_calls[0]['url']);

        // Persisted to the option and readable via the cached-read path.
        $this->assertSame($result, $figma->get_cached_design_tokens());
    }

    // ── Alpha < 1 → rgba() ─────────────────────────────────────────

    public function test_sync_alpha_below_one_produces_rgba(): void
    {
        $this->configure();

        $stylesBody = array(
            'meta' => array(
                'styles' => array(
                    array( 'node_id' => '9:9', 'style_type' => 'FILL', 'name' => 'Overlay/Scrim' ),
                ),
            ),
        );
        $nodesBody = array(
            'nodes' => array(
                '9:9' => array(
                    'document' => array(
                        'fills' => array(
                            array( 'type' => 'SOLID', 'color' => array( 'r' => 1.0, 'g' => 0.0, 'b' => 0.0, 'a' => 0.5 ) ),
                        ),
                    ),
                ),
            ),
        );

        $this->mockHttp($stylesBody, $nodesBody);

        $figma  = new Mcpwp_Figma();
        $result = $figma->sync_design_tokens();

        $this->assertSame('rgba(255, 0, 0, 0.5)', $result['colors']['overlay-scrim']);
    }

    // ── Explicit file_key overrides default_file_key ────────────────

    public function test_sync_accepts_explicit_file_key_overriding_default(): void
    {
        $this->configure(array( 'default_file_key' => 'default-key' ));

        $captured = $this->mockHttp(array( 'meta' => array( 'styles' => array() ) ), array());

        $figma  = new Mcpwp_Figma();
        $result = $figma->sync_design_tokens('explicit-key');

        $this->assertSame('explicit-key', $result['file_key']);
        $requests = iterator_to_array($captured);
        $this->assertStringContainsString('/files/explicit-key/styles', $requests[0]['url']);
    }

    // ── Cached-read path ───────────────────────────────────────────

    public function test_get_cached_design_tokens_returns_empty_shape_when_never_synced(): void
    {
        $figma  = new Mcpwp_Figma();
        $cached = $figma->get_cached_design_tokens();

        $this->assertNull($cached['synced_at']);
        $this->assertSame('', $cached['file_key']);
        $this->assertSame(array(), $cached['colors']);
        $this->assertSame(array(), $cached['typography']);
    }
}
