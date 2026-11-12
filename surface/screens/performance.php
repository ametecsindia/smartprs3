<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Performance</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Performance register (employee, cycle, 1-5 rating, reviewer,
// status) grown into a review: self rating, manager rating, final rating and
// the PRG band, per employee per period. The scale, periods, statuses and
// bands are config/performance.json; the reviewer is the reporting manager on
// the employee record. Writes go through surface/actions/performance-review.php.
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Performance;
use SmartPRS\Surface\PerformanceAppraisal;

$perf = new Performance(__DIR__ . '/../../config/performance.json');
$cfg = $perf->config();
$scale = $perf->scale();
$statuses = (array) ($cfg['statuses'] ?? []);
$canEdit = $may('employee.update');
$mayOthers = $may('employee.list');
$allPeople = [];

foreach ($scope->only(liveRows($store, 'employees'), $may('employee.list')) as $e) {
    $allPeople[(string) $e['id']] = $e;
}

$people = $scope->only(array_values($allPeople), $mayOthers);
$filter = orgFilter();
$inView = [];

foreach (orgFilterApply($store, $people, $filter) as $e) {
    $inView[(string) $e['id']] = $e;
}

$periods = $perf->periods(date('Y-m-d'));
$period = in_array((string) ($_GET['period'] ?? ''), $periods, true) ? (string) $_GET['period'] : ($periods[0] ?? '');
$own = $scope->own();
$reviews = [];

foreach ($store->all(Performance::TABLE) as $r) {
    if ((string) ($r['cycle'] ?? '') === $period && isset($inView[(string) ($r['employee_id'] ?? '')])) {
        $reviews[(string) $r['employee_id']] = $r;
    }
}

$openId = (string) ($_GET['emp'] ?? '');
$openEmp = $inView[$openId] ?? null;
$openReview = $reviews[$openId] ?? null;
$isSelf = $openEmp !== null && $own !== null && (string) $own === $openId;
$reviewer = $openEmp === null ? null : Performance::reviewerOf($openEmp, $allPeople);
$isReviewer = $reviewer !== null && $own !== null && $reviewer['id'] !== '' && (string) $own === $reviewer['id'];
$finalised = $openReview !== null && (string) ($openReview['status'] ?? '') === 'finalised';
// A RATING AS GIVEN: the tenant's own scale keeps it in `<col>_value` (a
// label "S", a number, a point in a slab "4.6"); older reviews and the shipped
// 1–5 scale have only the integer column.
$ratingOf = static fn (?array $r, string $col): ?string => $r === null ? null
    : (trim((string) ($r[$col . '_value'] ?? '')) !== '' ? (string) $r[$col . '_value'] : (trim((string) ($r[$col] ?? '')) !== '' ? (string) (int) $r[$col] : null));
// A finalised review shows what was signed; an open one shows what the config would take as final today.
$finalOf = static fn (?array $r): array => $r !== null && (string) ($r['status'] ?? '') === 'finalised'
    ? ['rating' => $ratingOf($r, 'final_rating'), 'working' => 'finalised as ' . ($ratingOf($r, 'final_rating') ?? '—') . ' by ' . (string) ($r['finalised_by'] ?? '') . ' on ' . substr((string) ($r['finalised_at'] ?? ''), 0, 10) . ' (self ' . ($ratingOf($r, 'self_rating') ?? '—') . ', manager ' . ($ratingOf($r, 'manager_rating') ?? '—') . ')']
    : $perf->finalRating($ratingOf($r, 'self_rating'), $ratingOf($r, 'manager_rating'), null);
$keep = ['period' => $period];
// THE RECOMMENDED INCREMENT COMES FROM THE TENANT'S OWN APPRAISAL CRITERIA
// (Organization Setup → Performance Appraisal) for the financial year this
// period ends in and the most specific box covering the employee — the same
// answer Apply acts on. Only when no criterion covers them does the shipped
// PRG band in config/performance.json show, and the screen says which.
$paAppraisal = new PerformanceAppraisal($customisation);
$paFyStart = financialYearStart($customisation);
$paOrgOf = static fn (array $e): array => ['role' => orgValueOf($store, $e, 'role'), 'employee' => orgValueOf($store, $e, 'employee')] + orgEnrich($store, $e);
$incrementFor = static function (array $e, int|string|null $rating, ?array $review = null) use ($paAppraisal, $paFyStart, $period, $scale, $perf, $paOrgOf): array {
    if ($rating === null || (string) $rating === '') {
        return ['pct' => null, 'why' => ''];
    }

    $rating = (string) $rating;
    $hint = $review !== null && trim((string) ($review['appraisal_box_id'] ?? '')) !== ''
        ? ['fy' => (string) $review['appraisal_key'], 'id' => (string) $review['appraisal_box_id']] : null;
    $hit = $paAppraisal->resolve($period, $paOrgOf($e), $rating, (string) ($scale[(int) $rating] ?? ''), $paFyStart, $hint);

    if ($hit !== null) {
        return ['pct' => $hit['pct'], 'why' => 'Performance Appraisal ' . PerformanceAppraisal::label($hit['fy'])
            . ($hit['description'] !== '' ? ' — ' . $hit['description'] : '')];
    }

    return ['pct' => preg_match('/^\d+$/', $rating) === 1 ? $perf->prg((int) $rating) : null,
        'why' => 'shipped PRG band (no appraisal criterion covers this employee for ' . $period . ')'];
};

// THE SCALE A REVIEW IS RATED ON — the same rule the save action applies:
// pinned to the box it was first rated on; else, while nobody has rated it,
// the most specific Performance Appraisal box covering the employee for the
// financial year this period ends in; else the shipped 1–5 scale.
$scaleFor = static function (array $e, ?array $review) use ($paAppraisal, $paFyStart, $period, $paOrgOf, $ratingOf): ?array {
    if ($review !== null && trim((string) ($review['appraisal_box_id'] ?? '')) !== '') {
        $b = $paAppraisal->box((string) $review['appraisal_key'], (string) $review['appraisal_box_id']);

        return $b === null ? null : ['fy' => (string) $review['appraisal_key'], 'box' => $b, 'format' => PerformanceAppraisal::format($b)];
    }

    if ($ratingOf($review, 'self_rating') !== null || $ratingOf($review, 'manager_rating') !== null) {
        return null; // rated before any criterion covered them: stays on the shipped scale
    }

    return $paAppraisal->scaleFor($period, $paOrgOf($e), $paFyStart);
};

// ONE RATING CONTROL FOR EVERY STEP, shaped by the scale: a list of the
// labels or numbers the criteria set up (with their descriptions), or — for
// slab ranges — a number box that only accepts a point inside one of them.
$ratingControl = static function (?array $sc, string $id, string $name, ?string $current, bool $required, string $blank) use ($scale): string {
    $req = $required ? ' required' : '';

    if ($sc === null) {
        $h = '<select id="' . e($id) . '" name="' . e($name) . '"' . $req . '><option value="">' . e($blank) . '</option>';
        foreach ($scale as $v => $l) {
            $h .= '<option value="' . $v . '"' . ((string) $v === $current ? ' selected' : '') . '>' . $v . ' — ' . e($l) . '</option>';
        }

        return $h . '</select>';
    }

    $slabs = (array) ($sc['box']['slabs'] ?? []);

    if ($sc['format'] === 'slab') {
        $ranges = array_map(static fn (array $s): array => [(string) $s['rating'], (string) ($s['rating_to'] ?? '')], $slabs);
        $hint = implode(' · ', array_map(static fn (array $s): string => $s['rating'] . '–' . ($s['rating_to'] ?? '') . (trim((string) ($s['description'] ?? '')) !== '' ? ' ' . $s['description'] : ''), $slabs));

        return '<input id="' . e($id) . '" name="' . e($name) . '" value="' . e((string) $current) . '" inputmode="decimal" autocomplete="off"' . $req
            . ' placeholder="' . e($blank === '—' ? 'e.g. ' . ($slabs[0]['rating'] ?? '') : $blank) . '" data-slab-ranges=\'' . e((string) json_encode($ranges)) . '\' style="width:9rem">'
            . '<div class="note" style="margin-top:4px">' . e($hint) . '</div>';
    }

    $h = '<select id="' . e($id) . '" name="' . e($name) . '"' . $req . '><option value="">' . e($blank) . '</option>';
    foreach ($slabs as $s) {
        $v = (string) $s['rating'];
        $h .= '<option value="' . e($v) . '"' . ($current !== null && strcasecmp($v, $current) === 0 ? ' selected' : '') . '>' . e($v)
            . (trim((string) ($s['description'] ?? '')) !== '' ? ' — ' . e((string) $s['description']) : '') . '</option>';
    }

    return $h . '</select>';
};
$scaleText = static function (?array $sc) use ($scale): string {
    if ($sc === null) {
        return 'Shipped scale ' . implode(' / ', array_map(static fn (int $v, string $l): string => $v . ' ' . $l, array_keys($scale), $scale))
            . ' — no Performance Appraisal criterion covers this employee for this period.';
    }

    $scopeBits = [];
    foreach ((array) ($sc['box']['scope'] ?? []) as $k => $v) { $scopeBits[] = ucfirst($k) . ': ' . $v; }

    return PerformanceAppraisal::describeScale($sc['box']) . ' — from Performance Appraisal ' . PerformanceAppraisal::label($sc['fy'])
        . ', ' . ($scopeBits === [] ? 'all employees' : implode(' · ', $scopeBits)) . '.';
};
$descOf = static function (?array $sc, ?string $rating) use ($scale): string {
    if ($rating === null) { return ''; }
    if ($sc === null) { return (string) ($scale[(int) $rating] ?? ''); }
    $slab = PerformanceAppraisal::slabForValue($sc['box'], $rating);

    return (string) ($slab['description'] ?? '');
};
?>
<div class="page-header">
  <div><h2>Performance</h2><p>One review per employee per period: a self rating, the reporting manager's rating, and the final rating HR signs off. Each employee is rated on the scale Organization Setup &rarr; <a href="?r=performance-appraisal">Performance Appraisal</a> sets for their company, branch, department &hellip; for the financial year the period ends in &mdash; rating labels, numbers or slab ranges. Anyone no criterion covers uses the shipped scale <?= e(implode(' / ', array_map(static fn (int $v, string $l): string => $v . ' ' . $l, array_keys($scale), $scale))) ?>.</p></div>
</div>
<?php // ONE FORM (owner, 13 Oct): this screen's own choice sits in the filter bar — one Show, no second form. ?>
<?= orgFilterBar('performance', $filter, orgFilterOptions($store, $people), array_diff_key($keep, ['period' => 1]), 'Show', null, null, null,
    '<div><label for="period">Period</label><select id="period" name="period">' . implode('', array_map(static fn ($p): string => '<option' . ($p === $period ? ' selected' : '') . '>' . e((string) $p) . '</option>', $periods)) . '</select></div>') ?>
<?php if ($openEmp !== null): ?>
<div class="card">
  <h2>Review — <?= e(Employment::label($openEmp)) ?> · <?= e($period) ?></h2>
  <p class="note">Reviewer: <b><?= e($reviewer['label']) ?></b> (the reporting manager on the employee record<?= $reviewer['id'] === '' ? ' — none linked, so only HR can enter the manager rating' : '' ?>). Status: <span class="badge badge-<?= $finalised ? 'green' : 'amber' ?>"><?= e((string) ($statuses[(string) ($openReview['status'] ?? 'draft')] ?? ($openReview['status'] ?? 'Draft'))) ?></span></p>
<?php $final = $finalOf($openReview); $sc = $scaleFor($openEmp, $openReview); ?>
  <p class="note" style="background:#eff6ff;border-left:3px solid #1e3a5f;padding:8px 10px;margin:0 0 12px"><b>Rating scale:</b> <?= e($scaleText($sc)) ?></p>
  <div class="row" style="gap:26px;margin-bottom:12px">
    <div><span class="note">Self rating</span><br><b style="font-size:22px"><?= e((string) ($ratingOf($openReview, 'self_rating') ?? '—')) ?></b></div>
    <div><span class="note">Manager rating</span><br><b style="font-size:22px"><?= e((string) ($ratingOf($openReview, 'manager_rating') ?? '—')) ?></b></div>
    <div><span class="note">Final rating</span><br><b style="font-size:22px"><?= e((string) ($final['rating'] ?? '—')) ?></b> <span class="note"><?= e($descOf($sc, $final['rating'] === null ? null : (string) $final['rating'])) ?></span></div>
<?php $inc = $incrementFor($openEmp, $final['rating'], $openReview); ?>
    <div><span class="note">Recommended increment</span><br><b style="font-size:22px"><?= $inc['pct'] === null ? '—' : e($inc['pct']) . '%' ?></b> <span class="note"><?= e($inc['why']) ?></span></div>
  </div>
  <p class="note">Working: <?= e($final['working']) ?>. The recommended increment never changes pay by itself — Organization Setup → Performance Appraisal → Apply does.</p>
<?php if (!$finalised && ($isSelf || $canEdit)): ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="performance-review"><input type="hidden" name="step" value="self"><input type="hidden" name="employee_id" value="<?= e($openId) ?>"><input type="hidden" name="period" value="<?= e($period) ?>"><?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <fieldset class="form-section"><legend>Self-assessment<?= $isSelf ? '' : ' (entered by HR on the employee\'s behalf)' ?></legend>
      <div class="row"><div><label for="self_rating">Self rating</label><?= $ratingControl($sc, 'self_rating', 'self_rating', $ratingOf($openReview, 'self_rating'), true, '—') ?></div>
      <div style="flex:1"><label for="self_comment">Comment</label><textarea id="self_comment" name="self_comment" rows="2"><?= e((string) ($openReview['self_comment'] ?? '')) ?></textarea></div></div>
      <button class="btn btn-outline" type="submit">Save self-assessment</button>
    </fieldset></form>
<?php endif; ?>
<?php if (!$finalised && ($isReviewer || $canEdit)): ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="performance-review"><input type="hidden" name="step" value="manager"><input type="hidden" name="employee_id" value="<?= e($openId) ?>"><input type="hidden" name="period" value="<?= e($period) ?>"><?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <fieldset class="form-section"><legend>Manager rating<?= $isReviewer ? '' : ' (entered by HR)' ?></legend>
      <div class="row"><div><label for="manager_rating">Manager rating</label><?= $ratingControl($sc, 'manager_rating', 'manager_rating', $ratingOf($openReview, 'manager_rating'), true, '—') ?></div>
      <div style="flex:1"><label for="manager_comment">Comment</label><textarea id="manager_comment" name="manager_comment" rows="2"><?= e((string) ($openReview['manager_comment'] ?? '')) ?></textarea></div></div>
      <button class="btn btn-outline" type="submit">Save manager rating</button>
    </fieldset></form>
<?php endif; ?>
<?php $bothRatingsIn = $ratingOf($openReview, 'self_rating') !== null && $ratingOf($openReview, 'manager_rating') !== null; ?>
<?php if (!$finalised && $canEdit && $bothRatingsIn): ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="performance-review"><input type="hidden" name="step" value="finalise"><input type="hidden" name="employee_id" value="<?= e($openId) ?>"><input type="hidden" name="period" value="<?= e($period) ?>"><?php foreach ($filter as $k => $v): ?><input type="hidden" name="f_<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <fieldset class="form-section"><legend>Finalise (HR)</legend>
      <div class="row"><div><label for="final_rating">Final rating</label><?= $ratingControl($sc, 'final_rating', 'final_rating', null, false, 'Default (' . $final['working'] . ')') ?><span class="note">Leave blank to take the default; pick or type a rating to override it.</span></div></div>
      <button class="btn btn-primary" type="submit">Finalise review</button> <span class="note">A finalised review is closed; a correction is a new period, never an edit.</span>
    </fieldset></form>
<?php elseif (!$finalised && $canEdit): ?>
  <p class="note">Finalising is unavailable until both the self-assessment and the manager rating are in — an HR override changes which rating counts, not whether both were given.</p>
<?php endif; ?>
  <p><a class="btn btn-outline btn-sm" href="?r=performance&amp;period=<?= e($period) ?><?= orgFilterQuery($filter) ?>">Back to the list</a></p>
</div>
<?php endif; ?>
<div class="card">
  <h2><?= e($period) ?> <span class="badge badge-gray"><?= count($inView) ?> in view</span> <span class="badge badge-green"><?= count(array_filter($reviews, static fn (array $r): bool => (string) ($r['status'] ?? '') === 'finalised')) ?> finalised</span></h2>
<?php if ($inView === []): ?>
  <p class="note">No employees in view.</p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Code</th><th>Employee</th><th>Department</th><th>Reviewer</th><th class="n">Self</th><th class="n">Manager</th><th class="n">Final</th><th class="n">Increment</th><th>Status</th><th></th></tr></thead>
    <tbody>
<?php foreach ($inView as $id => $e): $r = $reviews[$id] ?? null; $rv = Performance::reviewerOf($e, $allPeople);
      $f = $finalOf($r); ?>
      <tr>
        <td><b><?= e(Employment::code($e)) ?></b></td>
        <td><?= e((string) ($e['name'] ?? '')) ?></td>
        <td class="note"><?= e(orgValueOf($store, $e, 'department')) ?></td>
        <td class="note"><?= e($rv['label']) ?></td>
        <td class="n"><?= e((string) ($ratingOf($r, 'self_rating') ?? '—')) ?></td>
        <td class="n"><?= e((string) ($ratingOf($r, 'manager_rating') ?? '—')) ?></td>
        <td class="n"><b><?= e((string) ($f['rating'] ?? '—')) ?></b></td>
<?php $inc = $incrementFor($e, $f['rating'], $r); ?>
        <td class="n note" title="<?= e($inc['why']) ?>"><?= $inc['pct'] === null ? '—' : e($inc['pct']) . '%' ?></td>
        <td><span class="badge badge-<?= $r === null ? 'gray' : ((string) ($r['status'] ?? '') === 'finalised' ? 'green' : 'amber') ?>"><?= e($r === null ? 'not started' : (string) ($statuses[(string) ($r['status'] ?? '')] ?? (string) $r['status'])) ?></span></td>
        <td><a class="btn btn-outline btn-sm" href="?r=performance&amp;period=<?= e($period) ?>&amp;emp=<?= e((string) $id) ?><?= orgFilterQuery($filter) ?>">Open</a></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>

<script>
// A SLAB-RANGE RATING BOX refuses a number outside every range as it is typed
// (the layout paints the message); the save action checks the same thing.
document.querySelectorAll('input[data-slab-ranges]').forEach(function (el) {
  var ranges = JSON.parse(el.getAttribute('data-slab-ranges') || '[]');
  function check() {
    var v = el.value.trim(), msg = '';
    if (v !== '') {
      if (!/^\d+(\.\d{1,2})?$/.test(v)) { msg = 'Type a number such as 4 or 4.5.'; }
      else if (!ranges.some(function (r) { return +v >= +r[0] && +v <= +r[1]; })) { msg = 'Not inside any slab: ' + ranges.map(function (r) { return r[0] + '–' + r[1]; }).join(', ') + '.'; }
    }
    el.setCustomValidity(msg);
  }
  el.addEventListener('input', check, true);
  check();
});
</script>
