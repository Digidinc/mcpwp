<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-figma.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-figma-integration.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-guides.php';

/**
 * #845: onboarding told Free agents to call wp_figma_status.
 *
 * The Figma tools are Pro: on Free they are neither listed nor callable
 * (#564). The introspection quick start and operating rules, and the
 * onboarding guide, still named them whenever a Figma token was stored — and
 * storing one is allowed on every tier — so a Free agent was sent to a tool
 * that answers "unknown tool".
 *
 * Figma is mentioned only when the site is entitled to Pro, by the same check
 * the Figma gate uses (Mcpwp_Figma::is_entitled()).
 */
final class OnboardingFigmaTierTest extends TestCase
{
    protected function setUp(): void
    {
        if (defined('MCPWP_PRO') || defined('MCPWP_WPORG_BUILD') || defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('A build-level constant is defined in this process.');
        }
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }

        $GLOBALS['mcpwp_test_options']           = array();
        $GLOBALS['mcpwp_test_transients']        = array();
        $GLOBALS['mcpwp_test_filters']           = array();
        $GLOBALS['mcpwp_test_wpdb_read_fails']   = false;
        $GLOBALS['mcpwp_test_wpdb_insert_fails'] = false;
        unset($GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'], $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_integrations']);

        $ref = new ReflectionProperty(Mcpwp_Encryption::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        Mcpwp_Integration::clear_cache();
    }

    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_fs']      = null;
        $GLOBALS['mcpwp_test_filters'] = array();
        $this->setTier(false);
        Mcpwp_Integration::clear_cache();
    }

    private function setTier(bool $pro): void
    {
        $GLOBALS['mcpwp_test_fs'] = $pro ? new Mcpwp_Test_Fs_Stub(true, true, false, 'pro') : null;

        $ref = new ReflectionProperty(Mcpwp_License::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        $this->assertSame($pro, Mcpwp_License::get_instance()->is_pro(), 'tier precondition');
        Mcpwp_Integration::clear_cache();
    }

    private function configureFigma(): void
    {
        $this->assertTrue(Mcpwp_Integration_Manager::get_instance()->set_provider_config('figma', array(
            'personal_access_token' => 'figd_stored-on-any-tier',
            'default_file_key'      => 'FILEKEY123',
        )));
    }

    /**
     * get_introspection_data() reads site info through Mcpwp_Core. The shared
     * bootstrap has no wp_get_theme(), wp_timezone_string() or get_locale();
     * they are declared here, inside separate-process tests only, so they
     * cannot leak into the shared run.
     */
    private function stubThemeLookup(): void
    {
        if (! function_exists('wp_timezone_string')) {
            function wp_timezone_string()
            {
                return 'UTC';
            }
        }
        if (! function_exists('get_locale')) {
            function get_locale()
            {
                return 'en_US';
            }
        }
        // Declared so get_detected_integrations() does not require
        // wp-admin/includes/plugin.php, which the test tree does not have.
        if (! function_exists('is_plugin_active')) {
            function is_plugin_active($plugin)
            {
                return false;
            }
        }
        if (! function_exists('wp_get_theme')) {
            function wp_get_theme()
            {
                return new class () {
                    public function get($header)
                    {
                        return 'Stub Theme';
                    }
                };
            }
        }
    }

    /**
     * Every onboarding instruction an agent reads on connect.
     */
    private function onboardingText(): string
    {
        $introspection = (new Mcpwp_REST_MCP())->get_introspection_data();

        $guide = Mcpwp_Guides::guide_onboarding();

        return implode("\n", array_merge(
            (array) $introspection['quick_start'],
            (array) $introspection['operating_rules'],
            array_column($guide['sections'], 'content')
        ));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_figma_is_named_in_onboarding_only_on_a_pro_entitled_site(): void
    {
        $this->stubThemeLookup();
        $this->configureFigma();

        $this->setTier(false);
        $free = $this->onboardingText();

        $this->assertStringNotContainsStringIgnoringCase('figma', $free, 'a Free agent is never sent to a tool it cannot list or call');

        $this->setTier(true);
        $pro = $this->onboardingText();

        $this->assertStringContainsString('wp_figma_status', $pro, 'a Pro site with Figma configured is still told to use it');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_pro_site_without_figma_configured_is_not_told_to_call_it_from_introspection(): void
    {
        $this->stubThemeLookup();
        $this->setTier(true);

        $introspection = (new Mcpwp_REST_MCP())->get_introspection_data();

        $this->assertStringNotContainsStringIgnoringCase('figma', implode("\n", $introspection['quick_start']));

        // Pro but unconfigured already said nothing: the entitlement check is
        // added to the configured check, it does not replace it. Configured
        // but Free is the defect — the quick start named wp_figma_status.
        $this->configureFigma();
        $this->setTier(false);
        $this->assertStringNotContainsStringIgnoringCase('figma', implode("\n", (new Mcpwp_REST_MCP())->get_introspection_data()['quick_start']));
    }
}
