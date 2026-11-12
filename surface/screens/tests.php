<?php declare(strict_types=1); if (!$may('employee.view')): ?>
<div class="page-header"><div><h2>Tests</h2></div></div>
<div class="card"><div class="problem" style="margin:0">Denied: <?= e($access->decide((string) $session->user()['role'], 'employee.view')->summary) ?></div></div>
<?php return; endif; ?>
<?php
// SmartPRS 2's Tests screen: the list of tests with the signed-in employee's
// own result, "Take Test" for one with questions, and for managers a way to
// the question bank. Taking a test renders its questions here; the answers go
// to surface/actions/test-submit.php, which scores them server-side.
use SmartPRS\Surface\Assessments;

$own = $scope->own();
$canManage = $may('employee.update');
$tests = liveRows($store, 'tests');
usort($tests, static fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
$questionsByTest = [];

foreach ($store->all('test_questions') as $q) {
    $questionsByTest[(string) ($q['test_id'] ?? '')][] = $q;
}

$mine = [];

if ($own !== null) {
    foreach ($store->all('test_attempts') as $a) {
        if ((string) ($a['employee_id'] ?? '') === (string) $own) {
            $prev = $mine[(string) $a['test_id']] ?? null;

            // The best finished attempt shows; a pass beats a fail beats anything else.
            $rank = static fn (?array $x): int => $x === null ? -1 : match ((string) ($x['status'] ?? '')) { Assessments::PASSED => 2, Assessments::FAILED => 1, default => 0 };
            $mine[(string) $a['test_id']] = $rank($a) > $rank($prev) || ($rank($a) === $rank($prev) && (int) ($a['score'] ?? 0) > (int) ($prev['score'] ?? 0)) ? $a : $prev;
        }
    }
}

$takeId = (string) ($_GET['take'] ?? '');
$taking = $takeId === '' || $own === null ? null : $store->find('tests', $takeId);
$passMark = static fn (mixed $v): string => str_contains((string) $v, '.') ? rtrim(rtrim((string) $v, '0'), '.') : (string) $v; // '50.0000' reads as 50
$takingQuestions = $taking === null ? [] : ($questionsByTest[$takeId] ?? []);
usort($takingQuestions, static fn (array $a, array $b): int => ((int) ($a['seq'] ?? 0) <=> (int) ($b['seq'] ?? 0)) ?: strcmp((string) $a['id'], (string) $b['id']));
?>
<div class="page-header">
  <div><h2>Tests</h2><p><?= $canManage ? 'Define tests and their question banks; employees take them and are scored instantly, server-side.' : 'Take your assigned tests — you are scored instantly.' ?></p></div>
<?php if ($canManage): ?>
  <div><a class="btn btn-outline" href="?r=m&k=tests"><i class="fas fa-plus"></i> Manage tests</a> <a class="btn btn-outline" href="?r=m&k=test-questions"><i class="fas fa-list-check"></i> Question bank</a> <a class="btn btn-outline" href="?r=m&k=test-attempts"><i class="fas fa-square-poll-vertical"></i> Results</a></div>
<?php endif; ?>
</div>
<?php if ($taking !== null): ?>
<div class="card">
  <h2><?= e((string) $taking['name']) ?> <span class="note">pass mark <?= e($passMark($taking['pass_mark'] ?? '')) ?>%</span></h2>
<?php if ($takingQuestions === []): ?>
  <p class="note">This test has no questions yet.</p>
<?php else: ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= e($session->token()) ?>"><input type="hidden" name="action" value="test-submit"><input type="hidden" name="test_id" value="<?= e($takeId) ?>">
<?php foreach ($takingQuestions as $i => $q): ?>
    <fieldset class="form-section"><legend><?= $i + 1 ?>. <?= e((string) ($q['question'] ?? '')) ?> <span class="note">(<?= max(0, (int) ($q['marks'] ?? 1)) ?: 1 ?> mark)</span></legend>
<?php foreach (Assessments::LETTERS as $l): if (trim((string) ($q['opt_' . $l] ?? '')) === '') { continue; } ?>
      <label style="display:block;margin:4px 0"><input type="radio" name="answer[<?= e((string) $q['id']) ?>]" value="<?= $l ?>"> <?= strtoupper($l) ?>) <?= e((string) $q['opt_' . $l]) ?></label>
<?php endforeach; ?>
    </fieldset>
<?php endforeach; ?>
    <button class="btn btn-primary" type="submit"><i class="fas fa-paper-plane"></i> Submit answers</button> <a class="btn btn-outline" href="?r=tests">Cancel</a>
    <p class="note">Unanswered questions score nothing. Your score is marks earned ÷ total marks, as a whole percent, compared exactly with the pass mark.</p>
  </form>
<?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
<?php if ($tests === []): ?>
  <p class="note">No tests yet.<?= $canManage ? ' Add one under Manage tests, then its questions in the question bank.' : '' ?></p>
<?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Test</th><th>Category</th><th>For</th><th class="n">Pass %</th><th class="n">Questions</th><th>My result</th><th></th></tr></thead>
    <tbody>
<?php foreach ($tests as $t): $id = (string) $t['id']; $n = count($questionsByTest[$id] ?? []); $r = $mine[$id] ?? null; ?>
      <tr>
        <td><b><?= e((string) ($t['name'] ?? '')) ?></b></td>
        <td class="note"><?= e((string) ($t['category'] ?? '')) ?></td>
        <td class="note"><?= e(trim((string) ($t['target_type'] ?? '') . ' ' . (string) ($t['target'] ?? ''))) ?: 'everybody' ?></td>
        <td class="n"><?= e($passMark($t['pass_mark'] ?? '')) ?></td>
        <td class="n"><?= $n ?></td>
        <td><?php if ($r === null): ?><span class="note">—</span><?php else: ?><span class="badge badge-<?= (string) $r['status'] === Assessments::PASSED ? 'green' : ((string) $r['status'] === Assessments::FAILED ? 'red' : 'gray') ?>"><?= e(strtoupper((string) $r['status'])) ?> <?= e($passMark($r['score'] ?? '')) ?>%</span> <span class="note"><?= e((string) ($r['marks_scored'] ?? '')) ?>/<?= e((string) ($r['total_marks'] ?? '')) ?> on <?= e((string) ($r['attempted_on'] ?? '')) ?></span><?php endif; ?></td>
        <td><?php if ($n > 0 && $own !== null): ?><a class="btn btn-primary btn-sm" href="?r=tests&amp;take=<?= e($id) ?>"><i class="fas fa-pen-to-square"></i> Take test</a><?php elseif ($n === 0): ?><span class="note">No questions yet</span><?php else: ?><span class="note">Your account is not linked to an employee</span><?php endif; ?></td>
      </tr>
<?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
