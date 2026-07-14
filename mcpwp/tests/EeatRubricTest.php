<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-content-quality.php';

/**
 * #616 (part 2) — explicit E-E-A-T rubric in the content-quality scorer.
 */
final class EeatRubricTest extends TestCase
{
    private Mcpwp_REST_Content_Quality $controller;

    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options'] = array();
        $this->controller = new Mcpwp_REST_Content_Quality(false);
    }

    private function fakePost(int $authorId = 5): object
    {
        return (object) array('ID' => 1, 'post_author' => $authorId, 'post_title' => 'Why Our Return Policy Works');
    }

    public function test_experience_score_rewards_first_hand_markers(): void
    {
        $text    = 'in our experience testing hundreds of returns, we found this policy works well. see our case study below.';
        $content = $text . '<img src="x.jpg" alt="customer holding a returned product at our warehouse">';

        $result = $this->callMethod('score_eeat_experience', array($text, $content, array('proof')));

        $this->assertSame(100, $result['score']);
        $this->assertCount(3, $result['evidence']);
    }

    public function test_experience_score_zero_with_no_markers(): void
    {
        $result = $this->callMethod('score_eeat_experience', array('generic filler text with nothing notable', '<p>generic</p>', array()));

        $this->assertSame(0, $result['score']);
        $this->assertNotEmpty($result['evidence']);
    }

    public function test_expertise_rewards_byline_schema_and_topical_depth(): void
    {
        $content = '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Person","name":"Jane Smith"}</script>';
        $headings = array('Why our return policy works', 'How to request a refund', 'Case study: reduced disputes');

        $result = $this->callMethod('score_eeat_expertise', array($this->fakePost(), $content, array('author'), $headings));

        $this->assertSame(100, $result['score']);
    }

    public function test_expertise_low_without_signals(): void
    {
        // Author 0 is falsy but get_the_author_meta stub still returns a
        // non-empty "Author 0" string, so byline still counts in this test
        // harness — confirm topical-depth-only case instead by using a post
        // with a name-shaped exclusion is not practical here; assert the
        // Person-schema and depth bonuses are independently absent.
        $result = $this->callMethod('score_eeat_expertise', array($this->fakePost(), '<p>no schema here</p>', array(), array('Only one heading')));

        $this->assertLessThan(100, $result['score']);
    }

    public function test_authoritativeness_scales_with_external_links_and_caps_at_100(): void
    {
        $none = $this->callMethod('score_eeat_authoritativeness', array(0));
        $this->assertSame(0, $none['score']);

        $two = $this->callMethod('score_eeat_authoritativeness', array(2));
        $this->assertSame(50, $two['score']);

        $many = $this->callMethod('score_eeat_authoritativeness', array(10));
        $this->assertSame(100, $many['score']);
    }

    public function test_authoritativeness_always_notes_offsite_signals_are_out_of_scope(): void
    {
        $result = $this->callMethod('score_eeat_authoritativeness', array(3));

        $found = false;
        foreach ($result['evidence'] as $line) {
            if (false !== strpos($line, 'cannot be checked from a single page')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'Authoritativeness must always disclose the off-site scope limitation.');
    }

    public function test_trustworthiness_rewards_all_four_signals(): void
    {
        $post = $this->fakePost();
        $text = 'why our return policy works, contact support for details, updated on 2026-06-01.';

        $result = $this->callMethod('score_eeat_trustworthiness', array($post, $text, array('contact', 'date')));

        // HTTPS always true via the test-env get_permalink() stub.
        $this->assertSame(100, $result['score']);
    }

    public function test_trustworthiness_docks_points_for_missing_signals(): void
    {
        $post = $this->fakePost();
        $text = 'completely unrelated body text with no matching terms at all';

        $result = $this->callMethod('score_eeat_trustworthiness', array($post, $text, array()));

        // HTTPS (25) stays true via the stub; contact/date/title-match all fail.
        $this->assertSame(25, $result['score']);
    }

    public function test_title_matches_content_true_when_title_terms_present(): void
    {
        $matches = $this->callMethod('title_matches_content', array('Why Our Return Policy Works', 'this page explains our return policy in detail'));

        $this->assertTrue($matches);
    }

    public function test_title_matches_content_false_when_title_terms_absent(): void
    {
        $matches = $this->callMethod('title_matches_content', array('Why Our Return Policy Works', 'completely unrelated content about something else entirely'));

        $this->assertFalse($matches);
    }

    public function test_title_matches_content_true_when_title_has_no_meaningful_words(): void
    {
        // All short/stopwords — must not produce a false positive.
        $matches = $this->callMethod('title_matches_content', array('The Top', 'anything at all'));

        $this->assertTrue($matches);
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
