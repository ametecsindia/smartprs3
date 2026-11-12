<?php
// "+ ADD NEW…" ON A DROPDOWN (owner, 2 Nov 2026): Modules::addOption keeps the value under the field's `addable` list
// (versioned customisation), every field naming that list offers it, a duplicate in any case is not added twice.
// Run: php tests/dropdown_add_check.php
declare(strict_types=1);
require_once __DIR__ . '/lib/bootstrap.php';
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\RowStore;

class DdMem implements RowStore {
  public int $next = 1;
  public array $d = [];
  function forTenant(string $t): self { return $this; }
  function tenantId(): ?string { return null; }
  function all(string $c): array { return array_values($this->d[$c] ?? []); }
  function page(string $collection, string $orderBy, int $limit, ...$x): array { return array_slice($this->all($collection), 0, $limit); }
  function find(string $c, string $id): ?array { return $this->d[$c][$id] ?? null; }
  function put(string $c, array $row): array { $id = (string) ($row['id'] ?? $this->next++); $row['id'] = $id; $this->d[$c][$id] = array_merge($this->d[$c][$id] ?? [], $row); return $this->d[$c][$id]; }
  function count(string $c, array $s = [], string $n = ''): int { return count($this->d[$c] ?? []); }
  function aggregate(string $collection, ...$x): array { return []; }
  function columnsOf(string $c): array { return $c === 'incentive_schemes' ? ['id', 'name', 'purpose', 'status', 'basis', 'rate_type', 'scheme_kind', 'eligible_salary_types'] : ($c === 'commissions' ? ['id', 'purpose', 'month'] : []); }
}
$f = []; $n = 0;
$ok = function (bool $c, string $what) use (&$f, &$n): void { $n++; $c || $f[] = $what; };
$store = new DdMem();
$mods = new Modules($store, __DIR__ . '/../config/modules.json', new Customisation($store));
$by = array_column(json_decode((string) file_get_contents(__DIR__ . '/../config/modules.d/leave-comp.json'), true)['modules'], null, 'key');
$opts = static fn (string $k) => array_column($mods->fields($by[$k])['fields'], 'options', 'name')['purpose'] ?? [];

$r = $mods->addOption($by['commission-setup'], 'purpose', '  Card   Collection ', 'u1', 'admin');
$ok($r['ok'] && $r['value'] === 'Card Collection', 'added, spaces tidied: ' . $r['why']);
// Commission Entries take Purpose from the chosen scheme (hidden there), so the setups are where it is offered.
foreach (['commission-setup', 'incentive-setup'] as $k) {
    $ok(in_array('Card Collection', $opts($k), true), "offered on {$k}");
}
$ok(array_column($mods->fields($by['commission-setup'])['fields'], 'addable', 'name')['purpose'] === 'scheme_purpose', 'field says addable');
$r2 = $mods->addOption($by['incentive-setup'], 'purpose', 'card collection', 'u1', 'admin');
$ok($r2['ok'] && $r2['value'] === 'Card Collection' && count(array_keys($opts('commission-setup'), 'Card Collection', true)) === 1, 'duplicate (any case) not added twice');
$r3 = $mods->addOption($by['incentive-setup'], 'purpose', 'emi collection', 'u1', 'admin');
$ok($r3['value'] === 'EMI Collection', 'shipped value matched, not duplicated');
$ok(!$mods->addOption($by['commission-setup'], 'purpose', '', 'u1', 'admin')['ok'], 'blank refused');
$ok(!$mods->addOption($by['commission-setup'], 'purpose', str_repeat('x', 61), 'u1', 'admin')['ok'], 'over 60 refused');
$ok(!$mods->addOption($by['commission-setup'], 'status', 'Paused', 'u1', 'admin')['ok'], 'a field without addable refuses');
$s = $mods->save($by['commission-setup'], ['name' => 'Cards', 'purpose' => 'Card Collection', 'basis' => 'Manual', 'rate_type' => 'Percent']);
$ok($s['ok'] && ($s['row']['purpose'] ?? '') === 'Card Collection', 'saves with the new value: ' . ($s['why'] ?? ''));
$ok(count((new Customisation($store))->history(Modules::ADDITIONS_KEY)) === 1, 'one versioned change for one real addition');

echo ($n - count($f)) . ' / ' . count($f) . " (passed / failed)\n";
foreach ($f as $x) { echo "  FAIL: {$x}\n"; }
exit($f === [] ? 0 : 1);
