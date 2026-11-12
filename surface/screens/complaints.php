<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Complaints</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// COMPLAINTS (owner, 13 Oct). Employees raise a complaint and follow it here,
// seeing only their own. HR / Admin never type one in: they see every complaint
// from the employees in view — filters + date range — set the status and reply.
// Replies are appended (`complaint_responses`), never overwritten. Vocabulary is
// config/complaints.json; every write is surface/actions/complaint.php.
use SmartPRS\Surface\Dates;
use SmartPRS\Surface\Employment;

$cfg = (array) json_decode((string) file_get_contents(__DIR__ . '/../../config/complaints.json'), true);
$statuses = array_map('strval', (array) ($cfg['statuses'] ?? []));
$closed = array_map('strval', (array) ($cfg['closed_statuses'] ?? []));
$isHr = $may('employee.update');
$own = $scope->own();
$allPeople = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $allPeople[(string) $e['id']] = $e;
}

$filter = orgFilter();
$inView = [];

foreach (orgFilterApply($store, array_values($allPeople), $filter) as $e) {
    $inView[(string) $e['id']] = $e;
}

// The date range: All dates unless one is picked; it filters on the day the complaint was raised.
$period = ((string) ($_GET['p'] ?? 'all')) === 'all' ? allPeriod() : reportPeriod($_GET, financialYearStart($customisation), date('Y-m-d'));
$stPick = in_array((string) ($_GET['st'] ?? ''), $statuses, true) ? (string) $_GET['st'] : '';
$raisedOn = static fn (array $c): string => substr((string) ($c['created_at'] ?? ''), 0, 10);
$rows = array_values(array_filter($store->all('complaints'), static function (array $c) use ($inView, $period, $stPick, $raisedOn): bool {
    if (!isset($inView[(string) ($c['employee_id'] ?? '')])) {
        return false;
    }

    if ($period['from'] !== '' && ($raisedOn($c) < $period['from'] || $raisedOn($c) > $period['to'])) {
        return false;
    }

    return $stPick === '' || (string) ($c['status'] ?? '') === $stPick;
}));
usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$rows = array_slice($rows, 0, max(1, (int) ($cfg['list_limit'] ?? 500)));
$isClosed = static fn (array $c): bool => in_array((string) ($c['status'] ?? ''), $closed, true);
$open = count(array_filter($rows, static fn (array $c): bool => !$isClosed($c)));

// Replies, newest last, per complaint.
$replies = [];

foreach ($complaintReplies->all('complaint_responses') as $r) {
    $replies[(string) ($r['complaint_id'] ?? '')][] = $r;
}

foreach ($replies as &$list) {
    usort($list, static fn (array $a, array $b): int => strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')));
}
unset($list);

$openId = (string) ($_GET['c'] ?? '');
$openRow = null;

foreach ($rows as $c) {
    if ((string) $c['id'] === $openId) {
        $openRow = $c;
    }
}

$who = static fn (string $id): string => isset($allPeople[$id]) ? Employment::label($allPeople[$id]) : ($id === '' ? '—' : '#' . $id);
$dmy = static fn (string $d): string => $d === '' ? '—' : Dates::dmy(substr($d, 0, 10));
$keepQ = array_diff_key($_GET, ['r' => 1, 'c' => 1]);
$q = $keepQ === [] ? '' : '&' . http_build_query($keepQ);
$hidden = '<input type="hidden" name="csrf" value="' . e($session->token()) . '"><input type="hidden" name="action" value="complaint"><input type="hidden" name="back_q" value="' . e(http_build_query($keepQ)) . '">';
$statusSelect = '<div><label for="st">Status</label><select id="st" name="st"><option value="">All</option>';

foreach ($statuses as $s) {
    $statusSelect .= '<option' . ($s === $stPick ? ' selected' : '') . '>' . e($s) . '</option>';
}

$statusSelect .= '</select></div>';
$badge = static fn (string $st): string => '<span class="badge badge-' . (in_array($st, $closed, true) ? 'green' : 'amber') . '">' . e($st === '' ? '—' : $st) . '</span>';
?>
<div class="page-header">
  <div><h2>Complaints</h2><p><?= $isHr ? 'Every complaint raised by the employees in view. Open one to set its status and reply — the employee reads the reply here.' : 'Raise a complaint and follow its status and HR\'s replies here. You see only your own.' ?></p></div>
</div>
<?php if ($may('employee.list')): ?><div class="card"><?= orgFilterBar('complaints', $filter, orgFilterOptions($store, array_values($allPeople)), [], 'Show', null, $period, PERIOD_MODES_ALL, $statusSelect) ?></div><?php endif; ?>
<?php if ($own !== null && !$isHr): ?>
<div class="card">
  <h2>Raise a complaint</h2>
  <form method="post"><?= $hidden ?><input type="hidden" name="step" value="raise">
    <div class="row">
      <div><label for="source">Category</label><select id="source" name="source" required><option value="">— pick —</option><?php foreach ((array) ($cfg['categories'] ?? []) as $c): ?><option><?= e((string) $c) ?></option><?php endforeach; ?></select></div>
      <div><label for="severity">Severity</label><select id="severity" name="severity"><?php foreach ((array) ($cfg['severities'] ?? []) as $s): ?><option<?= (string) $s === (string) ($cfg['default_severity'] ?? '') ? ' selected' : '' ?>><?= e((string) $s) ?></option><?php endforeach; ?></select></div>
      <div><label for="date">Date of incident</label><input type="date" id="date" name="date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
    </div>
    <div><label for="description">Description — what happened, where, and who was involved</label><textarea id="description" name="description" rows="4" required></textarea></div>
    <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> Raise complaint</button>
  </form>
</div>
<?php endif; ?>
<?php if ($openRow !== null): $cid = (string) $openRow['id']; ?>
<div class="card">
  <h2>Complaint #<?= e($cid) ?> — <?= e((string) ($openRow['source'] ?? '')) ?> <?= $badge((string) ($openRow['status'] ?? '')) ?></h2>
  <p class="note">Raised by <?= e($who((string) ($openRow['employee_id'] ?? ''))) ?> on <?= e($dmy((string) ($openRow['created_at'] ?? ''))) ?> · incident on <?= e($dmy((string) ($openRow['date'] ?? ''))) ?> · severity <?= e((string) ($openRow['severity'] ?? '—')) ?> · via <?= e((string) ($openRow['channel'] ?? '—')) ?></p>
  <p><?= nl2br(e((string) ($openRow['description'] ?? ''))) ?></p>
<?php foreach ($replies[$cid] ?? [] as $r): ?>
  <fieldset class="form-section"><legend>Reply · <?= e($dmy((string) ($r['created_at'] ?? ''))) ?> · <?= e((string) ($r['responded_by'] ?? '')) ?> · status set to <?= e((string) ($r['status'] ?? '')) ?></legend><p style="margin:0"><?= nl2br(e((string) ($r['response'] ?? ''))) ?></p></fieldset>
<?php endforeach; ?>
<?php if ($isHr): ?>
  <form method="post"><?= $hidden ?><input type="hidden" name="step" value="respond"><input type="hidden" name="id" value="<?= e($cid) ?>">
    <fieldset class="form-section"><legend>Respond</legend>
      <div class="row"><div><label for="status">Status</label><select id="status" name="status"><?php foreach ($statuses as $s): ?><option<?= $s === (string) ($openRow['status'] ?? '') ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div></div>
      <div><label for="response">Reply — shown to the employee</label><textarea id="response" name="response" rows="3" required></textarea></div>
      <button class="btn btn-primary" type="submit">Save response</button>
    </fieldset></form>
<?php endif; ?>
  <p><a class="btn btn-outline btn-sm" href="?r=complaints<?= e($q) ?>">Back to the list</a></p>
</div>
<?php endif; ?>
<div class="card">
  <h2>Complaints <span class="badge badge-amber"><?= $open ?> open</span> <span class="badge badge-gray"><?= count($rows) - $open ?> closed</span></h2>
<?php if ($rows === []): ?>
  <p class="note">No complaints<?= $isHr ? ' from the employees in view for this period' : ' raised yet' ?>.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Raised on</th><th>Employee</th><th>Category</th><th>Severity</th><th>Incident date</th><th>Description</th><th>Status</th><th>Replies</th><th></th></tr></thead>
    <tbody>
<?php foreach ($rows as $c): $cid = (string) $c['id']; $d = (string) ($c['description'] ?? ''); ?>
      <tr>
        <td class="note"><?= e($cid) ?></td>
        <td class="note"><?= e($dmy((string) ($c['created_at'] ?? ''))) ?></td>
        <td><?= e($who((string) ($c['employee_id'] ?? ''))) ?></td>
        <td><?= e((string) ($c['source'] ?? '')) ?></td>
        <td><?= e((string) ($c['severity'] ?? '')) ?></td>
        <td class="note"><?= e($dmy((string) ($c['date'] ?? ''))) ?></td>
        <td class="note"><?= e($d) /* in full, never cut off (owner, 23 Oct) */ ?></td>
        <td><?= $badge((string) ($c['status'] ?? '')) ?></td>
        <td class="note"><?= count($replies[$cid] ?? []) ?></td>
        <td><a class="btn btn-outline btn-sm" href="?r=complaints&amp;c=<?= e($cid) ?><?= e($q) ?>"><?= $isHr && !$isClosed($c) ? 'Respond' : 'Open' ?></a></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
