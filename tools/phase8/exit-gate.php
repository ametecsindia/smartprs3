<?php
/**
 * THE PHASE 8 EXIT GATE — Part XXXII, Reports, dashboards and automation.
 *
 *   php tools/phase8/exit-gate.php
 *
 * Four exit conditions:
 *
 *   1. A customer can create a report and add a column with NO CODE.
 *   2. Every dashboard widget links to the action it implies.
 *   3. A message is recorded delivered only on provider acknowledgement.
 *   4. No OTP, password or full sensitive identifier is persisted in a message log.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';

use SmartPRS\Platform\Dashboard\Widget;
use SmartPRS\Platform\Notification\Outbox;
use SmartPRS\Platform\Notification\Redactor;
use SmartPRS\Platform\Reporting\Dataset;
use SmartPRS\Platform\Reporting\ReportDefinition;

$root = dirname(__DIR__, 2);
$results = [];

echo "SmartPRS 3 — Phase 8 exit gate (Part XXXII, Reports, dashboards and automation)\n";

// =============================================================================
// 1. A CUSTOMER CREATES A REPORT AND ADDS A COLUMN WITH NO CODE
// =============================================================================

$problems = [];

$dataset = new Dataset('ds_employment', 'Employment', [
    'employee_code' => ['type' => 'code', 'label' => 'Employee'],
    'department' => ['type' => 'code', 'label' => 'Department'],
    'gross' => ['type' => 'money', 'label' => 'Gross'],
    'bank_account' => ['type' => 'text', 'label' => 'Bank account', 'pii' => true],
]);

// The whole criterion, executed: a definition, then a column added, with nothing
// on disk changed and no class written for this report.
$report = new ReportDefinition('RPT-1', 'Headcount by department', $dataset, ['employee_code']);
$extended = $report->withColumn('department')->withColumn('gross');

if (count($extended->columns) !== 3) {
    $problems[] = 'adding two columns produced ' . count($extended->columns) . ' column(s)';
}

if (count($report->columns) !== 1) {
    $problems[] = 'adding a column mutated the original definition; a report version that changes under an '
        . 'existing saved view is a report whose output changes without anyone editing it';
}

$rendered = $extended->render([
    ['employee_code' => 'E-1', 'department' => 'OPS', 'gross' => '50000.00'],
]);

if (!str_contains($rendered, 'Department') || !str_contains($rendered, 'OPS')) {
    $problems[] = 'the added column does not appear in the rendered output, so "added" meant nothing';
}

// The proof that no code was involved: nothing under src/ names this report.
$namedInCode = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src')) as $file) {
    if ($file->isFile() && $file->getExtension() === 'php'
        && str_contains((string) file_get_contents($file->getPathname()), 'RPT-1')
    ) {
        $namedInCode[] = str_replace($root . '/', '', $file->getPathname());
    }
}

if ($namedInCode !== []) {
    $problems[] = 'the report code appears in ' . implode(', ', $namedInCode)
        . ' — a report a customer builds must exist nowhere in the application';
}

// A column the dataset does not declare is REFUSED, not rendered blank.
try {
    $report->withColumn('a_column_nobody_declared');
    $problems[] = 'a column outside the dataset was accepted. It would render blank, and a blank column '
        . 'reads as a fact about the business rather than as a typo';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

// A PII column needs an explicit acknowledgement.
try {
    $report->withColumn('bank_account');
    $problems[] = 'a PII column was added with no acknowledgement. A report a customer can extend is a '
        . 'report that can be extended into an export of everybody\'s identifiers';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

if (count($report->withColumn('bank_account', acknowledgePii: true)->columns) !== 2) {
    $problems[] = 'an acknowledged PII column was still refused; the acknowledgement is a decision, not a '
        . 'prohibition';
}

// A regulatory report may carry no literal (Part XXI R-XXI-056).
try {
    new ReportDefinition('RPT-REG', 'Audit', $dataset, ['employee_code'], regulatory: true,
        literals: ['score' => '92']);
    $problems[] = 'a regulatory report carrying a literal score was accepted. That is precisely how '
        . '`score => 92` reaches a customer-branded document';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

$results[] = [
    'name' => 'A customer can create a report and add a column with no code',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'a report is built and extended at runtime from a declared dataset, the report code appears '
        . 'nowhere under src/, adding is non-mutating so a saved view cannot change under its owner, an '
        . 'undeclared column is refused rather than rendered blank, a PII column needs an explicit '
        . 'acknowledgement, and a regulatory report may carry no literal',
];

// =============================================================================
// 2. EVERY DASHBOARD WIDGET LINKS TO THE ACTION IT IMPLIES
// =============================================================================

$problems = [];

$widgets = [
    new Widget('W-EXPIRED', 'Expired certifications', 'count', '/compliance/register?state=overdue',
        'Open the 14 records', freshnessSource: 'compliance sweep'),
    new Widget('W-UNASSIGNED', 'Obligations with no owner', 'count', '/compliance/obligations?owner=none',
        'Assign an owner', 'https://runbook/obligations', 'obligation materialisation'),
    new Widget('W-ARREARS', 'Open arrears', 'count', '/payroll/arrears', 'Review arrears',
        freshnessSource: 'last payroll run'),
];

foreach ($widgets as $widget) {
    if (trim($widget->actionRoute) === '') {
        $problems[] = "{$widget->code} shows a number with no action";
    }
}

try {
    new Widget('W-DEAD', 'Something', 'count', '', '', freshnessSource: 'x');
    $problems[] = 'a widget with no action was accepted. R-XXXI-090 states the same rule for the operations '
        . 'console: a read-only number with no next step must not be shown';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

try {
    new Widget('W-STALE', 'Something', 'count', '/x', 'Open');
    $problems[] = 'a widget that cannot say when its number was computed was accepted. A stale figure that '
        . 'looks live is worse than an obviously old one, because only one of them gets questioned';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

// The drill-through must agree with the count, which is the property an action
// link makes checkable at all.
if ($widgets[0]->agreesWith(14, 11)) {
    $problems[] = 'a widget reporting 14 agreed with a drill-through returning 11';
}

if (!$widgets[0]->agreesWith(14, 14)) {
    $problems[] = 'a widget disagreed with a drill-through that matches it';
}

$results[] = [
    'name' => 'Every dashboard widget links to the action it implies',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($widgets) . ' widgets, each carrying a route and a label; a widget with no action and '
        . 'one with no freshness source are both refused at construction; and the count must agree with the '
        . 'rows behind it, which is a property only a drill-through makes checkable',
];

// =============================================================================
// 3. DELIVERED ONLY ON PROVIDER ACKNOWLEDGEMENT
// =============================================================================

$problems = [];
$outbox = new Outbox();

$outbox->queue('M-1', 'sms', 'Your payslip for September is ready.');

if ($outbox->state('M-1') !== Outbox::QUEUED) {
    $problems[] = 'a queued message did not start queued';
}

$outbox->send('M-1', 'provider-abc-123');

if ($outbox->state('M-1') === Outbox::DELIVERED) {
    $problems[] = 'handing a message to a provider marked it DELIVERED. Sent means we handed it over; '
        . 'delivered means the provider said it arrived, and collapsing the two is how a team believes 400 '
        . 'payslip notifications went out when the gateway dropped every one';
}

try {
    $outbox->deliver('M-1', '   ');
    $problems[] = 'a message was marked delivered with no provider acknowledgement';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

$outbox->deliver('M-1', 'DLR-OK-2026-09-01T10:04Z');

if ($outbox->state('M-1') !== Outbox::DELIVERED) {
    $problems[] = 'an acknowledged message did not reach delivered';
}

// Delivered is unreachable from anywhere but sent.
$outbox->queue('M-2', 'email', 'hello');

try {
    $outbox->deliver('M-2', 'DLR-1');
    $problems[] = 'a QUEUED message was delivered without ever being sent';
} catch (InvalidArgumentException) {
    // Refused, as it must be.
}

// And no other method can reach it — checked structurally, so a future
// convenience setter cannot quietly open a second route.
$routes = [];

foreach ((new ReflectionClass(Outbox::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    $source = implode('', array_slice(
        file((new ReflectionClass(Outbox::class))->getFileName()) ?: [],
        $method->getStartLine() - 1,
        $method->getEndLine() - $method->getStartLine() + 1,
    ));

    if (str_contains($source, 'self::DELIVERED') && $method->getName() !== 'deliver'
        && $method->getName() !== 'counts'
    ) {
        $routes[] = $method->getName();
    }
}

if ($routes !== []) {
    $problems[] = 'these methods can also reach DELIVERED: ' . implode(', ', $routes)
        . '. One route, with an acknowledgement, or the state means nothing';
}

$counts = $outbox->counts();

if ($counts[Outbox::DELIVERED] !== 1 || $counts[Outbox::QUEUED] !== 1) {
    $problems[] = 'the console counts do not match the outbox: ' . json_encode($counts);
}

$results[] = [
    'name' => 'A message is recorded delivered only on provider acknowledgement',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'sent and delivered are separate states; deliver() demands a provider reference, refuses a '
        . 'blank one, refuses to run from anywhere but sent, and is structurally the only method that can '
        . 'reach the state — so a future convenience setter cannot open a second route',
];

// =============================================================================
// 4. NO OTP, PASSWORD OR FULL SENSITIVE IDENTIFIER IN A MESSAGE LOG
// =============================================================================

$problems = [];
$log = new Outbox();

$sensitive = [
    'M-OTP' => 'Your OTP is 483920. It expires in 10 minutes.',
    'M-PWD' => 'Your temporary password: Xy7#qLm9 — please change it.',
    'M-PIN' => 'ATM PIN 4417 has been reset.',
    'M-ID' => 'We have recorded your identifier 4123 5678 9012 against your record.',
    'M-ACC' => 'Salary credited to account 123456789012.',
    'M-TAX' => 'Your tax identifier ABCDE1234F is on file.',
    'M-TOKEN' => 'API token abc123def456 issued.',
];

foreach ($sensitive as $id => $body) {
    $log->queue($id, 'sms', $body);
}

foreach ($sensitive as $id => $original) {
    $stored = $log->storedBody($id);

    if ($stored === $original) {
        $problems[] = "{$id}: the message log stored the original text verbatim — a message log is the "
            . 'least-guarded copy of every credential the system ever sent, read by support, exported for '
            . 'debugging and folded into diagnostic bundles';
    }
}

foreach ([
    'M-OTP' => '483920',
    'M-PWD' => 'Xy7#qLm9',
    'M-PIN' => '4417',
    'M-ACC' => '123456789012',
    'M-TAX' => 'ABCDE1234F',
    'M-TOKEN' => 'abc123def456',
] as $id => $secret) {
    if (str_contains($log->storedBody($id), $secret)) {
        $problems[] = "{$id}: '{$secret}' survived into the stored body";
    }
}

// The identifier keeps its last four: enough for support to confirm the right
// record, not enough for anyone to use.
if (!str_contains($log->storedBody('M-ACC'), '9012')) {
    $problems[] = 'the account number was redacted past usefulness; the last four are what let a support '
        . 'agent confirm they have the right record';
}

if ($log->leakingMessages() !== []) {
    $problems[] = 'these stored bodies still contain something they should not: '
        . implode(', ', $log->leakingMessages());
}

// The redactor is PROVED to detect, so a clean log means clean rather than broken.
if (!Redactor::leaks('Your OTP is 999111.')) {
    $problems[] = 'the redactor does not detect a plain OTP, so a clean message log proves only that the '
        . 'redactor is broken';
}

if (Redactor::leaks('Your payslip for September is ready.')) {
    $problems[] = 'the redactor flags ordinary text, which is how a redactor gets switched off';
}

$results[] = [
    'name' => 'No OTP, password or full sensitive identifier is persisted in a message log',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($sensitive) . ' message shapes — OTP, password, PIN, token, 12-digit identifier, bank '
        . 'account, tax identifier — are redacted ON THE WAY INTO the log rather than at render, so the '
        . 'database never holds them; identifiers keep their last four; and the redactor is probed against '
        . 'a known secret and against ordinary text so a clean log means clean',
];

// =============================================================================

$failed = 0;

foreach ($results as $index => $result) {
    if ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL      \033[0m  %s\n", $index + 1, $result['name']);

        foreach ($result['problems'] as $problem) {
            echo "        └─ {$problem}\n";
        }

        continue;
    }

    printf("\n  %d. \033[32mPASS      \033[0m  %s\n        %s\n", $index + 1, $result['name'],
        $result['detail']);
}

printf("\n%s  %d of %d Phase 8 exit criteria proven\n", $failed === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed, count($results));

exit($failed === 0 ? 0 : 1);
