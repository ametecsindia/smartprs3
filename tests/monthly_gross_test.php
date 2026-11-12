<?php

declare(strict_types=1);

/**
 * WHAT ONE MONTH'S GROSS IS, ASSERTED IN RUPEES.
 *
 * This file exists because of the worst defect this build has carried. The `ctc`
 * column holds a year's cost — ₹7,20,000 for a seeded employment — and the payroll
 * register handed that figure to the run as THE MONTH'S GROSS. Every payslip the
 * product could produce was twelve times too high.
 *
 * Nothing caught it, and nothing was going to. Seven thousand assertions passed:
 * the money arithmetic was exact, the components summed, the fingerprint verified,
 * the reconciliation balanced, the net was the gross minus the deductions to the
 * paisa. Every one of those checks compared the system against ITSELF. And under
 * each line the payslip printed the working — "50% of ₹7,20,000 = ₹3,60,000" —
 * which is arithmetically true, so a person reading it sees the sum check out and
 * concludes the input must be right. A wrong number with correct working under it
 * is worse than a wrong number on its own.
 *
 * So the assertions here are RUPEE FIGURES A PERSON CAN CHECK BY HAND, not
 * relationships between quantities the code computed. That is the only kind of
 * assertion that could have caught this, and it is the reason a suite this large
 * did not. A test that says "the parts sum to the whole" is satisfied by a whole
 * that is twelve times too big.
 */

use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Structure;
use SmartPRS\Surface\Store;

group('monthly gross');

$annual = ['emp_code' => 'E-1', 'name' => 'One', 'ctc' => '720000.0000'];

// ---------------------------------------------------------------- the figure
same('₹60,000.00', Employment::monthlyGross($annual, 'annual')->format(),
    'A contracted annual cost of ₹7,20,000 is ₹60,000 of gross in a month. Written out in rupees '
    . 'on purpose: this is the assertion that fails if the twelfth ever goes away again, and it '
    . 'is checkable by a person who knows nothing about the code.');

same('₹7,20,000.00', Employment::monthlyGross($annual, 'monthly')->format(),
    'Declared monthly, the same stored figure IS the month. A customer who quotes monthly CTC '
    . 'changes a config value, not code.');

// The two answers must differ, or the basis is being read and ignored and both
// assertions above would pass against a method that returned the same thing twice.
ok(!Employment::monthlyGross($annual, 'annual')
    ->equals(Employment::monthlyGross($annual, 'monthly')),
    'and the two bases genuinely produce different numbers — otherwise the basis is decorative '
    . 'and both figures above are one figure asserted twice.');

// -------------------------------------------------------- the paisa nobody owns
// A twelfth of this does not exist in paise. It has to land somewhere, and it has
// to land there through Money, because in float it lands wherever the binary
// representation put it — differently on a different machine.
$awkward = ['emp_code' => 'E-2', 'name' => 'Two', 'ctc' => '420001.0000'];
$twelfth = Employment::monthlyGross($awkward, 'annual');

same('₹35,000.08', $twelfth->format(),
    'A twelfth of ₹4,20,001 is ₹35,000.0833…, which does not exist in paise. It rounds HalfUp — '
    . 'the payroll default — to a stated figure rather than to whatever a float happened to hold.');

// ------------------------------------------------------------- the basis is read
$structure = new Structure(
    (new Store(sys_get_temp_dir() . '/prs3-basis-' . bin2hex(random_bytes(4))))->forTenant('1'),
    __DIR__ . '/../config/salary-structure.json',
);

same('annual', $structure->contractedCostBasis('2026-09-30'),
    'The shipped structure declares the contracted figure as annual, and the register reads it '
    . 'from there rather than assuming.');

// AN UNRECOGNISED BASIS IS REFUSED, NOT DEFAULTED. "yearly" or a typo silently
// meaning monthly would reintroduce the twelve-times defect through the spelling
// of a config value, which is exactly how a fixed bug comes back.
$refused = false;

try {
    (new Structure(
        (new Store(sys_get_temp_dir() . '/prs3-basis2-' . bin2hex(random_bytes(4))))->forTenant('1'),
        __DIR__ . '/fixtures/structure-bad-basis.json',
    ))->contractedCostBasis('2026-09-30');
} catch (\RuntimeException $e) {
    $refused = str_contains($e->getMessage(), 'does not understand');
}

ok($refused, 'A basis this build does not understand is refused with a reason, never quietly '
    . 'treated as monthly. Guessing wrong there multiplies every payslip in the month by twelve '
    . 'and the payslip would show its working and look right.');
