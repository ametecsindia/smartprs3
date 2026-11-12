<?php declare(strict_types=1);
// DEV ONLY (13 Oct, owner: dropdowns only, values from Organization Configuration). Every employee
// field that is a dropdown, holding a name typed before the dropdown existed ("Manoj Krishna Reddy",
// "Kondapur, Hyderabad"), is rewritten to the option it means — see lookupMatch(). Values that match
// nothing are listed, not touched. ?apply=1 writes; without it, a dry run. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$mzApply = (string) ($_GET['apply'] ?? '') === '1';
$mzLookups = employeeLookups($store);
$mzFixed = [];
$mzLeft = [];
foreach (liveRows($store, 'employees') as $mzE) {
    $mzCode = SmartPRS\Surface\Employment::code($mzE);
    $mzExtras = $employeeExtras->forEmployment($mzCode);
    $mzCol = [];
    $mzExt = [];
    foreach ($employeeFields->inUse() as $mzF) {
        if (($mzF['control'] ?? '') !== 'lookup') { continue; }
        $mzOpts = $mzLookups[(string) ($mzF['lookup'] ?? '')] ?? [];
        $mzIsCol = ($mzF['storage'] ?? 'column') === 'column';
        $mzV = trim($mzIsCol ? (string) ($mzE[(string) $mzF['column']] ?? '') : (string) ($mzExtras[(string) $mzF['code']] ?? ''));
        if ($mzV === '' || $mzOpts === [] || array_key_exists($mzV, $mzOpts) || $mzV === (string) ($mzF['none_option']['value'] ?? "\0")) { continue; }
        // "None", "NA", "-" typed into a dropdown field means no value: cleared.
        if (preg_match('/^(none|n\/?a|nil|-+)$/i', $mzV) === 1) {
            $mzIsCol ? $mzCol[(string) $mzF['column']] = null : $mzExt[(string) $mzF['code']] = '';
            $mzFixed[] = "{$mzCode} · {$mzF['label']}: '{$mzV}' → (blank)";
            continue;
        }
        // AI2001 was "Karthik Mohan Yadav" until renamed on 13 Oct; the old name is still his.
        $mzKey = (string) ($mzF['lookup'] ?? '') === 'employees' && strcasecmp($mzV, 'Karthik Mohan Yadav') === 0 && isset($mzOpts['37'])
            ? '37' : lookupMatch($mzOpts, $mzV);
        if ($mzKey === null) { $mzLeft[] = "{$mzCode} · {$mzF['label']}: '{$mzV}'"; continue; }
        $mzIsCol ? $mzCol[(string) $mzF['column']] = $mzKey : $mzExt[(string) $mzF['code']] = $mzKey;
        $mzFixed[] = "{$mzCode} · {$mzF['label']}: '{$mzV}' → '{$mzOpts[$mzKey]}'";
    }
    if ($mzApply && $mzCol !== []) { $store->put('employees', ['id' => $mzE['id']] + $mzCol); }
    if ($mzApply && $mzExt !== []) { $employeeExtras->save($mzCode, $mzExt, (string) ($session->user()['email'] ?? '')); }
}
?><pre id="mz"><?= e(($mzApply ? 'APPLIED' : 'DRY RUN') . ' — ' . count($mzFixed) . " mapped, " . count($mzLeft) . " unmatched\n\nMAPPED\n" . implode("\n", $mzFixed) . "\n\nUNMATCHED\n" . implode("\n", $mzLeft)) ?></pre>
