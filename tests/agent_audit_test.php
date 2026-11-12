<?php
declare(strict_types=1);
// AUDIT REPORTS — SmartPRS 2's recovery-agent compliance audit, ported.
// Run: php tests/agent_audit_test.php
namespace SmartPRS\Surface;

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) { require_once $root . '/vendor/autoload.php'; }
require_once $root . '/surface/RowStore.php';
require_once $root . '/surface/Employment.php';
require_once $root . '/surface/AgentAudit.php';

final class AuditMem implements RowStore
{
    public array $t = [];
    public function forTenant(string $tenantId): self { return $this; }
    public function tenantId(): ?string { return 't'; }
    public function all(string $c): array { if (!isset($this->t[$c])) { throw new \RuntimeException('no table ' . $c); } return array_values($this->t[$c]); }
    public function page(string $collection, string $orderBy, int $limit, int $offset = 0, string $direction = 'asc', array $searchColumns = [], string $needle = ''): array { return []; }
    public function find(string $c, string $id): ?array { return $this->t[$c][$id] ?? null; }
    public function put(string $c, array $row): array { $this->t[$c][(string) $row['id']] = $row; return $row; }
    public function count(string $collection, array $searchColumns = [], string $needle = ''): int { return 0; }
    public function aggregate(string $collection, string $column = '', array $where = [], string $groupBy = ''): array { return []; }
    public function columnsOf(string $collection): array { return []; }
}

$pass = 0;
// Under tests/run.php the suite's own ok() counts it (a failure here used to exit the run unseen);
// standalone (php tests/agent_audit_test.php) it still exits non-zero on the first failure.
$ok = function_exists('ok')
    ? static function (bool $c, string $what) use (&$pass): void { \group('agent audit'); \ok($c, $what); $pass++; }
    : static function (bool $c, string $what) use (&$pass): void { if (!$c) { fwrite(STDERR, "FAIL: $what\n"); exit(1); } $pass++; };
$today = '2026-09-30';
$s = new AuditMem();
$state = static fn (array $r, string $param): string => (string) array_values(array_filter(array_merge(...array_values($r['groups'])), static fn ($x) => $x['param'] === $param))[0]['state'];

// Bare record, no side tables at all: everything missing, complaints table absent.
$bare = ['id' => '1', 'emp_code' => 'E1', 'name' => 'Bare'];
$r = AgentAudit::of($s, $bare, $today);
$ok($r['verdict'] === 'NON-COMPLIANT' && $r['fail'] === 6 && $r['warn'] === 4 && $r['pass'] === 2, 'bare record: 2 pass, 4 attention, 6 fail');
$ok($r['score'] === 20, 'bare score = only "no open complaints" (20)');
$ok($r['ref'] === 'RAC/2026/E1' && strlen($r['hash']) === 16, 'ref and hash');
$ok($state($r, 'UAN (PF)') === 'warn' && $state($r, 'PAN') === 'bad', 'UAN missing is attention, PAN missing is a fail');

// A fully compliant agent.
$full = ['id' => '2', 'emp_code' => 'e2', 'name' => 'Full', 'photo_path' => 'p.jpg', 'pan' => 'ABCDE1234F', 'uan' => '1001',
    'dra_status' => 'verified', 'dra_expiry' => '2027-01-01', 'pcc_status' => 'verified', 'pcc_expiry' => ''];
$s->t['bgv'] = ['1' => ['id' => '1', 'employee_id' => '2', 'status' => 'clear', 'agency' => 'X', 'checks' => json_encode(['n' => '2027-06-01'])]];
$s->t['employee_references'] = ['1' => ['id' => '1', 'employee_id' => '2'], '2' => ['id' => '2', 'employee_id' => '2']];
$s->t['code_of_conduct_ack'] = ['1' => ['id' => '1', 'employee_id' => '2', 'acknowledged' => '1']];
$s->t['agent_authorizations'] = ['1' => ['id' => '1', 'employee_id' => '2', 'bank' => 'B', 'portfolio' => 'P', 'auth_no' => 'A1', 'valid_to' => '2026-12-31']];
$s->t['complaints'] = ['1' => ['id' => '1', 'employee_id' => '2', 'date' => '2026-01-01', 'status' => 'closed']];
$s->t['letters'] = ['1' => ['id' => '1', 'employee_id' => '2', 'letter_type' => 'nda', 'status' => 'signed']];
$r = AgentAudit::of($s, $full, $today);
$ok($r['verdict'] === 'COMPLIANT' && $r['fail'] === 0 && $r['warn'] === 0 && $r['pass'] === 13, 'fully compliant: 13 parameters incl. re-verification');
$ok($r['score'] === 100, 'full score 100');
$ok($r['ref'] === 'RAC/2026/E2', 'ref upper-cases the code');

// Edge rules copied from SmartPRS 2.
$sub = $full + []; $sub['pcc_status'] = 'submitted';
$r = AgentAudit::of($s, $sub, $today);
$ok($state($r, 'Police clearance (PCC)') === 'ok' && $r['score'] === 80, 'PCC submitted passes the table but not the score');
$s->t['complaints']['2'] = ['id' => '2', 'employee_id' => '2', 'date' => '2026-09-01', 'status' => 'open'];
$r = AgentAudit::of($s, $full, $today);
$ok($state($r, 'Borrower complaints (last 90 days)') === 'warn' && $r['score'] === 80, 'one recent open complaint: attention, score loses 20');
$s->t['bgv']['1']['checks'] = json_encode(['n' => '2026-10-15']);
$ok($state(AgentAudit::of($s, $full, $today), 'Periodic re-verification') === 'warn', 're-verification within 30 days is attention');
$s->t['agent_authorizations']['1']['valid_to'] = '2026-01-01';
$ok($state(AgentAudit::of($s, $full, $today), 'Bank/portfolio authorisation') === 'warn', 'expired authorisation is attention');
function_exists('ok') || print("agent_audit_test: {$pass} passing\n");
