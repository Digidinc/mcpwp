<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-integration-manager.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-provider-cloudflare.php';

/**
 * Cloudflare integration — request shaping, idempotent rule upserts, error handling.
 */
final class CloudflareProviderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']   = array();
        $GLOBALS['mcpwp_test_filters']   = array();
        $GLOBALS['mcpwp_test_actions']   = array();

        $ref      = new ReflectionClass(Mcpwp_Encryption::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    /**
     * mockHttp() leaves a pre_http_request closure registered, and setUp alone
     * does not undo it — nothing clears it after this class's last test. The
     * next class to make any HTTP call then hit the Cloudflare mock, which reads
     * $args['body'] unconditionally and raised "Undefined array key body" from a
     * file unrelated to the failing test. #757 again: state cleaned on the way
     * IN is not cleaned after the last test that set it.
     */
    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_filters'] = array();
    }

    private function configure(): void
    {
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('cloudflare', array(
            'api_token' => 'test-token',
            'zone_id'   => 'zone-123',
        ));
    }

    /**
     * Registers a pre_http_request mock and returns the (shared, mutable) captured-requests store.
     *
     * add_filter() only registers the callback — nothing has been captured yet
     * at return time, so this must hand back a reference-like container
     * (ArrayObject), not a plain array, or the caller would only ever see an
     * empty snapshot taken before any request fired.
     *
     * @param callable $resultFor function(string $url, array $args): array Cloudflare "result" payload.
     * @return ArrayObject<int, array{url:string, method:string, body:?string}>
     */
    private function mockHttp(callable $resultFor): ArrayObject
    {
        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured, $resultFor) {
            $captured[] = array( 'url' => $url, 'method' => $args['method'], 'body' => $args['body'] ?? null );
            $result = $resultFor($url, $args);
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode(array( 'success' => true, 'errors' => array(), 'result' => $result )),
            );
        }, 10, 3);

        return $captured;
    }

    public function test_not_configured_returns_wp_error(): void
    {
        $cf     = new Mcpwp_Provider_Cloudflare();
        $result = $cf->get_zone_status();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('cloudflare_not_configured', $result->get_error_code());
    }

    public function test_get_zone_status_sends_bearer_auth_and_returns_result(): void
    {
        $this->configure();
        add_filter('pre_http_request', function ($preempt, $args, $url) {
            $this->assertStringEndsWith('/zones/zone-123', $url);
            $this->assertSame('Bearer test-token', $args['headers']['Authorization']);
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode(array( 'success' => true, 'errors' => array(), 'result' => array( 'name' => 'example.com', 'status' => 'active' ) )),
            );
        }, 10, 3);

        $cf     = new Mcpwp_Provider_Cloudflare();
        $result = $cf->get_zone_status();

        $this->assertSame('example.com', $result['name']);
        $this->assertSame('active', $result['status']);
    }

    public function test_api_error_envelope_becomes_wp_error_with_message(): void
    {
        $this->configure();
        add_filter('pre_http_request', function () {
            return array(
                'response' => array( 'code' => 400 ),
                'body'     => wp_json_encode(array(
                    'success' => false,
                    'errors'  => array( array( 'message' => 'Invalid zone identifier' ) ),
                )),
            );
        }, 10, 3);

        $cf     = new Mcpwp_Provider_Cloudflare();
        $result = $cf->get_zone_status();

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('cloudflare_api_error', $result->get_error_code());
        $this->assertStringContainsString('Invalid zone identifier', $result->get_error_message());
    }

    public function test_fix_mcp_access_creates_then_updates_idempotently(): void
    {
        $this->configure();

        $state = array( 'config_rules' => array(), 'waf_rules' => array() );
        add_filter('pre_http_request', function ($preempt, $args, $url) use (&$state) {
            $result = array();
            if (false !== strpos($url, '/rulesets/phases/http_config_settings/entrypoint')) {
                $result = array( 'id' => 'ruleset-config', 'rules' => $state['config_rules'] );
            } elseif (false !== strpos($url, '/rulesets/phases/http_request_firewall_custom/entrypoint')) {
                $result = array( 'id' => 'ruleset-waf', 'rules' => $state['waf_rules'] );
            } elseif (false !== strpos($url, '/rulesets/ruleset-config')) {
                $state['config_rules'] = json_decode($args['body'], true)['rules'];
                $result                = array( 'id' => 'ruleset-config', 'rules' => $state['config_rules'] );
            } elseif (false !== strpos($url, '/rulesets/ruleset-waf')) {
                $state['waf_rules'] = json_decode($args['body'], true)['rules'];
                $result             = array( 'id' => 'ruleset-waf', 'rules' => $state['waf_rules'] );
            }
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode(array( 'success' => true, 'errors' => array(), 'result' => $result )),
            );
        }, 10, 3);

        $cf = new Mcpwp_Provider_Cloudflare();

        $first = $cf->fix_mcp_access();
        $this->assertIsArray($first);
        $this->assertStringContainsString('Created', $first['summary'][0]);
        $this->assertStringContainsString('Created', $first['summary'][1]);

        $second = $cf->fix_mcp_access();
        $this->assertStringContainsString('Updated existing', $second['summary'][0]);
        $this->assertStringContainsString('Updated existing', $second['summary'][1]);

        // No duplicate rules were appended on the second call.
        $this->assertCount(1, $state['config_rules']);
        $this->assertCount(1, $state['waf_rules']);
        $this->assertSame('essentially_off', $state['config_rules'][0]['action_parameters']['security_level']);
    }

    public function test_purge_cache_sends_files_payload(): void
    {
        $this->configure();
        $captured = $this->mockHttp(fn () => array( 'id' => 'zone-123' ));

        $cf     = new Mcpwp_Provider_Cloudflare();
        $result = $cf->purge_cache(array( 'https://example.com/a/', 'https://example.com/b/' ));

        $this->assertIsArray($result);
        $this->assertCount(1, $captured);
        $body = json_decode($captured[0]['body'], true);
        $this->assertSame(array( 'https://example.com/a/', 'https://example.com/b/' ), $body['files']);
        $this->assertArrayNotHasKey('purge_everything', $body);
    }

    public function test_purge_cache_purge_everything_payload(): void
    {
        $this->configure();
        $captured = $this->mockHttp(fn () => array( 'id' => 'zone-123' ));

        $cf = new Mcpwp_Provider_Cloudflare();
        $cf->purge_cache(array(), true);

        $body = json_decode($captured[0]['body'], true);
        $this->assertTrue($body['purge_everything']);
        $this->assertArrayNotHasKey('files', $body);
    }

    public function test_purge_cache_without_urls_or_flag_errors_without_a_network_call(): void
    {
        $this->configure();
        $captured = $this->mockHttp(fn () => array( 'id' => 'zone-123' ));

        $cf     = new Mcpwp_Provider_Cloudflare();
        $result = $cf->purge_cache(array());

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('cloudflare_purge_no_urls', $result->get_error_code());
        $this->assertCount(0, $captured);
    }

    public function test_purge_cache_caps_urls_at_thirty(): void
    {
        $this->configure();
        $captured = $this->mockHttp(fn () => array( 'id' => 'zone-123' ));

        $urls = array_map(fn ($i) => "https://example.com/$i/", range(1, 40));

        $cf = new Mcpwp_Provider_Cloudflare();
        $cf->purge_cache($urls);

        $body = json_decode($captured[0]['body'], true);
        $this->assertCount(30, $body['files']);
    }

    public function test_get_speed_settings_reads_all_four(): void
    {
        $this->configure();
        $this->mockHttp(function ($url) {
            preg_match('#/settings/([a-z0-9_]+)$#', $url, $m);
            return array( 'id' => $m[1], 'value' => 'early_hints' === $m[1] ? 'on' : 'off' );
        });

        $cf       = new Mcpwp_Provider_Cloudflare();
        $settings = $cf->get_speed_settings();

        $this->assertSame('on', $settings['early_hints']);
        $this->assertSame('off', $settings['http3']);
        $this->assertSame('off', $settings['rocket_loader']);
        $this->assertSame('off', $settings['polish']);
    }

    public function test_update_speed_setting_rejects_unknown_setting_name(): void
    {
        $this->configure();
        $captured = $this->mockHttp(fn () => array());

        $cf     = new Mcpwp_Provider_Cloudflare();
        $result = $cf->update_speed_setting('mirage', 'on');

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('cloudflare_invalid_setting', $result->get_error_code());
        $this->assertCount(0, $captured, 'Invalid setting must not reach the network.');
    }

    public function test_update_speed_setting_sends_patch_with_value(): void
    {
        $this->configure();
        $captured = $this->mockHttp(fn ($url, $args) => array( 'id' => 'rocket_loader', 'value' => json_decode($args['body'], true)['value'] ));

        $cf     = new Mcpwp_Provider_Cloudflare();
        $result = $cf->update_speed_setting('rocket_loader', 'on');

        $this->assertSame('PATCH', $captured[0]['method']);
        $this->assertSame('on', $result['value']);
    }
}
