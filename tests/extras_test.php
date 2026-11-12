<?php

declare(strict_types=1);

use SmartPRS\Surface\Assets;
use SmartPRS\Surface\CalcGuide;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Roster;
use SmartPRS\Surface\Shifts;
use SmartPRS\Surface\Store;

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/prs3-extras-' . bin2hex(random_bytes(4))))->forTenant('1');

// ---------------------------------------------------------------- roster
group('roster: the most recent row covering the date wins');

$a = ['id' => '1', 'emp_code' => 'A1', 'name' => 'A', 'branch_id' => '5'];
$b = ['id' => '2', 'emp_code' => 'B1', 'name' => 'B'];
$store->put('shifts', ['id' => '10', 'name' => 'General', 'code' => 'GEN', 'start_time' => '09:30', 'end_time' => '18:00', 'status' => 'active']);
$store->put('shifts', ['id' => '11', 'name' => 'Night', 'code' => 'NGT', 'start_time' => '22:00', 'end_time' => '06:00', 'status' => 'active']);
$store->put('shifts', ['id' => '12', 'name' => 'Early', 'code' => 'EAR', 'start_time' => '07:00', 'end_time' => '15:00', 'status' => 'active']);
$store->put('shifts', ['id' => '13', 'name' => 'Old', 'code' => 'OLD', 'start_time' => '07:00', 'end_time' => '15:00', 'status' => 'inactive']);
$roster = new Roster($store, $root . '/config/roster.json', new Shifts($store));

same('GEN', $roster->shiftFor($a, '2026-09-10')['code'], 'no roster row: the scoped Working Shift');
same(null, $roster->shiftFor($a, '2026-09-10')['roster_id'] ?? null, 'and it does not claim to be rostered');

$r1 = $roster->assign(['1'], '11', '2026-09-01', '2026-09-30', 'night month', 'hr@x', '2026-08-25 10:00:00');
ok($r1['ok'], 'a month on nights: ' . $r1['why']);
same('NGT', $roster->shiftFor($a, '2026-09-10')['code'], 'the range row applies inside its dates');
same('GEN', $roster->shiftFor($a, '2026-10-01')['code'], 'and not outside them');
same('GEN', $roster->shiftFor($b, '2026-09-10')['code'], 'and not to somebody else');

$r2 = $roster->assign(['1'], '12', '2026-09-15', '2026-09-15', 'one-day swap', 'hr@x', '2026-09-01 09:00:00');
ok($r2['ok'], 'a one-day override saved later');
same('EAR', $roster->shiftFor($a, '2026-09-15')['code'], 'the later one-day row wins on its day');
same('NGT', $roster->shiftFor($a, '2026-09-16')['code'], 'the range still applies on the next day');
same('one-day swap', $roster->shiftFor($a, '2026-09-15')['roster_note'], 'the winning row carries its note');

$r3 = $roster->assign(['1'], '10', '2026-09-01', '2026-09-30', 'back on general, saved earlier', 'hr@x', '2026-08-01 09:00:00');
ok($r3['ok'], 'a range row with an OLDER created_at');
same('NGT', $roster->shiftFor($a, '2026-09-20')['code'], 'an older row never beats a newer one, whatever its dates');

$tie = (new Store(sys_get_temp_dir() . '/prs3-extras-tie-' . bin2hex(random_bytes(4))))->forTenant('1');
$tie->put('shifts', ['id' => '11', 'name' => 'Night', 'code' => 'NGT', 'status' => 'active']);
$tie->put('shifts', ['id' => '12', 'name' => 'Early', 'code' => 'EAR', 'status' => 'active']);
$tie->put('rosters', ['id' => '9', 'employee_id' => '1', 'shift_id' => '12', 'from_date' => '2026-09-15', 'to_date' => '2026-09-15', 'status' => 'active', 'created_at' => '2026-09-01 09:00:00']);
$tie->put('rosters', ['id' => '10', 'employee_id' => '1', 'shift_id' => '11', 'from_date' => '2026-09-01', 'to_date' => '2026-09-30', 'status' => 'active', 'created_at' => '2026-09-01 09:00:00']);
$tieRoster = new Roster($tie, $root . '/config/roster.json');
same('NGT', $tieRoster->shiftFor($a, '2026-09-15')['code'], 'equal created_at: the higher id (10 over 9, numerically) wins');
same(null, $tieRoster->shiftFor($b, '2026-09-15'), 'no row and no Shifts fallback: null');

$c = $roster->cancel((string) $r2['rows'][0]['id'], 'hr@x', '2026-09-02 09:00:00');
ok($c['ok'], 'cancel the override');
same('NGT', $roster->shiftFor($a, '2026-09-15')['code'], 'the range applies again on that day');

ok(!$roster->assign(['1'], '13', '2026-09-01', '2026-09-02', '', 'hr@x', 'now')['ok'], 'an inactive shift is refused');
ok(!$roster->assign(['1'], '10', '2026-09-05', '2026-09-01', '', 'hr@x', 'now')['ok'], 'a range that ends before it starts is refused');
ok(!$roster->assign([], '10', '2026-09-01', '2026-09-02', '', 'hr@x', 'now')['ok'], 'nobody is refused');
ok(!$roster->assign(['1'], '10', '2026-09-01', '2028-09-02', '', 'hr@x', 'now')['ok'], 'a range beyond max_range_days is refused');
$many = $roster->assign(['1', '2', '2'], '12', '2026-11-01', '2026-11-02', '', 'hr@x', '2026-10-01 09:00:00');
same(2, count($many['rows']), 'a scope of two (one repeated) writes two rows');

$grid = $roster->grid([$a], Roster::monthDates('2026-09'));
same(30, count($grid['1']), 'the grid has a cell per day');
same(['code' => 'NGT', 'rostered' => true], $grid['1']['2026-09-16'], 'a rostered cell says so');
same(['code' => 'GEN', 'rostered' => false], (new Roster($store, $root . '/config/roster.json', new Shifts($store)))->grid([$b], Roster::monthDates('2026-09'))['2']['2026-09-16'], 'a default cell does not');

// ---------------------------------------------------------------- assets
group('assets: code uniqueness through the register, issue/return state');

$registry = sys_get_temp_dir() . '/prs3-extras-modules-' . bin2hex(random_bytes(4)) . '.json';
$dropIn = json_decode((string) file_get_contents($root . '/config/modules.d/extras.json'), true);
file_put_contents($registry, json_encode($dropIn));
$modules = new Modules($store, $registry);
$assetsModule = $modules->find('assets');
ok($assetsModule !== null && $assetsModule['unique'] === ['asset_id'], 'the drop-in declares asset_id unique');
$store->put('employees', ['id' => '1', 'emp_code' => 'A1', 'name' => 'A', 'status' => 'active']);
$store->put('employees', ['id' => '9', 'emp_code' => 'Z9', 'name' => 'Gone', 'status' => 'inactive']);
$store->put('assets', ['id' => '100', 'asset_id' => 'LAP-001', 'asset_type' => 'Laptop', 'status' => 'in stock', 'cost' => '45000.0000']);
$store->put('assets', ['id' => '101', 'asset_id' => 'LAP-002', 'asset_type' => 'Laptop', 'status' => 'retired', 'cost' => '1.00']);
$dup = $modules->problems($assetsModule, ['asset_id' => 'lap-001', 'asset_type' => 'Laptop']);
ok($dup !== [] && str_contains($dup[0], 'already used'), 'the same code, any case, is refused: ' . ($dup[0] ?? '(nothing)'));
same([], $modules->problems($assetsModule, ['asset_id' => 'LAP-002', 'asset_type' => 'Laptop']), 'a retired asset does not hold its code');
same([], $modules->problems($assetsModule, ['id' => '100', 'asset_id' => 'LAP-001', 'asset_type' => 'Laptop']), 'editing the row itself is not a clash');
ok($modules->problems($assetsModule, ['asset_type' => 'Laptop']) !== [], 'a blank code is refused');

$assets = new Assets($store, $root . '/config/assets.json');
same(['issue', 'repair', 'retire'], $assets->movesFrom('in stock'), 'in stock: issue, repair, retire');
same(['return'], $assets->movesFrom('assigned'), 'assigned: return (a damaged return goes to repair by itself — library model, 30 Sep)');
$row = $store->find('assets', '100');
ok(!$assets->move($row, 'return', [], 'hr@x', '2026-09-09 10:00:00')['ok'], 'returning what is in stock is refused');
ok(!$assets->move($row, 'issue', ['employee_id' => '9'], 'hr@x', '2026-09-09 10:00:00')['ok'], 'issuing to a retired employee is refused');
ok(!$assets->move($row, 'issue', ['employee_id' => '1', 'on' => '2026-13-01'], 'hr@x', '2026-09-09 10:00:00')['ok'], 'a bad date is refused');
ok(!$assets->move($row, 'issue', ['employee_id' => '1', 'condition' => 'shiny'], 'hr@x', '2026-09-09 10:00:00')['ok'], 'a condition config does not know is refused');
$issued = $assets->move($row, 'issue', ['employee_id' => '1', 'on' => '2026-09-01', 'condition' => 'good', 'note' => 'with charger'], 'hr@x', '2026-09-09 10:00:00');
ok($issued['ok'], 'issue: ' . $issued['why']);
$row = $store->find('assets', '100');
same('assigned', $row['status'], 'now assigned');
same('1', (string) $row['issued_to_employee_id'], 'to A');
same('2026-09-01', $row['issue_date'], 'on the date given');
same('hr@x', $row['issued_by'], 'stamped with who');
same('2026-09-09 10:00:00', $row['issued_at'], 'and when');
ok(str_contains((string) $row['notes'], 'with charger'), 'the note is kept on the row');
same(1, count($assets->heldBy('1')), 'A holds one asset');
same('LAP-001', $assets->heldBy('1')[0]['asset_id'], 'the right one');
same([], $assets->heldBy('2'), 'nobody else holds it');
ok(!$assets->move($row, 'issue', ['employee_id' => '1'], 'hr@x', 'now')['ok'], 'an assigned asset cannot be issued again');
$returned = $assets->move($row, 'return', ['on' => '2026-09-08', 'condition' => 'fair'], 'admin@x', '2026-09-09 11:00:00');
ok($returned['ok'], 'return: ' . $returned['why']);
$row = $store->find('assets', '100');
same('in stock', $row['status'], 'back in stock');
same('', (string) ($row['issued_to_employee_id'] ?? ''), 'nobody holds it');
same('2026-09-08', $row['return_date'], 'returned on');
same('admin@x', $row['returned_by'], 'stamped with who took it back');
same('fair', $row['condition'], 'condition recorded');
same([], $assets->heldBy('1'), 'A holds nothing — clearance clean');
same('₹45,000.00', Assets::cost($assets->live())->format(), 'cost sums exactly from the DECIMAL(18,4) string, retired excluded');
$repair = $assets->move($row, 'repair', [], 'admin@x', '2026-09-10 09:00:00');
ok($repair['ok'] && $store->find('assets', '100')['status'] === 'under repair', 'repair from stock');
same(['restock', 'retire'], $assets->movesFrom('under repair'), 'under repair: back on the shelf, or retired');

// ---------------------------------------------------------------- calc guide
group('salary calculation guide reads the live configuration');

$structure = json_decode((string) file_get_contents($root . '/config/salary-structure.json'), true);
$version = $structure[0];
$cycleRules = [
    ['id' => 7, 'name' => 'Kondapur', 'branch' => 'Kondapur', 'cycle' => 'monthly', 'cutoff' => '20', 'pay_day' => 5, 'status' => 'active'],
    ['id' => 8, 'name' => 'Off', 'cycle' => 'weekly', 'cutoff' => 'calendar', 'pay_day' => 3, 'status' => 'inactive'],
];
$guide = new CalcGuide($root . '/config', '2026-09-09', $version, 'annual', $cycleRules,
    json_decode((string) file_get_contents($root . '/config/pay-cycles.json'), true),
    [['id' => 3, 'grace_min' => 15, 'full_day_hours' => 9, 'lates_before_cut' => 3, 'addl_late' => 'half_day', 'weekoff_action' => 'ignore']],
    [], 'no pack store in this test');
$sections = [];

foreach ($guide->sections() as $s) {
    $sections[$s['id']] = $s;
}

same(['cycle', 'days', 'gross', 'components', 'deductions', 'statutory', 'recoveries', 'gratuity', 'bonus'], array_keys($sections), 'the nine steps, in payslip order');
$text = static fn (string $id): string => implode("\n", $sections[$id]['points']);

ok(str_contains($text('cycle'), 'row 7') && str_contains($text('cycle'), 'branch Kondapur') && str_contains($text('cycle'), '21st to 20th') && str_contains($text('cycle'), 'day 5'), 'the pay cycle row is quoted with its id, scope, window and pay day');
ok(!str_contains($text('cycle'), 'Off'), 'an inactive cycle row is not quoted');

$att = json_decode((string) file_get_contents($root . '/config/attendance-rules.json'), true);
$first = $att['rules'][0];
ok(str_contains($text('days'), $first['code']) && str_contains($text('days'), 'Priority ' . $first['priority']) && str_contains($text('days'), $first['when']), 'every attendance rule is quoted with priority and condition: ' . $first['code']);
ok(str_contains($text('days'), 'HALF_DAY = ' . $att['statuses']['HALF_DAY'] . ' half-day'), 'status worth quoted from attendance-rules.json statuses');
ok(str_contains($text('days'), 'late_policy row 3') && str_contains($text('days'), 'grace 15 min') && str_contains($text('days'), 'lates before a cut 3'), 'the late policy row is quoted with its id and numbers');

ok(str_contains($text('gross'), 'ANNUAL') && str_contains($text('gross'), '÷ 12'), 'annual basis: divided by twelve');
ok(str_contains((new CalcGuide($root . '/config', '2026-09-09', $version, 'monthly', [], [], [], []))->sections()[2]['points'][1], 'as it stands'), 'monthly basis: not divided');
same('config/salary-structure.json (version in force on 2026-09-09)', $sections['gross']['source'], 'the source names the file and the date');

foreach ($version['components'] as $c) {
    ok(str_contains($text('components'), $c['code'] . ' (' . $c['kind']), 'component ' . $c['code'] . ' is explained');

    if (isset($c['rate_percent'])) {
        ok(str_contains($text('components'), $c['code'] . ' (' . $c['kind'] . ', sequence ' . $c['sequence'] . '): ' . $c['rate_percent'] . '%'), $c['code'] . ' quotes rate_percent ' . $c['rate_percent'] . ' from the file');
    }

    if (isset($c['flat_amount'])) {
        ok(str_contains($text('components'), 'flat ' . $c['flat_amount']), $c['code'] . ' quotes flat_amount ' . $c['flat_amount']);
    }
}

$formulaVersion = $structure[1];
$hra = $formulaVersion['components'][1];
ok(str_contains(implode("\n", (new CalcGuide($root . '/config', '2027-05-01', $formulaVersion, 'annual', [], [], [], []))->sections()[3]['points']), $hra['formula']['expression']), 'a formula component quotes its expression verbatim');

ok(str_contains($text('deductions'), 'D-PT: flat 200.00, NOT prorated'), 'the flat deduction is named, with its proration flag');
ok(str_contains($text('statutory'), 'no pack store in this test'), 'no pack: the reason is stated, never a rate');
$g = json_decode((string) file_get_contents($root . '/config/gratuity.json'), true);
ok(str_contains($text('gratuity'), 'after ' . $g['eligibility_years'] . ' completed years') && str_contains($text('gratuity'), $g['days_factor']['numerator'] . '/' . $g['days_factor']['denominator']) && str_contains($text('gratuity'), $g['statutory_cap']), 'gratuity quotes years, factor and cap from gratuity.json');
$bn = json_decode((string) file_get_contents($root . '/config/statutory-bonus.json'), true);
ok(str_contains($text('bonus'), $bn['eligibility_wage_ceiling']) && str_contains($text('bonus'), $bn['min_percent'] . '%') && str_contains($text('bonus'), $bn['max_percent'] . '%'), 'bonus quotes the ceilings and the band from statutory-bonus.json');

// The guide has no number of its own: change a config value, the guide changes.
$changed = $version;
$changed['components'][0]['rate_percent'] = 37;
ok(str_contains(implode("\n", (new CalcGuide($root . '/config', '2026-09-09', $changed, 'annual', [], [], [], []))->sections()[3]['points']), 'C-BASIC (earning, sequence 10): 37%'), 'a changed rate is what the guide says — nothing is typed in PHP');

$src = (string) file_get_contents($root . '/surface/CalcGuide.php');
ok(preg_match('/\b(15|26|8\.33|21000|7000|20000|200|50|40)\b/', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $src) ?? '') !== 1, 'no statutory or structure number appears as a literal in CalcGuide.php');

// ---------------------------------------------------------------- config files
group('extras config files parse and carry what the screens read');
$zk = json_decode((string) file_get_contents($root . '/config/zkteco.json'), true);
ok(is_array($zk) && str_starts_with((string) $zk['ingest_path'], '/api.php/api/v3/attendance-punches'), 'zkteco.json points at the /api/v3 punch ingest');
ok(count($zk['steps']) >= 4 && isset($zk['protocol']['lan_port']), 'zkteco.json carries the steps and ports');
$as = json_decode((string) file_get_contents($root . '/config/assets.json'), true);
foreach ($as['transitions'] as $op => $t) {
    ok(isset($as['statuses'][$t['to']]), "assets.json transition {$op} lands on a declared status");
    foreach ($t['from'] as $from) {
        ok(isset($as['statuses'][$from]), "assets.json transition {$op} starts from a declared status ({$from})");
    }
}
$mod = json_decode((string) file_get_contents($root . '/config/modules.d/extras.json'), true);
same(array_keys($as['statuses']), array_keys($mod['modules'][0]['fields']['status']['options']), 'the register offers exactly the statuses assets.json declares');
same($as['conditions'], array_keys($mod['modules'][0]['fields']['condition']['options']), 'and exactly its conditions');
