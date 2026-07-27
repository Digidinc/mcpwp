<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-mupot-connector.php';
require_once dirname(__DIR__) . '/includes/admin/traits/trait-mcpwp-admin-mupot.php';

/**
 * Exercises Mcpwp_Admin::get_control_room_mupot_data() in isolation by
 * defining a minimal host class with just the trait under test — avoids
 * pulling in the full Mcpwp_Admin dependency graph for a single method.
 */
final class MupotControlRoomTest extends TestCase
{
    private const USER_ID = 9;

    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']      = array( 'mcpwp_settings' => array( 'mupot_enabled' => true ) );
        $GLOBALS['mcpwp_test_user_meta']    = array();
        $GLOBALS['mcpwp_test_transients']   = array();
        $GLOBALS['mcpwp_test_filters']      = array();
        $GLOBALS['mcpwp_test_current_user'] = self::USER_ID;

        $ref      = new ReflectionClass(Mcpwp_Encryption::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    private function host(): object
    {
        return new class {
            use Mcpwp_Admin_Mupot_Trait;
        };
    }

    public function test_disabled_state_when_mupot_not_enabled(): void
    {
        $GLOBALS['mcpwp_test_options']['mcpwp_settings']['mupot_enabled'] = false;

        $data = $this->host()->get_control_room_mupot_data();

        $this->assertFalse($data['enabled']);
    }

    public function test_unconnected_state_when_no_token(): void
    {
        $data = $this->host()->get_control_room_mupot_data();

        $this->assertTrue($data['enabled']);
        $this->assertFalse($data['connected']);
        $this->assertNull($data['status']);
    }

    public function test_connected_state_with_status_and_board(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'test-token');
        $connector->save_squad(self::USER_ID, 'mumcp-core');

        add_filter('pre_http_request', function () {
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode(array( 'result' => array( 'member_id' => 'm-1' ) )),
            );
        }, 10, 3);

        $data = $this->host()->get_control_room_mupot_data();

        $this->assertTrue($data['connected']);
        $this->assertTrue($data['has_squad']);
        $this->assertIsArray($data['status']);
        $this->assertIsArray($data['task_board']);
        $this->assertNull($data['error']);
    }

    public function test_error_state_surfaces_message_without_fatal(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'bad-token');
        $connector->save_squad(self::USER_ID, 'mumcp-core');

        add_filter('pre_http_request', function () {
            return array( 'response' => array( 'code' => 401 ), 'body' => '{}' );
        }, 10, 3);

        $data = $this->host()->get_control_room_mupot_data();

        $this->assertTrue($data['connected']);
        $this->assertNull($data['status']);
        $this->assertNotEmpty($data['error']);
    }

    public function test_board_error_does_not_hide_already_successful_status(): void
    {
        // Regression for the finding: a task_board-only failure (e.g. wrong
        // squad id -> 403) must not wipe out a status call that already
        // succeeded. status and board_error are distinct fields; task_board
        // must stay null; the top-level error must stay null too (status
        // itself did not fail).
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'test-token');
        $connector->save_squad(self::USER_ID, 'not-my-squad');

        $call = 0;
        add_filter('pre_http_request', function () use (&$call) {
            $call++;
            if (1 === $call) {
                // First call is `status` — succeeds.
                return array(
                    'response' => array( 'code' => 200 ),
                    'body'     => wp_json_encode(array( 'result' => array( 'member_id' => 'm-1' ) )),
                );
            }
            // Second call is `task_board` — forbidden for this squad.
            return array( 'response' => array( 'code' => 403 ), 'body' => '{}' );
        }, 10, 3);

        $data = $this->host()->get_control_room_mupot_data();

        $this->assertTrue($data['connected']);
        $this->assertTrue($data['has_squad']);
        $this->assertIsArray($data['status']);
        $this->assertSame('m-1', $data['status']['member_id']);
        $this->assertNull($data['error']);
        $this->assertNotEmpty($data['board_error']);
        $this->assertNull($data['task_board']);
    }
}
