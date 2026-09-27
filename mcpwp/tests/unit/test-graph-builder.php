<?php
/**
 * Site graph builder — extraction, queue/worker incremental handling,
 * trash/delete semantics, batched link resolution, and backfill cursor
 * progress (#943 S1, revised after PR #993 review).
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
            'mcpwp_test_graph_queue',
            'mcpwp_test_public_post_types',
            'mcpwp_test_hierarchical_post_types',
            'mcpwp_test_actions',
            'mcpwp_test_is_multisite',
            'mcpwp_test_sites',
            'mcpwp_test_blog_stack',
            'mcpwp_test_current_blog_id',
            'mcpwp_test_url_to_postid',
        ) as $global) {
            $this->saved[ $global ] = $GLOBALS[ $global ] ?? array();
            $GLOBALS[ $global ]     = array();
        }
        unset($GLOBALS['mcpwp_test_throw_for_post_id']);
        $GLOBALS['mcpwp_test_current_blog_id'] = 1;
        $GLOBALS['wpdb']->prefix = 'wp_';
        Mcpwp_Graph_Builder::reset_request_state();
        // Every hook handler no-ops unless the tables are confirmed present.
        // Matches a healthy site; readiness-guard-off is its own test below.
        update_option(Mcpwp_Graph_Builder::READY_OPTION, true, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $global => $value) {
            $GLOBALS[ $global ] = $value;
        }
        unset($GLOBALS['mcpwp_test_throw_for_post_id']);
        $GLOBALS['wpdb']->prefix = 'wp_';
        Mcpwp_Graph_Builder::reset_request_state();
    }

    private function post(int $id, array $overrides = array()): WP_Post
    {
        $post = new WP_Post(array_merge(array(
            'ID'                => $id,
            'post_type'         => 'post',
            'post_status'       => 'publish',
            'post_title'        => 'Post ' . $id,
            'post_name'         => 'post-' . $id,
            'post_content'      => '',
            'post_parent'       => 0,
            'post_modified_gmt' => '2026-01-01 00:00:00',
        ), $overrides));
        $GLOBALS['_mcpwp_test_posts'][ $id ] = $post;
        return $post;
    }

    // ---------------------------------------------------------------------
    // Extraction: content links.
    // ---------------------------------------------------------------------

    public function test_extract_post_link_targets_from_content_link(): void
    {
        $this->post(20);
        $source = $this->post(10, array(
            'post_content' => '<p>See <a href="https://example.com/?p=20">this</a>.</p>',
        ));

        $this->assertSame(array(20), Mcpwp_Graph_Builder::extract_post_link_targets($source));
    }

    public function test_extract_post_link_targets_ignores_external_and_self_links(): void
    {
        $this->post(20);
        $source = $this->post(10, array(
            'post_content' => '<a href="https://external.example/x">ext</a> '
                . '<a href="https://example.com/?p=10">self</a> '
                . '<a href="https://example.com/?p=20">target</a>',
        ));

        $this->assertSame(array(20), Mcpwp_Graph_Builder::extract_post_link_targets($source));
    }

    public function test_extract_post_link_targets_resolves_via_slug_path(): void
    {
        $this->post(25, array('post_name' => 'about-us'));
        $source = $this->post(10, array(
            'post_content' => '<a href="https://example.com/about-us/">About</a>',
        ));

        $this->assertSame(array(25), Mcpwp_Graph_Builder::extract_post_link_targets($source));
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

        $this->assertSame(array(30), Mcpwp_Graph_Builder::extract_post_link_targets($source));
    }

    // ---------------------------------------------------------------------
    // N4: edge props (anchors <=5, location).
    // ---------------------------------------------------------------------

    public function test_build_link_edges_data_collects_anchors_and_location(): void
    {
        $this->post(20);
        $source = $this->post(10, array(
            'post_content' => '<a href="https://example.com/?p=20">First</a> '
                . '<a href="https://example.com/?p=20">Second</a>',
        ));

        $data = Mcpwp_Graph_Builder::build_link_edges_data($source);

        $this->assertArrayHasKey(20, $data);
        $this->assertSame(array('First', 'Second'), $data[20]['anchors']);
        $this->assertSame(array('content' => true), $data[20]['locations']);
    }

    public function test_build_link_edges_data_caps_anchors_at_five(): void
    {
        $this->post(20);
        $links = '';
        for ($i = 1; $i <= 7; $i++) {
            $links .= '<a href="https://example.com/?p=20">Anchor ' . $i . '</a> ';
        }
        $source = $this->post(10, array('post_content' => $links));

        $data = Mcpwp_Graph_Builder::build_link_edges_data($source);

        $this->assertCount(5, $data[20]['anchors']);
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
    // BLOCK-1: change hash covers everything the rebuild writes.
    // ---------------------------------------------------------------------

    public function test_content_hash_changes_with_elementor_thumbnail_template_or_menu_order(): void
    {
        $post = $this->post(50, array('menu_order' => 0));
        $base = Mcpwp_Graph_Builder::content_hash($post);

        $GLOBALS['_mcpwp_test_meta'][50]['_elementor_data'] = 'x';
        $this->assertNotSame($base, Mcpwp_Graph_Builder::content_hash($this->post(50, array('menu_order' => 0))), 'Elementor data change must change the hash.');
        unset($GLOBALS['_mcpwp_test_meta'][50]['_elementor_data']);

        $GLOBALS['_mcpwp_test_meta'][50]['_thumbnail_id'] = '99';
        $this->assertNotSame($base, Mcpwp_Graph_Builder::content_hash($this->post(50, array('menu_order' => 0))), 'Featured image change must change the hash.');
        unset($GLOBALS['_mcpwp_test_meta'][50]['_thumbnail_id']);

        $GLOBALS['_mcpwp_test_meta'][50]['_wp_page_template'] = 'full-width.php';
        $this->assertNotSame($base, Mcpwp_Graph_Builder::content_hash($this->post(50, array('menu_order' => 0))), 'Page template change must change the hash.');
        unset($GLOBALS['_mcpwp_test_meta'][50]['_wp_page_template']);

        $this->assertNotSame($base, Mcpwp_Graph_Builder::content_hash($this->post(50, array('menu_order' => 3))), 'menu_order change must change the hash.');
    }

    public function test_content_hash_changes_with_terms(): void
    {
        $GLOBALS['_mcpwp_test_taxonomies']['category'] = array(
            'object_type' => array( 'post' ),
            'terms'       => array(
                5 => array( 'slug' => 'news', 'name' => 'News' ),
                6 => array( 'slug' => 'life', 'name' => 'Life' ),
            ),
        );
        $post = $this->post(51);
        $GLOBALS['_mcpwp_test_object_terms'][51]['category'] = array( 5 );
        $with_one_term = Mcpwp_Graph_Builder::content_hash($post);

        $GLOBALS['_mcpwp_test_object_terms'][51]['category'] = array( 5, 6 );
        $with_two_terms = Mcpwp_Graph_Builder::content_hash($post);

        $this->assertNotSame($with_one_term, $with_two_terms);
    }

    // ---------------------------------------------------------------------
    // rebuild_post_node: full write + hash skip + delete + trash.
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

    public function test_rebuild_post_node_skips_when_hash_unchanged(): void
    {
        $this->post(15, array( 'post_title' => 'Stable', 'post_content' => 'Body text' ));

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

    public function test_rebuild_post_node_deletes_node_and_edges_when_post_permanently_gone(): void
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

        Mcpwp_Graph_Builder::rebuild_post_node(17); // No post 17 seeded.

        $this->assertArrayNotHasKey('post:17', $GLOBALS['mcpwp_test_graph_nodes']);
        $this->assertCount(0, $GLOBALS['mcpwp_test_graph_edges']);
    }

    public function test_rebuild_post_node_excludes_attachments(): void
    {
        $this->post(18, array('post_type' => 'attachment'));
        Mcpwp_Graph_Builder::rebuild_post_node(18);
        $this->assertArrayNotHasKey('post:18', $GLOBALS['mcpwp_test_graph_nodes']);
    }

    // ---------------------------------------------------------------------
    // BLOCK-2: trash is not delete.
    // ---------------------------------------------------------------------

    public function test_trash_keeps_edges_and_only_updates_status(): void
    {
        $this->post(60);
        $linker = $this->post(61, array('post_content' => '<a href="https://example.com/?p=60">L</a>'));
        Mcpwp_Graph_Builder::rebuild_post_node(60);
        Mcpwp_Graph_Builder::rebuild_post_node(61);
        $this->assertNotEmpty(array_filter($GLOBALS['mcpwp_test_graph_edges'], fn($e) => 'post:61' === $e['from_key'] && 'post:60' === $e['to_key']));

        // Trash post 60 — same node_key, status transitions to trash.
        $this->post(60, array('post_status' => 'trash'));
        Mcpwp_Graph_Builder::rebuild_post_node(60);

        $this->assertSame('trash', $GLOBALS['mcpwp_test_graph_nodes']['post:60']['status']);
        $this->assertNotEmpty(
            array_filter($GLOBALS['mcpwp_test_graph_edges'], fn($e) => 'post:61' === $e['from_key'] && 'post:60' === $e['to_key']),
            'B->A must survive A being trashed.'
        );

        // Restore — status returns to normal, edge is untouched either way.
        $this->post(60, array('post_status' => 'publish'));
        Mcpwp_Graph_Builder::rebuild_post_node(60);
        $this->assertSame('publish', $GLOBALS['mcpwp_test_graph_nodes']['post:60']['status']);
        $this->assertNotEmpty(array_filter($GLOBALS['mcpwp_test_graph_edges'], fn($e) => 'post:61' === $e['from_key'] && 'post:60' === $e['to_key']));
    }

    public function test_trash_of_never_graphed_post_falls_through_to_full_rebuild(): void
    {
        $this->post(62, array('post_status' => 'trash'));
        Mcpwp_Graph_Builder::rebuild_post_node(62);
        $this->assertArrayHasKey('post:62', $GLOBALS['mcpwp_test_graph_nodes']);
        $this->assertSame('trash', $GLOBALS['mcpwp_test_graph_nodes']['post:62']['status']);
    }

    public function test_permanent_delete_requeues_dependents(): void
    {
        $this->post(70);
        $this->post(71, array('post_content' => '<a href="https://example.com/?p=70">L</a>'));
        $this->post(72, array('post_parent' => 70));
        Mcpwp_Graph_Builder::rebuild_post_node(70);
        Mcpwp_Graph_Builder::rebuild_post_node(71);
        Mcpwp_Graph_Builder::rebuild_post_node(72);

        unset($GLOBALS['_mcpwp_test_posts'][70]); // permanently gone

        Mcpwp_Graph_Builder::on_deleted_post(70);
        Mcpwp_Graph_Builder::on_shutdown(); // mark() buffers until shutdown flushes it to the queue table.

        $this->assertArrayNotHasKey('post:70', $GLOBALS['mcpwp_test_graph_nodes']);
        $queued = array_keys($GLOBALS['mcpwp_test_graph_queue']);
        $this->assertContains('post:71', $queued, 'The linking source must be requeued.');
        $this->assertContains('post:72', $queued, 'The child must be requeued.');
    }

    // ---------------------------------------------------------------------
    // N3: term node upsert must not clobber an already-correct URL.
    // ---------------------------------------------------------------------

    public function test_ensure_term_node_does_not_clobber_url(): void
    {
        $GLOBALS['_mcpwp_test_taxonomies']['category'] = array(
            'object_type' => array( 'post' ),
            'terms'       => array( 5 => array( 'slug' => 'news', 'name' => 'News' ) ),
        );
        $GLOBALS['_mcpwp_test_object_terms'][80]['category'] = array( 5 );
        $this->post(80);

        Mcpwp_Graph_Builder::rebuild_post_node(80);

        $this->assertNotSame('', $GLOBALS['mcpwp_test_graph_nodes']['term:category:5']['url']);

        $this->post(81);
        $GLOBALS['_mcpwp_test_object_terms'][81]['category'] = array( 5 );
        Mcpwp_Graph_Builder::rebuild_post_node(81);

        $this->assertNotSame('', $GLOBALS['mcpwp_test_graph_nodes']['term:category:5']['url'], 'A second post referencing the term must not blank its URL.');
    }

    // ---------------------------------------------------------------------
    // W4/N1: scope — attachments excluded, set_object_terms only for covered types.
    // ---------------------------------------------------------------------

    public function test_on_set_object_terms_ignores_non_covered_post_type(): void
    {
        $this->post(90, array('post_type' => 'attachment'));
        Mcpwp_Graph_Builder::on_set_object_terms(90);
        $this->assertArrayNotHasKey('post:90', $GLOBALS['mcpwp_test_graph_queue'] ?? array());
    }

    public function test_on_set_object_terms_marks_covered_post_type(): void
    {
        $this->post(91);
        Mcpwp_Graph_Builder::on_set_object_terms(91);
        Mcpwp_Graph_Builder::on_shutdown(); // mark() buffers until shutdown flushes it to the queue table.
        $this->assertArrayHasKey('post:91', $GLOBALS['mcpwp_test_graph_queue']);
    }

    // ---------------------------------------------------------------------
    // W3: meta-change hooks (find-replace / rollback / featured image never
    // fire save_post).
    // ---------------------------------------------------------------------

    public function test_meta_change_marks_covered_post_for_watched_keys(): void
    {
        $this->post(95);

        Mcpwp_Graph_Builder::on_post_meta_changed(1, 95, '_elementor_data');
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertArrayHasKey('post:95', $GLOBALS['mcpwp_test_graph_queue']);

        $GLOBALS['mcpwp_test_graph_queue'] = array();
        Mcpwp_Graph_Builder::on_post_meta_changed(1, 95, '_thumbnail_id');
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertArrayHasKey('post:95', $GLOBALS['mcpwp_test_graph_queue']);

        $GLOBALS['mcpwp_test_graph_queue'] = array();
        Mcpwp_Graph_Builder::on_post_meta_changed(1, 95, '_wp_page_template');
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertArrayHasKey('post:95', $GLOBALS['mcpwp_test_graph_queue']);
    }

    public function test_meta_change_ignores_unwatched_key(): void
    {
        $this->post(96);
        Mcpwp_Graph_Builder::on_post_meta_changed(1, 96, '_some_unrelated_key');
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertArrayNotHasKey('post:96', $GLOBALS['mcpwp_test_graph_queue'] ?? array());
    }

    public function test_real_meta_write_fires_the_hook_and_marks(): void
    {
        // mcpwp.php wires these in production; a unit test that only
        // requires the class file must wire them itself to exercise the
        // real update_post_meta()/delete_post_meta() -> do_action() path.
        add_action('updated_post_meta', array('Mcpwp_Graph_Builder', 'on_post_meta_changed'), 10, 3);
        add_action('added_post_meta', array('Mcpwp_Graph_Builder', 'on_post_meta_changed'), 10, 3);
        add_action('deleted_post_meta', array('Mcpwp_Graph_Builder', 'on_post_meta_changed'), 10, 3);

        $this->post(97);
        update_post_meta(97, '_thumbnail_id', '5'); // added_post_meta (new key)
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertArrayHasKey('post:97', $GLOBALS['mcpwp_test_graph_queue']);

        $GLOBALS['mcpwp_test_graph_queue'] = array();
        update_post_meta(97, '_thumbnail_id', '6'); // updated_post_meta (existing key)
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertArrayHasKey('post:97', $GLOBALS['mcpwp_test_graph_queue']);

        $GLOBALS['mcpwp_test_graph_queue'] = array();
        delete_post_meta(97, '_thumbnail_id');
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertArrayHasKey('post:97', $GLOBALS['mcpwp_test_graph_queue']);
    }

    // ---------------------------------------------------------------------
    // BLOCK-4: mark = one (request-buffered, shutdown-flushed) INSERT
    // IGNORE; save-path never rebuilds inline.
    // ---------------------------------------------------------------------

    public function test_on_save_post_only_queues_never_rebuilds(): void
    {
        $this->post(100);
        Mcpwp_Graph_Builder::on_save_post(100);
        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertArrayHasKey('post:100', $GLOBALS['mcpwp_test_graph_queue']);
        $this->assertArrayNotHasKey('post:100', $GLOBALS['mcpwp_test_graph_nodes'], 'save_post must not rebuild inline.');
    }

    public function test_on_save_post_ignores_revisions_and_autosaves(): void
    {
        $this->post(40, array( 'post_type' => 'revision', 'post_parent' => 39 ));
        Mcpwp_Graph_Builder::on_save_post(40);

        $this->post(41, array( 'post_name' => '41-autosave-v1' ));
        Mcpwp_Graph_Builder::on_save_post(41);

        $this->post(42);
        Mcpwp_Graph_Builder::on_save_post(42);

        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertArrayNotHasKey('post:40', $GLOBALS['mcpwp_test_graph_queue'] ?? array());
        $this->assertArrayNotHasKey('post:41', $GLOBALS['mcpwp_test_graph_queue'] ?? array());
        $this->assertArrayHasKey('post:42', $GLOBALS['mcpwp_test_graph_queue']);
    }

    public function test_mark_batches_a_requests_marks_into_one_flush_at_shutdown(): void
    {
        Mcpwp_Graph_Builder::mark('post:1', 'first');
        Mcpwp_Graph_Builder::mark('post:1', 'second'); // Same node re-marked — first reason wins, no duplicate.
        Mcpwp_Graph_Builder::mark('post:2', 'third');

        // Nothing written yet — mark() only buffers in memory (BLOCK-4 perf:
        // this is what makes a bulk edit's SAVE step cheap regardless of how
        // many posts are marked in one request).
        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_queue'] ?? array());

        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertSame('first', $GLOBALS['mcpwp_test_graph_queue']['post:1']['reason']);
    }

    public function test_readiness_guard_makes_every_hook_a_no_op(): void
    {
        update_option(Mcpwp_Graph_Builder::READY_OPTION, false, true);
        $this->post(101);

        Mcpwp_Graph_Builder::on_save_post(101);
        Mcpwp_Graph_Builder::mark('post:999');

        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_queue'] ?? array());
    }

    // ---------------------------------------------------------------------
    // BLOCK-4: MCPWP-authenticated request rebuilds up to 5 within budget;
    // everything else only ever queues.
    // ---------------------------------------------------------------------

    public function test_shutdown_without_authenticated_flag_does_not_rebuild(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->post($i);
            Mcpwp_Graph_Builder::mark(Mcpwp_Graph_Builder::node_key_for_post($i));
        }

        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_nodes']);
    }

    public function test_shutdown_with_authenticated_flag_rebuilds_up_to_limit(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $this->post($i);
            Mcpwp_Graph_Builder::mark(Mcpwp_Graph_Builder::node_key_for_post($i));
        }
        Mcpwp_Graph_Builder::mark_request_authenticated();

        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertCount(Mcpwp_Graph_Builder::REQUEST_REBUILD_LIMIT, $GLOBALS['mcpwp_test_graph_nodes']);
    }

    // ---------------------------------------------------------------------
    // Worker: drains the queue, time-budgeted, self-reschedules.
    // ---------------------------------------------------------------------

    public function test_worker_batch_drains_queue_and_rebuilds(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post($i);
        }
        $GLOBALS['mcpwp_test_graph_queue'] = array(
            'post:1' => array('reason' => 'x', 'queued_at' => '2026-01-01 00:00:01'),
            'post:2' => array('reason' => 'x', 'queued_at' => '2026-01-01 00:00:02'),
            'post:3' => array('reason' => 'x', 'queued_at' => '2026-01-01 00:00:03'),
            'post:4' => array('reason' => 'x', 'queued_at' => '2026-01-01 00:00:04'),
            'post:5' => array('reason' => 'x', 'queued_at' => '2026-01-01 00:00:05'),
        );

        Mcpwp_Graph_Builder::run_worker_batch();

        $this->assertCount(5, $GLOBALS['mcpwp_test_graph_nodes']);
        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_queue']);
    }

    public function test_worker_batch_is_a_no_op_when_not_ready(): void
    {
        update_option(Mcpwp_Graph_Builder::READY_OPTION, false, true);
        $GLOBALS['mcpwp_test_graph_queue'] = array('post:1' => array('reason' => 'x', 'queued_at' => 'x'));

        Mcpwp_Graph_Builder::run_worker_batch();

        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_nodes']);
    }

    // ---------------------------------------------------------------------
    // Backfill: per-post cursor save, throwing filter does not stall it.
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
        $this->assertNotSame('', get_option(Mcpwp_Graph_Builder::BUILT_AT_OPTION, ''));

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

    public function test_backfill_is_a_no_op_when_not_ready(): void
    {
        update_option(Mcpwp_Graph_Builder::READY_OPTION, false, true);
        $this->post(1);

        Mcpwp_Graph_Builder::run_backfill_batch();

        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_nodes']);
        $this->assertSame('', get_option(Mcpwp_Graph_Builder::BACKFILL_STATUS_OPTION, ''));
    }

    public function test_backfill_throwing_filter_skips_one_post_and_advances_cursor(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->post($i);
        }
        $GLOBALS['mcpwp_test_throw_for_post_id'] = 3;

        Mcpwp_Graph_Builder::run_backfill_batch();

        // Post 3's own get_post() throws inside rebuild_node_safely(), which
        // must catch it; the cursor still advances past it, and every OTHER
        // post in the batch still gets built.
        $this->assertSame(5, get_option(Mcpwp_Graph_Builder::BACKFILL_CURSOR_OPTION, 0));
        $this->assertArrayNotHasKey('post:3', $GLOBALS['mcpwp_test_graph_nodes']);
        foreach (array(1, 2, 4, 5) as $id) {
            $this->assertArrayHasKey('post:' . $id, $GLOBALS['mcpwp_test_graph_nodes']);
        }
    }

    // ---------------------------------------------------------------------
    // W8: reconcile after reactivation.
    // ---------------------------------------------------------------------

    public function test_upgrade_when_backfill_already_done_enqueues_reconcile(): void
    {
        update_option(Mcpwp_Graph_Builder::BACKFILL_STATUS_OPTION, 'done', false);
        update_option(Mcpwp_Graph_Builder::BUILT_AT_OPTION, '2026-01-01 00:00:00', false);

        $this->post(200, array('post_modified_gmt' => '2026-06-01 00:00:00')); // modified after built_at
        $this->post(201, array('post_modified_gmt' => '2025-01-01 00:00:00')); // modified before

        Mcpwp_Graph_Builder::on_upgrade_schedule_backfill();
        Mcpwp_Graph_Builder::on_shutdown(); // mark() buffers until shutdown flushes it to the queue table.

        $this->assertArrayHasKey('post:200', $GLOBALS['mcpwp_test_graph_queue']);
        $this->assertArrayNotHasKey('post:201', $GLOBALS['mcpwp_test_graph_queue'] ?? array());
        $this->assertSame('pending', get_option(Mcpwp_Graph_Builder::RECONCILE_STATUS_OPTION, ''));
    }

    public function test_reconcile_batch_marks_only_hash_mismatches(): void
    {
        $this->post(210, array('post_content' => 'same'));
        $this->post(211, array('post_content' => 'old'));

        Mcpwp_Graph_Builder::rebuild_post_node(210);
        Mcpwp_Graph_Builder::rebuild_post_node(211);
        $unchanged_hash_before = $GLOBALS['mcpwp_test_graph_nodes']['post:210']['content_hash'];

        // Drift 211's content WITHOUT going through mark() — simulates a
        // change made while the plugin was deactivated (nothing marked it).
        $this->post(211, array('post_content' => 'new'));
        $expected_new_hash = Mcpwp_Graph_Builder::content_hash($GLOBALS['_mcpwp_test_posts'][211]);

        update_option(Mcpwp_Graph_Builder::RECONCILE_STATUS_OPTION, 'pending', false);
        update_option(Mcpwp_Graph_Builder::RECONCILE_CURSOR_OPTION, 0, false);
        $GLOBALS['mcpwp_test_graph_queue'] = array();

        // Pass 1: the queue is empty, so the worker advances the reconcile
        // scan — 210's hash still matches (untouched), 211's does not, so
        // only 211 gets mark()'d. Nothing is rebuilt yet in THIS pass.
        // on_shutdown() simulates the end of this cron/AS run, which is what
        // actually flushes the buffered mark() to the queue table (proven on
        // the real rig: WordPress's `shutdown` action fires for a WP-Cron
        // request the same way it does for a web request).
        Mcpwp_Graph_Builder::run_worker_batch();
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertSame($unchanged_hash_before, $GLOBALS['mcpwp_test_graph_nodes']['post:210']['content_hash'], 'An unchanged post must not be rewritten.');
        $this->assertArrayHasKey('post:211', $GLOBALS['mcpwp_test_graph_queue'], 'The drifted post must be queued by the reconcile scan.');
        $this->assertNotSame($expected_new_hash, $GLOBALS['mcpwp_test_graph_nodes']['post:211']['content_hash'], 'Not rebuilt yet — only queued.');

        // Pass 2: the next worker run drains what the reconcile scan queued.
        Mcpwp_Graph_Builder::run_worker_batch();
        Mcpwp_Graph_Builder::on_shutdown();
        $this->assertSame($expected_new_hash, $GLOBALS['mcpwp_test_graph_nodes']['post:211']['content_hash']);
    }

    // ---------------------------------------------------------------------
    // W1: reconcile/backfill completion refreshes BUILT_AT_OPTION.
    // ---------------------------------------------------------------------

    public function test_reconcile_completion_refreshes_built_at(): void
    {
        update_option(Mcpwp_Graph_Builder::BUILT_AT_OPTION, '2020-01-01 00:00:00', false);
        $this->post(300);

        update_option(Mcpwp_Graph_Builder::RECONCILE_STATUS_OPTION, 'pending', false);
        update_option(Mcpwp_Graph_Builder::RECONCILE_CURSOR_OPTION, 0, false);

        Mcpwp_Graph_Builder::run_worker_batch();

        $this->assertSame('done', get_option(Mcpwp_Graph_Builder::RECONCILE_STATUS_OPTION, ''));
        $this->assertNotSame('2020-01-01 00:00:00', get_option(Mcpwp_Graph_Builder::BUILT_AT_OPTION, ''), 'A completed reconcile must refresh the watermark, not leave the original backfill timestamp forever.');
    }

    // ---------------------------------------------------------------------
    // W1: activation (not just a version-bump upgrade) triggers reconcile.
    // ---------------------------------------------------------------------

    public function test_on_upgrade_schedule_backfill_reconciles_when_backfill_already_done(): void
    {
        // on_upgrade_schedule_backfill() is exactly what mcpwp_activate()
        // now calls directly (#943 S1 review round 2, W1) — a plain
        // deactivate/reactivate on an unchanged version used to never reach
        // this at all, since the OLD wiring only ran it from the
        // mcpwp_db_version version-compare branch.
        update_option(Mcpwp_Graph_Builder::BACKFILL_STATUS_OPTION, 'done', false);
        update_option(Mcpwp_Graph_Builder::BUILT_AT_OPTION, '2020-01-01 00:00:00', false);
        $this->post(301, array('post_modified_gmt' => '2026-06-01 00:00:00'));

        Mcpwp_Graph_Builder::on_upgrade_schedule_backfill();
        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertArrayHasKey('post:301', $GLOBALS['mcpwp_test_graph_queue']);
    }

    // ---------------------------------------------------------------------
    // W2: the mark buffer is grouped by blog ID, and on_shutdown() flushes
    // each group into THAT blog's own queue table.
    // ---------------------------------------------------------------------

    public function test_mark_on_a_switched_blog_flushes_into_that_blogs_queue_table(): void
    {
        $GLOBALS['mcpwp_test_is_multisite'] = true;

        Mcpwp_Graph_Builder::mark('post:1', 'edit'); // marked while "on" blog 1

        switch_to_blog(2);
        Mcpwp_Graph_Builder::mark('post:1', 'edit'); // same node_key, but blog 2's OWN post:1
        restore_current_blog();

        $this->assertSame(1, get_current_blog_id(), 'Sanity: back on the original blog before shutdown.');

        Mcpwp_Graph_Builder::on_shutdown();

        $queries = implode("\n", $GLOBALS['wpdb']->queries);
        $this->assertStringContainsString('wp_mcpwp_graph_queue', $queries, 'Blog 1s mark must flush through the UNPREFIXED table.');
        $this->assertStringContainsString('wp_2_mcpwp_graph_queue', $queries, "Blog 2's mark must flush through ITS OWN table, not blog 1's.");

        // on_shutdown() must have restored blog 1 as current when it finished.
        $this->assertSame(1, get_current_blog_id());
        $this->assertSame('wp_', $GLOBALS['wpdb']->prefix);
    }

    public function test_mark_without_multisite_never_switches_blogs(): void
    {
        $GLOBALS['mcpwp_test_is_multisite'] = false;
        $this->post(400);
        Mcpwp_Graph_Builder::mark('post:400', 'edit');

        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertArrayHasKey('post:400', $GLOBALS['mcpwp_test_graph_queue']);
        $this->assertSame(1, get_current_blog_id());
    }

    // ---------------------------------------------------------------------
    // N1: the authenticated-request flag resets at the start of a request
    // (reset_for_new_request(), wired on `init`) and at `shutdown`.
    // ---------------------------------------------------------------------

    public function test_authenticated_flag_resets_for_a_new_request(): void
    {
        Mcpwp_Graph_Builder::mark_request_authenticated();
        $this->assertTrue(Mcpwp_Graph_Builder::is_request_authenticated());

        Mcpwp_Graph_Builder::reset_for_new_request();

        $this->assertFalse(Mcpwp_Graph_Builder::is_request_authenticated());
    }

    public function test_authenticated_flag_resets_at_shutdown(): void
    {
        $this->post(401);
        Mcpwp_Graph_Builder::mark_request_authenticated();
        Mcpwp_Graph_Builder::mark('post:401', 'edit');

        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertFalse(Mcpwp_Graph_Builder::is_request_authenticated());
    }

    // ---------------------------------------------------------------------
    // N4a: a throwing rebuild gets exactly one retry, then gives up.
    // ---------------------------------------------------------------------

    public function test_a_fresh_failure_is_requeued_once_with_reason_retry(): void
    {
        $GLOBALS['mcpwp_test_throw_for_post_id'] = 500;

        Mcpwp_Graph_Builder::rebuild_node_safely('post:500', '');
        Mcpwp_Graph_Builder::on_shutdown(); // flush the re-mark mark() made.

        $this->assertArrayHasKey('post:500', $GLOBALS['mcpwp_test_graph_queue']);
        $this->assertSame('retry', $GLOBALS['mcpwp_test_graph_queue']['post:500']['reason']);
    }

    public function test_a_second_failure_on_a_retry_gives_up_without_requeuing(): void
    {
        $GLOBALS['mcpwp_test_throw_for_post_id'] = 501;

        Mcpwp_Graph_Builder::rebuild_node_safely('post:501', 'retry');
        Mcpwp_Graph_Builder::on_shutdown();

        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_queue'], 'A retry that fails again must not be requeued a third time.');
    }

    public function test_worker_threads_reason_through_to_rebuild_node_safely(): void
    {
        $GLOBALS['mcpwp_test_throw_for_post_id'] = 502;
        $GLOBALS['mcpwp_test_graph_queue']['post:502'] = array('reason' => 'retry', 'queued_at' => '2026-01-01 00:00:01');

        Mcpwp_Graph_Builder::run_worker_batch();

        // The worker popped it WITH reason='retry', so a throw there must
        // NOT be requeued again — proves pop_queue_batch()'s reason travels
        // all the way to rebuild_node_safely() via run_worker_batch().
        $this->assertSame(array(), $GLOBALS['mcpwp_test_graph_queue']);
    }

    // ---------------------------------------------------------------------
    // BLOCK-2: the batched resolver matches the FULL path for hierarchical
    // types, treats rewrite bases as not-a-post, and sends ambiguity to the
    // url_to_postid() fallback.
    // ---------------------------------------------------------------------

    public function test_nested_page_resolves_correctly_even_when_a_post_shares_its_slug(): void
    {
        // The exact BLOCK-2 repro: /services/seo/ must resolve to the nested
        // PAGE at that path, never to the unrelated blog POST named "seo".
        $this->post(600, array('post_type' => 'page', 'post_name' => 'services', 'post_parent' => 0));
        $this->post(601, array('post_type' => 'page', 'post_name' => 'seo', 'post_parent' => 600));
        $this->post(602, array('post_type' => 'post', 'post_name' => 'seo', 'post_parent' => 0));

        $source = $this->post(603, array(
            'post_content' => '<a href="https://example.com/services/seo/">SEO services</a>',
        ));

        $this->assertSame(array(601), Mcpwp_Graph_Builder::extract_post_link_targets($source));
    }

    public function test_category_archive_link_is_not_a_post(): void
    {
        $this->post(610, array('post_type' => 'post', 'post_name' => 'news')); // shares the archive's slug
        $source = $this->post(611, array(
            'post_content' => '<a href="https://example.com/category/news/">News category</a>',
        ));

        // Must NOT resolve to post 610 just because "news" matches its slug.
        $this->assertSame(array(), Mcpwp_Graph_Builder::extract_post_link_targets($source));
    }

    public function test_custom_category_base_is_also_excluded(): void
    {
        $GLOBALS['mcpwp_test_options']['category_base'] = 'topics';
        $this->post(612, array('post_type' => 'post', 'post_name' => 'launch'));
        $source = $this->post(613, array(
            'post_content' => '<a href="https://example.com/topics/launch/">Launch topic</a>',
        ));

        $this->assertSame(array(), Mcpwp_Graph_Builder::extract_post_link_targets($source));
    }

    public function test_tag_author_feed_and_pagination_paths_are_not_posts(): void
    {
        $source = $this->post(614, array(
            'post_content' => '<a href="https://example.com/tag/news/">tag</a> '
                . '<a href="https://example.com/author/admin/">author</a> '
                . '<a href="https://example.com/feed/">feed</a> '
                . '<a href="https://example.com/page/2/">pagination</a> '
                . '<a href="https://example.com/2026/">date archive</a> '
                . '<a href="https://example.com/?s=hello">search</a>',
        ));

        $this->assertSame(array(), Mcpwp_Graph_Builder::extract_post_link_targets($source));
    }

    public function test_ambiguous_slug_across_types_falls_back_to_url_to_postid(): void
    {
        // Two DIFFERENT covered-type posts share the same single-segment
        // slug — genuinely ambiguous from post_name alone. url_to_postid()
        // (which this test's stub resolves via its own registered rewrite
        // rule simulation) is the fallback, not a guess.
        $this->post(620, array('post_type' => 'post', 'post_name' => 'launch'));
        $GLOBALS['mcpwp_test_public_post_types'][] = 'sm_team';
        $this->post(621, array('post_type' => 'sm_team', 'post_name' => 'launch'));

        $GLOBALS['mcpwp_test_url_to_postid'] = array(
            'https://example.com/launch/' => 621,
        );

        $source = $this->post(622, array(
            'post_content' => '<a href="https://example.com/launch/">Launch</a>',
        ));

        $this->assertSame(array(621), Mcpwp_Graph_Builder::extract_post_link_targets($source));
    }

    public function test_post_id_permalink_resolves_through_the_fallback(): void
    {
        // A /%post_id%/ custom permalink structure: the path IS the numeric
        // ID, which is not a slug this resolver's batched query can match
        // by post_name at all — url_to_postid() is the only way to resolve it.
        $this->post(630);
        $GLOBALS['mcpwp_test_url_to_postid'] = array(
            'https://example.com/630/' => 630,
        );

        $source = $this->post(631, array(
            'post_content' => '<a href="https://example.com/630/">Direct ID link</a>',
        ));

        $this->assertSame(array(630), Mcpwp_Graph_Builder::extract_post_link_targets($source));
    }
}
