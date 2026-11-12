<?php declare(strict_types=1);
/**
 * ONE LETTER KIND: the issued register for it, a picker to issue one, a print view.
 *
 * Included by surface/screens/letters-<kind>.php with `$kind` set. Drafting and
 * issuing stay on the shared Letters screen (?r=letters&e=…&t=<kind>) — this is
 * SmartPRS 2's per-kind sub-menu over the one engine, not a second engine.
 */
if (!$may('employee.letters.view')): ?>
<div class="page-header"><div><h2>Letters</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.letters.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
$kind = (string) ($kind ?? '');
$template = $letters->find($kind);

if ($template === null): ?>
<div class="page-header"><div><h2>Letters</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Nothing in <code>config/letters.json</code> declares a <code><?= e($kind) ?></code> letter.</div></div>
<?php return; endif;

$label = (string) $template['label'];
$people = $scope->only($scope->only(liveRows($store, 'employees'), $may('employee.list')), $may('employee.letters.view'));
$byId = [];

foreach ($people as $p) {
    $byId[(string) $p['id']] = $p;
}

$viewId = trim((string) ($_GET['view'] ?? ''));
$viewing = $viewId === '' ? null : $store->find('letters', $viewId);

if ($viewing !== null && ((string) ($viewing['letter_type'] ?? '') !== $kind
    || (!isset($byId[(string) ($viewing['employee_id'] ?? '')]) && !($kind === 'offer' && trim((string) ($viewing['employee_id'] ?? '')) === '')))) {
    $viewing = null; // not this kind, or not an employee this viewer may read
}

$issued = [];

try {
    foreach ($store->all('letters') as $row) {
        // A NEW CANDIDATE'S OFFER (owner, 12 Nov 2026) has no employee yet — listed too, to whoever may issue offers.
        if ((string) ($row['letter_type'] ?? '') === $kind && (isset($byId[(string) ($row['employee_id'] ?? '')])
            || ($kind === 'offer' && trim((string) ($row['employee_id'] ?? '')) === '' && $may('employee.letters.view')))) {
            $issued[] = $row;
        }
    }
} catch (\Throwable) {
    $issued = []; // a table this installation does not have yet is an empty register
}

usort($issued, static fn (array $a, array $b): int
    => strcmp((string) ($b['issued_on'] ?? '') . (string) ($b['id'] ?? ''), (string) ($a['issued_on'] ?? '') . (string) ($a['id'] ?? '')));
$needed = SmartPRS\Surface\Letters::permissionsFor($template);
$mayIssue = array_reduce($needed, static fn (bool $c, string $p): bool => $c && $may($p), true);
?>
<div class="page-header">
  <div><h2><?= e($label) ?>s</h2><p><?= count($issued) ?> issued · template <code><?= e($kind) ?></code> in <code>config/letters.json</code><?= ($template['customised'] ?? false) ? ' · your wording' : '' ?> · issuing needs <?= e(implode(', ', $needed)) ?></p></div>
<?php if ($viewing !== null): ?>
  <button class="btn btn-outline no-print" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print or save as PDF</button>
<?php endif; ?>
</div>

<?php if ($viewing !== null): $who = $byId[(string) ($viewing['employee_id'] ?? '')] ?? null; ?>
<div class="card">
  <h2><?= e($label) ?> <span class="note">for <?= $who === null ? 'a new candidate' : e(SmartPRS\Surface\Employment::label($who)) ?> · issued <?= e((string) ($viewing['issued_on'] ?? '')) ?> by <?= e((string) ($viewing['issued_by'] ?? '—')) ?> · <?= e((string) ($viewing['status'] ?? '')) ?></span></h2>
  <pre style="white-space:pre-wrap;background:#fff;border:1px solid var(--line);padding:18px;border-radius:6px;font:13px/1.7 Georgia,serif"><?= e((string) ($viewing['body'] ?? '')) ?></pre>
  <p class="note no-print">This is the text that went out, kept as it went out. <a href="?r=letters-<?= e($kind) ?>">← All <?= e(strtolower($label)) ?>s</a></p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/letters-issue-card.php'; ?>

<div class="card no-print">
  <h2>The <?= e(strtolower($label)) ?>, as it stands<?= ($template['customised'] ?? false) ? ' <span class="note">(your wording)</span>' : ' <span class="note">(as shipped)</span>' ?></h2>
  <pre style="white-space:pre-wrap;background:#fff;border:1px solid var(--line);padding:18px;border-radius:6px;font:13px/1.7 Georgia,serif"><?= e((string) ($template['body'] ?? '')) ?></pre>
<?php if ($may('tenant.settings.update')): ?>
  <details>
    <summary class="btn btn-outline" style="display:inline-block;cursor:pointer">Change this wording</summary>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
      <input type="hidden" name="action" value="letter-configure">
      <input type="hidden" name="k" value="<?= e($kind) ?>">
      <input type="hidden" name="return_route" value="letters-<?= e($kind) ?>">
      <div class="row">
        <div style="flex:2"><label for="label">Called</label>
          <input id="label" name="label" value="<?= e((string) $template['label']) ?>"></div>
        <div style="flex:3"><label for="requires">Must have a value before it will issue</label>
          <input id="requires" name="requires"
            value="<?= e(implode(', ', array_map('strval', (array) ($template['requires'] ?? [])))) ?>">
          <span class="note">Comma separated. Every one of these must also appear in the wording
            below, or the letter would refuse for everybody naming a field nobody can see.</span></div>
        <div style="max-width:220px"><label for="lop_days">Loss of pay (days)</label>
          <input id="lop_days" name="lop_days" type="number" min="0" step="0.5" placeholder="blank = no deduction" value="<?= e((string) ($template['lop_days'] ?? '')) ?>"></div>
      </div>
      <div class="row" style="margin-top:12px;align-items:flex-end">
        <div style="flex:3"><label for="email_subject">Email subject <span class="note">(blank = "<?= e($label) ?> — name (code)")</span></label>
          <input id="email_subject" name="email_subject" value="<?= e((string) ($template['email_subject'] ?? '')) ?>"></div>
        <div style="flex:2"><label>Insert a field into the subject</label><?= letterFieldPicker('email_subject') ?></div>
      </div>
      <input type="hidden" name="needs_ack" value="0">
      <label style="display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-weight:600"><input type="checkbox" name="needs_ack" value="1"<?= SmartPRS\Surface\Letters::needsAck($template) ? ' checked' : '' ?>> Employee must acknowledge ("I acknowledge" in My Letters; tracked in the Sign-off report)</label>
      <label for="body" style="margin-top:12px">The letter <span style="float:right;font-weight:400"><?= letterFieldPicker('body') ?></span></label>
      <textarea id="body" name="body" rows="20" style="width:100%;font:13px/1.7 ui-monospace,
        Menlo,monospace"><?= e((string) ($template['body'] ?? '')) ?></textarea>
      <div class="row" style="margin-top:12px">
        <div style="flex:3"><label for="note">Why (kept with the version)</label>
          <input id="note" name="note" placeholder="e.g. legal asked for the arbitration line"></div>
        <div style="align-self:end"><button type="submit">Save as a new version</button></div>
      </div>
      <p class="note">Letters already issued do not change. What went out is stored as it went out
         &mdash; re-rendering a signed certificate from today's wording would be an overwritten
         record wearing a disguise.</p>
    </form>
  </details>
<?php endif; ?>
</div>


<div class="card no-print">
<?php if ($issued === []): ?>
  <p class="note">No <?= e(strtolower($label)) ?> issued yet.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Code</th><th>Employee</th><th>Issued on</th><th>By</th><th>Status</th><th>First line</th><th></th></tr></thead>
    <tbody>
<?php foreach ($issued as $one): $who = $byId[(string) ($one['employee_id'] ?? '')] ?? null;
        $cand = $who === null && preg_match('/^To\s*\n([^\n]+)/m', (string) ($one['body'] ?? ''), $cm) === 1 ? trim($cm[1]) : ''; ?>
      <tr>
        <td><b><?= $who === null ? '<span class="badge badge-blue">New candidate</span>' : e(SmartPRS\Surface\Employment::code($who)) ?></b></td>
        <td><?= e($who === null ? $cand : (string) ($who['name'] ?? '')) ?></td>
        <td><?= e((string) ($one['issued_on'] ?? '')) ?></td>
        <td class="note"><?= e((string) ($one['issued_by'] ?? '—')) ?></td>
        <td><span class="badge badge-<?= (string) ($one['status'] ?? '') === 'issued' ? 'green' : 'gray' ?>"><?= e((string) ($one['status'] ?? '')) ?></span></td>
        <td class="note"><?= e(mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($one['body'] ?? '')) ?? ''), 0, 60)) ?>…</td>
        <td><a class="btn btn-sm btn-outline" href="?r=letters-<?= e($kind) ?>&amp;view=<?= e((string) $one['id']) ?>">View / print</a></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
