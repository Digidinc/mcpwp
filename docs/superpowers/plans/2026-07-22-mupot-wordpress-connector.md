# mupot WordPress Connector (Read-First Slice) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A logged-in WP staff member connects their own personal mupot member token + squad id once, and sees their live mupot `status` + `task_board` inside wp-admin's existing Control Room page — read-only, capability-gated, zero mupot-side changes.

**Architecture:** A new `Mcpwp_Mupot_Connector` class is a pure HTTP client of mupot's existing `runtime-adapter/v1` seam (`POST {instance_url}/mcp {tool, args}`, Bearer member token). Settings gets an enable toggle + instance URL. Each WP user's own Profile screen gets two new fields (token, encrypted; squad id, plain). Control Room gets a new "My mupot" section following its existing anchor-nav-plus-card pattern.

**Tech Stack:** PHP 7.4+ (matches plugin floor), WordPress hooks/transients/user-meta, PHPUnit 9 (existing `tests/bootstrap.php` WP test-double shim), `pre_http_request` filter for HTTP mocking (existing convention, see `tests/FigmaDesignTokensTest.php`).

## Global Constraints

- No changes to the mupot repository — this is a client integration only, entirely within `Mumega-com/mcpwp`.
- Token stored encrypted via the existing `Mcpwp_Encryption::get_instance()->encrypt()/decrypt()` singleton (returns `false` on failure) — never in plaintext, never logged, never returned by any REST/MCP tool.
- Squad id is not secret — stored and edited as plain text.
- Everything (Profile fields, Control Room section) is inert unless `mcpwp_settings['mupot_enabled']` is `true` (default `false`).
- Profile fields and Control Room section both gated on `current_user_can( 'activate_plugins' )` — the same capability every other mcpwp admin page already requires.
- `status` is self-scoped and never needs `squad_id`. `task_board` requires `squad_id` explicitly for ordinary (non-agent-bound) member tokens — mupot's own source (`resolveScopedSquad` in `mupot/src/mcp/index.ts`) returns `400 invalid_args` ("squad_id required unless the token is agent-bound") without it.
- HTTPS-only calls to the configured instance URL. Bounded timeout: 5 seconds. Transient cache TTL: 60 seconds.
- Full existing PHPUnit suite (604 tests as of this plan) must stay green throughout.

---

### Task 1: `Mcpwp_Mupot_Connector` core class

**Files:**
- Create: `mcpwp/includes/core/class-mcpwp-mupot-connector.php`
- Modify: `mcpwp/tests/bootstrap.php` (add `get_user_meta`/`update_user_meta`/`delete_user_meta` stubs — not yet present)
- Modify: `mcpwp/mcpwp.php` (add `require_once` for the new class)
- Test: `mcpwp/tests/MupotConnectorTest.php`

**Interfaces:**
- Produces: `Mcpwp_Mupot_Connector::get_status( int $wp_user_id ): array|WP_Error`, `Mcpwp_Mupot_Connector::get_task_board( int $wp_user_id ): array|WP_Error`, `Mcpwp_Mupot_Connector::save_token( int $wp_user_id, string $raw_token ): bool`, `Mcpwp_Mupot_Connector::save_squad( int $wp_user_id, string $squad_id ): bool`, `Mcpwp_Mupot_Connector::has_token( int $wp_user_id ): bool`, `Mcpwp_Mupot_Connector::get_squad( int $wp_user_id ): string`, `Mcpwp_Mupot_Connector::has_squad( int $wp_user_id ): bool`. Constants: `Mcpwp_Mupot_Connector::TOKEN_META_KEY = 'mcpwp_mupot_member_token'`, `Mcpwp_Mupot_Connector::SQUAD_META_KEY = 'mcpwp_mupot_squad_id'`.

- [ ] **Step 1: Add user-meta test stubs to bootstrap.php**

`tests/bootstrap.php` has `get_option`/`set_transient`/`get_transient` stubs but no `get_user_meta`/`update_user_meta`/`delete_user_meta`. Add them immediately after the `delete_transient` function (around line 373), following the exact same `$GLOBALS`-backed style as the transient stubs above them:

```php
function get_user_meta($user_id, $key = '', $single = false)
{
    $user_id = (int) $user_id;
    if ('' === $key) {
        return isset($GLOBALS['mcpwp_test_user_meta'][ $user_id ]) ? $GLOBALS['mcpwp_test_user_meta'][ $user_id ] : array();
    }
    $value = isset($GLOBALS['mcpwp_test_user_meta'][ $user_id ][ $key ]) ? $GLOBALS['mcpwp_test_user_meta'][ $user_id ][ $key ] : '';
    return $single ? $value : array( $value );
}

function update_user_meta($user_id, $key, $value)
{
    $GLOBALS['mcpwp_test_user_meta'][ (int) $user_id ][ $key ] = $value;
    return true;
}

function delete_user_meta($user_id, $key)
{
    unset($GLOBALS['mcpwp_test_user_meta'][ (int) $user_id ][ $key ]);
    return true;
}
```

- [ ] **Step 2: Write the failing test file**

Create `tests/MupotConnectorTest.php`:

```php
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
```

- [ ] **Step 3: Run test to verify it fails**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml tests/MupotConnectorTest.php`
Expected: FAIL — `Class "Mcpwp_Mupot_Connector" not found`.

- [ ] **Step 4: Write the connector class**

Create `includes/core/class-mcpwp-mupot-connector.php`:

```php
<?php
/**
 * mupot WordPress Connector
 *
 * Pure HTTP client of mupot's existing runtime-adapter/v1 contract. Each WP
 * user carries their own personal mupot member token and squad id; identity
 * and capability are resolved entirely server-side by mupot from the bearer
 * token. This class never asserts who a user is or what they may do — it
 * only supplies the squad scope task_board requires for non-agent-bound
 * (ordinary human) member tokens.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Client for the per-user mupot connector.
 */
class Mcpwp_Mupot_Connector {

	/**
	 * User meta key storing the encrypted personal mupot member token.
	 *
	 * @var string
	 */
	const TOKEN_META_KEY = 'mcpwp_mupot_member_token';

	/**
	 * User meta key storing the plain mupot squad id/slug.
	 *
	 * @var string
	 */
	const SQUAD_META_KEY = 'mcpwp_mupot_squad_id';

	/**
	 * Default mupot instance URL when mupot_instance_url is unset.
	 *
	 * @var string
	 */
	const DEFAULT_INSTANCE_URL = 'https://mupot.mumega.com';

	/**
	 * Transient cache TTL in seconds.
	 *
	 * @var int
	 */
	const CACHE_TTL = 60;

	/**
	 * HTTP request timeout in seconds.
	 *
	 * @var int
	 */
	const TIMEOUT = 5;

	/**
	 * Get the current user's mupot status (self-scoped, no squad required).
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array|WP_Error Parsed mupot result, or error.
	 */
	public function get_status( $wp_user_id ) {
		return $this->call_cached( $wp_user_id, 'status', array() );
	}

	/**
	 * Get the current user's mupot task board.
	 *
	 * Requires a stored squad id — task_board rejects non-agent-bound
	 * (ordinary human) tokens with 400 invalid_args when squad_id is absent.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return array|WP_Error Parsed mupot result, or error.
	 */
	public function get_task_board( $wp_user_id ) {
		if ( ! $this->has_squad( $wp_user_id ) ) {
			return new WP_Error(
				'mupot_squad_not_configured',
				__( 'Add your mupot squad id in your profile to see your task board.', 'mcpwp' )
			);
		}

		return $this->call_cached( $wp_user_id, 'task_board', array( 'squad_id' => $this->get_squad( $wp_user_id ) ) );
	}

	/**
	 * Encrypt and store a user's personal mupot member token.
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $raw_token  Plaintext member token.
	 * @return bool True on success.
	 */
	public function save_token( $wp_user_id, $raw_token ) {
		$encrypted = Mcpwp_Encryption::get_instance()->encrypt( (string) $raw_token );
		if ( false === $encrypted ) {
			return false;
		}
		update_user_meta( $wp_user_id, self::TOKEN_META_KEY, $encrypted );
		return true;
	}

	/**
	 * Store a user's mupot squad id (plain — not secret).
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $squad_id   Squad id or slug.
	 * @return bool True on success.
	 */
	public function save_squad( $wp_user_id, $squad_id ) {
		update_user_meta( $wp_user_id, self::SQUAD_META_KEY, sanitize_text_field( (string) $squad_id ) );
		return true;
	}

	/**
	 * Whether the given user has a stored token.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return bool
	 */
	public function has_token( $wp_user_id ) {
		return '' !== (string) get_user_meta( $wp_user_id, self::TOKEN_META_KEY, true );
	}

	/**
	 * Whether the given user has a stored squad id.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return bool
	 */
	public function has_squad( $wp_user_id ) {
		return '' !== $this->get_squad( $wp_user_id );
	}

	/**
	 * Get the given user's stored squad id (empty string if unset).
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return string
	 */
	public function get_squad( $wp_user_id ) {
		return (string) get_user_meta( $wp_user_id, self::SQUAD_META_KEY, true );
	}

	/**
	 * Decrypt the given user's stored token.
	 *
	 * @param int $wp_user_id WordPress user ID.
	 * @return string|false Decrypted token, or false if unset/undecryptable.
	 */
	private function get_decrypted_token( $wp_user_id ) {
		$encrypted = (string) get_user_meta( $wp_user_id, self::TOKEN_META_KEY, true );
		if ( '' === $encrypted ) {
			return false;
		}
		return Mcpwp_Encryption::get_instance()->decrypt( $encrypted );
	}

	/**
	 * Get the configured mupot instance URL.
	 *
	 * @return string
	 */
	private function get_instance_url() {
		$settings = get_option( 'mcpwp_settings', array() );
		$url      = isset( $settings['mupot_instance_url'] ) ? trim( (string) $settings['mupot_instance_url'] ) : '';
		return '' !== $url ? untrailingslashit( $url ) : self::DEFAULT_INSTANCE_URL;
	}

	/**
	 * Call a tool with the 60s transient cache in front of it.
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $tool       mupot tool name.
	 * @param array  $args       Tool arguments.
	 * @return array|WP_Error
	 */
	private function call_cached( $wp_user_id, $tool, $args ) {
		$cache_key = 'mcpwp_mupot_' . $tool . '_' . (int) $wp_user_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = $this->call_tool( $wp_user_id, $tool, $args );

		if ( ! is_wp_error( $result ) ) {
			set_transient( $cache_key, $result, self::CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Make the actual POST /mcp call.
	 *
	 * @param int    $wp_user_id WordPress user ID.
	 * @param string $tool       mupot tool name.
	 * @param array  $args       Tool arguments.
	 * @return array|WP_Error
	 */
	private function call_tool( $wp_user_id, $tool, $args ) {
		$token = $this->get_decrypted_token( $wp_user_id );
		if ( false === $token || '' === $token ) {
			return new WP_Error(
				'mupot_not_connected',
				__( 'Connect your mupot account in your profile to see this.', 'mcpwp' )
			);
		}

		$response = wp_remote_post(
			$this->get_instance_url() . '/mcp',
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'tool' => $tool,
						// Cast to object: an empty PHP array (the `status` call's
						// args) encodes as JSON `[]`, but mupot's tool schemas
						// declare args as type object — `{}` is required even
						// when empty. (object) array() gives an empty stdClass,
						// which json_encode renders as `{}`.
						'args' => (object) $args,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'mupot_unavailable',
				__( 'mupot is unavailable right now.', 'mcpwp' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code || 403 === $code ) {
			if ( 'task_board' === $tool ) {
				return new WP_Error(
					'mupot_squad_forbidden',
					__( "Your mupot token doesn't have access to that squad — check the squad id in your profile.", 'mcpwp' )
				);
			}
			return new WP_Error(
				'mupot_unauthorized',
				__( 'Your mupot token is invalid or has been revoked — reconnect from your profile.', 'mcpwp' )
			);
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'mupot_unavailable',
				__( 'mupot is unavailable right now.', 'mcpwp' )
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! isset( $body['result'] ) || ! is_array( $body['result'] ) ) {
			return new WP_Error(
				'mupot_unavailable',
				__( 'mupot is unavailable right now.', 'mcpwp' )
			);
		}

		return $body['result'];
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml tests/MupotConnectorTest.php`
Expected: `OK (10 tests, ...)`

- [ ] **Step 6: Wire the require into mcpwp.php**

In `mcpwp.php`, find the line `require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-figma.php';` (around line 248) and add immediately after it:

```php
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-mupot-connector.php';
```

- [ ] **Step 7: Run full suite to confirm no regressions**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml`
Expected: all tests pass (604 pre-existing + 10 new = 614), no new deprecations.

- [ ] **Step 8: Commit**

```bash
cd /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator
git add mcpwp/includes/core/class-mcpwp-mupot-connector.php mcpwp/tests/MupotConnectorTest.php mcpwp/tests/bootstrap.php mcpwp/mcpwp.php
git commit -m "feat(mupot): add Mcpwp_Mupot_Connector core client

Pure HTTP client of mupot's runtime-adapter/v1 seam. Per-user encrypted
token + plain squad id in user meta, 60s transient cache, distinct
error codes for unconnected/no-squad/unauthorized/squad-forbidden/
unavailable states.

Signed-off-by: mumega-mumcp <mupot:e6695da3-2e04-45b8-b4af-acddaa7c1438>"
```

---

### Task 2: Settings extension (`mupot_enabled` + `mupot_instance_url`)

**Files:**
- Modify: `mcpwp/includes/admin/class-mcpwp-settings.php`
- Test: `mcpwp/tests/MupotSettingsTest.php`

**Interfaces:**
- Consumes: none new (extends the existing `Mcpwp_Settings::OPTION_NAME = 'mcpwp_settings'`, `get_defaults()`, `sanitize_settings()`, `register_settings()`).
- Produces: `mcpwp_settings['mupot_enabled']` (bool, default `false`), `mcpwp_settings['mupot_instance_url']` (string, default `'https://mupot.mumega.com'`) — consumed by Task 1's connector (already reads `mupot_instance_url`) and Tasks 3/4 (gate on `mupot_enabled`).

- [ ] **Step 1: Write the failing test**

Create `tests/MupotSettingsTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml tests/MupotSettingsTest.php`
Expected: FAIL — `Undefined array key "mupot_enabled"`.

- [ ] **Step 3: Add defaults**

In `includes/admin/class-mcpwp-settings.php`, in `get_defaults()`, add after the `'analytics_enabled' => false,` line:

```php
			'mupot_enabled'           => false,
			'mupot_instance_url'      => 'https://mupot.mumega.com',
```

- [ ] **Step 4: Add sanitization**

In `sanitize_settings()`, add after the `$sanitized['analytics_enabled'] = ! empty( $input['analytics_enabled'] );` line:

```php
		$sanitized['mupot_enabled'] = ! empty( $input['mupot_enabled'] );
		$mupot_url = isset( $input['mupot_instance_url'] ) ? trim( (string) $input['mupot_instance_url'] ) : '';
		$sanitized['mupot_instance_url'] = '' !== $mupot_url ? esc_url_raw( $mupot_url ) : 'https://mupot.mumega.com';
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml tests/MupotSettingsTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 6: Register the settings fields**

In `register_settings()`, add after the `analytics_enabled` field block (around line 205):

```php
		add_settings_field(
			'mupot_enabled',
			__( 'mupot Connector', 'mcpwp' ),
			array( $this, 'render_checkbox_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'mupot_enabled',
				'description' => __( 'Let connected WP staff see their own mupot task board in Control Room.', 'mcpwp' ),
			)
		);

		add_settings_field(
			'mupot_instance_url',
			__( 'mupot Instance URL', 'mcpwp' ),
			array( $this, 'render_text_field' ),
			'mcpwp_settings',
			'mcpwp_general_section',
			array(
				'id'          => 'mupot_instance_url',
				'description' => __( 'Your mupot deployment. Leave default unless self-hosting a fork.', 'mcpwp' ),
				'placeholder' => 'https://mupot.mumega.com',
			)
		);
```

- [ ] **Step 7: Run full suite**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml`
Expected: all pass, no regressions.

- [ ] **Step 8: Commit**

```bash
cd /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator
git add mcpwp/includes/admin/class-mcpwp-settings.php mcpwp/tests/MupotSettingsTest.php
git commit -m "feat(mupot): add mupot_enabled + mupot_instance_url settings

Off by default. Gates the Task 3/4 profile field and Control Room
section — nothing mupot-related exists in wp-admin until enabled.

Signed-off-by: mumega-mumcp <mupot:e6695da3-2e04-45b8-b4af-acddaa7c1438>"
```

---

### Task 3: Profile screen fields (token + squad)

**Files:**
- Create: `mcpwp/includes/admin/class-mcpwp-mupot-profile.php`
- Modify: `mcpwp/includes/class-mcpwp-loader.php`
- Modify: `mcpwp/mcpwp.php`
- Test: `mcpwp/tests/MupotProfileTest.php`

**Interfaces:**
- Consumes: `Mcpwp_Mupot_Connector::save_token()`, `::save_squad()`, `::has_token()`, `::get_squad()` (Task 1). `mcpwp_settings['mupot_enabled']` (Task 2).
- Produces: `Mcpwp_Mupot_Profile::render_fields( WP_User $user )`, `Mcpwp_Mupot_Profile::save_fields( int $user_id )` — hooked to `show_user_profile`/`edit_user_profile` and `personal_options_update`/`edit_user_profile_update` in Task 4's Control Room work has no dependency on this, but shares the same "is mupot enabled + can this user manage it" gate pattern.

- [ ] **Step 1: Write the failing test**

Create `tests/MupotProfileTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml tests/MupotProfileTest.php`
Expected: FAIL — `Class "Mcpwp_Mupot_Profile" not found`.

- [ ] **Step 3: Add nonce test stubs to bootstrap.php**

Confirmed absent from `tests/bootstrap.php` (no `wp_verify_nonce`/`wp_create_nonce`/`wp_nonce_field` stub exists anywhere in the file). Add all three immediately after the `sanitize_textarea_field` stub (added earlier in this project's history, directly below `sanitize_text_field`):

```php
function wp_create_nonce($action = -1)
{
    return 'test-nonce';
}

function wp_verify_nonce($nonce, $action = -1)
{
    return 'test-nonce' === $nonce ? 1 : false;
}

function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $echo = true)
{
    $field = '<input type="hidden" name="' . $name . '" value="test-nonce" />';
    if ($echo) {
        echo $field;
    }
    return $field;
}
```

- [ ] **Step 4: Write the profile class**

Create `includes/admin/class-mcpwp-mupot-profile.php`:

```php
<?php
/**
 * mupot Profile Fields
 *
 * Per-user "connect your mupot account" fields on the WP Profile screen.
 * Gated on mupot_enabled and current_user_can('activate_plugins') — the
 * same capability every other mcpwp admin surface already requires.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and saves the Profile screen mupot fields.
 */
class Mcpwp_Mupot_Profile {

	/**
	 * Nonce action/field name for the profile save.
	 *
	 * @var string
	 */
	const NONCE_FIELD = 'mcpwp_mupot_profile_nonce';

	/**
	 * Whether the mupot connector is enabled site-wide.
	 *
	 * @return bool
	 */
	private function is_enabled() {
		$settings = get_option( 'mcpwp_settings', array() );
		return ! empty( $settings['mupot_enabled'] );
	}

	/**
	 * Render the token + squad fields on a user's Profile screen.
	 *
	 * @param WP_User|object $user The profile-owning user.
	 */
	public function render_fields( $user ) {
		if ( ! $this->is_enabled() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$connector      = new Mcpwp_Mupot_Connector();
		$user_id        = (int) $user->ID;
		$has_token      = $connector->has_token( $user_id );
		$squad          = $connector->get_squad( $user_id );
		$token_display  = $has_token ? '••••••••' : '';
		?>
		<h2><?php esc_html_e( 'mupot Connection', 'mcpwp' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="mcpwp_mupot_member_token"><?php esc_html_e( 'Your mupot member token', 'mcpwp' ); ?></label></th>
				<td>
					<input type="password" id="mcpwp_mupot_member_token" name="mcpwp_mupot_member_token" value="" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $token_display ); ?>" />
					<p class="description"><?php esc_html_e( 'Paste your personal mupot member token. Leave blank to keep your current connection.', 'mcpwp' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="mcpwp_mupot_squad_id"><?php esc_html_e( 'Your mupot squad', 'mcpwp' ); ?></label></th>
				<td>
					<input type="text" id="mcpwp_mupot_squad_id" name="mcpwp_mupot_squad_id" value="<?php echo esc_attr( $squad ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'The squad id or slug your mupot task board lives in.', 'mcpwp' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
		wp_nonce_field( 'mcpwp_mupot_profile_save', self::NONCE_FIELD );
	}

	/**
	 * Persist the token + squad fields when a profile is saved.
	 *
	 * @param int $user_id The profile-owning user's ID.
	 */
	public function save_fields( $user_id ) {
		if ( ! $this->is_enabled() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), 'mcpwp_mupot_profile_save' ) ) {
			return;
		}

		$connector = new Mcpwp_Mupot_Connector();

		$new_token = isset( $_POST['mcpwp_mupot_member_token'] ) ? trim( (string) wp_unslash( $_POST['mcpwp_mupot_member_token'] ) ) : '';
		if ( '' !== $new_token ) {
			$connector->save_token( (int) $user_id, $new_token );
		}

		if ( isset( $_POST['mcpwp_mupot_squad_id'] ) ) {
			$connector->save_squad( (int) $user_id, wp_unslash( $_POST['mcpwp_mupot_squad_id'] ) );
		}
	}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml tests/MupotProfileTest.php`
Expected: `OK (6 tests, ...)`

- [ ] **Step 6: Wire hooks into the loader**

In `includes/class-mcpwp-loader.php`, inside `define_admin_hooks()` (starts at line 63), add after the `$this->add_action( 'admin_init', $settings, 'register_settings' );` line:

```php
		$mupot_profile = new Mcpwp_Mupot_Profile();
		$this->add_action( 'show_user_profile', $mupot_profile, 'render_fields' );
		$this->add_action( 'edit_user_profile', $mupot_profile, 'render_fields' );
		$this->add_action( 'personal_options_update', $mupot_profile, 'save_fields' );
		$this->add_action( 'edit_user_profile_update', $mupot_profile, 'save_fields' );
```

- [ ] **Step 7: Wire the require into mcpwp.php**

In `mcpwp.php`, find `require_once MCPWP_PLUGIN_DIR . 'includes/admin/class-mcpwp-settings.php';` (around line 347) and add immediately after it:

```php
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/class-mcpwp-mupot-profile.php';
```

- [ ] **Step 8: Run full suite**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml`
Expected: all pass, no regressions.

- [ ] **Step 9: Commit**

```bash
cd /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator
git add mcpwp/includes/admin/class-mcpwp-mupot-profile.php mcpwp/includes/class-mcpwp-loader.php mcpwp/mcpwp.php mcpwp/tests/MupotProfileTest.php mcpwp/tests/bootstrap.php
git commit -m "feat(mupot): add per-user Profile screen connect fields

Personal mupot member token (encrypted, masked once set) + squad id
(plain). Gated on mupot_enabled + activate_plugins, same as the rest
of mcpwp's admin surface. Blank token field on save keeps the existing
connection rather than clearing it.

Signed-off-by: mumega-mumcp <mupot:e6695da3-2e04-45b8-b4af-acddaa7c1438>"
```

---

### Task 4: Control Room "My mupot" section

**Files:**
- Modify: `mcpwp/includes/admin/traits/trait-mcpwp-admin-control-room.php`
- Modify: `mcpwp/admin/partials/mcpwp-control-room-display.php`
- Test: `mcpwp/tests/MupotControlRoomTest.php`

**Interfaces:**
- Consumes: `Mcpwp_Mupot_Connector::get_status()`, `::get_task_board()`, `::has_token()`, `::has_squad()` (Task 1). `mcpwp_settings['mupot_enabled']` (Task 2).
- Produces: `$data['mupot']` key in `Mcpwp_Admin::get_control_room_data()`'s return array, shape `array{enabled: bool, connected: bool, has_squad: bool, status: array|null, task_board: array|null, error: string|null}` — consumed only by the partial template in this same task.

- [ ] **Step 1: Write the failing test**

Create `tests/MupotControlRoomTest.php`. This tests the data-assembly method directly (the template itself is markup, exercised by the manual verification step, not unit tests — consistent with how `mcpwp-control-room-display.php` has no dedicated PHPUnit coverage today):

```php
<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-encryption.php';
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-mupot-connector.php';

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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml tests/MupotControlRoomTest.php`
Expected: FAIL — `Trait "Mcpwp_Admin_Mupot_Trait" not found`.

- [ ] **Step 3: Create the trait with the data-assembly method**

Create `includes/admin/traits/trait-mcpwp-admin-mupot.php`:

```php
<?php
/**
 * Admin mupot Control Room data assembly.
 *
 * Small dedicated trait (not folded into trait-mcpwp-admin-control-room.php)
 * so it stays independently testable without the full Control Room dependency
 * graph (Approvals, SEO Audit Store, Signals, etc.) — see MupotControlRoomTest.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Mcpwp_Admin_Mupot_Trait {

	/**
	 * Assemble the "My mupot" Control Room section data for the current user.
	 *
	 * @return array{enabled: bool, connected: bool, has_squad: bool, status: array|null, task_board: array|null, error: string|null}
	 */
	public function get_control_room_mupot_data() {
		$settings = get_option( 'mcpwp_settings', array() );
		$enabled  = ! empty( $settings['mupot_enabled'] );

		$data = array(
			'enabled'    => $enabled,
			'connected'  => false,
			'has_squad'  => false,
			'status'     => null,
			'task_board' => null,
			'error'      => null,
		);

		if ( ! $enabled ) {
			return $data;
		}

		$connector  = new Mcpwp_Mupot_Connector();
		$user_id    = get_current_user_id();
		$data['connected'] = $connector->has_token( $user_id );
		$data['has_squad'] = $connector->has_squad( $user_id );

		if ( ! $data['connected'] ) {
			return $data;
		}

		$status = $connector->get_status( $user_id );
		if ( is_wp_error( $status ) ) {
			$data['error'] = $status->get_error_message();
			return $data;
		}
		$data['status'] = $status;

		if ( $data['has_squad'] ) {
			$board = $connector->get_task_board( $user_id );
			if ( is_wp_error( $board ) ) {
				$data['error'] = $board->get_error_message();
			} else {
				$data['task_board'] = $board;
			}
		}

		return $data;
	}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml tests/MupotControlRoomTest.php`
Expected: `OK (4 tests, ...)`

- [ ] **Step 5: Mix the trait into Mcpwp_Admin and wire the data into Control Room**

In `includes/admin/class-mcpwp-admin.php`, lines 64-69 are the class's existing `use` statements:

```php
	use Mcpwp_Admin_Setup_Trait;
	use Mcpwp_Admin_Control_Room_Trait;
	use Mcpwp_Admin_Chat_Trait;
	use Mcpwp_Admin_Library_Trait;
	use Mcpwp_Admin_Settings_Trait;
	use Mcpwp_Admin_Activity_Trait;
```

Add `use Mcpwp_Admin_Mupot_Trait;` as a new line immediately after `use Mcpwp_Admin_Activity_Trait;` (line 69).

In `includes/admin/traits/trait-mcpwp-admin-control-room.php`, `get_control_room_data()` currently ends at lines 259-262:

```php
			}
		}

		return $data;
	}
```

Insert the new key between the closing `}` of the memory-count block (line 259) and `return $data;` (line 261):

```php
			}
		}

		// mupot connector (#689 read-first slice).
		$data['mupot'] = $this->get_control_room_mupot_data();

		return $data;
	}
```

Add the require for the new trait file in `mcpwp.php`. `class-mcpwp-admin.php` is required around line 345; add the new trait's require on the line immediately before it (traits must load before the class that uses them):

```php
	require_once MCPWP_PLUGIN_DIR . 'includes/admin/traits/trait-mcpwp-admin-mupot.php';
```

- [ ] **Step 6: Add the template section**

In `admin/partials/mcpwp-control-room-display.php`, add the destructure line after the existing `$memory_count = (int) ( $control_room['memory_count'] ?? 0 );` line:

```php
$mupot = isset( $control_room['mupot'] ) && is_array( $control_room['mupot'] ) ? $control_room['mupot'] : array();
```

Add the nav-tab anchor after the existing `<a class="nav-tab" href="#mcpwp-section-signals">...</a>` line, but only when the section will actually render:

```php
		<?php if ( ! empty( $mupot['enabled'] ) ) : ?>
		<a class="nav-tab" href="#mcpwp-section-mupot"><?php esc_html_e( 'My mupot', 'mcpwp' ); ?></a>
		<?php endif; ?>
```

Add the section itself immediately after line 620 (`</div>`, the closing tag of the Signals card — its content ends at line 619's `<?php endif; ?>`):

```php
<?php if ( ! empty( $mupot['enabled'] ) ) : ?>
<div class="mcpwp-card" id="mcpwp-section-mupot" style="margin-top:1.5rem">
	<div class="mcpwp-control-card-header">
		<h2 class="mcpwp-control-card-title"><?php esc_html_e( 'My mupot', 'mcpwp' ); ?></h2>
	</div>
	<?php if ( empty( $mupot['connected'] ) ) : ?>
		<p style="padding:1rem;color:#666">
			<?php
			printf(
				/* translators: %s: link to the user's own Profile screen */
				wp_kses(
					__( 'Not connected. <a href="%s">Add your mupot member token in your profile</a> to see your task board here.', 'mcpwp' ),
					array( 'a' => array( 'href' => array() ) )
				),
				esc_url( admin_url( 'profile.php' ) )
			);
			?>
		</p>
	<?php elseif ( ! empty( $mupot['error'] ) ) : ?>
		<p style="padding:1rem;color:#c0392b"><?php echo esc_html( $mupot['error'] ); ?></p>
	<?php else : ?>
		<?php if ( is_array( $mupot['status'] ) ) : ?>
			<div style="padding:0 1rem 1rem">
				<strong><?php esc_html_e( 'Status', 'mcpwp' ); ?>:</strong>
				<code><?php echo esc_html( wp_json_encode( $mupot['status'] ) ); ?></code>
			</div>
		<?php endif; ?>
		<?php if ( empty( $mupot['has_squad'] ) ) : ?>
			<p style="padding:0 1rem 1rem;color:#666">
				<?php
				printf(
					wp_kses(
						__( 'Add your mupot squad id in <a href="%s">your profile</a> to see your task board.', 'mcpwp' ),
						array( 'a' => array( 'href' => array() ) )
					),
					esc_url( admin_url( 'profile.php' ) )
				);
				?>
			</p>
		<?php elseif ( is_array( $mupot['task_board'] ) ) : ?>
			<div style="padding:0 1rem 1rem">
				<strong><?php esc_html_e( 'Task Board', 'mcpwp' ); ?>:</strong>
				<code><?php echo esc_html( wp_json_encode( $mupot['task_board'] ) ); ?></code>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php endif; ?>
```

- [ ] **Step 7: Run full suite**

Run: `docker run --rm -v /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator/mcpwp:/app -w /app php:8.3-cli php vendor/bin/phpunit -c tests/phpunit.xml`
Expected: all pass, no regressions.

- [ ] **Step 8: Commit**

```bash
cd /mnt/HC_Volume_104325311/projects/sitepilotai/wp-ai-operator
git add mcpwp/includes/admin/traits/trait-mcpwp-admin-mupot.php mcpwp/includes/admin/traits/trait-mcpwp-admin-control-room.php mcpwp/includes/admin/class-mcpwp-admin.php mcpwp/admin/partials/mcpwp-control-room-display.php mcpwp/mcpwp.php mcpwp/tests/MupotControlRoomTest.php
git commit -m "feat(mupot): add 'My mupot' section to Control Room (#689)

Read-only status + task_board for the current user, rendered as a new
anchor-nav section following the existing Control Room pattern. Hidden
entirely when mupot_enabled is false. Distinguishes unconnected /
no-squad / error / live-data states per the design spec.

Refs Mumega-com/mcpwp#689

Signed-off-by: mumega-mumcp <mupot:e6695da3-2e04-45b8-b4af-acddaa7c1438>"
```

- [ ] **Step 9: Manual verification**

1. `docker exec wp-test-wordpress-1 wp option patch update mcpwp_settings mupot_enabled 1 --format=json --allow-root` (or toggle via WP Admin > mcpwp > Settings).
2. Visit your own Profile screen, confirm the two new fields render, paste a real mupot member token + squad id, save.
3. Visit Control Room, confirm the "My mupot" nav-tab and section appear, and that `status`/`task_board` show genuine live data from the configured mupot instance.
4. Disable `mupot_enabled` again, confirm both the Profile fields and the Control Room section disappear immediately.

---

## Self-Review

**Spec coverage:** Site toggle + instance URL (Task 2) ✓. Per-user token, encrypted (Task 1, 3) ✓. Per-user squad id, plain (Task 1, 3) ✓. Control Room "My mupot" tab, not a new page (Task 4) ✓. All five states — disabled, unconnected, no-squad, connected, error/unavailable/forbidden (Tasks 1, 4) ✓. 60s transient cache (Task 1) ✓. `activate_plugins` gate on both surfaces (Task 3, 4 — Control Room's own page-level gate already exists in `render_control_room_page()`, unchanged) ✓. No mupot-repo changes — confirmed, every file touched is under `Mumega-com/mcpwp` ✓.

**Placeholder scan:** No TBD/TODO. All code blocks are complete, runnable PHP. Every test asserts concrete behavior, not "add tests for the above."

**Type consistency:** `Mcpwp_Mupot_Connector::get_status()`/`get_task_board()` return `array|WP_Error` consistently across Task 1's class, Task 4's trait consumption, and their respective tests. `has_token()`/`has_squad()`/`get_squad()` signatures match between Task 1 (defined), Task 3 (consumed in profile render/save), and Task 4 (consumed in data assembly). `mcpwp_settings['mupot_enabled']`/`['mupot_instance_url']` keys match exactly across Task 2 (defined), Task 1's `get_instance_url()`, Task 3's `is_enabled()`, and Task 4's `get_control_room_mupot_data()`. Nonce field name (`mcpwp_mupot_profile_nonce`, the `$_POST` key) and nonce action string (`mcpwp_mupot_profile_save`) verified consistent between `render_fields()`, `save_fields()`, and the Task 3 test's `$_POST` setup.

**Correctness fix applied during self-review:** `call_tool()`'s request body casts `args` to `(object)` before encoding — `wp_json_encode( array() )` (the `status` call's empty args) produces `[]`, but mupot's tool schemas declare `args` as a JSON object; `(object) array()` correctly encodes as `{}`. Non-empty `args` (the `task_board` call) round-trip identically either way, so this doesn't affect the `task_board` test assertions.
