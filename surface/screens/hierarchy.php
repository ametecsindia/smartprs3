<?php declare(strict_types=1);
// PEOPLE → HIERARCHY (owner, 12 Oct): the reporting tree of each company, top down — photo, name,
// designation — filtered by Company, Branch and Department (none = every company, side by side).
// The tree is the Reporting Manager on each employee record; nothing else decides it. Each person
// sits in THEIR OWN company's chart; someone whose manager is in another company (or is blank, or is
// outside the selection) is a top of their company's chart, and the card says who they report to.
// People with nobody under them are folded into a "N team members" chip under their manager and open
// into a wrapped grid, so a branch of 26 field executives does not push the page sideways.
use SmartPRS\Surface\Employment;

if (!$may('employee.directory.view')): ?>
<div class="page-header"><div><h2>Hierarchy</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.directory.view')->summary) ?></div></div>
<?php return; endif;

$hyKeys = ['company', 'branch', 'department'];
$hyFilter = array_fill_keys(array_keys(ORG_FILTERS), '');
foreach ($hyKeys as $k) {
    $v = trim((string) ($_GET['f_' . $k] ?? ''));
    $hyFilter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}
$hyScoped = array_values(array_filter($scope->only(liveRows($store, 'employees'), $may('employee.list')),
    static fn (array $e): bool => in_array(strtolower((string) ($e['status'] ?? 'active')), ['', 'active'], true)));
$hyOptions = orgFilterOptions($store, $hyScoped);
// DEMO DEFAULT (owner, 23 Oct — testing / demo): first visit opens on Ametecs. Choosing ALL in Company
// (f_company=ALL) still shows every company.
if (!array_key_exists('f_company', $_GET)) {
    foreach ((array) ($hyOptions['company'] ?? []) as $hyO) {
        if (stripos((string) $hyO, 'ametecs') !== false) { $hyFilter['company'] = (string) $hyO; break; }
    }
}
$hyPeople = orgFilterApply($store, $hyScoped, $hyFilter);
$hyAll = [];
foreach ($hyScoped as $e) { $hyAll[(string) $e['id']] = $e; }
$hyById = [];
$hyCo = [];
foreach ($hyPeople as $e) {
    $hyById[(string) $e['id']] = $e;
    $hyCo[(string) $e['id']] = orgValueOf($store, $e, 'company') ?: 'No company on the record';
}

// THE TREE, PER COMPANY: a manager counts only inside the person's own company and the selection.
$hyKids = [];
$hyTops = [];
foreach ($hyPeople as $e) {
    $id = (string) $e['id'];
    $m = (string) ($e['reporting_manager_id'] ?? '');
    ($m !== '' && $m !== $id && isset($hyById[$m]) && $hyCo[$m] === $hyCo[$id]) ? $hyKids[$m][] = $id : $hyTops[$hyCo[$id]][] = $id;
}
// A REPORTING LOOP (A reports to B, B to A) has no top; anyone not reached from a top becomes one.
$hyReached = [];
$hyWalk = static function (string $id) use (&$hyWalk, &$hyReached, $hyKids): void {
    if (isset($hyReached[$id])) { return; }
    $hyReached[$id] = true;
    foreach ($hyKids[$id] ?? [] as $k) { $hyWalk($k); }
};
foreach ($hyTops as $list) { foreach ($list as $r) { $hyWalk($r); } }
foreach ($hyPeople as $e) {
    if (!isset($hyReached[(string) $e['id']])) { $hyTops[$hyCo[(string) $e['id']]][] = (string) $e['id']; $hyWalk((string) $e['id']); }
}
$hySizes = [];
$hySize = static function (string $id, array $seen = []) use (&$hySize, &$hySizes, $hyKids): int {
    if (isset($hySizes[$id])) { return $hySizes[$id]; }
    if (isset($seen[$id])) { return 0; }
    $seen[$id] = true;
    $n = 1;
    foreach ($hyKids[$id] ?? [] as $k) { $n += $hySize($k, $seen); }
    return $hySizes[$id] = $n;
};
$hyName = static fn (string $id): string => strtolower(trim((string) ($hyById[$id]['name'] ?? '')));
$hyOrder = static fn (array $ids): array => (static function (array $ids) use ($hySize, $hyName): array {
    usort($ids, static fn (string $a, string $b): int => [$hySize($b), $hyName($a)] <=> [$hySize($a), $hyName($b)]);
    return $ids;
})($ids);
ksort($hyTops);
uksort($hyTops, static fn (string $a, string $b): int => [str_starts_with($a, 'No company') ? 1 : 0, $a] <=> [str_starts_with($b, 'No company') ? 1 : 0, $b]);

// COLOURS (owner, 13 Oct): each company its own colour (fixed by its place in the sorted list of every
// company in scope, so a filter never recolours one), and each card coloured by designation level.
// the company's own brand colour from Organization Configuration → Companies (companyColor()).
$hyCoColor = static fn (string $co): string => str_starts_with($co, 'No company') ? 'var(--text3)' : companyColor($store, $co);
// ponytail: level from words in the designation; switch to Designation → Grade once grades are filled in.
$hyTiers = [
    'lead' => ['Leadership', 'var(--purple)', '/\b(director|ceo|cfo|coo|cto|md|managing|chief|founder|president|vp|vice|partner|head)\b/i'],
    'mgmt' => ['Managers & Admins', 'var(--blue)', '/\b(manager|gm|admin|administrator)\b/i'],
    'tl' => ['Team Leads & Supervisors', '#0d9488', '/\b(lead|leader|tl|supervisor|coordinator|incharge|in-charge)\b/i'],
    'exec' => ['Executives & Officers', 'var(--green)', '/\b(executive|officer|analyst|associate|specialist|accountant|engineer|developer|consultant)\b/i'],
    'field' => ['Agents, Callers & Trainees', 'var(--amber)', '/\b(agent|caller|telecaller|tele|field|collector|trainee|intern|assistant|boy|helper|driver)\b/i'],
    'other' => ['Other', 'var(--text3)', ''],
];
$hyTierOf = static function (string $desig) use ($hyTiers): string {
    foreach ($hyTiers as $k => [, , $re]) { if ($re !== '' && preg_match($re, $desig)) { return $k; } }
    return 'other';
};
$hyTierSeen = [];
foreach ($hyPeople as $e) { $hyTierSeen[$hyTierOf(orgValueOf($store, $e, 'designation'))] = true; }

$hyFace = static function (array $e, string $cls): string {
    $photo = trim((string) ($e['photo_path'] ?? ''));
    $name = trim((string) ($e['name'] ?? ''));
    $ini = strtoupper(implode('', array_map(static fn (string $w): string => substr($w, 0, 1), array_slice(preg_split('/\s+/', $name) ?: [], 0, 2))));

    return $photo !== '' ? '<img class="' . $cls . '" src="' . e($photo) . '" alt="">' : '<span class="' . $cls . ' hy-ini">' . e($ini) . '</span>';
};
$hyCard = static function (array $e, int $kids, int $all) use ($store, $hyFace, $hyAll, $hyCo, $hyTierOf): string {
    // NO COMPANY ON THE CARD (owner, 12 Oct): it is the box at the top. Emp. ID, name, designation, branch.
    $sub = array_filter([orgValueOf($store, $e, 'branch')]);
    $m = (string) ($e['reporting_manager_id'] ?? '');
    $up = $m !== '' && isset($hyAll[$m]) && (orgValueOf($store, $hyAll[$m], 'company') !== ($hyCo[(string) $e['id']] ?? ''))
        ? '<div class="hy-up">reports to ' . e(trim((string) $hyAll[$m]['name'])) . '</div>' : '';

    return '<div class="hy-node hy-t-' . $hyTierOf(orgValueOf($store, $e, 'designation')) . ($kids > 0 ? ' hy-has' : '') . '" role="button" tabindex="0"' . ($kids > 0 ? ' title="Click to open / close the team"' : '') . '>'
        . $hyFace($e, 'hy-face') . '<div class="hy-name">' . e(trim((string) ($e['name'] ?? ''))) . '</div><div class="hy-desig">' . e(orgValueOf($store, $e, 'designation')) . '</div>'
        . '<div class="hy-sub">' . e(Employment::code($e) . ($sub === [] ? '' : ' · ' . implode(' · ', $sub))) . '</div>' . $up
        . ($kids > 0 ? '<div class="hy-count">' . $kids . ' direct · ' . $all . ' in all</div>' : '')
        . '<button type="button" class="hy-prof" data-hy="' . e((string) $e['id']) . '" title="Profile"><i class="fas fa-id-card"></i></button></div>';
};
$hyMini = static function (array $e) use ($store, $hyFace, $hyTierOf): string {
    return '<a class="hy-mini hy-t-' . $hyTierOf(orgValueOf($store, $e, 'designation')) . '" href="#" data-hy="' . e((string) $e['id']) . '" title="Profile">' . $hyFace($e, 'hy-mface')
        . '<span><b>' . e(trim((string) ($e['name'] ?? ''))) . '</b><br><span>' . e(orgValueOf($store, $e, 'designation')) . '</span><br><span class="hy-msub">'
        . e(Employment::code($e) . ' · ' . orgValueOf($store, $e, 'branch')) . '</span></span></a>';
};
$hyRender = static function (string $id, array $seen) use (&$hyRender, $hyKids, $hyById, $hyCard, $hyMini, $hySize, $hyOrder): string {
    if (isset($seen[$id])) { return ''; }
    $seen[$id] = true;
    $kids = $hyKids[$id] ?? [];
    $leads = $hyOrder(array_values(array_filter($kids, static fn (string $k): bool => ($hyKids[$k] ?? []) !== [])));
    $team = array_values(array_filter($kids, static fn (string $k): bool => ($hyKids[$k] ?? []) === []));
    usort($team, static fn (string $a, string $b): int => strcasecmp((string) $hyById[$a]['name'], (string) $hyById[$b]['name']));
    $inner = '';
    foreach ($leads as $k) { $inner .= $hyRender($k, $seen); }
    if ($team !== []) {
        $grid = '';
        foreach ($team as $k) { $grid .= $hyMini($hyById[$k]); }
        $inner .= '<li class="hy-teamli"><div class="hy-chip" role="button" tabindex="0"><i class="fas fa-users"></i> ' . count($team)
            . ' team member' . (count($team) === 1 ? '' : 's') . ' <span class="hy-chev"></span></div><div class="hy-grid">' . $grid . '</div></li>';
    }

    return '<li class="hy-closed">' . $hyCard($hyById[$id], count($kids), $hySize($id) - 1) . ($inner === '' ? '' : '<ul>' . $inner . '</ul>') . '</li>';
};
// THE PROFILE CARD (owner, 12 Oct: the full Employee view was too much from an org chart): the
// essentials in a small dialog, with a button to the full record.
$hyInfo = [];
$hyBrNames = (array) (employeeLookups($store)['branches'] ?? []);
foreach ($hyById as $id => $e) {
    $id = (string) $id; // numeric ids become int array keys
    $m = (string) ($e['reporting_manager_id'] ?? '');
    $hyInfo[$id] = ['name' => trim((string) ($e['name'] ?? '')), 'code' => Employment::code($e), 'photo' => trim((string) ($e['photo_path'] ?? '')),
        'designation' => orgValueOf($store, $e, 'designation'), 'company' => orgValueOf($store, $e, 'company'), 'branch' => orgValueOf($store, $e, 'branch')
            // MORE THAN ONE BRANCH (owner, 26 Oct): Home first, then the others they handle — the pop-up only
            . (($hyMore = otherBranchNames($employeeExtras->forEmployment(Employment::code($e)), $hyBrNames, (string) ($e['branch_id'] ?? ''))) === [] ? '' : ' (home) + ' . implode(', ', $hyMore)),
        'department' => orgValueOf($store, $e, 'department'), 'doj' => ($d = Employment::joinedOn($e)) === '' ? '' : SmartPRS\Surface\Dates::dmy($d),
        'manager' => $m !== '' && isset($hyAll[$m]) ? trim((string) $hyAll[$m]['name']) . ' (' . Employment::code($hyAll[$m]) . ')' : '',
        'email' => trim((string) ($e['email'] ?? '')), 'mobile' => trim((string) ($e['mobile'] ?? '')),
        'reports' => count($hyKids[$id] ?? []), 'team' => $hySize($id) - 1];
}
// ONE EMPLOYEE PICKED (owner, 22 Oct 2026): their company only, the line from the top down to them and their
// whole team below, opened; the rest of the company stays folded. The Export follows the same view.
$hyEmpCode = trim((string) ($_GET['f_employee'] ?? ''));
$hyTarget = '';
foreach ($hyById as $hid => $he) { Employment::code($he) === $hyEmpCode && $hyEmpCode !== '' && $hyTarget = (string) $hid; }
$hyPath = [];
if ($hyTarget !== '') {
    $hyParent = [];
    foreach ($hyKids as $pid => $ks) { foreach ($ks as $k) { $hyParent[(string) $k] = (string) $pid; } }
    for ($x = $hyTarget; $x !== '' && !in_array($x, $hyPath, true); $x = $hyParent[$x] ?? '') { array_unshift($hyPath, $x); }
    $hyTops = array_intersect_key($hyTops, [$hyCo[$hyTarget] => true]);
}
// THE EXPORT ROWS, depth first in the order the chart draws them.
$hyRows = [];
$hyDump = static function (string $id, int $level, array $seen) use (&$hyDump, &$hyRows, $hyKids, $hyById, $hyAll, $hyCo, $hySize, $hyOrder, $store): void {
    if (isset($seen[$id]) || !isset($hyById[$id])) { return; }
    $seen[$id] = true;
    $e = $hyById[$id];
    $m = (string) ($e['reporting_manager_id'] ?? '');
    $hyRows[] = [$level, Employment::code($e), trim((string) ($e['name'] ?? '')), orgValueOf($store, $e, 'designation'), orgValueOf($store, $e, 'department'),
        orgValueOf($store, $e, 'branch'), $hyCo[$id] ?? '', $m !== '' && isset($hyAll[$m]) ? Employment::code($hyAll[$m]) . ' · ' . trim((string) $hyAll[$m]['name']) : '',
        count($hyKids[$id] ?? []), $hySize($id) - 1];
    foreach ($hyOrder($hyKids[$id] ?? []) as $k) { $hyDump((string) $k, $level + 1, $seen); }
};
if ($hyTarget !== '') {
    foreach (array_slice($hyPath, 0, -1) as $i => $pid) { $hyDump2 = $hyById[$pid]; $m = (string) ($hyDump2['reporting_manager_id'] ?? '');
        $hyRows[] = [$i + 1, Employment::code($hyDump2), trim((string) ($hyDump2['name'] ?? '')), orgValueOf($store, $hyDump2, 'designation'), orgValueOf($store, $hyDump2, 'department'),
            orgValueOf($store, $hyDump2, 'branch'), $hyCo[$pid] ?? '', $m !== '' && isset($hyAll[$m]) ? Employment::code($hyAll[$m]) . ' · ' . trim((string) $hyAll[$m]['name']) : '',
            count($hyKids[$pid] ?? []), $hySize($pid) - 1]; }
    $hyDump($hyTarget, count($hyPath), []);
} else {
    foreach ($hyTops as $list) { foreach ($hyOrder($list) as $r) { $hyDump((string) $r, 1, []); } }
}
$hyFiltered = array_filter(array_intersect_key($hyFilter, array_flip($hyKeys))) !== [] || $hyEmpCode !== '';
$hyOneCo = count($hyTops) === 1;
?>
<div class="page-header">
  <div><h2>Hierarchy <span class="badge badge-gray" style="vertical-align:middle;font-size:11px" title="Click a company or a person to open the level below"><?= count($hyPeople) ?> people &middot; <?= count($hyTops) ?> compan<?= count($hyTops) === 1 ? 'y' : 'ies' ?></span></h2></div>
  <div class="actions"><a class="btn btn-outline btn-sm" href="?r=employees"><i class="fas fa-address-book"></i> Directory</a></div>
</div>
<form method="get" class="card row hy-filter" style="align-items:flex-end;gap:12px">
  <input type="hidden" name="r" value="hierarchy">
<?php foreach ($hyKeys as $k): ?>
  <div><label for="hy_<?= e($k) ?>"><?= e(ORG_FILTERS[$k]) ?></label><select id="hy_<?= e($k) ?>" name="f_<?= e($k) ?>"><option value="">ALL</option>
<?php foreach ((array) ($hyOptions[$k] ?? []) as $o): ?><option<?= strcasecmp((string) $o, $hyFilter[$k]) === 0 ? ' selected' : '' ?>><?= e((string) $o) ?></option><?php endforeach; ?></select></div>
<?php endforeach; ?>
  <div style="min-width:230px"><label for="hy_employee">Employee</label><?= employeePicker('hy_employee', 'f_employee', (array) ($hyOptions['_employee_labels'] ?? []), $hyEmpCode, 'ALL', false, '', [], (array) ($hyOptions['_employee_org'] ?? []), true) ?></div>
  <div><button class="go" type="submit">Show</button> <?php if ($hyFiltered): ?><a class="btn btn-outline" href="?r=hierarchy&amp;f_company=ALL">Clear</a><?php endif; ?></div>
  <div style="margin-left:auto;display:flex;flex-wrap:nowrap!important;flex:0 0 auto!important;max-width:none!important;gap:6px;align-items:center"><button type="button" class="btn btn-outline btn-sm" id="hy-open-all">Expand all</button>
    <button type="button" class="btn btn-outline btn-sm" id="hy-close-all">Collapse all</button>
    <button type="button" class="btn btn-outline btn-sm" id="hy-export" title="Download the chart as a PDF — exactly the levels open on screen now"><i class="fas fa-file-pdf"></i> Export PDF</button></div>
</form>
<style>
  /* THEMED (owner, 13 Oct): every colour below is the app's own theme variable, so the chart follows
     Appearance → Theme and Light / Dark. Company hues and designation levels are theme hues too. */
  .hy-legend{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 16px;font-size:12px;color:var(--text2)}
  .hy-legend b{font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.04em;font-size:11px;margin-right:4px}
  .hy-legend span{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;background:var(--card);border:1px solid var(--border)}
  .hy-legend i{width:9px;height:9px;border-radius:50%;background:var(--dc)}
  .hy-board{display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start}
  .hy-company{container-type:inline-size;position:relative;background:var(--card);border:1px solid var(--border);border-radius:16px;padding:22px 18px 20px;flex:1 1 440px;max-width:100%;overflow-x:auto;
    box-shadow:0 1px 3px rgba(15,23,42,.06),0 8px 24px -12px rgba(15,23,42,.12)}
  /* side by side while folded; a company opened below its top level takes the full row, so a big
     team never squeezes its siblings out of view */
  .hy-company:has(.hy-org > li > ul > li:not(.hy-closed) > ul){flex-basis:100%}
  .hy-company::before{content:"";position:absolute;inset:0 0 auto 0;height:4px;border-radius:16px 16px 0 0;background:var(--hc)}
  /* the tree lines */
  .hy-org,.hy-org ul{display:flex;justify-content:center;list-style:none;margin:0;padding:26px 0 0;position:relative}
  .hy-org{padding-top:0;width:max-content;min-width:100%}
  .hy-org li{position:relative;padding:26px 8px 0;display:flex;flex-direction:column;align-items:center}
  .hy-company{--hl:color-mix(in srgb,var(--hc) 45%,var(--border))}
  .hy-org ul::before{content:"";position:absolute;top:0;left:50%;height:26px;border-left:2px solid var(--hl)}
  .hy-org li::before,.hy-org li::after{content:"";position:absolute;top:0;width:50%;height:26px;border-top:2px solid var(--hl)}
  .hy-org li::before{right:50%}
  .hy-org li::after{left:50%;border-left:2px solid var(--hl)}
  .hy-org li:only-child::before,.hy-org li:only-child::after{display:none}
  .hy-org li:only-child{padding-top:0}
  .hy-org li:first-child::before,.hy-org li:last-child::after{border-top:0}
  .hy-org li:last-child::before{border-right:2px solid var(--hl);border-radius:0 10px 0 0}
  .hy-org li:first-child::after{border-radius:10px 0 0 0}
  .hy-org > li{padding-top:0}.hy-org > li::before,.hy-org > li::after{display:none}
  .hy-closed > ul{display:none}
  /* company root */
  .hy-co{cursor:pointer;display:flex;align-items:center;gap:12px;background:var(--hc);color:#fff;border-radius:14px;padding:12px 22px 12px 14px;user-select:none;
    box-shadow:0 6px 16px -6px color-mix(in srgb,var(--hc) 60%,transparent);transition:transform .15s,box-shadow .15s}
  .hy-co:hover{transform:translateY(-1px);box-shadow:0 10px 22px -8px color-mix(in srgb,var(--hc) 70%,transparent)}
  .hy-co .hy-co-ic{width:38px;height:38px;border-radius:10px;display:grid;place-items:center;background:rgba(255,255,255,.18);font-size:16px}
  .hy-co b{display:block;font-size:15px;font-weight:700;letter-spacing:.01em;line-height:1.2}
  .hy-co small{display:block;font-size:11.5px;opacity:.85;margin-top:2px}
  /* person card */
  .hy-node{position:relative;display:flex;flex-direction:column;align-items:center;width:184px;min-height:186px;padding:16px 12px 12px;text-align:center;background:var(--card);
    border:1px solid var(--border);border-top:3px solid var(--dc);border-radius:14px;box-shadow:0 1px 2px rgba(15,23,42,.05);transition:transform .15s,box-shadow .15s,border-color .15s}
  .hy-has{cursor:pointer}
  .hy-node:hover{transform:translateY(-2px);border-color:color-mix(in srgb,var(--dc) 55%,var(--border));border-top-color:var(--dc);box-shadow:0 10px 22px -10px rgba(15,23,42,.25)}
  .hy-face{width:56px;height:56px;border-radius:50%;object-fit:cover;margin:0 auto 10px;flex:0 0 auto;box-shadow:0 0 0 3px var(--card),0 0 0 5px var(--dc)}
  .hy-ini{display:flex;align-items:center;justify-content:center;background:var(--dc,var(--accent));color:#fff;font-weight:700;letter-spacing:.02em}
  .hy-name{font-weight:700;font-size:13.5px;line-height:1.25;color:var(--text1)}
  .hy-desig{display:inline-block;margin-top:6px;padding:2px 10px;border-radius:999px;font-size:11.5px;font-weight:600;color:var(--dc);background:color-mix(in srgb,var(--dc) 12%,transparent)}
  .hy-sub{font-size:11px;color:var(--text3);margin-top:6px;line-height:1.35}
  .hy-up{font-size:11px;color:var(--text2);margin-top:4px;font-style:italic;line-height:1.3}
  .hy-count{margin-top:auto;padding-top:10px;width:100%;border-top:1px dashed var(--border);font-size:11.5px;font-weight:600;color:var(--accent)}
  .hy-closed > .hy-node .hy-count::after{content:"  ▸"}
  li:not(.hy-closed) > .hy-has .hy-count::after{content:"  ▾"}
  .hy-prof{position:absolute;top:6px;right:6px;width:26px;height:26px;border-radius:8px;display:grid;place-items:center;color:var(--text3);font-size:12px;background:transparent;border:0;cursor:pointer}
  .hy-prof:hover{color:var(--accent);background:var(--accent-soft)}
  /* team chip + grid */
  .hy-chip{cursor:pointer;display:inline-flex;align-items:center;gap:6px;background:var(--accent-soft);border:1px solid color-mix(in srgb,var(--accent) 35%,transparent);color:var(--accent);border-radius:999px;padding:6px 14px;font-size:12px;font-weight:700;white-space:nowrap;transition:background .15s}
  .hy-chip:hover{background:color-mix(in srgb,var(--accent) 18%,transparent)}
  .hy-chip .hy-chev::after{content:"▸"}
  .hy-teamli.hy-open .hy-chip .hy-chev::after{content:"▾"}
  .hy-grid{display:none;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px;width:min(660px,80vw);width:min(660px,90cqw);margin-top:12px;padding:12px;text-align:left;
    background:var(--bg);border:1px solid var(--border);border-radius:14px}
  .hy-teamli.hy-open .hy-grid{display:grid}
  .hy-mini{display:flex;gap:10px;align-items:center;background:var(--card);border:1px solid var(--border);border-left:3px solid var(--dc);border-radius:10px;padding:7px 10px;text-decoration:none;color:var(--text1);font-size:12px;line-height:1.35;transition:box-shadow .15s,border-color .15s}
  .hy-mini:hover{border-color:color-mix(in srgb,var(--dc) 55%,var(--border));border-left-color:var(--dc);box-shadow:0 4px 12px -6px rgba(15,23,42,.25)}
  .hy-mini > span > span:not(.hy-msub){color:var(--dc);font-weight:600;font-size:11.5px}
  .hy-mini b{color:var(--text1)}
  .hy-mface{width:32px;height:32px;border-radius:50%;object-fit:cover;flex:0 0 32px;font-size:11px}
  .hy-msub{color:var(--text3);font-size:11px}
  /* profile dialog */
  #hy-dlg{background:var(--card);color:var(--text1);box-shadow:0 24px 60px -20px rgba(0,0,0,.45)}
  #hy-dlg::backdrop{background:rgba(15,23,42,.45)}
  #hy-dlg-desig{color:var(--accent)!important;font-weight:600}
  /* ONE-PAGE VIEW (owner, 23 Oct): compact header, filters and cards; the chart then scales itself to fit
     the space left on screen (never below 55%), and re-fits on every open / close. */
  .page-header:has(+ form.hy-filter){margin-bottom:8px}
  form.hy-filter{padding:10px 14px!important;margin-bottom:8px!important;gap:10px!important}
  form.hy-filter label{font-size:11px;margin-bottom:2px}
  form.hy-filter select,form.hy-filter input:not([type=checkbox]),form.hy-filter .emp-pick-text{height:32px;padding-top:3px;padding-bottom:3px;font-size:12.5px}
  form.hy-filter button.go,form.hy-filter .btn{height:32px}
  .hy-legend{margin:0 0 8px!important}
  .hy-company{padding:14px 12px 12px!important}
  .hy-org ul{padding-top:18px}
  .hy-org li{padding:18px 5px 0}
  .hy-org ul::before,.hy-org li::before,.hy-org li::after{height:18px}
  .hy-node{width:156px;min-height:0;padding:10px 8px 8px}
  .hy-face{width:42px;height:42px;margin-bottom:6px}
  .hy-name{font-size:12.5px}
  .hy-desig{margin-top:4px;padding:1px 8px;font-size:10.5px}
  .hy-sub{margin-top:4px;font-size:10.5px}
  .hy-count{padding-top:6px;margin-top:6px;font-size:11px}
  .hy-co{padding:8px 16px 8px 10px}.hy-co .hy-co-ic{width:30px;height:30px;font-size:13px}
  .hy-chip{padding:4px 12px}
  /* PDF CAPTURE ONLY (26 Oct): Arial while the image is taken (web fonts cannot be drawn into it), icons hidden */
  body.hy-pdf .hy-org,body.hy-pdf .hy-org *{font-family:Arial,Helvetica,sans-serif!important;transition:none!important}
  body.hy-pdf .hy-org .fas,body.hy-pdf .hy-prof,body.hy-pdf .hy-co-ic{display:none!important}
  /* whole-pixel sizes: the capture library shrinks a fractional size (12.5px → 11.9px) after measuring, leaving gaps */
  body.hy-pdf .hy-name{font-size:13px!important}body.hy-pdf .hy-desig,body.hy-pdf .hy-sub,body.hy-pdf .hy-mini > span > span:not(.hy-msub){font-size:11px!important}body.hy-pdf .hy-co small{font-size:12px!important}
<?php foreach ($hyTiers as $k => [, $c]): ?>  .hy-t-<?= $k ?>{--dc:<?= $c ?>}
<?php endforeach; ?></style>
<?php if ($hyPeople === []): ?>
<div class="card"><div class="empty">Nobody in this selection.</div></div>
<?php else: ?>
<div class="hy-legend"><b>Designation level:</b><?php foreach ($hyTiers as $k => [$label]): if (!isset($hyTierSeen[$k])) { continue; } ?><span class="hy-t-<?= $k ?>"><i></i><?= e($label) ?></span><?php endforeach; ?></div>
<div class="hy-board">
<?php foreach ($hyTops as $hyCoName => $hyCoTops): $hyCoTops = $hyOrder($hyCoTops); $hyCoCount = count(array_filter($hyCo, static fn (string $c): bool => $c === $hyCoName)); ?>
  <div class="hy-company" style="--hc:<?= $hyCoColor((string) $hyCoName) ?>"><ul class="hy-org"><li<?= $hyOneCo || count($hyTops) <= 3 ? '' : ' class="hy-closed"' ?>>
    <div class="hy-co" role="button" tabindex="0" title="Click to open / close"><span class="hy-co-ic"><i class="fas fa-building"></i></span><span><b><?= e($hyCoName) ?></b><small><?= $hyCoCount ?> people &middot; <?= count($hyCoTops) ?> at the top</small></span></div>
    <ul><?php foreach ($hyCoTops as $r) { echo $hyRender($r, []); } ?></ul></li></ul></div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<dialog id="hy-dlg" style="margin:auto;position:fixed;inset:0;border:0;border-radius:14px;padding:0;width:min(440px,94vw)">
  <div style="padding:20px 22px">
    <div style="display:flex;gap:14px;align-items:center"><div id="hy-dlg-face"></div><div><div id="hy-dlg-name" style="font-weight:700;font-size:17px"></div><div id="hy-dlg-desig"></div><div id="hy-dlg-code" class="note"></div></div></div>
    <table style="margin-top:12px;font-size:13px;width:100%"><tbody id="hy-dlg-rows"></tbody></table>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px"><button type="button" class="btn btn-outline" onclick="this.closest('dialog').close()">Close</button><a id="hy-dlg-open" class="btn btn-primary" href="#"><i class="fas fa-up-right-from-square"></i> Open full record</a></div>
  </div>
</dialog>
<script>
(function () {
  var info = <?= json_encode($hyInfo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var rows = <?= json_encode($hyRows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var target = <?= json_encode($hyTarget) ?>;
  // EXPORT = PDF OF WHAT IS OPEN NOW (owner, 23 Oct; fixed 26 Oct): the chart exactly as expanded on screen,
  // one page per company. The two libraries load from cdnjs / jsDelivr only when the button is pressed.
  // 26 Oct: (1) web fonts are NOT embedded (that fetched ~70 font files = the long wait) — during the capture
  // the chart switches to Arial, so the layout is measured in the same font the image is drawn in (no more
  // names over the designation, no □ icons); (2) each company's chart is captured whole, not its scroll box
  // (no cut-off right side, no scrollbar); (3) a page never splits a card — one page per company, as tall
  // as the chart needs.
  var hyLoad = function (src) { return new Promise(function (ok, no) { var s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = no; document.head.appendChild(s); }); };
  document.getElementById('hy-export').addEventListener('click', function () {
    var btn = this, orgs = [].slice.call(document.querySelectorAll('.hy-org'));
    if (!orgs.length) { return; }
    btn.disabled = true; var label = btn.innerHTML; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing PDF…';
    var zoomed = orgs.map(function (o) { var z = o.style.zoom; o.style.zoom = ''; return [o, z]; });
    var day = new Date().toLocaleDateString('en-GB').replace(/\//g, '-'), pdf = null;
    Promise.all([
      window.htmlToImage ? 0 : hyLoad('https://cdn.jsdelivr.net/npm/html-to-image@1.11.11/dist/html-to-image.js'),
      window.jspdf ? 0 : hyLoad('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js')
    ]).then(function () {
      document.body.classList.add('hy-pdf');
      return orgs.reduce(function (chain, org) {
        return chain.then(function () {
          return window.htmlToImage.toCanvas(org, {pixelRatio: 2, backgroundColor: '#ffffff', skipFonts: true, width: org.scrollWidth, height: org.scrollHeight});
        }).then(function (canvas) {
          var co = org.querySelector('.hy-co b'), title = 'Hierarchy - ' + (co ? co.textContent : '') + '   ' + day;
          // A4 landscape (A3 when wide), never enlarged past 100 %; a tall chart makes a taller page instead of a split
          var m = 10, top = 18, cssW = canvas.width / 2, pw = cssW > 1400 ? 420 : 297, iw = Math.min(pw - 2 * m, cssW * 0.2646), ih = canvas.height * iw / canvas.width;
          var ph = Math.max(pw === 420 ? 297 : 210, ih + top + m), fmt = [pw, ph];
          var ori = pw >= ph ? 'landscape' : 'portrait';
          if (pdf) { pdf.addPage(fmt, ori); } else { pdf = new window.jspdf.jsPDF({orientation: ori, unit: 'mm', format: fmt}); }
          pdf.setFontSize(12); pdf.setTextColor(31, 58, 95); pdf.text(title, m, 11);
          pdf.addImage(canvas.toDataURL('image/jpeg', 0.92), 'JPEG', (pw - iw) / 2, top, iw, ih);
        });
      }, Promise.resolve());
    }).then(function () {
      pdf.save('hierarchy-' + day + '.pdf');
      btn.innerHTML = label;
    }).catch(function () { btn.innerHTML = '<i class="fas fa-triangle-exclamation"></i> PDF failed — check internet, try again'; setTimeout(function () { btn.innerHTML = label; }, 5000); })
      .finally(function () { document.body.classList.remove('hy-pdf'); btn.disabled = false; zoomed.forEach(function (p) { p[0].style.zoom = p[1]; }); });
  });
  // FIT TO THE SCREEN (owner, 23 Oct): the open chart is scaled to the width and height left on the page.
  var hyFit = function () {
    document.querySelectorAll('.hy-company').forEach(function (co) {
      var org = co.querySelector('.hy-org'); if (!org) { return; }
      org.style.zoom = '';
      var w = org.scrollWidth, h = org.scrollHeight;
      var aw = co.clientWidth - 24, ah = window.innerHeight - co.getBoundingClientRect().top - window.scrollY + window.pageYOffset - 28;
      ah = window.innerHeight - org.getBoundingClientRect().top - 24;
      var z = Math.max(0.55, Math.min(1, aw / w, ah / h));
      if (z < 1) { org.style.zoom = z.toFixed(3); }
    });
  };
  document.addEventListener('click', function (ev) { if (ev.target.closest('.hy-co, .hy-has, .hy-chip, #hy-open-all, #hy-close-all')) { setTimeout(hyFit, 0); } });
  window.addEventListener('resize', hyFit);
  setTimeout(hyFit, 0);
  var dlg = document.getElementById('hy-dlg');
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  var show = function (id) {
    var p = info[id]; if (!p) { return; }
    var ini = p.name.split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0); }).join('').toUpperCase();
    document.getElementById('hy-dlg-face').innerHTML = p.photo ? '<img src="' + esc(p.photo) + '" style="width:72px;height:72px;border-radius:50%;object-fit:cover">'
      : '<span class="hy-ini" style="width:72px;height:72px;border-radius:50%;font-size:22px">' + esc(ini) + '</span>';
    document.getElementById('hy-dlg-name').textContent = p.name;
    document.getElementById('hy-dlg-desig').textContent = p.designation;
    document.getElementById('hy-dlg-code').textContent = p.code;
    var rows = [['Company', p.company], ['Branch', p.branch], ['Department', p.department], ['Reports to', p.manager], ['Team', p.reports ? p.reports + ' direct · ' + p.team + ' in all' : ''],
      ['Joined', p.doj], ['Email', p.email], ['Mobile', p.mobile]];
    document.getElementById('hy-dlg-rows').innerHTML = rows.filter(function (r) { return r[1]; }).map(function (r) {
      return '<tr><td class="note" style="padding:3px 10px 3px 0;white-space:nowrap">' + r[0] + '</td><td style="padding:3px 0">' + esc(String(r[1])) + '</td></tr>'; }).join('');
    document.getElementById('hy-dlg-open').href = '?r=employees&edit=' + encodeURIComponent(id);
    dlg.showModal();
  };
  document.querySelectorAll('[data-hy]').forEach(function (n) {
    n.addEventListener('click', function (ev) { ev.preventDefault(); ev.stopPropagation(); show(n.getAttribute('data-hy')); });
  });
  dlg.addEventListener('click', function (ev) { if (ev.target === dlg) { dlg.close(); } });
  // CLICK A COMPANY OR A PERSON = OPEN / CLOSE THE LEVEL BELOW; a team chip opens its grid.
  // The profile is the small icon on a card, or a click on a team member.
  var bind = function (sel, fn) {
    document.querySelectorAll(sel).forEach(function (n) {
      n.addEventListener('click', function () { fn(n); });
      n.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); fn(n); } });
    });
  };
  bind('.hy-co, .hy-has', function (n) { n.parentNode.classList.toggle('hy-closed'); });
  bind('.hy-chip', function (n) { n.parentNode.classList.toggle('hy-open'); });
  // THE PICKED EMPLOYEE: open every level from the top down to them, then everything under them.
  if (target) {
    var me = document.querySelector('.hy-node [data-hy="' + target + '"]') ? document.querySelector('.hy-node [data-hy="' + target + '"]').closest('li')
      : (document.querySelector('.hy-mini[data-hy="' + target + '"]') || {closest: function () { return null; }}).closest('li');
    if (me) {
      for (var l = me; l; l = l.parentElement && l.parentElement.closest('li')) { l.classList.remove('hy-closed'); l.classList.contains('hy-teamli') && l.classList.add('hy-open'); }
      me.querySelectorAll('li').forEach(function (x) { x.classList.remove('hy-closed'); x.classList.contains('hy-teamli') && x.classList.add('hy-open'); });
      var card = me.querySelector('.hy-node') && !me.classList.contains('hy-teamli') ? me.querySelector('.hy-node') : document.querySelector('.hy-mini[data-hy="' + target + '"]');
      if (card) { card.style.outline = '3px solid var(--accent)'; card.style.outlineOffset = '3px'; setTimeout(function () { card.scrollIntoView({block: 'center', inline: 'center'}); }, 50); }
    }
  }
  // TOP TWO LEVELS OPEN BY DEFAULT (owner, 23 Oct): the first two people levels, and their team chips.
  if (!target) {
    document.querySelectorAll('.hy-org > li, .hy-org > li > ul > li, .hy-org > li > ul > li > ul > li').forEach(function (l) {
      l.classList.remove('hy-closed'); l.classList.contains('hy-teamli') && l.classList.add('hy-open');
    });
  }
  document.getElementById('hy-open-all').addEventListener('click', function () {
    document.querySelectorAll('.hy-org li').forEach(function (l) { l.classList.remove('hy-closed'); });
  });
  document.getElementById('hy-close-all').addEventListener('click', function () {
    document.querySelectorAll('.hy-org li').forEach(function (l) { if (l.querySelector(':scope > ul') && !l.querySelector(':scope > .hy-co')) { l.classList.add('hy-closed'); } });
    document.querySelectorAll('.hy-teamli').forEach(function (l) { l.classList.remove('hy-open'); });
  });
})();
</script>
