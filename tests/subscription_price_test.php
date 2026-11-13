<?php

declare(strict_types=1);

use SmartPRS\Surface\Signup;
use SmartPRS\Surface\Store;

/** Owner, 13 Nov 2026: a licence's amount comes from the price list — a Custom licence is priced by its seats, never ₹0. */
group('subscription price');

$pricing = ['cloud' => ['packages' => [['name' => 'Starter', 'employees' => 25, 'monthly' => 1500, 'extra' => 60], ['name' => 'Professional', 'employees' => 100, 'monthly' => 4500, 'extra' => 45]],
    'cycles' => [['months' => 3, 'discount' => 0, 'label' => 'Quarterly'], ['months' => 12, 'discount' => 25, 'label' => 'Annual']], 'company_fee' => 1000]];
$sg = new Signup(new Store(sys_get_temp_dir() . '/sp' . getmypid()), $pricing, [], '/dev/null');

same(450000, $sg->periodPaise('', 100, 1, 1), 'Custom, 100 seats, monthly = Professional ₹4,500');
same(4050000, $sg->periodPaise('', 100, 1, 12), 'annual takes the 25% advance discount: 4,500 × 12 × 75%');
same(150000 + 100000, $sg->periodPaise('Starter', 20, 2, 1), 'a named plan keeps its price; a second company adds ₹1,000');
same(450000 + 4500 * 10, $sg->periodPaise('', 110, 1, 1), 'above the largest plan: extra employees at its rate');
