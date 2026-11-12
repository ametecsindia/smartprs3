<?php
/**
 * OFF-ROLL AGENT DAILY ACTIVITY (23 Oct 2026): record / correct one day (op=save, employee.update), approve or
 * reject ticked days (op=decide, payroll.run — a second person; the Administrator may decide their own),
 * download the bulk sheet for a date with every agent pre-listed (op=sample) and upload it (op=import —
 * every row through the same checks as the form; good rows kept, refused rows listed with line and reason).
 */
declare(strict_types=1);

use SmartPRS\Surface\Dates;
use SmartPRS\Surface\ModuleBulk;
use SmartPRS\Surface\Sheet;

include __DIR__ . '/../partials/offroll-boot.php';

$opOp = post('op');
$to = '?r=offroll-activity&month=' . rawurlencode(post('month') ?: date('Y-m'));
$perm = $opOp === 'decide' ? 'payroll.run' : 'employee.update';

if (!$may($perm)) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], $perm)->summary;
    $opBack($to);
}

$who = (string) $session->user()['email'];
$st = (array) $opD['day_statuses'];
$cols = [
    $opCol('agent_id', 'Agent', 'text', true, $opAgentLookup),
    $opCol('activity_date', 'Date', 'date', true),
    $opCol('day_status', 'Day', 'text', true, [], array_map(static fn (array $s): string => (string) $s['label'], $st)),
    $opCol('check_in', 'Check-in (hh:mm)', 'time'),
    $opCol('check_out', 'Check-out (hh:mm)', 'time'),
    $opCol('allocated_accounts', 'Accounts allotted', 'number'),
    $opCol('visits', 'Visits', 'number'),
    $opCol('ptp_count', 'Promises to pay', 'number'),
    $opCol('paid_accounts', 'Accounts paid', 'number'),
    $opCol('amount_collected', 'Amount collected (₹)', 'number'),
    $opCol('remarks', 'Remarks'),
];

if ($opOp === 'sample') {
    $day = Dates::toIso(post('day')) ?? date('Y-m-d');
    $data = [];

    foreach ($opAgents as $a) {
        $inCo = (post('f_company') === '' || (string) ($a['employer_company_id'] ?? '') === post('f_company'))
            && (post('f_branch') === '' || (string) ($a['branch_id'] ?? '') === post('f_branch')) && (post('vendor') === '' || (string) ($a['vendor_id'] ?? '') === post('vendor'));
        $deployed = (trim((string) ($a['joining_date'] ?? '')) === '' || substr((string) $a['joining_date'], 0, 10) <= $day)
            && (trim((string) ($a['exit_date'] ?? '')) === '' || substr((string) $a['exit_date'], 0, 10) >= $day);

        if ($inCo && $deployed && !in_array(strtolower((string) ($a['status'] ?? '')), ['terminated'], true)) {
            $have = $op->day((string) $a['id'], $day);
            $data[] = [$op->label($a), $day, $have === null ? '' : (string) ($st[(string) $have['day_status']]['label'] ?? ''), (string) ($have['check_in'] ?? ''), (string) ($have['check_out'] ?? ''),
                (string) ($have['allocated_accounts'] ?? ''), (string) ($have['visits'] ?? ''), (string) ($have['ptp_count'] ?? ''), (string) ($have['paid_accounts'] ?? ''),
                trim((string) ($have['amount_collected'] ?? '')) === '' ? '' : \SmartPRS\Surface\Amount::of((string) $have['amount_collected'])->toDecimalString(), (string) ($have['remarks'] ?? '')];
        }
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="agent-activity-' . $day . '-bulk-upload.xlsx"');
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

    $byLabel = [];

    foreach ($st as $k => $s) {
        $byLabel[strtolower((string) $s['label'])] = (string) $k;
        $byLabel[strtolower((string) $s['short'])] = (string) $k;
        $byLabel[strtolower((string) $k)] = (string) $k;
    }

    $saved = 0;
    $refused = [];

    foreach ($read['rows'] as $r) {
        $in = $r['input'];

        if (trim((string) ($in['day_status'] ?? '')) === '' && trim((string) ($in['visits'] ?? '')) === '') {
            continue; // an agent left blank on the pre-listed sheet: nothing recorded for them
        }

        $a = $opAgents[(string) ($in['agent_id'] ?? '')] ?? null;

        if ($a === null) {
            $refused[] = 'Line ' . $r['line'] . ': pick an agent from the list.';
            continue;
        }

        $in['day_status'] = $byLabel[strtolower(trim((string) ($in['day_status'] ?? '')))] ?? (string) ($in['day_status'] ?? '');
        $res = $op->saveDay($a, $in, $who, 'upload');
        $res['ok'] ? $saved++ : $refused[] = 'Line ' . $r['line'] . ': ' . $res['why'];
    }

    $saved > 0 && $audit('offroll.activity.uploaded', 'offroll_activity', null, null, ['saved' => $saved, 'refused' => count($refused)], 'Bulk upload of agent activity');
    $_SESSION['flash_notice'] = $saved . ' agent-day(s) recorded from the sheet — awaiting approval.';
    $refused === [] || $_SESSION['flash_problem'] = count($refused) . ' row(s) refused — fix and upload just those: ' . implode(' ', array_slice($refused, 0, 25));
    $opBack($to);
}

if ($opOp === 'decide') {
    $back = post('back');
    $back = str_starts_with($back, '?r=offroll-activity') ? $back : $to;
    $ids = array_values(array_filter(array_map('strval', (array) ($_POST['ids'] ?? []))));

    if ($ids === []) {
        $_SESSION['flash_problem'] = 'Tick the days to approve or reject.';
        $opBack($back);
    }

    $res = $op->decideDays($ids, post('decision') === 'approve', $who, post('remarks'), $opIsAdmin);
    $res['done'] > 0 && $audit('offroll.activity.' . (post('decision') === 'approve' ? 'approved' : 'rejected'), 'offroll_activity', null, null, ['ids' => $ids], $res['why']);
    $_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['why'];
    $opBack($back);
}

$a = $opAgents[post('agent_id')] ?? null;

if ($a === null) {
    $_SESSION['flash_problem'] = 'Pick the agent.';
    $opBack($to);
}

$res = $op->saveDay($a, $_POST, $who, 'manual');
$res['ok'] && $audit('offroll.activity.saved', 'offroll_activity', (string) $res['row']['id'], null, $res['row'], $res['why']);
$_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['why'];
$opBack($to . ($res['ok'] ? '' : '&ea=' . rawurlencode((string) $a['id']) . '&date=' . rawurlencode(post('activity_date')) . '#entry'));
