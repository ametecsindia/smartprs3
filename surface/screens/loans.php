<?php declare(strict_types=1);
// LOANS — exactly the Salary Advance flow (owner, 10 Sep), on the loans table, line LOAN-<id>.
$saKind = ['table' => 'loans', 'route' => 'loans', 'config' => 'loans.json', 'title' => 'Loans', 'noun' => 'loan', 'Noun' => 'Loan'];
include __DIR__ . '/salary-advance.php';
