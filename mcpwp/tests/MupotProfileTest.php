<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-mupot-connector.php';
require_once dirname(__DIR__) . '/includes/admin/class-mcpwp-mupot-profile.php';

final class MupotProfileTest extends TestCase
{
    private const USER_ID = 7;

    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']       = array( 'mcpwp_settings' => array( 'mupot_enabled' => true ) );
        $GLOBALS['mcpwp_test_user_meta']     = array();
        $GLOBALS['mcpwp_test_current_user']  = self::USER_ID;
        $GLOBALS['mcpwp_test_user_caps']     = array( self::USER_ID => array( 'activate_plugins' => true ) );

        $ref      = new ReflectionClass(Mcpwp_Encryption::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    public function test_does_not_render_when_mupot_disabled(): void
    {
        $GLOBALS['mcpwp_test_options']['mcpwp_settings']['mupot_enabled'] = false;

        $profile = new Mcpwp_Mupot_Profile();
        ob_start();
        $profile->render_fields((object) array( 'ID' => self::USER_ID ));
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_does_not_render_for_user_lacking_capability(): void
    {
        $GLOBALS['mcpwp_test_user_caps'][ self::USER_ID ]['activate_plugins'] = false;

        $profile = new Mcpwp_Mupot_Profile();
        ob_start();
        $profile->render_fields((object) array( 'ID' => self::USER_ID ));
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_renders_empty_token_field_and_squad_field_when_unconnected(): void
    {
        $profile = new Mcpwp_Mupot_Profile();
        ob_start();
        $profile->render_fields((object) array( 'ID' => self::USER_ID ));
        $output = ob_get_clean();

        $this->assertStringContainsString('mcpwp_mupot_member_token', $output);
        $this->assertStringContainsString('mcpwp_mupot_squad_id', $output);
        $this->assertStringNotContainsString('value="my-secret"', $output);
    }

    public function test_renders_masked_placeholder_when_token_already_stored(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'my-secret');

        $profile = new Mcpwp_Mupot_Profile();
        ob_start();
        $profile->render_fields((object) array( 'ID' => self::USER_ID ));
        $output = ob_get_clean();

        $this->assertStringContainsString('••••••••', $output);
        $this->assertStringNotContainsString('my-secret', $output);
    }

    public function test_save_fields_stores_token_and_squad(): void
    {
        $_POST['mcpwp_mupot_member_token'] = 'new-token-value';
        $_POST['mcpwp_mupot_squad_id']     = 'mumcp-core';
        $_POST['mcpwp_mupot_profile_nonce'] = 'test-nonce';

        $profile = new Mcpwp_Mupot_Profile();
        $profile->save_fields(self::USER_ID);

        $connector = new Mcpwp_Mupot_Connector();
        $this->assertTrue($connector->has_token(self::USER_ID));
        $this->assertSame('mumcp-core', $connector->get_squad(self::USER_ID));

        unset($_POST['mcpwp_mupot_member_token'], $_POST['mcpwp_mupot_squad_id'], $_POST['mcpwp_mupot_profile_nonce']);
    }

    public function test_save_fields_does_not_overwrite_token_when_field_left_blank(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'original-token');

        $_POST['mcpwp_mupot_member_token'] = '';
        $_POST['mcpwp_mupot_squad_id']     = 'mumcp-core';
        $_POST['mcpwp_mupot_profile_nonce'] = 'test-nonce';

        $profile = new Mcpwp_Mupot_Profile();
        $profile->save_fields(self::USER_ID);

        $this->assertTrue($connector->has_token(self::USER_ID));

        unset($_POST['mcpwp_mupot_member_token'], $_POST['mcpwp_mupot_squad_id'], $_POST['mcpwp_mupot_profile_nonce']);
    }
}
