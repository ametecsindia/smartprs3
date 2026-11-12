<?php
/**
 * D6 — statutory_settings, late_policy, app_settings and the rest of the
 * configuration whose hard deletion makes past payslips unexplainable.
 *
 * statutory_settings is a JSON blob per tenant holding the entire PF/ESI/PT/TDS
 * rate set, with no effective dating, no audit and no versioning — and it is a
 * table no migration creates. If pf_esi_settings / tds_rules / pt_slabs ALSO hold
 * rows, one of the two sources was authoritative for past payrolls and the other
 * is wrong: that is a compliance exposure, not a data question (J-… PO-2).
 *
 * Credentials (SMTP passwords are stored in plaintext) are NEVER written to the
 * pack. Only presence and length are recorded (R-XXIX-023).
 *
 * Read-only. Standalone: php checks/d6_config_blobs.php --app=/path/to/smartprs2
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';

function d6_config_blobs(DscCtx $c): void
{
    // D6.1  The statutory blob, per tenant. Configuration, not personal data — dumped whole.
    if (dsc_has_table($c, 'statutory_settings')) {
        $cols = array_keys(dsc_cols($c, 'statutory_settings'));
        $rows = dsc_q($c, 'SELECT * FROM statutory_settings ORDER BY '
            . (in_array('tenant_id', $cols, true) ? 'tenant_id, ' : '') . 'id', [], 'd6.1');
        dsc_write_csv($c, 'blobs/statutory_settings.csv', $rows, 'none');

        foreach ($rows as $r) {
            $tid = (string) ($r['tenant_id'] ?? 'NULL');
            $key = (string) ($r['key'] ?? $r['ckey'] ?? ('row' . ($r['id'] ?? '')));
            $val = (string) ($r['value'] ?? $r['cval'] ?? '');
            dsc_write_text($c, "blobs/statutory_settings/{$tid}/" . preg_replace('/[^A-Za-z0-9_.-]+/', '_', $key) . '.json', $val);
            if ($val !== '' && json_decode($val) === null && json_last_error() !== JSON_ERROR_NONE) {
                $c->finding('HIGH', 'config', "statutory_settings[$tid][$key] is not valid JSON",
                    'The statutory rate set for this tenant cannot be parsed. Past payslips derived from '
                    . 'it are not re-derivable until the customer restates the rates.');
            }
        }
        $c->finding('HIGH', 'config', count($rows) . ' statutory_settings rows',
            'R-XXIX-022: decompose into effective-dated rows. The analysts recommend dating from the '
            . "tenant's earliest payroll run — see blobs/earliest_payroll_run.csv. There is no effective "
            . 'dating, no audit and no versioning in the source, so every historical rate change is lost.');
    } else {
        $c->finding('HIGH', 'config', 'statutory_settings table is ABSENT on this install',
            'This is a runtime-created table (SettingsController.php:82). Absent means the Settings screen '
            . 'was never saved here — so where did the PF/ESI/PT/TDS rates used in past payrolls come from? '
            . 'Check pf_esi_settings / tds_rules / pt_slabs and statutory_configs below.');
    }

    // D6.2  The effective_from candidate: earliest payroll run per tenant/company.
    if (dsc_has_table($c, 'payroll_runs')) {
        $cols = array_keys(dsc_cols($c, 'payroll_runs'));
        $sel = ['tenant_id', 'company_id'];
        $sel = array_values(array_intersect($sel, $cols));
        if ($sel) {
            $g = implode(', ', array_map('dsc_id', $sel));
            $extra = [];
            foreach (['period_start', 'month', 'created_at'] as $col) {
                if (in_array($col, $cols, true)) {
                    $extra[] = 'MIN(' . dsc_id($col) . ') AS earliest_' . $col;
                }
            }
            dsc_write_csv($c, 'blobs/earliest_payroll_run.csv',
                dsc_q($c, "SELECT $g, " . implode(', ', $extra) . ", COUNT(*) AS runs
                             FROM payroll_runs GROUP BY $g ORDER BY $g", [], 'd6.2'), 'none');
        }
    }

    // D6.3  Blob vs superseded tables: if BOTH hold rows, one of them is wrong.
    $sources = [];
    foreach (['pf_esi_settings', 'tds_rules', 'pt_slabs', 'statutory_configs', 'statutory_settings', 'min_wages'] as $t) {
        $sources[] = ['table' => $t, 'present' => dsc_has_table($c, $t) ? 'yes' : 'no',
            'rows' => dsc_has_table($c, $t) ? (int) dsc_val($c, 'SELECT COUNT(*) FROM ' . dsc_id($t), [], 'd6.3:' . $t, 0) : 0];
    }
    dsc_write_csv($c, 'blobs/statutory_sources.csv', $sources, 'none');

    $populated = array_values(array_filter($sources, static fn($r) =>
        $r['rows'] > 0 && in_array($r['table'], ['pf_esi_settings', 'tds_rules', 'pt_slabs'], true)));
    $blobRows = 0;
    foreach ($sources as $r) {
        if ($r['table'] === 'statutory_settings') {
            $blobRows = $r['rows'];
        }
    }
    if ($populated && $blobRows > 0) {
        $c->finding('BLOCKER', 'config', 'BOTH the statutory blob and the superseded rate tables hold rows',
            'J-… PO-2, escalated by R-XXIX-022 as a COMPLIANCE EXPOSURE, not a migration question: '
            . 'statutory rates used in past payrolls may differ from what the blob says, and a past '
            . 'payslip may not be re-derivable. Populated: '
            . implode(', ', array_map(static fn($r) => $r['table'] . '=' . $r['rows'], $populated))
            . "; statutory_settings=$blobRows.",
            ['tables' => $populated]);
    }

    // D6.4  statutory_configs — the one effective-dated, scoped, indexed config table.
    if (dsc_has_table($c, 'statutory_configs')) {
        $cols = array_keys(dsc_cols($c, 'statutory_configs'));
        $want = array_values(array_intersect(
            ['id', 'tenant_id', 'kind', 'scope_type', 'scope_value', 'effective_from', 'effective_to', 'created_at'], $cols));
        $sel = implode(', ', array_map('dsc_id', $want));
        if (in_array('payload', $cols, true)) {
            $sel .= ', LEFT(payload, 2000) AS payload_head, LENGTH(payload) AS payload_bytes';
        }
        $order = implode(', ', array_map('dsc_id',
            array_values(array_intersect(['tenant_id', 'kind', 'effective_from'], $cols)) ?: ['id']));
        dsc_write_csv($c, 'blobs/statutory_configs.csv',
            dsc_q($c, "SELECT $sel FROM statutory_configs ORDER BY $order", [], 'd6.4'), 'none');
    }

    // D6.5  Late policy: is the late-penalty engine live anywhere at all?
    if (dsc_has_table($c, 'late_policy')) {
        $rows = dsc_q($c, 'SELECT * FROM late_policy ORDER BY id', [], 'd6.5');
        dsc_write_csv($c, 'blobs/late_policy.csv', $rows, 'none');
        $configured = 0;
        foreach ($rows as $r) {
            if (($r['mode'] ?? '') !== '' && ($r['mode'] ?? null) !== null
                && ($r['cut_mode'] ?? '') !== '' && ($r['cut_mode'] ?? null) !== null) {
                $configured++;
            }
        }
        $c->finding($configured === 0 ? 'HIGH' : 'INFO', 'config',
            'late_policy: ' . count($rows) . ' rows, ' . $configured . ' with both mode and cut_mode set',
            $configured === 0
                ? 'resolvePolicy() defaults mode to "simple" and simpleCut() returns 0.0 when cut_mode is '
                  . 'unset. With none configured, the entire late-penalty engine is DEAD IN THE FIELD here: '
                  . 'the SmartPRS 3 requirement is greenfield, not migration (B-… OQ-B-06).'
                : 'The late-penalty engine is configured on this install and its behaviour must be reproduced.',
            ['rows' => count($rows), 'configured' => $configured]);
    }

    // D6.6  Shifts: the cross-midnight question, answered from data.
    if (dsc_has_cols($c, 'shifts', ['start_time', 'end_time'])) {
        $rows = dsc_q($c, 'SELECT *, (end_time <= start_time) AS looks_overnight FROM shifts ORDER BY id', [], 'd6.6');
        dsc_write_csv($c, 'blobs/shifts.csv', $rows, 'none');
        $overnight = array_values(array_filter($rows, static fn($r) => (int) ($r['looks_overnight'] ?? 0) === 1));
        $c->finding($overnight ? 'BLOCKER' : 'INFO', 'config',
            count($overnight) . ' of ' . count($rows) . ' shifts look cross-midnight',
            $overnight
                ? 'No ingest path is shift-aware: a 22:00–06:00 shift yields log_date D for the IN and D+1 '
                  . 'for the OUT, and presentDays() counts TWO present days for one shift. Every payroll '
                  . 'period covering these shifts is overstated. See defects/cross_midnight_*.csv.'
                : 'No overnight shift is configured here, so the cross-midnight attendance defect has no '
                  . 'configured surface on this install (still check the attendance evidence).',
            ['overnight' => count($overnight)]);
    }

    // D6.7  app_settings — per-company SMTP and branding. SMTP passwords are PLAINTEXT.
    if (dsc_has_table($c, 'app_settings')) {
        $cols = array_keys(dsc_cols($c, 'app_settings'));
        $keyCol = in_array('ckey', $cols, true) ? 'ckey' : (in_array('key', $cols, true) ? 'key' : null);
        $valCol = in_array('cval', $cols, true) ? 'cval' : (in_array('value', $cols, true) ? 'value' : null);
        if ($keyCol && $valCol) {
            $rows = dsc_q($c, 'SELECT id, ' . (in_array('tenant_id', $cols, true) ? 'tenant_id, ' : '')
                . dsc_id($keyCol) . ' AS ckey, LENGTH(' . dsc_id($valCol) . ') AS value_bytes, '
                . 'LEFT(' . dsc_id($valCol) . ', 120) AS value_head'
                . (in_array('updated_at', $cols, true) ? ', updated_at' : '')
                . ' FROM app_settings ORDER BY id', [], 'd6.7');

            // Never carry a credential out of the customer's estate, even truncated.
            $secretish = static fn(string $k) => (bool) preg_match('/pass|secret|token|key|auth|cred/i', $k);
            foreach ($rows as &$r) {
                if ($secretish((string) $r['ckey'])) {
                    $r['value_head'] = '[CREDENTIAL WITHHELD: ' . $r['value_bytes'] . ' bytes present]';
                }
            }
            unset($r);
            dsc_write_csv($c, 'blobs/app_settings.csv', $rows, 'none');

            $creds = array_values(array_filter($rows, static fn($r) => $secretish((string) $r['ckey']) && (int) $r['value_bytes'] > 0));
            if ($creds) {
                $c->finding('HIGH', 'config', count($creds) . ' credential values found in app_settings',
                    'R-XXIX-023: these must be re-encrypted under SmartPRS 3 key management during transfer, '
                    . 'never written to the pack, and a decryption failure must alert rather than fall back to '
                    . 'treating ciphertext as plaintext. Values were withheld from this pack; only presence '
                    . 'and length are recorded. SMTP passwords are stored in plaintext in SmartPRS 2.',
                    ['keys' => array_column($creds, 'ckey')]);
            }
        }
    }

    // Other credential stores, presence only.
    $credTables = [];
    foreach (['biometric_configs' => ['password', 'pass', 'secret'],
              'sms_settings' => ['api_key', 'password', 'secret'],
              'wa_settings' => ['token', 'api_key', 'secret'],
              'api_keys' => ['key_hash', 'token']] as $t => $cands) {
        if (!dsc_has_table($c, $t)) {
            continue;
        }
        $cols = array_keys(dsc_cols($c, $t));
        foreach ($cands as $col) {
            if (!in_array($col, $cols, true)) {
                continue;
            }
            $q = dsc_id($col);
            $r = dsc_q($c, "SELECT COUNT(*) AS rows_, SUM($q IS NOT NULL AND $q <> '') AS populated,
                                   MIN(LENGTH($q)) AS min_len, MAX(LENGTH($q)) AS max_len
                              FROM " . dsc_id($t), [], "cred:$t.$col");
            $credTables[] = ['table' => $t, 'column' => $col, 'rows' => (int) ($r[0]['rows_'] ?? 0),
                'populated' => (int) ($r[0]['populated'] ?? 0), 'min_len' => (int) ($r[0]['min_len'] ?? 0),
                'max_len' => (int) ($r[0]['max_len'] ?? 0)];
        }
    }
    dsc_write_csv($c, 'blobs/credential_presence.csv', $credTables, 'none');

    // D6.8  pay_cycles — a runtime-only table that defines the payroll period.
    if (dsc_has_table($c, 'pay_cycles')) {
        dsc_write_csv($c, 'blobs/pay_cycles.csv', dsc_q($c, 'SELECT * FROM pay_cycles ORDER BY id', [], 'd6.8'), 'none');
    } else {
        $c->finding('HIGH', 'config', 'pay_cycles is ABSENT',
            'The payroll period definition table does not exist here. Payroll periods were whatever '
            . 'cycle_label free text the operator typed. Reconstructing the period boundaries for past '
            . 'runs is a customer conversation (§XXIX.5), not a data transformation.');
    }

    // D6.9  Configuration whose hard deletion makes past payslips unexplainable.
    foreach ([
        ['holidays', 'SELECT tenant_id, COUNT(*) AS c, MIN(date) AS lo, MAX(date) AS hi FROM holidays GROUP BY tenant_id ORDER BY tenant_id'],
        ['leave_types', 'SELECT * FROM leave_types ORDER BY id'],
        ['salary_components', 'SELECT * FROM salary_components ORDER BY id'],
        ['min_wages', 'SELECT * FROM min_wages ORDER BY id'],
        ['leave_types_usage', null],
    ] as [$name, $sql]) {
        if ($sql === null || !dsc_has_table($c, $name)) {
            continue;
        }
        dsc_write_csv($c, "blobs/$name.csv", dsc_q($c, $sql, [], 'd6.9:' . $name), 'none');
    }

    // salary_components.calc_value: is a formula still a formula on this install?
    if (dsc_has_cols($c, 'salary_components', ['calc_value'])) {
        $type = strtolower((string) (dsc_cols($c, 'salary_components')['calc_value']['DATA_TYPE'] ?? ''));
        $formulas = (int) dsc_val($c, "SELECT COUNT(*) FROM salary_components
                                        WHERE calc_value REGEXP '[A-Za-z]'", [], 'd6.calc', 0);
        $c->finding($type !== 'varchar' && $type !== 'char' && $type !== 'text' ? 'BLOCKER' : 'INFO', 'config',
            "salary_components.calc_value is $type; $formulas rows still contain letters",
            $type === 'decimal'
                ? 'This install stores calc_value as DECIMAL. Every formula ("0.4 * BASIC") that was ever '
                  . 'entered has already been truncated to a number, and the original expression is not '
                  . 'recoverable from the database. Payroll on this install does not compute what the '
                  . 'configuration screen appears to say (J-… §9 Q2 M3).'
                : 'Formula strings survive on this install.',
            ['data_type' => $type, 'rows_with_letters' => $formulas]);
    }

    dsc_finish($c, 'd6_config_blobs');
}

if (realpath($_SERVER['argv'][0] ?? '') === realpath(__FILE__)) {
    dsc_standalone('d6_config_blobs.php', 'd6_config_blobs');
}
