<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

use SmartPRS\Platform\Support\Rational;

/**
 * TESTS — the MCQ engine behind Learning → Tests / Test Results / Test Reports.
 *
 * A test is a `tests` row with a pass mark (percent); its questions are
 * `test_questions` rows (A–D, one correct letter, marks). An employee's attempt
 * is scored HERE, server-side, in whole marks: the browser posts letters, the
 * server knows the answers. Score percent is integer arithmetic on marks and
 * the pass decision compares that exact fraction with the pass mark as a
 * Rational — 2 of 3 marks is 66.66…%, which is under a 67 pass mark and over
 * a 66 one, and no float gets to decide which.
 */
final class Assessments
{
    public const PASSED = 'passed';
    public const FAILED = 'failed';
    public const LETTERS = ['a', 'b', 'c', 'd'];

    /**
     * @param list<array<string, mixed>> $questions `test_questions` rows of one test
     * @param array<string, string> $answers question id => letter
     * @return array{total: int, scored: int, percent: int, working: string, per_question: list<array{id: string, seq: int, given: string, correct: string, marks: int, earned: int}>}
     */
    public static function score(array $questions, array $answers): array
    {
        $total = 0;
        $scored = 0;
        $per = [];

        foreach ($questions as $q) {
            $id = (string) ($q['id'] ?? '');
            $marks = max(0, (int) ($q['marks'] ?? 1));
            $correct = strtolower(trim((string) ($q['correct'] ?? '')));
            $given = strtolower(trim((string) ($answers[$id] ?? '')));
            $earned = $given !== '' && $given === $correct ? $marks : 0;
            $total += $marks;
            $scored += $earned;
            $per[] = ['id' => $id, 'seq' => (int) ($q['seq'] ?? 0), 'given' => $given, 'correct' => $correct, 'marks' => $marks, 'earned' => $earned];
        }

        $percent = $total > 0 ? intdiv($scored * 100, $total) : 0;

        return ['total' => $total, 'scored' => $scored, 'percent' => $percent,
            'working' => $scored . ' of ' . $total . ' marks = ' . $percent . '% (whole percent, rounded down)',
            'per_question' => $per];
    }

    /** Exact: scored/total compared with the pass mark percent as a fraction. */
    public static function passed(int $scored, int $total, string $passMark): bool
    {
        if ($total <= 0) {
            return false;
        }

        if (preg_match('/^\d+(\.\d+)?$/', trim($passMark)) !== 1) {
            return false; // a pass mark nobody can read is a test nobody passes — fail closed
        }

        return Rational::of($scored * 100, $total)->compareTo(Rational::fromDecimalString(trim($passMark))) >= 0;
    }

    /**
     * PASS RATE PER TEST AND PER DEPARTMENT, from the attempts that finished.
     *
     * @param list<array<string, mixed>> $tests
     * @param list<array<string, mixed>> $attempts
     * @param array<string, string> $departmentOf employee id => department name
     * @return array{by_test: list<array{test_id: string, name: string, attempts: int, passed: int, failed: int, rate: ?Rational, working: string}>,
     *     by_department: list<array{department: string, attempts: int, passed: int, failed: int, rate: ?Rational, working: string}>}
     */
    public static function reports(array $tests, array $attempts, array $departmentOf): array
    {
        $byTest = [];
        $byDept = [];

        foreach ($tests as $t) {
            $byTest[(string) $t['id']] = ['test_id' => (string) $t['id'], 'name' => (string) ($t['name'] ?? ''), 'attempts' => 0, 'passed' => 0, 'failed' => 0, 'rate' => null, 'working' => ''];
        }

        foreach ($attempts as $a) {
            $status = (string) ($a['status'] ?? '');

            if ($status !== self::PASSED && $status !== self::FAILED) {
                continue; // in progress / abandoned: not an outcome
            }

            $tid = (string) ($a['test_id'] ?? '');
            $dept = $departmentOf[(string) ($a['employee_id'] ?? '')] ?? '';
            $dept = $dept === '' ? '(no department)' : $dept;
            $byDept[$dept] ??= ['department' => $dept, 'attempts' => 0, 'passed' => 0, 'failed' => 0, 'rate' => null, 'working' => ''];
            $byTest[$tid] ??= ['test_id' => $tid, 'name' => '(deleted test)', 'attempts' => 0, 'passed' => 0, 'failed' => 0, 'rate' => null, 'working' => ''];

            foreach ([&$byTest[$tid], &$byDept[$dept]] as &$bucket) {
                $bucket['attempts']++;
                $bucket[$status]++;
            }

            unset($bucket);
        }

        $finish = static function (array $b): array {
            if ($b['attempts'] > 0) {
                $b['rate'] = Rational::of($b['passed'], $b['attempts']);
                $b['working'] = $b['passed'] . ' passed ÷ ' . $b['attempts'] . ' attempts = ' . $b['rate']->toDecimalString(2) . ' → ' . Rational::of($b['passed'] * 100, $b['attempts'])->toDecimalString(1) . '%';
            } else {
                $b['working'] = 'no finished attempt';
            }

            return $b;
        };

        ksort($byDept);

        return ['by_test' => array_values(array_map($finish, $byTest)), 'by_department' => array_values(array_map($finish, $byDept))];
    }
}
