<?php

declare(strict_types=1);

use SmartPRS\Platform\Dashboard\Widget;
use SmartPRS\Platform\Lifecycle\EmploymentTimeline;
use SmartPRS\Platform\Lifecycle\FullAndFinal;
use SmartPRS\Platform\Lifecycle\LetterArtefact;
use SmartPRS\Platform\Migration\ClassCRegister;
use SmartPRS\Platform\Migration\CutoverPlan;
use SmartPRS\Platform\Migration\ReconciliationReport;
use SmartPRS\Platform\Migration\Variance;
use SmartPRS\Platform\Notification\Outbox;
use SmartPRS\Platform\Notification\Redactor;
use SmartPRS\Platform\Operations\HealthCheck;
use SmartPRS\Platform\Operations\Installer;
use SmartPRS\Platform\Operations\OperationsConsole;
use SmartPRS\Platform\Operations\RestoreDrill;
use SmartPRS\Platform\Operations\Scheduler;
use SmartPRS\Platform\Operations\UpdatePackage;
use SmartPRS\Platform\Reporting\Dataset;
use SmartPRS\Platform\Reporting\ReportDefinition;
use SmartPRS\Platform\Support\Money;

group('reporting');

$dataset = new Dataset('ds', 'D', [
    'a' => ['type' => 'code', 'label' => 'A'],
    'b' => ['type' => 'code', 'label' => 'B'],
    'acct' => ['type' => 'text', 'label' => 'Account', 'pii' => true],
]);
$report = new ReportDefinition('R', 'A report', $dataset, ['a']);

same(2, count($report->withColumn('b')->columns), 'a column is added at runtime, with no code');
same(1, count($report->columns), 'and adding does not mutate the saved definition under its owner');
throws(InvalidArgumentException::class, static fn () => $report->withColumn('nope'),
    'an undeclared column is refused rather than rendered blank — a blank column reads as a fact about '
    . 'the business rather than as a typo');
throws(InvalidArgumentException::class, static fn () => $report->withColumn('acct'),
    'a PII column needs an explicit acknowledgement');
same(2, count($report->withColumn('acct', true)->columns), 'which is a decision, not a prohibition');
throws(InvalidArgumentException::class,
    static fn () => new ReportDefinition('X', 'x', $dataset, ['a'], true, ['score' => '92']),
    'a regulatory report may carry no literal');

group('dashboard');

throws(InvalidArgumentException::class,
    static fn () => new Widget('W', 't', 'count', '', '', freshnessSource: 'x'),
    'a number with no action is refused — within a month it is a number nobody reads');
throws(InvalidArgumentException::class, static fn () => new Widget('W', 't', 'count', '/x', 'Open'),
    'and one that cannot say when it was computed is refused too');

group('notification');

$outbox = new Outbox();
$outbox->queue('M', 'sms', 'Your OTP is 483920 and your account 123456789012 is credited.');

ok(!str_contains($outbox->storedBody('M'), '483920'), 'the OTP never reaches the log');
ok(!str_contains($outbox->storedBody('M'), '123456789012'), 'nor does the full account number');
ok(str_contains($outbox->storedBody('M'), '9012'),
    'but the last four survive, which is what a support agent needs and an attacker cannot use');

$outbox->send('M', 'p-1');
same(Outbox::SENT, $outbox->state('M'),
    'handing a message to a provider is SENT — delivered means the provider said it arrived');
throws(InvalidArgumentException::class, static fn () => $outbox->deliver('M', ' '),
    'delivered demands an acknowledgement');
$outbox->deliver('M', 'DLR-1');
same(Outbox::DELIVERED, $outbox->state('M'), 'and reaches delivered when one arrives');

$outbox->queue('N', 'sms', 'hi');
throws(InvalidArgumentException::class, static fn () => $outbox->deliver('N', 'DLR'),
    'a queued message cannot be delivered without having been sent');
ok(Redactor::leaks('password: hunter2'), 'the redactor detects what it is meant to');
ok(!Redactor::leaks('Your payslip is ready.'), 'and leaves ordinary text alone');

group('operations');

$health = new HealthCheck();
$health->register('db', static fn (): array => ['status' => HealthCheck::OK, 'observed' => '3ms'],
    '<100ms', 'check the host', 'ops');
ok($health->run()['ok'], 'a passing check passes');

$broken = new HealthCheck();
$broken->register('db', static fn (): array => throw new RuntimeException('refused'), '<100ms', 'x', 'ops');
ok(!$broken->run()['ok'], 'a probe that THREW has not passed — the alternative is a green board on a '
    . 'system nobody can reach');
throws(InvalidArgumentException::class,
    static fn () => (new HealthCheck())->register('x', static fn (): array => [], 't', '', ''),
    'a check with no remediation or owner trains people to ignore notifications');

$scheduler = new Scheduler();
$installer = new Installer();
$out = $installer->install(['php_8_3' => true, 'pdo_mysql' => true, 'openssl' => true,
    'writable_storage' => true], $health, $scheduler);

ok($out['ok'], 'a clean install completes');
same([], $installer->defaultCredentials(),
    'and writes no default credential — admin/admin stays admin/admin wherever the installer was not '
    . 'the user');
ok(count($scheduler->jobs()) >= 3, 'the install registers its jobs rather than leaving a runbook step');
ok(!$scheduler->isAlive(new DateTimeImmutable('2026-09-01 12:00')),
    'a scheduler that never beat is not alive — a stopped scheduler looks exactly like an idle one, and '
    . 'the difference is a filing deadline');

$state = ['code' => '1.0', 'schema' => '1.0'];
$failed = (new UpdatePackage('1.0', '2.0', true))->apply(
    static fn (): string => 'bkp',
    static function () use (&$state): void { $state['code'] = '2.0'; },
    static function (): void { throw new RuntimeException('migration failed'); },
    static function () use (&$state): void { $state['schema'] = '1.0'; },
    static function () use (&$state): void { $state['code'] = '1.0'; },
);

ok($failed['rolled_back'], 'a failing update rolls back');
same('1.0', $state['code'], 'the code goes back');
same('1.0', $state['schema'], 'AND so does the schema — rolling back only the code leaves version N '
    . 'running against a schema for N+1, and every symptom then points somewhere else');

$drill = new RestoreDrill();
ok(str_contains($drill->consoleValue(new DateTimeImmutable()), 'NEVER PROVEN RESTORABLE'),
    'a system that has never drilled says so rather than showing a blank');
ok(!$drill->run('b', new DateTimeImmutable(), static fn (): bool => true,
    static fn (): array => ['tables' => 1, 'rows' => 3], ['tables' => 1, 'rows' => 900])['ok'],
    'a corrupt backup restores perfectly — the drill verifies what landed, or it has only proven the '
    . 'restore command runs');

$console = new OperationsConsole();
throws(InvalidArgumentException::class,
    static fn () => $console->item('scheduler', 'A number', '7', asOf: 'now'),
    'a console item with no next step is refused');

group('migration');

$exact = new Variance('RC-04', 'E-1', '2026-08', Money::of('100.00'), Money::of('100.01'), Variance::EXACT);
ok($exact->isFailure(), 'one paisa on a migrated COPY is a failure — not "within Rs 1", not "within 0.01%"');

$noRef = new Variance('RC-05', 'E-1', '2026-08', Money::of('100.00'), Money::of('90.00'),
    Variance::EXPLAINED, acceptedBy: 'u-1');
ok($noRef->isFailure(), 'a Class X with no reference IS a Class E failure — "we think it is because..." '
    . 'is not an explanation');

$wrongWay = new Variance('RC-05', 'E-1', '2026-08', Money::of('100.00'), Money::of('150.00'),
    Variance::CORRECTION, classCCode: 'C-LOP', disclosedBeforeGoLive: true);
ok($wrongWay->isFailure(), 'an LOP correction that INCREASED net pay is not a correction — when the '
    . 'direction is wrong the classification is wrong');
same(Variance::EXACT, $wrongWay->effectiveClass,
    'and it demotes to a failure rather than to a softer class');

$good = new Variance('RC-05', 'E-1', '2026-08', Money::of('100.00'), Money::of('90.00'),
    Variance::CORRECTION, classCCode: 'C-LOP', disclosedBeforeGoLive: true);
ok($good->isCorrection() && !$good->isFailure(),
    'a correct Class C is a SUCCESS, never counted in the failure total');

ok((new Variance('RC-13', 'E-1', '2026-08', Money::of('100.00'), Money::of('90.00'),
    Variance::CORRECTION, classCCode: 'C-ESI-OT', disclosedBeforeGoLive: true))->isFailure(),
    'a correction awaiting a compliance ruling must not be applied until the ruling exists');

$report = new ReconciliationReport('T', 'S');
$report->add($good);
ok($report->passes(), 'a report whose only difference is a correction passes');
same([], $report->forbiddenLanguageInCorrections(),
    'and never calls a correction a variance, a discrepancy, an error or an exception — the customer '
    . 'reads this report');
same(1, substr_count($report->render(), 'TOTAL FINANCIAL EFFECT OF CORRECTING KNOWN SmartPRS 2 DEFECTS'),
    'the single number is stated exactly once; printed twice it would one day disagree with itself');

$plan = new CutoverPlan('T', new DateTimeImmutable('2026-10-28'));
$plan->step('freeze', 'u', 'unfreeze');
ok(!$plan->readiness(240)['ready'],
    'a plan with no rehearsal is a document; the moment it is needed is the moment nobody can afford to '
    . 'discover what it left out');
$plan->rehearse(new DateTimeImmutable('2026-10-14'), 95, true, 'ok');
ok($plan->readiness(240)['ready'], 'a rehearsed plan within its window is ready');
ok(!$plan->readiness(60)['ready'], 'and a 95-minute rollback is not a rollback for a 60-minute window');
ok(!$plan->canCutOver($report, 240)['ok'], 'nobody has signed, so nobody cuts over');
throws(InvalidArgumentException::class,
    static fn () => $plan->step('a step', 'u', ''),
    'a cutover step with no way back is a point of no return nobody labelled');

same(14, count(ClassCRegister::ENTRIES), 'the Class C register carries all fourteen defects');

group('lifecycle');

$letter = new LetterArtefact('L', 'offer', 'tpl-v3', ['ctc' => '600000']);
$letter->issue('Offer: CTC 600000', new DateTimeImmutable('2026-08-01'));
throws(LogicException::class,
    static fn () => $letter->issue('Offer: CTC 600000 (template updated)', new DateTimeImmutable()),
    'an issued letter is frozen — re-rendering replaces the document a person signed with one built from '
    . "today's template, and neither party can then prove what was agreed");
ok(!$letter->isFullyExecuted(), 'a letter only the issuer signed is not executed');
$letter->sign('counterparty', 'A. Candidate', new DateTimeImmutable('2026-08-03'));
ok($letter->isFullyExecuted() && $letter->isIntact(), 'countersigned and intact');

$timeline = new EmploymentTimeline('E-1');
$timeline->record(EmploymentTimeline::JOINED, new DateTimeImmutable('2025-04-01'), ['grade' => 'G4']);
$timeline->record(EmploymentTimeline::PROMOTED, new DateTimeImmutable('2026-09-01'), ['grade' => 'G5'], 'r');
same('G4', $timeline->valueOn('grade', new DateTimeImmutable('2026-08-15')),
    'the timeline answers what the grade WAS in August; a column answers with today\'s');
$timeline->record(EmploymentTimeline::EXITED, new DateTimeImmutable('2026-11-30'), [], 'served notice');
throws(InvalidArgumentException::class,
    static fn () => $timeline->record(EmploymentTimeline::INCREMENT, new DateTimeImmutable('2026-12-01')),
    'nothing happens after an exit — that is the shape that pays somebody who left in March');

$fnf = new FullAndFinal('E-1', new DateTimeImmutable('2026-11-30'));
$fnf->add('LEAVE', Money::of('18400.00'), true, 'encashed');
$fnf->add('LOAN', Money::of('-42000.00'), false, 'still open');
ok(!$fnf->settle()['settled'],
    'a settlement with an open item is refused; "settled, with two outstanding" becomes a legal letter '
    . 'eighteen months later');
$fnf->add('LOAN', Money::of('-42000.00'), true, 'recovered in full');
same('-23600.00', $fnf->settle()['net']->toDecimalString(),
    'and a negative settlement is stated rather than clamped — it means the employee owes the company');
