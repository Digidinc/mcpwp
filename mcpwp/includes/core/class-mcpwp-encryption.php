<?php
/**
 * Encryption for third-party API keys
 *
 * Uses libsodium (PHP 7.2+) for reversible encryption of external service
 * API keys. Unlike our own MCPWP keys which are one-way hashed, third-party
 * keys need to be decrypted for outgoing API calls.
 *
 * KEY INTEGRITY (#564)
 *   - The key row in the options table is the only authority. It is read
 *     straight from the table, never through get_option(), so a stale
 *     `notoptions` cache or a failed read can never look like "no key".
 *   - A read that fails is a failure (REASON_KEY_UNAVAILABLE), not absence.
 *   - A row that exists but does not hold a valid key fails closed
 *     (REASON_KEY_CORRUPT). It is never regenerated or overwritten.
 *   - A new key is created only by encrypt(), only when the row is absent,
 *     and only through a plain INSERT that cannot overwrite. The row is then
 *     re-read and whatever is stored is used, so concurrent first writers
 *     converge on one key.
 *   - decrypt() never creates a key.
 *   - A pinned MCPWP_ENCRYPTION_KEY that is empty, blank, shorter than
 *     PINNED_KEY_MIN_BYTES once trimmed, or one repeated byte would derive a
 *     key anyone can compute. It fails closed (REASON_PINNED_KEY_WEAK) for
 *     encrypt and decrypt alike, with no fallback to the option key.
 *
 * BLOB FORMATS
 *   - v2 (written since 3.11.1): "v2:" . base64( version(1) || key id(4) ||
 *     nonce(24) || secretbox ciphertext ). The ':' is outside the base64
 *     alphabet, so a v2 blob can never parse as a legacy blob, and any other
 *     "vN:" prefix is reported as an unsupported version rather than corrupt.
 *     The key id is the first 4 bytes of a keyed BLAKE2b of a fixed context
 *     string under the key: a routing hint only. The header is not
 *     authenticated; the ciphertext is (Poly1305), so a changed header can at
 *     worst turn "tampered" into "wrong key", never yield wrong plaintext.
 *   - legacy (1.1.0 – 3.11.0): base64( nonce(24) || ciphertext ). No key id,
 *     so wrong key and tampering cannot be told apart (REASON_UNDECRYPTABLE).
 *
 * Callers read the reason for a false return from get_last_error(). Reasons
 * that mean stored secrets are unusable are also recorded in the
 * mcpwp_encryption_alerts option and shown to administrators.
 *
 * @package MCPWP
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
	 * Option holding the base64 dedicated key.
	 */
	const KEY_OPTION = 'mcpwp_encryption_key';

	/**
	 * Option holding recorded key/blob alerts for the admin notice.
	 */
	const ALERTS_OPTION = 'mcpwp_encryption_alerts';

	/**
	 * Versioned blob prefix and version byte.
	 */
	const BLOB_PREFIX  = 'v2:';
	const BLOB_VERSION = 2;

	/**
	 * Key id length and the context string it is derived from.
	 */
	const FINGERPRINT_BYTES   = 4;
	const FINGERPRINT_CONTEXT = 'mcpwp-encryption-key-id-v2';

	/**
	 * Failure reasons (get_last_error()).
	 */
	const REASON_SODIUM_UNAVAILABLE  = 'sodium_unavailable';
	const REASON_KEY_UNAVAILABLE     = 'key_unavailable';
	const REASON_KEY_CORRUPT         = 'key_corrupt';
	const REASON_KEY_MISSING         = 'key_missing';
	const REASON_KEY_WRITE_FAILED    = 'key_write_failed';
	const REASON_MALFORMED           = 'malformed';
	const REASON_UNSUPPORTED_VERSION = 'unsupported_version';
	const REASON_WRONG_KEY           = 'wrong_key';
	const REASON_TAMPERED            = 'tampered';
	const REASON_UNDECRYPTABLE       = 'undecryptable';
	const REASON_ENCRYPT_FAILED      = 'encrypt_failed';
	const REASON_PINNED_KEY_WEAK     = 'pinned_key_weak';

	/**
	 * Shortest pinned constant accepted, in bytes after trimming whitespace.
	 */
	const PINNED_KEY_MIN_BYTES = 16;

	/**
	 * Warning (get_last_warnings()): the value decrypted, but only with the
	 * option key while MCPWP_ENCRYPTION_KEY is defined.
	 */
	const WARNING_PINNED_KEY_DIFFERS = 'pinned_key_differs';

	/**
	 * Alert bookkeeping: at most one write per code per interval, and alerts
	 * not seen again within the TTL stop showing.
	 */
	const ALERT_WRITE_INTERVAL = 300;
	const ALERT_TTL            = 259200;

	/**
	 * Option-row read states (read_option_row()).
	 */
	const ROW_PRESENT = 'present';
	const ROW_ABSENT  = 'absent';
	const ROW_ERROR   = 'error';

	/**
	 * Singleton instance.
	 *
	 * @var Mcpwp_Encryption|null
	 */
	private static $instance = null;

	/**
	 * Cached primary key (pinned constant, else option key).
	 *
	 * @var string|null
	 */
	private $key = null;

	/**
	 * Cached option key, used as the fallback when a constant is pinned.
	 *
	 * @var string|null
	 */
	private $option_key = null;

	/**
	 * Reason for the last false return, '' after a success.
	 *
	 * @var string
	 */
	private $last_error = '';

	/**
	 * Warnings raised by the last call.
	 *
	 * @var string[]
	 */
	private $last_warnings = array();

	/**
	 * Alert codes already recorded by this instance.
	 *
	 * @var array<string, bool>
	 */
	private $alerted = array();

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
	 * Reason for the last false return from encrypt()/decrypt().
	 *
	 * @return string One of the REASON_* constants, or '' after a success.
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * Warnings raised by the last encrypt()/decrypt() call.
	 *
	 * @return string[]
	 */
	public function get_last_warnings() {
		return $this->last_warnings;
	}

	/**
	 * Whether a stored value is a versioned blob (any "vN:" prefix).
	 *
	 * @param mixed $encoded Stored value.
	 * @return bool
	 */
	public static function is_versioned_blob( $encoded ) {
		return is_string( $encoded ) && 1 === preg_match( '/^v\d+:/', $encoded );
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string|false v2 blob, or false (reason in get_last_error()).
	 */
	public function encrypt( $plaintext ) {
		$this->begin();

		if ( ! self::is_available() ) {
			return $this->fail( self::REASON_SODIUM_UNAVAILABLE );
		}

		$resolved = $this->resolve_primary_key( true );
		if ( '' !== $resolved['reason'] ) {
			return $this->fail( $resolved['reason'] );
		}

		try {
			$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$ciphertext = sodium_crypto_secretbox( (string) $plaintext, $nonce, $resolved['key'] );
		} catch ( \Throwable $e ) {
			return $this->fail( self::REASON_ENCRYPT_FAILED );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return self::BLOB_PREFIX . base64_encode( chr( self::BLOB_VERSION ) . self::fingerprint( $resolved['key'] ) . $nonce . $ciphertext );
	}

	/**
	 * Decrypt a stored blob (v2 or legacy).
	 *
	 * @param string $encoded Blob from encrypt() or a pre-3.11.1 release.
	 * @return string|false Plaintext, or false (reason in get_last_error()).
	 */
	public function decrypt( $encoded ) {
		$this->begin();

		if ( ! self::is_available() ) {
			return $this->fail( self::REASON_SODIUM_UNAVAILABLE );
		}

		$blob = self::parse_blob( $encoded );
		if ( '' !== $blob['reason'] ) {
			return $this->fail( $blob['reason'] );
		}

		$primary = $this->resolve_primary_key( false );
		$pinned  = self::pinned_key_defined();

		// A weak pin is a misconfiguration to fix, never a reason to try another key.
		if ( self::REASON_PINNED_KEY_WEAK === $primary['reason'] ) {
			return $this->fail( self::REASON_PINNED_KEY_WEAK );
		}

		if ( 'v2' === $blob['format'] ) {
			if ( '' === $primary['reason'] && hash_equals( self::fingerprint( $primary['key'] ), $blob['fingerprint'] ) ) {
				return $this->open_or_fail( $blob, $primary['key'] );
			}

			if ( ! $pinned ) {
				// Only one key to consult. If it could not be read, that is the answer.
				return $this->fail( '' !== $primary['reason'] ? $primary['reason'] : self::REASON_WRONG_KEY );
			}

			// A constant is pinned: the blob may predate it, under the option key.
			$option = $this->resolve_option_key( false );
			if ( '' === $option['reason'] && hash_equals( self::fingerprint( $option['key'] ), $blob['fingerprint'] ) ) {
				$plaintext = $this->open_or_fail( $blob, $option['key'] );
				if ( false !== $plaintext ) {
					$this->warn( self::WARNING_PINNED_KEY_DIFFERS );
				}
				return $plaintext;
			}

			// An option key that could not be read might have matched.
			if ( '' !== $option['reason'] && self::REASON_KEY_MISSING !== $option['reason'] ) {
				return $this->fail( $option['reason'] );
			}

			return $this->fail( self::REASON_WRONG_KEY );
		}

		// Legacy blob: no key id, so try the keys this site can hold.
		if ( '' === $primary['reason'] ) {
			$plaintext = self::open( $blob, $primary['key'] );
			if ( false !== $plaintext ) {
				return $plaintext;
			}
		}

		if ( ! $pinned ) {
			return $this->fail( '' !== $primary['reason'] ? $primary['reason'] : self::REASON_UNDECRYPTABLE );
		}

		$option = $this->resolve_option_key( false );
		if ( '' === $option['reason'] ) {
			if ( ! hash_equals( $option['key'], (string) $primary['key'] ) ) {
				$plaintext = self::open( $blob, $option['key'] );
				if ( false !== $plaintext ) {
					$this->warn( self::WARNING_PINNED_KEY_DIFFERS );
					return $plaintext;
				}
			}
		} elseif ( self::REASON_KEY_MISSING !== $option['reason'] ) {
			return $this->fail( $option['reason'] );
		}

		return $this->fail( self::REASON_UNDECRYPTABLE );
	}

	/**
	 * Decrypt with a key re-read from storage, bypassing this instance's cache.
	 *
	 * Used to verify a write against the key the site holds now, not the one
	 * this request cached earlier.
	 *
	 * @param string $encoded Blob.
	 * @return string|false
	 */
	public function decrypt_uncached( $encoded ) {
		$fresh     = new self();
		$plaintext = $fresh->decrypt( $encoded );

		$this->last_error    = $fresh->last_error;
		$this->last_warnings = $fresh->last_warnings;

		return $plaintext;
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
	 * Decrypt a blob (either format) using an explicit raw key.
	 *
	 * Records no alert: the migration probes blobs with a key they are not
	 * expected to open.
	 *
	 * @param string $encoded Blob.
	 * @param string $key     32-byte raw binary key.
	 * @return string|false Plaintext or false on failure.
	 */
	public function decrypt_with_key( $encoded, $key ) {
		$this->begin();

		if ( ! self::is_available() ) {
			$this->last_error = self::REASON_SODIUM_UNAVAILABLE;
			return false;
		}

		$blob = self::parse_blob( $encoded );
		if ( '' !== $blob['reason'] ) {
			$this->last_error = $blob['reason'];
			return false;
		}

		if ( ! is_string( $key ) || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			$this->last_error = self::REASON_UNDECRYPTABLE;
			return false;
		}

		$plaintext = self::open( $blob, $key );
		if ( false === $plaintext ) {
			$this->last_error = self::REASON_UNDECRYPTABLE;
		}

		return $plaintext;
	}

	/**
	 * Read one option row straight from the options table.
	 *
	 * The query selects a constant beside a scalar subquery, so it returns
	 * exactly one row whenever it runs: no row means the read failed, and a
	 * NULL value means the option does not exist (option_value is NOT NULL).
	 * wpdb can return null with an empty last_error (a failed reconnect after
	 * template_redirect), so the row shape — not last_error alone — decides.
	 *
	 * ORDER BY option_id: the UNIQUE index on option_name is what makes first
	 * writer win. On a table that lost it (a broken import), two rows can share
	 * a name; the first inserted one is then always the answer, never either.
	 *
	 * @param string $name Option name.
	 * @return array{state: string, value: string|null}
	 */
	public static function read_option_row( $name ) {
		global $wpdb;

		$error = array(
			'state' => self::ROW_ERROR,
			'value' => null,
		);

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) || ! method_exists( $wpdb, 'prepare' ) || empty( $wpdb->options ) ) {
			return $error;
		}

		$suppressed       = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
		$wpdb->last_error = '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the cache is exactly what must be bypassed.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT 1 AS mcpwp_probe, (SELECT option_value FROM {$wpdb->options} WHERE option_name = %s ORDER BY option_id ASC LIMIT 1) AS option_value",
				$name
			),
			ARRAY_A
		);

		$db_error = (string) $wpdb->last_error;

		if ( null !== $suppressed ) {
			$wpdb->suppress_errors( $suppressed );
		}

		if ( '' !== $db_error || ! is_array( $row ) || ! isset( $row['mcpwp_probe'] ) || '1' !== (string) $row['mcpwp_probe'] || ! array_key_exists( 'option_value', $row ) ) {
			return $error;
		}

		if ( null === $row['option_value'] ) {
			return array(
				'state' => self::ROW_ABSENT,
				'value' => null,
			);
		}

		return array(
			'state' => self::ROW_PRESENT,
			'value' => (string) $row['option_value'],
		);
	}

	/**
	 * Whether the key new blobs would use can be resolved right now.
	 *
	 * Side-effect free: never creates, caches, or records an alert.
	 *
	 * @return string '' when usable, else a REASON_* constant (REASON_KEY_MISSING
	 *                when no key row exists and no constant is pinned).
	 */
	public static function inspect_key() {
		if ( ! self::is_available() ) {
			return self::REASON_SODIUM_UNAVAILABLE;
		}

		if ( self::pinned_key_defined() ) {
			return null === self::pinned_key() ? self::REASON_PINNED_KEY_WEAK : '';
		}

		$row = self::read_option_row( self::KEY_OPTION );

		if ( self::ROW_ERROR === $row['state'] ) {
			return self::REASON_KEY_UNAVAILABLE;
		}

		if ( self::ROW_ABSENT === $row['state'] ) {
			return self::REASON_KEY_MISSING;
		}

		return null === self::decode_key( $row['value'] ) ? self::REASON_KEY_CORRUPT : '';
	}

	/**
	 * Insert an option row only if none exists (first writer wins).
	 *
	 * A plain INSERT against the UNIQUE option_name index: it cannot
	 * overwrite, unlike add_option()/update_option(), which issue
	 * INSERT … ON DUPLICATE KEY UPDATE. Autoload is off.
	 *
	 * @param string $name  Option name.
	 * @param string $value Raw value.
	 * @return bool True when this call inserted the row. False does not say
	 *              why — callers re-read the row.
	 */
	public static function insert_option_row_if_absent( $name, $value ) {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) || empty( $wpdb->options ) ) {
			return false;
		}

		// A duplicate-key refusal is an expected outcome; keep wpdb from
		// printing it into a JSON response (#802).
		$suppressed = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;

		$rows = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $value,
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);

		if ( null !== $suppressed ) {
			$wpdb->suppress_errors( $suppressed );
		}

		self::clean_option_cache( $name );

		return 1 === (int) $rows;
	}

	/**
	 * Drop cached copies of an option written or deleted behind the options API.
	 *
	 * @param string $name Option name.
	 */
	public static function clean_option_cache( $name ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
		}

		if ( function_exists( 'wp_cache_get' ) && function_exists( 'wp_cache_set' ) ) {
			$notoptions = wp_cache_get( 'notoptions', 'options' );
			if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
				unset( $notoptions[ $name ] );
				wp_cache_set( 'notoptions', $notoptions, 'options' );
			}
		}
	}

	/**
	 * Render the admin notice for recorded or live key problems.
	 *
	 * Shown only to users who can manage options. Never prints key material.
	 */
	public static function render_admin_notice() {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$alerts = get_option( self::ALERTS_OPTION, array() );
		if ( ! is_array( $alerts ) ) {
			$alerts = array();
		}

		$now     = time();
		$changed = false;
		$live    = '';

		foreach ( $alerts as $code => $entry ) {
			$last_seen = is_array( $entry ) && isset( $entry['last_seen'] ) ? (int) $entry['last_seen'] : 0;
			if ( $now - $last_seen > self::ALERT_TTL ) {
				unset( $alerts[ $code ] );
				$changed = true;
			}
		}

		// A weak pin is judged from the constant itself, so the notice shows
		// even with no recorded alert, and clears once the constant is fixed
		// or removed.
		if ( self::pinned_key_defined() && null === self::pinned_key() ) {
			$live = self::REASON_PINNED_KEY_WEAK;
		} elseif ( isset( $alerts[ self::REASON_PINNED_KEY_WEAK ] ) ) {
			unset( $alerts[ self::REASON_PINNED_KEY_WEAK ] );
			$changed = true;
		}

		// Key-state alerts follow the row itself: a repaired key clears them,
		// and a corrupt one shows even when recording the alert failed.
		if ( ! self::pinned_key_defined() ) {
			$row = self::read_option_row( self::KEY_OPTION );

			if ( self::ROW_ERROR === $row['state'] ) {
				$live = self::REASON_KEY_UNAVAILABLE;
			} elseif ( self::ROW_PRESENT === $row['state'] && null === self::decode_key( $row['value'] ) ) {
				$live = self::REASON_KEY_CORRUPT;
			} elseif ( self::ROW_PRESENT === $row['state'] ) {
				foreach ( array( self::REASON_KEY_CORRUPT, self::REASON_KEY_UNAVAILABLE, self::REASON_KEY_MISSING, self::REASON_KEY_WRITE_FAILED ) as $code ) {
					if ( isset( $alerts[ $code ] ) ) {
						unset( $alerts[ $code ] );
						$changed = true;
					}
				}
			}
		}

		if ( $changed ) {
			if ( empty( $alerts ) ) {
				delete_option( self::ALERTS_OPTION );
			} else {
				update_option( self::ALERTS_OPTION, $alerts, false );
			}
		}

		$codes = array_keys( $alerts );
		if ( '' !== $live && ! in_array( $live, $codes, true ) ) {
			$codes[] = $live;
		}

		foreach ( $codes as $code ) {
			$message = self::alert_message( (string) $code );
			if ( '' === $message ) {
				continue;
			}
			printf(
				'<div class="notice %s"><p><strong>%s</strong> %s</p></div>',
				self::WARNING_PINNED_KEY_DIFFERS === $code ? 'notice-warning' : 'notice-error',
				esc_html__( 'MCPWP encryption:', 'mcpwp' ),
				esc_html( $message )
			);
		}
	}

	/**
	 * Key id for a raw key.
	 *
	 * @param string $key 32-byte raw key.
	 * @return string 4 raw bytes.
	 */
	public static function fingerprint( $key ) {
		return substr( sodium_crypto_generichash( self::FINGERPRINT_CONTEXT, $key, SODIUM_CRYPTO_GENERICHASH_BYTES ), 0, self::FINGERPRINT_BYTES );
	}

	// ── Internals ────────────────────────────────────────────────────────

	/**
	 * Reset per-call status.
	 */
	private function begin() {
		$this->last_error    = '';
		$this->last_warnings = array();
	}

	/**
	 * Record a failure reason and return false.
	 *
	 * @param string $reason REASON_* constant.
	 * @return false
	 */
	private function fail( $reason ) {
		$this->last_error = $reason;

		$alertable = array(
			self::REASON_KEY_UNAVAILABLE,
			self::REASON_KEY_CORRUPT,
			self::REASON_KEY_MISSING,
			self::REASON_KEY_WRITE_FAILED,
			self::REASON_WRONG_KEY,
			self::REASON_TAMPERED,
			self::REASON_UNDECRYPTABLE,
			self::REASON_UNSUPPORTED_VERSION,
			self::REASON_PINNED_KEY_WEAK,
		);

		if ( in_array( $reason, $alertable, true ) ) {
			$this->record_alert( $reason );
		}

		return false;
	}

	/**
	 * Record a warning for the caller and the admin.
	 *
	 * @param string $warning WARNING_* constant.
	 */
	private function warn( $warning ) {
		if ( ! in_array( $warning, $this->last_warnings, true ) ) {
			$this->last_warnings[] = $warning;
		}
		$this->record_alert( $warning );
	}

	/**
	 * Persist an alert for the admin notice. Best effort, throttled.
	 *
	 * @param string $code Alert code.
	 */
	private function record_alert( $code ) {
		if ( isset( $this->alerted[ $code ] ) ) {
			return;
		}
		$this->alerted[ $code ] = true;

		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}

		$alerts = get_option( self::ALERTS_OPTION, array() );
		if ( ! is_array( $alerts ) ) {
			$alerts = array();
		}

		$now   = time();
		$entry = isset( $alerts[ $code ] ) && is_array( $alerts[ $code ] )
			? $alerts[ $code ]
			: array(
				'first_seen' => $now,
				'last_seen'  => 0,
			);

		if ( $now - (int) $entry['last_seen'] < self::ALERT_WRITE_INTERVAL ) {
			return;
		}

		$entry['last_seen'] = $now;
		$alerts[ $code ]    = $entry;
		update_option( self::ALERTS_OPTION, $alerts, false );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'MCPWP encryption: ' . $code . ' (see the MCPWP encryption admin notice).' );
		}
	}

	/**
	 * Admin-facing text for an alert code. Never includes stored values.
	 *
	 * @param string $code Alert code.
	 * @return string
	 */
	private static function alert_message( $code ) {
		switch ( $code ) {
			case self::REASON_KEY_CORRUPT:
				return __( 'The stored encryption key (option mcpwp_encryption_key) is not a valid key. MCPWP will not replace it, so stored provider keys and connection credentials cannot be encrypted or decrypted. Restore that option from a backup. Only if no backup exists, delete the option and re-enter every provider key and connection.', 'mcpwp' );
			case self::REASON_KEY_UNAVAILABLE:
				return __( 'MCPWP could not read its encryption key (option mcpwp_encryption_key) from the database. Stored secrets were not used and no key was replaced. If this keeps happening, check the database.', 'mcpwp' );
			case self::REASON_KEY_WRITE_FAILED:
				return __( 'MCPWP could not store a new encryption key (option mcpwp_encryption_key), so the secret being saved was not stored.', 'mcpwp' );
			case self::REASON_KEY_MISSING:
				return __( 'Encrypted secrets exist but the encryption key (option mcpwp_encryption_key) is missing, so they cannot be decrypted. Restore the option from a backup, or re-enter the affected provider keys and connections.', 'mcpwp' );
			case self::REASON_WRONG_KEY:
				return __( 'Some stored secrets were encrypted with a different key than this site now has (option mcpwp_encryption_key or the MCPWP_ENCRYPTION_KEY constant). Restore the original key, or re-enter the affected provider keys and connections.', 'mcpwp' );
			case self::REASON_UNDECRYPTABLE:
				return __( 'Some stored secrets could not be decrypted with this site\'s encryption key. Re-enter the affected provider keys and connections.', 'mcpwp' );
			case self::REASON_TAMPERED:
				return __( 'Some stored secrets failed their integrity check and were not used; they may have been altered in the database. Re-enter the affected provider keys and connections.', 'mcpwp' );
			case self::REASON_UNSUPPORTED_VERSION:
				return __( 'Some stored secrets use an encryption format newer than this version of MCPWP, which usually means the plugin was downgraded. Update MCPWP, or re-enter the affected provider keys and connections.', 'mcpwp' );
			case self::REASON_PINNED_KEY_WEAK:
				return __( 'MCPWP_ENCRYPTION_KEY is defined in wp-config.php but is empty or too weak (fewer than 16 bytes once whitespace is trimmed, or a single repeated character), so a key derived from it could be guessed. MCPWP will not encrypt or decrypt anything until it is fixed. Replace it with a long random value, or remove the constant to use the stored option key. Secrets saved under an earlier weak value must be re-entered.', 'mcpwp' );
			case self::WARNING_PINNED_KEY_DIFFERS:
				return __( 'MCPWP_ENCRYPTION_KEY is defined in wp-config.php, but some secrets are still encrypted with the stored option key (mcpwp_encryption_key). They still decrypt. Re-save those provider keys and connections to move them to the pinned key, and do not delete the mcpwp_encryption_key option before then.', 'mcpwp' );
		}

		return '';
	}

	/**
	 * Resolve the key new blobs are written under.
	 *
	 * @param bool $allow_create Create the option key when its row is absent.
	 * @return array{key: string|null, reason: string}
	 */
	private function resolve_primary_key( $allow_create ) {
		if ( null !== $this->key ) {
			return self::resolved( $this->key );
		}

		if ( self::pinned_key_defined() ) {
			$pinned = self::pinned_key();
			if ( null === $pinned ) {
				return self::unresolved( self::REASON_PINNED_KEY_WEAK );
			}
			$this->key = $pinned;
			return self::resolved( $pinned );
		}

		$option = $this->resolve_option_key( $allow_create );
		if ( '' === $option['reason'] ) {
			$this->key = $option['key'];
		}

		return $option;
	}

	/**
	 * Resolve the option key from its row.
	 *
	 * @param bool $allow_create Create the key when (and only when) no row exists.
	 * @return array{key: string|null, reason: string}
	 */
	private function resolve_option_key( $allow_create ) {
		if ( null !== $this->option_key ) {
			return self::resolved( $this->option_key );
		}

		$row = self::read_option_row( self::KEY_OPTION );

		if ( self::ROW_ABSENT === $row['state'] && $allow_create ) {
			try {
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				$candidate = base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
			} catch ( \Throwable $e ) {
				return self::unresolved( self::REASON_KEY_WRITE_FAILED );
			}

			self::insert_option_row_if_absent( self::KEY_OPTION, $candidate );
			$candidate = '';

			// Whatever became of that insert, the stored row is the authority:
			// when another request created the key first, its key is the key.
			$row = self::read_option_row( self::KEY_OPTION );
			if ( self::ROW_ABSENT === $row['state'] ) {
				return self::unresolved( self::REASON_KEY_WRITE_FAILED );
			}
		}

		if ( self::ROW_ERROR === $row['state'] ) {
			return self::unresolved( self::REASON_KEY_UNAVAILABLE );
		}

		if ( self::ROW_ABSENT === $row['state'] ) {
			return self::unresolved( self::REASON_KEY_MISSING );
		}

		$raw = self::decode_key( $row['value'] );
		if ( null === $raw ) {
			return self::unresolved( self::REASON_KEY_CORRUPT );
		}

		$this->option_key = $raw;
		return self::resolved( $raw );
	}

	/**
	 * Whether an operator pinned the key in wp-config.php.
	 *
	 * @return bool
	 */
	private static function pinned_key_defined() {
		return defined( 'MCPWP_ENCRYPTION_KEY' );
	}

	/**
	 * The pinned key, or null when none is defined or the constant is weak.
	 *
	 * The constant may be exactly 32 raw bytes, or any passphrase, which is
	 * hashed to the key length (unchanged from 3.2.4). A weak constant yields
	 * no key at all: hashing it would give a key anyone can recompute.
	 *
	 * @return string|null
	 */
	private static function pinned_key() {
		if ( ! self::pinned_key_defined() ) {
			return null;
		}

		$raw = is_scalar( MCPWP_ENCRYPTION_KEY ) ? (string) MCPWP_ENCRYPTION_KEY : '';
		if ( self::pinned_key_is_weak( $raw ) ) {
			return null;
		}

		if ( SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $raw ) ) {
			$raw = sodium_crypto_generichash( $raw, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}

		return $raw;
	}

	/**
	 * Whether a pinned constant is too weak to derive a key from.
	 *
	 * Empty, whitespace (trim() also strips NUL), fewer than
	 * PINNED_KEY_MIN_BYTES once trimmed, or a single repeated byte. This is a
	 * floor against obviously guessable values, not an entropy estimate.
	 *
	 * @param string $raw Constant value.
	 * @return bool
	 */
	private static function pinned_key_is_weak( $raw ) {
		if ( strlen( trim( $raw ) ) < self::PINNED_KEY_MIN_BYTES ) {
			return true;
		}

		return 1 === strlen( count_chars( $raw, 3 ) );
	}

	/**
	 * Decode a stored key value.
	 *
	 * @param mixed $value Raw option value.
	 * @return string|null 32 raw bytes, or null when the value is not a key.
	 */
	private static function decode_key( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$raw = base64_decode( $value, true );

		return ( false !== $raw && SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen( $raw ) ) ? $raw : null;
	}

	/**
	 * Parse a stored blob into its parts.
	 *
	 * @param mixed $encoded Stored value.
	 * @return array{reason: string, format: string, fingerprint: string, nonce: string, ciphertext: string}
	 */
	private static function parse_blob( $encoded ) {
		$parsed = array(
			'reason'      => self::REASON_MALFORMED,
			'format'      => '',
			'fingerprint' => '',
			'nonce'       => '',
			'ciphertext'  => '',
		);

		$minimum = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

		if ( ! is_string( $encoded ) || '' === $encoded ) {
			return $parsed;
		}

		if ( self::is_versioned_blob( $encoded ) ) {
			if ( 0 !== strpos( $encoded, self::BLOB_PREFIX ) ) {
				$parsed['reason'] = self::REASON_UNSUPPORTED_VERSION;
				return $parsed;
			}

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			$binary = base64_decode( substr( $encoded, strlen( self::BLOB_PREFIX ) ), true );
			if ( false === $binary || strlen( $binary ) < 1 + self::FINGERPRINT_BYTES + $minimum ) {
				return $parsed;
			}

			if ( self::BLOB_VERSION !== ord( $binary[0] ) ) {
				$parsed['reason'] = self::REASON_UNSUPPORTED_VERSION;
				return $parsed;
			}

			$offset                = 1 + self::FINGERPRINT_BYTES;
			$parsed['reason']      = '';
			$parsed['format']      = 'v2';
			$parsed['fingerprint'] = substr( $binary, 1, self::FINGERPRINT_BYTES );
			$parsed['nonce']       = substr( $binary, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$parsed['ciphertext']  = substr( $binary, $offset + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return $parsed;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$binary = base64_decode( $encoded, true );
		if ( false === $binary || strlen( $binary ) < $minimum ) {
			return $parsed;
		}

		$parsed['reason']     = '';
		$parsed['format']     = 'legacy';
		$parsed['nonce']      = substr( $binary, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$parsed['ciphertext'] = substr( $binary, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return $parsed;
	}

	/**
	 * Open a parsed blob with a key.
	 *
	 * @param array  $blob Parsed blob.
	 * @param string $key  Raw key.
	 * @return string|false
	 */
	private static function open( array $blob, $key ) {
		try {
			return sodium_crypto_secretbox_open( $blob['ciphertext'], $blob['nonce'], $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Open a v2 blob whose key id matched; a MAC failure is tampering.
	 *
	 * @param array  $blob Parsed blob.
	 * @param string $key  Raw key.
	 * @return string|false
	 */
	private function open_or_fail( array $blob, $key ) {
		$plaintext = self::open( $blob, $key );
		if ( false === $plaintext ) {
			return $this->fail( self::REASON_TAMPERED );
		}
		return $plaintext;
	}

	/**
	 * A resolved key.
	 *
	 * @param string $key Raw key.
	 * @return array{key: string, reason: string}
	 */
	private static function resolved( $key ) {
		return array(
			'key'    => $key,
			'reason' => '',
		);
	}

	/**
	 * An unresolved key.
	 *
	 * @param string $reason REASON_* constant.
	 * @return array{key: null, reason: string}
	 */
	private static function unresolved( $reason ) {
		return array(
			'key'    => null,
			'reason' => $reason,
		);
	}
}
