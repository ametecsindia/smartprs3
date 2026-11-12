<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Access\DataScope;

/**
 * WHO REPORTS TO WHOM, AND WHETHER THAT IS EVEN POSSIBLE.
 *
 * `Platform\Access\DataScope::reaches()` refuses a reporting chain that repeats
 * — "a cycle is a data defect, never a licence to widen scope, so it raises
 * rather than quietly truncating" — and `Platform\Organisation\ReportingLine`
 * does the same thing over dated assignment rows. Neither can fire against this
 * product's data: the surface hands the platform a chain ONE manager long,
 * because `employees.reporting_manager_id` is a flat current-valued column, and
 * a one-element chain cannot repeat.
 *
 * So nothing had ever asked the obvious question: does this tenant's reporting
 * data contain a cycle? It is not a hypothetical. A reorganisation typed in over
 * an afternoon produces A manages B and B manages A more often than anybody
 * expects, and nothing about the screens makes it visible — each row looks
 * perfectly ordinary on its own.
 *
 * WHY IT IS A SECURITY FINDING AND NOT A TIDINESS ONE. The manager role reaches
 * its direct reports. In a two-person cycle each of them IS the other's direct
 * report, so each can read the other's salary, leave and attendance — and both
 * reads are correctly authorised by every check in the product, because the data
 * says they are managers. Nothing is broken; the answer is just wrong, which is
 * the kind that survives an audit.
 *
 * A SELF-MANAGING EMPLOYMENT is the one-element case of the same thing and is
 * reported separately, because it reads differently to whoever has to fix it:
 * "this row points at itself" is a typo, and "these four people form a ring" is
 * a reorganisation somebody stopped halfway through.
 */
final class ReportingLines
{
    public function __construct(private readonly RowStore $store)
    {
    }

    /**
     * Every reporting cycle in the tenant.
     *
     * @return list<array{members: list<string>, why: string}>
     */
    public function cycles(): array
    {
        $manager = $this->managerOf();
        $cycles = [];
        $seenCycle = [];

        foreach (array_keys($manager) as $start) {
            $path = [];
            $at = $start;

            // AT MOST THE PLATFORM'S OWN DEPTH. `DataScope::MAX_REPORTING_DEPTH`
            // is 12 and is a platform maximum rather than a preference, so a walk
            // that exceeds it has found something no scope could resolve anyway.
            // Bounded rather than "until it repeats" because an unbounded walk
            // over cyclic data is the very hang this check exists to prevent.
            for ($step = 0; $step <= DataScope::MAX_REPORTING_DEPTH; $step++) {
                // MARKED BEFORE STEPPING PAST IT. The first version recorded the
                // node AFTER moving on, so the last member of a ring was never in
                // the path and a two-person cycle was reported as one person
                // managing themselves — a true statement about the wrong number
                // of people, which sends whoever has to fix it to one row instead
                // of two.
                $path[$at] = true;
                $next = $manager[$at] ?? null;

                if ($next === null || $next === '') {
                    break;
                }

                if (isset($path[$next])) {
                    // The ring itself, not the tail that led into it: reporting
                    // "E-9 eventually loops" to somebody who has to fix it is
                    // less useful than naming the four people in the ring.
                    $ring = array_keys($path);
                    $ring = array_slice($ring, array_search($next, $ring, true));
                    sort($ring);
                    $signature = implode('|', $ring);

                    if (!isset($seenCycle[$signature])) {
                        $seenCycle[$signature] = true;
                        $cycles[] = [
                            'members' => $ring,
                            'why' => count($ring) === 1
                                ? 'This employee is recorded as its own manager.'
                                : count($ring) . ' employees form a reporting ring, so each of '
                                    . 'them is the other\'s direct report — and a manager reaches '
                                    . 'their direct reports, so every one of them can read the '
                                    . 'others\' salary, leave and attendance, correctly authorised '
                                    . 'by every check in the product.',
                        ];
                    }

                    break;
                }

                $at = $next;
            }
        }

        return $cycles;
    }

    /**
     * Employees whose recorded manager is not an employee in this tenant.
     *
     * Reported because it is the OTHER way a reporting line silently does
     * nothing: a manager id pointing at a deleted or mistyped row means the
     * person has no manager at all, their leave reaches nobody's queue, and the
     * screen shows a name because the column has a value in it.
     *
     * @return list<array{employment: string, manager: string}>
     */
    public function danglingManagers(): array
    {
        $manager = $this->managerOf();
        $dangling = [];

        foreach ($manager as $employment => $reportsTo) {
            if ($reportsTo !== '' && !array_key_exists($reportsTo, $manager)) {
                $dangling[] = ['employment' => $employment, 'manager' => $reportsTo];
            }
        }

        return $dangling;
    }

    /**
     * employee code => the code of their manager (empty when none).
     *
     * BY CODE, because that is how `Surface\Scope` reaches a manager and how the
     * rest of the surface keys people. The column holds an `employees.id`, so it
     * is translated here once rather than at three call sites.
     *
     * @return array<string, string>
     */
    private function managerOf(): array
    {
        $byId = [];
        $rows = $this->store->all('employees');

        foreach ($rows as $row) {
            $byId[(string) ($row['id'] ?? '')] = Employment::code($row);
        }

        $manager = [];

        foreach ($rows as $row) {
            $managerId = (string) ($row['reporting_manager_id'] ?? '');
            $manager[Employment::code($row)] = $managerId === ''
                ? ''
                : ($byId[$managerId] ?? $managerId);
        }

        return $manager;
    }
}
