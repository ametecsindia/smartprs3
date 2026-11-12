<?php
/**
 * COMMISSION ENTRIES — BULK IMPORT, ALL OR NOTHING (owner, 3 Nov 2026: "if any mandatory value is missing, it should stop
 * and give the row number with the error cell … similar to the Employee import in Directory"). Every row is checked
 * first — the employee, the scheme, the month, the Target Achieved, the scheme covering the employee, a Fixed Commission,
 * the scheme's validity — and a file with any bad cell imports NOTHING; the reply names each row and cell to fix
 * ("Row 7 · I7 Target Achieved: required"). A clean file is saved row by row through Modules::save (the Add form's
 * own rules, so the commission, TDS, net, purpose, portfolio and payout come from the scheme). The same employee +
 * scheme + month already on the register and not yet approved is UPDATED, not entered twice.
 */
declare(strict_types=1);

use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\ModuleBulk;
use SmartPRS\Surface\Sheet;

$ciModule = $modules->find('commissions');
$ciBack = '?r=' . moduleRoute('commissions');

if ($ciModule === null || !$may($modules->permission($ciModule, 'edit'))) {
    $_SESSION['flash_problem'] = 'Denied: you cannot add commission entries.';
    header('Location: ' . $ciBack);
    exit;
}

$ciUpload = (string) ($_FILES['sheet']['tmp_name'] ?? '');

if (!is_uploaded_file($ciUpload)) {
    $_SESSION['flash_problem'] = 'No file was uploaded. Nothing was read.';
    header('Location: ' . $ciBack);
    exit;
}

$ciFields = ModuleBulk::columns($modules->fields($ciModule));

try {
    $ciCsv = Sheet::toCsv((string) file_get_contents($ciUpload), (string) ($_FILES['sheet']['name'] ?? 'upload.csv'));
    $ciRead = ModuleBulk::read($ciFields, $ciCsv);
} catch (\Throwable $e) {
    $ciRead = ['rows' => [], 'problem' => $e->getMessage()];
}

if ($ciRead['problem'] !== null) {
    $_SESSION['flash_problem'] = 'Nothing was imported — ' . $ciRead['problem'];
    header('Location: ' . $ciBack);
    exit;
}

// WHICH COLUMN HOLDS WHICH FIELD — for the cell reference in each message (the codes row, as ModuleBulk::read finds it)
$ciCol = [];
$ciLabel = [];
foreach ($ciFields as $f) {
    $ciLabel[(string) $f['name']] = rtrim((string) $f['label'], ' *');
}
$ciH = fopen('php://memory', 'w+');
fwrite($ciH, $ciCsv);
rewind($ciH);
$ciBest = 0;
for ($i = 0; $i < 5 && ($cells = fgetcsv($ciH, 0, ',', '"', '')) !== false; $i++) {
    $map = [];
    foreach ($cells as $c => $v) {
        isset($ciLabel[strtolower(trim((string) $v))]) && $map[strtolower(trim((string) $v))] = $c;
    }
    count($map) > $ciBest && [$ciCol, $ciBest] = [$map, count($map)];
}
fclose($ciH);
$ciLetter = static function (int $c): string { $s = ''; for ($c++; $c > 0; $c = intdiv($c - 1, 26)) { $s = chr(65 + ($c - 1) % 26) . $s; } return $s; };
$ciCell = static fn (string $field, int $line): string => (isset($ciCol[$field]) ? $ciLetter($ciCol[$field]) . $line . ' ' : '') . ($ciLabel[$field] ?? $field);

$ciEmployees = [];
foreach (liveRows($store, 'employees') as $e) { $ciEmployees[(string) $e['id']] = $e; }
$ciSchemes = [];
foreach (liveRows($store, 'incentive_schemes') as $s) { $ciSchemes[(string) $s['id']] = $s; }
$ciRoles = (array) (orgNames($store)['designation_roles'] ?? []);
$ciExisting = [];
foreach (liveRows($store, 'commissions') as $c) {
    $ciExisting[(string) ($c['employee_id'] ?? '') . '|' . (string) ($c['scheme_id'] ?? '') . '|' . substr((string) ($c['month'] ?? ''), 0, 7)][] = $c;
}

$ciErrors = [];
$ciPlan = [];
$ciSeen = [];

foreach ($ciRead['rows'] as $r) {
    $in = $r['input'];
    // THE SAMPLE'S HINT ROW ("Pick from the list", "Required · Number …") is never data, whatever its exact wording
    if (array_filter($in, static fn (string $v): bool => $v !== '' && preg_match('/^(Required · )?(Pick from the list|Text|Number|Month|Date|Time|dd-mm-yyyy)/', $v) !== 1) === []) {
        continue;
    }
    $line = (int) $r['line'];
    $err = static function (string $field, string $what) use (&$ciErrors, $ciCell, $line): void { $ciErrors[] = 'Row ' . $line . ' · ' . $ciCell($field, $line) . ': ' . $what; };
    $before = count($ciErrors);

    $empId = trim((string) ($in['employee_id'] ?? ''));
    $emp = $ciEmployees[$empId] ?? null;
    $empId === '' ? $err('employee_id', 'required') : ($emp === null ? $err('employee_id', '"' . $empId . '" is not an employee — keep the name exactly as listed') : null);

    $schId = trim((string) ($in['scheme_id'] ?? ''));
    $sch = $ciSchemes[$schId] ?? null;
    $schId === '' ? $err('scheme_id', 'required') : ($sch === null ? $err('scheme_id', '"' . $schId . '" is not a scheme') : null);

    $month = trim((string) ($in['month'] ?? ''));
    preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1 || $err('month', $month === '' ? 'required' : '"' . $month . '" is not a month — write it as YYYY-MM, e.g. 2026-11');

    trim((string) ($in['claim_type'] ?? '')) === '' && $err('claim_type', 'required');

    $base = trim((string) ($in['base_amount'] ?? ''));
    $needsBase = $sch !== null && in_array(strtolower(trim((string) ($sch['rate_type'] ?? ''))), ['percent', 'slab'], true);
    if ($base === '' && $needsBase) {
        $err('base_amount', 'required — type what the employee achieved');
    } elseif ($base !== '' && preg_match('/^\d+(\.\d{1,2})?$/', str_replace(',', '', $base)) !== 1) {
        $err('base_amount', '"' . $base . '" is not an amount');
    }

    if ($emp !== null && $sch !== null) {
        if (Compensation::isFixedCommission($sch)) {
            $err('scheme_id', 'a Fixed Commission is paid automatically — not imported');
        } elseif (!Compensation::eligible($sch, $emp, $ciRoles)) {
            $err('employee_id', Employment::label($emp) . ' is not covered by scheme "' . (string) ($sch['name'] ?? '') . '"');
        } elseif (($fx = Compensation::fixedSchemeFor(array_values($ciSchemes), $emp, $ciRoles, $month)) !== null) {
            $err('employee_id', Employment::label($emp) . ' is covered as part of Fixed Commission "' . (string) ($fx['name'] ?? '') . '" — no custom commission entry allowed');
        } elseif (!$modules->rowVisible($ciModule, ['employee_id' => $empId])) {
            $err('employee_id', 'that employee is outside your reach');
        } elseif ($month !== '' && !Compensation::liveIn($sch, $month)) {
            $err('month', 'scheme "' . (string) ($sch['name'] ?? '') . '" is not active for ' . $month . ' (status / Valid From – Till)');
        }
    }

    $key = $empId . '|' . $schId . '|' . $month;
    if (count($ciErrors) === $before && isset($ciSeen[$key])) {
        $err('employee_id', 'the same employee, scheme and month is already on row ' . $ciSeen[$key] . ' of this file');
    }
    $ciSeen[$key] ??= $line;

    // ALREADY ON THE REGISTER: a pending one is updated with this row; an approved / paid one is not touched
    $id = '';
    foreach ($ciExisting[$key] ?? [] as $old) {
        $st = strtolower(trim((string) ($old['status'] ?? '')));
        if (in_array($st, ['approved', 'confirmed', 'paid'], true)) {
            $err('employee_id', 'entry #' . (string) $old['id'] . ' for this employee, scheme and month is already ' . (string) $old['status'] . ' — change it on the screen');
        } elseif (!in_array($st, ['rejected', 'clawed back'], true)) {
            $id = (string) $old['id'];
        }
    }

    // THE REGISTER'S OWN FIELD CHECKS (required, lists, lookups, formats) — run here too, so a file is never half imported
    if (count($ciErrors) === $before) {
        foreach ($modules->problems($ciModule, ($id === '' ? [] : ['id' => $id]) + $in) as $why) {
            $ciErrors[] = 'Row ' . $line . ': ' . $why;
        }
    }

    count($ciErrors) === $before && $ciPlan[] = ['line' => $line, 'input' => ($id === '' ? [] : ['id' => $id]) + $in];
}

if ($ciErrors !== []) {
    $_SESSION['flash_problem'] = 'Nothing was imported — ' . count($ciErrors) . ' cell(s) to fix, then upload the same file again: '
        . implode(' · ', array_slice($ciErrors, 0, 30)) . (count($ciErrors) > 30 ? ' … and ' . (count($ciErrors) - 30) . ' more' : '');
    header('Location: ' . $ciBack);
    exit;
}

$ciNew = 0;
$ciUpd = 0;
$ciFail = [];
foreach ($ciPlan as $p) {
    $res = $modules->save($ciModule, $p['input']);
    if ($res['ok']) {
        isset($p['input']['id']) ? $ciUpd++ : $ciNew++;
        $audit('module.row.saved', 'commissions', (string) ($res['row']['id'] ?? ''), null, $res['row'], 'Commission Entries bulk import');
    } else {
        $ciFail[] = 'Row ' . $p['line'] . ': ' . $res['why'];
    }
}

$_SESSION['flash_notice'] = $ciNew . ' commission entr' . ($ciNew === 1 ? 'y' : 'ies') . ' added' . ($ciUpd > 0 ? ', ' . $ciUpd . ' updated' : '')
    . ' — commission, TDS and net payable worked out by each scheme.';
$ciFail === [] || $_SESSION['flash_problem'] = count($ciFail) . ' row(s) refused on save: ' . implode(' · ', array_slice($ciFail, 0, 25));
header('Location: ' . $ciBack);
exit;
