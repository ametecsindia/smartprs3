<?php

declare(strict_types=1);

/**
 * A COLUMN THAT READS AS SENSITIVE MUST SAY WHO MAY SEE IT.
 *
 * The generic module screen renders whatever `config/modules.json` declares, to
 * whoever holds the module's view permission — which for most modules is
 * `employee.view`, the permission an ordinary employee's viewer role holds. So
 * every salary figure, every API key and every password hash named in that file
 * was on a colleague's screen until it was given a `permission`.
 *
 * The fix is per column and was applied by hand. THIS TEST IS THE RATCHET: the
 * next person who adds a module with a `secret` column fails the build instead
 * of shipping it. It is a NAME check, not a semantic one — a column called
 * `amount` is presumed to be money until somebody says otherwise in the
 * allow-list below, with a reason, because "it turned out to be fine" has to be
 * written down by a person rather than assumed by a regex.
 */

group('sensitive fields');

/** Column-name parts that mean "this is money, a credential, or an identity". */
$sensitive = ['password', 'secret', 'token', 'key', 'salary', 'ctc', 'amount',
    'bank', 'ifsc', 'account', 'pan', 'aadhaar', 'uan', 'esic'];

/**
 * Columns whose NAME matches but whose VALUE does not need locking. Each line is
 * a decision somebody made, not an exemption somebody inherited.
 */
$allowed = [
    // WHICH LENDER an escalation is about (HDFC, SBI) — the client bank's name on a
    // field/ops register, not anybody's account.
    'escalations.bank',
    // The basis (monthly/daily/hourly), not the figure — the figure is `ctc`.
    'employees.salary_type',
    // A mode flag on the TDS rule table, not anybody's salary.
    'tds-rules.salary_tds_mode',
    // A yes/no rule ("deduct at the higher rate when no PAN is on file").
    'tds-rules.higher_if_no_pan',
    // Professional-tax slab amounts: published by the state, the same for everyone.
    'pt-slabs.amount',
    // The setting's NAME column ("payroll.cycle"), not a credential.
    'settings.key',
    // The client bank an off-roll agent is authorised to collect for — not bank details.
    'agent-authorizations.bank',
];

$registry = json_decode((string) file_get_contents(__DIR__ . '/../config/modules.json'), true);
ok(is_array($registry['modules'] ?? null), 'the module registry parses');

$checked = 0;

foreach ($registry['modules'] as $module) {
    $key = (string) $module['key'];
    $fields = (array) ($module['fields'] ?? []);

    // Both the list columns and the form fields: a column can be printed in a
    // table cell without ever appearing on the form, and that cell is a leak.
    $columns = array_unique(array_merge(
        array_map('strval', (array) $module['columns']),
        array_map('strval', array_keys($fields)),
    ));

    foreach ($columns as $column) {
        $parts = explode('_', $column);
        $looksSensitive = array_intersect($parts, $sensitive) !== []
            || str_contains($column, 'file_path');

        if (!$looksSensitive || in_array("{$key}.{$column}", $allowed, true)) {
            continue;
        }

        ++$checked;
        $declared = (string) ($fields[$column]['permission'] ?? '');

        ok($declared !== '', sprintf(
            '%s.%s reads as sensitive, so it declares a permission (or is allow-listed in %s '
            . 'with a reason) — otherwise it renders to anyone holding %s',
            $key,
            $column,
            basename(__FILE__),
            (string) ($module['permissions']['view'] ?? 'employee.view'),
        ));
    }
}

ok($checked > 20, "the scan found sensitive columns to check (saw {$checked})");

// A permission that is not in the catalogue fails closed, which is safe but
// reads as a bug to whoever hits it. So every one declared here must be real.
$catalogue = require __DIR__ . '/../src/Platform/Access/catalogue.php';

foreach ($registry['modules'] as $module) {
    foreach (array_merge([$module['permissions'] ?? []], array_values((array) ($module['fields'] ?? []))) as $spec) {
        foreach (['permission', 'view', 'edit'] as $slot) {
            $code = (string) (((array) $spec)[$slot] ?? '');

            if ($code !== '') {
                ok(isset($catalogue[$code]), "{$module['key']}: {$code} is a catalogued permission");
            }
        }
    }
}
