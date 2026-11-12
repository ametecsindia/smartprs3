<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * THE SIGNATURE, AND EVERY REASON TO REFUSE — Part XVII §XVII.4.6, R-XVII-009,
 * R-XVII-026, R-XVII-027.
 *
 * Ed25519 detached signature over `SHA-256(canonical(manifest) ‖ content_hash)`,
 * verified at import, again at activation, and the content hash re-verified
 * every time the pack is loaded into the resolver cache. Three points, because a
 * pack verified once and cached forever is a pack whose file can be edited after
 * the check.
 *
 * TWO SIGNATURES BY TWO DIFFERENT KEYS. Four-eyes on statutory content is the
 * same control as maker-checker on payroll: one person must not be able to ship
 * law. A countersignature by the same key is one person signing twice, which is
 * why the distinctness is asserted here rather than assumed of the pipeline.
 *
 * THERE IS NO `$force` PARAMETER, no `$skipVerification` flag, and no branch in
 * this class that can be reached with verification disabled. R-XVII-027 is
 * absolute: no configuration, permission, licence state, environment variable or
 * support-mode flag may disable signature verification, in any edition, on any
 * deployment, for any customer. An option that exists is an option somebody
 * eventually sets in production to get a payroll out on the 30th.
 */
final class PackVerifier
{
    public function __construct(
        private readonly KeySet $keys,
        private readonly string $platformVersion,
    ) {
    }

    /**
     * @param bool $forActivation revocation refuses a future activation but never
     *        makes an already-activated pack unreadable (R-XVII-120)
     * @throws PackRejected with EVERY reason
     */
    public function verify(RulePack $pack, bool $forActivation = true): void
    {
        $problems = [];
        $manifest = $pack->manifest;

        // 1. The content is what the manifest says it is.
        $computed = $pack->computedContentHash();

        if (!hash_equals($manifest->contentHash(), $computed)) {
            $problems[] = 'the content hash does not match the canonicalised body (manifest says '
                . substr($manifest->contentHash(), 0, 16) . '…, content hashes to ' . substr($computed, 0, 16)
                . '…) — the pack has been altered, or was never canonicalised the way it claims';
        }

        // 2. This build can evaluate it at all.
        if (version_compare($this->platformVersion, $manifest->minPlatformVersion(), '<')) {
            $problems[] = "this build is {$this->platformVersion} and the pack needs at least "
                . $manifest->minPlatformVersion() . '. A pack using a function the build lacks must not '
                . 'half-apply';
        }

        $max = $manifest->maxPlatformVersion();

        if ($max !== null && version_compare($this->platformVersion, $max, '>')) {
            $problems[] = "this build is {$this->platformVersion} and the pack declares a maximum of {$max}";
        }

        // 3. Two signatures, two different keys, both verifying.
        if ($manifest->keyId() === $manifest->countersignKeyId()) {
            $problems[] = 'the signature and countersignature name the same key (' . $manifest->keyId()
                . '). Four-eyes signed twice by one pair of eyes is one pair of eyes';
        }

        $signed = hash('sha256', Jcs::canonicalise($manifest->signablePart()) . $manifest->contentHash(), true);

        foreach ([
            ['signature', $manifest->keyId(), $manifest->signature()],
            ['countersignature', $manifest->countersignKeyId(), $manifest->countersignature()],
        ] as [$what, $keyId, $signature]) {
            if (!$this->keys->has($keyId)) {
                $problems[] = "the {$what} names key '{$keyId}', which this installation does not hold";

                continue;
            }

            if ($forActivation && $this->keys->isRevoked($keyId)) {
                $problems[] = "the {$what} key '{$keyId}' is REVOKED ("
                    . $this->keys->revocationReason($keyId) . '), so this pack cannot be activated — '
                    . 'already-activated packs signed by it stay readable and are marked signing_key_revoked, '
                    . 'because revocation must not make three years of payslips unexplainable';

                continue;
            }

            $raw = base64_decode($signature, true);

            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES) {
                $problems[] = "the {$what} is not a well-formed Ed25519 signature";

                continue;
            }

            if (!sodium_crypto_sign_verify_detached($raw, $signed, $this->keys->publicKey($keyId))) {
                $problems[] = "the {$what} does not verify against key '{$keyId}'";
            }
        }

        if ($problems !== []) {
            throw new PackRejected($problems, $manifest->packKey());
        }
    }

    /**
     * The cheap re-check on every load into the resolver cache (R-XVII-026).
     *
     * @throws PackRejected
     */
    public function verifyContentHash(RulePack $pack): void
    {
        if (!hash_equals($pack->manifest->contentHash(), $pack->computedContentHash())) {
            throw new PackRejected(
                ['the content hash no longer matches on load — the stored pack has changed since it was '
                    . 'verified, which is the case a verify-once-and-cache design cannot see'],
                $pack->manifest->packKey(),
            );
        }
    }

    /**
     * Sign a pack, for tests and for the Ametecs-side pipeline's reference
     * implementation. The private keys here are caller-supplied on purpose: in
     * production they live in an HSM or KMS and the signing service holds no
     * exportable key material, so there is no method on this class that could
     * hold one.
     */
    public static function sign(array $manifest, array $content, string $secretKey, string $counterSecret): array
    {
        $manifest['integrity']['content_hash'] = Jcs::hash($content);
        $signable = $manifest;
        unset($signable['integrity']['signature'], $signable['integrity']['countersignature']);

        $digest = hash('sha256', Jcs::canonicalise($signable) . $manifest['integrity']['content_hash'], true);

        $manifest['integrity']['signature'] = base64_encode(sodium_crypto_sign_detached($digest, $secretKey));
        $manifest['integrity']['countersignature'] =
            base64_encode(sodium_crypto_sign_detached($digest, $counterSecret));

        return $manifest;
    }
}
