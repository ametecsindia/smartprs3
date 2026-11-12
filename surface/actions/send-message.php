<?php
/**
 * QUEUE A BROADCAST: one row per recipient and channel, status queued.
 *
 * The scope and channels are re-resolved here from what was posted, not trusted
 * from the preview — the preview is a picture, this is the write. No transport
 * is configured in this build, so the rows are queued and the flash says so.
 */
declare(strict_types=1);

$back = static function (string $key, string $text): never {
    $_SESSION[$key] = $text;
    header('Location: ?r=send-message');
    exit;
};

if (!$may('employee.update')) {
    $back('flash_problem', 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary);
}

$messaging = new SmartPRS\Surface\Messaging($store, __DIR__ . '/../../config/communication.json');
$filter = [];

foreach (array_keys(ORG_FILTERS) as $k) {
    $v = post('f_' . $k);
    $filter[$k] = strcasecmp($v, 'all') === 0 ? '' : $v;
}

$channels = array_values(array_intersect(array_keys($messaging->channels()),
    array_map('strval', is_array($_POST['channels'] ?? null) ? $_POST['channels'] : [])));
$subject = post('subject');
$message = post('message');

if ($subject === '' || $message === '') {
    $back('flash_problem', 'Subject and message are both required.');
}

if ($channels === []) {
    $back('flash_problem', 'Pick at least one channel.');
}

$people = $scope->only($scope->only(liveRows($store, 'employees'), $may('employee.list')), $may('employee.view'));
$resolved = $messaging->resolve(orgFilterApply($store, $people, $filter), $channels);

if ($resolved['to'] === []) {
    $back('flash_problem', 'Nobody in that scope has an address on the channels picked, so nothing was queued.');
}

$target = implode(' · ', array_filter(array_map(static fn (string $k, string $v): string => $v === '' ? '' : ORG_FILTERS[$k] . ' ' . $v, array_keys($filter), $filter))) ?: 'ALL employees';
$user = $session->user();
$result = $messaging->queue($resolved['to'], $target, $subject, $message,
    (string) ($user['id'] ?? ''), (string) ($user['email'] ?? ''));
$audit('message.queued', 'messages_log', $result['batch'], null,
    ['target' => $target, 'channels' => $channels, 'queued' => $result['queued'], 'subject' => $subject,
        'missing' => array_map('count', $resolved['missing'])],
    'Queued from Send Message; no transport is configured in this build.');
$skipped = array_sum(array_map('count', $resolved['missing']));
$back('flash_notice', sprintf('%d message%s queued for %s on %s (status %s — no transport is configured, nothing was sent).%s',
    $result['queued'], $result['queued'] === 1 ? '' : 's', $target, implode(', ', $channels),
    (string) $messaging->config()['queued_status'],
    $skipped > 0 ? " {$skipped} recipient-channel pair" . ($skipped === 1 ? '' : 's') . ' had no address and were not queued.' : ''));
