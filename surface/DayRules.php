<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * THE RULE ENGINE, REACHED FROM A SCREEN — attendance.day.classify.
 *
 * `Platform\Rules` is twenty-five finished, tested classes and until this file
 * nothing a user could open ever entered it. Meanwhile `Attendance::payableDays()`
 * decided what a day was worth with `workedMinutes > 0` — one comparison, in PHP,
 * with a comment admitting it was policy. A rule engine nobody can reach and a
 * business rule hard-coded next to it are the same defect seen from two sides.
 *
 * The mechanics of getting from a JSON file to the engine now live in
 * `PublishedRules`, lifted out of here when `leave.request.validate` needed the
 * identical hundred lines. What is left is the part that was never general: what
 * a SET_STATUS means, what a day is worth in pay, and what to print when the
 * engine could not answer.
 *
 * IT FAILS TO A NAMED STATE, NOT TO A GUESS. `UNCLASSIFIED` with the reason
 * attached, every time. The tempting alternative is to fall back to PRESENT so
 * the page looks finished, which is how a broken classifier pays a month of
 * absences in full.
 *
 * EVERY ANSWER EXPLAINS ITSELF (S1, law 4). The engine's `DecisionTrace` names
 * the rule that won, the rules that lost and why each lost, so a day marked
 * HALF_DAY can be argued with by the person it belongs to.
 */
final class DayRules
{
    public const DECISION_POINT = 'attendance.day.classify';

    /** What the screen shows when the engine could not answer. Never a status. */
    public const UNCLASSIFIED = 'UNCLASSIFIED';

    private readonly PublishedRules $published;

    public function __construct(string $file, string $tenantId = 'T-DEMO')
    {
        $this->published = new PublishedRules($file, self::DECISION_POINT, 'employee_day', $tenantId);
    }

    /**
     * Classify one settled day.
     *
     * @param array<string, mixed> $values SPX symbols for the decision point
     * @return array{status: string, rule: ?string, why: string, trace: ?string, flags: list<string>}
     */
    public function classify(string $date, array $values): array
    {
        $subject = (string) ($values['__subject'] ?? 'unknown');
        $result = $this->published->evaluate($subject, $date,
            array_diff_key($values, ['__subject' => null]));

        if ($result === null) {
            return $this->unclassified((string) $this->published->refusal());
        }

        $statuses = $result->actionsOfCode('SET_STATUS');

        if ($statuses === []) {
            return $this->unclassified(
                'No published rule matched this day. A day with no answer is not a present day.',
                $result->trace->render(),
            );
        }

        $winner = $statuses[0];
        $code = (string) ($winner->parameters['code'] ?? self::UNCLASSIFIED);
        $ruleCode = $winner->emittedBy['rule_code'] ?? null;

        return [
            'status' => $code,
            'rule' => $ruleCode,
            'why' => $this->published->descriptionOf((string) $ruleCode)
                ?? 'The rule that set this status carries no description.',
            'trace' => $result->trace->render(),
            'flags' => array_map(
                static fn ($a): string => (string) ($a->parameters['reason'] ?? 'flagged'),
                $result->actionsOfCode('FLAG_FOR_REVIEW'),
            ),
        ];
    }

    /**
     * What one day of this status is worth, in HALVES of a day.
     *
     * Halves, not a fraction and never 0.5: this number multiplies a month's
     * gross, and law 7 says money is exact. Keeping the whole calculation in
     * integers to the last step is what makes that true rather than aspirational.
     *
     * NULL MEANS THE FILE DOES NOT SAY, and the caller must refuse. The
     * alternative — defaulting an unrecognised status to a full day — pays
     * somebody for a month they did not work, with nothing on any screen to say
     * it happened; defaulting it to zero does the opposite and is no better.
     * There is no safe guess about what a day is worth.
     */
    public function payableHalves(string $status): ?int
    {
        $declared = $this->published->declared('statuses');
        $worth = is_array($declared) ? ($declared[$status] ?? null) : null;

        return is_int($worth) ? $worth : null;
    }

    /**
     * @return array{status: string, rule: null, why: string, trace: ?string, flags: list<string>}
     */
    private function unclassified(string $why, ?string $trace = null): array
    {
        return ['status' => self::UNCLASSIFIED, 'rule' => null, 'why' => $why,
            'trace' => $trace, 'flags' => []];
    }

    /** @return list<\SmartPRS\Platform\Rules\Rule> */
    public function rules(): array
    {
        return $this->published->rules();
    }

    /** Why there are no rules, for the screen. Null when there are. */
    public function refusal(): ?string
    {
        return $this->published->refusal();
    }
}
