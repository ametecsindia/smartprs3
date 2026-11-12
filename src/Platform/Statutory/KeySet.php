<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * THE VERIFICATION KEYS AN INSTALLATION HOLDS — Part XVII §XVII.4.6.
 *
 * A SET, not a key. "A pack signed in 2026 must still verify in 2031", so
 * rotation adds a key rather than replacing one, and an install that has not
 * updated in a year is not locked out of statutory content.
 *
 * Revocation is deliberately asymmetric, and the asymmetry is the whole design:
 * a revoked key stops verifying packs for FUTURE activation, but an
 * already-activated pack stays readable and is marked `signing_key_revoked`
 * (R-XVII-120). Making revocation retroactive would render three years of
 * payslips unexplainable at the moment a key is compromised — turning an
 * incident into an audit failure across every install at once.
 *
 * There is no method here to disable verification, and none to add a key at
 * runtime from configuration. R-XVII-027: no configuration, permission, licence
 * state, environment variable or support-mode flag may disable signature
 * verification, in any edition, on any deployment, for any customer.
 */
final class KeySet
{
    /** @var array<string, string> key id => 32-byte Ed25519 public key */
    private array $keys = [];

    /** @var array<string, string> key id => reason it was revoked */
    private array $revoked = [];

    /** @param array<string, string> $keys key id => raw public key */
    public function __construct(array $keys = [])
    {
        foreach ($keys as $id => $key) {
            $this->add((string) $id, $key);
        }
    }

    public function add(string $keyId, string $publicKey): void
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \InvalidArgumentException(
                "Key '{$keyId}' is " . strlen($publicKey) . ' bytes, not '
                . SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES . '. A key of the wrong length verifies nothing, and a '
                . 'verifier that holds one fails open the first time it is asked.'
            );
        }

        $this->keys[$keyId] = $publicKey;
    }

    public function revoke(string $keyId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A key revoked for no stated reason cannot be reviewed.');
        }

        $this->revoked[$keyId] = $reason;
    }

    public function has(string $keyId): bool
    {
        return isset($this->keys[$keyId]);
    }

    public function isRevoked(string $keyId): bool
    {
        return isset($this->revoked[$keyId]);
    }

    public function revocationReason(string $keyId): ?string
    {
        return $this->revoked[$keyId] ?? null;
    }

    public function publicKey(string $keyId): string
    {
        return $this->keys[$keyId] ?? throw new \InvalidArgumentException(
            "No verification key '{$keyId}' is held by this installation. A pack signed by a key the "
            . 'install does not have is a pack it cannot verify, which is a refusal rather than a warning.'
        );
    }

    /** @return list<string> */
    public function keyIds(): array
    {
        return array_keys($this->keys);
    }
}
