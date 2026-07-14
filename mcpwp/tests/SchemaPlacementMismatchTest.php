<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-seo-audit.php';

/**
 * #616 (part 1) — schema-on-primary-content placement check.
 */
final class SchemaPlacementMismatchTest extends TestCase
{
    private Mcpwp_REST_SEO_Audit $controller;

    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options'] = array();
        $this->controller = new Mcpwp_REST_SEO_Audit();
    }

    public function test_faqpage_matching_page_content_is_not_flagged(): void
    {
        $entity = array(
            '@type'      => 'FAQPage',
            'mainEntity' => array(
                array(
                    '@type'          => 'Question',
                    'name'           => 'What is your return policy?',
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => 'Our return policy allows thirty days for refunds on unused items.',
                    ),
                ),
            ),
        );

        $visibleText = $this->callMethod('get_structured_data_visible_text', array(
            null,
            '<p>What is your return policy?</p><p>Our return policy allows thirty days for refunds on unused items.</p>',
        ));

        $mismatch = $this->callMethod('schema_entity_content_missing_from_page', array('faqpage', $entity, $visibleText));

        $this->assertFalse($mismatch, 'FAQ content present on the page must not be flagged as a mismatch.');
    }

    public function test_faqpage_content_not_on_page_is_flagged(): void
    {
        $entity = array(
            '@type'      => 'FAQPage',
            'mainEntity' => array(
                array(
                    '@type'          => 'Question',
                    'name'           => 'What is your return policy?',
                    'acceptedAnswer' => array(
                        '@type' => 'Answer',
                        'text'  => 'Our return policy allows thirty days for refunds on unused items.',
                    ),
                ),
            ),
        );

        $visibleText = $this->callMethod('get_structured_data_visible_text', array(
            null,
            '<p>Welcome to our store.</p><p>Browse our latest arrivals and seasonal collections below.</p>',
        ));

        $mismatch = $this->callMethod('schema_entity_content_missing_from_page', array('faqpage', $entity, $visibleText));

        $this->assertTrue($mismatch, 'FAQ content absent from the page must be flagged as a mismatch.');
    }

    public function test_howto_matching_steps_are_not_flagged(): void
    {
        $entity = array(
            '@type' => 'HowTo',
            'step'  => array(
                array('@type' => 'HowToStep', 'text' => 'Preheat the oven to three hundred fifty degrees.'),
                array('@type' => 'HowToStep', 'text' => 'Bake the tray for twenty five minutes.'),
            ),
        );

        $visibleText = $this->callMethod('get_structured_data_visible_text', array(
            null,
            '<p>Preheat the oven to three hundred fifty degrees.</p><p>Bake the tray for twenty five minutes.</p>',
        ));

        $mismatch = $this->callMethod('schema_entity_content_missing_from_page', array('howto', $entity, $visibleText));

        $this->assertFalse($mismatch);
    }

    public function test_entity_with_no_substantial_fragments_is_not_flagged(): void
    {
        // Fragments under 15 chars are not meaningful enough to check — this
        // must not produce a false positive on absence of evidence.
        $entity = array(
            '@type'      => 'FAQPage',
            'mainEntity' => array(
                array(
                    '@type'          => 'Question',
                    'name'           => 'Hi',
                    'acceptedAnswer' => array('@type' => 'Answer', 'text' => 'OK'),
                ),
            ),
        );

        $mismatch = $this->callMethod('schema_entity_content_missing_from_page', array('faqpage', $entity, 'completely unrelated visible text'));

        $this->assertFalse($mismatch);
    }

    /**
     * @param mixed[] $args
     * @return mixed
     */
    private function callMethod(string $method, array $args)
    {
        $ref = new ReflectionMethod(Mcpwp_REST_SEO_Audit::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->controller, $args);
    }
}
