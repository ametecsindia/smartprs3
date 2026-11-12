<?php

declare(strict_types=1);

/**
 * LETTERS: that a blank is refused, and that rupees in words are on the INDIAN
 * scale.
 *
 * The words matter more than they look. An offer letter states the salary twice
 * because the words are what a dispute is settled by, and a figure rendered on
 * the Western scale — "four million two hundred thousand" for ₹42,00,000 — is a
 * confident, quiet error in the one line nobody expects to be wrong.
 */

use SmartPRS\Surface\Letters;

group('letters');

$template = [
    'key' => 'offer',
    'label' => 'Offer letter',
    'requires' => ['name', 'designation', 'doj', 'ctc'],
    'body' => "Dear {{name}},\n\nWe offer you {{designation}} from {{doj|date}} at "
        . "{{ctc|money}} ({{ctc|money_words}}).\n\n{{company}}",
];

$complete = ['id' => '7', 'name' => 'Kavita Sharma', 'designation' => 'Collections Executive',
    'doj' => '2026-10-01', 'ctc' => '4200000', 'company' => 'Ametecs India Private Limited'];

$letters = new Letters(new SmartPRS\Surface\Store(
    sys_get_temp_dir() . '/prs3-letters-' . bin2hex(random_bytes(4))), __DIR__ . '/../config/letters.json');

// NON-VACUITY: the complete case must render, or every refusal below proves only
// that the renderer refuses everything.
$good = $letters->render($template, $complete);
ok($good['ok'], 'A letter with every value present must render.');
ok(!str_contains($good['body'], '{{'), 'No placeholder may survive into an issued letter.');
ok(str_contains($good['body'], '01-10-2026'),
    'A date must read as an Indian reader expects (dd-mm-yyyy, owner 7 Oct), not as 2026-10-01.');
// INDIAN GROUPING, not Western. ₹4,200,000.00 for a salary is the kind of wrong
// that reads as right to anyone who has not spent a career looking at rupees.
ok(str_contains($good['body'], '₹42,00,000.00'),
    'Rupees group on the Indian scale — 42,00,000, never 4,200,000.');
ok(str_contains($good['body'], 'Forty Two Lakh'),
    'and the words are on the Indian scale too — the line a dispute is settled by.');

// THE RULE THIS CLASS EXISTS FOR.
foreach (['ctc' => 'Ctc', 'doj' => 'Doj', 'name' => 'Name'] as $field => $label) {
    $short = $complete;
    unset($short[$field]);
    $result = $letters->render($template, $short);

    ok(!$result['ok'], "A letter missing {$field} must be refused, not rendered with a blank.");
    ok(in_array($label, $result['missing'], true),
        "The refusal must NAME {$field} — 'something is missing' is not something HR can act on.");
    ok($result['body'] === '',
        'A refused letter must produce no text at all: a half-rendered draft is the thing that '
        . 'gets signed.');
}

// A PLACEHOLDER NOT IN `requires` MUST STILL REFUSE WHEN IT IS BLANK. Without
// this, the `requires` list does all the work and the placeholder check is dead
// code that only looks like a safeguard — which is how a letter goes out saying
// "For  " where the company name belongs. Found by disabling the check and
// watching the suite still pass.
$noCompany = $complete;
unset($noCompany['company']);
$result = $letters->render($template, $noCompany);
ok(!$result['ok'], 'A blank placeholder must refuse even when nothing lists it as required.');
ok(in_array('Company', $result['missing'], true), 'and the refusal must name it.');

// An empty string is a blank, not a value. This is the case that slips through:
// the column exists, the row has it, and it holds ''.
$blank = $complete;
$blank['designation'] = '   ';
ok(!$letters->render($template, $blank)['ok'],
    'Whitespace is a blank. A column that exists and holds nothing is the case that slips through.');

// Rupees, through the codebase's own Money — which already formats and writes
// words on the Indian scale. Asserted here because a letter is where the two are
// read side by side, and where getting either wrong is signed for.
foreach ([
    '4200000' => ['₹42,00,000.00', 'Rupees Forty Two Lakh Only'],
    '10000000' => ['₹1,00,00,000.00', 'Rupees One Crore Only'],
    '950' => ['₹950.00', 'Rupees Nine Hundred Fifty Only'],
] as $amount => $expected) {
    $one = $letters->render($template, ['ctc' => $amount] + $complete);
    ok(str_contains($one['body'], $expected[0]), "{$amount} formats as {$expected[0]}");
    ok(str_contains($one['body'], $expected[1]), "{$amount} reads as {$expected[1]}");
}

// Issuing keeps the TEXT, so a later template change cannot rewrite a signed
// letter. This is the same law as everywhere else here: nothing is overwritten.
$issued = $letters->issue($template, $complete, 'admin@smartprslocal');
ok($issued['ok'], 'A complete letter must issue.');
ok(str_contains((string) $issued['letter']['body'], 'Kavita Sharma'),
    'What is stored is the letter as it went out, not a reference to the template.');
same('offer', $issued['letter']['letter_type'], 'and it records which template produced it');

$changed = $template;
$changed['body'] = "Completely different wording for {{name}}.";
$letters->issue($changed, $complete, 'admin@smartprslocal');
$history = $letters->issued('7');
same(2, count($history), 'Re-issuing writes another letter rather than changing the first.');
ok(str_contains((string) $history[1]['body'], 'We offer you'),
    'The first letter still says what it said, after the template changed.');

// A refused letter writes NOTHING. A row recording that a letter was issued,
// when it was not, is worse than no row.
$letters->issue($template, ['id' => '9', 'name' => 'No Details'], 'admin@smartprslocal');
same(0, count($letters->issued('9')), 'A refused letter must leave no record of being issued.');

// Every shipped template must be renderable in principle: a template whose
// `requires` names a field it never mentions is a refusal nobody can satisfy.
foreach ($letters->all() as $shipped) {
    $used = array_column(Letters::placeholders((string) $shipped['body']), 'field');

    foreach ((array) $shipped['requires'] as $required) {
        ok(in_array((string) $required, $used, true), sprintf(
            'Template "%s" requires "%s" but never uses it — a refusal nobody can satisfy by '
            . 'filling anything in.', (string) $shipped['key'], (string) $required));
    }
}

// ---- a customer's own wording ----------------------------------------------

group('letter customisation');

$fields = ['id', 'emp_code', 'name', 'designation', 'department', 'branch', 'doj', 'ctc',
    'address', 'reporting_manager'];

// NON-VACUITY: the change a customer actually wants must pass.
ok(Letters::templateProblems(['label' => 'Offer of employment',
    'body' => "Dear {{name}},\n\nWe offer you {{designation}} at {{ctc|money}} from "
        . "{{doj|date}}.\n\nFor {{company}}"], $fields) === [],
    'Rewriting the wording with fields that exist is the point and must pass.');

// THE CHECK THAT MATTERS: a placeholder no employment can fill would refuse the
// template for every person, for ever.
$unknown = Letters::templateProblems(['body' => 'Dear {{name}}, your {{grade_band}} is confirmed.'],
    $fields);
ok($unknown !== [],
    'A placeholder naming a field no employment holds must be refused at save time — otherwise the '
    . 'template refuses for everybody, and nobody finds out until the day they need the letter.');
ok(str_contains($unknown[0], 'grade_band'), 'and the refusal must name it.');

// A filter that does not exist would print raw in a signed document.
$badFilter = Letters::templateProblems(['body' => '{{ctc|rupees}}'], $fields);
ok($badFilter !== [], 'An unknown filter must be refused.');
ok(str_contains($badFilter[0], 'rupees') && str_contains($badFilter[0], 'money'),
    'and the refusal must name both what was written and what exists.');

ok(Letters::templateProblems(['body' => '   '], $fields) !== [], 'An empty letter is not a letter.');
ok(Letters::templateProblems(['key' => 'other'], $fields) !== [],
    'The key the rest of the application finds a template by is not customisable.');
ok(Letters::templateProblems(['requires' => ['pan'], 'body' => 'Dear {{name}}.'], $fields) !== [],
    'A template cannot insist on a field it never prints — nobody could satisfy the refusal.');

// AND THE CHECK CANNOT BE MADE VACUOUS BY HAVING NOTHING TO CHECK AGAINST.
ok(Letters::templateProblems(['body' => 'Dear {{anything_at_all}}.'], []) !== [],
    'With no field list the check cannot run, so the save is refused — running it against nothing '
    . 'would pass every template silently.');

// The overlay reaches rendering, not just the screen.
$store2 = (new SmartPRS\Surface\Store(
    sys_get_temp_dir() . '/prs3-lc-' . bin2hex(random_bytes(4))))->forTenant('1');
$c = new SmartPRS\Surface\Customisation($store2);
$custom = new Letters($store2, __DIR__ . '/../config/letters.json', $c);

ok(str_contains((string) $custom->find('offer')['body'], 'We are pleased to offer you'),
    'the shipped wording, before any change');

// A NEW WORDING THAT DROPS A REQUIRED FIELD IS REFUSED — and the override says
// nothing about `requires`, so this only fires because the check reads the
// MERGED template. The shipped offer letter requires the joining date; a body
// that stops printing it would refuse for everybody, naming a field that is
// nowhere on the page.
$dropped = $custom->customise('offer', [
    'body' => "Dear {{name}},\n\nJoin as {{designation}} on {{ctc|money}}."],
    $custom->shipped('offer'), $fields, '1', 'admin', '');
ok(!$dropped['ok'],
    'A wording that stops printing a still-required field must be refused, even though the change '
    . 'says nothing about the required list.');
ok(str_contains($dropped['why'], 'doj'), 'and the refusal must name the field.');

$saved = $custom->customise('offer', ['label' => 'Offer of employment',
    'requires' => ['name', 'designation', 'ctc'],
    'body' => "Dear {{name}},\n\nWe would like you to join as {{designation}} on {{ctc|money}}."],
    $custom->shipped('offer'), $fields, '1', 'admin', 'our own words');
ok($saved['ok'], 'a valid customisation saves: ' . $saved['why']);

$after = new Letters($store2, __DIR__ . '/../config/letters.json',
    new SmartPRS\Surface\Customisation($store2));
$rendered = $after->render((array) $after->find('offer'),
    ['name' => 'Kavita Sharma', 'designation' => 'Collections Executive', 'ctc' => '4200000']);

ok($rendered['ok'], 'the customised template renders');
ok(str_contains($rendered['body'], 'We would like you to join'),
    'The customer\'s wording is what gets rendered and issued, not only what is shown on the '
    . 'settings page.');
ok(str_contains($rendered['body'], '₹42,00,000.00'), 'and the money still formats on the Indian scale');
ok(($after->find('offer')['customised'] ?? false) === true,
    'and the template says it differs from what shipped');
same('Offer letter', $after->shipped('offer')['label'],
    'while the shipped template is untouched underneath');

// EFFECTIVE DATE (21 Sep 2026): HR picks it when drafting; today when not given.
$effLetters = $letters; // the shipped templates, as loaded above
$inc = $effLetters->find('increment');
$emp = ['id' => '9', 'name' => 'A B', 'emp_code' => 'X1', 'designation' => 'Analyst', 'ctc' => '600000', 'doj' => '2020-01-01'];
$r = $effLetters->render($inc, $emp, ['company' => 'Co', 'effective_date' => '2026-10-01', 'salary_structure' => 'Salary structure annexure']);
ok($r['ok'] && str_contains($r['body'], 'with effect from 01'), 'the increment letter prints the effective date picked');
ok(!str_contains($effLetters->render($inc, $emp, ['company' => 'Co'])['body'], '{{effective_date'), 'and today when none is picked');
