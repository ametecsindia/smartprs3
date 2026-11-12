<?php

declare(strict_types=1);

use SmartPRS\Platform\Config\ArtefactResolver;
use SmartPRS\Platform\Config\ConfigVersionCandidate;
use SmartPRS\Platform\Support\AmbiguousConfiguration;
use SmartPRS\Platform\Support\EffectiveRange;
use SmartPRS\Platform\Support\Scope;
use SmartPRS\Platform\Support\UnresolvedConfiguration;

group('ArtefactResolver');

$version = static function (
    int $no,
    string $from,
    ?string $to,
    array $scope = [],
    int $priority = 0,
    mixed $payload = null,
): ConfigVersionCandidate {
    return new ConfigVersionCandidate(
        artefactType: 'rule',
        artefactKey: 'LATE-POLICY',
        versionId: "cv-{$no}",
        versionNo: $no,
        scope: $scope === [] ? Scope::tenantDefault() : Scope::of($scope),
        priority: $priority,
        range: EffectiveRange::fromInclusive($from, $to),
        payload: $payload,
    );
};

$subject = ['branch' => 'Hyderabad', 'employee_category' => 'FIELD', 'department' => 'Collections'];

// ---------------------------------------------------------------------------
// The August-payroll-after-an-October-policy-change case (spine S4, S11, V.8.4).
// Late Policy V1 ran from April. V2 was published in October, which closed V1 at
// 30 September. Payroll for August must still resolve V1.
// ---------------------------------------------------------------------------

$resolver = new ArtefactResolver([
    $version(1, '2026-04-01', '2026-09-30', payload: 'V1: late > 10 min, 3 times = half day'),
    $version(2, '2026-10-01', null, payload: 'V2: late > 5 min, 2 times = half day'),
]);

$august = $resolver->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-08-15'));
same(1, $august->versionNo, 'August payroll resolves V1, not the October V2');
same('V1: late > 10 min, 3 times = half day', $august->payload, 'and gets V1s payload');

same(
    1,
    $resolver->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-09-30'))->versionNo,
    'the last day of V1 still resolves V1',
);
same(
    2,
    $resolver->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-10-01'))->versionNo,
    'the first day of V2 resolves V2',
);
same(
    2,
    $resolver->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-12-31'))->versionNo,
    'a later date resolves the open-ended V2',
);

$resolver->assertNoOverlap();
ok(true, 'a properly superseded pair has no overlap');

// ---------------------------------------------------------------------------
// Overlapping versions are rejected.
// ---------------------------------------------------------------------------

$overlapping = new ArtefactResolver([
    $version(1, '2026-04-01', null),            // never closed
    $version(2, '2026-10-01', null),            // published on top of it
]);

throws(
    AmbiguousConfiguration::class,
    static fn () => $overlapping->assertNoOverlap(),
    'publishing V2 without closing V1 is rejected at publish time (S4, V.8.2 A3)',
);
throws(
    AmbiguousConfiguration::class,
    static fn () => $overlapping->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-10-15')),
    'and resolving inside the overlap is ambiguous, never a coin toss',
);

// An overlap on a *different* scope is legal — that is what scoping is for.
(new ArtefactResolver([
    $version(1, '2026-04-01', null),
    $version(2, '2026-04-01', null, ['branch' => ['Hyderabad']]),
]))->assertNoOverlap();
ok(true, 'two versions overlapping on different scopes are not an overlap error');

// ---------------------------------------------------------------------------
// Specificity, then priority, then a blocked publication.
// ---------------------------------------------------------------------------

$scoped = new ArtefactResolver([
    $version(1, '2026-04-01', null),                                    // tenant default, 0
    $version(2, '2026-04-01', null, ['branch' => ['Hyderabad']]),       // 180
    $version(3, '2026-04-01', null, ['department' => ['Collections']]), // 400
]);

same(
    3,
    $scoped->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-08-15'))->versionNo,
    'the most specific matching version wins',
);
same(
    1,
    $scoped->resolve('rule', 'LATE-POLICY', ['branch' => 'Chennai'], new DateTimeImmutable('2026-08-15'))->versionNo,
    'a subject matching only the tenant default falls back to it',
);

$byPriority = new ArtefactResolver([
    $version(1, '2026-04-01', null, ['branch' => ['Hyderabad']], priority: 10),
    $version(2, '2026-04-01', null, ['employee_category' => ['FIELD']], priority: 10),
]);
// branch = 180, employee_category = 320: specificity settles it, priority is not consulted.
same(
    2,
    $byPriority->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-08-15'))->versionNo,
    'specificity is consulted before priority',
);

$sameSpecificity = new ArtefactResolver([
    $version(1, '2026-04-01', null, ['branch' => ['Hyderabad']], priority: 10),
    $version(2, '2026-04-01', null, ['branch' => ['Hyderabad', 'Chennai']], priority: 50),
]);
same(
    2,
    $sameSpecificity->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-08-15'))->versionNo,
    'equal specificity is broken by the declared priority',
);

// ---------------------------------------------------------------------------
// An ambiguous pair throws, naming both.
// ---------------------------------------------------------------------------

$ambiguous = new ArtefactResolver([
    // state(140) + branch(180) = 320, and employee_category = 320. Same priority.
    $version(7, '2026-04-01', null, ['state' => ['TG'], 'branch' => ['Hyderabad']], priority: 100),
    $version(9, '2026-04-01', null, ['employee_category' => ['FIELD']], priority: 100),
]);

try {
    $ambiguous->resolve(
        'rule',
        'LATE-POLICY',
        $subject + ['state' => 'TG'],
        new DateTimeImmutable('2026-08-15'),
    );
    ok(false, 'expected an AmbiguousConfiguration');
} catch (AmbiguousConfiguration $e) {
    ok(str_contains($e->getMessage(), 'v7'), 'the exception names the first candidate');
    ok(str_contains($e->getMessage(), 'v9'), 'the exception names the second candidate');
    ok(str_contains($e->getMessage(), 'specificity 320'), 'and states why they tie');
    ok(str_contains($e->getMessage(), 'blocked'), 'and says publication is blocked');
}

// ---------------------------------------------------------------------------
// Zero matches is a named domain error, never a silent default (V.5.3).
// ---------------------------------------------------------------------------

throws(
    UnresolvedConfiguration::class,
    static fn () => $resolver->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-01-01')),
    'a date before any version is effective is an error, not a default',
);
throws(
    UnresolvedConfiguration::class,
    static fn () => $resolver->resolve('rule', 'NO-SUCH-KEY', $subject, new DateTimeImmutable('2026-08-15')),
    'an unknown artefact key is an error',
);
throws(
    UnresolvedConfiguration::class,
    static fn () => $resolver->resolve('formula', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-08-15')),
    'the artefact type is part of the lookup',
);
throws(
    UnresolvedConfiguration::class,
    static fn () => (new ArtefactResolver([
        $version(1, '2026-04-01', null, ['branch' => ['Chennai']]),
    ]))->resolve('rule', 'LATE-POLICY', $subject, new DateTimeImmutable('2026-08-15')),
    'a subject matching no scope is an error',
);
