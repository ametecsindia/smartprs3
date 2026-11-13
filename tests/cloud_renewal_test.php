<?php

declare(strict_types=1);

use SmartPRS\Surface\Signup;
use SmartPRS\Surface\Store;

/** Owner, 13 Nov 2026: cloud subscribers renew online as in SmartPRS 2 — a renewal carries no setup charge. */
group('cloud renewal');

$pricing = ['cloud' => ['packages' => [['name' => 'Growth', 'employees' => 50, 'monthly' => 2500, 'extra' => 40]],
    'cycles' => [['months' => 12, 'discount' => 25, 'label' => 'Annual']], 'company_fee' => 1000], 'onboarding' => ['base' => 5000]];
$sg = new Signup(new Store(sys_get_temp_dir() . '/rn' . getmypid()), $pricing, ['rate_percent' => 18, 'home_state_code' => '36'], '/dev/null');
$plan = $sg->plan('Growth');
$first = $sg->price($plan, 50, 1, 12, null, 'Telangana (36)');
$renew = $sg->price($plan, 50, 1, 12, null, 'Telangana (36)', false);
same(500000, $first['setup'], 'signup carries the setup charge');
same(0, $renew['setup'], 'a renewal carries none');
same($first['subscription'], $renew['subscription'], 'the subscription part is the same price');
same(2 * intdiv($renew['taxable'] * 18 + 100, 200), $renew['tax'], 'GST on the renewal alone');
