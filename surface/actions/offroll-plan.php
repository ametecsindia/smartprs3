<?php
/**
 * OFF-ROLL AGENT PAY STRUCTURE (23 Oct 2026): save one agent's structure from a date (op=save), download
 * the bulk sample (op=sample — every agent pre-listed with the structure in force), or upload it (op=import —
 * every row through the same checks as the form, good rows kept, refused rows listed). Each save is a new
 * version from its date; nothing is overwritten.
 */
declare(strict_types=1);

use SmartPRS\Surface\ModuleBulk;
use SmartPRS\Surface\OffrollPay;
use SmartPRS\Surface\Sheet;

include __DIR__ . '/../partials/offroll-boot.php';

$opOp = post('op');
$to = post('back') === 'offroll-agent' ? '?r=offroll-agent&id=' . rawurlencode(post('id')) . '&month=' . rawurlencode(post('month')) : '?r=offroll-pay-plans';

if (!$opMayEdit) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary;
    $opBack($to);
}

$who = (string) $session->user()['email'];
$cols = [$opCol('agent_id', 'Agent', 'text', true, $opAgentLookup), $opCol('effective_from', 'In force from', 'date', true)];

foreach ((array) $opD['plan_components'] as $k => $c) {
    $cols[] = $opCol((string) $k, (string) $c['label'], 'number');
}

$cols[] = $opCol('monthly_cap', 'Monthly cap (₹)', 'number');
$cols[] = $opCol('collection_slabs', 'Collection slabs (e.g. 2% up to 100000, 3% above)');
$cols[] = $opCol('note', 'Note');

// "2% up to 100000, 3% above" → the JSON the engine reads; JSON itself passes through.
$slabsFrom = static function (string $v): string {
    $v = trim($v);

    if ($v === '' || str_starts_with($v, '[')) {
        return $v;
    }

    $out = [];

    foreach (preg_split('/[,;]+/', $v) ?: [] as $part) {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*%\s*(?:up\s*to\s*(\d+(?:\.\d+)?)|above|\+)?\s*$/i', $part, $m) !== 1) {
            return 'x'; // unreadable: the engine refuses it with its own message
        }

        $out[] = ['upto' => (string) ($m[2] ?? ''), 'percent' => $m[1]];
    }

    return (string) json_encode($out);
};

if ($opOp === 'sample') {
    $data = [];
    $plans = $op->plansByAgent();

    foreach ($opAgents as $a) {
        $p = OffrollPay::planOn($plans[(string) $a['id']] ?? [], $a, date('Y-m-d'));
        $row = [$op->label($a), date('Y-m-01')];

        foreach (array_keys((array) $opD['plan_components']) as $k) {
            $row[] = trim((string) ($p[$k] ?? '')) === '' ? '' : \SmartPRS\Surface\Amount::of((string) $p[$k])->toDecimalString();
        }

        $row[] = trim((string) ($p['monthly_cap'] ?? '')) === '' ? '' : \SmartPRS\Surface\Amount::of((string) $p['monthly_cap'])->toDecimalString();
        $slabs = OffrollPay::parseSlabs((string) ($p['collection_slabs'] ?? '')) ?? [];
        $row[] = implode(', ', array_map(static fn (array $s): string => $s['percent'] . '% ' . ($s['upto'] === '' ? 'above' : 'up to ' . $s['upto']), $slabs));
        $row[] = '';
        $data[] = $row;
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="agent-pay-structure-bulk-upload-sample.xlsx"');
    echo ModuleBulk::sample($cols, $data);
    exit;
}

if ($opOp === 'import') {
    $up = (string) ($_FILES['sheet']['tmp_name'] ?? '');

    if (!is_uploaded_file($up)) {
        $_SESSION['flash_problem'] = 'No file was uploaded. Nothing was read.';
        $opBack($to);
    }

    try {
        $read = ModuleBulk::read($cols, Sheet::toCsv((string) file_get_contents($up), (string) ($_FILES['sheet']['name'] ?? 'upload.csv')));
    } catch (\Throwable $e) {
        $read = ['rows' => [], 'problem' => $e->getMessage()];
    }

    if ($read['problem'] !== null) {
        $_SESSION['flash_problem'] = $read['problem'];
        $opBack($to);
    }

    $saved = 0;
    $refused = [];

    foreach ($read['rows'] as $r) {
        $in = $r['input'];
        $a = $opAgents[(string) ($in['agent_id'] ?? '')] ?? null;

        if ($a === null) {
            $refused[] = 'Line ' . $r['line'] . ': pick an agent from the list.';
            continue;
        }

        $in['collection_slabs'] = $slabsFrom((string) ($in['collection_slabs'] ?? ''));
        $res = $op->savePlan($a, $in, $who);
        $res['ok'] ? $saved++ : $refused[] = 'Line ' . $r['line'] . ': ' . $res['why'];
        $res['ok'] && $audit('offroll.plan.saved', 'offroll_pay_plan', (string) $res['row']['id'], null, $res['row'], 'Bulk upload');
    }

    $_SESSION['flash_notice'] = $saved . ' pay structure(s) saved.';
    $refused === [] || $_SESSION['flash_problem'] = count($refused) . ' row(s) refused — fix and upload just those: ' . implode(' ', array_slice($refused, 0, 25));
    $opBack($to);
}

$a = $opAgents[post('id')] ?? null;

if ($a === null) {
    $_SESSION['flash_problem'] = 'No such agent.';
    $opBack($to);
}

$in = $_POST;
$slabs = [];

foreach ((array) ($_POST['slab_pct'] ?? []) as $i => $pct) {
    $pct = trim((string) $pct);
    $upto = trim(str_replace([',', '₹', ' '], '', (string) ($_POST['slab_upto'][$i] ?? '')));
    $pct === '' || $slabs[] = ['upto' => $upto, 'percent' => $pct];
}

$in['collection_slabs'] = $slabs === [] ? '' : (string) json_encode($slabs);
$res = $op->savePlan($a, $in, $who);
$res['ok'] && $audit('offroll.plan.saved', 'offroll_pay_plan', (string) $res['row']['id'], null, $res['row'], $res['why']);
$_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['why'];
$opBack($to);
