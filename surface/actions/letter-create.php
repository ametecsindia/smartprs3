<?php
/**
 * NEW LETTER TEMPLATE (owner, 23 Oct 2026): HR Letters → Letter Templates → "New letter template".
 * Stored as a versioned customisation (no file edit, no migration); appears in every letter dropdown.
 */
declare(strict_types=1);

if (!$may('tenant.settings.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'tenant.settings.update')->summary;
} else {
    $lcResult = $letters->create(post('label'), (string) ($_POST['body'] ?? ''), explode(',', post('requires')),
        $modules->editable((array) $modules->find('employees')), (string) $session->user()['id'], (string) $session->user()['email'], post('note'), post('lop_days'), post('email_subject'), post('needs_ack') === '1');

    if ($lcResult['ok']) {
        $audit('letter.template_created', 'module_customisations', (string) $lcResult['key'], null, ['label' => post('label')], 'Created the ' . post('label') . ' letter template');
        $_SESSION['flash_notice'] = $lcResult['why'];
    } else {
        $_SESSION['flash_problem'] = $lcResult['why'];
        // What was typed comes back into the form, so a fix is one word, not a retype.
        $_SESSION['letter_new_draft'] = ['label' => post('label'), 'body' => (string) ($_POST['body'] ?? ''), 'requires' => post('requires'), 'lop_days' => post('lop_days'), 'email_subject' => post('email_subject'), 'needs_ack' => post('needs_ack')];
    }
}

header('Location: ?r=letters-templates');
exit;
