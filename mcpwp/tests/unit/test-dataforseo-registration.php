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
