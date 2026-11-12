<?php declare(strict_types=1); if (!$may('tenant.settings.update')): ?>
<div class="page-header"><div><h2>Performance Appraisal</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// ORGANIZATION CONFIGURATION → PERFORMANCE APPRAISAL. One or more "boxes" per
// financial year (or half-year / quarter / month, whichever cadence the
// owner runs appraisals on): a scope — company/branch/department/role/
// designation/team/employee, blank = everyone — and, within that scope, an
// unlimited table of slabs: a rating (or a rating RANGE), a description, and
// the % salary increase. See PerformanceAppraisal for how the most specific
// box wins, how a key maps to real dates, and how the archive is built.
//
// 21 Sep 2026 (owner): every field is checked AS IT IS TYPED and the form
// refuses to submit while anything is wrong; a refusal from the server comes
// back with every row still filled in and the offending boxes marked; a slab
// carries a description; a numeric box can slab by range; only the current
// financial year's criteria are on this screen — older ones and every earlier
// saved version are under Archive.
use SmartPRS\Surface\Performance;
use SmartPRS\Surface\PerformanceAppraisal;

$perf = new Performance(__DIR__ . '/../../config/performance.json');
$appraisal = new PerformanceAppraisal($customisation);
$scale = $perf->scale();
$periods = $perf->periods(date('Y-m-d'));
$today = date('Y-m-d');
$fyStart = financialYearStart($customisation);
$currentFyYear = PerformanceAppraisal::currentFyYear($today, $fyStart);
$fys = array_values(array_filter($appraisal->fys(), static fn (string $fy): bool => PerformanceAppraisal::isCurrent($fy, $today, $fyStart)));
$people = $scope->only(liveRows($store, 'employees'), $may('employee.list'));
$orgOptions = orgFilterOptions($store, $people);
$filter = orgFilter();
$mayApply = $may('employee.salary.update');
$view = (string) ($_GET['view'] ?? '') === 'archive' ? 'archive' : 'current';
$paOld = $_SESSION['pa_old'] ?? null;
unset($_SESSION['pa_old']);

$paRangeText = static function (string $fy) use ($fyStart): string {
    $r = PerformanceAppraisal::range($fy, $fyStart);

    return $r === null ? '' : date('d M Y', (int) strtotime($r['from'])) . ' – ' . date('d M Y', (int) strtotime($r['to']));
};
$paScopeSummary = static function (array $scopeArr): string {
    if ($scopeArr === []) {
        return 'All employees';
    }

    $labels = ['company' => 'Company', 'branch' => 'Branch', 'department' => 'Department', 'role' => 'Role',
        'designation' => 'Designation', 'team' => 'Team', 'employee' => 'Employee'];
    $parts = [];

    foreach ($scopeArr as $k => $v) {
        $parts[] = ($labels[$k] ?? ucfirst($k)) . ': ' . $v;
    }

    return implode(' · ', $parts); // plain text; e()'d at the render call site
};
$paRatingText = static fn (array $box, array $slab): string => PerformanceAppraisal::format($box) === 'slab'
    ? $slab['rating'] . ' – ' . ($slab['rating_to'] ?? '') : (string) $slab['rating'];
$paSlabTable = static function (array $box) use ($paRatingText): string {
    $h = '<div class="table-wrap pa-view"><table><thead><tr><th class="pa-h-rating">' . ['labels' => 'Rating label', 'numbers' => 'Number', 'slab' => 'Range'][PerformanceAppraisal::format($box)] . '</th>'
        . '<th class="pa-h-desc">Description</th><th class="pa-h-pct n">% Salary increase</th></tr></thead><tbody>';

    foreach ((array) ($box['slabs'] ?? []) as $slab) {
        $h .= '<tr><td class="pa-c-rating"><b>' . e($paRatingText($box, $slab)) . '</b></td>'
            . '<td class="pa-c-desc">' . (trim((string) ($slab['description'] ?? '')) !== '' ? e((string) $slab['description']) : '<span class="note">—</span>') . '</td>'
            . '<td class="pa-c-pct n"><b>' . e((string) $slab['increment_pct']) . '%</b></td></tr>';
    }

    return $h . '</tbody></table></div>';
};
?>
<style>
  .pa-slabs{width:100%;border-collapse:separate;border-spacing:0 6px}
  .pa-slabs th{font-size:11px;letter-spacing:.06em;text-transform:uppercase;padding:8px 10px;text-align:left;color:#1e3a5f}
  .pa-slabs td{padding:6px 10px;vertical-align:top;background:#fff}
  .pa-slabs th.pa-h-no,.pa-slabs td.pa-c-no{width:36px;text-align:center;color:#64748b}
  .pa-slabs .pa-h-rating,.pa-view .pa-h-rating{background:#dbeafe}
  .pa-slabs .pa-c-rating{background:#eff6ff}
  .pa-slabs .pa-h-desc,.pa-view .pa-h-desc{background:#e2e8f0}
  .pa-slabs .pa-c-desc{background:#f8fafc}
  .pa-slabs .pa-h-pct,.pa-view .pa-h-pct{background:#fed7aa}
  .pa-slabs .pa-c-pct{background:#fff7ed}
  .pa-slabs td input{width:100%;box-sizing:border-box}
  .pa-slabs .pa-c-rating{width:15%}
  .pa-slabs .pa-c-pct{width:170px}
  .pa-pct-wrap{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .pa-pct-wrap input{flex:1;min-width:0}
  .pa-pct-wrap .field-live-error{flex-basis:100%;order:3;margin-top:0 !important}
  .pa-pct-wrap span{font-weight:700;color:#9a3412}
  .pa-slabs td.pa-c-x{width:44px;text-align:center;background:transparent}
  .pa-slabs tr td:first-child{border-radius:8px 0 0 8px}
  .pa-view td.pa-c-rating{background:#eff6ff;width:22%}
  .pa-view td.pa-c-desc{background:#f8fafc}
  .pa-view td.pa-c-pct{background:#fff7ed;width:180px}
  .pa-range-note{margin:6px 0 0;color:#1e3a5f;font-weight:600}
  .pa-archive td{vertical-align:top}
  .pa-formats{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px}
  .pa-format{display:flex;gap:10px;align-items:flex-start;border:2px solid #e2e8f0;border-radius:10px;padding:12px;cursor:pointer;background:#fff;margin:0}
  .pa-format:has(input:checked){border-color:#f97316;background:#fff7ed}
  .pa-format input{margin-top:3px}
  .pa-format > span{display:flex;flex-direction:column;gap:4px}
  .pa-eg{font-size:12px;color:#1e3a5f;font-family:var(--mono, monospace)}
</style>

<?php if ($view === 'archive'): ?>
<?php
    // THE ARCHIVE — past financial years and every earlier saved version.
    $arAll = $appraisal->archive($today, $fyStart);
    $arFy = trim((string) ($_GET['a_fy'] ?? ''));
    $arFrom = trim((string) ($_GET['a_from'] ?? ''));
    $arTo = trim((string) ($_GET['a_to'] ?? ''));
    $arScope = [];

    foreach (['company', 'branch', 'department'] as $d) {
        $arScope[$d] = trim((string) ($_GET['a_' . $d] ?? ''));
    }

    $arFys = array_values(array_unique(array_map(static fn (array $a): string => $a['fy'], $arAll)));
    $arRows = array_values(array_filter($arAll, static function (array $a) use ($arFy, $arFrom, $arTo, $arScope): bool {
        $day = substr($a['saved_at'], 0, 10);

        if ($arFy !== '' && $a['fy'] !== $arFy) { return false; }
        if ($arFrom !== '' && ($day === '' || $day < $arFrom)) { return false; }
        if ($arTo !== '' && ($day === '' || $day > $arTo)) { return false; }

        foreach ($arScope as $d => $v) {
            if ($v !== '' && strcasecmp((string) ($a['box']['scope'][$d] ?? ''), $v) !== 0) { return false; }
        }

        return true;
    }));
?>
<div class="page-header">
  <div><h2>Performance Appraisal &mdash; Archive</h2>
    <p>Every appraisal criterion from a financial year that has ended, and every earlier version of a criterion that was later edited. Nothing here is applied any more; it is kept so anyone can see what applied, to whom, and when.</p></div>
  <div class="actions"><a class="btn btn-outline" href="?r=performance-appraisal"><i class="fas fa-arrow-left"></i> Current financial year</a></div>
</div>
<div class="card">
  <form method="get" class="row" style="align-items:flex-end;margin:0">
    <input type="hidden" name="r" value="performance-appraisal"><input type="hidden" name="view" value="archive">
    <div><label for="a-fy">Year / period</label><select id="a-fy" name="a_fy"><option value="">ALL</option><?php foreach ($arFys as $y): ?><option value="<?= e($y) ?>"<?= $y === $arFy ? ' selected' : '' ?>><?= e(PerformanceAppraisal::label($y)) ?></option><?php endforeach; ?></select></div>
    <div><label for="a-from">Saved from</label><input type="date" id="a-from" name="a_from" value="<?= e($arFrom) ?>"></div>
    <div><label for="a-to">Saved to</label><input type="date" id="a-to" name="a_to" value="<?= e($arTo) ?>" min="<?= e($arFrom) ?>"></div>
<?php foreach (['company' => 'Company', 'branch' => 'Branch', 'department' => 'Department'] as $d => $dl): ?>
    <div><label for="a-<?= $d ?>"><?= $dl ?></label><select id="a-<?= $d ?>" name="a_<?= $d ?>" data-org="<?= $d ?>"><option value="">ALL</option><?php foreach ($orgOptions[$d] ?? [] as $o): ?><option<?= strcasecmp($o, $arScope[$d]) === 0 ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></div>
<?php endforeach; ?>
    <div><button class="go" type="submit">Show</button></div>
    <div><a class="btn btn-outline btn-sm" href="?r=performance-appraisal&amp;view=archive">Clear</a></div>
  </form>
</div>
<div class="card" style="padding:0">
<?php if ($arRows === []): ?>
  <div class="empty"><i class="fas fa-box-archive"></i>Nothing in the archive<?= $arAll === [] ? ' yet — criteria move here once their financial year ends, or when they are edited.' : ' matches these filters.' ?></div>
<?php else: ?>
  <div class="table-wrap"><table class="pa-archive">
    <thead><tr><th>Year / period</th><th>Dates</th><th>Applies to</th><th>Slabs</th><th>Saved</th><th>Status</th></tr></thead>
    <tbody>
<?php foreach ($arRows as $a): ?>
      <tr>
        <td><b><?= e(PerformanceAppraisal::label($a['fy'])) ?></b></td>
        <td class="note" style="white-space:nowrap"><?= e($paRangeText($a['fy'])) ?></td>
        <td><?= e($paScopeSummary((array) ($a['box']['scope'] ?? []))) ?><div class="note"><?= e(['labels' => 'Rating labels', 'numbers' => 'Numbers', 'slab' => 'Slab (number ranges)'][PerformanceAppraisal::format((array) $a['box'])]) ?></div></td>
        <td style="min-width:320px"><?= $paSlabTable((array) $a['box']) ?></td>
        <td class="note" style="white-space:nowrap"><?= e(substr($a['saved_at'], 0, 16)) ?><br><?= e($a['saved_by']) ?></td>
        <td><span class="badge <?= $a['status'] === 'Past financial year' ? 'badge-gray' : 'badge-amber' ?>"><?= e($a['status']) ?></span><?php if ($a['replaced_at'] !== ''): ?><div class="note">replaced <?= e(substr($a['replaced_at'], 0, 16)) ?></div><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<?php return; endif; ?>

<div class="page-header">
  <div><h2>Performance Appraisal</h2>
    <p>For each financial year, and for each company, branch, department, role, designation, team or employee you choose, set <b>how people are rated</b> &mdash; rating labels (S, M, A, R, T…), numbers, or slab ranges &mdash; and what each rating means (description) and pays (% salary increase). The Performance screen rates each employee on the scale that covers them, its recommended increment reads the same table, and <b>Apply</b> below raises CTC from it. Employees no criterion covers are rated on the shipped scale (<?= e(implode(' / ', array_map(static fn (int $v, string $l): string => $v . ' ' . $l, array_keys($scale), $scale))) ?>).</p></div>
  <div class="actions" style="display:flex;gap:8px">
    <a class="btn btn-outline" href="?r=performance-appraisal&amp;view=archive"><i class="fas fa-box-archive"></i> Archive</a>
    <button class="btn btn-primary" type="button" onclick="paOpenNew()"><i class="fas fa-plus"></i> Add New</button></div>
</div>

<div id="pa-panel" class="card" style="border:2px solid #f97316" hidden>
  <h2 id="pa-panel-title" style="margin-top:0">Add a Performance Appraisal customisation</h2>
  <form method="post" id="pa-form" data-org-scope>
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="performance-appraisal">
    <input type="hidden" name="step" value="save">
    <input type="hidden" name="box_id" id="pa-box-id" value="">
    <input type="hidden" name="sub" id="pa-sub-value" value="">
    <fieldset class="form-section"><legend>Period</legend>
      <div class="row">
        <div><label for="pa-year">Year <span style="color:#dc2626">*</span></label><input type="number" id="pa-year" name="year" min="2000" max="2099" required value="<?= e((string) $currentFyYear) ?>" style="width:7rem"></div>
        <div><label for="pa-gran">Period type</label><select id="pa-gran" name="granularity" onchange="paGranChange()">
<?php foreach (PerformanceAppraisal::GRANULARITIES as $gk => $gl): ?><option value="<?= e($gk) ?>"><?= e($gl) ?></option><?php endforeach; ?>
        </select></div>
        <div id="pa-sub-wrap"><label id="pa-sub-label" for="pa-sub-select">Half</label><select id="pa-sub-select"></select></div>
      </div>
      <p class="pa-range-note" id="pa-range-note"></p>
      <p class="note">The financial year starts in <?= e(date('F', mktime(0, 0, 0, $fyStart, 1))) ?> (Organization Setup &rarr; Financial Year). A review period is read against the table whose dates hold its last day.</p>
    </fieldset>
    <fieldset class="form-section"><legend>Applies to</legend>
      <div class="row">
<?php foreach (['company' => 'Company', 'branch' => 'Branch', 'department' => 'Department', 'role' => 'Role', 'designation' => 'Designation', 'team' => 'Team'] as $d => $dl): ?>
        <div><label for="pa-scope-<?= $d ?>"><?= $dl ?></label><select id="pa-scope-<?= $d ?>" name="scope_<?= $d ?>" data-org="<?= $d ?>"><option value="">ALL</option><?php foreach ($orgOptions[$d] ?? [] as $o): ?><option><?= e($o) ?></option><?php endforeach; ?></select></div>
<?php endforeach; ?>
        <div><label for="pa-scope-employee">Employee</label><?= employeePicker('pa-scope-employee', 'scope_employee', (array) ($orgOptions['_employee_labels'] ?? []), '', 'ALL', false, '', [], (array) ($orgOptions['_employee_org'] ?? []), true) ?></div>
      </div>
      <p class="note">Each list only offers what belongs with what is already picked (a company's own branches, a branch's own departments, …). Leave a box at ALL to match everyone on that dimension; a more specific box wins over a broader one that also matches the same employee.</p>
    </fieldset>
    <fieldset class="form-section"><legend>Rating format</legend>
      <p class="note" style="margin-top:0">Choose how reviewers rate the people this applies to. The Performance screen shows exactly this scale when their self, manager and final ratings are entered, and Apply reads it back — each company, branch, department … can use a different format.</p>
      <input type="hidden" name="type" id="pa-type" value="numeric"><input type="hidden" name="slab_mode" id="pa-slab-mode" value="exact">
      <div class="pa-formats">
<?php foreach (['labels' => ['Rating labels', 'fa-font', 'Reviewers pick a label you name — S, M, A, R, T; or Outstanding, Good …', 'S → Star performer → 20%'],
    'numbers' => ['Numbers', 'fa-hashtag', 'Reviewers pick one of the numbers you list — 1 to 5, 1 to 10, or any set you like.', '5 → Outstanding → 12%'],
    'slab' => ['Slab (number ranges)', 'fa-sliders', 'Reviewers type any number; it falls into the range it sits in. Decimals allowed.', '4.5 to 5 → Outstanding → 12%']] as $fk => [$fl, $fi, $fd, $fe]): ?>
        <label class="pa-format"><input type="radio" name="format" value="<?= $fk ?>"<?= $fk === 'numbers' ? ' checked' : '' ?> onchange="paModeChange()">
          <span><b><i class="fas <?= $fi ?>"></i> <?= e($fl) ?></b><span class="note"><?= e($fd) ?></span><span class="pa-eg">e.g. <?= e($fe) ?></span></span></label>
<?php endforeach; ?>
      </div>
      <p class="note" id="pa-mode-note"></p>
    </fieldset>
    <fieldset class="form-section"><legend>Slabs</legend>
      <table class="pa-slabs">
        <thead><tr><th class="pa-h-no">#</th><th class="pa-h-rating" id="pa-h-from">Rating</th><th class="pa-h-rating pa-range-only">To</th><th class="pa-h-desc">Description</th><th class="pa-h-pct">% Salary increase</th><th></th></tr></thead>
        <tbody id="pa-slab-rows">
          <tr class="pa-slab-row">
            <td class="pa-c-no">1</td>
            <td class="pa-c-rating"><input name="slabs[0][rating]" class="pa-rating-input" data-f="rating" autocomplete="off" placeholder="e.g. 4"></td>
            <td class="pa-c-rating pa-range-only"><input name="slabs[0][rating_to]" data-f="rating_to" autocomplete="off" inputmode="decimal" placeholder="e.g. 5"></td>
            <td class="pa-c-desc"><input name="slabs[0][description]" data-f="description" maxlength="200" autocomplete="off" placeholder="e.g. Exceeds expectations"></td>
            <td class="pa-c-pct"><div class="pa-pct-wrap"><input name="slabs[0][increment_pct]" data-f="increment_pct" inputmode="decimal" autocomplete="off" placeholder="e.g. 8"><span>%</span></div></td>
            <td class="pa-c-x"><button type="button" class="btn btn-outline btn-sm pa-row-remove" title="Remove this slab">&times;</button></td>
          </tr>
        </tbody>
      </table>
      <p style="margin-top:8px"><button type="button" class="btn btn-outline btn-sm" id="pa-add-slab"><i class="fas fa-plus"></i> Add another slab</button>
        <span class="note">Any number of slabs. Each box is checked as you type; Save stays refused until every row is right. Completely blank rows are ignored.</span></p>
    </fieldset>
    <div style="display:flex;justify-content:flex-end;gap:8px"><button type="button" class="btn btn-outline" onclick="document.getElementById('pa-panel').hidden=true">Cancel</button><button class="btn btn-primary" type="submit"><i class="fas fa-check"></i> Save</button></div>
  </form>
</div>

<?php if ($fys === []): ?>
<div class="card"><p class="note" style="margin:0">No criteria for the current financial year (<?= e($currentFyYear . '-' . substr((string) ($currentFyYear + 1), 2)) ?>) yet. Click <b>+ Add New</b> above to create one<?= $appraisal->fys() !== [] ? ', or open <b>Archive</b> for earlier years' : '' ?>.</p></div>
<?php else: foreach ($fys as $i => $fy): $boxes = $appraisal->boxesFor($fy); $p = PerformanceAppraisal::parseKey($fy); ?>
<div class="card" style="background:<?= ['#eef2ff', '#ecfdf5', '#fff7ed', '#fdf2f8', '#f0f9ff'][$i % 5] ?>;border:0">
  <h2 style="margin-top:0"><?= e(PerformanceAppraisal::label($fy)) ?> <span class="note" style="font-weight:600"><?= e($paRangeText($fy)) ?></span> <span class="badge badge-gray"><?= count($boxes) ?> customisation(s)</span></h2>
<?php foreach ($boxes as $box): ?>
  <div class="card" style="margin:10px 0 0">
    <div class="row" style="justify-content:space-between;align-items:flex-start;flex-wrap:nowrap">
      <div><b><?= e($paScopeSummary((array) $box['scope'])) ?></b> <span class="note">&middot; <?= e(['labels' => 'Rating labels', 'numbers' => 'Numbers', 'slab' => 'Slab (number ranges)'][PerformanceAppraisal::format((array) $box)]) ?><?= isset($box['saved_at']) ? ' · saved ' . e(substr((string) $box['saved_at'], 0, 16)) . ' by ' . e((string) ($box['saved_by'] ?? '')) : '' ?></span></div>
      <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;justify-content:flex-end">
<?php $paCovers = array_values(array_filter($periods, static fn (string $per): bool => PerformanceAppraisal::keyCoversPeriod($fy, $per, $fyStart))); ?>
<?php if ($paCovers !== []): // RATING SAMPLE PER CRITERIA (8 Oct): import it on Increment / Appraisal ?>
      <form method="post" style="display:inline-flex;gap:6px;align-items:center;margin:0" title="Every employee this criteria rates, with a Rating dropdown of its scale. Fill it in and import it on Compensation → Increment / Appraisal.">
        <input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="appraisal-sample">
        <input type="hidden" name="cycle" value="<?= e($fy . '|' . $box['id']) ?>">
        <select name="period" style="padding:4px 6px;width:auto" aria-label="Review period"><?php foreach ($paCovers as $per): ?><option><?= e($per) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline btn-sm" type="submit"><i class="fas fa-file-excel" style="color:#15803d"></i> Rating sample</button></form>
<?php endif; ?>
      <button type="button" class="btn btn-outline btn-sm" data-box='<?= e((string) json_encode(['year' => $p['year'], 'granularity' => $p['granularity'], 'sub' => $p['sub'],
          'boxId' => $box['id'], 'scope' => $box['scope'], 'type' => $box['type'], 'slab_mode' => $box['slab_mode'] ?? 'exact', 'slabs' => $box['slabs']])) ?>' onclick="paOpenEdit(this)"><i class="fas fa-pen"></i> Edit</button>
      </div>
    </div>
    <div style="margin-top:8px"><?= $paSlabTable((array) $box) ?></div>
  </div>
<?php endforeach; ?>
</div>
<?php endforeach; endif; ?>

<div class="card">
  <h2 style="margin-top:0">Apply</h2>
  <?= orgFilterBar('performance-appraisal', $filter, $orgOptions) ?>
<?php if ($fys === []): ?>
  <p class="problem">Nothing to apply for the current financial year yet &mdash; click <b>+ Add New</b> above first.</p>
<?php elseif (!$mayApply): ?>
  <p class="problem">Your role cannot change salaries (<code>employee.salary.update</code>), so Apply is not available to you. Ask an administrator, or grant it under Administration &rarr; Roles &amp; Permissions.</p>
<?php else: ?>
  <form method="post" class="row" style="align-items:flex-end" onsubmit="return confirm('Apply the selected criteria to every employee in view with a finalised rating for the chosen period? This changes CTC.')">
    <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
    <input type="hidden" name="action" value="performance-appraisal">
    <input type="hidden" name="step" value="apply">
<?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <div><label for="pa-apply-fy">Criteria</label>
      <select id="pa-apply-fy" name="fy" onchange="paApplyPeriods()"><?php foreach ($fys as $y): ?><option value="<?= e($y) ?>"><?= e(PerformanceAppraisal::label($y)) ?> — <?= e($paRangeText($y)) ?></option><?php endforeach; ?></select></div>
    <div><label for="pa-apply-period">Review period (final ratings read from here)</label>
      <select id="pa-apply-period" name="period" required><?php foreach ($periods as $per): $pr = PerformanceAppraisal::periodRange($per); ?><option data-covers="<?= e(implode(' ', array_filter($fys, static fn (string $y): bool => PerformanceAppraisal::keyCoversPeriod($y, $per, $fyStart)))) ?>"><?= e($per) ?></option><?php endforeach; ?></select></div>
    <div><label for="pa-apply-eff">Effective date (blank = day after the criteria's dates)</label><input id="pa-apply-eff" type="date" name="effective"></div>
    <div><button class="btn btn-primary" type="submit">Apply to employees in view</button></div>
  </form>
  <p class="note">To rate many employees at once, download a criteria's <b>Rating sample</b> above and import it on Compensation &rarr; <a href="?r=<?= e(moduleRoute('increments')) ?>#appraisal-ratings">Increment / Appraisal</a>.</p>
  <p class="note" id="pa-apply-note">Only review periods that end inside the chosen criteria's dates are offered. Applying twice is safe: an employee already raised for that criteria and period is skipped. Only employees whose scope matches one of its customisations, and who have a finalised rating for the period, are touched.</p>
<?php endif; ?>
</div>

<script>
(function () {
  var FY_START = <?= (int) $fyStart ?>;
  var MONTHS = <?= json_encode(array_map(static fn (int $m): array => ['M' . str_pad((string) $m, 2, '0', STR_PAD_LEFT), date('M', mktime(0, 0, 0, $m, 1))], array_merge(range($fyStart, 12), range(1, $fyStart - 1)))) ?>;
  var MNAME = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  function mn(offset) { return MNAME[(FY_START - 1 + offset) % 12]; }
  var SUB_CONF = {
    half_year: {label: 'Half', options: [['H1', 'H1 (' + mn(0) + '–' + mn(5) + ')'], ['H2', 'H2 (' + mn(6) + '–' + mn(11) + ')']]},
    quarter: {label: 'Quarter', options: [0, 1, 2, 3].map(function (q) { return ['Q' + (q + 1), 'Q' + (q + 1) + ' (' + mn(3 * q) + '–' + mn(3 * q + 2) + ')']; })},
    month: {label: 'Month', options: MONTHS}
  };
  var form = document.getElementById('pa-form');
  var subSelect = document.getElementById('pa-sub-select');
  subSelect.addEventListener('change', function () { document.getElementById('pa-sub-value').value = this.value; paRange(); });
  document.getElementById('pa-year').addEventListener('input', paRange);

  // THE DATES THE CHOSEN KEY STANDS FOR — same arithmetic as PerformanceAppraisal::range().
  function paRange() {
    var y = parseInt(document.getElementById('pa-year').value, 10), g = document.getElementById('pa-gran').value, sub = subSelect.value;
    var note = document.getElementById('pa-range-note');
    if (!(y >= 2000 && y <= 2099)) { note.textContent = ''; return; }
    var offset = 0, months = 12;
    if (g === 'half_year') { offset = sub === 'H2' ? 6 : 0; months = 6; }
    if (g === 'quarter') { offset = 3 * (parseInt(sub.slice(1), 10) - 1); months = 3; }
    if (g === 'month') { offset = (parseInt(sub.slice(1), 10) - FY_START + 12) % 12; months = 1; }
    var from = new Date(y, FY_START - 1 + offset, 1), to = new Date(y, FY_START - 1 + offset + months, 0);
    var f = function (d) { return ('0' + d.getDate()).slice(-2) + ' ' + MNAME[d.getMonth()] + ' ' + d.getFullYear(); };
    note.textContent = 'Covers ' + f(from) + ' – ' + f(to) + (g === 'yearly' ? ' (financial year ' + y + '-' + String(y + 1).slice(2) + ')' : '');
  }

  window.paGranChange = function () {
    var g = document.getElementById('pa-gran').value;
    var wrap = document.getElementById('pa-sub-wrap');

    if (g === 'yearly' || !SUB_CONF[g]) {
      wrap.hidden = true;
      document.getElementById('pa-sub-value').value = '';
      paRange();

      return;
    }

    wrap.hidden = false;
    document.getElementById('pa-sub-label').textContent = SUB_CONF[g].label;
    subSelect.innerHTML = '';
    SUB_CONF[g].options.forEach(function (o) {
      var opt = document.createElement('option');
      opt.value = o[0];
      opt.textContent = o[1];
      subSelect.appendChild(opt);
    });
    document.getElementById('pa-sub-value').value = subSelect.value;
    paRange();
  };

  // TYPE × SLAB: a custom-label box is always one label per slab.
  function fmt() { var r = form.querySelector('input[name=format]:checked'); return r ? r.value : 'numbers'; }
  function isRange() { return fmt() === 'slab'; }
  function isCustom() { return fmt() === 'labels'; }
  function setFormat(f) { var r = form.querySelector('input[name=format][value="' + f + '"]'); if (r) { r.checked = true; } }
  window.paModeChange = function () {
    var custom = isCustom(), range = isRange();
    document.getElementById('pa-type').value = custom ? 'custom' : 'numeric';
    document.getElementById('pa-slab-mode').value = range ? 'range' : 'exact';
    document.querySelectorAll('.pa-range-only').forEach(function (el) { el.hidden = !range; });
    document.getElementById('pa-h-from').textContent = custom ? 'Rating label' : (range ? 'From' : 'Number');
    document.querySelectorAll('.pa-rating-input').forEach(function (el) {
      el.placeholder = custom ? 'e.g. S' : (range ? 'e.g. 4.5' : 'e.g. 5');
      el.inputMode = custom ? 'text' : 'decimal';
    });
    document.getElementById('pa-mode-note').textContent = custom
      ? 'Reviewers will choose from these labels. Put the best first; each label once.'
      : (range ? 'Reviewers will type a number; each row covers "from" to "to", both included. Ranges may not overlap.'
               : 'Reviewers will choose from these numbers; each number once.');
    rows.querySelectorAll('.pa-slab-row').forEach(function (r) { r.querySelectorAll('input[data-f=rating_to]').forEach(function (i) { i.disabled = !range; }); });
    checkAll();
  };

  // FIELD-LEVEL VALIDATION — every box is judged as it is typed (the layout's
  // live painter shows the message under it) and the browser refuses Save
  // while any box holds a message. A message the server sent back is kept on
  // its box until that box is edited.
  var DEC = /^\d+(\.\d+)?$/;
  function pctOf(v) { var m = String(v).trim().match(/^(\d+(?:\.\d+)?)\s*%?$/); return m ? m[1] : null; }
  function rowMessages(row) {
    var get = function (f) { var el = row.querySelector('[data-f=' + f + ']'); return el ? el.value.trim() : ''; };
    var from = get('rating'), to = isRange() ? get('rating_to') : '', desc = get('description'), pct = get('increment_pct');
    var m = {};
    if (from === '' && to === '' && desc === '' && pct === '') { return m; }
    if (from === '') { m.rating = isRange() ? 'Enter the lowest rating of this slab.' : 'Enter the rating.'; }
    else if (!isCustom() && !DEC.test(from)) { m.rating = '"' + from + '" is not a number. Switch the type to Custom labels for text ratings.'; }
    else if (isCustom() && from.length > 40) { m.rating = 'A rating label is 40 characters at most.'; }
    if (isRange()) {
      if (to === '') { m.rating_to = 'Enter the highest rating of this slab.'; }
      else if (!DEC.test(to)) { m.rating_to = '"' + to + '" is not a number.'; }
      else if (!m.rating && parseFloat(to) < parseFloat(from)) { m.rating_to = 'The "to" rating cannot be below the "from" rating.'; }
    }
    if (pct === '') { m.increment_pct = 'Enter the % salary increase (0 for none).'; }
    else if (pctOf(pct) === null) { m.increment_pct = '"' + pct + '" is not a percentage — type a number such as 8 or 12.5.'; }
    else if (parseFloat(pctOf(pct)) > 1000) { m.increment_pct = 'That is more than a 1000% increase — check the number.'; }
    return m;
  }
  function checkAll() {
    var all = Array.prototype.slice.call(rows.querySelectorAll('.pa-slab-row')), seen = {}, spans = [];
    all.forEach(function (row, i) {
      row.querySelector('.pa-c-no').textContent = i + 1;
      var m = rowMessages(row);
      var from = (row.querySelector('[data-f=rating]').value || '').trim();
      if (!m.rating && !m.rating_to && from !== '') {
        if (isRange()) {
          var to = row.querySelector('[data-f=rating_to]').value.trim(), f = parseFloat(from), t = parseFloat(to);
          spans.forEach(function (s) { if (f <= s.t && t >= s.f) { m.rating = 'This range overlaps row ' + (s.i + 1) + '.'; } });
          spans.push({f: f, t: t, i: i});
        } else {
          var key = isCustom() ? from.toLowerCase() : String(parseFloat(from));
          if (seen[key] !== undefined) { m.rating = 'Rating "' + from + '" is already in row ' + (seen[key] + 1) + '.'; }
          else { seen[key] = i; }
        }
      }
      row.querySelectorAll('input[data-f]').forEach(function (el) {
        var msg = el.dataset.serverError || m[el.dataset.f] || '';
        if (el.validationMessage !== msg) { el.setCustomValidity(msg); }
      });
    });
    var anyFilled = all.some(function (r) {
      return Array.prototype.some.call(r.querySelectorAll('input[data-f]'), function (i) { return !i.disabled && i.value.trim() !== ''; });
    });
    var firstRating = rows.querySelector('input[data-f=rating]');
    if (!anyFilled) { firstRating.setCustomValidity('Enter at least one slab.'); }
  }
  form.addEventListener('input', function (ev) {
    if (ev.target.dataset && ev.target.dataset.serverError) { delete ev.target.dataset.serverError; }
    if (ev.target.closest && ev.target.closest('#pa-slab-rows')) { checkAll(); paintRows(); }
  });
  // Re-paint every slab box after a re-check — fixing row 3 can clear row 1's "already in row 3" —
  // but never paint an untouched empty box red just because a neighbour was typed in.
  function paintRows() {
    rows.querySelectorAll('input[data-f]').forEach(function (el) {
      // The box being typed in is repainted too: the layout's painter ran
      // BEFORE this re-check on the same keystroke, so its note is one step stale.
      var noted = el.nextElementSibling && el.nextElementSibling.classList.contains('field-live-error');
      if (el.value !== '' || el.validationMessage !== '' || noted) { el.dispatchEvent(new Event('focusout', {bubbles: true})); }
    });
  }
  // The browser itself refuses Save while any box above holds a message
  // (and the layout paints each one); this only covers a message set by a
  // change the person never typed into (a row removed, the type switched).
  form.addEventListener('submit', function (ev) {
    checkAll();
    if (!form.checkValidity()) { ev.preventDefault(); form.reportValidity(); paintRows(); }
  });

  // SLAB ROWS: "Add another slab" clones the template row and renumbers
  // slabs[0][…] -> slabs[N][…]; remove never drops the last row.
  var rows = document.getElementById('pa-slab-rows');
  var n = 1;
  function addRow() {
    var clone = rows.querySelector('.pa-slab-row').cloneNode(true);
    clone.querySelectorAll('[name]').forEach(function (el) {
      el.name = el.name.replace(/slabs\[\d+\]/, 'slabs[' + n + ']');
      el.value = '';
      el.setCustomValidity('');
      delete el.dataset.serverError;
      el.style.borderColor = '';
      el.style.background = '';
    });
    clone.querySelectorAll('.field-live-error').forEach(function (x) { x.remove(); });
    rows.appendChild(clone);
    n++;
    return clone;
  }
  document.getElementById('pa-add-slab').addEventListener('click', function () { addRow().querySelector('input').focus(); checkAll(); });
  rows.addEventListener('click', function (e) {
    var btn = e.target.closest('.pa-row-remove');
    if (btn && rows.querySelectorAll('.pa-slab-row').length > 1) { btn.closest('.pa-slab-row').remove(); checkAll(); paintRows(); }
  });

  function resetSlabRows(slabs) {
    Array.prototype.slice.call(rows.querySelectorAll('.pa-slab-row'), 1).forEach(function (r) { r.remove(); });
    n = 1;
    var first = rows.querySelector('.pa-slab-row');
    first.querySelectorAll('input[data-f]').forEach(function (el) { el.value = ''; el.setCustomValidity(''); delete el.dataset.serverError; el.style.borderColor = ''; el.style.background = ''; });
    first.querySelectorAll('.field-live-error').forEach(function (x) { x.remove(); });
    var list = (slabs && slabs.length) ? slabs : [{}];
    list.forEach(function (slab, i) {
      var row = i === 0 ? first : addRow();
      ['rating', 'rating_to', 'description', 'increment_pct'].forEach(function (f) {
        var el = row.querySelector('[data-f=' + f + ']');
        if (el) { el.value = slab[f] || ''; }
      });
    });
    paModeChange();
  }

  function setScope(scope) {
    ['company', 'branch', 'department', 'role', 'designation', 'team'].forEach(function (d) {
      document.getElementById('pa-scope-' + d).value = (scope && scope[d]) || '';
    });
    window.sprsOrgRefresh && window.sprsOrgRefresh(form);
    window.sprsPickerSet && window.sprsPickerSet('pa-scope-employee', (scope && scope.employee) || '');
  }

  function fill(data) {
    document.getElementById('pa-box-id').value = data.boxId || '';
    document.getElementById('pa-year').value = data.year || <?= (int) $currentFyYear ?>;
    document.getElementById('pa-gran').value = data.granularity || 'yearly';
    paGranChange();
    if (data.sub) {
      subSelect.value = data.sub;
      document.getElementById('pa-sub-value').value = data.sub;
    }
    paRange();
    setScope(data.scope || {});
    setFormat(data.format || (data.type === 'custom' ? 'labels' : (data.slab_mode === 'range' ? 'slab' : 'numbers')));
    resetSlabRows(data.slabs || []);
  }

  function open(title) {
    document.getElementById('pa-panel-title').textContent = title;
    document.getElementById('pa-panel').hidden = false;
    document.getElementById('pa-panel').scrollIntoView({behavior: 'smooth', block: 'start'});
  }

  window.paOpenNew = function () { fill({}); open('Add a Performance Appraisal customisation'); };

  window.paOpenEdit = function (btn) {
    fill(JSON.parse(btn.getAttribute('data-box')));
    open('Edit this customisation');
  };

  // THE APPLY FORM only offers review periods that end inside the chosen criteria's dates.
  window.paApplyPeriods = function () {
    var fySel = document.getElementById('pa-apply-fy'), perSel = document.getElementById('pa-apply-period');
    if (!fySel || !perSel) { return; }
    var fy = fySel.value, firstOk = null;
    Array.prototype.forEach.call(perSel.options, function (o) {
      var ok = (' ' + (o.getAttribute('data-covers') || '') + ' ').indexOf(' ' + fy + ' ') >= 0;
      o.hidden = !ok; o.disabled = !ok;
      if (ok && firstOk === null) { firstOk = o; }
    });
    if (perSel.selectedOptions[0] && perSel.selectedOptions[0].disabled) { perSel.value = firstOk ? firstOk.value : ''; }
    var note = document.getElementById('pa-apply-note');
    if (note && firstOk === null) { note.innerHTML = '<b>No Performance review period ends inside these criteria\'s dates yet.</b> ' + note.innerHTML.replace(/^<b>.*?<\/b> /, ''); }
  };
  paApplyPeriods();

  paGranChange();
  paModeChange();

<?php if (is_array($paOld)): $po = (array) ($paOld['post'] ?? []); ?>
  // A REFUSED SAVE COMES BACK FILLED IN — every row as typed, each box the
  // server named marked with its own message. After DOMContentLoaded: the
  // layout's cascade, picker and live-painter scripts come later in the page.
  document.addEventListener('DOMContentLoaded', function () {
    var old = <?= json_encode([
        'boxId' => (string) ($po['box_id'] ?? ''), 'year' => (int) ($po['year'] ?? $currentFyYear), 'granularity' => (string) ($po['granularity'] ?? 'yearly'),
        'sub' => (string) ($po['sub'] ?? ''), 'format' => (string) ($po['format'] ?? ''), 'type' => (string) ($po['type'] ?? 'numeric'), 'slab_mode' => (string) ($po['slab_mode'] ?? 'exact'),
        'scope' => array_filter(array_map('strval', array_combine(PerformanceAppraisal::SCOPE_DIMENSIONS, array_map(static fn (string $d): string => (string) ($po['scope_' . $d] ?? ''), PerformanceAppraisal::SCOPE_DIMENSIONS)))),
        'slabs' => array_values(array_map(static fn ($r): array => array_map('strval', array_intersect_key((array) $r, array_flip(['rating', 'rating_to', 'description', 'increment_pct']))), (array) ($po['slabs'] ?? []))),
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var errors = <?= json_encode((object) ($paOld['errors'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    fill(old);
    open(old.boxId ? 'Edit this customisation' : 'Add a Performance Appraisal customisation');
    var list = rows.querySelectorAll('.pa-slab-row');
    Object.keys(errors).forEach(function (k) {
      var row = list[+k]; // the server counts rows exactly as the form posted them, blanks included
      if (!row) { return; }
      Object.keys(errors[k]).forEach(function (f) {
        var el = row.querySelector('[data-f=' + f + ']');
        if (el) { el.dataset.serverError = errors[k][f]; }
      });
    });
    checkAll();
    rows.querySelectorAll('input[data-f]').forEach(function (el) { if (el.validationMessage) { el.dispatchEvent(new Event('focusout', {bubbles: true})); } });
  });
<?php endif; ?>
})();
</script>
