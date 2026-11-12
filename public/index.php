<?php
/**
 * THE FRONT CONTROLLER — a real screen over the real engines.
 *
 *   php -S localhost:8000 -t public          (or point Laragon's docroot here)
 *
 * There is no framework under this and no vendor/ directory. That is not a
 * stopgap dressed up as a virtue: the engines hold no connection, no container
 * and no facade, so nothing they do needs one, and a screen over them is a
 * router and some HTML. When the Laravel skeleton lands, the controllers call
 * the same classes this file calls and this file goes away.
 *
 * Every number below is COMPUTED by src/Platform. Nothing on any page is typed
 * into the HTML. Change an input and the platform recomputes it.
 *
 * Routes are query parameters (`/?p=rules`) rather than paths, so this works
 * under `php -S` with no router script and under Laragon with no rewrite rules.
 */
declare(strict_types=1);

// A dev console over an unauthenticated database. Law 5 fails closed, and the
// cheapest closed door is the only one that exists until identity is wired in.
// Widened from localhost-only to also accept RFC1918 private-LAN addresses, so
// the console is reachable from another machine on the office/home network when
// `php -S 0.0.0.0:PORT` is used -- still fails closed against anything public.
$remote = $_SERVER['REMOTE_ADDR'] ?? '';

$isLocalhost = in_array($remote, ['127.0.0.1', '::1', ''], true);
$isPrivateLan = $remote !== ''
    && filter_var($remote, FILTER_VALIDATE_IP) !== false
    && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false;

if (!$isLocalhost && !$isPrivateLan) {
    http_response_code(403);
    exit('SmartPRS 3 dev console is local-network-only.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'SmartPRS\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $path = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

use SmartPRS\Platform\Audit\AuditLedger;
use SmartPRS\Platform\Config\ConfigVersionRepository;
use SmartPRS\Platform\Formula\Calculator;
use SmartPRS\Platform\Formula\Formula;
use SmartPRS\Platform\Formula\Rounding;
use SmartPRS\Platform\Persistence\StatusLookup;
use SmartPRS\Platform\Rules\Actions\Action;
use SmartPRS\Platform\Rules\DecisionPoints;
use SmartPRS\Platform\Rules\Engine;
use SmartPRS\Platform\Rules\EvaluationContext;
use SmartPRS\Platform\Rules\ImpactPreview;
use SmartPRS\Platform\Rules\GateEnvironment as RuleGateEnvironment;
use SmartPRS\Platform\Rules\PublishGates as RulePublishGates;
use SmartPRS\Platform\Rules\Rule;
use SmartPRS\Platform\Rules\RuleSet;
use SmartPRS\Platform\Rules\SpxExpression;
use SmartPRS\Platform\Spx\Compiled;
use SmartPRS\Platform\Spx\Context as SpxContext;
use SmartPRS\Platform\Spx\SymbolTable;
use SmartPRS\Platform\Spx\Types\Type;
use SmartPRS\Platform\Spx\Value;
use SmartPRS\Platform\Support\AmbiguousConfiguration;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Money;
use SmartPRS\Platform\Support\Rational;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Support\UnresolvedConfiguration;
use SmartPRS\Platform\Tenancy\TenantContext;
use SmartPRS\Platform\Workflow\AmountRouter;
use SmartPRS\Platform\Workflow\Node;
use SmartPRS\Platform\Workflow\NodeType;
use SmartPRS\Platform\Workflow\ParticipantResolver;
use SmartPRS\Platform\Workflow\ParticipantSpec;
use SmartPRS\Platform\Workflow\ResolutionContext;
use SmartPRS\Platform\Workflow\Sla\BusinessCalendar;
use SmartPRS\Platform\Workflow\Sla\Sweep;
use SmartPRS\Platform\Workflow\Sla\Timer as SlaTimer;
use SmartPRS\Platform\Workflow\Sla\TimerSchedule;
use SmartPRS\Platform\Workflow\Transition;
use SmartPRS\Platform\Workflow\Validator;
use SmartPRS\Platform\Workflow\WorkflowDefinition;
use SmartPRS\Platform\Workflow\WorkflowPublisher;
use SmartPRS\Platform\Payroll\PayrollRun;
use SmartPRS\Platform\Payroll\Reconciliation;
use SmartPRS\Platform\Payroll\RunAborted;
use SmartPRS\Platform\Payroll\SalaryComponent;
use SmartPRS\Platform\Attendance\DaySettlement;
use SmartPRS\Platform\Attendance\Punch;
use SmartPRS\Platform\Attendance\ShiftWindow;
use SmartPRS\Platform\Statutory\Applicability;
use SmartPRS\Platform\Statutory\PackStore;
use SmartPRS\Platform\Statutory\PackVerifier;
use SmartPRS\Platform\Statutory\StatutoryEngine;
use SmartPRS\Platform\Compliance\ComplianceItem;
use SmartPRS\Platform\Compliance\ComplianceScore;
use SmartPRS\Platform\Compliance\ComplianceService;
use SmartPRS\Platform\Compliance\ComplianceState;
use SmartPRS\Platform\Compliance\ItemType;
use SmartPRS\Platform\Compliance\MoneyGate;
use SmartPRS\Platform\Api\ErrorCatalogue;
use SmartPRS\Platform\Api\ErrorEnvelope;
use SmartPRS\Platform\Api\OpenApiGenerator;
use SmartPRS\Platform\Api\RouteRegistry;

const PAGES = [
    '' => 'Overview',
    'status' => 'Build status',
    'payroll' => 'Payroll',
    'attendance' => 'Attendance',
    'statutory' => 'Statutory',
    'compliance' => 'Compliance',
    'api' => 'API',
    'spx' => 'Expressions',
    'money' => 'Money',
    'formula' => 'Formulas',
    'rules' => 'Rules',
    'workflow' => 'Workflow',
    'config' => 'Configuration',
];

$page = (string) ($_GET['p'] ?? '');

// THE FRONT DOOR IS THE PRODUCT, NOT THIS FILE.
//
// `http://localhost/SmartPRS3/public/` served this page: a developer console over
// the engines, with hand-typed fixtures on it, localhost-only, 1,871 lines. The
// actual application is one file over — and the first thing anybody opening the
// project saw was a diagnostic tool, which is a poor way to meet a product and an
// actively misleading way to meet this one, because the console's figures are
// examples and the application's are a customer's.
//
// So a bare visit goes to the application. The console keeps every one of its
// pages on `?p=` and remains what it is: the place to look at an engine directly,
// without a tenant, a session or a screen in the way.
if ($page === '' && !isset($_GET['p'])) {
    header('Location: app.php');

    exit;
}

if (!array_key_exists($page, PAGES)) {
    http_response_code(404);
    $page = '';
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input(string $name, string $default): string
{
    $value = $_GET[$name] ?? $default;

    return is_string($value) ? $value : $default;
}

function checked(string $name): bool
{
    // A checkbox is absent when unticked, so an absent form means defaults, and
    // a present one means whatever the user actually ticked.
    return isset($_GET['submitted']) ? isset($_GET[$name]) : false;
}

function decimal(string $name, string $default): string
{
    $raw = trim(input($name, $default));

    return preg_match('/^-?\d+(\.\d+)?$/', $raw) === 1 ? $raw : $default;
}

/** A database connection, or null with the reason. */
function database(?string &$why = null): ?PDO
{
    $dsn = getenv('SMARTPRS_DSN') ?: 'mysql:host=127.0.0.1;charset=utf8mb4';
    $user = getenv('SMARTPRS_USER') ?: 'root';
    $password = getenv('SMARTPRS_PASSWORD') ?: '';
    $database = getenv('SMARTPRS_DB') ?: 'smartprs3';

    try {
        $pdo = new PDO($dsn . ';dbname=' . $database, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $e) {
        $why = $e->getMessage();

        return null;
    }

    return $pdo;
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($page === 'status' && (($_GET['live'] ?? '') !== 'off')): ?>
<meta http-equiv="refresh" content="20">
<?php endif; ?>
<title>SmartPRS 3 — <?= e(PAGES[$page]) ?></title>
<style>
  :root { --ink:#16181d; --dim:#616875; --line:#dfe2e8; --bg:#f6f7f9; --card:#fff;
          --ok:#0a7a4a; --no:#b3261e; --warn:#8a6100; --accent:#1f4fd8; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink);
         font:14px/1.55 ui-sans-serif,system-ui,"Segoe UI",Roboto,sans-serif; }
  header { background:var(--card); border-bottom:1px solid var(--line); padding:14px 22px; }
  header h1 { margin:0; font-size:16px; font-weight:650; letter-spacing:-.01em; }
  header p { margin:2px 0 0; color:var(--dim); font-size:12.5px; }
  nav { display:flex; gap:2px; flex-wrap:wrap; margin-top:11px; }
  nav a { padding:5px 11px; border-radius:6px; text-decoration:none; color:var(--dim); font-size:13px; }
  nav a:hover { background:var(--bg); }
  nav a.on { background:var(--ink); color:#fff; }
  main { max-width:940px; margin:0 auto; padding:22px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px;
          padding:18px 20px; margin-bottom:16px; }
  .card h2 { margin:0 0 3px; font-size:14.5px; font-weight:650; }
  .card h2 + p { margin:0 0 14px; color:var(--dim); font-size:12.5px; }
  form { display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end; margin-bottom:16px; }
  label { display:block; font-size:11.5px; color:var(--dim); margin-bottom:3px;
          text-transform:uppercase; letter-spacing:.04em; }
  input[type=text], input[type=date], select, textarea {
    font:13px ui-monospace,"Cascadia Mono",Menlo,monospace; padding:6px 8px;
    border:1px solid var(--line); border-radius:6px; background:#fff; color:var(--ink); }
  textarea { width:100%; font-size:13px; }
  .check { display:flex; align-items:center; gap:6px; padding-bottom:7px; }
  .check label { margin:0; text-transform:none; letter-spacing:0; font-size:13px; color:var(--ink); }
  button { font:600 13px ui-sans-serif,system-ui,sans-serif; padding:7px 16px; border:0;
           border-radius:6px; background:var(--ink); color:#fff; cursor:pointer; }
  pre { background:#0f1116; color:#e7e9ee; padding:14px 16px; border-radius:8px;
        overflow:auto; font:12.5px/1.5 ui-monospace,"Cascadia Mono",Menlo,monospace; margin:0; }
  table { width:100%; border-collapse:collapse; font-size:13px; }
  th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.04em;
       color:var(--dim); border-bottom:1px solid var(--line); padding:6px 8px 6px 0; font-weight:600; }
  td { padding:6px 8px 6px 0; border-bottom:1px solid var(--line); vertical-align:top; }
  td.n, th.n { text-align:right; font-variant-numeric:tabular-nums;
               font-family:ui-monospace,Menlo,monospace; }
  code { font:12.5px ui-monospace,"Cascadia Mono",Menlo,monospace; background:var(--bg);
         padding:1px 5px; border-radius:4px; }
  .ok { color:var(--ok); font-weight:600; }
  .no { color:var(--no); font-weight:600; }
  .warn { color:var(--warn); font-weight:600; }
  .note { color:var(--dim); font-size:12.5px; margin:12px 0 0; }
  .banner { border-left:3px solid var(--no); background:#fdf3f2; padding:11px 14px;
            border-radius:0 8px 8px 0; margin-bottom:16px; font-size:13px; }
  ul.plain { margin:8px 0 0; padding-left:18px; color:var(--dim); font-size:13px; }
  ul.plain li { margin:3px 0; }
  ul.plain b { color:var(--ink); font-weight:600; }
</style>
</head>
<body>
<header>
  <h1>SmartPRS 3 — engine console</h1>
  <p>Ametecs India · every figure on these pages is computed by <code>src/Platform</code>, not written into the page</p>
  <nav>
<?php foreach (PAGES as $key => $label): ?>
    <a class="<?= $key === $page ? 'on' : '' ?>" href="?p=<?= e($key) ?>"><?= e($label) ?></a>
<?php endforeach; ?>
  </nav>
</header>
<main>
<?php

// =============================================================================

if ($page === '') {
    $db = database($why);
    ?>
  <div class="card">
    <h2>What this is</h2>
    <p>A console over the platform, not the product.</p>
    <p>SmartPRS 3 is being built engine-first: the language, money, rules, formulas,
       workflow and configuration resolution exist and are tested, and the screens
       that people will use are not built yet. This console drives those engines
       directly so their behaviour can be seen rather than described.</p>
    <ul class="plain">
      <li><b>Expressions</b> — type an SPX condition; it is parsed and type-checked as at publish time.</li>
      <li><b>Money</b> — exact paise arithmetic, and what each rounding mode costs.</li>
      <li><b>Formulas</b> — evaluation in dependency order, with the rounding delta shown.</li>
      <li><b>Rules</b> — a decision, with the trace that explains it.</li>
      <li><b>Configuration</b> — which version governs a date, out of the real database.</li>
    </ul>
  </div>

  <div class="card">
    <h2>Database</h2>
    <p>Only the Configuration page needs one.</p>
<?php if ($db === null): ?>
    <p class="no">Not connected.</p>
    <pre><?= e($why) ?></pre>
    <p class="note">Start MySQL, then run <code>php tools/db/migrate.php --fresh</code> from the project
       root. It runs the nine migrations and publishes three versions of one rule through the real
       publisher. There is no <code>artisan</code> in this repository yet, which is why that command
       exists.</p>
<?php else:
    $tables = (int) $db->query(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
    )->fetchColumn();
    $versions = (int) $db->query('SELECT COUNT(*) FROM config_versions')->fetchColumn();
    $checks = (int) $db->query('SELECT COUNT(*) FROM config_publication_checks')->fetchColumn();
?>
    <p class="ok">Connected.</p>
    <table>
      <tr><th>Tables</th><td class="n"><?= $tables ?></td></tr>
      <tr><th>Published config versions</th><td class="n"><?= $versions ?></td></tr>
      <tr><th>Stored publication gate outcomes</th><td class="n"><?= $checks ?></td></tr>
    </table>
    <p class="note">Every one of those gate rows was written by the publisher, whether the
       publication proceeded or was refused. &ldquo;The usual checks were run&rdquo; is not an answer
       about a payslip three years later.</p>
<?php endif; ?>
  </div>

  <div class="card">
    <h2>The rest of the proof</h2>
    <p>What a browser cannot show.</p>
    <table>
      <tr><td><code>php tests/run.php</code></td><td>the whole suite</td></tr>
      <tr><td><code>php tools/gates/gate.php --fix-hint</code></td><td>the seven laws, over <code>src/</code></td></tr>
      <tr><td><code>php tools/gates/self-test.php</code></td><td>proof that each gate fails when it should</td></tr>
      <tr><td><code>php tools/blueprint/compile-worked-rules.php</code></td><td>the blueprint's own rules and formulas, compiled</td></tr>
      <tr><td><code>php tools/demo/walkthrough.php</code></td><td>the same engines, on a terminal</td></tr>
    </table>
  </div>
<?php
}

// =============================================================================

if ($page === 'spx') {
    $source = input('src', "attendance.late_minutes > policy.value('late_grace_min')");
    $symbols = SymbolTable::catalogue();
    ?>
  <div class="card">
    <h2>SPX — an expression is checked before it is allowed to exist</h2>
    <p>The same compiler the rule engine, the formula engine, report filters and workflow branches use.</p>
    <form method="get">
      <input type="hidden" name="p" value="spx">
      <div style="flex:1 1 100%">
        <label for="src">Expression</label>
        <textarea id="src" name="src" rows="3"><?= e($source) ?></textarea>
      </div>
      <button type="submit">Compile</button>
    </form>
<?php
    try {
        $compiled = Compiled::parse($source, $symbols);
        $check = $compiled->typeCheck();
        $prepared = $compiled->preparedKeys();
?>
    <table>
      <tr><th>Parses</th><td class="ok">yes</td></tr>
      <tr><th>Type</th><td>
<?php if ($check->isValid()): ?>
        <span class="ok"><?= e((string) $check->type) ?></span>
<?php else: ?>
        <span class="no">REFUSED</span>
        <ul class="plain"><?php foreach ($check->messages() as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
<?php endif; ?>
      </td></tr>
      <tr><th>AST nodes</th><td class="n"><?= $compiled->nodeCount() ?></td></tr>
      <tr><th>Depth</th><td class="n"><?= $compiled->depth() ?></td></tr>
      <tr><th>Aggregations the caller must prepare</th><td>
        <?= $prepared === [] ? '<span class="ok">none</span>' : e(implode(', ', $prepared)) ?>
      </td></tr>
    </table>
<?php
    } catch (Throwable $e) {
?>
    <div class="banner"><b>Refused at parse.</b><br><?= e($e->getMessage()) ?></div>
<?php
    }
?>
    <p class="note">Try <code>attendance.late_minutes &gt; 10</code>. It is refused: a bare number has no
       unit, and comparing minutes with a number is the coercion this engine exists to prevent. Try
       <code>attendance.late_minutes &gt; 10m</code> and it compiles. The refusal happens at publish, not
       in a payroll run.</p>
  </div>
<?php
}

// =============================================================================

if ($page === 'money') {
    $amount = decimal('amount', '33333.33');
    $ways = max(2, min(12, (int) input('ways', '3')));
    $gross = Money::of($amount);
    $half = Rational::of($gross->minor, 2);
    ?>
  <div class="card">
    <h2>Money is integer paise, and rounding happens once, where it is declared</h2>
    <p>Law 7. No float touches a rupee anywhere in <code>src/</code>, and a gate fails the build if one does.</p>
    <form method="get">
      <input type="hidden" name="p" value="money">
      <div><label for="amount">Amount (₹)</label>
        <input type="text" id="amount" name="amount" value="<?= e($amount) ?>" size="12"></div>
      <div><label for="ways">Split ways</label>
        <input type="text" id="ways" name="ways" value="<?= $ways ?>" size="4"></div>
      <button type="submit">Compute</button>
    </form>

    <table>
      <tr><th>Stored as</th><td class="n"><?= e((string) $gross->minor) ?> paise</td></tr>
      <tr><th>Formatted</th><td class="n"><?= e($gross->format()) ?></td></tr>
      <tr><th>Half of it, exactly</th><td class="n"><?= e($half->numerator . '/' . $half->denominator) ?> paise</td></tr>
    </table>

    <h2 style="margin-top:18px">…and half of it, rounded to the paisa</h2>
    <p>The mode is declared on the formula. There is no default anywhere in SmartPRS 3.</p>
    <table>
      <tr><th>Mode</th><th class="n">Result</th><th class="n">Cost of the boundary</th></tr>
<?php foreach (['half_up', 'half_down', 'half_even', 'floor', 'ceil'] as $mode):
        $rounding = Rounding::of($mode);
        $rounded = Money::ofMinor(
            $rounding->mode->divide($half->numerator, $half->denominator * $rounding->unitInMinor)
                * $rounding->unitInMinor,
        );
        $delta = Rational::of($rounded->minor)->minus($half);
?>
      <tr><td><code><?= e($mode) ?></code></td>
          <td class="n"><?= e($rounded->format()) ?></td>
          <td class="n"><?= e((string) $delta) ?> paise</td></tr>
<?php endforeach; ?>
    </table>

    <h2 style="margin-top:18px">Allocation loses nothing</h2>
    <p><code>ALLOCATE</code> gives the residue to the last share, so the parts always sum to the whole.</p>
<?php
    $parts = $gross->allocate(array_fill(0, $ways, 1));
?>
    <table>
      <tr><th>Split <?= $ways ?> ways</th>
          <td><?= e(implode('  +  ', array_map(static fn (Money $m): string => $m->format(), $parts))) ?></td></tr>
      <tr><th>Sum of the parts</th>
          <td class="n <?= Money::sum(...$parts)->minor === $gross->minor ? 'ok' : 'no' ?>">
            <?= e(Money::sum(...$parts)->format()) ?></td></tr>
    </table>
  </div>
<?php
}

// =============================================================================

if ($page === 'formula') {
    $gross = decimal('gross', '33333.33');
    $basicPct = decimal('pct_a', '50');
    $hraPct = decimal('pct_b', '40');
    $symbols = SymbolTable::catalogue();

    $make = static fn (string $code, string $src, int $sequence, string $why): Formula => new Formula(
        code: $code,
        calculationPoint: 'comp.earning',
        module: 'payroll',
        name: $code,
        businessDescription: $why,
        expression: Compiled::parse($src, $symbols),
        resultType: Type::money(),
        rounding: Rounding::of('half_up'),
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: $code . '-v1',
        status: 'published',
        unit: 'INR',
        sequenceNo: $sequence,
        sampleTests: ['nominal', 'boundary', 'zero'],
    );

    // BASIC is printed LAST on the payslip and must be computed FIRST. SmartPRS 2
    // evaluates in sequence_no order, so reordering a payslip changes the money.
    $basic = $make('F-BASIC', "salary.gross % policy.value('basic_pct_of_gross')", 99,
        'Basic is a declared percentage of gross.');
    $hra = $make('F-HRA', "formula.value('F-BASIC') % policy.value('hra_pct_of_basic')", 1,
        'HRA is a declared percentage of basic, so it depends on basic.');

    $calculator = new Calculator(['F-BASIC' => $basic, 'F-HRA' => $hra]);
    ?>
  <div class="card">
    <h2>Formulas evaluate in dependency order, never in payslip order</h2>
    <p>Two components: HRA is printed first and computed second, because it depends on basic.</p>
    <form method="get">
      <input type="hidden" name="p" value="formula">
      <div><label for="gross">Gross (₹)</label>
        <input type="text" id="gross" name="gross" value="<?= e($gross) ?>" size="12"></div>
      <div><label for="basic">Basic % of gross</label>
        <input type="text" id="pct_a" name="pct_a" value="<?= e($basicPct) ?>" size="6"></div>
      <div><label for="hra">HRA % of basic</label>
        <input type="text" id="pct_b" name="pct_b" value="<?= e($hraPct) ?>" size="6"></div>
      <button type="submit">Run payroll</button>
    </form>

    <table>
      <tr><th>Payslip order (<code>sequence_no</code>)</th><td>F-HRA (1) → F-BASIC (99)</td></tr>
      <tr><th>Evaluation order (topological)</th><td class="ok">
        <?= e(implode(' → ', array_map(static fn (Formula $f): string => $f->code, $calculator->order()))) ?>
      </td></tr>
    </table>
<?php
    $context = new SpxContext(new DateTimeImmutable('2026-08-31'), [
        'salary.gross' => Value::money(Money::of($gross)),
        "policy.value('basic_pct_of_gross')" => Value::quantityOfType(Type::percentage(), Rational::fromDecimalString($basicPct)),
        "policy.value('hra_pct_of_basic')" => Value::quantityOfType(Type::percentage(), Rational::fromDecimalString($hraPct)),
    ]);

    $results = $calculator->evaluateAll($context, 'console:' . $gross . ':' . $basicPct . ':' . $hraPct);
?>
    <h2 style="margin-top:18px">The trace each one produces</h2>
    <p>The exact rational, the declared boundary, and what the boundary cost.</p>
    <pre><?php foreach ($results as $r) {
        echo e(rtrim($r->trace)) . "\n\n";
    } ?></pre>
    <p class="note">Every payslip line keeps this. &ldquo;Why is my HRA ₹6,666.67?&rdquo; is answered from the
       row, not reconstructed by someone reading code.</p>
  </div>
<?php
}

// =============================================================================

if ($page === 'rules') {
    $point = 'attendance.day.penalise';
    $symbols = DecisionPoints::symbols($point);
    $late = (int) decimal('late', '23');
    $grace = (int) decimal('grace', '10');
    $branch = in_array(input('branch', 'HYD'), ['HYD', 'BLR'], true) ? input('branch', 'HYD') : 'HYD';
    $holiday = checked('holiday');
    $weeklyOff = checked('weeklyoff');

    $build = static fn (string $code, string $when, array $scope, string $why): Rule => new Rule(
        code: $code,
        decisionPoint: $point,
        module: 'attendance',
        name: $code,
        businessDescription: $why,
        scope: $scope === [] ? Scope::tenantDefault() : Scope::of($scope),
        priority: 0,
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: strtolower($code) . '-v1',
        status: 'published',
        when: SpxExpression::parse($when, $symbols),
        then: [new Action('APPLY_DAY_PENALTY', [
            'fraction' => Value::quantityOfType(Type::days(), Rational::of(1, 2)),
            'reason' => $why,
        ])],
        except: SpxExpression::parse('day.is_holiday OR day.is_weekly_off', $symbols),
    );

    $set = RuleSet::of(
        $point,
        $build('RL-LATE-STD', "attendance.late_minutes > policy.value('late_grace_min')",
            [], 'A late arrival over the grace costs half a day.'),
        $build('RL-LATE-HYD', "attendance.late_minutes > policy.value('late_grace_min') * 2",
            ['branch' => ['HYD']], 'Hyderabad runs a doubled grace while the flyover work continues.'),
    );
    ?>
  <div class="card">
    <h2>A decision, and the sentence that explains it</h2>
    <p>Two published rules at <code><?= e($point) ?></code>: a tenant default and a Hyderabad variation.</p>
    <form method="get">
      <input type="hidden" name="p" value="rules">
      <input type="hidden" name="submitted" value="1">
      <div><label for="late">Late by (minutes)</label>
        <input type="text" id="late" name="late" value="<?= $late ?>" size="5"></div>
      <div><label for="grace">Grace (minutes)</label>
        <input type="text" id="grace" name="grace" value="<?= $grace ?>" size="5"></div>
      <div><label for="branch">Branch</label>
        <select id="branch" name="branch">
          <option value="HYD"<?= $branch === 'HYD' ? ' selected' : '' ?>>HYD — Hyderabad</option>
          <option value="BLR"<?= $branch === 'BLR' ? ' selected' : '' ?>>BLR — Bengaluru</option>
        </select></div>
      <div class="check"><input type="checkbox" id="holiday" name="holiday" value="1"<?= $holiday ? ' checked' : '' ?>>
        <label for="holiday">Holiday</label></div>
      <div class="check"><input type="checkbox" id="weeklyoff" name="weeklyoff" value="1"<?= $weeklyOff ? ' checked' : '' ?>>
        <label for="weeklyoff">Weekly off</label></div>
      <button type="submit">Decide</button>
    </form>
<?php
    $context = new EvaluationContext(
        tenantId: 'T-1',
        subjectType: 'employee_day',
        subjectId: 'E-4471',
        subjectDate: new DateTimeImmutable('2026-08-26'),
        dimensions: ['branch' => $branch, 'grade' => 'G3', 'department' => 'OPS'],
        values: [
            'attendance.late_minutes' => $late,
            "policy.value('late_grace_min')" => $grace,
            'day.is_holiday' => $holiday,
            'day.is_weekly_off' => $weeklyOff,
        ],
    );

    try {
        $result = (new Engine())->evaluate($point, $set->published(), $context);
?>
    <table>
      <tr><th>Outcome</th>
          <td class="<?= $result->matched() ? 'no' : 'ok' ?>"><?= e($result->status) ?></td></tr>
      <tr><th>Actions</th><td>
        <?= $result->rendered() === [] ? '<span class="ok">none</span>'
            : e(implode(' · ', $result->rendered())) ?></td></tr>
<?php foreach ($result->counters() as $key => $value): ?>
      <tr><th><?= e(str_replace('_', ' ', $key)) ?></th><td class="n"><?= e((string) $value) ?></td></tr>
<?php endforeach; ?>
    </table>

    <h2 style="margin-top:18px">The trace</h2>
    <p>Stored with the decision, so the answer to &ldquo;why&rdquo; is a row, not an investigation.</p>
    <pre><?= e(rtrim($result->trace->render())) ?></pre>
<?php
    } catch (Throwable $e) {
?>
    <div class="banner"><b><?= e($e::class) ?></b><br><?= e($e->getMessage()) ?></div>
<?php
    }
?>
    <p class="note">In HYD the grace is doubled, so 23 minutes with a 10 minute grace is not
       penalised there and is in BLR — the branch-scoped rule wins on specificity, not on
       insertion order. Tick <b>weekly off</b> and the <code>except</code> clause suppresses both.</p>
  </div>

  <div class="card">
    <h2>What publishing these two would check</h2>
    <p>The nine rule gates, run live against each rule and its sibling.</p>
<?php foreach ($set->rules as $rule):
    // Each rule's sibling is the other one, which is what makes R07 a real
    // check here rather than a NOT CHECKED.
    $siblings = array_values(array_filter($set->rules, static fn ($r): bool => $r->code !== $rule->code));
    $report = RulePublishGates::run($rule, new RuleGateEnvironment(
        siblings: $siblings,
        authorUserId: 'u-ejaz',
        approverUserId: 'u-approver',
    ));
?>
    <h2 style="margin-top:16px"><?= e($rule->code) ?>
      <span class="<?= $report->isPublishable() ? 'ok' : 'no' ?>">
        — <?= $report->isPublishable() ? 'publishable' : 'REFUSED' ?></span></h2>
    <p><?= e($rule->businessDescription) ?></p>
    <table>
<?php foreach ($report->checks as $check):
        $class = ['pass' => 'ok', 'block' => 'no'][$check->severity] ?? 'warn';
?>
      <tr>
        <td style="width:3.5rem"><code><?= e($check->gate) ?></code></td>
        <td><?= e($check->name) ?></td>
        <td style="width:4.5rem" class="<?= $class ?>"><?= e($check->severity) ?></td>
        <td><?= e(implode('; ', $check->messages)) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
<?php endforeach; ?>
    <p class="note">Warnings do not block. What matters is that a gate with nothing to read says
       <b>NOT CHECKED</b> instead of <b>pass</b> — a check that silently checks nothing is worse
       than a missing check, because it is evidence.</p>
  </div>

  <div class="card">
    <h2>What publishing the change would actually do</h2>
    <p>&sect;7.9. The proposed version tightens the grace from
       <code><?= $grace ?>m</code> to <code><?= max(1, (int) round($grace / 2)) ?>m</code>, effective
       1 October. Before anybody approves it, this is the exact list of who it changes.</p>
<?php
    $tightened = max(1, (int) round($grace / 2));
    $proposedRule = new Rule(
        code: 'RL-LATE-STD',
        decisionPoint: $point,
        module: 'attendance',
        name: 'RL-LATE-STD',
        businessDescription: 'The grace is tightened; a late arrival over it costs half a day.',
        scope: Scope::tenantDefault(),
        priority: 0,
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-10-01')),
        versionNo: 2,
        versionId: 'rl-late-std-v2',
        status: 'published',
        when: SpxExpression::parse("attendance.late_minutes > {$tightened}m", $symbols),
        then: [new Action('APPLY_DAY_PENALTY', [
            'fraction' => Value::quantityOfType(Type::days(), Rational::of(1, 2)),
            'reason' => 'A late arrival over the tightened grace costs half a day.',
        ])],
        except: SpxExpression::parse('day.is_holiday OR day.is_weekly_off', $symbols),
    );

    // A small population standing in for the tenant. Every one of them is
    // evaluated: the preview never samples, because a count extrapolated from a
    // sample is an estimate an approver will read as a fact.
    $population = [];

    foreach ([3, 8, 11, 14, 19, 22, 26, 31, 38, 45, 52, 61] as $index => $lateMinutes) {
        $population[] = new EvaluationContext(
            tenantId: 'T1',
            subjectType: 'employee_day',
            subjectId: sprintf('E-%03d', $index + 1),
            subjectDate: new DateTimeImmutable('2026-10-01'),
            dimensions: ['branch' => $branch],
            values: [
                'attendance.late_minutes' => $lateMinutes,
                'day.date' => '2026-10-01',
                'day.is_holiday' => false,
                'day.is_weekly_off' => false,
                "policy.value('late_grace_min')" => $grace,
            ],
        );
    }

    $report = ImpactPreview::between(
        $point,
        $set,
        RuleSet::of($point, $proposedRule),
        $population,
        new DateTimeImmutable('2026-10-01'),
        authorSubjectId: 'E-004',
    );
?>
    <table>
      <tr><th>Subject</th><th>Late by</th><th>Today</th><th>Under the proposed version</th><th></th></tr>
<?php
    // Both columns come from the REPORT, not from re-deriving the rule in the
    // template. A page that recomputed the comparison would be a second
    // implementation of it, and the first time the two disagreed the screen
    // would be the one an approver believed.
    foreach ($population as $subject):
        $lateMinutes = (int) $subject->values['attendance.late_minutes'];
        $changes = in_array($subject->subjectId, $report->changedSubjectIds, true);
        $outcome = $report->outcomeOf($subject->subjectId) ?? ['before' => '?', 'after' => '?'];
?>
      <tr>
        <td><code><?= e($subject->subjectId) ?></code></td>
        <td class="n"><?= $lateMinutes ?>m</td>
        <td><?= e($outcome['before']) ?></td>
        <td><?= e($outcome['after']) ?></td>
        <td class="<?= $changes ? 'no' : 'ok' ?>"><?= $changes ? 'CHANGES' : 'same' ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <pre><?= e($report->render()) ?></pre>
    <p class="note">Three things this refuses to do, each of which is how a preview stops being
       exact. It never <b>samples</b> &mdash; the count says what it counted over, so
       &ldquo;<?= $report->changedCount() ?> of <?= $report->subjectCount ?>&rdquo; cannot be read as
       an estimate. It evaluates as at the version&rsquo;s <b>effective date</b>, not today: previewed
       in September this same change looks harmless, because the rule is not in force yet. And a
       subject whose evaluation <b>fails</b> counts as changed and is named, rather than being folded
       into &ldquo;unchanged&rdquo; &mdash; a preview reporting &ldquo;12 affected&rdquo; for a
       version that breaks evaluation for four hundred people is the same fail-open as everywhere
       else, wearing the costume of a summary statistic.
       <?= $report->requiresApproval() ? 'Approval is required here.' : '' ?></p>
  </div>
<?php
}

// =============================================================================

if ($page === 'workflow') {
    $requester = input('requester', 'u-ejaz');
    $subject = input('subject', 'u-priya');

    $node = static fn (string $key, NodeType $type, array $extra = []): Node => new Node(...([
        'key' => $key,
        'type' => $type,
        'label' => ucfirst(str_replace('_', ' ', $key)),
    ] + $extra));

    $definition = new WorkflowDefinition(
        key: 'LEAVE-APPROVE',
        name: 'Leave approval',
        businessDescription: "A leave application is approved by the requester's manager before it charges.",
        subjectEntityKey: 'leave_request',
        startNodeKey: 'raise',
        nodes: [
            $node('raise', NodeType::Submit),
            $node('manager', NodeType::Approval, [
                'participants' => [new ParticipantSpec('reporting_line', ['level_n' => 1])],
                'slaMinutes' => 2880,
            ]),
            $node('amend', NodeType::ReturnForEdit, ['participants' => [new ParticipantSpec('requester')]]),
            $node('charge', NodeType::Finalise, ['params' => ['completion_event_key' => 'leave.charged']]),
            $node('refuse', NodeType::Reject, ['params' => ['reason_required' => true]]),
        ],
        transitions: [
            new Transition('raise', 'submitted', 'manager'),
            new Transition('raise', 'blocked', 'refuse'),
            new Transition('manager', 'approved', 'charge'),
            new Transition('manager', 'rejected', 'refuse'),
            new Transition('manager', 'returned', 'amend'),
            new Transition('manager', 'escalated', 'manager'),
            new Transition('manager', 'auto_decided', 'charge'),
            new Transition('amend', 'returned', 'amend'),
            new Transition('amend', 'resubmitted', 'manager'),
            new Transition('amend', 'exhausted', 'refuse'),
            new Transition('amend', 'abandoned', 'refuse'),
        ],
        scope: Scope::tenantDefault(),
        range: EffectiveRange::openEndedFrom(new DateTimeImmutable('2026-04-01')),
        versionNo: 1,
        versionId: 'leave-approve-v1',
        status: 'published',
    );

    $report = Validator::validate($definition);
    ?>
  <div class="card">
    <h2><?= e($definition->name) ?> — the graph as published</h2>
    <p><?= e($definition->businessDescription) ?></p>
    <table>
      <tr><th>Node</th><th>Type</th><th>Participants</th><th>SLA</th><th>Outcomes it can produce</th></tr>
<?php foreach ($definition->nodes as $n): ?>
      <tr>
        <td><code><?= e($n->key) ?></code><?= $n->key === $definition->startNodeKey ? ' <span class="ok">start</span>' : '' ?></td>
        <td><?= e($n->type->value) ?><?= $n->type->isTerminal() ? ' <span class="no">terminal</span>' : '' ?></td>
        <td><?= $n->participants === [] ? '—' : e(implode(', ', array_map(
            static fn (ParticipantSpec $p): string => $p->kind, $n->participants))) ?></td>
        <td class="n"><?= $n->slaMinutes === null ? '—' : e((string) $n->slaMinutes) . 'm' ?></td>
        <td><?= e(implode(', ', $n->type->outcomes())) ?: '—' ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p class="note">Every outcome a node type can produce must have a transition, or W03 blocks. That
       is why <code>manager</code> declares five: an approval that can escalate but has nowhere to
       escalate to is how an approval sits in a queue forever.</p>
  </div>

  <div class="card">
    <h2>The eighteen publish checks</h2>
    <p>Run live on the definition above. <span class="<?= $report->isPublishable() ? 'ok' : 'no' ?>">
      <?= $report->isPublishable() ? 'Publishable' : 'REFUSED' ?></span>.</p>
    <table>
      <tr><th>Check</th><th>Name</th><th>Outcome</th><th>Message</th></tr>
<?php foreach ($report->checks as $check):
    $class = ['pass' => 'ok', 'block' => 'no'][$check->severity] ?? 'warn';
?>
      <tr>
        <td style="width:3.5rem"><code><?= e($check->gate) ?></code></td>
        <td><?= e($check->name) ?></td>
        <td style="width:4.5rem" class="<?= $class ?>"><?= e($check->severity) ?></td>
        <td><?= e(implode('; ', $check->messages)) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p class="note">Checksum over the whole graph:
       <code><?= e(substr(WorkflowPublisher::checksum($definition), 0, 32)) ?>…</code> — swap the
       approver for a notifier and it changes, which a hash over the header alone would not.</p>
  </div>

  <div class="card">
    <h2>Who it goes to, and who it must not go to</h2>
    <p>§IX.5.4. The exclusions are part of resolution, not a check somebody remembers to write.</p>
    <form method="get">
      <input type="hidden" name="p" value="workflow">
      <div><label for="requester">Requester</label>
        <input type="text" id="requester" name="requester" value="<?= e($requester) ?>" size="12"></div>
      <div><label for="subject">Request is about</label>
        <input type="text" id="subject" name="subject" value="<?= e($subject) ?>" size="12"></div>
      <button type="submit">Resolve</button>
    </form>
<?php
    $approval = new Node(
        key: 'manager',
        type: NodeType::Approval,
        label: 'Manager approval',
        participants: [new ParticipantSpec('role_in_scope', [
            'role_key' => 'MGR',
            'holders' => ['u-ejaz', 'u-priya', 'u-anil'],
        ])],
    );

    // In the application this closure is the repository query. Here the holders
    // are declared on the spec, so the page needs no database — the exclusion
    // logic being demonstrated is the resolver's, either way.
    $resolver = new ParticipantResolver(
        static fn (ParticipantSpec $spec, ResolutionContext $c): array => (array) ($spec->params['holders'] ?? []),
    );

    $resolution = $resolver->resolve($approval, new ResolutionContext(
        new DateTimeImmutable('2026-08-26'),
        requesterUserId: $requester,
        subjectEmployeeUserId: $subject,
    ));
?>
    <pre><?= e(rtrim($resolution->render())) ?></pre>
    <p class="note">Set both fields to the same person and the pool empties — an approval nobody may
       take is a named condition the instance fails on, not a task that silently sits unassigned.
       <code>u-anil</code> stays eligible in every case, because he is neither the requester nor the
       subject.</p>
  </div>

<?php
    $raisedAt = input('raised', '2026-08-28 16:00');

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raisedAt) !== 1) {
        $raisedAt = '2026-08-28 16:00';
    }

    $sla = max(1, min(100_000, (int) decimal('sla', '480')));
    $ist = new DateTimeZone('Asia/Kolkata');

    // 15 August is a Saturday in 2026 and 2 October a Friday, so only one of
    // these two national holidays actually moves a deadline. Both are declared,
    // because a calendar is what the tenant configures, not what happens to
    // matter this month.
    $calendar = BusinessCalendar::weekdays(
        'HYD-OFFICE',
        $ist,
        '09:30',
        '18:00',
        holidays: ['2026-08-15', '2026-08-31', '2026-10-02'],
        breaks: [['start' => '13:00', 'end' => '13:45']],
    );

    $activated = new DateTimeImmutable($raisedAt, $ist);

    $slaNode = new Node(
        key: 'manager',
        type: NodeType::Approval,
        label: 'Manager approval',
        participants: [new ParticipantSpec('reporting_line', ['level_n' => 1])],
        slaMinutes: $sla,
        reminderSchedule: [-120, 1440],
        escalationPolicy: 'notify_only',
        escalationTarget: 'u-head',
    );

    $schedule = TimerSchedule::forNode($slaNode, $calendar, $activated, taskId: '5501');
    $dueTimer = array_values(array_filter($schedule, static fn (SlaTimer $t): bool => $t->kind === 'due'))[0];
?>
  <div class="card">
    <h2>When it is due, and what gets written</h2>
    <p>Mon–Fri 09:30–18:00 Asia/Kolkata, 45 minutes for lunch, three declared holidays.</p>
    <form method="get">
      <input type="hidden" name="p" value="workflow">
      <div><label for="raised">Raised at (IST)</label>
        <input type="text" id="raised" name="raised" value="<?= e($raisedAt) ?>" size="17"></div>
      <div><label for="sla">SLA (working minutes)</label>
        <input type="text" id="sla" name="sla" value="<?= $sla ?>" size="7"></div>
      <button type="submit">Schedule</button>
    </form>

    <table>
      <tr><th>Raised</th><td><?= e($activated->format('D d M Y, H:i')) ?>
        <?= $calendar->isWorking($activated) ? '<span class="ok">office open</span>'
            : '<span class="no">office shut</span>' ?></td></tr>
      <tr><th>Due (<?= $sla ?> working minutes later)</th>
        <td class="ok"><?= e($dueTimer->fireAt->setTimezone($ist)->format('D d M Y, H:i')) ?></td></tr>
      <tr><th>Calendar time that actually spans</th>
        <td class="n"><?= e(number_format(
            ($dueTimer->fireAt->getTimestamp() - $activated->getTimestamp()) / 60,
        )) ?> minutes</td></tr>
    </table>

    <h2 style="margin-top:18px">The durable rows that activation writes</h2>
    <p>§IX.7.3. Not an in-memory schedule — an in-memory schedule is lost on restart, and what is
       lost is invisible.</p>
    <table>
      <tr><th>Kind</th><th>Fires</th><th>Offset</th><th>Idempotency key</th></tr>
<?php foreach ($schedule as $timer): ?>
      <tr>
        <td><code><?= e($timer->kind) ?><?= $timer->sequenceNo > 0 ? ' #' . $timer->sequenceNo : '' ?></code></td>
        <td><?= e($timer->fireAt->setTimezone($ist)->format('D d M Y, H:i')) ?></td>
        <td class="n"><?= $timer->offsetMinutes === null ? '—'
            : ($timer->offsetMinutes > 0 ? '+' : '') . e((string) $timer->offsetMinutes) . 'm' ?></td>
        <td><code><?= e($timer->idempotencyKey()) ?></code></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p class="note">Try <code>2026-08-28 16:00</code> — a Friday afternoon. Eight working hours later
       is Tuesday, because the weekend does not count and the Monday is a declared holiday. The
       negative reminder offset lands on a working morning rather than at 3am on a Sunday.</p>
  </div>

  <div class="card">
    <h2>And what the sweep does about it</h2>
    <p>§IX.7.6. The same timer, decided twice: once by a sweep running on time, once by a sweep
       catching up after an outage.</p>
<?php
    $autoNode = new Node(
        key: 'finance',
        type: NodeType::Approval,
        label: 'Finance approval',
        participants: [new ParticipantSpec('role_in_scope', ['role_key' => 'FIN'])],
        slaMinutes: $sla,
        escalationPolicy: 'auto_approve',
        staleThresholdMinutes: 15,
    );

    $escalation = new SlaTimer('escalation', $dueTimer->fireAt, 'finance', '5501');
?>
    <table>
      <tr><th>Sweep runs</th><th>Late by</th><th>Action</th><th>Why</th></tr>
<?php foreach ([2 => 'on time', 360 => 'after a six-hour outage'] as $lateBy => $label):
    $decision = Sweep::decide($escalation, $autoNode, $dueTimer->fireAt->modify("+{$lateBy} minutes"));
?>
      <tr>
        <td><?= e($label) ?></td>
        <td class="n"><?= e((string) $decision->latenessMinutes) ?>m</td>
        <td class="<?= $decision->isSuppressed() ? 'no' : 'ok' ?>"><?= e($decision->action) ?></td>
        <td><?= e($decision->reason) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p class="note">This is the one place where &ldquo;the job caught up&rdquo; is the dangerous
       outcome. A scheduler outage followed by a catch-up sweep must never silently approve a
       backlog of payments, so past the declared threshold the automatic decision is withheld, the
       instance is blocked with reason <code>stale_timer</code> and an operator is alerted
       (R-IX-066). Note that the breach itself is still recorded either way — reporting on breaches
       must not depend on having configured an escalation.</p>
  </div>

  <div class="card">
    <h2>Which approver the amount chooses</h2>
    <p>§IX.9.1. Type an amount in rupees. <code>from</code> is inclusive, <code>to</code> is
       exclusive, and every boundary is an integer number of paise (R-IX-077).</p>
<?php
    $bands = [
        ['from' => null, 'to' => 5_000_000, 'who' => 'Reporting manager'],
        ['from' => 5_000_000, 'to' => 50_000_000, 'who' => 'Director'],
        ['from' => 50_000_000, 'to' => null, 'who' => 'Board'],
    ];

    $amountRupees = decimal('amount', '50000.00');
    $amount = Money::of($amountRupees);
    $selected = AmountRouter::select($bands, $amount);
    $amended = Money::of(decimal('amended', '400000.00'));
?>
    <form method="get">
      <input type="hidden" name="p" value="workflow">
      <input type="hidden" name="raised" value="<?= e($raisedAt) ?>">
      <input type="hidden" name="sla" value="<?= $sla ?>">
      <div><label for="amount">Amount raised (₹)</label>
        <input type="text" id="amount" name="amount" value="<?= e($amountRupees) ?>" size="12"></div>
      <div><label for="amended">Amended to (₹)</label>
        <input type="text" id="amended" name="amended" value="<?= e(decimal('amended', '400000.00')) ?>" size="12"></div>
      <div><button type="submit">Route it</button></div>
    </form>
    <table>
      <tr><th>Band</th><th>From (inclusive)</th><th>To (exclusive)</th><th>Approver</th><th></th></tr>
<?php foreach ($bands as $index => $band): ?>
      <tr>
        <td class="n"><?= $index ?></td>
        <td class="n"><?= $band['from'] === null ? '—' : e(Money::ofMinor($band['from'])->format()) ?></td>
        <td class="n"><?= $band['to'] === null ? '—' : e(Money::ofMinor($band['to'])->format()) ?></td>
        <td><?= e($band['who']) ?></td>
        <td class="<?= $index === $selected['index'] ? 'ok' : '' ?>">
            <?= $index === $selected['index'] ? 'selected' : '' ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p><?= e($amount->format()) ?> goes to the <b><?= e($selected['band']['who']) ?></b> —
       <?= e($selected['why']) ?>.</p>
<?php $reroutes = AmountRouter::reroutes($bands, $amount, $amended); ?>
    <p class="note">R-IX-027: the band is chosen when the node <em>activates</em>, not when the
       instance is created. Amended from <?= e($amount->format()) ?> to <?= e($amended->format()) ?>,
       this request <b class="<?= $reroutes ? 'no' : 'ok' ?>"><?= $reroutes ? 're-routes' : 'stays put' ?></b>
       — <?= e(AmountRouter::select($bands, $amended)['band']['who']) ?> decides it. A request raised
       below a threshold and amended above it must not still be sitting in the manager&rsquo;s
       queue. SmartPRS 2 compares loan instalments as floats, and its scheme controller carries
       explicit <code>0.005</code> epsilons — the code acknowledging that its own comparisons are
       unreliable.</p>
  </div>
<?php
}

// =============================================================================

if ($page === 'config') {
    $db = database($why);
    $asOf = input('asof', '2026-08-15');
    $branch = in_array(input('branch', 'HYD'), ['HYD', 'BLR'], true) ? input('branch', 'HYD') : 'HYD';

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) !== 1) {
        $asOf = '2026-08-15';
    }
    ?>
  <div class="card">
    <h2>Which version governed that date</h2>
    <p><code>resolve(type, key, scope, as_of)</code> — the one configuration read every engine makes.</p>
<?php if ($db === null): ?>
    <div class="banner"><b>No database.</b><br><?= e($why) ?></div>
    <p class="note">Run <code>php tools/db/migrate.php --fresh</code> from the project root, then
       reload this page.</p>
<?php else:
    $tenant = new TenantContext();
    $tenant->establish(1);
    $repository = new ConfigVersionRepository($db, $tenant, new StatusLookup($db, $tenant));
    $date = new DateTimeImmutable($asOf);
    $dimensions = ['branch' => $branch, 'grade' => 'G3'];
?>
    <form method="get">
      <input type="hidden" name="p" value="config">
      <div><label for="asof">As at</label>
        <input type="date" id="asof" name="asof" value="<?= e($asOf) ?>"></div>
      <div><label for="branch">Branch</label>
        <select id="branch" name="branch">
          <option value="HYD"<?= $branch === 'HYD' ? ' selected' : '' ?>>HYD — Hyderabad</option>
          <option value="BLR"<?= $branch === 'BLR' ? ' selected' : '' ?>>BLR — Bengaluru</option>
        </select></div>
      <button type="submit">Resolve</button>
    </form>
<?php
    $candidates = $repository->candidates('rule', 'LATE-PENALTY', $date);
?>
    <table>
      <tr><th>In force on <?= e($asOf) ?></th><th>Scope</th><th class="n">Specificity</th><th>Effective</th></tr>
<?php if ($candidates === []): ?>
      <tr><td colspan="4" class="no">nothing is in force on this date</td></tr>
<?php endif; ?>
<?php foreach ($candidates as $candidate): ?>
      <tr>
        <td>v<?= e((string) $candidate->versionNo) ?></td>
        <td><?= e($candidate->scope->isTenantDefault() ? 'tenant default' : (string) $candidate->scope) ?></td>
        <td class="n"><?= e((string) $candidate->scope->specificity()) ?></td>
        <td><?= e((string) $candidate->range) ?></td>
      </tr>
<?php endforeach; ?>
    </table>

    <h2 style="margin-top:18px">Resolved, for branch <?= e($branch) ?></h2>
<?php
    try {
        $resolved = $repository->resolve('rule', 'LATE-PENALTY', $dimensions, $date);
        $payload = $repository->payload($resolved);
?>
    <table>
      <tr><th>Version</th><td class="ok">v<?= e((string) $resolved->versionNo) ?>
          — <?= e($resolved->scope->isTenantDefault() ? 'tenant default' : (string) $resolved->scope) ?></td></tr>
      <tr><th>Why it exists</th><td><?= e((string) ($payload['business_description'] ?? '')) ?></td></tr>
      <tr><th>Condition</th><td><code><?= e((string) ($payload['when_src'] ?? '')) ?></code></td></tr>
      <tr><th>Money-affecting</th><td><?= ($payload['is_money_affecting'] ?? 0) ? 'yes' : 'no' ?></td></tr>
    </table>
<?php
    } catch (UnresolvedConfiguration | AmbiguousConfiguration $e) {
?>
    <div class="banner"><b><?= e((new ReflectionClass($e))->getShortName()) ?></b><br><?= e($e->getMessage()) ?></div>
<?php
    }

    $gates = $db->query(
        'SELECT v.version_no, c.gate_code, c.gate_name, c.severity, c.messages_json
         FROM config_publication_checks c
         JOIN config_versions v ON v.id = c.config_version_id
         ORDER BY v.version_no, c.gate_code'
    )->fetchAll(PDO::FETCH_ASSOC);
?>
    <h2 style="margin-top:18px">What the publisher checked, and stored</h2>
    <p><?= count($gates) ?> outcome<?= count($gates) === 1 ? '' : 's' ?>, written at publication time.</p>
    <table>
      <tr><th>Version</th><th>Gate</th><th>Name</th><th>Outcome</th><th>Message</th></tr>
<?php foreach ($gates as $gate):
        $class = ['pass' => 'ok', 'block' => 'no'][$gate['severity']] ?? 'warn';
        $messages = json_decode((string) $gate['messages_json'], true) ?: [];
?>
      <tr>
        <td>v<?= e((string) $gate['version_no']) ?></td>
        <td><code><?= e($gate['gate_code']) ?></code></td>
        <td><?= e($gate['gate_name']) ?></td>
        <td class="<?= $class ?>"><?= e($gate['severity']) ?></td>
        <td><?= e(implode('; ', array_map('strval', (array) $messages))) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p class="note">A gate that could not check anything says so — the <b>NOT CHECKED</b> warnings
       above are not failures, they are the gates saying what they were not given. A check that
       silently checks nothing is worse than a missing check, because it is evidence.</p>

<?php
    $entries = $db->query(
        'SELECT seq, occurred_at, action, entity_id, actor_user_id, reason, prev_hash, hash
         FROM audit_entries ORDER BY seq'
    )->fetchAll(PDO::FETCH_ASSOC);
    $broken = (new AuditLedger($db, $tenant))->verify();
?>
    <h2 style="margin-top:18px">And who did it — the ledger</h2>
    <p>Law 5. Append-only, hash-chained per tenant.
      <span class="<?= $broken === null ? 'ok' : 'no' ?>">
        <?= $broken === null ? 'Chain intact.' : 'CHAIN BROKEN at seq ' . e((string) $broken['seq']) . '.' ?></span></p>
    <table>
      <tr><th class="n">Seq</th><th>When</th><th>Action</th><th>Actor</th><th>Reason</th><th>Links to</th></tr>
<?php foreach ($entries as $entry): ?>
      <tr>
        <td class="n"><?= e((string) $entry['seq']) ?></td>
        <td><?= e((string) $entry['occurred_at']) ?></td>
        <td><code><?= e((string) $entry['action']) ?></code></td>
        <td><?= e((string) $entry['actor_user_id']) ?></td>
        <td><?= e((string) $entry['reason']) ?></td>
        <td><code><?= $entry['prev_hash'] === null
            ? 'genesis' : e(substr((string) $entry['prev_hash'], 0, 12)) . '…' ?></code></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p class="note">Each entry's hash covers the previous one, so editing, reordering, inserting or
       deleting any row breaks the chain from that point and <code>verify()</code> names the seq.
       Try it: <code>UPDATE audit_entries SET reason = 'x' WHERE seq = 2</code>, then reload this
       page. Put it back and the chain verifies again.</p>
<?php endif; ?>
  </div>
<?php
}

// =============================================================================
// BUILD STATUS — what exists, measured now, not written into the page.
// =============================================================================

if ($page === 'status') {
    $root = dirname(__DIR__);

    /** Run a verification tool and report what it said. */
    $verify = static function (string $command) use ($root): array {
        $output = [];
        $status = 0;
        @exec('cd ' . escapeshellarg($root) . ' && ' . $command . ' 2>&1', $output, $status);
        $last = '';

        foreach (array_reverse($output) as $line) {
            $plain = trim((string) preg_replace('/\033\[[0-9;]*m/', '', $line));

            if (preg_match('/^(PASS|FAIL|BLOCKED|\d+ assertions)/', $plain) === 1) {
                $last = $plain;

                break;
            }
        }

        return ['ok' => $status === 0, 'summary' => $last ?: 'no summary line'];
    };

    $srcFiles = 0;
    $srcLines = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $srcFiles++;
            $srcLines += count(file($file->getPathname()) ?: []);
        }
    }
    ?>
  <div class="card">
    <h2>What is built, as measured right now</h2>
    <p>Every number on this page is counted or executed when the page loads. Nothing here is typed
       into the HTML — if a gate starts failing, this page turns red without anyone editing it.</p>
    <table>
      <tr><th>Platform source</th><td class="n"><?= number_format($srcFiles) ?> files ·
          <?= number_format($srcLines) ?> lines</td></tr>
      <tr><th>Test files</th><td class="n"><?= count(glob($root . '/tests/*_test.php') ?: []) ?></td></tr>
      <tr><th>Verification tools</th><td class="n"><?= count(glob($root . '/tools/*/*.php') ?: []) ?></td></tr>
    </table>
  </div>

  <div class="card">
    <h2>Verification — run on page load</h2>
    <table>
      <tr><th>Check</th><th>Result</th></tr>
<?php
    foreach ([
        'The test suite' => 'php tests/run.php',
        'Enforcement gates (13)' => 'php tools/gates/gate.php',
        'Gate self-test' => 'php tools/gates/self-test.php',
        'Engine conformance' => 'php tools/conformance/run.php',
        '/api/v3 contract' => 'php tools/api/contract-gate.php',
        'OpenAPI contract gates' => 'php tools/api/openapi-gate.php',
        'Webhook engine' => 'php tools/api/webhook-gate.php',
    ] as $label => $command):
        $result = $verify($command);
?>
      <tr>
        <td><?= e($label) ?></td>
        <td class="<?= $result['ok'] ? 'ok' : 'no' ?>"><?= e($result['summary']) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>Phase gates — Part XXXII</h2>
    <p>Eleven phases. A gate that says <span class="warn">BLOCKED</span> is not failing: it is waiting
       on a decision only a person can make, and the difference matters.</p>
    <table>
      <tr><th class="n">#</th><th>Phase</th><th>Gate</th></tr>
<?php
    foreach ([
        0 => 'Forensic analysis and specification',
        1 => 'Platform foundation',
        2 => 'The engines',
        3 => 'People',
        4 => 'Time and absence',
        5 => 'Compensation and money movement',
        6 => 'India statutory framework',
        7 => 'Talent, compliance and the collections pack',
        8 => 'Reports, dashboards and automation',
        9 => 'Delivery, deployment and operations',
        10 => 'Migration, parallel run and cutover',
    ] as $number => $title):
        if (!is_file($root . "/tools/phase{$number}/exit-gate.php")) {
            continue;
        }

        $gate = $verify("php tools/phase{$number}/exit-gate.php");
        $blocked = str_contains($gate['summary'], 'NOT PROVEN')
            || str_starts_with($gate['summary'], 'BLOCKED');
?>
      <tr>
        <td class="n"><?= $number ?></td>
        <td><?= e($title) ?></td>
        <td class="<?= $gate['ok'] ? 'ok' : ($blocked ? 'warn' : 'no') ?>">
          <?= e(mb_strimwidth($gate['summary'], 0, 90, '…')) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
  </div>

  <div class="card">
<?php
    $beatPath = $root . '/docs/heartbeat.json';
    $beat = is_file($beatPath)
        ? json_decode((string) file_get_contents($beatPath), true) : null;
?>
<?php if (is_array($beat)):
    $beatAge = time() - strtotime((string) $beat['at']);
    $live = $beatAge < 1800;
?>
    <h2>Right now
      <span class="<?= $live ? 'ok' : 'warn' ?>" style="font-weight:600">
        · <?= $live ? 'ACTIVE' : 'idle' ?></span></h2>
    <p style="margin:0 0 6px"><b><?= e((string) $beat['doing']) ?></b></p>
    <p class="note" style="margin:0">Last build activity
      <?= $beatAge < 90 ? 'moments ago'
          : ($beatAge < 5400 ? (int) ($beatAge / 60) . ' minutes ago'
             : (int) ($beatAge / 3600) . ' hours ago') ?>
      · <?= e(date('D j M, H:i', strtotime((string) $beat['at']))) ?>.
      A heartbeat that has gone quiet is a legitimate answer, and a more useful one than a page
      that looks identical whether the build is running, paused or broken.</p>
<?php else: ?>
    <h2>Right now</h2>
    <p class="note" style="margin:0">No heartbeat recorded yet.</p>
<?php endif; ?>
  </div>

  <div class="card">
    <h2>Recent activity — what changed, most recent first</h2>
<?php
    $changed = [];

    foreach (['src', 'tools', 'tests', 'packs', 'public'] as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir)) as $f) {
            if ($f->isFile() && in_array($f->getExtension(), ['php', 'json'], true)) {
                $changed[str_replace($root . '/', '', $f->getPathname())] = $f->getMTime();
            }
        }
    }

    arsort($changed);
?>
    <table>
      <tr><th>File</th><th>Last written</th></tr>
<?php foreach (array_slice($changed, 0, 15, true) as $file => $when): ?>
      <tr>
        <td><code><?= e($file) ?></code></td>
        <td class="note" style="margin:0"><?= e(date('D j M, H:i', $when)) ?>
          <?php $age = time() - $when; ?>
          <?= $age < 3600 ? '<span class="ok">· ' . (int) ($age / 60) . 'm ago</span>'
              : ($age < 86400 ? '· ' . (int) ($age / 3600) . 'h ago' : '') ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p class="note"><?= count($changed) ?> source, tool and test files in total. This page refreshes
       every 20 seconds — <a href="?p=status&amp;live=off">turn that off</a> if it gets in the way.</p>
  </div>

  <div class="card">
    <h2>The build journal</h2>
    <p>What was claimed, newest first. The gates above are what is <i>true</i> — the two are kept
       separate on purpose: a journal entry is somebody saying they built a thing, and a passing gate
       is the build saying so itself.</p>
    <table>
      <tr><th>When</th><th>What</th></tr>
<?php
    $journalPath = $root . '/docs/build-journal.json';
    $journal = is_file($journalPath)
        ? json_decode((string) file_get_contents($journalPath), true) : [];

    foreach (array_reverse((array) $journal) as $entry):
?>
      <tr>
        <td class="note" style="margin:0;white-space:nowrap"><?= e((string) $entry['at']) ?></td>
        <td><b><?= e((string) $entry['title']) ?></b><br>
          <span class="note"><?= e((string) $entry['detail']) ?></span></td>
      </tr>
<?php endforeach; ?>
    </table>
  </div>

  <div class="card">
    <h2>What this is not</h2>
    <div class="banner">
      <b>This console is not the product.</b> There is no login, no employee record you can create,
      no screen a payroll officer would use. What exists is the engine layer — the part that computes
      a payslip, resolves a rule as at a past date, refuses an unsigned statutory pack — and the
      enforcement toolchain that keeps it honest. The screens are not built.
    </div>
    <p class="note">Run <code>php tools/programme/traceability.php</code> for the honest coverage
       figure against the blueprint's 3,313 requirements, and
       <code>php tools/programme/status.php</code> for the whole programme on one screen.</p>
  </div>
<?php
}

// =============================================================================
// PAYROLL — a real payslip, computed here, from your numbers.
// =============================================================================

if ($page === 'payroll') {
    $gross = decimal('gross', '50000.00');
    $payable = (int) input('payable', '22');
    $periodDays = (int) input('period_days', '24');
    $loan = decimal('loan', '0.00');

    $components = [
        new SalaryComponent('C-BASIC', SalaryComponent::EARNING, SalaryComponent::PERCENT_OF_GROSS,
            ['prorates' => true, 'pf_base' => true], ratePercent: 50),
        new SalaryComponent('C-HRA', SalaryComponent::EARNING, SalaryComponent::PERCENT_OF,
            ['prorates' => true], ofComponent: 'C-BASIC', ratePercent: 40),
        new SalaryComponent('C-SPECIAL', SalaryComponent::EARNING, SalaryComponent::BALANCE,
            ['prorates' => true]),
        new SalaryComponent('D-PT', SalaryComponent::DEDUCTION, SalaryComponent::FLAT,
            ['prorates' => false], flatAmount: '200.00'),
        new SalaryComponent('D-LOAN', SalaryComponent::RECOVERY, SalaryComponent::SCHEDULE,
            ['prorates' => false]),
    ];
    ?>
  <div class="card">
    <h2>Run a payslip</h2>
    <form method="get">
      <input type="hidden" name="p" value="payroll">
      <div><label for="gross">Contracted gross (₹)</label>
        <input type="text" id="gross" name="gross" value="<?= e($gross) ?>" size="12"></div>
      <div><label for="payable">Payable days</label>
        <input type="text" id="payable" name="payable" value="<?= e((string) $payable) ?>" size="4"></div>
      <div><label for="period_days">Days in period</label>
        <input type="text" id="period_days" name="period_days"
               value="<?= e((string) $periodDays) ?>" size="4"></div>
      <div><label for="loan">Loan instalment (₹)</label>
        <input type="text" id="loan" name="loan" value="<?= e($loan) ?>" size="10"></div>
      <button type="submit">Compute</button>
    </form>
  </div>
<?php
    try {
        $recoveries = Money::of($loan)->isZero() ? [] : ['D-LOAN' => Money::of($loan)];
        $run = new PayrollRun('2026-09', $components, [[
            'employment_id' => 'E-1',
            'gross' => Money::of($gross),
            'payable_days' => $payable,
            'period_days' => $periodDays,
            'recoveries' => $recoveries,
        ]]);
        $slip = $run->payslips[0];
?>
  <div class="card">
    <h2>Payslip · E-1 · 2026-09</h2>
    <table>
      <tr><th>Component</th><th>Kind</th><th class="n">Amount</th><th>How it was computed</th></tr>
<?php foreach ($slip->lines as $line): ?>
      <tr>
        <td><code><?= e($line->componentCode) ?></code></td>
        <td><?= e($line->kind) ?></td>
        <td class="n"><?= e($line->amount->format()) ?></td>
        <td class="note" style="margin:0"><?= e($line->trace) ?></td>
      </tr>
<?php endforeach; ?>
      <tr><th colspan="2">Gross earnings</th>
          <td class="n"><b><?= e($slip->grossEarnings()->format()) ?></b></td><td></td></tr>
      <tr><th colspan="2">Total deductions</th>
          <td class="n"><b><?= e($slip->totalDeductions()->format()) ?></b></td><td></td></tr>
      <tr><th colspan="2">NET PAY</th>
          <td class="n <?= $slip->netPay()->isNegative() ? 'no' : 'ok' ?>">
            <b><?= e($slip->netPay()->format()) ?></b></td>
          <td class="note" style="margin:0"><?= $slip->netPay()->isNegative()
            ? 'Negative, and STATED rather than clamped to zero: the month&rsquo;s fixed deductions '
              . 'exceeded a prorated salary. Hiding it behind a zero would destroy the evidence and '
              . 'break the payslip&rsquo;s own arithmetic.'
            : '' ?></td></tr>
    </table>
    <p class="note"><b>The gross is prorated ONCE, then split.</b> Splitting first and prorating each
       part rounds three times: ₹50,000 at 22/24 becomes 22,916.67 + 9,166.67 + 13,750.00 =
       <b>45,833.34</b>, a paisa more than the employee is owed, on a payslip whose own lines do not
       sum to its own gross. The balance component absorbs the residue by subtraction instead.</p>
  </div>

  <div class="card">
    <h2>The reconciliation assertion</h2>
    <p>A run-level check in exact integer paise. Try one paisa of drift and watch it abort.</p>
<?php
        $totals = $run->totals();
        $perturbed = [$slip->withPerturbation('C-BASIC', 1)];

        try {
            Reconciliation::assertOrAbort($perturbed, $totals);
            echo '<p class="no">The perturbation did not abort the run.</p>';
        } catch (RunAborted $aborted) {
?>
    <table>
      <tr><th>Check</th><th>Expected</th><th>Actual</th><th>Difference</th></tr>
<?php foreach ($aborted->failures as $failure): ?>
      <tr><td><code><?= e($failure['check']) ?></code></td>
          <td class="n"><?= e($failure['expected']) ?></td>
          <td class="n"><?= e($failure['actual']) ?></td>
          <td class="n no"><?= e($failure['difference']) ?></td></tr>
<?php endforeach; ?>
    </table>
    <p class="note">One paisa injected into <code>C-BASIC</code>, and the run stops — no payslip is
       issued. There is no tolerance, because an error that is always under the tolerance is an error
       that is always there. SmartPRS 2 has no reconciliation check of any kind.</p>
<?php
        }
?>
  </div>
<?php
    } catch (\Throwable $e) {
?>
  <div class="card"><h2>Refused</h2>
    <p class="no"><?= e($e->getMessage()) ?></p>
    <p class="note">The engine refused rather than producing a number nobody could explain.</p></div>
<?php
    }
}

// =============================================================================
// ATTENDANCE — one night, settled.
// =============================================================================

if ($page === 'attendance') {
    $date = input('date', '2026-09-30');
    $start = input('start', '22:00');
    $end = input('end', '06:00');
    $in = input('in', '2026-09-30 22:07');
    $out = input('out', '2026-10-01 06:22');
    ?>
  <div class="card">
    <h2>Settle an attendance day</h2>
    <p>The default is a night shift that crosses midnight — the case SmartPRS 2 gets structurally
       wrong, splitting one night across two payroll months.</p>
    <form method="get">
      <input type="hidden" name="p" value="attendance">
      <div><label for="date">Attendance date</label>
        <input type="text" id="date" name="date" value="<?= e($date) ?>" size="11"></div>
      <div><label for="start">Shift start</label>
        <input type="text" id="start" name="start" value="<?= e($start) ?>" size="6"></div>
      <div><label for="end">Shift end</label>
        <input type="text" id="end" name="end" value="<?= e($end) ?>" size="6"></div>
      <div><label for="in">IN punch</label>
        <input type="text" id="in" name="in" value="<?= e($in) ?>" size="18"></div>
      <div><label for="out">OUT punch</label>
        <input type="text" id="out" name="out" value="<?= e($out) ?>" size="18"></div>
      <button type="submit">Settle</button>
    </form>
  </div>
<?php
    try {
        $shiftStart = new DateTimeImmutable("{$date} {$start}");
        $shiftEnd = new DateTimeImmutable("{$date} {$end}");

        if ($shiftEnd <= $shiftStart) {
            $shiftEnd = $shiftEnd->modify('+1 day');
        }

        $window = new ShiftWindow('GEN', new DateTimeImmutable($date), $shiftStart, $shiftEnd,
            graceMinutes: 5);

        $settled = DaySettlement::settle($window, 'E-1', [
            new Punch('E-1', new DateTimeImmutable($in), Punch::IN),
            new Punch('E-1', new DateTimeImmutable($out), Punch::OUT),
        ]);
?>
  <div class="card">
    <h2>The day</h2>
    <table>
      <tr><th>Attendance date</th><td><?= e($settled->attendanceDate->format('D, j M Y')) ?></td></tr>
      <tr><th>Crosses midnight</th>
          <td class="<?= $window->crossesMidnight() ? 'warn' : '' ?>">
            <?= $window->crossesMidnight() ? 'yes — and it is ONE day' : 'no' ?></td></tr>
      <tr><th>Worked</th><td class="n"><?= $settled->workedMinutes ?> min</td></tr>
      <tr><th>Late</th><td class="n"><?= $settled->lateMinutes ?> min</td></tr>
      <tr><th>Early exit</th><td class="n"><?= $settled->earlyExitMinutes ?> min</td></tr>
      <tr><th>Overtime</th><td class="n"><?= $settled->overtimeMinutes ?> min</td></tr>
      <tr><th>Shortfall</th><td class="n"><?= $settled->shortfallMinutes ?> min</td></tr>
      <tr><th>Needs regularisation</th>
          <td class="<?= $settled->needsRegularisation() ? 'warn' : 'ok' ?>">
            <?= $settled->needsRegularisation() ? 'yes' : 'no' ?></td></tr>
    </table>
    <p class="note"><b>The attendance day is a window, labelled by the date the shift STARTED.</b>
       Every figure is arithmetic on absolute instants anchored to that start, never
       minutes-since-midnight — which is why a 00:15 arrival on a 22:00 shift is 135 minutes late
       here, where <code>max(0, 15 − 1320)</code> is zero and the same punctual arrival is
       simultaneously charged eight hours of early exit.</p>
  </div>
<?php
    } catch (\Throwable $e) {
        echo '<div class="card"><h2>Refused</h2><p class="no">' . e($e->getMessage()) . '</p></div>';
    }
}

// =============================================================================
// STATUTORY — a signed pack, and a figure that can explain itself.
// =============================================================================

if ($page === 'statutory') {
    require_once dirname(__DIR__) . '/tools/phase6/synthetic-pack.php';

    $base = decimal('base', '30000.00');
    $asOf = input('as_of', '2026-11-30');
    ?>
  <div class="card">
    <h2>A statutory figure, with its snapshot</h2>
    <div class="banner">
      <b>The pack below is SYNTHETIC.</b> Jurisdiction <code>IN-ZZ</code> does not exist and every
      value in it is invented. No real statutory content exists in this build, and none will until a
      named compliance owner is appointed — because a rate attributed to nobody is a rate somebody
      typed. The machinery that will refuse an unattributed pack is finished; the content is not
      started.
    </div>
    <form method="get">
      <input type="hidden" name="p" value="statutory">
      <div><label for="base">Wage base (₹)</label>
        <input type="text" id="base" name="base" value="<?= e($base) ?>" size="12"></div>
      <div><label for="as_of">As at</label>
        <input type="text" id="as_of" name="as_of" value="<?= e($asOf) ?>" size="11"></div>
      <button type="submit">Compute</button>
    </form>
  </div>
<?php
    try {
        $synthetic = syntheticPack();
        $verifier = new PackVerifier($synthetic['keys'], '3.2.0');
        $store = new PackStore($verifier);
        $store->put($synthetic['pack']);
        $store->put(syntheticPack(['version' => '2026.2', 'effective_from' => '2027-01-01',
            'content' => (static function (array $c): array {
                $c['slab_tables']['SYNTH-BANDS']['bands'][2]['rate'] = '8';

                return $c;
            })($synthetic['content'])])['pack']);

        // THE HEAD COMES FROM THE PACK, not from this file. It was typed here as
        // a literal and the phase 5 exit gate had been failing on it for exactly
        // the right reason: a component code in code is law 1's business
        // literal, and this page's whole argument is that the pack carries the
        // law and the code carries none of it. Naming the head here quietly
        // contradicted the page it is printed on.
        $head = (string) $synthetic['content']['rules'][0]['head'];

        $applicability = new Applicability();
        $applicability->declare($head, 'EST-1', 'IN-ZZ-SYNTH', new DateTimeImmutable('2026-04-01'),
            true, 'declared covered from 1 April 2026', 'registration certificate on file');

        $row = (new StatutoryEngine($store, $applicability))->compute($head, 'E-1', 'EST-1',
            new DateTimeImmutable($asOf), ['CMP-A' => Money::of($base)],
            ['CMP-A' => ['is_synth_base' => true]]);
?>
  <div class="card">
    <h2>What the payslip carries</h2>
    <pre style="white-space:pre-wrap;margin:0;font:12.5px ui-monospace,Menlo,monospace"><?=
      e($row->explain()) ?></pre>
    <p class="note">Every one of those facts travels WITH the figure: the pack version, its content
       hash, the signing key, the rule code, the wage-base flag, which components composed the base,
       the rounding and the source reference. That is what lets an auditor re-derive the number in
       2031 without the database.</p>
  </div>

  <div class="card">
    <h2>An amendment reaches this install with no deployment</h2>
    <p>Version 2026.2 changes a band and takes effect on 1 January 2027. Same running code, no
       migration, no restart.</p>
    <table>
      <tr><th>As at</th><th>Pack version</th><th class="n">Figure</th></tr>
<?php
        foreach (['2026-11-30' => 'before the amendment', '2027-02-28' => 'after it takes effect']
            as $probe => $label) {
            $probed = (new StatutoryEngine($store, $applicability))->compute($head, 'E-1', 'EST-1',
                new DateTimeImmutable($probe), ['CMP-A' => Money::of($base)],
                ['CMP-A' => ['is_synth_base' => true]]);
?>
      <tr><td><?= e($probe) ?> <span class="note"><?= e($label) ?></span></td>
          <td><code><?= e($probed->packVersion) ?></code></td>
          <td class="n"><?= e($probed->amount->format()) ?></td></tr>
<?php } ?>
    </table>
    <p class="note">November keeps the version that was in force in November. A pack published later
       must not reprice a period already paid — resolution is by the statutory effective date, never
       by which pack was installed last.</p>
  </div>
<?php
    } catch (\Throwable $e) {
        echo '<div class="card"><h2>Refused</h2><p class="no">' . e($e->getMessage()) . '</p></div>';
    }
}

// =============================================================================
// COMPLIANCE — the three absent-record states, and the money gate.
// =============================================================================

if ($page === 'compliance') {
    $asOf = new DateTimeImmutable(input('as_of', '2026-09-30'));

    $type = new ItemType(
        code: 'DRA-CERT',
        name: 'Recovery agent certification',
        validStateCodes: [ComplianceState::VERIFIED],
        appliesExpression: "person.role_group == 'field_agent'",
        appliesReasonTemplate: 'the subject is not in the field-agent set, so this is not required',
        evidenceClass: ItemType::REGULATORY,
        scoreWeight: 30,
    );
    $types = ['DRA-CERT' => $type];

    $register = [
        'P-VALID' => new ComplianceItem('I-1', 'P-VALID', 'DRA-CERT', ComplianceState::VERIFIED,
            new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2027-01-01'), 'verified'),
        'P-LAPSED' => new ComplianceItem('I-2', 'P-LAPSED', 'DRA-CERT', ComplianceState::VERIFIED,
            new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2026-06-30'), 'verified'),
        'P-SUBMITTED' => new ComplianceItem('I-3', 'P-SUBMITTED', 'DRA-CERT',
            ComplianceState::SUBMITTED),
    ];

    $service = new ComplianceService(
        $types,
        static fn (string $s): ?bool => match ($s) {
            'P-DESK' => false,
            'P-UNKNOWABLE' => null,
            default => true,
        },
        static fn (string $s): ?ComplianceItem => $register[$s] ?? null,
    );

    $subjects = ['P-VALID', 'P-LAPSED', 'P-SUBMITTED', 'P-MISSING', 'P-DESK', 'P-UNKNOWABLE'];
    $statuses = $service->statusMany($subjects, ['DRA-CERT'], $asOf);
    ?>
  <div class="card">
    <h2>The register, as at <?= e($asOf->format('j M Y')) ?></h2>
    <table>
      <tr><th>Subject</th><th>State</th><th>Valid</th><th>Assessed on</th><th>Why</th></tr>
<?php foreach ($statuses as $status): ?>
      <tr>
        <td><code><?= e($status->subjectRef) ?></code></td>
        <td><?= e($status->state) ?></td>
        <td class="<?= $status->isValid ? 'ok' : 'no' ?>"><?= $status->isValid ? 'yes' : 'no' ?></td>
        <td class="<?= $status->isNotAssessed() ? 'warn' : '' ?>">
          <?= e($status->assessmentBasis) ?></td>
        <td class="note" style="margin:0"><?= e(mb_strimwidth($status->reason, 0, 110, '…')) ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p class="note"><b>Three absent-record cases, and none of them returns valid.</b>
       <code>P-MISSING</code> is applicable with no record — <i>due</i>, not a gap.
       <code>P-DESK</code> is <i>not applicable</i>, computed with its reason rather than left blank.
       <code>P-UNKNOWABLE</code> is <i>not assessed</i>: a check that could not run has not passed,
       and both defaults would be wrong. One of SmartPRS 2's five validity implementations treats a
       blank free-text field as valid.</p>
  </div>

  <div class="card">
    <h2>The score says what it could not assess</h2>
    <p><b><?= e(ComplianceScore::of($statuses, $types)->render()) ?></b></p>
    <p class="note">Never "n out of 100". A score that quietly drops the checks it could not run goes
       <i>up</i> when the system breaks — the dashboard looks healthiest when the data is worst.</p>
  </div>

  <div class="card">
    <h2>The money gate</h2>
    <table>
      <tr><th>Subject</th><th>Commission payment</th><th>Because</th></tr>
<?php
    $gate = new MoneyGate($service, ['commission' => ['DRA-CERT']], $types);

    foreach ($subjects as $subject) {
        $verdict = $gate->evaluate('commission', $subject, $asOf);
?>
      <tr>
        <td><code><?= e($subject) ?></code></td>
        <td class="<?= $verdict['allowed'] ? 'ok' : 'no' ?>">
          <?= $verdict['allowed'] ? 'allowed' : 'BLOCKED' ?></td>
        <td class="note" style="margin:0"><?= e(mb_strimwidth(implode(' · ', $verdict['why']),
          0, 120, '…')) ?></td>
      </tr>
<?php } ?>
    </table>
    <p class="note">The gate calls the same <code>compliance_status()</code> the register does — there
       is exactly one implementation, and a CI check greps for a second. SmartPRS 2 has five.</p>
  </div>
<?php
}

// =============================================================================
// API — the contract, generated.
// =============================================================================

if ($page === 'api') {
    $routes = new RouteRegistry();
    $routes->register('GET', '/api/v3/employees', 'employees.list', 'employee.view',
        ['employees:read'], 'EmployeeCollection', summary: 'List employments');
    $routes->register('GET', '/api/v3/employees/{id:uuid}', 'employees.show', 'employee.view',
        ['employees:read'], 'Employee', summary: 'Fetch one employment');
    $routes->register('PATCH', '/api/v3/employees/{id:uuid}', 'employees.update', 'employee.edit',
        ['employees:write'], 'Employee', 'EmployeePatch', ['validation.failed'],
        summary: 'Update an employment');
    $routes->register('POST', '/api/v3/payroll-runs', 'runs.create', 'payroll.run',
        ['payroll:write'], 'PayrollRun', 'PayrollRunRequest', ['period.locked'], idempotent: true,
        summary: 'Start a payroll run');
    ?>
  <div class="card">
    <h2>The route table</h2>
    <p>A route declares its whole contract at once, or it does not register.</p>
    <table>
      <tr><th>Method</th><th>Path</th><th>Permission</th><th>Scopes</th><th>Key</th></tr>
<?php foreach ($routes->all() as $route): ?>
      <tr>
        <td><code><?= e($route['method']) ?></code></td>
        <td><code><?= e($route['path']) ?></code></td>
        <td><code><?= e($route['permission']) ?></code></td>
        <td><?= e(implode(', ', $route['scopes'])) ?></td>
        <td><?= $route['idempotent'] ? '<span class="ok">required</span>' : '—' ?></td>
      </tr>
<?php endforeach; ?>
    </table>
    <p class="note"><code>PUT</code> is absent from this API rather than discouraged: a full-replace
       update is how SmartPRS 2's employee save nulls every omitted key. Identifiers are UUIDs only,
       and the tenant is never a path parameter — the moment it is addressable it is forgeable.</p>
  </div>

  <div class="card">
    <h2>The error envelope</h2>
    <p>One shape for every non-2xx, from a closed catalogue of
       <?= count(ErrorCatalogue::CODES) ?> codes. Clients branch on <code>code</code>, never on
       <code>message</code>.</p>
<?php
    $sample = ErrorEnvelope::build('validation.failed', 422, 'The request could not be processed.',
        '01936c1e-9a2b-7c31-b0d9-4a2f9e7c1d55', '01936c1e-9a2b-7c31-b0d9-4a2f9e7c1d56', [[
            'field' => 'joining_date', 'code' => 'date.before_dob',
            'message' => 'Joining date precedes date of birth.', 'value_seen' => '1999-03-14',
        ]]);
?>
    <pre style="white-space:pre-wrap;margin:0;font:12.5px ui-monospace,Menlo,monospace"><?=
      e(json_encode($sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>

  <div class="card">
    <h2>A 500 says nothing but its request id</h2>
<?php
    $server = ErrorEnvelope::build('server.unexpected', 500,
        'SQLSTATE[42S02]: Base table not found in /var/www/app/Repo.php line 88', 'req-2', 'corr-2');
?>
    <pre style="white-space:pre-wrap;margin:0;font:12.5px ui-monospace,Menlo,monospace"><?=
      e(json_encode($server, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
    <p class="note">The message handed in above contained a SQL state, a file path and a line number.
       None of it survives. SmartPRS 2 returns raw exception messages from 194 sites, disclosing
       schema and paths to any authenticated caller.</p>
  </div>

  <div class="card">
    <h2>The OpenAPI document, generated</h2>
<?php
    $document = (new OpenApiGenerator($routes, '3.0.0'))->generate([
        'Employee' => ['type' => 'object', 'required' => ['id'],
            'properties' => ['id' => ['type' => 'string'], 'employee_code' => ['type' => 'string']]],
        'EmployeeCollection' => ['type' => 'object', 'required' => ['data'],
            'properties' => ['data' => ['type' => 'array',
                'items' => ['$ref' => '#/components/schemas/Employee']]]],
        'EmployeePatch' => ['type' => 'object', 'required' => [], 'properties' => []],
        'PayrollRun' => ['type' => 'object', 'required' => ['id'],
            'properties' => ['id' => ['type' => 'string']]],
        'PayrollRunRequest' => ['type' => 'object', 'required' => ['period_code'],
            'properties' => ['period_code' => ['type' => 'string']]],
    ]);
?>
    <table>
      <tr><th>Version</th><td><code><?= e($document['openapi']) ?></code></td></tr>
      <tr><th>Paths</th><td class="n"><?= count($document['paths']) ?></td></tr>
      <tr><th>Schemas</th><td class="n"><?= count($document['components']['schemas']) ?></td></tr>
      <tr><th>Error codes published</th>
          <td class="n"><?= count($document['components']['x-error-catalogue']) ?></td></tr>
    </table>
    <p class="note">Generated from the route registry and the error catalogue, never hand-written —
       a hand-maintained specification drifts, and SmartPRS 2's disagrees with its source in sixteen
       recorded places.</p>
  </div>
<?php
}

?>
</main>
</body>
</html>
