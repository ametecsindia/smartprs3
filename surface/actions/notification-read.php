<?php
/**
 * MARK A NOTIFICATION READ — the viewer's own, or any when they may read the
 * tenant's trail. Then go where the message points (PRS2: click an item to
 * open the screen it refers to), or back to the list.
 */
declare(strict_types=1);

use SmartPRS\Surface\Employment;
use SmartPRS\Surface\Notifications;

$nrOwnRow = $scope->own() === null ? null : $store->find('employees', (string) $scope->own());
$nrCode = $nrOwnRow === null ? '' : Employment::code($nrOwnRow);
$nrOk = $notifications->markRead(post('id'), $nrCode, $may('audit.tenant.read'), date('Y-m-d H:i:s'),
    [Notifications::ROLE_PREFIX . (string) ($session->user()['role'] ?? '')]);
$nrRoutes = (array) (json_decode((string) file_get_contents(__DIR__ . '/../../config/notification-routes.json'), true)['routes'] ?? []);
$nrRow = $nrOk ? $store->find('notification_log', post('id')) : null;
$nrTo = $nrRow === null ? '' : Notifications::routeFor($nrRow, $nrRoutes);

if (!$nrOk) {
    $_SESSION['flash_problem'] = 'That message is not yours to mark.';
}

header('Location: ' . ($nrTo !== '' && post('go') === '1' ? $nrTo : '?r=notifications' . (post('kind') !== '' ? '&kind=' . rawurlencode(post('kind')) : '')
    // …and the org filter the list was showing, so marking one read keeps the view.
    . implode('', array_map(static fn (string $k): string => post('f_' . $k) !== '' ? '&f_' . $k . '=' . rawurlencode(post('f_' . $k)) : '', array_keys(ORG_FILTERS)))));
exit;
