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
