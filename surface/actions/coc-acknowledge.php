<?php
/**
 * ACKNOWLEDGE THE CURRENT CODE OF CONDUCT VERSION — the signed-in employee's
 * own, once per version, recording who and when. Never edited afterwards.
 */
declare(strict_types=1);

$fail = static function (string $why): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ?r=code-of-conduct');
    exit;
};

if (!$may('employee.view')) {
    $fail('Denied: ' . $access->decide((string) $session->user()['role'], 'employee.view')->summary);
}

$own = $scope->own();
$own === null && $fail('Your account is not linked to an employee.');
post('agree') !== '1' && $fail('Tick the statement to acknowledge.');

$cfg = (array) json_decode((string) file_get_contents(__DIR__ . '/../../config/code-of-conduct.json'), true);
$today = date('Y-m-d');
$versions = array_values(array_filter((array) ($cfg['versions'] ?? []), static fn (array $v): bool => (string) ($v['effective_from'] ?? '') <= $today));
usort($versions, static fn (array $a, array $b): int => strcmp((string) $b['effective_from'], (string) $a['effective_from']));
$current = (string) ($versions[0]['version'] ?? '');

if ($current === '' || post('version') !== $current) {
    $fail('That is not the current version — reload the page and acknowledge the version shown.');
}

foreach ($store->all('code_of_conduct_ack') as $a) {
    if ((string) ($a['employee_id'] ?? '') === (string) $own && (string) ($a['version'] ?? '') === $current && (string) ($a['acknowledged'] ?? '') === '1') {
        $fail('You already acknowledged v' . $current . ' on ' . (string) ($a['acknowledged_at'] ?? $a['ack_date'] ?? '') . '.');
    }
}

$now = date('Y-m-d H:i:s');
$user = $session->user();
$row = $store->put('code_of_conduct_ack', [
    'employee_id' => (string) $own,
    'acknowledged' => 1,
    'ack_date' => $today,
    'version' => $current,
    'acknowledged_by' => (string) ($user['email'] ?? ''),
    'acknowledged_at' => $now,
    'created_at' => $now,
    'updated_at' => $now,
]);
$audit('code_of_conduct.acknowledged', 'code_of_conduct_ack', (string) $row['id'], null, $row, 'Acknowledged v' . $current);
$_SESSION['flash_notice'] = 'Thank you — Code of Conduct v' . $current . ' acknowledged on ' . $now . '.';
header('Location: ?r=code-of-conduct');
exit;
