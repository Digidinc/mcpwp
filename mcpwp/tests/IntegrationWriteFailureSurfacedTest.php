<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-figma.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-integrations.php';
require_once dirname(__DIR__) . '/includes/admin/class-mcpwp-integrations-admin.php';

if (! class_exists('Mcpwp_Test_Json_Sent')) {
    /**
     * What a wp_send_json_* stub would have sent; thrown so the handler stops
     * where wp_die() would stop it.
     */
    final class Mcpwp_Test_Json_Sent extends RuntimeException
    {
        /** @var bool */
        public $sent_success;

        /** @var mixed */
        public $sent_data;

        public function __construct(bool $success, $data)
        {
            parent::__construct('json sent');
            $this->sent_success = $success;
            $this->sent_data    = $data;
        }
    }
}

/**
 * #845 review round 2, P2-2: a lost provider write reported success.
 *
 * The provider writes return false when the records cannot be read or every
 * compare-and-swap attempt misses. The REST remove route, the MCP
 * wp_remove_integration tool and the admin AJAX remove discarded that and
 * answered success, so an operator revoking a leaked key was told it was gone
 * while it stayed stored and in use. Saves answered a generic failure that did
 * not say the store was the problem, and a test whose status could not be
 * recorded said nothing about it.
 *
 * Every caller of the provider writes now surfaces the failure:
 *   REST / MCP  WP_Error integration_write_failed (503)
 *   admin AJAX  wp_send_json_error with code integration_write_failed
 *   tests       status_error integration_status_not_saved beside the result
 * and removing a provider that is not stored is a success that says so.
 */
final class IntegrationWriteFailureSurfacedTest extends TestCase
{
    private const OPTION = 'mcpwp_integrations';

    protected function setUp(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }
        if (defined('MCPWP_ENCRYPTION_KEY') || defined('MCPWP_PRO') || defined('MCPWP_WPORG_BUILD')) {
            $this->markTestSkipped('A build-level constant is defined in this process.');
        }

        $this->resetState();
        $this->assertNotFalse(Mcpwp_Encryption::get_instance()->encrypt('warm-up'));
    }

    protected function tearDown(): void
    {
        $this->resetState();
    }

    private function resetState(): void
    {
        $GLOBALS['mcpwp_test_options']            = array();
        $GLOBALS['mcpwp_test_transients']         = array();
        $GLOBALS['mcpwp_test_update_option_fail'] = array();
        $GLOBALS['mcpwp_test_wpdb_read_fails']    = false;
        $GLOBALS['mcpwp_test_wpdb_insert_fails']  = false;
        $GLOBALS['mcpwp_test_wpdb_before_insert'] = null;
        $GLOBALS['mcpwp_test_wpdb_before_update'] = null;
        $GLOBALS['mcpwp_test_wpdb_after_update']  = null;
        $GLOBALS['mcpwp_test_wpdb_update_fails']  = false;
        $GLOBALS['mcpwp_test_option_store_hook']  = null;
        $GLOBALS['mcpwp_test_fs']                 = null;
        $GLOBALS['mcpwp_test_current_user']       = 1;
        $GLOBALS['mcpwp_test_ajax_referer_valid'] = true;
        $_POST                                    = array();

        foreach (array('mcpwp_encryption_key', self::OPTION) as $name) {
            unset($GLOBALS['mcpwp_test_wpdb_options'][ $name ]);
        }

        foreach (array(Mcpwp_Encryption::class, Mcpwp_License::class) as $class) {
            $ref = new ReflectionProperty($class, 'instance');
            $ref->setAccessible(true);
            $ref->setValue(null, null);
        }
    }

    private function record(string $blob): array
    {
        return array(
            'key_encrypted' => $blob,
            'configured_at' => '2026-09-01 00:00:00',
            'last_tested'   => null,
            'test_status'   => null,
        );
    }

    private function seed(array $records): void
    {
        $GLOBALS['mcpwp_test_options'][ self::OPTION ] = $records;
    }

    private function records(): array
    {
        if (array_key_exists(self::OPTION, $GLOBALS['mcpwp_test_wpdb_options'] ?? array())) {
            $value = maybe_unserialize($GLOBALS['mcpwp_test_wpdb_options'][ self::OPTION ]);
        } else {
            $value = $GLOBALS['mcpwp_test_options'][ self::OPTION ] ?? array();
        }

        if (is_array($value)) { unset($value[Mcpwp_Integration_Manager::GENERATIONS_KEY]); }
        return is_array($value) ? $value : array();
    }

    /** Every write to the provider records fails at the database. */
    private function failEveryWrite(): void
    {
        $GLOBALS['mcpwp_test_wpdb_update_fails']  = function (string $name): bool {
            return self::OPTION === $name;
        };
        $GLOBALS['mcpwp_test_wpdb_insert_fails']  = true;
        $GLOBALS['mcpwp_test_update_option_fail'] = array(self::OPTION => true);
    }

    /** Another writer changes the records before every attempt, forever. */
    private function conflictForever(): void
    {
        $bump = null;
        $bump = function (string $name) use (&$bump): void {
            if (self::OPTION === $name) {
                $GLOBALS['mcpwp_test_options'][ self::OPTION ]['pexels']['configured_at'] = uniqid('', true);
            }
            $GLOBALS['mcpwp_test_wpdb_before_update'] = $bump;
        };
        $GLOBALS['mcpwp_test_wpdb_before_update'] = $bump;
        $GLOBALS['mcpwp_test_update_option_fail'] = array(self::OPTION => true);
    }

    private function controller(): Mcpwp_REST_Integrations
    {
        return new Mcpwp_REST_Integrations();
    }

    private function request(array $params): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/mcpwp/v1/integrations');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return $request;
    }

    private function assertWriteFailure($result, string $what): void
    {
        $this->assertInstanceOf(WP_Error::class, $result, $what . ': a lost write is never reported as success');
        $this->assertSame('integration_write_failed', $result->get_error_code(), $what);
        $data = $result->get_error_data();
        $this->assertSame(503, is_array($data) ? ($data['status'] ?? null) : null, $what);
    }

    // ---------------------------------------------------------------------
    // REST and MCP.
    // ---------------------------------------------------------------------

    public function test_rest_remove_key_reports_a_lost_removal_and_the_key_stays_stored(): void
    {
        $this->seed(array('openai' => $this->record('v2:openai-blob'), 'pexels' => $this->record('v2:pexels-blob')));
        $this->conflictForever();

        $this->assertWriteFailure($this->controller()->remove_key($this->request(array('provider' => 'openai'))), 'REST remove_key');
        $this->assertArrayHasKey('openai', $this->records());
    }

    public function test_mcp_remove_integration_reports_a_lost_removal(): void
    {
        $this->seed(array('openai' => $this->record('v2:openai-blob')));
        $this->failEveryWrite();

        $this->assertWriteFailure($this->controller()->remove_integration($this->request(array('provider' => 'openai'))), 'wp_remove_integration');
        $this->assertArrayHasKey('openai', $this->records());
    }

    public function test_removing_a_provider_that_is_not_stored_is_a_success_that_says_nothing_was_removed(): void
    {
        $this->seed(array('pexels' => $this->record('v2:pexels-blob')));

        foreach (array('remove_key', 'remove_integration') as $route) {
            $response = $this->controller()->{$route}($this->request(array('provider' => 'openai')));

            $this->assertInstanceOf(WP_REST_Response::class, $response, $route);
            $this->assertTrue($response->get_data()['success'], $route);
            $this->assertFalse($response->get_data()['removed'] ?? null, "{$route}: removing nothing is not reported as a removal");
        }

        $response = $this->controller()->remove_key($this->request(array('provider' => 'pexels')));
        $this->assertTrue($response->get_data()['removed'] ?? null, 'a real removal says so');
        $this->assertArrayNotHasKey('pexels', $this->records());
    }

    /** @dataProvider secretContainerEncodings */
    public function test_secret_key_is_masked_before_decoding_in_both_logs($secret): void
    {
        require_once dirname(__DIR__) . '/includes/core/class-mcpwp-action-log.php';
        update_option('mcpwp_settings', array('enable_logging' => true));
        $GLOBALS['mcpwp_test_wpdb_rows'] = array();
        $args = array('provider' => 'figma', 'config' => array('oauth_client_secret' => $secret));
        $this->assertInstanceOf(WP_REST_Response::class, $this->controller()->configure_integration($this->request($args)));
        $id = Mcpwp_Action_Log::begin('wp_configure_integration', $args, 'fixture', 'ai');
        Mcpwp_Action_Log::complete($id, 1, true, '');
        foreach (array('mcpwp_activity_log' => 'request_data', Mcpwp_Action_Log::TABLE => 'arguments') as $table => $column) {
            $rows = $GLOBALS['mcpwp_test_wpdb_rows'][$GLOBALS['wpdb']->prefix . $table] ?? array();
            $this->assertNotEmpty($rows, 'both audit tables record the action');
            $data = json_decode(end($rows)[$column], true);
            $this->assertSame('[redacted]', $data['config']['oauth_client_secret']);
        }
    }

    public function secretContainerEncodings(): array
    {
        return array(array('[]'), array('{}'), array('a:0:{}'), array('O:8:"stdClass":0:{}'), array('abc'));
    }

    public function test_configure_activity_log_redacts_short_secrets(): void
    {
        update_option('mcpwp_settings', array('enable_logging' => true));
        foreach (array('x', 'xy', 'abc', '0', '1', 'yes', 'long-fixture-secret') as $secret) {
            $GLOBALS['mcpwp_test_wpdb_rows'] = array();
            $result = $this->controller()->configure_integration($this->request(array(
                'provider' => 'figma',
                'config' => array('oauth_client_secret' => $secret),
            )));
            $this->assertInstanceOf(WP_REST_Response::class, $result);
            $rows = $GLOBALS['mcpwp_test_wpdb_rows'][ $GLOBALS['wpdb']->prefix . 'mcpwp_activity_log' ] ?? array();
            $this->assertNotEmpty($rows, 'configure writes an activity record');
            $logged = false;
            foreach ($rows as $row) {
                $data = json_decode($row['request_data'] ?? '', true);
                if (isset($data['config']['oauth_client_secret'])) {
                    $logged = true;
                    $this->assertSame('[redacted]', $data['config']['oauth_client_secret']);
                }
            }
            $this->assertTrue($logged, 'configured secret field is present only as a redacted marker');
        }
    }

    public function test_rest_key_only_write_to_multi_field_provider_returns_actionable_400(): void
    {
        $manager = Mcpwp_Integration_Manager::get_instance();
        $this->assertTrue($manager->set_provider_config('screenshot', array('url' => 'https://worker.example', 'token' => 'fixture-token')));
        $before = $this->records();
        $result = $this->controller()->store_key($this->request(array('provider' => 'screenshot', 'key' => 'replacement')));
        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('invalid_config', $result->get_error_code());
        $this->assertSame(400, $result->get_error_data()['status']);
        $this->assertStringContainsString('/integrations/configure', $result->get_error_message());
        $this->assertTrue($before === $this->records(), 'REST refusal preserves ciphertext');
    }

    public function test_rest_store_key_reports_a_lost_write(): void
    {
        $this->seed(array('pexels' => $this->record('v2:pexels-blob')));
        $this->failEveryWrite();

        $this->assertWriteFailure($this->controller()->store_key($this->request(array('provider' => 'openai', 'key' => 'sk-new'))), 'REST store_key');
        $this->assertArrayNotHasKey('openai', $this->records());
    }

    public function test_mcp_configure_integration_reports_a_lost_write_for_keys_and_multi_field_configs(): void
    {
        $this->seed(array('pexels' => $this->record('v2:pexels-blob')));
        $this->failEveryWrite();

        $this->assertWriteFailure(
            $this->controller()->configure_integration($this->request(array('provider' => 'openai', 'key' => 'sk-new'))),
            'wp_configure_integration (key)'
        );
        $this->assertWriteFailure(
            $this->controller()->configure_integration($this->request(array('provider' => 'screenshot', 'config' => array('url' => 'https://worker.example', 'token' => 't')))),
            'wp_configure_integration (config)'
        );
        $this->assertSame(array('pexels'), array_keys($this->records()));
    }

    public function test_rest_and_mcp_test_results_say_when_their_status_was_not_recorded(): void
    {
        foreach (array('test_provider', 'test_integration') as $route) {
            $this->seed(array('figma' => $this->record('v2:figma-blob'), 'pexels' => $this->record('v2:pexels-blob')));
            $this->conflictForever();

            $response = $this->controller()->{$route}($this->request(array('provider' => 'figma')));

            $this->assertInstanceOf(WP_REST_Response::class, $response, $route);
            $data = $response->get_data();
            $this->assertFalse($data['success'], "{$route}: Figma on Free fails its test");
            $this->assertSame('integration_status_not_saved', $data['status_error']['code'] ?? null, "{$route}: the lost status write is said, not left out");
            $this->assertNull($this->records()['figma']['test_status'], $route);

            $GLOBALS['mcpwp_test_wpdb_before_update'] = null;
        }
    }

    // ---------------------------------------------------------------------
    // Admin AJAX (separate processes: the wp_send_json_* stubs are global).
    // ---------------------------------------------------------------------

    /**
     * Declare wp_send_json_success/error as stubs that stop the handler the
     * way wp_die() does, carrying what would have been sent.
     */
    private function stubJsonResponses(): void
    {
        if (! function_exists('wp_send_json_success')) {
            function wp_send_json_success($data = null, $status_code = null, $flags = 0)
            {
                throw new Mcpwp_Test_Json_Sent(true, $data);
            }
        }
        if (! function_exists('wp_send_json_error')) {
            function wp_send_json_error($data = null, $status_code = null, $flags = 0)
            {
                throw new Mcpwp_Test_Json_Sent(false, $data);
            }
        }
    }

    private function ajax(string $handler, array $post): RuntimeException
    {
        $_POST = array_merge(array('nonce' => 'n'), $post);
        $admin = (new ReflectionClass(Mcpwp_Integrations_Admin::class))->newInstanceWithoutConstructor();

        try {
            $admin->{$handler}();
        } catch (RuntimeException $sent) {
            return $sent;
        }

        $this->fail("{$handler} sent no response");
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_admin_save_reports_a_lost_write_with_its_code(): void
    {
        $this->stubJsonResponses();
        $this->seed(array('pexels' => $this->record('v2:pexels-blob')));
        $this->failEveryWrite();

        $sent = $this->ajax('ajax_save_key', array('provider' => 'openai', 'key' => 'sk-new'));

        $this->assertFalse($sent->sent_success);
        $this->assertSame('integration_write_failed', $sent->sent_data['code'] ?? null, 'the admin notice says the store was the problem');
        $this->assertArrayNotHasKey('openai', $this->records());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_admin_remove_reports_a_lost_removal_and_says_when_nothing_was_stored(): void
    {
        $this->stubJsonResponses();
        $this->seed(array('openai' => $this->record('v2:openai-blob'), 'pexels' => $this->record('v2:pexels-blob')));
        $this->conflictForever();

        $sent = $this->ajax('ajax_remove_key', array('provider' => 'openai'));

        $this->assertFalse($sent->sent_success, 'a lost removal is not "API key removed."');
        $this->assertSame('integration_write_failed', $sent->sent_data['code'] ?? null);
        $this->assertArrayHasKey('openai', $this->records());

        $GLOBALS['mcpwp_test_wpdb_before_update'] = null;
        $GLOBALS['mcpwp_test_update_option_fail'] = array();

        $absent = $this->ajax('ajax_remove_key', array('provider' => 'gemini'));
        $this->assertTrue($absent->sent_success);
        $this->assertFalse($absent->sent_data['removed'] ?? null, 'nothing was stored, and the notice says so');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_admin_test_connection_says_when_its_status_was_not_recorded(): void
    {
        $this->stubJsonResponses();
        $this->seed(array('figma' => $this->record('v2:figma-blob'), 'pexels' => $this->record('v2:pexels-blob')));
        $this->conflictForever();

        $sent = $this->ajax('ajax_test_connection', array('provider' => 'figma'));

        $this->assertSame('integration_status_not_saved', $sent->sent_data['status_error']['code'] ?? null);
        $this->assertNull($this->records()['figma']['test_status']);
    }
}
