<?php
declare(strict_types=1);
// Self-check: punches on a W.OFF / holiday count only once approved (owner, 14 Oct).
// Run: php -d zend.assertions=1 -d assert.exception=1 tests/off_day_punch_hold_check.php
$root = $argv[1] ?? dirname(__DIR__);
spl_autoload_register(static function (string $c) use ($root): void {
    foreach (['SmartPRS\\Platform\\' => '/src/Platform/', 'SmartPRS\\Surface\\' => '/surface/'] as $p => $d) {
        if (str_starts_with($c, $p)) { $f = $root . $d . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) { require_once $f; } }
    }
});
use SmartPRS\Surface\{RowStore, Attendance, Calendar};
final class OffDayFakeStore implements RowStore {
    public function __construct(public array $data) {}
    public function forTenant(string $t): RowStore { return $this; }
    public function tenantId(): ?string { return '1'; }
    public function all(string $c): array { return $this->data[$c] ?? []; }
    public function page(string $collection, string $orderBy, int $limit, int $offset = 0, string $direction = 'asc', array $searchColumns = [], string $needle = ''): array { return []; }
    public function find(string $c, string $id): ?array { return null; }
    public function put(string $c, array $row): array { $this->data[$c][] = $row; return $row; }
    public function count(string $collection, array $searchColumns = [], string $needle = ''): int { return 0; }
    public function aggregate(string $collection, string $column = '', array $where = [], string $groupBy = ''): array { return []; }
    public function columnsOf(string $collection): array { return []; }
}
$cal = tempnam(sys_get_temp_dir(), 'cal');
file_put_contents($cal, json_encode(['weekly_off' => ['Sun'], 'grace_minutes' => 5, 'shift' => ['starts' => '09:00', 'ends' => '18:00'], 'proration_basis' => 'calendar_days',
    'holidays' => [['on' => '2026-10-02', 'name' => 'Gandhi Jayanti', 'type' => 'Government']]]));
$p = static fn (string $at, string $dir, string $src): array => ['employment_id' => 'E1', 'at' => $at, 'direction' => $dir, 'source' => $src];
$store = new OffDayFakeStore(['employees' => [['id' => 1, 'emp_code' => 'E1']], 'attendance_logs' => [
    $p('2026-10-01 09:00:00', 'in', 'device'), $p('2026-10-01 18:00:00', 'out', 'device'),          // Thu: working day
    $p('2026-10-02 09:00:00', 'in', 'device'), $p('2026-10-02 18:00:00', 'out', 'device'),          // Fri: holiday, not approved
    $p('2026-10-04 09:00:00', 'in', 'web'), $p('2026-10-04 18:00:00', 'out', 'web'),                // Sun: W.OFF, held …
    $p('2026-10-04 09:00:00', 'in', 'regularised'), $p('2026-10-04 18:00:00', 'out', 'regularised'),// … and approved
]]);
$att = new Attendance($store, null, new Calendar($cal));
$days = array_column($att->settledDays('E1', '09:00', '18:00', '2026-10-01', '2026-10-04'), null, 'date');
assert(isset($days['2026-10-01']), 'working day counts');
assert(!isset($days['2026-10-02']), 'unapproved holiday punches are held');
assert(isset($days['2026-10-04']) && $days['2026-10-04']['settlement']->workedMinutes === 540, 'approved W.OFF punches count once');
assert($att->offDayReason('E1', '2026-10-04') === 'weekly off' && $att->offDayReason('E1', '2026-10-02') === 'holiday' && $att->offDayReason('E1', '2026-10-01') === null);
// The day view lists what is held; a sweep raises one request per held day + direction, once.
assert(count($att->heldPunches('E1', '2026-10-02')) === 2 && $att->heldPunches('E1', '2026-10-01') === []);
$reg = new SmartPRS\Surface\Regularisation($store, $att);
$raised = $reg->raiseForHeldPunches($store->all('employees'), '2026-10-01');
$keys = array_map(static fn (array $r): string => $r['request']['date'] . ' ' . $r['request']['direction'], $raised);
sort($keys);
assert($keys === ['2026-10-02 in', '2026-10-02 out', '2026-10-04 in', '2026-10-04 out'], implode(',', $keys));
assert($reg->raiseForHeldPunches($store->all('employees'), '2026-10-01') === [], 'never raised twice');
echo "off_day_punch_hold_check: ok\n";
