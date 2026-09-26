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
        $GLOBALS['mcpwp_test_options']      = array();
        $GLOBALS['mcpwp_test_filters']      = array();
        $GLOBALS['mcpwp_test_actions']      = array();
        $GLOBALS['mcpwp_test_cron']         = array();
        $GLOBALS['mcpwp_test_single_cron']  = array();
        $GLOBALS['_mcpwp_test_posts']       = array();
        $GLOBALS['_mcpwp_test_meta']        = array();
        $GLOBALS['mcpwp_test_current_user'] = 2;

        $ref      = new ReflectionClass(Mcpwp_Encryption::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_filters'] = array();
    }

    private function configureJev(string $endpoint = 'https://jev-stub.test/v1/systemone'): void
    {
        Mcpwp_Integration_Manager::get_instance()->set_provider_config('jev', array(
            'api_key'  => 'test-typesafe-key',
            'endpoint' => $endpoint,
        ));
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
}
