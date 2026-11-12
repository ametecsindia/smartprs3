<?php

declare(strict_types=1);

use SmartPRS\Platform\Spx\Ast\Codec;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\SpxSyntaxError;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\Money;

group('SPX compiled artefact');

/**
 * Spine S5: "The stored artefact is the AST plus the source text, so a published
 * rule cannot be re-parsed differently by a later version of the parser."
 */

$symbols = SymbolTable::catalogue()
    ->withAggMetric('comp_off', SmartPRS\Platform\Spx\Types\Type::days());

// A deliberately awkward expression: every node kind, every literal form.
$source = <<<'SPX'
-- the whole node set in one expression
CASE
  WHEN employee.stage IN ['probation', 'confirmed']
       AND NOT (day.is_holiday OR day.is_weekly_off)
       AND attendance.late_minutes BETWEEN 10m AND 120m
       AND employee.code MATCHES 'E-[0-9]+'
       AND employee.code CONTAINS '-'
       AND agg.count(late_arrival, rolling(30, days), { event.value > 10m }) >= 3
  THEN salary.component('BASIC') % 40
  WHEN day.date > #2026-01-01# AND shift.start >= #09:30#
  THEN -MONEY(1500.50, 'INR') + ₹1,50,000.00
  ELSE MIN(salary.gross, ₹1,00,000.00) / 2
END
SPX;

$compiled = Compiled::parse($source, $symbols);
$check = $compiled->typeCheck();

ok($check->isValid(), 'the exercise expression type-checks: ' . implode('; ', $check->messages()));
same('Money', (string) $check->type, 'and it types to Money');

// --- serialise / deserialise, and assert the round trip is identical --------

$json = $compiled->toJson();
$restored = Compiled::fromJson($json, $symbols);

same($json, $restored->toJson(), 'the JSON round trip is byte-identical');
same($compiled->source, $restored->source, 'the source text survives the round trip');
same($compiled->nodeCount(), $restored->nodeCount(), 'the node count survives');
same($compiled->depth(), $restored->depth(), 'the depth survives');
same(Codec::encode($compiled->ast), Codec::encode($restored->ast), 'the encoded ASTs are equal');

// A second round trip changes nothing more: the encoding is a fixed point.
same($json, Compiled::fromJson($restored->toJson(), $symbols)->toJson(), 'and a second round trip is a fixed point');

/** Structural equality of two ASTs, node by node — not just the encoding. */
function astEquals(Node $a, Node $b): bool
{
    if ($a::class !== $b::class || $a->kind() !== $b->kind()
        || $a->line !== $b->line || $a->column !== $b->column
        || $a->fields() !== $b->fields()
    ) {
        return false;
    }

    $left = $a->children();
    $right = $b->children();

    if (count($left) !== count($right)) {
        return false;
    }

    foreach ($left as $index => $child) {
        if (!astEquals($child, $right[$index])) {
            return false;
        }
    }

    return true;
}

ok(astEquals($compiled->ast, $restored->ast), 'the restored AST is structurally identical, node by node, including positions');

// And it still evaluates to the same answer.
$context = (new Context(new DateTimeImmutable('2026-08-15', new DateTimeZone('UTC')), [
    'employee.code' => Value::text('E-0001'),
    'employee.stage' => Value::code('confirmed', 'employment_stage'),
    'day.date' => Value::date(new DateTimeImmutable('2026-08-15', new DateTimeZone('UTC'))),
    'day.is_holiday' => Value::boolean(false),
    'day.is_weekly_off' => Value::boolean(false),
    'attendance.late_minutes' => Value::quantity(SmartPRS\Platform\Spx\Types\TypeKind::Minutes, SmartPRS\Platform\Support\Rational::of(25)),
    "salary.component('BASIC')" => Value::money(Money::of('15000.00')),
    'salary.gross' => Value::money(Money::of('50000.00')),
    'shift.start' => Value::time(9 * 3600 + 30 * 60),
]))->withPrepared([
    // The counter key is derived from the predicate's SHAPE, not its position,
    // so the caller can compute it from the predicate text alone.
    'agg.count(late_arrival,rolling(30,days),{' . substr(hash('sha256', json_encode(
        Codec::encodeCanonical(SmartPRS\Platform\Spx\Parser::parse('event.value > 10m')),
        JSON_THROW_ON_ERROR,
    )), 0, 16) . '})' => Value::number(SmartPRS\Platform\Support\Rational::of(4)),
]);

same('₹6,000.00', Evaluator::evaluate($compiled, $context)->describe(), 'the original evaluates');
same(
    Evaluator::evaluate($compiled, $context)->describe(),
    Evaluator::evaluate($restored, $context)->describe(),
    'and the restored artefact produces the identical answer',
);

// --- every literal form survives exactly ------------------------------------

foreach ([
    '12',
    '0.25',
    "'a string with '' inside'",
    '#2026-08-12#',
    '#09:30#',
    '0.5d',
    '8.5h',
    '90m',
    '45s',
    '₹1,50,000.00',
    'TRUE',
    'FALSE',
    'NULL',
    "['HYD','BLR']",
    '[]',
] as $literal) {
    $one = Compiled::parse($literal, $symbols);
    $two = Compiled::fromJson($one->toJson(), $symbols);

    ok(astEquals($one->ast, $two->ast), "the literal {$literal} survives the round trip exactly");
}

// A decimal survives as a decimal STRING, never as a float: 0.1 must come back
// as 0.1 and not as 0.1000000000000000055511151231257827.
$decimal = Compiled::parse('0.1 + 0.2', $symbols);
ok(str_contains($decimal->toJson(), '"text":"0.1"'), 'a decimal literal is stored as the exact text the author wrote');
same('0.3', Evaluator::evaluate(
    Compiled::fromJson($decimal->toJson(), $symbols),
    new Context(new DateTimeImmutable('2026-08-15')),
)->describe(), 'and 0.1 + 0.2 is exactly 0.3 after a round trip, which it is not in floating point');

// --- the stored form is versioned and read by an explicit reader -------------

$decoded = json_decode($compiled->toJson(), true, 64, JSON_THROW_ON_ERROR);
same(Codec::VERSION, $decoded['codec'], 'the artefact records the AST encoding version');
same(SmartPRS\Platform\Spx\Functions\Catalogue::VERSION, $decoded['catalogue'], 'and the function catalogue version it compiled against');
ok(isset($decoded['source'], $decoded['ast']), 'the artefact is the AST plus the source text');

throws(
    SpxSyntaxError::class,
    static fn () => Compiled::fromArray(['codec' => 99, 'source' => 'x', 'ast' => []], $symbols),
    'an artefact from a newer encoding is refused rather than guessed at',
);

throws(
    SpxSyntaxError::class,
    static fn () => Compiled::fromArray(['codec' => 1, 'source' => 'x'], $symbols),
    'an artefact with no AST is refused',
);

throws(
    SpxSyntaxError::class,
    static fn () => Compiled::fromJson('{"codec":1,"source":"x","ast":{"k":"exec","l":1,"c":1}}', $symbols),
    'an unknown node kind in a stored artefact is refused, never instantiated',
);

throws(
    SpxSyntaxError::class,
    static fn () => Compiled::fromJson('{"codec":1,"source":"x","ast":{"k":"binary","l":1,"c":1,"f":{"operator":"+"}}}', $symbols),
    'a binary node missing its operands is refused',
);

throws(
    SpxSyntaxError::class,
    static fn () => Compiled::fromJson('{"codec":1,"source":"x","ast":{"k":"case","l":1,"c":1,"f":{"branches":5},"n":[]}}', $symbols),
    'a CASE whose branch count does not match its children is refused',
);

// --- the prepared-context contract the rule engine consumes ------------------

$keys = $compiled->preparedKeys();
same(1, count($keys), 'the artefact declares exactly the aggregation it will look up');
ok(str_starts_with($keys[0], 'agg.count(late_arrival,rolling(30,days),{'), 'and the key names the metric, the window and the predicate hash');
same($keys, $restored->preparedKeys(), 'the restored artefact declares the same keys');

$dataBacked = Compiled::parse("LEAVE_UNITS('SL', #2026-08-01#, #2026-08-31#) > 1d", $symbols);
same(
    ["LEAVE_UNITS('SL',#2026-08-01#,#2026-08-31#)"],
    $dataBacked->preparedKeys(),
    'a data-backed function with literal arguments declares its key too',
);

$computed = Compiled::parse("LEAVE_UNITS('SL', period.start, period.end) > 1d", $symbols);
same([], $computed->preparedKeys(), 'and one with computed arguments declares nothing, because its key is not knowable at publish');

// Two identical predicates hash to the same key; a different one does not.
$a = Compiled::parse('agg.count(late_arrival, payroll_period, { event.value > 10 })', $symbols)->preparedKeys();
$b = Compiled::parse('agg.count(late_arrival, payroll_period, { event.value > 10 })', $symbols)->preparedKeys();
$c = Compiled::parse('agg.count(late_arrival, payroll_period, { event.value > 20 })', $symbols)->preparedKeys();

same($a, $b, 'the same predicate hashes to the same counter key');
ok($a !== $c, 'and a different predicate to a different one');

// --- the three signatures the rule engine depends on -------------------------

// Compiled::parse/typeCheck and Evaluator::evaluate are called by name above
// and their exact signatures are checked by reflection below — the type
// system already guarantees they exist; the old method_exists() checks here
// asserted nothing beyond that.

$parse = new ReflectionMethod(Compiled::class, 'parse');
same('string', (string) $parse->getParameters()[0]->getType(), 'parse takes the source text');
same(SymbolTable::class, (string) $parse->getParameters()[1]->getType(), 'and the decision point\'s symbol table');
ok(in_array((string) $parse->getReturnType(), ['self', Compiled::class], true), 'and returns the compiled artefact (PHP 8.5 reports self as the class name)');

$evaluate = new ReflectionMethod(Evaluator::class, 'evaluate');
same(Compiled::class, (string) $evaluate->getParameters()[0]->getType(), 'evaluate takes a compiled expression');
same(Context::class, (string) $evaluate->getParameters()[1]->getType(), 'and a prepared context');
same(Value::class, (string) $evaluate->getReturnType(), 'and returns a Value');
ok($evaluate->getParameters()[2]->isOptional(), 'the sandbox parameter is optional, so the two-argument call stays valid');
