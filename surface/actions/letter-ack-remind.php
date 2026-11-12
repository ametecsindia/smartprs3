<?php
/**
 * REMIND EVERYONE PENDING (owner, 23 Oct 2026): from the Sign-off report, put an "acknowledge this letter"
 * notice in the app for every employee whose latest letter of this type is not acknowledged yet. A letter
 * already notified is not notified twice (the notice is deduped per letter).
 */
declare(strict_types=1);

if (!$may('employee.letters.view') || !$scope->seesEveryone($may('employee.list'))) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.letters.view')->summary;
    header('Location: ?r=letter-signoff');
    exit;
}

$arT = $letters->find(post('t'));
$arSent = 0;
if ($arT !== null && SmartPRS\Surface\Letters::needsAck($arT)) {
    foreach (liveRows($store, 'employees') as $arE) {
        if (strtolower(trim((string) ($arE['status'] ?? 'active'))) !== 'active') { continue; }
        foreach (letterPendingAcks((string) $arE['id']) as $arL) {
            if ((string) $arL['letter_type'] === (string) $arT['key']) {
                letterAskAck($arE, $arL, $arT);
                $arSent++;
                break;
            }
        }
    }
    $audit('letter.ack_reminded', 'letters', (string) $arT['key'], null, ['employees' => $arSent], 'Acknowledgement notice sent to pending employees');
}
$_SESSION['flash_notice'] = $arSent . ' employee(s) with a pending ' . (string) ($arT['label'] ?? 'letter') . ' were sent an in-app notice to acknowledge it (anyone already notified for the same letter is not notified twice).';
header('Location: ?r=letter-signoff&t=' . rawurlencode(post('t')));
exit;
