<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-content-quality.php';

/**
 * #617 — experimental, opt-in GEO/AI-citability heuristic.
 */
final class GeoCitabilityExperimentalTest extends TestCase
{
    private Mcpwp_REST_Content_Quality $controller;

    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options'] = array();
        $this->controller = new Mcpwp_REST_Content_Quality(false);
    }

    public function test_all_checks_pass_scores_100(): void
    {
        $intro = 'How does our return policy work? This short paragraph directly answers the question up front.';
        $body  = str_repeat('Our return policy allows customers to send back unused items within thirty days of purchase. ', 8);
        $ld    = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"FAQPage"}</script>';
        $content = $ld . '<p>' . $intro . '</p>' . '<p>' . $body . '</p>';
        $headings = array(array('level' => 2, 'text' => 'Why does this policy work?'));

        $result = $this->callMethod('calculate_geo_citability_experimental', array($content, $intro . ' ' . $body, $headings));

        $this->assertSame(100, $result['score']);
        $this->assertCount(4, $result['checks']);
        foreach ($result['checks'] as $check) {
            $this->assertTrue($check['passed'], "Expected check '{$check['check']}' to pass.");
        }
    }

    public function test_no_signals_scores_low_but_not_zero(): void
    {
        $content  = '<p>Just a short line of text.</p>';
        $headings = array();

        $result = $this->callMethod('calculate_geo_citability_experimental', array($content, 'just a short line of text', $headings));

        // Short opening paragraph still passes the "concise opening" check —
        // that is expected and correct, not a bug.
        $this->assertLessThan(100, $result['score']);
        $this->assertGreaterThan(0, $result['score']);
    }

    public function test_basis_always_discloses_not_google_confirmed(): void
    {
        $result = $this->callMethod('calculate_geo_citability_experimental', array('<p>x</p>', 'x', array()));

        $this->assertStringContainsString('not Google-confirmed', $result['basis']);
    }

    public function test_question_heading_check_handles_array_shaped_headings_without_fatal(): void
    {
        // extract_heading_texts() returns array(['level'=>int,'text'=>string]),
        // not plain strings — this must not fatal (regression: it did once).
        $headings = array(array('level' => 2, 'text' => 'What is your return policy?'));

        $result = $this->callMethod('calculate_geo_citability_experimental', array('<p>content</p>', 'content', $headings));

        $questionCheck = null;
        foreach ($result['checks'] as $check) {
            if ('question_based_heading' === $check['check']) {
                $questionCheck = $check;
            }
        }

        $this->assertNotNull($questionCheck);
        $this->assertTrue($questionCheck['passed']);
    }

    public function test_field_is_absent_by_default_and_present_when_opted_in(): void
    {
        new Mcpwp_REST_Content_Quality(true); // populate the route registry for this check.
        $args = $this->findRouteArgs();
        $this->assertNotNull($args, 'Expected /seo/content-quality route to be registered.');
        $this->assertArrayHasKey('include_geo_experimental', $args);
        $this->assertFalse($args['include_geo_experimental']['default'], 'include_geo_experimental must default to false (opt-in only).');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findRouteArgs(): ?array
    {
        global $_mcpwp_test_registered_routes;

        foreach ((array) ($_mcpwp_test_registered_routes ?? array()) as $registration) {
            if (isset($registration['route']) && false !== strpos($registration['route'], '/seo/content-quality')) {
                return $registration['args'][0]['args'] ?? null;
            }
        }

        return null;
    }

    /**
     * @param mixed[] $args
     * @return mixed
     */
    private function callMethod(string $method, array $args)
    {
        $ref = new ReflectionMethod(Mcpwp_REST_Content_Quality::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->controller, $args);
    }
}
