<?php declare(strict_types=1);
/**
 * RECRUITMENT PIPELINE — the rows for the Excel/CSV (surface/actions/people-export.php
 * with $params = the posted form: req, stage). The screen's pipeline table shows
 * these same candidates; the file adds their contact columns.
 */
$rcReq = trim((string) ($params['req'] ?? ''));
$rcStage = trim((string) ($params['stage'] ?? ''));
$export = null;

if (!$may('recruitment.pipeline.view')) {
    return;
}

$rcReqById = [];

foreach ($recruitment->requisitions() as $rr) {
    $rcReqById[(string) $rr['id']] = $rr;
}

$rcRows = array_values(array_filter($recruitment->candidates($rcReq === '' ? null : $rcReq), static fn (array $c): bool => $rcStage === '' || (string) $c['stage'] === $rcStage));
$export = ['title' => 'Recruitment pipeline' . ($rcReq !== '' && isset($rcReqById[$rcReq]) ? ' — ' . $rcReqById[$rcReq]['req_code'] : '') . ($rcStage !== '' ? ' — ' . $rcStage : ''),
    'filter' => array_filter(['requisition' => isset($rcReqById[$rcReq]) ? (string) $rcReqById[$rcReq]['req_code'] : '', 'stage' => $rcStage]),
    'columns' => ['CANDIDATE', 'MOBILE', 'EMAIL', 'POSITION', 'REQUISITION', 'SOURCE', 'LOCATION', 'EXPERIENCE (YRS)', 'CURRENT CTC', 'EXPECTED CTC', 'INTERVIEWS', 'CURRENT ROUND', 'STAGE', 'APPLIED ON'],
    'rows' => array_map(static fn (array $c): array => [(string) $c['name'], (string) ($c['mobile'] ?? ''), (string) ($c['email'] ?? ''), (string) ($c['position'] ?? ''),
        isset($rcReqById[(string) ($c['job_id'] ?? '')]) ? (string) $rcReqById[(string) $c['job_id']]['req_code'] : 'talent pool', (string) ($c['source'] ?? ''), (string) ($c['location'] ?? ''),
        (string) ($c['experience_years'] ?? ''), (string) ($c['current_ctc'] ?? ''), (string) ($c['expected_ctc'] ?? ''), (string) count($recruitment->interviews((string) $c['id'])), \SmartPRS\Surface\Recruitment::roundStatus($recruitment->interviews((string) $c['id'])),
        (string) $c['stage'], (string) ($c['applied_on'] ?? '')], $rcRows)];
