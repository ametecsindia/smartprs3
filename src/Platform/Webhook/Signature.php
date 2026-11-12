<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Webhook;

/**
 * A SIGNATURE THAT COVERS THE TIMESTAMP AND THE NONCE — Part XXIV R-XXIV-074,
 * R-XXIV-075.
 *
 *   signing_string = "v1" . "." . timestamp . "." . nonce . "." . raw_body_bytes
 *   signature      = hex(hmac_sha256(signing_string, secret))
 *
 * The blueprint is unusually direct about why: *"The signature covers the
 * timestamp and nonce BECAUSE SmartPRS 2'S DOES NOT, and its only protection
 * against replay is a natural-key index that is inert wherever `tenant_id` is
 * NULL. This requirement exists because of that finding."*
 *
 * A signature over the body alone authenticates the CONTENT and says nothing
 * about the OCCASION. Anyone who captures one valid delivery can replay it
 * forever, and every replay verifies perfectly, because it is the same body with
 * the same signature — which is exactly what the sender sent.
 *
 * THE `v1=` PREFIX exists so the algorithm can be migrated without breaking
 * every receiver at once. A scheme with no version is a scheme that can never
 * change, and "we will never need to change the hash" has a poor record.
 *
 * COMPARISON IS CONSTANT-TIME at both ends. SmartPRS 2 already does this
 * correctly, and the property is kept — one of the few places this rebuild
 * copies rather than replaces.
 *
 * THE BODY IS SIGNED AS RAW BYTES, never as re-encoded JSON. A receiver that
 * parses and re-serialises before verifying gets a different byte string from
 * the same document — different key order, different whitespace, `1.0` becoming
 * `1` — and the signature fails for a delivery that was never tampered with.
 */
final class Signature
{
    public const VERSION = 'v1';

    public static function sign(string $secret, int $timestamp, string $nonce, string $rawBody): string
    {
        return self::VERSION . '=' . hash_hmac('sha256', self::signingString($timestamp, $nonce, $rawBody),
            $secret);
    }

    /**
     * During a secret rotation overlap both signatures are sent, so a receiver
     * that has taken the new secret and one that has not are both satisfied.
     *
     * @param list<string> $secrets current first, then the previous one
     */
    public static function header(array $secrets, int $timestamp, string $nonce, string $rawBody): string
    {
        return implode(',', array_map(
            static fn (string $secret): string => self::sign($secret, $timestamp, $nonce, $rawBody),
            $secrets,
        ));
    }

    /**
     * The receiver's side of the published contract (R-XXIV-076).
     *
     * @param list<string> $acceptableSecrets during rotation, more than one
     */
    public static function verify(
        string $header,
        array $acceptableSecrets,
        int $timestamp,
        string $nonce,
        string $rawBody,
        int $now,
        int $toleranceSeconds = 300,
    ): bool {
        // Freshness FIRST. A signature that verifies is not a delivery that is
        // current, and checking the signature of a five-day-old replay before
        // noticing its age is work done in the wrong order.
        if (abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            foreach ($acceptableSecrets as $secret) {
                $expected = self::sign($secret, $timestamp, $nonce, $rawBody);

                // Constant-time. A comparison that returns early leaks how much
                // of the signature was right, one byte at a time.
                if (hash_equals($expected, trim($candidate))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function signingString(int $timestamp, string $nonce, string $rawBody): string
    {
        return self::VERSION . '.' . $timestamp . '.' . $nonce . '.' . $rawBody;
    }

    /** At least 256 bits, generated server-side (R-XXIV-093). */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }
}
