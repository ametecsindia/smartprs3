<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHAT A DAY EARNED BEYOND ITS PAY — attendance.day.earn.
 *
 * `DaySettlement` has computed `overtimeMinutes` for every settled day since
 * Phase 4 and nothing ever read it. The attendance screen printed it in a column,
 * no comp-off was ever credited, no overtime was ever recorded, and no rule could
 * have said otherwise because this decision point had no rules published against
 * it. A figure computed on every page load and consumed by nobody is not a
 * feature; it is a number that makes a screen look finished.
 *
 * ALL_MATCH, from the decision point. One day can earn overtime and a comp-off at
 * the same time and a policy that returns only the winner hands somebody one of
 * the two, permanently, without saying which it dropped.
 *
 * NOTHING HERE WRITES ANYTHING. It reads days and returns what they earned; the
 * ledger posting is `Attendance::postEarnings()`, deliberately a separate,
 * explicitly-invoked act. Classification runs on every page load — a screen that
 * credited a comp-off while rendering would credit one per refresh, which is the
 * accrual bug this codebase already fixed once and would be far harder to spot
 * the second time.
 */
final class EarnRules
{
    public const DECISION_POINT = 'attendance.day.earn';

    private readonly PublishedRules $published;

    public function __construct(string $file, string $tenantId = 'T-DEMO')
    {
        $this->published = new PublishedRules($file, self::DECISION_POINT, 'employee_day', $tenantId);
    }

    /**
     * What one settled day earned.
     *
     * @param array<string, mixed> $values SPX symbols for the decision point
     * @return array{comp_offs: list<array{days: string, reason: string, rule: string}>,
     *     overtime: list<array{minutes: int, rule: string}>, why: ?string, trace: ?string}
     */
    public function earnedOn(string $employmentId, string $date, array $values): array
    {
        $result = $this->published->evaluate($employmentId, $date, $values);

        if ($result === null) {
            // Reported, never treated as "this day earned nothing". Those are
            // the same shape on a screen and opposite facts, and the one that
            // costs somebody a day off is the one that stays quiet.
            return ['comp_offs' => [], 'overtime' => [],
                'why' => $this->published->refusal(), 'trace' => null];
        }

        $compOffs = [];

        foreach ($result->actionsOfCode('CREATE_COMP_OFF') as $action) {
            $compOffs[] = [
                'days' => (string) ($action->parameters['units'] ?? '1'),
                'reason' => (string) ($action->parameters['reason'] ?? 'Earned'),
                'rule' => (string) ($action->emittedBy['rule_code'] ?? ''),
            ];
        }

        $overtime = [];

        foreach ($result->actionsOfCode('CREATE_OT') as $action) {
            $overtime[] = [
                'minutes' => (int) ($action->parameters['minutes'] ?? 0),
                'rule' => (string) ($action->emittedBy['rule_code'] ?? ''),
            ];
        }

        return ['comp_offs' => $compOffs, 'overtime' => $overtime, 'why' => null,
            'trace' => $result->trace->render()];
    }

    /**
     * The leave type a comp-off credits. Null when the file does not name one,
     * and the caller must then refuse rather than pick: a credit posted to a
     * type nobody declared is a balance no screen shows and no payroll spends.
     */
    public function compOffType(): ?string
    {
        $declared = $this->published->declared('comp_off');
        $type = is_array($declared) ? ($declared['leave_type'] ?? null) : null;

        return is_string($type) && $type !== '' ? $type : null;
    }

    public function refusal(): ?string
    {
        return $this->published->refusal();
    }

    /** @return list<\SmartPRS\Platform\Rules\Rule> */
    public function rules(): array
    {
        return $this->published->rules();
    }
}
