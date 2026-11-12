<?php
/**
 * THE PHASE 6 EXIT GATE — Part XXXII, India statutory framework.
 *
 *   php tools/phase6/exit-gate.php
 *
 * Part XXXII states five exit conditions:
 *
 *   1. Every statutory value in the first pack has been verified against its
 *      primary source by the named compliance owner and carries a
 *      `source_reference`.
 *   2. The pack signature verifies.
 *   3. A pack change reaches a test install with no application deployment.
 *   4. Every payslip records the pack version and resolved values used.
 *   5. There is exactly one implementation of each statutory calculation,
 *      asserted by a CI check.
 *
 * Four of those are MACHINERY and are proven here against a synthetic pack. The
 * first is CONTENT and is blocked on the appointment the entry gate names — and
 * it reports NOT PROVEN rather than being quietly omitted, because a gate that
 * shows the criteria it can pass and stays silent about the rest is a progress
 * bar. A pack of real Indian rates attributed to nobody would turn this
 * criterion green and mean nothing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../tests/lib/bootstrap.php';
require_once __DIR__ . '/synthetic-pack.php';

use SmartPRS\Platform\Statutory\Applicability;
use SmartPRS\Platform\Statutory\GoldenReplay;
use SmartPRS\Platform\Statutory\Jcs;
use SmartPRS\Platform\Statutory\PackRejected;
use SmartPRS\Platform\Statutory\PackStore;
use SmartPRS\Platform\Statutory\PackVerifier;
use SmartPRS\Platform\Statutory\RulePack;
use SmartPRS\Platform\Statutory\StatutoryEngine;
use SmartPRS\Platform\Support\Money;

$root = dirname(__DIR__, 2);
$results = [];

echo "SmartPRS 3 — Phase 6 exit gate (Part XXXII, India statutory framework)\n";

$synthetic = syntheticPack();
$verifier = new PackVerifier($synthetic['keys'], '3.2.0');

/** A store and engine wired to the synthetic pack, for the criteria that need one. */
$wire = static function (array $s) use (&$verifier): array {
    $store = new PackStore($verifier);
    $store->put($s['pack']);

    $applicability = new Applicability();
    $applicability->declare('PT', 'EST-SYNTH', $s['pack']->manifest->packKey(),
        new DateTimeImmutable('2026-04-01'), true,
        'the synthetic establishment is declared covered from 1 April 2026',
        'synthetic registration record');

    return [$store, new StatutoryEngine($store, $applicability), $applicability];
};

[$store, $engine] = $wire($synthetic);

// =============================================================================
// 1. EVERY STATUTORY VALUE VERIFIED BY THE NAMED COMPLIANCE OWNER — CONTENT
// =============================================================================

$ownerPath = $root . '/config/compliance-owner.json';
$owner = is_file($ownerPath)
    ? json_decode((string) file_get_contents($ownerPath), true, 512, JSON_THROW_ON_ERROR)
    : null;

if (!is_array($owner) || trim((string) ($owner['name'] ?? '')) === '') {
    $results[] = [
        'name' => 'Every statutory value is verified against its primary source by the named compliance owner',
        'ran' => false,
        'problems' => [
            'R-XXXII-006: no compliance owner is appointed, so no pack can be verified and no pack of real '
            . 'statutory content exists to verify. This is the phase\'s HARD dependency, not an oversight — '
            . 'run tools/phase6/entry-gate.php.',
            'The only pack in this build is SYNTHETIC: jurisdiction IN-ZZ, which does not exist, values '
            . 'invented, verification.synthetic = true, and every figure derived from it is stamped as such '
            . 'on the payslip. It proves the machinery and states no law.',
            'What is NOT missing: the mechanism that will refuse a real pack lacking any of this. The '
            . 'manifest requires every verification.* field, every rule and every slab table requires a '
            . 'source_reference, and the four criteria below prove each refusal fires.',
        ],
        'detail' => '',
    ];
} else {
    $problems = [];

    foreach ($synthetic['pack']->rules() as $rule) {
        if (trim($rule->sourceReference) === '') {
            $problems[] = "rule {$rule->code} carries no source_reference";
        }
    }

    if ($synthetic['pack']->manifest->isSynthetic()) {
        $problems[] = 'a compliance owner is appointed but the only installed pack is still the SYNTHETIC '
            . 'one. The appointment opens this criterion; it does not satisfy it';
    }

    $results[] = [
        'name' => 'Every statutory value is verified against its primary source by the named compliance owner',
        'ran' => true,
        'problems' => $problems,
        'detail' => 'verified by ' . $owner['name'] . ', reviewed by ' . ($owner['reviewer_name'] ?? '?'),
    ];
}

// =============================================================================
// 2. THE PACK SIGNATURE VERIFIES — AND EVERY FORGERY IS REFUSED
// =============================================================================

$problems = [];

try {
    $verifier->verify($synthetic['pack']);
} catch (PackRejected $e) {
    $problems[] = 'a correctly signed pack was rejected: ' . implode('; ', $e->reasons);
}

/** Build a variant of the synthetic pack and assert it is REFUSED for a named reason. */
$refuses = static function (string $what, callable $mutate, string $expect) use (&$problems, $verifier): void {
    try {
        $s = syntheticPack();
        [$manifest, $content] = $mutate($s['manifest'], $s['content']);
        $verifier->verify(RulePack::of($manifest, $content));
        $problems[] = "{$what} was ACCEPTED. R-XVII-009 says rejected outright: not staged, not partially "
            . 'applied, not warned about';
    } catch (PackRejected $e) {
        if (!$e->mentions($expect)) {
            $problems[] = "{$what} was refused, but for '" . implode('; ', $e->reasons)
                . "' rather than mentioning '{$expect}'";
        }
    }
};

// A single altered byte of content breaks the hash — and the hash is over the
// CANONICAL form, so a re-indented or re-ordered pack still verifies.
$refuses('a pack whose content was edited after signing', static function (array $m, array $c): array {
    $c['rules'][0]['rounding'] = 'down';

    return [$m, $c];
}, 'content hash does not match');

$refuses('a pack signed by a key this install does not hold', static function (array $m, array $c): array {
    $m['integrity']['public_key_id'] = 'some-other-key';

    return [$m, $c];
}, 'does not hold');

$refuses('a pack countersigned by the SAME key as the signature',
    static function (array $m, array $c): array {
        $m['integrity']['countersignature'] = $m['integrity']['signature'];
        $m['integrity']['countersign_key_id'] = $m['integrity']['public_key_id'];

        return [$m, $c];
    }, 'same key');

$refuses('a pack needing a newer platform build', static function (array $m, array $c): array {
    $m['compatibility']['min_platform_version'] = '99.0.0';

    return [$m, $c];
}, 'needs at least');

// Content refusals, at parse rather than at signature.
foreach ([
    ['a rule with no source_reference', static function (array $m, array $c): array {
        $c['rules'][0]['source_reference'] = '';

        return [$m, $c];
    }, 'source_reference'],
    ['a slab table with no bands', static function (array $m, array $c): array {
        $c['slab_tables']['SYNTH-BANDS']['bands'] = [];

        return [$m, $c];
    }, 'no bands'],
    ['a manifest missing a verification field', static function (array $m, array $c): array {
        $m['verification']['reviewed_by'] = '';

        return [$m, $c];
    }, 'verification.reviewed_by'],
    ['a manifest with no primary source', static function (array $m, array $c): array {
        $m['verification']['primary_sources'] = [];

        return [$m, $c];
    }, 'primary_sources'],
    ['a pack carrying PHP rather than an expression', static function (array $m, array $c): array {
        $c['rules'][0]['calculation'] = '<?php function pt($x) { return $x * 0.02; }';

        return [$m, $c];
    }, 'carries code'],
    ['a manifest from an unknown schema version', static function (array $m, array $c): array {
        $m['manifest_schema_version'] = 2;

        return [$m, $c];
    }, 'knows only 1'],
] as [$what, $mutate, $expect]) {
    try {
        $s = syntheticPack();
        [$manifest, $content] = $mutate($s['manifest'], $s['content']);
        RulePack::of($manifest, $content);
        $problems[] = "{$what} was ACCEPTED";
    } catch (PackRejected $e) {
        if (!$e->mentions($expect)) {
            $problems[] = "{$what} was refused for '" . implode('; ', $e->reasons)
                . "' rather than mentioning '{$expect}'";
        }
    }
}

// Canonicalisation: the same pack, keys in a different order, must hash the same.
$reordered = array_reverse($synthetic['content'], true);

if (Jcs::hash($reordered) !== Jcs::hash($synthetic['content'])) {
    $problems[] = 'the same content in a different key order hashes differently, so the signature is over a '
        . 'formatting choice rather than over the content';
}

if (Jcs::canonicalise(['b' => 1, 'a' => 2]) !== '{"a":2,"b":1}') {
    $problems[] = 'JCS key ordering is wrong';
}

// A REVOKED key stops future activation and does not un-explain history.
$revoked = syntheticPack();
$revoked['keys']->revoke('synthetic-signer', 'compromised in a drill');
$strict = new PackVerifier($revoked['keys'], '3.2.0');

try {
    $strict->verify($revoked['pack'], forActivation: true);
    $problems[] = 'a pack signed by a REVOKED key was accepted for activation';
} catch (PackRejected $e) {
    if (!$e->mentions('REVOKED')) {
        $problems[] = 'a revoked key was refused for the wrong reason';
    }
}

try {
    $strict->verify($revoked['pack'], forActivation: false);
} catch (PackRejected $e) {
    $problems[] = 'a revoked key made an ALREADY-ACTIVATED pack unverifiable: ' . implode('; ', $e->reasons)
        . ' — revocation must not retroactively make three years of payslips unexplainable (R-XVII-120)';
}

// And there is no way to turn verification off (R-XVII-027).
foreach ((new ReflectionClass(PackVerifier::class))->getMethods() as $method) {
    foreach ($method->getParameters() as $parameter) {
        if (preg_match('/force|skip|unsafe|disable|insecure/i', $parameter->getName()) === 1) {
            $problems[] = "PackVerifier::{$method->getName()}() takes a \${$parameter->getName()} parameter. "
                . 'R-XVII-027: no flag may disable signature verification, in any edition, on any '
                . 'deployment. An option that exists is an option somebody sets on the 30th';
        }
    }
}

$results[] = [
    'name' => 'The pack signature verifies, and every forgery, edit and omission is refused outright',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'Ed25519 over SHA-256(JCS(manifest) ‖ content_hash), two signatures by two distinct keys; '
        . '12 refusal cases each fire for their own stated reason; re-ordered keys hash identically; a '
        . 'revoked key blocks activation but keeps history readable; and no method takes a force flag',
];

// =============================================================================
// 3. A PACK CHANGE REACHES A TEST INSTALL WITH NO APPLICATION DEPLOYMENT
// =============================================================================

$problems = [];

// The amendment: a new pack VERSION, effective later, changing the bands. No PHP
// changes, no migration, no restart — the same running engine.
$amended = syntheticPack([
    'version' => '2026.2',
    'effective_from' => '2027-01-01',
    'content' => (static function (array $c): array {
        $c['slab_tables']['SYNTH-BANDS']['bands'][2]['rate'] = '8';
        $c['golden_cases'][0]['expect_amount'] = '1000.00';

        return $c;
    })($synthetic['content']),
]);

$store->put($amended['pack']);

$before = $engine->compute('PT', 'E-1', 'EST-SYNTH', new DateTimeImmutable('2026-11-30'),
    ['CMP-A' => Money::of('30000.00')], ['CMP-A' => ['is_synth_base' => true]]);
$after = $engine->compute('PT', 'E-1', 'EST-SYNTH', new DateTimeImmutable('2027-02-28'),
    ['CMP-A' => Money::of('30000.00')], ['CMP-A' => ['is_synth_base' => true]]);

if ($before->amount->toDecimalString() !== '700.00') {
    $problems[] = 'before the amendment the figure is ' . $before->amount->toDecimalString() . ', expected 700.00';
}

if ($after->amount->toDecimalString() !== '1000.00') {
    $problems[] = 'after the amendment the figure is ' . $after->amount->toDecimalString()
        . ', expected 1000.00 — a pack change that does not change the answer has not been applied';
}

if ($before->packVersion === $after->packVersion) {
    $problems[] = 'both periods resolved the same pack version, so the amendment did not take effect on its '
        . 'own date';
}

// The amendment must NOT reach back. A pack effective 1 January must not change
// November, which is spine S4 applied to law.
if ($before->packVersion !== '2026.1') {
    $problems[] = 'November resolved pack version ' . $before->packVersion . ' rather than the one in force '
        . 'in November — a pack published later must not reprice a period already paid';
}

// And a signed version can never be rewritten in place.
try {
    $store->put($synthetic['pack']);
    $problems[] = 'a signed pack version was written twice; R-XVII-007 makes a version immutable and a '
        . 'correction a new version';
} catch (PackRejected) {
    // Refused, as it must be.
}

// The store re-verifies on every load, so an edit AFTER import is caught.
$tampered = syntheticPack();
$tamperedStore = new PackStore($verifier);
$tamperedStore->put($tampered['pack']);
$forged = RulePack::of(
    array_replace_recursive($tampered['manifest'], ['integrity' => ['content_hash' => str_repeat('0', 64)]]),
    $tampered['content'],
);

try {
    $verifier->verifyContentHash($forged);
    $problems[] = 'a pack whose stored content no longer matches its hash loaded without complaint';
} catch (PackRejected) {
    // Refused, as it must be.
}

$results[] = [
    'name' => 'A pack change reaches a test install with no application deployment',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'an amended band table shipped as version 2026.2 effective 1 January changes the January '
        . 'figure from ₹700.00 to ₹1,000.00 with no PHP changed, no migration and no restart — and does NOT '
        . 'change November, because resolution is by the statutory effective date rather than by which pack '
        . 'was installed last',
];

// =============================================================================
// 4. EVERY FIGURE RECORDS THE PACK VERSION AND THE RESOLVED VALUES USED
// =============================================================================

$problems = [];
$row = $engine->compute('PT', 'E-1', 'EST-SYNTH', new DateTimeImmutable('2026-11-30'),
    ['CMP-A' => Money::of('30000.00'), 'CMP-B' => Money::of('5000.00')],
    ['CMP-A' => ['is_synth_base' => true], 'CMP-B' => []]);

foreach ([
    'packVersion' => $row->packVersion,
    'packContentHash' => $row->packContentHash,
    'packKeyId' => $row->packKeyId,
    'ruleCode' => $row->ruleCode,
    'wageBaseFlag' => $row->wageBaseFlag,
    'sourceReference' => $row->sourceReference,
    'rounding' => $row->rounding,
] as $field => $value) {
    if (trim((string) $value) === '') {
        $problems[] = "the figure carries no {$field}, so it cannot be re-derived offline (R-XVII-119)";
    }
}

if ($row->baseComposition !== ['CMP-A']) {
    $problems[] = 'the base composition is ' . json_encode($row->baseComposition)
        . ' — the snapshot must name WHICH components summed to the base, or an auditor sees a total and '
        . 'has to guess what went into it';
}

if (!$row->isComplete()) {
    $problems[] = 'the row does not consider itself complete';
}

if (!str_contains($row->explain(), 'SYNTHETIC')) {
    $problems[] = 'a figure derived from a synthetic pack is not stamped as such on the explanation. A test '
        . 'fixture that reads like content is exactly what must not reach a payslip';
}

// A statute that APPLIES and cannot be computed REFUSES rather than deducting nothing.
$emptyStore = new PackStore($verifier);
$blindApplicability = new Applicability();
$blindApplicability->declare('PT', 'EST-SYNTH', 'IN-ZZ-SYNTH', new DateTimeImmutable('2026-04-01'), true,
    'covered', 'registration');

try {
    (new StatutoryEngine($emptyStore, $blindApplicability))->compute('PT', 'E-1', 'EST-SYNTH',
        new DateTimeImmutable('2026-11-30'), ['CMP-A' => Money::of('30000.00')],
        ['CMP-A' => ['is_synth_base' => true]]);
    $problems[] = 'a bound statute with NO pack computed something instead of refusing. Every SmartPRS 2 '
        . 'path that cannot resolve a statutory value returns zero, and a zero deduction that should have '
        . 'been ₹1,800 is indistinguishable on the payslip from a correct one';
} catch (SmartPRS\Platform\Statutory\StatutoryUnresolved) {
    // Refused, as it must be.
}

// And an UNDETERMINED statute is not "does not apply".
$undetermined = $engine->compute('PT', 'E-1', 'EST-NOBODY-ASKED', new DateTimeImmutable('2026-11-30'),
    ['CMP-A' => Money::of('30000.00')], ['CMP-A' => ['is_synth_base' => true]]);

if (!$undetermined->applicability->undetermined) {
    $problems[] = 'an establishment with no determination reported as "does not apply". Those are the same '
        . 'zero on a payslip and completely different facts on an inspection';
}

if (!str_contains($undetermined->explain(), 'NOT DETERMINED')) {
    $problems[] = 'the nil line does not say WHY it is nil';
}

// The golden cases inside the signed envelope run against the REAL engine.
$failures = (new GoldenReplay($engine))->run($synthetic['pack']);

if ($failures !== []) {
    $problems[] = 'the pack\'s own signed golden cases fail on this build: ' . implode('; ', $failures);
}

// A pack with NO golden cases is refused rather than passing an empty suite.
$noEvidence = syntheticPack(['content' => (static function (array $c): array {
    $c['golden_cases'] = [];

    return $c;
})($synthetic['content'])]);

if ((new GoldenReplay($engine))->run($noEvidence['pack']) === []) {
    $problems[] = 'a pack carrying NO golden cases replayed clean. An empty suite reports success louder '
        . 'than a failing one';
}

$results[] = [
    'name' => 'Every statutory figure records the pack version and the resolved values used',
    'ran' => true,
    'problems' => $problems,
    'detail' => 'each figure carries its pack version, content hash, signing key, rule code, wage-base flag, '
        . 'the component codes that composed the base, the rounding and the source reference; a bound statute '
        . 'with no pack REFUSES rather than deducting nothing; an undetermined one says so; and the pack\'s '
        . 'signed golden cases replay through the same engine the payslip calls',
];

// =============================================================================
// 5. EXACTLY ONE IMPLEMENTATION OF EACH STATUTORY CALCULATION
// =============================================================================

$problems = [];
$implementations = [];
$offenders = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $path = str_replace($root . '/', '', $file->getPathname());
    $source = (string) file_get_contents($file->getPathname());
    $code = (string) preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);

    if (preg_match('/function\s+compute\s*\([^)]*string\s+\$head/s', $code) === 1) {
        $implementations[] = $path;
    }

    // No statutory VALUE anywhere in application code (R-XVII-004). The shapes
    // are the ones SmartPRS 2 actually carries: a wage ceiling, a contribution
    // rate, and a slab boundary, hard-coded in five places that disagree.
    if (str_contains($path, '/Platform/Spx/StatutoryKeys.php')) {
        continue;       // type declarations, no values — its own docblock says so
    }

    foreach (token_get_all($source) as $token) {
        if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $literal = trim($token[1], "'\"");

        // A rate or ceiling written as a bare statutory-looking number next to a
        // statutory identifier. Kept narrow on purpose: a wide pattern that
        // matches prose gets suppressed, and a suppressed check is off.
        if (preg_match('/^(?:PF|EPS|EDLI|ESI|PT|LWF|TDS)_[A-Z_]*(RATE|CEILING|CAP|SLAB|THRESHOLD)$/', $literal)
            === 1 && !str_contains($path, '/Platform/Statutory/')
        ) {
            $offenders[] = "{$path} names the statutory value '{$literal}' outside Platform\\Statutory";
        }
    }
}

if (count($implementations) !== 1) {
    $problems[] = 'the statutory calculation is implemented ' . count($implementations) . ' time(s): '
        . ($implementations === [] ? 'nowhere' : implode(', ', $implementations))
        . '. R-XVII-122 says exactly one, and SmartPRS 2 computes PT in four places that disagree — with '
        . 'its only behavioural test being a hand-copied duplicate that stopped testing production the '
        . 'first time somebody forgot to re-copy it';
}

foreach ($offenders as $offender) {
    $problems[] = 'R-XVII-004: ' . $offender;
}

// The single implementation must also be the one the golden cases run through —
// otherwise "one implementation" is true and irrelevant.
$reflection = new ReflectionClass(GoldenReplay::class);

if (!str_contains((string) file_get_contents($reflection->getFileName()), '$this->engine->compute(')) {
    $problems[] = 'the golden replay does not call StatutoryEngine::compute(), so the pack\'s evidence tests '
        . 'something other than what the payslip calls (R-XVII-125)';
}

$results[] = [
    'name' => 'There is exactly one implementation of each statutory calculation, asserted by a CI check',
    'ran' => true,
    'problems' => $problems,
    'detail' => count($implementations) . ' implementation under src/, no statutory rate or ceiling '
        . 'identifier outside Platform\\Statutory, and the pack\'s golden cases run through that same method '
        . 'rather than through a copy of it',
];

// =============================================================================
// PART XXXII's PHASE 6 SCOPE — what is built, and what waits on the appointment
// =============================================================================
//
// The five criteria above are the phase's exit conditions. This section walks
// the SCOPE list Part XXXII gives the phase, so "4 of 5 proven" cannot be read
// as "4/5 of the phase". Each item RUNS; none is asserted by inspection.

$scope = [];

// ---- The floor: company policy may improve, never reduce --------------------

$problems = [];
$floorDeclared = [
    'floor' => Money::of('700.00'),
    'direction' => SmartPRS\Platform\Statutory\StatutoryFloor::HIGHER_IS_BETTER,
    'severity' => SmartPRS\Platform\Statutory\StatutoryFloor::EXCEPTION_REQUIRED,
    'rule_code' => 'SYNTH-PT-001',
    'source_reference' => 'SYNTHETIC',
];

if (!SmartPRS\Platform\Statutory\StatutoryFloor::check(Money::of('900.00'), $floorDeclared)['ok']) {
    $problems[] = 'a policy ABOVE the floor was refused; the baseline is a floor, not a target';
}

$below = SmartPRS\Platform\Statutory\StatutoryFloor::check(Money::of('500.00'), $floorDeclared);

if ($below['ok']) {
    $problems[] = 'a policy BELOW the floor was accepted with no recorded exception';
}

$exception = new SmartPRS\Platform\Statutory\ComplianceException(
    ruleCode: 'SYNTH-PT-001',
    legalBasis: 'The synthetic establishment operates under a transitional arrangement recorded for the '
        . 'purpose of exercising this mechanism, and states no law.',
    statutoryReference: 'SYNTHETIC reference',
    evidenceReference: 'DOC-SYNTH-001',
    authorisedBy: 'u-compliance-head',
    effectiveFrom: new DateTimeImmutable('2026-04-01'),
    effectiveTo: new DateTimeImmutable('2027-03-31'),
    reviewOn: new DateTimeImmutable('2026-10-01'),
    approvedBy: 'u-md',
);

if (!SmartPRS\Platform\Statutory\StatutoryFloor::check(Money::of('500.00'), $floorDeclared, $exception)['ok']) {
    $problems[] = 'a properly recorded exception did not authorise the departure it was recorded for';
}

// An exception for a DIFFERENT rule is not a general permission.
$other = SmartPRS\Platform\Statutory\StatutoryFloor::check(
    Money::of('500.00'),
    ['rule_code' => 'SYNTH-OTHER'] + $floorDeclared,
    $exception,
);

if ($other['ok']) {
    $problems[] = 'an exception recorded for one rule authorised a departure from another';
}

// A BLOCK has no exception path at any permission level.
$blocked = SmartPRS\Platform\Statutory\StatutoryFloor::check(
    Money::of('500.00'),
    ['severity' => SmartPRS\Platform\Statutory\StatutoryFloor::BLOCK] + $floorDeclared,
    $exception,
);

if ($blocked['ok']) {
    $problems[] = 'a BLOCK severity was softened by an exception. R-XVII-112: the resolution path for a '
        . 'block believed wrong is APPLICABILITY with a basis and evidence, which is auditable — not a '
        . 'severity somebody turned down';
}

foreach ([
    'an exception with a one-word legal basis' => static fn () => new SmartPRS\Platform\Statutory\ComplianceException(
        'R', 'n/a', 'ref', 'ev', 'u-a', new DateTimeImmutable('2026-04-01'),
        new DateTimeImmutable('2027-03-31'), new DateTimeImmutable('2026-10-01'), 'u-b'),
    'an OPEN-ENDED exception' => static fn () => new SmartPRS\Platform\Statutory\ComplianceException(
        'R', str_repeat('a basis long enough to be a basis. ', 3), 'ref', 'ev', 'u-a',
        new DateTimeImmutable('2027-04-01'), new DateTimeImmutable('2026-03-31'),
        new DateTimeImmutable('2026-10-01'), 'u-b'),
    'an exception authorised and approved by the same person' => static fn () => new SmartPRS\Platform\Statutory\ComplianceException(
        'R', str_repeat('a basis long enough to be a basis. ', 3), 'ref', 'ev', 'u-a',
        new DateTimeImmutable('2026-04-01'), new DateTimeImmutable('2027-03-31'),
        new DateTimeImmutable('2026-10-01'), 'u-a'),
] as $what => $build) {
    try {
        $build();
        $problems[] = "{$what} was accepted";
    } catch (InvalidArgumentException) {
        // Refused, as it must be.
    }
}

if ($exception->isActive(new DateTimeImmutable('2027-06-01'))) {
    $problems[] = 'an EXPIRED exception still authorises. R-XVII-113: expiry re-raises the finding, or a '
        . 'temporary departure becomes the way things are done';
}

$scope[] = ['Statutory baseline versus company policy (§XVII.8)', $problems,
    'a policy above the floor passes; below it needs a recorded exception naming that rule; a BLOCK has no '
    . 'exception path at any permission level; and an exception refuses a thin basis, an open-ended range '
    . 'and one person acting as both authoriser and approver'];

// ---- The statutory calendar: materialised, never authored -------------------

$problems = [];
$calendarApplicability = new Applicability();
$calendarApplicability->declare('PT', 'EST-SYNTH', 'IN-ZZ-SYNTH', new DateTimeImmutable('2026-04-01'),
    true, 'declared covered', 'synthetic registration');
$calendarApplicability->declare('PT', 'EST-OUT', 'IN-ZZ-SYNTH', new DateTimeImmutable('2026-04-01'),
    false, 'outside coverage', 'basis on file');

$calendar = new SmartPRS\Platform\Statutory\ObligationCalendar($store, $calendarApplicability);
$materialised = $calendar->materialise(
    [
        ['code' => 'EST-SYNTH', 'head' => 'PT', 'registration' => 'REG-1'],
        ['code' => 'EST-OUT', 'head' => 'PT', 'registration' => 'REG-2'],
        ['code' => 'EST-UNASKED', 'head' => 'PT', 'registration' => 'REG-3'],
    ],
    ['2026-10', '2026-11'],
    new DateTimeImmutable('2026-11-30'),
    ['SYNTH-PT-RETURN' => 'u-compliance'],
);

if (count($materialised['obligations']) !== 2) {
    $problems[] = 'the calendar materialised ' . count($materialised['obligations'])
        . ' obligation(s); the covered establishment across two periods is two';
}

$undeterminedReported = false;

foreach ($materialised['problems'] as $problem) {
    if (str_contains($problem, 'EST-UNASKED')) {
        $undeterminedReported = true;
    }
}

if (!$undeterminedReported) {
    $problems[] = 'an establishment nobody has assessed produced no obligations AND no warning, so an empty '
        . 'compliance calendar cannot be distinguished from a complete one';
}

$unowned = $calendar->materialise(
    [['code' => 'EST-SYNTH', 'head' => 'PT', 'registration' => 'REG-1']],
    ['2026-10'], new DateTimeImmutable('2026-11-30'), [],
);

if ($unowned['obligations'] !== [] || $unowned['problems'] === []) {
    $problems[] = 'an obligation whose owner role resolved to nobody was created anyway. SmartPRS 2 sends '
        . 'its compliance digest to nobody and never says so';
}

$obligation = $materialised['obligations'][0] ?? null;

if ($obligation === null) {
    $problems[] = 'no obligation to exercise the state machine against';
} else {
    if ($obligation->dueOn()->format('Y-m-d') !== '2026-11-15') {
        $problems[] = 'the due date is ' . $obligation->dueOn()->format('Y-m-d')
            . ', expected 15 days after the October period ends';
    }

    // R-XVII-129: `filed` is unreachable except by a recorded act with evidence.
    try {
        $obligation->file('u-compliance', 'ACK-1', new DateTimeImmutable('2026-11-10'));
        $problems[] = 'a DUE obligation was filed without ever being prepared';
    } catch (InvalidArgumentException) {
        // Refused, as it must be.
    }

    $obligation->prepare(['the control total and the rows disagree by ₹0.01']);

    if ($obligation->state() !== SmartPRS\Platform\Statutory\Obligation::PREPARED_FAILED) {
        $problems[] = 'a preparation whose reconciliation failed did not enter prepared_failed';
    }

    $obligation->prepare();

    try {
        $obligation->file('u-compliance', '   ', new DateTimeImmutable('2026-11-10'));
        $problems[] = 'an obligation was filed with NO evidence. DEFECT-F-07 is six quarters showing '
            . '"Filed" that nobody filed, and it is reachable exactly here';
    } catch (InvalidArgumentException) {
        // Refused, as it must be.
    }

    $obligation->file('u-compliance', 'ACK-2026-11-0042', new DateTimeImmutable('2026-11-10'));
    $obligation->acknowledge('ACK-2026-11-0042', new DateTimeImmutable('2026-11-12'));

    if ($obligation->state() !== SmartPRS\Platform\Statutory\Obligation::ACKNOWLEDGED) {
        $problems[] = 'the obligation did not reach acknowledged through the recorded acts';
    }

    if ($obligation->evidence() === null) {
        $problems[] = 'a filed obligation carries no evidence';
    }
}

$scope[] = ['The statutory calendar (§XVII.11)', $problems,
    'obligations are materialised from the pack crossed with applicability — none typed, none compiled in; '
    . 'a covered establishment generates rows, one outside coverage generates none, and one nobody has '
    . 'assessed generates a WARNING rather than silence; an unowned obligation is refused; and `filed` is '
    . 'reachable only from `prepared` by a named person carrying evidence'];

// ---- Returns: from stored rows, or not produced at all ----------------------

$problems = [];
$storedRows = [
    $engine->compute('PT', 'E-1', 'EST-SYNTH', new DateTimeImmutable('2026-11-30'),
        ['CMP-A' => Money::of('30000.00')], ['CMP-A' => ['is_synth_base' => true]]),
    $engine->compute('PT', 'E-2', 'EST-SYNTH', new DateTimeImmutable('2026-11-30'),
        ['CMP-A' => Money::of('50000.00')], ['CMP-A' => ['is_synth_base' => true]]),
];

// The synthetic pack must be REFUSED at the filing boundary, whatever else is
// true of it — which is the strongest statement this build can make about it.
$refusedSynthetic = SmartPRS\Platform\Statutory\ReturnFile::generate(
    'SYNTH-FORM-1', 'EST-SYNTH', '2026-11', 'PT', $storedRows);

if ($refusedSynthetic['ok']) {
    $problems[] = 'a return was generated from SYNTHETIC figures. A value that states no law must never '
        . 'reach a statutory filing, and this is the boundary that has to say so';
}

// So the return path itself is exercised against rows marked as real content
// would be. Everything else about them is unchanged.
$asReal = array_map(
    static fn (SmartPRS\Platform\Statutory\ContributionRow $r): SmartPRS\Platform\Statutory\ContributionRow
        => new SmartPRS\Platform\Statutory\ContributionRow(
            $r->head, $r->employmentId, $r->wageBase, $r->baseComposition, $r->wageBaseFlag, $r->amount,
            $r->ruleCode, $r->packKey, $r->packVersion, $r->packContentHash, $r->packKeyId,
            $r->packEffectiveFrom, $r->resolvedValues, $r->rounding, $r->applicability,
            $r->sourceReference, synthetic: false,
        ),
    $storedRows,
);

$produced = SmartPRS\Platform\Statutory\ReturnFile::generate(
    'SYNTH-FORM-1', 'EST-SYNTH', '2026-11', 'PT', $asReal, Money::of('2400.00'));

if (!$produced['ok']) {
    $problems[] = 'a clean return did not produce a file: ' . implode('; ', $produced['failures']);
}

if ($produced['hash'] === null) {
    $problems[] = 'the produced file was not hashed, so "what exactly did we file?" is unanswerable later';
}

if ($produced['rows'] !== 2) {
    $problems[] = 'the return covers ' . $produced['rows'] . ' row(s), expected the 2 stored for this '
        . 'registration and period';
}

// A control total that disagrees by one paisa does NOT produce a file.
$mismatch = SmartPRS\Platform\Statutory\ReturnFile::generate(
    'SYNTH-FORM-1', 'EST-SYNTH', '2026-11', 'PT', $asReal, Money::of('2400.01'));

if ($mismatch['ok'] || $mismatch['file'] !== null) {
    $problems[] = 'a return whose control total disagreed by ₹0.01 was still produced. R-XVII-131 step 6: '
        . 'if any assertion fails, DO NOT PRODUCE THE FILE — a file generated with a warning is a file '
        . 'filed with a warning';
}

// The population is the stored rows for THIS registration, not every employee.
$otherRegistration = SmartPRS\Platform\Statutory\ReturnFile::generate(
    'SYNTH-FORM-1', 'EST-ELSEWHERE', '2026-11', 'PT', $asReal);

if ($otherRegistration['ok']) {
    $problems[] = 'a return for a registration with no stored rows was produced anyway. SmartPRS 2 selects '
        . 'every non-deleted employee with no status, company or period filter and labels it with today\'s '
        . 'month (DEFECT-F-04)';
}

$scope[] = ['Returns, registers and file formats (§XVII.11.4)', $problems,
    'a return is bound from STORED contribution rows — never recomputed, never from CTC, never over an '
    . 'unfiltered set — is hashed and stored, refuses to exist when a control total disagrees by a paisa, '
    . 'covers exactly the population with rows for that registration and period, and refuses SYNTHETIC '
    . 'figures at the filing boundary whatever else is true of them'];

// =============================================================================

$failed = 0;
$notRun = 0;

foreach ($results as $index => $result) {
    if (!$result['ran']) {
        $notRun++;
        printf("\n  %d. \033[33mNOT PROVEN\033[0m  %s\n", $index + 1, $result['name']);
    } elseif ($result['problems'] !== []) {
        $failed++;
        printf("\n  %d. \033[31mFAIL      \033[0m  %s\n", $index + 1, $result['name']);
    } else {
        printf("\n  %d. \033[32mPASS      \033[0m  %s\n", $index + 1, $result['name']);
        echo "        {$result['detail']}\n";

        continue;
    }

    foreach ($result['problems'] as $problem) {
        echo "        └─ {$problem}\n";
    }
}

echo "\n  ── Part XXXII's Phase 6 scope, so \"4 of 5 criteria\" is not read as \"4/5 of the phase\" ──\n";

$scopeFailed = 0;

foreach ($scope as [$name, $problems, $detail]) {
    if ($problems === []) {
        printf("\n  \033[32m✓\033[0m  %s\n        %s\n", $name, $detail);

        continue;
    }

    $scopeFailed++;
    printf("\n  \033[31m✗\033[0m  %s\n", $name);

    foreach ($problems as $problem) {
        echo "        └─ {$problem}\n";
    }
}

printf(
    "\n  \033[33m·\033[0m  The per-statute CONTENT (PF, ESI, PT, LWF, TDS, gratuity, bonus, minimum wages,\n"
    . "     protected leave) is not started, and will not be until a compliance owner is named.\n"
    . "     The engine that runs them is generic and built: a statute is a pack, not a release.\n",
);

printf(
    "\n%s  %d of %d Phase 6 exit criteria proven%s\n",
    $failed === 0 && $notRun === 0 ? 'PASS' : 'FAIL',
    count($results) - $failed - $notRun,
    count($results),
    $notRun === 0 ? '' : ", {$notRun} NOT PROVEN (blocked on the compliance-owner appointment)",
);

exit($failed === 0 && $notRun === 0 && $scopeFailed === 0 ? 0 : 1);
