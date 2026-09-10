<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-figma.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-figma.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-seo-market.php';
require_once dirname(__DIR__) . '/includes/api/class-mcpwp-rest-integrations.php';
require_once dirname(__DIR__) . '/includes/mcp/class-mcpwp-mcp-figma-integration.php';

/**
 * Authentication is stubbed so the test isolates what sits after it: the tier.
 */
class Mcpwp_Test_Gated_Figma_Controller extends Mcpwp_REST_Figma
{
    /** @var true|WP_Error */
    public $auth_result = true;

    public function check_permission($request)
    {
        return $this->auth_result;
    }
}

class Mcpwp_Test_Gated_Seo_Market_Controller extends Mcpwp_REST_SEO_Market
{
    /** @var true|WP_Error */
    public $auth_result = true;

    public function check_permission($request)
    {
        return $this->auth_result;
    }
}

/**
 * #564 residuals on the Pro gate.
 *
 *   P2-A  Figma is Pro, but its REST routes and MCP tools answered on Free.
 *   P2-B  The DataForSEO market-data routes are Pro in the MCP map but were
 *         ungated on REST (and so through POST /batch, which dispatches with
 *         rest_do_request() and therefore runs the permission callback).
 *   P3-4  The integrations controller's gate allowed when Mcpwp_License was
 *         missing.
 */
final class ProGateResidualTest extends TestCase
{
    private const FIGMA_TOOLS = array(
        'wp_figma_status',
        'wp_get_figma_file',
        'wp_get_figma_node',
        'wp_get_figma_design_tokens',
        'wp_sync_figma_design_tokens',
    );

    protected function setUp(): void
    {
        if (defined('MCPWP_PRO') || defined('MCPWP_WPORG_BUILD')) {
            $this->markTestSkipped('A build-level tier constant is defined in this process.');
        }

        $GLOBALS['mcpwp_test_options']             = array();
        $GLOBALS['mcpwp_test_transients']          = array();
        $GLOBALS['mcpwp_test_filters']             = array();
        $GLOBALS['_mcpwp_test_registered_routes']  = array();
        Mcpwp_Integration::clear_cache();
        $this->setTier(false);
    }

    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_fs']      = null;
        $GLOBALS['mcpwp_test_filters'] = array();
        $this->resetLicense();
        Mcpwp_Integration::clear_cache();
    }

    private function resetLicense(): void
    {
        $ref = new ReflectionProperty(Mcpwp_License::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);
    }

    private function setTier(bool $pro): void
    {
        $GLOBALS['mcpwp_test_fs'] = $pro ? new Mcpwp_Test_Fs_Stub(true, true, false, 'pro') : null;
        $this->resetLicense();
        $this->assertSame($pro, Mcpwp_License::get_instance()->is_pro(), 'tier precondition');
    }

    /**
     * @return array<string, callable>
     */
    private function permissionCallbacks($controller): array
    {
        $GLOBALS['_mcpwp_test_registered_routes'] = array();
        $controller->register_routes();

        $callbacks = array();
        foreach ($GLOBALS['_mcpwp_test_registered_routes'] as $route) {
            $handlers = isset($route['args']['permission_callback']) ? array($route['args']) : $route['args'];
            foreach ($handlers as $handler) {
                if (is_array($handler) && isset($handler['permission_callback'])) {
                    $callbacks[ $handler['methods'] . ' ' . $route['route'] ] = $handler['permission_callback'];
                }
            }
        }

        return $callbacks;
    }

    private function assertProRequired($result, string $route): void
    {
        $this->assertInstanceOf(WP_Error::class, $result, $route . ' must refuse a Free caller');
        $this->assertSame('pro_required', $result->get_error_code(), $route);
        $data = $result->get_error_data();
        $this->assertSame(403, is_array($data) && isset($data['status']) ? (int) $data['status'] : null, $route);
    }

    public function test_figma_routes_answer_pro_required_on_free(): void
    {
        $controller = new Mcpwp_Test_Gated_Figma_Controller();
        $callbacks  = $this->permissionCallbacks($controller);

        $this->assertSame(
            array('GET /figma/status', 'GET /figma/file', 'GET /figma/node', 'GET /figma/design-tokens', 'POST /figma/design-tokens/sync'),
            array_keys($callbacks)
        );

        foreach ($callbacks as $route => $callback) {
            $this->assertProRequired(call_user_func($callback, new WP_REST_Request('GET', '/mcpwp/v1/figma/status')), $route);
        }

        // Authentication is still decided first.
        $controller->auth_result = new WP_Error('missing_api_key', 'API key required.', array('status' => 401));
        foreach ($callbacks as $route => $callback) {
            $this->assertSame('missing_api_key', call_user_func($callback, new WP_REST_Request('GET', '/x'))->get_error_code(), $route);
        }
    }

    public function test_seo_market_routes_answer_pro_required_on_free(): void
    {
        $callbacks = $this->permissionCallbacks(new Mcpwp_Test_Gated_Seo_Market_Controller());

        $this->assertSame(
            array('GET /seo/visibility', 'POST /seo/visibility/refresh', 'GET /seo/opportunities'),
            array_keys($callbacks)
        );

        foreach ($callbacks as $route => $callback) {
            $this->assertProRequired(call_user_func($callback, new WP_REST_Request('GET', '/mcpwp/v1/seo/visibility')), $route);
        }
    }

    public function test_figma_and_seo_market_routes_pass_on_pro(): void
    {
        $this->setTier(true);

        foreach (array(new Mcpwp_Test_Gated_Figma_Controller(), new Mcpwp_Test_Gated_Seo_Market_Controller()) as $controller) {
            $callbacks = $this->permissionCallbacks($controller);
            $this->assertNotEmpty($callbacks);
            foreach ($callbacks as $route => $callback) {
                $this->assertTrue(call_user_func($callback, new WP_REST_Request('GET', '/x')), $route . ' must allow a Pro caller');
            }
        }
    }

    public function test_figma_mcp_tools_are_listed_only_with_pro(): void
    {
        add_filter('mcpwp_integrations', static function (array $integrations): array {
            $integrations[] = new Mcpwp_MCP_Figma_Integration();
            return $integrations;
        });

        $integration = new Mcpwp_MCP_Figma_Integration();

        $this->assertSame(array(), $integration->get_tools());
        $this->assertSame(array(), $integration->get_tool_map(), 'no route mapping on Free, so tools/call cannot reach /figma/*');
        $this->assertSame(self::FIGMA_TOOLS, array_keys($integration->get_tool_categories()), 'the names stay reserved on Free');
        $this->assertSame(array(), array_values(array_intersect(self::FIGMA_TOOLS, $this->listedToolNames())));

        $this->setTier(true);
        Mcpwp_Integration::clear_cache();

        $this->assertSame(self::FIGMA_TOOLS, array_keys($integration->get_tool_map()));
        $this->assertSame(self::FIGMA_TOOLS, array_values(array_intersect(self::FIGMA_TOOLS, $this->listedToolNames())));
    }

    /**
     * @return string[]
     */
    private function listedToolNames(): array
    {
        $controller = new Mcpwp_REST_MCP();
        $request    = new WP_REST_Request(
            'POST',
            '/mcpwp/v1/mcp',
            array(),
            array(),
            array('jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => array())
        );

        return array_column($controller->handle_mcp($request)->get_data()['result']['tools'], 'name');
    }

    public function test_pro_gate_fails_closed_without_the_license_class(): void
    {
        $this->setTier(true);

        $method = new ReflectionMethod(Mcpwp_REST_API::class, 'entitlement_from_license_class');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke(null, 'Mcpwp_License_Class_That_Was_Never_Loaded'), 'an unreadable entitlement is not an entitlement');
        $this->assertTrue($method->invoke(null, 'Mcpwp_License'));
        $this->assertTrue(Mcpwp_REST_API::is_pro_entitled());

        $this->setTier(false);
        $this->assertFalse(Mcpwp_REST_API::is_pro_entitled());
    }

    public function test_integrations_controller_uses_the_shared_fail_closed_gate(): void
    {
        $method = new ReflectionMethod(Mcpwp_REST_Integrations::class, 'require_pro');

        $this->assertSame(
            Mcpwp_REST_API::class,
            $method->getDeclaringClass()->getName(),
            'the controller-local gate that allowed when Mcpwp_License was missing must be gone'
        );

        $controller = new Mcpwp_REST_Integrations();
        $this->assertProRequired($controller->generate_image(new WP_REST_Request('POST', '/mcpwp/v1/integrations/generate-image')), 'POST /integrations/generate-image');
    }
}
