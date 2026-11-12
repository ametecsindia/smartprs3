<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Probation;
use SmartPRS\Surface\StatutoryApplicability;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\Timeline;

/**
 * STATUTORY DURING PROBATION (owner, 8 Oct): PF, ESIC, PT, LWF, TDS are not
 * considered during probation by default; the tenant and each company can mark
 * any head applicable during probation. Confirmation (dated) ends it.
 */

group('probation · statutory');

$root = dirname(__DIR__);
$store = (new Store(sys_get_temp_dir() . '/prs3-pst-' . bin2hex(random_bytes(4))))->forTenant('1');
$store->put('companies', ['id' => 'C1', 'name' => 'One']);
$store->put('companies', ['id' => 'C2', 'name' => 'Two']);
$probation = new Probation($store, $root . '/config/probation.json', new Customisation($store), new Timeline($store));
$app = new StatutoryApplicability($root . '/config/statutory-applicability.json', null, $probation);

$onProb = ['id' => '1', 'emp_code' => 'E1', 'employer_company_id' => 'C1', 'employment_type' => 'Probationary', 'pf_applicable' => 1];
$confirmed = ['id' => '2', 'emp_code' => 'E2', 'employer_company_id' => 'C1', 'employment_type' => 'Permanent',
    'confirmation_date' => '2026-10-15', 'pf_applicable' => 1];
$permanent = ['id' => '3', 'emp_code' => 'E3', 'employer_company_id' => 'C1', 'employment_type' => 'Permanent', 'pf_applicable' => 1];

$off = $app->offFor($onProb, [], '2026-09-30');
ok(in_array('PF_EMPLOYEE', $off, true) && in_array('PF_EMPLOYER', $off, true), 'default: PF off during probation even with PF = Yes on the record');
ok(in_array('ESI_EMPLOYEE', $off, true) && in_array('PT', $off, true) && in_array('TDS', $off, true), 'default: ESI, PT, TDS off too');
same([], $app->offFor($permanent, [], '2026-09-30'), 'a permanent employee loses nothing');
ok(in_array('PF_EMPLOYEE', $app->offFor($confirmed, [], '2026-09-30'), true), 'confirmed 15 Oct: September is still a probation month');
same([], $app->offFor($confirmed, [], '2026-10-31'), 'confirmed 15 Oct: October is not');
ok(in_array('Provident Fund (PF) (on probation)', $app->offLabels($onProb, [], '2026-09-30'), true), 'the payslip label says why');
ok(in_array('PF', $app->offHeads($onProb, [], '2026-09-30'), true), 'and the engine is told the head is off');

// tenant default: PF applies during probation
$saved = $probation->saveStatutory(['PF' => true, 'ESI' => false, 'PT' => false, 'LWF' => false, 'TDS' => false], 'u', 'a@b');
ok($saved['ok'], 'tenant default saves: ' . $saved['why']);
$probation = new Probation($store, $root . '/config/probation.json', new Customisation($store), new Timeline($store));
$app = new StatutoryApplicability($root . '/config/statutory-applicability.json', null, $probation);
ok(!in_array('PF_EMPLOYEE', $app->offFor($onProb, [], '2026-09-30'), true), 'PF applicable during probation by the tenant default');
ok(in_array('ESI_EMPLOYEE', $app->offFor($onProb, [], '2026-09-30'), true), 'ESI still off');

// company C1 overrides: nothing applies; C2 follows the default
$probation->saveStatutory(['PF' => false, 'ESI' => false, 'PT' => true, 'LWF' => false, 'TDS' => false], 'u', 'a@b', 'C1');
$probation = new Probation($store, $root . '/config/probation.json', new Customisation($store), new Timeline($store));
$app = new StatutoryApplicability($root . '/config/statutory-applicability.json', null, $probation);
ok(in_array('PF_EMPLOYEE', $app->offFor($onProb, [], '2026-09-30'), true), 'C1 own answer: PF off');
ok(!in_array('PT', $app->offFor($onProb, [], '2026-09-30'), true), 'C1 own answer: PT applies');
ok(!in_array('PF_EMPLOYEE', $app->offFor(['employer_company_id' => 'C2'] + $onProb, [], '2026-09-30'), true), 'C2 follows the tenant default (PF applies)');
ok($probation->statutoryOverridden('C1') && !$probation->statutoryOverridden('C2'), 'override is tracked per company');
same(true, $probation->config()['default_months'] === 6, 'saving statutory leaves the months untouched');

$probation->saveStatutory([], 'u', 'a@b', 'C1', true);
$probation = new Probation($store, $root . '/config/probation.json', new Customisation($store), new Timeline($store));
ok(!$probation->statutoryOverridden('C1'), 'follow the default again removes the company answer');

// an explicit No on the record still wins after confirmation
same(['PF_EMPLOYEE', 'PF_EMPLOYER'], (new StatutoryApplicability($root . '/config/statutory-applicability.json', null, $probation))
    ->offFor(['pf_applicable' => 0] + $permanent, [], '2026-09-30'), 'a confirmed employee with PF = No has PF off');
