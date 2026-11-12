<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * IDENTIFIER CHECKSUM ALGORITHMS — Part X §X.3.2 #31.
 *
 * The split here is the one Law 1 asks for and it is worth stating, because
 * "no business literal in code" could be read as forbidding this file.
 *
 *   WHICH algorithm applies to WHICH identifier is CONFIGURATION. It lives in
 *   the field definition's constraints — an Aadhaar field declares
 *   `checksum: verhoeff`, a PAN field declares `checksum: pan_structure`, and a
 *   tenant that does not want Aadhaar checksummed simply does not declare it.
 *
 *   The algorithms THEMSELVES are code, because Verhoeff's dihedral tables are
 *   not a business rule anybody will edit. They are arithmetic, they are the
 *   same in every country that uses them, and expressing them as configuration
 *   would be configuration nobody can read and nobody can validate.
 *
 * A checksum is a TYPO CATCHER, not an authenticity check. A number that passes
 * Verhoeff is a number somebody could have made up; what it is not is a number
 * somebody mistyped. Saying so here matters because the alternative reading —
 * "it passed the checksum so it is a real Aadhaar" — is how a system stops
 * asking for the document.
 *
 * SmartPRS 2 validates none of these. Its identifier columns take whatever was
 * typed, so a transposed digit is discovered by the statutory filing being
 * rejected, months later, with a penalty attached.
 */
final class Checksum
{
    /** Which algorithms exist. A field naming one that is not here is a publish error. */
    public const ALGORITHMS = ['none', 'verhoeff', 'pan_structure', 'luhn', 'mod11'];

    /** Verhoeff's multiplication table over the dihedral group D5. */
    private const D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    /** The permutation table, applied by position. */
    private const P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    /** The inverse table, for generating a check digit rather than verifying one. */
    private const INV = [0, 4, 3, 2, 1, 5, 6, 7, 8, 9];

    /**
     * Verify a value under a declared algorithm.
     *
     * @return string|null the failure in words, or null when it holds
     */
    public static function verify(string $value, string $algorithm): ?string
    {
        $normalised = Encryption::normalise($value);

        return match ($algorithm) {
            'none' => null,
            'verhoeff' => self::verhoeff($normalised)
                ? null
                : 'the check digit does not match the rest of the number, which usually means a digit was '
                    . 'transposed or mistyped. A checksum catches typos; it does not prove the number is real.',
            'pan_structure' => self::panStructure($normalised),
            'luhn' => self::luhn($normalised)
                ? null
                : 'the check digit does not match (Luhn).',
            'mod11' => self::mod11($normalised)
                ? null
                : 'the check digit does not match (mod-11).',
            default => throw new \InvalidArgumentException(
                "'{$algorithm}' is not a checksum algorithm. One of: " . implode(', ', self::ALGORITHMS) . '. '
                . 'Which algorithm applies to which identifier is configuration; the algorithms themselves are '
                . 'arithmetic and live in code.'
            ),
        };
    }

    /** The 12-digit Verhoeff check used by Aadhaar. */
    public static function verhoeff(string $digits): bool
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            return false;
        }

        $c = 0;
        $reversed = strrev($digits);

        for ($i = 0; $i < strlen($reversed); $i++) {
            $c = self::D[$c][self::P[$i % 8][(int) $reversed[$i]]];
        }

        return $c === 0;
    }

    /** The check digit that would make a payload valid — for generating test data, not for fixing input. */
    public static function verhoeffDigit(string $payload): int
    {
        $c = 0;
        $reversed = strrev($payload);

        for ($i = 0; $i < strlen($reversed); $i++) {
            $c = self::D[$c][self::P[($i + 1) % 8][(int) $reversed[$i]]];
        }

        return self::INV[$c];
    }

    /**
     * PAN's structural rule: AAAAA9999A, where the fourth letter is the holder
     * TYPE and the fifth is the first letter of the surname or entity name.
     *
     * There is no check digit, so this is a shape test and says so. The fourth
     * character is checked against the published set because `AAAAA1234A` with a
     * `Z` there is not a PAN that exists, and catching it at entry is the whole
     * value of the rule.
     */
    public static function panStructure(string $value): ?string
    {
        if (preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $value) !== 1) {
            return 'a PAN is five letters, four digits and a letter (AAAAA9999A).';
        }

        // P individual · C company · H HUF · F firm · A AOP · T trust · B BOI
        // · L local authority · J artificial juridical person · G government
        if (!str_contains('PCHFATBLJG', $value[3])) {
            return "'{$value[3]}' is not a PAN holder type. The fourth character names what kind of taxpayer "
                . 'this is, and there is no check digit to catch a mistyped one afterwards.';
        }

        return null;
    }

    /** Luhn, for card-shaped numbers. */
    public static function luhn(string $digits): bool
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];

            if ($double) {
                $d *= 2;
                $d -= $d > 9 ? 9 : 0;
            }

            $sum += $d;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    /** Weighted mod-11, the shape several Indian bank and registration numbers use. */
    public static function mod11(string $digits): bool
    {
        if (preg_match('/^\d+$/', $digits) !== 1) {
            return false;
        }

        $sum = 0;
        $weight = 2;

        for ($i = strlen($digits) - 2; $i >= 0; $i--) {
            $sum += (int) $digits[$i] * $weight;
            $weight = $weight === 7 ? 2 : $weight + 1;
        }

        $check = (11 - $sum % 11) % 11;

        return $check === 10 ? false : $check === (int) $digits[strlen($digits) - 1];
    }
}
