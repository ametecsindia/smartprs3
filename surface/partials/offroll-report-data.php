<?php declare(strict_types=1);
/**
 * OFF-ROLL AGENT PAY REPORTS — the rows (23 Oct 2026). Included by the screen and by the export action with
 * $params set (and offroll-boot.php already included). Sets $rep = [key, title, subtitle, head, rows,
 * money (0-based columns holding amounts), bold (row indexes), filters (what the report takes)].
 *
 *   register    payout register of a month (one row per agent line), all companies or one
 *   pending     every approved line not yet paid, oldest month first
 *   statement   one agent's financial year: month by month earned, TDS, net, paid
 *   vendor      vendor-wise totals of a month
 *   tds         TDS deducted in a financial year quarter, per payee, with PAN and section (for the return)
 *   activity    a month's attendance and field work per agent
 *   compliance  every agent's DRA, PCC and bank letter, worst first
 */
use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\OffrollPay;

$repKey = (string) ($params['rep'] ?? 'register');
$repKey = in_array($repKey, ['register', 'pending', 'statement', 'vendor', 'tds', 'activity', 'compliance'], true) ? $repKey : 'register';
$repMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($params['month'] ?? '')) === 1 ? (string) $params['month'] : date('Y-m', strtotime('first day of last month'));
$repCo = (string) ($params['f_company'] ?? '');
$fyStart = financialYearStart($customisation);
$repFy = preg_match('/^\d{4}$/', (string) ($params['fy'] ?? '')) === 1 ? (int) $params['fy'] : (int) substr(OffrollPay::fyOf(date('Y-m'), $fyStart)[0], 0, 4);
$repQ = max(0, min(4, (int) ($params['q'] ?? 0)));
$repAgent = (string) ($params['agent'] ?? '');
$dec = static fn (mixed $v): string => Amount::of((string) $v)->toDecimalString();
$runsAll = array_column($op->runs(), null, 'id');
$linesAll = array_values(array_filter($op->lines(), static fn (array $l): bool => isset($opAgents[(string) $l['agent_id']], $runsAll[(string) $l['payout_id']])
    && ($repCo === '' || (string) ($runsAll[(string) $l['payout_id']]['employer_company_id'] ?? '') === $repCo)));
$statusWord = (array) $opD['payment_statuses'];
$mName = static fn (string $p): string => date('M Y', (int) strtotime($p . '-01'));
$rep = ['key' => $repKey, 'title' => '', 'subtitle' => ($repCo === '' ? 'All companies' : ($opCompanies[$repCo] ?? '')), 'head' => [], 'rows' => [], 'money' => [], 'bold' => [], 'filters' => []];
$sumCols = static function (array $rows, array $cols): array {
    $t = [];
    foreach ($cols as $c) {
        $t[$c] = Money::zero();
        foreach ($rows as $r) {
            $t[$c] = $t[$c]->plus(Amount::of((string) ($r[$c] ?? '0') === '' ? '0' : (string) $r[$c]));
        }
    }

    return array_map(static fn (Money $m): string => $m->toDecimalString(), $t);
};
$totalRow = static function (array $rows, array $moneyCols, int $width, string $label) use ($sumCols): array {
    $t = $sumCols($rows, $moneyCols);
    $row = array_fill(0, $width, '');
    $row[0] = $label;

    foreach ($t as $c => $v) {
        $row[$c] = $v;
    }

    return $row;
};

if ($repKey === 'register' || $repKey === 'pending') {
    $rep['title'] = $repKey === 'register' ? 'Payout register — ' . date('F Y', (int) strtotime($repMonth . '-01')) : 'Pending payments';
    $rep['filters'] = $repKey === 'register' ? ['month', 'company'] : ['company'];
    $rep['head'] = ['Month', 'Code', 'Agent', 'Paid to', 'Days', 'Visits', 'Collected', 'Gross', 'TDS', 'Net', 'Payout', 'Payment', 'Paid on', 'UTR'];
    $rep['money'] = [6, 7, 8, 9];
    $ls = array_values(array_filter($linesAll, static fn (array $l): bool => $repKey === 'register'
        ? (string) $runsAll[(string) $l['payout_id']]['period'] === $repMonth
        : (string) $runsAll[(string) $l['payout_id']]['status'] === 'approved' && (string) $l['payment_status'] !== 'paid'));
    usort($ls, static fn (array $x, array $y): int => strcmp((string) $runsAll[(string) $x['payout_id']]['period'], (string) $runsAll[(string) $y['payout_id']]['period'])
        ?: strcmp($op->code($opAgents[(string) $x['agent_id']]), $op->code($opAgents[(string) $y['agent_id']])));

    foreach ($ls as $l) {
        $run = $runsAll[(string) $l['payout_id']];
        $a = $opAgents[(string) $l['agent_id']];
        $rep['rows'][] = [$mName((string) $run['period']), $op->code($a), (string) ($a['name'] ?? ''), (string) $l['paid_to'] === 'vendor' ? (string) ($opVendors[(string) $l['vendor_id']]['name'] ?? 'Vendor') : 'Agent',
            rtrim(rtrim((string) $l['days_present'], '0'), '.') ?: '0', (string) (int) $l['visits'], $dec($l['amount_collected']), $dec($l['gross']), $dec($l['tds_amount']), $dec($l['net']),
            (string) ($opD['payout_statuses'][(string) $run['status']] ?? $run['status']), (string) ($statusWord[(string) $l['payment_status']] ?? $l['payment_status']),
            (string) ($l['paid_on'] ?? ''), (string) ($l['payment_ref'] ?? '')];
    }

    $rep['rows'] === [] || $rep['rows'][] = $totalRow($rep['rows'], $rep['money'], count($rep['head']), 'Total — ' . count($rep['rows']) . ' line(s)');
} elseif ($repKey === 'statement') {
    $a = $opAgents[$repAgent] ?? null;
    $rep['filters'] = ['agent', 'fy'];
    $rep['title'] = 'Agent statement — ' . ($a === null ? 'pick an agent' : $op->label($a)) . ' — FY ' . $repFy . '-' . substr((string) ($repFy + 1), 2);
    $rep['head'] = ['Month', 'Days', 'Visits', 'PTP', 'Accounts paid', 'Collected', 'Fixed', 'Variable', 'Collection pay', 'Adjustments', 'Deductions', 'Gross', 'TDS', 'Net', 'Payment', 'Paid on', 'UTR'];
    $rep['money'] = [5, 6, 7, 8, 9, 10, 11, 12, 13];
    $from = sprintf('%04d-%02d', $repFy, $fyStart);
    $to = (new DateTimeImmutable($from . '-01'))->modify('+11 months')->format('Y-m');

    foreach ($a === null ? [] : $linesAll as $l) {
        $run = $runsAll[(string) $l['payout_id']];

        if ((string) $l['agent_id'] !== $repAgent || (string) $run['period'] < $from || (string) $run['period'] > $to) {
            continue;
        }

        $var = Amount::of((string) $l['day_amount'])->plus(Amount::of((string) $l['visit_amount']))->plus(Amount::of((string) $l['ptp_amount']))->plus(Amount::of((string) $l['paid_account_amount']));
        $rep['rows'][(string) $run['period']] = [$mName((string) $run['period']), rtrim(rtrim((string) $l['days_present'], '0'), '.') ?: '0', (string) (int) $l['visits'], (string) (int) $l['ptp_count'], (string) (int) $l['paid_accounts'],
            $dec($l['amount_collected']), $dec($l['fixed_amount']), $var->toDecimalString(), $dec($l['collection_amount']), $dec($l['adjustments']), $dec($l['deductions']), $dec($l['gross']), $dec($l['tds_amount']), $dec($l['net']),
            (string) ($statusWord[(string) $l['payment_status']] ?? $l['payment_status']) . ((string) $run['status'] === 'draft' ? ' (draft)' : ''), (string) ($l['paid_on'] ?? ''), (string) ($l['payment_ref'] ?? '')];
    }

    ksort($rep['rows']);
    $rep['rows'] = array_values($rep['rows']);
    $rep['rows'] === [] || $rep['rows'][] = $totalRow($rep['rows'], $rep['money'], count($rep['head']), 'Financial year');
} elseif ($repKey === 'vendor') {
    $rep['filters'] = ['month', 'company'];
    $rep['title'] = 'Vendor-wise payout — ' . date('F Y', (int) strtotime($repMonth . '-01'));
    $rep['head'] = ['Vendor / payee', 'PAN', 'TDS section', 'Agents', 'Days', 'Visits', 'Collected', 'Gross', 'TDS', 'Net', 'Paid', 'Pending'];
    $rep['money'] = [6, 7, 8, 9, 10, 11];
    $g = [];

    foreach ($linesAll as $l) {
        if ((string) $runsAll[(string) $l['payout_id']]['period'] !== $repMonth) {
            continue;
        }

        $isV = (string) $l['paid_to'] === 'vendor';
        $k = $isV ? 'v' . $l['vendor_id'] : 'direct';
        $g[$k] ??= ['name' => $isV ? (string) ($opVendors[(string) $l['vendor_id']]['name'] ?? 'Vendor') : 'Agents paid directly', 'pan' => $isV ? (string) ($opVendors[(string) $l['vendor_id']]['pan'] ?? '') : '—',
            'sec' => (string) $l['tds_section'], 'n' => 0, 'days' => Money::zero(), 'visits' => 0, 'c' => Money::zero(), 'g' => Money::zero(), 't' => Money::zero(), 'net' => Money::zero(), 'paid' => Money::zero(), 'pend' => Money::zero()];
        $g[$k]['n']++;
        $g[$k]['days'] = $g[$k]['days']->plus(Amount::quantity((string) $l['days_present']));
        $g[$k]['visits'] += (int) $l['visits'];
        $g[$k]['c'] = $g[$k]['c']->plus(Amount::of((string) $l['amount_collected']));
        $g[$k]['g'] = $g[$k]['g']->plus(Amount::of((string) $l['gross']));
        $g[$k]['t'] = $g[$k]['t']->plus(Amount::of((string) $l['tds_amount']));
        $g[$k]['net'] = $g[$k]['net']->plus(Amount::of((string) $l['net']));
        (string) $l['payment_status'] === 'paid' ? $g[$k]['paid'] = $g[$k]['paid']->plus(Amount::of((string) $l['net'])) : $g[$k]['pend'] = $g[$k]['pend']->plus(Amount::of((string) $l['net']));
    }

    foreach ($g as $x) {
        $rep['rows'][] = [$x['name'], $x['pan'], $x['sec'], (string) $x['n'], rtrim(rtrim($x['days']->toDecimalString(), '0'), '.') ?: '0', (string) $x['visits'],
            $x['c']->toDecimalString(), $x['g']->toDecimalString(), $x['t']->toDecimalString(), $x['net']->toDecimalString(), $x['paid']->toDecimalString(), $x['pend']->toDecimalString()];
    }

    $rep['rows'] === [] || $rep['rows'][] = $totalRow($rep['rows'], $rep['money'], count($rep['head']), 'Total');
} elseif ($repKey === 'tds') {
    $rep['filters'] = ['fy', 'q', 'company'];
    $from = sprintf('%04d-%02d', $repFy, $fyStart);
    $months = [];

    for ($i = 0; $i < 12; $i++) {
        $m = (new DateTimeImmutable($from . '-01'))->modify("+{$i} months")->format('Y-m');
        ($repQ === 0 || intdiv($i, 3) + 1 === $repQ) && $months[$m] = true;
    }

    $rep['title'] = 'TDS on off-roll payouts — FY ' . $repFy . '-' . substr((string) ($repFy + 1), 2) . ($repQ > 0 ? ' Q' . $repQ : '');
    $rep['subtitle'] .= ' · approved payouts · ' . (string) ($opD['tds']['source'] ?? '');
    $rep['head'] = ['Month', 'Payee', 'Type', 'PAN', 'TDS section', 'Rate %', 'Amount paid / credited', 'TDS', 'Paid on', 'UTR'];
    $rep['money'] = [6, 7];
    $g = [];

    foreach ($linesAll as $l) {
        $run = $runsAll[(string) $l['payout_id']];

        if (!isset($months[(string) $run['period']]) || (string) $run['status'] !== 'approved') {
            continue;
        }

        $isV = (string) $l['paid_to'] === 'vendor';
        $k = (string) $run['period'] . ($isV ? 'v' . $l['vendor_id'] : 'a' . $l['agent_id']);
        $who = $isV ? ($opVendors[(string) $l['vendor_id']] ?? []) : $opAgents[(string) $l['agent_id']];
        $g[$k] ??= ['m' => (string) $run['period'], 'name' => $isV ? (string) ($who['name'] ?? '') : $op->label($who), 'type' => $isV ? 'Vendor' : 'Agent', 'pan' => (string) ($who['pan'] ?? ''),
            'sec' => (string) ($opD['tds']['sections'][(string) $l['tds_section']]['label'] ?? $l['tds_section']), 'rate' => rtrim(rtrim((string) $l['tds_rate'], '0'), '.') ?: '0',
            'g' => Money::zero(), 't' => Money::zero(), 'on' => (string) ($l['paid_on'] ?? ''), 'ref' => (string) ($l['payment_ref'] ?? '')];
        $g[$k]['g'] = $g[$k]['g']->plus(Amount::of((string) $l['gross']));
        $g[$k]['t'] = $g[$k]['t']->plus(Amount::of((string) $l['tds_amount']));
    }

    ksort($g);

    foreach ($g as $x) {
        $rep['rows'][] = [$mName($x['m']), $x['name'], $x['type'], $x['pan'] ?: 'NO PAN', $x['sec'], $x['rate'], $x['g']->toDecimalString(), $x['t']->toDecimalString(), $x['on'], $x['ref']];
    }

    $rep['rows'] === [] || $rep['rows'][] = $totalRow($rep['rows'], $rep['money'], count($rep['head']), 'Total');
} elseif ($repKey === 'activity') {
    $rep['filters'] = ['month', 'company'];
    $rep['title'] = 'Agent attendance & activity — ' . date('F Y', (int) strtotime($repMonth . '-01'));
    $rep['head'] = ['Code', 'Agent', 'Branch', 'Present', 'Half days', 'Absent', 'Leave', 'Visits', 'PTP', 'Accounts paid', 'Collected', 'Pending approval', 'Rejected'];
    $rep['money'] = [10];
    $acc = [];

    foreach ($op->activity($repMonth) as $r) {
        $a = $opAgents[(string) $r['agent_id']] ?? null;

        if ($a === null || $repCo !== '' && (string) ($a['employer_company_id'] ?? '') !== $repCo) {
            continue;
        }

        $id = (string) $a['id'];
        $acc[$id] ??= ['a' => $a, 'present' => 0, 'half_day' => 0, 'absent' => 0, 'leave' => 0, 'v' => 0, 'p' => 0, 'pa' => 0, 'c' => Money::zero(), 'pend' => 0, 'rej' => 0];

        if ((string) $r['approval'] === 'rejected') {
            $acc[$id]['rej']++;
            continue;
        }

        (string) $r['approval'] === 'pending' && $acc[$id]['pend']++;
        isset($acc[$id][(string) $r['day_status']]) && $acc[$id][(string) $r['day_status']]++;
        $acc[$id]['v'] += (int) ($r['visits'] ?? 0);
        $acc[$id]['p'] += (int) ($r['ptp_count'] ?? 0);
        $acc[$id]['pa'] += (int) ($r['paid_accounts'] ?? 0);
        $acc[$id]['c'] = $acc[$id]['c']->plus(Amount::of((string) ($r['amount_collected'] ?? '')));
    }

    uasort($acc, static fn (array $x, array $y): int => strcmp($op->code($x['a']), $op->code($y['a'])));

    foreach ($acc as $x) {
        $rep['rows'][] = [$op->code($x['a']), (string) ($x['a']['name'] ?? ''), $opBranches[(string) ($x['a']['branch_id'] ?? '')] ?? '', (string) $x['present'], (string) $x['half_day'], (string) $x['absent'], (string) $x['leave'],
            (string) $x['v'], (string) $x['p'], (string) $x['pa'], $x['c']->toDecimalString(), (string) $x['pend'], (string) $x['rej']];
    }

    $rep['rows'] === [] || $rep['rows'][] = $totalRow($rep['rows'], $rep['money'], count($rep['head']), 'Total');
} else {
    $rep['filters'] = ['company'];
    $rep['title'] = 'Certificate compliance — as of ' . Dates::dmy(date('Y-m-d'));
    $rep['subtitle'] .= ' · expiring = within ' . $opWindow . ' days';
    $rep['head'] = ['Code', 'Agent', 'Company', 'Branch', 'Status', 'DRA number', 'DRA valid up to', 'DRA', 'PCC number', 'PCC valid up to', 'PCC', 'Bank letter', 'Letter valid up to', 'Letter'];
    $auth = [];

    foreach (OffrollPay::rows($store, 'agent_authorizations') as $au) {
        $aid = (string) ($au['agent_id'] ?? '');
        $aid !== '' && !\SmartPRS\Surface\Modules::isRetired($au) && (!isset($auth[$aid]) || (string) ($au['valid_to'] ?? '') > (string) ($auth[$aid]['valid_to'] ?? '')) && $auth[$aid] = $au;
    }

    $rank = ['expired' => 0, 'none' => 1, 'due' => 2, 'valid' => 3];
    $list = [];

    foreach ($opAgents as $id => $a) {
        if ($repCo !== '' && (string) ($a['employer_company_id'] ?? '') !== $repCo) {
            continue;
        }

        $d = $opBand((string) ($a['dra_expiry'] ?? ''));
        $p = $opBand((string) ($a['pcc_expiry'] ?? ''));
        $l = isset($auth[$id]) ? $opBand((string) ($auth[$id]['valid_to'] ?? '')) : 'none';
        $list[] = ['w' => min($rank[$d], $rank[$p], $rank[$l]), 'row' => [$op->code($a), (string) ($a['name'] ?? ''), $opCompanies[(string) ($a['employer_company_id'] ?? '')] ?? '', $opBranches[(string) ($a['branch_id'] ?? '')] ?? '', (string) ($a['status'] ?? ''),
            (string) ($a['dra_cert_no'] ?? ''), substr((string) ($a['dra_expiry'] ?? ''), 0, 10), $opBandLabel[$d], (string) ($a['pcc_cert_no'] ?? ''), substr((string) ($a['pcc_expiry'] ?? ''), 0, 10), $opBandLabel[$p],
            isset($auth[$id]) ? (string) ($auth[$id]['bank'] ?? '') . ' · ' . (string) ($auth[$id]['auth_no'] ?? '') : '', substr((string) ($auth[$id]['valid_to'] ?? ''), 0, 10), $opBandLabel[$l]]];
    }

    usort($list, static fn (array $x, array $y): int => $x['w'] <=> $y['w'] ?: strcmp($x['row'][0], $y['row'][0]));
    $rep['rows'] = array_column($list, 'row');
}

$rep['rows'] === [] || !in_array($repKey, ['register', 'pending', 'statement', 'vendor', 'tds', 'activity'], true) || $rep['bold'] = [count($rep['rows']) - 1];
