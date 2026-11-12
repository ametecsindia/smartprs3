<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Statutory;

/**
 * DOES THIS STATUTE APPLY, AND WHY — Part XVII §XVII.5.
 *
 * The tenant declares applicability ("this establishment is PF-covered from this
 * date"); the pack declares the semantics. R-XVII-005 draws the line there and
 * nowhere else, because SmartPRS 2's PT slab editor lets a non-expert
 * administrator hand-type statutory band tables at 200 sites, where a typo of
 * the form `[[15000]]` silently zeroes a state.
 *
 * Every determination carries its REASON and the evidence behind it. "PT does not
 * apply" and "we have no PT registration recorded for this state" are the same
 * zero on a payslip and completely different facts on an inspection, and the
 * second one is a finding.
 */
final class Applicability
{
    /** @var list<array{head: string, establishment: string, pack_key: string, from: \DateTimeImmutable, to: ?\DateTimeImmutable, applies: bool, reason: string, evidence: string}> */
    private array $determinations = [];

    public function declare(
        string $head,
        string $establishmentCode,
        string $packKey,
        \DateTimeImmutable $from,
        bool $applies,
        string $reason,
        string $evidence,
        ?\DateTimeImmutable $to = null,
    ): void {
        if (trim($reason) === '' || trim($evidence) === '') {
            throw new \InvalidArgumentException(
                "An applicability determination for '{$head}' with no reason or no evidence is an opinion. "
                . 'On an inspection the question is never "is it on?" but "on what basis?".'
            );
        }

        $this->determinations[] = [
            'head' => $head,
            'establishment' => $establishmentCode,
            'pack_key' => $packKey,
            'from' => $from,
            'to' => $to,
            'applies' => $applies,
            'reason' => $reason,
            'evidence' => $evidence,
        ];
    }

    /**
     * The determination in force on a date.
     *
     * An UNDECLARED statute is NOT "does not apply". It is undetermined, and it
     * says so — the difference between a decision somebody made and a question
     * nobody asked. §XVII.5.5's coverage gate is built on exactly this
     * distinction, and it is the reason a multi-state employer can be told which
     * of its establishments has no determination at all rather than being shown a
     * clean screen.
     */
    public function resolve(string $head, string $establishmentCode, \DateTimeImmutable $asOf): Binding
    {
        $best = null;

        foreach ($this->determinations as $d) {
            if ($d['head'] !== $head || $d['establishment'] !== $establishmentCode) {
                continue;
            }

            if ($d['from'] > $asOf || ($d['to'] !== null && $d['to'] < $asOf)) {
                continue;
            }

            if ($best === null || $d['from'] > $best['from']) {
                $best = $d;
            }
        }

        if ($best === null) {
            return new Binding($head, $establishmentCode, '', false, 'NOT DETERMINED', '', true);
        }

        return new Binding(
            $best['head'],
            $best['establishment'],
            $best['pack_key'],
            $best['applies'],
            $best['reason'],
            $best['evidence'],
            false,
        );
    }

    /**
     * Which establishments have no determination for a head — the coverage gate.
     *
     * @param list<string> $establishmentCodes
     * @return list<string>
     */
    public function undetermined(string $head, array $establishmentCodes, \DateTimeImmutable $asOf): array
    {
        return array_values(array_filter(
            $establishmentCodes,
            fn (string $code): bool => $this->resolve($head, $code, $asOf)->undetermined,
        ));
    }
}
