<?php
/**
 * SAVE A YEAR'S INCOME-TAX RULES (owner, 15 Oct) — administrators (or payroll.structure.publish).
 * Starts from the year as it stands (so labels, items, salary codes and proof texts carry over) and
 * replaces only what the form edits. Checked before saving; stored as a Customisation version.
 */
declare(strict_types=1);

use SmartPRS\Surface\Amount;

$fy = trim(post('fy'));
$back = '?r=tds-rules&fy=' . rawurlencode($fy);
$fail = static function (string $why) use ($back): never { $_SESSION['flash_problem'] = $why; header('Location: ' . $back . '&edit=1'); exit; };
($may('payroll.structure.publish') || isAdministrator()) || $fail('Denied: editing income-tax rules needs an administrator.');
preg_match('/^\d{4}-\d{2}$/', $fy) === 1 || $fail('Pick a financial year.');
$byId = (string) ($session->user()['id'] ?? '');
$by = (string) ($session->user()['email'] ?? '');

if (post('revert') === '1') {
    $r = $taxDeclarations->saveRules($fy, null, $byId, $by);
    $r['ok'] && $audit('tax.rules.reverted', 'module_customisations', 'income-tax-rules', null, ['fy' => $fy], 'Income-tax rules reverted');
    $_SESSION[$r['ok'] ? 'flash_notice' : 'flash_problem'] = $r['ok'] ? 'The ' . $fy . ' rules are back to the shipped ones.' : $r['why'];
    header('Location: ' . $back);
    exit;
}

$Y = $taxDeclarations->year($fy);
$Y === [] && $fail('There are no rules to start from for ' . $fy . '.');
unset($Y['source'], $Y['carried_forward_from']);

// A rupee / percent figure as the decimal string the rules file uses; '' stays ''.
$rupees = static function (mixed $x, string $what) use ($fail): string {
    $x = trim(str_replace([',', '₹', ' '], '', (string) $x));
    if ($x === '') { return ''; }
    preg_match('/^\d+(\.\d{1,2})?$/', $x) === 1 || $fail($what . ': "' . $x . '" is not an amount.');
    return ltrim($x, '0') === '' || ltrim($x, '0')[0] === '.' ? '0' . ltrim($x, '0') : ltrim($x, '0');
};
$pct = static function (mixed $x, string $what) use ($fail): string {
    $x = trim((string) $x);
    preg_match('/^(100(\.0{1,2})?|\d{1,2}(\.\d{1,2})?)$/', $x) === 1 || $fail($what . ': "' . $x . '" is not a percentage between 0 and 100.');
    return $x;
};
$slabs = static function (array $rows, string $what) use ($rupees, $pct, $fail): array {
    $out = [];
    $last = -1;
    foreach (array_values($rows) as $r) {
        $u = trim((string) ($r['upto'] ?? ''));
        $p = trim((string) ($r['pct'] ?? ''));
        if ($u === '' && $p === '') { continue; }
        $p === '' && $fail($what . ': a slab has no rate.');
        $out[] = ['upto' => $u === '' ? null : $rupees($u, $what), 'pct' => $pct($p, $what)];
    }
    $out === [] && $fail($what . ': give at least one slab.');
    foreach ($out as $i => $s) {
        if ($s['upto'] === null) { $i === count($out) - 1 || $fail($what . ': only the last slab may be open-ended ("and above").'); continue; }
        (int) Amount::of($s['upto'])->minor > $last || $fail($what . ': slab limits must rise.');
        $last = (int) Amount::of($s['upto'])->minor;
    }
    end($out)['upto'] === null || $fail($what . ': the last slab must be open-ended — leave its "up to" blank.');

    return $out;
};
$surcharge = static function (array $rows, string $what) use ($rupees, $pct): array {
    $out = [];
    foreach (array_values($rows) as $r) {
        if (trim((string) ($r['above'] ?? '')) === '' && trim((string) ($r['pct'] ?? '')) === '') { continue; }
        $out[] = ['above' => $rupees($r['above'] ?? '', $what), 'pct' => $pct($r['pct'] ?? '', $what)];
    }
    usort($out, static fn (array $a, array $b): int => Amount::of($a['above'])->minor <=> Amount::of($b['above'])->minor);

    return $out;
};

$Y['cess_pct'] = $pct(post('cess_pct'), 'Cess');
$Y['default_regime'] = post('default_regime') === 'old' ? 'old' : 'new';
$Y['round_taxable_to'] = max(1, min(100, (int) post('round_taxable_to')));

foreach (['new' => 'New regime', 'old' => 'Old regime'] as $k => $label) {
    $in = (array) ($_POST[$k] ?? []);
    $Y['regimes'][$k]['standard_deduction'] = $rupees($in['standard_deduction'] ?? '', $label . ' standard deduction') ?: '0';
    $Y['regimes'][$k]['rebate'] = ['income_upto' => $rupees($in['rebate_upto'] ?? '', $label . ' rebate limit') ?: '0', 'max' => $rupees($in['rebate_max'] ?? '', $label . ' rebate') ?: '0',
        'marginal_relief' => $k === 'new' ? ($in['marginal_relief'] ?? '') === '1' : (bool) ($Y['regimes'][$k]['rebate']['marginal_relief'] ?? false)];
    $Y['regimes'][$k]['surcharge'] = $surcharge((array) ($in['surcharge'] ?? []), $label . ' surcharge');
}
$Y['regimes']['new']['slabs'] = $slabs((array) ($_POST['new']['slabs'] ?? []), 'New regime slabs');
foreach (['below_60' => 'below 60', '60_to_79' => '60 to 79', '80_plus' => '80 and above'] as $k => $l) {
    $Y['regimes']['old']['slabs_by_age'][$k] = $slabs((array) ($_POST['old']['slabs'][$k] ?? []), 'Old regime slabs (' . $l . ')');
}

foreach ((array) ($Y['groups'] ?? []) as $i => $g) {
    $key = (string) $g['key'];
    isset($g['cap'], $_POST['groupcap'][$key]) && $Y['groups'][$i]['cap'] = $rupees($_POST['groupcap'][$key], (string) $g['label']) ?: '0';
    if (isset($g['cap_pct_of_salary'], $_POST['grouppct'][$key])) {
        $p = $pct($_POST['grouppct'][$key], (string) $g['label']);
        $Y['groups'][$i]['cap_pct_of_salary'] = ['new' => $p, 'old' => $p];
    }
}
foreach ((array) ($Y['items'] ?? []) as $i => $it) {
    foreach ((array) ($_POST['itemcap'][(string) $it['code']] ?? []) as $ck => $val) {
        in_array($ck, ['cap', 'cap_if_senior_self', 'cap_if_senior_parents'], true) && isset($it[$ck]) && $Y['items'][$i][$ck] = $rupees($val, (string) $it['label']) ?: '0';
    }
}

$h = (array) ($_POST['hra'] ?? []);
$Y['hra']['metro_pct'] = $pct($h['metro_pct'] ?? '', 'HRA metro %');
$Y['hra']['other_pct'] = $pct($h['other_pct'] ?? '', 'HRA other %');
$Y['hra']['rent_less_pct_of_salary'] = $pct($h['rent_less_pct_of_salary'] ?? '', 'HRA rent less %');
$Y['hra']['metro_cities'] = array_values(array_filter(array_map('trim', explode(',', (string) ($h['metro_cities'] ?? ''))), static fn (string $c): bool => $c !== ''));
$Y['label'] = 'Tax Year ' . $fy . ' (edited by ' . $by . ' on ' . date('d-m-Y') . ')';

$r = $taxDeclarations->saveRules($fy, $Y, $byId, $by);
$r['ok'] || $fail($r['why']);
$audit('tax.rules.saved', 'module_customisations', 'income-tax-rules', null, ['fy' => $fy], 'Income-tax rules for ' . $fy);
$_SESSION['flash_notice'] = 'Income-tax rules for ' . $fy . ' saved — payroll TDS, declarations and Form 16 for ' . $fy . ' now use them.';
header('Location: ' . $back);
exit;
