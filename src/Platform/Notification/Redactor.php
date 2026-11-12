<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Notification;

/**
 * WHAT NEVER REACHES A MESSAGE LOG — Part XXXII Phase 8.
 *
 * *"No OTP, password or full sensitive identifier is persisted in a message
 * log."*
 *
 * The message itself goes to the recipient in full. What is stored afterwards is
 * the redacted form, because a message log is the least-guarded copy of every
 * credential the system ever sent: it is read by support, exported for
 * debugging, included in diagnostic bundles, and retained long after the OTP it
 * contains stopped mattering to anyone except an attacker.
 *
 * REDACTION IS APPLIED ON THE WAY INTO THE LOG, not on the way out of it. A log
 * that stores the raw text and masks it at render time is a log whose database
 * still holds every password anyone was ever emailed, and the masking is one
 * forgotten query away from irrelevant.
 *
 * The identifier patterns keep the LAST FOUR digits, which is the shape a support
 * agent needs to confirm they are looking at the right record and the shape an
 * attacker cannot use.
 */
final class Redactor
{
    /**
     * Patterns are ordered longest-first so a longer identifier is not
     * half-consumed by a shorter pattern.
     *
     * @var array<string, array{pattern: string, replace: callable|string}>
     */
    private const RULES = [
        // A 12-digit national identifier, however it is spaced or hyphenated.
        'identifier_12' => ['pattern' => '/\b(\d[ -]?){11}\d\b/'],
        // A bank account number: 9 to 18 digits.
        'account' => ['pattern' => '/\b\d{9,18}\b/'],
        // A 10-character tax identifier: five letters, four digits, a letter.
        'tax_id' => ['pattern' => '/\b[A-Z]{5}\d{4}[A-Z]\b/'],
    ];

    /** Anything after these words is a secret, whatever it looks like. */
    private const SECRET_LABELS = ['otp', 'password', 'passcode', 'pin', 'token', 'secret', 'api key'];

    public static function redact(string $message): string
    {
        // Labelled secrets first: an OTP is six digits, and so is a lot of
        // harmless text, so the LABEL is what makes it identifiable.
        foreach (self::SECRET_LABELS as $label) {
            $message = (string) preg_replace(
                '/\b' . preg_quote($label, '/') . '\b(\s*(?:is|:|=)?\s*)([A-Za-z0-9._\-]{3,})/i',
                $label . '$1[REDACTED]',
                $message,
            );
        }

        foreach (self::RULES as $rule) {
            $message = (string) preg_replace_callback(
                $rule['pattern'],
                static function (array $m): string {
                    $digits = preg_replace('/\D/', '', $m[0]) ?? '';

                    // Keep the last four: enough for a support agent to confirm
                    // the right record, not enough for anyone to use.
                    return strlen($digits) >= 4
                        ? str_repeat('•', max(0, strlen($m[0]) - 4)) . substr($digits, -4)
                        : '[REDACTED]';
                },
                $message,
            );
        }

        return $message;
    }

    /** Would storing this text leak something? Used by the gate, and by tests. */
    public static function leaks(string $stored): bool
    {
        return $stored !== self::redact($stored);
    }
}
