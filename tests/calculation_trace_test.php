<?php

declare(strict_types=1);

use SmartPRS\Platform\Support\CalculationTrace;
use SmartPRS\Platform\Support\Money;

group('CalculationTrace');

// The spine S7 worked example.
$basic = new CalculationTrace(
    label: 'BASIC',
    result: Money::of('15000.00'),
    artefact: 'structure "Telangana Collections"',
    version: 'v7',
);

$hra = new CalculationTrace(
    label: 'HRA',
    result: Money::of('6000.00'),
    inputs: ['BASIC' => Money::of('15000.00')],
    artefact: 'formula HRA',
    version: 'v3 (effective 01-Apr-2026 →)',
    expression: 'BASIC % 40',
    substituted: '₹15,000.00 × 40%',
    rounding: '2dp, half-up',
    children: [$basic],
);

$rendered = $hra->render();

ok(str_contains($rendered, 'HRA = ₹6,000.00'), 'the output line names the value and its formatted result');
ok(str_contains($rendered, '= BASIC % 40'), 'the expression source appears');
ok(str_contains($rendered, '= ₹15,000.00 × 40%'), 'the substituted form appears');
ok(str_contains($rendered, 'formula HRA v3'), 'the artefact and version that produced it appear');
ok(str_contains($rendered, 'inputs:   BASIC = ₹15,000.00'), 'the inputs appear with their values');
ok(str_contains($rendered, 'rounding: 2dp, half-up'), 'the rounding mode appears');
ok(str_contains($rendered, "\n    BASIC = ₹15,000.00"), 'a child step is indented under its parent');
ok(str_contains($rendered, 'structure "Telangana Collections" v7'), 'the child names its own source');
same($rendered, (string) $hra, '__toString renders');

// The tree grows without mutation.
$withAnother = $hra->withChild(new CalculationTrace('CITY_FACTOR', 'Metro'));
same(1, count($hra->children), 'withChild does not mutate the original');
same(2, count($withAnother->children), 'withChild returns a new trace with the extra step');

// JSON, for storage alongside a payslip line and for Explain(entity, field).
$decoded = json_decode($hra->toJson(), true, flags: JSON_THROW_ON_ERROR);

same('HRA', $decoded['label'], 'json carries the label');
same('₹6,000.00', $decoded['result'], 'json carries the rendered result');
same(['BASIC' => '₹15,000.00'], $decoded['inputs'], 'json carries the inputs');
same('formula HRA', $decoded['artefact'], 'json carries the artefact');
same('v3 (effective 01-Apr-2026 →)', $decoded['version'], 'json carries the version');
same('BASIC', $decoded['children'][0]['label'], 'json carries the child steps');
ok(!array_key_exists('condition', $decoded), 'empty fields are omitted rather than serialised as null');

// A trace with no optional detail still renders and serialises.
$bare = new CalculationTrace('LOP_DAYS', 2);
same('LOP_DAYS = 2', $bare->render(), 'a bare trace is a single line');
same('{"label":"LOP_DAYS","result":"2"}', $bare->toJson(), 'a bare trace serialises to two fields');

// Attendance-style trace (spine S15).
$attendance = new CalculationTrace(
    label: 'Status',
    result: 'Half Day',
    inputs: ['late_minutes' => 132, 'shift_start' => '09:30', 'actual_in' => '11:42'],
    artefact: 'rule HYD-LATE-003',
    version: 'v4',
    expression: 'late_minutes > 120',
);
ok(str_contains($attendance->render(), 'Status = Half Day'), 'a non-money trace renders too');
ok(str_contains($attendance->render(), 'late_minutes = 132'), 'with its scalar inputs');
