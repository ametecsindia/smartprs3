<?php

declare(strict_types=1);

use SmartPRS\Platform\Support\Money;
use SmartPRS\Surface\Employment;

/**
 * CTC ↔ LPA, EXACTLY (Law 7).
 *
 * "LPA" is how this market quotes a salary — lakhs per annum — and two screens
 * convert between it and rupees: the Salary Structure preview turns a typed LPA
 * figure into the annual cost it prices a whole payslip against, and the Payslip
 * Format designer fills its CTC (LPA) box from a real employee's stored rupees.
 *
 * Both did it in float: `(float) $lpa * 100000` one way, `round($ctc * 12 /
 * 100000, 4)` the other. Neither produced a wrong figure at any salary anybody
 * is paid — `number_format(…, 2)` rounded the error away before Money saw it —
 * so this is a latent violation rather than a live defect, and it is fixed for
 * the reason Law 7 is absolute rather than advisory: the SmartPRS 2 instalment
 * defect was latent too, right up until the residual balance of one loan landed
 * a fraction of a paisa from its comparand.
 *
 * The conversion is a NAMED METHOD for the same reason monthlyGross() is one —
 * its own docblock says a `/ 12` loose in the register is how every payslip in
 * the product came out twelve times too high, with a correct-looking trace under
 * each figure. A `/ 100000` loose in the front controller is the same bet.
 */

group('CTC ↔ LPA');

// --- lakhs to rupees --------------------------------------------------------

same('1250000.00', Employment::annualFromLpa('12.5')->toDecimalString(),
    '12.5 LPA is ₹12,50,000');
same('1000000.00', Employment::annualFromLpa('10')->toDecimalString(),
    'a whole number of lakhs is still an exact amount');
same('1234567.89', Employment::annualFromLpa('12.3456789')->toDecimalString(),
    'the fifth decimal of an LPA figure is a paisa, and it survives');
same('100.00', Employment::annualFromLpa('0.001')->toDecimalString(),
    'a thousandth of a lakh is ₹100 exactly, not ₹99.99…');

// THE SHAPE THE FRONT CONTROLLER MUST NOT LET THROUGH. is_numeric() accepts all
// of these and Rational::fromDecimalString() accepts none, so the guard is a
// message rather than an uncaught exception on an AJAX endpoint.
foreach (['1e3', '.5', '0x1A', '', '-3', '12.5.1', ' 12.5 '] as $notLpa) {
    same(null, Employment::lpaOrNull($notLpa), "'{$notLpa}' is not an LPA figure");
}

// Rational's __toString() is the exact fraction ("25/2"), which is the point of
// it — compare the decimal rendering, not the object's own notation.
same('12.5', Employment::lpaOrNull('12.5')?->toDecimalString(4), 'a plain decimal is');
same('0', Employment::lpaOrNull('0')?->toDecimalString(4),
    'zero parses — the caller decides it is too small to price');

// --- rupees to lakhs --------------------------------------------------------

$annual = ['ctc' => '1250000'];
$monthly = ['ctc' => '104166.67'];

same('12.5', Employment::ctcAsLpa($annual, 'annual'),
    'an annual CTC of ₹12,50,000 shows as 12.5');
// ₹1,04,166.67 × 12 = ₹12,50,000.04, which is 12.5000004 LPA — 12.5 at four
// places, and toDecimalString() drops the trailing zeros itself.
same('12.5', Employment::ctcAsLpa($monthly, 'monthly'),
    'a monthly CTC is multiplied by twelve first');

// ROUNDED, NOT TRUNCATED. Rational::toDecimalString() truncates, and using it
// directly moved the fourth decimal down on about one real CTC in three —
// ₹1,04,166.67 is 1.041666…, which reads 1.0417 and always has. Exactness was
// the point of this change; moving a figure an owner recognises was not.
same('1.0417', Employment::ctcAsLpa(['ctc' => '104166.67'], 'annual'),
    'the fourth decimal rounds HalfUp, exactly as round() did');
same('77.7778', Employment::ctcAsLpa(['ctc' => '7777777.77'], 'annual'),
    'and again where truncation would have read 77.7777');
same('0', Employment::ctcAsLpa(['ctc' => '0'], 'annual'),
    'no CTC recorded reads as 0, and the screen blanks the box rather than typing it');

// TRAILING ZEROS ARE NOT TYPED INTO THE BOX. toDecimalString() already drops
// them — the guard here is against anybody "helpfully" adding an rtrim('0'),
// which turns the exact string "100" into "1".
same('100', Employment::ctcAsLpa(['ctc' => '10000000'], 'annual'),
    'a round ₹1,00,00,000 is 100 LPA, not 1');

// --- the property the whole change rests on ---------------------------------

// Round-tripping must return the figure typed, for every value the LPA box can
// hold (step="0.01"). In float this is the loop that eventually disagrees.
$drift = [];

for ($paise = 1; $paise <= 5000; $paise++) {
    $lpa = number_format($paise / 100, 2, '.', '');
    $back = Employment::ctcAsLpa(['ctc' => Employment::annualFromLpa($lpa)->toDecimalString()], 'annual');

    if ((float) $back !== (float) $lpa) {
        $drift[] = "{$lpa} → {$back}";
    }
}

same([], $drift, 'every LPA the box can hold round-trips through rupees unchanged');
