<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * WHICH DAYS OF A LEAVE REQUEST COME OUT OF THE BALANCE — leave.request.charge.
 *
 * `Leave::workingDaysBetween()` counted them with `(int) $day->format('N') <= 5`.
 * Monday to Friday, in PHP, ignoring the working calendar entirely. A holiday
 * inside a leave span was charged — somebody who took the week of a festival paid
 * for the festival out of their own leave — and a tenant whose weekly off is
 * Sunday only got Saturdays free, because the code's idea of a week was not
 * theirs.
 *
 * IT WALKS THE SPAN A DAY AT A TIME. The decision point is about ONE day, and
 * assembling a whole request's answer in one evaluation would need a rule
 * language that loops. This is the loop, and it is here rather than in the
 * engine on purpose: the engine is a filter, a sort and a loop over RULES, and
 * giving it a second loop over subjects is how a rule engine turns into a
 * program.
 *
 * A DAY THAT COULD NOT BE DECIDED IS REPORTED, NOT SKIPPED. Skipping it would
 * silently make a request cheaper, which is the direction of error nobody
 * complains about and everybody eventually notices.
 */
final class ChargeRules
{
    public const DECISION_POINT = 'leave.request.charge';

    private readonly PublishedRules $published;

    public function __construct(string $file, string $tenantId = 'T-DEMO')
    {
        $this->published = new PublishedRules($file, self::DECISION_POINT, 'leave_request', $tenantId);
    }

    /**
     * Walk a span and say what each day costs.
     *
     * @param array<string, mixed> $employment the employee row, so a holiday
     *     scoped to one company/branch/role/designation charges correctly for
     *     this person; omitted (the default), every holiday is charged as
     *     unscoped, exactly as before this feature existed.
     * @param array<string, string> $companyNames company id => name
     * @param array<string, string> $designationRoles designation id => role name
     * @return array{days: int, charged: list<array{date: string, rule: string}>,
     *     exempt: list<array{date: string, reason: string, rule: string}>,
     *     undecided: list<array{date: string, why: string}>, why: ?string, trace: ?string}
     */
    public function chargeFor(string $employmentId, string $type, string $from, string $to,
        ?Calendar $calendar, array $employment = [], array $companyNames = [], array $designationRoles = []): array
    {
        $start = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        $charged = [];
        $exempt = [];
        $undecided = [];
        $lastTrace = null;

        if ($end < $start) {
            return ['days' => 0, 'charged' => [], 'exempt' => [], 'undecided' => [],
                'why' => 'The span ends before it begins.', 'trace' => null];
        }

        // NO CALENDAR, NO ANSWER — refused here rather than asked of the engine.
        //
        // The first version supplied null for `day.is_weekly_off` and
        // `day.is_holiday` and let SPX's three-valued logic sort it out. It does
        // not sort it out the way you would hope: the exempting rule correctly
        // goes UNKNOWN and does not match, and the charging rule's `except` also
        // goes unknown — and an unknown `except` does not carve anything out, so
        // the day is CHARGED. Every day of every span, at full price, with a
        // trace that says "null" in small print. That is a fail-open on somebody's
        // leave balance, and it is the kind that only ever errs in one direction.
        //
        // A question the caller cannot supply the inputs for is not a question
        // to ask. This is the same discipline as the prepared aggregation
        // counters: the engine answers what it is given, so giving it unknowns
        // and reading the result is the caller's mistake, not the engine's.
        if ($calendar === null) {
            for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
                $undecided[] = ['date' => $day->format('Y-m-d'),
                    'why' => 'No working calendar was given, so whether this day is a weekly off '
                        . 'or a holiday is not known, and a leave day charged on a guess is a day '
                        . 'taken out of somebody\'s balance for a reason nobody can state.'];
            }

            return ['days' => 0, 'charged' => [], 'exempt' => [], 'undecided' => $undecided,
                'why' => count($undecided) . ' day(s) in this span could not be decided, so the '
                    . 'number of days it costs is not known: ' . $undecided[0]['why'],
                'trace' => null];
        }

        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');

            // Real values, always: the no-calendar case returned above rather
            // than reaching here with unknowns.
            $result = $this->published->evaluate($employmentId, $date, [
                'day.date' => $date,
                'day.weekday' => strtoupper($day->format('D')),
                'day.is_weekly_off' => $calendar->isWeeklyOff($day->format('Y-m-d'), $employment, $designationRoles),
                'day.is_holiday' => isset(($employment === []
                    ? $calendar->holidays()
                    : $calendar->holidaysForEmployment($employment, $companyNames, $designationRoles))[$date]),
                'leave.type' => $type,
                'leave.from' => $from,
                'leave.to' => $to,
            ]);

            if ($result === null) {
                $undecided[] = ['date' => $date, 'why' => (string) $this->published->refusal()];

                continue;
            }

            $lastTrace = $result->trace->render();

            foreach ($result->actionsOfCode('EXEMPT_DAY') as $action) {
                $exempt[] = [
                    'date' => $date,
                    'reason' => (string) ($action->parameters['reason'] ?? ''),
                    'rule' => (string) ($action->emittedBy['rule_code'] ?? ''),
                ];
            }

            foreach ($result->actionsOfCode('CHARGE_DAY') as $action) {
                $charged[] = ['date' => $date,
                    'rule' => (string) ($action->emittedBy['rule_code'] ?? '')];
            }
        }

        return [
            'days' => count($charged),
            'charged' => $charged,
            'exempt' => $exempt,
            'undecided' => $undecided,
            // A span where SOME day could not be decided has no trustworthy
            // total, and saying so is cheaper than a balance that is wrong by an
            // amount nobody can name.
            'why' => $undecided === [] ? null
                : count($undecided) . ' day(s) in this span could not be decided, so the number of '
                    . 'days it costs is not known: ' . $undecided[0]['why'],
            'trace' => $lastTrace,
        ];
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
