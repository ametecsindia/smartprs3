<?php

declare(strict_types=1);

/**
 * PERFORMANCE & REWARDS + LEARNING & KNOWLEDGE — the arithmetic behind the
 * hand-built screens: points rules applied to counted events, the leaderboard,
 * MCQ scoring and the pass decision, pass rates, attrition, review periods and
 * the final rating; and the drop-in declarations line up with the menu.
 */

use SmartPRS\Platform\Support\Rational;
use SmartPRS\Surface\Assessments;
use SmartPRS\Surface\Attrition;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Navigation;
use SmartPRS\Surface\Performance;
use SmartPRS\Surface\Points;
use SmartPRS\Surface\Store;

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/prs3-pl-' . bin2hex(random_bytes(4))))->forTenant('1');

// ---- points -------------------------------------------------------------------
group('perf-learn: points engine');

$points = new Points($store, $root . '/config/points.json');
$rules = [
    ['id' => '1', 'event' => 'Late arrival', 'points' => '-2', 'category' => 'negative', 'employer_company_id' => ''],
    ['id' => '2', 'event' => 'Late arrival', 'points' => '-5', 'category' => 'negative', 'employer_company_id' => '9'],
    ['id' => '3', 'event' => 'Perfect attendance', 'points' => '10', 'category' => 'positive', 'employer_company_id' => ''],
    ['id' => '4', 'event' => 'Test passed', 'points' => '3', 'category' => 'positive', 'employer_company_id' => ''],
    ['id' => '5', 'event' => 'Award received', 'points' => '0', 'category' => 'positive', 'employer_company_id' => ''],
];
$all = Points::rulesByEvent($rules);
same('-2', (string) $all['Late arrival']['points'], 'ALL companies: the ALL rule');
same('-5', (string) Points::rulesByEvent($rules, '9')['Late arrival']['points'], 'company 9: its own rule beats the ALL rule');
same('-2', (string) Points::rulesByEvent($rules, '7')['Late arrival']['points'], 'company 7 has no rule of its own: the ALL rule');

$events = [
    ['employee_id' => 'E1', 'event' => 'Late arrival', 'count' => 3, 'employer_company_id' => '7'],
    ['employee_id' => 'E1', 'event' => 'Test passed', 'count' => 1, 'employer_company_id' => '7'],
    ['employee_id' => 'E2', 'event' => 'Perfect attendance', 'count' => 1, 'employer_company_id' => '7'],
    ['employee_id' => 'E2', 'event' => 'Award received', 'count' => 2, 'employer_company_id' => '7'],
    ['employee_id' => 'E3', 'event' => 'Target achieved', 'count' => 1, 'employer_company_id' => '7'],
    ['employee_id' => 'E3', 'event' => 'Late arrival', 'count' => 0, 'employer_company_id' => '7'],
];
$ledger = Points::apply($all, $events, '2026-03', 'Auto: ');
same(3, count($ledger), 'a zero-point rule, an event with no rule and a zero count write nothing');
same(-6, $ledger[0]['points'], '3 late × -2 = -6: the amount comes from the rule row');
same('Auto: Late arrival × 3 @ -2 = -6', $ledger[0]['note'], 'the row explains itself');
same('auto:2026-03', $ledger[0]['source_ref'], 'the month is on the row so it cannot be applied twice');
same('2026-03-01', $ledger[0]['date'], 'dated in the month');
same('negative', $ledger[0]['category'], 'category from the rule');
same(10, $ledger[2]['points'], 'perfect attendance once = 10');

// counting a month's events from the muster totals, awards and attempts
$employees = ['E1' => ['id' => 'E1', 'employer_company_id' => '7'], 'E2' => ['id' => 'E2', 'employer_company_id' => '7'], 'E3' => ['id' => 'E3']];
$muster = [
    'E1' => ['late_days' => 3, 'by_status' => ['PRESENT' => 18, 'ABSENT' => 2]],
    'E2' => ['late_days' => 0, 'by_status' => ['PRESENT' => 22]],
];
$awards = [['employee_id' => 'E2', 'date' => '2026-03-15'], ['employee_id' => 'E2', 'date' => '2026-02-28']];
$attempts = [['employee_id' => 'E1', 'status' => 'passed', 'attempted_on' => '2026-03-02'], ['employee_id' => 'E1', 'status' => 'failed', 'attempted_on' => '2026-03-03']];
$counted = [];
foreach ($points->monthEvents($muster, $employees, $awards, $attempts, '2026-03') as $ev) {
    $counted[$ev['employee_id'] . ':' . $ev['event']] = $ev['count'];
}
same(3, $counted['E1:Late arrival'] ?? null, 'late days from the muster');
same(2, $counted['E1:Absent'] ?? null, 'absent days from the status count');
same(1, $counted['E1:Test passed'] ?? null, 'only the passed attempt counts');
ok(!isset($counted['E1:Perfect attendance']), 'a late or absent day is not perfect attendance');
same(1, $counted['E2:Perfect attendance'] ?? null, 'no late, no absent = perfect');
same(1, $counted['E2:On-time streak'] ?? null, '22 present days with no late mark reaches the configured streak');
same(1, $counted['E2:Award received'] ?? null, 'only the award dated in the month');
ok(!isset($counted['E3:Perfect attendance']), 'no muster row = nothing known = no award');
ok(!isset($counted['E3:Target achieved']), 'a manual event is never auto-counted');

// the leaderboard
$board = Points::leaderboard([
    ['employee_id' => 'A', 'points' => '5', 'date' => '2026-03-01'],
    ['employee_id' => 'A', 'points' => '-2', 'date' => '2026-03-05'],
    ['employee_id' => 'B', 'points' => '3', 'date' => '2026-03-01'],
    ['employee_id' => 'C', 'points' => '7', 'date' => '2026-02-01'],
    ['employee_id' => 'D', 'points' => '-1', 'date' => '2026-03-01'],
]);
same(['C', 'A', 'B', 'D'], array_column($board, 'employee_id'), 'ranked by net, all time');
same([1, 2, 2, 4], array_column($board, 'rank'), 'A and B tie on 3: ranks 1, 2, 2, 4');
same(3, $board[1]['net'], 'A: 5 − 2 = 3');
same(5, $board[1]['positive'], 'A positive 5');
same(-2, $board[1]['negative'], 'A negative −2');
$march = Points::leaderboard([
    ['employee_id' => 'A', 'points' => '5', 'date' => '2026-03-01'],
    ['employee_id' => 'A', 'points' => '-2', 'date' => '2026-03-05'],
    ['employee_id' => 'B', 'points' => '3', 'date' => '2026-03-01'],
    ['employee_id' => 'C', 'points' => '7', 'date' => '2026-02-01'],
], '2026-03');
same(['A', 'B'], array_column($march, 'employee_id'), 'a month filter drops February');
same([1, 1], array_column($march, 'rank'), 'a tie shares the rank');
same(1, $store->put('points_ledger', ['employee_id' => 'A', 'points' => 1, 'date' => '2026-03-01', 'source_ref' => 'auto:2026-03'])['points'], 'a ledger row is stored');
ok($points->applied('2026-03'), 'March is now applied');
ok(!$points->applied('2026-04'), 'April is not');
ok($points->applied('2026-03', ['A']), 'March is applied for A');
ok(!$points->applied('2026-03', ['B']), 'but not for B, who was out of view');

// ---- tests --------------------------------------------------------------------
group('perf-learn: assessments');

$questions = [
    ['id' => 'q1', 'seq' => 1, 'correct' => 'a', 'marks' => 1],
    ['id' => 'q2', 'seq' => 2, 'correct' => 'B', 'marks' => 2],
    ['id' => 'q3', 'seq' => 3, 'correct' => 'c', 'marks' => 1],
];
$s = Assessments::score($questions, ['q1' => 'a', 'q2' => 'b', 'q3' => 'd']);
same(4, $s['total'], 'total marks');
same(3, $s['scored'], 'marks scored: 1 + 2');
same(75, $s['percent'], '3 of 4 = 75%');
same(0, $s['per_question'][2]['earned'], 'the wrong answer earns nothing');
same(50, Assessments::score($questions, ['q1' => 'a', 'q3' => 'c'])['percent'], '2 of 4 marks = 50%: marks weigh, not questions');
$oneEach = [['id' => '1', 'correct' => 'a'], ['id' => '2', 'correct' => 'a'], ['id' => '3', 'correct' => 'a']];
same(66, Assessments::score($oneEach, ['1' => 'a', '2' => 'A'])['percent'], '2 of 3 → 66, rounded down (integer arithmetic); blank marks count 1; case does not matter');
same(0, Assessments::score([], [])['percent'], 'a test with no questions scores 0');
ok(Assessments::passed(2, 3, '66'), '2/3 ≥ 66%');
ok(Assessments::passed(2, 3, '66.66'), '2/3 = 66.666… ≥ 66.66');
ok(!Assessments::passed(2, 3, '66.67'), '2/3 < 66.67: exact, not a rounded 67');
ok(!Assessments::passed(0, 0, '0'), 'no marks at all never passes');
ok(Assessments::passed(4, 4, '100'), 'full marks passes 100');
ok(!Assessments::passed(4, 4, 'abc'), 'a pass mark that is not a number fails closed');

$rep = Assessments::reports(
    [['id' => 'T1', 'name' => 'Fair Practice'], ['id' => 'T2', 'name' => 'POSH']],
    [
        ['test_id' => 'T1', 'employee_id' => 'E1', 'status' => 'passed'],
        ['test_id' => 'T1', 'employee_id' => 'E2', 'status' => 'failed'],
        ['test_id' => 'T1', 'employee_id' => 'E3', 'status' => 'passed'],
        ['test_id' => 'T1', 'employee_id' => 'E3', 'status' => 'in progress'],
    ],
    ['E1' => 'Collections', 'E2' => 'Collections', 'E3' => 'Ops'],
);
same(3, $rep['by_test'][0]['attempts'], 'in-progress attempts are not outcomes');
same('2/3', (string) $rep['by_test'][0]['rate'], 'pass rate is an exact fraction');
ok(str_contains($rep['by_test'][0]['working'], '66.6%'), 'and shown truncated as a percent');
same(null, $rep['by_test'][1]['rate'], 'a test nobody attempted has no rate');
same('Collections', $rep['by_department'][0]['department'], 'per department');
same('1/2', (string) $rep['by_department'][0]['rate'], 'Collections: 1 of 2');
same('1', (string) $rep['by_department'][1]['rate'], 'Ops: 1 of 1');

// ---- attrition ----------------------------------------------------------------
group('perf-learn: attrition');

same(['2025-11', '2025-12', '2026-01', '2026-02'], Attrition::months('2026-02', 4), 'months back across a year end');
$acfg = (array) json_decode((string) file_get_contents($root . '/config/attrition.json'), true);
$people = [
    ['id' => '1', 'emp_code' => 'A', 'doj' => '2025-06-01'],
    ['id' => '2', 'emp_code' => 'B', 'doj' => '2025-06-01', 'exit_date' => '2026-01-15'],
    ['id' => '3', 'emp_code' => 'C', 'doj' => '2026-01-10'],
    ['id' => '4', 'emp_code' => 'D', 'doj' => '2026-01-20', 'exit_date' => '2026-01-25'],
    ['id' => '5', 'emp_code' => 'E', 'doj' => '2025-06-01', 'status' => 'exited'],
    ['id' => '6', 'emp_code' => 'F', 'doj' => '2025-06-01'],
];
$t = Attrition::table($people, ['6' => '2026-02-03'], ['2026-01', '2026-02'], $acfg);
$jan = $t['rows'][0];
same(4, $jan['opening'], 'January opens with A, B, E, F');
same(2, $jan['joiners'], 'C and D joined in January');
same(2, $jan['leavers'], 'B and D left in January');
same(4, $jan['closing'], 'closing = opening + joiners − leavers');
same('4', (string) $jan['average'], '(4 + 4) ÷ 2 = 4');
same('1/2', (string) $jan['rate'], '2 leavers ÷ 4 = 1/2, exact');
ok(str_contains($jan['working'], '= 50%'), 'and as a percentage with the working');
$feb = $t['rows'][1];
same(1, $feb['leavers'], "F's exit came from the timeline, not the record");
same(3, $feb['closing'], 'February closes with A, C, E');
same('2/7', (string) $feb['rate'], '1 ÷ ((4 + 3) ÷ 2) = 2/7');
same(['E (left, date unknown)'], $t['unplaced'], 'an exited status with no date is reported, not guessed');
same(['B', 'D'], $t['leavers']['2026-01'], 'who left is listed');
$none = Attrition::table([], [], ['2026-01'], $acfg);
same(null, $none['rows'][0]['rate'], 'no headcount: no rate rather than a division by zero');

// ---- performance --------------------------------------------------------------
group('perf-learn: performance reviews');

$perf = new Performance($root . '/config/performance.json');
same([1, 2, 3, 4, 5], array_keys($perf->scale()), 'the configured scale');
same(4, $perf->rating('4'), 'a rating on the scale');
same(null, $perf->rating('6'), 'off the scale is refused');
same(null, $perf->rating('x'), 'not a number is refused');
same(['2026-H2', '2026-H1', '2025-H2', '2025-H1'], $perf->periods('2026-09-09'), 'half-years, newest first, two years back');
same(['2026-H1', '2025-H2', '2025-H1'], $perf->periods('2026-03-01'), 'H2 is not offered before July');
$byId = ['10' => ['id' => '10', 'emp_code' => 'M-1', 'name' => 'Manager One']];
same('10', Performance::reviewerOf(['reporting_manager_id' => '10'], $byId)['id'], 'the reviewer is the reporting manager on the record');
ok(str_contains(Performance::reviewerOf(['reporting_manager_id' => '10'], $byId)['label'], 'Manager One'), 'named from the manager record');
same('', Performance::reviewerOf(['reporting_manager' => 'Typed Name'], $byId)['id'], 'a legacy text name has no id');
same('Typed Name', Performance::reviewerOf(['reporting_manager' => 'Typed Name'], $byId)['label'], 'but is still named');
same(4, $perf->finalRating(3, 4, null)['rating'], 'final defaults to the manager rating');
same(5, $perf->finalRating(3, 4, 5)['rating'], 'an HR override wins');
ok(str_contains($perf->finalRating(3, 4, 5)['working'], 'override'), 'and says so');
same(3, $perf->finalRating(3, null, null)['rating'], 'only a self rating: that one');
same(null, $perf->finalRating(null, null, null)['rating'], 'nothing yet');
same('8', $perf->prg(4), 'PRG band for a 4');
same(null, $perf->prg(null), 'no rating, no band');

// ---- the drop-ins -------------------------------------------------------------
group('perf-learn: declarations');

$modules = new Modules($store, $root . '/config/modules.json');
foreach (['point-rules' => 'point_rules', 'points' => 'points_ledger', 'awards' => 'awards', 'tests' => 'tests',
    'test-questions' => 'test_questions', 'test-attempts' => 'test_attempts', 'training-programs' => 'training_programs',
    'training-records' => 'training_records', 'training-content' => 'training_subjects', 'kb' => 'kb_topics',
    'faqs' => 'faqs', 'policies' => 'policies'] as $key => $table) {
    same($table, (string) (((array) $modules->find($key))['table'] ?? ''), "$key is a register over $table");
}
same('employees', ((array) $modules->find('points'))['fields']['employee_id']['lookup']['table'] ?? '', 'the ledger picks the employee');
same('tests', ((array) $modules->find('test-questions'))['fields']['test_id']['lookup']['table'] ?? '', 'a question belongs to a picked test');
same(['A', 'B', 'C', 'D'], ((array) $modules->find('test-questions'))['fields']['correct']['options'] ?? [], 'the correct letter is one of four');
same('training_programs', ((array) $modules->find('training-records'))['fields']['program_id']['lookup']['table'] ?? '', 'a record picks its programme');
same('employee.update', ((array) $modules->find('point-rules'))['permissions']['edit'] ?? '', 'rules are edited by HR');

$sections = (new Navigation($root . '/config/navigation.json'))->sections();
$itemsOf = static fn (string $key): array => array_column((array) ((array_values(array_filter($sections, static fn (array $s): bool => ($s['key'] ?? '') === $key))[0] ?? [])['items'] ?? []), 'key');
$perfKeys = $itemsOf('performance');
$learnKeys = $itemsOf('learning');
foreach (['performance', 'points-rules', 'points-ledger', 'points-scores', 'awards', 'tests', 'test-results', 'test-reports', 'attrition', 'increments'] as $k) {
    ok(in_array($k, $perfKeys, true), "PERFORMANCE has $k");
}
// HR Helpdesk is its own section now (navigation.d/helpdesk.json), and Policies left the menu (its register remains).
foreach (['training-programs', 'training-records', 'training-content', 'kb', 'faqs', 'code-of-conduct'] as $k) {
    ok(in_array($k, $learnKeys, true), "LEARNING has $k");
}
foreach (['performance', 'points-scores', 'tests', 'test-reports', 'attrition', 'code-of-conduct', 'helpdesk'] as $route) {
    ok(is_file($root . '/surface/screens/' . $route . '.php'), "screen $route exists");
}
