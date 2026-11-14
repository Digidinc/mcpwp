<?php
/**
 * Mcpwp_Uninstaller — the older tables (activity log, webhooks, webhook logs,
 * feedback, action log) and core options are dropped on every build (#995).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-mcpwp-uninstaller.php';
require_once dirname(__DIR__, 2) . '/includes/core/class-mcpwp-key-state.php';
require_once dirname(__DIR__, 2) . '/includes/class-mcpwp-duplicate-copy.php';

class Mcpwp_Legacy_Uninstall_Test_Auth
{
    use Mcpwp_Api_Auth;
}

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

    public function test_removes_the_key_store_last_used_and_lock_options(): void
    {
        foreach (array('mcpwp_api_keys_last_used', 'mcpwp_api_keys_lock') as $option) {
            $this->assertContains($option, Mcpwp_Uninstaller::LEGACY_OPTIONS);
            update_option($option, 'x');
        }
        update_option('mcpwp_api_keys_other_plugin_lookalike', 'keep');

        Mcpwp_Uninstaller::drop_legacy_data_for_current_site();

        $this->assertArrayNotHasKey('mcpwp_api_keys_last_used', $GLOBALS['mcpwp_test_options']);
        $this->assertArrayNotHasKey('mcpwp_api_keys_lock', $GLOBALS['mcpwp_test_options']);
        $this->assertSame('keep', get_option('mcpwp_api_keys_other_plugin_lookalike'));
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

    public function test_the_action_log_prune_cron_is_cleared(): void
    {
        $GLOBALS['mcpwp_test_cron']['mcpwp_action_log_daily_prune'] = 1;

        Mcpwp_Uninstaller::drop_legacy_data_for_current_site();

        $this->assertArrayNotHasKey('mcpwp_action_log_daily_prune', $GLOBALS['mcpwp_test_cron']);
    }

    public function test_a_revoked_bridged_key_stays_dead_after_uninstall_and_reinstall(): void
    {
        $auth = new Mcpwp_Legacy_Uninstall_Test_Auth();
        $key  = $auth->regenerate_api_key();
        $rows = get_option('mcpwp_api_keys');
        $this->assertNotEmpty($rows);
        // The 2.8 migration copied the same hash into spai_api_keys and never removed it.
        update_option('spai_api_keys', $rows);
        update_option('spai_api_key', reset($rows)['hash']);
        foreach ($auth->list_scoped_api_keys(false) as $record) {
            $auth->revoke_scoped_api_key($record['id']);
        }
        $request = new WP_REST_Request('GET', '/mcpwp/v1/site-info', array(), array('X-API-Key' => $key));
        $this->assertInstanceOf(WP_Error::class, $auth->verify_api_key($request));

        Mcpwp_Uninstaller::drop_legacy_data_for_current_site(); // uninstall: revocation record goes too.

        // Reinstall: fresh install, nothing left to adopt the old key from.
        $GLOBALS['mcpwp_test_transients'] = array();
        if (method_exists('Mcpwp_REST_MCP', 'reset_verdict_memo')) {
            Mcpwp_REST_MCP::reset_verdict_memo();
        }
        $fresh   = new Mcpwp_Legacy_Uninstall_Test_Auth();
        $request = new WP_REST_Request('GET', '/mcpwp/v1/site-info', array(), array('X-API-Key' => $key));
        $this->assertInstanceOf(WP_Error::class, $fresh->verify_api_key($request));
        foreach (array('spai_api_keys', 'spai_api_key', 'spai_pro_license', 'spai_trial_started') as $option) {
            $this->assertContains($option, Mcpwp_Uninstaller::LEGACY_OPTIONS);
        }
    }

    public function test_stored_provider_keys_are_removed(): void
    {
        update_option('mcpwp_integrations', array('openai' => 'enc'));

        Mcpwp_Uninstaller::drop_legacy_data_for_current_site();

        $this->assertArrayNotHasKey('mcpwp_integrations', $GLOBALS['mcpwp_test_options']);
    }

    private function header(string $name, string $text_domain): array
    {
        return array('Name' => $name, 'TextDomain' => $text_domain);
    }

    public function test_another_installed_copy_is_identified_by_name_and_text_domain_and_self_is_ignored(): void
    {
        $plugins = array(
            'mcpwp/mcpwp.php'     => $this->header('MCPWP', 'mcpwp'),
            'akismet/akismet.php' => $this->header('Akismet', 'akismet'),
        );
        $this->assertFalse(Mcpwp_Uninstaller::another_copy_installed($plugins, 'mcpwp/mcpwp.php'));

        $plugins['mcpwp-old/mcpwp.php'] = $this->header('MCPWP', 'mcpwp');
        $this->assertTrue(Mcpwp_Uninstaller::another_copy_installed($plugins, 'mcpwp/mcpwp.php'));
        $this->assertTrue(Mcpwp_Uninstaller::another_copy_installed($plugins, 'mcpwp-old/mcpwp.php'));
    }

    public function test_a_premium_copy_counts_but_lookalikes_and_addons_do_not(): void
    {
        $self = 'mcpwp-premium/mcpwp.php';
        $this->assertTrue(Mcpwp_Uninstaller::another_copy_installed(array('mcpwp/mcpwp.php' => $this->header('MCPWP', 'mcpwp')), $self));
        $this->assertTrue(Mcpwp_Uninstaller::another_copy_installed(array('x/mcpwp.php' => $this->header('MCPWP Premium', 'mcpwp')), 'mcpwp/mcpwp.php'));
        // Same text domain, different plugin: must not suppress cleanup.
        $this->assertFalse(Mcpwp_Uninstaller::another_copy_installed(array('other/other.php' => $this->header('Other Plugin', 'mcpwp')), $self));
        $this->assertFalse(Mcpwp_Uninstaller::another_copy_installed(array('forms/forms.php' => $this->header('MCPWP Addon for Forms', 'mcpwp')), $self));
        // Right name, wrong text domain.
        $this->assertFalse(Mcpwp_Uninstaller::another_copy_installed(array('x/x.php' => $this->header('MCPWP', 'something-else')), $self));
    }

    public function test_every_premium_name_form_counts_as_a_copy(): void
    {
        foreach (array('MCPWP', 'MCPWP Premium', 'MCPWP (Premium)', 'mcpwp (premium)', 'MCPWP  Premium') as $name) {
            $this->assertTrue(
                Mcpwp_Uninstaller::another_copy_installed(array('mcpwp-premium/mcpwp.php' => $this->header($name, 'mcpwp')), 'mcpwp/mcpwp.php'),
                $name
            );
        }
        foreach (array('MCPWP Pro Addon', 'MCPWP (Premium) Forms', 'MCPWPX', 'Premium MCPWP') as $name) {
            $this->assertFalse(
                Mcpwp_Uninstaller::another_copy_installed(array('x/x.php' => $this->header($name, 'mcpwp')), 'mcpwp/mcpwp.php'),
                $name
            );
        }
    }

    public function test_the_duplicate_copy_guard_accepts_the_same_premium_name_forms(): void
    {
        foreach (array('MCPWP', 'MCPWP Premium', 'MCPWP (Premium)') as $name) {
            $this->assertTrue(Mcpwp_Duplicate_Copy::is_mcpwp_main_file("<?php\n/**\n * Plugin Name: {$name}\n */"), $name);
        }
        $this->assertFalse(Mcpwp_Duplicate_Copy::is_mcpwp_main_file("<?php\n/**\n * Plugin Name: MCPWP Addon for Forms\n */"));
    }

    public function test_per_site_signal_and_figma_crons_are_cleared(): void
    {
        $GLOBALS['mcpwp_test_cron']['mcpwp_compute_signals']            = 1;
        $GLOBALS['mcpwp_test_cron']['mcpwp_figma_sync_design_tokens'] = 1;

        Mcpwp_Uninstaller::drop_legacy_data_for_current_site();

        $this->assertArrayNotHasKey('mcpwp_compute_signals', $GLOBALS['mcpwp_test_cron']);
        $this->assertArrayNotHasKey('mcpwp_figma_sync_design_tokens', $GLOBALS['mcpwp_test_cron']);
    }

    public function test_a_copy_loaded_from_elsewhere_such_as_mu_plugins_is_detected(): void
    {
        $this->assertFalse(Mcpwp_Uninstaller::loaded_from_elsewhere('', '/wp/plugins/mcpwp'));
        $this->assertFalse(Mcpwp_Uninstaller::loaded_from_elsewhere('/wp/plugins/mcpwp/', '/wp/plugins/mcpwp'));
        $this->assertTrue(Mcpwp_Uninstaller::loaded_from_elsewhere('/wp/mu-plugins/mcpwp/', '/wp/plugins/mcpwp'));
        $this->assertTrue(Mcpwp_Uninstaller::loaded_from_elsewhere('/wp/plugins/mcpwp-legacy/', '/wp/plugins/mcpwp'));
    }

    public function test_the_migration_flag_is_kept_so_a_reinstall_cannot_resurrect_deleted_data(): void
    {
        // Mcpwp_Migrate::run() re-runs when the flag is missing and spai_settings
        // exists, copying the leftover spai_* options and tables back in.
        $this->assertNotContains('mcpwp_migrated_from_spai', Mcpwp_Uninstaller::LEGACY_OPTIONS);
        update_option('mcpwp_migrated_from_spai', '1');
        update_option('spai_settings', array('a' => 1));

        Mcpwp_Uninstaller::drop_legacy_data_for_current_site();

        $this->assertSame('1', get_option('mcpwp_migrated_from_spai'));
        // The key and licence bridges stay closed without the flag's help.
        foreach (array('spai_api_keys', 'spai_api_key', 'spai_pro_license', 'spai_trial_started') as $option) {
            $this->assertContains($option, Mcpwp_Uninstaller::LEGACY_OPTIONS);
        }
    }

    public function test_uninstall_php_fails_safe_for_an_older_active_copy_and_checks_other_copies_first(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');
        $this->assertStringContainsString("! class_exists( 'Mcpwp_Uninstaller' )", $src);
        $this->assertStringContainsString("method_exists( 'Mcpwp_Uninstaller', 'other_copy_present' )", $src);
        $this->assertStringContainsString('other_copy_present(', $src);
        $this->assertLessThan(strpos($src, 'drop_graph_data()'), strpos($src, 'other_copy_present('));
    }

    /**
     * The Freemius cleanup carries the same guard (deleting mcpwp-premium/
     * while a Free mcpwp/ stays must not wipe the shared data). The guard needs
     * no Freemius account; the function text is lifted from the source.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_freemius_cleanup_skips_everything_when_another_copy_is_installed(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/includes/freemius-init.php');
        $this->assertSame(1, preg_match('/\nfunction mcpwp_fs_uninstall_cleanup\(\) \{.*\z/s', $src, $m));
        if (! function_exists('plugin_basename')) {
            function plugin_basename($file)
            {
                return 'mcpwp-premium/mcpwp.php'; // The copy running the cleanup.
            }
        }
        if (! function_exists('get_plugins')) {
            function get_plugins()
            {
                return $GLOBALS['mcpwp_test_installed_plugins'];
            }
        }
        if (! defined('MCPWP_PLUGIN_DIR')) {
            define('MCPWP_PLUGIN_DIR', dirname(__DIR__)); // This copy, as mcpwp.php defines it.
        }
        // Include the lifted function from a temp file (not a dynamic-code call).
        $lifted = tempnam(sys_get_temp_dir(), 'mcpwp-fs-cleanup');
        // The function resolves paths from __DIR__; keep this file's dir, as the test assumed.
        file_put_contents($lifted, "<?php\n" . str_replace('__DIR__', var_export(__DIR__, true), $m[0]));
        include $lifted;
        unlink($lifted);

        update_option('mcpwp_api_key', 'keep-me');
        $GLOBALS['wpdb']->queries = array();

        // Free mcpwp/ stays while mcpwp-premium/ is deleted.
        $GLOBALS['mcpwp_test_installed_plugins'] = array(
            'mcpwp/mcpwp.php'         => array('Name' => 'MCPWP', 'TextDomain' => 'mcpwp'),
            'mcpwp-premium/mcpwp.php' => array('Name' => 'MCPWP Premium', 'TextDomain' => 'mcpwp'),
        );
        mcpwp_fs_uninstall_cleanup();
        $this->assertSame(array(), $GLOBALS['wpdb']->queries, 'Nothing may be dropped while another copy is installed.');
        $this->assertSame('keep-me', get_option('mcpwp_api_key'));

        // Only copy: cleanup runs.
        $GLOBALS['mcpwp_test_installed_plugins'] = array(
            'mcpwp-premium/mcpwp.php' => array('Name' => 'MCPWP Premium', 'TextDomain' => 'mcpwp'),
        );
        mcpwp_fs_uninstall_cleanup();
        $this->assertNotEmpty($GLOBALS['wpdb']->queries);
        $this->assertFalse(get_option('mcpwp_api_key', false));
    }
}
