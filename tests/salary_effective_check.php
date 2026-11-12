<?php
// Self-check for SalaryEffective. Run: php tests/salary_effective_check.php  (prints OK or the first mismatch)
declare(strict_types=1);
spl_autoload_register(static function (string $c): void {
    foreach (['SmartPRS\\Platform\\' => __DIR__ . '/../src/Platform/', 'SmartPRS\\Surface\\' => __DIR__ . '/../surface/'] as $p => $d) {
        if (str_starts_with($c, $p) && is_file($f = $d . str_replace('\\', '/', substr($c, strlen($p))) . '.php')) { require_once $f; }
    }
});
use SmartPRS\Surface\SalaryEffective as S;
$eq = static function (string $what, $got, $want): void { $got === $want || exit("FAIL {$what}: got " . var_export($got, true) . ' want ' . var_export($want, true) . "\n"); };
// Training dates are the employee's own (Directory); paid or not is the company's policy (owner, 15 Oct 2026).
$tr = ['training_start_date' => '2026-10-01', 'training_end_date' => '2026-10-10'];
$co = ['paid_during_training' => 'No'];
// Joined in the batch, unpaid: training ends Sat 10 Oct 2026; Sun 11th is off → paid from Mon 12th.
$eq('unpaid training', S::payStart(['doj' => '2026-10-01'] + $tr, $co)['date'], '2026-10-12');
$eq('paid training', S::payStart(['doj' => '2026-10-01'] + $tr, ['paid_during_training' => 'Yes'] + $co)['date'], '2026-10-01');
$eq('joined after training', S::payStart(['doj' => '2026-10-15'] + $tr, $co)['date'], '2026-10-15');
$eq('no training', S::payStart(['doj' => '2026-05-04'], null)['date'], '2026-05-04');
$eq('custom calendar', S::payStart(['doj' => '2026-10-02'] + $tr, $co, static fn (string $d): bool => !in_array(date('N', strtotime($d)), ['6', '7'], true))['date'], '2026-10-12');
$on = S::on(['doj' => '2024-01-10', 'confirmation_date' => '2024-07-10'], null,
    [['status' => 'Effected', 'effective' => '2025-04-01', 'new_ctc' => '600000'], ['status' => 'Effected', 'effective' => '2027-04-01', 'new_ctc' => '700000'], ['status' => 'Draft', 'effective' => '2026-01-01']], null, '2026-10-15');
$eq('latest event', $on['date'], '2025-04-01');
$eq('history count', count($on['history']), 3);
$eq('confirmation later', S::on(['doj' => '2026-06-01', 'confirmation_date' => '2026-09-01'], null, [], null, '2026-10-15')['date'], '2026-09-01');
// Date of production and tenure (owner, 15 Oct 2026): the company picks what tenure counts from.
$eq('production after training', S::production(['doj' => '2026-10-01'] + $tr, $co), '2026-10-12');
$eq('production with no training', S::production(['doj' => '2026-05-04'], null), '2026-05-04');
$eq('tenure from joining', S::tenure(['doj' => '2024-07-15'], null, null, '2026-10-15')['text'], '2 yrs 3 mths');
$eq('tenure basis default', S::tenure(['doj' => '2024-07-15'], null, null, '2026-10-15')['basis'], 'Date of joining');
$eq('tenure in days', S::tenure(['doj' => '2026-10-03'], null, null, '2026-10-15')['text'], '12 days');
$eq('tenure from production', S::tenure(['doj' => '2026-09-01', 'training_end_date' => '2026-09-12'], ['tenure_basis' => 'Date of production'], null, '2026-10-15')['from'], '2026-09-14');
$eq('not confirmed yet', S::tenure(['doj' => '2026-01-01'], ['tenure_basis' => 'Date of confirmation'], null, '2026-10-15')['why'], 'Not confirmed yet');
$eq('confirmed: 1 mth', S::tenure(['doj' => '2026-01-01', 'confirmation_date' => '2026-09-10'], ['tenure_basis' => 'Date of confirmation'], null, '2026-10-15')['text'], '1 mth');
$eq('leaver stops at exit', S::tenure(['doj' => '2025-01-01', 'exit_date' => '2026-04-01'], null, null, '2026-10-15')['text'], '1 yr 3 mths');
$eq('future joiner', S::tenure(['doj' => '2026-11-02'], null, null, '2026-10-15')['why'], 'Starts 02-11-2026');
echo "OK\n";
