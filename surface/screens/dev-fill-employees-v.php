<?php declare(strict_types=1);
// DEV ONLY (12 Oct): fill the blank Directory cells of the sample joiners (joined since 1 Feb 2026)
// the way the older sample employees are filled. Only BLANK cells are written; lookups (company,
// branch, department …) are never touched. Confirmation Date stays blank while someone is on
// probation, and Date of Exit stays blank for active people — both are correct when empty.
// ?r=dev-fill-employees-v (dry run, lists what it would write) · &go=1 writes. Delete after.
use SmartPRS\Surface\Employment;

if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
set_time_limit(0);
$fvGo = (string) ($_GET['go'] ?? '') === '1';
$fvBy = (string) ($session->user()['email'] ?? 'admin');
$fvFields = $employeeFields->inUse();
$fvAll = liveRows($store, 'employees');
$fvNew = array_values(array_filter($fvAll, static fn (array $e): bool => (string) ($e['doj'] ?? '') >= '2026-02-01' && trim((string) ($e['name'] ?? '')) !== ''
    && !str_starts_with(Employment::code($e), 'ADM')));  // the seeded admin login account is not a sample employee
$fvOld = array_values(array_filter($fvAll, static fn (array $e): bool => (string) ($e['doj'] ?? '') !== '' && (string) $e['doj'] < '2026-02-01'));
$fvVal = static function (array $e, array $f, array $extras): string {
    return trim((string) ($f['storage'] === 'extra' ? ($extras[(string) $f['code']] ?? '') : ($e[(string) $f['column']] ?? '')));
};
$fvHash = static fn (string $s, int $mod): int => (int) (sprintf('%u', crc32($s)) % $mod);
$fvDigits = static fn (string $seed, int $n): string => substr(str_repeat((string) sprintf('%u', crc32($seed)) . (string) sprintf('%u', crc32(strrev($seed))), 3), 0, $n);
$fvDay = static fn (string $d, string $mod): string => date('Y-m-d', strtotime($d . ' ' . $mod));

// Extras of every employee, read once.
$fvExtras = [];
foreach ($fvAll as $e) { $fvExtras[(string) $e['id']] = $employeeExtras->forEmployment(Employment::code($e)); }
$fvByLabel = [];
foreach ($fvFields as $f) { $fvByLabel[(string) $f['label']] = $f; }
$fvGet = static fn (array $e, string $label): string => isset($fvByLabel[$label]) ? $fvVal($e, $fvByLabel[$label], $fvExtras[(string) $e['id']] ?? []) : '';

$fvBio = 0;
foreach ($fvAll as $e) { $fvBio = max($fvBio, (int) $fvGet($e, 'Biometric Device User ID')); }

$fvLog = [];
$fvCells = 0;
foreach ($fvNew as $ni => $e) {
    $code = Employment::code($e);
    $branch = (string) ($e['branch_id'] ?? '');
    $peers = array_values(array_filter($fvOld, static fn (array $o): bool => (string) ($o['branch_id'] ?? '') === $branch)) ?: $fvOld;
    $donor = $peers[$ni % count($peers)];
    $first = trim((string) ($e['first_name'] ?? '')) ?: (string) explode(' ', (string) $e['name'])[0];
    $last = trim((string) ($e['last_name'] ?? '')) ?: (string) (array_slice(explode(' ', (string) $e['name']), -1)[0] ?? '');
    $married = strcasecmp($fvGet($e, 'Marital Status'), 'Married') === 0;
    $dob = (string) ($e['dob'] ?? '') ?: $fvGet($e, 'Date of Birth');
    $doj = (string) $e['doj'];
    $stage = strtolower($fvGet($e, 'Employee Stage'));
    $cols = [];
    $extras = [];

    foreach ($fvFields as $f) {
        // Lookups are left alone except the two that follow the branch, copied from a colleague at the same branch.
        if ((($f['control'] ?? '') === 'lookup' && !in_array((string) $f['label'], ['Work Location', 'Cost Code'], true)) || $fvVal($e, $f, $fvExtras[(string) $e['id']] ?? []) !== '') { continue; }
        $label = (string) $f['label'];
        $d = $fvVal($donor, $f, $fvExtras[(string) $donor['id']] ?? []);
        $v = match (true) {
            $label === 'Date of Exit', $label === 'Confirmation Date' && $stage !== 'confirmed' => '',
            $label === 'Confirmation Date' => max($doj, min(date('Y-m-d'), ($pe = $fvGet($e, 'Probation End Date')) !== '' && $pe >= $doj ? $pe : $fvDay($doj, '+6 months'))),
            $label === "Mother's Name" => trim(explode(' ', $d)[0] . ' ' . $last),
            $label === 'Spouse Name' => $married ? (string) ['Lakshmi', 'Divya', 'Kiran', 'Priya', 'Anil', 'Suresh'][$fvHash($code, 6)] . ' ' . $last : 'NA',
            $label === 'Emergency Contact Relation' => $married ? 'Spouse' : 'Father',
            $label === 'Personal Email' => strtolower($first . substr($last, 0, 3)) . substr($dob, 2, 2) . '@mail.demo.smartprs.local',
            $label === 'WhatsApp Number' => preg_replace('/\D/', '', (string) ($e['mobile'] ?? '')) ?: '9' . $fvDigits($code . 'w', 9),
            $label === 'Alternate Contact Number' => (string) [7, 8, 9][$fvHash($code, 3)] . $fvDigits($code . 'a', 9),
            $label === 'Photograph' => 'uploads/2/employees/photo_path__' . $code . '.png',
            $label === 'Biometric Device User ID' => (string) (++$fvBio),
            str_starts_with($label, 'Present Address — House'), str_starts_with($label, 'Permanent Address — House')
                => (1 + $fvHash($code . $label, 16)) . '-' . (1 + $fvHash($code, 9)) . '-' . (100 + $fvHash($label . $code, 800)),
            $label === 'National ID' => chr(65 + $fvHash($code . '1', 26)) . chr(65 + $fvHash($code . '2', 26)) . chr(65 + $fvHash($code . '3', 26)) . $fvDigits($code . 'n', 7),
            $label === 'Passport Number' => chr(75 + $fvHash($code, 12)) . $fvDigits($code . 'p', 7),
            $label === 'Passport Expiry Date' => $fvDay($doj, '+9 years'),
            $label === 'ESIC Number' => $fvDigits($code . 'e', 10),
            $label === 'PCC Certificate Expiry Date', $label === 'DRA Certificate Expiry Date' => $fvDay($doj, '+3 years'),
            $label === 'PCC Deadline' => $fvDay($doj, '+30 days'),
            $label === 'Year of Passing' => $dob === '' ? $d : (string) ((int) substr($dob, 0, 4) + 21),
            default => $d,
        };
        if ($v === '') { continue; }
        $f['storage'] === 'extra' ? $extras[(string) $f['code']] = $v : $cols[(string) $f['column']] = $v;
        $fvLog[] = "{$code}\t{$label}\t{$v}";
        $fvCells++;
    }

    if ($fvGo) {
        $cols !== [] && $store->put('employees', ['id' => $e['id']] + $cols);
        $extras !== [] && $employeeExtras->save($code, $extras, $fvBy);
    }
}

if ($fvGo) {
    $audit('employee.sample_fill', 'employees', null, null, ['cells' => $fvCells], 'Sample data: blank Directory cells filled');
}
file_put_contents(__DIR__ . '/../../storage/dev-fill-employees.tsv', implode("\n", $fvLog));
?><pre id="fv"><?= e(($fvGo ? 'WROTE ' : 'DRY RUN — would write ') . $fvCells . ' cell(s) for ' . count($fvNew) . ' employee(s). Detail: storage/dev-fill-employees.tsv') ?></pre>
