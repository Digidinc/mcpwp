<?php

use PHPUnit\Framework\TestCase;

final class SeoAutofixTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options'] = array();

        // Stored issues reference real published posts. list_issues() is now
        // fail-closed by default (get_plan passes no reveal flag), so an issue
        // whose post is missing/non-public is excluded. Seed the referenced
        // posts as published so these plan-mapping cases exercise the include
        // path; the exclude path is covered in RestExhaustiveDraftLeakTest.
        $GLOBALS['_mcpwp_test_posts'] = array();
        foreach (array( 10, 11, 12 ) as $pid) {
            $GLOBALS['_mcpwp_test_posts'][ $pid ] = (object) array(
                'ID'          => $pid,
                'post_type'   => 'page',
                'post_status' => 'publish',
                'post_title'  => 'Published page ' . $pid,
            );
        }
    }

    public function test_plan_maps_stored_issues_to_approval_safe_actions(): void
    {
        update_option(
            Mcpwp_SEO_Audit_Store::ISSUES_OPTION,
            array(
                'post-10-missing-meta' => array(
                    'id'                => 'post-10-missing-meta',
                    'status'            => 'open',
                    'last_seen_at'      => '2026-05-20T00:00:00+00:00',
                    'last_seen_run_id'  => 'run-1',
                    'post_id'           => 10,
                    'title'             => 'Service Page',
                    'url'               => 'https://example.com/service/',
                    'category'          => 'readiness',
                    'code'              => 'missing_meta_description',
                    'severity'          => 'warning',
                    'message'           => 'Missing meta description.',
                    'recommendation'    => 'Add one.',
                    'approval_required' => true,
                    'priority_score'    => 70,
                ),
                'post-11-orphan' => array(
                    'id'                => 'post-11-orphan',
                    'status'            => 'open',
                    'last_seen_at'      => '2026-05-20T00:00:00+00:00',
                    'last_seen_run_id'  => 'run-1',
                    'post_id'           => 11,
                    'title'             => 'Orphan Page',
                    'url'               => 'https://example.com/orphan/',
                    'category'          => 'readiness',
                    'code'              => 'orphan_content',
                    'severity'          => 'warning',
                    'message'           => 'No inbound links.',
                    'recommendation'    => 'Add internal links.',
                    'approval_required' => true,
                    'priority_score'    => 60,
                ),
            )
        );

        $plan = Mcpwp_SEO_Autofix::get_plan(array( 'limit' => 10 ));

        $this->assertSame('2026-05-20', $plan['schema_version']);
        $this->assertSame(2, $plan['summary']['actions']);
        $this->assertSame(2, $plan['summary']['can_prepare']);
        $this->assertSame(0, $plan['summary']['can_auto_apply']);
        $this->assertSame(2, $plan['summary']['needs_approval']);
        $this->assertSame('seo_meta_description', $plan['actions'][0]['strategy']);
        $this->assertSame('wp_validate_seo_readiness', $plan['actions'][0]['tool']);
        $this->assertFalse($plan['actions'][0]['can_auto_apply']);
        $this->assertSame('internal_link_suggestion', $plan['actions'][1]['strategy']);
        $this->assertSame('wp_suggest_internal_links', $plan['actions'][1]['tool']);
    }

    public function test_unknown_issue_code_stays_manual_review(): void
    {
        update_option(
            Mcpwp_SEO_Audit_Store::ISSUES_OPTION,
            array(
                'post-12-custom' => array(
                    'id'             => 'post-12-custom',
                    'status'         => 'open',
                    'last_seen_at'   => '2026-05-20T00:00:00+00:00',
                    'last_seen_run_id' => 'run-1',
                    'post_id'        => 12,
                    'category'       => 'readiness',
                    'code'           => 'custom_unknown_issue',
                    'severity'       => 'info',
                    'priority_score' => 10,
                ),
            )
        );

        $plan = Mcpwp_SEO_Autofix::get_plan(array( 'issue_id' => 'post-12-custom' ));

        $this->assertSame(1, $plan['summary']['manual_review']);
        $this->assertSame('manual_review', $plan['actions'][0]['strategy']);
        $this->assertFalse($plan['actions'][0]['can_auto_prepare']);
        $this->assertFalse($plan['actions'][0]['can_auto_apply']);
    }
}
