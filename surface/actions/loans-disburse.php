<?php declare(strict_types=1);
// Disburse a loan — the Salary Advance disburse, on the loans table.
$saKind = ['table' => 'loans', 'route' => 'loans', 'config' => 'loans.json', 'title' => 'Loans', 'noun' => 'loan', 'Noun' => 'Loan'];
include __DIR__ . '/salary-advance-disburse.php';
