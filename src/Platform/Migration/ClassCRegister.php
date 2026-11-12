<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Migration;

/**
 * THE DEFECTS WHOSE CORRECTION PRODUCES AN EXPECTED DIFFERENCE — Part XXIX
 * §XXIX.7.3, R-XXIX-105 and R-XXIX-106.
 *
 * "This class exists because without it the reconciliation report would record
 * the rebuild's central purpose as a failure."
 *
 * Each entry names the SmartPRS 2 defect, its citation, and — the field that
 * does the work — the DIRECTION the correction predicts. R-XXIX-106 clause 2:
 * *an LOP correction can only reduce net pay; if it increases net pay, the
 * classification is wrong and it is an unexplained difference.* Without the
 * declared direction, "Class C" becomes the box every unexplained number gets
 * filed in, and the reconciliation stops meaning anything.
 *
 * Four corrections are marked as depending on an unresolved compliance ruling
 * (R-XXIX-108). They may not be applied until the ruling exists — and the
 * platform cannot rule on them, so `blockedOnRuling()` is what a migration reads
 * rather than a decision somebody makes in a spreadsheet.
 */
final class ClassCRegister
{
    public const DECREASES = 'decreases';
    public const INCREASES = 'increases';
    public const BOTH = 'both';

    /** @var array<string, array{defect: string, citation: string, direction: string, ruling: bool}> */
    public const ENTRIES = [
        'C-LOP' => ['defect' => 'Loss-of-Pay leave paid in full', 'citation' => 'DEF-LVE-02',
            'direction' => self::DECREASES, 'ruling' => false],
        'C-LOAN' => ['defect' => 'Loan EMI never recovered', 'citation' => 'DEF-P02',
            'direction' => self::DECREASES, 'ruling' => false],
        'C-LOAN-OVER' => ['defect' => 'Loan EMI recovered forever', 'citation' => 'DEF-P02b',
            'direction' => self::INCREASES, 'ruling' => false],
        'C-NIGHT' => ['defect' => 'Cross-midnight shift counted as two days', 'citation' => 'B-Q2',
            'direction' => self::DECREASES, 'ruling' => false],
        'C-COMM' => ['defect' => 'Commissions stranded by the enum mismatch', 'citation' => 'DEF-P03',
            'direction' => self::INCREASES, 'ruling' => false],
        'C-ESI-OT' => ['defect' => 'Overtime escapes the ESI base', 'citation' => 'OQ-3',
            'direction' => self::DECREASES, 'ruling' => true],
        'C-ARREARS-TAX' => ['defect' => 'Arrears forced to tds_rate = 0', 'citation' => 'OQ-10',
            'direction' => self::DECREASES, 'ruling' => true],
        'C-PROBATION' => ['defect' => 'PF/PT/TDS suppressed for probationers and interns',
            'citation' => 'OQ-4', 'direction' => self::DECREASES, 'ruling' => true],
        'C-PF-APPL' => ['defect' => 'pf_applicable/esi_applicable populated and ignored',
            'citation' => 'F-12.2', 'direction' => self::BOTH, 'ruling' => true],
        'C-EPS' => ['defect' => 'EPS is Rs 0.50 wrong at the ceiling', 'citation' => 'A-9',
            'direction' => self::BOTH, 'ruling' => false],
        'C-ZERO-PUNCH' => ['defect' => 'Zero punches counted as full pay', 'citation' => 'OQ-1',
            'direction' => self::DECREASES, 'ruling' => false],
        'C-SANDWICH' => ['defect' => 'The sandwich rule cannot fire across LOP leave', 'citation' => 'E-7A',
            'direction' => self::DECREASES, 'ruling' => false],
        'C-DEDUP' => ['defect' => 'Duplicate employee records merged', 'citation' => 'RC-01',
            'direction' => self::BOTH, 'ruling' => false],
        'C-GEOFENCE' => ['defect' => 'Coordinates truncated to +/-557m', 'citation' => 'PO-16',
            'direction' => self::BOTH, 'ruling' => false],
    ];

    public static function knows(string $code): bool
    {
        return isset(self::ENTRIES[$code]);
    }

    /** Does a difference of this sign match what the correction PREDICTS? */
    public static function directionHolds(string $code, int $deltaMinor): bool
    {
        $direction = self::ENTRIES[$code]['direction'] ?? null;

        return match ($direction) {
            self::DECREASES => $deltaMinor < 0,
            self::INCREASES => $deltaMinor > 0,
            self::BOTH => $deltaMinor !== 0,
            default => false,
        };
    }

    public static function blockedOnRuling(string $code): bool
    {
        return (bool) (self::ENTRIES[$code]['ruling'] ?? false);
    }

    public static function citation(string $code): string
    {
        return self::ENTRIES[$code]['citation'] ?? '';
    }

    /** @return list<string> */
    public static function awaitingRuling(): array
    {
        return array_keys(array_filter(self::ENTRIES, static fn (array $e): bool => $e['ruling']));
    }
}
