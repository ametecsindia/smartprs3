<?php
/**
 * Law 1 allow-list: literals that may appear in src/*_/Domain/ .
 *
 * A value here is allowed EVERYWHERE in the domain. Adding a line is cheap and
 * deliberate; every hit is counted and printed as a suppression by the gate, so
 * the list cannot quietly become the whole vocabulary. If you find yourself
 * adding a component name, a rate, a slab or a leave-type code — stop. That is
 * exactly the literal Law 1 exists to keep out. It is data.
 *
 * @return array{values: list<string|int|float>, notes: array<string,string>}
 */
declare(strict_types=1);

return [
    'values' => [
        // Arithmetic identities and small structural counts. 0/1/-1 are allowed
        // by the gate itself and need no entry.
        2, 100,
        // Sort directions and other non-business vocabulary.
        'asc', 'desc',
        // Money is integer minor units (spine S1 Law 7, S16). The paise scale is
        // a platform constant, not a customer-configurable number.
        'INR',
    ],
    // Optional prose, printed with the suppression report. Key = value as string.
    'notes' => [
        '100' => 'percent divisor; the rates themselves stay in configuration',
        'INR' => 'ISO 4217 default currency code for the India rule pack',
    ],
];
