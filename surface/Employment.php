<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\RoundingMode;

/**
 * ONE PLACE THAT ANSWERS "WHAT IS THIS EMPLOYMENT'S CODE?"
 *
 * SmartPRS 3's screens were written against `employee_code`. The ported
 * `employees` table calls it `emp_code`. Twenty-nine reads across the front
 * controller, the payroll register and full-and-final were therefore asking for
 * a column that does not exist — and every one of them worked perfectly through
 * months of development, because the file store returns whatever key was written
 * into it and the code that wrote the fixtures used the same wrong name.
 *
 * That is the shape of every MySQL-only defect this rebuild has found: the file
 * store agrees with whatever you tell it, so a name that is wrong is wrong
 * consistently and looks right. It surfaces the first time a real schema is
 * involved, which is the worst possible moment — a payroll register with a blank
 * code column against a real month.
 *
 * So the question is asked ONCE here. `emp_code` first because that is what the
 * table has; `employee_code` after, because fixtures and older rows still carry
 * it and silently dropping them would turn a naming defect into missing data.
 * When neither is there the row says so rather than returning an empty string
 * that prints as a blank cell: a payslip with no code on it is one nobody can
 * match to a person.
 */
final class Employment
{
    /** The column the ported schema actually uses. */
    public const CODE_COLUMN = 'emp_code';

    /** What older rows and fixtures call it. Read, never written. */
    public const LEGACY_CODE_COLUMN = 'employee_code';

    /**
     * The ported name for every employee column this application had wrong,
     * and the name it used to use.
     *
     * `employee_code` was the first one found. The smoke check then opened the
     * Employees screen against a real schema and found `gross` and `joined_on`
     * in the next two cells, and `bank_account`/`bank_ifsc` on the bank advice.
     * Five columns, one mistake, made once and copied: the screens were written
     * before the port and nothing ever loaded them against the real table.
     *
     * @var array<string, string> what the code used to say => what the table says
     */
    public const PORTED = [
        'employee_code' => 'emp_code',
        'gross' => 'ctc',
        'joined_on' => 'doj',
        'bank_account' => 'bank_acc',
        'bank_ifsc' => 'ifsc',
    ];

    /**
     * Read a column by either name — the schema's first, the old one after.
     *
     * The fallback is for rows written before the port and for fixtures, and it
     * is READ-ONLY: nothing writes the old name any more. Dropping the fallback
     * would turn a naming defect into missing data on rows that already exist.
     */
    public static function value(array $employment, string $column, string $whenMissing = ''): string
    {
        $ported = self::PORTED[$column] ?? $column;
        $legacy = array_search($column, self::PORTED, true);

        foreach ([$ported, $column, $legacy === false ? null : $legacy] as $candidate) {
            if ($candidate !== null && trim((string) ($employment[$candidate] ?? '')) !== '') {
                return (string) $employment[$candidate];
            }
        }

        return $whenMissing;
    }

    /** The contracted cost as stored, as a decimal string `Money::of()` accepts. */
    public static function ctc(array $employment): string
    {
        // Through Amount, not (float). This is the parse every money figure in the
        // product enters by, so a float here is a float everywhere downstream —
        // and DECIMAL(18,4) out of the driver is a string with four decimal places
        // that has to lose two of them somewhere. Losing them in binary is how a
        // total ends in ...99.
        return Amount::of(self::value($employment, 'ctc', '0'))->toDecimalString();
    }

    /**
     * ONE MONTH'S GROSS, which is not the same number as the contract.
     *
     * The contracted figure is usually a year's cost in this market, and it was
     * being handed to the payroll run as the month's gross — every payslip in the
     * product twelve times too high, with a per-line trace under each figure
     * explaining the arithmetic correctly. Nobody would catch it by reading a
     * payslip; the working checks out. That is why this is a named method with a
     * declared basis rather than a `/ 12` somewhere in the register.
     *
     * DIVIDED THROUGH `Money`, NOT FLOAT. A twelfth of ₹4,20,001 does not exist in
     * paise, so somebody has to decide where the fraction goes: HalfUp, the payroll
     * default, and the same one the rest of this product rounds with. In float it
     * would land wherever the binary representation put it, differently on a
     * different machine, and the twelve months would not sum back to the year.
     *
     * ponytail: the remainder is not tracked across the year — twelve HalfUp
     * twelfths of a non-divisible annual cost can differ from the annual figure by
     * a few paise. Carry it when a customer reconciles annual CTC to the sum of
     * twelve payslips to the rupee; `Money::allocate()` already does exactly that
     * and takes a weights array of twelve ones.
     *
     * @param string $basis 'annual' or 'monthly', from Structure::contractedCostBasis()
     */
    public static function monthlyGross(array $employment, string $basis): Money
    {
        $contracted = Money::of(self::ctc($employment));

        return $basis === 'monthly'
            ? $contracted
            : $contracted->dividedBy(12, RoundingMode::HalfUp);
    }

    /**
     * LAKHS PER ANNUM — the unit this market quotes a salary in — AS RUPEES.
     *
     * A NAMED METHOD for the reason monthlyGross() above is one: a `/ 12` loose
     * in the register made every payslip in the product twelve times too high,
     * with a per-line trace under each figure that checked out. A `* 100000`
     * loose in the front controller is the same bet on the same table.
     *
     * EXACT (Law 7). This was `(float) $lpa * 100000` fed through
     * number_format() — the figure a previewed payslip is priced from, made in
     * binary. Rational multiplies without ever leaving exact arithmetic; two
     * places is the currency's minor unit, which is the string Money::of()
     * reads, and anything finer than a paisa is dropped here rather than
     * refused by Money.
     */
    public static function annualFromLpa(string|Rational $lpa): Money
    {
        // A caller that has already parsed the box (lpaOrNull, to tell "not a
        // figure" from "too small") hands the Rational straight over. Rendering
        // it back to a string for this method to re-parse would be a lossy step
        // in the middle of the one calculation that must not have one.
        $exact = $lpa instanceof Rational ? $lpa : self::lpaOrNull($lpa);

        if ($exact === null) {
            throw new \InvalidArgumentException("Not an LPA figure: {$lpa}");
        }

        return Money::of($exact->multipliedBy(Rational::of(100000))->toDecimalString(2));
    }

    /**
     * THE LPA BOX'S CONTENTS, PARSED, or null when it does not hold a figure.
     *
     * is_numeric() was the guard, and it accepts "1e3", ".5" and "0x1A" — none
     * of which is a lakh figure, and none of which Rational::fromDecimalString()
     * will parse. Asking here makes the refusal a message the screen can print
     * rather than an uncaught exception on a JSON endpoint three lines later.
     * Negative is refused too: a cost is not negative, and letting one through
     * only moves the argument to whoever reads the preview.
     */
    public static function lpaOrNull(string $lpa): ?Rational
    {
        return preg_match('/^\d+(\.\d+)?$/', $lpa) === 1
            ? Rational::fromDecimalString($lpa)
            : null;
    }

    /**
     * AN EMPLOYMENT'S STORED CTC, IN LAKHS, for the CTC (LPA) box.
     *
     * The inverse of annualFromLpa(), and the same Law 7 point: this was
     * `round((float) self::ctc($e) * 12 / 100000, 4)`.
     *
     * Four places, ROUNDED HalfUp — deliberately the rounding round() already
     * did, and the one the rest of this product rounds with. Rational's own
     * toDecimalString() truncates instead, and truncating moved the fourth
     * decimal down on roughly one real CTC in three (₹1,04,166.67 annual read
     * 1.0416 where it had read 1.0417). Exactness was the point of this change;
     * moving a figure an owner recognises was not, so the arithmetic is exact
     * and the number in the box is the number that was in it before.
     *
     * toDecimalString() drops trailing zeros and the point with them, so a round
     * ₹1,00,00,000 reads "100" — do NOT rtrim('0') the result, which would turn
     * that into "1".
     *
     * @param string $basis 'annual' or 'monthly', from Structure::contractedCostBasis()
     */
    public static function ctcAsLpa(array $employment, string $basis): string
    {
        $ctc = Rational::fromDecimalString(self::ctc($employment));
        $lpa = ($basis === 'monthly' ? $ctc->multipliedBy(Rational::of(12)) : $ctc)
            ->dividedBy(Rational::of(100000));

        return Rational::of(
            $lpa->multipliedBy(Rational::of(10000))->toInt(RoundingMode::HalfUp),
            10000,
        )->toDecimalString(4);
    }

    public static function joinedOn(array $employment, string $whenMissing = ''): string
    {
        return self::value($employment, 'doj', $whenMissing);
    }

    public static function code(array $employment): string
    {
        foreach ([self::CODE_COLUMN, self::LEGACY_CODE_COLUMN] as $column) {
            if (trim((string) ($employment[$column] ?? '')) !== '') {
                return (string) $employment[$column];
            }
        }

        return '';
    }

    /**
     * The code, or a stated absence.
     *
     * Never an empty string. A blank cell in a register reads as a rendering
     * fault or as data somebody forgot to enter, and those are different
     * problems with different fixes.
     */
    public static function codeOrSay(array $employment): string
    {
        $code = self::code($employment);

        return $code === '' ? 'no code recorded' : $code;
    }

    /** How an employee should be named on screen: code and name, never an id. */
    public static function label(array $employment): string
    {
        $parts = array_filter([
            self::code($employment),
            trim((string) ($employment['name'] ?? '')),
        ], static fn (string $part): bool => $part !== '');

        return $parts === []
            ? '#' . (string) ($employment['id'] ?? '?')
            : implode(' · ', $parts);
    }
}
