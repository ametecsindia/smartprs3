<?php
/**
 * Field names that must never reach a log line. Seeded from spine S13 and the
 * SmartPRS 2 discovery pack (identity numbers found in plaintext application
 * logs and in a world-readable document store).
 *
 * Matching is case-insensitive and word-boundary based, over string literals,
 * array keys, property names and variable names inside a logging call.
 *
 * @return list<string>
 */
declare(strict_types=1);

return [
    'aadhaar',
    'aadhar',
    'pan',
    'uan',
    'esic_no',
    'bank_acc',
    'bank_account',
    'account_number',
    'ifsc',
    'password',
    'otp',
    'salary',
    'ctc',
    'dob',
    'date_of_birth',
];
