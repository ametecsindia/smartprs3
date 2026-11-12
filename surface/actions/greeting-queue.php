<?php
/**
 * QUEUE ONE GREETING for one person on one occasion. Refused when it was already
 * queued for that date, when the wording does not render, or when the person
 * has no address on the greeting's channel.
 */
declare(strict_types=1);

$back = static function (string $key, string $text): never {
    $_SESSION[$key] = $text;
    header('Location: ?r=greetings');
    exit;
};

if (!$may('employee.update')) {
    $back('flash_problem', 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary);
}

$messaging = new SmartPRS\Surface\Messaging($store, __DIR__ . '/../../config/communication.json');
$greetings = new SmartPRS\Surface\Greetings($messaging, $letters);
$id = post('e');
$kind = post('kind');
$on = post('on');
$employee = $id === '' ? null : $store->find('employees', $id);

if ($employee === null || !$scope->covers($id, $may('employee.view'))) {
    $back('flash_problem', 'No such employee in your scope.');
}

// The entry is re-derived from the record, never trusted from the form.
$entry = null;

foreach ($greetings->due([$employee], $on, 0) as $candidate) {
    if ($candidate['kind'] === $kind) {
        $entry = $candidate;
    }
}

if ($entry === null) {
    $back('flash_problem', 'That person is not due a ' . $kind . ' greeting on ' . $on . '.');
}

if ($greetings->alreadyQueued($id, $kind, $on)) {
    $back('flash_problem', 'That greeting was already queued for ' . $on . '. A second click must not send a second wish.');
}

$rendered = $greetings->render($entry, $company);

if (!$rendered['ok']) {
    $back('flash_problem', $rendered['why']);
}

$address = $messaging->addressOf($employee, $rendered['channel']);

if ($address === '') {
    $back('flash_problem', SmartPRS\Surface\Employment::code($employee) . ' has no ' . $rendered['channel'] . ' address on the employee.');
}

$user = $session->user();
$result = $messaging->queue([['employee' => $employee, 'channel' => $rendered['channel'], 'address' => $address]],
    $kind . ' ' . $on, $rendered['subject'], $rendered['body'],
    (string) ($user['id'] ?? ''), (string) ($user['email'] ?? ''), 'greeting');
$audit('greeting.queued', 'messages_log', $result['batch'], null,
    ['employee_id' => $id, 'kind' => $kind, 'on' => $on, 'channel' => $rendered['channel']],
    'Queued from Greetings; no transport is configured in this build.');
$back('flash_notice', ucfirst($kind) . ' greeting queued for ' . SmartPRS\Surface\Employment::code($employee) . ' on ' . $rendered['channel'] . ' (status ' . (string) $messaging->config()['queued_status'] . ' — nothing was sent).');
