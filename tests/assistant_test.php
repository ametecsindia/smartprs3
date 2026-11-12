<?php

declare(strict_types=1);

/**
 * THE ASSISTANT IS A PHRASE TABLE IN FRONT OF THE SCREENS' OWN CODE — it sends nothing
 * anywhere and cannot answer what the handlers it is given cannot.
 */

use SmartPRS\Surface\Assistant;

group('assistant');

$a = new Assistant(dirname(__DIR__) . '/config/assistant.json');

same('absent_today', $a->classify('Who is absent today?'), 'a declared phrase names the intent');
same('overdue_approvals', $a->classify('which approvals are overdue'), '"overdue" beats the generic approvals wording');
same('my_leave_balance', $a->classify('where is my leave balance'), 'the longest matching phrase wins, not the first section with "where"');
same('find_employee', $a->classify('who is SP-004'), 'a lookup');
same(null, $a->classify('bake me a cake'), 'nothing declared, nothing matched');
same('SP-004', Assistant::subjectOf('who is SP-004?'), 'the subject follows the verb');
same('Priya', Assistant::subjectOf('find Priya'), 'and for find');

$r = $a->answer('who is absent today', ['absent_today' => static fn (): array => ['answer' => '2 absent', 'links' => [['label' => 'x', 'href' => '?r=x']]]]);
same('2 absent', $r['answer'], 'the handler answers');
same('Who is absent today', $r['label'], 'under the declared label');
$none = $a->answer('who is absent today', []);
same(null, $none['intent'], 'an intent with no handler is "not understood", never a guess');
ok(str_contains($none['answer'], 'config/assistant.json'), 'and says where wording is added');
throws(RuntimeException::class, static fn () => (new Assistant('/nope.json'))->intents(), 'a missing phrase table is refused, not an empty assistant');
