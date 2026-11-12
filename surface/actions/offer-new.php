<?php
/**
 * OFFER LETTER TO A NEW CANDIDATE (owner, 12 Nov 2026: "Offer letters — New employee or Existing; if New, text boxes for
 * all the relevant fields"). HR Letters → Offer Letters → New candidate. The typed fields stand in for an employee
 * record; the same template, the same renderer (blanks refused), the same salary-structure annexure on the typed CTC.
 * Preview keeps the draft in the session; Send keeps the text that went out (letters, no employee yet) and emails it.
 */
declare(strict_types=1);

use SmartPRS\Surface\Letters;

$onBack = '?r=letters-offer&for=new#lk-issue';
$onTemplate = $letters->find('offer');

if (post('op') === 'clear') {
    unset($_SESSION['offer_new']);
    header('Location: ' . $onBack);
    exit;
}

if ($onTemplate === null) {
    $_SESSION['flash_problem'] = 'Nothing in config/letters.json declares an offer letter.';
    header('Location: ' . $onBack);
    exit;
}

$onDenied = array_values(array_filter(Letters::permissionsFor($onTemplate), static fn (string $p): bool => !$may($p)));
if ($onDenied !== []) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], $onDenied[0])->summary;
    header('Location: ' . $onBack);
    exit;
}

$onIn = [];
foreach (['name' => 120, 'email' => 160, 'mobile' => 20, 'address' => 400, 'employer_company_id' => 20, 'branch_id' => 20, 'designation_id' => 20, 'doj' => 10, 'ctc' => 20, 'sig' => 30] as $k => $max) {
    $onIn[$k] = mb_substr(trim(post($k)), 0, $max);
}
$onIn['ctc'] = (string) preg_replace('/[^0-9.]/', '', $onIn['ctc']);
$_POST['sig'] = $onIn['sig']; // letterContextFor() reads the signatory pick from the request

// The candidate as a letter sees an employee: names resolved from the ids (company, branch, designation).
$onSubject = orgEnrich($store, ['id' => '', 'emp_code' => '', 'name' => $onIn['name'], 'email' => $onIn['email'], 'mobile' => $onIn['mobile'],
    'address' => $onIn['address'], 'employer_company_id' => $onIn['employer_company_id'], 'branch_id' => $onIn['branch_id'],
    'designation_id' => $onIn['designation_id'], 'doj' => $onIn['doj'], 'ctc' => $onIn['ctc']]);
$onCtx = letterContextFor($onTemplate, $onSubject, $onIn['doj'], $company);
$onR = $letters->render($onTemplate, $onSubject, $onCtx);
$_SESSION['offer_new'] = $onIn + ['body' => $onR['ok'] ? $onR['body'] : '', 'missing' => $onR['missing']];

if (post('op') === 'send') {
    if (!$onR['ok']) {
        $_SESSION['flash_problem'] = 'Not sent — still needed: ' . implode(', ', $onR['missing']) . '.';
    } elseif (filter_var($onIn['email'], FILTER_VALIDATE_EMAIL) === false) {
        $_SESSION['flash_problem'] = 'Not sent — give the candidate\'s email address.';
    } else {
        $onCc = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', post('cc')) ?: []), static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) !== false));
        $onSent = (new SmartPRS\Surface\AlertEmails($store, $customisation, __DIR__ . '/../../config/alert-emails.json'))->send([$onIn['email']], $onCc,
            letterEmailSubject($onTemplate, $onSubject, $company, ''), $onR['body']);

        if (!$onSent['ok']) {
            $_SESSION['flash_problem'] = 'Not sent: ' . $onSent['why'];
        } else {
            try {
                // THE TEXT THAT WENT OUT, kept as it went out; no employee yet (listed as "New candidate").
                $onRow = $store->put('letters', ['employee_id' => null, 'letter_type' => 'offer', 'issued_on' => date('Y-m-d'), 'status' => 'issued',
                    'body' => $onR['body'], 'issued_by' => (string) $session->user()['email']]);
                $audit('letter.issued', 'letters', (string) ($onRow['id'] ?? ''), null, ['letter_type' => 'offer', 'candidate' => $onIn['name'], 'email' => $onIn['email']], 'Offer letter sent to new candidate ' . $onIn['name']);
                $kept = '';
            } catch (\Throwable $onX) {
                error_log('Offer letter to a new candidate not kept: ' . $onX->getMessage());
                $kept = ' (it was emailed, but the copy could not be kept in the register: ' . $onX->getMessage() . ')';
            }
            unset($_SESSION['offer_new']);
            $_SESSION['flash_notice'] = 'Offer letter sent to ' . $onIn['name'] . ' (' . $onIn['email'] . ')' . ($onCc !== [] ? ', copy to ' . implode(', ', $onCc) : '') . '.' . $kept;
        }
    }
}

header('Location: ' . $onBack);
exit;
