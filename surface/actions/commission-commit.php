<?php
/**
 * CREATE COMMISSION ENTRIES FROM THE CALCULATOR'S PREVIEW.
 *
 * Only the FIGURES come from the form; the money is recomputed here from the
 * scheme row, exactly as the preview did — an agent cannot type a different
 * rate. Every entry stores base, gross, TDS rate, TDS and net, and its working
 * in `remarks`, so each figure on the register explains itself.
 */
declare(strict_types=1);

if (!$may('payroll.run')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'payroll.run')->summary;
    header('Location: ?r=commission-calc');
    exit;
}

use SmartPRS\Surface\Amount;
use SmartPRS\Surface\Compensation;
use SmartPRS\Surface\Employment;

$compensation = new Compensation($store, __DIR__ . '/../../config/compensation.json');
$commissionRules = (array) $compensation->config()['commission'];
$schemeId = trim(post('scheme'));
$month = trim(post('month'));
$status = post('status') === 'approved' ? 'approved' : (string) $commissionRules['default_status'];
$scheme = $schemeId === '' ? null : $store->find('incentive_schemes', $schemeId);
$back = '?r=commission-calc&scheme=' . rawurlencode($schemeId) . '&month=' . rawurlencode($month) . '&status=' . rawurlencode($status);

if ($scheme === null || strtolower((string) ($scheme['status'] ?? '')) !== 'active' || trim((string) ($scheme['scheme_kind'] ?? '')) === '' || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
    $_SESSION['flash_problem'] = 'No active scheme or no month — nothing was created.';
    header('Location: ?r=commission-calc');
    exit;
}

$c = (array) ($_POST['c'] ?? []);
$t = (array) ($_POST['t'] ?? []);
$m = (array) ($_POST['m'] ?? []);
$tdsRate = trim((string) ($scheme['tds_rate'] ?? '')) === '' ? (string) $commissionRules['default_tds_rate'] : (string) $scheme['tds_rate'];
$user = $session->user();
$created = 0;
$problems = [];

foreach (array_unique(array_merge(array_keys($c), array_keys($m))) as $id) {
    $id = (string) $id;
    $figure = trim((string) ($c[$id] ?? ''));
    $target = trim((string) ($t[$id] ?? ''));
    $manual = trim((string) ($m[$id] ?? ''));
    $employee = $store->find('employees', $id);

    if ($employee === null || ($figure === '' && $manual === '') || !Compensation::eligible($scheme, $employee, (array) (orgNames($store)['designation_roles'] ?? []))) {
        continue;
    }

    try {
        $result = Compensation::commission($scheme, $figure === '' ? '0' : $figure, $target, $manual);
        // THE SCHEME'S TDS (Commission / Incentive Setup, owner 30 Oct 2026): ticked = its Flat ₹ or %, else none.
        $tds = Compensation::schemeTds($result['gross'], $scheme, $employee, $commissionRules);
    } catch (\InvalidArgumentException $ex) {
        $problems[] = Employment::code($employee) . ': ' . $ex->getMessage();

        continue;
    }

    // THE SCHEME'S OWN LIMITS (owner, 2 Nov 2026): validity window, max claims and max ₹ per person.
    $limit = Compensation::limitsProblem($scheme, $id, $month, $result['gross'], $store->all('commissions'));
    if ($limit !== null) {
        $problems[] = Employment::code($employee) . ': ' . $limit;

        continue;
    }

    $row = $store->put('commissions', [
        'employee_id' => $id,
        'employer_company_id' => trim((string) ($employee['employer_company_id'] ?? '')) === '' ? null : (string) $employee['employer_company_id'],
        'scheme_id' => $schemeId,
        'claim_type' => trim((string) ($scheme['threshold'] ?? '')) !== '' || $result['achievement'] !== null
            ? 'Simple claim — target / bonus / special incentive' : 'Collection claim — money collected from a customer',
        'purpose' => (string) ($scheme['purpose'] ?? ''),
        'portfolio' => (string) ($scheme['portfolio'] ?? ''),
        'month' => $month,
        'base_amount' => Amount::of($figure === '' ? '0' : $figure)->toDecimalString(),
        'gross_amount' => $result['gross']->toDecimalString(),
        'tds_rate' => $tds['rate'],
        'tds_amount' => $tds['tds']->toDecimalString(),
        'amount' => $tds['net']->toDecimalString(),
        'payout_method' => (string) ($scheme['payout_method'] ?? ''),
        // SEPARATE PAYOUT ON THE SCHEME'S OWN DATE (28 Oct); with salary, the month decides.
        // A SEPARATE PAYOUT'S OWN DATE (29 Oct): the scheme's payout day in this entry's month (or the next).
        'payout_date' => Compensation::payoutDate($scheme, $month),
        'status' => $status,
        'remarks' => $result['working'] . '; ' . $tds['working'],
        'approver_name' => $status === 'approved' ? (string) ($user['email'] ?? '') : null,
        'decided_by' => $status === 'approved' ? (string) ($user['email'] ?? '') : null,
        'decided_at' => $status === 'approved' ? date('Y-m-d H:i:s') : null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $audit('commission.created', 'commissions', (string) $row['id'], null,
        ['employee_id' => $id, 'month' => $month, 'scheme' => (string) $scheme['name'], 'gross' => $result['gross']->format(), 'net' => $tds['net']->format(), 'status' => $status],
        'Commission Calculator: ' . $result['working'] . '; ' . $tds['working']);
    $created++;
}

$_SESSION['flash_notice'] = $created . ' commission entr' . ($created === 1 ? 'y' : 'ies') . ' created for ' . $month . ' as ' . $status . ' under "' . (string) $scheme['name'] . '".';

if ($problems !== []) {
    $_SESSION['flash_problem'] = 'Left out: ' . implode('; ', $problems);
}

header('Location: ' . ($created > 0 ? '?r=m&k=commissions' : $back));
exit;
