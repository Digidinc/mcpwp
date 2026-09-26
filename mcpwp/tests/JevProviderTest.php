<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-integration-manager.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-jev.php';

/**
 * Jev (TypeSafe) provider registration + wire client.
 *
 * Jev is opt-in, bring-your-own-key, and off until an api_key is saved.
 * These tests cover the provider config surface and the request/response
 * shape of Mcpwp_Jev::evaluate() against a mocked pre_http_request — never
 * a real TypeSafe key, never a real network call.
 */
final class JevProviderTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options'] = array();
        $GLOBALS['mcpwp_test_filters'] = array();
        $GLOBALS['mcpwp_test_actions'] = array();

        $ref      = new ReflectionClass(Mcpwp_Encryption::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    protected function tearDown(): void
    {
        // mockHttp() leaves a pre_http_request closure registered — clear it
        // so the next test class's first HTTP call doesn't hit a stale mock
        // (same lesson as CloudflareProviderTest's tearDown, #757).
        $GLOBALS['mcpwp_test_filters'] = array();
    }

    private function configure(string $endpoint = ''): void
    {
        $config = array('api_key' => 'test-typesafe-key');
        if ('' !== $endpoint) {
            $config['endpoint'] = $endpoint;
        }
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('jev', $config);
    }

    /**
     * Registers a pre_http_request mock and returns the captured requests.
     *
     * @return ArrayObject<int, array{url:string, args:array}>
     */
    private function mockHttp(array $jevResponse, int $httpCode = 200): ArrayObject
    {
        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured, $jevResponse, $httpCode) {
            $captured[] = array('url' => $url, 'args' => $args);
            return array(
                'response' => array('code' => $httpCode),
                'body'     => wp_json_encode($jevResponse),
            );
        }, 10, 3);

        return $captured;
    }

    // -- Provider registration ------------------------------------------------

    public function test_jev_is_registered_as_a_free_multi_field_provider(): void
    {
        $this->assertArrayHasKey('jev', Mcpwp_Integration_Manager::PROVIDERS);
        $this->assertSame('free', Mcpwp_Integration_Manager::PROVIDERS['jev']['tier']);

        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->is_multi_field_provider('jev'));

        $fields = Mcpwp_Integration_Manager::PROVIDERS['jev']['fields'];
        $this->assertArrayHasKey('api_key', $fields);
        $this->assertArrayHasKey('endpoint', $fields);
    }

    public function test_is_configured_false_until_a_key_is_saved(): void
    {
        $this->assertFalse(Mcpwp_Jev::is_configured());

        $this->configure();

        $this->assertTrue(Mcpwp_Jev::is_configured());
    }

    public function test_for_site_returns_false_when_not_configured(): void
    {
        $this->assertFalse(Mcpwp_Jev::for_site());
    }

    public function test_for_site_uses_default_endpoint_when_none_overridden(): void
    {
        $this->configure();

        $captured = $this->mockHttp(array(
            'answers' => array('q' => array('type' => 'noul', 'noul' => 0.9)),
        ));

        $jev = Mcpwp_Jev::for_site();
        $this->assertInstanceOf(Mcpwp_Jev::class, $jev);

        $jev->evaluate('state', array('q' => array('type' => 'boolean', 'instructions' => 'ok?', 'criteria' => array('true' => 'a', 'false' => 'b'))));

        $this->assertCount(1, $captured);
        $this->assertSame(Mcpwp_Jev::DEFAULT_ENDPOINT, $captured[0]['url']);
    }

    // -- Wire shape -------------------------------------------------------------

    public function test_evaluate_sends_documented_request_shape(): void
    {
        $this->configure('https://jev-stub.test/v1/systemone');

        $captured = $this->mockHttp(array(
            'model'   => 'jev-latest',
            'answers' => array(
                'urgent' => array('type' => 'noul', 'noul' => 0.75),
            ),
            'usage'   => array('input_tokens' => 42),
        ));

        $jev    = Mcpwp_Jev::for_site();
        $result = $jev->evaluate(
            array('title' => 'test state'),
            array(
                'urgent' => array(
                    'type'         => 'boolean',
                    'instructions' => 'Is this urgent?',
                    'criteria'     => array('true' => 'yes', 'false' => 'no'),
                ),
            )
        );

        $this->assertCount(1, $captured);
        $request = $captured[0];
        $this->assertSame('https://jev-stub.test/v1/systemone', $request['url']);
        $this->assertSame('POST', $request['args']['method']);
        // wp_safe_remote_post(), not wp_remote_post(): an admin-supplied
        // endpoint override must be treated like any other outbound
        // integration's SSRF guard.
        $this->assertTrue($request['args']['reject_unsafe_urls']);
        $this->assertTrue($request['args']['sslverify']);
        $this->assertSame('Bearer test-typesafe-key', $request['args']['headers']['Authorization']);
        $this->assertSame('application/json', $request['args']['headers']['Content-Type']);
        $this->assertLessThanOrEqual(8, $request['args']['timeout']);

        $body = json_decode($request['args']['body'], true);
        $this->assertSame('jev-latest', $body['model']);
        $this->assertSame(array('title' => 'test state'), $body['state']);
        // Our 'boolean' spelling must be translated to TypeSafe's 'noul' on the wire.
        $this->assertSame('noul', $body['questions']['urgent']['type']);

        // 'noul' answer translated back to boolean/probability at our boundary.
        $this->assertIsArray($result);
        $this->assertSame('boolean', $result['answers']['urgent']['type']);
        $this->assertSame(0.75, $result['answers']['urgent']['probability']);
    }

    public function test_evaluate_accepts_string_or_array_state_untranslated(): void
    {
        $this->configure('https://jev-stub.test/v1/systemone');
        $captured = $this->mockHttp(array('answers' => array('q' => array('type' => 'choice', 'choice' => 'a'))));

        Mcpwp_Jev::for_site()->evaluate('a plain string state', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $body = json_decode($captured[0]['args']['body'], true);
        $this->assertSame('a plain string state', $body['state']);
    }

    public function test_evaluate_makes_exactly_one_request_no_retries(): void
    {
        $this->configure('https://jev-stub.test/v1/systemone');
        $captured = $this->mockHttp(array('answers' => array('q' => array('type' => 'choice', 'choice' => 'a'))));

        Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertCount(1, $captured);
    }

    public function test_evaluate_never_relaxes_sslverify_even_for_a_loopback_shaped_endpoint(): void
    {
        // HTTPS-only is enforced by scheme alone — a loopback-shaped host
        // must never buy a weaker TLS check. There is no WP_DEBUG or
        // loopback carve-out left in the client at all.
        $this->configure('https://127.0.0.1:9443/v1/systemone');
        $captured = $this->mockHttp(array('answers' => array('q' => array('type' => 'choice', 'choice' => 'a'))));

        Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertCount(1, $captured);
        $this->assertTrue($captured[0]['args']['sslverify']);
        $this->assertTrue($captured[0]['args']['reject_unsafe_urls']);
    }

    // -- Failure modes: never fatal, always WP_Error -----------------------------

    public function test_evaluate_without_configuration_returns_wp_error_and_makes_no_request(): void
    {
        $captured = $this->mockHttp(array());

        $jev    = new Mcpwp_Jev('');
        $result = $jev->evaluate('s', array('q' => array('type' => 'boolean', 'instructions' => 'x', 'criteria' => array('true' => 'a', 'false' => 'b'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jev_not_configured', $result->get_error_code());
        $this->assertCount(0, $captured);
    }

    public function test_evaluate_rejects_non_https_endpoint_and_makes_no_request(): void
    {
        $captured = $this->mockHttp(array());

        $jev    = new Mcpwp_Jev('test-key', 'http://jev-stub.test/v1/systemone');
        $result = $jev->evaluate('s', array('q' => array('type' => 'boolean', 'instructions' => 'x', 'criteria' => array('true' => 'a', 'false' => 'b'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jev_invalid_endpoint', $result->get_error_code());
        $this->assertCount(0, $captured);
    }

    public function test_evaluate_returns_wp_error_on_incomplete_answers(): void
    {
        $this->configure('https://jev-stub.test/v1/systemone');
        $this->mockHttp(array('answers' => array())); // missing the one question asked

        $result = Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jev_incomplete_response', $result->get_error_code());
    }

    public function test_evaluate_returns_wp_error_on_http_error_status(): void
    {
        $this->configure('https://jev-stub.test/v1/systemone');
        $this->mockHttp(array('error' => 'bad request'), 422);

        $result = Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jev_http_error', $result->get_error_code());
    }

    public function test_evaluate_returns_wp_error_on_transport_failure(): void
    {
        $this->configure('https://jev-stub.test/v1/systemone');
        add_filter('pre_http_request', function () {
            return new WP_Error('http_request_failed', 'timed out');
        }, 10, 3);

        $result = Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jev_unreachable', $result->get_error_code());
    }
}
