<?php

declare(strict_types=1);

namespace SmartPRS\Surface;

/**
 * PERFORMANCE REVIEWS — Performance & Rewards → Performance.
 *
 * One review per employee per period. The rating scale, the periods offered,
 * the statuses and the PRG (performance-related growth) bands are all
 * config/performance.json. The reviewer is the reporting manager on the
 * employee record — never typed. The final rating defaults to whichever
 * rating the config names and HR may override it when finalising; the row
 * keeps all three so the override is visible.
 */
final class Performance
{
    public const TABLE = 'performance_reviews';

    /** @var array<string, mixed> */
    private readonly array $config;

    public function __construct(string $configPath)
    {
        $this->config = (array) json_decode((string) file_get_contents($configPath), true);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /** @return array<int, string> value => label */
    public function scale(): array
    {
        $s = [];

        foreach ((array) ($this->config['scale'] ?? []) as $step) {
            $s[(int) $step['value']] = (string) $step['label'];
        }

        return $s;
    }

    /** A typed rating, or null when it is not on the scale. */
    public function rating(string $typed): ?int
    {
        $typed = trim($typed);

        return preg_match('/^\d+$/', $typed) === 1 && isset($this->scale()[(int) $typed]) ? (int) $typed : null;
    }

    /**
     * The period labels offered, newest first: "2026-H2", "2026-H1", "2025-H2" …
     * from the configured kind and history.
     *
     * @return list<string>
     */
    public function periods(string $today): array
    {
        $kind = (string) ($this->config['periods']['kind'] ?? 'year');
        $labels = (array) ($this->config['periods']['labels'][$kind] ?? ['FY']);
        $years = max(1, (int) ($this->config['periods']['history_years'] ?? 1));
        $year = (int) substr($today, 0, 4);
        $month = (int) substr($today, 5, 2);
        $perYear = count($labels);
        $currentIndex = intdiv(($month - 1) * $perYear, 12);
        $out = [];

        for ($y = $year; $y > $year - $years; $y--) {
            for ($i = $perYear - 1; $i >= 0; $i--) {
                if ($y === $year && $i > $currentIndex) {
                    continue; // a period that has not started yet is not offered
                }

                $out[] = $y . '-' . $labels[$i];
            }
        }

        return $out;
    }

    /**
     * The reviewer is the employee's reporting manager: by id when the record
     * links one, else by the legacy text name.
     *
     * @param array<string, mixed> $employee
     * @param array<string, array<string, mixed>> $employeesById
     * @return array{id: string, label: string}
     */
    public static function reviewerOf(array $employee, array $employeesById): array
    {
        $id = trim((string) ($employee['reporting_manager_id'] ?? ''));

        if ($id !== '' && isset($employeesById[$id])) {
            return ['id' => $id, 'label' => Employment::label($employeesById[$id])];
        }

        $name = trim((string) ($employee['reporting_manager'] ?? ''));

        return ['id' => '', 'label' => $name === '' ? '— no reporting manager on the record —' : $name];
    }

    /**
     * The final rating with its working. An override wins when given; else the
     * configured default (manager, else self); a review with neither has none.
     *
     * A rating is an int on the shipped 1–5 scale, or a string when the
     * review is rated on the tenant's own Performance Appraisal scale (a
     * label "S", a number "4", a point in a slab "4.6") — which one wins is
     * the same rule either way.
     *
     * @return array{rating: int|string|null, working: string}
     */
    public function finalRating(int|string|null $self, int|string|null $manager, int|string|null $override): array
    {
        if ($override !== null) {
            return ['rating' => $override, 'working' => 'HR override ' . $override . ' (self ' . ($self ?? '—') . ', manager ' . ($manager ?? '—') . ')'];
        }

        $default = (string) ($this->config['final_defaults_to'] ?? 'manager_rating');
        $pick = $default === 'self_rating' ? $self : $manager;
        $other = $default === 'self_rating' ? $manager : $self;

        if ($pick !== null) {
            return ['rating' => $pick, 'working' => str_replace('_', ' ', $default) . ' ' . $pick . ' taken as final (other rating ' . ($other ?? '—') . ')'];
        }

        if ($other !== null) {
            return ['rating' => $other, 'working' => 'only one rating given (' . $other . '), taken as final'];
        }

        return ['rating' => null, 'working' => 'no rating yet'];
    }

    /** The PRG band for a final rating: the recommended increment percent as a decimal string, or null when no band. */
    public function prg(?int $rating): ?string
    {
        foreach ((array) ($this->config['prg']['bands'] ?? []) as $band) {
            if ($rating !== null && (int) $band['rating'] === $rating) {
                return (string) $band['increment_pct'];
            }
        }

        return null;
    }
}
