<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * THE MASK RENDERER — Part X §X.2.6, one of R-X-025's nine artefacts.
 *
 * It exists because the previous commit claimed it did. `AuthoringType` declared
 * all nine artefacts present for twenty-five types while five of the nine had no
 * code behind them, which is precisely the defect R-X-025 is written to prevent
 * and precisely the one this codebase keeps catching in itself: a claim about a
 * check, standing in for the check.
 *
 * Six rules, and the two properties that matter more than the list:
 *
 *   A MASK NEVER LEAKS THE LENGTH of what it hides beyond what the rule says.
 *   `full` on a four-character value and on a forty-character one render the
 *   same, because a mask whose width tracks the secret has told you about the
 *   secret. `last4` deliberately does leak length — it is the rule a bank
 *   statement uses, and hiding the length there would make the affordance
 *   useless — so the choice is per rule and it is declared.
 *
 *   A NULL IS NOT MASKED. An empty field renders as empty, not as `••••`,
 *   because "there is no value here" and "there is a value you may not see" are
 *   different facts and an HR screen that conflates them produces a support
 *   ticket per employee.
 *
 * SmartPRS 2 has no masking layer at all: whatever a query returns is what the
 * template prints, and its exports carry government identifiers in full to
 * whoever clicked the button.
 */
final class Masking
{
    /** What every masked value renders as, regardless of what it hides. */
    private const DOTS = '••••';

    /**
     * Render a value under a rule.
     *
     * @param  string|null  $custom  the replacement for `custom`, which is
     *         configuration rather than code — a tenant that wants "REDACTED"
     *         says so in the field definition
     */
    public static function apply(mixed $value, string $rule, ?string $custom = null): string
    {
        if ($value === null || $value === '') {
            // Not masked. See the class docblock: an absent value is a
            // different fact from a withheld one.
            return '';
        }

        $text = self::text($value);

        return match ($rule) {
            'none' => $text,
            'last4' => self::lastN($text, 4),
            'first_last' => self::firstLast($text),
            'full' => self::DOTS,
            'domain_only' => self::domainOnly($text),
            'custom' => $custom ?? self::DOTS,
            default => throw new \InvalidArgumentException(
                "'{$rule}' is not a masking rule (X.2.6). One of: "
                . implode(', ', FieldDefinition::MASKING_RULES) . '.'
            ),
        };
    }

    /** The rule an effective surface rule resolved to, applied to a stored value. */
    public static function forRule(EffectiveRule $rule, mixed $value, ?string $custom = null): string
    {
        return self::apply($value, $rule->masking, $custom);
    }

    /**
     * `••••3210` — a bank statement's rule, which leaks length on purpose so a
     * person can recognise their own account.
     */
    private static function lastN(string $text, int $n): string
    {
        return mb_strlen($text) <= $n ? self::DOTS : self::DOTS . mb_substr($text, -$n);
    }

    /**
     * `E••••z` — enough to confirm you are looking at the right record and not
     * enough to read it.
     */
    private static function firstLast(string $text): string
    {
        if (mb_strlen($text) <= 2) {
            return self::DOTS;
        }

        return mb_substr($text, 0, 1) . self::DOTS . mb_substr($text, -1);
    }

    /**
     * `••••@ametecsindia.com` — for a contact address, where the domain is the
     * fact somebody is usually checking and the local part is the identity.
     */
    private static function domainOnly(string $text): string
    {
        $at = mb_strrpos($text, '@');

        return $at === false ? self::DOTS : self::DOTS . mb_substr($text, $at);
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof \DateTimeInterface => $value->format(ValueCoercion::FILE_DATE_FORMAT),
            is_array($value) => implode(', ', array_map(self::text(...), $value)),
            is_object($value) && method_exists($value, 'toDecimalString') => $value->toDecimalString(),
            default => (string) $value,
        };
    }
}
