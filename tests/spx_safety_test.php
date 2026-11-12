<?php

declare(strict_types=1);

use SmartPRS\Platform\Spx\Ast\Codec;
use SmartPRS\Platform\Spx\Ast\Node;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context;
use SmartPRS\Platform\Spx\ErrorCode;
use SmartPRS\Platform\Spx\Evaluator;
use SmartPRS\Platform\Spx\Functions\Catalogue;
use SmartPRS\Platform\Spx\Lexer;
use SmartPRS\Platform\Spx\Parser;
use SmartPRS\Platform\Spx\Sandbox;
use SmartPRS\Platform\Spx\SpxSyntaxError;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Value;

group('SPX sandbox and safety');

$symbols = SymbolTable::catalogue();
$context = new Context(new DateTimeImmutable('2026-08-15', new DateTimeZone('UTC')), [
    'employee.code' => Value::text('E-0001'),
]);

/** Parse, check and evaluate, returning the error code or the rendered value. */
function run(string $source, ?Sandbox $sandbox = null): string
{
    $symbols = SymbolTable::catalogue();
    $compiled = Compiled::parse($source, $symbols);
    $check = $compiled->typeCheck();

    if (!$check->isValid()) {
        return 'PUBLISH ERROR: ' . implode('; ', $check->messages());
    }

    $value = Evaluator::evaluate(
        $compiled,
        new Context(new DateTimeImmutable('2026-08-15', new DateTimeZone('UTC')), [
            'employee.code' => Value::text('E-0001'),
        ]),
        $sandbox,
    );

    return $value->error->value ?? $value->describe();
}

// =============================================================================
// (b) IT CANNOT EXHAUST RESOURCES — Part VII §10.1b, §7.7
// =============================================================================

// --- a node bomb ends in a NAMED failure, not a hang -------------------------

// Wide rather than deep: 400 nodes at depth 3, so it is the NODE budget that
// stops it and not the publish-time depth limit.
$bomb = 'COUNT([' . implode(', ', array_fill(0, 400, '1')) . '])';
same('ERR_BUDGET_NODES', run($bomb, new Sandbox(nodeBudget: 100)), 'a node bomb is stopped by the node budget');
same('400', run($bomb), 'and the same expression evaluates fine inside the default budget');

$counted = new Sandbox(nodeBudget: 100);
run('1 + 2 + 3', $counted);
same(5, $counted->nodesEvaluated(), 'the sandbox counts what it evaluated, so a breach is attributable');

// Short-circuiting means the skipped branch costs no budget at all.
$shortCircuit = new Sandbox();
run('FALSE AND COUNT([' . implode(', ', array_fill(0, 100, '1')) . ']) = 1', $shortCircuit);
ok($shortCircuit->nodesEvaluated() < 10, 'an unevaluated AND branch consumes no node budget');

// --- a deep nest is refused, and refused BEFORE it is descended --------------

$deepAst = str_repeat('NOT ', 40) . 'TRUE';
ok(str_contains(run($deepAst), 'levels deep'), 'an AST deeper than 32 does not publish');

$hostile = str_repeat('(', 3000) . '1' . str_repeat(')', 3000);
$thrown = null;

try {
    Parser::parse($hostile);
} catch (SpxSyntaxError $e) {
    $thrown = $e;
}

ok($thrown instanceof SpxSyntaxError, 'a 5,000-deep parenthesis string is a named parse error, not a stack overflow');
ok(str_contains($thrown->reason ?? '', 'nests deeper than'), 'and the message names the nesting limit');
same(1, $thrown?->sourceLine, 'with a position, like every other parse error');

// --- a time budget --------------------------------------------------------

$expired = new Sandbox(nodeBudget: 100000, millisBudget: 0);
usleep(1000);
same('ERR_BUDGET_TIME', run('COUNT([' . implode(', ', array_fill(0, 200, '1')) . '])', $expired),
    'an expired time budget is a named failure');

// --- catastrophic backtracking is refused at PUBLISH, not survived at runtime -

foreach ([
    '(a+)+$',
    '(a*)*b',
    '(a|aa)+c',
    '(x+x+)+y',
    '([a-zA-Z]+)*z',
] as $evil) {
    ok(Sandbox::lintPattern($evil) !== null, "the catastrophic pattern '{$evil}' is rejected at publish");
    ok(str_contains(run("employee.code MATCHES '" . $evil . "'"), 'PUBLISH ERROR'),
        "and a rule containing '{$evil}' cannot be published");
}

foreach (['[A-Z]{2}-[0-9]{4}', 'E-[0-9]+', 'HYD|BLR|MAA'] as $fine) {
    same(null, Sandbox::lintPattern($fine), "the ordinary pattern '{$fine}' is accepted");
}

ok(Sandbox::lintPattern('(a)\\1') !== null, 'a backreference is rejected');
ok(Sandbox::lintPattern('(?<=x)y') !== null, 'lookbehind is rejected');
ok(Sandbox::lintPattern('(?R)') !== null, 'recursion is rejected');
ok(Sandbox::lintPattern(str_repeat('a', 600)) !== null, 'an over-long pattern is rejected');
ok(Sandbox::lintPattern('[') !== null, 'a pattern that does not compile is rejected');

// The compiler anchors the pattern, so a rule cannot accidentally match a substring.
same('FALSE', run("employee.code MATCHES '[0-9]+'"), 'MATCHES is anchored: a partial match is FALSE');
same('TRUE', run("employee.code MATCHES 'E-[0-9]+'"), 'a whole-string match is TRUE');
same('TRUE', run("employee.code MATCHES '.*'"), 'an anchored .* still matches everything');

// A pattern that slipped past the linter still cannot run away: the step limit
// converts it into a named error.
$evil = Sandbox::matches('(?:a+)+$', str_repeat('a', 60) . 'b');
ok($evil->isError() && $evil->error === ErrorCode::RegexSteps,
    'a catastrophic match that reaches the engine ends in ERR_REGEX_STEPS, not a hang');

$long = Sandbox::matches('.*', str_repeat('x', 600));
ok($long->isError() && $long->error === ErrorCode::Arg, 'a subject beyond 512 characters is refused');

// --- the statically checkable budgets are enforced at publish ----------------

$thrown = null;

try {
    Lexer::tokenise(str_repeat('1 + ', 4000) . '1');
} catch (SpxSyntaxError $e) {
    $thrown = $e;
}

ok($thrown !== null, 'source beyond 8,000 characters is refused at publish');

// =============================================================================
// (a) IT CANNOT EXECUTE ARBITRARY CODE — Part VII §10.1a
//
// The behavioural half: nothing in the grammar names a host function, and the
// catalogue is closed. The structural half is asserted over this source below.
// =============================================================================

foreach ([
    'system',
    'exec',
    'shell_exec',
    'passthru',
    'eval',
    'assert',
    'include',
    'require',
    'file_get_contents',
    'fopen',
    'unlink',
    'curl_exec',
    'unserialize',
    'call_user_func',
    'phpinfo',
    'strlen',
    'DB',
    'app',
] as $host) {
    $result = run("{$host}(employee.code)");
    ok(
        str_contains($result, 'PUBLISH ERROR') && str_contains($result, 'catalogue is closed'),
        "'{$host}()' is not reachable: the catalogue is closed",
    );
}

// There is no syntax that could name a superglobal, a constant, a class, a
// method or a property, so these are parse errors before they are anything else.
foreach ([
    '$_GET',
    '$GLOBALS',
    'employee->code',
    'employee::code',
    'new Money',
    '`ls`',
    'employee.code; DROP TABLE employees',
    '{$x}',
    '<?php echo 1; ?>',
    '__halt_compiler',
] as $attempt) {
    $thrown = null;

    try {
        Parser::parse($attempt);
    } catch (SpxSyntaxError $e) {
        $thrown = $e;
    }

    ok($thrown !== null, "'{$attempt}' has no production in the grammar");
}

// A namespaced path is the ONLY way to read anything, and it cannot name a
// subject: there is no employee('E-0002').salary accessor to write.
$thrown = null;

try {
    Parser::parse("employee('E-0002').salary");
} catch (SpxSyntaxError $e) {
    $thrown = $e;
}

ok($thrown !== null, 'no expression can name another subject: there is no such production');
ok(str_contains(run('tenant.id'), 'Unknown namespace'), 'there is no tenant-visible namespace');
ok(str_contains(run('session.user'), 'Unknown namespace'), 'there is no session namespace');
ok(str_contains(run('request.headers'), 'Unknown symbol'), 'request.* is a declared, typed slice, not the HTTP request');

// =============================================================================
// THE STRUCTURAL ARGUMENT — asserted over this implementation's own source.
//
// A behavioural test can only probe the paths it thinks of. These assertions
// cover the rest: if a future change adds a way out, the grep finds it.
// =============================================================================

/** @return array<string, string> relative path => source */
function spxSources(): array
{
    static $sources = null;

    if ($sources !== null) {
        return $sources;
    }

    $sources = [];

    // Keys are '/'-separated whatever the host uses: every assertion below looks
    // a file up by name ('Sandbox.php', 'Ast/Codec.php'). On Windows the iterator
    // returns backslashes, the prefix never stripped, and the structural half of
    // this suite silently degraded into comparisons against absolute paths and
    // nulls instead of failing loudly.
    $root = str_replace('\\', '/', dirname(__DIR__)) . '/src/Platform/Spx/';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $path = str_replace('\\', '/', $file->getPathname());
            $sources[str_replace($root, '', $path)] = (string) file_get_contents($path);
        }
    }

    ksort($sources);

    return $sources;
}

ok(count(spxSources()) > 20, 'the engine source is found for the structural assertions (' . count(spxSources()) . ' files)');

/**
 * Source with comments and string literals removed, so a probe reads the code
 * and not the prose about the code. (A docblock saying "unserialize() never
 * touches these bytes" must not read as a call to unserialize.)
 *
 * @return array<string, string>
 */
function spxCode(): array
{
    static $code = null;

    if ($code !== null) {
        return $code;
    }

    $code = [];

    foreach (spxSources() as $path => $source) {
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source) ?? $source;
        $code[$path] = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/', "''", $stripped) ?? $stripped;
    }

    return $code;
}

/** @return list<string> the files whose CODE matches, prose excluded */
function spxProbe(string $pattern): array
{
    $found = [];

    foreach (spxCode() as $path => $code) {
        if (preg_match($pattern, $code) === 1) {
            $found[] = $path;
        }
    }

    return $found;
}

// --- no dynamic execution, anywhere in the engine ---------------------------

foreach ([
    'eval' => '/(?<![\w>$:])eval\s*\(/',
    'assert' => '/(?<![\w>$:])assert\s*\(/',
    'create_function' => '/\bcreate_function\s*\(/',
    'unserialize' => '/\bunserialize\s*\(/',
    'call_user_func' => '/\bcall_user_func(_array)?\s*\(/',
    'a `callable` type declaration' => '/(?<![\w])callable\s+\$/',
    'a variable method ->$m()' => '/->\s*\$\w+\s*\(/',
    'a variable class new $c' => '/\bnew\s+\$\w+/',
    'a variable static ::$m()' => '/::\s*\$\w+\s*\(/',
    'variable variables' => '/\$\$\w+/',
    'reflection' => '/\bReflection\w*/',
    'a dynamic class string ::class from data' => '/\$\w+\s*::\s*class/',
] as $what => $probe) {
    same([], spxProbe($probe), "the engine contains no {$what}");
}

// A `$closure()` call exists in a few places, but PHP\Closure cannot hold a
// function NAME — only `callable` can, and there is none (probed above). Assert
// every such call site is a Closure the same file created or was handed.
foreach (spxCode() as $path => $code) {
    preg_match_all('/(?<![\w>$])\$(\w+)\s*\(/', $code, $calls);

    foreach (array_unique($calls[1]) as $name) {
        $declared = preg_match('/Closure\s+\$' . $name . '\b/', $code) === 1
            || preg_match('/\$' . $name . '\s*=\s*(static\s+)?(fn|function)\b/', $code) === 1
            || preg_match('/\$this->' . $name . '\b/', $code) === 1;

        ok($declared, "{$path}: \${$name}() is a Closure, not a function named by data");
    }
}

// --- no I/O of any kind: no filesystem, no network, no shell, no database ----

foreach ([
    'filesystem' => '/\b(file_get_contents|file_put_contents|fopen|fwrite|fread|unlink|scandir|glob|readfile|tmpfile)\s*\(/',
    'include or require' => '/\b(include|include_once|require|require_once)\b/',
    'shell' => '/\b(system|exec|shell_exec|passthru|proc_open|popen)\s*\(/',
    'network' => '/\b(curl_\w+|fsockopen|stream_socket_client|socket_create)\s*\(/',
    'database' => '/\b(PDO|mysqli|DB::|Eloquent)\b|->\s*(query|select|insert|update|delete)\s*\(/',
    'superglobal' => '/\$_(GET|POST|REQUEST|SERVER|ENV|COOKIE|SESSION|FILES)\b/',
    'randomness' => '/\b(rand|mt_rand|random_int|random_bytes|shuffle|uniqid|str_shuffle)\s*\(/',
    'output' => '/(?<![\w>$])(echo|print_r|var_dump|printf|header|error_log|trigger_error)\s*[\(\'"$]/',
    'global' => '/\bglobal\s+\$/',
    'mutable static state' => '/\bstatic\s+\$\w+\s*=/',
    'a backtick shell' => '/`/',
] as $what => $probe) {
    same([], spxProbe($probe), "the engine performs no {$what} access");
}

// --- the clock: the only temporal input is the injected evaluation date ------

foreach ([
    'time()' => '/(?<![\w>$:])(?<!function )time\s*\(\s*\)/',
    'date()' => '/(?<![\w>$:])(?<!function )date\s*\(/',
    'mktime/strtotime' => '/\b(mktime|strtotime)\s*\(/',
    'a no-argument DateTime' => '/new\s+\S*DateTime\w*\s*\(\s*\)/',
    "a 'now' date" => '/[\'"]now[\'"]/i',
    'date_default_timezone' => '/\bdate_default_timezone_(get|set)\b/',
] as $what => $probe) {
    same([], spxProbe($probe), "the engine reads no clock: {$what}");
}

// hrtime and ini_set exist exactly once each, in the sandbox, for the budgets.
same(['Sandbox.php'], spxProbe('/\bhrtime\s*\(/'), 'the only clock reading in the engine is the sandbox deadline');
same(['Sandbox.php'], spxProbe('/\bini_set\s*\(/'), 'the only ini_set in the engine sets the PCRE step limits');

// --- Law 7: no float anywhere in the engine ---------------------------------

foreach (spxCode() as $path => $code) {
    ok(preg_match('/\(\s*(float|double)\s*\)|\bfloatval\s*\(/', $code) !== 1, "{$path} contains no float cast");
    ok(preg_match('/(?<![\w.])\d+\.\d+(?![\w.])/', $code) !== 1, "{$path} contains no floating-point literal");
    ok(preg_match('/[:|(,]\s*\??float\b/', $code) !== 1, "{$path} declares no float parameter or return");
}

// --- every file declares strict types ---------------------------------------

foreach (spxSources() as $path => $source) {
    ok(str_contains($source, 'declare(strict_types=1);'), "{$path} declares strict_types=1");
}

// --- the node dispatch is closed (R-VII-050) --------------------------------

$nodeClasses = [];

foreach (spxSources() as $path => $source) {
    if (str_starts_with($path, 'Ast/') && preg_match('/final readonly class (\w+) extends Node/', $source, $m) === 1) {
        $nodeClasses[] = $m[1];
    }
}

sort($nodeClasses);
same(
    ['AggCall', 'Between', 'Binary', 'CaseExpr', 'FunctionCall', 'ListLiteral', 'Literal', 'PredicateBlock', 'Unary', 'VariableRef', 'WindowSpec'],
    $nodeClasses,
    'the AST node set is the eleven classes the grammar declares',
);

$evaluator = spxSources()['Evaluator.php'];
$checker = spxSources()['TypeChecker.php'];

foreach ($nodeClasses as $class) {
    ok(str_contains($evaluator, "instanceof {$class}"), "the evaluator dispatches {$class} explicitly");
    ok(str_contains($checker, "instanceof {$class}"), "the type checker dispatches {$class} explicitly");
}

ok(str_contains($evaluator, 'Unknown SPX node kind'), 'and an unknown node kind is refused rather than ignored');

// The codec constructs only the node classes it names — it never instantiates a
// class named by the stored payload (§10.1a-4).
ok(preg_match('/new\s+\$/', spxCode()['Ast/Codec.php']) !== 1, 'the AST reader never instantiates a class named by its input');
ok(str_contains(spxSources()['Ast/Codec.php'], 'Unknown AST node kind'), 'and it refuses an unknown node kind');

// =============================================================================
// (c) IT CANNOT READ DATA IT SHOULD NOT — Part VII §10.1c
// =============================================================================

// The evaluator holds a Context, and a Context holds values — no connection, no
// repository, no container, no query builder.
$contextSource = spxSources()['Context.php'];
ok(preg_match('/\b(Repository|Connection|QueryBuilder|Container|Model)\b/', $contextSource) !== 1,
    'the prepared context holds no connection, repository, container or query builder');
ok(str_contains($contextSource, 'final readonly class Context'), 'and it is immutable');

// The decision point's symbol table is the ceiling, checked twice.
$classify = SymbolTable::forNamespaces('attendance', 'shift', 'day', 'employee');
$rule = Compiled::parse('salary.gross > 0', $classify);
ok(!$rule->typeCheck()->isValid(), 'gate 1: a rule reading an undeclared namespace does not publish');

// Gate 2: even if publication were bypassed, there is no binding at runtime.
$sneaked = Compiled::parse('salary.gross > 0', SymbolTable::catalogue())->withSymbols($classify);
$value = Evaluator::evaluate($sneaked, new Context(new DateTimeImmutable('2026-08-15'), [
    'salary.gross' => Value::money(SmartPRS\Platform\Support\Money::of('50000.00')),
]));
ok($value->isError() && $value->error === ErrorCode::Type,
    'gate 2: with the namespace undeclared there is no binding at evaluation either, even with the value present');

// A not-null-required symbol that is null FAILS; it does not quietly not-match.
$strict = SymbolTable::forNamespaces('employee', 'day')->requiringNotNull('employee.joining_date');
$compiled = Compiled::parse('employee.joining_date < day.date', $strict);
ok($compiled->typeCheck()->isValid(), 'a not-null-required rule type-checks');
$value = Evaluator::evaluate($compiled, new Context(new DateTimeImmutable('2026-08-15')));
ok($value->isError() && $value->error === ErrorCode::MissingInput,
    'R-VII-015: a missing not-null-required symbol is ERR_MISSING_INPUT, not a silent non-match');

$lenient = SymbolTable::forNamespaces('employee', 'day');
$value = Evaluator::evaluate(
    Compiled::parse('employee.exit_date < day.date', $lenient),
    new Context(new DateTimeImmutable('2026-08-15')),
);
ok($value->isNull(), 'while an ordinary nullable symbol simply yields UNKNOWN');

// A context supplying the wrong type is ERR_TYPE — a platform bug, surfaced.
$value = Evaluator::evaluate(
    Compiled::parse('salary.gross > MONEY(1, \'INR\')', SymbolTable::catalogue()),
    new Context(new DateTimeImmutable('2026-08-15'), ['salary.gross' => Value::text('a lot')]),
);
ok($value->isError() && $value->error === ErrorCode::Type, 'a mistyped context value is ERR_TYPE, not a coercion');

// =============================================================================
// ERRORS PROPAGATE AND ARE NEVER SWALLOWED (§5.8)
// =============================================================================

same('ERR_DIV_ZERO', run('1 / 0'), 'division by zero is a typed error, never infinity or null or zero');
same('ERR_DIV_ZERO', run('(1 / 0) + 1'), 'an error poisons its containing expression');
same('ERR_DIV_ZERO', run('MIN(1 / 0, 5)'), 'and poisons through a function');
same('ERR_DIV_ZERO', run('(1 / 0) > 1 AND FALSE'), 'an error in a condition is not treated as false');
same('ERR_DIV_ZERO', run('CASE WHEN (1 / 0) > 1 THEN 1 ELSE 2 END'), 'nor absorbed by a CASE');
same('ERR_DIV_ZERO', run('COALESCE(1 / 0, 1)'), 'nor absorbed by COALESCE');
same('ERR_DIV_ZERO', run('IS_NULL(1 / 0)'), 'an error is not a null, so IS_NULL does not absorb it either');
same('ERR_DIV_ZERO', run("BANDS(1 / 0, [0], ['a'])"), 'nor absorbed by BANDS');

ok(ErrorCode::BudgetNodes->abortsEvaluation(), 'a budget breach aborts the whole evaluation');
ok(!ErrorCode::DivZero->abortsEvaluation(), 'an arithmetic error poisons but does not abort');

// Every error code in the model is reachable and named.
foreach (ErrorCode::cases() as $case) {
    ok(str_starts_with($case->value, 'ERR_'), "{$case->name} has a named code an operator can be shown");
}

// =============================================================================
// DETERMINISM (R-VII-019)
// =============================================================================

$compiled = Compiled::parse('context.evaluation_date', SymbolTable::catalogue());
$march = new Context(new DateTimeImmutable('2026-03-31'), [
    'context.evaluation_date' => Value::date(new DateTimeImmutable('2026-03-31', new DateTimeZone('UTC'))),
]);
same('2026-03-31', Evaluator::evaluate($compiled, $march)->describe(),
    'a recalculation of March, run in November, produces March: the only clock is the injected date');

ok(!Catalogue::has('NOW'), 'NOW() does not exist in SPX');
ok(!Catalogue::has('TODAY'), 'neither does TODAY()');
ok(!Catalogue::has('RANDOM'), 'nor RANDOM()');

// The same expression, evaluated twice, gives the same answer and mutates nothing.
$expression = Compiled::parse("salary.basic % 40", SymbolTable::catalogue());
$ctx = new Context(new DateTimeImmutable('2026-08-15'), [
    'salary.basic' => Value::money(SmartPRS\Platform\Support\Money::of('15000.00')),
]);
$first = Evaluator::evaluate($expression, $ctx);
$second = Evaluator::evaluate($expression, $ctx);
same($first->describe(), $second->describe(), 'evaluation is repeatable');
same('₹15,000.00', $ctx->symbol('salary.basic')?->describe(), 'and the context is unchanged afterwards');
same('salary.basic % 40', $expression->source, 'as is the compiled expression');

// The context is a trust boundary between the calling domain service and the
// engine, so a bare PHP value is refused at assembly rather than discovered
// mid-evaluation. Guessing a type for it is the coercion this engine prevents.
throws(
    InvalidArgumentException::class,
    static fn () => new Context(new DateTimeImmutable('2026-08-15'), ['salary.gross' => 50000]),
    'a bare PHP value in the context is refused: it carries no SPX type',
);
throws(
    InvalidArgumentException::class,
    static fn () => (new Context(new DateTimeImmutable('2026-08-15')))->withPrepared(['X' => 'answer']),
    'and so is a bare prepared answer',
);
