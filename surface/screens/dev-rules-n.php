<?php declare(strict_types=1);
// DEV ONLY (15 Oct): list salary_component_rules and the set in force on given dates; with
// &apply=close-julsep, close the rows that ran 01-07-2026 → 30-09-2026 (effective_to = 30-06-2026,
// the "never in force" mark used before — nothing deleted) after the owner republished from 01-07.
// Admin only. Delete with the other dev-*.php screens after testing.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$dn = [];
if (($_GET['apply'] ?? '') === 'close-julsep') {
    foreach ($store->all('salary_component_rules') as $r) {
        if ((string) ($r['effective_from'] ?? '') === '2026-07-01' && substr((string) ($r['effective_to'] ?? ''), 0, 10) === '2026-09-30') {
            $store->put('salary_component_rules', ['id' => $r['id'], 'effective_to' => '2026-06-30', 'updated_at' => date('Y-m-d H:i:s')]);
            $dn['closed'][] = (int) $r['id'];
        }
    }
}
foreach ($store->all('salary_component_rules') as $r) {
    $dn['rows'][] = implode('|', [$r['id'], $r['component_code'] ?? '', substr((string) ($r['effective_from'] ?? ''), 0, 10), substr((string) ($r['effective_to'] ?? ''), 0, 10), $r['scope_company'] ?? '', $r['scope_role'] ?? '', $r['scope_branch'] ?? '']);
}
foreach (['2026-07-15', '2026-08-15', '2026-09-15', '2026-10-15'] as $d) {
    $dn['in_force'][$d] = array_map(static fn (array $r): string => $r['id'] . ':' . $r['component_code'] . '@' . ($r['scope_role'] ?? '') . ($r['scope_company'] ?? ''), $componentRules->inForce($d));
}
?><pre id="dn"><?= e((string) json_encode($dn, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
