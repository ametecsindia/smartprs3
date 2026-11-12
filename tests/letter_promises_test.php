<?php

declare(strict_types=1);

/**
 * A LETTER THAT PROMISES WHAT PAYROLL DOES NOT DO.
 *
 * The appointment letter this build ships says statutory deductions "including
 * provident fund and professional tax where applicable, will be made in
 * accordance with the law". That sentence is a normal clause in an Indian
 * appointment letter and it is not the defect. The defect is that no provident
 * fund is ever deducted, and nobody issuing the letter is told — so a signed
 * document states something the system will not do, and the employee finds out
 * years later.
 *
 * The check has to be able to say NOTHING, or it is noise: a warning that fires
 * on every letter is read exactly as carefully as one that never fires.
 */

use SmartPRS\Surface\Letters;
use SmartPRS\Surface\Structure;
use SmartPRS\Surface\Store;

group('letter promises');

$promising = ['key' => 'appointment', 'body' => "Dear {{name}},\n\nYour annual cost to company is "
    . "{{ctc|money}}. Statutory deductions, including provident fund and professional tax where "
    . "applicable, will be made in accordance with the law.\n\n{{company}}"];

$silent = ['key' => 'relieving', 'body' => "Dear {{name}},\n\nYou have been relieved from your "
    . "duties as {{designation}}, and no dues remain outstanding on either side.\n\n{{company}}"];

$noStatutory = ['components' => [
    ['code' => 'C-BASIC', 'kind' => 'earning', 'flags' => ['pf_base' => true]],
    ['code' => 'D-ANYTHING', 'kind' => 'deduction', 'flags' => ['prorates' => false]],
]];

$computesPf = ['components' => [
    ['code' => 'C-BASIC', 'kind' => 'earning', 'flags' => ['pf_base' => true]],
    ['code' => 'D-WHATEVER-THEY-CALL-IT', 'kind' => 'deduction', 'flags' => ['pf' => true]],
]];

// THE FAILURE THE AUDIT FOUND.
$found = Letters::promisesNotKept($promising, $noStatutory);
$schemes = array_column($found, 'scheme');
ok(in_array('provident fund', $schemes, true),
    'A letter promising provident fund over a structure that deducts none must say so.');
ok(in_array('professional tax', $schemes, true),
    'And professional tax: a deduction nothing declares as PT is not PT to this check.');
ok(str_contains($found[0]['why'], 'provident fund'),
    'The message names the scheme rather than warning about "statutory deductions" in general.');
ok(str_contains($found[0]['why'], 'nothing is remitted'),
    'And states the consequence, not a bare flag.');

// NON-VACUITY, PART ONE: a letter that promises nothing gets nothing back. If
// this were not clean, every warning above would prove only that the method
// warns about everything it is handed.
same([], Letters::promisesNotKept($silent, $noStatutory),
    'A letter that names no statutory scheme has nothing to report.');

// NON-VACUITY, PART TWO: the same promising letter against a structure that
// DOES declare the scheme. The two answers differ on the structure alone, so
// the check is reading the structure and not the letter's reputation.
$covered = array_column(Letters::promisesNotKept($promising, $computesPf), 'scheme');
ok(!in_array('provident fund', $covered, true),
    'A deduction declaring the provident fund flag covers the promise, whatever it is called.');
ok(in_array('professional tax', $covered, true),
    'while the scheme that structure still does not compute is still reported.');
ok($schemes !== $covered,
    'NON-VACUITY: the same letter gets different answers from different structures.');

// A WAGE BASE IS NOT A DEDUCTION. `pf_base` on an earning says a base is being
// assembled; it deducts nothing and remits nothing, and reading it as coverage
// would clear the exact letter this file exists for.
ok(in_array('provident fund', array_column(Letters::promisesNotKept($promising,
    ['components' => [['code' => 'C-BASIC', 'kind' => 'earning', 'flags' => ['pf_base' => true]]]]),
    'scheme'), true),
    'A pf_base flag on an earning is a wage base, not a deduction, and does not keep the promise.');

// AGAINST WHAT THIS BUILD ACTUALLY SHIPS — the letter as written and the
// structure as configured, read the same way the screen reads them.
$store = (new Store(sys_get_temp_dir() . '/prs3-promise-' . bin2hex(random_bytes(4))))->forTenant('1');
$letters = new Letters($store, __DIR__ . '/../config/letters.json');
$structure = new Structure($store, __DIR__ . '/../config/salary-structure.json');
$shipped = $structure->versionOn(date('Y-m-d'));

$appointment = $letters->find('appointment');
ok($appointment !== null, 'The shipped appointment letter is there to check.');
ok(array_column(Letters::promisesNotKept($appointment, $shipped), 'scheme') !== [],
    'The letter this product ships promises statutory deductions the structure it ships does not '
    . 'compute, and the screen has to say so.');
same([], Letters::promisesNotKept($letters->find('warning'), $shipped),
    'while the warning letter, which promises nothing, stays clean.');
