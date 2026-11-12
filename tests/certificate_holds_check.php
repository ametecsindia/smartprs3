<?php
declare(strict_types=1);
// Self-check for surface/CertificateHolds.php. Run: php -d zend.assertions=1 tests/certificate_holds_check.php
$root = $argv[1] ?? dirname(__DIR__);
spl_autoload_register(static function (string $c) use ($root): void {
    foreach (['SmartPRS\\Platform\\' => '/src/Platform/', 'SmartPRS\\Surface\\' => '/surface/'] as $p => $d) {
        if (str_starts_with($c, $p)) { $f = $root . $d . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) { require_once $f; } }
    }
});
use SmartPRS\Surface\{RowStore, Customisation, CertificateHolds};
final class FakeStore implements RowStore {
    public function __construct(public array $data) {}
    public function forTenant(string $t): RowStore { return $this; }
    public function tenantId(): ?string { return '1'; }
    public function all(string $c): array { return $this->data[$c] ?? []; }
    public function page(string $collection, string $orderBy, int $limit, int $offset = 0, string $direction = 'asc', array $searchColumns = [], string $needle = ''): array { return []; }
    public function find(string $c, string $id): ?array { return null; }
    public function put(string $c, array $row): array { return $row; }
    public function count(string $collection, array $searchColumns = [], string $needle = ''): int { return 0; }
    public function aggregate(string $collection, string $column = '', array $where = [], string $groupBy = ''): array { return []; }
    public function columnsOf(string $collection): array { return []; }
}
$line = fn ($code, $m) => ['code' => $code, 'kind' => 'x', 'minor' => $m, 'amount' => ''];
$runs = [
    ['period' => '2026-07', 'status' => 'approved', 'rows' => ['E1' => ['lines' => [$line('BASIC', 100000), $line('DRA_DEDUCTION', 50000)]]]],
    ['period' => '2026-08', 'status' => 'approved', 'rows' => json_encode(['E1' => ['lines' => [$line('DRA_DEDUCTION', 50000)]]])],
    ['period' => '2026-09', 'status' => 'discarded', 'rows' => ['E1' => ['lines' => [$line('DRA_DEDUCTION', 50000)]]]],
];
$rules = ['rules' => [['key' => 'dra', 'label' => 'DRA', 'active' => true, 'deduction_code' => 'DRA_DEDUCTION', 'refund_code' => 'DRA_REFUND',
    'status_field' => 'dra_status', 'cleared_values' => ['Submitted', 'Verified'], 'on_exit' => 'forfeit']]];
$store = new FakeStore(['payroll_runs' => $runs, 'payroll_corrections' => [],
    'module_customisations' => [['module_key' => 'certificate-holds', 'version' => 1, 'payload' => json_encode($rules)]]]);
$cfg = tempnam(sys_get_temp_dir(), 'ch'); file_put_contents($cfg, json_encode(['customisation_key' => 'certificate-holds']));
$h = new CertificateHolds($store, new Customisation($store), $cfg);

$l = $h->ledger('2026-10');
assert($l['E1']['dra']['held'] === 100000, 'discarded month not counted');
// pending: nothing paid
assert($h->forEmployment(['employee_code' => 'E1', 'emp_code' => 'E1', 'code' => 'E1', 'dra_status' => 'Pending'] , '2026-10', '2026-10-31')['lines'] === []);
// submitted: one refund of everything held
$e = ['dra_status' => 'Submitted'] + array_fill_keys(['employee_code', 'emp_code', 'code', 'emp_id'], 'E1');
$r = $h->forEmployment($e, '2026-10', '2026-10-31');
assert(count($r['lines']) === 1 && $r['lines'][0]['minor'] === 100000 && $r['lines'][0]['code'] === 'DRA_REFUND', 'refund all held');
// after the refund month closed, nothing more is paid
$store->data['payroll_runs'][] = ['period' => '2026-10', 'status' => 'approved', 'rows' => ['E1' => ['lines' => [$line('DRA_REFUND', 100000)]]]];
assert($h->forEmployment($e, '2026-11', '2026-11-30')['lines'] === [], 'never paid twice');
// regenerating October itself still gives the same refund
assert($h->forEmployment($e, '2026-10', '2026-10-31')['lines'][0]['minor'] === 100000, 'regenerate is identical');
// left without certificate: forfeit -> concern, no line
$x = ['dra_status' => 'Pending', 'exit_date' => '2026-10-15'] + $e;
$f = $h->forEmployment($x, '2026-10', '2026-10-31');
assert($f['lines'] === [] && count($f['concerns']) === 1, 'forfeit on exit');
echo "certificate holds: all checks passed\n";
