<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE CTC IN FORCE ON A DATE (8 Oct, owner: an appraisal "wired at ALL places
 * application wide for calculations, updating the salary").
 *
 * An appraisal (Performance Appraisal → Apply, or the Increment / Appraisal
 * import) writes the NEW CTC onto the employee straight away and logs an
 * `Effected` row in the `increments` register carrying old CTC, new CTC and the
 * effective date. The employee record is therefore always today's CTC; a month
 * that ends BEFORE an increment's effective date must still be paid on the CTC
 * that increment replaced. That is the only correction made here: a later
 * effective increment → its old_ctc; otherwise the record as it stands.
 *
 * ponytail: reads only `Effected` rows that carry old_ctc and an effective
 * date; a hand-typed register row without them changes nothing (the register
 * never updated the employee's CTC by itself).
 */
final class Increments
{
    public const TABLE = 'increments';

    /** Performance Appraisal granularity → the register's own Cycle choice. */
    public const CYCLE_OF = ['yearly' => 'Annual', 'half_year' => 'Half-yearly', 'quarter' => 'Quarterly', 'month' => 'Special / Market correction'];

    /**
     * @param array<string, mixed> $employee
     * @param list<array<string, mixed>> $increments this employee's register rows (any order)
     */
    public static function ctcOn(array $employee, array $increments, string $date): string
    {
        $later = null;

        foreach ($increments as $i) {
            $eff = (string) ($i['effective'] ?? '');
            $old = trim((string) ($i['old_ctc'] ?? ''));

            if ((string) ($i['status'] ?? '') !== 'Effected' || $eff === '' || $old === '' || substr($eff, 0, 10) <= $date) {
                continue;
            }

            if ($later === null || [substr($eff, 0, 10), (int) ($i['id'] ?? 0)] < [substr((string) $later['effective'], 0, 10), (int) ($later['id'] ?? 0)]) {
                $later = $i;
            }
        }

        return $later === null ? Employment::ctc($employee) : Amount::of((string) $later['old_ctc'])->toDecimalString();
    }

    /**
     * Every employee row with `ctc` set to what was in force on $date.
     *
     * @param list<array<string, mixed>> $employees
     * @param list<array<string, mixed>> $increments the whole register
     * @return list<array<string, mixed>>
     */
    public static function inForce(array $employees, array $increments, string $date): array
    {
        $byEmployee = [];

        foreach ($increments as $i) {
            $byEmployee[(string) ($i['employee_id'] ?? '')][] = $i;
        }

        foreach ($employees as $k => $e) {
            if (isset($byEmployee[(string) ($e['id'] ?? '')])) {
                $employees[$k]['ctc'] = self::ctcOn($e, $byEmployee[(string) $e['id']], $date);
            }
        }

        return $employees;
    }
}
