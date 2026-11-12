<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>HR Helpdesk</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's HR Helpdesk: employees raise tickets and see only their own;
// HR sees everybody's in view and works them — priority, assignee, status,
// a reply the employee reads. Vocabulary is config/helpdesk.json; every
// write is surface/actions/helpdesk-ticket.php.
use SmartPRS\Surface\Employment;

$cfg = (array) json_decode((string) file_get_contents(__DIR__ . '/../../config/helpdesk.json'), true);
$statuses = (array) ($cfg['statuses'] ?? []);
$priorities = (array) ($cfg['priorities'] ?? []);
$closed = array_map('strval', (array) ($cfg['closed_statuses'] ?? []));
$isHr = $may('employee.update');
$own = $scope->own();
$allPeople = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $allPeople[(string) $e['id']] = $e;
}

$people = $scope->only(array_values($allPeople), $may('employee.list'));
$filter = orgFilter();
$inView = [];

foreach (orgFilterApply($store, $people, $filter) as $e) {
    $inView[(string) $e['id']] = $e;
}

$tickets = array_values(array_filter($store->all('helpdesk'), static fn (array $t): bool => isset($inView[(string) ($t['employee_id'] ?? '')])));
usort($tickets, static fn (array $a, array $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
$tickets = array_slice($tickets, 0, max(1, (int) ($cfg['list_limit'] ?? 300)));
$open = count(array_filter($tickets, static fn (array $t): bool => !in_array((string) ($t['status'] ?? ''), $closed, true)));
$openId = (string) ($_GET['t'] ?? '');
$openTicket = null;

foreach ($tickets as $t) {
    if ((string) $t['id'] === $openId) {
        $openTicket = $t;
    }
}

$who = static fn (string $id): string => isset($allPeople[$id]) ? Employment::label($allPeople[$id]) : ($id === '' ? '—' : '#' . $id);
$keep = [];
?>
<div class="page-header">
  <div><h2>HR Helpdesk</h2><p><?= $isHr ? 'Every ticket from the employees in view. Set the priority, assign it, reply, and close it.' : 'Raise a ticket for HR and follow its status here. You see only your own.' ?> Vocabulary: <code>config/helpdesk.json</code>.</p></div>
</div>
<?php if ($may('employee.list')): ?><?= orgFilterBar('helpdesk', $filter, orgFilterOptions($store, $people)) ?><?php endif; ?>
<?php if ($own !== null): ?>
<div class="card">
  <h2>Raise a ticket</h2>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="helpdesk-ticket"><input type="hidden" name="step" value="raise">
    <div class="row">
      <div><label for="category">Category</label><select id="category" name="category"><?php foreach ((array) ($cfg['categories'] ?? []) as $c): ?><option><?= e((string) $c) ?></option><?php endforeach; ?></select></div>
      <div style="flex:1"><label for="subject">Subject</label><input id="subject" name="subject" required maxlength="255"></div>
    </div>
    <div><label for="description">Description — explain the issue in your own words</label><textarea id="description" name="description" rows="3" required></textarea></div>
    <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> Raise ticket</button>
  </form>
</div>
<?php endif; ?>
<?php if ($openTicket !== null): ?>
<div class="card">
  <h2>#<?= e((string) $openTicket['id']) ?> — <?= e((string) ($openTicket['subject'] ?? '')) ?> <span class="badge badge-<?= in_array((string) ($openTicket['status'] ?? ''), $closed, true) ? 'green' : 'amber' ?>"><?= e((string) ($statuses[(string) ($openTicket['status'] ?? '')] ?? ($openTicket['status'] ?? ''))) ?></span></h2>
  <p class="note">Raised by <?= e($who((string) ($openTicket['employee_id'] ?? ''))) ?> on <?= e((string) ($openTicket['created_at'] ?? '')) ?> · <?= e((string) ($openTicket['category'] ?? '')) ?> · priority <?= e((string) ($priorities[(string) ($openTicket['priority'] ?? '')] ?? ($openTicket['priority'] ?? ''))) ?> · assigned to <?= e($who((string) ($openTicket['assigned_to'] ?? ''))) ?></p>
  <p><?= nl2br(e((string) ($openTicket['description'] ?? ''))) ?></p>
<?php if (trim((string) ($openTicket['hr_comment'] ?? '')) !== ''): ?>
  <fieldset class="form-section"><legend>HR reply</legend><p><?= nl2br(e((string) $openTicket['hr_comment'])) ?></p></fieldset>
<?php endif; ?>
<?php if ($isHr): ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="helpdesk-ticket"><input type="hidden" name="step" value="work"><input type="hidden" name="id" value="<?= e((string) $openTicket['id']) ?>"><?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <fieldset class="form-section"><legend>Work the ticket (HR)</legend>
      <div class="row">
        <div><label for="priority">Priority</label><select id="priority" name="priority"><?php foreach ($priorities as $k => $l): ?><option value="<?= e((string) $k) ?>"<?= (string) $k === (string) ($openTicket['priority'] ?? '') ? ' selected' : '' ?>><?= e((string) $l) ?></option><?php endforeach; ?></select></div>
        <div><label for="status">Status</label><select id="status" name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= e((string) $k) ?>"<?= (string) $k === (string) ($openTicket['status'] ?? '') ? ' selected' : '' ?>><?= e((string) $l) ?></option><?php endforeach; ?></select></div>
        <div><label for="assigned_to">Assign to</label><select id="assigned_to" name="assigned_to"><option value="">— nobody —</option><?php foreach ($allPeople as $id => $e): ?><option value="<?= e((string) $id) ?>"<?= (string) $id === (string) ($openTicket['assigned_to'] ?? '') ? ' selected' : '' ?>><?= e(Employment::label($e)) ?></option><?php endforeach; ?></select></div>
      </div>
      <div><label for="hr_comment">HR comment — reply shown to the employee</label><textarea id="hr_comment" name="hr_comment" rows="3"><?= e((string) ($openTicket['hr_comment'] ?? '')) ?></textarea></div>
      <button class="btn btn-primary" type="submit">Save</button>
    </fieldset></form>
<?php endif; ?>
  <p><a class="btn btn-outline btn-sm" href="?r=helpdesk<?= orgFilterQuery($filter) ?>">Back to the list</a></p>
</div>
<?php endif; ?>
<div class="card">
  <h2>Tickets <span class="badge badge-amber"><?= $open ?> open</span> <span class="badge badge-gray"><?= count($tickets) - $open ?> closed</span></h2>
<?php if ($tickets === []): ?>
  <p class="note">No tickets<?= $isHr ? ' from the employees in view' : '' ?>.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>#</th><th>Raised</th><th>Employee</th><th>Category</th><th>Subject</th><th>Priority</th><th>Status</th><th>Assigned</th><th></th></tr></thead>
    <tbody>
<?php foreach ($tickets as $t): $st = (string) ($t['status'] ?? ''); ?>
      <tr>
        <td class="note"><?= e((string) $t['id']) ?></td>
        <td class="note"><?= e(substr((string) ($t['created_at'] ?? ''), 0, 10)) ?></td>
        <td><?= e($who((string) ($t['employee_id'] ?? ''))) ?></td>
        <td class="note"><?= e((string) ($t['category'] ?? '')) ?></td>
        <td><b><?= e((string) ($t['subject'] ?? '')) ?></b></td>
        <td><?= e((string) ($priorities[(string) ($t['priority'] ?? '')] ?? ($t['priority'] ?? ''))) ?></td>
        <td><span class="badge badge-<?= in_array($st, $closed, true) ? 'green' : 'amber' ?>"><?= e((string) ($statuses[$st] ?? $st)) ?></span></td>
        <td class="note"><?= e($who((string) ($t['assigned_to'] ?? ''))) ?></td>
        <td style="white-space:nowrap"><a class="btn btn-outline btn-sm" href="?r=helpdesk&amp;t=<?= e((string) $t['id']) ?><?= orgFilterQuery($filter) ?>">Open</a>
<?php if ($isHr && !in_array($st, $closed, true)): ?>
          <form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="helpdesk-ticket"><input type="hidden" name="step" value="resolve"><input type="hidden" name="id" value="<?= e((string) $t['id']) ?>"><?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?><button class="btn btn-outline btn-sm" type="submit">Resolve</button></form>
<?php endif; ?>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
