<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-integration-manager.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-approvals.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-jev.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-jev-triage.php';

/**
 * Jev-powered approval-queue triage.
 *
 * Jev routes; it does not gate (see class docblock on Mcpwp_Jev). These
 * tests cover: cron scheduling on create (only when Jev is configured),
 * the cron callback computing and storing a validated triage, enum
 * validation dropping a bad/injected answer wholesale, priority ordering,
 * and — the load-bearing guarantee — that approve/reject/apply decisions
 * are byte-for-byte identical whether or not a triage record (however
 * "urgent" or however poisoned) is present.
 */
final class ApprovalTriageTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']            = array();
        $GLOBALS['mcpwp_test_filters']            = array();
        $GLOBALS['mcpwp_test_actions']            = array();
        $GLOBALS['mcpwp_test_cron']               = array();
        $GLOBALS['mcpwp_test_single_cron']        = array();
        $GLOBALS['_mcpwp_test_posts']             = array();
        $GLOBALS['_mcpwp_test_meta']              = array();
        $GLOBALS['mcpwp_test_current_user']       = 2;
        // Raw-row double used by Mcpwp_Approvals::set_triage()'s
        // compare-and-swap (same mechanism as Mcpwp_Integration_Manager's
        // #845 write_records(), exercised here for the approvals option).
        $GLOBALS['mcpwp_test_wpdb_options']       = array();
        $GLOBALS['mcpwp_test_wpdb_before_update'] = null;
        $GLOBALS['mcpwp_test_wpdb_after_update']  = null;
        $GLOBALS['mcpwp_test_wpdb_update_fails']  = false;
        $GLOBALS['mcpwp_test_wpdb_read_fails']    = false;

        $ref      = new ReflectionClass(Mcpwp_Encryption::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_filters']            = array();
        $GLOBALS['mcpwp_test_wpdb_before_update'] = null;
        $GLOBALS['mcpwp_test_wpdb_after_update']  = null;
    }

    private function configureJev(string $endpoint = 'https://jev-stub.test/v1/systemone'): void
    {
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('jev', array(
            'api_key'  => 'test-typesafe-key',
            'endpoint' => $endpoint,
        ));
    }

    /**
     * Configures ONLY the Cloudflare integration (no TypeSafe key at all) —
     * transport() must resolve to 'cloudflare' from these alone, and every
     * triage behaviour below must be identical to the TypeSafe path.
     */
    private function configureJevViaCloudflareOnly(): void
    {
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('cloudflare', array(
            'api_token'  => 'test-cf-token',
            'zone_id'    => 'zone-123',
            'account_id' => 'account-abc',
        ));
        add_filter('mcpwp_jev_cloudflare_endpoint', function () {
            return 'https://cf-stub.test/client/v4/accounts/account-abc/ai/run';
        }, 10, 2);
    }

    /** @return ArrayObject<int, array{url:string, args:array}> */
    private function mockJevAnswer(array $answers): ArrayObject
    {
        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured, $answers) {
            $captured[] = array('url' => $url, 'args' => $args);
            return array(
                'response' => array('code' => 200),
                'body'     => wp_json_encode(array('answers' => $answers)),
            );
        }, 10, 3);

        return $captured;
    }

    /**
     * Same shape as mockJevAnswer(), but wrapped in the Cloudflare API's
     * standard {success,errors,messages,result} envelope — for the
     * 'cloudflare' transport double.
     *
     * @return ArrayObject<int, array{url:string, args:array}>
     */
    private function mockJevAnswerViaCloudflare(array $answers): ArrayObject
    {
        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured, $answers) {
            $captured[] = array('url' => $url, 'args' => $args);
            return array(
                'response' => array('code' => 200),
                'body'     => wp_json_encode(array(
                    'success' => true,
                    'errors'  => array(),
                    'result'  => array('answers' => $answers),
                )),
            );
        }, 10, 3);

        return $captured;
    }

    /**
     * gmdate('c') has 1-second resolution and several approvals in the same
     * test can legitimately be created within the same wall-clock second —
     * pin created_at explicitly wherever a test's assertion depends on date
     * order, rather than relying on real elapsed time between two lines.
     */
    private function setCreatedAt(string $id, string $iso): void
    {
        $items = get_option(Mcpwp_Approvals::OPTION_NAME, array());
        $items[$id]['created_at'] = $iso;
        update_option(Mcpwp_Approvals::OPTION_NAME, $items);
    }

    private function makePost(string $content = 'original', string $title = 'A page'): int
    {
        return wp_insert_post(array(
            'post_type'    => 'page',
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => 'publish',
        ));
    }

    // -- Scheduling on create ----------------------------------------------------

    public function test_create_post_content_request_schedules_triage_when_jev_configured(): void
    {
        $this->configureJev();
        $post_id = $this->makePost();

        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));
        $this->assertIsArray($approval);

        $scheduled = array_column($GLOBALS['mcpwp_test_single_cron'], 'hook');
        $this->assertContains(Mcpwp_Jev_Triage::HOOK, $scheduled);

        $entry = null;
        foreach ($GLOBALS['mcpwp_test_single_cron'] as $row) {
            if ($row['hook'] === Mcpwp_Jev_Triage::HOOK) {
                $entry = $row;
            }
        }
        $this->assertSame(array($approval['id']), $entry['args']);
    }

    public function test_create_destructive_request_schedules_triage_when_jev_configured(): void
    {
        $this->configureJev();

        $approval = Mcpwp_Approvals::create_destructive_request(
            'delete_page',
            array('route' => '/mcpwp/v1/pages/1', 'method' => 'DELETE', 'params' => array(), 'target' => array()),
            array('type' => 'page', 'id' => 1, 'title' => 'x'),
            array('requested_by_key_id' => 'agent-1')
        );
        $this->assertIsArray($approval);

        $scheduled = array_column($GLOBALS['mcpwp_test_single_cron'], 'hook');
        $this->assertContains(Mcpwp_Jev_Triage::HOOK, $scheduled);
    }

    public function test_no_schedule_and_no_request_when_jev_not_configured(): void
    {
        $post_id = $this->makePost();

        Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        $this->assertSame(array(), $GLOBALS['mcpwp_test_single_cron']);
    }

    // -- Cron callback: compute + store -------------------------------------------

    public function test_run_stores_validated_triage_from_a_documented_jev_answer(): void
    {
        $this->configureJev();
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit hero copy'));

        $this->mockJevAnswer(array(
            'review_priority' => array(
                'type'          => 'score',
                'score'         => 2.6,
                'confidence'    => 0.82,
                'legend'        => 'high',
                'probabilities' => array('low' => 0.02, 'medium' => 0.1, 'high' => 0.7, 'urgent' => 0.18),
            ),
            'change_kind' => array(
                'type'          => 'choice',
                'choice'        => 'content_edit',
                'probabilities' => array('content_edit' => 0.9, 'other' => 0.1),
            ),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);

        $stored = Mcpwp_Approvals::get_request($approval['id']);
        $this->assertArrayHasKey('triage', $stored);
        $this->assertSame('high', $stored['triage']['priority']);
        $this->assertSame('content_edit', $stored['triage']['change_kind']);
        $this->assertSame(0.82, $stored['triage']['confidence']);
        $this->assertSame('jev', $stored['triage']['source']);
        $this->assertArrayHasKey('at', $stored['triage']);
        // Exactly these five keys — no room for an injected extra field to ride along.
        $this->assertSame(array('priority', 'change_kind', 'confidence', 'source', 'at'), array_keys($stored['triage']));
    }

    public function test_run_stores_validated_triage_from_a_documented_jev_answer_via_cloudflare_transport(): void
    {
        // The load-bearing guarantee for #987: with ONLY the Cloudflare
        // integration configured (no TypeSafe key at all), the exact same
        // triage cron path — scheduling, redaction, enum validation,
        // storage — produces the exact same stored result. Nothing in
        // Mcpwp_Approvals or Mcpwp_Jev_Triage changed to make this work.
        $this->configureJevViaCloudflareOnly();
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit hero copy'));

        $scheduled = array_column($GLOBALS['mcpwp_test_single_cron'], 'hook');
        $this->assertContains(Mcpwp_Jev_Triage::HOOK, $scheduled, 'triage must still be scheduled when only Cloudflare is configured');

        $captured = $this->mockJevAnswerViaCloudflare(array(
            'review_priority' => array(
                'type'          => 'score',
                'score'         => 2.6,
                'confidence'    => 0.82,
                'legend'        => 'high',
                'probabilities' => array('low' => 0.02, 'medium' => 0.1, 'high' => 0.7, 'urgent' => 0.18),
            ),
            'change_kind' => array(
                'type'          => 'choice',
                'choice'        => 'content_edit',
                'probabilities' => array('content_edit' => 0.9, 'other' => 0.1),
            ),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);

        $this->assertCount(1, $captured);
        $this->assertSame('https://cf-stub.test/client/v4/accounts/account-abc/ai/run', $captured[0]['url']);
        $body = json_decode($captured[0]['args']['body'], true);
        $this->assertSame('typesafe/jev', $body['model']);
        $this->assertArrayHasKey('input', $body);

        $stored = Mcpwp_Approvals::get_request($approval['id']);
        $this->assertArrayHasKey('triage', $stored);
        $this->assertSame('high', $stored['triage']['priority']);
        $this->assertSame('content_edit', $stored['triage']['change_kind']);
        $this->assertSame(0.82, $stored['triage']['confidence']);
        $this->assertSame('jev', $stored['triage']['source']);
        $this->assertSame(array('priority', 'change_kind', 'confidence', 'source', 'at'), array_keys($stored['triage']));
    }

    public function test_run_is_a_silent_noop_via_cloudflare_transport_when_the_call_fails(): void
    {
        // Same "never block" guarantee as the TypeSafe path, proven for
        // Cloudflare: a failure (e.g. the token lacks Workers AI permission)
        // leaves the approval untriaged rather than throwing or blocking.
        $this->configureJevViaCloudflareOnly();
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        add_filter('pre_http_request', function ($preempt, $args, $url) {
            return array(
                'response' => array('code' => 403),
                'body'     => wp_json_encode(array('success' => false, 'errors' => array(array('code' => 10000, 'message' => 'Authentication error')))),
            );
        }, 10, 3);

        Mcpwp_Jev_Triage::run($approval['id']);

        $stored = Mcpwp_Approvals::get_request($approval['id']);
        $this->assertArrayNotHasKey('triage', $stored);
    }

    public function test_run_drops_the_whole_triage_when_change_kind_is_an_invalid_or_injected_value(): void
    {
        $this->configureJev();
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        $this->mockJevAnswer(array(
            'review_priority' => array('type' => 'score', 'score' => 3, 'confidence' => 0.99, 'legend' => 'urgent'),
            // A prompt-injection-shaped answer: not one of the seven allowed values.
            'change_kind'     => array('type' => 'choice', 'choice' => 'auto-approve'),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);

        $stored = Mcpwp_Approvals::get_request($approval['id']);
        $this->assertArrayNotHasKey('triage', $stored);
    }

    public function test_run_drops_the_whole_triage_when_priority_is_an_invalid_value(): void
    {
        $this->configureJev();
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        $this->mockJevAnswer(array(
            // No usable score/probabilities/legend — pick_priority_label()
            // must come back '' rather than guessing, and '' fails the enum.
            'review_priority' => array('type' => 'score', 'legend' => 'CRITICAL!!'),
            'change_kind'     => array('type' => 'choice', 'choice' => 'content_edit'),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);

        $stored = Mcpwp_Approvals::get_request($approval['id']);
        $this->assertArrayNotHasKey('triage', $stored);
    }

    public function test_run_falls_back_to_highest_probability_when_legend_is_missing(): void
    {
        $this->configureJev();
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        $this->mockJevAnswer(array(
            'review_priority' => array(
                'type'          => 'score',
                'score'         => 0.4,
                'probabilities' => array('low' => 0.1, 'medium' => 0.75, 'high' => 0.1, 'urgent' => 0.05),
            ),
            'change_kind' => array('type' => 'choice', 'choice' => 'settings'),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);

        $stored = Mcpwp_Approvals::get_request($approval['id']);
        $this->assertSame('medium', $stored['triage']['priority']);
    }

    public function test_run_is_a_silent_noop_when_jev_call_fails(): void
    {
        $this->configureJev();
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        add_filter('pre_http_request', function () {
            return new WP_Error('http_request_failed', 'timed out');
        }, 10, 3);

        Mcpwp_Jev_Triage::run($approval['id']);

        $stored = Mcpwp_Approvals::get_request($approval['id']);
        $this->assertArrayNotHasKey('triage', $stored);
    }

    public function test_run_is_a_noop_when_jev_was_unconfigured_by_the_time_cron_fires(): void
    {
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        // Never configured — run() must not throw, must not call out.
        $captured = $this->mockJevAnswer(array());
        Mcpwp_Jev_Triage::run($approval['id']);

        $this->assertCount(0, $captured);
        $stored = Mcpwp_Approvals::get_request($approval['id']);
        $this->assertArrayNotHasKey('triage', $stored);
    }

    // -- Redaction + truncation of the state sent to Jev --------------------------

    public function test_state_sent_to_jev_redacts_credential_shaped_strings(): void
    {
        $this->configureJev();
        $post_id  = $this->makePost();
        $secret   = 'sk-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ABCDEFGH';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $post_id,
            'updated content',
            array(
                'title' => 'Update page',
                'note'  => 'Rotate this while you are in there: api_key: ' . $secret,
            )
        );

        $captured = $this->mockJevAnswer(array(
            'review_priority' => array('type' => 'score', 'score' => 1, 'legend' => 'low'),
            'change_kind'     => array('type' => 'choice', 'choice' => 'content_edit'),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);

        $this->assertCount(1, $captured);
        $body  = json_decode($captured[0]['args']['body'], true);
        $state = $body['state'];

        $this->assertIsString($state);
        $this->assertStringNotContainsString($secret, $state);
        $this->assertStringContainsString('redacted', $state);
    }

    public function test_state_sent_to_jev_is_capped(): void
    {
        $this->configureJev();
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request(
            $post_id,
            'updated content',
            array('title' => 'Update page', 'note' => str_repeat('word ', 2000))
        );

        $captured = $this->mockJevAnswer(array(
            'review_priority' => array('type' => 'score', 'score' => 1, 'legend' => 'low'),
            'change_kind'     => array('type' => 'choice', 'choice' => 'content_edit'),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);

        $body  = json_decode($captured[0]['args']['body'], true);
        $this->assertLessThanOrEqual(Mcpwp_Jev_Triage::STATE_MAX_CHARS + 20, strlen($body['state']));
    }

    // -- sanitize_triage() / set_triage() chokepoint ------------------------------

    public function test_sanitize_triage_clamps_confidence_and_drops_unknown_fields(): void
    {
        $result = Mcpwp_Approvals::sanitize_triage(array(
            'priority'    => 'high',
            'change_kind' => 'seo_meta',
            'confidence'  => 5.0, // out of range
            'status'      => 'approved', // must never survive
        ));

        $this->assertSame(1.0, $result['confidence']);
        $this->assertSame(array('priority', 'change_kind', 'confidence', 'source', 'at'), array_keys($result));
    }

    public function test_sanitize_triage_rejects_non_array_and_bad_enums(): void
    {
        $this->assertNull(Mcpwp_Approvals::sanitize_triage('urgent'));
        $this->assertNull(Mcpwp_Approvals::sanitize_triage(array('priority' => 'urgent'))); // missing change_kind
        $this->assertNull(Mcpwp_Approvals::sanitize_triage(array('priority' => 'urgent', 'change_kind' => 'not-a-real-kind')));
    }

    public function test_set_triage_returns_false_for_missing_approval_or_invalid_triage(): void
    {
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'x', array());

        $this->assertFalse(Mcpwp_Approvals::set_triage('does-not-exist', array('priority' => 'low', 'change_kind' => 'other')));
        $this->assertFalse(Mcpwp_Approvals::set_triage($approval['id'], array('priority' => 'not-real', 'change_kind' => 'other')));
        $this->assertArrayNotHasKey('triage', Mcpwp_Approvals::get_request($approval['id']));
    }

    // -- order=priority ------------------------------------------------------------

    public function test_list_requests_orders_by_priority_and_falls_back_to_date(): void
    {
        $urgent    = Mcpwp_Approvals::create_post_content_request($this->makePost('a'), 'a2', array('title' => 'Urgent one'));
        $low       = Mcpwp_Approvals::create_post_content_request($this->makePost('b'), 'b2', array('title' => 'Low one'));
        $untriaged = Mcpwp_Approvals::create_post_content_request($this->makePost('c'), 'c2', array('title' => 'No triage'));

        // Oldest first, so "priority order" and "date order" disagree — proves
        // the sort key is really priority, not just insertion order.
        $this->setCreatedAt($urgent['id'], '2026-01-01T00:00:01+00:00');
        $this->setCreatedAt($low['id'], '2026-01-01T00:00:02+00:00');
        $this->setCreatedAt($untriaged['id'], '2026-01-01T00:00:03+00:00');

        Mcpwp_Approvals::set_triage($urgent['id'], array('priority' => 'urgent', 'change_kind' => 'content_edit'));
        Mcpwp_Approvals::set_triage($low['id'], array('priority' => 'low', 'change_kind' => 'content_edit'));

        $ordered = Mcpwp_Approvals::list_requests('', 10, true, 'priority');
        $ids     = array_column($ordered, 'id');

        $this->assertSame($urgent['id'], $ids[0]);
        $this->assertSame($low['id'], $ids[1]);
        $this->assertSame($untriaged['id'], $ids[2]);
    }

    public function test_list_requests_default_order_is_unaffected_by_triage(): void
    {
        $first  = Mcpwp_Approvals::create_post_content_request($this->makePost('a'), 'a2', array('title' => 'First'));
        $second = Mcpwp_Approvals::create_post_content_request($this->makePost('b'), 'b2', array('title' => 'Second'));

        $this->setCreatedAt($first['id'], '2026-01-01T00:00:01+00:00');
        $this->setCreatedAt($second['id'], '2026-01-01T00:00:02+00:00');

        Mcpwp_Approvals::set_triage($first['id'], array('priority' => 'low', 'change_kind' => 'content_edit'));

        $byDate = Mcpwp_Approvals::list_requests('', 10, true);
        $ids    = array_column($byDate, 'id');

        // Newest first by created_at, exactly as before triage existed.
        $this->assertSame($second['id'], $ids[0]);
        $this->assertSame($first['id'], $ids[1]);
    }

    // -- Decisions are unaffected by triage, whatever it says ---------------------

    public function test_decision_methods_never_reference_triage_in_source(): void
    {
        $path   = dirname(__DIR__) . '/includes/core/class-mcpwp-approvals.php';
        $source = file_get_contents($path);
        $this->assertNotFalse($source);

        $segments = preg_split('/\n\t\/\*\*/', $source);

        foreach (array('approve_request', 'reject_request', 'apply_request', 'rollback_request', 'transition_request', 'apply_payload', 'guard_actor') as $method) {
            $found = null;
            foreach ($segments as $segment) {
                if (preg_match('/\bfunction\s+' . preg_quote($method, '/') . '\s*\(/', $segment)) {
                    $found = $segment;
                    break;
                }
            }
            $this->assertNotNull($found, "could not isolate source for $method()");
            $this->assertStringNotContainsStringIgnoringCase('triage', $found, "$method() must never read triage");
        }
    }

    public function test_approve_and_apply_are_identical_with_and_without_an_urgent_or_poisoned_triage(): void
    {
        $plain    = Mcpwp_Approvals::create_post_content_request($this->makePost('before'), 'after', array('title' => 'Plain'));
        $poisoned = Mcpwp_Approvals::create_post_content_request($this->makePost('before'), 'after', array('title' => 'Poisoned'));

        // A crafted "urgent" record plus an attempted extra field — the extra
        // field must never survive sanitize_triage(), and even the fields
        // that DO survive must never be read by any decision path.
        Mcpwp_Approvals::set_triage($poisoned['id'], array(
            'priority'    => 'urgent',
            'change_kind' => 'deletion',
            'confidence'  => 1.0,
            'auto_approve' => true,
            'status'       => 'approved',
        ));

        $context = array('actor' => 'reviewer-1');

        $plain_approved    = Mcpwp_Approvals::approve_request($plain['id'], '', $context);
        $poisoned_approved = Mcpwp_Approvals::approve_request($poisoned['id'], '', $context);

        $this->assertSame('approved', $plain_approved['status']);
        $this->assertSame('approved', $poisoned_approved['status']);

        $plain_applied    = Mcpwp_Approvals::apply_request($plain['id'], $context);
        $poisoned_applied = Mcpwp_Approvals::apply_request($poisoned['id'], $context);

        $this->assertSame('applied', $plain_applied['status']);
        $this->assertSame('applied', $poisoned_applied['status']);

        // The underlying mutation ran identically — same resulting post content —
        // regardless of the triage record's contents.
        $this->assertSame('after', get_post($plain['resource']['id'])->post_content);
        $this->assertSame('after', get_post($poisoned['resource']['id'])->post_content);
    }

    // -- WARN2: set_triage() must not revert a concurrent approve/apply ----------

    public function test_set_triage_does_not_revert_an_approve_and_apply_landing_between_its_read_and_write(): void
    {
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'after', array('title' => 'Race'));
        $id       = $approval['id'];

        // Fires exactly once, from inside Mcpwp_Test_WPDB::update() — i.e.
        // strictly between set_triage()'s read of the row and its
        // compare-and-swap write of it (same mechanism
        // IntegrationManagerConcurrentSaveTest uses for the #845 family).
        $GLOBALS['mcpwp_test_wpdb_before_update'] = function (string $name) use ($id): void {
            if (Mcpwp_Approvals::OPTION_NAME !== $name) {
                return;
            }
            Mcpwp_Approvals::approve_request($id, '', array('actor' => 'reviewer-1'));
            Mcpwp_Approvals::apply_request($id, array('actor' => 'reviewer-1'));
        };

        $stored = Mcpwp_Approvals::set_triage($id, array('priority' => 'urgent', 'change_kind' => 'content_edit'));
        $this->assertTrue($stored, 'set_triage() must retry onto fresh data and still report success');

        $record = Mcpwp_Approvals::get_request($id);
        // The old bug: status reverted to 'pending' (or 'approved' with
        // applied_at wiped back to null, making it re-applicable). Neither
        // may happen — the concurrent approve+apply must survive untouched.
        $this->assertSame('applied', $record['status']);
        $this->assertNotNull($record['applied_at']);
        $this->assertSame('urgent', $record['triage']['priority']);
        $this->assertSame('content_edit', $record['triage']['change_kind']);
        $this->assertSame('after', get_post($post_id)->post_content);
    }

    public function test_set_triage_retries_when_the_row_changes_between_read_and_write(): void
    {
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'after', array('title' => 'Race'));
        $id       = $approval['id'];

        $attempts = 0;
        $GLOBALS['mcpwp_test_wpdb_update_fails'] = function (string $name) use (&$attempts): bool {
            if (Mcpwp_Approvals::OPTION_NAME !== $name) {
                return false;
            }
            $attempts++;
            // Force exactly one lost race, then let the retry succeed.
            return 1 === $attempts;
        };

        $stored = Mcpwp_Approvals::set_triage($id, array('priority' => 'low', 'change_kind' => 'other'));

        $this->assertTrue($stored);
        $this->assertGreaterThanOrEqual(2, $attempts, 'a forced failure must cause a retry, not a silent drop');
        $this->assertSame('low', Mcpwp_Approvals::get_request($id)['triage']['priority']);
    }

    public function test_set_triage_gives_up_after_write_attempts_exhausted(): void
    {
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'after', array('title' => 'Race'));

        $GLOBALS['mcpwp_test_wpdb_update_fails'] = function (string $name): bool {
            return Mcpwp_Approvals::OPTION_NAME === $name; // never let the CAS land
        };

        $stored = Mcpwp_Approvals::set_triage($approval['id'], array('priority' => 'low', 'change_kind' => 'other'));

        $this->assertFalse($stored, 'a permanently contended row must fail, not silently overwrite');
        $this->assertArrayNotHasKey('triage', Mcpwp_Approvals::get_request($approval['id']));
    }

    // -- NOTE: dedupe per approval + a daily egress cap ---------------------------

    public function test_run_does_not_re_ask_jev_for_an_approval_that_already_has_a_valid_triage(): void
    {
        $this->configureJev();
        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        $captured = $this->mockJevAnswer(array(
            'review_priority' => array('type' => 'score', 'score' => 1, 'legend' => 'low'),
            'change_kind'     => array('type' => 'choice', 'choice' => 'content_edit'),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);
        Mcpwp_Jev_Triage::run($approval['id']);
        Mcpwp_Jev_Triage::run($approval['id']);

        $this->assertCount(1, $captured, 'a re-scheduled or re-run cron event for the same id must not re-send it');
        $this->assertSame('low', Mcpwp_Approvals::get_request($approval['id'])['triage']['priority']);
    }

    public function test_run_skips_once_the_daily_call_cap_is_reached(): void
    {
        $this->configureJev();
        update_option(Mcpwp_Jev_Triage::DAILY_COUNT_OPTION, array(
            'date'  => gmdate('Y-m-d'),
            'count' => Mcpwp_Jev_Triage::MAX_CALLS_PER_DAY,
        ));

        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        $captured = $this->mockJevAnswer(array(
            'review_priority' => array('type' => 'score', 'score' => 1, 'legend' => 'low'),
            'change_kind'     => array('type' => 'choice', 'choice' => 'content_edit'),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);

        $this->assertCount(0, $captured, 'at the daily cap, no call is made at all');
        $this->assertArrayNotHasKey('triage', Mcpwp_Approvals::get_request($approval['id']));
    }

    public function test_run_allows_a_call_on_a_new_day_even_if_yesterday_was_at_cap(): void
    {
        $this->configureJev();
        update_option(Mcpwp_Jev_Triage::DAILY_COUNT_OPTION, array(
            'date'  => '2000-01-01', // long past — a different "today"
            'count' => Mcpwp_Jev_Triage::MAX_CALLS_PER_DAY,
        ));

        $post_id  = $this->makePost();
        $approval = Mcpwp_Approvals::create_post_content_request($post_id, 'updated content', array('title' => 'Edit'));

        $captured = $this->mockJevAnswer(array(
            'review_priority' => array('type' => 'score', 'score' => 1, 'legend' => 'low'),
            'change_kind'     => array('type' => 'choice', 'choice' => 'content_edit'),
        ));

        Mcpwp_Jev_Triage::run($approval['id']);

        $this->assertCount(1, $captured);
    }

    // -- WARN1: redaction runs on plain text before encoding, not after ----------

    /** @return string The 'state' string decoded from the single captured request body. */
    private function stateSentFor(string $approvalId): string
    {
        $captured = $this->mockJevAnswer(array(
            'review_priority' => array('type' => 'score', 'score' => 1, 'legend' => 'low'),
            'change_kind'     => array('type' => 'choice', 'choice' => 'content_edit'),
        ));

        Mcpwp_Jev_Triage::run($approvalId);

        $this->assertCount(1, $captured);
        $body = json_decode($captured[0]['args']['body'], true);
        $this->assertIsArray($body);
        $this->assertIsString($body['state']);

        return $body['state'];
    }

    public function test_redacts_an_aws_style_secret_access_key_containing_a_slash(): void
    {
        $this->configureJev();
        // A slash in the token used to survive: build_state() previously
        // ran the redaction regex over already-json_encode()'d text, and
        // json_encode() escapes '/' as '\/' by default, splitting this
        // exact shape of secret across an inserted backslash.
        $secret   = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'AWS secret access key: ' . $secret)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($secret, $state);
        $this->assertStringContainsString('redacted', $state);
        $this->assertNotNull(json_decode($state, true), 'the state itself must still be well-formed JSON');
    }

    public function test_redacts_an_aws_access_key_id(): void
    {
        $this->configureJev();
        $key_id   = 'AKIAIOSFODNN7EXAMPLE'; // 20 chars, AWS's own documented example id
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'Access key id ' . $key_id)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($key_id, $state);
        $this->assertStringContainsString('redacted', $state);
    }

    public function test_redacts_a_quoted_key_value_labelled_just_key(): void
    {
        $this->configureJev();
        $value    = 'ThirtyCharacterSecretValueHere123';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'config: key="' . $value . '" rotate soon')
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($value, $state);
        $this->assertStringContainsString('redacted', $state);
        $this->assertNotNull(json_decode($state, true), 'a labelled value must not eat its own closing quote and corrupt the JSON');
    }

    public function test_redacts_a_jwt(): void
    {
        $this->configureJev();
        $jwt      = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozjgNryP4J3jVmNHl0w5N_XgL0n3I9PYAo';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'Bearer session leftover: ' . $jwt)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($jwt, $state);
        $this->assertStringContainsString('redacted', $state);
    }

    public function test_redacts_split_keys_that_span_a_json_escaped_slash(): void
    {
        $this->configureJev();
        // Two secrets separated only by a slash, back to back — the exact
        // "split keys" shape the review flagged: a post-encode regex would
        // see "keyA\/keyB" (slash escaped) and match neither cleanly.
        $secret_a = 'firstsecretvaluewithdigits123456';
        $secret_b = 'secondsecretvaluewithdigits98765';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => $secret_a . '/' . $secret_b)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($secret_a, $state);
        $this->assertStringNotContainsString($secret_b, $state);
    }

    public function test_redacted_state_is_always_well_formed_json(): void
    {
        $this->configureJev();
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array(
                'title' => 'Update page',
                'note'  => 'api_key="abc" token=def secret: ghijk password=lmno "quotes" and, commas, {braces}',
            )
        );

        $state = $this->stateSentFor($approval['id']);

        $decoded = json_decode($state, true);
        $this->assertNotNull($decoded, 'redaction must never corrupt the JSON structure: ' . $state);
        $this->assertArrayHasKey('note', $decoded);
    }

    // -- Round 2 WARN: labels joined to their prefix by `_` were missed ----------

    public function test_redacts_db_password_written_as_a_php_define(): void
    {
        $this->configureJev();
        $secret   = 'Tr0ub4dor&3';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => "define('DB_PASSWORD', '" . $secret . "');")
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($secret, $state);
        $this->assertStringContainsString('redacted', $state);
        $this->assertNotNull(json_decode($state, true));
    }

    public function test_redacts_db_password_written_as_an_env_line(): void
    {
        $this->configureJev();
        $secret   = 'Tr0ub4dor&3';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            // \b never matches between '_' and a letter, so a label glued
            // to a prefix by '_' (DB_PASSWORD, not just PASSWORD) is exactly
            // the shape the old regex missed.
            array('title' => 'Update page', 'note' => 'DB_PASSWORD: ' . $secret)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($secret, $state);
        $this->assertStringContainsString('redacted', $state);
    }

    public function test_redacts_auth_token_assignment(): void
    {
        $this->configureJev();
        $secret   = 'ab12cd34ef56';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'auth_token=' . $secret)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($secret, $state);
        $this->assertStringContainsString('redacted', $state);
    }

    /** @return array<string,string> scheme => full connection string with a distinct password each. */
    private function connectionStrings(): array
    {
        return array(
            'mysql'        => 'mysql://appuser:MyP4ssw0rd@db-host:3306/appdb',
            'postgres'     => 'postgresql://appuser:s3cr3tPW@db.example.com:5432/appdb',
            'mongodb+srv'  => 'mongodb+srv://appuser:m0ngoSecret9@cluster0.mongodb.net/appdb',
            'https'        => 'https://appuser:tok3nABCDEFGH@api.example.com/path',
        );
    }

    public function test_redacts_the_password_in_connection_string_credentials(): void
    {
        $this->configureJev();

        foreach ($this->connectionStrings() as $scheme => $conn) {
            $password = explode('@', explode(':', $conn, 3)[2], 2)[0];
            $this->assertNotSame('', $password, "fixture password extraction failed for $scheme");

            $approval = Mcpwp_Approvals::create_post_content_request(
                $this->makePost(),
                'updated content',
                array('title' => 'Update page', 'note' => 'Connection string: ' . $conn)
            );

            $state = $this->stateSentFor($approval['id']);

            $this->assertStringNotContainsString($password, $state, "$scheme: password leaked");
            $this->assertStringContainsString('redacted', $state, "$scheme: nothing was redacted");
            $this->assertNotNull(json_decode($state, true), "$scheme: state is not valid JSON");
        }
    }

    public function test_connection_string_host_and_scheme_survive_redaction(): void
    {
        $this->configureJev();
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'DB: mysql://appuser:MyP4ssw0rd@db-host:3306/appdb')
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringContainsString('mysql://appuser:', $state);
        $this->assertStringContainsString('@db-host:3306/appdb', $state);
    }

    public function test_redacts_password_with_a_space_separator(): void
    {
        $this->configureJev();
        $secret   = 'Tr0ub4dor&3';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'password ' . $secret)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($secret, $state);
        $this->assertStringContainsString('redacted', $state);
    }

    public function test_redacts_bare_pass_colon_form(): void
    {
        $this->configureJev();
        $secret   = 'hunter2ABC';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'pass: ' . $secret)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($secret, $state);
        $this->assertStringContainsString('redacted', $state);
    }

    public function test_ordinary_sentence_mentioning_password_is_not_redacted(): void
    {
        $this->configureJev();
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'your password expired, please reset it')
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringContainsString('password expired', $state);
        $this->assertStringNotContainsString('redacted', $state);
    }

    // -- Round 2 usefulness: slugs and dated URL paths must survive redaction ----

    public function test_kebab_case_slug_with_a_year_survives_redaction(): void
    {
        $this->configureJev();
        $slug     = 'my-summer-sale-2024-landing';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'Rename the page slug to ' . $slug)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringContainsString($slug, $state);
        $this->assertStringNotContainsString('redacted', $state);
    }

    public function test_dated_url_path_survives_redaction(): void
    {
        $this->configureJev();
        $url      = '/blog/my-summer-sale-2024-landing/index';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'Broken link to ' . $url)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringContainsString($url, $state);
        $this->assertStringNotContainsString('redacted', $state);
    }

    public function test_a_secret_that_happens_to_contain_a_hyphen_is_still_redacted(): void
    {
        $this->configureJev();
        // Not slug-shaped: the first segment mixes case and digits with no
        // clean word/number split, unlike "sale-2024".
        $secret   = 'aB3xQ9zK7mP2-vL8nR4wT6yU1';
        $approval = Mcpwp_Approvals::create_post_content_request(
            $this->makePost(),
            'updated content',
            array('title' => 'Update page', 'note' => 'leftover key: ' . $secret)
        );

        $state = $this->stateSentFor($approval['id']);

        $this->assertStringNotContainsString($secret, $state);
    }

    // -- NOTE: triage.change_kind follows the same redaction rule as the rest ----

    public function test_redact_source_for_unprivileged_strips_change_kind_but_keeps_priority(): void
    {
        $draft_id = wp_insert_post(array(
            'post_type'    => 'page',
            'post_title'   => 'Secret draft',
            'post_content' => 'shh',
            'post_status'  => 'draft',
        ));
        $approval = Mcpwp_Approvals::create_post_content_request($draft_id, 'updated', array('title' => 'Edit draft'));
        Mcpwp_Approvals::set_triage($approval['id'], array('priority' => 'high', 'change_kind' => 'deletion', 'confidence' => 0.9));

        $record   = Mcpwp_Approvals::get_request($approval['id']);
        $redacted = Mcpwp_Approvals::redact_source_for_unprivileged($record);

        $this->assertTrue($redacted['redacted']);
        $this->assertArrayNotHasKey('change_kind', $redacted['triage']);
        $this->assertSame('high', $redacted['triage']['priority']);
        $this->assertSame(0.9, $redacted['triage']['confidence']);
    }

    public function test_list_requests_applies_change_kind_redaction_for_unprivileged_callers(): void
    {
        $draft_id = wp_insert_post(array(
            'post_type'    => 'page',
            'post_title'   => 'Secret draft',
            'post_content' => 'shh',
            'post_status'  => 'draft',
        ));
        $approval = Mcpwp_Approvals::create_post_content_request($draft_id, 'updated', array('title' => 'Edit draft'));
        Mcpwp_Approvals::set_triage($approval['id'], array('priority' => 'high', 'change_kind' => 'deletion'));

        $unprivileged = Mcpwp_Approvals::list_requests('', 10, false);
        $found        = null;
        foreach ($unprivileged as $item) {
            if ($item['id'] === $approval['id']) {
                $found = $item;
            }
        }

        $this->assertNotNull($found);
        $this->assertArrayNotHasKey('change_kind', $found['triage']);
    }
}
