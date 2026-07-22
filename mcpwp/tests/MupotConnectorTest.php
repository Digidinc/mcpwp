<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-mupot-connector.php';

final class MupotConnectorTest extends TestCase
{
    private const USER_ID = 42;

    protected function setUp(): void
    {
        $GLOBALS['mcpwp_test_options']    = array();
        $GLOBALS['mcpwp_test_user_meta']  = array();
        $GLOBALS['mcpwp_test_filters']    = array();
        $GLOBALS['mcpwp_test_actions']    = array();
        $GLOBALS['mcpwp_test_transients'] = array();

        $ref      = new ReflectionClass(Mcpwp_Encryption::class);
        $instance = $ref->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }

    private function mockHttp(int $code, array $body): void
    {
        add_filter('pre_http_request', function () use ($code, $body) {
            return array(
                'response' => array( 'code' => $code ),
                'body'     => wp_json_encode($body),
            );
        }, 10, 3);
    }

    public function test_get_status_returns_error_when_no_token_stored(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $result    = $connector->get_status(self::USER_ID);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('mupot_not_connected', $result->get_error_code());
    }

    public function test_get_task_board_returns_error_when_no_squad_stored(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'test-member-token');

        $result = $connector->get_task_board(self::USER_ID);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('mupot_squad_not_configured', $result->get_error_code());
    }

    public function test_get_status_calls_mcp_with_bearer_token_and_returns_result(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'test-member-token');

        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured) {
            $captured[] = array( 'url' => $url, 'headers' => $args['headers'], 'body' => $args['body'] );
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode(array( 'result' => array( 'member_id' => 'm-1', 'capabilities' => array() ) )),
            );
        }, 10, 3);

        $result = $connector->get_status(self::USER_ID);

        $this->assertIsArray($result);
        $this->assertSame('m-1', $result['member_id']);
        $this->assertSame('https://mupot.mumega.com/mcp', $captured[0]['url']);
        $this->assertSame('Bearer test-member-token', $captured[0]['headers']['Authorization']);

        $sent_body = json_decode($captured[0]['body'], true);
        $this->assertSame('status', $sent_body['tool']);
    }

    public function test_get_task_board_sends_stored_squad_id(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'test-member-token');
        $connector->save_squad(self::USER_ID, 'mumcp-core');

        $captured = new ArrayObject();
        add_filter('pre_http_request', function ($preempt, $args, $url) use ($captured) {
            $captured[] = array( 'body' => $args['body'] );
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode(array( 'result' => array( 'columns' => array() ) )),
            );
        }, 10, 3);

        $result = $connector->get_task_board(self::USER_ID);

        $this->assertIsArray($result);
        $sent_body = json_decode($captured[0]['body'], true);
        $this->assertSame('task_board', $sent_body['tool']);
        $this->assertSame('mumcp-core', $sent_body['args']['squad_id']);
    }

    public function test_get_status_returns_error_on_401(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'bad-token');
        $this->mockHttp(401, array( 'error' => 'unauthorized' ));

        $result = $connector->get_status(self::USER_ID);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('mupot_unauthorized', $result->get_error_code());
    }

    public function test_get_task_board_returns_distinct_error_on_403(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'test-member-token');
        $connector->save_squad(self::USER_ID, 'not-my-squad');
        $this->mockHttp(403, array( 'error' => 'forbidden' ));

        $result = $connector->get_task_board(self::USER_ID);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('mupot_squad_forbidden', $result->get_error_code());
    }

    public function test_get_status_returns_unavailable_error_on_network_failure(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'test-member-token');
        add_filter('pre_http_request', function () {
            return new WP_Error('http_request_failed', 'Connection timed out');
        }, 10, 3);

        $result = $connector->get_status(self::USER_ID);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('mupot_unavailable', $result->get_error_code());
    }

    public function test_get_status_result_is_cached_in_transient(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'test-member-token');

        $calls = 0;
        add_filter('pre_http_request', function () use (&$calls) {
            $calls++;
            return array(
                'response' => array( 'code' => 200 ),
                'body'     => wp_json_encode(array( 'result' => array( 'member_id' => 'm-1' ) )),
            );
        }, 10, 3);

        $connector->get_status(self::USER_ID);
        $connector->get_status(self::USER_ID);

        $this->assertSame(1, $calls);
    }

    public function test_save_token_round_trips_through_encryption(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $connector->save_token(self::USER_ID, 'my-secret-token');

        $stored = get_user_meta(self::USER_ID, Mcpwp_Mupot_Connector::TOKEN_META_KEY, true);
        $this->assertNotSame('my-secret-token', $stored);

        $this->assertTrue($connector->has_token(self::USER_ID));
    }

    public function test_has_squad_and_get_squad(): void
    {
        $connector = new Mcpwp_Mupot_Connector();
        $this->assertFalse($connector->has_squad(self::USER_ID));

        $connector->save_squad(self::USER_ID, 'mumcp-core');

        $this->assertTrue($connector->has_squad(self::USER_ID));
        $this->assertSame('mumcp-core', $connector->get_squad(self::USER_ID));
    }
}
