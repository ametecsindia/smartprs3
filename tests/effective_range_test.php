<?php

declare(strict_types=1);

use SmartPRS\Platform\Support\EffectiveRange;

group('EffectiveRange');

$range = EffectiveRange::fromInclusive('2026-04-01', '2026-09-30');

// --- boundaries: the exact effective_from and effective_to ------------------

ok($range->contains('2026-04-01'), 'contains the exact effective_from (inclusive)');
ok($range->contains('2026-09-30'), 'contains the exact effective_to (inclusive)');
ok(!$range->contains('2026-03-31'), 'excludes the day before effective_from');
ok(!$range->contains('2026-10-01'), 'excludes the day after effective_to');
ok($range->contains('2026-08-15'), 'contains a date in the middle');

$open = EffectiveRange::openEndedFrom('2026-04-01');
ok($open->contains('2026-04-01'), 'open-ended contains its own effective_from');
ok($open->contains('2999-12-31'), 'open-ended contains any later date');
ok(!$open->contains('2026-03-31'), 'open-ended still excludes before effective_from');
ok($open->isOpenEnded(), 'isOpenEnded');
same(null, $open->effectiveTo(), 'an open-ended range persists effective_to as NULL');
same('2026-09-30', $range->effectiveTo()?->format('Y-m-d'), 'a closed range persists the inclusive effective_to');

// A single-day range is legal: from === to.
$oneDay = EffectiveRange::fromInclusive('2026-04-01', '2026-04-01');
ok($oneDay->contains('2026-04-01'), 'a one-day range contains its day');
ok(!$oneDay->contains('2026-04-02'), 'and nothing else');

throws(
    InvalidArgumentException::class,
    static fn () => EffectiveRange::fromInclusive('2026-04-01', '2026-03-31'),
    'effective_to before effective_from is rejected',
);

// A time component is irrelevant: dates are DATE, not instants (S16).
ok(
    EffectiveRange::fromInclusive(new DateTimeImmutable('2026-04-01 23:59:59'))->contains('2026-04-01'),
    'a time part on effective_from is normalised away',
);
ok(
    $range->contains(new DateTimeImmutable('2026-09-30 18:30:00')),
    'a time part on the queried date is normalised away',
);

// --- overlap ----------------------------------------------------------------

ok($range->overlaps(EffectiveRange::fromInclusive('2026-09-30', '2026-12-31')), 'overlap of exactly one day');
ok(!$range->overlaps(EffectiveRange::fromInclusive('2026-10-01', '2026-12-31')), 'adjacent ranges do not overlap');
ok($range->overlaps(EffectiveRange::openEndedFrom('2026-09-30')), 'an open-ended successor overlapping by a day');
ok(!$range->overlaps(EffectiveRange::openEndedFrom('2026-10-01')), 'an open-ended successor starting the next day');
ok($range->overlaps($range), 'a range overlaps itself');
ok(
    EffectiveRange::openEndedFrom('2026-01-01')->overlaps(EffectiveRange::openEndedFrom('2030-01-01')),
    'two open-ended ranges always overlap — which is why publishing must close the predecessor',
);
ok(
    $range->overlaps(EffectiveRange::fromInclusive('2026-01-01', '2026-12-31')),
    'a fully containing range overlaps',
);

// --- abutment ---------------------------------------------------------------

ok(
    $range->abuts(EffectiveRange::fromInclusive('2026-10-01', '2027-03-31')),
    'the successor starting the day after abuts',
);
ok(
    EffectiveRange::fromInclusive('2026-01-01', '2026-03-31')->abuts($range),
    'abutment is symmetric',
);
ok(!$range->abuts(EffectiveRange::fromInclusive('2026-10-02', '2027-03-31')), 'a one-day gap does not abut');
ok(!$range->abuts(EffectiveRange::fromInclusive('2026-09-30', '2027-03-31')), 'an overlap does not abut');
ok(!$open->abuts(EffectiveRange::openEndedFrom('2030-01-01')), 'an open-ended range abuts nothing after it');

// --- supersession: S4 "close the predecessor at successor.from - 1 day" -----

$closed = EffectiveRange::openEndedFrom('2026-04-01')->closedAt('2026-10-01');
same('2026-09-30', $closed->effectiveTo()?->format('Y-m-d'), 'closing at a successor sets the day before');
ok($closed->abuts(EffectiveRange::openEndedFrom('2026-10-01')), 'predecessor and successor tile with no gap');
ok(!$closed->overlaps(EffectiveRange::openEndedFrom('2026-10-01')), 'and with no overlap');
throws(
    InvalidArgumentException::class,
    static fn () => $closed->closedAt('2026-04-01'),
    'a successor may not start on its predecessor',
);

same('2026-04-01 → 2026-09-30', (string) $range, 'renders as a closed range');
same('2026-04-01 → ', (string) $open, 'renders as open-ended');
