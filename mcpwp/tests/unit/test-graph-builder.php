<?php
/**
 * Site graph builder — extraction, incremental dirty handling, hash-skip,
 * and backfill cursor progress (#943 S1).
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/core/class-mcpwp-graph-builder.php';

final class GraphBuilderTest extends TestCase
{
    private $saved = array();

    protected function setUp(): void
    {
        foreach (array(
            '_mcpwp_test_posts',
            '_mcpwp_test_meta',
            '_mcpwp_test_taxonomies',
            '_mcpwp_test_object_terms',
            '_mcpwp_test_thumbnail_ids',
            'mcpwp_test_options',
            'mcpwp_test_graph_nodes',
            'mcpwp_test_graph_edges',
            'mcpwp_test_public_post_types',
        ) as $global) {
            $this->saved[ $global ] = $GLOBALS[ $global ] ?? array();
            $GLOBALS[ $global ]     = array();
        }
        Mcpwp_Graph_Builder::reset_pending_dirty_nodes();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $global => $value) {
            $GLOBALS[ $global ] = $value;
        }
        Mcpwp_Graph_Builder::reset_pending_dirty_nodes();
    }

    private function post(int $id, array $overrides = array()): WP_Post
    {
        $post = new WP_Post(array_merge(array(
            'ID'           => $id,
            'post_type'    => 'post',
            'post_status'  => 'publish',
            'post_title'   => 'Post ' . $id,
            'post_name'    => 'post-' . $id,
            'post_content' => '',
            'post_parent'  => 0,
        ), $overrides));
        $GLOBALS['_mcpwp_test_posts'][ $id ] = $post;
        return $post;
    }

    // ---------------------------------------------------------------------
    // Extraction: content links.
    // ---------------------------------------------------------------------

    public function test_extract_post_link_targets_from_content_link(): void
    {
        $target = $this->post(20);
        $source = $this->post(10, array(
            'post_content' => '<p>See <a href="https://example.com/?p=20">this</a>.</p>',
        ));

        $targets = Mcpwp_Graph_Builder::extract_post_link_targets($source);

        $this->assertSame(array(20), $targets);
    }

    public function test_extract_post_link_targets_ignores_external_and_self_links(): void
    {
        $this->post(20);
        $source = $this->post(10, array(
            'post_content' => '<a href="https://external.example/x">ext</a> '
                . '<a href="https://example.com/?p=10">self</a> '
                . '<a href="https://example.com/?p=20">target</a>',
        ));

        $targets = Mcpwp_Graph_Builder::extract_post_link_targets($source);

        $this->assertSame(array(20), $targets);
    }

    // ---------------------------------------------------------------------
    // Extraction: Elementor links.
    // ---------------------------------------------------------------------

    public function test_extract_post_link_targets_from_elementor_button(): void
    {
        $this->post(30);
        $source = $this->post(11);
        $GLOBALS['_mcpwp_test_meta'][11]['_elementor_data'] = wp_json_encode(array(
            array(
                'id'       => 'sec0001',
                'elType'   => 'section',
                'settings' => array(),
                'elements' => array(
                    array(
                        'id'       => 'btn0001',
                        'elType'   => 'widget',
                        'settings' => array(
                            'link' => array( 'url' => 'https://example.com/?p=30' ),
                        ),
                    ),
                ),
            ),
        ));

        $targets = Mcpwp_Graph_Builder::extract_post_link_targets($source);

        $this->assertSame(array(30), $targets);
    }

    // ---------------------------------------------------------------------
    // Extraction: taxonomy terms.
    // ---------------------------------------------------------------------

    public function test_extract_term_refs(): void
    {
        $GLOBALS['_mcpwp_test_taxonomies']['category'] = array(
            'object_type' => array( 'post' ),
            'terms'       => array( 5 => array( 'slug' => 'news', 'name' => 'News' ) ),
        );
        $post = $this->post(12);
        $GLOBALS['_mcpwp_test_object_terms'][12]['category'] = array( 5 );

        $refs = Mcpwp_Graph_Builder::extract_term_refs($post);

        $this->assertSame(array(
            array( 'taxonomy' => 'category', 'term_id' => 5, 'name' => 'News' ),
        ), $refs);
    }

    // ---------------------------------------------------------------------
    // Extraction: media.
    // ---------------------------------------------------------------------

    public function test_extract_media_refs_featured_inline_and_elementor(): void
    {
        $GLOBALS['_mcpwp_test_thumbnail_ids'][13] = 55;
        $post = $this->post(13, array(
            'post_content' => '<img class="wp-image-77" src="x.jpg" />',
        ));
        $GLOBALS['_mcpwp_test_meta'][13]['_elementor_data'] = wp_json_encode(array(
            array(
                'id'       => 'img0001',
                'elType'   => 'widget',
                'settings' => array(
                    'image' => array( 'id' => 88 ),
                ),
            ),
        ));

        $ids = Mcpwp_Graph_Builder::extract_media_refs($post);
        sort($ids);

        $this->assertSame(array( 55, 77, 88 ), $ids);
    }

    // ---------------------------------------------------------------------
    // rebuild_post_node: full write + hash skip + delete.
    // ---------------------------------------------------------------------

    public function test_rebuild_post_node_writes_node_and_edges(): void
    {
        $this->post(21);
        $GLOBALS['_mcpwp_test_taxonomies']['category'] = array(
            'object_type' => array( 'post' ),
            'terms'       => array( 5 => array( 'slug' => 'news', 'name' => 'News' ) ),
        );
        $GLOBALS['_mcpwp_test_object_terms'][14]['category'] = array( 5 );
        $this->post(14, array(
            'post_content' => '<a href="https://example.com/?p=21">Link</a>',
        ));

        Mcpwp_Graph_Builder::rebuild_post_node(14);

        $this->assertArrayHasKey('post:14', $GLOBALS['mcpwp_test_graph_nodes']);
        $this->assertSame('post', $GLOBALS['mcpwp_test_graph_nodes']['post:14']['kind']);

        $types = array_column($GLOBALS['mcpwp_test_graph_edges'], 'type');
        $this->assertContains('links_to', $types);
        $this->assertContains('in_term', $types);
        $this->assertArrayHasKey('term:category:5', $GLOBALS['mcpwp_test_graph_nodes']);
    }

    public function test_rebuild_post_node_skips_when_content_and_terms_unchanged(): void
    {
        $post = $this->post(15, array( 'post_title' => 'Stable', 'post_content' => 'Body text' ));

        // First rebuild establishes the stored hash.
        Mcpwp_Graph_Builder::rebuild_post_node(15);
        $stored_hash = $GLOBALS['mcpwp_test_graph_nodes']['post:15']['content_hash'];
        $this->assertNotSame('', $stored_hash);

        // Plant a stale builder-sourced edge that a real rebuild would delete.
        $GLOBALS['mcpwp_test_graph_edges'][] = array(
            'from_key'   => 'post:15',
            'to_key'     => 'post:999',
            'type'       => 'links_to',
            'weight'     => 1.0,
            'source'     => 'builder',
            'props'      => '{}',
            'updated_at' => '2020-01-01 00:00:00',
        );

        // Nothing about the post changed — rebuilding again must be a no-op.
        Mcpwp_Graph_Builder::rebuild_post_node(15);

        $this->assertCount(1, $GLOBALS['mcpwp_test_graph_edges'], 'A skipped rebuild must not touch existing edges.');
        $this->assertSame('post:999', $GLOBALS['mcpwp_test_graph_edges'][0]['to_key']);
    }

    public function test_rebuild_post_node_rebuilds_when_content_changes(): void
    {
        $this->post(16, array( 'post_content' => 'Original' ));
        Mcpwp_Graph_Builder::rebuild_post_node(16);
        $first_hash = $GLOBALS['mcpwp_test_graph_nodes']['post:16']['content_hash'];

        $this->post(16, array( 'post_content' => 'Changed content' ));
        Mcpwp_Graph_Builder::rebuild_post_node(16);
        $second_hash = $GLOBALS['mcpwp_test_graph_nodes']['post:16']['content_hash'];

        $this->assertNotSame($first_hash, $second_hash);
    }

    public function test_rebuild_post_node_deletes_node_and_edges_when_post_missing(): void
    {
        $GLOBALS['mcpwp_test_graph_nodes']['post:17'] = array(
            'node_key' => 'post:17', 'kind' => 'post', 'ref_id' => 17, 'label' => 'Gone',
            'url' => '', 'status' => 'publish', 'content_hash' => 'x', 'word_count' => 0,
            'props' => '{}', 'updated_at' => '2020-01-01 00:00:00',
        );
        $GLOBALS['mcpwp_test_graph_edges'][] = array(
            'from_key' => 'post:17', 'to_key' => 'post:1', 'type' => 'links_to',
            'weight' => 1.0, 'source' => 'builder', 'props' => '{}', 'updated_at' => '2020-01-01 00:00:00',
        );

        Mcpwp_Graph_Builder::rebuild_post_node(17); // No post 17 seeded — "deleted".

        $this->assertArrayNotHasKey('post:17', $GLOBALS['mcpwp_test_graph_nodes']);
        $this->assertCount(0, $GLOBALS['mcpwp_test_graph_edges']);
    }

    // ---------------------------------------------------------------------
    // Incremental: dirty set + shutdown rebuild + overflow.
    // ---------------------------------------------------------------------

    public function test_shutdown_rebuilds_up_to_limit_and_overflows_the_rest(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->post($i);
            Mcpwp_Graph_Builder::mark_dirty(Mcpwp_Graph_Builder::node_key_for_post($i));
        }

        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertCount(Mcpwp_Graph_Builder::SHUTDOWN_LIMIT, $GLOBALS['mcpwp_test_graph_nodes']);

        $overflow = get_option(Mcpwp_Graph_Builder::DIRTY_OPTION, array());
        $this->assertCount(30 - Mcpwp_Graph_Builder::SHUTDOWN_LIMIT, $overflow);
        $this->assertSame(array(), Mcpwp_Graph_Builder::get_pending_dirty_nodes());
    }

    public function test_shutdown_with_nothing_dirty_is_a_no_op(): void
    {
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_nodes']);
        $this->assertFalse(get_option(Mcpwp_Graph_Builder::DIRTY_OPTION, false));
    }

    public function test_drain_dirty_batch_processes_overflow_option(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post($i);
        }
        update_option(Mcpwp_Graph_Builder::DIRTY_OPTION, array(
            'post:1', 'post:2', 'post:3', 'post:4', 'post:5',
        ), false);

        Mcpwp_Graph_Builder::drain_dirty_batch();

        $this->assertCount(5, $GLOBALS['mcpwp_test_graph_nodes']);
        $this->assertSame(array(), get_option(Mcpwp_Graph_Builder::DIRTY_OPTION, array()));
    }

    public function test_on_save_post_ignores_revisions_and_autosaves(): void
    {
        $this->post(40, array( 'post_type' => 'revision', 'post_parent' => 39 ));
        Mcpwp_Graph_Builder::on_save_post(40);
        $this->assertSame(array(), Mcpwp_Graph_Builder::get_pending_dirty_nodes());

        $this->post(41, array( 'post_name' => '41-autosave-v1' ));
        Mcpwp_Graph_Builder::on_save_post(41);
        $this->assertSame(array(), Mcpwp_Graph_Builder::get_pending_dirty_nodes());

        $this->post(42);
        Mcpwp_Graph_Builder::on_save_post(42);
        $this->assertSame(array( 'post:42' ), Mcpwp_Graph_Builder::get_pending_dirty_nodes());
    }

    // ---------------------------------------------------------------------
    // Backfill cursor progress.
    // ---------------------------------------------------------------------

    public function test_backfill_batches_advance_cursor_and_finish(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->post($i);
        }

        Mcpwp_Graph_Builder::on_upgrade_schedule_backfill();
        $this->assertSame('pending', get_option(Mcpwp_Graph_Builder::BACKFILL_STATUS_OPTION, ''));

        Mcpwp_Graph_Builder::run_backfill_batch();
        $this->assertSame(50, get_option(Mcpwp_Graph_Builder::BACKFILL_CURSOR_OPTION, 0));
        $this->assertSame('running', get_option(Mcpwp_Graph_Builder::BACKFILL_STATUS_OPTION, ''));
        $this->assertCount(50, $GLOBALS['mcpwp_test_graph_nodes']);

        Mcpwp_Graph_Builder::run_backfill_batch();
        $this->assertSame(100, get_option(Mcpwp_Graph_Builder::BACKFILL_CURSOR_OPTION, 0));
        $this->assertCount(100, $GLOBALS['mcpwp_test_graph_nodes']);

        Mcpwp_Graph_Builder::run_backfill_batch();
        $this->assertSame(120, get_option(Mcpwp_Graph_Builder::BACKFILL_CURSOR_OPTION, 0));
        $this->assertSame('done', get_option(Mcpwp_Graph_Builder::BACKFILL_STATUS_OPTION, ''));
        $this->assertCount(120, $GLOBALS['mcpwp_test_graph_nodes']);

        $status = Mcpwp_Graph_Builder::backfill_status();
        $this->assertSame('done', $status['status']);
        $this->assertSame(120, $status['cursor']);
    }

    public function test_backfill_already_done_is_a_no_op(): void
    {
        update_option(Mcpwp_Graph_Builder::BACKFILL_STATUS_OPTION, 'done', false);
        $this->post(1);

        Mcpwp_Graph_Builder::run_backfill_batch();

        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_nodes']);
    }
}
