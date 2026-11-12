<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Rational;

/**
 * WHICH LEAVE TYPES EXIST IS A BUSINESS DECISION — Part XV, and the same law the
 * salary structure obeys. `['EL', 'CL', 'SL']` was written into
 * `Surface\PayrollPeriod` as the list of PAID types, and `18` was written into
 * the front controller as the opening balance. Both are policy, and policy in a
 * PHP file is a release every time a state changes its rules.
 *
 * ACCRUAL IS POSTED, NOT COMPUTED ON READ. A balance derived by multiplying
 * months by a rate cannot be corrected, cannot be explained line by line, and
 * changes retrospectively the moment the rate changes. Posting an entry per
 * month leaves a ledger an employee can read and an administrator can adjust.
 *
 * ACCRUAL IS IDEMPOTENT. Each posting names itself — `accrual:EL:2026-03` — and
 * a month already posted is skipped. A page that accrues on load must be safe to
 * refresh; the alternative is a balance that grows every time somebody looks at
 * it, which is the kind of bug found six months later by an employee who is
 * pleased about it.
 *
 * THE CAP STOPS ACCRUAL, IT DOES NOT TRUNCATE THE BALANCE. An employee at the
 * cap earns nothing further, but nothing already earned is taken away by a
 * screen refresh — a silent deduction of days somebody worked for is the version
 * of this that ends in a dispute.
 *
 * ponytail: accrual is monthly and full-month; no pro-rata for a mid-month
 * joiner, no lapse-at-year-end. Both need a policy field before they need code.
 */
final class LeavePolicy
{
    /** The customisation key the tenant's own type list is saved under. */
    public const KEY = 'leave-types';

    /**
     * The organisation dimensions a type can be scoped to, edited as columns on
     * the Leave Types screen (after Note) — unlike hidden_for_marital_status,
     * which is a fixed policy fact set only in config/leave-policy.json, scope
     * is the owner's own choice and is saved through saveTypes() like every
     * other column. 'role' is Role/Designation Setup's free-text Role, not
     * `employees.role` (the access tier) — the caller resolves it the same way
     * every other Role-scoped screen this product has does, and hands it in
     * already resolved.
     */
    // DEPARTMENT added 21 Sep 2026 (owner: "which Company, Branch, Department,
    // Role, Designation … this leave type applies to"). An employee's
    // department reaches eligible() through leaveScopeContext()/orgEnrich().
    public const SCOPE_DIMENSIONS = ['company', 'branch', 'department', 'role', 'designation'];

    public function __construct(
        private readonly string $file,
        // THE TENANT'S OWN LIST, when they have saved one under Organization
        // Setup → Leave Types. It REPLACES the shipped list rather than merging
        // into it: a type the customer removed must be gone, not defaulted back.
        private readonly ?Customisation $customisation = null,
    ) {
    }

    /** The shipped list, as the file declares it. */
    public function shipped(): array
    {
        $decoded = is_file($this->file)
            ? json_decode((string) file_get_contents($this->file), true)
            : null;

        if (!is_array($decoded) || !is_array($decoded['types'] ?? null)) {
            throw new \RuntimeException(
                'config/leave-policy.json is missing or unreadable. It is refused rather than treated '
                . 'as "no leave types", which would silently make every leave day unpaid.'
            );
        }

        return self::keyed($decoded['types']);
    }

    public function types(): array
    {
        $saved = $this->customisation?->live()[self::KEY]['types'] ?? null;

        return is_array($saved) && $saved !== [] ? self::keyed($saved) : $this->shipped();
    }

    /**
     * The types that apply to this employee — see eligible().
     *
     * @param array<string, mixed> $employment marital_status, plus company,
     *     branch, role, designation as NAMES (the caller resolves these —
     *     see app.php's leaveScopeContext())
     * @return array<string, array<string, mixed>>
     */
    public function typesFor(array $employment): array
    {
        return array_filter($this->types(),
            fn (array $type, string $code): bool => $this->eligible($code, $employment),
            ARRAY_FILTER_USE_BOTH);
    }

    /**
     * The `hidden_for_<field>` fixed facts a type can carry — never a column on
     * the Leave Types screen, only ever set in config/leave-policy.json (see
     * saveTypes()). Maps the fact's key to the `employees` column it reads.
     * Both are the SAME shape, a list of values a type is hidden for, so
     * "Maternity Leave, only for Married Female employees" is two positive
     * exclusions (hidden_for_marital_status: Single/Divorced/Widowed,
     * hidden_for_gender: Male) rather than a one-off ML/PL check in code.
     */
    private const HIDDEN_FOR_FACTS = ['hidden_for_marital_status' => 'marital_status', 'hidden_for_gender' => 'gender'];

    /**
     * Does this type apply to this employee at all — by the fixed facts above
     * (never a form on this screen) AND by organisation scope (company,
     * branch, role, designation — a column on this screen, blank matches
     * everybody, every named dimension must match, the same convention Leave
     * Grants' scope already uses). A blank/not-yet-recorded value for a fixed
     * fact is never a positive match, so it never hides a type on its own.
     *
     * @param array<string, mixed> $employment
     */
    public function eligible(string $code, array $employment): bool
    {
        $type = $this->types()[$code] ?? null;

        if ($type === null) {
            return false;
        }

        if ($this->hiddenBy($code, $type, $employment) !== null) {
            return false;
        }

        foreach (self::SCOPE_DIMENSIONS as $dimension) {
            $want = trim((string) ($type['scope'][$dimension] ?? ''));

            if ($want !== '' && strcasecmp(trim((string) ($employment[$dimension] ?? '')), $want) !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which fixed fact hides this type: [fact key, the employee's value] or null.
     *
     * - Case-insensitive: "MALE" on a record is Male.
     * - The tenant's saved list and the shipped config are UNIONED for the same
     *   code: these facts are never set on screen, so a list saved before they
     *   existed (empty) or before a value was added still gets the shipped ones.
     * - GENDER FAILS CLOSED (owner, 10 Oct: maternity/paternity only for the
     *   respective gender): a gender-restricted type is hidden while the
     *   record has no gender. Marital status stays as before — blank hides nothing.
     *
     * @param array<string, mixed> $type
     * @param array<string, mixed> $employment
     * @return array{0: string, 1: string}|null
     */
    private function hiddenBy(string $code, array $type, array $employment): ?array
    {
        $shipped = null;

        foreach (self::HIDDEN_FOR_FACTS as $key => $column) {
            $shipped ??= $this->shipped();
            $hiddenFor = array_map('strtolower', array_map('strval',
                [...(array) ($type[$key] ?? []), ...(array) ($shipped[$code][$key] ?? [])]));
            $have = trim((string) ($employment[$column] ?? ''));

            if ($hiddenFor === []) {
                continue;
            }

            if ($have === '' ? $column === 'gender' : in_array(strtolower($have), $hiddenFor, true)) {
                return [$key, $have];
            }
        }

        return null;
    }

    /** Why eligible() said no, in words an employee or HR can act on — null when it said yes. */
    public function ineligibleReason(string $code, array $employment): ?string
    {
        if ($this->eligible($code, $employment)) {
            return null;
        }

        $type = $this->types()[$code] ?? null;

        if ($type === null) {
            return "'{$code}' is not one of this company's leave types.";
        }

        $factLabels = ['hidden_for_marital_status' => 'whose marital status is', 'hidden_for_gender' => 'whose gender is'];

        if (($hidden = $this->hiddenBy($code, $type, $employment)) !== null) {
            [$key, $have] = $hidden;

            return $have === ''
                ? "{$type['name']} needs the employee's gender on their record before it can be shown."
                : "{$type['name']} is not available to an employee {$factLabels[$key]} {$have}.";
        }

        $labels = ['company' => 'Company', 'branch' => 'Branch', 'department' => 'Department', 'role' => 'Role', 'designation' => 'Designation'];

        foreach (self::SCOPE_DIMENSIONS as $dimension) {
            $want = trim((string) ($type['scope'][$dimension] ?? ''));

            if ($want !== '' && strcasecmp(trim((string) ($employment[$dimension] ?? '')), $want) !== 0) {
                return "{$type['name']} is scoped to {$labels[$dimension]} \"{$want}\", which this employee does not match.";
            }
        }

        return "{$type['name']} does not apply to this employee.";
    }

    /** Is the live list the tenant's own, or the shipped one? */
    public function isCustomised(): bool
    {
        return is_array($this->customisation?->live()[self::KEY]['types'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    public function history(): array
    {
        return $this->customisation?->history(self::KEY) ?? [];
    }

    /**
     * Save the tenant's list. Every row is checked before any is kept.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{ok: bool, why: string}
     */
    public function saveTypes(array $rows, string $byId, string $byLabel): array
    {
        if ($this->customisation === null) {
            return ['ok' => false, 'why' => 'Customisation storage is not available here.'];
        }

        // The HIDDEN_FOR_FACTS (marital status, gender) have no control on the
        // Leave Types screen — each is set once in config/leave-policy.json and
        // applies universally. This form never submits them, so a row missing
        // either key keeps whatever was already configured for that code rather
        // than being silently cleared.
        $existing = $this->types();
        $clean = [];
        $seen = [];

        foreach (array_values($rows) as $i => $row) {
            $n = $i + 1;
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));

            if ($code === '' && $name === '') {
                continue; // a blank row on the form is not a type
            }

            if (preg_match('/^[A-Z][A-Z0-9_]{0,11}$/', $code) !== 1) {
                return ['ok' => false, 'why' => "Row {$n}: a leave code is 1 to 12 capitals, digits or underscores (EL, CL, SL, ML) — '{$code}' is not."];
            }

            if ($name === '') {
                return ['ok' => false, 'why' => "Row {$n} ({$code}): a type needs a name."];
            }

            if (isset($seen[$code])) {
                return ['ok' => false, 'why' => "Row {$n}: {$code} is declared twice."];
            }

            $seen[$code] = true;
            $decimals = [];

            foreach (['opening_days', 'accrual_days_per_month', 'maximum_days'] as $field) {
                $v = trim((string) ($row[$field] ?? '0'));
                $v = $v === '' ? '0' : $v;

                try {
                    Rational::fromDecimalString($v);
                } catch (\Throwable) {
                    return ['ok' => false, 'why' => "Row {$n} ({$code}): '{$v}' is not a number of days for " . str_replace('_', ' ', $field) . '.'];
                }

                if (str_starts_with($v, '-')) {
                    return ['ok' => false, 'why' => "Row {$n} ({$code}): days cannot be negative."];
                }

                $decimals[$field] = $v;
            }

            $clean[] = [
                'code' => $code,
                'name' => $name,
                'paid' => self::yes($row['paid'] ?? false),
                'opening_days' => $decimals['opening_days'],
                'accrual_days_per_month' => $decimals['accrual_days_per_month'],
                'maximum_days' => $decimals['maximum_days'],
                'encashable' => self::yes($row['encashable'] ?? false),
                'carry_forward' => self::yes($row['carry_forward'] ?? true),
                'note' => trim((string) ($row['note'] ?? '')),
                'hidden_for_marital_status' => self::cleanList((array) (
                    $row['hidden_for_marital_status'] ?? $existing[$code]['hidden_for_marital_status'] ?? []
                )),
                'hidden_for_gender' => self::cleanList((array) (
                    $row['hidden_for_gender'] ?? $existing[$code]['hidden_for_gender'] ?? []
                )),
                // Company / Branch / Role / Designation — the screen's own
                // columns, after Note. Blank means ALL; only named dimensions
                // are kept, so a row with nothing chosen stores an empty scope
                // rather than four blank strings.
                'scope' => self::cleanScope((array) ($row['scope'] ?? $existing[$code]['scope'] ?? [])),
            ];
        }

        if ($clean === []) {
            return ['ok' => false, 'why' => 'At least one leave type is needed — a company with no leave types cannot record a leave.'];
        }

        return $this->customisation->saveRaw(self::KEY, ['types' => $clean], $byId, $byLabel, 'Leave types');
    }

    private static function yes(mixed $v): bool
    {
        return $v === true || in_array(strtolower(trim((string) $v)), ['1', 'yes', 'true', 'on', 'y'], true);
    }

    /**
     * @return list<string> trimmed, de-duplicated — not checked against
     *     config/employee-fields.json's own option lists (marital_status,
     *     gender), so a tenant that adds a value there is never blocked from
     *     hiding a type for it.
     */
    private static function cleanList(array $list): array
    {
        $out = [];

        foreach ($list as $v) {
            $v = trim((string) $v);

            if ($v !== '' && !in_array($v, $out, true)) {
                $out[] = $v;
            }
        }

        return $out;
    }

    /** @return array<string, string> only the named, non-blank dimensions */
    private static function cleanScope(array $scope): array
    {
        $out = [];

        foreach (self::SCOPE_DIMENSIONS as $dimension) {
            $v = trim((string) ($scope[$dimension] ?? ''));

            if ($v !== '') {
                $out[$dimension] = $v;
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    private static function keyed(array $list): array
    {
        $types = [];

        foreach ($list as $type) {
            $types[(string) $type['code']] = $type + ['carry_forward' => true];
        }

        return $types;
    }

    public function paidCodes(): array
    {
        return array_values(array_keys(array_filter($this->types(),
            static fn (array $type): bool => (bool) ($type['paid'] ?? false))));
    }

    /** @return array<string, mixed> */
    public function type(string $code): array
    {
        return $this->types()[$code] ?? throw new \InvalidArgumentException(
            "'{$code}' is not a leave type in the policy. An unknown type is refused rather than "
            . 'accepted and treated as unpaid, which would deduct a day nobody decided to deduct.'
        );
    }
}
