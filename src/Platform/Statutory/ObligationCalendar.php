<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * MATERIALISED, NEVER AUTHORED — Part XVII §XVII.11.2, R-XVII-126.
 *
 * The calendar is generated from pack `obligation` objects crossed with the
 * tenant's bindings and applicability. "Nothing is typed by a customer, and
 * nothing is compiled into the application." Both halves of that matter: a
 * customer-typed due date is a due date that is wrong in one of two hundred
 * installs and nobody knows which, and a compiled-in one is a due date that needs
 * a release when a state moves a deadline.
 *
 * An establishment where the statute does NOT apply generates no obligations, and
 * an establishment where nobody has DECIDED generates none either — but the two
 * are different facts, and `undeterminedFor()` names the second so a clean
 * calendar cannot be mistaken for a complete one. A compliance calendar with
 * nothing on it is either a compliant company or a company nobody has assessed.
 */
final class ObligationCalendar
{
    public function __construct(
        private readonly PackStore $store,
        private readonly Applicability $applicability,
    ) {
    }

    /**
     * @param list<array{code: string, head: string, registration: string}> $establishments
     * @param list<string> $periodCodes
     * @param array<string, string> $owners obligation code => the user the role resolved to
     * @return array{obligations: list<Obligation>, problems: list<string>}
     */
    public function materialise(
        array $establishments,
        array $periodCodes,
        \DateTimeImmutable $asOf,
        array $owners,
    ): array {
        $obligations = [];
        $problems = [];

        foreach ($establishments as $establishment) {
            $binding = $this->applicability->resolve($establishment['head'], $establishment['code'], $asOf);

            if ($binding->undetermined) {
                $problems[] = "{$establishment['code']}: {$establishment['head']} is NOT DETERMINED, so no "
                    . 'obligations were generated and none should be read as "nothing is due" — an empty '
                    . 'calendar is either a compliant company or one nobody has assessed';

                continue;
            }

            if (!$binding->applies) {
                continue;
            }

            $pack = $this->store->resolve($binding->packKey, $asOf);

            if ($pack === null) {
                $problems[] = "{$establishment['code']}: {$establishment['head']} applies and no pack is in "
                    . 'force, so its filing calendar cannot be generated at all';

                continue;
            }

            foreach ((array) ($pack->content['obligations'] ?? []) as $declared) {
                foreach ($periodCodes as $periodCode) {
                    $code = (string) $declared['code'];
                    $owner = $owners[$code] ?? '';

                    if ($owner === '') {
                        // R-XVII-128: an unassigned obligation is an exception on
                        // the dashboard, not a row that quietly sits there.
                        $problems[] = "{$code} for {$periodCode} resolved to NO OWNER. A reminder ladder "
                            . 'that resolves to nobody sends to nobody and says nothing about it';

                        continue;
                    }

                    $obligations[] = new Obligation(
                        code: $code,
                        head: $establishment['head'],
                        registration: $establishment['registration'],
                        periodCode: $periodCode,
                        ownerUserId: $owner,
                        dueOn: self::dueDate($periodCode, (int) $declared['due_day']),
                        dueRule: (string) $declared['due_rule'],
                        packVersion: $pack->manifest->version(),
                    );
                }
            }
        }

        return ['obligations' => $obligations, 'problems' => $problems];
    }

    /**
     * The period's due date from the pack's declared day.
     *
     * ponytail: `due_day` days after the period end covers every monthly filing
     * shape the packs need. Upgrade path if a statute ever needs a working-day
     * or nth-weekday rule: the pack already carries `due_rule` as text, so it
     * becomes an SPX expression evaluated here, in this one method.
     */
    private static function dueDate(string $periodCode, int $dueDay): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($periodCode . '-01'))
            ->modify('last day of this month')
            ->modify("+{$dueDay} days");
    }
}
