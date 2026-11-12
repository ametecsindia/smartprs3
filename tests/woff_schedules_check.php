<?php
/**
 * EXTRA W.OFF SCHEDULES (owner, 30 Oct 2026): a schedule per Company / Branch / Role … ; an employee follows
 * the most specific match, everyone else the default; save refuses a schedule that narrows nothing.
 *   php tests/woff_schedules_check.php
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/bootstrap.php';

use SmartPRS\Surface\Calendar;
use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\RowStore;

class WoMem implements RowStore {
  public array $d = [];
  function forTenant(string $t): self { return $this; }
  function tenantId(): ?string { return null; }
  function all(string $c): array { return array_values($this->d[$c] ?? []); }
  function page(string $collection, string $orderBy, int $limit, ...$x): array { return []; }
  function find(string $c, string $id): ?array { return null; }
  function put(string $c, array $row): array { $this->d[$c][] = $row; return $row; }
  function count(string $c, array $s = [], string $n = ''): int { return 0; }
  function aggregate(string $collection, ...$x): array { return []; }
  function columnsOf(string $c): array { return []; }
}
$f = []; $n = 0;
$ok = function (bool $c, string $what) use (&$f, &$n): void { $n++; $c || $f[] = $what; };
$store = new WoMem();
$cal = new Calendar(__DIR__ . '/../config/calendar.json', new Customisation($store));
$roles = ['D1' => 'Field Executive', 'D2' => 'Manager'];
$office = ['employee_code' => 'E1', 'employer_company_id' => '1', 'branch_id' => '10', 'designation_id' => 'D2'];
$field = ['employee_code' => 'E2', 'employer_company_id' => '1', 'branch_id' => '10', 'designation_id' => 'D1'];
$pune = ['employee_code' => 'E3', 'employer_company_id' => '1', 'branch_id' => '20', 'designation_id' => 'D2'];
$named = ['employee_code' => 'E4', 'employer_company_id' => '1', 'branch_id' => '20', 'designation_id' => 'D1'];

// Default: every Sun + 2nd & 4th Sat. Schedule 2: branch 20 → every Sat & Sun. Schedule 3: role Field Executive → every Fri.
// Schedule 4: employee E4 → no weekly off.
$r = $cal->saveCalendar([], ['Sun'], 'u', 'U', ['Sat' => [2, 4]], [
    ['name' => 'Pune', 'applies_to' => ['branch' => '20'], 'weekly_off' => ['Sat', 'Sun']],
    ['name' => 'Field', 'applies_to' => ['role' => 'Field Executive', 'company' => ''], 'weekly_off' => ['Fri']],
    ['name' => 'Roster', 'applies_to' => ['employee' => 'E4'], 'weekly_off' => []],
]);
$ok($r['ok'], 'save: ' . $r['why']);
$cal = new Calendar(__DIR__ . '/../config/calendar.json', new Customisation($store));
$ok($cal->scheduleFor($office, $roles)['name'] === 'Default', 'office → Default');
$ok($cal->scheduleFor($field, $roles)['name'] === 'Field', 'field → Field');
$ok($cal->scheduleFor($pune, $roles)['name'] === 'Pune', 'pune → Pune');
$ok($cal->scheduleFor($named, $roles)['name'] === 'Roster', 'named employee beats branch and role');
$ok($cal->scheduleFor(null)['name'] === 'Default', 'no employee → Default');
// 2026-10-10 is the 2nd Saturday, 2026-10-03 the 1st, 2026-10-09 a Friday, 2026-10-04 a Sunday.
$ok($cal->isWeeklyOff('2026-10-10', $office, $roles) && !$cal->isWeeklyOff('2026-10-03', $office, $roles), 'office: 2nd Sat off, 1st Sat worked');
$ok($cal->isWeeklyOff('2026-10-03', $pune, $roles) && $cal->isWeeklyOff('2026-10-04', $pune, $roles), 'pune: every Sat and Sun');
$ok($cal->isWeeklyOff('2026-10-09', $field, $roles) && !$cal->isWeeklyOff('2026-10-04', $field, $roles), 'field: Fri off, Sun worked');
$ok(!$cal->isWeeklyOff('2026-10-04', $named, $roles), 'roster: Sunday worked');
$ok($cal->isWeeklyOff('2026-10-10') && !$cal->isWeeklyOff('2026-10-09'), 'no employee: default pattern, as before');
$ok($cal->workingDaysBetween('2026-10-01', '2026-10-31', $pune, $roles) !== $cal->workingDaysBetween('2026-10-01', '2026-10-31', $office, $roles), 'working days differ by schedule');
$ok($cal->weeklyOffLabel($cal->woffSchedules()[0]) === 'Every Sat + Every Sun', 'label: ' . $cal->weeklyOffLabel($cal->woffSchedules()[0]));
$ok(!isset($cal->woffSchedules()[1]['applies_to']['company']), 'blank Applies-to field = ALL, not stored');

// Refusals.
$ok(!$cal->saveCalendar([], ['Sun'], 'u', 'U', [], [['name' => 'X', 'applies_to' => [], 'weekly_off' => ['Sat']]])['ok'], 'refuse: no Applies-to');
$ok(!$cal->saveCalendar([], ['Sun'], 'u', 'U', [], [['name' => 'A', 'applies_to' => ['branch' => '20']], ['name' => 'B', 'applies_to' => ['branch' => '20']]])['ok'], 'refuse: same people twice');
$ok(!$cal->saveCalendar([], ['Sun'], 'u', 'U', [], [['name' => 'A', 'applies_to' => ['branch' => '20']], ['name' => 'a', 'applies_to' => ['branch' => '10']]])['ok'], 'refuse: duplicate name');
$ok(!$cal->saveCalendar([], ['Sun'], 'u', 'U', [], [['name' => 'A', 'applies_to' => ['branch' => '20'], 'weekly_off' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']]])['ok'], 'refuse: all seven days');
// Removing every extra schedule saves an empty list; null keeps what is saved.
$ok($cal->saveCalendar([], ['Sun'], 'u', 'U', [], null)['ok'] && count((new Calendar(__DIR__ . '/../config/calendar.json', new Customisation($store)))->woffSchedules()) === 3, 'null keeps saved schedules');
$ok($cal->saveCalendar([], ['Sun'], 'u', 'U', [], [])['ok'] && (new Calendar(__DIR__ . '/../config/calendar.json', new Customisation($store)))->woffSchedules() === [], 'empty list removes them');

echo $n - count($f) . ' / ' . count($f) . " (passed / failed)\n";
foreach ($f as $x) { echo "  FAIL: {$x}\n"; }
exit($f === [] ? 0 : 1);
