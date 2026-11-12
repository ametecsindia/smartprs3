<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Config\ConfigVersionRepository;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;

/**
 * THE READ SIDE OF `formula_payloads` — Part VIII §4.1, spine S4.
 *
 * FormulaPublisher has written every column of that table from the Formula
 * object since the payload migration, and NOTHING READ ONE BACK. Calculator
 * takes `array<string, Formula>` and every caller in the codebase — the console,
 * the tests, the conformance corpus — hands it formulas built in PHP. So
 * "formulas are effective-dated and resolve through resolve(type, key, scope,
 * as_of)" was a property of the write path only, and FC-10 ("a version published
 * in October effective 1-Oct is not used for September's payroll") could not be
 * stated against anything.
 *
 * This is that read. Two things it does deliberately:
 *
 *   THE STORED AST IS WHAT RUNS (R-VII-009). `expression_ast` is rehydrated
 *   through Compiled::fromJson, not re-parsed from `expression_src`. A parser
 *   upgrade must not silently change what a published formula computes, and
 *   re-parsing the source is exactly how it would.
 *
 *   DEPENDENCIES RESOLVE AT THE SAME DATE. `graph()` follows each formula's
 *   derived dependency set and resolves every dependency AS AT THE SAME DATE.
 *   Resolving the root at September and its dependencies at today's date would
 *   compute September's basic against October's HRA percentage, which is a
 *   payslip that reconciles against nothing.
 *
 * A dependency that does not resolve is an error, not a gap: spine S4's
 * UnresolvedConfiguration propagates out rather than leaving a hole in a graph
 * the Calculator would then evaluate around.
 */
final class FormulaResolver
{
    /** A formula depending on more than this is a graph nobody can read anyway. */
    private const MAX_FORMULAS = 500;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly \SmartPRS\Platform\Tenancy\TenantContext $tenant,
        private readonly ConfigVersionRepository $versions,
        private readonly SymbolTable $symbols,
    ) {
    }

    /**
     * The formula governing one code at a date, hydrated from its payload.
     *
     * @param array<string, string|int|null> $subjectDimensions
     *
     * @throws \SmartPRS\Platform\Support\UnresolvedConfiguration when nothing governs it
     * @throws \SmartPRS\Platform\Support\AmbiguousConfiguration  when two tie
     */
    public function resolve(string $code, array $subjectDimensions, \DateTimeImmutable $asOf): Formula
    {
        $candidate = $this->versions->resolve('formula', $code, $subjectDimensions, $asOf);
        $payload = $this->versions->payload($candidate);

        if ($payload === null) {
            throw new \RuntimeException(
                "Formula {$code} resolved to version {$candidate->versionId}, whose payload row is missing. "
                . 'A version pointing at nothing is not a formula that evaluates to zero.'
            );
        }

        return new Formula(
            code: $code,
            calculationPoint: (string) $payload['calculation_point'],
            module: (string) $payload['module'],
            name: $code,
            businessDescription: (string) $payload['business_description'],
            // The stored AST, not the stored source. See the class docblock.
            expression: Compiled::fromJson((string) $payload['expression_ast'], $this->symbols),
            resultType: Type::parse((string) $payload['result_type']),
            rounding: $payload['rounding_mode'] === null || (string) $payload['rounding_mode'] === 'none'
                ? Rounding::none()
                : Rounding::of(
                    (string) $payload['rounding_mode'],
                    (string) $payload['rounding_unit'],
                    (string) $payload['rounding_point'],
                ),
            scope: $candidate->scope,
            range: $candidate->range,
            versionNo: $candidate->versionNo,
            versionId: $candidate->versionId,
            status: 'published',
            kind: (string) $payload['formula_kind'],
            referenceValue: (string) $payload['reference_value'],
            unit: $payload['unit'] === null ? null : (string) $payload['unit'],
            priority: (int) ($payload['priority'] ?? 0),
            sequenceNo: (int) $payload['sequence_no'],
            declaredSlices: self::decode($payload['declared_slices_json']),
            sampleTests: $this->sampleCaseNames((int) $payload['id']),
            severity: (string) $payload['severity'],
            sourceRef: $payload['source_ref'] === null ? null : (string) $payload['source_ref'],
        );
    }

    /**
     * One formula and everything it depends on, all resolved at the same date.
     *
     * @param  list<string>                    $codes
     * @param  array<string, string|int|null>  $subjectDimensions
     * @return array<string, Formula>          keyed by code, ready for Calculator
     */
    public function graph(array $codes, array $subjectDimensions, \DateTimeImmutable $asOf): array
    {
        $graph = [];
        $queue = $codes;

        while ($queue !== []) {
            $code = array_shift($queue);

            if (isset($graph[$code])) {
                continue;
            }

            if (count($graph) >= self::MAX_FORMULAS) {
                throw new \RuntimeException(
                    'Resolving ' . implode(', ', $codes) . ' reached ' . self::MAX_FORMULAS
                    . ' formulas. A graph that large is a configuration mistake, and loading it would be '
                    . 'the first symptom nobody could diagnose.'
                );
            }

            $formula = $this->resolve($code, $subjectDimensions, $asOf);
            $graph[$code] = $formula;

            foreach ($formula->dependsOn as $dependency) {
                if ($dependency->kind === 'formula' && !isset($graph[$dependency->code])) {
                    $queue[] = $dependency->code;
                }
            }
        }

        return $graph;
    }

    /** @return list<string> */
    private function sampleCaseNames(int $payloadId): array
    {
        $rows = TenantScopedQuery::on($this->pdo, $this->tenant, 'formula_sample_cases')
            ->where('formula_payload_id', '=', $payloadId)
            ->orderBy('case_name')
            ->select(['case_name']);

        return array_map(static fn (array $row): string => (string) $row['case_name'], $rows);
    }

    /** @return list<string> */
    private static function decode(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : [];
    }
}
