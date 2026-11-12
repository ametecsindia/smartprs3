<?php
/**
 * LATE LOGIN POLICY — save or remove one policy (Organization Setup /
 * Configuration → Late Login Policy, owner 7 Oct). The narrowing beyond the
 * company (Branch, Department, Role, Designation, Team, Employee, Staff type,
 * Employee type) is stored as JSON in `scope_target` with scope = Filters —
 * see LatePolicy::criteria(). Only columns the table really has are written.
 */
declare(strict_types=1);

use SmartPRS\Surface\LatePolicy;

$lpBack = '?r=late-policy';

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
    header('Location: ' . $lpBack);
    exit;
}

if (post('op') === 'delete') {
    $lpModule = $modules->find('late-policy');
    $lpResult = $lpModule === null ? ['ok' => false, 'why' => 'The Late Login Policy register is not declared.'] : $modules->retire($lpModule, post('id'));
    $lpResult['ok'] && $audit('late_policy.retired', 'late_policy', post('id'), null, null, $lpResult['why']);
    $_SESSION[$lpResult['ok'] ? 'flash_notice' : 'flash_problem'] = $lpResult['why'];
    header('Location: ' . $lpBack);
    exit;
}

$lpNum = static fn (string $k): ?string => preg_match('/^\d+(\.\d+)?$/', trim(post($k))) === 1 ? trim(post($k)) : null;
$lpProblems = [];

$lpApplies = [];
foreach (array_keys(LatePolicy::APPLIES_TO) as $lpKey) {
    $lpKey === 'company' || trim(post('ap_' . $lpKey)) === '' || $lpApplies[$lpKey] = trim(post('ap_' . $lpKey));
}

$lpMode = in_array(post('mode'), ['Simple', 'Tiered', 'Net_hours'], true) ? post('mode') : 'Simple';
// THE DEDUCTION PLAN (owner, 14 Oct, second pass): by how late, by number of late logins, or
// every N late logins — any number of rows. The old columns are still written (first three rows,
// cut_mode / cut_n) so every screen that reads them keeps reading something sensible.
$lpPlanBy = in_array(post('deduct_by'), ['time', 'count', 'every'], true) ? post('deduct_by') : 'time';
$lpHalf = static fn (string $d): bool => $d === '' || preg_match('/^\d+(\.(0+|50*))?$/', $d) === 1;
$lpPlanRows = [];
if ($lpPlanBy !== 'every') {
    $lpT = (array) ($_POST[$lpPlanBy . '_t'] ?? []);
    $lpD = (array) ($_POST[$lpPlanBy . '_d'] ?? []);
    foreach ($lpT as $i => $t) {
        $t = trim((string) $t);
        $d = trim((string) ($lpD[$i] ?? ''));
        if ($d === '' && $t === '') { continue; }
        $lpHalf($d) || $lpProblems[] = "Row " . ($i + 1) . ": the deduction must be in half days (½, 1, 1½ …)";
        $d !== '' && $t !== '' && preg_match('/^\d+$/', $t) !== 1 && $lpProblems[] = "Row " . ($i + 1) . ": '{$t}' is not a whole number";
        $lpPlanBy === 'count' && $d !== '' && (int) $t < 1 && $lpProblems[] = 'Row ' . ($i + 1) . ': give which late login of the month it starts from (1 or more)';
        $d !== '' && $lpPlanRows[] = [(string) (int) $t, $d];
    }
    usort($lpPlanRows, static fn (array $a, array $b): int => (int) $a[0] <=> (int) $b[0]);
}
$lpEveryN = max(1, (int) post('every_n'));
$lpEveryD = trim(post('every_d'));
$lpPlanBy === 'every' && !$lpHalf($lpEveryD) && $lpProblems[] = 'The deduction must be in half days (½, 1, 1½ …)';
$_POST['cut_mode'] = $lpPlanBy === 'every' ? 'Every_n' : 'Every';
$_POST['cut_n'] = $lpPlanBy === 'every' ? (string) $lpEveryN : '';
foreach ([1, 2, 3] as $t) {
    $_POST["l{$t}_min"] = $lpPlanBy === 'every' ? ($t === 1 ? '' : '') : ($lpPlanRows[$t - 1][0] ?? '');
    $_POST["l{$t}_cut"] = $lpPlanBy === 'every' ? ($t === 1 ? $lpEveryD : '') : ($lpPlanRows[$t - 1][1] ?? '');
}

$lpCut = in_array(post('cut_mode'), ['None', 'Half_day_per_late', 'Full_day_per_late', 'One_day_per_n', 'Half_day_per_n', 'Every', 'Every_n'], true) ? post('cut_mode') : 'None';
$lpCutN = $lpNum('cut_n');

if (str_ends_with(strtolower($lpCut), '_n') && ((int) $lpCutN) < 1) {
    $lpProblems[] = 'N must be 1 or more for a "per N lates" rule';
}

foreach ([1, 2, 3] as $t) {
    $c = trim(post("l{$t}_cut"));

    if ($c !== '' && preg_match('/^\d+(\.(0+|50*))?$/', $c) !== 1) {
        $lpProblems[] = "Tier {$t} cut must be in half days (0.5, 1, 1.5 …)";
    }
}

$lpRow = [
    'employer_company_id' => trim(post('ap_company')) === '' ? null : trim(post('ap_company')),
    'scope' => 'Filters',
    'scope_target' => $lpApplies === [] ? '' : json_encode($lpApplies, JSON_UNESCAPED_UNICODE),
    'grace_min' => (int) post('grace_min'),
    'lates_before_cut' => (int) post('lates_before_cut'),
    'no_cut_on_weekoff' => post('no_cut_on_weekoff') === '0' ? '0' : '1',
    'mode' => $lpMode,
    'cut_mode' => $lpCut,
    'cut_n' => $lpCutN,
    'addl_late' => '',
    'full_day_hours' => $lpNum('full_day_hours'),
    'half_day_hours' => $lpNum('half_day_hours'),
    'weekoff_action' => in_array(post('weekoff_action'), ['Ignore', 'Comp_off', 'Pay_ot'], true) ? post('weekoff_action') : null,
    'break_budget' => post('break_budget') === '' ? null : (int) post('break_budget'),
    'break_cut' => in_array(post('break_cut'), ['None', 'Half_day', 'Per_30min'], true) ? post('break_cut') : null,
    'break_over_badge' => post('break_over_badge') ?: null,
];

foreach ([1, 2, 3] as $t) {
    $lpRow["l{$t}_min"] = post("l{$t}_min") === '' ? null : (int) post("l{$t}_min");
    $lpRow["l{$t}_cut"] = trim(post("l{$t}_cut")) === '' ? null : trim(post("l{$t}_cut"));
    $lpRow["l{$t}_badge"] = post("l{$t}_badge") ?: null;
}

if (strlen((string) $lpRow['scope_target']) > 255) {
    $lpProblems[] = 'Too many names picked under "Applies to" to store — narrow it';
}

if ($lpProblems !== []) {
    $_SESSION['flash_problem'] = 'Not saved: ' . implode('; ', $lpProblems) . '.';
    header('Location: ' . $lpBack . (post('id') !== '' ? '&edit=' . rawurlencode(post('id')) : '&edit=new'));
    exit;
}

// ONLY COLUMNS THE TABLE HAS — the store refuses an unknown column.
$store instanceof SmartPRS\Surface\SqlStore && $lpRow = array_intersect_key($lpRow, array_flip($store->columnsOf('late_policy')));
post('id') !== '' && $lpRow['id'] = post('id');
$lpBefore = post('id') !== '' ? $store->find('late_policy', post('id')) : null;
$lpSaved = $store->put('late_policy', $lpRow);
// LATE MINUTES COUNTED FROM (owner, 14 Oct) — kept per policy id as a Customisation version.
$lpBasisMap = LatePolicy::basis($store);
$lpSavedId = (string) ($lpSaved['id'] ?? post('id'));
// TIME BASED OR COUNT BASED (owner, 14 Oct) — same Customisation key, its own map.
$lpByMap = LatePolicy::deductBy($store);
$lpFromNew = post('late_from') === 'grace' ? 'grace' : 'start';
$lpByNew = $lpPlanBy === 'count' ? 'count' : 'time';
$lpPlansMap = LatePolicy::plans($store);
$lpPlanNew = ['by' => $lpPlanBy, 'rows' => $lpPlanRows, 'every_n' => $lpEveryN, 'every_d' => $lpEveryD];
if ($lpSavedId !== '' && (($lpBasisMap[$lpSavedId] ?? 'start') !== $lpFromNew || ($lpByMap[$lpSavedId] ?? 'time') !== $lpByNew
    || ($lpPlansMap[$lpSavedId] ?? null) !== $lpPlanNew)) {
    $lpBasisMap[$lpSavedId] = $lpFromNew;
    $lpByMap[$lpSavedId] = $lpByNew;
    $lpPlansMap[$lpSavedId] = $lpPlanNew;
    $customisation->saveRaw(LatePolicy::BASIS_KEY, ['policies' => $lpBasisMap, 'deduct_by' => $lpByMap, 'plans' => $lpPlansMap], (string) ($session->user()['id'] ?? ''),
        (string) $session->user()['email'], 'Late minutes counted from: ' . ($lpFromNew === 'grace' ? 'after grace period' : 'start of shift')
        . '; deduct based on: ' . ($lpByNew === 'count' ? 'count of late logins' : 'time late') . ' (policy #' . $lpSavedId . ')');
}
$audit(post('id') === '' ? 'late_policy.created' : 'late_policy.updated', 'late_policy', (string) ($lpSaved['id'] ?? ''), $lpBefore, $lpRow, 'Late Login Policy');
$_SESSION['flash_notice'] = 'Late Login Policy saved. Payroll uses it from the next generate / regenerate.';
header('Location: ' . $lpBack);
exit;
