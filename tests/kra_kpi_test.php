<?php

declare(strict_types=1);

use SmartPRS\Surface\KraKpi;
use SmartPRS\Surface\Store;

/**
 * A TARGET NOBODY CAN REACH IS A REVIEW SOMEBODY THINKS IS FAIR AND IS NOT.
 *
 * Every assertion below is about the two ways this screen could go wrong
 * silently: publishing a target that never gets consulted (wrong scope, wrong
 * KPI code), or recording a percentage that does not match the target it
 * claims to be measured against. The checkbox tests are about a third way:
 * an employee seeing a KPI in the Achievements dropdown that nobody actually
 * kept for their scope, or not seeing one that was kept. The role tests
 * (target/achievement/computed) are about a fourth way: the same figure
 * being targetable, recordable AND separately re-derived in three different
 * places that could each say something different about it.
 */

group('KRAs and KPIs');

$root = dirname(__DIR__);
$targetsStore = (new Store(sys_get_temp_dir() . '/kk-targets-' . bin2hex(random_bytes(4))))->forTenant('1');
$achievementsStore = (new Store(sys_get_temp_dir() . '/kk-ach-' . bin2hex(random_bytes(4))))->forTenant('1');
$kraKpi = new KraKpi($targetsStore, $achievementsStore, $root . '/config/kra-kpi.json');

// THE CATALOGUE IS DATA, NOT CODE — 175 collections-industry KPIs across 21
// KRAs, none of them a literal in this class.
$kpis = $kraKpi->kpis();
ok(count($kpis) >= 100, 'the catalogue carries the collections/recovery KRA/KPI matrix');
ok(isset($kpis['collection-recovery--collection-amount']), 'a known KPI resolves by its stable code');
$byKra = $kraKpi->byKra();
ok(count($byKra) >= 15, 'grouped into its real KRAs');

// A CODE NOBODY DECLARED, TICKED "KEEP". A target against it is a number
// that never applies to any real KPI dropdown or achievement entry.
$refused = $kraKpi->publish('', '', '', '', '', '', ['MYSTERY_KPI'], ['MYSTERY_KPI' => '100000'], '2026-04-01', 'tester');
same(1, count($refused['refused']), 'a KPI outside the catalogue is refused');
ok(str_contains($refused['refused'][0], 'kra-kpi.json'), 'and the reason names the file to fix');

// A "STANDALONE" KPI — no role in the catalogue, so it is targetable in
// Organization Setup and recordable in Performance against its own target,
// exactly like every KPI worked before roles existed. Used for everything
// generic below; the target/achievement/computed-specific behaviour has its
// own section further down using a real paired triplet from the matrix.
$code = 'customer-service--customer-complaints';
ok(!isset($kpis[$code]['role']), 'the generic test KPI carries no role — it is the default, standalone, case');

// A TARGET THAT IS NOT AN EXACT NUMBER. `(float) '1 lakh'` is 1.0 and would
// silently make every collector's percentage meaningless.
ok($kraKpi->publish('', '', '', '', '', '', [$code], [$code => 'one lakh'], '2026-04-01', 'tester')['refused'] !== [],
    'a target that is not a plain number is refused');

// A TARGET TYPED WITH ITS OWN UNIT STILL ATTACHED. A rate KPI's target is
// naturally typed as "60%", and a currency one as "1,200" — both should
// publish as plain decimals, not be refused for looking like the KPI's own
// description says it should look.
$formatted = $kraKpi->publish('', '', '', 'Formatted', '', '', [$code], [$code => '60%'], '2026-04-01', 'tester');
same([], $formatted['refused'], 'a target typed with a trailing % is accepted, not refused');
same(1, $formatted['published'], 'and it publishes');
same('60', $kraKpi->resolveTarget($code, '2026-04-01', '', '', '', 'Formatted', '', '')['target_value'] ?? null,
    'stored as the plain number, with the % stripped');

$commaFormatted = $kraKpi->publish('', '', '', 'CommaFormatted', '', '', [$code], [$code => '1,200'], '2026-04-01', 'tester');
same('1200', $kraKpi->resolveTarget($code, '2026-04-01', '', '', '', 'CommaFormatted', '', '')['target_value'] ?? null,
    'a comma thousands-separator is stripped too');

// NOTHING PUBLISHES WHILE ANY KEPT CODE IS BAD — a partial publish would
// leave the scope neither the old set of targets nor the new one.
$mixed = $kraKpi->publish('', '', '', '', '', '', [$code, 'MYSTERY_KPI'], [$code => '100000', 'MYSTERY_KPI' => '5'],
    '2026-04-01', 'tester');
same(0, $mixed['published'], 'one bad kept code blocks the whole batch');

// ---------------------------------------------------------------------------
// PUBLISHING AND SCOPE PRECEDENCE.
// ---------------------------------------------------------------------------

$first = $kraKpi->publish('', '', '', '', '', '', [$code], [$code => '1000000'], '2026-04-01', 'tester', 'tenant-wide baseline');
same(1, $first['published'], 'the tenant-wide target publishes');
same(0, $first['closed'], 'nothing to close on a first publish');

$branchScoped = $kraKpi->publish('', 'Pune', '', '', '', '', [$code], [$code => '1500000'], '2026-04-01', 'tester', 'Pune runs higher');
same(1, $branchScoped['published'], 'the branch-scoped target publishes alongside it');
same(0, $branchScoped['closed'], 'a different scope tuple is untouched — the tenant-wide row stays open');

// Only the two rows published just above (no role scope) — the Formatted /
// CommaFormatted role-scoped rows from the formatting checks are in force too.
$inForce = array_values(array_filter($kraKpi->targetsInForce('2026-04-15'),
    static fn (array $r): bool => (string) ($r['scope_role'] ?? '') === ''));
same(2, count($inForce), 'both targets are in force');
same('1500000', (string) $inForce[0]['target_value'], 'the more specific (branch) target sorts ahead');

$puneTarget = $kraKpi->resolveTarget($code, '2026-04-15', 'Ametecs India', 'Pune', '', 'Collector', '', '');
same('1500000', $puneTarget['target_value'], 'a Pune employee resolves the branch-scoped target');
$hyderabadTarget = $kraKpi->resolveTarget($code, '2026-04-15', 'Ametecs India', 'Hyderabad', '', 'Collector', '', '');
same('1000000', $hyderabadTarget['target_value'], 'an employee elsewhere falls back to the tenant-wide target');

ok(in_array($code, $kraKpi->kpisEnabledFor('2026-04-15', 'Ametecs India', 'Pune', '', 'Collector', '', ''), true),
    'a Pune employee sees the kept KPI in their achievements dropdown');
ok(in_array($code, $kraKpi->kpisEnabledFor('2026-04-15', 'Ametecs India', 'Hyderabad', '', 'Collector', '', ''), true),
    'an employee elsewhere still sees it, kept tenant-wide');

// RE-PUBLISHING THE SAME SCOPE CLOSES THE OLD ROW RATHER THAN EDITING IT
// (law 3) — an achievement already recorded against the old target must stay
// explained by it.
$revised = $kraKpi->publish('', 'Pune', '', '', '', '', [$code], [$code => '1800000'], '2026-05-01', 'tester', 'revised upward');
same(1, $revised['published'], 'the revised Pune target publishes');
same(1, $revised['closed'], 'the earlier Pune row is closed, not overwritten');
same('1500000', $kraKpi->resolveTarget($code, '2026-04-20', 'Ametecs India', 'Pune', '', 'Collector', '', '')['target_value'],
    'a date before the revision still sees the old target — reprinting April must not change');
same('1800000', $kraKpi->resolveTarget($code, '2026-05-05', 'Ametecs India', 'Pune', '', 'Collector', '', '')['target_value'],
    'a date after the revision sees the new one');

// A KPI NOBODY HAS SET A TARGET FOR AT ALL — never kept, never ticked.
$other = 'delinquency-management--par';
ok($kraKpi->resolveTarget($other, '2026-05-05', 'Ametecs India', 'Pune', '', 'Collector', '', '') === null,
    'an unconfigured KPI resolves to no target, never a guess');
ok(!in_array($other, $kraKpi->kpisEnabledFor('2026-05-05', 'Ametecs India', 'Pune', '', 'Collector', '', ''), true),
    'and it is not offered on the achievements dropdown either');

// ---------------------------------------------------------------------------
// "KEPT" BUT NO NUMBER YET — the checkbox is ticked, the target box is
// blank. The KPI shows up as a choice; recording against it is still refused.
// ---------------------------------------------------------------------------

$keptOnly = 'delinquency-management--par';
$keptNoNumber = $kraKpi->publish('', '', '', 'Collector', '', '', [$keptOnly], [], '2026-04-01', 'tester', 'kept, target TBD');
same(1, $keptNoNumber['published'], 'a kept KPI with no target still publishes a row');
ok(in_array($keptOnly, $kraKpi->kpisEnabledFor('2026-04-15', 'Ametecs India', 'Anywhere', '', 'Collector', '', ''), true),
    'it appears in the dropdown for a Collector once kept, even with no number yet');
ok($kraKpi->resolveTarget($keptOnly, '2026-04-15', 'Ametecs India', 'Anywhere', '', 'Collector', '', '') === null,
    'but resolveTarget refuses it — there is still nothing to measure against');
$refusedRecord = $kraKpi->record('emp-2', 'AMT-002', 'Ravi Kumar', $keptOnly, '2026-04-01', '2026-04-30',
    '3', 'Ametecs India', 'Anywhere', '', 'Collector', '', 'tester');
ok(!$refusedRecord['ok'], 'recording against a kept-but-numberless KPI is refused');

// UNCHECKING A PREVIOUSLY KEPT KPI AND RE-SAVING REMOVES IT — full replace,
// not a merge.
$unchecked = $kraKpi->publish('', '', '', 'Collector', '', '', [], [], '2026-05-01', 'tester', 'un-kept');
same(0, $unchecked['published'], 'saving with nothing checked publishes nothing');
same(1, $unchecked['closed'], 'but still closes what was open for that exact scope');
ok(!in_array($keptOnly, $kraKpi->kpisEnabledFor('2026-05-05', 'Ametecs India', 'Anywhere', '', 'Collector', '', ''), true),
    'the un-kept KPI no longer appears for a Collector after the scope is re-saved');

// ---------------------------------------------------------------------------
// RECORDING AN ACHIEVEMENT — the percentage must be exact, and the target it
// used must be visible on the row, not just in whatever is configured today.
// ---------------------------------------------------------------------------

$recorded = $kraKpi->record('emp-1', 'AMT-001', 'Asha Rao', $code, '2026-05-01', '2026-05-31',
    '1530000', 'Ametecs India', 'Pune', '', 'Collector', '', 'tester');
ok($recorded['ok'], 'an achievement against a configured target records');
same('1800000', $recorded['row']['target_value'], 'the row freezes the target it was measured against');
same('85', $recorded['row']['percent_achieved'], '1,530,000 of 1,800,000 is exactly 85%, not a rounded guess');

// RE-RECORDING THE SAME EMPLOYEE/KPI/PERIOD IS A NEW LEDGER ROW, NOT AN EDIT
// — exactly like the Points ledger.
$again = $kraKpi->record('emp-1', 'AMT-001', 'Asha Rao', $code, '2026-05-01', '2026-05-31',
    '1620000', 'Ametecs India', 'Pune', '', 'Collector', '', 'tester');
ok($again['ok'], 'a correction records as a new entry');
same(2, count($kraKpi->achievements()), 'both entries survive — history, not an overwrite');

// AN ACHIEVED FIGURE FOR A KPI WITH NO TARGET AT ALL — refused by name,
// never a percentage against a silent zero.
$noTarget = $kraKpi->record('emp-1', 'AMT-001', 'Asha Rao', $other, '2026-05-01', '2026-05-31',
    '10', 'Ametecs India', 'Pune', '', 'Collector', '', 'tester');
ok(!$noTarget['ok'], 'an achievement against an unconfigured KPI is refused');
ok(str_contains($noTarget['why'], 'KRAs and KPIs'), 'and points at where to configure it');

// AN 'ALL' SCOPE ON PUBLISH READS AS BLANK, THE SAME WORD EVERY OTHER SCOPED
// SCREEN IN THIS CODEBASE USES.
$allScoped = $kraKpi->publish('All', 'All', '', 'All', '', '', ['delinquency-management--par'],
    ['delinquency-management--par' => '5'], '2026-04-01', 'tester');
same(1, $allScoped['published'], "'All' publishes");
same('5', $kraKpi->resolveTarget('delinquency-management--par', '2026-04-15', 'Anybody', 'Anywhere', '', 'Any role', '', '')['target_value'],
    "'All' resolved for every employee, exactly like a blank scope");

// ---------------------------------------------------------------------------
// ROLES — a "target"-role KPI (Organization Setup only), an "achievement"-role
// KPI measured against a DIFFERENT KPI's target (Performance only), and a
// "computed"-role KPI that is neither (its value is just the achievement's
// % Achieved, already shown once the pair above is used — recording or
// targeting it separately would be a second, driftable copy of that number).
// This is the exact triplet the source matrix spells out for field visits:
// Field Visits Assigned (target) / Field Visits Completed (achievement,
// measured against Assigned) / Visit Completion % (computed = the same
// arithmetic % Achieved already does).
// ---------------------------------------------------------------------------

$fvTarget = 'field-collection--field-visits-assigned';
$fvAchievement = 'field-collection--field-visits-completed';
$fvComputed = 'field-collection--visit-completion';

same('target', $kpis[$fvTarget]['role'] ?? '', 'Field Visits Assigned is a target-role KPI');
same('achievement', $kpis[$fvAchievement]['role'] ?? '', 'Field Visits Completed is an achievement-role KPI');
same($fvTarget, $kpis[$fvAchievement]['measures_against'] ?? '',
    'and it names Field Visits Assigned as what it is measured against');
same('computed', $kpis[$fvComputed]['role'] ?? '', 'Visit Completion % is a computed-role KPI');

$configurableCodes = [];
foreach ($kraKpi->configurableByKra() as $grp) {
    if ($grp['kra'] === 'Field Collection') {
        foreach ($grp['items'] as $item) {
            $configurableCodes[] = $item['code'];
        }
    }
}
ok(in_array($fvTarget, $configurableCodes, true), 'the target KPI is offered on the Organization Setup screen');
ok(!in_array($fvAchievement, $configurableCodes, true),
    'the achievement KPI is NOT — its number comes from Performance, never a target published under its own code');
ok(!in_array($fvComputed, $configurableCodes, true),
    'the computed KPI is NOT — it is arithmetic over the other two, never targeted itself');

$achievableCodes = [];
foreach ($kraKpi->achievableByKra() as $grp) {
    if ($grp['kra'] === 'Field Collection') {
        foreach ($grp['items'] as $item) {
            $achievableCodes[] = $item['code'];
        }
    }
}
ok(in_array($fvAchievement, $achievableCodes, true), 'the achievement KPI is offered on the Achievements screen');
ok(!in_array($fvTarget, $achievableCodes, true),
    'the target KPI is NOT — it IS the plan, never something achieved against itself');
ok(!in_array($fvComputed, $achievableCodes, true),
    'the computed KPI is NOT — nobody enters a figure for it either');

// AN ACHIEVEMENT-ROLE OR COMPUTED-ROLE CODE CANNOT BE KEPT DIRECTLY — even if
// a stale or crafted request tries.
$refusedAch = $kraKpi->publish('', '', '', '', '', '', [$fvAchievement], [$fvAchievement => '100'], '2026-04-01', 'tester');
ok($refusedAch['refused'] !== [], 'trying to keep the achievement KPI directly is refused');
$refusedComp = $kraKpi->publish('', '', '', '', '', '', [$fvComputed], [$fvComputed => '90'], '2026-04-01', 'tester');
ok($refusedComp['refused'] !== [], 'trying to keep the computed KPI directly is refused');

// RECORDING THE ACHIEVEMENT KPI RESOLVES THE TARGET FROM ITS PAIRED TARGET
// KPI — never from a target published under the achievement's own code
// (there can never be one — publish() refuses that above).
ok($kraKpi->record('emp-3', 'AMT-003', 'Kiran Shah', $fvAchievement, '2026-06-01', '2026-06-30',
    '180', 'Ametecs India', 'Chennai', '', 'Collector', '', 'tester')['ok'] === false,
    'recording the achievement KPI before its paired target is published is refused');
$fvMeasuredAgainst = $kraKpi->record('emp-3', 'AMT-003', 'Kiran Shah', $fvAchievement, '2026-06-01', '2026-06-30',
    '180', 'Ametecs India', 'Chennai', '', 'Collector', '', 'tester');
ok(str_contains($fvMeasuredAgainst['why'], 'Field Visits Assigned'),
    'and the refusal names the KPI it is actually measured against, not just its own name');

$fvPublish = $kraKpi->publish('', 'Chennai', '', '', '', '', [$fvTarget], [$fvTarget => '200'], '2026-06-01', 'tester');
same(1, $fvPublish['published'], 'the target KPI publishes normally, like any other target');
ok(in_array($fvAchievement, $kraKpi->kpisEnabledFor('2026-06-15', 'Ametecs India', 'Chennai', '', 'Collector', '', ''), true),
    'once the TARGET is kept, the paired ACHIEVEMENT KPI is offered — even though it was never itself kept');

$fvRecorded = $kraKpi->record('emp-3', 'AMT-003', 'Kiran Shah', $fvAchievement, '2026-06-01', '2026-06-30',
    '180', 'Ametecs India', 'Chennai', '', 'Collector', '', 'tester');
ok($fvRecorded['ok'], 'recording Field Visits Completed against Field Visits Assigned\'s target now succeeds');
same('200', $fvRecorded['row']['target_value'], 'the frozen target is the one published for Field Visits Assigned');
same('90', $fvRecorded['row']['percent_achieved'], '180 of 200 is exactly 90%');
