<?php
/**
 * SCORE AN EMPLOYEE'S TEST ATTEMPT. The browser sent letters; the server
 * reads the question bank, scores in whole marks, decides pass/fail against
 * the test's pass mark exactly, and stores the attempt with the answers so
 * the score can be re-derived. Only the signed-in employee's own attempt.
 */
declare(strict_types=1);

use SmartPRS\Surface\Assessments;

$fail = static function (string $why): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ?r=tests');
    exit;
};

if (!$may('employee.view')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.view')->summary);
}

$own = $scope->own();
$testId = post('test_id');
$test = $testId === '' ? null : $store->find('tests', $testId);

if ($own === null) {
    $fail('Your account is not linked to an employee, so there is nobody to record the attempt against.');
}

if ($test === null) {
    $fail('No such test.');
}

$questions = array_values(array_filter($store->all('test_questions'), static fn (array $q): bool => (string) ($q['test_id'] ?? '') === $testId));

if ($questions === []) {
    $fail('This test has no questions yet.');
}

$answers = [];

foreach ((array) ($_POST['answer'] ?? []) as $qid => $letter) {
    if (is_string($letter) && in_array(strtolower($letter), Assessments::LETTERS, true)) {
        $answers[(string) $qid] = strtolower($letter);
    }
}

$score = Assessments::score($questions, $answers);
$passMark = str_contains((string) ($test['pass_mark'] ?? ''), '.') ? rtrim(rtrim((string) $test['pass_mark'], '0'), '.') : (string) ($test['pass_mark'] ?? ''); // '50.0000' reads as 50
$passed = Assessments::passed($score['scored'], $score['total'], (string) ($test['pass_mark'] ?? ''));
$now = date('Y-m-d H:i:s');
$row = $store->put('test_attempts', [
    'employee_id' => (string) $own,
    'test_id' => $testId,
    'status' => $passed ? Assessments::PASSED : Assessments::FAILED,
    'score' => (string) $score['percent'],
    'marks_scored' => $score['scored'],
    'total_marks' => $score['total'],
    'answers' => json_encode($answers),
    'attempted_on' => substr($now, 0, 10),
    'submitted_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
]);
$audit('test.attempted', 'test_attempts', (string) $row['id'], null, $row, $score['working'] . '; pass mark ' . $passMark . '%');
$_SESSION[$passed ? 'flash_notice' : 'flash_problem'] = ($passed ? 'PASSED — ' : 'Not passed — ') . (string) $test['name'] . ': ' . $score['working'] . ', pass mark ' . $passMark . '%.';
header('Location: ?r=tests');
exit;
