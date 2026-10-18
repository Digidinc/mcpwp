<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/traits/trait-mcpwp-sanitization.php';

/**
 * Exposes the sanitization trait's protected bulk normalizer for assertion.
 */
final class BulkItemNormalizerHarness
{
    use Mcpwp_Sanitization;

    /**
     * @param mixed $items
     * @return array<string, mixed>
     */
    public function normalize($items, string $singular): array
    {
        return $this->normalize_bulk_items($items, $singular);
    }
}

/**
 * #707 — define_tool() silently discarded every JSON Schema constraint beyond
 * type/description/default, so `items`, `enum`, `properties` and `format`
 * authored in the tool definitions never reached the client.
 *
 * The visible symptom: `wp_bulk_update_pages` advertised `pages` as a bare
 * array, clients sent JSON *strings*, and the handler read `$item['id']` off a
 * string — every item failed "id is required for each page", zero pages updated.
 */
final class ToolSchemaConstraintsTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function schemaProperties(string $toolName): array
    {
        foreach ((new Mcpwp_Mcp_Free_Tools())->get_tools() as $tool) {
            if ($tool['name'] === $toolName) {
                return $tool['inputSchema']['properties'];
            }
        }

        $this->fail(sprintf('Tool %s is not registered.', $toolName));
    }

    public function test_define_tool_propagates_items_constraint(): void
    {
        $registry = new Mcpwp_Mcp_Free_Tools();
        $ref      = new ReflectionMethod(Mcpwp_Mcp_Tool_Registry::class, 'define_tool');
        $ref->setAccessible(true);

        $tool = $ref->invokeArgs($registry, array(
            'wp_fake_tool',
            'Fake tool used only by this test.',
            array(
                'ids' => array(
                    'type'  => 'array',
                    'items' => array('type' => 'integer'),
                ),
            ),
        ));

        $this->assertSame(
            array('type' => 'integer'),
            $tool['inputSchema']['properties']['ids']['items'],
            'define_tool() must carry the authored items constraint into the emitted schema.'
        );
    }

    public function test_define_tool_propagates_enum_format_and_properties(): void
    {
        $registry = new Mcpwp_Mcp_Free_Tools();
        $ref      = new ReflectionMethod(Mcpwp_Mcp_Tool_Registry::class, 'define_tool');
        $ref->setAccessible(true);

        $tool = $ref->invokeArgs($registry, array(
            'wp_fake_tool',
            'Fake tool used only by this test.',
            array(
                'status' => array('type' => 'string', 'enum' => array('draft', 'publish')),
                'when'   => array('type' => 'string', 'format' => 'date-time'),
                'nested' => array(
                    'type'       => 'object',
                    'properties' => array('id' => array('type' => 'integer')),
                ),
            ),
        ));

        $props = $tool['inputSchema']['properties'];

        $this->assertSame(array('draft', 'publish'), $props['status']['enum']);
        $this->assertSame('date-time', $props['when']['format']);
        $this->assertSame(array('id' => array('type' => 'integer')), $props['nested']['properties']);
    }

    public function test_every_array_typed_parameter_declares_its_item_type(): void
    {
        $offenders = array();

        foreach (array(new Mcpwp_Mcp_Free_Tools(), new Mcpwp_Mcp_Pro_Tools()) as $registry) {
            foreach ($registry->get_tools() as $tool) {
                $properties = $tool['inputSchema']['properties'];

                if (! is_array($properties)) {
                    continue;
                }

                foreach ($properties as $name => $definition) {
                    if (($definition['type'] ?? '') === 'array' && ! isset($definition['items'])) {
                        $offenders[] = $tool['name'] . '.' . $name;
                    }
                }
            }
        }

        $this->assertSame(
            array(),
            $offenders,
            "Array parameters without an `items` type let clients send anything (#707).\nOffenders:\n- "
                . implode("\n- ", $offenders)
        );
    }

    public function test_bulk_update_pages_declares_object_items_with_required_id(): void
    {
        $pages = $this->schemaProperties('wp_bulk_update_pages')['pages'];

        $this->assertSame('array', $pages['type']);
        $this->assertSame('object', $pages['items']['type']);
        $this->assertArrayHasKey('id', $pages['items']['properties']);
        $this->assertSame(array('id'), $pages['items']['required']);
    }

    // ── Handler-side fallback ────────────────────────────────────────────

    /**
     * #1103 round 3: a JSON string item is NOT decoded any more. The auth
     * gate reads only array items, so decoding here was a second parser the
     * gate never saw (a string item created a product / edited a post past
     * the per-post check). The gate refuses the whole request first; this is
     * the handler-side mirror: the item is reported, never used.
     */
    public function test_json_string_items_are_refused_not_decoded(): void
    {
        $result = (new BulkItemNormalizerHarness())->normalize(
            array('{"id":5652,"status":"draft"}', '{"id":5653,"status":"draft"}'),
            'page'
        );

        $this->assertSame(array(), $result['items']);
        $this->assertCount(2, $result['errors']);
        $this->assertStringContainsString('must be an object', $result['errors'][0]['message']);
        $this->assertStringContainsString('string', $result['errors'][0]['message']);
    }

    public function test_object_typed_items_are_refused_too(): void
    {
        $result = (new BulkItemNormalizerHarness())->normalize(array((object) array('id' => 5)), 'page');

        $this->assertSame(array(), $result['items']);
        $this->assertStringContainsString('object', $result['errors'][0]['message']);
    }

    public function test_object_items_pass_through_untouched(): void
    {
        $items  = array(array('id' => 12, 'title' => 'Kept'));
        $result = (new BulkItemNormalizerHarness())->normalize($items, 'page');

        $this->assertSame($items, $result['items']);
        $this->assertSame(array(), $result['errors']);
    }

    public function test_undecodable_item_reports_what_was_received(): void
    {
        $result = (new BulkItemNormalizerHarness())->normalize(array('not json at all', 42), 'page');

        $this->assertSame(array(), $result['items']);
        $this->assertCount(2, $result['errors']);
        // The old failure blamed a missing id; the message must name the real problem.
        $this->assertStringContainsString('must be an object', $result['errors'][0]['message']);
        $this->assertStringContainsString('string', $result['errors'][0]['message']);
        $this->assertStringContainsString('integer', $result['errors'][1]['message']);
    }

    public function test_normalization_preserves_item_indexes_for_error_reporting(): void
    {
        $result = (new BulkItemNormalizerHarness())->normalize(
            array('bad', array('id' => 7)),
            'page'
        );

        $this->assertSame(array(1 => array('id' => 7)), $result['items']);
        $this->assertSame(0, $result['errors'][0]['index']);
    }
}
