<?php declare(strict_types=1);
// DEV ONLY (12 Oct): move job posts kept in the file store (before migration 2026_10_12_000100 ran)
// into the job_postings table. Skips a requisition that already has a post in the table. Delete after.
if ((string) ($session->user()['role'] ?? '') !== 'administrator') { return; }
if ($jobPostsInFiles) { echo '<pre id="mv">The job_postings table is not there yet — run composer db:migrate first.</pre>'; return; }
$mvFiles = (new SmartPRS\Surface\Store(__DIR__ . '/../../storage'))->forTenant($tenantId === '' ? '1' : $tenantId);
$mvCols = array_flip($store->columnsOf(SmartPRS\Surface\JobPostings::TABLE));
$mvHave = array_flip(array_map(static fn (array $p): string => (string) $p['job_id'], $store->all(SmartPRS\Surface\JobPostings::TABLE)));
$mvN = 0;
$mvSkip = 0;
foreach ($mvFiles->all(SmartPRS\Surface\JobPostings::TABLE) as $mvRow) {
    if (isset($mvHave[(string) $mvRow['job_id']])) { $mvSkip++; continue; }
    unset($mvRow['id'], $mvRow['tenant_id']);
    $store->put(SmartPRS\Surface\JobPostings::TABLE, array_intersect_key($mvRow, $mvCols));
    $mvHave[(string) $mvRow['job_id']] = true;
    $mvN++;
}
?><pre id="mv"><?= e("{$mvN} job post(s) moved into the database, {$mvSkip} already there.") ?></pre>
