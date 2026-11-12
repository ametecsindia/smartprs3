<?php declare(strict_types=1);
// DEV ONLY (owner, 23 Oct 2026): 20 sample off-roll agents with realistic certificate validity / expiry, vendors,
// bank letters, pay structures, three months of activity, ledger entries and two approved payouts — see
// surface/partials/offroll-seed.php for exactly what. Run once as administrator at ?r=dev-seed-offroll-g; it
// refuses to run twice. Delete with the other dev-*.php screens after testing.
use SmartPRS\Surface\OffrollPay;

if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
@set_time_limit(0);
session_write_close();
include __DIR__ . '/../partials/offroll-boot.php';

if (!$opReady) {
    echo '<pre id="sd">The off-roll tables are not migrated yet — run ?r=dev-migrate-a first.</pre>';
    return;
}

foreach (OffrollPay::rows($store, OffrollPay::AGENTS) as $sdA) {
    if ((string) ($sdA['agent_code'] ?? '') === 'OFR-0001') {
        echo '<pre id="sd">Already seeded — nothing done.</pre>';
        return;
    }
}

$seedBy = (string) ($session->user()['email'] ?? 'admin');
include __DIR__ . '/../partials/offroll-seed.php';
?><pre id="sd"><?= e(implode("\n", $seedOut)) ?></pre>
