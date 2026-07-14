<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-api.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-seo-audit.php';

/**
 * #615 — structured-data validator classifies deprecated rich-result types.
 */
final class StructuredDataDeprecatedTypesTest extends TestCase
{
    private Mcpwp_REST_SEO_Audit $controller;

    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options'] = array();
        $this->controller = new Mcpwp_REST_SEO_Audit();
    }

    /**
     * @dataProvider deprecatedTypeProvider
     */
    public function test_deprecated_type_is_classified(string $rawType, string $expectedSince): void
    {
        $normalized = $this->callMethod('normalize_schema_type', array($rawType));
        $info       = $this->callMethod('get_deprecated_schema_type_info', array($normalized));

        $this->assertIsArray($info, "Expected '{$rawType}' to be classified as deprecated.");
        $this->assertSame($expectedSince, $info['since']);
        $this->assertNotEmpty($info['note']);
    }

    public function deprecatedTypeProvider(): array
    {
        return array(
            'HowTo'               => array('HowTo', '2023-09'),
            'FAQPage'             => array('FAQPage', '2026-05-07'),
            'ClaimReview'         => array('ClaimReview', '2025-06-12'),
            'EstimatedSalary'     => array('EstimatedSalary', '2025-06-12'),
            'LearningVideo'       => array('LearningVideo', '2025-06-12'),
            'SpecialAnnouncement' => array('SpecialAnnouncement', '2025-06-12'),
            'VehicleListing'      => array('VehicleListing', '2025-06-12'),
        );
    }

    public function test_active_type_is_not_classified_as_deprecated(): void
    {
        $normalized = $this->callMethod('normalize_schema_type', array('Product'));
        $info       = $this->callMethod('get_deprecated_schema_type_info', array($normalized));

        $this->assertNull($info, 'Product must not be flagged deprecated — it has an active rich result.');
    }

    public function test_book_is_deliberately_not_classified_as_deprecated(): void
    {
        // Book Actions was retired June 2025, then un-deprecated Nov 2025 — a
        // live Search feature still consumes this markup. Must stay "active".
        $normalized = $this->callMethod('normalize_schema_type', array('Book'));
        $info       = $this->callMethod('get_deprecated_schema_type_info', array($normalized));

        $this->assertNull($info, 'Book must not be flagged deprecated — it was un-deprecated Nov 2025.');
    }

    public function test_course_is_deliberately_not_classified_as_deprecated(): void
    {
        // Only the "Course List carousel" rich result was retired; "Course" is
        // a broad, still-generally-valid @type. Flagging the whole type risks
        // a false positive, so it is intentionally excluded (see code comment).
        $normalized = $this->callMethod('normalize_schema_type', array('Course'));
        $info       = $this->callMethod('get_deprecated_schema_type_info', array($normalized));

        $this->assertNull($info);
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
