<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * BGV DETAILS TO THE VERIFICATION AGENCY (owner, 21 Oct 2026): one email for one or many
 * employees — picked on the BGV desk or listed in an uploaded CSV/Excel of employee codes —
 * with each employee's details in the body, the same details as an attached CSV, and the
 * documents on file attached (up to config/bgv.json agency_mail.attach_limit_mb in total).
 * Which details go is config/bgv.json agency_mail.fields, not code.
 */
final class BgvAgencyMail
{
    /**
     * Employee codes from an uploaded list (CSV text; Excel is converted by Sheet::toCsv first).
     * The first column of every row; a header row ("EMP CODE", "Employee ID"…) is skipped.
     *
     * @return list<string>
     */
    public static function codes(string $csv): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', ltrim($csv, "\xEF\xBB\xBF")) ?: [] as $i => $line) {
            $c = trim((string) (str_getcsv($line, ',', '"', '')[0] ?? ''), " \t\"'");
            if ($c === '' || ($i === 0 && preg_match('/code|emp|id/i', $c) === 1 && preg_match('/\d/', $c) !== 1)) {
                continue;
            }
            $out[strtolower($c)] = $c;
        }

        return array_values($out);
    }

    /**
     * @param list<array{code: string, name: string, details: array<string, string>, docs: array<string, string>}> $people
     *        details = label => value; docs = attachment name => absolute file path
     * @param list<string> $checks the check labels asked for
     * @return array{subject: string, body: string, attachments: list<array{0: string, 1: string}>, skipped: list<string>}
     */
    public static function build(array $people, array $checks, string $agency, string $company, int $limitBytes, string $today): array
    {
        $n = count($people);
        $subject = "Background verification request — {$n} employee" . ($n === 1 ? '' : 's') . " — {$company}";
        $body = 'Dear ' . ($agency !== '' ? $agency : 'Verification team') . ",\n\n"
            . "Please carry out the background verification below for {$n} employee" . ($n === 1 ? '' : 's') . " of {$company}.\n"
            . 'Checks requested: ' . ($checks === [] ? '—' : implode(', ', $checks)) . "\n\n";
        $labels = [];
        $attach = [];
        $skipped = [];
        $used = 0;

        foreach ($people as $i => $p) {
            $body .= ($i + 1) . '. ' . $p['code'] . ' · ' . $p['name'] . "\n";
            // CHECKS PER EMPLOYEE (owner, 22 Oct 2026): ticked in the employee × check table.
            isset($p['checks']) && $body .= '   Checks to verify: ' . ($p['checks'] === [] ? '—' : implode(', ', $p['checks'])) . "\n";
            foreach ($p['details'] as $l => $v) {
                $labels[$l] = true;
                $v !== '' && $body .= "   {$l}: {$v}\n";
            }
            $files = [];
            foreach ($p['docs'] as $name => $path) {
                $size = is_file($path) ? (int) filesize($path) : -1;
                if ($size < 0) {
                    $skipped[] = "{$name} (file missing)";
                    continue;
                }
                if ($used + $size > $limitBytes) {
                    $skipped[] = "{$name} (over the attachment limit)";
                    continue;
                }
                $used += $size;
                $attach[] = [$name, (string) file_get_contents($path)];
                $files[] = $name;
            }
            $body .= '   Documents attached: ' . ($files === [] ? 'none' : implode(', ', $files)) . "\n\n";
        }

        // THE SAME DETAILS AS A SHEET, one row per employee, for the agency's own tracker.
        $cols = array_keys($labels);
        foreach ($people as $k => $p) { isset($p['checks']) && $people[$k]['details']['Checks to verify'] = implode('; ', $p['checks']); }
        array_filter($people, static fn (array $p): bool => isset($p['checks'])) === [] || $cols[] = 'Checks to verify';
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $cols, ',', '"', '');
        foreach ($people as $p) {
            fputcsv($fh, array_map(static fn (string $c): string => (string) ($p['details'][$c] ?? ''), $cols), ',', '"', '');
        }
        rewind($fh);
        array_unshift($attach, ['bgv-request-' . $today . '.csv', "\xEF\xBB\xBF" . stream_get_contents($fh)]);
        fclose($fh);

        $skipped === [] || $body .= 'Not attached: ' . implode('; ', $skipped) . "\n\n";
        $body .= "Kindly share the verification report against each employee code.\n\nRegards,\nHR — {$company}";

        return ['subject' => $subject, 'body' => $body, 'attachments' => $attach, 'skipped' => $skipped];
    }
}
