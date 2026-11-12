<?php declare(strict_types=1); if (!$may('employee.update')): ?>
<div class="page-header"><div><h2>Send Message</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Send Message: pick a scope (all / company / branch / department /
// designation / team / employee), tick channels, write subject + body, send.
// Here the scope is the same Organization Setup filter every register uses, the
// recipient count and the addresses they resolve to are shown BEFORE anything is
// queued, and — no transport being configured in this build — the send QUEUES a
// row per recipient and channel to the Messages Log and says so.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Messaging;

$messaging = new Messaging($store, __DIR__ . '/../../config/communication.json');
$channels = $messaging->channels();
$filter = orgFilter();
$people = $scope->only($scope->only(liveRows($store, 'employees'), $may('employee.list')), $may('employee.view'));
$inView = orgFilterApply($store, $people, $filter);
$picked = array_values(array_intersect(array_keys($channels), (array) ($_GET['ch'] ?? ['email'])));
$resolved = $messaging->resolve($inView, $picked);
$target = implode(' · ', array_filter(array_map(static fn (string $k, string $v): string => $v === '' ? '' : ORG_FILTERS[$k] . ' ' . $v, array_keys($filter), $filter))) ?: 'ALL employees';

// A saved template pre-fills the message: sms_templates (SMS) and wa_templates (WhatsApp).
$templates = [];

foreach (['sms_templates' => ['label' => 'SMS', 'text' => 'content'], 'wa_templates' => ['label' => 'WhatsApp', 'text' => 'body']] as $table => $shape) {
    foreach (liveRows($store, $table) as $t) {
        $templates[$table . ':' . (string) $t['id']] = ['name' => $shape['label'] . ' · ' . (string) ($t['name'] ?? ''), 'text' => (string) ($t[$shape['text']] ?? '')];
    }
}

$tpl = (string) ($_GET['tpl'] ?? '');
$prefill = $templates[$tpl]['text'] ?? '';
$channelBoxes = '<div><label>Channels</label><div class="row" style="gap:10px;padding-top:6px">';

foreach ($channels as $key => $ch) {
    $channelBoxes .= '<label><input type="checkbox" name="ch[]" value="' . e($key) . '"' . (in_array($key, $picked, true) ? ' checked' : '') . '> ' . e((string) $ch['label']) . '</label>';
}

$channelBoxes .= '</div></div>';
// The channels sit in the scope bar so that Preview recipients resolves them too.
$bar = str_replace('<div><button class="go"', $channelBoxes . '<div><button class="go"', orgFilterBar('send-message', $filter, orgFilterOptions($store, $people), ['tpl' => $tpl], 'Preview recipients'));
?>
<div class="page-header">
  <div><h2>Send Message</h2><p>Broadcast to employees by Email, WhatsApp or SMS. <b>No transport is configured in this build</b>: a send queues one row per recipient and channel in the <a href="?r=m&amp;k=messages-log">Messages Log</a> with status <code><?= e((string) $messaging->config()['queued_status']) ?></code>; nothing leaves this server until a provider is wired under WhatsApp API / SMS Settings.</p></div>
</div>

<?= $bar ?>

<div class="row" style="align-items:flex-start">
<div class="card" style="flex:2">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="send-message">
<?php foreach ($filter as $k => $v): ?>
    <input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>">
<?php endforeach; ?>
    <fieldset class="form-section"><legend>Who</legend>
      <p><b><?= count($inView) ?></b> employee<?= count($inView) === 1 ? '' : 's' ?> in view: <?= e($target) ?>. Change the scope with the bar above; ALL in every box means everybody you may see.</p>
    </fieldset>
    <fieldset class="form-section"><legend>Channels</legend>
<?php foreach ($picked as $c): ?>
      <input type="hidden" name="channels[]" value="<?= e($c) ?>">
<?php endforeach; ?>
      <p><?= $picked === [] ? 'None ticked.' : e(implode(', ', array_map(static fn (string $c): string => (string) $channels[$c]['label'] . ' (' . (string) $channels[$c]['address'] . (isset($channels[$c]['fallback']) ? ', else ' . (string) $channels[$c]['fallback'] : '') . ')', $picked))) ?> <span class="note">Tick channels in the bar above and press Preview recipients.</span></p>
    </fieldset>
    <fieldset class="form-section"><legend>Message</legend>
      <div class="row">
        <div style="flex:1"><label for="tpl">Template</label>
          <select id="tpl" name="tpl" onchange="var f=this.form; var u=new URL(location.href); u.searchParams.set('tpl', this.value); location.href=u.toString();">
            <option value="">— none —</option>
<?php foreach ($templates as $key => $t): ?>
            <option value="<?= e($key) ?>"<?= $key === $tpl ? ' selected' : '' ?>><?= e($t['name']) ?></option>
<?php endforeach; ?>
          </select>
          <span class="note">Saved under WhatsApp Templates / SMS Templates.</span></div>
      </div>
      <div><label for="subject">Subject</label><input id="subject" name="subject" required maxlength="200" placeholder="Short and clear"></div>
      <div><label for="message">Message</label><textarea id="message" name="message" rows="8" required><?= e($prefill) ?></textarea></div>
    </fieldset>
    <button class="btn btn-primary" type="submit"<?= $resolved['to'] === [] ? ' disabled title="Nobody resolves to an address on the channels ticked"' : '' ?>><i class="fas fa-paper-plane"></i> Queue for <?= count($resolved['to']) ?> recipient<?= count($resolved['to']) === 1 ? '' : 's' ?></button>
  </form>
</div>

<div class="card" style="flex:1">
  <h2>Recipient preview</h2>
<?php if ($picked === []): ?>
  <p class="note">Tick at least one channel.</p>
<?php else: ?>
<?php foreach ($picked as $c): $on = array_values(array_filter($resolved['to'], static fn (array $r): bool => $r['channel'] === $c)); $none = $resolved['missing'][$c] ?? []; ?>
  <p><b><?= e((string) $channels[$c]['label']) ?></b>: <?= count($on) ?> with an address<?php if ($none !== []): ?>, <span class="badge badge-amber"><?= count($none) ?> without</span><?php endif; ?></p>
<?php if ($none !== []): ?>
  <p class="note">No <?= e((string) $channels[$c]['address']) ?> on: <?= e(implode(', ', $none)) /* the whole list (owner, 23 Oct) */ ?>. They will not be queued on this channel.</p>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($resolved['to'] !== []): ?>
  <div class="table-wrap" style="max-height:340px;overflow:auto"><table>
    <thead><tr><th>Code</th><th>Employee</th><th>Channel</th><th>To</th></tr></thead>
    <tbody>
<?php foreach (array_slice($resolved['to'], 0, 200) as $r): ?>
      <tr><td><b><?= e(Employment::code($r['employee'])) ?></b></td><td><?= e((string) ($r['employee']['name'] ?? '')) ?></td><td><?= e($r['channel']) ?></td><td class="note"><?= e($r['address']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php if (count($resolved['to']) > 200): ?><p class="note">First 200 of <?= count($resolved['to']) ?> shown.</p><?php endif; ?>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
