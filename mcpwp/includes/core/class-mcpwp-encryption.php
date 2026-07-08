<?php
/**
 * Encryption for third-party API keys
 *
 * Uses libsodium (PHP 7.2+) for reversible encryption of external service
 * API keys. Unlike our own mumcp keys which are one-way hashed, third-party
 * keys need to be decrypted for outgoing API calls.
 *
 * @package mumcp
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sodium-based encryption helper.
 */
class Mcpwp_Encryption {

	/**
	 * Singleton instance.
	 *
	 * @var Mcpwp_Encryption|null
	 */
	private static $instance = null;

	/**
	 * Cached derived key.
	 *
	 * @var string|null
	 */
	private $key = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Mcpwp_Encryption
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Check if sodium encryption is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& function_exists( 'sodium_crypto_generichash' );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string|false Base64-encoded nonce+ciphertext, or false on failure.
	 */
	public function encrypt( $plaintext ) {
		if ( ! self::is_available() ) {
			return false;
		}

		$key = $this->get_key();
		// get_key() returns '' when key persistence failed (DB write error).
		// Fail closed rather than encrypt with an unrecoverable ephemeral key.
		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			return false;
		}

		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

		try {
			$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $key );
		} catch ( \Exception $e ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt an encrypted string.
	 *
	 * @param string $encoded Base64-encoded nonce+ciphertext from encrypt().
	 * @return string|false Plaintext or false on tamper/failure.
	 */
	public function decrypt( $encoded ) {
		if ( ! self::is_available() ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
			return false;
		}

		$nonce_length = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( strlen( $decoded ) < $nonce_length + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return false;
		}

		$nonce      = substr( $decoded, 0, $nonce_length );
		$ciphertext = substr( $decoded, $nonce_length );
		$key        = $this->get_key();

		try {
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		} catch ( \Exception $e ) {
			return false;
		}

		return $plaintext;
	}

	/**
	 * Get or generate a dedicated 32-byte encryption key.
	 *
	 * Lookup order (first match wins):
	 *   1. PHP constant MCPWP_ENCRYPTION_KEY defined in wp-config.php
	 *   2. WP option mcpwp_encryption_key (persisted on first generation)
	 *   3. Generate random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), persist as (2)
	 *
	 * Never derives from AUTH_SALT — that constant is shared across WordPress
	 * core and rotates on credential compromise, which would silently corrupt
	 * all stored provider blobs. See Mcpwp_Encryption_Migration for the
	 * non-destructive upgrade path from the old AUTH_SALT-derived key.
	 *
	 * @return string 32-byte raw binary key.
	 */
	private function get_key() {
		if ( null !== $this->key ) {
			return $this->key;
		}

		// 1. Constant in wp-config.php wins (lets operators pin the key).
		if ( defined( 'MCPWP_ENCRYPTION_KEY' ) ) {
			// Constant may be raw bytes or hex; accept either.
			$raw = MCPWP_ENCRYPTION_KEY;
			// If it is exactly the right byte length already, use it directly.
			if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $raw ) ) {
				// Treat it as a passphrase and derive the correct-length key.
				$raw = sodium_crypto_generichash( $raw, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
			}
			$this->key = $raw;
			return $this->key;
		}

		// 2. Persisted option (set on first boot or by the migration).
		$stored = get_option( 'mcpwp_encryption_key', '' );
		if ( '' !== $stored ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$raw = base64_decode( $stored, true );
			if ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) {
				$this->key = $raw;
				return $this->key;
			}
			// Corrupt/truncated option — fall through to regenerate.
		}

		// 3. Generate and persist. Non-autoloaded so it doesn't bloat every page load.
		$raw     = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encoded = base64_encode( $raw );
		$written = update_option( 'mcpwp_encryption_key', $encoded, false );

		if ( ! $written ) {
			// update_option returns false on "no change" OR "db error".
			// Since this is a freshly-generated random key it cannot already be stored,
			// so false here always means a DB write failure.
			// Read back to confirm — if it's there, a concurrent request already wrote it.
			$readback = get_option( 'mcpwp_encryption_key', '' );
			if ( '' === $readback ) {
				// Write failed and nothing is stored — do NOT cache or return this key.
				// encrypt() will receive null and return false, which callers handle.
				return ''; // Signals failure; encrypt() checks strlen.
			}
			// Another request persisted a key concurrently — use that one for consistency.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$raw = base64_decode( $readback, true );
			if ( false === $raw || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $raw ) ) {
				return ''; // Corrupt concurrent write — fail closed.
			}
		}

		$this->key = $raw;
		return $this->key;
	}

	/**
	 * Derive a key using the legacy AUTH_SALT method (v1.1.0 – v3.2.3).
	 *
	 * Used only by Mcpwp_Encryption_Migration to decrypt blobs written
	 * before this fix. Must not be called from anywhere else.
	 *
	 * @return string 32-byte raw binary key.
	 */
	public function get_legacy_auth_salt_key() {
		$salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'mcpwp-default-salt';
		return sodium_crypto_generichash( $salt, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Decrypt a blob using an explicit raw key (used by migration).
	 *
	 * @param string $encoded Base64-encoded nonce+ciphertext.
	 * @param string $key     32-byte raw binary key.
	 * @return string|false Plaintext or false on failure.
	 */
	public function decrypt_with_key( $encoded, $key ) {
		if ( ! self::is_available() ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
			return false;
		}

		$nonce_length = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if ( strlen( $decoded ) < $nonce_length + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return false;
		}

		$nonce      = substr( $decoded, 0, $nonce_length );
		$ciphertext = substr( $decoded, $nonce_length );

		try {
			$plaintext = sodium_crypto_secretbox_open( $ciphertext, $nonce, $key );
		} catch ( \Exception $e ) {
			return false;
		}

		return $plaintext;
	}
}
