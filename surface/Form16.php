<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * FORM 130 (OLD FORM 16) — the annual TDS certificate (owner, 15 Oct).
 *
 *   Part A — TDS deposited, quarter by quarter. Only TRACES can issue it: HR downloads it there after
 *            the quarterly returns are filed and uploads the PDFs here (or the ZIP TRACES gives).
 *   Part B — salary, exemptions, deductions and the tax computed. Generated from the payroll and the
 *            employee's declaration by the same IncomeTax engine the monthly TDS uses.
 *
 * This class keeps Part A files (table form16_part_a, files under storage/) and whether a year has
 * been RELEASED to employees (Customisation key `form16-settings`, versioned). The numbers live in
 * IncomeTax / TaxDeclarations.
 */
final class Form16
{
    public const SETTINGS_KEY = 'form16-settings';
    private const TABLE = 'form16_part_a';

    public function __construct(
        private readonly RowStore $store,
        private readonly Customisation $customisation,
        private readonly string $dir,
    ) {
    }

    /** @return array<string, array<string, mixed>> employee id => live Part A row for the year */
    public function partA(string $fy): array
    {
        $out = [];

        try {
            $rows = $this->store->all(self::TABLE);
        } catch (\Throwable) {
            return []; // the table before its migration: nothing uploaded yet
        }

        foreach ($rows as $r) {
            if (Modules::isRetired($r) || (string) $r['financial_year'] !== $fy) { continue; }
            $e = (string) $r['employee_id'];
            if (!isset($out[$e]) || (int) $r['id'] > (int) $out[$e]['id']) { $out[$e] = $r; }
        }

        return $out;
    }

    /** Store one Part A PDF for an employee, retiring the one it replaces. @return array<string, mixed> */
    public function addPartA(string $employeeId, string $fy, string $pan, string $bytes, string $originalName, string $by): array
    {
        if (!self::isPdf($bytes)) {
            throw new \InvalidArgumentException($originalName . ' is not a PDF.');
        }

        $sub = $fy . '/' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $employeeId) . '_' . substr(hash('sha256', $bytes), 0, 12) . '.pdf';
        $abs = $this->dir . '/' . $sub;

        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0775, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('The Form 16 folder could not be created.');
        }

        if (file_put_contents($abs, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('The file could not be stored.');
        }

        $earlier = $this->partA($fy)[$employeeId] ?? null;
        $row = $this->store->put(self::TABLE, [
            'employee_id' => $employeeId, 'financial_year' => $fy, 'pan' => $pan === '' ? null : strtoupper($pan),
            'file_path' => $sub, 'file_name' => mb_substr($originalName, 0, 180), 'sha256' => hash('sha256', $bytes),
            'uploaded_by' => $by, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ($earlier !== null) {
            $this->store->put(self::TABLE, ['id' => $earlier['id'], 'deleted_at' => date('Y-m-d H:i:s')]);
        }

        return $row;
    }

    /** The stored file's absolute path, or null when it is missing on disk. */
    public function file(array $row): ?string
    {
        $abs = $this->dir . '/' . str_replace(['..', '\\'], ['', '/'], (string) $row['file_path']);

        return is_file($abs) ? $abs : null;
    }

    public function released(string $fy): bool
    {
        return (bool) (((array) ($this->customisation->live()[self::SETTINGS_KEY] ?? []))['released'][$fy] ?? false);
    }

    /** @return array{ok: bool, why: string} */
    public function setReleased(string $fy, bool $on, string $byId, string $byLabel): array
    {
        $s = (array) ($this->customisation->live()[self::SETTINGS_KEY] ?? []);
        $s['released'] = (array) ($s['released'] ?? []);
        $s['released'][$fy] = $on;

        return $this->customisation->saveRaw(self::SETTINGS_KEY, $s, $byId, $byLabel, 'Form 16 ' . ($on ? 'released' : 'withdrawn') . ' for ' . $fy);
    }

    /** The PAN in a TRACES file name ("ABCDE1234F_2027-28.pdf"), upper-cased, or ''. */
    public static function panIn(string $name): string
    {
        return preg_match('/(?<![A-Za-z])([A-Za-z]{5}[0-9]{4}[A-Za-z])(?![A-Za-z0-9])/', $name, $m) === 1 ? strtoupper($m[1]) : '';
    }

    public static function isPdf(string $bytes): bool
    {
        return str_starts_with(ltrim(substr($bytes, 0, 1024)), '%PDF-');
    }

    /** Quarter label of a wage month for an April year: Q1 = Apr–Jun … Q4 = Jan–Mar. */
    public static function quarter(string $period, int $startMonth = 4): int
    {
        return intdiv(((int) substr($period, 5, 2) - $startMonth + 12) % 12, 3) + 1;
    }
}
