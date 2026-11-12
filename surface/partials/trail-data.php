<?php declare(strict_types=1);
/**
 * THE ACTIVITY LOG'S ROWS — included by the trail screen (with $params = $_GET)
 * and by surface/actions/trail-export.php (with $params = $_POST), so the
 * download is exactly the rows shown. Sets $tlPeriod, $tlAction, $tlActor,
 * $tlType, $tlWord, $tlLimit, $tlKeep and $tlRows.
 */
$tlPeriod = isset($params['p']) ? reportPeriod($params, financialYearStart($customisation), date('Y-m-d')) : null;
$tlAction = trim((string) ($params['a'] ?? ''));
$tlActor = trim((string) ($params['by'] ?? ''));
$tlType = trim((string) ($params['t'] ?? ''));
$tlWord = trim((string) ($params['q'] ?? ''));
$tlLimit = max(10, min(5000, (int) ($params['n'] ?? 300)));
$tlKeep = ['a' => $tlAction, 'by' => $tlActor, 't' => $tlType, 'q' => $tlWord, 'n' => (string) $tlLimit]
    + ($tlPeriod === null ? [] : ['p' => $tlPeriod['mode'], 'month' => $tlPeriod['month'], 'year' => $tlPeriod['year'], 'fy' => (string) ($params['fy'] ?? ''), 'from' => $tlPeriod['from'], 'to' => $tlPeriod['to']]);
$tlRows = $trail->search($tlPeriod['from'] ?? '', $tlPeriod['to'] ?? '', $tlAction, $tlActor, $tlType, $tlWord, $tlLimit);
