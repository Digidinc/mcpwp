<?php
/**
 * Mcpwp_Uninstaller — the shared graph-drop helper both uninstall.php and
 * Freemius's after_uninstall cleanup call (#943 S1 review round 2, BLOCK-1).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/class-mcpwp-uninstaller.php';

final class GraphUninstallerTest extends TestCase
{
    private $saved = array();

    protected function setUp(): void
    {
        foreach (array(
            'mcpwp_test_graph_nodes',
            'mcpwp_test_graph_edges',
            'mcpwp_test_graph_signals',
            'mcpwp_test_graph_queue',
            'mcpwp_test_options',
            'mcpwp_test_actions',
            'mcpwp_test_is_multisite',
            'mcpwp_test_sites',
            'mcpwp_test_blog_stack',
            'mcpwp_test_current_blog_id',
        ) as $global) {
            $this->saved[ $global ] = $GLOBALS[ $global ] ?? array();
            $GLOBALS[ $global ]     = array();
        }
        $GLOBALS['mcpwp_test_current_blog_id'] = 1;
        $GLOBALS['wpdb']->prefix = 'wp_';
        $GLOBALS['wpdb']->queries = array();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $global => $value) {
            $GLOBALS[ $global ] = $value;
        }
        $GLOBALS['wpdb']->prefix = 'wp_';
    }

    public function test_drop_graph_data_for_current_site_drops_tables_options_and_schedules(): void
    {
        $GLOBALS['mcpwp_test_graph_nodes'] = array('post:1' => array());
        $GLOBALS['mcpwp_test_graph_edges'] = array(array());
        update_option('mcpwp_graph_ready', true);
        update_option('mcpwp_graph_backfill_status', 'done');
        update_option('mcpwp_graph_built_at', '2026-01-01 00:00:00');

        Mcpwp_Uninstaller::drop_graph_data_for_current_site();

        $queries = implode("\n", $GLOBALS['wpdb']->queries);
        foreach (Mcpwp_Uninstaller::GRAPH_TABLES as $table) {
            $this->assertStringContainsString('DROP TABLE IF EXISTS wp_' . $table, $queries);
        }
        foreach (Mcpwp_Uninstaller::GRAPH_OPTIONS as $option) {
            $this->assertArrayNotHasKey($option, $GLOBALS['mcpwp_test_options']);
        }
    }

    public function test_drop_graph_data_for_current_site_lists_exactly_this_prs_names(): void
    {
        // BLOCK-1d: the OLD inline copy in freemius-init.php still had
        // mcpwp_graph_dirty/mcpwp_graph_dirty_drain (renamed away from in
        // this same PR) and no queue table at all — this locks the two
        // lists to what the current code actually uses.
        $this->assertContains('mcpwp_graph_queue', Mcpwp_Uninstaller::GRAPH_TABLES);
        $this->assertNotContains('mcpwp_graph_dirty', Mcpwp_Uninstaller::GRAPH_OPTIONS);
        $this->assertContains('mcpwp_graph_worker', Mcpwp_Uninstaller::GRAPH_CRON_HOOKS);
        $this->assertNotContains('mcpwp_graph_dirty_drain', Mcpwp_Uninstaller::GRAPH_CRON_HOOKS);
    }

    public function test_drop_graph_data_iterates_every_site_on_multisite(): void
    {
        $GLOBALS['mcpwp_test_is_multisite'] = true;
        $GLOBALS['mcpwp_test_sites'] = array(1, 2, 3);

        Mcpwp_Uninstaller::drop_graph_data();

        $queries = implode("\n", $GLOBALS['wpdb']->queries);
        $this->assertStringContainsString('DROP TABLE IF EXISTS wp_mcpwp_graph_nodes', $queries);
        $this->assertStringContainsString('DROP TABLE IF EXISTS wp_2_mcpwp_graph_nodes', $queries);
        $this->assertStringContainsString('DROP TABLE IF EXISTS wp_3_mcpwp_graph_nodes', $queries);

        // Must end back on the original blog.
        $this->assertSame(1, get_current_blog_id());
        $this->assertSame('wp_', $GLOBALS['wpdb']->prefix);
    }

    public function test_unschedules_action_scheduler_actions_when_present(): void
    {
        $called = array();
        // as_unschedule_all_actions is not part of the shared bootstrap
        // (Action Scheduler is an optional dependency this plugin only ever
        // detects via function_exists()) — define it just for this test.
        if (! function_exists('as_unschedule_all_actions')) {
            function as_unschedule_all_actions($hook)
            {
                $GLOBALS['mcpwp_test_as_unscheduled'][] = $hook;
            }
        }
        $GLOBALS['mcpwp_test_as_unscheduled'] = array();

        Mcpwp_Uninstaller::drop_graph_data_for_current_site();

        foreach (Mcpwp_Uninstaller::GRAPH_CRON_HOOKS as $hook) {
            $this->assertContains($hook, $GLOBALS['mcpwp_test_as_unscheduled']);
        }
    }
}
