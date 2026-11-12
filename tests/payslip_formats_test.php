<?php

declare(strict_types=1);

use SmartPRS\Surface\ComponentRules;
use SmartPRS\Surface\PayslipFormats;
use SmartPRS\Surface\Store;

/**
 * WHOSE PAYSLIP LOOKS LIKE WHAT, WITH NO AMBIGUITY.
 *
 * Two formats that both apply to the same person is the defect this file is
 * mostly about: whichever the sort happens to return first is not a decision
 * anybody made, and "why does this payslip look like that" has to have one
 * answer. The ladder — company, branch, team, designation, employee — is the
 * same one the salary component rules resolve by, on purpose.
 */

group('Payslip formats');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/pf-' . bin2hex(random_bytes(4))))->forTenant('1');
$components = new ComponentRules($store, $root . '/config/salary-components.json');
$formats = new PayslipFormats($store, $components);

$lines = static fn (array $codes): array => array_combine($codes, array_map(
    static fn (int $i): array => ['checked' => true, 'order' => $i + 1], array_keys($codes)));

// A FORMAT WITH NO NAME AND A FORMAT WITH NO LINES ARE BOTH REFUSED.
ok($formats->refusals(['name' => '', 'lines' => $lines(['BASIC'])]) !== [],
    'a format with no name is refused — "Format 3" on a list of four is no choice at all');
ok($formats->refusals(['name' => 'Empty', 'lines' => []]) !== [],
    'a format with nothing ticked would print a blank payslip, and is refused');
ok($formats->refusals(['name' => 'Ghost', 'lines' => ['NOT_A_COMPONENT' =>
    ['checked' => true]]]) !== [], 'a line for a component nothing computes is refused');

// ---------------------------------------------------------------------------
// SAVING AND RESOLVING. Most specific wins; a named scope that does not match
// does not apply at all.
// ---------------------------------------------------------------------------

$formats->save(['name' => 'House standard', 'lines' => $lines(['BASIC', 'HRA', 'PF_EMPLOYEE'])],
    'tester');
$formats->save(['name' => 'Mumbai field', 'branch' => 'Mumbai - Andheri East',
    'lines' => $lines(['BASIC', 'CONVEYANCE', 'OT'])], 'tester');
$formats->save(['name' => 'One person', 'branch' => 'Mumbai - Andheri East',
    'designation' => 'Recovery Manager', 'employee' => 'E-1001',
    'lines' => $lines(['BASIC'])], 'tester');

same(3, count($formats->all()), 'all three saved');

same('House standard', (string) $formats->forContext(['branch' => 'Hyderabad'])['name'],
    'somebody the branch format does not name gets the tenant-wide one');
same('Mumbai field', (string) $formats->forContext(
    ['branch' => 'Mumbai - Andheri East'])['name'],
    'and somebody in that branch gets the branch format');
same('One person', (string) $formats->forContext(['branch' => 'Mumbai - Andheri East',
    'designation' => 'Recovery Manager', 'employee' => 'E-1001'])['name'],
    'the most specific format wins — three scopes beat one');

// A FORMAT THAT NAMES A BRANCH DOES NOT APPLY IN ANOTHER BRANCH. Not a weaker
// match: not a match. Without this a "Mumbai" format would print in Hyderabad
// whenever nothing else matched, which is the ambiguity this ladder removes.
same('House standard', (string) $formats->forContext(['branch' => 'Chennai',
    'designation' => 'Recovery Manager'])['name'],
    'a format naming a branch does not apply to somebody in another branch');

// TWO FORMATS FOR EXACTLY THE SAME SCOPE CANNOT BOTH EXIST.
$clash = $formats->refusals(['name' => 'Mumbai field again',
    'branch' => 'Mumbai - Andheri East', 'lines' => $lines(['BASIC'])]);
ok($clash !== [], 'a second format for exactly the same scope is refused');
ok(str_contains(implode(' ', $clash), 'Mumbai field'),
    'and the reason names the format already there');

// EDITING THAT SAME FORMAT IS NOT A CLASH WITH ITSELF.
$mumbai = $formats->forContext(['branch' => 'Mumbai - Andheri East']);
same([], $formats->refusals(['id' => (string) $mumbai['id'], 'name' => 'Mumbai field',
    'branch' => 'Mumbai - Andheri East', 'lines' => $lines(['BASIC'])]),
    'saving the same format again is not a clash with itself');

// ---------------------------------------------------------------------------
// LINES. Ordered as configured, grouped by the kind the CATALOGUE declares —
// never by what the format says, so an earning cannot be printed as a deduction.
// ---------------------------------------------------------------------------

$formats->save(['name' => 'Ordered', 'team' => 'Collections', 'lines' => [
    'HRA' => ['checked' => true, 'order' => 2, 'label' => 'House rent'],
    'BASIC' => ['checked' => true, 'order' => 1],
    'PF_EMPLOYEE' => ['checked' => true, 'order' => 1],
    'PF_EMPLOYER' => ['checked' => true, 'order' => 1],
    'LTA' => ['checked' => false, 'order' => 9],
]], 'tester');

$ordered = $formats->linesOf($formats->forContext(['team' => 'Collections']));

same(['BASIC', 'HRA'], array_column($ordered['earning'], 'code'),
    'earnings print in the order the format gives them');
same('House rent', (string) $ordered['earning'][1]['label'],
    'a renamed line prints under its new name');
same('Basic', (string) $ordered['earning'][0]['label'],
    'and one that was not renamed falls back to the component label from the catalogue');
same(['PF_EMPLOYEE'], array_column($ordered['deduction'], 'code'),
    'a deduction lands in the deductions block — the kind comes from the catalogue, not the format');
same(['PF_EMPLOYER'], array_column($ordered['employer_cost'], 'code'),
    'and an employer cost in its own, because it never reduces net pay');
ok(!in_array('LTA', array_column($ordered['earning'], 'code'), true),
    'a line that was not ticked is not in the format at all');

// Specificity is COUNTED from the scopes set, never typed.
same(1, (int) $formats->forContext(['team' => 'Collections'])['specificity'],
    'one scope set is specificity 1');
same(3, (int) $formats->forContext(['branch' => 'Mumbai - Andheri East',
    'designation' => 'Recovery Manager', 'employee' => 'E-1001'])['specificity'],
    'three scopes set is specificity 3');

// RETIRED, NOT DELETED — and a retired format stops matching, so the people it
// applied to fall back to the next one rather than to nothing.
$formats->retire((string) $mumbai['id'], 'tester');
same('House standard', (string) $formats->forContext(
    ['branch' => 'Mumbai - Andheri East'])['name'],
    'retiring a format falls back to the next that matches');
same(3, count($formats->all()), 'and it is gone from the list of formats in use');
