<?php

declare(strict_types=1);

/**
 * DOES THIS TENANT'S REPORTING DATA CONTAIN A CYCLE?
 *
 * `Platform\Access\DataScope` refuses a reporting chain that repeats — "a cycle
 * is a data defect, never a licence to widen scope" — and it cannot fire against
 * this product, because the surface hands it a chain one manager long and a
 * one-element chain cannot repeat. So nothing had ever asked.
 *
 * It matters because a cycle is not a tidiness problem. The manager role reaches
 * its direct reports; in a two-person ring each IS the other's direct report, so
 * each can read the other's salary, leave and attendance — and every read is
 * correctly authorised, because the data says they are managers. Nothing looks
 * broken. The answer is just wrong, which is the kind that survives an audit.
 *
 * A REFUSAL IS NOT A PASS. No database, or no employments to walk, exits 2 —
 * `tools/check.php` treats that as a fatal skip, because "no cycles found" and
 * "nothing was looked at" are the same line of output and opposite facts.
 */

require_once __DIR__ . '/../tests/lib/bootstrap.php';

use SmartPRS\Surface\Database;
use SmartPRS\Surface\ReportingLines;
use SmartPRS\Surface\SqlStore;

$root = dirname(__DIR__);

try {
    $pdo = Database::connect($root . '/.env');
} catch (\Throwable $e) {
    fwrite(STDERR, "Refused: no database.\n" . $e->getMessage() . "\n");

    exit(2);
}

$tenant = (string) $pdo->query('SELECT tenant_id FROM employees GROUP BY tenant_id '
    . 'ORDER BY COUNT(*) DESC LIMIT 1')->fetchColumn();

if ($tenant === '') {
    fwrite(STDERR, "Refused: there is no tenant with employments in it, so this check would\n"
        . "report no cycles having walked nothing at all.\n");

    exit(2);
}

$store = (new SqlStore($pdo))->forTenant($tenant);
$lines = new ReportingLines($store);
$people = count($store->all('employees'));

if ($people === 0) {
    fwrite(STDERR, "Refused: tenant {$tenant} has no employments to walk.\n");

    exit(2);
}

$cycles = $lines->cycles();
$dangling = $lines->danglingManagers();

printf("  %d employment(s) walked in tenant %s\n\n", $people, $tenant);

foreach ($cycles as $cycle) {
    printf("  CYCLE     %s\n            %s\n", implode(' → ', $cycle['members']), $cycle['why']);
}

foreach ($dangling as $one) {
    printf("  DANGLING  %s reports to '%s', which is not an employment in this tenant.\n"
        . "            Their leave reaches nobody's queue, and the screen shows a manager\n"
        . "            because the column has a value in it.\n",
        $one['employment'], $one['manager']);
}

if ($cycles !== [] || $dangling !== []) {
    printf("\nFAIL  %d reporting cycle(s), %d dangling manager(s) over %d employment(s).\n",
        count($cycles), count($dangling), $people);

    exit(1);
}

printf("PASS  %d employment(s), 0 reporting cycle(s), 0 dangling manager(s).\n", $people);

exit(0);
