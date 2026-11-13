<?php
/**
 * THE PAGE INSIDE THE SMARTPRS3 ANDROID APP (owner, 5 Nov 2026) — included at the end of every signed-in page when the
 * app's cookie sprs_app_device is present (the device gate itself is boot-hooks). It behaves as a phone app, not a
 * browser page: no side menu, a slim top bar (screen title, bell, profile), and a fixed bottom bar — Home (the icon grid,
 * surface/screens/app-home.php), Punch, Leave, Alerts (with the unread count) and Profile. Same screens and permissions
 * as the web underneath.
 */
declare(strict_types=1);

$amRoute = (string) ($rawRoute ?? '');
$amTabs = array_values(array_filter([
    ['app-home', 'fa-house', 'Home', true],
    ['punch', 'fa-fingerprint', 'Punch', true], // every login (owner, 13 Nov 2026)
    // MY ATTENDANCE (owner, 12 Nov 2026): own days, punches in / out, worked and late — a tab of its own.
    ['my-attendance', 'fa-calendar-check', 'Attendance', $may('ess.attendance.punch') && trim((string) ($session->user()['employee_id'] ?? '')) !== ''],
    ['leave', 'fa-umbrella-beach', 'Leave', $may('leave.request.view')],
    ['notifications', 'fa-bell', 'Alerts', true],
    ['account-settings', 'fa-user', 'Profile', true],
], static fn (array $t): bool => $t[3]));
$amUnread = (int) ($bellUnread ?? 0);
// THE COMPANY LOGO TOP-RIGHT (owner, 12 Nov 2026): Branding's logo, else the signed-in employee's own company logo.
$amCoLogo = trim((string) ($brandLogo ?? ''));
if ($amCoLogo === '' && ($amOwn = $scope->own()) !== null) {
    $amEmp = $store->find('employees', $amOwn);
    $amCoId = (string) ($amEmp['employer_company_id'] ?? '');
    $amCo = $amCoId === '' ? null : $store->find('companies', $amCoId);
    $amCoLogo = trim((string) ($amCo['logo_path'] ?? ''));
}
?>
<style>
  body.in-app .sidebar, body.in-app #nav-toggle, body.in-app .topbar-search, body.in-app .breadcrumb, body.in-app .topbar .page-crumbs { display:none !important; }
  body.in-app .main-wrap { margin-left:0 !important; }
  /* THE PAGE SCROLLS AS ONE (owner, 6 Nov 2026: the bottom bar slid away while scrolling): overflow-x:hidden on <body>
     can turn the body into its own scrolling box inside a phone web view; clip keeps the window the only scroller. */
  html, body.in-app { overflow-x:clip !important; overflow-y:visible !important; height:auto !important; }
  body.in-app .topbar { padding:8px 12px !important; min-height:52px; position:sticky; top:0; z-index:50; }
  body.in-app .content { padding:12px 12px 112px !important; }
  body.in-app .page-header { flex-wrap:wrap; gap:8px; }
  body.in-app .card { border-radius:14px; }
  .app-tabs { position:fixed; left:0; right:0; bottom:0; z-index:9000; transform:none; display:flex; background:var(--card,#fff); border-top:1px solid var(--border,#e2e8f0);
    box-shadow:0 -6px 18px rgba(15,23,42,.08); padding:6px 4px calc(4px + env(safe-area-inset-bottom)); flex-wrap:wrap; }
  /* POWERED BY AMETECS under the bottom bar on every app screen (owner, 6 Nov 2026) */
  .app-powered { flex:0 0 100%; width:100%; display:flex; align-items:center; justify-content:center; text-align:center; gap:6px; padding:5px 0 1px; margin-top:4px; border-top:1px solid var(--border,#e2e8f0); font-size:10.5px; color:var(--text3,#64748b); }
  .app-powered img { height:14px; width:auto; }
  .app-powered .lg-dark, [data-mode=dark] .app-powered .lg-light { display:none; }
  [data-mode=dark] .app-powered .lg-dark { display:inline; }
  .app-tabs a { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; text-decoration:none; color:var(--text3,#64748b); font-size:11px; font-weight:600; position:relative; padding:4px 0; }
  .app-tabs a i { font-size:19px; }
  .app-tabs a.on { color:var(--accent); }
  .app-tabs a.on i { transform:translateY(-1px); }
  .app-tabs a b { position:absolute; top:0; left:calc(50% + 6px); min-width:17px; height:17px; padding:0 4px; border-radius:9px; background:#dc2626; color:#fff; font-size:10px; line-height:17px; text-align:center; }
  @media print { .app-tabs { display:none; } }
  .app-selfie { display:inline-flex; align-items:center; gap:6px; margin:0 0 6px; font-weight:700; }
  /* COMPACT CARDS, NO RUNNING TEXT (owner, 6 Nov 2026: "the dashboard cards look very large … remove the descriptive
     text … in all the modules"). Phone only — the website keeps its layout and notes. Format hints under boxes and
     message text inside tables / lists stay. */
  body.in-app .dash-kpis { display:grid !important; grid-template-columns:repeat(3,minmax(0,1fr)) !important; gap:8px !important; margin-bottom:12px !important; }
  body.in-app .dash-kpis .kpi.dk { padding:10px 10px 9px !important; gap:6px !important; border-radius:12px !important; min-height:0 !important; }
  body.in-app .dash-kpis .dk-top { gap:6px !important; }
  body.in-app .dash-kpis .dk-ic { width:26px !important; height:26px !important; font-size:12px !important; border-radius:8px !important; }
  body.in-app .dash-kpis .dk-label { font-size:10.5px !important; letter-spacing:.02em !important; }
  body.in-app .dash-kpis .dk-val { font-size:22px !important; }
  body.in-app .dash-kpis .dk-foot { display:none !important; }
  body.in-app .stats-grid { grid-template-columns:repeat(2,minmax(0,1fr)) !important; gap:8px !important; margin-bottom:12px !important; }
  body.in-app .stat-card { padding:10px 12px !important; }
  body.in-app .stat-card .icon { width:26px !important; height:26px !important; font-size:12px !important; margin-bottom:6px !important; }
  body.in-app .stat-card h3 { font-size:20px !important; }
  body.in-app .kpi { padding:10px 12px !important; }
  body.in-app .content .page-header p,
  body.in-app .content .card-header p,
  body.in-app .content p.note,
  body.in-app .content .stat-card p,
  body.in-app .content div.note:not(.fmt-hint):not(td div):not(li div):not(.problem) { display:none !important; }
  body.in-app .content h2 { font-size:18px; }
  /* TOP BAR (owner, 6 Nov 2026): SmartPRS logo, the company logo from Branding beside it, then the bell, theme and
     profile icons. The screen name is not repeated on Home (Home is in the bottom bar). */
  .app-marks { display:flex; align-items:center; gap:8px; margin-right:6px; flex-shrink:0; }
  .app-marks img { height:24px; width:auto; display:block; }
  /* the company logo sits at the RIGHT end of the top row, SmartPRS stays in the left corner (owner, 6 Nov 2026) */
  .topbar > .app-co-logo { height:26px; width:auto; flex:0 1 64px; min-width:36px; max-width:80px; object-fit:contain; object-position:right center; display:block; margin-left:6px; order:5; }
  /* ONE ROW whatever the phone width (6 Nov 2026: the company logo dropped to the next line) */
  body.in-app .topbar { padding:6px 10px !important; }
  body.in-app .topbar .topbar-actions { gap:4px !important; flex-wrap:nowrap; }
  /* NO HELP (i) ICONS IN THE APP (owner, 6 Nov 2026: too little room on a phone) */
  body.in-app #help-i, body.in-app .help-i, body.in-app [title="How this screen works"] { display:none !important; }
  body.in-app .topbar .topbar-actions .topbar-btn { width:32px !important; height:32px !important; min-width:0 !important; padding:0 !important; }
  .app-marks .sp-dark, [data-mode=dark] .app-marks .sp-light { display:none; }
  [data-mode=dark] .app-marks .sp-dark { display:block; }
  body.in-app.app-on-home .topbar .page-title { display:none; }
  /* SCREEN NAME ON ITS OWN LINE under the logo row, smaller but bold (owner, 6 Nov 2026) */
  body.in-app .topbar { flex-wrap:wrap; row-gap:2px; }
  body.in-app .topbar > div:has(> .page-title) { order:10; flex:0 0 100% !important; min-width:0; }
  body.in-app .topbar .page-title { font-size:13.5px !important; font-weight:700 !important; line-height:1.3; }
  body.in-app .topbar .topbar-actions { margin-left:auto; }
  /* THE HEADER IS FIXED ON EVERY SCREEN (owner, 12 Nov 2026): SmartPRS logo top-left, the company's logo (Branding) top-right,
     never scrolling away. Its height is measured below and the page starts under it. */
  body.in-app .topbar { position:fixed !important; top:0; left:0; right:0; z-index:8000; background:var(--card,#fff); box-shadow:0 2px 10px rgba(15,23,42,.06); }
  body.in-app .main-wrap { padding-top:var(--app-head,60px); }
  .app-marks { order:-1; }
  /* ONE COMPACT TYPE SCALE ON EVERY APP SCREEN (owner, 12 + 13 Nov 2026: "all the text looks large … titles going to 2 rows").
     Body 13px, screen title 15px, sections 14px, labels / tables 12px, badges 10.5px — phone-app sizes, not website sizes;
     inline sizes on screens follow it, and a title never breaks inside a word. */
  body.in-app { font-size:13px; line-height:1.4; }
  body.in-app .content, body.in-app .content p, body.in-app .content li, body.in-app .content div, body.in-app .content span,
  body.in-app .content label, body.in-app .content b, body.in-app .content strong { font-size:13px; }
  body.in-app .content [style*="font-size"]:not(i):not(.fas):not(.far):not(.fab) { font-size:13px !important; }
  body.in-app .content h1, body.in-app .content h2, body.in-app .content .page-header h2 { font-size:15px !important; font-weight:700; line-height:1.3; margin-bottom:6px; }
  body.in-app .content h3, body.in-app .content h4, body.in-app .content legend { font-size:14px !important; font-weight:700; }
  body.in-app .content h1, body.in-app .content h2, body.in-app .content h3, body.in-app .content h4 { overflow-wrap:normal; word-break:normal; hyphens:none; }
  body.in-app .content label { font-size:12px !important; font-weight:600; color:var(--text2,#334155); margin-bottom:2px; }
  body.in-app .content input, body.in-app .content select, body.in-app .content textarea,
  body.in-app .content button, body.in-app .content .btn, body.in-app .content .go { font-size:13px !important; }
  body.in-app .content input:not([type=checkbox]):not([type=radio]):not([type=file]), body.in-app .content select { min-height:36px; padding-top:6px; padding-bottom:6px; }
  body.in-app .content .btn, body.in-app .content .go, body.in-app .content button { min-height:34px; border-radius:9px; padding-top:6px; padding-bottom:6px; }
  body.in-app .content table, body.in-app .content th, body.in-app .content td,
  body.in-app .content small, body.in-app .content .note, body.in-app .content .fmt-hint { font-size:12px !important; }
  body.in-app .content th { white-space:nowrap; }
  body.in-app .content .badge { font-size:10.5px !important; }
  body.in-app .content .card { padding:12px !important; margin-bottom:10px !important; }
  body.in-app .content .dk-val, body.in-app .content .stat-card h3 { font-size:18px !important; }
  body.in-app .content .dk-label { font-size:10.5px !important; }
  body.in-app .topbar .page-title { font-size:13px !important; }
  .app-tabs a { font-size:10px !important; }
  .app-tabs a i { font-size:17px !important; }
</style>
<nav class="app-tabs" aria-label="App">
<?php foreach ($amTabs as [$amR, $amI, $amL]): ?>
  <a href="?r=<?= e($amR) ?>" class="<?= $amRoute === $amR || ($amR === 'app-home' && $amRoute === 'dashboard') ? 'on' : '' ?>"><i class="fas <?= e($amI) ?>"></i><?= e($amL) ?><?php if ($amR === 'notifications' && $amUnread > 0): ?><b><?= $amUnread > 99 ? '99+' : $amUnread ?></b><?php endif; ?></a>
<?php endforeach; ?>
  <div class="app-powered">Powered by <img class="lg-light" src="images/ametecs-logo.png" alt="Ametecs"><img class="lg-dark" src="images/ametecs-logo-white.png" alt="Ametecs"></div>
</nav>
<?php if ($amRoute === 'account-settings'): ?>
<div class="card" id="app-signout" style="margin-top:14px;text-align:center">
  <button type="button" class="btn btn-outline" style="width:100%;color:#dc2626;border-color:#fca5a5;font-weight:700;padding:12px"
    onclick="if (confirm('Sign out of the app?')) { document.getElementById('sp-signout-form').submit(); }"><i class="fas fa-right-from-bracket"></i> Sign out</button>
<?php // APP VERSION (owner, 13 Nov 2026): the app names itself in its user agent ("SmartPRS3-Mobile/1.5"); the server's from config/version.json.
  $amAppVer = preg_match('#SmartPRS3-Mobile/([0-9][0-9A-Za-z.\-]*)#', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $amV) === 1 ? $amV[1] : '—';
  $amSrvVer = (string) ((json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/config/version.json'), true) ?: [])['version'] ?? ''); ?>
  <div style="margin-top:12px;font-size:12px"><a href="policy.php?p=delete-account" style="color:#94a3b8">Delete my account</a> · <a href="policy.php?p=privacy-policy" style="color:#94a3b8">Privacy policy</a></div>
  <div style="margin-top:6px;font-size:11px;color:#94a3b8">App version <?= e($amAppVer) ?><?= $amSrvVer !== '' ? ' · Server ' . e($amSrvVer) : '' ?></div>
</div>
<?php endif; ?>
<script>
(function () {
  var b = document.body;
<?php if (!$may('employee.list')): ?>
  // SELF-ONLY LOGINS SEE NO ORGANISATION FILTERS (owner, 13 Nov 2026: an employee's screens are about their own data):
  // the Company / Branch / … / Employee pickers go; a period, if the screen has one, and Show stay.
  [].forEach.call(document.querySelectorAll('form.org-filter'), function (f) {
    [].forEach.call(f.children, function (d) { if (d.querySelector && d.querySelector('[name^="f_"], .emp-multi, [name="sel"]')) { d.style.display = 'none'; } });
    var left = [].filter.call(f.querySelectorAll('select, input:not([type=hidden])'), function (i) { return i.offsetParent !== null; });
    if (left.length === 0) { f.style.display = 'none'; }
  });
<?php endif; ?>
  // THE BOTTOM BAR STAYS ON SCREEN WHILE SCROLLING (owner, 6 Nov 2026): it hangs off <body> itself, so no page wrapper
  // (an animated or scrolling container) can carry it away.
  var tabs = document.querySelector('.app-tabs');
  if (tabs && tabs.parentNode !== b) { b.appendChild(tabs); }
  // TAKE SELFIE UP FRONT (owner, 6 Nov 2026: "Camera option should be upfront, not after clicking choose file"): every
  // photo box gets its own button that opens the front camera straight away; Choose file stays beside it.
  var selfie = function (root) {
    [].forEach.call((root || document).querySelectorAll('input[type=file][data-crop="photo"]'), function (inp) {
      if (inp._selfie || inp.disabled) { return; } inp._selfie = 1;
      var bt = document.createElement('button'); bt.type = 'button'; bt.className = 'btn btn-outline btn-sm app-selfie';
      bt.innerHTML = '<i class="fas fa-camera"></i> Take selfie';
      bt.onclick = function () {
        var acc = inp.getAttribute('accept');
        inp.setAttribute('capture', 'user'); inp.setAttribute('accept', 'image/*');
        inp.click();
        setTimeout(function () { inp.removeAttribute('capture'); acc === null ? inp.removeAttribute('accept') : inp.setAttribute('accept', acc); }, 1500);
      };
      inp.parentNode.insertBefore(bt, inp);
      inp.parentNode.insertBefore(document.createElement('br'), inp);
    });
  };
  selfie(document);
  new MutationObserver(function () { selfie(document); }).observe(document.body, { childList: true, subtree: true });
  // SIGN OUT UNDER PROFILE (owner, 6 Nov 2026): the button sits at the end of the Profile screen's content.
  var so = document.getElementById('app-signout'), main = document.querySelector('.content');
  if (so && main) { main.appendChild(so); }
  b.classList.add('in-app');
  b.classList.remove('nav-hidden', 'app-nav-open');
  var tb = document.querySelector('.topbar');
  if (tb && !tb.querySelector('.app-marks')) {
    var mk = document.createElement('div'); mk.className = 'app-marks';
    mk.innerHTML = '<img class="sp-light" src="images/smartprs-logo-navy.png" alt="SmartPRS"><img class="sp-dark" src="images/smartprs-logo.png" alt="SmartPRS">';
<?php if ($amCoLogo !== ''): ?>
    var lg = document.createElement('img'); lg.className = 'app-co-logo'; lg.alt = ''; lg.src = <?= json_encode($amCoLogo, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    tb.appendChild(lg);
<?php endif; ?>
    tb.insertBefore(mk, tb.firstChild);
  }
<?php if (in_array($amRoute, ['app-home', 'dashboard', ''], true) && !isset($_GET['full'])): ?>
  b.classList.add('app-on-home');
<?php endif; ?>
  // the page starts just under the fixed header, whatever its height on this phone
  var fitHead = function () { var t = document.querySelector('.topbar'); t && b.style.setProperty('--app-head', t.offsetHeight + 'px'); };
  fitHead(); window.addEventListener('resize', fitHead); window.addEventListener('load', fitHead);
})();
</script>
