<?php
/**
 * Site graph read surface (#943 S2): visibility, SQL safety, filters,
 * pagination/cursors, size caps, and wiring.
 *
 * The unit double cannot execute SQL, so these tests work in two layers:
 *
 *  1. SQL shape: every query is captured, and the tests assert that the
 *     visibility clause, the status clamp and each filter's fixed fragment
 *     are present, with caller values only ever appearing as escaped,
 *     bound literals.
 *  2. The PHP backstop: the interceptor answers queries as if the SQL
 *     visibility clause were MISSING (it hands back drafts, stale rows and
 *     protected-post edges). Every surface must still refuse to return them,
 *     because the reader re-checks each post against get_post().
 *
 * Real-MySQL semantics (every filter against a brute-force count, keyset
 * pagination across all order modes, the draft leak over real /mcp) are
 * covered by the rig script in tests/rig/graph-read-surface-rig.php.
 *
 * @package MCPWP
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-graph.php';

/** Exposes the protected scope resolvers. */
class Mcpwp_Graph_Scope_Harness {
    use Mcpwp_Api_Auth;

    public function route_scope(string $method, string $route): string
    {
        return $this->get_required_scope_for_request(new WP_REST_Request($method, $route));
    }

    public function tool_scope(string $tool): string
    {
        return $this->get_required_scope_for_tool_name($tool);
    }
}

final class GraphReadSurfaceTest extends TestCase
{
    private const SECRET = 'SECRET DRAFT TITLE';

    /** @var array<int,string> Every SQL string the double saw. */
    private $sql = array();

    /** @var array<string,array> node_key => node row as the DB would hold it. */
    private $nodes = array();

    /** @var array<int,array> Edge rows. */
    private $edges = array();

    /** @var array<int,array>|null Rows handed back for query()'s main SELECT. */
    private $query_rows = null;

    /** @var array<int,array> Rows for the second-hop edge query. */
    private $hop2_edges = array();

    private $saved = array();

    protected function setUp(): void
    {
        foreach (array('_mcpwp_test_posts', 'mcpwp_test_options', 'mcpwp_test_public_post_types', 'mcpwp_test_graph_nodes', 'mcpwp_test_graph_edges', 'mcpwp_test_graph_queue') as $g) {
            $this->saved[ $g ] = $GLOBALS[ $g ] ?? array();
            $GLOBALS[ $g ]     = array();
        }
        $GLOBALS['wpdb']->prefix = 'wp_';
        update_option(Mcpwp_Graph_Builder::READY_OPTION, true, true);

        $this->sql        = array();
        $this->nodes      = array();
        $this->edges      = array();
        $this->query_rows = null;
        $this->hop2_edges = array();

        // A published page, a published post, another user's draft, a
        // private post, a stale node (stored 'publish', live 'draft'), a
        // password-protected published post, and a term.
        $this->post(10, 'publish', 'Home');
        $this->post(11, 'publish', 'About');
        $this->post(20, 'draft', self::SECRET);
        $this->post(21, 'private', 'SECRET PRIVATE TITLE');
        $this->post(22, 'draft', 'SECRET STALE TITLE');
        $this->post(30, 'publish', 'Members', 'hunter2');

        $this->node('post:10', 'page', 10, 'Home', 'publish');
        $this->node('post:11', 'post', 11, 'About', 'publish');
        $this->node('post:20', 'post', 20, self::SECRET, 'draft');
        $this->node('post:21', 'post', 21, 'SECRET PRIVATE TITLE', 'private');
        $this->node('post:22', 'post', 22, 'SECRET STALE TITLE', 'publish'); // stale stored status
        $this->node('post:30', 'post', 30, 'Members', 'publish', true);
        $this->node('term:category:5', 'term', 5, 'News', '');

        $test = $this;
        $GLOBALS['mcpwp_test_wpdb_intercept'] = static function ($method, $query, $output) use ($test) {
            return $test->answer($method, $query);
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['mcpwp_test_wpdb_intercept']);
        foreach ($this->saved as $g => $v) {
            $GLOBALS[ $g ] = $v;
        }
    }

    // ------------------------------------------------------------------
    // Fixtures and the fake database
    // ------------------------------------------------------------------

    private function post(int $id, string $status, string $title, string $password = ''): void
    {
        $GLOBALS['_mcpwp_test_posts'][ $id ] = new WP_Post(array(
            'ID'            => $id,
            'post_type'     => 'post',
            'post_status'   => $status,
            'post_title'    => $title,
            'post_password' => $password,
            'post_content'  => 'secret words inside',
        ));
    }

    private function node(string $key, string $kind, int $ref, string $label, string $status, bool $protected = false, string $url = ''): void
    {
        $this->nodes[ $key ] = array(
            'id'           => (string) (count($this->nodes) + 1),
            'node_key'     => $key,
            'kind'         => $kind,
            'ref_id'       => (string) $ref,
            'label'        => $label,
            'url'          => '' !== $url ? $url : 'https://example.test/' . rawurlencode($label) . '/',
            'status'       => $status,
            'word_count'   => '321',
            'props'        => wp_json_encode(array('builder' => 'gutenberg', 'template' => '')),
            'updated_at'   => '2026-09-01 00:00:00',
            'is_protected' => $protected ? '1' : '0',
            'order_value'  => $label,
        );
    }

    private function edge(int $id, string $from, string $to, string $type = 'links_to'): array
    {
        return array('id' => (string) $id, 'from_key' => $from, 'to_key' => $to, 'type' => $type, 'weight' => '1', 'source' => 'builder');
    }

    /**
     * Answers graph queries while IGNORING every visibility clause, so the
     * PHP backstop is what is under test.
     */
    public function answer(string $method, string $query)
    {
        $this->sql[] = $query;
        if (false === strpos($query, 'mcpwp_graph_')) {
            return MCPWP_TEST_WPDB_PASS;
        }

        if ('get_var' === $method) {
            return '0';
        }

        // Single node lookup (get_node / neighbors center).
        if (preg_match("/WHERE n\\.node_key = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m)) {
            $key = str_replace("\\'", "'", $m[1]);
            foreach ($this->nodes as $k => $row) {
                if (0 === strcasecmp($k, $key)) {
                    return array($row);
                }
            }
            return array();
        }

        // Minimal neighbor rows.
        if (preg_match('/WHERE n\.node_key IN \((.*?)\) AND/s', $query, $m)) {
            $keys = array_map(static function ($v) {
                return trim($v, " '");
            }, explode(',', $m[1]));
            $out = array();
            foreach ($keys as $k) {
                if (isset($this->nodes[ $k ])) {
                    $out[] = $this->nodes[ $k ];
                }
            }
            return $out;
        }

        // query() main select.
        if (0 === strpos($query, 'SELECT n.id AS id')) {
            return null !== $this->query_rows ? $this->query_rows : array_values($this->nodes);
        }

        // Second hop.
        if (false !== strpos($query, 'e.from_key IN (') || false !== strpos($query, 'e.to_key IN (')) {
            return $this->hop2_edges;
        }

        // First hop.
        if (0 === strpos($query, 'SELECT e.id AS id')) {
            return $this->edges;
        }

        // Summary kinds: count EVERY node, as if unfiltered.
        if (0 === strpos($query, 'SELECT n.kind AS kind')) {
            $counts = array();
            foreach ($this->nodes as $row) {
                $counts[ $row['kind'] ] = ($counts[ $row['kind'] ] ?? 0) + 1;
            }
            $out = array();
            foreach ($counts as $kind => $n) {
                $out[] = array('kind' => $kind, 'cnt' => (string) $n);
            }
            return $out;
        }

        return array();
    }

    private function graph_sql(): array
    {
        return array_values(array_filter($this->sql, static function ($q) {
            return false !== strpos($q, 'mcpwp_graph_');
        }));
    }

    private function controller(?array $scopes): Mcpwp_REST_Graph
    {
        $controller = new Mcpwp_REST_Graph();
        $prop       = new ReflectionProperty($controller, 'current_api_key_record');
        $prop->setAccessible(true);
        $prop->setValue($controller, null === $scopes ? null : array('id' => 'k1', 'scopes' => $scopes));
        return $controller;
    }

    private function json($value): string
    {
        if ($value instanceof WP_REST_Response) {
            $value = $value->get_data();
        }
        if ($value instanceof WP_Error) {
            $value = array('code' => $value->get_error_code(), 'message' => $value->get_error_message());
        }
        return (string) wp_json_encode($value);
    }

    private function assertNoSecrets(string $json, string $surface): void
    {
        foreach (array(self::SECRET, 'SECRET PRIVATE TITLE', 'SECRET STALE TITLE', 'post:20', 'post:21', 'post:22') as $needle) {
            $this->assertStringNotContainsString($needle, $json, "{$surface} leaked {$needle}");
        }
    }

    // ------------------------------------------------------------------
    // Draft leak: a read-scope key, all four surfaces, through the REST
    // controller (the same handlers /mcp tools/call and resources/read
    // dispatch to).
    // ------------------------------------------------------------------

    public function test_read_key_sees_no_hidden_post_through_any_surface(): void
    {
        $read = $this->controller(array('read'));

        // Edges from a public page to every kind of hidden post, plus two
        // legitimate ones.
        $this->edges = array(
            $this->edge(1, 'post:10', 'post:20'),
            $this->edge(2, 'post:10', 'post:21'),
            $this->edge(3, 'post:10', 'post:22'),
            $this->edge(4, 'post:20', 'post:10'),
            $this->edge(5, 'post:10', 'post:11'),
            $this->edge(6, 'post:10', 'term:category:5', 'in_term'),
        );
        $this->hop2_edges = array(
            $this->edge(7, 'post:11', 'post:20'),
            $this->edge(8, 'post:11', 'post:21'),
        );

        // 1. node: each hidden post, by node_key and by post_id, is a 404
        //    identical to a missing node.
        foreach (array(array('node_key' => 'post:20'), array('post_id' => 20), array('node_key' => 'post:21'), array('post_id' => 22), array('node_key' => 'POST:20')) as $params) {
            $resp = $read->get_node(new WP_REST_Request('GET', '/mcpwp/v1/graph/node', $params));
            $this->assertInstanceOf(WP_Error::class, $resp, wp_json_encode($params));
            $this->assertSame('not_found', $resp->get_error_code());
            $this->assertNoSecrets($this->json($resp), 'node');
        }
        $missing = $read->get_node(new WP_REST_Request('GET', '/mcpwp/v1/graph/node', array('post_id' => 99999)));
        $this->assertSame($missing->get_error_message(), $read->get_node(new WP_REST_Request('GET', '/mcpwp/v1/graph/node', array('post_id' => 20)))->get_error_message());

        // 2. neighbors, both depths, all directions.
        foreach (array('out', 'in', 'both') as $dir) {
            foreach (array(1, 2) as $depth) {
                $resp = $read->get_neighbors(new WP_REST_Request('GET', '/mcpwp/v1/graph/neighbors', array('node_key' => 'post:10', 'direction' => $dir, 'depth' => $depth)));
                $this->assertInstanceOf(WP_REST_Response::class, $resp);
                $this->assertNoSecrets($this->json($resp), "neighbors {$dir}/{$depth}");
            }
        }
        $data = $read->get_neighbors(new WP_REST_Request('GET', '/mcpwp/v1/graph/neighbors', array('node_key' => 'post:10', 'direction' => 'both')))->get_data();
        $this->assertSame(array('post:11', 'term:category:5'), array_values(array_unique(array_map(static function ($e) {
            return $e['to_key'];
        }, $data['edges']))));
        // A hidden node is not a usable center either.
        $this->assertInstanceOf(WP_Error::class, $read->get_neighbors(new WP_REST_Request('GET', '/mcpwp/v1/graph/neighbors', array('node_key' => 'post:20'))));

        // 3. query, including every attempt to ask for hidden statuses.
        foreach (array('', 'draft', 'private', 'any', 'trash', 'pending', 'future') as $status) {
            $resp = $read->query(new WP_REST_Request('GET', '/mcpwp/v1/graph/query', array('status' => $status)));
            $this->assertInstanceOf(WP_REST_Response::class, $resp, $status);
            $this->assertNoSecrets($this->json($resp), "query status={$status}");
            $keys = array_column($resp->get_data()['results'], 'node_key');
            $this->assertSame(array('post:10', 'post:11', 'post:30', 'term:category:5'), $keys);
        }

        // 4. the resource summary (GET /graph == mcpwp://graph).
        $this->query_rows = array_values($this->nodes);
        $resp             = $read->get_summary(new WP_REST_Request('GET', '/mcpwp/v1/graph'));
        $this->assertNoSecrets($this->json($resp), 'summary');
    }

    public function test_every_read_surface_query_carries_the_live_visibility_clause(): void
    {
        $read = $this->controller(array('read'));
        $this->edges = array($this->edge(1, 'post:10', 'post:11'));
        $read->get_node(new WP_REST_Request('GET', '/', array('node_key' => 'post:10')));
        $read->get_neighbors(new WP_REST_Request('GET', '/', array('node_key' => 'post:10', 'depth' => 2)));
        $read->query(new WP_REST_Request('GET', '/', array()));
        $read->get_summary(new WP_REST_Request('GET', '/'));

        $node_queries = 0;
        foreach ($this->graph_sql() as $q) {
            if (preg_match('/FROM wp_mcpwp_graph_nodes n/', $q) && false === strpos($q, 'INNER JOIN')) {
                $node_queries++;
                // Live join to wp_posts + public-status filter, never the stored n.status alone.
                $this->assertStringContainsString("LEFT JOIN wp_posts p ON (p.ID = n.ref_id AND LEFT(n.node_key, 5) = 'post:')", $q);
                $this->assertStringContainsString("(LEFT(n.node_key, 5) <> 'post:' OR p.post_status IN ('publish'))", $q);
            }
            if (preg_match('/FROM wp_mcpwp_graph_edges e WHERE/', $q)) {
                // Both endpoints checked against live public posts; content edges of protected posts excluded.
                $this->assertStringContainsString("(LEFT(e.from_key, 5) <> 'post:' OR EXISTS (SELECT 1 FROM wp_posts epf WHERE epf.ID = CAST(SUBSTRING(e.from_key, 6) AS UNSIGNED) AND epf.post_status IN ('publish')))", $q);
                $this->assertStringContainsString("(LEFT(e.to_key, 5) <> 'post:' OR EXISTS (SELECT 1 FROM wp_posts ept WHERE ept.ID = CAST(SUBSTRING(e.to_key, 6) AS UNSIGNED) AND ept.post_status IN ('publish')))", $q);
                $this->assertStringContainsString("epw.post_password <> ''", $q);
            }
        }
        $this->assertGreaterThanOrEqual(4, $node_queries);
    }

    public function test_privileged_key_sees_drafts_and_private_posts(): void
    {
        $write = $this->controller(array('write'));
        $resp  = $write->get_node(new WP_REST_Request('GET', '/', array('post_id' => 20)));
        $this->assertInstanceOf(WP_REST_Response::class, $resp);
        $this->assertSame(self::SECRET, $resp->get_data()['label']);

        $resp = $write->query(new WP_REST_Request('GET', '/', array('status' => 'any')));
        $this->assertContains('post:21', array_column($resp->get_data()['results'], 'node_key'));
        // 'any' drops the status clause entirely; the visibility clause only requires the post to exist.
        $main = array_values(array_filter($this->graph_sql(), static function ($q) {
            return 0 === strpos($q, 'SELECT n.id AS id');
        }));
        $this->assertStringNotContainsString('n.status =', end($main));
        $this->assertStringContainsString("(LEFT(n.node_key, 5) <> 'post:' OR p.ID IS NOT NULL)", end($main));
    }

    public function test_read_key_status_request_is_clamped_to_publish_in_sql(): void
    {
        Mcpwp_Graph_Reader::query(array('status' => 'draft'), false);
        $q = $this->graph_sql()[0];
        $this->assertStringContainsString("(LEFT(n.node_key, 5) <> 'post:' OR n.status = 'publish')", $q);
        $this->assertStringNotContainsString("'draft'", $q);
    }

    public function test_node_whose_post_is_gone_is_hidden_even_from_privileged_callers(): void
    {
        $this->node('post:40', 'post', 40, 'Deleted', 'publish'); // no live post 40
        $this->assertNull(Mcpwp_Graph_Reader::get_node('post:40', true));
        $this->assertNull(Mcpwp_Graph_Reader::get_node('post:40', false));
    }

    // ------------------------------------------------------------------
    // Password-protected posts
    // ------------------------------------------------------------------

    public function test_password_protected_post_exposes_no_content_derived_props_to_read_key(): void
    {
        $node = Mcpwp_Graph_Reader::get_node('post:30', false);
        $this->assertSame('Members', $node['label']);
        $this->assertNull($node['word_count']);
        $this->assertTrue($node['password_protected']);
        $this->assertArrayNotHasKey('builder', (array) $node['props']);

        $priv = Mcpwp_Graph_Reader::get_node('post:30', true);
        $this->assertSame(321, $priv['word_count']);
        $this->assertSame('gutenberg', $priv['props']['builder']);

        $this->query_rows = array($this->nodes['post:30']);
        $rows             = Mcpwp_Graph_Reader::query(array(), false)['results'];
        $this->assertNull($rows[0]['word_count']);
    }

    public function test_password_protected_posts_content_edges_are_dropped_for_read_key(): void
    {
        $this->edges = array(
            $this->edge(1, 'post:30', 'post:10'),
            $this->edge(2, 'post:30', 'media:9', 'uses_media'),
            $this->edge(3, 'post:30', 'term:category:5', 'in_term'),
        );
        $read = Mcpwp_Graph_Reader::get_neighbors(array('node_key' => 'post:30'), false);
        $this->assertSame(array('in_term'), array_column($read['edges'], 'type'));

        $priv = Mcpwp_Graph_Reader::get_neighbors(array('node_key' => 'post:30'), true);
        $this->assertSame(array('links_to', 'uses_media', 'in_term'), array_column($priv['edges'], 'type'));
    }

    public function test_word_count_and_no_edge_type_filters_cannot_probe_protected_posts(): void
    {
        Mcpwp_Graph_Reader::query(array('word_count_lt' => 50, 'no_edge_type' => 'links_to'), false);
        $q = $this->graph_sql()[0];
        $this->assertStringContainsString("NOT (p.ID IS NOT NULL AND p.post_password <> '') AND n.word_count < 50", $q);
        $this->assertStringContainsString("((p.ID IS NOT NULL AND p.post_password <> '') OR NOT EXISTS (SELECT 1 FROM wp_mcpwp_graph_edges en WHERE en.from_key = n.node_key AND en.type = 'links_to'))", $q);
    }

    // ------------------------------------------------------------------
    // SQL injection
    // ------------------------------------------------------------------

    /** @return array<string,array> */
    public static function injection_args(): array
    {
        $p = "x' OR '1'='1";
        return array(
            'kind'               => array(array('kind' => array("page') OR 1=1 -- "))),
            'kind comma string'  => array(array('kind' => "page,post' UNION SELECT user_pass FROM wp_users -- ")),
            'status'             => array(array('status' => "publish' OR '1'='1")),
            'has_metric'         => array(array('has_metric' => $p)),
            'no_edge_type'       => array(array('no_edge_type' => "links_to') OR ('1'='1")),
            'order_by'           => array(array('order_by' => 'label; DROP TABLE wp_posts')),
            'order_by subquery'  => array(array('order_by' => '(SELECT user_pass FROM wp_users LIMIT 1)')),
            'order_dir'          => array(array('order_dir' => 'ASC, (SELECT SLEEP(5))')),
            'metric name'        => array(array('metric' => array('name' => $p, 'op' => 'gt', 'value' => 1))),
            'metric op'          => array(array('metric' => array('name' => 'clicks', 'op' => '> 0 OR 1=1 OR 0 >', 'value' => 1))),
            'metric raw op'      => array(array('metric' => array('name' => 'clicks', 'op' => '>', 'value' => 1))),
            'metric value'       => array(array('metric' => array('name' => 'clicks', 'op' => 'gt', 'value' => '1 OR 1=1'))),
            'metric source'      => array(array('metric' => array('name' => 'clicks', 'op' => 'gt', 'value' => 1, 'source' => $p))),
            'metric window'      => array(array('metric' => array('name' => 'clicks', 'op' => 'gt', 'value' => 1, 'window_days' => '28) OR (1=1'))),
            'metric extra key'   => array(array('metric' => array('name' => 'clicks', 'op' => 'gt', 'value' => 1, 'sql' => '1=1'))),
            'metric json string' => array(array('metric' => '{"name":"clicks\' OR 1=1","op":"gt","value":1}')),
            'min_inbound'        => array(array('min_inbound' => '1 OR 1=1')),
            'max_inbound'        => array(array('max_inbound' => '-1')),
            'word_count_lt'      => array(array('word_count_lt' => '10; DELETE FROM wp_posts')),
            'modified_before'    => array(array('modified_before' => "2026-01-01' OR '1'='1")),
            'modified_before txt' => array(array('modified_before' => 'next monday')),
            'limit'              => array(array('limit' => '10 UNION SELECT 1')),
            'orphan'             => array(array('orphan' => "1' OR '1")),
        );
    }

    /**
     * @dataProvider injection_args
     */
    public function test_injection_in_any_filter_is_refused_before_any_sql_runs(array $args): void
    {
        foreach (array(false, true) as $priv) {
            $this->sql = array();
            $result    = Mcpwp_Graph_Reader::query($args, $priv);
            $this->assertInstanceOf(WP_Error::class, $result);
            $this->assertContains($result->get_error_code(), array('invalid_filter', 'invalid_cursor'));
            $this->assertSame(400, $result->get_error_data()['status']);
            $this->assertSame(array(), $this->graph_sql(), 'no graph SQL may run for a refused filter');
        }
    }

    public function test_injection_in_neighbors_params_is_refused(): void
    {
        foreach (array(
            array('direction' => "out' OR '1'='1"),
            array('types' => array("links_to') OR ('1'='1")),
            array('types' => 'links_to,in_term) UNION SELECT 1 -- '),
            array('depth' => '2 OR 1=1'),
            array('depth' => 3),
            array('limit' => '-1'),
        ) as $args) {
            $this->sql = array();
            $result    = Mcpwp_Graph_Reader::get_neighbors(array_merge(array('node_key' => 'post:10'), $args), false);
            $this->assertInstanceOf(WP_Error::class, $result, wp_json_encode($args));
            $this->assertSame(array(), $this->graph_sql());
        }
    }

    public function test_node_key_payload_only_ever_reaches_sql_as_an_escaped_literal(): void
    {
        $payload = "post:10' OR '1'='1";
        $this->assertNull(Mcpwp_Graph_Reader::get_node($payload, false));
        $q = $this->graph_sql()[0];
        $this->assertStringContainsString("n.node_key = 'post:10\\' OR \\'1\\'=\\'1'", $q);
        $this->assertStringNotContainsString("= 'post:10' OR '1'='1'", $q);
    }

    public function test_filter_values_are_bound_not_interpolated(): void
    {
        // Valid tokens still only reach SQL as quoted literals.
        Mcpwp_Graph_Reader::query(array(
            'kind'         => array('page', 'my-cpt'),
            'has_metric'   => 'clicks',
            'no_edge_type' => 'targets_query',
            'metric'       => array('name' => 'impressions', 'source' => 'gsc', 'op' => 'lt', 'value' => 0.1 + 0.2, 'window_days' => 7),
            'order_by'     => 'clicks',
        ), false);
        $q = $this->graph_sql()[0];
        $this->assertStringContainsString("n.kind IN ('my-cpt','page')", $q);
        $this->assertStringContainsString("sh.metric = 'clicks'", $q);
        $this->assertStringContainsString("en.type = 'targets_query'", $q);
        $this->assertMatchesRegularExpression("/sm\\.metric = 'impressions' AND sm\\.period_end >= '\\d{4}-\\d{2}-\\d{2}' AND sm\\.source = 'gsc'\\), 0\\) < '0\\.30000000000000004'/", $q);
        $this->assertStringContainsString("so.metric = 'clicks' ORDER BY so.period_end DESC LIMIT 1), 0) AS order_value", $q);
    }

    // ------------------------------------------------------------------
    // Every filter emits its fixed fragment
    // ------------------------------------------------------------------

    public function test_orphan_counts_only_live_links_to_sources(): void
    {
        Mcpwp_Graph_Reader::query(array('orphan' => true), true);
        $q = $this->graph_sql()[0];
        $this->assertStringContainsString("LEFT(n.node_key, 5) = 'post:' AND n.status = 'publish' AND NOT EXISTS (SELECT 1 FROM wp_mcpwp_graph_edges eo WHERE eo.to_key = n.node_key AND eo.type = 'links_to' AND (LEFT(eo.from_key, 5) <> 'post:' OR EXISTS (SELECT 1 FROM wp_posts eopf WHERE eopf.ID = CAST(SUBSTRING(eo.from_key, 6) AS UNSIGNED) AND eopf.post_status IN ('publish')))", $q);
        // Even for a privileged caller: a draft's or trashed post's link does not rescue an orphan.
        $this->assertStringContainsString("eopw.post_password <> ''", $q);
    }

    public function test_min_and_max_inbound(): void
    {
        Mcpwp_Graph_Reader::query(array('min_inbound' => 2, 'max_inbound' => '5'), false);
        $q = $this->graph_sql()[0];
        $this->assertMatchesRegularExpression('/\(SELECT COUNT\(\*\) FROM wp_mcpwp_graph_edges ei WHERE ei\.to_key = n\.node_key AND .*\) >= 2\)/', $q);
        $this->assertMatchesRegularExpression('/\) <= 5\)/', $q);
        $this->assertStringContainsString('eipf.post_status IN', $q);
    }

    public function test_modified_before_uses_live_post_modified_gmt(): void
    {
        Mcpwp_Graph_Reader::query(array('modified_before' => '2026-03-01'), false);
        $this->assertStringContainsString("LEFT(n.node_key, 5) = 'post:' AND p.post_modified_gmt < '2026-03-01 00:00:00'", $this->graph_sql()[0]);

        $this->sql = array();
        Mcpwp_Graph_Reader::query(array('modified_before' => '2026-03-01T10:30:00+02:00'), false);
        $this->assertStringContainsString("p.post_modified_gmt < '2026-03-01 08:30:00'", $this->graph_sql()[0]);
    }

    public function test_metric_filter_defaults_window_and_maps_ops(): void
    {
        Mcpwp_Graph_Reader::query(array('metric' => array('name' => 'clicks', 'op' => 'GT', 'value' => '10')), false);
        $q     = $this->graph_sql()[0];
        $since = gmdate('Y-m-d', time() - 28 * DAY_IN_SECONDS);
        $this->assertStringContainsString("IFNULL((SELECT SUM(sm.value) FROM wp_mcpwp_graph_signals sm WHERE sm.node_key = n.node_key AND sm.metric = 'clicks' AND sm.period_end >= '{$since}'), 0) > '10'", $q);

        // As a JSON string too (REST query-string callers).
        $this->sql = array();
        $this->assertIsArray(Mcpwp_Graph_Reader::query(array('metric' => '{"name":"clicks","op":"lt","value":3}'), false));
        $this->assertStringContainsString(", 0) < '3'", $this->graph_sql()[0]);
    }

    public function test_each_order_by_mode_and_direction(): void
    {
        $cases = array(
            'label'    => "IFNULL(n.label, '') AS order_value",
            'modified' => "IFNULL(p.post_modified_gmt, '1000-01-01 00:00:00') AS order_value",
            'inbound'  => 'ei.to_key = n.node_key AND',
            'clicks'   => "so.metric = 'clicks'",
        );
        foreach ($cases as $order_by => $needle) {
            foreach (array('asc' => 'ASC', 'desc' => 'DESC') as $dir => $sql_dir) {
                $this->sql = array();
                $this->assertIsArray(Mcpwp_Graph_Reader::query(array('order_by' => $order_by, 'order_dir' => $dir), false));
                $q = $this->graph_sql()[0];
                $this->assertStringContainsString($needle, $q);
                $this->assertStringContainsString("ORDER BY order_value {$sql_dir}, n.id {$sql_dir} LIMIT 51", $q);
            }
        }
    }

    // ------------------------------------------------------------------
    // Pagination and cursors
    // ------------------------------------------------------------------

    private function rows(int $n, int $url_len = 20): array
    {
        $rows = array();
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = array(
                'id' => (string) $i, 'node_key' => 'term:category:' . $i, 'kind' => 'term', 'ref_id' => (string) $i,
                'label' => sprintf('Label %03d', $i), 'url' => 'https://example.test/' . str_repeat('u', $url_len), 'status' => '',
                'word_count' => '0', 'props' => '{}', 'updated_at' => null, 'is_protected' => '0', 'order_value' => sprintf('Label %03d', $i),
            );
        }
        return $rows;
    }

    public function test_limit_is_clamped_and_fetches_one_extra_row_to_detect_more(): void
    {
        Mcpwp_Graph_Reader::query(array('limit' => 5000), false);
        $this->assertStringEndsWith('LIMIT 201', $this->graph_sql()[0]);

        $this->sql        = array();
        $this->query_rows = $this->rows(4);
        $page             = Mcpwp_Graph_Reader::query(array('limit' => 3), false);
        $this->assertStringEndsWith('LIMIT 4', $this->graph_sql()[0]);
        $this->assertCount(3, $page['results']);
        $this->assertNotNull($page['next_cursor']);

        $this->query_rows = $this->rows(3);
        $this->assertNull(Mcpwp_Graph_Reader::query(array('limit' => 3), false)['next_cursor']);
    }

    public function test_cursor_round_trip_becomes_a_keyset_predicate(): void
    {
        $this->query_rows = $this->rows(3);
        $cursor           = Mcpwp_Graph_Reader::query(array('limit' => 2, 'kind' => 'term'), false)['next_cursor'];

        $this->sql = array();
        $this->assertIsArray(Mcpwp_Graph_Reader::query(array('limit' => 2, 'kind' => 'term', 'cursor' => $cursor), false));
        $this->assertStringContainsString("AND ((IFNULL(n.label, '')) > 'Label 002' OR ((IFNULL(n.label, '')) = 'Label 002' AND n.id > 2)) ORDER BY", $this->graph_sql()[0]);

        // DESC uses <, and a label containing quotes/percent stays a bound literal.
        $row                = $this->rows(2);
        $row[0]['order_value'] = "O'Brien 100% %s";
        $row[0]['id']       = '9';
        $this->query_rows   = $row;
        $cursor             = Mcpwp_Graph_Reader::query(array('limit' => 1, 'order_dir' => 'desc'), false)['next_cursor'];
        $this->sql          = array();
        Mcpwp_Graph_Reader::query(array('limit' => 1, 'order_dir' => 'desc', 'cursor' => $cursor), false);
        $this->assertStringContainsString("< 'O\\'Brien 100% %s' OR ((IFNULL(n.label, '')) = 'O\\'Brien 100% %s' AND n.id < 9))", $this->graph_sql()[0]);
    }

    public function test_tampered_or_foreign_cursors_are_rejected(): void
    {
        $this->query_rows = $this->rows(3);
        $cursor           = Mcpwp_Graph_Reader::query(array('limit' => 2), false)['next_cursor'];
        list($payload, $mac) = explode('.', $cursor);
        $decoded          = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        $forge = static function (array $data) use ($mac) {
            return rtrim(strtr(base64_encode(wp_json_encode($data)), '+/', '-_'), '=') . '.' . $mac;
        };

        $bad = array(
            'garbage'            => 'not-a-cursor',
            'empty mac'          => $payload . '.',
            'flipped mac'        => $payload . '.' . strrev($mac),
            'flipped payload'    => substr($payload, 0, -2) . 'AA.' . $mac,
            'forged id'          => $forge(array_merge($decoded, array('i' => 0))),
            'forged value'       => $forge(array_merge($decoded, array('v' => "' OR 1=1 -- "))),
            'overlong'           => str_repeat('A', 3000) . '.' . $mac,
            'array'              => array('i' => 1),
            'sql'                => "1' OR '1'='1",
        );
        foreach ($bad as $label => $c) {
            $this->sql = array();
            $result    = Mcpwp_Graph_Reader::query(array('limit' => 2, 'cursor' => $c), false);
            $this->assertInstanceOf(WP_Error::class, $result, $label);
            $this->assertSame('invalid_cursor', $result->get_error_code(), $label);
            $this->assertSame(array(), $this->graph_sql(), $label);
        }

        // Valid signature, but minted for a different query / privilege / surface.
        $this->assertSame('invalid_cursor', Mcpwp_Graph_Reader::query(array('limit' => 2, 'orphan' => true, 'cursor' => $cursor), false)->get_error_code());
        $this->assertSame('invalid_cursor', Mcpwp_Graph_Reader::query(array('limit' => 2, 'cursor' => $cursor), true)->get_error_code());
        $this->assertSame('invalid_cursor', Mcpwp_Graph_Reader::get_neighbors(array('node_key' => 'post:10', 'cursor' => $cursor), false)->get_error_code());
        // Page size may change between pages.
        $this->assertIsArray(Mcpwp_Graph_Reader::query(array('limit' => 7, 'cursor' => $cursor), false));
    }

    public function test_neighbors_pagination_and_cursor(): void
    {
        $this->post(12, 'publish', 'P12');
        $this->post(13, 'publish', 'P13');
        $this->node('post:12', 'post', 12, 'P12', 'publish');
        $this->node('post:13', 'post', 13, 'P13', 'publish');
        $this->edges = array($this->edge(5, 'post:10', 'post:11'), $this->edge(8, 'post:10', 'post:12'), $this->edge(9, 'post:10', 'post:13'));

        $page = Mcpwp_Graph_Reader::get_neighbors(array('node_key' => 'post:10', 'limit' => 2), false);
        $this->assertCount(2, $page['edges']);
        $this->assertNotNull($page['next_cursor']);
        $first = array_values(array_filter($this->graph_sql(), static function ($q) {
            return 0 === strpos($q, 'SELECT e.id AS id');
        }));
        $this->assertStringEndsWith('ORDER BY e.id ASC LIMIT 3', $first[0]);

        $this->sql = array();
        Mcpwp_Graph_Reader::get_neighbors(array('node_key' => 'post:10', 'limit' => 2, 'cursor' => $page['next_cursor']), false);
        $hop1 = array_values(array_filter($this->graph_sql(), static function ($q) {
            return 0 === strpos($q, 'SELECT e.id AS id');
        }));
        $this->assertStringContainsString('AND e.id > 8 ORDER BY e.id ASC LIMIT 3', $hop1[0]);

        // Cursor bound to direction.
        $this->assertSame('invalid_cursor', Mcpwp_Graph_Reader::get_neighbors(array('node_key' => 'post:10', 'direction' => 'in', 'cursor' => $page['next_cursor']), false)->get_error_code());
    }

    // ------------------------------------------------------------------
    // Caps
    // ------------------------------------------------------------------

    public function test_depth_two_is_capped_at_200_nodes(): void
    {
        $this->edges = array();
        for ($i = 1; $i <= 100; $i++) {
            $this->edges[] = $this->edge($i, 'post:10', 'term:t:' . $i, 'in_term');
        }
        $this->hop2_edges = array();
        for ($i = 1; $i <= 1000; $i++) {
            $this->hop2_edges[] = $this->edge(1000 + $i, 'term:t:' . (($i % 100) + 1), 'url:' . $i, 'derived_from');
        }
        $result = Mcpwp_Graph_Reader::get_neighbors(array('node_key' => 'post:10', 'depth' => 2, 'direction' => 'both', 'limit' => 100), false);
        $keys   = array('post:10' => true);
        foreach ($result['edges'] as $e) {
            $keys[ $e['from_key'] ] = true;
            $keys[ $e['to_key'] ]   = true;
        }
        $this->assertSame(200, count($keys));
        $this->assertTrue($result['truncated']);
    }

    public function test_query_page_is_cut_at_the_byte_cap_with_a_resumable_cursor(): void
    {
        $this->query_rows = $this->rows(201, 2000);
        $page             = Mcpwp_Graph_Reader::query(array('limit' => 200), false);
        $this->assertTrue($page['truncated']);
        $this->assertLessThan(200, $page['count']);
        $this->assertLessThanOrEqual(Mcpwp_Graph_Reader::MAX_RESPONSE_BYTES, strlen(wp_json_encode($page['results'])));
        $this->assertNotNull($page['next_cursor']);

        $last             = end($page['results']);
        $this->sql        = array();
        Mcpwp_Graph_Reader::query(array('limit' => 200, 'cursor' => $page['next_cursor']), false);
        $this->assertStringContainsString("= '" . $last['label'] . "' AND n.id > " . $page['count'] . '))', $this->graph_sql()[0]);
    }

    public function test_resource_summary_stays_under_2k_tokens_in_the_worst_case(): void
    {
        $long = str_repeat('Ω', 255);
        $test = $this;
        $GLOBALS['mcpwp_test_wpdb_intercept'] = static function ($method, $query) use ($long) {
            if (false === strpos($query, 'mcpwp_graph_')) {
                return MCPWP_TEST_WPDB_PASS;
            }
            if ('get_var' === $method) {
                return '123456';
            }
            if (0 === strpos($query, 'SELECT n.kind AS kind')) {
                $out = array();
                for ($i = 0; $i < 25; $i++) {
                    $out[] = array('kind' => str_repeat('k', 60) . $i, 'cnt' => '999999');
                }
                return $out;
            }
            if (0 === strpos($query, 'SELECT e.type AS type')) {
                $out = array();
                for ($i = 0; $i < 25; $i++) {
                    $out[] = array('type' => str_repeat('t', 30) . $i, 'cnt' => '999999');
                }
                return $out;
            }
            if (0 === strpos($query, 'SELECT s.source AS source')) {
                $out = array();
                for ($i = 0; $i < 10; $i++) {
                    $out[] = array('source' => str_repeat('s', 30) . $i, 'latest_period_end' => '2026-09-27', 'cnt' => '999999');
                }
                return $out;
            }
            if (0 === strpos($query, 'SELECT n.id AS id')) {
                $rows = array();
                for ($i = 1; $i <= 11; $i++) {
                    $rows[] = array('id' => (string) $i, 'node_key' => 'term:' . str_repeat('x', 150) . $i, 'kind' => 'term', 'ref_id' => '1', 'label' => $long, 'url' => str_repeat('u', 2048), 'status' => '', 'word_count' => '0', 'props' => '{}', 'updated_at' => null, 'is_protected' => '0', 'order_value' => '2026-01-01 00:00:00');
                }
                return $rows;
            }
            return array();
        };

        $summary = Mcpwp_Graph_Reader::resource_summary(false);
        $json    = wp_json_encode($summary);
        $this->assertLessThanOrEqual(Mcpwp_Graph_Reader::RESOURCE_MAX_BYTES, strlen($json));
        // Conservative estimate: 1 token per 3 bytes of JSON (real tokenizers
        // do better on this kind of text), so this bounds it under 2,000.
        $this->assertLessThan(2000, (int) ceil(strlen($json) / 3));
        $this->assertTrue($summary['truncated']);
        $this->assertNotEmpty($summary['top_orphans']);
        $this->assertArrayHasKey('graph_status', $summary);
    }

    public function test_graph_status_never_counts_non_public_posts_for_read_key(): void
    {
        $GLOBALS['mcpwp_test_public_post_types'] = array('post', 'page');
        $captured = array();
        $GLOBALS['mcpwp_test_wpdb_intercept'] = static function ($method, $query) use (&$captured) {
            $captured[] = $query;
            return '0';
        };
        $status = Mcpwp_Graph_Reader::graph_status(false);
        $this->assertSame(array('ready', 'coverage', 'built_at', 'backfill', 'dirty_count'), array_keys($status));
        foreach (array_slice($captured, 0, 2) as $q) {
            $this->assertStringContainsString("post_status IN ('publish')", $q);
        }
        $captured = array();
        Mcpwp_Graph_Reader::graph_status(true);
        $this->assertStringContainsString("post_status <> 'auto-draft'", $captured[0]);
    }

    public function test_not_ready_graph_answers_503_not_an_sql_error(): void
    {
        update_option(Mcpwp_Graph_Builder::READY_OPTION, false, true);
        $this->assertSame('graph_not_ready', Mcpwp_Graph_Reader::query(array(), false)->get_error_code());
        $this->assertSame('graph_not_ready', Mcpwp_Graph_Reader::get_node('post:10', false)->get_error_code());
        $this->assertSame(array('ready' => false), array_intersect_key(Mcpwp_Graph_Reader::resource_summary(false), array('ready' => 1)));
        $this->assertSame(array(), $this->graph_sql());
    }

    // ------------------------------------------------------------------
    // Wiring: tools, annotations, scopes, resource
    // ------------------------------------------------------------------

    public function test_tools_are_free_read_only_titled_and_mapped_to_get_routes(): void
    {
        $registry = new Mcpwp_MCP_Free_Tools();
        $tools    = array();
        foreach ($registry->get_tools() as $tool) {
            $tools[ $tool['name'] ] = $tool;
        }
        $map        = $registry->get_tool_map();
        $categories = $registry->get_tool_categories();
        $harness    = new Mcpwp_Graph_Scope_Harness();

        $expected = array(
            'wp_graph_node'      => array('/graph/node', 'Get site graph node'),
            'wp_graph_neighbors' => array('/graph/neighbors', 'Get site graph neighbors'),
            'wp_graph_query'     => array('/graph/query', 'Query site graph'),
        );
        foreach ($expected as $name => list($route, $title)) {
            $this->assertArrayHasKey($name, $tools);
            $this->assertSame($title, $tools[ $name ]['title']);
            $this->assertTrue($tools[ $name ]['annotations']['readOnlyHint'], $name);
            $this->assertFalse($tools[ $name ]['annotations']['destructiveHint'], $name);
            $this->assertSame('GET', $map[ $name ]['method']);
            $this->assertSame($route, $map[ $name ]['route']);
            $this->assertSame('content', $categories[ $name ]);
            $this->assertSame('read', $harness->tool_scope($name));
            $this->assertSame('read', $harness->route_scope('GET', '/mcpwp/v1' . $route));
        }
        $this->assertSame('object', $tools['wp_graph_query']['inputSchema']['properties']['metric']['type']);
        $this->assertSame(array('gt', 'lt'), $tools['wp_graph_query']['inputSchema']['properties']['metric']['properties']['op']['enum']);
        $this->assertSame('array', $tools['wp_graph_query']['inputSchema']['properties']['kind']['type']);
    }

    public function test_rebuild_route_needs_admin_scope_and_summary_is_read(): void
    {
        $harness = new Mcpwp_Graph_Scope_Harness();
        $this->assertSame('admin', $harness->route_scope('POST', '/mcpwp/v1/graph/rebuild'));
        $this->assertSame('read', $harness->route_scope('GET', '/mcpwp/v1/graph'));
    }

    public function test_graph_resource_is_advertised(): void
    {
        $mcp    = new Mcpwp_REST_MCP();
        $method = new ReflectionMethod($mcp, 'mcp_resource_definitions');
        $method->setAccessible(true);
        $keys = array();
        foreach ($method->invoke($mcp) as $def) {
            $keys[ $def['key'] ] = $def['route'];
        }
        $this->assertSame('/mcpwp/v1/graph', $keys['graph']);
    }
}
