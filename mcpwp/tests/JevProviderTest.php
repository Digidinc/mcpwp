<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-integration-manager.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-jev.php';

/**
 * Jev (TypeSafe / Cloudflare Workers AI) provider registration + wire client.
 *
 * Jev is opt-in, bring-your-own-key, and off until either a TypeSafe key or
 * Cloudflare Workers AI credentials are saved. These tests cover the provider
 * config surface, transport resolution/override, and the request/response
 * shape of Mcpwp_Jev::evaluate() for BOTH transports against a mocked
 * pre_http_request — never a real TypeSafe key, never a real Cloudflare
 * token, never a real network call.
 *
 * The Cloudflare fixtures below (request/response shape) are taken directly
 * from Cloudflare's published schema-input.json / schema-output.json for
 * typesafe/jev (https://developers.cloudflare.com/ai/models/typesafe/jev/),
 * not guessed — see the class docblock on Mcpwp_Jev for what was verified.
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

    private function configure(string $endpoint = '', string $transport = ''): void
    {
        $config = array('api_key' => 'test-typesafe-key');
        if ('' !== $endpoint) {
            $config['endpoint'] = $endpoint;
        }
        if ('' !== $transport) {
            $config['transport'] = $transport;
        }
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('jev', $config);
    }

    private function configureTransportOverrideOnly(string $transport): void
    {
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('jev', array('transport' => $transport));
    }

    private function configureCloudflare(bool $withAccountId = true): void
    {
        $config = array(
            'api_token' => 'test-cf-token',
            'zone_id'   => 'zone-123',
        );
        if ($withAccountId) {
            $config['account_id'] = 'account-abc';
        }
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('cloudflare', $config);
    }

    /**
     * Points Mcpwp_Jev's Cloudflare endpoint at a local stub URL via the
     * mcpwp_jev_cloudflare_endpoint filter, and returns that URL.
     */
    private function stubCloudflareEndpoint(string $url = 'https://cf-stub.test/client/v4/accounts/account-abc/ai/run'): string
    {
        add_filter('mcpwp_jev_cloudflare_endpoint', function () use ($url) {
            return $url;
        }, 10, 2);
        return $url;
    }

    /**
     * Wraps a Cloudflare Workers AI "run" result in the standard
     * {success,errors,messages,result} envelope and registers it as the
     * mocked HTTP response.
     *
     * @return ArrayObject<int, array{url:string, args:array}>
     */
    private function mockCloudflareHttp(array $jevResult, int $httpCode = 200, bool $success = true, array $errors = array()): ArrayObject
    {
        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured, $jevResult, $httpCode, $success, $errors) {
            $captured[] = array('url' => $url, 'args' => $args);
            return array(
                'response' => array('code' => $httpCode),
                'body'     => wp_json_encode(array(
                    'success' => $success,
                    'errors'  => $errors,
                    'messages' => array(),
                    'result'  => $jevResult,
                )),
            );
        }, 10, 3);

        return $captured;
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

    // -- Cloudflare provider field registration -----------------------------

    public function test_cloudflare_gains_an_optional_account_id_field(): void
    {
        $fields = Mcpwp_Integration_Manager::PROVIDERS['cloudflare']['fields'];
        $this->assertArrayHasKey('account_id', $fields);
    }

    public function test_jev_gains_a_transport_override_field(): void
    {
        $fields = Mcpwp_Integration_Manager::PROVIDERS['jev']['fields'];
        $this->assertArrayHasKey('transport', $fields);
    }

    // -- Transport resolution: structural, no network ------------------------

    public function test_transport_is_empty_when_nothing_is_configured(): void
    {
        $this->assertSame('', Mcpwp_Jev::transport());
        $this->assertFalse(Mcpwp_Jev::is_available());
        $this->assertFalse(Mcpwp_Jev::is_configured());
    }

    public function test_transport_resolves_to_typesafe_when_only_typesafe_is_configured(): void
    {
        $this->configure();
        $this->assertSame('typesafe', Mcpwp_Jev::transport());
    }

    public function test_transport_resolves_to_cloudflare_when_only_cloudflare_is_configured(): void
    {
        $this->configureCloudflare();
        $this->assertSame('cloudflare', Mcpwp_Jev::transport());
        $this->assertTrue(Mcpwp_Jev::is_available());
        $this->assertTrue(Mcpwp_Jev::is_configured());
    }

    public function test_transport_prefers_typesafe_when_both_are_configured_and_no_override(): void
    {
        $this->configure();
        $this->configureCloudflare();

        $this->assertSame('typesafe', Mcpwp_Jev::transport());
    }

    public function test_cloudflare_zone_scoped_token_alone_is_not_enough_without_account_id(): void
    {
        // api_token + zone_id only (what the existing wp_cloudflare_* tools
        // need) must NOT be treated as Workers AI-ready — account_id is the
        // signal that this token was deliberately meant for Workers AI too.
        $this->configureCloudflare(false);

        $this->assertSame('', Mcpwp_Jev::transport());
    }

    public function test_override_forces_cloudflare_even_though_typesafe_is_also_configured(): void
    {
        $this->configure('', 'cloudflare');
        $this->configureCloudflare();

        $this->assertSame('cloudflare', Mcpwp_Jev::transport());
    }

    public function test_override_forces_typesafe_even_though_cloudflare_is_also_configured(): void
    {
        $this->configure('', 'typesafe');
        $this->configureCloudflare();

        $this->assertSame('typesafe', Mcpwp_Jev::transport());
    }

    public function test_override_naming_an_unconfigured_transport_does_not_fall_back_to_the_other(): void
    {
        // Override says "typesafe" but only Cloudflare is configured — this
        // must resolve to unavailable, not silently substitute Cloudflare.
        $this->configureTransportOverrideOnly('typesafe');
        $this->configureCloudflare();

        $this->assertSame('', Mcpwp_Jev::transport());

        $status = Mcpwp_Jev::status();
        $this->assertSame('override_typesafe_not_configured', $status['reason']);
    }

    public function test_override_naming_cloudflare_when_unconfigured_does_not_fall_back_to_typesafe(): void
    {
        $this->configure(); // TypeSafe configured
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('jev', array(
            'api_key'   => 'test-typesafe-key',
            'transport' => 'cloudflare',
        ));

        $this->assertSame('', Mcpwp_Jev::transport());
        $this->assertSame('override_cloudflare_not_configured', Mcpwp_Jev::status()['reason']);
    }

    public function test_unrecognised_override_value_behaves_as_auto(): void
    {
        $this->configure('', 'some-typo');

        $this->assertSame('typesafe', Mcpwp_Jev::transport());
    }

    public function test_status_reason_when_nothing_is_configured(): void
    {
        $this->assertSame(array('transport' => '', 'reason' => 'not_configured'), Mcpwp_Jev::status());
    }

    // -- Cloudflare wire shape: fixtures from Cloudflare's published schema -------

    public function test_for_site_builds_a_cloudflare_client_when_resolved(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();

        $jev = Mcpwp_Jev::for_site();

        $this->assertInstanceOf(Mcpwp_Jev::class, $jev);
        $this->assertSame('cloudflare', $jev->which_transport());
    }

    public function test_cloudflare_endpoint_defaults_to_the_documented_account_scoped_url(): void
    {
        $this->configureCloudflare();
        // No mcpwp_jev_cloudflare_endpoint filter hooked — must compute the
        // real https://api.cloudflare.com/... URL from account_id, per
        // https://developers.cloudflare.com/ai/models/typesafe/jev/.
        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured) {
            $captured[] = $url;
            return array(
                'response' => array('code' => 200),
                'body'     => wp_json_encode(array(
                    'success' => true,
                    'result'  => array('answers' => array('q' => array('type' => 'choice', 'choice' => 'a'))),
                )),
            );
        }, 10, 3);

        Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertCount(1, $captured);
        $this->assertSame('https://api.cloudflare.com/client/v4/accounts/account-abc/ai/run', $captured[0]);
    }

    public function test_evaluate_cloudflare_sends_the_documented_request_shape(): void
    {
        $this->configureCloudflare();
        $endpoint = $this->stubCloudflareEndpoint();

        // This exact {model, input:{state, questions}} shape, and the
        // noul/choice/score criteria/answer shapes below, are copied from
        // Cloudflare's published schema-input.json / schema-output.json and
        // its worked curl example — not guessed.
        $captured = $this->mockCloudflareHttp(array(
            'model'   => 'jev-1.13.0',
            'answers' => array(
                'is_urgent' => array('type' => 'noul', 'noul' => 0.75),
            ),
            'usage'   => array('input_tokens' => 42, 'output_tokens' => 0),
        ));

        $jev    = Mcpwp_Jev::for_site();
        $result = $jev->evaluate(
            array('title' => 'test state'),
            array(
                'is_urgent' => array(
                    'type'         => 'boolean',
                    'instructions' => 'Does this convey urgency?',
                    'criteria'     => array('true' => 'Explicitly time-sensitive', 'false' => 'No urgency expressed'),
                ),
            )
        );

        $this->assertCount(1, $captured);
        $request = $captured[0];
        $this->assertSame($endpoint, $request['url']);
        $this->assertSame('POST', $request['args']['method']);
        $this->assertTrue($request['args']['reject_unsafe_urls']);
        $this->assertTrue($request['args']['sslverify']);
        $this->assertSame('Bearer test-cf-token', $request['args']['headers']['Authorization']);
        $this->assertSame('application/json', $request['args']['headers']['Content-Type']);
        $this->assertLessThanOrEqual(8, $request['args']['timeout']);

        $body = json_decode($request['args']['body'], true);
        $this->assertSame('typesafe/jev', $body['model']);
        $this->assertSame(array('title' => 'test state'), $body['input']['state']);
        // Our 'boolean' spelling must be translated to the shared 'noul' wire
        // spelling for Cloudflare too — same dialect as TypeSafe.
        $this->assertSame('noul', $body['input']['questions']['is_urgent']['type']);

        $this->assertIsArray($result);
        $this->assertSame('cloudflare', $result['transport']);
        $this->assertSame('boolean', $result['answers']['is_urgent']['type']);
        $this->assertSame(0.75, $result['answers']['is_urgent']['probability']);
    }

    public function test_evaluate_cloudflare_translates_choice_and_score_answers_unchanged(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();

        $this->mockCloudflareHttp(array(
            'model'   => 'jev-1.13.0',
            'answers' => array(
                'department' => array(
                    'type'          => 'choice',
                    'choice'        => 'billing',
                    'probabilities' => array('billing' => 0.8, 'technical' => 0.15, 'sales' => 0.05),
                    'confidence'    => 0.8,
                ),
                'frustration' => array(
                    'type'          => 'score',
                    'score'         => 1.4,
                    'legend'        => array('0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry'),
                    'probabilities' => array('0' => 0.1, '1' => 0.6, '2' => 0.3),
                    'confidence'    => 0.6,
                ),
            ),
            'usage'   => array('input_tokens' => 50, 'output_tokens' => 0),
        ));

        $result = Mcpwp_Jev::for_site()->evaluate(
            'Help! My payouts have been failing for 3 days.',
            array(
                'department'  => array(
                    'type'         => 'choice',
                    'instructions' => 'Which team should handle this?',
                    'criteria'     => array('billing' => 'Payments, invoicing, refunds', 'technical' => 'Bugs, outages, integrations', 'sales' => 'Pricing, upgrades, new accounts'),
                ),
                'frustration' => array(
                    'type'         => 'score',
                    'instructions' => 'How frustrated is the customer?',
                    'criteria'     => array('Calm', 'Frustrated', 'Very angry'),
                ),
            )
        );

        $this->assertIsArray($result);
        // choice/score answers pass through untranslated on both transports.
        $this->assertSame('billing', $result['answers']['department']['choice']);
        $this->assertSame(1.4, $result['answers']['frustration']['score']);
        $this->assertSame('Frustrated', $result['answers']['frustration']['legend']['1']);
    }

    public function test_evaluate_cloudflare_returns_wp_error_when_envelope_reports_failure(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();
        $this->mockCloudflareHttp(
            array(),
            200,
            false,
            array(array('code' => 7003, 'message' => 'No route for that URI'))
        );

        $result = Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jev_http_error', $result->get_error_code());
        $this->assertStringContainsString('No route for that URI', $result->get_error_message());
    }

    public function test_evaluate_cloudflare_returns_wp_error_on_http_error_status(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();
        $this->mockCloudflareHttp(array(), 403, false);

        $result = Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jev_http_error', $result->get_error_code());
    }

    public function test_evaluate_cloudflare_returns_wp_error_on_transport_failure(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();
        add_filter('pre_http_request', function () {
            return new WP_Error('http_request_failed', 'timed out');
        }, 10, 3);

        $result = Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jev_unreachable', $result->get_error_code());
    }

    public function test_evaluate_cloudflare_never_relaxes_sslverify(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint('https://127.0.0.1:9443/client/v4/accounts/account-abc/ai/run');
        $captured = $this->mockCloudflareHttp(array('answers' => array('q' => array('type' => 'choice', 'choice' => 'a'))));

        Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertCount(1, $captured);
        $this->assertTrue($captured[0]['args']['sslverify']);
        $this->assertTrue($captured[0]['args']['reject_unsafe_urls']);
    }

    // -- test_connection(): says which transport answered, and why when none does --

    public function test_connection_reports_not_configured_message_when_nothing_is_saved(): void
    {
        $result = Mcpwp_Jev::test_connection();

        $this->assertFalse($result['success']);
        $this->assertSame('', $result['transport']);
        $this->assertStringContainsString('not configured', $result['message']);
    }

    public function test_connection_succeeds_via_typesafe_and_names_the_transport(): void
    {
        $this->configure('https://jev-stub.test/v1/systemone');
        $this->mockHttp(array('answers' => array('ok' => array('type' => 'noul', 'noul' => 0.99))));

        $result = Mcpwp_Jev::test_connection();

        $this->assertTrue($result['success']);
        $this->assertSame('typesafe', $result['transport']);
        $this->assertStringContainsString('typesafe', $result['message']);
    }

    public function test_connection_succeeds_via_cloudflare_and_names_the_transport(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();
        $this->mockCloudflareHttp(array('answers' => array('ok' => array('type' => 'noul', 'noul' => 0.99))));

        $result = Mcpwp_Jev::test_connection();

        $this->assertTrue($result['success']);
        $this->assertSame('cloudflare', $result['transport']);
        $this->assertStringContainsString('cloudflare', $result['message']);
    }

    public function test_connection_surfaces_a_cloudflare_permission_failure_as_a_named_message(): void
    {
        // The "does the token actually have Workers AI permission" question
        // is answered by a real call, not guessed at resolution time — this
        // is what that failure looks like.
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();
        $this->mockCloudflareHttp(array(), 403, false, array(array('code' => 10000, 'message' => 'Authentication error')));

        $result = Mcpwp_Jev::test_connection();

        $this->assertFalse($result['success']);
        $this->assertSame('cloudflare', $result['transport']);
        $this->assertStringContainsString('Authentication error', $result['message']);
    }

    // -- Unwrapped Cloudflare body (the model page's documented response) -----

    public function test_evaluate_cloudflare_accepts_the_unwrapped_model_payload_on_2xx(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();
        $this->mockHttp(array(
            'model'   => 'jev-1.13.0',
            'answers' => array('is_urgent' => array('type' => 'noul', 'noul' => 0.95)),
            'usage'   => array('input_tokens' => 426, 'output_tokens' => 73),
        ));

        $result = Mcpwp_Jev::for_site()->evaluate('s', array('is_urgent' => array('type' => 'boolean', 'instructions' => 'x', 'criteria' => array('true' => 'y', 'false' => 'n'))));

        $this->assertIsArray($result);
        $this->assertSame('cloudflare', $result['transport']);
        $this->assertSame(0.95, $result['answers']['is_urgent']['probability']);
        $this->assertSame('jev-1.13.0', $result['model']);
    }

    public function test_evaluate_cloudflare_does_not_accept_an_unwrapped_body_on_an_error_status(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();
        $this->mockHttp(array('answers' => array('q' => array('type' => 'choice', 'choice' => 'a'))), 500);

        $result = Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('jev_http_error', $result->get_error_code());
    }

    public function test_evaluate_cloudflare_error_never_echoes_the_token(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();
        $this->mockCloudflareHttp(array(), 401, false);

        $result = Mcpwp_Jev::for_site()->evaluate('s', array('q' => array('type' => 'choice', 'instructions' => 'x', 'criteria' => array('a' => 'a'))));

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertStringNotContainsString('test-cf-token', $result->get_error_message());
        $this->assertStringNotContainsString('test-cf-token', wp_json_encode($result->get_error_data()));
    }

    // -- Integration status + test surface say which transport and why ---------

    public function test_integrations_status_reports_the_active_jev_transport_and_reason(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();

        $none = $manager->get_available_providers()['jev'];
        $this->assertFalse($none['available']);
        $this->assertSame('', $none['transport']);
        $this->assertSame('not_configured', $none['transport_reason']);

        // Cloudflare credentials alone turn Jev on, with no 'jev' record saved.
        $this->configureCloudflare();
        $cf = $manager->get_available_providers()['jev'];
        $this->assertTrue($cf['available']);
        $this->assertSame('cloudflare', $cf['transport']);
        $this->assertSame('cloudflare_workers_ai_credentials_configured', $cf['transport_reason']);

        $this->configure();
        $ts = $manager->get_available_providers()['jev'];
        $this->assertSame('typesafe', $ts['transport']);
        $this->assertSame('typesafe_key_configured', $ts['transport_reason']);
    }

    public function test_integration_manager_test_provider_for_jev_names_the_transport(): void
    {
        $this->configureCloudflare();
        $this->stubCloudflareEndpoint();
        $this->mockCloudflareHttp(array('answers' => array('ok' => array('type' => 'noul', 'noul' => 0.99))));

        $result = Mcpwp_Integration_Manager::get_instance()->test_provider('jev');

        $this->assertTrue($result['success']);
        $this->assertSame('cloudflare', $result['transport']);
    }
}
