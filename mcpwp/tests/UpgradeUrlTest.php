<?php

/**
 * Pro upgrade URL contract (MUM-113 / GH #579).
 *
 * get_upgrade_url() must prefer install-linked Freemius checkout and must
 * never hand a buyer /contact/. REST, MCP, and Figma upsells share that
 * accessor so agents do not follow a contact form when checkout exists.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-figma.php';

final class UpgradeUrlTest extends TestCase
{
    private const FS_CHECKOUT = 'https://checkout.freemius.com/mode/dialog/plugin/23824/plan/0/?install_id=test';
    private const RECONNECT   = 'https://example.test/wp-admin/admin.php?page=mcpwp&fs_action=reset_anonymous_mode';

    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']    = array();
        $GLOBALS['mcpwp_test_transients'] = array();
        $GLOBALS['mcpwp_test_fs']         = null;
        $this->reset_license_singleton();
    }

    protected function tearDown(): void
    {
        $GLOBALS['mcpwp_test_fs'] = null;
        $this->reset_license_singleton();
    }

    private function reset_license_singleton(): void
    {
        $ref      = new ReflectionClass('Mcpwp_License');
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    private function stub_unlicensed(): Mcpwp_Test_Fs_Stub
    {
        $fs = new Mcpwp_Test_Fs_Stub(false, false, false, '');
        $GLOBALS['mcpwp_test_fs'] = $fs;
        return $fs;
    }

    public function test_fallback_is_marketing_pricing_not_contact(): void
    {
        $url = Mcpwp_License::get_instance()->get_upgrade_url();
        $this->assertSame(Mcpwp_License::MARKETING_UPGRADE_URL, $url);
        $this->assertStringContainsString('mcpwp.net/pricing', $url);
        $this->assertStringNotContainsString('/contact', $url);
    }

    public function test_prefers_freemius_checkout_when_sdk_offers_one(): void
    {
        $this->stub_unlicensed()->set_upgrade_url(self::FS_CHECKOUT);

        $this->assertSame(
            self::FS_CHECKOUT,
            Mcpwp_License::get_instance()->get_upgrade_url()
        );
    }

    public function test_rejects_contact_path_from_freemius_and_falls_back(): void
    {
        $this->stub_unlicensed()->set_upgrade_url('https://mcpwp.net/contact/');

        $this->assertSame(
            Mcpwp_License::MARKETING_UPGRADE_URL,
            Mcpwp_License::get_instance()->get_upgrade_url()
        );
    }

    public function test_rejects_relative_contact_path(): void
    {
        $this->stub_unlicensed()->set_upgrade_url('/contact-us/');

        $this->assertSame(
            Mcpwp_License::MARKETING_UPGRADE_URL,
            Mcpwp_License::get_instance()->get_upgrade_url()
        );
    }

    public function test_empty_freemius_url_falls_back_to_pricing(): void
    {
        $this->stub_unlicensed();

        $this->assertSame(
            Mcpwp_License::MARKETING_UPGRADE_URL,
            Mcpwp_License::get_instance()->get_upgrade_url()
        );
    }

    public function test_anonymous_reconnect_is_preferred_over_checkout(): void
    {
        $fs = $this->stub_unlicensed();
        $fs->set_anonymous(true);
        $fs->set_reconnect_url(self::RECONNECT);
        $fs->set_upgrade_url(self::FS_CHECKOUT);

        $this->assertSame(
            self::RECONNECT,
            Mcpwp_License::get_instance()->get_upgrade_url()
        );
    }

    public function test_pending_activation_uses_reconnect(): void
    {
        $fs = $this->stub_unlicensed();
        $fs->set_pending_activation(true);
        $fs->set_reconnect_url(self::RECONNECT);
        $fs->set_upgrade_url(self::FS_CHECKOUT);

        $this->assertSame(
            self::RECONNECT,
            Mcpwp_License::get_instance()->get_upgrade_url()
        );
    }

    public function test_mcp_pro_required_uses_freemius_checkout_not_contact(): void
    {
        $this->stub_unlicensed()->set_upgrade_url(self::FS_CHECKOUT);

        $controller = new Mcpwp_REST_MCP();
        $request    = new WP_REST_Request(
            'POST',
            '/mcpwp/v1/mcp',
            array(),
            array(),
            array(
                'jsonrpc' => '2.0',
                'id'      => 7,
                'method'  => 'tools/call',
                'params'  => array(
                    'name'      => 'wp_build_page',
                    'arguments' => array(),
                ),
            )
        );

        $data = $controller->handle_mcp($request)->get_data();
        $this->assertSame(self::FS_CHECKOUT, $data['error']['data']['upgrade_url']);
        $this->assertStringNotContainsString('/contact', $data['error']['data']['upgrade_url']);
    }

    public function test_rest_require_pro_uses_freemius_checkout_not_contact(): void
    {
        $this->stub_unlicensed()->set_upgrade_url(self::FS_CHECKOUT);

        $controller = new Mcpwp_REST_MCP();
        $method     = new ReflectionMethod(Mcpwp_REST_API::class, 'require_pro');
        $method->setAccessible(true);
        $result = $method->invoke($controller);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('pro_required', $result->get_error_code());
        $this->assertSame(self::FS_CHECKOUT, $result->get_error_data()['upgrade_url']);
    }

    public function test_figma_pro_required_uses_freemius_checkout_not_contact(): void
    {
        $this->stub_unlicensed()->set_upgrade_url(self::FS_CHECKOUT);

        $method = new ReflectionMethod(Mcpwp_Figma::class, 'pro_required_error');
        $method->setAccessible(true);
        $result = $method->invoke(null);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame(self::FS_CHECKOUT, $result->get_error_data()['upgrade_url']);
    }
}
