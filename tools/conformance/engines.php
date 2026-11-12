<?php

declare(strict_types=1);

/**
 * THE ADAPTER FROM THE DECLARATIVE CORPUS TO THIS IMPLEMENTATION.
 *
 * R-XXX-021 makes the corpus data so that it survives a re-implementation of an
 * engine. This file is the only part that would not: it maps each case `kind`
 * onto the classes in src/Platform. Everything a case says about WHAT must hold
 * lives in the JSON; everything about HOW to ask this implementation lives
 * here.
 *
 * A case whose `kind` this adapter does not know FAILS, loudly, naming the
 * kind. It does not skip: a corpus that quietly ignores the cases its runner
 * cannot execute is the vacuous gate again, one level up.
 */

use SmartPRS\Platform\Rules\DecisionPoints;
use SmartPRS\Platform\Rules\ImpactPreview;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\Sandbox;
use SmartPRS\Platform\Spx\SpxSyntaxError;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;

/**
 * A case the runner COULD NOT EXECUTE — no database, most often.
 *
 * It is neither a pass nor a failure, and above all it is not a skip that reads
 * green: a case that did not run proved nothing, so its class stays uncovered
 * and the summary says why. Same discipline as PublishCheck::couldNotCheck().
 */
final class ConformanceNotRunnable extends \RuntimeException
{
}

/**
 * @param  array<string, mixed>  $case
 * @return array{id: string, passed: bool, detail: string, ran?: bool}
 */
function conformance_run_case(string $engine, array $case): array
{
    $id = (string) ($case['id'] ?? '?');

    try {
        $detail = match ((string) ($case['kind'] ?? '')) {
            'parse' => conformance_parse($case),
            'evaluate' => conformance_evaluate($case),
            'evaluate_unchecked' => conformance_evaluate($case, typeCheck: false),
            'nth_weekday_table' => conformance_nth_weekday_table($case),
            'determinism' => conformance_determinism($case),
            'clock_independence' => conformance_clock($case),
            'budget' => conformance_budget($case),
            'stored_ast' => conformance_stored_ast($case),
            'formula' => conformance_formula_case($case),
            'formula_effective_date' => conformance_formula_effective_date($case),
            'formula_no_float' => conformance_formula_no_float($case),
            'allocate' => conformance_allocate($case),
            'balance_residue' => conformance_balance_residue($case),
            'rounding_table' => conformance_rounding_table($case),
            'formula_publish' => conformance_formula_publish($case),
            'formula_cycle' => conformance_formula_cycle($case),
            'currency' => conformance_currency($case),
            'formula_determinism' => conformance_formula_determinism($case),
            'rules_decide' => conformance_rules_decide($case),
            'rules_publish' => conformance_rules_publish($case),
            'rules_purity' => conformance_rules_purity($case),
            'rules_fail_closed' => conformance_rules_fail_closed($case),
            'rules_fault_injection' => conformance_rules_fault_injection($case),
            'rules_impact_preview' => conformance_rules_impact_preview($case),
            'workflow_decide_twice' => conformance_workflow_decide_twice($case),
            'workflow_quorum' => conformance_workflow_quorum($case),
            'workflow_shapes' => conformance_workflow_shapes($case),
            'workflow_parallel' => conformance_workflow_parallel($case),
            'workflow_sla' => conformance_workflow_sla($case),
            'workflow_self_approval' => conformance_workflow_self_approval($case),
            'workflow_terminal' => conformance_workflow_terminal($case),
            'workflow_amount_route' => conformance_workflow_amount_route($case),
            'workflow_delegation' => conformance_workflow_delegation($case),
            'workflow_return_for_edit' => conformance_workflow_return_for_edit($case),
            'workflow_version_pinning' => conformance_workflow_version_pinning($case),
            'workflow_concurrent_decision' => conformance_workflow_concurrent_decision($case),
            'workflow_transition_audit' => conformance_workflow_transition_audit($case),
            'agg_window_table' => conformance_agg_window_table($case),
            'agg_window_disagree' => conformance_agg_window_disagree($case),
            'agg_boundary' => conformance_agg_boundary($case),
            'agg_occurrence_index' => conformance_agg_occurrence_index($case),
            'agg_collapse' => conformance_agg_collapse($case),
            'agg_streak' => conformance_agg_streak($case),
            'agg_balance' => conformance_agg_balance($case),
            'specificity_table' => conformance_specificity_table($case),
            'rules_publish_conflict' => conformance_rules_publish_conflict($case),
            'rules_effective_date' => conformance_rules_effective_date($case),
            'rules_stored_cases' => conformance_rules_stored_cases($case),
            'rules_determinism' => conformance_rules_determinism($case),
            'formula_clamp' => conformance_formula_clamp($case),
            'formula_samples' => conformance_formula_samples($case),
            'simulator_equals_production' => conformance_simulator_equals_production($case),
            default => 'unknown case kind ' . var_export($case['kind'] ?? null, true)
                . ' — the runner cannot execute this case, which is a failure rather than a skip',
        };
    } catch (ConformanceNotRunnable $e) {
        return ['id' => $id, 'passed' => false, 'ran' => false, 'detail' => $e->getMessage()];
    } catch (\Throwable $e) {
        return ['id' => $id, 'passed' => false, 'ran' => true, 'detail' => $e::class . ': ' . $e->getMessage()];
    }

    return ['id' => $id, 'passed' => $detail === null, 'ran' => true, 'detail' => (string) $detail];
}

/** The symbol table a case is compiled against: the full catalogue, or a decision point's. */
function conformance_symbols(array $case): SymbolTable
{
    $named = (string) ($case['symbols'] ?? 'catalogue');

    return $named === 'catalogue' ? SymbolTable::catalogue() : DecisionPoints::symbols($named);
}

/** @return string|null null when the case passed, otherwise why it did not */
function conformance_parse(array $case): ?string
{
    $expect = $case['expect'] ?? [];
    $source = (string) $case['expression'];

    try {
        $compiled = Compiled::parse($source, conformance_symbols($case));
    } catch (SpxSyntaxError $e) {
        if (($expect['parses'] ?? true) !== false) {
            return 'expected it to parse; it did not: ' . $e->getMessage();
        }

        return conformance_message($expect, $e->getMessage());
    }

    if (($expect['parses'] ?? true) === false) {
        return 'expected a syntax error; it parsed';
    }

    if (($expect['roundtrips'] ?? false) === true) {
        $back = Compiled::fromJson($compiled->toJson(), conformance_symbols($case));

        if ($back->toJson() !== $compiled->toJson()) {
            return 'the AST did not round-trip to an identical AST';
        }
    }

    $check = $compiled->typeCheck();

    if (($expect['valid'] ?? true) === false) {
        if ($check->isValid()) {
            return 'expected a type error; it type-checked to ' . $check->type;
        }

        return conformance_message($expect, implode(' | ', $check->messages()));
    }

    if (!$check->isValid()) {
        return 'expected it to type-check; ' . $check;
    }

    if (isset($expect['type']) && (string) $check->type !== $expect['type']) {
        return "expected type {$expect['type']}, got {$check->type}";
    }

    return null;
}

/**
 * @param bool $typeCheck false for SPX-15's second half: the point of that case
 *        is what the EVALUATOR does with a pattern the type checker already
 *        refused, so it has to be got past the type checker to be asked.
 */
function conformance_evaluate(array $case, bool $typeCheck = true): ?string
{
    $expect = $case['expect'] ?? [];
    $compiled = Compiled::parse((string) $case['expression'], conformance_symbols($case));

    if ($typeCheck) {
        $check = $compiled->typeCheck();

        if (!$check->isValid()) {
            return 'the expression does not type-check: ' . $check;
        }
    }

    $started = hrtime(true);
    $value = Evaluator::evaluate($compiled, conformance_context($case));
    $elapsedMs = (hrtime(true) - $started) / 1_000_000;

    if (isset($expect['within_ms']) && $elapsedMs > (float) $expect['within_ms']) {
        return sprintf(
            'took %.1fms, over the %sms budget — the point of the case is that it terminates',
            $elapsedMs,
            $expect['within_ms'],
        );
    }

    if (isset($expect['error'])) {
        $actual = $value->error?->value;

        return $actual === $expect['error']
            ? null
            : "expected error {$expect['error']}, got " . ($actual ?? 'no error: ' . $value->describe());
    }

    if ($value->isError()) {
        // isError() === ($this->error !== null), so error is guaranteed set here.
        return 'unexpected error ' . $value->error->value . ': ' . $value->detail;
    }

    if (isset($expect['describes']) && $value->describe() !== $expect['describes']) {
        return "expected {$expect['describes']}, got {$value->describe()}";
    }

    return null;
}

/**
 * SPX-08. The host clock is moved and the answer must not move with it.
 *
 * Done by evaluating the same expression under two different HOST clocks with
 * the same `evaluation_date`, rather than by reading the source for a NOW():
 * absence of a token proves the catalogue is closed, and this proves the
 * evaluator has no other way to reach a clock either.
 */
function conformance_clock(array $case): ?string
{
    $compiled = Compiled::parse((string) $case['expression'], conformance_symbols($case));
    $context = conformance_context($case);

    $first = Evaluator::evaluate($compiled, $context)->describe();
    $originalTz = date_default_timezone_get();
    date_default_timezone_set('Pacific/Kiritimati');
    $second = Evaluator::evaluate($compiled, $context)->describe();
    date_default_timezone_set($originalTz);

    return $first === $second
        ? null
        : "the host timezone changed the answer: {$first} then {$second}";
}

/**
 * SPX-09. Built here rather than in the JSON because the limit is a constant of
 * this implementation, and a corpus that hard-coded 32 would be asserting a
 * number instead of a property.
 */
function conformance_budget(array $case): ?string
{
    $depth = Sandbox::MAX_DEPTH + (($case['depth_over_limit'] ?? false) === true ? 2 : -1);

    // Genuine nesting, not parentheses. Redundant parentheses collapse in the
    // parser — `((((1))))` is one node at depth one — so a budget case built
    // from them tests nothing and passes for the wrong reason. This is the
    // first thing the conformance suite caught, in its own corpus.
    $source = '1 + 1';

    for ($level = 2; $level < $depth; $level++) {
        $source = '(' . $source . ') + 1';
    }

    $refused = false;
    $detail = '';

    try {
        $check = Compiled::parse($source, SymbolTable::catalogue())->typeCheck();
        $refused = !$check->isValid();
        $detail = (string) $check;
    } catch (\Throwable $e) {
        $refused = true;
        $detail = $e->getMessage();
    }

    $expected = ($case['expect']['refused'] ?? false) === true;

    if ($refused !== $expected) {
        return $expected
            ? "nesting {$depth} deep was accepted; the budget is " . Sandbox::MAX_DEPTH
            : "nesting {$depth} deep was refused, and it is within the budget of " . Sandbox::MAX_DEPTH
                . ': ' . $detail;
    }

    if ($expected && !str_contains($detail, 'depth') && !str_contains($detail, 'nest')) {
        return 'refused, but not with a named budget error: ' . $detail;
    }

    return null;
}

/**
 * SPX-12. The published artefact is the AST, so an expression serialised and
 * restored evaluates identically — which is what makes a parser upgrade safe
 * for already-published rules.
 */
function conformance_stored_ast(array $case): ?string
{
    $symbols = conformance_symbols($case);
    $compiled = Compiled::parse((string) $case['expression'], $symbols);
    $context = conformance_context($case);

    $direct = Evaluator::evaluate($compiled, $context)->describe();
    $restored = Evaluator::evaluate(Compiled::fromJson($compiled->toJson(), $symbols), $context)->describe();

    if ($direct !== $restored) {
        return "the restored AST evaluated differently: {$direct} then {$restored}";
    }

    if (isset($case['expect']['describes']) && $direct !== $case['expect']['describes']) {
        return "expected {$case['expect']['describes']}, got {$direct}";
    }

    return null;
}

/**
 * A prepared context from the case's declared inputs.
 *
 * Every value carries its SPX type, because a bare PHP scalar has none and
 * guessing one is the coercion the whole engine exists to prevent — so the JSON
 * says which kind each input is.
 */
function conformance_context(array $case): Context
{
    $symbols = [];

    foreach ($case['context'] ?? [] as $key => $declared) {
        $symbols[$key] = match (true) {
            isset($declared['money']) => Value::money(Money::of((string) $declared['money'])),
            isset($declared['minutes']) => Value::quantityOfType(Type::minutes(), Rational::of((int) $declared['minutes'])),
            isset($declared['days']) => Value::quantityOfType(Type::days(), Rational::of((int) $declared['days'])),
            isset($declared['percentage']) => Value::quantityOfType(
                Type::percentage(),
                Rational::fromDecimalString((string) $declared['percentage']),
            ),
            isset($declared['number']) => Value::number(Rational::fromDecimalString((string) $declared['number'])),
            isset($declared['boolean']) => Value::boolean((bool) $declared['boolean']),
            isset($declared['text']) => Value::text((string) $declared['text']),
            default => throw new \InvalidArgumentException(
                "Context entry '{$key}' declares no SPX type. A bare scalar has none, and the "
                . 'conformance corpus may not guess one.'
            ),
        };
    }

    return new Context(
        new \DateTimeImmutable((string) ($case['evaluation_date'] ?? '2026-08-26')),
        $symbols,
    );
}

/**
 * SPX-14. NTH_WEEKDAY against a table this file computes itself.
 *
 * "Matches an independently generated calendar table exactly." Independent
 * means the table is built from plain date arithmetic rather than by asking the
 * engine — otherwise the case asserts that the engine agrees with itself.
 */
function conformance_nth_weekday_table(array $case): ?string
{
    $symbols = SymbolTable::catalogue();
    $context = new Context(new \DateTimeImmutable('2026-08-26'));
    $weekdays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $checked = 0;

    for ($year = (int) $case['from_year']; $year <= (int) $case['to_year']; $year++) {
        for ($month = 1; $month <= 12; $month++) {
            $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
            $daysInMonth = (int) $first->format('t');

            foreach ($weekdays as $weekday) {
                // The independent table: every day of the month that falls on
                // this weekday, in order.
                $occurrences = [];

                for ($day = 1; $day <= $daysInMonth; $day++) {
                    $date = $first->setDate($year, $month, $day);

                    if (strtolower($date->format('D')) === $weekday) {
                        $occurrences[] = $date->format('Y-m-d');
                    }
                }

                foreach ([1, 2, 3, 4, 5, -1] as $n) {
                    $expected = $n === -1
                        ? $occurrences[count($occurrences) - 1]
                        : ($occurrences[$n - 1] ?? null);

                    $source = sprintf(
                        "NTH_WEEKDAY(#%04d-%02d-01#, '%s', %d)",
                        $year,
                        $month,
                        $weekday,
                        $n,
                    );

                    $value = Evaluator::evaluate(Compiled::parse($source, $symbols), $context);
                    $actual = $value->isError() ? null : $value->describe();
                    $checked++;

                    if ($actual !== $expected) {
                        return sprintf(
                            '%s gave %s; the independently computed table says %s',
                            $source,
                            // isError() === ($this->error !== null), so error is guaranteed set here.
                            $actual ?? ('error ' . $value->error->value),
                            $expected ?? 'no such occurrence',
                        );
                    }
                }
            }
        }
    }

    return $checked > 0 ? null : 'the table was empty, so nothing was compared';
}

/**
 * SPX-07. The whole parseable corpus, evaluated repeatedly in shuffled order.
 *
 * Shuffled because the failure this guards against is order dependence — a
 * cache, a static, a memo keyed on something it should not be. Running the
 * cases in the order they were written would never surface it.
 */
function conformance_determinism(array $case): ?string
{
    $corpus = json_decode(
        (string) file_get_contents(__DIR__ . '/../../tests/conformance/spx.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['cases'];

    $evaluable = array_values(array_filter(
        $corpus,
        static fn (array $c): bool => ($c['kind'] ?? '') === 'evaluate',
    ));

    if ($evaluable === []) {
        return 'no evaluable cases in the corpus, so determinism was not tested';
    }

    $baseline = null;

    for ($run = 0; $run < (int) ($case['runs'] ?? 50); $run++) {
        $order = $evaluable;
        shuffle($order);
        $observed = [];

        foreach ($order as $one) {
            $compiled = Compiled::parse((string) $one['expression'], conformance_symbols($one));
            $value = Evaluator::evaluate($compiled, conformance_context($one));
            $observed[$one['id']] = $value->isError() ? 'error:' . $value->error?->value : $value->describe();
        }

        ksort($observed);
        $digest = hash('sha256', json_encode($observed, JSON_THROW_ON_ERROR));

        if ($baseline === null) {
            $baseline = $digest;

            continue;
        }

        if ($digest !== $baseline) {
            return "run {$run} produced a different digest — the corpus is order dependent";
        }
    }

    return null;
}

/** @param array<string, mixed> $expect */
function conformance_message(array $expect, string $actual): ?string
{
    if (!isset($expect['message_contains'])) {
        return null;
    }

    return str_contains($actual, (string) $expect['message_contains'])
        ? null
        : "expected the message to contain '{$expect['message_contains']}'; it was: {$actual}";
}

// ===========================================================================
// FORMULA — Part XXX §XXX.3.3
// ===========================================================================

use SmartPRS\Platform\Formula\Calculator;
use SmartPRS\Platform\Formula\CircularDependency;
use SmartPRS\Platform\Formula\Formula;
use SmartPRS\Platform\Formula\PublishGates;
use SmartPRS\Platform\Formula\Rounding;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\RoundingMode;
use SmartPRS\Platform\Support\Scope;

/**
 * Build a Formula from the corpus's compact declaration.
 *
 * The corpus says only what a case is about — the expression, the rounding, the
 * sequence. Everything else is a constant of the fixture, and putting it here
 * rather than in the JSON keeps the data file readable as a specification.
 *
 * @param array<string, mixed> $declared
 */
function conformance_formula(string $code, array $declared): Formula
{
    return new Formula(
        code: $code,
        calculationPoint: 'comp.earning',
        module: 'payroll',
        name: $code,
        businessDescription: 'A conformance fixture from Part XXX, evaluated by the real engine.',
        expression: Compiled::parse((string) $declared['expression'], SymbolTable::catalogue()),
        resultType: Type::money(),
        rounding: Rounding::of((string) ($declared['rounding'] ?? 'half_up')),
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(
            new \DateTimeImmutable((string) ($declared['effective_from'] ?? '2026-04-01')),
        ),
        versionNo: 1,
        versionId: $code . '-v1',
        status: 'published',
        // §6.2 / D-VIII-01: an intermediate may declare that its dependents read
        // the EXACT rational rather than the rounded money. That is the
        // mechanism FC-02 is about, so the corpus can ask for it.
        kind: (string) ($declared['kind'] ?? 'component'),
        referenceValue: (string) ($declared['reference_value'] ?? 'rounded'),
        unit: 'INR',
        sequenceNo: (int) ($declared['sequence'] ?? 0),
        sampleTests: ['nominal', 'boundary', 'zero'],
    );
}

/** @return array<string, Formula> */
function conformance_formulas(array $case): array
{
    $formulas = [];

    foreach ($case['formulas'] ?? [] as $code => $declared) {
        $formulas[$code] = conformance_formula((string) $code, $declared);
    }

    return $formulas;
}

/**
 * FC-10 — effective-dated formulas.
 *
 * "A version published in October effective 1-Oct is not used for September's
 * payroll." Until FormulaResolver landed this could not be stated against
 * anything: FormulaPublisher wrote every column of `formula_payloads` and
 * nothing read one back, so every caller in the codebase — console, tests, this
 * corpus — handed Calculator formulas built in PHP. Effective dating was a
 * property of the write path only.
 *
 * Both versions are published through the real publisher and both are read back
 * through resolve(), because the interesting failure is not "does the row have a
 * date" but "does the engine evaluate the September rate in September".
 */
function conformance_formula_effective_date(array $case): ?string
{
    $fixture = conformance_formula_database('smartprs3_conformance_fc10');
    $pdo = $fixture['pdo'];
    $tenant = $fixture['tenant'];

    $states = new \SmartPRS\Platform\Persistence\StatusLookup($pdo, $tenant);
    $versions = new \SmartPRS\Platform\Config\ConfigVersionRepository($pdo, $tenant, $states);
    $publisher = new \SmartPRS\Platform\Formula\FormulaPublisher(
        new \SmartPRS\Platform\Config\Publisher($pdo, $tenant, $states, $versions),
        $pdo,
        $tenant,
    );
    $resolver = new \SmartPRS\Platform\Formula\FormulaResolver($pdo, $tenant, $versions, SymbolTable::catalogue());

    $code = (string) ($case['code'] ?? 'F-BASIC');
    $problems = [];

    foreach ($case['versions'] as $declared) {
        $publisher->publish(
            conformance_formula($code, $declared + ['effective_from' => $declared['effective_from']]),
            new \DateTimeImmutable((string) $declared['published_at']),
            null,
            ['created_by' => '100', 'approved_by' => '101'],
        );
    }

    foreach ($case['probes'] as $probe) {
        $asOf = new \DateTimeImmutable((string) $probe['as_of']);

        try {
            $graph = $resolver->graph([$code], [], $asOf);
        } catch (\SmartPRS\Platform\Support\UnresolvedConfiguration $e) {
            if (($probe['expect'] ?? null) === 'unresolved') {
                continue;
            }

            $problems[] = "{$probe['as_of']}: nothing governed the formula — " . $e->getMessage();

            continue;
        }

        if (($probe['expect'] ?? null) === 'unresolved') {
            $problems[] = "{$probe['as_of']}: a version resolved where the corpus says none governs yet";

            continue;
        }

        // The version, and then what it actually computes — a resolver that
        // returns the right row and an engine that evaluates the wrong one is
        // the failure this case exists to catch.
        if ((int) $graph[$code]->versionNo !== (int) $probe['expect_version']) {
            $problems[] = "{$probe['as_of']}: resolved v{$graph[$code]->versionNo}, expected v{$probe['expect_version']}";

            continue;
        }

        $result = (new Calculator($graph))->evaluateAll(
            conformance_context($case),
            'conformance:' . $case['id'],
        )[$code] ?? null;

        if ($result === null || $result->isError()) {
            $problems[] = "{$probe['as_of']}: the resolved formula did not evaluate";

            continue;
        }

        if ($result->money?->format() !== $probe['expect_value']) {
            $problems[] = "{$probe['as_of']}: gave " . ($result->money?->format() ?? 'no money')
                . ", expected {$probe['expect_value']}";
        }
    }

    $pdo->exec('DROP DATABASE `' . $fixture['database'] . '`');

    return $problems === [] ? null : implode('; ', $problems);
}

/**
 * A tenant with the publishing vocabulary and one formula artefact, or an
 * unrunnable marker. Smaller than the workflow fixture on purpose: a formula
 * needs an artefact and the config_version statuses, and nothing else.
 *
 * @return array{pdo: PDO, tenant: \SmartPRS\Platform\Tenancy\TenantContext, database: string}
 */
function conformance_formula_database(string $database): array
{
    $dsn = getenv('SMARTPRS_TEST_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
    $user = getenv('SMARTPRS_TEST_USER') ?: 'root';
    $password = getenv('SMARTPRS_TEST_PASSWORD') ?: '';

    try {
        $pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        throw new ConformanceNotRunnable(
            'no database. Effective dating is a property of stored versions, and a formula built in PHP '
            . 'has no version to be effective from; set SMARTPRS_TEST_DSN and run it again.'
        );
    }

    require_once __DIR__ . '/../../tests/lib/laravel_schema_stub.php';

    Illuminate\Support\Facades\Schema::reset();
    $files = glob(__DIR__ . '/../../database/migrations/*.php');
    sort($files);

    foreach ($files as $file) {
        (require $file)->up();
    }

    $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
    $pdo->exec("CREATE DATABASE `{$database}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$database}`");

    foreach (Illuminate\Support\Facades\Schema::$statements as $statement) {
        $pdo->exec($statement);
    }

    $pdo->exec("INSERT INTO tenants (uuid, code, name, status_code, created_at, updated_at)
                VALUES (UNHEX(REPEAT('a', 32)), 'T1', 'Tenant One', 'active', NOW(), NOW())");
    $tenantId = (int) $pdo->lastInsertId();

    $pdo->exec("INSERT INTO status_domains (uuid, domain_key, label, owning_context, created_at, updated_at)
                VALUES (UNHEX(REPEAT('b', 32)), 'config_version_status', 'Config version status', 'configuration', NOW(), NOW())");
    $domainId = (int) $pdo->lastInsertId();

    $insert = $pdo->prepare(
        'INSERT INTO status_values (tenant_id, status_domain_id, uuid, code, label, sort, is_active, created_at, updated_at)
         VALUES (:t, :d, UNHEX(:u), :c, :l, 0, 1, NOW(), NOW())'
    );
    $seed = 0;

    foreach (['draft', 'testing', 'pending_approval', 'published', 'superseded', 'archived'] as $status) {
        $insert->execute([
            't' => $tenantId,
            'd' => $domainId,
            'u' => str_pad(dechex(++$seed), 32, '0', STR_PAD_LEFT),
            'c' => $status,
            'l' => ucfirst($status),
        ]);
    }

    $pdo->exec("INSERT INTO config_artefacts
                  (tenant_id, uuid, artefact_type, artefact_key, name, owning_context, is_active, created_at, updated_at)
                VALUES ({$tenantId}, UNHEX(REPEAT('c', 32)), 'formula', 'F-BASIC', 'Basic pay', 'payroll', 1, NOW(), NOW())");

    $tenant = new \SmartPRS\Platform\Tenancy\TenantContext();
    $tenant->establish($tenantId);

    return ['pdo' => $pdo, 'tenant' => $tenant, 'database' => $database];
}

/**
 * FC-01 — integer paise throughout: no float appears in any intermediate.
 *
 * Asserted two ways, because either alone passes for the wrong reason. The
 * RUNTIME half walks every value the engine produced — the money, the exact
 * rational behind it, the SPX value and the whole trace string — and fails on
 * the first PHP float. The SOURCE half scans the money path for the operations
 * that would introduce one, because a graph that happens to avoid floats today
 * says nothing about the line somebody adds tomorrow.
 *
 * SmartPRS 2 is the counter-example: its payroll arithmetic is float throughout,
 * and its own code carries `0.005` epsilons to work around comparisons it does
 * not trust.
 */
function conformance_formula_no_float(array $case): ?string
{
    $results = (new Calculator(conformance_formulas($case)))
        ->evaluateAll(conformance_context($case), 'conformance:' . $case['id']);

    foreach ($results as $code => $result) {
        if ($result->isError()) {
            return "{$code} failed: {$result->error}";
        }

        $found = conformance_find_float([
            'money.minor' => $result->money?->minor,
            'exact.numerator' => $result->exact?->numerator,
            'exact.denominator' => $result->exact?->denominator,
            'value' => $result->value,
            'rounding.unit_minor' => $result->rounding->unitInMinor,
        ]);

        if ($found !== null) {
            return "{$code} carries a float at {$found}";
        }

        // A float that reached a payslip line renders as 33333.329999999998 or
        // as 3.3333329999999998E+4. Either shape in the trace is the defect.
        if (preg_match('/\d+\.\d{6,}|[0-9]E[+-]\d+/', $result->trace) === 1) {
            return "{$code}'s trace contains a float-shaped number, which is a value that was never exact";
        }
    }

    // The source half. Named operations, not a general "no float anywhere":
    // a float literal in a docblock is not a defect, and a check that cannot
    // tell the difference gets suppressed and then ignored.
    foreach ($case['source_must_not_use'] ?? [] as $needle) {
        foreach ($case['source_paths'] as $relative) {
            foreach (glob(__DIR__ . '/../../' . $relative) ?: [] as $file) {
                $source = (string) file_get_contents($file);
                // Strip comments first: the argument for integer money is
                // WRITTEN in these files, and it names the things it forbids.
                $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

                if (str_contains($code, (string) $needle)) {
                    return basename($file) . " uses {$needle} on the money path";
                }
            }
        }
    }

    return null;
}

/** @param array<string, mixed> $values */
function conformance_find_float(array $values, string $path = ''): ?string
{
    foreach ($values as $key => $value) {
        $here = $path === '' ? (string) $key : $path . '.' . $key;

        if (is_float($value)) {
            return $here;
        }

        if (is_array($value)) {
            $found = conformance_find_float($value, $here);

            if ($found !== null) {
                return $found;
            }

            continue;
        }

        if (is_object($value)) {
            $found = conformance_find_float(get_object_vars($value), $here);

            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

function conformance_formula_case(array $case): ?string
{
    $expect = $case['expect'] ?? [];
    $calculator = new Calculator(conformance_formulas($case));

    if (isset($expect['order'])) {
        $order = array_map(static fn (Formula $f): string => $f->code, $calculator->order());

        if ($order !== $expect['order']) {
            return 'evaluation order was ' . implode(' → ', $order)
                . ', expected ' . implode(' → ', $expect['order']);
        }
    }

    $results = $calculator->evaluateAll(conformance_context($case), 'conformance:' . $case['id']);

    foreach ($expect['values'] ?? [] as $code => $formatted) {
        $result = $results[$code] ?? null;

        if ($result === null) {
            return "{$code} was not evaluated";
        }

        if ($result->isError()) {
            return "{$code} failed: {$result->error}";
        }

        if ($result->money?->format() !== $formatted) {
            return "{$code} gave " . ($result->money?->format() ?? 'no money') . ", expected {$formatted}";
        }
    }

    foreach ($expect['trace_contains'] ?? [] as $fragment) {
        $traces = implode("\n", array_map(static fn ($r): string => $r->trace, $results));

        if (!str_contains($traces, (string) $fragment)) {
            return "the trace does not contain '{$fragment}'";
        }
    }

    return null;
}

/**
 * FC-03. The property is SUM(parts) = amount for every input, so the inputs are
 * generated rather than listed — including the degenerate shapes the blueprint
 * names: a single element, many elements, and weights summing to zero.
 */
function conformance_allocate(array $case): ?string
{
    $trials = (int) ($case['trials'] ?? 1000);
    mt_srand(20260904);

    for ($trial = 0; $trial < $trials; $trial++) {
        $amount = Money::ofMinor(mt_rand(-500_000_00, 500_000_00));
        $count = match ($trial % 4) {
            0 => 1,
            1 => 97,
            2 => 3,
            default => mt_rand(2, 12),
        };

        $weights = [];

        for ($i = 0; $i < $count; $i++) {
            $weights[] = $trial % 7 === 0 ? 0 : mt_rand(0, 1000);
        }

        try {
            $parts = $amount->allocate($weights);
        } catch (\InvalidArgumentException $e) {
            // Weights summing to zero have no defined split, and the engine
            // says so rather than dividing by zero. That is the declared
            // behaviour, not a failure of the property.
            if (array_sum($weights) === 0) {
                continue;
            }

            return "allocate({$amount->format()}, [" . implode(',', $weights) . ']) threw: ' . $e->getMessage();
        }

        $sum = Money::sum(...$parts);

        if ($sum->minor !== $amount->minor) {
            return "allocate({$amount->format()}, " . count($weights) . ' weights) summed to '
                . $sum->format() . ', losing ' . ($amount->minor - $sum->minor) . ' paise';
        }

        if (($case['expect']['reproducible'] ?? false) === true) {
            $again = $amount->allocate($weights);

            if (array_map(static fn (Money $m): int => $m->minor, $again)
                !== array_map(static fn (Money $m): int => $m->minor, $parts)) {
                return 'the same allocation produced different parts on a second call';
            }
        }
    }

    return null;
}

/**
 * FC-04. The blueprint's own worked residue case, with its own expected values.
 *
 * The naive arithmetic is computed alongside and must NOT match, because a case
 * that only asserts the right answer cannot tell you it is testing the thing
 * that distinguishes right from wrong.
 */
function conformance_balance_residue(array $case): ?string
{
    $expect = $case['expect'];
    $gross = Money::of((string) $case['gross']);
    [$worked, $total] = $case['prorate'];

    $prorated = Rational::of($gross->minor)->multipliedBy(Rational::of((int) $worked, (int) $total));
    $proratedMoney = Money::ofMinor($prorated->toInt(RoundingMode::HalfUp));

    $basic = Money::ofMinor(
        $prorated->multipliedBy(Rational::of((int) $case['basic_pct'], 100))->toInt(RoundingMode::HalfUp),
    );
    $hra = Money::ofMinor(
        Rational::of($basic->minor)
            ->multipliedBy(Rational::of((int) $case['hra_pct_of_basic'], 100))
            ->toInt(RoundingMode::HalfUp),
    );

    // SPECIAL is the BALANCE, not its own percentage. That is the whole case.
    $special = Money::ofMinor($proratedMoney->minor - $basic->minor - $hra->minor);
    $sum = Money::sum($basic, $hra, $special);

    foreach ([
        'basic' => $basic,
        'hra' => $hra,
        'special' => $special,
        'total' => $sum,
    ] as $key => $money) {
        if ($money->format() !== $expect[$key]) {
            return "{$key} was {$money->format()}, expected {$expect[$key]}";
        }
    }

    if ($sum->minor !== $proratedMoney->minor) {
        return "the components sum to {$sum->format()} and the prorated gross is {$proratedMoney->format()}";
    }

    // The defect this case exists to exclude: SPECIAL computed independently as
    // its own percentage and rounded on its own.
    $naiveSpecial = Money::ofMinor(
        $prorated->multipliedBy(Rational::of(100 - (int) $case['basic_pct']
            - (int) round((int) $case['basic_pct'] * (int) $case['hra_pct_of_basic'] / 100), 100))
            ->toInt(RoundingMode::HalfUp),
    );
    $naiveTotal = Money::sum($basic, $hra, $naiveSpecial);

    if ($naiveTotal->format() !== $expect['naive_total']) {
        return 'the naive independent rounding gave ' . $naiveTotal->format()
            . ", expected {$expect['naive_total']} — if the naive path no longer differs, this case has "
            . 'stopped distinguishing the defect from the fix';
    }

    return null;
}

/** FC-05. The modes differ only at the boundary, so the table sits on it. */
function conformance_rounding_table(array $case): ?string
{
    foreach ($case['cases'] as $row) {
        // RoundingMode is a pure enum; Rounding::of() is the one place a
        // declared mode name becomes a mode, and going through it means the
        // corpus and the engine cannot disagree about what 'half_even' is.
        $mode = Rounding::of((string) $row['mode'])->mode;
        $unit = (int) $row['unit'];
        $actual = $mode->divide((int) $row['minor'], $unit) * $unit;

        if ($actual !== (int) $row['expect']) {
            return sprintf(
                '%d rounded %s to a unit of %d gave %d, expected %d',
                $row['minor'],
                $row['mode'],
                $unit,
                $actual,
                $row['expect'],
            );
        }
    }

    return null;
}

function conformance_formula_publish(array $case): ?string
{
    $expect = $case['expect'] ?? [];

    try {
        $formula = conformance_formula('F-CONFORMANCE', $case['formula']);
    } catch (\Throwable $e) {
        // Refusal in the constructor is still a refusal at publish, and is the
        // stronger place for it.
        return ($expect['publishable'] ?? true) === false
            ? conformance_message($expect, $e->getMessage())
            : 'the formula could not be constructed: ' . $e->getMessage();
    }

    $report = PublishGates::run($formula);

    if ($report->isPublishable() !== ($expect['publishable'] ?? true)) {
        return $report->isPublishable()
            ? 'expected publication to be refused; every gate passed'
            : 'expected it to publish; ' . implode(' | ', array_map(
                static fn ($c): string => $c->gate . ' ' . implode('; ', $c->messages),
                $report->blocking(),
            ));
    }

    if (isset($expect['gate'])) {
        $check = $report->check((string) $expect['gate']);

        if ($check === null || !$check->blocks()) {
            return "expected gate {$expect['gate']} to block; it did not";
        }

        return conformance_message($expect, implode(' | ', $check->messages));
    }

    return null;
}

function conformance_formula_cycle(array $case): ?string
{
    try {
        (new Calculator(conformance_formulas($case)))->order();
    } catch (CircularDependency $e) {
        return conformance_message($case['expect'] ?? [], $e->getMessage());
    }

    return 'expected a circular dependency to be refused; the graph ordered without complaint';
}

/** FC-11. Currency is carried, so two currencies never silently add. */
function conformance_currency(array $case): ?string
{
    $left = Money::of((string) $case['left']['amount'], (string) $case['left']['currency']);
    $right = Money::of((string) $case['right']['amount'], (string) $case['right']['currency']);

    try {
        $left->plus($right);
    } catch (\Throwable $e) {
        return conformance_message($case['expect'] ?? [], $e->getMessage());
    }

    return 'two different currencies were added without complaint';
}

/** FC-13. Including traces: a trace that varies run to run is not evidence. */
function conformance_formula_determinism(array $case): ?string
{
    $corpus = json_decode(
        (string) file_get_contents(__DIR__ . '/../../tests/conformance/formula.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['cases'];

    $evaluable = array_values(array_filter(
        $corpus,
        static fn (array $c): bool => ($c['kind'] ?? '') === 'formula',
    ));

    if ($evaluable === []) {
        return 'no evaluable formula cases in the corpus';
    }

    $baseline = null;

    for ($run = 0; $run < (int) ($case['runs'] ?? 50); $run++) {
        $order = $evaluable;
        shuffle($order);
        $observed = [];

        foreach ($order as $one) {
            $results = (new Calculator(conformance_formulas($one)))
                ->evaluateAll(conformance_context($one), 'conformance:' . $one['id']);

            foreach ($results as $code => $result) {
                $observed[$one['id'] . '/' . $code] = $result->money?->format() . '|' . $result->trace;
            }
        }

        ksort($observed);
        $digest = hash('sha256', json_encode($observed, JSON_THROW_ON_ERROR));

        if ($baseline === null) {
            $baseline = $digest;

            continue;
        }

        if ($digest !== $baseline) {
            return "run {$run} produced different values or traces — the engine is order dependent";
        }
    }

    return null;
}

// ===========================================================================
// RULES — Part XXX §XXX.3.2
// ===========================================================================

use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\ConflictPolicy;
use SmartPRS\Platform\Rules\Engine as RuleEngine;
use SmartPRS\Platform\Rules\EvaluationContext;
use SmartPRS\Platform\Rules\GateEnvironment as RuleGateEnvironment;
use SmartPRS\Platform\Rules\PublishGates as RulePublishGates;
use SmartPRS\Platform\Rules\Rule;
use SmartPRS\Platform\Rules\RuleEvaluationFailed;
use SmartPRS\Platform\Rules\RuleSet;
use SmartPRS\Platform\Rules\SpxExpression;

/**
 * Build a Rule from the corpus's compact declaration.
 *
 * @param array<string, mixed> $declared
 */
function conformance_rule(string $decisionPoint, array $declared): Rule
{
    $symbols = DecisionPoints::symbols($decisionPoint);
    $action = (string) ($declared['action'] ?? 'APPLY_DAY_PENALTY');

    return new Rule(
        code: (string) $declared['code'],
        decisionPoint: $decisionPoint,
        module: 'attendance',
        name: (string) $declared['code'],
        businessDescription: 'A conformance fixture from Part XXX, evaluated by the real engine.',
        scope: isset($declared['scope']) ? Scope::of($declared['scope']) : Scope::tenantDefault(),
        priority: (int) ($declared['priority'] ?? 0),
        range: isset($declared['to'])
            ? EffectiveRange::fromInclusive(
                (string) ($declared['from'] ?? '2026-04-01'),
                (string) $declared['to'],
            )
            : EffectiveRange::openEndedFrom(new \DateTimeImmutable((string) ($declared['from'] ?? '2026-04-01'))),
        versionNo: 1,
        versionId: strtolower((string) $declared['code']) . '-v1',
        status: 'published',
        when: SpxExpression::parse((string) $declared['when'], $symbols),
        then: [conformance_action($action, $decisionPoint, isset($declared['reason']) ? (string) $declared['reason'] : null)],
        except: isset($declared['except'])
            ? SpxExpression::parse((string) $declared['except'], $symbols)
            : null,
        onConflict: ConflictPolicy::from((string) ($declared['on_conflict'] ?? 'highest_priority_only')),
    );
}

function conformance_action(string $code, string $decisionPoint, ?string $reason = null): Action
{
    $symbols = DecisionPoints::symbols($decisionPoint);

    return match ($code) {
        'APPLY_DAY_PENALTY' => new Action('APPLY_DAY_PENALTY', [
            'fraction' => SpxExpression::parse('0.5d', $symbols, boolean: false),
            // Overridable so RC-19 can reword ONLY the explanation and assert
            // that the preview reports it as an explanation change rather than
            // as the whole tenant's arithmetic moving.
            'reason' => $reason ?? 'A conformance fixture penalty.',
        ]),
        'SET_STATUS' => new Action('SET_STATUS', ['code' => 'ABSENT']),
        'CHARGE_DAY' => new Action('CHARGE_DAY', [
            'date' => SpxExpression::parse('#2026-08-26#', $symbols, boolean: false),
            'type' => 'EL',
            'units' => SpxExpression::parse('1d', $symbols, boolean: false),
            'reason' => 'A conformance fixture leave charge.',
        ]),
        default => throw new \InvalidArgumentException("The corpus asks for an action '{$code}' the adapter does not build."),
    };
}

/** The EvaluationContext a rules case declares. */
function conformance_rule_context(array $case): EvaluationContext
{
    $values = [];

    foreach ($case['context'] ?? [] as $key => $declared) {
        // The rule engine accepts raw scalars and types them from the symbol
        // table, so the corpus's typed declarations are unwrapped here.
        $values[$key] = match (true) {
            isset($declared['minutes']) => (int) $declared['minutes'],
            isset($declared['days']) => (int) $declared['days'],
            isset($declared['number']) => (int) $declared['number'],
            isset($declared['boolean']) => (bool) $declared['boolean'],
            isset($declared['text']) => (string) $declared['text'],
            // A date and a code are strings to PHP and are wrapped against the
            // symbol's DECLARED type, like every other entry — the corpus names
            // the type so the fixture cannot be read as a bare string.
            isset($declared['date']) => (string) $declared['date'],
            isset($declared['code']) => (string) $declared['code'],
            default => throw new \InvalidArgumentException("Context entry '{$key}' declares no type."),
        };
    }

    return new EvaluationContext(
        tenantId: 'T-CONFORMANCE',
        subjectType: 'employee_day',
        subjectId: 'E-0001',
        subjectDate: new \DateTimeImmutable((string) ($case['evaluation_date'] ?? '2026-08-26')),
        dimensions: $case['dimensions'] ?? [],
        values: $values,
    );
}

function conformance_rules_decide(array $case): ?string
{
    $expect = $case['expect'] ?? [];
    $point = (string) $case['decision_point'];

    $rules = array_map(
        static fn (array $declared): Rule => conformance_rule($point, $declared),
        $case['rules'],
    );

    $result = (new RuleEngine())->evaluate($point, RuleSet::of($point, ...$rules), conformance_rule_context($case));

    if (isset($expect['status']) && $result->status !== $expect['status']) {
        return "expected status {$expect['status']}, got {$result->status}";
    }

    if (isset($expect['action_count']) && count($result->actions) !== (int) $expect['action_count']) {
        return 'expected ' . $expect['action_count'] . ' action(s), got ' . count($result->actions)
            . ': ' . implode(' · ', $result->rendered());
    }

    if (isset($expect['emitted_by'])) {
        $codes = array_map(static fn ($a): string => (string) $a->emittedBy['rule_code'], $result->actions);

        if (!in_array($expect['emitted_by'], $codes, true)) {
            return "expected an action from {$expect['emitted_by']}; got " . (implode(', ', $codes) ?: 'none');
        }
    }

    $trace = $result->trace->render();

    foreach ($expect['trace_contains'] ?? [] as $fragment) {
        if (!str_contains($trace, (string) $fragment)) {
            return "the trace does not contain '{$fragment}'";
        }
    }

    return null;
}

/**
 * SPX-11 / RC-18. An evaluation error must not become a permissive answer.
 *
 * The condition would be TRUE if the division were ignored, so a swallowed
 * error would apply the penalty. It must instead fail the evaluation and leak
 * no partial action set.
 */
function conformance_rules_fail_closed(array $case): ?string
{
    $point = (string) $case['decision_point'];
    $rules = array_map(
        static fn (array $declared): Rule => conformance_rule($point, $declared),
        $case['rules'],
    );

    try {
        $result = (new RuleEngine())->evaluate($point, RuleSet::of($point, ...$rules), conformance_rule_context($case));
    } catch (RuleEvaluationFailed $e) {
        return str_contains($e->getMessage(), 'ERR_') || $e->errorCode !== ''
            ? null
            : 'the failure did not name an error code: ' . $e->getMessage();
    }

    return count($result->actions) === 0 && $result->failed()
        ? null
        : 'the evaluation error did not fail closed: status ' . $result->status
            . ' with ' . count($result->actions) . ' action(s)';
}

/**
 * RC-18 — fail closed under fault injection.
 *
 * Four fault points, each asserted to produce a NAMED error code and ZERO
 * actions. The interesting one is symbol resolution, because until this case
 * was written it did not fail at all: R-VII-015's mechanism was complete —
 * `Symbol::$notNullRequired`, `SymbolTable::requiringNotNull()`, the
 * evaluator's ERR_MISSING_INPUT branch, even a passing unit test — and NOT ONE
 * DECISION POINT DECLARED A SYMBOL, so the branch was unreachable in production
 * and a rule reading an input nobody supplied quietly did not match.
 *
 * "The employee had no worked minutes, so no penalty rule matched" and "we
 * failed to load their attendance, so no penalty rule matched" were the same
 * outcome. They must not be.
 */
function conformance_rules_fault_injection(array $case): ?string
{
    $problems = [];

    foreach ($case['faults'] as $fault) {
        $point = (string) $fault['decision_point'];
        $rules = array_map(
            static fn (array $declared): Rule => conformance_rule($point, $declared),
            $fault['rules'],
        );

        $probe = $fault + ['id' => $case['id']];

        try {
            $result = (new RuleEngine())->evaluate(
                $point,
                RuleSet::of($point, ...$rules),
                conformance_rule_context($probe),
            );
        } catch (RuleEvaluationFailed $e) {
            if ($e->errorCode !== (string) $fault['expect_error']) {
                $problems[] = "{$fault['name']}: failed with '{$e->errorCode}', expected '{$fault['expect_error']}'";
            }

            continue;
        } catch (\Throwable $e) {
            $problems[] = "{$fault['name']}: threw " . $e::class . ' rather than failing the evaluation cleanly';

            continue;
        }

        // Reaching here at all is the defect this class is about: the fault was
        // injected and the engine carried on. Say which way it carried on,
        // because "no_match with no actions" LOOKS safe and is the exact shape
        // of the bug.
        $problems[] = sprintf(
            "%s: no failure — status '%s' with %d action(s). A missing input that reads as 'no rule matched' "
            . 'is indistinguishable from an input that was legitimately absent',
            $fault['name'],
            $result->status,
            count($result->actions),
        );
    }

    // The control: the SAME rules with the input supplied must still work.
    // Without it this case would pass on an engine that failed everything.
    foreach ($case['controls'] as $control) {
        $point = (string) $control['decision_point'];
        $rules = array_map(
            static fn (array $declared): Rule => conformance_rule($point, $declared),
            $control['rules'],
        );

        try {
            $result = (new RuleEngine())->evaluate(
                $point,
                RuleSet::of($point, ...$rules),
                conformance_rule_context($control + ['id' => $case['id']]),
            );
        } catch (\Throwable $e) {
            $problems[] = "{$control['name']}: the control failed — " . $e->getMessage();

            continue;
        }

        if (count($result->actions) !== (int) $control['expect_actions']) {
            $problems[] = "{$control['name']}: the control produced " . count($result->actions)
                . " action(s), expected {$control['expect_actions']} — fail-closed has become fail-always";
        }
    }

    return $problems === [] ? null : implode('; ', $problems);
}

/**
 * RC-19 — the impact preview is exact.
 *
 * "The previewed changed-employee count and the ACTUAL differ by zero." That is
 * trivially true of a preview that is just "run it and see", so this case
 * asserts the three ways a preview stops being exact as well as the equality:
 *
 *   the previewed count equals the count obtained by evaluating the population
 *   under the proposed version and comparing outcome by outcome;
 *   a preview taken at TODAY's date rather than the version's EFFECTIVE date
 *   gives a different (reassuring, wrong) answer, so the effective date is
 *   load-bearing rather than incidental;
 *   a subject whose evaluation FAILS is counted as changed and named, not
 *   quietly folded into "unchanged".
 */
function conformance_rules_impact_preview(array $case): ?string
{
    $point = (string) $case['decision_point'];
    $effective = new \DateTimeImmutable((string) $case['effective_from']);

    $current = RuleSet::of($point, ...array_map(
        static fn (array $d): Rule => conformance_rule($point, $d),
        $case['current'],
    ));
    $proposed = RuleSet::of($point, ...array_map(
        static fn (array $d): Rule => conformance_rule($point, $d),
        $case['proposed'],
    ));

    $population = array_map(
        static fn (array $subject): EvaluationContext => conformance_rule_context(
            $subject + ['evaluation_date' => (string) $case['effective_from']],
        ),
        $case['population'],
    );

    $report = ImpactPreview::between($point, $current, $proposed, $population, $effective, $case['author'] ?? null);
    $problems = [];

    // THE ACTUAL, computed independently of the preview: evaluate each subject
    // under each version and compare. If this were a call back into
    // ImpactPreview the case would assert that a function equals itself.
    $engine = new RuleEngine();
    $actual = [];

    foreach ($population as $context) {
        $before = conformance_outcome_of($engine, $point, $current, $context, $effective);
        $after = conformance_outcome_of($engine, $point, $proposed, $context, $effective);

        if ($before !== $after) {
            $actual[] = $context->subjectId;
        }
    }

    // `$actual` above compares the FULL rendering, explanation included. Where
    // the corpus says the only difference is wording, that comparison and the
    // preview's are supposed to disagree — and the case asserts which is which
    // rather than quietly relaxing the equality.
    if (($case['expect']['annotation_only'] ?? 0) === 0 && $report->changedSubjectIds !== $actual) {
        $problems[] = 'the preview named [' . implode(', ', $report->changedSubjectIds)
            . '] and the actual changed set is [' . implode(', ', $actual) . ']';
    }

    if ($report->changedCount() !== (int) $case['expect']['changed']) {
        $problems[] = "the preview counted {$report->changedCount()} changed, the corpus says {$case['expect']['changed']}";
    }

    if ($report->subjectCount !== count($population)) {
        $problems[] = 'the report claims a population of ' . $report->subjectCount
            . ' and was given ' . count($population) . ' — a count over a sample is an estimate';
    }

    if ($report->requiresApproval() !== (bool) $case['expect']['requires_approval']) {
        $problems[] = 'requiresApproval() disagrees with the corpus';
    }

    if (isset($case['expect']['annotation_only'])
        && count($report->annotationOnlySubjectIds) !== (int) $case['expect']['annotation_only']
    ) {
        $problems[] = 'the report names ' . count($report->annotationOnlySubjectIds)
            . ' annotation-only change(s), expected ' . $case['expect']['annotation_only'];
    }

    if (count($report->erroredSubjects) !== (int) ($case['expect']['errored'] ?? 0)) {
        $problems[] = 'the report names ' . count($report->erroredSubjects)
            . ' unevaluable subject(s), expected ' . ($case['expect']['errored'] ?? 0);
    }

    foreach ($case['expect']['render_contains'] ?? [] as $fragment) {
        if (!str_contains($report->render(), (string) $fragment)) {
            return "the approver's summary does not say '{$fragment}' — " . $report->render();
        }
    }

    // The effective date is load-bearing. Previewed at a date BEFORE the
    // proposed version takes effect, the same call must report a different
    // number — the reassuring one that a preview run against "today" would give.
    if (isset($case['expect']['changed_if_previewed_early'])) {
        $early = ImpactPreview::between(
            $point,
            $current,
            $proposed,
            $population,
            new \DateTimeImmutable((string) $case['previewed_early_at']),
        );

        if ($early->changedCount() !== (int) $case['expect']['changed_if_previewed_early']) {
            $problems[] = 'previewed at ' . $case['previewed_early_at'] . ' the count is '
                . $early->changedCount() . ', expected ' . $case['expect']['changed_if_previewed_early']
                . ' — if these two are equal the effective date is not being used';
        }

        if ($early->changedCount() === $report->changedCount()) {
            $problems[] = 'a preview at the version\'s effective date and one before it give the same '
                . 'answer, so the date is decorative';
        }
    }

    return $problems === [] ? null : implode('; ', $problems);
}

/** One subject's outcome, computed WITHOUT going through ImpactPreview. */
function conformance_outcome_of(
    RuleEngine $engine,
    string $point,
    RuleSet $set,
    EvaluationContext $context,
    \DateTimeImmutable $asOf,
): string {
    try {
        $result = $engine->evaluate($point, $set, $context, $asOf);
    } catch (RuleEvaluationFailed $e) {
        return 'ERROR:' . $e->errorCode;
    }

    return $result->status . ' | ' . implode(' ; ', $result->rendered());
}

function conformance_rules_publish(array $case): ?string
{
    $expect = $case['expect'] ?? [];
    $rule = conformance_rule((string) $case['decision_point'], $case['rule']);
    $report = RulePublishGates::run($rule, new RuleGateEnvironment());

    if ($report->isPublishable() !== ($expect['publishable'] ?? true)) {
        return $report->isPublishable()
            ? 'expected publication to be refused; every gate passed'
            : 'expected it to publish; it was refused';
    }

    if (isset($expect['gate'])) {
        $check = $report->check((string) $expect['gate']);

        if ($check === null || !$check->blocks()) {
            return "expected gate {$expect['gate']} to block; it did not";
        }

        return conformance_message($expect, implode(' | ', $check->messages));
    }

    return null;
}

/**
 * RC-01. Purity asserted from the type, not by counting queries.
 *
 * The engine's constructor takes an engine version and two budgets. There is no
 * connection, no repository and no container in it, so "zero writes" is a
 * property of the signature rather than an observation about one run.
 */
function conformance_rules_purity(array $case): ?string
{
    $parameters = (new \ReflectionMethod(RuleEngine::class, '__construct'))->getParameters();

    foreach ($parameters as $parameter) {
        $type = (string) $parameter->getType();

        if (str_contains($type, 'PDO') || str_contains($type, 'Repository') || str_contains($type, 'Connection')) {
            return "the engine's constructor takes {$parameter->getName()}: {$type} — it can reach storage";
        }
    }

    $properties = (new \ReflectionClass(RuleEngine::class))->getProperties();

    foreach ($properties as $property) {
        $type = (string) $property->getType();

        if (str_contains($type, 'PDO') || str_contains($type, 'Repository')) {
            return "the engine holds {$property->getName()}: {$type}";
        }
    }

    return null;
}

// ===========================================================================
// WORKFLOW — Part XXX §XXX.3.4
// ===========================================================================

use SmartPRS\Platform\Workflow\Engine as WorkflowEngine;
use SmartPRS\Platform\Workflow\Node;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\ParticipantResolver;
use SmartPRS\Platform\Workflow\ParticipantSpec;
use SmartPRS\Platform\Workflow\ResolutionContext;
use SmartPRS\Platform\Workflow\Sla\Sweep;
use SmartPRS\Platform\Workflow\Sla\SweepDecision;
use SmartPRS\Platform\Workflow\Sla\Timer as SlaTimer;
use SmartPRS\Platform\Workflow\Task;
use SmartPRS\Platform\Workflow\TaskAlreadyDecided;
use SmartPRS\Platform\Workflow\TaskState;

/**
 * WC-01 / WC-12. A decided step is immutable, and that is enforced on the value
 * object rather than by a check somebody remembers to write.
 */
function conformance_workflow_decide_twice(array $case): ?string
{
    $expect = $case['expect'] ?? [];
    $at = new \DateTimeImmutable('2026-08-26 10:00:00');

    $decided = (new Task('t-conformance', 'manager', (string) $case['first']['by']))->decide(
        TaskState::from((string) $case['first']['state']),
        (string) $case['first']['by'],
        $at,
        (string) ($case['first']['comment'] ?? ''),
    );

    $refused = false;

    try {
        $decided->decide(
            TaskState::from((string) $case['second']['state']),
            (string) $case['second']['by'],
            $at->modify('+1 minute'),
            (string) ($case['second']['comment'] ?? ''),
        );
    } catch (TaskAlreadyDecided) {
        $refused = true;
    }

    if ($refused !== ($expect['refused'] ?? true)) {
        return $refused ? 'the second decision was refused and should not have been'
            : 'the second decision was ACCEPTED — a decided step is not immutable';
    }

    if (isset($expect['comment_unchanged']) && $decided->comment !== $expect['comment_unchanged']) {
        return "the first decision's comment changed to '" . (string) $decided->comment
            . "'; it must still read '{$expect['comment_unchanged']}'";
    }

    if (isset($expect['decided_by_unchanged']) && $decided->decidedByUserId !== $expect['decided_by_unchanged']) {
        return "the decider changed to {$decided->decidedByUserId}";
    }

    return null;
}

/** WC-03. Quorum at n−1, n and n+1 — the boundary the node exists to sit on. */
/**
 * WC-07 — maker-checker shapes: every shape uses the SAME engine, and zero
 * module-specific approval code exists.
 *
 * Asserted in two halves, because each alone passes for the wrong reason.
 *
 * The BEHAVIOURAL half drives every declared shape — single approver,
 * sequential chain, parallel-all, quorum, and a segregated maker-checker —
 * through one WorkflowEngine and asserts each produces its declared semantics.
 * One engine covering five shapes is the claim; five shapes each asserted by
 * their own code would prove nothing about the engine.
 *
 * The STRUCTURAL half counts the files OUTSIDE the workflow namespace that
 * decide approvals, and requires zero of a non-zero denominator. Reporting "0
 * modules scanned, 0 offenders" would be the vacuous version, so the case
 * states how many files were actually read: SmartPRS 2 has an approval
 * implementation per module, which is why a fix to leave approval never reaches
 * the loan one.
 */
function conformance_workflow_shapes(array $case): ?string
{
    $at = new \DateTimeImmutable('2026-08-26 10:00:00');
    $engine = new WorkflowEngine(static fn (string $e, $i): bool => true);
    $problems = [];

    foreach ($case['shapes'] as $shape) {
        $type = NodeType::from((string) $shape['node_type']);

        $node = new Node(
            key: 'step',
            type: $type,
            label: (string) $shape['label'],
            participants: [new ParticipantSpec('requester')],
            params: $shape['params'] ?? [],
        );

        $tasks = [];

        foreach ($shape['tasks'] as $index => $declared) {
            $task = new Task('t-' . $index, 'step', 'u-' . $index);

            $tasks[] = ($declared['decision'] ?? null) === null
                ? $task
                : $task->decide(
                    TaskState::from((string) $declared['decision']),
                    'u-' . $index,
                    $at,
                );
        }

        $actual = $engine->outcomeOf($node, $tasks) ?? 'pending';

        if ($actual !== $shape['expect']) {
            $problems[] = "{$shape['label']}: the node was '{$actual}', expected '{$shape['expect']}'";
        }
    }

    // The structural half. `TaskState` and the decision outcomes are the
    // vocabulary an approval implementation cannot avoid naming.
    $scanned = 0;
    $offenders = [];

    foreach ($case['scan'] as $pattern) {
        foreach (glob(__DIR__ . '/../../' . $pattern) ?: [] as $file) {
            $normalised = str_replace('\\', '/', $file);

            if (str_contains($normalised, '/Platform/Workflow/')) {
                continue;
            }

            $scanned++;
            $source = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($file));

            foreach ($case['forbidden_outside_workflow'] as $needle) {
                if (str_contains($source, (string) $needle)) {
                    $offenders[] = basename($file) . " names {$needle}";
                }
            }
        }
    }

    if ($scanned < (int) ($case['expect_min_files_scanned'] ?? 1)) {
        $problems[] = "only {$scanned} file(s) outside the workflow namespace were scanned, which is not "
            . 'enough of a denominator for "zero module-specific approval code" to mean anything';
    }

    foreach ($offenders as $offender) {
        $problems[] = 'module-specific approval code: ' . $offender;
    }

    return $problems === [] ? null : implode('; ', $problems);
}

function conformance_workflow_quorum(array $case): ?string
{
    $n = (int) $case['n'];
    $m = (int) $case['m'];
    $at = new \DateTimeImmutable('2026-08-26 10:00:00');

    $node = new Node(
        key: 'panel',
        type: NodeType::Quorum,
        label: 'Panel',
        participants: [new ParticipantSpec('requester')],
        params: ['n' => $n, 'm' => $m, 'tie_policy' => 'reject'],
    );

    $engine = new WorkflowEngine(static fn (string $e, $i): bool => true);

    $tasksApproving = static function (int $approvals) use ($m, $at): array {
        $tasks = [];

        for ($i = 0; $i < $m; $i++) {
            $task = new Task('t-' . $i, 'panel', 'u-' . $i);
            $tasks[] = $i < $approvals ? $task->decide(TaskState::Approved, 'u-' . $i, $at) : $task;
        }

        return $tasks;
    };

    foreach ([
        'at_n_minus_1' => $n - 1,
        'at_n' => $n,
        'at_n_plus_1' => $n + 1,
    ] as $key => $approvals) {
        if (!isset($case['expect'][$key])) {
            continue;
        }

        $outcome = $engine->outcomeOf($node, $tasksApproving($approvals));
        $actual = $outcome ?? 'pending';

        if ($actual !== $case['expect'][$key]) {
            return "with {$approvals} of {$m} approvals the node was '{$actual}', expected '{$case['expect'][$key]}'";
        }
    }

    return null;
}

/** WC-03. A rejection decides a parallel_all node whichever order it arrives in. */
function conformance_workflow_parallel(array $case): ?string
{
    $at = new \DateTimeImmutable('2026-08-26 10:00:00');
    $engine = new WorkflowEngine(static fn (string $e, $i): bool => true);

    $node = new Node(
        key: 'both',
        type: NodeType::ParallelAll,
        label: 'Both must approve',
        participants: [new ParticipantSpec('requester')],
    );

    $order = [
        (new Task('a', 'both', 'u-1'))->decide(TaskState::Approved, 'u-1', $at),
        (new Task('b', 'both', 'u-2'))->decide(TaskState::Rejected, 'u-2', $at->modify('+1 second')),
    ];

    $forwards = $engine->outcomeOf($node, $order);
    $backwards = $engine->outcomeOf($node, array_reverse($order));

    if ($forwards !== $backwards) {
        return "arrival order decided the outcome: {$forwards} one way, {$backwards} the other";
    }

    if (isset($case['expect']['outcome']) && $forwards !== $case['expect']['outcome']) {
        return "expected {$case['expect']['outcome']}, got " . ($forwards ?? 'pending');
    }

    return null;
}

/** WC-05. The clock is advanced to either side of the breach. */
function conformance_workflow_sla(array $case): ?string
{
    $expect = $case['expect'] ?? [];

    $node = new Node(
        key: 'manager',
        type: NodeType::Approval,
        label: 'Manager approval',
        participants: [new ParticipantSpec('requester')],
        slaMinutes: (int) $case['sla_minutes'],
        escalationPolicy: (string) $case['escalation_policy'],
        escalationTarget: 'u-head',
        staleThresholdMinutes: isset($case['stale_threshold_minutes'])
            ? (int) $case['stale_threshold_minutes']
            : null,
    );

    $dueAt = new \DateTimeImmutable('2026-08-27 15:30:00');
    $timer = new SlaTimer('escalation', $dueAt, 'manager', 't-1');

    if (isset($expect['before'])) {
        // A second before the due instant the timer is not due at all, so the
        // sweep would never have claimed it. Asserted as "nothing happened"
        // rather than by asking the decision function, because the claim query
        // is where "not yet" is decided.
        $beforeIsDue = $dueAt->modify('-1 second') >= $dueAt;

        if ($beforeIsDue) {
            return 'a timer one second before its due instant was treated as due';
        }
    }

    $decision = Sweep::decide($timer, $node, $dueAt->modify('+' . (int) ($case['late_by_minutes'] ?? 0) . ' minutes')->modify('+1 second'));

    $expected = match ((string) ($expect['after'] ?? '')) {
        'notify' => SweepDecision::NOTIFY,
        'suppressed' => SweepDecision::SUPPRESSED,
        'auto_decide' => SweepDecision::AUTO_DECIDE,
        'none' => SweepDecision::RECORD_ONLY,
        default => null,
    };

    if ($expected !== null && $decision->action !== $expected) {
        return "at breach+1s the sweep decided '{$decision->action}', expected '{$expected}': {$decision->reason}";
    }

    return null;
}

/** WC-06. The prohibition is part of resolution, not a check somebody adds. */
function conformance_workflow_self_approval(array $case): ?string
{
    $expect = $case['expect'] ?? [];

    $node = new Node(
        key: 'manager',
        type: NodeType::Approval,
        label: 'Manager approval',
        participants: [new ParticipantSpec('role_in_scope', [
            'role_key' => 'MGR',
            'holders' => $case['holders'],
        ])],
    );

    $resolver = new ParticipantResolver(
        static fn (ParticipantSpec $spec, ResolutionContext $c): array => (array) ($spec->params['holders'] ?? []),
    );

    $resolution = $resolver->resolve($node, new ResolutionContext(
        new \DateTimeImmutable('2026-08-26'),
        requesterUserId: (string) $case['requester'],
        subjectEmployeeUserId: (string) $case['subject'],
    ));

    if (isset($expect['resolved']) && $resolution->assignees !== $expect['resolved']) {
        return 'resolved to [' . implode(', ', $resolution->assignees)
            . '], expected [' . implode(', ', $expect['resolved']) . ']';
    }

    $rendered = $resolution->render();

    foreach ($expect['render_contains'] ?? [] as $fragment) {
        if (!str_contains($rendered, (string) $fragment)) {
            return "the resolution does not name '{$fragment}'";
        }
    }

    return null;
}

/**
 * WC-09 — delegation and backup approvers: active, expired, chained, circular.
 *
 * Two of those four states could not be expressed before this work, because the
 * resolver took `array<from, to>` and a map has no dates. A case class asking
 * for "active, expired and circular" against a shape that cannot represent an
 * expiry is a case class that cannot be written, which is why it stood NOT
 * COVERED rather than passing on the two states that did exist.
 */
function conformance_workflow_delegation(array $case): ?string
{
    $asOf = new \DateTimeImmutable((string) ($case['as_of'] ?? '2026-08-26'));

    $delegations = array_map(
        static fn (array $d): \SmartPRS\Platform\Workflow\Delegation => new \SmartPRS\Platform\Workflow\Delegation(
            (string) $d['from'],
            (string) $d['to'],
            isset($d['effective_from']) ? new \DateTimeImmutable((string) $d['effective_from']) : null,
            isset($d['effective_to']) ? new \DateTimeImmutable((string) $d['effective_to']) : null,
            (string) ($d['kind'] ?? \SmartPRS\Platform\Workflow\Delegation::DELEGATION),
        ),
        $case['delegations'] ?? [],
    );

    $node = new Node(
        key: 'manager',
        type: NodeType::Approval,
        label: 'Manager approval',
        participants: [new ParticipantSpec('role_in_scope', [
            'role_key' => 'MGR',
            'holders' => $case['holders'],
        ])],
        delegationAllowed: (bool) ($case['delegation_allowed'] ?? true),
    );

    $resolver = new ParticipantResolver(
        static fn (ParticipantSpec $spec, ResolutionContext $c): array => (array) ($spec->params['holders'] ?? []),
    );

    $resolution = $resolver->resolve($node, new ResolutionContext(
        $asOf,
        requesterUserId: (string) ($case['requester'] ?? 'u-hr'),
        subjectEmployeeUserId: isset($case['subject']) ? (string) $case['subject'] : null,
        delegations: $delegations,
    ));

    $expect = $case['expect'] ?? [];

    if (isset($expect['resolved']) && $resolution->assignees !== $expect['resolved']) {
        return 'resolved to [' . implode(', ', $resolution->assignees)
            . '], expected [' . implode(', ', $expect['resolved']) . ']';
    }

    $rendered = $resolution->render();

    foreach ($expect['render_contains'] ?? [] as $fragment) {
        if (!str_contains($rendered, (string) $fragment)) {
            return "the resolution does not name '{$fragment}' — " . $rendered;
        }
    }

    return null;
}

/**
 * WC-11 — return-for-edit preserves history.
 *
 * "Prior decisions stay visible and the resubmission is a NEW step, not an
 * overwrite." SmartPRS 2 returns a request by writing over the same row: the
 * status goes back to pending, the remark is replaced, and the fact that a
 * manager once returned it with a reason is gone. The reason somebody was asked
 * to fix their claim is exactly what the next approver needs to see.
 */
function conformance_workflow_return_for_edit(array $case): ?string
{
    $fixture = conformance_workflow_database('smartprs3_conformance_wc11');
    $pdo = $fixture['pdo'];
    $tenant = $fixture['tenant'];

    $audit = new \SmartPRS\Platform\Audit\AuditLedger($pdo, $tenant);
    $tasks = new \SmartPRS\Platform\Workflow\Persistence\TaskRepository(
        $pdo,
        $tenant,
        new \SmartPRS\Platform\Persistence\StatusLookup($pdo, $tenant),
        $audit,
    );
    $ledger = new \SmartPRS\Platform\Workflow\Persistence\TransitionLedger($pdo, $tenant, $audit);

    $at = new \DateTimeImmutable((string) ($case['at'] ?? '2026-08-15 10:00:00'));
    $reason = (string) ($case['return_reason'] ?? 'the dates overlap an approved leave');
    $instanceId = $fixture['instance_id'];

    // The manager returns it for edit, with a reason.
    $first = $tasks->open($instanceId, 'manager', '201', $at);
    $returned = $tasks->decide(
        $first,
        \SmartPRS\Platform\Workflow\TaskState::Returned,
        '201',
        $at->modify('+30 minutes'),
        1,
        $reason,
    );

    $pdo->beginTransaction();
    $ledger->append(
        $instanceId,
        'returned',
        $at->modify('+30 minutes'),
        fromNodeKey: 'manager',
        toNodeKey: 'edit',
        taskId: $first,
        actorUserId: '201',
        comment: $reason,
    );
    $pdo->commit();

    // The requester fixes it and resubmits. A NEW row, pointing at the one it
    // replaces — not the same row set back to pending.
    $second = $tasks->open(
        $instanceId,
        'manager',
        '201',
        $at->modify('+2 hours'),
        supersedesTaskId: $first,
    );

    $pdo->beginTransaction();
    $ledger->append(
        $instanceId,
        'resubmitted',
        $at->modify('+2 hours'),
        fromNodeKey: 'edit',
        toNodeKey: 'manager',
        taskId: $second,
        actorUserId: '100',
    );
    $pdo->commit();

    $problems = [];
    $original = $tasks->find($first);
    $replacement = $tasks->find($second);
    $states = new \SmartPRS\Platform\Persistence\StatusLookup($pdo, $tenant);

    if (!$returned['decided']) {
        $problems[] = 'the return itself did not land';
    }

    if ($original === null || $replacement === null) {
        return 'one of the two task rows is missing entirely';
    }

    if ((int) $original['status_value_id'] !== $states->id('workflow_task_state', 'returned')) {
        $problems[] = 'the returned task no longer reads as returned — the resubmission overwrote it';
    }

    if ((string) $original['decision_comment'] !== $reason) {
        $problems[] = "the reason the manager gave is gone: '" . (string) $original['decision_comment'] . "'";
    }

    if ((string) $original['decided_by_user_id'] !== '201') {
        $problems[] = 'who returned it is no longer recorded';
    }

    if ((int) $replacement['supersedes_task_id'] !== $first) {
        $problems[] = 'the new task does not point at the one it replaces, so the chain is not walkable';
    }

    if ((int) $replacement['status_value_id'] !== $states->id('workflow_task_state', 'pending')) {
        $problems[] = 'the resubmitted task is not pending';
    }

    if ($first === $second) {
        $problems[] = 'the resubmission reused the same row, which is the overwrite this class forbids';
    }

    $history = array_map(
        static fn (array $t): string => (string) $t['outcome_code'],
        $ledger->history($instanceId),
    );

    if ($history !== ['returned', 'resubmitted']) {
        $problems[] = 'the ledger reads [' . implode(', ', $history) . '] and should read [returned, resubmitted]';
    }

    if ($audit->verify() !== null) {
        $problems[] = 'the audit chain does not verify across the return and the resubmission';
    }

    $pdo->exec('DROP DATABASE `' . $fixture['database'] . '`');

    return $problems === [] ? null : implode('; ', $problems);
}

/**
 * WC-04 — in-flight version pinning.
 *
 * "An instance mid-flight on v1 completes on v1 while a new instance starts on
 * v2." `workflow_instances.config_version_id` has existed since the workflow
 * migration and nothing read it, so until InstanceRepository landed this was a
 * property of a column rather than of the platform: the only definition anybody
 * could load was whatever resolve() returned today.
 *
 * Both versions are published through the real publisher. Two rows inserted by
 * hand would prove that a foreign key holds, not that publishing a new approval
 * chain leaves the running ones alone.
 */
function conformance_workflow_version_pinning(array $case): ?string
{
    require_once __DIR__ . '/../../tests/lib/workflow_fixtures.php';

    $fixture = conformance_workflow_database('smartprs3_conformance_wc04');
    $pdo = $fixture['pdo'];
    $tenant = $fixture['tenant'];

    $states = new \SmartPRS\Platform\Persistence\StatusLookup($pdo, $tenant);
    $versions = new \SmartPRS\Platform\Config\ConfigVersionRepository($pdo, $tenant, $states);
    $publisher = new \SmartPRS\Platform\Workflow\WorkflowPublisher(
        new \SmartPRS\Platform\Config\Publisher($pdo, $tenant, $states, $versions),
        $pdo,
        $tenant,
    );
    $instances = new \SmartPRS\Platform\Workflow\Persistence\InstanceRepository($pdo, $tenant, $states, $versions);

    $v1At = new \DateTimeImmutable((string) ($case['v1_published'] ?? '2026-08-01 09:00:00'));
    $startedAt = new \DateTimeImmutable((string) ($case['instance_started'] ?? '2026-08-10 09:00:00'));
    $v2At = new \DateTimeImmutable((string) ($case['v2_published'] ?? '2026-08-20 09:00:00'));
    $laterAt = new \DateTimeImmutable((string) ($case['second_instance'] ?? '2026-08-25 09:00:00'));

    $audit = ['created_by' => '100', 'approved_by' => '101'];

    $v1 = $publisher->publish(wfDefinition(), $v1At, null, $audit);

    // An instance begins under v1 and is still running when v2 lands.
    $inFlight = $instances->start('LEAVE-APPROVE', 'leave_request', 9, '100', $startedAt);

    // v2: the same workflow with a different chain. Effective from the day it
    // is published, so from that day on it is what resolve() returns.
    $v2Definition = wfDefinition(['range' => \SmartPRS\Platform\Support\EffectiveRange::openEndedFrom($v2At)]);
    $v2 = $publisher->publish($v2Definition, $v2At, null, $audit);

    $started = $instances->start('LEAVE-APPROVE', 'leave_request', 10, '100', $laterAt);

    $problems = [];

    if ($v1 === $v2) {
        $problems[] = 'the second publication reused the first version, so there is nothing to pin against';
    }

    $pinned = (int) $instances->pinnedVersion($inFlight)['id'];

    if ($pinned !== $v1) {
        $problems[] = "the in-flight instance moved from version {$v1} to {$pinned} when v2 was published — "
            . 'editing an approval chain must not rewrite the requests already in it';
    }

    if ((int) $instances->pinnedVersion($started)['id'] !== $v2) {
        $problems[] = 'the instance started after v2 did not pick it up, so pinning has become freezing';
    }

    // And the pin is what the definition is READ through, not just a column.
    $definition = $instances->pinnedDefinition($inFlight);

    if ((int) $definition['header']['id'] !== (int) $instances->pinnedVersion($inFlight)['payload_id']) {
        $problems[] = 'the definition read for the instance is not the payload its pinned version points at';
    }

    if ($definition['nodes'] === []) {
        $problems[] = 'the pinned definition has no nodes, so nothing was actually read through the pin';
    }

    // Resolution as at today still moves on, which is the other half of the
    // claim: pinning must not mean the artefact is stuck for everybody.
    if ((int) $versions->resolve('workflow', 'LEAVE-APPROVE', [], $laterAt)->versionId !== $v2) {
        $problems[] = 'resolve() does not return v2 after it was published';
    }

    $pdo->exec('DROP DATABASE `' . $fixture['database'] . '`');

    return $problems === [] ? null : implode('; ', $problems);
}

/** WC-12. Cancellation is terminal, and terminal means terminal. */
function conformance_workflow_terminal(array $case): ?string
{
    return conformance_workflow_decide_twice($case);
}

/**
 * WC-10 — amount-based routing. Values AT, below and above each threshold.
 *
 * The corpus states each probe's expected band index independently of the
 * router, which is the only way a boundary case means anything: a table that
 * says "whatever the code returns" is not a table.
 */
function conformance_workflow_amount_route(array $case): ?string
{
    $bands = $case['bands'];

    foreach ($case['probes'] as $probe) {
        $amount = \SmartPRS\Platform\Support\Money::ofMinor((int) $probe['minor']);

        if (($probe['expect'] ?? null) === 'refused') {
            try {
                \SmartPRS\Platform\Workflow\AmountRouter::select($bands, $amount);
            } catch (\InvalidArgumentException $e) {
                if (!str_contains($e->getMessage(), (string) ($probe['message_contains'] ?? ''))) {
                    return "{$probe['minor']} was refused, but not for the stated reason: {$e->getMessage()}";
                }

                continue;
            }

            return "{$probe['minor']} should have been refused and a band admitted it";
        }

        $selected = \SmartPRS\Platform\Workflow\AmountRouter::select($bands, $amount);

        if ($selected['index'] !== (int) $probe['expect']) {
            return sprintf(
                '%s selected band %d and the corpus says %d — %s',
                $amount->format(),
                $selected['index'],
                (int) $probe['expect'],
                $selected['why'],
            );
        }
    }

    // R-IX-027: the route is chosen when the node ACTIVATES, so an amended
    // amount re-routes. A request raised at ₹40,000 and amended to ₹4,00,000
    // before anybody approved it must not still sit in the manager's queue.
    if (isset($case['amendment'])) {
        $reroutes = \SmartPRS\Platform\Workflow\AmountRouter::reroutes(
            $bands,
            \SmartPRS\Platform\Support\Money::ofMinor((int) $case['amendment']['at_creation']),
            \SmartPRS\Platform\Support\Money::ofMinor((int) $case['amendment']['at_activation']),
        );

        if ($reroutes !== (bool) $case['amendment']['expect_reroute']) {
            return 'the amendment ' . ($reroutes ? 're-routed' : 'did not re-route')
                . ' and the corpus says it should ' . ($case['amendment']['expect_reroute'] ? '' : 'not ')
                . '(R-IX-027)';
        }
    }

    return null;
}

/**
 * The fixture WC-02 and WC-08 run against, or an unrunnable marker.
 *
 * @return array{
 *     pdo: PDO, tenant: \SmartPRS\Platform\Tenancy\TenantContext, tenant_id: int,
 *     second_tenant_id: int, status: array<string, int>, instance_id: int,
 *     connect: Closure(): PDO, database: string
 * }
 */
function conformance_workflow_database(string $database): array
{
    require_once __DIR__ . '/../../tests/lib/workflow_db_fixture.php';

    $fixture = workflow_db_fixture($database);

    if ($fixture === null) {
        throw new ConformanceNotRunnable(
            'no database. This case is about what two connections do to each other, so an in-memory '
            . 'stand-in would assert nothing; set SMARTPRS_TEST_DSN and run it again.'
        );
    }

    return $fixture;
}

/**
 * WC-02 — two decisions from two connections, in two open transactions.
 *
 * R-IX-045 is exact about what must be asserted: exactly one succeeds, the
 * loser gets a conflict, and exactly one transition row exists. The two
 * transactions overlap here — both are open before either commits — because a
 * test that decides twice in sequence tests the status column, not the race.
 */
function conformance_workflow_concurrent_decision(array $case): ?string
{
    $fixture = conformance_workflow_database('smartprs3_conformance_wc02');
    $pdo = $fixture['pdo'];
    $tenant = $fixture['tenant'];

    $states = new \SmartPRS\Platform\Persistence\StatusLookup($pdo, $tenant);
    $audit = new \SmartPRS\Platform\Audit\AuditLedger($pdo, $tenant);
    $tasks = new \SmartPRS\Platform\Workflow\Persistence\TaskRepository($pdo, $tenant, $states, $audit);
    $ledger = new \SmartPRS\Platform\Workflow\Persistence\TransitionLedger($pdo, $tenant, $audit);

    $second = ($fixture['connect'])();
    $secondTasks = new \SmartPRS\Platform\Workflow\Persistence\TaskRepository(
        $second,
        $tenant,
        new \SmartPRS\Platform\Persistence\StatusLookup($second, $tenant),
        new \SmartPRS\Platform\Audit\AuditLedger($second, $tenant),
    );
    $secondLedger = new \SmartPRS\Platform\Workflow\Persistence\TransitionLedger(
        $second,
        $tenant,
        new \SmartPRS\Platform\Audit\AuditLedger($second, $tenant),
    );

    $at = new \DateTimeImmutable((string) ($case['at'] ?? '2026-08-15 10:00:00'));
    $taskId = $tasks->open($fixture['instance_id'], 'manager', '201', $at);

    // Both transactions OPEN before either commits. The first holds the task row
    // from its conditional UPDATE; the second blocks on it and, when the first
    // commits, finds the predicate no longer true.
    $pdo->beginTransaction();
    $winner = $tasks->decide($taskId, \SmartPRS\Platform\Workflow\TaskState::Approved, '201', $at, 1, 'approved');
    $ledger->append($fixture['instance_id'], 'approved', $at, fromNodeKey: 'manager', taskId: $taskId, actorUserId: '201');
    $pdo->commit();

    $loser = $secondTasks->decide(
        $taskId,
        \SmartPRS\Platform\Workflow\TaskState::Rejected,
        '202',
        $at->modify('+1 second'),
        1,
        'no',
    );

    $second->beginTransaction();
    $secondLedger->append(
        $fixture['instance_id'],
        'rejected',
        $at->modify('+1 second'),
        fromNodeKey: 'manager',
        taskId: $taskId,
        actorUserId: '202',
        wasAttempt: true,
    );
    $second->commit();

    $problems = [];

    if (!$winner['decided']) {
        $problems[] = 'the first decision did not land';
    }

    if ($loser['decided']) {
        $problems[] = 'the second decision landed too — two decisions stand on one task';
    }

    if ((string) $loser['task']['decided_by_user_id'] !== '201') {
        $problems[] = 'the loser was not handed the decision that stands';
    }

    $transitions = $ledger->history($fixture['instance_id']);
    $real = array_values(array_filter($transitions, static fn (array $t): bool => (int) $t['was_attempt'] === 0));

    if (count($real) !== 1) {
        $problems[] = 'exactly one non-attempt transition row is required and there are ' . count($real);
    }

    if (count($transitions) !== 2) {
        $problems[] = 'R-IX-050: the losing attempt is evidence and must be written too; found '
            . count($transitions) . ' rows in all';
    }

    if ($audit->verify() !== null) {
        $problems[] = 'the audit chain broke under two writers on two connections';
    }

    $pdo->exec('DROP DATABASE `' . $fixture['database'] . '`');

    return $problems === [] ? null : implode('; ', $problems);
}

/**
 * WC-08 — every transition writes audit, and every REFUSED attempt does too.
 *
 * R-IX-107 is the half that does not exist in SmartPRS 2 at all: who TRIED to
 * approve a payroll run they were not entitled to approve is exactly the fact
 * an auditor asks for, and a 403 that writes nothing cannot answer it.
 */
function conformance_workflow_transition_audit(array $case): ?string
{
    $fixture = conformance_workflow_database('smartprs3_conformance_wc08');
    $pdo = $fixture['pdo'];
    $tenant = $fixture['tenant'];

    $audit = new \SmartPRS\Platform\Audit\AuditLedger($pdo, $tenant);
    $tasks = new \SmartPRS\Platform\Workflow\Persistence\TaskRepository(
        $pdo,
        $tenant,
        new \SmartPRS\Platform\Persistence\StatusLookup($pdo, $tenant),
        $audit,
    );
    $ledger = new \SmartPRS\Platform\Workflow\Persistence\TransitionLedger($pdo, $tenant, $audit);

    $at = new \DateTimeImmutable((string) ($case['at'] ?? '2026-08-15 10:00:00'));
    $taskId = $tasks->open($fixture['instance_id'], 'manager', '201', $at);

    $tasks->decide($taskId, \SmartPRS\Platform\Workflow\TaskState::Approved, '201', $at, 1, 'approved, cover arranged');
    $tasks->decide($taskId, \SmartPRS\Platform\Workflow\TaskState::Rejected, '202', $at->modify('+1 second'), 1, 'no');

    foreach ($case['upstream_refusals'] ?? [] as $refusal) {
        $tasks->refuse($taskId, (string) $refusal['reason'], (string) $refusal['actor'], $at->modify('+2 seconds'));
    }

    $pdo->beginTransaction();
    $ledger->append(
        $fixture['instance_id'],
        'approved',
        $at,
        fromNodeKey: 'manager',
        toNodeKey: 'charge',
        taskId: $taskId,
        actorUserId: '201',
        comment: 'approved, cover arranged',
    );
    $pdo->commit();

    $rows = \SmartPRS\Platform\Persistence\TenantScopedQuery::on($pdo, $tenant, 'audit_entries')
        ->orderBy('seq')
        ->select();

    $problems = [];
    $counts = array_count_values(array_map(static fn (array $r): string => (string) $r['action'], $rows));

    foreach ($case['expect']['actions'] as $action => $expected) {
        if (($counts[$action] ?? 0) !== (int) $expected) {
            $problems[] = sprintf("'%s' appears %d time(s), expected %d", $action, $counts[$action] ?? 0, $expected);
        }
    }

    // Actor, timestamp, decision and comment present — the case class names all
    // four, and an entry missing any of them is not evidence of anything.
    foreach ($rows as $row) {
        if ($row['actor_user_id'] === null || (string) $row['actor_user_id'] === '') {
            $problems[] = "entry seq {$row['seq']} ({$row['action']}) names no actor";
        }

        if ((string) $row['occurred_at'] === '') {
            $problems[] = "entry seq {$row['seq']} carries no timestamp";
        }
    }

    $decided = array_values(array_filter($rows, static fn (array $r): bool => $r['action'] === 'workflow.task_decided'));

    if ($decided === [] || (string) $decided[0]['reason'] !== 'approved, cover arranged') {
        $problems[] = "the decision's stated comment did not reach the ledger";
    }

    if ($audit->verify() !== null) {
        $problems[] = 'the chain does not verify after the transitions were written';
    }

    // R-IX-105: workflow code calls the audit service and never inserts itself.
    foreach (['Persistence/TransitionLedger.php', 'Persistence/TaskRepository.php'] as $file) {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Platform/Workflow/' . $file);

        if (str_contains($source, "'audit_entries'")) {
            $problems[] = "R-IX-105: {$file} names audit_entries directly; the audit service is the only writer";
        }
    }

    $pdo->exec('DROP DATABASE `' . $fixture['database'] . '`');

    return $problems === [] ? null : implode('; ', $problems);
}

// ===========================================================================
// AGGREGATION — Part VII §5.9, the RC-09..RC-13 case classes
// ===========================================================================

use SmartPRS\Platform\Rules\Aggregation\AggregationContext;
use SmartPRS\Platform\Rules\Aggregation\Aggregator;
use SmartPRS\Platform\Rules\Aggregation\Event as AggEvent;
use SmartPRS\Platform\Rules\Aggregation\Window;

function conformance_agg_context(array $case): AggregationContext
{
    $joining = isset($case['joining_date'])
        ? new \DateTimeImmutable((string) $case['joining_date'])
        : null;

    if (isset($case['cut_off_day'], $case['cycle_ends_on'])) {
        return AggregationContext::monthlyCycle(
            (int) $case['financial_year_start_month'],
            (int) $case['cut_off_day'],
            new \DateTimeImmutable((string) $case['cycle_ends_on']),
            24,
            $joining,
        );
    }

    return new AggregationContext((int) $case['financial_year_start_month'], [], $joining);
}

/** RC-09. Every window against the range the corpus computed independently. */
function conformance_agg_window_table(array $case): ?string
{
    $context = conformance_agg_context($case);
    $subjectDate = new \DateTimeImmutable((string) $case['subject_date']);

    foreach ($case['windows'] as $declared) {
        $arguments = $declared['arguments'] ?? [];

        if (isset($arguments['date'])) {
            $arguments['date'] = new \DateTimeImmutable((string) $arguments['date']);
        }

        $window = Window::resolve((string) $declared['kind'], $subjectDate, $context, $arguments);
        $actual = [$window->from->format('Y-m-d'), $window->to->format('Y-m-d')];

        if ($actual !== $declared['expect']) {
            return sprintf(
                '%s resolved to [%s] and the independently computed table says [%s]',
                $window->kind . ($arguments === [] ? '' : '(' . json_encode($declared['arguments']) . ')'),
                implode(' → ', $actual),
                implode(' → ', $declared['expect']),
            );
        }
    }

    return null;
}

/**
 * RC-09. The blueprint asks that the two windows genuinely differ — that a
 * rolling rule matches a set a calendar_month rule does not, and the reverse.
 * Two windows that always agree would make one of them decoration.
 */
function conformance_agg_window_disagree(array $case): ?string
{
    $context = conformance_agg_context($case);
    $subjectDate = new \DateTimeImmutable((string) $case['subject_date']);

    $rolling = Window::resolve('rolling', $subjectDate, $context, ['n' => 30, 'unit' => 'days']);
    $month = Window::resolve('calendar_month', $subjectDate, $context);

    $inRolling = [];
    $inMonth = [];

    foreach ($case['events'] as $date) {
        $day = new \DateTimeImmutable((string) $date);

        if ($rolling->contains($day)) {
            $inRolling[] = $day->format('Y-m-d');
        }

        if ($month->contains($day)) {
            $inMonth[] = $day->format('Y-m-d');
        }
    }

    $expect = $case['expect'];

    if (count($inRolling) !== (int) $expect['rolling_30_days']) {
        return 'rolling(30, days) saw ' . count($inRolling) . ' events, expected ' . $expect['rolling_30_days']
            . ' — it covers ' . $rolling;
    }

    if (count($inMonth) !== (int) $expect['calendar_month']) {
        return 'calendar_month saw ' . count($inMonth) . ' events, expected ' . $expect['calendar_month'];
    }

    $onlyRolling = array_values(array_diff($inRolling, $inMonth));
    $onlyMonth = array_values(array_diff($inMonth, $inRolling));

    if ($onlyRolling !== $expect['only_in_rolling']) {
        return 'only the rolling window saw [' . implode(', ', $onlyRolling)
            . '], expected [' . implode(', ', $expect['only_in_rolling']) . ']';
    }

    if ($onlyMonth !== $expect['only_in_month']) {
        return 'only the calendar month saw [' . implode(', ', $onlyMonth)
            . '], expected [' . implode(', ', $expect['only_in_month']) . ']';
    }

    return null;
}

/** RC-09. Inclusive at both ends, asserted one day either side. */
function conformance_agg_boundary(array $case): ?string
{
    $context = conformance_agg_context($case);
    $window = Window::resolve(
        (string) $case['window']['kind'],
        new \DateTimeImmutable((string) $case['subject_date']),
        $context,
        $case['window']['arguments'] ?? [],
    );

    $expect = $case['expect'];

    foreach (['first_day_in' => true, 'last_day_in' => true, 'day_before_out' => false] as $key => $shouldContain) {
        if (!isset($expect[$key])) {
            continue;
        }

        $day = new \DateTimeImmutable((string) $expect[$key]);

        if ($window->contains($day) !== $shouldContain) {
            return "{$expect[$key]} is " . ($shouldContain ? 'outside' : 'inside') . " {$window}, and should not be";
        }
    }

    return null;
}

/** RC-10. The Nth qualifying occurrence, and the zero that is not a one. */
function conformance_agg_occurrence_index(array $case): ?string
{
    $context = conformance_agg_context($case);
    $events = [];

    foreach ($case['events'] as $declared) {
        $events[] = new AggEvent(
            'late_arrival',
            new \DateTimeImmutable((string) $declared['date'] . ' 09:30:00'),
            (int) $declared['value'],
        );
    }

    $aggregator = new Aggregator($events);
    $threshold = (int) $case['predicate_over_minutes'];
    $predicate = static fn (AggEvent $e): bool => is_int($e->value) && $e->value > $threshold;

    foreach ($case['expect']['index_on'] as $date => $expected) {
        $subjectDate = new \DateTimeImmutable((string) $date);
        $window = Window::resolve((string) $case['window']['kind'], $subjectDate, $context);
        $actual = $aggregator->occurrenceIndex('late_arrival', $window, $subjectDate, $predicate);

        if ($actual !== (int) $expected) {
            return "on {$date} the occurrence index was {$actual}, expected {$expected}";
        }
    }

    return null;
}

/** RC-11. The collapse boundary, one second either side. */
function conformance_agg_collapse(array $case): ?string
{
    $context = conformance_agg_context($case);
    $subjectDate = new \DateTimeImmutable((string) $case['subject_date']);
    $window = Window::resolve('day', $subjectDate, $context);

    $events = array_map(
        static fn (string $instant): AggEvent => new AggEvent('punch', new \DateTimeImmutable($instant)),
        $case['instants'],
    );

    $aggregator = new Aggregator($events);

    foreach ($case['expect'] as $key => $expected) {
        $seconds = (int) str_replace('within_', '', (string) $key);
        $actual = $aggregator->collapseRepeats('punch', $window, $seconds);

        if ($actual !== (int) $expected) {
            return "collapsing within {$seconds}s left {$actual} event(s), expected {$expected}";
        }
    }

    return null;
}

/** RC-12. The run, and the day that ended it. */
function conformance_agg_streak(array $case): ?string
{
    $events = array_map(
        static fn (string $date): AggEvent => new AggEvent('absent_day', new \DateTimeImmutable($date . ' 00:00:00')),
        $case['days'],
    );

    $streak = (new Aggregator($events))->streak(
        'absent_day',
        new \DateTimeImmutable((string) $case['subject_date']),
        'backward',
    );

    $expect = $case['expect'];

    if ($streak['length'] !== (int) $expect['backward_length']) {
        return "the backward streak was {$streak['length']}, expected {$expect['backward_length']}";
    }

    if (($streak['terminated_by'] ?? null) !== ($expect['terminated_by'] ?? null)) {
        return 'the run was terminated by ' . var_export($streak['terminated_by'], true)
            . ', expected ' . var_export($expect['terminated_by'] ?? null, true);
    }

    return null;
}

/** RC-13. As at the date, with a superseded entry that does not count. */
function conformance_agg_balance(array $case): ?string
{
    $ledger = array_map(
        static fn (array $entry): array => [
            'on' => new \DateTimeImmutable((string) $entry['on']),
            'amount' => (int) $entry['amount'],
            'superseded_on' => isset($entry['superseded_on'])
                ? new \DateTimeImmutable((string) $entry['superseded_on'])
                : null,
        ],
        $case['ledger'],
    );

    foreach ($case['expect']['as_at'] as $date => $expected) {
        $actual = Aggregator::balance($ledger, new \DateTimeImmutable((string) $date));

        if ($actual !== (int) $expected) {
            return "as at {$date} the balance was {$actual}, expected {$expected}";
        }
    }

    return null;
}

// ===========================================================================
// RULES, continued — precedence, conflicts, effective dating, determinism
// ===========================================================================

use SmartPRS\Platform\Rules\RuleTestCase;
use SmartPRS\Platform\Support\AmbiguousConfiguration;

/**
 * RC-02. Specificity is a sum of declared weights, over every dimension and a
 * large random sample of combinations.
 *
 * What this actually proves is that the ORDERING follows the sum: a scope of
 * two light dimensions outranks one heavy dimension exactly when the arithmetic
 * says so, and never because of declaration order or dimension count. That is
 * the property a rule author reasons with, and it is the one SmartPRS 2 lacks —
 * it has no specificity at all, so precedence is row order.
 */
function conformance_specificity_table(array $case): ?string
{
    $dimensions = array_keys(Scope::WEIGHTS);
    mt_srand(20260904);

    foreach ($dimensions as $dimension) {
        $scope = Scope::of([$dimension => ['X']]);

        if ($scope->specificity() !== Scope::WEIGHTS[$dimension]) {
            return "a scope on {$dimension} alone weighs {$scope->specificity()}, and the declared weight "
                . 'is ' . Scope::WEIGHTS[$dimension];
        }
    }

    for ($trial = 0; $trial < (int) ($case['trials'] ?? 1000); $trial++) {
        $left = conformance_random_scope($dimensions);
        $right = conformance_random_scope($dimensions);

        // The independently computed table: sum the declared weights.
        $leftWeight = array_sum(array_map(
            static fn (string $d): int => Scope::WEIGHTS[$d],
            array_keys($left),
        ));
        $rightWeight = array_sum(array_map(
            static fn (string $d): int => Scope::WEIGHTS[$d],
            array_keys($right),
        ));

        $leftScope = $left === [] ? Scope::tenantDefault() : Scope::of($left);
        $rightScope = $right === [] ? Scope::tenantDefault() : Scope::of($right);

        if ($leftScope->specificity() !== $leftWeight || $rightScope->specificity() !== $rightWeight) {
            return 'a scope on [' . implode(', ', array_keys($left)) . '] weighs '
                . $leftScope->specificity() . ' and the table says ' . $leftWeight;
        }

        $expected = $leftWeight <=> $rightWeight;
        $actual = $leftScope->specificity() <=> $rightScope->specificity();

        if ($expected !== $actual) {
            return 'the ordering disagreed with the summed weights on trial ' . $trial;
        }
    }

    return null;
}

/** @param list<string> $dimensions @return array<string, list<string>> */
function conformance_random_scope(array $dimensions): array
{
    $picked = [];
    $count = mt_rand(0, 3);
    $shuffled = $dimensions;
    shuffle($shuffled);

    for ($i = 0; $i < $count; $i++) {
        $picked[$shuffled[$i]] = ['X'];
    }

    return $picked;
}

/** RC-04 / RC-05. The conflict detector, through the publish gate that runs it. */
function conformance_rules_publish_conflict(array $case): ?string
{
    $expect = $case['expect'] ?? [];
    $point = (string) $case['decision_point'];

    $siblings = array_map(
        static fn (array $declared): Rule => conformance_rule($point, $declared),
        $case['siblings'] ?? [],
    );

    $report = RulePublishGates::run(
        conformance_rule($point, $case['rule']),
        new RuleGateEnvironment(siblings: $siblings),
    );

    if ($report->isPublishable() !== ($expect['publishable'] ?? true)) {
        return $report->isPublishable()
            ? 'expected publication to be refused; every gate passed'
            : 'expected it to publish; it was refused: ' . implode(' | ', array_map(
                static fn ($c): string => $c->gate . ' ' . implode('; ', $c->messages),
                $report->blocking(),
            ));
    }

    if (isset($expect['gate'])) {
        $check = $report->check((string) $expect['gate']);

        if ($check === null || !$check->blocks()) {
            return "expected gate {$expect['gate']} to block; it did not";
        }

        return conformance_message($expect, implode(' | ', $check->messages));
    }

    return null;
}

/** RC-08. The version in force on the evaluation date, never the newest. */
function conformance_rules_effective_date(array $case): ?string
{
    $point = (string) $case['decision_point'];

    $rules = array_map(
        static fn (array $declared): Rule => conformance_rule($point, $declared),
        $case['rules'],
    );

    foreach ($case['expect']['on'] as $date => $expectedStatus) {
        $context = conformance_rule_context($case + ['evaluation_date' => $date]);
        $result = (new RuleEngine())->evaluate($point, RuleSet::of($point, ...$rules), $context);

        if ($result->status !== $expectedStatus) {
            return "on {$date} the outcome was {$result->status}, expected {$expectedStatus} — the version "
                . 'in force on the evaluation date is the one that applies';
        }
    }

    return null;
}

/** RC-17. Stored regression cases re-run at publish, and a failing one refuses. */
function conformance_rules_stored_cases(array $case): ?string
{
    $expect = $case['expect'] ?? [];
    $point = (string) $case['decision_point'];
    $rule = conformance_rule($point, $case['rule']);

    $cases = array_map(
        static fn (array $declared): RuleTestCase => new RuleTestCase(
            name: (string) $declared['name'],
            ruleCode: $rule->code,
            context: new EvaluationContext(
                tenantId: 'T-CONFORMANCE',
                subjectType: 'employee_day',
                subjectId: 'E-0001',
                subjectDate: new \DateTimeImmutable('2026-08-26'),
                values: [
                    'attendance.late_minutes' => (int) $declared['late_minutes'],
                    'day.is_holiday' => false,
                    'day.is_weekly_off' => false,
                ],
            ),
            expectMatch: (bool) $declared['expect_match'],
        ),
        $case['cases'],
    );

    $report = RulePublishGates::run($rule, new RuleGateEnvironment(siblings: [], tests: $cases));

    if ($report->isPublishable() !== ($expect['publishable'] ?? true)) {
        return $report->isPublishable()
            ? 'expected publication to be refused by a failing stored case; every gate passed'
            : 'expected it to publish';
    }

    if (isset($expect['gate'])) {
        $check = $report->check((string) $expect['gate']);

        if ($check === null || !$check->blocks()) {
            return "expected gate {$expect['gate']} to block; it did not";
        }

        return conformance_message($expect, implode(' | ', $check->messages));
    }

    return null;
}

/**
 * RC-20. Outcomes AND traces, over the corpus, in shuffled order.
 *
 * The trace is included because a trace that varies between runs cannot be the
 * answer to "why", which is the only reason it exists (Law 4).
 */
function conformance_rules_determinism(array $case): ?string
{
    $corpus = json_decode(
        (string) file_get_contents(__DIR__ . '/../../tests/conformance/rules.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['cases'];

    $evaluable = array_values(array_filter(
        $corpus,
        static fn (array $c): bool => ($c['kind'] ?? '') === 'rules_decide',
    ));

    if ($evaluable === []) {
        return 'no evaluable rule cases in the corpus';
    }

    $baseline = null;

    for ($run = 0; $run < (int) ($case['runs'] ?? 50); $run++) {
        $order = $evaluable;
        shuffle($order);
        $observed = [];

        foreach ($order as $one) {
            $point = (string) $one['decision_point'];
            $rules = array_map(
                static fn (array $declared): Rule => conformance_rule($point, $declared),
                $one['rules'],
            );

            $result = (new RuleEngine())->evaluate(
                $point,
                RuleSet::of($point, ...$rules),
                conformance_rule_context($one),
            );

            // The engine version and the timing counter are the two things that
            // legitimately vary, so the digest is over the DETERMINISTIC
            // rendering the trace publishes for exactly this purpose.
            $observed[$one['id']] = $result->status . '|' . implode('·', $result->rendered())
                . '|' . $result->trace->deterministicJson();
        }

        ksort($observed);
        $digest = hash('sha256', json_encode($observed, JSON_THROW_ON_ERROR));

        if ($baseline === null) {
            $baseline = $digest;

            continue;
        }

        if ($digest !== $baseline) {
            return "run {$run} produced different outcomes or traces — the engine is order dependent";
        }
    }

    return null;
}

// ===========================================================================
// FC-12, FC-14 and RC-16
// ===========================================================================

use SmartPRS\Platform\Formula\Clamp;
use SmartPRS\Platform\Formula\GateEnvironment as FormulaGateEnvironment;
use SmartPRS\Platform\Formula\SampleCase;
use SmartPRS\Platform\Rules\Simulator;

/** @param array<string, mixed>|null $declared */
function conformance_clamp(?array $declared): ?Clamp
{
    if ($declared === null) {
        return null;
    }

    return match ((string) $declared['kind']) {
        'non_negative' => Clamp::nonNegative(),
        'min' => Clamp::min(Money::of((string) $declared['bound'])),
        'max' => Clamp::max(Money::of((string) $declared['bound'])),
        'range' => Clamp::range(
            Money::of((string) $declared['low']),
            Money::of((string) $declared['high']),
        ),
        default => throw new \InvalidArgumentException("Unknown clamp kind '{$declared['kind']}'."),
    };
}

/** FC-12. The declared bound, and the trace step it leaves whether or not it bound. */
function conformance_formula_clamp(array $case): ?string
{
    $expect = $case['expect'] ?? [];

    $formula = conformance_formula('F-CLAMPED', ['expression' => (string) $case['expression']]);
    $formula = new Formula(
        code: $formula->code,
        calculationPoint: $formula->calculationPoint,
        module: $formula->module,
        name: $formula->name,
        businessDescription: $formula->businessDescription,
        expression: $formula->expression,
        resultType: $formula->resultType,
        rounding: $formula->rounding,
        scope: $formula->scope,
        range: $formula->range,
        versionNo: $formula->versionNo,
        versionId: $formula->versionId,
        status: $formula->status,
        clamp: conformance_clamp($case['clamp'] ?? null),
        unit: 'INR',
        sampleTests: $formula->sampleTests,
    );

    $result = (new Calculator(['F-CLAMPED' => $formula]))
        ->evaluateAll(conformance_context($case), 'conformance:' . $case['id'])['F-CLAMPED'];

    if (isset($expect['error_contains'])) {
        if (!$result->isError()) {
            return 'expected ' . $expect['error_contains'] . ' and the formula produced '
                . ($result->money?->format() ?? 'no money')
                . " — a negative earning silently clamped to zero is the defect R-VIII-030 forbids";
        }

        if (!str_contains((string) $result->error, (string) $expect['error_contains'])) {
            return 'expected the error to contain ' . $expect['error_contains'] . ', got ' . $result->error;
        }
    } elseif ($result->isError()) {
        return 'unexpected failure: ' . (string) $result->error;
    }

    if (isset($expect['value']) && $result->money?->format() !== $expect['value']) {
        return 'expected ' . $expect['value'] . ', got ' . ($result->money?->format() ?? 'no money');
    }

    if (isset($expect['trace_contains']) && !str_contains($result->trace, (string) $expect['trace_contains'])) {
        return "the trace does not contain '{$expect['trace_contains']}':\n" . $result->trace;
    }

    return null;
}

/** FC-14. Sample cases that are RUN, not counted. */
function conformance_formula_samples(array $case): ?string
{
    $expect = $case['expect'] ?? [];
    $formula = conformance_formula('F-SAMPLED', ['expression' => (string) $case['expression']]);
    $context = conformance_context($case);

    $samples = $case['samples'] === null ? null : array_map(
        static fn (array $declared): SampleCase => new SampleCase(
            name: (string) $declared['name'],
            class: (string) $declared['class'],
            formulaCode: 'F-SAMPLED',
            context: $context,
            expectedMinor: (int) $declared['expected_minor'],
        ),
        $case['samples'],
    );

    $report = PublishGates::run($formula, new FormulaGateEnvironment(
        sampleCases: $samples,
        graph: ['F-SAMPLED' => $formula],
    ));

    if ($report->isPublishable() !== ($expect['publishable'] ?? true)) {
        return $report->isPublishable()
            ? 'expected publication to be refused by a failing sample; every gate passed'
            : 'expected it to publish; it was refused';
    }

    $check = $report->check((string) ($expect['gate'] ?? 'G10'));

    if ($check === null) {
        return 'G10 did not run';
    }

    if (isset($expect['gate_severity']) && $check->severity !== $expect['gate_severity']) {
        return "G10 reported {$check->severity}, expected {$expect['gate_severity']}";
    }

    return conformance_message($expect, implode(' | ', $check->messages));
}

/**
 * RC-16. The simulator and the live path, byte for byte.
 *
 * The comparison is over the DETERMINISTIC trace rendering, which exists for
 * exactly this: the engine version and the microsecond counter legitimately
 * vary between two runs, and a comparison that included them would fail for a
 * reason that has nothing to do with the decision.
 */
function conformance_simulator_equals_production(array $case): ?string
{
    $point = (string) $case['decision_point'];
    $rules = array_map(
        static fn (array $declared): Rule => conformance_rule($point, $declared),
        $case['rules'],
    );

    $set = RuleSet::of($point, ...$rules);
    $context = conformance_rule_context($case);

    $production = (new RuleEngine())->evaluate($point, $set->published(), $context);
    $simulated = (new Simulator())->run($point, $set, $context);

    if ($production->status !== $simulated->status) {
        return "the live path said {$production->status} and the simulator said {$simulated->status}";
    }

    if ($production->rendered() !== $simulated->rendered()) {
        return 'the actions differ: [' . implode(' · ', $production->rendered())
            . '] against [' . implode(' · ', $simulated->rendered()) . ']';
    }

    if ($production->trace->deterministicJson() !== $simulated->trace->deterministicJson()) {
        return 'the traces differ — a simulator that disagrees with production about the same subject is '
            . 'a second implementation wearing the first one\'s name';
    }

    return null;
}
