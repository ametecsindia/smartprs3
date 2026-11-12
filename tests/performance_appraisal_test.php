<?php

declare(strict_types=1);

use SmartPRS\Surface\Customisation;
use SmartPRS\Surface\PerformanceAppraisal as PA;
use SmartPRS\Surface\Store;

group('performance appraisal');

// THE OWNER'S REFUSED SAVE (21 Sep): "20%" in the % box was rejected as "not a number".
$c = PA::checkSlabs('custom', 'exact', [
    ['rating' => 'S', 'increment_pct' => '20%', 'description' => 'Star performer'],
    ['rating' => 'M', 'increment_pct' => ' 16 % '],
    ['rating' => '', 'increment_pct' => ''],
]);
same([], $c['errors'], 'a % typed after the number is accepted');
same('20', $c['clean'][0]['increment_pct'], 'and stored as the bare number');
same('Star performer', $c['clean'][0]['description'], 'each slab keeps its description');
same(2, count($c['clean']), 'a blank row is not a slab');

// PER-FIELD MESSAGES, keyed by the row index the form posted (blanks counted).
$c = PA::checkSlabs('numeric', 'exact', [['rating' => '4', 'increment_pct' => 'x'], ['rating' => '', 'increment_pct' => ''], ['rating' => '4', 'increment_pct' => '5']]);
same([0, 2], array_keys($c['errors']), 'every bad row is reported, not only the first');
ok(isset($c['errors'][0]['increment_pct']) && isset($c['errors'][2]['rating']), 'each message names its own box');

$c = PA::checkSlabs('numeric', 'range', [
    ['rating' => '4', 'rating_to' => '5', 'increment_pct' => '12'],
    ['rating' => '3', 'rating_to' => '4', 'increment_pct' => '8'],
    ['rating' => '2', 'rating_to' => '1', 'increment_pct' => '1'],
]);
ok(isset($c['errors'][1]['rating']), 'overlapping ranges are refused');
ok(isset($c['errors'][2]['rating_to']), 'a range whose "to" is below its "from" is refused');

// SLAB MODES
$box = ['type' => 'numeric', 'slab_mode' => 'range', 'slabs' => [
    ['rating' => '4.5', 'rating_to' => '5', 'increment_pct' => '12'],
    ['rating' => '3', 'rating_to' => '4.4', 'increment_pct' => '8'],
]];
same('12', PA::pctFor($box, 5, ''), 'a range includes its top');
same('8', PA::pctFor($box, 4, ''), 'and a rating inside a lower range');
same(null, PA::pctFor($box, 2, ''), 'a rating no range covers gets nothing');
same('20', PA::pctFor(['type' => 'custom', 'slabs' => [['rating' => 'outstanding', 'increment_pct' => '20']]], 5, 'Outstanding'), 'custom labels match the scale label');
same('9', PA::pctFor(['type' => 'numeric', 'slabs' => [['rating' => '5', 'increment_pct' => '9']]], 5, ''), 'a box saved before slab modes still reads as exact');

// WIRED TO THE FINANCIAL YEAR (start month 4)
same(['from' => '2026-04-01', 'to' => '2027-03-31'], PA::range('2026-27', 4), 'a yearly key is its financial year');
same(['from' => '2026-10-01', 'to' => '2027-03-31'], PA::range('2026-H2', 4), 'H2 is the second half of that year');
same(['from' => '2027-02-01', 'to' => '2027-02-28'], PA::range('2026-M02', 4), 'a month key names that month of the financial year');
ok(PA::keyCoversPeriod('2026-27', '2026-H1', 4), 'Performance period 2026-H1 ends inside FY 2026-27');
ok(!PA::keyCoversPeriod('2025-26', '2026-H1', 4), 'and not inside FY 2025-26');
ok(!PA::isCurrent('2025-26', '2026-09-21', 4) && PA::isCurrent('2026-27', '2026-09-21', 4), 'only the current financial year stays on the main screen');

// SAVE, ARCHIVE, RESOLVE
$store = (new Store(sys_get_temp_dir() . '/prs3-pa-' . bin2hex(random_bytes(4))))->forTenant('1');
$pa = new PA(new Customisation($store));
ok($pa->saveBox('2025-26', null, ['company' => 'ABC'], 'custom', [['rating' => 'S', 'increment_pct' => '20%']], '1', 'hr', 'exact')['ok'], 'a past year saves');
ok($pa->saveBox('2026-27', null, ['company' => 'ABC'], 'numeric', [['rating' => '4', 'rating_to' => '5', 'increment_pct' => '10']], '1', 'hr', 'range')['ok'], 'a range box saves');
$id = (string) $pa->boxesFor('2026-27')[0]['id'];
ok($pa->saveBox('2026-27', $id, ['company' => 'ABC'], 'numeric', [['rating' => '4', 'rating_to' => '5', 'increment_pct' => '12']], '1', 'hr', 'range')['ok'], 'and is edited');
$bad = $pa->saveBox('2026-27', $id, ['company' => 'ABC'], 'numeric', [['rating' => 'x', 'increment_pct' => '1']], '1', 'hr', 'exact');
ok(!$bad['ok'] && isset($bad['errors'][0]['rating']), 'a refused save returns its per-field errors');
same('12', $pa->boxesFor('2026-27')[0]['slabs'][0]['increment_pct'], 'and changes nothing');

$archive = $pa->archive('2026-09-21', 4);
same(2, count($archive), 'the archive holds the past year and the superseded 10% version');
same('12', $pa->resolve('2026-H1', ['company' => 'ABC'], '5', '', 4)['pct'] ?? null, 'resolve reads the current year for the period');
same(null, $pa->resolve('2026-H1', ['company' => 'XYZ'], '5', '', 4), 'nobody covered, nothing recommended');

// RATING FORMATS — how reviewers rate, per box (owner, 21 Sep: "whether rating or number or slab")
$labels = ['type' => 'custom', 'slabs' => [['rating' => 'S', 'increment_pct' => '20'], ['rating' => 'M', 'increment_pct' => '16']]];
$numbers = ['type' => 'numeric', 'slab_mode' => 'exact', 'slabs' => [['rating' => '5', 'increment_pct' => '12'], ['rating' => '4', 'increment_pct' => '8']]];
$slabBox = ['type' => 'numeric', 'slab_mode' => 'range', 'slabs' => [['rating' => '4.5', 'rating_to' => '5', 'increment_pct' => '12']]];
same(['labels', 'numbers', 'slab'], [PA::format($labels), PA::format($numbers), PA::format($slabBox)], 'three formats');
same('S', PA::acceptValue($labels, 's'), 'a label is accepted case-insensitively and stored as written in the criteria');
same(null, PA::acceptValue($labels, 'X'), 'a label nobody set up is refused');
same('5', PA::acceptValue($numbers, '5'), 'a listed number is accepted');
same(null, PA::acceptValue($numbers, '3'), 'an unlisted number is refused');
same('4.6', PA::acceptValue($slabBox, '4.6'), 'a point inside a slab is accepted as typed');
same(null, PA::acceptValue($slabBox, '4.2'), 'a point outside every slab is refused');
same('16', PA::slabForValue($labels, 'M')['increment_pct'] ?? null, 'a label rating finds its %');
ok(PA::FORMATS['slab'] === ['type' => 'numeric', 'slab_mode' => 'range'], 'the screen format maps to the stored type');

$scaleOf = $pa->scaleFor('2026-H1', ['company' => 'ABC'], 4);
same('slab', $scaleOf['format'] ?? null, 'a reviewer of an ABC employee in 2026-H1 rates on ABC\'s 2026-27 slab scale');
same(null, $pa->scaleFor('2026-H1', ['company' => 'XYZ'], 4), 'nobody covered: the shipped scale');
same('12', $pa->resolve('2026-H1', [], '4.7', '', 4, ['fy' => '2026-27', 'id' => $id])['pct'] ?? null, 'the box a review was rated on wins');
