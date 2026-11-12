<?php

declare(strict_types=1);

/**
 * A PAYSLIP IS A DOCUMENT THAT LEAVES THE BUILDING.
 *
 * There were two "Print or save as PDF" buttons in `public/app.php` carrying
 * `class="no-print"` and NO `@media print` block anywhere in the file. The class
 * styled nothing. What the button produced was the payslip with the navy
 * sidebar, the search box, the print button itself — and, found by actually
 * rendering one, the standing installation warnings: "a default credential is
 * present on this installation" and "password floor lowered to 8 characters",
 * printed above somebody's net pay, on the document they file with a bank.
 *
 * This asserts the rules exist, because the failure mode is silence: a missing
 * stylesheet does not error, it just prints the wrong thing, and nobody looks at
 * a print preview in a test suite.
 *
 * No PDF library is added and none is needed. Every browser prints to PDF, and a
 * PDF library is a second layout to keep in step with the first — the second
 * layout is always the one that drifts, and the day it drifts an employee's
 * filed copy stops matching the register.
 */

group('Print view');

$app = (string) file_get_contents(dirname(__DIR__) . '/public/app.php');

// The block exists at all. This is the whole defect: two buttons, no rules.
ok(preg_match('/@media print \{(.*?)\n  \}/s', $app, $match) === 1,
    'public/app.php carries an @media print block — `no-print` styled nothing before it');

$block = $match[1] ?? '';

// ---------------------------------------------------------------------------
// WHAT MUST NOT REACH THE PAGE. Each of these was in a rendered payslip.
// ---------------------------------------------------------------------------

foreach ([
    '.sidebar' => 'the navigation rail',
    '.topbar' => 'the search box and account menu',
    '.no-print' => 'the print button itself and anything else marked not for print',
    '.standing' => 'the installation warnings — an employee\'s payslip is not the place to '
        . 'publish that this instance still has a default credential',
    'button' => 'every button on the page',
] as $selector => $why) {
    ok(preg_match('/(^|,|\s)' . preg_quote($selector, '/') . '\s*[,{]/m', $block) === 1,
        'print hides ' . $why);
}

// And the banners actually carry the class, or hiding it achieves nothing.
ok(!str_contains($app, '<div class="standing amber"><i')
    && !str_contains($app, '<div class="standing red"><i'),
    'every standing banner carries no-print — a rule that hides a class nothing uses is the '
    . 'same defect one level down');

// ---------------------------------------------------------------------------
// WHAT THE PAGE MUST BE. A payslip that splits a total across two sheets is a
// document somebody queries.
// ---------------------------------------------------------------------------

ok(str_contains($block, '@page'), 'a page size and margins are declared');
ok(str_contains($block, 'A4'), 'A4, which is the paper this product prints on');
ok(str_contains($block, 'payslip-sheet'), 'one payslip per page');
ok(str_contains($block, 'page-break-inside: avoid'), 'and no table row split across two pages');

// The class the rules act on is on the payslips themselves, both the single
// screen and the batch. A page-break rule for a class nobody applies breaks
// nothing across nothing.
ok(substr_count($app, 'card payslip-sheet') >= 2,
    'both the single payslip and every payslip in the batch are marked as sheets');

// ---------------------------------------------------------------------------
// THE BATCH EXISTS, and reads from the same place the single payslip does.
// A separate renderer for "the printable version" is the second layout that
// drifts, and the day it drifts the filed copy stops matching the register.
// ---------------------------------------------------------------------------

ok(str_contains($app, "route === 'payslips-batch'"), 'there is a batch print route');
ok(preg_match('/payslips-batch.*?Payslip::from\(\$batchRun/s', $app) === 1,
    'and it reads each payslip through Payslip::from() against the stored run, exactly like '
    . 'the single screen — not through a second renderer');
ok(preg_match('/payslips-batch.*?\$scope->only\(/s', $app) === 1,
    'and narrows to the rows this account may read: a batch print is the widest read in the '
    . 'product, every salary in the tenant on one page');
