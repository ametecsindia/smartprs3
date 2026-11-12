<?php
/**
 * DAY-WISE SHIFT TIMINGS (owner, 13 Oct; layout 14 Oct) — Organization Setup → Shifts.
 * One card per shift: name, code and regular times on top with Edit / Delete, the
 * week ACROSS (Mon … Sun) with a Start and an End row of time boxes, and a Save.
 * Every box shows the time that day really runs; a day left at the regular time
 * is stored as "regular" (blank), a changed one (every Saturday 09:30–16:00)
 * replaces it for that weekday in attendance, late marks and payroll
 * (Shifts::for()). Saved as a Customisation version.
 */
declare(strict_types=1);

$swShifts = liveRows($store, 'shifts');
$swWeek = (new SmartPRS\Surface\Shifts($store))->week();
$swDays = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$swToday = (int) date('N');
$swCanSave = $may('tenant.settings.update');
$swOrg = orgNames($store);
?>
<style>
  .sw-card{border:1.5px solid var(--accent);border-radius:10px;overflow:hidden;background:var(--card);margin-bottom:16px}
  .sw-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;background:color-mix(in srgb,var(--accent) 7%,var(--card));border-bottom:1px solid var(--border)}
  .sw-name{font-weight:700;color:var(--accent);font-size:15px}
  .sw-code{font-size:12px;color:var(--text2);font-weight:600}
  .sw-meta{font-size:12px;color:var(--text3)}
  .sw-acts{margin-left:auto;display:flex;gap:6px;align-items:center}
  html body table.sw-t{width:100%;border-collapse:collapse;table-layout:fixed;margin:0}
  html body table.sw-t th:nth-child(n), html body table.sw-t td:nth-child(n){background:var(--card);padding:8px 6px;text-align:center;border-bottom:1px solid var(--border)}
  html body table.sw-t thead th:nth-child(n){background:color-mix(in srgb,var(--accent) 10%,var(--card));color:var(--accent);font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
  html body table.sw-t th.sw-row:nth-child(n){text-align:left;padding-left:14px;width:70px;font-size:13px;color:var(--text1);text-transform:none;letter-spacing:0;background:var(--card)}
  html body table.sw-t thead th.sw-row:nth-child(n){background:color-mix(in srgb,var(--accent) 10%,var(--card))}
  html body table.sw-t .sw-today:nth-child(n){background:color-mix(in srgb,var(--accent) 18%,var(--card))}
  html body table.sw-t td.sw-today:nth-child(n){background:color-mix(in srgb,var(--accent) 5%,var(--card))}
  html body table.sw-t tbody tr:last-child td, html body table.sw-t tbody tr:last-child th{border-bottom:0}
  .sw-t input[type=time]{width:100%;max-width:120px;text-align:center;padding:5px 4px;font-variant-numeric:tabular-nums}
  .sw-t input.sw-own{border-color:var(--accent);font-weight:700}
  .sw-dur{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;padding:10px 14px;border-top:1px solid var(--border);font-size:13px}
  .sw-dur b{font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--accent);margin-right:4px}
  .sw-dur label{display:flex;align-items:center;gap:6px;margin:0;color:var(--text2)}
  .sw-dur input{width:90px !important;min-width:0 !important}
  .sw-foot{display:flex;justify-content:flex-end;gap:8px;padding:10px 14px;border-top:1px solid var(--border)}
</style>
<div class="card" id="shift-week">
  <div class="card-header"><div>
    <h3 style="font-size:15px;font-weight:700">Day-wise Shift Timings</h3>
    <p class="note" style="margin:0">Each shift&rsquo;s start and end for every day of the week. Change a day&rsquo;s time and
      press Save; a day left at the regular time follows the shift. Weekly offs still come from the W.OFF schedule.</p>
  </div></div>
  <div style="padding:16px 22px">
<?php if ($swShifts === []): ?>
    <p class="note">No shifts yet — add one with the form above.</p>
<?php endif; ?>
<?php foreach (array_values($swShifts) as $swS):
    $swId = (string) $swS['id'];
    $swStart = substr((string) ($swS['start_time'] ?? ''), 0, 5);
    $swEnd = substr((string) ($swS['end_time'] ?? ''), 0, 5);
    $swGrace = trim((string) ($swS['grace_min'] ?? ''));
    $swScope = array_filter([
        (string) ($swOrg['companies'][(string) ($swS['employer_company_id'] ?? '')] ?? ''),
        (string) ($swOrg['branches'][(string) ($swS['branch_id'] ?? '')] ?? ''),
        (string) ($swOrg['departments'][(string) ($swS['department_id'] ?? '')] ?? ''),
    ]);
    $swMeta = 'Regular ' . $swStart . '–' . $swEnd
        . ($swGrace !== '' ? ' · grace ' . (int) $swGrace . ' min' : '')
        . ' · ' . ($swScope === [] ? 'All companies' : implode(' / ', $swScope)); ?>
    <form method="post" class="sw-card" id="sw-<?= e($swId) ?>">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
      <input type="hidden" name="action" value="shift-week-save">
      <input type="hidden" name="shift_id" value="<?= e($swId) ?>">
      <div class="sw-head">
        <span class="sw-name"><?= e(trim((string) ($swS['name'] ?? ''))) ?></span>
        <?php if (trim((string) ($swS['code'] ?? '')) !== ''): ?><span class="sw-code"><?= e((string) $swS['code']) ?></span><?php endif; ?>
        <span class="sw-meta"><?= e($swMeta) ?></span>
<?php if ($swCanSave): ?>
        <span class="sw-acts">
          <a class="btn btn-outline btn-sm" href="?r=setup-shifts&amp;k=shifts&amp;edit=<?= e($swId) ?>"><i class="fas fa-pen"></i> Edit</a>
          <button class="btn btn-sm" type="submit" form="sw-del-<?= e($swId) ?>" style="background:#fff;color:#b91c1c;border:1px solid #fecaca"><i class="fas fa-trash"></i> Delete</button>
        </span>
<?php endif; ?>
      </div>
      <div class="table-wrap"><table class="sw-t">
        <thead><tr><th class="sw-row"></th><?php foreach ($swDays as $swN => $swDay): ?><th class="<?= $swN === $swToday ? 'sw-today' : '' ?>"><?= $swDay ?></th><?php endforeach; ?></tr></thead>
        <tbody>
<?php foreach (['start' => 'Start', 'end' => 'End'] as $swK => $swL): ?>
          <tr><th class="sw-row"><?= $swL ?></th>
<?php   foreach ($swDays as $swN => $swDay):
            $swOwn = (string) ($swWeek[$swId][(string) $swN][$swK] ?? '');
            $swRegular = $swK === 'start' ? $swStart : $swEnd; ?>
            <td class="<?= $swN === $swToday ? 'sw-today' : '' ?>"><input type="time" name="days[<?= $swN ?>][<?= $swK ?>]" value="<?= e($swOwn !== '' ? $swOwn : $swRegular) ?>" data-regular="<?= e($swRegular) ?>" class="<?= $swOwn !== '' ? 'sw-own' : '' ?>" title="<?= $swOwn !== '' ? 'Own time for ' . $swDay : 'Regular time' ?>" aria-label="<?= $swDay ?> <?= strtolower($swL) ?>"<?= $swCanSave ? '' : ' disabled' ?>></td>
<?php   endforeach; ?>
          </tr>
<?php endforeach; ?>
        </tbody>
      </table></div>
      <?php // ATTENDANCE DURATION (owner, 14 Oct — moved here from Late Login Policy): what a full and
            // a half day take on this shift, and the break allowed. Blank = the shift's length. ?>
      <div class="sw-dur"><b>Attendance duration</b>
<?php foreach (['full_day_hours' => ['Full-day hours', '0.25', 'e.g. 8.5'], 'half_day_hours' => ['Half-day hours', '0.25', 'e.g. 4'], 'break_budget' => ['Break allowed (min / day)', '1', 'e.g. 60']] as $swK => [$swL, $swStep, $swPh]):
    $swV = trim((string) ($swS[$swK] ?? ''));
    $swV = $swV === '' ? '' : (preg_match('/^\d+(\.\d+)?$/', $swV) === 1 ? (rtrim(rtrim($swV, '0'), '.') ?: '0') : $swV); ?>
        <label><?= $swL ?> <input type="number" name="<?= $swK ?>" min="0" step="<?= $swStep ?>" value="<?= e($swV) ?>" placeholder="<?= $swPh ?>"<?= $swCanSave ? '' : ' disabled' ?>></label>
<?php endforeach; ?>
      </div>
<?php if ($swCanSave): ?>
      <div class="sw-foot">
        <button class="btn btn-outline btn-sm" type="button" onclick="this.form.querySelectorAll('input[type=time]').forEach(function(i){i.value=i.dataset.regular;i.classList.remove('sw-own');})">Reset to regular</button>
        <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-save"></i> Save</button>
      </div>
<?php endif; ?>
    </form>
<?php if ($swCanSave): ?>
    <form method="post" id="sw-del-<?= e($swId) ?>" style="display:none" onsubmit="return confirm('Delete shift <?= e(addslashes(trim((string) ($swS['name'] ?? '')))) ?>? It is kept in history and stops being offered.')">
      <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="module-delete">
      <input type="hidden" name="k" value="shifts"><input type="hidden" name="id" value="<?= e($swId) ?>"></form>
<?php endif; ?>
<?php endforeach; ?>
  </div>
</div>
<script>
document.querySelectorAll('#shift-week .sw-t input[type=time]').forEach(function (i) {
  i.addEventListener('input', function () { i.classList.toggle('sw-own', i.value !== i.dataset.regular); });
});
</script>
