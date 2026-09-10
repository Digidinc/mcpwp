<?php
/**
 * Stand-ins for the public API surface of the two object-cache drop-ins the
 * feedback relay recognises (#752 F6). Only what the relay reads is modelled;
 * storage itself is the wp_cache_* double in tests/bootstrap.php.
 *
 * @package MCPWP
 */

if ( ! class_exists( 'Mcpwp_Test_Redis_Object_Cache' ) ) {
	/**
	 * Redis Object Cache (rhubarbgroup/redis-cache) drop-in surface.
	 */
	class Mcpwp_Test_Redis_Object_Cache {
		/** @var string[] Public in the real drop-in. */
		public $ignored_groups = array();

		/** @var bool */
		public $connected = true;

		/** @var Mcpwp_Test_Redis_Client */
		public $client;

		public function __construct() {
			$this->client = new Mcpwp_Test_Redis_Client();
		}

		public function redis_status() {
			return (bool) $this->connected;
		}

		public function redis_instance() {
			return $this->client;
		}

		public function fast_build_key( $key, $group = 'default' ) {
			return 'wp_1:' . $group . ':' . $key;
		}
	}

	/**
	 * The Redis client handed out by redis_instance(): EXPIRE only, applied to
	 * the bootstrap store so a test can see the TTL it set.
	 */
	class Mcpwp_Test_Redis_Client {
		/** @var array<int, array{0: string, 1: int}> */
		public $expire_calls = array();

		public function expire( $derived_key, $ttl ) {
			$this->expire_calls[] = array( (string) $derived_key, (int) $ttl );

			$parts = explode( ':', (string) $derived_key, 3 );
			if ( 3 !== count( $parts ) ) {
				return false;
			}
			$id = $parts[1] . ':' . $parts[2];
			if ( ! isset( $GLOBALS['mcpwp_test_object_cache'][ $id ] ) ) {
				return false;
			}
			$GLOBALS['mcpwp_test_object_cache'][ $id ]['ttl']     = (int) $ttl;
			$GLOBALS['mcpwp_test_object_cache'][ $id ]['expires'] = time() + (int) $ttl;
			return true;
		}
	}
}

if ( ! class_exists( 'Mcpwp_Test_Memcached_Object_Cache' ) ) {
	/**
	 * Automattic memcached (Automattic/wp-memcached) drop-in surface.
	 */
	class Mcpwp_Test_Memcached_Object_Cache {
		/** @var string[] Public (`var`) in the real drop-in. */
		public $no_mc_groups = array();

		/** @var array<int, array{host: string, port: int}> Public (`var`) in the real drop-in. */
		public $connection_errors = array();

		public function get_mc( $group ) {
			return null;
		}
	}
}
