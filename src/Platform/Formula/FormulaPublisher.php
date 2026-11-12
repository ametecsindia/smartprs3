<?php

declare(strict_types=1);

namespace SmartPRS\Platform\Formula;

use SmartPRS\Platform\Config\Publisher;
use SmartPRS\Platform\Persistence\TenantScopedQuery;
use SmartPRS\Platform\Tenancy\TenantContext;

/**
 * PUBLISHING A FORMULA, WITH THE PAYLOAD DERIVED FROM THE FORMULA.
 *
 * Config\Publisher takes a payload array and a report, because it publishes any
 * artefact type. That leaves one seam a caller could get wrong: hand-mapping a
 * Formula's fields onto `formula_payloads` columns. Two records of the same
 * facts, kept in step by hand, is what R-VIII-012 refuses for dependency sets
 * and it is no better here — a rounding mode that says half_up on the object and
 * half_down in the row is a payslip nobody can explain.
 *
 * So this is the only way a formula is published: the report comes from
 * PublishGates over the same object, and every column comes from the object.
 * There is no argument through which a caller can disagree with the formula
 * being published.
 */
final class FormulaPublisher
{
    public function __construct(
        private readonly Publisher $publisher,
        private readonly ?\PDO $pdo = null,
        private readonly ?TenantContext $tenant = null,
    ) {
    }

    /**
     * @param array<string, string> $audit created_by, approved_by, reason, change_note
     * @param list<SampleCase>      $cases the runnable cases behind the declared names.
     *        `formula_sample_cases` was written by NOTHING until FC-10 needed a
     *        published formula read back: the names lived on the object, the
     *        table stayed empty, and a resolver rehydrating a published formula
     *        found zero cases and was refused by the very guard (R-VIII-047)
     *        that publication had satisfied. A table only one side of a
     *        round trip knows about is not persistence.
     * @return int the new config_versions id
     *
     * @throws \SmartPRS\Platform\Config\PublicationRefused when a gate blocks
     */
    public function publish(
        Formula $formula,
        \DateTimeImmutable $now,
        ?GateEnvironment $environment = null,
        array $audit = [],
        array $cases = [],
    ): int {
        return $this->publisher->publish(
            'formula',
            $formula->code,
            'formula_payloads',
            self::payload($formula, $now),
            $formula->scope,
            $formula->range,
            PublishGates::run($formula, $environment),
            $now,
            $formula->priority,
            $formula->checksum(),
            $audit,
            fn (int $payloadId): null => $this->writeCases($formula, $cases, $payloadId, $now),
        );
    }

    /**
     * The declared case names, and the expectation behind each.
     *
     * A name with no runnable case is stored with its expected value taken from
     * the case supplied for it; a name with no case at all is stored as a name
     * and nothing else, which is exactly what G10 already reports as NOT
     * CHECKED. What is NOT done is inventing an expected value — a row that
     * asserts whatever the formula happened to produce is a regression case that
     * can never fail.
     *
     * @param list<SampleCase> $cases
     */
    private function writeCases(Formula $formula, array $cases, int $payloadId, \DateTimeImmutable $now): null
    {
        if ($this->pdo === null || $this->tenant === null) {
            return null;
        }

        $byName = [];

        foreach ($cases as $case) {
            $byName[$case->name] = $case;
        }

        $timestamp = $now->format('Y-m-d H:i:s');

        foreach ($formula->sampleTests as $name) {
            $case = $byName[$name] ?? null;

            TenantScopedQuery::on($this->pdo, $this->tenant, 'formula_sample_cases')->insert([
                'formula_payload_id' => $payloadId,
                'case_name' => $name,
                // $case is genuinely nullable here — see the docblock above, a name
                // with no runnable case is exactly the NOT_CHECKED path below — so
                // the null branch below is spelled out rather than using `?->` (PHPStan
                // flags `$x?->y ?? default` as unnecessary purely on the shape of the
                // expression, not because $case can't be null).
                'case_class' => $case === null ? 'nominal' : $case->class,
                'sample_employee_code' => $case === null ? 'SAMPLE-1' : $case->sampleEmployeeCode,
                'period_code' => 'CURRENT',
                'as_of' => $now->format('Y-m-d'),
                'context_overrides_json' => null,
                // NOT_CHECKED as a sentinel rather than a plausible number: a
                // row carrying an invented expectation is a case that can never
                // fail, which is worse than a row that says it was never run.
                'expected_value_minor' => $case === null ? PHP_INT_MIN : $case->expectedMinor,
                'expected_rounding_step' => $case === null ? 'NOT CHECKED' : null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        return null;
    }

    /**
     * The payload row, entirely from the formula.
     *
     * Deliberately exhaustive rather than clever: every column of
     * `formula_payloads` that mirrors a field appears here, so a new column with
     * no source is a visible omission rather than a silently-defaulted one.
     *
     * @return array<string, mixed>
     */
    public static function payload(Formula $formula, \DateTimeImmutable $now): array
    {
        $timestamp = $now->format('Y-m-d H:i:s');

        return [
            'calculation_point' => $formula->calculationPoint,
            'module' => $formula->module,
            'business_description' => $formula->businessDescription,
            'expression_src' => $formula->expression->source,
            // The EVALUATED artefact. A published formula runs its stored
            // canonical AST, so a parser upgrade cannot change what it does
            // (R-VII-009); the source beside it is for reading and diffing.
            'expression_ast' => $formula->expression->toJson(),
            'result_type' => (string) $formula->resultType,
            'unit' => $formula->unit,
            'rounding_mode' => $formula->rounding->declaredMode,
            'rounding_unit' => $formula->rounding->declaredUnit,
            'rounding_point' => $formula->rounding->point,
            'reference_value' => $formula->referenceValue,
            'formula_kind' => $formula->kind,
            'sequence_no' => $formula->sequenceNo,
            'declared_slices_json' => self::json($formula->declaredSlices),
            // R-VIII-012: derived by the compiler from the AST, never authored.
            'depends_on_json' => self::json(array_map(
                static fn (Dependency $d): array => ['kind' => $d->kind, 'code' => $d->code],
                $formula->dependsOn,
            )),
            'severity' => $formula->severity,
            'requires_approval' => $formula->requiresApproval ? 1 : 0,
            'source_ref' => $formula->sourceRef,
            'checksum' => $formula->checksum(),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
    }

    /** @param array<mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
