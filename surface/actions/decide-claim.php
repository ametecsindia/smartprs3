<?php
/**
 * APPROVE OR REJECT AN EXPENSE CLAIM OR A SALARY ADVANCE.
 *
 * The row gets its new status, who decided and when; the reason goes to the
 * audit trail against the row. Self-approval is refused. If the claim was also
 * raised as a workflow request (config/workflows.json), that chain is advanced
 * first and the row only moves when the chain finishes — an approval at the
 * manager step on a ₹60,000 claim leaves it pending for finance.
 */
declare(strict_types=1);

if (!$may('payroll.run')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'payroll.run')->summary;
    header('Location: ?r=claims');
    exit;
}

$compensation = new SmartPRS\Surface\Compensation($store, __DIR__ . '/../../config/compensation.json');
$claimRules = (array) $compensation->config()['claims'];
$table = post('table');
$id = trim(post('id'));
$approve = post('decision') === 'approve';
$reason = trim(post('reason'));
$rule = is_array($claimRules[$table] ?? null) ? (array) $claimRules[$table] : null;
$row = $rule === null || $id === '' ? null : $store->find($table, $id);
$fail = static function (string $why): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ?r=claims');
    exit;
};

if ($row === null) {
    $fail('No such claim.');
}

if (!in_array(strtolower(trim((string) ($row['status'] ?? ''))), (array) $rule['pending'], true)) {
    $fail('That claim was already ' . (string) $row['status'] . '. Deciding it twice would record two outcomes for one question.');
}

if (!$approve && $reason === '') {
    $fail('Rejecting needs a reason. A decision with no reason is one nobody can question later, and the person it affects is the one who asks.');
}

$dcSelfOk = $access->maySelfApprove((string) ($session->user()['role'] ?? ''));

if (!$dcSelfOk && $scope->own() !== null && (string) $scope->own() === (string) ($row['employee_id'] ?? '')) {
    $fail('That is your own claim, so you cannot decide it. The whole point of an approval is that a second person looked.');
}

$user = $session->user();
$byId = (string) ($user['id'] ?? '');
$byLabel = (string) ($user['email'] ?? '');

// A workflow request for the same row: advance the declared chain and only
// move the row when the chain closes.
foreach ($requests->open() as $request) {
    if ((string) $request['subject_table'] !== $table || (string) $request['subject_id'] !== $id) {
        continue;
    }

    $result = $requests->decide((string) $request['id'], $approve, $reason, $byId, $byLabel, $dcSelfOk,
        $approvalAuthority->isFinal((string) ($user['role'] ?? '')));

    if (!$result['ok']) {
        $fail($result['why']);
    }

    if ((string) $result['request']['state'] === SmartPRS\Surface\Requests::OPEN) {
        $audit('claim.step', $table, $id, ['status' => (string) $row['status']], ['status' => (string) $row['status'], 'chain' => $result['why']], $reason);
        $_SESSION['flash_notice'] = $result['why'] . ' The row stays pending until the chain finishes.';
        header('Location: ?r=claims');
        exit;
    }
}

$before = ['status' => (string) $row['status'], 'decided_by' => (string) ($row['decided_by'] ?? ''), 'decided_at' => (string) ($row['decided_at'] ?? '')];
$row['status'] = (string) ($approve ? $rule['approved'] : $rule['rejected']);
$row['decided_by'] = $byLabel;
$row['decided_at'] = date('Y-m-d H:i:s');
$row['approver_name'] = trim((string) ($row['approver_name'] ?? '')) === '' ? $byLabel : $row['approver_name'];
$store->put($table, $row);
$after = ['status' => $row['status'], 'decided_by' => $row['decided_by'], 'decided_at' => $row['decided_at']];
$audit('claim.decided', $table, $id, $before, $after, $reason === '' ? ($approve ? 'approved' : 'rejected') : $reason);
$_SESSION['flash_notice'] = ucfirst($row['status']) . ': ' . ($table === 'expenses' ? 'expense claim' : 'salary advance') . ' #' . $id . ' for ' . SmartPRS\Surface\Amount::of($row['amount'] ?? '')->format() . '.';
header('Location: ?r=claims');
exit;
