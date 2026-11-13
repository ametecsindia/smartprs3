<?php

declare(strict_types=1);

use SmartPRS\Platform\Attendance\DaySettlement;
use SmartPRS\Platform\Attendance\Punch;
use SmartPRS\Surface\Attendance;

group('attendance_day_window');

// Owner, 13 Nov 2026: a QR punch must count for the day, and late login comes from the FIRST punch of the day.
// A 09:00–18:00 shift used to read punches only between 07:00 and 22:00, so a 23:55 scan left the day without a First In.
$day = '2026-11-12';
$w = Attendance::windowFor($day, new DateTimeImmutable("$day 09:00"), new DateTimeImmutable("$day 18:00"), 10);
$p = static fn (string $t, string $d): Punch => new Punch('E1', new DateTimeImmutable("$day $t"), $d, 'qr');

$late = DaySettlement::settle($w, 'E1', [$p('23:55:05', Punch::IN), $p('23:57:27', Punch::OUT)]);
same(0, count($late->outsideWindow), 'no punch of the date is dropped');
same('23:55', $late->sessions[0]['in']->at->format('H:i'), 'a 23:55 punch on a day shift is that day\'s First In');
ok($w->contains($p('00:00:01', Punch::IN)) && $w->contains($p('06:30', Punch::IN)), 'early-morning punches of the same date count too');

$onTime = DaySettlement::settle($w, 'E1', [$p('09:05', Punch::IN), $p('18:10', Punch::OUT)]);
same(0, $onTime->lateMinutes, 'inside grace: not late');
$tardy = DaySettlement::settle($w, 'E1', [$p('09:40', Punch::IN), $p('09:45', Punch::IN), $p('18:00', Punch::OUT)]);
ok($tardy->lateMinutes >= 30, 'late login is read from the FIRST IN of the day (09:40), not a later one');

$night = Attendance::windowFor($day, new DateTimeImmutable("$day 22:00"), new DateTimeImmutable('2026-11-13 06:00'), 0);
ok($night->contains(new Punch('E1', new DateTimeImmutable('2026-11-13 06:20'), Punch::OUT, 'qr')), 'a night shift still takes its next-morning OUT');
