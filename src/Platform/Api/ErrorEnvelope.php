<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Api;

/**
 * ONE ENVELOPE, FOR EVERY NON-2xx — Part XXIV §XXIV.2.9.
 *
 * "Every non-2xx response from every endpoint, WITHOUT EXCEPTION, including
 * framework-level failures (404, 405, 415, 429, 500)." The exception list is
 * where this normally goes wrong: an application shapes its own errors carefully
 * and then the framework's 404 and 500 come out in a different form, so a client
 * has to parse two shapes and gets the second one at the worst moment.
 *
 * Four rules, each carrying a SmartPRS 2 defect:
 *
 *   `code` COMES FROM A CLOSED CATALOGUE. Clients branch on `code`, never on
 *   `message`. SmartPRS 2 returns raw exception messages from 194 sites,
 *   disclosing schema and file paths to any authenticated caller — so `message`
 *   here is human-facing, may be localised, and carries no internal detail.
 *
 *   `retryable` IS A CONTRACT, NOT A HINT. True only where a byte-identical
 *   retry with the same idempotency key is safe. A client that retries a 422
 *   because a field said "maybe" is a client that submits twice.
 *
 *   A 500 CARRIES A REQUEST ID AND NOTHING ELSE. No message, no trace, ever.
 *
 *   404 AND "OUTSIDE YOUR TENANT" ARE THE SAME RESPONSE, by design. A 403 for a
 *   record that exists in another tenant confirms it exists, which is an
 *   enumeration oracle wearing an access-control label.
 */
final class ErrorEnvelope
{
    /** Retryable ONLY where a byte-identical retry is safe. */
    private const RETRYABLE_STATUSES = [429, 502, 503, 504];
    private const RETRYABLE_CODES = ['idempotency.in_progress'];

    /** @param list<array{field: string, code: string, message: string, value_seen?: string}> $details */
    public static function build(
        string $code,
        int $status,
        string $message,
        string $requestId,
        string $correlationId,
        array $details = [],
    ): array {
        if (!ErrorCatalogue::knows($code)) {
            throw new \LogicException(
                "'{$code}' is not in the published error catalogue. Codes are a closed, versioned "
                . 'contract: adding one is additive, removing or repurposing one is breaking, and an '
                . 'ad-hoc code is a code no client can branch on.'
            );
        }

        if (ErrorCatalogue::statusFor($code) !== $status) {
            throw new \LogicException(
                "'{$code}' is published as HTTP " . ErrorCatalogue::statusFor($code)
                . " and was returned as {$status}. A code whose status varies by call site is a code a "
                . 'client cannot handle.'
            );
        }

        // A 500 says nothing but its request id. Not a sanitised message, not a
        // class name — an internal detail that reaches a caller is one an
        // attacker reads, and every 500 message is written under pressure.
        if ($status >= 500) {
            return ['error' => [
                'code' => $code,
                'message' => 'An unexpected error occurred.',
                'status' => $status,
                'request_id' => $requestId,
                'correlation_id' => $correlationId,
                'documentation_url' => ErrorCatalogue::documentationUrl($code),
                'details' => [],
                'retryable' => self::retryable($code, $status),
            ]];
        }

        foreach ($details as $detail) {
            foreach (['field', 'code', 'message'] as $required) {
                if (trim((string) ($detail[$required] ?? '')) === '') {
                    throw new \LogicException(
                        "A validation detail is missing '{$required}'. A detail without a field cannot be "
                        . 'shown next to the input that caused it, which is the only reason details exist.'
                    );
                }
            }
        }

        return ['error' => [
            'code' => $code,
            'message' => $message,
            'status' => $status,
            'request_id' => $requestId,
            'correlation_id' => $correlationId,
            'documentation_url' => ErrorCatalogue::documentationUrl($code),
            'details' => array_values($details),
            'retryable' => self::retryable($code, $status),
        ]];
    }

    public static function retryable(string $code, int $status): bool
    {
        return in_array($status, self::RETRYABLE_STATUSES, true)
            || in_array($code, self::RETRYABLE_CODES, true);
    }

    /**
     * Does a payload leak something a caller must never see?
     *
     * Used by the contract gate, and by anything that renders an error: the list
     * is the shape of SmartPRS 2's 194 raw-exception sites.
     *
     * @return list<string>
     */
    public static function leaks(array $envelope): array
    {
        $text = json_encode($envelope, JSON_THROW_ON_ERROR);
        $found = [];

        foreach ([
            '/\/(var|home|usr|opt)\/[a-z]/i' => 'a filesystem path',
            '/\.php\b/' => 'a source file name',
            '/\b(SQLSTATE|SELECT\s|INSERT\s|UPDATE\s|Exception|Stack trace)\b/i' => 'an internal detail',
            '/\bline \d+/i' => 'a line number',
        ] as $pattern => $what) {
            if (preg_match($pattern, $text) === 1) {
                $found[] = $what;
            }
        }

        return $found;
    }
}
