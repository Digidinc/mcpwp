<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-figma.php';

/**
 * #564 review r1 P2-2: Figma is Pro at the capability, not per entry point.
 *
 * The REST routes and MCP tools were gated, but the same capability ran on
 * Free through WP-Cron (hourly design-token sync), the Integrations page
 * (OAuth, test connection) and even get_status() (an expired OAuth token was
 * refreshed — a Figma call and a config write — whenever site-info asked for
 * capabilities). Mcpwp_Figma itself now refuses on Free, so every entry point
 * inherits it.
 *
 * Storing a Figma token on Free stays allowed: it unlocks nothing.
 */
final class FigmaProGateTest extends TestCase
{
    /** @var ArrayObject<int, array{url: string, method: string}> */
    private $http;

    protected function setUp(): void
    {
        if (defined('MCPWP_PRO') || defined('MCPWP_WPORG_BUILD') || defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('A build-level constant is defined in this process.');
        }

        $GLOBALS['mcpwp_test_options']            = array();
        $GLOBALS['mcpwp_test_transients']         = array();
        $GLOBALS['mcpwp_test_filters']            = array();
        $GLOBALS['mcpwp_test_cron']               = array();
        $GLOBALS['mcpwp_test_wpdb_read_fails']    = false;
        $GLOBALS['mcpwp_test_wpdb_insert_fails']  = false;
        unset($GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key']);

        $ref = new ReflectionProperty(Mcpwp_Encryption::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $this->setTier(false);
        $this->http = $this->mockFigma();
    }

    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_filters'] = array();
        $GLOBALS['mcpwp_test_cron']    = array();
        $GLOBALS['mcpwp_test_fs']      = null;
        $this->resetLicense();
    }

    private function resetLicense(): void
    {
        $ref = new ReflectionProperty(Mcpwp_License::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    private function setTier(bool $pro): void
    {
        $GLOBALS['mcpwp_test_fs'] = $pro ? new Mcpwp_Test_Fs_Stub(true, true, false, 'pro') : null;
        $this->resetLicense();
        $this->assertSame($pro, Mcpwp_License::get_instance()->is_pro(), 'tier precondition');
    }

    /**
     * Answers every Figma endpoint and records each request.
     */
    private function mockFigma(): ArrayObject
    {
        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured) {
            $captured[] = array('url' => (string) $url, 'method' => isset($args['method']) ? (string) $args['method'] : '');

            if (false !== strpos($url, '/oauth/token')) {
                $body = array('access_token' => 'fresh-access', 'refresh_token' => 'fresh-refresh', 'expires_in' => 3600);
            } elseif (false !== strpos($url, '/styles')) {
                $body = array('meta' => array('styles' => array()));
            } elseif (false !== strpos($url, '/me')) {
                $body = array('handle' => 'designer');
            } else {
                $body = array('name' => 'Brand file', 'document' => array('children' => array()));
            }

            return array('response' => array('code' => 200), 'body' => wp_json_encode($body));
        }, 10, 3);

        return $captured;
    }

    private function configure(array $config): void
    {
        $this->assertTrue(
            Mcpwp_Integration_Manager::get_instance()->set_provider_config('figma', $config),
            'storing Figma configuration is allowed on every tier'
        );
    }

    private function assertProRequired($result, string $what): void
    {
        $this->assertInstanceOf(WP_Error::class, $result, $what . ' must refuse on Free');
        $this->assertSame('pro_required', $result->get_error_code(), $what);
        $data = $result->get_error_data();
        $this->assertSame(403, is_array($data) && isset($data['status']) ? (int) $data['status'] : null, $what);
    }

    public function test_cron_sync_on_free_makes_no_http_call_and_writes_nothing(): void
    {
        $this->configure(array('personal_access_token' => 'figd_token', 'default_file_key' => 'FILE123'));

        Mcpwp_Figma::cron_sync_design_tokens();

        $this->assertCount(0, $this->http, 'no Figma request on Free');
        $this->assertFalse(get_option(Mcpwp_Figma::DESIGN_TOKENS_OPTION), 'no Figma-derived data written on Free');
    }

    public function test_an_expired_oauth_token_is_not_refreshed_on_free(): void
    {
        $config = array(
            'oauth_client_id'     => 'client',
            'oauth_client_secret' => 'secret',
            'access_token'        => 'stale-access',
            'refresh_token'       => 'stale-refresh',
            'expires_at'          => gmdate('Y-m-d H:i:s', time() - 3600),
            'default_file_key'    => 'FILE123',
        );
        $this->configure($config);

        $status = (new Mcpwp_Figma())->get_status();
        Mcpwp_Figma::cron_sync_design_tokens();

        $this->assertCount(0, $this->http, 'site-info status and cron must not refresh the token on Free');
        $this->assertSame($config, Mcpwp_Integration_Manager::get_instance()->get_provider_config('figma'), 'the stored config is unchanged');
        $this->assertTrue($status['pro_required']);
        $this->assertFalse(get_option(Mcpwp_Figma::DESIGN_TOKENS_OPTION));
    }

    public function test_schedule_on_free_clears_an_existing_event_and_schedules_nothing(): void
    {
        $GLOBALS['mcpwp_test_cron'][ Mcpwp_Figma::CRON_HOOK ] = time() - 60;

        Mcpwp_Figma::schedule();
        $this->assertFalse(wp_next_scheduled(Mcpwp_Figma::CRON_HOOK), 'an event scheduled while Pro is removed on Free');

        Mcpwp_Figma::schedule();
        $this->assertFalse(wp_next_scheduled(Mcpwp_Figma::CRON_HOOK), 'and never scheduled again on Free');
    }

    public function test_every_figma_use_refuses_on_free_without_calling_figma(): void
    {
        $this->configure(array(
            'personal_access_token' => 'figd_token',
            'oauth_client_id'       => 'client',
            'oauth_client_secret'   => 'secret',
            'default_file_key'      => 'FILE123',
        ));
        $stored = Mcpwp_Integration_Manager::get_instance()->get_provider_config('figma');

        $figma = new Mcpwp_Figma();

        $this->assertProRequired($figma->get_file(), 'get_file');
        $this->assertProRequired($figma->get_node('', '1:2'), 'get_node');
        $this->assertProRequired($figma->sync_design_tokens(), 'sync_design_tokens');
        $this->assertProRequired($figma->get_oauth_authorize_url(), 'get_oauth_authorize_url');

        set_transient('mcpwp_figma_oauth_state_abc', array('created_at' => time()), 600);
        $this->assertProRequired($figma->exchange_oauth_code('code', 'abc'), 'exchange_oauth_code');

        $test = $figma->test_connection();
        $this->assertFalse($test['success'], 'test_connection');

        $managerTest = Mcpwp_Integration_Manager::get_instance()->test_provider('figma');
        $this->assertFalse($managerTest['success'], 'Integrations > Test connection');

        $this->assertCount(0, $this->http, 'not one Figma request on Free');
        $this->assertFalse(get_option(Mcpwp_Figma::DESIGN_TOKENS_OPTION));
        $issued = array_filter(
            array_keys($GLOBALS['mcpwp_test_transients']),
            static function (string $name): bool {
                return 0 === strpos($name, 'mcpwp_figma_oauth_state_') && 'mcpwp_figma_oauth_state_abc' !== $name;
            }
        );
        $this->assertSame(array(), array_values($issued), 'no new OAuth state is issued on Free');

        $current = Mcpwp_Integration_Manager::get_instance()->get_provider_config('figma');
        unset($current['last_tested'], $current['test_status']);
        $this->assertSame($stored, $current, 'no Figma tokens written on Free');

        $status = $figma->get_status();
        $this->assertTrue($status['configured'], 'a stored token is still reported as configured');
        $this->assertTrue($status['pro_required'], 'and, separately, as needing Pro');
    }

    public function test_pro_keeps_the_scheduled_sync_and_figma_access(): void
    {
        $this->setTier(true);
        $this->configure(array(
            'personal_access_token' => 'figd_token',
            'oauth_client_id'       => 'client',
            'oauth_client_secret'   => 'secret',
            'default_file_key'      => 'FILE123',
        ));

        Mcpwp_Figma::schedule();
        $this->assertNotFalse(wp_next_scheduled(Mcpwp_Figma::CRON_HOOK), 'Pro schedules the sync');

        Mcpwp_Figma::cron_sync_design_tokens();
        $this->assertGreaterThan(0, count($this->http), 'Pro cron calls Figma');
        $tokens = get_option(Mcpwp_Figma::DESIGN_TOKENS_OPTION);
        $this->assertIsArray($tokens, 'Pro cron writes the design tokens');
        $this->assertSame('FILE123', $tokens['file_key']);

        $figma = new Mcpwp_Figma();
        $this->assertIsArray($figma->get_file());
        $this->assertIsString($figma->get_oauth_authorize_url());
        $this->assertTrue($figma->test_connection()['success']);
        $this->assertFalse($figma->get_status()['pro_required']);
    }
}
