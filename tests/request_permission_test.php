<?php

declare(strict_types=1);

/**
 * WHICH PERMISSION A REQUEST INHERITS.
 *
 * A request is not a screen of its own; it is a second place the same values are
 * printed. So the question is not "who may see requests" — that is the question
 * that produced the bug — it is "what is this request ABOUT, and who may see
 * that?".
 *
 * The defect this file locks out was live: the increments module declares
 * `view: employee.view`, which the Viewer role holds, and puts
 * `employee.salary.view` on `new_ctc`. On the module screen that field was
 * correctly masked. The salary increment REQUEST printed the same number on its
 * face and inherited only the module's view permission — so the field guard was
 * bypassed by rendering the value somewhere the field did not exist. Masking a
 * column on one screen and printing it on another is not a permission system.
 *
 * The last section is the non-vacuity check: it proves the two permissions are
 * actually different for this module, so a run where `permissionFor()` returned
 * the wrong one would visibly fail rather than agreeing by coincidence.
 */

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\Modules;
use SmartPRS\Surface\Requests;
use SmartPRS\Surface\Store;
use SmartPRS\Surface\WorkflowCustomisation;

group('request permission');

$store = (new Store(sys_get_temp_dir() . '/prs3-reqperm-' . bin2hex(random_bytes(4))))
    ->forTenant('1');

$modules = new Modules($store, __DIR__ . '/../config/modules.json');
$requests = new Requests($store, __DIR__ . '/../config/workflows.json',
    new WorkflowCustomisation(new Customisation($store)));

// ------------------------------------------------------- the amount's own field
$increment = (array) $requests->workflow('increment');

same('employee.salary.view', $requests->permissionFor($increment, $modules)['permission'],
    'A salary increment is read behind the permission guarding the FIELD its amount comes from '
    . '(`new_ctc` → employee.salary.view), not the weaker view permission of the module that '
    . 'declares the table. The request prints the number; the field guard has to travel with it.');

// -------------------------------------------- and the module's, when there is no field guard
$leave = (array) $requests->workflow('leave-request');
$leavesModule = null;

foreach ($modules->all() as $module) {
    if ((string) $module['table'] === (string) $leave['subject']) {
        $leavesModule = $module;
    }
}

same($modules->permission((array) $leavesModule, 'view'),
    $requests->permissionFor($leave, $modules)['permission'],
    'A workflow whose amount field carries no permission of its own falls back to the view '
    . 'permission of the module declaring its subject — the original rule, still in force where '
    . 'nothing narrower was declared.');

// ------------------------------------------------------------- nothing declares it
$orphan = ['subject' => 'no_such_table', 'label' => 'Orphan', 'amount_field' => 'amount'];
$decided = $requests->permissionFor($orphan, $modules);

ok($decided['permission'] === null && $decided['why'] !== null,
    'A subject no module declares yields NO permission and a reason — refused rather than shown '
    . 'to everybody. "No rule found" must never read as "no rule applies".');

// --------------------------------------------------------------- non-vacuity
// If these two were the same string the first assertion above would pass no
// matter which one the code returned, and this file would be reassurance.
$incrementModule = null;

foreach ($modules->all() as $module) {
    if ((string) $module['table'] === 'increments') {
        $incrementModule = $module;
    }
}

ok($incrementModule !== null, 'The increments module is declared — without it the first '
    . 'assertion tests the orphan branch by accident.');

ok($modules->permission((array) $incrementModule, 'view') !== 'employee.salary.view',
    'The module view permission and the field permission are genuinely DIFFERENT strings for this '
    . 'module, so the first assertion distinguishes them. Two names that happen to agree would '
    . 'make it pass whichever one the code chose.');
