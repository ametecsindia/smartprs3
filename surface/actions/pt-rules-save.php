<?php
/**
 * PT SLABS — save, remove or restore Professional Tax rules (Organization Setup → PT Slabs, owner
 * 28 Oct 2026). The whole set is stored as a new Customisation version ('pt-rules'), so every earlier
 * set stays in history. An edit that moves "Effective from" later is ADDED beside the rule it replaces
 * (nothing overwritten): a re-run of an earlier month still finds the slabs that applied then.
 */
declare(strict_types=1);

use SmartPRS\Surface\Dates;
use SmartPRS\Surface\PtRules;

$ptBack = '?r=pt-slabs';
$ptGo = static function (string $to): never { header('Location: ' . $to); exit; };

if (!($may('payroll.structure.publish') || isAdministrator())) {
    $_SESSION['flash_problem'] = 'Denied: changing PT slabs needs an administrator (or payroll structure publish).';
    $ptGo($ptBack);
}

$ptR = PtRules::for($store, __DIR__ . '/../../config');
$ptRules = $ptR->rules();
$ptOp = post('op');
$ptById = (string) ($session->user()['id'] ?? '');
$ptBy = (string) ($session->user()['email'] ?? '');

if ($ptOp === 'reset') {
    $res = $customisation->saveRaw(PtRules::KEY, ['use_shipped' => true], $ptById, $ptBy, 'PT Slabs: back to the Government schedule');
    $res['ok'] && $audit('pt.rules.reset', 'module_customisations', PtRules::KEY, null, null, 'PT Slabs restored to the Government schedule');
    $_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['ok'] ? 'PT Slabs are back to the Government schedule — payroll uses it from now.' : $res['why'];
    $ptGo($ptBack);
}

if ($ptOp === 'delete') {
    $id = post('id');
    $left = array_values(array_filter($ptRules, static fn (array $r): bool => $r['id'] !== $id));
    if (count($left) === count($ptRules)) {
        $_SESSION['flash_problem'] = 'That PT rule is no longer there — nothing was removed.';
        $ptGo($ptBack);
    }
    $res = $customisation->saveRaw(PtRules::KEY, ['rules' => $left], $ptById, $ptBy, 'PT Slabs: rule removed');
    $res['ok'] && $audit('pt.rules.removed', 'module_customisations', PtRules::KEY, null, ['id' => $id], 'PT rule removed');
    $_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['ok'] ? 'PT rule removed. The earlier set is kept in history.' : $res['why'];
    $ptGo($ptBack);
}

// SAVE ONE RULE (new or edited).
$upto = (array) ($_POST['upto'] ?? []);
$amount = (array) ($_POST['amount'] ?? []);
$special = (array) ($_POST['special'] ?? []);
$slabs = [];
foreach (array_keys($amount) as $i) {
    $slabs[] = ['upto' => (string) ($upto[$i] ?? ''), 'amount' => (string) $amount[$i], 'special' => (string) ($special[$i] ?? '')];
}
$from = post('effective_from');
$in = PtRules::normalise([
    'id' => post('id'),
    'name' => post('name'),
    'state' => post('state'),
    'company_id' => post('company_id'),
    'branch_id' => post('branch_id'),
    'gender' => post('gender'),
    'frequency' => post('frequency'),
    'months' => (array) ($_POST['months'] ?? []),
    'months_set' => true,
    'exempt_age' => post('exempt_age'),
    'exempt_pwd' => post('exempt_pwd') === '1',
    'exempt_types' => (array) ($_POST['exempt_types'] ?? []),
    'slab_on' => post('slab_on'),
    'slabs' => $slabs,
    'special_month' => post('special_month'),
    'annual_cap' => post('annual_cap'),
    'effective_from' => $from === '' ? '' : (Dates::toIso($from) ?? $from),
    'active' => post('active') !== '0',
    'source' => 'tenant',
]);
// No month chosen for the different amount: its column means nothing.
$in['special_month'] === 0 && $in['slabs'] = array_map(static fn (array $s): array => ['special' => ''] + $s, $in['slabs']);
if ($in['name'] === '') {
    $in['name'] = $in['state'] . ($in['gender'] !== 'all' ? ' — ' . strtolower(PtRules::GENDERS[$in['gender']]) : '');
}

$idx = null;
foreach ($ptRules as $i => $r) {
    $r['id'] === $in['id'] && $in['id'] !== '' && $idx = $i;
}
$kept = false;
if ($idx === null) {
    $in['id'] = 'r' . bin2hex(random_bytes(4));
    $ptRules[] = $in;
} elseif ($in['effective_from'] > $ptRules[$idx]['effective_from']) {
    // LATER DATE = A NEW VERSION: the rule as it was stays for the months before it.
    $in['id'] = 'r' . bin2hex(random_bytes(4));
    array_splice($ptRules, $idx + 1, 0, [$in]);
    $kept = true;
} else {
    $ptRules[$idx] = $in;
}

$problems = PtRules::problems($ptRules);
if ($problems !== []) {
    $_SESSION['pt_draft'] = ['id' => $idx === null ? '' : (string) post('id')] + $in;
    $_SESSION['flash_problem'] = implode(' ', $problems);
    $ptGo($ptBack . '&edit=' . rawurlencode($idx === null ? 'new' : (string) post('id')) . '#pt-' . ($idx === null ? 'new' : rawurlencode((string) post('id'))));
}

$res = $customisation->saveRaw(PtRules::KEY, ['rules' => array_values($ptRules)], $ptById, $ptBy, 'PT Slabs: ' . $in['name']);
if (!$res['ok']) {
    $_SESSION['pt_draft'] = ['id' => $idx === null ? '' : (string) post('id')] + $in;
    $_SESSION['flash_problem'] = 'Not saved: ' . $res['why'];
    $ptGo($ptBack . '&edit=' . rawurlencode($idx === null ? 'new' : (string) post('id')));
}
$audit('pt.rules.saved', 'module_customisations', PtRules::KEY, null, ['rule' => $in['name'], 'state' => $in['state'], 'effective_from' => $in['effective_from']], 'PT rule saved: ' . PtRules::summary($in));
$_SESSION['flash_notice'] = 'PT rule "' . $in['name'] . '" saved — payroll and PT Applicable use it'
    . ($in['effective_from'] !== '' ? ' from ' . date('d-m-Y', (int) strtotime($in['effective_from'])) : '') . '.'
    . ($kept ? ' The earlier version is kept for the months before that date.' : '');
$ptGo($ptBack . '#pt-' . rawurlencode($in['id']));
