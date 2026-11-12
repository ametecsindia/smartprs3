<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * APPLICATION-LAYER ENCRYPTION AND THE BLIND INDEX — Part X §X.2.6.
 *
 * Two separate jobs that are always confused for one, so they get separate keys
 * here and the separation is the design:
 *
 *   ENCRYPT hides the value. AES-256-GCM, authenticated, with a fresh IV per
 *   value, so two employees with the same Aadhaar produce different ciphertext
 *   and nobody can tell by looking that they match.
 *
 *   BLIND INDEX finds it again. HMAC-SHA256 of the NORMALISED value under a
 *   different key, deterministic on purpose — that is what makes
 *   `WHERE v_blind_index = ?` and a UNIQUE constraint possible on a column
 *   nobody can read. Deterministic means two identical values DO collide, which
 *   is the feature; it also means the index leaks equality, which is why it is
 *   keyed rather than a plain hash. A `sha256(pan)` column is a rainbow table
 *   away from being plaintext.
 *
 * Both keys come from the environment and NEITHER HAS A DEFAULT. A missing key
 * throws; it does not fall back to a constant, a derived value or the other key.
 * An encryption layer with a fallback key is an encryption layer that is off in
 * exactly the deployment nobody checked, and it fails silently because the
 * ciphertext still looks like ciphertext.
 *
 * The envelope carries its key id, so rotation is: add a key, encrypt new
 * values under it, decrypt old ones under the id they name. Nothing has to be
 * re-encrypted in a migration window, which is what makes rotation something a
 * team will actually do.
 *
 * SmartPRS 2 stores government identifiers and bank account numbers as plain
 * columns, exports them in full to whoever clicked the button, and has no
 * masking layer to stop the template printing them.
 */
final class Encryption
{
    public const CIPHER = 'aes-256-gcm';

    /** The envelope: version, key id, IV, tag, ciphertext. Self-describing on purpose. */
    private const PREFIX = 'enc:v1';

    /**
     * @param array<string, string> $keys keyId => 32-byte key, newest last
     * @param string $indexKey the SEPARATE key the blind index is computed under
     */
    public function __construct(
        private readonly array $keys,
        private readonly string $indexKey,
        private readonly string $activeKeyId,
    ) {
        if ($keys === []) {
            throw new \InvalidArgumentException(
                'No encryption key. There is no default and no fallback: a layer that encrypts under a '
                . 'constant when its key is missing is off in the one deployment nobody checked, and the '
                . 'ciphertext still looks like ciphertext.'
            );
        }

        if (!isset($keys[$activeKeyId])) {
            throw new \InvalidArgumentException("The active key id '{$activeKeyId}' is not among the supplied keys.");
        }

        foreach ($keys as $id => $key) {
            if (strlen($key) !== 32) {
                throw new \InvalidArgumentException(
                    "Key '{$id}' is " . strlen($key) . ' bytes; AES-256 takes 32. A short key is not padded here, '
                    . 'because padding it silently is how a 4-byte key becomes a system nobody knows is weak.'
                );
            }
        }

        if (strlen($indexKey) < 32) {
            throw new \InvalidArgumentException(
                'The blind-index key is shorter than 32 bytes. It is a SEPARATE key from the encryption key '
                . 'on purpose: whoever can compute the index must not thereby be able to decrypt.'
            );
        }

        if (in_array($indexKey, $keys, true)) {
            throw new \InvalidArgumentException(
                'The blind-index key is the same as an encryption key. Reusing one key for two purposes means '
                . 'a component that only needed to look a value up can read every value there is.'
            );
        }
    }

    /**
     * Build from the environment. Every key absent is an error, not a warning.
     *
     * Keys arrive base64-encoded because a 32-byte key is not typeable, and a
     * team that has to type it will choose one that is.
     */
    public static function fromEnvironment(): self
    {
        $keys = [];

        foreach ($_ENV + $_SERVER as $name => $value) {
            if (is_string($name) && preg_match('/^SMARTPRS_ENC_KEY_([A-Z0-9]+)$/', $name, $m) === 1) {
                $keys[strtolower($m[1])] = self::decodeKey((string) $value, $name);
            }
        }

        $indexKey = (string) (getenv('SMARTPRS_BLIND_INDEX_KEY') ?: '');

        if ($indexKey === '') {
            throw new \RuntimeException(
                'SMARTPRS_BLIND_INDEX_KEY is not set. Encrypted fields cannot be looked up or made unique '
                . 'without it, and starting without one would mean a uniqueness constraint that quietly '
                . 'constrains nothing.'
            );
        }

        $active = (string) (getenv('SMARTPRS_ENC_ACTIVE_KEY') ?: array_key_last($keys) ?? '');

        return new self($keys, self::decodeKey($indexKey, 'SMARTPRS_BLIND_INDEX_KEY'), $active);
    }

    /** @return string the self-describing envelope */
    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->keys[$this->activeKeyId],
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed. The value is NOT stored in the clear as a fallback.');
        }

        return implode(':', [
            self::PREFIX,
            $this->activeKeyId,
            base64_encode($iv),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    public function decrypt(string $envelope): string
    {
        $parts = explode(':', $envelope);

        if (count($parts) !== 6 || $parts[0] . ':' . $parts[1] !== self::PREFIX) {
            throw new \InvalidArgumentException(
                'Not an encryption envelope. A stored value that does not decrypt is an error rather than a '
                . 'value returned as-is: returning it would print the ciphertext on a payslip, or worse, '
                . 'print a plaintext somebody stored before the layer was switched on.'
            );
        }

        $keyId = $parts[2];

        if (!isset($this->keys[$keyId])) {
            throw new \RuntimeException(
                "This value was encrypted under key '{$keyId}', which this process does not hold. Rotation "
                . 'keeps old keys available precisely so that this is a configuration error somebody fixes, '
                . 'rather than data nobody can read.'
            );
        }

        $plaintext = openssl_decrypt(
            base64_decode($parts[5], true) ?: '',
            self::CIPHER,
            $this->keys[$keyId],
            OPENSSL_RAW_DATA,
            base64_decode($parts[3], true) ?: '',
            base64_decode($parts[4], true) ?: '',
        );

        if ($plaintext === false) {
            // GCM is authenticated: a failure here means the ciphertext or the
            // tag was altered. That is tampering, and it is reported as such.
            throw new \RuntimeException(
                'Authenticated decryption failed: the stored value or its tag has been altered since it was '
                . 'written. This is not a corrupt row to be skipped.'
            );
        }

        return $plaintext;
    }

    /**
     * The deterministic, keyed lookup handle.
     *
     * The value is NORMALISED first, because the index only works if two
     * spellings of one thing produce one index: `ABCDE1234F` and
     * `abcde 1234 f` are the same PAN, and an index that disagrees makes the
     * uniqueness constraint miss the duplicate it exists to catch.
     */
    public function blindIndex(string $value): string
    {
        return hash_hmac('sha256', self::normalise($value), $this->indexKey);
    }

    /** Upper-cased, with every separator removed. */
    public static function normalise(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value) ?? $value);
    }

    /** Whether a stored value is already an envelope, so a re-encrypt is not a double-encrypt. */
    public static function isEnvelope(string $value): bool
    {
        return str_starts_with($value, self::PREFIX . ':');
    }

    private static function decodeKey(string $encoded, string $name): string
    {
        $key = base64_decode(trim($encoded), true);

        if ($key === false) {
            throw new \RuntimeException("{$name} is not valid base64. A key that does not decode is not a key.");
        }

        return $key;
    }
}
