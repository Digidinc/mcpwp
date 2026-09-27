<?php
/**
 * Mcpwp_Graph_Signals::upsert() idempotency + retention prune (#943 S1).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/core/class-mcpwp-graph-signals.php';

final class GraphSignalsTest extends TestCase
{
    private $saved = array();

    protected function setUp(): void
    {
        foreach (array( 'mcpwp_test_graph_signals', 'mcpwp_test_filters' ) as $global) {
            $this->saved[ $global ] = $GLOBALS[ $global ] ?? array();
            $GLOBALS[ $global ]     = array();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $global => $value) {
            $GLOBALS[ $global ] = $value;
        }
    }

    public function test_upsert_replaces_rather_than_duplicates(): void
    {
        $written = Mcpwp_Graph_Signals::upsert(array(
            array(
                'node_key'     => 'post:1',
                'metric'       => 'clicks',
                'value'        => 4,
                'period_start' => '2026-09-01',
                'period_end'   => '2026-09-01',
            ),
        ), 'gsc');

        $this->assertSame(1, $written);
        $this->assertCount(1, $GLOBALS['mcpwp_test_graph_signals']);

        // Same node_key + metric + source + period — must replace, not add.
        Mcpwp_Graph_Signals::upsert(array(
            array(
                'node_key'     => 'post:1',
                'metric'       => 'clicks',
                'value'        => 9,
                'period_start' => '2026-09-01',
                'period_end'   => '2026-09-01',
            ),
        ), 'gsc');

        $this->assertCount(1, $GLOBALS['mcpwp_test_graph_signals'], 'A re-import of the same key must upsert, never duplicate.');
        $this->assertSame(9.0, $GLOBALS['mcpwp_test_graph_signals'][0]['value']);
    }

    public function test_upsert_distinguishes_by_source_and_period(): void
    {
        Mcpwp_Graph_Signals::upsert(array(
            array( 'node_key' => 'post:1', 'metric' => 'clicks', 'value' => 1, 'period_start' => '2026-09-01', 'period_end' => '2026-09-01' ),
        ), 'gsc');
        Mcpwp_Graph_Signals::upsert(array(
            array( 'node_key' => 'post:1', 'metric' => 'clicks', 'value' => 2, 'period_start' => '2026-09-01', 'period_end' => '2026-09-01' ),
        ), 'bing');
        Mcpwp_Graph_Signals::upsert(array(
            array( 'node_key' => 'post:1', 'metric' => 'clicks', 'value' => 3, 'period_start' => '2026-09-02', 'period_end' => '2026-09-02' ),
        ), 'gsc');

        $this->assertCount(3, $GLOBALS['mcpwp_test_graph_signals']);
    }

    public function test_upsert_skips_rows_missing_node_key_or_metric(): void
    {
        $written = Mcpwp_Graph_Signals::upsert(array(
            array( 'node_key' => '', 'metric' => 'clicks', 'value' => 1 ),
            array( 'node_key' => 'post:1', 'metric' => '', 'value' => 1 ),
        ), 'gsc');

        $this->assertSame(0, $written);
        $this->assertCount(0, $GLOBALS['mcpwp_test_graph_signals']);
    }

    public function test_prune_removes_only_rows_older_than_retention(): void
    {
        $GLOBALS['mcpwp_test_graph_signals'] = array(
            array( 'node_key' => 'post:1', 'metric' => 'clicks', 'value' => 1, 'period_start' => '2020-01-01', 'period_end' => '2020-01-01', 'source' => 'gsc', 'fetched_at' => '2020-01-01 00:00:00' ),
            array( 'node_key' => 'post:2', 'metric' => 'clicks', 'value' => 1, 'period_start' => date('Y-m-d'), 'period_end' => date('Y-m-d'), 'source' => 'gsc', 'fetched_at' => '2020-01-01 00:00:00' ),
        );

        Mcpwp_Graph_Signals::prune();

        $this->assertCount(1, $GLOBALS['mcpwp_test_graph_signals']);
        $this->assertSame('post:2', $GLOBALS['mcpwp_test_graph_signals'][0]['node_key']);
    }

    public function test_prune_retention_is_filterable(): void
    {
        $recent = date('Y-m-d', time() - 5 * 86400);
        $GLOBALS['mcpwp_test_graph_signals'] = array(
            array( 'node_key' => 'post:1', 'metric' => 'clicks', 'value' => 1, 'period_start' => $recent, 'period_end' => $recent, 'source' => 'gsc', 'fetched_at' => '2020-01-01 00:00:00' ),
        );

        add_filter('mcpwp_graph_signal_retention_days', function () {
            return 1;
        });

        Mcpwp_Graph_Signals::prune();

        $this->assertCount(0, $GLOBALS['mcpwp_test_graph_signals']);
    }
}
