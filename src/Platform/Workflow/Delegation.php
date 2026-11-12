<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Workflow;

/**
 * A DELEGATION OR AN OUT-OF-OFFICE BACKUP, WITH THE DATES THAT MAKE IT REAL —
 * Part IX §IX.5.5, steps 4 and 5 of participant resolution.
 *
 * Until now the resolver took `array<from, to>`: a bare map with no dates. That
 * shape cannot express an EXPIRED delegation at all, so the conformance class
 * asking for "active, expired and circular" could not be written against it —
 * two of the three states did not exist. A delegation that never expires is not
 * a simplification, it is the one that is still routing approvals to somebody
 * who left in March.
 *
 * Three properties this adds, each of which is a defect when it is missing:
 *
 *   DATED. `activeOn()` is evaluated at the ACTIVATION date, like every other
 *   read in §IX.5.1. A delegation raised for a fortnight's leave stops applying
 *   when the fortnight ends, without anybody remembering to delete it.
 *
 *   CHAINED, BOUNDED. A delegates to B who is out of office with C as backup:
 *   the work lands on C. Following one hop and stopping — what the map did —
 *   silently assigns a task to somebody the system already knows is away.
 *
 *   CYCLES REFUSED, NOT RESOLVED. A → B → A has no terminal delegate, and
 *   picking one of the two arbitrarily is how a task ends up with the person the
 *   delegation existed to route around. `chain()` refuses, naming the cycle.
 *
 * Backups are the same structure with a different `kind`, because they differ
 * only in what the trace should call them. Two classes would be two chain
 * walkers, and the second one is the one nobody tests.
 */
final readonly class Delegation
{
    public const DELEGATION = 'delegation';
    public const BACKUP = 'out-of-office backup';

    /** How many hops before the chain is treated as pathological regardless of cycles. */
    public const MAX_HOPS = 10;

    public function __construct(
        public string $from,
        public string $to,
        public ?\DateTimeImmutable $effectiveFrom = null,
        public ?\DateTimeImmutable $effectiveTo = null,
        public string $kind = self::DELEGATION,
        public ?string $reason = null,
    ) {
        if ($from === '' || $to === '') {
            throw new \InvalidArgumentException('A delegation names both parties.');
        }

        if ($from === $to) {
            throw new \InvalidArgumentException(
                "{$from} cannot delegate to themselves. A self-delegation is a cycle of length one, and it "
                . 'reads as a delegation that is in force while doing nothing.'
            );
        }

        if ($effectiveFrom !== null && $effectiveTo !== null && $effectiveTo < $effectiveFrom) {
            throw new \InvalidArgumentException(
                "{$from} → {$to} ends before it begins ("
                . $effectiveTo->format('Y-m-d') . ' < ' . $effectiveFrom->format('Y-m-d')
                . '), so it is never in force — which is not what anybody who typed those dates meant.'
            );
        }
    }

    /** Both bounds INCLUSIVE, compared by date: a delegation "until the 14th" covers the 14th. */
    public function activeOn(\DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        if ($this->effectiveFrom !== null && $day < $this->effectiveFrom->format('Y-m-d')) {
            return false;
        }

        return $this->effectiveTo === null || $day <= $this->effectiveTo->format('Y-m-d');
    }

    public function describe(): string
    {
        $window = match (true) {
            $this->effectiveFrom === null && $this->effectiveTo === null => 'open-ended',
            $this->effectiveFrom === null => 'until ' . $this->effectiveTo->format('j M Y'),
            $this->effectiveTo === null => 'from ' . $this->effectiveFrom->format('j M Y'),
            default => $this->effectiveFrom->format('j M') . ' – ' . $this->effectiveTo->format('j M Y'),
        };

        return "{$this->from} → {$this->to} ({$this->kind}, {$window})";
    }

    /**
     * Follow the chain from one user to whoever actually holds the work.
     *
     * @param  list<self>  $all  every delegation and backup known, of any date
     * @return array{to: string, hops: list<self>}|null  null when nothing applies
     *
     * @throws CircularDelegation   when the chain returns to somebody it already visited
     * @throws AmbiguousDelegation  when one user has two delegations in force at once — a tie
     *                              is a blocked decision, never a coin toss (spine S4)
     */
    public static function chain(array $all, string $user, \DateTimeImmutable $asOf): ?array
    {
        $hops = [];
        $seen = [$user => true];
        $current = $user;

        while (true) {
            $applicable = array_values(array_filter(
                $all,
                static fn (self $d): bool => $d->from === $current && $d->activeOn($asOf),
            ));

            if ($applicable === []) {
                return $hops === [] ? null : ['to' => $current, 'hops' => $hops];
            }

            if (count($applicable) > 1) {
                throw new AmbiguousDelegation(
                    "{$current} has " . count($applicable) . ' delegations in force on '
                    . $asOf->format('j M Y') . ' — '
                    . implode('; ', array_map(static fn (self $d): string => $d->describe(), $applicable))
                    . '. Which one holds the approval is not a question the engine may answer by picking one.'
                );
            }

            $next = $applicable[0];

            if (isset($seen[$next->to])) {
                throw new CircularDelegation(
                    'Circular delegation: '
                    . implode(' → ', [...array_keys($seen), $next->to])
                    . '. There is no terminal delegate, and choosing one of them arbitrarily hands the task '
                    . 'to the person the delegation existed to route around.'
                );
            }

            if (count($hops) >= self::MAX_HOPS) {
                throw new CircularDelegation(
                    'Delegation chain from ' . $user . ' exceeds ' . self::MAX_HOPS
                    . ' hops without terminating. Whatever this is, nobody set it up on purpose.'
                );
            }

            $hops[] = $next;
            $seen[$next->to] = true;
            $current = $next->to;
        }
    }

    /**
     * Whether adding this delegation would create a cycle — for the screen that
     * accepts one, so the refusal happens where somebody can still fix it rather
     * than at 6pm when an approval needs to route.
     *
     * @param list<self> $existing
     */
    public static function wouldCycle(array $existing, self $proposed, \DateTimeImmutable $asOf): bool
    {
        try {
            self::chain([...$existing, $proposed], $proposed->from, $asOf);
        } catch (CircularDelegation) {
            // gate:allow-swallow this predicate's whole purpose is to turn that exception into the
            // yes/no a form field needs; the caller that wants the message calls chain() instead
            return true;
        } catch (AmbiguousDelegation) {
            // gate:allow-swallow ambiguity is a different refusal with a different message, and
            // this question is only about cycles — the caller raises that one separately
            return false;
        }

        return false;
    }
}
