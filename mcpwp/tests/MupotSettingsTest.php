<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/admin/class-mcpwp-settings.php';

final class MupotSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options'] = array();
    }

    public function test_defaults_include_mupot_disabled_by_default(): void
    {
        $settings = new Mcpwp_Settings();
        $defaults = $settings->get_defaults();

        $this->assertFalse($defaults['mupot_enabled']);
        $this->assertSame('https://mupot.mumega.com', $defaults['mupot_instance_url']);
    }

    public function test_sanitize_settings_casts_mupot_enabled_to_bool(): void
    {
        $settings = new Mcpwp_Settings();

        $sanitized = $settings->sanitize_settings(array( 'mupot_enabled' => '1' ));
        $this->assertTrue($sanitized['mupot_enabled']);

        $sanitized = $settings->sanitize_settings(array());
        $this->assertFalse($sanitized['mupot_enabled']);
    }

    public function test_sanitize_settings_keeps_valid_instance_url(): void
    {
        $settings  = new Mcpwp_Settings();
        $sanitized = $settings->sanitize_settings(array( 'mupot_instance_url' => 'https://my-fork.example.com' ));

        $this->assertSame('https://my-fork.example.com', $sanitized['mupot_instance_url']);
    }

    public function test_sanitize_settings_falls_back_to_default_on_empty_url(): void
    {
        $settings  = new Mcpwp_Settings();
        $sanitized = $settings->sanitize_settings(array( 'mupot_instance_url' => '' ));

        $this->assertSame('https://mupot.mumega.com', $sanitized['mupot_instance_url']);
    }
}
