<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Entity;

/**
 * THE EMPLOYMENT STATE MACHINE — Part X §X.7, R-X-055 to R-X-061.
 *
 * Statuses are rows in a status domain and transitions are rows in a table, so
 * this class holds no list of either. What it holds is the ANSWER to "may this
 * actor move this employment from here to there, today?", and the four ways that
 * answer can be no:
 *
 *   no such transition — R-X-058's "a transition not present in the table is
 *   impossible", which is what stops an archived employee being reactivated and
 *   then soft-deleted into a state nobody designed;
 *   the actor lacks the permission;
 *   the transition requires a reason and none was given;
 *   the guard did not hold, or could not be evaluated — and could-not-evaluate
 *   is a NO, because a guard that fails open is not a guard.
 *
 * `semantics_json` on the status value is where `consumes_seat` lives (R-X-056).
 * That matters more than it looks: SmartPRS 2 keeps its seat-free statuses in a
 * `private const` in the subscription service, and four of the five values in it
 * cannot be produced by any screen — so the constant describes a system that does
 * not exist, while the real one leaks seats through two import paths.
 */
final class TransitionTable
{
    /**
     * @param list<StatusTransition>              $transitions
     * @param array<string, array<string, mixed>> $semantics status code => semantics_json
     */
    public function __construct(
        private readonly array $transitions,
        private readonly array $semantics = [],
    ) {
    }

    /**
     * Decide one transition.
     *
     * @param  list<string>                  $actorPermissions
     * @param  (callable(string): ?bool)|null $evaluateGuard  the SPX guard, in the
     *         employment's own context. Returning null means "could not
     *         evaluate", which denies.
     * @return array{allowed: bool, why: string, transition: ?StatusTransition, workflow: ?string}
     */
    public function decide(
        string $from,
        string $to,
        array $actorPermissions,
        ?string $reason = null,
        ?\DateTimeImmutable $effectiveFrom = null,
        ?callable $evaluateGuard = null,
    ): array {
        $transition = $this->find($from, $to);

        if ($transition === null) {
            return [
                'allowed' => false,
                'why' => "There is no transition from '{$from}' to '{$to}'. A transition not present in the "
                    . 'table is impossible (R-X-058) — this is not a permission you can be granted, it is a '
                    . 'move the lifecycle does not have. Declared from here: '
                    . ($this->from($from) === [] ? 'nothing' : implode(', ', $this->from($from))) . '.',
                'transition' => null,
                'workflow' => null,
            ];
        }

        if (!in_array($transition->requiredPermission, $actorPermissions, true)) {
            return [
                'allowed' => false,
                'why' => "{$transition->requiredPermission} is required to move an employment from '{$from}' "
                    . "to '{$to}'.",
                'transition' => $transition,
                'workflow' => null,
            ];
        }

        if ($transition->requiresReason && ($reason === null || trim($reason) === '')) {
            return [
                'allowed' => false,
                'why' => "Moving from '{$from}' to '{$to}' records a reason. A status change with no stated "
                    . 'reason is the one nobody can explain to the employee afterwards.',
                'transition' => $transition,
                'workflow' => null,
            ];
        }

        if ($transition->requiresEffectiveDate && $effectiveFrom === null) {
            return [
                'allowed' => false,
                'why' => "Moving from '{$from}' to '{$to}' is effective-dated. Without a date the change is "
                    . '"now", and payroll cannot answer what this employee\'s status was on the 15th.',
                'transition' => $transition,
                'workflow' => null,
            ];
        }

        if ($transition->guardExpression !== null) {
            $held = $evaluateGuard === null ? null : $evaluateGuard($transition->guardExpression);

            if ($held !== true) {
                return [
                    'allowed' => false,
                    'why' => $held === null
                        ? "The guard on '{$from}' → '{$to}' (" . $transition->guardExpression . ') could not be '
                            . 'evaluated, so the transition is refused. A guard that fails open is not a guard.'
                        : "The guard on '{$from}' → '{$to}' did not hold: " . $transition->guardExpression,
                    'transition' => $transition,
                    'workflow' => null,
                ];
            }
        }

        return [
            'allowed' => true,
            'why' => $transition->workflowArtefactKey === null
                ? 'Permitted: ' . $transition->describe()
                : 'Permitted, and routed to ' . $transition->workflowArtefactKey . ' before it takes effect.',
            'transition' => $transition,
            'workflow' => $transition->workflowArtefactKey,
        ];
    }

    /**
     * The most specific row for a move: one naming the FROM status beats the
     * wildcard, so `* → archived` is a fallback rather than an override.
     */
    public function find(string $from, string $to): ?StatusTransition
    {
        $wildcard = null;

        foreach ($this->transitions as $transition) {
            if (!$transition->matches($from, $to)) {
                continue;
            }

            if (!$transition->isWildcard()) {
                return $transition;
            }

            $wildcard = $transition;
        }

        return $wildcard;
    }

    /** @return list<string> where an employment in this status can go */
    public function from(string $status): array
    {
        $targets = [];

        foreach ($this->transitions as $transition) {
            if (($transition->from === $status || $transition->isWildcard())
                && $transition->to !== $status
                && !in_array($transition->to, $targets, true)
            ) {
                $targets[] = $transition->to;
            }
        }

        return $targets;
    }

    /**
     * R-X-056: whether a status consumes a licensed seat, read from the status
     * value's own declaration.
     *
     * An undeclared status consumes a seat. That is the conservative answer and
     * it is deliberate: the failure mode of the other default is revenue leaking
     * through a status somebody added without thinking about licensing, which is
     * exactly how SmartPRS 2's import path bypasses the licence entirely.
     */
    public function consumesSeat(string $status): bool
    {
        return (bool) ($this->semantics[$status]['consumes_seat'] ?? true);
    }

    public function isPayrollEligible(string $status): bool
    {
        return (bool) ($this->semantics[$status]['payroll_eligible'] ?? false);
    }

    public function isLoginEnabled(string $status): bool
    {
        return (bool) ($this->semantics[$status]['login_enabled'] ?? false);
    }

    /**
     * R-X-057: the seat count, computed from status the same way for every
     * caller.
     *
     * @param  array<string, int>  $countByStatus  status code => how many employments hold it
     */
    public function seatsUsed(array $countByStatus): int
    {
        $used = 0;

        foreach ($countByStatus as $status => $count) {
            $used += $this->consumesSeat((string) $status) ? (int) $count : 0;
        }

        return $used;
    }

    /**
     * A status that cannot be left, so the table can be checked for one at
     * publish rather than discovered by an employment stuck in it.
     *
     * @param  list<string>  $statuses  every code in the domain
     * @return list<string>
     */
    public function terminalStatuses(array $statuses): array
    {
        return array_values(array_filter($statuses, fn (string $s): bool => $this->from($s) === []));
    }
}
