<?php

use PHPUnit\Framework\TestCase;

/**
 * A weak MCPWP_ENCRYPTION_KEY must never become the key.
 *
 * The constant is hashed to the key length when it is not 32 bytes, so an
 * empty, blank or short constant derives a key anyone can compute (the empty
 * string's BLAKE2b is public). Such a constant fails closed with its own
 * reason — no fallback to the option key, no derived key — and is surfaced in
 * the encryption admin notice.
 *
 * Constants cannot be undefined: every test runs in its own process (#757).
 */
final class EncryptionWeakPinnedKeyTest extends TestCase
{
    private function restartEncryption(): Mcpwp_Encryption
    {
        $ref = new ReflectionProperty(Mcpwp_Encryption::class, 'instance');
        $ref->setAccessible(true);
        $ref->setValue(null, null);

        return Mcpwp_Encryption::get_instance();
    }

    private function cleanProcess(): void
    {
        if (! Mcpwp_Encryption::is_available()) {
            $this->markTestSkipped('sodium extension not available');
        }
        if (defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('MCPWP_ENCRYPTION_KEY already defined in this process');
        }

        $GLOBALS['mcpwp_test_options']            = array();
        $GLOBALS['mcpwp_test_wpdb_options']       = array();
        $GLOBALS['mcpwp_test_wpdb_read_fails']    = false;
        $GLOBALS['mcpwp_test_wpdb_insert_fails']  = false;
        $GLOBALS['mcpwp_test_update_option_fail'] = array();
        $GLOBALS['mcpwp_test_user_caps']          = array();
        $this->restartEncryption();
    }

    private function legacyBlob(string $plaintext, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $key));
    }

    private function noticeHtml(): string
    {
        $GLOBALS['mcpwp_test_current_user'] = 2;
        ob_start();
        Mcpwp_Encryption::render_admin_notice();

        return (string) ob_get_clean();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function weakConstants(): array
    {
        return array(
            'empty string'                  => array(''),
            'whitespace only'               => array(" \t\n  "),
            'short passphrase'              => array('hunter2-pass'),
            '15 bytes inside whitespace'    => array('   fifteen-bytes!!   '),
            'one repeated character'        => array(str_repeat('a', 32)),
            'all zero bytes'                => array(str_repeat("\0", 32)),
            // define( 'MCPWP_ENCRYPTION_KEY', getenv( 'MCPWP_ENCRYPTION_KEY' ) ) with the variable unset.
            'getenv() of an unset variable' => array(false),
            'null'                          => array(null),
            // Review r2 P3-C: the checks run on the trimmed value, and short
            // periodic patterns are refused.
            '16 repeated bytes and a newline'  => array("aaaaaaaaaaaaaaaa\n"),
            'repeated bytes inside spaces'     => array(' ' . str_repeat('a', 20) . ' '),
            'two-byte pattern'                 => array('abababababababab'),
            'three-byte pattern'               => array(str_repeat('abc', 8)),
            'three-byte pattern in whitespace' => array(' ' . str_repeat('abc', 8) . "\n"),
            'four-byte pattern'                => array(str_repeat('abcd', 6)),
            'eight-byte pattern'               => array('passwordpassword'),
            '32 bytes of an eight-byte pattern' => array(str_repeat('k3Y!pinN', 4)),
        );
    }

    /**
     * @dataProvider weakConstants
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_weak_pinned_constant_fails_closed_and_is_surfaced($constant): void
    {
        $this->cleanProcess();

        // Secrets already exist under the option key.
        $optionKey = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'] = base64_encode($optionKey);
        $GLOBALS['mcpwp_test_options']['mcpwp_encryption_key']      = base64_encode($optionKey);
        $underOption = Mcpwp_Encryption::get_instance()->encrypt('under-the-option-key');
        $this->assertNotFalse($underOption);

        define('MCPWP_ENCRYPTION_KEY', $constant);
        $enc = $this->restartEncryption();

        $this->assertFalse($enc->encrypt('new-secret'), 'nothing may be written under a guessable key');
        $this->assertSame(Mcpwp_Encryption::REASON_PINNED_KEY_WEAK, $enc->get_last_error());

        $this->assertFalse($enc->decrypt($underOption), 'a weak pin is a misconfiguration, not a reason to fall back');
        $this->assertSame(Mcpwp_Encryption::REASON_PINNED_KEY_WEAK, $enc->get_last_error());
        $this->assertSame(array(), $enc->get_last_warnings());

        // A blob under the publicly derivable key is not honoured either.
        $as_string = is_scalar($constant) ? (string) $constant : '';
        $public    = SODIUM_CRYPTO_SECRETBOX_KEYBYTES === strlen($as_string)
            ? $as_string
            : sodium_crypto_generichash($as_string, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        $this->assertFalse($enc->decrypt($this->legacyBlob('under-the-public-key', $public)));
        $this->assertSame(Mcpwp_Encryption::REASON_PINNED_KEY_WEAK, $enc->get_last_error());

        $this->assertSame(base64_encode($optionKey), $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'], 'the option key is untouched');

        $this->assertArrayHasKey(
            Mcpwp_Encryption::REASON_PINNED_KEY_WEAK,
            (array) get_option(Mcpwp_Encryption::ALERTS_OPTION, array())
        );

        // The notice shows it from the constant itself, even with no recorded alert.
        delete_option(Mcpwp_Encryption::ALERTS_OPTION);
        $html = $this->noticeHtml();
        $this->assertStringContainsString('MCPWP_ENCRYPTION_KEY', $html);
        if ('' !== trim($as_string)) {
            $this->assertStringNotContainsString($as_string, $html, 'the notice never echoes the constant');
        }

        $GLOBALS['mcpwp_test_current_user'] = 0;
        ob_start();
        Mcpwp_Encryption::render_admin_notice();
        $this->assertSame('', (string) ob_get_clean());
    }

    /**
     * The floor is deliberately simple: a 16-byte phrase with no short repeat
     * passes it. It stops empty, unset, placeholder and patterned values, not
     * a bad passphrase.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_sixteen_byte_passphrase_is_accepted(): void
    {
        $this->cleanProcess();

        define('MCPWP_ENCRYPTION_KEY', 'sixteen-byte-key');
        $enc = $this->restartEncryption();

        $blob = $enc->encrypt('pinned-secret');
        $this->assertNotFalse($blob);
        $this->assertSame('pinned-secret', $enc->decrypt($blob));
        $this->assertSame('', $enc->get_last_error());
        $this->assertSame('', $this->noticeHtml());
    }

    /**
     * Random keys in every common encoding must never be refused. 4000 of each;
     * the false-positive odds per key are below 1e-8 for the shortest form.
     */
    public function test_random_keys_in_common_encodings_are_never_classified_weak(): void
    {
        $isWeak = new ReflectionMethod(Mcpwp_Encryption::class, 'pinned_key_is_weak');
        $isWeak->setAccessible(true);

        $forms = array(
            'raw 32 bytes'                   => static function (): string { return random_bytes(32); },
            'hex, 64 chars'                  => static function (): string { return bin2hex(random_bytes(32)); },
            'base64, 44 chars'               => static function (): string { return base64_encode(random_bytes(32)); },
            'hex, 16 chars'                  => static function (): string { return bin2hex(random_bytes(8)); },
            'raw with NUL and newline edges' => static function (): string { return "\0" . random_bytes(30) . "\n"; },
        );

        foreach ($forms as $label => $make) {
            $refused = 0;
            for ($i = 0; $i < 4000; $i++) {
                if ($isWeak->invoke(null, $make())) {
                    $refused++;
                }
            }
            $this->assertSame(0, $refused, $label . ': a random key was classified weak');
        }
    }

    /**
     * Review r2 M28: once the constant is fixed or removed, the stale
     * "will not encrypt or decrypt anything" notice goes away.
     */
    public function test_a_stale_weak_pin_alert_clears_once_the_constant_is_fixed_or_removed(): void
    {
        if (defined('MCPWP_ENCRYPTION_KEY')) {
            $this->markTestSkipped('MCPWP_ENCRYPTION_KEY already defined in this process');
        }

        $prevUser = $GLOBALS['mcpwp_test_current_user'];
        $prevCaps = $GLOBALS['mcpwp_test_user_caps'];
        $GLOBALS['mcpwp_test_options']         = array();
        $GLOBALS['mcpwp_test_wpdb_read_fails'] = false;
        $GLOBALS['mcpwp_test_user_caps']       = array();
        $key                                   = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key'] = $key;

        try {
            update_option(Mcpwp_Encryption::ALERTS_OPTION, array(
                Mcpwp_Encryption::REASON_PINNED_KEY_WEAK => array('first_seen' => time() - 120, 'last_seen' => time() - 60),
            ));

            $html = $this->noticeHtml();

            $this->assertStringNotContainsString('MCPWP_ENCRYPTION_KEY', $html);
            $this->assertArrayNotHasKey(Mcpwp_Encryption::REASON_PINNED_KEY_WEAK, (array) get_option(Mcpwp_Encryption::ALERTS_OPTION, array()));
        } finally {
            unset($GLOBALS['mcpwp_test_wpdb_options']['mcpwp_encryption_key']);
            $GLOBALS['mcpwp_test_current_user'] = $prevUser;
            $GLOBALS['mcpwp_test_user_caps']    = $prevCaps;
        }
    }

    /**
     * Review r2 M20: a weak pin stops the AUTH_SALT migration — no flag, no
     * failures — so it runs once the constant is fixed.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_a_weak_pin_stops_the_migration_without_a_flag_or_failures(): void
    {
        $this->cleanProcess();

        $blob = $this->legacyBlob('sk-legacy', Mcpwp_Encryption::get_instance()->get_legacy_auth_salt_key());
        update_option(Mcpwp_Integration_Manager::OPTION_NAME, array(
            'openai' => array('key_encrypted' => $blob, 'configured_at' => '2026-01-01 00:00:00'),
        ));

        define('MCPWP_ENCRYPTION_KEY', 'weak');
        $this->restartEncryption();

        Mcpwp_Encryption_Migration::run();

        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::MIGRATED_FLAG));
        $this->assertFalse(get_option(Mcpwp_Encryption_Migration::FAILURES_OPTION), 'a key problem is not a blob failure');
        $this->assertSame($blob, get_option(Mcpwp_Integration_Manager::OPTION_NAME)['openai']['key_encrypted']);
        $this->assertArrayNotHasKey('mcpwp_encryption_key', $GLOBALS['mcpwp_test_wpdb_options']);
    }
}
