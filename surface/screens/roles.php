<?php declare(strict_types=1); if (!$may('tenant.settings.view')): ?>
<div class="page-header"><div><h2>Roles &amp; Permissions</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'tenant.settings.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// ONE MATRIX: every menu item down the side, every role across the top, one
// dropdown per cell — the format the owner asked for (Administration → Roles
// & Permissions), replacing the earlier one-role-at-a-time-with-radio-columns
// layout. Nothing about WHAT is being edited changed: still
// `Authorisation::CUSTOMISATION_KEY`, still one row per menu item's own
// permission CODE (not per module), still the same four states — the save
// action (`save-role-permissions` in app.php) already accepts
// `grid[role][code] = state` for as many roles as are posted at once, which
// is exactly what a "every role, all at once" grid needs; nothing there
// changed either.
//
// ROLES ACROSS THE TOP COME FROM `Authorisation::roles()` — the 3 built-in
// tiers plus every distinct "Role" value typed into Organization
// Configuration → Role/Designation Setup (each one a real, independently
// permissioned role, not a display grouping). That is the "roles taken from
// Organization Configuration" the owner asked for; already true before this
// page existed in this shape, so no change to Authorisation.php was needed.
//
// FOUR STATES, NOT THREE — the mockup's dropdown showed No Access / View /
// Edit. A 4th, Full Access, stays in: `Authorisation::withOverride()` treats
// Edit and Full Access differently for a code whose OWN tier is "full" (an
// admin/destructive action) — only Full Access grants those, so dropping the
// option would make that handful of permissions silently un-grantable from
// this screen. Confirmed keeping it with the owner.
//
// A CODE SEVERAL MENU ITEMS SHARE is still one switch under several labels
// (e.g. two screens gated by the same permission). Unlike the old radio
// design, a <select> has no "only one checked per name" trap, so keeping
// every row's dropdown in sync is just: give the FIRST row to declare a code
// the name that actually posts, give every later row for the same code a
// nameless twin, and mirror value changes across every cell sharing a
// code+role (JS at the bottom). Simpler than the radio-era version because a
// dropdown's value is one thing to copy, not four checkboxes to keep exclusive.
use SmartPRS\Surface\RoleMatrix;

$roleMatrix = new RoleMatrix($access, SmartPRS\Platform\Access\PermissionCatalogue::seeded(), $customisation);
$roleLabel = ['administrator' => 'Administrator', 'manager' => 'Reporting manager', 'viewer' => 'Employee (viewer)'];
$stateLabel = ['none' => 'No Access', 'view' => 'View', 'edit' => 'Edit', 'full' => 'Full Access'];
$stateBadge = ['none' => 'badge-gray', 'view' => 'badge-blue', 'edit' => 'badge-amber', 'full' => 'badge-green'];
$stateOrder = ['none', 'view', 'edit', 'full']; // dropdown option order — matches the mockup, Full Access appended
$canEdit = $may('access.role.update');
// COLUMNS ARE EXACTLY ORGANIZATION CONFIGURATION → ROLE/DESIGNATION SETUP'S
// OWN "Role" VALUES — the owner's explicit instruction (19 Sep): the 3
// built-in access tiers (administrator/manager/viewer) that `$access->
// roles()` also carries are NOT shown as columns here, even though they are
// real, permissionable roles under the hood (Authorisation still knows them
// and still enforces them everywhere else) — this screen just does not add
// them to what the owner asked to see verbatim from that one screen.
$roles = array_values(array_diff($access->roles(), ['administrator', 'manager', 'viewer']));

$accounts = [];

foreach ($users->all()['users'] as $u) {
    $accounts[(string) ($u['role'] ?? '')] = ($accounts[(string) ($u['role'] ?? '')] ?? 0) + 1;
}

// WHICH DESIGNATIONS SIT UNDER EACH ROLE — a header tooltip only, purely
// informational (the old screen's Role/Designation filter served the same
// purpose; a filter no longer makes sense once every role is a column at
// once, so this is what replaces it). Exact match on the designation's own
// "Role" field, same rule the old filter used.
//
// SAME PASS ALSO COLLECTS EACH ROLE'S LEVEL, for column order (owner's
// instruction, 19 Sep: highest level first — Company Admin, then Branch
// Admin, and so on down). THE LEVEL BELONGS TO THE ROLE, NOT THE
// DESIGNATION (owner's correction, same day, after a first pass took the
// HIGHEST level across every designation sharing a role — that reads the
// number off whichever designation row happens to carry it, one design
// choice among several rows, rather than treating it as one fact about the
// role itself): the first designation encountered for a role fixes that
// role's level; later designations sharing the same role never overwrite
// it, so the number shown is the role's own, not a comparison across rows.
$desigByRole = [];
$roleLevel = [];

foreach (liveRows($store, 'designations') as $d) {
    $role = trim((string) ($d['role'] ?? ''));
    $desigByRole[$role][] = (string) ($d['name'] ?? '');

    if (!array_key_exists($role, $roleLevel)) {
        $roleLevel[$role] = (int) ($d['level'] ?? 0);
    }
}

usort($roles, static fn (string $a, string $b): int => ($roleLevel[$b] ?? 0) <=> ($roleLevel[$a] ?? 0));

$navTree = $navigation->forViewer(static fn (string $p): bool => true);
$emittedAt = []; // code => "Section → Item" of the first row that declared it, for every later row's note
$navGroups = []; // list<array{section: string, icon: string, items: list<array>}>
$reachedCodes = []; // every code this screen offers a row for, for the footer count
$allLocations = []; // code => every "Section → Item" that declares it, canonical row included
$openCount = 0; // items with no permission code at all — listed, but nothing here to switch

foreach ($navTree as $sec) {
    $secItems = [];

    foreach ((array) $sec['items'] as $it) {
        $code = (string) ($it['permission'] ?? '');

        // A PERMISSION CODE THE CATALOGUE DOES NOT DECLARE is skipped — a menu
        // item pointing at a code nobody minted is a configuration defect, and
        // manufacturing a row for it would let that defect hide behind a
        // plausible-looking grid instead of surfacing it (nothing declares it,
        // so `$roleMatrix->isDeclared()` would never be true for it either).
        if ($code !== '' && !$roleMatrix->isDeclared($code)) {
            continue;
        }

        // EVERY OTHER MENU ITEM GETS A ROW, INCLUDING ONE WITH NO PERMISSION
        // GATE AT ALL (owner's instruction, 19 Sep: the grid must list the
        // whole sidebar, not just the switchable part of it — Dashboard, Live
        // Salary and a handful of others used to be silently dropped here
        // because there is no `$may(...)` check on them anywhere to grant or
        // deny). This class's own docblock already states the sidebar's rule
        // for a permission an item DOES have: "an item nobody may open is
        // marked, not removed." An item EVERYONE may open is the mirror case
        // and gets the same treatment — shown, marked as open, not turned
        // into a phantom row the owner has to go find missing.
        if ($code === '') {
            $openCount++;
            $secItems[] = ['code' => '', 'label' => (string) $it['label'], 'ref' => false, 'open' => true];

            continue;
        }

        $at = (string) $sec['label'] . ' → ' . (string) $it['label'];
        $reachedCodes[$code] = true;
        $allLocations[$code][] = $at;

        if (isset($emittedAt[$code])) {
            $secItems[] = ['code' => $code, 'label' => (string) $it['label'], 'ref' => true, 'at' => $emittedAt[$code]];

            continue;
        }

        $emittedAt[$code] = $at;
        $secItems[] = ['code' => $code, 'label' => (string) $it['label'], 'ref' => false];
    }

    if ($secItems !== []) {
        $navGroups[] = ['section' => (string) $sec['label'], 'icon' => (string) ($sec['icon'] ?? ''), 'items' => $secItems];
    }
}

$catalogueTotal = count(SmartPRS\Platform\Access\PermissionCatalogue::seeded()->declaredCodes());
$colCount = 1 + count($roles);

/** One role×code cell — an editable dropdown (canonical row posts; a row sharing the code with an earlier one is a nameless twin kept in sync by JS) or, read-only, a plain badge. */
$cell = static function (string $role, string $code, bool $ref) use ($roleMatrix, $canEdit, $stateLabel, $stateBadge, $stateOrder): string {
    $current = $roleMatrix->state($role, $code);

    if (!$canEdit) {
        return '<td style="text-align:center"><span class="badge ' . e($stateBadge[$current] ?? 'badge-gray') . '">' . e($stateLabel[$current] ?? $current) . '</span></td>';
    }

    $name = $ref ? '' : ' name="grid[' . e($role) . '][' . e($code) . ']"';
    $h = '<td style="text-align:center"><select class="rm-cell"' . $name . ' data-code="' . e($code) . '" data-role="' . e($role) . '">';

    foreach ($stateOrder as $state) {
        $h .= '<option value="' . e($state) . '"' . ($state === $current ? ' selected' : '') . '>' . e($stateLabel[$state]) . '</option>';
    }

    return $h . '</select></td>';
};
?>
<div class="page-header">
  <div><h2>Roles &amp; Permissions</h2>
    <p>One row per menu item, one column per role — pick what each role may do with it. Roles come live from Organization
      Configuration &rarr; Role/Designation Setup, plus the 3 built-in access tiers.</p></div>
  <div><a class="btn btn-outline" href="?r=users"><i class="fas fa-user-gear"></i> User Management</a></div>
</div>

<?php if (!$canEdit): ?>
<div class="note" style="margin-bottom:12px"><b>Read-only for your role.</b> Editing this grid needs
  <code>access.role.update</code>, which your role does not hold.</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<form method="post" id="rm-form">
  <input type="hidden" name="csrf" value="<?= e($session->token()) ?>">
  <input type="hidden" name="action" value="save-role-permissions">
<?php endif; ?>

<div class="row" style="gap:8px;margin-bottom:8px">
  <button type="button" class="btn btn-outline" id="rm-expand-all"><i class="fas fa-angles-down"></i> Expand all</button>
  <button type="button" class="btn btn-outline" id="rm-collapse-all"><i class="fas fa-angles-up"></i> Collapse all</button>
</div>

<div class="table-wrap"><table class="rm-matrix">
  <thead><tr>
    <th style="position:sticky;left:0;background:#fff;z-index:1;width:26%">Menu item</th>
<?php foreach ($roles as $role): $desig = $desigByRole[$role] ?? []; ?>
    <th style="text-align:center"<?= $desig !== [] ? ' title="' . e(implode(', ', $desig)) . '"' : '' ?>>
      <?= e($roleLabel[$role] ?? $role) ?><br><span class="note"><?= (int) ($accounts[$role] ?? 0) ?> account(s)</span>
    </th>
<?php endforeach; ?>
  </tr></thead>
  <?php // HOW FAR EACH ROLE READS, above the permission rows, because it is the
        // question every row underneath is answered inside. A permission says
        // WHETHER somebody reads past their own record; this says HOW FAR past.
        //
        // IT IS NOT A PERMISSION AND IT IS NOT IN THE GRID. It posts under its
        // own name and is saved to its own versioned key
        // (Authorisation::REACH_KEY), so nothing that reads the permission record
        // has to learn about a value that is not a state. ?>
  <tbody>
    <tr style="background:#eff6ff">
      <td style="position:sticky;left:0;background:#eff6ff;border-top:1px solid #bfdbfe">
        <b>Visibility</b>
      </td>
<?php foreach ($roles as $role): $reachNow = $access->reach($role); ?>
      <td style="text-align:center;border-top:1px solid #bfdbfe">
<?php if ($canEdit): ?>
        <select name="reach[<?= e($role) ?>]" style="width:100%;max-width:150px">
          <option value="line"<?= $reachNow === 'line' ? ' selected' : '' ?>>Only their reporting line</option>
          <option value="branch"<?= $reachNow === 'branch' ? ' selected' : '' ?>>Their branch</option>
          <option value="company"<?= $reachNow === 'company' ? ' selected' : '' ?>>Whole company</option>
        </select>
<?php else: ?>
        <span class="badge <?= $reachNow === 'company' ? 'badge-amber' : 'badge-blue' ?>"><?=
          $reachNow === 'company' ? 'Whole company' : ($reachNow === 'branch' ? 'Their branch' : 'Only their reporting line') ?></span>
<?php endif; ?>
      </td>
<?php endforeach; ?>
    </tr>
  </tbody>
<?php foreach ($navGroups as $gidx => $group): ?>
  <tbody>
    <tr class="rm-sec-head" data-target="rm-sec-<?= $gidx ?>" style="cursor:pointer" title="Click to collapse/expand this section">
      <td style="position:sticky;left:0;font-weight:700;background:#f8fafc;border-top:1px solid #e5e7eb">
        <i class="fas fa-chevron-down rm-chevron" style="width:12px;margin-right:6px;font-size:11px;color:var(--muted,#94a3b8)"></i>
<?php if ($group['icon'] !== ''): ?><i class="fas <?= e($group['icon']) ?>" style="margin-right:6px"></i><?php endif; ?><?= e($group['section']) ?>
        <span class="note">(<?= count($group['items']) ?>)</span>
      </td>
<?php // THE ROLE NAMES AGAIN ON EVERY SECTION ROW (owner, 29 Sep): a long
      // matrix scrolls the top header out of view, and a column of dropdowns
      // with no name above it is a guess about whose access is being set. ?>
<?php foreach ($roles as $role): ?>
      <td style="text-align:center;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.02em;border-top:1px solid #e5e7eb"><?= e($roleLabel[$role] ?? $role) ?>
<?php if ($canEdit): // SET THE WHOLE SECTION FOR ONE ROLE (owner, 29 Sep): tick the box and
      // every row of this section, for this role, takes the value picked
      // here. It posts nothing itself — the rows below are what save. ?>
        <div class="rm-bulk-wrap" style="display:flex;flex-wrap:nowrap !important;gap:4px;align-items:center;justify-content:center;margin-top:4px;text-transform:none;font-weight:400">
          <input type="checkbox" class="rm-bulk-on" data-role="<?= e($role) ?>" data-sec="rm-sec-<?= $gidx ?>" title="Apply to every item in this section">
          <select class="rm-bulk" data-role="<?= e($role) ?>" data-sec="rm-sec-<?= $gidx ?>" style="flex:1 1 auto;min-width:0;max-width:120px;font-size:12px">
<?php foreach ($stateOrder as $state): ?>
            <option value="<?= e($state) ?>"><?= e($stateLabel[$state]) ?></option>
<?php endforeach; ?>
          </select>
        </div>
<?php endif; ?>
      </td>
<?php endforeach; ?>
    </tr>
  </tbody>
  <tbody id="rm-sec-<?= $gidx ?>">
<?php foreach ($group['items'] as $gi): ?>
<?php if ($gi['open'] ?? false): ?>
    <tr>
      <td style="position:sticky;left:0;background:#fff;padding-left:22px">&mdash; <?= e($gi['label']) ?></td>
      <td colspan="<?= count($roles) ?>" style="text-align:center;color:var(--muted,#94a3b8);font-style:italic">
        Open to every signed-in user &mdash; no permission gate, nothing to grant or deny here
      </td>
    </tr>
<?php continue; endif; $elsewhere = array_values(array_diff($allLocations[$gi['code']] ?? [],
        [$group['section'] . ' → ' . $gi['label']])); ?>
    <tr>
      <td style="position:sticky;left:0;background:#fff;padding-left:22px">&mdash; <?= e($gi['label']) ?>
<?php if ($elsewhere !== []): ?> <i class="fas fa-link" style="font-size:11px;color:var(--muted,#94a3b8)"
    title="Same permission as <?= e(implode(', ', $elsewhere)) ?> — changing either changes both"></i><?php endif; ?></td>
<?php foreach ($roles as $role): ?>
      <?= $cell($role, $gi['code'], $gi['ref']) ?>
<?php endforeach; ?>
    </tr>
<?php endforeach; ?>
  </tbody>
<?php endforeach; ?>
</table></div>


<script>
(function () {
  // COLLAPSE/EXPAND PER SECTION (owner's instruction, 19 Sep) — every menu
  // section now genuinely lists every item under it (see the PHP above), so
  // the table is longer than it used to be; this is here so finding one
  // section's rows doesn't mean scrolling past all the others. Kept outside
  // the edit-only block below: a read-only viewer gets the same
  // navigation aid, not just the person who can save changes.
  var heads = Array.prototype.slice.call(document.querySelectorAll('.rm-sec-head'));

  function setOpen(head, open) {
    var body = document.getElementById(head.dataset.target);

    if (!body) {
      return;
    }

    body.style.display = open ? '' : 'none';

    var chevron = head.querySelector('.rm-chevron');

    if (chevron) {
      chevron.classList.toggle('fa-chevron-down', open);
      chevron.classList.toggle('fa-chevron-right', !open);
    }
  }

  heads.forEach(function (head) {
    head.addEventListener('click', function () {
      var body = document.getElementById(head.dataset.target);
      setOpen(head, !!body && body.style.display === 'none');
    });
  });

  var expandAll = document.getElementById('rm-expand-all');
  var collapseAll = document.getElementById('rm-collapse-all');

  if (expandAll) {
    expandAll.addEventListener('click', function () {
      heads.forEach(function (head) { setOpen(head, true); });
    });
  }

  if (collapseAll) {
    collapseAll.addEventListener('click', function () {
      heads.forEach(function (head) { setOpen(head, false); });
    });
  }
})();
</script>

<?php if ($canEdit): ?>
  <div class="row" style="gap:10px;margin-top:6px;align-items:center">
    <input type="text" name="note" placeholder="Why (optional) &mdash; kept with this version" style="flex:1;max-width:420px">
    <button type="submit" class="btn btn-primary"><i class="fas fa-floppy-disk"></i> Save changes</button>
  </div>
</form>
<script>
(function () {
  var cells = Array.prototype.slice.call(document.querySelectorAll('.rm-cell'));

  // A permission code shared by more than one row is one switch wearing
  // several labels — moving any one of its dropdowns moves every other cell
  // for that same code+role, including the one (and only one, per code) that
  // actually has a `name` and posts.
  cells.forEach(function (cell) {
    cell.addEventListener('change', function () {
      var code = cell.dataset.code, role = cell.dataset.role, val = cell.value;

      cells.forEach(function (other) {
        if (other !== cell && other.dataset.code === code && other.dataset.role === role) {
          other.value = val;
        }
      });
    });
  });

  // SECTION-WIDE SET: ticked box + value = every row in that section for that
  // role. Clicks inside do not collapse the section header they sit in.
  function applyBulk(box) {
    if (!box.checked) { return; }
    var sel = box.parentNode.querySelector('.rm-bulk');
    var body = document.getElementById(box.dataset.sec);
    if (!body || !sel) { return; }
    body.querySelectorAll('.rm-cell[data-role="' + box.dataset.role + '"]').forEach(function (c) {
      c.value = sel.value;
      c.dispatchEvent(new Event('change'));
    });
  }

  document.querySelectorAll('.rm-bulk-wrap').forEach(function (wrap) {
    wrap.addEventListener('click', function (e) { e.stopPropagation(); });
    var box = wrap.querySelector('.rm-bulk-on');
    box.addEventListener('change', function () { applyBulk(box); });
    wrap.querySelector('.rm-bulk').addEventListener('change', function () { applyBulk(box); });
  });
})();
</script>
<?php endif; ?>
<style>
/* ACCESS COLOURS (owner, 22 Oct 2026): every access dropdown is tinted by what it grants. */
select.rm-cell[data-state="none"], select.rm-bulk[data-state="none"] { background-color: #fee2e2 !important; border-color: #fca5a5 !important; }
select.rm-cell[data-state="view"], select.rm-bulk[data-state="view"] { background-color: #ede9fe !important; border-color: #a78bfa !important; }
select.rm-cell[data-state="edit"], select.rm-bulk[data-state="edit"] { background-color: #fef3c7 !important; border-color: #fcd34d !important; }
select.rm-cell[data-state="full"], select.rm-bulk[data-state="full"] { background-color: #bbf7d0 !important; border-color: #22c55e !important; }
</style>
<script>
(function () {
  function paint(s) { s.setAttribute('data-state', s.value); }
  document.querySelectorAll('select.rm-cell, select.rm-bulk').forEach(function (s) {
    paint(s);
    s.addEventListener('change', function () { paint(s); });
  });
  // Rows changed by a section's bulk setter or a linked twin are set from script: repaint after any change.
  function repaintAll() { setTimeout(function () { document.querySelectorAll('select.rm-cell, select.rm-bulk').forEach(paint); }, 0); }
  document.addEventListener('change', repaintAll, true);
  document.addEventListener('click', repaintAll, true);
})();
</script>
