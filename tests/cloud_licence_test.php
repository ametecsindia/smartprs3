<?php

declare(strict_types=1);

use SmartPRS\Surface\Licensing;

/** Owner, 13 Nov 2026: cloud clients get SmartPRS 2's licence controls — seat and company caps, active/grace/locked, expiry alerts. */
group('cloud licence');

$live = ['active', 'trial'];
$subs = [['status' => 'active', 'seats' => 25, 'companies' => 2, 'current_period_end' => '2026-12-31'],
    ['status' => 'cancelled', 'seats' => 500, 'companies' => 9, 'current_period_end' => '2026-01-31']];
same(25, Licensing::cloudLimit($subs, $live, 'seats'), 'seats come from the live subscription, not a cancelled one');
same(2, Licensing::cloudLimit($subs, $live, 'companies'), 'companies likewise');
same(null, Licensing::cloudLimit([], $live, 'seats'), 'no subscription on record = no cap');
same(null, Licensing::cloudLimit([['status' => 'active', 'seats' => 0]], $live, 'seats'), 'seats 0 = no cap');

same('none', Licensing::lifecycle([], '2026-11-13', 7), 'no subscription = none');
same('active', Licensing::lifecycle($subs, '2026-12-31', 7), 'on the last day it is active');
same('grace', Licensing::lifecycle($subs, '2027-01-07', 7), 'up to 7 days after it is grace');
same('locked', Licensing::lifecycle($subs, '2027-01-08', 7), 'after grace it is locked');
same('locked', Licensing::lifecycle([['status' => 'suspended', 'current_period_end' => '2027-12-31']], '2026-11-13', 7), 'suspended = locked at once');

same('expiry-15', Licensing::expiryAlert($subs, '2026-12-16', 7, [15, 7, 3, 1, 0]), '15 days before');
same('expiry-0', Licensing::expiryAlert($subs, '2026-12-31', 7, [15, 7, 3, 1, 0]), 'on the day');
same('grace', Licensing::expiryAlert($subs, '2027-01-03', 7, [15, 7, 3, 1, 0]), 'during grace');
same(null, Licensing::expiryAlert($subs, '2026-12-10', 7, [15, 7, 3, 1, 0]), 'other days nothing');
