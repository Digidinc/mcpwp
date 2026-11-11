<?php
/**
 * Mcpwp_Uninstaller — the older tables (activity log, webhooks, webhook logs,
 * feedback, action log) and core options are dropped on every build (#995).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-mcpwp-uninstaller.php';

final class LegacyUninstallerTest extends TestCase
{
    private $saved = array();

    protected function setUp(): void
    {
        foreach (array(
            'mcpwp_test_options',
            'mcpwp_test_transients',
            'mcpwp_test_cron',
            'mcpwp_test_is_multisite',
            'mcpwp_test_sites',
            'mcpwp_test_blog_stack',
            'mcpwp_test_current_blog_id',
        ) as $global) {
            $this->saved[ $global ] = $GLOBALS[ $global ] ?? array();
            $GLOBALS[ $global ]     = array();
        }
        $GLOBALS['mcpwp_test_current_blog_id'] = 1;
        $GLOBALS['wpdb']->prefix  = 'wp_';
        $GLOBALS['wpdb']->queries = array();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $global => $value) {
            $GLOBALS[ $global ] = $value;
        }
        $GLOBALS['wpdb']->prefix = 'wp_';
    }

    private function dropped_tables(): array
    {
        $dropped = array();
        foreach ($GLOBALS['wpdb']->queries as $q) {
            if (preg_match('/^DROP TABLE IF EXISTS (\S+)$/', trim($q), $m)) {
                $dropped[] = $m[1];
            }
        }
        return $dropped;
    }

    public function test_drops_exactly_the_five_older_tables_by_exact_name(): void
    {
        Mcpwp_Uninstaller::drop_legacy_data_for_current_site();

        $this->assertSame(
            array('wp_mcpwp_activity_log', 'wp_mcpwp_webhooks', 'wp_mcpwp_webhook_logs', 'wp_mcpwp_feedback', 'wp_mcpwp_action_log'),
            $this->dropped_tables()
        );
        // No pattern matching, no other statement kinds.
        foreach ($GLOBALS['wpdb']->queries as $q) {
            $this->assertStringNotContainsString('LIKE', $q);
            $this->assertStringNotContainsString('%', $q);
            $this->assertStringNotContainsString('wp_posts', $q);
            $this->assertStringNotContainsString('DELETE', strtoupper($q));
        }
    }

    public function test_removes_options_transients_and_cron_and_keeps_foreign_ones(): void
    {
        foreach (Mcpwp_Uninstaller::LEGACY_OPTIONS as $option) {
            update_option($option, 'x');
        }
        update_option('other_plugin_mcpwp_lookalike', 'keep');
        update_option('mcpwp_other_plugin_option', 'keep');
        $GLOBALS['mcpwp_test_transients']['mcpwp_capabilities_cache'] = 1;
        $GLOBALS['mcpwp_test_cron']['mcpwp_cleanup_logs']             = 1;
        $GLOBALS['mcpwp_test_cron']['mcpwp_check_alerts']             = 1;
        $GLOBALS['mcpwp_test_cron']['someone_elses_hook']             = 1;

        Mcpwp_Uninstaller::drop_legacy_data_for_current_site();

        foreach (Mcpwp_Uninstaller::LEGACY_OPTIONS as $option) {
            $this->assertArrayNotHasKey($option, $GLOBALS['mcpwp_test_options']);
        }
        $this->assertSame('keep', get_option('other_plugin_mcpwp_lookalike'));
        $this->assertSame('keep', get_option('mcpwp_other_plugin_option'));
        $this->assertArrayNotHasKey('mcpwp_capabilities_cache', $GLOBALS['mcpwp_test_transients']);
        $this->assertArrayNotHasKey('mcpwp_cleanup_logs', $GLOBALS['mcpwp_test_cron']);
        $this->assertArrayNotHasKey('mcpwp_check_alerts', $GLOBALS['mcpwp_test_cron']);
        $this->assertArrayHasKey('someone_elses_hook', $GLOBALS['mcpwp_test_cron']);
    }

    public function test_is_idempotent(): void
    {
        Mcpwp_Uninstaller::drop_legacy_data_for_current_site();
        Mcpwp_Uninstaller::drop_legacy_data_for_current_site();

        $this->assertCount(10, $this->dropped_tables());
        foreach ($GLOBALS['wpdb']->queries as $q) {
            $this->assertStringContainsString('IF EXISTS', $q);
        }
    }

    public function test_single_site_does_not_loop(): void
    {
        Mcpwp_Uninstaller::drop_legacy_data();

        $this->assertCount(5, $this->dropped_tables());
        $this->assertNotContains('wp_2_mcpwp_activity_log', $this->dropped_tables());
    }

    public function test_multisite_drops_every_sites_tables_and_restores_the_blog(): void
    {
        $GLOBALS['mcpwp_test_is_multisite'] = true;
        $GLOBALS['mcpwp_test_sites']        = array(1, 2, 3);

        Mcpwp_Uninstaller::drop_legacy_data();

        $dropped = $this->dropped_tables();
        $this->assertCount(15, $dropped);
        foreach (array('wp_', 'wp_2_', 'wp_3_') as $prefix) {
            foreach (Mcpwp_Uninstaller::LEGACY_TABLES as $suffix) {
                $this->assertContains($prefix . $suffix, $dropped);
            }
        }
        $this->assertSame(1, get_current_blog_id());
        $this->assertSame('wp_', $GLOBALS['wpdb']->prefix);
    }

    public function test_uninstall_php_runs_the_shared_cleanup_for_the_older_tables(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
        $this->assertStringContainsString('Mcpwp_Uninstaller::drop_legacy_data();', $src);
        $this->assertStringContainsString("defined( 'WP_UNINSTALL_PLUGIN' )", $src);
    }

    public function test_freemius_cleanup_uses_the_same_shared_function_and_no_inline_drops(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/freemius-init.php');
        $this->assertStringContainsString('Mcpwp_Uninstaller::drop_legacy_data_for_current_site();', $src);
        $this->assertStringNotContainsString('DROP TABLE', $src);
    }

    public function test_deactivation_never_drops_the_older_tables(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/class-mcpwp-deactivator.php');
        $this->assertStringNotContainsString('DROP TABLE', $src);
        $this->assertStringNotContainsString('Mcpwp_Uninstaller', $src);
    }
}
