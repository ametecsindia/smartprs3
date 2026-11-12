<?php declare(strict_types=1);
// DEV ONLY (12 Oct): the two Pune sample branches had no latitude/longitude, so the Geo-Fence Map
// could not draw them. Approximate centres of the business parks they are named after. Only blanks are filled. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
$bcKnown = ['eon it park' => ['18.552300', '73.951200'], 'cyber city' => ['18.516700', '73.930000']];
$bcOut = [];
foreach (liveRows($store, 'branches') as $b) {
    $k = strtolower(trim((string) $b['name']));
    if (!isset($bcKnown[$k]) || trim((string) ($b['lat'] ?? '')) !== '' || trim((string) ($b['lng'] ?? '')) !== '') { continue; }
    $store->put('branches', ['id' => $b['id'], 'lat' => $bcKnown[$k][0], 'lng' => $bcKnown[$k][1]]);
    $bcOut[] = $b['name'];
}
?><pre id="bc"><?= e($bcOut === [] ? 'Nothing to fill.' : 'Filled: ' . implode(', ', $bcOut)) ?></pre>
