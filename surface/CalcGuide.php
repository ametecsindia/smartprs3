<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Payroll\SalaryComponent;

/**
 * THE SALARY CALCULATION GUIDE, READ FROM THE LIVE CONFIGURATION.
 *
 * SmartPRS 2's guide was prose typed into a controller — worked examples with
 * "26 days" and "12%" in them that stopped being true the day a customer
 * changed a setting. This one has no number of its own: every figure it quotes
 * is read, at request time, from the file or row payroll itself reads, and each
 * point names where it came from. Change the configuration and the guide
 * changes with it; there is nothing to keep in step.
 *
 * Returns SECTIONS as data (id, title, source, points, example) so the screen
 * prints and the test asserts the same thing.
 */
final class CalcGuide
{
    /**
     * @param list<array<string, mixed>> $payCycleRules `$payCycles->rules()`
     * @param array<string, mixed> $structureVersion `$structure->versionOn($date)`
     * @param list<array<string, mixed>> $latePolicies live `late_policy` rows
     * @param array<string, object> $packsByHead `$statutory->packsByHead($asOf)` — head => RulePack
     */
    public function __construct(
        private readonly string $configDir,
        private readonly string $asOf,
        private readonly array $structureVersion,
        private readonly string $costBasis,
        private readonly array $payCycleRules,
        private readonly array $payCycleVocabulary,
        private readonly array $latePolicies,
        private readonly array $packsByHead,
        private readonly ?string $statutoryWhy = null,
    ) {
    }

    /** @return list<array{id: string, title: string, source: string, points: list<string>, example?: string}> */
    public function sections(): array
    {
        return [
            $this->cycle(),
            $this->days(),
            $this->gross(),
            $this->components(),
            $this->deductions(),
            $this->statutory(),
            $this->recoveries(),
            $this->gratuity(),
            $this->bonus(),
        ];
    }

    /** @return array<string, mixed> */
    private function file(string $name): array
    {
        $decoded = json_decode((string) file_get_contents($this->configDir . '/' . $name), true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function q(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array{id: string, title: string, source: string, points: list<string>, example?: string} */
    private function cycle(): array
    {
        $vocab = $this->payCycleVocabulary;
        $points = ['The pay cycle decides WHICH DAYS a month\'s payslip covers and when it is paid; it never changes the arithmetic.'];

        if ($this->payCycleRules === []) {
            $points[] = 'No pay cycle row is published (Organization Setup → Pay Cycle & Lots), so every employee is paid on the calendar month: '
                . self::q($vocab['attendance_periods']['calendar'] ?? 'calendar');
        }

        foreach ($this->payCycleRules as $r) {
            if (strtolower((string) ($r['status'] ?? '')) !== 'active') {
                continue;
            }

            $scope = [];

            foreach (['company', 'branch', 'department', 'designation', 'team', 'employee'] as $k) {
                if (trim((string) ($r[$k] ?? '')) !== '') {
                    $scope[] = $k . ' ' . (string) $r[$k];
                }
            }

            $cutoff = (string) ($r['cutoff'] ?? 'calendar');
            $window = $cutoff === 'custom'
                ? 'day ' . self::q($r['from_day'] ?? '') . ' to day ' . self::q($r['to_day'] ?? '') . ' of the next month'
                : self::q($vocab['attendance_periods'][$cutoff] ?? $cutoff);
            $points[] = 'Rule "' . self::q($r['name'] ?? $r['id'] ?? '') . '" (pay-cycles row ' . self::q($r['id'] ?? '?') . ', '
                . ($scope === [] ? 'everyone' : implode(', ', $scope)) . '): ' . self::q($r['cycle'] ?? '') . ' cycle, attendance window '
                . $window . ', paid on day ' . self::q($r['pay_day'] ?? '') . '.';
        }

        $points[] = 'The most specific active row wins for an employee (employee > team > designation > department > branch > company > everyone).';

        return ['id' => 'cycle', 'title' => 'Which days the month covers', 'source' => 'config/pay-cycles.json → customisation "' . self::q($vocab['customisation_key'] ?? 'pay-cycles') . '"', 'points' => $points];
    }

    /** @return array{id: string, title: string, source: string, points: list<string>, example?: string} */
    private function days(): array
    {
        $rules = $this->file('attendance-rules.json');
        $points = ['Every day in the window is classified by ONE rule — the highest-priority rule whose condition holds (policy "'
            . self::q($rules['policy'] ?? '') . '", decision point "' . self::q($rules['decision_point'] ?? '') . '").'];
        $ordered = (array) ($rules['rules'] ?? []);
        usort($ordered, static fn (array $a, array $b): int => (int) ($b['priority'] ?? 0) <=> (int) ($a['priority'] ?? 0));

        foreach ($ordered as $r) {
            $status = '';

            foreach ((array) ($r['then'] ?? []) as $then) {
                if (($then['action'] ?? '') === 'SET_STATUS') {
                    $status = (string) $then['code'];
                }
            }

            $points[] = 'Priority ' . self::q($r['priority'] ?? '') . ' — ' . self::q($r['code'] ?? '') . ' "' . self::q($r['name'] ?? '') . '": when '
                . self::q($r['when'] ?? '') . ' → ' . $status . '.';
        }

        $worth = [];

        foreach ((array) ($rules['statuses'] ?? []) as $status => $halves) {
            if (is_int($halves) || ctype_digit((string) $halves)) {
                $worth[] = $status . ' = ' . self::q($halves) . ' half-day(s)';
            }
        }

        $points[] = 'What a status is WORTH, in halves of a day (never a decimal): ' . implode('; ', $worth) . '. A status with no worth is refused, not defaulted.';
        $points[] = 'Payable days = the sum of those halves ÷ 2, so a month can end on a half. Period days = the number of days in the attendance window.';

        foreach ($this->latePolicies as $lp) {
            $points[] = 'Late policy (late_policy row ' . self::q($lp['id'] ?? '?') . (trim((string) ($lp['employer_company_id'] ?? '')) === '' ? ', all companies' : ', company id ' . self::q($lp['employer_company_id'])) . '): grace '
                . self::q($lp['grace_min'] ?? '—') . ' min, full day ' . self::q($lp['full_day_hours'] ?? '—') . ' h, lates before a cut ' . self::q($lp['lates_before_cut'] ?? '—')
                . ', each additional late ' . self::q($lp['addl_late'] ?? '—') . ', week-off action ' . self::q($lp['weekoff_action'] ?? '—') . '.';
        }

        if ($this->latePolicies === []) {
            $points[] = 'No late policy row is published (Time & Attendance → Late Policy): lateness marks nothing and cuts nothing.';
        }

        $points[] = 'A shift\'s own full-day / half-day hours and grace (Organization Setup → Working Shifts, and the Roster for the day) override the policy for that day.';

        return ['id' => 'days', 'title' => 'Payable days', 'source' => 'config/attendance-rules.json; late_policy rows; shifts / rosters', 'points' => $points];
    }

    /** @return array{id: string, title: string, source: string, points: list<string>, example?: string} */
    private function gross(): array
    {
        $v = $this->structureVersion;
        $points = [
            'The figure on the employee record (employees.ctc) is read as ' . strtoupper($this->costBasis) . ' cost (structure version effective '
                . self::q($v['effective_from'] ?? '') . ', contracted_cost_basis "' . self::q($v['contracted_cost_basis'] ?? '') . '").',
            $this->costBasis === 'monthly'
                ? 'The month\'s gross is that figure as it stands.'
                : 'The month\'s gross = that figure ÷ 12, rounded half-up to the paisa.',
            'Prorated gross = month\'s gross × payable days ÷ period days, computed ONCE on the gross in exact paise. The gross is prorated first and then split into components, so the lines add up to the prorated gross by construction.',
            'Only components flagged prorates: true are affected; a component flagged prorates: false is paid or deducted in full whatever the days.',
        ];

        return ['id' => 'gross', 'title' => 'Gross and proration', 'source' => 'config/salary-structure.json (version in force on ' . $this->asOf . ')', 'points' => $points];
    }

    /** @return array{id: string, title: string, source: string, points: list<string>, example?: string} */
    private function components(): array
    {
        $points = [];
        $rows = (array) ($this->structureVersion['components'] ?? []);
        usort($rows, static fn (array $a, array $b): int => (int) ($a['sequence'] ?? 0) <=> (int) ($b['sequence'] ?? 0));

        foreach ($rows as $c) {
            $flags = [];

            foreach ((array) ($c['flags'] ?? []) as $f => $on) {
                if ($on) {
                    $flags[] = (string) $f;
                }
            }

            $rule = match ((string) ($c['basis'] ?? '')) {
                SalaryComponent::PERCENT_OF_GROSS => self::q($c['rate_percent'] ?? '') . '% of the (prorated) gross',
                SalaryComponent::PERCENT_OF => self::q($c['rate_percent'] ?? '') . '% of ' . self::q($c['of_component'] ?? ''),
                SalaryComponent::FLAT => 'flat ' . self::q($c['flat_amount'] ?? '') . ' per month',
                SalaryComponent::BALANCE => 'whatever is left of the gross after every other earning — the balance absorbs rounding',
                SalaryComponent::FORMULA => 'formula ' . self::q($c['formula']['expression'] ?? '') . ' (rounded ' . self::q($c['formula']['rounding_mode'] ?? '') . ' to ' . self::q($c['formula']['rounding_unit'] ?? '') . ')',
                SalaryComponent::SCHEDULE => 'from a schedule',
                default => self::q($c['basis'] ?? ''),
            };
            $points[] = self::q($c['code'] ?? '') . ' (' . self::q($c['kind'] ?? '') . ', sequence ' . self::q($c['sequence'] ?? '') . '): ' . $rule
                . ($flags === [] ? '' : ' — flags ' . implode(', ', $flags)) . '.';
        }

        if ($points === []) {
            $points[] = 'The structure in force declares no components — payroll would refuse to close.';
        }

        $points[] = 'Components are computed in dependency order (a percent-of component after the one it reads), then printed in sequence order. A cycle is refused before any amount is computed.';

        return ['id' => 'components', 'title' => 'Each component\'s rule', 'source' => 'config/salary-structure.json → components (version effective ' . self::q($this->structureVersion['effective_from'] ?? '') . ')', 'points' => $points];
    }

    /** @return array{id: string, title: string, source: string, points: list<string>, example?: string} */
    private function deductions(): array
    {
        $points = ['Structure deductions (kind "deduction" above) come off the earnings; an employer_cost component is shown to the employer and never reduces net pay.'];

        foreach ((array) ($this->structureVersion['components'] ?? []) as $c) {
            if ((string) ($c['kind'] ?? '') === SalaryComponent::DEDUCTION) {
                $points[] = self::q($c['code']) . ': ' . ((string) ($c['basis'] ?? '') === SalaryComponent::FLAT ? 'flat ' . self::q($c['flat_amount'] ?? '') : self::q($c['basis'] ?? ''))
                    . (empty($c['flags']['prorates']) ? ', NOT prorated — deducted in full in a part month' : ', prorated') . '.';
            }
        }

        $points[] = 'Ad-hoc deductions typed on the Deductions ledger (Payroll → Deductions, status pending/applied) are the month\'s manual lines; every one names its reference.';
        $points[] = 'Net pay = earnings − structure deductions − statutory heads − recoveries. Arrears from an earlier month\'s correction are added as their own line and kept separate from the month\'s own net.';

        return ['id' => 'deductions', 'title' => 'Deductions and net', 'source' => 'config/salary-structure.json; deductions rows', 'points' => $points];
    }

    /** @return array{id: string, title: string, source: string, points: list<string>, example?: string} */
    private function statutory(): array
    {
        $points = ['Every statutory head in force is asked for every employee on every close. A refusal (no pack, a synthetic pack, a missing wage-base flag) becomes a CONCERN printed on the payslip — never a silent zero.'];

        if ($this->packsByHead === []) {
            $points[] = 'No signed pack is in force on ' . $this->asOf . ($this->statutoryWhy === null ? '' : ': ' . $this->statutoryWhy) . '. Payslips carry no statutory line and say so.';
        }

        foreach ($this->packsByHead as $head => $pack) {
            $manifest = $pack->manifest;
            $state = $manifest->isSynthetic() ? 'SYNTHETIC — states no law, deducts nothing' : 'signed';

            foreach ($pack->rules() as $rule) {
                if ($rule->head !== $head) {
                    continue;
                }

                $points[] = $head . ' — pack ' . $manifest->packKey() . ' v' . $manifest->version() . ' (' . $state . '), rule ' . $rule->code . ': ' . $rule->calculation
                    . ' on the components flagged ' . $rule->wageBaseFlag . ', rounded ' . $rule->rounding
                    . ($rule->eligibility === null ? '' : ', eligible when ' . self::q($rule->eligibility)) . '. Source: ' . $rule->sourceReference . '.';
            }
        }

        return ['id' => 'statutory', 'title' => 'Statutory heads', 'source' => 'signed packs under packs/ (Statutory Configuration)', 'points' => $points];
    }

    /** @return array{id: string, title: string, source: string, points: list<string>, example?: string} */
    private function recoveries(): array
    {
        return ['id' => 'recoveries', 'title' => 'Loan and advance recoveries', 'source' => 'loans / advances rows', 'points' => [
            'Every loan and advance whose status is a recovering one is recovered on every close: a loan deducts its `emi`, an advance its instalment, as a deduction line naming which instalment of how many and what is left.',
            'The ported opening figures (outstanding, balance) are never rewritten; the closed runs are the movement, so the balance is always re-derivable from the runs.',
            'Anything that cannot be computed (an EMI larger than the pay, a blank EMI) is a concern on the row, not a skipped instalment.',
        ]];
    }

    /** @return array{id: string, title: string, source: string, points: list<string>, example?: string} */
    private function gratuity(): array
    {
        $g = $this->file('gratuity.json');

        return ['id' => 'gratuity', 'title' => 'Gratuity', 'source' => 'config/gratuity.json', 'points' => [
            'Eligible after ' . self::q($g['eligibility_years'] ?? '') . ' completed years; a part year of at least ' . self::q($g['part_year_counts_from_months'] ?? '') . ' months counts as a full year.',
            'Amount = last drawn basic × ' . self::q($g['days_factor']['numerator'] ?? '') . '/' . self::q($g['days_factor']['denominator'] ?? '') . ' × completed years, capped at ' . self::q($g['statutory_cap'] ?? '') . '.',
            '"Basic" is the sum of the components flagged ' . self::q($g['basic_flag'] ?? '') . ' in the most recent closed run.',
        ]];
    }

    /** @return array{id: string, title: string, source: string, points: list<string>, example?: string} */
    private function bonus(): array
    {
        $b = $this->file('statutory-bonus.json');

        return ['id' => 'bonus', 'title' => 'Statutory bonus', 'source' => 'config/statutory-bonus.json', 'points' => [
            'A month counts when its bonus wage (components flagged ' . self::q($b['bonus_wage_flag'] ?? '') . ') is at most ' . self::q($b['eligibility_wage_ceiling'] ?? '') . ' and the employee worked at least ' . self::q($b['min_working_days'] ?? '') . ' days in the year.',
            'Each eligible month contributes min(bonus wage, ' . self::q($b['calculation_wage_ceiling'] ?? '') . ') × a percent between ' . self::q($b['min_percent'] ?? '') . '% and ' . self::q($b['max_percent'] ?? '') . '%.',
            'The bonus year starts in month ' . self::q($b['financial_year_start_month'] ?? '') . '.',
        ]];
    }
}
