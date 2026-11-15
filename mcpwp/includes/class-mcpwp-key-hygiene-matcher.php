<?php
/**
 * The API key matcher, for the key hygiene sweep.
 *
 * Exposes Mcpwp_Api_Auth::is_api_key_hash_match() — the exact check every
 * authentication path uses — so the sweep judges a record the way a request
 * would, and cannot drift from it.
 *
 * @package MCPWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! trait_exists( 'Mcpwp_Api_Auth' ) ) {
	require_once __DIR__ . '/traits/trait-mcpwp-api-auth.php';
}

final class Mcpwp_Key_Hygiene_Matcher {
	use Mcpwp_Api_Auth;

	/**
	 * Whether a presented key verifies against a stored hash.
	 *
	 * @param string $candidate Presented key.
	 * @param string $hash      Stored hash.
	 * @return bool
	 */
	public function matches( $candidate, $hash ) {
		return (bool) $this->is_api_key_hash_match( $candidate, $hash );
	}
}
