<?php
declare(strict_types=1);
// ASSET STOCK ⇄ ISSUE & RETURNS, like a library: counts come from unit rows,
// issue takes a unit off the shelf, return puts it back (or to repair / lost).
// Run: php tests/assets_library_test.php
namespace SmartPRS\Surface;

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; } else { require_once $root . '/src/Platform/Support/Money.php'; require_once $root . '/surface/Amount.php'; }
require_once $root . '/surface/RowStore.php';
require_once $root . '/surface/Modules.php';
require_once $root . '/surface/Assets.php';

final class MemStore implements RowStore
{
    public array $t = [];
    private int $n = 0;
    public function forTenant(string $tenantId): self { return $this; }
    public function tenantId(): ?string { return 't'; }
    public function all(string $c): array { return array_values($this->t[$c] ?? []); }
    public function page(string $collection, string $orderBy, int $limit, int $offset = 0, string $direction = 'asc', array $searchColumns = [], string $needle = ''): array { return []; }
    public function find(string $c, string $id): ?array { return $this->t[$c][$id] ?? null; }
    public function put(string $c, array $row): array { $id = (string) ($row['id'] ?? ++$this->n); $row['id'] = $id; $this->t[$c][$id] = array_merge($this->t[$c][$id] ?? [], $row); return $this->t[$c][$id]; }
    public function count(string $collection, array $searchColumns = [], string $needle = ''): int { return 0; }
    public function aggregate(string $collection, string $column = '', array $where = [], string $groupBy = ''): array { return []; }
    public function columnsOf(string $collection): array { return ['returned_from_employee_id', 'branch_id']; }
}

$pass = 0;
// Under tests/run.php the suite's own ok() counts it (a failure here used to exit the run unseen);
// standalone (php tests/assets_library_test.php) it still exits non-zero on the first failure.
$ok = function_exists('ok')
    ? static function (bool $c, string $what) use (&$pass): void { \group('assets library'); \ok($c, $what); $pass++; }
    : static function (bool $c, string $what) use (&$pass): void { if (!$c) { fwrite(STDERR, "FAIL: $what\n"); exit(1); } $pass++; };
$s = new MemStore();
$s->put('employees', ['id' => '7', 'emp_code' => 'E7', 'name' => 'Asha', 'status' => 'active']);
$s->put('employees', ['id' => '8', 'emp_code' => 'E8', 'name' => 'Ravi', 'status' => 'active']);
foreach (['L1', 'L2', 'L3'] as $tag) { $s->put('assets', ['asset_id' => $tag, 'asset_type' => 'Laptop', 'make_model' => 'Dell 5420']); } // no status: register form
$a = new Assets($s, $root . '/config/assets.json');
$count = static fn (string $st): int => count($a->inStatus($st));
$id = static fn (string $tag): string => (string) array_values(array_filter($s->all('assets'), static fn ($r) => $r['asset_id'] === $tag))[0]['id'];

$ok($count('in stock') === 3, 'three blank-status units read as available');
$r = $a->move($s->find('assets', $id('L1')), 'issue', ['employee_id' => '7', 'purpose' => 'field'], 'admin', '2026-09-29 10:00:00');
$ok($r['ok'], 'issue L1');
$ok($count('in stock') === 2 && $count('assigned') === 1, 'issue moves one off the shelf');
$ok($s->find('assets', $id('L1'))['purpose'] === 'field', 'purpose kept');
$again = $a->move($s->find('assets', $id('L1')), 'issue', ['employee_id' => '8'], 'admin', '2026-09-29 10:01:00');
$ok(!$again['ok'], 'an issued unit cannot be issued again');
$ok(count($a->heldBy('7')) === 1, 'Asha holds one');
$ok($a->move($s->find('assets', $id('L1')), 'return', ['condition' => 'good'], 'admin', '2026-09-30 10:00:00')['ok'], 'return good');
$ok($count('in stock') === 3 && $count('assigned') === 0 && $a->heldBy('7') === [], 'good return is back on the shelf');
$a->move($s->find('assets', $id('L2')), 'issue', ['employee_id' => '8'], 'admin', '2026-09-29 10:00:00');
$a->move($s->find('assets', $id('L2')), 'return', ['condition' => 'damaged', 'damage_resolution' => 'Replaced'], 'admin', '2026-09-30 10:00:00');
$ok($count('under repair') === 1 && $count('in stock') === 2, 'damaged return goes to repair, not the shelf');
$ok($a->move($s->find('assets', $id('L2')), 'restock', [], 'admin', '2026-10-01 10:00:00')['ok'] && $count('in stock') === 3, 'repaired unit comes back');
$a->move($s->find('assets', $id('L3')), 'issue', ['employee_id' => '8'], 'admin', '2026-09-29 10:00:00');
$a->move($s->find('assets', $id('L3')), 'return', ['condition' => 'lost', 'damage_resolution' => 'Written off', 'recovery_amount' => '1500', 'recovery_status' => 'On hold'], 'admin', '2026-09-30 10:00:00');
$ok($count('lost') === 1 && $count('in stock') === 2, 'lost return is out of stock');
$book = $a->stockBook();
$ok(count($book) === 1 && $book[0]['total'] === 3 && array_sum($book[0]['counts']) === 3, 'stock book adds up');
$ok($a->move($s->find('assets', $id('L3')), 'retire', [], 'admin', '2026-10-02 10:00:00')['ok'] && $a->stockBook()[0]['total'] === 2, 'retired leaves the count');
$s->put('assets', ['asset_id' => 'L4', 'asset_type' => 'Laptop', 'make_model' => 'HP 840']);
$s->put('assets', ['asset_id' => 'L5', 'asset_type' => 'laptop', 'make_model' => 'HP 840']);
$s->put('assets', ['asset_id' => 'M1', 'asset_type' => 'Monitor', 'make_model' => 'Dell P24']);
$s->put('assets', ['asset_id' => 'M2', 'asset_type' => 'Monitor', 'make_model' => 'Dell P24']);
$ok($a->move($s->find('assets', $id('L4')), 'issue', ['employee_id' => '7'], 'admin', '2026-09-29 11:00:00')['ok'], 'first laptop issues');
$second = $a->move($s->find('assets', $id('L5')), 'issue', ['employee_id' => '7'], 'admin', '2026-09-29 11:01:00');
$ok(!$second['ok'] && str_contains($second['why'], 'already issued'), 'second laptop refused without a yes');
$ok($a->move($s->find('assets', $id('L5')), 'issue', ['employee_id' => '7', 'reissue_ok' => '1'], 'admin', '2026-09-29 11:02:00')['ok'], 'second laptop with a yes');
$ok($a->move($s->find('assets', $id('M1')), 'issue', ['employee_id' => '7'], 'admin', '2026-09-29 11:03:00')['ok']
    && $a->move($s->find('assets', $id('M2')), 'issue', ['employee_id' => '7'], 'admin', '2026-09-29 11:04:00')['ok'], 'dual monitors need no confirmation');
$s->put('assets', ['asset_id' => 'K1', 'asset_type' => 'Keyboard', 'make_model' => 'K120']);
$a->move($s->find('assets', $id('K1')), 'issue', ['employee_id' => '8'], 'admin', '2026-09-29 12:00:00');
$ok(!$a->move($s->find('assets', $id('K1')), 'return', ['condition' => 'damaged'], 'admin', '2026-09-30 12:00:00')['ok'], 'damaged return needs a final status');
$ok($a->move($s->find('assets', $id('K1')), 'return', ['condition' => 'good', 'damage_resolution' => 'Replaced', 'recovery_amount' => '99'], 'admin', '2026-09-30 12:00:00')['ok']
    && ($s->find('assets', $id('K1'))['recovery_amount'] ?? null) === null, 'good return drops stray recovery answers');
// EXIT & FnF: held units and unsettled damaged/lost returns hold the exit.
$s->put('employees', ['id' => '9', 'emp_code' => 'E9', 'name' => 'Leaver', 'status' => 'active']);
$s->put('assets', ['asset_id' => 'X1', 'asset_type' => 'Laptop', 'make_model' => 'Dell']);
$s->put('assets', ['asset_id' => 'X2', 'asset_type' => 'Mouse', 'make_model' => 'M90']);
$s->put('assets', ['asset_id' => 'X3', 'asset_type' => 'Webcam', 'make_model' => 'C270']);
foreach (['X1', 'X2', 'X3'] as $tag) { $a->move($s->find('assets', $id($tag)), 'issue', ['employee_id' => '9'], 'admin', '2026-09-01 10:00:00'); }
$ok(count($a->exitBlockers('9')) === 3, 'three held units hold the exit');
$a->move($s->find('assets', $id('X1')), 'return', ['condition' => 'good'], 'admin', '2026-09-30 10:00:00');
$a->move($s->find('assets', $id('X2')), 'return', ['condition' => 'lost', 'damage_resolution' => 'Cost recovered', 'recovery_amount' => '450', 'recovery_status' => 'On hold'], 'admin', '2026-09-30 10:00:00');
$a->move($s->find('assets', $id('X3')), 'return', ['condition' => 'damaged', 'damage_resolution' => 'Waived off'], 'admin', '2026-09-30 10:00:00');
$o9 = $a->outstandingFor('9');
$ok($o9['held'] === [] && count($o9['unsettled']) === 1 && $o9['unsettled'][0]['asset_id'] === 'X2', 'only the unpaid lost mouse still holds the exit');
$ok(!$a->settleRecovery($s->find('assets', $id('X1')), ['damage_resolution' => 'Waived off'], 'admin', '2026-10-01 10:00:00')['ok'], 'a good return has nothing to settle');
$ok($a->settleRecovery($s->find('assets', $id('X2')), ['damage_resolution' => 'Cost recovered', 'recovery_amount' => '450', 'recovery_status' => 'Paid'], 'admin', '2026-10-01 10:00:00')['ok']
    && $a->exitBlockers('9') === [], 'paid recovery clears the exit');
// ISSUE ONLY WITHIN THE EMPLOYEE'S COMPANY AND BRANCH (owner, 30 Sep):
// config `issue_within` names the columns a unit and its employee must share.
$s->put('employees', ['id' => '10', 'emp_code' => 'E10', 'name' => 'Branch', 'status' => 'active', 'employer_company_id' => 'C1', 'branch_id' => 'B1']);
$s->put('assets', ['asset_id' => 'B1U', 'asset_type' => 'Mouse', 'make_model' => 'M1', 'employer_company_id' => 'C1', 'branch_id' => 'B1']);
$s->put('assets', ['asset_id' => 'B2U', 'asset_type' => 'Mouse', 'make_model' => 'M1', 'employer_company_id' => 'C1', 'branch_id' => 'B2']);
$s->put('assets', ['asset_id' => 'C2U', 'asset_type' => 'Mouse', 'make_model' => 'M1', 'employer_company_id' => 'C2', 'branch_id' => 'B1']);
$ok(!$a->move($s->find('assets', $id('B2U')), 'issue', ['employee_id' => '10'], 'admin', '2026-09-30 10:00:00')['ok'], 'another branch\'s unit is refused');
$ok(!$a->move($s->find('assets', $id('C2U')), 'issue', ['employee_id' => '10'], 'admin', '2026-09-30 10:00:00')['ok'], 'another company\'s unit is refused');
$ok($a->move($s->find('assets', $id('B1U')), 'issue', ['employee_id' => '10'], 'admin', '2026-09-30 10:00:00')['ok'], 'own company + branch unit is issued');
$ok(in_array('branch_id', $a->issueWithin(), true) && in_array('employer_company_id', $a->issueWithin(), true), 'issueWithin reads config');
// TRANSFER BETWEEN BRANCHES / COMPANIES (owner, 30 Sep): stock on the shelf
// or in repair moves; an issued unit must come back first.
$s->put('branches', ['id' => 'B1', 'employer_company_id' => 'C1', 'name' => 'One']);
$s->put('branches', ['id' => 'B2', 'employer_company_id' => 'C1', 'name' => 'Two']);
$s->put('branches', ['id' => 'B9', 'employer_company_id' => 'C2', 'name' => 'Nine']);
$tr = $a->transfer($s->find('assets', $id('B2U')), 'C1', 'B1', 'moving', 'admin', '2026-09-30 11:00:00');
$ok($tr['ok'] && $s->find('assets', $id('B2U'))['branch_id'] === 'B1', 'shelf unit moves to another branch');
$ok(str_contains((string) $s->find('assets', $id('B2U'))['notes'], 'moving'), 'the move is written on the unit');
$ok(!$a->transfer($s->find('assets', $id('B1U')), 'C1', 'B2', '', 'admin', '2026-09-30 11:00:00')['ok'], 'an issued unit cannot be transferred');
$ok(!$a->transfer($s->find('assets', $id('C2U')), 'C1', 'B9', '', 'admin', '2026-09-30 11:00:00')['ok'], 'branch must belong to the company');
$ok(!$a->transfer($s->find('assets', $id('B2U')), 'C1', 'B1', '', 'admin', '2026-09-30 11:00:00')['ok'], 'already there is refused');
$ok($a->transfer($s->find('assets', $id('C2U')), 'C1', 'B2', '', 'admin', '2026-09-30 11:00:00')['ok'] && $s->find('assets', $id('C2U'))['employer_company_id'] === 'C1', 'moves to another company');
function_exists('ok') || print("assets_library_test: {$pass} passing\n");
