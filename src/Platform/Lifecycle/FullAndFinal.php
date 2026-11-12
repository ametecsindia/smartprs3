<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Lifecycle;

use SmartPRS\Platform\Support\Money;

/**
 * THE LAST PAYSLIP, AND EVERY OPEN THING CLOSED — Part XX, Part XXXII Phase 7.
 *
 * A full-and-final settlement is where every loose end in the system arrives at
 * once: unrecovered loans, unencashed leave, notice-period shortfall, held
 * amounts, pending claims. What makes it go wrong is not the arithmetic; it is
 * that a component nobody enumerated stays open, and the employee is gone.
 *
 * So this class REFUSES to settle while anything is unresolved, and names what.
 * "Settled with two items outstanding" is the shape that turns into a legal
 * letter eighteen months later, because at that point there is no employment
 * record to attach the recovery to and no employee to ask.
 *
 * Net is NOT clamped, for the same reason it is not clamped on a payslip: a
 * negative settlement means the employee owes the company, which is a real
 * situation with a real remedy that somebody decides. Hiding it behind a zero
 * destroys the evidence that anything was owed.
 */
final class FullAndFinal
{
    /** @var array<string, array{amount: Money, resolved: bool, note: string}> */
    private array $items = [];

    public function __construct(
        public readonly string $employmentId,
        public readonly \DateTimeImmutable $exitDate,
    ) {
    }

    public function add(string $code, Money $amount, bool $resolved, string $note): void
    {
        if (trim($note) === '') {
            throw new \InvalidArgumentException(
                "Settlement item '{$code}' carries no note. Every line on a final settlement is a line the "
                . 'employee will ask about, and they will ask after they have left.'
            );
        }

        $this->items[$code] = ['amount' => $amount, 'resolved' => $resolved, 'note' => $note];
    }

    /** @return list<string> everything still open, named */
    public function outstanding(): array
    {
        $open = [];

        foreach ($this->items as $code => $item) {
            if (!$item['resolved']) {
                $open[] = "{$code} ({$item['amount']->format()}): {$item['note']}";
            }
        }

        return $open;
    }

    public function net(): Money
    {
        $net = Money::zero();

        foreach ($this->items as $item) {
            $net = $net->plus($item['amount']);
        }

        return $net;
    }

    /**
     * @return array{settled: bool, net: Money, outstanding: list<string>, why: string}
     */
    public function settle(): array
    {
        $outstanding = $this->outstanding();

        if ($outstanding !== []) {
            return [
                'settled' => false,
                'net' => $this->net(),
                'outstanding' => $outstanding,
                'why' => 'the settlement cannot be issued while ' . count($outstanding) . ' item(s) remain '
                    . 'open. "Settled, with two items outstanding" is the shape that becomes a legal letter '
                    . 'eighteen months later — by which time there is no employment record to attach a '
                    . 'recovery to and no employee to ask',
            ];
        }

        return [
            'settled' => true,
            // Not clamped. A negative settlement means the employee owes the
            // company, which is a real situation with a real remedy that a
            // person decides; a zero destroys the evidence that anything was owed.
            'net' => $this->net(),
            'outstanding' => [],
            'why' => 'every open item is resolved and the settlement is '
                . ($this->net()->isNegative() ? 'a RECOVERY of ' . $this->net()->absolute()->format()
                    . ' owed by the employee' : 'a payment of ' . $this->net()->format()),
        ];
    }
}
