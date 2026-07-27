# SEO Market Data Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give MCPWP real external SEO data — keyword volume/difficulty and search visibility — so a customer's AI agent can decide what to work on next, and the Control Room SEO tab stops being half-empty.

**Architecture:** A BYO-credential DataForSEO provider following the existing `Mcpwp_Integration_Manager` pattern, feeding two consumers: enrichment of the existing `Mcpwp_Keyword_Research`, and a new `Mcpwp_SEO_Visibility` store. A pure-function ranker fuses visibility, market data and existing audit issues into an opportunity list whose signal adapts to how mature the site is.

**Tech Stack:** PHP 7.4+, WordPress REST API, PHPUnit 9, DataForSEO Labs API v3.

## Global Constraints

- PHP 7.4 minimum — no typed properties, no arrow functions, no `match`, no constructor promotion.
- Every user-facing string wrapped in `__( ..., 'mcpwp' )`.
- No `console.log`/`error_log` debugging left in shipped code.
- **CI must never call the live DataForSEO API.** All tests replay fixtures in `mcpwp/tests/fixtures/dataforseo/`.
- **An unavailable source and an empty result must never produce the same output.** Every failure path returns a distinct `reason` string.
- Provider tier is `pro`. Credentials stored via `Mcpwp_Integration_Manager`, never in a new option.
- DataForSEO uses **HTTP Basic auth** (login + password), not a bearer token.
- New test files MUST be added to `mcpwp/tests/phpunit.xml` explicitly or they never run.
- Run tests inside the container: `docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml'`
- Conventional Commits (`feat:` → minor, `fix:` → patch). Never hand-bump versions; release-please owns them.

## File Structure

| File | Responsibility |
|---|---|
| `mcpwp/includes/core/class-mcpwp-provider-dataforseo.php` | HTTP client: Basic auth, POST, status→reason mapping, response unwrapping |
| `mcpwp/includes/core/class-mcpwp-seo-visibility.php` | Store + query ranked-keyword snapshots |
| `mcpwp/includes/core/class-mcpwp-seo-opportunities.php` | Pure ranker fusing visibility + market + audit |
| `mcpwp/includes/core/class-mcpwp-keyword-research.php` | *Modified* — gains enrichment, keeps autocomplete |
| `mcpwp/includes/core/class-mcpwp-integration-manager.php` | *Modified* — registry entry + test dispatch |
| `mcpwp/includes/core/class-mcpwp-seo-cache.php` | Transient cache for paid lookups, including failures |
| `mcpwp/includes/api/class-mcpwp-rest-seo-market.php` | REST routes for visibility + opportunities |
| `mcpwp/includes/mcp/class-mcpwp-mcp-pro-tools.php` | *Modified* — three new tool→route mappings |
| `mcpwp/includes/core/class-mcpwp-agent-playbooks.php` | *Modified* — `improve_search_visibility` playbook |
| `mcpwp/includes/admin/traits/trait-mcpwp-admin-control-room.php` | *Modified* — visibility + opportunities in payload |
| `mcpwp/admin/partials/mcpwp-control-room-display.php` | *Modified* — renders the opportunity table |
| `mcpwp/tests/fixtures/dataforseo/*.json` | Captured live responses (already committed) |

---

### Task 1: DataForSEO provider client

**Files:**
- Create: `mcpwp/includes/core/class-mcpwp-provider-dataforseo.php`
- Create: `mcpwp/tests/unit/test-dataforseo-provider.php`
- Modify: `mcpwp/tests/phpunit.xml`
- Modify: `mcpwp/tests/bootstrap.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `new Mcpwp_Provider_Dataforseo( string $login, string $password )`
  - `keyword_suggestions( string $seed, array $args = array() ) : array|WP_Error`
  - `ranked_keywords( string $target, array $args = array() ) : array|WP_Error`
  - `test_connection() : array` → `array{success:bool, message:string, balance:float|null}`
  - `public static function reason_for_status( int $status_code ) : string`
  - `protected function post( string $path, array $payload )` — overridden in tests to replay fixtures.

- [ ] **Step 1: Write the failing test**

Create `mcpwp/tests/unit/test-dataforseo-provider.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

/**
 * Replays captured live responses. CI must never call the real API.
 */
class FixtureDataforseoProvider extends Mcpwp_Provider_Dataforseo {
	public $fixture;
	public $last_payload;

	protected function post( $path, $payload ) {
		$this->last_payload = $payload;
		return $this->fixture;
	}
}

class DataforseoProviderTest extends TestCase {

	private function fixture( $name ) {
		$path = dirname( __DIR__ ) . '/fixtures/dataforseo/' . $name . '.json';
		return json_decode( file_get_contents( $path ), true );
	}

	private function provider( $fixture_name ) {
		$p          = new FixtureDataforseoProvider( 'user@example.com', 'secret' );
		$p->fixture = $this->fixture( $fixture_name );
		return $p;
	}

	public function test_keyword_suggestions_returns_market_metrics() {
		$result = $this->provider( 'keyword_suggestions_live' )->keyword_suggestions( 'wordpress mcp' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$first = $result[0];
		$this->assertSame( 'wordpress mcp', $first['keyword'] );
		$this->assertSame( 720, $first['search_volume'] );
		$this->assertSame( 27, $first['keyword_difficulty'] );
		$this->assertEqualsWithDelta( 14.45, $first['cpc'], 0.01 );
		$this->assertSame( 'LOW', $first['competition_level'] );
	}

	public function test_ranked_keywords_returns_positions_and_urls() {
		$result = $this->provider( 'ranked_keywords_live' )->ranked_keywords( 'mcpwp.net' );

		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );

		$keywords = array_column( $result, 'keyword' );
		$this->assertContains( 'wordpress mcp', $keywords );

		foreach ( $result as $row ) {
			$this->assertArrayHasKey( 'position', $row );
			$this->assertArrayHasKey( 'ranking_url', $row );
			// Clicks and impressions are Search Console data. This provider
			// cannot supply them, so it must not invent them.
			$this->assertArrayNotHasKey( 'clicks', $row );
			$this->assertArrayNotHasKey( 'impressions', $row );
		}
	}

	/**
	 * @dataProvider status_reason_cases
	 */
	public function test_each_failure_status_has_its_own_reason( $status, $expected ) {
		$this->assertSame( $expected, Mcpwp_Provider_Dataforseo::reason_for_status( $status ) );
	}

	public function status_reason_cases() {
		return array(
			'auth rejected'      => array( 40100, 'auth_rejected' ),
			'payment required'   => array( 40200, 'insufficient_credits' ),
			'client error'       => array( 40400, 'request_rejected' ),
			'server error'       => array( 50000, 'provider_unavailable' ),
		);
	}

	public function test_reasons_are_all_distinct() {
		$reasons = array();
		foreach ( array( 40100, 40200, 40400, 50000 ) as $code ) {
			$reasons[] = Mcpwp_Provider_Dataforseo::reason_for_status( $code );
		}
		$this->assertSame( $reasons, array_unique( $reasons ), 'Each failure must be distinguishable.' );
	}

	public function test_non_ok_status_returns_error_not_empty_array() {
		$p          = new FixtureDataforseoProvider( 'user@example.com', 'secret' );
		$p->fixture = array( 'status_code' => 40100, 'status_message' => 'Unauthorized.' );

		$result = $p->keyword_suggestions( 'anything' );

		// An unusable credential must never look like "no keywords found".
		$this->assertInstanceOf( 'WP_Error', $result );
		$this->assertSame( 'auth_rejected', $result->get_error_code() );
	}

	public function test_request_caps_keywords_sent_for_enrichment() {
		$p          = new FixtureDataforseoProvider( 'user@example.com', 'secret' );
		$p->fixture = $this->fixture( 'keyword_suggestions_live' );

		$p->keyword_suggestions( 'wordpress mcp', array( 'limit' => 9999 ) );

		$this->assertLessThanOrEqual(
			Mcpwp_Provider_Dataforseo::MAX_ENRICHMENT_KEYWORDS,
			$p->last_payload[0]['limit'],
			'An unbounded limit must be clamped so an agent loop cannot spend without bound.'
		);
	}
}
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter DataforseoProviderTest'
```

Expected: FAIL — `Class "Mcpwp_Provider_Dataforseo" not found`.

- [ ] **Step 3: Register the test file and bootstrap the class**

In `mcpwp/tests/phpunit.xml`, add inside `<testsuite>`:

```xml
      <file>./unit/test-dataforseo-provider.php</file>
```

Append to `mcpwp/tests/bootstrap.php`:

```php
// DataForSEO market data provider.
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-provider-dataforseo.php';
```

- [ ] **Step 4: Implement the provider**

Create `mcpwp/includes/core/class-mcpwp-provider-dataforseo.php`:

```php
<?php
/**
 * DataForSEO API client — keyword market data and search visibility.
 *
 * The customer brings their own credentials. Every call spends their money,
 * so limits are clamped here rather than trusted from callers.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DataForSEO Labs client.
 */
class Mcpwp_Provider_Dataforseo {

	const API_BASE = 'https://api.dataforseo.com/v3/';

	/**
	 * DataForSEO signals success with 20000, not with HTTP 200 alone.
	 */
	const STATUS_OK = 20000;

	/**
	 * Hard ceiling on keywords sent for enrichment in one request.
	 *
	 * No single call is expensive ($0.012/request + $0.00012/result), so this
	 * exists to bound an agent loop, not to ration lookups.
	 */
	const MAX_ENRICHMENT_KEYWORDS = 50;

	const TIMEOUT = 30;

	/**
	 * API login.
	 *
	 * @var string
	 */
	private $login;

	/**
	 * API password.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Constructor.
	 *
	 * @param string $login    DataForSEO API login.
	 * @param string $password DataForSEO API password.
	 */
	public function __construct( $login, $password ) {
		$this->login    = (string) $login;
		$this->password = (string) $password;
	}

	/**
	 * Map a DataForSEO status code to a distinct machine-readable reason.
	 *
	 * Each failure gets its own reason so "out of credits" never renders the
	 * same as "no results" or "bad password".
	 *
	 * @param int $status_code DataForSEO status code.
	 * @return string Reason slug.
	 */
	public static function reason_for_status( $status_code ) {
		$status_code = (int) $status_code;

		if ( self::STATUS_OK === $status_code ) {
			return 'ok';
		}
		if ( $status_code >= 40100 && $status_code < 40200 ) {
			return 'auth_rejected';
		}
		if ( $status_code >= 40200 && $status_code < 40300 ) {
			return 'insufficient_credits';
		}
		if ( $status_code >= 40000 && $status_code < 50000 ) {
			return 'request_rejected';
		}

		return 'provider_unavailable';
	}

	/**
	 * Human-readable sentence for a reason slug.
	 *
	 * @param string $reason Reason slug.
	 * @return string
	 */
	public static function describe_reason( $reason ) {
		switch ( $reason ) {
			case 'auth_rejected':
				return __( 'DataForSEO rejected the configured credentials.', 'mcpwp' );
			case 'insufficient_credits':
				return __( 'The DataForSEO account has no remaining credit.', 'mcpwp' );
			case 'request_rejected':
				return __( 'DataForSEO rejected the request parameters.', 'mcpwp' );
			case 'provider_unavailable':
				return __( 'DataForSEO could not be reached.', 'mcpwp' );
			case 'not_configured':
				return __( 'No DataForSEO credentials are configured.', 'mcpwp' );
			default:
				return __( 'DataForSEO returned no results.', 'mcpwp' );
		}
	}

	/**
	 * Keyword suggestions with market metrics.
	 *
	 * @param string $seed Seed keyword.
	 * @param array  $args Optional: limit, language_code, location_code.
	 * @return array|WP_Error List of normalized keyword rows.
	 */
	public function keyword_suggestions( $seed, $args = array() ) {
		$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : self::MAX_ENRICHMENT_KEYWORDS;
		$limit = max( 1, min( self::MAX_ENRICHMENT_KEYWORDS, $limit ) );

		$payload = array(
			array(
				'keyword'           => (string) $seed,
				'language_code'     => isset( $args['language_code'] ) ? (string) $args['language_code'] : 'en',
				'location_code'     => isset( $args['location_code'] ) ? (int) $args['location_code'] : 2840,
				'limit'             => $limit,
				'include_serp_info' => false,
			),
		);

		$items = $this->request( 'dataforseo_labs/google/keyword_suggestions/live', $payload );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$out = array();
		foreach ( $items as $item ) {
			$info  = isset( $item['keyword_info'] ) && is_array( $item['keyword_info'] ) ? $item['keyword_info'] : array();
			$props = isset( $item['keyword_properties'] ) && is_array( $item['keyword_properties'] ) ? $item['keyword_properties'] : array();

			$out[] = array(
				'keyword'            => isset( $item['keyword'] ) ? (string) $item['keyword'] : '',
				'search_volume'      => isset( $info['search_volume'] ) ? (int) $info['search_volume'] : 0,
				'cpc'                => isset( $info['cpc'] ) ? (float) $info['cpc'] : 0.0,
				'competition'        => isset( $info['competition'] ) ? (float) $info['competition'] : 0.0,
				'competition_level'  => isset( $info['competition_level'] ) ? (string) $info['competition_level'] : '',
				'keyword_difficulty' => isset( $props['keyword_difficulty'] ) ? (int) $props['keyword_difficulty'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Keywords the target domain already ranks for.
	 *
	 * Returns position and market metrics only. Clicks and impressions are
	 * Search Console data; this provider has no access to them and must not
	 * emit zeroed columns that would render as "no traffic".
	 *
	 * @param string $target Domain, without scheme.
	 * @param array  $args   Optional: limit, language_code, location_code.
	 * @return array|WP_Error
	 */
	public function ranked_keywords( $target, $args = array() ) {
		$limit = isset( $args['limit'] ) ? absint( $args['limit'] ) : 100;
		$limit = max( 1, min( 1000, $limit ) );

		$payload = array(
			array(
				'target'        => (string) $target,
				'language_code' => isset( $args['language_code'] ) ? (string) $args['language_code'] : 'en',
				'location_code' => isset( $args['location_code'] ) ? (int) $args['location_code'] : 2840,
				'limit'         => $limit,
				'order_by'      => array( 'ranked_serp_element.serp_item.rank_group,asc' ),
			),
		);

		$items = $this->request( 'dataforseo_labs/google/ranked_keywords/live', $payload );
		if ( is_wp_error( $items ) ) {
			return $items;
		}

		$out = array();
		foreach ( $items as $item ) {
			$kd    = isset( $item['keyword_data'] ) && is_array( $item['keyword_data'] ) ? $item['keyword_data'] : array();
			$info  = isset( $kd['keyword_info'] ) && is_array( $kd['keyword_info'] ) ? $kd['keyword_info'] : array();
			$props = isset( $kd['keyword_properties'] ) && is_array( $kd['keyword_properties'] ) ? $kd['keyword_properties'] : array();
			$serp  = isset( $item['ranked_serp_element']['serp_item'] ) && is_array( $item['ranked_serp_element']['serp_item'] )
				? $item['ranked_serp_element']['serp_item']
				: array();

			$keyword = isset( $kd['keyword'] ) ? (string) $kd['keyword'] : '';
			if ( '' === $keyword ) {
				continue;
			}

			$out[] = array(
				'keyword'            => $keyword,
				'position'           => isset( $serp['rank_group'] ) ? (int) $serp['rank_group'] : 0,
				'ranking_url'        => isset( $serp['url'] ) ? (string) $serp['url'] : '',
				'search_volume'      => isset( $info['search_volume'] ) ? (int) $info['search_volume'] : 0,
				'keyword_difficulty' => isset( $props['keyword_difficulty'] ) ? (int) $props['keyword_difficulty'] : 0,
			);
		}

		return $out;
	}

	/**
	 * Verify credentials and report remaining credit.
	 *
	 * @return array{success:bool,message:string,balance:float|null}
	 */
	public function test_connection() {
		$response = $this->post( 'appendix/user_data', array() );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
				'balance' => null,
			);
		}

		$status = isset( $response['status_code'] ) ? (int) $response['status_code'] : 0;
		if ( self::STATUS_OK !== $status ) {
			return array(
				'success' => false,
				'message' => self::describe_reason( self::reason_for_status( $status ) ),
				'balance' => null,
			);
		}

		$balance = isset( $response['tasks'][0]['result'][0]['money']['balance'] )
			? (float) $response['tasks'][0]['result'][0]['money']['balance']
			: null;

		return array(
			'success' => true,
			'message' => null === $balance
				? __( 'Connected to DataForSEO.', 'mcpwp' )
				: sprintf(
					/* translators: %s: remaining account credit in USD. */
					__( 'Connected to DataForSEO. Remaining credit: $%s', 'mcpwp' ),
					number_format( $balance, 2 )
				),
			'balance' => $balance,
		);
	}

	/**
	 * Issue a request and unwrap the task result items.
	 *
	 * @param string $path    API path after /v3/.
	 * @param array  $payload Request payload.
	 * @return array|WP_Error Items array.
	 */
	private function request( $path, $payload ) {
		$response = $this->post( $path, $payload );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = isset( $response['status_code'] ) ? (int) $response['status_code'] : 0;
		if ( self::STATUS_OK !== $status ) {
			$reason = self::reason_for_status( $status );
			return new WP_Error( $reason, self::describe_reason( $reason ) );
		}

		// A task can fail while the envelope reports success.
		$task_status = isset( $response['tasks'][0]['status_code'] ) ? (int) $response['tasks'][0]['status_code'] : self::STATUS_OK;
		if ( self::STATUS_OK !== $task_status ) {
			$reason = self::reason_for_status( $task_status );
			return new WP_Error( $reason, self::describe_reason( $reason ) );
		}

		$items = isset( $response['tasks'][0]['result'][0]['items'] ) ? $response['tasks'][0]['result'][0]['items'] : array();

		return is_array( $items ) ? $items : array();
	}

	/**
	 * POST to the API. Overridden in tests to replay fixtures.
	 *
	 * @param string $path    API path after /v3/.
	 * @param array  $payload Request payload.
	 * @return array|WP_Error Decoded response.
	 */
	protected function post( $path, $payload ) {
		$args = array(
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->login . ':' . $this->password ),
				'Content-Type'  => 'application/json',
			),
		);

		if ( ! empty( $payload ) ) {
			$args['body'] = wp_json_encode( $payload );
		} else {
			$args['method'] = 'GET';
		}

		$response = empty( $payload )
			? wp_remote_get( self::API_BASE . $path, $args )
			: wp_remote_post( self::API_BASE . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'provider_unavailable', self::describe_reason( 'provider_unavailable' ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'provider_unavailable', self::describe_reason( 'provider_unavailable' ) );
		}

		return $decoded;
	}
}
```

- [ ] **Step 5: Run the tests**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter DataforseoProviderTest'
```

Expected: PASS, 7 tests.

- [ ] **Step 6: Commit**

```bash
git add mcpwp/includes/core/class-mcpwp-provider-dataforseo.php mcpwp/tests/unit/test-dataforseo-provider.php mcpwp/tests/phpunit.xml mcpwp/tests/bootstrap.php
git commit -m "feat(seo): DataForSEO provider client with distinct failure reasons"
```

---

### Task 2: Register the provider

**Files:**
- Modify: `mcpwp/includes/core/class-mcpwp-integration-manager.php`
- Modify: `mcpwp/mcpwp.php`
- Create: `mcpwp/tests/unit/test-dataforseo-registration.php`
- Modify: `mcpwp/tests/phpunit.xml`

**Interfaces:**
- Consumes: `Mcpwp_Provider_Dataforseo::test_connection()` from Task 1.
- Produces: provider key `'dataforseo'` retrievable via `get_provider_config( 'dataforseo' )` returning `array{login:string,password:string}`.

- [ ] **Step 1: Write the failing test**

Create `mcpwp/tests/unit/test-dataforseo-registration.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

class DataforseoRegistrationTest extends TestCase {

	public function test_provider_is_registered_as_pro_multi_field() {
		$providers = Mcpwp_Integration_Manager::PROVIDERS;

		$this->assertArrayHasKey( 'dataforseo', $providers );
		$this->assertSame( 'pro', $providers['dataforseo']['tier'] );
		$this->assertArrayHasKey( 'fields', $providers['dataforseo'] );
	}

	public function test_provider_uses_login_and_password_not_api_key() {
		$fields = Mcpwp_Integration_Manager::PROVIDERS['dataforseo']['fields'];

		// DataForSEO is HTTP Basic auth. A single api_key field would be wrong.
		$this->assertArrayHasKey( 'login', $fields );
		$this->assertArrayHasKey( 'password', $fields );
		$this->assertArrayNotHasKey( 'api_key', $fields );
	}

	public function test_password_field_is_masked() {
		$fields = Mcpwp_Integration_Manager::PROVIDERS['dataforseo']['fields'];
		$this->assertSame( 'password', $fields['password']['type'] );
	}
}
```

Add to `mcpwp/tests/phpunit.xml`:

```xml
      <file>./unit/test-dataforseo-registration.php</file>
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter DataforseoRegistrationTest'
```

Expected: FAIL — `Failed asserting that an array has the key 'dataforseo'`.

- [ ] **Step 3: Add the registry entry**

In `mcpwp/includes/core/class-mcpwp-integration-manager.php`, inside the `PROVIDERS` constant, after the `'posthog'` entry:

```php
		'dataforseo' => array(
			'name'        => 'DataForSEO',
			'url'         => 'https://app.dataforseo.com/api-access',
			'tier'        => 'pro',
			'description' => 'Keyword search volume, difficulty and search visibility. You bring your own DataForSEO account; usage is billed to you directly.',
			'fields'      => array(
				'login'    => array(
					'label'       => 'API Login',
					'placeholder' => 'you@example.com',
					'type'        => 'text',
				),
				'password' => array(
					'label'       => 'API Password',
					'placeholder' => '',
					'type'        => 'password',
				),
			),
		),
```

- [ ] **Step 4: Add the test dispatch**

In the same file, inside `test_provider()`, after the `'figma'` branch:

```php
		if ( 'dataforseo' === $provider ) {
			return $this->test_dataforseo_provider();
		}
```

Then add the method alongside the other `test_*_provider()` methods:

```php
	/**
	 * Verify DataForSEO credentials and surface the remaining credit.
	 *
	 * The balance is shown because every lookup spends the customer's money —
	 * they should be able to see what is left without leaving WP admin.
	 *
	 * @return array{success:bool,message:string}
	 */
	private function test_dataforseo_provider() {
		$config = $this->get_provider_config( 'dataforseo' );

		if ( empty( $config['login'] ) || empty( $config['password'] ) ) {
			return array(
				'success' => false,
				'message' => Mcpwp_Provider_Dataforseo::describe_reason( 'not_configured' ),
			);
		}

		$provider = new Mcpwp_Provider_Dataforseo( $config['login'], $config['password'] );
		$result   = $provider->test_connection();

		return array(
			'success' => (bool) $result['success'],
			'message' => (string) $result['message'],
		);
	}
```

- [ ] **Step 5: Load the class in the plugin bootstrap**

In `mcpwp/mcpwp.php`, alongside the other provider `require_once` calls:

```php
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-provider-dataforseo.php';
```

- [ ] **Step 6: Run the tests**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml'
```

Expected: PASS, all tests including the 3 new ones.

- [ ] **Step 7: Commit**

```bash
git add mcpwp/includes/core/class-mcpwp-integration-manager.php mcpwp/mcpwp.php mcpwp/tests/unit/test-dataforseo-registration.php mcpwp/tests/phpunit.xml
git commit -m "feat(seo): register DataForSEO provider with credit-balance connection test"
```

---

### Task 3: Visibility store

**Files:**
- Create: `mcpwp/includes/core/class-mcpwp-seo-visibility.php`
- Create: `mcpwp/tests/unit/test-seo-visibility.php`
- Modify: `mcpwp/tests/phpunit.xml`, `mcpwp/tests/bootstrap.php`

**Interfaces:**
- Consumes: normalized rows from `Mcpwp_Provider_Dataforseo::ranked_keywords()` (Task 1).
- Produces:
  - `Mcpwp_SEO_Visibility::store_snapshot( array $rows, string $target ) : array` → `array{snapshot_id:string, captured_at:int, count:int}`
  - `Mcpwp_SEO_Visibility::get_latest() : array` → `array{snapshot_id:string|null, captured_at:int|null, target:string|null, rows:array, reason:string|null}`
  - `Mcpwp_SEO_Visibility::OPTION_NAME` = `'mcpwp_seo_visibility'`

- [ ] **Step 1: Write the failing test**

Create `mcpwp/tests/unit/test-seo-visibility.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

class SeoVisibilityTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_options'] = array();
	}

	private function rows() {
		return array(
			array(
				'keyword'            => 'wordpress mcp',
				'position'           => 75,
				'ranking_url'        => 'https://docs.mcpwp.net/get-started/',
				'search_volume'      => 720,
				'keyword_difficulty' => 27,
			),
			array(
				'keyword'            => 'mcp pricing',
				'position'           => 91,
				'ranking_url'        => 'https://mcpwp.net/pricing/',
				'search_volume'      => 30,
				'keyword_difficulty' => 9,
			),
		);
	}

	public function test_snapshot_round_trips() {
		Mcpwp_SEO_Visibility::store_snapshot( $this->rows(), 'mcpwp.net' );

		$latest = Mcpwp_SEO_Visibility::get_latest();

		$this->assertSame( 'mcpwp.net', $latest['target'] );
		$this->assertCount( 2, $latest['rows'] );
		$this->assertSame( 'wordpress mcp', $latest['rows'][0]['keyword'] );
		$this->assertSame( 75, $latest['rows'][0]['position'] );
	}

	public function test_store_never_carries_click_or_impression_columns() {
		Mcpwp_SEO_Visibility::store_snapshot( $this->rows(), 'mcpwp.net' );
		$latest = Mcpwp_SEO_Visibility::get_latest();

		foreach ( $latest['rows'] as $row ) {
			// DataForSEO cannot supply these. A zeroed column would render as
			// "no traffic", which is a different and false claim.
			$this->assertArrayNotHasKey( 'clicks', $row );
			$this->assertArrayNotHasKey( 'impressions', $row );
			$this->assertArrayNotHasKey( 'ctr', $row );
		}
	}

	public function test_no_snapshot_is_reported_as_a_reason_not_an_empty_list() {
		$latest = Mcpwp_SEO_Visibility::get_latest();

		$this->assertSame( array(), $latest['rows'] );
		$this->assertSame( 'never_fetched', $latest['reason'] );
	}

	public function test_a_real_empty_snapshot_differs_from_never_fetched() {
		Mcpwp_SEO_Visibility::store_snapshot( array(), 'newsite.example' );
		$latest = Mcpwp_SEO_Visibility::get_latest();

		$this->assertSame( array(), $latest['rows'] );
		// The site genuinely ranks for nothing — that is an answer, not a gap.
		$this->assertSame( 'no_rankings', $latest['reason'] );
	}

	public function test_rows_are_sorted_by_position() {
		Mcpwp_SEO_Visibility::store_snapshot( array_reverse( $this->rows() ), 'mcpwp.net' );
		$latest = Mcpwp_SEO_Visibility::get_latest();

		$this->assertSame( 75, $latest['rows'][0]['position'] );
		$this->assertSame( 91, $latest['rows'][1]['position'] );
	}
}
```

Add to `mcpwp/tests/phpunit.xml`:

```xml
      <file>./unit/test-seo-visibility.php</file>
```

Append to `mcpwp/tests/bootstrap.php`:

```php
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-visibility.php';
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter SeoVisibilityTest'
```

Expected: FAIL — `Class "Mcpwp_SEO_Visibility" not found`.

- [ ] **Step 3: Implement the store**

Create `mcpwp/includes/core/class-mcpwp-seo-visibility.php`:

```php
<?php
/**
 * Search visibility snapshots — where the site ranks in the market.
 *
 * Deliberately separate from Mcpwp_Search_Performance. That store holds
 * first-party traffic (clicks, impressions, CTR) from Search Console. This one
 * holds market position and volume. Sharing a table would force zeroed click
 * columns that render as "no traffic", and the two sources do not even measure
 * position the same way: Search Console reports an impression-weighted average,
 * DataForSEO reports a scraped SERP rank.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visibility snapshot store.
 */
class Mcpwp_SEO_Visibility {

	const OPTION_NAME = 'mcpwp_seo_visibility';

	/**
	 * Persist a snapshot, replacing any previous one.
	 *
	 * @param array  $rows   Normalized rows from the provider.
	 * @param string $target Domain the snapshot describes.
	 * @return array{snapshot_id:string,captured_at:int,count:int}
	 */
	public static function store_snapshot( $rows, $target ) {
		$captured_at = time();
		$snapshot_id = 'vis_' . md5( $target . '|' . $captured_at );

		$clean = array();
		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) || empty( $row['keyword'] ) ) {
				continue;
			}

			$clean[] = array(
				'keyword'            => sanitize_text_field( (string) $row['keyword'] ),
				'position'           => isset( $row['position'] ) ? (int) $row['position'] : 0,
				'ranking_url'        => isset( $row['ranking_url'] ) ? esc_url_raw( (string) $row['ranking_url'] ) : '',
				'search_volume'      => isset( $row['search_volume'] ) ? (int) $row['search_volume'] : 0,
				'keyword_difficulty' => isset( $row['keyword_difficulty'] ) ? (int) $row['keyword_difficulty'] : 0,
			);
		}

		usort(
			$clean,
			function ( $a, $b ) {
				return $a['position'] - $b['position'];
			}
		);

		update_option(
			self::OPTION_NAME,
			array(
				'snapshot_id' => $snapshot_id,
				'captured_at' => $captured_at,
				'target'      => sanitize_text_field( (string) $target ),
				'rows'        => $clean,
			)
		);

		return array(
			'snapshot_id' => $snapshot_id,
			'captured_at' => $captured_at,
			'count'       => count( $clean ),
		);
	}

	/**
	 * Read the latest snapshot.
	 *
	 * Distinguishes "never fetched" from "fetched and the site ranks for
	 * nothing" — the first is a gap, the second is an answer.
	 *
	 * @return array{snapshot_id:string|null,captured_at:int|null,target:string|null,rows:array,reason:string|null}
	 */
	public static function get_latest() {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['snapshot_id'] ) ) {
			return array(
				'snapshot_id' => null,
				'captured_at' => null,
				'target'      => null,
				'rows'        => array(),
				'reason'      => 'never_fetched',
			);
		}

		$rows = isset( $stored['rows'] ) && is_array( $stored['rows'] ) ? $stored['rows'] : array();

		return array(
			'snapshot_id' => (string) $stored['snapshot_id'],
			'captured_at' => isset( $stored['captured_at'] ) ? (int) $stored['captured_at'] : null,
			'target'      => isset( $stored['target'] ) ? (string) $stored['target'] : null,
			'rows'        => $rows,
			'reason'      => empty( $rows ) ? 'no_rankings' : null,
		);
	}
}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter SeoVisibilityTest'
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add mcpwp/includes/core/class-mcpwp-seo-visibility.php mcpwp/tests/unit/test-seo-visibility.php mcpwp/tests/phpunit.xml mcpwp/tests/bootstrap.php
git commit -m "feat(seo): visibility snapshot store, separate from first-party performance"
```

---

### Task 4: Keyword research enrichment

**Files:**
- Modify: `mcpwp/includes/core/class-mcpwp-keyword-research.php:194-263`
- Create: `mcpwp/tests/unit/test-keyword-enrichment.php`
- Modify: `mcpwp/tests/phpunit.xml`

**Interfaces:**
- Consumes: `Mcpwp_Provider_Dataforseo::keyword_suggestions()` (Task 1).
- Produces: `Mcpwp_Keyword_Research::enrich( array $grouped, $provider ) : array` — adds `metrics` (list of keyword rows) and `enrichment_reason` (string|null) to the grouped result.

- [ ] **Step 1: Write the failing test**

Create `mcpwp/tests/unit/test-keyword-enrichment.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

class StubEnrichmentProvider {
	public $result;

	public function keyword_suggestions( $seed, $args = array() ) {
		return $this->result;
	}
}

class KeywordEnrichmentTest extends TestCase {

	private function grouped() {
		return array(
			'seed'      => 'wordpress mcp',
			'keywords'  => array( 'wordpress mcp server' ),
			'questions' => array( 'what is wordpress mcp' ),
			'total'     => 2,
		);
	}

	public function test_enrichment_adds_market_metrics() {
		$provider         = new StubEnrichmentProvider();
		$provider->result = array(
			array(
				'keyword'            => 'wordpress mcp',
				'search_volume'      => 720,
				'cpc'                => 14.45,
				'competition'        => 0.12,
				'competition_level'  => 'LOW',
				'keyword_difficulty' => 27,
			),
		);

		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), $provider );

		$this->assertSame( 720, $result['metrics'][0]['search_volume'] );
		$this->assertSame( 27, $result['metrics'][0]['keyword_difficulty'] );
		$this->assertNull( $result['enrichment_reason'] );
	}

	public function test_no_provider_degrades_to_autocomplete_without_erroring() {
		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), null );

		// The free tier must keep working exactly as before.
		$this->assertSame( array( 'wordpress mcp server' ), $result['keywords'] );
		$this->assertSame( array(), $result['metrics'] );
		$this->assertSame( 'not_configured', $result['enrichment_reason'] );
	}

	public function test_provider_error_is_reported_not_swallowed() {
		$provider         = new StubEnrichmentProvider();
		$provider->result = new WP_Error( 'insufficient_credits', 'no credit' );

		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), $provider );

		// Out of credit must not look like "this keyword has no volume".
		$this->assertSame( array(), $result['metrics'] );
		$this->assertSame( 'insufficient_credits', $result['enrichment_reason'] );
	}

	public function test_apology_note_is_gone_when_enriched() {
		$provider         = new StubEnrichmentProvider();
		$provider->result = array(
			array(
				'keyword'            => 'wordpress mcp',
				'search_volume'      => 720,
				'cpc'                => 14.45,
				'competition'        => 0.12,
				'competition_level'  => 'LOW',
				'keyword_difficulty' => 27,
			),
		);

		$result = Mcpwp_Keyword_Research::enrich( $this->grouped(), $provider );

		$this->assertArrayNotHasKey( 'note', $result );
	}
}
```

Add to `mcpwp/tests/phpunit.xml`:

```xml
      <file>./unit/test-keyword-enrichment.php</file>
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter KeywordEnrichmentTest'
```

Expected: FAIL — `Call to undefined method Mcpwp_Keyword_Research::enrich()`.

- [ ] **Step 3: Add the enrich method**

In `mcpwp/includes/core/class-mcpwp-keyword-research.php`, add before the closing brace:

```php
	/**
	 * Attach market metrics to a grouped autocomplete result.
	 *
	 * Autocomplete supplies the ideas; DataForSEO supplies the numbers. With no
	 * provider the result is exactly what this class returned before enrichment
	 * existed, so the free tier degrades rather than breaking.
	 *
	 * @param array                             $grouped  Result of group().
	 * @param Mcpwp_Provider_Dataforseo|null    $provider Configured provider, or null.
	 * @return array Grouped result plus 'metrics' and 'enrichment_reason'.
	 */
	public static function enrich( array $grouped, $provider ) {
		$grouped['metrics']           = array();
		$grouped['enrichment_reason'] = 'not_configured';

		if ( null === $provider ) {
			return $grouped;
		}

		$seed    = isset( $grouped['seed'] ) ? (string) $grouped['seed'] : '';
		$metrics = $provider->keyword_suggestions( $seed );

		if ( is_wp_error( $metrics ) ) {
			$grouped['enrichment_reason'] = $metrics->get_error_code();
			return $grouped;
		}

		$grouped['metrics']           = is_array( $metrics ) ? $metrics : array();
		$grouped['enrichment_reason'] = null;

		// The apology only applies to an unenriched result.
		unset( $grouped['note'] );

		return $grouped;
	}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter KeywordEnrichmentTest'
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add mcpwp/includes/core/class-mcpwp-keyword-research.php mcpwp/tests/unit/test-keyword-enrichment.php mcpwp/tests/phpunit.xml
git commit -m "feat(seo): enrich keyword research with volume, CPC and difficulty"
```

---

### Task 5: Maturity-aware opportunity ranker

**Files:**
- Create: `mcpwp/includes/core/class-mcpwp-seo-opportunities.php`
- Create: `mcpwp/tests/unit/test-seo-opportunities.php`
- Modify: `mcpwp/tests/phpunit.xml`, `mcpwp/tests/bootstrap.php`

**Interfaces:**
- Consumes: visibility rows (Task 3), keyword metrics (Task 4).
- Produces: `Mcpwp_SEO_Opportunities::rank( array $visibility_rows, array $keyword_metrics, array $audit_issues ) : array` → `array{mode:string, opportunities:array, reason:string|null}`.

- [ ] **Step 1: Write the failing test**

Create `mcpwp/tests/unit/test-seo-opportunities.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

class SeoOpportunitiesTest extends TestCase {

	/**
	 * Real mcpwp.net data: six keywords, every one at position 64-91.
	 * A striking-distance-only ranker returns nothing for this site.
	 */
	private function young_site_rows() {
		return array(
			array( 'keyword' => 'claude desktop config json', 'position' => 64, 'search_volume' => 70,  'keyword_difficulty' => 14, 'ranking_url' => 'https://docs.mcpwp.net/connect/claude-desktop/' ),
			array( 'keyword' => 'claude desktop mcp',         'position' => 72, 'search_volume' => 590, 'keyword_difficulty' => 14, 'ranking_url' => 'https://docs.mcpwp.net/connect/claude-desktop/' ),
			array( 'keyword' => 'wordpress mcp',              'position' => 75, 'search_volume' => 720, 'keyword_difficulty' => 27, 'ranking_url' => 'https://docs.mcpwp.net/get-started/' ),
			array( 'keyword' => 'wordpress mcp server',       'position' => 79, 'search_volume' => 210, 'keyword_difficulty' => 8,  'ranking_url' => 'https://docs.mcpwp.net/get-started/' ),
			array( 'keyword' => 'mcp unlimited',              'position' => 90, 'search_volume' => 110, 'keyword_difficulty' => 8,  'ranking_url' => 'https://mcpwp.net/pricing/' ),
			array( 'keyword' => 'mcp pricing',                'position' => 91, 'search_volume' => 30,  'keyword_difficulty' => 9,  'ranking_url' => 'https://mcpwp.net/pricing/' ),
		);
	}

	public function test_young_site_still_gets_opportunities() {
		$result = Mcpwp_SEO_Opportunities::rank( $this->young_site_rows(), array(), array() );

		$this->assertNotEmpty(
			$result['opportunities'],
			'A site ranking only at positions 64-91 must still receive a ranked list.'
		);
		$this->assertSame( 'emerging', $result['mode'] );
	}

	public function test_young_site_ranks_high_volume_low_difficulty_first() {
		$result = Mcpwp_SEO_Opportunities::rank( $this->young_site_rows(), array(), array() );

		$top = $result['opportunities'][0];

		// wordpress mcp server: volume 210, difficulty 8 -> ratio 26.25
		// claude desktop mcp:   volume 590, difficulty 14 -> ratio 42.14
		// wordpress mcp:        volume 720, difficulty 27 -> ratio 26.67
		$this->assertSame( 'claude desktop mcp', $top['keyword'] );
	}

	public function test_every_opportunity_carries_a_reason() {
		$result = Mcpwp_SEO_Opportunities::rank( $this->young_site_rows(), array(), array() );

		foreach ( $result['opportunities'] as $opp ) {
			$this->assertNotEmpty( $opp['reason'] );
		}
	}

	public function test_mature_site_uses_striking_distance_as_a_multiplier() {
		$rows = array(
			// Page 2, modest volume — should beat a deep-ranked bigger term.
			array( 'keyword' => 'page two term', 'position' => 14, 'search_volume' => 200, 'keyword_difficulty' => 20, 'ranking_url' => 'https://example.com/a' ),
			array( 'keyword' => 'deep term',     'position' => 88, 'search_volume' => 300, 'keyword_difficulty' => 20, 'ranking_url' => 'https://example.com/b' ),
		);

		$result = Mcpwp_SEO_Opportunities::rank( $rows, array(), array() );

		$this->assertSame( 'mature', $result['mode'] );
		$this->assertSame( 'page two term', $result['opportunities'][0]['keyword'] );
	}

	public function test_site_with_no_rankings_falls_back_to_keyword_research() {
		$metrics = array(
			array( 'keyword' => 'new topic', 'search_volume' => 400, 'keyword_difficulty' => 10, 'cpc' => 1.0, 'competition' => 0.1, 'competition_level' => 'LOW' ),
		);

		$result = Mcpwp_SEO_Opportunities::rank( array(), $metrics, array() );

		$this->assertSame( 'greenfield', $result['mode'] );
		$this->assertSame( 'new topic', $result['opportunities'][0]['keyword'] );
	}

	public function test_nothing_to_do_is_distinct_from_nothing_computed() {
		$empty = Mcpwp_SEO_Opportunities::rank( array(), array(), array() );

		$this->assertSame( array(), $empty['opportunities'] );
		$this->assertSame( 'no_inputs', $empty['reason'] );
	}

	public function test_zero_difficulty_does_not_divide_by_zero() {
		$rows = array(
			array( 'keyword' => 'unguarded', 'position' => 50, 'search_volume' => 100, 'keyword_difficulty' => 0, 'ranking_url' => 'https://example.com/x' ),
		);

		$result = Mcpwp_SEO_Opportunities::rank( $rows, array(), array() );

		$this->assertNotEmpty( $result['opportunities'] );
		$this->assertIsFloat( $result['opportunities'][0]['score'] );
	}
}
```

Add to `mcpwp/tests/phpunit.xml`:

```xml
      <file>./unit/test-seo-opportunities.php</file>
```

Append to `mcpwp/tests/bootstrap.php`:

```php
require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-opportunities.php';
```

- [ ] **Step 2: Run it and confirm it fails**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter SeoOpportunitiesTest'
```

Expected: FAIL — `Class "Mcpwp_SEO_Opportunities" not found`.

- [ ] **Step 3: Implement the ranker**

Create `mcpwp/includes/core/class-mcpwp-seo-opportunities.php`:

```php
<?php
/**
 * Ranks SEO work by fusing visibility, market metrics and audit issues.
 *
 * The signal adapts to how mature the site is. An earlier design used striking
 * distance (positions 11-20) as the entry condition; tested against a real
 * domain it returned nothing, because that site ranked only at positions 64-91.
 * Most installs are new or thin sites, so they are the primary case and
 * striking distance is a multiplier, never a gate.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Opportunity ranker. Pure functions — no I/O.
 */
class Mcpwp_SEO_Opportunities {

	/**
	 * Below this position a site is treated as still emerging.
	 */
	const STRIKING_DISTANCE_MAX = 20;

	/**
	 * Score multiplier applied to keywords already within striking distance.
	 */
	const STRIKING_DISTANCE_BOOST = 3.0;

	/**
	 * Substituted for a zero difficulty so the ratio stays finite.
	 */
	const MIN_DIFFICULTY = 1;

	const MAX_OPPORTUNITIES = 20;

	/**
	 * Rank the work worth doing.
	 *
	 * @param array $visibility_rows Rows from Mcpwp_SEO_Visibility.
	 * @param array $keyword_metrics Rows from keyword enrichment.
	 * @param array $audit_issues    Open issues from Mcpwp_SEO_Audit_Store.
	 * @return array{mode:string,opportunities:array,reason:string|null}
	 */
	public static function rank( array $visibility_rows, array $keyword_metrics, array $audit_issues ) {
		if ( empty( $visibility_rows ) && empty( $keyword_metrics ) ) {
			return array(
				'mode'          => 'unknown',
				'opportunities' => array(),
				'reason'        => 'no_inputs',
			);
		}

		if ( empty( $visibility_rows ) ) {
			return array(
				'mode'          => 'greenfield',
				'opportunities' => self::from_keyword_metrics( $keyword_metrics ),
				'reason'        => null,
			);
		}

		$mode = self::detect_mode( $visibility_rows );

		return array(
			'mode'          => $mode,
			'opportunities' => self::from_visibility( $visibility_rows ),
			'reason'        => null,
		);
	}

	/**
	 * Classify the site by how well it already ranks.
	 *
	 * @param array $rows Visibility rows.
	 * @return string 'mature' or 'emerging'.
	 */
	private static function detect_mode( array $rows ) {
		foreach ( $rows as $row ) {
			$position = isset( $row['position'] ) ? (int) $row['position'] : 0;
			if ( $position > 0 && $position <= self::STRIKING_DISTANCE_MAX ) {
				return 'mature';
			}
		}

		return 'emerging';
	}

	/**
	 * Score keywords the site already ranks for.
	 *
	 * @param array $rows Visibility rows.
	 * @return array Ranked opportunities.
	 */
	private static function from_visibility( array $rows ) {
		$scored = array();

		foreach ( $rows as $row ) {
			$keyword = isset( $row['keyword'] ) ? (string) $row['keyword'] : '';
			if ( '' === $keyword ) {
				continue;
			}

			$volume     = isset( $row['search_volume'] ) ? (int) $row['search_volume'] : 0;
			$difficulty = isset( $row['keyword_difficulty'] ) ? (int) $row['keyword_difficulty'] : 0;
			$position   = isset( $row['position'] ) ? (int) $row['position'] : 0;

			$score = $volume / max( self::MIN_DIFFICULTY, $difficulty );

			$striking = ( $position > 0 && $position <= self::STRIKING_DISTANCE_MAX );
			if ( $striking ) {
				$score *= self::STRIKING_DISTANCE_BOOST;
			}

			$scored[] = array(
				'keyword'            => $keyword,
				'position'           => $position,
				'search_volume'      => $volume,
				'keyword_difficulty' => $difficulty,
				'ranking_url'        => isset( $row['ranking_url'] ) ? (string) $row['ranking_url'] : '',
				'score'              => (float) $score,
				'reason'             => $striking
					? sprintf(
						/* translators: 1: current position, 2: monthly search volume. */
						__( 'Ranks %1$d for %2$d monthly searches — close enough to reach page one.', 'mcpwp' ),
						$position,
						$volume
					)
					: sprintf(
						/* translators: 1: monthly search volume, 2: difficulty score, 3: current position. */
						__( '%1$d monthly searches at difficulty %2$d, currently ranking %3$d — winnable with focused work.', 'mcpwp' ),
						$volume,
						$difficulty,
						$position
					),
			);
		}

		return self::sort_and_cap( $scored );
	}

	/**
	 * Score keywords for a site that ranks for nothing yet.
	 *
	 * @param array $metrics Keyword metric rows.
	 * @return array Ranked opportunities.
	 */
	private static function from_keyword_metrics( array $metrics ) {
		$scored = array();

		foreach ( $metrics as $row ) {
			$keyword = isset( $row['keyword'] ) ? (string) $row['keyword'] : '';
			if ( '' === $keyword ) {
				continue;
			}

			$volume     = isset( $row['search_volume'] ) ? (int) $row['search_volume'] : 0;
			$difficulty = isset( $row['keyword_difficulty'] ) ? (int) $row['keyword_difficulty'] : 0;

			$scored[] = array(
				'keyword'            => $keyword,
				'position'           => 0,
				'search_volume'      => $volume,
				'keyword_difficulty' => $difficulty,
				'ranking_url'        => '',
				'score'              => (float) ( $volume / max( self::MIN_DIFFICULTY, $difficulty ) ),
				'reason'             => sprintf(
					/* translators: 1: monthly search volume, 2: difficulty score. */
					__( 'Not ranking yet. %1$d monthly searches at difficulty %2$d — worth targeting with new content.', 'mcpwp' ),
					$volume,
					$difficulty
				),
			);
		}

		return self::sort_and_cap( $scored );
	}

	/**
	 * Sort by score descending and cap the list.
	 *
	 * @param array $scored Scored opportunities.
	 * @return array
	 */
	private static function sort_and_cap( array $scored ) {
		usort(
			$scored,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return 0;
				}
				return ( $a['score'] < $b['score'] ) ? 1 : -1;
			}
		);

		return array_slice( $scored, 0, self::MAX_OPPORTUNITIES );
	}
}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter SeoOpportunitiesTest'
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add mcpwp/includes/core/class-mcpwp-seo-opportunities.php mcpwp/tests/unit/test-seo-opportunities.php mcpwp/tests/phpunit.xml mcpwp/tests/bootstrap.php
git commit -m "feat(seo): maturity-aware opportunity ranker

Striking distance is a multiplier, not a gate — a site ranking only at
positions 64-91 must still receive a ranked list."
```

---

### Task 6: Response caching

**Files:**
- Create: `mcpwp/includes/core/class-mcpwp-seo-cache.php`
- Create: `mcpwp/tests/unit/test-seo-cache.php`
- Modify: `mcpwp/tests/phpunit.xml`, `mcpwp/tests/bootstrap.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `Mcpwp_SEO_Cache::remember( string $key, callable $fetch )` — returns cached value or calls `$fetch`.
  - `Mcpwp_SEO_Cache::TTL_OK` = `DAY_IN_SECONDS`, `Mcpwp_SEO_Cache::TTL_FAIL` = `5 * MINUTE_IN_SECONDS`.

- [ ] **Step 1: Write the failing test**

Create `mcpwp/tests/unit/test-seo-cache.php`:

```php
<?php

use PHPUnit\Framework\TestCase;

class SeoCacheTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['mcpwp_test_transients'] = array();
	}

	public function test_second_call_does_not_refetch() {
		$calls = 0;
		$fetch = function () use ( &$calls ) {
			$calls++;
			return array( 'value' => 'x' );
		};

		Mcpwp_SEO_Cache::remember( 'seed|en', $fetch );
		Mcpwp_SEO_Cache::remember( 'seed|en', $fetch );

		// Every uncached call spends the customer's credit.
		$this->assertSame( 1, $calls );
	}

	public function test_different_keys_fetch_separately() {
		$calls = 0;
		$fetch = function () use ( &$calls ) {
			$calls++;
			return array( 'value' => 'x' );
		};

		Mcpwp_SEO_Cache::remember( 'seed|en', $fetch );
		Mcpwp_SEO_Cache::remember( 'seed|de', $fetch );

		$this->assertSame( 2, $calls );
	}

	public function test_failures_are_cached_briefly_so_a_bad_key_cannot_hot_loop() {
		$fetch = function () {
			return new WP_Error( 'auth_rejected', 'nope' );
		};

		Mcpwp_SEO_Cache::remember( 'seed|en', $fetch );

		$entry = $GLOBALS['mcpwp_test_transients'][ Mcpwp_SEO_Cache::PREFIX . md5( 'seed|en' ) ];

		$this->assertSame( Mcpwp_SEO_Cache::TTL_FAIL, $entry['ttl'] );
		$this->assertLessThan( Mcpwp_SEO_Cache::TTL_OK, $entry['ttl'] );
	}

	public function test_cached_error_is_returned_as_an_error_not_as_empty_data() {
		$fetch = function () {
			return new WP_Error( 'insufficient_credits', 'no credit' );
		};

		Mcpwp_SEO_Cache::remember( 'seed|en', $fetch );
		$second = Mcpwp_SEO_Cache::remember( 'seed|en', $fetch );

		$this->assertInstanceOf( 'WP_Error', $second );
		$this->assertSame( 'insufficient_credits', $second->get_error_code() );
	}
}
```

Add to `mcpwp/tests/phpunit.xml`:

```xml
      <file>./unit/test-seo-cache.php</file>
```

**Modify the existing `set_transient` stub** at `mcpwp/tests/bootstrap.php:396`. It currently records only `value` and `expires` (an absolute timestamp), so a test cannot read back the TTL that was requested. Add one key — this is additive and no existing test reads `ttl`:

```php
function set_transient($name, $value, $expiration)
{
    $GLOBALS['mcpwp_test_transients'][ $name ] = array(
        'value'   => $value,
        'expires' => time() + (int) $expiration,
        // Requested TTL, kept verbatim. 'expires' cannot be compared reliably
        // when two writes land in the same second.
        'ttl'     => (int) $expiration,
    );
    return true;
}
```

> **Do NOT add a second `function set_transient`.** PHP binds function declarations at first load, so a competing definition is a fatal — edit the existing one in place.

Then append to `mcpwp/tests/bootstrap.php`:

```php
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

require_once dirname(__DIR__) . '/includes/core/class-mcpwp-seo-cache.php';
```

> **Check first:** if `MINUTE_IN_SECONDS`/`DAY_IN_SECONDS` are already defined earlier in `bootstrap.php`, skip those two lines rather than duplicating them.

- [ ] **Step 2: Run it and confirm it fails**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter SeoCacheTest'
```

Expected: FAIL — `Class "Mcpwp_SEO_Cache" not found`.

- [ ] **Step 3: Implement the cache**

Create `mcpwp/includes/core/class-mcpwp-seo-cache.php`:

```php
<?php
/**
 * Cache for paid SEO lookups.
 *
 * No single DataForSEO call is expensive ($0.012/request + $0.00012/result).
 * The risk is an agent loop making thousands of cheap ones, so failures are
 * cached too — a rejected credential must not be retried on every request.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transient-backed lookup cache.
 */
class Mcpwp_SEO_Cache {

	const PREFIX   = 'mcpwp_seo_';
	const TTL_OK   = DAY_IN_SECONDS;
	const TTL_FAIL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Return a cached value, or fetch and cache it.
	 *
	 * Errors are cached under a shorter TTL and returned as errors, so a
	 * failure never degrades into an empty-looking success.
	 *
	 * @param string   $key   Cache key, e.g. "seed|en|2840".
	 * @param callable $fetch Producer returning array|WP_Error.
	 * @return array|WP_Error
	 */
	public static function remember( $key, $fetch ) {
		$transient_key = self::PREFIX . md5( (string) $key );

		$cached = get_transient( $transient_key );
		if ( is_array( $cached ) && isset( $cached['is_error'] ) ) {
			return $cached['is_error']
				? new WP_Error( $cached['code'], $cached['message'] )
				: $cached['data'];
		}

		$result = call_user_func( $fetch );

		if ( is_wp_error( $result ) ) {
			set_transient(
				$transient_key,
				array(
					'is_error' => true,
					'code'     => $result->get_error_code(),
					'message'  => $result->get_error_message(),
				),
				self::TTL_FAIL
			);

			return $result;
		}

		set_transient(
			$transient_key,
			array(
				'is_error' => false,
				'data'     => $result,
			),
			self::TTL_OK
		);

		return $result;
	}
}
```

- [ ] **Step 4: Run the tests**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml --filter SeoCacheTest'
```

Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add mcpwp/includes/core/class-mcpwp-seo-cache.php mcpwp/tests/unit/test-seo-cache.php mcpwp/tests/phpunit.xml mcpwp/tests/bootstrap.php
git commit -m "feat(seo): cache paid lookups, including failures, to bound agent loops"
```

---

### Task 7: REST + MCP surfaces and agent playbook

**Files:**
- Create: `mcpwp/includes/api/class-mcpwp-rest-seo-market.php`
- Modify: `mcpwp/includes/class-mcpwp-loader.php`
- Modify: `mcpwp/mcpwp.php`
- Modify: `mcpwp/includes/mcp/class-mcpwp-mcp-pro-tools.php`
- Modify: `mcpwp/includes/core/class-mcpwp-agent-playbooks.php`

**Interfaces:**
- Consumes: Tasks 1, 3, 4, 5, 6.
- Produces: `GET /seo/visibility`, `POST /seo/visibility/refresh`, `GET /seo/opportunities`; MCP tools `wp_seo_visibility`, `wp_refresh_seo_visibility`, `wp_seo_opportunities`; playbook `improve_search_visibility`.

- [ ] **Step 1: Create the controller**

Create `mcpwp/includes/api/class-mcpwp-rest-seo-market.php`:

```php
<?php
/**
 * REST surface for SEO market data — visibility and opportunities.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visibility and opportunity endpoints.
 */
class Mcpwp_REST_SEO_Market extends Mcpwp_REST_API {

	/**
	 * Cache key for the visibility fetch.
	 */
	const REFRESH_LOCK = 'mcpwp_seo_visibility_refresh';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/seo/visibility',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_visibility' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/seo/visibility/refresh',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'refresh_visibility' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/seo/opportunities',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_opportunities' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Return the stored visibility snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_visibility( $request ) {
		$this->log_activity( 'get_seo_visibility', $request );

		return $this->success_response( Mcpwp_SEO_Visibility::get_latest() );
	}

	/**
	 * Fetch a fresh snapshot from DataForSEO.
	 *
	 * Explicit action only — nothing in this feature runs on a timer, so an
	 * agent loop cannot quietly spend the customer's credit overnight.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function refresh_visibility( $request ) {
		$this->log_activity( 'refresh_seo_visibility', $request );

		$provider = $this->get_dataforseo_provider();
		if ( null === $provider ) {
			return $this->error_response(
				'not_configured',
				Mcpwp_Provider_Dataforseo::describe_reason( 'not_configured' ),
				400
			);
		}

		$target = wp_parse_url( home_url(), PHP_URL_HOST );
		$rows   = $provider->ranked_keywords( $target );

		if ( is_wp_error( $rows ) ) {
			return $this->error_response( $rows->get_error_code(), $rows->get_error_message(), 502 );
		}

		$stored = Mcpwp_SEO_Visibility::store_snapshot( $rows, $target );

		return $this->success_response( $stored );
	}

	/**
	 * Return the ranked opportunity list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_opportunities( $request ) {
		$this->log_activity( 'get_seo_opportunities', $request );

		$visibility = Mcpwp_SEO_Visibility::get_latest();

		$issues = class_exists( 'Mcpwp_SEO_Audit_Store' )
			? Mcpwp_SEO_Audit_Store::list_issues( array( 'status' => 'open' ) )
			: array();

		// A site that ranks for nothing has no visibility rows to score. Without
		// a seed there is genuinely nothing to rank, and the ranker says so with
		// reason 'no_inputs' rather than returning a misleading empty list.
		$metrics = array();
		$seed    = (string) $request->get_param( 'seed' );

		if ( empty( $visibility['rows'] ) && '' !== $seed ) {
			$provider = $this->get_dataforseo_provider();

			if ( null !== $provider ) {
				$fetched = Mcpwp_SEO_Cache::remember(
					'suggestions|' . $seed,
					function () use ( $provider, $seed ) {
						return $provider->keyword_suggestions( $seed );
					}
				);

				if ( is_wp_error( $fetched ) ) {
					return $this->error_response( $fetched->get_error_code(), $fetched->get_error_message(), 502 );
				}

				$metrics = $fetched;
			}
		}

		$result = Mcpwp_SEO_Opportunities::rank( $visibility['rows'], $metrics, $issues );

		return $this->success_response( $result );
	}

	/**
	 * Build a configured provider, or null when credentials are absent.
	 *
	 * @return Mcpwp_Provider_Dataforseo|null
	 */
	private function get_dataforseo_provider() {
		if ( ! class_exists( 'Mcpwp_Integration_Manager' ) || ! class_exists( 'Mcpwp_Provider_Dataforseo' ) ) {
			return null;
		}

		$config = Mcpwp_Integration_Manager::get_instance()->get_provider_config( 'dataforseo' );

		if ( empty( $config['login'] ) || empty( $config['password'] ) ) {
			return null;
		}

		return new Mcpwp_Provider_Dataforseo( $config['login'], $config['password'] );
	}
}
```

- [ ] **Step 2: Load and register the controller**

In `mcpwp/mcpwp.php`, alongside the other REST controller requires:

```php
	require_once MCPWP_PLUGIN_DIR . 'includes/api/class-mcpwp-rest-seo-market.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-seo-visibility.php';
	require_once MCPWP_PLUGIN_DIR . 'includes/core/class-mcpwp-seo-opportunities.php';
```

In `mcpwp/includes/class-mcpwp-loader.php`, where the other REST controllers are instantiated, add:

```php
		$seo_market = new Mcpwp_REST_SEO_Market();
		$seo_market->register_routes();
```

- [ ] **Step 3: Verify the routes exist**

```bash
docker exec wp-test-wordpress-1 wp eval 'print_r( array_values( array_filter( array_keys( rest_get_server()->get_routes() ), function( $r ) { return false !== strpos( $r, "seo/visibility" ) || false !== strpos( $r, "seo/opportunities" ); } ) ) );' --allow-root
```

Expected: the three routes listed.

- [ ] **Step 4: Run the full suite**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml'
```

Expected: PASS, no regressions.

- [ ] **Step 5: Expose the MCP tools**

In `mcpwp/includes/mcp/class-mcpwp-mcp-pro-tools.php`, inside `get_tool_map()` alongside the other SEO entries:

```php
			'wp_seo_visibility'         => array(
				'method' => 'GET',
				'route'  => '/seo/visibility',
			),
			'wp_refresh_seo_visibility' => array(
				'method' => 'POST',
				'route'  => '/seo/visibility/refresh',
			),
			'wp_seo_opportunities'      => array(
				'method' => 'GET',
				'route'  => '/seo/opportunities',
			),
```

- [ ] **Step 6: Add the agent playbook**

In `mcpwp/includes/core/class-mcpwp-agent-playbooks.php`, inside `definitions()` alongside `build_gutenberg_page`:

```php
			'improve_search_visibility' => array(
				'goal'             => __( 'Find and act on the highest-value search opportunities for this site.', 'mcpwp' ),
				'required_tools'   => array( 'wp_get_site_state', 'wp_get_site_context', 'wp_seo_opportunities', 'wp_seo_visibility', 'wp_keyword_research', 'wp_validate_seo_readiness', 'wp_list_approvals' ),
				'steps'            => array(
					__( 'Read wp_get_site_state and wp_get_site_context before proposing anything.', 'mcpwp' ),
					__( 'Call wp_seo_opportunities. Use its mode field: greenfield means the site ranks for nothing yet and needs new content; emerging means push existing pages; mature means nudge page-two terms.', 'mcpwp' ),
					__( 'Pick opportunities whose reason you can act on. Each carries the evidence that produced it.', 'mcpwp' ),
					__( 'Edit or create content through the normal approval-gated content tools.', 'mcpwp' ),
					__( 'Validate with wp_validate_seo_readiness before requesting approval.', 'mcpwp' ),
					__( 'Re-run wp_refresh_seo_visibility only after changes are live — rankings do not move instantly.', 'mcpwp' ),
				),
				'validation_gates' => array( 'wp_validate_seo_readiness', 'wp_audit_content_quality' ),
				'approval_gates'   => array( 'content_update', 'publish_status_change', 'seo_metadata_change' ),
				'rollback'         => array( 'wp_list_approvals', 'wp_rollback_approval' ),
				'stop_conditions'  => array( 'missing_site_context', 'dataforseo_not_configured', 'insufficient_credits', 'human_rejects_approval' ),
			),
```

- [ ] **Step 7: Verify the routes and tools exist**

```bash
docker exec wp-test-wordpress-1 wp eval 'print_r( array_values( array_filter( array_keys( rest_get_server()->get_routes() ), function( $r ) { return false !== strpos( $r, "seo/visibility" ) || false !== strpos( $r, "seo/opportunities" ); } ) ) );' --allow-root
```

Expected: the three routes listed.

- [ ] **Step 8: Run the full suite**

```bash
docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml'
```

Expected: PASS, no regressions.

- [ ] **Step 9: Commit**

```bash
git add mcpwp/includes/api/class-mcpwp-rest-seo-market.php mcpwp/includes/class-mcpwp-loader.php mcpwp/mcpwp.php mcpwp/includes/mcp/class-mcpwp-mcp-pro-tools.php mcpwp/includes/core/class-mcpwp-agent-playbooks.php
git commit -m "feat(seo): REST + MCP surfaces and improve_search_visibility playbook"
```

---

### Task 8: Control Room rendering

**Files:**
- Modify: `mcpwp/includes/admin/traits/trait-mcpwp-admin-control-room.php:176-240`
- Modify: `mcpwp/admin/partials/mcpwp-control-room-display.php:253`

**Interfaces:**
- Consumes: `Mcpwp_SEO_Visibility::get_latest()` (Task 3), `Mcpwp_SEO_Opportunities::rank()` (Task 5).
- Produces: `$control_room['seo_visibility']` and `$control_room['seo_opportunities']` for the partial.

- [ ] **Step 1: Add the data to the Control Room payload**

In `get_control_room_data()`, alongside the existing `'seo_summary'` and `'open_seo_issues'` keys:

```php
			'seo_visibility'    => class_exists( 'Mcpwp_SEO_Visibility' )
				? Mcpwp_SEO_Visibility::get_latest()
				: array( 'rows' => array(), 'reason' => 'unavailable' ),
			'seo_opportunities' => class_exists( 'Mcpwp_SEO_Opportunities' ) && class_exists( 'Mcpwp_SEO_Visibility' )
				? Mcpwp_SEO_Opportunities::rank(
					Mcpwp_SEO_Visibility::get_latest()['rows'],
					array(),
					isset( $seo_open['issues'] ) && is_array( $seo_open['issues'] ) ? $seo_open['issues'] : array()
				)
				: array( 'mode' => 'unknown', 'opportunities' => array(), 'reason' => 'unavailable' ),
```

- [ ] **Step 2: Render them in the SEO card**

In `mcpwp/admin/partials/mcpwp-control-room-display.php`, near the top with the other extractions:

```php
$seo_visibility    = isset( $control_room['seo_visibility'] ) && is_array( $control_room['seo_visibility'] ) ? $control_room['seo_visibility'] : array();
$seo_opportunities = isset( $control_room['seo_opportunities'] ) && is_array( $control_room['seo_opportunities'] ) ? $control_room['seo_opportunities'] : array();
```

Then inside the `#mcpwp-section-seo` card, after the existing audit filters block:

```php
			<h3><?php esc_html_e( 'Search opportunities', 'mcpwp' ); ?></h3>
			<?php if ( ! empty( $seo_opportunities['opportunities'] ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Keyword', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Position', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Volume', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Difficulty', 'mcpwp' ); ?></th>
							<th><?php esc_html_e( 'Why', 'mcpwp' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $seo_opportunities['opportunities'] as $opp ) : ?>
						<tr>
							<td><?php echo esc_html( $opp['keyword'] ); ?></td>
							<td><?php echo $opp['position'] > 0 ? esc_html( (string) $opp['position'] ) : '&mdash;'; ?></td>
							<td><?php echo esc_html( (string) $opp['search_volume'] ); ?></td>
							<td><?php echo esc_html( (string) $opp['keyword_difficulty'] ); ?></td>
							<td><?php echo esc_html( $opp['reason'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p>
					<?php
					// "Not configured" and "nothing to do" are different answers
					// and must not render as the same empty table.
					$reason = isset( $seo_opportunities['reason'] ) ? $seo_opportunities['reason'] : '';
					if ( 'no_inputs' === $reason ) {
						esc_html_e( 'No search data yet. Connect DataForSEO under Integrations, then refresh visibility.', 'mcpwp' );
					} elseif ( 'unavailable' === $reason ) {
						esc_html_e( 'Search opportunity analysis is not available in this build.', 'mcpwp' );
					} else {
						esc_html_e( 'No opportunities outstanding.', 'mcpwp' );
					}
					?>
				</p>
			<?php endif; ?>
```

- [ ] **Step 3: Verify it renders**

Load `http://localhost:8080/wp-admin/admin.php?page=mcpwp-control-room` and confirm the SEO tab shows the "Search opportunities" heading with either a table or a specific message — never a bare empty table.

- [ ] **Step 4: Check for PHP notices**

```bash
docker exec wp-test-wordpress-1 wp eval 'echo "ok\n";' --allow-root
docker logs wp-test-wordpress-1 --tail 30 2>&1 | grep -iE "notice|warning|fatal" || echo "no PHP notices"
```

Expected: `no PHP notices`.

- [ ] **Step 5: Commit**

```bash
git add mcpwp/includes/admin/traits/trait-mcpwp-admin-control-room.php mcpwp/admin/partials/mcpwp-control-room-display.php
git commit -m "feat(seo): render search opportunities in Control Room"
```

---

### Task 9: Live verification against mcpwp.net

**Files:**
- Modify: `CLAUDE.md`

**Interfaces:**
- Consumes: everything above.
- Produces: documentation, and proof the feature works against the real API.

- [ ] **Step 1: Configure credentials on the local site**

```bash
docker exec wp-test-wordpress-1 wp eval '
$m = Mcpwp_Integration_Manager::get_instance();
$m->set_provider_config( "dataforseo", array( "login" => getenv("DFS_LOGIN"), "password" => getenv("DFS_PASS") ) );
print_r( $m->test_provider( "dataforseo" ) );
' --allow-root
```

Expected: `success => 1` and a message naming the remaining credit.

- [ ] **Step 2: Confirm an unconfigured site degrades rather than breaking**

```bash
docker exec wp-test-wordpress-1 wp eval '
Mcpwp_Integration_Manager::get_instance()->remove_provider_key( "dataforseo" );
$r = Mcpwp_Keyword_Research::enrich( Mcpwp_Keyword_Research::group( "test", array( "test one" ) ), null );
echo $r["enrichment_reason"] . "\n";
' --allow-root
```

Expected: `not_configured` — and no fatal.

- [ ] **Step 3: Verify the ranker against real data**

Re-add credentials, then:

```bash
docker exec wp-test-wordpress-1 wp eval '
$c = Mcpwp_Integration_Manager::get_instance()->get_provider_config( "dataforseo" );
$p = new Mcpwp_Provider_Dataforseo( $c["login"], $c["password"] );
$rows = $p->ranked_keywords( "mcpwp.net" );
Mcpwp_SEO_Visibility::store_snapshot( $rows, "mcpwp.net" );
$o = Mcpwp_SEO_Opportunities::rank( Mcpwp_SEO_Visibility::get_latest()["rows"], array(), array() );
echo "mode: " . $o["mode"] . "\n";
foreach ( array_slice( $o["opportunities"], 0, 3 ) as $x ) { echo $x["keyword"] . " — " . $x["reason"] . "\n"; }
' --allow-root
```

Expected: `mode: emerging`, and a non-empty list. **An empty list here is a failure** — that is the exact regression this ranker exists to prevent.

- [ ] **Step 4: Document the feature**

Add to `CLAUDE.md` under the SEO section:

```markdown
### SEO Market Data (DataForSEO)

Two data classes, deliberately in separate stores:

- **Visibility** (`Mcpwp_SEO_Visibility`) — where the site ranks in the market. Position, volume,
  difficulty. From DataForSEO.
- **Performance** (`Mcpwp_Search_Performance`) — traffic actually received. Clicks, impressions,
  CTR. Search Console only; still import-only until the broker OAuth work lands.

They are not interchangeable. DataForSEO cannot supply clicks or impressions, and `position` means
an impression-weighted average in Search Console but a scraped SERP rank in DataForSEO. Merging
them produces a number nobody can interpret.

`Mcpwp_SEO_Opportunities::rank()` adapts its signal to site maturity — `greenfield` (no rankings),
`emerging` (ranks, but deep), `mature` (page-2 positions). Striking distance is a score multiplier,
never an entry condition: a site ranking only at positions 64-91 must still get a ranked list.

Credentials are BYO via WP Admin > Integrations > DataForSEO (login + password, HTTP Basic).
Nothing runs on a timer — every lookup is an explicit user or agent action.
```

- [ ] **Step 5: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: SEO market data — two stores, maturity-aware ranking"
```

---

## Verification checklist

- [ ] `docker exec wp-rig-wp-dev bash -c 'cd /var/www/html/wp-content/plugins/mcpwp && vendor/bin/phpunit --configuration tests/phpunit.xml'` — all green
- [ ] `bash scripts/verify_distignore_refs.sh` — no unguarded pruned-class references
- [ ] No test calls the live DataForSEO API
- [ ] A site with no credentials returns `not_configured`, never a fatal
- [ ] A site with no rankings still receives a ranked opportunity list
