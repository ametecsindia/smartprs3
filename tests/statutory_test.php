<?php

declare(strict_types=1);

require_once __DIR__ . '/../tools/phase6/synthetic-pack.php';

use SmartPRS\Platform\Statutory\Applicability;
use SmartPRS\Platform\Statutory\Jcs;
use SmartPRS\Platform\Statutory\PackRejected;
use SmartPRS\Platform\Statutory\PackStore;
use SmartPRS\Platform\Statutory\PackVerifier;
use SmartPRS\Platform\Statutory\RulePack;
use SmartPRS\Platform\Statutory\StatutoryEngine;
use SmartPRS\Platform\Statutory\StatutoryUnresolved;
use SmartPRS\Platform\Support\Money;

group('statutory');

// -----------------------------------------------------------------------------
// Canonicalisation: the signature must be over the content, not its formatting.
// -----------------------------------------------------------------------------

same('{"a":2,"b":1}', Jcs::canonicalise(['b' => 1, 'a' => 2]), 'JCS sorts object keys');
same('[1,2,3]', Jcs::canonicalise([1, 2, 3]), 'JCS leaves array order alone — a list is not a set');
same(
    Jcs::hash(['x' => ['b' => 1, 'a' => 2]]),
    Jcs::hash(['x' => ['a' => 2, 'b' => 1]]),
    'the same content written in a different key order hashes identically, so a pack that survives a '
    . 'round-trip through an editor still verifies',
);
ok(Jcs::hash(['a' => 1]) !== Jcs::hash(['a' => 2]), 'and different content does not');

throws(InvalidArgumentException::class, static fn () => Jcs::canonicalise(0.1),
    'a float cannot appear in a signed statutory artefact at all — a rate that arrived as a double has '
    . 'already lost what the notification said, and hashing it afterwards only certifies the loss');

// -----------------------------------------------------------------------------
// The signature, and the refusals.
// -----------------------------------------------------------------------------

$synthetic = syntheticPack();
$verifier = new PackVerifier($synthetic['keys'], '3.2.0');

$verifier->verify($synthetic['pack']);
ok(true, 'a correctly signed pack verifies');

throws(PackRejected::class, static function () use ($synthetic, $verifier): void {
    $content = $synthetic['content'];
    $content['rules'][0]['rounding'] = 'down';
    $verifier->verify(RulePack::of($synthetic['manifest'], $content));
}, 'a pack edited after signing is refused');

throws(PackRejected::class, static function () use ($synthetic, $verifier): void {
    $manifest = $synthetic['manifest'];
    $manifest['integrity']['countersign_key_id'] = $manifest['integrity']['public_key_id'];
    $verifier->verify(RulePack::of($manifest, $synthetic['content']));
}, 'one key signing twice is not four-eyes');

throws(PackRejected::class, static function () use ($synthetic): void {
    $content = $synthetic['content'];
    $content['rules'][0]['source_reference'] = '';
    RulePack::of($synthetic['manifest'], $content);
}, 'a rule with no source_reference cannot be built at all — R-XVII-028 made structural');

// R-XVII-027: there is no way to turn verification off.
$forceParams = [];

foreach ((new ReflectionClass(PackVerifier::class))->getMethods() as $method) {
    foreach ($method->getParameters() as $parameter) {
        if (preg_match('/force|skip|unsafe|disable/i', $parameter->getName()) === 1) {
            $forceParams[] = $method->getName() . '($' . $parameter->getName() . ')';
        }
    }
}

same([], $forceParams, 'no method offers a way to bypass verification — an option that exists is an option '
    . 'somebody sets in production to get a payroll out on the 30th');

// -----------------------------------------------------------------------------
// The store: immutable, re-verified, never deleting.
// -----------------------------------------------------------------------------

$store = new PackStore($verifier);
$store->put($synthetic['pack']);

throws(PackRejected::class, static fn () => $store->put($synthetic['pack']),
    'a signed pack version is never written twice — a correction is a new version that supersedes');

$store->put(syntheticPack(['version' => '2026.2', 'effective_from' => '2027-01-01'])['pack']);

same('2026.1', $store->resolve('IN-ZZ-SYNTH', new DateTimeImmutable('2026-11-30'))?->manifest->version(),
    'November resolves the pack in force in November, not the one installed most recently');
same('2026.2', $store->resolve('IN-ZZ-SYNTH', new DateTimeImmutable('2027-02-01'))?->manifest->version(),
    'and February resolves the amendment, on its own statutory effective date');
same(null, $store->resolve('IN-ZZ-SYNTH', new DateTimeImmutable('2026-01-01')),
    'a date before any pack resolves to nothing rather than to the earliest one');

// -----------------------------------------------------------------------------
// The engine: one implementation, and a refusal rather than a zero.
// -----------------------------------------------------------------------------

$applicability = new Applicability();
$applicability->declare('PT', 'EST-SYNTH', 'IN-ZZ-SYNTH', new DateTimeImmutable('2026-04-01'), true,
    'declared covered', 'synthetic registration');

$engine = new StatutoryEngine($store, $applicability);

$row = $engine->compute('PT', 'E-1', 'EST-SYNTH', new DateTimeImmutable('2026-11-30'),
    ['CMP-A' => Money::of('30000.00'), 'CMP-B' => Money::of('5000.00')],
    ['CMP-A' => ['is_synth_base' => true], 'CMP-B' => []]);

same('30000.00', $row->wageBase->toDecimalString(),
    'the wage base is the sum of components carrying the declared FLAG, never a list of names');
same(['CMP-A'], $row->baseComposition, 'and the snapshot names which ones');
same('700.00', $row->amount->toDecimalString(), 'the figure comes from the pack, through the one engine');
ok($row->isComplete(), 'the figure carries everything needed to re-derive it offline in 2031');
ok(str_contains($row->explain(), 'SYNTHETIC'), 'a synthetic figure is stamped as such and cannot pass as law');

$undetermined = $engine->compute('PT', 'E-1', 'EST-NOBODY-ASKED', new DateTimeImmutable('2026-11-30'),
    ['CMP-A' => Money::of('30000.00')], ['CMP-A' => ['is_synth_base' => true]]);

ok($undetermined->applicability->undetermined,
    '"nobody decided" is a third state, not the same as "does not apply" — the same zero on a payslip, '
    . 'completely different facts on an inspection');
ok(str_contains($undetermined->explain(), 'NOT DETERMINED'), 'and the nil line says why it is nil');

$bound = new Applicability();
$bound->declare('PT', 'EST-SYNTH', 'IN-NOWHERE', new DateTimeImmutable('2026-04-01'), true, 'covered', 'reg');

throws(StatutoryUnresolved::class, static fn () => (new StatutoryEngine($store, $bound))
    ->compute('PT', 'E-1', 'EST-SYNTH', new DateTimeImmutable('2026-11-30'),
        ['CMP-A' => Money::of('30000.00')], ['CMP-A' => ['is_synth_base' => true]]),
    'a statute that APPLIES and cannot be computed refuses; every SmartPRS 2 path in this position '
    . 'returns zero, and a wrong zero is invisible on a payslip');

throws(InvalidArgumentException::class,
    static fn () => $applicability->declare('PT', 'E-2', 'IN-ZZ-SYNTH', new DateTimeImmutable('2026-04-01'),
        true, '', 'evidence'),
    'a determination with no stated reason is an opinion');

same(['EST-X'], $applicability->undetermined('PT', ['EST-SYNTH', 'EST-X'], new DateTimeImmutable('2026-11-30')),
    'the coverage gate names the establishments nobody has decided about');

// -----------------------------------------------------------------------------
// The floor: a policy may improve on the statute, never reduce it.
// -----------------------------------------------------------------------------

use SmartPRS\Platform\Statutory\ComplianceException;
use SmartPRS\Platform\Statutory\Obligation;
use SmartPRS\Platform\Statutory\ObligationCalendar;
use SmartPRS\Platform\Statutory\ReturnFile;
use SmartPRS\Platform\Statutory\StatutoryFloor;

$declared = [
    'floor' => Money::of('700.00'),
    'direction' => StatutoryFloor::HIGHER_IS_BETTER,
    'severity' => StatutoryFloor::EXCEPTION_REQUIRED,
    'rule_code' => 'SYNTH-PT-001',
    'source_reference' => 'SYNTHETIC',
];

ok(StatutoryFloor::check(Money::of('900.00'), $declared)['ok'],
    'a policy above the floor passes — the baseline is a floor, not a target');
ok(!StatutoryFloor::check(Money::of('500.00'), $declared)['ok'],
    'and one below it needs a recorded exception, not a checkbox');

$exception = new ComplianceException(
    'SYNTH-PT-001',
    'A transitional arrangement recorded to exercise this mechanism, which states no law whatsoever.',
    'SYNTHETIC reference', 'DOC-1', 'u-compliance-head',
    new DateTimeImmutable('2026-04-01'), new DateTimeImmutable('2027-03-31'),
    new DateTimeImmutable('2026-10-01'), 'u-md',
);

ok(StatutoryFloor::check(Money::of('500.00'), $declared, $exception)['ok'],
    'a properly recorded exception authorises the departure it names');
ok(!StatutoryFloor::check(Money::of('500.00'), ['rule_code' => 'OTHER'] + $declared, $exception)['ok'],
    'and authorises nothing else — an exception is not a general permission');
ok(!StatutoryFloor::check(Money::of('500.00'), ['severity' => StatutoryFloor::BLOCK] + $declared,
    $exception)['ok'],
    'a BLOCK has no exception path at any permission level; the auditable route is applicability');

ok(!$exception->isActive(new DateTimeImmutable('2027-06-01')),
    'an expired exception authorises nothing — expiry re-raises the finding');

throws(InvalidArgumentException::class, static fn () => new ComplianceException('R', 'n/a', 'ref', 'ev',
    'u-a', new DateTimeImmutable('2026-04-01'), new DateTimeImmutable('2027-03-31'),
    new DateTimeImmutable('2026-10-01'), 'u-b'),
    'a one-word legal basis is refused — the minimum does not make a basis good, it makes "n/a" impossible');

throws(InvalidArgumentException::class, static fn () => new ComplianceException('R',
    str_repeat('a basis long enough to be a basis. ', 3), 'ref', 'ev', 'u-a',
    new DateTimeImmutable('2026-04-01'), new DateTimeImmutable('2027-03-31'),
    new DateTimeImmutable('2026-10-01'), 'u-a'),
    'one person cannot both authorise and approve a departure from the statutory baseline');

// -----------------------------------------------------------------------------
// The calendar, and the state nobody can reach by accident.
// -----------------------------------------------------------------------------

$calendarApplicability = new Applicability();
$calendarApplicability->declare('PT', 'EST-SYNTH', 'IN-ZZ-SYNTH', new DateTimeImmutable('2026-04-01'),
    true, 'declared covered', 'registration');

$materialised = (new ObligationCalendar($store, $calendarApplicability))->materialise(
    [['code' => 'EST-SYNTH', 'head' => 'PT', 'registration' => 'REG-1'],
     ['code' => 'EST-UNASKED', 'head' => 'PT', 'registration' => 'REG-2']],
    ['2026-10'], new DateTimeImmutable('2026-11-30'), ['SYNTH-PT-RETURN' => 'u-compliance'],
);

same(1, count($materialised['obligations']), 'only the covered establishment generates a filing');
ok($materialised['problems'] !== [],
    'and the establishment nobody assessed produces a WARNING — an empty calendar is either a compliant '
    . 'company or one nobody has looked at');

$obligation = $materialised['obligations'][0];
same('2026-11-15', $obligation->dueOn()->format('Y-m-d'), 'the due date comes from the pack, not from a form');

throws(InvalidArgumentException::class,
    static fn () => $obligation->file('u-1', 'ACK', new DateTimeImmutable('2026-11-10')),
    'a DUE obligation cannot be filed — filing runs from a prepared state, and prepared means reconciled');

$obligation->prepare();

throws(InvalidArgumentException::class,
    static fn () => $obligation->file('u-1', '  ', new DateTimeImmutable('2026-11-10')),
    'filing with no evidence is refused: this is the exact path that produces six quarters marked Filed '
    . 'that nobody filed');

$obligation->file('u-1', 'ACK-0042', new DateTimeImmutable('2026-11-10'));
same(Obligation::FILED, $obligation->state(), 'a recorded act with evidence files it');

throws(InvalidArgumentException::class,
    static fn () => new Obligation('C', 'PT', 'REG', '2026-10', '', new DateTimeImmutable('2026-11-15'),
        'rule', '2026.1'),
    'an obligation with no owner is refused rather than sitting on a dashboard');

// -----------------------------------------------------------------------------
// Returns: from stored rows, or not produced at all.
// -----------------------------------------------------------------------------

$real = new SmartPRS\Platform\Statutory\ContributionRow(
    $row->head, $row->employmentId, $row->wageBase, $row->baseComposition, $row->wageBaseFlag, $row->amount,
    $row->ruleCode, $row->packKey, $row->packVersion, $row->packContentHash, $row->packKeyId,
    $row->packEffectiveFrom, $row->resolvedValues, $row->rounding, $row->applicability,
    $row->sourceReference, synthetic: false,
);

ok(!ReturnFile::generate('F', 'EST-SYNTH', '2026-11', 'PT', [$row])['ok'],
    'a SYNTHETIC figure is refused at the filing boundary, whatever else is true of it');

$produced = ReturnFile::generate('F', 'EST-SYNTH', '2026-11', 'PT', [$real], Money::of('700.00'));
ok($produced['ok'], 'a clean return produces a file');
ok($produced['hash'] !== null, 'and it is hashed, so "what exactly did we file?" survives the years');

$mismatch = ReturnFile::generate('F', 'EST-SYNTH', '2026-11', 'PT', [$real], Money::of('700.01'));
same(null, $mismatch['file'],
    'a control total out by one paisa produces NO FILE — a file generated with a warning is a file filed '
    . 'with a warning');

ok(!ReturnFile::generate('F', 'EST-ELSEWHERE', '2026-11', 'PT', [$real])['ok'],
    'the population is exactly the rows stored for that registration and period, never every employee');
