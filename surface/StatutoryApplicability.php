<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Payroll\SalaryComponent;

/**
 * WHETHER PF, ESI, PT, LWF AND TDS APPLY TO ONE EMPLOYEE — and payroll honouring
 * the answer.
 *
 * The employee record has carried `pf_applicable` and `esi_applicable` since the
 * SmartPRS 2 port, and NOTHING READ THEM: the Salary Structure computed PF,
 * ESI and PT for everybody, so ticking "PF Applicable: No" on the Directory
 * changed the screen and not the payslip. That is the gap the owner's 28 Sep
 * instruction closes — confirmation sets these, and "all these should be wired
 * accurately for calculations in payroll generation, salary, payslip".
 *
 * ONE READER, EVERY CALLER. The close (Register), its reproduction (verify), the
 * live salary screen and the payslip preview all ask this class, so the four
 * cannot disagree about whether somebody pays PF.
 *
 * BLANK IS NOT NO. A head nobody has answered for an employee computes exactly
 * as it did before this class existed. Treating blank as "does not apply" would
 * take PF off every payslip of every employee nobody has opened yet — the most
 * expensive possible reading of a field somebody simply has not filled in.
 *
 * WHICH COMPONENTS A HEAD SWITCHES OFF, AND WHERE ITS ANSWER IS KEPT, are
 * declared in config/statutory-applicability.json (Law 1): no component code
 * and no field name is typed here.
 */
final class StatutoryApplicability
{
    public const YES = 'Yes';

    public const NO = 'No';

    /** @var array<string, mixed>|null */
    private ?array $declared = null;

    public function __construct(
        private readonly string $file,
        private readonly ?EmployeeExtras $extras = null,
        // ON PROBATION, THE PROBATION SETTING DECIDES (owner, 8 Oct): a head the
        // employee's company marks "not applicable during probation" is off for
        // every month the employee is still on probation, whatever the record says.
        private readonly ?Probation $probation = null,
        // AS PER GOVERNMENT RULES (owner, 16 Oct): head => Yes | No | '' worked out from the employee's
        // own salary each time it is read, so saved employees follow too. '' = cannot tell (no salary):
        // the saved answer stands.
        private readonly ?\Closure $government = null,
    ) {
    }

    /** The Government-rule answer for one field (pf_applicable, esi_applicable), or '' when none applies. */
    public function governmentAnswer(array $employment, string $field): string
    {
        $answers = $this->government === null ? [] : ($this->government)($employment);

        foreach ($this->heads() as $head => $h) {
            if ((string) ($h['field'] ?? '') === $field && isset($h['government'])) {
                return (string) ($answers[$head] ?? '');
            }
        }

        return '';
    }

    /**
     * The declared heads, head => {label, field, column, column_kind, components}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function heads(): array
    {
        if ($this->declared === null) {
            $d = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;

            if (!is_array($d) || !is_array($d['heads'] ?? null)) {
                throw new \RuntimeException("{$this->file} does not declare any statutory heads.");
            }

            $this->declared = $d;
        }

        return (array) $this->declared['heads'];
    }

    /** A stored answer, as Yes, No or '' (not decided). 1/0, true/false, y/n all read. */
    public static function normalise(mixed $raw): string
    {
        $v = strtolower(trim((string) $raw));

        return match (true) {
            in_array($v, ['yes', 'y', '1', 'true', 'applicable'], true) => self::YES,
            in_array($v, ['no', 'n', '0', 'false', 'not applicable', 'na', 'n/a'], true) => self::NO,
            default => '',
        };
    }

    /**
     * Every head's answer for one employee.
     *
     * The column when the table has one and it holds a value, else the value
     * kept beside the record. `$extras` may be passed in when the caller has
     * already read them (a screen drawing many rows); otherwise they are read.
     *
     * @param array<string, mixed> $employment
     * @param array<string, string>|null $extras field code => value
     * @return array<string, string> head => Yes | No | ''
     */
    public function answers(array $employment, ?array $extras = null): array
    {
        $extras ??= $this->extras === null ? [] : $this->extras->forEmployment(Employment::code($employment));
        $out = [];
        $government = $this->government === null ? [] : ($this->government)($employment);

        foreach ($this->heads() as $head => $h) {
            if (isset($h['government']) && (string) ($government[$head] ?? '') !== '') {
                $out[(string) $head] = (string) $government[$head];

                continue;
            }

            $column = (string) ($h['column'] ?? '');
            $value = $column !== '' && array_key_exists($column, $employment)
                ? self::normalise($employment[$column])
                : '';

            if ($value === '') {
                $value = self::normalise($extras[(string) ($h['field'] ?? '')] ?? '');
            }

            $out[(string) $head] = $value;
        }

        return $out;
    }

    /**
     * The catalogue component codes switched OFF for this employee — only the
     * heads answered No. Sorted, so it can be part of a grouping key.
     *
     * @param array<string, mixed> $employment
     * @return list<string>
     */
    public function offFor(array $employment, ?array $extras = null, ?string $asOf = null): array
    {
        $off = [];
        $heads = $this->heads();
        $probationOff = $this->probationOff($employment, $asOf);

        foreach ($this->answers($employment, $extras) as $head => $answer) {
            if ($answer === self::NO || isset($probationOff[$head])) {
                foreach ((array) ($heads[$head]['components'] ?? []) as $code) {
                    $off[] = (string) $code;
                }
            }
        }

        $off = array_values(array_unique($off));
        sort($off, SORT_STRING);

        return $off;
    }

    /**
     * The HEADS (PF, ESI, PT, LWF, TDS) off for this employee — answered No, or
     * not applicable during probation. The statutory engine's rows are filtered
     * by these, so a signed pack cannot put back a head the component list took off.
     *
     * @param array<string, mixed> $employment
     * @return list<string>
     */
    public function offHeads(array $employment, ?array $extras = null, ?string $asOf = null): array
    {
        $probationOff = $this->probationOff($employment, $asOf);
        $out = [];

        foreach ($this->answers($employment, $extras) as $head => $answer) {
            if ($answer === self::NO || isset($probationOff[$head])) {
                $out[] = (string) $head;
            }
        }

        return $out;
    }

    /**
     * Heads off because the employee is on probation on $asOf (today when
     * null) and their company's probation setting says the head does not
     * apply during probation.
     *
     * @param array<string, mixed> $employment
     * @return array<string, true>
     */
    public function probationOff(array $employment, ?string $asOf = null): array
    {
        if ($this->probation === null || Employment::code($employment) === ''
            || !$this->probation->onProbationOn($employment, $asOf ?? date('Y-m-d'))) {
            return [];
        }

        $applies = $this->probation->statutoryDuringProbation(array_map('strval', array_keys($this->heads())),
            trim((string) ($employment['employer_company_id'] ?? '')) ?: null);

        return array_fill_keys(array_keys(array_filter($applies, static fn (bool $a): bool => !$a)), true);
    }

    /**
     * The heads switched off, by label — what a payroll row says about the
     * deductions it does not carry.
     *
     * @param array<string, mixed> $employment
     * @return list<string>
     */
    public function offLabels(array $employment, ?array $extras = null, ?string $asOf = null): array
    {
        $labels = [];
        $heads = $this->heads();
        $probationOff = $this->probationOff($employment, $asOf);

        foreach ($this->answers($employment, $extras) as $head => $answer) {
            if (isset($probationOff[$head])) {
                $labels[] = (string) ($heads[$head]['label'] ?? $head) . ' (on probation)';
            } elseif ($answer === self::NO) {
                $labels[] = (string) ($heads[$head]['label'] ?? $head);
            }
        }

        return $labels;
    }

    /**
     * The components with every switched-off code removed. A flat-structure
     * code (D-PT) is matched with its kind prefix off, the same rule
     * ComponentRules::catalogueCodeOf() uses, so one declared list covers both.
     *
     * @param list<SalaryComponent> $components
     * @param list<string> $off
     * @return list<SalaryComponent>
     */
    public static function without(array $components, array $off): array
    {
        if ($off === []) {
            return $components;
        }

        $off = array_fill_keys($off, true);

        return array_values(array_filter($components, static function (SalaryComponent $c) use ($off): bool {
            $bare = preg_match('/^[A-Z]-(.+)$/', $c->code, $m) === 1 ? $m[1] : $c->code;

            return !isset($off[$c->code]) && !isset($off[$bare]);
        }));
    }

    /**
     * Split a set of chosen answers into what goes on the employee row and
     * what is kept beside it. Only Yes / No are accepted; anything else for a
     * declared head is refused by name rather than saved as a third state.
     *
     * @param array<string, string> $choice head => Yes | No
     * @param list<string> $columnsOnTable the columns `employees` actually has
     * @return array{ok: bool, why: string, columns: array<string, int|string>, extras: array<string, string>}
     */
    public function split(array $choice, array $columnsOnTable): array
    {
        $columns = [];
        $extras = [];

        foreach ($this->heads() as $head => $h) {
            if (!array_key_exists($head, $choice)) {
                continue;
            }

            $answer = self::normalise($choice[$head]);

            if ($answer === '') {
                return ['ok' => false, 'why' => sprintf('%s applicable needs Yes or No.', (string) ($h['label'] ?? $head)),
                    'columns' => [], 'extras' => []];
            }

            $column = (string) ($h['column'] ?? '');

            if ($column !== '' && in_array($column, $columnsOnTable, true)) {
                $columns[$column] = ($h['column_kind'] ?? 'text') === 'flag'
                    ? ($answer === self::YES ? 1 : 0)
                    : $answer;
            } else {
                $extras[(string) ($h['field'] ?? $head)] = $answer;
            }
        }

        return ['ok' => true, 'why' => '', 'columns' => $columns, 'extras' => $extras];
    }
}
