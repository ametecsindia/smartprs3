<?php
/**
 * "I ACKNOWLEDGE" (owner, 23 Oct 2026): the signed-in employee signs off a letter issued to them that needs
 * acknowledgement. Once per letter; kept as signed — who, when, from where. Feeds the NDA Sign-off report.
 */
declare(strict_types=1);

$laFail = static function (string $why): never {
    $_SESSION['flash_problem'] = $why;
    header('Location: ?r=letters-mine');
    exit;
};

$laOwn = $scope->own();
$laOwn === null && $laFail('Your login is not linked to an employee record.');
post('agree') !== '1' && $laFail('Tick "I have read this letter and I accept it" first.');
$laLetter = $store->find('letters', post('id'));
($laLetter === null || (string) ($laLetter['employee_id'] ?? '') !== (string) $laOwn) && $laFail('That letter was not issued to you.');
$laT = $letters->find((string) ($laLetter['letter_type'] ?? ''));
($laT === null || !SmartPRS\Surface\Letters::needsAck($laT)) && $laFail('This letter does not need an acknowledgement.');

try {
    foreach ($store->all('letter_acks') as $a) {
        (string) ($a['letter_id'] ?? '') === (string) $laLetter['id'] && $laFail('Already acknowledged on ' . date('d-m-Y H:i', (int) strtotime((string) $a['acknowledged_at'])) . '.');
    }
    $now = date('Y-m-d H:i:s');
    $row = $store->put('letter_acks', ['letter_id' => (string) $laLetter['id'], 'employee_id' => (string) $laOwn, 'letter_type' => (string) $laLetter['letter_type'],
        'acknowledged_at' => $now, 'acknowledged_by' => (string) $session->user()['email'], 'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'created_at' => $now, 'updated_at' => $now]);
} catch (\Throwable $e) {
    $laFail('Could not record the acknowledgement (' . $e->getMessage() . '). Please tell HR.');
}
$audit('letter.acknowledged', 'letter_acks', (string) ($row['id'] ?? ''), null, ['letter_id' => $laLetter['id'], 'letter_type' => $laLetter['letter_type']], (string) $laT['label'] . ' acknowledged');
$_SESSION['flash_notice'] = 'Thank you — ' . (string) $laT['label'] . ' acknowledged on ' . date('d-m-Y H:i', strtotime($now)) . '.';
header('Location: ?r=letters-mine');
exit;
