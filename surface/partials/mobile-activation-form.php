<?php
/**
 * "SEND ACTIVATION CODE" — the form under Administration → Mobile Devices and QR Attendance → Phones (owner, 12 Nov 2026).
 * Set $maBackTo ('mobile-devices' or 'qr-attendance&qp=phones') before including. Posts to surface/actions/mobile-activation.php.
 */
if (!$may('tenant.settings.update')) { return; }
$maChoices = [];
$maLinked = [];
foreach ($store->all('users') as $u) { trim((string) ($u['employee_id'] ?? '')) !== '' && $maLinked[(string) $u['employee_id']] = true; }
foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $maChoices['e:' . $e['id']] = SmartPRS\Surface\Employment::code($e) . ' · ' . trim((string) ($e['name'] ?? '')) . (isset($maLinked[(string) $e['id']]) ? '' : ' (no login yet)');
}
foreach ($store->all('users') as $u) {
    if (trim((string) ($u['employee_id'] ?? '')) === '' && trim((string) ($u['disabled_at'] ?? '')) === '' && (string) ($u['tenant_id'] ?? '') === (string) $tenantId) {
        $maChoices['u:' . $u['id']] = (string) $u['email'] . ' (login)';
    }
}
?>
<div class="card" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
  <div style="flex:1 1 320px"><h3 style="margin:0 0 4px"><i class="fas fa-key"></i> Mobile app activation code</h3>
    <div class="note">First sign-in on a new phone asks for this code. It works once, is emailed to the person and shown here to pass on.</div></div>
  <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex:1 1 420px;margin:0"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="mobile-activation"><input type="hidden" name="back" value="<?= e((string) $maBackTo) ?>">
    <div style="flex:1"><label for="ma-subject">Employee or login</label><?= employeePicker('ma-subject', 'subject', $maChoices, '', 'Type code, name or email', true) ?></div>
    <button class="go" type="submit"><i class="fas fa-paper-plane"></i> Send code</button>
  </form>
</div>
<?php $maCard = (array) ($_SESSION['ma_card'] ?? []); unset($_SESSION['ma_card']); if ($maCard !== []): // one field per line (12 Nov 2026) ?>
<div class="card" style="background:#ECFDF5;border-left:4px solid #16A34A;position:relative">
  <button type="button" title="Close" onclick="this.closest('.card').remove()" style="position:absolute;top:10px;right:10px;width:28px;height:28px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text2);cursor:pointer"><i class="fas fa-xmark"></i></button>
  <div style="color:#166534;margin-bottom:6px"><i class="fas fa-circle-check"></i> Activation code sent — app sign-in details</div>
  <table style="width:auto;border:0;margin:0"><tbody>
<?php foreach ($maCard as $fk => $fv): ?>
    <tr><td style="border:0;padding:2px 14px 2px 0;background:transparent;color:var(--text2);white-space:nowrap"><?= e((string) $fk) ?></td><td style="border:0;padding:2px 0;background:transparent;<?= $fk === 'Activation code' ? 'font:16px ui-monospace,Consolas,monospace;letter-spacing:.1em' : '' ?>"><?= e((string) $fv) ?></td></tr>
<?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
