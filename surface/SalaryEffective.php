<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHEN THE SALARY STRUCTURE AN EMPLOYEE IS ON TOOK EFFECT (owner, 15 Oct).
 *
 * - From the start of pay: the date of joining — or, when the EMPLOYEE has induction training dates (Directory →
 *   Training start / end date) and their company does not pay for training (Organization Configuration →
 *   Companies → Paid during training = No), the date of production: the first working day after training.
 * - Revised by every effected appraisal / increment (its effective date) and by probation confirmation
 *   (PF, ESIC, PT… that wait on confirmation start then).
 * The latest of those on or before the date asked about is the structure's effective date. Payroll, the
 * Salary Breakup, the offer / increment letters and their salary-structure annexure all read this.
 */
final class SalaryEffective
{
    /**
     * The first day the employee is paid.
     *
     * @param array<string, mixed>|null $company the employee's company row
     * @param \Closure(string): bool|null $isWorkingDay
     * @return array{date: string, why: string, training: ?array{from: string, to: string, paid: bool}, production: string}
     */
    public static function payStart(array $employee, ?array $company, ?\Closure $isWorkingDay = null): array
    {
        $doj = self::day((string) ($employee['doj'] ?? ''));
        $training = self::training($employee, $company);

        if ($training === null) {
            return ['date' => $doj, 'why' => 'Date of joining', 'training' => null, 'production' => $doj];
        }

        $production = self::nextWorkingDay($training['to'], $isWorkingDay);

        if ($training['paid']) {
            return ['date' => $doj, 'why' => 'Date of joining (training paid)', 'training' => $training, 'production' => $production];
        }

        return ['date' => $production, 'why' => 'Date of production — first working day after unpaid training (' . Dates::dmy($training['from']) . ' – ' . Dates::dmy($training['to']) . ')', 'training' => $training, 'production' => $production];
    }

    /**
     * THE EMPLOYEE'S INDUCTION TRAINING (owner, 15 Oct): their own dates on the Directory; whether it is paid,
     * their company's policy. Null when they have no training end date — or dates that cannot be right
     * (ending before they joined, or before it starts).
     *
     * @return array{from: string, to: string, paid: bool}|null
     */
    public static function training(array $employee, ?array $company): ?array
    {
        $doj = self::day((string) ($employee['doj'] ?? ''));
        $from = self::day((string) ($employee['training_start_date'] ?? ''));
        $to = self::day((string) ($employee['training_end_date'] ?? ''));
        $from = $from !== '' ? $from : $doj;

        if ($to === '' || ($from !== '' && $to < $from) || ($doj !== '' && $to < $doj)) {
            return null;
        }

        return ['from' => $from, 'to' => $to, 'paid' => strcasecmp(trim((string) ($company['paid_during_training'] ?? 'Yes')), 'No') !== 0];
    }

    /** DATE OF PRODUCTION (owner, 15 Oct): the first working / business day after training ends; with no training, the date of joining. */
    public static function production(array $employee, ?array $company, ?\Closure $isWorkingDay = null): string
    {
        $t = self::training($employee, $company);

        return $t === null ? self::day((string) ($employee['doj'] ?? '')) : self::nextWorkingDay($t['to'], $isWorkingDay);
    }

    /**
     * TENURE (owner, 15 Oct), counted from the date the company chose (Organization Configuration →
     * Companies → Tenure counted from: Date of joining / Date of production / Date of confirmation) to
     * today, or to the exit date for someone who has left. `text` is "2 yrs 3 mths", "4 mths", "12 days";
     * '' with `why` when the start date is not there yet (e.g. not confirmed).
     *
     * @return array{basis: string, from: string, to: string, text: string, why: string, months: int}
     */
    public static function tenure(array $employee, ?array $company, ?\Closure $isWorkingDay, string $today): array
    {
        $basis = trim((string) ($company['tenure_basis'] ?? '')) ?: 'Date of joining';
        $from = match ($basis) {
            'Date of production' => self::production($employee, $company, $isWorkingDay),
            'Date of confirmation' => self::day((string) ($employee['confirmation_date'] ?? '')),
            default => self::day((string) ($employee['doj'] ?? '')),
        };
        $exit = self::day((string) ($employee['exit_date'] ?? ''));
        $to = $exit !== '' && $exit < $today ? $exit : $today;
        $out = ['basis' => $basis, 'from' => $from, 'to' => $to, 'text' => '', 'why' => '', 'months' => 0];

        if ($from === '') {
            $out['why'] = $basis === 'Date of confirmation' ? 'Not confirmed yet' : 'No date on record';

            return $out;
        }

        if ($from > $to) {
            $out['why'] = 'Starts ' . Dates::dmy($from);

            return $out;
        }

        $d = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to));
        $out['months'] = $d->y * 12 + $d->m;
        $out['text'] = $d->y > 0 || $d->m > 0
            ? trim(($d->y > 0 ? $d->y . ' yr' . ($d->y === 1 ? '' : 's') : '') . ' ' . ($d->m > 0 ? $d->m . ' mth' . ($d->m === 1 ? '' : 's') : ''))
            : $d->days . ' day' . ($d->days === 1 ? '' : 's');

        return $out;
    }

    private static function nextWorkingDay(string $after, ?\Closure $isWorkingDay): string
    {
        $next = $after;
        for ($i = 0; $i < 31; $i++) {
            $next = date('Y-m-d', (int) strtotime($next . ' +1 day'));
            if ($isWorkingDay === null ? date('N', (int) strtotime($next)) !== '7' : $isWorkingDay($next)) { break; }
        }

        return $next;
    }

    /**
     * The structure in force on $asOf and every event that set it, oldest first.
     *
     * @param list<array<string, mixed>> $increments this employee's `increments` rows
     * @return array{date: string, why: string, history: list<array{date: string, why: string}>, pay_start: array}
     */
    public static function on(array $employee, ?array $company, array $increments, ?\Closure $isWorkingDay, string $asOf): array
    {
        $start = self::payStart($employee, $company, $isWorkingDay);
        $events = $start['date'] === '' ? [] : [['date' => $start['date'], 'why' => $start['why']]];

        foreach ($increments as $i) {
            $eff = self::day((string) ($i['effective'] ?? ''));
            if ((string) ($i['status'] ?? '') === 'Effected' && $eff !== '' && $eff <= $asOf) {
                $new = trim((string) ($i['new_ctc'] ?? ''));
                $events[] = ['date' => $eff, 'why' => 'Appraisal / increment' . ($new !== '' ? ' — CTC ' . \SmartPRS\Platform\Support\Money::of(Amount::of($new)->toDecimalString())->format() : '')];
            }
        }

        $confirmed = self::day((string) ($employee['confirmation_date'] ?? ''));
        if ($confirmed !== '' && $confirmed <= $asOf) {
            $events[] = ['date' => $confirmed, 'why' => 'Probation confirmed — PF, ESIC, PT… applied from here'];
        }

        usort($events, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
        $last = $events === [] ? ['date' => '', 'why' => 'No date of joining on record'] : $events[count($events) - 1];

        return ['date' => $last['date'], 'why' => $last['why'], 'history' => $events, 'pay_start' => $start];
    }

    private static function day(string $v): string
    {
        $v = substr(trim($v), 0, 10);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && $v !== '0000-00-00' ? $v : '';
    }
}
