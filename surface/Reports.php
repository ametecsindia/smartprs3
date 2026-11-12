<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * A REPORT IS A DECLARATION, NOT A QUERY SOMEBODY WROTE INTO A CONTROLLER.
 *
 * `config/reports.json` names the table, what to group by, and what to count or
 * total. `Reports` runs it. A new report is an entry in that file — the same
 * bargain as the module registry, for the same reason: SmartPRS 2's report
 * screens are a controller each, so a change to how a total is formatted has to
 * be made in every one of them.
 *
 * IT AGGREGATES IN SQL, THROUGH THE STORE — never by building a query here. This
 * class used to read every row and add them up in PHP, on the reasoning that a
 * declaration file able to write a GROUP BY is a declaration file that can reach
 * any table by any path, and a report is exactly the feature somebody adds in a
 * hurry. That reasoning was right about the danger and wrong about the remedy:
 * the aggregate is now `RowStore::aggregate()`, which takes a column, a filter
 * and a grouping — no SQL, no fragments — checks each identifier against the real
 * schema, binds every value, and carries the same tenancy clause `all()` did. The
 * declaration cannot reach further than it could; it just no longer costs the
 * whole table to answer.
 *
 * A TOTAL SAYS WHAT IT COUNTED. Every report carries the number of rows behind
 * it, because "₹4.2 crore" from an unknown number of rows is a figure nobody can
 * check, and the first question anyone asks of a report is "out of how many?".
 *
 * ROWS THAT CANNOT BE GROUPED ARE COUNTED SEPARATELY, never dropped. A hundred
 * employees with no department must not quietly vanish from a headcount by
 * department — the report would balance perfectly and be wrong, which is worse
 * than one that admits the gap.
 *
 * ponytail: count, sum and average, one grouping level. A second level and a
 * date filter are the next two fields in the same JSON.
 */
final class Reports
{
    private const PREFIX = 'report:';

    /**
     * What a customer may change about a report. `table`, `lookup` and `money` are
     * deliberately NOT here.
     *
     * `permissionsFor()` below derives a report's permission from the module that
     * declares its table, plus the salary permission when the report is money. So
     * a customisation able to set `table` or `money` would change the permission in
     * front of the figure — a privilege escalation with a settings form in front of
     * it. Those are refused by name rather than ignored.
     */
    public const REPORT_KEYS = ['label', 'question', 'group', 'where'];

    /** @var list<array<string, mixed>>|null */
    private ?array $reports = null;

    public function __construct(
        private readonly RowStore $store,
        private readonly string $file,
        private readonly ?Modules $modules = null,
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->reports !== null) {
            return $this->reports;
        }

        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['reports'] ?? null)) {
            throw new \RuntimeException(
                "The report registry at {$this->file} is missing or unreadable. Refused rather than "
                . 'treated as "no reports", which would render an empty page that looks like an '
                . 'answer.'
            );
        }

        // The customer's own wording and filters laid over the shipped report, at
        // the one point every read passes through — so the list, the run and the
        // permission derivation are all looking at the same report.
        return $this->reports = array_map($this->overlay(...), $decoded['reports']);
    }

    private function overlay(array $report): array
    {
        $override = $this->customisation?->live()[self::PREFIX . $report['key']] ?? [];

        return $override === []
            ? $report
            : array_merge($report, array_intersect_key($override, array_flip(self::REPORT_KEYS)))
                + ['customised' => true];
    }

    /** The report AS SHIPPED — what a change is compared against. */
    public function shipped(string $key): ?array
    {
        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        foreach ((array) ($decoded['reports'] ?? []) as $report) {
            if ((string) $report['key'] === $key) {
                return $report;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    public function history(string $key): array
    {
        return $this->customisation?->history(self::PREFIX . $key) ?? [];
    }

    /** The columns the report's table really has, asked of the store. */
    public function columnsFor(array $report): array
    {
        try {
            return $this->store->columnsOf((string) $report['table']);
        } catch (\Throwable) {
            // Nothing is swallowed here: the columns are unobtainable, and
            // reportProblems() below treats an empty list as "cannot be checked"
            // and refuses a filter over it — not as "every column exists".
            return [];
        }
    }

    /**
     * Record a customer's own version of a report.
     *
     * @param array<string, mixed> $override
     * @return array{ok: bool, why: string, version?: int}
     */
    public function customise(string $key, array $override, ?array $shipped, string $byId,
        string $byLabel, string $note = ''): array
    {
        if ($shipped === null) {
            return ['ok' => false, 'why' => "There is no report '{$key}' to customise."];
        }

        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }

        $problems = self::reportProblems($override, $this->columnsFor($shipped), $shipped);

        if ($problems !== []) {
            return ['ok' => false, 'why' => implode(' ', $problems)];
        }

        return $this->customisation->saveRaw(self::PREFIX . $key, $override, $byId, $byLabel, $note);
    }

    /**
     * What is wrong with a proposed report customisation.
     *
     * THE CHECK THIS EARNS ITS PLACE WITH is the one on `where`. A filter naming a
     * column the table does not have matches nothing, so the report comes back
     * empty — and an empty report is indistinguishable from "there is nothing to
     * report". Somebody reads "0 agents on notice" off a screen and believes it.
     * So the column is named in the refusal, because the fix is one word.
     *
     * @param list<string> $columns the columns the report's table really has
     * @return list<string>
     */
    public static function reportProblems(array $override, array $columns, array $shipped): array
    {
        $problems = [];

        foreach (array_keys($override) as $key) {
            if (in_array((string) $key, self::REPORT_KEYS, true)) {
                continue;
            }

            $problems[] = sprintf(
                '"%s" cannot be customised on a report. The table it reads and whether it is money '
                . 'are what decide the permission in front of the figure — a report inherits the '
                . 'view permission of the module declaring its table, and money reports the salary '
                . 'permission as well. A form that could change either is a privilege escalation '
                . 'with a settings screen in front of it.', (string) $key);
        }

        foreach (['label', 'question', 'group'] as $key) {
            if (array_key_exists($key, $override) && trim((string) $override[$key]) === '') {
                $problems[] = sprintf(
                    'The %s cannot be emptied — a report with no %s is a row nobody can find or '
                    . 'read.', $key, $key);
            }
        }

        if (!array_key_exists('where', $override)) {
            return $problems;
        }

        if ($columns === []) {
            $problems[] = sprintf(
                'The columns of `%s` could not be read here, so a filter over it cannot be checked. '
                . 'Refused rather than accepted unchecked: a filter naming a column that is not '
                . 'there matches no rows, and an empty report reads as "there is nothing to '
                . 'report".', (string) ($shipped['table'] ?? '?'));

            return $problems;
        }

        foreach ((array) $override['where'] as $column => $expected) {
            if (!in_array((string) $column, $columns, true)) {
                $problems[] = sprintf(
                    'There is no column `%s` on `%s` to filter by. Named rather than ignored: the '
                    . 'filter would match no rows, the report would come back empty, and an empty '
                    . 'report is read as "there is nothing to report" rather than as one wrong '
                    . 'word.', (string) $column, (string) ($shipped['table'] ?? '?'));
            }

            if ((array) $expected === []) {
                $problems[] = sprintf(
                    'The filter on `%s` lists no values, so it would match nothing at all. Remove '
                    . 'the filter to mean "every row".', (string) $column);
            }
        }

        return $problems;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function byGroup(): array
    {
        $groups = [];

        foreach ($this->all() as $report) {
            $groups[(string) $report['group']][] = $report;
        }

        return $groups;
    }

    /**
     * THE PERMISSIONS A REPORT NEEDS, DERIVED FROM WHAT IT READS.
     *
     * This class's own docblock says "a report is exactly the feature somebody
     * adds in a hurry", and then this screen shipped with no permission check on
     * it at all. `ctc-by-department` totals every salary in the company from a
     * table whose own module sits behind `employee.view`, and anybody signed in
     * could open it. The aggregate was the leak: nobody thinks of a total as
     * data, and a total by department with three people in it is three salaries.
     *
     * So a report inherits the VIEW PERMISSION OF THE MODULE THAT DECLARES ITS
     * TABLE — derived, not declared, because a permission somebody has to
     * remember to add to a new report is one they forget on the report they add
     * in a hurry. A money report additionally needs the salary permission, on the
     * same reasoning as the letters.
     *
     * A report over a table NO module declares is refused rather than allowed:
     * there is nothing to inherit a permission from, and "no rule found" must
     * never read as "no rule applies".
     *
     * @return array{permissions: list<string>, why: ?string}
     */
    public function permissionsFor(array $report): array
    {
        $table = (string) $report['table'];

        if ($this->modules === null) {
            return ['permissions' => [], 'why' => 'The module registry is not available here, so '
                . 'the permission this report should sit behind cannot be worked out. Refused '
                . 'rather than shown: a report is an aggregate of rows somebody may not be allowed '
                . 'to read one at a time.'];
        }

        foreach ($this->modules->all() as $module) {
            if ((string) $module['table'] !== $table) {
                continue;
            }

            $needed = [$this->modules->permission($module, 'view')];

            if ($report['money'] ?? false) {
                $needed[] = 'employee.salary.view';
            }

            return ['permissions' => $needed, 'why' => null];
        }

        return ['permissions' => [], 'why' => sprintf(
            'No module declares `%s`, so there is no permission for this report to inherit. Refused '
            . 'rather than shown to everybody: "no rule found" must never read as "no rule '
            . 'applies".', $table)];
    }

    public function find(string $key): ?array
    {
        foreach ($this->all() as $report) {
            if ((string) $report['key'] === $key) {
                return $report;
            }
        }

        return null;
    }

    /**
     * Run a report.
     *
     * THE MEASURE IS EXACT (Law 7). A sum and an average over a money column
     * were accumulated in float — `$sum += (float) $row[$column]` — and a
     * ₹4.2-crore CTC total is exactly the size at which that stops being the
     * number the columns hold. Sums are integer minor units and averages divide
     * with a declared rounding mode.
     *
     * A COUNT IS AN INT AND A SUM IS AN EXACT DECIMAL STRING. Both are what they
     * are: "3" employees is not "3.00", and "42000000.00" is not a float. The
     * caller formats a money report through `Money`, and a `days` sum (which is
     * a quantity, not rupees) through the same exact string.
     *
     * @return array{rows: list<array<string, mixed>>, total: int|string, counted: int,
     *     ungrouped: int, why: ?string}
     */
    public function run(array $report): array
    {
        $measure = (string) ($report['measure'] ?? 'count');
        $zero = $measure === 'count' ? 0 : Money::zero()->toDecimalString();
        $groupBy = (string) ($report['group_by'] ?? '');
        $column = (string) ($report['column'] ?? '');

        // ONE GROUPED QUERY, NOT A TABLE READ AND A PHP LOOP. Every report in
        // `config/reports.json` is a count or a total over one table, broken down
        // by one column, narrowed by an IN filter — which is exactly a GROUP BY,
        // so there was nothing here the store could not answer. `ctc-by-
        // department` over the real `employees` shape used to read every row to
        // print eight numbers.
        //
        // The declaration still cannot reach anything: `table`, `group_by`,
        // `column` and the filter columns are checked against the real schema by
        // the store and refused by name, the values are bound, and the tenancy
        // clause is the store's — the same one `all()` used. What changed is
        // where the addition happens, not who is allowed to ask.
        try {
            $buckets = $this->store->aggregate((string) $report['table'], $column,
                (array) ($report['where'] ?? []), $groupBy);
        } catch (\Throwable $e) {
            // A value that is not an exact figure, a column that is not there, a
            // table that is not: all REPORTED, never counted as zero. A row
            // silently left out of a total is a total wrong by exactly that row
            // with nothing on the screen saying so.
            return ['rows' => [], 'total' => $zero, 'counted' => 0, 'ungrouped' => 0,
                'why' => sprintf('This report has no total to show: %s', $e->getMessage())];
        }

        $labels = $this->labelsFor($report);
        $out = [];
        $total = Money::zero();
        $count = 0;
        $ungrouped = 0;

        foreach ($buckets as $bucket) {
            // An ungrouped aggregate over an empty table still answers "none",
            // and "none" is not a row on a chart.
            if ($bucket['count'] === 0) {
                continue;
            }

            $key = $bucket['key'];

            if ($groupBy === '') {
                $key = 'All';
            } elseif ($key === '') {
                // Counted and NAMED, never dropped. A hundred employees with no
                // department must not vanish from a headcount by department.
                $ungrouped += $bucket['count'];
                $key = '(not set)';
            }

            $bucket = ['label' => $labels[$key] ?? $key, 'count' => $bucket['count'],
                'sum' => Amount::of($bucket['sum'])];

            $money = match ($measure) {
                'sum' => $bucket['sum'],
                // A declared rounding mode, because an average rarely divides
                // evenly and there is no default rounding in this codebase.
                //
                // PHPStan proves `count` can never be 0 here — a bucket only
                // exists because a row created it — and so calls the zero guard
                // dead. It stays. This is a division, the thing being divided is
                // money, and the cost of the two branches being wrong is not
                // symmetrical: a redundant guard costs one comparison, and a
                // missing one costs a DivisionByZeroError on a payroll report
                // the first time an empty bucket is reachable by some route
                // nobody has thought of yet. Never remove a divide-by-zero guard
                // to satisfy an analyser.
                'average' => $bucket['count'] === 0
                    ? Money::zero()
                    : $bucket['sum']->dividedBy($bucket['count'], RoundingMode::HalfUp),
                default => Money::zero(),
            };

            if ($measure === 'sum') {
                $total = $total->plus($money);
            }

            $count += $bucket['count'];
            $out[] = ['label' => $bucket['label'], 'rows' => $bucket['count'],
                'value' => $measure === 'count' ? $bucket['count'] : $money->toDecimalString(),
                'sort' => $measure === 'count' ? $bucket['count'] : $money->minor];
        }

        // Sorted on the exact minor units, never on the rendered string: "9.00"
        // sorts above "10.00" as text and that is a chart drawn upside down.
        usort($out, static fn (array $a, array $b): int => $b['sort'] <=> $a['sort']);
        $out = array_map(static function (array $row): array {
            unset($row['sort']);

            return $row;
        }, $out);

        return [
            'rows' => $out,
            'total' => match ($measure) {
                'sum' => $total->toDecimalString(),
                'average' => Money::zero()->toDecimalString(),
                default => $count,
            },
            // The rows behind the figure, added up from the buckets rather than
            // counted in PHP — the same number, and nothing was read to get it.
            'counted' => $count,
            'ungrouped' => $ungrouped,
            'why' => null,
        ];
    }

    /**
     * Turn grouping keys into names where the report says they point at another
     * table. Grouping by `department_id` and printing "3" is a report nobody can
     * read without a second screen open.
     *
     * ponytail: still `all()` over the LOOKUP table — the departments, branches
     * and leave types a report groups by, which are tens of rows, not the table
     * being reported on. The ceiling is a customer with more distinct groups than
     * fit on a chart, and the upgrade path is a join in the store's aggregate,
     * which is one query with a second table name checked the same way. Not done
     * here because it buys nothing measurable today and costs the one property
     * that makes `aggregate()` safe: exactly one table per call.
     *
     * @return array<string, string>
     */
    private function labelsFor(array $report): array
    {
        // A report may name its raw values outright (owner, 23 Oct: PF applicability "1" → "Yes").
        $named = array_map('strval', (array) ($report['value_labels'] ?? []));
        $lookup = (array) ($report['lookup'] ?? []);

        if ($lookup === []) {
            return $named;
        }

        try {
            $rows = $this->store->all((string) $lookup['table']);
        } catch (\Throwable) {
            return [];
        }

        $labels = [];
        $value = (string) ($lookup['value'] ?? 'id');

        foreach ($rows as $row) {
            $parts = [];

            foreach ((array) ($lookup['show'] ?? ['name']) as $c) {
                if ((string) ($row[$c] ?? '') !== '') {
                    $parts[] = (string) $row[$c];
                }
            }

            $labels[(string) ($row[$value] ?? '')] = $parts === [] ? '—' : implode(' — ', $parts);
        }

        return $named + $labels;
    }
}
