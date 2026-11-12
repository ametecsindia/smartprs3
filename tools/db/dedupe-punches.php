<?php
/**
 * PUNCH CLEAN-UP, ONE TIME (16 Oct 2026). Two steps, in this order:
 *
 *  1. EXACT DUPLICATES — same workspace, employee, instant and direction — were
 *     possible before 16 Oct (double-clicked Add, refreshed page, two sources).
 *     The first is kept; the repeats are removed.
 *  2. MANUAL PUNCHES WITH NO LINK — every punch typed in on Manual Entry gets its
 *     approved "Manual entry" regularization (who typed it is read from the audit
 *     trail), and the punch carries that regularization's id — the same link an
 *     approved request's punch has. A matching row that already exists is reused.
 *
 *   php tools\db\dedupe-punches.php           shows both lists, changes NOTHING
 *   php tools\db\dedupe-punches.php --apply   does both; removed rows are first
 *                                             written to storage/backups/
 *
 * Safe to run again: with nothing left to do it reports 0 and 0.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/../../surface/partials/endpoint-boot.php';

$apply = in_array('--apply', $argv, true);

// ---- 1. duplicates ---------------------------------------------------------
$repeats = $pdo->query(
    'SELECT a.* FROM attendance_logs a
       JOIN (SELECT tenant_id, emp_code, punch_at, direction, MIN(id) AS keep_id
               FROM attendance_logs
              GROUP BY tenant_id, emp_code, punch_at, direction
             HAVING COUNT(*) > 1) d
         ON a.tenant_id <=> d.tenant_id AND a.emp_code = d.emp_code
        AND a.punch_at = d.punch_at AND a.direction = d.direction AND a.id <> d.keep_id
      ORDER BY a.tenant_id, a.emp_code, a.punch_at, a.id'
)->fetchAll(PDO::FETCH_ASSOC);

echo "1. DUPLICATE PUNCHES\n";
foreach ($repeats as $r) {
    printf("   tenant %-3s %-12s %s %-3s source=%-10s id=%s\n", $r['tenant_id'], $r['emp_code'], $r['punch_at'], $r['direction'], $r['source'] ?? '', $r['id']);
}
echo '   ' . count($repeats) . " duplicate(s).\n";
$dupIds = array_flip(array_column($repeats, 'id'));

// ---- 2. unlinked manual punches -------------------------------------------
$manual = array_values(array_filter($pdo->query(
    "SELECT * FROM attendance_logs WHERE source = 'manual' AND (external_id IS NULL OR external_id = '') ORDER BY tenant_id, emp_code, punch_at"
)->fetchAll(PDO::FETCH_ASSOC), static fn (array $r): bool => !isset($dupIds[$r['id']])));

$audit = $pdo->query("SELECT tenant_id, entity_id, actor, payload FROM audit WHERE action = 'attendance.punch.recorded'")->fetchAll(PDO::FETCH_ASSOC);
$whoTyped = static function (array $p) use ($audit): string {
    $at = substr((string) $p['punch_at'], 0, 16);
    $variants = [str_replace(' ', 'T', $at), $at, str_replace(' ', 'T', $at) . ':00', $at . ':00'];

    foreach ($audit as $a) {
        if ((string) $a['tenant_id'] !== (string) $p['tenant_id'] || (string) $a['entity_id'] !== (string) $p['emp_code']) {
            continue;
        }

        $payload = str_replace('\\/', '/', (string) $a['payload']);

        foreach ($variants as $v) {
            if (str_contains($payload, $v) && str_contains($payload, '"' . $p['direction'] . '"')) {
                return (string) $a['actor'];
            }
        }
    }

    return 'recorded manually before 16-10-2026';
};

echo "\n2. MANUAL PUNCHES NOT YET LINKED TO A REGULARIZATION\n";
foreach ($manual as $p) {
    printf("   tenant %-3s %-12s %s %-3s by %s\n", $p['tenant_id'], $p['emp_code'], $p['punch_at'], $p['direction'], $whoTyped($p));
}
echo '   ' . count($manual) . " to link.\n\n";

if (!$apply) {
    echo ($repeats === [] && $manual === []) ? "Nothing to do.\n" : "Nothing changed. Run again with --apply to do both.\n";
    exit(0);
}

if ($repeats !== []) {
    $dir = __DIR__ . '/../../storage/backups';
    is_dir($dir) || mkdir($dir, 0775, true);
    $file = $dir . '/dedupe-punches-' . date('Ymd-His') . '.json';

    if (file_put_contents($file, json_encode($repeats, JSON_PRETTY_PRINT)) === false) {
        fwrite(STDERR, "Could not write the backup {$file}; nothing changed.\n");
        exit(1);
    }

    $pdo->beginTransaction();
    $del = $pdo->prepare('DELETE FROM attendance_logs WHERE id = ?');
    foreach ($repeats as $r) {
        $del->execute([$r['id']]);
    }
    $pdo->commit();
    echo count($repeats) . " duplicate(s) removed. Backup: {$file}\n";
}

$linked = 0;
$reused = 0;
$find = $pdo->prepare("SELECT id FROM attendance_corrections WHERE tenant_id <=> ? AND emp_code = ? AND log_date = ? AND sub_type = ?
    AND reason = 'Manual entry' AND (req_in = ? OR req_out = ?) LIMIT 1");

foreach ($manual as $p) {
    $ts = $platform->forTenant((string) $p['tenant_id']);
    $time = substr((string) $p['punch_at'], 11, 8);
    $find->execute([$p['tenant_id'], $p['emp_code'], substr((string) $p['punch_at'], 0, 10), $p['direction'], $time, $time]);
    $ref = $find->fetchColumn();

    if ($ref === false) {
        $ref = $ts->put('attendance_corrections', [
            'emp_code' => $p['emp_code'], 'log_date' => substr((string) $p['punch_at'], 0, 10), 'sub_type' => $p['direction'],
            $p['direction'] === 'in' ? 'req_in' : 'req_out' => $time, 'reason' => 'Manual entry', 'status' => 'approved',
            'decided_by' => $whoTyped($p), 'decided_at' => (string) ($p['created_at'] ?? date('Y-m-d H:i:s')),
        ])['id'];
        $linked++;
    } else {
        $reused++;
    }

    $ts->put('attendance_logs', ['id' => $p['id'], 'external_id' => (string) $ref]);
}

echo "{$linked} manual punch(es) given an approved \"Manual entry\" regularization, {$reused} linked to one that already existed.\n";
