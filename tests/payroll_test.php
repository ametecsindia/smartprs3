<?php

declare(strict_types=1);

use SmartPRS\Platform\Payroll\InputSnapshot;
use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Payroll\PayslipCalculator;
use SmartPRS\Platform\Payroll\Reconciliation;
use SmartPRS\Platform\Payroll\Recovery;
use SmartPRS\Platform\Payroll\RunAborted;
use SmartPRS\Platform\Payroll\SalaryComponent;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

group('payroll');

/** @return list<SalaryComponent> a structure where nothing is decided by a name */
$structure = static fn (): array => [
    new SalaryComponent('X1', SalaryComponent::EARNING, SalaryComponent::PERCENT_OF_GROSS,
        ['prorates' => true, 'pf_base' => true], ratePercent: 50),
    new SalaryComponent('X2', SalaryComponent::EARNING, SalaryComponent::PERCENT_OF,
        ['prorates' => true], ofComponent: 'X1', ratePercent: 40),
    new SalaryComponent('X3', SalaryComponent::EARNING, SalaryComponent::BALANCE, ['prorates' => true]),
    new SalaryComponent('X4', SalaryComponent::DEDUCTION, SalaryComponent::FLAT,
        ['prorates' => false], flatAmount: '200.00'),
];

// -----------------------------------------------------------------------------
// The split is exact, and the parts sum to the whole even when it does not divide.
// -----------------------------------------------------------------------------

$slip = (new PayslipCalculator($structure()))
    ->calculate('E-1', '2026-09', Money::of('50000.00'), Rational::of(22), 24);

same('45833.33', $slip->grossEarnings()->toDecimalString(),
    'the gross is prorated ONCE, before it is split');
same('13749.99', (string) $slip->lineFor('X3')?->amount->toDecimalString(),
    'the balance component absorbs the residue rather than being computed at its own rate');

$sum = Money::zero();

foreach ($slip->linesOfKind(SalaryComponent::EARNING) as $line) {
    $sum = $sum->plus($line->amount);
}

ok($sum->equals($slip->grossEarnings()), 'the earning lines sum to the gross EXACTLY, not to within a paisa');

// -----------------------------------------------------------------------------
// A negative net is the answer.
// -----------------------------------------------------------------------------

$thin = (new PayslipCalculator($structure()))
    ->calculate('E-2', '2026-09', Money::of('1000.00'), Rational::of(1), 30);

same('-166.67', $thin->netPay()->toDecimalString(),
    'a month whose fixed deductions exceed a prorated salary states the negative rather than clamping it');
ok($thin->grossEarnings()->minus($thin->totalDeductions())->equals($thin->netPay()),
    'RC-03 holds on the payslip an employee actually receives');

// A flat deduction does not prorate: whether it does is a FLAG.
same('200.00', (string) $thin->lineFor('X4')?->amount->toDecimalString(),
    'a flat statutory deduction is for the month and is not scaled by payable days');

// -----------------------------------------------------------------------------
// The reconciliation aborts, and it names the difference.
// -----------------------------------------------------------------------------

$run = new PayrollRun('2026-09', $structure(), [
    ['employment_id' => 'E-1', 'gross' => Money::of('50000.00'), 'payable_days' => Rational::of(30), 'period_days' => 30],
]);

throws(RunAborted::class, static function () use ($run): void {
    Reconciliation::assertOrAbort([$run->payslips[0]->withPerturbation('X1', 1)], $run->totals());
}, 'a one-paisa perturbation aborts the run rather than being tolerated');

try {
    Reconciliation::assertOrAbort([$run->payslips[0]->withPerturbation('X1', 1)], $run->totals());
} catch (RunAborted $e) {
    ok($e->failed('RC-04'), 'the abort names the check that caught it');
    ok(str_contains($e->getMessage(), '0.01'), 'the abort reports the difference to the paisa');
}

// A line with no trace cannot be constructed at all — Law 4 is structural.
throws(InvalidArgumentException::class, static fn () => new SmartPRS\Platform\Payroll\PayslipLine(
    'X1', 'X1-v1', SalaryComponent::EARNING, Money::zero(), Money::zero(), '  '),
    'a payslip line with no trace is refused, because a figure that cannot explain itself is one somebody '
    . 'has to reconstruct from today\'s configuration rather than the day\'s');

// -----------------------------------------------------------------------------
// Re-running is reproducible, and honest about drift.
// -----------------------------------------------------------------------------

$inputs = [
    ['employment_id' => 'E-1', 'gross' => Money::of('50000.00'), 'payable_days' => Rational::of(30), 'period_days' => 30],
];
$original = new PayrollRun('2026-09', $structure(), $inputs);
$again = $original->rerun($inputs);

ok($again['reproduced'], 'a re-run from identical inputs reproduces every figure to the paisa');
same([], $again['changed_inputs'], 'and reports nothing changed, so "nothing changed" means something');

$snapshot = $original->snapshots['E-1'];
$revised = new InputSnapshot('E-1', '2026-09', Money::of('51000.00'), Rational::of(30), 30,
    $snapshot->componentVersions, []);

ok(str_contains(implode(' ', $snapshot->diff($revised)), 'contracted_gross'),
    'a changed input is NAMED rather than counted');
ok($snapshot->diff($snapshot) === [], 'a snapshot does not differ from itself');

// -----------------------------------------------------------------------------
// A recovery reverses to exactly what it was, as an entry.
// -----------------------------------------------------------------------------

$loan = new Recovery('E-1', 'R1', Money::of('20000.00'), Money::of('5000.00'));
$before = $loan->outstanding();
$loan->take('2026-09');

same('15000.00', $loan->outstanding()->toDecimalString(), 'the instalment reduces the outstanding');

$loan->reverse('2026-09', 'The payslip it was taken against was itself reversed.');

ok($loan->outstanding()->equals($before), 'the reversal restores the outstanding EXACTLY');
same(2, count($loan->history()),
    'the reversal is a compensating ENTRY, not a deletion — deleting gives the same balance and destroys '
    . 'the evidence that a deduction the employee saw ever happened');

throws(InvalidArgumentException::class, static fn () => $loan->reverse('2026-09', 'again'),
    'the same instalment cannot be reversed twice');

$tail = new Recovery('E-1', 'R1', Money::of('250.00'), Money::of('100.00'));
$tail->take('2026-07');
$tail->take('2026-08');

same('50.00', $tail->take('2026-09')->toDecimalString(),
    'the final instalment is the remainder, not the standing amount');
ok($tail->outstanding()->isZero(), 'a fully recovered loan owes nothing');

throws(InvalidArgumentException::class,
    static fn () => $tail->take('2026-10', Money::of('100.00')),
    'over-recovering is refused: it is money the employer holds with no instrument behind it');
