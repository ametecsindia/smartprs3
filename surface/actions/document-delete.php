<?php
/**
 * REMOVE ONE EMPLOYEE DOCUMENT (SmartPRS 2's per-row delete on the Documents
 * modal). The row is retired with deleted_at — nothing is overwritten — and
 * the file stays on disk for the trail; the audit line names the kind.
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;

$ddBack = 'document-tracker';

if (!$may('employee.update')) {
    $_SESSION['flash_problem'] = 'Denied: ' . $access->decide((string) $session->user()['role'], 'employee.update')->summary;
    header('Location: ?r=' . $ddBack);
    exit;
}

$ddDoc = $store->find('documents', post('doc'));
$ddEmp = $ddDoc === null ? null : $store->find('employees', (string) $ddDoc['employee_id']);

if ($ddDoc === null || $ddEmp === null || !$scope->covers((string) $ddDoc['employee_id'], $may('employee.list'))) {
    $_SESSION['flash_problem'] = $ddDoc === null ? 'No such document.' : $scope->why((string) $ddDoc['employee_id']);
    header('Location: ?r=' . $ddBack);
    exit;
}

$store->put('documents', ['id' => $ddDoc['id'], 'deleted_at' => date('Y-m-d H:i:s')]);
$audit('document.removed', 'employment', Employment::code($ddEmp), ['kind' => $ddDoc['kind'], 'expiry' => $ddDoc['expiry'] ?? null], null, (string) $ddDoc['kind'] . ' removed from ' . Employment::label($ddEmp));
$_SESSION['flash_notice'] = (string) $ddDoc['kind'] . ' removed for ' . Employment::label($ddEmp) . '. The row is kept in history.';
header('Location: ?r=' . $ddBack . '&id=' . rawurlencode((string) $ddEmp['id']));
exit;
