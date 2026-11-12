<?php declare(strict_types=1);
// Save a loan — the Salary Advance save, on the loans table.
$saKind = ['table' => 'loans', 'route' => 'loans', 'config' => 'loans.json', 'title' => 'Loans', 'noun' => 'loan', 'Noun' => 'Loan'];
include __DIR__ . '/salary-advance-save.php';
