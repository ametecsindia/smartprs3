<?php
/**
 * OFF-ROLL AGENT PAYOUTS (23 Oct 2026): generate / regenerate a draft (op=generate), approve it (op=approve —
 * a second person; the Administrator may approve their own), discard a draft (op=discard), mark lines paid
 * (op=pay — date, mode, UTR; a vendor's lines together), hold / release a line (op=hold / release), and
 * download the payout register or the bank advice (op=advice — a formatted workbook). All but the downloads
 * need payroll.run; the downloads need employee.view.
 */
declare(strict_types=1);

use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Sheet;

include __DIR__ . '/../partials/offroll-boot.php';

$opOp = post('op');
$to = '?r=offroll-payouts' . (post('run') !== '' ? '&run=' . rawurlencode(post('run')) : '');
$perm = $opOp === 'advice' ? 'employee.view' : 'payroll.run';

if (!$may($perm)) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], $perm)->summary;
    $opBack($to);
}

$who = (string) $session->user()['email'];

if ($opOp === 'generate') {
    $co = post('employer_company_id');
    $res = $op->generate($co, post('period'), $who, static fn (string $from, string $last): string => (string) $calendar->workingDaysBetween($from, $last),
        financialYearStart($customisation), $opWindow);
    $res['ok'] && $audit('offroll.payout.generated', 'offroll_payout', (string) $res['run']['id'], null, ['period' => post('period'), 'company' => $co], $res['why']);
    $_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['why'];
    $opBack('?r=offroll-payouts' . ($res['ok'] ? '&run=' . rawurlencode((string) $res['run']['id']) : ''));
}

if ($opOp === 'approve' || $opOp === 'discard') {
    $res = $opOp === 'approve' ? $op->approve(post('run'), $who, $opIsAdmin) : $op->discard(post('run'));
    $res['ok'] && $audit('offroll.payout.' . ($opOp === 'approve' ? 'approved' : 'discarded'), 'offroll_payout', post('run'), null, null, $res['why']);
    $_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['why'];
    $opBack($opOp === 'discard' && $res['ok'] ? '?r=offroll-payouts' : $to);
}

if ($opOp === 'pay') {
    $ids = array_values(array_filter(array_map('strval', (array) ($_POST['lines'] ?? []))));

    if ($ids === []) {
        $_SESSION['flash_problem'] = 'Tick the lines that were paid.';
        $opBack($to === '?r=offroll-payouts' ? '?r=offroll-payouts&view=pending' : $to);
    }

    $res = $op->markPaid($ids, post('paid_on'), post('mode'), post('ref'), $who);
    $res['ok'] && $audit('offroll.payout.paid', 'offroll_payout_line', null, null, ['lines' => $ids, 'paid_on' => post('paid_on'), 'mode' => post('mode'), 'ref' => post('ref')], $res['why']);
    $_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['why'];
    $opBack($to === '?r=offroll-payouts' ? '?r=offroll-payouts&view=pending' : $to);
}

if ($opOp === 'hold' || $opOp === 'release') {
    $res = $op->hold(post('line'), $opOp === 'hold', post('reason'));
    $res['ok'] && $audit('offroll.payout.' . $opOp, 'offroll_payout_line', post('line'), null, ['reason' => post('reason')], $res['why']);
    $_SESSION[$res['ok'] ? 'flash_notice' : 'flash_problem'] = $res['why'];
    $opBack($to . '#line-' . rawurlencode(post('line')));
}

if ($opOp === 'advice') {
    $run = $op->run(post('run'));

    if ($run === null) {
        $_SESSION['flash_problem'] = 'No such payout run.';
        $opBack('?r=offroll-payouts');
    }

    $lines = array_values(array_filter($op->lines((string) $run['id']), static fn (array $l): bool => isset($opAgents[(string) $l['agent_id']])));
    $company = $opCompanies[(string) ($run['employer_company_id'] ?? '')] ?? '';
    $title = date('F Y', (int) strtotime((string) $run['period'] . '-01'));
    $dec = static fn (mixed $v): string => Amount::of((string) $v)->toDecimalString();
    $status = (array) $opD['payment_statuses'];

    if (post('what') === 'bank') {
        // ONE ROW PER PAYEE: an agent paid directly, or a vendor with all its agents' lines summed.
        $payees = [];

        foreach ($lines as $l) {
            $isV = (string) $l['paid_to'] === 'vendor';
            $key = $isV ? 'v' . $l['vendor_id'] : 'a' . $l['agent_id'];
            $who2 = $isV ? ($opVendors[(string) $l['vendor_id']] ?? []) : $opAgents[(string) $l['agent_id']];
            $payees[$key] ??= ['who' => $who2, 'vendor' => $isV, 'agents' => 0, 'net' => Money::zero(), 'tds' => Money::zero(), 'status' => []];
            $payees[$key]['agents']++;
            $payees[$key]['net'] = $payees[$key]['net']->plus(Amount::of((string) $l['net']));
            $payees[$key]['tds'] = $payees[$key]['tds']->plus(Amount::of((string) $l['tds_amount']));
            $payees[$key]['status'][(string) $l['payment_status']] = true;
        }

        $rows = [];
        $sum = Money::zero();

        foreach ($payees as $p) {
            $w = $p['who'];
            $rows[] = [$p['vendor'] ? (string) ($w['name'] ?? '') : $op->label($w), $p['vendor'] ? 'Vendor (' . $p['agents'] . ' agent(s))' : 'Agent',
                (string) ($w['pan'] ?? ''), (string) ($w['bank_name'] ?? ''), (string) ($w['bank_account'] ?? ''), (string) ($w['ifsc'] ?? ''), (string) ($w['upi'] ?? ''),
                $p['tds']->toDecimalString(), $p['net']->toDecimalString(), implode(', ', array_map(static fn (string $s): string => (string) ($status[$s] ?? $s), array_keys($p['status'])))];
            $sum = $sum->plus($p['net']);
        }

        $rows[] = ['Total', '', '', '', '', '', '', '', $sum->toDecimalString(), ''];
        $bytes = Sheet::report('Off-roll agents — bank advice — ' . $title, $company . ' · payout ' . (string) ($opD['payout_statuses'][(string) $run['status']] ?? $run['status']),
            ['Payee', 'Type', 'PAN', 'Bank', 'Account number', 'IFSC', 'UPI', 'TDS deducted', 'Net to pay', 'Payment status'], $rows, [7, 8], [count($rows) - 1], 'Bank advice');
        $file = 'offroll-bank-advice-' . $run['period'] . '.xlsx';
    } else {
        $rows = [];
        $tot = array_fill(0, 12, Money::zero());

        foreach ($lines as $l) {
            $a = $opAgents[(string) $l['agent_id']];
            $var = Amount::of((string) $l['day_amount'])->plus(Amount::of((string) $l['visit_amount']))->plus(Amount::of((string) $l['ptp_amount']))->plus(Amount::of((string) $l['paid_account_amount']));
            $vals = [$dec($l['amount_collected']), $dec($l['fixed_amount']), $var->toDecimalString(), $dec($l['collection_amount']), $dec($l['adjustments']), $dec($l['deductions']), $dec($l['gross']), $dec($l['tds_amount']), $dec($l['net'])];
            $rows[] = array_merge([$op->code($a), (string) ($a['name'] ?? ''), $opBranches[(string) ($a['branch_id'] ?? '')] ?? '', (string) $l['paid_to'] === 'vendor' ? (string) ($opVendors[(string) $l['vendor_id']]['name'] ?? 'Vendor') : 'Agent',
                rtrim(rtrim((string) $l['days_present'], '0'), '.') ?: '0', (string) (int) $l['visits'], (string) (int) $l['ptp_count'], (string) (int) $l['paid_accounts']], $vals,
                [(string) $l['tds_section'], (string) ($status[(string) $l['payment_status']] ?? $l['payment_status']), (string) ($l['paid_on'] ?? ''), (string) ($l['payment_ref'] ?? ''), (string) ($l['flags'] ?? '')]);

            foreach ($vals as $i => $v) {
                $tot[$i] = $tot[$i]->plus(Amount::of($v));
            }
        }

        $rows[] = array_merge(['Total', count($lines) . ' agent(s)', '', '', '', '', '', ''], array_map(static fn (Money $m): string => $m->toDecimalString(), array_slice($tot, 0, 9)), ['', '', '', '', '']);
        $bytes = Sheet::report('Off-roll agents — payout register — ' . $title, $company . ' · payout ' . (string) ($opD['payout_statuses'][(string) $run['status']] ?? $run['status']),
            ['Code', 'Agent', 'Branch', 'Paid to', 'Days', 'Visits', 'PTP', 'Accounts paid', 'Collected', 'Fixed', 'Per day / visit / PTP / paid', 'Collection pay', 'Adjustments', 'Deductions', 'Gross', 'TDS', 'Net', 'TDS section', 'Payment', 'Paid on', 'UTR', 'Flags'],
            $rows, [8, 9, 10, 11, 12, 13, 14, 15, 16], [count($rows) - 1], 'Payout register');
        $file = 'offroll-payout-register-' . $run['period'] . '.xlsx';
    }

    $audit('offroll.payout.exported', 'offroll_payout', (string) $run['id'], null, ['what' => post('what')], 'Downloaded');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    echo $bytes;
    exit;
}

$_SESSION['flash_problem'] = 'Unknown request.';
$opBack($to);
